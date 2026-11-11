<?php

namespace Tests\Feature\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesClinicUser;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use CreatesClinicUser;
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = $this->createClinicUser();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_link_requires_a_valid_email(): void
    {
        $this->post('/forgot-password', ['email' => 'no-es-correo'])
            ->assertSessionHasErrors('email');
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = $this->createClinicUser();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $this->get('/reset-password/'.$notification->token)->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = $this->createClinicUser();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NuevaClave-2026',
                'password_confirmation' => 'NuevaClave-2026',
            ]);

            $response->assertSessionHasNoErrors()->assertRedirect(route('login'));
            $this->assertTrue(Hash::check('NuevaClave-2026', $user->fresh()->password));

            return true;
        });
    }

    public function test_password_reset_requires_matching_confirmation(): void
    {
        Notification::fake();

        $user = $this->createClinicUser();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NuevaClave-2026',
                'password_confirmation' => 'otra-cosa',
            ])->assertSessionHasErrors('password');

            return true;
        });
    }
}
