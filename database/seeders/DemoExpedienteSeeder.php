<?php

namespace Database\Seeders;

use App\Enums\ClinicalFileType;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalFile;
use App\Models\ClinicalNote;
use App\Models\ConsentAcceptance;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Treatment;
use App\Services\Expediente\DocumentService;
use App\Services\Expediente\FileService;
use App\Services\Expediente\NoteService;
use App\Services\Expediente\OdontogramService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Datos demo del expediente clínico: consultas, notas (simple/SOAP, firmadas con adenda y borrador),
 * odontograma con historial, archivos, consentimientos, tratamientos y documentos generados.
 * Idempotente: omite a los pacientes que ya tienen notas.
 */
class DemoExpedienteSeeder extends Seeder
{
    public function run(): void
    {
        $notes = app(NoteService::class);
        $patients = Patient::query()->orderBy('created_at')->orderBy('folio')->limit(6)->get();
        $doctors = $notes->doctors();
        $branch = Branch::query()->orderByDesc('is_main')->orderBy('created_at')->first();

        if ($patients->isEmpty() || $doctors->isEmpty() || ! $branch) {
            return;
        }

        $catalog = Treatment::query()->where('status', 'active')->orderBy('name')->get();

        foreach ($patients as $i => $patient) {
            if ($notes->notesFor($patient)->exists()) {
                continue;
            }
            $doctor = $doctors[$i % $doctors->count()];
            $this->seedPatient($patient, $i, $doctor->id, $branch, $catalog);
        }
    }

    private function seedPatient(Patient $patient, int $i, string $doctorId, Branch $branch, $catalog): void
    {
        $notes = app(NoteService::class);
        $record = $notes->medicalRecordFor($patient);
        if (blank($record->medical_history)) {
            $record->medical_history = ['Sin antecedentes relevantes.', 'Hipertensión controlada con medicamento.', 'Diabetes tipo 2 en control.'][$i % 3];
        }
        $record->save();

        $treatment = $catalog->get($i % max(1, $catalog->count()));

        // ---- Consultas y notas
        $enc1 = $this->encounter($record->id, $branch->id, $doctorId, Carbon::now()->subDays(21 + $i));
        $enc2 = $this->encounter($record->id, $branch->id, $doctorId, Carbon::now()->subDays(7 + $i));

        $soap = $this->note($patient, $enc1, $doctorId, [
            'note_type' => 'soap', 'responsible_doctor_member_id' => $doctorId, 'treatment_id' => $treatment?->id, 'tooth_fdi' => 26,
            'subjective' => 'Refiere sensibilidad al masticar en el lado superior izquierdo desde hace dos semanas.',
            'objective' => 'Caries interproximal en pieza 26, sin compromiso pulpar. Percusión negativa.',
            'analysis' => 'Caries dentinaria de profundidad media en 26.',
            'plan' => 'Resina compuesta en 26 en la siguiente cita. Reforzar higiene interproximal.',
        ], Carbon::now()->subDays(21 + $i));

        $simple = $this->note($patient, $enc2, $doctorId, [
            'note_type' => 'simple', 'responsible_doctor_member_id' => $doctorId, 'treatment_id' => $treatment?->id, 'tooth_fdi' => null,
            'objective' => 'Limpieza dental y revisión general. Sin hallazgos relevantes. Se programa resina en la pieza 26.',
        ], Carbon::now()->subDays(7 + $i));

        if ($i === 0) {
            $notes->addAddendum($soap, 'Se aclara que la sensibilidad es principalmente al frío.', 'Corrección del interrogatorio', $doctorId);

            // Borrador pendiente de firma.
            $enc3 = $this->encounter($record->id, $branch->id, $doctorId, Carbon::now()->subHours(3));
            $notes->create($patient, [
                'note_type' => 'simple', 'responsible_doctor_member_id' => $doctorId, 'treatment_id' => $treatment?->id,
                'encounter_id' => $enc3->id, 'objective' => 'Borrador: control posoperatorio, paciente sin molestias.',
            ], $doctorId);
        }

        // ---- Odontograma con historial
        $odo = app(OdontogramService::class);
        $odo->record($patient, 26, 'caries', ['O'], $doctorId, $enc1->id, null, Carbon::now()->subDays(21 + $i));
        if ($i % 2 === 0) {
            $odo->record($patient, 26, 'restauracion', ['O'], $doctorId, $enc2->id, null, Carbon::now()->subDays(5 + $i));
        }
        $odo->record($patient, 36, 'endodoncia', [], $doctorId, null, null, Carbon::now()->subDays(60));
        $odo->record($patient, 14, 'ausente', [], $doctorId, null, null, Carbon::now()->subDays(90));
        $odo->record($patient, 47, 'restauracion', ['O', 'D'], $doctorId, null, null, Carbon::now()->subDays(45));

        // ---- Tratamientos del paciente
        if ($catalog->isNotEmpty() && $i < 4) {
            $statuses = ['planeado', 'en_progreso', 'realizado', 'planeado'];
            PatientTreatment::query()->create([
                'patient_id' => $patient->id,
                'treatment_id' => $catalog[$i % $catalog->count()]->id,
                'tooth_fdi' => $i % 2 === 0 ? 26 : null,
                'status' => $statuses[$i],
                'started_at' => $statuses[$i] !== 'planeado' ? Carbon::now()->subDays(14) : null,
                'completed_at' => $statuses[$i] === 'realizado' ? Carbon::now()->subDays(3) : null,
            ]);
        }

        // ---- Archivos clínicos
        $files = app(FileService::class);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
        $files->storeContents($patient, $png, 'png', 'image/png', ClinicalFileType::Radiografia->value, 'Radiografía panorámica.png', $doctorId);
        $files->storeContents($patient, $png, 'png', 'image/png', ClinicalFileType::FotografiaIntraoral->value, 'Foto intraoral pieza 26.png', $doctorId);
        $consentFile = $files->storeContents($patient, $pdf, 'pdf', 'application/pdf', ClinicalFileType::Consentimiento->value, 'Consentimiento informado.pdf', $doctorId);

        // ---- Consentimiento aceptado (tutor si es menor de edad)
        $minor = $patient->birth_date && $patient->birth_date->age < 18;
        $guardian = $minor ? $patient->guardians()->first() : null;
        ConsentAcceptance::query()->create([
            'patient_id' => $patient->id,
            'clinical_file_id' => $consentFile->id,
            'accepted_at' => Carbon::now()->subDays(21 + $i),
            'accepted_by_name' => $guardian?->full_name ?? ($minor ? 'Tutor de '.$patient->full_name : $patient->full_name),
            'method' => 'firma_autografa_escaneada',
            'witness_member_id' => $doctorId,
            'content_hash' => hash('sha256', $consentFile->sha256.'|'.$patient->id),
        ]);

        // ---- Documento generado (historia clínica) para el primer paciente
        if ($i === 0) {
            app(DocumentService::class)->store($patient, DocumentService::HISTORIA, [
                'full_name' => $patient->full_name,
                'birth_date' => $patient->birth_date?->format('Y-m-d'),
                'phone' => $patient->phone,
                'chief_complaint' => 'Revisión general y limpieza.',
                'bad_experience' => 'no', 'hygiene' => 'cepillo', 'brushing_times' => 3,
                'conditions' => [], 'blood_pressure' => '118/76', 'pulse' => 72, 'respiration' => 16, 'temperature' => 36.5,
                'sync_record' => false,
            ], $doctorId);
        }
    }

    private function encounter(string $recordId, string $branchId, string $doctorId, Carbon $at): ClinicalEncounter
    {
        return ClinicalEncounter::query()->create([
            'medical_record_id' => $recordId, 'branch_id' => $branchId, 'doctor_member_id' => $doctorId, 'occurred_at' => $at,
        ]);
    }

    /** Crea la nota como borrador, ajusta su fecha de creación y la firma (después no se puede modificar). */
    private function note(Patient $patient, ClinicalEncounter $encounter, string $doctorId, array $data, Carbon $at): ClinicalNote
    {
        $notes = app(NoteService::class);
        $note = $notes->create($patient, $data + ['encounter_id' => $encounter->id], $doctorId)->refresh();

        // Firma "histórica": un único UPDATE sobre el borrador (el trigger sólo bloquea lo ya firmado),
        // para que signed_at coincida con la fecha de la consulta demo.
        DB::table('clinical_notes')->where('id', $note->id)->update([
            'created_at' => $at,
            'status' => 'firmada',
            'signed_at' => $at,
            'signed_by_member_id' => $doctorId,
            'signature_mechanism' => NoteService::MECHANISM,
            'content_hash' => $note->computeHash(),
        ]);

        return ClinicalNote::query()->findOrFail($note->id);
    }
}
