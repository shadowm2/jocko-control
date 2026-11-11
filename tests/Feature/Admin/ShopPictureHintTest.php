<?php

namespace Tests\Feature\Admin;

use App\Models\Shop;
use App\Models\ShopFile;
use App\Models\ShopRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

class ShopPictureHintTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->shop = Shop::factory()->create(['keep_original_pictures' => true]);
    }

    private function picture(int $mediaId, int $ownerId, string $kind, int $order = 0): void
    {
        $this->mirror($this->shop, 'media', $mediaId, ['owner_type' => 'brand', 'owner_id' => $ownerId, 'collection' => 'images', 'mime_type' => 'image/jpeg', 'original_name' => "p{$mediaId}.jpg", 'order' => $order]);
        ShopFile::forceCreate(['shop_id' => $this->shop->id, 'media_id' => $mediaId, 'kind' => $kind, 'sha256' => str_repeat((string) ($mediaId % 10), 64), 'mime_type' => 'image/jpeg', 'size' => 1000, 'path' => "shop-files/x/{$mediaId}.jpg"]);
    }

    private function brandPage(): string
    {
        $brand = $this->mirror($this->shop, 'brand', 1, ['name' => 'Bosch']);

        return html_entity_decode($this->get(route('shops.data.show', ['shop' => $this->shop, 'entity' => 'brand', 'record' => $brand]))->assertOk()->getContent());
    }

    public function test_only_the_picture_that_is_a_preview_carries_the_note(): void
    {
        $this->picture(12, 1, ShopFile::ORIGINAL, order: 0);
        $this->picture(13, 1, ShopFile::PREVIEW, order: 1);

        $html = $this->brandPage();

        $this->assertSame(1, substr_count($html, 'data-preview-note'), 'one note, for the one preview');
        $this->assertStringContainsString(__('ui.shop_data.pictures_hint'), $html);

        // The note follows the preview (13), not the original (12).
        $this->assertMatchesRegularExpression('#files/13".*?data-preview-note#s', $html);
        $this->assertDoesNotMatchRegularExpression('#files/12".*?data-preview-note.*?files/13#s', $html);
    }

    public function test_a_page_of_originals_only_has_no_note(): void
    {
        $this->picture(12, 1, ShopFile::ORIGINAL);

        $this->assertStringNotContainsString('data-preview-note', $this->brandPage());
    }

    public function test_the_note_does_not_depend_on_the_shops_current_setting(): void
    {
        $this->shop->update(['keep_original_pictures' => false]);
        $this->picture(12, 1, ShopFile::ORIGINAL);

        $this->assertStringNotContainsString('data-preview-note', $this->brandPage(), 'the file is an original whatever the switch says now');
    }

    public function test_the_page_of_a_single_picture_says_it_too(): void
    {
        $this->picture(14, 1, ShopFile::PREVIEW);
        $record = ShopRecord::where('entity', 'media')->sole();

        $html = html_entity_decode($this->get(route('shops.data.show', ['shop' => $this->shop, 'entity' => 'media', 'record' => $record]))->assertOk()->getContent());

        $this->assertStringContainsString('data-preview-note', $html);
    }
}
