<?php

namespace App\Console\Commands;

use App\Models\ClinicalNote;
use App\Models\Prescription;
use App\Services\Recetas\PrescriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan qa:audit
 *
 * Auditoria de coherencia de datos del consultorio. Cada verificacion es una
 * consulta que devuelve las filas que VIOLAN la regla; sin filas = OK.
 * Exit code 1 si alguna verificacion falla (los avisos WARN no fallan).
 *
 * Uso: php artisan qa:audit [--limit=10] [--only=folios,pagos]
 */
class QaAudit extends Command
{
    protected $signature = 'qa:audit {--limit=10 : Filas de ejemplo por violacion} {--only= : Grupos separados por coma}';

    protected $description = 'Audita la coherencia de los datos (folios, totales, pagos, inventario, firmas, citas, usuarios...)';

    private int $failures = 0;

    private int $warnings = 0;

    private const EPS = '0.01';

    public function handle(): int
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        $groups = [
            'integridad' => fn () => $this->integridad(),
            'pacientes' => fn () => $this->pacientes(),
            'folios' => fn () => $this->folios(),
            'presupuestos' => fn () => $this->presupuestos(),
            'facturas' => fn () => $this->facturas(),
            'pagos' => fn () => $this->pagos(),
            'caja' => fn () => $this->caja(),
            'inventario' => fn () => $this->inventario(),
            'firmas' => fn () => $this->firmas(),
            'clinico' => fn () => $this->clinico(),
            'citas' => fn () => $this->citas(),
            'fechas' => fn () => $this->fechas(),
            'usuarios' => fn () => $this->usuarios(),
        ];

        $this->info('== Auditoria de coherencia de datos (BD: ' . DB::getDatabaseName() . ') ==');

        foreach ($groups as $name => $run) {
            if ($only && ! in_array($name, $only, true)) {
                continue;
            }
            $this->newLine();
            $this->line("<options=bold>[{$name}]</>");
            $run();
        }

        $this->newLine();
        if ($this->failures > 0) {
            $this->error("RESULTADO: {$this->failures} verificacion(es) con violaciones, {$this->warnings} aviso(s).");

            return self::FAILURE;
        }

        $this->info("RESULTADO: todo coherente ({$this->warnings} aviso(s)).");

        return self::SUCCESS;
    }

    /** @param array<int, object|array> $rows */
    private function report(string $title, array $rows, bool $warning = false): void
    {
        $count = count($rows);

        if ($count === 0) {
            $this->line("  <info>OK</info>    {$title}");

            return;
        }

        if ($warning) {
            $this->warnings++;
            $this->line("  <comment>WARN</comment>  {$title}: {$count}");
        } else {
            $this->failures++;
            $this->line("  <error>FAIL</error>  {$title}: {$count}");
        }

        foreach (array_slice($rows, 0, (int) $this->option('limit')) as $row) {
            $row = (array) $row;
            $this->line('        - ' . ($row['info'] ?? json_encode($row, JSON_UNESCAPED_UNICODE)));
        }

        if ($count > (int) $this->option('limit')) {
            $this->line('        ... y ' . ($count - (int) $this->option('limit')) . ' mas');
        }
    }

    private function q(string $sql): array
    {
        return DB::select($sql);
    }

    // ---------------------------------------------------------------- integridad

    private function integridad(): void
    {
        // FKs simples hacia tablas con organization_id: el hijo y el padre deben ser de la misma organizacion.
        $fks = DB::select("
            SELECT k.TABLE_NAME child, k.COLUMN_NAME col, k.REFERENCED_TABLE_NAME parent, k.REFERENCED_COLUMN_NAME pcol
            FROM information_schema.KEY_COLUMN_USAGE k
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
              AND k.CONSTRAINT_NAME IN (
                  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = k.TABLE_NAME AND REFERENCED_TABLE_NAME IS NOT NULL
                  GROUP BY CONSTRAINT_NAME HAVING COUNT(*) = 1)
        ");

        $orgCols = collect(DB::select("SELECT TABLE_NAME t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'organization_id'"))
            ->pluck('t')->flip();

        $bad = [];
        foreach ($fks as $fk) {
            if (! isset($orgCols[$fk->child]) || ! isset($orgCols[$fk->parent]) || $fk->parent === 'organizations') {
                continue;
            }
            $rows = $this->q("SELECT '{$fk->child}.{$fk->col} -> {$fk->parent}' info FROM `{$fk->child}` c JOIN `{$fk->parent}` p ON p.`{$fk->pcol}` = c.`{$fk->col}` WHERE c.organization_id <> p.organization_id LIMIT 5");
            array_push($bad, ...$rows);
        }
        $this->report('Referencias simples que cruzan organizaciones', $bad);

        $orgs = (int) DB::table('organizations')->count();
        $this->report('Un solo consultorio (1 organizacion)', $orgs === 1 ? [] : [['info' => "hay {$orgs} organizaciones"]]);

        $this->report('Sucursal principal activa unica', $this->q("
            SELECT CONCAT('organizacion ', organization_id, ' tiene ', COUNT(*), ' principales activas') info
            FROM branches WHERE is_main = 1 AND status = 'active' GROUP BY organization_id HAVING COUNT(*) <> 1
            UNION ALL
            SELECT CONCAT('organizacion ', o.id, ' sin sucursal principal activa') FROM organizations o
            WHERE NOT EXISTS (SELECT 1 FROM branches b WHERE b.organization_id = o.id AND b.is_main = 1 AND b.status = 'active')"));
    }

    // ---------------------------------------------------------------- pacientes

    private function pacientes(): void
    {
        $this->report('Cada paciente tiene su expediente (MedicalRecord)', $this->q("
            SELECT CONCAT('paciente ', p.folio, ' sin expediente') info FROM patients p
            LEFT JOIN medical_records m ON m.organization_id = p.organization_id AND m.patient_id = p.id WHERE m.id IS NULL"));

        $this->report('Pacientes menores de 18 con tutor', $this->q("
            SELECT CONCAT('paciente ', p.folio, ' (', TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()), ' anios) sin tutor') info
            FROM patients p WHERE p.deleted_at IS NULL AND p.anonymized_at IS NULL AND p.birth_date IS NOT NULL
              AND TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) < 18
              AND NOT EXISTS (SELECT 1 FROM patient_guardians g WHERE g.patient_id = p.id)"));

        $this->report('Fecha de nacimiento no futura', $this->q("SELECT CONCAT('paciente ', folio, ' nace ', birth_date) info FROM patients WHERE birth_date > CURDATE()"));

        $this->report('Un solo perfil fiscal por defecto por paciente', $this->q("
            SELECT CONCAT('paciente ', patient_id, ' tiene ', COUNT(*), ' perfiles por defecto') info
            FROM patient_fiscal_profiles WHERE is_default = 1 GROUP BY patient_id HAVING COUNT(*) > 1"));

        $this->report('RFC de perfiles fiscales con formato valido', $this->q("
            SELECT CONCAT('perfil ', id, ' RFC ', rfc) info FROM patient_fiscal_profiles
            WHERE NOT REGEXP_LIKE(rfc, '^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$', 'c')"));

        $this->report('Doctor asignado es un miembro activo con perfil de doctor', $this->q("
            SELECT CONCAT('paciente ', p.folio, ' doctor asignado sin perfil de doctor o inactivo') info
            FROM patients p JOIN organization_members m ON m.organization_id = p.organization_id AND m.id = p.assigned_doctor_member_id
            LEFT JOIN doctor_profiles d ON d.member_id = m.id WHERE d.member_id IS NULL OR m.is_active = 0"));
    }

    // ---------------------------------------------------------------- folios

    private function folios(): void
    {
        $map = [
            'patient' => ['patients', 'folio'],
            'prescription' => ['prescriptions', 'folio'],
            'quote' => ['quotes', 'folio'],
            'invoice' => ['invoices', 'internal_folio'],
        ];

        $dupes = $high = $prefix = $gaps = [];

        foreach (DB::table('folio_sequences')->get() as $seq) {
            if (! isset($map[$seq->document_type])) {
                continue;
            }
            [$table, $col] = $map[$seq->document_type];
            $query = DB::table($table)->where('organization_id', $seq->organization_id);
            if ($table === 'invoices') {
                // El folio interno F-#### se numera con la secuencia de la serie de la factura.
                $query->whereRaw("COALESCE(series, '') = ?", [$seq->series]);
            }
            $folios = $query->pluck($col)->all();

            $nums = [];
            foreach ($folios as $f) {
                if (! str_starts_with($f, $seq->prefix)) {
                    $prefix[] = ['info' => "{$table}.{$col} '{$f}' no empieza con el prefijo '{$seq->prefix}'"];

                    continue;
                }
                $n = (int) substr($f, strlen($seq->prefix));
                if (isset($nums[$n])) {
                    $dupes[] = ['info' => "{$table}: folio duplicado {$f}"];
                }
                $nums[$n] = true;
            }

            if ($nums) {
                $max = max(array_keys($nums));
                if ($max > (int) $seq->last_value) {
                    $high[] = ['info' => "{$table}: folio maximo {$max} > folio_sequences.last_value {$seq->last_value}"];
                }
                $missing = array_diff(range(min(array_keys($nums)), $max), array_keys($nums));
                if ($missing) {
                    $gaps[] = ['info' => "{$table}: huecos de folio (" . implode(',', array_slice($missing, 0, 8)) . ') entre ' . min(array_keys($nums)) . " y {$max}"];
                }
            }
        }

        $fiscal = $this->q("
            SELECT CONCAT(v.internal_folio, ': fiscal_folio ', v.fiscal_folio, ' > last_value ', q.`last_value`, ' de la serie ', v.series) info
            FROM invoices v JOIN folio_sequences q ON q.organization_id = v.organization_id AND q.document_type = 'invoice' AND q.series = v.series
            WHERE v.fiscal_folio IS NOT NULL AND v.fiscal_folio > q.`last_value`
            UNION ALL
            SELECT CONCAT(series, ' folio fiscal ', fiscal_folio, ' repetido ', COUNT(*), ' veces') FROM invoices
            WHERE fiscal_folio IS NOT NULL GROUP BY series, fiscal_folio HAVING COUNT(*) > 1");

        $this->report('Folios fiscales (serie) sin duplicados y dentro del contador', $fiscal);
        $this->report('Folios sin duplicados', $dupes);
        $this->report('Folios con el prefijo de su serie', $prefix);
        $this->report('Ningun folio supera folio_sequences.last_value', $high);
        $this->report('Folios consecutivos (huecos permitidos solo por borradores eliminados)', $gaps, true);
    }

    // ---------------------------------------------------------------- presupuestos

    private function presupuestos(): void
    {
        $e = self::EPS;

        $this->report('Presupuesto: total = subtotal - descuento + impuestos', $this->q("
            SELECT CONCAT(folio, ': total ', total, ' <> ', ROUND(subtotal - discount_total + tax_total, 2)) info
            FROM quotes WHERE ABS(total - (subtotal - discount_total + tax_total)) > {$e}"));

        $this->report('Presupuesto: totales = suma de sus lineas', $this->q("
            SELECT CONCAT(q.folio, ': cabecera ', q.subtotal, '/', q.discount_total, '/', q.tax_total, '/', q.total,
                          ' vs lineas ', s.sub, '/', s.dis, '/', s.tax, '/', s.tot) info
            FROM quotes q JOIN (SELECT quote_id, SUM(line_subtotal) sub, SUM(line_discount) dis, SUM(line_tax) tax, SUM(line_total) tot
                                FROM quote_items GROUP BY quote_id) s ON s.quote_id = q.id
            WHERE ABS(q.subtotal - s.sub) > {$e} OR ABS(q.discount_total - s.dis) > {$e}
               OR ABS(q.tax_total - s.tax) > {$e} OR ABS(q.total - s.tot) > {$e}"));

        $this->report('Presupuesto: linea = subtotal - descuento + impuesto', $this->q("
            SELECT CONCAT('linea ', i.line_number, ' de ', q.folio, ': ', i.line_total, ' <> ', ROUND(i.line_subtotal - i.line_discount + i.line_tax, 2)) info
            FROM quote_items i JOIN quotes q ON q.id = i.quote_id
            WHERE ABS(i.line_total - (i.line_subtotal - i.line_discount + i.line_tax)) > {$e}"));

        $this->report('Presupuesto: linea subtotal = cantidad x precio', $this->q("
            SELECT CONCAT('linea ', i.line_number, ' de ', q.folio, ': ', i.line_subtotal, ' <> ', ROUND(i.quantity * i.unit_price, 2)) info
            FROM quote_items i JOIN quotes q ON q.id = i.quote_id
            WHERE ABS(i.line_subtotal - i.quantity * i.unit_price) > {$e}"));

        $this->report('Presupuestos aprobados con plan de tratamiento', $this->q("
            SELECT CONCAT(q.folio, ' aprobado: linea ', i.line_number, ' sin PatientTreatment') info
            FROM quotes q JOIN quote_items i ON i.quote_id = q.id
            WHERE q.status = 'aprobado' AND i.treatment_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM patient_treatments t WHERE t.quote_item_id = i.id)"));

        $this->report('Presupuestos enviados/aprobados con fecha de envio', $this->q("
            SELECT CONCAT(folio, ' (', status, ') sin sent_at') info FROM quotes WHERE status IN ('enviado','aprobado','rechazado') AND sent_at IS NULL"), true);
    }

    // ---------------------------------------------------------------- facturas

    private function facturas(): void
    {
        $e = self::EPS;

        $this->report('Factura: subtotal/descuento = suma de conceptos', $this->q("
            SELECT CONCAT(v.internal_folio, ': subtotal ', v.subtotal, ' vs ', s.amt, ' / descuento ', v.discount_total, ' vs ', s.dis) info
            FROM invoices v JOIN (SELECT invoice_id, SUM(amount) amt, SUM(discount) dis FROM invoice_items GROUP BY invoice_id) s ON s.invoice_id = v.id
            WHERE ABS(v.subtotal - s.amt) > {$e} OR ABS(v.discount_total - s.dis) > {$e}"));

        $this->report('Factura: impuestos = suma de impuestos de conceptos', $this->q("
            SELECT CONCAT(v.internal_folio, ': tax_total ', v.tax_total, ' vs ', COALESCE(t.tax, 0)) info
            FROM invoices v LEFT JOIN (
                SELECT ii.invoice_id, SUM(CASE WHEN x.is_withholding = 0 THEN COALESCE(x.amount,0) ELSE 0 END) tax
                FROM invoice_item_taxes x JOIN invoice_items ii ON ii.id = x.invoice_item_id GROUP BY ii.invoice_id) t ON t.invoice_id = v.id
            WHERE ABS(v.tax_total - COALESCE(t.tax, 0)) > {$e}"));

        $this->report('Factura: total = subtotal - descuento + impuestos', $this->q("
            SELECT CONCAT(internal_folio, ': total ', total, ' <> ', ROUND(subtotal - discount_total + tax_total, 2)) info
            FROM invoices WHERE ABS(total - (subtotal - discount_total + tax_total)) > {$e}"));

        $this->report('Factura desde presupuesto: mismo total que el presupuesto', $this->q("
            SELECT CONCAT(v.internal_folio, ' total ', v.total, ' vs ', q.folio, ' ', q.total) info
            FROM invoices v JOIN quotes q ON q.id = v.quote_id WHERE ABS(v.total - q.total) > {$e}"), true);

        $this->report('UUID fiscal solo en facturas timbradas/canceladas por el PAC', $this->q("
            SELECT CONCAT(internal_folio, ' (', fiscal_status, ') con uuid_fiscal') info FROM invoices
            WHERE uuid_fiscal IS NOT NULL AND fiscal_status NOT IN ('timbrada','cancelacion_solicitada','cancelada')
            UNION ALL
            SELECT CONCAT(internal_folio, ' timbrada sin uuid_fiscal') FROM invoices WHERE fiscal_status = 'timbrada' AND uuid_fiscal IS NULL"));

        $this->report('Snapshots de emisor/receptor presentes', $this->q("
            SELECT CONCAT(internal_folio, ' sin issuer/receiver snapshot') info FROM invoices
            WHERE issuer_snapshot IS NULL OR receiver_snapshot IS NULL OR JSON_LENGTH(issuer_snapshot) = 0 OR JSON_LENGTH(receiver_snapshot) = 0"));

        $this->report('Catalogos CFDI validos (uso, forma de pago, objeto de impuesto)', $this->catalogViolations());
    }

    private function catalogViolations(): array
    {
        $uses = array_map('strval', array_keys((array) config('sat.usos_cfdi', config('sat.cfdi_uses', []))));
        $forms = array_map('strval', array_keys((array) config('sat.formas_pago', config('sat.payment_forms', []))));
        $out = [];

        if (! $uses && ! $forms) {
            return [['info' => 'config/sat.php no expone usos_cfdi/formas_pago con las claves esperadas; verificacion omitida']];
        }

        foreach (DB::table('invoices')->select('internal_folio', 'cfdi_use', 'cfdi_payment_form')->get() as $v) {
            if ($uses && ! in_array($v->cfdi_use, $uses, true)) {
                $out[] = ['info' => "{$v->internal_folio}: uso CFDI '{$v->cfdi_use}' no esta en el catalogo"];
            }
            if ($forms && ! in_array($v->cfdi_payment_form, $forms, true)) {
                $out[] = ['info' => "{$v->internal_folio}: forma de pago '{$v->cfdi_payment_form}' no esta en el catalogo"];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- pagos

    private function pagos(): void
    {
        $e = self::EPS;

        $this->report('Aplicaciones vigentes por factura <= total de la factura', $this->q("
            SELECT CONCAT(v.internal_folio, ': aplicado ', s.ap, ' > total ', v.total) info
            FROM invoices v JOIN (SELECT invoice_id, SUM(applied_amount) ap FROM payment_applications WHERE reversed_at IS NULL GROUP BY invoice_id) s ON s.invoice_id = v.id
            WHERE s.ap - v.total > {$e}"));

        $this->report('Aplicaciones vigentes por pago <= monto del pago', $this->q("
            SELECT CONCAT('pago ', p.id, ': aplicado ', s.ap, ' > monto ', p.amount) info
            FROM payments p JOIN (SELECT payment_id, SUM(applied_amount) ap FROM payment_applications WHERE reversed_at IS NULL GROUP BY payment_id) s ON s.payment_id = p.id
            WHERE s.ap - p.amount > {$e}"));

        $this->report('collection_status coherente con las aplicaciones vigentes', $this->q("
            SELECT CONCAT(v.internal_folio, ': estado ', v.collection_status, ' pero aplicado ', COALESCE(s.ap, 0), ' de ', v.total) info
            FROM invoices v LEFT JOIN (SELECT invoice_id, SUM(applied_amount) ap FROM payment_applications WHERE reversed_at IS NULL GROUP BY invoice_id) s ON s.invoice_id = v.id
            WHERE v.collection_status <> CASE
                    WHEN COALESCE(s.ap, 0) <= 0 THEN 'pendiente'
                    WHEN COALESCE(s.ap, 0) >= v.total THEN 'pagada' ELSE 'pago_parcial' END"));

        $this->report('Pago registrado = exactamente un movimiento de ingreso (mismo monto y metodo)', $this->q("
            SELECT CONCAT('pago ', p.id, ' (', p.method, ' ', p.amount, '): ', COUNT(m.id), ' movimientos') info
            FROM payments p LEFT JOIN financial_movements m ON m.payment_id = p.id AND m.direction = 'ingreso'
            WHERE p.status = 'registrado' GROUP BY p.id, p.method, p.amount HAVING COUNT(m.id) <> 1
            UNION ALL
            SELECT CONCAT('pago ', p.id, ': movimiento ', m.method, ' ', m.amount, ' difiere de ', p.method, ' ', p.amount)
            FROM payments p JOIN financial_movements m ON m.payment_id = p.id AND m.direction = 'ingreso'
            WHERE p.status = 'registrado' AND (ABS(m.amount - p.amount) > {$e} OR m.method <> p.method)"));

        $this->report('Pago en efectivo ligado a un turno de caja', $this->q("
            SELECT CONCAT('pago ', p.id, ' en efectivo sin cash_session_id') info FROM payments p WHERE p.method = 'efectivo' AND p.status = 'registrado' AND p.cash_session_id IS NULL"));

        $this->report('Pago revertido con aplicaciones revertidas', $this->q("
            SELECT CONCAT('pago ', p.id, ' revertido con aplicaciones vigentes') info FROM payments p
            JOIN payment_applications a ON a.payment_id = p.id WHERE p.status = 'revertido' AND a.reversed_at IS NULL"));

        $this->report('Pagos con idempotency_key y monto > 0', $this->q("
            SELECT CONCAT('pago ', id, ' monto ', amount) info FROM payments WHERE amount <= 0 OR idempotency_key = ''"));

        $this->report('Complementos de pago: saldo previo - pagado = saldo restante', $this->q("
            SELECT CONCAT('complemento ', id, ': ', previous_balance, ' - ', paid_amount, ' <> ', remaining_balance) info FROM payment_complements
            WHERE previous_balance IS NOT NULL AND ABS(previous_balance - paid_amount - remaining_balance) > {$e}"));
    }

    // ---------------------------------------------------------------- caja

    private function caja(): void
    {
        $e = self::EPS;

        $this->report('Turno cerrado: esperado = apertura + efectivo ingresos - egresos', $this->q("
            SELECT CONCAT('turno ', s.id, ': esperado ', s.expected_cash, ' vs calculado ',
                   ROUND(s.opening_amount + COALESCE(x.i, 0) - COALESCE(x.e, 0), 2)) info
            FROM cash_sessions s LEFT JOIN (
                SELECT cash_session_id,
                       SUM(CASE WHEN direction = 'ingreso' THEN amount ELSE 0 END) i,
                       SUM(CASE WHEN direction = 'egreso' THEN amount ELSE 0 END) e
                FROM financial_movements WHERE method = 'efectivo' GROUP BY cash_session_id) x ON x.cash_session_id = s.id
            WHERE s.status = 'cerrada'
              AND ABS(s.expected_cash - (s.opening_amount + COALESCE(x.i, 0) - COALESCE(x.e, 0))) > {$e}"));

        $this->report('Turno cerrado: diferencia = contado - esperado', $this->q("
            SELECT CONCAT('turno ', id, ': diferencia ', difference, ' <> ', ROUND(counted_cash - expected_cash, 2)) info FROM cash_sessions
            WHERE status = 'cerrada' AND (counted_cash IS NULL OR expected_cash IS NULL OR ABS(difference - (counted_cash - expected_cash)) > {$e})"));

        $this->report('Turno cerrado con quien/cuando cerro; abiertos sin cierre', $this->q("
            SELECT CONCAT('turno ', id, ' cerrado sin closed_at/closed_by o closed_at < opened_at') info FROM cash_sessions
            WHERE status = 'cerrada' AND (closed_at IS NULL OR closed_by IS NULL OR closed_at < opened_at)
            UNION ALL
            SELECT CONCAT('turno ', id, ' abierto con datos de cierre') FROM cash_sessions
            WHERE status = 'abierta' AND (closed_at IS NOT NULL OR counted_cash IS NOT NULL)"));

        $this->report('Movimientos en efectivo ligados a un turno', $this->q("
            SELECT CONCAT('movimiento ', id, ' efectivo ', amount, ' sin cash_session_id') info FROM financial_movements
            WHERE method = 'efectivo' AND cash_session_id IS NULL"), true);

        $this->report('Una sola sesion abierta por caja', $this->q("
            SELECT CONCAT('caja ', cash_register_id, ' con ', COUNT(*), ' turnos abiertos') info FROM cash_sessions WHERE status = 'abierta' GROUP BY cash_register_id HAVING COUNT(*) > 1"));

        $this->report('Reversiones: mismo monto, direccion contraria y una sola vez', $this->q("
            SELECT CONCAT('reversion ', r.id, ' de ', o.id, ' inconsistente') info
            FROM financial_movements r JOIN financial_movements o ON o.id = r.reversed_of_id
            WHERE r.direction = o.direction OR ABS(r.amount - o.amount) > {$e}
            UNION ALL
            SELECT CONCAT('movimiento ', reversed_of_id, ' revertido ', COUNT(*), ' veces') FROM financial_movements
            WHERE reversed_of_id IS NOT NULL GROUP BY reversed_of_id HAVING COUNT(*) > 1"));

        $this->report('Categoria del movimiento con la misma direccion', $this->q("
            SELECT CONCAT('movimiento ', m.id, ' (', m.direction, ') con categoria ', c.name, ' (', c.direction, ')') info
            FROM financial_movements m JOIN financial_categories c ON c.id = m.category_id WHERE c.direction <> m.direction"));
    }

    // ---------------------------------------------------------------- inventario

    private function inventario(): void
    {
        $e = '0.0001';

        $this->report('Stock = suma de movimientos por (sucursal, insumo)', $this->q("
            SELECT CONCAT('sucursal ', COALESCE(s.branch_id, m.branch_id), ' insumo ', COALESCE(s.item_id, m.item_id),
                          ': stock ', COALESCE(s.quantity_on_hand, 'NULL'), ' vs movimientos ', COALESCE(m.q, 0)) info
            FROM inventory_stock s
            LEFT JOIN (SELECT branch_id, item_id, SUM(quantity) q FROM inventory_movements GROUP BY branch_id, item_id) m
                   ON m.branch_id = s.branch_id AND m.item_id = s.item_id
            WHERE ABS(s.quantity_on_hand - COALESCE(m.q, 0)) > {$e}
            UNION ALL
            SELECT CONCAT('movimientos sin fila de stock: sucursal ', m.branch_id, ' insumo ', m.item_id)
            FROM (SELECT branch_id, item_id FROM inventory_movements GROUP BY branch_id, item_id) m
            LEFT JOIN inventory_stock s ON s.branch_id = m.branch_id AND s.item_id = m.item_id WHERE s.item_id IS NULL"));

        $this->report('Stock >= 0 y costos >= 0', $this->q("
            SELECT CONCAT('stock negativo/costo negativo: ', branch_id, '/', item_id) info FROM inventory_stock WHERE quantity_on_hand < 0 OR avg_unit_cost < 0 OR min_quantity < 0
            UNION ALL
            SELECT CONCAT('movimiento ', id, ' con costo negativo') FROM inventory_movements WHERE unit_cost < 0"));

        $this->report('Signo del movimiento coherente con su tipo', $this->q("
            SELECT CONCAT('movimiento ', id, ' ', movement_type, ' cantidad ', quantity) info FROM inventory_movements
            WHERE (movement_type IN ('entrada','inventario_inicial') AND quantity <= 0) OR (movement_type = 'salida' AND quantity >= 0)"));

        $this->report('Un solo inventario inicial por sucursal+insumo', $this->q("
            SELECT CONCAT('sucursal ', branch_id, ' insumo ', item_id, ' con ', COUNT(*), ' iniciales') info FROM inventory_movements
            WHERE movement_type = 'inventario_inicial' GROUP BY branch_id, item_id HAVING COUNT(*) > 1"));

        $this->report('Reversiones apuntan a un movimiento con cantidad opuesta', $this->q("
            SELECT CONCAT('reversion ', r.id, ' (', r.quantity, ') de ', o.id, ' (', o.quantity, ')') info
            FROM inventory_movements r JOIN inventory_movements o ON o.id = r.reverses_movement_id
            WHERE ABS(r.quantity + o.quantity) > {$e} OR r.item_id <> o.item_id OR r.branch_id <> o.branch_id"));

        $this->report('Lotes: suma de lotes <= stock del insumo', $this->q("
            SELECT CONCAT('sucursal ', l.branch_id, ' insumo ', l.item_id, ': lotes ', SUM(l.quantity_on_hand), ' > stock ', COALESCE(s.quantity_on_hand, 0)) info
            FROM inventory_lots l LEFT JOIN inventory_stock s ON s.branch_id = l.branch_id AND s.item_id = l.item_id
            GROUP BY l.branch_id, l.item_id, s.quantity_on_hand HAVING SUM(l.quantity_on_hand) - COALESCE(s.quantity_on_hand, 0) > {$e}"), true);
    }

    // ---------------------------------------------------------------- firmas

    private function firmas(): void
    {
        $badNotes = [];
        foreach (ClinicalNote::withoutGlobalScopes()->where('status', 'firmada')->get() as $n) {
            if (! $n->signed_at || ! $n->signed_by_member_id || ! $n->content_hash) {
                $badNotes[] = ['info' => "nota {$n->id} firmada sin signed_at/signed_by/content_hash"];
            } elseif (! hash_equals((string) $n->content_hash, $n->computeHash())) {
                $badNotes[] = ['info' => "nota {$n->id}: content_hash no coincide con su contenido"];
            }
        }
        $this->report('Notas firmadas con hash recomputable', $badNotes);

        $this->report('Notas en borrador sin datos de firma', $this->q("
            SELECT CONCAT('nota ', id, ' en borrador con signed_at/content_hash') info FROM clinical_notes
            WHERE status = 'borrador' AND (signed_at IS NOT NULL OR signed_by_member_id IS NOT NULL OR content_hash IS NOT NULL)"));

        $this->report('Solo el doctor responsable firma la nota', $this->q("
            SELECT CONCAT('nota ', id, ' firmada por otro miembro que el responsable') info FROM clinical_notes
            WHERE status = 'firmada' AND signed_by_member_id <> responsible_doctor_member_id"), true);

        $service = app(PrescriptionService::class);
        $badRx = [];
        foreach (Prescription::withoutGlobalScopes()->with('items')->where('status', 'firmada')->get() as $rx) {
            if (! $rx->signed_at || ! $rx->content_hash || ! $rx->doctor_name_snapshot || ! $rx->doctor_license_snapshot) {
                $badRx[] = ['info' => "receta {$rx->folio} firmada sin firma/hash/snapshot del doctor"];
            } elseif (! $service->verifyIntegrity($rx)) {
                $badRx[] = ['info' => "receta {$rx->folio}: content_hash no coincide con su contenido"];
            }
            if ($rx->items->isEmpty()) {
                $badRx[] = ['info' => "receta {$rx->folio} firmada sin medicamentos"];
            }
        }
        $this->report('Recetas firmadas con hash recomputable, snapshot del doctor y medicamentos', $badRx);

        $this->report('Recetas: lineas consecutivas desde 1', $this->q("
            SELECT CONCAT('receta ', p.folio, ': lineas ', COUNT(*), ' pero maximo ', MAX(i.line_number)) info
            FROM prescriptions p JOIN prescription_items i ON i.prescription_id = p.id GROUP BY p.id, p.folio HAVING MAX(i.line_number) <> COUNT(*)"));

        $this->report('Adendas: versiones consecutivas y solo sobre notas firmadas', $this->q("
            SELECT CONCAT('adenda ', a.id, ' sobre nota no firmada') info FROM clinical_note_addenda a JOIN clinical_notes n ON n.id = a.note_id WHERE n.status <> 'firmada'
            UNION ALL
            SELECT CONCAT('nota ', note_id, ': versiones ', COUNT(*), ' maximo ', MAX(version_number)) FROM clinical_note_addenda GROUP BY note_id HAVING MAX(version_number) <> COUNT(*)"));
    }

    // ---------------------------------------------------------------- clinico

    private function clinico(): void
    {
        $this->report('Odontograma: un hallazgo vigente por pieza y paciente', $this->q("
            SELECT CONCAT('paciente ', patient_id, ' pieza ', tooth_fdi, ': ', COUNT(*), ' vigentes') info FROM tooth_findings
            WHERE superseded_at IS NULL GROUP BY patient_id, tooth_fdi HAVING COUNT(*) > 1"));

        $this->report('Odontograma: pieza FDI valida para su denticion', $this->q("
            SELECT CONCAT('hallazgo ', id, ' pieza ', tooth_fdi, ' denticion ', dentition) info FROM tooth_findings
            WHERE NOT ((dentition = 'permanente' AND tooth_fdi MOD 10 BETWEEN 1 AND 8 AND tooth_fdi DIV 10 BETWEEN 1 AND 4)
                    OR (dentition = 'temporal' AND tooth_fdi MOD 10 BETWEEN 1 AND 5 AND tooth_fdi DIV 10 BETWEEN 5 AND 8))"));

        $this->report('Odontograma: historial cronologico (superseded_at >= recorded_at)', $this->q("
            SELECT CONCAT('hallazgo ', id, ' cerrado antes de registrarse') info FROM tooth_findings WHERE superseded_at IS NOT NULL AND superseded_at < recorded_at"));

        $this->report('Hallazgos: el paciente coincide con el de la consulta', $this->q("
            SELECT CONCAT('hallazgo ', f.id, ' paciente distinto al de su consulta') info FROM tooth_findings f
            JOIN clinical_encounters e ON e.id = f.encounter_id JOIN medical_records m ON m.id = e.medical_record_id WHERE m.patient_id <> f.patient_id"));

        $this->report('Consulta con cita: mismo paciente, doctor y sucursal que la cita', $this->q("
            SELECT CONCAT('consulta ', e.id, ' vs cita ', a.id, ': ',
                   IF(m.patient_id <> a.patient_id, 'paciente ', ''), IF(e.doctor_member_id <> a.doctor_member_id, 'doctor ', ''),
                   IF(e.branch_id <> a.branch_id, 'sucursal', '')) info
            FROM clinical_encounters e JOIN appointments a ON a.id = e.appointment_id JOIN medical_records m ON m.id = e.medical_record_id
            WHERE m.patient_id <> a.patient_id OR e.doctor_member_id <> a.doctor_member_id OR e.branch_id <> a.branch_id"));

        $this->report('Citas en curso/completadas tienen su consulta', $this->q("
            SELECT CONCAT('cita ', a.id, ' (', a.status, ') sin consulta') info FROM appointments a
            WHERE a.status IN ('en_curso','completada') AND NOT EXISTS (SELECT 1 FROM clinical_encounters e WHERE e.appointment_id = a.id)"));

        $this->report('Nota: autor/doctor de la consulta coherentes con la cita de la nota', $this->q("
            SELECT CONCAT('nota ', n.id, ': cita de la nota difiere de la cita de su consulta') info FROM clinical_notes n
            JOIN clinical_encounters e ON e.id = n.encounter_id
            WHERE n.appointment_id IS NOT NULL AND e.appointment_id IS NOT NULL AND n.appointment_id <> e.appointment_id"), true);

        $this->report('Archivos clinicos: tamanio > 0 y storage_key unico', $this->q("
            SELECT CONCAT('archivo ', id, ' tamanio ', size_bytes) info FROM clinical_files WHERE size_bytes <= 0"));

        $this->report('Tratamiento realizado con fecha de termino', $this->q("
            SELECT CONCAT('tratamiento ', id, ' realizado sin completed_at') info FROM patient_treatments WHERE status = 'realizado' AND completed_at IS NULL
            UNION ALL
            SELECT CONCAT('tratamiento ', id, ' con completed_at < started_at') FROM patient_treatments WHERE completed_at < started_at"));
    }

    // ---------------------------------------------------------------- citas

    private function citas(): void
    {
        $active = "('pendiente','confirmada','en_curso')";

        $this->report('Citas activas sin empalme de doctor', $this->q("
            SELECT CONCAT('citas ', a.id, ' y ', b.id, ' del mismo doctor se empalman') info
            FROM appointments a JOIN appointments b ON a.doctor_member_id = b.doctor_member_id AND a.id < b.id
            WHERE a.status IN {$active} AND b.status IN {$active} AND a.starts_at < b.ends_at AND a.ends_at > b.starts_at"));

        $this->report('Citas activas sin empalme de paciente', $this->q("
            SELECT CONCAT('citas ', a.id, ' y ', b.id, ' del mismo paciente se empalman') info
            FROM appointments a JOIN appointments b ON a.patient_id = b.patient_id AND a.id < b.id
            WHERE a.status IN {$active} AND b.status IN {$active} AND a.starts_at < b.ends_at AND a.ends_at > b.starts_at"));

        $this->report('Cita: inicio < fin', $this->q("SELECT CONCAT('cita ', id) info FROM appointments WHERE ends_at <= starts_at"));

        $this->report('Doctor de la cita tiene perfil de doctor (DoctorProfile)', $this->q("
            SELECT CONCAT('cita ', a.id, ': el doctor no tiene doctor_profile') info
            FROM appointments a JOIN organization_members m ON m.id = a.doctor_member_id LEFT JOIN doctor_profiles d ON d.member_id = m.id
            WHERE d.member_id IS NULL"));

        $this->report('Cita: paciente dado de baja con citas activas futuras', $this->q("
            SELECT CONCAT('cita ', a.id, ' activa de paciente dado de baja ', p.folio) info FROM appointments a
            JOIN patients p ON p.id = a.patient_id WHERE p.deleted_at IS NOT NULL AND a.status IN {$active} AND a.starts_at > NOW()"));

        $this->report('Historial de citas: ultimo estado = estado actual', $this->q("
            SELECT CONCAT('cita ', a.id, ': estado ', a.status, ' vs ultimo historial ', h.to_status) info
            FROM appointments a JOIN appointment_status_history h ON h.appointment_id = a.id
            WHERE h.created_at = (SELECT MAX(created_at) FROM appointment_status_history x WHERE x.appointment_id = a.id)
              AND h.to_status <> a.status AND (SELECT COUNT(*) FROM appointment_status_history y WHERE y.appointment_id = a.id AND y.created_at = h.created_at) = 1"), true);

        $this->report('Citas canceladas con motivo en el historial', $this->q("
            SELECT CONCAT('cita ', a.id, ' cancelada sin motivo') info FROM appointments a
            WHERE a.status = 'cancelada' AND EXISTS (SELECT 1 FROM appointment_status_history h WHERE h.appointment_id = a.id)
              AND NOT EXISTS (SELECT 1 FROM appointment_status_history h WHERE h.appointment_id = a.id AND h.to_status = 'cancelada' AND h.reason IS NOT NULL AND h.reason <> '')"), true);
    }

    // ---------------------------------------------------------------- fechas

    private function fechas(): void
    {
        $this->report('Firmas posteriores a la creacion (notas y recetas)', $this->q("
            SELECT CONCAT('nota ', id, ' firmada ', signed_at, ' antes de crearse ', created_at) info FROM clinical_notes WHERE signed_at IS NOT NULL AND signed_at < created_at
            UNION ALL
            SELECT CONCAT('receta ', folio, ' firmada ', signed_at, ' antes de crearse ', created_at) FROM prescriptions WHERE signed_at IS NOT NULL AND signed_at < created_at"));

        $this->report('Pagos no anteriores a la emision de la factura aplicada', $this->q("
            SELECT CONCAT('pago ', p.id, ' (', p.paid_at, ') anterior a factura ', v.internal_folio, ' (', COALESCE(v.issued_at, v.created_at), ')') info
            FROM payment_applications a JOIN payments p ON p.id = a.payment_id JOIN invoices v ON v.id = a.invoice_id
            WHERE DATE(p.paid_at) < DATE(COALESCE(v.issued_at, v.created_at))"));

        $this->report('Timbrado no anterior a la emision', $this->q("
            SELECT CONCAT(internal_folio, ' timbrada antes de emitirse') info FROM invoices WHERE stamped_at IS NOT NULL AND issued_at IS NOT NULL AND stamped_at < issued_at"));

        $this->report('Fechas no futuras en hechos consumados (pagos, movimientos, recetas)', $this->q("
            SELECT CONCAT('pago ', id, ' fechado en el futuro ', paid_at) info FROM payments WHERE paid_at > NOW() + INTERVAL 1 DAY
            UNION ALL SELECT CONCAT('movimiento ', id, ' fechado en el futuro ', occurred_on) FROM financial_movements WHERE occurred_on > CURDATE()
            UNION ALL SELECT CONCAT('receta ', folio, ' emitida en el futuro ', issued_on) FROM prescriptions WHERE issued_on > CURDATE()"));

        $this->report('Presupuesto: fecha no posterior al envio', $this->q("
            SELECT CONCAT(folio, ' enviado ', sent_at, ' antes de su fecha ', quote_date) info FROM quotes WHERE sent_at IS NOT NULL AND DATE(sent_at) < quote_date"), true);

        $this->report('Lotes no caducados con stock', $this->q("
            SELECT CONCAT('lote ', lot_code, ' caducado ', expires_on, ' con ', quantity_on_hand, ' unidades') info FROM inventory_lots WHERE expires_on < CURDATE() AND quantity_on_hand > 0"), true);
    }

    // ---------------------------------------------------------------- usuarios

    private function usuarios(): void
    {
        $this->report('Usuarios activos con membresia activa y rol activo', $this->q("
            SELECT CONCAT('usuario ', u.email, ': ', IF(m.id IS NULL, 'sin membresia', IF(m.is_active = 0, 'membresia inactiva', IF(m.role_id IS NULL, 'sin rol', 'rol inactivo')))) info
            FROM users u LEFT JOIN organization_members m ON m.user_id = u.id LEFT JOIN roles r ON r.id = m.role_id
            WHERE u.is_active = 1 AND u.password IS NOT NULL AND (m.id IS NULL OR m.is_active = 0 OR m.role_id IS NULL OR r.status <> 'active')"));

        $this->report('Usuarios inactivos sin membresia activa', $this->q("
            SELECT CONCAT('usuario inactivo ', u.email, ' con membresia activa') info FROM users u JOIN organization_members m ON m.user_id = u.id
            WHERE u.is_active = 0 AND m.is_active = 1"), true);

        $this->report('Al menos un administrador activo', $this->q("
            SELECT 'no hay ningun usuario admin_sistema activo' info WHERE NOT EXISTS (
                SELECT 1 FROM organization_members m JOIN roles r ON r.id = m.role_id JOIN users u ON u.id = m.user_id
                WHERE r.is_system_admin = 1 AND m.is_active = 1 AND u.is_active = 1)"));

        $this->report('Roles no administradores con al menos un permiso', $this->q("
            SELECT CONCAT('rol ', r.code, ' sin permisos') info FROM roles r
            WHERE r.is_system_admin = 0 AND r.status = 'active' AND NOT EXISTS (SELECT 1 FROM role_permissions p WHERE p.role_id = r.id)"), true);

        $this->report('Permisos: solo con "ver" si tiene otra accion del modulo', $this->q("
            SELECT CONCAT('rol ', r.code, ' tiene ', p.module, '.', p.action, ' sin ', p.module, '.ver') info
            FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
            WHERE r.is_system_admin = 0 AND p.action <> 'ver' AND NOT EXISTS (
                SELECT 1 FROM role_permissions rp2 JOIN permissions p2 ON p2.id = rp2.permission_id
                WHERE rp2.role_id = rp.role_id AND p2.module = p.module AND p2.action = 'ver')"), true);

        $this->report('Doctores activos con perfil (cedula) para emitir recetas', $this->q("
            SELECT CONCAT('doctor ', u.email, ' sin cedula profesional') info FROM organization_members m
            JOIN roles r ON r.id = m.role_id JOIN users u ON u.id = m.user_id LEFT JOIN doctor_profiles d ON d.member_id = m.id
            WHERE r.code = 'doctor' AND m.is_active = 1 AND (d.member_id IS NULL OR d.professional_license IS NULL OR d.professional_license = '')"), true);

        $this->report('Correos de usuarios unicos sin distinguir mayusculas', $this->q("
            SELECT CONCAT('correo duplicado ', MIN(LOWER(email))) info FROM users GROUP BY LOWER(email) HAVING COUNT(*) > 1"));
    }
}
