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
   Container Type (00N8a00000FXdRZ) es un picklist RESTRINGIDO: si el valor no
   coincide letra por letra con el de Salesforce, Salesforce descarta el lead
   entero (y Web-to-Lead igual responde 200).

   Los nombres de silversea_sf_container_types() son los que se guardan en el
   mapeo de cada producto (Cotizador → Salesforce). Algunos valores reales de
   Salesforce se escriben distinto (typos/comas en el picklist de SF); esta tabla
   los traduce SOLO al armar el payload, sin tocar el mapeo guardado en la base
   ni el texto legible de Description.

   nombre interno del mapeo  =>  valor exacto en Salesforce
══════════════════════════════════════════════════════════════ */

function silversea_sf_container_value_overrides() {
    return [
        "40' Reefer"       => "40' Refeer",         /* typo en el picklist de SF */
        "40' HC Open Side" => "40' HC Open Side,",  /* coma final en el picklist de SF */
    ];
}

function silversea_sf_container_value( $internal ) {
    $overrides = silversea_sf_container_value_overrides();
    return $overrides[ $internal ] ?? $internal;
}

/* ══════════════════════════════════════════════════════════════
   PICKLIST — valores válidos de País en Salesforce (00NUm00000G445R)
   Debe coincidir letra por letra con las <option value="..."> del
   <select name="rqa_country"> en request-quote-form.php. Se usa solo
   para validar lo que llega por POST antes de reenviarlo a Salesforce.
══════════════════════════════════════════════════════════════ */

function silversea_sf_country_values() {
    return [
        'Spain', 'Portugal', 'France', 'Italy', 'Germany', 'United Kingdom',
        'Mexico', 'Colombia', 'Argentina', 'Brazil', 'Chile', 'Uruguay', 'Peru',
        'United States', 'Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola',
        'Antigua and Barbuda', 'Armenia', 'Australia', 'Austria', 'Azerbaijan',
        'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados', 'Belarus', 'Belgium',
        'Belize', 'Benin', 'Bhutan', 'Bolivia', 'Bosnia and Herzegovina',
        'Botswana', 'Brunei', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cabo Verde',
        'Cambodia', 'Cameroon', 'Canada', 'Central African Republic', 'Chad',
        'China', 'Comoros', 'Congo (Congo-Brazzaville)', 'Costa Rica', 'Croatia',
        'Cuba', 'Cyprus', 'Czech Republic', 'Democratic Republic of the Congo',
        'Denmark', 'Djibouti', 'Dominica', 'Dominican Republic', 'Ecuador',
        'Egypt', 'El Salvador', 'Equatorial Guinea', 'Eritrea', 'Estonia',
        'Eswatini', 'Ethiopia', 'Fiji', 'Finland', 'Gabon', 'Gambia', 'Georgia',
        'Ghana', 'Greece', 'Grenada', 'Guatemala', 'Guinea', 'Guinea-Bissau',
        'Guyana', 'Haiti', 'Honduras', 'Hungary', 'Iceland', 'India', 'Indonesia',
        'Iran', 'Iraq', 'Ireland', 'Israel', 'Jamaica', 'Japan', 'Jordan',
        'Kazakhstan', 'Kenya', 'Kiribati', 'Kuwait', 'Kyrgyzstan', 'Laos',
        'Latvia', 'Lebanon', 'Lesotho', 'Liberia', 'Libya', 'Liechtenstein',
        'Lithuania', 'Luxembourg', 'Madagascar', 'Malawi', 'Malaysia', 'Maldives',
        'Mali', 'Malta', 'Marshall Islands', 'Mauritania', 'Mauritius',
        'Micronesia', 'Moldova', 'Monaco', 'Mongolia', 'Montenegro', 'Morocco',
        'Mozambique', 'Myanmar (Burma)', 'Namibia', 'Nauru', 'Nepal',
        'Netherlands', 'New Zealand', 'Nicaragua', 'Niger', 'Nigeria',
        'North Korea', 'North Macedonia', 'Norway', 'Oman', 'Pakistan', 'Palau',
        'Palestine State', 'Panama', 'Papua New Guinea', 'Paraguay',
        'Philippines', 'Poland', 'Qatar', 'Romania', 'Russia', 'Rwanda',
        'Saint Kitts and Nevis', 'Saint Lucia', 'Saint Vincent and the Grenadines',
        'Samoa', 'San Marino', 'Sao Tome and Principe', 'Saudi Arabia', 'Senegal',
        'Serbia', 'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia',
        'Slovenia', 'Solomon Islands', 'Somalia', 'South Africa', 'South Korea',
        'South Sudan', 'Sri Lanka', 'Sudan', 'Suriname', 'Sweden', 'Switzerland',
        'Syria', 'Tajikistan', 'Tanzania', 'Thailand', 'Timor-Leste', 'Togo',
        'Tonga', 'Trinidad and Tobago', 'Tunisia', 'Turkey', 'Turkmenistan',
        'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates', 'Uzbekistan',
        'Vanuatu', 'Vatican City', 'Venezuela', 'Vietnam', 'Yemen', 'Zambia',
        'Zimbabwe',
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
   EXPORTAR MAPEO — CSV producto → ContainerType (interno y enviado a SF)
   Sirve para contrastar el mapeo contra el picklist de Salesforce.
══════════════════════════════════════════════════════════════ */

add_action( 'admin_post_silversea_export_sf_mapping', 'silversea_handle_export_sf_mapping' );

function silversea_handle_export_sf_mapping() {
    if ( ! current_user_can('manage_woocommerce') ) wp_die( 'No autorizado.', 403 );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'silversea_export_sf_mapping' ) ) wp_die( 'Nonce inválido.' );

    $known    = silversea_sf_container_types();
    $products = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="salesforce-mapeo-contenedores-' . date('Y-m-d') . '.csv"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    echo "\xEF\xBB\xBF"; // BOM para Excel

    $out = fopen( 'php://output', 'w' );

    fputcsv( $out, [
        'ID', 'Producto', 'SKU', 'Tipo de producto', 'Categorías',
        'Mapeo (nombre interno)', 'Valor enviado a Salesforce (Container Type)', 'Observación',
    ], ';' );

    foreach ( $products as $post ) {
        $product = wc_get_product( $post->ID );
        if ( ! $product ) continue;

        $mapped = (string) get_post_meta( $post->ID, 'silversea_sf_container_type', true );
        $sent   = $mapped === '' ? '' : silversea_sf_container_value( $mapped );

        if ( $mapped === '' )                       $note = 'Sin asignar: se envía vacío';
        elseif ( ! isset( $known[ $mapped ] ) )     $note = 'Valor no reconocido por el plugin';
        elseif ( $sent !== $mapped )                $note = 'Traducido: el valor de Salesforce se escribe distinto';
        else                                        $note = '';

        $cats = wp_get_post_terms( $post->ID, 'product_cat', [ 'fields' => 'names' ] );

        fputcsv( $out, [
            $post->ID,
            $post->post_title,
            $product->get_sku(),
            $product->is_type('variable') ? 'Variable' : 'Simple',
            is_wp_error( $cats ) ? '' : implode( ', ', $cats ),
            $mapped,
            $sent,
            $note,
        ], ';' );
    }

    fclose( $out );
    exit;
}

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
      <h1 style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        Salesforce – Tipos de contenedor
        <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=silversea_export_sf_mapping'), 'silversea_export_sf_mapping' ) ); ?>"
           class="button" title="Exporta todos los productos publicados, sin aplicar los filtros">⬇ Exportar CSV</a>
      </h1>
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
            'country'         => 'País (estándar)',
            '00NUm00000G445R' => 'País',
            '00NUm00000WqX8j' => 'Tipo de cliente',
            '00N8a00000FXdRZ' => 'ContainerType',
            '00N8a00000FXdRo' => 'Quantity',
            '00N8a00000FXdRe' => 'Grade',
            '00N8a00000FXdRt' => 'Modality',
            '00N8a00000FXdRj' => 'Market',
            '00NUm00000WuUnO' => 'Idioma',
            '00NUm00000WuUnT' => 'UTM Source',
            '00NUm00000WuUnS' => 'UTM Medium',
            '00NUm00000WuUnQ' => 'UTM Campaign',
            '00NUm00000WuUnU' => 'UTM Term',
            '00NUm00000WuUnR' => 'UTM Content',
            '00NUm00000WuUnP' => 'gclid',
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

/**
 * Construye el payload Web-to-Lead. ÚNICA fuente de verdad: la usan el envío
 * automático (silversea_send_to_salesforce) y el reenvío manual
 * (silversea_sf_build_payload_from_quote). Cualquier campo nuevo se agrega
 * SOLO acá, así ambos caminos quedan siempre idénticos.
 *
 * @param array $d        Datos del cliente: name, type, prefix, phone, email, city,
 *                        postal, message, country, form_lang, utm_*, gclid.
 * @param array $products Items del carrito (name, qty, product_id…).
 * @param int   $quote_id ID del CPT silversea_quote (viaja como 00NUm00000UecA9).
 * @return array          Campos listos para el POST.
 */
function silversea_sf_build_payload( $d, $products, $quote_id = 0 ) {
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

    return [
        'oid'             => '00D8a000002A8Hp',
        'retURL'          => home_url( '/es/gracias' ),
        'lead_source'     => 'Web',
        '00N8a00000FXdRj' => 'Europe',
        '00N8a00000FXdRt' => 'Buy',
        '00N8a00000FXdRZ' => silversea_sf_container_value( $container_type ),
        '00N8a00000FXdRo' => (string) $quantity,
        '00N8a00000FXdRe' => silversea_sf_grade_for_items( $products ),
        'first_name'      => $first_name,
        'last_name'       => $last_name,
        'email'           => $d['email']  ?? '',
        'phone'           => $phone,
        'company'         => $company,
        'city'            => $d['city']   ?? '',
        'zip'             => $d['postal'] ?? '',
        'country'         => $d['country'] ?? '',
        '00NUm00000G445R' => $d['country'] ?? '',
        /* Customer Type: picklist restringido de SF, solo acepta "Individual" o "Company"
           (no "Particular"/"Empresa": con esos valores Salesforce descarta el lead entero). */
        '00NUm00000WqX8j' => $is_empresa ? 'Company' : 'Individual',
        '00NUm00000WuUnO' => $d['form_lang']    ?? 'ES',
        '00NUm00000WuUnT' => $d['utm_source']   ?? '',
        '00NUm00000WuUnS' => $d['utm_medium']   ?? '',
        '00NUm00000WuUnQ' => $d['utm_campaign'] ?? '',
        '00NUm00000WuUnU' => $d['utm_term']     ?? '',
        '00NUm00000WuUnR' => $d['utm_content']  ?? '',
        '00NUm00000WuUnP' => $d['gclid']        ?? '',
        'description'     => $description,
        '00NUm00000UecA9' => $quote_id ? (string) $quote_id : '',
        '00NUm00000Ue4V3' => 'SILVERSEA',
        '00NUm00000Ue4V4' => 'SILVERSEA',
    ];
}

/**
 * Grade de Salesforce (00N8a00000FXdRe) de UN item: Nuevo → "New", Usado → "Cargo Worthy".
 * Devuelve '' si no se puede determinar (Salesforce acepta el picklist en blanco).
 *
 * El estado guardado en el item (`condition`) viene vacío para las variaciones de
 * productos variables (p. ej. colores RAL): en esos casos el estado está en el atributo
 * pa_condicion del producto PADRE, así que se consulta ahí. Último recurso: "Usado" en el
 * nombre del producto. Nunca se adivina "Nuevo": mejor en blanco que equivocado.
 */
function silversea_sf_grade_for_item( $item ) {
    $cond = strtolower( trim( (string) ( $item['condition'] ?? '' ) ) );

    if ( $cond === '' ) {
        $product = silversea_resolve_quote_product( $item );
        if ( $product ) {
            $lookup_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            $slugs     = wc_get_product_terms( $lookup_id, 'pa_condicion', [ 'fields' => 'slugs' ] );
            if ( is_array( $slugs ) ) {
                $slugs = array_map( 'strtolower', $slugs );
                $used  = in_array( 'usado', $slugs, true );
                $new   = in_array( 'nuevo', $slugs, true );
                if ( $used && ! $new )      $cond = 'usado';
                elseif ( $new && ! $used )  $cond = 'nuevo';
            }
        }
    }

    if ( $cond === '' && stripos( (string) ( $item['name'] ?? '' ), 'usado' ) !== false ) $cond = 'usado';

    if ( $cond === 'usado' ) return 'Cargo Worthy';
    if ( $cond === 'nuevo' ) return 'New';
    return '';
}

/**
 * Grade del presupuesto completo. Misma regla que ContainerType: solo se envía si TODOS
 * los productos tienen el mismo Grade; si hay mezcla (nuevo + usado) o alguno indeterminado,
 * queda vacío (el detalle siempre llega en Description).
 */
function silversea_sf_grade_for_items( $products ) {
    $grades = [];
    foreach ( $products as $item ) {
        $g = silversea_sf_grade_for_item( $item );
        if ( $g === '' ) return '';
        $grades[ $g ] = true;
    }
    return count( $grades ) === 1 ? (string) key( $grades ) : '';
}

/* ── Reconstruir el payload desde los meta guardados del CPT ──
   Devuelve null si el presupuesto no tiene productos guardados. */
function silversea_sf_build_payload_from_quote( $quote_id ) {
    $products = json_decode( get_post_meta( $quote_id, '_sq_products', true ) ?: '[]', true );
    if ( ! is_array( $products ) || empty( $products ) ) return null;

    $d = [
        'name'         => get_post_meta( $quote_id, '_sq_name',         true ),
        'type'         => get_post_meta( $quote_id, '_sq_client_type',  true ),
        'email'        => get_post_meta( $quote_id, '_sq_email',        true ),
        'prefix'       => '', /* _sq_phone ya guarda prefijo + número */
        'phone'        => get_post_meta( $quote_id, '_sq_phone',        true ),
        'city'         => get_post_meta( $quote_id, '_sq_city',         true ),
        'postal'       => get_post_meta( $quote_id, '_sq_postal',       true ),
        'message'      => get_post_meta( $quote_id, '_sq_message',      true ),
        'country'      => get_post_meta( $quote_id, '_sq_country',      true ),
        'form_lang'    => get_post_meta( $quote_id, '_sq_form_lang',    true ) ?: 'ES',
        'utm_source'   => get_post_meta( $quote_id, '_sq_utm_source',   true ),
        'utm_medium'   => get_post_meta( $quote_id, '_sq_utm_medium',   true ),
        'utm_campaign' => get_post_meta( $quote_id, '_sq_utm_campaign', true ),
        'utm_term'     => get_post_meta( $quote_id, '_sq_utm_term',     true ),
        'utm_content'  => get_post_meta( $quote_id, '_sq_utm_content',  true ),
        'gclid'        => get_post_meta( $quote_id, '_sq_gclid',        true ),
    ];

    return silversea_sf_build_payload( $d, $products, (int) $quote_id );
}

/**
 * Reenvía un presupuesto a Salesforce. Lo usan el botón individual y la acción masiva.
 *
 * Reconstruye SIEMPRE el payload desde los meta del presupuesto, con la lógica vigente.
 * Reusar el payload guardado repetiría tal cual un envío que pudo haber fallado (o que
 * se armó con una versión vieja del código). El guardado queda solo como último recurso
 * si no se puede reconstruir.
 *
 * @return bool false si no hay datos para armar el lead (nada se envió); true si se
 *              intentó el envío (el resultado queda en el meta _sq_sf_status).
 */
function silversea_sf_resend_quote( $quote_id ) {
    $payload = silversea_sf_build_payload_from_quote( $quote_id );
    if ( ! $payload ) {
        $stored  = get_post_meta( $quote_id, '_sq_sf_payload', true );
        $payload = ( is_array( $stored ) && ! empty( $stored ) ) ? $stored : null;
    }
    if ( ! $payload ) return false;

    silversea_sf_do_post( $quote_id, $payload );
    return true;
}

/* ── Handler re-envío manual ── */
add_action( 'admin_post_silversea_sf_resend', function() {
    $quote_id = (int) ( $_GET['quote_id'] ?? 0 );
    if ( ! $quote_id || ! current_user_can('manage_woocommerce') ) wp_die( 'No autorizado.', 403 );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'silversea_sf_resend_' . $quote_id ) ) wp_die( 'Nonce inválido.' );

    if ( ! silversea_sf_resend_quote( $quote_id ) ) {
        wp_die( 'No se pudieron reconstruir los datos: el presupuesto no tiene productos guardados.' );
    }

    wp_safe_redirect( add_query_arg( [ 'post' => $quote_id, 'action' => 'edit', 'sf_resent' => 1 ], admin_url('post.php') ) );
    exit;
} );

/* ══════════════════════════════════════════════════════════════
   ACCIÓN MASIVA — "Reenviar a Salesforce" en el listado de presupuestos
   WordPress ya valida el nonce de acciones masivas (bulk-posts) antes
   de disparar el filtro handle_bulk_actions-*.
══════════════════════════════════════════════════════════════ */

if ( ! defined( 'SILVERSEA_SF_BULK_TIME_BUDGET' ) ) {
    define( 'SILVERSEA_SF_BULK_TIME_BUDGET', 40 ); /* segundos por corrida: evita el timeout del servidor */
}

add_filter( 'bulk_actions-edit-silversea_quote', function( $actions ) {
    $actions['silversea_sf_resend'] = 'Reenviar a Salesforce';
    return $actions;
} );

add_filter( 'handle_bulk_actions-edit-silversea_quote', function( $redirect_to, $action, $post_ids ) {
    if ( $action !== 'silversea_sf_resend' ) return $redirect_to;
    if ( ! current_user_can( 'manage_woocommerce' ) ) return $redirect_to;

    /* Orden cronológico: los leads llegan a Salesforce en el orden en que se pidieron. */
    $post_ids = array_unique( array_map( 'intval', (array) $post_ids ) );
    $post_ids = array_values( array_filter( $post_ids, function( $id ) {
        return get_post_type( $id ) === 'silversea_quote';
    } ) );
    sort( $post_ids );

    $started = microtime( true );
    $sent = $failed = $nodata = 0;
    $last = 0;

    foreach ( $post_ids as $i => $quote_id ) {
        /* Si se agota el tiempo, cortar prolijo: lo que falta se informa como pendiente. */
        if ( $i > 0 && ( microtime( true ) - $started ) > SILVERSEA_SF_BULK_TIME_BUDGET ) break;

        if ( ! silversea_sf_resend_quote( $quote_id ) ) {
            $nodata++;
        } elseif ( get_post_meta( $quote_id, '_sq_sf_status', true ) === 'success' ) {
            $sent++;
        } else {
            $failed++;
        }
        $last = $quote_id;
    }

    $done = $sent + $failed + $nodata;

    return add_query_arg( [
        'sf_bulk'         => 1,
        'sf_bulk_sent'    => $sent,
        'sf_bulk_failed'  => $failed,
        'sf_bulk_nodata'  => $nodata,
        'sf_bulk_pending' => max( 0, count( $post_ids ) - $done ),
        'sf_bulk_last'    => $last,
    ], $redirect_to );
}, 10, 3 );

add_action( 'admin_notices', function() {
    if ( empty( $_GET['sf_bulk'] ) ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'edit-silversea_quote' ) return;

    $sent    = (int) ( $_GET['sf_bulk_sent']    ?? 0 );
    $failed  = (int) ( $_GET['sf_bulk_failed']  ?? 0 );
    $nodata  = (int) ( $_GET['sf_bulk_nodata']  ?? 0 );
    $pending = (int) ( $_GET['sf_bulk_pending'] ?? 0 );
    $last    = (int) ( $_GET['sf_bulk_last']    ?? 0 );

    $class = ( $failed || $nodata || $pending ) ? 'notice-warning' : 'notice-success';

    echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p><strong>☁️ Reenvío masivo a Salesforce</strong></p><ul style="list-style:disc;margin-left:20px;">';
    echo '<li>' . $sent . ' enviado(s) (Salesforce respondió HTTP 200)</li>';
    if ( $failed )  echo '<li>' . $failed . ' con error de conexión o HTTP distinto de 200 — ver el panel Salesforce de cada uno</li>';
    if ( $nodata )  echo '<li>' . $nodata . ' sin productos guardados: no se pudo armar el lead</li>';
    if ( $pending ) echo '<li><strong>' . $pending . ' pendiente(s)</strong>: se agotó el tiempo de la corrida' . ( $last ? ' (procesados hasta el #' . $last . ')' : '' ) . '. Volvé a seleccionar los que falten y repetí la acción.</li>';
    echo '</ul><p style="color:#6b7280;">Salesforce responde 200 aunque descarte un lead; confirmá en Salesforce que se hayan creado.</p></div>';
} );

/* Confirmación antes de reenviar en masa: reenviar leads que ya habían entrado los duplica. */
add_action( 'admin_footer-edit.php', function() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'edit-silversea_quote' ) return;
    ?>
    <script>
    jQuery(function($) {
        $('#posts-filter').on('submit', function(e) {
            var action = $('#bulk-action-selector-top').val();
            if (action === '-1') action = $('#bulk-action-selector-bottom').val();
            if (action !== 'silversea_sf_resend') return;
            var n = $('#the-list input[name="post[]"]:checked').length;
            if (!n) return;
            if (!confirm('¿Reenviar ' + n + ' presupuesto(s) a Salesforce?\n\nSi alguno ya había llegado a Salesforce, se creará un lead duplicado. Seleccioná solo los que NO entraron.')) {
                e.preventDefault();
            }
        });
    });
    </script>
    <?php
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

    /* Payload único, compartido con el reenvío manual (ver silversea_sf_build_payload). */
    $payload = silversea_sf_build_payload( $d, $products, (int) $quote_id );

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
