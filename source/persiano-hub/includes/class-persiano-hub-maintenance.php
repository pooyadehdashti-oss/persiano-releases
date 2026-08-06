<?php
/**
 * Safe maintenance tools for removing test transactions and linked reporting data.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Maintenance {
    const PAGE = 'persiano-hub-maintenance';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 90 );
        add_action( 'admin_post_persiano_cleanup_test_transactions', array( __CLASS__, 'handle_cleanup' ) );
        add_action( 'admin_post_persiano_cleanup_price_history', array( __CLASS__, 'handle_price_history_cleanup' ) );
    }

    public static function register_page() {
        add_submenu_page(
            'persiano-hub',
            __( 'Maintenance', 'persiano-hub' ),
            __( 'Maintenance', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
    }

    private static function parse_ids( $raw ) {
        $parts = preg_split( '/[^0-9]+/', (string) $raw );
        $ids   = array_values( array_unique( array_filter( array_map( 'absint', $parts ) ) ) );
        return array_slice( $ids, 0, 100 );
    }

    private static function parse_record_ids( $raw ) {
        $parts = preg_split( '/[\s,;]+/', (string) $raw );
        $ids = array();
        foreach ( (array) $parts as $part ) {
            $part = sanitize_text_field( trim( $part ) );
            if ( '' === $part || ! preg_match( '/^[A-Za-z0-9._:-]{6,120}$/', $part ) ) { continue; }
            $ids[ $part ] = $part;
        }
        return array_slice( array_values( $ids ), 0, 1000 );
    }

    private static function price_history_preview( $record_ids = array(), $batch_id = '' ) {
        $record_lookup = array_fill_keys( (array) $record_ids, true );
        $batch_id = sanitize_text_field( $batch_id );
        $rows = array();
        $ingredient_ids = get_posts( array(
            'post_type' => Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
            'post_status' => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ) );
        foreach ( $ingredient_ids as $ingredient_id ) {
            $history = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, true );
            if ( ! is_array( $history ) ) { continue; }
            foreach ( $history as $entry ) {
                if ( ! is_array( $entry ) ) { continue; }
                $record_id = sanitize_text_field( $entry['record_id'] ?? '' );
                $entry_batch = sanitize_text_field( $entry['import_batch_id'] ?? '' );
                $match = ( $record_id && isset( $record_lookup[ $record_id ] ) ) || ( $batch_id && $entry_batch === $batch_id );
                if ( ! $match ) { continue; }
                $rows[] = array(
                    'record_id' => $record_id,
                    'batch_id' => $entry_batch,
                    'ingredient_id' => $ingredient_id,
                    'ingredient' => get_the_title( $ingredient_id ),
                    'date' => ! empty( $entry['time'] ) ? wp_date( 'Y-m-d', absint( $entry['time'] ) ) : '',
                    'supplier' => sanitize_text_field( $entry['supplier'] ?? $entry['vendor'] ?? '' ),
                    'package' => trim( (string) ( $entry['purchase_qty'] ?? '' ) . ' ' . (string) ( $entry['purchase_unit'] ?? '' ) ),
                    'total' => (float) ( $entry['gross_cost'] ?? 0 ),
                    'unit_cost' => (float) ( $entry['unit_cost'] ?? 0 ),
                    'base_unit' => sanitize_text_field( $entry['base_unit'] ?? '' ),
                );
            }
        }
        return $rows;
    }

    private static function table_exists( $table ) {
        global $wpdb;
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    private static function linked_ids( $root_ids ) {
        global $wpdb;
        $all = $root_ids;

        if ( $root_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $root_ids ), '%d' ) );
            $post_refunds = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order_refund' AND post_parent IN ({$placeholders})",
                    $root_ids
                )
            ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $all = array_merge( $all, array_map( 'absint', $post_refunds ) );

            $orders_table = $wpdb->prefix . 'wc_orders';
            if ( self::table_exists( $orders_table ) ) {
                $hpos_refunds = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT id FROM {$orders_table} WHERE type = 'shop_order_refund' AND parent_order_id IN ({$placeholders})",
                        $root_ids
                    )
                ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $all = array_merge( $all, array_map( 'absint', $hpos_refunds ) );
            }
        }

        return array_values( array_unique( array_filter( $all ) ) );
    }

    private static function preview_rows( $root_ids ) {
        global $wpdb;
        $rows = array();
        foreach ( $root_ids as $id ) {
            $order = wc_get_order( $id );
            $rows[] = array(
                'id'       => $id,
                'exists'   => $order instanceof WC_Order,
                'number'   => $order instanceof WC_Order ? $order->get_order_number() : (string) $id,
                'customer' => $order instanceof WC_Order ? ( $order->get_formatted_billing_full_name() ?: $order->get_billing_email() ) : '',
                'status'   => $order instanceof WC_Order ? wc_get_order_status_name( $order->get_status() ) : __( 'Order record not found', 'persiano-hub' ),
                'total'    => $order instanceof WC_Order ? $order->get_formatted_order_total() : '',
            );
        }
        return $rows;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to use maintenance tools.', 'persiano-hub' ) );
        }

        $raw = isset( $_GET['order_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['order_ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ids = self::parse_ids( $raw );
        $rows = $ids ? self::preview_rows( $ids ) : array();
        $notice = isset( $_GET['persiano_cleanup'] ) ? sanitize_key( wp_unslash( $_GET['persiano_cleanup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $removed = isset( $_GET['removed'] ) ? absint( $_GET['removed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $price_raw = isset( $_GET['price_record_ids'] ) ? sanitize_textarea_field( wp_unslash( $_GET['price_record_ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $price_batch = isset( $_GET['price_batch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['price_batch_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $price_ids = self::parse_record_ids( $price_raw );
        $price_rows = ( $price_ids || $price_batch ) ? self::price_history_preview( $price_ids, $price_batch ) : array();
        $price_notice = isset( $_GET['persiano_price_cleanup'] ) ? sanitize_key( wp_unslash( $_GET['persiano_price_cleanup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $price_removed = isset( $_GET['price_removed'] ) ? absint( $_GET['price_removed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $price_ingredients = isset( $_GET['price_ingredients'] ) ? absint( $_GET['price_ingredients'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap ph-maintenance">
            <h1><?php esc_html_e( 'Batchly Maintenance', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'Use each maintenance tool carefully. Preview records before deleting. The transaction tool is for test orders; the price-history tool removes only exact record IDs or one exact import batch.', 'persiano-hub' ); ?></p>

            <?php if ( 'done' === $notice ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Cleanup completed for %d transaction records. Analytics caches were cleared.', 'persiano-hub' ), $removed ) ); ?></p></div>
            <?php elseif ( 'error' === $notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'Nothing was removed. Enter at least one valid order ID and confirm the cleanup.', 'persiano-hub' ); ?></p></div>
            <?php endif; ?>

            <?php if ( 'done' === $price_notice ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Removed %1$d price-history records from %2$d ingredients. Current costs and recipes were recalculated.', 'persiano-hub' ), $price_removed, $price_ingredients ) ); ?></p></div>
            <?php elseif ( 'error' === $price_notice ) : ?>
                <div class="notice notice-error"><p><?php esc_html_e( 'No price-history records were removed. Enter exact record IDs or an exact import batch ID, preview the matches, and confirm deletion.', 'persiano-hub' ); ?></p></div>
            <?php endif; ?>

            <div class="ph-maint-card">
                <h2><?php esc_html_e( 'Remove test transactions', 'persiano-hub' ); ?></h2>
                <form method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
                    <label for="ph-order-ids"><strong><?php esc_html_e( 'Order IDs', 'persiano-hub' ); ?></strong></label>
                    <p class="description"><?php esc_html_e( 'Enter exact IDs separated by commas, spaces, or new lines. Do not enter customer names.', 'persiano-hub' ); ?></p>
                    <textarea id="ph-order-ids" name="order_ids" rows="4" class="large-text code" placeholder="1405, 1406"><?php echo esc_textarea( $raw ); ?></textarea>
                    <p><button class="button button-secondary"><?php esc_html_e( 'Preview records', 'persiano-hub' ); ?></button></p>
                </form>

                <?php if ( $rows ) : ?>
                    <h3><?php esc_html_e( 'Preview', 'persiano-hub' ); ?></h3>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e( 'ID', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Order', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Customer', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Status', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Total', 'persiano-hub' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row['id'] ); ?></td>
                                <td>#<?php echo esc_html( $row['number'] ); ?></td>
                                <td><?php echo esc_html( $row['customer'] ); ?></td>
                                <td><?php echo esc_html( $row['status'] ); ?></td>
                                <td><?php echo wp_kses_post( $row['total'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ph-danger-zone">
                        <input type="hidden" name="action" value="persiano_cleanup_test_transactions">
                        <input type="hidden" name="order_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
                        <?php wp_nonce_field( 'persiano_cleanup_test_transactions' ); ?>
                        <label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e( 'I confirm these are test transactions and may be permanently deleted.', 'persiano-hub' ); ?></label>
                        <p><button class="button button-primary ph-delete-button" type="submit" onclick="return confirm('<?php echo esc_js( __( 'Permanently remove these test transactions and linked reporting data?', 'persiano-hub' ) ); ?>');"><?php esc_html_e( 'Permanently remove test transactions', 'persiano-hub' ); ?></button></p>
                    </form>
                <?php endif; ?>
            </div>

            <div class="ph-maint-card">
                <h2><?php esc_html_e( 'Remove price-history records', 'persiano-hub' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Use exact price-history record IDs for older imports, or an import batch ID for imports created by version 0.45.1 or later. This does not delete ingredients.', 'persiano-hub' ); ?></p>
                <form method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
                    <label for="ph-price-record-ids"><strong><?php esc_html_e( 'Price-history record IDs', 'persiano-hub' ); ?></strong></label>
                    <p class="description"><?php esc_html_e( 'Paste UUIDs or record IDs separated by commas, spaces, or new lines.', 'persiano-hub' ); ?></p>
                    <textarea id="ph-price-record-ids" name="price_record_ids" rows="6" class="large-text code" placeholder="e6ac09b0-2fca-4743-a966-a9db3d085d4f"><?php echo esc_textarea( $price_raw ); ?></textarea>
                    <p><label for="ph-price-batch-id"><strong><?php esc_html_e( 'Or import batch ID', 'persiano-hub' ); ?></strong></label><br>
                    <input id="ph-price-batch-id" name="price_batch_id" type="text" class="regular-text code" value="<?php echo esc_attr( $price_batch ); ?>"></p>
                    <p><button class="button button-secondary"><?php esc_html_e( 'Preview price records', 'persiano-hub' ); ?></button></p>
                </form>

                <?php if ( $price_ids || $price_batch ) : ?>
                    <h3><?php echo esc_html( sprintf( __( 'Preview: %d matching records', 'persiano-hub' ), count( $price_rows ) ) ); ?></h3>
                    <?php if ( $price_rows ) : ?>
                        <div style="max-height:420px;overflow:auto"><table class="widefat striped">
                            <thead><tr><th><?php esc_html_e( 'Record ID', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Ingredient', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Date', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Supplier', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Package', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Total', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Unit cost', 'persiano-hub' ); ?></th></tr></thead>
                            <tbody><?php foreach ( $price_rows as $row ) : ?><tr><td><code><?php echo esc_html( $row['record_id'] ?: '—' ); ?></code></td><td><?php echo esc_html( $row['ingredient'] ); ?></td><td><?php echo esc_html( $row['date'] ); ?></td><td><?php echo esc_html( $row['supplier'] ); ?></td><td><?php echo esc_html( $row['package'] ); ?></td><td><?php echo wp_kses_post( wc_price( $row['total'] ) ); ?></td><td><?php echo esc_html( '$' . number_format( $row['unit_cost'], 6 ) . '/' . ( $row['base_unit'] ?: 'unit' ) ); ?></td></tr><?php endforeach; ?></tbody>
                        </table></div>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ph-danger-zone">
                            <input type="hidden" name="action" value="persiano_cleanup_price_history">
                            <textarea name="price_record_ids" hidden><?php echo esc_textarea( implode( "
", $price_ids ) ); ?></textarea>
                            <input type="hidden" name="price_batch_id" value="<?php echo esc_attr( $price_batch ); ?>">
                            <?php wp_nonce_field( 'persiano_cleanup_price_history' ); ?>
                            <label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e( 'I confirm these exact price-history records should be permanently removed.', 'persiano-hub' ); ?></label>
                            <p><button class="button button-primary ph-delete-button" type="submit" onclick="return confirm('<?php echo esc_js( __( 'Permanently remove the previewed price-history records?', 'persiano-hub' ) ); ?>');"><?php esc_html_e( 'Permanently remove price records', 'persiano-hub' ); ?></button></p>
                        </form>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No price-history records matched those exact identifiers.', 'persiano-hub' ); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <style>.ph-maint-card{max-width:980px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;margin-top:18px}.ph-danger-zone{margin-top:22px;padding:18px;border:1px solid #d63638;background:#fff5f5;border-radius:8px}.ph-delete-button{background:#b32d2e!important;border-color:#b32d2e!important}</style>
        <?php
    }

    public static function handle_cleanup() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to remove transactions.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_cleanup_test_transactions' );

        $root_ids = self::parse_ids( isset( $_POST['order_ids'] ) ? wp_unslash( $_POST['order_ids'] ) : '' );
        if ( ! $root_ids || empty( $_POST['confirm'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&persiano_cleanup=error' ) );
            exit;
        }

        $all_ids = self::linked_ids( $root_ids );

        // Delete WooCommerce objects first so the active data store performs its normal cleanup.
        foreach ( array_reverse( $all_ids ) as $id ) {
            $order = wc_get_order( $id );
            if ( $order instanceof WC_Abstract_Order ) {
                $order->delete( true );
            }
        }

        self::delete_reporting_rows( $root_ids, $all_ids );
        self::remove_loss_waste_records( $root_ids, $all_ids );
        self::clear_analytics_caches();

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&persiano_cleanup=done&removed=' . count( $all_ids ) ) );
        exit;
    }

    public static function handle_price_history_cleanup() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to remove price-history records.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_cleanup_price_history' );
        $record_ids = self::parse_record_ids( isset( $_POST['price_record_ids'] ) ? wp_unslash( $_POST['price_record_ids'] ) : '' );
        $batch_id = sanitize_text_field( isset( $_POST['price_batch_id'] ) ? wp_unslash( $_POST['price_batch_id'] ) : '' );
        if ( ( ! $record_ids && ! $batch_id ) || empty( $_POST['confirm'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&persiano_price_cleanup=error' ) );
            exit;
        }
        $record_lookup = array_fill_keys( $record_ids, true );
        $ingredient_ids = get_posts( array(
            'post_type' => Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
            'post_status' => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ) );
        $removed = 0;
        $affected = array();
        foreach ( $ingredient_ids as $ingredient_id ) {
            $history = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, true );
            if ( ! is_array( $history ) ) { continue; }
            $kept = array();
            $changed = false;
            foreach ( $history as $entry ) {
                if ( ! is_array( $entry ) ) { $kept[] = $entry; continue; }
                $record_id = sanitize_text_field( $entry['record_id'] ?? '' );
                $entry_batch = sanitize_text_field( $entry['import_batch_id'] ?? '' );
                $match = ( $record_id && isset( $record_lookup[ $record_id ] ) ) || ( $batch_id && $entry_batch === $batch_id );
                if ( $match ) { $removed++; $changed = true; continue; }
                $kept[] = $entry;
            }
            if ( $changed ) {
                update_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, array_values( $kept ) );
                $affected[] = $ingredient_id;
                if ( class_exists( 'Persiano_Hub_Ingredient_Master' ) ) { Persiano_Hub_Ingredient_Master::repair_and_apply_current_cost( $ingredient_id ); }
            }
        }
        if ( $affected && method_exists( 'Persiano_Hub_Costing', 'recalculate_recipe' ) ) {
            $recipe_ids = get_posts( array( 'post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE, 'post_status'=>array('publish','draft','private'), 'posts_per_page'=>-1, 'fields'=>'ids', 'no_found_rows'=>true ) );
            foreach ( $recipe_ids as $recipe_id ) { Persiano_Hub_Costing::recalculate_recipe( $recipe_id ); }
        }
        $status = $removed ? 'done' : 'error';
        wp_safe_redirect( add_query_arg( array( 'page'=>self::PAGE, 'persiano_price_cleanup'=>$status, 'price_removed'=>$removed, 'price_ingredients'=>count(array_unique($affected)) ), admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function delete_reporting_rows( $root_ids, $all_ids ) {
        global $wpdb;
        if ( ! $all_ids ) {
            return;
        }

        $ids_sql = implode( ',', array_map( 'absint', $all_ids ) );
        $roots_sql = implode( ',', array_map( 'absint', $root_ids ) );

        $tables = array(
            $wpdb->prefix . 'wc_order_product_lookup' => 'order_id',
            $wpdb->prefix . 'wc_order_coupon_lookup'  => 'order_id',
            $wpdb->prefix . 'wc_order_tax_lookup'     => 'order_id',
            $wpdb->prefix . 'wc_download_log'         => 'order_id',
        );
        foreach ( $tables as $table => $column ) {
            if ( self::table_exists( $table ) ) {
                $wpdb->query( "DELETE FROM {$table} WHERE {$column} IN ({$ids_sql})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        }

        $stats = $wpdb->prefix . 'wc_order_stats';
        if ( self::table_exists( $stats ) ) {
            $wpdb->query( "DELETE FROM {$stats} WHERE order_id IN ({$ids_sql}) OR parent_id IN ({$roots_sql})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $marketing = class_exists( 'Persiano_Hub_Marketing' ) ? Persiano_Hub_Marketing::table_name() : $wpdb->prefix . 'persiano_marketing_events';
        if ( self::table_exists( $marketing ) ) {
            $wpdb->query( "DELETE FROM {$marketing} WHERE object_id IN ({$ids_sql}) AND event_type IN ('purchase','refund')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // Last-resort cleanup for orphaned legacy post records.
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_sql})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids_sql}) AND post_type IN ('shop_order','shop_order_refund')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Last-resort cleanup for orphaned HPOS records.
        $hpos_tables = array(
            $wpdb->prefix . 'wc_order_addresses',
            $wpdb->prefix . 'wc_order_operational_data',
            $wpdb->prefix . 'wc_orders_meta',
        );
        foreach ( $hpos_tables as $table ) {
            if ( self::table_exists( $table ) ) {
                $wpdb->query( "DELETE FROM {$table} WHERE order_id IN ({$ids_sql})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        }
        $orders_table = $wpdb->prefix . 'wc_orders';
        if ( self::table_exists( $orders_table ) ) {
            $wpdb->query( "DELETE FROM {$orders_table} WHERE id IN ({$ids_sql}) OR parent_order_id IN ({$roots_sql})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    private static function remove_loss_waste_records( $root_ids, $all_ids ) {
        if ( ! class_exists( 'Persiano_Hub_Loss_Waste' ) ) {
            return;
        }
        $ledger = get_option( Persiano_Hub_Loss_Waste::LEDGER_OPTION, array() );
        if ( ! is_array( $ledger ) ) {
            return;
        }
        $targets = array_flip( array_map( 'absint', array_merge( $root_ids, $all_ids ) ) );
        foreach ( $ledger as $key => $record ) {
            $order_id = isset( $record['order_id'] ) ? absint( $record['order_id'] ) : 0;
            if ( isset( $targets[ $order_id ] ) ) {
                unset( $ledger[ $key ] );
            }
        }
        update_option( Persiano_Hub_Loss_Waste::LEDGER_OPTION, $ledger, false );
    }

    private static function clear_analytics_caches() {
        global $wpdb;

        if ( class_exists( '\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Cache' ) && method_exists( '\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Cache', 'invalidate' ) ) {
            \Automattic\WooCommerce\Admin\API\Reports\Cache::invalidate();
        }

        $patterns = array(
            '_transient_wc_report_%',
            '_transient_timeout_wc_report_%',
            '_transient_woocommerce_admin_report_%',
            '_transient_timeout_woocommerce_admin_report_%',
        );
        foreach ( $patterns as $pattern ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
        }

        if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
            wc_delete_shop_order_transients();
        }
        wp_cache_flush();
    }
}
