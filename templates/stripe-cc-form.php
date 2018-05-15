<?php
/**
 * Checkout LLMS_Payment_Gateway
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

do_action( 'lifterlms_before_checkout_cc_form' );

if ( 'test' === $gateway->get_api_mode() ) {

	$notice = __( 'LifterLMS Stripe is currently in test mode. You may use any test cards as documented <a href="https://stripe.com/docs/testing">here</a> or use the prefill options below to process test transactions.', 'lifterlms-stripe' );
	$notice .= llms_form_field( array(
		'columns' => 12,
		'id' => 'llms-stripe-autofill-cc-card-number',
		'last_column' => true,
		'name' => false,
		'options' => apply_filters( 'llms_stripe_test_cards', array(
			'4242424242424242' => __( 'Payment Success', 'lifterlms-stripe' ),
			'4000000000000002' => __( 'Card Declined (During Validation)', 'lifterlms-stripe' ),
			'4000000000000341' => __( 'Card Declined (During Charge)', 'lifterlms' ),
			'4000000000000069' => __( 'Card Expired', 'lifterlms-stripe' ),
			'4000000000000127' => __( 'Incorrect CVV', 'lifterlms-stripe' ),
		) ),
		'required' => false,
		'type'  => 'select',
	), false );

	$notice .= llms_form_field( array(
		'columns' => 12,
		'id' => 'llms-stripe-autofill-cc',
		'last_column' => true,
		'name' => false,
		'required' => false,
		'type'  => 'button',
		'value' => __( 'Use Card', 'lifterlms-stripe' ),
	), false );

	llms_print_notice( $notice, 'debug' );


}


if ( $cards ) {

	echo '<div class="llms-form-field llms-cols-12 llms-cols-last"><label>' . __( 'Payment Method', 'lifterlms-stripe' ) . '</label></div><div class="clear"></div>';
	llms_form_field( array(
		'columns' => 12,
		'disabled' => $selected ? false : true,
		'id' => 'llms_stripe_saved_card_id',
		'last_column' => true,
		'required' => false,
		'type'  => 'select',
		'options' => $cards,
	) );

}

echo '<section class="llms-stripe-cc-form">';

	llms_form_field( array(
		'columns' => 12,
		'disabled' => $selected ? false : true,
		'id' => 'llms_stripe_cc_number',
		'label' => __( 'Card Number', 'lifterlms-stripe' ),
		'last_column' => true,
		'max_length' => 19,
		'name' => false,
		'placeholder' => '•••• •••• •••• ••••',
		'required' => true,
		'type'  => 'tel',
		'wrapper_classes' => 'llms-stripe-cc-number',
	) );

	llms_form_field( array(
		'columns' => 6,
		'disabled' => $selected ? false : true,
		'id' => 'llms_stripe_expiration',
		'label' => __( 'Expiration', 'lifterlms-stripe' ),
		'last_column' => false,
		'max_length' => 9,
		'name' => false,
		'placeholder' => __( 'MM / YY', 'lifterlms-stripe' ),
		'required' => true,
		'type'  => 'tel',
	) );

	llms_form_field( array(
		'columns' => 6,
		'disabled' => $selected ? false : true,
		'id' => 'llms_stripe_cvc',
		'label' => __( 'CVC', 'lifterlms-stripe' ),
		'last_column' => true,
		'max_length' => 4,
		'name' => false,
		'required' => true,
		'type'  => 'tel',
	) );

echo '</section>';

llms_form_field( array(
	'disabled' => $selected ? false : true,
	'id' => 'llms_stripe_token',
	'type'  => 'hidden',
) );

llms_form_field( array(
	'disabled' => $selected ? false : true,
	'id' => 'llms_stripe_card_id',
	'type'  => 'hidden',
) );

do_action( 'lifterlms_after_checkout_cc_form' );
