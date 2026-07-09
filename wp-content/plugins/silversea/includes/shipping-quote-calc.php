<?php
/**
 * Silversea – Cálculo consolidado de envío para el quote cart de YITH
 * @version 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── CIUDADES ──────────────────────────────────────────────────
   Cada ciudad tiene: key, name, depot, address, modes[]
   modes puede contener 'delivery', 'pickup' o ambas.
   Los datos se guardan en la opción silversea_cities_config;
   si la opción no existe se usan los defaults definidos aquí.
────────────────────────────────────────────────────────────── */

function silversea_default_cities() {
    return [
        [
            'key'          => 'barcelona',
            'name'         => 'Barcelona',
            'display_name' => '',
            'depot'        => 'Almacenes de Depósito MARTAINER',
            'address'      => 'Ronda del port, 437, 08039 Barcelona',
            'modes'        => ['delivery', 'pickup'],
        ],
        [
            'key'          => 'madrid',
            'name'         => 'Madrid',
            'display_name' => '',
            'depot'        => 'MAARIF S.L.',
            'address'      => 'Humanes de Madrid, Camino de la Fraila esq. calle Salamanca (acceso desde M-413)',
            'modes'        => ['delivery', 'pickup'],
        ],
        [
            'key'          => 'madrid2',
            'name'         => 'Madrid 2',
            'display_name' => '',
            'depot'        => 'SILVERMODULAR',
            'address'      => 'C. de Tamajón, 53, 19210 Yunquera de Henares, Guadalajara',
            'modes'        => ['delivery', 'pickup'],
        ],
        [
            'key'          => 'valencia',
            'name'         => 'Valencia',
            'display_name' => '',
            'depot'        => 'Trans Base Soler',
            'address'      => 'Puerto de Valencia, Ampliación Norte S/N, 46024 Valencia',
            'modes'        => ['delivery', 'pickup'],
        ],
        [
            'key'          => 'bilbao',
            'name'         => 'Bilbao',
            'display_name' => '',
            'depot'        => 'Depot CARGOR',
            'address'      => 'PORT OF BILBAO. ZAL ZONE. MUELLE AZ 3 TRASERA, 48508 ZIBERBENA',
            'modes'        => ['pickup'],
        ],
        [
            'key'          => 'algeciras',
            'name'         => 'Algeciras',
            'display_name' => '',
            'depot'        => 'Depot DOCKS ALGECIRAS',
            'address'      => 'Polígono Industrial ZAL, Área del Fresno s/n, Estación Férrea s/n, Los Barrios 11370 Cádiz',
            'modes'        => ['pickup'],
        ],
    ];
}

/**
 * Texto que se muestra en los desplegables del frontend para una ciudad.
 * Si display_name está configurado, lo usa; si no, usa nombre + depósito.
 */
function silversea_city_dropdown_label( $city ) {
    return ! empty( $city['display_name'] )
        ? $city['display_name']
        : $city['name'] . ' — ' . $city['depot'];
}

/** Devuelve el array completo de ciudades (desde opción o defaults). */
function silversea_get_cities() {
    $saved = get_option( 'silversea_cities_config' );
    return ( is_array($saved) && ! empty($saved) ) ? $saved : silversea_default_cities();
}

/** Ciudades filtradas por modo: 'delivery' | 'pickup'. */
function silversea_get_cities_for_mode( $mode ) {
    return array_values( array_filter( silversea_get_cities(), function( $c ) use ( $mode ) {
        return in_array( $mode, $c['modes'], true );
    } ) );
}

/** Claves (keys) de todas las ciudades, o filtradas por modo. */
function silversea_get_city_keys( $mode = null ) {
    $cities = $mode ? silversea_get_cities_for_mode( $mode ) : silversea_get_cities();
    return array_column( $cities, 'key' );
}

/** Objeto ciudad por key, o null si no existe. */
function silversea_get_city( $key ) {
    foreach ( silversea_get_cities() as $city ) {
        if ( $city['key'] === $key ) return $city;
    }
    return null;
}

/**
 * Precio de un producto para una ciudad específica.
 * Busca en el meta _silversea_city_prices del producto padre.
 * Devuelve float o null si no hay precio configurado para esa ciudad.
 */
function silversea_get_product_city_price( $product_id, $city ) {
    if ( ! $product_id || ! $city ) return null;

    $product = wc_get_product( $product_id );
    if ( ! $product ) return null;

    /* Para variaciones usar el precio del padre */
    $lookup_id = $product->is_type('variation') ? $product->get_parent_id() : $product_id;

    $raw    = get_post_meta( $lookup_id, '_silversea_city_prices', true );
    $prices = $raw ? json_decode( $raw, true ) : [];

    if ( ! empty( $prices[ $city ] ) && is_numeric( $prices[ $city ] ) ) {
        return (float) $prices[ $city ];
    }
    return null;
}

/**
 * Normaliza un código postal español a 5 dígitos.
 * Los CP españoles tienen siempre 5 dígitos; si el cliente omite el cero
 * inicial (ej. "8758") se completa a "08758".
 *
 * @return string CP de 5 dígitos, o '' si es inválido.
 */
function silversea_normalize_cp( $cp ) {
    $cp = preg_replace( '/\D/', '', (string) $cp );
    if ( strlen( $cp ) === 4 ) $cp = '0' . $cp;
    return strlen( $cp ) === 5 ? $cp : '';
}

/**
 * Busca la fila de tarifa para una ciudad + CP.
 * Maneja la inconsistencia de ceros a la izquierda: prueba el CP normalizado
 * (08758) y la forma sin cero inicial (8758), por si los datos se importaron
 * desde Excel/CSV que elimina el cero. NO hace match por prefijo aproximado.
 *
 * @return array|null Fila ARRAY_A o null si no existe tarifa exacta.
 */
function silversea_find_tarifa_row( $origin, $cp5 ) {
    global $wpdb;
    $table   = $wpdb->prefix . 'silversea_tarifas';
    $cp_alt  = ltrim( $cp5, '0' ); // forma sin cero(s) inicial(es)
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE ciudad_origen=%s AND cp_destino IN (%s,%s) LIMIT 1",
        $origin, $cp5, $cp_alt
    ), ARRAY_A );
}

/**
 * Resuelve el ID de un producto por su título exacto.
 * Usa una consulta directa para evitar la ambigüedad de wc_get_products('name'),
 * que filtra por slug y no por título.
 */
function silversea_get_product_id_by_title( $title ) {
    if ( ! $title ) return 0;
    global $wpdb;
    $id = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_title = %s AND post_type IN ('product','product_variation')
           AND post_status = 'publish'
         ORDER BY ID ASC LIMIT 1",
        $title
    ) );
    return (int) $id;
}

/**
 * Devuelve el WC_Product de un item guardado en _sq_products.
 * Prioriza product_id (cotizaciones nuevas); como fallback busca por título
 * exacto (cotizaciones anteriores que no guardaban el ID).
 *
 * @param array $item Item con 'product_id' (opcional) y 'name'.
 * @return WC_Product|null
 */
function silversea_resolve_quote_product( $item ) {
    if ( ! empty( $item['product_id'] ) ) {
        $p = wc_get_product( (int) $item['product_id'] );
        if ( $p ) return $p;
    }
    $id = silversea_get_product_id_by_title( $item['name'] ?? '' );
    return $id ? wc_get_product( $id ) : null;
}

/**
 * Nombre corto para mostrar en emails, reportes, etc.
 * Ej: 'madrid2' → 'Madrid 2', 'bilbao' → 'Bilbao'.
 */
function silversea_origin_label( $origin ) {
    $city = silversea_get_city( $origin );
    return $city ? $city['name'] : ucfirst( $origin );
}

/**
 * Detecta el tamaño (pies) de un producto WooCommerce.
 * Prioridad: taxonomía pa_tamano → padre si es variación → título del producto.
 */
function silversea_get_product_size( WC_Product $product ) {
    $terms = wc_get_product_terms( $product->get_id(), 'pa_tamano', ['fields' => 'slugs'] );
    if ( empty( $terms ) && $product->is_type('variation') ) {
        $terms = wc_get_product_terms( $product->get_parent_id(), 'pa_tamano', ['fields' => 'slugs'] );
    }
    if ( ! empty( $terms ) && preg_match( '/(\d+)/', $terms[0], $m ) ) {
        return (int) $m[1];
    }
    if ( preg_match( '/\b(10|20|40)\b/', $product->get_title(), $m2 ) ) {
        return (int) $m2[1];
    }
    return 20; // default
}

/**
 * Construye la lista óptima de camiones dado un array de items_detail.
 * Regla: camión = 4 unidades (40'=4u, 20'=2u, 10'=1u).
 * Los 40' van solos. Los 20' y 10' se combinan hasta llenar cada camión.
 */
function silversea_build_truck_list( $items_detail ) {
    $containers_40 = [];
    $remaining     = [];

    foreach ( $items_detail as $item ) {
        $units = intdiv( $item['size'], 10 );
        for ( $i = 0; $i < $item['quantity']; $i++ ) {
            $c = [ 'name' => $item['name'], 'size' => $item['size'], 'units' => $units ];
            if ( $item['size'] === 40 ) $containers_40[] = $c;
            else                        $remaining[]      = $c;
        }
    }

    $trucks = [];

    foreach ( $containers_40 as $c ) {
        $trucks[] = [ 'containers' => [ ['name' => $c['name'], 'size' => 40, 'qty' => 1] ], 'units' => 4 ];
    }

    $current       = [];
    $current_units = 0;

    foreach ( $remaining as $c ) {
        if ( $current_units + $c['units'] <= 4 ) {
            $current[]      = $c;
            $current_units += $c['units'];
        } else {
            if ( ! empty($current) )
                $trucks[] = [ 'containers' => silversea_consolidate_truck($current), 'units' => $current_units ];
            $current       = [$c];
            $current_units = $c['units'];
        }
    }
    if ( ! empty($current) )
        $trucks[] = [ 'containers' => silversea_consolidate_truck($current), 'units' => $current_units ];

    return $trucks;
}

function silversea_consolidate_truck( $containers ) {
    $map = [];
    foreach ( $containers as $c ) {
        $key = $c['size'] . '_' . $c['name'];
        if ( isset($map[$key]) ) $map[$key]['qty']++;
        else                     $map[$key] = [ 'name' => $c['name'], 'size' => $c['size'], 'qty' => 1 ];
    }
    return array_values( $map );
}

/**
 * Precio con descarga para un camión.
 * Camión lleno sin 40' (4 unidades de ≤20') → tarifa equivalente 40'.
 */
function silversea_calc_truck_con_descarga( $truck, $p_c20, $p_c40 ) {
    $units  = $truck['units'] ?? 0;
    $has_40 = false;
    foreach ( $truck['containers'] as $c ) {
        if ( $c['size'] === 40 ) { $has_40 = true; break; }
    }

    if ( ! $has_40 && $units >= 4 ) return $p_c40;

    $total = 0.0;
    foreach ( $truck['containers'] as $c ) {
        $total += ( $c['size'] === 40 ? $p_c40 : $p_c20 ) * $c['qty'];
    }
    return $total;
}

/**
 * Construye el breakdown de precios por camión y el total.
 *
 * @param array  $truck_list    Salida de silversea_build_truck_list()
 * @param string $transport     'sin' | 'con'
 * @param string $descarga_modo 'contenedor' | 'camion'
 * @param float  $p_sin         Precio sin descarga por camión
 * @param float  $p_c20         Precio con descarga contenedor 20'
 * @param float  $p_c40         Precio con descarga contenedor 40'
 * @param float  $p_extra_truck Costo extra camiones 2+ con descarga
 * @param bool   $is_demo       Añade etiqueta "(DEMO)" a los labels
 * @return array ['breakdown' => [...], 'total' => float]
 */
function silversea_build_truck_breakdown( $truck_list, $transport, $descarga_modo, $p_sin, $p_c20, $p_c40, $p_extra_truck, $is_demo = false ) {
    $breakdown  = [];
    $total      = 0.0;
    $demo_tag   = $is_demo ? ' (DEMO)' : '';

    if ( $transport === 'sin' || $descarga_modo === 'camion' ) {
        foreach ( $truck_list as $idx => $truck ) {
            $label       = 'Camión ' . ($idx + 1) . ': ' . silversea_truck_label($truck) . $demo_tag;
            $breakdown[] = [ 'label' => $label, 'price' => $p_sin ];
            $total      += $p_sin;
        }
    } else {
        foreach ( $truck_list as $idx => $truck ) {
            $lbl = silversea_truck_label($truck);
            if ( $idx === 0 ) {
                $price       = silversea_calc_truck_con_descarga( $truck, $p_c20, $p_c40 );
                $units       = $truck['units'] ?? 0;
                $has_40      = false;
                foreach ( $truck['containers'] as $_c ) { if ( $_c['size'] === 40 ) { $has_40 = true; break; } }
                $extra_label = ( ! $has_40 && $units >= 4 ) ? ' [equiv. 40ft]' : '';
                $breakdown[] = [ 'label' => "Camión 1 (con grúa): {$lbl}{$extra_label}{$demo_tag}", 'price' => $price ];
            } else {
                $price       = $p_sin + $p_extra_truck;
                $breakdown[] = [ 'label' => 'Camión ' . ($idx + 1) . " (sin grúa + extra): {$lbl}{$demo_tag}", 'price' => $price ];
            }
            $total += $price;
        }
    }

    return [ 'breakdown' => $breakdown, 'total' => round( $total, 2 ) ];
}

function silversea_truck_label( $truck ) {
    return implode( ' + ', array_map( function($c) {
        return "{$c['qty']}×{$c['size']}'";
    }, $truck['containers'] ) );
}

/**
 * Extrae items_detail desde raq_content.
 */
function silversea_raq_to_items_detail( $raq_content ) {
    $items_detail = [];
    $total_units  = 0;

    foreach ( $raq_content as $raq ) {
        $product_id = ! empty($raq['variation_id']) ? $raq['variation_id'] : $raq['product_id'];
        $_product   = wc_get_product( $product_id );
        if ( ! $_product ) continue;

        $quantity     = (int)( $raq['quantity'] ?? 1 );
        $size         = silversea_get_product_size( $_product );
        $units        = intdiv( $size, 10 );
        $total_units += $units * $quantity;
        $items_detail[] = [
            'name'     => $_product->get_title(),
            'size'     => $size,
            'quantity' => $quantity,
            'units'    => $units * $quantity,
        ];
    }

    return [ 'items' => $items_detail, 'total_units' => $total_units ];
}

/**
 * Calcula el costo de envío consolidado para todos los productos del quote cart.
 */
function silversea_calc_consolidated_shipping( $raq_content, $origin, $cp, $transport ) {

    if ( empty( $raq_content ) )
        return new WP_Error( 'empty_cart', 'No hay productos en la selección.' );

    if ( ! in_array( $origin, silversea_get_city_keys('delivery'), true ) )
        return new WP_Error( 'invalid_origin', 'Ciudad de origen inválida.' );

    $cp = silversea_normalize_cp( $cp );
    if ( ! $cp )
        return new WP_Error( 'invalid_cp', 'Código postal inválido. Debe tener 5 dígitos.' );

    if ( get_option( 'silversea_demo_mode', '0' ) === '1' )
        return silversea_calc_consolidated_demo( $raq_content, $origin, $cp, $transport );

    $row = silversea_find_tarifa_row( $origin, $cp );
    if ( ! $row )
        return new WP_Error( 'no_tarifa',
            'No encontramos tarifa para CP ' . $cp . ' desde ' . silversea_origin_label($origin) . '.'
        );

    $descarga_modo = get_option( 'silversea_descarga_modo', 'contenedor' );
    $p_sin         = (float) $row['precio_sin_descarga'];
    $p_c20         = (float) $row['precio_con_desc_20'];
    $p_c40         = (float) $row['precio_con_desc_40'];
    $p_extra_truck = (float) get_option( 'silversea_extra_truck_cost', '1350.00' );

    /* CP tiene tarifa sin descarga pero no tiene con descarga → precio a confirmar */
    if ( $transport === 'con' && $p_c20 == 0.0 && $p_c40 == 0.0 && $p_sin > 0 )
        return new WP_Error( 'price_pending', 'Con descarga no disponible para CP ' . $cp . '.' );

    $parsed     = silversea_raq_to_items_detail( $raq_content );
    $items_detail = $parsed['items'];
    $total_units  = $parsed['total_units'];

    if ( $total_units === 0 )
        return new WP_Error( 'no_units', 'No se pudieron calcular unidades de envío.' );

    $truck_list = silversea_build_truck_list( $items_detail );
    $result     = silversea_build_truck_breakdown( $truck_list, $transport, $descarga_modo, $p_sin, $p_c20, $p_c40, $p_extra_truck );

    return [
        'total'        => $result['total'],
        'trucks'       => count( $truck_list ),
        'total_units'  => $total_units,
        'transport'    => $transport,
        'transp_label' => $transport === 'sin' ? 'Sin descarga' : 'Con descarga',
        'origin'       => $origin,
        'cp'           => $cp,
        'destino'      => $row['municipio_destino'] ?: "CP {$cp}",
        'breakdown'    => $result['breakdown'],
        'items_detail' => $items_detail,
        'days'         => silversea_shipping_estimate_days( (int)$row['km'] ),
        'km'           => (int)$row['km'],
        'descarga_modo'=> $descarga_modo,
    ];
}

/**
 * Versión desde sesión WC — para templates de YITH.
 */
function silversea_calc_consolidated_from_session( $raq_content ) {
    $sc = WC()->session ? WC()->session->get( 'silversea_shipping_data' ) : null;
    if ( ! $sc || empty($sc['method']) ) return null;

    if ( $sc['method'] === 'pickup' ) {
        return [ 'method' => 'pickup', 'pickup_city' => $sc['pickup_city'] ?? '', 'total' => 0, 'trucks' => 0, 'days' => 5 ];
    }

    return silversea_calc_consolidated_shipping(
        $raq_content,
        $sc['origin']      ?? '',
        $sc['postal_code'] ?? '',
        $sc['transport']   ?? 'sin'
    );
}

/**
 * Cálculo demo — usa precios del panel admin.
 */
function silversea_calc_consolidated_demo( $raq_content, $origin, $cp, $transport ) {
    $descarga_modo = get_option( 'silversea_descarga_modo', 'contenedor' );
    $p_sin         = (float) get_option( 'silversea_demo_price_sin', '786.60' );
    $p_c20         = (float) get_option( 'silversea_demo_price_c20', '1644.00' );
    $p_c40         = (float) get_option( 'silversea_demo_price_c40', '1765.28' );
    $p_extra_truck = (float) get_option( 'silversea_extra_truck_cost', '1350.00' );

    $parsed       = silversea_raq_to_items_detail( $raq_content );
    $items_detail = $parsed['items'];
    $total_units  = $parsed['total_units'];

    if ( $total_units === 0 )
        return new WP_Error( 'no_units', 'No se pudieron calcular unidades.' );

    $truck_list = silversea_build_truck_list( $items_detail );
    $result     = silversea_build_truck_breakdown( $truck_list, $transport, $descarga_modo, $p_sin, $p_c20, $p_c40, $p_extra_truck, true );

    return [
        'total'        => $result['total'],
        'trucks'       => count( $truck_list ),
        'total_units'  => $total_units,
        'transport'    => $transport,
        'transp_label' => $transport === 'sin' ? 'Sin descarga' : 'Con descarga',
        'origin'       => $origin,
        'cp'           => $cp,
        'destino'      => "CP {$cp} (DEMO)",
        'breakdown'    => $result['breakdown'],
        'items_detail' => $items_detail,
        'days'         => 5,
        'km'           => 0,
        'descarga_modo'=> $descarga_modo,
        'is_demo'      => true,
    ];
}

/* ══════════════════════════════════════════════════════════════
   OPCIÓN 2 — Multi-envío por producto (desactivada por defecto)
   Activar con: add_filter('silversea_enable_multi_shipping', '__return_true');
══════════════════════════════════════════════════════════════ */
