<?php

namespace App\Http\Controllers\Inventario;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventario\SupplierRequest;
use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Rules\MxPhone;
use Illuminate\Http\Request;

/** Proveedores de insumos: alta, edicion, activar/desactivar y baja (solo si no tienen insumos). */
class ProveedorController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'inactive', 'all'], true) ? $request->query('status') : 'active';

        $suppliers = Supplier::query()
            ->withCount('items')
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
                $w->where(fn ($s) => $s->where('name', 'like', $like)->orWhere('contact_email', 'like', $like)->orWhere('contact_phone', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $user = $request->user();

        return view('inventario.proveedores', [
            'suppliers' => $suppliers,
            'q' => $q,
            'status' => $status,
            'can' => [
                'create' => $user->hasPermission('inventario', 'crear'),
                'edit' => $user->hasPermission('inventario', 'editar'),
                'toggle' => $user->hasPermission('inventario', 'desactivar'),
            ],
        ]);
    }

    public function store(SupplierRequest $request)
    {
        $supplier = Supplier::create($this->payload($request->validated()) + ['status' => RecordStatus::Active->value]);

        return redirect()->route('inventario.proveedores.index')
            ->with('success', 'Proveedor «' . $supplier->name . '» registrado.');
    }

    public function update(SupplierRequest $request, Supplier $supplier)
    {
        $supplier->update($this->payload($request->validated()));

        return redirect()->route('inventario.proveedores.index')
            ->with('success', 'Proveedor actualizado.');
    }

    public function toggle(Supplier $supplier)
    {
        $supplier->status = $supplier->status === RecordStatus::Active ? RecordStatus::Inactive : RecordStatus::Active;
        $supplier->save();

        return back()->with('success', 'Proveedor «' . $supplier->name . '» ' . ($supplier->status === RecordStatus::Active ? 'activado' : 'desactivado') . '.');
    }

    public function destroy(Supplier $supplier)
    {
        if (InventoryItem::query()->where('supplier_id', $supplier->id)->exists()) {
            return back()->with('error', 'El proveedor tiene insumos asignados; desactívelo en lugar de eliminarlo.');
        }

        $name = $supplier->name;
        $supplier->delete();

        return redirect()->route('inventario.proveedores.index')
            ->with('success', 'Proveedor «' . $name . '» eliminado.');
    }

    private function payload(array $d): array
    {
        return [
            'name' => $d['name'],
            'contact_phone' => filled($d['contact_phone'] ?? null) ? MxPhone::normalize($d['contact_phone']) : null,
            'contact_email' => filled($d['contact_email'] ?? null) ? mb_strtolower($d['contact_email']) : null,
        ];
    }
}
