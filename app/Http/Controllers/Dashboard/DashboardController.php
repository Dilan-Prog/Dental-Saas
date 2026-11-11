<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $service = DashboardService::for($user);
        $canAgenda = $user->hasPermission('agenda', 'ver');
        $canMoney = $user->hasPermission('finanzas', 'ver') || $user->hasPermission('facturacion', 'ver');

        return view('dashboard.index', [
            'greeting' => $service->greeting(),
            'kpis' => $service->kpis(),
            'agenda' => $canAgenda ? $service->todayAgenda() : null,
            'upcoming' => $canAgenda ? $service->upcoming() : collect(),
            'income' => $canMoney ? $service->incomeSeries(30) : null,
            'alerts' => $service->alerts(),
            'newAppointmentUrl' => $user->hasPermission('agenda', 'crear') && Route::has('agenda.index')
                ? route('agenda.index', ['nueva' => 1]) : null,
            'reportUrl' => $user->hasPermission('finanzas', 'ver') && Route::has('finanzas.index')
                ? route('finanzas.index') : null,
            'agendaUrl' => Route::has('agenda.index') ? route('agenda.index') : null,
        ]);
    }
}
