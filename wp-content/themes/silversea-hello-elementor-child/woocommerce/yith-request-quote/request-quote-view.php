<?php
/**
 * Template override: Mi Selección
 * @version 1.2.0 — addons YITH WAPO + cotizador consolidado + fix eliminación
 */

if ( isset( $_REQUEST['sent'] ) ) return;

if ( count( $raq_content ) === 0 ) : ?>
    <div class="silversea-raq-empty">
        <p><?php esc_html_e( 'No hay productos en su selección.', 'yith-woocommerce-request-a-quote' ); ?></p>
        <a href="<?php echo esc_url( home_url('tienda-de-contenedores') ); ?>" class="silversea-btn-outline">Ver contenedores</a>
    </div>
<?php else :

/*  Addons guardados en el quote cart por silversea_inject_addons_to_raq() (hook yith_raq_updated)  */
?>
<div class="silversea-raq-wrap">

    <form id="yith-ywraq-form" name="yith-ywraq-form"
          action="<?php echo esc_url( YITH_Request_Quote()->get_raq_page_url('update') ); ?>"
          method="post">

        <div class="silversea-raq-table">
            <div class="silversea-raq-header">
                <span class="col-product">Tipo</span>
                <span class="col-qty">Cantidad</span>
            </div>

            <?php foreach ( $raq_content as $key => $raq ) :
                $product_id = ! empty($raq['variation_id']) ? $raq['variation_id'] : $raq['product_id'];
                $_product   = wc_get_product( $product_id );
                if ( ! $_product || ! is_object($_product) ) continue;

                /*  Addons desde el quote cart (guardados por silversea_inject_addons_to_raq)  */
                $addons = $raq['silversea_addons'] ?? [];

                /*  Variaciones  */
                $item_data = [];
                if ( ! empty($raq['variation_id']) && is_array($raq['variations'] ?? []) ) {
                    foreach ( $raq['variations'] as $name => $value ) {
                        if ( '' === $value ) continue;
                        $attr_taxonomy = wc_attribute_taxonomy_name(str_replace('attribute_pa_','',urldecode($name)));
                        $label = $name;
                        if ( taxonomy_exists($attr_taxonomy) ) {
                            $attr_term = get_term_by('slug', $value, $attr_taxonomy);
                            if ( !is_wp_error($attr_term) && $attr_term ) $value = $attr_term->name;
                            $label = wc_attribute_label($attr_taxonomy);
                        } elseif ( strpos($name,'attribute_')!==false ) {
                            $custom = str_replace('attribute_','',$name);
                            $label  = $custom ? wc_attribute_label($custom) : $name;
                        }
                        $item_data[] = ['key'=>$label,'value'=>$value];
                    }
                }
                $item_data = apply_filters('ywraq_request_quote_view_item_data', $item_data, $raq, $_product);
            ?>
            <div class="silversea-raq-row">

                <button type="button" class="silversea-raq-remove yith-ywraq-item-remove"
                        data-remove-item="<?php echo esc_attr($key); ?>"
                        data-wp_nonce="<?php echo esc_attr(wp_create_nonce('yith-ywraq-ajax-action')); ?>"
                        data-product_id="<?php echo esc_attr($product_id); ?>"
                        title="Eliminar">
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                    <path d="M1 1L11 11M11 1L1 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                  </svg>
                </button>

                <div class="silversea-raq-thumb">
                    <?php $thumb = $_product->get_image('thumbnail'); ?>
                    <?php if ($_product->is_visible()): ?>
                        <a href="<?php echo esc_url($_product->get_permalink()); ?>"><?php echo $thumb; ?></a>
                    <?php else: echo $thumb; endif; ?>
                </div>

                <div class="silversea-raq-info">
                    <a class="silversea-raq-name" href="<?php echo esc_url($_product->get_permalink()); ?>">
                        <?php echo wp_kses_post($_product->get_title()); ?>
                    </a>

                    <?php if (!empty($item_data)): ?>
                        <ul class="silversea-raq-meta">
                            <?php foreach($item_data as $d): ?>
                                <li><?php echo esc_html($d['key']); ?>: <?php echo wp_kses_post($d['value']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($addons)): ?>
                        <ul class="silversea-raq-addons">
                            <?php foreach($addons as $addon): ?>
                                <li><span class="silversea-addon-badge">+ <?php echo esc_html($addon); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="silversea-raq-qty col-qty">
                    <?php echo woocommerce_quantity_input([
                        'input_name'  => "raq[{$key}][qty]",
                        'input_value' => apply_filters('ywraq_quantity_input_value', $raq['quantity']),
                        'min_value'   => apply_filters('ywraq_quantity_min_value', 0, $_product),
                        'step'        => apply_filters('ywraq_quantity_step_value', 1, $_product),
                    ], $_product, false); ?>
                </div>

            </div>
            <?php endforeach; ?>
        </div>

        <?php if (get_option('ywraq_show_update_list')==='yes'): ?>
            <div class="silversea-raq-update-row">
                <button type="submit" name="update_raq" class="silversea-btn-outline">Actualizar lista</button>
                <input type="hidden" name="update_raq_wpnonce" value="<?php echo esc_attr(wp_create_nonce('update-request-quote-quantity')); ?>">
            </div>
        <?php endif; ?>

    </form>

    <!--  Cotizador consolidado  -->
    <?php if ( get_option('silversea_show_consolidated', '1') === '1' ) : ?>
    <div class="silversea-raq-shipping-wrap">
        <?php echo do_shortcode('[silversea_shipping mode="consolidated"]'); ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>