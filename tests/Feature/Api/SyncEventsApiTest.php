<?php

namespace Tests\Feature\Api;

use App\Contracts\SyncEventHandler;
use App\Models\Shop;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class SyncEventsApiTest extends TestCase
{
    use CreatesDevices, RefreshDatabase;

    public function test_valid_events_are_persisted_against_the_authenticated_devices_shop(): void
    {
        $issued = $this->issueDevice();
        $event = $this->eventPayload(['base_version' => 4]);

        $this->postJson('/api/v1/sync/events', ['events' => [$event]], $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.accepted.0.event_id', $event['event_id'])
            ->assertJsonPath('data.accepted.0.index', 0)
            ->assertJsonPath('data.already_accepted', [])
            ->assertJsonPath('data.rejected', [])
            ->assertJsonPath('meta.accepted', 1);

        $stored = SyncEvent::sole();
        $this->assertSame($event['event_id'], $stored->event_id);
        $this->assertSame($issued->device->shop_id, $stored->shop_id);
        $this->assertSame($issued->device->id, $stored->device_id);
        $this->assertSame('repair_order.created', $stored->event_type);
        $this->assertSame('repair_order', $stored->aggregate_type);
        $this->assertSame($event['aggregate_id'], $stored->aggregate_id);
        $this->assertSame(4, $stored->base_version);
        $this->assertEquals($event['payload'], $stored->payload);
        $this->assertSame(64, strlen($stored->payload_hash));
        $this->assertNotNull($stored->received_at);
    }

    public function test_a_client_supplied_shop_id_is_ignored(): void
    {
        $issued = $this->issueDevice();
        $otherShop = Shop::factory()->create();

        $this->postJson('/api/v1/sync/events', [
            'shop_id' => $otherShop->id,
            'events' => [$this->eventPayload(['shop_id' => $otherShop->id, 'device_id' => (string) Str::uuid()])],
        ], $this->bearer($issued))->assertOk();

        $stored = SyncEvent::sole();
        $this->assertSame($issued->device->shop_id, $stored->shop_id);
        $this->assertSame($issued->device->id, $stored->device_id);
    }

    public function test_a_successful_push_records_server_verified_sync_time(): void
    {
        $issued = $this->issueDevice();
        $issued->device->update(['last_sync_error' => 'previous failure', 'last_sync_error_at' => now()]);

        $this->postJson('/api/v1/sync/events', ['events' => [$this->eventPayload()]], $this->bearer($issued))->assertOk();

        $device = $issued->device->fresh();
        $this->assertNotNull($device->last_sync_at);
        $this->assertNull($device->last_sync_error);
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_replaying_the_same_event_is_idempotent(): void
    {
        $issued = $this->issueDevice();
        $event = $this->eventPayload();

        $this->postJson('/api/v1/sync/events', ['events' => [$event]], $this->bearer($issued))->assertOk();

        // Same content with differently ordered keys is still the same event.
        $replay = $event;
        $replay['payload'] = array_reverse($event['payload'], true);

        $this->postJson('/api/v1/sync/events', ['events' => [$replay]], $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.accepted', [])
            ->assertJsonPath('data.already_accepted.0.event_id', $event['event_id'])
            ->assertJsonPath('data.rejected', [])
            ->assertJsonPath('meta.already_accepted', 1);

        $this->assertDatabaseCount('sync_events', 1);
    }

    public function test_the_same_event_twice_in_one_batch_is_stored_once(): void
    {
        $issued = $this->issueDevice();
        $event = $this->eventPayload();

        $this->postJson('/api/v1/sync/events', ['events' => [$event, $event]], $this->bearer($issued))
            ->assertOk()
            ->assertJsonCount(1, 'data.accepted')
            ->assertJsonCount(1, 'data.already_accepted');

        $this->assertDatabaseCount('sync_events', 1);
    }

    public function test_reusing_an_event_id_with_different_content_is_rejected_without_overwriting(): void
    {
        $issued = $this->issueDevice();
        $event = $this->eventPayload();

        $this->postJson('/api/v1/sync/events', ['events' => [$event]], $this->bearer($issued))->assertOk();

        $tampered = $event;
        $tampered['payload']['total'] = 1;

        $this->postJson('/api/v1/sync/events', ['events' => [$tampered]], $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.accepted', [])
            ->assertJsonPath('data.rejected.0.event_id', $event['event_id'])
            ->assertJsonPath('data.rejected.0.code', 'payload_mismatch');

        $this->assertDatabaseCount('sync_events', 1);
        $this->assertEquals($event['payload'], SyncEvent::sole()->payload);

        $device = $issued->device->fresh();
        $this->assertStringContainsString('payload_mismatch', $device->last_sync_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sync.event_conflict', 'device_id' => $device->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sync.batch_rejected', 'device_id' => $device->id]);
    }

    public function test_an_event_id_used_by_another_shop_is_rejected_without_disclosure(): void
    {
        $first = $this->issueDevice();
        $second = $this->issueDevice();
        $event = $this->eventPayload();

        $this->postJson('/api/v1/sync/events', ['events' => [$event]], $this->bearer($first))->assertOk();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/sync/events', ['events' => [$event]], $this->bearer($second))
            ->assertOk()
            ->assertJsonPath('data.already_accepted', [])
            ->assertJsonPath('data.rejected.0.code', 'event_id_conflict');

        $this->assertDatabaseCount('sync_events', 1);
        $this->assertSame($first->device->shop_id, SyncEvent::sole()->shop_id);
    }

    public function test_malformed_events_are_rejected_individually_and_valid_ones_still_stored(): void
    {
        $issued = $this->issueDevice();
        $good = $this->eventPayload();

        $bad = [
            $this->eventPayload(['event_id' => 'not-a-uuid']),
            $this->eventPayload(['aggregate_id' => '123']),
            $this->eventPayload(['event_type' => 'has space']),
            $this->eventPayload(['event_type' => str_repeat('a', 101)]),
            $this->eventPayload(['occurred_at' => 'yesterday']),
            $this->eventPayload(['occurred_at' => '2026-01-01 10:00:00']),
            $this->eventPayload(['base_version' => -1]),
            $this->eventPayload(['base_version' => 'abc']),
            $this->eventPayload(['payload' => 'a string']),
            array_diff_key($this->eventPayload(), ['payload' => 1]),
            'just a string',
        ];

        $response = $this->postJson('/api/v1/sync/events', ['events' => [...$bad, $good]], $this->bearer($issued))
            ->assertOk()
            ->assertJsonCount(count($bad), 'data.rejected')
            ->assertJsonCount(1, 'data.accepted')
            ->assertJsonPath('data.accepted.0.event_id', $good['event_id']);

        foreach ($response->json('data.rejected') as $rejected) {
            $this->assertSame('validation_failed', $rejected['code']);
        }

        $this->assertDatabaseCount('sync_events', 1);
        $this->assertNull($issued->device->fresh()->last_sync_at, 'A batch with rejections is not a successful sync.');
        $this->assertNotNull($issued->device->fresh()->last_sync_error);
    }

    public function test_an_empty_payload_object_is_accepted(): void
    {
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => [$this->eventPayload(['payload' => []])]], $this->bearer($issued))
            ->assertOk()
            ->assertJsonCount(1, 'data.accepted');
    }

    public function test_oversized_and_overly_deep_payloads_are_rejected(): void
    {
        config(['jocko.sync.max_payload_bytes' => 200, 'jocko.sync.max_payload_depth' => 3]);
        $issued = $this->issueDevice();

        $big = $this->eventPayload(['payload' => ['blob' => str_repeat('x', 500)]]);
        $deep = $this->eventPayload(['payload' => ['a' => ['b' => ['c' => ['d' => 1]]]]]);

        $this->postJson('/api/v1/sync/events', ['events' => [$big, $deep]], $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.rejected.0.code', 'payload_too_large')
            ->assertJsonPath('data.rejected.1.code', 'payload_too_deep');

        $this->assertDatabaseCount('sync_events', 0);
    }

    public function test_batches_are_limited_to_one_hundred_events(): void
    {
        $issued = $this->issueDevice();

        $events = fn (int $n) => array_map(fn () => $this->eventPayload(), range(1, $n));

        $this->postJson('/api/v1/sync/events', ['events' => $events(101)], $this->bearer($issued))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertDatabaseCount('sync_events', 0);

        $this->postJson('/api/v1/sync/events', ['events' => $events(100)], $this->bearer($issued))
            ->assertOk()
            ->assertJsonCount(100, 'data.accepted');
        $this->assertDatabaseCount('sync_events', 100);
    }

    public function test_an_empty_or_missing_batch_is_rejected(): void
    {
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => []], $this->bearer($issued))->assertUnprocessable();
        $this->postJson('/api/v1/sync/events', [], $this->bearer($issued))->assertUnprocessable();
        $this->postJson('/api/v1/sync/events', ['events' => 'nope'], $this->bearer($issued))->assertUnprocessable();
    }

    public function test_oversized_request_bodies_are_refused(): void
    {
        config(['jocko.sync.max_request_bytes' => 1000]);
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => [$this->eventPayload(['payload' => ['x' => str_repeat('a', 2000)]])]], $this->bearer($issued))
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'request_too_large');
    }

    public function test_an_unexpected_failure_rolls_back_the_whole_batch(): void
    {
        $issued = $this->issueDevice();
        $created = 0;

        SyncEvent::creating(function () use (&$created): void {
            if (++$created === 3) {
                throw new \RuntimeException('simulated database failure with SECRET-PAYLOAD-VALUE');
            }
        });

        Log::spy();

        $response = $this->postJson('/api/v1/sync/events', [
            'events' => [$this->eventPayload(), $this->eventPayload(), $this->eventPayload(), $this->eventPayload()],
        ], $this->bearer($issued))
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'server_error');

        $this->assertStringNotContainsString('SECRET-PAYLOAD-VALUE', $response->getContent());
        $this->assertDatabaseCount('sync_events', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sync.batch_error', 'device_id' => $issued->device->id]);
        $this->assertNull($issued->device->fresh()->last_sync_at);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'Synchronization batch could not be stored.');
    }

    public function test_registered_handlers_run_after_storage_and_cannot_discard_events(): void
    {
        $handler = new class implements SyncEventHandler
        {
            /** @var array<int, string> */
            public static array $seen = [];

            public function supports(SyncEvent $event): bool
            {
                return $event->event_type === 'repair_order.created';
            }

            public function handle(SyncEvent $event): void
            {
                self::$seen[] = $event->event_id;

                throw new \RuntimeException('handler blew up');
            }
        };
        $this->app->instance($handler::class, $handler);
        config(['jocko.sync.handlers' => [$handler::class]]);

        $issued = $this->issueDevice();
        $event = $this->eventPayload();

        $this->postJson('/api/v1/sync/events', ['events' => [$event]], $this->bearer($issued))
            ->assertOk()
            ->assertJsonCount(1, 'data.accepted');

        $this->assertSame([$event['event_id']], $handler::$seen);
        $this->assertDatabaseCount('sync_events', 1);
    }

    public function test_no_business_tables_are_touched_by_ingestion(): void
    {
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => [$this->eventPayload()]], $this->bearer($issued))->assertOk();

        $tables = collect(Schema::getTables(config('database.connections.mysql.database')))
            ->pluck('name')
            ->reject(fn (string $t) => in_array($t, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'users', 'personal_access_tokens', 'shops', 'shop_devices', 'sync_events', 'remote_commands', 'audit_logs'], true));

        $this->assertEmpty($tables, 'Unexpected business tables: '.$tables->implode(', '));
    }
}
