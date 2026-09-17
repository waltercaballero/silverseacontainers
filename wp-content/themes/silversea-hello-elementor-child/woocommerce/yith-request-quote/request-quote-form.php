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

            <!-- País. Lista cerrada: el value debe coincidir letra por letra con el
                 picklist de Salesforce (campo 00NUm00000G445R). NO traducir los value,
                 solo la etiqueta visible. El value viaja también al campo estándar
                 country (hidden rqa-country-std, sincronizado por JS más abajo). -->
            <div class="silversea-form-group">
                <select class="silversea-input" name="rqa_country" id="rqa-country" required>
                    <option value="Spain" selected>España</option>
                    <option value="Portugal">Portugal</option>
                    <option value="France">Francia</option>
                    <option value="Italy">Italia</option>
                    <option value="Germany">Alemania</option>
                    <option value="United Kingdom">Reino Unido</option>
                    <option value="Mexico">México</option>
                    <option value="Colombia">Colombia</option>
                    <option value="Argentina">Argentina</option>
                    <option value="Brazil">Brasil</option>
                    <option value="Chile">Chile</option>
                    <option value="Uruguay">Uruguay</option>
                    <option value="Peru">Perú</option>
                    <option value="United States">Estados Unidos</option>
                    <option disabled>──────────</option>
                    <option value="Afghanistan">Afghanistan</option>
                    <option value="Albania">Albania</option>
                    <option value="Algeria">Argelia</option>
                    <option value="Andorra">Andorra</option>
                    <option value="Angola">Angola</option>
                    <option value="Antigua and Barbuda">Antigua and Barbuda</option>
                    <option value="Armenia">Armenia</option>
                    <option value="Australia">Australia</option>
                    <option value="Austria">Austria</option>
                    <option value="Azerbaijan">Azerbaijan</option>
                    <option value="Bahamas">Bahamas</option>
                    <option value="Bahrain">Bahrain</option>
                    <option value="Bangladesh">Bangladés</option>
                    <option value="Barbados">Barbados</option>
                    <option value="Belarus">Belarus</option>
                    <option value="Belgium">Bélgica</option>
                    <option value="Belize">Belize</option>
                    <option value="Benin">Benin</option>
                    <option value="Bhutan">Bhutan</option>
                    <option value="Bolivia">Bolivia</option>
                    <option value="Bosnia and Herzegovina">Bosnia and Herzegovina</option>
                    <option value="Botswana">Botswana</option>
                    <option value="Brunei">Brunei</option>
                    <option value="Bulgaria">Bulgaria</option>
                    <option value="Burkina Faso">Burkina Faso</option>
                    <option value="Burundi">Burundi</option>
                    <option value="Cabo Verde">Cabo Verde</option>
                    <option value="Cambodia">Cambodia</option>
                    <option value="Cameroon">Cameroon</option>
                    <option value="Canada">Canadá</option>
                    <option value="Central African Republic">Central African Republic</option>
                    <option value="Chad">Chad</option>
                    <option value="China">China</option>
                    <option value="Comoros">Comoros</option>
                    <option value="Congo (Congo-Brazzaville)">Congo (Congo-Brazzaville)</option>
                    <option value="Costa Rica">Costa Rica</option>
                    <option value="Croatia">Croacia</option>
                    <option value="Cuba">Cuba</option>
                    <option value="Cyprus">Cyprus</option>
                    <option value="Czech Republic">República Checa</option>
                    <option value="Democratic Republic of the Congo">Democratic Republic of the Congo</option>
                    <option value="Denmark">Dinamarca</option>
                    <option value="Djibouti">Djibouti</option>
                    <option value="Dominica">Dominica</option>
                    <option value="Dominican Republic">República Dominicana</option>
                    <option value="Ecuador">Ecuador</option>
                    <option value="Egypt">Egipto</option>
                    <option value="El Salvador">El Salvador</option>
                    <option value="Equatorial Guinea">Equatorial Guinea</option>
                    <option value="Eritrea">Eritrea</option>
                    <option value="Estonia">Estonia</option>
                    <option value="Eswatini">Eswatini</option>
                    <option value="Ethiopia">Ethiopia</option>
                    <option value="Fiji">Fiji</option>
                    <option value="Finland">Finlandia</option>
                    <option value="Gabon">Gabon</option>
                    <option value="Gambia">Gambia</option>
                    <option value="Georgia">Georgia</option>
                    <option value="Ghana">Ghana</option>
                    <option value="Greece">Grecia</option>
                    <option value="Grenada">Grenada</option>
                    <option value="Guatemala">Guatemala</option>
                    <option value="Guinea">Guinea</option>
                    <option value="Guinea-Bissau">Guinea-Bissau</option>
                    <option value="Guyana">Guyana</option>
                    <option value="Haiti">Haiti</option>
                    <option value="Honduras">Honduras</option>
                    <option value="Hungary">Hungría</option>
                    <option value="Iceland">Iceland</option>
                    <option value="India">India</option>
                    <option value="Indonesia">Indonesia</option>
                    <option value="Iran">Irán</option>
                    <option value="Iraq">Irak</option>
                    <option value="Ireland">Irlanda</option>
                    <option value="Israel">Israel</option>
                    <option value="Jamaica">Jamaica</option>
                    <option value="Japan">Japón</option>
                    <option value="Jordan">Jordania</option>
                    <option value="Kazakhstan">Kazakhstan</option>
                    <option value="Kenya">Kenia</option>
                    <option value="Kiribati">Kiribati</option>
                    <option value="Kuwait">Kuwait</option>
                    <option value="Kyrgyzstan">Kyrgyzstan</option>
                    <option value="Laos">Laos</option>
                    <option value="Latvia">Letonia</option>
                    <option value="Lebanon">Líbano</option>
                    <option value="Lesotho">Lesotho</option>
                    <option value="Liberia">Liberia</option>
                    <option value="Libya">Libya</option>
                    <option value="Liechtenstein">Liechtenstein</option>
                    <option value="Lithuania">Lituania</option>
                    <option value="Luxembourg">Luxembourg</option>
                    <option value="Madagascar">Madagascar</option>
                    <option value="Malawi">Malawi</option>
                    <option value="Malaysia">Malasia</option>
                    <option value="Maldives">Maldives</option>
                    <option value="Mali">Mali</option>
                    <option value="Malta">Malta</option>
                    <option value="Marshall Islands">Marshall Islands</option>
                    <option value="Mauritania">Mauritania</option>
                    <option value="Mauritius">Mauritius</option>
                    <option value="Micronesia">Micronesia</option>
                    <option value="Moldova">Moldova</option>
                    <option value="Monaco">Monaco</option>
                    <option value="Mongolia">Mongolia</option>
                    <option value="Montenegro">Montenegro</option>
                    <option value="Morocco">Marruecos</option>
                    <option value="Mozambique">Mozambique</option>
                    <option value="Myanmar (Burma)">Myanmar (Burma)</option>
                    <option value="Namibia">Namibia</option>
                    <option value="Nauru">Nauru</option>
                    <option value="Nepal">Nepal</option>
                    <option value="Netherlands">Países Bajos</option>
                    <option value="New Zealand">Nueva Zelanda</option>
                    <option value="Nicaragua">Nicaragua</option>
                    <option value="Niger">Niger</option>
                    <option value="Nigeria">Nigeria</option>
                    <option value="North Korea">North Korea</option>
                    <option value="North Macedonia">North Macedonia</option>
                    <option value="Norway">Noruega</option>
                    <option value="Oman">Oman</option>
                    <option value="Pakistan">Pakistán</option>
                    <option value="Palau">Palau</option>
                    <option value="Palestine State">Palestine State</option>
                    <option value="Panama">Panamá</option>
                    <option value="Papua New Guinea">Papua New Guinea</option>
                    <option value="Paraguay">Paraguay</option>
                    <option value="Philippines">Filipinas</option>
                    <option value="Poland">Polonia</option>
                    <option value="Qatar">Catar</option>
                    <option value="Romania">Rumanía</option>
                    <option value="Russia">Rusia</option>
                    <option value="Rwanda">Rwanda</option>
                    <option value="Saint Kitts and Nevis">Saint Kitts and Nevis</option>
                    <option value="Saint Lucia">Saint Lucia</option>
                    <option value="Saint Vincent and the Grenadines">Saint Vincent and the Grenadines</option>
                    <option value="Samoa">Samoa</option>
                    <option value="San Marino">San Marino</option>
                    <option value="Sao Tome and Principe">Sao Tome and Principe</option>
                    <option value="Saudi Arabia">Arabia Saudí</option>
                    <option value="Senegal">Senegal</option>
                    <option value="Serbia">Serbia</option>
                    <option value="Seychelles">Seychelles</option>
                    <option value="Sierra Leone">Sierra Leone</option>
                    <option value="Singapore">Singapur</option>
                    <option value="Slovakia">Eslovaquia</option>
                    <option value="Slovenia">Eslovenia</option>
                    <option value="Solomon Islands">Solomon Islands</option>
                    <option value="Somalia">Somalia</option>
                    <option value="South Africa">Sudáfrica</option>
                    <option value="South Korea">Corea del Sur</option>
                    <option value="South Sudan">South Sudan</option>
                    <option value="Sri Lanka">Sri Lanka</option>
                    <option value="Sudan">Sudan</option>
                    <option value="Suriname">Surinam</option>
                    <option value="Sweden">Suecia</option>
                    <option value="Switzerland">Suiza</option>
                    <option value="Syria">Syria</option>
                    <option value="Tajikistan">Tajikistan</option>
                    <option value="Tanzania">Tanzania</option>
                    <option value="Thailand">Tailandia</option>
                    <option value="Timor-Leste">Timor-Leste</option>
                    <option value="Togo">Togo</option>
                    <option value="Tonga">Tonga</option>
                    <option value="Trinidad and Tobago">Trinidad y Tobago</option>
                    <option value="Tunisia">Túnez</option>
                    <option value="Turkey">Turquía</option>
                    <option value="Turkmenistan">Turkmenistan</option>
                    <option value="Tuvalu">Tuvalu</option>
                    <option value="Uganda">Uganda</option>
                    <option value="Ukraine">Ucrania</option>
                    <option value="United Arab Emirates">Emiratos Árabes Unidos</option>
                    <option value="Uzbekistan">Uzbekistan</option>
                    <option value="Vanuatu">Vanuatu</option>
                    <option value="Vatican City">Vatican City</option>
                    <option value="Venezuela">Venezuela</option>
                    <option value="Vietnam">Vietnam</option>
                    <option value="Yemen">Yemen</option>
                    <option value="Zambia">Zambia</option>
                    <option value="Zimbabwe">Zimbabwe</option>
                </select>
            </div>
            <input type="hidden" id="rqa-country-std" name="rqa_country_std" value="">

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

            <!-- Idioma del formulario y datos de atribución (UTM) — se populan por JS
                 más abajo. gclid queda deshabilitado hasta que el sitio tenga un
                 gestor de consentimiento de cookies (ver comentario en el script). -->
            <input type="hidden" id="rqa-form-language" name="rqa_form_language" value="ES">
            <input type="hidden" id="rqa-utm-source"    name="rqa_utm_source"    value="">
            <input type="hidden" id="rqa-utm-medium"    name="rqa_utm_medium"    value="">
            <input type="hidden" id="rqa-utm-campaign"  name="rqa_utm_campaign"  value="">
            <input type="hidden" id="rqa-utm-term"      name="rqa_utm_term"      value="">
            <input type="hidden" id="rqa-utm-content"   name="rqa_utm_content"   value="">
            <input type="hidden" id="rqa-gclid"         name="rqa_gclid"         value="">

            <script>
            (function() {
                /* País → campo estándar (mismo patrón que usa Salesforce internamente:
                   el picklist se usa para segmentar, el estándar para mapa/geo). */
                var pais    = document.getElementById('rqa-country');
                var paisStd = document.getElementById('rqa-country-std');
                function syncCountry() { if (pais && paisStd) paisStd.value = pais.value; }
                if (pais) { pais.addEventListener('change', syncCountry); syncCountry(); }

                /* Idioma de la versión del sitio. Hoy el sitio es solo ES; queda
                   preparado para cuando existan versiones EN/PT. */
                var lang    = (document.documentElement.lang || 'es').slice(0, 2).toLowerCase();
                var langMap = { es: 'ES', en: 'EN', pt: 'PT' };
                var langEl  = document.getElementById('rqa-form-language');
                if (langEl && langMap[lang]) langEl.value = langMap[lang];

                /* Atribución de campaña (UTM). Se guardan en sessionStorage para
                   sobrevivir la navegación si el visitante entra por una landing
                   y llega al cotizador varias páginas después. */
                var utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
                var params  = new URLSearchParams(window.location.search);

                utmKeys.forEach(function(k) {
                    var v = params.get(k);
                    if (v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
                });
                utmKeys.forEach(function(k) {
                    var el = document.getElementById('rqa-' + k.replace(/_/g, '-'));
                    if (!el) return;
                    var v = null;
                    try { v = sessionStorage.getItem(k); } catch (e) {}
                    if (v) el.value = v;
                });

                /* gclid: dato personal de Google Ads. Salesforce lo acepta
                   (00NUm00000WuUnP) pero el sitio todavía no tiene un gestor de
                   consentimiento de cookies instalado, así que por ahora NO se
                   captura. Cuando exista un CMP, reemplazar el `return false`
                   por la comprobación real de consentimiento de marketing. */
                function hasMarketingConsent() { return false; }
                if (hasMarketingConsent()) {
                    var gclid = params.get('gclid');
                    if (gclid) { try { sessionStorage.setItem('gclid', gclid); } catch (e) {} }
                    var gclidEl = document.getElementById('rqa-gclid');
                    var gclidVal = null;
                    try { gclidVal = sessionStorage.getItem('gclid'); } catch (e) {}
                    if (gclidEl && gclidVal) gclidEl.value = gclidVal;
                }
            })();
            </script>

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
