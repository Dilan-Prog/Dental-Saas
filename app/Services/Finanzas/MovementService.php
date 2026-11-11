<?php

namespace App\Services\Finanzas;

use App\Enums\MovementDirection;
use App\Enums\PaymentMethod;
use App\Enums\RecordStatus;
use App\Models\FinancialCategory;
use App\Models\FinancialMovement;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Movimientos financieros manuales y sus reversiones.
 *  - Los movimientos que vienen de un pago de paciente (payment_id) son de solo lectura:
 *    los crea y revierte Facturacion; aqui nunca se duplican ni se editan.
 *  - Un movimiento nunca se edita ni se borra: se revierte con el movimiento contrapuesto.
 */
class MovementService
{
    public const DISK = 'local';

    /** Nombres de las categorias de sistema usadas por las reversiones (direccion contraria). */
    public const REVERSAL_CATEGORY = [
        'ingreso' => 'Reversión de ingreso',  // se registra como egreso
        'egreso' => 'Reversión de egreso',    // se registra como ingreso
    ];

    public function __construct(private readonly CashSessionService $cash)
    {
    }

    /** @param array<string, mixed> $data datos ya validados por StoreMovementRequest */
    public function createManual(array $data, User $user, ?UploadedFile $receipt = null): FinancialMovement
    {
        $bag = 'movement';

        if (! empty($data['submission_key']) && ! Cache::add('finanzas:submission:'.$data['submission_key'], true, 600)) {
            throw ValidationException::withMessages(['concept' => 'Esta transaccion ya fue registrada (envio duplicado).'])->errorBag($bag);
        }

        $storedKey = null;

        try {
            return DB::transaction(function () use ($data, $user, $receipt, $bag, &$storedKey) {
                $direction = MovementDirection::from($data['direction']);
                $method = PaymentMethod::from($data['method']);

                $category = FinancialCategory::query()->active()->find($data['category_id']);
                if (! $category || $category->direction !== $direction) {
                    $this->fail('category_id', 'La categoria no corresponde al tipo de movimiento.', $bag);
                }

                $amount = Money::round($data['amount']);
                $session = null;

                if ($method === PaymentMethod::Efectivo) {
                    $session = $this->cash->sessionForCashMovement($data['branch_id'] ?? null, $data['cash_session_id'] ?? null, $bag);
                    $this->assertEnoughCash($session->expectedCash(), $direction, $amount, $bag);
                }

                if ($receipt) {
                    $storedKey = $receipt->store('finanzas/receipts/'.now()->format('Y/m'), self::DISK);
                }

                return FinancialMovement::create([
                    'branch_id' => $data['branch_id'] ?? null,
                    'direction' => $direction->value,
                    'category_id' => $category->id,
                    'concept' => trim($data['concept']),
                    'occurred_on' => $data['occurred_on'],
                    'amount' => $amount,
                    'currency' => Organization::current()?->currency ?? 'MXN',
                    'method' => $method->value,
                    'cash_session_id' => $session?->id,
                    'receipt_storage_key' => $storedKey,
                    'created_by' => $user->id,
                ]);
            });
        } catch (QueryException $e) {
            if ($storedKey) {
                Storage::disk(self::DISK)->delete($storedKey);
            }
            $this->failFromDatabase($e, $bag);
        } catch (\Throwable $e) {
            if ($storedKey) {
                Storage::disk(self::DISK)->delete($storedKey);
            }
            if (! empty($data['submission_key'])) {
                Cache::forget('finanzas:submission:'.$data['submission_key']);
            }
            throw $e;
        }
    }

    /** Crea el movimiento contrapuesto (reversed_of_id) de un movimiento manual. */
    public function reverse(FinancialMovement $movement, string $reason, User $user): FinancialMovement
    {
        $bag = 'reverse';

        try {
            return DB::transaction(function () use ($movement, $reason, $user, $bag) {
                $original = FinancialMovement::query()->lockForUpdate()->findOrFail($movement->id);

                if ($original->payment_id !== null) {
                    $this->fail('reason', 'Los movimientos generados por pagos de pacientes se revierten desde Facturacion (reversion del pago).', $bag);
                }
                if ($original->reversed_of_id !== null) {
                    $this->fail('reason', 'Una reversion no se puede revertir.', $bag);
                }
                if ($original->reversal()->exists()) {
                    $this->fail('reason', 'Este movimiento ya fue revertido.', $bag);
                }

                $opposite = $original->direction === MovementDirection::Ingreso ? MovementDirection::Egreso : MovementDirection::Ingreso;
                $category = $this->reversalCategory($original->direction);

                $session = null;
                if ($original->method === PaymentMethod::Efectivo) {
                    // La reversion de efectivo cae en el turno ABIERTO (el original puede estar en uno cerrado).
                    $preferred = $original->cash_session_id && $original->cashSession?->status->value === 'abierta'
                        ? $original->cash_session_id : null;
                    $session = $this->cash->sessionForCashMovement($original->branch_id, $preferred, $bag);
                    $this->assertEnoughCash($session->expectedCash(), $opposite, (string) $original->amount, $bag);
                }

                $concept = mb_substr('Reversión: '.$original->concept.' — '.trim($reason), 0, 300);

                return FinancialMovement::create([
                    'branch_id' => $original->branch_id,
                    'direction' => $opposite->value,
                    'category_id' => $category->id,
                    'concept' => $concept,
                    'occurred_on' => now()->toDateString(),
                    'amount' => $original->amount,
                    'currency' => $original->currency,
                    'method' => $original->method->value,
                    'cash_session_id' => $session?->id,
                    'created_by' => $user->id,
                    'reversed_of_id' => $original->id,
                ]);
            });
        } catch (QueryException $e) {
            $this->failFromDatabase($e, $bag);
        }
    }

    /** Categoria de sistema (de la direccion contraria al original), creada bajo demanda. */
    public function reversalCategory(MovementDirection $originalDirection): FinancialCategory
    {
        $opposite = $originalDirection === MovementDirection::Ingreso ? MovementDirection::Egreso : MovementDirection::Ingreso;
        $name = self::REVERSAL_CATEGORY[$originalDirection->value];

        $category = FinancialCategory::query()->where('name', $name)->where('direction', $opposite->value)->first();

        return $category ?? FinancialCategory::create([
            'name' => $name,
            'direction' => $opposite->value,
            'status' => RecordStatus::Active->value,
        ]);
    }

    public static function isSystemCategoryName(string $name): bool
    {
        return in_array(mb_strtolower($name), array_map('mb_strtolower', array_values(self::REVERSAL_CATEGORY)), true);
    }

    private function assertEnoughCash(string $expected, MovementDirection $direction, string $amount, string $bag): void
    {
        if ($direction === MovementDirection::Egreso && Money::cmp($expected, $amount) < 0) {
            $this->fail('amount', 'El efectivo disponible en caja ('.Money::format($expected).') no alcanza para este egreso de '.Money::format($amount).'.', $bag);
        }
    }

    private function fail(string $field, string $message, string $bag): never
    {
        throw ValidationException::withMessages([$field => $message])->errorBag($bag);
    }

    private function failFromDatabase(QueryException $e, string $bag): never
    {
        $message = (string) $e->getMessage();

        if (str_contains($message, 'chk_fin_movements_amount')) {
            $this->fail('amount', 'El monto debe ser mayor a cero.', $bag);
        }
        if (str_contains($message, 'Duplicate entry') && str_contains($message, 'uq_fin_movements_org_payment')) {
            $this->fail('concept', 'Este pago ya tiene su movimiento registrado.', $bag);
        }
        if (str_contains($message, 'Sesion de caja cerrada')) {
            $this->fail('method', 'El turno de caja ya esta cerrado.', $bag);
        }

        throw $e;
    }
}
