<?php
/**
 * Unified customer checkout and payment experience.
 *
 * @package Persiano_Hub
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Unified_Checkout {
    public static function init() {
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ), 30 );
        add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'address_preferences' ), 20 );
        add_action( 'woocommerce_checkout_update_user_meta', array( __CLASS__, 'save_address_preferences' ), 20, 2 );
        add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 30, 2 );
        add_action( 'woocommerce_order_action_persiano_send_etransfer', array( __CLASS__, 'send_etransfer' ) );
        add_action( 'admin_init', array( __CLASS__, 'mark_notifications_read_early' ), 1 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ), 50 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ), 50 );
    }

    public static function checkout_fields( $fields ) {
        $priorities = array(
            'billing_first_name' => 10,
            'billing_last_name'  => 20,
            'billing_email'      => 30,
            'billing_phone'      => 40,
            'billing_address_1'  => 50,
            'billing_address_2'  => 60,
            'billing_city'       => 70,
            'billing_state'      => 80,
            'billing_postcode'   => 90,
            'billing_country'    => 100,
        );
        foreach ( $priorities as $key => $priority ) {
            if ( isset( $fields['billing'][ $key ] ) ) {
                $fields['billing'][ $key ]['priority'] = $priority;
                $fields['billing'][ $key ]['class'] = in_array( $key, array( 'billing_address_1', 'billing_address_2' ), true ) ? array( 'form-row-wide' ) : array( 'form-row-first', 'ph-half-field' );
            }
        }
        foreach ( array( 'billing_last_name', 'billing_phone', 'billing_state', 'billing_country' ) as $key ) {
            if ( isset( $fields['billing'][ $key ] ) ) {
                $fields['billing'][ $key ]['class'] = array( 'form-row-last', 'ph-half-field' );
            }
        }
        if ( isset( $fields['order']['order_comments'] ) ) {
            $fields['order']['order_comments']['priority'] = 10;
            $fields['order']['order_comments']['class'] = array( 'form-row-wide', 'ph-order-notes' );
        }
        return $fields;
    }

    public static function address_preferences( $checkout ) {
        if ( ! is_user_logged_in() ) { return; }
        echo '<div class="ph-address-preferences">';
        echo '<label><input type="checkbox" name="persiano_save_address" value="1"> <span>' . esc_html__( 'Save this address to my account', 'persiano-hub' ) . '</span></label>';
        echo '<label><input type="checkbox" name="persiano_make_default_address" value="1"> <span>' . esc_html__( 'Make this my default address for future orders', 'persiano-hub' ) . '</span></label>';
        echo '</div>';
    }

    public static function save_address_preferences( $customer_id, $data ) {
        if ( ! $customer_id || empty( $_POST['persiano_save_address'] ) ) { return; }
        $customer = new WC_Customer( $customer_id );
        foreach ( array( 'first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone' ) as $field ) {
            $key = 'billing_' . $field;
            if ( isset( $_POST[ $key ] ) ) {
                $setter = 'set_billing_' . $field;
                if ( is_callable( array( $customer, $setter ) ) ) {
                    $customer->{$setter}( wc_clean( wp_unslash( $_POST[ $key ] ) ) );
                }
            }
        }
        if ( ! empty( $_POST['persiano_make_default_address'] ) ) {
            foreach ( array( 'first_name','last_name','company','address_1','address_2','city','state','postcode','country' ) as $field ) {
                $billing_getter = 'get_billing_' . $field;
                $shipping_setter = 'set_shipping_' . $field;
                if ( is_callable( array( $customer, $billing_getter ) ) && is_callable( array( $customer, $shipping_setter ) ) ) {
                    $customer->{$shipping_setter}( $customer->{$billing_getter}() );
                }
            }
        }
        $customer->save();
    }

    public static function order_actions( $actions, $order ) {
        if ( $order instanceof WC_Order && $order->needs_payment() ) {
            $actions['persiano_send_etransfer'] = __( 'Send e-transfer instructions', 'persiano-hub' );
        }
        return $actions;
    }

    public static function send_etransfer( $order ) {
        if ( ! $order instanceof WC_Order || ! $order->get_billing_email() ) { return; }
        $transfer_email = sanitize_email( get_option( 'persiano_etransfer_email', get_option( 'admin_email' ) ) );
        $brand = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' );
        $subject = sprintf( __( 'E-transfer instructions for %1$s order #%2$s', 'persiano-hub' ), $brand, $order->get_order_number() );
        $body  = '<p>' . esc_html( sprintf( __( 'You can pay your %s order by Interac e-Transfer using the details below.', 'persiano-hub' ), $brand ) ) . '</p>';
        $body .= '<p><strong>' . esc_html__( 'Amount due:', 'persiano-hub' ) . '</strong> ' . wp_kses_post( $order->get_formatted_order_total() ) . '<br>';
        $body .= '<strong>' . esc_html__( 'Send to:', 'persiano-hub' ) . '</strong> ' . esc_html( $transfer_email ) . '<br>';
        $body .= '<strong>' . esc_html__( 'Reference:', 'persiano-hub' ) . '</strong> Order #' . esc_html( $order->get_order_number() ) . '</p>';
        $body .= '<p>' . esc_html( sprintf( __( 'Your order remains unpaid until %s confirms receipt of the transfer.', 'persiano-hub' ), $brand ) ) . '</p>';
        if ( class_exists( 'Persiano_Hub_Email_Branding' ) ) {
            $body = Persiano_Hub_Email_Branding::branded_message( $subject, $body, $subject );
        }
        $sent = wp_mail( $order->get_billing_email(), $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
        $order->add_order_note( $sent ? __( 'E-transfer instructions emailed to the customer.', 'persiano-hub' ) : __( 'E-transfer instruction email failed.', 'persiano-hub' ) );
    }

    public static function mark_notifications_read_early() {
        if ( ! current_user_can( 'manage_woocommerce' ) || empty( $_GET['page'] ) || 'persiano-hub-notifications' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { return; }
        $log = get_option( 'persiano_hub_notification_log', array() );
        $latest = is_array( $log ) && ! empty( $log[0]['id'] ) ? sanitize_text_field( $log[0]['id'] ) : '';
        update_user_meta( get_current_user_id(), 'persiano_hub_notification_last_read', $latest );
    }

    public static function frontend_assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) { return; }
        wp_register_style( 'persiano-unified-checkout', false, array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-unified-checkout' );
        wp_add_inline_style( 'persiano-unified-checkout', self::css() );
    }

    private static function css() {
        return '
body.woocommerce-checkout .pd-commerce-shell,
body.woocommerce-order-pay .pd-commerce-shell{max-width:1320px!important;width:min(1320px,calc(100% - 40px))!important}
body.woocommerce-checkout .pd-checkout-content,
body.woocommerce-order-pay .pd-checkout-content{width:100%!important;max-width:none!important}
body.woocommerce-checkout .woocommerce,
body.woocommerce-order-pay .woocommerce{max-width:none!important;width:100%!important;margin:0!important}
body.woocommerce-checkout form.checkout{display:grid!important;grid-template-columns:minmax(0,1.75fr) minmax(380px,.85fr)!important;column-gap:32px!important;row-gap:18px!important;align-items:start!important;width:100%!important}
body.woocommerce-checkout form.checkout>#customer_details{grid-column:1!important;grid-row:1 / span 3!important;min-width:0!important}
body.woocommerce-checkout form.checkout>#order_review_heading{grid-column:2!important;grid-row:1!important;margin:0 0 -6px!important;padding:0 4px!important;font-size:1.5rem!important}
body.woocommerce-checkout form.checkout>#order_review{grid-column:2!important;grid-row:2!important;position:sticky!important;top:96px!important;min-width:0!important;width:100%!important;background:#fffaf3!important;border:1px solid rgba(47,35,29,.14)!important;border-radius:22px!important;padding:22px!important;overflow:visible!important}
body.woocommerce-checkout .col2-set{display:block!important;width:100%!important}
body.woocommerce-checkout .col2-set .col-1,
body.woocommerce-checkout .col2-set .col-2{float:none!important;width:100%!important;max-width:none!important;margin:0!important}
body.woocommerce-checkout .woocommerce-billing-fields,
body.woocommerce-checkout .woocommerce-shipping-fields,
body.woocommerce-checkout .woocommerce-additional-fields{background:#fff!important;border:1px solid rgba(47,35,29,.12)!important;border-radius:20px!important;padding:22px!important;margin:0 0 16px!important;box-shadow:none!important}
body.woocommerce-checkout .woocommerce-billing-fields__field-wrapper,
body.woocommerce-checkout .woocommerce-shipping-fields__field-wrapper{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:14px 16px!important}
body.woocommerce-checkout .woocommerce-billing-fields__field-wrapper .form-row,
body.woocommerce-checkout .woocommerce-shipping-fields__field-wrapper .form-row{float:none!important;width:100%!important;max-width:none!important;margin:0!important;padding:0!important;clear:none!important}
body.woocommerce-checkout .woocommerce-billing-fields__field-wrapper .form-row-wide,
body.woocommerce-checkout .woocommerce-shipping-fields__field-wrapper .form-row-wide{grid-column:1 / -1!important}
body.woocommerce-checkout h3{font-size:1.35rem!important;line-height:1.2!important;margin:0 0 16px!important}
body.woocommerce-checkout .form-row label{font-size:15px!important;font-weight:750!important;margin-bottom:6px!important}
body.woocommerce-checkout input.input-text,
body.woocommerce-checkout select,
body.woocommerce-checkout textarea{min-height:48px!important;font-size:16px!important;border-radius:10px!important;padding:10px 12px!important}
body.woocommerce-checkout textarea{min-height:76px!important;resize:vertical!important}
body.woocommerce-checkout .woocommerce-shipping-fields h3{display:flex!important;align-items:center!important;justify-content:space-between!important;padding:2px 0!important}
body.woocommerce-checkout .shipping_address{margin-top:14px!important}
.ph-address-preferences{display:grid!important;gap:10px!important;padding:14px 16px!important;background:#f8f3e9!important;border-radius:14px!important;margin:4px 0 16px!important}
.ph-address-preferences label{display:flex!important;gap:10px!important;align-items:center!important;font-size:15px!important}
.ph-address-preferences input,
body.woocommerce-checkout input[type=radio],
body.woocommerce-checkout input[type=checkbox]{width:21px!important;height:21px!important;min-width:21px!important}
body.woocommerce-checkout .woocommerce-checkout-review-order-table{table-layout:auto!important;width:100%!important;margin:0 0 14px!important}
body.woocommerce-checkout .woocommerce-checkout-review-order-table th,
body.woocommerce-checkout .woocommerce-checkout-review-order-table td{font-size:14px!important;line-height:1.4!important;padding:11px 6px!important;vertical-align:top!important;overflow-wrap:anywhere!important}
body.woocommerce-checkout .woocommerce-checkout-review-order-table .product-total{text-align:right!important;width:34%!important;white-space:normal!important}
body.woocommerce-checkout #order_review .ph-checkout-loyalty,
body.woocommerce-checkout #order_review .ph-tip-box{margin:14px 0!important;padding:16px!important;background:#fff!important;border-radius:16px!important}
body.woocommerce-checkout #order_review .ph-checkout-loyalty h3,
body.woocommerce-checkout #order_review .ph-tip-box h3{font-size:1.35rem!important;margin:0 0 5px!important}
body.woocommerce-checkout #order_review .ph-checkout-loyalty p,
body.woocommerce-checkout #order_review .ph-tip-box p{font-size:14px!important;line-height:1.45!important;margin:0 0 10px!important}
body.woocommerce-checkout .ph-reward-options,
body.woocommerce-checkout .ph-tip-options{display:flex!important;flex-wrap:wrap!important;gap:8px!important;align-items:center!important}
body.woocommerce-checkout .ph-reward-options label,
body.woocommerce-checkout .ph-tip-choice{min-height:40px!important;padding:8px 13px!important;font-size:14px!important}
.ph-tip-custom-wrap{display:inline-flex!important;align-items:center!important;gap:6px!important;border:1px solid #c7b9aa!important;border-radius:999px!important;padding:0 12px!important;background:#fff!important;min-height:42px!important}
.ph-tip-custom-wrap b{font-size:17px!important}
.ph-tip-custom-wrap input{border:0!important;box-shadow:none!important;width:78px!important;min-height:38px!important;padding:4px!important}
body.woocommerce-checkout #payment{background:#fff!important;border:1px solid rgba(47,35,29,.11)!important;border-radius:16px!important;margin-top:14px!important;padding:4px 14px 14px!important}
body.woocommerce-checkout #payment ul.payment_methods{padding:0!important;margin:0!important}
body.woocommerce-checkout #payment ul.payment_methods li{padding:12px 0!important}
body.woocommerce-checkout #payment ul.payment_methods li>label{font-size:16px!important;font-weight:750!important}
body.woocommerce-checkout #payment div.payment_box{margin:10px 0 0!important;padding:14px!important}
body.woocommerce-checkout #place_order{width:100%!important;min-height:54px!important;font-size:16px!important;margin-top:12px!important}
body.woocommerce-checkout .wc-square-wallet-buttons,
body.woocommerce-checkout .wc-square-digital-wallet,
body.woocommerce-checkout [class*=apple-pay],
body.woocommerce-checkout [class*=payment-request]{max-width:520px!important;margin:10px auto 16px!important}
body.woocommerce-order-pay form#order_review{display:grid!important;grid-template-columns:minmax(0,1.25fr) minmax(380px,.75fr)!important;gap:28px!important;max-width:1180px!important;align-items:start!important}
body.woocommerce-order-pay form#order_review>.shop_table{grid-column:1!important;background:#fff!important;border-radius:18px!important;overflow:hidden!important}
body.woocommerce-order-pay form#order_review>#payment{grid-column:2!important;position:sticky!important;top:96px!important;background:#fffaf3!important;border:1px solid rgba(47,35,29,.14)!important;border-radius:20px!important;padding:18px!important}
body.woocommerce-order-pay .ph-pay-reward,
body.woocommerce-order-pay .ph-tip-box{background:#fff!important;border-radius:16px!important;padding:16px!important;margin:14px 0!important}
@media(max-width:1020px){
 body.woocommerce-checkout form.checkout{grid-template-columns:minmax(0,1.45fr) minmax(340px,.8fr)!important;gap:22px!important}
}
@media(max-width:900px){
 body.woocommerce-checkout .pd-commerce-shell,body.woocommerce-order-pay .pd-commerce-shell{width:min(100% - 24px,760px)!important}
 body.woocommerce-checkout form.checkout,body.woocommerce-order-pay form#order_review{display:block!important}
 body.woocommerce-checkout form.checkout>#order_review_heading{margin-top:20px!important}
 body.woocommerce-checkout form.checkout>#order_review,body.woocommerce-order-pay form#order_review>#payment{position:static!important;margin-top:12px!important}
}
@media(max-width:620px){
 body.woocommerce-checkout .woocommerce-billing-fields__field-wrapper,
 body.woocommerce-checkout .woocommerce-shipping-fields__field-wrapper{grid-template-columns:1fr!important}
 body.woocommerce-checkout .woocommerce-billing-fields__field-wrapper .form-row-wide,
 body.woocommerce-checkout .woocommerce-shipping-fields__field-wrapper .form-row-wide{grid-column:auto!important}
 body.woocommerce-checkout .woocommerce-billing-fields,
 body.woocommerce-checkout .woocommerce-shipping-fields,
 body.woocommerce-checkout .woocommerce-additional-fields{padding:16px!important}
 body.woocommerce-checkout form.checkout>#order_review{padding:16px!important}
}
@media print{
 .site-header,.site-footer,.pd-commerce-hero,.pd-global-search-bar,#wpadminbar,.ph-toolbar{display:none!important}
 body.woocommerce-checkout .pd-commerce-section{padding:0!important}
 body.woocommerce-checkout .pd-commerce-shell{width:100%!important;max-width:none!important;margin:0!important}
 body.woocommerce-checkout form.checkout{display:block!important}
 body.woocommerce-checkout form.checkout>#order_review{position:static!important;page-break-inside:avoid!important;margin-top:14px!important}
 body.woocommerce-checkout .woocommerce-billing-fields,
 body.woocommerce-checkout .woocommerce-shipping-fields,
 body.woocommerce-checkout .woocommerce-additional-fields{page-break-inside:avoid!important}
}
';
    }

    public static function admin_assets( $hook ) {
        if ( 'persiano-hub_page_persiano-manual-order' !== $hook ) { return; }
        wp_register_style( 'persiano-manual-polish', false, array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-manual-polish' );
        wp_add_inline_style( 'persiano-manual-polish', '.ph-manual-tip-presets{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.ph-manual-tip-presets button.is-active{background:#8e2435;color:#fff;border-color:#8e2435}.ph-money-input{display:flex;align-items:center;gap:8px}.ph-money-input b{font-size:17px}' );
        wp_enqueue_script( 'jquery' );
        $script = <<<'JS'
jQuery(function($){
    var box=$('input[name="manual_tip"]');
    if(!box.length){return;}
    box.attr('step','1');
    box.wrap('<span class="ph-money-input"></span>').before('<b>$</b>');
    $('<input>',{type:'hidden',name:'manual_tip_percent',value:'0'}).insertAfter(box);
    box.closest('.ph-manual-order-card').find('h2').after('<div class="ph-manual-tip-presets"><button type="button" class="button is-active" data-tip="0">No tip</button><button type="button" class="button" data-tip-pct="10">10%</button><button type="button" class="button" data-tip-pct="15">15%</button><button type="button" class="button" data-tip-pct="20">20%</button><button type="button" class="button" data-tip-custom="1">Custom amount</button></div>');
    $(document).on('click','.ph-manual-tip-presets button',function(){
        var b=$(this);
        b.siblings().removeClass('is-active');
        b.addClass('is-active');
        $('input[name="manual_tip_percent"]').val(b.data('tip-pct')||0);
        if(b.data('tip')!==undefined){box.val(b.data('tip'));}
        if(b.data('tip-custom')){box.focus();}
    });
    box.on('focus change',function(){
        $('.ph-manual-tip-presets button').removeClass('is-active').filter('[data-tip-custom]').addClass('is-active');
        $('input[name="manual_tip_percent"]').val(0);
    });
});
JS;
        wp_add_inline_script( 'jquery', $script );
    }
}
