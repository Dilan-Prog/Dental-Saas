<?php

namespace App\Http\Requests\Usuarios;

use App\Models\OrganizationMember;

class UpdateUsuarioRequest extends UsuarioRequest
{
    protected function ignoredUserId(): ?string
    {
        /** @var OrganizationMember|null $member */
        $member = $this->route('member');

        return $member?->user_id;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(['remove_signature' => $this->boolean('remove_signature')]);
    }

    public function rules(): array
    {
        return parent::rules() + ['remove_signature' => ['boolean']];
    }
}
