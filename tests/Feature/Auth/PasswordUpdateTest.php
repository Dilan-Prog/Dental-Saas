<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesClinicUser;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use CreatesClinicUser;
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = $this->createClinicUser();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'NuevaClave-2026',
                'password_confirmation' => 'NuevaClave-2026',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $this->assertTrue(Hash::check('NuevaClave-2026', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = $this->createClinicUser();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'NuevaClave-2026',
                'password_confirmation' => 'NuevaClave-2026',
            ]);

        $response->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }

    public function test_new_password_must_be_confirmed_and_long_enough(): void
    {
        $user = $this->createClinicUser();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'corta',
                'password_confirmation' => 'distinta',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'password');
    }
}
