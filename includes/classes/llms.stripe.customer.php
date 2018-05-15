<?php
/**
 * Handle LifterLMS Stripe Customer API calls and functions
 * @since  2.0.0
 * @version  4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LLMS_Stripe_Customer {

	/**
	 * Constructor
	 * @param    int     $user_id  WP User ID
	 * @param    obj     $gateway  Instance of LLMS_Payment_Gateway_Stripe
	 * @since    2.0.0
	 * @version  4.0.0
	 */
	public function __construct( $user_id, $gateway ) {

		$this->id = $user_id;
		$this->user = new WP_User( $this->id );
		$this->gateway = $gateway;

	}

	/**
	 * Creates the current user in Stripe and attaches a default source
	 * @param  string $token  token id obtained from stripe.js
	 * @return mixed
	 * @since    2.0.0
	 * @version  4.1.0
	 */
	public function create( $token ) {

		$customer = Wixbu_Stripe()->call_api( 'customers', apply_filters( 'llms_stripe_new_customer_data', array(

			'email' => $this->user->user_email,
			'metadata' => array(
				__( 'LifterLMS Student ID', 'lifterlms-stripe' ) => $this->id
			),
			'source' => $token

		), $this->id ) );

		// api call failed
		if ( is_wp_error( $customer->get_result() ) ) {

			return $customer->get_result();

		}
		// success
		else {

			$res = $customer->get_result();

			update_user_meta( $this->id, 'llms_stripe_' . $this->gateway->get_api_mode() . '_customer_id', $res->id );

			return $res->id;

		}

	}

	/**
	 * Add a new source to an existing stripe customer
	 * @param string  $token   token id obtained from stripe.js
	 * @param bool    $default if true, set's card as default source after adding it
	 * @return mixed          WP_Error or true
	 * @since    2.0.0
	 * @version  4.0.0
	 */
	public function add_source( $token, $default = true ) {

		$id = $this->get_customer_id();

		// if card saving is enabled, add the card and set as default
		if ( $this->gateway->are_saved_cards_enabled() ) {

			$source = Wixbu_Stripe()->call_api( 'customers/' . $id . '/sources', array(
				'source' => $token
			) );

			if ( is_wp_error( $source->get_result() ) ) {

				return $source->get_result();

			}

			// set new card as the default source
			elseif ( $default ) {

				$card = $source->get_result();
				$make_default = Wixbu_Stripe()->call_api( 'customers/' . $id, array(
					'default_source' => $card->id,
				) );

				if ( is_wp_error( $make_default->get_result() ) ) {

					return $customer->get_result();

				}

			}

		}

		// this removes replaces existing default
		// use this when creating a brand new customer
		// or when adding a card and card saving is disabled
		else {

			$customer = Wixbu_Stripe()->call_api( 'customers/' . $id, array(

				'source' => $token

			) );

			if ( is_wp_error( $customer->get_result() ) ) {

				return $customer->get_result();

			}

		}

		return true;

	}

	/**
	 * Retrieve the customer's Stripe Customer ID from the usermeta table
	 * 		Will return an empty string if the customer doesn't exist
	 *   	Will return an ID specific to the current API mode
	 *   		This prevents collisions and issues when switching between live and test mode
	 * @return string
	 * @since    2.0.0
	 * @version  4.0.0
	 */
	public function get_customer_id() {

		// return a test mode stripe customer id
		if ( 'test' === $this->gateway->get_api_mode() ) {

			$customer_id = $this->user->llms_stripe_test_customer_id;

		}

		// return a live mode stripe customer id
		else {

			$customer_id = $this->user->llms_stripe_live_customer_id;

		}

		return $customer_id;

	}

	/**
	 * Retrieve an array of cards attached to a customer
	 *
	 * Note: this will only return the most recent 10 cards attached to the customer's stripe account
	 *
	 * @return   WP_Error|Array
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	public function get_cards() {

		$id = $this->get_customer_id();

		$customer = Wixbu_Stripe()->call_api( 'customers/' . $id, array(), 'GET' );

		if ( $customer->is_error() ) {

			return $customer->get_result();

		} else {

			$customer = $customer->get_result();

			$cards = array();
			if ( isset( $customer->sources ) && isset( $customer->sources->data ) ) {

				foreach ( $customer->sources->data as $card ) {

					$cards[ $card->id ] = $this->gateway->get_card_desrcription( $card );

				}

				$cards['create-new'] = __( 'Add a new card', 'lifterlms-stripe' );

			}

			return $cards;

		}

	}

	/**
	 * Retrieve information about a single payment source by ID
	 * @param    string     $source_id  the Stripe source id
	 * @return   obj
	 * @since    4.2.0
	 * @version  4.2.0
	 */
	public function get_source( $source_id ) {

		$id = $this->get_customer_id();

		$card = Wixbu_Stripe()->call_api( 'customers/' . $id . '/sources/' . $source_id, array(), 'GET' );

		return $card->get_result();

	}

}
