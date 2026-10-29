# Jocko Control

Central management application and synchronization API for **Jocko**, an offline-first, multi-tenant SaaS for automotive repair shops.

Every repair shop runs its own local installation ([`jocko-shop`](../jocko-shop)) with its own MySQL database, and keeps working without internet. Jocko Control runs on a central server and never reaches into a shop's network: local installations make **outbound HTTPS requests** to it.

This MVP covers the central infrastructure only:

- administrator login and a Persian (RTL) dashboard;
- shop management (create, edit, suspend/reactivate; shops are never deleted);
- device registration with revocable API tokens;
- an authenticated, versioned device API (`/api/v1`): identity, heartbeat, event push, command pull and acknowledgement;
- a durable, idempotent inbox of synchronization events;
- a **read-only mirror of each shop's data** (orders, customers, cars, inventory, purchases, payments…) projected from those events and browsable per shop;
- a queue of allowlisted remote commands;
- an audit trail.

> **Ingestion boundary.** Receiving a synchronization event first only *records* it in the `sync_events` inbox. The one thing applied from there is the **read-only shop mirror** (`shop_records`, see [Shop data](#shop-data-read-only-mirror)). Control never writes back to a shop's data, and there is still no central repair-order, customer or inventory *business* schema with conflict rules. Further consumers hook in through `App\Contracts\SyncEventHandler` (see [Extending](#extending-event-processing)).

Stack: Laravel 13, PHP 8.5 (8.3+ supported), MySQL 8, Livewire 4, Flux UI (free), Tailwind CSS 4, Laravel Sanctum, PHPUnit.

## Setup

Requirements: PHP 8.3+ with `pdo_mysql`, `intl` and `mbstring`; Composer; MySQL 8; Node 22 + npm.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the databases (one for the app, one for the tests) and put the credentials in `.env`:

```bash
mysql -u root -p -e "CREATE DATABASE jocko_control CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                     CREATE DATABASE jocko_control_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=jocko_control
DB_USERNAME=...
DB_PASSWORD=...
```

Then:

```bash
php artisan migrate
npm install
npm run build          # or `npm run dev` while developing
php artisan jocko:create-admin
php artisan serve
```

Open <http://localhost:8000> and sign in.

### Creating administrators

There is **no public registration**. Administrators are the application's `users`, created from the command line; the command prompts for the name, email and password (minimum 12 characters, letters and numbers) and never accepts the password as an argument:

```bash
php artisan jocko:create-admin
```

A user can be deactivated (`users.is_active = false`); that takes effect immediately, even for live sessions.

### Sample data (development only)

```bash
php artisan db:seed --class=DemoSeeder
```

Needs an existing administrator, refuses to run in production, and prints one device token so you can try the API straight away. That token is a secret like any other: **do not commit it** and do not paste it into tickets or chats.

### Scheduler

Remote commands whose deadline passed are marked `expired` lazily (when a device polls or acknowledges), and also by a scheduled sweep so the admin UI stays accurate:

```bash
php artisan schedule:work                       # development
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1   # production cron
```

Correctness never depends on the scheduler: an expired command is never delivered or accepted as completed, scheduler or not.

## Configuration

Everything specific to this application is in [`config/jocko.php`](config/jocko.php); the useful environment variables are:

| Variable | Default | Meaning |
| --- | --- | --- |
| `JOCKO_ONLINE_THRESHOLD_MINUTES` | `15` | A device is *online* if the server last heard from it within this many minutes. |
| `JOCKO_SYNC_FAILURE_WINDOW_HOURS` | `24` | How long a device with an unresolved sync error counts as "recently failing". |
| `JOCKO_DISPLAY_TIMEZONE` | `Asia/Tehran` | Timezone administrators see (storage is UTC). |
| `JOCKO_REQUIRE_HTTPS` | on in production | Refuse plain-HTTP API calls and redirect the admin UI to HTTPS. |
| `JOCKO_API_RATE_LIMIT_PER_IP` / `_PER_DEVICE` | `600` / `120` | Requests per minute. |
| `TRUSTED_PROXIES` | empty | Comma-separated proxy IPs (or `*`) when TLS terminates at a load balancer. |
| `SANCTUM_TOKEN_PREFIX` | `jkd_` | Prefix of device tokens (helps secret scanners find leaked ones). |

Fixed limits (also in `config/jocko.php`): 100 events per batch, 64 KB per event payload, 8 MB per request, 50 commands per poll.

The UI is Persian-first (`APP_LOCALE=fa`, RTL, Jalali dates, Persian digits). Strings live in `lang/fa/`. Database values and API field names are language independent.

## How it works

### Shops, devices and identity

- A **shop** has a permanent UUID; a **device** has a permanent UUID and belongs to exactly one shop. Devices are identified by their token, never by IP address.
- The shop of a request is always derived **server-side from the authenticated device**. A `shop_id`/`device_id` sent in a request body is ignored.
- Shops cannot be deleted (foreign keys are `RESTRICT`); suspend them instead. **Suspending a shop** makes every request from its devices fail with `403 shop_suspended` and stops command queuing.

### Device credentials

- Administrators register devices from the shop page. The API token is generated with Laravel Sanctum and shown **exactly once**; only its SHA-256 hash is stored (`personal_access_tokens`). It is never written to logs or the audit trail and cannot be displayed again, including after a page refresh.
- **Revoke** deletes all of the device's tokens and marks it `revoked`. **Issue a new token** (rotate) invalidates the old token and shows a new one once.
- The token is a **secret**. Configure it on the device through its environment/secret store. Never commit it to source control.
- Administrators authenticate with the session guard, devices with bearer tokens; a session cookie is useless on `/api/v1` and a device token is useless on the admin UI.

### Connectivity states

Derived, never stored, using only the **server's clock**:

| State | Meaning |
| --- | --- |
| `revoked` | Device was revoked. |
| `shop_suspended` | Its shop is suspended. |
| `never_connected` | `last_seen_at` is empty. Never shown as online. |
| `online` | `last_seen_at` within the threshold (15 min by default). |
| `offline` | Seen before, but not recently. |

`last_seen_at` is updated by every authenticated device request. `last_sync_at` is updated **only** when the server has verified a synchronization (a pushed batch fully stored, with no rejected events) or the device explicitly reports `sync.status = "success"` in a heartbeat. A heartbeat alone is not proof of synchronization.

### Event ingestion

`POST /api/v1/sync/events` accepts up to 100 events and answers per event:

- **accepted**: stored in the inbox;
- **already accepted**: same `event_id` and same content: nothing is inserted (idempotent). Key order inside `payload` does not matter;
- **rejected**: invalid fields, payload too large/deep, or the `event_id` was seen before with *different* content. The earlier event is never overwritten and the conflict is audited. An `event_id` that belongs to another shop is rejected without revealing it.

A batch is stored in one database transaction: if something unexpected goes wrong, **nothing** from that batch is kept and the device gets a generic `500` (retry the whole batch; replays are safe).

### Remote commands

Only these types exist: `sync_now`, `check_version`, `collect_diagnostics`, `refresh_config`. They carry validated, whitelisted parameters. There is no shell, SQL or code execution. Sensitive commands (`collect_diagnostics`, `refresh_config`) need an explicit confirmation in the UI.

A command is delivered to its own device while `queued` and unexpired (at-least-once, so devices must tolerate seeing it again until they acknowledge it). Acknowledging is idempotent: repeating the same status is a no-op, contradicting a final status is `409`, and an expired command can never become completed.

### Shop data (read-only mirror)

Open a shop and choose **داده‌های فروشگاه** (*Shop data*) to browse a copy of its data: an overview per entity, searchable lists, and record pages that link related records (an order shows its customer, car, items, payments and the balance). Nothing on these pages can change anything; edits happen in the shop and arrive here by synchronization.

How it works:

1. The shop records every create, update, delete and restore in its own outbox and pushes them as events to `POST /api/v1/sync/events`. Each event carries the **complete state** of one record (`event_type` `order.updated`, `aggregate_type` `order`, `payload` with the record's fields and its local `id`). See [docs/api.md](docs/api.md#shop-data-events).
2. `App\Services\ShopRecordProjector` (registered in `config('jocko.sync.handlers')`) upserts the record into `shop_records`, keyed by shop, entity and the shop's own id. Events are idempotent, and one older than what is already mirrored is ignored, so late, repeated or replayed deliveries are harmless.
3. `config/shop_data.php` is the catalog of mirrored entities, with an **allowlist of fields** for each. A field not listed there is dropped on arrival, so a password, OTP or token can never be stored even if a shop sent it. Never add credentials to it.

`php artisan jocko:project-events [--shop=UUID]` rebuilds the mirror by replaying the stored inbox (after a bug fix, or to catch up events received before the projector existed). The mirror is a view of the inbox, so `sync_events` stays the system of record.

Not mirrored, on purpose: settings and API keys, roles and permissions, uploaded media files, and everything credential-like.

### Extending event processing

Implement `App\Contracts\SyncEventHandler` (`supports()` and `handle()`) and list the class in `config('jocko.sync.handlers')`. Handlers run after the batch has been committed; a handler that throws is logged and never discards a stored event. Conflict resolution using `base_version` and projection onto further business tables belong there; the shop mirror above is the reference implementation.

## Device API

See **[docs/api.md](docs/api.md)** for the full reference with example requests and responses.

## Tests

The suite runs against **MySQL** (no SQLite assumptions). `phpunit.xml` forces the database name `jocko_control_test` and `tests/TestCase.php` refuses to run against any database whose name does not end in `_test`, because the tests wipe the schema. Credentials come from `.env`.

```bash
php artisan test --compact                       # everything
php artisan test --compact tests/Feature/Api     # device API only
vendor/bin/pint --dirty --format agent           # code style
```

Frontend: `npm run build`. Feature tests disable Vite, so they do not need a build.

## Production checklist

- Serve over **HTTPS only**. Set `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL` (https), and `TRUSTED_PROXIES` if behind a proxy/load balancer. With `JOCKO_REQUIRE_HTTPS` on (the production default) the API refuses plain HTTP, session cookies are `Secure`, and HSTS is sent.
- Use a dedicated MySQL user with rights on the application database only. Keep `.env` out of source control.
- `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan config:cache route:cache view:cache`.
- Run the scheduler (cron above).
- Back up the database, in particular `sync_events` (the system of record for what shops have sent; `shop_records` can be rebuilt from it) and `audit_logs`.
- Plan retention for `sync_events` and `audit_logs`; both grow without bound.
- Create administrators with `jocko:create-admin`; never seed a default one.

## Known limitations

- Only the read-only shop mirror is projected from events; there is no central business data with conflict resolution yet, and shops cannot be edited from here.
- Mirror lists search the record's label (e.g. order number, name) and id only. Related lists on a record page are looked up in the record's JSON, which is fine at one shop's scale but is the first thing to index (a generated column) if it ever gets slow.
- The admin UI has Persian strings only; no language switcher.
- No password reset, MFA or role separation: every active user is a full administrator.
- Rate limiting uses the configured cache store; for several app servers use a shared one (database, Redis, Memcached).
- Device-originated timestamps (`occurred_at`) are stored as supplied (within 2000–2100) and never used for connectivity.
