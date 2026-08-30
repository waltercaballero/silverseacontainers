<?php
/*
Plugin Name: SILVERSEA Containers
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

	if ( function_exists( 'is_shop' ) && ( is_shop() || is_product() || is_product_category() || is_product_tag() ) ) {
		add_action( 'wp_head', function() {
			echo '<style>.lang-section { display: none !important; }</style>';
		}, 99 );
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
			$html = '<img src="' . esc_url($flag_url) . '" alt="SILVERSEA Containers ' . strtoupper( $country ) . '" class="open-store-popup" id="open-store-popup">';
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
		'pr' => 'Puerto Rico',
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





/* SOLO FUNCIONA CON ELEMENTOR PRO --- SE CAMBIÓ AL MÉTODO POR JAVASCRIPT */
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
		//'00N8a00000FXdRt' => 'Buy',
		'00N8a00000FXdRZ' => $fields['00N8a00000FXdRZ'] ?? '',	// Container Type
		'00N8a00000FXdRo' => $fields['00N8a00000FXdRo'] ?? '',	// Quantity
		'00NUm00000G445R' => $fields['00NUm00000G445R'] ?? '',	// Country
		//'debug' => $fields['debug'] ? 1 : 0,
		//'debugEmail' => 'waltercaballero@gmail.com',
		//'city'            => $fields['city'] ?? '',	// City	- form de contacto
		//'zip'             => $fields['zip'] ?? '',
		//'description'     => $fields['description'] ?? '',
		'00N8a00000FXdRj' => $fields['00N8a00000FXdRj'] ?? 'Europe',	// Market
		//'00N8a00000FXdRe' => $fields['00N8a00000FXdRe'] ?? 'Cargo Worthy',	// Estado
		'00NUm00000Ue4V3' => 'SILVERSEA',
        '00NUm00000Ue4V4' => 'SILVERSEA',
    ];

		$to = 'waltercaballero@gmail.com';
		$subject = 'Nuevo formulario enviado desde SILVERSEA Containers';
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
    $subject = 'Nuevo formulario enviado desde SILVERSEA Containers';
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

add_action( 'plugins_loaded', 'silversea_cargar_cotizador' );

function silversea_cargar_cotizador() {
    if ( class_exists( 'WooCommerce' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'cotizador.php';
    }
}