<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** True Web Push for installed Batchly Home Screen apps. */
class Persiano_Hub_Web_Push {
    const SUBSCRIPTIONS = 'persiano_hub_push_subscriptions';
    const PRIVATE_KEY   = 'persiano_hub_vapid_private_key';
    const PUBLIC_KEY    = 'persiano_hub_vapid_public_key';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
    }

    private static function b64url( $data ) { return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); }
    private static function b64url_decode( $data ) { return base64_decode( strtr( $data . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 ), '-_', '+/' ) ); }

    private static function ensure_keys() {
        $private = get_option( self::PRIVATE_KEY, '' );
        $public  = get_option( self::PUBLIC_KEY, '' );
        if ( $private && $public ) { return array( $private, $public ); }
        if ( ! function_exists( 'openssl_pkey_new' ) ) { return new WP_Error( 'openssl_missing', 'PHP OpenSSL is required for Web Push.' ); }
        $resource = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
        if ( ! $resource ) { return new WP_Error( 'vapid_key_failed', 'Could not generate VAPID keys.' ); }
        openssl_pkey_export( $resource, $private );
        $details = openssl_pkey_get_details( $resource );
        if ( empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) { return new WP_Error( 'vapid_public_failed', 'Could not read the VAPID public key.' ); }
        $public = self::b64url( "\x04" . $details['ec']['x'] . $details['ec']['y'] );
        update_option( self::PRIVATE_KEY, $private, false );
        update_option( self::PUBLIC_KEY, $public, false );
        return array( $private, $public );
    }

    public static function public_key() {
        $keys = self::ensure_keys();
        return is_wp_error( $keys ) ? '' : $keys[1];
    }

    public static function ajax_subscribe() {
        Persiano_Hub_Frontend_POS::ajax_guard();
        $raw = json_decode( wp_unslash( $_POST['subscription'] ?? '' ), true );
        if ( empty( $raw['endpoint'] ) || empty( $raw['keys']['p256dh'] ) || empty( $raw['keys']['auth'] ) ) { wp_send_json_error( array( 'message' => 'The browser did not return a valid push subscription.' ), 400 ); }
        $endpoint = esc_url_raw( $raw['endpoint'] );
        $hash = hash( 'sha256', $endpoint );
        $subs = get_option( self::SUBSCRIPTIONS, array() );
        if ( ! is_array( $subs ) ) { $subs = array(); }
        $subs[ $hash ] = array(
            'endpoint' => $endpoint,
            'p256dh' => sanitize_text_field( $raw['keys']['p256dh'] ),
            'auth' => sanitize_text_field( $raw['keys']['auth'] ),
            'user_id' => get_current_user_id(),
            'created' => time(),
            'last_seen' => time(),
            'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
        );
        update_option( self::SUBSCRIPTIONS, $subs, false );
        wp_send_json_success( array( 'hash' => $hash ) );
    }

    public static function ajax_unsubscribe() {
        Persiano_Hub_Frontend_POS::ajax_guard();
        $endpoint = esc_url_raw( wp_unslash( $_POST['endpoint'] ?? '' ) );
        $hash = hash( 'sha256', $endpoint );
        $subs = get_option( self::SUBSCRIPTIONS, array() );
        unset( $subs[ $hash ] ); update_option( self::SUBSCRIPTIONS, $subs, false );
        wp_send_json_success();
    }

    public static function ajax_test() {
        Persiano_Hub_Frontend_POS::ajax_guard();
        $subs = self::subscriptions_for_user( get_current_user_id() );
        if ( ! $subs ) { wp_send_json_error( array( 'message' => 'No subscribed devices were found for this user.' ), 404 ); }
        $ok = 0;
        foreach ( $subs as $hash => $sub ) {
            set_transient( 'phub_push_' . $hash, array( 'title' => 'Batchly test', 'body' => 'Background notifications are working.', 'url' => home_url( '/hub/' ), 'tag' => 'phub-test' ), 300 );
            if ( true === self::send_empty_push( $sub ) ) { $ok++; }
        }
        wp_send_json_success( array( 'message' => sprintf( 'Test push sent to %d device(s).', $ok ) ) );
    }

    private static function subscriptions_for_user( $user_id = 0 ) {
        $subs = get_option( self::SUBSCRIPTIONS, array() );
        if ( ! is_array( $subs ) ) { return array(); }
        if ( ! $user_id ) { return $subs; }
        return array_filter( $subs, static function( $sub ) use ( $user_id ) { return (int) ( $sub['user_id'] ?? 0 ) === (int) $user_id; } );
    }

    public static function send_payment_ready( WC_Order $order ) {
        $settings = get_option( Persiano_Hub_Frontend_POS::OPTION, array() );
        if ( 'no' === ( $settings['web_push_enabled'] ?? 'yes' ) ) { return; }
        $subs = self::subscriptions_for_user();
        if ( ! $subs ) { return; }
        $sent = $order->get_meta( '_persiano_web_push_sent', true );
        if ( ! is_array( $sent ) ) { $sent = array(); }
        foreach ( $subs as $hash => $sub ) {
            if ( in_array( $hash, $sent, true ) ) { continue; }
            $payload = array(
                'title' => 'Payment ready — ' . wp_strip_all_tags( $order->get_formatted_order_total() ),
                'body'  => 'Order #' . $order->get_order_number() . ' · ' . $order->get_item_count() . ' item' . ( 1 === $order->get_item_count() ? '' : 's' ) . ' · ' . ( $order->get_formatted_billing_full_name() ?: 'Guest' ),
                'url'   => home_url( '/hub/pay/' . $order->get_id() . '/' ),
                'tag'   => 'phub-payment-' . $order->get_id(),
            );
            set_transient( 'phub_push_' . $hash, $payload, DAY_IN_SECONDS );
            $result = self::send_empty_push( $sub );
            if ( true === $result ) { $sent[] = $hash; }
        }
        $order->update_meta_data( '_persiano_web_push_sent', array_values( array_unique( $sent ) ) );
        $order->save();
    }

    private static function audience( $endpoint ) {
        $parts = wp_parse_url( $endpoint );
        return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
    }

    private static function der_to_raw( $der, $size = 32 ) {
        $pos = 2;
        if ( ord( $der[1] ) & 0x80 ) { $len = ord( $der[1] ) & 0x7f; $pos = 2 + $len; }
        if ( ord( $der[$pos] ) !== 0x02 ) { return false; }
        $rlen = ord( $der[++$pos] ); $r = substr( $der, ++$pos, $rlen ); $pos += $rlen;
        if ( ord( $der[$pos] ) !== 0x02 ) { return false; }
        $slen = ord( $der[++$pos] ); $ss = substr( $der, ++$pos, $slen );
        $r = str_pad( ltrim( $r, "\0" ), $size, "\0", STR_PAD_LEFT );
        $ss = str_pad( ltrim( $ss, "\0" ), $size, "\0", STR_PAD_LEFT );
        return substr( $r, -$size ) . substr( $ss, -$size );
    }

    private static function send_empty_push( $sub ) {
        $keys = self::ensure_keys();
        if ( is_wp_error( $keys ) ) { return $keys; }
        list( $private, $public ) = $keys;
        $header = self::b64url( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
        $claims = self::b64url( wp_json_encode( array( 'aud' => self::audience( $sub['endpoint'] ), 'exp' => time() + 43200, 'sub' => 'mailto:' . sanitize_email( get_option( 'admin_email' ) ) ) ) );
        $input = $header . '.' . $claims;
        if ( ! openssl_sign( $input, $der, $private, OPENSSL_ALGO_SHA256 ) ) { return new WP_Error( 'vapid_sign_failed', 'Could not sign Web Push request.' ); }
        $raw = self::der_to_raw( $der );
        if ( ! $raw ) { return new WP_Error( 'vapid_signature_failed', 'Could not encode Web Push signature.' ); }
        $jwt = $input . '.' . self::b64url( $raw );
        $response = wp_remote_post( $sub['endpoint'], array(
            'timeout' => 20,
            'headers' => array(
                'TTL' => '60', 'Urgency' => 'high',
                'Authorization' => 'vapid t=' . $jwt . ', k=' . $public,
                'Crypto-Key' => 'p256ecdsa=' . $public,
                'Content-Length' => '0',
            ),
            'body' => '',
        ) );
        if ( is_wp_error( $response ) ) { return $response; }
        $code = wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, array( 201, 202 ), true ) ) { return true; }
        if ( in_array( $code, array( 404, 410 ), true ) ) {
            $subs = get_option( self::SUBSCRIPTIONS, array() );
            unset( $subs[ hash( 'sha256', $sub['endpoint'] ) ] ); update_option( self::SUBSCRIPTIONS, $subs, false );
        }
        return new WP_Error( 'push_failed', 'Push service returned HTTP ' . $code . '.' );
    }

    public static function rest_routes() {
        register_rest_route( 'persiano-hub/v1', '/push-latest', array(
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => static function( WP_REST_Request $request ) {
                $hash = sanitize_text_field( $request->get_param( 'subscription' ) );
                if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) { return new WP_Error( 'invalid_subscription', 'Invalid subscription.', array( 'status' => 400 ) ); }
                $data = get_transient( 'phub_push_' . $hash );
                return rest_ensure_response( is_array( $data ) ? $data : array( 'title' => 'Batchly', 'body' => 'A payment is ready.', 'url' => home_url( '/hub/payments/' ), 'tag' => 'phub-payment' ) );
            },
        ) );
    }
}
