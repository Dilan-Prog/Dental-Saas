<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base para tablas sin UUID propio: llave simple no generada (organization_id, member_id)
 * o llave compuesta (definida en $compositeKey), p. ej. inventory_stock, member_branches.
 */
abstract class BasePivotModel extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Columnas que forman la llave primaria compuesta (vacío si la llave es simple). */
    protected array $compositeKey = [];

    protected function setKeysForSaveQuery($query)
    {
        return $this->applyCompositeKey($query) ?? parent::setKeysForSaveQuery($query);
    }

    protected function setKeysForSelectQuery($query)
    {
        return $this->applyCompositeKey($query) ?? parent::setKeysForSelectQuery($query);
    }

    private function applyCompositeKey(Builder $query): ?Builder
    {
        if ($this->compositeKey === []) {
            return null;
        }
        foreach ($this->compositeKey as $column) {
            $query->where($column, '=', $this->getOriginal($column, $this->getAttribute($column)));
        }

        return $query;
    }
}
