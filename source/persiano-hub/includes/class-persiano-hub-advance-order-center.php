<?php
/**
 * Advance Order Center: statuses, admin actions and customer correspondence.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Advance_Order_Center {
    const META_STATUS   = '_persiano_advance_status';
    const META_MESSAGES = '_persiano_advance_messages';
    const META_TOKEN    = '_persiano_advance_portal_token';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 36 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_order_meta_box' ) );
        add_action( 'admin_post_persiano_advance_admin_action', array( __CLASS__, 'handle_admin_action' ) );
        add_action( 'admin_post_nopriv_persiano_advance_portal', array( __CLASS__, 'render_customer_portal' ) );
        add_action( 'admin_post_persiano_advance_portal', array( __CLASS__, 'render_customer_portal' ) );
        add_action( 'admin_post_nopriv_persiano_advance_customer_action', array( __CLASS__, 'handle_customer_action' ) );
        add_action( 'admin_post_persiano_advance_customer_action', array( __CLASS__, 'handle_customer_action' ) );

        add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'initialize_order' ), 30 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'initialize_order' ), 30 );
        add_action( 'woocommerce_order_action_persiano_confirm_advance_and_request_payment', array( __CLASS__, 'sync_confirmed_status' ), 30 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'sync_order_status' ), 30, 4 );
    }

    public static function statuses() {
        return array(
            'request_received'            => __( 'Request received', 'persiano-hub' ),
            'needs_clarification'         => __( 'Needs clarification', 'persiano-hub' ),
            'amended_waiting_customer'    => __( 'Amended — awaiting customer approval', 'persiano-hub' ),
            'confirmed_payment_pending'   => __( 'Confirmed — payment pending', 'persiano-hub' ),
            'paid_processing'             => __( 'Paid / processing', 'persiano-hub' ),
            'rejected'                    => __( 'Rejected', 'persiano-hub' ),
            'cancelled'                   => __( 'Cancelled', 'persiano-hub' ),
            'expired'                     => __( 'Expired', 'persiano-hub' ),
        );
    }

    private static function clean_status( $status ) {
        $status = sanitize_key( (string) $status );
        return isset( self::statuses()[ $status ] ) ? $status : 'request_received';
    }

    public static function is_advance_order( $order ) {
        return $order instanceof WC_Order && 'yes' === $order->get_meta( '_persiano_advance_order_request', true );
    }

    public static function initialize_order( $order ) {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( $order );
        }
        if ( ! self::is_advance_order( $order ) ) {
            return;
        }
        if ( ! $order->get_meta( self::META_STATUS, true ) ) {
            $order->update_meta_data( self::META_STATUS, 'request_received' );
        }
        self::ensure_portal_token( $order, false );
        $messages = $order->get_meta( self::META_MESSAGES, true );
        if ( ! is_array( $messages ) || empty( $messages ) ) {
            self::append_message( $order, __( 'Advance order request received.', 'persiano-hub' ), 'system', 'customer', false );
        }
        $order->save();
    }

    public static function sync_confirmed_status( $order ) {
        if ( ! self::is_advance_order( $order ) ) {
            return;
        }
        $order->update_meta_data( self::META_STATUS, 'confirmed_payment_pending' );
        $order->save();
    }

    public static function sync_order_status( $order_id, $old_status, $new_status, $order ) {
        if ( ! self::is_advance_order( $order ) ) {
            return;
        }
        if ( in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
            $order->update_meta_data( self::META_STATUS, 'paid_processing' );
            $order->save();
        } elseif ( 'cancelled' === $new_status && 'rejected' !== $order->get_meta( self::META_STATUS, true ) ) {
            $order->update_meta_data( self::META_STATUS, 'cancelled' );
            $order->save();
        }
    }

    public static function admin_menu() {
        $count = self::waiting_on_persiano_count();
        $label = __( 'Advance Orders', 'persiano-hub' );
        if ( $count ) {
            $label .= ' <span class="awaiting-mod count-' . absint( $count ) . '"><span class="pending-count">' . absint( $count ) . '</span></span>';
        }
        add_submenu_page(
            'persiano-hub',
            __( 'Advance Orders', 'persiano-hub' ),
            $label,
            'manage_woocommerce',
            'persiano-hub-advance-orders',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    private static function all_advance_orders( $limit = 250 ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }
        $orders = wc_get_orders(
            array(
                'limit'   => max( 1, absint( $limit ) ),
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
            )
        );
        return array_values(
            array_filter(
                $orders,
                function( $order ) {
                    return self::is_advance_order( $order );
                }
            )
        );
    }

    private static function waiting_on_persiano_count() {
        $count = 0;
        foreach ( self::all_advance_orders( 150 ) as $order ) {
            if ( 'persiano' === self::waiting_on( $order ) ) {
                $count++;
            }
        }
        return $count;
    }

    public static function waiting_on( $order ) {
        if ( ! self::is_advance_order( $order ) ) {
            return '';
        }
        $status = self::current_status( $order );
        if ( in_array( $status, array( 'request_received' ), true ) ) {
            return 'persiano';
        }
        if ( in_array( $status, array( 'needs_clarification', 'amended_waiting_customer', 'confirmed_payment_pending' ), true ) ) {
            return 'customer';
        }
        return '';
    }

    public static function current_status( $order ) {
        if ( ! self::is_advance_order( $order ) ) {
            return '';
        }
        if ( $order->is_paid() && ! in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
            return 'paid_processing';
        }
        $stored = $order->get_meta( self::META_STATUS, true );
        if ( $stored ) {
            return self::clean_status( $stored );
        }
        if ( 'yes' === $order->get_meta( '_persiano_advance_order_confirmed', true ) ) {
            return 'confirmed_payment_pending';
        }
        return 'request_received';
    }

    private static function status_label( $order ) {
        $status = self::current_status( $order );
        $labels = self::statuses();
        return isset( $labels[ $status ] ) ? $labels[ $status ] : ucwords( str_replace( '_', ' ', $status ) );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage advance orders.', 'persiano-hub' ) );
        }

        $status_filter = isset( $_GET['advance_status'] ) ? sanitize_key( wp_unslash( $_GET['advance_status'] ) ) : '';
        $waiting_filter = isset( $_GET['waiting'] ) ? sanitize_key( wp_unslash( $_GET['waiting'] ) ) : '';
        $orders = self::all_advance_orders();
        if ( $status_filter ) {
            $orders = array_values( array_filter( $orders, function( $order ) use ( $status_filter ) { return self::current_status( $order ) === $status_filter; } ) );
        }
        if ( $waiting_filter ) {
            $orders = array_values( array_filter( $orders, function( $order ) use ( $waiting_filter ) { return self::waiting_on( $order ) === $waiting_filter; } ) );
        }

        $per_page = 25;
        $page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
        $total = count( $orders );
        $page_orders = array_slice( $orders, ( $page - 1 ) * $per_page, $per_page );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Advance Orders', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'Accept, amend, clarify or reject advance-order requests and keep customer correspondence attached to the order.', 'persiano-hub' ); ?></p>
            <form method="get" style="margin:18px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="page" value="persiano-hub-advance-orders">
                <select name="advance_status">
                    <option value=""><?php esc_html_e( 'All statuses', 'persiano-hub' ); ?></option>
                    <?php foreach ( self::statuses() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status_filter, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="waiting">
                    <option value=""><?php esc_html_e( 'Waiting on anyone', 'persiano-hub' ); ?></option>
                    <option value="persiano" <?php selected( $waiting_filter, 'persiano' ); ?>><?php esc_html_e( 'Waiting on Persiano', 'persiano-hub' ); ?></option>
                    <option value="customer" <?php selected( $waiting_filter, 'customer' ); ?>><?php esc_html_e( 'Waiting on customer', 'persiano-hub' ); ?></option>
                </select>
                <button class="button"><?php esc_html_e( 'Filter', 'persiano-hub' ); ?></button>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=persiano-hub-advance-orders' ) ); ?>"><?php esc_html_e( 'Reset', 'persiano-hub' ); ?></a>
            </form>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Order', 'persiano-hub' ); ?></th>
                    <th><?php esc_html_e( 'Customer', 'persiano-hub' ); ?></th>
                    <th><?php esc_html_e( 'Requested items', 'persiano-hub' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'persiano-hub' ); ?></th>
                    <th><?php esc_html_e( 'Waiting on', 'persiano-hub' ); ?></th>
                    <th><?php esc_html_e( 'Total', 'persiano-hub' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'persiano-hub' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( ! $page_orders ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No advance orders found.', 'persiano-hub' ); ?></td></tr>
                <?php else : foreach ( $page_orders as $order ) :
                    $edit_url = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
                    $waiting = self::waiting_on( $order );
                    $accept_url = self::admin_quick_action_url( $order, 'accept' );
                    $reject_url = self::admin_quick_action_url( $order, 'reject' );
                    ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url( $edit_url ); ?>#persiano_hub_advance_center">#<?php echo esc_html( $order->get_order_number() ); ?></a></strong><br><small><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'M j, Y g:i a' ) : '' ); ?></small></td>
                        <td><?php echo esc_html( trim( $order->get_formatted_billing_full_name() ) ?: $order->get_billing_email() ); ?><br><small><?php echo esc_html( $order->get_billing_email() ); ?></small></td>
                        <td><?php echo wp_kses_post( self::requested_items_html( $order, true ) ); ?></td>
                        <td><span class="ph-advance-badge ph-advance-badge--<?php echo esc_attr( self::current_status( $order ) ); ?>"><?php echo esc_html( self::status_label( $order ) ); ?></span></td>
                        <td><?php echo $waiting ? esc_html( 'persiano' === $waiting ? __( 'Persiano', 'persiano-hub' ) : __( 'Customer', 'persiano-hub' ) ) : '—'; ?></td>
                        <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>#persiano_hub_advance_center"><?php esc_html_e( 'Open', 'persiano-hub' ); ?></a>
                            <?php if ( 'persiano' === $waiting ) : ?>
                                <a class="button button-small button-primary" href="<?php echo esc_url( $accept_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Accept this advance order and send the payment request?', 'persiano-hub' ) ); ?>')"><?php esc_html_e( 'Accept', 'persiano-hub' ); ?></a>
                                <a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>#persiano_hub_advance_center"><?php esc_html_e( 'Message / Amend', 'persiano-hub' ); ?></a>
                                <a class="button button-small" href="<?php echo esc_url( $reject_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Reject this request with the default message?', 'persiano-hub' ) ); ?>')"><?php esc_html_e( 'Reject', 'persiano-hub' ); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <?php
            $pages = (int) ceil( $total / $per_page );
            if ( $pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) ) . '</div></div>';
            }
            ?>
        </div>
        <style>
            .ph-advance-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#f0f0f1;font-weight:600;font-size:12px;line-height:1.3}
            .ph-advance-badge--request_received{background:#fff3cd;color:#664d03}.ph-advance-badge--needs_clarification,.ph-advance-badge--amended_waiting_customer{background:#e8f2ff;color:#174a7e}.ph-advance-badge--confirmed_payment_pending{background:#ede7f6;color:#5e357a}.ph-advance-badge--paid_processing{background:#e5f5e8;color:#1f6a37}.ph-advance-badge--rejected,.ph-advance-badge--cancelled{background:#fbeaea;color:#8a2424}
        </style>
        <?php
    }

    private static function admin_quick_action_url( $order, $type ) {
        $url = add_query_arg(
            array(
                'action' => 'persiano_advance_admin_action',
                'order_id' => $order->get_id(),
                'persiano_advance_admin_action_type' => $type,
                'redirect_to' => admin_url( 'admin.php?page=persiano-hub-advance-orders' ),
            ),
            admin_url( 'admin-post.php' )
        );
        return wp_nonce_url( $url, 'persiano_advance_admin_action_' . $order->get_id() );
    }

    public static function register_order_meta_box() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        $screen = 'shop_order';
        if ( class_exists( '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController' ) && function_exists( 'wc_get_container' ) && function_exists( 'wc_get_page_screen_id' ) ) {
            try {
                $controller = wc_get_container()->get( '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController' );
                if ( $controller && $controller->custom_orders_table_usage_is_enabled() ) {
                    $screen = wc_get_page_screen_id( 'shop-order' );
                }
            } catch ( Throwable $e ) {
                $screen = 'shop_order';
            }
        }
        add_meta_box(
            'persiano_hub_advance_center',
            __( 'Advance Order Center', 'persiano-hub' ),
            array( __CLASS__, 'render_order_meta_box' ),
            $screen,
            'normal',
            'high'
        );
    }

    private static function order_from_meta_box_object( $object ) {
        if ( $object instanceof WC_Order ) {
            return $object;
        }
        if ( $object instanceof WP_Post ) {
            return wc_get_order( $object->ID );
        }
        if ( is_object( $object ) && isset( $object->ID ) ) {
            return wc_get_order( absint( $object->ID ) );
        }
        return false;
    }

    public static function render_order_meta_box( $object ) {
        $order = self::order_from_meta_box_object( $object );
        if ( ! self::is_advance_order( $order ) ) {
            echo '<p>' . esc_html__( 'This is not an advance-order request.', 'persiano-hub' ) . '</p>';
            return;
        }
        self::initialize_order( $order );
        $status = self::current_status( $order );
        $waiting = self::waiting_on( $order );
        $portal_url = self::portal_url( $order );
        $messages = self::messages( $order );
        wp_nonce_field( 'persiano_advance_admin_action_' . $order->get_id(), 'persiano_advance_admin_nonce' );
        ?>
        <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $order->get_edit_order_url() ); ?>#persiano_hub_advance_center">
        <div class="ph-advance-center">
            <div class="ph-advance-center__summary">
                <div><strong><?php esc_html_e( 'Request status', 'persiano-hub' ); ?></strong><br><span class="ph-advance-badge ph-advance-badge--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( self::status_label( $order ) ); ?></span></div>
                <div><strong><?php esc_html_e( 'Waiting on', 'persiano-hub' ); ?></strong><br><?php echo $waiting ? esc_html( 'persiano' === $waiting ? __( 'Persiano', 'persiano-hub' ) : __( 'Customer', 'persiano-hub' ) ) : '—'; ?></div>
                <div><strong><?php esc_html_e( 'Customer portal', 'persiano-hub' ); ?></strong><br><a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open secure request page ↗', 'persiano-hub' ); ?></a></div>
            </div>

            <h3><?php esc_html_e( 'Requested items / amendment', 'persiano-hub' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Change the requested date, quantity or customer-facing unit price below, then use “Send amendment”. Standard WooCommerce order-item editing remains available for more complex changes.', 'persiano-hub' ); ?></p>
            <table class="widefat striped ph-advance-items"><thead><tr><th><?php esc_html_e( 'Item', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Requested for', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Qty', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Unit price (tax incl.)', 'persiano-hub' ); ?></th></tr></thead><tbody>
            <?php foreach ( $order->get_items() as $item_id => $item ) : if ( 'yes' !== $item->get_meta( '_persiano_advance_order', true ) ) { continue; }
                $qty = max( 1, (float) $item->get_quantity() );
                $gross = (float) $item->get_total() + (float) $item->get_total_tax();
                $unit_price = $qty ? $gross / $qty : 0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $item->get_name() ); ?></strong></td>
                    <td><input type="text" class="widefat" name="persiano_advance_items[<?php echo esc_attr( $item_id ); ?>][requested]" value="<?php echo esc_attr( $item->get_meta( __( 'Requested for', 'persiano-hub' ), true ) ); ?>"></td>
                    <td><input type="number" min="1" step="1" name="persiano_advance_items[<?php echo esc_attr( $item_id ); ?>][qty]" value="<?php echo esc_attr( $qty ); ?>" style="width:82px"></td>
                    <td><input type="number" min="0" step="0.01" name="persiano_advance_items[<?php echo esc_attr( $item_id ); ?>][unit_price]" value="<?php echo esc_attr( number_format( $unit_price, 2, '.', '' ) ); ?>" style="width:110px"></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>

            <h3><?php esc_html_e( 'Message / action', 'persiano-hub' ); ?></h3>
            <textarea class="widefat" rows="4" name="persiano_advance_message" placeholder="<?php esc_attr_e( 'Write a customer-visible message, clarification question, amendment explanation, or private note…', 'persiano-hub' ); ?>"></textarea>
            <p class="ph-advance-actions">
                <button type="button" class="button button-primary ph-advance-submit" data-action="accept"><?php esc_html_e( 'Accept & send payment link', 'persiano-hub' ); ?></button>
                <button type="button" class="button ph-advance-submit" data-action="clarify"><?php esc_html_e( 'Request clarification', 'persiano-hub' ); ?></button>
                <button type="button" class="button ph-advance-submit" data-action="amend"><?php esc_html_e( 'Send amendment', 'persiano-hub' ); ?></button>
                <button type="button" class="button ph-advance-submit" data-action="private_note"><?php esc_html_e( 'Add private note', 'persiano-hub' ); ?></button>
                <button type="button" class="button button-link-delete ph-advance-submit" data-action="reject" data-confirm="<?php echo esc_attr( __( 'Reject this advance order request?', 'persiano-hub' ) ); ?>"><?php esc_html_e( 'Reject request', 'persiano-hub' ); ?></button>
            </p>

            <h3><?php esc_html_e( 'Conversation', 'persiano-hub' ); ?></h3>
            <div class="ph-advance-thread">
                <?php if ( ! $messages ) : ?>
                    <p><?php esc_html_e( 'No messages yet.', 'persiano-hub' ); ?></p>
                <?php else : foreach ( array_reverse( $messages ) as $message ) :
                    $private = 'private' === ( $message['visibility'] ?? 'customer' );
                    ?>
                    <div class="ph-advance-message <?php echo $private ? 'is-private' : ''; ?>">
                        <div class="ph-advance-message__meta"><strong><?php echo esc_html( ucfirst( $message['author'] ?? 'system' ) ); ?></strong> · <?php echo esc_html( $message['time'] ?? '' ); ?><?php echo $private ? ' · ' . esc_html__( 'Private', 'persiano-hub' ) : ''; ?></div>
                        <div><?php echo nl2br( esc_html( $message['body'] ?? '' ) ); ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <style>
            .ph-advance-center__summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}.ph-advance-center__summary>div{padding:12px;background:#f6f7f7;border-radius:8px}.ph-advance-actions{display:flex;gap:8px;flex-wrap:wrap}.ph-advance-thread{display:grid;gap:10px}.ph-advance-message{padding:12px 14px;border-left:4px solid #8a2b4b;background:#faf7f3;border-radius:6px}.ph-advance-message.is-private{border-left-color:#646970;background:#f0f0f1}.ph-advance-message__meta{font-size:12px;color:#646970;margin-bottom:5px}.ph-advance-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#f0f0f1;font-weight:600;font-size:12px}.ph-advance-badge--request_received{background:#fff3cd;color:#664d03}.ph-advance-badge--needs_clarification,.ph-advance-badge--amended_waiting_customer{background:#e8f2ff;color:#174a7e}.ph-advance-badge--confirmed_payment_pending{background:#ede7f6;color:#5e357a}.ph-advance-badge--paid_processing{background:#e5f5e8;color:#1f6a37}.ph-advance-badge--rejected,.ph-advance-badge--cancelled{background:#fbeaea;color:#8a2424}@media(max-width:782px){.ph-advance-center__summary{grid-template-columns:1fr}.ph-advance-items{display:block;overflow-x:auto}}
        </style>
        <script>
        (function(){
            var box=document.getElementById('persiano_hub_advance_center');
            if(!box) return;
            box.addEventListener('click',function(e){
                var button=e.target.closest('.ph-advance-submit');
                if(!button) return;
                e.preventDefault();
                var confirmText=button.getAttribute('data-confirm');
                if(confirmText && !window.confirm(confirmText)) return;
                var form=document.createElement('form');
                form.method='post';
                form.action=<?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
                function add(name,value){var input=document.createElement('input');input.type='hidden';input.name=name;input.value=value||'';form.appendChild(input);}
                add('action','persiano_advance_admin_action');
                add('order_id',box.querySelector('[name="order_id"]').value);
                add('redirect_to',box.querySelector('[name="redirect_to"]').value);
                add('persiano_advance_admin_nonce',box.querySelector('[name="persiano_advance_admin_nonce"]').value);
                add('persiano_advance_admin_action_type',button.getAttribute('data-action'));
                var message=box.querySelector('[name="persiano_advance_message"]');
                add('persiano_advance_message',message?message.value:'');
                box.querySelectorAll('[name^="persiano_advance_items["]').forEach(function(field){add(field.name,field.value);});
                document.body.appendChild(form);
                form.submit();
            });
        })();
        </script>
        <?php
    }

    public static function handle_admin_action() {
        $order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
        if ( ! $order_id || ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage this order.', 'persiano-hub' ), 403 );
        }
        if ( isset( $_POST['persiano_advance_admin_nonce'] ) ) {
            check_admin_referer( 'persiano_advance_admin_action_' . $order_id, 'persiano_advance_admin_nonce' );
        } else {
            check_admin_referer( 'persiano_advance_admin_action_' . $order_id );
        }
        $order = wc_get_order( $order_id );
        if ( ! self::is_advance_order( $order ) ) {
            wp_die( esc_html__( 'Advance order not found.', 'persiano-hub' ) );
        }
        self::initialize_order( $order );

        $type = isset( $_REQUEST['persiano_advance_admin_action_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['persiano_advance_admin_action_type'] ) ) : '';
        $message = isset( $_POST['persiano_advance_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_advance_message'] ) ) : '';
        $redirect = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_REQUEST['redirect_to'] ), admin_url( 'admin.php?page=persiano-hub-advance-orders' ) ) : admin_url( 'admin.php?page=persiano-hub-advance-orders' );

        if ( 'accept' === $type ) {
            if ( $message ) {
                self::append_message( $order, $message, 'persiano', 'customer' );
            }
            Persiano_Hub_Advance_Orders::confirm_advance_and_request_payment( $order );
            $order->update_meta_data( self::META_STATUS, 'confirmed_payment_pending' );
            $order->save();
        } elseif ( 'clarify' === $type ) {
            if ( ! $message ) {
                $message = __( 'We need a little more information before we can confirm your advance order. Please reply using the secure request page.', 'persiano-hub' );
            }
            self::append_message( $order, $message, 'persiano', 'customer' );
            $order->update_meta_data( self::META_STATUS, 'needs_clarification' );
            $order->add_order_note( sprintf( __( 'Clarification requested: %s', 'persiano-hub' ), $message ) );
            $order->save();
            self::send_customer_message_email( $order, __( 'We need a little more information about your advance order', 'persiano-hub' ), $message );
        } elseif ( 'amend' === $type ) {
            self::apply_item_amendments( $order );
            if ( ! $message ) {
                $message = __( 'We made a proposed change to your advance order. Please review it and approve or decline the amendment.', 'persiano-hub' );
            }
            self::append_message( $order, $message, 'persiano', 'customer' );
            $order->update_meta_data( self::META_STATUS, 'amended_waiting_customer' );
            $order->update_meta_data( '_persiano_advance_order_confirmed', 'no' );
            $order->add_order_note( sprintf( __( 'Advance order amendment sent to customer: %s', 'persiano-hub' ), $message ) );
            $order->save();
            self::send_customer_message_email( $order, sprintf( __( 'Please review your updated %s advance order', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ), $message );
        } elseif ( 'reject' === $type ) {
            if ( ! $message ) {
                $message = __( 'We’re sorry, but we’re unable to accept this advance-order request. Please contact us if you would like to discuss another date or option.', 'persiano-hub' );
            }
            self::append_message( $order, $message, 'persiano', 'customer' );
            $order->update_meta_data( self::META_STATUS, 'rejected' );
            $order->update_meta_data( '_persiano_advance_order_confirmed', 'no' );
            $order->add_order_note( sprintf( __( 'Advance order rejected: %s', 'persiano-hub' ), $message ) );
            $order->set_status( 'cancelled' );
            $order->save();
            self::send_customer_message_email( $order, sprintf( __( 'Update about your %s advance order', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ), $message );
        } elseif ( 'private_note' === $type ) {
            if ( $message ) {
                self::append_message( $order, $message, 'persiano', 'private' );
                $order->add_order_note( $message, false, false );
                $order->save();
            }
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    private static function apply_item_amendments( $order ) {
        $rows = isset( $_POST['persiano_advance_items'] ) && is_array( $_POST['persiano_advance_items'] ) ? wp_unslash( $_POST['persiano_advance_items'] ) : array();
        $requested_key = __( 'Requested for', 'persiano-hub' );
        foreach ( $rows as $item_id => $data ) {
            $item = $order->get_item( absint( $item_id ) );
            if ( ! $item instanceof WC_Order_Item_Product || 'yes' !== $item->get_meta( '_persiano_advance_order', true ) ) {
                continue;
            }
            $requested = isset( $data['requested'] ) ? sanitize_text_field( $data['requested'] ) : '';
            $qty = isset( $data['qty'] ) ? max( 1, absint( $data['qty'] ) ) : max( 1, (int) $item->get_quantity() );
            $unit_price = isset( $data['unit_price'] ) && is_numeric( $data['unit_price'] ) ? max( 0, (float) $data['unit_price'] ) : null;
            if ( $requested ) {
                $item->update_meta_data( $requested_key, $requested );
            }
            $item->set_quantity( $qty );
            if ( null !== $unit_price ) {
                $original_id = absint( $item->get_meta( '_persiano_original_product_id', true ) );
                $product = $original_id ? wc_get_product( $original_id ) : false;
                $net_unit = $unit_price;
                if ( $product && function_exists( 'wc_get_price_excluding_tax' ) ) {
                    $net_unit = (float) wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $unit_price ) );
                }
                $line_total = round( $net_unit * $qty, wc_get_price_decimals() );
                $item->set_subtotal( $line_total );
                $item->set_total( $line_total );
            }
            $item->save();
        }
        if ( method_exists( $order, 'calculate_taxes' ) ) {
            $order->calculate_taxes();
        }
        $order->calculate_totals( true );
        $order->save();
    }

    private static function append_message( $order, $body, $author = 'persiano', $visibility = 'customer', $save = true ) {
        $body = sanitize_textarea_field( (string) $body );
        if ( ! $body ) {
            return;
        }
        $messages = self::messages( $order );
        $messages[] = array(
            'id'         => wp_generate_uuid4(),
            'time'       => current_time( 'mysql' ),
            'author'     => sanitize_key( $author ),
            'visibility' => 'private' === $visibility ? 'private' : 'customer',
            'body'       => $body,
        );
        $order->update_meta_data( self::META_MESSAGES, $messages );
        if ( $save ) {
            $order->save();
        }
    }

    private static function messages( $order ) {
        $messages = $order->get_meta( self::META_MESSAGES, true );
        return is_array( $messages ) ? array_values( $messages ) : array();
    }

    private static function ensure_portal_token( $order, $save = true ) {
        $token = (string) $order->get_meta( self::META_TOKEN, true );
        if ( ! $token ) {
            $token = wp_generate_password( 48, false, false );
            $order->update_meta_data( self::META_TOKEN, $token );
            if ( $save ) {
                $order->save();
            }
        }
        return $token;
    }

    public static function portal_url( $order ) {
        $token = self::ensure_portal_token( $order );
        return add_query_arg(
            array(
                'action'   => 'persiano_advance_portal',
                'order_id' => $order->get_id(),
                'token'    => rawurlencode( $token ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    private static function portal_order_from_request() {
        $order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
        $token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
        $order = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! self::is_advance_order( $order ) ) {
            return false;
        }
        $expected = (string) $order->get_meta( self::META_TOKEN, true );
        if ( ! $expected || ! $token || ! hash_equals( $expected, $token ) ) {
            return false;
        }
        return $order;
    }

    public static function render_customer_portal() {
        $order = self::portal_order_from_request();
        if ( ! $order ) {
            status_header( 403 );
            wp_die( esc_html__( 'This secure advance-order link is invalid or has expired.', 'persiano-hub' ) );
        }
        $status = self::current_status( $order );
        $messages = array_filter( self::messages( $order ), function( $message ) { return 'private' !== ( $message['visibility'] ?? 'customer' ); } );
        $action_url = admin_url( 'admin-post.php' );
        $token = $order->get_meta( self::META_TOKEN, true );
        nocache_headers();
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( sprintf( __( 'Advance Order #%s', 'persiano-hub' ), $order->get_order_number() ) ); ?></title><style>
        body{margin:0;background:#f7f1e8;color:#33251f;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:820px;margin:40px auto;padding:0 18px}.card{background:#fff;border:1px solid #e6ddd1;border-radius:18px;padding:24px;margin-bottom:18px;box-shadow:0 12px 35px rgba(56,39,31,.06)}h1,h2{font-family:Georgia,serif;color:#5c2038}.status{display:inline-block;padding:6px 10px;border-radius:999px;background:#f4e6eb;font-weight:700}.items{width:100%;border-collapse:collapse}.items th,.items td{padding:10px;border-bottom:1px solid #eee;text-align:left}.msg{padding:12px 14px;background:#faf7f3;border-left:4px solid #8a2b4b;border-radius:6px;margin:10px 0}.meta{font-size:12px;color:#75685f;margin-bottom:5px}textarea{width:100%;box-sizing:border-box;padding:12px;border:1px solid #cfc5ba;border-radius:8px}.buttons{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}button{border:0;border-radius:8px;padding:11px 16px;font-weight:700;cursor:pointer}.primary{background:#7a1e35;color:#fff}.secondary{background:#eee5dc;color:#4d392f}.danger{background:#f8dddd;color:#7b1f1f}@media(max-width:600px){.wrap{margin:18px auto}.card{padding:18px}.items{font-size:14px}}
        </style></head><body><div class="wrap">
            <div class="card"><h1><?php echo esc_html( sprintf( __( 'Advance Order #%s', 'persiano-hub' ), $order->get_order_number() ) ); ?></h1><p><span class="status"><?php echo esc_html( self::status_label( $order ) ); ?></span></p><p><?php echo wp_kses_post( self::requested_items_html( $order, false ) ); ?></p><p><strong><?php esc_html_e( 'Current total:', 'persiano-hub' ); ?></strong> <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></p></div>
            <div class="card"><h2><?php esc_html_e( 'Conversation', 'persiano-hub' ); ?></h2>
                <?php foreach ( $messages as $message ) : ?><div class="msg"><div class="meta"><strong><?php echo esc_html( 'customer' === ( $message['author'] ?? '' ) ? __( 'You', 'persiano-hub' ) : ( function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ); ?></strong> · <?php echo esc_html( $message['time'] ?? '' ); ?></div><?php echo nl2br( esc_html( $message['body'] ?? '' ) ); ?></div><?php endforeach; ?>
            </div>
            <div class="card"><h2><?php esc_html_e( 'Reply', 'persiano-hub' ); ?></h2>
                <form method="post" action="<?php echo esc_url( $action_url ); ?>">
                    <input type="hidden" name="action" value="persiano_advance_customer_action"><input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
                    <textarea name="message" rows="4" placeholder="<?php esc_attr_e( 'Write your reply…', 'persiano-hub' ); ?>"></textarea>
                    <div class="buttons"><button class="primary" name="customer_action" value="reply"><?php esc_html_e( 'Send reply', 'persiano-hub' ); ?></button>
                    <?php if ( 'amended_waiting_customer' === $status ) : ?><button class="secondary" name="customer_action" value="approve"><?php esc_html_e( 'Approve changes & continue', 'persiano-hub' ); ?></button><button class="danger" name="customer_action" value="decline"><?php esc_html_e( 'Decline changes', 'persiano-hub' ); ?></button><?php endif; ?></div>
                </form>
            </div>
        </div></body></html><?php
        exit;
    }

    public static function handle_customer_action() {
        $order = self::portal_order_from_request();
        if ( ! $order ) {
            status_header( 403 );
            wp_die( esc_html__( 'This secure advance-order link is invalid or has expired.', 'persiano-hub' ) );
        }
        $action = isset( $_POST['customer_action'] ) ? sanitize_key( wp_unslash( $_POST['customer_action'] ) ) : 'reply';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        if ( $message ) {
            self::append_message( $order, $message, 'customer', 'customer' );
            $order->add_order_note( sprintf( __( 'ACTION REQUIRED — Customer replied to advance order: %s', 'persiano-hub' ), $message ) );
        }
        if ( 'approve' === $action && 'amended_waiting_customer' === self::current_status( $order ) ) {
            self::append_message( $order, __( 'I approve the proposed changes.', 'persiano-hub' ), 'customer', 'customer' );
            Persiano_Hub_Advance_Orders::confirm_advance_and_request_payment( $order );
            $order->update_meta_data( self::META_STATUS, 'confirmed_payment_pending' );
        } elseif ( 'decline' === $action && 'amended_waiting_customer' === self::current_status( $order ) ) {
            self::append_message( $order, __( 'I do not approve the proposed changes. Please contact me to discuss another option.', 'persiano-hub' ), 'customer', 'customer' );
            $order->update_meta_data( self::META_STATUS, 'request_received' );
            $order->update_meta_data( '_persiano_advance_order_confirmed', 'no' );
        } else {
            if ( $message ) {
                $order->update_meta_data( self::META_STATUS, 'request_received' );
            }
        }
        $order->save();
        wp_safe_redirect( self::portal_url( $order ) );
        exit;
    }

    private static function send_customer_message_email( $order, $subject, $message ) {
        $email = sanitize_email( $order->get_billing_email() );
        if ( ! $email ) {
            return false;
        }
        $portal = self::portal_url( $order );
        $body = '<p>' . nl2br( esc_html( $message ) ) . '</p>';
        $body .= '<p><a href="' . esc_url( $portal ) . '" style="display:inline-block;padding:12px 18px;background:#7a1e35;color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">' . esc_html__( 'View and reply to your advance order', 'persiano-hub' ) . '</a></p>';
        if ( class_exists( 'Persiano_Hub_Email_Branding' ) ) {
            $body = Persiano_Hub_Email_Branding::branded_message( $subject, $body, $message );
        } elseif ( function_exists( 'WC' ) && WC()->mailer() ) {
            $body = WC()->mailer()->wrap_message( $subject, $body );
        }
        $brand = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' );
        $from  = function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : sanitize_email( get_option( 'admin_email' ) );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        if ( is_email( $from ) ) {
            $headers[] = 'From: ' . sanitize_text_field( $brand ) . ' <' . $from . '>';
            $headers[] = 'Reply-To: ' . sanitize_text_field( $brand ) . ' <' . $from . '>';
        }
        return wp_mail( $email, wp_strip_all_tags( $subject ), $body, $headers );
    }

    private static function requested_items_html( $order, $compact = false ) {
        $rows = array();
        $requested_key = __( 'Requested for', 'persiano-hub' );
        foreach ( $order->get_items() as $item ) {
            if ( 'yes' !== $item->get_meta( '_persiano_advance_order', true ) ) {
                continue;
            }
            $name = preg_replace( '/\s+—\s+' . preg_quote( __( 'Advance order', 'persiano-hub' ), '/' ) . '$/u', '', $item->get_name() );
            $requested = $item->get_meta( $requested_key, true );
            $rows[] = '<strong>' . esc_html( $name ) . '</strong> × ' . esc_html( $item->get_quantity() ) . ( $requested ? '<br><small>' . esc_html( $requested ) . '</small>' : '' );
        }
        if ( $compact ) {
            return implode( '<br>', $rows );
        }
        return $rows ? '<div>' . implode( '<hr style="border:0;border-top:1px solid #eee">', $rows ) . '</div>' : '';
    }
}
