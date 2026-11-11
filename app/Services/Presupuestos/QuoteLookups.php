<?php

namespace App\Services\Presupuestos;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\OrganizationMember;
use App\Models\Patient;
use App\Models\Treatment;
use Illuminate\Support\Collection;

/**
 * Catálogos de apoyo para los formularios de presupuestos.
 */
class QuoteLookups
{
    /** Doctores activos (rol doctor o perfil de doctor): id => nombre. */
    public function doctors(): Collection
    {
        return OrganizationMember::active()
            ->with(['user', 'role', 'doctorProfile'])
            ->get()
            ->filter(fn (OrganizationMember $m) => $m->isDoctor() && $m->user?->is_active)
            ->sortBy(fn (OrganizationMember $m) => $m->name)
            ->mapWithKeys(fn (OrganizationMember $m) => [$m->id => $m->name]);
    }

    /** Sucursales activas: id => nombre. */
    public function branches(): Collection
    {
        return Branch::query()
            ->where('status', RecordStatus::Active->value)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    /** Tratamientos activos con los datos que necesita el formulario (precio, impuesto). */
    public function treatments(): Collection
    {
        return Treatment::active()
            ->with(['taxProfile', 'category'])
            ->orderBy('name')
            ->get();
    }

    /** Búsqueda de pacientes activos (no dados de baja ni anonimizados). */
    public function searchPatients(?string $term, int $limit = 15): Collection
    {
        return Patient::query()
            ->where('status', RecordStatus::Active->value)
            ->whereNull('deleted_at')
            ->whereNull('anonymized_at')
            ->search($term)
            ->orderBy('full_name')
            ->limit($limit)
            ->get(['id', 'folio', 'full_name', 'phone']);
    }
}
