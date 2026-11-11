{{-- Modal "Nueva transacción" (Figma 808:3920). Requiere: $categories (activas, sin sistema), $selectableCategories, $branches, $methods, $openSessions, $mainBranchId --}}
@php
    $bag = $errors->getBag('movement');
    $dir = old('direction', 'ingreso');
    $cat = fn ($key) => $bag->has($key) ? ' has-error' : '';
    $multiSession = $openSessions->count() > 1;
@endphp
<x-ui.modal id="nueva-transaccion" title="Nueva transacción" subtitle="Registro manual de movimiento" size="lg" open-on-error
            action="{{ route('finanzas.movimientos.store') }}" enctype="multipart/form-data" data-fin-movement-form>
    <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
    <input type="hidden" name="direction" value="{{ $dir }}" data-fin-direction-input>

    <div class="stack">
        <div>
            <div class="segmented segmented--block" role="group" aria-label="Tipo de movimiento">
                <button type="button" data-fin-direction="ingreso" class="{{ $dir === 'ingreso' ? 'is-active' : '' }}">↑ Ingreso</button>
                <button type="button" data-fin-direction="egreso" class="{{ $dir === 'egreso' ? 'is-active' : '' }}">↓ Egreso</button>
            </div>
            @if ($bag->has('direction'))<div class="field__error" role="alert">{{ $bag->first('direction') }}</div>@endif
        </div>

        <div class="form-grid">
            <x-ui.field label="Categoría" name="category_id" bag="movement" required>
                <select name="category_id" id="category_id" class="select{{ $cat('category_id') }}" data-fin-category required>
                    <option value="">Selecciona…</option>
                    @foreach ($selectableCategories as $c)
                        <option value="{{ $c->id }}" data-direction="{{ $c->direction->value }}" @selected(old('category_id') === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </x-ui.field>
            <x-ui.field label="Método de pago" name="method" bag="movement" required>
                <select name="method" id="method" class="select{{ $cat('method') }}" data-fin-method required>
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}" @selected(old('method', 'tarjeta') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-ui.field>

            <x-ui.field label="Concepto" name="concept" bag="movement" class="col-span-2" required placeholder="Descripción del movimiento" maxlength="300" />

            <x-ui.field label="Monto" name="amount" bag="movement" mask="money" inputmode="decimal" placeholder="0.00" required hint="Pesos mexicanos (MXN), hasta 2 decimales." />
            <x-ui.field label="Fecha" name="occurred_on" bag="movement" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}" required />

            @if ($branches->count() > 1)
                <x-ui.field label="Sucursal" name="branch_id" bag="movement" as="select" :options="$branches->pluck('name', 'id')->all()" :selected="$mainBranchId" required />
            @else
                <input type="hidden" name="branch_id" value="{{ $mainBranchId }}">
            @endif

            @if ($multiSession)
                <x-ui.field label="Caja (turno abierto)" name="cash_session_id" bag="movement" class="fin-session-field">
                    <select name="cash_session_id" id="cash_session_id" class="select{{ $cat('cash_session_id') }}">
                        <option value="">Selecciona la caja…</option>
                        @foreach ($openSessions as $s)
                            <option value="{{ $s->id }}" @selected(old('cash_session_id') === $s->id)>{{ $s->cashRegister?->name }} · {{ $s->cashRegister?->branch?->name }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
            @endif

            <x-ui.field label="Comprobante (opcional)" name="receipt" bag="movement" class="col-span-2" hint="JPG, PNG o PDF, máximo 5 MB.">
                <input type="file" name="receipt" id="receipt" class="input{{ $cat('receipt') }}" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
            </x-ui.field>
        </div>

        <div class="fin-cash-note text-sm text-muted" data-fin-cash-note hidden>
            <x-ui.icon name="info" size="sm" />
            @if ($openSessions->isEmpty())
                No hay una caja abierta: los movimientos en efectivo requieren un turno de caja abierto.
            @else
                El movimiento en efectivo se asociará al turno abierto de la caja y afectará el corte.
            @endif
        </div>
    </div>

    <x-slot name="footer">
        <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
        <x-ui.button type="submit">Guardar</x-ui.button>
    </x-slot>
</x-ui.modal>
