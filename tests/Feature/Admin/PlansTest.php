<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingStatus;
use App\Livewire\Plans\Index as PlansIndex;
use App\Livewire\Shops\Form;
use App\Livewire\Shops\Index as ShopsIndex;
use App\Livewire\Shops\Show;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlansTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_the_plans_page_lists_plans_and_is_in_the_menu(): void
    {
        Plan::factory()->create(['name' => 'Starter', 'price' => 1_500_000]);

        $this->get(route('plans.index'))
            ->assertOk()
            ->assertSee('Starter')
            ->assertSee('1,500,000')
            ->assertSee(route('plans.index'), false);
    }

    public function test_an_administrator_creates_a_plan_with_a_limit_and_it_is_audited(): void
    {
        Livewire::test(PlansIndex::class)
            ->set('name', 'Pro')
            ->set('price', '2500000')
            ->set('billing_days', '30')
            ->set('max_devices', '5')
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::where('name', 'Pro')->sole();
        $this->assertSame(2_500_000, $plan->price);
        $this->assertSame(5, $plan->max_devices);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.created', 'user_id' => $this->admin->id]);
    }

    public function test_an_empty_device_limit_means_unlimited(): void
    {
        Livewire::test(PlansIndex::class)->set('name', 'Free for all')->set('max_devices', '')->call('save')->assertHasNoErrors();

        $this->assertNull(Plan::where('name', 'Free for all')->sole()->max_devices);
    }

    public function test_a_plan_is_validated(): void
    {
        Plan::factory()->create(['name' => 'Taken']);

        Livewire::test(PlansIndex::class)->set('name', '')->call('save')->assertHasErrors(['name']);
        Livewire::test(PlansIndex::class)->set('name', 'Taken')->call('save')->assertHasErrors(['name']);
        Livewire::test(PlansIndex::class)->set('name', 'Bad')->set('price', '-5')->call('save')->assertHasErrors(['price']);
        Livewire::test(PlansIndex::class)->set('name', 'Bad')->set('billing_days', '0')->call('save')->assertHasErrors(['billing_days']);
        Livewire::test(PlansIndex::class)->set('name', 'Bad')->set('max_devices', '0')->call('save')->assertHasErrors(['max_devices']);
    }

    public function test_a_plan_is_edited(): void
    {
        $plan = Plan::factory()->create(['name' => 'Old', 'price' => 100]);

        Livewire::test(PlansIndex::class)->call('edit', $plan->id)->assertSet('name', 'Old')->set('name', 'New')->set('price', '200')->call('save')->assertHasNoErrors();

        $this->assertSame('New', $plan->fresh()->name);
        $this->assertSame(200, $plan->fresh()->price);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.updated']);
    }

    public function test_a_plan_with_shops_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $used = Plan::factory()->create();
        Shop::factory()->create(['plan_id' => $used->id]);
        $unused = Plan::factory()->create();

        Livewire::test(PlansIndex::class)->call('delete', $used->id)->call('delete', $unused->id);

        $this->assertDatabaseHas('plans', ['id' => $used->id]);
        $this->assertDatabaseMissing('plans', ['id' => $unused->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.deleted']);
    }

    public function test_billing_status_follows_the_paid_until_date(): void
    {
        $plan = Plan::factory()->create();
        $status = fn (?string $until, bool $withPlan = true): BillingStatus => Shop::factory()
            ->create(['plan_id' => $withPlan ? $plan->id : null, 'paid_until' => $until])
            ->fresh()
            ->billingStatus();

        $this->assertSame(BillingStatus::NoPlan, $status(null));
        $this->assertSame(BillingStatus::NoPlan, $status(now()->addMonth()->toDateString(), withPlan: false));
        $this->assertSame(BillingStatus::Paid, $status(now()->addDays(30)->toDateString()));
        $this->assertSame(BillingStatus::DueSoon, $status(now()->addDays(3)->toDateString()));
        $this->assertSame(BillingStatus::DueSoon, $status(now()->toDateString()), 'the paid-until day itself still counts');
        $this->assertSame(BillingStatus::Expired, $status(now()->subDay()->toDateString()));
    }

    public function test_a_shop_is_put_on_a_plan_from_its_edit_page(): void
    {
        $plan = Plan::factory()->create();
        $shop = Shop::factory()->create();

        Livewire::test(Form::class, ['shop' => $shop])
            ->set('plan_id', (string) $plan->id)
            ->set('paid_until', '2027-01-15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($plan->id, $shop->fresh()->plan_id);
        $this->assertSame('2027-01-15', $shop->fresh()->paidUntil()?->format('Y-m-d'));

        Livewire::test(Form::class, ['shop' => $shop->fresh()])->assertSet('plan_id', (string) $plan->id)->assertSet('paid_until', '2027-01-15')
            ->set('plan_id', '')->set('paid_until', '')->call('save');

        $this->assertNull($shop->fresh()->plan_id);
        $this->assertNull($shop->fresh()->paid_until);
    }

    public function test_an_unknown_plan_is_refused(): void
    {
        Livewire::test(Form::class, ['shop' => Shop::factory()->create()])->set('plan_id', '9999')->call('save')->assertHasErrors(['plan_id']);
    }

    public function test_the_shop_page_shows_the_plan_status_and_warns_when_over_the_device_limit(): void
    {
        $plan = Plan::factory()->create(['name' => 'Tiny', 'max_devices' => 1]);
        $shop = Shop::factory()->create(['plan_id' => $plan->id, 'paid_until' => now()->subDays(2)->toDateString()]);
        ShopDevice::factory()->count(2)->for($shop)->create();

        Livewire::test(Show::class, ['shop' => $shop])
            ->assertSee('Tiny')
            ->assertSee(__('ui.billing_status.expired'))
            ->assertSee(__('ui.plans.over_limit'));
    }

    public function test_the_shop_page_does_not_warn_within_the_limit_and_never_blocks_the_shop(): void
    {
        $plan = Plan::factory()->create(['max_devices' => 3]);
        $shop = Shop::factory()->create(['plan_id' => $plan->id, 'paid_until' => now()->subYear()->toDateString()]);
        ShopDevice::factory()->for($shop)->create();

        Livewire::test(Show::class, ['shop' => $shop])->assertDontSee(__('ui.plans.over_limit'));

        $this->assertTrue($shop->fresh()->isActive(), 'an expired plan only shows a status');
    }

    public function test_the_shop_list_shows_each_shops_plan(): void
    {
        $plan = Plan::factory()->create(['name' => 'Gold']);
        Shop::factory()->create(['name' => 'Alpha', 'plan_id' => $plan->id, 'paid_until' => now()->addMonth()->toDateString()]);
        Shop::factory()->create(['name' => 'Beta']);

        Livewire::test(ShopsIndex::class)->assertSee('Gold')->assertSee(__('ui.billing_status.paid'))->assertSee(__('ui.plans.none'));
    }

    public function test_deleting_a_plan_directly_leaves_its_shops_without_one(): void
    {
        $plan = Plan::factory()->create();
        $shop = Shop::factory()->create(['plan_id' => $plan->id]);

        $plan->delete();

        $this->assertNull($shop->fresh()->plan_id);
    }
}
