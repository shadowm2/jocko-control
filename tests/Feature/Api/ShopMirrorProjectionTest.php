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

    public function test_a_closed_day_is_mirrored_by_its_date_and_its_amounts_are_kept(): void
    {
        $issued = $this->issueDevice();
        $event = $this->recordEvent('cash_close', 'created', [
            'id' => 4, 'business_date' => '2026-11-04', 'expected_cash' => '150.0000', 'counted_cash' => '140.0000',
            'difference' => '-10.0000', 'note' => 'short', 'closed_by' => 1, 'totals' => ['cash' => 150],
        ]);

        $this->postJson('/api/v1/sync/events', ['events' => [$event]], $this->bearer($issued))->assertOk();

        $record = ShopRecord::sole();
        $this->assertSame('cash_close', $record->entity);
        $this->assertSame('2026-11-04', $record->dated_at->toDateString());
        $this->assertSame('-10.0000', (string) $record->field('difference'));
        $this->assertNull($record->field('totals'));
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

    public function test_only_allowlisted_plain_fields_are_stored_and_the_only_secret_kept_is_the_password_hash(): void
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
        // The password hash is kept on purpose (to restore a lost shop); OTPs, tokens and 2FA secrets never are.
        $this->assertEqualsCanonicalizing(['id', 'first_name', 'last_name', 'mobile', 'password'], array_keys($record->data));
        $this->assertSame('$2y$12$hashhashhashhashhashhash', $record->data['password']);
        $encoded = json_encode($record->getAttributes());
        foreach (['123456', 'remember-me', 'JBSWY3DPEHPK3PXP'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
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

    public function test_the_indexed_columns_hold_the_parent_the_amount_and_the_date_a_record_counts_for(): void
    {
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => [
            $this->recordEvent('order_item', 'created', ['id' => 3, 'order_id' => 7, 'total' => '300000.0000', 'created_at' => '2026-10-20T08:00:00.000000Z']),
            $this->recordEvent('payment', 'created', ['id' => 4, 'order_id' => 7, 'amount' => '100000.0000', 'paid_at' => '2026-10-22T00:00:00.000000Z', 'created_at' => '2026-10-25T08:00:00.000000Z']),
            $this->recordEvent('purchase', 'created', ['id' => 5, 'order_number' => 'PO-5', 'ordered_at' => '2026-10-21T09:00:00.000000Z', 'created_at' => '2026-10-19T09:00:00.000000Z']),
            $this->recordEvent('purchase', 'created', ['id' => 6, 'order_number' => 'PO-6', 'ordered_at' => null, 'created_at' => '2026-10-18T09:00:00.000000Z']),
            $this->recordEvent('brand', 'created', ['id' => 8, 'name' => 'Bosch']),
        ]], $this->bearer($issued))->assertOk();

        $item = ShopRecord::where('entity', 'order_item')->sole();
        $this->assertSame(7, $item->parent_id);
        $this->assertSame('300000.0000', $item->amount);
        $this->assertSame('2026-10-20 08:00:00', $item->dated_at->utc()->format('Y-m-d H:i:s'));

        $payment = ShopRecord::where('entity', 'payment')->sole();
        $this->assertSame('100000.0000', $payment->amount);
        $this->assertSame('2026-10-22', $payment->dated_at->utc()->format('Y-m-d'), 'a payment counts on the day it was paid');

        $this->assertSame('2026-10-21', ShopRecord::where('label', 'PO-5')->sole()->dated_at->utc()->format('Y-m-d'));
        $this->assertSame('2026-10-18', ShopRecord::where('label', 'PO-6')->sole()->dated_at->utc()->format('Y-m-d'), 'no ordered_at falls back to created_at');

        $brand = ShopRecord::where('entity', 'brand')->sole();
        $this->assertNull($brand->parent_id);
        $this->assertNull($brand->amount);
        $this->assertNull($brand->dated_at);
    }

    public function test_a_garbage_amount_or_parent_is_ignored_rather_than_stored(): void
    {
        $issued = $this->issueDevice();

        $this->postJson('/api/v1/sync/events', ['events' => [
            $this->recordEvent('order_item', 'created', ['id' => 3, 'order_id' => 'seven', 'total' => 'a lot']),
        ]], $this->bearer($issued))->assertOk();

        $item = ShopRecord::sole();
        $this->assertNull($item->parent_id);
        $this->assertNull($item->amount);
    }

    public function test_replaying_the_inbox_backfills_rows_projected_before_the_columns_existed(): void
    {
        $issued = $this->issueDevice();
        $this->postJson('/api/v1/sync/events', ['events' => [
            $this->recordEvent('order_item', 'created', ['id' => 3, 'order_id' => 7, 'total' => '300000.0000', 'created_at' => '2026-10-20T08:00:00.000000Z']),
        ]], $this->bearer($issued))->assertOk();
        ShopRecord::query()->update(['parent_id' => null, 'amount' => null, 'dated_at' => null]);

        $this->artisan('jocko:project-events')->assertSuccessful();

        $item = ShopRecord::sole();
        $this->assertSame(7, $item->parent_id);
        $this->assertSame('300000.0000', $item->amount);
        $this->assertNotNull($item->dated_at);
    }

    public function test_the_projector_only_supports_events_named_after_their_entity(): void
    {
        $projector = app(ShopRecordProjector::class);

        $this->assertTrue($projector->supports(new SyncEvent(['event_type' => 'order.created', 'aggregate_type' => 'order'])));
        $this->assertFalse($projector->supports(new SyncEvent(['event_type' => 'payment.created', 'aggregate_type' => 'order'])));
        $this->assertFalse($projector->supports(new SyncEvent(['event_type' => 'repair_order.created', 'aggregate_type' => 'repair_order'])));
    }
}
