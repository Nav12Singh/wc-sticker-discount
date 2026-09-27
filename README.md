# WC Sticker Discount

Automatically discounts eligible stickers when the cart reaches a minimum quantity.

When the cart contains **5 or more eligible stickers**, a **10% discount** is applied to those sticker products only. The discount is shown as its own line in the cart and checkout, is included in the order total, and is saved on the order. All rules can be changed in **WooCommerce → Sticker Discount**.

| | |
|---|---|
| Version | 1.0.0 |
| Author | EGNOTO |
| License | [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) |
| Requires | WordPress 7.0+, WooCommerce 9.0+, PHP 7.4+ |

## Features

- Discounts only eligible stickers; other products in the cart are never discounted.
- Products already on sale neither count towards the minimum nor get the discount (configurable, on by default).
- Recalculated on every cart change: quantity updates, removals, classic AJAX and Store API (block) updates. The discount can never be applied twice.
- Product prices are never changed. The discount is a separate cart line.
- Saves the discount amount and eligible quantity as order meta.
- Works for guests and logged-in customers, on the block Cart/Checkout and the classic `[woocommerce_cart]` / `[woocommerce_checkout]` shortcodes.
- Compatible with High-Performance Order Storage (HPOS) and the Cart/Checkout blocks.
- Correct with coupons and taxes (prices including or excluding tax, mixed tax rates).
- Admin page with an AJAX category search and a live "Try the rule" preview.

## Environment used for development and testing

| Software | Version |
|---|---|
| WordPress | 7.1.2 |
| WooCommerce | 11.1.2 (HPOS enabled, block and classic Cart/Checkout) |
| PHP | 8.2.29 |
| Theme | Storefront |

## Installation

1. Copy the `wc-sticker-discount` folder to `wp-content/plugins/`, or upload the zip via **Plugins → Add New → Upload Plugin**.
2. Make sure WooCommerce is installed and active. The plugin shows an admin notice and does nothing without it.
3. Activate **WC Sticker Discount** from the Plugins screen.
4. Create a product category with the slug `stickers` (**Products → Categories**) and assign your sticker products to it. It is used automatically until categories are saved in the settings.
5. Optional: adjust the rules in **WooCommerce → Sticker Discount** (also linked as **Settings** on the Plugins screen).

## Settings

**WooCommerce → Sticker Discount**

| Setting | Description | Default |
|---|---|---|
| Enable | Switch the discount on or off. | On |
| Discount label | Text of the cart/checkout line. Required. For percentage discounts the rate is appended, e.g. "Sticker Discount (10%)". | Sticker Discount |
| Eligible categories | Products in these categories and their sub-categories count as stickers. Type at least 3 characters to search (AJAX, like the Upsells field). Categories without products can be chosen too. | `stickers` |
| Sale products | Exclude products that are already on sale. | On |
| Minimum quantity | Total quantity of eligible products needed (whole number, 1 or more). | 5 |
| Discount type | Percentage of the eligible products, or a fixed amount off them. | Percentage |
| Discount value | 0–100 for a percentage; any amount of 0 or more for a fixed discount. A fixed amount is never more than the eligible products' total. | 10 |

Invalid values are rejected with an error message and the previous value is kept. The page also has a **"Try the rule"** preview that shows the effect of the current form on a sample cart, including changes that have not been saved yet.

## Testing

### Test data

WP-CLI, run from the WordPress root:

```bash
wp wc product_cat create --name=Stickers --slug=stickers --user=1
wp wc product create --name="Sticker A" --regular_price=10 --categories='[{"id":<stickers_id>}]' --user=1
wp wc product create --name="Sticker B" --regular_price=10 --categories='[{"id":<stickers_id>}]' --user=1
wp wc product create --name="Sticker C" --regular_price=12 --sale_price=10 --categories='[{"id":<stickers_id>}]' --user=1
wp wc product create --name="T-Shirt" --regular_price=20 --user=1
```

### Simple products

Run these as a guest and again as a logged-in customer:

1. Add Sticker A ×2, Sticker B ×3, Sticker C ×1 and T-Shirt ×2 to the cart.
   **Expected:** subtotal 100, a separate line "Sticker Discount (10%) −5.00", total 95. The eligible quantity is 5: Sticker C is on sale and the T-Shirt is not a sticker.
2. Change Sticker B to 2. **Expected:** the discount line disappears (4 eligible), total 90.
3. Change Sticker B back to 3, then remove Sticker A. **Expected:** no discount.
4. Add Sticker A ×2 again and refresh the cart several times. **Expected:** exactly one discount line of −5.00.
5. Check out, e.g. with Cash on delivery. **Expected:** order total 95 with the discount fee line.
6. Open the order in **WooCommerce → Orders**. **Expected:** the "Sticker Discount" panel shows Discount amount 5.00 and Eligible sticker quantity 5.
7. Change the settings (e.g. fixed amount 7, minimum 6, sale products included) and repeat step 1 to see the new rules apply.

Steps 1–5 behave the same on the block Cart/Checkout and on classic shortcode pages.

### Variable products

Variations have no categories of their own; they use the parent product's categories. Create a variable product in the `stickers` category with a **Size** attribute:

| Variation | Price | Counts? |
|---|---|---|
| Small | 10 | Yes |
| Medium | 15, on sale for 12 | No (on sale) |
| Large | 20 | Yes |

| Cart | Expected |
|---|---|
| Small ×3 + Large ×2 | 5 eligible, −7.00 (10% of 30 + 40) |
| Change Large to 1 | 4 eligible, no discount |
| Small ×3 + Large ×2 + Medium ×2 | Still −7.00: the on-sale Medium does not count |
| Small ×3 + Medium ×2 | 5 stickers but only 3 eligible, no discount |
| Small ×2 + Sticker A ×3 | Variations and simple products combine: 5 eligible, −5.00 |

## How it works

- **Cart:** `WCSD_Discount` hooks into `woocommerce_cart_calculate_fees`. It adds up the quantity and total of the eligible cart lines and, when the minimum is reached, adds a single negative fee with a fixed ID. WooCommerce clears all fees before every totals calculation, so the discount is rebuilt from the current cart each time and can never stack. Product prices are not modified.
- **Tax:** when taxes are enabled, `woocommerce_cart_totals_get_fees_from_cart_taxes` sets the tax part of the discount from the eligible lines only. WooCommerce would otherwise spread it across every item in the cart.
- **Totals:** a negative fee is part of the WooCommerce totals, so it shows as its own line in the cart, checkout, emails and admin, and is included in the order total for both the classic and block checkout.
- **Orders:** `WCSD_Order` tags the order fee line created from that fee and copies the amount and eligible quantity to order meta just before the order is saved. If a pending order is reused and the discount no longer applies, the meta is removed. All order data goes through the `WC_Order` API, so it works with HPOS and legacy post storage.
- **Settings:** `WCSD_Settings` is the only place that reads options. `WCSD_Settings_Page` adds the admin page (`manage_woocommerce` capability, nonce-checked) and renders, validates and saves its fields with the WooCommerce settings API.

### Order meta

| Meta key | Value |
|---|---|
| `_wcsd_discount_amount` | Discount as shown in the cart; includes tax when the store displays prices including tax. |
| `_wcsd_eligible_qty` | Total quantity of eligible stickers. |

## File structure

```
wc-sticker-discount/
├── wc-sticker-discount.php            Bootstrap, WooCommerce check, HPOS/blocks compatibility
├── uninstall.php                      Removes the plugin options (order meta is kept)
├── readme.txt                         WordPress.org-style readme
├── includes/
│   ├── class-wcsd-settings.php        Settings and defaults
│   ├── class-wcsd-discount.php        Eligibility, calculation and cart fee
│   ├── class-wcsd-order.php           Order meta and admin order display
│   └── class-wcsd-settings-page.php   WooCommerce → Sticker Discount admin page and category search
└── assets/
    ├── css/admin.css                  Admin page styles
    └── js/admin.js                    AJAX category search and live preview
```

## Hooks

### WordPress / WooCommerce hooks used

| Hook | Purpose |
|---|---|
| `plugins_loaded` | Boot the plugin after WooCommerce is loaded. |
| `before_woocommerce_init` | Declare HPOS (`custom_order_tables`) and `cart_checkout_blocks` compatibility. |
| `admin_notices` | Notice when WooCommerce is missing. |
| `plugin_action_links_{plugin}` | "Settings" link on the Plugins screen. |
| `admin_menu`, `load-{page}` | Add the WooCommerce → Sticker Discount page and save it. |
| `admin_init` | Redirect the old WooCommerce → Settings → Sticker Discount tab URL to the new page. |
| `woocommerce_screen_ids` | Load the WooCommerce admin styles and scripts on that page. |
| `admin_enqueue_scripts` | Load the page's own stylesheet and script. |
| `woocommerce_admin_field_wcsd_category_search` | Output the AJAX category search field. |
| `wp_ajax_wcsd_search_categories` | Category search endpoint (nonce and `manage_woocommerce` checked). |
| `woocommerce_admin_settings_sanitize_option_{option}` | Validate the settings. |
| `woocommerce_cart_calculate_fees` | Add the discount line. |
| `woocommerce_cart_totals_get_fees_from_cart_taxes` | Take the discount's tax from the eligible lines only. |
| `woocommerce_checkout_create_order_fee_item` | Tag the order fee line (classic and block checkout). |
| `woocommerce_checkout_create_order` | Save order meta (classic checkout). |
| `woocommerce_store_api_checkout_update_order_from_request` | Save order meta (block checkout). |
| `woocommerce_admin_order_data_after_order_details` | Show the saved values on the order screen. |

### Filters provided by the plugin

| Filter | Arguments | Purpose |
|---|---|---|
| `wcsd_settings` | `$values` | Override settings in code. |
| `wcsd_is_product_eligible` | `$is_eligible, $product, $cart_item` | Change how an eligible sticker is identified. |
| `wcsd_discount_amount` | `$amount, $eligible_qty, $eligible_total, $cart` | Adjust the calculated discount. |

Example: also treat products tagged `sticker` as eligible.

```php
add_filter(
	'wcsd_is_product_eligible',
	function ( $is_eligible, $product ) {
		$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

		return $is_eligible || has_term( 'sticker', 'product_tag', $product_id );
	},
	10,
	2
);
```

## Assumptions

- An eligible sticker is a product in a selected category (default: `stickers`) or one of its sub-categories. Variations use their parent product's categories.
- "On sale" means `WC_Product::is_on_sale()`, which respects scheduled sale dates. It is checked per cart line, so one variation on sale is excluded while its siblings are not.
- On-sale stickers neither receive the discount nor count towards the minimum quantity.
- The minimum is the total quantity of eligible stickers (2 × A + 3 × B = 5), not the number of different products.
- The discount is calculated on what the customer pays for the eligible lines: after coupons, and including tax when prices are entered including tax. So 10% of stickers costing 50.00 is always 5.00 off those stickers, and a coupon that already made the stickers free leaves nothing to discount. A fixed amount is treated the same way and is never more than the eligible lines' total.
- The discount is split between the net price and each tax rate in the same proportion as the eligible lines, so only the stickers' tax is reduced.

## Known limitations

- The discount is a cart fee, so WooCommerce features that ignore fees use the pre-discount amounts: the free shipping "minimum order amount", the coupon "minimum spend", and suggested refund amounts per line item.
- The order meta is a snapshot taken when the order is placed. Editing or removing the fee line in the admin later does not change it. Using "Recalculate" on an order lets WooCommerce re-apportion the fee's tax across all items, which can change the tax split on orders with mixed tax rates.
- When a customer returns to the block checkout to retry a failed payment, the order meta is refreshed when the order is placed again, not while the checkout page is being viewed.
- Multi-currency plugins: a fixed amount is used as-is in the active currency. A percentage works in any currency.
- Product bundles, composites and subscriptions are not specifically handled; child items count as separate products.

## Uninstall

Deleting the plugin from the Plugins screen removes its options. Order meta is kept because it is part of each order's record.

## Changelog

### 1.0.0

- Initial release.
