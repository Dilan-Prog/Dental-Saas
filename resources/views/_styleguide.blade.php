{{-- Guía de estilo / catálogo de componentes. Solo disponible en APP_ENV=local en /_styleguide. --}}
<x-app-layout title="Guía de estilo" :breadcrumbs="['Guía de estilo']">
    <x-ui.page-header title="Guía de estilo" subtitle="Catálogo de componentes del sistema de diseño (CSS plano + Blade).">
        <x-slot name="actions">
            <x-ui.button variant="secondary" icon="download">Exportar</x-ui.button>
            <x-ui.button icon="plus" data-modal-open="demo-modal">Abrir modal</x-ui.button>
        </x-slot>
    </x-ui.page-header>

    <div class="stack stack--lg">

        <x-ui.card title="Botones">
            <div class="row row--wrap">
                <x-ui.button>Primario</x-ui.button>
                <x-ui.button variant="secondary">Secundario</x-ui.button>
                <x-ui.button variant="ghost">Ghost</x-ui.button>
                <x-ui.button variant="danger">Peligro</x-ui.button>
                <x-ui.button variant="danger-outline">Peligro outline</x-ui.button>
                <x-ui.button size="sm" icon="plus">Pequeño</x-ui.button>
                <x-ui.button size="lg">Grande</x-ui.button>
                <x-ui.button disabled>Deshabilitado</x-ui.button>
                <x-ui.button loading>Cargando</x-ui.button>
                <x-ui.button variant="secondary" icon="edit" icon-only aria-label="Editar" />
                <x-ui.button href="#" variant="secondary" icon="external-link">Enlace</x-ui.button>
            </div>
        </x-ui.card>

        <div class="grid grid-4">
            <x-ui.kpi label="Citas hoy" value="8" delta="+2 vs. ayer (6)" trend="up" />
            <x-ui.kpi label="Ingresos del día" value="$14,350" delta="+18% vs. ayer" trend="up" />
            <x-ui.kpi label="Pacientes nuevos (mes)" value="23" delta="-3 vs. mes anterior" trend="down" />
            <x-ui.kpi label="Facturas por cobrar" value="$28,400" delta="6 facturas pendientes" />
        </div>

        <div class="grid grid-2">
            <x-ui.card title="Formulario" subtitle="Campos con validación y máscaras">
                <form class="form-grid" method="POST" action="#" onsubmit="return false">
                    <x-ui.field name="sg_nombre" label="Nombre completo" required placeholder="Renata Aguilar Vega" class="col-span-2" />
                    <x-ui.field name="sg_tel" label="Teléfono" mask="phone" placeholder="55 1234 5678" icon="phone" />
                    <x-ui.field name="sg_rfc" label="RFC" mask="rfc" placeholder="XAXX010101000" hint="Se convierte a mayúsculas." />
                    <x-ui.field name="sg_sexo" label="Sexo" as="select" :options="['female' => 'Femenino', 'male' => 'Masculino', 'other' => 'Otro']" placeholder="Selecciona" />
                    <x-ui.field name="sg_nac" label="Nacimiento" type="date" />
                    <x-ui.field name="sg_notas" label="Notas" as="textarea" rows="3" class="col-span-2" />
                    <x-ui.field label="Campo deshabilitado" name="sg_dis"><x-ui.input name="sg_dis" value="No editable" disabled /></x-ui.field>
                    <div class="field">
                        <span class="field__label">Campo con error</span>
                        <input class="input has-error" value="valor inválido">
                        <div class="field__error"><x-ui.icon name="alert-triangle" size="sm" />El RFC no tiene un formato válido.</div>
                    </div>
                    <div class="stack stack--sm col-span-2">
                        <x-ui.checkbox name="sg_chk" label="Acepto el aviso de privacidad" :checked="true" />
                        <x-ui.switch name="sg_sw" label="Recordatorios por WhatsApp" :checked="true" />
                        <div class="row"><x-ui.radio name="sg_rd" value="a" label="Efectivo" :checked="true" /><x-ui.radio name="sg_rd" value="b" label="Tarjeta" /></div>
                    </div>
                </form>
            </x-ui.card>

            <div class="stack">
                <x-ui.card title="Badges y avatares">
                    <div class="row row--wrap">
                        <x-ui.badge variant="warning" dot>Pendiente</x-ui.badge>
                        <x-ui.badge variant="info" dot>Confirmada</x-ui.badge>
                        <x-ui.badge variant="primary" dot>En curso</x-ui.badge>
                        <x-ui.badge variant="success" dot>Completada</x-ui.badge>
                        <x-ui.badge variant="danger" dot>Cancelada</x-ui.badge>
                        <x-ui.badge>Neutral</x-ui.badge>
                        <x-ui.avatar name="Dra. Mariana Reyes" />
                        <x-ui.avatar name="Lucía Fonseca" size="sm" soft />
                        <x-ui.avatar name="Roberto Mendoza" size="lg" />
                    </div>
                </x-ui.card>

                <x-ui.card title="Alertas y toasts">
                    <div class="stack stack--sm">
                        <x-ui.alert title="Información">Tu plan incluye 3 usuarios.</x-ui.alert>
                        <x-ui.alert variant="success">Cambios guardados.</x-ui.alert>
                        <x-ui.alert variant="warning">Stock bajo en guantes de nitrilo (M).</x-ui.alert>
                        <x-ui.alert variant="danger">No se pudo timbrar la factura.</x-ui.alert>
                        <div class="row row--wrap mt-2">
                            <x-ui.button size="sm" variant="secondary" onclick="UI.toast('Paciente guardado', 'success')">Toast éxito</x-ui.button>
                            <x-ui.button size="sm" variant="secondary" onclick="UI.toast('Revisa los datos', 'error', 'Error')">Toast error</x-ui.button>
                            <x-ui.button size="sm" variant="secondary" onclick="UI.confirm('¿Eliminar el registro?').then(function(r){UI.toast(r ? 'Confirmado' : 'Cancelado', 'info')})">Confirmar</x-ui.button>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card title="Dropdown y confirm-form">
                    <div class="row">
                        <x-ui.dropdown>
                            <x-slot name="trigger"><x-ui.button variant="secondary" icon="more-horizontal">Acciones</x-ui.button></x-slot>
                            <a href="#" class="dropdown-item"><x-ui.icon name="edit" /> Editar</a>
                            <a href="#" class="dropdown-item"><x-ui.icon name="printer" /> Imprimir</a>
                            <div class="dropdown-sep"></div>
                            <button type="button" class="dropdown-item dropdown-item--danger"><x-ui.icon name="trash" /> Eliminar</button>
                        </x-ui.dropdown>
                        <x-ui.confirm-form action="#" method="POST" message="¿Dar de baja este paciente?" label="Dar de baja" icon="trash" />
                    </div>
                </x-ui.card>
            </div>
        </div>

        <x-ui.card title="Tabs (paneles)" subtitle="Cambian en la misma página">
            <x-ui.tabs :items="['historial' => 'Historial', 'notas' => ['label' => 'Notas', 'count' => 4], 'archivos' => 'Archivos', 'odonto' => 'Odontograma']" active="historial" hash>
                <x-ui.tab name="historial">Contenido del historial clínico.</x-ui.tab>
                <x-ui.tab name="notas">Notas SOAP del paciente.</x-ui.tab>
                <x-ui.tab name="archivos">Radiografías y consentimientos.</x-ui.tab>
                <x-ui.tab name="odonto">Odontograma interactivo.</x-ui.tab>
            </x-ui.tabs>
        </x-ui.card>

        <x-ui.card flush title="Tabla" subtitle="Con toolbar, badges, acciones y paginación">
            <div class="table-toolbar">
                <div class="input-wrap"><x-ui.icon name="search" size="sm" /><input class="input" placeholder="Buscar paciente..."></div>
                <div class="segmented"><button class="is-active" type="button">Todos</button><button type="button">Activos</button><button type="button">Inactivos</button></div>
            </div>
            <x-ui.table clickable>
                <x-slot name="head">
                    <tr><th>Paciente</th><th>Tratamiento</th><th>Estado</th><th class="text-right">Total</th><th class="actions"></th></tr>
                </x-slot>
                <tr>
                    <td><div class="row"><x-ui.avatar name="Renata Aguilar" size="sm" soft /><div><div class="fw-600">Renata Aguilar Vega</div><div class="text-muted text-xs">PAC-0481</div></div></div></td>
                    <td>Limpieza dental</td>
                    <td><x-ui.badge variant="info" dot>Confirmada</x-ui.badge></td>
                    <td class="text-right mono">$800.00</td>
                    <td class="actions"><x-ui.button variant="ghost" size="sm" icon="eye" icon-only aria-label="Ver" /><x-ui.button variant="ghost" size="sm" icon="edit" icon-only aria-label="Editar" /></td>
                </tr>
                <tr>
                    <td><div class="row"><x-ui.avatar name="Daniela Vargas" size="sm" soft /><div><div class="fw-600">Daniela Vargas Pineda</div><div class="text-muted text-xs">PAC-0482</div></div></div></td>
                    <td>Revisión</td>
                    <td><x-ui.badge variant="warning" dot>Pendiente</x-ui.badge></td>
                    <td class="text-right mono">$500.00</td>
                    <td class="actions"><x-ui.button variant="ghost" size="sm" icon="eye" icon-only aria-label="Ver" /><x-ui.button variant="ghost" size="sm" icon="edit" icon-only aria-label="Editar" /></td>
                </tr>
            </x-ui.table>
            @php $pg = new \Illuminate\Pagination\LengthAwarePaginator(collect(range(1, 5)), 57, 5, 3, ['path' => url('/_styleguide')]); @endphp
            {{ $pg->links() }}
        </x-ui.card>

        <div class="grid grid-2">
            <x-ui.card title="Estado vacío"><x-ui.empty-state icon="user" title="Aún no hay pacientes" text="Crea el primero para comenzar."><x-ui.button icon="plus" size="sm">Nuevo paciente</x-ui.button></x-ui.empty-state></x-ui.card>
            <x-ui.card title="Skeleton"><div class="stack stack--sm"><div class="skeleton" style="height:18px;width:60%"></div><div class="skeleton" style="height:14px"></div><div class="skeleton" style="height:14px;width:80%"></div></div></x-ui.card>
        </div>
    </div>

    <x-ui.modal id="demo-modal" title="Nueva cita" subtitle="Ejemplo de modal con formulario" action="#" method="POST" size="md">
        <div class="form-grid">
            <x-ui.field name="m_pac" label="Paciente" as="select" :options="['1' => 'Renata Aguilar Vega']" placeholder="Selecciona" class="col-span-2" required />
            <x-ui.field name="m_fecha" label="Fecha" type="date" required />
            <x-ui.field name="m_hora" label="Hora" type="time" required />
        </div>
        <x-slot name="footer">
            <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
            <x-ui.button type="button" data-modal-close>Guardar</x-ui.button>
        </x-slot>
    </x-ui.modal>
</x-app-layout>
