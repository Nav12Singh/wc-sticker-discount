<?php
/**
 * Cart discount logic.
 *
 * @package WC_Sticker_Discount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out the sticker discount and adds it to the cart as a negative fee.
 *
 * Fees are rebuilt from scratch every time WooCommerce calculates totals,
 * so the discount can never stack and always reflects the current cart.
 */
class WCSD_Discount {

	const FEE_ID = 'wcsd_sticker_discount';

	/**
	 * Plugin settings.
	 *
	 * @var WCSD_Settings
	 */
	private $settings;

	/**
	 * Category IDs (including child categories) resolved for this request.
	 *
	 * @var int[]|null
	 */
	private $category_ids = null;

	/**
	 * Constructor.
	 *
	 * @param WCSD_Settings $settings Plugin settings.
	 */
	public function __construct( WCSD_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register WooCommerce hooks.
	 */
	public function register_hooks() {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_cart_fee' ) );
		add_filter( 'woocommerce_cart_totals_get_fees_from_cart_taxes', array( $this, 'set_fee_taxes' ), 10, 2 );
	}

	/**
	 * Add the discount line to the cart when it qualifies.
	 *
	 * @param WC_Cart $cart Cart being calculated.
	 */
	public function add_cart_fee( $cart ) {
		if ( ! $this->settings->is_enabled() ) {
			return;
		}

		$discount = $this->calculate( $cart );

		if ( $discount['amount'] <= 0 ) {
			return;
		}

		$cart->fees_api()->add_fee(
			array(
				'id'                => self::FEE_ID,
				'name'              => $this->get_fee_name(),
				'amount'            => -1 * $discount['net_amount'],
				'wcsd_eligible_qty' => $discount['eligible_qty'],
				'wcsd_taxes'        => $discount['taxes'],
			)
		);
	}

	/**
	 * Take the discount's tax from the eligible lines only.
	 *
	 * WooCommerce would otherwise spread the tax of a negative fee across every item in the cart.
	 *
	 * @param array  $taxes Fee taxes in WooCommerce's internal precision.
	 * @param object $fee   Fee being totalled.
	 * @return array
	 */
	public function set_fee_taxes( $taxes, $fee ) {
		if ( empty( $fee->object->id ) || self::FEE_ID !== $fee->object->id || ! isset( $fee->object->wcsd_taxes ) ) {
			return $taxes;
		}

		// WooCommerce may reduce a negative fee so the order total cannot go below zero.
		$requested = wc_add_number_precision( (float) $fee->object->amount );
		$scale     = $requested < 0 ? min( 1, $fee->total / $requested ) : 1;

		$discount_taxes = array();

		foreach ( $fee->object->wcsd_taxes as $rate_id => $tax ) {
			$discount_taxes[ $rate_id ] = -1 * $tax * $scale;
		}

		return wc_add_number_precision_deep( $discount_taxes );
	}

	/**
	 * Calculate the discount for a cart.
	 *
	 * The discount is based on what the customer pays for the eligible lines: after coupons,
	 * and including tax when prices are entered including tax. It is then split between the
	 * net price and each tax rate in the same proportion as those lines.
	 *
	 * @param WC_Cart $cart Cart to inspect. Line totals must already be calculated.
	 * @return array {
	 *     @type int   $eligible_qty   Total quantity of eligible products.
	 *     @type float $eligible_total Amount paid for the eligible lines.
	 *     @type float $amount         Discount amount, 0 when the cart does not qualify.
	 *     @type float $net_amount     Discount amount excluding tax.
	 *     @type array $taxes          Tax part of the discount, keyed by tax rate ID.
	 * }
	 */
	public function calculate( $cart ) {
		$eligible_qty   = 0;
		$eligible_net   = 0.0;
		$eligible_taxes = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $this->is_eligible( $cart_item['data'], $cart_item ) ) {
				continue;
			}

			$eligible_qty += (int) $cart_item['quantity'];
			$eligible_net += (float) $cart_item['line_total'];

			$line_taxes = isset( $cart_item['line_tax_data']['total'] ) ? (array) $cart_item['line_tax_data']['total'] : array();

			foreach ( $line_taxes as $rate_id => $tax ) {
				$eligible_taxes[ $rate_id ] = ( isset( $eligible_taxes[ $rate_id ] ) ? $eligible_taxes[ $rate_id ] : 0 ) + (float) $tax;
			}
		}

		$eligible_total = wc_prices_include_tax() ? $eligible_net + array_sum( $eligible_taxes ) : $eligible_net;
		$amount         = 0.0;

		if ( $eligible_qty >= $this->settings->get_min_qty() && $eligible_total > 0 ) {
			$amount = $this->get_discount_amount( $eligible_total );
		}

		/**
		 * Filter the calculated sticker discount amount.
		 *
		 * @param float   $amount         Discount amount (positive number).
		 * @param int     $eligible_qty   Total quantity of eligible products.
		 * @param float   $eligible_total Amount paid for the eligible lines.
		 * @param WC_Cart $cart           Cart being calculated.
		 */
		$amount   = (float) apply_filters( 'wcsd_discount_amount', $amount, $eligible_qty, $eligible_total, $cart );
		$amount   = max( 0, min( $amount, $eligible_total ) );
		$ratio    = $eligible_total > 0 ? $amount / $eligible_total : 0;
		$decimals = wc_get_price_decimals();

		$taxes = array();

		foreach ( $eligible_taxes as $rate_id => $tax ) {
			$taxes[ $rate_id ] = round( $tax * $ratio, $decimals );
		}

		// When prices include tax the amount already contains the tax, so net + tax must add up to it exactly.
		$net_amount = wc_prices_include_tax() ? round( $amount - array_sum( $taxes ), $decimals ) : $amount;

		return array(
			'eligible_qty'   => $eligible_qty,
			'eligible_total' => $eligible_total,
			'amount'         => $amount,
			'net_amount'     => $net_amount,
			'taxes'          => $taxes,
		);
	}

	/**
	 * Whether a product counts towards and receives the discount.
	 *
	 * @param WC_Product $product   Product in the cart (variation or simple).
	 * @param array      $cart_item Cart item data.
	 * @return bool
	 */
	public function is_eligible( $product, $cart_item = array() ) {
		$category_ids = $this->get_category_ids();

		// Variations have no categories of their own, so check the parent product.
		$product_id  = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$is_eligible = ! empty( $category_ids ) && has_term( $category_ids, 'product_cat', $product_id );

		if ( $is_eligible && $this->settings->exclude_sale_items() && $product->is_on_sale() ) {
			$is_eligible = false;
		}

		/**
		 * Filter whether a cart product is eligible for the sticker discount.
		 *
		 * @param bool       $is_eligible Whether the product is eligible.
		 * @param WC_Product $product     Product in the cart.
		 * @param array      $cart_item   Cart item data.
		 */
		return (bool) apply_filters( 'wcsd_is_product_eligible', $is_eligible, $product, $cart_item );
	}

	/**
	 * Discount for the eligible lines, rounded to the store's price decimals.
	 *
	 * @param float $eligible_total Amount paid for the eligible lines.
	 * @return float
	 */
	private function get_discount_amount( $eligible_total ) {
		$value = $this->settings->get_discount_value();

		if ( 'fixed' === $this->settings->get_discount_type() ) {
			$amount = min( $value, $eligible_total );
		} else {
			$amount = $eligible_total * $value / 100;
		}

		return round( $amount, wc_get_price_decimals() );
	}

	/**
	 * Label for the cart line, e.g. "Sticker Discount (10%)".
	 *
	 * @return string
	 */
	private function get_fee_name() {
		$label = $this->settings->get_fee_label();

		if ( 'percent' === $this->settings->get_discount_type() ) {
			/* translators: 1: discount label, 2: discount percentage. */
			$label = sprintf( __( '%1$s (%2$s%%)', 'wc-sticker-discount' ), $label, wc_format_localized_decimal( $this->settings->get_discount_value() ) );
		}

		return $label;
	}

	/**
	 * Selected categories plus their child categories.
	 *
	 * @return int[]
	 */
	private function get_category_ids() {
		if ( null === $this->category_ids ) {
			$selected = $this->settings->get_category_ids();
			$ids      = $selected;

			foreach ( $selected as $term_id ) {
				$children = get_term_children( $term_id, 'product_cat' );

				if ( ! is_wp_error( $children ) ) {
					$ids = array_merge( $ids, $children );
				}
			}

			$this->category_ids = array_map( 'absint', array_unique( $ids ) );
		}

		return $this->category_ids;
	}
}
