<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Gateway_Persiano_Square_POS extends WC_Payment_Gateway {
    public function __construct() {
        $this->id = 'square_pos';
        $this->method_title = 'Persiano Square Tap to Pay';
        $this->method_description = 'Internal Batchly POS gateway for verified Square payments and refunds.';
        $this->has_fields = false;
        $this->supports = array( 'products', 'refunds' );
        $this->enabled = 'yes';
        $this->title = 'Square Tap to Pay';
    }
    public function is_available() { return false; }
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return new WP_Error( 'invalid_order', 'Order not found.' ); }
        $payment_id = $order->get_meta( '_persiano_square_payment_id' );
        if ( ! $payment_id ) { return new WP_Error( 'square_payment_missing', 'No verified Square payment ID is saved for this order.' ); }
        $amount = null === $amount ? $order->get_remaining_refund_amount() : (float) $amount;
        if ( $amount <= 0 ) { return new WP_Error( 'invalid_refund_amount', 'Refund amount must be greater than zero.' ); }
        $body = array(
            'idempotency_key' => substr( 'phub-' . $order_id . '-' . wp_generate_uuid4(), 0, 45 ),
            'amount_money' => array( 'amount' => (int) round( $amount * 100 ), 'currency' => $order->get_currency() ),
            'payment_id' => $payment_id,
            'reason' => sanitize_text_field( $reason ?: 'WooCommerce refund for order #' . $order->get_order_number() ),
        );
        $response = Persiano_Hub_Square_Payments::api_request( 'POST', '/v2/refunds', $body );
        if ( is_wp_error( $response ) ) {
            Persiano_Hub_Square_Payments::record_attempt( $order, 'refund_failed', array( 'message' => $response->get_error_message(), 'amount' => $amount ) );
            return $response;
        }
        $refund = $response['refund'] ?? array();
        $refund_id = sanitize_text_field( (string) ( $refund['id'] ?? '' ) );
        $status = strtolower( (string) ( $refund['status'] ?? 'pending' ) );
        $refunds = $order->get_meta( '_persiano_square_refunds', true );
        if ( ! is_array( $refunds ) ) { $refunds = array(); }
        $refunds[] = array( 'id' => $refund_id, 'status' => $status, 'amount' => $amount, 'time' => current_time( 'mysql' ) );
        $order->update_meta_data( '_persiano_square_refunds', $refunds );
        $order->update_meta_data( '_persiano_square_payment_status', 'completed' === $status ? ( $amount + $order->get_total_refunded() >= $order->get_total() ? 'refunded' : 'partially_refunded' ) : 'refund_pending' );
        $order->add_order_note( sprintf( 'Square refund submitted: %s %0.2f · Refund ID %s · Status %s.', $order->get_currency(), $amount, $refund_id, strtoupper( $status ) ) );
        $order->save();
        Persiano_Hub_Square_Payments::record_attempt( $order, 'completed' === $status ? 'refund_completed' : 'refund_pending', array( 'refund_id' => $refund_id, 'amount' => $amount ) );
        return true;
    }
}
