<?php

namespace Tests\Feature\Admin;

use App\Livewire\Events\Index as EventsIndex;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use App\Models\User;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EventTypeLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_event_type_is_put_in_words_in_persian(): void
    {
        $this->assertSame('ایجاد سفارش', Format::eventType('order.created'));
        $this->assertSame('ویرایش قلم سفارش', Format::eventType('order_item.updated'));
        $this->assertSame('حذف تصویر', Format::eventType('media.deleted'));
        $this->assertSame('بازیابی کاربر', Format::eventType('user.restored'));
        $this->assertSame('همگام‌سازی کامل پرداخت', Format::eventType('payment.snapshot'));
    }

    public function test_it_is_in_english_in_the_english_interface(): void
    {
        app()->setLocale('en');

        $this->assertSame('Order created', Format::eventType('order.created'));
        $this->assertSame('Warehouse stock full copy', Format::eventType('warehouse_item.snapshot'));
    }

    public function test_a_name_without_a_word_is_shown_as_it_is_and_a_malformed_one_is_not_translated(): void
    {
        $this->assertSame('ایجاد repair_order', Format::eventType('repair_order.created'));
        $this->assertSame('shipped سفارش', Format::eventType('order.shipped'));
        $this->assertNull(Format::eventType('order'));
        $this->assertNull(Format::eventType('.created'));
        $this->assertNull(Format::eventType('order.'));
        $this->assertNull(Format::eventType('a.b.c'));
    }

    public function test_the_events_list_shows_the_words_and_the_exact_type_small_in_parentheses(): void
    {
        $this->actingAs(User::factory()->create());
        $device = ShopDevice::factory()->for(Shop::factory()->create())->create();
        SyncEvent::factory()->create(['device_id' => $device->id, 'event_type' => 'order.created']);

        $html = $this->get(route('events.index'))->assertOk()->getContent();

        $this->assertStringContainsString('ایجاد سفارش', $html);
        $this->assertMatchesRegularExpression('/<span[^>]*text-xs[^>]*dir="ltr"[^>]*>\(order\.created\)<\/span>/', $html);
    }

    public function test_the_detail_dialog_and_the_shop_pages_use_the_same_label(): void
    {
        $this->actingAs(User::factory()->create());
        $shop = Shop::factory()->create();
        $device = ShopDevice::factory()->for($shop)->create();
        $event = SyncEvent::factory()->create(['device_id' => $device->id, 'event_type' => 'payment.updated']);

        Livewire::test(EventsIndex::class)->call('view', $event->id)
            ->assertSee('ویرایش پرداخت')->assertSee('(payment.updated)');

        $this->get(route('shops.show', $shop))->assertOk()->assertSee('ویرایش پرداخت')->assertSee('(payment.updated)');
    }

    public function test_searching_still_works_on_the_exact_type(): void
    {
        $this->actingAs(User::factory()->create());
        $device = ShopDevice::factory()->for(Shop::factory()->create())->create();
        SyncEvent::factory()->create(['device_id' => $device->id, 'event_type' => 'order.created']);
        SyncEvent::factory()->create(['device_id' => $device->id, 'event_type' => 'brand.created']);

        Livewire::test(EventsIndex::class)->set('search', 'order.')->assertSee('(order.created)')->assertDontSee('(brand.created)');
    }
}
