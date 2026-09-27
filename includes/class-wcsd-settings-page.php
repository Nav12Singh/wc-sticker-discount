<?php
/**
 * Admin settings page.
 *
 * @package WC_Sticker_Discount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds WooCommerce > Sticker Discount.
 *
 * Fields are rendered, sanitized and saved with the WooCommerce settings API.
 */
class WCSD_Settings_Page {

	const PAGE_SLUG           = 'wcsd-sticker-discount';
	const NONCE_ACTION        = 'wcsd_save_settings';
	const SEARCH_NONCE_ACTION = 'wcsd_search_categories';
	const MIN_SEARCH_LENGTH   = 3;

	/**
	 * Plugin settings.
	 *
	 * @var WCSD_Settings
	 */
	private $settings;

	/**
	 * Admin page hook suffix, set when the menu is registered.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param WCSD_Settings $settings Plugin settings.
	 */
	public function __construct( WCSD_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Admin URL of the settings page.
	 *
	 * @return string
	 */
	public static function get_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Register admin hooks.
	 */
	public function register_hooks() {
		// After WooCommerce > Settings (50) and before Status (60).
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 55 );
		add_filter( 'woocommerce_screen_ids', array( $this, 'add_screen_id' ) );
		add_action( 'admin_init', array( $this, 'redirect_old_settings_tab' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_admin_field_wcsd_category_search', array( $this, 'render_category_search_field' ) );
		add_action( 'wp_ajax_wcsd_search_categories', array( $this, 'ajax_search_categories' ) );

		add_filter( 'woocommerce_admin_settings_sanitize_option_' . WCSD_Settings::option_name( 'fee_label' ), array( $this, 'sanitize_fee_label' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . WCSD_Settings::option_name( 'min_qty' ), array( $this, 'sanitize_min_qty' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . WCSD_Settings::option_name( 'discount_type' ), array( $this, 'sanitize_discount_type' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . WCSD_Settings::option_name( 'discount_value' ), array( $this, 'sanitize_discount_value' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . WCSD_Settings::option_name( 'categories' ), array( $this, 'sanitize_categories' ) );
	}

	/**
	 * Add the page under the WooCommerce menu.
	 */
	public function add_menu_page() {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Sticker Discount', 'wc-sticker-discount' ),
			__( 'Sticker Discount', 'wc-sticker-discount' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'maybe_save' ) );
			add_action( 'load-' . $this->hook_suffix, array( $this, 'hide_third_party_notices' ) );
		}
	}

	/**
	 * Load the page styles and the live preview script.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! $this->hook_suffix || $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wcsd-admin', plugins_url( 'assets/css/admin.css', WCSD_FILE ), array(), WCSD_VERSION );
		wp_enqueue_script( 'wcsd-admin', plugins_url( 'assets/js/admin.js', WCSD_FILE ), array( 'jquery', 'selectWoo' ), WCSD_VERSION, true );

		wp_localize_script(
			'wcsd-admin',
			'wcsdAdmin',
			array(
				'samplePrice'  => 10,
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'searchNonce'  => wp_create_nonce( self::SEARCH_NONCE_ACTION ),
				'defaultLabel' => __( 'Sticker Discount', 'wc-sticker-discount' ),
				'currency'     => array(
					'symbol'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
					'format'      => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
					'decimals'    => wc_get_price_decimals(),
					'decimalSep'  => wc_get_price_decimal_separator(),
					'thousandSep' => wc_get_price_thousand_separator(),
				),
				'i18n'         => array(
					'active'           => __( 'Active', 'wc-sticker-discount' ),
					'off'              => __( 'Off', 'wc-sticker-discount' ),
					/* translators: %s: number of stickers. */
					'inCart'           => __( '%s in cart', 'wc-sticker-discount' ),
					/* translators: %s: number of stickers still needed. */
					'needMore'         => __( 'Add %s more to unlock the discount.', 'wc-sticker-discount' ),
					'applied'          => __( 'Discount applied.', 'wc-sticker-discount' ),
					'disabled'         => __( 'The discount is off, so this cart pays full price.', 'wc-sticker-discount' ),
					/* translators: %s: number of characters still needed. */
					'typeMore'         => __( 'Type %s more characters to search', 'wc-sticker-discount' ),
					'typeOne'          => __( 'Type 1 more character to search', 'wc-sticker-discount' ),
					'searching'        => __( 'Searching…', 'wc-sticker-discount' ),
					'noMatches'        => __( 'No categories found', 'wc-sticker-discount' ),
					'loadError'        => __( 'Could not load categories. Try again.', 'wc-sticker-discount' ),
					'categoryRequired' => __( 'Select at least one eligible category.', 'wc-sticker-discount' ),
				),
			)
		);
	}

	/**
	 * Load WooCommerce admin styles and scripts (enhanced selects, tooltips) on this page.
	 *
	 * @param array $screen_ids WooCommerce screen IDs.
	 * @return array
	 */
	public function add_screen_id( $screen_ids ) {
		$screen_ids[] = 'woocommerce_page_' . self::PAGE_SLUG;

		return $screen_ids;
	}

	/**
	 * Send the old WooCommerce > Settings tab URL to the new page.
	 */
	public function redirect_old_settings_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect.
		if ( isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && 'wcsd_sticker_discount' === $_GET['tab'] ) {
			wp_safe_redirect( self::get_url() );
			exit;
		}
	}

	/**
	 * Keep this page free of other plugins' admin notices.
	 *
	 * The page's own messages are printed inside the page, not through these hooks.
	 */
	public function hide_third_party_notices() {
		// Runs just before WordPress prints notices, so late registrations are caught too.
		add_action(
			'in_admin_header',
			function () {
				foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
					remove_all_actions( $hook );
				}
			},
			PHP_INT_MAX
		);
	}

	/**
	 * Save the submitted settings before the page is rendered.
	 */
	public function maybe_save() {
		if ( empty( $_POST['save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'wc-sticker-discount' ), 403 );
		}

		$this->load_settings_api();

		WC_Admin_Settings::save_fields( $this->get_fields() );
		WC_Admin_Settings::add_message( __( 'Your settings have been saved.', 'wc-sticker-discount' ) );
	}

	/**
	 * Output the settings page.
	 */
	public function render_page() {
		$this->load_settings_api();

		// Fresh read, so the status reflects values saved in this request.
		$rule = new WCSD_Settings();
		?>
		<div class="wrap woocommerce wcsd-admin">
			<div class="wcsd-banner">
				<span class="wcsd-banner__badge dashicons dashicons-tag" aria-hidden="true"></span>
				<div class="wcsd-banner__text">
					<div class="wcsd-banner__heading">
						<h1 class="wcsd-banner__title"><?php esc_html_e( 'WC Sticker Discount', 'wc-sticker-discount' ); ?></h1>
						<span class="wcsd-banner__version">
							<?php
							/* translators: %s: plugin version number. */
							printf( esc_html__( 'Version %s', 'wc-sticker-discount' ), esc_html( WCSD_VERSION ) );
							?>
						</span>
					</div>
					<p class="wcsd-banner__description"><?php esc_html_e( 'Automatically discounts eligible stickers when the cart reaches a minimum quantity.', 'wc-sticker-discount' ); ?></p>
				</div>
				<span class="wcsd-status<?php echo $rule->is_enabled() ? '' : ' is-off'; ?>" data-wcsd-status>
					<?php echo $rule->is_enabled() ? esc_html__( 'Active', 'wc-sticker-discount' ) : esc_html__( 'Off', 'wc-sticker-discount' ); ?>
				</span>
			</div>
			<hr class="wp-header-end">
			<?php WC_Admin_Settings::show_messages(); ?>

			<div class="wcsd-layout">
				<form method="post" id="mainform" action="" class="wcsd-form">
					<?php WC_Admin_Settings::output_fields( $this->get_fields() ); ?>
					<p class="submit">
						<button name="save" class="button-primary woocommerce-save-button" type="submit" value="<?php esc_attr_e( 'Save changes', 'wc-sticker-discount' ); ?>"><?php esc_html_e( 'Save changes', 'wc-sticker-discount' ); ?></button>
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					</p>
				</form>

				<aside class="wcsd-preview" aria-labelledby="wcsd-preview-title" hidden>
					<h2 id="wcsd-preview-title"><?php esc_html_e( 'Try the rule', 'wc-sticker-discount' ); ?></h2>
					<p class="wcsd-preview__intro">
						<?php
						printf(
							/* translators: %s: sample sticker price. */
							esc_html__( 'A sample cart of %s stickers. The preview follows the form, including changes you have not saved yet.', 'wc-sticker-discount' ),
							wp_kses_post( wc_price( 10 ) )
						);
						?>
					</p>

					<div class="wcsd-stepper">
						<button type="button" class="wcsd-stepper__button" data-wcsd-step="-1" aria-label="<?php esc_attr_e( 'Remove a sticker', 'wc-sticker-discount' ); ?>">&minus;</button>
						<output class="wcsd-stepper__count" data-wcsd-count aria-live="polite"></output>
						<button type="button" class="wcsd-stepper__button" data-wcsd-step="1" aria-label="<?php esc_attr_e( 'Add a sticker', 'wc-sticker-discount' ); ?>">+</button>
					</div>

					<ul class="wcsd-sheet" data-wcsd-sheet aria-hidden="true"></ul>
					<p class="wcsd-preview__status" data-wcsd-progress aria-live="polite"></p>

					<dl class="wcsd-receipt">
						<div class="wcsd-receipt__row">
							<dt><?php esc_html_e( 'Subtotal', 'wc-sticker-discount' ); ?></dt>
							<dd data-wcsd-subtotal></dd>
						</div>
						<div class="wcsd-receipt__row wcsd-receipt__row--discount" data-wcsd-discount-row>
							<dt data-wcsd-label></dt>
							<dd data-wcsd-discount></dd>
						</div>
						<div class="wcsd-receipt__row wcsd-receipt__row--total">
							<dt><?php esc_html_e( 'Total', 'wc-sticker-discount' ); ?></dt>
							<dd data-wcsd-total></dd>
						</div>
					</dl>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings fields.
	 *
	 * @return array
	 */
	private function get_fields() {
		$defaults = $this->settings->get_defaults();

		return array(
			array(
				'id'    => 'wcsd_general',
				'type'  => 'title',
				'title' => __( 'General', 'wc-sticker-discount' ),
				'desc'  => __( 'The discount is added as its own line in the cart and checkout. Product prices are never changed.', 'wc-sticker-discount' ),
			),
			array(
				'id'      => WCSD_Settings::option_name( 'enabled' ),
				'type'    => 'checkbox',
				'title'   => __( 'Enable', 'wc-sticker-discount' ),
				'desc'    => __( 'Enable the sticker discount', 'wc-sticker-discount' ),
				'default' => $defaults['enabled'],
			),
			array(
				'id'                => WCSD_Settings::option_name( 'fee_label' ),
				'type'              => 'text',
				'title'             => __( 'Discount label', 'wc-sticker-discount' ),
				'desc_tip'          => __( 'Shown in the cart, checkout and order. For percentage discounts the rate is added automatically, e.g. "Sticker Discount (10%)".', 'wc-sticker-discount' ),
				'default'           => $defaults['fee_label'],
				'custom_attributes' => array(
					'required' => 'required',
				),
			),
			array(
				'id'   => 'wcsd_general',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'wcsd_eligibility',
				'type'  => 'title',
				'title' => __( 'Which products count', 'wc-sticker-discount' ),
			),
			array(
				'id'                => WCSD_Settings::option_name( 'categories' ),
				'type'              => 'wcsd_category_search',
				'title'             => __( 'Eligible categories', 'wc-sticker-discount' ),
				'desc_tip'          => __( 'Products in these categories (and their sub-categories) count as stickers. Type at least 3 characters to search.', 'wc-sticker-discount' ),
				'default'           => array_map( 'strval', $this->settings->get_default_category_ids() ),
				'custom_attributes' => array(
					'data-placeholder' => __( 'Search for a category…', 'wc-sticker-discount' ),
				),
			),
			array(
				'id'      => WCSD_Settings::option_name( 'exclude_sale' ),
				'type'    => 'checkbox',
				'title'   => __( 'Sale products', 'wc-sticker-discount' ),
				'desc'    => __( 'Exclude products that are already on sale', 'wc-sticker-discount' ),
				'default' => $defaults['exclude_sale'],
			),
			array(
				'id'   => 'wcsd_eligibility',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'wcsd_rule',
				'type'  => 'title',
				'title' => __( 'Discount rule', 'wc-sticker-discount' ),
			),
			array(
				'id'                => WCSD_Settings::option_name( 'min_qty' ),
				'type'              => 'number',
				'title'             => __( 'Minimum quantity', 'wc-sticker-discount' ),
				'desc_tip'          => __( 'Total quantity of eligible products needed before the discount applies.', 'wc-sticker-discount' ),
				'default'           => $defaults['min_qty'],
				'css'               => 'width:80px;',
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
			),
			array(
				'id'      => WCSD_Settings::option_name( 'discount_type' ),
				'type'    => 'select',
				'title'   => __( 'Discount type', 'wc-sticker-discount' ),
				'class'   => 'wc-enhanced-select',
				'default' => $defaults['discount_type'],
				'options' => array(
					'percent' => __( 'Percentage of eligible products', 'wc-sticker-discount' ),
					'fixed'   => __( 'Fixed amount off eligible products', 'wc-sticker-discount' ),
				),
			),
			array(
				'id'       => WCSD_Settings::option_name( 'discount_value' ),
				'type'     => 'text',
				'title'    => __( 'Discount value', 'wc-sticker-discount' ),
				'desc_tip' => __( 'Percentage (0-100) or fixed amount, depending on the discount type. A fixed amount is never more than the total of the eligible products.', 'wc-sticker-discount' ),
				'class'    => 'wc_input_decimal',
				'css'      => 'width:80px;',
				'default'  => $defaults['discount_value'],
			),
			array(
				'id'   => 'wcsd_rule',
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * Keep the previous label when the submitted one is empty.
	 *
	 * @param mixed $value Label already cleaned by WooCommerce.
	 * @return string|null Null tells WooCommerce not to save the field.
	 */
	public function sanitize_fee_label( $value ) {
		$label = trim( (string) $value );

		if ( '' === $label ) {
			WC_Admin_Settings::add_error( __( 'Discount label is required.', 'wc-sticker-discount' ) );
			return null;
		}

		return $label;
	}

	/**
	 * Keep the previous value when the minimum quantity is invalid.
	 *
	 * @param mixed $value     Sanitized value.
	 * @param array $option    Field definition.
	 * @param mixed $raw_value Submitted value.
	 * @return int|null Null tells WooCommerce not to save the field.
	 */
	public function sanitize_min_qty( $value, $option, $raw_value ) {
		$min_qty = filter_var( trim( (string) $raw_value ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );

		if ( false === $min_qty ) {
			WC_Admin_Settings::add_error( __( 'Minimum quantity must be a whole number of 1 or more.', 'wc-sticker-discount' ) );
			return null;
		}

		return $min_qty;
	}

	/**
	 * Keep the previous type when switching to a percentage with an invalid value.
	 *
	 * The matching error is added by sanitize_discount_value().
	 *
	 * @param mixed $value Sanitized value.
	 * @return string|null Null tells WooCommerce not to save the field.
	 */
	public function sanitize_discount_type( $value ) {
		return 'percent' === $value && ! $this->is_valid_percentage_submitted() ? null : $value;
	}

	/**
	 * Keep the previous value when the discount value is invalid.
	 *
	 * @param mixed $value     Sanitized value.
	 * @param array $option    Field definition.
	 * @param mixed $raw_value Submitted value.
	 * @return string|null Null tells WooCommerce not to save the field.
	 */
	public function sanitize_discount_value( $value, $option, $raw_value ) {
		$value = wc_format_decimal( $raw_value );

		if ( '' === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
			WC_Admin_Settings::add_error( __( 'Discount value must be a number of 0 or more.', 'wc-sticker-discount' ) );
			return null;
		}

		if ( 'percent' === $this->get_submitted( 'discount_type' ) && ! $this->is_valid_percentage_submitted() ) {
			WC_Admin_Settings::add_error( __( 'A percentage discount cannot be more than 100.', 'wc-sticker-discount' ) );
			return null;
		}

		return $value;
	}

	/**
	 * Only keep IDs of existing product categories; at least one is required.
	 *
	 * @param mixed $value Submitted category IDs.
	 * @return string[]|null Null keeps the previous categories.
	 */
	public function sanitize_categories( $value ) {
		$ids       = array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
		$valid_ids = array();

		if ( $ids ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'include'    => $ids,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);

			if ( ! is_wp_error( $terms ) ) {
				// Keep the order the categories were chosen in.
				$valid_ids = array_values( array_intersect( $ids, array_map( 'absint', $terms ) ) );
			}
		}

		if ( empty( $valid_ids ) ) {
			WC_Admin_Settings::add_error( __( 'Select at least one eligible category.', 'wc-sticker-discount' ) );
			return null;
		}

		return array_map( 'strval', $valid_ids );
	}

	/**
	 * Output the category field. Only saved categories are printed; the rest are searched over AJAX.
	 *
	 * @param array $field Field definition prepared by WooCommerce.
	 */
	public function render_category_search_field( $field ) {
		$selected_ids = array_filter( array_map( 'absint', (array) $field['value'] ) );
		$selected     = array();

		if ( $selected_ids ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'include'    => $selected_ids,
					'hide_empty' => false,
				)
			);

			if ( ! is_wp_error( $terms ) ) {
				$selected = $terms;
			}
		}

		$placeholder = isset( $field['custom_attributes']['data-placeholder'] ) ? $field['custom_attributes']['data-placeholder'] : '';
		?>
		<tr class="<?php echo esc_attr( $field['row_class'] ); ?>">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?> <?php echo wc_help_tip( $field['desc_tip'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip() escapes its output. ?></label>
			</th>
			<td class="forminp forminp-multiselect">
				<select
					id="<?php echo esc_attr( $field['id'] ); ?>"
					name="<?php echo esc_attr( $field['field_name'] ); ?>[]"
					class="wcsd-category-search"
					multiple="multiple"
					style="width: 400px;"
					data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
					data-minimum_input_length="<?php echo esc_attr( self::MIN_SEARCH_LENGTH ); ?>"
					required="required"
				>
					<?php foreach ( $selected as $term ) : ?>
						<option value="<?php echo esc_attr( $term->term_id ); ?>" selected="selected"><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * AJAX: search product categories by name, including categories without products.
	 */
	public function ajax_search_categories() {
		check_ajax_referer( self::SEARCH_NONCE_ACTION, 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( null, 403 );
		}

		$search = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		if ( mb_strlen( $search ) < self::MIN_SEARCH_LENGTH ) {
			wp_send_json( array() );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'name__like' => $search,
				'hide_empty' => false,
				'orderby'    => 'name',
				'number'     => 50,
			)
		);

		$results = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$results[] = array(
					'id'    => (string) $term->term_id,
					// Chosen chips show only the category's own name.
					'label' => $term->name,
					/* translators: 1: category name including its parents, 2: number of products. */
					'text'  => sprintf( __( '%1$s (%2$s)', 'wc-sticker-discount' ), $this->get_category_label( $term ), number_format_i18n( $term->count ) ),
				);
			}
		}

		wp_send_json( $results );
	}

	/**
	 * Category name with its parents for search results, e.g. "Clothing > Tshirts".
	 *
	 * @param WP_Term $term Product category.
	 * @return string
	 */
	private function get_category_label( $term ) {
		$names = array();

		foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );

			if ( $ancestor instanceof WP_Term ) {
				$names[] = $ancestor->name;
			}
		}

		$names[] = $term->name;

		return implode( ' > ', $names );
	}

	/**
	 * Whether the submitted discount value is a valid percentage (0-100).
	 *
	 * @return bool
	 */
	private function is_valid_percentage_submitted() {
		$value = wc_format_decimal( $this->get_submitted( 'discount_value' ) );

		return is_numeric( $value ) && (float) $value >= 0 && (float) $value <= 100;
	}

	/**
	 * Raw submitted value of another field on this tab.
	 *
	 * Type and value are validated together, but WooCommerce sanitizes fields one at a time.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function get_submitted( $key ) {
		$field = WCSD_Settings::option_name( $key );

		// The nonce is verified in maybe_save() before any field is sanitized.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
	}

	/**
	 * Make sure the WooCommerce settings API class is available.
	 */
	private function load_settings_api() {
		if ( ! class_exists( 'WC_Admin_Settings', false ) ) {
			include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
		}
	}
}
