<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\BaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Utilidades para pruebas de la clínica (única organización).
 * Requiere que BaseSeeder haya corrido (TestCase lo hace en setUp).
 */
trait InteractsWithClinic
{
    protected function seedBase(): void
    {
        $this->seed(BaseSeeder::class);
    }

    protected function organizationId(): string
    {
        return (string) DB::table('organizations')->orderBy('created_at')->value('id');
    }

    protected function mainBranchId(): string
    {
        return (string) DB::table('branches')->where('organization_id', $this->organizationId())
            ->where('is_main', true)->value('id');
    }

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** Autentica como el administrador sembrado (admin@dental.test). */
    protected function actingAsAdmin(): User
    {
        $user = $this->userByEmail('admin@dental.test');
        $this->actingAs($user);

        return $user;
    }

    protected function actingAsDoctor(): User
    {
        $user = $this->userByEmail('doctor@dental.test');
        $this->actingAs($user);

        return $user;
    }

    protected function actingAsReceptionist(): User
    {
        $user = $this->userByEmail('recepcion@dental.test');
        $this->actingAs($user);

        return $user;
    }

    /** id de organization_members del usuario (p. ej. doctor_member_id). */
    protected function memberId(User|string $user): string
    {
        $id = $user instanceof User ? $user->id : $user;

        return (string) DB::table('organization_members')
            ->where('organization_id', $this->organizationId())->where('user_id', $id)->value('id');
    }
}
