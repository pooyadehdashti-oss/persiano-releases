<?php
/**
 * White-label business profile and trial-site configuration.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Business_Profile {
    const OPTION_KEY = 'persiano_hub_business_profile';
    const PAGE       = 'persiano-hub-business-profile';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 58 );
        add_action( 'admin_post_persiano_hub_save_business_profile', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_persiano_hub_export_business_profile', array( __CLASS__, 'handle_export' ) );
        add_action( 'admin_post_persiano_hub_import_business_profile', array( __CLASS__, 'handle_import' ) );
    }

    private static function defaults() {
        $site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
        $site_name = $site_name ? $site_name : 'Persiano Dish';
        $email     = sanitize_email( (string) get_option( 'admin_email' ) );

        return array(
            'hub_name'         => 'Batchly',
            'business_type'    => 'food_service',
            'brand_name'       => $site_name,
            'legal_name'       => $site_name,
            'tagline'          => (string) get_bloginfo( 'description' ),
            'support_email'    => $email,
            'support_phone'    => '',
            'website_url'      => home_url( '/' ),
            'address'          => '',
            'service_area'     => '',
            'logo_url'         => '',
            'primary_color'    => '#8e2435',
            'accent_color'     => '#d79a2d',
            'dark_color'       => '#2f231d',
            'background_color' => '#f8f3e9',
            'surface_color'    => '#fffaf2',
            'order_label'      => 'Order',
            'orders_label'     => 'Orders',
            'customer_label'   => 'Customer',
            'product_label'    => 'Product',
            'products_label'   => 'Products',
            'enabled_sections' => array(
                'persiano-hub-operations',
                'persiano-hub-products-dashboard',
                'persiano-hub-recipes-costing',
                'persiano-hub-customers-sales',
                'persiano-hub-publishing-content',
                'persiano-hub-reports',
                'persiano-hub-system-tools',
            ),
        );
    }

    public static function get_profile() {
        $saved = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
    }

    public static function get( $key, $fallback = '' ) {
        $profile = self::get_profile();
        return isset( $profile[ $key ] ) && '' !== $profile[ $key ] ? $profile[ $key ] : $fallback;
    }

    public static function hub_name() {
        return sanitize_text_field( self::get( 'hub_name', 'Batchly' ) );
    }

    public static function brand_name() {
        return sanitize_text_field( self::get( 'brand_name', get_bloginfo( 'name' ) ) );
    }

    public static function support_email() {
        $email = sanitize_email( self::get( 'support_email', get_option( 'admin_email' ) ) );
        return $email ? $email : sanitize_email( get_option( 'admin_email' ) );
    }

    public static function legal_name() {
        return sanitize_text_field( self::get( 'legal_name', self::brand_name() ) );
    }

    public static function tagline() {
        return sanitize_text_field( self::get( 'tagline', '' ) );
    }

    public static function website_url() {
        $url = esc_url_raw( self::get( 'website_url', home_url( '/' ) ) );
        return $url ? $url : home_url( '/' );
    }

    public static function support_phone() {
        return sanitize_text_field( self::get( 'support_phone', '' ) );
    }

    public static function address() {
        return sanitize_textarea_field( self::get( 'address', '' ) );
    }

    public static function service_area() {
        return sanitize_text_field( self::get( 'service_area', '' ) );
    }

    public static function label( $key, $fallback ) {
        return sanitize_text_field( self::get( $key, $fallback ) );
    }


    public static function enabled_sections() {
        $profile = self::get_profile();
        $allowed = array(
            'persiano-hub-operations',
            'persiano-hub-products-dashboard',
            'persiano-hub-recipes-costing',
            'persiano-hub-customers-sales',
            'persiano-hub-publishing-content',
            'persiano-hub-reports',
            'persiano-hub-system-tools',
        );
        $saved = isset( $profile['enabled_sections'] ) && is_array( $profile['enabled_sections'] ) ? $profile['enabled_sections'] : $allowed;
        $saved = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $saved ) ) );

        // System & Tools always stays available so branding, integrations and
        // updates cannot be hidden accidentally on a remote trial site.
        if ( ! in_array( 'persiano-hub-system-tools', $saved, true ) ) {
            $saved[] = 'persiano-hub-system-tools';
        }
        return $saved;
    }

    public static function is_section_enabled( $slug ) {
        return in_array( sanitize_key( $slug ), self::enabled_sections(), true );
    }

    /**
     * Keep duplicate operational settings aligned with the canonical profile.
     * Credentials and business-specific tax identifiers are deliberately left
     * untouched.
     */
    private static function sync_dependent_settings( $profile ) {
        $message_settings = get_option( 'persiano_hub_messages_settings', array() );
        $message_settings = is_array( $message_settings ) ? $message_settings : array();
        $message_settings['email_from_name']    = $profile['brand_name'];
        $message_settings['email_from_address'] = $profile['support_email'];
        update_option( 'persiano_hub_messages_settings', $message_settings, false );

        $pos_settings = get_option( 'persiano_hub_frontend_pos_settings', array() );
        $pos_settings = is_array( $pos_settings ) ? $pos_settings : array();
        $pos_settings['business_name']       = $profile['brand_name'];
        $pos_settings['business_legal_name'] = $profile['legal_name'];
        $pos_settings['business_address']    = $profile['address'];
        $pos_settings['business_phone']      = $profile['support_phone'];
        $pos_settings['business_email']      = $profile['support_email'];
        $pos_settings['accent']              = $profile['primary_color'];
        if ( empty( $pos_settings['bcc_email'] ) ) {
            $pos_settings['bcc_email'] = $profile['support_email'];
        }
        update_option( 'persiano_hub_frontend_pos_settings', $pos_settings, false );
    }

    public static function logo_url() {
        $logo = esc_url_raw( self::get( 'logo_url', '' ) );
        return $logo ? $logo : '';
    }

    public static function color( $key, $fallback ) {
        $color = sanitize_hex_color( self::get( $key, $fallback ) );
        return $color ? $color : $fallback;
    }

    public static function register_page() {
        add_submenu_page(
            'persiano-hub',
            __( 'Business Profile', 'persiano-hub' ),
            __( 'Business Profile', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
    }

    private static function posted_text( $key, $default = '' ) {
        return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
    }

    private static function sanitize_profile( $input ) {
        $defaults = self::defaults();
        $input    = is_array( $input ) ? $input : array();

        $profile = array(
            'hub_name'         => sanitize_text_field( isset( $input['hub_name'] ) ? $input['hub_name'] : $defaults['hub_name'] ),
            'business_type'    => sanitize_key( isset( $input['business_type'] ) ? $input['business_type'] : $defaults['business_type'] ),
            'brand_name'       => sanitize_text_field( isset( $input['brand_name'] ) ? $input['brand_name'] : $defaults['brand_name'] ),
            'legal_name'       => sanitize_text_field( isset( $input['legal_name'] ) ? $input['legal_name'] : $defaults['legal_name'] ),
            'tagline'          => sanitize_text_field( isset( $input['tagline'] ) ? $input['tagline'] : $defaults['tagline'] ),
            'support_email'    => sanitize_email( isset( $input['support_email'] ) ? $input['support_email'] : $defaults['support_email'] ),
            'support_phone'    => sanitize_text_field( isset( $input['support_phone'] ) ? $input['support_phone'] : '' ),
            'website_url'      => esc_url_raw( isset( $input['website_url'] ) ? $input['website_url'] : $defaults['website_url'] ),
            'address'          => sanitize_textarea_field( isset( $input['address'] ) ? $input['address'] : '' ),
            'service_area'     => sanitize_text_field( isset( $input['service_area'] ) ? $input['service_area'] : '' ),
            'logo_url'         => esc_url_raw( isset( $input['logo_url'] ) ? $input['logo_url'] : '' ),
            'primary_color'    => sanitize_hex_color( isset( $input['primary_color'] ) ? $input['primary_color'] : $defaults['primary_color'] ),
            'accent_color'     => sanitize_hex_color( isset( $input['accent_color'] ) ? $input['accent_color'] : $defaults['accent_color'] ),
            'dark_color'       => sanitize_hex_color( isset( $input['dark_color'] ) ? $input['dark_color'] : $defaults['dark_color'] ),
            'background_color' => sanitize_hex_color( isset( $input['background_color'] ) ? $input['background_color'] : $defaults['background_color'] ),
            'surface_color'    => sanitize_hex_color( isset( $input['surface_color'] ) ? $input['surface_color'] : $defaults['surface_color'] ),
            'order_label'      => sanitize_text_field( isset( $input['order_label'] ) ? $input['order_label'] : $defaults['order_label'] ),
            'orders_label'     => sanitize_text_field( isset( $input['orders_label'] ) ? $input['orders_label'] : $defaults['orders_label'] ),
            'customer_label'   => sanitize_text_field( isset( $input['customer_label'] ) ? $input['customer_label'] : $defaults['customer_label'] ),
            'product_label'    => sanitize_text_field( isset( $input['product_label'] ) ? $input['product_label'] : $defaults['product_label'] ),
            'products_label'   => sanitize_text_field( isset( $input['products_label'] ) ? $input['products_label'] : $defaults['products_label'] ),
            'enabled_sections' => isset( $input['enabled_sections'] ) ? array_values( array_map( 'sanitize_key', (array) $input['enabled_sections'] ) ) : $defaults['enabled_sections'],
        );

        foreach ( array( 'primary_color', 'accent_color', 'dark_color', 'background_color', 'surface_color' ) as $color_key ) {
            if ( ! $profile[ $color_key ] ) {
                $profile[ $color_key ] = $defaults[ $color_key ];
            }
        }

        if ( ! $profile['brand_name'] ) {
            $profile['brand_name'] = $defaults['brand_name'];
        }
        if ( ! $profile['hub_name'] ) {
            $profile['hub_name'] = $defaults['hub_name'];
        }
        if ( ! $profile['order_label'] ) {
            $profile['order_label'] = $defaults['order_label'];
        }
        if ( ! $profile['orders_label'] ) {
            $profile['orders_label'] = $defaults['orders_label'];
        }
        if ( ! $profile['customer_label'] ) {
            $profile['customer_label'] = $defaults['customer_label'];
        }
        if ( ! $profile['product_label'] ) {
            $profile['product_label'] = $defaults['product_label'];
        }
        if ( ! $profile['products_label'] ) {
            $profile['products_label'] = $defaults['products_label'];
        }
        if ( ! in_array( $profile['business_type'], array( 'food_service', 'catering', 'bakery', 'retail', 'service', 'general' ), true ) ) {
            $profile['business_type'] = $defaults['business_type'];
        }
        $profile['enabled_sections'] = array_values( array_unique( array_intersect( $defaults['enabled_sections'], $profile['enabled_sections'] ) ) );
        if ( ! in_array( 'persiano-hub-system-tools', $profile['enabled_sections'], true ) ) {
            $profile['enabled_sections'][] = 'persiano-hub-system-tools';
        }

        return $profile;
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to change the business profile.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_hub_save_business_profile' );

        $profile = self::sanitize_profile( wp_unslash( $_POST ) );
        update_option( self::OPTION_KEY, $profile, false );
        self::sync_dependent_settings( $profile );

        if ( class_exists( 'Persiano_Hub_Email_Branding' ) ) {
            Persiano_Hub_Email_Branding::sync_woocommerce_header_image();
        }

        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_export() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to export this profile.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_hub_export_business_profile' );

        $payload = array(
            'format'      => 'persiano-hub-business-profile',
            'version'     => 1,
            'exported_at' => gmdate( 'c' ),
            'profile'     => self::get_profile(),
        );

        nocache_headers();
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename=business-profile-' . gmdate( 'Y-m-d' ) . '.json' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public static function handle_import() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to import a business profile.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_hub_import_business_profile' );

        if ( empty( $_FILES['business_profile_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['business_profile_file']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'profile_error' => rawurlencode( __( 'Choose a business profile JSON file.', 'persiano-hub' ) ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $contents = file_get_contents( $_FILES['business_profile_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $decoded  = json_decode( (string) $contents, true );
        $profile  = is_array( $decoded ) && isset( $decoded['profile'] ) ? $decoded['profile'] : $decoded;

        if ( ! is_array( $profile ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'profile_error' => rawurlencode( __( 'The selected file is not a valid business profile.', 'persiano-hub' ) ) ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $profile = self::sanitize_profile( $profile );
        update_option( self::OPTION_KEY, $profile, false );
        self::sync_dependent_settings( $profile );
        if ( class_exists( 'Persiano_Hub_Email_Branding' ) ) {
            Persiano_Hub_Email_Branding::sync_woocommerce_header_image();
        }
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'imported' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $p = self::get_profile();
        ?>
        <div class="wrap ph-business-profile">
            <style>
                .ph-business-profile{max-width:1120px}.ph-profile-hero{padding:26px 30px;margin:20px 0;background:<?php echo esc_attr( self::color( 'surface_color', '#fffaf2' ) ); ?>;border:1px solid #dfd5c9;border-radius:18px}.ph-profile-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(300px,.6fr);gap:18px}.ph-profile-card{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:24px}.ph-profile-card h2{margin-top:0}.ph-profile-preview{border-radius:16px;overflow:hidden;background:<?php echo esc_attr( self::color( 'background_color', '#f8f3e9' ) ); ?>;border:1px solid #ddd}.ph-preview-head{padding:22px;background:<?php echo esc_attr( self::color( 'primary_color', '#8e2435' ) ); ?>;color:#fff}.ph-preview-body{padding:22px;background:<?php echo esc_attr( self::color( 'surface_color', '#fffaf2' ) ); ?>}.ph-preview-foot{padding:16px 22px;background:<?php echo esc_attr( self::color( 'dark_color', '#2f231d' ) ); ?>;color:#fff}.ph-colors{display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:12px}.ph-colors label{display:grid;gap:6px}.ph-profile-actions{display:flex;gap:10px;flex-wrap:wrap}@media(max-width:900px){.ph-profile-grid{grid-template-columns:1fr}.ph-colors{grid-template-columns:1fr}}
            </style>
            <div class="ph-profile-hero">
                <h1><?php esc_html_e( 'Business Profile & White-Label Settings', 'persiano-hub' ); ?></h1>
                <p><?php esc_html_e( 'Configure customer-facing names, contact details, colours, logo and terminology without editing the plugin. These settings make one release suitable for multiple trial businesses.', 'persiano-hub' ); ?></p>
            </div>
            <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Business profile saved.', 'persiano-hub' ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['imported'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Business profile imported.', 'persiano-hub' ); ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['profile_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['profile_error'] ) ) ) ); ?></p></div><?php endif; ?>
            <div class="ph-profile-grid">
                <div class="ph-profile-card">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="persiano_hub_save_business_profile">
                        <?php wp_nonce_field( 'persiano_hub_save_business_profile' ); ?>
                        <h2><?php esc_html_e( 'Identity', 'persiano-hub' ); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr><th><label for="ph_hub_name"><?php esc_html_e( 'Hub name', 'persiano-hub' ); ?></label></th><td><input class="regular-text" id="ph_hub_name" name="hub_name" value="<?php echo esc_attr( $p['hub_name'] ); ?>"><p class="description"><?php esc_html_e( 'Internal dashboard name. The installed plugin remains Batchly for update compatibility.', 'persiano-hub' ); ?></p></td></tr>
                            <tr><th><label for="ph_business_type"><?php esc_html_e( 'Business type', 'persiano-hub' ); ?></label></th><td><select id="ph_business_type" name="business_type"><option value="food_service" <?php selected( $p['business_type'], 'food_service' ); ?>><?php esc_html_e( 'Prepared food / meal business', 'persiano-hub' ); ?></option><option value="catering" <?php selected( $p['business_type'], 'catering' ); ?>><?php esc_html_e( 'Catering', 'persiano-hub' ); ?></option><option value="bakery" <?php selected( $p['business_type'], 'bakery' ); ?>><?php esc_html_e( 'Bakery', 'persiano-hub' ); ?></option><option value="retail" <?php selected( $p['business_type'], 'retail' ); ?>><?php esc_html_e( 'Retail / maker', 'persiano-hub' ); ?></option><option value="service" <?php selected( $p['business_type'], 'service' ); ?>><?php esc_html_e( 'Service business', 'persiano-hub' ); ?></option><option value="general" <?php selected( $p['business_type'], 'general' ); ?>><?php esc_html_e( 'General', 'persiano-hub' ); ?></option></select><p class="description"><?php esc_html_e( 'Used as a deployment profile; WooCommerce remains the order and product engine.', 'persiano-hub' ); ?></p></td></tr>
                            <tr><th><label for="ph_brand_name"><?php esc_html_e( 'Customer-facing brand', 'persiano-hub' ); ?></label></th><td><input class="regular-text" id="ph_brand_name" name="brand_name" value="<?php echo esc_attr( $p['brand_name'] ); ?>"></td></tr>
                            <tr><th><label for="ph_legal_name"><?php esc_html_e( 'Legal business name', 'persiano-hub' ); ?></label></th><td><input class="regular-text" id="ph_legal_name" name="legal_name" value="<?php echo esc_attr( $p['legal_name'] ); ?>"></td></tr>
                            <tr><th><label for="ph_tagline"><?php esc_html_e( 'Email/footer tagline', 'persiano-hub' ); ?></label></th><td><input class="large-text" id="ph_tagline" name="tagline" value="<?php echo esc_attr( $p['tagline'] ); ?>"></td></tr>
                            <tr><th><label for="ph_logo_url"><?php esc_html_e( 'Logo URL', 'persiano-hub' ); ?></label></th><td><input class="large-text" type="url" id="ph_logo_url" name="logo_url" value="<?php echo esc_attr( $p['logo_url'] ); ?>"><p class="description"><?php esc_html_e( 'Paste a Media Library image URL. When blank, the WordPress custom logo or site icon is used.', 'persiano-hub' ); ?></p></td></tr>
                        </table>
                        <h2><?php esc_html_e( 'Contact and service area', 'persiano-hub' ); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr><th><?php esc_html_e( 'Support email', 'persiano-hub' ); ?></th><td><input class="regular-text" type="email" name="support_email" value="<?php echo esc_attr( $p['support_email'] ); ?>"></td></tr>
                            <tr><th><?php esc_html_e( 'Support phone', 'persiano-hub' ); ?></th><td><input class="regular-text" name="support_phone" value="<?php echo esc_attr( $p['support_phone'] ); ?>"></td></tr>
                            <tr><th><?php esc_html_e( 'Website', 'persiano-hub' ); ?></th><td><input class="large-text" type="url" name="website_url" value="<?php echo esc_attr( $p['website_url'] ); ?>"></td></tr>
                            <tr><th><?php esc_html_e( 'Address', 'persiano-hub' ); ?></th><td><textarea class="large-text" rows="3" name="address"><?php echo esc_textarea( $p['address'] ); ?></textarea></td></tr>
                            <tr><th><?php esc_html_e( 'Service area', 'persiano-hub' ); ?></th><td><input class="large-text" name="service_area" value="<?php echo esc_attr( $p['service_area'] ); ?>"></td></tr>
                        </table>
                        <h2><?php esc_html_e( 'Terminology', 'persiano-hub' ); ?></h2>
                        <table class="form-table" role="presentation">
                            <tr><th><?php esc_html_e( 'Order label', 'persiano-hub' ); ?></th><td><input name="order_label" value="<?php echo esc_attr( $p['order_label'] ); ?>"> <input name="orders_label" value="<?php echo esc_attr( $p['orders_label'] ); ?>"><p class="description"><?php esc_html_e( 'Examples: Order / Orders, Booking / Bookings, Job / Jobs.', 'persiano-hub' ); ?></p></td></tr>
                            <tr><th><?php esc_html_e( 'Customer label', 'persiano-hub' ); ?></th><td><input name="customer_label" value="<?php echo esc_attr( $p['customer_label'] ); ?>"></td></tr>
                            <tr><th><?php esc_html_e( 'Product label', 'persiano-hub' ); ?></th><td><input name="product_label" value="<?php echo esc_attr( $p['product_label'] ); ?>"> <input name="products_label" value="<?php echo esc_attr( $p['products_label'] ); ?>"></td></tr>
                        </table>
                        <h2><?php esc_html_e( 'Dashboard areas', 'persiano-hub' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Hide areas a trial business does not need. System & Tools always remains available so the profile and updater cannot be locked out.', 'persiano-hub' ); ?></p>
                        <?php $section_choices = array( 'persiano-hub-operations' => __( 'Operations', 'persiano-hub' ), 'persiano-hub-products-dashboard' => __( 'Products', 'persiano-hub' ), 'persiano-hub-recipes-costing' => __( 'Recipes & Costing', 'persiano-hub' ), 'persiano-hub-customers-sales' => __( 'Customers & Sales', 'persiano-hub' ), 'persiano-hub-publishing-content' => __( 'Publishing & Content', 'persiano-hub' ), 'persiano-hub-reports' => __( 'Reports', 'persiano-hub' ), 'persiano-hub-system-tools' => __( 'System & Tools', 'persiano-hub' ) ); ?>
                        <fieldset style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:8px;margin:12px 0 22px">
                            <?php foreach ( $section_choices as $section_slug => $section_label ) : ?><label><input type="checkbox" name="enabled_sections[]" value="<?php echo esc_attr( $section_slug ); ?>" <?php checked( in_array( $section_slug, (array) $p['enabled_sections'], true ) ); ?> <?php disabled( 'persiano-hub-system-tools' === $section_slug ); ?>> <?php echo esc_html( $section_label ); ?></label><?php endforeach; ?>
                            <input type="hidden" name="enabled_sections[]" value="persiano-hub-system-tools">
                        </fieldset>
                        <h2><?php esc_html_e( 'Brand colours', 'persiano-hub' ); ?></h2>
                        <div class="ph-colors">
                            <?php foreach ( array( 'primary_color' => 'Primary', 'accent_color' => 'Accent', 'dark_color' => 'Dark footer', 'background_color' => 'Page background', 'surface_color' => 'Email surface' ) as $key => $label ) : ?>
                                <label><?php echo esc_html( $label ); ?><input type="color" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $p[ $key ] ); ?>"></label>
                            <?php endforeach; ?>
                        </div>
                        <?php submit_button( __( 'Save Business Profile', 'persiano-hub' ) ); ?>
                    </form>
                </div>
                <div>
                    <div class="ph-profile-preview">
                        <div class="ph-preview-head"><strong><?php echo esc_html( $p['brand_name'] ); ?></strong><br><small><?php echo esc_html( $p['tagline'] ); ?></small></div>
                        <div class="ph-preview-body"><h2><?php echo esc_html( $p['order_label'] ); ?> update</h2><p><?php esc_html_e( 'Customer emails, correspondence timelines and selected dashboard labels use this profile.', 'persiano-hub' ); ?></p><p><a href="#" style="color:<?php echo esc_attr( $p['primary_color'] ); ?>"><?php esc_html_e( 'Example action link', 'persiano-hub' ); ?></a></p></div>
                        <div class="ph-preview-foot"><?php echo esc_html( $p['support_email'] ); ?></div>
                    </div>
                    <div class="ph-profile-card" style="margin-top:18px">
                        <h2><?php esc_html_e( 'Move a profile to another trial site', 'persiano-hub' ); ?></h2>
                        <p><?php esc_html_e( 'Export only the white-label profile. API secrets, customer data and orders are not included.', 'persiano-hub' ); ?></p>
                        <div class="ph-profile-actions">
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_export_business_profile' ), 'persiano_hub_export_business_profile' ) ); ?>"><?php esc_html_e( 'Export profile JSON', 'persiano-hub' ); ?></a>
                        </div>
                        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
                            <input type="hidden" name="action" value="persiano_hub_import_business_profile">
                            <?php wp_nonce_field( 'persiano_hub_import_business_profile' ); ?>
                            <input type="file" name="business_profile_file" accept="application/json,.json" required>
                            <?php submit_button( __( 'Import profile', 'persiano-hub' ), 'secondary', 'submit', false ); ?>
                        </form>
                    </div>
                    <div class="ph-profile-card" style="margin-top:18px">
                        <h2><?php esc_html_e( 'Central updates', 'persiano-hub' ); ?></h2>
                        <p><?php esc_html_e( 'Each trial site can receive new plugin releases from the same GitHub repository while keeping its own profile and credentials.', 'persiano-hub' ); ?></p>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=persiano-hub-updates' ) ); ?>"><?php esc_html_e( 'Configure GitHub updates', 'persiano-hub' ); ?></a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
