<?php

namespace App\Http\Requests\Configuracion;

use App\Services\Configuracion\ConfiguracionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificacionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = [];

        foreach (ConfiguracionCatalog::notificationEvents() as $event => $meta) {
            $row = (array) ($this->input("events.$event") ?? []);
            $row['enabled'] = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $row['lead'] = isset($row['lead']) && $row['lead'] !== '' ? $row['lead'] : null;
            $row['role'] = isset($row['role']) && $row['role'] !== '' ? $row['role'] : null;
            $rows[$event] = $row;
        }

        $this->merge(['events' => $rows]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['events' => ['required', 'array']];

        foreach (ConfiguracionCatalog::notificationEvents() as $event => $meta) {
            $rules["events.$event.enabled"] = ['boolean'];

            if ($meta['lead']) {
                $rules["events.$event.lead"] = [
                    Rule::requiredIf(fn () => (bool) $this->input("events.$event.enabled")),
                    'nullable', 'integer', 'min:'.$meta['lead']['min'], 'max:'.$meta['lead']['max'],
                ];
            }

            if ($meta['role']) {
                $rules["events.$event.role"] = [
                    Rule::requiredIf(fn () => (bool) $this->input("events.$event.enabled")),
                    'nullable',
                    Rule::exists('roles', 'id')->where('organization_id', \App\Models\Organization::currentId())->where('status', 'active'),
                ];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [];

        foreach (ConfiguracionCatalog::notificationEvents() as $event => $meta) {
            $label = mb_strtolower($meta['label']);

            if ($meta['lead']) {
                $messages["events.$event.lead.required"] = "Indica la anticipación en minutos para «{$meta['label']}».";
                $messages["events.$event.lead.integer"] = "La anticipación de «{$meta['label']}» debe ser un número entero de minutos.";
                $messages["events.$event.lead.min"] = "La anticipación de «{$meta['label']}» debe ser de al menos {$meta['lead']['min']} minutos.";
                $messages["events.$event.lead.max"] = "La anticipación de «{$meta['label']}» no puede superar {$meta['lead']['max']} minutos.";
            }

            if ($meta['role']) {
                $messages["events.$event.role.required"] = "Selecciona el rol destinatario de «{$meta['label']}».";
                $messages["events.$event.role.exists"] = "El rol seleccionado para «{$meta['label']}» no es válido.";
            }
        }

        return $messages;
    }
}
