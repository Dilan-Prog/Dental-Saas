<?php

/*
|--------------------------------------------------------------------------
| Configuración del módulo Agenda
|--------------------------------------------------------------------------
| hours.start / hours.end : horario de atención (hora entera, fin exclusivo).
|                           Una cita debe iniciar >= start y terminar <= end.
| step                    : granularidad (min) de los horarios sugeridos.
| row_height              : alto en px de una hora en la cuadrícula.
*/

return [
    'hours' => [
        'start' => 8,
        'end' => 21,
    ],
    'step' => 15,
    'min_duration' => 15,
    'max_duration' => 480,
    'default_duration' => 30,
    'row_height' => 56,
];
