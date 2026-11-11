<?php

/*
|--------------------------------------------------------------------------
| Menú lateral (sidebar)
|--------------------------------------------------------------------------
| Lo consume resources/views/layouts/partials/sidebar.blade.php.
| Cada ítem:
|   key     identificador único
|   label   texto visible
|   route   nombre de ruta (si la ruta aún no existe se muestra con href="#")
|   icon    nombre de icono de <x-ui.icon> (ver components/ui/icon.blade.php)
|   module  módulo de permisos: el ítem solo se muestra si
|           auth()->user()->hasPermission(module, 'ver')
|   active  patrones de Route::is() que marcan el ítem como activo
*/

return [
    'sections' => [
        [
            'title' => 'Clínica',
            'items' => [
                ['key' => 'dashboard',    'label' => 'Dashboard',    'route' => 'dashboard',          'icon' => 'layout-dashboard', 'module' => 'dashboard',    'active' => ['dashboard']],
                ['key' => 'agenda',       'label' => 'Agenda',       'route' => 'agenda.index',       'icon' => 'calendar',         'module' => 'agenda',       'active' => ['agenda.*', 'citas.*']],
                ['key' => 'pacientes',    'label' => 'Pacientes',    'route' => 'pacientes.index',    'icon' => 'user',             'module' => 'pacientes',    'active' => ['pacientes.*', 'expediente.*']],
                ['key' => 'recetas',      'label' => 'Recetas',      'route' => 'recetas.index',      'icon' => 'clipboard',        'module' => 'recetas',      'active' => ['recetas.*']],
                ['key' => 'presupuestos', 'label' => 'Presupuestos', 'route' => 'presupuestos.index', 'icon' => 'file-text',        'module' => 'presupuestos', 'active' => ['presupuestos.*', 'tratamientos.*']],
                ['key' => 'facturacion',  'label' => 'Facturación',  'route' => 'facturacion.index',  'icon' => 'receipt',          'module' => 'facturacion',  'active' => ['facturacion.*', 'pagos.*']],
                ['key' => 'finanzas',     'label' => 'Finanzas',     'route' => 'finanzas.index',     'icon' => 'trending-up',      'module' => 'finanzas',     'active' => ['finanzas.*']],
                ['key' => 'inventario',   'label' => 'Inventario',   'route' => 'inventario.index',   'icon' => 'package',          'module' => 'inventario',   'active' => ['inventario.*']],
            ],
        ],
        [
            'title' => 'Administración',
            'items' => [
                ['key' => 'configuracion', 'label' => 'Configuración', 'route' => 'configuracion.index', 'icon' => 'settings', 'module' => 'configuracion', 'active' => ['configuracion.*', 'usuarios.*', 'roles.*', 'sucursales.*', 'profile.*']],
            ],
        ],
    ],
];
