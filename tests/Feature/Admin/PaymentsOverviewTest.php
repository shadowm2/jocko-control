<?php

namespace Tests\Feature\Admin;

use App\Livewire\Payments\Index;
use App\Models\Shop;
use App\Models\User;
use App\Services\PaymentsOverview;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

class PaymentsOverviewTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private Shop $alpha;

    private Shop $beta;

    private int $next = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        config(['jocko.display_timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));

        $this->alpha = Shop::factory()->create(['name' => 'Alpha']);
        $this->beta = Shop::factory()->create(['name' => 'Beta']);
    }

    private function paid(Shop $shop, string $method, float $amount, string $on, bool $removed = false): void
    {
        $this->mirror($shop, 'payment', $this->next++, ['method' => $method, 'amount' => $amount, 'paid_at' => $on], null, $removed);
    }

    public function test_it_adds_up_today_this_month_and_thirty_days_by_shop_and_method(): void
    {
        $this->paid($this->alpha, 'cash', 1000, '2026-11-12');
        $this->paid($this->alpha, 'pos', 5000, '2026-11-12');
        $this->paid($this->alpha, 'pos', 2000, '2026-11-05');
        $this->paid($this->beta, 'online', 4000, '2026-11-12');
        $this->paid($this->beta, 'cash', 700, '2026-10-20');

        $summary = app(PaymentsOverview::class)->summary();

        $this->assertSame(['today' => 10000.0, 'month' => 12000.0, 'days30' => 12700.0], $summary['totals']);
        $this->assertSame(['pos' => 7000.0, 'online' => 4000.0, 'cash' => 1000.0], $summary['byMethod']['month']);

        $alpha = collect($summary['shops'])->firstWhere('shop.name', 'Alpha');
        $this->assertSame([6000.0, 8000.0], [$alpha['today'], $alpha['month']]);
        $this->assertSame(['cash' => 1000.0, 'pos' => 7000.0], $alpha['methods']);

        $beta = collect($summary['shops'])->firstWhere('shop.name', 'Beta');
        $this->assertSame([4000.0, 4000.0, 4700.0], [$beta['today'], $beta['month'], $beta['days30']]);
    }

    public function test_the_windows_are_calendar_dates_with_no_gap_or_overlap_at_their_edges(): void
    {
        $this->paid($this->alpha, 'cash', 1, '2026-11-12');   // today
        $this->paid($this->alpha, 'cash', 10, '2026-11-11');  // yesterday: not today
        $this->paid($this->alpha, 'cash', 100, '2026-10-14'); // 29 days back: the first day of the 30
        $this->paid($this->alpha, 'cash', 1000, '2026-10-13'); // 30 days back: out
        $this->paid($this->alpha, 'cash', 10000, '2026-11-13'); // tomorrow: not yet

        $totals = app(PaymentsOverview::class)->summary()['totals'];

        $this->assertSame(1.0, $totals['today']);
        $this->assertSame(111.0, $totals['days30']);
    }

    public function test_a_refund_is_subtracted_and_removed_or_other_records_do_not_count(): void
    {
        $this->paid($this->alpha, 'online', 4000, '2026-11-12');
        $this->paid($this->alpha, 'online', -4000, '2026-11-12');
        $this->paid($this->alpha, 'cash', 900, '2026-11-12', removed: true);
        $this->mirror($this->alpha, 'order_item', 99, ['total' => 9999, 'paid_at' => '2026-11-12']);

        $summary = app(PaymentsOverview::class)->summary();

        $this->assertSame(0.0, $summary['totals']['today']);
        $this->assertSame(0.0, $summary['byMethod']['today']['online']);
        $this->assertArrayNotHasKey('cash', $summary['byMethod']['today']);
    }

    public function test_the_month_is_the_jalali_month(): void
    {
        // 12 November 2026 is 21 Aban 1405, and Aban began on 23 October.
        $this->paid($this->alpha, 'cash', 5, '2026-10-23');
        $this->paid($this->alpha, 'cash', 70, '2026-10-22');

        $this->assertSame(['from' => '2026-10-23', 'to' => '2026-11-12'], app(PaymentsOverview::class)->windows()['month']);
        $this->assertSame(5.0, app(PaymentsOverview::class)->summary()['totals']['month']);
    }

    public function test_the_page_lists_every_shop_biggest_first_with_labels_and_a_menu_link(): void
    {
        $this->paid($this->alpha, 'pos', 1000, '2026-11-12');
        $this->paid($this->beta, 'online', 9000, '2026-11-12');

        $page = $this->get(route('payments.index'))->assertOk()
            ->assertSee(route('payments.index'), false)
            ->assertSee('data-window="today"', false)
            ->assertSee(__('ui.shop_data.values.payment.method.pos'))
            ->assertSee(__('ui.shop_data.values.payment.method.online'));

        $page->assertSeeInOrder(['data-shop-row="'.$this->beta->getKey().'"', 'data-shop-row="'.$this->alpha->getKey().'"'], false);

        Livewire::test(Index::class)->assertSee('data-total="10000"', false);
    }

    public function test_it_needs_a_signed_in_administrator_and_works_with_no_payments(): void
    {
        Livewire::test(Index::class)->assertOk()->assertSee(__('ui.payments.none'));

        auth()->logout();

        $this->get(route('payments.index'))->assertRedirect(route('login'));
    }

    public function test_a_bounced_cheque_is_not_money_collected(): void
    {
        $this->paid($this->alpha, 'cash', 1000, '2026-11-12');
        $this->mirror($this->alpha, 'payment', $this->next++, ['method' => 'cheque', 'amount' => 5000, 'paid_at' => '2026-11-12', 'cheque_status' => 'bounced']);
        $this->mirror($this->alpha, 'payment', $this->next++, ['method' => 'cheque', 'amount' => 2000, 'paid_at' => '2026-11-12', 'cheque_status' => 'pending']);

        $summary = app(PaymentsOverview::class)->summary();

        $this->assertSame(3000.0, $summary['totals']['today']);
        $this->assertSame(['cheque' => 2000.0, 'cash' => 1000.0], $summary['byMethod']['today']);
    }

    private function cheque(Shop $shop, ?string $due, string $status = 'pending', float $amount = 1000, bool $removed = false): void
    {
        $this->mirror($shop, 'payment', $this->next++, ['method' => 'cheque', 'amount' => $amount, 'paid_at' => '2026-11-01', 'cheque_status' => $status, 'cheque_due_at' => $due, 'cheque_number' => 'CH'.$this->next], null, $removed);
    }

    public function test_it_lists_the_cheques_still_waiting_with_the_overdue_ones_first(): void
    {
        $this->cheque($this->alpha, '2026-11-20', amount: 3000);
        $this->cheque($this->alpha, '2026-11-01', amount: 2000);   // overdue
        $this->cheque($this->beta, '2026-11-12', amount: 500);     // due today: not overdue yet
        $this->cheque($this->beta, null, amount: 700);             // no due date: last
        $this->cheque($this->alpha, '2026-10-01', 'cleared');
        $this->cheque($this->alpha, '2026-10-02', 'bounced');
        $this->cheque($this->alpha, '2026-10-03', removed: true);

        $cheques = app(PaymentsOverview::class)->chequesDue();

        $this->assertSame([4, 6200.0, 1, 2000.0], [$cheques['count'], $cheques['total'], $cheques['overdue_count'], $cheques['overdue_total']]);
        $this->assertSame(
            [2000.0, 500.0, 3000.0, 700.0],
            array_map(fn (array $row): float => $row['amount'], $cheques['cheques']),
        );
        $this->assertSame([true, false, false, false], array_map(fn (array $row): bool => $row['overdue'], $cheques['cheques']));
    }

    public function test_a_cheque_is_overdue_by_its_own_shops_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-12 22:00:00', 'UTC'));
        $this->alpha->update(['timezone' => 'UTC']);
        $tokyo = Shop::factory()->create(['name' => 'Tokyo', 'timezone' => 'Asia/Tokyo']);   // already the 13th there
        $this->cheque($tokyo, '2026-11-12');
        $this->cheque($this->alpha, '2026-11-12');

        $byShop = collect(app(PaymentsOverview::class)->chequesDue()['cheques'])->mapWithKeys(fn (array $row): array => [$row['shop']->name => $row['overdue']]);

        $this->assertTrue($byShop['Tokyo']);
        $this->assertFalse($byShop['Alpha']);
    }

    public function test_it_lists_the_recent_closes_where_the_cash_did_not_match(): void
    {
        $close = fn (Shop $shop, string $date, float $difference, bool $removed = false) => $this->mirror($shop, 'cash_close', $this->next++, ['business_date' => $date, 'expected_cash' => 100, 'counted_cash' => 100 + $difference, 'difference' => $difference], null, $removed);

        $close($this->alpha, '2026-11-11', -50);
        $close($this->beta, '2026-11-12', 0);
        $close($this->beta, '2026-11-10', 20);
        $close($this->alpha, '2026-10-01', -999);          // too long ago
        $close($this->alpha, '2026-11-09', -7, removed: true);

        $cash = app(PaymentsOverview::class)->cashDifferences();

        $this->assertSame([3, 2], [$cash['closes'], $cash['differences']]);
        $this->assertSame([-50.0, 20.0], array_map(fn (array $row): float => $row['difference'], $cash['rows']));
    }

    public function test_the_page_shows_both_lists_and_is_calm_with_nothing_to_show(): void
    {
        Livewire::test(Index::class)
            ->assertSee('data-cheques-due', false)
            ->assertSee(__('ui.payments.no_cheques'))
            ->assertSee(__('ui.payments.no_closes'))
            ->assertDontSee('data-cheque ', false);

        $this->cheque($this->alpha, '2026-11-01');
        $this->mirror($this->alpha, 'cash_close', $this->next++, ['business_date' => '2026-11-11', 'expected_cash' => 100, 'counted_cash' => 90, 'difference' => -10]);

        Livewire::test(Index::class)
            ->assertSee('data-cheque', false)
            ->assertSee('data-overdue-cheques="1"', false)
            ->assertSee('data-cash-difference', false);
    }
}
