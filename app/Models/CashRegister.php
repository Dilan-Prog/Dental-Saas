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
 * Tabla `cash_registers`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $name
 * @property \App\Enums\RecordStatus $status
 *
 * @property-read \App\Models\Branch|null $branch
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CashSession> $sessions
 */
class CashRegister extends BaseModel
{
    use BelongsToOrganization;

    protected $table = 'cash_registers';

    public $timestamps = false;

    protected $casts = [
        'status' => \App\Enums\RecordStatus::class,
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashSession::class, 'cash_register_id')->orderBy('opened_at');
    }

}
