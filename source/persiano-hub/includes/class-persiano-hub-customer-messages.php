<?php
/**
 * Unified one-to-one customer correspondence for email and Twilio SMS.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Customer_Messages {
    const DB_VERSION       = '1.1.0';
    const OPTION_SETTINGS  = 'persiano_hub_messages_settings';
    const OPTION_DB        = 'persiano_hub_messages_db_version';
    const OPTION_OPTOUTS   = 'persiano_hub_sms_optouts';
    const PAGE             = 'persiano-hub-messages';
    const SETTINGS_PAGE    = 'persiano-hub-messages-settings';
    const REST_NAMESPACE   = 'persiano-hub/v1';

    private static $pending_wc_email = array();

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_pages' ), 82 );
        add_action( 'admin_post_persiano_hub_send_message', array( __CLASS__, 'handle_send' ) );
        add_action( 'admin_post_persiano_hub_save_message_settings', array( __CLASS__, 'handle_save_settings' ) );
        add_action( 'admin_post_persiano_hub_assign_message_thread', array( __CLASS__, 'handle_assign_thread' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
        add_action( 'wp_ajax_persiano_hub_order_contact', array( __CLASS__, 'ajax_order_contact' ) );

        add_action( 'woocommerce_new_order', array( __CLASS__, 'capture_order_created' ), 30, 2 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'capture_order_status_changed' ), 30, 4 );
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'capture_payment_complete' ), 30, 1 );
        add_action( 'woocommerce_order_refunded', array( __CLASS__, 'capture_order_refunded' ), 30, 2 );
        add_action( 'woocommerce_email_order_details', array( __CLASS__, 'capture_wc_email_context' ), 999, 4 );
        add_action( 'wp_mail_succeeded', array( __CLASS__, 'capture_wp_mail_succeeded' ), 10, 1 );
        add_action( 'wp_mail_failed', array( __CLASS__, 'capture_wp_mail_failed' ), 10, 1 );
    }

    public static function ajax_order_contact() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'persiano-hub' ) ), 403 );
        }
        check_ajax_referer( 'persiano_hub_order_contact', 'nonce' );
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'persiano-hub' ) ), 404 );
        }
        wp_send_json_success( array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'name' => trim( $order->get_formatted_billing_full_name() ),
            'email' => sanitize_email( $order->get_billing_email() ),
            'phone' => sanitize_text_field( $order->get_billing_phone() ),
            'status' => wc_get_order_status_name( $order->get_status() ),
            'payment_link' => $order->needs_payment() ? $order->get_checkout_payment_url() : '',
        ) );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'persiano_messages';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            thread_key varchar(64) NOT NULL,
            channel varchar(20) NOT NULL,
            direction varchar(12) NOT NULL,
            status varchar(32) NOT NULL,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            contact varchar(190) NOT NULL DEFAULT '',
            contact_normalized varchar(190) NOT NULL DEFAULT '',
            subject text NULL,
            body longtext NOT NULL,
            provider_message_id varchar(191) NOT NULL DEFAULT '',
            provider_error text NULL,
            media_json longtext NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            read_at datetime NULL,
            PRIMARY KEY  (id),
            KEY thread_key (thread_key),
            KEY contact_normalized (contact_normalized),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY provider_message_id (provider_message_id),
            KEY channel_status (channel,status),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta( $sql );
        self::migrate_order_threads();
        update_option( self::OPTION_DB, self::DB_VERSION, false );

        $settings = get_option( self::OPTION_SETTINGS, array() );
        if ( empty( $settings['webhook_token'] ) ) {
            $settings['webhook_token'] = wp_generate_password( 32, false, false );
            update_option( self::OPTION_SETTINGS, $settings, false );
        }
    }

    public static function maybe_install() {
        if ( version_compare( (string) get_option( self::OPTION_DB, '0' ), self::DB_VERSION, '<' ) ) {
            self::install();
        }
    }

    public static function settings() {
        $defaults = array(
            'email_from_name'       => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' ),
            'email_from_address'    => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_email() : get_option( 'admin_email' ),
            'twilio_account_sid'    => '',
            'twilio_auth_token'     => '',
            'twilio_service_sid'    => '',
            'twilio_from_number'    => '',
            'webhook_token'         => '',
            'strict_signature'      => 'no',
            'quiet_hours_enabled'   => 'yes',
            'quiet_start'           => '20:00',
            'quiet_end'             => '09:00',
            'sms_signature'         => '',
            'quick_replies'         => "Thanks — I’ll check and get back to you shortly.\nYour order is ready for pickup.\nCould you please confirm your pickup or delivery time?\nThank you. Your payment link is ready: {payment_link}",
        );
        $saved = get_option( self::OPTION_SETTINGS, array() );
        $settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
        if ( empty( $settings['webhook_token'] ) ) {
            $settings['webhook_token'] = wp_generate_password( 32, false, false );
            update_option( self::OPTION_SETTINGS, $settings, false );
        }
        return $settings;
    }

    public static function register_pages() {
        add_submenu_page(
            'persiano-hub',
            __( 'Order Correspondence', 'persiano-hub' ),
            __( 'Correspondence', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
        add_submenu_page(
            'persiano-hub',
            __( 'Message Settings', 'persiano-hub' ),
            __( 'Message Settings', 'persiano-hub' ),
            'manage_woocommerce',
            self::SETTINGS_PAGE,
            array( __CLASS__, 'render_settings' )
        );
    }

    public static function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/twilio/inbound',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'receive_twilio_message' ),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            self::REST_NAMESPACE,
            '/twilio/status',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'receive_twilio_status' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    private static function normalize_phone( $phone ) {
        $phone = trim( (string) $phone );
        if ( '' === $phone ) { return ''; }
        $leading_plus = 0 === strpos( $phone, '+' );
        $digits = preg_replace( '/\D+/', '', $phone );
        if ( ! $digits ) { return ''; }
        if ( 10 === strlen( $digits ) ) { return '+1' . $digits; }
        if ( 11 === strlen( $digits ) && '1' === $digits[0] ) { return '+' . $digits; }
        return $leading_plus ? '+' . $digits : '+' . $digits;
    }

    private static function normalize_contact( $contact, $channel ) {
        return 'sms' === $channel ? self::normalize_phone( $contact ) : strtolower( sanitize_email( $contact ) );
    }

    private static function thread_key( $customer_id, $contact_normalized, $order_id = 0 ) {
        if ( $order_id ) {
            return hash( 'sha256', 'order:' . absint( $order_id ) );
        }
        return hash( 'sha256', $customer_id ? 'customer:' . absint( $customer_id ) : 'contact:' . strtolower( trim( (string) $contact_normalized ) ) );
    }

    private static function migrate_order_threads() {
        global $wpdb;
        $table = self::table_name();
        $order_ids = $wpdb->get_col( "SELECT DISTINCT order_id FROM {$table} WHERE order_id > 0" );
        foreach ( $order_ids as $order_id ) {
            $order_id = absint( $order_id );
            if ( ! $order_id ) { continue; }
            $wpdb->update(
                $table,
                array( 'thread_key' => self::thread_key( 0, '', $order_id ) ),
                array( 'order_id' => $order_id ),
                array( '%s' ),
                array( '%d' )
            );
        }
    }

    private static function find_context( $contact, $channel, $order_id = 0 ) {
        $order = $order_id ? wc_get_order( $order_id ) : false;
        $customer_id = $order instanceof WC_Order ? absint( $order->get_customer_id() ) : 0;
        if ( $order instanceof WC_Order ) {
            $order_contact = 'sms' === $channel ? $order->get_billing_phone() : $order->get_billing_email();
            if ( ! $contact && $order_contact ) { $contact = $order_contact; }
        }
        $normalized = self::normalize_contact( $contact, $channel );

        if ( ! $order && $normalized ) {
            global $wpdb;
            $recent_cutoff = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) );
            $existing_order_id = absint( $wpdb->get_var( $wpdb->prepare(
                'SELECT order_id FROM ' . self::table_name() . ' WHERE contact_normalized=%s AND order_id>0 AND created_at>=%s ORDER BY created_at DESC, id DESC LIMIT 1',
                $normalized,
                $recent_cutoff
            ) ) );
            if ( $existing_order_id ) {
                $existing_order = wc_get_order( $existing_order_id );
                if ( $existing_order instanceof WC_Order && ! $existing_order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
                    $order       = $existing_order;
                    $order_id    = $existing_order_id;
                    $customer_id = absint( $existing_order->get_customer_id() );
                }
            }
        }

        if ( ! $order && $normalized ) {
            $args = array( 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' );
            if ( 'sms' === $channel ) {
                $args['billing_phone'] = $normalized;
                $orders = wc_get_orders( $args );
                if ( ! $orders && 0 === strpos( $normalized, '+1' ) ) {
                    $args['billing_phone'] = substr( $normalized, 2 );
                    $orders = wc_get_orders( $args );
                }
                if ( ! $orders ) {
                    // Billing phones are often saved with spaces, brackets or
                    // dashes. Compare normalized values across recent orders so
                    // an incoming reply can still attach to the latest order.
                    $recent_orders = wc_get_orders( array( 'limit' => 200, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) );
                    foreach ( $recent_orders as $recent_order ) {
                        if ( $recent_order instanceof WC_Order && self::normalize_phone( $recent_order->get_billing_phone() ) === $normalized ) {
                            $orders = array( $recent_order );
                            break;
                        }
                    }
                }
            } else {
                $args['billing_email'] = $normalized;
                $orders = wc_get_orders( $args );
            }
            $active_orders = array();
            foreach ( (array) $orders as $candidate_order ) {
                if ( $candidate_order instanceof WC_Order && ! $candidate_order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed' ) ) ) {
                    $active_orders[] = $candidate_order;
                }
            }
            if ( 1 === count( $active_orders ) ) {
                $order       = $active_orders[0];
                $order_id    = $order->get_id();
                $customer_id = absint( $order->get_customer_id() );
            } elseif ( empty( $active_orders ) && 1 === count( (array) $orders ) && $orders[0] instanceof WC_Order ) {
                $order       = $orders[0];
                $order_id    = $order->get_id();
                $customer_id = absint( $order->get_customer_id() );
            }
        }

        if ( ! $customer_id && 'email' === $channel && $normalized ) {
            $user = get_user_by( 'email', $normalized );
            if ( $user ) { $customer_id = absint( $user->ID ); }
        }

        return array(
            'contact'            => $contact,
            'contact_normalized' => $normalized,
            'customer_id'        => $customer_id,
            'order_id'           => absint( $order_id ),
            'order'              => $order,
            'thread_key'         => self::thread_key( $customer_id, $normalized, $order_id ),
        );
    }

    private static function insert_message( $data ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $defaults = array(
            'thread_key'         => '',
            'channel'            => '',
            'direction'          => '',
            'status'             => '',
            'customer_id'        => 0,
            'order_id'           => 0,
            'contact'            => '',
            'contact_normalized' => '',
            'subject'            => '',
            'body'               => '',
            'provider_message_id'=> '',
            'provider_error'     => '',
            'media_json'         => '',
            'created_by'         => get_current_user_id(),
            'created_at'         => $now,
            'updated_at'         => $now,
            'read_at'            => null,
        );
        $row = wp_parse_args( $data, $defaults );
        $read_at = $row['read_at'];
        unset( $row['read_at'] );
        $wpdb->insert(
            self::table_name(),
            $row,
            array( '%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s' )
        );
        if ( null !== $read_at && $wpdb->insert_id ) {
            $wpdb->update(
                self::table_name(),
                array( 'read_at' => $read_at ),
                array( 'id' => $wpdb->insert_id ),
                array( '%s' ),
                array( '%d' )
            );
        }
        return absint( $wpdb->insert_id );
    }

    private static function add_order_note( $order_id, $message ) {
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( $order instanceof WC_Order ) {
            $order->add_order_note( sanitize_text_field( $message ), false, true );
        }
    }

    private static function is_sms_opted_out( $phone ) {
        $optouts = get_option( self::OPTION_OPTOUTS, array() );
        return is_array( $optouts ) && ! empty( $optouts[ hash( 'sha256', self::normalize_phone( $phone ) ) ] );
    }

    private static function set_sms_optout( $phone, $opted_out ) {
        $phone = self::normalize_phone( $phone );
        if ( ! $phone ) { return; }
        $key = hash( 'sha256', $phone );
        $optouts = get_option( self::OPTION_OPTOUTS, array() );
        $optouts = is_array( $optouts ) ? $optouts : array();
        if ( $opted_out ) { $optouts[ $key ] = current_time( 'mysql' ); }
        else { unset( $optouts[ $key ] ); }
        update_option( self::OPTION_OPTOUTS, $optouts, false );
    }

    private static function in_quiet_hours() {
        $settings = self::settings();
        if ( 'yes' !== $settings['quiet_hours_enabled'] ) { return false; }
        $now = current_datetime();
        $minutes = ( (int) $now->format( 'G' ) * 60 ) + (int) $now->format( 'i' );
        list( $sh, $sm ) = array_map( 'intval', explode( ':', $settings['quiet_start'] ) + array( 0, 0 ) );
        list( $eh, $em ) = array_map( 'intval', explode( ':', $settings['quiet_end'] ) + array( 0, 0 ) );
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;
        return $start > $end ? ( $minutes >= $start || $minutes < $end ) : ( $minutes >= $start && $minutes < $end );
    }

    /**
     * Return a secure payment URL only while the order can actually be paid.
     *
     * Keeping this check in one place prevents a {payment_link} token from
     * silently becoming an empty string and avoids sending stale links for
     * completed, cancelled, refunded, or zero-value orders.
     *
     * @param WC_Order|false $order Order object.
     * @return string
     */
    private static function payment_url_for_order( $order ) {
        if ( ! $order instanceof WC_Order || $order->is_paid() || (float) $order->get_total() <= 0 || ! $order->needs_payment() ) {
            return '';
        }

        $url = class_exists( 'Persiano_Hub_Customer_Accounts' )
            ? Persiano_Hub_Customer_Accounts::create_payment_access_url( $order )
            : $order->get_checkout_payment_url();

        return $url ? esc_url_raw( $url ) : '';
    }

    private static function expand_message_tokens( $body, $order ) {
        if ( ! $order instanceof WC_Order ) { return $body; }
        return strtr(
            $body,
            array(
                '{first_name}'   => $order->get_billing_first_name(),
                '{order_number}' => $order->get_order_number(),
                '{order_total}'  => wp_strip_all_tags( $order->get_formatted_order_total() ),
                '{payment_link}' => self::payment_url_for_order( $order ),
            )
        );
    }

    private static function email_button_html( $url, $label ) {
        if ( ! $url ) { return ''; }
        $primary = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'primary_color', '#8e2435' ) : '#8e2435';
        $surface = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'surface_color', '#fffaf2' ) : '#fffaf2';
        return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;margin:24px 0 16px;"><tr><td align="center"><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="' . esc_attr( $primary ) . '" style="background:' . esc_attr( $primary ) . ';border-radius:999px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . esc_attr( $primary ) . ';border:1px solid ' . esc_attr( $primary ) . ';border-radius:999px;color:' . esc_attr( $surface ) . '!important;-webkit-text-fill-color:' . esc_attr( $surface ) . '!important;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;line-height:20px;padding:15px 30px;text-align:center;text-decoration:none;"><span style="color:' . esc_attr( $surface ) . '!important;-webkit-text-fill-color:' . esc_attr( $surface ) . '!important;">' . esc_html( $label ) . '</span></a></td></tr></table></td></tr></table>';
    }

    private static function email_order_summary_html( $order, $payment_url ) {
        if ( ! $order instanceof WC_Order ) { return ''; }

        $order_number = $order->get_order_number();
        $status       = wc_get_order_status_name( $order->get_status() );
        $date_created = $order->get_date_created();
        $date_label   = $date_created ? wc_format_datetime( $date_created, get_option( 'date_format' ) ) : '';
        $shipping     = $order->get_shipping_method();
        $view_url     = $payment_url ? $payment_url : $order->get_checkout_order_received_url();

        $html  = '<div style="margin:28px 0 0;border:1px solid #eadfd2;border-radius:16px;overflow:hidden;background:#ffffff;">';
        $html .= '<div style="padding:18px 22px;background:#f3eadf;border-bottom:1px solid #eadfd2;"><h2 style="margin:0;color:#2f231d;font-family:Georgia,serif;font-size:22px;line-height:1.3;">' . esc_html( sprintf( __( 'Order #%s', 'persiano-hub' ), $order_number ) ) . '</h2></div>';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">';
        $html .= '<tr><td style="padding:14px 22px;color:#75685f;font-size:13px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #eee5dc;">' . esc_html__( 'Status', 'persiano-hub' ) . '</td><td align="right" style="padding:14px 22px;color:#2f231d;font-weight:700;border-bottom:1px solid #eee5dc;">' . esc_html( $status ) . '</td></tr>';
        if ( $date_label ) {
            $html .= '<tr><td style="padding:14px 22px;color:#75685f;font-size:13px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #eee5dc;">' . esc_html__( 'Order date', 'persiano-hub' ) . '</td><td align="right" style="padding:14px 22px;color:#2f231d;border-bottom:1px solid #eee5dc;">' . esc_html( $date_label ) . '</td></tr>';
        }
        if ( $shipping ) {
            $html .= '<tr><td style="padding:14px 22px;color:#75685f;font-size:13px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #eee5dc;">' . esc_html__( 'Fulfilment', 'persiano-hub' ) . '</td><td align="right" style="padding:14px 22px;color:#2f231d;border-bottom:1px solid #eee5dc;">' . esc_html( $shipping ) . '</td></tr>';
        }
        $html .= '<tr><td style="padding:16px 22px;color:#2f231d;font-size:15px;font-weight:700;">' . esc_html__( 'Order total', 'persiano-hub' ) . '</td><td align="right" style="padding:16px 22px;color:#8e2435;font-size:19px;font-weight:700;">' . wp_kses_post( $order->get_formatted_order_total() ) . '</td></tr>';
        $html .= '</table>';

        $items = $order->get_items( 'line_item' );
        if ( $items ) {
            $html .= '<div style="padding:0 22px 18px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;">';
            foreach ( $items as $item ) {
                $html .= '<tr><td style="padding:11px 0;border-top:1px solid #eee5dc;color:#4f433c;">' . esc_html( $item->get_name() ) . ' <span style="color:#8a7d74;">× ' . esc_html( $item->get_quantity() ) . '</span></td><td align="right" style="padding:11px 0;border-top:1px solid #eee5dc;color:#4f433c;font-weight:600;">' . wp_kses_post( $order->get_formatted_line_subtotal( $item ) ) . '</td></tr>';
            }
            $html .= '</table></div>';
        }
        $html .= '</div>';

        if ( $payment_url ) {
            $html .= '<div style="margin:24px 0 0;padding:22px;border-radius:16px;background:#fff3e4;border:1px solid #edcfaa;text-align:center;">';
            $html .= '<h2 style="margin:0 0 8px;color:#2f231d;font-family:Georgia,serif;font-size:22px;">' . esc_html__( 'Payment is ready', 'persiano-hub' ) . '</h2>';
            $html .= '<p style="margin:0;color:#675950;">' . esc_html__( 'Use the secure button below to complete payment for this order.', 'persiano-hub' ) . '</p>';
            $html .= self::email_button_html( $payment_url, __( 'Pay Securely', 'persiano-hub' ) );
            $html .= '<p style="margin:0;color:#7a6d64;font-size:12px;line-height:1.55;">' . esc_html__( 'Button not working? Copy and paste this secure link:', 'persiano-hub' ) . '<br><a href="' . esc_url( $payment_url ) . '" style="color:#8e2435;word-break:break-all;">' . esc_html( $payment_url ) . '</a></p>';
            $html .= '</div>';
        } elseif ( $view_url ) {
            $html .= self::email_button_html( $view_url, __( 'View Order Details', 'persiano-hub' ) );
        }

        return $html;
    }

    public static function record_order_message( $order_id, $channel, $direction, $status, $contact, $subject, $body, $provider_message_id = '', $provider_error = '' ) {
        if ( ! in_array( $channel, array( 'email', 'sms', 'system' ), true ) ) { return 0; }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) { return 0; }
        $context = self::find_context( $contact, 'sms' === $channel ? 'sms' : 'email', $order->get_id() );
        return self::insert_message(
            array(
                'thread_key'          => $context['thread_key'],
                'channel'             => $channel,
                'direction'           => sanitize_key( $direction ),
                'status'              => sanitize_key( $status ),
                'customer_id'         => $context['customer_id'],
                'order_id'            => $context['order_id'],
                'contact'             => sanitize_text_field( $contact ),
                'contact_normalized'  => $context['contact_normalized'],
                'subject'             => sanitize_text_field( $subject ),
                'body'                => sanitize_textarea_field( $body ),
                'provider_message_id' => sanitize_text_field( $provider_message_id ),
                'provider_error'      => sanitize_text_field( $provider_error ),
                'created_by'          => get_current_user_id(),
                'read_at'             => current_time( 'mysql' ),
            )
        );
    }

    public static function log_order_event( $order_id, $event_key, $subject, $body, $created_at = '' ) {
        global $wpdb;
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) { return 0; }

        $provider_id = 'system:' . sanitize_key( $event_key );
        $existing = absint( $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . self::table_name() . ' WHERE order_id=%d AND provider_message_id=%s LIMIT 1',
            $order->get_id(),
            $provider_id
        ) ) );
        if ( $existing ) { return $existing; }

        $email = $order->get_billing_email();
        $phone = $order->get_billing_phone();
        $contact = $email ? $email : $phone;
        $normalized = $email ? self::normalize_contact( $email, 'email' ) : self::normalize_contact( $phone, 'sms' );
        $date = $created_at ? $created_at : current_time( 'mysql' );

        return self::insert_message(
            array(
                'thread_key'          => self::thread_key( $order->get_customer_id(), $normalized, $order->get_id() ),
                'channel'             => 'system',
                'direction'           => 'system',
                'status'              => 'recorded',
                'customer_id'         => absint( $order->get_customer_id() ),
                'order_id'            => $order->get_id(),
                'contact'             => $contact,
                'contact_normalized'  => $normalized,
                'subject'             => sanitize_text_field( $subject ),
                'body'                => sanitize_textarea_field( $body ),
                'provider_message_id' => $provider_id,
                'created_by'          => 0,
                'created_at'          => $date,
                'updated_at'          => $date,
                'read_at'             => $date,
            )
        );
    }

    private static function ensure_order_baseline_events( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) { return; }
        $created = $order->get_date_created();
        self::log_order_event(
            $order->get_id(),
            'order-created',
            sprintf( __( 'Order #%s created', 'persiano-hub' ), $order->get_order_number() ),
            sprintf( __( 'Order created with status %s.', 'persiano-hub' ), wc_get_order_status_name( $order->get_status() ) ),
            $created ? $created->date( 'Y-m-d H:i:s' ) : ''
        );
    }

    public static function capture_order_created( $order_id, $order = false ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) { return; }
        self::log_order_event(
            $order->get_id(),
            'order-created',
            sprintf( __( 'Order #%s created', 'persiano-hub' ), $order->get_order_number() ),
            sprintf( __( 'Order created with status %s and total %s.', 'persiano-hub' ), wc_get_order_status_name( $order->get_status() ), wp_strip_all_tags( $order->get_formatted_order_total() ) )
        );
    }

    public static function capture_order_status_changed( $order_id, $from, $to, $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) { return; }
        self::log_order_event(
            $order->get_id(),
            'status-' . sanitize_key( $from ) . '-' . sanitize_key( $to ) . '-' . time(),
            __( 'Order status changed', 'persiano-hub' ),
            sprintf( __( 'Status changed from %1$s to %2$s.', 'persiano-hub' ), wc_get_order_status_name( $from ), wc_get_order_status_name( $to ) )
        );
    }

    public static function capture_payment_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) { return; }
        $transaction = $order->get_transaction_id();
        self::log_order_event(
            $order->get_id(),
            'payment-complete-' . ( $transaction ? sanitize_key( $transaction ) : time() ),
            __( 'Payment received', 'persiano-hub' ),
            sprintf( __( 'Payment of %1$s was recorded%2$s.', 'persiano-hub' ), wp_strip_all_tags( $order->get_formatted_order_total() ), $transaction ? ' · Transaction ' . $transaction : '' )
        );
    }

    public static function capture_order_refunded( $order_id, $refund_id ) {
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order instanceof WC_Order ) { return; }
        $amount = $refund instanceof WC_Order_Refund ? wc_price( abs( (float) $refund->get_amount() ), array( 'currency' => $order->get_currency() ) ) : '';
        self::log_order_event(
            $order->get_id(),
            'refund-' . absint( $refund_id ),
            __( 'Refund recorded', 'persiano-hub' ),
            $amount ? sprintf( __( 'A refund of %s was recorded.', 'persiano-hub' ), wp_strip_all_tags( $amount ) ) : __( 'A refund was recorded for this order.', 'persiano-hub' )
        );
    }

    public static function capture_wc_email_context( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin || ! $order instanceof WC_Order || ! is_object( $email ) ) { return; }
        if ( method_exists( $email, 'is_customer_email' ) && ! $email->is_customer_email() ) { return; }
        $recipient = method_exists( $email, 'get_recipient' ) ? $email->get_recipient() : $order->get_billing_email();
        if ( ! $recipient ) { return; }
        self::$pending_wc_email = array(
            'order_id'  => $order->get_id(),
            'email_id'  => isset( $email->id ) ? sanitize_key( $email->id ) : 'woocommerce-email',
            'title'     => method_exists( $email, 'get_title' ) ? wp_strip_all_tags( $email->get_title() ) : __( 'WooCommerce email', 'persiano-hub' ),
            'subject'   => method_exists( $email, 'get_subject' ) ? wp_strip_all_tags( $email->get_subject() ) : '',
            'recipient' => sanitize_email( $recipient ),
        );
    }

    private static function record_captured_wc_email( $mail_data, $status, $error = '' ) {
        if ( empty( self::$pending_wc_email['order_id'] ) ) { return; }
        $pending = self::$pending_wc_email;
        self::$pending_wc_email = array();
        $order = wc_get_order( $pending['order_id'] );
        if ( ! $order instanceof WC_Order ) { return; }

        $to = isset( $mail_data['to'] ) ? $mail_data['to'] : $pending['recipient'];
        $to_string = is_array( $to ) ? implode( ',', $to ) : (string) $to;
        if ( $pending['recipient'] && false === stripos( $to_string, $pending['recipient'] ) ) { return; }

        $subject = ! empty( $mail_data['subject'] ) ? wp_strip_all_tags( $mail_data['subject'] ) : $pending['subject'];
        $contact = $pending['recipient'] ? $pending['recipient'] : $order->get_billing_email();
        $context = self::find_context( $contact, 'email', $order->get_id() );
        self::insert_message(
            array(
                'thread_key'          => $context['thread_key'],
                'channel'             => 'email',
                'direction'           => 'outbound',
                'status'              => sanitize_key( $status ),
                'customer_id'         => $context['customer_id'],
                'order_id'            => $context['order_id'],
                'contact'             => $contact,
                'contact_normalized'  => $context['contact_normalized'],
                'subject'             => $subject,
                'body'                => sprintf( __( 'WooCommerce transactional email: %s', 'persiano-hub' ), $pending['title'] ),
                'provider_message_id' => 'wc-email:' . $pending['email_id'] . ':' . wp_generate_uuid4(),
                'provider_error'      => sanitize_text_field( $error ),
                'created_by'          => 0,
                'read_at'             => current_time( 'mysql' ),
            )
        );
    }

    public static function capture_wp_mail_succeeded( $mail_data ) {
        self::record_captured_wc_email( is_array( $mail_data ) ? $mail_data : array(), 'sent' );
    }

    public static function capture_wp_mail_failed( $error ) {
        $mail_data = is_wp_error( $error ) ? (array) $error->get_error_data() : array();
        $message   = is_wp_error( $error ) ? $error->get_error_message() : __( 'Email sending failed.', 'persiano-hub' );
        self::record_captured_wc_email( $mail_data, 'failed', $message );
    }

    private static function send_email( $context, $subject, $body ) {
        if ( ! is_email( $context['contact_normalized'] ) ) {
            return new WP_Error( 'invalid_email', __( 'Enter a valid customer email address.', 'persiano-hub' ) );
        }

        $settings    = self::settings();
        $order       = $context['order'] instanceof WC_Order ? $context['order'] : false;
        $payment_url = self::payment_url_for_order( $order );
        $first_name  = $order ? trim( $order->get_billing_first_name() ) : '';
        $heading     = $order ? sprintf( __( 'A personal update about order #%s', 'persiano-hub' ), $order->get_order_number() ) : $subject;
        $brand       = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' );
        $primary     = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'primary_color', '#8e2435' ) : '#8e2435';
        $preheader   = $order ? sprintf( __( 'A message from %1$s about order #%2$s.', 'persiano-hub' ), $brand, $order->get_order_number() ) : $subject;

        $message_html = make_clickable( nl2br( esc_html( $body ) ) );
        $content  = '<p style="margin:0 0 18px;color:#4f433c;">' . esc_html( $first_name ? sprintf( __( 'Hi %s,', 'persiano-hub' ), $first_name ) : __( 'Hello,', 'persiano-hub' ) ) . '</p>';
        $content .= '<div style="padding:20px 22px;background:#ffffff;border:1px solid #eadfd2;border-left:5px solid ' . esc_attr( $primary ) . ';border-radius:14px;color:#40352f;font-size:17px;line-height:1.7;">' . wp_kses_post( $message_html ) . '</div>';
        $content .= self::email_order_summary_html( $order, $payment_url );
        $content .= '<p style="margin:24px 0 0;color:#75685f;font-size:14px;line-height:1.6;">' . esc_html__( 'Questions or changes? Reply directly to this email and we’ll help.', 'persiano-hub' ) . '</p>';

        $html_body = class_exists( 'Persiano_Hub_Email_Branding' )
            ? Persiano_Hub_Email_Branding::branded_message( $heading, $content, $preheader )
            : $content;

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        if ( is_email( $settings['email_from_address'] ) ) {
            $headers[] = 'From: ' . sanitize_text_field( $settings['email_from_name'] ) . ' <' . sanitize_email( $settings['email_from_address'] ) . '>';
        }
        $sent = wp_mail( $context['contact_normalized'], $subject, $html_body, $headers );
        return $sent ? array( 'status' => 'sent', 'provider_message_id' => '' ) : new WP_Error( 'email_failed', __( 'WordPress could not send the email.', 'persiano-hub' ) );
    }

    private static function send_sms( $context, $body ) {
        $settings = self::settings();
        if ( ! $context['contact_normalized'] ) {
            return new WP_Error( 'invalid_phone', __( 'Enter a valid customer phone number.', 'persiano-hub' ) );
        }
        if ( self::is_sms_opted_out( $context['contact_normalized'] ) ) {
            return new WP_Error( 'sms_optout', __( 'This phone number has opted out of SMS.', 'persiano-hub' ) );
        }
        if ( empty( $settings['twilio_account_sid'] ) || empty( $settings['twilio_auth_token'] ) ) {
            return new WP_Error( 'twilio_missing', __( 'Twilio credentials are not configured.', 'persiano-hub' ) );
        }
        if ( empty( $settings['twilio_service_sid'] ) && empty( $settings['twilio_from_number'] ) ) {
            return new WP_Error( 'twilio_sender_missing', __( 'Add a Twilio Messaging Service SID or From number.', 'persiano-hub' ) );
        }
        $payload = array(
            'To'             => $context['contact_normalized'],
            'Body'           => trim( $body . ( $settings['sms_signature'] ? "\n" . $settings['sms_signature'] : '' ) ),
            'StatusCallback' => add_query_arg( 'token', rawurlencode( $settings['webhook_token'] ), rest_url( self::REST_NAMESPACE . '/twilio/status' ) ),
        );
        if ( $settings['twilio_service_sid'] ) { $payload['MessagingServiceSid'] = trim( $settings['twilio_service_sid'] ); }
        else { $payload['From'] = self::normalize_phone( $settings['twilio_from_number'] ); }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( trim( $settings['twilio_account_sid'] ) ) . '/Messages.json';
        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 30,
                'headers' => array( 'Authorization' => 'Basic ' . base64_encode( trim( $settings['twilio_account_sid'] ) . ':' . trim( $settings['twilio_auth_token'] ) ) ),
                'body'    => $payload,
            )
        );
        if ( is_wp_error( $response ) ) { return $response; }
        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'twilio_error', sanitize_text_field( $json['message'] ?? __( 'Twilio rejected the message.', 'persiano-hub' ) ) );
        }
        return array(
            'status'              => sanitize_key( $json['status'] ?? 'queued' ),
            'provider_message_id' => sanitize_text_field( $json['sid'] ?? '' ),
        );
    }

    public static function handle_send() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to send customer messages.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_send_message' );
        $channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
        if ( ! in_array( $channel, array( 'email', 'sms' ), true ) ) { $channel = 'email'; }
        $contact = isset( $_POST['contact'] ) ? sanitize_text_field( wp_unslash( $_POST['contact'] ) ) : '';
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
        $raw_body = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
        $override_quiet = ! empty( $_POST['override_quiet'] );
        $context = self::find_context( $contact, $channel, $order_id );

        if ( false !== strpos( $raw_body, '{payment_link}' ) && ! self::payment_url_for_order( $context['order'] ) ) {
            $message = $context['order'] instanceof WC_Order
                ? sprintf( __( 'Order #%s is not currently awaiting online payment, so a payment link cannot be generated.', 'persiano-hub' ), $context['order']->get_order_number() )
                : __( 'Select an unpaid WooCommerce order before using {payment_link}.', 'persiano-hub' );
            self::redirect_notice( 'error', $message, $context['thread_key'] );
        }

        $body = self::expand_message_tokens( $raw_body, $context['order'] );
        if ( ! $body ) { self::redirect_notice( 'error', __( 'Write a message before sending.', 'persiano-hub' ) ); }
        if ( 'email' === $channel && ! $subject ) { $subject = $context['order'] instanceof WC_Order ? sprintf( __( 'Update for order #%s', 'persiano-hub' ), $context['order']->get_order_number() ) : sprintf( __( 'A message from %s', 'persiano-hub' ), class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' ) ); }
        if ( 'sms' === $channel && self::in_quiet_hours() && ! $override_quiet ) {
            self::redirect_notice( 'error', __( 'SMS quiet hours are active. Check “Send during quiet hours” only when the message cannot wait.', 'persiano-hub' ) );
        }

        $result = 'sms' === $channel ? self::send_sms( $context, $body ) : self::send_email( $context, $subject, $body );
        $status = is_wp_error( $result ) ? 'failed' : sanitize_key( $result['status'] ?? 'sent' );
        $message_id = self::insert_message(
            array(
                'thread_key'          => $context['thread_key'],
                'channel'             => $channel,
                'direction'           => 'outbound',
                'status'              => $status,
                'customer_id'         => $context['customer_id'],
                'order_id'            => $context['order_id'],
                'contact'             => $contact,
                'contact_normalized'  => $context['contact_normalized'],
                'subject'             => $subject,
                'body'                => $body,
                'provider_message_id' => is_wp_error( $result ) ? '' : sanitize_text_field( $result['provider_message_id'] ?? '' ),
                'provider_error'      => is_wp_error( $result ) ? $result->get_error_message() : '',
                'read_at'             => current_time( 'mysql' ),
            )
        );
        if ( is_wp_error( $result ) ) {
            self::add_order_note( $context['order_id'], sprintf( '%s message failed: %s', strtoupper( $channel ), $result->get_error_message() ) );
            self::redirect_notice( 'error', $result->get_error_message(), $context['thread_key'] );
        }
        self::add_order_note( $context['order_id'], sprintf( '%s sent to %s from Persiano Messages.', strtoupper( $channel ), $context['contact_normalized'] ) );
        self::redirect_notice( 'success', sprintf( __( '%s sent successfully.', 'persiano-hub' ), strtoupper( $channel ) ), $context['thread_key'], $message_id );
    }

    public static function handle_assign_thread() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to assign correspondence.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_hub_assign_message_thread' );
        $thread_key = isset( $_POST['thread_key'] ) ? sanitize_text_field( wp_unslash( $_POST['thread_key'] ) ) : '';
        $order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order      = wc_get_order( $order_id );
        if ( ! $thread_key || ! $order instanceof WC_Order ) {
            self::redirect_notice( 'error', __( 'Choose a valid WooCommerce order.', 'persiano-hub' ), $thread_key );
        }

        global $wpdb;
        $new_key = self::thread_key( $order->get_customer_id(), '', $order_id );
        $updated = $wpdb->query( $wpdb->prepare(
            'UPDATE ' . self::table_name() . ' SET order_id=%d, customer_id=%d, thread_key=%s, updated_at=%s WHERE thread_key=%s',
            $order_id,
            absint( $order->get_customer_id() ),
            $new_key,
            current_time( 'mysql' ),
            $thread_key
        ) );
        if ( false === $updated ) {
            self::redirect_notice( 'error', __( 'The correspondence could not be assigned.', 'persiano-hub' ), $thread_key );
        }
        self::ensure_order_baseline_events( $order_id );
        self::add_order_note( $order_id, __( 'Previously unassigned correspondence was linked to this order.', 'persiano-hub' ) );
        self::redirect_notice( 'success', __( 'Correspondence assigned to the order.', 'persiano-hub' ), $new_key );
    }

    private static function redirect_notice( $type, $message, $thread = '', $message_id = 0 ) {
        $args = array( 'page' => self::PAGE, 'ph_notice_type' => sanitize_key( $type ), 'ph_notice' => rawurlencode( $message ) );
        if ( $thread ) { $args['thread'] = sanitize_text_field( $thread ); }
        if ( $message_id ) { $args['message_id'] = absint( $message_id ); }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to change message settings.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_save_message_settings' );
        $current = self::settings();
        $auth_token = $current['twilio_auth_token'];
        if ( ! empty( $_POST['clear_twilio_auth_token'] ) ) { $auth_token = ''; }
        elseif ( ! empty( $_POST['twilio_auth_token'] ) ) { $auth_token = sanitize_text_field( wp_unslash( $_POST['twilio_auth_token'] ) ); }
        $settings = array(
            'email_from_name'       => sanitize_text_field( wp_unslash( $_POST['email_from_name'] ?? '' ) ),
            'email_from_address'    => sanitize_email( wp_unslash( $_POST['email_from_address'] ?? '' ) ),
            'twilio_account_sid'    => sanitize_text_field( wp_unslash( $_POST['twilio_account_sid'] ?? '' ) ),
            'twilio_auth_token'     => $auth_token,
            'twilio_service_sid'    => sanitize_text_field( wp_unslash( $_POST['twilio_service_sid'] ?? '' ) ),
            'twilio_from_number'    => self::normalize_phone( wp_unslash( $_POST['twilio_from_number'] ?? '' ) ),
            'webhook_token'         => $current['webhook_token'] ?: wp_generate_password( 32, false, false ),
            'strict_signature'      => ! empty( $_POST['strict_signature'] ) ? 'yes' : 'no',
            'quiet_hours_enabled'   => ! empty( $_POST['quiet_hours_enabled'] ) ? 'yes' : 'no',
            'quiet_start'           => sanitize_text_field( wp_unslash( $_POST['quiet_start'] ?? '20:00' ) ),
            'quiet_end'             => sanitize_text_field( wp_unslash( $_POST['quiet_end'] ?? '09:00' ) ),
            'sms_signature'         => sanitize_text_field( wp_unslash( $_POST['sms_signature'] ?? '' ) ),
            'quick_replies'         => sanitize_textarea_field( wp_unslash( $_POST['quick_replies'] ?? '' ) ),
        );
        if ( ! empty( $_POST['rotate_webhook_token'] ) ) { $settings['webhook_token'] = wp_generate_password( 32, false, false ); }
        update_option( self::OPTION_SETTINGS, $settings, false );
        wp_safe_redirect( add_query_arg( array( 'page' => self::SETTINGS_PAGE, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function verify_webhook( WP_REST_Request $request, $route ) {
        $settings = self::settings();
        $token = sanitize_text_field( (string) $request->get_param( 'token' ) );
        if ( ! $settings['webhook_token'] || ! hash_equals( (string) $settings['webhook_token'], $token ) ) {
            return new WP_Error( 'invalid_webhook_token', 'Invalid webhook token.', array( 'status' => 403 ) );
        }
        if ( 'yes' !== $settings['strict_signature'] ) { return true; }
        $signature = isset( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ) ) : '';
        if ( ! $signature || ! $settings['twilio_auth_token'] ) {
            return new WP_Error( 'missing_twilio_signature', 'Missing Twilio signature.', array( 'status' => 403 ) );
        }
        $url = add_query_arg( 'token', rawurlencode( $settings['webhook_token'] ), rest_url( self::REST_NAMESPACE . $route ) );
        $params = $request->get_body_params();
        ksort( $params, SORT_STRING );
        $data = $url;
        foreach ( $params as $key => $value ) {
            if ( is_array( $value ) ) { continue; }
            $data .= $key . $value;
        }
        $expected = base64_encode( hash_hmac( 'sha1', $data, $settings['twilio_auth_token'], true ) );
        return hash_equals( $expected, $signature ) ? true : new WP_Error( 'invalid_twilio_signature', 'Invalid Twilio signature.', array( 'status' => 403 ) );
    }

    public static function receive_twilio_message( WP_REST_Request $request ) {
        $verified = self::verify_webhook( $request, '/twilio/inbound' );
        if ( is_wp_error( $verified ) ) { return $verified; }
        $from = self::normalize_phone( $request->get_param( 'From' ) );
        $to = self::normalize_phone( $request->get_param( 'To' ) );
        $body = sanitize_textarea_field( (string) $request->get_param( 'Body' ) );
        $sid = sanitize_text_field( (string) $request->get_param( 'MessageSid' ) );
        $context = self::find_context( $from, 'sms', 0 );
        $keyword = strtoupper( trim( $body ) );
        if ( in_array( $keyword, array( 'STOP','STOPALL','UNSUBSCRIBE','CANCEL','END','QUIT' ), true ) ) { self::set_sms_optout( $from, true ); }
        if ( in_array( $keyword, array( 'START','UNSTOP' ), true ) ) { self::set_sms_optout( $from, false ); }
        $media = array();
        $num_media = absint( $request->get_param( 'NumMedia' ) );
        for ( $i = 0; $i < $num_media; $i++ ) {
            $media[] = array(
                'url'  => esc_url_raw( (string) $request->get_param( 'MediaUrl' . $i ) ),
                'type' => sanitize_text_field( (string) $request->get_param( 'MediaContentType' . $i ) ),
            );
        }
        self::insert_message(
            array(
                'thread_key'          => $context['thread_key'],
                'channel'             => 'sms',
                'direction'           => 'inbound',
                'status'              => 'received',
                'customer_id'         => $context['customer_id'],
                'order_id'            => $context['order_id'],
                'contact'             => $from,
                'contact_normalized'  => $from,
                'body'                => $body,
                'provider_message_id' => $sid,
                'media_json'          => $media ? wp_json_encode( $media ) : '',
                'created_by'          => 0,
                'read_at'             => null,
            )
        );
        self::add_order_note( $context['order_id'], sprintf( 'SMS received from %s: %s', $from, wp_trim_words( $body, 18 ) ) );
        if ( class_exists( 'Persiano_Hub_Notifications' ) ) {
            Persiano_Hub_Notifications::add_system_notification(
                'customer_sms',
                sprintf( 'New SMS from %s', $from ),
                wp_trim_words( $body, 24 ),
                add_query_arg( array( 'page' => self::PAGE, 'thread' => $context['thread_key'] ), admin_url( 'admin.php' ) )
            );
        }
        $response = new WP_REST_Response( '<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200 );
        $response->header( 'Content-Type', 'text/xml; charset=UTF-8' );
        return $response;
    }

    public static function receive_twilio_status( WP_REST_Request $request ) {
        $verified = self::verify_webhook( $request, '/twilio/status' );
        if ( is_wp_error( $verified ) ) { return $verified; }
        global $wpdb;
        $sid = sanitize_text_field( (string) $request->get_param( 'MessageSid' ) );
        $status = sanitize_key( (string) $request->get_param( 'MessageStatus' ) );
        $error = sanitize_text_field( trim( (string) $request->get_param( 'ErrorCode' ) . ' ' . (string) $request->get_param( 'ErrorMessage' ) ) );
        if ( $sid ) {
            $wpdb->update(
                self::table_name(),
                array( 'status' => $status ?: 'updated', 'provider_error' => $error, 'updated_at' => current_time( 'mysql' ) ),
                array( 'provider_message_id' => $sid ),
                array( '%s','%s','%s' ),
                array( '%s' )
            );
        }
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    private static function get_threads( $channel = '', $search = '', $unread = false ) {
        global $wpdb;
        $table = self::table_name();
        $where = array( '1=1' );
        $args  = array();

        if ( in_array( $channel, array( 'email', 'sms', 'system' ), true ) ) {
            $where[] = "EXISTS (SELECT 1 FROM {$table} cf WHERE cf.thread_key=m.thread_key AND cf.channel=%s)";
            $args[] = $channel;
        }
        if ( $unread ) {
            $where[] = "EXISTS (SELECT 1 FROM {$table} uf WHERE uf.thread_key=m.thread_key AND uf.direction='inbound' AND uf.read_at IS NULL)";
        }
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = "EXISTS (SELECT 1 FROM {$table} sf WHERE sf.thread_key=m.thread_key AND (sf.contact LIKE %s OR sf.body LIKE %s OR sf.subject LIKE %s OR CAST(sf.order_id AS CHAR) LIKE %s))";
            array_push( $args, $like, $like, $like, $like );
        }

        $sql = "SELECT m.*, stats.channels, stats.message_count, stats.unread_count
            FROM {$table} m
            LEFT JOIN {$table} newer ON newer.thread_key=m.thread_key
                AND (newer.created_at>m.created_at OR (newer.created_at=m.created_at AND newer.id>m.id))
            INNER JOIN (
                SELECT thread_key, GROUP_CONCAT(DISTINCT channel ORDER BY channel SEPARATOR ',') channels,
                    COUNT(*) message_count,
                    SUM(CASE WHEN direction='inbound' AND read_at IS NULL THEN 1 ELSE 0 END) unread_count
                FROM {$table}
                GROUP BY thread_key
            ) stats ON stats.thread_key=m.thread_key
            WHERE newer.id IS NULL AND " . implode( ' AND ', $where ) . '
            ORDER BY m.created_at DESC, m.id DESC LIMIT 150';

        return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql );
    }

    private static function get_thread_messages( $thread_key ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE thread_key=%s ORDER BY created_at ASC, id ASC LIMIT 500', $thread_key ) );
    }

    private static function mark_thread_read( $thread_key ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table_name() . " SET read_at=%s, updated_at=%s WHERE thread_key=%s AND direction='inbound' AND read_at IS NULL", current_time( 'mysql' ), current_time( 'mysql' ), $thread_key ) );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to view customer correspondence.', 'persiano-hub' ) );
        }

        $channel         = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
        $search          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $unread          = ! empty( $_GET['unread'] );
        $thread_key      = isset( $_GET['thread'] ) ? sanitize_text_field( wp_unslash( $_GET['thread'] ) ) : '';
        $prefill_order   = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $prefill_channel = isset( $_GET['compose'] ) ? sanitize_key( wp_unslash( $_GET['compose'] ) ) : '';

        if ( $prefill_order ) {
            $prefill_order_object = wc_get_order( $prefill_order );
            if ( $prefill_order_object instanceof WC_Order ) {
                self::ensure_order_baseline_events( $prefill_order );
                $thread_key = self::thread_key( $prefill_order_object->get_customer_id(), '', $prefill_order );
            }
        }

        if ( $thread_key ) { self::mark_thread_read( $thread_key ); }
        $threads  = self::get_threads( $channel, $search, $unread );
        $messages = $thread_key ? self::get_thread_messages( $thread_key ) : array();
        $active   = $messages ? end( $messages ) : null;
        if ( $messages ) { reset( $messages ); }

        $active_order_id = $prefill_order ?: ( $active && $active->order_id ? absint( $active->order_id ) : 0 );
        $active_order    = $active_order_id ? wc_get_order( $active_order_id ) : false;
        if ( $active_order instanceof WC_Order ) {
            self::ensure_order_baseline_events( $active_order->get_id() );
            $messages = self::get_thread_messages( self::thread_key( $active_order->get_customer_id(), '', $active_order->get_id() ) );
            $thread_key = self::thread_key( $active_order->get_customer_id(), '', $active_order->get_id() );
            $active = $messages ? end( $messages ) : null;
            if ( $messages ) { reset( $messages ); }
        }

        $settings      = self::settings();
        $quick_replies = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $settings['quick_replies'] ) ) );
        $order_label   = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::get( 'order_label', 'Order' ) : 'Order';
        $orders_label  = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::get( 'orders_label', 'Orders' ) : 'Orders';
        $primary       = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'primary_color', '#8e2435' ) : '#8e2435';
        $prefill_email = $active_order instanceof WC_Order ? $active_order->get_billing_email() : '';
        $prefill_phone = $active_order instanceof WC_Order ? $active_order->get_billing_phone() : '';
        $fallback_contact = $active ? $active->contact_normalized : '';
        ?>
        <div class="wrap ph-messages-wrap">
            <h1><?php echo esc_html( sprintf( __( '%s Correspondence', 'persiano-hub' ), $order_label ) ); ?></h1>
            <p class="description"><?php echo esc_html( sprintf( __( 'Each %1$s is one case. Email, SMS and system activity appear together in chronological order. Messages without a reliable %1$s match remain Unassigned.', 'persiano-hub' ), strtolower( $order_label ) ) ); ?></p>
            <?php if ( isset( $_GET['ph_notice'] ) ) : $type = 'error' === ( $_GET['ph_notice_type'] ?? '' ) ? 'notice-error' : 'notice-success'; ?><div class="notice <?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['ph_notice'] ) ) ) ); ?></p></div><?php endif; ?>
            <div class="ph-message-toolbar">
                <form method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
                    <select name="channel"><option value=""><?php esc_html_e( 'All channels', 'persiano-hub' ); ?></option><option value="email" <?php selected( $channel, 'email' ); ?>>Email</option><option value="sms" <?php selected( $channel, 'sms' ); ?>>SMS</option><option value="system" <?php selected( $channel, 'system' ); ?>>System</option></select>
                    <label><input type="checkbox" name="unread" value="1" <?php checked( $unread ); ?>> <?php esc_html_e( 'Unread', 'persiano-hub' ); ?></label>
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr( sprintf( __( 'Search %s, contact or message', 'persiano-hub' ), strtolower( $order_label ) ) ); ?>">
                    <button class="button"><?php esc_html_e( 'Filter', 'persiano-hub' ); ?></button>
                </form>
                <div class="ph-toolbar-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE ) ); ?>"><?php esc_html_e( 'Message Settings', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=persiano-hub-business-profile' ) ); ?>"><?php esc_html_e( 'Business Profile', 'persiano-hub' ); ?></a></div>
            </div>
            <div class="ph-message-grid">
                <aside class="ph-thread-list">
                    <div class="ph-thread-list-head"><strong><?php echo esc_html( $orders_label ); ?></strong><span><?php echo esc_html( count( $threads ) ); ?></span></div>
                    <?php if ( ! $threads ) : ?><p class="ph-empty"><?php esc_html_e( 'No correspondence yet.', 'persiano-hub' ); ?></p><?php endif; ?>
                    <?php foreach ( $threads as $thread ) :
                        $thread_order = $thread->order_id ? wc_get_order( $thread->order_id ) : false;
                        $url = add_query_arg( array( 'page' => self::PAGE, 'thread' => $thread->thread_key ), admin_url( 'admin.php' ) );
                        $is_unread = ! empty( $thread->unread_count );
                        $channels = array_filter( explode( ',', (string) $thread->channels ) );
                        $customer_name = $thread_order instanceof WC_Order ? trim( $thread_order->get_formatted_billing_full_name() ) : '';
                        $title = $thread_order instanceof WC_Order
                            ? sprintf( '%s #%s', $order_label, $thread_order->get_order_number() )
                            : __( 'Unassigned', 'persiano-hub' );
                        $contact_label = $customer_name ? $customer_name : ( $thread->contact_normalized ?: $thread->contact );
                        ?>
                        <a class="ph-thread <?php echo $thread_key === $thread->thread_key ? 'is-active' : ''; ?> <?php echo $is_unread ? 'is-unread' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
                            <div class="ph-thread-title"><strong><?php echo esc_html( $title ); ?></strong><?php if ( $is_unread ) : ?><span class="ph-unread-count"><?php echo esc_html( absint( $thread->unread_count ) ); ?></span><?php endif; ?></div>
                            <div class="ph-thread-contact"><?php echo esc_html( $contact_label ); ?></div>
                            <div class="ph-channel-row"><?php foreach ( $channels as $thread_channel ) : ?><span class="ph-channel ph-channel-<?php echo esc_attr( $thread_channel ); ?>"><?php echo esc_html( strtoupper( $thread_channel ) ); ?></span><?php endforeach; ?><span class="ph-count"><?php echo esc_html( absint( $thread->message_count ) ); ?> entries</span></div>
                            <small><?php echo esc_html( wp_trim_words( $thread->body ?: $thread->subject, 14 ) ); ?></small>
                            <time><?php echo esc_html( mysql2date( 'M j, g:i a', $thread->created_at ) ); ?></time>
                        </a>
                    <?php endforeach; ?>
                </aside>
                <main class="ph-conversation">
                    <?php if ( $messages ) : ?>
                        <div class="ph-conversation-head">
                            <div>
                                <?php if ( $active_order instanceof WC_Order ) : ?>
                                    <h2><?php echo esc_html( sprintf( '%s #%s', $order_label, $active_order->get_order_number() ) ); ?></h2>
                                    <p><?php echo esc_html( trim( $active_order->get_formatted_billing_full_name() ) ); ?> · <?php echo esc_html( wc_get_order_status_name( $active_order->get_status() ) ); ?> · <?php echo wp_kses_post( $active_order->get_formatted_order_total() ); ?></p>
                                <?php else : ?>
                                    <h2><?php esc_html_e( 'Unassigned correspondence', 'persiano-hub' ); ?></h2>
                                    <p><?php echo esc_html( $active->contact_normalized ?: $active->contact ); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if ( $active_order instanceof WC_Order ) : ?><a class="button" href="<?php echo esc_url( $active_order->get_edit_order_url() ); ?>"><?php echo esc_html( sprintf( __( 'Open %s', 'persiano-hub' ), $order_label ) ); ?></a><?php endif; ?>
                        </div>
                        <?php if ( ! $active_order instanceof WC_Order ) : ?>
                            <form class="ph-assign" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="persiano_hub_assign_message_thread"><input type="hidden" name="thread_key" value="<?php echo esc_attr( $thread_key ); ?>"><?php wp_nonce_field( 'persiano_hub_assign_message_thread' ); ?>
                                <strong><?php echo esc_html( sprintf( __( 'Assign this history to a %s', 'persiano-hub' ), strtolower( $order_label ) ) ); ?></strong>
                                <input type="number" min="1" name="order_id" placeholder="<?php echo esc_attr( $order_label . ' ID' ); ?>" required><button class="button button-secondary"><?php esc_html_e( 'Assign and merge', 'persiano-hub' ); ?></button>
                            </form>
                        <?php endif; ?>
                        <div class="ph-bubbles">
                            <?php foreach ( $messages as $message ) :
                                $is_system = 'system' === $message->channel || 'system' === $message->direction;
                                ?>
                                <?php if ( $is_system ) : ?>
                                    <article class="ph-system-event"><span class="dashicons dashicons-marker"></span><div><?php if ( $message->subject ) : ?><strong><?php echo esc_html( $message->subject ); ?></strong><?php endif; ?><p><?php echo nl2br( esc_html( $message->body ) ); ?></p><footer><?php echo esc_html( mysql2date( 'M j, g:i a', $message->created_at ) ); ?></footer></div></article>
                                <?php else : ?>
                                    <article class="ph-bubble <?php echo 'outbound' === $message->direction ? 'is-outbound' : 'is-inbound'; ?>">
                                        <div class="ph-bubble-top"><span class="ph-channel ph-channel-<?php echo esc_attr( $message->channel ); ?>"><?php echo esc_html( strtoupper( $message->channel ) ); ?></span><span><?php echo esc_html( ucfirst( $message->direction ) ); ?></span></div>
                                        <?php if ( $message->subject ) : ?><strong class="ph-subject"><?php echo esc_html( $message->subject ); ?></strong><?php endif; ?>
                                        <div><?php echo nl2br( esc_html( $message->body ) ); ?></div>
                                        <?php if ( $message->media_json ) : ?><small><?php esc_html_e( 'Media attached', 'persiano-hub' ); ?></small><?php endif; ?>
                                        <footer><?php echo esc_html( $message->status . ' · ' . mysql2date( 'M j, g:i a', $message->created_at ) ); ?><?php if ( $message->provider_error ) : ?> · <span class="ph-error"><?php echo esc_html( $message->provider_error ); ?></span><?php endif; ?></footer>
                                    </article>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?><div class="ph-empty-state"><h2><?php echo esc_html( sprintf( __( 'Start %s correspondence', 'persiano-hub' ), strtolower( $order_label ) ) ); ?></h2><p><?php echo esc_html( sprintf( __( 'Open a %s from WooCommerce or enter a contact and %s ID below.', 'persiano-hub' ), strtolower( $order_label ), strtolower( $order_label ) ) ); ?></p></div><?php endif; ?>
                    <form class="ph-compose" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-email="<?php echo esc_attr( $prefill_email ); ?>" data-phone="<?php echo esc_attr( $prefill_phone ); ?>">
                        <input type="hidden" name="action" value="persiano_hub_send_message"><?php wp_nonce_field( 'persiano_hub_send_message' ); ?>
                        <div class="ph-compose-row">
                            <label><?php esc_html_e( 'Channel', 'persiano-hub' ); ?><select name="channel" id="ph-message-channel"><option value="email" <?php selected( $prefill_channel ?: ( $active && in_array( $active->channel, array( 'email', 'sms' ), true ) ? $active->channel : 'email' ), 'email' ); ?>>Email</option><option value="sms" <?php selected( $prefill_channel ?: ( $active && in_array( $active->channel, array( 'email', 'sms' ), true ) ? $active->channel : '' ), 'sms' ); ?>>SMS</option></select></label>
                            <label><?php esc_html_e( 'Customer contact', 'persiano-hub' ); ?><input required name="contact" id="ph-message-contact" value="<?php echo esc_attr( $prefill_channel === 'sms' ? $prefill_phone : ( $prefill_email ?: $fallback_contact ) ); ?>" placeholder="email@example.com or +16045551234"></label>
                            <label><?php echo esc_html( $order_label . ' ID' ); ?><input type="number" min="0" name="order_id" value="<?php echo esc_attr( $active_order_id ); ?>"></label>
                        </div>
                        <label class="ph-email-subject"><?php esc_html_e( 'Subject', 'persiano-hub' ); ?><input name="subject" value="" placeholder="<?php echo esc_attr( $order_label . ' update' ); ?>"></label>
                        <?php if ( $quick_replies ) : ?><label><?php esc_html_e( 'Quick reply', 'persiano-hub' ); ?><select id="ph-quick-reply"><option value=""><?php esc_html_e( 'Choose a saved reply…', 'persiano-hub' ); ?></option><?php foreach ( $quick_replies as $reply ) : ?><option value="<?php echo esc_attr( $reply ); ?>"><?php echo esc_html( wp_trim_words( $reply, 10 ) ); ?></option><?php endforeach; ?></select></label><?php endif; ?>
                        <label><?php esc_html_e( 'Message', 'persiano-hub' ); ?><textarea required name="body" id="ph-message-body" rows="6" placeholder="<?php esc_attr_e( 'Write a personal message…', 'persiano-hub' ); ?>"></textarea></label>
                        <div class="ph-compose-actions"><label><input type="checkbox" name="override_quiet" value="1"> <?php esc_html_e( 'Send during SMS quiet hours when necessary', 'persiano-hub' ); ?></label><button class="button button-primary button-large"><?php esc_html_e( 'Send message', 'persiano-hub' ); ?></button></div>
                    </form>
                </main>
            </div>
        </div>
        <style>
        .ph-messages-wrap{max-width:1500px;--ph-primary:<?php echo esc_attr( $primary ); ?>}.ph-message-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin:18px 0}.ph-message-toolbar form,.ph-toolbar-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.ph-message-grid{display:grid;grid-template-columns:minmax(300px,390px) minmax(0,1fr);min-height:720px;background:#fff;border:1px solid #dcdcde;border-radius:14px;overflow:hidden}.ph-thread-list{border-right:1px solid #e3e3e3;max-height:920px;overflow:auto;background:#f7f7f8}.ph-thread-list-head{position:sticky;top:0;z-index:2;display:flex;justify-content:space-between;padding:13px 16px;background:#f0f0f1;border-bottom:1px solid #dcdcde}.ph-thread{display:block;padding:15px;text-decoration:none;color:#1d2327;border-bottom:1px solid #e3e3e3}.ph-thread:hover,.ph-thread.is-active{background:#fff}.ph-thread.is-unread{box-shadow:inset 4px 0 var(--ph-primary)}.ph-thread-title{display:flex;justify-content:space-between;gap:8px}.ph-thread-contact{margin:3px 0 8px;color:#50575e}.ph-channel-row{display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-bottom:7px}.ph-channel{display:inline-block;padding:3px 7px;border-radius:999px;background:#eee;font-size:9px;font-weight:800;letter-spacing:.07em}.ph-channel-email{background:#e8f1fb;color:#155a91}.ph-channel-sms{background:#f6e9ed;color:#8e2435}.ph-channel-system{background:#ececec;color:#50575e}.ph-count{font-size:11px;color:#787c82}.ph-unread-count{min-width:19px;height:19px;padding:0 5px;border-radius:999px;background:var(--ph-primary);color:#fff;text-align:center;font-size:11px;line-height:19px}.ph-thread small{display:block;color:#646970}.ph-thread time{display:block;margin-top:6px;font-size:11px;color:#8c8f94}.ph-conversation{display:flex;flex-direction:column;min-width:0}.ph-conversation-head{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid #e3e3e3}.ph-conversation-head h2,.ph-conversation-head p{margin:0}.ph-assign{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 22px;background:#fff8e5;border-bottom:1px solid #ead9a2}.ph-assign input{width:140px}.ph-bubbles{flex:1;padding:22px;overflow:auto;background:#fbfaf8}.ph-bubble{max-width:72%;padding:12px 15px;margin:0 0 12px;border-radius:14px;background:#fff;border:1px solid #e0ddd9}.ph-bubble.is-outbound{margin-left:auto;background:#f4e9eb;border-color:#e4cbd0}.ph-bubble-top{display:flex;gap:8px;align-items:center;margin-bottom:8px;font-size:11px;color:#72777c}.ph-subject{display:block;margin-bottom:7px}.ph-bubble footer,.ph-system-event footer{margin-top:7px;font-size:11px;color:#72777c}.ph-system-event{display:flex;justify-content:center;gap:9px;max-width:720px;margin:16px auto;padding:10px 14px;border-radius:12px;background:#f0f0f1;color:#50575e;text-align:left}.ph-system-event p{margin:3px 0}.ph-system-event .dashicons{color:var(--ph-primary)}.ph-error{color:#b32d2e}.ph-compose{padding:18px 22px;border-top:1px solid #e3e3e3;display:grid;gap:12px}.ph-compose-row{display:grid;grid-template-columns:140px minmax(220px,1fr) 140px;gap:10px}.ph-compose label{display:grid;gap:4px;font-weight:600}.ph-compose-actions{display:flex;justify-content:space-between;align-items:center;gap:12px}.ph-compose-actions label{display:block;font-weight:400}.ph-empty,.ph-empty-state{padding:24px;color:#646970}@media(max-width:900px){.ph-message-grid{grid-template-columns:1fr}.ph-thread-list{max-height:300px;border-right:0;border-bottom:1px solid #e3e3e3}.ph-compose-row{grid-template-columns:1fr}.ph-bubble{max-width:90%}.ph-compose-actions{align-items:flex-start;flex-direction:column}.ph-message-toolbar{align-items:flex-start;flex-direction:column}}
        </style>
        <script>document.addEventListener('DOMContentLoaded',function(){
const form=document.querySelector('.ph-compose'),channel=document.getElementById('ph-message-channel'),contact=document.getElementById('ph-message-contact'),orderId=document.getElementById('ph-message-order-id'),subject=document.querySelector('.ph-email-subject'),quick=document.getElementById('ph-quick-reply'),body=document.getElementById('ph-message-body');
let timer=null;
function setContact(){if(!form||!channel||!contact)return;const next=channel.value==='sms'?form.dataset.phone:form.dataset.email;contact.value=next||'';}
function toggle(){if(subject)subject.style.display=channel&&channel.value==='sms'?'none':'grid';setContact();}
function lookup(){if(!orderId||!form)return;const id=parseInt(orderId.value||'0',10);if(!id){form.dataset.email='';form.dataset.phone='';setContact();return;}const data=new URLSearchParams({action:'persiano_hub_order_contact',nonce:'<?php echo esc_js( wp_create_nonce( 'persiano_hub_order_contact' ) ); ?>',order_id:String(id)});fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString()}).then(r=>r.json()).then(r=>{if(r.success&&r.data){form.dataset.email=r.data.email||'';form.dataset.phone=r.data.phone||'';setContact();contact.setCustomValidity('');}else{form.dataset.email='';form.dataset.phone='';contact.value='';contact.setCustomValidity((r.data&&r.data.message)||'Order not found');}}).catch(()=>{});}
if(channel){channel.addEventListener('change',toggle);toggle();}
if(orderId){orderId.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(lookup,350)});orderId.addEventListener('change',lookup);}
if(quick&&body){quick.addEventListener('change',function(){if(this.value){body.value=this.value;body.focus();}})}
});</script>
        <?php
    }

    public static function render_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'You do not have permission to change message settings.', 'persiano-hub' ) ); }
        $s = self::settings();
        $inbound = add_query_arg( 'token', rawurlencode( $s['webhook_token'] ), rest_url( self::REST_NAMESPACE . '/twilio/inbound' ) );
        $status = add_query_arg( 'token', rawurlencode( $s['webhook_token'] ), rest_url( self::REST_NAMESPACE . '/twilio/status' ) );
        ?>
        <div class="wrap"><h1><?php esc_html_e( 'Customer Message Settings', 'persiano-hub' ); ?></h1><?php if ( ! empty( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif; ?>
        <p>Configure one-to-one email and SMS correspondence. Persiano Messages does not add contacts to marketing lists or send bulk campaigns.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_save_message_settings"><?php wp_nonce_field( 'persiano_hub_save_message_settings' ); ?>
        <h2>Email</h2><table class="form-table"><tr><th>From name</th><td><input class="regular-text" name="email_from_name" value="<?php echo esc_attr( $s['email_from_name'] ); ?>"></td></tr><tr><th>From address</th><td><input class="regular-text" type="email" name="email_from_address" value="<?php echo esc_attr( $s['email_from_address'] ); ?>"><p class="description">Your SMTP provider must allow this From address.</p></td></tr></table>
        <h2>Twilio SMS</h2><table class="form-table"><tr><th>Account SID</th><td><input class="regular-text" name="twilio_account_sid" value="<?php echo esc_attr( $s['twilio_account_sid'] ); ?>" autocomplete="off"></td></tr><tr><th>Auth Token</th><td><input class="regular-text" type="password" name="twilio_auth_token" value="" autocomplete="new-password" placeholder="<?php echo $s['twilio_auth_token'] ? 'Saved — enter only to replace' : 'Paste Auth Token'; ?>"><?php if ( $s['twilio_auth_token'] ) : ?><p><label><input type="checkbox" name="clear_twilio_auth_token" value="1"> Remove saved token</label></p><?php endif; ?></td></tr><tr><th>Messaging Service SID</th><td><input class="regular-text" name="twilio_service_sid" value="<?php echo esc_attr( $s['twilio_service_sid'] ); ?>" placeholder="MG…"><p class="description">Preferred. Until a Messaging Service is available, leave this blank and use the Twilio From number below.</p></td></tr><tr><th>Twilio From number</th><td><input class="regular-text" name="twilio_from_number" value="<?php echo esc_attr( $s['twilio_from_number'] ); ?>" placeholder="+16045551234"></td></tr><tr><th>SMS signature</th><td><input class="regular-text" name="sms_signature" value="<?php echo esc_attr( $s['sms_signature'] ); ?>" placeholder="<?php echo esc_attr( class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' ) ); ?>"><p class="description">Optional. Added on a new line to outgoing SMS.</p></td></tr><tr><th>Quiet hours</th><td><label><input type="checkbox" name="quiet_hours_enabled" value="1" <?php checked( $s['quiet_hours_enabled'], 'yes' ); ?>> Warn and block routine SMS during quiet hours</label><p><input type="time" name="quiet_start" value="<?php echo esc_attr( $s['quiet_start'] ); ?>"> to <input type="time" name="quiet_end" value="<?php echo esc_attr( $s['quiet_end'] ); ?>"> (<?php echo esc_html( wp_timezone_string() ); ?>)</p></td></tr><tr><th>Strict signature validation</th><td><label><input type="checkbox" name="strict_signature" value="1" <?php checked( $s['strict_signature'], 'yes' ); ?>> Require a valid X-Twilio-Signature in addition to the private webhook token</label><p class="description">Enable after the webhook works through your hosting proxy.</p></td></tr><tr><th>Quick replies</th><td><textarea class="large-text" rows="7" name="quick_replies"><?php echo esc_textarea( $s['quick_replies'] ); ?></textarea><p class="description">One reply per line. Available tokens: {first_name}, {order_number}, {order_total}, {payment_link}.</p></td></tr></table>
        <h2>Webhook URLs</h2><table class="form-table"><tr><th>Incoming message webhook</th><td><input class="large-text code" readonly value="<?php echo esc_attr( $inbound ); ?>"><p class="description">Set this as the HTTP POST webhook for incoming messages on the Twilio number or Messaging Service.</p></td></tr><tr><th>Delivery status callback</th><td><input class="large-text code" readonly value="<?php echo esc_attr( $status ); ?>"><p class="description">Outgoing messages also include this callback automatically.</p></td></tr><tr><th>Rotate private webhook token</th><td><label><input type="checkbox" name="rotate_webhook_token" value="1"> Generate new URLs when saving</label><p class="description">After rotating, update the URLs in Twilio.</p></td></tr></table>
        <?php submit_button(); ?></form></div>
        <?php
    }

    public static function add_order_meta_box() {
        $screens = array( 'shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) { $screens[] = wc_get_page_screen_id( 'shop-order' ); }
        foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
            add_meta_box( 'persiano-customer-messages', __( 'Order Correspondence', 'persiano-hub' ), array( __CLASS__, 'render_order_meta_box' ), $screen, 'side', 'default' );
        }
    }

    public static function render_order_meta_box( $object ) {
        $order = $object instanceof WC_Order ? $object : wc_get_order( is_object( $object ) ? $object->ID : $object );
        if ( ! $order ) { return; }
        self::ensure_order_baseline_events( $order->get_id() );
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE order_id=%d ORDER BY id DESC LIMIT 8', $order->get_id() ) );
        $thread = self::thread_key( $order->get_customer_id(), '', $order->get_id() );
        $timeline_url = add_query_arg( array( 'page' => self::PAGE, 'thread' => $thread, 'order_id' => $order->get_id() ), admin_url( 'admin.php' ) );
        echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'order_id' => $order->get_id(), 'compose' => 'email' ), admin_url( 'admin.php' ) ) ) . '">Email customer</a> <a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'order_id' => $order->get_id(), 'compose' => 'sms' ), admin_url( 'admin.php' ) ) ) . '">SMS customer</a></p>';
        echo '<p><a href="' . esc_url( $timeline_url ) . '"><strong>' . esc_html__( 'Open complete correspondence history', 'persiano-hub' ) . '</strong></a></p>';
        if ( ! $rows ) { echo '<p class="description">No linked correspondence yet.</p>'; return; }
        echo '<ol style="margin-left:18px">';
        foreach ( $rows as $row ) {
            $label = 'system' === $row->channel ? __( 'SYSTEM', 'persiano-hub' ) : strtoupper( $row->channel ) . ' ' . $row->direction;
            echo '<li style="margin-bottom:9px"><strong>' . esc_html( $label ) . '</strong><br><small>' . esc_html( mysql2date( 'M j, g:i a', $row->created_at ) . ' · ' . $row->status ) . '</small><br>' . esc_html( wp_trim_words( $row->subject ? $row->subject . ' — ' . $row->body : $row->body, 12 ) ) . '</li>';
        }
        echo '</ol>';
    }
}
