<?php
/**
 * Remove plugin options on uninstall.
 *
 * Order meta is kept on purpose: it is part of each order's financial record.
 *
 * @package WC_Sticker_Discount
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wcsd_options = array( 'enabled', 'fee_label', 'categories', 'min_qty', 'discount_type', 'discount_value', 'exclude_sale' );

foreach ( $wcsd_options as $wcsd_option ) {
	delete_option( 'wcsd_' . $wcsd_option );
}
