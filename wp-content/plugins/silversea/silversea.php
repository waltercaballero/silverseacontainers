<?php
/*
Plugin Name: Silversea Containers
Description: Personalizaciones y funcionalidades espec&iacute;ficas del sitio.
Author: Walter D. Caballero
Version: 1.0
*/

add_filter( 'auto_update_plugin', '__return_false' );

add_filter( 'auto_update_theme', '__return_false' );

define( 'WP_AUTO_UPDATE_CORE', false );





add_filter( 'upload_mimes', function( $file_types ) {
	$new_filetypes = array();

	$new_filetypes['svg'] = 'image/svg+xml';

	$file_types = array_merge( $file_types, $new_filetypes );

	return $file_types;
} );





add_action( 'wp_head', function() {
	?>
	<style>
		.grecaptcha-badge {
			bottom: 120px !important;
		}
	</style>
	<?php

	if ( is_shop() || is_product() || is_product_category() || is_product_tag() ) {
		echo '<style>.lang-section { display: none !important; }</style>';
	}
} );









add_action('parse_request', function( $wp ) {
    $current_url = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?: '/';

	$available_countries = array_keys( lcs_get_countries() );

	if ( preg_match('/^\/(es|br|fr|en|zh)\/(' . implode( '|', $available_countries ) . ')\/?$/i', $current_url, $matches) ) {
        $language = $matches[1];
        $country = $matches[2];

        //error_log('language: ' . $language . ' -- country: ' . $country);

        setcookie('lcs_country', $country, time() + (30 * DAY_IN_SECONDS), '/');

		wp_redirect( home_url('/') );
        exit;
    }

	if ( preg_match('/^\/(es|br|fr|en|zh)(?:\/([a-z-]+))?\/?$/i', $current_url, $matches) ) {
        $language = $matches[1];

		$map = [
			'es' => 'es',
			'br' => 'br',
			'fr' => 'fr',
			'en' => 'gb',
			'zh' => 'zh',
		];

		$country = $map[ $language ] ?? 'es';

        //error_log('language: ' . $language . ' -- country: ' . $country);

        setcookie('lcs_country', $country, time() + (30 * DAY_IN_SECONDS), '/');
    }
});





function lcs_show_flag() {
	$html = '';
    $country = isset($_COOKIE['lcs_country']) ? $_COOKIE['lcs_country'] : null;

    if ($country) {
        //$flag_url = plugin_dir_url(__FILE__) . 'flags/' . $country . '.png';

		if ( $flag_url = get_option('lcs_flag_' . $country) ) {
			$html = '<img src="' . esc_url($flag_url) . '" alt="Silversea Containers ' . strtoupper( $country ) . '" class="open-store-popup" id="open-store-popup">';
		}
    }

    return $html;
}
add_shortcode( 'lcs_flag', 'lcs_show_flag' );

function lcs_flush_rewrite_rules() {
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'lcs_flush_rewrite_rules' );
register_deactivation_hook( __FILE__, 'lcs_flush_rewrite_rules' );



function lcs_get_countries() {
    return [
        'ar' => 'Argentina',
        'uy' => 'Uruguay',
        'co' => 'Colombia',
        'do' => 'Rep&uacute;blica Dominicana',
        'cr' => 'Costa Rica',
        'es' => 'Espa&ntilde;a',
        'br' => 'Brasil',
        'fr' => 'Francia',
        'zh' => 'China',
        'us' => 'Estados Unidos',
        'gb' => 'Reino Unido',
        'ea' => 'Emiratos &Aacute;rabes',
    ];
}

function lcs_register_country_flags() {
    foreach (lcs_get_countries() as $code => $name) {
        register_setting('lcs_flags_group', "lcs_flag_$code");
    }
}
add_action('admin_init', 'lcs_register_country_flags');

function lcs_admin_page_setup() {
    add_menu_page(
        'Configuraci&oacute;n de Banderas',
        'Banderas',
        'manage_options',
        'lcs-flags',
        'lcs_render_admin_page',
        'dashicons-flag',
        20
    );
}
add_action('admin_menu', 'lcs_admin_page_setup');

function lcs_render_admin_page() {
    ?>
    <div class="wrap">
        <h1>Configuraci&oacute;n de Banderas por Pa&iacute;s</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('lcs_flags_group');
            do_settings_sections('lcs_flags_group');
            ?>

            <table class="form-table">
                <?php foreach (lcs_get_countries() as $code => $name): ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($name); ?></th>
                        <td>
                            <input type="hidden" id="lcs_flag_<?php echo esc_attr($code); ?>" name="lcs_flag_<?php echo esc_attr($code); ?>" value="<?php echo esc_attr(get_option("lcs_flag_$code")); ?>" />
                            <img id="lcs_flag_preview_<?php echo esc_attr($code); ?>" src="<?php echo esc_url(get_option("lcs_flag_$code")); ?>" style="max-width: 100px; max-height: 50px; display: block; margin-bottom: 10px;" />
                            <button type="button" class="button lcs-upload-image" data-target="lcs_flag_<?php echo esc_attr($code); ?>">Seleccionar Bandera</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function lcs_enqueue_admin_scripts($hook) {
    if ($hook !== 'toplevel_page_lcs-flags') {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script(
        'lcs-admin-scripts',
        plugin_dir_url(__FILE__) . 'assets/js/admin-scripts.js',
        ['jquery'],
        null,
        true
    );
}
add_action('admin_enqueue_scripts', 'lcs_enqueue_admin_scripts');



add_filter('body_class', 'lcs_add_country_class');

function lcs_add_country_class($classes) {
    if (isset($_COOKIE['lcs_country']) && !empty($_COOKIE['lcs_country'])) {
        $country = sanitize_text_field($_COOKIE['lcs_country']);
        $classes[] = 'store-' . strtolower($country);
    }

    return $classes;
}





function lcs_enqueue_flag_dropdown_assets() {
    $css_path = plugin_dir_url(__FILE__) . 'assets/css/styles.css';
    $js_path = plugin_dir_url(__FILE__) . 'assets/js/scripts.js';

    wp_enqueue_style('flags-dropdown-style', $css_path, [], '1.0.0');

    wp_enqueue_script('flags-dropdown-script', $js_path, ['jquery'], '1.0.0', true);
}
add_action('wp_enqueue_scripts', 'lcs_enqueue_flag_dropdown_assets');





function replace_dynamic_menu_links_manual($item_output, $item, $depth, $args) {
    if (preg_match('/##CURRENT_LINK_([a-z]{2})##/', $item_output, $matches)) {
		$target_lang = strtolower( $matches[1] );

		/*
		$current_url = $_SERVER['REQUEST_URI'];
		preg_match('/^\/([a-zA-Z]{2})\//', $current_url, $matches);
		$current_lang = isset($matches[1]) ? $matches[1] : 'es';
		*/

		$request_uri = $_SERVER['REQUEST_URI'];
		$base_url = '/';
		if (strpos($request_uri, $base_url) === 0) {
			$request_uri = substr($request_uri, strlen($base_url));
		}
		$path = trim($request_uri, '/');
		$parts = explode('/', $path);

		$current_lang = isset($parts[0]) ? $parts[0] : 'es';

        $translations = [
			'es' => [
				'alquiler-de-contenedores-maritimos-one-way' => [
					'en' => 'one-way-maritime-container-rental',
					'pt' => 'aluguel-conteineres-one-way',
					'fr' => 'location-conteneurs-one-way',
					'zh' => '%e5%8d%95%e7%a8%8b%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81',
				],
				'blog' => [
					'en' => 'blog',
					'pt' => 'blog',
					'fr' => 'blog',
					'zh' => '%e5%8d%9a%e5%ae%a2',
				],
				'compra-de-contenedores' => [
					'en' => 'buy-maritime-containers',
					'pt' => 'compra-conteineres-maritimos',
					'fr' => 'achat-conteneurs-maritimes',
					'zh' => '%e8%b4%ad%e4%b9%b0%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'contacto' => [
					'en' => 'contact',
					'pt' => 'contato',
					'fr' => 'contact',
					'zh' => '%e8%81%94%e7%b3%bb%e6%88%91%e4%bb%ac',
				],
				'contenedores-maritimos-particulares' => [
					'en' => 'maritime-containers-private-buyers',
					'pt' => 'conteineres-maritimos-particulares',
					'fr' => 'conteneurs-maritimes-particuliers',
					'zh' => '%e7%a7%81%e4%ba%ba%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'cotizar-contenedores-maritimos' => [
					'en' => 'get-a-quote-maritime-containers',
					'pt' => 'orcamento-conteineres-maritimos',
					'fr' => 'devis-conteneurs-maritimes',
					'zh' => '%e8%8e%b7%e5%8f%96%e6%8a%a5',
				],
				'gracias' => [
					'en' => 'thank-you',
					'pt' => 'obrigado',
					'fr' => 'merci',
					'zh' => '%e6%84%9f%e8%b0%a2',
				],
				'inicio' => [
					'en' => '',
					'pt' => '',
					'fr' => '',
					'zh' => '',
				],
				'leasing-de-contenedores' => [
					'en' => 'maritime-container-leasing',
					'pt' => 'leasing-conteineres-maritimos',
					'fr' => 'location-de-conteneurs-maritimes',
					'zh' => '%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81',
				],
				'nosotros' => [
					'en' => 'about-us',
					'pt' => 'sobre-nos',
					'fr' => 'a-propos',
					'zh' => '%e5%85%b3%e4%ba%8e%e6%88%91%e4%bb%ac',
				],
				'nuestros-clientes' => [
					'en' => 'our-clients',
					'pt' => 'nossos-clientes',
					'fr' => 'nos-clients',
					'zh' => '%e6%88%91%e4%bb%ac%e7%9a%84%e5%ae%a2%e6%88%b7',
				],
				'nuestros-contenedores-maritimos' => [
					'en' => 'our-maritime-containers',
					'pt' => 'nossos-conteineres-maritimos',
					'fr' => 'nos-conteneurs-maritimes',
					'zh' => '%e6%88%91%e4%bb%ac%e7%9a%84%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'preguntas-frecuentes-contenedores-maritimos' => [
					'en' => 'faqs-maritime-containers',
					'pt' => 'perguntas-frequentes-conteineres',
					'fr' => 'faq-conteneurs-maritimes',
					'zh' => '%e5%b8%b8%e8%a7%81%e9%97%ae%e9%a2%98',
				],
				'recompra-de-contenedores' => [
					'en' => 'maritime-container-buyback',
					'pt' => 'recompra-conteineres-maritimos',
					'fr' => 'reprise-conteneurs-maritimes',
					'zh' => '%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e5%9b%9e%e8%b4%ad%e8%ae%a1%e5%88%92',
				],
			],

			'en' => [
				'one-way-maritime-container-rental' => [
					'es' => 'alquiler-de-contenedores-maritimos-one-way',
					'pt' => 'aluguel-conteineres-one-way',
					'fr' => 'location-conteneurs-one-way',
					'zh' => '%e5%8d%95%e7%a8%8b%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81',
				],
				'blog' => [
					'es' => 'blog',
					'pt' => 'blog',
					'fr' => 'blog',
					'zh' => '%e5%8d%9a%e5%ae%a2',
				],
				'buy-maritime-containers' => [
					'es' => 'compra-de-contenedores',
					'pt' => 'compra-conteineres-maritimos',
					'fr' => 'achat-conteneurs-maritimes',
					'zh' => '%e8%b4%ad%e4%b9%b0%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'contact' => [
					'es' => 'contacto',
					'pt' => 'contato',
					'fr' => 'contact',
					'zh' => '%e8%81%94%e7%b3%bb%e6%88%91%e4%bb%ac',
				],
				'maritime-containers-private-buyers' => [
					'es' => 'contenedores-maritimos-particulares',
					'pt' => 'conteineres-maritimos-particulares',
					'fr' => 'conteneurs-maritimes-particuliers',
					'zh' => '%e7%a7%81%e4%ba%ba%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'get-a-quote-maritime-containers' => [
					'es' => 'cotizar-contenedores-maritimos',
					'pt' => 'orcamento-conteineres-maritimos',
					'fr' => 'devis-conteneurs-maritimes',
					'zh' => '%e8%8e%b7%e5%8f%96%e6%8a%a5',
				],
				'thank-you' => [
					'es' => 'gracias',
					'pt' => 'obrigado',
					'fr' => 'merci',
					'zh' => '%e6%84%9f%e8%b0%a2',
				],
				'home' => [
					'es' => '',
					'pt' => '',
					'fr' => '',
					'zh' => '',
				],
				'maritime-container-leasing' => [
					'es' => 'leasing-de-contenedores',
					'pt' => 'leasing-conteineres-maritimos',
					'fr' => 'location-de-conteneurs-maritimes',
					'zh' => '%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81',
				],
				'about-us' => [
					'es' => 'nosotros',
					'pt' => 'sobre-nos',
					'fr' => 'a-propos',
					'zh' => '%e5%85%b3%e4%ba%8e%e6%88%91%e4%bb%ac',
				],
				'our-clients' => [
					'es' => 'nuestros-clientes',
					'pt' => 'nossos-clientes',
					'fr' => 'nos-clients',
					'zh' => '%e6%88%91%e4%bb%ac%e7%9a%84%e5%ae%a2%e6%88%b7',
				],
				'our-maritime-containers' => [
					'es' => 'nuestros-contenedores-maritimos',
					'pt' => 'nossos-conteineres-maritimos',
					'fr' => 'nos-conteneurs-maritimes',
					'zh' => '%e6%88%91%e4%bb%ac%e7%9a%84%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'faqs-maritime-containers' => [
					'es' => 'preguntas-frecuentes-contenedores-maritimos',
					'pt' => 'perguntas-frequentes-conteineres',
					'fr' => 'faq-conteneurs-maritimes',
					'zh' => '%e5%b8%b8%e8%a7%81%e9%97%ae%e9%a2%98',
				],
				'maritime-container-buyback' => [
					'es' => 'recompra-de-contenedores',
					'pt' => 'recompra-conteineres-maritimos',
					'fr' => 'reprise-conteneurs-maritimes',
					'zh' => '%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e5%9b%9e%e8%b4%ad%e8%ae%a1%e5%88%92',
				],
			],

			'pt' => [
				'aluguel-conteineres-one-way' => [
					'es' => 'alquiler-de-contenedores-maritimos-one-way',
					'en' => 'one-way-maritime-container-rental',
					'fr' => 'location-conteneurs-one-way',
					'zh' => '%e5%8d%95%e7%a8%8b%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81',
				],
				'blog' => [
					'es' => 'blog',
					'en' => 'blog',
					'fr' => 'blog',
					'zh' => '%e5%8d%9a%e5%ae%a2',
				],
				'compra-conteineres-maritimos' => [
					'es' => 'compra-de-contenedores',
					'en' => 'buy-maritime-containers',
					'fr' => 'achat-conteneurs-maritimes',
					'zh' => '%e8%b4%ad%e4%b9%b0%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'contato' => [
					'es' => 'contacto',
					'en' => 'contact',
					'fr' => 'contact',
					'zh' => '%e8%81%94%e7%b3%bb%e6%88%91%e4%bb%ac',
				],
				'conteineres-maritimos-particulares' => [
					'es' => 'contenedores-maritimos-particulares',
					'en' => 'maritime-containers-private-buyers',
					'fr' => 'conteneurs-maritimes-particuliers',
					'zh' => '%e7%a7%81%e4%ba%ba%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'orcamento-conteineres-maritimos' => [
					'es' => 'cotizar-contenedores-maritimos',
					'en' => 'get-a-quote-maritime-containers',
					'fr' => 'devis-conteneurs-maritimes',
					'zh' => '%e8%8e%b7%e5%8f%96%e6%8a%a5',
				],
				'obrigado' => [
					'es' => 'gracias',
					'en' => 'thank-you',
					'fr' => 'merci',
					'zh' => '%e6%84%9f%e8%b0%a2',
				],
				'inicio' => [
					'es' => '',
					'en' => '',
					'fr' => '',
					'zh' => '',
				],
				'leasing-conteineres-maritimos' => [
					'es' => 'leasing-de-contenedores',
					'en' => 'maritime-container-leasing',
					'fr' => 'location-de-conteneurs-maritimes',
					'zh' => '%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81',
				],
				'sobre-nos' => [
					'es' => 'nosotros',
					'en' => 'about-us',
					'fr' => 'a-propos',
					'zh' => '%e5%85%b3%e4%ba%8e%e6%88%91%e4%bb%ac',
				],
				'nossos-clientes' => [
					'es' => 'nuestros-clientes',
					'en' => 'our-clients',
					'fr' => 'nos-clients',
					'zh' => '%e6%88%91%e4%bb%ac%e7%9a%84%e5%ae%a2%e6%88%b7',
				],
				'nossos-conteineres-maritimos' => [
					'es' => 'nuestros-contenedores-maritimos',
					'en' => 'our-maritime-containers',
					'fr' => 'nos-conteneurs-maritimes',
					'zh' => '%e6%88%91%e4%bb%ac%e7%9a%84%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'perguntas-frequentes-conteineres' => [
					'es' => 'preguntas-frecuentes-contenedores-maritimos',
					'en' => 'faqs-maritime-containers',
					'fr' => 'faq-conteneurs-maritimes',
					'zh' => '%e5%b8%b8%e8%a7%81%e9%97%ae%e9%a2%98',
				],
				'recompra-conteineres-maritimos' => [
					'es' => 'recompra-de-contenedores',
					'en' => 'maritime-container-buyback',
					'fr' => 'reprise-conteneurs-maritimes',
					'zh' => '%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e5%9b%9e%e8%b4%ad%e8%ae%a1%e5%88%92',
				],
			],

			'fr' => [
				'location-conteneurs-one-way' => [
					'es' => 'alquiler-de-contenedores-maritimos-one-way',
					'en' => 'one-way-maritime-container-rental',
					'pt' => 'aluguel-conteineres-one-way',
					'zh' => '%e5%8d%95%e7%a8%8b%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81',
				],
				'blog' => [
					'es' => 'blog',
					'en' => 'blog',
					'pt' => 'blog',
					'zh' => '%e5%8d%9a%e5%ae%a2',
				],
				'achat-conteneurs-maritimes' => [
					'es' => 'compra-de-contenedores',
					'en' => 'buy-maritime-containers',
					'pt' => 'compra-conteineres-maritimos',
					'zh' => '%e8%b4%ad%e4%b9%b0%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'contact' => [
					'es' => 'contacto',
					'en' => 'contact',
					'pt' => 'contato',
					'zh' => '%e8%81%94%e7%b3%bb%e6%88%91%e4%bb%ac',
				],
				'conteneurs-maritimes-particuliers' => [
					'es' => 'contenedores-maritimos-particulares',
					'en' => 'maritime-containers-private-buyers',
					'pt' => 'conteineres-maritimos-particulares',
					'zh' => '%e7%a7%81%e4%ba%ba%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'devis-conteneurs-maritimes' => [
					'es' => 'cotizar-contenedores-maritimos',
					'en' => 'get-a-quote-maritime-containers',
					'pt' => 'orcamento-conteineres-maritimos',
					'zh' => '%e8%8e%b7%e5%8f%96%e6%8a%a5',
				],
				'merci' => [
					'es' => 'gracias',
					'en' => 'thank-you',
					'pt' => 'obrigado',
					'zh' => '%e6%84%9f%e8%b0%a2',
				],
				'accueil' => [
					'es' => '',
					'en' => '',
					'pt' => '',
					'zh' => '',
				],
				'location-de-conteneurs-maritimes' => [
					'es' => 'leasing-de-contenedores',
					'en' => 'maritime-container-leasing',
					'pt' => 'leasing-conteineres-maritimos',
					'zh' => '%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81',
				],
				'a-propos' => [
					'es' => 'nosotros',
					'en' => 'about-us',
					'pt' => 'sobre-nos',
					'zh' => '%e5%85%b3%e4%ba%8e%e6%88%91%e4%bb%ac',
				],
				'nos-clients' => [
					'es' => 'nuestros-clientes',
					'en' => 'our-clients',
					'pt' => 'nossos-clientes',
					'zh' => '%e6%88%91%e4%bb%ac%e7%9a%84%e5%ae%a2%e6%88%b7',
				],
				'nos-conteneurs-maritimes' => [
					'es' => 'nuestros-contenedores-maritimos',
					'en' => 'our-maritime-containers',
					'pt' => 'nossos-conteineres-maritimos',
					'zh' => '%e6%88%91%e4%bb%ac%e7%9a%84%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1',
				],
				'faq-conteneurs-maritimes' => [
					'es' => 'preguntas-frecuentes-contenedores-maritimos',
					'en' => 'faqs-maritime-containers',
					'pt' => 'perguntas-frequentes-conteineres',
					'zh' => '%e5%b8%b8%e8%a7%81%e9%97%ae%e9%a2%98',
				],
				'reprise-conteneurs-maritimes' => [
					'es' => 'recompra-de-contenedores',
					'en' => 'maritime-container-buyback',
					'pt' => 'recompra-conteineres-maritimos',
					'zh' => '%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e5%9b%9e%e8%b4%ad%e8%ae%a1%e5%88%92',
				],
			],

			'zh' => [
				'%e5%8d%95%e7%a8%8b%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81' => [
					'es' => 'alquiler-de-contenedores-maritimos-one-way',
					'en' => 'one-way-maritime-container-rental',
					'pt' => 'aluguel-conteineres-one-way',
					'fr' => 'location-conteneurs-one-way',
				],
				'%e5%8d%9a%e5%ae%a2' => [
					'es' => 'blog',
					'en' => 'blog',
					'pt' => 'blog',
					'fr' => 'blog',
				],
				'%e8%b4%ad%e4%b9%b0%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1' => [
					'es' => 'compra-de-contenedores',
					'en' => 'buy-maritime-containers',
					'pt' => 'compra-conteineres-maritimos',
					'fr' => 'achat-conteneurs-maritimes',
				],
				'%e8%81%94%e7%b3%bb%e6%88%91%e4%bb%ac' => [
					'es' => 'contacto',
					'en' => 'contact',
					'pt' => 'contato',
					'fr' => 'contact',
				],
				'%e7%a7%81%e4%ba%ba%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1' => [
					'es' => 'contenedores-maritimos-particulares',
					'en' => 'maritime-containers-private-buyers',
					'pt' => 'conteineres-maritimos-particulares',
					'fr' => 'conteneurs-maritimes-particuliers',
				],
				'%e8%8e%b7%e5%8f%96%e6%8a%a5' => [
					'es' => 'cotizar-contenedores-maritimos',
					'en' => 'get-a-quote-maritime-containers',
					'pt' => 'orcamento-conteineres-maritimos',
					'fr' => 'devis-conteneurs-maritimes',
				],
				'%e6%84%9f%e8%b0%a2' => [
					'es' => 'gracias',
					'en' => 'thank-you',
					'pt' => 'obrigado',
					'fr' => 'merci',
				],
				'%e9%a6%96%e9%a1%b5' => [
					'es' => '',
					'en' => '',
					'pt' => '',
					'fr' => '',
				],
				'%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e7%a7%9f%e8%b5%81' => [
					'es' => 'leasing-de-contenedores',
					'en' => 'maritime-container-leasing',
					'pt' => 'leasing-conteineres-maritimos',
					'fr' => 'location-de-conteneurs-maritimes',
				],
				'%e5%85%b3%e4%ba%8e%e6%88%91%e4%bb%ac' => [
					'es' => 'nosotros',
					'en' => 'about-us',
					'pt' => 'sobre-nos',
					'fr' => 'a-propos',
				],
				'%e6%88%91%e4%bb%ac%e7%9a%84%e5%ae%a2%e6%88%b7' => [
					'es' => 'nuestros-clientes',
					'en' => 'our-clients',
					'pt' => 'nossos-clientes',
					'fr' => 'nos-clients',
				],
				'%e6%88%91%e4%bb%ac%e7%9a%84%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1' => [
					'es' => 'nuestros-contenedores-maritimos',
					'en' => 'our-maritime-containers',
					'pt' => 'nossos-conteineres-maritimos',
					'fr' => 'nos-conteneurs-maritimes',
				],
				'%e5%b8%b8%e8%a7%81%e9%97%ae%e9%a2%98' => [
					'es' => 'preguntas-frecuentes-contenedores-maritimos',
					'en' => 'faqs-maritime-containers',
					'pt' => 'perguntas-frequentes-conteineres',
					'fr' => 'faq-conteneurs-maritimes',
				],
				'%e6%b5%b7%e8%bf%90%e9%9b%86%e8%a3%85%e7%ae%b1%e5%9b%9e%e8%b4%ad%e8%ae%a1%e5%88%92' => [
					'es' => 'recompra-de-contenedores',
					'en' => 'maritime-container-buyback',
					'pt' => 'recompra-conteineres-maritimos',
					'fr' => 'reprise-conteneurs-maritimes',
				],
			],
		];

		global $post;

		if ( isset( $post->post_name ) ) {
			$current_slug = $post->post_name;

			if (isset($translations[$current_lang][$current_slug][$target_lang])) {
				$translated_slug = $translations[$current_lang][$current_slug][$target_lang];
			} else {
				$translated_slug = $current_slug;
			}

			$item_output = preg_replace('/##CURRENT_LINK_([a-z]{2})##/', '/' . $target_lang . '/' . $translated_slug, $item_output);
		}

    }

    return $item_output;
}
add_filter('walker_nav_menu_start_el', 'replace_dynamic_menu_links_manual', 10, 4);





/* SOLO FUNCIONA CON ELEMENTOR PRO --- SE CAMBI� AL M�TODO POR JAVASCRIPT */
add_action( 'elementor_pro/forms/new_record', function( $record, $handler ) {
    $form_name = $record->get_form_settings( 'form_name' );

    if ( 'FormularioSalesforce' !== $form_name ) {
        return;
    }

    $raw_fields = $record->get( 'fields' );
    $fields = [];

    foreach ( $raw_fields as $id => $field ) {
        $fields[ $id ] = $field['value'];
    }

    $salesforce_data = [
        'oid'             => '00D8a000002A8Hp',
        'retURL'          => $fields['retURL'] ?? get_home_url(),
        'first_name'      => $fields['first_name'] ?? '',
        'last_name'       => $fields['last_name'] ?? '',
        'company'         => $fields['company'] ?? '',
        'phone'           => $fields['phone'] ?? '',
        'email'           => $fields['email'] ?? '',
        'country'         => $fields['country'] ?? '',
		'lead_source'     => $fields['lead_source'] ?? 'Web',
        '00N8a00000FXhD2' => $fields['00N8a00000FXhD2'] ?? '',	// City
		'city'            => $fields['00N8a00000FXhD2'] ?? '',	// City
        '00N8a00000FXdRt' => $fields['00N8a00000FXdRt'] ?? '',	// Modality
		'00N8a00000FXdRZ' => $fields['00N8a00000FXdRZ'] ?? '',	// Container Type
		'00N8a00000FXdRo' => $fields['00N8a00000FXdRo'] ?? '',	// Quantity
		'00NUm00000G445R' => $fields['00NUm00000G445R'] ?? '',	// Country
		//'debug' => $fields['debug'] ? 1 : 0,
		//'debugEmail' => 'waltercaballero@gmail.com',
		//'city'            => $fields['city'] ?? '',	// City	- form de contacto
		//'description'     => $fields['description'] ?? '',
		//'00N8a00000FXdRj' => $fields['00N8a00000FXdRj'] ?? 'Central America',	// Market
		//'00N8a00000FXdRe' => $fields['00N8a00000FXdRe'] ?? 'Cargo Worthy',	// Estado
    ];


		$to = 'waltercaballero@gmail.com';
		$subject = 'Nuevo formulario enviado desde Silversea Containers';
		$message = "Se ha enviado un nuevo formulario con los siguientes datos:\n\n";

		foreach ($salesforce_data as $key => $value) {
			$message .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
		}

		$headers = ['Content-Type: text/plain; charset=UTF-8'];

		wp_mail($to, $subject, $message, $headers);



    $response = wp_remote_post( 'https://webto.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8', [
        'body'    => $salesforce_data,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    ]);

	/*
	$response = wp_remote_post( 'https://webto.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8', [
		'headers' => [
			'Content-Type' => 'application/x-www-form-urlencoded',
			'User-Agent'   => 'Mozilla/5.0 (WordPress)',
		],
		'body'    => http_build_query( $salesforce_data ),
	] );
	*/

	//error_log( 'Salesforce Data: ' . print_r( $salesforce_data, true ) );
    //error_log( 'Salesforce Response: ' . print_r( $response, true ) );

    if ( is_wp_error( $response ) ) {
        error_log( 'Salesforce Error: ' . $response->get_error_message() );
        return;
    }

	/*
	// no funciona, asi q se redirecciona desde config del widget elementor
    $response_code = wp_remote_retrieve_response_code( $response );
	if ( $response_code === 200 ) {
		//wp_safe_redirect( $salesforce_data['retURL'] );
        //exit;
    } else {
        error_log( 'Salesforce Error: C�digo ' . $response_code );
    }
	*/
}, 10, 2 );


//https://silverseacontainers.com/es/gracias-cotizar/
/*
add_action('rest_api_init', function () {
    register_rest_route('salesforce-form', '/email', [
        'methods'  => 'POST',
        'callback' => 'lcs_sf_form_send_email',
        'permission_callback' => '__return_true',
    ]);
});

function lcs_sf_form_send_email(WP_REST_Request $request) {
    $data = $request->get_json_params();

    $to = 'waltercaballero@gmail.com';
    $subject = 'Nuevo formulario enviado desde Silversea Containers';
    $message = "Se ha enviado un nuevo formulario con los siguientes datos:\n\n";

    foreach ($data as $key => $value) {
        $message .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
    }

    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    $sent = wp_mail($to, $subject, $message, $headers);

    return new WP_REST_Response([
        'success' => $sent,
    ], $sent ? 200 : 500);
}



add_action('rest_api_init', function () {
  register_rest_route('salesforce-form', '/lead', [
    'methods' => 'POST',
    'callback' => 'lcs_enviar_lead_a_salesforce',
    'permission_callback' => '__return_true',
  ]);
});

function lcs_enviar_lead_a_salesforce($request) {
	$data = $request->get_json_params();

	$salesforce_data = array_filter([
		'oid' => '00D8a000002A8Hp',
		'retURL' => $data['retURL'] ?? home_url(),
		'first_name' => $data['first_name'] ?? '',
		'last_name' => $data['last_name'] ?? '',
		'company' => $data['company'] ?? '',
		'phone' => $data['phone'] ?? '',
		'email' => $data['email'] ?? '',
		'00N8a00000FXhD2' => $data['00N8a00000FXhD2'] ?? '',
		//'city' => $data['city'] ?? '',
		'country' => $data['country'] ?? '',
		'lead_source' => $data['lead_source'] ?? '',
		//'description' => $data['description'] ?? '',
		//'00N8a00000FXdRt' => $data['00N8a00000FXdRt'] ?? '',
		'00N8a00000FXdRZ' => $data['00N8a00000FXdRZ'] ?? '',
		'00N8a00000FXdRo' => $data['00N8a00000FXdRo'] ?? '',
	]);

	$response = wp_remote_post('https://webto.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8', [
		'body' => $salesforce_data,
		'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
	]);

	error_log( 'Salesforce Data: ' . print_r( $salesforce_data, true ) );
	error_log( 'Salesforce Response: ' . print_r( $response, true ) );

	if (is_wp_error($response)) {
		return new WP_Error('salesforce_error', 'Error al enviar a Salesforce', ['status' => 500]);
	}

	return rest_ensure_response(['message' => 'Enviado a Salesforce']);
}
*/


/* Cotizador */

require_once plugin_dir_path( __FILE__ ) . 'includes/shipping-calculator.php';

/**
 * Usado en el listado de productos, para mostrar el total de resultados
 */
add_shortcode( 'product_count', function() {
	$count_posts = wp_count_posts( 'product' );
	return $count_posts->publish . ' Resultados';
} );

/**
 * Usado en el listado de productos, para mostrar el badge de USADO
 */
add_shortcode('badge_usado', function() {
    global $product;

    $product_id = $product ? $product->get_id() : get_the_ID();

    if ( ! $product_id ) return '';

    if ( has_term( 'usado', 'pa_condicion', $product_id ) ) {
        return 'usado';
    }

    return '';
});

/**
 * Ordenador de productos con drag & drop — modifica menu_order
 * Editor masivo de precios — regular + oferta para simples y variaciones
 */
add_action( 'admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=product',
        'Ordenar Productos',
        '↕ Ordenar',
        'manage_woocommerce',
        'silversea-product-order',
        'silversea_render_product_order_page'
    );
    add_submenu_page(
        'edit.php?post_type=product',
        'Editar Precios',
        '€ Precios',
        'manage_woocommerce',
        'silversea-product-prices',
        'silversea_render_product_prices_page'
    );
} );

add_action( 'wp_ajax_silversea_save_product_order', function() {
    check_ajax_referer( 'silversea_product_order', 'nonce' );
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error( null, 403 );

    $ids = array_map( 'intval', (array)( $_POST['ids'] ?? [] ) );
    foreach ( $ids as $pos => $id ) {
        if ( $id > 0 ) wp_update_post( ['ID' => $id, 'menu_order' => $pos] );
    }
    wp_send_json_success( ['saved' => count($ids)] );
} );

function silversea_render_product_order_page() {
    $cat_id = (int) ( $_GET['cat'] ?? 0 );
    $search = sanitize_text_field( $_GET['s'] ?? '' );

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
    ];
    if ( $cat_id ) {
        $args['tax_query'] = [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id]];
    }
    if ( $search ) {
        $args['s'] = $search;
    }

    $products   = get_posts( $args );
    $categories = get_terms( ['taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name'] );

    wp_enqueue_script( 'jquery-ui-sortable' );
    ?>
    <div class="wrap" style="max-width:860px;">
      <h1 style="display:flex;align-items:center;gap:10px;">
        ↕ Ordenar Productos
        <span style="font-size:13px;font-weight:400;color:#6b7280;">
          — el orden se aplica cuando Elementor usa <em>Orden del menú</em>
        </span>
      </h1>

      <!-- Filtros -->
      <form method="get" style="display:flex;gap:10px;align-items:center;margin:16px 0;">
        <input type="hidden" name="post_type" value="product">
        <input type="hidden" name="page" value="silversea-product-order">
        <select name="cat" onchange="this.form.submit()" style="height:34px;">
          <option value="">Todas las categorías</option>
          <?php foreach ( $categories as $cat ) : ?>
            <option value="<?php echo $cat->term_id; ?>" <?php selected( $cat_id, $cat->term_id ); ?>>
              <?php echo esc_html( $cat->name ); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
               placeholder="Buscar…" style="width:200px;height:34px;padding:0 8px;">
        <button type="submit" class="button">Filtrar</button>
        <?php if ( $cat_id || $search ) : ?>
          <a href="?post_type=product&page=silversea-product-order" class="button">✕ Limpiar</a>
        <?php endif; ?>
        <span style="margin-left:4px;font-size:13px;color:#9ca3af;">
          <?php echo count($products); ?> producto<?php echo count($products) !== 1 ? 's' : ''; ?>
        </span>
      </form>

      <!-- Botón guardar -->
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">
        <button id="spo-save" class="button button-primary" style="height:36px;font-size:14px;padding:0 20px;">
          💾 Guardar orden
        </button>
        <span id="spo-status" style="font-size:13px;"></span>
      </div>

      <!-- Lista ordenable -->
      <?php if ( empty($products) ) : ?>
        <p style="color:#9ca3af;text-align:center;padding:40px;">No se encontraron productos.</p>
      <?php else : ?>
      <ul id="spo-list">
        <?php foreach ( $products as $i => $post ) :
            $product = wc_get_product( $post->ID );
            if ( ! $product ) continue;
            $thumb  = get_the_post_thumbnail_url( $post->ID, [56, 56] ) ?: wc_placeholder_img_src( [56, 56] );
            $sku    = $product->get_sku() ?: '—';
            $is_var = $product->is_type('variable');
        ?>
        <li data-id="<?php echo $post->ID; ?>">
          <span class="spo-handle" title="Arrastrar">⠿</span>
          <span class="spo-pos"><?php echo $i + 1; ?></span>
          <img src="<?php echo esc_url($thumb); ?>" width="44" height="44" alt="">
          <span class="spo-name"><?php echo esc_html( $post->post_title ); ?></span>
          <code class="spo-sku"><?php echo esc_html($sku); ?></code>
          <span class="spo-type <?php echo $is_var ? 'spo-var' : 'spo-simple'; ?>">
            <?php echo $is_var ? 'Variable' : 'Simple'; ?>
          </span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <style>
    #spo-list {
      list-style: none; margin: 0; padding: 0;
    }
    #spo-list li {
      display: flex; align-items: center; gap: 12px;
      background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
      padding: 10px 14px; margin-bottom: 6px;
      transition: box-shadow .15s, background .15s;
      user-select: none;
    }
    #spo-list li:hover { background: #f8fafc; box-shadow: 0 2px 8px rgba(0,0,0,.07); }
    #spo-list li.ui-sortable-helper {
      box-shadow: 0 8px 24px rgba(0,0,0,.14); background: #eff6ff; cursor: grabbing;
    }
    #spo-list li.ui-sortable-placeholder {
      visibility: visible !important;
      background: #eff6ff; border: 2px dashed #93c5fd; border-radius: 8px;
    }
    .spo-handle {
      font-size: 20px; color: #d1d5db; cursor: grab; flex-shrink: 0; line-height: 1;
    }
    .spo-handle:hover { color: #6b7280; }
    .spo-pos {
      font-size: 12px; color: #9ca3af; min-width: 26px;
      text-align: right; flex-shrink: 0;
    }
    #spo-list img {
      border-radius: 5px; object-fit: cover; flex-shrink: 0;
      border: 1px solid #e5e7eb;
    }
    .spo-name  { flex: 1; font-size: 14px; font-weight: 500; color: #111; }
    .spo-sku   { font-size: 11px; background: #f3f4f6; padding: 2px 7px; border-radius: 3px; font-family: monospace; flex-shrink: 0; }
    .spo-type  { font-size: 11px; padding: 2px 9px; border-radius: 4px; flex-shrink: 0; }
    .spo-var   { color: #7c3aed; background: #f5f3ff; }
    .spo-simple{ color: #059669; background: #f0fdf4; }
    </style>

    <script>
    jQuery(function($) {
      var nonce = '<?php echo wp_create_nonce('silversea_product_order'); ?>';

      $('#spo-list').sortable({
        handle:      '.spo-handle',
        placeholder: 'ui-sortable-placeholder',
        tolerance:   'pointer',
        update: function() {
          /* Actualizar números de posición en tiempo real */
          $('#spo-list li').each(function(i) {
            $(this).find('.spo-pos').text(i + 1);
          });
        }
      });

      $('#spo-save').on('click', function() {
        var $btn    = $(this);
        var $status = $('#spo-status');
        var ids     = $('#spo-list li').map(function() { return $(this).data('id'); }).get();

        $btn.prop('disabled', true).text('Guardando…');
        $status.text('').css('color', '#6b7280');

        $.post(ajaxurl, { action: 'silversea_save_product_order', nonce: nonce, ids: ids })
          .done(function(res) {
            if (res.success) {
              $status.css('color', '#059669').text('✓ Orden guardado — ' + ids.length + ' productos actualizados.');
            } else {
              $status.css('color', '#dc2626').text('✗ Error al guardar.');
            }
          })
          .fail(function() { $status.css('color', '#dc2626').text('✗ Error de conexión.'); })
          .always(function() {
            $btn.prop('disabled', false).text('💾 Guardar orden');
            setTimeout(function() { $status.text(''); }, 5000);
          });
      });
    });
    </script>
    <?php
}

/* ── AJAX: guardar precios ── */
add_action( 'wp_ajax_silversea_save_product_prices', function() {
    check_ajax_referer( 'silversea_product_prices', 'nonce' );
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error( null, 403 );

    $items = json_decode( stripslashes( $_POST['items'] ?? '[]' ), true );
    if ( ! is_array($items) ) wp_send_json_error( ['message' => 'Datos inválidos.'] );

    $parents_to_sync = [];
    $saved = 0;

    foreach ( $items as $item ) {
        $id   = (int) ( $item['id']      ?? 0 );
        $reg  = trim( $item['regular']   ?? '' );
        $sale = trim( $item['sale']      ?? '' );
        if ( ! $id || $reg === '' ) continue;

        $p = wc_get_product( $id );
        if ( ! $p ) continue;

        $p->set_regular_price( wc_format_decimal($reg) );
        $p->set_sale_price( $sale !== '' ? wc_format_decimal($sale) : '' );
        $p->save();

        if ( $p->is_type('variation') )
            $parents_to_sync[ $p->get_parent_id() ] = true;

        $saved++;
    }

    foreach ( array_keys($parents_to_sync) as $parent_id )
        WC_Product_Variable::sync( $parent_id );

    wp_send_json_success( ['saved' => $saved] );
} );

/* ── Página: editor masivo de precios ── */
function silversea_render_product_prices_page() {
    $cat_id = (int) ( $_GET['cat'] ?? 0 );
    $search = sanitize_text_field( $_GET['s'] ?? '' );

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ];
    if ( $cat_id ) $args['tax_query'] = [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id]];
    if ( $search ) $args['s'] = $search;

    $products   = get_posts( $args );
    $categories = get_terms( ['taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name'] );

    /* Helper: label legible para los atributos de una variación */
    $variation_label = function( WC_Product_Variation $var ) {
        $parts = [];
        foreach ( $var->get_variation_attributes() as $attr => $slug ) {
            if ( ! $slug ) { $parts[] = 'Cualquiera'; continue; }
            $tax  = str_replace( 'attribute_', '', $attr );
            $term = taxonomy_exists($tax) ? get_term_by('slug', $slug, $tax) : false;
            $parts[] = $term ? $term->name : $slug;
        }
        return implode( ' / ', $parts ) ?: 'Variación #' . $var->get_id();
    };
    ?>
    <div class="wrap" style="max-width:980px;">
      <h1>€ Editar Precios</h1>

      <!-- Filtros -->
      <form method="get" style="display:flex;gap:10px;align-items:center;margin:16px 0;">
        <input type="hidden" name="post_type" value="product">
        <input type="hidden" name="page" value="silversea-product-prices">
        <select name="cat" onchange="this.form.submit()" style="height:34px;">
          <option value="">Todas las categorías</option>
          <?php foreach ( $categories as $cat ) : ?>
            <option value="<?php echo $cat->term_id; ?>" <?php selected($cat_id, $cat->term_id); ?>>
              <?php echo esc_html($cat->name); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
               placeholder="Buscar…" style="width:200px;height:34px;padding:0 8px;">
        <button type="submit" class="button">Filtrar</button>
        <?php if ( $cat_id || $search ) : ?>
          <a href="?post_type=product&page=silversea-product-prices" class="button">✕ Limpiar</a>
        <?php endif; ?>
        <span style="font-size:13px;color:#9ca3af;"><?php echo count($products); ?> productos</span>
      </form>

      <!-- Acciones -->
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <button id="spp-save" class="button button-primary" style="height:36px;padding:0 20px;font-size:14px;">
          💾 Guardar precios
        </button>
        <button id="spp-expand-all" class="button" style="height:36px;">↕ Expandir todo</button>
        <span id="spp-status" style="font-size:13px;"></span>
      </div>

      <?php if ( empty($products) ) : ?>
        <p style="color:#9ca3af;text-align:center;padding:48px;">No se encontraron productos.</p>
      <?php else : ?>
      <table id="spp-table" class="wp-list-table widefat fixed">
        <thead>
          <tr>
            <th style="width:44px;"></th>
            <th>Producto / Variación</th>
            <th style="width:130px;">SKU</th>
            <th style="width:150px;">Precio regular €</th>
            <th style="width:150px;">Precio oferta €</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ( $products as $post ) :
            $product = wc_get_product( $post->ID );
            if ( ! $product ) continue;
            $thumb  = get_the_post_thumbnail_url( $post->ID, [40,40] ) ?: wc_placeholder_img_src([40,40]);
            $sku    = $product->get_sku() ?: '—';
            $is_var = $product->is_type('variable');
        ?>

        <?php if ( $is_var ) :
            $variation_ids = $product->get_children();
        ?>
          <!-- Variable: fila cabecera (sin inputs de precio) -->
          <tr class="spp-parent">
            <td style="text-align:center;padding:8px 4px;">
              <img src="<?php echo esc_url($thumb); ?>" width="34" height="34"
                   style="border-radius:4px;object-fit:cover;vertical-align:middle;">
            </td>
            <td>
              <strong><?php echo esc_html($post->post_title); ?></strong>
              <span class="spp-badge spp-var">Variable · <?php echo count($variation_ids); ?> var.</span>
              <button type="button" class="spp-toggle button button-small"
                      data-parent="<?php echo $post->ID; ?>">▼ Ver</button>
            </td>
            <td><code class="spp-sku"><?php echo esc_html($sku); ?></code></td>
            <td colspan="2" style="color:#9ca3af;font-size:12px;font-style:italic;">
              <?php echo $product->get_price_html(); ?>
            </td>
          </tr>

          <!-- Variable: filas de variaciones (ocultas por defecto) -->
          <?php foreach ( $variation_ids as $vid ) :
              $var = wc_get_product($vid);
              if ( ! $var ) continue;
          ?>
          <tr class="spp-variation spp-child-<?php echo $post->ID; ?>" style="display:none;">
            <td style="background:#f8fafc;"></td>
            <td style="background:#f8fafc;padding-left:40px;">
              <span style="color:#6b7280;font-size:13px;">
                ↳ <?php echo esc_html( $variation_label($var) ); ?>
              </span>
            </td>
            <td style="background:#f8fafc;">
              <code class="spp-sku"><?php echo esc_html($var->get_sku() ?: '—'); ?></code>
            </td>
            <td style="background:#f8fafc;">
              <input type="number" class="spp-input" step="0.01" min="0"
                     data-id="<?php echo $vid; ?>" data-field="regular"
                     value="<?php echo esc_attr($var->get_regular_price()); ?>"
                     placeholder="0.00">
            </td>
            <td style="background:#f8fafc;">
              <input type="number" class="spp-input" step="0.01" min="0"
                     data-id="<?php echo $vid; ?>" data-field="sale"
                     value="<?php echo esc_attr($var->get_sale_price()); ?>"
                     placeholder="Sin oferta">
            </td>
          </tr>
          <?php endforeach; ?>

        <?php else : // Simple ?>
          <tr class="spp-simple">
            <td style="text-align:center;padding:8px 4px;">
              <img src="<?php echo esc_url($thumb); ?>" width="34" height="34"
                   style="border-radius:4px;object-fit:cover;vertical-align:middle;">
            </td>
            <td>
              <?php echo esc_html($post->post_title); ?>
              <span class="spp-badge spp-simple-badge">Simple</span>
            </td>
            <td><code class="spp-sku"><?php echo esc_html($sku); ?></code></td>
            <td>
              <input type="number" class="spp-input" step="0.01" min="0"
                     data-id="<?php echo $post->ID; ?>" data-field="regular"
                     value="<?php echo esc_attr($product->get_regular_price()); ?>"
                     placeholder="0.00">
            </td>
            <td>
              <input type="number" class="spp-input" step="0.01" min="0"
                     data-id="<?php echo $post->ID; ?>" data-field="sale"
                     value="<?php echo esc_attr($product->get_sale_price()); ?>"
                     placeholder="Sin oferta">
            </td>
          </tr>
        <?php endif; ?>

        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <style>
    #spp-table th, #spp-table td { vertical-align: middle; }
    #spp-table tbody tr:nth-child(odd) { background: transparent; }
    .spp-badge { display:inline-block; font-size:11px; padding:2px 8px; border-radius:4px; margin-left:6px; vertical-align:middle; }
    .spp-var          { background:#f5f3ff; color:#7c3aed; }
    .spp-simple-badge { background:#f0fdf4; color:#059669; }
    .spp-sku  { font-size:11px; background:#f3f4f6; padding:2px 6px; border-radius:3px; font-family:monospace; }
    .spp-input {
      width:120px; height:30px; padding:0 6px; font-size:13px;
      border:1px solid #d1d5db; border-radius:4px; box-sizing:border-box;
      transition: border-color .15s;
    }
    .spp-input:focus  { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; outline:none; }
    .spp-input.spp-dirty { border-color:#f59e0b; background:#fffbeb; }
    .spp-toggle { font-size:11px !important; padding:1px 8px !important; margin-left:8px; }
    .spp-parent td { border-top: 2px solid #e5e7eb; }
    </style>

    <script>
    jQuery(function($) {
      var nonce     = '<?php echo wp_create_nonce('silversea_product_prices'); ?>';
      var allOpen   = false;

      /* Marcar campo como modificado */
      $(document).on('input', '.spp-input', function() { $(this).addClass('spp-dirty'); });

      /* Expand / colapsar una fila variable */
      $(document).on('click', '.spp-toggle', function() {
        var pid    = $(this).data('parent');
        var $rows  = $('.spp-child-' + pid);
        var isOpen = $rows.first().is(':visible');
        $rows.toggle(!isOpen);
        $(this).text(isOpen ? '▼ Ver' : '▲ Ocultar');
      });

      /* Expandir / colapsar todo */
      $('#spp-expand-all').on('click', function() {
        allOpen = !allOpen;
        $('.spp-variation').toggle(allOpen);
        $('.spp-toggle').text(allOpen ? '▲ Ocultar' : '▼ Ver');
        $(this).text(allOpen ? '↕ Colapsar todo' : '↕ Expandir todo');
      });

      /* Guardar */
      $('#spp-save').on('click', function() {
        var $btn    = $(this);
        var $status = $('#spp-status');

        /* Agrupar inputs por product/variation ID */
        var map = {};
        $('.spp-input').each(function() {
          var id    = $(this).data('id');
          var field = $(this).data('field');
          if (!map[id]) map[id] = { id: id };
          map[id][field] = $(this).val();
        });
        var items = Object.values(map);

        $btn.prop('disabled', true).text('Guardando…');
        $status.text('').css('color', '#6b7280');

        $.post(ajaxurl, {
          action: 'silversea_save_product_prices',
          nonce:  nonce,
          items:  JSON.stringify(items),
        })
        .done(function(res) {
          if (res.success) {
            $status.css('color', '#059669')
                   .text('✓ ' + res.data.saved + ' precio(s) actualizados correctamente.');
            $('.spp-input').removeClass('spp-dirty');
          } else {
            $status.css('color', '#dc2626').text('✗ Error al guardar.');
          }
        })
        .fail(function() { $status.css('color', '#dc2626').text('✗ Error de conexión.'); })
        .always(function() {
          $btn.prop('disabled', false).text('💾 Guardar precios');
          setTimeout(function() { $status.text(''); }, 6000);
        });
      });
    });
    </script>
    <?php
}

/**
 * Columna SKU / Color RAL en el listado de productos del admin.
 * Simple  → SKU + color del producto.
 * Variable → "Variable" + una línea por variación con su SKU y color.
 */
add_filter( 'manage_edit-product_columns', function( $columns ) {
    /* Ocultar columnas de Yoast SEO */
    unset( $columns['wpseo-links'], $columns['wpseo-linked'] );

    /* Insertar columna SKU / Color RAL después de "Nombre" */
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'name' ) {
            $new['silversea_sku_color'] = 'SKU / Color RAL';
        }
    }
    return isset( $new['silversea_sku_color'] ) ? $new : ( $columns + ['silversea_sku_color' => 'SKU / Color RAL'] );
} );

add_action( 'manage_product_posts_custom_column', function( $column, $post_id ) {
    if ( $column !== 'silversea_sku_color' ) return;

    $product = wc_get_product( $post_id );
    if ( ! $product ) return;

    if ( $product->is_type('variable') ) {
        echo '<em style="font-size:11px;color:#9ca3af;">Variable</em>';
        foreach ( $product->get_children() as $vid ) {
            $var = wc_get_product( $vid );
            if ( ! $var ) continue;
            $sku   = $var->get_sku() ?: '—';
            $color = '';
            $slug  = $var->get_attribute('pa_color-ral');
            if ( $slug ) {
                $term  = get_term_by( 'slug', $slug, 'pa_color-ral' );
                $color = $term ? $term->name : $slug;
            }
            echo '<div style="font-size:11px;line-height:1.8;white-space:nowrap;">'
               . '<code style="font-size:10px;background:#f3f4f6;padding:1px 5px;border-radius:3px;font-family:monospace;">' . esc_html( $sku ) . '</code>'
               . ( $color ? ' <span style="color:#374151;">· ' . esc_html( $color ) . '</span>' : '' )
               . '</div>';
        }
    } else {
        echo '<em style="font-size:11px;color:#9ca3af;">Simple</em>';
        $sku   = $product->get_sku() ?: '—';
        $terms = wc_get_product_terms( $post_id, 'pa_color-ral', ['fields' => 'names'] );
        $color = $terms[0] ?? '';
        echo '<div style="font-size:11px;line-height:1.8;white-space:nowrap;">'
           . '<code style="font-size:10px;background:#f3f4f6;padding:1px 5px;border-radius:3px;font-family:monospace;">' . esc_html( $sku ) . '</code>'
           . ( $color ? ' <span style="color:#374151;">· ' . esc_html( $color ) . '</span>' : '' )
           . '</div>';
    }
}, 10, 2 );

/* Ancho fijo para la columna — evita que se expanda demasiado en tablas grandes */
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'edit-product' ) return;
    echo '<style>.column-silversea_sku_color { width: 180px; }</style>';
} );

/**
 * Usado en el detalle de producto, para mostrar el label antes del Qty. field
 */
add_action( 'woocommerce_before_add_to_cart_quantity', function() {
    echo '<div class="qty-container"><p class="qty-label">Cantidad: </p>';
} );

add_action( 'woocommerce_after_add_to_cart_quantity', function() {
    echo '</div><!-- .qty-container -->';
} );

/**
 * Forzar override de templates de YITH desde el tema hijo
 */
 
add_filter( 'wc_get_template', function( $located, $template_name, $args, $template_path, $default_path ) {
    if ( strpos( $default_path, 'yith-woocommerce-request-a-quote' ) !== false ) {
        $theme_override = get_stylesheet_directory() . '/woocommerce/yith-request-quote/' . $template_name;
        if ( file_exists( $theme_override ) ) {
            return $theme_override;
        }
    }
    return $located;
}, 10, 5 );


// Suprimir deprecated notices que rompen el redirect
add_action( 'init', function() {
    if ( isset($_POST['raq_mail_wpnonce']) ) {
        error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );
    }
}, 0 );




add_filter( 'wp_redirect', function( $location, $status ) {
    if ( strpos( $location, 'sent=1' ) !== false ) {
        // Limpiar notices de YITH antes de redirigir
        if ( function_exists( 'yith_ywraq_clear_notices' ) ) {
            yith_ywraq_clear_notices();
        }
        // Alternativa directa via WC session
        if ( WC()->session ) {
            WC()->session->__unset( 'yith_ywraq_notices' );
            WC()->session->__unset( 'wc_notices' );
        }
    }
    return $location;
}, 5, 2 );


/* - Silversea: fix sesi�n YITH en submit - */
add_action( 'wp_loaded', function() {
    if ( ! isset( $_POST['raq_mail_wpnonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['raq_mail_wpnonce'], 'send-request-quote' ) ) return;
    
    // wp_loaded corre DESPU�S de init completo � ac� YITH ya inicializ� todo
    // pero send_message() ya corri� tambi�n. Lo que hacemos es:
    // si la lista est� vac�a pero la sesi�n tiene productos, procesamos nosotros
    
    if ( YITH_Request_Quote()->is_empty() ) {
        $session = new YITH_YWRAQ_Session();
        $raq     = $session->get( 'raq', [] );
        
        //error_log('wp_loaded fix � raq en sesi�n: ' . count($raq));
        
        if ( ! empty( $raq ) ) {
            YITH_Request_Quote()->raq_content = $raq;
            
            // Llamar send_message manualmente ahora que el carrito est� cargado
            YITH_Request_Quote()->send_message();
        }
    }
}, 20 );
