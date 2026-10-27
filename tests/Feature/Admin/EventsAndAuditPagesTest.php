<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditAction;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Events\Index as EventsIndex;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\ShopDevice;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class EventsAndAuditPagesTest extends TestCase
{
    use CreatesDevices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_received_events_are_visible_and_filterable(): void
    {
        $a = ShopDevice::factory()->for(Shop::factory()->create(['name' => 'Alpha']))->create();
        $b = ShopDevice::factory()->for(Shop::factory()->create(['name' => 'Beta']))->create();
        SyncEvent::factory()->create(['device_id' => $a->id, 'event_type' => 'invoice.paid']);
        SyncEvent::factory()->create(['device_id' => $b->id, 'event_type' => 'part.consumed']);

        $this->get(route('events.index'))->assertOk()->assertSee('invoice.paid')->assertSee('part.consumed')
            ->assertSee(__('ui.events.boundary_notice'));

        Livewire::test(EventsIndex::class)
            ->set('shop', $a->shop_id)
            ->assertSee('invoice.paid')
            ->assertDontSee('part.consumed')
            ->set('shop', '')
            ->set('search', 'part.')
            ->assertSee('part.consumed')
            ->assertDontSee('invoice.paid');
    }

    public function test_an_event_payload_can_be_inspected(): void
    {
        $event = SyncEvent::factory()->create(['payload' => ['plate' => '12ب345-67', 'total' => 990]]);

        Livewire::test(EventsIndex::class)
            ->call('view', $event->id)
            ->assertSee($event->event_id)
            ->assertSee('12ب345-67');
    }

    public function test_the_events_list_is_paginated_without_counting(): void
    {
        SyncEvent::factory()->count(30)->create();

        Livewire::test(EventsIndex::class)
            ->assertViewHas('events', fn ($events) => $events->count() === 25 && $events->hasMorePages());
    }

    public function test_the_audit_log_is_listed_and_filterable_by_action(): void
    {
        $shop = Shop::factory()->create(['name' => 'Alpha']);
        AuditLog::create(['shop_id' => $shop->id, 'action' => 'shop.created']);
        AuditLog::create(['shop_id' => $shop->id, 'action' => 'shop.suspended']);

        $this->get(route('audit.index'))->assertOk()
            ->assertSee(__('ui.audit_action.shop_created'))
            ->assertSee(__('ui.audit_action.shop_suspended'));

        Livewire::test(AuditIndex::class)
            ->set('action', 'shop.suspended')
            ->assertViewHas('logs', fn ($logs) => $logs->pluck('action')->map->value->all() === ['shop.suspended']);
    }

    public function test_no_secret_ever_appears_in_the_audit_log(): void
    {
        $issued = $this->issueDevice();
        $secret = explode('|', $issued->plainTextToken, 2)[1];

        app(AuditLogger::class)->record(
            AuditAction::DeviceCreated,
            device: $issued->device,
            metadata: ['token' => $secret, 'password' => 'hunter2', 'nested' => ['plain_text_token' => $secret], 'name' => 'ok'],
        );

        $log = AuditLog::latest('id')->first();
        $this->assertEquals(['name' => 'ok', 'nested' => []], $log->metadata);
    }
}
