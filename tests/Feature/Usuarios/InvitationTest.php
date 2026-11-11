<?php

namespace Tests\Feature\Usuarios;

use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;

class InvitationTest extends UsuariosTestCase
{
    /** Crea una invitación y devuelve [token, user, member]. */
    private function invite(): array
    {
        $this->actingAsAdmin();
        $this->post(route('usuarios.store'), $this->userData());
        $token = basename(session('access_link')['url']);
        $user = User::where('email', 'ana.lopez@clinica.mx')->firstOrFail();
        auth()->logout();

        return [$token, $user, OrganizationMember::where('user_id', $user->id)->firstOrFail()];
    }

    public function test_invitation_page_is_public_and_shows_the_form(): void
    {
        [$token] = $this->invite();

        $this->get(route('invitations.accept', $token))
            ->assertOk()->assertSee('Hola, Dra. Ana López Ruiz')->assertSee('Guardar y entrar');
    }

    public function test_accepting_sets_the_password_activates_the_account_and_logs_in(): void
    {
        [$token, $user, $member] = $this->invite();

        $this->post(route('invitations.complete', $token), ['password' => 'Segura2026', 'password_confirmation' => 'Segura2026'])
            ->assertRedirect('/');

        $user->refresh();
        $this->assertTrue(Hash::check('Segura2026', $user->password));
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($member->fresh()->is_active);
        $this->assertNotNull(UserInvitation::where('email', $user->email)->value('accepted_at'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_link_works_only_once(): void
    {
        [$token] = $this->invite();

        $this->post(route('invitations.complete', $token), ['password' => 'Segura2026', 'password_confirmation' => 'Segura2026']);
        auth()->logout();

        $this->get(route('invitations.accept', $token))->assertSee('Enlace no válido');
        $this->post(route('invitations.complete', $token), ['password' => 'Otra2026xx', 'password_confirmation' => 'Otra2026xx'])
            ->assertRedirect(route('invitations.accept', $token));
        $this->assertGuest();
    }

    public function test_weak_or_mismatched_passwords_are_rejected(): void
    {
        [$token, $user] = $this->invite();

        foreach ([
            ['abc', 'abc'],                       // corta
            ['sololetrasminusculas', 'sololetrasminusculas'],   // sin mayúsculas ni número
            ['SinNumeroAqui', 'SinNumeroAqui'],   // sin número
            ['Segura2026', 'Distinta2026'],       // no coincide
            ['', ''],                             // vacía
        ] as [$password, $confirmation]) {
            $this->post(route('invitations.complete', $token), ['password' => $password, 'password_confirmation' => $confirmation])
                ->assertSessionHasErrors('password');
            $this->assertNull($user->fresh()->password);
        }
    }

    public function test_expired_and_unknown_tokens_show_an_invalid_link_page(): void
    {
        [$token, $user] = $this->invite();
        UserInvitation::where('email', $user->email)->update(['expires_at' => now()->subMinute()]);

        $this->get(route('invitations.accept', $token))->assertOk()->assertSee('Enlace no válido');
        $this->get(route('invitations.accept', str_repeat('a', 48)))->assertOk()->assertSee('Enlace no válido');
        $this->get('/invitaciones/corto')->assertNotFound();
    }

    public function test_a_user_who_has_not_accepted_cannot_log_in(): void
    {
        [, $user] = $this->invite();

        $this->post('/login', ['email' => $user->email, 'password' => 'cualquiera'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
