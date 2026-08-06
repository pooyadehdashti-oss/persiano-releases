<?php
/**
 * Square transaction workspace, live synchronization, webhooks and safe refunds.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Square_Transactions {
    const PAGE            = 'persiano-hub-square-transactions';
    const OPTION_CACHE    = 'persiano_hub_square_transactions_cache';
    const OPTION_SYNCED   = 'persiano_hub_square_transactions_synced';
    const OPTION_EVENTS   = 'persiano_hub_square_webhook_events';
    const REST_NAMESPACE  = 'persiano-hub/v1';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 83 );
        add_action( 'admin_post_persiano_hub_square_sync', array( __CLASS__, 'handle_sync' ) );
        add_action( 'admin_post_persiano_hub_square_refund', array( __CLASS__, 'handle_refund' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );
    }

    public static function register_page() {
        add_submenu_page(
            'persiano-hub',
            __( 'Square Transactions', 'persiano-hub' ),
            __( 'Square Transactions', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function register_rest_route() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/square/webhook',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'receive_webhook' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    private static function pos_settings() {
        $settings = get_option( Persiano_Hub_Square_Payments::OPTION, array() );
        $settings = is_array( $settings ) ? $settings : array();
        if ( empty( $settings['square_webhook_token'] ) ) {
            $settings['square_webhook_token'] = wp_generate_password( 32, false, false );
            update_option( Persiano_Hub_Square_Payments::OPTION, $settings, false );
        }
        return wp_parse_args( $settings, array( 'square_webhook_token' => '', 'square_webhook_signature_key' => '', 'square_location_id' => '' ) );
    }

    public static function webhook_url() {
        $s = self::pos_settings();
        return add_query_arg( 'token', rawurlencode( $s['square_webhook_token'] ), rest_url( self::REST_NAMESPACE . '/square/webhook' ) );
    }

    private static function money( $money ) {
        return array(
            'amount'   => (int) ( $money['amount'] ?? 0 ),
            'currency' => sanitize_text_field( $money['currency'] ?? 'CAD' ),
        );
    }

    private static function normalize_payment( $payment ) {
        $card = $payment['card_details']['card'] ?? array();
        $fees = 0;
        foreach ( (array) ( $payment['processing_fee'] ?? array() ) as $fee ) { $fees += (int) ( $fee['amount_money']['amount'] ?? 0 ); }
        return array(
            'id'             => sanitize_text_field( $payment['id'] ?? '' ),
            'order_id'       => sanitize_text_field( $payment['order_id'] ?? '' ),
            'status'         => strtoupper( sanitize_text_field( $payment['status'] ?? '' ) ),
            'created_at'     => sanitize_text_field( $payment['created_at'] ?? '' ),
            'updated_at'     => sanitize_text_field( $payment['updated_at'] ?? '' ),
            'amount_money'   => self::money( $payment['amount_money'] ?? array() ),
            'tip_money'      => self::money( $payment['tip_money'] ?? array() ),
            'refunded_money' => self::money( $payment['refunded_money'] ?? array() ),
            'processing_fee' => $fees,
            'receipt_url'    => esc_url_raw( $payment['receipt_url'] ?? '' ),
            'receipt_number' => sanitize_text_field( $payment['receipt_number'] ?? '' ),
            'source_type'    => sanitize_text_field( $payment['source_type'] ?? '' ),
            'card_brand'     => sanitize_text_field( $card['card_brand'] ?? '' ),
            'card_last4'     => sanitize_text_field( $card['last_4'] ?? '' ),
            'customer_id'    => sanitize_text_field( $payment['customer_id'] ?? '' ),
            'location_id'    => sanitize_text_field( $payment['location_id'] ?? '' ),
            'note'           => sanitize_text_field( $payment['note'] ?? '' ),
            'raw'            => $payment,
        );
    }

    private static function cache() {
        $cache = get_option( self::OPTION_CACHE, array() );
        return is_array( $cache ) ? $cache : array();
    }

    private static function save_cache( $payments ) {
        uasort( $payments, static function( $a, $b ) { return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ); } );
        update_option( self::OPTION_CACHE, array_slice( $payments, 0, 750, true ), false );
        update_option( self::OPTION_SYNCED, current_time( 'mysql' ), false );
    }

    private static function merge_payment( $payment ) {
        $normalized = self::normalize_payment( $payment );
        if ( ! $normalized['id'] ) { return; }
        $cache = self::cache();
        $cache[ $normalized['id'] ] = $normalized;
        self::save_cache( $cache );
    }

    private static function find_wc_order( $payment ) {
        $payment_id = sanitize_text_field( $payment['id'] ?? '' );
        $square_order_id = sanitize_text_field( $payment['order_id'] ?? '' );
        foreach ( array( '_persiano_square_payment_id' => $payment_id, '_persiano_square_order_id' => $square_order_id, '_persiano_square_transaction_id' => $square_order_id ) as $key => $value ) {
            if ( ! $value ) { continue; }
            $ids = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'meta_key' => $key, 'meta_value' => $value ) );
            if ( $ids ) { return wc_get_order( $ids[0] ); }
        }
        return false;
    }

    public static function sync( $days = 90 ) {
        if ( ! Persiano_Hub_Square_Payments::has_token() ) { return new WP_Error( 'square_token_missing', __( 'Square Production Access Token is not configured.', 'persiano-hub' ) ); }
        $days = max( 1, min( 365, absint( $days ) ) );
        $begin = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - DAY_IN_SECONDS * $days );
        $settings = self::pos_settings();
        $query = array( 'begin_time' => $begin, 'sort_order' => 'DESC', 'limit' => 100 );
        if ( $settings['square_location_id'] ) { $query['location_id'] = $settings['square_location_id']; }
        $cache = self::cache();
        $cursor = '';
        $count = 0;
        for ( $page = 0; $page < 8; $page++ ) {
            if ( $cursor ) { $query['cursor'] = $cursor; }
            $response = Persiano_Hub_Square_Payments::api_request( 'GET', '/v2/payments?' . http_build_query( $query ) );
            if ( is_wp_error( $response ) ) { return $response; }
            foreach ( (array) ( $response['payments'] ?? array() ) as $payment ) {
                $normalized = self::normalize_payment( $payment );
                if ( $normalized['id'] ) { $cache[ $normalized['id'] ] = $normalized; $count++; }
            }
            $cursor = sanitize_text_field( $response['cursor'] ?? '' );
            if ( ! $cursor ) { break; }
        }
        self::save_cache( $cache );
        return $count;
    }

    public static function handle_sync() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to sync Square transactions.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_square_sync' );
        $days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 90;
        $result = self::sync( $days );
        $args = array( 'page' => self::PAGE );
        if ( is_wp_error( $result ) ) { $args['ph_notice_type'] = 'error'; $args['ph_notice'] = rawurlencode( $result->get_error_message() ); }
        else { $args['ph_notice_type'] = 'success'; $args['ph_notice'] = rawurlencode( sprintf( __( 'Square sync completed. %d payment records were refreshed.', 'persiano-hub' ), $result ) ); }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_refund() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to refund payments.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_square_refund' );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $amount = (float) wc_format_decimal( wp_unslash( $_POST['amount'] ?? 0 ) );
        $reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
        $order = wc_get_order( $order_id );
        if ( ! $order || 'square_pos' !== $order->get_payment_method() ) {
            self::refund_redirect( 'error', __( 'A linked Square WooCommerce order is required.', 'persiano-hub' ) );
        }
        if ( $amount <= 0 || $amount > (float) $order->get_remaining_refund_amount() + 0.0001 ) {
            self::refund_redirect( 'error', __( 'Enter an amount no greater than the remaining refundable total.', 'persiano-hub' ), $order_id );
        }
        $result = wc_create_refund(
            array(
                'order_id'       => $order_id,
                'amount'         => $amount,
                'reason'         => $reason ?: sprintf( 'Square refund from Batchly for order #%s', $order->get_order_number() ),
                'refund_payment' => true,
                'restock_items'  => false,
            )
        );
        if ( is_wp_error( $result ) ) { self::refund_redirect( 'error', $result->get_error_message(), $order_id ); }
        self::sync( 30 );
        self::refund_redirect( 'success', sprintf( __( 'Refund of %1$s %2$.2f was submitted through Square.', 'persiano-hub' ), $order->get_currency(), $amount ), $order_id );
    }

    private static function refund_redirect( $type, $message, $order_id = 0 ) {
        $args = array( 'page' => self::PAGE, 'ph_notice_type' => sanitize_key( $type ), 'ph_notice' => rawurlencode( $message ) );
        if ( $order_id ) { $args['order_id'] = absint( $order_id ); }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function verify_webhook( WP_REST_Request $request ) {
        $settings = self::pos_settings();
        $token = sanitize_text_field( (string) $request->get_param( 'token' ) );
        if ( ! $settings['square_webhook_token'] || ! hash_equals( (string) $settings['square_webhook_token'], $token ) ) {
            return new WP_Error( 'invalid_square_webhook_token', 'Invalid webhook token.', array( 'status' => 403 ) );
        }
        if ( ! empty( $settings['square_webhook_signature_key'] ) ) {
            $signature = isset( $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ) ) : '';
            $expected = base64_encode( hash_hmac( 'sha256', self::webhook_url() . $request->get_body(), $settings['square_webhook_signature_key'], true ) );
            if ( ! $signature || ! hash_equals( $expected, $signature ) ) {
                return new WP_Error( 'invalid_square_signature', 'Invalid Square webhook signature.', array( 'status' => 403 ) );
            }
        }
        return true;
    }

    public static function receive_webhook( WP_REST_Request $request ) {
        $verified = self::verify_webhook( $request );
        if ( is_wp_error( $verified ) ) { return $verified; }
        $event = json_decode( $request->get_body(), true );
        if ( ! is_array( $event ) ) { return new WP_Error( 'invalid_square_event', 'Invalid JSON.', array( 'status' => 400 ) ); }
        $event_id = sanitize_text_field( $event['event_id'] ?? $event['id'] ?? '' );
        $seen = get_option( self::OPTION_EVENTS, array() );
        $seen = is_array( $seen ) ? $seen : array();
        if ( $event_id && isset( $seen[ $event_id ] ) ) { return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 ); }
        if ( $event_id ) {
            $seen[ $event_id ] = time();
            foreach ( $seen as $key => $when ) { if ( time() - (int) $when > 7 * DAY_IN_SECONDS ) { unset( $seen[ $key ] ); } }
            update_option( self::OPTION_EVENTS, array_slice( $seen, -500, null, true ), false );
        }
        $type = sanitize_text_field( $event['type'] ?? '' );
        $object = $event['data']['object'] ?? array();
        $payment = $object['payment'] ?? array();
        if ( is_array( $payment ) && ! empty( $payment['id'] ) ) {
            self::merge_payment( $payment );
            $order = self::find_wc_order( $payment );
            if ( $order instanceof WC_Order ) {
                $status = strtoupper( (string) ( $payment['status'] ?? '' ) );
                $order->update_meta_data( '_persiano_square_payment_status', strtolower( $status ) );
                if ( ! empty( $payment['receipt_url'] ) ) { $order->update_meta_data( '_persiano_square_receipt_url', esc_url_raw( $payment['receipt_url'] ) ); }
                if ( 'COMPLETED' === $status && ! $order->is_paid() ) {
                    $order->payment_complete( sanitize_text_field( $payment['id'] ) );
                } else { $order->save(); }
                $order->add_order_note( sprintf( 'Square webhook: %s · payment %s is %s.', $type, sanitize_text_field( $payment['id'] ), $status ) );
            }
        }
        $refund = $object['refund'] ?? array();
        if ( is_array( $refund ) && ! empty( $refund['payment_id'] ) ) {
            $payment_id = sanitize_text_field( $refund['payment_id'] );

            // A refund event contains one refund, while the Payment object contains
            // the cumulative refunded total. Refresh the payment so multiple or
            // updated refunds are never misrepresented as a single total.
            if ( Persiano_Hub_Square_Payments::has_token() ) {
                $payment_response = Persiano_Hub_Square_Payments::api_request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ) );
                if ( ! is_wp_error( $payment_response ) && ! empty( $payment_response['payment'] ) ) {
                    self::merge_payment( $payment_response['payment'] );
                }
            }

            $order = self::find_wc_order( array( 'id' => $payment_id ) );
            if ( $order instanceof WC_Order ) {
                $refund_status = strtoupper( sanitize_text_field( $refund['status'] ?? '' ) );
                $refund_id = sanitize_text_field( $refund['id'] ?? '' );
                $amount = (int) ( $refund['amount_money']['amount'] ?? 0 );
                $currency = sanitize_text_field( $refund['amount_money']['currency'] ?? $order->get_currency() );
                $order->update_meta_data( '_persiano_square_payment_status', 'COMPLETED' === $refund_status ? 'refund_updated' : strtolower( $refund_status ?: 'refund_updated' ) );
                $order->save();
                $order->add_order_note( sprintf( 'Square webhook: %s · refund %s is %s (%s %0.2f). Review WooCommerce refunds if this was created outside Batchly.', $type, $refund_id ?: 'unknown', $refund_status ?: 'UPDATED', $currency, $amount / 100 ) );
            }
        }
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    private static function filtered_cache( $status, $search, $from, $to ) {
        $rows = self::cache();
        return array_filter(
            $rows,
            static function( $row ) use ( $status, $search, $from, $to ) {
                if ( $status && strtoupper( $status ) !== strtoupper( $row['status'] ?? '' ) ) { return false; }
                $created = substr( (string) ( $row['created_at'] ?? '' ), 0, 10 );
                if ( $from && $created && $created < $from ) { return false; }
                if ( $to && $created && $created > $to ) { return false; }
                if ( $search ) {
                    $haystack = strtolower( implode( ' ', array( $row['id'] ?? '', $row['order_id'] ?? '', $row['receipt_number'] ?? '', $row['card_last4'] ?? '', $row['note'] ?? '' ) ) );
                    if ( false === strpos( $haystack, strtolower( $search ) ) ) { return false; }
                }
                return true;
            }
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to view Square transactions.', 'persiano-hub' ) ); }
        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
        $to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
        $detail_id = isset( $_GET['payment_id'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_id'] ) ) : '';
        $rows = self::filtered_cache( $status, $search, $from, $to );
        $settings = self::pos_settings();
        ?>
        <div class="wrap ph-square-wrap"><h1><?php esc_html_e( 'Square Transactions', 'persiano-hub' ); ?></h1><p class="description">Live Square payment history with exact WooCommerce links. Amount-only matching is never used.</p>
        <?php if ( isset( $_GET['ph_notice'] ) ) : $class = 'error' === ( $_GET['ph_notice_type'] ?? '' ) ? 'notice-error' : 'notice-success'; ?><div class="notice <?php echo esc_attr( $class ); ?> is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['ph_notice'] ) ) ) ); ?></p></div><?php endif; ?>
        <div class="ph-square-toolbar"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_square_sync"><?php wp_nonce_field( 'persiano_hub_square_sync' ); ?><label>Sync last <input type="number" min="1" max="365" name="days" value="90" style="width:75px"> days</label><button class="button button-primary">Sync Square</button></form><span>Last sync: <strong><?php echo esc_html( get_option( self::OPTION_SYNCED, 'Never' ) ); ?></strong></span><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=persiano-hub-pos-settings' ) ); ?>">Square settings</a></div>
        <?php if ( ! Persiano_Hub_Square_Payments::has_token() ) : ?><div class="notice notice-warning"><p>Add the Square Production Access Token before syncing.</p></div><?php endif; ?>
        <details class="ph-webhook-box"><summary>Square webhook</summary><p>Webhook URL:</p><input class="large-text code" readonly value="<?php echo esc_attr( self::webhook_url() ); ?>"><p class="description">Add this endpoint in Square Developer Console for payment.created, payment.updated, refund.created and refund.updated. Add the webhook signature key in POS &amp; Square settings.</p></details>
        <form class="ph-square-filters" method="get"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><select name="status"><option value="">All statuses</option><?php foreach ( array( 'COMPLETED','APPROVED','PENDING','CANCELED','FAILED' ) as $option ) : ?><option value="<?php echo esc_attr( strtolower( $option ) ); ?>" <?php selected( strtoupper( $status ), $option ); ?>><?php echo esc_html( $option ); ?></option><?php endforeach; ?></select><input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"><input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Payment, order, receipt or last 4"><button class="button">Filter</button></form>
        <?php if ( $detail_id ) { self::render_detail( $detail_id ); } ?>
        <div class="ph-square-table-wrap"><table class="widefat striped"><thead><tr><th>Date</th><th>Amount</th><th>Status</th><th>Card</th><th>Square IDs</th><th>WooCommerce</th><th>Fee</th><th>Refunded</th><th></th></tr></thead><tbody>
        <?php if ( ! $rows ) : ?><tr><td colspan="9">No cached Square transactions match the filters. Run Sync Square.</td></tr><?php endif; ?>
        <?php foreach ( $rows as $row ) : $order = self::find_wc_order( $row['raw'] ?? $row ); $currency = $row['amount_money']['currency'] ?? 'CAD'; ?>
        <tr><td><?php echo esc_html( $row['created_at'] ? mysql2date( 'M j, Y g:i a', gmdate( 'Y-m-d H:i:s', strtotime( $row['created_at'] ) ) ) : '—' ); ?></td><td><strong><?php echo esc_html( $currency . ' ' . number_format_i18n( ( $row['amount_money']['amount'] ?? 0 ) / 100, 2 ) ); ?></strong><?php if ( ! empty( $row['tip_money']['amount'] ) ) : ?><br><small>Tip <?php echo esc_html( number_format_i18n( $row['tip_money']['amount'] / 100, 2 ) ); ?></small><?php endif; ?></td><td><span class="ph-square-status status-<?php echo esc_attr( strtolower( $row['status'] ) ); ?>"><?php echo esc_html( $row['status'] ?: 'UNKNOWN' ); ?></span></td><td><?php echo esc_html( trim( ( $row['card_brand'] ?: 'Card' ) . ( $row['card_last4'] ? ' •••• ' . $row['card_last4'] : '' ) ) ); ?></td><td><small>Payment <code><?php echo esc_html( $row['id'] ); ?></code><?php if ( $row['order_id'] ) : ?><br>Order <code><?php echo esc_html( $row['order_id'] ); ?></code><?php endif; ?></small></td><td><?php if ( $order ) : ?><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">Order #<?php echo esc_html( $order->get_order_number() ); ?></a><br><small><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></small><?php else : ?><span class="ph-unlinked">Unlinked</span><?php endif; ?></td><td><?php echo esc_html( $currency . ' ' . number_format_i18n( ( $row['processing_fee'] ?? 0 ) / 100, 2 ) ); ?></td><td><?php echo esc_html( $currency . ' ' . number_format_i18n( ( $row['refunded_money']['amount'] ?? 0 ) / 100, 2 ) ); ?></td><td><a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'payment_id' => $row['id'] ), admin_url( 'admin.php' ) ) ); ?>">Details</a><?php if ( $row['receipt_url'] ) : ?> <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( $row['receipt_url'] ); ?>">Receipt</a><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div></div>
        <style>.ph-square-wrap{max-width:1500px}.ph-square-toolbar,.ph-square-filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:16px 0}.ph-square-toolbar{justify-content:space-between;background:#fff;border:1px solid #dcdcde;padding:14px;border-radius:10px}.ph-square-toolbar form{display:flex;gap:8px;align-items:center}.ph-webhook-box,.ph-square-detail{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px;margin:16px 0}.ph-square-table-wrap{overflow:auto}.ph-square-status{display:inline-block;padding:4px 8px;border-radius:999px;background:#f0f0f1;font-size:11px;font-weight:700}.status-completed{background:#d7f3df;color:#14532d}.status-failed,.status-canceled{background:#f8d7da;color:#842029}.status-pending,.status-approved{background:#fff3cd;color:#664d03}.ph-unlinked{color:#8c8f94}.ph-square-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.ph-square-detail-grid>div{background:#f6f7f7;padding:12px;border-radius:8px}.ph-refund-form{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:16px}.ph-refund-form label{display:grid;gap:4px}@media(max-width:900px){.ph-square-detail-grid{grid-template-columns:1fr 1fr}}</style>
        <?php
    }

    private static function render_detail( $payment_id ) {
        $cache = self::cache();
        $row = $cache[ $payment_id ] ?? null;
        if ( Persiano_Hub_Square_Payments::has_token() ) {
            $response = Persiano_Hub_Square_Payments::api_request( 'GET', '/v2/payments/' . rawurlencode( $payment_id ) );
            if ( ! is_wp_error( $response ) && ! empty( $response['payment'] ) ) { $row = self::normalize_payment( $response['payment'] ); self::merge_payment( $response['payment'] ); }
        }
        if ( ! $row ) { echo '<div class="notice notice-error"><p>Payment was not found.</p></div>'; return; }
        $order = self::find_wc_order( $row['raw'] ?? $row );
        $currency = $row['amount_money']['currency'] ?? 'CAD';
        echo '<section class="ph-square-detail"><h2>Payment ' . esc_html( $payment_id ) . '</h2><div class="ph-square-detail-grid"><div><small>Amount</small><br><strong>' . esc_html( $currency . ' ' . number_format_i18n( ( $row['amount_money']['amount'] ?? 0 ) / 100, 2 ) ) . '</strong></div><div><small>Status</small><br><strong>' . esc_html( $row['status'] ) . '</strong></div><div><small>Card</small><br><strong>' . esc_html( trim( ( $row['card_brand'] ?: 'Card' ) . ' •••• ' . $row['card_last4'] ) ) . '</strong></div><div><small>Processing fee</small><br><strong>' . esc_html( $currency . ' ' . number_format_i18n( ( $row['processing_fee'] ?? 0 ) / 100, 2 ) ) . '</strong></div></div>';
        if ( $order ) {
            $remaining = (float) $order->get_remaining_refund_amount();
            echo '<p>Linked WooCommerce order: <a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></p>';
            if ( $remaining > 0 && 'COMPLETED' === strtoupper( $row['status'] ) ) {
                echo '<form class="ph-refund-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="persiano_hub_square_refund"><input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
                wp_nonce_field( 'persiano_hub_square_refund' );
                echo '<label>Refund amount<input type="number" min="0.01" max="' . esc_attr( $remaining ) . '" step="0.01" name="amount" value="' . esc_attr( number_format( $remaining, 2, '.', '' ) ) . '"></label><label>Reason<input class="regular-text" name="reason" placeholder="Customer request"></label><button class="button">Submit Square refund</button></form><p class="description">Refunds are available only when the Square payment is linked to a WooCommerce order, so the accounting record stays synchronized.</p>';
            }
        } else { echo '<p class="ph-unlinked">This Square payment is not linked to a WooCommerce order. Batchly will not guess from the amount or timestamp.</p>'; }
        echo '</section>';
    }
}
