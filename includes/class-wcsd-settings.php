<?php
/**
 * Settings repository.
 *
 * @package WC_Sticker_Discount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads plugin options and falls back to sensible defaults.
 */
class WCSD_Settings {

	const OPTION_PREFIX = 'wcsd_';

	/**
	 * Settings loaded for the current request.
	 *
	 * @var array|null
	 */
	private $values = null;

	/**
	 * Default value for every setting, keyed without the option prefix.
	 *
	 * The default categories are looked up only when needed, see get_default_category_ids().
	 *
	 * @return array
	 */
	public function get_defaults() {
		return array(
			'enabled'        => 'yes',
			'fee_label'      => __( 'Sticker Discount', 'wc-sticker-discount' ),
			'categories'     => null,
			'min_qty'        => 5,
			'discount_type'  => 'percent',
			'discount_value' => 10,
			'exclude_sale'   => 'yes',
		);
	}

	/**
	 * Full option name for a setting key.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function option_name( $key ) {
		return self::OPTION_PREFIX . $key;
	}

	/**
	 * Get a raw setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		if ( null === $this->values ) {
			$this->load();
		}

		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}

	/**
	 * Whether the discount is switched on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === $this->get( 'enabled' );
	}

	/**
	 * Label shown for the discount line in cart and checkout.
	 *
	 * @return string
	 */
	public function get_fee_label() {
		$label = trim( (string) $this->get( 'fee_label' ) );

		return '' !== $label ? $label : __( 'Sticker Discount', 'wc-sticker-discount' );
	}

	/**
	 * Product category IDs that make a product a "sticker".
	 *
	 * @return int[]
	 */
	public function get_category_ids() {
		return array_values( array_filter( array_map( 'absint', (array) $this->get( 'categories' ) ) ) );
	}

	/**
	 * Minimum eligible quantity needed to unlock the discount.
	 *
	 * @return int
	 */
	public function get_min_qty() {
		return max( 1, absint( $this->get( 'min_qty' ) ) );
	}

	/**
	 * Either "percent" or "fixed".
	 *
	 * @return string
	 */
	public function get_discount_type() {
		return 'fixed' === $this->get( 'discount_type' ) ? 'fixed' : 'percent';
	}

	/**
	 * Discount value: a percentage (0-100) or a fixed amount.
	 *
	 * @return float
	 */
	public function get_discount_value() {
		$value = max( 0, (float) wc_format_decimal( $this->get( 'discount_value' ) ) );

		return 'percent' === $this->get_discount_type() ? min( 100, $value ) : $value;
	}

	/**
	 * Whether products on sale are left out of the discount.
	 *
	 * @return bool
	 */
	public function exclude_sale_items() {
		return 'yes' === $this->get( 'exclude_sale' );
	}

	/**
	 * Load every option once per request.
	 */
	private function load() {
		$values = array();

		foreach ( $this->get_defaults() as $key => $default ) {
			$values[ $key ] = get_option( self::option_name( $key ), $default );
		}

		if ( null === $values['categories'] ) {
			$values['categories'] = $this->get_default_category_ids();
		}

		/**
		 * Filter the plugin settings before they are used.
		 *
		 * @param array $values Settings keyed without the option prefix.
		 */
		$this->values = apply_filters( 'wcsd_settings', $values );
	}

	/**
	 * Use the "stickers" product category until categories are saved.
	 *
	 * @return int[]
	 */
	public function get_default_category_ids() {
		$term = get_term_by( 'slug', 'stickers', 'product_cat' );

		return $term ? array( (int) $term->term_id ) : array();
	}
}
