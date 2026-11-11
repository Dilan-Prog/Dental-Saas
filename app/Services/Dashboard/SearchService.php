<?php

namespace App\Services\Dashboard;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * Buscador global del topbar. Solo busca en los modulos que el usuario
 * puede ver y tolera que la ruta de detalle de un modulo aun no exista.
 */
class SearchService
{
    public const MIN_LENGTH = 2;

    public function __construct(private User $user, private int $limit = 6)
    {
    }

    /**
     * @return array<string, array{title:string,items:array<int,array{label:string,sub:?string,url:?string}>}>
     */
    public function search(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }
        // Escapa comodines de LIKE para que el texto se busque literal.
        $like = addcslashes($term, '\\%_');

        $groups = [];

        if ($this->user->hasPermission('pacientes', 'ver')) {
            $items = Patient::query()->search($term)->orderBy('full_name')->limit($this->limit)->get()
                ->map(fn (Patient $p) => [
                    'label' => $p->full_name,
                    'sub' => trim($p->folio.' · '.($p->phone ?: ($p->email ?: '')), ' ·'),
                    'url' => $this->url('pacientes.show', $p),
                ])->all();
            $groups['pacientes'] = ['title' => 'Pacientes', 'items' => $items];
        }

        if ($this->user->hasPermission('agenda', 'ver')) {
            $items = Appointment::query()
                ->whereHas('patient', fn (Builder $q) => $q->search($term))
                ->with(['patient:id,full_name', 'treatment:id,name'])
                ->orderByDesc('starts_at')->limit($this->limit)->get()
                ->map(fn (Appointment $a) => [
                    'label' => ($a->patient?->full_name ?? 'Paciente').($a->treatment ? ' · '.$a->treatment->name : ''),
                    'sub' => ucfirst($a->starts_at->translatedFormat('D j M Y, H:i')),
                    'url' => $this->url('agenda.index', ['date' => $a->starts_at->toDateString()]),
                ])->all();
            $groups['citas'] = ['title' => 'Citas', 'items' => $items];
        }

        if ($this->user->hasPermission('facturacion', 'ver')) {
            $items = Invoice::query()
                ->where(fn (Builder $w) => $w
                    ->where('internal_folio', 'like', '%'.$like.'%')
                    ->orWhereHas('patient', fn (Builder $q) => $q->search($term)))
                ->with('patient:id,full_name')
                ->orderByDesc('created_at')->limit($this->limit)->get()
                ->map(fn (Invoice $i) => [
                    'label' => $i->internal_folio.' · '.($i->patient?->full_name ?? 'Paciente'),
                    'sub' => \App\Support\Money::format($i->total),
                    'url' => $this->url('facturacion.show', $i),
                ])->all();
            $groups['facturas'] = ['title' => 'Facturas', 'items' => $items];
        }

        if ($this->user->hasPermission('recetas', 'ver')) {
            $items = Prescription::query()
                ->where(fn (Builder $w) => $w
                    ->where('folio', 'like', '%'.$like.'%')
                    ->orWhereHas('patient', fn (Builder $q) => $q->search($term)))
                ->with('patient:id,full_name')
                ->orderByDesc('issued_on')->limit($this->limit)->get()
                ->map(fn (Prescription $r) => [
                    'label' => $r->folio.' · '.($r->patient?->full_name ?? 'Paciente'),
                    'sub' => $r->issued_on?->translatedFormat('j M Y'),
                    'url' => $this->url('recetas.show', $r),
                ])->all();
            $groups['recetas'] = ['title' => 'Recetas', 'items' => $items];
        }

        if ($this->user->hasPermission('presupuestos', 'ver')) {
            $items = Quote::query()
                ->where(fn (Builder $w) => $w
                    ->where('folio', 'like', '%'.$like.'%')
                    ->orWhereHas('patient', fn (Builder $q) => $q->search($term)))
                ->with('patient:id,full_name')
                ->orderByDesc('quote_date')->limit($this->limit)->get()
                ->map(fn (Quote $q) => [
                    'label' => $q->folio.' · '.($q->patient?->full_name ?? 'Paciente'),
                    'sub' => \App\Support\Money::format($q->total),
                    'url' => $this->url('presupuestos.show', $q),
                ])->all();
            $groups['presupuestos'] = ['title' => 'Presupuestos', 'items' => $items];
        }

        return $groups;
    }

    private function url(string $route, mixed $params = []): ?string
    {
        return Route::has($route) ? route($route, $params) : null;
    }
}
