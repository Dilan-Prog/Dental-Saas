<?php

namespace App\Services\Pacientes;

use App\Enums\AppointmentStatus;
use App\Enums\FiscalStatus;
use App\Enums\RecordStatus;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\OrganizationMember;
use App\Models\Patient;
use App\Models\PatientFiscalProfile;
use App\Models\PatientGuardian;
use App\Models\PaymentApplication;
use App\Models\Role;
use App\Support\Folio;
use App\Support\Money;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio del módulo Pacientes. Los controladores solo orquestan.
 */
class PatientService
{
    public const ADULT_AGE = 18;

    /** Parentescos permitidos para el tutor de un paciente. */
    public const RELATIONSHIPS = ['Madre', 'Padre', 'Tutor legal', 'Abuelo(a)', 'Hermano(a)', 'Tío(a)', 'Otro'];

    /** Estados de cita que bloquean la baja/anonimización de un paciente. */
    private const ACTIVE_APPOINTMENT_STATUSES = [
        AppointmentStatus::Pendiente,
        AppointmentStatus::Confirmada,
        AppointmentStatus::EnCurso,
    ];

    // ------------------------------------------------------------------ altas

    /**
     * Alta de paciente: folio PAC-, expediente (MedicalRecord), perfil fiscal
     * opcional y tutor opcional, todo en una sola transacción.
     *
     * @param  array<string, mixed>  $data      datos personales ya validados
     * @param  array<string, mixed>|null  $fiscal
     * @param  array<string, mixed>|null  $guardian
     */
    public function create(array $data, ?array $fiscal = null, ?array $guardian = null): Patient
    {
        return DB::transaction(function () use ($data, $fiscal, $guardian) {
            $patient = Patient::create([
                'folio' => Folio::next(Folio::PATIENT),
                'full_name' => $data['full_name'],
                'birth_date' => $data['birth_date'] ?? null,
                'sex' => $data['sex'] ?: 'unspecified',
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'assigned_doctor_member_id' => $data['assigned_doctor_member_id'] ?? null,
                'status' => RecordStatus::Active->value,
            ]);

            MedicalRecord::create(['patient_id' => $patient->id]);

            if ($this->hasValues($fiscal)) {
                $this->saveFiscalProfile($patient, $fiscal);
            }

            if ($this->hasValues($guardian)) {
                $this->saveGuardian($patient, $guardian);
            }

            return $patient;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $guardian
     */
    public function update(Patient $patient, array $data, ?array $guardian = null): Patient
    {
        $this->assertEditable($patient);

        return DB::transaction(function () use ($patient, $data, $guardian) {
            $patient->update([
                'full_name' => $data['full_name'],
                'birth_date' => $data['birth_date'] ?? null,
                'sex' => $data['sex'] ?: 'unspecified',
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'assigned_doctor_member_id' => $data['assigned_doctor_member_id'] ?? null,
                'status' => $data['status'] ?? $patient->status->value,
            ]);

            if ($this->hasValues($guardian) && $patient->guardians()->doesntExist()) {
                $this->saveGuardian($patient, $guardian);
            }

            return $patient->refresh();
        });
    }

    // ------------------------------------------------------------ baja lógica

    /**
     * Baja lógica (deleted_at + status inactivo). Nunca se borra físicamente:
     * el expediente, las notas firmadas y los documentos fiscales se conservan.
     */
    public function archive(Patient $patient): void
    {
        $this->assertEditable($patient);
        $this->assertNoOpenWork($patient, 'dar de baja');

        DB::transaction(function () use ($patient) {
            $patient->forceFill(['status' => RecordStatus::Inactive->value])->save();
            $patient->delete();
        });
    }

    public function restore(Patient $patient): void
    {
        if ($patient->anonymized_at !== null) {
            throw new DomainException('Un paciente anonimizado no se puede reactivar.');
        }

        DB::transaction(function () use ($patient) {
            $patient->restore();
            $patient->forceFill(['status' => RecordStatus::Active->value])->save();
        });
    }

    /**
     * Anonimización según política legal: se eliminan los datos que identifican a
     * la persona. La información clínica (expediente) y los CFDI emitidos se
     * conservan porque existe obligación de conservarlos.
     */
    public function anonymize(Patient $patient): void
    {
        $this->assertEditable($patient);
        $this->assertNoOpenWork($patient, 'anonimizar');

        DB::transaction(function () use ($patient) {
            $patient->guardians()->delete();
            $patient->fiscalProfiles()->delete();

            $patient->forceFill([
                'full_name' => 'Paciente anonimizado '.$patient->folio,
                'birth_date' => null,
                'sex' => 'unspecified',
                'phone' => null,
                'email' => null,
                'status' => RecordStatus::Inactive->value,
                'anonymized_at' => now(),
            ])->save();
        });
    }

    // ---------------------------------------------------- datos fiscales

    /**
     * Crea o actualiza (si trae `id`) un perfil fiscal. Garantiza un único perfil
     * predeterminado: el primero siempre lo es y, si se marca otro, los demás se desmarcan.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveFiscalProfile(Patient $patient, array $data, ?PatientFiscalProfile $profile = null): PatientFiscalProfile
    {
        return DB::transaction(function () use ($patient, $data, $profile) {
            $payload = [
                'rfc' => strtoupper(trim($data['rfc'])),
                'legal_name' => trim($data['legal_name']),
                'tax_regime_code' => $data['tax_regime_code'],
                'fiscal_postal_code' => $data['fiscal_postal_code'],
                'default_cfdi_use' => $data['default_cfdi_use'] ?? null,
            ];

            $isFirst = $patient->fiscalProfiles()->doesntExist();
            $wantsDefault = $isFirst || ! empty($data['is_default']);

            if ($profile) {
                $profile->update($payload);
            } else {
                $profile = $patient->fiscalProfiles()->create($payload + ['is_default' => false]);
            }

            if ($wantsDefault) {
                $this->makeDefault($patient, $profile);
            } elseif ($profile->is_default && empty($data['is_default'])) {
                // No se puede quitar el predeterminado sin designar otro: se mantiene.
                $profile->forceFill(['is_default' => true])->save();
            }

            return $profile->refresh();
        });
    }

    public function makeDefault(Patient $patient, PatientFiscalProfile $profile): void
    {
        DB::transaction(function () use ($patient, $profile) {
            $patient->fiscalProfiles()->where('id', '!=', $profile->id)->update(['is_default' => false]);
            $profile->forceFill(['is_default' => true])->save();
        });
    }

    public function deleteFiscalProfile(Patient $patient, PatientFiscalProfile $profile): void
    {
        DB::transaction(function () use ($patient, $profile) {
            $wasDefault = $profile->is_default;
            $profile->delete();

            if ($wasDefault && ($next = $patient->fiscalProfiles()->first())) {
                $next->forceFill(['is_default' => true])->save();
            }
        });
    }

    // ------------------------------------------------------------- tutores

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveGuardian(Patient $patient, array $data, ?PatientGuardian $guardian = null): PatientGuardian
    {
        return DB::transaction(function () use ($patient, $data, $guardian) {
            $payload = [
                'full_name' => trim($data['full_name']),
                'relationship' => $data['relationship'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ];

            $isFirst = $patient->guardians()->doesntExist();
            $wantsPrimary = $isFirst || ! empty($data['is_primary']);

            if ($guardian) {
                $guardian->update($payload);
            } else {
                $guardian = $patient->guardians()->create($payload + ['is_primary' => false]);
            }

            if ($wantsPrimary) {
                $patient->guardians()->where('id', '!=', $guardian->id)->update(['is_primary' => false]);
                $guardian->forceFill(['is_primary' => true])->save();
            } elseif ($guardian->is_primary) {
                $guardian->forceFill(['is_primary' => true])->save();
            }

            return $guardian->refresh();
        });
    }

    public function deleteGuardian(Patient $patient, PatientGuardian $guardian): void
    {
        if ($this->isMinor($patient->birth_date) && $patient->guardians()->count() <= 1) {
            throw new DomainException('El paciente es menor de edad: debe conservar al menos un tutor. Agrega otro antes de eliminar éste.');
        }

        DB::transaction(function () use ($patient, $guardian) {
            $wasPrimary = $guardian->is_primary;
            $guardian->delete();

            if ($wasPrimary && ($next = $patient->guardians()->first())) {
                $next->forceFill(['is_primary' => true])->save();
            }
        });
    }

    // ---------------------------------------------------------- consultas

    /**
     * Pacientes que podrían ser duplicados: mismo teléfono, mismo correo o mismo
     * nombre con la misma fecha de nacimiento.
     *
     * @return Collection<int, Patient>
     */
    public function findDuplicates(array $data, ?Patient $ignore = null): Collection
    {
        $phone = $data['phone'] ?? null;
        $email = $data['email'] ?? null;
        $name = $data['full_name'] ?? null;
        $birth = $data['birth_date'] ?? null;

        if (! $phone && ! $email && ! ($name && $birth)) {
            return collect();
        }

        return Patient::query()
            ->whereNull('anonymized_at')
            ->when($ignore, fn (Builder $q) => $q->where('id', '!=', $ignore->id))
            ->where(function (Builder $q) use ($phone, $email, $name, $birth) {
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
                if ($email) {
                    $q->orWhere('email', $email);
                }
                if ($name && $birth) {
                    $q->orWhere(fn (Builder $w) => $w->where('full_name', $name)->whereDate('birth_date', $birth));
                }
            })
            ->orderBy('folio')
            ->limit(5)
            ->get(['id', 'folio', 'full_name', 'phone', 'email']);
    }

    /**
     * Miembros activos con rol "doctor" (los únicos que pueden ser doctor asignado).
     *
     * @return Collection<int, OrganizationMember>
     */
    public function doctors(): Collection
    {
        $roleIds = Role::query()->where('code', 'doctor')->pluck('id');

        return OrganizationMember::query()
            ->with('user:id,full_name')
            ->where('is_active', true)
            ->whereIn('role_id', $roleIds)
            ->get()
            ->sortBy(fn (OrganizationMember $m) => mb_strtolower((string) $m->user?->full_name))
            ->values();
    }

    /** @return array<string, string> id de miembro => nombre */
    public function doctorOptions(): array
    {
        return $this->doctors()->mapWithKeys(fn ($m) => [$m->id => (string) $m->user?->full_name])->all();
    }

    /**
     * Consulta del listado con edad/última visita/saldo calculados en SQL
     * (sin N+1): last_visit, invoiced_total y applied_total.
     */
    public function listingQuery(): Builder
    {
        $excluded = [FiscalStatus::Borrador->value, FiscalStatus::Cancelada->value];

        $invoicesOfPatient = fn () => Invoice::query()
            ->select('id')
            ->whereColumn('patient_id', 'patients.id')
            ->whereNotIn('fiscal_status', $excluded);

        return Patient::query()
            ->select('patients.*')
            ->addSelect([
                'last_visit' => Appointment::query()
                    ->selectRaw('MAX(starts_at)')
                    ->whereColumn('patient_id', 'patients.id')
                    ->where('status', AppointmentStatus::Completada->value),
                'invoiced_total' => Invoice::query()
                    ->selectRaw('COALESCE(SUM(total), 0)')
                    ->whereColumn('patient_id', 'patients.id')
                    ->whereNotIn('fiscal_status', $excluded),
                'applied_total' => PaymentApplication::query()
                    ->selectRaw('COALESCE(SUM(applied_amount), 0)')
                    ->whereNull('reversed_at')
                    ->whereIn('invoice_id', $invoicesOfPatient()),
            ])
            ->with('assignedDoctor.user:id,full_name');
    }

    public static function balanceOf(Patient $patient): string
    {
        $value = Money::sub($patient->invoiced_total ?? 0, $patient->applied_total ?? 0);

        return Money::cmp($value, 0) < 0 ? '0.00' : $value;
    }

    // --------------------------------------------------------------- utilidades

    public function isMinor(?Carbon $birthDate): bool
    {
        return $birthDate !== null && $birthDate->age < self::ADULT_AGE;
    }

    public static function formatPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($digits) === 10
            ? substr($digits, 0, 2).' '.substr($digits, 2, 4).' '.substr($digits, 6)
            : (string) $phone;
    }

    /** Color estable (por id) para el punto del doctor en el listado. */
    public static function doctorColor(?string $memberId): string
    {
        $palette = ['#10b981', '#f59e0b', '#2563eb', '#0ea5e9', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];

        return $palette[abs(crc32((string) $memberId)) % count($palette)];
    }

    // ----------------------------------------------------------------- privados

    private function assertEditable(Patient $patient): void
    {
        if ($patient->anonymized_at !== null) {
            throw new DomainException('El paciente fue anonimizado y ya no se puede modificar.');
        }
    }

    private function assertNoOpenWork(Patient $patient, string $verb): void
    {
        $futureAppointments = Appointment::query()
            ->where('patient_id', $patient->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, self::ACTIVE_APPOINTMENT_STATUSES))
            ->where('ends_at', '>=', now())
            ->count();

        if ($futureAppointments > 0) {
            throw new DomainException("No se puede {$verb} al paciente: tiene {$futureAppointments} cita(s) pendiente(s). Cancélalas o complétalas primero.");
        }

        $balance = $patient->pendingBalance();
        if (Money::cmp($balance, 0) > 0) {
            throw new DomainException('No se puede '.$verb.' al paciente: tiene un saldo pendiente de '.Money::format($balance).'.');
        }
    }

    private function hasValues(?array $data): bool
    {
        return $data !== null && collect($data)->filter(fn ($v) => $v !== null && $v !== '' && $v !== false)->isNotEmpty();
    }
}
