<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\SucursalRequest;
use App\Models\Branch;
use App\Models\OrganizationMember;
use App\Services\Configuracion\Auditor;
use App\Services\Configuracion\ConfiguracionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sucursales: gestión exclusiva del Owner (rol administrador del sistema).
 * No hay borrado físico: una sucursal tiene citas, caja e inventario
 * asociados, por lo que solo se desactiva.
 */
class SucursalesController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, \Closure $next) {
            abort_unless($request->user()?->isAdmin(), 403, 'Solo el propietario puede administrar las sucursales.');

            return $next($request);
        });
    }

    public function index(): View
    {
        $branches = Branch::query()->with('manager')->orderByDesc('is_main')->orderBy('name')->get();

        // Doctores activos asignados a cada sucursal.
        $doctors = DB::table('member_branches as mb')
            ->join('organization_members as om', 'om.id', '=', 'mb.member_id')
            ->join('roles as r', 'r.id', '=', 'om.role_id')
            ->where('om.organization_id', \App\Models\Organization::currentId())
            ->where('om.is_active', 1)
            ->where('r.code', 'doctor')
            ->groupBy('mb.branch_id')
            ->pluck(DB::raw('COUNT(*)'), 'mb.branch_id');

        $managers = OrganizationMember::query()->with('user')->where('is_active', 1)->get()
            ->filter(fn ($m) => $m->user && $m->user->is_active)
            ->mapWithKeys(fn ($m) => [$m->user_id => $m->user->full_name])
            ->sort();

        return view('configuracion.sucursales', [
            'section' => 'sucursales',
            'branches' => $branches,
            'doctors' => $doctors,
            'managers' => $managers,
            'formatPhone' => fn ($p) => ConfiguracionCatalog::formatPhone($p),
        ]);
    }

    public function store(SucursalRequest $request): RedirectResponse
    {
        $data = $request->safe()->only(['name', 'address_line', 'phone', 'manager_user_id']);

        $branch = DB::transaction(function () use ($request, $data) {
            if ($request->boolean('is_main')) {
                $this->demoteCurrentMain();
            }

            $branch = Branch::query()->create($data + [
                'status' => 'active',
                'is_main' => $request->boolean('is_main'),
            ]);

            Auditor::log('branch', $branch->id, 'insert', array_keys($data));

            return $branch;
        });

        return redirect()->route('configuracion.sucursales')->with('success', "La sucursal «{$branch->name}» se creó correctamente.");
    }

    public function update(SucursalRequest $request, Branch $branch): RedirectResponse
    {
        $wantsMain = $request->boolean('is_main');

        if ($branch->is_main && ! $wantsMain) {
            throw ValidationException::withMessages([
                'is_main' => 'Para quitar la marca de principal, designa primero otra sucursal como principal.',
            ])->errorBag('default');
        }

        if ($wantsMain && ! $branch->is_main && $branch->status->value !== 'active') {
            throw ValidationException::withMessages([
                'is_main' => 'Una sucursal inactiva no puede ser la principal.',
            ]);
        }

        DB::transaction(function () use ($request, $branch, $wantsMain) {
            $branch->fill($request->safe()->only(['name', 'address_line', 'phone', 'manager_user_id']));

            if ($wantsMain && ! $branch->is_main) {
                $this->demoteCurrentMain();
                $branch->is_main = true;
            }

            $changed = array_keys($branch->getDirty());
            $branch->save();

            if ($changed) {
                Auditor::log('branch', $branch->id, 'update', $changed);
            }
        });

        return redirect()->route('configuracion.sucursales')->with('success', "La sucursal «{$branch->name}» se actualizó correctamente.");
    }

    /** Activa o desactiva una sucursal. */
    public function status(Request $request, Branch $branch): RedirectResponse
    {
        $status = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ], [
            'status.required' => 'Indica el estado de la sucursal.',
            'status.in' => 'El estado de la sucursal no es válido.',
        ])['status'];

        if ($status === 'inactive') {
            if ($branch->is_main) {
                throw ValidationException::withMessages([
                    'status' => 'No se puede desactivar la sucursal principal. Designa otra como principal primero.',
                ]);
            }

            $upcoming = $branch->appointments()
                ->whereIn('status', ['pendiente', 'confirmada', 'en_curso'])
                ->where('ends_at', '>=', now())
                ->count();

            if ($upcoming > 0) {
                throw ValidationException::withMessages([
                    'status' => "No se puede desactivar «{$branch->name}»: tiene {$upcoming} cita(s) próxima(s) o en curso. Reprográmalas o cancélalas primero.",
                ]);
            }
        }

        if ($branch->status->value !== $status) {
            DB::transaction(function () use ($branch, $status) {
                $branch->status = $status;
                $branch->save();
                Auditor::log('branch', $branch->id, 'update', ['status']);
            });
        }

        return redirect()->route('configuracion.sucursales')->with('success', $status === 'active'
            ? "La sucursal «{$branch->name}» se activó."
            : "La sucursal «{$branch->name}» se desactivó.");
    }

    /** Designa la sucursal principal. */
    public function main(Branch $branch): RedirectResponse
    {
        if ($branch->status->value !== 'active') {
            throw ValidationException::withMessages(['status' => 'Solo una sucursal activa puede ser la principal.']);
        }

        if (! $branch->is_main) {
            DB::transaction(function () use ($branch) {
                $this->demoteCurrentMain();
                $branch->is_main = true;
                $branch->save();
                Auditor::log('branch', $branch->id, 'update', ['is_main']);
            });
        }

        return redirect()->route('configuracion.sucursales')->with('success', "«{$branch->name}» es ahora la sucursal principal.");
    }

    /** El índice único de sucursal principal obliga a quitar la marca anterior antes de asignar la nueva. */
    private function demoteCurrentMain(): void
    {
        Branch::query()->where('is_main', true)->update(['is_main' => false]);
    }
}
