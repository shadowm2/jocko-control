<?php

namespace Tests\Feature\Admin;

use App\Livewire\Shops\Show;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

class ShopEventRecordLinkTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->shop = Shop::factory()->create();
    }

    private function eventFor(string $aggregateId, ?Shop $shop = null): SyncEvent
    {
        $shop ??= $this->shop;

        return SyncEvent::factory()->for($shop)->for(ShopDevice::factory()->for($shop), 'device')->create([
            'event_type' => 'brand.created',
            'aggregate_type' => 'brand',
            'aggregate_id' => $aggregateId,
        ]);
    }

    public function test_a_recent_event_links_to_the_record_it_is_about(): void
    {
        $record = $this->mirror($this->shop, 'brand', 7, ['name' => 'Bosch']);
        $this->eventFor($record->aggregate_id);

        $url = route('shops.data.show', ['shop' => $this->shop, 'entity' => 'brand', 'record' => $record]);

        Livewire::test(Show::class, ['shop' => $this->shop])->assertSeeHtml('href="'.$url.'"')->assertSee(__('ui.shops.open_record'));

        $this->get($url)->assertOk()->assertSee('Bosch');
    }

    public function test_an_event_without_a_mirrored_record_has_no_link(): void
    {
        $this->eventFor('11111111-1111-4111-8111-111111111111');

        Livewire::test(Show::class, ['shop' => $this->shop])->assertDontSee(__('ui.shops.open_record'));
    }

    public function test_another_shops_record_is_never_linked(): void
    {
        $other = Shop::factory()->create();
        $record = $this->mirror($other, 'brand', 7, ['name' => 'Theirs']);
        $this->eventFor($record->aggregate_id);

        Livewire::test(Show::class, ['shop' => $this->shop])->assertDontSee(__('ui.shops.open_record'));
    }
}
