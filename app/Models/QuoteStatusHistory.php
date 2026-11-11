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
 * Tabla `quote_status_history`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $quote_id
 * @property \App\Enums\QuoteStatus|null $from_status
 * @property \App\Enums\QuoteStatus $to_status
 * @property string|null $actor_user_id
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read \App\Models\Quote|null $quote
 * @property-read \App\Models\User|null $actor
 */
class QuoteStatusHistory extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'quote_status_history';

    public const UPDATED_AT = null;

    protected $casts = [
        'from_status' => \App\Enums\QuoteStatus::class,
        'to_status' => \App\Enums\QuoteStatus::class,
        'created_at' => 'datetime',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

}
