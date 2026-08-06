<?php
/**
 * Admin notifications and delivery logging.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Notifications {
    const PAGE_SLUG = 'persiano-hub-notifications';
    const OPTION_SETTINGS = 'persiano_hub_notification_settings';
    const OPTION_LOG = 'persiano_hub_notification_log';
    const OPTION_LAST_READ = 'persiano_hub_notification_last_read';
    const META_LAST_EVENT = '_persiano_notification_last_event';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 26 );
        add_action( 'admin_post_persiano_save_notification_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_persiano_send_test_notification', array( __CLASS__, 'send_test' ) );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status_changed' ), 20, 4 );
        add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 95 );
        add_action( 'admin_notices', array( __CLASS__, 'floating_notice' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_styles' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_mark_all_read' ) );
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Notifications', 'persiano-hub' ),
            __( 'Notifications', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function get_settings() {
        $saved = get_option( self::OPTION_SETTINGS, array() );
        $defaults = array(
            'recipients' => get_option( 'admin_email' ),
            'manual_created' => 'yes',
            'payment_received' => 'yes',
            'status_changed' => 'yes',
            'cancelled_refunded' => 'yes',
            'price_feed_alerts' => 'yes',
            'ai_scan_alerts' => 'yes',
        );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
    }

    /** Add a non-order system notification to the admin log and, when enabled, email it. */
    public static function add_system_notification( $type, $message, $detail = '', $url = '' ) {
        $type = sanitize_key( $type );
        $message = sanitize_text_field( $message );
        $detail = sanitize_text_field( $detail );
        $url = esc_url_raw( $url );
        if ( ! $message ) { return false; }

        $fingerprints = get_option( 'persiano_hub_system_notification_fingerprints', array() );
        $fingerprints = is_array( $fingerprints ) ? $fingerprints : array();
        $fingerprint = md5( $type . '|' . $message . '|' . $detail );
        if ( ! empty( $fingerprints[ $fingerprint ] ) && time() - (int) $fingerprints[ $fingerprint ] < 12 * HOUR_IN_SECONDS ) {
            return true;
        }
        $fingerprints[ $fingerprint ] = time();
        foreach ( $fingerprints as $key => $recorded ) {
            if ( time() - (int) $recorded > 30 * DAY_IN_SECONDS ) { unset( $fingerprints[ $key ] ); }
        }
        update_option( 'persiano_hub_system_notification_fingerprints', $fingerprints, false );

        $settings = self::get_settings();
        $email_enabled = true;
        if ( 0 === strpos( $type, 'price_feed' ) ) { $email_enabled = 'yes' === ( $settings['price_feed_alerts'] ?? 'yes' ); }
        if ( 0 === strpos( $type, 'ai_scan' ) ) { $email_enabled = 'yes' === ( $settings['ai_scan_alerts'] ?? 'yes' ); }
        $body = '<p>' . esc_html( $detail ?: $message ) . '</p>';
        if ( $url ) { $body .= '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:11px 18px;border-radius:999px;background:#8e2435;color:#fff;text-decoration:none;font-weight:700">' . esc_html__( 'Open in Batchly', 'persiano-hub' ) . '</a></p>'; }
        if ( $email_enabled ) {
            return self::send( $type, '[' . ( function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) . '] ' . $message, $body );
        }
        self::log( $type, $message, 'notice', 0, $detail );
        return true;
    }

    public static function notify_manual_order_created( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $settings = self::get_settings();
        if ( 'yes' !== $settings['manual_created'] ) {
            return;
        }
        $subject = sprintf( '[%1$s] Manual order #%2$s created', function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ), $order->get_order_number() );
        $body = self::order_summary_html( $order, __( 'A manual order was created and is awaiting your attention.', 'persiano-hub' ) );
        self::send( 'manual_created', $subject, $body, $order );
    }

    public static function order_status_changed( $order_id, $from, $to, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $settings = self::get_settings();
        if ( 'pending' === $to && 'persiano-manual' === $order->get_created_via() ) {
            return;
        }
        if ( in_array( $to, array( 'processing', 'completed' ), true ) && 'yes' === $settings['payment_received'] && $order->is_paid() ) {
            $subject = sprintf( '[%1$s] Payment received for order #%2$s', function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ), $order->get_order_number() );
            self::send( 'payment_received', $subject, self::order_summary_html( $order, __( 'Payment has been received.', 'persiano-hub' ) ), $order );
            return;
        }
        if ( in_array( $to, array( 'cancelled', 'refunded', 'failed' ), true ) && 'yes' === $settings['cancelled_refunded'] ) {
            $subject = sprintf( '[%1$s] Order #%2$s is %3$s', function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ), $order->get_order_number(), wc_get_order_status_name( $to ) );
            self::send( 'status_alert', $subject, self::order_summary_html( $order, sprintf( __( 'Order status changed from %1$s to %2$s.', 'persiano-hub' ), wc_get_order_status_name( $from ), wc_get_order_status_name( $to ) ) ), $order );
            return;
        }
        if ( 'yes' === $settings['status_changed'] && $from !== $to ) {
            $subject = sprintf( '[%1$s] Order #%2$s updated to %3$s', function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ), $order->get_order_number(), wc_get_order_status_name( $to ) );
            self::send( 'status_changed', $subject, self::order_summary_html( $order, sprintf( __( 'Order status changed from %1$s to %2$s.', 'persiano-hub' ), wc_get_order_status_name( $from ), wc_get_order_status_name( $to ) ) ), $order );
        }
    }

    private static function order_summary_html( $order, $intro ) {
        $name = trim( $order->get_formatted_billing_full_name() );
        $edit = $order->get_edit_order_url();
        $items = array();
        foreach ( $order->get_items() as $item ) {
            $items[] = esc_html( $item->get_name() ) . ' × ' . absint( $item->get_quantity() );
        }
        $html  = '<p>' . esc_html( $intro ) . '</p>';
        $html .= '<p><strong>' . esc_html( $name ?: __( 'Guest customer', 'persiano-hub' ) ) . '</strong><br>';
        $html .= esc_html( $order->get_billing_email() ) . ( $order->get_billing_phone() ? '<br>' . esc_html( $order->get_billing_phone() ) : '' ) . '</p>';
        $html .= '<p>' . implode( '<br>', $items ) . '</p>';
        $html .= '<p><strong>' . esc_html__( 'Total:', 'persiano-hub' ) . '</strong> ' . wp_kses_post( $order->get_formatted_order_total() ) . '<br>';
        $html .= '<strong>' . esc_html__( 'Status:', 'persiano-hub' ) . '</strong> ' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</p>';
        $html .= '<p><a href="' . esc_url( $edit ) . '" style="display:inline-block;padding:11px 18px;border-radius:999px;background:#8e2435;color:#fff;text-decoration:none;font-weight:700">' . esc_html__( 'Open order', 'persiano-hub' ) . '</a></p>';
        return $html;
    }

    private static function send( $type, $subject, $body, $order = null ) {
        if ( $order instanceof WC_Order ) {
            $fingerprint = md5( $type . '|' . $order->get_id() . '|' . $order->get_status() . '|' . $subject );
            $last = (string) $order->get_meta( self::META_LAST_EVENT, true );
            if ( $last === $fingerprint ) {
                return true;
            }
            $order->update_meta_data( self::META_LAST_EVENT, $fingerprint );
            $order->save_meta_data();
        }
        $settings = self::get_settings();
        $recipients = array_filter( array_map( 'sanitize_email', preg_split( '/[;,\s]+/', (string) $settings['recipients'] ) ) );
        if ( empty( $recipients ) ) {
            self::log( $type, $subject, 'skipped', $order ? $order->get_id() : 0, __( 'No valid recipient.', 'persiano-hub' ) );
            return false;
        }
        if ( class_exists( 'Persiano_Hub_Email_Branding' ) ) {
            $body = Persiano_Hub_Email_Branding::branded_message( $subject, $body, wp_strip_all_tags( $subject ) );
        }
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent = wp_mail( $recipients, $subject, $body, $headers );
        self::log( $type, $subject, $sent ? 'sent' : 'failed', $order ? $order->get_id() : 0, implode( ', ', $recipients ) );
        return $sent;
    }

    private static function log( $type, $message, $status, $order_id = 0, $detail = '' ) {
        $log = get_option( self::OPTION_LOG, array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        array_unshift( $log, array(
            'time' => current_time( 'mysql' ),
            'type' => sanitize_key( $type ),
            'message' => sanitize_text_field( $message ),
            'status' => sanitize_key( $status ),
            'order_id' => absint( $order_id ),
            'detail' => sanitize_text_field( $detail ),
            'id' => wp_generate_uuid4(),
        ) );
        update_option( self::OPTION_LOG, array_slice( $log, 0, 60 ), false );
    }


    public static function maybe_mark_all_read() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( self::PAGE_SLUG !== $page ) { return; }
        $log = get_option( self::OPTION_LOG, array() );
        $latest = is_array( $log ) && ! empty( $log[0]['id'] ) ? sanitize_text_field( $log[0]['id'] ) : '';
        update_user_meta( get_current_user_id(), self::OPTION_LAST_READ, $latest );
    }

    public static function unread_count() {
        $log = get_option( self::OPTION_LOG, array() );
        $last_read = (string) get_user_meta( get_current_user_id(), self::OPTION_LAST_READ, true );
        $count = 0;
        foreach ( is_array( $log ) ? $log : array() as $entry ) {
            if ( ! empty( $entry['id'] ) && $entry['id'] === $last_read ) { break; }
            $count++;
        }
        return $count;
    }

    public static function admin_bar( $bar ) {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! is_admin_bar_showing() ) { return; }
        $unread = self::unread_count();
        $title = '<span class="ab-icon dashicons dashicons-bell"></span>';
        if ( $unread ) { $title .= '<span class="ph-notification-count">' . absint( $unread ) . '</span>'; }
        $bar->add_node( array(
            'id' => 'persiano-notifications',
            'title' => $title,
            'href' => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
            'meta' => array( 'title' => $unread ? sprintf( _n( '%d unread Persiano notification', '%d unread Persiano notifications', $unread, 'persiano-hub' ), $unread ) : __( 'No unread Persiano notifications', 'persiano-hub' ), 'class' => $unread ? 'ph-has-unread' : 'ph-no-unread' ),
        ) );
    }

    public static function admin_styles() {
        wp_register_style( 'persiano-hub-notification-bar', false, array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-hub-notification-bar' );
        wp_add_inline_style( 'persiano-hub-notification-bar', '#wpadminbar #wp-admin-bar-persiano-notifications .ab-icon:before{content:"\\f16a";top:2px}.ph-no-unread .ab-icon{color:#a7aaad!important}.ph-has-unread .ab-icon{color:#f0b849!important}.ph-notification-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;margin-left:2px;border-radius:99px;background:#a32638;color:#fff;font-size:11px;font-weight:700;line-height:18px}' );
    }

    public static function floating_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! self::unread_count() ) { return; }
        $log = get_option( self::OPTION_LOG, array() );
        $entry = is_array( $log ) && ! empty( $log[0] ) ? $log[0] : array();
        if ( empty( $entry['message'] ) ) { return; }
        echo '<div class="notice notice-info is-dismissible"><p><strong>' . esc_html__( 'New Persiano notification:', 'persiano-hub' ) . '</strong> ' . esc_html( $entry['message'] ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'View notifications', 'persiano-hub' ) . '</a></p></div>';
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_save_notification_settings' );
        update_option( self::OPTION_SETTINGS, array(
            'recipients' => sanitize_text_field( wp_unslash( $_POST['recipients'] ?? '' ) ),
            'manual_created' => ! empty( $_POST['manual_created'] ) ? 'yes' : 'no',
            'payment_received' => ! empty( $_POST['payment_received'] ) ? 'yes' : 'no',
            'status_changed' => ! empty( $_POST['status_changed'] ) ? 'yes' : 'no',
            'cancelled_refunded' => ! empty( $_POST['cancelled_refunded'] ) ? 'yes' : 'no',
            'price_feed_alerts' => ! empty( $_POST['price_feed_alerts'] ) ? 'yes' : 'no',
            'ai_scan_alerts' => ! empty( $_POST['ai_scan_alerts'] ) ? 'yes' : 'no',
        ), false );
        wp_safe_redirect( add_query_arg( 'updated', 1, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
        exit;
    }

    public static function send_test() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_send_test_notification' );
        self::send( 'test', '[' . ( function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) . '] Test notification', '<p>This is a test admin notification from ' . esc_html( class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::hub_name() : 'Batchly' ) . '.</p>' );
        wp_safe_redirect( add_query_arg( 'tested', 1, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $settings = self::get_settings();
        $log = get_option( self::OPTION_LOG, array() );
        ?>
        <div class="wrap"><h1><?php esc_html_e( 'Notifications', 'persiano-hub' ); ?></h1>
        <p><?php esc_html_e( 'Receive admin alerts for orders, online price sources and background AI Scan jobs.', 'persiano-hub' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:850px;background:#fff;border:1px solid #dcdcde;padding:22px;border-radius:12px">
        <?php wp_nonce_field( 'persiano_save_notification_settings' ); ?><input type="hidden" name="action" value="persiano_save_notification_settings">
        <p><label><strong><?php esc_html_e( 'Recipients', 'persiano-hub' ); ?></strong><br><input class="large-text" type="text" name="recipients" value="<?php echo esc_attr( $settings['recipients'] ); ?>"><br><span class="description"><?php esc_html_e( 'Separate multiple email addresses with commas.', 'persiano-hub' ); ?></span></label></p>
        <?php foreach ( array( 'manual_created' => 'Manual order created', 'payment_received' => 'Payment received', 'status_changed' => 'Meaningful status changes', 'cancelled_refunded' => 'Cancelled, refunded or failed orders', 'price_feed_alerts' => 'Price source inaccessible or product identity changed', 'ai_scan_alerts' => 'AI Scan job completed or failed' ) as $key => $label ) : ?>
        <p><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $settings[ $key ], 'yes' ); ?>> <?php echo esc_html( $label ); ?></label></p>
        <?php endforeach; ?><p><button class="button button-primary"><?php esc_html_e( 'Save notification settings', 'persiano-hub' ); ?></button></p></form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0"><?php wp_nonce_field( 'persiano_send_test_notification' ); ?><input type="hidden" name="action" value="persiano_send_test_notification"><button class="button"><?php esc_html_e( 'Send test notification', 'persiano-hub' ); ?></button></form>
        <h2><?php esc_html_e( 'Delivery history', 'persiano-hub' ); ?></h2><table class="widefat striped"><thead><tr><th>Date</th><th>Type</th><th>Message</th><th>Status</th><th>Details</th></tr></thead><tbody>
        <?php if ( empty( $log ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No notifications yet.', 'persiano-hub' ); ?></td></tr><?php else : foreach ( $log as $entry ) : ?>
        <tr><td><?php echo esc_html( $entry['time'] ?? '' ); ?></td><td><?php echo esc_html( $entry['type'] ?? '' ); ?></td><td><?php echo esc_html( $entry['message'] ?? '' ); ?><?php if ( ! empty( $entry['order_id'] ) ) : ?> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $entry['order_id'] ) ) ); ?>"><?php esc_html_e( 'Open order', 'persiano-hub' ); ?></a><?php endif; ?></td><td><?php echo esc_html( $entry['status'] ?? '' ); ?></td><td><?php echo esc_html( $entry['detail'] ?? '' ); ?></td></tr>
        <?php endforeach; endif; ?></tbody></table></div>
        <?php
    }
}
