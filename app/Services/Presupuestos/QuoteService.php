<?php

namespace App\Services\Presupuestos;

use App\Enums\PlannedTreatmentStatus;
use App\Enums\QuoteStatus;
use App\Models\PatientTreatment;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuoteStatusHistory;
use App\Models\Treatment;
use App\Support\Folio;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Casos de uso de presupuestos: crear/editar borradores, transiciones de
 * estado con historial y aprobación (genera el plan de tratamiento).
 */
class QuoteService
{
    public function __construct(private QuoteCalculator $calculator)
    {
    }

    /**
     * Crea un presupuesto en borrador con folio PRE-.
     *
     * @param  array{patient_id: string, doctor_member_id: string, branch_id: string, quote_date: string, items: array<int, array<string, mixed>>}  $data
     */
    public function create(array $data, ?string $actorUserId): Quote
    {
        return DB::transaction(function () use ($data, $actorUserId) {
            $quote = Quote::create([
                'folio' => Folio::next(Folio::QUOTE),
                'patient_id' => $data['patient_id'],
                'doctor_member_id' => $data['doctor_member_id'],
                'branch_id' => $data['branch_id'],
                'quote_date' => $data['quote_date'],
                'status' => QuoteStatus::Borrador,
                'currency' => 'MXN',
                'subtotal' => 0,
                'discount_total' => 0,
                'tax_total' => 0,
                'total' => 0,
            ]);

            $this->syncItems($quote, $data['items']);
            $this->recordHistory($quote, null, QuoteStatus::Borrador, $actorUserId);

            return $quote->refresh();
        });
    }

    /** Actualiza un borrador (reemplaza sus partidas y recalcula totales). */
    public function update(Quote $quote, array $data): Quote
    {
        $this->assertStatus($quote, QuoteStatus::Borrador, 'Solo se puede editar un presupuesto en borrador.');

        return DB::transaction(function () use ($quote, $data) {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quote->id);
            $this->assertStatus($quote, QuoteStatus::Borrador, 'Solo se puede editar un presupuesto en borrador.');

            $quote->fill([
                'patient_id' => $data['patient_id'],
                'doctor_member_id' => $data['doctor_member_id'],
                'branch_id' => $data['branch_id'],
                'quote_date' => $data['quote_date'],
            ])->save();

            $this->syncItems($quote, $data['items']);

            return $quote->refresh();
        });
    }

    public function send(Quote $quote, ?string $actorUserId): Quote
    {
        return $this->transition($quote, QuoteStatus::Enviado, $actorUserId, function (Quote $q) {
            if ($q->items()->count() === 0) {
                throw new RuntimeException('Agrega al menos un concepto antes de enviar el presupuesto.');
            }
            $q->sent_at = now();
        });
    }

    /** Aprobar: marca el presupuesto y crea un PatientTreatment (planeado) por partida con tratamiento. */
    public function approve(Quote $quote, ?string $actorUserId): Quote
    {
        return $this->transition($quote, QuoteStatus::Aprobado, $actorUserId, function (Quote $q) {
            foreach ($q->items()->get() as $item) {
                if (! $item->treatment_id) {
                    continue;
                }

                // Idempotente: una partida genera como máximo un tratamiento del plan.
                $exists = PatientTreatment::query()->where('quote_item_id', $item->id)->exists();
                if ($exists) {
                    continue;
                }

                PatientTreatment::create([
                    'patient_id' => $q->patient_id,
                    'treatment_id' => $item->treatment_id,
                    'quote_item_id' => $item->id,
                    'tooth_fdi' => $item->tooth_fdi,
                    'status' => PlannedTreatmentStatus::Planeado,
                ]);
            }
        });
    }

    public function reject(Quote $quote, ?string $actorUserId): Quote
    {
        return $this->transition($quote, QuoteStatus::Rechazado, $actorUserId);
    }

    /** Enviado -> Borrador (permite corregir un presupuesto aún sin respuesta). */
    public function reopen(Quote $quote, ?string $actorUserId): Quote
    {
        return $this->transition($quote, QuoteStatus::Borrador, $actorUserId, function (Quote $q) {
            $q->sent_at = null;
        });
    }

    /** Elimina un borrador sin facturas (con sus partidas e historial). */
    public function delete(Quote $quote): void
    {
        DB::transaction(function () use ($quote) {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quote->id);
            $this->assertStatus($quote, QuoteStatus::Borrador, 'Solo se puede eliminar un presupuesto en borrador.');

            if ($quote->invoices()->exists()) {
                throw new RuntimeException('El presupuesto tiene facturas asociadas y no se puede eliminar.');
            }

            QuoteItem::query()->where('quote_id', $quote->id)->delete();
            QuoteStatusHistory::query()->where('quote_id', $quote->id)->delete();
            $quote->delete();
        });
    }

    // ---------------------------------------------------------------- privado

    private function transition(Quote $quote, QuoteStatus $to, ?string $actorUserId, ?callable $apply = null): Quote
    {
        return DB::transaction(function () use ($quote, $to, $actorUserId, $apply) {
            $quote = Quote::query()->lockForUpdate()->findOrFail($quote->id);
            $from = $quote->status;

            if (! in_array($to, $from->allowedTransitions(), true)) {
                throw new RuntimeException("No se puede pasar el presupuesto de «{$from->label()}» a «{$to->label()}».");
            }

            if ($apply) {
                $apply($quote);
            }

            $quote->status = $to;
            $quote->save();

            $this->recordHistory($quote, $from, $to, $actorUserId);

            return $quote->refresh();
        });
    }

    private function recordHistory(Quote $quote, ?QuoteStatus $from, QuoteStatus $to, ?string $actorUserId): void
    {
        QuoteStatusHistory::create([
            'quote_id' => $quote->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actorUserId,
        ]);
    }

    private function assertStatus(Quote $quote, QuoteStatus $expected, string $message): void
    {
        if ($quote->status !== $expected) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Reemplaza las partidas con snapshots del tratamiento (nombre, clave SAT,
     * tasa de impuesto vigente) y recalcula los totales.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(Quote $quote, array $items): void
    {
        QuoteItem::query()->where('quote_id', $quote->id)->delete();

        $treatments = Treatment::with('taxProfile')
            ->whereIn('id', collect($items)->pluck('treatment_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $computed = [];
        $line = 1;

        foreach (array_values($items) as $row) {
            /** @var Treatment $treatment */
            $treatment = $treatments->get($row['treatment_id']);
            $profile = $treatment->taxProfile;
            $exempt = $profile ? (bool) $profile->is_exempt : true;
            $rate = ($profile && ! $exempt) ? (string) $profile->rate : '0';

            $amounts = $this->calculator->line($row['quantity'], $row['unit_price'], $row['discount_pct'] ?? 0, $rate, $exempt);

            QuoteItem::create([
                'quote_id' => $quote->id,
                'line_number' => $line++,
                'treatment_id' => $treatment->id,
                'description_snapshot' => $treatment->name,
                'sat_product_key_snapshot' => $treatment->sat_product_key,
                'tooth_fdi' => ($row['tooth_fdi'] ?? '') !== '' ? (int) $row['tooth_fdi'] : null,
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'discount_pct' => $row['discount_pct'] ?? 0,
                'tax_rate_snapshot' => $rate,
                'tax_is_exempt' => $exempt,
                ...$amounts,
            ]);

            $computed[] = $amounts;
        }

        $quote->fill($this->calculator->totals($computed))->save();
    }
}
