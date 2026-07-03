﻿﻿﻿/* shipping-calculator.js  v1.4.0 */

/* Textos editables desde el admin (Cotizador → 📝 Textos).
   Lee silvSea.texts[key]; si no existe usa el fallback (texto por defecto). */
function scT(key, fallback) {
  var t = (typeof silvSea !== 'undefined' && silvSea.texts) ? silvSea.texts[key] : null;
  return (t !== undefined && t !== null && t !== '') ? t : fallback;
}

(function () {
  "use strict";

  /* ── COOKIE helpers ── */
  var SC_COOKIE = 'sc_prefs';
  var SC_COOKIE_DAYS = 365;

  function scCookieSave(data) {
    var exp = new Date();
    exp.setDate(exp.getDate() + SC_COOKIE_DAYS);
    document.cookie = SC_COOKIE + '=' + encodeURIComponent(JSON.stringify(data))
      + ';expires=' + exp.toUTCString() + ';path=/;SameSite=Lax';
  }

  function scCookieLoad() {
    var match = document.cookie.match('(?:^|; )' + SC_COOKIE + '=([^;]*)');
    if (!match) return null;
    try { return JSON.parse(decodeURIComponent(match[1])); } catch(e) { return null; }
  }

  function scSavePrefs() {
    var methodEl  = document.querySelector('.sc-toggle-btn.active');
    var method    = methodEl ? methodEl.dataset.method : currentMethod;
    var pickupEl  = document.getElementById('scPickupCity');
    var originEl  = document.getElementById('scOriginCity');
    var postalEl  = document.getElementById('scPostalCode');
    scCookieSave({
      method:    method,
      pickup:    pickupEl  ? pickupEl.value  : '',
      origin:    originEl  ? originEl.value  : '',
      postal:    postalEl  ? postalEl.value  : '',
      transport: currentTransport,
    });
  }

  function scRestorePrefs() {
    var p = scCookieLoad();
    if (!p) return;
    /* method */
    if (p.method) {
      currentMethod = p.method;
      document.querySelectorAll('.sc-toggle-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.method === p.method);
      });
      var pickup   = document.getElementById('scPickupPanel');
      var delivery = document.getElementById('scDeliveryPanel');
      if (pickup)   pickup.classList.toggle('sc-hidden',   p.method !== 'pickup');
      if (delivery) delivery.classList.toggle('sc-hidden', p.method !== 'delivery');
    }
    /* pickup city */
    if (p.pickup) {
      var pickupEl = document.getElementById('scPickupCity');
      if (pickupEl) { pickupEl.value = p.pickup; scPickupCityChanged(); }
    }
    /* origin */
    if (p.origin) {
      var originEl = document.getElementById('scOriginCity');
      if (originEl) { originEl.value = p.origin; scShowDeposito(p.origin, 'scDepositoInfoDelivery'); }
      if (window.scUpdateProductPrice) window.scUpdateProductPrice(p.origin);
    }
    /* postal */
    if (p.postal) {
      var postalEl = document.getElementById('scPostalCode');
      if (postalEl) postalEl.value = p.postal;
    }
    /* transport */
    if (p.transport) {
      currentTransport = p.transport;
      document.querySelectorAll('[data-transport]').forEach(function(b) {
        b.classList.toggle('active', b.dataset.transport === p.transport);
      });
    }
    /* suggest transport tip if both origin + postal filled */
    if (p.origin && p.postal) scSuggestTransport();

    /* No auto-calcular al restaurar: solo pre-llenar los campos.
       El usuario verá sus preferencias previas listas pero sin mostrar
       "Cotización guardada" en productos que nunca cotizó. */
  }

  var productSize = typeof silvSea !== "undefined" && silvSea.productSize ? silvSea.productSize : "20";
  var demoMode = typeof silvSea !== "undefined" && silvSea.demoMode === "1";
  var descargaModo = typeof silvSea !== "undefined" && silvSea.descargaModo ? silvSea.descargaModo : "contenedor";
  var demoPriceSin = typeof silvSea !== "undefined" ? (parseFloat(silvSea.demoPriceSin) || 786.60) : 786.60;
  var demoPriceC20 = typeof silvSea !== "undefined" ? (parseFloat(silvSea.demoPriceC20) || 1644.00) : 1644.00;
  var demoPriceC40 = typeof silvSea !== "undefined" ? (parseFloat(silvSea.demoPriceC40) || 1765.28) : 1765.28;
  var isConsolidated = typeof silvSea !== "undefined" && silvSea.mode === "consolidated";
  var showFront = typeof silvSea !== "undefined" && silvSea.showFront === "1";
  var cartItems = typeof silvSea !== "undefined" && silvSea.items ? silvSea.items : [];

  var currentMethod = "pickup";
  var currentTransport = "sin";
  var currentQty = 1;

  /*  CANTIDAD  */
  window.scQty = function (delta) { currentQty = Math.max(1, currentQty + delta); scSyncQty(); scClearResult(); };

  function scSyncQty() {
    document.getElementById("scQtyVal").textContent = currentQty;
    var native = document.querySelector('input[name="quantity"]');
    if (native && parseInt(native.value) !== currentQty) { native.value = currentQty; native.dispatchEvent(new Event("change", { bubbles: true })); }
  }

  function scGetQty() { return currentQty; }

  document.addEventListener("DOMContentLoaded", function () {
    /* Restaurar preferencias guardadas en cookie */
    scRestorePrefs();

    /* Auto-recalcular si venimos de "Actualizar lista" */
    if (window.location.search.indexOf('sc_recalc=1') !== -1) {
      /* Limpiar el parámetro de la URL sin recargar */
      var cleanUrl = window.location.href
        .replace(/([?&])sc_recalc=1(&?)/, function(_, pre, post) { return post ? pre : ''; })
        .replace(/\?$/, '');
      window.history.replaceState({}, '', cleanUrl);
      /* Disparar cálculo si hay método delivery con datos suficientes */
      if (currentMethod === 'delivery') {
        setTimeout(function() { scCalculate(); }, 150);
      }
    }

    var native = document.querySelector('input[name="quantity"]');
    if (!native) return;
    function syncFromNative() { var val = Math.max(1, parseInt(this.value) || 1); if (val !== currentQty) { currentQty = val; document.getElementById("scQtyVal").textContent = currentQty; scClearResult(); } }
    native.addEventListener("change", syncFromNative);
    native.addEventListener("input", syncFromNative);
  });

  /*  MÉTODO  */
  window.scSetMethod = function (m) {
    currentMethod = m;
    document.querySelectorAll(".sc-toggle-btn").forEach(function (b) { b.classList.toggle("active", b.dataset.method === m); });
    document.getElementById("scPickupPanel").classList.toggle("sc-hidden", m !== "pickup");
    document.getElementById("scDeliveryPanel").classList.toggle("sc-hidden", m !== "delivery");
    scClearResult();
    scSavePrefs();
  };

  /*  RETIRO  */
  window.scPickupCityChanged = function () {
    var city = document.getElementById("scPickupCity").value;
    var info = document.getElementById("scPickupInfo"); var contBtn = document.getElementById("scContinueBtn");
    if (city) { info.classList.remove("sc-hidden"); contBtn.classList.remove("sc-hidden"); }
    else { info.classList.add("sc-hidden"); contBtn.classList.add("sc-hidden"); }
    scShowDeposito(city, "scDepositoInfo");
    scSavePrefs();
    if (window.scUpdateProductPrice) window.scUpdateProductPrice(city);
  };

  window.scContinue = function () {
    var city = document.getElementById("scPickupCity").value; if (!city) return;
    var sd = new FormData();
    sd.append("action", "silversea_save_shipping");
    sd.append("nonce", typeof silvSea !== "undefined" ? silvSea.nonce : "");
    sd.append("method", "pickup"); sd.append("pickup_city", city); sd.append("price", 0);
    fetch(typeof silvSea !== "undefined" ? silvSea.ajaxUrl : "/wp-admin/admin-ajax.php", { method: "POST", body: sd });
    /* Sincronizar hidden fields del form de cotización */
    scSyncFormFields({ method: "pickup", origin: "", cp: "", transport: "", price: 0, pickup: city });
    var btn = document.getElementById("scContinueBtn");
    btn.textContent = scT('msg_pickup_saved', "\u2713 Recogida guardada"); btn.disabled = true; btn.style.background = "#1D9E75";
  };

  /*  ENTREGA  */
  window.scSetTransport = function (t) {
    currentTransport = t;
    document.querySelectorAll("[data-transport]").forEach(function (b) { b.classList.toggle("active", b.dataset.transport === t); });
    scClearResult();
    scSavePrefs();
  };

  window.scSuggestTransport = function () {
    var origin = document.getElementById("scOriginCity").value;
    var postal = document.getElementById("scPostalCode").value;
    var tip = document.getElementById("scTransportTip"); var tipText = document.getElementById("scTransportTipText");
    scShowDeposito(origin, "scDepositoInfoDelivery");
    scSavePrefs();
    if (window.scUpdateProductPrice) window.scUpdateProductPrice(origin);
    if (origin && postal.length >= 4) {
      tip.classList.remove("sc-hidden");
      tipText.innerHTML = scT('transport_tip', "Recomendamos <strong>sin descarga</strong> si dispone de medios propios en destino (precio menor). Elija <strong>con descarga</strong> si necesita que el cami\u00f3n incluya gr\u00faa.");
    } else tip.classList.add("sc-hidden");
    scClearResult();
  };

  /*  HELPERS  */
  function scClearResult() {
    document.getElementById("scResultArea").classList.add("sc-hidden");
    document.getElementById("scErrorArea").classList.add("sc-hidden");
  }

  function scShowError(msg) {
    var el = document.getElementById("scErrorArea"); el.textContent = msg; el.classList.remove("sc-hidden");
    document.getElementById("scResultArea").classList.add("sc-hidden");
    document.getElementById("scLoaderArea").classList.add("sc-hidden");
    document.getElementById("scCalcBtn").disabled = false;
  }

  function scShowResult(data) {
    document.getElementById("scLoaderArea").classList.add("sc-hidden");
    document.getElementById("scErrorArea").classList.add("sc-hidden");
    document.getElementById("scCalcBtn").disabled = false;
    var area = document.getElementById("scResultArea");
    var priceEl = document.getElementById("scResultPrice");
    var detailEl = document.getElementById("scResultDetail");
    var breakEl = document.getElementById("scResultBreakdown");
    var daysEl = document.getElementById("scResultDays");

    if (showFront) {
      area.classList.remove("sc-hidden");
      setTimeout(function () { area.scrollIntoView({ behavior: "smooth", block: "nearest" }); }, 50);
      priceEl.className = "sc-result-price";
      var num = parseFloat(data.price);
      priceEl.textContent = "€ " + num.toLocaleString("es-ES", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      detailEl.textContent = data.detail || "";
      if (data.breakdown && data.breakdown.length > 0) {
        var lines = data.breakdown.map(function (b) { return b.label + ": € " + parseFloat(b.price).toLocaleString("es-ES", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); });
        /* Leyenda de extras seleccionados (informativa, no suma al total) */
        var extrasHtml = '';
        if (window.scSelectedExtras && typeof silvSea !== 'undefined' && Array.isArray(silvSea.extras)) {
          var sel = silvSea.extras.filter(function(e) { return window.scSelectedExtras[e.value]; });
          if (sel.length) {
            extrasHtml = '<br><strong>Extras:</strong><br>' + sel.map(function(e) {
              return e.label + (e.price > 0 ? ' — Desde ' + parseFloat(e.price).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €' : '');
            }).join('<br>');
          }
        }
        breakEl.innerHTML = "<strong>Desglose:</strong><br>" + lines.join("<br>") + extrasHtml;
        breakEl.classList.remove("sc-hidden");
      } else breakEl.classList.add("sc-hidden");
      if (data.days) {
        daysEl.textContent = "Plazo estimado: " + data.days + " días hábiles"; daysEl.classList.remove("sc-hidden");
      } else daysEl.classList.add("sc-hidden");
    } else {
      /* showFront desactivado — mostrar confirmación neutral sin precio */
      area.classList.remove("sc-hidden");
      priceEl.className = "sc-result-free";
      priceEl.textContent = scT('msg_quote_saved', "✓ Cotización guardada");
      detailEl.textContent = scT('msg_quote_saved_sub', "Recibirá el detalle por email.");
      breakEl.classList.add("sc-hidden");
      daysEl.classList.add("sc-hidden");
    }
    if (currentMethod === "delivery") {
      /* Guardar en sesión siempre que sea delivery — aunque price=0 —
         para evitar que persista una sesión vieja de retiro (pickup). */
      var sd = new FormData();
      sd.append("action", "silversea_save_shipping");
      sd.append("nonce", typeof silvSea !== "undefined" ? silvSea.nonce : "");
      sd.append("method", "delivery");
      sd.append("origin", document.getElementById("scOriginCity").value);
      sd.append("postal_code", document.getElementById("scPostalCode").value);
      sd.append("transport", currentTransport);
      sd.append("price", data.price || 0); sd.append("detail", data.detail || "");
      sd.append("trucks", data.trucks || 1); sd.append("days", data.days || 5);
      fetch(typeof silvSea !== "undefined" ? silvSea.ajaxUrl : "/wp-admin/admin-ajax.php", { method: "POST", body: sd });
      /* Sincronizar hidden fields del form de cotización */
      scSyncFormFields({ method: "delivery", origin: document.getElementById("scOriginCity").value, cp: document.getElementById("scPostalCode").value, transport: currentTransport, price: data.price || 0, pickup: "" });
    }
  }

  /*  SYNC con form de cotización — popula hidden fields y dispara evento  */
  window.scSyncFormFields = function (d) {
    function setVal(id, val) {
      var el = document.getElementById(id); if (el) el.value = val || "";
    }
    setVal("rqa-shipping-method",    d.method);
    setVal("rqa-shipping-origin",    d.origin);
    setVal("rqa-shipping-cp",        d.cp);
    setVal("rqa-shipping-transport", d.transport);
    setVal("rqa-shipping-price",     d.price);
    setVal("rqa-shipping-pickup",    d.pickup);
    document.dispatchEvent(new CustomEvent("silversea:shipping:saved", { detail: d }));
  };

  /*  LÓGICA DE CAMIONES  */
  function scBuildTruckList(qty, size) {
    var trucks = []; var remaining = [];
    for (var i = 0; i < qty; i++) {
      if (size === 40) trucks.push({ containers: [{ size: 40, qty: 1 }], units: 4 });
      else remaining.push({ size: size, units: size / 10 });
    }
    var current = []; var currentUnits = 0;
    remaining.forEach(function (c) {
      if (currentUnits + c.units <= 4) { current.push(c); currentUnits += c.units; }
      else { if (current.length > 0) trucks.push({ containers: scConsolidateTruck(current), units: currentUnits }); current = [c]; currentUnits = c.units; }
    });
    if (current.length > 0) trucks.push({ containers: scConsolidateTruck(current), units: currentUnits });
    return trucks;
  }

  function scConsolidateTruck(containers) {
    var map = {};
    containers.forEach(function (c) { var key = c.size; if (map[key]) map[key].qty++; else map[key] = { size: c.size, qty: 1 }; });
    return Object.values(map);
  }

  /*  BUILD RESULT — SINGLE  */
  function scBuildResult(qty, size, transport, pSin, pCon, cp) {
    var useTrucks = transport === "sin" || descargaModo === "camion";
    var truckList = scBuildTruckList(qty, size);
    var breakdown = []; var total = 0;
    var pExtraTruck = typeof silvSea !== "undefined" ? (parseFloat(silvSea.extraTruckCost) || 1350.00) : 1350.00;
    if (useTrucks) {
      truckList.forEach(function (truck, idx) {
        var label = "Cami\u00f3n " + (idx + 1) + ": " + truck.containers.map(function (c) { return c.qty + "\u00d7" + c.size + "'"; }).join(" + ");
        breakdown.push({ label: label, price: pSin }); total += pSin;
      });
    } else {
      truckList.forEach(function (truck, idx) {
        if (idx === 0) {
          /* Camión 1 — con grúa. Regla: ≤20' llenando el camión (4u) = tarifa 40' */
          var truckUnits = truck.units || 0;
          var has40 = truck.containers.some(function(c) { return c.size === 40; });
          if (!has40 && truckUnits >= 4) {
            /* Camión lleno sin 40': cobrar como equiv. 40' */
            breakdown.push({ label: "Camión 1 (con grúa) – " + truck.containers.map(function(c){return c.qty+"×"+c.size+"'";}).join(" + ") + " [equiv. 40']", price: demoPriceC40 });
            total += demoPriceC40;
          } else {
            truck.containers.forEach(function (c) {
              var p = c.size === 40 ? demoPriceC40 : pCon;
              for (var i = 1; i <= c.qty; i++) { breakdown.push({ label: "Camión 1 (con grúa) – Contenedor (" + c.size + "')", price: p }); total += p; }
            });
          }
        } else {
          /* Camiones extra — sin grúa: pSin + extra */
          var priceExtra = pSin + pExtraTruck;
          var lbl = truck.containers.map(function (c) { return c.qty + "\u00d7" + c.size + "'"; }).join(" + ");
          breakdown.push({ label: "Cami\u00f3n " + (idx + 1) + " (sin gr\u00faa + extra): " + lbl, price: priceExtra });
          total += priceExtra;
        }
      });
    }
    var trucks = truckList.length;
    var transp_label = transport === "sin" ? "sin descarga" : "con descarga";
    var detail = qty + "\u00d7" + size + "' \u00b7 " + transp_label + " \u00b7 Municipio Demo a CP " + cp + (trucks > 1 ? " \u00b7 " + trucks + " camiones" : "");
    return { price: parseFloat(total.toFixed(2)), detail: detail, breakdown: breakdown, trucks: trucks, days: 3 };
  }

  /*  BUILD RESULT — CONSOLIDADO  */
  function scBuildResultConsolidated(items, transport, cp) {
    var allContainers = [];
    items.forEach(function (item) {
      for (var i = 0; i < item.quantity; i++) {
        if (item.size === 40) allContainers.push({ size: 40, units: 4, name: item.name, is40: true });
        else allContainers.push({ size: item.size, units: item.size / 10, name: item.name, is40: false });
      }
    });
    var trucks = []; var remaining = [];
    allContainers.forEach(function (c) {
      if (c.is40) trucks.push({ containers: [{ size: 40, qty: 1, name: c.name }], units: 4 });
      else remaining.push(c);
    });
    var current = []; var currentUnits = 0;
    remaining.forEach(function (c) {
      if (currentUnits + c.units <= 4) { current.push(c); currentUnits += c.units; }
      else { if (current.length > 0) trucks.push({ containers: scConsolidateNamedTruck(current), units: currentUnits }); current = [c]; currentUnits = c.units; }
    });
    if (current.length > 0) trucks.push({ containers: scConsolidateNamedTruck(current), units: currentUnits });
    var useTrucks = transport === "sin" || descargaModo === "camion";
    var pExtraTruck = typeof silvSea !== "undefined" ? (parseFloat(silvSea.extraTruckCost) || 1350.00) : 1350.00;
    var breakdown = []; var total = 0;
    if (useTrucks) {
      trucks.forEach(function (truck, idx) {
        var label = "Cami\u00f3n " + (idx + 1) + ": " + truck.containers.map(function (c) { return c.qty + "\u00d7" + c.size + "'"; }).join(" + ") + " (DEMO)";
        breakdown.push({ label: label, price: demoPriceSin }); total += demoPriceSin;
      });
    } else {
      trucks.forEach(function (truck, idx) {
        if (idx === 0) {
          /* Camión 1 — con grúa. Regla: ≤20' llenando el camión (4u) = tarifa 40' */
          var truckUnits = truck.units || 0;
          var has40 = truck.containers.some(function(c) { return c.size === 40; });
          if (!has40 && truckUnits >= 4) {
            breakdown.push({ label: "Camión 1 (con grúa) – " + truck.containers.map(function(c){return c.qty+"×"+c.size+"'";}).join(" + ") + " [equiv. 40'] (DEMO)", price: demoPriceC40 });
            total += demoPriceC40;
          } else {
            truck.containers.forEach(function (c) {
              var p = c.size === 40 ? demoPriceC40 : demoPriceC20;
              for (var i = 1; i <= c.qty; i++) { breakdown.push({ label: "Camión 1 (con grúa) – " + c.size + "' (DEMO)", price: p }); total += p; }
            });
          }
        } else {
          /* Camiones extra — sin grúa: demoPriceSin + extra */
          var priceExtra = demoPriceSin + pExtraTruck;
          var lbl = truck.containers.map(function (c) { return c.qty + "\u00d7" + c.size + "'"; }).join(" + ");
          breakdown.push({ label: "Cami\u00f3n " + (idx + 1) + " (sin gr\u00faa + extra): " + lbl + " (DEMO)", price: priceExtra });
          total += priceExtra;
        }
      });
    }
    var numTrucks = trucks.length;
    var transp_label = transport === "sin" ? "sin descarga" : "con descarga";
    var detail = "Selecci\u00f3n completa \u00b7 " + transp_label + " \u00b7 CP " + cp + (numTrucks > 1 ? " \u00b7 " + numTrucks + " camiones" : "");
    return { price: parseFloat(total.toFixed(2)), detail: detail, breakdown: breakdown, trucks: numTrucks, days: 3 };
  }

  function scConsolidateNamedTruck(containers) {
    var map = {};
    containers.forEach(function (c) { var key = c.size + "_" + c.name; if (map[key]) map[key].qty++; else map[key] = { size: c.size, name: c.name, qty: 1 }; });
    return Object.values(map);
  }

  /*  VALIDACIÓN  */
  function scValidate() {
    var origin = document.getElementById("scOriginCity").value;
    var postal = document.getElementById("scPostalCode").value;
    if (!origin) { scShowError(scT('msg_err_select_origin', "Por favor, seleccione la ciudad de salida.")); return false; }
    if (!postal || postal.length < 5) { scShowError(scT('msg_err_invalid_cp', "Introduzca un c\u00f3digo postal v\u00e1lido (5 d\u00edgitos).")); return false; }
    return true;
  }

  /*  ENVÍO AJAX ROBUSTO — distingue fallo de red de respuesta corrupta  */
  function scSendCalc(url, fd) {
    var httpStatus = 0;
    fetch(url, { method: "POST", body: fd })
      .then(function (r) {
        httpStatus = r.status;
        return r.text();
      })
      .then(function (text) {
        var data;
        try {
          data = JSON.parse(text);
        } catch (e) {
          /* El servidor devolvió algo que no es JSON (warning PHP, HTML de error, etc.) */
          console.error("[Silversea] Respuesta no-JSON del servidor (HTTP " + httpStatus + "):\n" + text);
          scShowError(scT('msg_err_server', "El servidor devolvió una respuesta inesperada. Recargue la página e inténtelo de nuevo."));
          return;
        }
        if (data && data.success) {
          if (data.data && data.data.no_tarifa) {
            scShowNoTarifaFlow(data.data);
          } else if (data.data && data.data.price_pending) {
            scShowPricePendingFlow(data.data);
          } else {
            scShowResult(data.data);
          }
        } else {
          scShowError(data && data.data && data.data.message
            ? data.data.message
            : scT('msg_err_calc', "Error al calcular. Inténtelo de nuevo."));
        }
      })
      .catch(function (err) {
        console.error("[Silversea] Falló la conexión AJAX:", err);
        scShowError(scT('msg_err_conn', "Error de conexión. Inténtelo de nuevo."));
      });
  }

  /*  CP SIN TARIFA — flujo alternativo  */
  function scShowNoTarifaFlow(data) {
    document.getElementById("scLoaderArea").classList.add("sc-hidden");
    document.getElementById("scErrorArea").classList.add("sc-hidden");
    document.getElementById("scCalcBtn").disabled = false;

    var area    = document.getElementById("scResultArea");
    var priceEl = document.getElementById("scResultPrice");
    var detailEl= document.getElementById("scResultDetail");
    var breakEl = document.getElementById("scResultBreakdown");
    var daysEl  = document.getElementById("scResultDays");

    area.classList.remove("sc-hidden");
    priceEl.className   = "sc-result-free";
    priceEl.textContent = scT('msg_no_tarifa_title', "Precio de transporte a confirmar");
    detailEl.textContent= scT('msg_no_tarifa_body',
      "Tu código postal no está en nuestra base de datos. Puedes continuar con tu solicitud: el equipo comercial te confirmará el precio de transporte.");
    breakEl.classList.add("sc-hidden");
    daysEl.classList.add("sc-hidden");

    /* Guardar en sesión con price=0 para que el formulario de cotización lo recoja */
    var origin = data.origin || (document.getElementById("scOriginCity") ? document.getElementById("scOriginCity").value : "");
    var cp     = data.cp     || (document.getElementById("scPostalCode") ? document.getElementById("scPostalCode").value : "");
    var sd = new FormData();
    sd.append("action",       "silversea_save_shipping");
    sd.append("nonce",        typeof silvSea !== "undefined" ? silvSea.nonce : "");
    sd.append("method",       "delivery");
    sd.append("origin",       origin);
    sd.append("postal_code",  cp);
    sd.append("transport",    currentTransport);
    sd.append("price",        0);
    sd.append("detail",       "CP sin tarifa");
    fetch(typeof silvSea !== "undefined" ? silvSea.ajaxUrl : "/wp-admin/admin-ajax.php", { method: "POST", body: sd });

    /* Sincronizar hidden fields del form y desbloquear "Añadir a selección" */
    scSyncFormFields({ method: "delivery", origin: origin, cp: cp, transport: currentTransport, price: 0, pickup: "", no_tarifa: true });

    setTimeout(function() { area.scrollIntoView({ behavior: "smooth", block: "nearest" }); }, 50);
  }


  function scShowPricePendingFlow(data) {
    document.getElementById("scLoaderArea").classList.add("sc-hidden");
    document.getElementById("scErrorArea").classList.add("sc-hidden");
    document.getElementById("scCalcBtn").disabled = false;

    var area    = document.getElementById("scResultArea");
    var priceEl = document.getElementById("scResultPrice");
    var detailEl= document.getElementById("scResultDetail");
    var breakEl = document.getElementById("scResultBreakdown");
    var daysEl  = document.getElementById("scResultDays");

    area.classList.remove("sc-hidden");
    priceEl.className   = "sc-result-free";
    priceEl.textContent = "Precio con descarga a confirmar";
    detailEl.textContent= "El servicio con descarga no tiene tarifa cargada para este código postal. Puedes continuar con tu solicitud y el asesor comercial te confirmará el precio al contactarse.";
    breakEl.classList.add("sc-hidden");
    daysEl.classList.add("sc-hidden");

    var origin = data.origin || (document.getElementById("scOriginCity") ? document.getElementById("scOriginCity").value : "");
    var cp     = data.cp     || (document.getElementById("scPostalCode") ? document.getElementById("scPostalCode").value : "");
    var sd = new FormData();
    sd.append("action",        "silversea_save_shipping");
    sd.append("nonce",         typeof silvSea !== "undefined" ? silvSea.nonce : "");
    sd.append("method",        "delivery");
    sd.append("origin",        origin);
    sd.append("postal_code",   cp);
    sd.append("transport",     currentTransport);
    sd.append("price",         0);
    sd.append("detail",        data.detail || "Con descarga a confirmar");
    sd.append("price_pending", 1);
    fetch(typeof silvSea !== "undefined" ? silvSea.ajaxUrl : "/wp-admin/admin-ajax.php", { method: "POST", body: sd });

    scSyncFormFields({ method: "delivery", origin: origin, cp: cp, transport: currentTransport, price: 0, pickup: "", price_pending: true });

    setTimeout(function() { area.scrollIntoView({ behavior: "smooth", block: "nearest" }); }, 50);
  }
  /*  CALCULAR  */
  window.scCalculate = function () {
    scClearResult(); if (!scValidate()) return;
    document.getElementById("scLoaderArea").classList.remove("sc-hidden");
    document.getElementById("scCalcBtn").disabled = true;
    /* Demo consolidado */
    if (demoMode && isConsolidated) {
      setTimeout(function () {
        var cp = document.getElementById("scPostalCode").value;
        scShowResult(scBuildResultConsolidated(cartItems, currentTransport, cp));
      }, 600); return;
    }
    /* Demo single */
    if (demoMode) {
      setTimeout(function () {
        var qty = scGetQty(); var size = parseInt(productSize);
        var pCon = size === 40 ? demoPriceC40 : demoPriceC20;
        var cp = document.getElementById("scPostalCode").value;
        scShowResult(scBuildResult(qty, size, currentTransport, demoPriceSin, pCon, cp));
      }, 600); return;
    }
    /* Real consolidado */
    if (isConsolidated) {
      var payload = {
        action: "silversea_calc_consolidated", nonce: typeof silvSea !== "undefined" ? silvSea.nonce : "",
        method: "delivery", transport: currentTransport,
        origin: document.getElementById("scOriginCity").value,
        postal_code: document.getElementById("scPostalCode").value
      };
      var ajaxUrl2 = typeof silvSea !== "undefined" ? silvSea.ajaxUrl : "/wp-admin/admin-ajax.php";
      var fd2 = new FormData(); Object.keys(payload).forEach(function (k) { fd2.append(k, payload[k]); });
      scSendCalc(ajaxUrl2, fd2);
      return;
    }
    /* Real single */
    var payload = {
      action: "silversea_calc_shipping", nonce: typeof silvSea !== "undefined" ? silvSea.nonce : "",
      method: "delivery", product_size: productSize, quantity: scGetQty(), transport: currentTransport,
      origin: document.getElementById("scOriginCity").value,
      postal_code: document.getElementById("scPostalCode").value
    };
    var ajaxUrl = typeof silvSea !== "undefined" ? silvSea.ajaxUrl : "/wp-admin/admin-ajax.php";
    var formData = new FormData(); Object.keys(payload).forEach(function (k) { formData.append(k, payload[k]); });
    scSendCalc(ajaxUrl, formData);
  };

})();


/* ── DEPÓSITOS — mostrar dirección completa bajo el select ── */
var scDepositos = {};
if (typeof silvSea !== 'undefined' && Array.isArray(silvSea.cities)) {
  silvSea.cities.forEach(function(city) {
    scDepositos[city.key] = { nombre: city.depot, direccion: city.address };
  });
}

function scShowDeposito(city, infoId) {
  var el = document.getElementById(infoId);
  if (!el) return;
  var dep = scDepositos[city];
  if (dep) {
    el.innerHTML = '<strong>' + dep.nombre + '</strong><br>' + dep.direccion;
    el.classList.remove('sc-hidden');
  } else {
    el.classList.add('sc-hidden');
    el.innerHTML = '';
  }
}

/* ── TOOLTIPS ── */
(function () {
  var bubble = null;

  function createBubble(text, trigger) {
    if (bubble) bubble.remove();
    bubble = document.createElement("div");
    bubble.className = "sc-tooltip-bubble";
    bubble.textContent = text;
    document.body.appendChild(bubble);
    positionBubble(trigger);
  }

  function positionBubble(trigger) {
    if (!bubble) return;
    var rect = trigger.getBoundingClientRect();
    var bw   = bubble.offsetWidth;
    var left = rect.left + window.scrollX + rect.width / 2 - bw / 2;
    var top  = rect.bottom + window.scrollY + 8;
    if (left < 8) left = 8;
    if (left + bw > window.innerWidth - 8) left = window.innerWidth - 8 - bw;
    bubble.style.left     = left + "px";
    bubble.style.top      = top  + "px";
    bubble.style.position = "absolute";
  }

  function removeBubble() {
    if (bubble) { bubble.remove(); bubble = null; }
  }

  document.addEventListener("click", function (e) {
    var t = e.target.closest(".sc-tooltip-trigger");
    if (t) {
      e.stopPropagation();
      if (bubble) { removeBubble(); return; }
      var text = (t.dataset.tip || "").replace(/&#10;/g, "\n").replace(/&quot;/g, '"');
      createBubble(text, t);
    } else {
      removeBubble();
    }
  });

  window.addEventListener("scroll", removeBubble, true);
  window.addEventListener("resize", removeBubble);
})();

/* ── REQUERIR COTIZACIÓN ANTES DE AGREGAR ── */
(function() {
  var requireQuote = typeof silvSea !== 'undefined' && silvSea.requireQuote === '1';
  if ( ! requireQuote ) return;

  /* True solo cuando el usuario cotizó durante ESTA visita a la página.
     La cookie sc_prefs solo pre-llena campos, no desbloquea el botón. */
  var quoteConfirmedThisVisit = false;

  document.addEventListener('DOMContentLoaded', function() {
    updateAddBtnState();

    /* Cuando se confirma una cotización nueva, habilitar el botón */
    document.addEventListener('silversea:shipping:saved', function(e) {
      var d = e.detail || {};
      if ( d.method === 'pickup' && d.pickup )                          { quoteConfirmedThisVisit = true; updateAddBtnState(true); }
      if ( d.method === 'delivery' && (d.price > 0 || d.no_tarifa || d.price_pending) ) { quoteConfirmedThisVisit = true; updateAddBtnState(true); }
    });

    /* Interceptar el click en el botón YITH para mostrar mensaje si no hay cotización */
    document.body.addEventListener('click', function(e) {
      var btn = e.target.closest('.yith-ywraq-add-to-quote, .add_to_quote_button, [class*="add-to-quote"]');
      if ( ! btn ) return;
      if ( btn.dataset.silvseaBlocked === '1' ) {
        e.preventDefault();
        e.stopImmediatePropagation();
        scShowRequireQuoteMsg();
        return false;
      }
    }, true);
  });

  function updateAddBtnState(force_enable) {
    /* Solo considerar "cotizado" si ocurrió en esta visita, no por cookie anterior */
    var hasQuote = force_enable || quoteConfirmedThisVisit;

    document.querySelectorAll('.yith-ywraq-add-to-quote, .add_to_quote_button, [class*="add-to-quote"]').forEach(function(btn) {
      if ( hasQuote ) {
        btn.dataset.silvseaBlocked = '0';
        btn.style.opacity = '';
        btn.title = '';
      } else {
        btn.dataset.silvseaBlocked = '1';
        btn.style.opacity = '0.45';
        btn.title = 'Cotizá el envío primero para poder agregar este contenedor';
      }
    });
  }

  function scShowRequireQuoteMsg() {
    var existing = document.getElementById('sc-require-quote-msg');
    if ( existing ) { existing.remove(); }

    var msg = document.createElement('div');
    msg.id = 'sc-require-quote-msg';
    msg.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);'
      + 'background:#0F2557;color:#fff;padding:14px 24px;border-radius:10px;'
      + 'font-size:14px;font-weight:500;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.2);'
      + 'max-width:90vw;text-align:center;';
    msg.textContent = scT('msg_require_quote', 'Cotice el envío antes de añadir este contenedor a su selección.');
    document.body.appendChild(msg);

    setTimeout(function() { if ( msg.parentNode ) msg.remove(); }, 3500);
  }
})();


/* ── COLOR RAL — selector en .sc-qty-row ── */
(function () {
  var colorData = (typeof silvSea !== 'undefined' && silvSea.productColor)
    ? silvSea.productColor
    : { type: 'none', label: '' };

  if (colorData.type === 'none') return;

  /* USADO variable: ocultar la tabla de variaciones nativa y auto-seleccionar
     la primera variación disponible, para que el producto sea agregable sin
     mostrar selector de color (el color es aleatorio). */
  if (colorData.type === 'variable_hidden') {
    document.addEventListener('DOMContentLoaded', function () {
      var table = document.querySelector('table.variations');
      if (table) table.style.display = 'none';

      var sel = document.querySelector('select[name="attribute_pa_color-ral"]');
      if (sel) {
        var chosen = '';
        for (var i = 0; i < sel.options.length; i++) {
          if (sel.options[i].value) { chosen = sel.options[i].value; break; }
        }
        if (chosen) {
          sel.value = chosen;
          sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
    });
    return;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var qtyRow = document.querySelector('.sc-qty-row');
    if (!qtyRow) return;
    var sizeBadge = qtyRow.querySelector('.sc-size-badge');

    var wrapper = document.createElement('div');
    wrapper.className = 'sc-color-group';

    var lbl = document.createElement('span');
    lbl.className = 'sc-color-label';
    lbl.textContent = 'Color';
    wrapper.appendChild(lbl);

    if (colorData.type === 'variable') {
      var origSelect = document.querySelector('select[name="attribute_pa_color-ral"]');
      if (!origSelect) return;

      /* Clonar el select original para mostrarlo dentro del cotizador */
      var mirror = origSelect.cloneNode(true);
      mirror.id  = 'sc-color-mirror';
      mirror.className = 'sc-color-select';
      mirror.removeAttribute('data-attribute_name');
      mirror.removeAttribute('data-show_option_none');
      wrapper.appendChild(mirror);

      /* mirror → original: propaga el change para que WC actualice la variación */
      mirror.addEventListener('change', function () {
        origSelect.value = this.value;
        origSelect.dispatchEvent(new Event('change', { bubbles: true }));
      });

      /* original → mirror: si WC cambia el select (ej. reset), reflejar */
      origSelect.addEventListener('change', function () {
        if (mirror.value !== this.value) mirror.value = this.value;
      });

      /* Ocultar solo la fila del color RAL en la tabla de variaciones.
         Si hay más atributos (tamaño, condición…) se mantienen visibles. */
      var table = document.querySelector('table.variations');
      if (table) {
        var attrRows = table.querySelectorAll('tr');
        if (attrRows.length <= 1) {
          /* Un único atributo → ocultar toda la tabla */
          table.style.display = 'none';
        } else {
          /* Múltiples atributos → solo ocultar la fila del color */
          attrRows.forEach(function(row) {
            if (row.querySelector('select[name="attribute_pa_color-ral"]')) {
              row.style.display = 'none';
            }
          });
        }
      }

    } else if (colorData.type === 'simple') {
      /* Producto simple: mostrar el color único desactivado */
      var sel = document.createElement('select');
      sel.className = 'sc-color-select';
      sel.disabled  = true;
      var opt = document.createElement('option');
      opt.textContent = colorData.label;
      sel.appendChild(opt);
      wrapper.appendChild(sel);
    }

    if (sizeBadge) qtyRow.insertBefore(wrapper, sizeBadge);
    else            qtyRow.appendChild(wrapper);
  });
})();


/* ── EXTRAS WAPO — cards en el cotizador, sincronizadas con bloque nativo ── */
(function () {
  var extras = (typeof silvSea !== 'undefined' && Array.isArray(silvSea.extras) && silvSea.extras.length)
    ? silvSea.extras : [];
  if (!extras.length) return;

  /* Estado global de selección (compartido entre todos los grids y expuesto para scShowResult) */
  var selectedValues = {};
  window.scSelectedExtras = selectedValues;

  /* Devuelve todos los inputs WAPO nativos que coinciden por value */
  function wapoInputsByValue(value) {
    return Array.prototype.slice.call(
      document.querySelectorAll('.yith-wapo-option-value[value="' + value.replace(/"/g, '\\"') + '"]')
    );
  }

  /* Actualiza visualmente una card (sin disparar su click) */
  function setCardState(card, isSelected) {
    card.classList.toggle('selected', isSelected);
    var checkEl = card.querySelector('.sc-extra-check');
    if (checkEl) checkEl.classList.toggle('checked', isSelected);
  }

  /* Actualiza TODAS las cards de todos los grids para un valor dado */
  function syncAllCards(value, isSelected) {
    document.querySelectorAll('.sc-extras-grid [data-extra-value="' + value.replace(/"/g, '\\"') + '"]')
      .forEach(function (card) { setCardState(card, isSelected); });
  }

  /* Crea y devuelve una card para un extra dado */
  function buildCard(extra) {
    var card = document.createElement('div');
    card.className = 'sc-extra-card';
    card.dataset.extraValue = extra.value;

    var check = document.createElement('span');
    check.className = 'sc-extra-check';
    card.appendChild(check);

    if (extra.icon) {
      var img = document.createElement('img');
      img.src = extra.icon;
      img.className = 'sc-extra-icon';
      img.alt = '';
      card.appendChild(img);
    }

    var lbl = document.createElement('span');
    lbl.className = 'sc-extra-label';
    lbl.textContent = extra.label;
    card.appendChild(lbl);

    if (extra.desc) {
      var desc = document.createElement('span');
      desc.className = 'sc-extra-desc';
      desc.textContent = extra.desc;
      card.appendChild(desc);
    }

    /* if (extra.price && extra.price > 0) {
      var priceEl = document.createElement('span');
      priceEl.className = 'sc-extra-price';
      priceEl.textContent = 'Desde ' + extra.price.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
      card.appendChild(priceEl);
    } */

    /* Card → sincronizar todos los grids + WAPO nativo */
    card.addEventListener('click', function () {
      var isSelected = !selectedValues[extra.value];
      selectedValues[extra.value] = isSelected;

      /* Actualizar todas las cards del mismo extra en todos los grids */
      syncAllCards(extra.value, isSelected);

      /* Togglear inputs WAPO nativos */
      wapoInputsByValue(extra.value).forEach(function (inp) {
        if (inp.checked !== isSelected) {
          inp.checked = isSelected;
          inp.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    });

    /* Reflejar estado actual */
    if (selectedValues[extra.value]) setCardState(card, true);

    return card;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var grids = document.querySelectorAll('.sc-extras-grid');
    if (!grids.length) return;

    /* ── Render cards en cada grid ── */
    grids.forEach(function (grid) {
      extras.forEach(function (extra) {
        grid.appendChild(buildCard(extra));
      });
    });

    /* ── Sync inicial: reflejar selecciones por defecto de WAPO ── */
    document.querySelectorAll('.yith-wapo-option-value').forEach(function (inp) {
      if (!inp.checked) return;
      selectedValues[inp.value] = true;
      syncAllCards(inp.value, true);
    });

    /* ── WAPO nativo → todos los grids ── */
    document.addEventListener('change', function (e) {
      if (!e.target || !e.target.classList.contains('yith-wapo-option-value')) return;
      var value = e.target.value;
      selectedValues[value] = e.target.checked;
      syncAllCards(value, e.target.checked);
    });

    /* ── Inyectar en form.cart antes de que YITH envíe ── */
    var form = document.querySelector('form.cart');
    if (!form) return;
    var yithBtn = form.querySelector('.yith-ywraq-add-to-quote, [name="yith_ywraq_add_item"]');
    if (!yithBtn) return;

    yithBtn.addEventListener('click', function () {
      form.querySelectorAll('.sc-extra-injected').forEach(function (el) { el.remove(); });

      /* Siempre inyectar los extras seleccionados como campos ocultos.
         silversea_intercept_wapo deduplica en el servidor, por lo que no
         hay conflicto si YITH WAPO también envía sus propios campos. */
      extras.forEach(function (extra) {
        if (!selectedValues[extra.value]) return;
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.className = 'sc-extra-injected';
        inp.name = extra.name; inp.value = extra.value;
        form.appendChild(inp);
        /* Inyectar también el precio del addon */
        if (extra.price && extra.price > 0) {
          var priceInp = document.createElement('input');
          priceInp.type = 'hidden'; priceInp.className = 'sc-extra-injected';
          priceInp.name = 'silversea_addon_price[' + extra.value + ']';
          priceInp.value = extra.price;
          form.appendChild(priceInp);
        }
      });
    }, true);
  });
})();


/* ── VALIDAR COLOR ANTES DE AÑADIR (productos variables) ── */
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    /* Solo activo en productos variables con selector de color */
    var mirror = document.getElementById('sc-color-mirror');
    if (!mirror) return;

    /* Captura el click antes que YITH (capture: true, después del bloqueo de requireQuote) */
    document.body.addEventListener('click', function (e) {
      var btn = e.target.closest('.yith-ywraq-add-to-quote, .add_to_quote_button, [class*="add-to-quote"]');
      if (!btn) return;

      /* Si el botón ya está bloqueado por requireQuote (silvseaBlocked='1'),
         ese listener ya habrá hecho stopImmediatePropagation y nunca llegamos acá.
         Solo validamos color cuando el quote está OK. */
      if (!mirror.value) {
        e.preventDefault();
        e.stopImmediatePropagation();

        /* Resaltar el selector con borde rojo + animación */
        mirror.classList.add('sc-color-error');
        mirror.scrollIntoView({ behavior: 'smooth', block: 'center' });

        /* Quitar el error cuando el usuario elija un color */
        mirror.addEventListener('change', function onColorPicked() {
          mirror.classList.remove('sc-color-error');
          mirror.removeEventListener('change', onColorPicked);
        });

        /* Toast de error */
        scShowColorRequiredMsg();
        return false;
      }
    }, true);
  });

  function scShowColorRequiredMsg() {
    var existing = document.getElementById('sc-color-required-msg');
    if (existing) existing.remove();

    var msg = document.createElement('div');
    msg.id = 'sc-color-required-msg';
    msg.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);'
      + 'background:#dc2626;color:#fff;padding:14px 24px;border-radius:10px;'
      + 'font-size:14px;font-weight:500;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.2);'
      + 'max-width:90vw;text-align:center;';
    msg.textContent = scT('msg_color_required', 'Seleccione un color RAL antes de añadir a su selección.');
    document.body.appendChild(msg);
    setTimeout(function () { if (msg.parentNode) msg.remove(); }, 3500);
  }
})();


/* ── AUTOCOMPLETE DE CÓDIGO POSTAL ───────────────────────────
   Sugiere los CP cargados para la ciudad de origen seleccionada.
   Sirve también para ver qué códigos existen en la base.
──────────────────────────────────────────────────────────── */
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var cpInput   = document.getElementById('scPostalCode');
    var originSel = document.getElementById('scOriginCity');
    if (!cpInput || !originSel || typeof silvSea === 'undefined') return;

    var list = document.createElement('datalist');
    list.id = 'scCpList';
    document.body.appendChild(list);
    cpInput.setAttribute('list', 'scCpList');
    cpInput.setAttribute('autocomplete', 'off');

    var timer = null;

    cpInput.addEventListener('input', function () {
      var origin = originSel.value;
      var term   = cpInput.value.replace(/\D/g, '');
      if (!origin || term.length < 2) { list.innerHTML = ''; return; }
      clearTimeout(timer);
      timer = setTimeout(function () { fetchSuggest(origin, term); }, 250);
    });

    /* Si cambia la ciudad, limpiar sugerencias previas */
    originSel.addEventListener('change', function () { list.innerHTML = ''; });

    function fetchSuggest(origin, term) {
      var fd = new FormData();
      fd.append('action', 'silversea_cp_suggest');
      fd.append('nonce', silvSea.nonce);
      fd.append('origin', origin);
      fd.append('term', term);
      fetch(silvSea.ajaxUrl, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res || !res.success || !Array.isArray(res.data)) { list.innerHTML = ''; return; }
          list.innerHTML = res.data.map(function (row) {
            var label = row.municipio_destino
              ? (row.cp_destino + ' — ' + row.municipio_destino)
              : row.cp_destino;
            return '<option value="' + row.cp_destino + '">' + label + '</option>';
          }).join('');
        })
        .catch(function () { /* silencioso */ });
    }
  });
})();

/* Filtrado de galería por color — manejado por color-gallery.js */

/* ── PRECIO DINÁMICO POR CIUDAD ──────────────────────────────
   Actualiza el precio del producto en la página cuando el usuario
   selecciona una ciudad en el cotizador.
──────────────────────────────────────────────────────────── */
(function () {
  var showProductPrice = (typeof silvSea !== 'undefined' && silvSea.showProductPrice === '1');
  var isModeSingle     = (typeof silvSea !== 'undefined' && silvSea.mode === 'single');
  var cityPrices = (typeof silvSea !== 'undefined' && silvSea.productCityPrices)
    ? silvSea.productCityPrices : {};

  if (!isModeSingle) return;
  if (!showProductPrice && !Object.keys(cityPrices).length) return;

  var $ = typeof jQuery !== 'undefined' ? jQuery : null;

  function formatPrice(value) {
    return parseFloat(value).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /* Devuelve nuestro elemento de precio, creandolo por JS si el hook PHP no disparo */
  function getOurEl() {
    var el = document.getElementById('silversea-product-price');
    if (!el && showProductPrice) {
      el = document.createElement('div');
      el.id = 'silversea-product-price';
      el.className = 'price silversea-product-price-display';
      el.style.display = 'none';
      var form = document.querySelector('form.cart');
      if (form) form.parentNode.insertBefore(el, form);
    }
    return el;
  }

  function applyPrice(city) {
    /* Nuestro elemento: siempre actualizado cuando el toggle esta ON */
    if (showProductPrice) {
      var el = getOurEl();
      if (el) {
        if (!city) {
          el.style.display = 'none';
          el.innerHTML = '';
        } else if (cityPrices[city] && parseFloat(cityPrices[city]) > 0) {
          el.style.display = '';
          el.innerHTML = '<span class="woocommerce-Price-amount amount">'
            + '<bdi><span class="woocommerce-Price-currencySymbol">&euro;&nbsp;</span>'
            + formatPrice(parseFloat(cityPrices[city])) + '</bdi></span>';
        } else {
          el.style.display = '';
          el.innerHTML = '<span class="silversea-price-unavailable">No disponible</span>';
        }
      }
    }

    /* Elemento .price nativo de WC (solo actualizado cuando hay precio) */
    if (city && cityPrices[city] && parseFloat(cityPrices[city]) > 0) {
      var price = parseFloat(cityPrices[city]);
      var html  = '<span class="woocommerce-Price-amount amount">'
                + '<bdi><span class="woocommerce-Price-currencySymbol">&euro;&nbsp;</span>'
                + formatPrice(price) + '</bdi></span>';
      if ($) {
        var $np = $('form.cart').closest('.product').find('.price').first();
        if ($np.length) $np.html(html).attr('data-sc-city', city);
      } else {
        var np = document.querySelector('.product .price');
        if (np) { np.innerHTML = html; np.dataset.scCity = city; }
      }
    }
  }

  window.scUpdateProductPrice = applyPrice;

  /* Re-aplicar cuando WC actualiza precio al seleccionar variacion */
  if ($) {
    $(function() {
      $('form.cart').on('found_variation', function() {
        var city = $('form.cart').closest('.product').find('.price').first().attr('data-sc-city');
        if (city) setTimeout(function() { applyPrice(city); }, 20);
      });
    });
  }
})();