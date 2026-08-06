<?php
/**
 * Batchly workspace, availability model and spreadsheet-style product editor.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Workspace {
    const PAGE_PRODUCTS = 'persiano-hub-products';
    const PAGE_ORDERS   = 'persiano-hub-orders';
    const META_AVAILABILITY = '_persiano_availability';
    const META_RETURN_DATE  = '_persiano_return_date';
    const META_UNAVAILABLE_REASON = '_persiano_unavailable_reason';
    const META_ADVANCE_MIN_QTY = '_persiano_advance_min_qty';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_pages' ), 70 );
        // Menu order is managed by Persiano_Hub_Architecture.
        add_action( 'admin_post_persiano_bulk_save_products', array( __CLASS__, 'save_products' ) );

        add_filter( 'woocommerce_product_backorders_allowed', '__return_false', 999 );
        add_filter( 'woocommerce_product_get_backorders', array( __CLASS__, 'disable_backorders_value' ), 999 );
        add_filter( 'woocommerce_product_variation_get_backorders', array( __CLASS__, 'disable_backorders_value' ), 999 );
        add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'availability_purchasable' ), 999, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'availability_purchasable' ), 999, 2 );
        add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'availability_text' ), 999, 2 );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'force_backorders_off' ), 999 );

        add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'render_availability_field' ), 5 );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_availability_field' ), 25 );
        add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'render_badge_loop' ), 4 );
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_badge_single' ), 7 );
        add_action( 'admin_head', array( __CLASS__, 'admin_styles' ) );
        add_action( 'wp_head', array( __CLASS__, 'frontend_styles' ) );
    }

    public static function options() {
        return array(
            'this_week'    => __( 'This Week', 'persiano-hub' ),
            'available'    => __( 'Available Now', 'persiano-hub' ),
            'advance'      => __( 'Advance Order', 'persiano-hub' ),
            'unavailable'  => __( 'Unavailable', 'persiano-hub' ),
        );
    }

    public static function register_pages() {
        add_submenu_page(
            'persiano-hub',
            __( 'Products & This Week', 'persiano-hub' ),
            __( 'Products & This Week', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE_PRODUCTS,
            array( __CLASS__, 'render_products' )
        );
        add_submenu_page(
            'persiano-hub',
            __( 'Order Workspace', 'persiano-hub' ),
            __( 'Orders', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE_ORDERS,
            array( __CLASS__, 'render_orders' )
        );
    }

    public static function organize_menu() {
        global $submenu;
        if ( empty( $submenu['persiano-hub'] ) || ! is_array( $submenu['persiano-hub'] ) ) {
            return;
        }
        $labels = array(
            'persiano-hub'                    => 'Dashboard',
            self::PAGE_ORDERS                  => 'Orders',
            'persiano-hub-loss-waste'          => 'Loss & Waste',
            'persiano-manual-order'            => 'New Manual Order',
            'persiano-hub-advance-orders'      => 'Advance Orders',
            self::PAGE_PRODUCTS                => 'Products & This Week',
            'persiano-hub-labels'              => 'Labels & Printing',
            'persiano-hub-fulfilment'          => 'Fulfilment',
            'persiano-hub-loyalty-admin'       => 'Customers & Loyalty',
            'persiano-hub-website-content'     => 'Website Content',
            'persiano-hub-mailing-list'        => 'Mailing List',
            'persiano-hub-marketing'           => 'Marketing',
            'persiano-hub-events'              => 'Event RSVPs',
            'persiano-hub-notifications'       => 'Notifications',
            'persiano-hub-connections'         => 'Connections',
            'persiano-hub-maintenance'          => 'Maintenance',
            'persiano-hub-updates'             => 'Updates',
        );
        $priority = array_keys( $labels );
        foreach ( $submenu['persiano-hub'] as &$item ) {
            $slug = isset( $item[2] ) ? (string) $item[2] : '';
            if ( isset( $labels[ $slug ] ) ) {
                $item[0] = $labels[ $slug ];
            }
        }
        unset( $item );
        usort(
            $submenu['persiano-hub'],
            static function( $a, $b ) use ( $priority ) {
                $ai = array_search( isset( $a[2] ) ? $a[2] : '', $priority, true );
                $bi = array_search( isset( $b[2] ) ? $b[2] : '', $priority, true );
                $ai = false === $ai ? 999 : $ai;
                $bi = false === $bi ? 999 : $bi;
                return $ai <=> $bi;
            }
        );
    }

    public static function disable_backorders_value() {
        return 'no';
    }

    public static function force_backorders_off( $product ) {
        if ( $product instanceof WC_Product ) {
            $product->set_backorders( 'no' );
        }
    }

    public static function get_availability( $product_id ) {
        $value = sanitize_key( (string) get_post_meta( $product_id, self::META_AVAILABILITY, true ) );
        if ( isset( self::options()[ $value ] ) ) {
            return $value;
        }
        if ( 'yes' === get_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_THIS_WEEK, true ) ) {
            return 'this_week';
        }
        if ( 'yes' === get_post_meta( $product_id, Persiano_Hub_Product_Fields::META_ALLOW_ADVANCE, true ) ) {
            return 'advance';
        }
        $product = wc_get_product( $product_id );
        return $product && ! $product->is_in_stock() ? 'unavailable' : 'available';
    }

    public static function sync_availability( $product_id, $availability ) {
        $availability = isset( self::options()[ $availability ] ) ? $availability : 'available';
        update_post_meta( $product_id, self::META_AVAILABILITY, $availability );
        update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_THIS_WEEK, 'this_week' === $availability ? 'yes' : 'no' );
        update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_ALLOW_ADVANCE, 'advance' === $availability ? 'yes' : 'no' );

        $product = wc_get_product( $product_id );
        if ( $product ) {
            $product->set_backorders( 'no' );
            if ( 'unavailable' === $availability ) {
                $product->set_stock_status( 'outofstock' );
            } elseif ( ! $product->is_in_stock() ) {
                $product->set_stock_status( 'instock' );
            }
            $product->save();
        }
    }

    public static function availability_purchasable( $purchasable, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $purchasable;
        }
        $status = self::get_availability( $product->get_id() );
        if ( in_array( $status, array( 'advance', 'unavailable' ), true ) ) {
            return false;
        }
        return $purchasable;
    }

    public static function availability_text( $text, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $text;
        }
        $status = self::get_availability( $product->get_id() );
        if ( 'this_week' === $status ) {
            return __( 'Available this week', 'persiano-hub' );
        }
        if ( 'available' === $status ) {
            return __( 'Available now', 'persiano-hub' );
        }
        if ( 'advance' === $status ) {
            return __( 'Available by advance order', 'persiano-hub' );
        }
        $reason = trim( (string) get_post_meta( $product->get_id(), self::META_UNAVAILABLE_REASON, true ) );
        return $reason ? $reason : __( 'Currently unavailable', 'persiano-hub' );
    }

    public static function render_availability_field() {
        global $post;
        $id = $post ? absint( $post->ID ) : 0;
        woocommerce_wp_select(
            array(
                'id'          => self::META_AVAILABILITY,
                'label'       => __( 'Persiano availability', 'persiano-hub' ),
                'description' => __( 'Controls the customer-facing state. WooCommerce backorders are disabled.', 'persiano-hub' ),
                'desc_tip'    => true,
                'options'     => self::options(),
                'value'       => self::get_availability( $id ),
            )
        );
        woocommerce_wp_text_input(
            array(
                'id'          => self::META_RETURN_DATE,
                'label'       => __( 'Seasonal return date', 'persiano-hub' ),
                'type'        => 'date',
                'value'       => get_post_meta( $id, self::META_RETURN_DATE, true ),
            )
        );
        woocommerce_wp_text_input(
            array(
                'id'          => self::META_UNAVAILABLE_REASON,
                'label'       => __( 'Unavailable message', 'persiano-hub' ),
                'placeholder' => __( 'Seasonally unavailable', 'persiano-hub' ),
                'value'       => get_post_meta( $id, self::META_UNAVAILABLE_REASON, true ),
            )
        );
        woocommerce_wp_text_input(
            array(
                'id'                => self::META_ADVANCE_MIN_QTY,
                'label'             => __( 'Advance-order minimum quantity', 'persiano-hub' ),
                'type'              => 'number',
                'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
                'value'             => max( 1, absint( get_post_meta( $id, self::META_ADVANCE_MIN_QTY, true ) ) ),
            )
        );
    }

    public static function save_availability_field( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return;
        }
        $availability = isset( $_POST[ self::META_AVAILABILITY ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_AVAILABILITY ] ) ) : self::get_availability( $product->get_id() );
        if ( ! isset( self::options()[ $availability ] ) ) {
            $availability = 'available';
        }
        $product->update_meta_data( self::META_AVAILABILITY, $availability );
        $product->update_meta_data( Persiano_Hub_Product_Fields::META_SHOW_THIS_WEEK, 'this_week' === $availability ? 'yes' : 'no' );
        $product->update_meta_data( Persiano_Hub_Product_Fields::META_ALLOW_ADVANCE, 'advance' === $availability ? 'yes' : 'no' );
        $product->update_meta_data( self::META_RETURN_DATE, isset( $_POST[ self::META_RETURN_DATE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_RETURN_DATE ] ) ) : '' );
        $product->update_meta_data( self::META_UNAVAILABLE_REASON, isset( $_POST[ self::META_UNAVAILABLE_REASON ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_UNAVAILABLE_REASON ] ) ) : '' );
        $product->update_meta_data( self::META_ADVANCE_MIN_QTY, max( 1, isset( $_POST[ self::META_ADVANCE_MIN_QTY ] ) ? absint( $_POST[ self::META_ADVANCE_MIN_QTY ] ) : 1 ) );
        $product->set_backorders( 'no' );
        if ( 'unavailable' === $availability ) {
            $product->set_stock_status( 'outofstock' );
        } elseif ( ! $product->is_in_stock() ) {
            $product->set_stock_status( 'instock' );
        }
    }

    private static function render_badge( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return;
        }
        $status = self::get_availability( $product->get_id() );
        $options = self::options();
        echo '<span class="ph-availability-badge ph-availability-' . esc_attr( $status ) . '">' . esc_html( $options[ $status ] ) . '</span>';
    }

    public static function render_badge_loop() {
        global $product;
        self::render_badge( $product );
    }

    public static function render_badge_single() {
        global $product;
        self::render_badge( $product );
    }

    public static function render_products() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage products.', 'persiano-hub' ) );
        }
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $args = array(
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
            's'              => $search,
        );
        if ( in_array( $view, array_keys( self::options() ), true ) ) {
            $args['meta_query'] = array(
                array(
                    'key'   => self::META_AVAILABILITY,
                    'value' => $view,
                ),
            );
        }
        $products = get_posts( $args );
        $saved = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : 0;
        ?>
        <div class="wrap ph-workspace">
            <h1><?php esc_html_e( 'Products & This Week', 'persiano-hub' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Edit the fields you use most without opening every product. Changes are saved through WooCommerce, not by direct database editing.', 'persiano-hub' ); ?></p>
            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( _n( '%d product updated.', '%d products updated.', $saved, 'persiano-hub' ), $saved ) ); ?></p></div><?php endif; ?>
            <nav class="nav-tab-wrapper">
                <?php
                $tabs = array( 'all' => __( 'All Products', 'persiano-hub' ) ) + self::options();
                foreach ( $tabs as $key => $label ) {
                    $url = add_query_arg( array( 'page' => self::PAGE_PRODUCTS, 'view' => $key ), admin_url( 'admin.php' ) );
                    echo '<a class="nav-tab ' . ( $view === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
                }
                ?>
            </nav>
            <form method="get" class="ph-workspace-search"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_PRODUCTS ); ?>"><input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>"><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search products"><button class="button">Search</button></form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="persiano_bulk_save_products">
                <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
                <?php wp_nonce_field( 'persiano_bulk_save_products' ); ?>
                <div class="ph-grid-wrap"><table class="widefat striped ph-edit-grid">
                    <thead><tr><th><?php esc_html_e( 'Product', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Availability', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Regular price', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Sale price', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Stock', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Order deadline', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Fulfilment date', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Advance notice (h)', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Min qty', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Return date', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Published', 'persiano-hub' ); ?></th></tr></thead>
                    <tbody>
                    <?php if ( ! $products ) : ?><tr><td colspan="11"><?php esc_html_e( 'No products found.', 'persiano-hub' ); ?></td></tr><?php endif; ?>
                    <?php foreach ( $products as $post ) : $product = wc_get_product( $post->ID ); if ( ! $product ) { continue; } ?>
                        <tr>
                            <td class="ph-product-cell"><input type="hidden" name="products[<?php echo esc_attr( $post->ID ); ?>][id]" value="<?php echo esc_attr( $post->ID ); ?>"><strong><?php echo esc_html( $product->get_name() ); ?></strong><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" target="_blank"><?php esc_html_e( 'Open', 'persiano-hub' ); ?></a></td>
                            <td><select name="products[<?php echo esc_attr( $post->ID ); ?>][availability]" class="ph-availability-select"><?php foreach ( self::options() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( self::get_availability( $post->ID ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
                            <td><input type="number" min="0" step="0.01" name="products[<?php echo esc_attr( $post->ID ); ?>][regular_price]" value="<?php echo esc_attr( $product->get_regular_price() ); ?>"></td>
                            <td><input type="number" min="0" step="0.01" name="products[<?php echo esc_attr( $post->ID ); ?>][sale_price]" value="<?php echo esc_attr( $product->get_sale_price() ); ?>"></td>
                            <td><input type="number" min="0" step="1" name="products[<?php echo esc_attr( $post->ID ); ?>][stock]" value="<?php echo esc_attr( null === $product->get_stock_quantity() ? '' : $product->get_stock_quantity() ); ?>"></td>
                            <td><input type="datetime-local" name="products[<?php echo esc_attr( $post->ID ); ?>][deadline]" value="<?php echo esc_attr( self::datetime_local( get_post_meta( $post->ID, Persiano_Hub_Product_Fields::META_ORDER_DEADLINE, true ) ) ); ?>"></td>
                            <td><input type="date" name="products[<?php echo esc_attr( $post->ID ); ?>][available_date]" value="<?php echo esc_attr( get_post_meta( $post->ID, Persiano_Hub_Product_Fields::META_AVAILABLE_DATE, true ) ); ?>"></td>
                            <td><input type="number" min="0" step="1" name="products[<?php echo esc_attr( $post->ID ); ?>][advance_hours]" value="<?php echo esc_attr( absint( get_post_meta( $post->ID, Persiano_Hub_Product_Fields::META_ADVANCE_HOURS, true ) ) ); ?>"></td>
                            <td><input type="number" min="1" step="1" name="products[<?php echo esc_attr( $post->ID ); ?>][min_qty]" value="<?php echo esc_attr( max( 1, absint( get_post_meta( $post->ID, self::META_ADVANCE_MIN_QTY, true ) ) ) ); ?>"></td>
                            <td><input type="date" name="products[<?php echo esc_attr( $post->ID ); ?>][return_date]" value="<?php echo esc_attr( get_post_meta( $post->ID, self::META_RETURN_DATE, true ) ); ?>"></td>
                            <td><label><input type="checkbox" name="products[<?php echo esc_attr( $post->ID ); ?>][published]" value="1" <?php checked( 'publish', $post->post_status ); ?>> <?php esc_html_e( 'Yes', 'persiano-hub' ); ?></label></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <div class="ph-save-bar"><span><?php esc_html_e( 'Only rows with changed values are updated.', 'persiano-hub' ); ?></span><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save product changes', 'persiano-hub' ); ?></button></div>
            </form>
        </div>
        <?php
    }

    private static function datetime_local( $value ) {
        if ( ! $value ) {
            return '';
        }
        $time = strtotime( $value );
        return $time ? wp_date( 'Y-m-d\TH:i', $time ) : '';
    }

    public static function save_products() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage products.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_bulk_save_products' );
        $rows = isset( $_POST['products'] ) && is_array( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : array();
        $changed = 0;
        foreach ( $rows as $product_id => $row ) {
            $product_id = absint( $product_id );
            $product = wc_get_product( $product_id );
            if ( ! $product || ! current_user_can( 'edit_post', $product_id ) ) {
                continue;
            }
            $availability = isset( $row['availability'] ) ? sanitize_key( $row['availability'] ) : self::get_availability( $product_id );
            $product->set_regular_price( isset( $row['regular_price'] ) ? wc_format_decimal( $row['regular_price'] ) : $product->get_regular_price() );
            $product->set_sale_price( isset( $row['sale_price'] ) && '' !== $row['sale_price'] ? wc_format_decimal( $row['sale_price'] ) : '' );
            if ( isset( $row['stock'] ) && '' !== $row['stock'] ) {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( max( 0, absint( $row['stock'] ) ) );
            }
            $product->set_backorders( 'no' );
            $product->set_status( ! empty( $row['published'] ) ? 'publish' : 'draft' );
            $product->update_meta_data( self::META_AVAILABILITY, $availability );
            $product->update_meta_data( Persiano_Hub_Product_Fields::META_SHOW_THIS_WEEK, 'this_week' === $availability ? 'yes' : 'no' );
            $product->update_meta_data( Persiano_Hub_Product_Fields::META_ALLOW_ADVANCE, 'advance' === $availability ? 'yes' : 'no' );
            $product->update_meta_data( Persiano_Hub_Product_Fields::META_ORDER_DEADLINE, isset( $row['deadline'] ) ? sanitize_text_field( $row['deadline'] ) : '' );
            $product->update_meta_data( Persiano_Hub_Product_Fields::META_AVAILABLE_DATE, isset( $row['available_date'] ) ? sanitize_text_field( $row['available_date'] ) : '' );
            $product->update_meta_data( Persiano_Hub_Product_Fields::META_ADVANCE_HOURS, isset( $row['advance_hours'] ) ? absint( $row['advance_hours'] ) : 0 );
            $product->update_meta_data( self::META_ADVANCE_MIN_QTY, isset( $row['min_qty'] ) ? max( 1, absint( $row['min_qty'] ) ) : 1 );
            $product->update_meta_data( self::META_RETURN_DATE, isset( $row['return_date'] ) ? sanitize_text_field( $row['return_date'] ) : '' );
            if ( 'unavailable' === $availability ) {
                $product->set_stock_status( 'outofstock' );
            } elseif ( $product->get_manage_stock() && $product->get_stock_quantity() <= 0 ) {
                $product->set_stock_status( 'outofstock' );
            } else {
                $product->set_stock_status( 'instock' );
            }
            $product->save();
            $changed++;
        }
        $view = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'all';
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_PRODUCTS, 'view' => $view, 'saved' => $changed ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render_orders() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage orders.', 'persiano-hub' ) );
        }
        $orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 30, 'orderby' => 'date', 'order' => 'DESC', 'status' => array_keys( wc_get_order_statuses() ) ) );
        $orders = array_values( array_filter( $orders, static function( $candidate ) {
            return $candidate instanceof WC_Order && ! $candidate instanceof WC_Order_Refund;
        } ) );
        ?>
        <div class="wrap ph-workspace"><h1><?php esc_html_e( 'Order Workspace', 'persiano-hub' ); ?></h1>
        <div class="ph-quick-actions"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=persiano-manual-order' ) ); ?>"><?php esc_html_e( 'Create manual order', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=persiano-hub-advance-orders' ) ); ?>"><?php esc_html_e( 'Advance orders', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=persiano-hub-labels' ) ); ?>"><?php esc_html_e( 'Print labels', 'persiano-hub' ); ?></a></div>
        <table class="widefat striped ph-orders-table"><thead><tr><th><?php esc_html_e( 'Order', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Customer', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Status', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Fulfilment', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Payment', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Total', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Actions', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php foreach ( $orders as $order ) : $edit = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ); ?>
        <tr><td><strong><a href="<?php echo esc_url( $edit ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></strong><br><small><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'M j, Y g:i a' ) : '' ); ?></small></td><td><?php echo esc_html( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ); ?></td><td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td><td><?php echo esc_html( $order->get_meta( '_persiano_fulfilment_method' ) ?: '—' ); ?></td><td><?php echo $order->is_paid() ? esc_html__( 'Paid', 'persiano-hub' ) : esc_html__( 'Payment due', 'persiano-hub' ); ?></td><td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td><td><a class="button button-small" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Open', 'persiano-hub' ); ?></a><?php if ( ! $order->is_paid() && $order->needs_payment() ) : ?> <a class="button button-small" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" target="_blank"><?php esc_html_e( 'Payment link', 'persiano-hub' ); ?></a><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?php
    }

    public static function admin_styles() {
        $screen = get_current_screen();
        if ( ! $screen || false === strpos( $screen->id, 'persiano-hub' ) ) {
            return;
        }
        echo '<style>.ph-workspace{max-width:1600px}.ph-workspace .description{font-size:14px}.ph-workspace-search{display:flex;gap:8px;margin:16px 0}.ph-workspace-search input{min-width:280px}.ph-grid-wrap{overflow:auto;background:#fff;border:1px solid #c3c4c7}.ph-edit-grid{min-width:1550px;border:0}.ph-edit-grid th{position:static;background:#f6f7f7;white-space:nowrap}.ph-edit-grid input,.ph-edit-grid select{width:100%;min-width:105px}.ph-edit-grid input[type=checkbox]{width:auto;min-width:0}.ph-product-cell{min-width:210px}.ph-product-cell strong{display:block}.ph-product-cell a{font-size:12px}.ph-save-bar{position:sticky;bottom:0;display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;box-shadow:0 -3px 12px rgba(0,0,0,.08);z-index:4}.ph-quick-actions{display:flex;gap:8px;margin:14px 0}.ph-orders-table td{vertical-align:middle}@media(max-width:782px){.ph-save-bar{align-items:flex-start;gap:8px;flex-direction:column}}</style>';
    }

    public static function frontend_styles() {
        echo '<style>.ph-availability-badge{display:inline-flex;align-items:center;padding:.32rem .65rem;border-radius:999px;font-size:.78rem;font-weight:700;letter-spacing:.02em;margin:.25rem 0 .45rem;background:#f3eee7;color:#5f2432}.ph-availability-this_week{background:#5f2432;color:#fff}.ph-availability-available{background:#edf6ef;color:#215c35}.ph-availability-advance{background:#fff3d6;color:#704d00}.ph-availability-unavailable{background:#f1f1f1;color:#666}</style>';
    }
}
