<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $enums = [
        'record_status' => ['active', 'inactive'],
        'branch_status' => ['active', 'inactive'],
        'sex_type' => ['female', 'male', 'other', 'unspecified'],
        'appointment_status' => ['pendiente', 'confirmada', 'en_curso', 'completada', 'cancelada', 'no_asistio'],
        'note_type' => ['simple', 'soap'],
        'signing_status' => ['borrador', 'firmada'],
        'tooth_condition' => ['sano', 'caries', 'endodoncia', 'restauracion', 'ausente'],
        'dentition_type' => ['permanente', 'temporal'],
        'clinical_file_type' => ['radiografia', 'consentimiento', 'fotografia_intraoral', 'otro'],
        'quote_status' => ['borrador', 'enviado', 'aprobado', 'rechazado'],
        'planned_treatment_status' => ['planeado', 'en_progreso', 'realizado', 'cancelado'],
        'fiscal_status' => ['borrador', 'pendiente_timbrado', 'timbrada', 'cancelacion_solicitada', 'cancelada', 'error'],
        'collection_status' => ['pendiente', 'pago_parcial', 'pagada'],
        'cfdi_payment_method' => ['PUE', 'PPD'],
        'payment_method' => ['efectivo', 'tarjeta', 'transferencia', 'domiciliado'],
        'payment_status' => ['registrado', 'revertido'],
        'cfdi_type' => ['I', 'E', 'P'],
        'pac_request_kind' => ['timbrado', 'cancelacion', 'consulta_estatus'],
        'pac_request_status' => ['pendiente', 'enviado', 'exitoso', 'error', 'reintento'],
        'cancellation_status' => ['solicitada', 'aceptada', 'rechazada', 'en_proceso'],
        'movement_direction' => ['ingreso', 'egreso'],
        'cash_session_status' => ['abierta', 'cerrada'],
        'inventory_movement_type' => ['inventario_inicial', 'entrada', 'salida', 'ajuste', 'reversion'],
        'notification_channel' => ['in_app', 'email', 'whatsapp'],
        'outbox_status' => ['pendiente', 'procesando', 'enviado', 'fallido'],
        'delivery_status' => ['pendiente', 'enviado', 'entregado', 'fallido'],
    ];

    public function up(): void
    {
        foreach (['pgcrypto', 'citext', 'btree_gist', 'pg_trgm'] as $ext) {
            DB::unprepared("CREATE EXTENSION IF NOT EXISTS {$ext}");
        }

        foreach ($this->enums as $name => $values) {
            $list = implode(', ', array_map(fn ($v) => "'{$v}'", $values));
            DB::unprepared("CREATE TYPE {$name} AS ENUM ({$list})");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->enums) as $name) {
            DB::unprepared("DROP TYPE IF EXISTS {$name} CASCADE");
        }
    }
};
