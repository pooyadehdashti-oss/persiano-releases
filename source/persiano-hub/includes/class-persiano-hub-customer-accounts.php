<?php
/**
 * Customer accounts, favourites, loyalty and marketing consent.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Customer_Accounts {
    const CONSENT_TABLE_SUFFIX = 'persiano_consents';
    const FAVORITES_ENDPOINT   = 'favorites';
    const LOYALTY_ENDPOINT     = 'loyalty';
    const MIN_REDEMPTION_POINTS = 100;
    const POINTS_PER_DOLLAR    = 1;
    const POINTS_PER_REWARD    = 100;
    const REWARD_VALUE         = 5;

    /** @var bool */
    private static $saving_order_fulfilment = false;

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_endpoints' ) );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ) );
        add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_dashboard' ), 20 );
        add_action( 'woocommerce_account_' . self::FAVORITES_ENDPOINT . '_endpoint', array( __CLASS__, 'render_favorites' ) );
        add_action( 'woocommerce_account_' . self::LOYALTY_ENDPOINT . '_endpoint', array( __CLASS__, 'render_loyalty' ) );

        add_action( 'woocommerce_register_form_start', array( __CLASS__, 'registration_intro' ) );
        add_action( 'woocommerce_register_form', array( __CLASS__, 'registration_fields' ) );
        add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'validate_registration' ), 10, 3 );
        add_action( 'woocommerce_created_customer', array( __CLASS__, 'save_registration' ), 10, 3 );

        add_action( 'woocommerce_edit_account_form', array( __CLASS__, 'profile_fields' ) );
        add_action( 'woocommerce_save_account_details_errors', array( __CLASS__, 'validate_profile' ), 10, 2 );
        add_action( 'woocommerce_save_account_details', array( __CLASS__, 'save_profile' ) );

        add_action( 'template_redirect', array( __CLASS__, 'handle_magic_payment_access' ), 1 );
        add_action( 'template_redirect', array( __CLASS__, 'handle_favorite_toggle' ), 5 );
        add_action( 'template_redirect', array( __CLASS__, 'handle_quick_reorder' ), 5 );
        add_action( 'template_redirect', array( __CLASS__, 'handle_apply_order_reward' ), 5 );

        add_action( 'persiano_dish_product_card_details', array( __CLASS__, 'product_card_favorite' ), 20 );
        add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'loop_favorite' ), 12 );
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_favorite' ), 35 );

        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'award_order_points' ) );
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'award_order_points' ) );
        add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'reverse_order_loyalty' ) );
        add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'reverse_order_loyalty' ) );
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_link_order_and_invite' ) );
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_link_order_and_invite' ), 5 );
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_link_order_and_invite' ), 5 );

        add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_checkout_loyalty' ), 3 );
        add_action( 'woocommerce_pay_order_before_payment', array( __CLASS__, 'render_order_pay_loyalty' ), 3 );
        add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'update_checkout_loyalty_session' ) );
        add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_checkout_loyalty_fee' ), 30 );
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'store_checkout_loyalty_on_order' ), 30, 2 );
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'deduct_checkout_loyalty' ), 30, 3 );

        add_action( 'woocommerce_thankyou', array( __CLASS__, 'guest_account_prompt' ), 20 );
        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_payment_access_button' ), 12, 4 );

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ) );
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::consent_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            recorded_by bigint(20) unsigned NOT NULL DEFAULT 0,
            channel varchar(20) NOT NULL,
            contact varchar(190) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL,
            source varchar(80) NOT NULL DEFAULT '',
            consent_text text NULL,
            recorded_at datetime NOT NULL,
            ip_address varchar(100) NOT NULL DEFAULT '',
            user_agent text NULL,
            PRIMARY KEY  (id),
            KEY user_channel (user_id,channel),
            KEY contact_channel (contact,channel),
            KEY order_id (order_id),
            KEY recorded_by (recorded_by),
            KEY recorded_at (recorded_at)
        ) {$charset_collate};";
        dbDelta( $sql );
    }

    public static function consent_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::CONSENT_TABLE_SUFFIX;
    }

    public static function email_consent_text() {
        $brand = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( get_bloginfo( 'name' ) ?: 'This business' );
        $email = function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : sanitize_email( get_option( 'admin_email' ) );
        $area  = function_exists( 'persiano_hub_business_value' ) ? persiano_hub_business_value( 'service_area', '' ) : '';
        $identity = trim( $brand . ( $area || $email ? ' (' . implode( ', ', array_filter( array( $area, $email ) ) ) . ')' : '' ) );
        return sprintf( __( 'Yes, %s may send me menus, product updates, events and special offers by email. Consent is optional; I can unsubscribe from any marketing email or change this choice in Profile & Preferences.', 'persiano-hub' ), $identity );
    }

    public static function sms_consent_text() {
        $brand = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( get_bloginfo( 'name' ) ?: 'This business' );
        return sprintf( __( 'Yes, %s may send me occasional promotional text messages. Consent is optional, message and data rates may apply, and I can withdraw consent in Profile & Preferences or by contacting %s.', 'persiano-hub' ), $brand, $brand );
    }

    public static function register_endpoints() {
        add_rewrite_endpoint( self::FAVORITES_ENDPOINT, EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( self::LOYALTY_ENDPOINT, EP_ROOT | EP_PAGES );
    }

    public static function query_vars( $vars ) {
        $vars[] = self::FAVORITES_ENDPOINT;
        $vars[] = self::LOYALTY_ENDPOINT;
        return $vars;
    }

    public static function account_menu_items( $items ) {
        $logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : __( 'Sign out', 'persiano-hub' );
        unset( $items['customer-logout'], $items['downloads'] );

        $ordered = array();
        $ordered['dashboard'] = __( 'Dashboard', 'persiano-hub' );
        $ordered['orders'] = __( 'My Orders', 'persiano-hub' );
        $ordered[ self::FAVORITES_ENDPOINT ] = __( 'Favourites', 'persiano-hub' );
        $ordered[ self::LOYALTY_ENDPOINT ] = __( 'Loyalty & Rewards', 'persiano-hub' );
        if ( isset( $items['edit-address'] ) ) {
            $ordered['edit-address'] = __( 'Addresses', 'persiano-hub' );
        }
        if ( isset( $items['payment-methods'] ) ) {
            $ordered['payment-methods'] = __( 'Payment Methods', 'persiano-hub' );
        }
        $ordered['edit-account'] = __( 'Profile & Preferences', 'persiano-hub' );
        $ordered['customer-logout'] = $logout;
        return $ordered;
    }

    private static function claim_order_from_request() {
        $claim_order_id = isset( $_GET['persiano_claim_order'] ) ? absint( $_GET['persiano_claim_order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $claim_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $claim_order_id || ! $claim_key ) {
            return false;
        }
        $order = wc_get_order( $claim_order_id );
        return $order instanceof WC_Order && hash_equals( $order->get_order_key(), $claim_key ) ? $order : false;
    }

    public static function registration_intro() {
        $order = self::claim_order_from_request();
        $message = __( 'Save addresses, view orders, keep favourites and earn loyalty rewards. You can still order as a guest whenever you prefer.', 'persiano-hub' );
        if ( $order ) {
            $message = sprintf( __( 'Create the account using %s to connect order #%s, save it in your history and receive its loyalty points.', 'persiano-hub' ), $order->get_billing_email(), $order->get_order_number() );
        }
        echo '<div class="ph-account-intro"><strong>' . esc_html__( 'Create your Persiano account', 'persiano-hub' ) . '</strong><p>' . esc_html( $message ) . '</p></div>';
    }

    public static function registration_fields() {
        $claim_order = self::claim_order_from_request();
        $first = isset( $_POST['persiano_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_first_name'] ) ) : ( $claim_order ? $claim_order->get_billing_first_name() : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $last = isset( $_POST['persiano_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_last_name'] ) ) : ( $claim_order ? $claim_order->get_billing_last_name() : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $phone = isset( $_POST['persiano_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_phone'] ) ) : ( $claim_order ? $claim_order->get_billing_phone() : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
            <label for="reg_persiano_first_name"><?php esc_html_e( 'First name', 'persiano-hub' ); ?>&nbsp;<span class="required">*</span></label>
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="persiano_first_name" id="reg_persiano_first_name" autocomplete="given-name" value="<?php echo esc_attr( $first ); ?>" required>
        </p>
        <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
            <label for="reg_persiano_last_name"><?php esc_html_e( 'Last name', 'persiano-hub' ); ?>&nbsp;<span class="required">*</span></label>
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="persiano_last_name" id="reg_persiano_last_name" autocomplete="family-name" value="<?php echo esc_attr( $last ); ?>" required>
        </p>
        <div class="clear"></div>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="reg_persiano_phone"><?php esc_html_e( 'Phone number', 'persiano-hub' ); ?></label>
            <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="persiano_phone" id="reg_persiano_phone" autocomplete="tel" value="<?php echo esc_attr( $phone ); ?>">
        </p>
        <?php if ( $claim_order && $claim_order->get_billing_email() ) : ?>
            <script>document.addEventListener('DOMContentLoaded',function(){var email=document.getElementById('reg_email');if(email&&!email.value){email.value=<?php echo wp_json_encode( $claim_order->get_billing_email() ); ?>;}});</script>
        <?php endif; ?>
        <fieldset class="ph-consent-box">
            <legend><?php esc_html_e( 'Marketing preferences', 'persiano-hub' ); ?></legend>
            <label><input type="checkbox" name="persiano_email_marketing" value="yes"> <span><?php echo esc_html( self::email_consent_text() ); ?></span></label>
            <label><input type="checkbox" name="persiano_sms_marketing" value="yes"> <span><?php echo esc_html( self::sms_consent_text() ); ?></span></label>
        </fieldset>
        <?php
    }

    public static function validate_registration( $errors, $username, $email ) {
        $first = isset( $_POST['persiano_first_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['persiano_first_name'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $last = isset( $_POST['persiano_last_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['persiano_last_name'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $phone = isset( $_POST['persiano_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! $first ) {
            $errors->add( 'persiano_first_name', __( 'Please enter your first name.', 'persiano-hub' ) );
        }
        if ( ! $last ) {
            $errors->add( 'persiano_last_name', __( 'Please enter your last name.', 'persiano-hub' ) );
        }
        if ( isset( $_POST['persiano_sms_marketing'] ) && ! $phone ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $errors->add( 'persiano_sms_phone', __( 'Enter a phone number before choosing SMS marketing.', 'persiano-hub' ) );
        }
        $claim_order = self::claim_order_from_request();
        if ( $claim_order && $claim_order->get_billing_email() && 0 !== strcasecmp( $claim_order->get_billing_email(), $email ) ) {
            $errors->add( 'persiano_claim_email', sprintf( __( 'Use %s to connect this order to the new account.', 'persiano-hub' ), $claim_order->get_billing_email() ) );
        }
        return $errors;
    }

    public static function save_registration( $customer_id, $new_customer_data = array(), $password_generated = false ) {
        $first = isset( $_POST['persiano_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_first_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $last = isset( $_POST['persiano_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_last_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $phone = isset( $_POST['persiano_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $customer = new WC_Customer( $customer_id );
        if ( $first ) {
            $customer->set_first_name( $first );
            $customer->set_billing_first_name( $first );
        }
        if ( $last ) {
            $customer->set_last_name( $last );
            $customer->set_billing_last_name( $last );
        }
        if ( $phone ) {
            $customer->set_billing_phone( $phone );
        }
        $customer->save();

        $email = $customer->get_email();
        if ( isset( $_POST['persiano_email_marketing'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            self::set_marketing_consent( 'email', $email, true, 'account_registration', $customer_id );
        }
        if ( isset( $_POST['persiano_sms_marketing'] ) && $phone ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            self::set_marketing_consent( 'sms', $phone, true, 'account_registration', $customer_id );
        }
        self::link_guest_orders_to_customer( $customer_id );
    }

    public static function profile_fields() {
        $user_id = get_current_user_id();
        $customer = new WC_Customer( $user_id );
        $preferred = get_user_meta( $user_id, '_persiano_preferred_contact', true );
        $language = get_user_meta( $user_id, '_persiano_language', true );
        $dietary = get_user_meta( $user_id, '_persiano_dietary_preferences', true );
        $allergies = get_user_meta( $user_id, '_persiano_allergy_notes', true );
        $email_consent = self::has_active_consent( 'email', $customer->get_email(), $user_id );
        $sms_consent = self::has_active_consent( 'sms', $customer->get_billing_phone(), $user_id );
        ?>
        <fieldset class="ph-account-fieldset">
            <legend><?php esc_html_e( 'Contact information', 'persiano-hub' ); ?></legend>
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="persiano_account_phone"><?php esc_html_e( 'Phone number', 'persiano-hub' ); ?></label>
                <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="persiano_account_phone" id="persiano_account_phone" autocomplete="tel" value="<?php echo esc_attr( $customer->get_billing_phone() ); ?>">
            </p>
            <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
                <label for="persiano_preferred_contact"><?php esc_html_e( 'Preferred contact method', 'persiano-hub' ); ?></label>
                <select name="persiano_preferred_contact" id="persiano_preferred_contact">
                    <option value="email" <?php selected( $preferred, 'email' ); ?>><?php esc_html_e( 'Email', 'persiano-hub' ); ?></option>
                    <option value="phone" <?php selected( $preferred, 'phone' ); ?>><?php esc_html_e( 'Phone call', 'persiano-hub' ); ?></option>
                    <option value="sms" <?php selected( $preferred, 'sms' ); ?>><?php esc_html_e( 'Text message', 'persiano-hub' ); ?></option>
                    <option value="whatsapp" <?php selected( $preferred, 'whatsapp' ); ?>><?php esc_html_e( 'WhatsApp', 'persiano-hub' ); ?></option>
                </select>
            </p>
            <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
                <label for="persiano_language"><?php esc_html_e( 'Language preference', 'persiano-hub' ); ?></label>
                <select name="persiano_language" id="persiano_language">
                    <option value="english" <?php selected( $language, 'english' ); ?>><?php esc_html_e( 'English', 'persiano-hub' ); ?></option>
                    <option value="farsi" <?php selected( $language, 'farsi' ); ?>><?php esc_html_e( 'فارسی', 'persiano-hub' ); ?></option>
                    <option value="both" <?php selected( $language, 'both' ); ?>><?php esc_html_e( 'English and فارسی', 'persiano-hub' ); ?></option>
                </select>
            </p>
            <div class="clear"></div>
        </fieldset>
        <fieldset class="ph-account-fieldset">
            <legend><?php esc_html_e( 'Food preferences', 'persiano-hub' ); ?></legend>
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="persiano_dietary_preferences"><?php esc_html_e( 'Dietary preferences', 'persiano-hub' ); ?></label>
                <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="persiano_dietary_preferences" id="persiano_dietary_preferences" value="<?php echo esc_attr( $dietary ); ?>" placeholder="Vegetarian, halal, gluten-free…">
            </p>
            <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
                <label for="persiano_allergy_notes"><?php esc_html_e( 'Allergy or ingredient notes', 'persiano-hub' ); ?></label>
                <textarea name="persiano_allergy_notes" id="persiano_allergy_notes" rows="3"><?php echo esc_textarea( $allergies ); ?></textarea>
                <small><?php echo esc_html( sprintf( __( 'Please confirm allergies with %s for every order. Saving a note here does not guarantee a product is allergen-free.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ); ?></small>
            </p>
        </fieldset>
        <fieldset class="ph-account-fieldset ph-consent-box">
            <legend><?php esc_html_e( 'Marketing preferences', 'persiano-hub' ); ?></legend>
            <label><input type="checkbox" name="persiano_email_marketing" value="yes" <?php checked( $email_consent ); ?>> <span><?php echo esc_html( self::email_consent_text() ); ?></span></label>
            <label><input type="checkbox" name="persiano_sms_marketing" value="yes" <?php checked( $sms_consent ); ?>> <span><?php echo esc_html( self::sms_consent_text() ); ?></span></label>
        </fieldset>
        <?php
    }

    public static function validate_profile( $errors, $user ) {
        $phone = isset( $_POST['persiano_account_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_account_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['persiano_sms_marketing'] ) && ! $phone ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $errors->add( 'persiano_sms_phone', __( 'Enter a phone number before enabling SMS marketing.', 'persiano-hub' ) );
        }
    }

    public static function save_profile( $user_id ) {
        $customer = new WC_Customer( $user_id );
        $old_phone = $customer->get_billing_phone();
        $phone = isset( $_POST['persiano_account_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_account_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $customer->set_billing_phone( $phone );
        $customer->save();

        update_user_meta( $user_id, '_persiano_preferred_contact', isset( $_POST['persiano_preferred_contact'] ) ? sanitize_key( wp_unslash( $_POST['persiano_preferred_contact'] ) ) : 'email' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_user_meta( $user_id, '_persiano_language', isset( $_POST['persiano_language'] ) ? sanitize_key( wp_unslash( $_POST['persiano_language'] ) ) : 'english' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_user_meta( $user_id, '_persiano_dietary_preferences', isset( $_POST['persiano_dietary_preferences'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_dietary_preferences'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_user_meta( $user_id, '_persiano_allergy_notes', isset( $_POST['persiano_allergy_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_allergy_notes'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $email = $customer->get_email();
        self::set_marketing_consent( 'email', $email, isset( $_POST['persiano_email_marketing'] ), 'account_profile', $user_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( $old_phone && $old_phone !== $phone && self::has_active_consent( 'sms', $old_phone, $user_id ) ) {
            self::set_marketing_consent( 'sms', $old_phone, false, 'account_profile_phone_changed', $user_id );
        }
        self::set_marketing_consent( 'sms', $phone, isset( $_POST['persiano_sms_marketing'] ) && $phone, 'account_profile', $user_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    public static function set_marketing_consent( $channel, $contact, $granted, $source, $user_id = 0, $order_id = 0, $consent_text = '' ) {
        global $wpdb;

        $channel = in_array( $channel, array( 'email', 'sms' ), true ) ? $channel : '';
        $contact = 'email' === $channel ? sanitize_email( $contact ) : sanitize_text_field( $contact );
        if ( ! $channel || ! $contact ) {
            return false;
        }

        $current = self::has_active_consent( $channel, $contact, $user_id );
        if ( (bool) $current === (bool) $granted ) {
            return true;
        }

        $text = $consent_text ? sanitize_textarea_field( $consent_text ) : ( 'email' === $channel ? self::email_consent_text() : self::sms_consent_text() );
        $wpdb->insert(
            self::consent_table_name(),
            array(
                'user_id'      => absint( $user_id ),
                'order_id'     => absint( $order_id ),
                'recorded_by'  => get_current_user_id(),
                'channel'      => $channel,
                'contact'      => $contact,
                'status'       => $granted ? 'granted' : 'withdrawn',
                'source'       => sanitize_key( $source ),
                'consent_text' => $text,
                'recorded_at'  => current_time( 'mysql' ),
                'ip_address'   => self::request_ip(),
                'user_agent'   => self::request_user_agent(),
            )
        );

        if ( $user_id ) {
            update_user_meta( $user_id, '_persiano_marketing_' . $channel, $granted ? 'yes' : 'no' );
            update_user_meta( $user_id, '_persiano_marketing_' . $channel . '_updated', current_time( 'mysql' ) );
        }

        if ( 'email' === $channel && class_exists( 'Persiano_Hub_Newsletter' ) ) {
            if ( $granted ) {
                $user = $user_id ? get_userdata( $user_id ) : false;
                $name = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
                Persiano_Hub_Newsletter::subscribe( $contact, $name, $source, array( 'tags' => 'customer', 'consent_text' => $text ) );
            } elseif ( method_exists( 'Persiano_Hub_Newsletter', 'unsubscribe_by_email' ) ) {
                Persiano_Hub_Newsletter::unsubscribe_by_email( $contact );
            }
        }
        return true;
    }

    public static function has_active_consent( $channel, $contact = '', $user_id = 0 ) {
        global $wpdb;
        $channel = sanitize_key( $channel );
        if ( $user_id ) {
            $meta = get_user_meta( $user_id, '_persiano_marketing_' . $channel, true );
            if ( 'yes' === $meta ) {
                return true;
            }
            if ( 'no' === $meta ) {
                return false;
            }
        }
        $contact = 'email' === $channel ? sanitize_email( $contact ) : sanitize_text_field( $contact );
        if ( ! $contact ) {
            return false;
        }
        $status = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . self::consent_table_name() . ' WHERE channel = %s AND contact = %s ORDER BY id DESC LIMIT 1',
                $channel,
                $contact
            )
        );
        if ( null !== $status ) {
            return 'granted' === $status;
        }

        // Preserve consent collected through the existing standalone email
        // signup form before the unified consent ledger was introduced.
        if ( 'email' === $channel && class_exists( 'Persiano_Hub_Newsletter' ) && method_exists( 'Persiano_Hub_Newsletter', 'table_name' ) ) {
            $newsletter_status = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT status FROM ' . Persiano_Hub_Newsletter::table_name() . ' WHERE email = %s ORDER BY id DESC LIMIT 1',
                    $contact
                )
            );
            return 'active' === $newsletter_status;
        }
        return false;
    }

    private static function request_ip() {
        if ( class_exists( 'WC_Geolocation' ) ) {
            return sanitize_text_field( WC_Geolocation::get_ip_address() );
        }
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    }

    private static function request_user_agent() {
        return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
    }

    public static function render_dashboard() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        $customer = new WC_Customer( $user_id );
        $points = self::get_points_balance( $user_id );
        $point_value = ( $points / self::POINTS_PER_REWARD ) * self::REWARD_VALUE;
        $redeemable_value = $points >= self::MIN_REDEMPTION_POINTS ? floor( $points / self::POINTS_PER_REWARD ) * self::REWARD_VALUE : 0;
        $pending_orders = wc_get_orders( array( 'customer_id' => $user_id, 'status' => array( 'pending', 'failed' ), 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ) );
        $favorites = self::get_favorites( $user_id );
        $orders = wc_get_orders( array( 'customer_id' => $user_id, 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
        $order_count = function_exists( 'wc_get_customer_order_count' ) ? wc_get_customer_order_count( $user_id ) : count( $orders );
        $most = self::get_most_ordered_products( $user_id, 4 );
        ?>
        <div class="ph-account-dashboard">
            <?php if ( $pending_orders ) : ?>
                <section class="ph-pending-actions"><div class="ph-account-section-head"><span><?php esc_html_e( 'Needs your attention', 'persiano-hub' ); ?></span><h2><?php esc_html_e( 'Pending actions', 'persiano-hub' ); ?></h2></div>
                <?php foreach ( $pending_orders as $pending_order ) : ?><div class="ph-pending-order"><div><strong><?php echo esc_html( sprintf( __( 'Payment required — Order #%s', 'persiano-hub' ), $pending_order->get_order_number() ) ); ?></strong><small><?php echo wp_kses_post( $pending_order->get_formatted_order_total() ); ?> · <?php echo esc_html( wc_get_order_status_name( $pending_order->get_status() ) ); ?></small></div><div class="ph-pending-order-actions"><a class="button" href="<?php echo esc_url( $pending_order->get_checkout_payment_url() ); ?>"><?php echo esc_html( sprintf( __( 'Pay %s securely', 'persiano-hub' ), wp_strip_all_tags( $pending_order->get_formatted_order_total() ) ) ); ?></a><a href="<?php echo esc_url( $pending_order->get_view_order_url() ); ?>"><?php esc_html_e( 'View order', 'persiano-hub' ); ?></a></div></div><?php endforeach; ?>
                </section>
            <?php endif; ?>
            <div class="ph-account-cards">
                <a class="ph-account-card" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>"><span><?php esc_html_e( 'Recent orders', 'persiano-hub' ); ?></span><strong><?php echo esc_html( number_format_i18n( $order_count ) ); ?></strong><small><?php esc_html_e( 'View and pay for orders', 'persiano-hub' ); ?></small></a>
                <a class="ph-account-card" href="<?php echo esc_url( wc_get_account_endpoint_url( self::LOYALTY_ENDPOINT ) ); ?>"><span><?php esc_html_e( 'Loyalty balance', 'persiano-hub' ); ?></span><strong><?php echo esc_html( number_format_i18n( $points ) ); ?> pts</strong><small><?php echo wp_kses_post( sprintf( __( 'Worth %1$s · Redeemable now %2$s', 'persiano-hub' ), wc_price( $point_value ), wc_price( $redeemable_value ) ) ); ?></small></a>
                <a class="ph-account-card" href="<?php echo esc_url( wc_get_account_endpoint_url( self::FAVORITES_ENDPOINT ) ); ?>"><span><?php esc_html_e( 'Favourites', 'persiano-hub' ); ?></span><strong><?php echo esc_html( count( $favorites ) ); ?></strong><small><?php esc_html_e( 'Saved dishes and pantry items', 'persiano-hub' ); ?></small></a>
                <a class="ph-account-card" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address' ) ); ?>"><span><?php esc_html_e( 'Default address', 'persiano-hub' ); ?></span><strong class="ph-account-card-address"><?php echo esc_html( $customer->get_billing_city() ? $customer->get_billing_city() : __( 'Add one', 'persiano-hub' ) ); ?></strong><small><?php esc_html_e( 'Manage delivery addresses', 'persiano-hub' ); ?></small></a>
            </div>
            <?php if ( $most ) : ?>
                <section class="ph-account-section">
                    <div class="ph-account-section-head"><div><span><?php esc_html_e( 'Your usual favourites', 'persiano-hub' ); ?></span><h2><?php esc_html_e( 'Most ordered', 'persiano-hub' ); ?></h2></div></div>
                    <div class="ph-account-product-grid">
                        <?php foreach ( $most as $row ) : self::render_account_product_card( $row['product'], $row['quantity'], true ); endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_favorites() {
        $ids = self::get_favorites( get_current_user_id() );
        echo '<div class="ph-account-section"><div class="ph-account-section-head"><div><span>' . esc_html__( 'Saved for later', 'persiano-hub' ) . '</span><h2>' . esc_html__( 'My favourites', 'persiano-hub' ) . '</h2></div></div>';
        if ( ! $ids ) {
            echo '<div class="ph-account-empty"><p>' . esc_html__( 'You have not saved any favourites yet. Use the heart button on a product to keep it here.', 'persiano-hub' ) . '</p><a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Browse products', 'persiano-hub' ) . '</a></div></div>';
            return;
        }
        echo '<div class="ph-account-product-grid">';
        foreach ( $ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product && 'publish' === get_post_status( $product_id ) ) {
                self::render_account_product_card( $product, 0, false );
            }
        }
        echo '</div></div>';
    }

    public static function render_loyalty() {
        $user_id = get_current_user_id();
        $balance = self::get_points_balance( $user_id );
        $lifetime = absint( get_user_meta( $user_id, '_persiano_loyalty_lifetime', true ) );
        $redeemable = floor( $balance / self::POINTS_PER_REWARD ) * self::REWARD_VALUE;
        $log = get_user_meta( $user_id, '_persiano_loyalty_log', true );
        $log = is_array( $log ) ? array_slice( $log, 0, 20 ) : array();
        ?>
        <div class="ph-loyalty-page">
            <div class="ph-loyalty-hero">
                <div><span><?php esc_html_e( 'Persiano Rewards', 'persiano-hub' ); ?></span><h2><?php echo esc_html( number_format_i18n( $balance ) ); ?> <?php esc_html_e( 'points', 'persiano-hub' ); ?></h2><p><?php echo wp_kses_post( sprintf( __( 'Your current balance is worth up to %s in rewards.', 'persiano-hub' ), wc_price( $redeemable ) ) ); ?></p></div>
                <div class="ph-loyalty-rule"><strong><?php esc_html_e( 'How it works', 'persiano-hub' ); ?></strong><p><?php echo wp_kses_post( sprintf( __( 'Earn %1$d point for each %2$s spent on eligible products. Every %3$d points gives you a %4$s reward. Minimum redemption is %3$d points.', 'persiano-hub' ), self::POINTS_PER_DOLLAR, wc_price( 1 ), self::POINTS_PER_REWARD, wc_price( self::REWARD_VALUE ) ) ); ?></p></div>
            </div>
            <div class="ph-account-cards ph-account-cards--two">
                <div class="ph-account-card"><span><?php esc_html_e( 'Available', 'persiano-hub' ); ?></span><strong><?php echo esc_html( number_format_i18n( $balance ) ); ?></strong><small><?php esc_html_e( 'points ready to use', 'persiano-hub' ); ?></small></div>
                <div class="ph-account-card"><span><?php esc_html_e( 'Lifetime earned', 'persiano-hub' ); ?></span><strong><?php echo esc_html( number_format_i18n( $lifetime ) ); ?></strong><small><?php esc_html_e( 'points earned from paid orders', 'persiano-hub' ); ?></small></div>
            </div>
            <section class="ph-account-section">
                <div class="ph-account-section-head"><div><span><?php esc_html_e( 'Account activity', 'persiano-hub' ); ?></span><h2><?php esc_html_e( 'Points history', 'persiano-hub' ); ?></h2></div></div>
                <?php if ( $log ) : ?>
                    <div class="ph-loyalty-log">
                        <?php foreach ( $log as $entry ) : ?>
                            <div><span><strong><?php echo esc_html( isset( $entry['label'] ) ? $entry['label'] : __( 'Loyalty activity', 'persiano-hub' ) ); ?></strong><small><?php echo esc_html( isset( $entry['date'] ) ? $entry['date'] : '' ); ?></small></span><b class="<?php echo ! empty( $entry['points'] ) && $entry['points'] > 0 ? 'is-positive' : 'is-negative'; ?>"><?php echo esc_html( ( ! empty( $entry['points'] ) && $entry['points'] > 0 ? '+' : '' ) . intval( $entry['points'] ) ); ?></b></div>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?><div class="ph-account-empty"><p><?php esc_html_e( 'Your points activity will appear here after your first eligible paid order.', 'persiano-hub' ); ?></p></div><?php endif; ?>
            </section>
        </div>
        <?php
    }

    private static function render_account_product_card( $product, $quantity = 0, $show_order_count = false ) {
        if ( ! $product instanceof WC_Product ) {
            return;
        }
        $product_id = $product->get_id();
        ?>
        <article class="ph-account-product-card">
            <a class="ph-account-product-image" href="<?php echo esc_url( get_permalink( $product_id ) ); ?>"><?php echo $product->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
            <div><h3><a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h3><?php if ( $show_order_count ) : ?><p><?php echo esc_html( sprintf( _n( 'Ordered %s time', 'Ordered %s times', $quantity, 'persiano-hub' ), number_format_i18n( $quantity ) ) ); ?></p><?php endif; ?><div class="ph-account-product-actions"><span><?php echo wp_kses_post( $product->get_price_html() ); ?></span><?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?><a class="button" href="<?php echo esc_url( self::quick_reorder_url( $product_id ) ); ?>"><?php esc_html_e( 'Quick reorder', 'persiano-hub' ); ?></a><?php endif; ?></div></div>
        </article>
        <?php
    }

    public static function get_favorites( $user_id ) {
        $ids = get_user_meta( absint( $user_id ), '_persiano_favorites', true );
        $ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
        return $ids;
    }

    public static function favorite_url( $product_id ) {
        $redirect = self::current_url();
        return wp_nonce_url( add_query_arg( array( 'persiano_favorite' => absint( $product_id ), 'redirect_to' => rawurlencode( $redirect ) ), home_url( '/' ) ), 'persiano_favorite_' . absint( $product_id ) );
    }

    public static function favorite_button( $product_id, $compact = false ) {
        $active = is_user_logged_in() && in_array( absint( $product_id ), self::get_favorites( get_current_user_id() ), true );
        $label = $active ? __( 'Remove from favourites', 'persiano-hub' ) : __( 'Save to favourites', 'persiano-hub' );
        echo '<a class="ph-favorite-button' . ( $active ? ' is-active' : '' ) . ( $compact ? ' is-compact' : '' ) . '" href="' . esc_url( self::favorite_url( $product_id ) ) . '" aria-label="' . esc_attr( $label ) . '"><span aria-hidden="true">' . ( $active ? '♥' : '♡' ) . '</span><em>' . esc_html( $label ) . '</em></a>';
    }

    public static function product_card_favorite( $product ) {
        if ( $product instanceof WC_Product ) {
            self::favorite_button( $product->get_id(), true );
        }
    }

    public static function loop_favorite() {
        global $product;
        if ( $product instanceof WC_Product ) {
            self::favorite_button( $product->get_id(), true );
        }
    }

    public static function single_favorite() {
        global $product;
        if ( $product instanceof WC_Product ) {
            self::favorite_button( $product->get_id(), false );
        }
    }

    public static function handle_favorite_toggle() {
        if ( empty( $_GET['persiano_favorite'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $product_id = absint( $_GET['persiano_favorite'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $nonce, 'persiano_favorite_' . $product_id ) ) {
            wp_die( esc_html__( 'This favourites link expired. Please try again.', 'persiano-hub' ), 403 );
        }
        $redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['redirect_to'] ) ) ) : get_permalink( $product_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! is_user_logged_in() ) {
            wc_add_notice( __( 'Sign in or create an account to save favourites.', 'persiano-hub' ), 'notice' );
            wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $redirect ), wc_get_page_permalink( 'myaccount' ) ) );
            exit;
        }
        $ids = self::get_favorites( get_current_user_id() );
        if ( in_array( $product_id, $ids, true ) ) {
            $ids = array_values( array_diff( $ids, array( $product_id ) ) );
            wc_add_notice( __( 'Removed from favourites.', 'persiano-hub' ), 'success' );
        } else {
            array_unshift( $ids, $product_id );
            $ids = array_slice( array_values( array_unique( $ids ) ), 0, 100 );
            wc_add_notice( __( 'Saved to your favourites.', 'persiano-hub' ), 'success' );
        }
        update_user_meta( get_current_user_id(), '_persiano_favorites', $ids );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function quick_reorder_url( $product_id ) {
        return wp_nonce_url( add_query_arg( 'persiano_reorder_product', absint( $product_id ), home_url( '/' ) ), 'persiano_reorder_' . absint( $product_id ) );
    }

    public static function handle_quick_reorder() {
        if ( empty( $_GET['persiano_reorder_product'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $product_id = absint( $_GET['persiano_reorder_product'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $nonce, 'persiano_reorder_' . $product_id ) ) {
            wp_die( esc_html__( 'This reorder link expired. Please try again.', 'persiano-hub' ), 403 );
        }
        $product = wc_get_product( $product_id );
        if ( $product && $product->is_purchasable() && $product->is_in_stock() && function_exists( 'WC' ) && WC()->cart ) {
            WC()->cart->add_to_cart( $product_id, 1 );
            wc_add_notice( sprintf( __( '%s was added to your cart.', 'persiano-hub' ), $product->get_name() ), 'success' );
        } else {
            wc_add_notice( __( 'That item is not currently available to reorder.', 'persiano-hub' ), 'error' );
        }
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    public static function get_most_ordered_products( $user_id, $limit = 4 ) {
        $statuses = wc_get_is_paid_statuses();
        $orders = wc_get_orders( array( 'customer_id' => absint( $user_id ), 'status' => $statuses, 'limit' => 60, 'return' => 'objects' ) );
        $counts = array();
        foreach ( $orders as $order ) {
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $product_id = $item->get_variation_id() ?: $item->get_product_id();
                if ( $product_id ) {
                    $counts[ $product_id ] = isset( $counts[ $product_id ] ) ? $counts[ $product_id ] + (float) $item->get_quantity() : (float) $item->get_quantity();
                }
            }
        }
        arsort( $counts, SORT_NUMERIC );
        $result = array();
        foreach ( array_slice( $counts, 0, absint( $limit ), true ) as $product_id => $quantity ) {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $result[] = array( 'product' => $product, 'quantity' => $quantity );
            }
        }
        return $result;
    }

    public static function get_points_balance( $user_id ) {
        return max( 0, intval( get_user_meta( absint( $user_id ), '_persiano_loyalty_balance', true ) ) );
    }

    private static function change_points( $user_id, $delta, $label, $order_id = 0 ) {
        $user_id = absint( $user_id );
        $delta = intval( $delta );
        if ( ! $user_id || ! $delta ) {
            return 0;
        }
        $before = self::get_points_balance( $user_id );
        $after = max( 0, $before + $delta );
        $actual = $after - $before;
        update_user_meta( $user_id, '_persiano_loyalty_balance', $after );
        if ( $actual > 0 ) {
            $lifetime = absint( get_user_meta( $user_id, '_persiano_loyalty_lifetime', true ) );
            update_user_meta( $user_id, '_persiano_loyalty_lifetime', $lifetime + $actual );
        }
        $log = get_user_meta( $user_id, '_persiano_loyalty_log', true );
        $log = is_array( $log ) ? $log : array();
        array_unshift( $log, array( 'date' => current_time( 'mysql' ), 'points' => $actual, 'label' => sanitize_text_field( $label ), 'order_id' => absint( $order_id ) ) );
        update_user_meta( $user_id, '_persiano_loyalty_log', array_slice( $log, 0, 100 ) );
        return $actual;
    }

    public static function award_order_points( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || $order->get_meta( '_persiano_loyalty_points_awarded', true ) ) {
            return;
        }
        self::maybe_attach_order_to_existing_account( $order );
        $user_id = $order->get_customer_id();
        if ( ! $user_id ) {
            return;
        }
        $eligible = 0.0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $eligible += (float) $item->get_total();
        }
        $points = max( 0, (int) floor( $eligible * self::POINTS_PER_DOLLAR ) );
        if ( $points ) {
            self::change_points( $user_id, $points, sprintf( __( 'Points from order #%d', 'persiano-hub' ), $order->get_order_number() ), $order_id );
            $order->update_meta_data( '_persiano_loyalty_points_awarded', $points );
            $order->save();
        }
    }

    public static function reverse_order_loyalty( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $user_id = $order->get_customer_id();
        $awarded = absint( $order->get_meta( '_persiano_loyalty_points_awarded', true ) );
        if ( $user_id && $awarded && ! $order->get_meta( '_persiano_loyalty_points_reversed', true ) ) {
            self::change_points( $user_id, -$awarded, sprintf( __( 'Points reversed for order #%d', 'persiano-hub' ), $order->get_order_number() ), $order_id );
            $order->update_meta_data( '_persiano_loyalty_points_reversed', $awarded );
        }
        $redeemed = absint( $order->get_meta( '_persiano_loyalty_points_redeemed', true ) );
        if ( $user_id && $redeemed && ! $order->get_meta( '_persiano_loyalty_points_restored', true ) ) {
            self::change_points( $user_id, $redeemed, sprintf( __( 'Reward restored from order #%d', 'persiano-hub' ), $order->get_order_number() ), $order_id );
            $order->update_meta_data( '_persiano_loyalty_points_restored', $redeemed );
        }
        $order->save();
    }

    public static function render_checkout_loyalty() {
        if ( ! is_user_logged_in() ) { return; }
        $balance = self::get_points_balance( get_current_user_id() );
        $blocks  = (int) floor( $balance / self::POINTS_PER_REWARD );
        if ( $blocks < 1 ) { return; }
        $selected = function_exists( 'WC' ) && WC()->session ? absint( WC()->session->get( 'persiano_loyalty_blocks', 0 ) ) : 0;
        echo '<section class="ph-checkout-loyalty"><h3>' . esc_html__( 'Persiano Rewards', 'persiano-hub' ) . '</h3>';
        echo '<p>' . wp_kses_post( sprintf( __( 'You have %1$d points, worth %2$s. Choose a fixed reward amount.', 'persiano-hub' ), $balance, wc_price( ( $balance / self::POINTS_PER_REWARD ) * self::REWARD_VALUE ) ) ) . '</p><div class="ph-reward-options">';
        echo '<label><input type="radio" name="persiano_loyalty_blocks" value="0" ' . checked( 0, $selected, false ) . '> <span>' . esc_html__( 'No reward', 'persiano-hub' ) . '</span></label>';
        for ( $i = 1; $i <= $blocks; $i++ ) {
            $value = $i * self::REWARD_VALUE;
            echo '<label><input type="radio" name="persiano_loyalty_blocks" value="' . esc_attr( $i ) . '" ' . checked( $i, $selected, false ) . '> <span>' . wp_kses_post( sprintf( __( 'Use %s', 'persiano-hub' ), wc_price( $value ) ) ) . '</span></label>';
        }
        echo '</div></section><script>document.addEventListener("change",function(e){if(e.target&&e.target.name==="persiano_loyalty_blocks"&&window.jQuery){jQuery(document.body).trigger("update_checkout");}});</script>';
    }

    public static function update_checkout_loyalty_session( $post_data ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) { return; }
        parse_str( $post_data, $data );
        WC()->session->set( 'persiano_loyalty_blocks', isset( $data['persiano_loyalty_blocks'] ) ? absint( $data['persiano_loyalty_blocks'] ) : 0 );
    }

    public static function apply_checkout_loyalty_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
        if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! WC()->session ) { return; }
        $requested = absint( WC()->session->get( 'persiano_loyalty_blocks', 0 ) );
        if ( $requested < 1 ) { return; }
        $balance_blocks = (int) floor( self::get_points_balance( get_current_user_id() ) / self::POINTS_PER_REWARD );
        $eligible_blocks = (int) floor( max( 0, (float) $cart->get_cart_contents_total() ) / self::REWARD_VALUE );
        $blocks = min( $requested, $balance_blocks, $eligible_blocks );
        if ( $blocks < 1 ) { return; }
        $value  = $blocks * self::REWARD_VALUE;
        $points = $blocks * self::POINTS_PER_REWARD;
        WC()->session->set( 'persiano_loyalty_points_checkout', $points );
        WC()->session->set( 'persiano_loyalty_value_checkout', $value );
        $cart->add_fee( __( 'Persiano loyalty reward', 'persiano-hub' ), -$value, false );
    }

    public static function store_checkout_loyalty_on_order( $order, $data ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }
        $points = absint( WC()->session->get( 'persiano_loyalty_points_checkout' ) );
        $value = (float) WC()->session->get( 'persiano_loyalty_value_checkout' );
        if ( $points && $value > 0 ) {
            $order->update_meta_data( '_persiano_loyalty_points_redeemed_pending', $points );
            $order->update_meta_data( '_persiano_loyalty_reward_value', $value );
        }
    }

    public static function deduct_checkout_loyalty( $order_id, $posted_data = array(), $order = null ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || $order->get_meta( '_persiano_loyalty_points_redeemed', true ) ) {
            return;
        }
        $points = absint( $order->get_meta( '_persiano_loyalty_points_redeemed_pending', true ) );
        $user_id = $order->get_customer_id();
        if ( $points && $user_id ) {
            $actual = self::change_points( $user_id, -$points, sprintf( __( 'Reward used on order #%d', 'persiano-hub' ), $order->get_order_number() ), $order_id );
            $order->update_meta_data( '_persiano_loyalty_points_redeemed', abs( $actual ) );
            $order->save();
        }
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( 'persiano_loyalty_blocks' );
            WC()->session->__unset( 'persiano_loyalty_points_checkout' );
            WC()->session->__unset( 'persiano_loyalty_value_checkout' );
        }
    }

    public static function render_order_pay_loyalty() {
        if ( ! is_user_logged_in() || ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) { return; }
        $order_id = absint( get_query_var( 'order-pay' ) );
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || ! $order->needs_payment() || $order->get_customer_id() !== get_current_user_id() ) { return; }
        $applied = absint( $order->get_meta( '_persiano_loyalty_points_redeemed', true ) );
        if ( $applied ) {
            echo '<div class="woocommerce-info ph-pay-reward">' . esc_html( sprintf( __( '%d loyalty points have been applied to this order.', 'persiano-hub' ), $applied ) ) . '</div>';
            return;
        }
        $balance = self::get_points_balance( get_current_user_id() );
        $max_blocks = min( (int) floor( $balance / self::POINTS_PER_REWARD ), (int) floor( self::eligible_order_subtotal( $order ) / self::REWARD_VALUE ) );
        if ( $max_blocks < 1 ) { return; }
        echo '<section class="ph-pay-reward"><h3>' . esc_html__( 'Persiano Rewards', 'persiano-hub' ) . '</h3><p>' . wp_kses_post( sprintf( __( 'You have %1$d points. Choose a reward to apply before payment.', 'persiano-hub' ), $balance ) ) . '</p><div class="ph-reward-options">';
        for ( $i = 1; $i <= $max_blocks; $i++ ) {
            $url = wp_nonce_url( add_query_arg( array( 'persiano_apply_order_reward' => $order_id, 'reward_blocks' => $i, 'key' => $order->get_order_key() ), $order->get_checkout_payment_url() ), 'persiano_apply_order_reward_' . $order_id );
            echo '<a class="button" href="' . esc_url( $url ) . '">' . wp_kses_post( sprintf( __( 'Use %s', 'persiano-hub' ), wc_price( $i * self::REWARD_VALUE ) ) ) . '</a>';
        }
        echo '</div></section>';
    }

    public static function handle_apply_order_reward() {
        if ( empty( $_GET['persiano_apply_order_reward'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $order_id = absint( $_GET['persiano_apply_order_reward'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $nonce, 'persiano_apply_order_reward_' . $order_id ) ) {
            wp_die( esc_html__( 'This reward link expired. Please try again.', 'persiano-hub' ), 403 );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || $order->get_customer_id() !== get_current_user_id() || ! $order->needs_payment() ) {
            wp_die( esc_html__( 'This reward cannot be applied to that order.', 'persiano-hub' ), 403 );
        }
        if ( $order->get_meta( '_persiano_loyalty_points_redeemed', true ) ) {
            wp_safe_redirect( $order->get_checkout_payment_url() );
            exit;
        }
        $balance = self::get_points_balance( get_current_user_id() );
        $subtotal = 0.0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $subtotal += (float) $item->get_total();
        }
        $requested_blocks = isset( $_GET['reward_blocks'] ) ? max( 1, absint( $_GET['reward_blocks'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $available_blocks = (int) floor( $balance / self::POINTS_PER_REWARD );
        $eligible_blocks = (int) floor( $subtotal / self::REWARD_VALUE );
        $blocks = min( $requested_blocks, $available_blocks, $eligible_blocks );
        $value = $blocks * self::REWARD_VALUE;
        if ( $value > 0 ) {
            $points = (int) round( ( $value / self::REWARD_VALUE ) * self::POINTS_PER_REWARD );
            $fee = new WC_Order_Item_Fee();
            $fee->set_name( __( 'Persiano loyalty reward', 'persiano-hub' ) );
            $fee->set_amount( -$value );
            $fee->set_total( -$value );
            $fee->set_tax_status( 'none' );
            $order->add_item( $fee );
            $actual = self::change_points( get_current_user_id(), -$points, sprintf( __( 'Reward used on order #%d', 'persiano-hub' ), $order->get_order_number() ), $order_id );
            $order->update_meta_data( '_persiano_loyalty_points_redeemed', abs( $actual ) );
            $order->update_meta_data( '_persiano_loyalty_reward_value', $value );
            $order->calculate_totals( false );
            $order->save();
            wc_add_notice( __( 'Your loyalty reward was applied.', 'persiano-hub' ), 'success' );
        }
        wp_safe_redirect( $order->get_checkout_payment_url() );
        exit;
    }

    private static function eligible_order_subtotal( $order ) {
        $subtotal = 0.0;
        if ( $order instanceof WC_Order ) {
            foreach ( $order->get_items( 'line_item' ) as $item ) { $subtotal += (float) $item->get_total(); }
        }
        return max( 0, $subtotal );
    }

    public static function maybe_attach_order_to_existing_account( $order ) {
        if ( ! $order instanceof WC_Order || $order->get_customer_id() ) {
            return;
        }
        $email = $order->get_billing_email();
        $user_id = $email ? email_exists( $email ) : 0;
        if ( $user_id ) {
            $order->set_customer_id( absint( $user_id ) );
            $order->save();
        }
    }

    public static function maybe_link_order_and_invite( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        self::maybe_attach_order_to_existing_account( $order );
        if ( ! $order->get_customer_id() && 'yes' === $order->get_meta( '_persiano_invite_account_after_payment', true ) && ! $order->get_meta( '_persiano_account_invite_sent', true ) && $order->get_billing_email() ) {
            $url = add_query_arg( array( 'persiano_claim_order' => $order->get_id(), 'key' => $order->get_order_key() ), wc_get_page_permalink( 'myaccount' ) );
            $subject = sprintf( __( 'Save your %1$s order #%2$s', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ), $order->get_order_number() );
            $heading = __( 'Save your order and rewards', 'persiano-hub' );
            $content = '<p style="margin:0 0 20px;">' . esc_html( sprintf( __( 'Your order is confirmed. Create a free %s account to save this order, earn loyalty points, keep favourites and reorder quickly.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ) . '</p>';
            $content .= self::email_action_button_html( $url, __( 'Create My Account', 'persiano-hub' ) );
            $content .= self::email_fallback_link_html( $url );
            $message = class_exists( 'Persiano_Hub_Email_Branding' )
                ? Persiano_Hub_Email_Branding::branded_message( $heading, $content, sprintf( __( 'Create your %s account and save this order.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) )
                : $content;
            wp_mail( $order->get_billing_email(), $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
            $order->update_meta_data( '_persiano_account_invite_sent', current_time( 'mysql' ) );
            $order->save();
        }
    }

    public static function link_guest_orders_to_customer( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->user_email ) {
            return;
        }
        $orders = wc_get_orders( array( 'billing_email' => $user->user_email, 'customer_id' => 0, 'limit' => 100, 'return' => 'objects' ) );
        foreach ( $orders as $order ) {
            $order->set_customer_id( $user_id );
            $order->save();
            if ( $order->is_paid() ) {
                self::award_order_points( $order->get_id() );
            }
        }
    }

    public static function guest_account_prompt( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || $order->get_customer_id() || ! $order->get_billing_email() || email_exists( $order->get_billing_email() ) ) {
            return;
        }
        $url = add_query_arg( array( 'persiano_claim_order' => $order_id, 'key' => $order->get_order_key() ), wc_get_page_permalink( 'myaccount' ) );
        echo '<section class="ph-claim-order"><h2>' . esc_html__( 'Save this order and earn rewards', 'persiano-hub' ) . '</h2><p>' . esc_html__( 'Create a free account to keep this order in your history, save favourites and earn loyalty points.', 'persiano-hub' ) . '</p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Create my account', 'persiano-hub' ) . '</a></section>';
    }

    public static function create_payment_access_url( $order ) {
        if ( ! $order instanceof WC_Order || ! $order->needs_payment() ) {
            return '';
        }
        if ( ! $order->get_customer_id() ) {
            return $order->get_checkout_payment_url();
        }
        $expires = time() + DAY_IN_SECONDS;
        $payload = implode( '|', array( $order->get_id(), $order->get_customer_id(), $expires, $order->get_order_key() ) );
        $token = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        return add_query_arg(
            array(
                'persiano_pay_access' => $order->get_id(),
                'expires'             => $expires,
                'token'               => $token,
            ),
            home_url( '/' )
        );
    }

    public static function handle_magic_payment_access() {
        if ( empty( $_GET['persiano_pay_access'] ) || empty( $_GET['token'] ) || empty( $_GET['expires'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $order = wc_get_order( absint( $_GET['persiano_pay_access'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $expires = absint( $_GET['expires'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $order instanceof WC_Order || ! $order->needs_payment() || ! $order->get_customer_id() || time() > $expires ) {
            wp_die( esc_html__( 'This payment link is no longer available.', 'persiano-hub' ), 410 );
        }
        $payload = implode( '|', array( $order->get_id(), $order->get_customer_id(), $expires, $order->get_order_key() ) );
        $expected = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        if ( ! hash_equals( $expected, $token ) ) {
            wp_die( esc_html( sprintf( __( 'This secure payment link is invalid. Please ask %s for a new link.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ), 403 );
        }
        wp_set_current_user( $order->get_customer_id() );
        wp_set_auth_cookie( $order->get_customer_id(), false, is_ssl() );
        wp_safe_redirect( $order->get_checkout_payment_url() );
        exit;
    }

    public static function email_payment_access_button( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin || ! $order instanceof WC_Order || ! $order->needs_payment() ) {
            return;
        }
        $email_id = is_object( $email ) && isset( $email->id ) ? $email->id : '';
        if ( ! in_array( $email_id, array( 'customer_invoice', 'customer_on_hold_order', 'customer_processing_order' ), true ) ) {
            return;
        }
        $url = self::create_payment_access_url( $order );
        if ( ! $url ) {
            return;
        }

        $label = __( 'Pay for Your Order', 'persiano-hub' );
        $expiry_message = $order->get_customer_id()
            ? __( 'This secure account-access link expires after 24 hours.', 'persiano-hub' )
            : __( 'This secure payment link is available while the order is awaiting payment.', 'persiano-hub' );

        if ( $plain_text ) {
            echo "\n" . wp_strip_all_tags( $label ) . ":\n" . esc_url_raw( $url ) . "\n" . wp_strip_all_tags( $expiry_message ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        echo self::email_action_button_html( $url, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo self::email_fallback_link_html( $url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<p style="margin:12px 0 0;font-size:13px;line-height:1.5;color:#6f655e;text-align:center;">' . esc_html( $expiry_message ) . '</p>';
    }

    /**
     * A table-based email button with explicit text colour for Gmail and Outlook.
     *
     * @param string $url   Destination URL.
     * @param string $label Visible button label.
     * @return string
     */
    private static function email_action_button_html( $url, $label ) {
        return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;margin:24px 0 18px;"><tr><td align="center"><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="#8e2435" style="background:#8e2435;border-radius:999px;"><a class="button" href="' . esc_url( $url ) . '" style="display:inline-block;background:#8e2435;border:1px solid #8e2435;border-radius:999px;color:#fffaf2!important;-webkit-text-fill-color:#fffaf2!important;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;line-height:20px;padding:14px 28px;text-align:center;text-decoration:none;"><span style="color:#fffaf2!important;-webkit-text-fill-color:#fffaf2!important;">' . esc_html( $label ) . '</span></a></td></tr></table></td></tr></table>';
    }

    /**
     * Display the destination as a copyable fallback beneath an email button.
     *
     * @param string $url Destination URL.
     * @return string
     */
    private static function email_fallback_link_html( $url ) {
        return '<div style="margin:0 0 12px;text-align:center;font-size:13px;line-height:1.55;color:#6f655e;"><p style="margin:0 0 6px;">' . esc_html__( 'Button not working? Copy and paste this secure link into your browser:', 'persiano-hub' ) . '</p><a href="' . esc_url( $url ) . '" style="color:#8e2435!important;text-decoration:underline;word-break:break-all;overflow-wrap:anywhere;">' . esc_html( $url ) . '</a></div>';
    }

    public static function current_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        return esc_url_raw( $scheme . $host . $uri );
    }

    public static function frontend_assets() {
        if ( ! function_exists( 'is_account_page' ) || ( ! is_account_page() && ! is_product() && ! is_shop() && ! is_product_taxonomy() && ! is_checkout() ) ) {
            return;
        }
        wp_register_style( 'persiano-hub-account', false, array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-hub-account' );
        wp_add_inline_style( 'persiano-hub-account', '.ph-consent-box{border:1px solid rgba(47,35,29,.14);border-radius:18px;padding:18px;margin:20px 0}.ph-consent-box legend{font-weight:800;padding:0 8px}.ph-consent-box label{display:flex;gap:10px;align-items:flex-start;margin:10px 0}.ph-consent-box input{margin-top:5px}.ph-account-intro{padding:18px;border-radius:18px;background:#f8f3e9;margin-bottom:22px}.ph-account-intro p{margin:5px 0 0}.ph-account-fieldset{border:0;padding:0;margin:26px 0}.ph-account-fieldset legend{font-size:1.2rem;font-weight:800;margin-bottom:12px}.ph-favorite-button{display:inline-flex;align-items:center;gap:7px;color:#8e2435;text-decoration:none;font-weight:750;margin:10px 0}.ph-favorite-button span{font-size:1.35rem}.ph-favorite-button em{font-style:normal}.ph-favorite-button.is-compact em{font-size:.78rem}.ph-account-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:24px 0}.ph-account-cards--two{grid-template-columns:repeat(2,minmax(0,1fr))}.ph-account-card{display:flex;flex-direction:column;padding:22px;border:1px solid rgba(47,35,29,.12);border-radius:20px;background:#fff;text-decoration:none}.ph-account-card>span{font-size:.72rem;font-weight:850;letter-spacing:.1em;text-transform:uppercase;color:#8e2435}.ph-account-card>strong{font-size:2rem;margin:8px 0;line-height:1}.ph-account-card>small{color:#7b6f64}.ph-account-card-address{font-size:1.15rem!important}.ph-account-section{margin:44px 0}.ph-account-section-head span,.ph-loyalty-hero>div>span{font-size:.72rem;font-weight:850;letter-spacing:.12em;text-transform:uppercase;color:#8e2435}.ph-account-section-head h2,.ph-loyalty-hero h2{margin:5px 0 20px}.ph-account-product-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.ph-account-product-card{display:grid;grid-template-columns:110px 1fr;gap:18px;padding:16px;border:1px solid rgba(47,35,29,.12);border-radius:20px;background:#fff}.ph-account-product-image img{width:110px;height:110px;object-fit:cover;border-radius:14px}.ph-account-product-card h3{font-size:1.25rem;margin:3px 0 7px}.ph-account-product-card h3 a{text-decoration:none}.ph-account-product-card p{font-size:.85rem;color:#7b6f64;margin:0}.ph-account-product-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px}.ph-account-product-actions .button{min-height:38px!important;padding:0 14px!important;font-size:.68rem!important}.ph-account-empty{padding:28px;border-radius:20px;background:#fff;border:1px dashed rgba(47,35,29,.2)}.ph-loyalty-hero{display:grid;grid-template-columns:1.2fr .8fr;gap:24px;padding:28px;border-radius:24px;background:#2f231d;color:#fff}.ph-loyalty-hero p{color:rgba(255,255,255,.75)}.ph-loyalty-rule{padding:20px;border-radius:18px;background:rgba(255,255,255,.08)}.ph-loyalty-log>div{display:flex;justify-content:space-between;gap:18px;padding:15px 0;border-bottom:1px solid rgba(47,35,29,.12)}.ph-loyalty-log span{display:flex;flex-direction:column}.ph-loyalty-log small{color:#7b6f64}.ph-loyalty-log b{font-size:1.1rem}.ph-loyalty-log .is-positive{color:#4f7048}.ph-loyalty-log .is-negative{color:#8e2435}.ph-checkout-loyalty,.ph-pay-reward,.ph-claim-order{padding:18px;border-radius:18px;background:#fff8e8;border:1px solid rgba(215,154,45,.3);margin:18px 0}.ph-claim-order{margin:30px 0}.ph-claim-order h2{font-size:1.8rem}.ph-pay-reward p{margin:6px 0 12px}.ph-pending-actions{margin:0 0 26px;padding:22px;border-radius:20px;background:#fff8e8;border:1px solid rgba(215,154,45,.35)}.ph-pending-actions h2{margin:4px 0 14px}.ph-pending-order{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:16px 0;border-top:1px solid rgba(47,35,29,.12)}.ph-pending-order:first-of-type{border-top:0}.ph-pending-order div:first-child{display:flex;flex-direction:column;gap:5px}.ph-pending-order small{color:#7b6f64}.ph-pending-order-actions{display:flex;align-items:center;gap:12px}.ph-pending-order-actions .button{min-height:44px!important;font-size:.78rem!important}.ph-marketing-managed-note{padding:12px 14px;border-radius:12px;background:#f8f3e9;font-size:15px}@media(max-width:900px){.ph-account-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.ph-account-cards,.ph-account-cards--two,.ph-account-product-grid,.ph-loyalty-hero{grid-template-columns:1fr}.ph-account-product-card{grid-template-columns:80px 1fr}.ph-account-product-image img{width:80px;height:80px}.ph-account-product-actions{align-items:flex-start;flex-direction:column}}' );
    }
}
