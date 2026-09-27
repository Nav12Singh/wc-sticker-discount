=== WC Sticker Discount ===
Contributors: EGNOTO
Tags: woocommerce, discount, bulk discount, cart
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically discounts eligible sticker products when the cart contains a minimum quantity of them.

== Description ==

When the cart contains 5 or more eligible stickers, a 10% discount is applied to those sticker products only.
The discount is shown as a separate line in the cart and checkout, is included in the order total and is saved on the order.

* Products already on sale do not count towards the minimum and are not discounted (configurable, on by default).
* The discount is recalculated whenever the cart changes (quantity change, removal, AJAX / Store API updates).
* Product prices are never changed.
* Works for guests and logged-in customers, with the classic (shortcode) and block Cart/Checkout.
* Compatible with High-Performance Order Storage (HPOS).

All rules can be changed in WooCommerce > Sticker Discount.

= Environment used for development and testing =

* WordPress 7.1.2
* WooCommerce 11.1.2 (HPOS enabled, block Cart/Checkout and classic shortcode Cart/Checkout)
* PHP 8.2.29
* Theme: Storefront

== Installation ==

1. Copy the `wc-sticker-discount` folder to `wp-content/plugins/` (or upload the zip via Plugins > Add New > Upload Plugin).
2. Make sure WooCommerce is installed and active.
3. Activate "WC Sticker Discount" from the Plugins screen.
4. Create a product category with the slug `stickers` (Products > Categories) and assign your sticker products to it.
   It is selected automatically when no categories have been saved yet.
5. Optional: adjust the rules in WooCommerce > Sticker Discount.

== Settings ==

* Enable - switch the discount on or off.
* Discount label - text of the cart/checkout line (required). For percentage discounts the rate is appended, e.g. "Sticker Discount (10%)".
* Eligible categories - products in these categories (and their sub-categories) are treated as stickers. Default: `stickers`.
  Type at least 3 characters to search categories (AJAX, like the Upsells field); categories without products are included.
* Minimum quantity - total eligible quantity required. Default: 5.
* Discount type - percentage of the eligible products, or a fixed amount off them (never more than their total). Default: percentage.
  Coupons do not change the discount; they apply on top of it.
* Discount value - default: 10.
* Sale products - exclude products that are already on sale. Default: on.

== Testing ==

Create the test data (WP-CLI example, run from the WordPress root):

    wp wc product_cat create --name=Stickers --slug=stickers --user=1
    wp wc product create --name="Sticker A" --regular_price=10 --categories='[{"id":<stickers_id>}]' --user=1
    wp wc product create --name="Sticker B" --regular_price=10 --categories='[{"id":<stickers_id>}]' --user=1
    wp wc product create --name="Sticker C" --regular_price=12 --sale_price=10 --categories='[{"id":<stickers_id>}]' --user=1
    wp wc product create --name="T-Shirt" --regular_price=20 --user=1

Then, as a guest and again as a logged-in customer:

1. Add Sticker A x2, Sticker B x3, Sticker C x1, T-Shirt x2 to the cart.
   Expected: subtotal 100, a separate line "Sticker Discount (10%) -5.00", total 95.
   (Eligible quantity is 5: Sticker C is on sale and the T-Shirt is not a sticker.)
2. Change Sticker B to 2. Expected: the discount line disappears (eligible quantity 4), total 90.
3. Change Sticker B back to 3, then remove Sticker A. Expected: no discount.
4. Add Sticker A x2 again and refresh the cart several times. Expected: exactly one discount line of -5.00.
5. Go to checkout and place the order (e.g. Cash on delivery). Expected: order total 95 with the discount fee line.
6. Open the order in WooCommerce > Orders. The "Sticker Discount" panel shows Discount amount 5.00 and Eligible sticker quantity 5.
   The values are stored as order meta `_wcsd_discount_amount` (the discount as shown in the cart: including tax
   when the store displays prices including tax) and `_wcsd_eligible_qty`.
7. Change the settings (e.g. fixed amount 7, minimum 6, sale products included) and repeat step 1 to see the new rules apply.
8. Apply a coupon (e.g. a fixed cart coupon of 10). Expected: the sticker discount stays -5.00 and the coupon applies on top.

Steps 1-5 behave the same on the block Cart/Checkout pages and on classic pages using the
`[woocommerce_cart]` and `[woocommerce_checkout]` shortcodes.

== How it works ==

* `WCSD_Discount` hooks into `woocommerce_cart_calculate_fees`. It adds up the quantity and total of eligible cart lines
  and, when the minimum is reached, adds a single negative fee with a fixed ID. WooCommerce clears all fees before every
  totals calculation, so the discount is rebuilt from the current cart each time and can never be applied twice.
  Product prices are not modified.
* When taxes are enabled, `woocommerce_cart_totals_get_fees_from_cart_taxes` sets the tax part of the discount from the
  eligible lines only (WooCommerce would otherwise spread it across every item in the cart).
* A negative fee is part of the WooCommerce totals, so it is shown as its own line in the cart, checkout, emails and
  admin, and is included in the order total for both the classic and block checkout.
* `WCSD_Order` tags the order fee line created from that fee and copies the amount and eligible quantity to the order
  meta just before the order is saved. If a pending order is reused and the discount no longer applies, the meta is removed.
  All order data is read and written through the `WC_Order` API, so it works with HPOS and legacy post storage.
* `WCSD_Settings` is the only place that reads options. `WCSD_Settings_Page` adds the WooCommerce > Sticker Discount
  page (`manage_woocommerce` capability, nonce-checked) and renders, validates and saves its fields with the
  WooCommerce settings API.

Files:

* `wc-sticker-discount.php` - bootstrap, WooCommerce check, HPOS/blocks compatibility.
* `includes/class-wcsd-settings.php` - settings and defaults.
* `includes/class-wcsd-discount.php` - eligibility, calculation and cart fee.
* `includes/class-wcsd-order.php` - order meta and admin order display.
* `includes/class-wcsd-settings-page.php` - WooCommerce > Sticker Discount admin page.
* `assets/css/admin.css`, `assets/js/admin.js` - admin page styles, the AJAX category search and the live "Try the rule" preview (only loaded on that page).
* `uninstall.php` - removes the plugin options (order meta is kept).

== Hooks used ==

WordPress / WooCommerce hooks:

* `plugins_loaded` - boot the plugin after WooCommerce is loaded.
* `before_woocommerce_init` - declare HPOS (`custom_order_tables`) and `cart_checkout_blocks` compatibility.
* `admin_notices` - notice when WooCommerce is missing.
* `plugin_action_links_{plugin}` - "Settings" link on the Plugins screen.
* `admin_menu` / `load-{page}` - add the WooCommerce > Sticker Discount page and save it.
* `admin_init` - redirect the old WooCommerce > Settings > Sticker Discount tab URL to the new page.
* `woocommerce_screen_ids` - load the WooCommerce admin styles and scripts on that page.
* `admin_enqueue_scripts` - load the page's own stylesheet and preview script.
* `woocommerce_admin_field_wcsd_category_search` - output the AJAX category search field.
* `wp_ajax_wcsd_search_categories` - category search endpoint (nonce and `manage_woocommerce` checked).
* `woocommerce_admin_settings_sanitize_option_{option}` - validate the settings.
* `woocommerce_cart_calculate_fees` - add the discount line.
* `woocommerce_cart_totals_get_fees_from_cart_taxes` - take the discount's tax from the eligible lines only.
* `woocommerce_checkout_create_order_fee_item` - tag the order fee line (classic and block checkout).
* `woocommerce_checkout_create_order` - save order meta (classic checkout).
* `woocommerce_store_api_checkout_update_order_from_request` - save order meta (block checkout).
* `woocommerce_admin_order_data_after_order_details` - show the saved values on the order screen.

Filters provided by the plugin:

* `wcsd_settings` - override settings in code.
* `wcsd_is_product_eligible` - change how an eligible sticker is identified ( $is_eligible, $product, $cart_item ).
* `wcsd_discount_amount` - adjust the calculated discount ( $amount, $eligible_qty, $eligible_total, $cart ).
  `$eligible_total` is the eligible lines' price before coupons.

== Assumptions ==

* An eligible sticker is a product in the selected category (default: the `stickers` category) or one of its
  sub-categories. Variations use the categories of their parent product.
* "On sale" means `WC_Product::is_on_sale()`, which respects scheduled sale dates. It is checked per cart line, so a
  single variation on sale is excluded while its siblings are not.
* On-sale stickers neither receive the discount nor count towards the minimum quantity.
* The minimum is based on the total quantity of eligible stickers (2 x A + 3 x B = 5), not the number of different products.
* The discount is calculated on the eligible lines' price before coupons (including tax when prices are entered
  including tax), so applying a coupon does not change it: 10% of stickers that cost 50.00 is always 5.00, and the
  coupon applies on top. The discount is capped at what is still left to pay for those stickers after coupons, so a
  coupon that already made them (almost) free never pushes the discount onto other products. A fixed amount is
  treated the same way and is never more than that remaining amount.
* The discount is split between the net price and each tax rate in the same proportion as the eligible lines, so only
  the stickers' tax is reduced.

== Known limitations ==

* The discount is a cart fee, so WooCommerce features that ignore fees work on the pre-discount amounts:
  free shipping "minimum order amount", coupon "minimum spend", and suggested refund amounts per line item.
* The order meta is a snapshot taken when the order is placed. Editing or removing the fee line in the admin later does
  not change it. Using "Recalculate" on an order in the admin lets WooCommerce re-apportion the fee's tax across all
  items, which can change the tax split on orders with mixed tax rates.
* When a customer returns to the block checkout to retry a failed payment, the order meta is refreshed when the order
  is placed again, not while the checkout page is being viewed.
* Multi-currency plugins: a fixed amount is used as-is in the active currency (a percentage works in any currency).
* Product bundles/composites/subscriptions are not specifically handled: child items count as separate products.

== Changelog ==

= 1.0.0 =
* Initial release.
* Fix: applying a coupon no longer changes the sticker discount amount.
