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
 * Tabla `member_branches`.
 *
 * @property string $organization_id
 * @property string $member_id
 * @property string $branch_id
 *
 * @property-read \App\Models\OrganizationMember|null $member
 * @property-read \App\Models\Branch|null $branch
 */
class MemberBranch extends BasePivotModel
{
    use BelongsToOrganization;

    protected $table = 'member_branches';

    protected $primaryKey = null;

    protected array $compositeKey = ['member_id', 'branch_id'];

    public $timestamps = false;

    public function member(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'member_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

}
