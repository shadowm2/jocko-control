<?php

namespace Tests\Feature\Admin;

use App\Models\Shop;
use App\Models\ShopFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShopMirror;
use Tests\TestCase;

class ShopPictureHintTest extends TestCase
{
    use BuildsShopMirror, RefreshDatabase;

    private function pageOfAPicture(bool $keepsOriginals): string
    {
        $this->actingAs(User::factory()->create());
        $shop = Shop::factory()->create(['keep_original_pictures' => $keepsOriginals]);
        $record = $this->mirror($shop, 'media', 12, ['owner_type' => 'brand', 'owner_id' => 1, 'collection' => 'images', 'mime_type' => 'image/jpeg', 'original_name' => 'photo.jpg']);
        ShopFile::forceCreate(['shop_id' => $shop->id, 'media_id' => 12, 'sha256' => str_repeat('a', 64), 'mime_type' => 'image/jpeg', 'size' => 1000, 'path' => 'shop-files/x/a.jpg']);

        return $this->get(route('shops.data.show', ['shop' => $shop, 'entity' => 'media', 'record' => $record]))->assertOk()->assertSee(route('shops.files.show', [$shop, 12]), false)->getContent();
    }

    public function test_a_shop_on_previews_is_told_the_picture_is_a_downsized_copy(): void
    {
        $this->assertStringContainsString(__('ui.shop_data.pictures_hint'), html_entity_decode($this->pageOfAPicture(keepsOriginals: false)));
    }

    public function test_a_shop_that_keeps_originals_is_not_told_that(): void
    {
        $this->assertStringNotContainsString(__('ui.shop_data.pictures_hint'), html_entity_decode($this->pageOfAPicture(keepsOriginals: true)));
    }
}
