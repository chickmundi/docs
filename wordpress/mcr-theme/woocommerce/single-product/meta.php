<?php
/**
 * Product page details: SKU and categories. Tags are hidden: they are search words only.
 *
 * @version 9.7.0 (follows woocommerce/templates/single-product/meta.php)
 */
defined('ABSPATH') || exit;

global $product;
?>
<div class="product_meta">
	<?php do_action('woocommerce_product_meta_start'); ?>
	<?php if (wc_product_sku_enabled() && ($product->get_sku() || $product->is_type('variable'))) : ?>
		<span class="sku_wrapper"><?php esc_html_e('SKU:', 'woocommerce'); ?> <span class="sku"><?php echo ($sku = $product->get_sku()) ? esc_html($sku) : esc_html__('N/A', 'woocommerce'); ?></span></span>
	<?php endif; ?>
	<?php echo wc_get_product_category_list($product->get_id(), ', ', '<span class="posted_in">' . _n('Category:', 'Categories:', count($product->get_category_ids()), 'woocommerce') . ' ', '</span>'); // phpcs:ignore ?>
	<?php do_action('woocommerce_product_meta_end'); ?>
</div>
