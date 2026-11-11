<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\PasswordRequest;
use App\Http\Requests\Configuracion\PerfilRequest;
use App\Models\DoctorProfile;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Services\Configuracion\Auditor;
use App\Services\Configuracion\StoredImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Mi perfil: disponible para cualquier usuario autenticado. */
class PerfilController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $member = $this->member($request);

        return view('configuracion.perfil', [
            'section' => 'perfil',
            'user' => $user,
            'member' => $member,
            'roleName' => $member?->role?->name,
            'isDoctor' => (bool) $member?->isDoctor(),
            'doctor' => $member?->doctorProfile,
        ]);
    }

    public function update(PerfilRequest $request): RedirectResponse
    {
        $user = $request->user();
        $member = $this->member($request);
        $isDoctor = (bool) $member?->isDoctor();
        $newFiles = [];
        $oldFiles = [];

        try {
            DB::transaction(function () use ($request, $user, $member, $isDoctor, &$newFiles, &$oldFiles) {
                $user->fill($request->safe()->only(['full_name', 'email']));

                if ($request->hasFile('avatar')) {
                    $oldFiles[] = $user->avatar_storage_key;
                    $newFiles[] = $user->avatar_storage_key = StoredImage::store($request->file('avatar'), 'avatars');
                } elseif ($request->boolean('remove_avatar')) {
                    $oldFiles[] = $user->avatar_storage_key;
                    $user->avatar_storage_key = null;
                }

                $changed = array_keys($user->getDirty());
                $user->save();

                if ($changed) {
                    Auditor::log('user', $user->id, 'update', $changed);
                }

                if ($isDoctor && $member) {
                    $profile = DoctorProfile::query()->firstOrNew(['member_id' => $member->id]);
                    $profile->organization_id = Organization::currentId();
                    $profile->professional_license = $request->input('professional_license');
                    $profile->specialty = $request->input('specialty');

                    if ($request->hasFile('signature')) {
                        $oldFiles[] = $profile->signature_image_storage_key;
                        $newFiles[] = $profile->signature_image_storage_key = StoredImage::store($request->file('signature'), 'firmas');
                    } elseif ($request->boolean('remove_signature')) {
                        $oldFiles[] = $profile->signature_image_storage_key;
                        $profile->signature_image_storage_key = null;
                    }

                    $changedProfile = array_keys($profile->getDirty());
                    $profile->save();

                    if ($changedProfile) {
                        Auditor::log('doctor_profile', $member->id, 'update', $changedProfile);
                    }
                }
            });
        } catch (\Throwable $e) {
            foreach ($newFiles as $key) {
                StoredImage::delete($key);
            }
            throw $e;
        }

        foreach ($oldFiles as $key) {
            StoredImage::delete($key);
        }

        return redirect()->route('configuracion.perfil')->with('status', 'profile-updated');
    }

    public function password(PasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->password = $request->validated('password');
        $user->save();

        Auditor::log('user', $user->id, 'update', ['password']);

        return redirect()->route('configuracion.perfil')->with('status', 'password-updated');
    }

    public function avatar(Request $request): StreamedResponse
    {
        return StoredImage::response($request->user()->avatar_storage_key);
    }

    public function firma(Request $request): StreamedResponse
    {
        return StoredImage::response($this->member($request)?->doctorProfile?->signature_image_storage_key);
    }

    private function member(Request $request): ?OrganizationMember
    {
        return OrganizationMember::query()
            ->with(['role', 'doctorProfile'])
            ->where('user_id', $request->user()->id)
            ->first();
    }
}
