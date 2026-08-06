<?php
/**
 * Item-level comps, service recovery, and loss/waste reporting.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Loss_Waste {
    const PAGE = 'persiano-hub-loss-waste';
    const META_RECORDS = '_persiano_loss_waste_records';
    const FEE_META = '_persiano_loss_waste_fee';
    const LEDGER_OPTION = 'persiano_loss_waste_ledger_v1';

    public static function init() {
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( __CLASS__, 'add_order_metabox' ) );
        add_action( 'add_meta_boxes_shop_order', array( __CLASS__, 'add_legacy_order_metabox' ) );
        add_action( 'admin_post_persiano_save_item_adjustment', array( __CLASS__, 'save_adjustment' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_report' ), 75 );
        add_filter( 'woocommerce_order_get_items', array( __CLASS__, 'keep_internal_fee_visible_in_admin' ), 10, 3 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_order_summary' ), 20 );
    }

    public static function register_report() {
        add_submenu_page(
            'persiano-hub',
            __( 'Loss & Waste', 'persiano-hub' ),
            __( 'Loss & Waste', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE,
            array( __CLASS__, 'render_report' )
        );
    }

    public static function add_order_metabox() {
        add_meta_box(
            'persiano-loss-waste',
            __( 'Comp / Service Recovery', 'persiano-hub' ),
            array( __CLASS__, 'render_metabox' ),
            'woocommerce_page_wc-orders',
            'normal',
            'high'
        );
    }

    public static function add_legacy_order_metabox() {
        add_meta_box(
            'persiano-loss-waste',
            __( 'Comp / Service Recovery', 'persiano-hub' ),
            array( __CLASS__, 'render_metabox' ),
            'shop_order',
            'normal',
            'high'
        );
    }

    private static function current_order( $object = null ) {
        if ( $object instanceof WC_Order ) {
            return $object;
        }
        if ( is_object( $object ) && isset( $object->ID ) ) {
            return wc_get_order( $object->ID );
        }
        $order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return $order_id ? wc_get_order( $order_id ) : false;
    }

    public static function render_metabox( $object ) {
        $order = self::current_order( $object );
        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Order not found.', 'persiano-hub' ) . '</p>';
            return;
        }
        $records = $order->get_meta( self::META_RECORDS, true );
        $records = is_array( $records ) ? $records : array();
        ?>
        <div class="ph-loss-box">
            <p><?php esc_html_e( 'Waive part or all of an individual item while keeping the original order history. This is intended for quality issues, waste, replacements, samples, and other service recovery.', 'persiano-hub' ); ?></p>
            <?php if ( $order->is_paid() ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'This order is already paid. Record the loss here for reporting, then use WooCommerce Refund to return money to the customer.', 'persiano-hub' ); ?></p></div>
            <?php endif; ?>
            <div class="ph-loss-adjustment-form" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'persiano_save_item_adjustment_' . $order->get_id() ) ); ?>" data-action-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e( 'Item', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Qty affected', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Amount to waive', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Outcome', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Reason', 'persiano-hub' ); ?></th></tr></thead>
                    <tbody><tr>
                        <td><select data-ph-field="item_id" style="max-width:100%;width:100%"><option value=""><?php esc_html_e( 'Select an order item…', 'persiano-hub' ); ?></option><?php foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) : ?><option value="<?php echo esc_attr( $item_id ); ?>" data-max-qty="<?php echo esc_attr( $item->get_quantity() ); ?>" data-max-amount="<?php echo esc_attr( max( 0, (float) $item->get_total() ) ); ?>"><?php echo esc_html( $item->get_name() . ' × ' . $item->get_quantity() ); ?></option><?php endforeach; ?></select></td>
                        <td><input type="number" data-ph-field="quantity" min="1" step="1" value="1" style="width:90px"></td>
                        <td><label class="ph-money-prefix"><span><?php echo esc_html( get_woocommerce_currency_symbol( $order->get_currency() ) ); ?></span><input type="number" data-ph-field="amount" min="0.01" step="0.01" style="width:120px"></label></td>
                        <td><select data-ph-field="outcome"><option value="comped_delivered"><?php esc_html_e( 'Comped but delivered', 'persiano-hub' ); ?></option><option value="discarded"><?php esc_html_e( 'Discarded / waste', 'persiano-hub' ); ?></option><option value="replaced"><?php esc_html_e( 'Replaced at no charge', 'persiano-hub' ); ?></option><option value="partial_adjustment"><?php esc_html_e( 'Partial quality adjustment', 'persiano-hub' ); ?></option><option value="sample"><?php esc_html_e( 'Sample / promotion', 'persiano-hub' ); ?></option><option value="store_credit"><?php esc_html_e( 'Store credit issued', 'persiano-hub' ); ?></option></select></td>
                        <td><select data-ph-field="reason"><option value="quality_issue"><?php esc_html_e( 'Quality issue', 'persiano-hub' ); ?></option><option value="preparation_error"><?php esc_html_e( 'Preparation error', 'persiano-hub' ); ?></option><option value="incorrect_item"><?php esc_html_e( 'Incorrect item', 'persiano-hub' ); ?></option><option value="missing_item"><?php esc_html_e( 'Missing item', 'persiano-hub' ); ?></option><option value="damaged_packaging"><?php esc_html_e( 'Damaged packaging', 'persiano-hub' ); ?></option><option value="spoilage"><?php esc_html_e( 'Spoilage', 'persiano-hub' ); ?></option><option value="customer_goodwill"><?php esc_html_e( 'Customer goodwill', 'persiano-hub' ); ?></option><option value="other"><?php esc_html_e( 'Other', 'persiano-hub' ); ?></option></select></td>
                    </tr></tbody>
                </table>
                <p><label><strong><?php esc_html_e( 'Internal note', 'persiano-hub' ); ?></strong><br><textarea data-ph-field="note" rows="2" style="width:100%"></textarea></label></p>
                <p><label><input type="checkbox" data-ph-field="apply_to_balance" value="1" <?php checked( ! $order->is_paid() ); ?> <?php disabled( $order->is_paid() ); ?>> <?php esc_html_e( 'Apply this amount to the unpaid customer balance as a quality adjustment', 'persiano-hub' ); ?></label></p>
                <p><button class="button button-primary ph-record-item-adjustment" type="button"><?php esc_html_e( 'Record item adjustment', 'persiano-hub' ); ?></button></p>
            </div>

            <?php if ( $records ) : ?>
                <h4><?php esc_html_e( 'Recorded adjustments', 'persiano-hub' ); ?></h4>
                <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Item', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Qty', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Outcome / reason', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Amount', 'persiano-hub' ); ?></th></tr></thead><tbody>
                <?php foreach ( array_reverse( $records ) as $record ) : ?>
                    <tr><td><?php echo esc_html( isset( $record['date'] ) ? $record['date'] : '' ); ?></td><td><?php echo esc_html( isset( $record['item_name'] ) ? $record['item_name'] : '' ); ?></td><td><?php echo esc_html( isset( $record['quantity'] ) ? $record['quantity'] : '' ); ?></td><td><?php echo esc_html( self::label( isset( $record['outcome'] ) ? $record['outcome'] : '' ) . ' — ' . self::label( isset( $record['reason'] ) ? $record['reason'] : '' ) ); ?></td><td><?php echo wp_kses_post( wc_price( isset( $record['amount'] ) ? $record['amount'] : 0, array( 'currency' => $order->get_currency() ) ) ); ?></td></tr>
                <?php endforeach; ?></tbody></table>
            <?php endif; ?>
        </div>
        <style>.ph-loss-box select,.ph-loss-box input,.ph-loss-box textarea{font-size:14px}.ph-loss-box td{vertical-align:middle}.ph-money-prefix{display:inline-flex;align-items:center;border:1px solid #8c8f94;border-radius:4px;background:#fff;overflow:hidden}.ph-money-prefix span{padding:0 8px;background:#f0f0f1;line-height:30px}.ph-money-prefix input{border:0!important;box-shadow:none!important}</style>
        <script>
        (function(){
            function field(box,name){return box.querySelector('[data-ph-field="'+name+'"]');}
            document.addEventListener('change',function(e){
                if(!e.target || e.target.getAttribute('data-ph-field')!=='item_id') return;
                var o=e.target.options[e.target.selectedIndex], box=e.target.closest('.ph-loss-adjustment-form');
                if(!o||!box) return;
                var q=field(box,'quantity'), a=field(box,'amount');
                q.max=o.dataset.maxQty||''; q.value=o.dataset.maxQty||1;
                a.max=o.dataset.maxAmount||''; a.value=parseFloat(o.dataset.maxAmount||0).toFixed(2);
            });
            document.addEventListener('click',function(e){
                var button=e.target.closest('.ph-record-item-adjustment');
                if(!button) return;
                var box=button.closest('.ph-loss-adjustment-form');
                if(!box) return;
                var item=field(box,'item_id'), amount=field(box,'amount');
                if(!item || !item.value){ item && item.focus(); alert('Please select an order item.'); return; }
                if(!amount || parseFloat(amount.value||0)<=0){ amount && amount.focus(); alert('Enter an adjustment amount greater than zero.'); return; }
                var form=document.createElement('form'); form.method='post'; form.action=box.dataset.actionUrl; form.style.display='none';
                var values={action:'persiano_save_item_adjustment',order_id:box.dataset.orderId,_wpnonce:box.dataset.nonce};
                ['item_id','quantity','amount','outcome','reason','note'].forEach(function(name){var el=field(box,name);values[name]=el?el.value:'';});
                var apply=field(box,'apply_to_balance'); if(apply && apply.checked) values.apply_to_balance='1';
                Object.keys(values).forEach(function(name){var input=document.createElement('input');input.type='hidden';input.name=name;input.value=values[name];form.appendChild(input);});
                document.body.appendChild(form); form.submit();
            });
        })();
        </script>
        <?php
    }

    public static function save_adjustment() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage orders.', 'persiano-hub' ) );
        }
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        check_admin_referer( 'persiano_save_item_adjustment_' . $order_id );
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'persiano-hub' ) );
        }
        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
        $item = $order->get_item( $item_id );
        if ( ! $item || ! $item instanceof WC_Order_Item_Product ) {
            wp_die( esc_html__( 'Order item not found.', 'persiano-hub' ) );
        }
        $quantity = isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1;
        $quantity = min( $quantity, max( 1, (int) $item->get_quantity() ) );
        $amount = isset( $_POST['amount'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['amount'] ) ) : 0;
        $amount = min( max( 0, $amount ), max( 0, (float) $item->get_total() ) );
        if ( $amount <= 0 ) {
            wp_die( esc_html__( 'Adjustment amount must be greater than zero.', 'persiano-hub' ) );
        }
        $outcome = isset( $_POST['outcome'] ) ? sanitize_key( wp_unslash( $_POST['outcome'] ) ) : 'comped_delivered';
        $reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : 'other';
        $note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
        $apply = ! empty( $_POST['apply_to_balance'] ) && ! $order->is_paid();
        $record_id = wp_generate_uuid4();
        $record = array(
            'id'          => $record_id,
            'date'        => current_time( 'mysql' ),
            'item_id'     => $item_id,
            'product_id'  => $item->get_product_id(),
            'item_name'   => $item->get_name(),
            'quantity'    => $quantity,
            'amount'      => $amount,
            'outcome'     => $outcome,
            'reason'      => $reason,
            'note'        => $note,
            'applied'     => $apply,
            'user_id'     => get_current_user_id(),
        );
        $records = $order->get_meta( self::META_RECORDS, true );
        $records = is_array( $records ) ? $records : array();
        $records[] = $record;
        $order->update_meta_data( self::META_RECORDS, $records );
        self::append_to_ledger( $order, $record );

        if ( $apply ) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name( sprintf( __( 'Quality adjustment — %s', 'persiano-hub' ), $item->get_name() ) );
            $fee->set_amount( -1 * $amount );
            $fee->set_total( -1 * $amount );
            $fee->set_tax_status( 'none' );
            $fee->add_meta_data( self::FEE_META, $record_id, true );
            $order->add_item( $fee );
            $order->calculate_totals( false );
        }
        $order->add_order_note( sprintf( __( 'Item adjustment recorded: %1$s × %2$d, %3$s (%4$s), %5$s.', 'persiano-hub' ), $item->get_name(), $quantity, self::label( $outcome ), self::label( $reason ), wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) ) );
        $order->save();
        wp_safe_redirect( self::order_edit_url( $order_id ) );
        exit;
    }

    private static function append_to_ledger( $order, $record ) {
        $ledger = get_option( self::LEDGER_OPTION, array() );
        $ledger = is_array( $ledger ) ? $ledger : array();
        $record['order_id'] = $order->get_id();
        $record['currency'] = $order->get_currency();
        $record['customer'] = $order->get_formatted_billing_full_name() ?: $order->get_billing_email();
        $ledger[ $record['id'] ] = $record;
        if ( count( $ledger ) > 5000 ) {
            $ledger = array_slice( $ledger, -5000, null, true );
        }
        update_option( self::LEDGER_OPTION, $ledger, false );
    }

    private static function backfill_ledger() {
        $ledger = get_option( self::LEDGER_OPTION, array() );
        $ledger = is_array( $ledger ) ? $ledger : array();
        $orders = wc_get_orders(
            array(
                'type'   => 'shop_order',
                'limit'  => -1,
                'status' => array_keys( wc_get_order_statuses() ),
                'return' => 'objects',
            )
        );
        $changed = false;
        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
                continue;
            }
            $records = $order->get_meta( self::META_RECORDS, true );
            if ( ! is_array( $records ) ) {
                continue;
            }
            foreach ( $records as $record ) {
                if ( empty( $record['id'] ) ) {
                    $record['id'] = wp_generate_uuid4();
                }
                if ( isset( $ledger[ $record['id'] ] ) ) {
                    continue;
                }
                $record['order_id'] = $order->get_id();
                $record['currency'] = $order->get_currency();
                $record['customer'] = $order->get_formatted_billing_full_name() ?: $order->get_billing_email();
                $ledger[ $record['id'] ] = $record;
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( self::LEDGER_OPTION, $ledger, false );
        }
        return $ledger;
    }

    public static function render_order_summary( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $records = $order->get_meta( self::META_RECORDS, true );
        if ( ! is_array( $records ) || ! $records ) {
            return;
        }
        $total = 0;
        foreach ( $records as $record ) {
            $total += isset( $record['amount'] ) ? (float) $record['amount'] : 0;
        }
        echo '<div class="order_data_column ph-order-loss-summary">';
        echo '<h3>' . esc_html__( 'Service Recovery / Loss', 'persiano-hub' ) . '</h3>';
        echo '<p><strong>' . esc_html( sprintf( _n( '%d recorded adjustment', '%d recorded adjustments', count( $records ), 'persiano-hub' ), count( $records ) ) ) . '</strong><br>';
        echo esc_html__( 'Total recorded value:', 'persiano-hub' ) . ' ' . wp_kses_post( wc_price( $total, array( 'currency' => $order->get_currency() ) ) ) . '</p>';
        echo '<ul style="margin:0 0 12px 18px">';
        foreach ( array_slice( array_reverse( $records ), 0, 5 ) as $record ) {
            echo '<li>' . esc_html( ( $record['item_name'] ?? '' ) . ' × ' . ( $record['quantity'] ?? 1 ) . ' — ' . self::label( $record['outcome'] ?? '' ) . ' / ' . self::label( $record['reason'] ?? '' ) ) . ' — ' . wp_kses_post( wc_price( $record['amount'] ?? 0, array( 'currency' => $order->get_currency() ) ) ) . '</li>';
        }
        echo '</ul></div>';
    }

    private static function order_edit_url( $order_id ) {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $order_id ) );
        }
        return admin_url( 'post.php?post=' . absint( $order_id ) . '&action=edit' );
    }

    private static function label( $value ) {
        return ucwords( str_replace( '_', ' ', (string) $value ) );
    }

    public static function keep_internal_fee_visible_in_admin( $items, $order, $types ) {
        return $items;
    }

    public static function render_report() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this report.', 'persiano-hub' ) );
        }
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : wp_date( 'Y-m-01' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : wp_date( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ledger = self::backfill_ledger();
        $rows = array();
        $totals = array( 'lost_revenue' => 0, 'waste' => 0, 'replacement' => 0, 'promotion' => 0 );
        $from_ts = strtotime( $from . ' 00:00:00' );
        $to_ts   = strtotime( $to . ' 23:59:59' );
        foreach ( $ledger as $record ) {
            $record_ts = ! empty( $record['date'] ) ? strtotime( $record['date'] ) : 0;
            if ( ! $record_ts || $record_ts < $from_ts || $record_ts > $to_ts ) {
                continue;
            }
            $order_id = isset( $record['order_id'] ) ? absint( $record['order_id'] ) : 0;
            $order = $order_id ? wc_get_order( $order_id ) : false;
            $amount = isset( $record['amount'] ) ? (float) $record['amount'] : 0;
            $totals['lost_revenue'] += $amount;
            if ( 'discarded' === ( $record['outcome'] ?? '' ) ) { $totals['waste'] += $amount; }
            if ( 'replaced' === ( $record['outcome'] ?? '' ) ) { $totals['replacement'] += $amount; }
            if ( 'sample' === ( $record['outcome'] ?? '' ) ) { $totals['promotion'] += $amount; }
            $rows[] = array( 'order' => $order, 'record' => $record );
        }
        usort( $rows, function( $a, $b ) {
            return strcmp( $a['record']['date'] ?? '', $b['record']['date'] ?? '' );
        } );
        ?>
        <div class="wrap ph-loss-report"><h1><?php esc_html_e( 'Loss & Waste Report', 'persiano-hub' ); ?></h1>
        <form method="get" class="ph-report-filter"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>"><label><?php esc_html_e( 'From', 'persiano-hub' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label><label><?php esc_html_e( 'To', 'persiano-hub' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label><button class="button"><?php esc_html_e( 'Apply', 'persiano-hub' ); ?></button></form>
        <div class="ph-report-cards"><div><strong><?php echo wp_kses_post( wc_price( $totals['lost_revenue'] ) ); ?></strong><span><?php esc_html_e( 'Comped / lost revenue', 'persiano-hub' ); ?></span></div><div><strong><?php echo wp_kses_post( wc_price( $totals['waste'] ) ); ?></strong><span><?php esc_html_e( 'Discarded / waste value', 'persiano-hub' ); ?></span></div><div><strong><?php echo wp_kses_post( wc_price( $totals['replacement'] ) ); ?></strong><span><?php esc_html_e( 'Replacement value', 'persiano-hub' ); ?></span></div><div><strong><?php echo wp_kses_post( wc_price( $totals['promotion'] ) ); ?></strong><span><?php esc_html_e( 'Samples / promotions', 'persiano-hub' ); ?></span></div></div>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Order', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Customer', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Item', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Qty', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Outcome', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Reason', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Amount', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Note', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php if ( ! $rows ) : ?><tr><td colspan="9"><?php esc_html_e( 'No item adjustments were recorded in this period.', 'persiano-hub' ); ?></td></tr><?php endif; ?>
        <?php foreach ( array_reverse( $rows ) as $row ) : $order = $row['order']; $record = $row['record']; $currency = $order instanceof WC_Order ? $order->get_currency() : ( $record['currency'] ?? get_woocommerce_currency() ); ?><tr><td><?php echo esc_html( $record['date'] ?? '' ); ?></td><td><?php if ( $order instanceof WC_Order ) : ?><a href="<?php echo esc_url( self::order_edit_url( $order->get_id() ) ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a><?php else : ?>#<?php echo esc_html( $record['order_id'] ?? '' ); ?><?php endif; ?></td><td><?php echo esc_html( $order instanceof WC_Order ? ( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ) : ( $record['customer'] ?? '' ) ); ?></td><td><?php echo esc_html( $record['item_name'] ?? '' ); ?></td><td><?php echo esc_html( $record['quantity'] ?? '' ); ?></td><td><?php echo esc_html( self::label( $record['outcome'] ?? '' ) ); ?></td><td><?php echo esc_html( self::label( $record['reason'] ?? '' ) ); ?></td><td><?php echo wp_kses_post( wc_price( $record['amount'] ?? 0, array( 'currency' => $currency ) ) ); ?></td><td><?php echo esc_html( $record['note'] ?? '' ); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <style>.ph-report-filter{display:flex;gap:12px;align-items:end;margin:16px 0}.ph-report-cards{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:14px;margin:16px 0}.ph-report-cards>div{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px}.ph-report-cards strong{display:block;font-size:26px}.ph-report-cards span{color:#646970}@media(max-width:900px){.ph-report-cards{grid-template-columns:1fr 1fr}}</style>
        <?php
    }
}
