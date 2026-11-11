{{-- Contenido por defecto del panel de notificaciones (sin módulo de notificaciones instalado).
     Para personalizarlo crea resources/views/notifications/panel.blade.php. --}}
<div class="empty" style="padding:24px 16px">
    <div class="empty__icon"><x-ui.icon name="bell" /></div>
    <div class="empty__title">Sin notificaciones</div>
    <div class="empty__text text-sm">Aquí verás recordatorios de citas, stock bajo y pagos.</div>
</div>
