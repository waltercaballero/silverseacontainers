<?php
/**
 * Silversea � Shortcodes de cotizaci�n
 * @version 1.2.0
 *
 * Uso:
 *   [silversea_quote_view form_page="slug-del-form"]
 *   [silversea_quote_form view_page="slug-de-mi-seleccion"]
 *
 * Incluir desde shipping-calculator.php:
 *   require_once __DIR__ . '/shipping-quote-pages.php';
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/*
   FILTRO � redirigir bot�n "Solicitar Cotizaci�n" de YITH
 */

$GLOBALS['silversea_form_page_slug'] = '';

add_filter( 'ywraq_request_page_url', function ( $url ) {
    $slug = $GLOBALS['silversea_form_page_slug'];
    if ( $slug ) {
        $page = get_page_by_path( $slug );
        if ( $page ) return get_permalink( $page->ID );
    }
    return $url;
} );

/*
   Cuando el usuario hace "Actualizar lista" en Mi Selección:
   – Limpia el dato de envío guardado en sesión (ya no es válido para las nuevas cantidades)
   – Añade ?sc_recalc=1 al redirect para que el JS auto-recalcule al cargar
*/
add_filter( 'wp_redirect', function ( $location, $status ) {
    if ( empty( $_POST['update_raq'] ) ) return $location;
    if ( ! isset( $_POST['update_raq_wpnonce'] ) ) return $location;
    if ( ! wp_verify_nonce( $_POST['update_raq_wpnonce'], 'update-request-quote-quantity' ) ) return $location;

    /* Solo actuar si el redirect apunta a la página RAQ de YITH */
    $raq_page_id = (int) get_option( 'ywraq_page_id' );
    if ( $raq_page_id && strpos( $location, get_permalink( $raq_page_id ) ) === false ) return $location;

    if ( function_exists('WC') && WC()->session ) {
        WC()->session->__unset( 'silversea_shipping_data' );
    }
    return add_query_arg( 'sc_recalc', '1', $location );
}, 10, 2 );


/*
   HELPER — obtener raq_content de YITH (sesión o propiedad del objeto).
*/
function silversea_get_raq_content() {
    if ( ! function_exists('YITH_Request_Quote') ) return [];
    $yith        = YITH_Request_Quote();
    $raq_content = $yith->get_raq_return();
    if ( empty( $raq_content ) && isset( $yith->session_class ) ) {
        $raq_content = $yith->session_class->get( 'raq', [] );
        if ( ! empty( $raq_content ) ) $yith->raq_content = $raq_content;
    }
    return $raq_content;
}


/*
   HELPER � renderizar lista de productos del quote
   Incluye imagen, nombre, condici�n, addons y cantidad.
 */

function silversea_render_product_list( $raq_content, $show_remove = false ) {
    if ( empty($raq_content) ) return '';

    ob_start(); ?>
    <div class="silversea-product-list">
        <?php foreach ( $raq_content as $key => $raq ) :
            $pid      = ! empty($raq['variation_id']) ? $raq['variation_id'] : $raq['product_id'];
            $_product = wc_get_product($pid);
            if ( ! $_product ) continue;

            $img_url  = wp_get_attachment_image_url( $_product->get_image_id(), 'thumbnail' ) ?: wc_placeholder_img_src('thumbnail');
            $title    = $_product->get_title();
            $qty      = (int) $raq['quantity'];
            $addons   = $raq['silversea_addons'] ?? [];

            $terms     = wc_get_product_terms( $_product->get_id(), 'pa_condicion', ['fields' => 'names'] );
            $condicion = ! empty($terms) ? ucfirst($terms[0]) : '';
        ?>
        <div class="silversea-product-item">
            <div class="silversea-product-img">
                <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($title); ?>">
            </div>
            <div class="silversea-product-info">
                <p class="silversea-product-name"><?php echo esc_html($title); ?></p>
                <?php /* if ( $condicion ) : ?>
                    <p class="silversea-product-meta"><?php echo esc_html($condicion); ?></p>
                <?php endif; */ ?>
                <?php foreach ( $addons as $addon ) : ?>
                    <p class="silversea-product-addon">
                        <!-- <span class="silversea-addon-label">Adicional</span> -->
                        <?php echo esc_html($addon); ?>
                    </p>
                <?php endforeach; ?>
            </div>
            <div class="silversea-product-qty">
                <span class="silversea-qty-label">Cant.</span>
                <span class="silversea-qty-value"><?php echo $qty; ?></span>
            </div>
            <?php if ( $show_remove ) : ?>
            <div class="silversea-product-remove">
                <a href="#"
                   class="silversea-remove-item"
                   data-key="<?php echo esc_attr($key); ?>"
                   title="Eliminar">x</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}


/*
   SHORTCODE [silversea_quote_view]
   Par�metros:
     form_page  � slug de la p�gina con [silversea_quote_form]
 */

add_shortcode( 'silversea_quote_view', 'silversea_shortcode_quote_view' );

function silversea_shortcode_quote_view( $atts ) {
    if ( ! function_exists('YITH_Request_Quote') ) return '';

    $atts = shortcode_atts( [
        'form_page' => '',
    ], $atts, 'silversea_quote_view' );

    if ( $atts['form_page'] ) {
        $GLOBALS['silversea_form_page_slug'] = sanitize_title( $atts['form_page'] );
    }

    $raq_content         = silversea_get_raq_content();
    $quote_wrapper_class = get_option( 'ywraq_page_list_layout_template', '' );
    $quote_wrapper_class = empty($raq_content) ? '' : $quote_wrapper_class;
    $args                = [ 'raq_content' => $raq_content ];

    function_exists('wc_nocache_headers') && wc_nocache_headers();

    ob_start();
    echo '<div class="woocommerce ywraq-wrapper">';
    echo '<div id="yith-ywraq-message"></div>';
    if ( function_exists('yith_ywraq_print_notices') ) yith_ywraq_print_notices();
    echo '<div class="ywraq-form-table-wrapper ' . esc_attr($quote_wrapper_class) . '">';
    wc_get_template( 'request-quote-view.php', $args, '', YITH_YWRAQ_TEMPLATE_PATH );
    echo '</div>';
    echo '</div>';
    return ob_get_clean();
}


/*
   SHORTCODE [silversea_quote_form]
   Muestra:
     1. Listado informativo de productos (imagen, addons, cantidad)
     2. Formulario de contacto (template YITH override)
   El bot�n "Solicitar Cotizaci�n" va en Elementor (manual).

   Par�metros:
     view_page  � slug de la p�gina con [silversea_quote_view]
 */

add_shortcode( 'silversea_quote_form', 'silversea_shortcode_quote_form' );

function silversea_shortcode_quote_form( $atts ) {
    if ( ! function_exists('YITH_Request_Quote') ) return '';

    $atts = shortcode_atts( [
        'view_page'   => '',
        'thanks_page' => 'gracias-cotizar',
    ], $atts, 'silversea_quote_form' );

    $raq_content = silversea_get_raq_content();
    function_exists('wc_nocache_headers') && wc_nocache_headers();

    /* Guardar URL de gracias en sesi�n para el redirect filter */
    if ( $atts['thanks_page'] && WC()->session ) {
        $thanks_page_obj = get_page_by_path( sanitize_title($atts['thanks_page']) );
        if ( $thanks_page_obj ) {
            WC()->session->set( 'silversea_thanks_url', get_permalink($thanks_page_obj->ID) );
        }
    }

    $view_url = '';
    if ( $atts['view_page'] ) {
        $view_page = get_page_by_path( sanitize_title($atts['view_page']) );
        if ( $view_page ) $view_url = get_permalink( $view_page->ID );
    }

    ob_start(); ?>
    <div class="woocommerce ywraq-wrapper">
        <div id="yith-ywraq-message"></div>
        <?php if ( function_exists('yith_ywraq_print_notices') ) yith_ywraq_print_notices(); ?>

        <?php if ( ! empty($raq_content) ) : ?>

            <?php /* - Listado informativo de productos - */ ?>
            <div class="silversea-form-product-summary">
                <h3 class="silversea-form-summary-title">Tu Solicitud</h3>
                <?php echo silversea_render_product_list($raq_content, false); ?>
            </div>

            <?php
            $ya_cotizo    = WC()->session && WC()->session->get('silversea_last_quote');
            $sc_shipping  = WC()->session ? WC()->session->get('silversea_shipping_data') : null;

            /* - Resumen de env�o (siempre visible si hay datos) - */
            if ( $sc_shipping && ! empty($sc_shipping['method']) ) :
                $sc_method = $sc_shipping['method'];
                if ( $sc_method === 'pickup' ) {
                    $pickup_city   = $sc_shipping['pickup_city'] ?? '';
                    $pickup_label  = silversea_origin_label( $pickup_city );
                    $shipping_desc = 'Retiro en dep&oacute;sito - ' . $pickup_label;
                } else {
                    $sc_origin     = $sc_shipping['origin'] ?? '';
                    $origin_label  = silversea_origin_label( $sc_origin );
                    $sc_cp         = $sc_shipping['postal_code'] ?? '';
                    $sc_transport  = $sc_shipping['transport'] === 'con' ? 'Con descarga' : 'Sin descarga';
                    $shipping_desc = 'Entrega desde ' . $origin_label . ' - CP ' . $sc_cp . ' - ' . $sc_transport;
                }
            ?>
            <div class="silversea-shipping-summary-compact">
                <span class="silversea-shipping-summary-icon"></span>
                <span class="silversea-shipping-summary-text"><?php echo esc_html($shipping_desc); ?></span>
                <a href="#" id="silversea-recotizar-form" class="silversea-shipping-summary-edit">Recalcular</a>
            </div>
            <div id="silversea-widget-recotizar-form" class="sc-hidden" style="margin-bottom:1.5rem;">
                <?php echo do_shortcode('[silversea_shipping mode="consolidated"]'); ?>
            </div>
            <script>
            (function() {
                var btn = document.getElementById('silversea-recotizar-form');
                if ( !btn ) return;
                var w = document.getElementById('silversea-widget-recotizar-form');
                var open = false;
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    open = !open;
                    if ( w ) w.classList.toggle('sc-hidden', !open);
                    btn.textContent = open ? 'Cancelar' : 'Recalcular';
                });
            })();
            </script>
            <?php endif; ?>

            <?php /* - Formulario � solo si a�n no cotiz� - */ ?>
            <?php if ( ! $ya_cotizo ) : ?>
            <?php
            /* Cargar override del tema hijo primero, luego plugin */
            $form_override = get_stylesheet_directory() . '/woocommerce/yith-request-quote/request-quote-form.php';
            $form_plugin   = defined('YITH_YWRAQ_TEMPLATE_PATH') ? YITH_YWRAQ_TEMPLATE_PATH . 'request-quote-form.php' : '';
            $form_file     = file_exists($form_override) ? $form_override : $form_plugin;
            if ( $form_file && file_exists($form_file) ) {
                include $form_file;
            }
            ?>
            <?php else : ?>
            <div class="silversea-already-quoted">
                <div class="silversea-already-quoted-icon"></div>
                <p class="silversea-already-quoted-title">Tu solicitud ya fue enviada</p>
                <p class="silversea-already-quoted-sub">En breve recibiri&aacute;s un correo con todos los detalles.</p>
            </div>
            <?php endif; ?>

        <?php else : ?>
            <div class="silversea-raq-empty">
                <p>No hay productos en tu selecci&oacute;n.</p>
                <?php if ( $view_url ) : ?>
                    <a href="<?php echo esc_url($view_url); ?>" class="silversea-btn-outline">
                        Ver mi selecci&oacute;n
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


/*
   SHORTCODE [silversea_thanks]
   Muestra resumen del pedido despu�s del submit.
   Los datos vienen de los post_meta del �ltimo quote guardado,
   que se leen desde la sesi�n de YITH (la sesi�n a�n no se limpi�).

   Uso: [silversea_thanks]
 */

add_shortcode( 'silversea_thanks', 'silversea_shortcode_thanks' );

function silversea_shortcode_thanks( $atts ) {
    if ( ! isset($_GET['sent']) || $_GET['sent'] != 1 ) return '';

    /* Leer datos guardados en WC session por silversea_process_and_save() */
    $last = WC()->session ? WC()->session->get('silversea_last_quote') : null;

    $name        = $last['name']     ?? '';
    $type        = $last['type']     ?? '';
    $city        = $last['city']     ?? '';
    $postal      = $last['postal']   ?? '';
    $phone       = $last['phone']    ?? '';
    $message     = $last['message']  ?? '';
    $products    = $last['products'] ?? [];
    $method      = $last['method']   ?? '';
    $origin      = $last['origin']   ?? '';
    $cp          = $last['cp']       ?? '';
    $transport   = $last['transport'] ?? '';
    $pickup      = $last['pickup']   ?? '';
    $price       = $last['price']    ?? 0;
    $consolidated= $last['consolidated'] ?? null;

    $raq_content        = silversea_get_raq_content();
    $use_products_array = empty($raq_content) && ! empty($products);

    ob_start(); ?>
    <div class="silversea-thanks-wrap">

        <!--
		<div class="silversea-thanks-hero">
            <div class="silversea-thanks-check"></div>
            <h1 class="silversea-thanks-title">Muchas gracias por su solicitud</h1>
            <p class="silversea-thanks-subtitle">
                En breve recibir� un correo desde <strong>sales@silverseacontainers.com</strong> con todos los detalles de lo solicitado.<br>
                Adem�s, uno de nuestros asesores se comunicar�n con usted telef�nicamente para acompa�arlo en el proceso.
            </p>
        </div>
		-->

        <div class="silversea-thanks-grid">

            <?php /* - Resumen de la orden - */ ?>
            <div class="silversea-thanks-section">
                <h3 class="silversea-thanks-section-title">Resumen De La Orden</h3>
                <div class="silversea-product-list">

                <?php if ( ! $use_products_array && ! empty($raq_content) ) :
                    foreach ( $raq_content as $key => $raq ) :
                        $pid      = ! empty($raq['variation_id']) ? $raq['variation_id'] : $raq['product_id'];
                        $_product = wc_get_product($pid);
                        if ( ! $_product ) continue;
                        $img_url   = wp_get_attachment_image_url($_product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail');
                        $ptitle    = $_product->get_title();
                        $qty       = (int) $raq['quantity'];
                        $addons    = $raq['silversea_addons'] ?? [];
                        $terms     = wc_get_product_terms($_product->get_id(), 'pa_condicion', ['fields'=>'names']);
                        $condicion = ! empty($terms) ? ucfirst($terms[0]) : '';
                    ?>
                    <div class="silversea-product-item">
                        <div class="silversea-product-img">
                            <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($ptitle); ?>">
                        </div>
                        <div class="silversea-product-info">
                            <p class="silversea-product-name"><?php echo esc_html($ptitle); ?></p>
                            <?php /* if ( $condicion ) echo '<p class="silversea-product-meta">' . esc_html($condicion) . '</p>'; */ ?>
                            <?php foreach ( $addons as $addon ) : ?>
                                <p class="silversea-product-addon">
                                    <!-- <span class="silversea-addon-label">Adicionales</span> -->
                                    <?php echo esc_html($addon); ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( $qty > 1 ) : ?>
                        <div class="silversea-product-qty">
                            <span class="silversea-qty-label">Cant.</span>
                            <span class="silversea-qty-value"><?php echo $qty; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach;

                elseif ( $use_products_array ) :
                    foreach ( $products as $item ) : ?>
                    <div class="silversea-product-item">
                        <div class="silversea-product-info">
                            <p class="silversea-product-name"><?php echo esc_html($item['name']); ?></p>
                            <?php /* if ( ! empty($item['condition']) ) echo '<p class="silversea-product-meta">' . esc_html($item['condition']) . '</p>'; */ ?>
                            <?php foreach ( $item['addons'] ?? [] as $addon ) : ?>
                                <p class="silversea-product-addon">
                                    <!-- <span class="silversea-addon-label">Adicionales</span> -->
                                    <?php echo esc_html($addon); ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( $item['qty'] > 1 ) : ?>
                        <div class="silversea-product-qty">
                            <span class="silversea-qty-label">Cant.</span>
                            <span class="silversea-qty-value"><?php echo (int)$item['qty']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach;
                endif; ?>

                </div><!-- .silversea-product-list -->
            </div>

            <?php /* - Datos de contacto - */ ?>
            <div class="silversea-thanks-section">
                <h3 class="silversea-thanks-section-title">Datos De Contacto</h3>
                <div class="silversea-thanks-contact">
                    <?php if ( $name )            echo '<p>' . esc_html($name) . '</p>'; ?>
                    <?php if ( $type )            echo '<p>' . ucfirst(esc_html($type)) . '</p>'; ?>
                    <?php if ( $city || $postal ) echo '<p>' . esc_html(trim("$city $postal")) . '</p>'; ?>
                    <?php if ( $phone )           echo '<p>' . esc_html($phone) . '</p>'; ?>
                    <?php if ( $message ) : ?>
                        <p style="margin-top:10px;"><strong>Observaciones:</strong> <?php echo esc_html($message); ?></p>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- .silversea-thanks-grid -->
    </div>
    <?php
    $output = ob_get_clean();

    /* Limpiar sesi�n una sola vez � usamos flag para no limpiar en recargas */
    if ( WC()->session && WC()->session->get('silversea_last_quote') ) {
        WC()->session->__unset('silversea_last_quote');
        WC()->session->__unset('silversea_thanks_url');
        /* Limpiar tambi�n el quote cart de YITH */
        if ( function_exists('YITH_Request_Quote') ) {
            YITH_Request_Quote()->clear_raq_list();
        }
    }

    return $output;
}


/*
   CSS � encolar en p�ginas que usen estos shortcodes
 */

add_action( 'wp_enqueue_scripts', function () {
    global $post;
    if ( ! $post ) return;

    $has_shortcode = has_shortcode( $post->post_content, 'silversea_quote_view' )
                  || has_shortcode( $post->post_content, 'silversea_quote_form' )
                  || has_shortcode( $post->post_content, 'silversea_thanks' );

    if ( ! $has_shortcode ) return;

    wp_enqueue_style(
        'silversea-raq',
        SILVERSEA_PLUGIN_URL . 'assets/css/silversea-raq.css',
        [], '1.2.0'
    );
} );
