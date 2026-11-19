=== Webtrix Product Suggestions ===
Contributors: webtrixteam
Tags: woocommerce, suggested products, cross sell, add to cart, persian
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show suggested products with prices on the WooCommerce product page and let customers add them to the cart in one click.

== Description ==

Webtrix Product Suggestions adds a small, unobtrusive box to the single product page that lists related or complementary products with their prices, for example a bag for a laptop, a case for a phone or shoe care for a pair of shoes. Customers can add the suggestions to the cart together with the main product, or one by one.

**Features**

* **Per-product list:** a "Suggested products" field inside the *Linked products* tab of the product editor, using the standard WooCommerce product search.
* **Global rules:** suggest products for a whole category (sub-categories included) or for the entire store, so you do not have to assign them product by product. All categories are detected automatically. A product's own list takes priority, or both can be combined.
* **Placement:** above or below the price, the short description or the add to cart section, or anywhere with the `[wtx_product_suggestion]` shortcode.
* **Three display styles:** list, grid cards and carousel. You choose how many products are visible at once; longer lists turn into a carousel automatically, and on phones one product is shown at a time with horizontal swipe.
* **Two add to cart methods:** checkboxes (ticked items are added together with the main product, with a live total) or a separate AJAX button per item.
* **Language setting:** Persian (default) or English, or follow the site language.
* RTL ready, live prices from WooCommerce, and out of stock or unpublished products are never shown.

Suggested products must be simple products. The main product can be any type, including variable products.

Persian and English translations are bundled with the plugin.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen, then activate it.
3. Go to **WooCommerce → Product Suggestions** to choose the position, display style and global rules.
4. To set the list for a single product, open the product, go to *Product data → Linked products* and use the **Suggested products** field.

== Frequently Asked Questions ==

= Does it work with variable products as the main product? =

Yes. The main product can be any type; the suggested products must be simple products.

= What happens if a product has its own list and there is also a global rule? =

By default the product's own list wins. You can change this in the settings to combine both.

= The box does not appear in the position I chose. =

Some themes and page builders do not use the standard WooCommerce hooks. The plugin tries to place the box automatically; if the result is not what you want, set the position to "Shortcode only" and use the `[wtx_product_suggestion]` shortcode wherever you need it.

= How do I find out why nothing is displayed? =

While logged in as a shop manager, open the product page with `?wtx_ps_debug=1` and view the page source. An HTML comment lists each suggested product and why it is or is not shown (for example out of stock or not a simple product).

= Does the plugin work with page caching? =

Yes. Adding to the cart is done through WooCommerce AJAX endpoints and a nonce.

= How do I change the plugin language? =

Go to **WooCommerce → Product Suggestions → Plugin language**. Options: Persian (default), English, or automatic (follow the site language).

== Screenshots ==

1. The suggested products box below the product price.
2. The "Suggested products" field inside the Linked products tab.
3. The settings page with global category rules.

== Changelog ==

= 1.0.3 =
* Persian is now the default plugin language (switchable to English or automatic in the settings).
* Texts are now generic and no longer mention a specific kind of product.
* The plugin name and description are translated in the plugins list.

= 1.0.2 =
* New "Visible items" setting (1-6) for the grid and carousel styles.
* List and grid styles switch to a carousel automatically when there are more products than visible items (can be turned off).
* On phones every style shows one product at a time with horizontal swipe (can be turned off).
* New "Plugin language" setting.
* A second active copy of the plugin no longer causes duplicate output; a notice is shown instead.

= 1.0.1 =
* Grid style switches to a carousel automatically when there are more than 4 products and swipes horizontally on phones.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.3 =
Persian is the default language and texts are now generic. Your settings are kept.
