<?php
/**
 * Plain-text customer on-hold order email with Persiano advance-order wording.
 *
 * @package Persiano_Hub
 */

defined( 'ABSPATH' ) || exit;

$is_advance_request = class_exists( 'Persiano_Hub_Email_Branding' )
    && Persiano_Hub_Email_Branding::is_unconfirmed_advance_order( $order );

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_strip_all_tags( $email_heading );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
printf( esc_html__( 'Hi %s,', 'persiano-hub' ), esc_html( $order->get_billing_first_name() ) );
echo "\n\n";

if ( $is_advance_request ) {
    printf( esc_html__( 'We’ve received your advance-order request. It is currently pending confirmation from %s.', 'persiano-hub' ), esc_html( class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' ) ) );
    echo "\n\n";
    echo esc_html__( 'We’ll review the requested date and availability. Once the order is approved, we’ll send you an order confirmation with payment instructions or a secure payment link.', 'persiano-hub' ) . "\n\n";
    echo esc_html__( 'Here’s a summary of your request:', 'persiano-hub' ) . "\n\n";
} else {
    echo esc_html__( 'We’ve received your order and it’s currently on hold until we can confirm your payment or cash arrangements.', 'persiano-hub' ) . "\n\n";
    echo esc_html__( 'Here’s a reminder of what you’ve ordered:', 'persiano-hub' ) . "\n\n";
}

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
    echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
