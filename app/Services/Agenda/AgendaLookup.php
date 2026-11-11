<?php

namespace App\Services\Agenda;

use App\Models\Branch;
use App\Models\OrganizationMember;
use App\Models\Patient;
use App\Models\Treatment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Consultas de catálogos que usa la agenda: doctores, sucursales,
 * tratamientos y pacientes.
 */
class AgendaLookup
{
    /** Doctores activos (rol doctor o con perfil de doctor), opcionalmente de una sucursal. */
    public function doctorsQuery(?string $branchId = null): Builder
    {
        return OrganizationMember::query()
            ->where('is_active', true)
            ->whereHas('user', fn ($u) => $u->where('is_active', true))
            ->where(function (Builder $q) {
                $q->whereHas('doctorProfile')
                    ->orWhereHas('role', fn ($r) => $r->where('code', 'doctor'));
            })
            ->when($branchId, fn (Builder $q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->with(['user', 'doctorProfile']);
    }

    /** @return Collection<int, OrganizationMember> */
    public function doctors(?string $branchId = null): Collection
    {
        return $this->doctorsQuery($branchId)->get()
            ->sortBy(fn (OrganizationMember $m) => mb_strtolower((string) $m->name))
            ->values();
    }

    /** Un doctor válido (activo, rol doctor) y, si se indica, asignado a la sucursal. */
    public function doctorServesBranch(string $memberId, string $branchId): ?OrganizationMember
    {
        return $this->doctorsQuery($branchId)->whereKey($memberId)->first();
    }

    public function doctorExists(string $memberId): ?OrganizationMember
    {
        return $this->doctorsQuery()->whereKey($memberId)->first();
    }

    /** @return Collection<int, Branch> */
    public function branches(): Collection
    {
        return Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get();
    }

    public function mainBranch(): ?Branch
    {
        return Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->first();
    }

    /** @return Collection<int, Treatment> */
    public function treatments(): Collection
    {
        return Treatment::query()->active()->orderBy('name')->get(['id', 'name', 'duration_minutes']);
    }

    /** Búsqueda de pacientes activos por nombre, folio, teléfono o correo. */
    public function searchPatients(?string $term, int $limit = 10): Collection
    {
        return Patient::query()->active()->search($term)
            ->orderBy('full_name')->limit($limit)
            ->get(['id', 'folio', 'full_name', 'phone']);
    }
}
