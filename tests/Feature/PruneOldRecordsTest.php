<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneOldRecordsTest extends TestCase
{
    use RefreshDatabase;

    private ShopDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        $this->device = ShopDevice::factory()->for(Shop::factory())->create();
    }

    public function test_it_deletes_only_what_is_older_than_the_retention_period(): void
    {
        config(['jocko.retention.events_days' => 30, 'jocko.retention.audit_days' => 10]);

        $oldEvent = SyncEvent::factory()->create(['device_id' => $this->device->id, 'received_at' => now()->subDays(31)]);
        $newEvent = SyncEvent::factory()->create(['device_id' => $this->device->id, 'received_at' => now()->subDays(29)]);
        AuditLog::query()->insert([
            ['action' => 'admin_login', 'created_at' => now()->subDays(11)],
            ['action' => 'admin_login', 'created_at' => now()->subDays(9)],
        ]);

        $this->artisan('jocko:prune')->assertSuccessful();

        $this->assertModelMissing($oldEvent);
        $this->assertModelExists($newEvent);
        $this->assertSame(1, AuditLog::query()->count());
        $this->assertTrue(AuditLog::query()->first()->created_at->greaterThan(now()->subDays(10)));
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        config(['jocko.retention.events_days' => 30]);
        $old = SyncEvent::factory()->create(['device_id' => $this->device->id, 'received_at' => now()->subDays(90)]);

        $this->artisan('jocko:prune --dry-run')->assertSuccessful();

        $this->assertModelExists($old);
    }

    public function test_zero_keeps_everything(): void
    {
        config(['jocko.retention.events_days' => 0, 'jocko.retention.audit_days' => 0]);
        $old = SyncEvent::factory()->create(['device_id' => $this->device->id, 'received_at' => now()->subYears(5)]);
        AuditLog::query()->insert(['action' => 'admin_login', 'created_at' => now()->subYears(5)]);

        $this->artisan('jocko:prune')->assertSuccessful();

        $this->assertModelExists($old);
        $this->assertSame(1, AuditLog::query()->count());
    }
}
