<?php
/**
 * Persiano-branded WooCommerce order emails and thank-you messaging.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Email_Branding {
    public static function init() {
        add_filter( 'woocommerce_email_styles', array( __CLASS__, 'email_styles' ), 30, 2 );
        add_filter( 'wp_mail_from_name', array( __CLASS__, 'mail_from_name' ), 999 );
        add_filter( 'wp_mail_from', array( __CLASS__, 'mail_from_address' ), 999 );
        add_filter( 'woocommerce_email_headers', array( __CLASS__, 'customer_email_bcc' ), 20, 4 );
        add_filter( 'woocommerce_email_header_image', array( __CLASS__, 'header_image' ), 999 );
        add_filter( 'woocommerce_email_footer_text', array( __CLASS__, 'footer_text' ) );
        add_filter( 'woocommerce_locate_template', array( __CLASS__, 'locate_email_template' ), 20, 3 );
        add_action( 'init', array( __CLASS__, 'sync_woocommerce_header_image' ), 25 );
        add_action( 'customize_save_after', array( __CLASS__, 'sync_woocommerce_header_image' ) );
        add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( __CLASS__, 'sync_woocommerce_header_image' ), 20 );

        add_filter( 'woocommerce_email_subject_customer_on_hold_order', array( __CLASS__, 'subject_on_hold' ), 20, 2 );
        add_filter( 'woocommerce_email_heading_customer_on_hold_order', array( __CLASS__, 'heading_on_hold' ), 20, 2 );
        add_filter( 'woocommerce_email_subject_customer_processing_order', array( __CLASS__, 'subject_processing' ), 20, 2 );
        add_filter( 'woocommerce_email_heading_customer_processing_order', array( __CLASS__, 'heading_processing' ), 20, 2 );
        add_filter( 'woocommerce_email_subject_customer_completed_order', array( __CLASS__, 'subject_completed' ), 20, 2 );
        add_filter( 'woocommerce_email_heading_customer_completed_order', array( __CLASS__, 'heading_completed' ), 20, 2 );

        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'render_customer_next_steps' ), 35, 4 );
        add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_thankyou_next_steps' ), 30 );
    }



    public static function customer_email_bcc( $headers, $email_id, $object, $email ) {
        if ( 0 !== strpos( (string) $email_id, 'customer_' ) ) { return $headers; }
        $settings = wp_parse_args( get_option( 'persiano_hub_frontend_pos_settings', array() ), array( 'bcc_customer_emails' => 'yes', 'bcc_email' => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_email() : get_option( 'admin_email' ) ) );
        $bcc = sanitize_email( $settings['bcc_email'] );
        if ( 'yes' !== $settings['bcc_customer_emails'] || ! is_email( $bcc ) ) { return $headers; }
        $recipient = is_object( $email ) && method_exists( $email, 'get_recipient' ) ? sanitize_email( $email->get_recipient() ) : '';
        if ( $recipient && strtolower( $recipient ) === strtolower( $bcc ) ) { return $headers; }
        if ( false === stripos( $headers, 'Bcc:' ) ) { $headers .= 'Bcc: ' . $bcc . "
"; }
        return $headers;
    }

    public static function mail_from_name( $name ) {
        return class_exists( 'Persiano_Hub_Business_Profile' )
            ? Persiano_Hub_Business_Profile::brand_name()
            : ( get_bloginfo( 'name' ) ?: $name );
    }

    public static function mail_from_address( $email ) {
        if ( class_exists( 'Persiano_Hub_Business_Profile' ) ) {
            $preferred = Persiano_Hub_Business_Profile::support_email();
            if ( $preferred ) { return $preferred; }
        }
        $preferred = sanitize_email( get_theme_mod( 'persiano_contact_email', get_option( 'admin_email' ) ) );
        return $preferred ? $preferred : $email;
    }

    /**
     * Use a Persiano-specific on-hold template so advance-order requests do not
     * inherit WooCommerce's generic "waiting for payment" wording.
     */
    public static function locate_email_template( $template, $template_name, $template_path ) {
        $overrides = array(
            'emails/customer-on-hold-order.php',
            'emails/plain/customer-on-hold-order.php',
        );

        if ( ! in_array( $template_name, $overrides, true ) ) {
            return $template;
        }

        $candidate = PERSIANO_HUB_PATH . 'templates/' . $template_name;
        return file_exists( $candidate ) ? $candidate : $template;
    }

    public static function email_styles( $css, $email ) {
        $primary    = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'primary_color', '#8e2435' ) : '#8e2435';
        $accent     = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'accent_color', '#d79a2d' ) : '#d79a2d';
        $dark       = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'dark_color', '#2f231d' ) : '#2f231d';
        $background = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'background_color', '#f8f3e9' ) : '#f8f3e9';
        $surface    = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'surface_color', '#fffaf2' ) : '#fffaf2';
        $css .= '
'
            . 'body{background:' . $background . '!important;color:' . $dark . '!important;}'
            . '#wrapper{background:' . $background . '!important;padding:28px 0!important;}'
            . '#template_container{border:0!important;border-radius:22px!important;overflow:hidden!important;box-shadow:0 18px 50px rgba(47,35,29,.08)!important;}'
            . '#template_header{background:' . $primary . '!important;border-radius:0!important;}'
            . '#template_header h1{color:' . $surface . '!important;font-family:Georgia,serif!important;font-weight:600!important;letter-spacing:-.02em!important;}'
            . '#template_body{background:' . $surface . '!important;}'
            . '#body_content_inner{color:#5f554c!important;}'
            . 'h1,h2,h3{font-family:Georgia,serif!important;color:' . $dark . '!important;}'
            . 'a{color:' . $primary . '!important;}'
            . '.button,a.button{background:' . $primary . '!important;border-color:' . $primary . '!important;color:' . $surface . '!important;-webkit-text-fill-color:' . $surface . '!important;border-radius:999px!important;}'
            . '.button span,a.button span{color:' . $surface . '!important;-webkit-text-fill-color:' . $surface . '!important;}'
            . '#template_header_image{text-align:center!important;padding:0 18px 18px!important;}'
            . '#template_header_image p{margin:0!important;}'
            . '#template_header_image img{display:block!important;width:auto!important;max-width:120px!important;max-height:64px!important;height:auto!important;margin:0 auto!important;}'
            . '#template_footer{background:' . $dark . '!important;}'
            . '#credit{color:rgba(255,255,255,.72)!important;}'
            . '#credit a{color:' . $accent . '!important;}'
            . 'table.td,th.td,td.td{border-color:#e5dfd5!important;}'
            . 'td#header_wrapper{padding:28px 36px!important;}'
            . 'td#body_content{padding:0!important;}'
            . 'td#body_content>table>tbody>tr>td{padding:34px 38px!important;}'
            . '@media only screen and (max-width:600px){td#body_content>table>tbody>tr>td{padding:24px 20px!important;}td#header_wrapper{padding:24px 20px!important;}}';
        return $css;
    }

    /**
     * Return the current customer-facing Persiano logo URL.
     *
     * The theme's dedicated header logo is preferred, followed by the normal
     * WordPress custom logo and then the site icon. Keeping this lookup dynamic
     * means future logo changes flow into new emails without another release.
     *
     * @return string
     */
    public static function get_logo_url() {
        if ( class_exists( 'Persiano_Hub_Business_Profile' ) ) {
            $profile_logo = Persiano_Hub_Business_Profile::logo_url();
            if ( $profile_logo ) { return $profile_logo; }
        }
        $logo_ids = array(
            absint( get_theme_mod( 'persiano_header_logo', 0 ) ),
            absint( get_theme_mod( 'custom_logo', 0 ) ),
        );

        foreach ( array_unique( array_filter( $logo_ids ) ) as $logo_id ) {
            $image = wp_get_attachment_image_url( $logo_id, 'full' );
            if ( ! $image ) {
                $image = wp_get_attachment_url( $logo_id );
            }
            if ( $image ) {
                return esc_url_raw( $image );
            }
        }

        $site_icon = get_site_icon_url( 512 );
        return $site_icon ? esc_url_raw( $site_icon ) : '';
    }

    public static function header_image( $url ) {
        $logo = self::get_logo_url();
        return $logo ? $logo : $url;
    }


    /**
     * Keep WooCommerce's stored email header image aligned with the current
     * Persiano logo. Standard WooCommerce templates read this option directly.
     */
    public static function sync_woocommerce_header_image() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $logo = self::get_logo_url();
        if ( ! $logo ) {
            return;
        }

        $current = (string) get_option( 'woocommerce_email_header_image', '' );
        if ( $current !== $logo ) {
            update_option( 'woocommerce_email_header_image', $logo, false );
        }
    }

    /**
     * Wrap a custom transactional message in the Persiano email design.
     *
     * WooCommerce templates already receive the logo through header_image().
     * This helper is for the small number of direct wp_mail() messages created
     * by Batchly, such as the post-payment account invitation.
     *
     * @param string $heading    Email heading.
     * @param string $content    Trusted, escaped HTML content.
     * @param string $preheader  Optional inbox preview text.
     * @return string
     */
    public static function branded_message( $heading, $content, $preheader = '' ) {
        $logo          = self::get_logo_url();
        $site_name     = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $contact_email = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_email() : sanitize_email( get_option( 'admin_email' ) );
        $tagline       = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::get( 'tagline', '' ) : get_bloginfo( 'description' );
        $primary       = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'primary_color', '#8e2435' ) : '#8e2435';
        $accent        = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'accent_color', '#d79a2d' ) : '#d79a2d';
        $dark          = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'dark_color', '#2f231d' ) : '#2f231d';
        $background    = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'background_color', '#f8f3e9' ) : '#f8f3e9';
        $surface       = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'surface_color', '#fffaf2' ) : '#fffaf2';
        if ( ! $site_name ) { $site_name = 'Business'; }

        ob_start();
        ?>
        <!doctype html>
        <html lang="en">
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
        <body style="margin:0;padding:0;background:<?php echo esc_attr( $background ); ?>;color:<?php echo esc_attr( $dark ); ?>;font-family:Arial,Helvetica,sans-serif;">
            <?php if ( $preheader ) : ?><div style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;mso-hide:all;"><?php echo esc_html( $preheader ); ?></div><?php endif; ?>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:<?php echo esc_attr( $background ); ?>;">
                <tr><td align="center" style="padding:28px 14px;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:620px;background:<?php echo esc_attr( $surface ); ?>;border-radius:22px;overflow:hidden;box-shadow:0 18px 50px rgba(47,35,29,.08);">
                        <?php if ( $logo ) : ?>
                            <tr><td align="center" style="padding:28px 28px 18px;"><img src="<?php echo esc_url( $logo ); ?>" width="120" alt="<?php echo esc_attr( $site_name ); ?>" style="display:block;width:auto;max-width:120px;max-height:64px;height:auto;border:0;outline:none;text-decoration:none;"></td></tr>
                        <?php else : ?>
                            <tr><td align="center" style="padding:28px 28px 18px;font-family:Georgia,serif;font-size:22px;font-weight:700;color:<?php echo esc_attr( $primary ); ?>;"><?php echo esc_html( $site_name ); ?></td></tr>
                        <?php endif; ?>
                        <tr><td style="padding:24px 34px;background:<?php echo esc_attr( $primary ); ?>;color:<?php echo esc_attr( $surface ); ?>;"><h1 style="margin:0;color:<?php echo esc_attr( $surface ); ?>;font-family:Georgia,serif;font-size:30px;line-height:1.2;font-weight:600;"><?php echo esc_html( $heading ); ?></h1></td></tr>
                        <tr><td style="padding:32px 36px;color:#5f554c;font-size:16px;line-height:1.65;"><?php echo wp_kses_post( $content ); ?></td></tr>
                        <tr><td align="center" style="padding:24px 28px;background:<?php echo esc_attr( $dark ); ?>;color:rgba(255,255,255,.76);font-size:13px;line-height:1.5;"><?php echo esc_html( $site_name ); ?><?php if ( $tagline ) : ?> · <?php echo esc_html( $tagline ); ?><?php endif; ?><br><?php esc_html_e( 'Questions?', 'persiano-hub' ); ?> <a href="mailto:<?php echo esc_attr( $contact_email ); ?>" style="color:<?php echo esc_attr( $accent ); ?>!important;text-decoration:none;"><?php echo esc_html( $contact_email ); ?></a></td></tr>
                    </table>
                </td></tr>
            </table>
        </body>
        </html>
        <?php
        return trim( ob_get_clean() );
    }

    public static function footer_text( $text ) {
        $brand   = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' );
        $tagline = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::get( 'tagline', '' ) : get_bloginfo( 'description' );
        $email   = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_email() : sanitize_email( get_option( 'admin_email' ) );
        $line    = trim( $brand . ( $tagline ? ' · ' . $tagline : '' ) );
        return sprintf(
            '%1$s<br>%2$s <a href="mailto:%3$s">%3$s</a>',
            esc_html( $line ),
            esc_html__( 'Questions about your order?', 'persiano-hub' ),
            esc_attr( $email )
        );
    }

    private static function brand_name_for_email() {
        return class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' );
    }

    public static function subject_on_hold( $subject, $order ) {
        if ( self::is_unconfirmed_advance_order( $order ) ) {
            return self::subject_with_number( sprintf( __( 'Your %s advance order request #%%s was received', 'persiano-hub' ), self::brand_name_for_email() ), $order );
        }
        return self::subject_with_number( sprintf( __( 'We received your %s order #%%s', 'persiano-hub' ), self::brand_name_for_email() ), $order );
    }

    public static function heading_on_hold( $heading, $order ) {
        if ( self::is_unconfirmed_advance_order( $order ) ) {
            return __( 'Your advance order is pending confirmation', 'persiano-hub' );
        }
        return __( 'Your order is in', 'persiano-hub' );
    }

    public static function subject_processing( $subject, $order ) {
        return self::subject_with_number( sprintf( __( 'Your %s order #%%s is confirmed', 'persiano-hub' ), self::brand_name_for_email() ), $order );
    }

    public static function heading_processing( $heading, $order ) {
        return __( 'Your order is confirmed', 'persiano-hub' );
    }

    public static function subject_completed( $subject, $order ) {
        return self::subject_with_number( sprintf( __( 'Your %s order #%%s is complete', 'persiano-hub' ), self::brand_name_for_email() ), $order );
    }

    public static function heading_completed( $heading, $order ) {
        return __( 'Thank you — your order is complete', 'persiano-hub' );
    }

    public static function render_customer_next_steps( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin || ! $order instanceof WC_Order ) {
            return;
        }

        $title = __( 'What happens next', 'persiano-hub' );
        $lines = self::next_steps_for_order( $order );
        if ( empty( $lines ) ) {
            return;
        }

        if ( $plain_text ) {
            echo "\n" . $title . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            foreach ( $lines as $line ) {
                echo '• ' . wp_strip_all_tags( $line ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            return;
        }

        echo '<section style="margin:26px 0 18px;padding:22px 24px;border-radius:16px;background:#f8f3e9;border-left:4px solid #8e2435;">';
        echo '<h2 style="margin:0 0 10px;color:#2f231d;font-family:Georgia,serif;">' . esc_html( $title ) . '</h2><ul style="margin:0;padding-left:20px;color:#5f554c;">';
        foreach ( $lines as $line ) {
            echo '<li style="margin:6px 0;">' . esc_html( $line ) . '</li>';
        }
        echo '</ul></section>';
    }

    public static function render_thankyou_next_steps( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $lines = self::next_steps_for_order( $order );
        if ( empty( $lines ) ) {
            return;
        }
        $eyebrow = self::is_unconfirmed_advance_order( $order )
            ? __( 'Request received', 'persiano-hub' )
            : __( 'Order received', 'persiano-hub' );
        echo '<section class="ph-thankyou-next"><span class="ph-thankyou-next-eyebrow">' . esc_html( $eyebrow ) . '</span><h2>' . esc_html__( 'What happens next', 'persiano-hub' ) . '</h2><ul>';
        foreach ( $lines as $line ) {
            echo '<li>' . esc_html( $line ) . '</li>';
        }
        echo '</ul></section>';
    }

    private static function next_steps_for_order( $order ) {
        $lines = array();

        if ( self::is_unconfirmed_advance_order( $order ) ) {
            $lines[] = sprintf( __( 'Your advance-order request is pending confirmation from %s.', 'persiano-hub' ), self::brand_name_for_email() );
            $lines[] = __( 'We will review the requested date and availability before confirming the order.', 'persiano-hub' );
            $lines[] = __( 'No online payment is required until the advance order is confirmed.', 'persiano-hub' );
        } else {
            $payment_method = $order->get_payment_method();
            if ( $order->has_status( 'on-hold' ) || 'invoice' === $payment_method || false !== stripos( $order->get_payment_method_title(), 'invoice' ) ) {
                $lines[] = __( 'Your order has been received and is reserved while payment or cash arrangements are completed.', 'persiano-hub' );
            } elseif ( $order->is_paid() ) {
                $lines[] = __( 'Your payment has been received.', 'persiano-hub' );
            }
        }

        $method_title = '';
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            $method_title = $shipping_item->get_name();
            break;
        }
        if ( $method_title ) {
            $lines[] = sprintf( __( 'Your selected fulfilment method is: %s.', 'persiano-hub' ), $method_title );
        }

        if ( self::is_unconfirmed_advance_order( $order ) ) {
            $lines[] = __( 'Once approved, you will receive an order confirmation with payment instructions or a secure payment link.', 'persiano-hub' );
        } elseif ( self::order_has_advance_items( $order ) ) {
            $lines[] = __( 'Your requested advance-order date is recorded in the order details below.', 'persiano-hub' );
        }

        $lines[] = __( 'You will receive another email when the order status changes.', 'persiano-hub' );
        return $lines;
    }

    public static function is_unconfirmed_advance_order( $order ) {
        return $order instanceof WC_Order
            && 'yes' === $order->get_meta( '_persiano_advance_order_request', true )
            && 'yes' !== $order->get_meta( '_persiano_advance_order_confirmed', true );
    }

    private static function order_has_advance_items( $order ) {
        foreach ( $order->get_items() as $item ) {
            if ( 'yes' === $item->get_meta( '_persiano_advance_order', true ) ) {
                return true;
            }
        }
        return false;
    }

    private static function subject_with_number( $template, $order ) {
        $number = $order instanceof WC_Order ? $order->get_order_number() : '';
        return sprintf( $template, $number );
    }
}
