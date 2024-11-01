<?php /** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUnusedParameterInspection */
/** @noinspection SqlResolve */
session_start();

/*This Class use for the handing Check transaction and Post Transaction API Calls*/

class WooCommerce_Arcavis_Transaction {
	public function __construct() {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'arcavis_check_transaction' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'arcavis_script' ) );
		add_action( 'wp_head', array( $this, 'arcavis_js_variables' ) );
		add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'customise_checkout_field' ) );
		add_action( 'wp_ajax_arcavis_get_applied_voucher_code', array( $this, 'arcavis_get_applied_voucher_code' ) );
		add_action( 'wp_ajax_nopriv_arcavis_get_applied_voucher_code', array(
			$this,
			'arcavis_get_applied_voucher_code'
		) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'arcavis_process_transaction' ), 99, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'order_process' ), 10, 1 );
		add_filter( 'woocommerce_coupons_enabled', array( $this, 'hide_coupon_field_on_cart' ) );
		//add_filter('woocommerce_payment_gateways', 'add_arcavis_gateway' );
		//add_filter('woocommerce_checkout_place_order', array( $this,'checkout_place_order') );
	}

	public function arcavis_js_variables() {

		?>
        <script type="text/javascript">
            let website_url = '<?php echo site_url(); ?>';
        </script>
		<?php
	}

	## Returns the applied voucher codes from check transaction call to show to the user
	public function arcavis_get_applied_voucher_code() {
		global $wpdb;
		$applied_vouchers = $wpdb->get_row( "SELECT * FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . session_id() . "' AND discount_type='voucher'" );
		if ( ! empty( $applied_vouchers ) ) {
			echo json_encode( $applied_vouchers );
		} else {
			echo "";
		}
		exit;
	}

	## This function use to add voucher field in checkout form. 
	function customise_checkout_field( $checkout ) {
		global $wc_arcavis_shop;
		woocommerce_form_field( 'arcavis_voucher', array(
			'type'        => 'text',
			'class'       => array(
				'my-field-class form-row-wide'
			),
			'label'       => __( $wc_arcavis_shop->text_entervoucher ),
			'placeholder' => __( $wc_arcavis_shop->text_entervoucherplaceholder ),
			'required'    => false,
		), $checkout->get_value( 'arcavis_voucher' ) );

		echo '<div id="user_link_hidden_checkout_field">
           		 <input type="hidden" class="input-hidden" name="arcavis_applied_voucher" id="arcavis_applied_voucher" value="">
    		  </div>';
	}


	## This function use to Hide default coupon system of woocommerce.
	public function hide_coupon_field_on_cart( $enabled ) {

		if ( is_cart() || is_checkout() ) {
			$enabled = false;
		}

		return $enabled;
	}


	public function arcavis_script() {
		wp_add_inline_script( 'arcavis_frontend', 'var website_url=' . site_url() );
		wp_enqueue_script( 'arcavis_frontend_js', plugin_dir_url( __FILE__ ) . 'js/frontend.js' );
	}


	## This function call Check Transaction API of Arcavis.
	public function arcavis_check_transaction() {

		global $woocommerce;
		global $wc_arcavis_shop;
		global $wpdb;

		if ( ! empty( $_POST ) && ! empty( $_POST['post_data'] ) ) {
			$data = $_POST['post_data'];
			$wc_arcavis_shop->logDebug( 'POST DATA ' . $data );
			parse_str( $data,$post_data);
			if ( $post_data['billing_email'] != '' || $post_data['arcavis_voucher'] != '' || $post_data['arcavis_voucher'] == '' ) {

				$request_array = array();
				if ( get_current_user_id() ) {
					$request_array['Customer'] = array(
						'CustomerNumber'   => 'WP-' . get_current_user_id(),
						'LanguageId'       => '',
						'IsCompany'        => $post_data['billing_company'] == '' ? 'false' : 'true',
						'CompanyName'      => $post_data['billing_company'],
						'Salutation'       => '',
						'SalutationTitle'  => '',
						'Firstname'        => $post_data['billing_first_name'],
						'Name'             => $post_data['billing_last_name'],
						'Street'           => $post_data['billing_address_1'],
						'StreetNumber'     => '',
						'StreetSupplement' => '',
						'PoBox'            => '',
						'Zip'              => $post_data['billing_postcode'],
						'City'             => $post_data['billing_city'],
						'CountryIsoCode'   => $post_data['billing_country'],
						'ContactEmail'     => $post_data['billing_email'],
						'ContactPhone'     => $post_data['billing_phone'],
						'ContactMobile'    => '',
						'Birthdate'        => ''
					);
				}

				$cart_total = preg_replace( "/[^0-9,.]/", "", html_entity_decode( $woocommerce->cart->get_cart_total() ) ); //

				$cart_total              = $cart_total + $woocommerce->cart->shipping_total;
				$request_array['Amount'] = $cart_total;

				$request_array['Remarks'] = $post_data['order_comments'];

				$items     = $woocommerce->cart->get_cart();
				$cart_data = array();
				$i         = 1;
				foreach ( $items as $item => $values ) {

					$article_id  = get_post_meta( $values['data']->get_id(), 'article_id', true );
					$_product    = wc_get_product( $values['data']->get_id() );
					$price       = get_post_meta( $values['product_id'], '_price', true );
					$cart_data[] = array(
						'ReceiptPosition' => $i,
						'ArticleId'       => $article_id,
						'Title'           => $_product->get_title(),
						'Quantity'        => $values['quantity'],
						'TaxRate'         => '',
						'UnitPrice'       => $price,
						'Price'           => $price * $values['quantity']
					);
					$i ++;
				}
				$shipping_data = array();

				$shipping_session = WC()->session->get( 'shipping_for_package_0' );
				if ( ! empty( $shipping_session ) ) {
					$shipping_data[] = array(
						'ReceiptPosition' => $i,
						'ArticleId'       => 0,
						'Title'           => $shipping_session['rates'][ $_POST['shipping_method'][0] ]->label,
						'Price'           => $woocommerce->cart->shipping_total,
					);


				}

				$request_array['TransactionArticles'] = array_merge( $cart_data, $shipping_data );
				$vouchers                             = array();
				if ( trim( $post_data['arcavis_applied_voucher'] ) != '' ) {
					$vouchers[] = array( 'VoucherId' => $post_data['arcavis_applied_voucher'], 'Amount' => '' );
				}

				$request_array['TransactionVouchers'] = $vouchers;

				$data = json_encode( $request_array );
				$wc_arcavis_shop->logDebug( 'PUT Query ' . $data );
				$options = $wc_arcavis_shop->settings_obj->get_arcavis_settings();

				$response      = wp_remote_request( $options['arcavis_link'] . '/api/transactions',
					array(
						'method'  => 'PUT',
						'body'    => $data,
						'headers' => array(
							'Authorization' => 'Basic ' . base64_encode( $options['arcavis_username'] . ':' . $options['arcavis_password'] ),
							'Content-Type'  => 'application/json',
						),
					)
				);
				$json_response = wp_remote_retrieve_body( $response );
				$response_body = json_decode( $json_response );
                Wc()->session->set('arcavis_ok', $response_body->IsSuccessful);
				if ( isset( $response_body->Result->AmountOpen ) ) {

					if ( ! empty( $response_body->Result->TransactionVouchers ) ) {

						$wpdb->query( "DELETE FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . session_id() . "' AND discount_type='voucher'" );

						$wpdb->insert( $wpdb->prefix . "applied_vouchers", array(
							'session_id'      => session_id(),
							'voucher_code'    => $response_body->Result->TransactionVouchers[0]->VoucherId,
							'discount_amount' => $response_body->Result->TransactionVouchers[0]->Amount,
							'discount_type'   => 'voucher'
						) );

					} else {

						$wpdb->query( "DELETE FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . session_id() . "' AND discount_type='voucher'" );
					}


					if ( ! empty( $response_body->Result->TransactionVouchers ) ) {
						$voucher_discount = $response_body->Result->TransactionVouchers[0]->Amount;
						$discount         = $response_body->Result->AmountOpen + $voucher_discount;
						$discount         = $cart_total - $discount;
					} else {
						$discount = $cart_total - ( $response_body->Result->AmountOpen );
					}
					//echo $discount;
					if ( $discount != '0' ) {

						$wpdb->query( "DELETE FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . session_id() . "' AND discount_type='discount'" );
						$wpdb->insert( $wpdb->prefix . "applied_vouchers", array(
							'session_id'      => session_id(),
							'voucher_code'    => '',
							'discount_amount' => $discount,
							'discount_type'   => 'discount'
						) );

					} else {

						$wpdb->query( "DELETE FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . session_id() . "' AND discount_type='discount'" );
					}

					$wpdb->query( "DELETE FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . session_id() . "'  AND discount_type='response'" );
					$wpdb->insert( $wpdb->prefix . "applied_vouchers", array(
						'session_id'           => session_id(),
						'discount_type'        => 'response',
						'transaction_response' => $json_response
					) );
				}
			}
		}

		$applied_discount = $wpdb->get_row( "SELECT * FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . session_id() . "' AND discount_type='discount'" );
		## Arcavis Discount on checkout page is added from here.
		if ( ! empty( $applied_discount ) ) {


			$extra_fee_option_label    = $wc_arcavis_shop->text_discount;
			$extra_fee_option_cost     = '-' . $applied_discount->discount_amount;
			$extra_fee_option_taxable  = false;
			$extra_fee_option_cost     = round( $extra_fee_option_cost, 2 );
			$woocommerce->cart->add_fee( __( $extra_fee_option_label, 'woocommerce' ), $extra_fee_option_cost, $extra_fee_option_taxable );
		}
		## Voucher Discount on checkout page is added from here.
		$applied_vouchers = $wpdb->get_row( "SELECT * FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . session_id() . "' AND discount_type='voucher'" );
		if ( ! empty( $applied_vouchers ) ) {


			$extra_fee_option_label    = $wc_arcavis_shop->text_voucher;
			$extra_fee_option_cost     = '-' . $applied_vouchers->discount_amount;
			$extra_fee_option_taxable  = false;
			$extra_fee_option_cost     = round( $extra_fee_option_cost, 2 );
			$woocommerce->cart->add_fee( __( $extra_fee_option_label, 'woocommerce' ), $extra_fee_option_cost, $extra_fee_option_taxable );

		}

	}

	public function order_process( $order_id ) {

		update_post_meta( $order_id, 'session_id_at_checkout', session_id() );

	}

	## This function call the Post Transaction API of Arcavis.
	public function arcavis_process_transaction( $order_id, $old_status, $new_status ) {
		$session_id_at_checkout = get_post_meta( $order_id, 'session_id_at_checkout', true );
		if ( in_array( $new_status, array( 'on-hold', 'completed', 'processing' ) ) ) {
			$transactions_done_or_not = get_post_meta( $order_id, 'arcavis_response', true );
			if ( ! $transactions_done_or_not ) {
				$this->arcavis_post_transaction( $order_id, $session_id_at_checkout );
			}
		}
	}

	public function arcavis_post_transaction( $order_id, $session_id_at_checkout ) {
		global $wc_arcavis_shop;
		global $wpdb;
		$order = new WC_Order( $order_id );

		if ( get_current_user_id() ) {
			$current_user              = wp_get_current_user();
			$request_array['Customer'] = array(
				'CustomerNumber'   => 'WP-' . get_current_user_id(),
				'LanguageId'       => 'de',
				'IsCompany'        => $order->get_billing_company() == '' ? 'false' : 'true',
				'CompanyName'      => $order->get_billing_company(),
				'Salutation'       => '',
				'SalutationTitle'  => '',
				'Firstname'        => $current_user->user_firstname,
				'Name'             => $current_user->user_lastname,
				'Street'           => $order->get_billing_address_1(),
				'StreetNumber'     => '',
				'StreetSupplement' => '',
				'PoBox'            => '',
				'Zip'              => $order->get_billing_postcode(),
				'City'             => $order->get_billing_city(),
				'CountryIsoCode'   => $order->get_billing_country(),
				'ContactEmail'     => $current_user->user_email,
				'ContactPhone'     => $order->get_billing_phone(),
				'ContactMobile'    => '',
				'Birthdate'        => ''
			);
		}

		$arcavis_response_json = $wpdb->get_row( "SELECT transaction_response FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . $session_id_at_checkout . "' AND discount_type='response'" );
		$arcavis_response      = json_decode( stripslashes( $arcavis_response_json->transaction_response ) );
		if($arcavis_response == null || $arcavis_response->IsSuccessful == false){
            throw new Exception("Ein unerwarteter Fehler ist aufgetreten.");
		}
		$cart_total                  = $arcavis_response->Result->AmountOpen; //round($order->get_total(),1, PHP_ROUND_HALF_EVEN);
		$request_array['Amount']     = $cart_total;
		$request_array['AmountOpen'] = $cart_total;
		$request_array['Remarks']    = $order_id;//." Remarks: ".$order->customer_message;//$arcavis_response->Result->Remarks;


		$request_array['TransactionArticles'] = $arcavis_response->Result->TransactionArticles;//array_merge($cart_data,$shipping_data);
		$vouchers                             = array();
		if ( ! empty( $arcavis_response->Result->TransactionVouchers ) ) {
			$vouchers = $arcavis_response->Result->TransactionVouchers;
		}
		$request_array['TransactionVouchers'] = $vouchers;

		$payments = array();
		if ( $cart_total > 0 ) {
			$payments = array(
				'Title'           => $order->get_payment_method_title(),
				'Amount'          => $cart_total,
				'CurrencyIsoCode' => get_woocommerce_currency()
			);
		}
		$request_array['TransactionPayments'][] = $payments;

		$data = json_encode( $request_array );
		//print_r($request_array);
		//exit;
		$options       = $wc_arcavis_shop->settings_obj->get_arcavis_settings();
		$response      = wp_remote_request( $options['arcavis_link'] . '/api/transactions',
			array(
				'method'  => 'POST',
				'body'    => $data,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $options['arcavis_username'] . ':' . $options['arcavis_password'] ),
					'Content-Type'  => 'application/json',
				),
			)
		);
		$json_response = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $json_response );


		if ( $response_body->IsSuccessful === true && $response_body->Message == 'Success' ) {

			update_post_meta( $order_id, 'arcavis_response', $json_response );
			//$options = $wc_arcavis_shop->settings_obj->get_arcavis_settings();
			//$wc_arcavis_shop->sync->update_article_stock($options);
			setcookie( 'arcavis_response', '', time() - 3600, '/' );

			$wpdb->query( "DELETE FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . $session_id_at_checkout . "' AND discount_type='voucher'" );
			$wpdb->query( "DELETE FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . $session_id_at_checkout . "' AND discount_type='discount'" );
			$wpdb->query( "DELETE FROM " . $wpdb->prefix . "applied_vouchers WHERE session_id='" . $session_id_at_checkout . "' AND discount_type='response'" );
		} else {
			$wc_arcavis_shop->logError( 'Post Transaction ' . $response_body->Message );
		}
		return $response_body;
	}


}//End of class
?>