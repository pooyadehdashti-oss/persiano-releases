<?php
/**
 * Persiano-specific fulfilment rules for WooCommerce.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Fulfilment {
    const OPTION_KEY = 'persiano_hub_fulfilment_settings';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_post_persiano_hub_save_fulfilment', array( __CLASS__, 'save_settings' ) );

        add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'add_package_cache_marker' ), 20 );
        add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_package_rates' ), 100, 2 );
        add_filter( 'woocommerce_cart_no_shipping_available_html', array( __CLASS__, 'no_shipping_message' ) );
        add_filter( 'woocommerce_no_shipping_available_html', array( __CLASS__, 'no_shipping_message' ) );
        add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_common_method' ), 30 );

        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'snapshot_order_fulfilment' ), 20, 2 );
        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_order_fulfilment' ), 20 );
        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'render_email_fulfilment' ), 20, 4 );
        add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'clean_order_fulfilment_total' ), 20, 3 );
        add_filter( 'woocommerce_shipping_package_name', array( __CLASS__, 'shipping_package_name' ), 20, 3 );
    }

    public static function defaults() {
        return array(
            'pickup_enabled'          => 'yes',
            'pickup_label'            => sprintf( __( 'Pickup from %s', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ),
            'pickup_fee'              => '0',
            'pickup_address'          => '',
            'pickup_window'           => '',
            'pickup_instructions'     => __( 'Pickup details will be included with your order confirmation.', 'persiano-hub' ),
            'delivery_enabled'        => 'no',
            'delivery_label'          => __( 'Local delivery', 'persiano-hub' ),
            'delivery_fee'            => '',
            'delivery_minimum'        => '',
            'delivery_free_threshold' => '',
            'delivery_cities'         => class_exists( 'Persiano_Hub_Business_Profile' ) ? str_replace( ',', "\n", Persiano_Hub_Business_Profile::service_area() ) : '',
            'delivery_postcodes'      => '',
            'delivery_window'         => '',
            'delivery_instructions'   => '',
            'shipping_enabled'        => 'no',
            'shipping_label'          => __( 'Pantry shipping', 'persiano-hub' ),
            'shipping_fee'            => '',
            'shipping_free_threshold' => '',
            'shipping_country'        => 'CA',
            'shipping_note'           => __( 'Shipping is available only for eligible Pantry products.', 'persiano-hub' ),
            'fees_taxable'            => 'no',
        );
    }

    public static function get_settings() {
        $saved = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
    }

    public static function admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Fulfilment Settings', 'persiano-hub' ),
            __( 'Fulfilment', 'persiano-hub' ),
            'manage_woocommerce',
            'persiano-fulfilment',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings = self::get_settings();
        $saved    = isset( $_GET['persiano_saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['persiano_saved'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap ph-fulfilment-settings">
            <h1><?php esc_html_e( 'Persiano Fulfilment', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'Control the checkout methods offered by Batchly. Product-level Pickup, Local delivery and Shipping checkboxes determine which methods are allowed for each item.', 'persiano-hub' ); ?></p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Fulfilment settings saved.', 'persiano-hub' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="persiano_hub_save_fulfilment">
                <?php wp_nonce_field( 'persiano_hub_save_fulfilment' ); ?>

                <div class="ph-admin-settings-card">
                    <h2><?php esc_html_e( 'Pickup', 'persiano-hub' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Pickup is offered only when every item in the cart allows Pickup.', 'persiano-hub' ); ?></p>
                    <?php self::checkbox_row( 'pickup_enabled', __( 'Enable pickup', 'persiano-hub' ), $settings ); ?>
                    <?php self::text_row( 'pickup_label', __( 'Checkout label', 'persiano-hub' ), $settings ); ?>
                    <?php self::number_row( 'pickup_fee', __( 'Pickup fee', 'persiano-hub' ), $settings, '0.00' ); ?>
                    <?php self::text_row( 'pickup_address', __( 'Pickup address', 'persiano-hub' ), $settings, __( 'Shown after an order is placed. Leave blank until you want the address displayed.', 'persiano-hub' ) ); ?>
                    <?php self::text_row( 'pickup_window', __( 'Pickup window', 'persiano-hub' ), $settings, __( 'Example: Thursday 5:30–7:30 PM', 'persiano-hub' ) ); ?>
                    <?php self::textarea_row( 'pickup_instructions', __( 'Pickup instructions', 'persiano-hub' ), $settings ); ?>
                </div>

                <div class="ph-admin-settings-card">
                    <h2><?php esc_html_e( 'Local delivery', 'persiano-hub' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Local delivery appears only when every item allows it and the customer address matches one of the cities or postal-code prefixes below.', 'persiano-hub' ); ?></p>
                    <?php self::checkbox_row( 'delivery_enabled', __( 'Enable local delivery', 'persiano-hub' ), $settings ); ?>
                    <?php self::text_row( 'delivery_label', __( 'Checkout label', 'persiano-hub' ), $settings ); ?>
                    <?php self::number_row( 'delivery_fee', __( 'Delivery fee', 'persiano-hub' ), $settings, '0.00' ); ?>
                    <?php self::number_row( 'delivery_minimum', __( 'Minimum order for delivery', 'persiano-hub' ), $settings, '0.00', __( 'Leave blank for no minimum.', 'persiano-hub' ) ); ?>
                    <?php self::number_row( 'delivery_free_threshold', __( 'Free-delivery threshold', 'persiano-hub' ), $settings, '0.00', __( 'Leave blank if you never want the fee removed automatically.', 'persiano-hub' ) ); ?>
                    <?php self::textarea_row( 'delivery_cities', __( 'Eligible cities', 'persiano-hub' ), $settings, __( 'One per line, for example the cities or areas served by this business.', 'persiano-hub' ) ); ?>
                    <?php self::textarea_row( 'delivery_postcodes', __( 'Eligible postal-code prefixes', 'persiano-hub' ), $settings, __( 'Optional. One per line or comma-separated, for example V7H, V7J, V7P. Spaces are ignored.', 'persiano-hub' ) ); ?>
                    <?php self::text_row( 'delivery_window', __( 'Delivery window', 'persiano-hub' ), $settings, __( 'Example: Thursday 6–9 PM', 'persiano-hub' ) ); ?>
                    <?php self::textarea_row( 'delivery_instructions', __( 'Delivery instructions', 'persiano-hub' ), $settings ); ?>
                </div>

                <div class="ph-admin-settings-card">
                    <h2><?php esc_html_e( 'Pantry shipping', 'persiano-hub' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Existing WooCommerce carrier rates remain available when every item is marked Shipping. You can also enable a simple Persiano flat-rate shipping option below.', 'persiano-hub' ); ?></p>
                    <?php self::checkbox_row( 'shipping_enabled', __( 'Enable flat-rate shipping', 'persiano-hub' ), $settings ); ?>
                    <?php self::text_row( 'shipping_label', __( 'Checkout label', 'persiano-hub' ), $settings ); ?>
                    <?php self::number_row( 'shipping_fee', __( 'Shipping fee', 'persiano-hub' ), $settings, '0.00', __( 'Required when the Persiano shipping rate is enabled.', 'persiano-hub' ) ); ?>
                    <?php self::number_row( 'shipping_free_threshold', __( 'Free-shipping threshold', 'persiano-hub' ), $settings, '0.00', __( 'Leave blank if you never want this flat rate removed automatically.', 'persiano-hub' ) ); ?>
                    <?php self::text_row( 'shipping_country', __( 'Allowed shipping country code', 'persiano-hub' ), $settings, __( 'Use CA for Canada-only shipping.', 'persiano-hub' ) ); ?>
                    <?php self::textarea_row( 'shipping_note', __( 'Shipping note', 'persiano-hub' ), $settings ); ?>
                </div>

                <div class="ph-admin-settings-card">
                    <h2><?php esc_html_e( 'Fees & tax', 'persiano-hub' ); ?></h2>
                    <?php self::checkbox_row( 'fees_taxable', __( 'Apply WooCommerce shipping tax calculations to pickup/delivery fees', 'persiano-hub' ), $settings ); ?>
                    <p class="description"><?php esc_html_e( 'Enable this only if your tax setup requires these service fees to be taxed.', 'persiano-hub' ); ?></p>
                </div>

                <?php submit_button( __( 'Save Fulfilment Settings', 'persiano-hub' ) ); ?>
            </form>
        </div>
        <style>
            .ph-fulfilment-settings{max-width:980px}.ph-admin-settings-card{margin:22px 0;padding:22px 26px;border:1px solid #dcdcde;border-radius:12px;background:#fff}.ph-admin-settings-card h2{margin-top:0}.ph-admin-settings-row{display:grid;grid-template-columns:240px minmax(0,1fr);gap:20px;align-items:start;padding:12px 0;border-top:1px solid #f0f0f1}.ph-admin-settings-card .ph-admin-settings-row:first-of-type{border-top:0}.ph-admin-settings-row label{font-weight:600}.ph-admin-settings-row input[type=text],.ph-admin-settings-row input[type=number],.ph-admin-settings-row textarea{width:100%;max-width:560px}.ph-admin-settings-row textarea{min-height:90px}@media(max-width:782px){.ph-admin-settings-row{grid-template-columns:1fr}}
        </style>
        <?php
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to change fulfilment settings.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_save_fulfilment' );

        $defaults = self::defaults();
        $new      = array();

        foreach ( array( 'pickup_enabled', 'delivery_enabled', 'shipping_enabled', 'fees_taxable' ) as $key ) {
            $new[ $key ] = ! empty( $_POST[ $key ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        foreach ( array( 'pickup_label', 'pickup_address', 'pickup_window', 'delivery_label', 'delivery_window', 'shipping_label', 'shipping_country' ) as $key ) {
            $new[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $defaults[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        foreach ( array( 'pickup_instructions', 'delivery_instructions', 'delivery_cities', 'delivery_postcodes', 'shipping_note' ) as $key ) {
            $new[ $key ] = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : $defaults[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        foreach ( array( 'pickup_fee', 'delivery_fee', 'delivery_minimum', 'delivery_free_threshold', 'shipping_fee', 'shipping_free_threshold' ) as $key ) {
            $value       = isset( $_POST[ $key ] ) ? wc_format_decimal( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $new[ $key ] = '' === $value ? '' : (string) max( 0, (float) $value );
        }

        $new['shipping_country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $new['shipping_country'] ), 0, 2 ) );
        if ( ! $new['shipping_country'] ) {
            $new['shipping_country'] = 'CA';
        }

        update_option( self::OPTION_KEY, $new );

        if ( class_exists( 'WC_Cache_Helper' ) ) {
            WC_Cache_Helper::get_transient_version( 'shipping', true );
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'persiano-fulfilment', 'persiano_saved' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }


    public static function add_package_cache_marker( $packages ) {
        if ( ! is_array( $packages ) ) {
            return $packages;
        }
        $marker = md5( wp_json_encode( self::get_settings() ) );
        foreach ( $packages as $index => $package ) {
            if ( is_array( $package ) ) {
                $packages[ $index ]['persiano_fulfilment_hash'] = $marker;
            }
        }
        return $packages;
    }

    public static function filter_package_rates( $rates, $package ) {
        if ( ! is_array( $rates ) || ! is_array( $package ) ) {
            return $rates;
        }

        $eligibility = self::package_eligibility( $package );
        if ( ! $eligibility['managed'] ) {
            return $rates;
        }

        $settings = self::get_settings();
        $filtered = array();

        foreach ( $rates as $rate_id => $rate ) {
            if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_method_id' ) ) {
                continue;
            }

            $method_id = $rate->get_method_id();
            if ( 'local_pickup' === $method_id ) {
                // Persiano pickup replaces generic local pickup so instructions stay consistent.
                continue;
            }

            if ( $eligibility['shipping'] && self::destination_allows_shipping( $package, $settings ) ) {
                $filtered[ $rate_id ] = $rate;
            }
        }

        if ( 'yes' === $settings['pickup_enabled'] && $eligibility['pickup'] ) {
            $fee  = self::decimal( $settings['pickup_fee'] );
            $rate = self::make_rate(
                'persiano_pickup',
                $settings['pickup_label'] ?: sprintf( __( 'Pickup from %s', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ),
                $fee,
                'persiano_pickup',
                $settings['pickup_instructions'],
                $settings['pickup_window'],
                $settings
            );
            $filtered[ $rate->get_id() ] = $rate;
        }

        if ( 'yes' === $settings['delivery_enabled'] && $eligibility['delivery'] && self::destination_allows_local_delivery( $package, $settings ) ) {
            $subtotal = self::package_subtotal( $package );
            $minimum  = self::decimal_or_null( $settings['delivery_minimum'] );

            if ( null === $minimum || $subtotal >= $minimum ) {
                $fee       = self::decimal( $settings['delivery_fee'] );
                $threshold = self::decimal_or_null( $settings['delivery_free_threshold'] );
                if ( null !== $threshold && $subtotal >= $threshold ) {
                    $fee = 0.0;
                }

                $rate = self::make_rate(
                    'persiano_local_delivery',
                    $settings['delivery_label'] ?: __( 'Local delivery', 'persiano-hub' ),
                    $fee,
                    'persiano_local_delivery',
                    $settings['delivery_instructions'],
                    $settings['delivery_window'],
                    $settings
                );
                $filtered[ $rate->get_id() ] = $rate;
            }
        }

        // Optional simple flat-rate parcel shipping for eligible Pantry-only carts.
        // This is useful when no carrier/zone shipping method has been configured yet.
        if ( 'yes' === $settings['shipping_enabled'] && $eligibility['shipping'] && self::destination_allows_shipping( $package, $settings ) ) {
            $subtotal  = self::package_subtotal( $package );
            $fee       = self::decimal_or_null( $settings['shipping_fee'] );
            $threshold = self::decimal_or_null( $settings['shipping_free_threshold'] );

            if ( null !== $fee ) {
                if ( null !== $threshold && $subtotal >= $threshold ) {
                    $fee = 0.0;
                }

                $rate = self::make_rate(
                    'persiano_pantry_shipping',
                    $settings['shipping_label'] ?: __( 'Pantry shipping', 'persiano-hub' ),
                    $fee,
                    'persiano_pantry_shipping',
                    $settings['shipping_note'],
                    '',
                    $settings
                );
                $filtered[ $rate->get_id() ] = $rate;
            }
        }

        return $filtered;
    }

    public static function validate_common_method() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $package = array( 'contents' => WC()->cart->get_cart() );
        $allowed = self::package_eligibility( $package );
        if ( $allowed['managed'] && ! $allowed['pickup'] && ! $allowed['delivery'] && ! $allowed['shipping'] ) {
            wc_add_notice(
                __( 'The items in this cart do not share a common pickup, delivery or shipping method. Please place them as separate orders.', 'persiano-hub' ),
                'error'
            );
        }
    }

    public static function no_shipping_message( $message ) {
        return __( 'No fulfilment option is available for this combination of items and address. Try a local pickup/delivery address or place non-shippable and shippable items as separate orders.', 'persiano-hub' );
    }



    /**
     * Use customer-friendly fulfilment wording in order totals instead of
     * repeating the shipping method name in both the label and value.
     */
    public static function clean_order_fulfilment_total( $rows, $order, $tax_display ) {
        if ( ! $order instanceof WC_Order || empty( $rows['shipping'] ) ) {
            return $rows;
        }

        $method = $order->get_shipping_method();
        if ( $method ) {
            $shipping_total = (float) $order->get_shipping_total();
            $value          = esc_html( $method );

            if ( $shipping_total > 0 ) {
                $value .= ' — ' . wc_price( $shipping_total, array( 'currency' => $order->get_currency() ) );
            }

            $rows['shipping']['label'] = __( 'Fulfilment:', 'persiano-hub' );
            $rows['shipping']['value'] = $value;
        }

        return $rows;
    }

    public static function shipping_package_name( $name, $index = 0, $package = array() ) {
        return __( 'Fulfilment', 'persiano-hub' );
    }

    public static function snapshot_order_fulfilment( $order, $data ) {
        if ( ! $order instanceof WC_Order || ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        $chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
        $rate_id = isset( $chosen[0] ) ? sanitize_text_field( (string) $chosen[0] ) : '';
        if ( ! $rate_id ) {
            return;
        }

        $settings = self::get_settings();
        $snapshot = array(
            'rate_id' => $rate_id,
            'type'    => 'shipping',
            'lines'   => array(),
        );

        if ( 0 === strpos( $rate_id, 'persiano_pickup' ) ) {
            $snapshot['type'] = 'pickup';
            if ( $settings['pickup_address'] ) {
                $snapshot['lines'][] = array( __( 'Pickup location', 'persiano-hub' ), $settings['pickup_address'] );
            }
            if ( $settings['pickup_window'] ) {
                $snapshot['lines'][] = array( __( 'Pickup window', 'persiano-hub' ), $settings['pickup_window'] );
            }
            if ( $settings['pickup_instructions'] ) {
                $snapshot['lines'][] = array( __( 'Instructions', 'persiano-hub' ), $settings['pickup_instructions'] );
            }
        } elseif ( 0 === strpos( $rate_id, 'persiano_local_delivery' ) ) {
            $snapshot['type'] = 'delivery';
            if ( $settings['delivery_window'] ) {
                $snapshot['lines'][] = array( __( 'Delivery window', 'persiano-hub' ), $settings['delivery_window'] );
            }
            if ( $settings['delivery_instructions'] ) {
                $snapshot['lines'][] = array( __( 'Delivery note', 'persiano-hub' ), $settings['delivery_instructions'] );
            }
        } elseif ( $settings['shipping_note'] ) {
            $snapshot['lines'][] = array( __( 'Shipping', 'persiano-hub' ), $settings['shipping_note'] );
        }

        $order->update_meta_data( '_persiano_fulfilment_snapshot', $snapshot );
    }

    public static function render_order_fulfilment( $order ) {
        self::render_fulfilment_summary( $order, false );
    }

    public static function render_email_fulfilment( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin ) {
            return;
        }
        self::render_fulfilment_summary( $order, (bool) $plain_text );
    }

    private static function render_fulfilment_summary( $order, $plain_text = false ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $snapshot = $order->get_meta( '_persiano_fulfilment_snapshot', true );
        if ( is_array( $snapshot ) && ! empty( $snapshot['lines'] ) && is_array( $snapshot['lines'] ) ) {
            $lines = $snapshot['lines'];
            $title = __( 'Fulfilment details', 'persiano-hub' );
        } else {
            $method_id = self::order_method_id( $order );
            if ( ! $method_id ) {
                return;
            }

            $settings = self::get_settings();
            $lines    = array();
            $title    = __( 'Fulfilment details', 'persiano-hub' );

            if ( 'persiano_pickup' === $method_id ) {
            if ( $settings['pickup_address'] ) {
                $lines[] = array( __( 'Pickup location', 'persiano-hub' ), $settings['pickup_address'] );
            }
            if ( $settings['pickup_window'] ) {
                $lines[] = array( __( 'Pickup window', 'persiano-hub' ), $settings['pickup_window'] );
            }
            if ( $settings['pickup_instructions'] ) {
                $lines[] = array( __( 'Instructions', 'persiano-hub' ), $settings['pickup_instructions'] );
            }
        } elseif ( 'persiano_local_delivery' === $method_id ) {
            if ( $settings['delivery_window'] ) {
                $lines[] = array( __( 'Delivery window', 'persiano-hub' ), $settings['delivery_window'] );
            }
            if ( $settings['delivery_instructions'] ) {
                $lines[] = array( __( 'Delivery note', 'persiano-hub' ), $settings['delivery_instructions'] );
            }
            } elseif ( $settings['shipping_note'] ) {
                $lines[] = array( __( 'Shipping', 'persiano-hub' ), $settings['shipping_note'] );
            }
        }

        if ( empty( $lines ) ) {
            return;
        }

        if ( $plain_text ) {
            echo "\n" . $title . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            foreach ( $lines as $line ) {
                echo wp_strip_all_tags( $line[0] . ': ' . $line[1] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            return;
        }

        echo '<section class="ph-order-fulfilment" style="margin:28px 0 20px;"><h2 style="margin:0 0 14px;">' . esc_html( $title ) . '</h2>';
        echo '<table cellspacing="0" cellpadding="7" style="width:100%;border-collapse:collapse;border:1px solid #e5dfd5;">';
        foreach ( $lines as $line ) {
            echo '<tr>';
            echo '<th scope="row" style="width:34%;padding:10px 12px;text-align:left;vertical-align:top;border-bottom:1px solid #e5dfd5;">' . esc_html( $line[0] ) . '</th>';
            echo '<td style="padding:10px 12px;vertical-align:top;border-bottom:1px solid #e5dfd5;">' . nl2br( esc_html( $line[1] ) ) . '</td>';
            echo '</tr>';
        }
        echo '</table></section>';
    }

    private static function package_eligibility( $package ) {
        $result = array(
            'managed'  => false,
            'pickup'   => true,
            'delivery' => true,
            'shipping' => true,
        );

        $contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
        foreach ( $contents as $item ) {
            $product_id = ! empty( $item['persiano_original_product_id'] )
                ? absint( $item['persiano_original_product_id'] )
                : ( ! empty( $item['product_id'] ) ? absint( $item['product_id'] ) : 0 );
            if ( ! $product_id || ! function_exists( 'persiano_hub_get_product_details' ) ) {
                continue;
            }

            $details = persiano_hub_get_product_details( $product_id );
            $flags   = self::effective_product_methods( $details );

            if ( ! $flags['managed'] ) {
                continue;
            }

            $result['managed']  = true;
            $result['pickup']   = $result['pickup'] && $flags['pickup'];
            $result['delivery'] = $result['delivery'] && $flags['delivery'];
            $result['shipping'] = $result['shipping'] && $flags['shipping'];
        }

        return $result;
    }

    private static function effective_product_methods( $details ) {
        $explicit = ! empty( $details['pickup'] ) || ! empty( $details['delivery'] ) || ! empty( $details['shipping'] );
        if ( $explicit ) {
            return array(
                'managed'  => true,
                'pickup'   => ! empty( $details['pickup'] ),
                'delivery' => ! empty( $details['delivery'] ),
                'shipping' => ! empty( $details['shipping'] ),
            );
        }

        $is_prepared = 'prepared_meal' === ( $details['content_type'] ?? '' ) || ! empty( $details['show_this_week'] );
        $is_pantry   = 'pantry' === ( $details['content_type'] ?? '' ) || ! empty( $details['show_pantry'] );

        if ( $is_prepared || $is_pantry ) {
            // Safe defaults: local methods are allowed; parcel shipping must always be explicitly enabled.
            return array(
                'managed'  => true,
                'pickup'   => true,
                'delivery' => true,
                'shipping' => false,
            );
        }

        return array( 'managed' => false, 'pickup' => true, 'delivery' => true, 'shipping' => true );
    }

    private static function destination_allows_local_delivery( $package, $settings ) {
        $destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
        $country     = strtoupper( (string) ( $destination['country'] ?? '' ) );
        if ( $country && 'CA' !== $country ) {
            return false;
        }

        $city     = self::normalize_city( $destination['city'] ?? '' );
        $postcode = self::normalize_postcode( $destination['postcode'] ?? '' );
        $cities   = self::list_values( $settings['delivery_cities'] );
        $prefixes = self::list_values( $settings['delivery_postcodes'] );

        $city_match = false;
        foreach ( $cities as $allowed_city ) {
            if ( $city && self::normalize_city( $allowed_city ) === $city ) {
                $city_match = true;
                break;
            }
        }

        $postcode_match = false;
        foreach ( $prefixes as $prefix ) {
            $prefix = self::normalize_postcode( $prefix );
            if ( $prefix && $postcode && 0 === strpos( $postcode, $prefix ) ) {
                $postcode_match = true;
                break;
            }
        }

        if ( empty( $cities ) && empty( $prefixes ) ) {
            return false;
        }

        return $city_match || $postcode_match;
    }

    private static function destination_allows_shipping( $package, $settings ) {
        $allowed_country = strtoupper( (string) $settings['shipping_country'] );
        if ( ! $allowed_country ) {
            return true;
        }
        $destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
        $country     = strtoupper( (string) ( $destination['country'] ?? '' ) );
        return ! $country || $allowed_country === $country;
    }

    private static function package_subtotal( $package ) {
        $total = 0.0;
        foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
            if ( isset( $item['line_total'] ) ) {
                $total += (float) $item['line_total'];
            } elseif ( isset( $item['data'], $item['quantity'] ) && $item['data'] instanceof WC_Product ) {
                $total += (float) $item['data']->get_price() * (int) $item['quantity'];
            }
        }
        return $total;
    }

    private static function make_rate( $id, $label, $cost, $method_id, $description, $delivery_time, $settings ) {
        $taxes = array();
        if ( 'yes' === $settings['fees_taxable'] && $cost > 0 && class_exists( 'WC_Tax' ) ) {
            $taxes = WC_Tax::calc_shipping_tax( $cost, WC_Tax::get_shipping_tax_rates() );
        }

        $rate = new WC_Shipping_Rate( $id, $label, $cost, $taxes, $method_id );
        if ( method_exists( $rate, 'set_description' ) && $description ) {
            $rate->set_description( wp_strip_all_tags( $description ) );
        }
        if ( method_exists( $rate, 'set_delivery_time' ) && $delivery_time ) {
            $rate->set_delivery_time( wp_strip_all_tags( $delivery_time ) );
        }
        return $rate;
    }

    private static function order_method_id( $order ) {
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            if ( method_exists( $shipping_item, 'get_method_id' ) ) {
                return $shipping_item->get_method_id();
            }
        }
        return '';
    }

    private static function list_values( $value ) {
        $parts = preg_split( '/[\r\n,]+/', (string) $value );
        $parts = array_map( 'trim', is_array( $parts ) ? $parts : array() );
        return array_values( array_filter( $parts, 'strlen' ) );
    }

    private static function normalize_city( $value ) {
        return strtolower( trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $value ) ) ) );
    }

    private static function normalize_postcode( $value ) {
        return strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $value ) );
    }

    private static function decimal( $value ) {
        return max( 0, (float) wc_format_decimal( $value ) );
    }

    private static function decimal_or_null( $value ) {
        if ( '' === trim( (string) $value ) ) {
            return null;
        }
        return self::decimal( $value );
    }

    private static function checkbox_row( $key, $label, $settings ) {
        printf(
            '<div class="ph-admin-settings-row"><label for="%1$s">%2$s</label><div><label><input type="checkbox" id="%1$s" name="%1$s" value="1" %3$s> %4$s</label></div></div>',
            esc_attr( $key ),
            esc_html( $label ),
            checked( 'yes', $settings[ $key ], false ),
            esc_html__( 'Enabled', 'persiano-hub' )
        );
    }

    private static function text_row( $key, $label, $settings, $help = '' ) {
        printf(
            '<div class="ph-admin-settings-row"><label for="%1$s">%2$s</label><div><input type="text" id="%1$s" name="%1$s" value="%3$s">%4$s</div></div>',
            esc_attr( $key ),
            esc_html( $label ),
            esc_attr( $settings[ $key ] ),
            $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }

    private static function number_row( $key, $label, $settings, $placeholder = '', $help = '' ) {
        printf(
            '<div class="ph-admin-settings-row"><label for="%1$s">%2$s</label><div><input type="number" min="0" step="0.01" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s">%5$s</div></div>',
            esc_attr( $key ),
            esc_html( $label ),
            esc_attr( $settings[ $key ] ),
            esc_attr( $placeholder ),
            $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }

    private static function textarea_row( $key, $label, $settings, $help = '' ) {
        printf(
            '<div class="ph-admin-settings-row"><label for="%1$s">%2$s</label><div><textarea id="%1$s" name="%1$s">%3$s</textarea>%4$s</div></div>',
            esc_attr( $key ),
            esc_html( $label ),
            esc_textarea( $settings[ $key ] ),
            $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
    }
}
