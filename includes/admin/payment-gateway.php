<?php
/**
 * Offline Payment Gateway
 *
 * Provides an Offline Payment Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class       WC_Arcavis_Gateway
 * @extends     WC_Payment_Gateway
 * @version     1.1.1
 * @package     WooCommerce/Classes/Payment
 * @author      bet
 * @description Payment gateway for customer account payments
 */
define( 'WOO_PAYMENT_DIR', plugin_dir_path( __FILE__ ) );
add_action( 'plugins_loaded', 'wc_arcavis_gateway_init', 11 );

function wc_arcavis_gateway_init() {

	class WC_Arcavis_Gateway extends WC_Payment_Gateway {

		public function __construct() {
			$this->id                 = 'woo_arcavis';
			$this->method_title       = __( 'Arcavis Rechnung', 'arcavis_invoice' );
			$this->method_description = __( 'Bezahlen auf Rechnung mit dem Arcavis Rechnungsmodul', 'arcavis_invoice' );
			$this->title              = __( 'Rechnung', 'arcavis_invoice' );
			$this->has_fields         = false;
			// Locale English
			if ( strpos( get_locale(), 'en' ) == 0 ) {
				$this->method_title       = __( 'Arcavis Invoice', 'arcavis_invoice' );
				$this->method_description = __( 'Pay by invoice with the Arcavis invoice module', 'arcavis_invoice' );
				//$this->title              	= __( 'Invoice', 'arcavis_invoice' );
			}
			$this->init_form_fields();
			$this->init_settings();
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable/Disable', 'woocommerce' ),
					'default' => 'yes'
				)
			);
		}


		public function process_payment( $order_id ) {
			global $wc_arcavis_shop;

			$order         = new WC_Order( $order_id );
			$success       = false;
			$error_message = '';
			$error_type    = 'error';

			if ( get_current_user_id() ) {
				$response = $wc_arcavis_shop->transaction->arcavis_post_transaction( $order_id, session_id() );

				if ( $response->IsSuccessful === true ) {
					$order->payment_complete();
					$success = true;
				} else {
					// Translate error message
					if ( $response->Message == 'NotEnoughFunds' ) {
						// Nicht genug guthaben
						$error_message = 'Ihre Kontolimite wurde erreicht';
					} else if ( WP_DEBUG === true ) {
						// Show service error
						$error_message = $response->Message;
					} else {
						// Something else
						$error_message = 'Die Zahlung war nicht erfolgreich, bitte versuchen Sie ein anderes Zahlmittel';
					}
				}
			} else {
				$error_message = 'Für Zahlung auf Rechnung wird ein Kundenkonto benötigt. Bitte melden Sie sich an, oder aktivieren Sie die Option "Kundenkonto neu anlegen"';
			}

			// Show message
			if ( $success ) {
				// Return thank you redirect
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order )
				);
			} else {
				wc_add_notice( __( 'Fehler: ', 'woothemes' ) . $error_message, $error_type );
				return array();
			}
		}

	} // end \WC_Gateway_Offline class
}


function add_arcavis_gateway( ) {
	$methods = array();
	$methods[] = 'WC_Arcavis_Gateway';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_arcavis_gateway' );