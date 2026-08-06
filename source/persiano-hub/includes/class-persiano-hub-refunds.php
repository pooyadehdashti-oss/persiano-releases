<?php
/**
 * Refund usability fixes for WooCommerce order administration.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Refunds {
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ), 99 );
    }

    public static function admin_assets( $hook_suffix ) {
        if ( 'woocommerce_page_wc-orders' !== $hook_suffix ) {
            return;
        }

        if ( empty( $_GET['action'] ) || 'edit' !== sanitize_key( wp_unslash( $_GET['action'] ) ) || empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $order_id = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $remaining = max( 0, (float) $order->get_remaining_refund_amount() );
        $currency  = $order->get_currency();
        $symbol    = get_woocommerce_currency_symbol( $currency );

        wp_enqueue_script( 'jquery' );
        wp_enqueue_style( 'woocommerce_admin_styles' );

        $data = array(
            'remaining'        => wc_format_decimal( $remaining, wc_get_price_decimals() ),
            'formatted'        => wp_strip_all_tags( wc_price( $remaining, array( 'currency' => $currency ) ) ),
            'currencySymbol'   => html_entity_decode( $symbol, ENT_QUOTES, get_bloginfo( 'charset' ) ),
            'emptyMessage'     => __( 'Enter a refund amount greater than zero.', 'persiano-hub' ),
            'tooHighMessage'   => sprintf(
                /* translators: %s: maximum refundable amount */
                __( 'The maximum available refund is %s.', 'persiano-hub' ),
                wp_strip_all_tags( wc_price( $remaining, array( 'currency' => $currency ) ) )
            ),
            'helperText'       => sprintf(
                /* translators: %s: refundable amount */
                __( 'Available to refund: %s. Use the full amount button to populate the refundable line items, or edit individual line amounts for a partial refund.', 'persiano-hub' ),
                wp_strip_all_tags( wc_price( $remaining, array( 'currency' => $currency ) ) )
            ),
        );

        $js = <<<'JS'
(function($){
    'use strict';

    var cfg = window.PersianoRefundUX || {};

    function numberValue(value) {
        var normalized = String(value || '').replace(/[^0-9.,-]/g, '').replace(',', '.');
        var parsed = parseFloat(normalized);
        return isFinite(parsed) ? parsed : 0;
    }

    function amountInput() {
        return $('#refund_amount, input.refund_amount').first();
    }

    function visibleRefundPanel() {
        return $('.wc-order-refund-items:visible, .refund-actions:visible').first();
    }

    function triggerRecalculation() {
        $('.refund_line_total:visible, .refund_line_tax:visible, .refund_order_item_qty:visible')
            .trigger('input').trigger('keyup').trigger('change');
        $(document.body).trigger('wc_order_items_recalculate');
    }

    function installHelper() {
        var $panel = visibleRefundPanel();
        var $input = amountInput();
        if (!$panel.length || $('.persiano-refund-helper').length) {
            return;
        }
        var $helper = $('<div class="persiano-refund-helper"></div>');
        $('<span class="persiano-refund-helper__text"></span>').text(cfg.helperText || '').appendTo($helper);
        $('<button type="button" class="button button-small persiano-use-full-refund"></button>')
            .text('Use full amount (' + (cfg.formatted || '') + ')')
            .appendTo($helper);
        if ($input.length) {
            $input.closest('tr, .refund-actions').after($helper);
        } else {
            $panel.append($helper);
        }
    }

    function fillFullAmount() {
        var found = false;
        $('.refund_order_item_qty:visible').each(function(){
            var $field = $(this);
            var max = $field.attr('max') || $field.data('max') || $field.data('max-qty');
            if (max !== undefined && max !== '') {
                $field.val(max);
                found = true;
            }
        });
        $('.refund_line_total:visible, .refund_line_tax:visible').each(function(){
            var $field = $(this);
            var max = $field.data('max-refund');
            if (max === undefined) { max = $field.attr('data-max_refund'); }
            if (max === undefined) { max = $field.attr('data-max-refund'); }
            if (max !== undefined && max !== '') {
                $field.val(numberValue(max).toFixed(2));
                found = true;
            }
        });
        triggerRecalculation();
        window.setTimeout(function(){
            var $input = amountInput();
            if ($input.length && numberValue($input.val()) <= 0 && numberValue(cfg.remaining) > 0 && !found) {
                $input.val(cfg.remaining).trigger('input').trigger('keyup').trigger('change');
            }
        }, 80);
    }

    $(document).on('click', '.refund-items', function(){
        window.setTimeout(function(){
            installHelper();
            if (numberValue(amountInput().val()) <= 0) { fillFullAmount(); }
        }, 250);
    });

    $(document).on('click', '.persiano-use-full-refund', function(){
        fillFullAmount();
    });

    $(document).on('click', '.do-manual-refund, .do-api-refund', function(event){
        var amount = numberValue(amountInput().val());
        var maximum = numberValue(cfg.remaining);
        if (amount <= 0) {
            event.preventDefault();
            event.stopImmediatePropagation();
            window.alert(cfg.emptyMessage || 'Enter a refund amount greater than zero.');
            return false;
        }
        if (maximum > 0 && amount > maximum + 0.0001) {
            event.preventDefault();
            event.stopImmediatePropagation();
            window.alert(cfg.tooHighMessage || 'The refund amount is too high.');
            return false;
        }
    });

    $(function(){
        if (visibleRefundPanel().length) {
            installHelper();
            if (numberValue(amountInput().val()) <= 0) { fillFullAmount(); }
        }
    });
})(jQuery);
JS;

        wp_add_inline_script( 'jquery', 'window.PersianoRefundUX=' . wp_json_encode( $data ) . ';' . $js );

        $css = '
            .persiano-refund-helper{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin:10px 0 0;padding:10px 0 0;border-top:1px solid #dcdcde}.persiano-refund-helper__text{font-size:13px;color:#50575e}.persiano-use-full-refund{white-space:nowrap}#refund_amount,input.refund_amount{min-width:150px;font-weight:600}
        ';
        wp_add_inline_style( 'woocommerce_admin_styles', $css );
    }
}
