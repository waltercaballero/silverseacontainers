/* Silversea – Ver Tarifas: edición en línea (AJAX) de municipio/km/precios */
(function () {
    if (typeof silvSeaTarifas === 'undefined') return;

    var table = document.getElementById('sc-tarifas-table');
    if (!table) return;

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function money(v) {
        return '€' + Number(v || 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderStatic(tr, row) {
        var cells = tr.children;
        cells[1].textContent = row.municipio_destino || '';
        cells[2].textContent = row.km;
        cells[3].textContent = money(row.precio_sin_descarga);
        cells[4].textContent = money(row.precio_con_desc_20);
        cells[5].textContent = money(row.precio_con_desc_40);
        cells[6].textContent = (row.problemas || []).join(', ');
        tr.style.background = (row.problemas && row.problemas.length) ? '#fef2f2' : '';
        if (cells[7].dataset.original) cells[7].innerHTML = cells[7].dataset.original;
    }

    function startEdit(tr) {
        var row = JSON.parse(tr.dataset.row);
        var cells = tr.children;

        cells[1].innerHTML = '<input type="text" class="sc-f-municipio" value="' + escapeHtml(row.municipio_destino || '') + '" style="width:100%;min-width:100px;">';
        cells[2].innerHTML = '<input type="number" class="sc-f-km" value="' + escapeHtml(row.km) + '" min="0" style="width:70px;text-align:right;">';
        cells[3].innerHTML = '<input type="text" class="sc-f-sin" value="' + escapeHtml(row.precio_sin_descarga) + '" style="width:80px;text-align:right;">';
        cells[4].innerHTML = '<input type="text" class="sc-f-c20" value="' + escapeHtml(row.precio_con_desc_20) + '" style="width:80px;text-align:right;">';
        cells[5].innerHTML = '<input type="text" class="sc-f-c40" value="' + escapeHtml(row.precio_con_desc_40) + '" style="width:80px;text-align:right;">';

        var actions = cells[7];
        if (!actions.dataset.original) actions.dataset.original = actions.innerHTML;
        actions.innerHTML =
            '<button type="button" class="button button-primary button-small sc-save-btn">Guardar</button> ' +
            '<button type="button" class="button button-small sc-cancel-btn">Cancelar</button>';
    }

    function cancelEdit(tr) {
        renderStatic(tr, JSON.parse(tr.dataset.row));
    }

    function actionsMarkup(deleteUrl, cp) {
        return '<button type="button" class="button button-small sc-edit-btn">Editar</button> ' +
               '<a href="' + escapeHtml(deleteUrl) + '" class="button button-small" style="color:#b91c1c;" ' +
               'onclick="return confirm(\'¿Eliminar la tarifa del CP ' + escapeHtml(cp) + '? Esta acción no se puede deshacer.\')">Eliminar</a>';
    }

    function reEnableAddButton() {
        var btn = document.getElementById('sc-add-tarifa-btn');
        if (btn) btn.disabled = false;
    }

    function insertNewRowForm() {
        var tbody = table.querySelector('tbody');
        var tr = document.createElement('tr');
        tr.className = 'sc-new-row';
        tr.innerHTML =
            '<td style="padding:5px 10px;border:1px solid #eee;"><input type="text" class="sc-f-cp" placeholder="CP" maxlength="5" style="width:70px;"></td>' +
            '<td style="padding:5px 10px;border:1px solid #eee;"><input type="text" class="sc-f-municipio" placeholder="Municipio" style="width:100%;min-width:100px;"></td>' +
            '<td style="padding:5px 10px;border:1px solid #eee;text-align:right;"><input type="number" class="sc-f-km" min="0" style="width:70px;text-align:right;"></td>' +
            '<td style="padding:5px 10px;border:1px solid #eee;text-align:right;"><input type="text" class="sc-f-sin" style="width:80px;text-align:right;"></td>' +
            '<td style="padding:5px 10px;border:1px solid #eee;text-align:right;"><input type="text" class="sc-f-c20" style="width:80px;text-align:right;"></td>' +
            '<td style="padding:5px 10px;border:1px solid #eee;text-align:right;"><input type="text" class="sc-f-c40" style="width:80px;text-align:right;"></td>' +
            '<td style="padding:5px 10px;border:1px solid #eee;"></td>' +
            '<td style="padding:5px 10px;border:1px solid #eee;white-space:nowrap;">' +
                '<button type="button" class="button button-primary button-small sc-create-save-btn">Guardar</button> ' +
                '<button type="button" class="button button-small sc-create-cancel-btn">Cancelar</button>' +
            '</td>';
        tbody.insertBefore(tr, tbody.firstChild);
        var cpInput = tr.querySelector('.sc-f-cp');
        if (cpInput) cpInput.focus();
    }

    function createRow(tr) {
        var saveBtn = tr.querySelector('.sc-create-save-btn');
        if (saveBtn) saveBtn.disabled = true;

        var body = new URLSearchParams();
        body.set('action', 'silversea_create_tarifa');
        body.set('nonce', silvSeaTarifas.nonce);
        body.set('origin', table.dataset.origin);
        body.set('cp_destino', tr.querySelector('.sc-f-cp').value);
        body.set('municipio_destino', tr.querySelector('.sc-f-municipio').value);
        body.set('km', tr.querySelector('.sc-f-km').value);
        body.set('precio_sin_descarga', tr.querySelector('.sc-f-sin').value);
        body.set('precio_con_desc_20', tr.querySelector('.sc-f-c20').value);
        body.set('precio_con_desc_40', tr.querySelector('.sc-f-c40').value);

        fetch(silvSeaTarifas.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    alert((json && json.data && json.data.message) || 'No se pudo crear la tarifa.');
                    if (saveBtn) saveBtn.disabled = false;
                    return;
                }
                var row = json.data;
                tr.classList.remove('sc-new-row');
                tr.dataset.row = JSON.stringify(row);
                tr.children[0].textContent = row.cp_destino;
                tr.children[7].dataset.original = actionsMarkup(row.delete_url, row.cp_destino);
                renderStatic(tr, row);
                reEnableAddButton();
            })
            .catch(function () {
                alert('Error de red al guardar.');
                if (saveBtn) saveBtn.disabled = false;
            });
    }

    function saveEdit(tr) {
        var cells  = tr.children;
        var row    = JSON.parse(tr.dataset.row);
        var saveBtn = tr.querySelector('.sc-save-btn');
        if (saveBtn) saveBtn.disabled = true;

        var body = new URLSearchParams();
        body.set('action', 'silversea_update_tarifa');
        body.set('nonce', silvSeaTarifas.nonce);
        body.set('id', row.id);
        body.set('municipio_destino', cells[1].querySelector('input').value);
        body.set('km', cells[2].querySelector('input').value);
        body.set('precio_sin_descarga', cells[3].querySelector('input').value);
        body.set('precio_con_desc_20', cells[4].querySelector('input').value);
        body.set('precio_con_desc_40', cells[5].querySelector('input').value);

        fetch(silvSeaTarifas.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    alert((json && json.data && json.data.message) || 'No se pudo guardar.');
                    if (saveBtn) saveBtn.disabled = false;
                    return;
                }
                tr.dataset.row = JSON.stringify(json.data);
                renderStatic(tr, json.data);
            })
            .catch(function () {
                alert('Error de red al guardar. Probá de nuevo.');
                if (saveBtn) saveBtn.disabled = false;
            });
    }

    table.addEventListener('click', function (e) {
        var editBtn         = e.target.closest('.sc-edit-btn');
        var saveBtn         = e.target.closest('.sc-save-btn');
        var cancelBtn       = e.target.closest('.sc-cancel-btn');
        var createSaveBtn   = e.target.closest('.sc-create-save-btn');
        var createCancelBtn = e.target.closest('.sc-create-cancel-btn');
        if (editBtn)         { e.preventDefault(); startEdit(editBtn.closest('tr')); }
        if (saveBtn)         { e.preventDefault(); saveEdit(saveBtn.closest('tr')); }
        if (cancelBtn)       { e.preventDefault(); cancelEdit(cancelBtn.closest('tr')); }
        if (createSaveBtn)   { e.preventDefault(); createRow(createSaveBtn.closest('tr')); }
        if (createCancelBtn) { e.preventDefault(); createCancelBtn.closest('tr').remove(); reEnableAddButton(); }
    });

    var addBtn = document.getElementById('sc-add-tarifa-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            if (table.querySelector('.sc-new-row')) return; // ya hay una fila nueva sin guardar
            addBtn.disabled = true;
            insertNewRowForm();
        });
    }
})();
