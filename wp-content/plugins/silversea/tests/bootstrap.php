<?php
/**
 * Bootstrap de los tests: stubs mínimos de WordPress/WooCommerce para correr el código
 * REAL del plugin desde la línea de comandos, sin WordPress ni base de datos.
 *
 * Solo para CLI. No subir esta carpeta (tests/) al servidor.
 */

if ( PHP_SAPI !== 'cli' ) exit;

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );

/* ── Estado de los dobles (cada test lo resetea con ss_reset()) ─────────── */
function ss_reset() {
    $GLOBALS['SS_OPT']       = [];   // opciones de WP
    $GLOBALS['SS_TRANSIENT'] = [];
    $GLOBALS['SS_PRODUCTS']  = [];   // id => WC_Product
    $GLOBALS['SS_TERMS']     = [];   // id => [taxonomía => [slugs]]
    $GLOBALS['SS_META']      = [];   // id => [meta_key => valor]
    $GLOBALS['SS_TARIFA']    = null; // fila de tarifa que devuelve $wpdb->get_row()
    $GLOBALS['SS_SCREEN']    = null;
    $_POST = [];
}
ss_reset();

/* ── Hooks / registro: no-ops (solo se necesita que los archivos carguen) ── */
foreach ( [ 'add_action', 'add_filter', 'add_shortcode', 'add_submenu_page', 'add_menu_page',
            'register_post_type', 'register_activation_hook', 'add_meta_box',
            'wp_enqueue_script', 'wp_enqueue_style', 'wp_localize_script' ] as $fn ) {
    eval( "function $fn(...\$a) { return true; }" );
}
function plugin_dir_url( $f )  { return 'http://localhost/wp-content/plugins/silversea/'; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }

/* ── Opciones, transients ──────────────────────────────────────────────── */
function get_option( $k, $d = false )   { return array_key_exists( $k, $GLOBALS['SS_OPT'] ) ? $GLOBALS['SS_OPT'][ $k ] : $d; }
function get_transient( $k )            { return $GLOBALS['SS_TRANSIENT'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ){ $GLOBALS['SS_TRANSIENT'][ $k ] = $v; return true; }
function delete_transient( $k )         { unset( $GLOBALS['SS_TRANSIENT'][ $k ] ); return true; }

/* ── Utilidades de WP ──────────────────────────────────────────────────── */
function sanitize_key( $k )    { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
function esc_html( $s )        { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s )         { return (string) $s; }
function home_url( $p = '' )   { return 'https://example.test' . $p; }
function current_user_can( $c ){ return true; }
function get_current_screen()  { return $GLOBALS['SS_SCREEN']; }
function check_ajax_referer( ...$a ) { return true; }
function get_edit_post_link( $id, $ctx = '' ) { return 'http://localhost/wp-admin/post.php?post=' . $id . '&action=edit'; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['SS_META'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $val ) { $GLOBALS['SS_META'][ $id ][ $key ] = $val; return true; }

class WP_Error { public $code; public $msg;
    function __construct( $code = '', $msg = '' ) { $this->code = $code; $this->msg = $msg; }
    function get_error_code()    { return $this->code; }
    function get_error_message() { return $this->msg; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

/* wp_send_json_*: en WP terminan la petición; acá lanzan una excepción con el resultado. */
class SSJson extends Exception { public $ok; public $data; }
function wp_send_json_success( $d = null ) { $e = new SSJson(); $e->ok = true;  $e->data = $d; throw $e; }
function wp_send_json_error( $d = null, $code = 0 ) { $e = new SSJson(); $e->ok = false; $e->data = $d; throw $e; }

/** Ejecuta un handler AJAX y devuelve ['ok' => bool, 'data' => mixed]. */
function ss_call_ajax( $handler ) {
    try { $handler(); } catch ( SSJson $r ) { return [ 'ok' => $r->ok, 'data' => $r->data ]; }
    return [ 'ok' => null, 'data' => null ];
}

/* ── $wpdb falso: solo devuelve la tarifa configurada en el test ───────── */
class SSFakeDB {
    public $prefix = 'wp_';
    function prepare( $q, ...$a ) { return $q; }
    function get_row( $q, $t = null ) { return $GLOBALS['SS_TARIFA']; }
    function get_var( $q ) { return 0; }
}
$wpdb = new SSFakeDB();

/* ── WooCommerce: producto falso ───────────────────────────────────────── */
class WC_Product {
    private $id; private $title; private $parent;
    function __construct( $id, $title, $parent = 0 ) { $this->id = $id; $this->title = $title; $this->parent = $parent; }
    function get_id()        { return $this->id; }
    function get_title()     { return $this->title; }
    function get_parent_id() { return $this->parent; }
    function is_type( $t )   { return $t === 'variation' && $this->parent > 0; }
}

/** Crea y registra un producto falso. $terms = ['pa_tamano' => ['10-pies'], ...]. $parent > 0 => variación. */
function ss_product( $id, $title, $terms = [], $parent = 0, $meta = [] ) {
    $GLOBALS['SS_PRODUCTS'][ $id ] = new WC_Product( $id, $title, $parent );
    $GLOBALS['SS_TERMS'][ $id ]    = $terms;
    if ( $meta ) $GLOBALS['SS_META'][ $id ] = $meta;
    return $GLOBALS['SS_PRODUCTS'][ $id ];
}
function wc_get_product( $id )  { return $GLOBALS['SS_PRODUCTS'][ (int) $id ] ?? false; }
function wc_get_product_terms( $id, $tax, $args = [] ) { return $GLOBALS['SS_TERMS'][ $id ][ $tax ] ?? []; }
function get_posts( $args ) {
    $ids = [];
    foreach ( $GLOBALS['SS_PRODUCTS'] as $id => $p ) if ( ! $p->get_parent_id() ) $ids[] = $id; // solo padres
    return $ids;
}

/* ── Cargar el plugin real (misma cadena que cotizador.php) ────────────── */
require dirname( __DIR__ ) . '/includes/shipping-calculator.php'; // incluye texts, session, quote-calc, salesforce, quote-pages
require dirname( __DIR__ ) . '/includes/shipping-size-audit.php';
