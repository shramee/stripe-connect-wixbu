<?php
/**
 * Handle registration, localization, and enqueues
 *
 * @since    4.0.0
 * @version  4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LLMS_Stripe_Assets {

	public function __construct() {

		add_action( 'wp', array( $this, 'init' ) );
		add_action( 'wp_ajax_llms_stripe_token', array( $this, 'llms_stripe_token' ) );

	}

	/**
	 * Register, enqueue, & localize
	 * @return void
	 * @since  4.0.0
	 * @version  4.0.1
	 */
	public function enqueue() {

		// Only enqueque js files if stripe is actually enabled as a payment gateway
		if ( 'yes' === get_option( 'llms_gateway_stripe_enabled', 'no' ) ) {

			// if WP_DEBUG is enabled, load unminifed scripts
			$min = (!defined('WP_DEBUG') || WP_DEBUG == false) ? '.min' : '';

			wp_register_script('stripe', 'https://js.stripe.com/v2/', false, 'v2', true);
			wp_register_script('lifterlms-stripe', plugins_url('assets/js/llms-stripe' . $min . '.js', LLMS_STRIPE_PLUGIN_FILE), array('jquery', 'stripe', 'llms-form-checkout'), LLMS_STRIPE_VERSION, true);
			wp_enqueue_script('lifterlms-stripe');

			/** @var LLMS_Payment_Gateway_Stripe $stripe */
			$stripe = LLMS()->payment_gateways()->get_gateway_by_id('stripe');

			// localize the script
			wp_localize_script('lifterlms-stripe', 'llms_stripe', array(
				'publishable_key' => $stripe->get_publishable_key(),
				'tkn_ajax_url' => admin_url( 'admin-ajax.php?action=llms_stripe_token' ),
			));
		}
	}

	public function llms_stripe_token() {

	}

	/**
	 * Get started
	 * @return   void
	 * @since    4.0.0
	 * @version  4.2.0
	 */
	public function init() {

		if ( is_llms_checkout() || ( get_current_user_id() && is_llms_account_page() ) ) {

			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		}

	}

}
return new LLMS_Stripe_Assets();
