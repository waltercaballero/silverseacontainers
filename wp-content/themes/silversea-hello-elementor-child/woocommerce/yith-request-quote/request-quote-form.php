<?php
/**
 * Template override: Formulario de cotización
 * Path: wp-content/themes/silversea-hello-elementor-child/woocommerce/yith-request-quote/request-quote-form.php
 * @version 1.3.0
 */

$ywraq_current_user = [];
if ( is_user_logged_in() ) {
    $ywraq_current_user = get_user_by( 'id', get_current_user_id() );
}
$user_name  = ! empty($ywraq_current_user) ? $ywraq_current_user->display_name : '';
$user_mail  = ! empty($ywraq_current_user) ? $ywraq_current_user->user_email   : '';
$user_phone = ! empty($ywraq_current_user) ? get_user_meta( get_current_user_id(), 'billing_phone',    true ) : '';
$user_city  = ! empty($ywraq_current_user) ? get_user_meta( get_current_user_id(), 'billing_city',     true ) : '';
$user_cp    = ! empty($ywraq_current_user) ? get_user_meta( get_current_user_id(), 'billing_postcode', true ) : '';

/* Si ya cotizó (hay datos de envío en sesión), no necesita volver a calcular */
$ya_cotizo = WC()->session && WC()->session->get('silversea_shipping_data');
?>

<div class="silversea-raq-form-wrap">
    <div class="silversea-raq-form-card">
        <form id="yith-ywraq-mail-form" name="yith-ywraq-mail-form"
              action="<?php echo esc_url( YITH_Request_Quote()->get_raq_page_url() ); ?>"
              method="post">

            <!-- Tipo de cliente -->
            <div class="silversea-form-group silversea-client-type">
                <label class="silversea-radio-label">
                    <input type="radio" name="rqa_client_type" value="empresa" checked> Empresa
                </label>
                <label class="silversea-radio-label">
                    <input type="radio" name="rqa_client_type" value="particular"> Particular
                </label>
            </div>

            <!-- Razón social / Nombre y Apellido -->
            <div class="silversea-form-group">
                <label id="rqa-name-label" for="rqa-name" style="display:block;margin-bottom:5px;font-size:13px;font-weight:600;color:#374151;">Razón Social</label>
                <input type="text" class="silversea-input" name="rqa_name" id="rqa-name"
                       placeholder="Razón Social"
                       value="<?php echo esc_attr($user_name); ?>" required>
            </div>

            <script>
            (function() {
                var radios = document.querySelectorAll('[name="rqa_client_type"]');
                var label  = document.getElementById('rqa-name-label');
                var input  = document.getElementById('rqa-name');
                var labels = { empresa: 'Razón Social', particular: 'Nombre y Apellido' };

                function update(val) {
                    var text = labels[val] || 'Nombre';
                    if (label) label.textContent = text;
                    if (input) input.placeholder  = text;
                }

                radios.forEach(function(r) {
                    r.addEventListener('change', function() { update(this.value); });
                    if (r.checked) update(r.value);
                });
            })();
            </script>

            <!-- Email -->
            <div class="silversea-form-group">
                <input type="email" class="silversea-input" name="rqa_email" id="rqa-email"
                       placeholder="Correo Electrónico"
                       value="<?php echo esc_attr($user_mail); ?>" required>
            </div>

            <!-- Teléfono -->
            <div class="silversea-form-group silversea-phone-group">
                <div class="silversea-phone-prefix">
                    <select name="rqa_phone_prefix" class="silversea-select-prefix">
                        <option value="+34">🇪🇸 +34</option>
                        <option value="+54">🇦🇷 +54</option>
                        <option value="+1">🇺🇸 +1</option>
                        <option value="+44">🇬🇧 +44</option>
                        <option value="+33">🇫🇷 +33</option>
                        <option value="+49">🇩🇪 +49</option>
                        <option value="+39">🇮🇹 +39</option>
                        <option value="+55">🇧🇷 +55</option>
                        <option value="+52">🇲🇽 +52</option>
                    </select>
                </div>
                <input type="tel" class="silversea-input" name="rqa_phone" id="rqa-phone"
                       placeholder="Teléfono"
                       value="<?php echo esc_attr($user_phone); ?>" required>
            </div>

            <!-- Ciudad y CP -->
            <div class="silversea-form-row-2">
                <div class="silversea-form-group">
                    <input type="text" class="silversea-input" name="rqa_city" id="rqa-city"
                           placeholder="Ciudad"
                           value="<?php echo esc_attr($user_city); ?>">
                </div>
                <div class="silversea-form-group">
                    <input type="text" class="silversea-input" name="rqa_postal" id="rqa-postal"
                           placeholder="Código Postal"
                           value="<?php echo esc_attr($user_cp); ?>">
                </div>
            </div>

            <!-- Mensaje -->
            <div class="silversea-form-group">
                <textarea class="silversea-input silversea-textarea" name="rqa_message" id="rqa-message"
                          placeholder="Mensaje (opcional)" rows="3"></textarea>
            </div>

            <!-- El cotizador se muestra fuera del form (en silversea_quote_form shortcode).
                 Aquí solo mantenemos los hidden fields que se sincronizan via JS. -->

            <!-- Hidden fields — se populan desde shipping-calculator.js cuando el usuario calcula -->
            <input type="hidden" id="rqa-shipping-method"    name="rqa_shipping_method"     value="">
            <input type="hidden" id="rqa-shipping-origin"    name="rqa_shipping_origin"      value="">
            <input type="hidden" id="rqa-shipping-cp"        name="rqa_shipping_cp"          value="">
            <input type="hidden" id="rqa-shipping-transport" name="rqa_shipping_transport"   value="">
            <input type="hidden" id="rqa-shipping-price"     name="rqa_shipping_price"       value="">
            <input type="hidden" id="rqa-shipping-pickup"    name="rqa_shipping_pickup_city" value="">

            <!-- Privacy -->
            <?php if ( 'yes' === get_option('ywraq_add_privacy_checkbox', 'no') ) : ?>
                <div class="silversea-form-group silversea-privacy">
                    <label class="silversea-check-label">
                        <input type="checkbox" name="rqa_privacy" id="rqa_privacy" required>
                        <?php echo wp_kses_post( ywraq_replace_policy_page_link_placeholders( get_option('ywraq_privacy_label') ) ); ?>
                        <abbr class="required" title="required">*</abbr>
                    </label>
                </div>
            <?php endif; ?>

            <!-- Términos Silversea -->
            <div class="silversea-form-group silversea-privacy">
                <label class="silversea-check-label">
                    <input type="checkbox" name="rqa_terms" required>
                    <a href="<?php echo esc_url(get_privacy_policy_url()); ?>" target="_blank">
                        Acepto t&eacute;rminos y condiciones generales de Silversea.
                    </a>
                    <abbr class="required" title="required">*</abbr>
                </label>
            </div>

            <!-- Nonce YITH -->
            <input type="hidden" id="raq-mail-wpnonce" name="raq_mail_wpnonce"
                   value="<?php echo esc_attr(wp_create_nonce('send-request-quote')); ?>">

            <button type="submit" class="silversea-btn-primary raq-send-request">
                Rellenar una solicitud
            </button>

        </form>
    </div>
</div>

<?php /*
   La sincronización de los campos ocultos de envío la maneja el plugin:
   shipping-calculator.js → scSyncFormFields() los completa por ID
   (rqa-shipping-*) cuando el cliente calcula/guarda el envío, y además
   guarda los datos en la sesión de WC como respaldo. El toggle "Recalcular"
   lo maneja el propio shortcode [silversea_quote_form] (#silversea-recotizar-form).
   No se necesita JS adicional acá.
*/ ?>
