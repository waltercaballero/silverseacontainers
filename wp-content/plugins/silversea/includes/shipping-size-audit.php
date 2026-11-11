<?php
/**
 * Silversea – Auditoría del tamaño de envío de los productos
 *
 * El tamaño (10'/20'/40') de cada producto decide cuántos camiones se cobran. Si no se
 * puede detectar, el cálculo asume 20' EN SILENCIO (ver silversea_get_product_size_info()),
 * y el presupuesto sale mal sin que nadie lo note. Este archivo hace visible ese caso:
 *
 *  - Columna "Tamaño de envío" en el listado de productos, con el origen del dato.
 *  - Aviso en el listado de productos y en el panel Cotizador cuando hay productos
 *    cuyo tamaño NO se pudo detectar (se cotizan como 20').
 *
 * Origen del tamaño: atributo pa_tamano (confiable) · título (inferido) · defecto (no detectado).
 * El aviso salta solo por "defecto"; "por título" es solo informativo en la columna.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Clave del transient donde se cachea el resultado del escaneo. */
function silversea_size_audit_cache_key() {
    return 'silversea_size_audit';
}

/**
 * Escanea los productos publicados y devuelve los que NO tienen tamaño detectable
 * (se cotizarían como 20' por defecto). Cada item: ['id', 'title', 'edit_url'].
 */
function silversea_size_audit_scan() {
    $ids = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $problems = [];
    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product ) continue;
        $info = silversea_get_product_size_info( $product );
        if ( $info['source'] === 'default' ) {
            $problems[] = [
                'id'       => (int) $id,
                'title'    => $product->get_title(),
                'edit_url' => get_edit_post_link( $id, 'raw' ),
            ];
        }
    }
    return $problems;
}

/** Resultado del escaneo, cacheado 1 hora (se invalida al guardar/borrar productos). */
function silversea_size_audit_get() {
    $cached = get_transient( silversea_size_audit_cache_key() );
    if ( is_array( $cached ) ) return $cached;

    $problems = silversea_size_audit_scan();
    set_transient( silversea_size_audit_cache_key(), $problems, HOUR_IN_SECONDS );
    return $problems;
}

function silversea_size_audit_flush() {
    delete_transient( silversea_size_audit_cache_key() );
}
add_action( 'woocommerce_new_product',    'silversea_size_audit_flush' );
add_action( 'woocommerce_update_product', 'silversea_size_audit_flush' );
add_action( 'deleted_post',               'silversea_size_audit_flush' );

/* ── Columna "Tamaño de envío" en el listado de productos ─────────────── */

add_filter( 'manage_edit-product_columns', 'silversea_size_audit_columns', 20 );

function silversea_size_audit_columns( $columns ) {
    $after = isset( $columns['silversea_sku_color'] ) ? 'silversea_sku_color' : 'name';
    $new   = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === $after ) $new['silversea_ship_size'] = 'Tamaño de envío';
    }
    return isset( $new['silversea_ship_size'] ) ? $new : ( $columns + [ 'silversea_ship_size' => 'Tamaño de envío' ] );
}

add_action( 'manage_product_posts_custom_column', 'silversea_size_audit_column_content', 10, 2 );

function silversea_size_audit_column_content( $column, $post_id ) {
    if ( $column !== 'silversea_ship_size' ) return;

    $product = wc_get_product( $post_id );
    if ( ! $product ) return;

    $info = silversea_get_product_size_info( $product );
    $size = (int) $info['size'];

    if ( $info['source'] === 'attribute' ) {
        echo '<strong>' . $size . '\'</strong> <span style="font-size:11px;color:#6b7280;">atributo</span>';
    } elseif ( $info['source'] === 'title' ) {
        echo '<strong>' . $size . '\'</strong> <span style="font-size:11px;color:#b45309;" title="No tiene el atributo Tamaño: se infiere del título.">por título</span>';
    } else {
        echo '<span style="color:#b91c1c;font-weight:600;" title="No tiene el atributo Tamaño ni un 10/20/40 en el título: se cotiza como contenedor de 20\'.">⚠ no detectado</span>'
           . '<br><span style="font-size:11px;color:#b91c1c;">se cotiza como 20\'</span>';
    }
}

add_action( 'admin_head', 'silversea_size_audit_admin_head' );

function silversea_size_audit_admin_head() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'edit-product' ) return;
    echo '<style>.column-silversea_ship_size { width: 130px; }</style>';
}

/* ── Aviso: productos que se cotizan con tamaño "supuesto" ────────────── */

add_action( 'admin_notices', 'silversea_size_audit_notice' );

function silversea_size_audit_notice() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, [ 'edit-product', 'toplevel_page_silversea-cotizador' ], true ) ) return;

    $problems = silversea_size_audit_get();
    if ( empty( $problems ) ) return;

    $max   = 8;
    $items = [];
    foreach ( array_slice( $problems, 0, $max ) as $p ) {
        $items[] = '<a href="' . esc_url( $p['edit_url'] ) . '">' . esc_html( $p['title'] ) . '</a>';
    }
    $more = count( $problems ) > $max ? ' y ' . ( count( $problems ) - $max ) . ' más' : '';

    echo '<div class="notice notice-warning"><p><strong>⚠ ' . count( $problems )
       . ( count( $problems ) === 1 ? ' producto sin tamaño detectable' : ' productos sin tamaño detectable' )
       . '</strong> — se cotizan como contenedor de <strong>20\'</strong> en silencio, y el envío puede salir mal '
       . '(camiones de más o de menos). Cargá el atributo <em>Tamaño</em> en cada uno: '
       . implode( ', ', $items ) . esc_html( $more ) . '.</p>'
       . '<p style="color:#6b7280;">Si alguno no es un contenedor (no lleva envío por camión), ignoralo.</p></div>';
}
