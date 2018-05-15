( function( $ ) {

	var llms_stripe = window.llms_stripe || {};
	Stripe.setPublishableKey( llms_stripe.publishable_key );

	/**
	 * Bind DOM events
	 * @return   void
	 * @since    4.0.0
	 * @version  4.2.0
	 */
	llms_stripe.bind = function() {

		var self = this,
			allowed = [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ' ' ];

		// add token creation & cc validation to core before submit validations
		window.llms.checkout.add_before_submit_event( {
			data: self,
			handler: self.submit,
		} );

		// only allow numbers & spaces in all stripe fields
		$( '#llms_stripe_cc_number, #llms_stripe_expiration, #llms_stripe_expiration' ).on( 'keypress', function( e ) {
			if ( -1 === allowed.indexOf( String.fromCharCode( e.which || e.keyCode ) ) ) {
				return false;
			}
		} );

		// remove the slash & spaces and delete the second digit entered
		$( '#llms_stripe_expiration' ).on( 'keydown', function( e ) {

			var $el = $( this ),
				val = $el.val();

			if ( 5 === val.length && 8 === e.keyCode ) { // backspace
				$el.val( val.substr( 0, 2 ) );
			}

		} );

		// add a slash after second char is entered
		$( '#llms_stripe_expiration' ).on( 'keypress', function( e ) {

			var $el = $( this ),
				val = $el.val(),
				char = String.fromCharCode( e.which || e.keyCode );

			if ( 1 === val.length && -1 !== allowed.indexOf( char ) ) {
				$el.val( val + char + ' / ' );
				return false;
			}

		} );

		// validations
		$( '#llms_stripe_cc_number' ).on( 'focusout keyup', function() {
			$( this ).closest( '.llms-form-field' ).attr( 'data-brand', Stripe.card.cardType( $( this ).val() ) );
			self.validate( $( this ), 'validateCardNumber' );
		} );

		$( '#llms_stripe_expiration' ).on( 'focusout keyup', function( e ) {
			self.validate( $( this ), 'validateExpiry' );
		} );

		$( '#llms_stripe_cvc' ).on( 'focusout keyup', function() {
			self.validate( $( this ), 'validateCVC' );
		} );

		// saved card dropdown interactions
		$( '#llms_stripe_saved_card_id' ).on( 'change', function() {

			var card_id = $( this ).val(),
				$card_id = $( '#llms_stripe_card_id' ),
				$form = $( '.llms-stripe-cc-form' ),
				$fields = $form.find( 'input' );

			if ( 'create-new' === card_id ) {

				$card_id.val( '' );
				$form.slideDown( 200 );
				$fields.removeAttr( 'disabled' );

			} else {

				$card_id.val( card_id );
				$fields.attr( 'disabled', 'disabled' );
				$form.slideUp( 200 );

			}

		} );

		// trigger saved card field change on pageload
		$( '#llms_stripe_saved_card_id' ).trigger( 'change' );

		// when stripe is selected we should trigger a saved card change
		$( '.llms-payment-gateways' ).on( 'llms-gateway-selected', function( e, data ) {
			if ( 'stripe' === data.id ) {
				$( '#llms_stripe_saved_card_id' ).trigger( 'change' );
			}
		} );


		// prefill form with with test card data
		$( '#llms-stripe-autofill-cc' ).on( 'click', function() {

			$( '#llms_stripe_saved_card_id' ).val( 'create-new' ).trigger( 'change' );

			var date = new Date();

			$( '#llms_stripe_cc_number' ).val( $( '#llms-stripe-autofill-cc-card-number' ).val() ).trigger( 'keyup' );
			$( '#llms_stripe_expiration' ).val( '12 / ' + ( date.getFullYear() + 1 ) ).trigger( 'keyup' );
			$( '#llms_stripe_cvc' ).val( '123' ).trigger( 'keyup' );

		} );

	};

	/**
	 * Locate a translated error by code, fallback to unknown
	 * @param    string   code      error code key found in LLMS.l10n.strings
	 * @param    string   fallback  untranslated fallback to use when the code can't be found
	 *                              if no fallback and code can't be found falls back to "stripe-unknown" code
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	llms_stripe.get_error = function( code, fallback ) {

		var msg = ( LLMS.l10n.strings[ 'stripe-' + code ] ) ? LLMS.l10n.translate( 'stripe-' + code ) : fallback;

		if ( ! msg ) {
			LLMS.l10n.translate( 'stripe-unknown' );
		}

		return msg;

	};

	/**
	 * Retrieve a token using Stripe.js (ASYNC)
	 * @param    function  callback  callback function which 2 params
	 *                               success object
	 *                               error object
	 * @return   callback
	 * @since    3.0.0
	 * @version  3.0.0
	 */
	llms_stripe.get_token = function( callback ) {

		var token = $( '#llms_stripe_token' ).val() || '',
			card = {
				cvc: $( '#llms_stripe_cvc' ).val(),
				exp: $( '#llms_stripe_expiration' ).val(),
				number: $( '#llms_stripe_cc_number' ).val(),

				name: ( $( '#first_name' ).val() + ' ' + $( '#last_name' ).val() ).trim() || '',
				address_line1: $( '#llms_billing_address_1' ).val() || '',
				address_line2: $( '#llms_billing_address_2' ).val() || '',
				address_city: $( '#llms_billing_city' ).val() || '',
				address_state: $( '#llms_billing_state' ).val() || '',
				address_zip: $( '#llms_billing_zip' ).val() || '',
				address_country: $( '#llms_billing_country' ).val() || '',
			};

		Stripe.card.createToken( card, function( status, r ) {

			// we have a problem...
			if ( r.error ) {
				return callback( false, r.error );
			}

			// all good
			else if ( r.id ) {
				return callback( r );
			}

		} );

	};

	/**
	 * Determine if Stripe is selected on the list of available payment methods
	 * @return Boolean
	 * @since  2.0.0
	 * @version 4.0.0
	 */
	llms_stripe.is_selected = function() {

		// check the payment method radio element to see if it's checked
		return $( '#llms_payment_gateway_stripe' ).is( ':checked' );

	};

	/**
	 * Handle checkout submission to retrieve a token when Stripe is the selected gateway
	 * @return  void
	 * @since   4.0.0
	 * @version 4.1.1
	 */
	llms_stripe.submit = function( self, callback ) {

		var $saved = $( '#llms_stripe_saved_card_id' ),
			$form = $( this ),
			response = true;

		// don't proceed unless stripe is selected
		if( ! self.is_selected() ) {
			callback( response );
			return;
		}

		// skip if we're using a saved Card ID
		if ( $saved.length && 'create-new' !== $saved.val() ) {
			callback( response );
			return;
		}

		// get a token
		llms_stripe.get_token( function( token, err ) {

			// success
			if ( token && token.id && token.card && token.card.id ) {
				$( '#llms_stripe_token' ).val( token.id );
				$( '#llms_stripe_card_id' ).val( token.card.id )
			} else {
				// error
				response = self.get_error( err.code, err.message );
			}

			callback( response );
		} );

	};

	/**
	 * Validate Stripe a CC field using Stripe.js
	 * @param    obj     $el                  jQuery selector of input element to validate
	 * @param    string  validation_function  validation funtion to pass to Stripe.card
	 * @return   void
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	llms_stripe.validate = function( $el, validation_function ) {

		var $parent = $el.closest( '.llms-form-field' ),
			$fields = $parent.closest( '.llms-gateway-fields' ),
			$submit = $( '#llms_create_pending_order' ),
			val = $el.val(),
			valid = Stripe.card[ validation_function ]( val ),
			add_class, remove_class;

		if ( ! val.length ) {
			add_class = '';
			remove_class = 'valid invalid';
		} else if ( valid ) {
			add_class = 'valid';
			remove_class = 'invalid';
		} else {
			add_class = 'invalid';
			remove_class = 'valid';
		}

		$parent.removeClass( remove_class ).addClass( add_class );

		// handle form submit button state
		if ( $fields.find( '.invalid' ).length ) {
			$submit.attr( 'disabled', 'disabled' );
		} else {
			$submit.removeAttr( 'disabled' );
		}

	};

	// add this object to the core checkout class for future binds
	window.llms.checkout.add_gateway( llms_stripe );

	llms_stripe.bind();

} )(jQuery);
