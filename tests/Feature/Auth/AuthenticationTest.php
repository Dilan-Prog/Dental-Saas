<?php

namespace Tests\Feature\Auth;

use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicUser;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesClinicUser;
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertStatus(200);
    }

    public function test_users_can_authenticate_and_last_login_is_recorded(): void
    {
        $user = $this->createClinicUser();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(RouteServiceProvider::HOME);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_email_is_case_insensitive_and_trimmed(): void
    {
        $user = $this->createClinicUser(['email' => 'doctora@clinica.test']);

        $this->post('/login', [
            'email' => '  DOCTORA@Clinica.TEST ',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = $this->createClinicUser();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_requires_valid_email_and_password(): void
    {
        $this->post('/login', ['email' => 'no-es-correo', 'password' => ''])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    public function test_inactive_users_can_not_authenticate(): void
    {
        $user = $this->createClinicUser(['is_active' => false]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_without_active_membership_can_not_authenticate(): void
    {
        $user = $this->createClinicUser([], 'recepcion', false);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_without_password_can_not_authenticate(): void
    {
        $user = $this->createClinicUser();
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update(['password' => null]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = $this->createClinicUser();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_public_registration_and_email_verification_do_not_exist(): void
    {
        $this->get('/register')->assertStatus(404);
        $this->post('/register', [])->assertStatus(404);
        $this->get('/verify-email')->assertStatus(404);
    }

    public function test_guests_are_redirected_to_login_from_root(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
