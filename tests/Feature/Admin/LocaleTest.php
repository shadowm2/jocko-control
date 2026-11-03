<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_picking_a_language_is_remembered_in_a_cookie(): void
    {
        $this->from(route('login'))->post(route('locale.update', 'en'))
            ->assertRedirect(route('login'))
            ->assertCookie('locale', 'en');
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->post(route('locale.update', 'xx'))->assertNotFound();
    }

    public function test_english_is_left_to_right_and_persian_is_the_default(): void
    {
        $this->get(route('login'))->assertSee('lang="fa"', false)->assertSee('dir="rtl"', false);

        $this->withCookie('locale', 'en')->get(route('login'))
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Administrator sign-in');
    }

    public function test_an_unknown_cookie_value_falls_back_to_the_default(): void
    {
        $this->withCookie('locale', 'xx')->get(route('login'))->assertSee('lang="fa"', false);
    }

    public function test_the_pages_render_in_english_with_the_gregorian_calendar(): void
    {
        $this->actingAs(User::factory()->create())->withCookie('locale', 'en');

        foreach (['dashboard', 'shops.index', 'events.index', 'audit.index'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee('ui.');
        }

        $this->get(route('dashboard'))->assertSee('Needs attention');
    }

    public function test_every_persian_ui_string_has_an_english_one(): void
    {
        $missing = array_diff_key(
            collect(Arr::dot(require lang_path('fa/ui.php')))->all(),
            Arr::dot(require lang_path('en/ui.php')),
        );

        $this->assertSame([], array_keys($missing), 'Missing from lang/en/ui.php.');

        $extra = array_diff_key(
            Arr::dot(require lang_path('en/ui.php')),
            Arr::dot(require lang_path('fa/ui.php')),
        );

        $this->assertSame([], array_keys($extra), 'Missing from lang/fa/ui.php.');
    }
}
