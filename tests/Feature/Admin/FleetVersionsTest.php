<?php

namespace Tests\Feature\Admin;

use App\Livewire\Dashboard;
use App\Livewire\Shops\DevicesPanel;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;
use App\Services\FleetVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FleetVersionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function device(Shop $shop, ?string $version): ShopDevice
    {
        return ShopDevice::factory()->for($shop)->create(['app_version' => $version]);
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function releases(): array
    {
        return [
            'plain' => ['1.4', '1.4'],
            'three parts' => ['2.0.1', '2.0.1'],
            'with a v' => ['v3.1', '3.1'],
            'with a commit' => ['1.2.3@abc1234', '1.2.3'],
            'a branch' => ['dev-main@abc1234', null],
            'a bare commit' => ['abc1234', null],
            'text' => ['beta', null],
            'nothing' => [null, null],
        ];
    }

    #[DataProvider('releases')]
    public function test_it_tells_release_numbers_from_everything_else(?string $reported, ?string $release): void
    {
        $this->assertSame($release, app(FleetVersions::class)->release($reported));
    }

    public function test_it_puts_versions_in_numeric_order_not_alphabetical(): void
    {
        $versions = app(FleetVersions::class);

        $this->assertSame('1.10', $versions->latest(['1.9', '1.10', '1.2', 'dev-main']));
        $this->assertTrue($versions->isOutdated('1.9', '1.10'));
        $this->assertFalse($versions->isOutdated('1.10', '1.10'));
        $this->assertFalse($versions->isOutdated('dev-main@abc', '1.10'), 'a branch cannot be compared');
        $this->assertFalse($versions->isOutdated('1.0', null));
    }

    public function test_it_counts_the_devices_on_each_version_and_how_many_are_behind(): void
    {
        $shop = Shop::factory()->create();
        $this->device($shop, '1.2');
        $this->device($shop, '1.2');
        $this->device($shop, '1.3');
        $this->device($shop, '1.0');
        $this->device($shop, null);
        // Not connectable: a revoked device and a suspended shop's device are left out.
        ShopDevice::factory()->for($shop)->revoked()->create(['app_version' => '9.9']);
        ShopDevice::factory()->for(Shop::factory()->suspended()->create())->create(['app_version' => '8.8']);

        $summary = app(FleetVersions::class)->summary();

        $this->assertSame('1.3', $summary['latest']);
        $this->assertSame(3, $summary['outdated_devices']);
        $this->assertSame(1, $summary['unreported_devices']);
        $this->assertSame([
            ['version' => '1.2', 'devices' => 2, 'latest' => false, 'outdated' => true],
            ['version' => '1.0', 'devices' => 1, 'latest' => false, 'outdated' => true],
            ['version' => '1.3', 'devices' => 1, 'latest' => true, 'outdated' => false],
        ], $summary['rows']);
    }

    public function test_the_dashboard_shows_the_versions_and_flags_the_old_ones(): void
    {
        $shop = Shop::factory()->create();
        $this->device($shop, '2.0');
        $this->device($shop, '1.0');

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('data-versions')
            ->assertSeeHtml('data-version="2.0"')
            ->assertSeeHtml('data-version="1.0"')
            ->assertSeeHtml('data-outdated-count="1"');
    }

    public function test_the_dashboard_has_no_versions_card_without_devices(): void
    {
        Livewire::test(Dashboard::class)->assertDontSeeHtml('data-versions');
    }

    public function test_versions_that_are_not_releases_are_listed_without_calling_any_outdated(): void
    {
        $shop = Shop::factory()->create();
        $this->device($shop, 'dev-main@abc1234');
        $this->device($shop, 'dev-main@def5678');

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('data-version="dev-main@abc1234"')
            ->assertDontSeeHtml('data-outdated-count')
            ->assertSee(__('ui.dashboard.versions_hint'));
    }

    public function test_a_shop_page_marks_a_device_that_is_behind_the_fleet(): void
    {
        $shop = Shop::factory()->create();
        $this->device($shop, '1.0');
        $other = Shop::factory()->create();
        $this->device($other, '1.5');

        Livewire::test(DevicesPanel::class, ['shopId' => $shop->getKey()])->assertSeeHtml('data-outdated');
        Livewire::test(DevicesPanel::class, ['shopId' => $other->getKey()])->assertDontSeeHtml('data-outdated');
    }
}
