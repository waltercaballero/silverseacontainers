<?php
/**
 * Shipping Calculator – Calculador de envío de contenedores
 * @version 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'SILVERSEA_PLUGIN_FILE' ) )
    define( 'SILVERSEA_PLUGIN_FILE', dirname(__DIR__) . '/silversea.php' );
if ( ! defined( 'SILVERSEA_PLUGIN_URL' ) )
    define( 'SILVERSEA_PLUGIN_URL', plugin_dir_url( SILVERSEA_PLUGIN_FILE ) );
if ( ! defined( 'SILVERSEA_PLUGIN_DIR' ) )
    define( 'SILVERSEA_PLUGIN_DIR', plugin_dir_path( SILVERSEA_PLUGIN_FILE ) );

/* Versión única para cache-busting de todos los assets (JS/CSS).
   Subir este número cuando se modifique cualquier archivo de assets. */
if ( ! defined( 'SILVERSEA_VERSION' ) )
    define( 'SILVERSEA_VERSION', '2.3.0' );

require_once __DIR__ . '/texts.php';

/* ══ 1. TABLA ══════════════════════════════════════════════ */

register_activation_hook( SILVERSEA_PLUGIN_FILE, 'silversea_shipping_create_table' );

function silversea_shipping_create_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'silversea_tarifas';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ciudad_origen         VARCHAR(20)     NOT NULL,
        cp_destino            VARCHAR(10)     NOT NULL,
        municipio_destino     VARCHAR(120)    NOT NULL DEFAULT '',
        km                    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        precio_sin_descarga   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
        precio_con_desc_20    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
        precio_con_desc_40    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ciudad_cp (ciudad_origen, cp_destino),
        KEY idx_cp (cp_destino)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

add_action( 'plugins_loaded', function () {
    global $wpdb;
    $table = $wpdb->prefix . 'silversea_tarifas';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table )
        silversea_shipping_create_table();
} );

/* ══ 2. SETTINGS ══════════════════════════════════════════ */

add_action( 'admin_init', function () {
    foreach ( [
        'silversea_demo_mode',
        'silversea_descarga_modo',
        'silversea_demo_price_sin',
        'silversea_demo_price_c20',
        'silversea_demo_price_c40',
        'silversea_extra_truck_cost',
        'silversea_show_front',
        'silversea_admin_email',
        'silversea_sales_email',
        'silversea_email_send_client',
        'silversea_email_show_prices',
        'silversea_require_quote',
        'silversea_show_consolidated',
        'silversea_catalog_price_mode',
        'silversea_catalog_price_default_city',
    ] as $key ) register_setting( 'silversea_settings', $key );
} );

/* ══ 3. MENÚ ADMIN ════════════════════════════════════════ */

add_action( 'admin_menu', 'silversea_shipping_admin_menu' );

function silversea_shipping_admin_menu() {
    /* Menú top-level */
    add_menu_page(
        'Silversea – Cotizador',
        'Cotizador',
        'manage_woocommerce',
        'silversea-cotizador',
        'silversea_shipping_admin_page',
        'dashicons-money-alt',
        56
    );
    /* Primer submenú (mismo slug = renombra el ítem duplicado que WP agrega) */
    add_submenu_page(
        'silversea-cotizador',
        'Silversea – Configuración',
        'Configuración',
        'manage_woocommerce',
        'silversea-cotizador',
        'silversea_shipping_admin_page'
    );
    /* Precios por ciudad */
    add_submenu_page(
        'silversea-cotizador',
        'Precios por Ciudad',
        'Precios Ciudad',
        'manage_woocommerce',
        'silversea-city-prices',
        'silversea_render_city_prices_page'
    );
}

/* ══ 4. PÁGINA ADMIN ══════════════════════════════════════ */

function silversea_shipping_admin_page() {
    global $wpdb;
    $table   = $wpdb->prefix . 'silversea_tarifas';
    $message = ''; $error = '';

    if ( isset($_POST['silversea_cities_nonce'])
         && wp_verify_nonce($_POST['silversea_cities_nonce'], 'silversea_save_cities') ) {
        $submitted_modes  = $_POST['city_modes']         ?? [];
        $submitted_labels = $_POST['city_display_names'] ?? [];
        $updated_cities   = [];
        foreach ( silversea_get_cities() as $current ) {
            $key          = $current['key'];
            $raw          = isset($submitted_modes[$key]) && is_array($submitted_modes[$key])
                            ? $submitted_modes[$key] : [];
            $modes        = array_values( array_intersect( ['delivery','pickup'], $raw ) );
            $display_name = sanitize_text_field( $submitted_labels[$key] ?? '' );
            $updated_cities[] = array_merge( $current, [
                'modes'        => $modes,
                'display_name' => $display_name,
            ] );
        }
        update_option( 'silversea_cities_config', $updated_cities );
        $message = 'Configuración de ciudades guardada.';
    }

    if ( isset($_POST['silversea_import_nonce'])
         && wp_verify_nonce($_POST['silversea_import_nonce'], 'silversea_import') ) {
        $ciudad = sanitize_key($_POST['ciudad_origen'] ?? '');
        $ciudad = in_array($ciudad, silversea_get_city_keys('delivery'), true) ? $ciudad : '';
        if ( ! $ciudad ) { $error = 'Seleccioná una ciudad de origen válida.'; }
        elseif ( empty($_FILES['tarifa_file']['tmp_name']) ) { $error = 'No se recibió ningún archivo.'; }
        else {
            $file = $_FILES['tarifa_file'];
            $ext  = strtolower( pathinfo($file['name'], PATHINFO_EXTENSION) );
            $rows = [];
            if ( $ext === 'csv' )      $rows = silversea_shipping_parse_csv($file['tmp_name']);
            elseif ( $ext === 'xlsx' ) $rows = silversea_shipping_parse_xlsx($file['tmp_name']);
            elseif ( $ext === 'json' ) $rows = silversea_shipping_parse_json($file['tmp_name']);
            else $error = 'Formato no soportado. Usá CSV, XLSX o JSON.';
            if ( ! $error && ! empty($rows) ) {
                $result = silversea_shipping_import_rows($ciudad, $rows);
                if ( is_wp_error($result) ) $error = $result->get_error_message();
                else { $ins=$result['inserted']; $ski=$result['skipped'];
                    $message = "Importación completada: <strong>{$ins}</strong> filas insertadas, <strong>{$ski}</strong> omitidas."; }
            } elseif ( ! $error ) $error = 'El archivo estaba vacío o no pudo parsearse.';
        }
    }

    if ( isset($_POST['silversea_clear_nonce'])
         && wp_verify_nonce($_POST['silversea_clear_nonce'], 'silversea_clear') ) {
        $ciudad = sanitize_key($_POST['clear_ciudad'] ?? '');
        if ( in_array($ciudad, silversea_get_city_keys('delivery'), true) ) {
            $wpdb->delete($table, ['ciudad_origen'=>$ciudad]);
            $message = "Tarifas de <strong>{$ciudad}</strong> eliminadas.";
        }
    }

    $counts = [];
    foreach ( silversea_get_city_keys('delivery') as $c )
        $counts[$c] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ciudad_origen=%s",$c));

    $ciudad_preview = sanitize_key($_GET['preview'] ?? 'barcelona');
    $preview_rows   = $wpdb->get_results($wpdb->prepare(
        "SELECT cp_destino,municipio_destino,km,precio_sin_descarga,precio_con_desc_20,precio_con_desc_40
         FROM {$table} WHERE ciudad_origen=%s ORDER BY cp_destino LIMIT 10", $ciudad_preview), ARRAY_A);

    $demo_mode     = get_option('silversea_demo_mode','0');
    $descarga_modo = get_option('silversea_descarga_modo','contenedor');
    $p_sin         = get_option('silversea_demo_price_sin','786.60');
    $p_c20         = get_option('silversea_demo_price_c20','1644.00');
    $p_c40         = get_option('silversea_demo_price_c40','1765.28');
    $admin_email   = get_option('silversea_admin_email', get_option('admin_email'));
    $ej_qty        = 6;
    $ej_contenedor = number_format($ej_qty*(float)$p_c20,2,',','.');
    $ej_trucks     = (int)ceil((2*$ej_qty)/4);
    $ej_camion     = number_format($ej_trucks*(float)$p_sin,2,',','.');
    ?>
    <div class="wrap">
    <h1 style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      Silversea – Cotizador
      <a href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=silversea_export_products'), 'silversea_export_products' ) ); ?>"
         class="button"
         style="font-size:13px;font-weight:500;display:flex;align-items:center;gap:6px;">
        ⬇ Exportar contenedores (.csv)
      </a>
    </h1>
    <?php if($message): ?><div class="notice notice-success"><p><?php echo $message; ?></p></div><?php endif; ?>
    <?php if($error): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;margin-top:16px;">
    <h2 style="margin-top:0;">⚙️ Configuración</h2>
    <form method="post" action="options.php">
    <?php settings_fields('silversea_settings'); ?>
    <table class="form-table" style="margin:0;">
      <tr>
        <th style="width:220px;padding:12px 0;">Modo demo</th>
        <td>
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="silversea_demo_mode" value="1" <?php checked('1',$demo_mode); ?>>
            Activar resultados de prueba (sin consultar la base de datos)
          </label>
          <p class="description">Desactivar antes de salir a producción.</p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Con descarga: cobrar por</th>
        <td>
          <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;">
            <input type="radio" name="silversea_descarga_modo" value="contenedor" style="margin-top:3px;" <?php checked('contenedor',$descarga_modo); ?>>
            <span><strong>Contenedor individual</strong><br>
            <em style="color:#666;font-size:12px;">Ejemplo 6×20': 6 × €<?php echo esc_html($p_c20); ?> = €<?php echo $ej_contenedor; ?></em></span>
          </label>
          <label style="display:flex;align-items:flex-start;gap:8px;">
            <input type="radio" name="silversea_descarga_modo" value="camion" style="margin-top:3px;" <?php checked('camion',$descarga_modo); ?>>
            <span><strong>Camión</strong> — igual que sin descarga<br>
            <em style="color:#666;font-size:12px;">Ejemplo 6×20': <?php echo $ej_trucks; ?> camiones × €<?php echo esc_html($p_sin); ?> = €<?php echo $ej_camion; ?></em></span>
          </label>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Precios de demo (€)</th>
        <td>
          <div style="display:flex;gap:20px;flex-wrap:wrap;">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">Sin descarga <em style="color:#888;font-size:11px;">(por camión)</em>
              <input type="number" name="silversea_demo_price_sin" step="0.01" min="0" style="width:130px;" value="<?php echo esc_attr($p_sin); ?>">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">Con descarga 20' <em style="color:#888;font-size:11px;">(por contenedor)</em>
              <input type="number" name="silversea_demo_price_c20" step="0.01" min="0" style="width:130px;" value="<?php echo esc_attr($p_c20); ?>">
            </label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px;">Con descarga 40' <em style="color:#888;font-size:11px;">(por contenedor)</em>
              <input type="number" name="silversea_demo_price_c40" step="0.01" min="0" style="width:130px;" value="<?php echo esc_attr($p_c40); ?>">
            </label>
          </div>
          <p class="description" style="margin-top:8px;">Solo afectan el modo demo.</p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Email de ventas</th>
        <td>
          <input type="email" name="silversea_sales_email" value="<?php echo esc_attr( get_option('silversea_sales_email', '') ); ?>" style="width:280px;" class="regular-text" placeholder="comercial@ejemplo.com">
          <p class="description">Dirección a la que se envían las cotizaciones. <strong>Si está vacío, los emails no se envían</strong> — las cotizaciones solo se guardan en el panel para enviarlas manualmente después.</p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Email debug / fallback</th>
        <td>
          <input type="email" name="silversea_admin_email" value="<?php echo esc_attr($admin_email); ?>" style="width:280px;" class="regular-text">
          <p class="description">En modo demo/debug, todos los emails se envían aquí en lugar de los destinos reales. Por defecto: <?php echo esc_html(get_option('admin_email')); ?></p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Enviar email al cliente</th>
        <td>
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="silversea_email_send_client" value="1"
                   <?php checked('1', get_option('silversea_email_send_client','0')); ?>>
            Enviar email de confirmación al cliente tras cada cotización
          </label>
          <p class="description">Desactivado por defecto. El email a ventas siempre se envía.</p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Mostrar precios en email cliente</th>
        <td>
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="silversea_email_show_prices" value="1"
                   <?php checked('1', get_option('silversea_email_show_prices','1')); ?>>
            Incluir precios estimados de productos y transporte en el email al cliente
          </label>
          <p class="description">El email a ventas siempre muestra los precios completos.</p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Costo camión extra (con descarga)</th>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <span>€</span>
            <input type="number" name="silversea_extra_truck_cost" step="0.01" min="0" style="width:130px;"
                   value="<?php echo esc_attr(get_option('silversea_extra_truck_cost','1350.00')); ?>">
          </div>
          <p class="description">Cuando hay más de 1 camión con descarga: el camión 1 lleva la grúa (precio con descarga normal). Los camiones 2+ se cobran como sin descarga + este importe adicional.</p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Visualización en front</th>
        <td>
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="silversea_show_front" value="1"
                   <?php checked('1', get_option('silversea_show_front','0')); ?>>
            Mostrar el precio calculado al cliente en la página del producto
          </label>
          <p class="description">Desactivado por defecto. Cuando está desactivado el cálculo se realiza internamente pero el resultado no se muestra — solo se envía por email.</p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Requerir cotización previa</th>
        <td>
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="silversea_require_quote" value="1"
                   <?php checked('1', get_option('silversea_require_quote','0')); ?>>
            Obligar a cotizar el envío antes de poder agregar un contenedor a la selección
          </label>
          <p class="description">Si está activo, el botón "Añadir a Selección" no funcionará hasta que el usuario calcule el envío en esa página de producto.</p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Precio en catálogo</th>
        <td>
          <?php
            $cat_mode         = get_option('silversea_catalog_price_mode', 'wc');
            $cat_default_city = get_option('silversea_catalog_price_default_city', '');
          ?>
          <select name="silversea_catalog_price_mode" id="scp-mode" onchange="document.getElementById('scp-city-row').style.display=this.value==='default_city'?'':'none'" style="min-width:320px;">
            <option value="wc"           <?php selected($cat_mode,'wc'); ?>>Precio de WooCommerce (sin cambios)</option>
            <option value="min"          <?php selected($cat_mode,'min'); ?>>Desde el precio mínimo entre ciudades</option>
            <option value="default_city" <?php selected($cat_mode,'default_city'); ?>>Precio de una ciudad específica</option>
          </select>
          <div id="scp-city-row" style="margin-top:8px;<?php echo $cat_mode !== 'default_city' ? 'display:none;' : ''; ?>">
            <select name="silversea_catalog_price_default_city" style="min-width:220px;">
              <?php foreach ( silversea_get_cities() as $city ) : ?>
                <option value="<?php echo esc_attr($city['key']); ?>" <?php selected($cat_default_city,$city['key']); ?>>
                  <?php echo esc_html($city['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="description">Controla qué precio se muestra en fichas de producto y catálogo cuando hay precios por ciudad configurados. El precio dinámico (según ciudad elegida en el cotizador) siempre tiene prioridad.</p>
        </td>
      </tr>
      <tr>
        <th style="padding:12px 0;">Cotizador consolidado</th>
        <td>
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="silversea_show_consolidated" value="1"
                   <?php checked('1', get_option('silversea_show_consolidated','1')); ?>>
            Mostrar el cotizador de envío consolidado en la página "Mi Selección"
          </label>
          <p class="description">Cuando está activo, aparece el widget de cotización debajo de la lista de productos seleccionados.</p>
        </td>
      </tr>
      <?php do_action('silversea_admin_page_extra_settings'); ?>
    </table>
    <?php submit_button('Guardar configuración','primary','submit',false); ?>
    </form>
    </div>

    <div style="display:flex;gap:12px;margin:20px 0 8px;">
    <?php foreach($counts as $c=>$n): ?>
      <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px 20px;min-width:140px;">
        <div style="font-size:11px;text-transform:uppercase;color:#666;margin-bottom:4px;"><?php echo silversea_origin_label($c); ?></div>
        <div style="font-size:22px;font-weight:600;"><?php echo number_format($n); ?></div>
        <div style="font-size:11px;color:#888;">tarifas cargadas</div>
      </div>
    <?php endforeach; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
      <h2 style="margin-top:0;">Importar tarifas</h2>
      <p style="color:#555;font-size:13px;">Columnas: <code>Origen CP | Destino | Km | Sin descarga | Con 20' | Con 40'</code></p>
      <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('silversea_import','silversea_import_nonce'); ?>
        <table class="form-table" style="margin:0;">
          <tr><th style="width:140px;">Ciudad</th><td>
            <select name="ciudad_origen" required style="min-width:180px;">
              <option value="">— Seleccioná —</option>
              <?php foreach ( silversea_get_cities_for_mode('delivery') as $city ) : ?>
                <option value="<?php echo esc_attr($city['key']); ?>"><?php echo esc_html($city['name'] . ' (' . $city['depot'] . ')'); ?></option>
              <?php endforeach; ?>
            </select>
          </td></tr>
          <tr><th>Archivo</th><td>
            <input type="file" name="tarifa_file" accept=".csv,.xlsx,.json" required />
            <p class="description">CSV, Excel (.xlsx), JSON</p>
          </td></tr>
        </table>
        <p><input type="submit" class="button button-primary" value="Importar" /></p>
      </form>
    </div>
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
      <h2 style="margin-top:0;">Administrar datos</h2>
      <form method="post" onsubmit="return confirm('¿Eliminar todas las tarifas de esta ciudad?')">
        <?php wp_nonce_field('silversea_clear','silversea_clear_nonce'); ?>
        <select name="clear_ciudad" style="min-width:180px;margin-right:8px;">
          <?php foreach ( silversea_get_cities_for_mode('delivery') as $city ) : ?>
            <option value="<?php echo esc_attr($city['key']); ?>"><?php echo esc_html($city['name'] . ' (' . $city['depot'] . ')'); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="submit" class="button button-secondary" value="Vaciar ciudad" />
      </form>
      <hr style="margin:20px 0;">
      <h3 style="margin:0 0 8px;">Vista previa</h3>
      <form method="get" style="margin-bottom:8px;">
        <input type="hidden" name="page" value="silversea-cotizador">
        <select name="preview" onchange="this.form.submit()" style="min-width:160px;">
          <?php foreach ( silversea_get_cities_for_mode('delivery') as $city ) : ?>
            <option value="<?php echo esc_attr($city['key']); ?>" <?php selected($ciudad_preview, $city['key']); ?>><?php echo esc_html($city['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if($preview_rows): ?>
        <table style="width:100%;font-size:12px;border-collapse:collapse;">
          <thead><tr style="background:#f5f5f5;">
            <th style="padding:4px 6px;text-align:left;border:1px solid #ddd;">CP</th>
            <th style="padding:4px 6px;text-align:left;border:1px solid #ddd;">Municipio</th>
            <th style="padding:4px 6px;text-align:right;border:1px solid #ddd;">Km</th>
            <th style="padding:4px 6px;text-align:right;border:1px solid #ddd;">Sin desc.</th>
            <th style="padding:4px 6px;text-align:right;border:1px solid #ddd;">20'</th>
            <th style="padding:4px 6px;text-align:right;border:1px solid #ddd;">40'</th>
          </tr></thead>
          <tbody>
          <?php foreach($preview_rows as $r): ?>
            <tr>
              <td style="padding:3px 6px;border:1px solid #eee;"><?php echo esc_html($r['cp_destino']); ?></td>
              <td style="padding:3px 6px;border:1px solid #eee;"><?php echo esc_html($r['municipio_destino']); ?></td>
              <td style="padding:3px 6px;border:1px solid #eee;text-align:right;"><?php echo esc_html($r['km']); ?></td>
              <td style="padding:3px 6px;border:1px solid #eee;text-align:right;">€<?php echo number_format((float)$r['precio_sin_descarga'],2,',','.'); ?></td>
              <td style="padding:3px 6px;border:1px solid #eee;text-align:right;">€<?php echo number_format((float)$r['precio_con_desc_20'],2,',','.'); ?></td>
              <td style="padding:3px 6px;border:1px solid #eee;text-align:right;">€<?php echo number_format((float)$r['precio_con_desc_40'],2,',','.'); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p style="font-size:11px;color:#888;margin-top:4px;">Primeros 10 registros · ordenados por CP.</p>
      <?php else: ?>
        <p style="color:#999;font-size:13px;">Sin datos para <?php echo silversea_origin_label($ciudad_preview); ?>.</p>
      <?php endif; ?>
    </div>
    </div>

    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-top:24px;">
    <h2 style="margin-top:0;">🏙️ Ciudades / Depósitos</h2>
    <p style="color:#555;font-size:13px;margin-top:0;">Seleccioná los modos habilitados para cada depósito. <strong>Entrega</strong>: el cliente recibe el contenedor a domicilio (requiere tarifas cargadas). <strong>Retiro</strong>: el cliente retira en el depósito sin costo de transporte.</p>
    <form method="post">
      <?php wp_nonce_field('silversea_save_cities', 'silversea_cities_nonce'); ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#f5f5f5;">
            <th style="padding:8px 12px;text-align:left;border:1px solid #ddd;">Ciudad</th>
            <th style="padding:8px 12px;text-align:left;border:1px solid #ddd;">Depósito</th>
            <th style="padding:8px 12px;text-align:left;border:1px solid #ddd;">Dirección</th>
            <th style="padding:8px 12px;text-align:left;border:1px solid #ddd;">Nombre en desplegable</th>
            <th style="padding:8px 12px;text-align:center;border:1px solid #ddd;width:80px;">Entrega</th>
            <th style="padding:8px 12px;text-align:center;border:1px solid #ddd;width:80px;">Retiro</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ( silversea_get_cities() as $city ) : ?>
            <tr>
              <td style="padding:8px 12px;border:1px solid #eee;font-weight:600;"><?php echo esc_html($city['name']); ?></td>
              <td style="padding:8px 12px;border:1px solid #eee;"><?php echo esc_html($city['depot']); ?></td>
              <td style="padding:8px 12px;border:1px solid #eee;color:#555;"><?php echo esc_html($city['address']); ?></td>
              <td style="padding:8px 12px;border:1px solid #eee;">
                <input type="text" name="city_display_names[<?php echo esc_attr($city['key']); ?>]"
                  value="<?php echo esc_attr($city['display_name'] ?? ''); ?>"
                  placeholder="<?php echo esc_attr($city['name'] . ' — ' . $city['depot']); ?>"
                  style="width:100%;max-width:220px;padding:4px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;">
              </td>
              <td style="padding:8px 12px;border:1px solid #eee;text-align:center;">
                <input type="checkbox" name="city_modes[<?php echo esc_attr($city['key']); ?>][]" value="delivery"
                  <?php checked(in_array('delivery', $city['modes'], true)); ?>>
              </td>
              <td style="padding:8px 12px;border:1px solid #eee;text-align:center;">
                <input type="checkbox" name="city_modes[<?php echo esc_attr($city['key']); ?>][]" value="pickup"
                  <?php checked(in_array('pickup', $city['modes'], true)); ?>>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin-top:12px;"><input type="submit" class="button button-primary" value="Guardar ciudades" /></p>
    </form>
    </div>

    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-top:24px;">
    <h2 style="margin-top:0;">Formato JSON</h2>
    <pre style="background:#f5f5f5;padding:12px;border-radius:4px;font-size:12px;overflow-x:auto;">[{"cp_destino":"30640","municipio_destino":"Abanilla","km":195,"precio_sin_descarga":525.10,"precio_con_desc_20":1644.00,"precio_con_desc_40":1644.00}]</pre>
    </div>
    </div>
    <?php
}

/* ══ 5. PARSERS ════════════════════════════════════════════ */

function silversea_shipping_parse_csv($filepath) {
    $rows = [];
    if ( ($handle=fopen($filepath,'r'))===false ) return $rows;
    $first = fgets($handle); rewind($handle);
    $delimiter = substr_count($first,';')>=substr_count($first,',') ? ';' : ',';
    $line_num = 0;
    while ( ($data=fgetcsv($handle,1000,$delimiter))!==false ) {
        $line_num++;
        if ($line_num===1 && !is_numeric(trim($data[0]))) continue;
        $rows[] = ['cp_destino'=>trim($data[0]??''),'municipio_destino'=>trim($data[1]??''),
            'km'=>(int)trim($data[2]??0),'precio_sin_descarga'=>silversea_shipping_parse_price($data[3]??0),
            'precio_con_desc_20'=>silversea_shipping_parse_price($data[4]??0),
            'precio_con_desc_40'=>silversea_shipping_parse_price($data[5]??0)];
    }
    fclose($handle); return $rows;
}

function silversea_shipping_parse_json($filepath) {
    $decoded = json_decode(file_get_contents($filepath),true);
    if (!is_array($decoded)) return [];
    $rows = [];
    foreach ($decoded as $item) {
        $cp  = $item['cp_destino']??($item['Origen CP']??($item['origen_cp']??''));
        $mun = $item['municipio_destino']??($item['Destino']??($item['destino']??''));
        $km  = $item['km']??($item['Km']??0);
        $sin = $item['precio_sin_descarga']??($item['Venta sin descarga EUROS']??0);
        $c20 = $item['precio_con_desc_20']??($item["Venta con Descarga (20')"]??0);
        $c40 = $item['precio_con_desc_40']??($item["Venta con Descarga (40')"]??0);
        if (empty($cp)) continue;
        $rows[] = ['cp_destino'=>trim($cp),'municipio_destino'=>trim($mun),'km'=>(int)$km,
            'precio_sin_descarga'=>silversea_shipping_parse_price($sin),
            'precio_con_desc_20'=>silversea_shipping_parse_price($c20),
            'precio_con_desc_40'=>silversea_shipping_parse_price($c40)];
    }
    return $rows;
}

function silversea_shipping_parse_xlsx($filepath) {
    if (!class_exists('ZipArchive')) return new WP_Error('no_zip','ZipArchive no disponible.');
    $zip = new ZipArchive();
    if ($zip->open($filepath)!==true) return new WP_Error('bad_zip','No se pudo abrir el XLSX.');

    /* Detectar número de hojas leyendo el workbook */
    $sheet_files = [];
    $wb_xml = $zip->getFromName('xl/workbook.xml');
    if ($wb_xml) {
        $wb = new SimpleXMLElement($wb_xml);
        $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        /* Contar sheets por orden */
        foreach ($wb->sheets->sheet as $s) {
            $sheet_files[] = (string)$s['name'];
        }
    }
    /* Fallback: detectar por archivos presentes */
    if (empty($sheet_files)) {
        for ($i = 1; $i <= 10; $i++) {
            if ($zip->getFromName("xl/worksheets/sheet{$i}.xml") !== false) $sheet_files[] = "sheet{$i}";
            else break;
        }
    }

    /* Cargar shared strings una sola vez */
    $strings = [];
    $ss_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss_xml) {
        $ss_dom = new SimpleXMLElement($ss_xml);
        foreach ($ss_dom->si as $si) {
            if (isset($si->t)) { $strings[] = (string)$si->t; }
            else { $val=''; foreach($si->r as $r) $val.=(string)$r->t; $strings[]=$val; }
        }
    }

    $num_sheets = count($sheet_files);

    /* ── FORMATO NUEVO: 2 hojas ── */
    if ($num_sheets >= 2) {
        $sheet1_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet2_xml = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        if (!$sheet1_xml || !$sheet2_xml) return [];

        /* Hoja 1: CP → precio_sin */
        $sin_map = [];
        $sheet1 = new SimpleXMLElement($sheet1_xml);
        foreach ($sheet1->sheetData->row as $row_xml) {
            $cells = silversea_xlsx_read_row($row_xml, $strings);
            $cp = silversea_normalize_cp(trim($cells[0] ?? ''));
            if (!$cp) continue;
            $sin_map[$cp] = silversea_shipping_parse_price($cells[1] ?? 0);
        }

        /* Hoja 2: CP → con_20, con_40 */
        $con_map = [];
        $sheet2 = new SimpleXMLElement($sheet2_xml);
        foreach ($sheet2->sheetData->row as $row_xml) {
            $cells = silversea_xlsx_read_row($row_xml, $strings);
            $cp = silversea_normalize_cp(trim($cells[0] ?? ''));
            if (!$cp) continue;
            $con_map[$cp] = [
                silversea_shipping_parse_price($cells[1] ?? 0),
                silversea_shipping_parse_price($cells[2] ?? 0),
            ];
        }

        /* Unión de CPs */
        $all_cps = array_unique(array_merge(array_keys($sin_map), array_keys($con_map)));
        $rows = [];
        foreach ($all_cps as $cp) {
            $rows[] = [
                'cp_destino'         => $cp,
                'municipio_destino'  => '',
                'km'                 => 0,
                'precio_sin_descarga'=> $sin_map[$cp] ?? 0.0,
                'precio_con_desc_20' => $con_map[$cp][0] ?? 0.0,
                'precio_con_desc_40' => $con_map[$cp][1] ?? 0.0,
            ];
        }
        return $rows;
    }

    /* ── FORMATO LEGACY: 1 hoja ── */
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheet_xml) return [];
    $sheet = new SimpleXMLElement($sheet_xml); $line_num = 0; $rows = [];
    foreach ($sheet->sheetData->row as $row_xml) {
        $line_num++;
        $cells = silversea_xlsx_read_row($row_xml, $strings);
        if ($line_num===1 && !is_numeric(trim($cells[0]??''))) continue;
        $cp = trim($cells[0]??''); if ($cp==='') continue;
        $rows[] = ['cp_destino'=>$cp,'municipio_destino'=>trim($cells[1]??''),'km'=>(int)trim($cells[3]??0),
            'precio_sin_descarga'=>silversea_shipping_parse_price($cells[4]??0),
            'precio_con_desc_20'=>silversea_shipping_parse_price($cells[5]??0),
            'precio_con_desc_40'=>silversea_shipping_parse_price($cells[7]??0)];
    }
    return $rows;
}

/** Lee una fila XML de un sheet XLSX y devuelve array indexado por columna (0-based). */
function silversea_xlsx_read_row($row_xml, $strings) {
    $cells = [];
    foreach ($row_xml->c as $cell) {
        $col_index = silversea_shipping_col_to_index(preg_replace('/[0-9]/','_',(string)$cell['r']));
        $val_raw   = isset($cell->v) ? (string)$cell->v : '';
        $cells[$col_index] = ((string)$cell['t']==='s') ? ($strings[(int)$val_raw]??'') : $val_raw;
    }
    return $cells;
}

function silversea_shipping_col_to_index($col) {
    $col=strtoupper(str_replace('_','',$col)); $index=0; $len=strlen($col);
    for($i=0;$i<$len;$i++) $index=$index*26+(ord($col[$i])-ord('A')+1);
    return $index-1;
}

function silversea_shipping_parse_price($raw) {
    $s=trim((string)$raw);
    if (preg_match('/^\d{1,3}(\.\d{3})*(,\d+)?$/',$s)) { $s=str_replace('.','',$s); $s=str_replace(',','.',$s); }
    else $s=str_replace(',',' ',$s);
    return (float)$s;
}

/* ══ 6. INSERTAR FILAS ════════════════════════════════════ */

function silversea_shipping_import_rows($ciudad,$rows) {
    global $wpdb; $table=$wpdb->prefix.'silversea_tarifas'; $inserted=0; $skipped=0;
    foreach($rows as $r) {
        if(empty($r['cp_destino'])){$skipped++;continue;}
        /* Normalizar a 5 dígitos (Excel/CSV suele eliminar el cero inicial) */
        $cp_norm = silversea_normalize_cp($r['cp_destino']);
        if($cp_norm==='') {$skipped++;continue;}
        $result=$wpdb->replace($table,['ciudad_origen'=>$ciudad,'cp_destino'=>$cp_norm,
            'municipio_destino'=>$r['municipio_destino'],'km'=>$r['km'],
            'precio_sin_descarga'=>$r['precio_sin_descarga'],'precio_con_desc_20'=>$r['precio_con_desc_20'],
            'precio_con_desc_40'=>$r['precio_con_desc_40']],[' %s','%s','%s','%d','%f','%f','%f']);
        if($result===false)$skipped++;else $inserted++;
    }
    return ['inserted'=>$inserted,'skipped'=>$skipped];
}

/* ══ 7. SHORTCODE [silversea_shipping] ════════════════════ */

add_shortcode( 'silversea_shipping', 'silversea_shipping_shortcode' );

function silversea_shipping_shortcode( $atts = [] ) {
    $atts = shortcode_atts(['mode' => 'single'], $atts, 'silversea_shipping');
    $mode = sanitize_key($atts['mode']); // 'single' | 'consolidated'

    /* ── Datos según modo ── */
    $product_size  = '20';
    $size_label    = '20 pies';
    $items_summary = [];
    $color_info    = ['type' => 'none', 'label' => ''];
    $extras_data = [];

    if ( $mode === 'consolidated' ) {
        if ( ! function_exists('silversea_get_raq_content') ) return '';
        $raq_content = silversea_get_raq_content();
        foreach ( $raq_content as $raq ) {
            $pid      = $raq['variation_id'] ?? $raq['product_id'];
            $_product = wc_get_product($pid);
            if ( ! $_product ) continue;
            $items_summary[] = [
                'size'     => silversea_get_product_size( $_product ),
                'quantity' => (int)($raq['quantity'] ?? 1),
                'name'     => $_product->get_title(),
            ];
        }
        $product_size = 'consolidated';
        $size_label   = 'Múltiples tamaños';
    } else {
        if ( function_exists('wc_get_product') ) {
            global $product;
            $pid = get_queried_object_id() ?: get_the_ID();
            $p   = ($product instanceof WC_Product) ? $product : wc_get_product($pid);
            if ( $p ) {
                $product_size = (string) silversea_get_product_size( $p );
                $size_label   = $product_size . ' pies';
                /* Color RAL — variable o simple.
                   En contenedores USADOS el color es aleatorio: no se muestra
                   el selector para no confundir al cliente. */
                $is_usado = has_term( 'usado', 'pa_condicion', $p->get_id() );

                if ( $is_usado ) {
                    /* Variable usado → ocultar selector y auto-seleccionar variación
                       para que igual se pueda agregar a la selección.
                       Simple usado → sin selector. */
                    $color_info = $p->is_type('variable')
                        ? ['type' => 'variable_hidden', 'label' => '']
                        : ['type' => 'none', 'label' => ''];
                } elseif ( $p->is_type('variable') ) {
                    $color_info = ['type' => 'variable', 'label' => ''];
                } else {
                    $c_terms    = wc_get_product_terms($p->get_id(), 'pa_color-ral', ['fields' => 'names']);
                    $color_info = ! empty($c_terms)
                        ? ['type' => 'simple', 'label' => $c_terms[0]]
                        : ['type' => 'none',   'label' => ''];
                }

                /* Extras WAPO — query directo a la DB, dedup por label */
                if ( function_exists('YITH_WAPO') && function_exists('YITH_WAPO_DB') ) {
                    global $wpdb;
                    $block_ids   = YITH_WAPO_DB()->yith_wapo_get_blocks_by_product( $p->get_id(), null, 'yes' );
                    $seen_labels = [];
                    foreach ( $block_ids as $block_id ) {
                        $rows = $wpdb->get_results( $wpdb->prepare(
                            "SELECT id, settings, options FROM {$wpdb->prefix}yith_wapo_addons WHERE block_id = %d AND visibility = '1' ORDER BY priority ASC",
                            $block_id
                        ) );
                        foreach ( $rows as $row ) {
                            $settings = @unserialize( $row->settings );
                            if ( ( $settings['type'] ?? '' ) !== 'checkbox' ) continue;
                            $opts = @unserialize( $row->options );
                            if ( ! is_array( $opts ) || ! isset( $opts['label'] ) || ! is_array( $opts['label'] ) ) continue;
                            foreach ( $opts['label'] as $x => $label ) {
                                if ( ! $label ) continue;
                                if ( in_array( $label, $seen_labels, true ) ) continue;
                                $seen_labels[] = $label;
                                $img_id        = $opts['image'][ $x ] ?? 0;
                                $extras_data[] = [
                                    'name'  => 'yith_wapo[][' . $row->id . '-' . $x . ']',
                                    'value' => $label,
                                    'label' => $label,
                                    'desc'  => $opts['description'][ $x ] ?? '',
                                    'icon'  => $img_id ? wp_get_attachment_image_url( (int) $img_id, [48, 48] ) : '',
                                    'price' => (float)( $opts['price'][ $x ] ?? 0 ),
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    /* Precios por ciudad para el producto actual (solo en modo single) */
    $city_prices = [];
    if ( $mode === 'single' && isset($p) && $p ) {
        $lookup_id   = $p->is_type('variation') ? $p->get_parent_id() : $p->get_id();
        $raw         = get_post_meta( $lookup_id, '_silversea_city_prices', true );
        $parsed      = $raw ? json_decode( $raw, true ) : [];
        foreach ( (array) $parsed as $k => $v ) {
            if ( is_numeric($v) ) $city_prices[ $k ] = (float) $v;
        }
    }

    wp_enqueue_style( 'silversea-shipping-calc', SILVERSEA_PLUGIN_URL.'assets/css/shipping-calculator.css', [], SILVERSEA_VERSION);
    wp_enqueue_script('silversea-shipping-calc', SILVERSEA_PLUGIN_URL.'assets/js/shipping-calculator.js',  ['jquery'], SILVERSEA_VERSION, true);
    wp_localize_script('silversea-shipping-calc', 'silvSea', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('silversea_calc'),
        'productSize'  => $product_size,
        'sizeLabel'    => $size_label,
        'mode'         => $mode,
        'items'        => $items_summary,
        'demoMode'     => get_option('silversea_demo_mode',     '0'),
        'descargaModo' => get_option('silversea_descarga_modo', 'contenedor'),
        'demoPriceSin' => (float)get_option('silversea_demo_price_sin', '786.60'),
        'demoPriceC20' => (float)get_option('silversea_demo_price_c20', '1644.00'),
        'demoPriceC40'    => (float)get_option('silversea_demo_price_c40', '1765.28'),
        'extraTruckCost'  => (float)get_option('silversea_extra_truck_cost', '1350.00'),
        'showFront'       => get_option('silversea_show_front', '0'),
        'requireQuote'    => get_option('silversea_require_quote', '0'),
        'productColor'    => $color_info,
        'extras'          => $extras_data,
        'cities'           => silversea_get_cities(),
        'productCityPrices'=> $city_prices,
        'texts'            => silversea_texts_all(),
    ]);

    $tooltip_retiro    = silversea_text('tooltip_pickup');
    $tooltip_salida    = silversea_text('tooltip_origin');
    $tooltip_transport = silversea_text('tooltip_transport');

    ob_start(); ?>
    <div class="sc-wrap"><div class="sc-card">

      <?php if ( $mode === 'single' ) : ?>
      <div class="sc-qty-row">
        <div class="sc-qty-group">
          <span class="sc-qty-label"><?php echo esc_html(silversea_text('qty_label')); ?></span>
          <div class="sc-qty-ctrl">
            <button class="sc-qty-btn" onclick="scQty(-1)" aria-label="Reducir">−</button>
            <span class="sc-qty-val" id="scQtyVal">1</span>
            <button class="sc-qty-btn" onclick="scQty(1)" aria-label="Aumentar">+</button>
          </div>
        </div>
        <div class="sc-size-badge">
          <span class="sc-size-badge-label">Tamaño</span>
          <span class="sc-size-badge-val"><?php echo esc_html($size_label); ?></span>
        </div>
      </div>
      <div class="sc-bulk-notice">
        <span class="sc-bulk-notice-icon">?</span>
        <div><?php echo silversea_text('bulk_notice'); ?></div>
      </div>
      <?php endif; ?>

      <?php if ( $mode === 'consolidated' && ! empty($items_summary) ) : ?>
      <div class="sc-items-summary">
        <p class="sc-title"><?php echo esc_html(silversea_text('items_summary_title')); ?></p>
        <div class="sc-items-list">
          <?php foreach ( $items_summary as $item ) : ?>
            <div class="sc-item-row">
              <span class="sc-item-name"><?php echo esc_html($item['name']); ?></span>
              <span class="sc-item-detail"><?php echo (int)$item['quantity']; ?>*<?php echo (int)$item['size']; ?>'</span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- <div class="sc-divider"></div> -->
      <p class="sc-title"><?php echo esc_html(silversea_text('method_title')); ?></p>
      <div class="sc-toggle" id="methodToggle">
        <button class="sc-toggle-btn" data-method="delivery" onclick="scSetMethod('delivery')"><span class="dot"></span> <?php echo esc_html(silversea_text('btn_delivery')); ?></button>
        <button class="sc-toggle-btn active" data-method="pickup"   onclick="scSetMethod('pickup')"><span class="dot"></span> <?php echo esc_html(silversea_text('btn_pickup')); ?></button>
      </div>

      <div id="scPickupPanel">
        <div class="sc-field">
          <label class="sc-label" for="scPickupCity">
            <?php echo esc_html(silversea_text('pickup_city_label')); ?>
            <span class="sc-tooltip-trigger" data-tip="<?php echo esc_attr($tooltip_retiro); ?>">?</span>
          </label>
          <select class="sc-select" id="scPickupCity" onchange="scPickupCityChanged()">
            <option value=""><?php echo esc_html(silversea_text('pickup_select_default')); ?></option>
            <?php foreach ( silversea_get_cities_for_mode('pickup') as $city ) : ?>
              <option value="<?php echo esc_attr($city['key']); ?>"><?php echo esc_html(silversea_city_dropdown_label($city)); ?></option>
            <?php endforeach; ?>
          </select>
          <div id="scDepositoInfo" class="sc-deposito-info sc-hidden"></div>
        </div>
        <div id="scPickupInfo" class="sc-tip sc-hidden">
          <span class="sc-tip-icon">ⓘ</span>
          <span><?php echo silversea_text('pickup_info'); ?></span>
        </div>
        <?php if ( $mode === 'single' && ! empty($extras_data) ) : ?>
        <div class="sc-extras-section">
          <p class="sc-title"><?php echo esc_html(silversea_text('extras_title')); ?></p>
          <div class="sc-extras-grid"></div>
        </div>
        <?php endif; ?>
        <button class="sc-btn-continue sc-hidden" id="scContinueBtn" onclick="scContinue()"><?php echo esc_html(silversea_text('btn_continue')); ?></button>
      </div>

      <div id="scDeliveryPanel" class="sc-hidden">
        <div class="sc-field">
          <label class="sc-label" for="scOriginCity">
            <?php echo esc_html(silversea_text('origin_label')); ?>
            <span class="sc-tooltip-trigger" data-tip="<?php echo esc_attr($tooltip_salida); ?>">?</span>
          </label>
          <select class="sc-select" id="scOriginCity" onchange="scSuggestTransport()">
            <option value=""><?php echo esc_html(silversea_text('origin_select_default')); ?></option>
            <?php foreach ( silversea_get_cities_for_mode('delivery') as $city ) : ?>
              <option value="<?php echo esc_attr($city['key']); ?>"><?php echo esc_html(silversea_city_dropdown_label($city)); ?></option>
            <?php endforeach; ?>
          </select>
          <div id="scDepositoInfoDelivery" class="sc-deposito-info sc-hidden"></div>
        </div>
        <div class="sc-field">
          <label class="sc-label" for="scPostalCode"><?php echo esc_html(silversea_text('cp_label')); ?></label>
          <input type="text" class="sc-input" id="scPostalCode" placeholder="<?php echo esc_attr(silversea_text('cp_placeholder')); ?>" maxlength="5"
                 oninput="this.value=this.value.replace(/\D/g,'');scSuggestTransport()" />
        </div>
        <div class="sc-field">
          <div class="sc-label">
            <?php echo esc_html(silversea_text('transport_label')); ?>
            <span class="sc-tooltip-trigger" data-tip="<?php echo esc_attr($tooltip_transport); ?>">?</span>
          </div>
          <div class="sc-size-toggle">
            <button class="sc-size-btn active" data-transport="sin" onclick="scSetTransport('sin')"><?php echo esc_html(silversea_text('transport_sin')); ?></button>
            <button class="sc-size-btn"        data-transport="con" onclick="scSetTransport('con')"><?php echo esc_html(silversea_text('transport_con')); ?></button>
          </div>
        </div>
        <div id="scTransportTip" class="sc-tip sc-hidden">
          <span class="sc-tip-icon">★</span><span id="scTransportTipText"></span>
        </div>
        <?php if ( $mode === 'single' && ! empty($extras_data) ) : ?>
        <div class="sc-extras-section">
          <p class="sc-title"><?php echo esc_html(silversea_text('extras_title')); ?></p>
          <div class="sc-extras-grid"></div>
        </div>
        <?php endif; ?>

        <button class="sc-btn-calc" id="scCalcBtn" onclick="scCalculate()">
          <?php echo esc_html( $mode === 'consolidated' ? silversea_text('btn_calc_consolidated') : silversea_text('btn_calc_single') ); ?>
        </button>
        <div id="scLoaderArea" class="sc-loader sc-hidden"><?php echo esc_html(silversea_text('loader')); ?></div>
        <div id="scErrorArea"  class="sc-error  sc-hidden"></div>
        <div id="scResultArea" class="sc-hidden">
          <div class="sc-result">
            <div class="sc-result-row">
              <span class="sc-result-label"><?php echo esc_html(silversea_text('result_label')); ?></span>
              <span id="scResultPrice" class="sc-result-price"></span>
            </div>
            <div id="scResultDetail"    class="sc-result-detail"></div>
            <div id="scResultBreakdown" class="sc-result-breakdown sc-hidden"></div>
            <div id="scResultDays"      class="sc-result-days sc-hidden"></div>
          </div>
        </div>

      </div>

    </div></div>
    <?php return ob_get_clean();
}

/* ══ 8. AJAX HANDLER SINGLE ════════════════════════════════ */

add_action('wp_ajax_silversea_calc_shipping',        'silversea_shipping_ajax_calc');
add_action('wp_ajax_nopriv_silversea_calc_shipping', 'silversea_shipping_ajax_calc');

function silversea_shipping_ajax_calc() {
    if (!check_ajax_referer('silversea_calc','nonce',false))
        wp_send_json_error(['message'=>'Sesión inválida.'],403);

    $method = sanitize_key($_POST['method']??'');
    $transp = in_array($_POST['transport']??'',['sin','con'])?$_POST['transport']:'sin';

    if ($method==='pickup') wp_send_json_success(['free'=>true,'price'=>0,'detail'=>silversea_text('msg_pickup_free'),'days'=>5]);
    if ($method!=='delivery') wp_send_json_error(['message'=>'Método inválido.']);

    $origin       = sanitize_key($_POST['origin']??'');
    $cp           = silversea_normalize_cp($_POST['postal_code']??'');
    $product_size = in_array((string)($_POST['product_size']??''),['10','20','40'])?(int)$_POST['product_size']:20;
    $quantity     = max(1,(int)($_POST['quantity']??1));

    if (!in_array($origin, silversea_get_city_keys('delivery'), true)) wp_send_json_error(['message'=>'Ciudad de origen inválida.']);
    if (!$cp) wp_send_json_error(['message'=>'Código postal inválido. Debe tener 5 dígitos.']);

    global $wpdb; $table=$wpdb->prefix.'silversea_tarifas';
    $row=silversea_find_tarifa_row($origin,$cp);
    if (!$row) wp_send_json_success(['no_tarifa'=>true,'cp'=>$cp,'origin'=>$origin,'product_size'=>$product_size,'quantity'=>$quantity]);

    $units_per=$product_size===10?1:($product_size===20?2:4);
    $trucks=(int)ceil(($units_per*$quantity)/4);
    $descarga_modo=get_option('silversea_descarga_modo','contenedor');
    $p_sin=(float)$row['precio_sin_descarga']; $p_c20=(float)$row['precio_con_desc_20']; $p_c40=(float)$row['precio_con_desc_40'];
    $breakdown=[]; $total=0.0;

    if ($transp==='sin'||$descarga_modo==='camion') {
        $total=$p_sin*$trucks;
        for($i=1;$i<=$trucks;$i++) $breakdown[]=['label'=>"Camión {$i} ({$product_size}')",'price'=>$p_sin];
    } else {
        /* Camión 1: con descarga (lleva grúa). Camiones 2+: sin descarga + extra */
        $p_extra_truck = (float)get_option('silversea_extra_truck_cost', '1350.00');
        for ( $i = 1; $i <= $trucks; $i++ ) {
            if ( $i === 1 ) {
                /* Camión 1 con grúa — regla: ≤20' lleno (4 unidades) = tarifa 40' */
                $units_in_truck      = min( $units_per * $quantity, 4 );
                $containers_in_truck = (int)ceil( $units_in_truck / $units_per );
                if ( $product_size !== 40 && $units_in_truck >= 4 ) {
                    $price_truck = $p_c40;
                    $breakdown[] = ['label' => "Camión 1 — con grúa ({$containers_in_truck}×{$product_size}' equiv. 40')", 'price' => $price_truck];
                } else {
                    $p_unit      = $product_size === 40 ? $p_c40 : $p_c20;
                    $price_truck = $p_unit * $containers_in_truck;
                    $breakdown[] = ['label' => "Camión 1 — con grúa ({$containers_in_truck}×{$product_size}')", 'price' => $price_truck];
                }
                $total += $price_truck;
            } else {
                /* Camiones extra — sin descarga + costo extra */
                $price_truck = $p_sin + $p_extra_truck;
                $breakdown[] = ['label' => "Camión {$i} — sin grúa + servicio extra", 'price' => $price_truck];
                $total += $price_truck;
            }
        }
    }

    $transp_label=$transp==='sin'?'sin descarga':'con descarga';
    $destino=$row['municipio_destino']?:"CP {$cp}";
    $detail_parts=["{$quantity}×{$product_size}'",$transp_label,$destino,'desde ' . silversea_origin_label($origin)];
    if (($transp==='sin'||$descarga_modo==='camion')&&$trucks>1) $detail_parts[]="{$trucks} camiones";

    wp_send_json_success(['price'=>round($total,2),'detail'=>implode(' · ',$detail_parts),'breakdown'=>$breakdown,'trucks'=>$trucks,'days'=>silversea_shipping_estimate_days((int)$row['km']),'km'=>(int)$row['km']]);
}

function silversea_shipping_estimate_days($km) {
    if($km<=100)return 2; if($km<=300)return 3; if($km<=600)return 4; return 5;
}

/* ══ 8b. AJAX — AUTOCOMPLETE DE CÓDIGOS POSTALES ══════════ */

add_action('wp_ajax_silversea_cp_suggest',        'silversea_cp_suggest');
add_action('wp_ajax_nopriv_silversea_cp_suggest', 'silversea_cp_suggest');

function silversea_cp_suggest() {
    if (!check_ajax_referer('silversea_calc','nonce',false)) wp_send_json_error([],403);

    $origin = sanitize_key($_POST['origin'] ?? '');
    $term   = preg_replace('/\D/','', $_POST['term'] ?? '');

    if (!in_array($origin, silversea_get_city_keys('delivery'), true) || strlen($term) < 2)
        wp_send_json_success([]);

    global $wpdb; $table = $wpdb->prefix.'silversea_tarifas';
    /* Buscar por prefijo, contemplando la forma con y sin cero inicial */
    $like     = $wpdb->esc_like($term).'%';
    $like_alt = $wpdb->esc_like(ltrim($term,'0')).'%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT cp_destino, municipio_destino FROM {$table}
         WHERE ciudad_origen=%s AND (cp_destino LIKE %s OR cp_destino LIKE %s)
         ORDER BY cp_destino LIMIT 12",
        $origin, $like, $like_alt
    ), ARRAY_A);

    wp_send_json_success($rows ?: []);
}

/* ══ 9. AJAX HANDLER CONSOLIDADO ══════════════════════════ */

add_action('wp_ajax_silversea_calc_consolidated',        'silversea_shipping_ajax_calc_consolidated');
add_action('wp_ajax_nopriv_silversea_calc_consolidated', 'silversea_shipping_ajax_calc_consolidated');

function silversea_shipping_ajax_calc_consolidated() {
    if (!check_ajax_referer('silversea_calc','nonce',false))
        wp_send_json_error(['message'=>'Sesión inválida.'],403);

    $method = sanitize_key($_POST['method']??'');
    $transp = in_array($_POST['transport']??'',['sin','con'])?$_POST['transport']:'sin';
    $origin = sanitize_key($_POST['origin']??'');
    $cp     = preg_replace('/\D/','', $_POST['postal_code']??'');

    if ($method==='pickup') wp_send_json_success(['free'=>true,'price'=>0,'detail'=>silversea_text('msg_pickup_free'),'days'=>5]);

    $raq_content = silversea_get_raq_content();
    if (empty($raq_content)) wp_send_json_error(['message'=>'No hay productos en la selección.']);

    $result=silversea_calc_consolidated_shipping($raq_content,$origin,$cp,$transp);
    if (is_wp_error($result)) {
        /* CP no disponible → flujo alternativo en vez de bloquear */
        if ($result->get_error_code()==='no_tarifa')
            wp_send_json_success(['no_tarifa'=>true,'cp'=>$cp,'origin'=>$origin,'product_size'=>'consolidated','quantity'=>count($raq_content)]);
        wp_send_json_error(['message'=>$result->get_error_message()]);
    }

    wp_send_json_success(['price'=>$result['total'],
        'detail'=>'Selección completa · '.$result['transp_label'].' · '.$result['destino'].' · desde '.silversea_origin_label($result['origin']).' · '.$result['trucks'].' camión'.($result['trucks']>1?'es':''),
        'breakdown'=>$result['breakdown'],'trucks'=>$result['trucks'],'days'=>$result['days']]);
}

/* ══ 10. FIX BUG ELIMINACIÓN ══════════════════════════════ */

add_action('wp_footer', function() {
    global $post;
    if (!$post) return;
    $raq_page_id = get_option('ywraq_page_id');
    $has_sc = has_shortcode($post->post_content,'silversea_quote_view');
    if (!is_page($raq_page_id) && !$has_sc) return;
    ?>
    <script>
    (function() {
        document.addEventListener('DOMContentLoaded', function() {
            document.body.addEventListener('click', function(e) {
                var btn = e.target.closest('.yith-ywraq-item-remove');
                if (!btn) return;
                e.preventDefault(); e.stopImmediatePropagation();
                var productId = btn.dataset.product_id;
                var removeKey = btn.getAttribute('data-remove-item');
                var nonce     = btn.dataset.wp_nonce;
                if (!productId||!removeKey) return;
                var row = btn.closest('.silversea-raq-row');
                if (row) { row.style.opacity='0.4'; row.style.pointerEvents='none'; }
                /* YITH verifica 'yith-ywraq-ajax-action' como nonce action,
                   el valor correcto está en yith_ywraq_frontend.security */
                var yithSecurity = (typeof yith_ywraq_frontend !== 'undefined')
                    ? yith_ywraq_frontend.security
                    : nonce; // fallback al nonce del botón

                var fd = new FormData();
                fd.append('action',       'yith_ywraq_action');
                fd.append('ywraq_action', 'remove_item');
                fd.append('security',     yithSecurity);
                fd.append('product_id',   productId);
                fd.append('key',          removeKey);
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST',body:fd})
                .then(function(){ window.location.reload(); })
                .catch(function(){
                    if(row){row.style.opacity='1';row.style.pointerEvents='';}
                    alert('<?php echo esc_js( silversea_text('msg_delete_error') ); ?>');
                });
            }, true);
        });
    })();
    </script>
    <?php
});

require_once __DIR__ . '/shipping-session.php';
require_once __DIR__ . '/shipping-quote-pages.php';

/* ══════════════════════════════════════════════════════════════
   PRECIO EN CATÁLOGO — según opción configurada
══════════════════════════════════════════════════════════════ */

add_filter( 'woocommerce_get_price_html', 'silversea_catalog_price_html', 20, 2 );

function silversea_catalog_price_html( $price_html, $product ) {
    $mode = get_option( 'silversea_catalog_price_mode', 'wc' );
    if ( $mode === 'wc' ) return $price_html;

    $lookup_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $raw       = get_post_meta( $lookup_id, '_silversea_city_prices', true );
    $prices    = $raw ? json_decode( $raw, true ) : [];
    $prices    = array_filter( (array) $prices, 'is_numeric' );
    if ( empty( $prices ) ) return $price_html;

    if ( $mode === 'min' ) {
        $min = min( array_map( 'floatval', $prices ) );
        return 'desde ' . wc_price( $min );
    }

    if ( $mode === 'default_city' ) {
        $city = get_option( 'silversea_catalog_price_default_city', '' );
        if ( $city && isset( $prices[ $city ] ) ) {
            return wc_price( (float) $prices[ $city ] );
        }
    }

    return $price_html;
}

/* ══════════════════════════════════════════════════════════════
   AJAX — guardar precios por ciudad
══════════════════════════════════════════════════════════════ */

add_action( 'wp_ajax_silversea_save_city_prices', function() {
    check_ajax_referer( 'silversea_city_prices', 'nonce' );
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error( null, 403 );

    $items = json_decode( stripslashes( $_POST['items'] ?? '[]' ), true );
    if ( ! is_array( $items ) ) wp_send_json_error( ['message' => 'Datos inválidos.'] );

    $valid_cities = silversea_get_city_keys();
    $saved = 0;

    foreach ( $items as $item ) {
        $id = (int) ( $item['id'] ?? 0 );
        if ( ! $id ) continue;
        $prices = [];
        foreach ( (array) ( $item['prices'] ?? [] ) as $city => $price ) {
            $city  = sanitize_key( $city );
            $price = trim( (string) $price );
            if ( ! in_array( $city, $valid_cities, true ) ) continue;
            if ( $price !== '' && is_numeric( $price ) && (float)$price > 0 ) {
                $prices[ $city ] = number_format( (float)$price, 2, '.', '' );
            }
        }
        update_post_meta( $id, '_silversea_city_prices', wp_json_encode( $prices ) );
        $saved++;
    }

    wp_send_json_success( ['saved' => $saved] );
} );

/* ══════════════════════════════════════════════════════════════
   PÁGINA — Precios por Ciudad (mapeo en lote)
══════════════════════════════════════════════════════════════ */

function silversea_render_city_prices_page() {
    $cat_id = (int) ( $_GET['cat'] ?? 0 );
    $search = sanitize_text_field( $_GET['s'] ?? '' );

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
    ];
    if ( $cat_id ) $args['tax_query'] = [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id]];
    if ( $search ) $args['s'] = $search;

    $products   = get_posts( $args );
    $categories = get_terms( ['taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name'] );
    $cities     = silversea_get_cities();
    ?>
    <div class="wrap" style="max-width:1100px;">
      <h1>🏙 Precios por Ciudad</h1>
      <p style="color:#6b7280;font-size:13px;margin-top:4px;">
        Precio del contenedor según la ciudad de retiro o salida elegida por el cliente.
        Dejá vacío para que aplique el precio general de WooCommerce.
      </p>

      <!-- Filtros -->
      <form method="get" style="display:flex;gap:10px;align-items:center;margin:16px 0;">
        <input type="hidden" name="page" value="silversea-city-prices">
        <select name="cat" onchange="this.form.submit()" style="height:34px;">
          <option value="">Todas las categorías</option>
          <?php foreach ( $categories as $cat ) : ?>
            <option value="<?php echo $cat->term_id; ?>" <?php selected($cat_id,$cat->term_id); ?>>
              <?php echo esc_html($cat->name); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
               placeholder="Buscar…" style="width:200px;height:34px;padding:0 8px;">
        <button type="submit" class="button">Filtrar</button>
        <?php if ( $cat_id || $search ) : ?>
          <a href="?page=silversea-city-prices" class="button">✕ Limpiar</a>
        <?php endif; ?>
        <span style="font-size:13px;color:#9ca3af;"><?php echo count($products); ?> productos</span>
      </form>

      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <button id="scp-save" class="button button-primary" style="height:36px;padding:0 20px;font-size:14px;">
          💾 Guardar precios
        </button>
        <span id="scp-status" style="font-size:13px;"></span>
      </div>

      <?php if ( empty($products) ) : ?>
        <p style="color:#9ca3af;text-align:center;padding:48px;">No se encontraron productos.</p>
      <?php else : ?>
      <table class="wp-list-table widefat fixed" id="scp-table">
        <thead>
          <tr>
            <th style="width:44px;"></th>
            <th>Producto</th>
            <th style="width:110px;">SKU</th>
            <?php foreach ( $cities as $city ) : ?>
              <th style="width:110px;text-align:center;"><?php echo esc_html($city['name']); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ( $products as $post ) :
            $product = wc_get_product( $post->ID );
            if ( ! $product ) continue;
            $thumb  = get_the_post_thumbnail_url( $post->ID, [36,36] ) ?: wc_placeholder_img_src([36,36]);
            $sku    = $product->get_sku() ?: '—';
            $prices = json_decode( get_post_meta($post->ID,'_silversea_city_prices',true) ?: '{}', true );
            $is_var = $product->is_type('variable');
        ?>
        <tr class="scp-row" data-id="<?php echo $post->ID; ?>">
          <td style="text-align:center;padding:8px 4px;">
            <img src="<?php echo esc_url($thumb); ?>" width="34" height="34"
                 style="border-radius:4px;object-fit:cover;vertical-align:middle;">
          </td>
          <td>
            <?php echo esc_html($post->post_title); ?>
            <?php if ($is_var) : ?>
              <span style="font-size:11px;background:#f5f3ff;color:#7c3aed;padding:1px 7px;border-radius:4px;margin-left:6px;">Variable</span>
            <?php endif; ?>
          </td>
          <td><code style="font-size:11px;background:#f3f4f6;padding:2px 6px;border-radius:3px;"><?php echo esc_html($sku); ?></code></td>
          <?php foreach ( $cities as $city ) : ?>
            <td style="text-align:center;">
              <input type="number" class="scp-input" step="0.01" min="0"
                     data-city="<?php echo esc_attr($city['key']); ?>"
                     value="<?php echo esc_attr($prices[$city['key']] ?? ''); ?>"
                     placeholder="—"
                     style="width:90px;height:28px;padding:0 6px;font-size:13px;border:1px solid #d1d5db;border-radius:4px;text-align:right;">
            </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <style>
    #scp-table th, #scp-table td { vertical-align:middle; }
    .scp-input:focus { border-color:#2271b1!important; box-shadow:0 0 0 1px #2271b1; outline:none; }
    .scp-input.scp-dirty { border-color:#f59e0b!important; background:#fffbeb; }
    </style>

    <script>
    jQuery(function($) {
      var nonce = '<?php echo wp_create_nonce('silversea_city_prices'); ?>';

      $(document).on('input', '.scp-input', function() { $(this).addClass('scp-dirty'); });

      $('#scp-save').on('click', function() {
        var $btn = $(this), $status = $('#scp-status');
        var items = [];

        $('.scp-row').each(function() {
          var id = $(this).data('id');
          var prices = {};
          $(this).find('.scp-input').each(function() {
            var v = $(this).val().trim();
            if (v !== '') prices[$(this).data('city')] = v;
          });
          items.push({ id: id, prices: prices });
        });

        $btn.prop('disabled', true).text('Guardando…');
        $status.text('').css('color','#6b7280');

        $.post(ajaxurl, { action:'silversea_save_city_prices', nonce:nonce, items:JSON.stringify(items) })
          .done(function(res) {
            if (res.success) {
              $status.css('color','#059669').text('✓ ' + res.data.saved + ' producto(s) guardados.');
              $('.scp-input').removeClass('scp-dirty');
            } else {
              $status.css('color','#dc2626').text('✗ Error al guardar.');
            }
          })
          .fail(function() { $status.css('color','#dc2626').text('✗ Error de conexión.'); })
          .always(function() {
            $btn.prop('disabled', false).text('💾 Guardar precios');
            setTimeout(function(){ $status.text(''); }, 5000);
          });
      });
    });
    </script>
    <?php
}

/* ══════════════════════════════════════════════════════════════
   EXPORTAR CONTENEDORES — descarga CSV con atributos completos
══════════════════════════════════════════════════════════════ */

add_action( 'admin_post_silversea_export_products', 'silversea_handle_export_products' );

function silversea_handle_export_products() {
    if ( ! current_user_can('manage_woocommerce') ) wp_die( 'No autorizado.', 403 );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'silversea_export_products' ) ) wp_die( 'Nonce inválido.' );

    $products = get_posts( [
        'post_type'      => 'product',
        'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
        'posts_per_page' => -1,
        'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
    ] );

    /* ── Headers HTTP para descarga ── */
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="contenedores-' . date('Y-m-d') . '.csv"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    /* BOM para que Excel abra correctamente en UTF-8 */
    echo "\xEF\xBB\xBF";

    $out = fopen( 'php://output', 'w' );

    /* Cabeceras del CSV */
    fputcsv( $out, [
        'Contenedor',
        'SKU',
        'Colores RAL',
        'Precio',
        'Categoría Salesforce',
        'Tamaño',
        'Condición',
        'Tipo',
        'Estado',
        'Orden',
    ], ';' );

    $status_labels = [
        'publish' => 'Publicado',
        'draft'   => 'Borrador',
        'private' => 'Privado',
        'pending' => 'Pendiente',
    ];

    foreach ( $products as $post ) {
        $product = wc_get_product( $post->ID );
        if ( ! $product ) continue;

        $is_var = $product->is_type( 'variable' );

        /* Colores RAL — términos asignados al producto (simple o variable) */
        $color_terms = wc_get_product_terms( $post->ID, 'pa_color-ral', [ 'fields' => 'names' ] );
        $colors      = implode( ', ', $color_terms );

        /* Precio */
        if ( $is_var ) {
            $min   = $product->get_variation_price( 'min' );
            $max   = $product->get_variation_price( 'max' );
            $price = ( $min == $max ) ? $min : $min . ' – ' . $max;
        } else {
            $price = $product->get_regular_price();
        }

        /* Atributos de taxonomía */
        $tamano    = implode( ', ', wc_get_product_terms( $post->ID, 'pa_tamano',    [ 'fields' => 'names' ] ) );
        $condicion = implode( ', ', wc_get_product_terms( $post->ID, 'pa_condicion', [ 'fields' => 'names' ] ) );

        /* Tipo — categorías WC "para-transporte" y/o "para-almacenaje" */
        $tipos = [];
        if ( has_term( 'para-transporte', 'product_cat', $post->ID ) ) $tipos[] = 'Para transporte';
        if ( has_term( 'para-almacenaje', 'product_cat', $post->ID ) ) $tipos[] = 'Para almacenaje';
        $tipo = implode( ', ', $tipos );

        /* Categoría Salesforce */
        $sf_type = get_post_meta( $post->ID, 'silversea_sf_container_type', true );

        fputcsv( $out, [
            $post->post_title,
            $product->get_sku(),
            $colors,
            $price,
            $sf_type,
            $tamano,
            $condicion,
            $tipo,
            $status_labels[ $post->post_status ] ?? $post->post_status,
            $post->menu_order,
        ], ';' );
    }

    fclose( $out );
    exit;
}
