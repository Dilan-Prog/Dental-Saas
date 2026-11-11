<?php

namespace App\Http\Controllers\Usuarios;

use App\Http\Controllers\Controller;
use App\Http\Requests\Usuarios\StoreUsuarioRequest;
use App\Http\Requests\Usuarios\UpdateUsuarioRequest;
use App\Models\Branch;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Usuarios\AccessException;
use App\Services\Usuarios\UserAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsuarioController extends Controller
{
    public function __construct(private readonly UserAccessService $service)
    {
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'uuid'],
            'branch' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:activo,inactivo,pendiente'],
        ]);

        $pendingEmails = UserInvitation::pending()->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();

        $members = OrganizationMember::query()
            ->with(['user', 'role', 'branches', 'doctorProfile'])
            ->when($filters['q'] ?? null, function ($query, $q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->whereHas('user', fn ($u) => $u->where('full_name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role_id', $role))
            ->when($filters['branch'] ?? null, fn ($query, $branch) => $query->whereHas('branches', fn ($b) => $b->where('branches.id', $branch)))
            ->when($filters['status'] ?? null, function ($query, $status) use ($pendingEmails) {
                match ($status) {
                    'activo' => $query->where('is_active', true)->whereHas('user', fn ($u) => $u->where('is_active', true)),
                    'pendiente' => $query->where('is_active', false)->whereHas('user', fn ($u) => $u->whereIn('email', $pendingEmails ?: [''])),
                    'inactivo' => $query->where(function ($q) use ($pendingEmails) {
                        $q->where('is_active', false)
                            ->orWhereHas('user', fn ($u) => $u->where('is_active', false));
                    })->whereDoesntHave('user', fn ($u) => $u->whereIn('email', $pendingEmails ?: [''])),
                };
            })
            ->orderBy(User::query()->select('full_name')->whereColumn('users.id', 'organization_members.user_id'))
            ->paginate(15)
            ->withQueryString();

        $statuses = [];
        foreach ($members as $member) {
            $statuses[$member->id] = $this->service->status($member, in_array(mb_strtolower($member->user->email), $pendingEmails, true));
        }

        return view('usuarios.index', [
            'members' => $members,
            'statuses' => $statuses,
            'roles' => Role::query()->orderBy('name')->get(),
            'branches' => Branch::query()->where('status', 'active')->orderBy('name')->get(),
            'filters' => $filters,
            'totalMembers' => OrganizationMember::query()->count(),
        ]);
    }

    public function store(StoreUsuarioRequest $request): RedirectResponse
    {
        try {
            [$member, $url] = $this->service->create(
                $request->safe()->except('signature'),
                $request->file('signature'),
                $request->user()
            );
        } catch (AccessException $e) {
            return back()->withInput()->withErrors(['email' => $e->getMessage()], 'invite');
        }

        return redirect()->route('usuarios.index')
            ->with('success', "Se creó la invitación para {$member->user->full_name}.")
            ->with('access_link', $this->linkPayload($member, $url));
    }

    public function edit(OrganizationMember $member, Request $request): View
    {
        $member->load(['user', 'role', 'branches', 'doctorProfile']);
        $pending = $this->service->pendingInvitation($member->user->email);

        return view('usuarios.edit', [
            'member' => $member,
            'status' => $this->service->status($member, (bool) $pending),
            'pending' => $pending,
            'roles' => Role::query()->active()->orderBy('name')->get(),
            'branches' => Branch::query()->where('status', 'active')->orderBy('name')->get(),
            'isSelf' => $member->user_id === $request->user()->id,
        ]);
    }

    public function update(UpdateUsuarioRequest $request, OrganizationMember $member): RedirectResponse
    {
        try {
            $this->service->update(
                $member,
                $request->safe()->except(['signature', 'remove_signature']),
                $request->file('signature'),
                $request->boolean('remove_signature'),
                $request->user()
            );
        } catch (AccessException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('usuarios.index')->with('success', 'Usuario actualizado correctamente.');
    }

    /** Activar / desactivar acceso. */
    public function estado(Request $request, OrganizationMember $member): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        try {
            $this->service->setActive($member, (bool) $data['active'], $request->user());
        } catch (AccessException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['active'] ? 'Usuario activado.' : 'Usuario desactivado. Ya no podrá iniciar sesión.');
    }

    /** Genera un nuevo enlace de acceso (reenviar invitación o restablecer acceso). */
    public function acceso(Request $request, OrganizationMember $member): RedirectResponse
    {
        $member->load('user');

        if ($member->user_id === $request->user()->id) {
            return back()->with('error', 'Para cambiar tu contraseña usa tu perfil.');
        }

        $url = $this->service->issueInvitation($member, $request->user());

        return redirect()->route('usuarios.index')
            ->with('success', 'Se generó un nuevo enlace de acceso. El anterior dejó de funcionar.')
            ->with('access_link', $this->linkPayload($member, $url));
    }

    public function revocar(OrganizationMember $member): RedirectResponse
    {
        $member->load('user');
        $this->service->revokeInvitation($member);

        return back()->with('success', 'La invitación fue revocada.');
    }

    /** Imagen de firma (almacenamiento privado, solo para quien puede ver usuarios). */
    public function firma(OrganizationMember $member): StreamedResponse
    {
        $key = $member->doctorProfile?->signature_image_storage_key;
        abort_unless($key && Storage::disk('local')->exists($key), 404);

        return Storage::disk('local')->response($key, null, ['Cache-Control' => 'private, max-age=300']);
    }

    private function linkPayload(OrganizationMember $member, string $url): array
    {
        return [
            'name' => $member->user->full_name,
            'email' => $member->user->email,
            'url' => $url,
            'days' => UserAccessService::INVITATION_DAYS,
        ];
    }
}
