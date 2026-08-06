<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Savings {
    public static function init() {
        add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_item_price' ), 20, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'cart_item_subtotal' ), 20, 3 );
        add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'cart_total_savings_row' ) );
        add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'cart_total_savings_row' ) );
        add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'order_totals' ), 30, 3 );
        add_action( 'woocommerce_admin_order_totals_after_total', array( __CLASS__, 'admin_order_savings' ) );
    }

    private static function product_regular_price( $product ) {
        if ( ! $product instanceof WC_Product ) { return 0.0; }
        $regular = (float) $product->get_regular_price();
        return $regular > 0 ? $regular : (float) $product->get_price();
    }

    public static function cart_item_price( $html, $cart_item, $cart_item_key ) {
        $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
        if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) { return $html; }
        $regular = self::product_regular_price( $product );
        $sale = (float) $product->get_price();
        if ( $regular <= $sale ) { return $html; }
        return wc_format_sale_price( wc_price( $regular ), wc_price( $sale ) ) . '<br><small class="ph-item-saving">' . esc_html( sprintf( __( 'Save %s each', 'persiano-hub' ), wp_strip_all_tags( wc_price( $regular - $sale ) ) ) ) . '</small>';
    }

    public static function cart_item_subtotal( $html, $cart_item, $cart_item_key ) {
        $product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
        $qty = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0;
        if ( ! $product instanceof WC_Product || ! $product->is_on_sale() || $qty <= 0 ) { return $html; }
        $regular = self::product_regular_price( $product ) * $qty;
        $sale = (float) $product->get_price() * $qty;
        if ( $regular <= $sale ) { return $html; }
        return wc_format_sale_price( wc_price( $regular ), wc_price( $sale ) ) . '<br><small class="ph-item-saving">' . esc_html( sprintf( __( 'You save %s', 'persiano-hub' ), wp_strip_all_tags( wc_price( $regular - $sale ) ) ) ) . '</small>';
    }

    public static function cart_product_savings() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return 0.0; }
        $saving = 0.0;
        foreach ( WC()->cart->get_cart() as $item ) {
            $product = isset( $item['data'] ) ? $item['data'] : null;
            $qty = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0;
            if ( $product instanceof WC_Product && $product->is_on_sale() ) {
                $saving += max( 0, ( self::product_regular_price( $product ) - (float) $product->get_price() ) * $qty );
            }
        }
        return $saving;
    }

    public static function cart_total_savings_row() {
        $product_saving = self::cart_product_savings();
        $coupon_saving = function_exists( 'WC' ) && WC()->cart ? (float) WC()->cart->get_discount_total() + (float) WC()->cart->get_discount_tax() : 0.0;
        $total = $product_saving + $coupon_saving;
        if ( $total <= 0 ) { return; }
        echo '<tr class="ph-total-savings"><th>' . esc_html__( 'You saved', 'persiano-hub' ) . '</th><td data-title="' . esc_attr__( 'You saved', 'persiano-hub' ) . '"><strong>' . wp_kses_post( wc_price( $total ) ) . '</strong></td></tr>';
    }

    public static function order_product_savings( $order ) {
        if ( ! $order instanceof WC_Order ) { return 0.0; }
        $saving = 0.0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $product = $item->get_product();
            $qty = (float) $item->get_quantity();
            if ( ! $product instanceof WC_Product || ! $product->is_on_sale() || $qty <= 0 ) { continue; }
            $regular_each = self::product_regular_price( $product );
            $regular_ex_tax = wc_get_price_excluding_tax( $product, array( 'qty' => $qty, 'price' => $regular_each ) );
            $actual_ex_tax = (float) $item->get_subtotal();
            $saving += max( 0, $regular_ex_tax - $actual_ex_tax );
        }
        return round( $saving, wc_get_price_decimals() );
    }

    public static function manual_discount( $order ) {
        $amount = 0.0;
        foreach ( $order->get_items( 'fee' ) as $fee ) {
            if ( 'yes' === $fee->get_meta( '_persiano_manual_discount', true ) ) {
                $amount += abs( (float) $fee->get_total() + (float) $fee->get_total_tax() );
            }
        }
        return round( $amount, wc_get_price_decimals() );
    }

    public static function order_totals( $totals, $order, $tax_display ) {
        if ( ! $order instanceof WC_Order ) { return $totals; }
        $product = self::order_product_savings( $order );
        $manual = self::manual_discount( $order );
        if ( $product > 0 ) {
            $totals['persiano_product_savings'] = array( 'label' => __( 'Promotion savings:', 'persiano-hub' ), 'value' => wc_price( $product, array( 'currency' => $order->get_currency() ) ) );
        }
        if ( $manual > 0 ) {
            $totals['persiano_order_adjustments'] = array( 'label' => __( 'Order adjustments:', 'persiano-hub' ), 'value' => wc_price( $manual, array( 'currency' => $order->get_currency() ) ) );
        }
        return $totals;
    }

    public static function admin_order_savings( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return; }
        $promotion = self::order_product_savings( $order );
        $adjustment = self::manual_discount( $order );
        if ( $promotion > 0 ) {
            echo '<tr><td class="label">' . esc_html__( 'Promotion savings:', 'persiano-hub' ) . '</td><td width="1%"></td><td class="total">' . wp_kses_post( wc_price( $promotion, array( 'currency' => $order->get_currency() ) ) ) . '</td></tr>';
        }
        if ( $adjustment > 0 ) {
            echo '<tr><td class="label">' . esc_html__( 'Order adjustments:', 'persiano-hub' ) . '</td><td width="1%"></td><td class="total">' . wp_kses_post( wc_price( $adjustment, array( 'currency' => $order->get_currency() ) ) ) . '</td></tr>';
        }
    }
}
