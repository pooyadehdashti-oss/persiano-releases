<?php
/**
 * Refinements confirmed during live workflow testing.
 *
 * @package Persiano_Hub
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Tested_Refinements {
    public static function init() {
        add_action( 'woocommerce_pay_order_before_payment', array( __CLASS__, 'render_pay_order_context' ), 8 );
        add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'payment_button_text' ), 30 );
        add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );
        add_action( 'admin_footer', array( __CLASS__, 'admin_js' ), 99 );
        add_filter( 'woocommerce_email_headers', array( __CLASS__, 'reply_to_header' ), 30, 4 );
    }

    public static function reply_to_header( $headers, $email_id, $object, $email ) {
        $address = function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : sanitize_email( get_option( 'admin_email' ) );
        $brand   = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' );
        if ( false === stripos( (string) $headers, 'Reply-To:' ) && is_email( $address ) ) {
            $headers .= 'Reply-To: ' . sanitize_text_field( $brand ) . ' <' . $address . ">\r\n";
        }
        return $headers;
    }

    public static function render_pay_order_context( $order ) {
        if ( ! $order instanceof WC_Order ) { return; }
        $note = trim( (string) $order->get_customer_note() );
        $date = '';
        foreach ( array( '_persiano_requested_date', '_persiano_requested_fulfilment', '_persiano_fulfilment_datetime', '_persiano_requested_datetime' ) as $key ) {
            $value = trim( (string) $order->get_meta( $key, true ) );
            if ( $value ) { $date = $value; break; }
        }
        if ( ! $note && ! $date ) { return; }
        echo '<section class="ph-payment-context">';
        echo '<h3>' . esc_html__( 'Order details to confirm', 'persiano-hub' ) . '</h3>';
        if ( $date ) {
            $timestamp = strtotime( $date );
            $display = $timestamp ? wp_date( 'l, F j, Y \a\t g:i A', $timestamp ) : $date;
            echo '<p><strong>' . esc_html__( 'Requested fulfilment:', 'persiano-hub' ) . '</strong> ' . esc_html( $display ) . '</p>';
        }
        if ( $note ) {
            echo '<p><strong>' . esc_html__( 'Your order note:', 'persiano-hub' ) . '</strong><br>' . nl2br( esc_html( $note ) ) . '</p>';
        }
        echo '</section>';
    }

    public static function payment_button_text( $text ) {
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
            return __( 'Complete payment', 'persiano-hub' );
        }
        return $text;
    }

    public static function admin_css() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) { return; }
        echo '<style>
        .ph-payment-context{background:#fffaf2;border:1px solid #eadfce;border-radius:16px;padding:18px 20px;margin:0 0 24px}.ph-payment-context h3{margin-top:0}
        body.post-type-shop_order #woocommerce-order-downloads,body.woocommerce_page_wc-orders #woocommerce-order-downloads,.woocommerce-order-data__meta .downloadable_product_permissions{display:none!important}
        .ph-manual-email-choice{border:2px solid #8e2435!important;background:#fff7f2!important;border-radius:12px!important;padding:14px 16px!important;margin:18px 0!important;display:block!important}
        .ph-manual-email-choice strong{display:block;color:#8e2435;margin-bottom:4px}
        .ph-service-recovery-help{background:#f6f7f7;border-left:4px solid #8e2435;padding:10px 12px;margin:0 0 12px}
        .ph-instagram-format-cards{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px;margin:8px 0 14px}.ph-instagram-format-card{border:1px solid #dcdcde;border-radius:12px;padding:12px;background:#fff;cursor:pointer}.ph-instagram-format-card.is-selected{border:2px solid #3858e9;background:#f4f6ff}.ph-instagram-format-card strong{display:block;margin-bottom:4px}.ph-carousel-preview{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}.ph-carousel-item{position:relative;width:92px;height:92px;border:1px solid #dcdcde;border-radius:10px;overflow:hidden;background:#f6f7f7}.ph-carousel-item img,.ph-carousel-item video{width:100%;height:100%;object-fit:cover}.ph-carousel-item button{position:absolute;right:4px;top:4px;border:0;border-radius:999px;background:#fff;color:#b32d2e;width:24px;height:24px;cursor:pointer}.ph-suggested-media{margin:12px 0;padding:12px;border:1px dashed #c3c4c7;border-radius:12px}.ph-suggested-media-grid{display:flex;flex-wrap:wrap;gap:8px}.ph-suggested-media button{padding:0;border:2px solid transparent;border-radius:10px;overflow:hidden;background:none;cursor:pointer}.ph-suggested-media img{width:78px;height:78px;object-fit:cover;display:block}.ph-suggested-media button:hover{border-color:#3858e9}
        @media(max-width:900px){.ph-instagram-format-cards{grid-template-columns:repeat(2,1fr)}}
        </style>';
    }

    public static function admin_js() {
        ?>
        <script>
        (function($){
          $(function(){
            var emailBox=$('input[name="persiano_email_customer"],input[name="email_customer"],input[name="send_email"]');
            emailBox.each(function(){var label=$(this).closest('label');if(label.length&&!label.hasClass('ph-manual-email-choice')){label.addClass('ph-manual-email-choice');label.prepend('<strong>Optional customer email</strong>');label.append('<small style="display:block;margin-top:5px">Leave unchecked to create the order silently. You can send the payment link later from the order screen.</small>');}});
            var loss=$('.ph-loss-box,.ph-loss-adjustment-form').first();
            if(loss.length&&!loss.find('.ph-service-recovery-help').length){loss.prepend('<div class="ph-service-recovery-help"><strong>Service recovery only</strong><br>Use this when part of an order is waived, replaced, discarded, or credited. The explanation below is saved inside this adjustment record.</div>');loss.find('strong').filter(function(){return $(this).text().trim()==='Internal note';}).text('Internal explanation for this adjustment');}
          });
        })(jQuery);
        </script>
        <?php
    }
}
