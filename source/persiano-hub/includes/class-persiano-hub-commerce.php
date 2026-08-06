<?php
/**
 * Commerce helpers for Batchly.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Commerce {
    public static function init() {
        add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cleanup_legacy_cart_item_data' ), 5, 2 );
        add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 20, 2 );
        add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'cleanup_order_item_meta' ), 20, 2 );
        add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart_deadlines' ) );
        add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'classic_order_button_text' ) );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'hide_internal_invoice_gateway' ), 100 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item_details' ), 20, 4 );
        add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_tip_selector' ), 5 );
        add_action( 'woocommerce_pay_order_before_payment', array( __CLASS__, 'render_tip_selector' ), 5 );
        add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_checkout_tip_fee' ), 30 );
        add_action( 'wp_ajax_persiano_set_tip', array( __CLASS__, 'ajax_set_tip' ) );
        add_action( 'wp_ajax_nopriv_persiano_set_tip', array( __CLASS__, 'ajax_set_tip' ) );
        add_action( 'wp_footer', array( __CLASS__, 'tip_script' ), 40 );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'hide_internal_gateways_from_customers' ), 999 );
    }


    /**
     * Remove legacy/internal product fields from the customer-facing cart when
     * Batchly now owns the structured equivalent.
     */
    public static function cleanup_legacy_cart_item_data( $item_data, $cart_item ) {
        $product_id = ! empty( $cart_item['persiano_original_product_id'] ) ? absint( $cart_item['persiano_original_product_id'] ) : ( ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0 );
        $details    = $product_id && function_exists( 'persiano_hub_get_product_details' ) ? persiano_hub_get_product_details( $product_id ) : array();
        $has_size   = ! empty( $details['size'] );

        foreach ( (array) $item_data as $index => $row ) {
            $label = isset( $row['key'] ) ? $row['key'] : ( isset( $row['name'] ) ? $row['name'] : '' );
            if ( self::is_hidden_legacy_label( $label, $has_size ) ) {
                unset( $item_data[ $index ] );
            }
        }

        return array_values( $item_data );
    }

    /**
     * Keep internal bilingual/import metadata out of customer and admin order
     * summaries while retaining useful customer notes.
     */
    public static function cleanup_order_item_meta( $formatted_meta, $item ) {
        $product_id = is_object( $item ) && method_exists( $item, 'get_product_id' ) ? absint( $item->get_product_id() ) : 0;
        $details    = $product_id && function_exists( 'persiano_hub_get_product_details' ) ? persiano_hub_get_product_details( $product_id ) : array();
        $has_size   = ! empty( $details['size'] );

        foreach ( (array) $formatted_meta as $meta_id => $meta ) {
            $label = '';
            if ( is_object( $meta ) ) {
                $label = isset( $meta->display_key ) ? $meta->display_key : ( isset( $meta->key ) ? $meta->key : '' );
            }
            if ( self::is_hidden_legacy_label( $label, $has_size ) ) {
                unset( $formatted_meta[ $meta_id ] );
            }
        }

        return $formatted_meta;
    }

    public static function cart_item_data( $item_data, $cart_item ) {
        $product_id = ! empty( $cart_item['persiano_original_product_id'] ) ? absint( $cart_item['persiano_original_product_id'] ) : ( ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0 );
        if ( ! $product_id || ! function_exists( 'persiano_hub_get_product_details' ) ) {
            return $item_data;
        }

        $details = persiano_hub_get_product_details( $product_id );

        if ( empty( $cart_item['persiano_advance_order'] ) && ! empty( $details['available_date'] ) ) {
            $item_data[] = array(
                'key'   => __( 'Available', 'persiano-hub' ),
                'value' => self::format_date( $details['available_date'] ),
            );
        }

        if ( ! empty( $details['size'] ) ) {
            $item_data[] = array(
                'key'   => __( 'Size', 'persiano-hub' ),
                'value' => sanitize_text_field( $details['size'] ),
            );
        }

        $methods = self::fulfilment_methods( $details );
        if ( $methods ) {
            $item_data[] = array(
                'key'   => __( 'Available by', 'persiano-hub' ),
                'value' => $methods,
            );
        }

        return $item_data;
    }

    public static function validate_cart_deadlines() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! function_exists( 'persiano_hub_get_product_details' ) ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( ! empty( $cart_item['persiano_advance_order'] ) ) {
                continue;
            }
            $product_id = ! empty( $cart_item['persiano_original_product_id'] ) ? absint( $cart_item['persiano_original_product_id'] ) : ( ! empty( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0 );
            if ( ! $product_id ) {
                continue;
            }

            $details = persiano_hub_get_product_details( $product_id );
            if ( empty( $details['close_at_deadline'] ) || empty( $details['order_deadline'] ) ) {
                continue;
            }

            try {
                $deadline = new DateTimeImmutable( $details['order_deadline'], wp_timezone() );
                $now = new DateTimeImmutable( 'now', wp_timezone() );
                if ( $now >= $deadline ) {
                    $product = wc_get_product( $product_id );
                    wc_add_notice(
                        sprintf(
                            /* translators: %s: product name */
                            __( '%s can no longer be ordered because its order deadline has passed. Please remove it from your cart.', 'persiano-hub' ),
                            $product ? $product->get_name() : __( 'This item', 'persiano-hub' )
                        ),
                        'error'
                    );
                }
            } catch ( Exception $e ) {
                continue;
            }
        }
    }

    public static function classic_order_button_text( $text ) {
        if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
            global $wp;
            $order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
            $order = $order_id ? wc_get_order( $order_id ) : false;
            if ( $order ) {
                return sprintf( __( 'Pay %s Securely', 'persiano-hub' ), wp_strip_all_tags( $order->get_formatted_order_total() ) );
            }
            return __( 'Pay Securely', 'persiano-hub' );
        }
        return __( 'Place Order & Pay', 'persiano-hub' );
    }

    public static function hide_internal_invoice_gateway( $gateways ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $gateways;
        }
        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            foreach ( array( 'persiano_invoice', 'invoice' ) as $gateway_id ) {
                if ( isset( $gateways[ $gateway_id ] ) ) {
                    unset( $gateways[ $gateway_id ] );
                }
            }
        }
        return $gateways;
    }


    /**
     * Hide internal/manual payment methods from customer-facing checkout and
     * order-payment screens while leaving them available in wp-admin.
     *
     * @param array $gateways Available payment gateways.
     * @return array
     */
    public static function hide_internal_gateways_from_customers( $gateways ) {
        if ( ! is_array( $gateways ) ) {
            return $gateways;
        }

        if ( is_admin() && ! wp_doing_ajax() ) {
            return $gateways;
        }

        $is_customer_payment_screen = false;
        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            $is_customer_payment_screen = true;
        }
        if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
            $is_customer_payment_screen = true;
        }

        if ( ! $is_customer_payment_screen ) {
            return $gateways;
        }

        $blocked_ids = array(
            'persiano_invoice',
            'invoice',
            'cod',
            'bacs',
            'cheque',
            'persiano_pay_in_person',
            'pay_in_person',
            'paid_externally',
            'manual_payment',
        );

        $blocked_terms = array(
            'invoice',
            'pay in person',
            'paid externally',
            'manual payment',
            'already paid',
        );

        foreach ( $gateways as $gateway_id => $gateway ) {
            $remove = in_array( (string) $gateway_id, $blocked_ids, true );
            if ( ! $remove && is_object( $gateway ) ) {
                $title = '';
                if ( method_exists( $gateway, 'get_title' ) ) {
                    $title = (string) $gateway->get_title();
                } elseif ( isset( $gateway->title ) ) {
                    $title = (string) $gateway->title;
                }
                $haystack = strtolower( wp_strip_all_tags( $title ) );
                foreach ( $blocked_terms as $term ) {
                    if ( false !== strpos( $haystack, $term ) ) {
                        $remove = true;
                        break;
                    }
                }
            }
            if ( $remove ) {
                unset( $gateways[ $gateway_id ] );
            }
        }

        return $gateways;
    }

    public static function save_order_item_details( $item, $cart_item_key, $values, $order ) {
        $product_id = ! empty( $values['persiano_original_product_id'] ) ? absint( $values['persiano_original_product_id'] ) : ( ! empty( $values['product_id'] ) ? absint( $values['product_id'] ) : 0 );
        if ( ! $product_id || ! function_exists( 'persiano_hub_get_product_details' ) ) {
            return;
        }

        $details = persiano_hub_get_product_details( $product_id );

        if ( empty( $values['persiano_advance_order'] ) && ! empty( $details['available_date'] ) ) {
            $item->add_meta_data( __( 'Available', 'persiano-hub' ), self::format_date( $details['available_date'] ), true );
        }
        if ( ! empty( $details['size'] ) ) {
            $item->add_meta_data( __( 'Size', 'persiano-hub' ), sanitize_text_field( $details['size'] ), true );
        }
        $methods = self::fulfilment_methods( $details );
        if ( $methods ) {
            $item->add_meta_data( __( 'Available by', 'persiano-hub' ), $methods, true );
        }
    }


    public static function render_tip_selector() {
        if ( ! is_checkout() ) { return; }
        $base = self::tip_base_amount();
        $current_tip = self::current_tip_amount();
        echo '<section class="ph-tip-box"><h3>' . esc_html__( 'Add a tip', 'persiano-hub' ) . '</h3><p>' . esc_html__( 'Optional. Tips are calculated from food items only, before tax and delivery.', 'persiano-hub' ) . '</p><div class="ph-tip-options">';
        foreach ( array( 0 => __( 'No tip', 'persiano-hub' ), 10 => '10%', 15 => '15%', 20 => '20%' ) as $pct => $label ) {
            echo '<button type="button" class="ph-tip-choice' . ( abs( $current_tip - round( $base * $pct / 100, wc_get_price_decimals() ) ) < 0.01 ? ' is-active' : '' ) . '" data-percent="' . esc_attr( $pct ) . '" data-base="' . esc_attr( $base ) . '">' . esc_html( $label ) . '</button>';
        }
        echo '<label class="ph-tip-custom-wrap"><span>' . esc_html__( 'Custom amount', 'persiano-hub' ) . '</span><b>$</b><input type="number" class="ph-tip-custom" min="0" step="1" placeholder="0.00"></label></div><div class="ph-tip-status" aria-live="polite"></div></section>';
    }


    private static function current_tip_amount() {
        if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
            global $wp;
            $order = ! empty( $wp->query_vars['order-pay'] ) ? wc_get_order( absint( $wp->query_vars['order-pay'] ) ) : false;
            if ( $order ) { foreach ( $order->get_items( 'fee' ) as $fee ) { if ( 'yes' === $fee->get_meta( '_persiano_tip', true ) ) { return (float) $fee->get_total(); } } }
        }
        $amount = WC()->session ? (float) WC()->session->get( 'persiano_tip_amount', 0 ) : 0;
        return $amount < 0.01 ? 0.0 : round( $amount, wc_get_price_decimals() );
    }

    private static function tip_base_amount() {
        if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
            global $wp;
            $order = ! empty( $wp->query_vars['order-pay'] ) ? wc_get_order( absint( $wp->query_vars['order-pay'] ) ) : false;
            if ( $order ) { $sum=0; foreach ( $order->get_items('line_item') as $item ) { $sum += (float) $item->get_total(); } return $sum; }
        }
        return WC()->cart ? (float) WC()->cart->get_cart_contents_total() : 0;
    }

    public static function ajax_set_tip() {
        check_ajax_referer( 'persiano_set_tip', 'nonce' );
        $amount = max( 0, (float) wc_format_decimal( wp_unslash( $_POST['amount'] ?? 0 ), wc_get_price_decimals() ) );
        $amount = $amount < 0.01 ? 0.0 : round( $amount, wc_get_price_decimals() );
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) || ! $order->needs_payment() ) { wp_send_json_error( array( 'message' => __( 'This order cannot be updated.', 'persiano-hub' ) ), 403 ); }
            foreach ( $order->get_items( 'fee' ) as $item_id => $fee ) { if ( 'yes' === $fee->get_meta( '_persiano_tip', true ) ) { $order->remove_item( $item_id ); } }
            if ( $amount > 0 ) { $tip = new WC_Order_Item_Fee(); $tip->set_name( __( 'Tip', 'persiano-hub' ) ); $tip->set_amount( $amount ); $tip->set_total( $amount ); $tip->set_tax_status( 'none' ); $tip->update_meta_data( '_persiano_tip', 'yes' ); $order->add_item( $tip ); }
            $order->calculate_totals( true ); $order->save();
            wp_send_json_success( array( 'reload' => true, 'total' => wp_strip_all_tags( $order->get_formatted_order_total() ) ) );
        }
        if ( WC()->session ) { WC()->session->set( 'persiano_tip_amount', $amount ); }
        wp_send_json_success( array( 'reload' => true ) );
    }

    public static function add_checkout_tip_fee( $cart ) {
        if ( is_admin() && ! wp_doing_ajax() ) { return; }
        $amount = WC()->session ? (float) WC()->session->get( 'persiano_tip_amount', 0 ) : 0;
        $amount = $amount < 0.01 ? 0.0 : round( $amount, wc_get_price_decimals() );
        if ( $amount > 0 ) { $cart->add_fee( __( 'Tip', 'persiano-hub' ), $amount, false ); }
    }

    public static function tip_script() {
        if ( ! is_checkout() ) { return; }
        global $wp;
        $order_id = ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() && ! empty( $wp->query_vars['order-pay'] ) ) ? absint( $wp->query_vars['order-pay'] ) : 0;
        $order = $order_id ? wc_get_order( $order_id ) : false;
        $payload = array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'persiano_set_tip' ), 'orderId' => $order_id, 'orderKey' => $order ? $order->get_order_key() : '' );
        ?><style>.ph-tip-box{margin:22px 0;padding:22px;border:1px solid #e5ddd2;border-radius:18px;background:#fffaf3;font-size:16px}.ph-tip-box h3{margin:0 0 6px;font-size:30px}.ph-tip-box p{font-size:16px}.ph-tip-options{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.ph-tip-choice{border:1px solid #a32638;background:#fff;color:#a32638;border-radius:999px;padding:11px 18px;min-height:44px;font-size:16px;font-weight:700;cursor:pointer}.ph-tip-choice:hover,.ph-tip-choice.is-active{background:#a32638;color:#fff}.ph-tip-custom{width:120px!important;min-height:44px;font-size:16px!important}.ph-tip-status{margin-top:8px;font-size:1rem}</style><script>(function(){var cfg=<?php echo wp_json_encode( $payload ); ?>;function setTip(amount,btn){var fd=new FormData();fd.append('action','persiano_set_tip');fd.append('nonce',cfg.nonce);fd.append('amount',Math.max(0,amount||0));fd.append('order_id',cfg.orderId);fd.append('order_key',cfg.orderKey);document.querySelectorAll('.ph-tip-choice').forEach(function(x){x.classList.remove('is-active')});if(btn)btn.classList.add('is-active');var s=document.querySelector('.ph-tip-status');if(s)s.textContent='Updating total…';fetch(cfg.ajax,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json()}).then(function(j){if(j.success){window.location.reload()}else if(s){s.textContent=(j.data&&j.data.message)||'Could not update tip.'}}).catch(function(){if(s)s.textContent='Could not update tip.'})}document.addEventListener('click',function(e){var b=e.target.closest('.ph-tip-choice');if(!b)return;var base=parseFloat(b.dataset.base||'0'),pct=parseFloat(b.dataset.percent||'0');setTip(base*pct/100,b)});document.addEventListener('focusin',function(e){if(e.target.matches('.ph-tip-custom')){document.querySelectorAll('.ph-tip-choice').forEach(function(x){x.classList.remove('is-active')});e.target.closest('.ph-tip-custom-wrap').classList.add('is-active')}});document.addEventListener('change',function(e){if(e.target.matches('.ph-tip-custom'))setTip(parseFloat(e.target.value||'0'),null)});})();</script><?php
    }


    private static function is_hidden_legacy_label( $label, $has_size = false ) {
        $normalized = strtolower( trim( wp_strip_all_tags( (string) $label ) ) );
        $normalized = preg_replace( '/\s+/', ' ', $normalized );

        $hidden = array(
            'title fa',
            'fa title',
            'description fa',
            'fa description',
            'fa note',
        );

        if ( $has_size ) {
            $hidden[] = 'serving size';
        }

        return in_array( $normalized, $hidden, true );
    }

    private static function fulfilment_methods( $details ) {
        $methods = array();
        if ( ! empty( $details['pickup'] ) ) {
            $methods[] = __( 'Pickup', 'persiano-hub' );
        }
        if ( ! empty( $details['delivery'] ) ) {
            $methods[] = __( 'Local delivery', 'persiano-hub' );
        }
        if ( ! empty( $details['shipping'] ) ) {
            $methods[] = __( 'Shipping', 'persiano-hub' );
        }
        return implode( ' · ', $methods );
    }

    private static function format_date( $value ) {
        try {
            $date = new DateTimeImmutable( $value . ' 12:00:00', wp_timezone() );
            return wp_date( 'l, M j', $date->getTimestamp(), wp_timezone() );
        } catch ( Exception $e ) {
            return sanitize_text_field( $value );
        }
    }
}
