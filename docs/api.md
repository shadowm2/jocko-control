# Jocko Control — Device API v1

Base URL: `https://<control-server>/api/v1` (HTTPS is mandatory in production; plain HTTP is refused with `403 https_required`).

All requests and responses are JSON. Send `Accept: application/json` and, for `POST`, `Content-Type: application/json`.

## Authentication

Each registered device has its own bearer token, issued by an administrator in the Jocko Control UI. It is shown **once**, at registration (or when a new token is issued).

```
Authorization: Bearer 12|jkd_pQ3x...
```

> **The token is a secret.** Store it in the device's secret store or environment, never in source control, logs, tickets or chat. If it leaks, an administrator revokes the device or issues a new token, which invalidates the old one immediately.

Examples below use:

```bash
export CONTROL=https://control.example.com
export TOKEN='12|jkd_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'   # from your secret store
```

The shop is always determined by the token. Fields such as `shop_id` in a request body are ignored.

## Conventions

### Errors

Every error has the same envelope and never contains stack traces, SQL or secrets:

```json
{ "error": { "code": "validation_failed", "message": "The request is invalid.", "details": { "events": ["The events field must not have more than 100 items."] } } }
```

`details` is present only for validation errors; `retry_after` (seconds) only for `429`.

| HTTP | `error.code` | Meaning |
| --- | --- | --- |
| 401 | `unauthenticated` | Missing, invalid or deleted token (this includes revoked devices, whose tokens are deleted). |
| 401 | `device_revoked` | The device is flagged revoked. |
| 403 | `shop_suspended` | The device's shop is suspended. Retry later; nothing is accepted meanwhile. |
| 403 | `https_required` | Plain HTTP in production. |
| 404 | `command_not_found` | The command does not exist **or belongs to another device** (indistinguishable on purpose). |
| 409 | `command_expired` | The command's deadline passed; it cannot be completed any more. |
| 409 | `command_already_finalized` | Acknowledged before with a different status. |
| 413 | `request_too_large` | Body larger than 8 MB. |
| 422 | `validation_failed` | The request envelope is invalid. |
| 429 | `too_many_requests` | Rate limited (120 requests/minute per device by default). Honour `Retry-After`. |
| 500 | `server_error` | Unexpected failure. For `POST /sync/events` nothing from the batch was stored: retry the whole batch. |

### Timestamps

Timestamps are ISO 8601. `occurred_at` must include a timezone (`2026-10-26T10:15:00Z` or `+03:30`), may have up to 6 fractional digits, and must be between years 2000 and 2100. The device's clock is **never** used to decide whether it is online; the server stamps `last_seen_at` itself.

---

## `GET /device/me`

Identity and basic configuration of the authenticated device (never another device's).

```bash
curl -s "$CONTROL/api/v1/device/me" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

```json
{
  "data": {
    "id": "01a17811-5ade-7119-8540-1b6987efccbc",
    "name": "Front desk",
    "status": "active",
    "app_version": "1.4.2",
    "database_schema_version": null,
    "shop": {
      "id": "01a17811-5ace-72af-af9d-7955187aee3b",
      "name": "Alpha Garage",
      "slug": "alpha-garage",
      "timezone": "Asia/Tehran"
    },
    "config": {
      "heartbeat_interval_seconds": 60,
      "command_poll_interval_seconds": 60,
      "max_events_per_batch": 100,
      "max_event_payload_bytes": 65536
    },
    "server_time": "2026-10-26T11:56:18+00:00"
  }
}
```

## `POST /device/heartbeat`

Tells the server the device is alive and reports diagnostics. The server records `last_seen_at` with **its own clock**.

| Field | Type | Notes |
| --- | --- | --- |
| `app_version` | string, optional | Longer than 50 characters is cut to 50, not rejected. |
| `database_schema_version` | string, optional | Longer than 50 characters is cut to 50, not rejected. |
| `local_status.state` | `idle`, `syncing`, `error`, `maintenance`, optional | |
| `local_status.pending_events` | integer ≥ 0, optional | Events waiting to be pushed. |
| `local_status.database_ok` | boolean, optional | |
| `local_status.disk_free_mb` | integer ≥ 0, optional | |
| `local_status.uptime_seconds` | integer ≥ 0, optional | |
| `sync.status` | `success` or `failed`, optional | Report the outcome of the device's last synchronization. |
| `sync.error` | string ≤ 1000, optional | With `failed`. Do not include personal data or secrets. |

Only these fields are kept; anything else is ignored. A heartbeat **without** `sync.status = "success"` does not change `last_sync_at`.

```bash
curl -s -X POST "$CONTROL/api/v1/device/heartbeat" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
    "app_version": "1.5.0",
    "database_schema_version": "2026_09_01_000001",
    "local_status": { "state": "idle", "pending_events": 0, "database_ok": true },
    "sync": { "status": "success" }
  }'
```

```json
{
  "data": {
    "received_at": "2026-10-26T11:56:18+00:00",
    "last_sync_at": "2026-10-26T11:56:18+00:00",
    "pending_commands": 1
  }
}
```

`pending_commands` is the number of unexpired queued commands: poll `GET /device/commands` when it is above zero.

## `POST /sync/events`

Pushes a batch of 1–100 events into the central inbox.

> **Receiving an event does not apply it.** `accepted` means "durably stored in the inbox". Nothing is written to central business tables yet.

Per event:

| Field | Type | Rules |
| --- | --- | --- |
| `event_id` | UUID | Globally unique; the idempotency key. |
| `event_type` | string ≤ 100 | `A-Z a-z 0-9 _ . : -`, e.g. `repair_order.created`. |
| `aggregate_type` | string ≤ 100 | Same charset, e.g. `repair_order`. |
| `aggregate_id` | UUID | Local business entity. |
| `base_version` | integer 0…4294967295 or `null` | Version of the aggregate the event was based on. |
| `payload` | JSON object (may be `{}`) | ≤ 64 KB when serialized, nested ≤ 16 levels. |
| `occurred_at` | ISO 8601 with timezone | Device time; informational only. |

```bash
curl -s -X POST "$CONTROL/api/v1/sync/events" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
    "events": [
      {
        "event_id": "7f424678-1786-4b8f-8c53-05b5f8aa4eef",
        "event_type": "repair_order.created",
        "aggregate_type": "repair_order",
        "aggregate_id": "0f6c1c1e-6f43-4b59-9d3e-2f2d1a1d9a11",
        "base_version": 0,
        "payload": { "customer": "Ali", "total": 1250000 },
        "occurred_at": "2026-10-26T10:15:00Z"
      }
    ]
  }'
```

Response (`200`, always, when the batch envelope is valid):

```json
{
  "data": {
    "accepted":         [ { "index": 0, "event_id": "7f424678-1786-4b8f-8c53-05b5f8aa4eef" } ],
    "already_accepted": [],
    "rejected":         []
  },
  "meta": { "received": 1, "accepted": 1, "already_accepted": 0, "rejected": 0 }
}
```

Sending the **same event again** (same `event_id`, same content) is safe and answers `already_accepted`:

```json
{ "data": { "accepted": [], "already_accepted": [ { "index": 0, "event_id": "7f42…" } ], "rejected": [] },
  "meta": { "received": 1, "accepted": 0, "already_accepted": 1, "rejected": 0 } }
```

Sending the same `event_id` with **different content** is rejected; the stored event is kept untouched and the inconsistency is audited:

```json
{ "data": { "accepted": [], "already_accepted": [],
            "rejected": [ { "index": 0, "event_id": "7f42…", "code": "payload_mismatch",
                            "message": "An event with this event_id was already received with different content." } ] },
  "meta": { "received": 1, "accepted": 0, "already_accepted": 0, "rejected": 1 } }
```

Rejection codes (per event): `validation_failed` (with `details` per field), `payload_too_large`, `payload_too_deep`, `payload_mismatch`, `event_id_conflict`. Rejections are final for that event content; fix the event or drop it. Valid events in the same batch are still stored, so **match results by `event_id`/`index`, not by HTTP status**.

Whole-request failures: `422` when `events` is missing, empty, not an array, or has more than 100 items; `413` for bodies over 8 MB; `500` if storing failed (**nothing** from the batch was kept; resend it).

A batch in which nothing was rejected marks the device as successfully synchronized (`last_sync_at` is set, `last_sync_error` cleared). A batch with rejections records a sync error for administrators instead.

## `GET /device/commands`

Returns the device's own queued, unexpired commands, oldest first. `?limit=` (1–50, default 20).

```bash
curl -s "$CONTROL/api/v1/device/commands?limit=10" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

```json
{
  "data": [
    {
      "id": "01a17811-5b33-72a6-ba1c-8d5174109255",
      "type": "collect_diagnostics",
      "payload": { "include_logs": true, "since_minutes": 60 },
      "status": "queued",
      "requested_at": "2026-10-26T11:56:05+00:00",
      "expires_at": "2026-10-27T11:56:05+00:00"
    }
  ]
}
```

Command types and parameters (all optional):

| `type` | `payload` |
| --- | --- |
| `sync_now` | `full` (boolean) |
| `check_version` | none |
| `collect_diagnostics` | `include_logs` (boolean), `since_minutes` (1–1440) |
| `refresh_config` | none |

A command stays in this list until the device acknowledges it or it expires, so a device may see the same command again if it did not acknowledge (deliveries are at-least-once). Completed, failed and expired commands are never returned.

## `POST /device/commands/{command}/ack`

Reports the outcome of a command.

| Field | Type | Notes |
| --- | --- | --- |
| `status` | `completed` or `failed` | required |
| `result` | JSON object, optional | ≤ 32 KB serialized. |
| `error` | string ≤ 1000, optional | Stored as `result.error`. |

```bash
curl -s -X POST "$CONTROL/api/v1/device/commands/01a17811-5b33-72a6-ba1c-8d5174109255/ack" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{ "status": "completed", "result": { "disk_free_mb": 20480 } }'
```

```json
{
  "data": {
    "id": "01a17811-5b33-72a6-ba1c-8d5174109255",
    "status": "completed",
    "completed_at": "2026-10-26T12:01:44+00:00",
    "already_acknowledged": false
  }
}
```

Repeating the identical acknowledgment answers `200` with `already_acknowledged: true` and does **not** overwrite the first result. Acknowledging with a different final status answers `409 command_already_finalized`; acknowledging after the deadline answers `409 command_expired` and the command stays expired. A command that belongs to another device answers `404 command_not_found`.

---

## Recommended device loop

1. Every ~60 s: `POST /device/heartbeat` (include `sync` after each sync attempt). If `pending_commands > 0`, `GET /device/commands`, execute, `POST …/ack`.
2. When there are unsent local events: `POST /sync/events` in batches of ≤ 100, oldest first. Remove an event from the local outbox once it is `accepted` or `already_accepted`; treat `rejected` per the codes above.
3. On `429`, wait `Retry-After`. On `5xx` or network errors, back off and resend the same batch (it is idempotent). On `403 shop_suspended`, back off for minutes, not seconds. On `401`, stop and alert the shop owner: the token was revoked or replaced.
4. The local application must keep working regardless of any of the above failing.

---

## Shop data events

The shop mirror (see the README) is built from ordinary `POST /sync/events` events that follow these conventions. Nothing else about the endpoint changes.

| Field | Convention |
| --- | --- |
| `event_type` | `<entity>.<action>`, where action is `created`, `updated`, `deleted`, `restored` or `snapshot` (a full re-send of current state). |
| `aggregate_type` | The entity, e.g. `order`, `order_item`, `payment`, `user`. Must equal the part of `event_type` before the dot. The full list is in `config/shop_data.php`. |
| `aggregate_id` | A UUID that is stable per record. jocko-shop derives it (UUIDv5) from the entity and its local id. |
| `payload` | The **complete** state of the record, not a diff: its fields as stored, with the shop's local primary key as integer `id`. Foreign keys are the referenced record's local `id`. Decimals are strings, enums their value, timestamps ISO 8601 UTC. `deleted_at` is `null` unless the record is soft-deleted. |
| `occurred_at` | When the change happened. A mirrored record is only replaced by an event that is not older than the state it already has. |

Only the fields listed for the entity in `config/shop_data.php` are kept; the rest is dropped on arrival. Events for other entities, or without a valid integer `id`, are still stored in the inbox but not mirrored.

```json
{
  "event_id": "3b0f0c3e-6a43-4a0e-9d5a-6c9a4f0d3d11",
  "event_type": "order.updated",
  "aggregate_type": "order",
  "aggregate_id": "b8c5c6a4-1d2c-5f3e-8a41-0c9f6d5e7a10",
  "base_version": null,
  "payload": {
    "id": 7, "slug": "ord7", "order_number": "ORD-00007", "status": "completed",
    "user_id": 3, "user_car_id": 2, "warehouse_id": 1, "description": "Brake service",
    "created_at": "2026-10-29T10:20:12.000000Z", "updated_at": "2026-10-29T11:02:40.000000Z", "deleted_at": null
  },
  "occurred_at": "2026-10-29T11:02:40.123456Z"
}
```

