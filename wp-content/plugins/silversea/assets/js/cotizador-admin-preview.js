/* Silversea – Vista previa: buscador de CP con autocomplete (admin) */
(function () {
    if (typeof silvSeaPreview === 'undefined') return;

    var box = document.getElementById('sc-preview-box');
    if (!box) return;

    var input       = document.getElementById('sc-preview-cp-input');
    var suggestions = document.getElementById('sc-preview-cp-suggestions');
    var resultBox   = document.getElementById('sc-preview-cp-result');
    var debounceTimer = null;
    var currentRequest = 0;

    function money(v) {
        return '€' + parseFloat(v || 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function cityLabel(key) {
        return (silvSeaPreview.cityLabels && silvSeaPreview.cityLabels[key]) || key;
    }

    function hideSuggestions() {
        suggestions.style.display = 'none';
        suggestions.innerHTML = '';
    }

    function renderRow(row) {
        var warn = row.sin_tarifa_descarga
            ? '<p style="margin:8px 0 0;font-size:12px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:4px;padding:6px 8px;">' +
              '⚠ Tiene tarifa "sin descarga" pero no "con descarga" — en el cotizador real este CP se muestra como "a confirmar por asesor".</p>'
            : '';
        resultBox.innerHTML =
            '<div style="background:#f0fdf4;border-left:4px solid #16a34a;border-radius:4px;padding:10px 14px;">' +
              '<strong style="font-size:14px;">' + escapeHtml(row.cp_destino) + '</strong> — ' + escapeHtml(row.municipio_destino || '') +
              '<table style="width:100%;font-size:12px;border-collapse:collapse;margin-top:8px;">' +
                '<tr><td style="padding:2px 6px 2px 0;color:#666;">Km</td><td style="text-align:right;">' + escapeHtml(row.km) + '</td></tr>' +
                '<tr><td style="padding:2px 6px 2px 0;color:#666;">Sin descarga</td><td style="text-align:right;">' + money(row.precio_sin_descarga) + '</td></tr>' +
                '<tr><td style="padding:2px 6px 2px 0;color:#666;">Con descarga 20\'</td><td style="text-align:right;">' + money(row.precio_con_desc_20) + '</td></tr>' +
                '<tr><td style="padding:2px 6px 2px 0;color:#666;">Con descarga 40\'</td><td style="text-align:right;">' + money(row.precio_con_desc_40) + '</td></tr>' +
              '</table>' + warn +
            '</div>';
    }

    function renderNotFound(term) {
        resultBox.innerHTML =
            '<p style="color:#b91c1c;font-size:13px;background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:8px 10px;margin:0;">' +
            'No se encontró ningún CP que empiece con "' + escapeHtml(term) + '" en ' + escapeHtml(cityLabel(box.dataset.origin)) + '.</p>';
    }

    function renderSuggestions(rows) {
        if (!rows.length) { hideSuggestions(); return; }
        suggestions.innerHTML = rows.map(function (r) {
            return '<div class="sc-preview-suggestion" data-row=\'' + JSON.stringify(r).replace(/'/g, '&#39;') + '\'' +
                   ' style="padding:6px 10px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;">' +
                   '<strong>' + escapeHtml(r.cp_destino) + '</strong> — ' + escapeHtml(r.municipio_destino || '') +
                   '</div>';
        }).join('');
        suggestions.style.display = 'block';

        Array.prototype.forEach.call(suggestions.querySelectorAll('.sc-preview-suggestion'), function (el) {
            el.addEventListener('mouseenter', function () { el.style.background = '#f5f5f5'; });
            el.addEventListener('mouseleave', function () { el.style.background = ''; });
            el.addEventListener('click', function () {
                /* El navegador ya decodificó &#39; a ' al parsear el innerHTML */
                var row = JSON.parse(el.dataset.row);
                input.value = row.cp_destino;
                hideSuggestions();
                renderRow(row);
            });
        });
    }

    function search(term) {
        var origin = box.dataset.origin;
        var reqId  = ++currentRequest;

        var body = new URLSearchParams();
        body.set('action', 'silversea_preview_cp_search');
        body.set('nonce', silvSeaPreview.nonce);
        body.set('origin', origin);
        body.set('term', term);

        fetch(silvSeaPreview.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (reqId !== currentRequest) return; // respuesta obsoleta, ignorar
                var rows = (json && json.success) ? json.data : [];

                if (!rows.length) {
                    hideSuggestions();
                    renderNotFound(term);
                } else if (rows.length === 1) {
                    /* Coincidencia única: mostrar el detalle directo, sin exigir un clic */
                    hideSuggestions();
                    renderRow(rows[0]);
                } else {
                    renderSuggestions(rows);
                    resultBox.innerHTML = '';
                }
            })
            .catch(function () { /* red inestable: no romper la UI */ });
    }

    input.addEventListener('input', function () {
        var term = input.value.replace(/\D/g, '');
        clearTimeout(debounceTimer);
        resultBox.innerHTML = '';

        if (term.length < 2) { hideSuggestions(); return; }

        debounceTimer = setTimeout(function () { search(term); }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!box.contains(e.target)) hideSuggestions();
    });
})();
