<?php

namespace App\Services\Usuarios;

use App\Models\DoctorProfile;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\RoleChangeHistory;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Altas, edición, activación y acceso (invitación) de usuarios del consultorio.
 *
 * El acceso inicial se entrega SIEMPRE con un enlace de un solo uso
 * (user_invitations guarda solo el hash SHA-256 del token). El usuario define
 * su propia contraseña al aceptarlo, de modo que nunca existe una contraseña
 * temporal en circulación.
 */
class UserAccessService
{
    public const INVITATION_DAYS = 7;

    /**
     * Crea el usuario (inactivo hasta aceptar la invitación), su membresía,
     * sucursales y perfil de doctor. Devuelve [member, enlace de acceso].
     *
     * @param  array{full_name:string,email:string,role_id:string,branch_ids:array,is_doctor?:bool,professional_license?:?string,specialty?:?string}  $data
     * @return array{0: OrganizationMember, 1: string}
     */
    public function create(array $data, ?UploadedFile $signature, User $actor): array
    {
        try {
            return DB::transaction(function () use ($data, $signature, $actor) {
                $user = User::create([
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'is_active' => false,
                ]);

                $member = OrganizationMember::create([
                    'user_id' => $user->id,
                    'role_id' => $data['role_id'],
                    'is_active' => false,
                ]);

                $this->syncBranches($member, $data['branch_ids']);
                $this->syncDoctorProfile($member, $data, $signature);
                $this->logRoleAssigned($member, null, Role::find($data['role_id']), $actor);

                $url = $this->issueInvitation($member, $actor);

                return [$member->fresh(['user', 'role']), $url];
            });
        } catch (QueryException $e) {
            throw AccessException::fromQuery($e) ?? $e;
        }
    }

    /**
     * Genera (y reemplaza) la invitación vigente de un miembro. Devuelve la URL
     * completa; el token en claro NO se guarda y no se puede volver a ver.
     */
    public function issueInvitation(OrganizationMember $member, User $actor): string
    {
        $member->loadMissing('user');

        UserInvitation::query()
            ->where('email', $member->user->email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $token = Str::random(48);

        UserInvitation::create([
            'email' => $member->user->email,
            'role_id' => $member->role_id,
            'invited_by_member_id' => $actor->member?->id,
            'token_hash' => self::hashToken($token),
            'expires_at' => now()->addDays(self::INVITATION_DAYS),
        ]);

        return route('invitations.accept', ['token' => $token]);
    }

    public function revokeInvitation(OrganizationMember $member): void
    {
        UserInvitation::query()
            ->where('email', $member->user->email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Invitación pendiente (no aceptada, no revocada, no vencida) de un correo. */
    public function pendingInvitation(string $email): ?UserInvitation
    {
        return UserInvitation::pending()->where('email', $email)->latest('created_at')->first();
    }

    /**
     * Actualiza nombre, correo, rol, sucursales y perfil de doctor.
     */
    public function update(OrganizationMember $member, array $data, ?UploadedFile $signature, bool $removeSignature, User $actor): OrganizationMember
    {
        $member->loadMissing(['user', 'role']);
        $newRole = Role::findOrFail($data['role_id']);
        $roleChanged = $member->role_id !== $newRole->id;

        if ($roleChanged) {
            if ($member->user_id === $actor->id) {
                throw new AccessException('No puedes cambiar tu propio rol.');
            }
            $this->guardLastAdmin($member, $newRole->is_system_admin);
        }

        try {
            DB::transaction(function () use ($member, $data, $signature, $removeSignature, $actor, $newRole, $roleChanged) {
                $member->user->update([
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                ]);

                if ($roleChanged) {
                    $old = $member->role;
                    $member->update(['role_id' => $newRole->id]);
                    $this->logRoleAssigned($member, $old, $newRole, $actor);
                }

                $this->syncBranches($member, $data['branch_ids']);
                $this->syncDoctorProfile($member, $data, $signature, $removeSignature);
            });
        } catch (QueryException $e) {
            throw AccessException::fromQuery($e) ?? $e;
        }

        return $member->fresh(['user', 'role']);
    }

    /**
     * Activa o desactiva el acceso. Un usuario con invitación pendiente se activa
     * únicamente al aceptarla.
     */
    public function setActive(OrganizationMember $member, bool $active, User $actor): void
    {
        $member->loadMissing(['user', 'role']);

        if (! $active) {
            if ($member->user_id === $actor->id) {
                throw new AccessException('No puedes desactivar tu propia cuenta.');
            }
            $this->guardLastAdmin($member, false);
        }

        if ($active && $this->pendingInvitation($member->user->email)) {
            throw new AccessException('Este usuario aún no acepta su invitación; se activará al aceptarla.');
        }

        if ($active && blank($member->user->password)) {
            throw new AccessException('Este usuario no tiene contraseña definida. Genera un enlace de acceso para que la establezca.');
        }

        try {
            DB::transaction(function () use ($member, $active) {
                $member->update(['is_active' => $active]);
                $member->user->update(['is_active' => $active]);
            });
        } catch (QueryException $e) {
            throw AccessException::fromQuery($e) ?? $e;
        }
    }

    /**
     * Estado visible: activo | inactivo | pendiente.
     */
    public function status(OrganizationMember $member, bool $hasPendingInvitation): string
    {
        if ($member->is_active && $member->user->is_active) {
            return 'activo';
        }

        return $hasPendingInvitation ? 'pendiente' : 'inactivo';
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Impide dejar a la clínica sin administradores activos (el trigger de BD
     * también lo hace; aquí damos un mensaje claro antes de llegar a él).
     */
    public function guardLastAdmin(OrganizationMember $member, bool $newRoleIsAdmin): void
    {
        $isActiveAdmin = $member->is_active
            && $member->role?->is_system_admin
            && $member->role?->status?->value === 'active';

        if (! $isActiveAdmin || $newRoleIsAdmin) {
            return;
        }

        $others = OrganizationMember::query()
            ->where('is_active', true)
            ->where('id', '!=', $member->id)
            ->whereHas('role', fn ($q) => $q->where('is_system_admin', true)->where('status', 'active'))
            ->count();

        if ($others === 0) {
            throw new AccessException('Debe existir al menos un administrador activo en la clínica.');
        }
    }

    public function syncBranches(OrganizationMember $member, array $branchIds): void
    {
        $orgId = Organization::currentId();

        DB::table('member_branches')->where('member_id', $member->id)->delete();

        $rows = array_map(fn ($branchId) => [
            'organization_id' => $orgId,
            'member_id' => $member->id,
            'branch_id' => $branchId,
        ], array_values(array_unique($branchIds)));

        if ($rows) {
            DB::table('member_branches')->insert($rows);
        }
    }

    protected function syncDoctorProfile(OrganizationMember $member, array $data, ?UploadedFile $signature, bool $remove = false): void
    {
        $existing = DB::table('doctor_profiles')->where('member_id', $member->id)->first();

        if (empty($data['is_doctor'])) {
            if ($existing) {
                $this->deleteSignature($existing->signature_image_storage_key);
                DB::table('doctor_profiles')->where('member_id', $member->id)->delete();
            }

            return;
        }

        $key = $existing->signature_image_storage_key ?? null;

        if ($remove && $key) {
            $this->deleteSignature($key);
            $key = null;
        }

        if ($signature) {
            $this->deleteSignature($key);
            $key = $signature->storeAs(
                'signatures',
                $member->id.'-'.Str::random(8).'.'.$signature->extension(),
                'local'
            );
        }

        DB::table('doctor_profiles')->updateOrInsert(
            ['member_id' => $member->id],
            [
                'organization_id' => $member->organization_id,
                'professional_license' => $data['professional_license'] ?? null,
                'specialty' => $data['specialty'] ?? null,
                'signature_image_storage_key' => $key,
            ]
        );
    }

    protected function deleteSignature(?string $key): void
    {
        if ($key && Storage::disk('local')->exists($key)) {
            Storage::disk('local')->delete($key);
        }
    }

    protected function logRoleAssigned(OrganizationMember $member, ?Role $old, ?Role $new, User $actor): void
    {
        RoleChangeHistory::create([
            'change_type' => 'user_role_assigned',
            'target_member_id' => $member->id,
            'role_id' => ($new ?? $old)?->id,
            'old_value' => $old ? ['role_id' => $old->id, 'role' => $old->name] : null,
            'new_value' => $new ? ['role_id' => $new->id, 'role' => $new->name] : null,
            'actor_user_id' => $actor->id,
        ]);
    }

    /** Registra un cambio de rol hecho desde la gestión de roles (reutilizado por RoleService). */
    public function recordRoleAssignment(OrganizationMember $member, ?Role $old, ?Role $new, User $actor): void
    {
        $this->logRoleAssigned($member, $old, $new, $actor);
    }
}
