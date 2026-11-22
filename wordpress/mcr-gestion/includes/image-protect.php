<?php
/**
 * Image protection: visitors and customers cannot right-click, long-press, drag or "save as"
 * the shop's pictures. Administrators keep the normal browser behaviour.
 *
 * What it does for everyone except administrators:
 *  - no right-click / long-press menu on pictures (computer, Android and iPhone);
 *  - pictures cannot be dragged to the desktop or another tab;
 *  - the media library is not listed through the public WordPress API (/wp-json/wp/v2/media);
 *  - image "attachment" pages redirect to the product (or the home page).
 * Note: nothing on the web can stop a screenshot; this removes the easy ways to copy.
 */
defined('ABSPATH') || exit;

/** Can the current visitor copy pictures? Administrators only (filterable). */
function mcr_can_copy_images(): bool
{
    return (bool) apply_filters('mcr_can_copy_images', is_user_logged_in() && current_user_can('manage_options'));
}

// Front end: small inline style and script (no extra file to download).
add_action('wp_head', function () {
    if (mcr_can_copy_images()) {
        return;
    }
    echo '<style id="mcr-img-protect">img,picture,svg image{-webkit-user-drag:none;user-drag:none;-webkit-touch-callout:none;-webkit-user-select:none;user-select:none}</style>' . "\n";
}, 20);

add_action('wp_footer', function () {
    if (mcr_can_copy_images()) {
        return;
    }
    ?>
<script id="mcr-img-protect-js">
(function () {
  var sel = 'img, picture, video, canvas, .woocommerce-product-gallery, .pswp, [style*="background-image"]';
  function onImage(e) { return e.target && e.target.closest && e.target.closest(sel); }
  document.addEventListener('contextmenu', function (e) { if (onImage(e)) e.preventDefault(); }, true);
  document.addEventListener('dragstart', function (e) { if (onImage(e)) e.preventDefault(); }, true);
  document.addEventListener('copy', function (e) {
    var s = window.getSelection && window.getSelection();
    if (!s || !s.rangeCount) return;
    var box = document.createElement('div');
    box.appendChild(s.getRangeAt(0).cloneContents());
    if (box.querySelector('img, picture')) { e.preventDefault(); if (e.clipboardData) e.clipboardData.setData('text/plain', s.toString()); }
  }, true);
})();
</script>
    <?php
}, 99);

// The public WordPress API must not list or describe the media library.
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    $route = (string) $request->get_route();
    if (strpos($route, '/wp/v2/media') === 0 && !current_user_can('upload_files')) {
        return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to do that.'), ['status' => rest_authorization_required_code()]);
    }
    return $result;
}, 10, 3);

// Image "attachment" pages (?attachment_id=… or /product/x/image-name/) go to the product instead.
add_action('template_redirect', function () {
    if (!is_attachment() || mcr_can_copy_images()) {
        return;
    }
    $parent = (int) wp_get_post_parent_id(get_queried_object_id());
    $to = $parent && get_post_status($parent) === 'publish' ? get_permalink($parent) : home_url('/');
    wp_safe_redirect($to, 301);
    exit;
}, 1);
