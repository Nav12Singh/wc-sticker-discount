<?php
/**
 * Order integration.
 *
 * @package WC_Sticker_Discount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the sticker discount details on the order and shows them in the admin.
 */
class WCSD_Order {

	const META_DISCOUNT_AMOUNT = '_wcsd_discount_amount';
	const META_ELIGIBLE_QTY    = '_wcsd_eligible_qty';

	/**
	 * Register WooCommerce hooks.
	 */
	public function register_hooks() {
		add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'tag_fee_item' ), 10, 3 );

		// Both run after the order items are (re)built and before the order is saved.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'sync_order_meta' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'sync_order_meta' ) );

		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_admin_details' ) );
	}

	/**
	 * Mark the order fee line created from our cart fee.
	 *
	 * @param WC_Order_Item_Fee $item    Order fee item.
	 * @param string            $fee_key Cart fee ID.
	 * @param object            $fee     Cart fee data.
	 */
	public function tag_fee_item( $item, $fee_key, $fee ) {
		if ( WCSD_Discount::FEE_ID !== $fee_key ) {
			return;
		}

		$eligible_qty = isset( $fee->wcsd_eligible_qty ) ? absint( $fee->wcsd_eligible_qty ) : 0;

		$item->add_meta_data( self::META_ELIGIBLE_QTY, $eligible_qty, true );
	}

	/**
	 * Copy the discount details from the fee line to the order.
	 *
	 * Checkout can reuse a pending order and rebuild its items, so stale
	 * values are removed when the discount no longer applies.
	 *
	 * @param WC_Order $order Order being created or updated.
	 */
	public function sync_order_meta( $order ) {
		$fee_item = $this->get_discount_fee_item( $order );

		if ( ! $fee_item ) {
			$order->delete_meta_data( self::META_DISCOUNT_AMOUNT );
			$order->delete_meta_data( self::META_ELIGIBLE_QTY );
			return;
		}

		// Match how the discount line was shown to the customer.
		$discount_amount = abs( (float) $fee_item->get_total() );

		if ( 'incl' === get_option( 'woocommerce_tax_display_cart' ) ) {
			$discount_amount += abs( (float) $fee_item->get_total_tax() );
		}

		$order->update_meta_data( self::META_DISCOUNT_AMOUNT, wc_format_decimal( $discount_amount, wc_get_price_decimals() ) );
		$order->update_meta_data( self::META_ELIGIBLE_QTY, absint( $fee_item->get_meta( self::META_ELIGIBLE_QTY ) ) );
	}

	/**
	 * Show the saved discount details on the edit order screen.
	 *
	 * @param WC_Order $order Order being viewed.
	 */
	public function display_admin_details( $order ) {
		$amount = $order->get_meta( self::META_DISCOUNT_AMOUNT );

		if ( '' === $amount ) {
			return;
		}
		?>
		<div class="form-field form-field-wide wcsd-order-details">
			<h3><?php esc_html_e( 'Sticker Discount', 'wc-sticker-discount' ); ?></h3>
			<p>
				<strong><?php esc_html_e( 'Discount amount:', 'wc-sticker-discount' ); ?></strong>
				<?php echo wp_kses_post( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ); ?>
				<br />
				<strong><?php esc_html_e( 'Eligible sticker quantity:', 'wc-sticker-discount' ); ?></strong>
				<?php echo esc_html( absint( $order->get_meta( self::META_ELIGIBLE_QTY ) ) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Find the fee line added by this plugin.
	 *
	 * @param WC_Order $order Order to search.
	 * @return WC_Order_Item_Fee|null
	 */
	private function get_discount_fee_item( $order ) {
		foreach ( $order->get_fees() as $fee_item ) {
			if ( '' !== $fee_item->get_meta( self::META_ELIGIBLE_QTY ) ) {
				return $fee_item;
			}
		}

		return null;
	}
}
