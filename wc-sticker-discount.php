<?php
/**
 * Plugin Name:          WC Sticker Discount
 * Description:          Automatically discounts eligible sticker products when the cart reaches a minimum quantity.
 * Version:              1.0.0
 * Author:               EGNOTO
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          wc-sticker-discount
 * Requires at least:    7.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.1
 *
 * @package WC_Sticker_Discount
 */

defined( 'ABSPATH' ) || exit;

define( 'WCSD_VERSION', '1.0.0' );
define( 'WCSD_FILE', __FILE__ );
define( 'WCSD_PATH', plugin_dir_path( __FILE__ ) );

add_action( 'before_woocommerce_init', 'wcsd_declare_compatibility' );
add_action( 'plugins_loaded', 'wcsd_init' );

/**
 * Declare compatibility with HPOS and the Cart/Checkout blocks.
 */
function wcsd_declare_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCSD_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WCSD_FILE, true );
	}
}

/**
 * Boot the plugin once WooCommerce is available.
 */
function wcsd_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wcsd_missing_woocommerce_notice' );
		return;
	}

	require_once WCSD_PATH . 'includes/class-wcsd-settings.php';
	require_once WCSD_PATH . 'includes/class-wcsd-discount.php';
	require_once WCSD_PATH . 'includes/class-wcsd-order.php';

	$settings = new WCSD_Settings();

	( new WCSD_Discount( $settings ) )->register_hooks();
	( new WCSD_Order() )->register_hooks();

	if ( is_admin() ) {
		require_once WCSD_PATH . 'includes/class-wcsd-settings-page.php';

		( new WCSD_Settings_Page( $settings ) )->register_hooks();

		add_filter( 'plugin_action_links_' . plugin_basename( WCSD_FILE ), 'wcsd_plugin_action_links' );
	}
}

/**
 * Show an admin notice when WooCommerce is not active.
 */
function wcsd_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'WC Sticker Discount requires WooCommerce to be installed and active.', 'wc-sticker-discount' )
	);
}

/**
 * Add a "Settings" link on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function wcsd_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( WCSD_Settings_Page::get_url() ),
		esc_html__( 'Settings', 'wc-sticker-discount' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}
