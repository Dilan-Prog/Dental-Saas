<?php

namespace App\Services\Expediente;

use App\Enums\NoteType;
use App\Enums\SigningStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\ClinicalNoteAddendum;
use App\Models\MedicalRecord;
use App\Models\OrganizationMember;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Notas clínicas: borrador editable, firma con hash SHA-256 (inmutable después)
 * y adendas versionadas para correcciones.
 *
 * Convención: en notas "simple" el texto de la nota se guarda en `objective`
 * (procedimiento y hallazgos); en notas SOAP se usan subjective/objective/analysis/plan.
 */
class NoteService
{
    public const MECHANISM = 'hash_interno';

    /** Doctores activos de la clínica (miembros con perfil de doctor). */
    public function doctors(): Collection
    {
        return OrganizationMember::query()
            ->where('is_active', true)
            ->whereHas('doctorProfile')
            ->with('user:id,full_name')
            ->get()
            ->sortBy(fn ($m) => $m->user?->full_name)
            ->values();
    }

    /** Doctor sugerido: el usuario si es doctor; si no, el asignado al paciente; si no, el primero. */
    public function defaultDoctorId(Patient $patient, ?string $memberId): ?string
    {
        $ids = $this->doctors()->pluck('id');

        if ($memberId && $ids->contains($memberId)) {
            return $memberId;
        }
        if ($patient->assigned_doctor_member_id && $ids->contains($patient->assigned_doctor_member_id)) {
            return $patient->assigned_doctor_member_id;
        }

        return $ids->first();
    }

    public function medicalRecordFor(Patient $patient): MedicalRecord
    {
        return MedicalRecord::query()->firstOrCreate(['patient_id' => $patient->id]);
    }

    /** Consulta (encuentro) donde se registra la nota: existente, de una cita, o una nueva. */
    public function resolveEncounter(Patient $patient, array $data, string $doctorId): ClinicalEncounter
    {
        $record = $this->medicalRecordFor($patient);

        if (! empty($data['encounter_id'])) {
            $encounter = ClinicalEncounter::query()
                ->where('medical_record_id', $record->id)
                ->find($data['encounter_id']);
            if (! $encounter) {
                throw new ExpedienteException('La consulta seleccionada no pertenece a este paciente.');
            }

            return $encounter;
        }

        if (! empty($data['appointment_id'])) {
            $appointment = Appointment::query()->where('patient_id', $patient->id)->find($data['appointment_id']);
            if (! $appointment) {
                throw new ExpedienteException('La cita seleccionada no pertenece a este paciente.');
            }

            return ClinicalEncounter::query()->firstOrCreate(
                ['medical_record_id' => $record->id, 'appointment_id' => $appointment->id],
                [
                    'branch_id' => $appointment->branch_id,
                    'doctor_member_id' => $appointment->doctor_member_id,
                    'occurred_at' => $appointment->starts_at,
                ]
            );
        }

        $branchId = Branch::query()->orderByDesc('is_main')->orderBy('created_at')->value('id');
        if (! $branchId) {
            throw new ExpedienteException('No hay una sucursal configurada para registrar la consulta.');
        }

        return ClinicalEncounter::query()->create([
            'medical_record_id' => $record->id,
            'branch_id' => $branchId,
            'doctor_member_id' => $doctorId,
            'occurred_at' => Carbon::now(),
        ]);
    }

    /** Crea la nota como borrador. */
    public function create(Patient $patient, array $data, string $authorMemberId): ClinicalNote
    {
        return DB::transaction(function () use ($patient, $data, $authorMemberId) {
            $doctorId = $data['responsible_doctor_member_id'];
            $encounter = $this->resolveEncounter($patient, $data, $doctorId);

            return ClinicalNote::query()->create([
                'encounter_id' => $encounter->id,
                'note_type' => $data['note_type'],
                'author_member_id' => $authorMemberId,
                'responsible_doctor_member_id' => $doctorId,
                'treatment_id' => $data['treatment_id'] ?? null,
                'tooth_fdi' => $data['tooth_fdi'] ?? null,
                'appointment_id' => $data['appointment_id'] ?? $encounter->appointment_id,
                'subjective' => $this->clean($data['subjective'] ?? null),
                'objective' => $this->clean($data['objective'] ?? null),
                'analysis' => $this->clean($data['analysis'] ?? null),
                'plan' => $this->clean($data['plan'] ?? null),
                'prescription_text' => $this->clean($data['prescription_text'] ?? null),
                'status' => SigningStatus::Borrador->value,
            ]);
        });
    }

    /** Actualiza un borrador (una nota firmada es inmutable). */
    public function update(ClinicalNote $note, array $data, string $memberId, bool $isAdmin): ClinicalNote
    {
        return DB::transaction(function () use ($note, $data, $memberId, $isAdmin) {
            $locked = $this->lock($note);
            $this->assertDraft($locked, 'Una nota firmada no se puede editar; agrega una adenda.');
            $this->assertCanManage($locked, $memberId, $isAdmin);

            $locked->fill([
                'responsible_doctor_member_id' => $data['responsible_doctor_member_id'],
                'treatment_id' => $data['treatment_id'] ?? null,
                'tooth_fdi' => $data['tooth_fdi'] ?? null,
                'subjective' => $this->clean($data['subjective'] ?? null),
                'objective' => $this->clean($data['objective'] ?? null),
                'analysis' => $this->clean($data['analysis'] ?? null),
                'plan' => $this->clean($data['plan'] ?? null),
                'prescription_text' => $this->clean($data['prescription_text'] ?? null),
            ])->save();

            return $locked;
        });
    }

    /** Firma la nota: sólo el doctor responsable; fija hash y bloquea la edición. */
    public function sign(ClinicalNote $note, string $memberId): ClinicalNote
    {
        return DB::transaction(function () use ($note, $memberId) {
            $locked = $this->lock($note);
            $this->assertDraft($locked, 'La nota ya está firmada.');

            if ($locked->responsible_doctor_member_id !== $memberId) {
                throw new ExpedienteException('Sólo el doctor responsable de la nota puede firmarla.');
            }
            $this->assertSignable($locked);

            $locked->content_hash = $locked->computeHash();
            $locked->signed_at = Carbon::now();
            $locked->signed_by_member_id = $memberId;
            $locked->signature_mechanism = self::MECHANISM;
            $locked->status = SigningStatus::Firmada->value;
            $locked->save();

            return $locked;
        });
    }

    /** Agrega una adenda (versión) a una nota firmada. */
    public function addAddendum(ClinicalNote $note, string $content, string $reason, string $memberId): ClinicalNoteAddendum
    {
        return DB::transaction(function () use ($note, $content, $reason, $memberId) {
            $locked = $this->lock($note);
            if (! $locked->isSigned()) {
                throw new ExpedienteException('Sólo se pueden agregar adendas a notas firmadas.');
            }

            $version = (int) ClinicalNoteAddendum::query()->where('note_id', $locked->id)->max('version_number') + 1;
            $content = trim($content);
            $reason = trim($reason);

            return ClinicalNoteAddendum::query()->create([
                'note_id' => $locked->id,
                'version_number' => $version,
                'content' => $content,
                'reason' => $reason,
                'author_member_id' => $memberId,
                'content_hash' => hash('sha256', implode('|', [$locked->content_hash, $version, $content, $reason, $memberId])),
            ]);
        });
    }

    /** Elimina sólo borradores. */
    public function delete(ClinicalNote $note, string $memberId, bool $isAdmin): void
    {
        DB::transaction(function () use ($note, $memberId, $isAdmin) {
            $locked = $this->lock($note);
            $this->assertDraft($locked, 'Una nota firmada no se puede eliminar.');
            $this->assertCanManage($locked, $memberId, $isAdmin);
            $locked->delete();
        });
    }

    /** Verifica que el hash guardado coincida con el contenido actual (integridad). */
    public function verifyIntegrity(ClinicalNote $note): bool
    {
        return $note->isSigned() && hash_equals((string) $note->content_hash, $note->computeHash());
    }

    /** Consulta base de notas de un paciente. */
    public function notesFor(Patient $patient): Builder
    {
        return ClinicalNote::query()->whereIn(
            'encounter_id',
            ClinicalEncounter::query()->select('id')->whereIn(
                'medical_record_id',
                MedicalRecord::query()->where('patient_id', $patient->id)->select('id')
            )
        );
    }

    /** Resumen de una línea para listados. */
    public static function summary(ClinicalNote $note, int $limit = 220): string
    {
        $text = $note->note_type === NoteType::Simple
            ? ($note->objective ?: $note->plan ?: $note->analysis ?: $note->subjective)
            : ($note->analysis ?: $note->plan ?: $note->objective ?: $note->subjective);

        return \Illuminate\Support\Str::limit(trim((string) $text), $limit);
    }

    // ------------------------------------------------------------------

    private function lock(ClinicalNote $note): ClinicalNote
    {
        return ClinicalNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
    }

    private function assertDraft(ClinicalNote $note, string $message): void
    {
        if ($note->isSigned()) {
            throw new ExpedienteException($message);
        }
    }

    private function assertCanManage(ClinicalNote $note, string $memberId, bool $isAdmin): void
    {
        if (! $isAdmin && $note->author_member_id !== $memberId && $note->responsible_doctor_member_id !== $memberId) {
            throw new ExpedienteException('Sólo el autor o el doctor responsable puede modificar este borrador.');
        }
    }

    private function assertSignable(ClinicalNote $note): void
    {
        if ($note->note_type === NoteType::Simple) {
            if (blank($note->objective)) {
                throw new ExpedienteException('La nota está vacía: escribe el procedimiento y los hallazgos antes de firmar.');
            }

            return;
        }

        foreach (['subjective' => 'Subjetivo (S)', 'objective' => 'Objetivo (O)', 'analysis' => 'Análisis (A)', 'plan' => 'Plan (P)'] as $field => $label) {
            if (blank($note->{$field})) {
                throw new ExpedienteException("Para firmar una nota SOAP completa la sección {$label}.");
            }
        }
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
