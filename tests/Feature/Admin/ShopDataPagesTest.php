<?php

namespace Tests\Feature\Admin;

use App\Livewire\Shops\Data\Overview;
use App\Livewire\Shops\Data\Records;
use App\Livewire\Shops\Data\Show;
use App\Models\Shop;
use App\Models\ShopRecord;
use App\Models\User;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class ShopDataPagesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->shop = Shop::factory()->create(['name' => 'Alpha Garage']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(string $entity, int $id, array $data, ?string $label = null, ?Shop $shop = null, bool $removed = false): ShopRecord
    {
        $factory = ShopRecord::factory()
            ->for($shop ?? $this->shop)
            ->entity($entity, ['id' => $id] + $data, $label);

        return ($removed ? $factory->removed() : $factory)->create();
    }

    public function test_guests_cannot_see_shop_data(): void
    {
        auth()->logout();

        $this->get(route('shops.data', $this->shop))->assertRedirect(route('login'));
    }

    public function test_the_overview_counts_live_records_per_entity_of_this_shop_only(): void
    {
        $this->record('brand', 1, ['name' => 'Bosch'], 'Bosch');
        $this->record('brand', 2, ['name' => 'Gone'], 'Gone', removed: true);
        $this->record('brand', 1, ['name' => 'Other'], 'Other', Shop::factory()->create());

        $this->get(route('shops.data', $this->shop))
            ->assertOk()
            ->assertSee(__('ui.shop_data.entities.brand'))
            ->assertSee(__('ui.shop_data.read_only_notice'));

        Livewire::test(Overview::class, ['shop' => $this->shop])
            ->assertViewHas('total', 1)
            ->assertViewHas('counts', fn ($counts) => $counts['brand'] === 1);
    }

    public function test_an_empty_overview_explains_that_nothing_arrived_yet(): void
    {
        Livewire::test(Overview::class, ['shop' => $this->shop])
            ->assertSee(__('ui.shop_data.empty'));
    }

    public function test_the_shop_page_links_to_its_data(): void
    {
        $this->get(route('shops.show', $this->shop))->assertOk()->assertSee(route('shops.data', $this->shop));
    }

    public function test_records_are_listed_searchable_and_scoped_to_the_shop(): void
    {
        $this->record('brand', 1, ['name' => 'Bosch'], 'Bosch');
        $this->record('brand', 2, ['name' => 'Valeo'], 'Valeo');
        $this->record('brand', 3, ['name' => 'Removed Co'], 'Removed Co', removed: true);
        $this->record('brand', 4, ['name' => 'Foreign'], 'Foreign', Shop::factory()->create());

        $this->get(route('shops.data.entity', [$this->shop, 'brand']))->assertOk()->assertSee('Bosch');

        Livewire::test(Records::class, ['shop' => $this->shop, 'entity' => 'brand'])
            ->assertSee('Bosch')->assertSee('Valeo')
            ->assertDontSee('Removed Co')->assertDontSee('Foreign')
            ->set('search', 'val')
            ->assertSee('Valeo')->assertDontSee('Bosch')
            ->set('search', '1')
            ->assertSee('Bosch')->assertDontSee('Valeo')
            ->call('resetFilters')
            ->set('scope', 'removed')
            ->assertSee('Removed Co')->assertDontSee('Bosch')
            ->set('scope', 'all')
            ->assertSee('Removed Co')->assertSee('Bosch');
    }

    public function test_an_unknown_entity_is_not_found(): void
    {
        $this->get(route('shops.data.entity', [$this->shop, 'settings']))->assertNotFound();
    }

    public function test_a_record_shows_its_fields_with_references_resolved_to_links(): void
    {
        $customer = $this->record('user', 3, ['first_name' => 'Ali', 'last_name' => 'Rezaei', 'type' => 'customer'], 'Ali Rezaei');
        $order = $this->record('order', 7, [
            'order_number' => 'ORD-0007', 'user_id' => 3, 'status' => 'in_progress', 'description' => 'Brake service',
        ], 'ORD-0007');

        $this->get(route('shops.data.show', [$this->shop, 'order', $order]))
            ->assertOk()
            ->assertSee('ORD-0007')
            ->assertSee('Ali Rezaei')
            ->assertSee(route('shops.data.show', [$this->shop, 'user', $customer]), false)
            ->assertSee(__('ui.shop_data.values.order.status.in_progress'))
            ->assertSee('Brake service');
    }

    public function test_an_order_lists_its_items_and_payments_and_works_out_the_balance(): void
    {
        $order = $this->record('order', 7, ['order_number' => 'ORD-0007'], 'ORD-0007');
        $this->record('item', 5, ['name' => 'Brake pad'], 'Brake pad');
        $this->record('order_item', 1, ['order_id' => 7, 'item_id' => 5, 'quantity' => '2.0000', 'unit_price' => '300000.0000', 'total' => '600000.0000']);
        $this->record('order_item', 2, ['order_id' => 7, 'item_id' => 5, 'quantity' => '1.0000', 'unit_price' => '400000.0000', 'total' => '400000.0000']);
        $this->record('order_item', 3, ['order_id' => 7, 'item_id' => 5, 'total' => '999999.0000'], removed: true);
        $this->record('order_item', 4, ['order_id' => 8, 'item_id' => 5, 'total' => '777777.0000']);
        $this->record('payment', 1, ['order_id' => 7, 'amount' => '250000.0000', 'method' => 'cash', 'paid_at' => '2026-10-20']);

        Livewire::test(Show::class, ['shop' => $this->shop, 'entity' => 'order', 'record' => $order])
            ->assertSee('Brake pad')
            ->assertSee(__('ui.shop_data.values.payment.method.cash'))
            ->assertSeeHtml('data-children="order_item"')
            ->assertSeeHtml('data-children="payment"')
            ->assertSeeInOrder([Format::number(1000000), Format::number(250000), Format::number(750000)])
            ->assertViewHas('summary', ['total' => 1000000.0, 'paid' => 250000.0, 'balance' => 750000.0])
            ->assertViewHas('children', fn (array $children) => $children['order_item']['total'] === 2);
    }

    public function test_a_removed_record_is_flagged(): void
    {
        $record = $this->record('brand', 1, ['name' => 'Bosch'], 'Bosch', removed: true);

        $this->get(route('shops.data.show', [$this->shop, 'brand', $record]))
            ->assertOk()
            ->assertSee(__('ui.shop_data.removed_banner'));
    }

    public function test_a_record_cannot_be_opened_through_another_shop_or_entity(): void
    {
        $record = $this->record('brand', 1, ['name' => 'Bosch'], 'Bosch');
        $otherShop = Shop::factory()->create();

        $this->get(route('shops.data.show', [$otherShop, 'brand', $record]))->assertNotFound();
        $this->get(route('shops.data.show', [$this->shop, 'item', $record]))->assertNotFound();
    }

    public function test_the_pages_offer_no_way_to_change_the_data(): void
    {
        $record = $this->record('brand', 1, ['name' => 'Bosch'], 'Bosch');

        foreach ([Overview::class, Records::class, Show::class] as $component) {
            $actions = array_filter(
                get_class_methods($component),
                fn (string $method): bool => ! in_array($method, get_class_methods(Component::class), true)
                    && in_array($method, ['save', 'update', 'delete', 'remove', 'destroy', 'store'], true),
            );

            $this->assertSame([], $actions, "{$component} must stay read-only.");
        }

        $this->assertSame(1, ShopRecord::count());
        $this->assertNotNull($record->fresh());
    }
}
