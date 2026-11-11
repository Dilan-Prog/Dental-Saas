<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicUser;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use CreatesClinicUser;
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = $this->createClinicUser();

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_profile_page_requires_authentication(): void
    {
        $this->get('/profile')->assertRedirect(route('login'));
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = $this->createClinicUser();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'full_name' => 'Mariana Reyes Lopez',
                'email' => 'mariana@clinica.test',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Mariana Reyes Lopez', $user->full_name);
        $this->assertSame('mariana@clinica.test', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = $this->createClinicUser();

        $this->actingAs($user)
            ->patch('/profile', [
                'full_name' => 'Nombre Nuevo',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_profile_validation_rejects_invalid_data(): void
    {
        $user = $this->createClinicUser();
        $other = $this->createClinicUser();

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', ['full_name' => '', 'email' => 'no-es-correo'])
            ->assertSessionHasErrors(['full_name', 'email']);

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', ['full_name' => 'Ab', 'email' => $other->email])
            ->assertSessionHasErrors(['full_name', 'email']);
    }

    public function test_user_can_deactivate_their_account(): void
    {
        $user = $this->createClinicUser();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', ['password' => 'password']);

        $response->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertGuest();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertNotNull($user->fresh(), 'la cuenta se da de baja, no se borra');
    }

    public function test_correct_password_must_be_provided_to_deactivate_account(): void
    {
        $user = $this->createClinicUser();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', ['password' => 'wrong-password']);

        $response->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertTrue($user->fresh()->is_active);
    }
}
