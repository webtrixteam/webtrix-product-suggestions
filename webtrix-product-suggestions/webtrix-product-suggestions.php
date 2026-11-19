<?php
/**
 * Plugin Name:       Webtrix Product Suggestions
 * Description:       Show suggested products and add-ons with prices on the single product page and let customers add them to the cart in one click.
 * Version:           1.0.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Author:            Webtrix
 * Author URI:        https://webtrix.ir
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webtrix-product-suggestions
 * Domain Path:       /languages
 *
 * @package Webtrix_Product_Suggestions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WTX_PS_FILE' ) ) {
	// Another copy of this plugin is already active: do nothing to avoid duplicate output and conflicts.
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Another copy of Webtrix Product Suggestions is already active. Please deactivate the other copy; your settings are kept.', 'webtrix-product-suggestions' ) . '</p></div>';
		}
	);
	return;
}

define( 'WTX_PS_FILE', __FILE__ );
define( 'WTX_PS_URL', plugin_dir_url( __FILE__ ) );
define( 'WTX_PS_VER', '1.0.3' );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WTX_PS_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			Webtrix_Product_Suggestions::instance();
		}
	},
	20
);

/**
 * Main plugin class.
 */
final class Webtrix_Product_Suggestions {

	const OPT   = 'wtx_ps_options';
	const META  = '_wtx_ps_ids';
	const NONCE = 'wtx_ps';

	/** Hook priorities on woocommerce_single_product_summary. */
	const POSITIONS = array(
		'before_price'   => 9,
		'after_price'    => 11,
		'before_excerpt' => 19,
		'after_excerpt'  => 21,
		'before_cart'    => 29,
		'after_cart'     => 31,
		'shortcode'      => 0,
	);

	/** @var Webtrix_Product_Suggestions|null */
	private static $instance = null;

	/** @var int */
	private $counter = 0;

	/** @var bool */
	private $enqueued = false;

	/** @var bool */
	private $placed = false;

	/** @var array */
	private $cache = array();

	/**
	 * Singleton.
	 *
	 * @return Webtrix_Product_Suggestions
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Translated position labels (call only after init).
	 *
	 * @return array
	 */
	public static function position_labels() {
		return array(
			'before_price'   => __( 'Above the price', 'webtrix-product-suggestions' ),
			'after_price'    => __( 'Below the price (default)', 'webtrix-product-suggestions' ),
			'before_excerpt' => __( 'Above the short description', 'webtrix-product-suggestions' ),
			'after_excerpt'  => __( 'Below the short description', 'webtrix-product-suggestions' ),
			'before_cart'    => __( 'Above the add to cart section', 'webtrix-product-suggestions' ),
			'after_cart'     => __( 'Below the add to cart section', 'webtrix-product-suggestions' ),
			'shortcode'      => __( 'Shortcode only', 'webtrix-product-suggestions' ),
		);
	}

	/**
	 * Default options. Title is null so the translated default is resolved at render time.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'    => 1,
			'position'   => 'after_price',
			'style'      => 'list',
			'cart_mode'  => 'checkbox',
			'merge_mode' => 'override',
			'title'      => null,
			'max'        => 6,
			'show_image' => 1,
			'show_total' => 1,
			'accent'     => '#0f4bb5',
			'columns'    => 4,
			'carousel'   => 1,
			'swipe'      => 1,
			'language'   => 'fa_IR',
			'rules'      => array(),
		);
	}

	/**
	 * Saved options merged with defaults.
	 *
	 * @return array
	 */
	public static function opts() {
		$saved = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'plugin_locale', array( $this, 'filter_locale' ), 10, 2 );

		// Admin.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WTX_PS_FILE ), array( $this, 'action_links' ) );
		add_action( 'woocommerce_product_options_related', array( $this, 'related_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );

		// Frontend.
		$o = self::opts();
		if ( $o['enabled'] && isset( self::POSITIONS[ $o['position'] ] ) && self::POSITIONS[ $o['position'] ] > 0 ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_hook' ), self::POSITIONS[ $o['position'] ] );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( $this, 'fallback_render' ), 5 );
		add_shortcode( 'wtx_product_suggestion', array( $this, 'shortcode' ) );
		add_action( 'wc_ajax_wtx_ps_add', array( $this, 'ajax_add' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'bundle_add' ), 20, 6 );
	}

	/**
	 * Let the plugin language be chosen in the settings (auto / English / Persian).
	 *
	 * @param string $locale Current locale.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function filter_locale( $locale, $domain ) {
		if ( 'webtrix-product-suggestions' !== $domain ) {
			return $locale;
		}
		$o = self::opts();
		if ( 'en_US' === $o['language'] || 'fa_IR' === $o['language'] ) {
			return $o['language'];
		}
		// Auto: any Persian locale (fa_IR, fa_AF…) uses the bundled Persian translation.
		return ( 0 === strpos( (string) $locale, 'fa' ) ) ? 'fa_IR' : $locale;
	}

	/**
	 * Load bundled translations (English source, Persian included).
	 */
	public function load_textdomain() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- ships a bundled fa_IR translation.
		load_plugin_textdomain( 'webtrix-product-suggestions', false, dirname( plugin_basename( WTX_PS_FILE ) ) . '/languages' );
	}

	/* ------------------------------------------------------------------
	 * Suggestion resolver
	 * ---------------------------------------------------------------- */

	/**
	 * Product IDs coming from global category rules.
	 *
	 * @param int $pid Product ID.
	 * @return int[]
	 */
	private function global_ids( $pid ) {
		$o = self::opts();
		if ( empty( $o['rules'] ) || ! is_array( $o['rules'] ) ) {
			return array();
		}
		$cats = null;
		$ids  = array();
		foreach ( $o['rules'] as $r ) {
			$match = ! empty( $r['all'] );
			if ( ! $match ) {
				if ( null === $cats ) {
					$cats  = array();
					$terms = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $terms ) ) {
						foreach ( $terms as $tid ) {
							$cats[] = (int) $tid;
							$cats   = array_merge( $cats, array_map( 'intval', get_ancestors( $tid, 'product_cat', 'taxonomy' ) ) );
						}
					}
				}
				$match = (bool) array_intersect( $cats, array_map( 'intval', (array) $r['cats'] ) );
			}
			if ( $match ) {
				$ids = array_merge( $ids, array_map( 'absint', (array) $r['ids'] ) );
			}
		}
		return $ids;
	}

	/**
	 * Final list of purchasable suggestions for a product.
	 *
	 * @param int $pid Product ID.
	 * @return int[]
	 */
	public function get_suggestions( $pid ) {
		$pid = absint( $pid );
		if ( isset( $this->cache[ $pid ] ) ) {
			return $this->cache[ $pid ];
		}
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return array();
		}
		$o   = self::opts();
		$own = $product->get_meta( self::META );
		$own = is_array( $own ) ? array_filter( array_map( 'absint', $own ) ) : array();

		if ( $own && 'override' === $o['merge_mode'] ) {
			$ids = $own;
		} else {
			$ids = array_merge( $own, $this->global_ids( $pid ) );
		}

		$out = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( ! $id || $id === $pid ) {
				continue;
			}
			$s = wc_get_product( $id );
			if ( ! $s || ! $s->is_type( 'simple' ) || 'publish' !== $s->get_status() || ! $s->is_purchasable() || ! $s->is_in_stock() ) {
				continue;
			}
			$out[] = $id;
			if ( count( $out ) >= (int) $o['max'] ) {
				break;
			}
		}
		$this->cache[ $pid ] = $out;
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Frontend rendering
	 * ---------------------------------------------------------------- */

	/**
	 * Enqueue assets on single product pages.
	 */
	public function maybe_enqueue() {
		$o = self::opts();
		if ( $o['enabled'] && function_exists( 'is_product' ) && is_product() ) {
			$this->enqueue();
		}
	}

	/**
	 * Enqueue frontend CSS/JS once.
	 */
	private function enqueue() {
		if ( $this->enqueued ) {
			return;
		}
		$this->enqueued = true;
		wp_enqueue_style( 'wtx-ps', WTX_PS_URL . 'assets/wtx-ps.css', array(), WTX_PS_VER );
		wp_enqueue_script( 'wtx-ps', WTX_PS_URL . 'assets/wtx-ps.js', array( 'jquery' ), WTX_PS_VER, true );

		$currency = get_woocommerce_currency();
		wp_localize_script(
			'wtx-ps',
			'wtxPS',
			array(
				'ajax_url' => esc_url_raw( WC_AJAX::get_endpoint( 'wtx_ps_add' ) ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'cart_url' => wc_get_cart_url(),
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'format'   => get_woocommerce_price_format(),
				'decimals' => wc_get_price_decimals(),
				'thousand' => wc_get_price_thousand_separator(),
				'decimal'  => wc_get_price_decimal_separator(),
				'fa'       => in_array( $currency, array( 'IRT', 'IRR', 'IRHR', 'IRHT' ), true ) ? 1 : 0,
				'i18n'     => array(
					'added' => __( 'Added ✓', 'webtrix-product-suggestions' ),
					'cart'  => __( 'View cart', 'webtrix-product-suggestions' ),
					'error' => __( 'Could not add to cart. Please try again.', 'webtrix-product-suggestions' ),
				),
			)
		);
	}

	/**
	 * Output on woocommerce_single_product_summary.
	 */
	public function render_hook() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$this->placed = true;
		$this->render( $product->get_id() );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'wtx_product_suggestion' );
		$pid  = absint( $atts['id'] );
		if ( ! $pid ) {
			$pid = get_the_ID();
		}
		if ( ! $pid || 'product' !== get_post_type( $pid ) ) {
			return '';
		}
		ob_start();
		$this->render( $pid );
		return ob_get_clean();
	}

	/**
	 * Fallback for themes / page builders that skip the standard hooks:
	 * print the box in the footer and let JS move it next to the right element.
	 */
	public function fallback_render() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$o   = self::opts();
		$pid = get_queried_object_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic flag, restricted to shop managers.
		if ( isset( $_GET['wtx_ps_debug'] ) && current_user_can( 'manage_woocommerce' ) ) {
			$this->debug_comment( $pid );
		}

		if ( ! $o['enabled'] || $this->placed || 'shortcode' === $o['position'] || ! $pid || ! $this->get_suggestions( $pid ) ) {
			return;
		}
		echo '<div class="wtx-ps-fallback" data-position="' . esc_attr( $o['position'] ) . '" style="display:none">';
		$this->render( $pid );
		echo '</div>';
	}

	/**
	 * Print an HTML comment explaining why items are (not) shown. Admin-only.
	 *
	 * @param int $pid Product ID.
	 */
	private function debug_comment( $pid ) {
		$o   = self::opts();
		$p   = wc_get_product( $pid );
		$own = $p ? $p->get_meta( self::META ) : array();
		$own = is_array( $own ) ? $own : array();
		$glo = $this->global_ids( $pid );
		$l   = array(
			'enabled=' . (int) $o['enabled'] . ' position=' . $o['position'] . ' hook_fired=' . (int) $this->placed,
			'product=' . $pid . ' own=' . wp_json_encode( $own ) . ' global=' . wp_json_encode( $glo ),
		);
		foreach ( array_unique( array_map( 'absint', array_merge( $own, $glo ) ) ) as $id ) {
			$s = wc_get_product( $id );
			if ( ! $s ) {
				$l[] = $id . ': not found';
				continue;
			}
			$l[] = $id . ': type=' . $s->get_type() . ' status=' . $s->get_status() . ' purchasable=' . (int) $s->is_purchasable() . ' in_stock=' . (int) $s->is_in_stock() . ' price=' . $s->get_price();
		}
		echo "\n<!-- WTX-PS DEBUG\n" . esc_html( str_replace( '--', '- -', implode( "\n", $l ) ) ) . "\n-->\n";
	}

	/**
	 * Allowed tags/attributes for product thumbnails.
	 *
	 * @return array
	 */
	private function img_kses() {
		return array(
			'img' => array(
				'src'      => true,
				'srcset'   => true,
				'sizes'    => true,
				'alt'      => true,
				'width'    => true,
				'height'   => true,
				'class'    => true,
				'loading'  => true,
				'decoding' => true,
				'title'    => true,
			),
		);
	}

	/**
	 * Print the suggestions box.
	 *
	 * @param int $pid Product ID.
	 */
	private function render( $pid ) {
		$o = self::opts();
		if ( ! $o['enabled'] ) {
			return;
		}
		$ids = $this->get_suggestions( $pid );
		if ( ! $ids ) {
			return;
		}
		$this->enqueue();
		++$this->counter;

		$checkbox = ( 'checkbox' === $o['cart_mode'] );

		// How many cards are visible at once (grid / carousel).
		$cols  = max( 1, min( 6, (int) $o['columns'] ) );
		$style = $o['style'];
		// List and grid turn into a carousel automatically when there are more items than visible columns.
		if ( $o['carousel'] && in_array( $style, array( 'list', 'grid' ), true ) && count( $ids ) > $cols ) {
			$style = 'carousel';
		}
		$classes = 'wtx-ps wtx-ps--' . $style . ' wtx-ps--' . $o['cart_mode'];
		if ( $o['swipe'] ) {
			// On phones every style shows one card at a time with horizontal swipe.
			$classes .= ' wtx-ps--swipe';
		}
		$accent   = sanitize_hex_color( $o['accent'] ) ? $o['accent'] : '#0f4bb5';
		$title    = is_null( $o['title'] ) ? __( 'Suggested products', 'webtrix-product-suggestions' ) : $o['title'];
		$add_text = __( 'Add to cart', 'webtrix-product-suggestions' );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" id="wtx-ps-<?php echo (int) $this->counter; ?>" data-product="<?php echo (int) $pid; ?>" style="--wtx-accent:<?php echo esc_attr( $accent ); ?>;--wtx-cols:<?php echo (int) $cols; ?>">
			<?php if ( '' !== trim( $title ) ) : ?>
				<div class="wtx-ps__title"><?php echo esc_html( $title ); ?></div>
			<?php endif; ?>

			<div class="wtx-ps__wrap">
				<?php if ( 'carousel' === $style ) : ?>
					<button type="button" class="wtx-ps__nav wtx-ps__nav--prev" aria-label="<?php esc_attr_e( 'Previous', 'webtrix-product-suggestions' ); ?>"><?php echo is_rtl() ? '›' : '‹'; ?></button>
				<?php endif; ?>

				<div class="wtx-ps__items">
					<?php
					foreach ( $ids as $id ) :
						$p = wc_get_product( $id );
						if ( ! $p ) {
							continue;
						}
						$price_num = wc_get_price_to_display( $p );
						$img       = $o['show_image'] ? $p->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) : '';
						if ( $checkbox ) :
							?>
							<label class="wtx-ps__item">
								<input type="checkbox" class="wtx-ps__check" value="<?php echo (int) $id; ?>" data-price="<?php echo esc_attr( $price_num ); ?>">
								<?php if ( $img ) : ?>
									<span class="wtx-ps__img"><?php echo wp_kses( $img, $this->img_kses() ); ?></span>
								<?php endif; ?>
								<span class="wtx-ps__name"><?php echo esc_html( $p->get_name() ); ?></span>
								<span class="wtx-ps__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
							</label>
						<?php else : ?>
							<div class="wtx-ps__item">
								<?php if ( $img ) : ?>
									<a class="wtx-ps__img" href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo wp_kses( $img, $this->img_kses() ); ?></a>
								<?php endif; ?>
								<a class="wtx-ps__name" href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo esc_html( $p->get_name() ); ?></a>
								<span class="wtx-ps__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
								<button type="button" class="wtx-ps__btn" data-id="<?php echo (int) $id; ?>" data-label="<?php echo esc_attr( $add_text ); ?>"><?php echo esc_html( $add_text ); ?></button>
							</div>
							<?php
						endif;
					endforeach;
					?>
				</div>

				<?php if ( 'carousel' === $style ) : ?>
					<button type="button" class="wtx-ps__nav wtx-ps__nav--next" aria-label="<?php esc_attr_e( 'Next', 'webtrix-product-suggestions' ); ?>"><?php echo is_rtl() ? '‹' : '›'; ?></button>
				<?php endif; ?>
			</div>

			<?php if ( $checkbox ) : ?>
				<div class="wtx-ps__hint"><?php esc_html_e( 'Checked items are added to the cart together with this product.', 'webtrix-product-suggestions' ); ?></div>
				<?php if ( $o['show_total'] ) : ?>
					<div class="wtx-ps__total" hidden><?php esc_html_e( 'Selected items total:', 'webtrix-product-suggestions' ); ?> <strong></strong></div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="wtx-ps__msg" role="status" aria-live="polite"></div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Cart handling
	 * ---------------------------------------------------------------- */

	/**
	 * Checkbox mode: suggested products ride along with the main add-to-cart request.
	 *
	 * @param string $cart_item_key  Cart item key.
	 * @param int    $product_id     Product ID.
	 * @param int    $quantity       Quantity.
	 * @param int    $variation_id   Variation ID.
	 * @param array  $variation      Variation attributes.
	 * @param array  $cart_item_data Cart item data.
	 */
	public function bundle_add( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		static $running = false;
		if ( $running ) {
			return;
		}
		if ( ! isset( $_REQUEST['wtx_ps_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['wtx_ps_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		$wanted = isset( $_REQUEST['wtx_ps_add'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['wtx_ps_add'] ) ) : array();
		if ( ! $wanted ) {
			return;
		}
		$running = true;
		$allowed = $this->get_suggestions( $product_id );
		foreach ( array_unique( $wanted ) as $id ) {
			if ( in_array( $id, $allowed, true ) ) {
				WC()->cart->add_to_cart( $id, 1 );
			}
		}
		$running = false;
	}

	/**
	 * Button mode: AJAX add for individual suggested products.
	 */
	public function ajax_add() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE ) ) {
			wp_send_json_error();
		}
		$pid = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();

		if ( ! $pid || ! $ids || ! WC()->cart ) {
			wp_send_json_error();
		}
		$allowed = $this->get_suggestions( $pid );
		$added   = 0;
		foreach ( array_unique( $ids ) as $id ) {
			if ( in_array( $id, $allowed, true ) && WC()->cart->add_to_cart( $id, 1 ) ) {
				++$added;
			}
		}
		wc_clear_notices();
		if ( ! $added ) {
			wp_send_json_error();
		}
		wp_send_json_success( array( 'added' => $added ) );
	}

	/* ------------------------------------------------------------------
	 * Product edit screen (inside the "Linked products" tab)
	 * ---------------------------------------------------------------- */

	/**
	 * Adds the field after Cross-sells in the Linked products tab.
	 */
	public function related_field() {
		global $post;
		if ( ! $post ) {
			return;
		}
		$product = wc_get_product( $post->ID );
		$ids     = $product ? $product->get_meta( self::META ) : array();
		$ids     = is_array( $ids ) ? $ids : array();
		?>
		<p class="form-field">
			<label for="wtx_ps_ids"><?php esc_html_e( 'Suggested products', 'webtrix-product-suggestions' ); ?></label>
			<select class="wc-product-search" multiple="multiple" style="width:50%;" id="wtx_ps_ids" name="_wtx_ps_ids[]"
				data-placeholder="<?php esc_attr_e( 'Search for a product…', 'webtrix-product-suggestions' ); ?>"
				data-action="woocommerce_json_search_products"
				data-exclude_type="variable"
				data-exclude="<?php echo (int) $post->ID; ?>">
				<?php
				foreach ( $ids as $id ) {
					$p = wc_get_product( $id );
					if ( $p ) {
						echo '<option value="' . esc_attr( $id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
					}
				}
				?>
			</select>
			<?php echo wp_kses_post( wc_help_tip( __( 'Products suggested on this product page (simple products only). If empty, the global rules from the plugin settings apply.', 'webtrix-product-suggestions' ) ) ); ?>
		</p>
		<?php
	}

	/**
	 * Save the field. WooCommerce has already verified the product edit nonce.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_product( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by WooCommerce before this hook.
		$raw = isset( $_POST['_wtx_ps_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['_wtx_ps_ids'] ) ) : array();
		$ids = array_values( array_unique( array_filter( $raw ) ) );
		if ( $ids ) {
			$product->update_meta_data( self::META, $ids );
		} else {
			$product->delete_meta_data( self::META );
		}
	}

	/* ------------------------------------------------------------------
	 * Settings page
	 * ---------------------------------------------------------------- */

	/**
	 * Add a Settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wtx-ps' ) ) . '">' . esc_html__( 'Settings', 'webtrix-product-suggestions' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the WooCommerce submenu page.
	 */
	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Product Suggestions', 'webtrix-product-suggestions' ),
			__( 'Product Suggestions', 'webtrix-product-suggestions' ),
			'manage_woocommerce',
			'wtx-ps',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * Register the option.
	 */
	public function register_settings() {
		register_setting(
			'wtx_ps_group',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize the options array.
	 *
	 * @param mixed $in Raw input.
	 * @return array
	 */
	public function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$d   = self::defaults();
		$out = array();

		$out['enabled']    = empty( $in['enabled'] ) ? 0 : 1;
		$out['position']   = ( isset( $in['position'] ) && isset( self::POSITIONS[ $in['position'] ] ) ) ? $in['position'] : $d['position'];
		$out['style']      = ( isset( $in['style'] ) && in_array( $in['style'], array( 'list', 'grid', 'carousel' ), true ) ) ? $in['style'] : $d['style'];
		$out['cart_mode']  = ( isset( $in['cart_mode'] ) && in_array( $in['cart_mode'], array( 'checkbox', 'button' ), true ) ) ? $in['cart_mode'] : $d['cart_mode'];
		$out['merge_mode'] = ( isset( $in['merge_mode'] ) && in_array( $in['merge_mode'], array( 'override', 'merge' ), true ) ) ? $in['merge_mode'] : $d['merge_mode'];
		$out['title']      = isset( $in['title'] ) ? sanitize_text_field( $in['title'] ) : $d['title'];
		if ( is_string( $out['title'] ) && in_array( $out['title'], array( 'Suggested accessories', 'Suggested products', __( 'Suggested products', 'webtrix-product-suggestions' ), 'لوازم جانبی پیشنهادی', 'محصولات پیشنهادی' ), true ) ) {
			$out['title'] = null; // Use the translated default.
		}
		$out['max']        = isset( $in['max'] ) ? max( 1, min( 24, absint( $in['max'] ) ) ) : $d['max'];
		$out['show_image'] = empty( $in['show_image'] ) ? 0 : 1;
		$out['show_total'] = empty( $in['show_total'] ) ? 0 : 1;
		$accent            = isset( $in['accent'] ) ? sanitize_hex_color( $in['accent'] ) : '';
		$out['accent']     = $accent ? $accent : $d['accent'];
		$out['columns']    = isset( $in['columns'] ) ? max( 1, min( 6, absint( $in['columns'] ) ) ) : $d['columns'];
		$out['carousel']   = empty( $in['carousel'] ) ? 0 : 1;
		$out['swipe']      = empty( $in['swipe'] ) ? 0 : 1;
		$out['language']   = ( isset( $in['language'] ) && in_array( $in['language'], array( 'auto', 'en_US', 'fa_IR' ), true ) ) ? $in['language'] : $d['language'];

		$out['rules'] = array();
		if ( ! empty( $in['rules'] ) && is_array( $in['rules'] ) ) {
			foreach ( $in['rules'] as $r ) {
				$ids  = array_values( array_filter( array_map( 'absint', (array) ( $r['ids'] ?? array() ) ) ) );
				$cats = array_values( array_filter( array_map( 'absint', (array) ( $r['cats'] ?? array() ) ) ) );
				$all  = empty( $r['all'] ) ? 0 : 1;
				if ( ! $ids || ( ! $all && ! $cats ) ) {
					continue;
				}
				$out['rules'][] = array(
					'label' => sanitize_text_field( $r['label'] ?? '' ),
					'all'   => $all,
					'cats'  => $cats,
					'ids'   => $ids,
				);
			}
		}
		return $out;
	}

	/**
	 * Settings page assets.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function admin_assets( $hook ) {
		if ( 'woocommerce_page_wtx-ps' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'wtx-ps-admin', WTX_PS_URL . 'assets/wtx-ps-admin.css', array(), WTX_PS_VER );
		wp_enqueue_script( 'wtx-ps-admin', WTX_PS_URL . 'assets/wtx-ps-admin.js', array( 'jquery', 'wc-enhanced-select' ), WTX_PS_VER, true );
	}

	/**
	 * Print hierarchical <option> list of product categories.
	 *
	 * @param int[] $selected Selected term IDs.
	 */
	private function cat_options( $selected ) {
		$walk = function ( $parent, $depth ) use ( &$walk, $selected ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'parent'     => $parent,
				)
			);
			if ( is_wp_error( $terms ) ) {
				return;
			}
			foreach ( $terms as $t ) {
				echo '<option value="' . (int) $t->term_id . '"' . selected( in_array( (int) $t->term_id, $selected, true ), true, false ) . '>' . esc_html( str_repeat( '— ', $depth ) . $t->name ) . '</option>';
				$walk( $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
	}

	/**
	 * Print one global rule row.
	 *
	 * @param int|string $i Row index (or the __i__ placeholder).
	 * @param array      $r Rule data.
	 */
	private function rule_row( $i, $r ) {
		$name = self::OPT . '[rules][' . $i . ']';
		$r    = wp_parse_args(
			$r,
			array(
				'label' => '',
				'all'   => 0,
				'cats'  => array(),
				'ids'   => array(),
			)
		);
		?>
		<div class="wtx-ps-rule">
			<p>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $r['label'] ); ?>" placeholder="<?php esc_attr_e( 'Rule name (optional, for you only)', 'webtrix-product-suggestions' ); ?>">
				<a href="#" class="wtx-ps-remove"><?php esc_html_e( 'Remove rule', 'webtrix-product-suggestions' ); ?></a>
			</p>
			<p>
				<label><input type="checkbox" class="wtx-ps-all" name="<?php echo esc_attr( $name ); ?>[all]" value="1" <?php checked( $r['all'], 1 ); ?>> <?php esc_html_e( 'Apply to all products', 'webtrix-product-suggestions' ); ?></label>
			</p>
			<p class="wtx-ps-cats"<?php echo $r['all'] ? ' style="display:none"' : ''; ?>>
				<label><strong><?php esc_html_e( 'Categories', 'webtrix-product-suggestions' ); ?></strong> <?php esc_html_e( '(a parent category includes its sub-categories)', 'webtrix-product-suggestions' ); ?></label><br>
				<select class="wc-enhanced-select wtx-ps-wide" multiple="multiple" name="<?php echo esc_attr( $name ); ?>[cats][]" data-placeholder="<?php esc_attr_e( 'Select categories…', 'webtrix-product-suggestions' ); ?>">
					<?php $this->cat_options( array_map( 'intval', (array) $r['cats'] ) ); ?>
				</select>
			</p>
			<p>
				<label><strong><?php esc_html_e( 'Suggested products (simple)', 'webtrix-product-suggestions' ); ?></strong></label><br>
				<select class="wc-product-search wtx-ps-wide" multiple="multiple" name="<?php echo esc_attr( $name ); ?>[ids][]"
					data-placeholder="<?php esc_attr_e( 'Search for a product…', 'webtrix-product-suggestions' ); ?>" data-action="woocommerce_json_search_products" data-exclude_type="variable">
					<?php
					foreach ( (array) $r['ids'] as $id ) {
						$p = wc_get_product( $id );
						if ( $p ) {
							echo '<option value="' . esc_attr( $id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
						}
					}
					?>
				</select>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$o     = self::opts();
		$n     = self::OPT;
		$rows  = is_array( $o['rules'] ) ? $o['rules'] : array();
		$title = is_null( $o['title'] ) ? __( 'Suggested products', 'webtrix-product-suggestions' ) : $o['title'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Product Suggestions', 'webtrix-product-suggestions' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wtx_ps_group' ); ?>

				<h2><?php esc_html_e( 'Display settings', 'webtrix-product-suggestions' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'webtrix-product-suggestions' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $o['enabled'], 1 ); ?>> <?php esc_html_e( 'Show on the single product page', 'webtrix-product-suggestions' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Position', 'webtrix-product-suggestions' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n ); ?>[position]">
								<?php foreach ( self::position_labels() as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o['position'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'If your theme reorders product sections the position may differ slightly. Use the shortcode [wtx_product_suggestion] to place the box anywhere.', 'webtrix-product-suggestions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Display style', 'webtrix-product-suggestions' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n ); ?>[style]">
								<option value="list" <?php selected( $o['style'], 'list' ); ?>><?php esc_html_e( 'Simple list (small image + price)', 'webtrix-product-suggestions' ); ?></option>
								<option value="grid" <?php selected( $o['style'], 'grid' ); ?>><?php esc_html_e( 'Grid cards', 'webtrix-product-suggestions' ); ?></option>
								<option value="carousel" <?php selected( $o['style'], 'carousel' ); ?>><?php esc_html_e( 'Carousel', 'webtrix-product-suggestions' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Add to cart method', 'webtrix-product-suggestions' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n ); ?>[cart_mode]">
								<option value="checkbox" <?php selected( $o['cart_mode'], 'checkbox' ); ?>><?php esc_html_e( 'Checkboxes (added together with the main product)', 'webtrix-product-suggestions' ); ?></option>
								<option value="button" <?php selected( $o['cart_mode'], 'button' ); ?>><?php esc_html_e( 'Separate button per item (AJAX)', 'webtrix-product-suggestions' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Box title', 'webtrix-product-suggestions' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[title]" value="<?php echo esc_attr( $title ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to hide the title.', 'webtrix-product-suggestions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Maximum items', 'webtrix-product-suggestions' ); ?></th>
						<td><input type="number" min="1" max="24" name="<?php echo esc_attr( $n ); ?>[max]" value="<?php echo (int) $o['max']; ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Visible items', 'webtrix-product-suggestions' ); ?></th>
						<td>
							<input type="number" min="1" max="6" name="<?php echo esc_attr( $n ); ?>[columns]" value="<?php echo (int) $o['columns']; ?>">
							<p class="description"><?php esc_html_e( 'How many products are shown side by side on desktop (grid and carousel).', 'webtrix-product-suggestions' ); ?></p>
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[carousel]" value="1" <?php checked( $o['carousel'], 1 ); ?>> <?php esc_html_e( 'Switch to a carousel when there are more products than visible items (list and grid)', 'webtrix-product-suggestions' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[swipe]" value="1" <?php checked( $o['swipe'], 1 ); ?>> <?php esc_html_e( 'On phones show one product at a time with horizontal swipe', 'webtrix-product-suggestions' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'webtrix-product-suggestions' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_image]" value="1" <?php checked( $o['show_image'], 1 ); ?>> <?php esc_html_e( 'Show product image', 'webtrix-product-suggestions' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_total]" value="1" <?php checked( $o['show_total'], 1 ); ?>> <?php esc_html_e( 'Show the total of selected items (checkbox mode)', 'webtrix-product-suggestions' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Accent color', 'webtrix-product-suggestions' ); ?></th>
						<td><input type="color" name="<?php echo esc_attr( $n ); ?>[accent]" value="<?php echo esc_attr( $o['accent'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugin language', 'webtrix-product-suggestions' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n ); ?>[language]">
								<option value="fa_IR" <?php selected( $o['language'], 'fa_IR' ); ?>>فارسی</option>
								<option value="en_US" <?php selected( $o['language'], 'en_US' ); ?>>English</option>
								<option value="auto" <?php selected( $o['language'], 'auto' ); ?>><?php esc_html_e( 'Automatic (follow the site language)', 'webtrix-product-suggestions' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'List priority', 'webtrix-product-suggestions' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n ); ?>[merge_mode]">
								<option value="override" <?php selected( $o['merge_mode'], 'override' ); ?>><?php esc_html_e( 'If a product has its own list, show only that list', 'webtrix-product-suggestions' ); ?></option>
								<option value="merge" <?php selected( $o['merge_mode'], 'merge' ); ?>><?php esc_html_e( 'Product list + global rules together', 'webtrix-product-suggestions' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Global rules (by category)', 'webtrix-product-suggestions' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Example: category “Shoes” → shoe care kit + socks. All store categories are available automatically.', 'webtrix-product-suggestions' ); ?></p>

				<div id="wtx-ps-rules">
					<?php
					foreach ( $rows as $i => $r ) {
						$this->rule_row( $i, $r );
					}
					?>
				</div>
				<p><button type="button" class="button" id="wtx-ps-add" data-next="<?php echo (int) ( $rows ? max( array_keys( $rows ) ) + 1 : 0 ); ?>"><?php esc_html_e( '+ Add rule', 'webtrix-product-suggestions' ); ?></button></p>

				<template id="wtx-ps-tpl">
					<?php $this->rule_row( '__i__', array() ); ?>
				</template>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
