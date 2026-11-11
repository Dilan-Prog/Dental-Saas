<?php

namespace App\Http\Requests\Usuarios;

/** Edición de nombre/descripción de un rol. */
class UpdateRoleRequest extends StoreRoleRequest
{
    protected $errorBag = 'role_edit';

    protected function ignoredRoleId(): ?string
    {
        return $this->route('role')?->id;
    }
}
