<?php

namespace Database\Seeders;

use App\Enums\CashSessionStatus;
use App\Enums\MovementDirection;
use App\Enums\PaymentMethod;
use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionAdjustment;
use App\Models\FinancialCategory;
use App\Models\FinancialMovement;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finanzas\MovementService;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Datos demo de Finanzas: categorias, caja, 6 meses de movimientos manuales,
 * dos turnos cerrados (uno con faltante y su ajuste), un turno abierto hoy y una reversion.
 * Idempotente: no duplica nada si ya existe.
 */
class DemoFinanzasSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::current();
        $admin = User::query()->where('email', 'admin@dental.test')->first();
        $branch = Branch::query()->where('is_main', true)->first() ?? Branch::query()->first();

        if (! $org || ! $admin || ! $branch) {
            return;
        }

        $cat = $this->categories();
        $register = CashRegister::query()->firstOrCreate(
            ['branch_id' => $branch->id, 'name' => 'Caja principal'],
            ['status' => RecordStatus::Active->value]
        );

        if (! FinancialMovement::query()->where('concept', 'Renta consultorio')->exists()) {
            $this->monthlyMovements($cat, $branch, $admin);
        }

        if (! CashSession::query()->exists()) {
            $this->cashSessions($cat, $register, $branch, $admin);
        }

        if (! FinancialMovement::query()->where('concept', 'Compra duplicada de resina (captura repetida)')->exists()) {
            $dup = $this->movement($cat['Insumos'], $branch, $admin, 'Compra duplicada de resina (captura repetida)', now()->subDays(4)->toDateString(), '1240.00', PaymentMethod::Transferencia);
            app(MovementService::class)->reverse($dup, 'Captura duplicada', $admin);
        }
    }

    /** @return array<string, FinancialCategory> */
    private function categories(): array
    {
        $defs = [
            ['Tratamientos', MovementDirection::Ingreso],
            ['Otros ingresos', MovementDirection::Ingreso],
            ['Nómina', MovementDirection::Egreso],
            ['Insumos', MovementDirection::Egreso],
            ['Renta', MovementDirection::Egreso],
            ['Servicios', MovementDirection::Egreso],
            ['Laboratorio', MovementDirection::Egreso],
            ['Mantenimiento', MovementDirection::Egreso],
            ['Papelería y oficina', MovementDirection::Egreso],
        ];

        $out = [];
        foreach ($defs as [$name, $direction]) {
            $out[$name] = FinancialCategory::query()->firstOrCreate(
                ['name' => $name, 'direction' => $direction->value],
                ['status' => RecordStatus::Active->value]
            );
        }

        return $out;
    }

    /** Renta, nomina, servicios, insumos, laboratorio e ingresos varios de los ultimos 6 meses. */
    private function monthlyMovements(array $cat, Branch $branch, User $admin): void
    {
        $today = now()->startOfDay();

        for ($back = 5; $back >= 0; $back--) {
            $month = $today->copy()->startOfMonth()->subMonthsNoOverflow($back);
            $seed = (int) $month->format('Ym');

            $rows = [
                [1, 'Renta', 'Renta consultorio', '18500.00', PaymentMethod::Domiciliado],
                [1, 'Nómina', 'Nómina primera quincena', '28000.00', PaymentMethod::Transferencia],
                [15, 'Nómina', 'Nómina segunda quincena', '28000.00', PaymentMethod::Transferencia],
                [5, 'Servicios', 'Luz, agua e internet', $this->vary(3200, $seed, 300), PaymentMethod::Transferencia],
                [8, 'Insumos', 'Compra de insumos dentales', $this->vary(7600, $seed + 1, 1800), PaymentMethod::Transferencia],
                [12, 'Laboratorio', 'Trabajos de laboratorio protésico', $this->vary(4200, $seed + 2, 900), PaymentMethod::Tarjeta],
                [20, 'Mantenimiento', 'Mantenimiento de unidad dental', $this->vary(1800, $seed + 3, 400), PaymentMethod::Tarjeta],
            ];

            foreach ($rows as [$day, $catName, $concept, $amount, $method]) {
                $date = $month->copy()->day(min($day, $month->daysInMonth));
                if ($date->gt($today)) {
                    continue;
                }
                $this->movement($cat[$catName], $branch, $admin, $concept, $date->toDateString(), $amount, $method);
            }

            $income = $month->copy()->day(min(18, $month->daysInMonth));
            if ($income->lte($today)) {
                $this->movement($cat['Otros ingresos'], $branch, $admin, 'Venta de productos de higiene bucal', $income->toDateString(), $this->vary(1500, $seed + 4, 700), PaymentMethod::Tarjeta);
            }
        }
    }

    /** Dos turnos cerrados (uno con faltante corregido por ajuste) y uno abierto hoy. */
    private function cashSessions(array $cat, CashRegister $register, Branch $branch, User $admin): void
    {
        $today = now()->startOfDay();

        // Turno A: cuadra exacto.
        $a = $this->openSession($register, $admin, $today->copy()->subDays(3)->setTime(9, 0), '1000.00');
        $this->cashMovement($a, $cat['Otros ingresos'], $branch, $admin, 'Venta de cepillos y pastas', '1500.00', MovementDirection::Ingreso, $a->opened_at->copy()->setTime(11, 30));
        $this->cashMovement($a, $cat['Papelería y oficina'], $branch, $admin, 'Compra de papelería', '350.00', MovementDirection::Egreso, $a->opened_at->copy()->setTime(13, 0));
        $this->closeSession($a, $admin, $today->copy()->subDays(3)->setTime(19, 0), null, null);

        // Turno B: faltante de $20 que luego se corrige con un ajuste.
        $b = $this->openSession($register, $admin, $today->copy()->subDays(2)->setTime(9, 0), '1000.00');
        $this->cashMovement($b, $cat['Otros ingresos'], $branch, $admin, 'Venta de enjuague bucal', '800.00', MovementDirection::Ingreso, $b->opened_at->copy()->setTime(12, 0));
        $this->cashMovement($b, $cat['Servicios'], $branch, $admin, 'Pago a mensajería', '200.00', MovementDirection::Egreso, $b->opened_at->copy()->setTime(15, 0));
        $this->closeSession($b, $admin, $today->copy()->subDays(2)->setTime(19, 0), '20.00', 'Faltante por cambio entregado de más.');
        CashSessionAdjustment::create([
            'cash_session_id' => $b->id,
            'amount' => '-20.00',
            'reason' => 'Propina entregada a mensajero y no registrada en el turno',
            'created_by' => $admin->id,
            'created_at' => $today->copy()->subDay()->setTime(10, 0),
        ]);

        // Turno abierto hoy.
        $openedAt = now()->copy()->setTime(8, 0)->min(now());
        $c = $this->openSession($register, $admin, $openedAt, '1000.00');
        $this->cashMovement($c, $cat['Otros ingresos'], $branch, $admin, 'Venta de cepillos dentales', '450.00', MovementDirection::Ingreso, $openedAt->copy()->addMinutes(5)->min(now()));
        $this->cashMovement($c, $cat['Papelería y oficina'], $branch, $admin, 'Compra de agua purificada', '120.00', MovementDirection::Egreso, $openedAt->copy()->addMinutes(10)->min(now()));
    }

    private function openSession(CashRegister $register, User $admin, $openedAt, string $opening): CashSession
    {
        return CashSession::create([
            'cash_register_id' => $register->id,
            'opened_by' => $admin->id,
            'opened_at' => $openedAt,
            'opening_amount' => $opening,
            'status' => CashSessionStatus::Abierta->value,
        ]);
    }

    /** Cierra el turno; $shortfall = faltante a restar del esperado para simular un descuadre. */
    private function closeSession(CashSession $session, User $admin, $closedAt, ?string $shortfall, ?string $notes): void
    {
        $expected = $session->expectedCash();
        $counted = $shortfall ? Money::sub($expected, $shortfall) : $expected;

        $session->update([
            'status' => CashSessionStatus::Cerrada->value,
            'closed_by' => $admin->id,
            'closed_at' => $closedAt,
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'difference' => Money::sub($counted, $expected),
            'notes' => $notes,
        ]);
    }

    private function cashMovement(CashSession $session, FinancialCategory $category, Branch $branch, User $admin, string $concept, string $amount, MovementDirection $direction, $at): FinancialMovement
    {
        return FinancialMovement::create([
            'branch_id' => $branch->id,
            'direction' => $direction->value,
            'category_id' => $category->id,
            'concept' => $concept,
            'occurred_on' => $at->toDateString(),
            'amount' => $amount,
            'currency' => 'MXN',
            'method' => PaymentMethod::Efectivo->value,
            'cash_session_id' => $session->id,
            'created_by' => $admin->id,
            'created_at' => $at,
        ]);
    }

    private function movement(FinancialCategory $category, Branch $branch, User $admin, string $concept, string $date, string $amount, PaymentMethod $method): FinancialMovement
    {
        return FinancialMovement::create([
            'branch_id' => $branch->id,
            'direction' => $category->direction->value,
            'category_id' => $category->id,
            'concept' => $concept,
            'occurred_on' => $date,
            'amount' => $amount,
            'currency' => 'MXN',
            'method' => $method->value,
            'created_by' => $admin->id,
            'created_at' => $date.' 10:00:00',
        ]);
    }

    /** Importe determinista base ± variacion (misma semilla = mismos datos). */
    private function vary(int $base, int $seed, int $spread): string
    {
        mt_srand($seed);
        $value = $base + mt_rand(-$spread, $spread);

        return number_format(max($value, 100), 2, '.', '');
    }
}
