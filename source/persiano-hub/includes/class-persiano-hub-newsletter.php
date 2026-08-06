<?php
/**
 * Mailing list management for Batchly.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Newsletter {
    const TABLE_SUFFIX = 'persiano_subscribers';
    const CHECKOUT_FIELD_ID = 'persiano-hub/newsletter-opt-in';
    const SMS_CHECKOUT_FIELD_ID = 'persiano-hub/sms-opt-in';

    public static function init() {
        add_shortcode( 'persiano_newsletter_signup', array( __CLASS__, 'shortcode' ) );

        add_action( 'admin_post_nopriv_persiano_newsletter_subscribe', array( __CLASS__, 'handle_subscribe' ) );
        add_action( 'admin_post_persiano_newsletter_subscribe', array( __CLASS__, 'handle_subscribe' ) );
        add_action( 'admin_post_nopriv_persiano_newsletter_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );
        add_action( 'admin_post_persiano_newsletter_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );

        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
        add_action( 'admin_post_persiano_newsletter_export', array( __CLASS__, 'export_csv' ) );
        add_action( 'admin_post_persiano_newsletter_save_subscriber', array( __CLASS__, 'save_subscriber_admin' ) );
        add_action( 'admin_post_persiano_newsletter_bulk', array( __CLASS__, 'bulk_action_admin' ) );
        add_action( 'admin_post_persiano_newsletter_import', array( __CLASS__, 'import_csv_admin' ) );

        // Classic checkout.
        add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render_classic_checkout_opt_in' ), 8 );
        add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic_checkout_opt_in' ), 20, 2 );
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_classic_checkout_opt_in' ), 20, 3 );

        // Cart/Checkout block.
        add_action( 'woocommerce_init', array( __CLASS__, 'register_block_checkout_field' ) );
        add_action( 'woocommerce_validate_additional_field', array( __CLASS__, 'validate_block_checkout_opt_in' ), 20, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'process_block_checkout_opt_in' ), 20, 1 );

        // Make subscription available to themes without coupling to a shortcode.
        add_action( 'persiano_hub_newsletter_form', array( __CLASS__, 'render_form' ) );
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
            email varchar(190) NOT NULL,
            name varchar(190) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'subscribed',
            source varchar(80) NOT NULL DEFAULT 'website',
            tags text NULL,
            notes text NULL,
            consent_text text NULL,
            consent_at datetime NULL,
            consent_ip varchar(100) NOT NULL DEFAULT '',
            consent_user_agent text NULL,
            unsubscribed_at datetime NULL,
            token varchar(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email),
            UNIQUE KEY token (token),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    public static function consent_text() {
        return class_exists( 'Persiano_Hub_Customer_Accounts' ) ? Persiano_Hub_Customer_Accounts::email_consent_text() : sprintf( __( 'Yes, %s may send me menus, product updates, events and special offers by email. Consent is optional and I can unsubscribe at any time.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) );
    }

    public static function subscribe( $email, $name = '', $source = 'website', $context = array() ) {
        global $wpdb;

        $email = sanitize_email( $email );
        $name = sanitize_text_field( $name );
        $source = sanitize_key( $source );

        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'persiano-hub' ) );
        }

        $now = current_time( 'mysql' );
        $table = self::table_name();
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $data = array(
            'name'               => $name,
            'status'             => 'subscribed',
            'source'             => $source ?: 'website',
            'tags'               => isset( $context['tags'] ) ? self::normalize_tags( $context['tags'] ) : ( $existing ? (string) $existing->tags : '' ),
            'notes'              => isset( $context['notes'] ) ? sanitize_textarea_field( $context['notes'] ) : ( $existing ? (string) $existing->notes : '' ),
            'consent_text'       => isset( $context['consent_text'] ) ? sanitize_textarea_field( $context['consent_text'] ) : self::consent_text(),
            'consent_at'         => $now,
            'consent_ip'         => isset( $context['ip'] ) ? sanitize_text_field( $context['ip'] ) : self::request_ip(),
            'consent_user_agent' => isset( $context['user_agent'] ) ? sanitize_textarea_field( $context['user_agent'] ) : self::request_user_agent(),
            'unsubscribed_at'    => null,
            'updated_at'         => $now,
        );

        if ( $existing ) {
            if ( ! $data['name'] ) {
                unset( $data['name'] );
            }
            $wpdb->update( $table, $data, array( 'id' => absint( $existing->id ) ) );
            return absint( $existing->id );
        }

        $data['email'] = $email;
        $data['token'] = wp_generate_password( 48, false, false );
        $data['created_at'] = $now;
        $wpdb->insert( $table, $data );

        if ( ! $wpdb->insert_id ) {
            return new WP_Error( 'db_error', __( 'We could not save your subscription. Please try again.', 'persiano-hub' ) );
        }

        return absint( $wpdb->insert_id );
    }

    public static function unsubscribe_by_token( $token ) {
        global $wpdb;
        $token = sanitize_text_field( $token );
        if ( ! $token ) {
            return false;
        }

        $table = self::table_name();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $row ) {
            return false;
        }

        $now = current_time( 'mysql' );
        $wpdb->update(
            $table,
            array(
                'status'          => 'unsubscribed',
                'unsubscribed_at' => $now,
                'updated_at'      => $now,
            ),
            array( 'id' => absint( $row->id ) )
        );
        return true;
    }

    public static function unsubscribe_by_email( $email ) {
        global $wpdb;
        $email = sanitize_email( $email );
        if ( ! is_email( $email ) ) {
            return false;
        }
        $table = self::table_name();
        $updated = $wpdb->update(
            $table,
            array(
                'status'          => 'unsubscribed',
                'unsubscribed_at' => current_time( 'mysql' ),
                'updated_at'      => current_time( 'mysql' ),
            ),
            array( 'email' => $email )
        );
        return false !== $updated;
    }

    public static function get_unsubscribe_url( $email ) {
        global $wpdb;
        $table = self::table_name();
        $token = $wpdb->get_var( $wpdb->prepare( "SELECT token FROM {$table} WHERE email = %s", sanitize_email( $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $token ) {
            return '';
        }
        return add_query_arg(
            array(
                'action' => 'persiano_newsletter_unsubscribe',
                'token'  => rawurlencode( $token ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    public static function render_form( $args = array() ) {
        $args = wp_parse_args(
            $args,
            array(
                'source'      => 'website',
                'title'       => __( 'Be first to know what’s cooking.', 'persiano-hub' ),
                'description' => __( 'Get new weekly menus, pantry drops and Persiano events in your inbox.', 'persiano-hub' ),
                'compact'     => false,
            )
        );

        $status = isset( $_GET['persiano_subscribe'] ) ? sanitize_key( wp_unslash( $_GET['persiano_subscribe'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <section class="ph-newsletter<?php echo $args['compact'] ? ' ph-newsletter--compact' : ''; ?>">
            <div class="ph-newsletter-copy">
                <span class="ph-newsletter-eyebrow"><?php esc_html_e( 'The Persiano List', 'persiano-hub' ); ?></span>
                <h2><?php echo esc_html( $args['title'] ); ?></h2>
                <p><?php echo esc_html( $args['description'] ); ?></p>
            </div>
            <div class="ph-newsletter-form-wrap">
                <?php if ( 'success' === $status ) : ?>
                    <div class="ph-newsletter-message ph-newsletter-message--success"><?php esc_html_e( 'You’re on the list. We’ll keep it useful and delicious.', 'persiano-hub' ); ?></div>
                <?php elseif ( 'error' === $status ) : ?>
                    <div class="ph-newsletter-message ph-newsletter-message--error"><?php esc_html_e( 'Something went wrong. Please check your email and try again.', 'persiano-hub' ); ?></div>
                <?php endif; ?>
                <form class="ph-newsletter-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="persiano_newsletter_subscribe">
                    <input type="hidden" name="source" value="<?php echo esc_attr( $args['source'] ); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_url( self::current_url() ); ?>">
                    <?php wp_nonce_field( 'persiano_newsletter_subscribe', 'persiano_newsletter_nonce' ); ?>
                    <div class="ph-newsletter-fields">
                        <label>
                            <span class="screen-reader-text"><?php esc_html_e( 'First name', 'persiano-hub' ); ?></span>
                            <input type="text" name="name" autocomplete="given-name" placeholder="<?php esc_attr_e( 'First name', 'persiano-hub' ); ?>">
                        </label>
                        <label>
                            <span class="screen-reader-text"><?php esc_html_e( 'Email address', 'persiano-hub' ); ?></span>
                            <input type="email" name="email" autocomplete="email" placeholder="<?php esc_attr_e( 'Email address', 'persiano-hub' ); ?>" required>
                        </label>
                        <button type="submit"><?php esc_html_e( 'Join the List', 'persiano-hub' ); ?></button>
                    </div>
                    <label class="ph-newsletter-consent">
                        <input type="checkbox" name="consent" value="yes" required>
                        <span><?php echo esc_html( self::consent_text() ); ?></span>
                    </label>
                </form>
            </div>
        </section>
        <?php
    }

    public static function shortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'source' => 'shortcode',
                'title'  => '',
            ),
            $atts,
            'persiano_newsletter_signup'
        );
        ob_start();
        self::render_form(
            array(
                'source' => $atts['source'],
                'title'  => $atts['title'] ?: __( 'Be first to know what’s cooking.', 'persiano-hub' ),
            )
        );
        return ob_get_clean();
    }

    public static function handle_subscribe() {
        $nonce = isset( $_POST['persiano_newsletter_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_newsletter_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'persiano_newsletter_subscribe' ) ) {
            wp_die( esc_html__( 'The form expired. Please go back and try again.', 'persiano-hub' ), 403 );
        }

        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/' );
        $consent = isset( $_POST['consent'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['consent'] ) );
        if ( ! $consent ) {
            wp_safe_redirect( add_query_arg( 'persiano_subscribe', 'error', $redirect ) );
            exit;
        }

        $result = self::subscribe(
            isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '',
            isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
            isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : 'website'
        );

        wp_safe_redirect( add_query_arg( 'persiano_subscribe', is_wp_error( $result ) ? 'error' : 'success', $redirect ) );
        exit;
    }

    public static function handle_unsubscribe() {
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $success = self::unsubscribe_by_token( $token );

        wp_die(
            $success
                ? '<h1>' . esc_html__( 'You’re unsubscribed.', 'persiano-hub' ) . '</h1><p>' . esc_html( sprintf( __( 'You will no longer receive %s marketing emails.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ) . '</p>'
                : '<h1>' . esc_html__( 'We could not find that subscription.', 'persiano-hub' ) . '</h1>',
            esc_html( sprintf( __( '%s Mailing List', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ),
            array( 'response' => $success ? 200 : 404 )
        );
    }

    public static function register_block_checkout_field() {
        if ( is_user_logged_in() ) { $uid=get_current_user_id(); if ( '' !== get_user_meta( $uid, '_persiano_marketing_email', true ) || '' !== get_user_meta( $uid, '_persiano_marketing_sms', true ) ) { return; } }
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
            return;
        }

        woocommerce_register_additional_checkout_field(
            array(
                'id'            => self::CHECKOUT_FIELD_ID,
                'label'         => self::consent_text(),
                'optionalLabel' => self::consent_text(),
                'location'      => 'contact',
                'type'          => 'checkbox',
                'required'      => false,
            )
        );

        if ( class_exists( 'Persiano_Hub_Customer_Accounts' ) ) {
            woocommerce_register_additional_checkout_field(
                array(
                    'id'            => self::SMS_CHECKOUT_FIELD_ID,
                    'label'         => Persiano_Hub_Customer_Accounts::sms_consent_text(),
                    'optionalLabel' => Persiano_Hub_Customer_Accounts::sms_consent_text(),
                    'location'      => 'contact',
                    'type'          => 'checkbox',
                    'required'      => false,
                )
            );
        }
    }

    public static function render_classic_checkout_opt_in() {
        if ( is_user_logged_in() && class_exists( 'Persiano_Hub_Customer_Accounts' ) ) {
            $user_id = get_current_user_id();
            $email_choice = get_user_meta( $user_id, '_persiano_marketing_email', true );
            $sms_choice = get_user_meta( $user_id, '_persiano_marketing_sms', true );
            if ( '' !== $email_choice || '' !== $sms_choice ) {
                echo '<p class="ph-marketing-managed-note">' . esc_html__( 'Marketing preferences are managed in My Account → Profile & Preferences.', 'persiano-hub' ) . '</p>';
                return;
            }
        }
        ?>
        <fieldset class="ph-checkout-newsletter ph-consent-box">
            <legend><?php esc_html_e( 'Marketing preferences', 'persiano-hub' ); ?></legend>
            <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="persiano_newsletter_opt_in" value="1">
                <span><?php echo esc_html( self::consent_text() ); ?></span>
            </label>
            <?php if ( class_exists( 'Persiano_Hub_Customer_Accounts' ) ) : ?>
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="persiano_sms_opt_in" value="1">
                    <span><?php echo esc_html( Persiano_Hub_Customer_Accounts::sms_consent_text() ); ?></span>
                </label>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    public static function validate_classic_checkout_opt_in( $data, $errors ) {
        if ( ! empty( $_POST['persiano_sms_opt_in'] ) && empty( $data['billing_phone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $errors->add( 'persiano_sms_phone_required', __( 'Enter a phone number before choosing SMS marketing.', 'persiano-hub' ) );
        }
    }

    public static function validate_block_checkout_opt_in( $errors, $field_key, $field_value ) {
        if ( self::SMS_CHECKOUT_FIELD_ID !== $field_key || ! $field_value ) {
            return;
        }
        $phone = function_exists( 'WC' ) && WC()->customer ? WC()->customer->get_billing_phone() : '';
        if ( ! $phone ) {
            $errors->add( 'persiano_sms_phone_required', __( 'Enter a phone number before choosing SMS marketing.', 'persiano-hub' ) );
        }
    }

    public static function process_classic_checkout_opt_in( $order_id, $posted_data = array(), $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        if ( ! empty( $_POST['persiano_newsletter_opt_in'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            self::subscribe_from_order( $order, 'checkout' );
        }
        if ( ! empty( $_POST['persiano_sms_opt_in'] ) && class_exists( 'Persiano_Hub_Customer_Accounts' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            Persiano_Hub_Customer_Accounts::set_marketing_consent( 'sms', $order->get_billing_phone(), true, 'checkout', $order->get_customer_id(), $order->get_id() );
        }
    }

    public static function process_block_checkout_opt_in( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $value = $order->get_meta( '_wc_other/' . self::CHECKOUT_FIELD_ID, true );
        if ( '1' === (string) $value || true === $value || 1 === $value ) {
            self::subscribe_from_order( $order, 'checkout' );
        }
        $sms_value = $order->get_meta( '_wc_other/' . self::SMS_CHECKOUT_FIELD_ID, true );
        if ( ( '1' === (string) $sms_value || true === $sms_value || 1 === $sms_value ) && class_exists( 'Persiano_Hub_Customer_Accounts' ) ) {
            Persiano_Hub_Customer_Accounts::set_marketing_consent( 'sms', $order->get_billing_phone(), true, 'checkout', $order->get_customer_id(), $order->get_id() );
        }
    }

    private static function subscribe_from_order( $order, $source ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $email = $order->get_billing_email();
        $name = trim( $order->get_billing_first_name() );
        if ( $email ) {
            if ( class_exists( 'Persiano_Hub_Customer_Accounts' ) ) {
                Persiano_Hub_Customer_Accounts::set_marketing_consent( 'email', $email, true, $source, $order->get_customer_id(), $order->get_id(), self::consent_text() );
            } else {
                self::subscribe( $email, $name, $source, array( 'tags' => 'customer', 'consent_text' => self::consent_text() ) );
            }
        }
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Mailing List', 'persiano-hub' ),
            __( 'Mailing List', 'persiano-hub' ),
            'manage_woocommerce',
            'persiano-mailing-list',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function get_active_subscribers() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'subscribed' ORDER BY updated_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function render_admin_page() {
        global $wpdb;
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $table = self::table_name();
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tag = isset( $_GET['tag'] ) ? sanitize_key( wp_unslash( $_GET['tag'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $where = array( '1=1' );
        $params = array();
        if ( $search ) {
            $where[] = '(email LIKE %s OR name LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ( in_array( $status, array( 'subscribed', 'unsubscribed' ), true ) ) {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        if ( $source ) {
            $where[] = 'source = %s';
            $params[] = $source;
        }
        if ( $tag ) {
            $where[] = 'FIND_IN_SET(%s, tags)';
            $params[] = $tag;
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT 1000';
        if ( $params ) {
            $sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $subscribers = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $active_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'subscribed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $unsubscribed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'unsubscribed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $sources = $wpdb->get_col( "SELECT DISTINCT source FROM {$table} WHERE source <> '' ORDER BY source ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $all_tags = array();
        foreach ( $wpdb->get_col( "SELECT tags FROM {$table} WHERE tags <> ''" ) as $tag_string ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $all_tags = array_merge( $all_tags, array_filter( array_map( 'sanitize_key', explode( ',', (string) $tag_string ) ) ) );
        }
        $all_tags = array_values( array_unique( $all_tags ) );
        sort( $all_tags );
        ?>
        <div class="wrap ph-mailing-list-wrap">
            <span class="ph-admin-eyebrow"><?php esc_html_e( 'Batchly', 'persiano-hub' ); ?></span>
            <h1><?php esc_html_e( 'Mailing List', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'Manage consented subscribers, audience tags and CSV imports. Email campaigns are sent from the Publishing screen by selecting the Email channel.', 'persiano-hub' ); ?></p>
            <?php if ( ! empty( $_GET['ph_notice'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ph_notice'] ) ) ); ?></p></div><?php endif; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>

            <div class="ph-newsletter-stats">
                <div><strong><?php echo esc_html( number_format_i18n( $active_count ) ); ?></strong><span><?php esc_html_e( 'Active subscribers', 'persiano-hub' ); ?></span></div>
                <div><strong><?php echo esc_html( number_format_i18n( $unsubscribed_count ) ); ?></strong><span><?php esc_html_e( 'Unsubscribed', 'persiano-hub' ); ?></span></div>
                <div><strong><?php echo esc_html( number_format_i18n( count( $all_tags ) ) ); ?></strong><span><?php esc_html_e( 'Audience tags', 'persiano-hub' ); ?></span></div>
            </div>

            <div class="ph-newsletter-grid">
                <section class="ph-newsletter-panel">
                    <h2><?php esc_html_e( 'Add subscriber', 'persiano-hub' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="persiano_newsletter_save_subscriber">
                        <?php wp_nonce_field( 'persiano_newsletter_save_subscriber' ); ?>
                        <div class="ph-newsletter-form-grid">
                            <label><span><?php esc_html_e( 'Email', 'persiano-hub' ); ?></span><input type="email" name="email" required></label>
                            <label><span><?php esc_html_e( 'First name', 'persiano-hub' ); ?></span><input type="text" name="name"></label>
                            <label><span><?php esc_html_e( 'Tags', 'persiano-hub' ); ?></span><input type="text" name="tags" placeholder="weekly-menu, pantry"></label>
                            <label><span><?php esc_html_e( 'Source', 'persiano-hub' ); ?></span><input type="text" name="source" value="manual"></label>
                        </div>
                        <p><button class="button button-primary"><?php esc_html_e( 'Add / Update Subscriber', 'persiano-hub' ); ?></button></p>
                    </form>
                </section>

                <section class="ph-newsletter-panel">
                    <h2><?php esc_html_e( 'Import subscribers', 'persiano-hub' ); ?></h2>
                    <p><?php esc_html_e( 'Import a CSV with headers such as Email, Name, Status, Source and Tags. Existing emails are updated instead of duplicated.', 'persiano-hub' ); ?></p>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="persiano_newsletter_import">
                        <?php wp_nonce_field( 'persiano_newsletter_import' ); ?>
                        <input type="file" name="subscriber_csv" accept=".csv,text/csv" required>
                        <p><button class="button"><?php esc_html_e( 'Import CSV', 'persiano-hub' ); ?></button> <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=persiano_newsletter_export' ), 'persiano_newsletter_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'persiano-hub' ); ?></a></p>
                    </form>
                </section>
            </div>

            <form method="get" class="ph-newsletter-filters">
                <input type="hidden" name="page" value="persiano-mailing-list">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email or name', 'persiano-hub' ); ?>">
                <select name="status"><option value=""><?php esc_html_e( 'All statuses', 'persiano-hub' ); ?></option><option value="subscribed" <?php selected( $status, 'subscribed' ); ?>><?php esc_html_e( 'Subscribed', 'persiano-hub' ); ?></option><option value="unsubscribed" <?php selected( $status, 'unsubscribed' ); ?>><?php esc_html_e( 'Unsubscribed', 'persiano-hub' ); ?></option></select>
                <select name="source"><option value=""><?php esc_html_e( 'All sources', 'persiano-hub' ); ?></option><?php foreach ( $sources as $source_option ) : ?><option value="<?php echo esc_attr( $source_option ); ?>" <?php selected( $source, $source_option ); ?>><?php echo esc_html( $source_option ); ?></option><?php endforeach; ?></select>
                <select name="tag"><option value=""><?php esc_html_e( 'All tags', 'persiano-hub' ); ?></option><?php foreach ( $all_tags as $tag_option ) : ?><option value="<?php echo esc_attr( $tag_option ); ?>" <?php selected( $tag, $tag_option ); ?>><?php echo esc_html( $tag_option ); ?></option><?php endforeach; ?></select>
                <button class="button"><?php esc_html_e( 'Filter', 'persiano-hub' ); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="persiano_newsletter_bulk">
                <?php wp_nonce_field( 'persiano_newsletter_bulk' ); ?>
                <div class="ph-newsletter-bulk">
                    <select name="bulk_action" required><option value=""><?php esc_html_e( 'Bulk actions', 'persiano-hub' ); ?></option><option value="subscribe"><?php esc_html_e( 'Mark subscribed', 'persiano-hub' ); ?></option><option value="unsubscribe"><?php esc_html_e( 'Mark unsubscribed', 'persiano-hub' ); ?></option><option value="add_tag"><?php esc_html_e( 'Add tag', 'persiano-hub' ); ?></option><option value="remove_tag"><?php esc_html_e( 'Remove tag', 'persiano-hub' ); ?></option><option value="delete"><?php esc_html_e( 'Delete permanently', 'persiano-hub' ); ?></option></select>
                    <input type="text" name="bulk_tag" placeholder="<?php esc_attr_e( 'Tag for add/remove', 'persiano-hub' ); ?>">
                    <button class="button"><?php esc_html_e( 'Apply', 'persiano-hub' ); ?></button>
                </div>
                <div class="ph-responsive-table"><table class="widefat striped">
                    <thead><tr><td class="check-column"><input type="checkbox" onclick="var c=this.checked;document.querySelectorAll('.ph-subscriber-check').forEach(function(el){el.checked=c;});"></td><th><?php esc_html_e( 'Email', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Name', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Status', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Source', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Tags', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Consent date', 'persiano-hub' ); ?></th></tr></thead>
                    <tbody>
                    <?php if ( $subscribers ) : foreach ( $subscribers as $subscriber ) : ?>
                        <tr>
                            <th class="check-column"><input class="ph-subscriber-check" type="checkbox" name="subscriber_ids[]" value="<?php echo esc_attr( $subscriber->id ); ?>"></th>
                            <td><strong><?php echo esc_html( $subscriber->email ); ?></strong></td>
                            <td><?php echo esc_html( $subscriber->name ); ?></td>
                            <td><span class="ph-subscriber-status ph-subscriber-status--<?php echo esc_attr( $subscriber->status ); ?>"><?php echo esc_html( ucfirst( $subscriber->status ) ); ?></span></td>
                            <td><?php echo esc_html( $subscriber->source ); ?></td>
                            <td><?php echo esc_html( $subscriber->tags ); ?></td>
                            <td><?php echo esc_html( $subscriber->consent_at ?: $subscriber->created_at ); ?></td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No subscribers match these filters.', 'persiano-hub' ); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
            </form>
        </div>
        <?php
    }

    public static function save_subscriber_admin() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage subscribers.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_newsletter_save_subscriber' );
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'manual';
        $tags = isset( $_POST['tags'] ) ? self::normalize_tags( wp_unslash( $_POST['tags'] ) ) : '';
        $result = self::subscribe( $email, $name, $source, array( 'tags' => $tags, 'consent_text' => sprintf( __( 'Added manually by an authorized %s administrator.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ) );
        $message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Subscriber saved.', 'persiano-hub' );
        wp_safe_redirect( add_query_arg( 'ph_notice', $message, admin_url( 'admin.php?page=persiano-mailing-list' ) ) );
        exit;
    }

    public static function bulk_action_admin() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage subscribers.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_newsletter_bulk' );
        global $wpdb;
        $ids = isset( $_POST['subscriber_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['subscriber_ids'] ) ) ) ) : array();
        $action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $tag = isset( $_POST['bulk_tag'] ) ? sanitize_key( wp_unslash( $_POST['bulk_tag'] ) ) : '';
        if ( ! $ids || ! $action ) {
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-mailing-list' ) );
            exit;
        }
        $table = self::table_name();
        foreach ( $ids as $id ) {
            if ( 'delete' === $action ) {
                $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
                continue;
            }
            if ( in_array( $action, array( 'subscribe', 'unsubscribe' ), true ) ) {
                $wpdb->update( $table, array( 'status' => 'subscribe' === $action ? 'subscribed' : 'unsubscribed', 'updated_at' => current_time( 'mysql' ), 'unsubscribed_at' => 'unsubscribe' === $action ? current_time( 'mysql' ) : null ), array( 'id' => $id ) );
                continue;
            }
            if ( $tag && in_array( $action, array( 'add_tag', 'remove_tag' ), true ) ) {
                $current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT tags FROM {$table} WHERE id=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $tags = array_filter( array_map( 'sanitize_key', explode( ',', $current ) ) );
                if ( 'add_tag' === $action ) {
                    $tags[] = $tag;
                } else {
                    $tags = array_values( array_diff( $tags, array( $tag ) ) );
                }
                $wpdb->update( $table, array( 'tags' => implode( ',', array_unique( $tags ) ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
            }
        }
        wp_safe_redirect( add_query_arg( 'ph_notice', __( 'Bulk action completed.', 'persiano-hub' ), admin_url( 'admin.php?page=persiano-mailing-list' ) ) );
        exit;
    }

    public static function import_csv_admin() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to import subscribers.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_newsletter_import' );
        if ( empty( $_FILES['subscriber_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['subscriber_csv']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'ph_notice', __( 'No CSV file was uploaded.', 'persiano-hub' ), admin_url( 'admin.php?page=persiano-mailing-list' ) ) );
            exit;
        }
        $handle = fopen( $_FILES['subscriber_csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! $handle ) {
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-mailing-list' ) );
            exit;
        }
        $headers = fgetcsv( $handle );
        $headers = array_map( static function( $v ) { return sanitize_key( str_replace( ' ', '_', (string) $v ) ); }, (array) $headers );
        $count = 0;
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $data = array_combine( $headers, array_slice( array_pad( $row, count( $headers ), '' ), 0, count( $headers ) ) );
            if ( ! $data ) {
                continue;
            }
            $email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
            if ( ! is_email( $email ) ) {
                continue;
            }
            $name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : ( isset( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '' );
            $source = isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'csv_import';
            $tags = isset( $data['tags'] ) ? self::normalize_tags( $data['tags'] ) : '';
            $result = self::subscribe( $email, $name, $source, array( 'tags' => $tags, 'consent_text' => sprintf( __( 'Imported by an authorized %s administrator.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ) );
            if ( ! is_wp_error( $result ) ) {
                $count++;
                if ( isset( $data['status'] ) && 'unsubscribed' === sanitize_key( $data['status'] ) ) {
                    global $wpdb;
                    $wpdb->update( self::table_name(), array( 'status' => 'unsubscribed', 'unsubscribed_at' => current_time( 'mysql' ) ), array( 'email' => $email ) );
                }
            }
        }
        fclose( $handle );
        wp_safe_redirect( add_query_arg( 'ph_notice', sprintf( __( 'Imported or updated %d subscribers.', 'persiano-hub' ), $count ), admin_url( 'admin.php?page=persiano-mailing-list' ) ) );
        exit;
    }

    public static function export_csv() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to export subscribers.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_newsletter_export' );

        global $wpdb;
        $table = self::table_name();
        $rows = $wpdb->get_results( "SELECT email, name, status, source, tags, notes, consent_at, unsubscribed_at FROM {$table} ORDER BY updated_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=persiano-mailing-list-' . gmdate( 'Y-m-d' ) . '.csv' );
        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, array( 'Email', 'Name', 'Status', 'Source', 'Tags', 'Notes', 'Consent Date', 'Unsubscribed Date' ) );
        foreach ( $rows as $row ) {
            fputcsv( $output, $row );
        }
        fclose( $output );
        exit;
    }

    private static function normalize_tags( $tags ) {
        $tags = is_array( $tags ) ? $tags : preg_split( '/[;,]+/', (string) $tags );
        $tags = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $tags ) ) ) );
        return implode( ',', $tags );
    }

    private static function request_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
        return sanitize_text_field( $ip );
    }

    private static function request_user_agent() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
        return sanitize_textarea_field( $ua );
    }

    private static function current_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        return esc_url_raw( $scheme . $host . $uri );
    }
}
