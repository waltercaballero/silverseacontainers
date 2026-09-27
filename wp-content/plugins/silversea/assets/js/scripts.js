/* ══════════════════════════════════════════════════════════════
   ATRIBUCIÓN DE CAMPAÑA — captura sitewide (este script se carga en
   TODAS las páginas vía lcs_enqueue_flag_dropdown_assets, a diferencia
   del resto de este archivo que solo actúa en elementos puntuales).

   Por qué acá y no solo en el formulario de presupuesto: si la campaña
   apunta a otra página (home, un producto, una landing) y el visitante
   navega internamente antes de llegar al cotizador, la URL de la página
   del cotizador ya no tiene "?utm_source=...". Hay que capturarlo en la
   PRIMERA página que ve, guardarlo en sessionStorage, y sobrevive la
   navegación hasta que se envía el formulario (request-quote-form.php
   solo LEE de sessionStorage, no captura).
══════════════════════════════════════════════════════════════ */
(function () {
  try {
    var params  = new URLSearchParams(window.location.search);
    var utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    var hasUtmInUrl = utmKeys.some(function (k) { return !!params.get(k); });

    /* Si esta URL trae parámetros de campaña, valen más que lo guardado antes
       (last-touch: la campaña más reciente pisa la anterior). */
    utmKeys.forEach(function (k) {
      var v = params.get(k);
      if (v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
    });

    /* Sin parámetros de campaña: distinguir origen orgánico de directo.
       Solo en la PRIMERA página de la visita — si ya hay algo guardado, no
       se pisa con la navegación interna (document.referrer en la 2da página
       sería la propia página anterior de Silversea, no el origen real). */
    var already = null;
    try { already = sessionStorage.getItem('utm_source'); } catch (e) {}

    if (! hasUtmInUrl && ! already) {
      var source = 'Direct', medium = 'none';
      var ref = document.referrer;
      if (ref) {
        try {
          var refHost = new URL(ref).hostname.replace(/^www\./, '');
          var ownHost = window.location.hostname.replace(/^www\./, '');
          if (refHost && refHost !== ownHost) {
            var searchEngines = ['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'baidu.', 'yandex.', 'ecosia.'];
            var isSearch = searchEngines.some(function (s) { return refHost.indexOf(s) !== -1; });
            source = isSearch ? 'Organic'  : 'Referral';
            medium = isSearch ? 'organic'  : 'referral';
          }
        } catch (e) {}
      }
      try {
        sessionStorage.setItem('utm_source', source);
        sessionStorage.setItem('utm_medium', medium);
      } catch (e) {}
    }

    /* gclid: dato personal de Google Ads. Solo con consentimiento de marketing
       — el sitio todavía no tiene gestor de consentimiento de cookies, así que
       queda deshabilitado (mismo criterio que en request-quote-form.php). */
    function hasMarketingConsent() { return false; }
    if (hasMarketingConsent()) {
      var gclid = params.get('gclid');
      if (gclid) { try { sessionStorage.setItem('gclid', gclid); } catch (e) {} }
    }
  } catch (e) {}
})();

/*
document.addEventListener('DOMContentLoaded', function () {
    const dropdown = document.querySelector('.lcs_custom-dropdown');
    const options = dropdown.querySelector('.lcs_dropdown-options');
    const flagItems = options.querySelectorAll('li');

    flagItems.forEach(item => {
        item.addEventListener('click', function () {
            window.location.href = item.dataset.value;
        });
    });
});
*/


document.addEventListener('DOMContentLoaded', function () {
  const openPopupBtns = document.querySelectorAll('.open-store-popup');
  const closePopupBtn = document.getElementById('close-store-popup');
  const popupContainer = document.getElementById('store-popup-container');

  openPopupBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      popupContainer.style.display = 'flex';
    });
  });
  
  if ( closePopupBtn ) {
	  closePopupBtn.addEventListener('click', () => {
		popupContainer.style.display = 'none';
	  });
	  
	  window.addEventListener('click', (event) => {
		if (event.target === popupContainer) {
		  popupContainer.style.display = 'none';
		}
	  });
  }
});

/*
document.addEventListener('DOMContentLoaded', function () {
  const form = document.querySelector('.elementor-form');
  if (!form) return;

  console.log('Procesador de Salesforce leads cargado');

  form.addEventListener('submit', function (e) {
    const formData = new FormData(form);
    const entries = {};
    formData.forEach((value, key) => {
      const match = key.match(/^form_fields\[(.+?)\]$/);
      const cleanKey = match ? match[1] : key;
      entries[cleanKey] = value;
    });

    console.log('Entries procesados:', entries);

    const salesforce_data = {
      'oid': '00D8a000002A8Hp',
      'retURL': entries['retURL'] || window.location.href,
      'first_name': entries['first_name'] || '',
      'last_name': entries['last_name'] || '',
      'company': entries['company'] || '',
      'phone': entries['phone'] || '',
      'email': entries['email'] || '',
      '00N8a00000FXhD2': entries['00N8a00000FXhD2'] || '',
      //'city': entries['city'] || '',
      'country': entries['country'] || '',
      'lead_source': entries['lead_source'] || '',
      //'description': entries['description'] || '',
      //'00N8a00000FXdRt': entries['00N8a00000FXdRt'] || '',
      '00N8a00000FXdRZ': entries['00N8a00000FXdRZ'] || '',
      '00N8a00000FXdRo': entries['00N8a00000FXdRo'] || ''
    };

    fetch('/wp-json/salesforce-form/lead', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(salesforce_data)
    })
      .then(res => res.json())
      .then(data => console.log('Salesforce response:', data))
      .catch(err => console.error('Error:', err));

    fetch('/wp-json/salesforce-form/email', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(salesforce_data)
    }).then(res => console.log('Email enviado:', res.status));
  });
});

*/