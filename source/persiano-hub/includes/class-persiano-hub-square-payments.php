<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Square payment verification, payment history, and WooCommerce refunds.
 */
class Persiano_Hub_Square_Payments {
    const OPTION = 'persiano_hub_frontend_pos_settings';
    const API_VERSION = '2026-07-15';

    public static function init() {
        if ( class_exists( 'WC_Payment_Gateway' ) && ! class_exists( 'WC_Gateway_Persiano_Square_POS' ) ) {
            require_once PERSIANO_HUB_PATH . 'includes/class-wc-gateway-persiano-square-pos.php';
        }
        add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ) );
        add_action( 'persiano_hub_square_retry_verify', array( __CLASS__, 'scheduled_verify' ), 10, 2 );
    }

    public static function register_gateway( $gateways ) {
        $gateways[] = 'WC_Gateway_Persiano_Square_POS';
        return $gateways;
    }


    public static function schedule_verification( $order_id, $transaction_id = '' ) {
        $delays = array( 5, 15, 45, 120 );
        foreach ( $delays as $delay ) {
            wp_schedule_single_event( time() + $delay, 'persiano_hub_square_retry_verify', array( absint( $order_id ), sanitize_text_field( $transaction_id ) ) );
        }
    }

    public static function scheduled_verify( $order_id, $transaction_id = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->is_paid() || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) { return; }
        $result = $transaction_id ? self::complete_order_from_transaction( $order, $transaction_id, '' ) : self::reconcile_order( $order );
        if ( is_wp_error( $result ) ) {
            $order->update_meta_data( '_persiano_square_payment_status', 'verification_pending' );
            $order->save();
        }
    }

    public static function derived_ledger_status( $order ) {
        if ( ! $order instanceof WC_Order ) { return 'pending'; }
        $refunded = (float) $order->get_total_refunded();
        $total = (float) $order->get_total();
        if ( $refunded > 0 ) {
            return $refunded + 0.0001 >= $total ? 'refunded' : 'partially_refunded';
        }
        $status = sanitize_key( $order->get_meta( '_persiano_square_payment_status' ) ?: '' );
        if ( in_array( $status, array( 'verification_started', 'approved', 'verification_pending' ), true ) ) { return 'verification_pending'; }
        if ( $order->is_paid() ) { return 'paid'; }
        if ( $order->has_status( 'cancelled' ) ) { return 'cancelled'; }
        if ( $order->has_status( 'failed' ) ) { return 'failed'; }
        return $status ?: 'pending';
    }

    public static function settings() {
        return wp_parse_args( get_option( self::OPTION, array() ), array(
            'square_app_id'       => '',
            'square_location_id'  => '',
            'square_access_token' => '',
            'currency'            => 'CAD',
        ) );
    }

    public static function has_token() {
        $s = self::settings();
        return ! empty( $s['square_access_token'] );
    }

    public static function api_request( $method, $path, $body = null ) {
        $s = self::settings();
        if ( empty( $s['square_access_token'] ) ) {
            return new WP_Error( 'square_token_missing', 'Square Production Access Token is not configured.' );
        }
        $args = array(
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => array(
                'Authorization'  => 'Bearer ' . trim( $s['square_access_token'] ),
                'Square-Version' => self::API_VERSION,
                'Content-Type'   => 'application/json',
                'Accept'         => 'application/json',
            ),
        );
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }
        $response = wp_remote_request( 'https://connect.squareup.com' . $path, $args );
        if ( is_wp_error( $response ) ) { return $response; }
        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) {
            $message = 'Square API request failed.';
            if ( ! empty( $json['errors'][0]['detail'] ) ) { $message = $json['errors'][0]['detail']; }
            return new WP_Error( 'square_api_error', $message, array( 'status' => $code, 'response' => $json ) );
        }
        return is_array( $json ) ? $json : array();
    }


    /** Find a recent completed Square card payment that belongs to this WooCommerce POS order. */
    public static function find_recent_payment_for_order( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return new WP_Error( 'invalid_order', 'WooCommerce order was not found.' );
        }
        $s = self::settings();
        $created = $order->get_date_created();
        $begin = $created ? clone $created : new WC_DateTime( 'now', new DateTimeZone( 'UTC' ) );
        $begin->modify( '-30 minutes' );
        $begin->setTimezone( new DateTimeZone( 'UTC' ) );
        $query = array(
            'begin_time' => $begin->format( 'Y-m-d\TH:i:s.000\Z' ),
            'sort_order' => 'DESC',
            'limit'      => 100,
        );
        if ( ! empty( $s['square_location_id'] ) ) {
            $query['location_id'] = $s['square_location_id'];
        }
        $response = self::api_request( 'GET', '/v2/payments?' . http_build_query( $query ) );
        if ( is_wp_error( $response ) ) { return $response; }

        $target_cents = (int) round( (float) $order->get_total() * 100 );
        $needle_full  = 'woocommerce order #' . strtolower( (string) $order->get_order_number() );
        $needle_num   = '#' . strtolower( (string) $order->get_order_number() );
        $possible = array();

        foreach ( (array) ( $response['payments'] ?? array() ) as $payment ) {
            if ( 'COMPLETED' !== strtoupper( (string) ( $payment['status'] ?? '' ) ) ) { continue; }
            $amount = (int) ( $payment['amount_money']['amount'] ?? 0 );
            $currency = strtoupper( (string) ( $payment['amount_money']['currency'] ?? '' ) );
            if ( abs( $amount - $target_cents ) > 1 ) { continue; }
            if ( $currency && $currency !== strtoupper( $order->get_currency() ) ) { continue; }
            $payment_id = sanitize_text_field( (string) ( $payment['id'] ?? '' ) );
            if ( ! $payment_id ) { continue; }

            // Never attach a Square payment that is already assigned to another order.
            $already = wc_get_orders( array(
                'limit'      => 1,
                'return'     => 'ids',
                'exclude'    => array( $order->get_id() ),
                'meta_key'   => '_persiano_square_payment_id',
                'meta_value' => $payment_id,
            ) );
            if ( $already ) { continue; }

            $haystack = strtolower( wp_json_encode( $payment ) );
            $order_id = sanitize_text_field( (string) ( $payment['order_id'] ?? '' ) );
            $square_order = array();
            if ( $order_id ) {
                $order_response = self::api_request( 'GET', '/v2/orders/' . rawurlencode( $order_id ) );
                if ( ! is_wp_error( $order_response ) ) {
                    $square_order = $order_response['order'] ?? array();
                    $haystack .= ' ' . strtolower( wp_json_encode( $square_order ) );
                }
            }
            if ( false !== strpos( $haystack, $needle_full ) || false !== strpos( $haystack, $needle_num ) ) {
                return array( 'payment' => $payment, 'payment_id' => $payment_id, 'square_order' => $square_order, 'transaction_id' => $order_id ?: $payment_id );
            }
        }

        // Never infer a match from amount or time. An exact WooCommerce order reference is mandatory.
        return new WP_Error( 'square_payment_not_found', 'No completed Square payment with this exact WooCommerce order number was found. Amount-only matching is disabled for safety.' );
    }

    public static function complete_order_from_resolved( $order, $resolved, $client_transaction_id = '' ) {
        if ( ! $order instanceof WC_Order ) { return new WP_Error( 'invalid_order', 'WooCommerce order was not found.' ); }
        $payment = $resolved['payment'] ?? array();
        $square_order = $resolved['square_order'] ?? array();
        $payment_id = sanitize_text_field( (string) ( $resolved['payment_id'] ?? ( $payment['id'] ?? '' ) ) );
        $transaction_id = sanitize_text_field( (string) ( $resolved['transaction_id'] ?? ( $payment['order_id'] ?? $payment_id ) ) );
        if ( ! $payment_id || ! is_array( $payment ) ) { return new WP_Error( 'square_payment_missing', 'Square did not return payment details.' ); }
        $status = strtoupper( (string) ( $payment['status'] ?? '' ) );
        if ( 'COMPLETED' !== $status ) { return new WP_Error( 'square_not_completed', 'Square payment status is ' . ( $status ?: 'unknown' ) . '.' ); }

        $reference_haystack = strtolower( wp_json_encode( array( 'payment' => $payment, 'order' => $square_order ) ) );
        $order_number = strtolower( (string) $order->get_order_number() );
        $exact_full = 'woocommerce order #' . $order_number;
        $exact_hash = '#' . $order_number;
        if ( false === strpos( $reference_haystack, $exact_full ) && false === strpos( $reference_haystack, $exact_hash ) ) {
            return new WP_Error( 'square_order_reference_mismatch', 'Square payment does not contain the exact WooCommerce order number. It was not attached.' );
        }

        $s = self::settings();
        $currency = strtoupper( (string) ( $payment['amount_money']['currency'] ?? $payment['total_money']['currency'] ?? '' ) );
        $paid_cents = (int) ( $payment['amount_money']['amount'] ?? $payment['total_money']['amount'] ?? 0 );
        $location_id = (string) ( $payment['location_id'] ?? $square_order['location_id'] ?? '' );
        if ( $currency && strtoupper( $order->get_currency() ) !== $currency ) { return new WP_Error( 'square_currency_mismatch', 'Square currency does not match the WooCommerce order.' ); }
        if ( ! empty( $s['square_location_id'] ) && $location_id && $location_id !== $s['square_location_id'] ) { return new WP_Error( 'square_location_mismatch', 'Square location does not match the configured POS location.' ); }

        $tip_cents = (int) ( $square_order['total_tip_money']['amount'] ?? $square_order['net_amounts']['tip_money']['amount'] ?? 0 );
        $existing_tip = (int) $order->get_meta( '_persiano_square_tip_cents', true );
        if ( $tip_cents > 0 && $existing_tip <= 0 ) {
            $fee = new WC_Order_Item_Fee(); $fee->set_name( 'Tip' ); $fee->set_amount( $tip_cents / 100 ); $fee->set_total( $tip_cents / 100 ); $fee->set_tax_status( 'none' ); $order->add_item( $fee );
            $order->update_meta_data( '_persiano_square_tip_cents', $tip_cents ); $order->calculate_totals( false );
        }
        $order_total_cents = (int) round( (float) $order->get_total() * 100 );
        if ( $paid_cents > 0 && abs( $paid_cents - $order_total_cents ) > 1 ) {
            $order->add_order_note( sprintf( 'Square paid amount (%s %0.2f) differs from WooCommerce total (%s %0.2f). Review required.', $currency, $paid_cents / 100, $order->get_currency(), $order_total_cents / 100 ) );
            $order->update_meta_data( '_persiano_square_amount_mismatch', 'yes' );
        } else { $order->delete_meta_data( '_persiano_square_amount_mismatch' ); }

        $card = $payment['card_details']['card'] ?? array();
        $processing_fee = 0;
        foreach ( (array) ( $payment['processing_fee'] ?? array() ) as $fee_row ) { $processing_fee += (int) ( $fee_row['amount_money']['amount'] ?? 0 ); }
        $order->update_meta_data( '_persiano_square_transaction_id', $transaction_id );
        $order->update_meta_data( '_persiano_square_client_transaction_id', sanitize_text_field( $client_transaction_id ) );
        $order->update_meta_data( '_persiano_square_payment_id', $payment_id );
        $order->update_meta_data( '_persiano_square_order_id', sanitize_text_field( (string) ( $payment['order_id'] ?? $square_order['id'] ?? $transaction_id ) ) );
        $order->update_meta_data( '_persiano_square_receipt_url', esc_url_raw( (string) ( $payment['receipt_url'] ?? '' ) ) );
        $order->update_meta_data( '_persiano_square_card_brand', sanitize_text_field( (string) ( $card['card_brand'] ?? '' ) ) );
        $order->update_meta_data( '_persiano_square_card_last4', sanitize_text_field( (string) ( $card['last_4'] ?? '' ) ) );
        $order->update_meta_data( '_persiano_square_processing_fee_cents', $processing_fee );
        $order->update_meta_data( '_persiano_square_paid_cents', $paid_cents );
        $order->update_meta_data( '_persiano_square_callback_received', current_time( 'mysql' ) );
        $order->set_payment_method( 'square_pos' ); $order->set_payment_method_title( 'Square Tap to Pay' ); $order->save();
        if ( ! $order->is_paid() ) { $order->payment_complete( $payment_id ); }
        $order->add_order_note( 'Square Tap to Pay verified and completed. Payment ID: ' . $payment_id . ( $card ? ' · ' . ( $card['card_brand'] ?? 'Card' ) . ' •••• ' . ( $card['last_4'] ?? '' ) : '' ) );
        self::record_attempt( $order, 'paid', array( 'payment_id' => $payment_id, 'transaction_id' => $transaction_id, 'amount_cents' => $paid_cents, 'tip_cents' => $tip_cents ) );
        return $resolved;
    }

    /** Resolve a POS API transaction/order ID into the Square Order and Payment. */
    public static function resolve_transaction( $transaction_id ) {
        $transaction_id = sanitize_text_field( $transaction_id );
        $order_response = self::api_request( 'GET', '/v2/orders/' . rawurlencode( $transaction_id ) );
        if ( is_wp_error( $order_response ) ) { return $order_response; }
        $square_order = $order_response['order'] ?? ( $order_response['orders'][0] ?? null );
        if ( ! is_array( $square_order ) ) {
            return new WP_Error( 'square_order_missing', 'Square did not return the completed POS order.' );
        }
        $payment_id = '';
        foreach ( (array) ( $square_order['tenders'] ?? array() ) as $tender ) {
            if ( ! empty( $tender['payment_id'] ) ) { $payment_id = (string) $tender['payment_id']; break; }
            if ( ! empty( $tender['id'] ) ) { $payment_id = (string) $tender['id']; break; }
        }
        if ( ! $payment_id ) {
            return new WP_Error( 'square_payment_missing', 'Square order has no payment ID yet. Try Verify payment again in a few seconds.' );
        }
        $payment_response = self::api_request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ) );
        if ( is_wp_error( $payment_response ) ) { return $payment_response; }
        $payment = $payment_response['payment'] ?? null;
        if ( ! is_array( $payment ) ) {
            return new WP_Error( 'square_payment_missing', 'Square did not return payment details.' );
        }
        return array( 'square_order' => $square_order, 'payment' => $payment, 'payment_id' => $payment_id );
    }

    public static function record_attempt( $order, $status, $data = array() ) {
        if ( ! $order instanceof WC_Order ) { return; }
        $attempts = $order->get_meta( '_persiano_square_attempts', true );
        if ( ! is_array( $attempts ) ) { $attempts = array(); }
        $attempts[] = array_merge( array(
            'time'   => current_time( 'mysql' ),
            'status' => sanitize_key( $status ),
        ), array_map( static function( $value ) {
            return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
        }, $data ) );
        $order->update_meta_data( '_persiano_square_attempts', array_slice( $attempts, -30 ) );
        $order->update_meta_data( '_persiano_square_payment_status', sanitize_key( $status ) );
        $order->save();
    }

    public static function complete_order_from_transaction( $order, $transaction_id, $client_transaction_id = '' ) {
        if ( ! $order instanceof WC_Order ) { return new WP_Error( 'invalid_order', 'WooCommerce order was not found.' ); }
        self::record_attempt( $order, 'verification_started', array( 'transaction_id' => $transaction_id ) );
        $resolved = self::resolve_transaction( $transaction_id );
        if ( is_wp_error( $resolved ) ) {
            self::record_attempt( $order, 'verification_error', array( 'transaction_id' => $transaction_id, 'message' => $resolved->get_error_message() ) );
            return $resolved;
        }
        $resolved['transaction_id'] = $transaction_id;
        $result = self::complete_order_from_resolved( $order, $resolved, $client_transaction_id );
        if ( is_wp_error( $result ) ) { self::record_attempt( $order, 'verification_error', array( 'transaction_id' => $transaction_id, 'message' => $result->get_error_message() ) ); }
        return $result;
    }

    public static function reconcile_order( $order ) {
        if ( ! $order instanceof WC_Order ) { return new WP_Error( 'invalid_order', 'WooCommerce order was not found.' ); }
        if ( $order->is_paid() && $order->get_meta( '_persiano_square_payment_id' ) ) { return array( 'already_paid' => true ); }
        $resolved = self::find_recent_payment_for_order( $order );
        if ( is_wp_error( $resolved ) ) {
            // A card order can legitimately have no Square payment yet. Treat the
            // normal "not paid yet / not visible yet" responses as pending rather
            // than poisoning the ledger with a verification error before the
            // customer has even tapped their card.
            $code = $resolved->get_error_code();
            if ( in_array( $code, array( 'square_payment_not_found', 'square_order_missing', 'square_payment_missing' ), true ) ) {
                $current = sanitize_key( (string) $order->get_meta( '_persiano_square_payment_status', true ) );
                if ( ! in_array( $current, array( 'verification_pending', 'approved' ), true ) ) {
                    $order->update_meta_data( '_persiano_square_payment_status', 'pending' );
                    $order->save();
                }
                return $resolved;
            }
            self::record_attempt( $order, 'verification_error', array( 'message' => $resolved->get_error_message() ) );
            return $resolved;
        }
        $result = self::complete_order_from_resolved( $order, $resolved, '' );
        if ( is_wp_error( $result ) ) { self::record_attempt( $order, 'verification_error', array( 'message' => $result->get_error_message() ) ); }
        return $result;
    }

    public static function add_order_meta_box() {
        $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
        add_meta_box( 'persiano-square-payment-history', 'Persiano Square Payment', array( __CLASS__, 'render_order_meta_box' ), $screen, 'side', 'default' );
    }

    public static function render_order_meta_box( $post_or_order ) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : $post_or_order );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) { echo '<p>Not a Persiano POS order.</p>'; return; }
        $payment_id = $order->get_meta( '_persiano_square_payment_id' );
        $status = $order->get_meta( '_persiano_square_payment_status' ) ?: 'created';
        echo '<p><strong>Status:</strong> ' . esc_html( ucwords( str_replace( '_', ' ', $status ) ) ) . '</p>';
        if ( $payment_id ) { echo '<p><strong>Payment ID:</strong><br><code style="word-break:break-all">' . esc_html( $payment_id ) . '</code></p>'; }
        $brand = $order->get_meta( '_persiano_square_card_brand' ); $last4 = $order->get_meta( '_persiano_square_card_last4' );
        if ( $brand || $last4 ) { echo '<p><strong>Card:</strong> ' . esc_html( trim( $brand . ' •••• ' . $last4 ) ) . '</p>'; }
        $receipt = $order->get_meta( '_persiano_square_receipt_url' );
        if ( $receipt ) { echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( $receipt ) . '">Square receipt</a></p>'; }
        $attempts = $order->get_meta( '_persiano_square_attempts', true );
        if ( is_array( $attempts ) && $attempts ) {
            echo '<details><summary>Payment history (' . count( $attempts ) . ')</summary><ol style="margin-left:18px">';
            foreach ( array_reverse( $attempts ) as $attempt ) {
                echo '<li><strong>' . esc_html( ucwords( str_replace( '_', ' ', $attempt['status'] ?? '' ) ) ) . '</strong><br><small>' . esc_html( $attempt['time'] ?? '' ) . ( ! empty( $attempt['message'] ) ? ' · ' . $attempt['message'] : '' ) . '</small></li>';
            }
            echo '</ol></details>';
        }
    }
}
