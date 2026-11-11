<?php

/**
 * Catálogos del SAT para CFDI 4.0 (subconjunto relevante para clínica dental).
 * Formato: clave => descripción.
 */
return [

    'regimenes' => [
        '601' => 'General de Ley Personas Morales',
        '603' => 'Personas Morales con Fines no Lucrativos',
        '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
        '606' => 'Arrendamiento',
        '607' => 'Régimen de Enajenación o Adquisición de Bienes',
        '608' => 'Demás ingresos',
        '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
        '611' => 'Ingresos por Dividendos (socios y accionistas)',
        '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
        '614' => 'Ingresos por intereses',
        '615' => 'Régimen de los ingresos por obtención de premios',
        '616' => 'Sin obligaciones fiscales',
        '620' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
        '621' => 'Incorporación Fiscal',
        '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
        '623' => 'Opcional para Grupos de Sociedades',
        '624' => 'Coordinados',
        '625' => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
        '626' => 'Régimen Simplificado de Confianza',
    ],

    'usos_cfdi' => [
        'G01' => 'Adquisición de mercancías',
        'G02' => 'Devoluciones, descuentos o bonificaciones',
        'G03' => 'Gastos en general',
        'I01' => 'Construcciones',
        'I02' => 'Mobiliario y equipo de oficina por inversiones',
        'I03' => 'Equipo de transporte',
        'I04' => 'Equipo de cómputo y accesorios',
        'I05' => 'Dados, troqueles, moldes, matrices y herramental',
        'I06' => 'Comunicaciones telefónicas',
        'I07' => 'Comunicaciones satelitales',
        'I08' => 'Otra maquinaria y equipo',
        'D01' => 'Honorarios médicos, dentales y gastos hospitalarios',
        'D02' => 'Gastos médicos por incapacidad o discapacidad',
        'D03' => 'Gastos funerales',
        'D04' => 'Donativos',
        'D05' => 'Intereses reales por créditos hipotecarios',
        'D06' => 'Aportaciones voluntarias al SAR',
        'D07' => 'Primas por seguros de gastos médicos',
        'D08' => 'Gastos de transportación escolar obligatoria',
        'D09' => 'Depósitos en cuentas para el ahorro, primas de pensiones',
        'D10' => 'Pagos por servicios educativos (colegiaturas)',
        'S01' => 'Sin efectos fiscales',
        'CP01' => 'Pagos',
        'CN01' => 'Nómina',
    ],

    'formas_pago' => [
        '01' => 'Efectivo',
        '02' => 'Cheque nominativo',
        '03' => 'Transferencia electrónica de fondos',
        '04' => 'Tarjeta de crédito',
        '05' => 'Monedero electrónico',
        '06' => 'Dinero electrónico',
        '08' => 'Vales de despensa',
        '12' => 'Dación en pago',
        '13' => 'Pago por subrogación',
        '14' => 'Pago por consignación',
        '15' => 'Condonación',
        '17' => 'Compensación',
        '23' => 'Novación',
        '24' => 'Confusión',
        '25' => 'Remisión de deuda',
        '26' => 'Prescripción o caducidad',
        '27' => 'A satisfacción del acreedor',
        '28' => 'Tarjeta de débito',
        '29' => 'Tarjeta de servicios',
        '30' => 'Aplicación de anticipos',
        '31' => 'Intermediario pagos',
        '99' => 'Por definir',
    ],

    // Relación entre método de pago interno y forma de pago SAT por defecto.
    'forma_pago_por_metodo' => [
        'efectivo' => '01',
        'tarjeta' => '04',
        'transferencia' => '03',
        'domiciliado' => '03',
    ],

    'metodos_pago' => [
        'PUE' => 'Pago en una sola exhibición',
        'PPD' => 'Pago en parcialidades o diferido',
    ],

    'motivos_cancelacion' => [
        '01' => 'Comprobante emitido con errores con relación',
        '02' => 'Comprobante emitido con errores sin relación',
        '03' => 'No se llevó a cabo la operación',
        '04' => 'Operación nominativa relacionada en una factura global',
    ],

    'tipos_relacion' => [
        '01' => 'Nota de crédito de los documentos relacionados',
        '02' => 'Nota de débito de los documentos relacionados',
        '03' => 'Devolución de mercancía sobre facturas o traslados previos',
        '04' => 'Sustitución de los CFDI previos',
        '07' => 'CFDI por aplicación de anticipo',
    ],

    'tipos_comprobante' => [
        'I' => 'Ingreso',
        'E' => 'Egreso',
        'P' => 'Pago',
    ],

    'unidades' => [
        'E48' => 'Unidad de servicio',
        'H87' => 'Pieza',
        'ACT' => 'Actividad',
        'KGM' => 'Kilogramo',
        'LTR' => 'Litro',
        'XBX' => 'Caja',
        'XPK' => 'Paquete',
    ],

    'objeto_impuesto' => [
        '01' => 'No objeto de impuesto',
        '02' => 'Sí objeto de impuesto',
        '03' => 'Sí objeto del impuesto y no obligado al desglose',
        '04' => 'Sí objeto del impuesto y no causa impuesto',
    ],

    'impuestos' => [
        '001' => 'ISR',
        '002' => 'IVA',
        '003' => 'IEPS',
    ],

    // Clave de producto/servicio habitual para servicios dentales.
    'claves_producto_sugeridas' => [
        '85121800' => 'Servicios de odontología',
        '85121801' => 'Servicios de dentista general',
        '85121802' => 'Servicios de ortodoncia',
    ],

    'cp_publico_general' => [
        'rfc' => 'XAXX010101000',
        'nombre' => 'PUBLICO EN GENERAL',
        'regimen' => '616',
        'uso_cfdi' => 'S01',
    ],

];
