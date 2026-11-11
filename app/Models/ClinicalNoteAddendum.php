<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Tabla `clinical_note_addenda`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $note_id
 * @property int $version_number
 * @property string $content
 * @property string $reason
 * @property string $author_member_id
 * @property string $content_hash
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\ClinicalNote|null $note
 * @property-read \App\Models\OrganizationMember|null $author
 */
class ClinicalNoteAddendum extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'clinical_note_addenda';

    public const UPDATED_AT = null;

    protected $casts = [
        'version_number' => 'integer',
        'created_at' => 'datetime',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(ClinicalNote::class, 'note_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'author_member_id');
    }

}
