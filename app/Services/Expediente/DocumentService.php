<?php

namespace App\Services\Expediente;

use App\Enums\ClinicalFileType;
use App\Models\ClinicalFile;
use App\Models\ConsentAcceptance;
use App\Models\Patient;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generación de documentos clínicos (Historia clínica dental, Autorización de tratamiento).
 * El contenido capturado se guarda como JSON en el disco privado y se registra como ClinicalFile;
 * se imprime desde una vista dedicada. La aceptación del paciente se registra en consent_acceptances.
 */
class DocumentService
{
    public const MIME = 'application/json';

    public const HISTORIA = 'historia-clinica';

    public const AUTORIZACION = 'autorizacion-tratamiento';

    public const CONDITIONS = [
        'anemia' => 'Anemia',
        'artritis' => 'Artritis',
        'hipertension' => 'Hipertensión',
        'hipotension' => 'Hipotensión',
        'diabetes' => 'Diabetes',
        'cardiopatia' => 'Cardiopatía',
        'convulsiones' => 'Convulsiones',
        'reacciones_alergicas' => 'Reacciones alérgicas',
        'hepatitis' => 'Hepatitis',
        'embarazada' => 'Embarazada',
    ];

    public const EXTRACTION_COMPLICATIONS = [
        'hemorragia_prolongada' => 'Hemorragia prolongada',
        'problemas_cicatrizacion' => 'Problemas de cicatrización',
    ];

    public function __construct(private FileService $files)
    {
    }

    /** @return array<string, array{title:string,description:string,icon:string,file_type:string,short:string}> */
    public static function templates(): array
    {
        return [
            self::HISTORIA => [
                'title' => 'Historia Clínica Dental',
                'short' => 'Historia clínica dental',
                'description' => 'Plantilla estandarizada para registrar los antecedentes médicos, odontograma inicial, padecimientos actuales y plan de tratamiento detallado.',
                'icon' => 'file-text',
                'file_type' => ClinicalFileType::Otro->value,
            ],
            self::AUTORIZACION => [
                'title' => 'Autorización de Tratamiento',
                'short' => 'Autorización de tratamiento',
                'description' => 'Genera un formulario de consentimiento informado. Obligatorio para asegurar que el paciente comprende y acepta el plan y presupuesto de los procedimientos clínicos.',
                'icon' => 'clipboard',
                'file_type' => ClinicalFileType::Consentimiento->value,
            ],
        ];
    }

    public static function exists(string $template): bool
    {
        return array_key_exists($template, self::templates());
    }

    public function store(Patient $patient, string $template, array $data, string $memberId): ClinicalFile
    {
        abort_unless(self::exists($template), 404);
        $meta = self::templates()[$template];

        return DB::transaction(function () use ($patient, $template, $data, $memberId, $meta) {
            $now = Carbon::now();
            $payload = [
                'template' => $template,
                'version' => 1,
                'created_at' => $now->toIso8601String(),
                'patient' => ['id' => $patient->id, 'folio' => $patient->folio, 'full_name' => $patient->full_name],
                'data' => $this->sanitize(Arr::except($data, ['patient_id', 'sync_record', 'register_acceptance', 'accepted_by_name', 'acceptance_method'])),
            ];

            $file = $this->files->storeContents(
                $patient,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                'json',
                self::MIME,
                $meta['file_type'],
                $meta['short'].' — '.$now->format('d/m/Y'),
                $memberId
            );

            if ($template === self::HISTORIA && ! empty($data['sync_record'])) {
                $this->syncMedicalRecord($patient, $data);
            }

            if ($template === self::AUTORIZACION && ! empty($data['register_acceptance'])) {
                ConsentAcceptance::query()->create([
                    'patient_id' => $patient->id,
                    'clinical_file_id' => $file->id,
                    'accepted_at' => $now,
                    'accepted_by_name' => trim($data['accepted_by_name']),
                    'method' => $data['acceptance_method'] ?? 'verbal_registrada',
                    'content_hash' => $file->sha256,
                ]);
            }

            return $file;
        });
    }

    /** @return array{template:string,created_at:string,patient:array,data:array}|null */
    public function payload(ClinicalFile $file): ?array
    {
        if (! $this->files->isGeneratedDocument($file)) {
            return null;
        }
        $json = $this->files->contents($file);

        return $json ? json_decode($json, true) : null;
    }

    /** Valores por defecto de la plantilla a partir del paciente (precarga del formulario). */
    public function defaults(Patient $patient, string $template): array
    {
        $record = $patient->medicalRecord;

        if ($template === self::HISTORIA) {
            return [
                'full_name' => $patient->full_name,
                'birth_date' => $patient->birth_date?->format('Y-m-d'),
                'sex' => $patient->sex?->value !== 'unspecified' ? $patient->sex?->value : null,
                'phone' => $patient->phone,
                'allergies_detail' => $record?->allergies,
                'under_treatment' => null,
            ];
        }

        return [];
    }

    private function syncMedicalRecord(Patient $patient, array $data): void
    {
        $record = $patient->medicalRecord ?? \App\Models\MedicalRecord::query()->create(['patient_id' => $patient->id]);

        if (! blank($data['allergies_detail'] ?? null)) {
            $record->allergies = trim($data['allergies_detail']);
        }

        $labels = collect($data['conditions'] ?? [])->map(fn ($c) => self::CONDITIONS[$c] ?? null)->filter()->values();
        $parts = [];
        if ($labels->isNotEmpty()) {
            $parts[] = 'Padecimientos: '.$labels->implode(', ').'.';
        }
        if (! blank($data['under_treatment'] ?? null)) {
            $parts[] = 'Bajo tratamiento médico: '.trim($data['under_treatment']);
        }
        if ($parts) {
            $record->medical_history = implode("\n", $parts);
        }

        $record->save();
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->sanitize($v), $value);
        }

        return is_string($value) ? trim(strip_tags($value)) : $value;
    }
}
