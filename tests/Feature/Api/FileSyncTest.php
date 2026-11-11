<?php

namespace Tests\Feature\Api;

use App\Livewire\Shops\Form;
use App\Models\Shop;
use App\Models\ShopFile;
use App\Models\ShopRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShopMirror;
use Tests\Concerns\CreatesDevices;
use Tests\TestCase;

class FileSyncTest extends TestCase
{
    use BuildsShopMirror, CreatesDevices, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * After a device call the default guard stays on sanctum (see the project notes).
     */
    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs(User::factory()->create());
    }

    /**
     * @return array{file: UploadedFile, sha256: string}
     */
    private function image(string $name = 'p.jpg', int $width = 40): array
    {
        $file = UploadedFile::fake()->image($name, $width, $width);

        return ['file' => $file, 'sha256' => hash_file('sha256', $file->getRealPath())];
    }

    public function test_a_device_uploads_the_preview_of_one_of_its_pictures(): void
    {
        $issued = $this->issueDevice();
        $image = $this->image();

        $this->post('/api/v1/sync/files', ['media_id' => 12] + $image, $this->bearer($issued))
            ->assertCreated()
            ->assertJsonPath('data.media_id', 12)
            ->assertJsonPath('data.status', 'stored');

        $stored = ShopFile::sole();
        $this->assertSame($issued->device->shop_id, $stored->shop_id);
        $this->assertSame($image['sha256'], $stored->sha256);
        $this->assertSame('image/jpeg', $stored->mime_type);
        Storage::disk('local')->assertExists($stored->path);
    }

    public function test_sending_the_same_file_again_stores_nothing_new(): void
    {
        $issued = $this->issueDevice();
        $image = $this->image();

        $this->post('/api/v1/sync/files', ['media_id' => 12] + $image, $this->bearer($issued))->assertCreated();
        $this->post('/api/v1/sync/files', ['media_id' => 12] + $image, $this->bearer($issued))
            ->assertOk()
            ->assertJsonPath('data.status', 'already_stored');

        $this->assertSame(1, ShopFile::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_new_file_for_the_same_picture_replaces_the_old_one(): void
    {
        $issued = $this->issueDevice();
        $old = $this->image('a.jpg', 40);
        $new = $this->image('b.jpg', 60);

        $this->post('/api/v1/sync/files', ['media_id' => 12] + $old, $this->bearer($issued))->assertCreated();
        $oldPath = ShopFile::sole()->path;
        $this->post('/api/v1/sync/files', ['media_id' => 12] + $new, $this->bearer($issued))->assertCreated();

        $this->assertSame($new['sha256'], ShopFile::sole()->sha256);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_wrong_checksum_is_refused(): void
    {
        $issued = $this->issueDevice();

        $this->post('/api/v1/sync/files', ['media_id' => 12, 'sha256' => str_repeat('a', 64), 'file' => $this->image()['file']], $this->bearer($issued))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['sha256']]]);

        $this->assertSame(0, ShopFile::query()->count());
    }

    public function test_only_small_images_are_accepted(): void
    {
        $issued = $this->issueDevice(Shop::factory()->create(['keep_original_pictures' => false]));

        // Real files, because the check reads the content, not the name: a script or an SVG with a picture's name.
        $disguised = fn (string $name, string $content): UploadedFile => new UploadedFile(
            tap(tempnam(sys_get_temp_dir(), 'up'), fn (string $path) => file_put_contents($path, $content)),
            $name,
            'image/jpeg',
            null,
            true,
        );
        $text = $disguised('x.jpg', '<?php echo 1;');
        $svg = $disguised('x.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $huge = UploadedFile::fake()->image('big.jpg')->size(3000);

        foreach (['text' => $text, 'svg' => $svg, 'huge' => $huge] as $kind => $file) {
            $this->app['auth']->forgetGuards();
            $response = $this->post('/api/v1/sync/files', ['media_id' => 1, 'sha256' => hash_file('sha256', $file->getRealPath()), 'file' => $file], $this->bearer($issued));

            $this->assertSame(422, $response->status(), "A {$kind} file must be refused.");
        }

        $this->assertSame(0, ShopFile::query()->count());
    }

    public function test_the_shop_comes_from_the_token_and_shops_cannot_touch_each_others_files(): void
    {
        $alpha = $this->issueDevice();
        $beta = $this->issueDevice();

        $this->post('/api/v1/sync/files', ['media_id' => 5, 'shop_id' => $beta->device->shop_id] + $this->image('a.jpg', 40), $this->bearer($alpha))->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->post('/api/v1/sync/files', ['media_id' => 5] + $this->image('b.jpg', 60), $this->bearer($beta))->assertCreated();

        $this->assertSame(2, ShopFile::query()->count());
        $this->assertSame(1, ShopFile::query()->where('shop_id', $alpha->device->shop_id)->count());
        $this->assertSame(1, ShopFile::query()->where('shop_id', $beta->device->shop_id)->count());
    }

    public function test_it_needs_a_device_token(): void
    {
        $this->post('/api/v1/sync/files', ['media_id' => 1] + $this->image(), ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_a_revoked_or_suspended_device_cannot_upload(): void
    {
        $issued = $this->issueDevice(Shop::factory()->suspended()->create());

        $this->post('/api/v1/sync/files', ['media_id' => 1] + $this->image(), $this->bearer($issued))->assertForbidden();
    }

    public function test_only_signed_in_administrators_can_view_a_preview(): void
    {
        $issued = $this->issueDevice();
        $this->post('/api/v1/sync/files', ['media_id' => 12] + $this->image(), $this->bearer($issued))->assertCreated();
        $shop = $issued->device->shop;

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->get(route('shops.files.show', [$shop, 12]))->assertRedirect(route('login'));

        $this->asAdmin()
            ->get(route('shops.files.show', [$shop, 12]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        $this->get(route('shops.files.show', [$shop, 99]))->assertNotFound();
        $this->get(route('shops.files.show', [Shop::factory()->create(), 12]))->assertNotFound();
    }

    public function test_deleting_the_picture_in_the_shop_removes_the_preview(): void
    {
        $issued = $this->issueDevice();
        $this->post('/api/v1/sync/files', ['media_id' => 12] + $this->image(), $this->bearer($issued))->assertCreated();
        $path = ShopFile::sole()->path;

        $payload = ['id' => 12, 'owner_type' => 'item', 'owner_id' => 4, 'collection' => 'images', 'mime_type' => 'image/jpeg', 'original_name' => 'a.jpg'];
        $created = $this->eventPayload(['event_type' => 'media.created', 'aggregate_type' => 'media', 'base_version' => null, 'payload' => $payload, 'occurred_at' => now()->subMinute()->toIso8601String()]);
        $deleted = $this->eventPayload(['event_type' => 'media.deleted', 'aggregate_type' => 'media', 'aggregate_id' => $created['aggregate_id'], 'base_version' => null, 'payload' => $payload, 'occurred_at' => now()->toIso8601String()]);

        $this->postJson('/api/v1/sync/events', ['events' => [$created]], $this->bearer($issued))->assertOk();
        $this->assertSame(1, ShopFile::query()->count());

        $this->postJson('/api/v1/sync/events', ['events' => [$deleted]], $this->bearer($issued))->assertOk();

        $this->assertTrue(ShopRecord::query()->where('entity', 'media')->sole()->isRemoved());
        $this->assertSame(0, ShopFile::query()->count());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_record_page_shows_its_pictures_and_the_list_shows_a_thumbnail(): void
    {
        $this->actingAs(User::factory()->create());
        $shop = Shop::factory()->create();
        $item = $this->mirror($shop, 'item', 4, ['name' => 'Brake pad'], 'Brake pad');
        $this->mirror($shop, 'item', 5, ['name' => 'No picture'], 'No picture');
        $this->mirror($shop, 'media', 12, ['owner_type' => 'item', 'owner_id' => 4, 'order' => 1], 'a.jpg');
        $this->mirror($shop, 'media', 13, ['owner_type' => 'item', 'owner_id' => 4, 'order' => 0], 'b.jpg');
        $this->mirror($shop, 'media', 14, ['owner_type' => 'item', 'owner_id' => 4, 'order' => 2], 'no-preview.jpg');
        $this->mirror($shop, 'media', 15, ['owner_type' => 'brand', 'owner_id' => 4], 'other-owner.jpg');
        $this->mirror($shop, 'media', 16, ['owner_type' => 'item', 'owner_id' => 4], 'removed.jpg', removed: true);
        foreach ([12, 13, 15, 16] as $media) {
            ShopFile::factory()->create(['shop_id' => $shop->id, 'media_id' => $media]);
        }

        $this->get(route('shops.data.show', [$shop, 'item', $item]))
            ->assertOk()
            ->assertSee('data-pictures', false)
            ->assertSeeInOrder([route('shops.files.show', [$shop, 13]), route('shops.files.show', [$shop, 12])], false)
            ->assertDontSee(route('shops.files.show', [$shop, 14]), false)
            ->assertDontSee(route('shops.files.show', [$shop, 15]), false)
            ->assertDontSee(route('shops.files.show', [$shop, 16]), false);

        $this->get(route('shops.data.entity', [$shop, 'item']))
            ->assertOk()
            ->assertSee(route('shops.files.show', [$shop, 13]), false);

        $this->get(route('shops.data.entity', [$shop, 'media']))
            ->assertOk()
            ->assertSee(route('shops.files.show', [$shop, 12]), false)
            ->assertDontSee(route('shops.files.show', [$shop, 14]), false);
    }

    /**
     * @return array{file: UploadedFile, sha256: string}
     */
    private function bigImage(int $kilobytes): array
    {
        $file = UploadedFile::fake()->image('big.jpg', 60, 60)->size($kilobytes);

        return ['file' => $file, 'sha256' => hash_file('sha256', $file->getRealPath())];
    }

    public function test_a_shop_that_keeps_originals_may_send_a_large_file(): void
    {
        $issued = $this->issueDevice(Shop::factory()->create(['keep_original_pictures' => true]));

        $this->post('/api/v1/sync/files', ['media_id' => 5] + $this->bigImage(5000), $this->bearer($issued))->assertCreated();
    }

    public function test_a_shop_on_previews_is_still_held_to_the_preview_size(): void
    {
        $issued = $this->issueDevice(Shop::factory()->create(['keep_original_pictures' => false]));

        $this->post('/api/v1/sync/files', ['media_id' => 5] + $this->bigImage(5000), $this->bearer($issued))->assertStatus(422);
    }

    public function test_even_an_original_has_an_upper_limit(): void
    {
        $issued = $this->issueDevice(Shop::factory()->create(['keep_original_pictures' => true]));

        $this->post('/api/v1/sync/files', ['media_id' => 5] + $this->bigImage(8000), $this->bearer($issued))->assertStatus(422);
    }

    public function test_the_device_is_told_whether_to_send_originals_or_previews(): void
    {
        $original = $this->issueDevice(Shop::factory()->create(['keep_original_pictures' => true]));
        $preview = $this->issueDevice(Shop::factory()->create(['keep_original_pictures' => false]));

        // Sanctum remembers the first device within one test: start each call fresh.
        $call = function (string $method, string $uri, $issued) {
            $this->app['auth']->forgetGuards();

            return $method === 'getJson' ? $this->getJson($uri, $this->bearer($issued)) : $this->postJson($uri, [], $this->bearer($issued));
        };

        $call('postJson', '/api/v1/device/heartbeat', $original)->assertOk()->assertJsonPath('data.pictures', 'original');
        $call('postJson', '/api/v1/device/heartbeat', $preview)->assertOk()->assertJsonPath('data.pictures', 'preview');
        $call('getJson', '/api/v1/device/me', $original)->assertJsonPath('data.config.pictures', 'original');
        $call('getJson', '/api/v1/device/me', $preview)->assertJsonPath('data.config.pictures', 'preview');
    }

    public function test_an_administrator_flips_the_switch_on_the_shop_form(): void
    {
        $shop = Shop::factory()->create(['keep_original_pictures' => true]);

        $this->asAdmin();
        Livewire::test(Form::class, ['shop' => $shop])
            ->assertSet('keep_original_pictures', true)
            ->set('keep_original_pictures', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($shop->fresh()->keepsOriginalPictures());
    }
}
