<?php
/**
 * First-party marketing analytics and email campaign delivery.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Marketing {
    const TABLE_SUFFIX = 'persiano_marketing_events';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_tracking' ), 30 );
        add_action( 'wp_ajax_nopriv_persiano_hub_track_event', array( __CLASS__, 'track_event_ajax' ) );
        add_action( 'wp_ajax_persiano_hub_track_event', array( __CLASS__, 'track_event_ajax' ) );

        add_action( 'admin_post_nopriv_persiano_hub_track_click', array( __CLASS__, 'track_click_redirect' ) );
        add_action( 'admin_post_persiano_hub_track_click', array( __CLASS__, 'track_click_redirect' ) );
        add_action( 'admin_post_nopriv_persiano_hub_email_open', array( __CLASS__, 'email_open_pixel' ) );
        add_action( 'admin_post_persiano_hub_email_open', array( __CLASS__, 'email_open_pixel' ) );

        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'record_order_attribution' ), 40, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'record_store_api_order_attribution' ), 40, 1 );
        add_action( 'woocommerce_order_refunded', array( __CLASS__, 'record_refund_attribution' ), 40, 2 );

        add_action( 'admin_post_persiano_hub_marketing_export', array( __CLASS__, 'export_csv' ) );
        add_action( 'admin_post_persiano_hub_preview_email', array( __CLASS__, 'preview_email_admin' ) );
        add_action( 'admin_post_persiano_hub_send_test_email', array( __CLASS__, 'send_test_email_admin' ) );
    }


    public static function admin_assets( $hook ) {
        if ( false === strpos( (string) $hook, 'persiano-marketing-analytics' ) && false === strpos( (string) $hook, 'persiano-mailing-list' ) ) {
            return;
        }
        wp_enqueue_style( 'persiano-hub-publishing-admin', PERSIANO_HUB_URL . 'assets/css/publishing-admin.css', array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-hub-marketing-admin', PERSIANO_HUB_URL . 'assets/css/marketing-admin.css', array( 'persiano-hub-publishing-admin' ), PERSIANO_HUB_VERSION );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(40) NOT NULL,
            session_id varchar(80) NOT NULL DEFAULT '',
            visitor_hash varchar(64) NOT NULL DEFAULT '',
            campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(50) NOT NULL DEFAULT '',
            source varchar(100) NOT NULL DEFAULT 'direct',
            medium varchar(100) NOT NULL DEFAULT '',
            campaign_key varchar(190) NOT NULL DEFAULT '',
            action_name varchar(100) NOT NULL DEFAULT '',
            url text NULL,
            referrer text NULL,
            value decimal(12,2) NOT NULL DEFAULT 0.00,
            meta_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY campaign_id (campaign_id),
            KEY object_id (object_id),
            KEY source (source),
            KEY created_at (created_at),
            KEY session_id (session_id)
        ) {$charset_collate};";
        dbDelta( $sql );
    }

    public static function active_subscriber_count() {
        global $wpdb;
        if ( ! class_exists( 'Persiano_Hub_Newsletter' ) ) {
            return 0;
        }
        $table = Persiano_Hub_Newsletter::table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'subscribed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function enqueue_tracking() {
        if ( is_admin() || ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) ) {
            return;
        }

        $post_id = get_queried_object_id();
        $campaign_id = 0;
        if ( $post_id ) {
            $campaign_id = absint( get_post_meta( $post_id, '_persiano_campaign_id', true ) );
            if ( ! $campaign_id && 'product' === get_post_type( $post_id ) ) {
                $campaign_id = absint( get_post_meta( $post_id, '_persiano_active_availability_campaign', true ) );
                if ( ! $campaign_id ) {
                    $campaign_id = absint( get_post_meta( $post_id, '_persiano_campaign_id', true ) );
                }
            }
        }

        wp_enqueue_script(
            'persiano-hub-marketing-tracking',
            PERSIANO_HUB_URL . 'assets/js/marketing-tracking.js',
            array(),
            PERSIANO_HUB_VERSION,
            true
        );
        wp_localize_script(
            'persiano-hub-marketing-tracking',
            'PersianoMarketing',
            array(
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'objectId'     => absint( $post_id ),
                'objectType'   => $post_id ? sanitize_key( get_post_type( $post_id ) ) : '',
                'campaignId'   => $campaign_id,
                'campaignKey'  => $campaign_id ? sanitize_title( get_post_field( 'post_name', $campaign_id ) ) : '',
                'cookiePrefix' => 'phm_',
            )
        );
    }

    public static function track_event_ajax() {
        $event_type = isset( $_POST['event_type'] ) ? sanitize_key( wp_unslash( $_POST['event_type'] ) ) : '';
        if ( ! in_array( $event_type, array( 'pageview', 'click' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid event.' ), 400 );
        }

        $campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
        if ( ! $campaign_id && ! empty( $_POST['ph_campaign'] ) ) {
            $campaign_id = absint( $_POST['ph_campaign'] );
        }

        $data = array(
            'event_type'   => $event_type,
            'session_id'   => isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '',
            'campaign_id'  => $campaign_id,
            'object_id'    => isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : 0,
            'object_type'  => isset( $_POST['object_type'] ) ? sanitize_key( wp_unslash( $_POST['object_type'] ) ) : '',
            'source'       => isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'direct',
            'medium'       => isset( $_POST['medium'] ) ? sanitize_text_field( wp_unslash( $_POST['medium'] ) ) : '',
            'campaign_key' => isset( $_POST['campaign_key'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_key'] ) ) : '',
            'action_name'  => isset( $_POST['action_name'] ) ? sanitize_key( wp_unslash( $_POST['action_name'] ) ) : '',
            'url'          => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
            'referrer'     => isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '',
            'value'        => 0,
            'meta'         => array(
                'path'  => isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '',
                'label' => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
            ),
        );

        self::record_event( $data );
        wp_send_json_success();
    }

    public static function record_event( $data ) {
        global $wpdb;
        $defaults = array(
            'event_type'   => '',
            'session_id'   => '',
            'campaign_id'  => 0,
            'object_id'    => 0,
            'object_type'  => '',
            'source'       => 'direct',
            'medium'       => '',
            'campaign_key' => '',
            'action_name'  => '',
            'url'          => '',
            'referrer'     => '',
            'value'        => 0,
            'meta'         => array(),
        );
        $data = wp_parse_args( $data, $defaults );
        if ( ! $data['event_type'] ) {
            return false;
        }

        $source = sanitize_text_field( $data['source'] );
        if ( '' === $source ) {
            $source = 'direct';
        }

        return (bool) $wpdb->insert(
            self::table_name(),
            array(
                'event_type'   => sanitize_key( $data['event_type'] ),
                'session_id'   => substr( sanitize_text_field( $data['session_id'] ), 0, 80 ),
                'visitor_hash' => self::visitor_hash(),
                'campaign_id'  => absint( $data['campaign_id'] ),
                'object_id'    => absint( $data['object_id'] ),
                'object_type'  => substr( sanitize_key( $data['object_type'] ), 0, 50 ),
                'source'       => substr( $source, 0, 100 ),
                'medium'       => substr( sanitize_text_field( $data['medium'] ), 0, 100 ),
                'campaign_key' => substr( sanitize_text_field( $data['campaign_key'] ), 0, 190 ),
                'action_name'  => substr( sanitize_key( $data['action_name'] ), 0, 100 ),
                'url'          => esc_url_raw( $data['url'] ),
                'referrer'     => esc_url_raw( $data['referrer'] ),
                'value'        => wc_format_decimal( $data['value'], 2 ),
                'meta_json'    => wp_json_encode( is_array( $data['meta'] ) ? $data['meta'] : array() ),
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
        );
    }

    private static function visitor_hash() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        return hash_hmac( 'sha256', $ip . '|' . $ua, wp_salt( 'auth' ) );
    }

    public static function tracked_url( $url, $campaign_id, $source, $medium = 'social', $subscriber_token = '' ) {
        $url = esc_url_raw( $url );
        if ( ! $url ) {
            return '';
        }
        $campaign_id = absint( $campaign_id );
        $campaign = $campaign_id ? get_post( $campaign_id ) : null;
        $campaign_key = $campaign ? $campaign->post_name : '';

        $target = add_query_arg(
            array_filter(
                array(
                    'utm_source'   => sanitize_key( $source ),
                    'utm_medium'   => sanitize_key( $medium ),
                    'utm_campaign' => $campaign_key,
                    'ph_campaign'  => $campaign_id ?: null,
                ),
                static function( $value ) {
                    return null !== $value && '' !== $value && 0 !== $value;
                }
            ),
            $url
        );

        $source_key = sanitize_key( $source );
        $medium_key = sanitize_key( $medium );
        $signature = hash_hmac( 'sha256', $target . '|' . $campaign_id . '|' . $source_key . '|' . $medium_key, wp_salt( 'auth' ) );

        return add_query_arg(
            array_filter(
                array(
                    'action'      => 'persiano_hub_track_click',
                    'campaign_id' => $campaign_id ?: null,
                    'source'      => $source_key,
                    'medium'      => $medium_key,
                    'sub'         => $subscriber_token ? substr( hash( 'sha256', $subscriber_token ), 0, 20 ) : null,
                    'sig'         => $signature,
                    'target'      => rawurlencode( $target ),
                ),
                static function( $value ) {
                    return null !== $value && '' !== $value && 0 !== $value;
                }
            ),
            admin_url( 'admin-post.php' )
        );
    }

    public static function track_click_redirect() {
        $target = isset( $_GET['target'] ) ? rawurldecode( wp_unslash( $_GET['target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $target = esc_url_raw( $target );
        if ( ! $target || ! preg_match( '#^https?://#i', $target ) ) {
            wp_die( esc_html__( 'The destination link is invalid.', 'persiano-hub' ), 400 );
        }

        $campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $medium = isset( $_GET['medium'] ) ? sanitize_text_field( wp_unslash( $_GET['medium'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sub = isset( $_GET['sub'] ) ? sanitize_text_field( wp_unslash( $_GET['sub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $signature = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $expected = hash_hmac( 'sha256', $target . '|' . $campaign_id . '|' . sanitize_key( $source ) . '|' . sanitize_key( $medium ), wp_salt( 'auth' ) );
        if ( ! $signature || ! hash_equals( $expected, $signature ) ) {
            wp_die( esc_html__( 'This tracking link is invalid or has expired.', 'persiano-hub' ), 403 );
        }

        self::record_event(
            array(
                'event_type'   => 'click',
                'campaign_id'  => $campaign_id,
                'source'       => $source,
                'medium'       => $medium,
                'campaign_key' => $campaign_id ? get_post_field( 'post_name', $campaign_id ) : '',
                'action_name'  => 'campaign_cta',
                'url'          => $target,
                'referrer'     => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
                'meta'         => array( 'subscriber' => $sub ),
            )
        );

        wp_redirect( $target, 302, 'Batchly' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    public static function email_open_pixel() {
        $campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sub = isset( $_GET['sub'] ) ? sanitize_text_field( wp_unslash( $_GET['sub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        self::record_event(
            array(
                'event_type'   => 'email_open',
                'campaign_id'  => $campaign_id,
                'source'       => 'email',
                'medium'       => 'email',
                'campaign_key' => $campaign_id ? get_post_field( 'post_name', $campaign_id ) : '',
                'action_name'  => 'open',
                'meta'         => array( 'subscriber' => $sub ),
            )
        );

        nocache_headers();
        header( 'Content-Type: image/gif' );
        echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        exit;
    }

    public static function publish_email( $campaign_id ) {
        if ( ! class_exists( 'Persiano_Hub_Newsletter' ) ) {
            return array( 'success' => false, 'message' => __( 'The Persiano mailing list is unavailable.', 'persiano-hub' ) );
        }

        $subscribers = Persiano_Hub_Newsletter::get_active_subscribers();
        $requested_tags = array_filter( array_map( 'sanitize_key', preg_split( '/\s*,\s*/', (string) get_post_meta( $campaign_id, '_ph_pub_email_tags', true ) ) ) );
        if ( $requested_tags ) {
            $subscribers = array_values(
                array_filter(
                    $subscribers,
                    static function( $subscriber ) use ( $requested_tags ) {
                        $tags = array_filter( array_map( 'sanitize_key', preg_split( '/\s*,\s*/', (string) $subscriber->tags ) ) );
                        return (bool) array_intersect( $requested_tags, $tags );
                    }
                )
            );
        }

        if ( ! $subscribers ) {
            return array( 'success' => false, 'message' => __( 'No active subscribers match this email audience.', 'persiano-hub' ) );
        }

        $subject = trim( (string) get_post_meta( $campaign_id, '_ph_pub_email_subject', true ) );
        if ( ! $subject ) {
            $subject = get_the_title( $campaign_id );
        }
        $subject = html_entity_decode( wp_specialchars_decode( (string) $subject, ENT_QUOTES ), ENT_QUOTES, 'UTF-8' );
        $sent = 0;
        $failed = 0;

        foreach ( $subscribers as $subscriber ) {
            $html = self::build_email_html( $campaign_id, $subscriber );
            $brand = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' );
            $from  = function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : sanitize_email( get_option( 'admin_email' ) );
            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            if ( is_email( $from ) ) { $headers[] = 'From: ' . sanitize_text_field( $brand ) . ' <' . $from . '>'; }
            $ok = wp_mail( sanitize_email( $subscriber->email ), $subject, $html, $headers );
            if ( $ok ) {
                $sent++;
                self::record_event(
                    array(
                        'event_type'   => 'email_sent',
                        'campaign_id'  => $campaign_id,
                        'source'       => 'email',
                        'medium'       => 'email',
                        'campaign_key' => get_post_field( 'post_name', $campaign_id ),
                        'action_name'  => 'sent',
                        'meta'         => array( 'subscriber' => substr( hash( 'sha256', (string) $subscriber->token ), 0, 20 ) ),
                    )
                );
            } else {
                $failed++;
            }
        }

        if ( $sent > 0 ) {
            return array(
                'success'     => true,
                'message'     => 0 === $failed
                    ? sprintf( _n( 'Email sent to %d subscriber.', 'Email sent to %d subscribers.', $sent, 'persiano-hub' ), $sent )
                    : sprintf( __( 'Email sent to %1$d subscribers; %2$d deliveries failed.', 'persiano-hub' ), $sent, $failed ),
                'external_id' => 'email:' . $campaign_id . ':' . time(),
                'url'         => admin_url( 'admin.php?page=persiano-marketing-analytics&campaign_id=' . absint( $campaign_id ) ),
                'url_label'   => __( 'View campaign report', 'persiano-hub' ),
            );
        }

        return array( 'success' => false, 'message' => __( 'Email delivery failed for all matching subscribers.', 'persiano-hub' ) );
    }

    private static function build_email_html( $campaign_id, $subscriber ) {
        $post = get_post( $campaign_id );
        $preview = trim( (string) get_post_meta( $campaign_id, '_ph_pub_email_preview', true ) );
        $body_override = trim( (string) get_post_meta( $campaign_id, '_ph_pub_email_body', true ) );
        $body = $body_override ? wpautop( wp_kses_post( $body_override ) ) : wpautop( wp_kses_post( $post ? $post->post_content : '' ) );
        if ( ! trim( wp_strip_all_tags( $body ) ) && $post ) {
            $body = '<p>' . esc_html( $post->post_title ) . '</p>';
        }

        $destination = self::campaign_destination_url( $campaign_id );
        $tracked = $destination ? self::tracked_url( $destination, $campaign_id, 'email', 'email', (string) $subscriber->token ) : '';
        $cta_label = self::campaign_cta_label( $campaign_id );
        $unsubscribe = Persiano_Hub_Newsletter::get_unsubscribe_url( $subscriber->email );
        $image_id = absint( get_post_meta( $campaign_id, '_ph_pub_email_image_id', true ) );
        if ( ! $image_id ) {
            $image_id = get_post_thumbnail_id( $campaign_id );
        }
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';
        $image_alt = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';
        $image_mode = sanitize_key( (string) get_post_meta( $campaign_id, '_ph_pub_email_image_mode', true ) ) ?: 'fit';
        if ( ! in_array( $image_mode, array( 'fit', 'original', 'cover' ), true ) ) { $image_mode = 'fit'; }
        $image_position = sanitize_key( (string) get_post_meta( $campaign_id, '_ph_pub_email_image_position', true ) ) ?: 'center';
        if ( ! in_array( $image_position, array( 'top', 'center', 'bottom' ), true ) ) { $image_position = 'center'; }
        $image_style = 'display:block;max-width:100%;height:auto;margin:0 auto;';
        if ( 'fit' === $image_mode ) {
            $image_style = 'display:block;width:100%;max-width:100%;height:auto;';
        } elseif ( 'cover' === $image_mode ) {
            $image_style = 'display:block;width:100%;height:360px;object-fit:cover;object-position:' . $image_position . ' center;';
        }
        $logo_id = get_theme_mod( 'persiano_header_logo', 0 );
        if ( ! $logo_id ) {
            $logo_id = get_theme_mod( 'custom_logo', 0 );
        }
        $logo_url = class_exists( 'Persiano_Hub_Email_Branding' ) ? Persiano_Hub_Email_Branding::get_logo_url() : ( $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '' );
        $brand      = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' );
        $service    = function_exists( 'persiano_hub_business_value' ) ? persiano_hub_business_value( 'service_area', '' ) : '';
        $primary    = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'primary_color', '#8e2435' ) : '#8e2435';
        $accent     = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'accent_color', '#d79a2d' ) : '#d79a2d';
        $dark       = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'dark_color', '#2f231d' ) : '#2f231d';
        $background = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'background_color', '#f8f3e9' ) : '#f8f3e9';
        $surface    = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'surface_color', '#fffaf2' ) : '#fffaf2';
        $name = trim( (string) $subscriber->name );
        $greeting = $name ? sprintf( __( 'Hi %s,', 'persiano-hub' ), $name ) : __( 'Hello,', 'persiano-hub' );
        $open_url = add_query_arg(
            array(
                'action'      => 'persiano_hub_email_open',
                'campaign_id' => absint( $campaign_id ),
                'sub'         => substr( hash( 'sha256', (string) $subscriber->token ), 0, 20 ),
            ),
            admin_url( 'admin-post.php' )
        );
        $title = $post ? $post->post_title : $brand;
        $title = html_entity_decode( wp_specialchars_decode( (string) $title, ENT_QUOTES ), ENT_QUOTES, 'UTF-8' );
        $template = sanitize_key( (string) get_post_meta( $campaign_id, '_ph_pub_email_template', true ) );
        if ( ! $template || 'auto' === $template ) {
            $type = sanitize_key( (string) get_post_meta( $campaign_id, '_ph_pub_type', true ) );
            if ( 'weekly_menu' === $type ) { $template = 'weekly_menu'; }
            elseif ( 'promotion' === $type ) { $template = 'promotion'; }
            elseif ( in_array( $type, array( 'dish', 'pantry', 'availability' ), true ) ) { $template = 'product'; }
            elseif ( 'event' === $type ) { $template = 'event'; }
            else { $template = 'update'; }
        }
        $brand_upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $brand ) : strtoupper( $brand );
        $kickers = array(
            'weekly_menu' => sprintf( __( 'THIS WEEK AT %s', 'persiano-hub' ), $brand_upper ),
            'promotion'   => __( 'SPECIAL OFFER', 'persiano-hub' ),
            'product'     => sprintf( __( 'FROM THE %s KITCHEN', 'persiano-hub' ), $brand_upper ),
            'event'       => sprintf( __( '%s EVENT', 'persiano-hub' ), $brand_upper ),
            'update'      => sprintf( __( '%s UPDATE', 'persiano-hub' ), $brand_upper ),
        );
        $kicker = isset( $kickers[ $template ] ) ? $kickers[ $template ] : $kickers['update'];

        ob_start();
        ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $title ); ?></title></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $background ); ?>;color:<?php echo esc_attr( $dark ); ?>;font-family:Arial,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;"><?php echo esc_html( $preview ); ?></div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $background ); ?>;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:<?php echo esc_attr( $surface ); ?>;border-radius:24px;overflow:hidden;border:1px solid rgba(47,35,29,.12);">
<tr><td style="padding:28px 34px 18px;text-align:center;"><?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $brand ); ?>" style="max-width:190px;height:auto;"><?php else : ?><div style="font-family:Georgia,serif;font-size:30px;font-weight:bold;color:<?php echo esc_attr( $primary ); ?>;"><?php echo esc_html( $brand ); ?></div><?php endif; ?></td></tr>
<?php if ( $image_url ) : ?><tr><td style="text-align:center;line-height:0;"><img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $image_alt ); ?>" style="<?php echo esc_attr( $image_style ); ?>"></td></tr><?php endif; ?>
<tr><td style="padding:34px;">
<p style="margin:0 0 16px;font-size:16px;"><?php echo esc_html( $greeting ); ?></p>
<div style="margin:0 0 12px;color:<?php echo esc_attr( $primary ); ?>;font-size:12px;font-weight:bold;letter-spacing:.14em;"><?php echo esc_html( $kicker ); ?></div>
<h1 style="margin:0 0 20px;font-family:Georgia,serif;font-size:42px;line-height:1.05;color:<?php echo esc_attr( $dark ); ?>;"><?php echo esc_html( $title ); ?></h1>
<div style="font-size:17px;line-height:1.75;color:#4b4039;"><?php echo wp_kses_post( $body ); ?></div>
<?php if ( $tracked ) : ?><p style="margin:30px 0 6px;"><a href="<?php echo esc_url( $tracked ); ?>" style="display:inline-block;padding:14px 24px;border-radius:999px;background:<?php echo esc_attr( $primary ); ?>;color:<?php echo esc_attr( $surface ); ?>;text-decoration:none;font-weight:bold;"><?php echo esc_html( $cta_label ); ?></a></p><?php endif; ?>
</td></tr>
<tr><td style="padding:24px 34px;background:<?php echo esc_attr( $dark ); ?>;color:<?php echo esc_attr( $surface ); ?>;font-size:12px;line-height:1.7;text-align:center;"><?php echo esc_html( $brand . ( $service ? ' · ' . $service : '' ) ); ?><br><?php if ( $unsubscribe ) : ?><a href="<?php echo esc_url( $unsubscribe ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;">Unsubscribe</a><?php endif; ?></td></tr>
</table>
<img src="<?php echo esc_url( $open_url ); ?>" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">
</td></tr></table></body></html>
        <?php
        return (string) ob_get_clean();
    }

    public static function preview_email_admin() {
        $campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
        if ( ! $campaign_id || ! current_user_can( 'edit_post', $campaign_id ) ) {
            wp_die( esc_html__( 'You do not have permission to preview this email.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_preview_email_' . $campaign_id );
        $subscriber = (object) array(
            'email' => wp_get_current_user()->user_email,
            'name'  => wp_get_current_user()->display_name,
            'token' => 'preview-' . $campaign_id,
        );
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        echo self::build_email_html( $campaign_id, $subscriber ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function send_test_email_admin() {
        $campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
        if ( ! $campaign_id || ! current_user_can( 'edit_post', $campaign_id ) ) {
            wp_die( esc_html__( 'You do not have permission to send this test email.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_send_test_email_' . $campaign_id );
        $user = wp_get_current_user();
        $recipient = sanitize_email( $user->user_email );
        $subscriber = (object) array(
            'email' => $recipient,
            'name'  => $user->display_name,
            'token' => 'test-' . $campaign_id,
        );
        $subject = trim( (string) get_post_meta( $campaign_id, '_ph_pub_email_subject', true ) );
        if ( ! $subject ) {
            $subject = get_the_title( $campaign_id );
        }
        $subject = html_entity_decode( wp_specialchars_decode( (string) $subject, ENT_QUOTES ), ENT_QUOTES, 'UTF-8' );
        $brand = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' );
        $from  = function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : sanitize_email( get_option( 'admin_email' ) );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        if ( is_email( $from ) ) { $headers[] = 'From: ' . sanitize_text_field( $brand ) . ' <' . $from . '>'; }
        $sent = wp_mail( $recipient, '[TEST] ' . $subject, self::build_email_html( $campaign_id, $subscriber ), $headers );
        $message = $sent ? sprintf( __( 'Test email sent to %s.', 'persiano-hub' ), $recipient ) : __( 'The test email could not be sent.', 'persiano-hub' );
        wp_safe_redirect( add_query_arg( 'ph_email_notice', $message, get_edit_post_link( $campaign_id, 'url' ) ) );
        exit;
    }

    private static function campaign_destination_url( $campaign_id ) {
        if ( class_exists( 'Persiano_Hub_Publishing' ) && method_exists( 'Persiano_Hub_Publishing', 'marketing_destination_url' ) ) {
            return Persiano_Hub_Publishing::marketing_destination_url( $campaign_id );
        }
        return home_url( '/' );
    }

    private static function campaign_cta_label( $campaign_id ) {
        if ( class_exists( 'Persiano_Hub_Publishing' ) && method_exists( 'Persiano_Hub_Publishing', 'marketing_cta_label' ) ) {
            return Persiano_Hub_Publishing::marketing_cta_label( $campaign_id );
        }
        return sprintf( __( 'View on %s', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) );
    }

    public static function record_order_attribution( $order_id, $posted_data = array(), $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        self::record_order( $order );
    }

    public static function record_store_api_order_attribution( $order ) {
        self::record_order( $order );
    }

    private static function record_order( $order ) {
        if ( ! $order instanceof WC_Order || $order->get_meta( '_persiano_marketing_purchase_recorded', true ) ) {
            return;
        }

        $source = self::cookie_value( 'phm_source', 'direct' );
        $medium = self::cookie_value( 'phm_medium', '' );
        $campaign_key = self::cookie_value( 'phm_campaign', '' );
        $campaign_id = absint( self::cookie_value( 'phm_campaign_id', '0' ) );
        $session_id = self::cookie_value( 'phm_session', '' );

        $order->update_meta_data( '_persiano_marketing_source', $source );
        $order->update_meta_data( '_persiano_marketing_medium', $medium );
        $order->update_meta_data( '_persiano_marketing_campaign', $campaign_key );
        $order->update_meta_data( '_persiano_marketing_campaign_id', $campaign_id );
        $order->update_meta_data( '_persiano_marketing_purchase_recorded', current_time( 'mysql' ) );
        $order->save();

        self::record_event(
            array(
                'event_type'   => 'purchase',
                'session_id'   => $session_id,
                'campaign_id'  => $campaign_id,
                'object_id'    => $order->get_id(),
                'object_type'  => 'shop_order',
                'source'       => $source,
                'medium'       => $medium,
                'campaign_key' => $campaign_key,
                'action_name'  => 'purchase',
                'value'        => (float) $order->get_total(),
                'meta'         => array( 'currency' => $order->get_currency() ),
            )
        );
    }


    public static function record_refund_attribution( $order_id, $refund_id ) {
        $order  = wc_get_order( $order_id );
        $refund = wc_get_order( $refund_id );
        if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund ) {
            return;
        }
        self::record_refund_event( $order, $refund );
    }

    private static function record_refund_event( $order, $refund ) {
        if ( 'yes' === $refund->get_meta( '_persiano_marketing_refund_recorded', true ) ) {
            return;
        }
        $campaign_id  = absint( $order->get_meta( '_persiano_marketing_campaign_id', true ) );
        $campaign_key = (string) $order->get_meta( '_persiano_marketing_campaign', true );
        $source       = (string) $order->get_meta( '_persiano_marketing_source', true );
        $medium       = (string) $order->get_meta( '_persiano_marketing_medium', true );
        if ( ! $campaign_id && ! $campaign_key && ! $source && ! $order->get_meta( '_persiano_marketing_purchase_recorded', true ) ) {
            return;
        }
        self::record_event(
            array(
                'event_type'   => 'refund',
                'session_id'   => '',
                'campaign_id'  => $campaign_id,
                'object_id'    => $order->get_id(),
                'object_type'  => 'shop_order',
                'source'       => $source ?: 'direct',
                'medium'       => $medium,
                'campaign_key' => $campaign_key,
                'action_name'  => 'refund',
                'value'        => -1 * abs( (float) $refund->get_amount() ),
                'meta'         => array( 'currency' => $order->get_currency(), 'refund_id' => $refund->get_id() ),
            )
        );
        $refund->update_meta_data( '_persiano_marketing_refund_recorded', 'yes' );
        $refund->save();
    }

    private static function sync_historical_refunds( $since ) {
        global $wpdb;
        $table = self::table_name();
        $order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM {$table} WHERE event_type='purchase' AND object_type='shop_order' AND created_at >= %s AND object_id > 0", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        foreach ( array_map( 'absint', (array) $order_ids ) as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order instanceof WC_Order ) {
                continue;
            }
            foreach ( $order->get_refunds() as $refund ) {
                if ( $refund instanceof WC_Order_Refund ) {
                    self::record_refund_event( $order, $refund );
                }
            }
        }
    }

    private static function cookie_value( $key, $default = '' ) {
        return isset( $_COOKIE[ $key ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $key ] ) ) : $default;
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Marketing Analytics', 'persiano-hub' ),
            __( 'Marketing Analytics', 'persiano-hub' ),
            'manage_woocommerce',
            'persiano-marketing-analytics',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        global $wpdb;
        $days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $days, array( 7, 30, 90, 365 ), true ) ) {
            $days = 30;
        }
        $campaign_filter = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $since = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( DAY_IN_SECONDS * $days ), wp_timezone() );
        $table = self::table_name();
        self::sync_historical_refunds( $since );
        $where = $wpdb->prepare( 'created_at >= %s', $since );
        if ( $campaign_filter ) {
            $where .= $wpdb->prepare( ' AND campaign_id = %d', $campaign_filter );
        }

        $summary_rows = $wpdb->get_results( "SELECT event_type, COUNT(*) AS total, SUM(value) AS value FROM {$table} WHERE {$where} GROUP BY event_type", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $views = isset( $summary_rows['pageview'] ) ? (int) $summary_rows['pageview']->total : 0;
        $clicks = isset( $summary_rows['click'] ) ? (int) $summary_rows['click']->total : 0;
        $orders = isset( $summary_rows['purchase'] ) ? (int) $summary_rows['purchase']->total : 0;
        $gross_revenue = isset( $summary_rows['purchase'] ) ? (float) $summary_rows['purchase']->value : 0;
        $refunded = isset( $summary_rows['refund'] ) ? abs( (float) $summary_rows['refund']->value ) : 0;
        $revenue = $gross_revenue - $refunded;
        $unique = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT NULLIF(session_id,'')) FROM {$table} WHERE {$where} AND event_type='pageview'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $sources = $wpdb->get_results( "SELECT source, medium, SUM(event_type='pageview') AS views, SUM(event_type='click') AS clicks, SUM(event_type='purchase') AS orders, SUM(CASE WHEN event_type IN ('purchase','refund') THEN value ELSE 0 END) AS revenue FROM {$table} WHERE {$where} GROUP BY source, medium ORDER BY orders DESC, views DESC LIMIT 30" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $campaigns = $wpdb->get_results( "SELECT campaign_id, campaign_key, SUM(event_type='pageview') AS views, SUM(event_type='click') AS clicks, SUM(event_type='email_open') AS opens, SUM(event_type='purchase') AS orders, SUM(CASE WHEN event_type IN ('purchase','refund') THEN value ELSE 0 END) AS revenue FROM {$table} WHERE {$where} AND campaign_id > 0 GROUP BY campaign_id, campaign_key ORDER BY orders DESC, views DESC LIMIT 30" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $content = $wpdb->get_results( "SELECT object_id, object_type, COUNT(*) AS views, COUNT(DISTINCT NULLIF(session_id,'')) AS visitors FROM {$table} WHERE {$where} AND event_type='pageview' AND object_id > 0 GROUP BY object_id, object_type ORDER BY views DESC LIMIT 20" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $export = wp_nonce_url( add_query_arg( array( 'action' => 'persiano_hub_marketing_export', 'days' => $days ), admin_url( 'admin-post.php' ) ), 'persiano_hub_marketing_export' );
        ?>
        <div class="wrap ph-marketing-wrap">
            <span class="ph-admin-eyebrow"><?php esc_html_e( 'Batchly', 'persiano-hub' ); ?></span>
            <h1><?php esc_html_e( 'Marketing Analytics', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'First-party reporting for website traffic, campaign clicks, sources and WooCommerce order attribution. Logged-in store managers are excluded from frontend tracking.', 'persiano-hub' ); ?></p>

            <div class="ph-marketing-toolbar">
                <div>
                    <?php foreach ( array( 7, 30, 90, 365 ) as $range ) : ?>
                        <a class="button <?php echo $days === $range ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'persiano-marketing-analytics', 'days' => $range ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( sprintf( __( '%d days', 'persiano-hub' ), $range ) ); ?></a>
                    <?php endforeach; ?>
                </div>
                <a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export Events CSV', 'persiano-hub' ); ?></a>
            </div>

            <div class="ph-marketing-stats">
                <div><strong><?php echo esc_html( number_format_i18n( $views ) ); ?></strong><span><?php esc_html_e( 'Page views', 'persiano-hub' ); ?></span></div>
                <div><strong><?php echo esc_html( number_format_i18n( $unique ) ); ?></strong><span><?php esc_html_e( 'Unique sessions', 'persiano-hub' ); ?></span></div>
                <div><strong><?php echo esc_html( number_format_i18n( $clicks ) ); ?></strong><span><?php esc_html_e( 'Tracked clicks', 'persiano-hub' ); ?></span></div>
                <div><strong><?php echo esc_html( number_format_i18n( $orders ) ); ?></strong><span><?php esc_html_e( 'Attributed orders', 'persiano-hub' ); ?></span></div>
                <div><strong><?php echo wp_kses_post( wc_price( $revenue ) ); ?></strong><span><?php esc_html_e( 'Attributed revenue', 'persiano-hub' ); ?></span></div>
                <div><strong><?php echo wp_kses_post( wc_price( $refunded ) ); ?></strong><span><?php esc_html_e( 'Refunded revenue', 'persiano-hub' ); ?></span></div>
            </div>

            <div class="ph-marketing-grid">
                <section class="ph-marketing-panel">
                    <h2><?php esc_html_e( 'Traffic & sales by source', 'persiano-hub' ); ?></h2>
                    <div class="ph-responsive-table"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Source', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Medium', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Views', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Clicks', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Orders', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Revenue', 'persiano-hub' ); ?></th></tr></thead><tbody>
                    <?php if ( $sources ) : foreach ( $sources as $row ) : ?><tr><td><strong><?php echo esc_html( $row->source ?: 'direct' ); ?></strong></td><td><?php echo esc_html( $row->medium ); ?></td><td><?php echo esc_html( number_format_i18n( $row->views ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row->clicks ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row->orders ) ); ?></td><td><?php echo wp_kses_post( wc_price( (float) $row->revenue ) ); ?></td></tr><?php endforeach; else : ?><tr><td colspan="6"><?php esc_html_e( 'No tracked traffic yet.', 'persiano-hub' ); ?></td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>

                <section class="ph-marketing-panel">
                    <h2><?php esc_html_e( 'Campaign performance', 'persiano-hub' ); ?></h2>
                    <div class="ph-responsive-table"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Campaign', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Views', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Clicks', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Email opens', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Orders', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Revenue', 'persiano-hub' ); ?></th></tr></thead><tbody>
                    <?php if ( $campaigns ) : foreach ( $campaigns as $row ) : $title = get_the_title( absint( $row->campaign_id ) ) ?: $row->campaign_key; ?><tr><td><a href="<?php echo esc_url( get_edit_post_link( absint( $row->campaign_id ) ) ); ?>"><strong><?php echo esc_html( $title ); ?></strong></a></td><td><?php echo esc_html( number_format_i18n( $row->views ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row->clicks ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row->opens ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row->orders ) ); ?></td><td><?php echo wp_kses_post( wc_price( (float) $row->revenue ) ); ?></td></tr><?php endforeach; else : ?><tr><td colspan="6"><?php esc_html_e( 'No campaign activity yet.', 'persiano-hub' ); ?></td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            </div>

            <section class="ph-marketing-panel" style="margin-top:20px;">
                <h2><?php esc_html_e( 'Most viewed content', 'persiano-hub' ); ?></h2>
                <div class="ph-responsive-table"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Content', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Type', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Views', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Unique sessions', 'persiano-hub' ); ?></th></tr></thead><tbody>
                <?php if ( $content ) : foreach ( $content as $row ) : $title = get_the_title( absint( $row->object_id ) ); ?><tr><td><?php if ( $title ) : ?><a href="<?php echo esc_url( get_permalink( absint( $row->object_id ) ) ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( $title ); ?></strong></a><?php else : ?>#<?php echo esc_html( $row->object_id ); ?><?php endif; ?></td><td><?php echo esc_html( $row->object_type ); ?></td><td><?php echo esc_html( number_format_i18n( $row->views ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row->visitors ) ); ?></td></tr><?php endforeach; else : ?><tr><td colspan="4"><?php esc_html_e( 'No content views have been recorded yet.', 'persiano-hub' ); ?></td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
        </div>
        <?php
    }

    public static function export_csv() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to export marketing data.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_marketing_export' );
        global $wpdb;
        $days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
        $since = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( DAY_IN_SECONDS * max( 1, $days ) ), wp_timezone() );
        $table = self::table_name();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_type,campaign_id,object_id,object_type,source,medium,campaign_key,action_name,url,referrer,value,created_at FROM {$table} WHERE created_at >= %s ORDER BY id DESC", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=persiano-marketing-' . gmdate( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Event', 'Campaign ID', 'Object ID', 'Object Type', 'Source', 'Medium', 'Campaign', 'Action', 'URL', 'Referrer', 'Value', 'Created At' ) );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
        fclose( $out );
        exit;
    }
}
