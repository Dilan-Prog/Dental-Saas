<?php

namespace App\Models;

use App\Enums\SigningStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `clinical_notes`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $encounter_id
 * @property \App\Enums\NoteType $note_type
 * @property string $author_member_id
 * @property string $responsible_doctor_member_id
 * @property string|null $treatment_id
 * @property int|null $tooth_fdi
 * @property string|null $appointment_id
 * @property string|null $subjective
 * @property string|null $objective
 * @property string|null $analysis
 * @property string|null $plan
 * @property string|null $prescription_text
 * @property \App\Enums\SigningStatus $status
 * @property \Illuminate\Support\Carbon|null $signed_at
 * @property string|null $signed_by_member_id
 * @property string|null $content_hash
 * @property string|null $signature_mechanism
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\ClinicalEncounter|null $encounter
 * @property-read \App\Models\OrganizationMember|null $author
 * @property-read \App\Models\OrganizationMember|null $responsibleDoctor
 * @property-read \App\Models\Treatment|null $treatment
 * @property-read \App\Models\Appointment|null $appointment
 * @property-read \App\Models\OrganizationMember|null $signedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ClinicalNoteAddendum> $addenda
 */
class ClinicalNote extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'clinical_notes';

    public const UPDATED_AT = null;

    protected $casts = [
        'note_type' => \App\Enums\NoteType::class,
        'tooth_fdi' => 'integer',
        'status' => \App\Enums\SigningStatus::class,
        'signed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'author_member_id');
    }

    public function responsibleDoctor(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'responsible_doctor_member_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'treatment_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'signed_by_member_id');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(ClinicalNoteAddendum::class, 'note_id')->orderBy('version_number');
    }

    public function isSigned(): bool
    {
        return $this->status === SigningStatus::Firmada;
    }

    /** SHA-256 del contenido clínico de la nota (lo que se firma). */
    public function computeHash(): string
    {
        return hash('sha256', json_encode([
            $this->encounter_id, $this->note_type?->value, $this->author_member_id,
            $this->responsible_doctor_member_id, $this->treatment_id, $this->tooth_fdi,
            $this->subjective, $this->objective, $this->analysis, $this->plan, $this->prescription_text,
        ], JSON_UNESCAPED_UNICODE));
    }

}
