<?php

namespace Tests\Feature\Admin;

use App\Livewire\Dashboard;
use App\Livewire\Shops\Show;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-12 10:00:00');
        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
        $this->plan = Plan::factory()->create(['price' => 500_000, 'billing_days' => 30]);
    }

    private function shopPaidUntil(?string $date): Shop
    {
        return Shop::factory()->create(['plan_id' => $this->plan->id, 'paid_until' => $date]);
    }

    public function test_a_payment_for_a_lapsed_shop_starts_today(): void
    {
        $shop = $this->shopPaidUntil('2026-10-01');

        Livewire::test(Show::class, ['shop' => $shop])->call('recordPayment')->assertHasNoErrors();

        $payment = SubscriptionPayment::sole();
        $this->assertSame('2026-11-12', $payment->period_start->toDateString());
        $this->assertSame('2026-12-11', $payment->period_end->toDateString());
        $this->assertSame('2026-12-11', $shop->fresh()->paidUntil()?->toDateString());
        $this->assertSame(500_000, $payment->amount);
        $this->assertSame($this->admin->id, $payment->user_id);
    }

    public function test_a_payment_for_a_paid_up_shop_keeps_its_remaining_days(): void
    {
        $shop = $this->shopPaidUntil('2026-11-20');

        Livewire::test(Show::class, ['shop' => $shop])->call('recordPayment');

        $payment = SubscriptionPayment::sole();
        $this->assertSame('2026-11-21', $payment->period_start->toDateString());
        $this->assertSame('2026-12-20', $payment->period_end->toDateString());
        $this->assertSame('2026-12-20', $shop->fresh()->paidUntil()?->toDateString());
    }

    public function test_the_last_paid_day_still_counts_as_paid(): void
    {
        $shop = $this->shopPaidUntil('2026-11-12');

        Livewire::test(Show::class, ['shop' => $shop])->call('recordPayment');

        $this->assertSame('2026-11-13', SubscriptionPayment::sole()->period_start->toDateString());
    }

    public function test_a_shop_with_no_paid_until_yet_starts_today(): void
    {
        $shop = $this->shopPaidUntil(null);

        Livewire::test(Show::class, ['shop' => $shop])->call('recordPayment');

        $this->assertSame('2026-11-12', SubscriptionPayment::sole()->period_start->toDateString());
    }

    public function test_the_amount_and_a_note_can_be_given(): void
    {
        $shop = $this->shopPaidUntil('2026-10-01');

        Livewire::test(Show::class, ['shop' => $shop])
            ->set('paymentAmount', '450000')
            ->set('paymentNote', 'Card to card')
            ->call('recordPayment')
            ->assertSet('paymentAmount', null)
            ->assertSet('paymentNote', '');

        $payment = SubscriptionPayment::sole();
        $this->assertSame(450_000, $payment->amount);
        $this->assertSame('Card to card', $payment->note);
    }

    public function test_a_bad_amount_is_refused(): void
    {
        $shop = $this->shopPaidUntil('2026-10-01');

        Livewire::test(Show::class, ['shop' => $shop])
            ->set('paymentAmount', '-5')
            ->call('recordPayment')
            ->assertHasErrors('paymentAmount');

        $this->assertSame(0, SubscriptionPayment::count());
        $this->assertSame('2026-10-01', $shop->fresh()->paidUntil()?->toDateString());
    }

    public function test_a_shop_without_a_plan_cannot_be_paid_for(): void
    {
        $shop = Shop::factory()->create(['plan_id' => null]);

        Livewire::test(Show::class, ['shop' => $shop])
            ->assertDontSee('data-record-payment', false)
            ->call('recordPayment')
            ->assertHasErrors('paymentAmount');

        $this->assertSame(0, SubscriptionPayment::count());
    }

    public function test_a_payment_is_audited_and_listed_on_the_shop_page(): void
    {
        $shop = $this->shopPaidUntil('2026-10-01');

        Livewire::test(Show::class, ['shop' => $shop])
            ->assertDontSee('data-payments', false)
            ->call('recordPayment')
            ->assertSee('data-payment', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.payment_recorded', 'shop_id' => $shop->id, 'user_id' => $this->admin->id]);
    }

    public function test_the_dashboard_lists_the_shops_whose_plan_is_running_out(): void
    {
        $expired = $this->shopPaidUntil('2026-11-01');
        $dueSoon = $this->shopPaidUntil('2026-11-15');
        $paid = $this->shopPaidUntil('2027-02-01');
        Shop::factory()->create(['plan_id' => null]);

        Livewire::test(Dashboard::class)
            ->assertSee('data-billing-attention', false)
            ->assertSee($expired->name)
            ->assertSee($dueSoon->name)
            ->assertDontSee($paid->name)
            ->assertViewHas('billing', fn (array $billing): bool => $billing['expired'] === 1 && $billing['due_soon'] === 1);
    }

    public function test_the_dashboard_has_no_billing_card_when_everyone_is_paid(): void
    {
        $this->shopPaidUntil('2027-02-01');

        Livewire::test(Dashboard::class)->assertDontSee('data-billing-attention', false);
    }
}
