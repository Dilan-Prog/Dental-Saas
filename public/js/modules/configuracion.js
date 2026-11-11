/* =====================================================================
   Módulo Configuración: vista previa de imágenes, modal de sucursales
   y estado visual de las notificaciones.
   ===================================================================== */
(function () {
    'use strict';

    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

    /* ---- Vista previa de imágenes (logo, avatar, firma) ---- */
    function bindPreview(inputSel, imgSel, hideSel, maxMb) {
        var input = $(inputSel), img = $(imgSel);
        if (!input || !img) return;

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;

            if (file.size > maxMb * 1024 * 1024) {
                input.value = '';
                if (window.UI && UI.toast) UI.toast('El archivo no debe pesar más de ' + maxMb + ' MB.', 'error');
                return;
            }

            var reader = new FileReader();
            reader.onload = function (e) {
                img.src = e.target.result;
                img.hidden = false;
                $$(hideSel).forEach(function (el) { el.hidden = true; });
            };
            reader.readAsDataURL(file);
        });
    }

    bindPreview('[data-logo-input]', '[data-logo-preview]', '[data-logo-hint]', 2);
    bindPreview('[data-signature-input]', '[data-signature-preview]', '[data-signature-hint]', 1);
    bindPreview('[data-avatar-input]', '[data-avatar-preview]', '[data-avatar-fallback]', 2);

    /* ---- Notificaciones: atenuar opciones cuando el aviso está apagado ---- */
    $$('[data-notif]').forEach(function (row) {
        var toggle = $('[data-notif-toggle]', row);
        if (!toggle) return;
        var sync = function () { row.classList.toggle('is-off', !toggle.checked); };
        toggle.addEventListener('change', sync);
        sync();
    });

    /* ---- Sucursales: un solo modal para crear y editar ---- */
    var form = $('[data-branch-form]');
    if (form) {
        var method = $('[data-branch-method]', form);
        var idField = $('[data-branch-id]', form);
        var title = $('#sucursal-modal-title');
        var storeUrl = window.__branchStoreUrl;
        var mainBox = $('[data-branch-main]', form);

        var setValue = function (name, value) {
            var el = form.elements[name];
            if (el) el.value = value == null ? '' : value;
        };

        var reset = function () {
            form.reset();
            $$('.has-error', form).forEach(function (el) { el.classList.remove('has-error'); });
            $$('.field__error', form).forEach(function (el) { el.remove(); });
        };

        $$('[data-branch-new]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                reset();
                form.setAttribute('action', storeUrl);
                method.value = 'POST';
                idField.value = '';
                if (title) title.textContent = 'Nueva sucursal';
                if (mainBox) { mainBox.disabled = false; mainBox.checked = false; }
                UI.openModal('sucursal-modal');
            });
        });

        $$('[data-branch-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                reset();
                form.setAttribute('action', btn.dataset.action);
                method.value = 'PUT';
                idField.value = btn.dataset.id;
                setValue('name', btn.dataset.name);
                setValue('address_line', btn.dataset.address);
                setValue('phone', btn.dataset.phone);
                setValue('manager_user_id', btn.dataset.manager);
                if (title) title.textContent = 'Editar sucursal';

                if (mainBox) {
                    var isMain = btn.dataset.main === '1';
                    mainBox.checked = isMain;
                    // La principal no se puede "quitar": se designa otra; una inactiva no puede ser principal.
                    mainBox.disabled = isMain || btn.dataset.inactive === '1';
                }
                UI.openModal('sucursal-modal');
            });
        });

        // Un checkbox deshabilitado no se envía: se conserva la marca de principal.
        form.addEventListener('submit', function () {
            if (mainBox && mainBox.disabled && mainBox.checked) {
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = 'is_main'; h.value = '1';
                form.appendChild(h);
            }
        });
    }
})();
