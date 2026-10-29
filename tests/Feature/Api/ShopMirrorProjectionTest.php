<?php

namespace Tests\Feature\Api;

use App\Models\Shop;
use App\Models\ShopRecord;
use App\Models\SyncEvent;
use App\Services\ShopRecordProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class ShopMirrorProjectionTest extends TestCase
{
    use CreatesDevices, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function recordEvent(string $entity, string $action, array $payload, array $overrides = []): array
    {
        return $this->eventPayload([
            'event_type' => "{$entity}.{$action}",
            'aggregate_type' => $entity,
            'base_version' => null,
            'payload' => $payload,
        ] + $overrides);
    }

    public function test_an_event_pushed_through_the_api_becomes_a_mirrored_record_of_that_shop(): void
    {
        $issued = $this->issueDevice();
        $event = $this->recordEvent('order', 'created', [
            'id' => 7, 'order_number' => 'ORD-0007', 'status' => 'pending', 'user_id' => 3,
        ]);

        $this->postJson('/api/v1/sync/events', ['events' => [$event]], $this->bearer($issued))->assertOk();

        $record = ShopRecord::sole();
        $this->assertSame($issued->device->shop_id, $record->shop_id);
        $this->assertSame('order', $record->entity);
        $this->assertSame(7, $record->local_id);
        $this->assertSame('ORD-0007', $record->label);
        $this->assertSame('pending', $record->field('status'));
        $this->assertSame($event['aggregate_id'], $record->aggregate_id);
        $this->assertFalse($record->isRemoved());
    }

    public function test_a_later_event_replaces_the_state_and_the_same_event_twice_changes_nothing(): void
    {
        $issued = $this->issueDevice();
        $first = $this->recordEvent('order', 'created', ['id' => 7, 'order_number' => 'ORD-0007', 'status' => 'pending'], ['occurred_at' => now()->subMinutes(5)->toIso8601String()]);
        $second = $this->recordEvent('order', 'updated', ['id' => 7, 'order_number' => 'ORD-0007', 'status' => 'completed'], ['occurred_at' => now()->subMinute()->toIso8601String(), 'aggregate_id' => $first['aggregate_id']]);

        $this->postJson('/api/v1/sync/events', ['events' => [$first, $second]], $this->bearer($issued))->assertOk();
        $this->postJson('/api/v1/sync/events', ['events' => [$first, $second]], $this->bearer($issued))->assertOk();

        $this->assertSame(1, ShopRecord::count());
        $this->assertSame('completed', ShopRecord::sole()->field('status'));
    }

    public function test_an_event_older_than_the_mirrored_state_is_ignored(): void
    {
        $issued = $this->issueDevice();
        $newer = $this->recordEvent('order', 'updated', ['id' => 7, 'order_number' => 'ORD-0007', 'status' => 'completed'], ['occurred_at' => now()->subMinute()->toIso8601String()]);
        $older = $this->recordEvent('order', 'created', ['id' => 7, 'order_number' => 'ORD-0007', 'status' => 'pending'], ['occurred_at' => now()->subHour()->toIso8601String()]);

        $this->postJson('/api/v1/sync/events', ['events' => [$newer]], $this->bearer($issued))->assertOk();
        $this->postJson('/api/v1/sync/events', ['events' => [$older]], $this->bearer($issued))->assertOk();

        $this->assertSame('completed', ShopRecord::sole()->field('status'));
    }

    public function test_deleting_marks_the_record_removed_and_restoring_brings_it_back(): void
    {
        $issued = $this->issueDevice();
        $payload = ['id' => 4, 'name' => 'Bosch'];
        $deletedAt = now()->subMinutes(2)->utc()->format('Y-m-d\TH:i:s.v\Z');

        $this->postJson('/api/v1/sync/events', ['events' => [
            $this->recordEvent('brand', 'created', $payload, ['occurred_at' => now()->subMinutes(10)->toIso8601String()]),
            $this->recordEvent('brand', 'deleted', $payload + ['deleted_at' => $deletedAt], ['occurred_at' => now()->subMinutes(2)->toIso8601String()]),
        ]], $this->bearer($issued))->assertOk();

        $this->assertTrue(ShopRecord::sole()->isRemoved());

        $this->postJson('/api/v1/sync/events', ['events' => [
            $this->recordEvent('brand', 'restored', $payload + ['deleted_at' => null], ['occurred_at' => now()->subMinute()->toIso8601String()]),
        ]], $this->bearer($issued))->assertOk();

        $this->assertFalse(ShopRecord::sole()->isRemoved());
    }

    public function test_a_hard_delete_without_a_deleted_at_still_removes_the_record(): void
    {
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => [
            $this->recordEvent('brand', 'created', ['id' => 4, 'name' => 'Bosch'], ['occurred_at' => now()->subMinutes(10)->toIso8601String()]),
            $this->recordEvent('brand', 'deleted', ['id' => 4, 'name' => 'Bosch'], ['occurred_at' => now()->subMinute()->toIso8601String()]),
        ]], $this->bearer($issued))->assertOk();

        $this->assertTrue(ShopRecord::sole()->isRemoved());
    }

    public function test_only_allowlisted_plain_fields_are_stored_so_secrets_never_reach_the_mirror(): void
    {
        $issued = $this->issueDevice();
        $event = $this->recordEvent('user', 'created', [
            'id' => 9,
            'first_name' => 'Ali',
            'last_name' => 'Rezaei',
            'mobile' => '09120000000',
            'password' => '$2y$12$hashhashhashhashhashhash',
            'otp_code' => '123456',
            'remember_token' => 'remember-me',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'nested' => ['a' => 1],
        ]);

        $this->postJson('/api/v1/sync/events', ['events' => [$event]], $this->bearer($issued))->assertOk();

        $record = ShopRecord::sole();
        $this->assertSame('Ali Rezaei', $record->label);
        $this->assertEqualsCanonicalizing(['id', 'first_name', 'last_name', 'mobile'], array_keys($record->data));
        $this->assertStringNotContainsString('hashhash', json_encode($record->getAttributes()));
    }

    public function test_the_same_local_id_in_two_shops_stays_two_records(): void
    {
        $alpha = $this->issueDevice();
        $beta = $this->issueDevice();

        foreach ([$alpha, $beta] as $issued) {
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/sync/events', ['events' => [
                $this->recordEvent('brand', 'created', ['id' => 1, 'name' => 'Shop '.$issued->device->shop_id]),
            ]], $this->bearer($issued))->assertOk();
        }

        $this->assertSame(2, ShopRecord::count());
        $this->assertSame(1, ShopRecord::forShop($alpha->device->shop_id)->count());
    }

    public function test_unknown_entities_and_events_without_a_record_id_are_stored_but_not_mirrored(): void
    {
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => [
            $this->recordEvent('settings', 'updated', ['id' => 1, 'value' => 'secret']),
            $this->recordEvent('brand', 'updated', ['name' => 'No id']),
        ]], $this->bearer($issued))->assertOk()->assertJsonPath('meta.accepted', 2);

        $this->assertSame(2, SyncEvent::count());
        $this->assertSame(0, ShopRecord::count());
    }

    public function test_replaying_the_inbox_rebuilds_the_mirror(): void
    {
        $shop = Shop::factory()->create();
        $device = $this->issueDevice($shop)->device;

        foreach ([['pending', 10], ['completed', 5]] as [$status, $minutesAgo]) {
            SyncEvent::factory()->for($device, 'device')->create([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'order.updated',
                'aggregate_type' => 'order',
                'payload' => ['id' => 2, 'order_number' => 'ORD-2', 'status' => $status],
                'occurred_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        $this->assertSame(0, ShopRecord::count());

        $this->artisan('jocko:project-events')->assertSuccessful();
        $this->artisan('jocko:project-events', ['--shop' => $shop->getKey()])->assertSuccessful();

        $this->assertSame('completed', ShopRecord::sole()->field('status'));
    }

    public function test_the_projector_only_supports_events_named_after_their_entity(): void
    {
        $projector = app(ShopRecordProjector::class);

        $this->assertTrue($projector->supports(new SyncEvent(['event_type' => 'order.created', 'aggregate_type' => 'order'])));
        $this->assertFalse($projector->supports(new SyncEvent(['event_type' => 'payment.created', 'aggregate_type' => 'order'])));
        $this->assertFalse($projector->supports(new SyncEvent(['event_type' => 'repair_order.created', 'aggregate_type' => 'repair_order'])));
    }
}
