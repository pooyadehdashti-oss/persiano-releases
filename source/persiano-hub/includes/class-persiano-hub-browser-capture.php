<?php
/**
 * Browser-assisted supplier price capture.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Browser_Capture {
    const PAGE_SLUG = 'persiano-hub-browser-capture';
    const TOKEN_OPTION = 'persiano_hub_browser_capture_token_hash';
    const TOKEN_HINT_OPTION = 'persiano_hub_browser_capture_token_hint';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 40 );
        add_action( 'admin_post_persiano_hub_browser_capture_token', array( __CLASS__, 'regenerate_token' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_filter( 'rest_pre_serve_request', array( __CLASS__, 'cors_headers' ), 10, 4 );
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Browser Price Capture', 'persiano-hub' ),
            __( 'Browser Price Capture', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    private static function token_hash( $token ) {
        return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
    }

    private static function current_token_valid( $token ) {
        $stored = (string) get_option( self::TOKEN_OPTION, '' );
        return $stored && $token && hash_equals( $stored, self::token_hash( $token ) );
    }

    private static function request_token( WP_REST_Request $request ) {
        $header = trim( (string) $request->get_header( 'authorization' ) );
        if ( preg_match( '/^Bearer\s+(.+)$/i', $header, $m ) ) {
            return trim( $m[1] );
        }
        return trim( (string) $request->get_header( 'x-persiano-capture-token' ) );
    }

    public static function permission( WP_REST_Request $request ) {
        if ( self::current_token_valid( self::request_token( $request ) ) ) {
            return true;
        }
        return new WP_Error( 'persiano_capture_unauthorized', __( 'Invalid browser-capture token.', 'persiano-hub' ), array( 'status' => 401 ) );
    }

    public static function register_routes() {
        register_rest_route( 'persiano-hub/v1', '/browser-capture/health', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'health' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
        ) );
        register_rest_route( 'persiano-hub/v1', '/browser-capture/ingredients', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'ingredients' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
            'args' => array( 'search' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
        ) );
        register_rest_route( 'persiano-hub/v1', '/browser-capture/ingredients', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'create_ingredient' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
        ) );
        register_rest_route( 'persiano-hub/v1', '/browser-capture', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array( __CLASS__, 'capture' ),
            'permission_callback' => array( __CLASS__, 'permission' ),
        ) );
    }

    public static function cors_headers( $served, $result, $request, $server ) {
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
        if ( $origin && ( 0 === strpos( $origin, 'chrome-extension://' ) || 0 === strpos( $origin, 'moz-extension://' ) ) ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Vary: Origin' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-Persiano-Capture-Token' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
        }
        return $served;
    }

    public static function health() {
        return rest_ensure_response( array(
            'ok' => true,
            'site' => get_bloginfo( 'name' ),
            'version' => defined( 'PERSIANO_HUB_VERSION' ) ? PERSIANO_HUB_VERSION : '',
            'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'CAD',
        ) );
    }

    public static function ingredients( WP_REST_Request $request ) {
        $search = trim( (string) $request->get_param( 'search' ) );
        $args = array(
            'post_type' => Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
            'post_status' => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => 30,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
        );
        if ( '' !== $search ) { $args['s'] = $search; }
        $rows = array();
        foreach ( get_posts( $args ) as $id ) {
            if ( get_post_meta( $id, '_persiano_ing_merged_to', true ) ) { continue; }
            $rows[] = array(
                'id' => (int) $id,
                'name' => get_the_title( $id ),
                'canonical_id' => class_exists( 'Persiano_Hub_Ingredient_Master' ) ? Persiano_Hub_Ingredient_Master::canonical_id( $id ) : '',
                'unit' => get_post_meta( $id, Persiano_Hub_Costing::ING_BASE_UNIT, true ),
                'family' => get_post_meta( $id, '_persiano_ing_family', true ),
                'category' => get_post_meta( $id, Persiano_Hub_Costing::ING_CATEGORY, true ),
            );
        }
        return rest_ensure_response( array( 'items' => $rows ) );
    }

    public static function create_ingredient( WP_REST_Request $request ) {
        $data = (array) $request->get_json_params();
        $name = sanitize_text_field( $data['name'] ?? '' );
        $family = sanitize_text_field( $data['family'] ?? '' );
        $category = sanitize_text_field( $data['category'] ?? '' );
        $unit = self::normalize_unit( $data['unit'] ?? 'g' );
        $confirm = ! empty( $data['confirm_similar'] );
        if ( '' === $name ) {
            return new WP_Error( 'persiano_capture_name', __( 'Ingredient name is required.', 'persiano-hub' ), array( 'status' => 400 ) );
        }
        $similar = array();
        foreach ( get_posts( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_status'=>array('publish','draft','private'), 'posts_per_page'=>50, 's'=>$name, 'fields'=>'ids', 'no_found_rows'=>true ) ) as $id ) {
            if ( get_post_meta( $id, '_persiano_ing_merged_to', true ) ) { continue; }
            similar_text( strtolower( $name ), strtolower( get_the_title( $id ) ), $pct );
            if ( $pct >= 55 ) {
                $similar[] = array( 'id'=>(int)$id, 'name'=>get_the_title($id), 'family'=>get_post_meta($id,'_persiano_ing_family',true), 'unit'=>get_post_meta($id,Persiano_Hub_Costing::ING_BASE_UNIT,true) );
            }
        }
        if ( $similar && ! $confirm ) {
            return new WP_Error( 'persiano_capture_similar', __( 'Similar ingredients already exist. Confirm before creating a separate master ingredient.', 'persiano-hub' ), array( 'status'=>409, 'similar'=>$similar ) );
        }
        $id = wp_insert_post( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_status'=>'publish', 'post_title'=>$name ), true );
        if ( is_wp_error( $id ) ) { return $id; }
        update_post_meta( $id, Persiano_Hub_Costing::ING_BASE_UNIT, $unit ?: 'g' );
        if ( $family ) { update_post_meta( $id, '_persiano_ing_family', $family ); }
        if ( $category ) { update_post_meta( $id, Persiano_Hub_Costing::ING_CATEGORY, $category ); }
        update_post_meta( $id, '_persiano_ing_type', 'purchased' );
        if ( class_exists( 'Persiano_Hub_Ingredient_Master' ) ) { Persiano_Hub_Ingredient_Master::ensure_canonical_id( $id ); }
        return rest_ensure_response( array( 'ok'=>true, 'item'=>array( 'id'=>(int)$id, 'name'=>$name, 'family'=>$family, 'category'=>$category, 'unit'=>$unit ?: 'g' ) ) );
    }

    private static function normalize_unit( $unit ) {
        $unit = strtolower( trim( sanitize_text_field( $unit ) ) );
        $map = array(
            'gram' => 'g', 'grams' => 'g', 'g' => 'g',
            'kilogram' => 'kg', 'kilograms' => 'kg', 'kgs' => 'kg', 'kg' => 'kg',
            'millilitre' => 'ml', 'millilitres' => 'ml', 'milliliter' => 'ml', 'milliliters' => 'ml', 'ml' => 'ml',
            'litre' => 'l', 'litres' => 'l', 'liter' => 'l', 'liters' => 'l', 'l' => 'l',
            'ounce' => 'oz', 'ounces' => 'oz', 'oz' => 'oz',
            'pound' => 'lb', 'pounds' => 'lb', 'lbs' => 'lb', 'lb' => 'lb',
            'each' => 'each', 'ea' => 'each', 'count' => 'each', 'ct' => 'each',
        );
        return $map[ $unit ] ?? $unit;
    }

    private static function normalize_supplier( $supplier, $url = '' ) {
        $supplier = trim( sanitize_text_field( $supplier ) );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( false !== strpos( $host, 'walmart.' ) || preg_match( '/\bwalmart\b/i', $supplier ) ) { return 'Walmart Canada'; }
        if ( false !== strpos( $host, 'costco.' ) || preg_match( '/\bcostco\b/i', $supplier ) ) { return 'Costco Canada'; }
        if ( false !== strpos( $host, 'realcanadiansuperstore.' ) || preg_match( '/real canadian superstore/i', $supplier ) ) { return 'Real Canadian Superstore'; }
        return $supplier;
    }

    public static function capture( WP_REST_Request $request ) {
        $data = (array) $request->get_json_params();
        $ingredient_id = absint( $data['ingredient_id'] ?? 0 );
        if ( ! $ingredient_id || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $ingredient_id ) ) {
            return new WP_Error( 'persiano_capture_ingredient', __( 'Choose a valid Ingredient Master record.', 'persiano-hub' ), array( 'status' => 400 ) );
        }
        $url = esc_url_raw( $data['url'] ?? '' );
        $parts = $url ? wp_parse_url( $url ) : array();
        if ( ! $url || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
            return new WP_Error( 'persiano_capture_url', __( 'A valid public product URL is required.', 'persiano-hub' ), array( 'status' => 400 ) );
        }
        $name = sanitize_text_field( $data['name'] ?? '' );
        $supplier = self::normalize_supplier( $data['supplier'] ?? '', $url );
        $brand = sanitize_text_field( $data['brand'] ?? '' );
        $qty = (float) ( $data['package_quantity'] ?? 0 );
        $unit = self::normalize_unit( $data['package_unit'] ?? '' );
        $price = (float) ( $data['price'] ?? 0 );
        $regular = (float) ( $data['regular_price'] ?? 0 );
        if ( ! $name || ! $supplier || $qty <= 0 || ! $unit || $price <= 0 ) {
            return new WP_Error( 'persiano_capture_fields', __( 'Product name, supplier/source, package quantity, package unit and price are required.', 'persiano-hub' ), array( 'status' => 400 ) );
        }

        $existing = get_posts( array(
            'post_type' => Persiano_Hub_Price_Feeds::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => Persiano_Hub_Price_Feeds::META_URL,
            'meta_value' => $url,
            'no_found_rows' => true,
        ) );
        $source_id = $existing ? (int) $existing[0] : wp_insert_post( array(
            'post_type' => Persiano_Hub_Price_Feeds::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $name,
        ), true );
        if ( is_wp_error( $source_id ) ) { return $source_id; }
        wp_update_post( array( 'ID' => $source_id, 'post_title' => $name ) );
        $meta = array(
            Persiano_Hub_Price_Feeds::META_URL => $url,
            Persiano_Hub_Price_Feeds::META_CURRENT_URL => $url,
            Persiano_Hub_Price_Feeds::META_STATUS => 'captured',
            Persiano_Hub_Price_Feeds::META_ACTIVE => 0,
            Persiano_Hub_Price_Feeds::META_INGREDIENT_ID => $ingredient_id,
            Persiano_Hub_Price_Feeds::META_SUPPLIER => $supplier,
            Persiano_Hub_Price_Feeds::META_NAME => $name,
            Persiano_Hub_Price_Feeds::META_BRAND => $brand,
            Persiano_Hub_Price_Feeds::META_SKU => sanitize_text_field( $data['sku'] ?? '' ),
            Persiano_Hub_Price_Feeds::META_GTIN => sanitize_text_field( $data['gtin'] ?? '' ),
            Persiano_Hub_Price_Feeds::META_PACKAGE_QTY => $qty,
            Persiano_Hub_Price_Feeds::META_PACKAGE_UNIT => $unit,
            Persiano_Hub_Price_Feeds::META_PRICE => $price,
            Persiano_Hub_Price_Feeds::META_REGULAR_PRICE => max( 0, $regular ),
            Persiano_Hub_Price_Feeds::META_CURRENCY => sanitize_text_field( $data['currency'] ?? 'CAD' ),
            Persiano_Hub_Price_Feeds::META_AVAILABILITY => sanitize_key( $data['availability'] ?? 'in_stock' ),
            Persiano_Hub_Price_Feeds::META_IMAGE => esc_url_raw( $data['image'] ?? '' ),
            Persiano_Hub_Price_Feeds::META_LAST_SUCCESS => time(),
            Persiano_Hub_Price_Feeds::META_APPROVED_IDENTITY => 1,
        );
        foreach ( $meta as $key => $value ) { update_post_meta( $source_id, $key, $value ); }
        update_post_meta( $source_id, '_persiano_capture_method', 'browser_extension' );
        update_post_meta( $source_id, '_persiano_capture_page_title', sanitize_text_field( $data['page_title'] ?? '' ) );
        update_post_meta( $source_id, '_persiano_capture_observed_at', time() );
        update_post_meta( $source_id, '_persiano_capture_source_type', sanitize_key( $data['source_type'] ?? 'website_observation' ) );
        update_post_meta( $source_id, '_persiano_capture_channel', sanitize_text_field( $data['channel'] ?? '' ) );
        update_post_meta( $source_id, '_persiano_capture_variable_weight', ! empty( $data['variable_weight'] ) ? 1 : 0 );
        update_post_meta( $source_id, '_persiano_capture_unit_price', (float) ( $data['unit_price'] ?? 0 ) );
        update_post_meta( $source_id, '_persiano_capture_unit_price_unit', self::normalize_unit( $data['unit_price_unit'] ?? '' ) );
        update_post_meta( $source_id, '_persiano_capture_confidence', sanitize_key( $data['confidence'] ?? 'reviewed' ) );

        $applied = Persiano_Hub_Price_Feeds::apply_captured_source( $source_id, $ingredient_id );
        if ( ! $applied ) {
            return new WP_Error( 'persiano_capture_apply', __( 'The observation was saved, but the supplier package could not be calculated. Check the package size, unit and price.', 'persiano-hub' ), array( 'status' => 422, 'source_id' => $source_id ) );
        }
        return rest_ensure_response( array(
            'ok' => true,
            'source_id' => $source_id,
            'ingredient_id' => $ingredient_id,
            'ingredient_name' => get_the_title( $ingredient_id ),
            'message' => __( 'Price observation and supplier package saved.', 'persiano-hub' ),
        ) );
    }

    public static function regenerate_token() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_browser_capture_token' );
        $token = 'phc_' . wp_generate_password( 48, false, false );
        update_option( self::TOKEN_OPTION, self::token_hash( $token ), false );
        update_option( self::TOKEN_HINT_OPTION, substr( $token, 0, 10 ) . '…' . substr( $token, -6 ), false );
        set_transient( 'persiano_hub_browser_capture_new_token_' . get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&token-created=1' ) );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $token = get_transient( 'persiano_hub_browser_capture_new_token_' . get_current_user_id() );
        if ( $token ) { delete_transient( 'persiano_hub_browser_capture_new_token_' . get_current_user_id() ); }
        $hint = (string) get_option( self::TOKEN_HINT_OPTION, '' );
        echo '<div class="wrap"><h1>' . esc_html__( 'Browser Price Capture', 'persiano-hub' ) . '</h1>';
        echo '<p>' . esc_html__( 'Capture product name, package size and price from a page open in your browser, then save it to a selected Ingredient Master record.', 'persiano-hub' ) . '</p>';
        if ( $token ) {
            echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Copy this token now. It will not be shown again.', 'persiano-hub' ) . '</strong></p><p><input class="large-text code" readonly value="' . esc_attr( $token ) . '" onclick="this.select()"></p></div>';
        }
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><th style="width:220px">' . esc_html__( 'Site URL', 'persiano-hub' ) . '</th><td><code>' . esc_html( home_url() ) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__( 'REST endpoint', 'persiano-hub' ) . '</th><td><code>' . esc_html( rest_url( 'persiano-hub/v1/browser-capture' ) ) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__( 'Current token', 'persiano-hub' ) . '</th><td>' . ( $hint ? '<code>' . esc_html( $hint ) . '</code>' : esc_html__( 'Not generated', 'persiano-hub' ) ) . '</td></tr>';
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px"><input type="hidden" name="action" value="persiano_hub_browser_capture_token">';
        wp_nonce_field( 'persiano_hub_browser_capture_token' );
        submit_button( $hint ? __( 'Regenerate capture token', 'persiano-hub' ) : __( 'Generate capture token', 'persiano-hub' ), 'primary', 'submit', false );
        echo '</form>';
        echo '<h2>' . esc_html__( 'How to use', 'persiano-hub' ) . '</h2><ol><li>' . esc_html__( 'Install the Persiano Price Capture browser extension.', 'persiano-hub' ) . '</li><li>' . esc_html__( 'Enter this site URL and the generated token in extension settings.', 'persiano-hub' ) . '</li><li>' . esc_html__( 'Open a retailer product page and click the extension.', 'persiano-hub' ) . '</li><li>' . esc_html__( 'Review the detected fields, choose an ingredient, and save.', 'persiano-hub' ) . '</li></ol>';
        echo '<p><strong>' . esc_html__( 'Security:', 'persiano-hub' ) . '</strong> ' . esc_html__( 'The token can create price observations and supplier packages, but it cannot access orders, customers or WordPress administration. Regenerating it immediately invalidates the previous token.', 'persiano-hub' ) . '</p>';
        echo '</div>';
    }
}
