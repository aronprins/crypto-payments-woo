<?php
/**
 * CPW_Subscriptions - WooCommerce Subscriptions integration.
 *
 * Crypto payments are inherently manual: you cannot auto-charge a wallet.
 * This class forces every subscription created via this gateway to use
 * manual renewal. WCS then creates a pending renewal order and emails
 * the customer, who pays through the normal crypto checkout flow.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPW_Subscriptions {

	public function __construct() {
		// Flag new subscriptions as requiring manual renewal.
		add_action( 'woocommerce_checkout_subscription_created', [ $this, 'set_manual_renewal' ], 10, 3 );

		// Fallback: handle the scheduled payment hook if manual renewal is ever bypassed.
		add_action( 'woocommerce_scheduled_subscription_payment_crypto_payments', [ $this, 'process_scheduled_payment' ], 10, 2 );

		// Copy preferred crypto network from the parent order to the renewal order.
		add_action( 'wcs_renewal_order_created', [ $this, 'copy_payment_meta_to_renewal' ], 10, 2 );
	}

	/**
	 * Mark a new subscription as requiring manual renewal.
	 *
	 * Prevents WCS from attempting to auto-charge the customer via cron.
	 * Instead WCS will create a pending renewal order and send a payment
	 * reminder email when the next renewal is due.
	 *
	 * @param WC_Subscription $subscription  The newly created subscription.
	 * @param WC_Order        $order         The parent order.
	 * @param array           $recurring_cart The recurring cart data.
	 */
	public function set_manual_renewal( $subscription, $order, $recurring_cart ) {
		if ( $subscription->get_payment_method() !== 'crypto_payments' ) {
			return;
		}

		$subscription->set_requires_manual_renewal( true );
		$subscription->save();
	}

	/**
	 * Fallback handler for the WCS scheduled payment hook.
	 *
	 * Should not normally fire for subscriptions marked as manual renewal,
	 * but acts as a safety net if the manual renewal flag is cleared
	 * (e.g. via an admin-initiated payment method change).
	 *
	 * Sets the renewal order to pending and the subscription to on-hold
	 * so WCS sends the customer a payment reminder email.
	 *
	 * @param float    $amount_to_charge The renewal amount.
	 * @param WC_Order $renewal_order    The renewal order.
	 */
	public function process_scheduled_payment( $amount_to_charge, $renewal_order ) {
		$renewal_order->update_status(
			'pending',
			__( 'Awaiting manual crypto payment for subscription renewal.', 'crypto-payments-woo' )
		);

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order );
		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription->has_status( 'on-hold' ) ) {
				$subscription->update_status(
					'on-hold',
					__( 'Subscription on hold awaiting manual crypto payment.', 'crypto-payments-woo' )
				);
			}
		}
	}

	/**
	 * Copy the preferred crypto network metadata from the parent order to the
	 * renewal order so the admin order panel shows useful context before the
	 * customer has paid.
	 *
	 * @param WC_Order        $renewal_order The newly created renewal order.
	 * @param WC_Subscription $subscription  The subscription being renewed.
	 */
	public function copy_payment_meta_to_renewal( $renewal_order, $subscription ) {
		if ( $subscription->get_payment_method() !== 'crypto_payments' ) {
			return;
		}

		$parent_order = $subscription->get_parent();
		if ( ! $parent_order ) {
			return;
		}

		$network_id     = $parent_order->get_meta( '_cpw_network' );
		$wallet_address = $parent_order->get_meta( '_cpw_wallet_address' );

		if ( $network_id ) {
			$renewal_order->update_meta_data( '_cpw_network', $network_id );
		}
		if ( $wallet_address ) {
			$renewal_order->update_meta_data( '_cpw_wallet_address', $wallet_address );
		}

		$renewal_order->save();
	}
}
