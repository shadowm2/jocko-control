<?php

namespace Database\Seeders;

use App\Enums\CommandType;
use App\Models\RemoteCommand;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\DeviceManagementService;
use App\Services\RemoteCommandService;
use App\Services\ShopManagementService;
use Illuminate\Database\Seeder;

/**
 * Sample shops, devices, events and commands for local development.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Needs an existing administrator (php artisan jocko:create-admin). It prints
 * one device token so the device API can be tried right away; that token is a
 * development secret and must not be committed anywhere.
 */
class DemoSeeder extends Seeder
{
    public function run(
        ShopManagementService $shops,
        DeviceManagementService $devices,
        RemoteCommandService $commands,
    ): void {
        if (app()->isProduction()) {
            $this->command->error('The demo seeder never runs in production.');

            return;
        }

        $admin = User::query()->first();

        if ($admin === null) {
            $this->command->error('Create an administrator first: php artisan jocko:create-admin');

            return;
        }

        $alpha = $shops->create(['name' => 'تعمیرگاه آلفا', 'slug' => 'alpha-garage', 'owner_name' => 'رضا احمدی', 'phone' => '09120000001', 'timezone' => 'Asia/Tehran'], $admin);
        $beta = $shops->create(['name' => 'تعمیرگاه بتا', 'slug' => 'beta-garage', 'owner_name' => 'مریم کریمی', 'timezone' => 'Asia/Tehran'], $admin);
        $gamma = $shops->create(['name' => 'تعمیرگاه گاما', 'slug' => 'gamma-garage'], $admin);
        $shops->suspend($gamma, $admin);

        $front = $devices->register($alpha, 'صندوق اصلی', $admin);
        $workshop = $devices->register($alpha, 'کارگاه', $admin);
        $betaDesk = $devices->register($beta, 'پذیرش', $admin);
        $devices->register($beta, 'دستگاه تازه ثبت‌شده', $admin);
        $old = $devices->register($gamma, 'دستگاه قدیمی', $admin);
        $devices->revoke($devices->register($beta, 'دستگاه ابطال‌شده', $admin)->device, $admin);

        $front->device->forceFill(['last_seen_at' => now()->subMinutes(2), 'last_sync_at' => now()->subMinutes(2), 'app_version' => '1.4.2'])->save();
        $workshop->device->forceFill(['last_seen_at' => now()->subHours(3), 'last_sync_error' => 'Local database is locked', 'last_sync_error_at' => now()->subHours(3), 'app_version' => '1.4.0'])->save();
        $betaDesk->device->forceFill(['last_seen_at' => now()->subMinutes(40), 'last_sync_at' => now()->subMinutes(41), 'app_version' => '1.4.2'])->save();
        $old->device->forceFill(['last_seen_at' => now()->subDay()])->save();

        SyncEvent::factory()->count(12)->create(['device_id' => $front->device->id]);
        SyncEvent::factory()->count(5)->create(['device_id' => $betaDesk->device->id, 'event_type' => 'inventory.adjusted', 'aggregate_type' => 'inventory_item']);

        $commands->queue($admin, $front->device, CommandType::CheckVersion);
        $commands->queue($admin, $betaDesk->device, CommandType::SyncNow, ['full' => true]);
        RemoteCommand::factory()->completed()->create(['device_id' => $front->device->id, 'requested_by' => $admin->id, 'command_type' => CommandType::CollectDiagnostics, 'result' => ['disk_free_mb' => 20480]]);

        $this->command->info('Demo data created.');
        $this->command->line('Device token for "'.$front->device->name.'" (development only, shown once):');
        $this->command->line($front->plainTextToken);

    }
}
