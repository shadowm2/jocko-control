<?php

namespace Tests\Feature\Admin;

use App\Livewire\Events\Index as EventsIndex;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\ShopRecord;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\ShopDataCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

/**
 * A user's password hash is kept so a lost shop can be restored, and goes no
 * further than the mirror: no page, list, export or event view shows it.
 */
class ShopSecretFieldsTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private const HASH = '$2y$12$SecretHashSecretHashSecretHashSecretHashSecretHashSec';

    private Shop $shop;

    private ShopRecord $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->shop = Shop::factory()->create(['slug' => 'alpha']);
        $this->user = $this->mirror($this->shop, 'user', 4, [
            'type' => 'admin', 'first_name' => 'Maryam', 'last_name' => 'Boss', 'mobile' => '09120000001',
            'password' => self::HASH, 'roles' => 'manager',
        ], 'Maryam Boss');
    }

    public function test_the_catalog_keeps_the_hash_but_never_lists_it_as_visible(): void
    {
        $catalog = app(ShopDataCatalog::class);

        $this->assertArrayHasKey('password', $catalog->fields('user'));
        $this->assertArrayNotHasKey('password', $catalog->visibleFields('user'));
        $this->assertArrayHasKey('roles', $catalog->visibleFields('user'));
        $this->assertSame(self::HASH, $this->user->fresh()->data['password']);
    }

    public function test_the_record_page_shows_the_role_but_not_the_hash(): void
    {
        $this->get(route('shops.data.show', ['shop' => $this->shop, 'entity' => 'user', 'record' => $this->user]))
            ->assertOk()
            ->assertSee('Maryam Boss')
            ->assertSee('manager')
            ->assertDontSee(self::HASH)
            ->assertDontSee('SecretHash');
    }

    public function test_the_list_does_not_show_the_hash(): void
    {
        $this->get(route('shops.data.entity', ['shop' => $this->shop, 'entity' => 'user']))
            ->assertOk()
            ->assertSee('Maryam Boss')
            ->assertDontSee('SecretHash');
    }

    public function test_the_csv_export_leaves_the_hash_out(): void
    {
        $raw = $this->get(route('shops.data.export', ['shop' => $this->shop, 'entity' => 'user']))->assertOk()->streamedContent();

        $this->assertStringContainsString('Maryam', $raw);
        $this->assertStringContainsString('manager', $raw);
        $this->assertStringNotContainsString('SecretHash', $raw);
        $this->assertStringNotContainsString('password', strtolower(explode("\n", $raw)[0]));
    }

    public function test_the_event_viewer_blanks_the_hash(): void
    {
        $event = SyncEvent::factory()->for($this->shop)->for(ShopDevice::factory()->for($this->shop), 'device')->create([
            'event_type' => 'user.updated',
            'aggregate_type' => 'user',
            'payload' => ['id' => 4, 'first_name' => 'Maryam', 'password' => self::HASH, 'roles' => 'manager'],
        ]);

        Livewire::test(EventsIndex::class)
            ->call('view', $event->id)
            ->assertSee('Maryam')
            ->assertDontSee('SecretHash')
            ->assertSee('••••••••');
    }

    public function test_a_role_is_a_mirrored_entity_with_its_permissions(): void
    {
        $role = $this->mirror($this->shop, 'role', 2, ['name' => 'Accountant', 'guard_name' => 'web', 'permissions' => 'backups.manage,reports.view'], 'Accountant');

        $this->get(route('shops.data.show', ['shop' => $this->shop, 'entity' => 'role', 'record' => $role]))
            ->assertOk()
            ->assertSee('Accountant')
            ->assertSee('reports.view');
    }
}
