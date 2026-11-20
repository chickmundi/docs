<?php
defined('ABSPATH') || exit;
global $product;
if (empty($product) || !$product->is_visible()) {
    return;
}
echo '<li class="pgrid__item">';
get_template_part('template-parts/card', null, ['product' => $product]);
echo '</li>';
