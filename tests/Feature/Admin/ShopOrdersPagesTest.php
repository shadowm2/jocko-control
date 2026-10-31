<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Shops\Orders\Index;
use App\Livewire\Shops\Orders\Show;
use App\Models\Shop;
use App\Models\ShopRecord;
use App\Models\User;
use App\Services\ShopOrders;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

class ShopOrdersPagesTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private Shop $shop;

    /** @var array<int, ShopRecord> */
    private array $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->shop = Shop::factory()->create(['name' => 'Alpha Garage', 'timezone' => 'Asia/Tehran']);
        $this->orders = $this->seedShop($this->shop)['orders'];
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    private function numbers(array $filters = []): array
    {
        $orders = app(ShopOrders::class);

        return array_column($orders->rows($this->shop, $orders->query($this->shop, $filters)->get()), 'number');
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        auth()->logout();

        $this->get(route('shops.orders', $this->shop))->assertRedirect(route('login'));
        $this->get(route('shops.orders.show', [$this->shop, $this->orders[1]]))->assertRedirect(route('login'));
    }

    public function test_the_list_shows_live_orders_newest_first_with_customer_car_warehouse_total_and_states(): void
    {
        $this->get(route('shops.orders', $this->shop))
            ->assertOk()
            ->assertSeeInOrder(['ORD-4', 'ORD-3', 'ORD-2', 'ORD-1'])
            ->assertDontSee('ORD-5')
            ->assertSee('Ali Rezaei')
            ->assertSee('09120000000')
            ->assertSee('Pride')
            ->assertSee('Main')
            ->assertSee(Format::number(300000.0))
            ->assertSee(OrderStatus::Pending->label())
            ->assertSee(PaymentStatus::Partial->label());
    }

    public function test_payment_status_follows_what_was_billed_and_paid(): void
    {
        $rows = collect(app(ShopOrders::class)->rows($this->shop, app(ShopOrders::class)->query($this->shop)->get()))->keyBy('number');

        $this->assertSame(PaymentStatus::Partial, $rows['ORD-1']['payment']);
        $this->assertSame(300000.0, $rows['ORD-1']['total']);
        $this->assertSame(100000.0, $rows['ORD-1']['paid']);
        $this->assertSame(2, $rows['ORD-1']['lines']);
        $this->assertSame(PaymentStatus::Paid, $rows['ORD-2']['payment']);
        $this->assertSame(PaymentStatus::Unpaid, $rows['ORD-3']['payment']);
        $this->assertSame(PaymentStatus::Unpaid, $rows['ORD-4']['payment']);
        $this->assertSame(0.0, $rows['ORD-4']['total']);
    }

    public function test_removed_items_and_payments_are_not_counted(): void
    {
        $row = app(ShopOrders::class)->rows($this->shop, [$this->orders[1]])[0];

        $this->assertSame(300000.0, $row['total']);
        $this->assertSame(100000.0, $row['paid']);
    }

    public function test_filtering_by_order_status(): void
    {
        $this->assertSame(['ORD-3'], $this->numbers(['status' => 'cancelled']));
        $this->assertSame(['ORD-2'], $this->numbers(['status' => 'completed']));
        $this->assertSame(['ORD-4', 'ORD-3', 'ORD-2', 'ORD-1'], $this->numbers(['status' => 'not-a-status']));
    }

    public function test_filtering_by_payment_status_uses_the_same_rule_as_the_shop(): void
    {
        $this->assertSame(['ORD-1'], $this->numbers(['payment_status' => 'partial']));
        $this->assertSame(['ORD-2'], $this->numbers(['payment_status' => 'paid']));
        $this->assertSame(['ORD-4', 'ORD-3'], $this->numbers(['payment_status' => 'unpaid']));
    }

    public function test_searching_by_number_customer_name_or_mobile_even_in_persian_digits(): void
    {
        $this->assertSame(['ORD-2'], $this->numbers(['search' => 'ORD-2']));
        $this->assertSame(['ORD-4', 'ORD-3', 'ORD-2', 'ORD-1'], $this->numbers(['search' => 'rezaei']));
        $this->assertSame(['ORD-4', 'ORD-3', 'ORD-2', 'ORD-1'], $this->numbers(['search' => '09120000000']));
        $this->assertSame(['ORD-4', 'ORD-3', 'ORD-2', 'ORD-1'], $this->numbers(['search' => '۰۹۱۲۰۰۰۰۰۰۰']));
        $this->assertSame([], $this->numbers(['search' => 'nobody']));
    }

    public function test_filtering_by_the_date_an_order_was_created(): void
    {
        $today = now()->setTimezone('Asia/Tehran')->format('Y-m-d');
        $old = now()->subDays(40)->setTimezone('Asia/Tehran')->format('Y-m-d');

        $this->assertSame(['ORD-4', 'ORD-3', 'ORD-1'], $this->numbers(['created_from' => $today]));
        $this->assertSame(['ORD-2'], $this->numbers(['created_to' => $old]));
        $this->assertSame(['ORD-2'], $this->numbers(['created_from' => $old, 'created_to' => $old]));
        $this->assertSame(['ORD-4', 'ORD-3', 'ORD-2', 'ORD-1'], $this->numbers(['created_from' => 'garbage']));
    }

    public function test_the_page_filters_and_resets(): void
    {
        Livewire::test(Index::class, ['shop' => $this->shop])
            ->assertSee('ORD-1')->assertSee('ORD-2')
            ->set('paymentStatus', 'paid')
            ->assertSee('ORD-2')->assertDontSee('ORD-1')
            ->set('paymentStatus', '')
            ->set('status', 'cancelled')
            ->assertSee('ORD-3')->assertDontSee('ORD-2')
            ->set('search', 'nobody')
            ->assertSee(__('ui.shop_orders.empty_filtered'))
            ->call('resetFilters')
            ->assertSee('ORD-1')->assertSee('ORD-4');
    }

    public function test_another_shops_orders_are_never_listed(): void
    {
        $other = Shop::factory()->create();
        $this->mirror($other, 'order', 70, ['order_number' => 'ORD-OTHER', 'status' => 'pending', 'created_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')], 'ORD-OTHER');

        $this->get(route('shops.orders', $this->shop))->assertOk()->assertDontSee('ORD-OTHER');
        $this->assertNotContains('ORD-OTHER', $this->numbers());
    }

    public function test_an_empty_shop_says_there_are_no_orders(): void
    {
        $empty = Shop::factory()->create();

        $this->get(route('shops.orders', $empty))->assertOk()->assertSee(__('ui.shop_orders.empty'));
    }

    public function test_the_invoice_lists_lines_payments_and_the_balance(): void
    {
        Livewire::test(Show::class, ['shop' => $this->shop, 'record' => $this->orders[1]])
            ->assertOk()
            ->assertSee('ORD-1')
            ->assertSee('Ali Rezaei')
            ->assertSee('09120000000')
            ->assertSee('Pride')
            ->assertSee('Brake pad')
            ->assertSee('Oil filter')
            ->assertSee('pc')
            ->assertSee('Note 1')
            ->assertDontSee(Format::number(777777.0))
            ->assertDontSee(Format::number(555555.0))
            ->assertSeeHtml('data-line')
            ->assertSeeHtml('data-payment')
            ->assertSeeHtml('data-total>'.Format::number(300000.0).'<')
            ->assertSeeHtml('data-paid>'.Format::number(100000.0).'<')
            ->assertSeeHtml('data-balance>'.Format::number(200000.0).'<')
            ->assertSee(__('ui.shop_data.values.payment.method.cash'));
    }

    public function test_payments_are_listed_oldest_first_with_their_method(): void
    {
        $invoice = app(ShopOrders::class)->invoice($this->shop, $this->orders[2]);

        $this->assertSame(['card', 'transfer'], array_column($invoice['payments'], 'method'));
        $this->assertSame(0.0, $invoice['balance']);
        $this->assertSame(500000.0, $invoice['total']);
    }

    public function test_an_order_without_items_still_renders(): void
    {
        $this->get(route('shops.orders.show', [$this->shop, $this->orders[4]]))
            ->assertOk()
            ->assertSee(__('ui.shop_orders.no_lines'));
    }

    public function test_an_order_cannot_be_opened_through_another_shop_or_as_another_entity(): void
    {
        $other = Shop::factory()->create();
        $customer = ShopRecord::where('entity', 'user')->firstOrFail();

        $this->get(route('shops.orders.show', [$other, $this->orders[1]]))->assertNotFound();
        $this->get(route('shops.orders.show', [$this->shop, $customer]))->assertNotFound();
    }

    public function test_the_shop_screens_listen_for_changes_and_keep_a_slow_poll_as_a_fallback(): void
    {
        $record = ShopRecord::where('entity', 'user')->firstOrFail();

        foreach ([
            route('shops.dashboard', $this->shop),
            route('shops.orders', $this->shop),
            route('shops.orders.show', [$this->shop, $this->orders[1]]),
            route('shops.data', $this->shop),
            route('shops.data.entity', [$this->shop, 'user']),
            route('shops.data.show', [$this->shop, 'user', $record]),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('wire:poll.60s.visible', false)
                ->assertSee('echo-private:shops.'.$this->shop->getKey().',.ShopChanged', false);
        }
    }

    public function test_the_orders_pages_offer_no_way_to_change_anything(): void
    {
        foreach ([Index::class, Show::class] as $component) {
            $actions = array_intersect(get_class_methods($component), ['save', 'update', 'delete', 'remove', 'destroy', 'store', 'cancel', 'pay']);

            $this->assertSame([], array_values($actions), "{$component} must stay read-only.");
        }
    }
}
