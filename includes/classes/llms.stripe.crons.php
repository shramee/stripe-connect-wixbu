<?php
/**
 * Handle all LifterLMS Stripe cron jobs
 * @since  2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
class LLMS_Stripe_Crons
{


	/**
	 * Constructor
	 * Add actions and run the scheduler
	 */
	public function __construct()
	{

		add_action( 'wp', array( $this, 'schedule' ) );

		// add_action( 'init', array( $this, 'cancel_expired_subscriptions' ) );
		add_action( 'llms_stripe_cancel_expired_subscriptions', array( $this, 'cancel_expired_subscriptions' ) );
		add_action( 'wixbu_do_payouts', array( $this, 'wixbu_do_payouts' ) );

	}



	/**
	 * Cancel expired subscriptions
	 *
	 * Called by action 'llms_stripe_cancel_expired_subscriptions'
	 *
	 * @return void
	 */
	public function cancel_expired_subscriptions()
	{

		// find orders matching the following
		// 1) have a stripe subscription id
		// 2) are not already cancelled
		// 3) have a billing cycle of 1 or more
		// 4) were processed via Stripe
		$orders = new WP_Query( array(

			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'compare' => 'EXISTS',
					'key'     => '_llms_gateway_subscription_id',
				),
				array(
					'compare' => '!=',
					'key'     => '_llms_stripe_subscription_status',
					'value'   => 'cancelled',
				),
				array(
					'compare' => '>=',
					'key'     => '_llms_billing_length',
					'value'   => 1
				),
				array(
					'key'     => '_llms_payment_gateway',
					'value'   => 'stripe',
				),
			),
			'post_type'      => 'order',
			'post_status'    => 'any',
			'posts_per_page' => -1,

		) );

		if( $orders->have_posts() ) {

			$now = current_time( 'timestamp' );

			// loop through orders
			foreach( $orders->posts as $post ) {

				$start = get_post_meta( $post->ID, '_llms_order_date', true );
				$period = get_post_meta( $post->ID, '_llms_billing_period', true );
				$frequency = get_post_meta( $post->ID, '_llms_billing_frequency', true );
				$cycles = get_post_meta( $post->ID, '_llms_billing_length', true );

				$total_cycles = intval( $frequency ) * intval( $cycles );
				$s = ( intval( $total_cycles ) > 1 ) ? 's' : '';

				$end = date( 'Y-m-d H:i:s' , strtotime( '+' . $total_cycles . ' ' . $period . $s , $now ) );

				// expired if current time is greater than the scheduled end date
				if( $now > strtotime( $end ) ) {

					$customer_id = get_post_meta( $post->ID, '_llms_gateway_customer_id', true );
					$subscription_id = get_post_meta( $post->ID, '_llms_gateway_subscription_id', true );

					// delete
					$del = LLMS_Stripe()->call_api( 'customers/' . $customer_id . '/subscriptions/' . $subscription_id, null, 'DELETE' );

					/**
					 * @todo  make a setting to email error logs
					 *        b/c if debug mode is off and people aren't watching logs
					 *        they'll never see this probably
					 */
					if( is_wp_error( $del ) ) {

						llms_log( 'LifterLMS Stripe Error: Unable to Cancel Subscription, details below' );
						llms_log( $del );

					}
					// success
					else {

						// record the cancellation on the order
						update_post_meta( $post->ID, '_llms_stripe_subscription_status', 'cancelled' );

					}

				}

			}

		}

	}

	/**
	 * Schedule Crons
	 * @return void
	 */
	public function schedule()
	{

//		$this->wixbu_do_payouts();die();

		if ( ! wp_next_scheduled( 'llms_stripe_cancel_expired_subscriptions' ) ) {

			wp_schedule_event( time(), 'daily', 'llms_stripe_cancel_expired_subscriptions' );

		}

		if ( ! wp_next_scheduled( 'wixbu_do_payouts' ) && class_exists( 'Wixbu_Instructors' ) ) {

			$date = Wixbu_Instructors::instance()->next_payout_date();

			wp_schedule_single_event( strtotime( $date ), 'wixbu_do_payouts' );

		}
	}

	public function wixbu_do_payouts() {
		/** @var $wpdb wpdb */
		global $wpdb;

		$date = date( 'Ymd', strtotime( '- 30 days' ) );

		$results = $wpdb->get_results(
			"SELECT t2.* FROM `$wpdb->postmeta` as t1 JOIN `$wpdb->postmeta` AS t2 ON t1.post_id =  t2.post_id " .
			"WHERE t2.meta_key BETWEEN 'wixbu_order_data' " .
			"AND 'wixbu_order_data|{$date}z' AND t1.meta_key LIKE 'wixbu_payout_pending'" );

		$instructors_to_pay = [];

		foreach ( $results as $result ) {
			$data = unserialize( $result->meta_value );
			$data['post_id'] = $result->post_id;
			if ( empty( $instructors_to_pay[ $data['instructor'] ] ) ) {
				$instructors_to_pay[ $data['instructor'] ] = [
					'orders'    => [],
					'gross'     => [],
					'stripe_id' => '',
				];
			}

			if ( isset( $data['stripe_id'] ) ) {
				$instructors_to_pay[ $data['instructor'] ]['stripe_id'] = $data['stripe_id'];
			}

			$instructors_to_pay[ $data['instructor'] ]['gross'][] = ( $data['amount'] - $data['tax'] );
			$instructors_to_pay[ $data['instructor'] ]['orders'][] = $result->post_id;

			// @TODO Make payment to instructor here
		}

		foreach ( $instructors_to_pay as $intructor_user_id => $instructor ) {

			$amt = array_sum( $instructor['gross'] );
			$amt *= INSTRUCTOR_SHARE / 100;
			$amt *= .95; // Stripe fees 5%

			if ( empty( $instructor['stripe_id'] ) ) {
				$instructor['stripe_id'] = get_user_meta( $intructor_user_id, 'stripe_user_id', 1 );
			}
			$transfer_data = [
			'amount' => floor( $amt * 100 ),
			'currency' => get_lifterlms_currency(),
			'destination' => $instructor['stripe_id'],
			'transfer_group' => "wixbu_instructor_{$intructor_user_id}",
			];

			var_dump( $transfer_data );

			$transfer_data['amount'] = 160;

			$result = Wixbu_Stripe()->call_api( 'transfers', $transfer_data );

			$result = $result->get_result();

			$instructor['user_id'] = $intructor_user_id;
			$instructor['paid'] = $amt;

			do_action( 'wixbu_intructor_paid_out', $result, $instructor );

			foreach ( $instructor['orders'] as $order ) {
				delete_post_meta( $order, 'wixbu_payout_pending' );
			}
		}
	}

}
return new LLMS_Stripe_Crons();
