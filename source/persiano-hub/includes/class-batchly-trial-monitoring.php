<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Privacy-conscious trial activity monitoring.
 * Records feature usage and errors without storing message bodies, credentials,
 * payment details, addresses, phone numbers, or uploaded file contents.
 */
class Batchly_Trial_Monitoring {
    const OPTION = 'batchly_trial_monitoring_settings';
    const HOOK   = 'batchly_trial_monitoring_daily_summary';
    const TABLE  = 'batchly_activity_log';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 95 );
        add_action( 'admin_init', array( __CLASS__, 'save_settings' ) );
        add_action( 'wp_login', array( __CLASS__, 'login' ), 10, 2 );
        add_action( 'current_screen', array( __CLASS__, 'screen_view' ) );
        add_action( 'save_post_product', array( __CLASS__, 'product_saved' ), 10, 3 );
        add_action( 'woocommerce_new_order', array( __CLASS__, 'order_created' ), 10, 2 );
        add_action( 'updated_option', array( __CLASS__, 'option_updated' ), 10, 3 );
        add_action( 'admin_notices', array( __CLASS__, 'privacy_notice' ) );
        add_action( 'admin_footer', array( __CLASS__, 'feedback_prompt' ) );
        add_action( 'wp_ajax_batchly_trial_feedback', array( __CLASS__, 'ajax_feedback' ) );
        add_action( self::HOOK, array( __CLASS__, 'send_daily_summary' ) );
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_label varchar(190) NOT NULL DEFAULT '',
            event_type varchar(80) NOT NULL,
            object_type varchar(50) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            context varchar(190) NOT NULL DEFAULT '',
            outcome varchar(30) NOT NULL DEFAULT 'success',
            details text NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY event_type (event_type),
            KEY user_id (user_id)
        ) {$charset};" );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    private static function defaults() {
        return array(
            'enabled'          => 'no',
            'recipient'        => sanitize_email( get_option( 'admin_email' ) ),
            'daily_summary'    => 'yes',
            'error_alerts'     => 'yes',
            'inactivity_days'  => 3,
            'feedback_prompts' => 'yes',
            'notice_seen'      => 'no',
        );
    }

    public static function settings() {
        $saved = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
    }

    public static function enabled() {
        return 'yes' === self::settings()['enabled'];
    }

    public static function log( $event_type, $args = array() ) {
        if ( ! self::enabled() ) { return; }
        global $wpdb;
        $user = wp_get_current_user();
        $args = wp_parse_args( $args, array(
            'object_type' => '', 'object_id' => 0, 'context' => '', 'outcome' => 'success', 'details' => array(),
        ) );
        // Allow-listed, non-sensitive details only.
        $details = array();
        foreach ( (array) $args['details'] as $key => $value ) {
            if ( in_array( $key, array( 'step', 'status', 'channel', 'page', 'reason', 'count', 'rating' ), true ) ) {
                $details[ sanitize_key( $key ) ] = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
            }
        }
        $wpdb->insert( $wpdb->prefix . self::TABLE, array(
            'created_at'  => current_time( 'mysql' ),
            'user_id'     => get_current_user_id(),
            'user_label'  => $user && $user->exists() ? sanitize_text_field( $user->display_name ?: $user->user_login ) : 'System',
            'event_type'  => sanitize_key( $event_type ),
            'object_type' => sanitize_key( $args['object_type'] ),
            'object_id'   => absint( $args['object_id'] ),
            'context'     => sanitize_text_field( $args['context'] ),
            'outcome'     => sanitize_key( $args['outcome'] ),
            'details'     => wp_json_encode( $details ),
        ), array( '%s','%d','%s','%s','%s','%d','%s','%s','%s' ) );
    }

    public static function login( $user_login, $user ) {
        self::log( 'user_login', array( 'object_type' => 'user', 'object_id' => $user->ID, 'context' => 'WordPress login' ) );
    }

    public static function screen_view( $screen ) {
        if ( ! is_admin() || ! $screen ) { return; }
        $id = (string) $screen->id;
        if ( false !== strpos( $id, 'persiano-hub' ) || false !== strpos( $id, 'batchly' ) || in_array( $screen->post_type, array( 'product', 'shop_order' ), true ) ) {
            self::log( 'feature_viewed', array( 'context' => $id, 'details' => array( 'page' => $id ) ) );
        }
    }

    public static function product_saved( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) { return; }
        self::log( $update ? 'product_updated' : 'product_created', array( 'object_type' => 'product', 'object_id' => $post_id, 'context' => 'WooCommerce product' ) );
    }

    public static function order_created( $order_id, $order = null ) {
        self::log( 'order_created', array( 'object_type' => 'order', 'object_id' => $order_id, 'context' => 'WooCommerce order' ) );
    }

    public static function option_updated( $option, $old, $new ) {
        if ( 'persiano_hub_setup_wizard' === $option ) {
            $step = is_array( $new ) ? absint( $new['step'] ?? 0 ) : 0;
            $completed = is_array( $new ) ? sanitize_text_field( $new['completed'] ?? 'no' ) : 'no';
            self::log( 'setup_wizard_progress', array( 'context' => 'Setup Wizard', 'details' => array( 'step' => $step, 'status' => $completed ) ) );
        } elseif ( 'persiano_hub_business_profile' === $option ) {
            self::log( 'business_profile_updated', array( 'context' => 'Business Profile' ) );
        }
    }

    public static function menu() {
        add_submenu_page( 'persiano-hub', 'Trial Monitoring', 'Trial Monitoring', 'manage_woocommerce', 'batchly-trial-monitoring', array( __CLASS__, 'render' ) );
    }

    public static function save_settings() {
        if ( empty( $_POST['batchly_monitoring_save'] ) || ! current_user_can( 'manage_woocommerce' ) ) { return; }
        check_admin_referer( 'batchly_monitoring_save' );
        $s = self::settings();
        $s['enabled']          = ! empty( $_POST['enabled'] ) ? 'yes' : 'no';
        $s['recipient']        = sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) );
        $s['daily_summary']    = ! empty( $_POST['daily_summary'] ) ? 'yes' : 'no';
        $s['error_alerts']     = ! empty( $_POST['error_alerts'] ) ? 'yes' : 'no';
        $s['feedback_prompts'] = ! empty( $_POST['feedback_prompts'] ) ? 'yes' : 'no';
        $s['inactivity_days']  = max( 1, absint( $_POST['inactivity_days'] ?? 3 ) );
        $s['notice_seen']      = 'yes';
        update_option( self::OPTION, $s, false );
        if ( $s['enabled'] === 'yes' ) { self::log( 'monitoring_enabled', array( 'context' => 'Trial Monitoring' ) ); }
        wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=batchly-trial-monitoring' ) ) );
        exit;
    }

    public static function privacy_notice() {
        if ( ! self::enabled() || ! current_user_can( 'manage_woocommerce' ) ) { return; }
        echo '<div class="notice notice-info"><p><strong>Batchly trial monitoring is active.</strong> Feature usage, setup progress and errors may be summarized for the site owner. Passwords, API credentials, payment details and message contents are not recorded.</p></div>';
    }

    public static function feedback_prompt() {
        if ( ! self::enabled() || 'yes' !== self::settings()['feedback_prompts'] || ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ( false === strpos( $screen->id, 'persiano-hub' ) && false === strpos( $screen->id, 'batchly' ) ) ) { return; }
        $nonce = wp_create_nonce( 'batchly_trial_feedback' );
        echo '<div id="batchly-feedback" style="position:fixed;right:18px;bottom:18px;z-index:99999;background:#fff;border:1px solid #ccd0d4;border-radius:10px;padding:10px 12px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-width:290px;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><strong style="margin-right:auto">Was this page clear?</strong><button type="button" class="button" data-rating="yes">Yes</button><button type="button" class="button" data-rating="no">No</button><button type="button" class="button-link" data-close aria-label="Close">×</button></div>';
        echo '<script>(function(){const box=document.getElementById("batchly-feedback");if(!box)return;box.addEventListener("click",function(e){const r=e.target.dataset.rating;if(e.target.dataset.close!==undefined){box.remove();return;}if(!r)return;const d=new URLSearchParams({action:"batchly_trial_feedback",nonce:' . wp_json_encode( $nonce ) . ',rating:r,page:' . wp_json_encode( $screen->id ) . '});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(()=>{box.innerHTML="Thank you — feedback recorded.";setTimeout(()=>box.remove(),1800)});});})();</script>';
    }

    public static function ajax_feedback() {
        check_ajax_referer( 'batchly_trial_feedback', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error(); }
        self::log( 'tester_feedback', array( 'context' => sanitize_text_field( wp_unslash( $_POST['page'] ?? '' ) ), 'details' => array( 'rating' => sanitize_key( $_POST['rating'] ?? '' ) ) ) );
        wp_send_json_success();
    }

    private static function recent_events( $hours = 24, $limit = 100 ) {
        global $wpdb;
        $since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - ( absint( $hours ) * HOUR_IN_SECONDS ) );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE created_at >= %s ORDER BY id DESC LIMIT %d", $since, absint( $limit ) ) );
    }

    public static function send_daily_summary() {
        $s = self::settings();
        if ( 'yes' !== $s['enabled'] || 'yes' !== $s['daily_summary'] || ! is_email( $s['recipient'] ) ) { return; }
        $events = self::recent_events( 24, 200 );
        $wizard = get_option( 'persiano_hub_setup_wizard', array() );
        $subject = '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] Batchly trial activity summary';
        $lines = array();
        $lines[] = 'Batchly trial activity for the past 24 hours';
        $lines[] = 'Site: ' . home_url( '/' );
        $lines[] = 'Setup Wizard: step ' . absint( $wizard['step'] ?? 1 ) . ', completed: ' . sanitize_text_field( $wizard['completed'] ?? 'no' );
        $lines[] = '';
        if ( ! $events ) {
            $lines[] = 'No recorded activity.';
        } else {
            foreach ( array_reverse( $events ) as $e ) {
                $lines[] = sprintf( '%s — %s — %s%s', $e->created_at, $e->user_label, str_replace( '_', ' ', $e->event_type ), $e->context ? ' (' . $e->context . ')' : '' );
            }
        }
        wp_mail( $s['recipient'], $subject, implode( "\n", $lines ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Access denied.' ); }
        $s = self::settings();
        $events = self::recent_events( 24 * 14, 250 );
        echo '<div class="wrap"><h1>Trial Monitoring</h1><p>Receive privacy-conscious summaries of how testers use this Batchly installation without logging in. The log excludes passwords, API credentials, payment details, message bodies, phone numbers and customer addresses.</p>';
        echo '<form method="post">'; wp_nonce_field( 'batchly_monitoring_save' );
        echo '<table class="form-table"><tr><th>Monitoring</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked( $s['enabled'], 'yes', false ) . '> Enable activity monitoring</label></td></tr>';
        echo '<tr><th>Report recipient</th><td><input type="email" class="regular-text" name="recipient" value="' . esc_attr( $s['recipient'] ) . '"></td></tr>';
        echo '<tr><th>Reports and prompts</th><td><label><input type="checkbox" name="daily_summary" value="1" ' . checked( $s['daily_summary'], 'yes', false ) . '> Daily summary email</label><br><label><input type="checkbox" name="error_alerts" value="1" ' . checked( $s['error_alerts'], 'yes', false ) . '> Error alerts</label><br><label><input type="checkbox" name="feedback_prompts" value="1" ' . checked( $s['feedback_prompts'], 'yes', false ) . '> Ask short clarity questions on Batchly pages</label></td></tr>';
        echo '<tr><th>Inactivity threshold</th><td><input type="number" min="1" name="inactivity_days" value="' . esc_attr( $s['inactivity_days'] ) . '"> days</td></tr></table>';
        echo '<p><button class="button button-primary" name="batchly_monitoring_save" value="1">Save monitoring settings</button></p></form>';
        echo '<h2>Recent activity</h2><table class="widefat striped"><thead><tr><th>Date</th><th>User</th><th>Action</th><th>Context</th><th>Outcome</th></tr></thead><tbody>';
        if ( ! $events ) { echo '<tr><td colspan="5">No activity recorded yet.</td></tr>'; }
        foreach ( $events as $e ) { echo '<tr><td>' . esc_html( $e->created_at ) . '</td><td>' . esc_html( $e->user_label ) . '</td><td>' . esc_html( str_replace( '_', ' ', $e->event_type ) ) . '</td><td>' . esc_html( $e->context ) . '</td><td>' . esc_html( $e->outcome ) . '</td></tr>'; }
        echo '</tbody></table></div>';
    }
}
