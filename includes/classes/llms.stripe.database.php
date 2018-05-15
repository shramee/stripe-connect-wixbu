<?php
/**
 * Handle various database table installations and upgrades
 * @since  2.0.0
 * @version 4.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
class LLMS_Stripe_Database {


	public function __construct() {

		$this->maybe_install();

	}


	/**
	 * Create Stripe order tables
	 * @since 2.0.0
	 * @version 4.0.0
	 */
	public function install_tables() {

		global $wpdb;
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$table = "
			CREATE TABLE {$wpdb->prefix}lifterlms_stripe_plans (
			  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			  `product_id` bigint(20) DEFAULT NULL,
			  `plan_id` varchar(55) DEFAULT NULL,
			  `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  `initial_amount` bigint(20) DEFAULT NULL,
			  `recurring_amount` bigint(20) DEFAULT NULL,
			  `billing_period` varchar(55) DEFAULT NULL,
			  `billing_frequency` int(11) DEFAULT NULL,
			  `billing_cycles` int(11) DEFAULT '0',
			  `currency` varchar(3) DEFAULT NULL,
			  `test_mode` tinyint(1) DEFAULT '0',
			  PRIMARY KEY (`id`)
			) {$wpdb->get_charset_collate()};";

		dbDelta( $table );

	}

	/**
	 * Update option names for 4.0.0
	 * @return   void
	 * @since    4.0.0
	 * @version  4.0.0
	 */
	private function update_options() {

		global $wpdb;

		$options = array(
			'lifterlms_stripe_enabled' => 'llms_gateway_stripe_enabled',
			'lifterlms_gateway_stripe_test_mode_enabled' => 'llms_gateway_stripe_test_mode_enabled',
			'lifterlms_gateway_stripe_debug_enabled' => 'llms_gateway_stripe_logging_enabled',

			// keys
			'lifterlms_gateway_stripe_test_secret_key' => 'llms_gateway_stripe_test_secret_key',
			'lifterlms_gateway_stripe_test_publish_key' => 'llms_gateway_stripe_test_publishable_key',
			'lifterlms_gateway_stripe_secret_key' => 'llms_gateway_stripe_live_secret_key',
			'lifterlms_gateway_stripe_publish_key' => 'llms_gateway_stripe_live_publishable_key',
		);

		foreach ( $options as $old => $new ) {

			$update = $wpdb->query(
				"UPDATE {$wpdb->options} AS o
				 SET o.option_name = '{$new}'
			 	 WHERE o.option_name = '{$old}';"
			);

			if ( false === $update ) {
				return false;
			}

		}

		return true;

	}

	/**
	 * Check database version to see if a table installation is required
	 * @return void
	 * @since 2.0.0
	 * @version 4.0.0
	 */
	public function maybe_install() {

		$version = get_option( 'llms_stripe_db_version', 0 );

		if ( version_compare( '2.0.0', $version ) === 1 ) {

			$this->install_tables();
			if( ! $wpdb->last_error ) {

				update_option( 'llms_stripe_db_version', '2.0.0' );

			}

		}

		if ( $version && version_compare( $version, Wixbu_Stripe()->version, '<' ) ) {

			if ( $this->update_options() ) {

				update_option( 'llms_stripe_db_version', Wixbu_Stripe()->version );

			}

		}


	}

}
return new LLMS_Stripe_Database();
