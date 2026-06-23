<?php
/**
 * Silversea – Sesión de envío + CPT quotes + email admin
 * @version 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/shipping-quote-calc.php';
require_once __DIR__ . '/salesforce.php';

/* ══════════════════════════════════════════════════════════════
   1.  GUARDAR datos de envío en WC Session vía AJAX
══════════════════════════════════════════════════════════════ */

add_action( 'wp_ajax_silversea_save_shipping',        'silversea_ajax_save_shipping' );
add_action( 'wp_ajax_nopriv_silversea_save_shipping', 'silversea_ajax_save_shipping' );

function silversea_ajax_save_shipping() {
    if ( ! check_ajax_referer( 'silversea_calc', 'nonce', false ) )
        wp_send_json_error( ['message' => 'Nonce inválido.'], 403 );

    $allowed_origins    = array_merge( silversea_get_city_keys(), [''] ); // '' = sin ciudad (pickup libre)
    $allowed_transports = ['sin','con'];
    $allowed_methods    = ['delivery','pickup'];

    $data = [
        'method'      => in_array( sanitize_key($_POST['method'] ?? ''), $allowed_methods, true )
                         ? sanitize_key($_POST['method']) : 'delivery',
        'origin'      => in_array( sanitize_key($_POST['origin'] ?? ''), $allowed_origins, true )
                         ? sanitize_key($_POST['origin']) : '',
        'postal_code' => preg_replace( '/\D/', '', $_POST['postal_code'] ?? '' ),
        'transport'   => in_array( sanitize_key($_POST['transport'] ?? ''), $allowed_transports, true )
                         ? sanitize_key($_POST['transport']) : 'sin',
        'pickup_city' => in_array( sanitize_key($_POST['pickup_city'] ?? ''), $allowed_origins, true )
                         ? sanitize_key($_POST['pickup_city']) : '',
        'price'       => round( (float)($_POST['price'] ?? 0), 2 ),
        'detail'      => sanitize_text_field( $_POST['detail'] ?? '' ),
        'trucks'      => (int)($_POST['trucks'] ?? 1),
        'days'        => (int)($_POST['days']   ?? 5),
    ];

    WC()->session->set( 'silversea_shipping_data', $data );
    wp_send_json_success( ['saved' => true] );
}


/* ══════════════════════════════════════════════════════════════
   2.  CPT — silversea_quote
       Registra nuestro propio post type para guardar cotizaciones
       sin depender de YITH Premium.
══════════════════════════════════════════════════════════════ */

add_action( 'init', 'silversea_register_quote_cpt' );

function silversea_register_quote_cpt() {
    register_post_type( 'silversea_quote', [
        'labels' => [
            'name'               => 'Cotizaciones',
            'singular_name'      => 'Cotización',
            'add_new'            => 'Nueva cotización',
            'add_new_item'       => 'Nueva cotización',
            'edit_item'          => 'Editar cotización',
            'view_item'          => 'Ver cotización',
            'all_items'          => 'Todas las cotizaciones',
            'search_items'       => 'Buscar cotizaciones',
            'not_found'          => 'No se encontraron cotizaciones.',
            'not_found_in_trash' => 'No hay cotizaciones en la papelera.',
        ],
        'public'            => false,
        'show_ui'           => true,
        'show_in_menu'      => true,
        'menu_icon'         => 'dashicons-clipboard',
        'menu_position'     => 57,
        'supports'          => ['title'],
        'capability_type'   => 'post',
        'has_archive'       => false,
    ]);
}

/* Columnas del listado */
add_filter( 'manage_silversea_quote_posts_columns', 'silversea_quote_columns' );

function silversea_quote_columns( $cols ) {
    return [
        'cb'          => '<input type="checkbox">',
        'title'       => 'Cotización',
        'sq_client'   => 'Cliente',
        'sq_email'    => 'Email',
        'sq_phone'    => 'Teléfono',
        'sq_products' => 'Productos',
        'sq_shipping' => 'Envío',
        'sq_price'    => 'Precio',
        'date'        => 'Fecha',
    ];
}

add_action( 'manage_silversea_quote_posts_custom_column', 'silversea_quote_column_content', 10, 2 );

function silversea_quote_column_content( $col, $post_id ) {
    switch ( $col ) {
        case 'sq_client':
            $type = get_post_meta($post_id, '_sq_client_type', true);
            echo esc_html( get_post_meta($post_id, '_sq_name', true) );
            if ( $type ) echo '<br><small style="color:#6b7280">' . ucfirst($type) . '</small>';
            break;
        case 'sq_email':
            $email = get_post_meta($post_id, '_sq_email', true);
            if ( $email ) echo '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
            break;
        case 'sq_phone':
            echo esc_html( get_post_meta($post_id, '_sq_phone', true) );
            break;
        case 'sq_products':
            $items = json_decode( get_post_meta($post_id, '_sq_products', true) ?: '[]', true );
            foreach ( (array)$items as $item ) {
                echo esc_html($item['name']) . ' ×' . (int)$item['qty'] . '<br>';
            }
            break;
        case 'sq_shipping':
            $method = get_post_meta($post_id, '_sq_shipping_method', true);
            if ( $method === 'pickup' ) {
                echo 'Retiro en ' . silversea_origin_label( get_post_meta($post_id, '_sq_shipping_pickup', true) );
            } else {
                $origin = silversea_origin_label( get_post_meta($post_id, '_sq_shipping_origin', true) );
                $cp     = get_post_meta($post_id, '_sq_shipping_cp', true);
                if ( $origin || $cp ) echo "$origin → CP $cp";
            }
            break;
        case 'sq_price':
            $price = get_post_meta($post_id, '_sq_shipping_price', true);
            if ( $price ) echo '€ ' . number_format((float)$price, 2, ',', '.');
            break;
    }
}

/* Metabox de detalle completo */
add_action( 'add_meta_boxes', function() {
    add_meta_box( 'silversea_quote_detail', 'Detalle de la cotización',
        'silversea_quote_metabox', 'silversea_quote', 'normal', 'high' );
    add_meta_box( 'silversea_quote_email_sales', '📧 Email enviado a ventas',
        'silversea_quote_email_sales_metabox', 'silversea_quote', 'normal', 'default' );
    add_meta_box( 'silversea_quote_email_client', '📧 Email enviado al cliente',
        'silversea_quote_email_client_metabox', 'silversea_quote', 'normal', 'default' );
} );

/* ── Metabox email ventas ── */
function silversea_quote_email_sales_metabox( $post ) {
    silversea_render_email_metabox( $post->ID, 'sales' );
}

/* ── Metabox email cliente ── */
function silversea_quote_email_client_metabox( $post ) {
    silversea_render_email_metabox( $post->ID, 'client' );
}

function silversea_render_email_metabox( $post_id, $type ) {
    $meta_key   = $type === 'sales' ? '_sq_email_body_sales' : '_sq_email_body_client';
    $body       = get_post_meta( $post_id, $meta_key, true );
    $nonce_key  = 'silversea_email_' . $type . '_nonce';
    $label      = $type === 'sales' ? 'ventas (comercial-eu@silverseacontainers.com)' : 'cliente';

    if ( ! $body ) {
        /* Intentar generar el body on-the-fly */
        $body = silversea_generate_email_body_for_quote( $post_id, $type );
        if ( $body ) {
            update_post_meta( $post_id, $meta_key, $body );
        } else {
            /* No se pudo generar aún — mostrar botón manual */
            $gen_url = wp_nonce_url(
                add_query_arg([
                    'action'     => 'silversea_generate_email_preview',
                    'quote_id'   => $post_id,
                    'email_type' => $type,
                ], admin_url('admin-post.php') ),
                'silversea_gen_preview_' . $post_id
            );
            echo '<p style="color:#9ca3af;font-size:13px;margin:0 0 10px;">El email aún no fue generado.</p>';
            echo '<a href="' . esc_url($gen_url) . '" class="button button-secondary">⚙️ Generar preview del email</a>';
            return;
        }
    }

    wp_nonce_field( 'silversea_save_email_' . $type . '_' . $post_id, $nonce_key );
    ?>
    <p style="font-size:12px;color:#6b7280;margin:0 0 8px;">
        Podés editar el HTML del email antes de reenviarlo. Los cambios se guardan al hacer clic en "Actualizar".
    </p>
    <textarea
        name="<?php echo esc_attr($meta_key); ?>"
        id="<?php echo esc_attr($meta_key); ?>"
        style="width:100%;height:320px;font-family:monospace;font-size:12px;border:1px solid #d1d5db;border-radius:6px;padding:8px;box-sizing:border-box;resize:vertical;"
    ><?php echo esc_textarea($body); ?></textarea>

    <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <a href="#"
           onclick="silvseaPreviewEmail('<?php echo esc_js($meta_key); ?>');return false;"
           style="font-size:13px;color:#185FA5;text-decoration:underline;">
            👁 Vista previa
        </a>
        <span style="color:#d1d5db;">|</span>
        <?php
        $resend_url = wp_nonce_url(
            add_query_arg( [
                'action'      => 'silversea_resend_single',
                'quote_id'    => $post_id,
                'email_type'  => $type,
            ], admin_url('admin-post.php') ),
            'silversea_resend_single_' . $post_id
        );
        ?>
        <a href="<?php echo esc_url($resend_url); ?>"
           style="font-size:13px;background:#185FA5;color:#fff;padding:5px 14px;border-radius:5px;text-decoration:none;"
           onclick="return confirm('¿Reenviar el email a <?php echo esc_js($label); ?>?')">
            📤 Reenviar email a <?php echo esc_html($label); ?>
        </a>
    </div>

    <script>
    function silvseaPreviewEmail(metaKey) {
        var html = document.getElementById(metaKey).value;
        var win = window.open('', '_blank');
        win.document.write(html);
        win.document.close();
    }
    </script>
    <?php
}

/* Guardar los email bodies al hacer Update en el CPT */
add_action( 'save_post_silversea_quote', 'silversea_save_email_bodies', 10, 1 );

function silversea_save_email_bodies( $post_id ) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    foreach ( ['sales', 'client'] as $type ) {
        $meta_key  = $type === 'sales' ? '_sq_email_body_sales' : '_sq_email_body_client';
        $nonce_key = 'silversea_email_' . $type . '_nonce';
        if ( isset($_POST[$nonce_key]) &&
             wp_verify_nonce($_POST[$nonce_key], 'silversea_save_email_' . $type . '_' . $post_id) &&
             isset($_POST[$meta_key]) ) {
            update_post_meta( $post_id, $meta_key, wp_kses_post( wp_unslash($_POST[$meta_key]) ) );
        }
    }
}

/* Handler reenvío de un solo email (ventas o cliente) con body editado */
add_action( 'admin_post_silversea_resend_single', 'silversea_handle_resend_single' );

function silversea_handle_resend_single() {
    $quote_id   = (int)( $_GET['quote_id']   ?? 0 );
    $email_type = sanitize_key( $_GET['email_type'] ?? '' );

    if ( ! $quote_id || ! in_array($email_type, ['sales','client'], true) )
        wp_die('Parámetros inválidos.', 400);
    if ( ! current_user_can('manage_woocommerce') )
        wp_die('No autorizado.', 403);
    if ( ! wp_verify_nonce($_GET['_wpnonce'] ?? '', 'silversea_resend_single_' . $quote_id) )
        wp_die('Nonce inválido.');

    $post = get_post($quote_id);
    if ( ! $post || $post->post_type !== 'silversea_quote' ) wp_die('Cotización no encontrada.');

    $debug_mode      = get_option('silversea_demo_mode', '0') === '1';
    $email_from      = 'sales@silverseacontainers.com';
    $email_comercial = 'comercial-eu@silverseacontainers.com';
    $email_debug     = get_option('silversea_admin_email', get_option('admin_email'));
    $client_email    = get_post_meta($quote_id, '_sq_email', true);
    $client_name     = get_post_meta($quote_id, '_sq_name',  true);

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: SILVERSEA Containers <' . $email_from . '>',
    ];
    if ( $client_email ) $headers[] = 'Reply-To: ' . $client_name . ' <' . $client_email . '>';

    if ( $email_type === 'sales' ) {
        $body    = get_post_meta($quote_id, '_sq_email_body_sales', true);
        $to      = $debug_mode ? $email_debug : $email_comercial;
        $subject = sprintf('[Silversea Ventas] Cotización #%d – %s', $quote_id, $client_name);
    } else {
        $body    = get_post_meta($quote_id, '_sq_email_body_client', true);
        $to      = $debug_mode ? $email_debug : $client_email;
        $subject = sprintf('[Silversea Containers] Su solicitud de cotización #%d', $quote_id);
        if ( ! $to ) wp_die('El cliente no tiene email registrado.');
    }

    if ( ! $body ) wp_die('No hay contenido de email guardado para esta cotización.');

    wp_mail( $to, $subject, $body, $headers );

    wp_safe_redirect( add_query_arg(
        ['post_type' => 'silversea_quote', 'silversea_resent' => 1],
        admin_url('edit.php')
    ) );
    exit;
}

/* ══════════════════════════════════════════════════════════════
   HELPER — reconstruir $data completo desde post_meta de un quote
   Usado por silversea_generate_email_body_for_quote y silversea_handle_resend_email
══════════════════════════════════════════════════════════════ */

function silversea_load_quote_data( $quote_id ) {
    $origin    = get_post_meta( $quote_id, '_sq_shipping_origin',    true );
    $cp        = get_post_meta( $quote_id, '_sq_shipping_cp',        true );
    $transp    = get_post_meta( $quote_id, '_sq_shipping_transport', true );
    $method    = get_post_meta( $quote_id, '_sq_shipping_method',    true );
    $pickup    = get_post_meta( $quote_id, '_sq_shipping_pickup',    true );
    $price     = (float) get_post_meta( $quote_id, '_sq_shipping_price',    true );
    $trucks    = (int)   get_post_meta( $quote_id, '_sq_shipping_trucks',   true );
    $days      = (int)   get_post_meta( $quote_id, '_sq_shipping_days',     true );
    $breakdown = json_decode( get_post_meta( $quote_id, '_sq_shipping_breakdown', true ) ?: '[]', true );
    $products  = json_decode( get_post_meta( $quote_id, '_sq_products',           true ) ?: '[]', true );

    $raq_content = [];
    foreach ( $products as $item ) {
        $_product = silversea_resolve_quote_product( $item );
        if ( $_product ) {
            $raq_content[] = [
                'product_id'            => $_product->get_id(),
                'quantity'              => $item['qty'],
                'silversea_addons'      => $item['addons']       ?? [],
                'silversea_addon_prices'=> $item['addon_prices'] ?? [],
            ];
        }
    }

    $consolidated = null;
    if ( $method === 'delivery' && $price > 0 ) {
        $consolidated = [
            'total'        => $price,
            'trucks'       => $trucks,
            'days'         => $days,
            'transport'    => $transp,
            'transp_label' => $transp === 'con' ? 'Con descarga' : 'Sin descarga',
            'origin'       => $origin,
            'cp'           => $cp,
            'destino'      => "CP {$cp}",
            'breakdown'    => $breakdown,
        ];
    }

    return [
        'name'        => get_post_meta( $quote_id, '_sq_name',        true ),
        'email'       => get_post_meta( $quote_id, '_sq_email',       true ),
        'phone'       => get_post_meta( $quote_id, '_sq_phone',       true ),
        'type'        => get_post_meta( $quote_id, '_sq_client_type', true ),
        'city'        => get_post_meta( $quote_id, '_sq_city',        true ),
        'postal'      => get_post_meta( $quote_id, '_sq_postal',      true ),
        'message'     => get_post_meta( $quote_id, '_sq_message',     true ),
        'method'      => $method,
        'origin'      => $origin,
        'cp'          => $cp,
        'transport'   => $transp,
        'pickup'      => $pickup,
        'price'       => $price,
        'consolidated'=> $consolidated,
        'raq_content' => $raq_content,
        '_products'   => $products,
    ];
}

/* ══════════════════════════════════════════════════════════════
   GENERAR BODY DE EMAIL DESDE POST META (sin necesidad de enviar)
══════════════════════════════════════════════════════════════ */

function silversea_generate_email_body_for_quote( $quote_id, $type ) {
    $show_prices = get_option('silversea_email_show_prices', '1') === '1';

    $data = silversea_load_quote_data( $quote_id );
    if ( empty($data['_products']) ) return false;

    $is_sales        = ( $type === 'sales' );
    $prices_for_type = $is_sales ? true : $show_prices;
    $raq_content     = $data['raq_content'];
    $price_city      = silversea_derive_price_city( $data );

    $products_html = ! empty($raq_content)
        ? silversea_email_products_html( $raq_content, $prices_for_type, $price_city )
        : silversea_email_products_html_from_meta( $quote_id, $prices_for_type, $price_city );

    $shipping_html = silversea_email_shipping_html( $data, $prices_for_type );

    return silversea_email_template( $quote_id, $data, $products_html, $shipping_html, $prices_for_type, $is_sales );
}

/* Handler — generar preview desde botón en metabox */
add_action( 'admin_post_silversea_generate_email_preview', 'silversea_handle_generate_email_preview' );

function silversea_handle_generate_email_preview() {
    $quote_id   = (int)( $_GET['quote_id']   ?? 0 );
    $email_type = sanitize_key( $_GET['email_type'] ?? 'client' );

    if ( ! $quote_id || ! current_user_can('manage_woocommerce') ) wp_die('No autorizado.', 403);
    if ( ! wp_verify_nonce($_GET['_wpnonce'] ?? '', 'silversea_gen_preview_' . $quote_id) ) wp_die('Nonce inválido.');

    $post = get_post($quote_id);
    if ( ! $post || $post->post_type !== 'silversea_quote' ) wp_die('Cotización no encontrada.');

    $meta_key = $email_type === 'sales' ? '_sq_email_body_sales' : '_sq_email_body_client';
    $body     = silversea_generate_email_body_for_quote( $quote_id, $email_type );

    if ( $body ) {
        update_post_meta( $quote_id, $meta_key, $body );
    }

    wp_safe_redirect( add_query_arg(
        ['post' => $quote_id, 'action' => 'edit', 'silversea_generated' => 1],
        admin_url('post.php')
    ) );
    exit;
}

/* Aviso tras generación */
add_action( 'admin_notices', function() {
    if ( empty($_GET['silversea_generated']) ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'silversea_quote' ) return;
    echo '<div class="notice notice-success is-dismissible"><p>✅ Preview del email generado correctamente.</p></div>';
} );


function silversea_quote_metabox( $post ) {
    $id = $post->ID;

    $name      = get_post_meta($id, '_sq_name',             true);
    $email     = get_post_meta($id, '_sq_email',            true);
    $phone     = get_post_meta($id, '_sq_phone',            true);
    $type      = get_post_meta($id, '_sq_client_type',      true);
    $city      = get_post_meta($id, '_sq_city',             true);
    $postal    = get_post_meta($id, '_sq_postal',           true);
    $message   = get_post_meta($id, '_sq_message',          true);
    $method    = get_post_meta($id, '_sq_shipping_method',  true);
    $origin    = get_post_meta($id, '_sq_shipping_origin',  true);
    $cp        = get_post_meta($id, '_sq_shipping_cp',      true);
    $transport = get_post_meta($id, '_sq_shipping_transport', true);
    $pickup    = get_post_meta($id, '_sq_shipping_pickup',  true);
    $price     = get_post_meta($id, '_sq_shipping_price',   true);
    $trucks    = get_post_meta($id, '_sq_shipping_trucks',  true);
    $days      = get_post_meta($id, '_sq_shipping_days',    true);
    $products  = json_decode( get_post_meta($id, '_sq_products', true) ?: '[]', true );
    $breakdown = json_decode( get_post_meta($id, '_sq_shipping_breakdown', true) ?: '[]', true );

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;font-size:13px;">';

    /* Cliente */
    echo '<div>';
    echo '<h3 style="margin:0 0 10px;font-size:13px;text-transform:uppercase;color:#6b7280;">Cliente</h3>';
    $rows = [
        'Nombre'   => $name,
        'Tipo'     => ucfirst($type),
        'Email'    => $email ? '<a href="mailto:'.esc_attr($email).'">'.esc_html($email).'</a>' : '',
        'Teléfono' => $phone,
        'Ciudad'   => $city . ( $postal ? " ($postal)" : '' ),
        'Mensaje'  => $message,
    ];
    echo '<table style="width:100%;border-collapse:collapse;">';
    foreach ( $rows as $label => $val ) {
        if ( ! $val ) continue;
        echo '<tr><td style="padding:4px 8px 4px 0;color:#6b7280;white-space:nowrap;">' . esc_html($label) . '</td>';
        echo '<td style="padding:4px 0;">' . wp_kses_post($val) . '</td></tr>';
    }
    echo '</table></div>';

    /* Envío */
    echo '<div>';
    echo '<h3 style="margin:0 0 10px;font-size:13px;text-transform:uppercase;color:#6b7280;">Envío</h3>';
    if ( $method === 'pickup' ) {
        echo '<p>Retiro en depósito de <strong>' . silversea_origin_label($pickup) . '</strong> — Sin costo</p>';
    } else {
        $ship_rows = [
            'Origen'      => silversea_origin_label($origin),
            'CP destino'  => $cp,
            'Transporte'  => $transport === 'con' ? 'Con descarga' : 'Sin descarga',
            'Camiones'    => $trucks,
            'Plazo'       => $days ? "$days días hábiles" : '',
            'Precio'      => $price ? '€ ' . number_format((float)$price, 2, ',', '.') : '',
        ];
        echo '<table style="width:100%;border-collapse:collapse;">';
        foreach ( $ship_rows as $label => $val ) {
            if ( ! $val ) continue;
            echo '<tr><td style="padding:4px 8px 4px 0;color:#6b7280;white-space:nowrap;">' . esc_html($label) . '</td>';
            echo '<td style="padding:4px 0;font-weight:600;">' . esc_html($val) . '</td></tr>';
        }
        echo '</table>';
        if ( ! empty($breakdown) ) {
            echo '<hr style="margin:10px 0;"><strong>Desglose</strong><table style="width:100%;font-size:12px;">';
            foreach ( $breakdown as $b ) {
                echo '<tr><td>' . esc_html($b['label']) . '</td><td style="text-align:right;">€' . number_format((float)$b['price'],2,',','.') . '</td></tr>';
            }
            echo '</table>';
        }
    }
    echo '</div>';
    echo '</div>';

    /* Productos */
    if ( ! empty($products) ) {
        echo '<hr style="margin:20px 0;"><h3 style="margin:0 0 12px;font-size:13px;text-transform:uppercase;color:#6b7280;">Productos</h3>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        echo '<tr style="background:#f8fafc;"><th style="padding:8px;text-align:left;">Producto</th><th style="padding:8px;">Cant.</th><th style="padding:8px;text-align:left;">Adicionales</th></tr>';
        foreach ( $products as $item ) {
            echo '<tr style="border-top:1px solid #e5e7eb;">';
            echo '<td style="padding:8px;">' . esc_html($item['name']) . '<br><small style="color:#6b7280">' . esc_html($item['condition'] ?? '') . '</small></td>';
            echo '<td style="padding:8px;text-align:center;">' . (int)$item['qty'] . '</td>';
            $addon_prices_map = $item['addon_prices'] ?? [];
            $addons_str = implode(', ', array_map( function($a) use ($addon_prices_map) {
                $p = isset($addon_prices_map[$a]) ? ' (' . number_format((float)$addon_prices_map[$a], 2, ',', '.') . ' €)' : '';
                return $a . $p;
            }, $item['addons'] ?? [] ));
            echo '<td style="padding:8px;">' . esc_html($addons_str) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
}


/* ══════════════════════════════════════════════════════════════
   ROW ACTIONS — Reenviar emails desde el listado de cotizaciones
══════════════════════════════════════════════════════════════ */

add_filter( 'post_row_actions', 'silversea_quote_row_actions', 10, 2 );

function silversea_quote_row_actions( $actions, $post ) {
    if ( $post->post_type !== 'silversea_quote' ) return $actions;
    $url = wp_nonce_url(
        add_query_arg( [ 'action' => 'silversea_resend_email', 'quote_id' => $post->ID ], admin_url('admin-post.php') ),
        'silversea_resend_' . $post->ID
    );
    $actions['resend_email'] = '<a href="' . esc_url($url) . '" style="color:#185FA5;">📧 Reenviar emails</a>';
    return $actions;
}

add_action( 'admin_post_silversea_resend_email', 'silversea_handle_resend_email' );

function silversea_handle_resend_email() {
    $quote_id = (int)( $_GET['quote_id'] ?? 0 );
    if ( ! $quote_id || ! current_user_can('manage_woocommerce') ) wp_die('No autorizado.', 403);
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'silversea_resend_' . $quote_id ) ) wp_die('Nonce inválido.');

    $post = get_post( $quote_id );
    if ( ! $post || $post->post_type !== 'silversea_quote' ) wp_die('Cotización no encontrada.');

    $data = silversea_load_quote_data( $quote_id );

    /* Borrar bodies guardados para forzar regeneración limpia */
    delete_post_meta( $quote_id, '_sq_email_body_sales' );
    delete_post_meta( $quote_id, '_sq_email_body_client' );

    silversea_send_admin_email( $quote_id, $data );

    wp_safe_redirect( add_query_arg(
        [ 'post_type' => 'silversea_quote', 'silversea_resent' => 1 ],
        admin_url('edit.php')
    ) );
    exit;
}

/* Aviso de confirmación */
add_action( 'admin_notices', function() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'silversea_quote' ) return;
    if ( empty($_GET['silversea_resent']) ) return;
    echo '<div class="notice notice-success is-dismissible"><p>✅ Emails reenviados correctamente.</p></div>';
} );


/* ══════════════════════════════════════════════════════════════
   3.  PROCESAR SUBMIT — guardar quote + email + sesión gracias
       ywraq_process es el único hook disponible en versión free.
       Aquí hacemos todo: guardar CPT, email, datos para gracias.
══════════════════════════════════════════════════════════════ */

function silversea_get_shipping_post_data() {
    static $data = null;
    if ( $data === null ) {
        /* Leer datos de contacto del POST */
        $data = [
            'phone'     => sanitize_text_field( $_POST['rqa_phone']         ?? '' ),
            'prefix'    => sanitize_text_field( $_POST['rqa_phone_prefix']  ?? '' ),
            'city'      => sanitize_text_field( $_POST['rqa_city']          ?? '' ),
            'postal'    => preg_replace( '/\D/', '', $_POST['rqa_postal']  ?? '' ),
            'type'      => sanitize_key( $_POST['rqa_client_type']          ?? 'particular' ),
            'name'      => sanitize_text_field( $_POST['rqa_name']          ?? '' ),
            'email'     => sanitize_email( $_POST['rqa_email']              ?? '' ),
            'message'   => sanitize_textarea_field( $_POST['rqa_message']   ?? '' ),
        ];

        /* Leer datos de envío del POST */
        $method    = sanitize_key( $_POST['rqa_shipping_method']      ?? '' );
        $origin    = sanitize_key( $_POST['rqa_shipping_origin']      ?? '' );
        $cp        = preg_replace( '/\D/', '', $_POST['rqa_shipping_cp'] ?? '' );
        $transport = sanitize_key( $_POST['rqa_shipping_transport']   ?? '' );
        $price     = round( (float)($_POST['rqa_shipping_price']      ?? 0), 2 );
        $pickup    = sanitize_key( $_POST['rqa_shipping_pickup_city'] ?? '' );

        /* Fallback: si los campos de envío llegan vacíos (JS no sincronizó a tiempo),
           leer desde WC session que el cotizador guardó via AJAX */
        if ( ! $method && WC()->session ) {
            $sc = WC()->session->get('silversea_shipping_data');
            if ( ! empty($sc) ) {
                $method    = $sc['method']      ?? 'delivery';
                $origin    = $sc['origin']      ?? '';
                $cp        = $sc['postal_code'] ?? '';
                $transport = $sc['transport']   ?? 'sin';
                $price     = $sc['price']       ?? 0;
                $pickup    = $sc['pickup_city'] ?? '';
            }
        }

        $data['method']    = $method    ?: 'delivery';
        $data['origin']    = $origin;
        $data['cp']        = $cp;
        $data['transport'] = $transport ?: 'sin';
        $data['price']     = $price;
        $data['pickup']    = $pickup;
    }
    return $data;
}

/* Inyectar raq_content en YITH antes de send_message() */
add_action( 'init', function() {
    if ( ! isset( $_POST['raq_mail_wpnonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['raq_mail_wpnonce'], 'send-request-quote' ) ) return;
    $session = new YITH_YWRAQ_Session();
    $raq     = $session->get( 'raq', [] );
    if ( ! empty( $raq ) ) {
        YITH_Request_Quote()->raq_content = $raq;
    }
}, 11 );

/* Interceptar redirect de YITH → llevar a página de gracias */
add_filter( 'wp_redirect', 'silversea_redirect_to_thanks', 1, 2 );

function silversea_redirect_to_thanks( $location, $status ) {
    if ( strpos($location, 'sent=1') === false ) return $location;
    $thanks_url = WC()->session ? WC()->session->get('silversea_thanks_url') : '';
    if ( ! $thanks_url ) return $location;
    return add_query_arg('sent', 1, $thanks_url);
}

/* ywraq_process — disponible en versión free */
add_action( 'ywraq_process', 'silversea_process_and_save', 5, 1 );

function silversea_process_and_save( $args ) {
    $raq_content = $args['raq_content'] ?? [];
    $d           = silversea_get_shipping_post_data();

    /* Calcular envío consolidado */
    $consolidated = null;
    if ( $d['method'] === 'delivery' && $d['origin'] && $d['cp'] ) {
        $result = silversea_calc_consolidated_shipping(
            $raq_content, $d['origin'], $d['cp'], $d['transport']
        );
        if ( ! is_wp_error($result) ) $consolidated = $result;
    }

    $price = $consolidated ? $consolidated['total'] : $d['price'];

    /* Ciudad que determina el precio */
    $price_city = silversea_derive_price_city( $d );

    /* Construir array de productos para guardar */
    $products = [];
    foreach ( $raq_content as $raq ) {
        $pid      = ! empty($raq['variation_id']) ? $raq['variation_id'] : $raq['product_id'];
        $_product = wc_get_product($pid);
        if ( ! $_product ) continue;
        $terms    = wc_get_product_terms($_product->get_id(), 'pa_condicion', ['fields'=>'names']);

        /* Color RAL — desde variación o atributo del producto simple */
        $color = '';
        if ( ! empty($raq['variation_id']) ) {
            $var = wc_get_product( (int)$raq['variation_id'] );
            if ( $var ) {
                $slug = $var->get_attribute('pa_color-ral');
                if ( $slug ) {
                    $term  = get_term_by('slug', $slug, 'pa_color-ral');
                    $color = $term ? $term->name : $slug;
                }
            }
        } else {
            $c_terms = wc_get_product_terms($_product->get_id(), 'pa_color-ral', ['fields' => 'names']);
            $color   = ! empty($c_terms) ? $c_terms[0] : '';
        }

        $unit_price = silversea_get_product_city_price( $pid, $price_city );
        if ( $unit_price === null ) $unit_price = (float) $_product->get_price();

        $products[] = [
            'name'         => $_product->get_title(),
            'product_id'   => (int) $pid,
            'condition'    => ! empty($terms) ? $terms[0] : '',
            'qty'          => (int) $raq['quantity'],
            'addons'       => $raq['silversea_addons']       ?? [],
            'addon_prices' => $raq['silversea_addon_prices'] ?? [],
            'color'        => $color,
            'price'        => $unit_price,
            'city'         => $price_city,
        ];
    }

    /* Guardar CPT silversea_quote */
    $title    = sprintf( '#%s - %s', date('ymdHi'), $d['name'] ?: $d['email'] );
    $quote_id = wp_insert_post( wp_slash([
        'post_type'   => 'silversea_quote',
        'post_title'  => $title,
        'post_status' => 'publish',
        'meta_input'  => [
            '_sq_name'                => $d['name'],
            '_sq_email'               => $d['email'],
            '_sq_phone'               => trim($d['prefix'] . ' ' . $d['phone']),
            '_sq_client_type'         => $d['type'],
            '_sq_city'                => $d['city'],
            '_sq_postal'              => $d['postal'],
            '_sq_message'             => $d['message'],
            '_sq_shipping_method'     => $d['method'],
            '_sq_shipping_origin'     => $d['origin'],
            '_sq_shipping_cp'         => $d['cp'],
            '_sq_shipping_transport'  => $d['transport'],
            '_sq_shipping_pickup'     => $d['pickup'],
            '_sq_shipping_price'      => $price,
            '_sq_shipping_trucks'     => $consolidated['trucks'] ?? '',
            '_sq_shipping_days'       => $consolidated['days']   ?? '',
            '_sq_shipping_breakdown'  => $consolidated ? json_encode($consolidated['breakdown'], JSON_UNESCAPED_UNICODE) : '',
            '_sq_products'            => json_encode($products, JSON_UNESCAPED_UNICODE),
        ],
    ]) );

    /* Guardar datos en WC session para la página de gracias */
    if ( WC()->session ) {
        WC()->session->set( 'silversea_last_quote', [
            'quote_id'    => $quote_id ?: 0,
            'name'        => $d['name'],
            'email'       => $d['email'],
            'phone'       => trim($d['prefix'] . ' ' . $d['phone']),
            'type'        => $d['type'],
            'city'        => $d['city'],
            'postal'      => $d['postal'],
            'message'     => $d['message'],
            'products'    => $products,
            'method'      => $d['method'],
            'origin'      => $d['origin'],
            'cp'          => $d['cp'],
            'transport'   => $d['transport'],
            'pickup'      => $d['pickup'],
            'price'       => $price,
            'consolidated'=> $consolidated,
        ]);
        WC()->session->__unset('silversea_shipping_data');
    }

    /* Enviar email al admin */
    if ( $quote_id && ! is_wp_error( $quote_id ) ) {
        silversea_send_admin_email( $quote_id, [
            'name'        => $d['name'],
            'email'       => $d['email'],
            'phone'       => trim($d['prefix'] . ' ' . $d['phone']),
            'city'        => $d['city'],
            'postal'      => $d['postal'],
            'type'        => $d['type'],
            'message'     => $d['message'],
            'method'      => $d['method'],
            'origin'      => $d['origin'],
            'cp'          => $d['cp'],
            'transport'   => $d['transport'],
            'pickup'      => $d['pickup'],
            'price'       => $price,
            'consolidated'=> $consolidated,
            'raq_content' => $raq_content,
        ]);
    }

    /* Enviar lead a Salesforce */
    silversea_send_to_salesforce( $d, $products, $quote_id ?: 0 );
}


/* ══════════════════════════════════════════════════════════════
   4.  EMAIL AL ADMIN
══════════════════════════════════════════════════════════════ */

/* ==========================================================
   4.  SISTEMA DE EMAILS
       - Email ventas:   sales@silverseacontainers.com → to: comercial-eu@silverseacontainers.com
                         (en debug mode → al email configurado en admin)
       - Email cliente:  opcional (toggle admin), precios opcionales (toggle admin)
========================================================== */

/* Descripciones hardcodeadas para transporte según tipo */
function silversea_get_transport_desc( $transport, $method ) {
    if ( $method === 'pickup' ) {
        return 'Recogida en depósito sin coste de transporte adicional. Disponible en 5 días hábiles.';
    }
    if ( $transport === 'sin' ) {
        return 'Incluye transporte hasta su dirección. Descarga no incluida (opción disponible).';
    }
    return 'Incluye transporte con grúa y descarga del contenedor en la ubicación requerida.';
}

/* Construir bloque HTML de productos para un email.
   $show_prices: si mostrar precio por unidad */
/* Construir HTML de productos desde _sq_products meta (fallback robusto).
   Usado cuando raq_content no está disponible o llega vacío. */
function silversea_email_products_html_from_meta( $post_id, $show_prices, $city = '' ) {
    $products = json_decode( get_post_meta($post_id, '_sq_products', true) ?: '[]', true );
    if ( empty($products) ) return '<p style="color:#9ca3af;">—</p>';

    $html = '';
    foreach ( $products as $item ) {
        $name      = $item['name']      ?? '';
        $condition = $item['condition'] ?? '';
        $qty       = (int)( $item['qty'] ?? 1 );
        $addons       = $item['addons']       ?? [];
        $addon_prices = $item['addon_prices'] ?? [];

        /* Buscar el producto en WC para obtener precio y descripción */
        $_product = silversea_resolve_quote_product( $item );
        $price    = 0.0;
        $desc     = '';
        $tamano   = '';
        $condicion= $condition;

        if ( $_product ) {
            $city_p    = $city ? silversea_get_product_city_price( $_product->get_id(), $city ) : null;
            $price     = $city_p !== null ? $city_p : (float) $_product->get_price();
            $desc     = wp_trim_words( wp_strip_all_tags( get_post_field('post_content', $_product->get_id()) ), 40, '…' );
            $t_terms  = wc_get_product_terms( $_product->get_id(), 'pa_tamano',    ['fields' => 'names'] );
            $c_terms  = wc_get_product_terms( $_product->get_id(), 'pa_condicion', ['fields' => 'names'] );
            $tamano   = ! empty($t_terms) ? $t_terms[0] : '';
            $condicion= ! empty($c_terms) ? $c_terms[0] : $condition;
        }

        $price_html = '';
        if ( $show_prices && $price > 0 ) {
            $price_html = ' &nbsp;<strong style="color:#0F2557;">€ ' . number_format($price * $qty, 2, ',', '.') . '</strong>'
                        . ' <span style="color:#9ca3af;font-size:12px;">(€ ' . number_format($price, 2, ',', '.') . ' c/u)</span>';
        }

        $html .= '<div style="padding:14px 0;border-bottom:1px solid #f0f0f0;">';
        $html .= '<p style="margin:0 0 4px;font-size:15px;font-weight:600;color:#111;">'
               . (int)$qty . ' × ' . esc_html($name) . $price_html . '</p>';

        if ( $tamano )
            $html .= '<p style="margin:2px 0;font-size:13px;color:#6b7280;">' . esc_html($tamano) . '</p>';
        if ( $condicion )
            $html .= '<p style="margin:2px 0;font-size:13px;color:#6b7280;">' . esc_html($condicion) . '</p>';
        $color = $item['color'] ?? '';
        if ( $color )
            $html .= '<p style="margin:2px 0;font-size:13px;color:#6b7280;">' . esc_html($color) . '</p>';
        if ( $desc )
            $html .= '<p style="margin:4px 0;font-size:13px;color:#374151;font-style:italic;">' . esc_html($desc) . '</p>';

        foreach ( $addons as $addon ) {
            $ap    = isset($addon_prices[$addon]) ? (float)$addon_prices[$addon] : 0.0;
            $label = '+ ' . esc_html($addon);
            if ( $show_prices && $ap > 0 )
                $label .= ' — ' . number_format($ap, 2, ',', '.') . ' €';
            $html .= '<span class="ad" style="display:inline-block;margin:3px 4px 0 0;font-size:12px;color:#fff;background:#222E5C;padding:2px 8px;border-radius:4px;">' . $label . '</span>';
        }

        $html .= '</div>';
    }
    return $html;
}

function silversea_email_products_html( $raq_content, $show_prices, $city = '' ) {
    $html = '';
    foreach ( $raq_content as $raq ) {
        $pid      = ! empty($raq['variation_id']) ? $raq['variation_id'] : $raq['product_id'];
        $_product = wc_get_product( $pid );
        if ( ! $_product ) continue;

        $qty         = (int)( $raq['quantity'] ?? 1 );
        $title       = $_product->get_title();
        $city_price  = $city ? silversea_get_product_city_price( $pid, $city ) : null;
        $price       = $city_price !== null ? $city_price : (float) $_product->get_price();

        /* Descripción del producto desde post_content */
        $description = wp_strip_all_tags( get_post_field('post_content', $_product->get_id()) );
        $description = wp_trim_words( $description, 40, '…' );

        /* Atributos */
        $tamano_terms   = wc_get_product_terms( $_product->get_id(), 'pa_tamano',   ['fields' => 'names'] );
        $condicion_terms= wc_get_product_terms( $_product->get_id(), 'pa_condicion',['fields' => 'names'] );
        $tamano   = ! empty($tamano_terms)    ? $tamano_terms[0]    : '';
        $condicion= ! empty($condicion_terms) ? $condicion_terms[0] : '';

        /* Color RAL */
        $color_attr = '';
        if ( ! empty($raq['variation_id']) ) {
            $var = wc_get_product( (int)$raq['variation_id'] );
            if ( $var ) {
                $c_slug = $var->get_attribute('pa_color-ral');
                if ( $c_slug ) {
                    $c_term     = get_term_by('slug', $c_slug, 'pa_color-ral');
                    $color_attr = $c_term ? $c_term->name : $c_slug;
                }
            }
        } else {
            $c_terms    = wc_get_product_terms( $_product->get_id(), 'pa_color-ral', ['fields' => 'names'] );
            $color_attr = ! empty($c_terms) ? $c_terms[0] : '';
        }

        /* Addons */
        $addons       = $raq['silversea_addons']       ?? [];
        $addon_prices = $raq['silversea_addon_prices'] ?? [];

        /* Precio unitario */
        $price_html = '';
        if ( $show_prices && $price > 0 ) {
            $price_html = ' &nbsp;<strong style="color:#0F2557;">€ ' . number_format($price * $qty, 2, ',', '.') . '</strong>'
                        . ' <span style="color:#9ca3af;font-size:12px;">(€ ' . number_format($price, 2, ',', '.') . ' c/u)</span>';
        }

        $html .= '<div class="pb" style="padding:12px 0;border-bottom:1px solid #f3f4f6;">';
        $html .= '<p class="pn" style="margin:0 0 4px;font-size:15px;font-weight:600;color:#111;">'
               . (int)$qty . ' × ' . esc_html($title) . $price_html . '</p>';

        if ( $tamano )
            $html .= '<p class="pm" style="margin:2px 0;font-size:13px;color:#6b7280;">' . esc_html($tamano) . '</p>';
        if ( $condicion )
            $html .= '<p class="pm" style="margin:2px 0;font-size:13px;color:#6b7280;">' . esc_html($condicion) . '</p>';
        if ( $color_attr )
            $html .= '<p class="pm" style="margin:2px 0;font-size:13px;color:#6b7280;">' . esc_html($color_attr) . '</p>';
        if ( $description )
            $html .= '<p class="pd" style="margin:4px 0;font-size:13px;color:#374151;font-style:italic;">' . esc_html($description) . '</p>';

        foreach ( $addons as $addon ) {
            $ap    = isset($addon_prices[$addon]) ? (float)$addon_prices[$addon] : 0.0;
            $label = '+ ' . esc_html($addon);
            if ( $show_prices && $ap > 0 )
                $label .= ' — ' . number_format($ap, 2, ',', '.') . ' €';
            $html .= '<span class="ad" style="display:inline-block;margin:3px 4px 0 0;font-size:12px;color:#fff;background:#222E5C;padding:2px 8px;border-radius:4px;">' . $label . '</span>';
        }

        $html .= '</div>';
    }
    return $html ?: '<p style="color:#9ca3af;">—</p>';
}

/**
 * Determina la ciudad que define el precio del contenedor.
 * Pickup → ciudad de retiro. Delivery → ciudad de origen.
 */
function silversea_derive_price_city( $data ) {
    if ( ( $data['method'] ?? '' ) === 'pickup' ) {
        return $data['pickup'] ?? '';
    }
    return $data['origin'] ?? '';
}

/* Construir bloque HTML de transporte */
function silversea_email_shipping_html( $data, $show_prices ) {
    $con    = $data['consolidated'] ?? null;
    $method = $data['method']       ?? '';
    $transp = $data['transport']    ?? 'sin';
    $desc   = silversea_get_transport_desc( $transp, $method );
    $html   = '';

    if ( $method === 'pickup' ) {
        $pickup_label = silversea_origin_label( $data['pickup'] ?? '' );
        $html .= '<div class="tb" style="background:#f0fdf4;border-radius:8px;padding:16px 20px;margin:0;">';
        $html .= '<p class="tl" style="font-size:14px;font-weight:600;color:#1D9E75;margin:0 0 3px;">✓ Recogida en depósito · ' . esc_html($pickup_label) . ' — Sin coste</p>';
        $html .= '<p class="td" style="font-size:13px;color:#374151;margin:0;">' . esc_html($desc) . '</p>';
        $html .= '</div>';
        return $html;
    }

    $origin_label = silversea_origin_label( $data['origin'] ?? '' );
    $transp_label = $transp === 'con' ? 'Con descarga' : 'Sin descarga';

    $html .= '<div class="tb" style="background:#f8fafc;border-radius:8px;padding:16px 20px;margin:0;">';
    $html .= '<p class="tl" style="font-size:14px;font-weight:600;color:#0F2557;margin:0 0 3px;">'
           . esc_html($transp_label) . ' · desde ' . esc_html($origin_label)
           . ' · CP ' . esc_html($data['cp'] ?? '') . '</p>';
    $html .= '<p class="td" style="font-size:13px;color:#374151;margin:0;">' . esc_html($desc) . '</p>';

    if ( $show_prices && $con && ! empty($con['breakdown']) ) {
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        foreach ( $con['breakdown'] as $b ) {
            $html .= '<tr><td style="padding:5px 12px;border-bottom:1px solid #f0f0f0;color:#374151;">'
                   . esc_html($b['label']) . '</td>'
                   . '<td style="padding:5px 12px;border-bottom:1px solid #f0f0f0;text-align:right;font-weight:600;">€ '
                   . number_format((float)$b['price'],2,',','.') . '</td></tr>';
        }
        $html .= '<tr style="background:#f8fafc;"><td style="padding:8px 12px;font-weight:700;">Total envío</td>'
               . '<td style="padding:8px 12px;text-align:right;font-weight:700;color:#0F2557;">€ '
               . number_format((float)$con['total'],2,',','.') . '</td></tr>';
        $html .= '</table>';
    } elseif ( $show_prices && isset($data['price']) && $data['price'] > 0 ) {
        $html .= '<p style="font-size:14px;font-weight:600;color:#0F2557;margin-top:8px;">€ '
               . number_format((float)$data['price'],2,',','.') . '</p>';
    } elseif ( ! $con && ! ( isset($data['price']) && (float)$data['price'] > 0 ) ) {
        /* CP sin tarifa — precio pendiente de confirmar */
        $html .= '<p style="margin:10px 0 0;font-size:13px;font-weight:600;color:#d97706;">'
               . '⚠ Precio de transporte a confirmar por asesor comercial</p>';
    }

    /* Plazo de entrega */
    $html .= '<p style="margin:10px 0 0;font-size:12px;color:#6b7280;">'
           . 'El plazo estándar es de 5 a 7 días hábiles desde la confirmación del pago. '
           . 'El plazo exacto se acuerda al confirmar la oferta y puede variar según la ubicación de entrega y la disponibilidad de stock.'
           . '</p>';

    /* Nota BAF + complejidad — debajo del total de envío */
    $html .= '<p style="margin:10px 0 0;font-size:11px;color:#9ca3af;font-style:italic;'
           . 'padding-top:8px;border-top:1px dashed #e5e7eb;line-height:1.55;">'
           . 'El precio final puede estar sujeto a modificación en casos donde las condiciones de entrega '
           . 'presenten una complejidad especial (accesibilidad limitada, obstáculos en zona de descarga, '
           . 'cableado aéreo, terreno irregular/arenoso, u otros requisitos no contemplados en la cotización '
           . 'inicial), o por variaciones en el BAF (Factor de Ajuste de Combustible) aplicables al transporte terrestre.'
           . '</p>';

    $html .= '</div>'; /* close .tb */
    return $html;
}

/* Construir total estimado para email cliente */
function silversea_email_total( $raq_content, $data ) {
    $city  = silversea_derive_price_city( $data );
    $total = 0.0;
    foreach ( $raq_content as $raq ) {
        $pid      = ! empty($raq['variation_id']) ? $raq['variation_id'] : $raq['product_id'];
        $_product = wc_get_product( $pid );
        if ( ! $_product ) continue;
        $qty   = (int)($raq['quantity'] ?? 1);
        $cp    = $city ? silversea_get_product_city_price( $pid, $city ) : null;
        $price = $cp !== null ? $cp : (float) $_product->get_price();
        $total += $price * $qty;
        /* Sumar precios de addons (por unidad × cantidad) */
        foreach ( (array)($raq['silversea_addon_prices'] ?? []) as $ap ) {
            $total += (float)$ap * $qty;
        }
    }
    $con = $data['consolidated'] ?? null;
    if ( $con ) $total += (float)($con['total'] ?? 0);
    elseif ( isset($data['price']) ) $total += (float)$data['price'];
    return $total;
}

/* Plantilla HTML base del email */
function silversea_email_template( $quote_id, $data, $products_html, $shipping_html, $show_prices, $is_sales = false ) {
    $name    = esc_html( $data['name']  ?? '' );
    $email   = esc_html( $data['email'] ?? '' );
    $phone   = esc_html( $data['phone'] ?? '' );
    $type    = ucfirst( $data['type']   ?? '' );
    $city    = esc_html( trim( ($data['city'] ?? '') . ' ' . ($data['postal'] ?? '') ) );
    $message = $data['message'] ?? '';

    /* ══════════════════════════════
       EMAIL VENTAS
    ══════════════════════════════ */
    if ( $is_sales ) {
        $msg_row = $message
            ? '<tr><td style="padding:4px 0;color:#6b7280;vertical-align:top;">Mensaje</td><td style="padding:4px 0;">' . nl2br(esc_html($message)) . '</td></tr>'
            : '';
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
            . '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;color:#111;margin:0;padding:0;background:#f5f5f5;">'
            . '<div style="max-width:620px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">'
            . '<div style="background:#0F2557;padding:28px 32px;">'
            . '<h1 style="margin:0;color:#fff;font-size:20px;font-weight:600;">Nueva Solicitud de Cotización</h1>'
            . '<p style="margin:6px 0 0;color:#93C5FD;font-size:13px;">Quote #' . $quote_id . ' · ' . date('d/m/Y H:i') . '</p>'
            . '</div>'
            . '<div style="padding:24px 32px;border-bottom:1px solid #e5e7eb;">'
            . '<h2 style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;">Datos del cliente</h2>'
            . '<table style="width:100%;font-size:14px;border-collapse:collapse;">'
            . '<tr><td style="padding:4px 0;color:#6b7280;width:120px;">Nombre</td><td style="padding:4px 0;font-weight:600;">' . $name . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280;">Email</td><td style="padding:4px 0;"><a href="mailto:' . $email . '" style="color:#185FA5;">' . $email . '</a></td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280;">Teléfono</td><td style="padding:4px 0;">' . $phone . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280;">Tipo</td><td style="padding:4px 0;">' . $type . '</td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280;">Ciudad / CP</td><td style="padding:4px 0;">' . $city . '</td></tr>'
            . $msg_row
            . '</table></div>'
            . '<div style="padding:24px 32px;border-bottom:1px solid #e5e7eb;">'
            . '<h2 style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;">Productos</h2>'
            . $products_html . '</div>'
            . '<div style="padding:24px 32px;border-bottom:1px solid #e5e7eb;">'
            . '<h2 style="margin:0 0 10px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;">Envío</h2>'
            . $shipping_html . '</div>'
            . '<div style="padding:16px 32px;background:#f8fafc;">'
            . '<p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">'
            . '<a href="' . admin_url('post.php?post=' . $quote_id . '&action=edit') . '" style="color:#185FA5;">Ver en el panel</a>'
            . '</p></div>'
            . '</div></body></html>';
    }

    /* ══════════════════════════════
       EMAIL CLIENTE — narrativo
    ══════════════════════════════ */

    $total_str = '';
    if ( $show_prices && ! empty($data['raq_content']) ) {
        $total = silversea_email_total( $data['raq_content'], $data );
        if ( $total > 0 ) $total_str = number_format($total, 2, ',', '.');
    }

    $disclaimer = ''; /* Nota de precio/BAF movida a la sección de Transporte */

    $s = 'body{margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#111;}';
    $s .= '.wrap{max-width:620px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);}';
    $s .= '.hd{background:#0F2557;padding:28px 32px;} .hd h1{margin:0;color:#fff;font-size:22px;font-weight:700;} .hd p{margin:4px 0 0;color:#93C5FD;font-size:13px;}';
    $s .= '.bd{padding:28px 32px;} .gr{font-size:15px;line-height:1.65;color:#374151;margin:0 0 24px;}';
    $s .= '.st{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin:24px 0 12px;padding-bottom:8px;border-bottom:2px solid #f3f4f6;}';
    $s .= '.pb{padding:12px 0;border-bottom:1px solid #f3f4f6;} .pb:last-child{border-bottom:none;}';
    $s .= '.pn{margin:0 0 4px;font-size:15px;font-weight:600;color:#111;}';
    $s .= '.pm{margin:2px 0;font-size:13px;color:#6b7280;} .pd{margin:4px 0;font-size:13px;color:#374151;font-style:italic;}';
    $s .= '.ad{display:inline-block;margin:3px 4px 0 0;font-size:12px;color:#fff;background:#222E5C;padding:2px 8px;border-radius:4px;}';
    $s .= '.tb{background:#f8fafc;border-radius:8px;padding:16px 20px;}';
    $s .= '.tl{font-size:14px;font-weight:600;color:#0F2557;margin:0 0 3px;} .td{font-size:13px;color:#374151;margin:0;}';
    $s .= '.tot{background:#0F2557;padding:20px 32px;} .tot table{width:100%;}';
    $s .= '.tlab{font-size:15px;font-weight:700;color:#fff;} .tval{font-size:22px;font-weight:700;color:#fff;text-align:right;}';
    $s .= '.tiva{font-size:12px;font-weight:400;color:#93C5FD;}';
    $s .= '.ns{padding:24px 32px;border-bottom:1px solid #e5e7eb;}';
    $s .= '.ns ul{margin:8px 0 0;padding:0 0 0 20px;font-size:14px;color:#374151;line-height:1.9;}';
    $s .= '.ft{padding:20px 32px;background:#f8fafc;} .ft p{margin:0;font-size:12px;color:#9ca3af;line-height:1.65;}';
    $s .= '.disc{margin:14px 0 0;font-size:11px;color:#aab0bb;font-style:italic;line-height:1.65;padding-top:12px;border-top:1px dashed #e5e7eb;}';

    $o  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $s . '</style></head><body><div class="wrap">';
    $o .= '<div class="hd"><h1>SILVERSEA Containers</h1><p>Resumen de su solicitud · #' . $quote_id . '</p></div>';
    $o .= '<div class="bd">';
    $o .= '<p class="gr">Hola <strong>' . $name . '</strong>,<br><br>'
       .  'Gracias por contactar con <strong>SILVERSEA Containers</strong>. '
       .  'A continuación le enviamos el resumen de su solicitud'
       .  ( $show_prices ? ' y una estimación de precios para que tenga una referencia clara.' : '.' ) . '</p>';
    $o .= '<p class="st" style="margin-top:0;">Resumen de su solicitud</p>';
    $o .= $products_html;
    $o .= '<p class="st">Transporte</p>';
    $o .= $shipping_html;
    if ( $message ) {
        $o .= '<p class="st">Su mensaje</p>';
        $o .= '<p style="font-size:14px;color:#374151;font-style:italic;margin:0;">' . nl2br(esc_html($message)) . '</p>';
    }
    $o .= '</div>';
    if ( $show_prices && $total_str ) {
        $o .= '<div class="tot"><table><tr>';
        $o .= '<td class="tlab">Total estimado</td>';
        $o .= '<td class="tval">€ ' . $total_str . ' <span class="tiva">+ IVA</span></td>';
        $o .= '</tr></table></div>';
    }
    $o .= '<div class="ns"><p class="st" style="margin-top:0;">Nuestro equipo le contactará para</p>';
    $o .= '<ul><li>Confirmar disponibilidad en el depósito más cercano.</li>';
    $o .= '<li>Presentar la cotización definitiva.</li>';
    $o .= '<li>Coordinar entrega, recogida o descarga.</li>';
    $o .= '<li>Resolver cualquier duda técnica o logística.</li></ul></div>';
    $o .= '<div class="ft"><p>';
    $o .= 'Esta solicitud no constituye una reserva. ';
    $o .= '<a href="https://www.silverseacontainers.com/terminos" style="color:#185FA5;">Términos y Condiciones</a>';
    $o .= '<br>Si necesitas asistencia inmediata: <a href="mailto:sales@silverseacontainers.com" style="color:#185FA5;">sales@silverseacontainers.com</a>';
    $o .= '</p></div>';
    $o .= '</div></body></html>';
    return $o;
}

/* Función principal de envío */
function silversea_send_admin_email( $quote_id, $data ) {
    $debug_mode   = get_option('silversea_demo_mode', '0') === '1';
    $send_client  = get_option('silversea_email_send_client', '0') === '1';
    $show_prices  = get_option('silversea_email_show_prices', '1') === '1';

    $email_from      = 'sales@silverseacontainers.com';
    $email_comercial = get_option('silversea_sales_email', '');
    $email_debug     = get_option('silversea_admin_email', get_option('admin_email'));

    $subject_sales  = sprintf('[Silversea Ventas] Nueva cotización #%d – %s', $quote_id, $data['name']);
    $subject_client = sprintf('[Silversea Containers] Su solicitud de cotización #%d', $quote_id);

    $headers_base = [
        'Content-Type: text/html; charset=UTF-8',
        'From: SILVERSEA Containers <' . $email_from . '>',
    ];
    if ( $data['email'] ) {
        $headers_base[] = 'Reply-To: ' . $data['name'] . ' <' . $data['email'] . '>';
    }

    /* ── Construir HTML de productos:
          Si raq_content tiene datos usarlo; si no, leer desde _sq_products meta ── */
    $raq        = $data['raq_content'] ?? [];
    $price_city = silversea_derive_price_city( $data );
    $products_html_sales = ! empty($raq)
        ? silversea_email_products_html( $raq, true, $price_city )
        : silversea_email_products_html_from_meta( $quote_id, true, $price_city );

    $shipping_html_sales = silversea_email_shipping_html( $data, true );

    /* Siempre regenerar el body al enviar (para que refleje los productos correctos)
       Solo usar el guardado si es un reenvío explícito desde el metabox (flag) */
    $use_saved = $data['use_saved_body'] ?? false;
    $body_sales = ( $use_saved && get_post_meta($quote_id, '_sq_email_body_sales', true) )
        ? get_post_meta($quote_id, '_sq_email_body_sales', true)
        : silversea_email_template( $quote_id, $data, $products_html_sales, $shipping_html_sales, true, true );

    update_post_meta( $quote_id, '_sq_email_body_sales', $body_sales );

    $to_sales = $debug_mode ? $email_debug : $email_comercial;
    if ( $to_sales ) {
        wp_mail( $to_sales, $subject_sales, $body_sales, $headers_base );
    }
    /* Si no hay email configurado: el body queda guardado en el CPT
       para enviarlo manualmente desde el panel de cotizaciones. */

    /* ── Email cliente (opcional) — solo si también hay email de ventas configurado ── */
    if ( $send_client && $data['email'] && $to_sales ) {
        $to_client = $debug_mode ? $email_debug : $data['email'];

        $products_html_client = ! empty($raq)
            ? silversea_email_products_html( $raq, $show_prices, $price_city )
            : silversea_email_products_html_from_meta( $quote_id, $show_prices, $price_city );

        $shipping_html_client = silversea_email_shipping_html( $data, $show_prices );

        $body_client = ( $use_saved && get_post_meta($quote_id, '_sq_email_body_client', true) )
            ? get_post_meta($quote_id, '_sq_email_body_client', true)
            : silversea_email_template( $quote_id, $data, $products_html_client, $shipping_html_client, $show_prices, false );

        update_post_meta( $quote_id, '_sq_email_body_client', $body_client );
        wp_mail( $to_client, $subject_client, $body_client, $headers_base );
    }
}






/* ══════════════════════════════════════════════════════════════
   6.  ENCOLAR CSS de la página de cotización
══════════════════════════════════════════════════════════════ */

add_action( 'wp_enqueue_scripts', 'silversea_enqueue_raq_styles' );

function silversea_enqueue_raq_styles() {
    $raq_page_id = get_option( 'ywraq_page_id' );
    if ( ! $raq_page_id || ! is_page( $raq_page_id ) ) return;
    wp_enqueue_style(
        'silversea-raq',
        SILVERSEA_PLUGIN_URL . 'assets/css/silversea-raq.css',
        [], SILVERSEA_VERSION
    );
}


/* ══════════════════════════════════════════════════════════════
   6.  BLOQUEAR "AÑADIR A SELECCIÓN" SI NO SE HA COTIZADO
══════════════════════════════════════════════════════════════ */

add_filter( 'ywraq_ajax_add_item_is_valid', 'silversea_require_quote_before_add', 5, 2 );

function silversea_require_quote_before_add( $is_valid, $product_id ) {
    if ( get_option('silversea_require_quote', '0') !== '1' ) return $is_valid;
    if ( ! $is_valid ) return $is_valid;

    $sc = WC()->session ? WC()->session->get('silversea_shipping_data') : null;

    if ( empty($sc) || empty($sc['method']) ) {
        wp_send_json_error([
            'message' => silversea_text('msg_require_quote'),
        ]);
        exit;
    }

    /* Guardar el último product_id agregado con cotización */
    WC()->session->set( 'silversea_last_added_product', (int)$product_id );

    return $is_valid;
}


/* ══════════════════════════════════════════════════════════════
   7.  GUARDAR ADDONS EN EL QUOTE CART
       Paso A: ywraq_ajax_add_item_is_valid → capturar yith_wapo
       Paso B: yith_raq_updated → inyectar en raq_content
══════════════════════════════════════════════════════════════ */

add_filter( 'ywraq_ajax_add_item_is_valid', 'silversea_intercept_wapo', 10, 2 );

function silversea_intercept_wapo( $is_valid, $product_id ) {
    if ( ! $product_id ) return $is_valid;
    if ( empty( $_POST['yith_wapo'] ) || ! is_array( $_POST['yith_wapo'] ) ) return $is_valid;

    $addons = [];
    foreach ( $_POST['yith_wapo'] as $group ) {
        if ( ! is_array( $group ) ) continue;
        foreach ( $group as $val ) {
            $val = sanitize_text_field( $val );
            if ( $val && ! in_array( $val, $addons, true ) ) $addons[] = $val;
        }
    }
    if ( empty( $addons ) ) return $is_valid;

    /* Capturar precios inyectados por el JS (silversea_addon_price[label] = precio) */
    $addon_prices = [];
    foreach ( (array)( $_POST['silversea_addon_price'] ?? [] ) as $label => $price ) {
        $label = sanitize_text_field( $label );
        $price = (float) $price;
        if ( $label && $price > 0 ) $addon_prices[ $label ] = $price;
    }

    global $silversea_pending_addons, $silversea_pending_addon_prices;
    $silversea_pending_addons[ (int) $product_id ]       = $addons;
    $silversea_pending_addon_prices[ (int) $product_id ] = $addon_prices;

    return $is_valid;
}

add_action( 'yith_raq_updated', 'silversea_inject_addons_to_raq' );

function silversea_inject_addons_to_raq() {
    global $silversea_pending_addons, $silversea_pending_addon_prices;
    if ( empty( $silversea_pending_addons ) ) return;
    if ( ! function_exists( 'YITH_Request_Quote' ) ) return;

    $yith = YITH_Request_Quote();
    if ( empty( $yith->raq_content ) ) return;

    $modified = false;
    foreach ( $yith->raq_content as $key => &$raq ) {
        $pid = (int) $raq['product_id'];
        $vid = (int) ( $raq['variation_id'] ?? 0 );
        /* Buscar addons primero por variation_id (productos variables),
           luego por product_id (productos simples). */
        $lookup_key = isset( $silversea_pending_addons[ $vid ] ) ? $vid
                    : ( isset( $silversea_pending_addons[ $pid ] ) ? $pid : null );
        if ( $lookup_key === null ) continue;
        if ( isset( $raq['silversea_addons'] ) ) continue;

        $raq['silversea_addons']       = $silversea_pending_addons[ $lookup_key ];
        $raq['silversea_addon_prices'] = $silversea_pending_addon_prices[ $lookup_key ] ?? [];
        unset( $silversea_pending_addons[ $lookup_key ], $silversea_pending_addon_prices[ $lookup_key ] );
        $modified = true;
    }
    unset( $raq );

    if ( $modified ) {
        remove_action( 'yith_raq_updated', 'silversea_inject_addons_to_raq' );
        $yith->session_class->set( 'raq', $yith->raq_content );
        add_action( 'yith_raq_updated', 'silversea_inject_addons_to_raq' );
    }
}