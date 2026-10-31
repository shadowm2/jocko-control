<?php

namespace Tests\Feature\Admin;

use App\Livewire\Shops\Dashboard;
use App\Models\Shop;
use App\Models\ShopRecord;
use App\Models\User;
use App\Services\ShopDashboardMetrics;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

class ShopDashboardTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setTime(12, 0));
        $this->actingAs(User::factory()->create());
        $this->shop = Shop::factory()->create(['name' => 'Alpha Garage', 'timezone' => 'Asia/Tehran']);
    }

    public function test_guests_cannot_see_the_dashboard(): void
    {
        auth()->logout();

        $this->get(route('shops.dashboard', $this->shop))->assertRedirect(route('login'));
    }

    public function test_a_shop_without_data_says_so_instead_of_showing_zeroes(): void
    {
        $this->get(route('shops.dashboard', $this->shop))
            ->assertOk()
            ->assertSee(__('ui.shop_data.empty'))
            ->assertDontSee(__('ui.shop_dashboard.total_orders'));
    }

    public function test_the_headline_numbers_follow_the_shops_own_rules(): void
    {
        $this->seedShop($this->shop);

        $metrics = app(ShopDashboardMetrics::class)->for($this->shop);

        // Removed orders are not counted; cancelled ones are counted but are not revenue.
        $this->assertSame(4, $metrics['orderCount']);
        $this->assertSame(['pending' => 1, 'completed' => 1, 'cancelled' => 1, 'in_progress' => 1], $metrics['statusCounts']);
        $this->assertSame(800000.0, $metrics['orderValue']);
        $this->assertSame(1, $metrics['openPurchases']);
        $this->assertSame(1, $metrics['customerCount']);
    }

    public function test_low_stock_lists_items_at_or_under_their_minimum_only(): void
    {
        $this->seedShop($this->shop);

        $metrics = app(ShopDashboardMetrics::class)->for($this->shop);

        $this->assertSame(1, $metrics['lowStockCount']);
        $this->assertSame('Brake pad', $metrics['lowStock'][0]['item']);
        $this->assertSame('Main', $metrics['lowStock'][0]['warehouse']);
        $this->assertSame('1.000', $metrics['lowStock'][0]['quantity']);
        $this->assertSame('2.000', $metrics['lowStock'][0]['minimum']);
    }

    public function test_sales_and_purchases_are_bucketed_by_month_and_day_without_cancelled_or_removed_work(): void
    {
        $this->seedShop($this->shop);

        $metrics = app(ShopDashboardMetrics::class)->for($this->shop);

        $this->assertCount(6, $metrics['monthLabels']);
        $this->assertCount(30, $metrics['dayLabels']);
        // ORD-1 (300 000) and ORD-2 (500 000, 40 days ago) are inside six months; ORD-3 is cancelled.
        $this->assertSame(800000.0, array_sum($metrics['monthSales']));
        $this->assertSame(360000.0, array_sum($metrics['monthPurchases']));
        // Only ORD-1 is dated inside the last 30 days, and today is the last bucket.
        $this->assertSame(300000.0, array_sum($metrics['daySales']));
        $this->assertSame(300000.0, $metrics['daySales'][29]);
        $this->assertSame(300000.0, $metrics['monthSales'][5]);
    }

    public function test_recent_orders_come_newest_first_with_customer_car_and_total(): void
    {
        $this->seedShop($this->shop);

        $recent = app(ShopDashboardMetrics::class)->for($this->shop)['recentOrders'];

        $this->assertSame(['ORD-4', 'ORD-3', 'ORD-2', 'ORD-1'], array_column($recent, 'number'));
        $this->assertSame('Ali Rezaei', $recent[3]['customer']);
        $this->assertSame('Pride', $recent[3]['car']);
        $this->assertSame('Main', $recent[3]['warehouse']);
        $this->assertSame(300000.0, $recent[3]['total']);
    }

    public function test_recent_purchases_name_the_supplier_and_total_their_items(): void
    {
        $this->seedShop($this->shop);

        $purchases = app(ShopDashboardMetrics::class)->for($this->shop)['recentPurchases'];

        $this->assertSame(['PO-2', 'PO-1'], array_column($purchases, 'number'));
        $this->assertSame('Sara Parts', $purchases[1]['supplier']);
        $this->assertSame(360000.0, $purchases[1]['total']);
    }

    public function test_another_shops_data_never_leaks_into_the_numbers(): void
    {
        $this->seedShop($this->shop);
        $other = Shop::factory()->create();
        $this->seedShop($other);
        $this->mirror($other, 'order', 99, ['order_number' => 'ORD-99', 'status' => 'pending', 'created_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')], 'ORD-99');

        $metrics = app(ShopDashboardMetrics::class)->for($this->shop);

        $this->assertSame(4, $metrics['orderCount']);
        $this->assertSame(800000.0, $metrics['orderValue']);
        $this->assertNotContains('ORD-99', array_column($metrics['recentOrders'], 'number'));
    }

    public function test_the_page_shows_the_same_numbers_as_the_shop_dashboard(): void
    {
        $this->seedShop($this->shop);

        Livewire::test(Dashboard::class, ['shop' => $this->shop])
            ->assertOk()
            ->assertSee(__('ui.shop_dashboard.total_orders'))
            ->assertSeeHtml('data-stat="0"')
            ->assertSee(Format::number(4))
            ->assertSee(Format::number(800000.0))
            ->assertSee(__('ui.shop_dashboard.sales_vs_purchases'))
            ->assertSee(__('ui.shop_dashboard.daily_sales'))
            ->assertSee(__('ui.shop_dashboard.orders_by_status'))
            ->assertSee('ORD-1')
            ->assertSee('Ali Rezaei')
            ->assertSee('Brake pad')
            ->assertSee('PO-1')
            ->assertSee(route('shops.orders.show', [$this->shop, ShopRecord::where('entity', 'order')->where('local_id', 1)->sole()]), false);
    }

    public function test_the_shop_tabs_link_the_dashboard_orders_and_all_data(): void
    {
        $this->get(route('shops.show', $this->shop))
            ->assertOk()
            ->assertSee(route('shops.dashboard', $this->shop), false)
            ->assertSee(route('shops.orders', $this->shop), false)
            ->assertSee(route('shops.data', $this->shop), false);
    }
}
