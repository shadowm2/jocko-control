<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_any_administrative_page(): void
    {
        $shop = Shop::factory()->create();

        foreach ([
            route('dashboard'),
            route('shops.index'),
            route('shops.create'),
            route('shops.show', $shop),
            route('shops.edit', $shop),
            route('events.index'),
            route('audit.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_logging_out_is_a_post_and_requires_authentication(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->get('/logout')->assertStatus(405);
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password-1234'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_login_page_renders_in_persian_rtl(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="fa"', false)
            ->assertSee(__('ui.auth.submit'));
    }

    public function test_an_administrator_can_sign_in_and_is_audited(): void
    {
        $admin = User::factory()->create(['password' => 'correct-horse-9']);

        $response = $this->post(route('login'), ['email' => $admin->email, 'password' => 'correct-horse-9']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login', 'user_id' => $admin->id]);

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_the_session_id_is_regenerated_on_login(): void
    {
        $admin = User::factory()->create(['password' => 'correct-horse-9']);

        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'correct-horse-9']);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_passwords_are_stored_hashed(): void
    {
        $admin = User::factory()->create(['password' => 'correct-horse-9']);

        $stored = $admin->getRawOriginal('password');

        $this->assertNotSame('correct-horse-9', $stored);
        $this->assertTrue(password_verify('correct-horse-9', $stored));
    }

    public function test_wrong_credentials_are_rejected_and_audited(): void
    {
        $admin = User::factory()->create(['password' => 'correct-horse-9']);

        $this->from(route('login'))
            ->post(route('login'), ['email' => $admin->email, 'password' => 'wrong'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login_failed', 'user_id' => $admin->id]);
        $this->assertSame(0, AuditLog::where('metadata', 'like', '%wrong%')->count(), 'Passwords must never reach the audit trail.');
    }

    public function test_a_deactivated_administrator_cannot_sign_in(): void
    {
        $admin = User::factory()->create(['password' => 'correct-horse-9', 'is_active' => false]);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'correct-horse-9'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_deactivated_administrator_with_a_live_session_is_signed_out(): void
    {
        $admin = User::factory()->create(['is_active' => false]);

        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $admin = User::factory()->create(['password' => 'correct-horse-9']);
        RateLimiter::clear(strtolower($admin->email).'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => $admin->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        }

        // Even the right password is refused while locked out.
        $this->post(route('login'), ['email' => $admin->email, 'password' => 'correct-horse-9'])
            ->assertSessionHasErrors(['email' => __('ui.auth.throttle', ['seconds' => RateLimiter::availableIn(strtolower($admin->email).'|127.0.0.1')])]);

        $this->assertGuest();

        // The JSON form of the same refusal is a real 429.
        $this->postJson(route('login'), ['email' => $admin->email, 'password' => 'correct-horse-9'])->assertStatus(429);
    }

    public function test_logout_ends_the_session_and_is_audited(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.logout', 'user_id' => $admin->id]);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_an_authenticated_user_is_redirected_away_from_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect();
    }

    public function test_the_create_admin_command_prompts_for_credentials_and_hashes_the_password(): void
    {
        $this->artisan('jocko:create-admin')
            ->expectsQuestion('Name', 'Site Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password (min. 12 characters)', 'a-long-secret-1')
            ->expectsQuestion('Confirm password', 'a-long-secret-1')
            ->expectsOutputToContain('created')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.com')->sole();
        $this->assertTrue($admin->is_active);
        $this->assertNotSame('a-long-secret-1', $admin->getRawOriginal('password'));
        $this->assertTrue(password_verify('a-long-secret-1', $admin->getRawOriginal('password')));
    }

    public function test_the_create_admin_command_refuses_mismatched_passwords_and_duplicates(): void
    {
        $this->artisan('jocko:create-admin')
            ->expectsQuestion('Name', 'Site Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password (min. 12 characters)', 'a-long-secret-1')
            ->expectsQuestion('Confirm password', 'different-secret-1')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);

        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('jocko:create-admin', ['--name' => 'Dup', '--email' => 'taken@example.com'])
            ->expectsQuestion('Password (min. 12 characters)', 'a-long-secret-1')
            ->expectsQuestion('Confirm password', 'a-long-secret-1')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
