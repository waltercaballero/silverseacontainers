<?php
/**
 * Silversea – Integración Salesforce Web-to-Lead
 *
 * Se activa automáticamente al procesar una cotización del cotizador.
 * Incluye la página de mapeo Producto → ContainerType de Salesforce.
 *
 * Endpoint: https://webto.salesforce.com/servlet/servlet.WebToLead
 * OID:      00D8a000002A8Hp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ══════════════════════════════════════════════════════════════
   PICKLIST — valores válidos de ContainerType en Salesforce
══════════════════════════════════════════════════════════════ */

function silversea_sf_container_types() {
    return [
        ''                          => '— Sin asignar —',
        "10' Dry Van"               => "10' Dry Van",
        "20' Dry Van"               => "20' Dry Van",
        "40' Dry Van"               => "40' Dry Van",
        "20' High Cube"             => "20' High Cube",
        "40' High Cube"             => "40' High Cube",
        "40' High Cube NOR"         => "40' High Cube NOR",
        "40' NOR"                   => "40' NOR",
        "20' Reefer"                => "20' Reefer",
        "40' Reefer"                => "40' Reefer",
        "20' Open Top"              => "20' Open Top",
        "40' Open Top"              => "40' Open Top",
        "20' Double Door"           => "20' Double Door",
        "40' HC Double Door"        => "40' HC Double Door",
        "40' HC Open Side"          => "40' HC Open Side",
        "40' HC Open Side 4 Doors"  => "40' HC Open Side 4 Doors",
    ];
}

/* ══════════════════════════════════════════════════════════════
   HELPERS — obtener el ContainerType mapeado de un producto.
   El meta 'silversea_sf_container_type' se guarda en el producto padre.
══════════════════════════════════════════════════════════════ */

/** Devuelve el tipo SF a partir de un objeto WC_Product (o su padre si es variación). */
function silversea_sf_type_for_product( $product ) {
    if ( ! $product ) return '';
    $lookup_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $value     = get_post_meta( $lookup_id, 'silversea_sf_container_type', true );
    return $value ? (string) $value : '';
}

/** Devuelve el tipo SF de un item guardado en _sq_products (por product_id o título). */
function silversea_sf_type_for_item( $item ) {
    return silversea_sf_type_for_product( silversea_resolve_quote_product( $item ) );
}

/* ══════════════════════════════════════════════════════════════
   MENÚ ADMIN — submenú "Salesforce" dentro de Cotizador
══════════════════════════════════════════════════════════════ */

add_action( 'admin_menu', function() {
    add_submenu_page(
        'silversea-cotizador',
        'Salesforce – Tipos de contenedor',
        'Salesforce',
        'manage_woocommerce',
        'silversea-sf-mapping',
        'silversea_sf_render_mapping_page'
    );
} );

/* ══════════════════════════════════════════════════════════════
   AJAX — guardar mapeo producto → ContainerType
══════════════════════════════════════════════════════════════ */

add_action( 'wp_ajax_silversea_save_sf_types', function() {
    check_ajax_referer( 'silversea_sf_types', 'nonce' );
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error( null, 403 );

    $items = json_decode( stripslashes( $_POST['items'] ?? '[]' ), true );
    if ( ! is_array($items) ) wp_send_json_error( ['message' => 'Datos inválidos.'] );

    $saved = 0;
    foreach ( $items as $item ) {
        $id   = (int) ( $item['id'] ?? 0 );
        $type = sanitize_text_field( $item['type'] ?? '' );
        if ( ! $id ) continue;
        update_post_meta( $id, 'silversea_sf_container_type', $type );
        $saved++;
    }
    wp_send_json_success( ['saved' => $saved] );
} );

/* ══════════════════════════════════════════════════════════════
   PÁGINA — mapeo en lote Producto → ContainerType
══════════════════════════════════════════════════════════════ */

function silversea_sf_render_mapping_page() {
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
    $types      = silversea_sf_container_types();
    ?>
    <div class="wrap" style="max-width:820px;">
      <h1>Salesforce – Tipos de contenedor</h1>
      <p style="color:#6b7280;font-size:13px;margin-top:4px;">
        Asigná el tipo de contenedor de Salesforce a cada producto. Los productos sin tipo asignado enviarán
        <code>ContainerType</code> vacío al lead (el detalle siempre llega en <em>Description</em>).
      </p>

      <!-- Filtros -->
      <form method="get" style="display:flex;gap:10px;align-items:center;margin:16px 0;">
        <input type="hidden" name="page" value="silversea-sf-mapping">
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
          <a href="?page=silversea-sf-mapping" class="button">✕ Limpiar</a>
        <?php endif; ?>
        <span style="font-size:13px;color:#9ca3af;"><?php echo count($products); ?> productos</span>
      </form>

      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <button id="ssf-save" class="button button-primary" style="height:36px;padding:0 20px;font-size:14px;">
          💾 Guardar mapeo
        </button>
        <span id="ssf-status" style="font-size:13px;"></span>
      </div>

      <?php if ( empty($products) ) : ?>
        <p style="color:#9ca3af;text-align:center;padding:48px;">No se encontraron productos.</p>
      <?php else : ?>
      <table class="wp-list-table widefat fixed" id="ssf-table">
        <thead>
          <tr>
            <th style="width:44px;"></th>
            <th>Producto</th>
            <th style="width:130px;">SKU</th>
            <th style="width:240px;">Tipo Salesforce</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ( $products as $post ) :
            $product = wc_get_product( $post->ID );
            if ( ! $product ) continue;
            $thumb   = get_the_post_thumbnail_url( $post->ID, [40, 40] ) ?: wc_placeholder_img_src( [40, 40] );
            $sku     = $product->get_sku() ?: '—';
            $current = get_post_meta( $post->ID, 'silversea_sf_container_type', true );
            $is_var  = $product->is_type('variable');
        ?>
        <tr>
          <td style="text-align:center;padding:8px 4px;">
            <img src="<?php echo esc_url($thumb); ?>" width="34" height="34"
                 style="border-radius:4px;object-fit:cover;vertical-align:middle;">
          </td>
          <td>
            <?php echo esc_html( $post->post_title ); ?>
            <?php if ( $is_var ) : ?>
              <span style="font-size:11px;background:#f5f3ff;color:#7c3aed;padding:1px 7px;border-radius:4px;margin-left:6px;">Variable</span>
            <?php endif; ?>
          </td>
          <td>
            <code style="font-size:11px;background:#f3f4f6;padding:2px 6px;border-radius:3px;">
              <?php echo esc_html($sku); ?>
            </code>
          </td>
          <td>
            <select class="ssf-select" data-id="<?php echo $post->ID; ?>"
                    style="width:100%;height:30px;font-size:13px;">
              <?php foreach ( $types as $val => $label ) : ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected( $current, $val ); ?>>
                  <?php echo esc_html($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <script>
    jQuery(function($) {
      var nonce = '<?php echo wp_create_nonce('silversea_sf_types'); ?>';

      /* Marcar fila modificada */
      $(document).on('change', '.ssf-select', function() {
        $(this).closest('tr').css('background', '#fffbeb');
      });

      $('#ssf-save').on('click', function() {
        var $btn    = $(this);
        var $status = $('#ssf-status');
        var items   = [];

        $('.ssf-select').each(function() {
          items.push({ id: $(this).data('id'), type: $(this).val() });
        });

        $btn.prop('disabled', true).text('Guardando…');
        $status.text('').css('color', '#6b7280');

        $.post(ajaxurl, {
          action: 'silversea_save_sf_types',
          nonce:  nonce,
          items:  JSON.stringify(items),
        })
        .done(function(res) {
          if (res.success) {
            $status.css('color', '#059669')
                   .text('✓ ' + res.data.saved + ' producto(s) guardados.');
            $('tr').css('background', '');
          } else {
            $status.css('color', '#dc2626').text('✗ Error al guardar.');
          }
        })
        .fail(function() {
          $status.css('color', '#dc2626').text('✗ Error de conexión.');
        })
        .always(function() {
          $btn.prop('disabled', false).text('💾 Guardar mapeo');
          setTimeout(function() { $status.text(''); }, 5000);
        });
      });
    });
    </script>
    <?php
}

/* ══════════════════════════════════════════════════════════════
   FUNCIÓN PRINCIPAL — enviar lead a Salesforce
   Llamada desde silversea_process_and_save() en shipping-session.php

   @param array $d        Datos del cliente (de silversea_get_shipping_post_data())
   @param array $products Array de productos guardados en el CPT
══════════════════════════════════════════════════════════════ */

/* ══════════════════════════════════════════════════════════════
   META BOX — panel Salesforce en cada cotización
══════════════════════════════════════════════════════════════ */

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'silversea_sf_status',
        '☁️ Salesforce',
        'silversea_sf_metabox',
        'silversea_quote',
        'side',
        'high'
    );
} );

function silversea_sf_metabox( $post ) {
    $status  = get_post_meta( $post->ID, '_sq_sf_status',        true );
    $code    = get_post_meta( $post->ID, '_sq_sf_response_code', true );
    $sent_at = get_post_meta( $post->ID, '_sq_sf_sent_at',       true );
    $error   = get_post_meta( $post->ID, '_sq_sf_error',         true );
    $payload = get_post_meta( $post->ID, '_sq_sf_payload',       true );

    /* ── Resumen de estado ── */
    if ( ! $status ) {
        echo '<p style="color:#9ca3af;font-size:13px;margin:0 0 12px;">Aún no se envió a Salesforce.</p>';
    } elseif ( $status === 'skipped' ) {
        echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">'
           . '<span style="background:#fffbeb;color:#b45309;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px;">⏸ No enviado (demo)</span>'
           . '</div>';
        if ( $error ) {
            echo '<p style="font-size:12px;color:#92740a;margin:0 0 12px;">' . esc_html($error) . '</p>';
        }
    } elseif ( $status === 'success' ) {
        echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">'
           . '<span style="background:#f0fdf4;color:#15803d;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px;">✓ Enviado</span>'
           . '<span style="font-size:12px;color:#6b7280;">HTTP ' . esc_html($code) . '</span>'
           . '</div>';
    } else {
        echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">'
           . '<span style="background:#fef2f2;color:#dc2626;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px;">✗ Error</span>'
           . ( $code ? '<span style="font-size:12px;color:#6b7280;">HTTP ' . esc_html($code) . '</span>' : '' )
           . '</div>';
        if ( $error ) {
            echo '<p style="font-size:12px;color:#dc2626;margin:0 0 12px;word-break:break-all;">' . esc_html($error) . '</p>';
        }
    }

    if ( $sent_at ) {
        echo '<p style="font-size:11px;color:#9ca3af;margin:0 0 12px;">Enviado: '
           . esc_html( date_i18n( 'd/m/Y H:i', strtotime($sent_at) ) ) . '</p>';
    }

    /* ── Datos enviados ── */
    if ( is_array($payload) && ! empty($payload) ) {
        $labels = [
            'first_name'      => 'Nombre',
            'last_name'       => 'Apellido',
            'company'         => 'Empresa',
            'email'           => 'Email',
            'phone'           => 'Teléfono',
            'city'            => 'Ciudad',
            'zip'             => 'CP',
            '00N8a00000FXdRZ' => 'ContainerType',
            '00N8a00000FXdRo' => 'Quantity',
            '00N8a00000FXdRt' => 'Modality',
            '00N8a00000FXdRj' => 'Market',
            'lead_source'     => 'Lead Source',
            'description'     => 'Description',
        ];
        echo '<details style="margin-bottom:12px;">'
           . '<summary style="font-size:12px;font-weight:600;cursor:pointer;color:#374151;">Datos enviados</summary>'
           . '<table style="width:100%;font-size:11px;margin-top:8px;border-collapse:collapse;">';
        foreach ( $labels as $key => $label ) {
            $val = $payload[$key] ?? '';
            if ( $val === '' || $val === null ) continue;
            echo '<tr style="border-top:1px solid #f3f4f6;">'
               . '<td style="padding:3px 6px 3px 0;color:#6b7280;white-space:nowrap;vertical-align:top;">' . esc_html($label) . '</td>'
               . '<td style="padding:3px 0;word-break:break-word;white-space:pre-wrap;">' . esc_html($val) . '</td>'
               . '</tr>';
        }
        echo '</table></details>';
    }

    /* ── Botón re-enviar ── */
    $resend_url = wp_nonce_url(
        add_query_arg( [ 'action' => 'silversea_sf_resend', 'quote_id' => $post->ID ], admin_url('admin-post.php') ),
        'silversea_sf_resend_' . $post->ID
    );
    echo '<a href="' . esc_url($resend_url) . '" class="button button-secondary" style="width:100%;text-align:center;box-sizing:border-box;"'
       . ' onclick="return confirm(\'¿Re-enviar este lead a Salesforce?\')">'
       . ( $status ? '↺ Re-enviar a Salesforce' : '↑ Enviar a Salesforce' )
       . '</a>';
}

/**
 * Calcula ContainerType y Quantity para el lead a partir de los productos.
 *
 * ContainerType solo se asigna si TODOS los productos del carrito mapean
 * al MISMO tipo de Salesforce. Si hay productos sin mapear o tipos mixtos,
 * queda vacío (el detalle siempre llega en Description). Quantity es la
 * suma total de unidades en todos los casos.
 *
 * @param array $products Cada item con keys 'name' y 'qty'.
 * @return array ['container_type' => string, 'quantity' => int]
 */
function silversea_sf_type_and_qty( $products ) {
    $sf_types     = [];
    $total_qty    = 0;
    $any_unmapped = false;

    foreach ( $products as $item ) {
        $total_qty += (int) ( $item['qty'] ?? 0 );
        $sf_type    = silversea_sf_type_for_item( $item );
        if ( $sf_type === '' ) {
            $any_unmapped = true;
        } elseif ( ! in_array( $sf_type, $sf_types, true ) ) {
            $sf_types[] = $sf_type;
        }
    }

    $container_type = ( count( $sf_types ) === 1 && ! $any_unmapped ) ? $sf_types[0] : '';

    return [ 'container_type' => $container_type, 'quantity' => $total_qty ];
}

/* ── Reconstruir payload desde los meta del CPT (para cotizaciones antiguas) ── */
function silversea_sf_build_payload_from_quote( $quote_id ) {
    $name         = get_post_meta( $quote_id, '_sq_name',        true );
    $email        = get_post_meta( $quote_id, '_sq_email',       true );
    $phone        = get_post_meta( $quote_id, '_sq_phone',       true ); /* ya viene prefijo + número */
    $type         = get_post_meta( $quote_id, '_sq_client_type', true );
    $city         = get_post_meta( $quote_id, '_sq_city',        true );
    $postal       = get_post_meta( $quote_id, '_sq_postal',      true );
    $message      = get_post_meta( $quote_id, '_sq_message',     true );
    $products_raw = json_decode( get_post_meta( $quote_id, '_sq_products', true ) ?: '[]', true );

    if ( empty( $products_raw ) ) return null;

    /* Nombre → first / last */
    $is_empresa = ( $type === 'empresa' );
    if ( $is_empresa ) {
        $company = $name; $first_name = ''; $last_name = $name;
    } else {
        $parts      = explode( ' ', trim( $name ), 2 );
        $first_name = $parts[0] ?? '';
        $last_name  = $parts[1] ?? $first_name;
        if ( empty( $last_name ) ) $last_name = $first_name;
        $company    = '';
    }

    /* ContainerType y Quantity */
    $tq             = silversea_sf_type_and_qty( $products_raw );
    $container_type = $tq['container_type'];
    $quantity       = $tq['quantity'];

    /* Description */
    $lines = [];
    foreach ( $products_raw as $item ) {
        $sf_type = silversea_sf_type_for_item( $item ) ?: $item['name'];
        $lines[] = '- ' . $sf_type . ' x' . (int) $item['qty'];
    }
    $description = "Pedido del cotizador:\n" . implode( "\n", $lines );
    if ( ! empty( $message ) ) $description .= "\n\nMensaje del cliente: " . $message;

    return [
        'oid'             => '00D8a000002A8Hp',
        'retURL'          => home_url( '/es/gracias' ),
        'lead_source'     => 'Web',
        '00N8a00000FXdRj' => 'Europe',
        '00N8a00000FXdRt' => 'Buy',
        '00N8a00000FXdRZ' => $container_type,
        '00N8a00000FXdRo' => (string) $quantity,
        'first_name'      => $first_name,
        'last_name'       => $last_name,
        'email'           => $email,
        'phone'           => $phone,
        'company'         => $company,
        'city'            => $city,
        'zip'             => $postal,
        'description'     => $description,
    ];
}

/* ── Handler re-envío manual ── */
add_action( 'admin_post_silversea_sf_resend', function() {
    $quote_id = (int) ( $_GET['quote_id'] ?? 0 );
    if ( ! $quote_id || ! current_user_can('manage_woocommerce') ) wp_die( 'No autorizado.', 403 );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'silversea_sf_resend_' . $quote_id ) ) wp_die( 'Nonce inválido.' );

    /* Usar payload guardado o reconstruirlo desde los meta del CPT */
    $payload = get_post_meta( $quote_id, '_sq_sf_payload', true );
    if ( ! is_array( $payload ) || empty( $payload ) ) {
        $payload = silversea_sf_build_payload_from_quote( $quote_id );
    }
    if ( ! $payload ) wp_die( 'No se pudieron reconstruir los datos: la cotización no tiene productos guardados.' );

    silversea_sf_do_post( $quote_id, $payload );

    wp_safe_redirect( add_query_arg( [ 'post' => $quote_id, 'action' => 'edit', 'sf_resent' => 1 ], admin_url('post.php') ) );
    exit;
} );

add_action( 'admin_notices', function() {
    if ( empty($_GET['sf_resent']) ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'silversea_quote' ) return;
    echo '<div class="notice notice-success is-dismissible"><p>☁️ Lead re-enviado a Salesforce.</p></div>';
} );

/* ══════════════════════════════════════════════════════════════
   HELPER INTERNO — ejecutar el POST a Salesforce y guardar resultado
══════════════════════════════════════════════════════════════ */

function silversea_sf_do_post( $quote_id, $payload ) {
    update_post_meta( $quote_id, '_sq_sf_payload',  $payload );
    update_post_meta( $quote_id, '_sq_sf_sent_at',  current_time('mysql') );
    update_post_meta( $quote_id, '_sq_sf_error',    '' );

    $response = wp_remote_post(
        'https://webto.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8&orgId=00D8a000002A8Hp',
        [
            'body'    => $payload,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'timeout' => 15,
        ]
    );

    if ( is_wp_error( $response ) ) {
        update_post_meta( $quote_id, '_sq_sf_status', 'error' );
        update_post_meta( $quote_id, '_sq_sf_response_code', '' );
        update_post_meta( $quote_id, '_sq_sf_error', $response->get_error_message() );
        error_log( '[Silversea SF] Web-to-Lead error: ' . $response->get_error_message() );
        return;
    }

    $code    = (int) wp_remote_retrieve_response_code( $response );
    $success = in_array( $code, [ 200, 302 ] );

    update_post_meta( $quote_id, '_sq_sf_status',        $success ? 'success' : 'error' );
    update_post_meta( $quote_id, '_sq_sf_response_code', $code );

    if ( ! $success ) {
        error_log( '[Silversea SF] Web-to-Lead HTTP inesperado: ' . $code );
    }
}

/* ══════════════════════════════════════════════════════════════
   FUNCIÓN PRINCIPAL — enviar lead a Salesforce
   Llamada desde silversea_process_and_save() en shipping-session.php

   @param array $d        Datos del cliente (de silversea_get_shipping_post_data())
   @param array $products Array de productos guardados en el CPT
   @param int   $quote_id ID del CPT silversea_quote (para guardar el resultado)
══════════════════════════════════════════════════════════════ */

function silversea_send_to_salesforce( $d, $products, $quote_id = 0 ) {
    if ( empty( $products ) ) return;

    /* En modo demo no se envían leads reales a Salesforce.
       El botón "Enviar a Salesforce" del panel sigue funcionando manualmente. */
    if ( get_option( 'silversea_demo_mode', '0' ) === '1' ) {
        if ( $quote_id ) {
            update_post_meta( $quote_id, '_sq_sf_status', 'skipped' );
            update_post_meta( $quote_id, '_sq_sf_error',  'Modo demo activo: no se envió automáticamente a Salesforce.' );
        }
        return;
    }

    /* ── Nombre → first_name / last_name ──────────────────────
       Salesforce requiere last_name obligatoriamente.
       Empresas: company = nombre, last_name = nombre.
       Particulares: primera palabra = first_name, resto = last_name.
    ────────────────────────────────────────────────────────── */
    $full_name  = trim( $d['name'] ?? '' );
    $is_empresa = ( ( $d['type'] ?? '' ) === 'empresa' );

    if ( $is_empresa ) {
        $company    = $full_name;
        $first_name = '';
        $last_name  = $full_name;
    } else {
        $parts      = explode( ' ', $full_name, 2 );
        $first_name = $parts[0] ?? '';
        $last_name  = $parts[1] ?? $first_name;
        if ( empty( $last_name ) ) $last_name = $first_name;
        $company    = '';
    }

    /* ── Teléfono completo ── */
    $phone = trim( ( $d['prefix'] ?? '' ) . ' ' . ( $d['phone'] ?? '' ) );

    /* ── ContainerType y Quantity según lógica del carrito ────
       1 tipo  → ContainerType = ese tipo, Quantity = su cantidad
       2+ tipos → ContainerType = vacío,   Quantity = suma total
    ────────────────────────────────────────────────────────── */
    $tq             = silversea_sf_type_and_qty( $products );
    $container_type = $tq['container_type'];
    $quantity       = $tq['quantity'];

    /* ── Description: detalle completo del carrito ── */
    $lines = [];
    foreach ( $products as $item ) {
        $sf_type = silversea_sf_type_for_item( $item ) ?: $item['name'];
        $lines[] = '- ' . $sf_type . ' x' . (int) $item['qty'];
    }

    $description = "Pedido del cotizador:\n" . implode( "\n", $lines );
    if ( ! empty( $d['message'] ) ) {
        $description .= "\n\nMensaje del cliente: " . $d['message'];
    }

    /* ── Payload Web-to-Lead ── */
    $payload = [
        'oid'             => '00D8a000002A8Hp',
        'retURL'          => home_url( '/es/gracias' ),
        'lead_source'     => 'Web',
        '00N8a00000FXdRj' => 'Europe',
        '00N8a00000FXdRt' => 'Buy',
        '00N8a00000FXdRZ' => $container_type,
        '00N8a00000FXdRo' => (string) $quantity,
        'first_name'      => $first_name,
        'last_name'       => $last_name,
        'email'           => $d['email']  ?? '',
        'phone'           => $phone,
        'company'         => $company,
        'city'            => $d['city']   ?? '',
        'zip'             => $d['postal'] ?? '',
        'description'     => $description,
        '00NUm00000UecA9' => $quote_id ? (string) $quote_id : '',
        '00NUm00000Ue4V3' => 'SILVERSEA',
        '00NUm00000Ue4V4' => 'SILVERSEA',
    ];

    /* ── Envío ── */
    if ( $quote_id ) {
        silversea_sf_do_post( $quote_id, $payload );
    } else {
        /* Sin quote_id: enviar sin guardar resultado (no debería ocurrir en producción) */
        wp_remote_post(
            'https://webto.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8&orgId=00D8a000002A8Hp',
            [
                'body'    => $payload,
                'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
                'timeout' => 15,
            ]
        );
    }
}
