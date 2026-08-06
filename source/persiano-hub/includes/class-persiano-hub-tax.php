<?php
/**
 * Persiano Dish tax configuration helpers.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Tax {
    const SETUP_OPTION  = 'persiano_hub_tax_setup_version';
    const SETUP_VERSION = '1.0.0';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_configure_store' ), 20 );
    }

    /**
     * Configure the store so entered/menu prices are tax-inclusive and ensure a
     * standard 5% BC GST rate exists for taxable prepared meals.
     *
     * The setup is intentionally conservative: existing Pantry/Other product
     * tax statuses are not changed automatically.
     */
    public static function maybe_configure_store() {
        if ( ! class_exists( 'WooCommerce' ) || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $version = (string) get_option( self::SETUP_OPTION, '0' );
        if ( version_compare( $version, self::SETUP_VERSION, '>=' ) ) {
            return;
        }

        update_option( 'woocommerce_calc_taxes', 'yes' );
        update_option( 'woocommerce_prices_include_tax', 'yes' );
        update_option( 'woocommerce_tax_display_shop', 'incl' );
        update_option( 'woocommerce_tax_display_cart', 'incl' );
        update_option( 'woocommerce_tax_total_display', 'single' );

        self::ensure_bc_gst_rate();
        self::apply_prepared_meal_defaults();

        update_option( self::SETUP_OPTION, self::SETUP_VERSION );
    }

    /**
     * Add a standard 5% GST rate for BC only when no equivalent standard rate
     * already exists. This avoids duplicating a rate a store owner configured.
     */
    private static function ensure_bc_gst_rate() {
        global $wpdb;

        if ( ! class_exists( 'WC_Tax' ) || ! method_exists( 'WC_Tax', '_insert_tax_rate' ) ) {
            return;
        }

        $table = $wpdb->prefix . 'woocommerce_tax_rates';
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT tax_rate_id
                 FROM {$table}
                 WHERE tax_rate_country = %s
                   AND tax_rate IN (%s, %s)
                   AND tax_rate_class = %s
                   AND (tax_rate_state = %s OR tax_rate_state = %s)
                 LIMIT 1",
                'CA',
                '5',
                '5.0000',
                '',
                'BC',
                ''
            )
        );

        if ( $existing ) {
            return;
        }

        WC_Tax::_insert_tax_rate(
            array(
                'tax_rate_country'  => 'CA',
                'tax_rate_state'    => 'BC',
                'tax_rate'          => '5.0000',
                'tax_rate_name'     => 'GST',
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 0,
                'tax_rate_order'    => 0,
                'tax_rate_class'    => '',
            )
        );
    }

    /**
     * Existing prepared meals become standard-taxable unless a Persiano tax
     * treatment has already been explicitly saved for that product.
     */
    private static function apply_prepared_meal_defaults() {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return;
        }

        $product_ids = wc_get_products(
            array(
                'limit'  => -1,
                'return' => 'ids',
                'status' => array( 'publish', 'draft', 'private', 'pending' ),
            )
        );

        foreach ( $product_ids as $product_id ) {
            if ( get_post_meta( $product_id, Persiano_Hub_Product_Fields::META_TAX_TREATMENT, true ) ) {
                continue;
            }

            $details = Persiano_Hub_Product_Fields::get_product_details( $product_id );
            if ( 'prepared_meal' !== $details['content_type'] && empty( $details['show_this_week'] ) ) {
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $product->set_tax_status( 'taxable' );
            $product->set_tax_class( '' );
            $product->update_meta_data( Persiano_Hub_Product_Fields::META_TAX_TREATMENT, 'standard' );
            $product->save();
        }
    }
}
