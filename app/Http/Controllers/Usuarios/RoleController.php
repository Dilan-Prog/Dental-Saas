<?php

namespace App\Http\Controllers\Usuarios;

use App\Http\Controllers\Controller;
use App\Http\Requests\Usuarios\AssignRoleUsersRequest;
use App\Http\Requests\Usuarios\DeactivateRoleRequest;
use App\Http\Requests\Usuarios\StoreRoleRequest;
use App\Http\Requests\Usuarios\UpdateRoleRequest;
use App\Http\Requests\Usuarios\UpdateRolePermissionsRequest;
use App\Models\OrganizationMember;
use App\Models\Role;
use App\Models\RoleChangeHistory;
use App\Services\Usuarios\AccessException;
use App\Services\Usuarios\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $service)
    {
    }

    public function index(): View
    {
        $roles = Role::query()
            ->withCount('members')
            ->orderByDesc('is_system_admin')
            ->orderBy('name')
            ->get();

        return view('roles.index', ['roles' => $roles]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = $this->service->create($request->validated('name'), $request->validated('description'), $request->user());

        return redirect()->route('roles.show', $role)
            ->with('success', 'Rol creado. Ahora asigna sus permisos.');
    }

    public function show(Role $role): View
    {
        $role->loadCount('members');
        $members = $role->members()->with('user')->get()->sortBy(fn ($m) => $m->user->full_name)->values();

        $history = RoleChangeHistory::query()
            ->with(['actor', 'targetMember.user'])
            ->where(fn ($q) => $q->where('role_id', $role->id)->orWhere('old_value->role_id', $role->id))
            ->latest('created_at')
            ->limit(50)
            ->get();

        $granted = array_flip($this->service->grantedKeys($role));
        $matrix = $this->service->matrix();

        $modulesWithAccess = collect($matrix)
            ->filter(fn ($row) => isset($granted[$row['module'].'.ver']))
            ->count();

        $assignable = OrganizationMember::query()
            ->with(['user', 'role'])
            ->where(fn ($q) => $q->whereNull('role_id')->orWhere('role_id', '!=', $role->id))
            ->get()
            ->sortBy(fn ($m) => $m->user->full_name)
            ->values();

        $others = Role::query()->active()->where('id', '!=', $role->id)->orderBy('name')->get();

        return view('roles.show', [
            'role' => $role,
            'members' => $members,
            'history' => $history,
            'matrix' => $matrix,
            'granted' => $granted,
            'modulesWithAccess' => $modulesWithAccess,
            'menuPreview' => $this->service->menuPreview($role),
            'assignable' => $assignable,
            'otherRoles' => $others,
            'lastChange' => $history->first(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        try {
            $this->service->update($role, $request->validated('name'), $request->validated('description'), $request->user());
        } catch (AccessException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Rol actualizado.');
    }

    public function permisos(UpdateRolePermissionsRequest $request, Role $role): RedirectResponse
    {
        try {
            $changed = $this->service->syncPermissions($role, $request->validated('permissions') ?? [], $request->user());
        } catch (AccessException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('roles.show', $role)->with(
            $changed ? 'success' : 'info',
            $changed ? 'Permisos actualizados. Aplican de inmediato a los usuarios de este rol.' : 'No hubo cambios en los permisos.'
        );
    }

    public function estado(DeactivateRoleRequest $request, Role $role): RedirectResponse
    {
        try {
            if ($request->boolean('active')) {
                $this->service->activate($role, $request->user());
                $message = 'Rol activado.';
            } else {
                $moved = $this->service->deactivate($role, $request->validated('replacement_role_id'), $request->user());
                $message = $moved > 0
                    ? "Rol desactivado. {$moved} usuario(s) pasaron al rol elegido."
                    : 'Rol desactivado. Se puede reactivar cuando quieras.';
            }
        } catch (AccessException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('roles.index')->with('success', $message);
    }

    public function asignar(AssignRoleUsersRequest $request, Role $role): RedirectResponse
    {
        try {
            $moved = $this->service->assignUsers($role, $request->validated('member_ids'), $request->user());
        } catch (AccessException $e) {
            return redirect()->to(route('roles.show', $role).'#usuarios')->with('error', $e->getMessage());
        }

        return redirect()->to(route('roles.show', $role).'#usuarios')
            ->with('success', $moved > 0 ? "{$moved} usuario(s) asignados a {$role->name}." : 'No hubo cambios.');
    }

    public function quitar(Request $request, Role $role, OrganizationMember $member): RedirectResponse
    {
        try {
            $this->service->removeUser($role, $member, $request->user());
        } catch (AccessException $e) {
            return redirect()->to(route('roles.show', $role).'#usuarios')->with('error', $e->getMessage());
        }

        return redirect()->to(route('roles.show', $role).'#usuarios')
            ->with('success', 'Usuario quitado del rol. Sin rol no tiene acceso a ningún módulo.');
    }
}
