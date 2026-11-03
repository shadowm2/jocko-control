<?php

namespace Tests\Feature\Admin;

use App\Livewire\Shops\Data\Overview;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopDataCatalog;
use App\Support\EntityStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

class DataOverviewPageTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->shop = Shop::factory()->create(['name' => 'Alpha']);
        $this->travelTo(now()->startOfSecond());
    }

    public function test_every_entity_has_an_icon_and_every_group_a_colour_and_icon(): void
    {
        $catalog = app(ShopDataCatalog::class);

        foreach ($catalog->entities() as $entity) {
            $this->assertNotSame('circle-stack', EntityStyle::icon($entity), "{$entity} has no icon of its own");
        }

        foreach (array_keys($catalog->groups()) as $group) {
            $this->assertNotSame('folder', EntityStyle::groupIcon($group), "{$group} has no icon");
            $this->assertStringContainsString('bg-', EntityStyle::palette($group)['chip']);
            $this->assertNotSame(EntityStyle::palette('nope'), EntityStyle::palette($group), "{$group} has no colour");
        }
    }

    public function test_the_page_shows_the_total_each_table_and_when_it_last_changed(): void
    {
        $this->mirror($this->shop, 'brand', 1, ['name' => 'Bosch', 'updated_at' => now()->toIso8601String()], 'Bosch');
        $this->mirror($this->shop, 'brand', 2, ['name' => 'Valeo'], 'Valeo');
        $this->mirror($this->shop, 'brand', 3, ['name' => 'Gone'], 'Gone', removed: true);
        $this->mirror($this->shop, 'order', 1, ['order_number' => 'ORD-1', 'status' => 'pending'], 'ORD-1');

        $page = $this->get(route('shops.data', $this->shop))->assertOk()
            ->assertSee('data-hero', false)
            ->assertSee('data-total="3"', false)
            ->assertSee('data-entity="brand"', false)
            ->assertSee('data-count="2"', false)
            ->assertSee('data-count="1"', false)
            ->assertSee('data-count="0"', false)
            ->assertSee(__('ui.shop_data.read_only_notice'));

        $page->assertSeeInOrder(['data-group="sales"', 'data-group="people"'], false);
    }

    public function test_it_counts_the_deleted_ones_apart_and_never_in_the_total(): void
    {
        $this->mirror($this->shop, 'brand', 1, ['name' => 'Bosch'], 'Bosch');
        $this->mirror($this->shop, 'brand', 2, ['name' => 'Gone'], 'Gone', removed: true);
        $this->mirror($this->shop, 'brand', 3, ['name' => 'Gone too'], 'Gone too', removed: true);

        Livewire::test(Overview::class, ['shop' => $this->shop])
            ->assertViewHas('total', 1)
            ->assertViewHas('details', fn ($details) => $details['brand']['removed'] === 2 && $details['brand']['last_at'] !== null);
    }

    public function test_the_latest_changes_are_this_shops_newest_first_and_link_to_the_record(): void
    {
        $old = $this->mirror($this->shop, 'brand', 1, ['name' => 'Old'], 'Old');
        $new = $this->mirror($this->shop, 'item', 1, ['name' => 'New'], 'New');
        $old->update(['source_occurred_at' => now()->subHour()]);
        $new->update(['source_occurred_at' => now()]);
        $this->mirror(Shop::factory()->create(), 'brand', 9, ['name' => 'Foreign'], 'Foreign');

        $page = $this->get(route('shops.data', $this->shop))->assertOk()
            ->assertSee('data-latest', false)
            ->assertSee(route('shops.data.show', [$this->shop, 'item', $new]), false)
            ->assertDontSee('Foreign');

        $page->assertSeeInOrder(['data-latest-record="'.$new->id.'"', 'data-latest-record="'.$old->id.'"'], false);
    }

    public function test_at_most_eight_changes_are_listed(): void
    {
        foreach (range(1, 12) as $n) {
            $this->mirror($this->shop, 'brand', $n, ['name' => "B{$n}"], "B{$n}");
        }

        Livewire::test(Overview::class, ['shop' => $this->shop])->assertViewHas('latest', fn ($latest) => $latest->count() === 8);
    }

    public function test_it_can_be_searched_and_emptied_of_empty_tables_in_the_browser(): void
    {
        $this->mirror($this->shop, 'brand', 1, ['name' => 'Bosch'], 'Bosch');

        $this->get(route('shops.data', $this->shop))->assertOk()->assertSee('data-find-table', false)->assertSee('data-hide-empty', false);
    }

    public function test_an_empty_shop_still_says_nothing_arrived_and_shows_no_hero(): void
    {
        $this->get(route('shops.data', $this->shop))->assertOk()
            ->assertSee(__('ui.shop_data.empty'))
            ->assertDontSee('data-hero', false);
    }

    public function test_it_is_read_only_and_works_in_english_too(): void
    {
        $this->mirror($this->shop, 'brand', 1, ['name' => 'Bosch'], 'Bosch');
        app()->setLocale('en');

        $this->get(route('shops.data', $this->shop))->assertOk()->assertSee('Latest changes')->assertSee('Hide empty')->assertSee('in 1 table');
    }
}
