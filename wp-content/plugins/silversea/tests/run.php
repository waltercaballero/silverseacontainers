<?php
/**
 * Tests del plugin Silversea — corren el código REAL, sin WordPress ni base de datos.
 *
 *     php tests/run.php
 *
 * Sale con código 1 si algún test falla. Ver README, sección "Tests".
 *
 * Cubre las reglas que ya costaron un problema real en producción:
 *  1. Reglas de carga y precio de camiones (incl. contenedores de 10').
 *  2. El cotizador de un solo producto da lo mismo que el consolidado (+ modo demo).
 *  3. Detección del tamaño de un producto y su aviso en el admin.
 *  4. Firma del carrito (anti-duplicados).
 *  5. Payload a Salesforce: picklists restringidos (Customer Type, Container Type, Grade).
 */

require __DIR__ . '/bootstrap.php';

/* ── Mini framework ────────────────────────────────────────────────────── */
$T = [ 'pass' => 0, 'fail' => 0, 'section' => '' ];

function section( $name ) { global $T; $T['section'] = $name; echo "\n== $name ==\n"; }

function check( $name, $cond, $detail = '' ) {
    global $T;
    if ( $cond ) { $T['pass']++; echo "  ok    $name\n"; }
    else         { $T['fail']++; echo "  FALLA $name" . ( $detail !== '' ? "  -> $detail" : '' ) . "\n"; }
}

/** Compara valores; los números se comparan con tolerancia de medio centavo. */
function same( $name, $actual, $expected ) {
    $ok = ( is_numeric( $actual ) && is_numeric( $expected ) )
        ? abs( (float) $actual - (float) $expected ) < 0.005
        : $actual === $expected;
    check( $name, $ok, 'esperado ' . json_encode( $expected, JSON_UNESCAPED_UNICODE ) . ', obtenido ' . json_encode( $actual, JSON_UNESCAPED_UNICODE ) );
}

/* ══════════════════════════════════════════════════════════════════════
   1. CAMIONES — reglas de carga y de precio
   Tarifas de prueba: sin descarga 786,60 · con descarga 20' 1.644 · 40' 1.765,28 · extra 1.350
══════════════════════════════════════════════════════════════════════ */
section( '1. Camiones: reglas de carga y precio' );

const P_SIN = 786.60; const P_C20 = 1644.00; const P_C40 = 1765.28; const P_EXTRA = 1350.00;

function ss_trucks( $items ) {
    $detail = [];
    foreach ( $items as $it ) $detail[] = [ 'name' => $it[2] ?? "{$it[0]}'", 'size' => $it[0], 'quantity' => $it[1] ];
    return silversea_build_truck_list( $detail );
}
function ss_total( $trucks, $transport, $modo = 'contenedor', $p_sin = P_SIN ) {
    return silversea_build_truck_breakdown( $trucks, $transport, $modo, $p_sin, P_C20, P_C40, P_EXTRA )['total'];
}

/* Cada caso: [items], composición de camiones, total sin descarga, con descarga, con descarga en modo "camión" */
$cases = [
    // Solo 10': hasta 4 por camión. Escalón con descarga: 1-2 u = tarifa 20', 3-4 u = tarifa 40'
    '1×10'           => [ [[10,1]],            ["1×10'"],                        786.60,  1644.00, 786.60 ],
    '2×10'           => [ [[10,2]],            ["2×10'"],                        786.60,  1644.00, 786.60 ],
    '3×10'           => [ [[10,3]],            ["3×10'"],                        786.60,  1765.28, 786.60 ],
    '4×10'           => [ [[10,4]],            ["4×10'"],                        786.60,  1765.28, 786.60 ],
    '5×10'           => [ [[10,5]],            ["4×10'", "1×10'"],              1573.20,  3901.88, 1573.20 ],
    '8×10'           => [ [[10,8]],            ["4×10'", "4×10'"],              1573.20,  3901.88, 1573.20 ],
    '9×10'           => [ [[10,9]],            ["4×10'", "4×10'", "1×10'"],     2359.80,  6038.48, 2359.80 ],
    '10 nuevo + 10 usado (2 productos)' => [ [[10,1,"10' Pies"],[10,1,"10' Pies Usado"]], ["1×10' + 1×10'"], 786.60, 1644.00, 786.60 ],
    // 20' y 40': NO deben cambiar
    '1×20'           => [ [[20,1]],            ["1×20'"],                        786.60,  1644.00, 786.60 ],
    '2×20'           => [ [[20,2]],            ["2×20'"],                        786.60,  1765.28, 786.60 ],
    '3×20'           => [ [[20,3]],            ["2×20'", "1×20'"],              1573.20,  3901.88, 1573.20 ],
    '1×40'           => [ [[40,1]],            ["1×40'"],                        786.60,  1765.28, 786.60 ],
    '2×40'           => [ [[40,2]],            ["1×40'", "1×40'"],              1573.20,  3901.88, 1573.20 ],
    '20 + 40'        => [ [[20,1],[40,1]],     ["1×40'", "1×20'"],              1573.20,  3901.88, 1573.20 ],
    // Un 10' junto a otro tamaño: camión aparte (caso del presupuesto #8782)
    '20 + 10'        => [ [[20,1],[10,1]],     ["1×20'", "1×10'"],              1573.20,  3780.60, 1573.20 ],
    '10 + 20 (orden inverso en el carrito)' => [ [[10,1],[20,1]], ["1×20'", "1×10'"], 1573.20, 3780.60, 1573.20 ],
    '40 + 10'        => [ [[40,1],[10,1]],     ["1×40'", "1×10'"],              1573.20,  3901.88, 1573.20 ],
    '2×20 + 2×10'    => [ [[20,2],[10,2]],     ["2×20'", "2×10'"],              1573.20,  3901.88, 1573.20 ],
    '3×20 + 10'      => [ [[20,3],[10,1]],     ["2×20'", "1×20'", "1×10'"],     2359.80,  6038.48, 2359.80 ],
    '20 + 3×10'      => [ [[20,1],[10,3]],     ["1×20'", "3×10'"],              1573.20,  3780.60, 1573.20 ],
    '40 + 20 + 10'   => [ [[40,1],[20,1],[10,1]], ["1×40'", "1×20'", "1×10'"],  2359.80,  6038.48, 2359.80 ],
];
foreach ( $cases as $label => [ $items, $comp, $sin, $con, $camion ] ) {
    $trucks = ss_trucks( $items );
    same( "$label — composición",          array_map( 'silversea_truck_label', $trucks ), $comp );
    same( "$label — sin descarga",         ss_total( $trucks, 'sin' ), $sin );
    same( "$label — con descarga",         ss_total( $trucks, 'con' ), $con );
    same( "$label — con descarga, modo camión", ss_total( $trucks, 'con', 'camion' ), $camion );
}

/* El camión 1 (con grúa) es el del contenedor de mayor tamaño; los 10' van después y cobran como camión 2 */
$bd = silversea_build_truck_breakdown( ss_trucks( [[20,1],[10,1]] ), 'con', 'contenedor', P_SIN, P_C20, P_C40, P_EXTRA )['breakdown'];
check( '20 + 10: el camión 1 (con grúa) es el 20\'', strpos( $bd[0]['label'], 'Camión 1 (con grúa): 1×20\'' ) === 0, $bd[0]['label'] );
check( '20 + 10: el 10\' viaja en el camión 2, sin grúa + extra', strpos( $bd[1]['label'], 'Camión 2 (sin grúa + extra): 1×10\'' ) === 0, $bd[1]['label'] );
same( '20 + 10: el camión 2 cobra sin descarga + extra', $bd[1]['price'], P_SIN + P_EXTRA );

/* El escalón de los 10' aplica solo al camión 1: el camión 2 cobra sin descarga + extra sin importar la carga */
$bd = silversea_build_truck_breakdown( ss_trucks( [[10,7]] ), 'con', 'contenedor', P_SIN, P_C20, P_C40, P_EXTRA )['breakdown'];
same( '7×10: camión 1 (4 u) = tarifa 40\'',          $bd[0]['price'], P_C40 );
same( '7×10: camión 2 (3 u) = sin descarga + extra', $bd[1]['price'], P_SIN + P_EXTRA );

/* Etiquetas de equivalencia en el desglose */
$bd = silversea_build_truck_breakdown( ss_trucks( [[10,2]] ), 'con', 'contenedor', P_SIN, P_C20, P_C40, P_EXTRA )['breakdown'];
check( '2×10: etiqueta [equiv. 20ft]', strpos( $bd[0]['label'], '[equiv. 20ft]' ) !== false, $bd[0]['label'] );
$bd = silversea_build_truck_breakdown( ss_trucks( [[10,3]] ), 'con', 'contenedor', P_SIN, P_C20, P_C40, P_EXTRA )['breakdown'];
check( '3×10: etiqueta [equiv. 40ft]', strpos( $bd[0]['label'], '[equiv. 40ft]' ) !== false, $bd[0]['label'] );

/* CP sin tarifa "sin descarga" (p_sin = 0): los camiones 2+ usan su propia tarifa con descarga */
same( '20 + 3×10 con p_sin=0 → 20\' (c20) + 3×10\' (c40)', ss_total( ss_trucks( [[20,1],[10,3]] ), 'con', 'contenedor', 0.0 ), P_C20 + P_C40 );
same( '5×10 con p_sin=0 → 4×10\' (c40) + 1×10\' (c20)',    ss_total( ss_trucks( [[10,5]] ),         'con', 'contenedor', 0.0 ), P_C40 + P_C20 );

/* ══════════════════════════════════════════════════════════════════════
   2. COTIZADOR DE UN SOLO PRODUCTO (ficha de producto) + modo demo
══════════════════════════════════════════════════════════════════════ */
section( '2. Cotizador de un solo producto' );

function ss_single( $size, $qty, $transport = 'con', $cp = '28001' ) {
    $_POST = [ 'method' => 'delivery', 'transport' => $transport, 'origin' => 'madrid',
               'postal_code' => $cp, 'product_size' => (string) $size, 'quantity' => (string) $qty ];
    return ss_call_ajax( 'silversea_shipping_ajax_calc' );
}
function ss_set_tarifa( $sin = P_SIN, $c20 = P_C20, $c40 = P_C40 ) {
    $GLOBALS['SS_TARIFA'] = [ 'cp_destino' => '28001', 'municipio_destino' => 'Madrid', 'km' => 100,
        'precio_sin_descarga' => $sin, 'precio_con_desc_20' => $c20, 'precio_con_desc_40' => $c40 ];
}

ss_reset(); ss_set_tarifa();
$r = ss_single( 10, 2 ); same( '10×2 con descarga = 1 transporte de 20\'', $r['data']['price'], 1644.00 );
$r = ss_single( 10, 3 ); same( '10×3 con descarga = 1 transporte de 40\'', $r['data']['price'], 1765.28 );
$r = ss_single( 10, 5 ); same( '10×5 con descarga = 3.901,88 en 2 camiones', $r['data']['price'], 3901.88 ); same( '10×5 camiones', $r['data']['trucks'], 2 );
$r = ss_single( 20, 1, 'sin' ); same( '20×1 sin descarga', $r['data']['price'], 786.60 );
$r = ss_single( 40, 2, 'con' ); same( '40×2 con descarga', $r['data']['price'], 3901.88 );

/* Invariante: la ficha de producto cotiza EXACTAMENTE lo mismo que el cálculo compartido (no hay copia propia) */
$bad = [];
foreach ( [ 10, 20, 40 ] as $size ) foreach ( range( 1, 9 ) as $qty ) foreach ( [ [ 'sin', 'contenedor' ], [ 'con', 'contenedor' ], [ 'con', 'camion' ] ] as [ $tr, $modo ] ) {
    ss_reset(); ss_set_tarifa(); $GLOBALS['SS_OPT']['silversea_descarga_modo'] = $modo;
    $r        = ss_single( $size, $qty, $tr );
    $trucks   = ss_trucks( [ [ $size, $qty ] ] );
    $expected = silversea_build_truck_breakdown( $trucks, $tr, $modo, P_SIN, P_C20, P_C40, P_EXTRA )['total'];
    if ( abs( $r['data']['price'] - $expected ) > 0.005 || $r['data']['trucks'] !== count( $trucks ) ) $bad[] = "{$size}×{$qty} $tr/$modo";
}
check( 'ficha de producto == cálculo compartido (81 combinaciones tamaño×cantidad×modo)', empty( $bad ), implode( ', ', array_slice( $bad, 0, 5 ) ) );

/* Cantidad absurda (endpoint público): se limita en vez de agotar memoria/CPU */
ss_reset(); ss_set_tarifa();
$t0 = microtime( true ); $r = ss_single( 10, 100000000 );
check( 'cantidad 100.000.000 → responde acotada y rápido (tope 1000)', $r['ok'] === true && $r['data']['trucks'] === 250 && ( microtime( true ) - $t0 ) < 2, 'trucks=' . ( $r['data']['trucks'] ?? '?' ) );

/* Casos de error / especiales */
ss_reset(); $GLOBALS['SS_TARIFA'] = null;
$r = ss_single( 20, 1 ); check( 'CP sin tarifa → flujo no_tarifa', ! empty( $r['data']['no_tarifa'] ) );
ss_reset(); ss_set_tarifa( 786.60, 0.0, 0.0 );
$r = ss_single( 20, 1, 'con' ); check( 'CP sin tarifa con descarga → precio a confirmar', ! empty( $r['data']['price_pending'] ) );
$r = ss_single( 20, 1, 'sin' ); same( 'CP sin tarifa con descarga: sin descarga sí cotiza', $r['data']['price'], 786.60 );
ss_reset(); ss_set_tarifa();
$r = ss_single( 20, 1, 'con', '12' ); check( 'CP inválido → error', $r['ok'] === false );

/* Modo demo: precios del panel admin, sin tabla de tarifas, con etiqueta (DEMO) */
ss_reset(); $GLOBALS['SS_TARIFA'] = null;
$GLOBALS['SS_OPT'] += [ 'silversea_demo_mode' => '1', 'silversea_demo_price_sin' => '100.00', 'silversea_demo_price_c20' => '200.00', 'silversea_demo_price_c40' => '300.00', 'silversea_extra_truck_cost' => '50.00' ];
$r = ss_single( 10, 3, 'con' );
same( 'demo: 3×10 con descarga usa el precio 40\' de demo', $r['data']['price'], 300.00 );
check( 'demo: no consulta la tabla de tarifas', empty( $r['data']['no_tarifa'] ) );
check( 'demo: el desglose lleva la marca (DEMO)', strpos( $r['data']['breakdown'][0]['label'], '(DEMO)' ) !== false, $r['data']['breakdown'][0]['label'] );
$r = ss_single( 10, 5, 'con' );
same( 'demo: 5×10 con descarga = 300 + (100 + 50 extra)', $r['data']['price'], 450.00 );

/* ══════════════════════════════════════════════════════════════════════
   3. TAMAÑO DEL PRODUCTO + AVISO EN EL ADMIN
══════════════════════════════════════════════════════════════════════ */
section( '3. Tamaño del producto y aviso del admin' );

ss_reset();
$p10  = ss_product( 1, "10' Pies",           [ 'pa_tamano' => [ '10-pies' ] ] );
$pt   = ss_product( 2, "Contenedor 40 pies HC" );                        // sin atributo, 40 en el título
$pd   = ss_product( 3, 'Contenedor marítimo' );                          // sin atributo ni número → defecto
$par  = ss_product( 4, "40' Pies High Cube", [ 'pa_tamano' => [ '40-pies' ] ] );
$var  = ss_product( 5, "40' Pies High Cube - RAL 5010", [], 4 );         // variación: el atributo está en el padre

same( 'atributo "10-pies" → 10 (atributo)', silversea_get_product_size_info( $p10 ), [ 'size' => 10, 'source' => 'attribute' ] );
same( 'sin atributo, "40" en el título → 40 (título)', silversea_get_product_size_info( $pt ), [ 'size' => 40, 'source' => 'title' ] );
same( 'sin atributo ni número → 20 (defecto)', silversea_get_product_size_info( $pd ), [ 'size' => 20, 'source' => 'default' ] );
same( 'variación hereda el atributo del padre', silversea_get_product_size_info( $var ), [ 'size' => 40, 'source' => 'attribute' ] );
same( 'silversea_get_product_size devuelve el entero', silversea_get_product_size( $p10 ), 10 );

/* Escaneo: solo los de "defecto" (los de título son informativos, no avisan) */
$problems = silversea_size_audit_scan();
same( 'el escaneo marca solo el producto sin tamaño detectable', array_column( $problems, 'id' ), [ 3 ] );
check( 'el escaneo no recorre variaciones', ! in_array( 5, array_column( $problems, 'id' ), true ) );

/* Caché */
silversea_size_audit_flush();
silversea_size_audit_get();
check( 'el resultado se cachea en un transient', is_array( get_transient( silversea_size_audit_cache_key() ) ) );
silversea_size_audit_flush();
check( 'flush borra la caché', get_transient( silversea_size_audit_cache_key() ) === false );

/* Aviso */
function ss_render( $fn, ...$args ) { ob_start(); $fn( ...$args ); return ob_get_clean(); }
$GLOBALS['SS_SCREEN'] = (object) [ 'id' => 'edit-product' ];
$html = ss_render( 'silversea_size_audit_notice' );
check( 'aviso en el listado de productos: menciona el producto', strpos( $html, 'Contenedor marítimo' ) !== false && strpos( $html, '1 producto sin tamaño detectable' ) !== false );
$GLOBALS['SS_SCREEN'] = (object) [ 'id' => 'toplevel_page_silversea-cotizador' ];
check( 'aviso también en el panel Cotizador', strpos( ss_render( 'silversea_size_audit_notice' ), 'sin tamaño detectable' ) !== false );
$GLOBALS['SS_SCREEN'] = (object) [ 'id' => 'dashboard' ];
check( 'sin aviso en otras pantallas', ss_render( 'silversea_size_audit_notice' ) === '' );
$GLOBALS['SS_SCREEN'] = (object) [ 'id' => 'edit-product' ];
silversea_size_audit_flush(); unset( $GLOBALS['SS_PRODUCTS'][3] );       // arreglan el producto → desaparece
check( 'sin productos problemáticos → sin aviso', ss_render( 'silversea_size_audit_notice' ) === '' );

/* Columna */
check( 'columna: atributo',    strpos( ss_render( 'silversea_size_audit_column_content', 'silversea_ship_size', 1 ), 'atributo' ) !== false );
check( 'columna: por título',  strpos( ss_render( 'silversea_size_audit_column_content', 'silversea_ship_size', 2 ), 'por título' ) !== false );
$GLOBALS['SS_PRODUCTS'][3] = $pd;
check( 'columna: no detectado', strpos( ss_render( 'silversea_size_audit_column_content', 'silversea_ship_size', 3 ), 'no detectado' ) !== false );
check( 'columna: ignora otras columnas', ss_render( 'silversea_size_audit_column_content', 'price', 1 ) === '' );
same( 'columna: se inserta tras "SKU / Color RAL"', array_keys( silversea_size_audit_columns( [ 'name' => 'N', 'silversea_sku_color' => 'S', 'price' => 'P' ] ) ), [ 'name', 'silversea_sku_color', 'silversea_ship_size', 'price' ] );

/* ══════════════════════════════════════════════════════════════════════
   4. FIRMA DEL CARRITO (anti-duplicados)
══════════════════════════════════════════════════════════════════════ */
section( '4. Firma del carrito (anti-duplicados)' );

$cart = [ [ 'product_id' => 10, 'qty' => 2, 'addons' => [ 'Pintura' ] ], [ 'product_id' => 20, 'qty' => 1 ] ];
same( 'el orden de los productos no cambia la firma',
    silversea_quote_product_signature( [ $cart[1], $cart[0] ] ), silversea_quote_product_signature( $cart ) );
check( 'otra cantidad → otra firma',
    silversea_quote_product_signature( [ [ 'product_id' => 10, 'qty' => 3, 'addons' => [ 'Pintura' ] ], $cart[1] ] ) !== silversea_quote_product_signature( $cart ) );
check( 'otro addon → otra firma',
    silversea_quote_product_signature( [ [ 'product_id' => 10, 'qty' => 2, 'addons' => [ 'Pintura', 'Logo' ] ], $cart[1] ] ) !== silversea_quote_product_signature( $cart ) );
check( 'el orden de los addons no cambia la firma',
    silversea_quote_product_signature( [ [ 'product_id' => 1, 'qty' => 1, 'addons' => [ 'A', 'B' ] ] ] ) === silversea_quote_product_signature( [ [ 'product_id' => 1, 'qty' => 1, 'addons' => [ 'B', 'A' ] ] ] ) );
same( 'sin email nunca se considera duplicado', silversea_find_recent_duplicate_quote( '', $cart ), 0 );

/* ══════════════════════════════════════════════════════════════════════
   5. PAYLOAD A SALESFORCE — picklists restringidos
   Si un valor no coincide letra por letra con el picklist de Salesforce, Salesforce
   descarta el lead ENTERO y Web-to-Lead igual responde 200 (así se perdieron leads).
══════════════════════════════════════════════════════════════════════ */
section( '5. Payload a Salesforce' );

ss_reset();
$sf = function ( $prod_id, $title, $terms, $sf_type ) { return ss_product( $prod_id, $title, $terms, 0, [ 'silversea_sf_container_type' => $sf_type ] ); };
$sf( 101, "40' Pies Refrigerado",  [ 'pa_condicion' => [ 'nuevo' ] ], "40' Reefer" );
$sf( 102, "20' Pies Estándar Usado", [ 'pa_condicion' => [ 'usado' ] ], "20' Dry Van" );
$sf( 103, "40' HC Full Open Side", [ 'pa_condicion' => [ 'nuevo' ] ], "40' HC Open Side" );
ss_product( 104, "40' Pies High Cube", [ 'pa_condicion' => [ 'nuevo' ] ], 0, [ 'silversea_sf_container_type' => "40' High Cube" ] );
ss_product( 105, "40' Pies High Cube - RAL 5010", [], 104 );             // variación sin estado propio: se toma del padre

$base = [ 'name' => 'Acme Logistics SL', 'type' => 'empresa', 'email' => 'a@acme.test', 'prefix' => '+34', 'phone' => '600123456',
          'city' => 'Madrid', 'postal' => '28001', 'message' => '', 'country' => 'Spain', 'form_lang' => 'ES' ];
$item = function ( $pid, $name, $qty = 1, $cond = '' ) { return [ 'product_id' => $pid, 'name' => $name, 'qty' => $qty, 'condition' => $cond ]; };

$pl = silversea_sf_build_payload( $base, [ $item( 101, "40' Pies Refrigerado", 1, 'Nuevo' ) ], 7 );
same( 'empresa → Customer Type "Company"', $pl['00NUm00000WqX8j'], 'Company' );
same( 'reefer 40\' → "40\' Refeer" (así está escrito en el picklist de SF)', $pl['00N8a00000FXdRZ'], "40' Refeer" );
same( 'nuevo → Grade "New"', $pl['00N8a00000FXdRe'], 'New' );
same( 'empresa: company y last_name = razón social', [ $pl['company'], $pl['last_name'], $pl['first_name'] ], [ 'Acme Logistics SL', 'Acme Logistics SL', '' ] );
same( 'el ID del presupuesto viaja en 00NUm00000UecA9', $pl['00NUm00000UecA9'], '7' );
same( 'país en el campo estándar y en el picklist', [ $pl['country'], $pl['00NUm00000G445R'] ], [ 'Spain', 'Spain' ] );
same( 'Business Type fijo SILVERSEA (los dos campos)', [ $pl['00NUm00000Ue4V3'], $pl['00NUm00000Ue4V4'] ], [ 'SILVERSEA', 'SILVERSEA' ] );

$pl = silversea_sf_build_payload( [ 'name' => 'Juan Perez Lopez', 'type' => 'particular' ] + $base, [ $item( 102, "20' Pies Estándar Usado", 2, 'Usado' ) ], 8 );
same( 'particular → Customer Type "Individual"', $pl['00NUm00000WqX8j'], 'Individual' );
same( 'particular: nombre y apellidos separados, sin company', [ $pl['first_name'], $pl['last_name'], $pl['company'] ], [ 'Juan', 'Perez Lopez', '' ] );
same( 'usado → Grade "Cargo Worthy"', $pl['00N8a00000FXdRe'], 'Cargo Worthy' );
same( 'Quantity = suma de unidades', $pl['00N8a00000FXdRo'], '2' );

$pl = silversea_sf_build_payload( $base, [ $item( 103, "40' HC Full Open Side", 1, 'Nuevo' ) ], 9 );
same( 'open side 40\' → "40\' HC Open Side," (con coma, así está en SF)', $pl['00N8a00000FXdRZ'], "40' HC Open Side," );

$pl = silversea_sf_build_payload( $base, [ $item( 101, "40' Pies Refrigerado", 1, 'Nuevo' ), $item( 102, "20' Pies Estándar Usado", 1, 'Usado' ) ], 10 );
same( 'tipos distintos → Container Type vacío', $pl['00N8a00000FXdRZ'], '' );
same( 'nuevo + usado → Grade vacío', $pl['00N8a00000FXdRe'], '' );
same( 'Quantity sigue siendo la suma con tipos mezclados', $pl['00N8a00000FXdRo'], '2' );

$pl = silversea_sf_build_payload( $base, [ $item( 105, "40' Pies High Cube - RAL 5010", 1, '' ) ], 11 );
same( 'variación sin estado propio: Grade sale del padre (pa_condicion)', $pl['00N8a00000FXdRe'], 'New' );

same( 'los valores de container sin traducción pasan tal cual', silversea_sf_container_value( "20' Dry Van" ), "20' Dry Van" );

/* ── Resumen ───────────────────────────────────────────────────────────── */
echo "\n──────────────────────────────────────\n";
echo "Pasaron: {$T['pass']}   Fallaron: {$T['fail']}\n";
exit( $T['fail'] > 0 ? 1 : 0 );
