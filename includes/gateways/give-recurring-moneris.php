<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Give_Recurring_Moneris
 */
class Give_Recurring_Moneris extends Give_Recurring_Gateway {

	/**
	 * Call Give Moneris Invoice Class for processing recurring donations.
	 *
	 * @var $invoice
	 */
	public $invoice;

    public $access_token;
    public $store_id;

	/**
	 * Get Moneris Started.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function init() {
		$this->id = 'moneris';

		if (
			defined( 'GIVE_MONERIS_VERSION' ) &&
			version_compare( GIVE_MONERIS_VERSION, '1.0.1', '<' )
		) {
			add_action( 'admin_notices', array( $this, 'old_api_upgrade_notice' ) );

			// No Moneris SDK. Bounce.
			return false;
		}

		// Bailout, if gateway is not active.
		if ( ! give_is_gateway_active( $this->id ) ) {
			return;
		}

        $this->access_token = give_get_option( 'give_moneris_access_token' );
        $this->store_id     = give_get_option( 'give_moneris_store_id' );

        add_action( 'give_recurring_process_checkout', array( $this, 'process_recurring_checkout' ) );
	}

	/**
	 * Upgrade notice.
	 *
	 * Tells the admin that they need to upgrade the Moneris gateway.
	 *
	 * @since  1.9.0
	 * @access public
	 */
	public function old_api_upgrade_notice() {

		$message = sprintf(
			/* translators: 1. GiveWP account login page, 2. GiveWP Account downloads page */
			__( '<strong>Attention:</strong> The Recurring Donations plugin requires the latest version of the Moneris gateway add-on to process donations properly. Please update to the latest version of Moneris to resolve this issue. If your license is active you should see the update available in WordPress. Otherwise, you can access the latest version by <a href="%1$s" target="_blank">logging into your account</a> and visiting <a href="%1$s" target="_blank">your downloads</a> page on the Give website.', 'give-recurring' ),
			'https://givewp.com/wp-login.php',
			'https://givewp.com/my-account/#tab_downloads'
		);

		if ( class_exists( 'Give_Notices' ) ) {
			Give()->notices->register_notice(
				array(
					'id'          => 'give-activation-error',
					'type'        => 'error',
					'description' => $message,
					'show'        => true,
				)
			);
		} else {
			$class = 'notice notice-error';
			printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
		}
	}

	/**
	 * This function will be used to do all the heavy lifting for processing a donation payment.
	 *
	 * @param array $donation_data List of donation data.
	 *
	 * @return void
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function process_recurring_checkout( $donation_data ) {
		// Bailout, if the current gateway and the posted gateway mismatched.
		if ( $this->id !== $donation_data['gateway'] ) {
			return;
		}

		// Validate gateway nonce.
		give_validate_nonce( $donation_data['gateway_nonce'], 'give-gateway' );

		// Make sure we don't have any left over errors present.
		give_clear_errors();

		// Fields validation
        // @see https://developer.moneris.com/Documentation/NA/E-Commerce%20Solutions/API/Purchase?lang=php
        if ( !in_array($donation_data['period'], array('day', 'week', 'month')) ) {
            give_set_error( 'Moneris Error', __( 'Invalid recurring period. Valid options: day, week, month.', 'give-recurring' ) );
        }

		// Any errors?
		$errors = give_get_errors();

		// No errors, proceed.
		if ( ! $errors ) {

			$donation_amount = give_format_amount( $donation_data['price'] );

			$args = array(
				'price'           => $donation_amount,
				'give_form_title' => $donation_data['post_data']['give-form-title'],
				'give_form_id'    => intval( $donation_data['post_data']['give-form-id'] ),
				'give_price_id'   => isset( $donation_data['post_data']['give-price-id'] ) ? $donation_data['post_data']['give-price-id'] : '',
				'date'            => $donation_data['date'],
				'user_email'      => $donation_data['user_email'],
				'purchase_key'    => $donation_data['purchase_key'],
				'currency'        => give_get_currency( $donation_data['post_data']['give-form-id'], $donation_data ),
				'user_info'       => $donation_data['user_info'],
				'status'          => 'pending'
			);

			// Create a pending donation.
			$donation_id = give_insert_payment( $args );
			
			$exp_month   = sprintf( '%02d', $donation_data['card_info']['card_exp_month'] );
			$exp_year    = substr( $donation_data['card_info']['card_exp_year'], 2, 2 );
			$expiry_date = "{$exp_year}{$exp_month}";

            // according to the selected recurring period,
            // add dates to calculate the next billing date
            $start_date = new DateTime($donation_data['date']);
            $start_date->add(date_interval_create_from_date_string('1 ' . $donation_data['period']));

            $recurArray = array(
                'recur_unit'   => $donation_data['period'],     // (day | week | month)
                'start_date'   => $start_date->format('Y/m/d'), // yyyy/mm/dd
                'num_recurs'   => '99',                         // The number of times that the transaction must recur. Valid values: 1-99
                'start_now'    => 'true',                       // First billing will be placed now.
                'period'       => '1',                          // Number of recur units that must pass between recurring billings.
                'recur_amount' => $donation_amount
            );

            $mpgRecur = new Give_Moneris\mpgRecur($recurArray);

			$payment_object = array(
				'type'               => 'purchase',
				'order_id'           => give_moneris_get_unique_donation_id( $donation_id ),
				'cust_id'            => give_get_payment_donor_id( $donation_id ),
				'amount'             => give_format_decimal( array( 'amount' => $donation_data['price'] ) ),
				'pan'                => $donation_data['card_info']['card_number'],
				'expdate'            => $expiry_date,
				'crypt_type'         => 7, // @todo provide a filter to change the crypt type.
				'dynamic_descriptor' => give_moneris_get_statement_descriptor(),
			);

			$transaction_object = new Give_Moneris\mpgTransaction( $payment_object );
            $transaction_object->setRecur($mpgRecur);
			$request_object     = new Give_Moneris\mpgRequest( $transaction_object );
			$request_object->setProcCountryCode( give_get_option( 'base_country' ) );
			$request_object->setTestMode( give_is_test_mode() );

			$https_post_object = new Give_Moneris\mpgHttpsPost( $this->store_id, $this->access_token, $request_object );
			$response          = $https_post_object->getMpgResponse();

			// Prepare Response Variables.
			$response_code       = (int) $response->getResponseCode();
			$is_payment_complete = (bool) $response->getComplete();

			if ( $is_payment_complete & $response_code !== null ) {

				switch ( $response_code ) {

					case $response_code <= 29:

						// Save Transaction ID to Donation.
						$transaction_id = $response->getTxnNumber();
						give_set_payment_transaction_id( $donation_id, $transaction_id );
						give_insert_payment_note( $donation_id, "Transaction ID: {$transaction_id}" );
						give_insert_payment_note( $donation_id, "Approval Code: {$response->getAuthCode()}" );

						if ( ! empty( $transaction_id ) ) {

							// Set status to completed.
							give_update_payment_status( $donation_id );

							// All done. Send to success page.
							give_send_to_success_page();
						}

						break;

					case $response_code >= 50 && $response_code <= 99:

						// Something went wrong outside of Moneris.
						give_record_gateway_error(
							__( 'Moneris Error', 'give-moneris' ),
							sprintf(
							/* translators: %s Exception error message. */
								__( 'The Moneris Gateway declined the donation with an error. Details: %s', 'give-moneris' ),
								$response->getMessage()
							)
						);

						// Set Error to notify donor.
						give_set_error( 'give_moneris_gateway_error', __( 'Payment Declined. Please try again.', 'give-moneris' ) );

						// Set status to failed.
						give_update_payment_status( $donation_id, 'failed' );

						// Send user back to checkout.
						give_send_back_to_checkout( '?payment-mode=moneris' );
						break;

					default:

						// Something went wrong outside of Moneris.
						give_record_gateway_error(
							__( 'Moneris Error', 'give-moneris' ),
							sprintf(
							/* translators: %s Exception error message. */
								__( 'The Moneris Gateway declined the donation with an error. Details: %s', 'give-moneris' ),
								$response->getMessage()
							)
						);

						// Set Error to notify donor.
						give_set_error( 'give_moneris_gateway_error', __( 'Payment Declined. Please try again.', 'give-moneris' ) );

						// Set status to failed.
						give_update_payment_status( $donation_id, 'failed' );

						// Send user back to checkout.
						give_send_back_to_checkout( '?payment-mode=moneris' );
						break;

				}

			} else {

				// Something went wrong outside of Moneris.
				give_record_gateway_error(
					__( 'Moneris Error', 'give-moneris' ),
					sprintf(
					/* translators: %s Exception error message. */
						__( 'The Moneris Gateway returned an error while processing a donation. Details: %s', 'give-moneris' ),
						$response->getMessage()
					)
				);

				// Set Error to notify donor.
				give_set_error( 'give_moneris_gateway_error', __( 'Incomplete Payment Recorded. Please try again.', 'give-moneris' ) );

				// Set status to failed.
				give_update_payment_status( $donation_id, 'failed' );

				// Send user back to checkout.
				give_send_back_to_checkout( '?payment-mode=moneris' );
			}
		}

	}

	/**
	 * This function is used to display billing details only when enabled.
	 *
	 * @param $form_id
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 */
	public function display_billing_details( $form_id ) {

		// Remove Address Fields if user has option enabled.
		if ( ! give_get_option( 'give_moneris_collect_billing_details' ) ) {
			remove_action( 'give_after_cc_fields', 'give_default_cc_address_fields' );
		}

		// Ensure CC field is in place properly.
		do_action( 'give_cc_form', $form_id );

	}
}

new Give_Recurring_Moneris();
