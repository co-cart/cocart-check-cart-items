<?php
/*
 * Plugin Name: CoCart - Check Cart Items
 * Plugin URI:  https://cocart.xyz
 * Description: Checks items in the cart for validity and stock. Updates the cart before the cart returns what remains and returns a notice for each item changed.
 * Author:      Sébastien Dumont
 * Author URI:  https://sebastiendumont.com
 * Version:     1.0.0
 * Text Domain: cocart-check-cart-items
 * Domain Path: /languages/
 *
 * Requires at least: 5.3
 * Requires PHP: 7.0
 * WC requires at least: 4.3
 * WC tested up to: 4.8
 *
 * Copyright: © 2020 Sébastien Dumont, (mailme@sebastiendumont.com)
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! class_exists( 'CoCart_Check_Cart_Items' ) ) {
	class CoCart_Check_Cart_Items {

		/**
		 * Load the plugin.
		 *
		 * @access public
		 */
		public function __construct() {
			// Load translation files.
			add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

			// Check cart items.
			add_filter( 'cocart_return_cart_contents', array( $this, 'check_cart_items' ), 98 );

			// Plugin activation and deactivation.
			register_activation_hook( __FILE__, array( $this, 'activated' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivated' ) );
		} // END __construct()

		/**
		 * Make the plugin translation ready.
		 *
		 * Translations should be added in the WordPress language directory:
		 *      - WP_LANG_DIR/plugins/cocart-check-cart-items-LOCALE.mo
		 *
		 * @access public
		 * @return void
		 */
		public function load_plugin_textdomain() {
			load_plugin_textdomain( 'cocart-check-cart-items', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}

		/**
		 * Check all cart items for validity and stock.
		 *
		 * @access public
		 * @return $cart_contents
		 */
		public function check_cart_items( $cart_contents = array() ) {
			$result = $this->check_cart_item_validity();

			if ( is_wp_error( $result ) ) {
				wc_add_notice( $result->get_error_message(), 'error' );
				$return = false;
			}

			$result = $this->check_cart_item_stock();

			if ( is_wp_error( $result ) ) {
				wc_add_notice( $result->get_error_message(), 'error' );
			}

			return $cart_contents;
		} // END check_cart_items()

		/**
		 * Looks through cart items and checks the products are not trashed or deleted.
		 *
		 * @access public
		 * @return bool|WP_Error
		 */
		public function check_cart_item_validity() {
			$return = true;

			$cart = WC()->cart;

			foreach ( $cart->get_cart() as $cart_item_key => $values ) {
				$product = $values['data'];

				if ( ! $product || ! $product->exists() || 'trash' === $product->get_status() ) {
					$cart->set_quantity( $cart_item_key, 0 );
					$return = new WP_Error( 'invalid', __( 'An item which is no longer available was removed from your cart.', 'cocart-check-cart-items' ) );
				}
			}

			return $return;
		} // END check_cart_item_validity()

		/**
		 * Looks through the cart to check each item is in stock. If not, add an error.
		 *
		 * @access public
		 * @return bool|WP_Error
		 */
		public function check_cart_item_stock() {
			$cart = WC()->cart;

			$error                    = new WP_Error();
			$product_qty_in_cart      = $cart->get_cart_item_quantities();
			$current_session_order_id = isset( WC()->session->order_awaiting_payment ) ? absint( WC()->session->order_awaiting_payment ) : 0;

			foreach ( $cart->get_cart() as $cart_item_key => $values ) {
				$product = $values['data'];

				// Check stock based on stock-status.
				if ( ! $product->is_in_stock() ) {
					/* translators: %s: product name */
					$error->add( 'out-of-stock', sprintf( __( 'Sorry, "%s" is not in stock. Please edit your cart and try again. We apologize for any inconvenience caused.', 'cocart-check-cart-items' ), $product->get_name() ) );
					return $error;
				}

				// We only need to check products managing stock, with a limited stock qty.
				if ( ! $product->managing_stock() || $product->backorders_allowed() ) {
					continue;
				}

				// Check stock based on all items in the cart and consider any held stock within pending orders.
				$held_stock     = wc_get_held_stock_quantity( $product, $current_session_order_id );
				$required_stock = $product_qty_in_cart[ $product->get_stock_managed_by_id() ];

				/**
				 * Allows filter if product have enough stock to get added to the cart.
				 *
				 * @param bool       $has_stock If have enough stock.
				 * @param WC_Product $product   Product instance.
				 * @param array      $values    Cart item values.
				 */
				if ( apply_filters( 'cocart_cart_item_required_stock_is_not_enough', $product->get_stock_quantity() < ( $held_stock + $required_stock ), $product, $values ) ) {
					/* translators: 1: product name 2: quantity in stock */
					$error->add( 'out-of-stock', sprintf( __( 'Sorry, we do not have enough "%1$s" in stock to fulfill your order (%2$s available). We apologize for any inconvenience caused.', 'cocart-check-cart-items' ), $product->get_name(), wc_format_stock_quantity_for_display( $product->get_stock_quantity() - $held_stock, $product ) ) );
					return $error;
				}
			}

			return true;
		} // END check_cart_item_stock()

		/**
		 * Runs when the plugin is activated.
		 *
		 * Adds plugin to list of installed CoCart add-ons.
		 *
		 * @access public
		 */
		public function activated() {
			$addons_installed = get_site_option( 'cocart_addons_installed', array() );

			$plugin = plugin_basename( __FILE__ );

			// Check if plugin is already added to list of installed add-ons.
			if ( ! in_array( $plugin, $addons_installed, true ) ) {
				array_push( $addons_installed, $plugin );
				update_site_option( 'cocart_addons_installed', $addons_installed );
			}
		} // END activated()

		/**
		 * Runs when the plugin is deactivated.
		 *
		 * Removes plugin from list of installed CoCart add-ons.
		 *
		 * @access public
		 */
		public function deactivated() {
			$addons_installed = get_site_option( 'cocart_addons_installed', array() );

			$plugin = plugin_basename( __FILE__ );
			
			// Remove plugin from list of installed add-ons.
			if ( in_array( $plugin, $addons_installed, true ) ) {
				$addons_installed = array_diff( $addons_installed, array( $plugin ) );
				update_site_option( 'cocart_addons_installed', $addons_installed );
			}
		} // END deactivated()

	} // END class

} // END if class exists

new CoCart_Check_Cart_Items();
