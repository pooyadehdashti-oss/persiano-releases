<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Loyalty_Admin {
    const PAGE_SLUG = 'persiano-hub-loyalty-admin';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
        add_action( 'admin_post_persiano_loyalty_adjust', array( __CLASS__, 'adjust' ) );
        add_action( 'admin_post_persiano_loyalty_recalculate', array( __CLASS__, 'recalculate' ) );
    }

    public static function menu() {
        add_submenu_page( 'persiano-hub', __( 'Loyalty Management', 'persiano-hub' ), __( 'Loyalty', 'persiano-hub' ), 'manage_woocommerce', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
    }

    private static function user_id() {
        return isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    private static function search_term() {
        return isset( $_GET['customer_search'] ) ? sanitize_text_field( wp_unslash( $_GET['customer_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    private static function search_customers( $term ) {
        if ( '' === $term ) { return array(); }
        $ids = array();

        if ( ctype_digit( $term ) ) {
            $candidate = get_userdata( absint( $term ) );
            if ( $candidate ) { $ids[] = $candidate->ID; }
        }

        $users = get_users( array(
            'number'         => 25,
            'search'         => '*' . $term . '*',
            'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
            'fields'         => 'all',
        ) );
        foreach ( $users as $user ) { $ids[] = $user->ID; }

        $meta_query = new WP_User_Query( array(
            'number'     => 25,
            'fields'     => 'all',
            'meta_query' => array(
                'relation' => 'OR',
                array( 'key' => 'first_name', 'value' => $term, 'compare' => 'LIKE' ),
                array( 'key' => 'last_name', 'value' => $term, 'compare' => 'LIKE' ),
                array( 'key' => 'billing_first_name', 'value' => $term, 'compare' => 'LIKE' ),
                array( 'key' => 'billing_last_name', 'value' => $term, 'compare' => 'LIKE' ),
                array( 'key' => 'billing_phone', 'value' => preg_replace( '/\D+/', '', $term ), 'compare' => 'LIKE' ),
            ),
        ) );
        foreach ( $meta_query->get_results() as $user ) { $ids[] = $user->ID; }

        $needle = strtolower( trim( $term ) );
        $digits = preg_replace('/\D+/','',$term);
        $matches = array();
        foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
            $u = get_userdata($id); if(!$u) continue;
            $hay = strtolower( implode(' ', array($u->display_name,$u->user_login,$u->user_email,get_user_meta($id,'first_name',true),get_user_meta($id,'last_name',true),get_user_meta($id,'billing_first_name',true),get_user_meta($id,'billing_last_name',true))) );
            $phone = preg_replace('/\D+/','', (string)get_user_meta($id,'billing_phone',true));
            if ( (ctype_digit($term) && (int)$term === (int)$id) || false !== strpos($hay,$needle) || ($digits && false !== strpos($phone,$digits)) ) $matches[]=$u;
            if(count($matches)>=25) break;
        }
        return $matches;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

        $uid     = self::user_id();
        $user    = $uid ? get_userdata( $uid ) : false;
        $search  = self::search_term();
        $matches = self::search_customers( $search );

        echo '<div class="wrap"><h1>' . esc_html__( 'Loyalty Management', 'persiano-hub' ) . '</h1>';
        echo '<form method="get" style="max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px 20px;margin:16px 0">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<label for="ph-customer-search"><strong>' . esc_html__( 'Find customer', 'persiano-hub' ) . '</strong></label><br>';
        echo '<input id="ph-customer-search" type="search" name="customer_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name, email, phone number or customer ID', 'persiano-hub' ) . '" style="width:min(520px,100%);margin-top:7px"> ';
        echo '<button class="button button-primary">' . esc_html__( 'Search', 'persiano-hub' ) . '</button>';
        echo '</form>';

        if ( $search && ! $uid ) {
            echo '<div class="card" style="max-width:900px"><h2>' . esc_html__( 'Matching customers', 'persiano-hub' ) . '</h2>';
            if ( empty( $matches ) ) {
                echo '<p>' . esc_html__( 'No customers matched that search.', 'persiano-hub' ) . '</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Customer', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Email', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Phone', 'persiano-hub' ) . '</th><th></th></tr></thead><tbody>';
                foreach ( $matches as $match ) {
                    $phone = get_user_meta( $match->ID, 'billing_phone', true );
                    $name  = trim( get_user_meta( $match->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $match->ID, 'billing_last_name', true ) );
                    if ( ! $name ) { $name = $match->display_name; }
                    $url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'customer_id' => $match->ID ), admin_url( 'admin.php' ) );
                    echo '<tr><td><strong>' . esc_html( $name ) . '</strong><br><small>ID ' . esc_html( $match->ID ) . '</small></td><td>' . esc_html( $match->user_email ) . '</td><td>' . esc_html( $phone ) . '</td><td><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Manage points', 'persiano-hub' ) . '</a></td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
        }

        if ( $user ) {
            $bal   = (int) get_user_meta( $uid, '_persiano_loyalty_balance', true );
            $life  = (int) get_user_meta( $uid, '_persiano_loyalty_lifetime', true );
            $log   = get_user_meta( $uid, '_persiano_loyalty_log', true );
            $log   = is_array( $log ) ? $log : array();
            $phone = get_user_meta( $uid, 'billing_phone', true );

            echo '<div class="card" style="max-width:900px;margin-top:20px"><h2>' . esc_html( $user->display_name ) . ' — ' . esc_html( $user->user_email ) . '</h2><p>' . esc_html( $phone ) . '</p><p><strong>' . esc_html__( 'Available points:', 'persiano-hub' ) . '</strong> ' . esc_html( $bal ) . ' &nbsp; <strong>' . esc_html__( 'Lifetime earned:', 'persiano-hub' ) . '</strong> ' . esc_html( $life ) . '</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'persiano_loyalty_adjust_' . $uid );
            echo '<input type="hidden" name="action" value="persiano_loyalty_adjust"><input type="hidden" name="customer_id" value="' . esc_attr( $uid ) . '"><p><label>' . esc_html__( 'Adjustment (+ or −)', 'persiano-hub' ) . ' <input type="number" name="points" required></label> <label>' . esc_html__( 'Reason', 'persiano-hub' ) . ' <input type="text" name="reason" required style="min-width:320px"></label> <button class="button button-primary">' . esc_html__( 'Apply adjustment', 'persiano-hub' ) . '</button></p></form>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'persiano_loyalty_recalculate_' . $uid );
            echo '<input type="hidden" name="action" value="persiano_loyalty_recalculate"><input type="hidden" name="customer_id" value="' . esc_attr( $uid ) . '"><button class="button">' . esc_html__( 'Recalculate from existing paid orders', 'persiano-hub' ) . '</button></form>';
            echo '<h3>' . esc_html__( 'Activity', 'persiano-hub' ) . '</h3><table class="widefat striped"><thead><tr><th>Date</th><th>Reason</th><th>Points</th></tr></thead><tbody>';
            foreach ( $log as $row ) { echo '<tr><td>' . esc_html( $row['date'] ?? '' ) . '</td><td>' . esc_html( $row['label'] ?? '' ) . '</td><td>' . esc_html( $row['points'] ?? 0 ) . '</td></tr>'; }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }

    private static function add_log( $uid, $points, $reason ) {
        $log = get_user_meta( $uid, '_persiano_loyalty_log', true );
        $log = is_array( $log ) ? $log : array();
        array_unshift( $log, array( 'date' => current_time( 'mysql' ), 'points' => $points, 'label' => $reason, 'order_id' => 0, 'admin_id' => get_current_user_id() ) );
        update_user_meta( $uid, '_persiano_loyalty_log', array_slice( $log, 0, 100 ) );
    }

    public static function adjust() {
        $uid = absint( $_POST['customer_id'] ?? 0 );
        check_admin_referer( 'persiano_loyalty_adjust_' . $uid );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'No permission' ); }
        $delta  = intval( $_POST['points'] ?? 0 );
        $reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
        $before = max( 0, (int) get_user_meta( $uid, '_persiano_loyalty_balance', true ) );
        $after  = max( 0, $before + $delta );
        $actual = $after - $before;
        update_user_meta( $uid, '_persiano_loyalty_balance', $after );
        if ( $actual > 0 ) { update_user_meta( $uid, '_persiano_loyalty_lifetime', max( 0, (int) get_user_meta( $uid, '_persiano_loyalty_lifetime', true ) ) + $actual ); }
        self::add_log( $uid, $actual, $reason . ' (admin)' );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&customer_id=' . $uid ) ); exit;
    }

    public static function recalculate() {
        $uid = absint( $_POST['customer_id'] ?? 0 );
        check_admin_referer( 'persiano_loyalty_recalculate_' . $uid );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'No permission' ); }
        $orders = wc_get_orders( array( 'customer_id' => $uid, 'status' => array( 'processing', 'completed' ), 'limit' => -1 ) );
        $points = 0;
        foreach ( $orders as $order ) { foreach ( $order->get_items( 'line_item' ) as $item ) { $points += (int) floor( (float) $item->get_total() * Persiano_Hub_Customer_Accounts::POINTS_PER_DOLLAR ); } }
        $before = max( 0, (int) get_user_meta( $uid, '_persiano_loyalty_balance', true ) );
        update_user_meta( $uid, '_persiano_loyalty_balance', $points );
        update_user_meta( $uid, '_persiano_loyalty_lifetime', $points );
        self::add_log( $uid, $points - $before, __( 'Balance recalculated from existing paid orders', 'persiano-hub' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&customer_id=' . $uid ) ); exit;
    }
}
