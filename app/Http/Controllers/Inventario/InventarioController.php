<?php

namespace App\Http\Controllers\Inventario;

use App\Enums\InventoryMovementType;
use App\Http\Controllers\Controller;
use App\Services\Inventario\InventarioReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pantalla principal del inventario: Resumen (insumos con existencias) y
 * Transacciones (movimientos), mas exportacion CSV de la vista activa.
 */
class InventarioController extends Controller
{
    public function __construct(private readonly InventarioReport $report)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $branches = $this->report->branches();
        $f = $this->filters($request, $branches);

        $data = [
            'f' => $f,
            'branches' => $branches,
            'categories' => $this->report->categories(),
            'suppliers' => $this->report->suppliers(),
            'can' => [
                'create' => $user->hasPermission('inventario', 'crear'),
                'edit' => $user->hasPermission('inventario', 'editar'),
                'export' => $user->hasPermission('inventario', 'exportar'),
            ],
            'kpis' => $this->report->kpis($f['branch']),
        ];

        if ($f['vista'] === 'transacciones') {
            $data['movements'] = $this->report->movements($f);
        } else {
            $data['items'] = $this->report->items($f);
        }

        if ($data['can']['create']) {
            $data += $this->modalData();
        }

        return view('inventario.index', $data);
    }

    public function export(Request $request): StreamedResponse
    {
        $branches = $this->report->branches();
        $f = $this->filters($request, $branches);
        $vista = $f['vista'];
        $name = 'inventario-' . ($vista === 'transacciones' ? 'transacciones' : 'resumen') . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($f, $vista) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel respete UTF-8

            if ($vista === 'transacciones') {
                fputcsv($out, ['Fecha', 'Insumo', 'SKU', 'Sucursal', 'Tipo', 'Cantidad', 'Unidad', 'Costo unitario', 'Lote', 'Referencia', 'Motivo', 'Usuario']);
                $this->report->movementsQuery($f)->lazy(500)->each(function ($m) use ($out) {
                    fputcsv($out, [
                        $m->occurred_at->format('Y-m-d H:i'),
                        self::safe($m->item?->name),
                        self::safe($m->item?->sku),
                        self::safe($m->branch?->name),
                        $m->movement_type->label(),
                        rtrim(rtrim((string) $m->quantity, '0'), '.'),
                        self::safe($m->item?->unit_of_measure),
                        $m->unit_cost !== null ? number_format((float) $m->unit_cost, 4, '.', '') : '',
                        self::safe($m->lot?->lot_code),
                        self::safe($m->reference),
                        self::safe($m->reason),
                        self::safe($m->actor?->full_name),
                    ]);
                });
            } else {
                fputcsv($out, ['Insumo', 'SKU', 'Categoría', 'Proveedor', 'Unidad', 'Existencia', 'Mínimo', 'Costo unitario', 'Valor', 'Ubicación', 'Estado']);
                $this->report->itemsQuery($f)->lazy(500)->each(function ($i) use ($out) {
                    $qty = (float) $i->stock_qty;
                    $cost = (float) $i->unit_cost;
                    fputcsv($out, [
                        self::safe($i->name),
                        self::safe($i->sku),
                        self::safe($i->category),
                        self::safe($i->supplier?->name),
                        self::safe($i->unit_of_measure),
                        rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') ?: '0',
                        rtrim(rtrim(number_format((float) $i->min_qty, 4, '.', ''), '0'), '.') ?: '0',
                        number_format($cost, 4, '.', ''),
                        number_format($qty * $cost, 2, '.', ''),
                        self::safe($i->locations),
                        $i->status->label(),
                    ]);
                });
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Filtros saneados desde la query string (valores invalidos se ignoran). */
    private function filters(Request $request, $branches): array
    {
        $v = Validator::make($request->query(), [
            'vista' => ['nullable', 'in:resumen,transacciones'],
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'uuid'],
            'sucursal' => ['nullable', 'string', 'max:40'],
            'filter' => ['nullable', 'in:bajo,agotado,caducan'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'type' => ['nullable', 'in:' . implode(',', array_column(InventoryMovementType::cases(), 'value'))],
            'item' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $clean = $v->fails() ? array_diff_key($request->query(), $v->errors()->toArray()) : $request->query();

        return [
            'vista' => $clean['vista'] ?? 'resumen',
            'q' => $clean['q'] ?? '',
            'category' => $clean['category'] ?? '',
            'supplier' => $clean['supplier'] ?? '',
            'branch' => $this->report->resolveBranch($clean['sucursal'] ?? null, $branches),
            'filter' => $clean['filter'] ?? '',
            'status' => $clean['status'] ?? 'active',
            'type' => $clean['type'] ?? '',
            'item' => $clean['item'] ?? '',
            'from' => $clean['from'] ?? '',
            'to' => $clean['to'] ?? '',
        ];
    }

    /** Datos que necesitan los modales de movimientos (entrada, salida, ajuste, nuevo insumo). */
    public function modalData(): array
    {
        return [
            'modalItems' => $this->report->activeItems(),
            'encounters' => $this->report->recentEncounters(),
            'openLots' => $this->report->openLots(),
        ];
    }

    /** Evita inyeccion de formulas al abrir el CSV en Excel. */
    public static function safe(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
