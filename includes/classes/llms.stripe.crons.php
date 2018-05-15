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

		if ( ! wp_next_scheduled( 'llms_stripe_cancel_expired_subscriptions' ) ) {

			wp_schedule_event( time(), 'daily', 'llms_stripe_cancel_expired_subscriptions' );

		}

	}


}
return new LLMS_Stripe_Crons();
