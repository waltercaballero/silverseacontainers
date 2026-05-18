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
        'silversea_email_send_client',
        'silversea_email_show_prices',
        'silversea_require_quote',
        'silversea_show_consolidated',
    ] as $key ) register_setting( 'silversea_settings', $key );
} );

/* ══ 3. MENÚ ADMIN ════════════════════════════════════════ */

add_action( 'admin_menu', 'silversea_shipping_admin_menu' );

function silversea_shipping_admin_menu() {
    add_submenu_page( 'woocommerce', 'Silversea – Cotizador', 'Cotizador',
        'manage_woocommerce', 'silversea-tarifas', 'silversea_shipping_admin_page' );
}

/* ══ 4. PÁGINA ADMIN ══════════════════════════════════════ */

function silversea_shipping_admin_page() {
    global $wpdb;
    $table   = $wpdb->prefix . 'silversea_tarifas';
    $message = ''; $error = '';

    if ( isset($_POST['silversea_cities_nonce'])
         && wp_verify_nonce($_POST['silversea_cities_nonce'], 'silversea_save_cities') ) {
        $submitted_modes = $_POST['city_modes'] ?? [];
        $updated_cities  = [];
        foreach ( silversea_default_cities() as $default ) {
            $key   = $default['key'];
            $raw   = isset($submitted_modes[$key]) && is_array($submitted_modes[$key])
                     ? $submitted_modes[$key] : [];
            $modes = array_values( array_intersect( ['delivery','pickup'], $raw ) );
            $updated_cities[] = array_merge( $default, ['modes' => $modes] );
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
    <h1>Silversea – Cotizador</h1>
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
        <input type="hidden" name="page" value="silversea-tarifas">
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
    $rows = [];
    if (!class_exists('ZipArchive')) return new WP_Error('no_zip','ZipArchive no disponible.');
    $zip = new ZipArchive();
    if ($zip->open($filepath)!==true) return new WP_Error('bad_zip','No se pudo abrir el XLSX.');
    $strings=[]; $ss_xml=$zip->getFromName('xl/sharedStrings.xml');
    if ($ss_xml) { $ss_dom=new SimpleXMLElement($ss_xml);
        foreach($ss_dom->si as $si) { if(isset($si->t)){$strings[]=(string)$si->t;}
            else{$val='';foreach($si->r as $r)$val.=(string)$r->t;$strings[]=$val;} } }
    $sheet_xml=$zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
    if (!$sheet_xml) return $rows;
    $sheet=new SimpleXMLElement($sheet_xml); $line_num=0;
    foreach($sheet->sheetData->row as $row_xml) {
        $line_num++; $cells=[];
        foreach($row_xml->c as $cell) {
            $col_index=silversea_shipping_col_to_index(preg_replace('/[0-9]/','_',(string)$cell['r']));
            $val_raw=isset($cell->v)?(string)$cell->v:'';
            $cells[$col_index]=((string)$cell['t']==='s')?($strings[(int)$val_raw]??''):$val_raw;
        }
        if ($line_num===1&&!is_numeric(trim($cells[0]??''))) continue;
        $cp=trim($cells[0]??''); if($cp==='') continue;
        $rows[]=['cp_destino'=>$cp,'municipio_destino'=>trim($cells[1]??''),'km'=>(int)trim($cells[3]??0),
            'precio_sin_descarga'=>silversea_shipping_parse_price($cells[4]??0),
            'precio_con_desc_20'=>silversea_shipping_parse_price($cells[5]??0),
            'precio_con_desc_40'=>silversea_shipping_parse_price($cells[7]??0)];
    }
    return $rows;
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
        $result=$wpdb->replace($table,['ciudad_origen'=>$ciudad,'cp_destino'=>$r['cp_destino'],
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
                /* Color RAL — variable o simple */
                if ( $p->is_type('variable') ) {
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
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    wp_enqueue_style( 'silversea-shipping-calc', SILVERSEA_PLUGIN_URL.'assets/css/shipping-calculator.css', [], '1.5.0');
    wp_enqueue_script('silversea-shipping-calc', SILVERSEA_PLUGIN_URL.'assets/js/shipping-calculator.js',  ['jquery'], '1.5.0', true);
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
        'cities'          => silversea_get_cities(),
    ]);

    $tooltip_retiro    = 'Seleccione la ciudad más cercana al domicilio de entrega del contenedor';
    $tooltip_salida    = 'Seleccione la ciudad más cercana al domicilio de entrega del contenedor';
    $tooltip_transport = '"con descarga": SILVERSEA proporciona el camión que incluye grúa con descarga del contenedor en la ubicación precisa donde se requiere' . "\n\n" . '"sin descarga": SILVERSEA proporciona el transporte del contenedor y el cliente deberá hacerse cargo de los medios para descargarlo en la ubicación requerida';

    ob_start(); ?>
    <div class="sc-wrap"><div class="sc-card">

      <?php if ( $mode === 'single' ) : ?>
      <div class="sc-qty-row">
        <div class="sc-qty-group">
          <span class="sc-qty-label">Cantidad</span>
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
      <?php endif; ?>

      <?php if ( $mode === 'consolidated' && ! empty($items_summary) ) : ?>
      <div class="sc-items-summary">
        <p class="sc-title">Tu selecci&oacute;n</p>
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
      <p class="sc-title">Método de entrega</p>
      <div class="sc-toggle" id="methodToggle">
        <button class="sc-toggle-btn" data-method="delivery" onclick="scSetMethod('delivery')"><span class="dot"></span> Con entrega</button>
        <button class="sc-toggle-btn active" data-method="pickup"   onclick="scSetMethod('pickup')"><span class="dot"></span> A retirar</button>
      </div>

      <div id="scPickupPanel">
        <div class="sc-field">
          <label class="sc-label" for="scPickupCity">
            Ciudad de retiro
            <span class="sc-tooltip-trigger" data-tip="<?php echo esc_attr($tooltip_retiro); ?>">?</span>
          </label>
          <select class="sc-select" id="scPickupCity" onchange="scPickupCityChanged()">
            <option value="">Selecciona una ciudad</option>
            <?php foreach ( silversea_get_cities_for_mode('pickup') as $city ) : ?>
              <option value="<?php echo esc_attr($city['key']); ?>"><?php echo esc_html($city['name'] . ' — ' . $city['depot']); ?></option>
            <?php endforeach; ?>
          </select>
          <div id="scDepositoInfo" class="sc-deposito-info sc-hidden"></div>
        </div>
        <div id="scPickupInfo" class="sc-tip sc-hidden">
          <span class="sc-tip-icon">ⓘ</span>
          <span>El contenedor estará disponible en un plazo estimado de <strong>5 días hábiles</strong>. El retiro en depósito no tiene costo adicional.</span>
        </div>
        <?php if ( $mode === 'single' && ! empty($extras_data) ) : ?>
        <div class="sc-extras-section">
          <p class="sc-title">Extras disponibles</p>
          <div class="sc-extras-grid"></div>
        </div>
        <?php endif; ?>
        <button class="sc-btn-continue sc-hidden" id="scContinueBtn" onclick="scContinue()">Guardar preferencia de retiro</button>
      </div>

      <div id="scDeliveryPanel" class="sc-hidden">
        <div class="sc-field">
          <label class="sc-label" for="scOriginCity">
            Ciudad de salida
            <span class="sc-tooltip-trigger" data-tip="<?php echo esc_attr($tooltip_salida); ?>">?</span>
          </label>
          <select class="sc-select" id="scOriginCity" onchange="scSuggestTransport()">
            <option value="">Selecciona ciudad de origen</option>
            <?php foreach ( silversea_get_cities_for_mode('delivery') as $city ) : ?>
              <option value="<?php echo esc_attr($city['key']); ?>"><?php echo esc_html($city['name'] . ' — ' . $city['depot']); ?></option>
            <?php endforeach; ?>
          </select>
          <div id="scDepositoInfoDelivery" class="sc-deposito-info sc-hidden"></div>
        </div>
        <div class="sc-field">
          <label class="sc-label" for="scPostalCode">Código postal de destino</label>
          <input type="text" class="sc-input" id="scPostalCode" placeholder="Ej. 28001" maxlength="5"
                 oninput="this.value=this.value.replace(/\D/g,'');scSuggestTransport()" />
        </div>
        <div class="sc-field">
          <div class="sc-label">
            Tipo de transporte
            <span class="sc-tooltip-trigger" data-tip="<?php echo esc_attr($tooltip_transport); ?>">?</span>
          </div>
          <div class="sc-size-toggle">
            <button class="sc-size-btn active" data-transport="sin" onclick="scSetTransport('sin')">Sin descarga</button>
            <button class="sc-size-btn"        data-transport="con" onclick="scSetTransport('con')">Con descarga</button>
          </div>
        </div>
        <div id="scTransportTip" class="sc-tip sc-hidden">
          <span class="sc-tip-icon">★</span><span id="scTransportTipText"></span>
        </div>
        <?php if ( $mode === 'single' && ! empty($extras_data) ) : ?>
        <div class="sc-extras-section">
          <p class="sc-title">Extras disponibles</p>
          <div class="sc-extras-grid"></div>
        </div>
        <?php endif; ?>

        <button class="sc-btn-calc" id="scCalcBtn" onclick="scCalculate()">
          <?php echo $mode === 'consolidated' ? 'Calcular precio de envío total' : 'Calcular precio de envío'; ?>
        </button>
        <div id="scLoaderArea" class="sc-loader sc-hidden">Calculando...</div>
        <div id="scErrorArea"  class="sc-error  sc-hidden"></div>
        <div id="scResultArea" class="sc-hidden">
          <div class="sc-result">
            <div class="sc-result-row">
              <span class="sc-result-label">Costo de envío estimado</span>
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

    if ($method==='pickup') wp_send_json_success(['free'=>true,'price'=>0,'detail'=>'Retiro en depósito sin costo adicional.','days'=>5]);
    if ($method!=='delivery') wp_send_json_error(['message'=>'Método inválido.']);

    $origin       = sanitize_key($_POST['origin']??'');
    $cp           = preg_replace('/\D/','', $_POST['postal_code']??'');
    $product_size = in_array((string)($_POST['product_size']??''),['10','20','40'])?(int)$_POST['product_size']:20;
    $quantity     = max(1,(int)($_POST['quantity']??1));

    if (!in_array($origin, silversea_get_city_keys('delivery'), true)) wp_send_json_error(['message'=>'Ciudad de origen inválida.']);
    if (strlen($cp)<4||strlen($cp)>5) wp_send_json_error(['message'=>'Código postal inválido.']);

    global $wpdb; $table=$wpdb->prefix.'silversea_tarifas';
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE ciudad_origen=%s AND cp_destino=%s LIMIT 1",$origin,$cp),ARRAY_A);
    if (!$row) $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE ciudad_origen=%s AND cp_destino LIKE %s LIMIT 1",$origin,substr($cp,0,4).'%'),ARRAY_A);
    if (!$row) wp_send_json_error(['message'=>"No encontramos tarifa para el CP {$cp} desde " . silversea_origin_label($origin) . '. Contactanos para una cotización personalizada.']);

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

    if ($method==='pickup') wp_send_json_success(['free'=>true,'price'=>0,'detail'=>'Retiro en depósito sin costo adicional.','days'=>5]);

    $raq_content = silversea_get_raq_content();
    if (empty($raq_content)) wp_send_json_error(['message'=>'No hay productos en la selección.']);

    $result=silversea_calc_consolidated_shipping($raq_content,$origin,$cp,$transp);
    if (is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()]);

    wp_send_json_success(['price'=>$result['total'],
        'detail'=>'Selección completa · '.$result['transp_label'].' · '.$result['destino'].' · desde '.ucfirst($result['origin']).' · '.$result['trucks'].' camión'.($result['trucks']>1?'es':''),
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
                    alert('Error al eliminar. Intentá nuevamente.');
                });
            }, true);
        });
    })();
    </script>
    <?php
});

require_once __DIR__ . '/shipping-session.php';
require_once __DIR__ . '/shipping-quote-pages.php';
