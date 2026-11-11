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
 * Tabla `folio_sequences`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $document_type
 * @property string $series
 * @property string $prefix
 * @property int $last_value
 *
 */
class FolioSequence extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'folio_sequences';

    public $timestamps = false;

    protected $casts = [
        'last_value' => 'integer',
    ];

    /**
     * Siguiente folio atómico (p. ej. PAC-0481). Debe existir la secuencia (document_type, series).
     */
    public static function next(string $documentType, string $series = ''): string
    {
        return DB::transaction(function () use ($documentType, $series) {
            $seq = static::query()
                ->where('document_type', $documentType)
                ->where('series', $series)
                ->lockForUpdate()
                ->firstOrFail();

            $seq->last_value = $seq->last_value + 1;
            $seq->save();

            return $seq->prefix.str_pad((string) $seq->last_value, 4, '0', STR_PAD_LEFT);
        });
    }

}
