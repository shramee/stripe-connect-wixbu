<?php
/**
* Stripe Payment Gateway Class
*
* @since   4.0.0
* @version 4.2.0
*/
if ( ! defined( 'ABSPATH' ) ) exit;
class LLMS_Payment_Gateway_Stripe extends LLMS_Payment_Gateway {

	/**
	 * URL to the Stripe Dashboard
	 */
	const DASHBOARD_URL = 'https://dashboard.stripe.com/';

	/**
	 * Maximum transaction amount (in cents)
	 * @see     https://support.stripe.com/questions/what-is-the-maximum-amount-i-can-charge-with-stripe
	 * @since   4.0.0
	 * @version 4.0.0
	 */
	const MAX_AMOUNT = 99999999;

	/**
	 * live publishable key
	 * @var  string
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public $live_publishable_key = '';

	/**
	 * live secret key
	 * @var  string
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public $live_secret_key = '';

	/**
	 * whether or not to save cards
	 * @var  string
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public $saved_cards_enabled = '';

	/**
	 * test publishable key
	 * @var  string
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public $test_publishable_key = '';

	/**
	 * test secret key
	 * @var  string
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public $test_secret_key = '';


	/**
	 * Constructor
	 * @return  void
	 * @since   1.0.0
	 * @version 4.2.0
	 */
	public function __construct() {

		$this->id = 'stripe';
		$icon_alt = __( 'Powered by Stripe', 'lifterlms-stripe' );
		$this->icon = '<a href="https://stripe.com" target="_blank" title="' . $icon_alt . '"><img src="' . plugins_url( 'assets/img/icon-dark.png', LLMS_STRIPE_PLUGIN_FILE ) . '" alt="' . $icon_alt . '"></a>';
		$this->admin_description = __( 'Allow customers to purchase courses and memberships using Stripe.', 'lifterlms-stripe' );
		$this->admin_title = 'Stripe';
		$this->title = 'Credit Card';
		$this->description = __( 'Processed by Stripe', 'lifterlms-stripe' );

		$this->supports = array(
			'checkout_fields' => true,
			'refunds' => true,
			'single_payments' => true,
			'recurring_payments' => true,
			'recurring_retry' => true,
			'test_mode' => true,
		);

		$this->admin_order_fields = wp_parse_args( array(
			'customer' => true,
			'source' => true,
		), $this->admin_order_fields );

		// @todo documetation
		$this->test_mode_description = sprintf( __( 'Stipe Test Mode can be used to process test transactions. <a href="%s">Learn more</a>.', 'lifterlms-stripe' ), '#' );
		$this->test_mode_title = __( 'Test Mode', 'lifterlms-stripe' );

		// add stripe specific fields
		add_filter( 'llms_get_gateway_settings_fields', array( $this, 'settings_fields' ), 10, 2 );

		// show payment method source description on the view order screen on the student dashboard
		add_action( 'lifterlms_view_order_after_payment_method', array( $this, 'output_order_payment_source' ) );

	}

	/**
	 * Are Saved Cards enabled?
	 * @return   boolean
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function are_saved_cards_enabled() {
		return ( 'yes' === $this->get_option( 'saved_cards_enabled' ) );
	}

	/**
	 * Execute a charge
	 * @param  LLMS_Order   $order   instance of the LLMS_Order to execute a charge for
	 * @param  string   $type    payment type [initial|recurring]
	 * @since  2.0.0
	 * @version 4.1.0
	 */
	private function charge( $order, $type = 'initial' ) {

		$this->log( 'Stripe `charge()` started' );

		if ( 'initial' === $type ) {
			$amount = $order->get_initial_price( array(), 'float' );
		} else {
			$amount = $order->get_price( 'total', array(), 'float' );
		}

		if ( $amount <= 0 && ! $order->has_trial() ) {
			$this->log( $charge, 'Stripe `charge()` finished with errors', 'nothing to charge' );
			return new WP_Error( 'error', sprintf( __( 'Cannot process a transaction for "%s"', 'lifterlms-stripe' ), $amount ) );
		}

		// trial, we have the CC on the customer in stripe and that'll be charged next time
		if ( floatval( 0 ) === $amount ) {

			$txn = $order->record_transaction( array(
				'amount' => $amount,
				'completed_date' => current_time( 'mysql' ),
				'customer_id' => $order->get( 'gateway_customer_id' ),
				'source_description' => __( 'Free Trial', 'lifterlms-stripe' ),
				'status' => 'llms-txn-succeeded',
				'payment_type' => 'trial',
			) );

			$order->add_note( __( 'Free trial started', 'lifterlms-stripe' ) );

			return true;

		}
		// process a charge right now
		else {

			// single
			if ( ! $order->is_recurring() ) {
				$payment_type = 'single';
			}
			// recurring
			else {
				$payment_type = ( $order->has_trial() && 'initial' === $type ) ? 'trial' : 'recurring';
			}

			// record the pending transaction
			$txn = $order->record_transaction( array(
				'amount' => $amount,
				'customer_id' => $order->get( 'gateway_customer_id' ),
				'status' => 'llms-txn-pending',
				'payment_type' => $payment_type,
			) );

			// setup data to pass to stripe
			$data = array(
				'amount'   => $this->get_stripe_amount( $amount ),
				'description' => apply_filters( 'llms_stripe_charge_description', sprintf( '%s: %s', ucwords( $order->get( 'product_type' ) ), $order->get( 'product_title' ) ), $order ),
				'currency' => $order->get( 'currency' ),
				'customer' => $order->get( 'gateway_customer_id' ),
				'metadata' => array(
					__( 'AccessPlanName', 'lifterlms-stripe' ) => $order->get( 'plan_title' ),
					__( 'AccessPlanSKU', 'lifterlms-stripe' ) => $order->get( 'plan_sku' ),
					__( 'AccessPlanID', 'lifterlms-stripe' ) => $order->get( 'plan_id' ),
					__( 'ProductName', 'lifterlms-stripe' ) => $order->get( 'product_title' ),
					__( 'ProductSKU', 'lifterlms-stripe' ) => $order->get( 'product_sku' ),
					__( 'ProductID', 'lifterlms-stripe' ) => $order->get( 'product_id' ),
					__( 'ProductType', 'lifterlms-stripe' ) => $order->get( 'product_type' ),
				),
				'source' => $order->get( 'gateway_source_id' ),
				'statement_descriptor' => llms_trim_string( apply_filters( 'llms_stripe_statement_descriptor', get_bloginfo( 'name' ) . ' - ' . ucwords( $order->get( 'product_type' ) ), $order ), 22 ),
			);

			// attempt the charge
			$r = Wixbu_Stripe()->call_api( 'charges', apply_filters( 'llms_stripe_new_charge_data', $data ) );

			// error...
			if ( $r->is_error() ) {

				$txn->set( 'status', 'llms-txn-failed' );

				$order->add_note( sprintf( __( 'Failed charge attempt for %s payment using "%s"', 'lifterlms-stripe' ), $payment_type, $order->get( 'gateway_source_id' ) ) );

				$this->log( $r, 'Stripe `charge()` finished with errors' );

				return $r->get_result();

			}

			// success
			else {

				// record transaction data
				$data = $r->get_result();
				$txn->set( 'completed_date', $data->created );
				$txn->set( 'gateway_source_id', $data->source->id );
				$txn->set( 'gateway_source_description', $this->get_card_desrcription( $data->source ) );
				$txn->set( 'gateway_transaction_id', $data->id );
				$txn->set( 'status', 'llms-txn-succeeded' );

				$order->add_note( sprintf( __( 'Charge attempt for %s payment using "%s" succeeded! [Charge ID: %s]', 'lifterlms-stripe' ), $payment_type, $order->get( 'gateway_source_id' ), $data->id ) );

				$this->log( $r, 'Stripe `charge()` finished' );

				// return the transaction
				return $data;

			}

		}

	}

	/**
	 * Convert a Stripe Card Object into a String an obscured string
	 * for onscreen display
	 *
	 * @param    obj     $card  Stripe API Source object
	 * @return   string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_card_desrcription( $card ) {
		return apply_filters( 'llms_stripe_get_card_desrcription', sprintf( '%s &mdash; **** **** **** %s', $card->brand, $card->last4 ), $card );
	}

	/**
	 * Return a URL to a customer on the Stripe Dashboard
	 * @param    string     $customer_id  Gateway's customer ID
	 * @param    string     $api_mode     Link to either the live or test site for the gateway, where applicabale
	 * @return   string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_customer_url( $customer_id, $api_mode = 'live' ) {
		$url = self::DASHBOARD_URL;
		if ( 'test' === $api_mode ) {
			$url .= 'test/';
		}
		$url .= 'customers/' . $customer_id;
		return apply_filters( 'llms_stripe_get_customer_url', $url, $customer_id, $api_mode );
	}

	/**
	 * Output Credit Card fields on the Checkout form
	 * @return   string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_fields() {

		// get saved cards from Stripe
		$user_id = get_current_user_id();
		if ( $user_id && $this->are_saved_cards_enabled() ) {
			$customer = new LLMS_Stripe_Customer( $user_id, $this );

			$cards = $customer->get_customer_id() ? $customer->get_cards() : false;

		} else {
			$cards = false;
		}

		/** @var LLMS_Access_Plan $plan */
		$plan = new LLMS_Access_Plan( $_GET['plan'] );

		if ( $plan ) {
			$product = $plan->get_product();
			if( $product && $product->author ) {
				ob_start();
				llms_get_template( 'stripe-cc-form.php', array(
					'cards'    => $cards,
					'gateway'  => $this,
					'selected' => ( $this->get_id() === LLMS()->payment_gateways()->get_default_gateway() ),
				), '', LLMS_STRIPE_PLUGIN_DIR . 'templates/' );

				return apply_filters( 'llms_get_gateway_admin_title', ob_get_clean(), $this->id );
			}
		}

	}

	/**
	 * Get live publishable key
	 * @return   string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_live_publishable_key() {
		return $this->get_option( 'live_publishable_key' );
	}

	/**
	 * Get live secret key
	 * @return   string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_live_secret_key() {
		return $this->get_option( 'live_secret_key' );
	}

	/**
	 * Get the Publishable key based on the current API mode
	 * @return string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_publishable_key() {

		if ( 'test' === $this->get_api_mode() ) {
			$key = $this->get_test_publishable_key();
		} else {
			$key = $this->get_live_publishable_key();
		}
		return $key;

	}

	/**
	 * Get the Secret key based on the current API mode
	 * @return string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_secret_key() {

		if ( 'test' === $this->get_api_mode() ) {
			$key = $this->get_test_secret_key();
		} else {
			$key = $this->get_live_secret_key();
		}
		return $key;

	}

	/**
	 * Convert amount / price to amount used by stripe (decimal-less)
	 * @return   int
	 * @since    4.0.0
	 * @version  4.5.0 - removed logging function
	 */
	public function get_stripe_amount( $amount, $currency = '' ) {

		if ( ! $currency ) {
			$currency = get_lifterlms_currency();
		}

		switch ( strtoupper( $currency ) ) {

			// Zero decimal currencies
			case 'BIF' :
			case 'CLP' :
			case 'DJF' :
			case 'GNF' :
			case 'JPY' :
			case 'KMF' :
			case 'KRW' :
			case 'MGA' :
			case 'PYG' :
			case 'RWF' :
			case 'VND' :
			case 'VUV' :
			case 'XAF' :
			case 'XOF' :
			case 'XPF' :
				$amount = absint( $amount );
			break;

			// Decimal Currencies
			default :
				$amount = round( $amount, 2 ) * 100; // cents
			break;

		}

		return $amount;

	}

	/**
	 * Get test publishable key
	 * @return   string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_test_publishable_key() {
		return $this->get_option( 'test_publishable_key' );
	}

	/**
	 * Get test secret key
	 * @return   string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_test_secret_key() {
		return $this->get_option( 'test_secret_key' );
	}

	/**
	 * Get the minimum allowe transaction amount for a given currency
	 * @see      https://support.stripe.com/questions/what-is-the-minimum-amount-i-can-charge-with-stripe
	 * @param    string     $currency  currency code
	 * @return   float
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_transaction_minimum( $currency ) {

		switch( $currency ) {

			case 'AUD': $min = 0.50; break;
			case 'CAD': $min = 0.50; break;
			case 'CHF': $min = 0.50; break;
			case 'DKK': $min = 2.50; break;
			case 'EUR': $min = 0.50; break;
			case 'GBP': $min = 0.30; break;
			case 'HKD': $min = 4.00; break;
			case 'JPY': $min = 50;   break;
			case 'MXN': $min = 10;   break;
			case 'NOK': $min = 3.00; break;
			case 'SEK': $min = 3.00; break;
			case 'SGD': $min = 0.50; break;
			case 'USD': $min = 0.50; break;
			default: $min = 0;       break;

		}

		return apply_filters( 'llms_stripe_get_transaction_minimum', $min, $currency );

	}

	/**
	 * Return a URL to a charge on the Stripe Dashboard
	 * @param    string     $transaction_id  Gateway's transaction ID
	 * @param    string     $api_mode        Link to either the live or test site for the gateway, where applicabale
	 * @return   string
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_transaction_url( $transaction_id, $api_mode = 'live' ) {
		$url = self::DASHBOARD_URL;
		if ( 'test' === $api_mode ) {
			$url .= 'test/';
		}
		$url .= 'payments/' . $transaction_id;
		return apply_filters( 'llms_stripe_get_customer_url', $url, $transaction_id, $api_mode );
	}

	/**
	 * Create or Retrieve a Stripe Customer ID for the user who's checking out
	 * @param  int             $user_id   WordPress User ID of the user
	 * @param  string          $token     new card token from Stripe.js OR a card_id if customer is using a saved card
	 * @return WP_Error|string            Stripe Customer ID or WP_Error if creation fails
	 * @since  2.0.0
	 * @version 4.0.0
	 */
	private function handle_customer( $user_id, $token ) {

		// instantiate the customer
		$customer = new LLMS_Stripe_Customer( $user_id, $this );
		$customer_id = $customer->get_customer_id();

		// if customer doesn't have a customer id we have to create a customer
		if ( ! $customer_id ) {

			return $customer->create( $token );

		}
		// customer already exists, add the new source if it's not a card
		elseif ( false === strpos( $token, 'card_' ) ) {

			$add_source = $customer->add_source( $token, true );
			if ( is_wp_error( $add_source ) ) {

				return $add_source;

			}

		}

		return $customer_id;

	}

	/**
	 * Called when the Update Payment Method form is submitted from a single order view on the student dashboard
	 *
	 * Gateways should do whatever the gateway needs to do to validate the new payment method and save it to the order
	 * so that future payments on the order will use this new source
	 *
	 * @param    obj     $order      Instance of the LLMS_Order
	 * @param    array   $form_data  Additional data passed from the submitted form (EG $_POST)
	 * @return   void
	 * @since    4.2.0
	 * @version  4.2.0
	 */
	public function handle_payment_source_switch( $order, $form_data = array() ) {

		$this->log( 'Stripe `handle_payment_source_switch()` started', $order );

		if ( empty( $form_data['llms_stripe_token'] ) && empty( $form_data['llms_stripe_card_id'] ) ) {
			return llms_add_notice( __( 'Missing payment method details.', 'lifterlms-stripe' ), 'error' );
		}

		$token = ( ! empty( $form_data['llms_stripe_token'] ) ) ? $form_data['llms_stripe_token'] : $form_data['llms_stripe_saved_card_id'];
		$token = sanitize_text_field( $token );

		// create / update the customer in Stripe
		$customer_id = $this->handle_customer( $order->get( 'user_id' ), $token );
		if ( is_wp_error( $customer_id ) ) {
			$this->log( 'Stripe `handle_pending_order()` finished with errors', $customer_id );
			return llms_add_notice( $customer_id->get_error_message(), 'error' );
		}

		// possible changes
		$changes = array(
			'payment_gateway' => array(
				'new_value' => $this->get_id(),
				'note' => esc_html__( 'Payment gateway changed from "%1$s" to "%2$s".', 'lifterlms-stripe' ),
			),
			'gateway_customer_id' => array(
				'new_value' => $customer_id,
				'note' => esc_html__( 'Customer ID changed from "%1$s" to "%2$s".', 'lifterlms-stripe' ),
			),
			'gateway_source_id' => array(
				'new_value' => sanitize_text_field( $form_data['llms_stripe_card_id'] ),
				'note' => esc_html__( 'Source ID changed from "%1$s" to "%2$s".', 'lifterlms-stripe' ),
			),
		);

		$note = array(
			esc_html__( 'Payment source updated by customer.', 'lifterlms-stripe' ),
		);

		foreach ( $changes as $meta_key => $data ) {

			$old_val = $order->get( $meta_key );
			if ( $old_val !== $data['new_value'] ) {
				$order->set( $meta_key, $data['new_value'] );
				$note[] = sprintf( $data['note'], $old_val, $data['new_value'] );
			}

		}

		$order->set( 'gateway_subscription_id', '' );

		$order->add_note( implode( ' ', $note ) );

		if ( isset( $form_data['llms_switch_action'] ) && 'pay' === $form_data['llms_switch_action'] ) {
			$this->handle_recurring_transaction( $order );
		}

		$this->log( 'Stripe `handle_payment_source_switch()` finished', $order );

	}

	/**
	 * Handle a Pending Order
	 * Called by LLMS_Controller_Orders->create_pending_order() on checkout form submission
	 * All data will be validated before it's passed to this function
	 *
	 * @param   obj       $order   Instance LLMS_Order for the order being processed
	 * @param   obj       $plan    Instance LLMS_Access_Plan for the order being processed
	 * @param   obj       $person  Instance of LLMS_Student for the purchasing customer
	 * @param   obj|false $coupon  Instance of LLMS_Coupon applied to the order being processed, or false when none is being used
	 * @return  void
	 * @since   4.0.0
	 * @version 4.1.0
	 */
	public function handle_pending_order( $order, $plan, $person, $coupon = false ) {

		$this->log( 'Stripe `handle_pending_order()` started', $order, $plan, $person, $coupon );

		if ( empty( $_POST['llms_stripe_token'] ) && empty( $_POST['llms_stripe_card_id'] ) ) {
			return llms_add_notice( __( 'Missing payment method details.', 'lifterlms-stripe' ), 'error' );
		}

		// do some gateway specific validation before proceeding
		$total = $order->get_price( 'total', array(), 'float' );
		$currency = $order->get( 'currency' );
		$min = $this->get_transaction_minimum( $currency );
		if ( $total < $min ) {
			return llms_add_notice( sprintf( _x( 'Stripe cannot process %s transactions for less than %s', 'min transaction amount error', 'lifterlms-stripe' ), $currency, $min ), 'error' );
		} elseif ( $this->get_stripe_amount( $total, $currency ) > self::MAX_AMOUNT ) {
			return llms_add_notice( sprintf( _x( 'Stripe cannot process %s transactions for more than %s', 'max transaction amount error', 'lifterlms-stripe' ), $currency, self::MAX_AMOUNT ), 'error' );
		}

		$token = ( ! empty( $_POST['llms_stripe_token'] ) ) ? sanitize_text_field( $_POST['llms_stripe_token'] ) : sanitize_text_field( $_POST['llms_stripe_saved_card_id'] );

		// create / update the customer in Stripe
		$customer_id = $this->handle_customer( $order->get( 'user_id' ), $token );
		if ( is_wp_error( $customer_id ) ) {

			$this->log( 'Stripe `handle_pending_order()` finished with errors', $customer_id );
			return llms_add_notice( $customer_id->get_error_message(), 'error' );
		}

		$order->add_note( sprintf( __( 'Stripe Customer "%s" created or updated.', 'lifterlms-stripe' ), $customer_id ) );

		$order->set( 'gateway_customer_id', $customer_id );
		$order->set( 'gateway_source_id', sanitize_text_field( $_POST['llms_stripe_card_id'] ) );

		$charge = $this->charge( $order, 'initial' );

		if ( is_wp_error( $charge ) ) {

			$this->log( 'Stripe `handle_pending_order()` finished with errors', $charge );
			return llms_add_notice( $charge->get_error_message(), 'error' );

		} else {

			$this->log( $charge, 'Stripe `handle_pending_order()` finished' );

			$this->add_earning_report_field( $charge );

			$this->complete_transaction( $order );

		}

	}

	/**
	 * Called by scheduled actions to charge an order for a scheduled recurring transaction
	 * This function must be defined by gateways which support recurring transactions
	 * @param    obj       $order   Instance LLMS_Order for the order being processed
	 * @return   void
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function handle_recurring_transaction( $order ) {

		$this->log( 'Stripe `handle_recurring_transaction()` started', $order );

		$charge = $this->charge( $order, 'recurring' );

		/**
		 * @todo  notifications
		 */
		if ( is_wp_error( $charge ) ) {

			$this->log( 'Stripe `handle_recurring_transaction()` finished with errors', $charge );

		} else {

			$this->log( 'Stripe `handle_recurring_transaction()` finished', $charge );

		}

	}

	/**
	 * Output the order's source description on the student dashboard single order view
	 * @param    obj     $order  instance of the LLMS_Order being viewed
	 * @return   void
	 * @since    4.2.0
	 * @version  4.2.0
	 */
	public function output_order_payment_source( $order ) {

		if ( 'stripe' !== $order->get( 'payment_gateway') ) {
			return;
		}

		$customer = new LLMS_Stripe_Customer( $order->get( 'user_id' ), $this );
		$source = $customer->get_source( $order->get( 'gateway_source_id' ) );
		if ( isset( $source->brand ) && isset( $source->last4 ) ) {
			echo sprintf( '&mdash; %1$s **** **** **** %2$s', $source->brand, $source->last4 );
		}

	}

	/**
	 * Called when refunding via a Gateway
	 * This function must be defined by gateways which support refunds
	 * This function is called by LLMS_Transaction->process_refund()
	 * @param    obj     $transaction  Instance of the LLMS_Transaction
	 * @param    float   $amount       Amount to refund
	 * @param    string  $note         Optional refund note to pass to the gateway
	 * @return   string|WP_Error       refund id on success, WP_Error otherwise
	 * @since    4.1.0
	 * @version  4.1.0
	 */
	public function process_refund( $transaction, $amount = 0, $note = '' ) {

		$this->log( 'Stripe `process_refund()` started', $transaction, $amount, $note );

		if ( ! $transaction ) {
			return new WP_Error( 'error', __( 'Missing or invalid transaction.', 'lifterlms-stripe' ) );
		}

		$data = array(
			'amount' =>  $this->get_stripe_amount( $amount, $transaction->get( 'currency' ) ),
			'charge' => $transaction->get( 'gateway_transaction_id' ),
			'metadata' => array(
				'note' => $note,
			),
		);

		// attempt the refund
		$ret = Wixbu_Stripe()->call_api( 'refunds', apply_filters( 'llms_stripe_refund_data', $data ) );

		// error...
		if ( $ret->is_error() ) {

			$this->log( $r, 'Stripe `process_refund()` finished with errors' );
			return $r->get_result();

		}
		// check for success
		else {

			$res = $ret->get_result();

			// real success
			if ( isset( $res->status ) && 'succeeded' === $res->status ) {

				$this->log( $r, 'Stripe `process_refund()` finished' );
				return $res->id;

			} else {

				$this->log( $res, 'Stripe `process_refund()` finished with errors' );

			}

		}

		return new WP_Error( 'error', __( 'An unknown error was encountered while attempting to process the refund.', 'lifterlms-stripe' ) );

	}

	/**
	 * Output custom settings fields on the LifterLMS Gateways Screen
	 * @param    array     $fields      array of existing fields
	 * @param    string    $gateway_id  id of the gateway
	 * @return   array
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function settings_fields( $fields, $gateway_id ) {

		// don't add fields to other gateways!
		if ( $this->id !== $gateway_id ) {
			return $fields;
		}

		$fields[] = array(
			'type'  => 'custom-html',
			'value' => '
				<h4>' . __( 'Stripe Live API Credentials', 'lifterlms-stripe' ) . '</h4>
				<p>' . sprintf( __( 'Enter your Stripe API credentials to process transactions via Stripe. Learn how to access your Stripe API Credentials <a href="%s">here</a>.', 'lifterlms-stripe' ), 'https://support.stripe.com/questions/where-do-i-find-my-api-keys' ) . '</p>
			',
		);

		$live = array(
			'live_secret_key' => __( 'Live Secret Key', 'lifterlms-stripe' ),
			'live_publishable_key' => __( 'Live Publishable Key', 'lifterlms-stripe' ),
		);
		foreach( $live as $k => $v ) {
			$fields[] = array(
				'id'            => $this->get_option_name( $k ),
				'default'       => $this->{'get_' . $k}(),
				'title'         => $v,
				'type'          => 'text',
			);
		}

		$fields[] = array(
			'type'  => 'custom-html',
			'value' => '
				<h4>' . __( 'Stripe Test API Credentials', 'lifterlms-stripe' ) . '</h4>
				<p>' . sprintf( __( 'Enter your Stripe API credentials to process transactions via Stripe. Learn how to access your Stripe API Credentials <a href="%s">here</a>.', 'lifterlms-stripe' ), 'https://support.stripe.com/questions/where-do-i-find-my-api-keys' ) . '</p>
			',
		);

		$test = array(
			'test_secret_key' => __( 'Test Secret Key', 'lifterlms-stripe' ),
			'test_publishable_key' => __( 'Test Publishable Key', 'lifterlms-stripe' ),
		);
		foreach( $test as $k => $v ) {
			$fields[] = array(
				'id'            => $this->get_option_name( $k ),
				'default'       => $this->{'get_' . $k}(),
				'title'         => $v,
				'type'          => 'text',
			);
		}

		$fields[] = array(
			'type'  => 'custom-html',
			'value' => '<h4>' . __( 'Additional Options', 'lifterlms-stripe' ) . '</h4>',
		);

		$fields[] = array(
			'id'            => $this->get_option_name( 'saved_cards_enabled' ),
			'desc'          => __( 'Enable Saved Cards', 'lifterlms-stripe' ),
			'desc_tooltip'  => __( 'When enabled, users can checkout with a card saved during previous transactions. Cards information is stored on Stripe\'s servers.', 'lifterlms-stripe' ),
			'default'       => 'no',
			'title'         => __( 'Saved Cards', 'lifterlms-stripe' ),
			'type'          => 'checkbox',
		);

		/**
		 * @todo  implement stripe checkout options
		 */

		return $fields;

	}

	private function add_earning_report_field( $charge ) {
		$meta = (array)$charge->metadata;

		$amount = $charge->amount / 100;
		$net = +$meta['NetReceived'];

		$type = $meta['ProductType'] == 'membership' ? 'subs' : 'sale';
		$time = date('Ymd');
		update_user_meta(
			$meta['Instructor'],
			"$type-$time::$meta[Student]::$meta[ProductID]",
			"$amount::$net::$meta[ProductName]"
		);
	}

}
