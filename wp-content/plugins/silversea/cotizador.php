<?php

/* Cotizador */

require_once plugin_dir_path( __FILE__ ) . 'includes/shipping-calculator.php';

/**
 * Usado en el listado de productos, para mostrar el total de resultados
 */
add_shortcode( 'product_count', function() {
	$count_posts = wp_count_posts( 'product' );
	return $count_posts->publish . ' Resultados';
} );

/**
 * Usado en el listado de productos, para mostrar el badge de USADO
 */
add_shortcode('badge_usado', function() {
    global $product;

    $product_id = $product ? $product->get_id() : get_the_ID();

    if ( ! $product_id ) return '';

    if ( has_term( 'usado', 'pa_condicion', $product_id ) ) {
        return 'usado';
    }

    return '';
});

/**
 * Ordenador de productos con drag & drop — modifica menu_order
 * Editor masivo de precios — regular + oferta para simples y variaciones
 */
add_action( 'admin_menu', function() {
    add_submenu_page(
        'silversea-cotizador',
        'Editar Precios',
        'Precios',
        'manage_woocommerce',
        'silversea-product-prices',
        'silversea_render_product_prices_page'
    );
    add_submenu_page(
        'silversea-cotizador',
        'Ordenar Productos',
        'Ordenar',
        'manage_woocommerce',
        'silversea-product-order',
        'silversea_render_product_order_page'
    );
} );

add_action( 'wp_ajax_silversea_save_product_order', function() {
    check_ajax_referer( 'silversea_product_order', 'nonce' );
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error( null, 403 );

    $ids = array_map( 'intval', (array)( $_POST['ids'] ?? [] ) );
    foreach ( $ids as $pos => $id ) {
        if ( $id > 0 ) wp_update_post( ['ID' => $id, 'menu_order' => $pos] );
    }
    wp_send_json_success( ['saved' => count($ids)] );
} );

function silversea_render_product_order_page() {
    $cat_id = (int) ( $_GET['cat'] ?? 0 );
    $search = sanitize_text_field( $_GET['s'] ?? '' );

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
    ];
    if ( $cat_id ) {
        $args['tax_query'] = [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id]];
    }
    if ( $search ) {
        $args['s'] = $search;
    }

    $products   = get_posts( $args );
    $categories = get_terms( ['taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name'] );

    wp_enqueue_script( 'jquery-ui-sortable' );
    ?>
    <div class="wrap" style="max-width:860px;">
      <h1 style="display:flex;align-items:center;gap:10px;">
        ↕ Ordenar Productos
        <span style="font-size:13px;font-weight:400;color:#6b7280;">
          — el orden se aplica cuando Elementor usa <em>Orden del menú</em>
        </span>
      </h1>

      <!-- Filtros -->
      <form method="get" style="display:flex;gap:10px;align-items:center;margin:16px 0;">
        <input type="hidden" name="post_type" value="product">
        <input type="hidden" name="page" value="silversea-product-order">
        <select name="cat" onchange="this.form.submit()" style="height:34px;">
          <option value="">Todas las categorías</option>
          <?php foreach ( $categories as $cat ) : ?>
            <option value="<?php echo $cat->term_id; ?>" <?php selected( $cat_id, $cat->term_id ); ?>>
              <?php echo esc_html( $cat->name ); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
               placeholder="Buscar…" style="width:200px;height:34px;padding:0 8px;">
        <button type="submit" class="button">Filtrar</button>
        <?php if ( $cat_id || $search ) : ?>
          <a href="?post_type=product&page=silversea-product-order" class="button">✕ Limpiar</a>
        <?php endif; ?>
        <span style="margin-left:4px;font-size:13px;color:#9ca3af;">
          <?php echo count($products); ?> producto<?php echo count($products) !== 1 ? 's' : ''; ?>
        </span>
      </form>

      <!-- Botón guardar -->
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">
        <button id="spo-save" class="button button-primary" style="height:36px;font-size:14px;padding:0 20px;">
          💾 Guardar orden
        </button>
        <span id="spo-status" style="font-size:13px;"></span>
      </div>

      <!-- Lista ordenable -->
      <?php if ( empty($products) ) : ?>
        <p style="color:#9ca3af;text-align:center;padding:40px;">No se encontraron productos.</p>
      <?php else : ?>
      <ul id="spo-list">
        <?php foreach ( $products as $i => $post ) :
            $product = wc_get_product( $post->ID );
            if ( ! $product ) continue;
            $thumb  = get_the_post_thumbnail_url( $post->ID, [56, 56] ) ?: wc_placeholder_img_src( [56, 56] );
            $sku    = $product->get_sku() ?: '—';
            $is_var = $product->is_type('variable');
        ?>
        <li data-id="<?php echo $post->ID; ?>">
          <span class="spo-handle" title="Arrastrar">⠿</span>
          <span class="spo-pos"><?php echo $i + 1; ?></span>
          <img src="<?php echo esc_url($thumb); ?>" width="44" height="44" alt="">
          <span class="spo-name"><?php echo esc_html( $post->post_title ); ?></span>
          <code class="spo-sku"><?php echo esc_html($sku); ?></code>
          <span class="spo-type <?php echo $is_var ? 'spo-var' : 'spo-simple'; ?>">
            <?php echo $is_var ? 'Variable' : 'Simple'; ?>
          </span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <style>
    #spo-list {
      list-style: none; margin: 0; padding: 0;
    }
    #spo-list li {
      display: flex; align-items: center; gap: 12px;
      background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
      padding: 10px 14px; margin-bottom: 6px;
      transition: box-shadow .15s, background .15s;
      user-select: none;
    }
    #spo-list li:hover { background: #f8fafc; box-shadow: 0 2px 8px rgba(0,0,0,.07); }
    #spo-list li.ui-sortable-helper {
      box-shadow: 0 8px 24px rgba(0,0,0,.14); background: #eff6ff; cursor: grabbing;
    }
    #spo-list li.ui-sortable-placeholder {
      visibility: visible !important;
      background: #eff6ff; border: 2px dashed #93c5fd; border-radius: 8px;
    }
    .spo-handle {
      font-size: 20px; color: #d1d5db; cursor: grab; flex-shrink: 0; line-height: 1;
    }
    .spo-handle:hover { color: #6b7280; }
    .spo-pos {
      font-size: 12px; color: #9ca3af; min-width: 26px;
      text-align: right; flex-shrink: 0;
    }
    #spo-list img {
      border-radius: 5px; object-fit: cover; flex-shrink: 0;
      border: 1px solid #e5e7eb;
    }
    .spo-name  { flex: 1; font-size: 14px; font-weight: 500; color: #111; }
    .spo-sku   { font-size: 11px; background: #f3f4f6; padding: 2px 7px; border-radius: 3px; font-family: monospace; flex-shrink: 0; }
    .spo-type  { font-size: 11px; padding: 2px 9px; border-radius: 4px; flex-shrink: 0; }
    .spo-var   { color: #7c3aed; background: #f5f3ff; }
    .spo-simple{ color: #059669; background: #f0fdf4; }
    </style>

    <script>
    jQuery(function($) {
      var nonce = '<?php echo wp_create_nonce('silversea_product_order'); ?>';

      $('#spo-list').sortable({
        handle:      '.spo-handle',
        placeholder: 'ui-sortable-placeholder',
        tolerance:   'pointer',
        update: function() {
          /* Actualizar números de posición en tiempo real */
          $('#spo-list li').each(function(i) {
            $(this).find('.spo-pos').text(i + 1);
          });
        }
      });

      $('#spo-save').on('click', function() {
        var $btn    = $(this);
        var $status = $('#spo-status');
        var ids     = $('#spo-list li').map(function() { return $(this).data('id'); }).get();

        $btn.prop('disabled', true).text('Guardando…');
        $status.text('').css('color', '#6b7280');

        $.post(ajaxurl, { action: 'silversea_save_product_order', nonce: nonce, ids: ids })
          .done(function(res) {
            if (res.success) {
              $status.css('color', '#059669').text('✓ Orden guardado — ' + ids.length + ' productos actualizados.');
            } else {
              $status.css('color', '#dc2626').text('✗ Error al guardar.');
            }
          })
          .fail(function() { $status.css('color', '#dc2626').text('✗ Error de conexión.'); })
          .always(function() {
            $btn.prop('disabled', false).text('💾 Guardar orden');
            setTimeout(function() { $status.text(''); }, 5000);
          });
      });
    });
    </script>
    <?php
}

/* ── AJAX: guardar precios ── */
add_action( 'wp_ajax_silversea_save_product_prices', function() {
    check_ajax_referer( 'silversea_product_prices', 'nonce' );
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error( null, 403 );

    $items = json_decode( stripslashes( $_POST['items'] ?? '[]' ), true );
    if ( ! is_array($items) ) wp_send_json_error( ['message' => 'Datos inválidos.'] );

    $parents_to_sync = [];
    $saved = 0;

    foreach ( $items as $item ) {
        $id   = (int) ( $item['id']      ?? 0 );
        $reg  = trim( $item['regular']   ?? '' );
        $sale = trim( $item['sale']      ?? '' );
        if ( ! $id || $reg === '' ) continue;

        $p = wc_get_product( $id );
        if ( ! $p ) continue;

        $p->set_regular_price( wc_format_decimal($reg) );
        $p->set_sale_price( $sale !== '' ? wc_format_decimal($sale) : '' );
        $p->save();

        if ( $p->is_type('variation') )
            $parents_to_sync[ $p->get_parent_id() ] = true;

        $saved++;
    }

    foreach ( array_keys($parents_to_sync) as $parent_id )
        WC_Product_Variable::sync( $parent_id );

    wp_send_json_success( ['saved' => $saved] );
} );

/* ── Página: editor masivo de precios ── */
function silversea_render_product_prices_page() {
    $cat_id = (int) ( $_GET['cat'] ?? 0 );
    $search = sanitize_text_field( $_GET['s'] ?? '' );

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ];
    if ( $cat_id ) $args['tax_query'] = [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id]];
    if ( $search ) $args['s'] = $search;

    $products   = get_posts( $args );
    $categories = get_terms( ['taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name'] );

    /* Helper: label legible para los atributos de una variación */
    $variation_label = function( WC_Product_Variation $var ) {
        $parts = [];
        foreach ( $var->get_variation_attributes() as $attr => $slug ) {
            if ( ! $slug ) { $parts[] = 'Cualquiera'; continue; }
            $tax  = str_replace( 'attribute_', '', $attr );
            $term = taxonomy_exists($tax) ? get_term_by('slug', $slug, $tax) : false;
            $parts[] = $term ? $term->name : $slug;
        }
        return implode( ' / ', $parts ) ?: 'Variación #' . $var->get_id();
    };
    ?>
    <div class="wrap" style="max-width:980px;">
      <h1>€ Editar Precios</h1>

      <!-- Filtros -->
      <form method="get" style="display:flex;gap:10px;align-items:center;margin:16px 0;">
        <input type="hidden" name="post_type" value="product">
        <input type="hidden" name="page" value="silversea-product-prices">
        <select name="cat" onchange="this.form.submit()" style="height:34px;">
          <option value="">Todas las categorías</option>
          <?php foreach ( $categories as $cat ) : ?>
            <option value="<?php echo $cat->term_id; ?>" <?php selected($cat_id, $cat->term_id); ?>>
              <?php echo esc_html($cat->name); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
               placeholder="Buscar…" style="width:200px;height:34px;padding:0 8px;">
        <button type="submit" class="button">Filtrar</button>
        <?php if ( $cat_id || $search ) : ?>
          <a href="?post_type=product&page=silversea-product-prices" class="button">✕ Limpiar</a>
        <?php endif; ?>
        <span style="font-size:13px;color:#9ca3af;"><?php echo count($products); ?> productos</span>
      </form>

      <!-- Acciones -->
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <button id="spp-save" class="button button-primary" style="height:36px;padding:0 20px;font-size:14px;">
          💾 Guardar precios
        </button>
        <button id="spp-expand-all" class="button" style="height:36px;">↕ Expandir todo</button>
        <span id="spp-status" style="font-size:13px;"></span>
      </div>

      <?php if ( empty($products) ) : ?>
        <p style="color:#9ca3af;text-align:center;padding:48px;">No se encontraron productos.</p>
      <?php else : ?>
      <table id="spp-table" class="wp-list-table widefat fixed">
        <thead>
          <tr>
            <th style="width:44px;"></th>
            <th>Producto / Variación</th>
            <th style="width:130px;">SKU</th>
            <th style="width:150px;">Precio regular €</th>
            <th style="width:150px;">Precio oferta €</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ( $products as $post ) :
            $product = wc_get_product( $post->ID );
            if ( ! $product ) continue;
            $thumb  = get_the_post_thumbnail_url( $post->ID, [40,40] ) ?: wc_placeholder_img_src([40,40]);
            $sku    = $product->get_sku() ?: '—';
            $is_var = $product->is_type('variable');
        ?>

        <?php if ( $is_var ) :
            $variation_ids = $product->get_children();
        ?>
          <!-- Variable: fila cabecera (sin inputs de precio) -->
          <tr class="spp-parent">
            <td style="text-align:center;padding:8px 4px;">
              <img src="<?php echo esc_url($thumb); ?>" width="34" height="34"
                   style="border-radius:4px;object-fit:cover;vertical-align:middle;">
            </td>
            <td>
              <strong><?php echo esc_html($post->post_title); ?></strong>
              <span class="spp-badge spp-var">Variable · <?php echo count($variation_ids); ?> var.</span>
              <button type="button" class="spp-toggle button button-small"
                      data-parent="<?php echo $post->ID; ?>">▼ Ver</button>
            </td>
            <td><code class="spp-sku"><?php echo esc_html($sku); ?></code></td>
            <td colspan="2" style="color:#9ca3af;font-size:12px;font-style:italic;">
              <?php echo $product->get_price_html(); ?>
            </td>
          </tr>

          <!-- Variable: filas de variaciones (ocultas por defecto) -->
          <?php foreach ( $variation_ids as $vid ) :
              $var = wc_get_product($vid);
              if ( ! $var ) continue;
          ?>
          <tr class="spp-variation spp-child-<?php echo $post->ID; ?>" style="display:none;">
            <td style="background:#f8fafc;"></td>
            <td style="background:#f8fafc;padding-left:40px;">
              <span style="color:#6b7280;font-size:13px;">
                ↳ <?php echo esc_html( $variation_label($var) ); ?>
              </span>
            </td>
            <td style="background:#f8fafc;">
              <code class="spp-sku"><?php echo esc_html($var->get_sku() ?: '—'); ?></code>
            </td>
            <td style="background:#f8fafc;">
              <input type="number" class="spp-input" step="0.01" min="0"
                     data-id="<?php echo $vid; ?>" data-field="regular"
                     value="<?php echo esc_attr($var->get_regular_price()); ?>"
                     placeholder="0.00">
            </td>
            <td style="background:#f8fafc;">
              <input type="number" class="spp-input" step="0.01" min="0"
                     data-id="<?php echo $vid; ?>" data-field="sale"
                     value="<?php echo esc_attr($var->get_sale_price()); ?>"
                     placeholder="Sin oferta">
            </td>
          </tr>
          <?php endforeach; ?>

        <?php else : // Simple ?>
          <tr class="spp-simple">
            <td style="text-align:center;padding:8px 4px;">
              <img src="<?php echo esc_url($thumb); ?>" width="34" height="34"
                   style="border-radius:4px;object-fit:cover;vertical-align:middle;">
            </td>
            <td>
              <?php echo esc_html($post->post_title); ?>
              <span class="spp-badge spp-simple-badge">Simple</span>
            </td>
            <td><code class="spp-sku"><?php echo esc_html($sku); ?></code></td>
            <td>
              <input type="number" class="spp-input" step="0.01" min="0"
                     data-id="<?php echo $post->ID; ?>" data-field="regular"
                     value="<?php echo esc_attr($product->get_regular_price()); ?>"
                     placeholder="0.00">
            </td>
            <td>
              <input type="number" class="spp-input" step="0.01" min="0"
                     data-id="<?php echo $post->ID; ?>" data-field="sale"
                     value="<?php echo esc_attr($product->get_sale_price()); ?>"
                     placeholder="Sin oferta">
            </td>
          </tr>
        <?php endif; ?>

        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <style>
    #spp-table th, #spp-table td { vertical-align: middle; }
    #spp-table tbody tr:nth-child(odd) { background: transparent; }
    .spp-badge { display:inline-block; font-size:11px; padding:2px 8px; border-radius:4px; margin-left:6px; vertical-align:middle; }
    .spp-var          { background:#f5f3ff; color:#7c3aed; }
    .spp-simple-badge { background:#f0fdf4; color:#059669; }
    .spp-sku  { font-size:11px; background:#f3f4f6; padding:2px 6px; border-radius:3px; font-family:monospace; }
    .spp-input {
      width:120px; height:30px; padding:0 6px; font-size:13px;
      border:1px solid #d1d5db; border-radius:4px; box-sizing:border-box;
      transition: border-color .15s;
    }
    .spp-input:focus  { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; outline:none; }
    .spp-input.spp-dirty { border-color:#f59e0b; background:#fffbeb; }
    .spp-toggle { font-size:11px !important; padding:1px 8px !important; margin-left:8px; }
    .spp-parent td { border-top: 2px solid #e5e7eb; }
    </style>

    <script>
    jQuery(function($) {
      var nonce     = '<?php echo wp_create_nonce('silversea_product_prices'); ?>';
      var allOpen   = false;

      /* Marcar campo como modificado */
      $(document).on('input', '.spp-input', function() { $(this).addClass('spp-dirty'); });

      /* Expand / colapsar una fila variable */
      $(document).on('click', '.spp-toggle', function() {
        var pid    = $(this).data('parent');
        var $rows  = $('.spp-child-' + pid);
        var isOpen = $rows.first().is(':visible');
        $rows.toggle(!isOpen);
        $(this).text(isOpen ? '▼ Ver' : '▲ Ocultar');
      });

      /* Expandir / colapsar todo */
      $('#spp-expand-all').on('click', function() {
        allOpen = !allOpen;
        $('.spp-variation').toggle(allOpen);
        $('.spp-toggle').text(allOpen ? '▲ Ocultar' : '▼ Ver');
        $(this).text(allOpen ? '↕ Colapsar todo' : '↕ Expandir todo');
      });

      /* Guardar */
      $('#spp-save').on('click', function() {
        var $btn    = $(this);
        var $status = $('#spp-status');

        /* Agrupar inputs por product/variation ID */
        var map = {};
        $('.spp-input').each(function() {
          var id    = $(this).data('id');
          var field = $(this).data('field');
          if (!map[id]) map[id] = { id: id };
          map[id][field] = $(this).val();
        });
        var items = Object.values(map);

        $btn.prop('disabled', true).text('Guardando…');
        $status.text('').css('color', '#6b7280');

        $.post(ajaxurl, {
          action: 'silversea_save_product_prices',
          nonce:  nonce,
          items:  JSON.stringify(items),
        })
        .done(function(res) {
          if (res.success) {
            $status.css('color', '#059669')
                   .text('✓ ' + res.data.saved + ' precio(s) actualizados correctamente.');
            $('.spp-input').removeClass('spp-dirty');
          } else {
            $status.css('color', '#dc2626').text('✗ Error al guardar.');
          }
        })
        .fail(function() { $status.css('color', '#dc2626').text('✗ Error de conexión.'); })
        .always(function() {
          $btn.prop('disabled', false).text('💾 Guardar precios');
          setTimeout(function() { $status.text(''); }, 6000);
        });
      });
    });
    </script>
    <?php
}

/**
 * Columna SKU / Color RAL en el listado de productos del admin.
 * Simple  → SKU + color del producto.
 * Variable → "Variable" + una línea por variación con su SKU y color.
 */
add_filter( 'manage_edit-product_columns', function( $columns ) {
    /* Ocultar columnas de Yoast SEO */
    unset( $columns['wpseo-links'], $columns['wpseo-linked'] );

    /* Insertar columna SKU / Color RAL después de "Nombre" */
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'name' ) {
            $new['silversea_sku_color'] = 'SKU / Color RAL';
        }
    }
    return isset( $new['silversea_sku_color'] ) ? $new : ( $columns + ['silversea_sku_color' => 'SKU / Color RAL'] );
} );

add_action( 'manage_product_posts_custom_column', function( $column, $post_id ) {
    if ( $column !== 'silversea_sku_color' ) return;

    $product = wc_get_product( $post_id );
    if ( ! $product ) return;

    if ( $product->is_type('variable') ) {
        echo '<em style="font-size:11px;color:#9ca3af;">Variable</em>';
        foreach ( $product->get_children() as $vid ) {
            $var = wc_get_product( $vid );
            if ( ! $var ) continue;
            $sku   = $var->get_sku() ?: '—';
            $color = '';
            $slug  = $var->get_attribute('pa_color-ral');
            if ( $slug ) {
                $term  = get_term_by( 'slug', $slug, 'pa_color-ral' );
                $color = $term ? $term->name : $slug;
            }
            echo '<div style="font-size:11px;line-height:1.8;white-space:nowrap;">'
               . '<code style="font-size:10px;background:#f3f4f6;padding:1px 5px;border-radius:3px;font-family:monospace;">' . esc_html( $sku ) . '</code>'
               . ( $color ? ' <span style="color:#374151;">· ' . esc_html( $color ) . '</span>' : '' )
               . '</div>';
        }
    } else {
        echo '<em style="font-size:11px;color:#9ca3af;">Simple</em>';
        $sku   = $product->get_sku() ?: '—';
        $terms = wc_get_product_terms( $post_id, 'pa_color-ral', ['fields' => 'names'] );
        $color = $terms[0] ?? '';
        echo '<div style="font-size:11px;line-height:1.8;white-space:nowrap;">'
           . '<code style="font-size:10px;background:#f3f4f6;padding:1px 5px;border-radius:3px;font-family:monospace;">' . esc_html( $sku ) . '</code>'
           . ( $color ? ' <span style="color:#374151;">· ' . esc_html( $color ) . '</span>' : '' )
           . '</div>';
    }
}, 10, 2 );

/* Ancho fijo para la columna — evita que se expanda demasiado en tablas grandes */
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'edit-product' ) return;
    echo '<style>.column-silversea_sku_color { width: 180px; }</style>';
} );

/**
 * Usado en el detalle de producto, para mostrar el label antes del Qty. field
 */
add_action( 'woocommerce_before_add_to_cart_quantity', function() {
    echo '<div class="qty-container"><p class="qty-label">Cantidad: </p>';
} );

add_action( 'woocommerce_after_add_to_cart_quantity', function() {
    echo '</div><!-- .qty-container -->';
} );

/**
 * Forzar override de templates de YITH desde el tema hijo
 */
 
add_filter( 'wc_get_template', function( $located, $template_name, $args, $template_path, $default_path ) {
    if ( strpos( $default_path, 'yith-woocommerce-request-a-quote' ) !== false ) {
        $theme_override = get_stylesheet_directory() . '/woocommerce/yith-request-quote/' . $template_name;
        if ( file_exists( $theme_override ) ) {
            return $theme_override;
        }
    }
    return $located;
}, 10, 5 );


// Suprimir deprecated notices que rompen el redirect
add_action( 'init', function() {
    if ( isset($_POST['raq_mail_wpnonce']) ) {
        error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );
    }
}, 0 );




add_filter( 'wp_redirect', function( $location, $status ) {
    if ( strpos( $location, 'sent=1' ) !== false ) {
        // Limpiar notices de YITH antes de redirigir
        if ( function_exists( 'yith_ywraq_clear_notices' ) ) {
            yith_ywraq_clear_notices();
        }
        // Alternativa directa via WC session
        if ( WC()->session ) {
            WC()->session->__unset( 'yith_ywraq_notices' );
            WC()->session->__unset( 'wc_notices' );
        }
    }
    return $location;
}, 5, 2 );


/* - Silversea: fix sesi�n YITH en submit - */
add_action( 'wp_loaded', function() {
    if ( ! isset( $_POST['raq_mail_wpnonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['raq_mail_wpnonce'], 'send-request-quote' ) ) return;
    
    // wp_loaded corre DESPU�S de init completo � ac� YITH ya inicializ� todo
    // pero send_message() ya corri� tambi�n. Lo que hacemos es:
    // si la lista est� vac�a pero la sesi�n tiene productos, procesamos nosotros
    
    if ( YITH_Request_Quote()->is_empty() ) {
        $session = new YITH_YWRAQ_Session();
        $raq     = $session->get( 'raq', [] );
        
        //error_log('wp_loaded fix � raq en sesi�n: ' . count($raq));
        
        if ( ! empty( $raq ) ) {
            YITH_Request_Quote()->raq_content = $raq;

            // Llamar send_message manualmente ahora que el carrito est� cargado
            YITH_Request_Quote()->send_message();
        }
    }
}, 20 );


/* ══════════════════════════════════════════════════════════════
   COLOR GALLERY FILTER
   Productos variables con pa_color-ral: filtra las imágenes del
   gallery según el color seleccionado en el dropdown.
══════════════════════════════════════════════════════════════ */

/**
 * Inyecta data-sc-image-id en cada slide principal de la galería.
 * Permite al JS identificar a qué color pertenece cada imagen.
 */
add_filter( 'woocommerce_single_product_image_html', 'silversea_gallery_slide_image_id', 10, 2 );

function silversea_gallery_slide_image_id( $html, $attachment_id ) {
    return str_replace(
        'class="woocommerce-product-gallery__image"',
        'class="woocommerce-product-gallery__image" data-sc-image-id="' . (int) $attachment_id . '"',
        $html
    );
}

/**
 * Inyecta data-sc-image-id en cada thumbnail de la galería.
 */
add_filter( 'woocommerce_single_product_image_thumbnail_html', 'silversea_gallery_thumb_image_id', 10, 2 );

function silversea_gallery_thumb_image_id( $html, $attachment_id ) {
    return str_replace( '<img ', '<img data-sc-image-id="' . (int) $attachment_id . '" ', $html );
}

/**
 * Inyecta scColorGallery (mapa color → image IDs) en el footer
 * de las páginas de producto variable. Encola el JS de filtro.
 */
add_action( 'wp_footer', 'silversea_inject_color_gallery_data', 15 );

function silversea_inject_color_gallery_data() {
    if ( ! is_product() ) return;

    global $product;
    if ( ! $product ) $product = wc_get_product( get_the_ID() );
    if ( ! $product || ! $product->is_type( 'variable' ) ) return;

    $color_map  = [];
    $main_img   = (int) $product->get_image_id();

    /* ── 1. Imagen principal de cada variación ── */
    $valid_colors = [];
    foreach ( $product->get_available_variations() as $var_data ) {
        $slug   = $var_data['attributes']['attribute_pa_color-ral'] ?? '';
        $img_id = (int) ( $var_data['image_id'] ?? 0 );
        if ( ! $slug ) continue;
        $valid_colors[] = $slug;
        if ( $img_id && $img_id !== $main_img ) {
            $color_map[ $slug ][] = $img_id;
        }
    }
    $valid_colors = array_unique( $valid_colors );

    /* ── 2. Imágenes extra de la galería del producto taggeadas por alt ──
     * Para agregar imágenes a un color: en Biblioteca de medios → imagen →
     * campo "Texto alternativo" → ponés el slug exacto del color
     * (el mismo que aparece en la URL del atributo, ej: "ral-9016-blanco").
     */
    foreach ( $product->get_gallery_image_ids() as $gid ) {
        $gid = (int) $gid;
        if ( $gid === $main_img ) continue;
        $alt = trim( get_post_meta( $gid, '_wp_attachment_image_alt', true ) );
        if ( $alt && in_array( $alt, $valid_colors, true ) ) {
            $color_map[ $alt ][] = $gid;
        }
    }

    if ( empty( $color_map ) ) return;

    foreach ( $color_map as &$ids ) {
        $ids = array_values( array_unique( $ids ) );
    }
    unset( $ids );

    echo '<script>var scColorGallery = ' . wp_json_encode( [
        'colorMap' => $color_map,
        'attrKey'  => 'attribute_pa_color-ral',
    ] ) . ';</script>' . "\n";

    wp_enqueue_script(
        'silversea-color-gallery',
        SILVERSEA_PLUGIN_URL . 'assets/js/color-gallery.js',
        [ 'jquery' ],
        SILVERSEA_VERSION,
        true
    );
}
