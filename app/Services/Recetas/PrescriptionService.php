<?php

namespace App\Services\Recetas;

use App\Enums\OutboxStatus;
use App\Enums\RecordStatus;
use App\Enums\SigningStatus;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionDelivery;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Support\Folio;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de las recetas médicas.
 *
 *  - save():    crea/actualiza un BORRADOR (con folio RX- desde la creación).
 *  - sign():    EMITE la receta (status firmada): solo el doctor que prescribe,
 *               con cédula, diagnóstico y al menos un medicamento. Fija snapshots,
 *               fecha de firma y hash SHA-256 del contenido. Después es inmutable
 *               (la BD lo garantiza con triggers).
 *  - repeat():  crea una receta NUEVA (borrador) copiando los medicamentos.
 *  - send():    registra la entrega (PrescriptionDelivery) y el evento de outbox
 *               en la misma transacción.
 *
 * Los errores de negocio se lanzan como DomainException con mensaje en español.
 */
class PrescriptionService
{
    public const CHANNELS = ['impresion', 'email', 'whatsapp'];

    /**
     * @param  array{patient_id:string,doctor_member_id:string,issued_on:string,diagnosis?:?string,general_instructions?:?string,items:array<int,array<string,mixed>>}  $data
     */
    public function save(?Prescription $rx, array $data): Prescription
    {
        return DB::transaction(function () use ($rx, $data) {
            if ($rx !== null) {
                $rx = Prescription::query()->whereKey($rx->id)->lockForUpdate()->firstOrFail();
                if ($rx->isSigned()) {
                    throw new DomainException('La receta ya fue emitida y no puede modificarse. Use "Repetir receta" para crear una nueva.');
                }
            } else {
                $rx = new Prescription();
                $rx->folio = Folio::next(Folio::PRESCRIPTION);
                $rx->status = SigningStatus::Borrador;
            }

            $rx->patient_id = $data['patient_id'];
            $rx->doctor_member_id = $data['doctor_member_id'];
            $rx->issued_on = $data['issued_on'];
            $rx->diagnosis = $this->blankToNull($data['diagnosis'] ?? null);
            $rx->general_instructions = $this->blankToNull($data['general_instructions'] ?? null);
            $rx->save();

            $this->replaceItems($rx, $data['items']);

            return $rx->load('items');
        });
    }

    /** Emite (firma) un borrador. $actor debe ser el doctor que prescribe. */
    public function sign(Prescription $rx, User $actor): Prescription
    {
        return DB::transaction(function () use ($rx, $actor) {
            $rx = Prescription::query()->whereKey($rx->id)->lockForUpdate()->firstOrFail();

            if ($rx->isSigned()) {
                throw new DomainException('La receta ya fue emitida.');
            }

            $member = $actor->member;
            if (! $member || $member->id !== $rx->doctor_member_id) {
                throw new DomainException('Solo el doctor que prescribe puede firmar y emitir la receta.');
            }
            if (! $actor->hasPermission('recetas', 'emitir_recetas')) {
                throw new DomainException('No tiene permiso para emitir recetas.');
            }

            $this->assertReadyToSign($rx);

            return $this->applySignature($rx);
        });
    }

    /**
     * Aplica la firma sin comprobar al actor (la usan sign() y los seeders).
     * Debe llamarse dentro de una transacción y con el borrador ya validado.
     */
    public function applySignature(Prescription $rx): Prescription
    {
        $rx->loadMissing('doctor.user', 'doctor.doctorProfile', 'items');

        $rx->doctor_name_snapshot = $rx->doctor->user->full_name;
        $rx->doctor_license_snapshot = $rx->doctor->doctorProfile->professional_license;
        $rx->signed_at = now();
        $rx->content_hash = $this->contentHash($rx);
        $rx->status = SigningStatus::Firmada;
        $rx->save();

        return $rx->refresh()->load('items');
    }

    /** Valida que un borrador pueda emitirse. */
    public function assertReadyToSign(Prescription $rx): void
    {
        $rx->loadMissing('doctor.user', 'doctor.doctorProfile', 'items', 'patient');

        if ($rx->items->isEmpty()) {
            throw new DomainException('Agregue al menos un medicamento antes de emitir la receta.');
        }
        foreach ($rx->items as $item) {
            if (blank($item->dose) || blank($item->frequency)) {
                throw new DomainException("El medicamento {$item->line_number} requiere dosis y frecuencia.");
            }
        }
        if (blank($rx->diagnosis)) {
            throw new DomainException('El diagnóstico es obligatorio para emitir la receta.');
        }
        if ($rx->issued_on->isFuture()) {
            throw new DomainException('La fecha de la receta no puede ser futura.');
        }
        $patient = $rx->patient;
        if (! $patient || $patient->status !== RecordStatus::Active || $patient->anonymized_at !== null) {
            throw new DomainException('El paciente no está activo.');
        }
        if (! $rx->doctor || ! $rx->doctor->is_active || ! $rx->doctor->doctorProfile) {
            throw new DomainException('El doctor que prescribe no está activo.');
        }
        if (blank($rx->doctor->doctorProfile->professional_license)) {
            throw new DomainException('El doctor no tiene cédula profesional registrada; no es posible emitir la receta.');
        }
    }

    /** Crea un borrador nuevo copiando los medicamentos de una receta emitida. */
    public function repeat(Prescription $source, ?User $actor = null): Prescription
    {
        if (! $source->isSigned()) {
            throw new DomainException('Solo se pueden repetir recetas emitidas.');
        }

        return DB::transaction(function () use ($source, $actor) {
            $source->loadMissing('items', 'patient', 'doctor');

            $patient = $source->patient;
            if (! $patient || $patient->status !== RecordStatus::Active || $patient->anonymized_at !== null) {
                throw new DomainException('El paciente no está activo; no es posible repetir la receta.');
            }

            $doctorId = $source->doctor_member_id;
            if (! $source->doctor || ! $source->doctor->is_active) {
                $doctorId = $actor?->member?->id;
                if (! $doctorId) {
                    throw new DomainException('El doctor original no está activo.');
                }
            }

            $new = new Prescription();
            $new->folio = Folio::next(Folio::PRESCRIPTION);
            $new->status = SigningStatus::Borrador;
            $new->patient_id = $source->patient_id;
            $new->doctor_member_id = $doctorId;
            $new->issued_on = now()->toDateString();
            $new->diagnosis = $source->diagnosis;
            $new->general_instructions = $source->general_instructions;
            $new->repeated_from_id = $source->id;
            $new->save();

            $this->replaceItems($new, $source->items->map(fn (PrescriptionItem $i) => [
                'medication_id' => $i->medication_id,
                'medication_name' => $i->medication_name,
                'strength' => $i->strength,
                'presentation' => $i->presentation,
                'dose' => $i->dose,
                'route' => $i->route,
                'frequency' => $i->frequency,
                'duration' => $i->duration,
                'instructions' => $i->instructions,
            ])->all());

            return $new->load('items');
        });
    }

    /** Registra la entrega de una receta emitida (impresión, correo o WhatsApp). */
    public function send(Prescription $rx, string $channel, ?User $actor = null): PrescriptionDelivery
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new DomainException('Canal de envío no válido.');
        }
        if (! $rx->isSigned()) {
            throw new DomainException('Solo se pueden enviar o imprimir recetas emitidas. Firme y emita la receta primero.');
        }

        $rx->loadMissing('patient');
        /** @var Patient|null $patient */
        $patient = $rx->patient;
        if (! $patient) {
            throw new DomainException('La receta no tiene paciente.');
        }

        $destination = null;
        if ($channel === 'email') {
            $destination = $patient->email;
            if (blank($destination)) {
                throw new DomainException('El paciente no tiene correo electrónico registrado.');
            }
        } elseif ($channel === 'whatsapp') {
            $destination = preg_replace('/\D+/', '', (string) $patient->phone);
            if (strlen((string) $destination) !== 10) {
                throw new DomainException('El paciente no tiene un teléfono celular válido de 10 dígitos para WhatsApp.');
            }
        }

        return DB::transaction(function () use ($rx, $channel, $actor, $destination) {
            $immediate = $channel === 'impresion';

            $event = OutboxEvent::create([
                'event_type' => 'prescription.deliver',
                'aggregate_type' => 'prescription',
                'aggregate_id' => $rx->id,
                'payload' => array_filter([
                    'prescription_id' => $rx->id,
                    'folio' => $rx->folio,
                    'patient_id' => $rx->patient_id,
                    'channel' => $channel,
                    'destination' => $destination,
                ]),
                'status' => $immediate ? OutboxStatus::Enviado : OutboxStatus::Pendiente,
                'processed_at' => $immediate ? now() : null,
                'attempts' => 0,
            ]);

            return PrescriptionDelivery::create([
                'prescription_id' => $rx->id,
                'channel' => $channel,
                'sent_at' => $immediate ? now() : null,
                'actor_user_id' => $actor?->id,
                'outbox_event_id' => $event->id,
            ]);
        });
    }

    /** Elimina un borrador (las recetas emitidas jamás se borran). */
    public function deleteDraft(Prescription $rx): void
    {
        DB::transaction(function () use ($rx) {
            $rx = Prescription::query()->whereKey($rx->id)->lockForUpdate()->firstOrFail();
            if ($rx->isSigned()) {
                throw new DomainException('Una receta emitida no puede eliminarse.');
            }
            if ($rx->repeats()->exists()) {
                throw new DomainException('La receta tiene recetas derivadas y no puede eliminarse.');
            }
            PrescriptionItem::query()->where('prescription_id', $rx->id)->delete();
            $rx->delete();
        });
    }

    /** SHA-256 del contenido clínico y de los snapshots (para verificar integridad). */
    public function contentHash(Prescription $rx): string
    {
        $rx->loadMissing('items');

        $payload = [
            'folio' => $rx->folio,
            'patient_id' => $rx->patient_id,
            'doctor_member_id' => $rx->doctor_member_id,
            'issued_on' => $rx->issued_on->toDateString(),
            'diagnosis' => $rx->diagnosis,
            'general_instructions' => $rx->general_instructions,
            'doctor_name' => $rx->doctor_name_snapshot,
            'doctor_license' => $rx->doctor_license_snapshot,
            'items' => $rx->items->sortBy('line_number')->values()->map(fn (PrescriptionItem $i) => [
                'line' => (int) $i->line_number,
                'name' => $i->medication_name,
                'strength' => $i->strength,
                'presentation' => $i->presentation,
                'dose' => $i->dose,
                'route' => $i->route,
                'frequency' => $i->frequency,
                'duration' => $i->duration,
                'instructions' => $i->instructions,
            ])->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** true si el contenido actual coincide con el hash guardado al firmar. */
    public function verifyIntegrity(Prescription $rx): bool
    {
        return $rx->isSigned() && hash_equals((string) $rx->content_hash, $this->contentHash($rx));
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function replaceItems(Prescription $rx, array $items): void
    {
        PrescriptionItem::query()->where('prescription_id', $rx->id)->delete();

        // La FK compuesta (organization_id, medication_id) solo admite medicamentos propios
        // de la clínica: los del catálogo global quedan como snapshot (nombre, dosis, etc.).
        $linkable = [];
        foreach (array_values($items) as $item) {
            $id = $this->blankToNull($item['medication_id'] ?? null);
            if ($id !== null && ! array_key_exists($id, $linkable)) {
                $linkable[$id] = Medication::withoutGlobalScopes()
                    ->whereKey($id)->where('organization_id', Organization::currentId())->exists();
            }
        }

        foreach (array_values($items) as $i => $item) {
            $medicationId = $this->blankToNull($item['medication_id'] ?? null);
            PrescriptionItem::create([
                'prescription_id' => $rx->id,
                'line_number' => $i + 1,
                'medication_id' => ($medicationId !== null && ($linkable[$medicationId] ?? false)) ? $medicationId : null,
                'medication_name' => trim((string) $item['medication_name']),
                'strength' => $this->blankToNull($item['strength'] ?? null),
                'presentation' => $this->blankToNull($item['presentation'] ?? null),
                'dose' => trim((string) $item['dose']),
                'route' => $this->blankToNull($item['route'] ?? null),
                'frequency' => trim((string) $item['frequency']),
                'duration' => $this->blankToNull($item['duration'] ?? null),
                'instructions' => $this->blankToNull($item['instructions'] ?? null),
            ]);
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
