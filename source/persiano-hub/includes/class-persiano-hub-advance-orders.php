<?php
/**
 * Advance-order support for unavailable Persiano products.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Advance_Orders {
    const OPTION_PLACEHOLDER_ID = 'persiano_hub_advance_order_product_id';
    const CART_FLAG             = 'persiano_advance_order';
    const CART_ORIGINAL_ID      = 'persiano_original_product_id';
    const CART_REQUESTED_AT     = 'persiano_requested_datetime';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'ensure_placeholder_product' ), 40 );
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_advance_order_form' ), 32 );
        add_action( 'persiano_dish_product_card_details', array( __CLASS__, 'render_card_notice' ), 20, 1 );

        add_action( 'admin_post_persiano_add_advance_order', array( __CLASS__, 'handle_add_to_cart' ) );
        add_action( 'admin_post_nopriv_persiano_add_advance_order', array( __CLASS__, 'handle_add_to_cart' ) );

        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_advance_order_pricing' ), 20 );
        add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'cart_item_name' ), 20, 3 );
        add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'cart_item_thumbnail' ), 20, 3 );
        add_filter( 'woocommerce_cart_item_permalink', array( __CLASS__, 'cart_item_permalink' ), 20, 3 );
        add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 30, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_line_item' ), 12, 4 );
        add_filter( 'woocommerce_order_item_thumbnail', array( __CLASS__, 'order_item_thumbnail' ), 20, 2 );
        add_filter( 'woocommerce_admin_order_item_thumbnail', array( __CLASS__, 'admin_order_item_thumbnail' ), 20, 3 );

        // Advance orders are requests first: hide online gateways until admin confirmation.
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'limit_payment_gateways_for_advance_orders' ), 100 );
        add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_advance_checkout_notice' ), 8 );
        add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'mark_order_as_advance_request' ), 20, 2 );
        add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_confirm_and_request_payment_action' ), 20, 2 );
        add_action( 'woocommerce_order_action_persiano_confirm_advance_and_request_payment', array( __CLASS__, 'confirm_advance_and_request_payment' ) );

        /*
         * Keep the hidden placeholder permanently purchasable/in stock and
         * repair advance-order cart items restored from older sessions.
         */
        add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'restore_advance_cart_item_product' ), 50, 3 );
        add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'placeholder_is_in_stock' ), 50, 2 );
        add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'placeholder_is_purchasable' ), 50, 2 );

        add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_order_advance_note' ), 10 );
        add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'render_email_advance_note' ), 10, 4 );
    }

    public static function ensure_placeholder_product() {
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Simple' ) ) {
            return 0;
        }

        $product_id = absint( get_option( self::OPTION_PLACEHOLDER_ID, 0 ) );
        $product    = $product_id ? wc_get_product( $product_id ) : false;

        /*
         * Older Batchly versions created the placeholder with a fixed SKU.
         * If the option was lost while that product still existed, attempting to
         * create another product with the same SKU caused WooCommerce to throw a
         * fatal WC_Data_Exception. Recover the existing product robustly first.
         */
        if ( ! $product ) {
            global $wpdb;

            $legacy_sku = 'persiano-advance-order-placeholder';
            $existing_id = 0;

            if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
                try {
                    $existing_id = absint( wc_get_product_id_by_sku( $legacy_sku ) );
                } catch ( Throwable $e ) {
                    $existing_id = 0;
                }
            }

            if ( ! $existing_id && isset( $wpdb->postmeta ) ) {
                $existing_id = absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY meta_id ASC LIMIT 1",
                            '_sku',
                            $legacy_sku
                        )
                    )
                );
            }

            if ( ! $existing_id && isset( $wpdb->postmeta ) ) {
                $existing_id = absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY meta_id ASC LIMIT 1",
                            '_persiano_advance_placeholder',
                            'yes'
                        )
                    )
                );
            }

            if ( $existing_id ) {
                $product = wc_get_product( $existing_id );
                if ( $product ) {
                    $product_id = $product->get_id();
                }
            }
        }

        if ( ! $product ) {
            $product = new WC_Product_Simple();
            $product->set_name( __( 'Batchly Advance Order', 'persiano-hub' ) );
            $product->set_status( 'publish' );
            $product->set_catalog_visibility( 'hidden' );

            /*
             * No SKU is required for this internal placeholder. Leaving it blank
             * permanently avoids duplicate-SKU failures across reinstalls,
             * restores and database migrations.
             */
            $product->set_regular_price( '0' );
            $product->set_price( '0' );
            $product->set_manage_stock( false );
            $product->set_virtual( false );
            $product->set_reviews_allowed( false );

            try {
                $product_id = $product->save();
            } catch ( Throwable $e ) {
                return 0;
            }
        }

        if ( $product_id && $product instanceof WC_Product ) {
            /*
             * Older placeholder products may have inherited an out-of-stock
             * status. WooCommerce validates the underlying cart product again
             * at checkout, so always normalize this internal product.
             */
            try {
                $needs_save = false;

                if ( 'publish' !== $product->get_status() ) {
                    $product->set_status( 'publish' );
                    $needs_save = true;
                }
                if ( 'hidden' !== $product->get_catalog_visibility() ) {
                    $product->set_catalog_visibility( 'hidden' );
                    $needs_save = true;
                }
                if ( $product->get_manage_stock() ) {
                    $product->set_manage_stock( false );
                    $needs_save = true;
                }
                if ( 'instock' !== $product->get_stock_status() ) {
                    $product->set_stock_status( 'instock' );
                    $needs_save = true;
                }
                if ( '' === (string) $product->get_price() ) {
                    $product->set_regular_price( '0' );
                    $product->set_price( '0' );
                    $needs_save = true;
                }
                if ( $product->is_sold_individually() ) {
                    $product->set_sold_individually( false );
                    $needs_save = true;
                }
                if ( 'persiano-advance-order-placeholder' === (string) $product->get_sku() ) {
                    $product->set_sku( '' );
                    $needs_save = true;
                }

                if ( $needs_save ) {
                    $product->save();
                }
            } catch ( Throwable $e ) {
                // The runtime filters below still keep checkout safe.
            }

            update_post_meta( $product_id, '_persiano_advance_placeholder', 'yes' );
            update_option( self::OPTION_PLACEHOLDER_ID, $product_id );
        }

        return $product_id;
    }

    /**
     * Repair advance-order cart items restored from WooCommerce sessions.
     *
     * The original dish may be intentionally out of stock. The underlying cart
     * product must remain the hidden placeholder so checkout never validates
     * the current-batch stock of the original dish.
     */
    public static function restore_advance_cart_item_product( $session_data, $values, $cart_item_key ) {
        if ( empty( $values[ self::CART_FLAG ] ) ) {
            return $session_data;
        }

        $placeholder_id = self::ensure_placeholder_product();
        if ( ! $placeholder_id ) {
            return $session_data;
        }

        $placeholder = wc_get_product( $placeholder_id );
        if ( ! $placeholder ) {
            return $session_data;
        }

        // Runtime normalization protects carts created by older plugin builds.
        $placeholder->set_manage_stock( false );
        $placeholder->set_stock_status( 'instock' );
        $placeholder->set_price( isset( $session_data['data'] ) && $session_data['data'] instanceof WC_Product ? $session_data['data']->get_price() : '0' );

        $session_data['product_id'] = $placeholder_id;
        $session_data['variation_id'] = 0;
        $session_data['variation'] = array();
        $session_data['data'] = $placeholder;

        return $session_data;
    }

    /**
     * The internal placeholder is always considered in stock.
     */
    public static function placeholder_is_in_stock( $is_in_stock, $product ) {
        return self::is_placeholder_product( $product ) ? true : $is_in_stock;
    }

    /**
     * The internal placeholder is always purchasable; the original dish's
     * advance-order eligibility is validated before it is added to the cart.
     */
    public static function placeholder_is_purchasable( $purchasable, $product ) {
        return self::is_placeholder_product( $product ) ? true : $purchasable;
    }

    private static function is_placeholder_product( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return false;
        }

        $placeholder_id = absint( get_option( self::OPTION_PLACEHOLDER_ID, 0 ) );
        if ( $placeholder_id && $product->get_id() === $placeholder_id ) {
            return true;
        }

        return 'yes' === get_post_meta( $product->get_id(), '_persiano_advance_placeholder', true );
    }

    public static function render_advance_order_form() {
        global $product;

        if ( ! $product instanceof WC_Product || ! self::product_allows_advance_order( $product->get_id() ) ) {
            return;
        }

        if ( self::product_is_currently_available( $product ) ) {
            return;
        }

        $details      = persiano_hub_get_product_details( $product->get_id() );
        $notice_hours = max( 1, absint( $details['advance_notice_hours'] ?? 24 ) );
        $minimum      = new DateTimeImmutable( '+' . $notice_hours . ' hours', wp_timezone() );
        $minimum_attr = $minimum->format( 'Y-m-d\TH:i' );
        $current_url  = get_permalink( $product->get_id() );
        ?>
        <section class="ph-advance-order" aria-labelledby="ph-advance-order-title">
            <span class="ph-advance-order-eyebrow"><?php esc_html_e( 'Available by advance order', 'persiano-hub' ); ?></span>
            <h3 id="ph-advance-order-title"><?php esc_html_e( 'Order this for a future date', 'persiano-hub' ); ?></h3>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: minimum notice in hours */
                        _n( 'Choose a date and time at least %d hour ahead.', 'Choose a date and time at least %d hours ahead.', $notice_hours, 'persiano-hub' ),
                        $notice_hours
                    )
                );
                ?>
            </p>
            <form class="ph-advance-order-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="persiano_add_advance_order">
                <input type="hidden" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $current_url ); ?>">
                <?php wp_nonce_field( 'persiano_add_advance_order_' . $product->get_id(), 'persiano_advance_order_nonce' ); ?>

                <label>
                    <span><?php esc_html_e( 'Requested date & time', 'persiano-hub' ); ?></span>
                    <input type="datetime-local" name="requested_datetime" min="<?php echo esc_attr( $minimum_attr ); ?>" required>
                </label>
                <label>
                    <span><?php esc_html_e( 'Quantity', 'persiano-hub' ); ?></span>
                    <input type="number" name="quantity" min="1" step="1" value="1" required>
                </label>
                <button type="submit" class="button alt"><?php esc_html_e( 'Add Advance Order to Cart', 'persiano-hub' ); ?></button>
            </form>
            <p class="ph-advance-order-note"><?php esc_html_e( 'Pickup, local delivery or shipping options are shown at checkout according to this item’s fulfilment settings.', 'persiano-hub' ); ?></p>
        </section>
        <?php
    }

    public static function render_card_notice( $product ) {
        if ( ! $product instanceof WC_Product || ! self::product_allows_advance_order( $product->get_id() ) || self::product_is_currently_available( $product ) ) {
            return;
        }

        $details = persiano_hub_get_product_details( $product->get_id() );
        $hours   = max( 1, absint( $details['advance_notice_hours'] ?? 24 ) );
        printf(
            '<div class="ph-advance-card-note">%1$s</div>',
            esc_html(
                sprintf(
                    /* translators: %d: minimum notice in hours */
                    _n( 'Advance orders available with %d hour notice', 'Advance orders available with %d hours’ notice', $hours, 'persiano-hub' ),
                    $hours
                )
            )
        );
    }

    public static function handle_add_to_cart() {
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $nonce      = isset( $_POST['persiano_advance_order_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_advance_order_nonce'] ) ) : '';
        $redirect   = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : get_permalink( $product_id );

        if ( ! $product_id || ! wp_verify_nonce( $nonce, 'persiano_add_advance_order_' . $product_id ) ) {
            wp_die( esc_html__( 'The advance-order form expired. Please go back and try again.', 'persiano-hub' ), 403 );
        }

        if ( function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! WC()->cart ) ) {
            wc_load_cart();
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || ! self::product_allows_advance_order( $product_id ) ) {
            self::redirect_with_notice( $redirect, __( 'Advance ordering is not available for this item.', 'persiano-hub' ), 'error' );
        }

        $requested_raw = isset( $_POST['requested_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['requested_datetime'] ) ) : '';
        $requested     = self::parse_local_datetime( $requested_raw );
        $quantity      = isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1;
        $details       = persiano_hub_get_product_details( $product_id );
        $notice_hours  = max( 1, absint( $details['advance_notice_hours'] ?? 24 ) );
        $minimum       = new DateTimeImmutable( '+' . $notice_hours . ' hours', wp_timezone() );

        if ( ! $requested || $requested < $minimum ) {
            self::redirect_with_notice(
                $redirect,
                sprintf(
                    /* translators: %d: minimum notice in hours */
                    _n( 'Please choose a date at least %d hour from now.', 'Please choose a date at least %d hours from now.', $notice_hours, 'persiano-hub' ),
                    $notice_hours
                ),
                'error'
            );
        }

        $placeholder_id = self::ensure_placeholder_product();
        if ( ! $placeholder_id ) {
            self::redirect_with_notice( $redirect, __( 'We could not start the advance order. Please try again.', 'persiano-hub' ), 'error' );
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            self::redirect_with_notice( $redirect, __( 'Your cart could not be loaded. Please try again.', 'persiano-hub' ), 'error' );
        }

        $cart_item_data = array(
            self::CART_FLAG        => 'yes',
            self::CART_ORIGINAL_ID => $product_id,
            self::CART_REQUESTED_AT => $requested->format( 'Y-m-d\TH:i' ),
        );

        $added = WC()->cart->add_to_cart( $placeholder_id, $quantity, 0, array(), $cart_item_data );

        if ( ! $added ) {
            self::redirect_with_notice( $redirect, __( 'We could not add this advance order to your cart.', 'persiano-hub' ), 'error' );
        }

        wc_add_notice( __( 'Advance order added to your cart.', 'persiano-hub' ), 'success' );
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    public static function apply_advance_order_pricing( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( ! $cart instanceof WC_Cart ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item[ self::CART_FLAG ] ) || empty( $cart_item[ self::CART_ORIGINAL_ID ] ) || empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
                continue;
            }

            $original = wc_get_product( absint( $cart_item[ self::CART_ORIGINAL_ID ] ) );
            if ( ! $original ) {
                continue;
            }

            $cart_item['data']->set_price( (float) $original->get_price() );
            $cart_item['data']->set_tax_status( $original->get_tax_status() );
            $cart_item['data']->set_tax_class( $original->get_tax_class() );
        }
    }

    public static function cart_item_name( $name, $cart_item, $cart_item_key ) {
        $original = self::original_product_from_cart_item( $cart_item );
        if ( ! $original ) {
            return $name;
        }

        return sprintf(
            '<a href="%1$s">%2$s</a> <span class="ph-advance-order-badge">%3$s</span>',
            esc_url( get_permalink( $original->get_id() ) ),
            esc_html( $original->get_name() ),
            esc_html__( 'Advance order', 'persiano-hub' )
        );
    }

    public static function cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
        $original = self::original_product_from_cart_item( $cart_item );
        return $original ? $original->get_image( 'woocommerce_thumbnail' ) : $thumbnail;
    }

    public static function cart_item_permalink( $permalink, $cart_item, $cart_item_key ) {
        $original = self::original_product_from_cart_item( $cart_item );
        return $original ? get_permalink( $original->get_id() ) : $permalink;
    }

    public static function cart_item_data( $item_data, $cart_item ) {
        if ( empty( $cart_item[ self::CART_FLAG ] ) ) {
            return $item_data;
        }

        $requested = self::parse_local_datetime( $cart_item[ self::CART_REQUESTED_AT ] ?? '' );
        if ( $requested ) {
            $item_data[] = array(
                'key'   => __( 'Requested for', 'persiano-hub' ),
                'value' => self::format_datetime( $requested ),
            );
        }

        return $item_data;
    }

    public static function save_order_line_item( $item, $cart_item_key, $values, $order ) {
        if ( empty( $values[ self::CART_FLAG ] ) || empty( $values[ self::CART_ORIGINAL_ID ] ) ) {
            return;
        }

        $original = wc_get_product( absint( $values[ self::CART_ORIGINAL_ID ] ) );
        if ( $original ) {
            $item->set_name( $original->get_name() . ' — ' . __( 'Advance order', 'persiano-hub' ) );
        }

        $requested = self::parse_local_datetime( $values[ self::CART_REQUESTED_AT ] ?? '' );
        if ( $requested ) {
            $item->add_meta_data( __( 'Requested for', 'persiano-hub' ), self::format_datetime( $requested ), true );
        }

        $item->add_meta_data( '_persiano_advance_order', 'yes', true );
        $item->add_meta_data( '_persiano_original_product_id', absint( $values[ self::CART_ORIGINAL_ID ] ), true );
    }

    /**
     * Use the original product image in customer emails without changing the
     * underlying order product. Changing WC_Order_Item_Product::get_product()
     * globally interferes with WooCommerce stock reservation, so display-only
     * filters are used instead.
     */
    public static function order_item_thumbnail( $image, $item ) {
        if ( ! $item instanceof WC_Order_Item_Product || 'yes' !== $item->get_meta( '_persiano_advance_order', true ) ) {
            return $image;
        }

        $original_id = absint( $item->get_meta( '_persiano_original_product_id', true ) );
        $original    = $original_id ? wc_get_product( $original_id ) : false;

        return $original ? $original->get_image( 'woocommerce_thumbnail' ) : $image;
    }

    /**
     * Use the original product thumbnail in the WooCommerce admin order screen.
     */
    public static function admin_order_item_thumbnail( $image, $item_id, $item ) {
        return self::order_item_thumbnail( $image, $item );
    }

    /**
     * Determine whether the active cart contains one or more advance-order items.
     */
    public static function cart_has_advance_order() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( self::is_advance_cart_item( $cart_item ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Advance orders must be confirmed before online payment. Keep manual
     * invoice / bank-transfer / cash-style gateways and remove card wallets
     * such as Square from the first checkout.
     */
    public static function limit_payment_gateways_for_advance_orders( $gateways ) {
        if ( ! self::cart_has_advance_order() ) {
            return $gateways;
        }

        foreach ( $gateways as $gateway_id => $gateway ) {
            if ( ! self::is_manual_confirmation_gateway( $gateway_id, $gateway ) ) {
                unset( $gateways[ $gateway_id ] );
            }
        }

        return $gateways;
    }

    private static function is_manual_confirmation_gateway( $gateway_id, $gateway ) {
        $gateway_id = strtolower( (string) $gateway_id );

        if ( in_array( $gateway_id, array( 'bacs', 'cod', 'cheque' ), true ) ) {
            return true;
        }

        $parts = array( $gateway_id, get_class( $gateway ) );
        if ( is_object( $gateway ) && method_exists( $gateway, 'get_title' ) ) {
            $parts[] = $gateway->get_title();
        }
        if ( is_object( $gateway ) && method_exists( $gateway, 'get_method_title' ) ) {
            $parts[] = $gateway->get_method_title();
        }

        $haystack = strtolower( wp_strip_all_tags( implode( ' ', array_filter( $parts ) ) ) );
        $manual_markers = array(
            'invoice',
            'direct bank',
            'bank transfer',
            'e-transfer',
            'etransfer',
            'cash on delivery',
            'cheque payment',
            'check payment',
        );

        foreach ( $manual_markers as $marker ) {
            if ( false !== strpos( $haystack, $marker ) ) {
                return true;
            }
        }

        return false;
    }

    public static function render_advance_checkout_notice() {
        if ( ! self::cart_has_advance_order() ) {
            return;
        }

        wc_print_notice(
            sprintf( __( 'Advance orders are submitted for confirmation first. No online card payment is collected at this stage. After %s confirms the requested date, you can pay by the agreed method or receive an online payment link.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ),
            'notice'
        );
    }

    /**
     * Mark newly created advance-order checkouts so they are easy to identify
     * and can later be confirmed from the order actions menu.
     */
    public static function mark_order_as_advance_request( $order, $data ) {
        if ( ! $order instanceof WC_Order || ! self::cart_has_advance_order() ) {
            return;
        }

        $order->update_meta_data( '_persiano_advance_order_request', 'yes' );
        $order->update_meta_data( '_persiano_advance_order_confirmed', 'no' );
    }

    /**
     * Add a one-click admin action to confirm an advance order and send the
     * customer WooCommerce's payment-request email with an order-pay link.
     */
    public static function add_confirm_and_request_payment_action( $actions, $order = null ) {
        if ( ! $order instanceof WC_Order ) {
            return $actions;
        }

        if ( 'yes' !== $order->get_meta( '_persiano_advance_order_request', true ) ) {
            return $actions;
        }

        if ( 'yes' === $order->get_meta( '_persiano_advance_order_confirmed', true ) ) {
            return $actions;
        }

        $actions['persiano_confirm_advance_and_request_payment'] = __( 'Confirm advance order & send payment link', 'persiano-hub' );
        return $actions;
    }

    public static function confirm_advance_and_request_payment( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $order->update_meta_data( '_persiano_advance_order_confirmed', 'yes' );

        if ( ! $order->is_paid() ) {
            $order->set_status( 'pending' );
        }

        $order->add_order_note(
            sprintf( __( 'Advance order confirmed by %s. A payment request has been sent to the customer.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ),
            false,
            true
        );
        $order->save();

        if ( function_exists( 'WC' ) && WC()->mailer() ) {
            WC()->payment_gateways();
            WC()->shipping();
            WC()->mailer()->customer_invoice( $order );
        }
    }

    public static function render_order_advance_note( $order ) {
        self::render_advance_summary( $order, false, false );
    }

    public static function render_email_advance_note( $order, $sent_to_admin, $plain_text, $email ) {
        self::render_advance_summary( $order, (bool) $plain_text, (bool) $sent_to_admin );
    }

    private static function render_advance_summary( $order, $plain_text = false, $sent_to_admin = false ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $dates = array();
        foreach ( $order->get_items() as $item ) {
            if ( 'yes' !== $item->get_meta( '_persiano_advance_order', true ) ) {
                continue;
            }
            $requested = $item->get_meta( __( 'Requested for', 'persiano-hub' ), true );
            if ( $requested ) {
                $dates[] = $item->get_name() . ': ' . $requested;
            }
        }

        if ( empty( $dates ) ) {
            return;
        }

        $is_unconfirmed = class_exists( 'Persiano_Hub_Email_Branding' )
            && Persiano_Hub_Email_Branding::is_unconfirmed_advance_order( $order );

        if ( $sent_to_admin ) {
            $title = __( 'Advance order details', 'persiano-hub' );
            $text  = $is_unconfirmed
                ? sprintf( __( 'This advance-order request is awaiting confirmation from %s.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) )
                : __( 'This order includes one or more items requested for a future date.', 'persiano-hub' );
        } else {
            $title = $is_unconfirmed ? __( 'Your advance order request', 'persiano-hub' ) : __( 'Your advance order', 'persiano-hub' );
            $text  = $is_unconfirmed
                ? sprintf( __( 'We have recorded your requested date. This request is awaiting confirmation from %s.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) )
                : __( 'We have recorded the requested date shown below. Your order will follow the fulfilment method selected at checkout.', 'persiano-hub' );
        }

        if ( $plain_text ) {
            echo "\n" . $title . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $text . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            foreach ( $dates as $line ) {
                echo wp_strip_all_tags( $line ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            return;
        }

        echo '<section style="margin:24px 0;padding:20px 22px;border-radius:16px;background:#f8f3e9;border:1px solid #e5dfd5;">';
        echo '<h2 style="margin:0 0 8px;color:#2f231d;font-family:Georgia,serif;">' . esc_html( $title ) . '</h2>';
        echo '<p style="margin:0 0 12px;color:#6f6258;">' . esc_html( $text ) . '</p><ul style="margin:0;padding-left:20px;">';
        foreach ( $dates as $line ) {
            echo '<li style="margin:4px 0;color:#2f231d;">' . esc_html( $line ) . '</li>';
        }
        echo '</ul></section>';
    }

    public static function is_advance_cart_item( $cart_item ) {
        return ! empty( $cart_item[ self::CART_FLAG ] ) && ! empty( $cart_item[ self::CART_ORIGINAL_ID ] );
    }

    public static function original_product_id_from_cart_item( $cart_item ) {
        return self::is_advance_cart_item( $cart_item ) ? absint( $cart_item[ self::CART_ORIGINAL_ID ] ) : 0;
    }

    private static function original_product_from_cart_item( $cart_item ) {
        $product_id = self::original_product_id_from_cart_item( $cart_item );
        return $product_id ? wc_get_product( $product_id ) : false;
    }

    private static function product_allows_advance_order( $product_id ) {
        if ( ! function_exists( 'persiano_hub_get_product_details' ) ) {
            return false;
        }
        $details = persiano_hub_get_product_details( $product_id );
        return ! empty( $details['allow_advance_order'] );
    }

    private static function product_is_currently_available( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return false;
        }
        return $product->is_in_stock() && $product->is_purchasable();
    }

    private static function parse_local_datetime( $value ) {
        $value = sanitize_text_field( (string) $value );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ) {
            return null;
        }
        try {
            return new DateTimeImmutable( $value, wp_timezone() );
        } catch ( Exception $e ) {
            return null;
        }
    }

    private static function format_datetime( DateTimeInterface $date ) {
        return wp_date( 'D, M j · g:i a', $date->getTimestamp(), wp_timezone() );
    }

    private static function redirect_with_notice( $redirect, $message, $type = 'error' ) {
        if ( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( $message, $type );
        }
        wp_safe_redirect( $redirect ?: home_url( '/' ) );
        exit;
    }
}
