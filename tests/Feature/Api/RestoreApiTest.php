<?php

namespace Tests\Feature\Api;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShopMirror;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class RestoreApiTest extends TestCase
{
    use BuildsShopMirror, CreatesDevices, RefreshDatabase;

    public function test_a_device_cannot_read_its_data_unless_an_administrator_allowed_it(): void
    {
        $issued = $this->issueDevice();
        $this->mirror($issued->device->shop, 'brand', 1, ['name' => 'Bosch']);

        $this->getJson('/api/v1/restore', $this->bearer($issued))->assertForbidden();
        $this->getJson('/api/v1/restore/brand', $this->bearer($issued))->assertForbidden();
    }

    public function test_the_permission_runs_out(): void
    {
        $issued = $this->issueDevice(Shop::factory()->create(['restore_allowed_until' => now()->subMinute()]));

        $this->getJson('/api/v1/restore', $this->bearer($issued))->assertForbidden();
    }

    public function test_an_administrator_opens_a_limited_window_and_it_is_audited(): void
    {
        $shop = Shop::factory()->create();
        $admin = User::factory()->create();

        resolve(ShopManagementService::class)->allowRestore($shop, $admin);

        $this->assertTrue($shop->fresh()->restoreAllowed());
        $this->assertEqualsWithDelta(24 * 3600, now()->diffInSeconds($shop->fresh()->restore_allowed_until), 5);
        $this->assertDatabaseHas('audit_logs', ['shop_id' => $shop->id, 'user_id' => $admin->id, 'action' => AuditAction::ShopRestoreAllowed->value]);
    }

    public function test_the_summary_counts_what_can_be_restored_and_is_audited(): void
    {
        $shop = Shop::factory()->create(['restore_allowed_until' => now()->addHour()]);
        $issued = $this->issueDevice($shop);

        $this->mirror($shop, 'brand', 1, ['name' => 'Bosch']);
        $this->mirror($shop, 'brand', 2, ['name' => 'Mann']);
        $this->mirror($shop, 'color', 1, ['name' => 'Red', 'hex' => '#f00']);
        $this->mirror($shop, 'brand', 3, ['name' => 'Gone'], removed: true);

        $this->getJson('/api/v1/restore', $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.entities.brand', 2)
            ->assertJsonPath('data.entities.color', 1);

        $this->assertSame(1, AuditLog::where('action', AuditAction::ShopRestoreStarted)->count());
    }

    public function test_records_come_back_in_pages_by_local_id(): void
    {
        $shop = Shop::factory()->create(['restore_allowed_until' => now()->addHour()]);
        $issued = $this->issueDevice($shop);

        foreach (range(1, 503) as $id) {
            $this->mirror($shop, 'brand', $id, ['name' => "Brand {$id}"]);
        }

        $first = $this->getJson('/api/v1/restore/brand', $this->bearer($issued))->assertOk();
        $this->assertCount(500, $first->json('data.records'));
        $this->assertSame(500, $first->json('data.next'));
        $this->assertSame(1, $first->json('data.records.0.id'));

        $second = $this->getJson('/api/v1/restore/brand?after=500', $this->bearer($issued))->assertOk();
        $this->assertSame([501, 502, 503], array_column($second->json('data.records'), 'id'));
        $this->assertNull($second->json('data.next'));
    }

    public function test_soft_deleted_records_come_back_but_ones_removed_for_good_do_not(): void
    {
        $shop = Shop::factory()->create(['restore_allowed_until' => now()->addHour()]);
        $issued = $this->issueDevice($shop);

        $this->mirror($shop, 'brand', 1, ['name' => 'Kept']);
        $this->mirror($shop, 'brand', 2, ['name' => 'Soft deleted', 'deleted_at' => '2026-11-01T10:00:00.000000Z'], removed: true);
        $this->mirror($shop, 'brand', 3, ['name' => 'Hard deleted'], removed: true);

        $ids = array_column($this->getJson('/api/v1/restore/brand', $this->bearer($issued))->json('data.records'), 'id');

        $this->assertSame([1, 2], $ids);
    }

    public function test_a_device_only_ever_sees_its_own_shop(): void
    {
        $mine = Shop::factory()->create(['restore_allowed_until' => now()->addHour()]);
        $theirs = Shop::factory()->create(['restore_allowed_until' => now()->addHour()]);
        $issued = $this->issueDevice($mine);

        $this->mirror($mine, 'brand', 1, ['name' => 'Mine']);
        $this->mirror($theirs, 'brand', 2, ['name' => 'Theirs']);

        $names = array_column($this->getJson('/api/v1/restore/brand', $this->bearer($issued))->json('data.records'), 'name');

        $this->assertSame(['Mine'], $names);
    }

    public function test_an_unknown_entity_is_not_found(): void
    {
        $issued = $this->issueDevice(Shop::factory()->create(['restore_allowed_until' => now()->addHour()]));

        $this->getJson('/api/v1/restore/passwords', $this->bearer($issued))->assertNotFound();
    }
}
