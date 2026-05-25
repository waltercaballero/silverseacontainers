/* ─────────────────────────────────────────────────────────────
   Silversea Color Gallery Filter  v1.1
   Filtra imágenes del gallery WooCommerce según el color RAL
   seleccionado en el dropdown de variaciones.

   Estructura real detectada en producción:
   ▸ Slides:     div.woocommerce-product-gallery__image[data-thumb="URL"]
                   └─ a > img[data-sc-image-id]  ← inyectado por PHP
   ▸ Thumbnails: ol.flex-control-thumbs > li > img[src="URL-100x100"]
                   (src coincide con data-thumb del slide padre)
───────────────────────────────────────────────────────────── */

(function () {
  'use strict';

  if (typeof scColorGallery === 'undefined') return;
  if (!Object.keys(scColorGallery.colorMap).length) return;

  /* ── Mapa inverso: imageId → colorSlug ── */
  var idToColor = {};
  Object.keys(scColorGallery.colorMap).forEach(function (color) {
    scColorGallery.colorMap[color].forEach(function (id) {
      idToColor[id] = color;
    });
  });

  /**
   * Construye mapa: thumbUrl → colorSlug
   * Cada slide tiene data-thumb="URL" + un img[data-sc-image-id] adentro.
   * El thumbnail nav usa esa misma URL como src.
   */
  function buildThumbUrlToColor() {
    var map = {};
    var slideImgs = document.querySelectorAll(
      '.woocommerce-product-gallery__image img[data-sc-image-id]'
    );
    slideImgs.forEach(function (img) {
      var imgId    = parseInt(img.getAttribute('data-sc-image-id'), 10);
      var color    = idToColor[imgId];
      if (!color) return;
      var slideDiv = img.closest('.woocommerce-product-gallery__image');
      var thumbUrl = slideDiv ? slideDiv.getAttribute('data-thumb') : null;
      if (thumbUrl) map[thumbUrl] = color;
    });
    return map;
  }

  /**
   * Agrega data-sc-color a cada slide div y a cada thumb li.
   * Elementos sin tag = "genéricos", siempre visibles.
   */
  function tagElements(thumbUrlToColor) {
    /* Slides */
    document.querySelectorAll(
      '.woocommerce-product-gallery__image img[data-sc-image-id]'
    ).forEach(function (img) {
      var imgId    = parseInt(img.getAttribute('data-sc-image-id'), 10);
      var color    = idToColor[imgId];
      var slideDiv = img.closest('.woocommerce-product-gallery__image');
      if (slideDiv && color) slideDiv.setAttribute('data-sc-color', color);
    });

    /* Thumbnails */
    document.querySelectorAll('.flex-control-thumbs li').forEach(function (li) {
      var img   = li.querySelector('img');
      var src   = img ? img.getAttribute('src') : null;
      var color = src ? thumbUrlToColor[src] : null;
      if (color) li.setAttribute('data-sc-color', color);
    });
  }

  /* ── Filtrado ─────────────────────────────────────────────
     Thumbnails: display:none (están en <ol>, no afectan al slider)
     Slides: data-sc-hidden + opacity (conservan dimensiones para
             no romper el posicionamiento de FlexSlider)
  ────────────────────────────────────────────────────────── */

  function filterGallery(selectedColor) {

    /* Thumbnails */
    document.querySelectorAll('.flex-control-thumbs li').forEach(function (li) {
      var color = li.getAttribute('data-sc-color');
      /*
       * Sin selección  → todo visible.
       * Con selección  → solo el color elegido; genéricas (sin color) también
       *                  se ocultan porque son imágenes de otras variaciones.
       */
      var show = !selectedColor || color === selectedColor;
      li.style.display = show ? '' : 'none';
    });

    /* Slides — marcar como hidden sin sacarlos del flujo */
    document.querySelectorAll('.woocommerce-product-gallery__image').forEach(function (div) {
      var color = div.getAttribute('data-sc-color');
      var show  = !selectedColor || color === selectedColor;
      div.setAttribute('data-sc-hidden', show ? '0' : '1');
    });

    /* Navegar al primer slide del color elegido */
    if (selectedColor) {
      var firstColorThumb = document.querySelector(
        '.flex-control-thumbs li[data-sc-color="' + selectedColor + '"] img'
      );
      if (firstColorThumb) {
        setTimeout(function () { firstColorThumb.click(); }, 60);
      }
    }
  }

  /* ── Interceptar FlexSlider para saltear slides ocultos ──
     Evita que prev/next muestre un slide en blanco.
  ────────────────────────────────────────────────────────── */

  function hookFlexSlider() {
    if (typeof jQuery === 'undefined') return;
    var $gallery = jQuery('.woocommerce-product-gallery');
    if (!$gallery.length) return;
    if ($gallery.data('sc-hooked')) return; /* evitar doble hook */
    $gallery.data('sc-hooked', true);

    $gallery.on('before.flexslider', function (e, slider) {
      var targetIdx = slider.animatingTo;
      var $target   = jQuery(slider.slides[targetIdx]);
      if ($target.attr('data-sc-hidden') !== '1') return;

      var dir   = slider.direction === 'next' ? 1 : -1;
      var count = slider.count;
      for (var i = 1; i < count; i++) {
        var next = ((targetIdx + dir * i) % count + count) % count;
        if (jQuery(slider.slides[next]).attr('data-sc-hidden') !== '1') {
          e.preventDefault();
          (function (idx) {
            setTimeout(function () { slider.flexAnimate(idx, true); }, 0);
          })(next);
          return;
        }
      }
      e.preventDefault(); /* todos ocultos */
    });
  }

  /* ── Init ─────────────────────────────────────────────── */

  document.addEventListener('DOMContentLoaded', function () {

    var select = document.querySelector(
      'select[name="' + scColorGallery.attrKey + '"]'
    );
    if (!select) return;

    var thumbUrlToColor = buildThumbUrlToColor();

    /* Esperar un tick para que FlexSlider construya los thumbnails */
    setTimeout(function () {
      tagElements(thumbUrlToColor);

      select.addEventListener('change', function () {
        filterGallery(select.value || null);
      });

      if (typeof jQuery !== 'undefined') {
        /* Reset al limpiar las variaciones */
        jQuery('.variations_form').on('reset_data', function () {
          filterGallery(null);
        });

        /* Hook FlexSlider */
        jQuery(document).on('wc-product-gallery-after-init', hookFlexSlider);
        setTimeout(hookFlexSlider, 1000);
      }

      if (select.value) filterGallery(select.value);

    }, 200);
  });

})();
