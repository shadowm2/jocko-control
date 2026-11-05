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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function cheque(int $id, string $amount, ?string $dueOn, string $status = 'pending', array $extra = [], bool $removed = false): void
    {
        $this->mirror($this->shop, 'payment', $id, array_merge([
            'order_id' => 1, 'amount' => $amount, 'method' => 'cheque', 'paid_at' => now()->toDateString(),
            'cheque_number' => "CH-{$id}", 'cheque_bank' => 'Melli', 'cheque_due_at' => $dueOn, 'cheque_status' => $status,
        ], $extra), "#{$id}", $removed);
    }

    public function test_cheques_add_up_what_is_pending_and_what_is_past_due_nearest_due_date_first(): void
    {
        $today = now('Asia/Tehran');

        $this->cheque(1, '300000.0000', $today->copy()->addDays(10)->toDateString());
        $this->cheque(2, '100000.0000', $today->copy()->subDays(3)->toDateString());
        $this->cheque(3, '50000.0000', $today->toDateString());
        $this->cheque(4, '70000.0000', null);
        // Not waiting any more, never money, deleted, or not a cheque at all.
        $this->cheque(5, '900000.0000', $today->copy()->subDays(9)->toDateString(), 'cleared');
        $this->cheque(6, '800000.0000', $today->copy()->subDays(8)->toDateString(), 'bounced');
        $this->cheque(7, '700000.0000', $today->copy()->subDays(7)->toDateString(), removed: true);
        $this->cheque(8, '600000.0000', $today->copy()->subDays(6)->toDateString(), extra: ['method' => 'cash', 'cheque_status' => null]);

        $metrics = app(ShopDashboardMetrics::class)->for($this->shop);

        $this->assertSame(4, $metrics['pendingChequeCount']);
        $this->assertSame(520000.0, $metrics['pendingChequeTotal']);
        // Due today is not yet overdue, and a cheque without a date cannot be.
        $this->assertSame(1, $metrics['overdueChequeCount']);
        $this->assertSame(100000.0, $metrics['overdueChequeTotal']);
        $this->assertSame(['CH-2', 'CH-3', 'CH-1', 'CH-4'], array_column($metrics['cheques'], 'number'));
        $this->assertSame([true, false, false, false], array_column($metrics['cheques'], 'overdue'));
        $this->assertNull($metrics['cheques'][3]['due']);
    }

    public function test_the_cheque_card_lists_each_cheque_and_flags_the_overdue_ones(): void
    {
        $this->mirror($this->shop, 'user', 1, ['type' => 'customer', 'first_name' => 'Ali', 'last_name' => 'Rezaei'], 'Ali Rezaei');

        Livewire::test(Dashboard::class, ['shop' => $this->shop])
            ->assertSee(__('ui.shop_dashboard.no_cheques'))
            ->assertDontSee(__('ui.shop_dashboard.overdue'));

        $this->cheque(1, '100000.0000', now('Asia/Tehran')->subDays(2)->toDateString());
        $this->cheque(2, '250000.0000', now('Asia/Tehran')->addDays(5)->toDateString());

        Livewire::test(Dashboard::class, ['shop' => $this->shop])
            ->assertSee('CH-1')
            ->assertSee('CH-2')
            ->assertSee(Format::number(250000.0))
            ->assertSee(Format::number(1).' '.__('ui.shop_dashboard.overdue'))
            ->assertDontSee(__('ui.shop_dashboard.no_cheques'));
    }

    private function close(int $id, int $daysAgo, string $difference, bool $removed = false): void
    {
        $day = now('Asia/Tehran')->subDays($daysAgo)->toDateString();

        $this->mirror($this->shop, 'cash_close', $id, [
            'business_date' => $day, 'expected_cash' => '100.0000', 'counted_cash' => '100.0000', 'difference' => $difference,
        ], $day, $removed);
    }

    public function test_cash_differences_count_unbalanced_days_of_the_last_thirty_days_newest_first(): void
    {
        $this->close(1, 0, '0.0000');
        $this->close(2, 1, '-25000.0000');
        $this->close(3, 3, '5000.0000');
        $this->close(4, 29, '-1.0000');
        $this->close(5, 31, '-99999.0000');
        $this->close(6, 2, '-77777.0000', removed: true);

        $metrics = app(ShopDashboardMetrics::class)->for($this->shop);

        // The balanced day, the one 31 days ago and the removed one are not differences;
        // only the days inside the window are closes at all.
        $this->assertSame(4, $metrics['cashCloseCount']);
        $this->assertSame(3, $metrics['cashDifferenceCount']);
        $this->assertSame([-25000.0, 5000.0, -1.0], array_column($metrics['cashDifferences'], 'difference'));
    }

    public function test_the_cash_card_tells_a_shop_that_never_closes_from_one_that_balances(): void
    {
        $this->mirror($this->shop, 'user', 1, ['type' => 'customer', 'first_name' => 'Ali', 'last_name' => 'Rezaei'], 'Ali Rezaei');

        Livewire::test(Dashboard::class, ['shop' => $this->shop])
            ->assertSee(__('ui.shop_dashboard.no_cash_closes'))
            ->assertDontSee(__('ui.shop_dashboard.cash_balanced'));

        $this->close(1, 0, '0.0000');

        Livewire::test(Dashboard::class, ['shop' => $this->shop])
            ->assertSee(__('ui.shop_dashboard.cash_balanced'))
            ->assertDontSee(__('ui.shop_dashboard.no_cash_closes'));
    }

    public function test_the_cash_card_shows_a_shortage_and_an_overage_with_their_sign(): void
    {
        $this->mirror($this->shop, 'user', 1, ['type' => 'customer', 'first_name' => 'Ali', 'last_name' => 'Rezaei'], 'Ali Rezaei');
        $this->close(1, 1, '-25000.0000');
        $this->close(2, 2, '5000.0000');

        Livewire::test(Dashboard::class, ['shop' => $this->shop])
            ->assertSee(__('ui.shop_dashboard.cash_differences'))
            ->assertSee(Format::number(-25000.0))
            ->assertSee('+'.Format::number(5000.0));
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
