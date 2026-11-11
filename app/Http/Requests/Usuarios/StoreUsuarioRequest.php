<?php

namespace App\Http\Requests\Usuarios;

/** Alta (invitación) de usuario. Los errores se muestran en el modal "Invitar usuario". */
class StoreUsuarioRequest extends UsuarioRequest
{
    protected $errorBag = 'invite';
}
