<?php

namespace Tests\Feature\Inventario;

/** Autenticacion y permisos por accion del modulo Inventario. */
class InventarioAccessTest extends InventarioTestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('inventario.index'))->assertRedirect(route('login'));
        $this->post(route('inventario.movimientos.entrada'), [])->assertRedirect(route('login'));
    }

    public function test_admin_can_open_every_inventory_screen(): void
    {
        $this->actingAsAdmin();
        $item = $this->makeItem();

        $this->get(route('inventario.index'))->assertOk()->assertSee('Inventario')->assertSee('Guantes nitrilo (M)');
        $this->get(route('inventario.index', ['vista' => 'transacciones']))->assertOk();
        $this->get(route('inventario.items.show', $item))->assertOk()->assertSee('Kardex');
        $this->get(route('inventario.proveedores.index'))->assertOk();
    }

    public function test_doctor_without_inventory_permission_gets_403(): void
    {
        $this->actingAsDoctor();

        $this->get(route('inventario.index'))->assertForbidden();
    }

    public function test_receptionist_can_view_but_not_create_edit_or_export(): void
    {
        $this->actingAsAdmin();
        $item = $this->makeItem();

        $this->actingAsReceptionist();

        $this->get(route('inventario.index'))->assertOk()->assertDontSee('data-modal-open="modal-nuevo-insumo"', false);
        $this->get(route('inventario.items.show', $item))->assertOk();
        $this->entrada($item, '5')->assertForbidden();
        $this->post(route('inventario.items.store'), [])->assertForbidden();
        $this->put(route('inventario.items.update', $item), [])->assertForbidden();
        $this->patch(route('inventario.items.toggle', $item))->assertForbidden();
        $this->get(route('inventario.export'))->assertForbidden();
        $this->post(route('inventario.proveedores.store'), ['name' => 'X'])->assertForbidden();
    }

    public function test_unknown_item_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->get(route('inventario.items.show', '11111111-1111-4111-8111-111111111111'))->assertNotFound();
    }
}
