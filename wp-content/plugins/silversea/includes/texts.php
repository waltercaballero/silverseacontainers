<?php
/**
 * Silversea – Textos editables del cotizador
 *
 * Permite editar desde el admin (Cotizador → 📝 Textos) todos los textos
 * mostrados al cliente, sin tocar código. Se guardan en la opción
 * `silversea_texts` (array key => valor). Cada texto tiene un default.
 *
 * Uso en PHP:   echo silversea_text('clave');           // HTML/text
 *               echo esc_attr( silversea_text('clave') ); // en atributos
 * Uso en JS:    silvSea.texts.clave  (ver T() en shipping-calculator.js)
 *
 * Para sumar nuevos textos (p. ej. emails) basta con agregar entradas a
 * silversea_text_defaults().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ══════════════════════════════════════════════════════════════
   REGISTRO DE TEXTOS — clave => [grupo, label, tipo, default]
   tipo: 'text' (input) | 'textarea' (multilínea/HTML)
══════════════════════════════════════════════════════════════ */

function silversea_text_defaults() {
    return [

        /* ── Cotizador: estructura ── */
        'qty_label'              => ['Cotizador', 'Etiqueta "Cantidad"', 'text', 'Cantidad'],
        'method_title'           => ['Cotizador', 'Título método de entrega', 'text', 'Método de entrega'],
        'btn_delivery'           => ['Cotizador', 'Botón "Con entrega"', 'text', 'Con entrega'],
        'btn_pickup'             => ['Cotizador', 'Botón "A recoger"', 'text', 'A recoger'],
        'items_summary_title'    => ['Cotizador', 'Título resumen selección', 'text', 'Su selección'],

        /* ── Cotizador: recogida ── */
        'pickup_city_label'      => ['Cotizador', 'Etiqueta "Ciudad de recogida"', 'text', 'Ciudad de recogida'],
        'pickup_select_default'  => ['Cotizador', 'Placeholder select ciudad recogida', 'text', 'Seleccione una ciudad'],
        'pickup_info'            => ['Cotizador', 'Aviso de recogida (HTML)', 'textarea', 'El contenedor estará disponible en un plazo estimado de <strong>5 días hábiles</strong>. La recogida en depósito no tiene coste adicional.'],
        'btn_continue'           => ['Cotizador', 'Botón guardar recogida', 'text', 'Guardar preferencia de recogida'],
        'tooltip_pickup'         => ['Cotizador', 'Tooltip ciudad de recogida', 'textarea', 'Seleccione la ciudad más cercana al domicilio de entrega del contenedor'],

        /* ── Cotizador: entrega ── */
        'origin_label'           => ['Cotizador', 'Etiqueta "Ciudad de salida"', 'text', 'Ciudad de salida'],
        'origin_select_default'  => ['Cotizador', 'Placeholder select ciudad origen', 'text', 'Seleccione ciudad de origen'],
        'tooltip_origin'         => ['Cotizador', 'Tooltip ciudad de salida', 'textarea', 'Seleccione la ciudad más cercana al domicilio de entrega del contenedor'],
        'cp_label'               => ['Cotizador', 'Etiqueta código postal', 'text', 'Código postal de destino'],
        'cp_placeholder'         => ['Cotizador', 'Placeholder código postal', 'text', 'Ej. 28001'],
        'transport_label'        => ['Cotizador', 'Etiqueta "Tipo de transporte"', 'text', 'Tipo de transporte'],
        'transport_sin'          => ['Cotizador', 'Botón "Sin descarga"', 'text', 'Sin descarga'],
        'transport_con'          => ['Cotizador', 'Botón "Con descarga"', 'text', 'Con descarga'],
        'tooltip_transport'      => ['Cotizador', 'Tooltip tipo de transporte', 'textarea', '"con descarga": SILVERSEA proporciona el camión que incluye grúa con descarga del contenedor en la ubicación precisa donde se requiere' . "\n\n" . '"sin descarga": SILVERSEA proporciona el transporte del contenedor y deberá hacerse cargo de los medios para descargarlo en la ubicación requerida'],
        'transport_tip'          => ['Cotizador', 'Sugerencia bajo transporte (HTML)', 'textarea', 'Recomendamos <strong>sin descarga</strong> si dispone de medios propios en destino (precio menor). Elija <strong>con descarga</strong> si necesita que el camión incluya grúa.'],
        'btn_calc_single'        => ['Cotizador', 'Botón calcular (producto)', 'text', 'Calcular precio de envío'],
        'btn_calc_consolidated'  => ['Cotizador', 'Botón calcular (selección)', 'text', 'Calcular precio de envío total'],
        'loader'                 => ['Cotizador', 'Texto "Calculando..."', 'text', 'Calculando...'],
        'result_label'           => ['Cotizador', 'Etiqueta del resultado', 'text', 'Coste de envío estimado'],
        'extras_title'           => ['Cotizador', 'Título "Extras disponibles"', 'text', 'Extras disponibles'],
        'color_label'            => ['Cotizador', 'Etiqueta "Color"', 'text', 'Color'],
        'bulk_notice'            => ['Cotizador', 'Aviso "más de 7 contenedores" (HTML)', 'textarea', '<strong>¿Necesita más de 7 contenedores?</strong><br>Póngase en contacto con nuestro equipo comercial enviando un correo a <a href="mailto:sales@silverseacontainers.com">sales@silverseacontainers.com</a>'],

        /* ── Mensajes y validaciones (toasts/errores) ── */
        'msg_quote_saved'        => ['Mensajes', 'Confirmación "Cotización guardada"', 'text', '✓ Cotización guardada'],
        'msg_quote_saved_sub'    => ['Mensajes', 'Subtítulo confirmación', 'text', 'Recibirá el detalle por email.'],
        'msg_pickup_saved'       => ['Mensajes', 'Confirmación "Recogida guardada"', 'text', '✓ Recogida guardada'],
        'msg_err_select_origin'  => ['Mensajes', 'Error: falta ciudad de salida', 'text', 'Por favor, seleccione la ciudad de salida.'],
        'msg_err_invalid_cp'     => ['Mensajes', 'Error: código postal inválido', 'text', 'Introduzca un código postal válido (5 dígitos).'],
        'msg_err_server'         => ['Mensajes', 'Error: respuesta inesperada', 'text', 'El servidor devolvió una respuesta inesperada. Recargue la página e inténtelo de nuevo.'],
        'msg_err_calc'           => ['Mensajes', 'Error: al calcular', 'text', 'Error al calcular. Inténtelo de nuevo.'],
        'msg_err_conn'           => ['Mensajes', 'Error: de conexión', 'text', 'Error de conexión. Inténtelo de nuevo.'],
        'msg_require_quote'      => ['Mensajes', 'Aviso: cotizar antes de añadir', 'text', 'Cotice el envío antes de añadir este contenedor a su selección.'],
        'msg_color_required'     => ['Mensajes', 'Aviso: elegir color', 'text', 'Seleccione un color RAL antes de añadir a su selección.'],
        'msg_delete_error'       => ['Mensajes', 'Error: al eliminar', 'text', 'Error al eliminar. Inténtelo de nuevo.'],
        'msg_pickup_free'        => ['Mensajes', 'Detalle recogida sin coste', 'text', 'Recogida en depósito sin coste adicional.'],
        'msg_no_tarifa'          => ['Mensajes', 'Error: sin tarifa (usar %1$s=CP, %2$s=ciudad)', 'textarea', 'No encontramos tarifa para el CP %1$s desde %2$s. Contáctenos para una cotización personalizada.'],

        /* ── Mi selección y página de gracias ── */
        'sel_form_title'         => ['Mi selección', 'Título "Su solicitud"', 'text', 'Su solicitud'],
        'sel_already_title'      => ['Mi selección', 'Título solicitud enviada', 'text', 'Su solicitud ya fue enviada'],
        'sel_already_sub'        => ['Mi selección', 'Subtítulo solicitud enviada', 'text', 'En breve recibirá un correo con todos los detalles.'],
        'sel_empty'              => ['Mi selección', 'Sin productos en la selección', 'text', 'No hay productos en su selección.'],
        'sel_btn_view'           => ['Mi selección', 'Botón ver selección', 'text', 'Ver mi selección'],
        'sel_empty_view'         => ['Mi selección', 'Vacío (página selección)', 'text', 'No hay productos en su selección.'],
        'sel_btn_products'       => ['Mi selección', 'Botón ver contenedores', 'text', 'Ver contenedores'],
        'thanks_order_title'     => ['Gracias', 'Título resumen del pedido', 'text', 'Resumen del pedido'],
        'thanks_contact_title'   => ['Gracias', 'Título datos de contacto', 'text', 'Datos de contacto'],
    ];
}

/* ══════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════ */

/** Devuelve el valor guardado de un texto, o su default. */
function silversea_text( $key ) {
    static $saved = null;
    if ( $saved === null ) $saved = get_option( 'silversea_texts', [] );
    if ( isset( $saved[ $key ] ) && $saved[ $key ] !== '' ) {
        return $saved[ $key ];
    }
    $defaults = silversea_text_defaults();
    return isset( $defaults[ $key ] ) ? $defaults[ $key ][3] : '';
}

/** Mapa resuelto clave => valor (para localize a JS y para el admin). */
function silversea_texts_all() {
    $out = [];
    foreach ( silversea_text_defaults() as $key => $def ) {
        $out[ $key ] = silversea_text( $key );
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════
   AJUSTES + PÁGINA ADMIN
══════════════════════════════════════════════════════════════ */

add_action( 'admin_init', function () {
    register_setting( 'silversea_texts_group', 'silversea_texts', [
        'type'              => 'array',
        'sanitize_callback' => 'silversea_texts_sanitize',
    ] );
} );

function silversea_texts_sanitize( $input ) {
    if ( ! is_array( $input ) ) return [];
    $defaults = silversea_text_defaults();
    $clean    = [];
    foreach ( $input as $key => $val ) {
        if ( ! isset( $defaults[ $key ] ) ) continue;     // solo claves conocidas
        $clean[ $key ] = wp_kses_post( wp_unslash( $val ) ); // HTML seguro permitido
    }
    return $clean;
}

add_action( 'admin_menu', function () {
    add_submenu_page(
        'silversea-cotizador',
        'Textos del cotizador',
        '📝 Textos',
        'manage_woocommerce',
        'silversea-textos',
        'silversea_texts_render_page'
    );
}, 20 ); // prioridad 20: asegura que el menú padre 'silversea-cotizador' ya exista

function silversea_texts_render_page() {
    $defaults = silversea_text_defaults();
    $saved    = get_option( 'silversea_texts', [] );

    /* Agrupar por grupo conservando el orden de definición */
    $groups = [];
    foreach ( $defaults as $key => $def ) {
        $groups[ $def[0] ][ $key ] = $def;
    }
    ?>
    <div class="wrap" style="max-width:880px;">
      <h1>📝 Textos del cotizador</h1>
      <p style="color:#6b7280;font-size:13px;margin-top:4px;">
        Edita los textos que ve el cliente. Se permite HTML básico (negritas, enlaces, saltos de línea).
        Deja un campo vacío para volver a su valor por defecto.
      </p>

      <form method="post" action="options.php">
        <?php settings_fields( 'silversea_texts_group' ); ?>

        <?php foreach ( $groups as $group_name => $items ) : ?>
          <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin:18px 0;">
            <h2 style="margin:0 0 14px;font-size:15px;"><?php echo esc_html( $group_name ); ?></h2>
            <table class="form-table" style="margin:0;">
              <?php foreach ( $items as $key => $def ) :
                  $label = $def[1];
                  $type  = $def[2];
                  $val   = isset( $saved[ $key ] ) ? $saved[ $key ] : $def[3];
              ?>
                <tr>
                  <th style="width:230px;padding:10px 0;vertical-align:top;">
                    <label for="sst_<?php echo esc_attr($key); ?>" style="font-weight:600;"><?php echo esc_html( $label ); ?></label>
                    <br><code style="font-size:10px;color:#9ca3af;"><?php echo esc_html($key); ?></code>
                  </th>
                  <td style="padding:10px 0;">
                    <?php if ( $type === 'textarea' ) : ?>
                      <textarea id="sst_<?php echo esc_attr($key); ?>"
                                name="silversea_texts[<?php echo esc_attr($key); ?>]"
                                rows="3" style="width:100%;max-width:560px;font-family:inherit;"><?php echo esc_textarea( $val ); ?></textarea>
                    <?php else : ?>
                      <input type="text" id="sst_<?php echo esc_attr($key); ?>"
                             name="silversea_texts[<?php echo esc_attr($key); ?>]"
                             value="<?php echo esc_attr( $val ); ?>"
                             style="width:100%;max-width:560px;">
                    <?php endif; ?>
                    <p style="margin:4px 0 0;font-size:11px;color:#b0b4bb;">Por defecto: <?php echo esc_html( mb_strimwidth( wp_strip_all_tags( $def[3] ), 0, 90, '…' ) ); ?></p>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php endforeach; ?>

        <?php submit_button( 'Guardar textos' ); ?>
      </form>
    </div>
    <?php
}
