<?php
/**
 * Customer on-hold order email with Persiano advance-order wording.
 *
 * @package Persiano_Hub
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$is_advance_request = class_exists( 'Persiano_Hub_Email_Branding' )
    && Persiano_Hub_Email_Branding::is_unconfirmed_advance_order( $order );
?>

<p><?php printf( esc_html__( 'Hi %s,', 'persiano-hub' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<?php if ( $is_advance_request ) : ?>
    <p><?php printf( esc_html__( 'We’ve received your advance-order request. It is currently pending confirmation from %s.', 'persiano-hub' ), esc_html( class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' ) ) ); ?></p>
    <p><?php esc_html_e( 'We’ll review the requested date and availability. Once the order is approved, we’ll send you an order confirmation with payment instructions or a secure payment link.', 'persiano-hub' ); ?></p>
    <p><?php esc_html_e( 'Here’s a summary of your request:', 'persiano-hub' ); ?></p>
<?php else : ?>
    <p><?php esc_html_e( 'We’ve received your order and it’s currently on hold until we can confirm your payment or cash arrangements.', 'persiano-hub' ); ?></p>
    <p><?php esc_html_e( 'Here’s a reminder of what you’ve ordered:', 'persiano-hub' ); ?></p>
<?php endif; ?>

<?php
/**
 * Order details, customer details and custom Persiano sections.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
