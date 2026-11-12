<?php

namespace Tests\Feature\Admin;

use App\Enums\ShopStatus;
use App\Livewire\Shops\Form;
use App\Livewire\Shops\Index;
use App\Livewire\Shops\Show;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_an_administrator_can_create_a_shop(): void
    {
        Livewire::test(Form::class)
            ->set('name', 'Alpha Garage')
            ->set('slug', 'alpha-garage')
            ->set('owner_name', 'Reza')
            ->set('email', 'alpha@example.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $shop = Shop::where('slug', 'alpha-garage')->sole();
        $this->assertSame('Alpha Garage', $shop->name);
        $this->assertSame(ShopStatus::Active, $shop->status);
        $this->assertSame('Asia/Tehran', $shop->timezone);
        $this->assertNull($shop->phone, 'Empty optional inputs are stored as NULL.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.created', 'shop_id' => $shop->id, 'user_id' => $this->admin->id]);
    }

    public function test_the_slug_is_suggested_from_the_name(): void
    {
        Livewire::test(Form::class)
            ->set('name', 'Alpha Garage')
            ->assertSet('slug', 'alpha-garage');
    }

    public function test_shop_validation(): void
    {
        Livewire::test(Form::class)
            ->set('name', '')
            ->set('slug', 'Not Valid!')
            ->set('email', 'not-an-email')
            ->set('timezone', 'Mars/Olympus')
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'slug' => 'regex', 'email' => 'email', 'timezone' => 'in']);

        $this->assertDatabaseCount('shops', 0);
    }

    public function test_slugs_must_be_unique(): void
    {
        Shop::factory()->create(['slug' => 'taken']);

        Livewire::test(Form::class)
            ->set('name', 'Another')
            ->set('slug', 'taken')
            ->call('save')
            ->assertHasErrors(['slug' => 'unique']);

        $this->assertDatabaseCount('shops', 1);
    }

    public function test_editing_keeps_the_own_slug_valid_and_audits_changes(): void
    {
        $shop = Shop::factory()->create(['slug' => 'mine', 'name' => 'Old name']);

        Livewire::test(Form::class, ['shop' => $shop])
            ->assertSet('name', 'Old name')
            ->set('name', 'New name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New name', $shop->fresh()->name);
        $this->assertSame('mine', $shop->fresh()->slug);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.updated', 'shop_id' => $shop->id]);
    }

    public function test_the_list_is_paginated_searchable_and_filterable(): void
    {
        Shop::factory()->count(17)->create();
        $match = Shop::factory()->create(['name' => 'Zeta Motors', 'slug' => 'zeta-motors']);
        $suspended = Shop::factory()->suspended()->create(['name' => 'Sleepy Garage', 'slug' => 'sleepy']);

        Livewire::test(Index::class)
            ->assertViewHas('shops', fn ($shops) => $shops->count() === 15 && $shops->total() === 19);

        Livewire::test(Index::class)
            ->set('search', 'zeta')
            ->assertSee('Zeta Motors')
            ->assertDontSee('Sleepy Garage');

        Livewire::test(Index::class)
            ->set('search', 'sleepy')
            ->assertSee('Sleepy Garage')
            ->assertDontSee('Zeta Motors');

        Livewire::test(Index::class)
            ->set('status', 'suspended')
            ->assertViewHas('shops', fn ($shops) => $shops->pluck('id')->all() === [$suspended->id]);

        Livewire::test(Index::class)
            ->set('search', 'no-such-shop')
            ->assertSee(__('ui.shops.empty'));

        $this->assertNotNull($match);
    }

    public function test_search_treats_wildcards_literally(): void
    {
        Shop::factory()->create(['name' => 'Plain', 'slug' => 'plain']);

        Livewire::test(Index::class)
            ->set('search', '%')
            ->assertViewHas('shops', fn ($shops) => $shops->isEmpty());
    }

    public function test_the_list_shows_device_counts_without_n_plus_one_queries(): void
    {
        $shops = Shop::factory()->count(5)->create();
        foreach ($shops as $shop) {
            ShopDevice::factory()->count(2)->for($shop)->seenAt(now())->create();
        }

        \DB::enableQueryLog();
        Livewire::test(Index::class)->assertSee(__('ui.shops.devices_online', ['online' => '۲', 'total' => '۲']));
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(15, $queries);
    }

    public function test_the_detail_page_shows_the_shop_its_devices_and_history(): void
    {
        $shop = Shop::factory()->create(['name' => 'Detail Garage', 'notes' => 'Handle with care']);
        ShopDevice::factory()->for($shop)->create(['name' => 'Front desk']);
        ShopDevice::factory()->for(Shop::factory())->create(['name' => 'Other shop device']);

        $this->get(route('shops.show', $shop))
            ->assertOk()
            ->assertSeeLivewire(Show::class)
            ->assertSee('Detail Garage')
            ->assertSee('Handle with care')
            ->assertSee('Front desk')
            ->assertDontSee('Other shop device');
    }

    public function test_an_administrator_can_suspend_and_reactivate_a_shop(): void
    {
        $shop = Shop::factory()->create();

        Livewire::test(Show::class, ['shop' => $shop])
            ->call('suspend')
            ->assertSee(__('ui.shops.suspended_banner'));

        $this->assertSame(ShopStatus::Suspended, $shop->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.suspended', 'shop_id' => $shop->id, 'user_id' => $this->admin->id]);

        Livewire::test(Show::class, ['shop' => $shop])
            ->call('reactivate')
            ->assertDontSee(__('ui.shops.suspended_banner'));

        $this->assertSame(ShopStatus::Active, $shop->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.reactivated', 'shop_id' => $shop->id]);
    }

    public function test_an_administrator_can_allow_a_shop_to_restore_its_data(): void
    {
        $shop = Shop::factory()->create();

        Livewire::test(Show::class, ['shop' => $shop])
            ->assertSee(__('ui.shops.allow_restore'))
            ->call('allowRestore');

        $this->assertTrue($shop->fresh()->restoreAllowed());
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.restore_allowed', 'shop_id' => $shop->id, 'user_id' => $this->admin->id]);
    }

    public function test_an_administrator_can_revoke_the_restore_before_it_runs_out(): void
    {
        $shop = Shop::factory()->create();

        Livewire::test(Show::class, ['shop' => $shop])
            ->assertDontSee('data-revoke-restore', false)
            ->call('allowRestore')
            ->assertSee('data-revoke-restore', false)
            ->call('revokeRestore')
            ->assertDontSee('data-revoke-restore', false);

        $this->assertFalse($shop->fresh()->restoreAllowed());
        $this->assertNull($shop->fresh()->restore_allowed_until);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.restore_revoked', 'shop_id' => $shop->id, 'user_id' => $this->admin->id]);
    }

    public function test_revoking_when_nothing_is_open_writes_no_audit_entry(): void
    {
        $shop = Shop::factory()->create();

        Livewire::test(Show::class, ['shop' => $shop])->call('revokeRestore');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'shop.restore_revoked']);
    }

    public function test_suspending_twice_writes_one_audit_entry(): void
    {
        $shop = Shop::factory()->create();

        Livewire::test(Show::class, ['shop' => $shop])->call('suspend')->call('suspend');

        $this->assertSame(1, AuditLog::where('action', 'shop.suspended')->count());
    }

    public function test_shops_have_no_delete_action(): void
    {
        $this->assertFalse(method_exists(Show::class, 'delete'));
        $this->assertFalse(method_exists(Index::class, 'delete'));
    }

    public function test_livewire_actions_are_authorized_on_every_request(): void
    {
        $shop = Shop::factory()->create();
        $component = Livewire::test(Show::class, ['shop' => $shop]);

        $this->admin->update(['is_active' => false]);

        $component->call('suspend')->assertForbidden();

        $this->assertSame(ShopStatus::Active, $shop->fresh()->status);
    }

    public function test_a_guest_cannot_drive_livewire_components(): void
    {
        auth()->logout();

        Livewire::test(Index::class)->assertForbidden();
    }
}
