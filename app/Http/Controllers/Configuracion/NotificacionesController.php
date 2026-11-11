<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\NotificacionesRequest;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Role;
use App\Services\Configuracion\Auditor;
use App\Services\Configuracion\ConfiguracionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NotificacionesController extends Controller
{
    public function show(): View
    {
        $roles = Role::query()->where('status', 'active')->orderBy('name')->get();
        $prefs = NotificationPreference::query()->get()->keyBy(fn ($p) => $p->event_type.'|'.$p->channel->value);

        $rows = [];

        foreach (ConfiguracionCatalog::notificationEvents() as $event => $meta) {
            $pref = $prefs->get($event.'|'.$meta['channel']);
            $defaultRole = ($meta['default_role'] ?? null) ? $roles->firstWhere('code', $meta['default_role'])?->id : null;

            $rows[$event] = $meta + [
                'event' => $event,
                'enabled' => $pref ? $pref->is_enabled : true,
                'lead_value' => $pref?->lead_time_minutes ?? ($meta['lead']['default'] ?? null),
                'role_id' => $pref?->recipient_role_id ?? $defaultRole,
            ];
        }

        return view('configuracion.notificaciones', [
            'section' => 'notificaciones',
            'rows' => $rows,
            'roles' => $roles,
        ]);
    }

    public function update(NotificacionesRequest $request): RedirectResponse
    {
        $input = $request->validated('events');
        $orgId = Organization::currentId();
        $changed = [];

        DB::transaction(function () use ($input, $orgId, &$changed) {
            foreach (ConfiguracionCatalog::notificationEvents() as $event => $meta) {
                $row = $input[$event] ?? [];
                $enabled = (bool) ($row['enabled'] ?? false);

                $pref = NotificationPreference::query()->firstOrNew([
                    'event_type' => $event,
                    'channel' => $meta['channel'],
                ]);
                $pref->organization_id = $orgId;
                $pref->is_enabled = $enabled;

                if ($meta['lead']) {
                    // Al desactivar se conserva la anticipación previa (o la predeterminada).
                    $pref->lead_time_minutes = ($row['lead'] ?? null) !== null
                        ? (int) $row['lead']
                        : ($pref->lead_time_minutes ?? $meta['lead']['default']);
                }

                if ($meta['role']) {
                    $pref->recipient_role_id = ($row['role'] ?? null) ?: $pref->recipient_role_id;
                }

                if (! $pref->exists || $pref->isDirty()) {
                    $changed[] = $event;
                    $pref->save();
                }
            }

            if ($changed) {
                Auditor::log('notification_preferences', $orgId, 'update', $changed);
            }
        });

        return redirect()->route('configuracion.notificaciones')->with('success', 'Las preferencias de notificación se guardaron.');
    }
}
