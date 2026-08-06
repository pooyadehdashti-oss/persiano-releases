<?php
/**
 * Persistent online product-price sources and background extraction queue.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Price_Feeds {
    const POST_TYPE = 'persiano_price_src';
    const PAGE_SLUG = 'persiano-hub-price-feeds';
    const PROCESS_HOOK = 'persiano_hub_process_price_source';
    const SWEEP_HOOK = 'persiano_hub_price_source_sweep';
    const RECALC_HOOK = 'persiano_hub_price_source_recalculate_recipes';

    const META_URL = '_persiano_feed_url';
    const META_CURRENT_URL = '_persiano_feed_current_url';
    const META_STATUS = '_persiano_feed_status';
    const META_ACTIVE = '_persiano_feed_active';
    const META_FREQUENCY = '_persiano_feed_frequency';
    const META_INGREDIENT_ID = '_persiano_feed_ingredient_id';
    const META_SUPPLIER = '_persiano_feed_supplier';
    const META_NAME = '_persiano_feed_product_name';
    const META_BRAND = '_persiano_feed_brand';
    const META_SKU = '_persiano_feed_sku';
    const META_GTIN = '_persiano_feed_gtin';
    const META_PACKAGE_QTY = '_persiano_feed_package_qty';
    const META_PACKAGE_UNIT = '_persiano_feed_package_unit';
    const META_PRICE = '_persiano_feed_price';
    const META_REGULAR_PRICE = '_persiano_feed_regular_price';
    const META_CURRENCY = '_persiano_feed_currency';
    const META_AVAILABILITY = '_persiano_feed_availability';
    const META_IMAGE = '_persiano_feed_image';
    const META_LAST_ATTEMPT = '_persiano_feed_last_attempt';
    const META_LAST_SUCCESS = '_persiano_feed_last_success';
    const META_FAILURES = '_persiano_feed_failures';
    const META_LAST_ERROR = '_persiano_feed_last_error';
    const META_HTTP_CODE = '_persiano_feed_http_code';
    const META_APPROVED_IDENTITY = '_persiano_feed_approved_identity';
    const META_LAST_RECORDED_SIGNATURE = '_persiano_feed_last_recorded_signature';
    const META_SUGGESTED_INGREDIENT = '_persiano_feed_suggested_ingredient';
    const META_LAST_CHANGE = '_persiano_feed_last_change';
    const META_PREVIOUS_PRICE = '_persiano_feed_previous_price';
    const META_PRICE_CHANGE = '_persiano_feed_price_change';
    const META_PRICE_CHANGE_PCT = '_persiano_feed_price_change_pct';
    const META_PRICE_CHANGED_AT = '_persiano_feed_price_changed_at';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ), 12 );
        add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 45 );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 32 );
        add_action( 'admin_post_persiano_hub_feed_add_urls', array( __CLASS__, 'add_urls' ) );
        add_action( 'admin_post_persiano_hub_feed_queue', array( __CLASS__, 'queue_selected' ) );
        add_action( 'admin_post_persiano_hub_feed_approve', array( __CLASS__, 'approve_selected' ) );
        add_action( 'admin_post_persiano_hub_feed_pause', array( __CLASS__, 'pause_selected' ) );
        add_action( 'admin_post_persiano_hub_feed_delete', array( __CLASS__, 'delete_selected' ) );
        add_action( 'admin_post_persiano_hub_feed_export', array( __CLASS__, 'export_csv' ) );
        add_action( self::PROCESS_HOOK, array( __CLASS__, 'process_source' ), 10, 1 );
        add_action( self::SWEEP_HOOK, array( __CLASS__, 'scheduled_sweep' ) );
        add_action( self::RECALC_HOOK, array( __CLASS__, 'recalculate_recipes' ) );
    }

    public static function register_post_type() {
        if ( post_type_exists( self::POST_TYPE ) ) { return; }
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array( 'name' => __( 'Price Sources', 'persiano-hub' ), 'singular_name' => __( 'Price Source', 'persiano-hub' ) ),
                'public' => false,
                'show_ui' => false,
                'show_in_menu' => false,
                'supports' => array( 'title' ),
                'capability_type' => 'post',
                'map_meta_cap' => true,
            )
        );
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Price Feeds', 'persiano-hub' ),
            __( 'Price Feeds', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage price feeds.', 'persiano-hub' ) );
        }
    }

    public static function page_url( $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
    }

    public static function ensure_schedule() {
        if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
            wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::SWEEP_HOOK );
        }
    }

    private static function schedule_source( $source_id, $delay = 1 ) {
        $source_id = absint( $source_id );
        if ( ! $source_id ) { return; }
        $args = array( $source_id );
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + max( 1, (int) $delay ), self::PROCESS_HOOK, $args, 'persiano-hub', true );
            return;
        }
        if ( ! wp_next_scheduled( self::PROCESS_HOOK, $args ) ) {
            wp_schedule_single_event( time() + max( 1, (int) $delay ), self::PROCESS_HOOK, $args );
        }
    }

    private static function schedule_recalculation() {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + 20, self::RECALC_HOOK, array(), 'persiano-hub', true );
        } elseif ( ! wp_next_scheduled( self::RECALC_HOOK ) ) {
            wp_schedule_single_event( time() + 20, self::RECALC_HOOK );
        }
    }

    public static function recalculate_recipes() {
        if ( ! class_exists( 'Persiano_Hub_Costing' ) ) { return; }
        $ids = get_posts( array(
            'post_type' => Persiano_Hub_Costing::RECIPE_POST_TYPE,
            'post_status' => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ) );
        foreach ( $ids as $id ) { Persiano_Hub_Costing::recalculate_recipe( $id ); }
    }

    private static function canonical_url( $url ) {
        $url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
        if ( ! $url || ! wp_http_validate_url( $url ) ) { return ''; }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return ''; }
        $scheme = strtolower( $parts['scheme'] );
        $host = strtolower( rtrim( $parts['host'], '.' ) );
        $port = ! empty( $parts['port'] ) && ! ( 80 === (int) $parts['port'] && 'http' === $scheme ) && ! ( 443 === (int) $parts['port'] && 'https' === $scheme ) ? ':' . absint( $parts['port'] ) : '';
        $path = isset( $parts['path'] ) ? preg_replace( '~/{2,}~', '/', $parts['path'] ) : '/';
        if ( '/' !== $path ) { $path = rtrim( $path, '/' ); }
        $query = array();
        if ( ! empty( $parts['query'] ) ) {
            parse_str( $parts['query'], $query );
            foreach ( array_keys( $query ) as $key ) {
                $lower = strtolower( (string) $key );
                if ( 0 === strpos( $lower, 'utm_' ) || 0 === strpos( $lower, 'mc_' ) || in_array( $lower, array( 'gclid','fbclid','msclkid','ref_src' ), true ) ) { unset( $query[ $key ] ); }
            }
            ksort( $query );
        }
        return esc_url_raw( $scheme . '://' . $host . $port . ( $path ?: '/' ) . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' ) );
    }

    private static function extract_urls( $text ) {
        $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
        preg_match_all( '~https?://[^\s<>"\']+~i', $text, $matches );
        $urls = array();
        foreach ( (array) ( $matches[0] ?? array() ) as $url ) {
            $url = rtrim( trim( $url ), ".,;:!?)]}'\"" );
            $url = self::canonical_url( $url );
            if ( $url ) { $urls[ strtolower( $url ) ] = $url; }
        }
        return array_values( $urls );
    }

    private static function find_source_by_url( $url ) {
        $url = self::canonical_url( $url );
        if ( ! $url ) { return 0; }
        $ids = get_posts( array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'OR',
                array( 'key' => self::META_URL, 'value' => $url ),
                array( 'key' => self::META_CURRENT_URL, 'value' => $url ),
            ),
            'no_found_rows' => true,
        ) );
        if ( $ids ) { return (int) $ids[0]; }
        foreach ( get_posts( array( 'post_type'=>self::POST_TYPE,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true ) ) as $source_id ) {
            $saved = get_post_meta( $source_id, self::META_CURRENT_URL, true ) ?: get_post_meta( $source_id, self::META_URL, true );
            if ( $saved && self::canonical_url( $saved ) === $url ) { return (int) $source_id; }
        }
        return 0;
    }

    private static function source_title( $url ) {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        $path = $path ? basename( $path ) : '';
        return trim( preg_replace( '/^www\./i', '', $host ) . ( $path ? ' — ' . rawurldecode( $path ) : '' ) );
    }

    public static function add_urls() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_feed_add_urls' );
        $urls = self::extract_urls( wp_unslash( $_POST['price_feed_urls'] ?? '' ) );
        $urls = array_slice( $urls, 0, 200 );
        $created = 0; $requeued = 0; $invalid = 0; $failed = 0; $last_error = '';
        foreach ( $urls as $index => $url ) {
            if ( ! wp_http_validate_url( $url ) ) { $invalid++; continue; }
            $id = self::find_source_by_url( $url );
            if ( $id ) {
                update_post_meta( $id, self::META_STATUS, 'queued' );
                update_post_meta( $id, self::META_ACTIVE, 1 );
                delete_post_meta( $id, self::META_LAST_ERROR );
                $requeued++;
            } else {
                $id = wp_insert_post( array(
                    'post_type' => self::POST_TYPE,
                    'post_status' => 'publish',
                    'post_title' => self::source_title( $url ),
                ), true );
                if ( is_wp_error( $id ) ) {
                    $failed++;
                    $last_error = $id->get_error_message();
                    continue;
                }
                update_post_meta( $id, self::META_URL, $url );
                update_post_meta( $id, self::META_CURRENT_URL, $url );
                update_post_meta( $id, self::META_STATUS, 'queued' );
                update_post_meta( $id, self::META_ACTIVE, 1 );
                update_post_meta( $id, self::META_FREQUENCY, 'manual' );
                update_post_meta( $id, self::META_FAILURES, 0 );
                $created++;
            }
            self::schedule_source( $id, 2 + ( $index * 3 ) );
        }
        $redirect_args = array( 'added' => $created, 'requeued' => $requeued, 'invalid' => $invalid, 'failed' => $failed );
        if ( $last_error ) { $redirect_args['feed_error'] = rawurlencode( $last_error ); }
        wp_safe_redirect( self::page_url( $redirect_args ) );
        exit;
    }

    private static function selected_ids() {
        $ids = isset( $_POST['source_ids'] ) && is_array( $_POST['source_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['source_ids'] ) ) : array();
        return array_values( array_filter( array_unique( $ids ) ) );
    }

    private static function update_linked_supplier_item( $source_id, $mode = 'activate' ) {
        $ingredient_id = absint( get_post_meta( $source_id, self::META_INGREDIENT_ID, true ) );
        if ( ! $ingredient_id ) { return; }
        $items = get_post_meta( $ingredient_id, '_persiano_supplier_items', true );
        if ( ! is_array( $items ) ) { return; }
        $changed = false;
        foreach ( $items as $index => $item ) {
            if ( absint( $item['price_source_id'] ?? 0 ) !== absint( $source_id ) ) { continue; }
            if ( 'remove' === $mode ) { unset( $items[ $index ] ); }
            else { $items[ $index ]['active'] = 'activate' === $mode ? 1 : 0; }
            $changed = true;
        }
        if ( $changed ) { update_post_meta( $ingredient_id, '_persiano_supplier_items', array_values( $items ) ); }
    }

    public static function queue_selected() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_feed_bulk' );
        $ids = self::selected_ids();
        if ( ! $ids && ! empty( $_POST['check_all'] ) ) {
            $ids = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => self::META_ACTIVE, 'meta_value' => 1 ) );
        }
        $frequencies = isset( $_POST['frequency'] ) && is_array( $_POST['frequency'] ) ? wp_unslash( $_POST['frequency'] ) : array();
        foreach ( $ids as $index => $id ) {
            if ( isset( $frequencies[ $id ] ) ) {
                $frequency = sanitize_key( $frequencies[ $id ] );
                if ( in_array( $frequency, array( 'manual','daily','weekly','monthly' ), true ) ) { update_post_meta( $id, self::META_FREQUENCY, $frequency ); }
            }
            update_post_meta( $id, self::META_STATUS, 'queued' );
            delete_post_meta( $id, self::META_LAST_ERROR );
            self::schedule_source( $id, 1 + ( $index * 3 ) );
        }
        $return_to = isset( $_POST['return_to'] ) ? esc_url_raw( wp_unslash( $_POST['return_to'] ) ) : '';
        $redirect = $return_to ? add_query_arg( 'price_feeds_queued', count( $ids ), $return_to ) : self::page_url( array( 'queued' => count( $ids ) ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function pause_selected() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_feed_bulk' );
        $ids = self::selected_ids();
        foreach ( $ids as $id ) {
            update_post_meta( $id, self::META_ACTIVE, 0 );
            update_post_meta( $id, self::META_STATUS, 'paused' );
        }
        wp_safe_redirect( self::page_url( array( 'paused' => count( $ids ) ) ) );
        exit;
    }

    public static function delete_selected() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_feed_bulk' );
        $ids = self::selected_ids();
        foreach ( $ids as $id ) { self::update_linked_supplier_item( $id, 'remove' ); wp_delete_post( $id, true ); }
        wp_safe_redirect( self::page_url( array( 'deleted' => count( $ids ) ) ) );
        exit;
    }

    private static function normalize_unit( $unit ) {
        $unit = strtolower( trim( (string) $unit ) );
        $unit = str_replace( array( 'litres', 'liters', 'litre', 'liter' ), 'l', $unit );
        $unit = str_replace( array( 'kilograms', 'kilogram', 'kgs' ), 'kg', $unit );
        $unit = str_replace( array( 'grams', 'gram' ), 'g', $unit );
        $unit = str_replace( array( 'millilitres', 'milliliters', 'millilitre', 'milliliter' ), 'ml', $unit );
        $unit = str_replace( array( 'ounces', 'ounce' ), 'oz', $unit );
        $unit = str_replace( array( 'pounds', 'pound', 'lbs' ), 'lb', $unit );
        if ( in_array( $unit, array( 'count', 'ct', 'pc', 'pcs', 'piece', 'pieces', 'pack', 'package' ), true ) ) { return 'each'; }
        return in_array( $unit, array( 'g','kg','oz','lb','ml','l','each' ), true ) ? $unit : '';
    }

    private static function package_from_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
        if ( preg_match( '/(\d+(?:[\.,]\d+)?)\s*[x×]\s*(\d+(?:[\.,]\d+)?)\s*(kg|g|ml|l|oz|lb|ct|count|pack|pcs?)\b/i', $text, $m ) ) {
            $count = (float) str_replace( ',', '.', $m[1] );
            $qty = (float) str_replace( ',', '.', $m[2] );
            $unit = self::normalize_unit( $m[3] );
            return array( 'quantity' => $count * $qty, 'unit' => $unit );
        }
        if ( preg_match_all( '/(\d+(?:[\.,]\d+)?)\s*(kg|g|ml|l|oz|lb|ct|count|pack|pcs?)\b/i', $text, $matches, PREG_SET_ORDER ) ) {
            $m = end( $matches );
            return array( 'quantity' => (float) str_replace( ',', '.', $m[1] ), 'unit' => self::normalize_unit( $m[2] ) );
        }
        return array( 'quantity' => 0.0, 'unit' => '' );
    }

    private static function meta_content( $html, $keys ) {
        foreach ( (array) $keys as $key ) {
            $quoted = preg_quote( $key, '/' );
            if ( preg_match( '/<meta[^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $html, $m ) || preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]*>/i', $html, $m ) ) {
                return html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
            }
        }
        return '';
    }

    private static function jsonld_product( $value ) {
        if ( ! is_array( $value ) ) { return array(); }
        $type = $value['@type'] ?? '';
        $types = is_array( $type ) ? $type : array( $type );
        foreach ( $types as $candidate ) {
            if ( 'product' === strtolower( (string) $candidate ) ) { return $value; }
        }
        foreach ( array( '@graph', 'itemListElement', 'mainEntity', 'item' ) as $key ) {
            if ( empty( $value[ $key ] ) ) { continue; }
            $found = self::jsonld_product( $value[ $key ] );
            if ( $found ) { return $found; }
        }
        foreach ( $value as $child ) {
            if ( is_array( $child ) ) {
                $found = self::jsonld_product( $child );
                if ( $found ) { return $found; }
            }
        }
        return array();
    }

    private static function parse_price( $value ) {
        if ( is_array( $value ) ) { $value = $value['value'] ?? $value['price'] ?? ''; }
        $value = preg_replace( '/[^0-9,\.\-]/', '', (string) $value );
        if ( substr_count( $value, ',' ) === 1 && false === strpos( $value, '.' ) ) { $value = str_replace( ',', '.', $value ); }
        else { $value = str_replace( ',', '', $value ); }
        return max( 0, (float) $value );
    }

    private static function schema_measurement_text( $value ) {
        if ( is_scalar( $value ) ) { return sanitize_text_field( (string) $value ); }
        if ( ! is_array( $value ) ) { return ''; }
        $amount = $value['value'] ?? $value['amount'] ?? '';
        $unit = $value['unitText'] ?? $value['unitCode'] ?? $value['unit'] ?? '';
        if ( '' !== (string) $amount ) { return trim( sanitize_text_field( (string) $amount ) . ' ' . sanitize_text_field( (string) $unit ) ); }
        return sanitize_text_field( $value['name'] ?? '' );
    }

    private static function supplier_from_url( $url ) {
        $host = preg_replace( '/^www\./i', '', (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $root = strtolower( preg_replace( '/\.(ca|com|net|org)$/i', '', $host ) );
        $known = array(
            'realcanadiansuperstore' => 'Real Canadian Superstore', 'wholesaleclub' => 'Wholesale Club', 'walmart' => 'Walmart',
            'costco' => 'Costco', 'saveonfoods' => 'Save-On-Foods', 'nofrills' => 'No Frills', 'loblaws' => 'Loblaws',
            'tntsupermarket' => 'T&T Supermarket', 'amazon' => 'Amazon',
        );
        if ( isset( $known[ $root ] ) ) { return $known[ $root ]; }
        return sanitize_text_field( ucwords( str_replace( array( '-', '.' ), ' ', $root ) ) );
    }

    private static function parse_html( $html, $url ) {
        $product = array();
        if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $scripts ) ) {
            foreach ( $scripts[1] as $script ) {
                $decoded = json_decode( html_entity_decode( trim( $script ), ENT_QUOTES, 'UTF-8' ), true );
                if ( is_array( $decoded ) ) {
                    $product = self::jsonld_product( $decoded );
                    if ( $product ) { break; }
                }
            }
        }
        $offers = $product['offers'] ?? array();
        if ( isset( $offers[0] ) ) { $offers = $offers[0]; }
        $brand = $product['brand'] ?? '';
        if ( is_array( $brand ) ) { $brand = $brand['name'] ?? ''; }
        $name = sanitize_text_field( $product['name'] ?? '' );
        if ( ! $name ) { $name = sanitize_text_field( self::meta_content( $html, array( 'og:title', 'twitter:title' ) ) ); }
        if ( ! $name && preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) { $name = sanitize_text_field( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) ); }
        $price_spec = is_array( $offers['priceSpecification'] ?? null ) ? $offers['priceSpecification'] : array();
        $price = self::parse_price( $offers['price'] ?? $offers['lowPrice'] ?? $price_spec['price'] ?? $product['price'] ?? self::meta_content( $html, array( 'product:price:amount', 'og:price:amount' ) ) );
        $regular = self::parse_price( $offers['highPrice'] ?? self::meta_content( $html, array( 'product:original_price:amount', 'product:regular_price:amount' ) ) );
        $currency = sanitize_text_field( $offers['priceCurrency'] ?? self::meta_content( $html, array( 'product:price:currency', 'og:price:currency' ) ) );
        if ( ! $currency && function_exists( 'get_woocommerce_currency' ) ) { $currency = get_woocommerce_currency(); }
        $availability = sanitize_text_field( $offers['availability'] ?? self::meta_content( $html, array( 'product:availability' ) ) );
        $availability = strtolower( preg_replace( '~^https?://schema.org/~i', '', $availability ) );
        $sku = sanitize_text_field( $product['sku'] ?? $product['mpn'] ?? '' );
        $gtin = sanitize_text_field( $product['gtin13'] ?? $product['gtin12'] ?? $product['gtin14'] ?? $product['gtin'] ?? '' );
        $image = $product['image'] ?? self::meta_content( $html, array( 'og:image', 'twitter:image' ) );
        if ( is_array( $image ) ) { $image = reset( $image ); }
        $package_text = implode( ' ', array_filter( array( $name, self::schema_measurement_text( $product['size'] ?? '' ), self::schema_measurement_text( $product['weight'] ?? '' ), self::schema_measurement_text( $product['description'] ?? '' ) ) ) );
        $package = self::package_from_text( $package_text );
        return array(
            'name' => $name,
            'brand' => sanitize_text_field( $brand ),
            'supplier' => self::supplier_from_url( $url ),
            'sku' => $sku,
            'gtin' => $gtin,
            'package_quantity' => (float) $package['quantity'],
            'package_unit' => sanitize_key( $package['unit'] ),
            'price' => $price,
            'regular_price' => $regular,
            'currency' => $currency ?: 'CAD',
            'availability' => $availability ?: 'unknown',
            'image' => esc_url_raw( $image ),
        );
    }

    private static function identity_from_data( $data ) {
        return array(
            'name' => sanitize_text_field( $data['name'] ?? '' ),
            'brand' => sanitize_text_field( $data['brand'] ?? '' ),
            'sku' => sanitize_text_field( $data['sku'] ?? '' ),
            'gtin' => sanitize_text_field( $data['gtin'] ?? '' ),
            'package_quantity' => (float) ( $data['package_quantity'] ?? 0 ),
            'package_unit' => sanitize_key( $data['package_unit'] ?? '' ),
        );
    }

    private static function identity_changed( $approved, $current ) {
        if ( ! is_array( $approved ) || empty( $approved ) ) { return false; }
        foreach ( array( 'sku', 'gtin' ) as $field ) {
            if ( ! empty( $approved[ $field ] ) && ! empty( $current[ $field ] ) && (string) $approved[ $field ] !== (string) $current[ $field ] ) { return true; }
        }
        if ( ! empty( $approved['package_unit'] ) && ! empty( $current['package_unit'] ) && $approved['package_unit'] !== $current['package_unit'] ) { return true; }
        if ( (float) ( $approved['package_quantity'] ?? 0 ) > 0 && (float) ( $current['package_quantity'] ?? 0 ) > 0 ) {
            $old = (float) $approved['package_quantity']; $new = (float) $current['package_quantity'];
            if ( abs( $old - $new ) > max( 0.01, $old * 0.02 ) ) { return true; }
        }
        $old_name = strtolower( preg_replace( '/\s+/', ' ', (string) ( $approved['name'] ?? '' ) ) );
        $new_name = strtolower( preg_replace( '/\s+/', ' ', (string) ( $current['name'] ?? '' ) ) );
        if ( $old_name && $new_name ) {
            similar_text( $old_name, $new_name, $percent );
            if ( $percent < 55 ) { return true; }
        }
        return false;
    }

    private static function save_extracted( $source_id, $data, $http_code, $current_url ) {
        $old_price = (float) get_post_meta( $source_id, self::META_PRICE, true );
        $new_price = max( 0, (float) ( $data['price'] ?? 0 ) );
        if ( $old_price > 0 && $new_price > 0 && abs( $new_price - $old_price ) >= 0.005 ) {
            $change = $new_price - $old_price;
            update_post_meta( $source_id, self::META_PREVIOUS_PRICE, $old_price );
            update_post_meta( $source_id, self::META_PRICE_CHANGE, $change );
            update_post_meta( $source_id, self::META_PRICE_CHANGE_PCT, ( $change / $old_price ) * 100 );
            update_post_meta( $source_id, self::META_PRICE_CHANGED_AT, time() );
        }
        $map = array(
            self::META_CURRENT_URL => $current_url,
            self::META_NAME => $data['name'] ?? '',
            self::META_BRAND => $data['brand'] ?? '',
            self::META_SUPPLIER => $data['supplier'] ?? '',
            self::META_SKU => $data['sku'] ?? '',
            self::META_GTIN => $data['gtin'] ?? '',
            self::META_PACKAGE_QTY => $data['package_quantity'] ?? 0,
            self::META_PACKAGE_UNIT => $data['package_unit'] ?? '',
            self::META_PRICE => $data['price'] ?? 0,
            self::META_REGULAR_PRICE => $data['regular_price'] ?? 0,
            self::META_CURRENCY => $data['currency'] ?? '',
            self::META_AVAILABILITY => $data['availability'] ?? '',
            self::META_IMAGE => $data['image'] ?? '',
            self::META_HTTP_CODE => $http_code,
            self::META_LAST_SUCCESS => time(),
            self::META_FAILURES => 0,
        );
        foreach ( $map as $key => $value ) { update_post_meta( $source_id, $key, $value ); }
        delete_post_meta( $source_id, self::META_LAST_ERROR );
        if ( ! get_post_meta( $source_id, self::META_INGREDIENT_ID, true ) && ! empty( $data['name'] ) && class_exists( 'Persiano_Hub_Costing' ) ) {
            $suggested = Persiano_Hub_Costing::find_matching_ingredient( $data['name'] );
            if ( $suggested ) { update_post_meta( $source_id, self::META_SUGGESTED_INGREDIENT, $suggested ); }
        }
        if ( ! empty( $data['name'] ) ) {
            wp_update_post( array( 'ID' => $source_id, 'post_title' => $data['name'] . ( ! empty( $data['supplier'] ) ? ' — ' . $data['supplier'] : '' ) ) );
        }
    }

    private static function response_final_url( $response, $fallback ) {
        if ( is_array( $response ) && isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
            $object = $response['http_response']->get_response_object();
            if ( is_object( $object ) && ! empty( $object->url ) ) { return esc_url_raw( $object->url ); }
        }
        return $fallback;
    }

    private static function failure( $source_id, $message, $http_code = 0, $permanent = false ) {
        $previous = (string) get_post_meta( $source_id, self::META_STATUS, true );
        $failures = (int) get_post_meta( $source_id, self::META_FAILURES, true ) + 1;
        update_post_meta( $source_id, self::META_FAILURES, $failures );
        update_post_meta( $source_id, self::META_LAST_ERROR, sanitize_text_field( $message ) );
        update_post_meta( $source_id, self::META_HTTP_CODE, (int) $http_code );
        update_post_meta( $source_id, self::META_LAST_ATTEMPT, time() );
        $status = ( $permanent || $failures >= 3 ) ? 'needs_attention' : 'retrying';
        update_post_meta( $source_id, self::META_STATUS, $status );
        if ( 'needs_attention' === $status ) { self::update_linked_supplier_item( $source_id, 'deactivate' ); }
        if ( 'needs_attention' === $status && 'needs_attention' !== $previous ) {
            self::notify( 'price_feed_unavailable', sprintf( 'Price source needs attention: %s', get_the_title( $source_id ) ), $message, $source_id );
        }
        if ( ! $permanent && $failures < 3 ) {
            self::schedule_source( $source_id, min( DAY_IN_SECONDS, (int) pow( 4, $failures ) * MINUTE_IN_SECONDS ) );
        }
    }

    private static function notify( $type, $message, $detail, $source_id ) {
        if ( class_exists( 'Persiano_Hub_Notifications' ) && method_exists( 'Persiano_Hub_Notifications', 'add_system_notification' ) ) {
            Persiano_Hub_Notifications::add_system_notification( $type, $message, $detail, self::page_url( array( 'source' => absint( $source_id ) ) ) );
        }
    }

    public static function process_source( $source_id ) {
        $source_id = absint( $source_id );
        if ( ! $source_id || self::POST_TYPE !== get_post_type( $source_id ) ) { return; }
        if ( ! (int) get_post_meta( $source_id, self::META_ACTIVE, true ) ) { return; }
        $url = esc_url_raw( get_post_meta( $source_id, self::META_CURRENT_URL, true ) ?: get_post_meta( $source_id, self::META_URL, true ) );
        if ( ! $url || ! wp_http_validate_url( $url ) ) {
            self::failure( $source_id, __( 'The saved URL is not valid.', 'persiano-hub' ), 0, true );
            return;
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'admin' ); }
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        update_post_meta( $source_id, self::META_STATUS, 'processing' );
        update_post_meta( $source_id, self::META_LAST_ATTEMPT, time() );
        $response = wp_safe_remote_get( $url, array(
            'timeout' => 35,
            'redirection' => 5,
            'limit_response_size' => 5 * MB_IN_BYTES,
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.7',
                'Accept-Language' => 'en-CA,en;q=0.9',
                'User-Agent' => 'PersianoHubPriceMonitor/' . ( defined( 'PERSIANO_HUB_VERSION' ) ? PERSIANO_HUB_VERSION : '1.0' ) . ' (' . home_url( '/' ) . ')',
            ),
        ) );
        if ( is_wp_error( $response ) ) {
            self::failure( $source_id, $response->get_error_message() );
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, array( 404, 410 ), true ) ) {
            self::failure( $source_id, sprintf( __( 'The retailer returned HTTP %d. The product page may have been removed.', 'persiano-hub' ), $code ), $code, true );
            return;
        }
        if ( in_array( $code, array( 401, 403, 429 ), true ) ) {
            self::failure( $source_id, sprintf( __( 'The retailer blocked or restricted automated access (HTTP %d).', 'persiano-hub' ), $code ), $code, 401 === $code );
            return;
        }
        if ( $code < 200 || $code >= 400 ) {
            self::failure( $source_id, sprintf( __( 'The retailer returned HTTP %d.', 'persiano-hub' ), $code ), $code );
            return;
        }
        $html = (string) wp_remote_retrieve_body( $response );
        if ( '' === trim( $html ) ) {
            self::failure( $source_id, __( 'The page returned no readable content.', 'persiano-hub' ), $code );
            return;
        }
        if ( preg_match( '/captcha|verify you are human|access denied|bot detection/i', substr( $html, 0, 250000 ) ) ) {
            self::failure( $source_id, __( 'The retailer requires a CAPTCHA or blocks automated checks.', 'persiano-hub' ), $code );
            return;
        }
        $current_url = self::response_final_url( $response, $url );
        $data = self::parse_html( $html, $current_url );
        if ( empty( $data['name'] ) ) {
            self::failure( $source_id, __( 'The page loaded, but a product name could not be extracted.', 'persiano-hub' ), $code );
            return;
        }
        $approved = get_post_meta( $source_id, self::META_APPROVED_IDENTITY, true );
        $current_identity = self::identity_from_data( $data );
        self::save_extracted( $source_id, $data, $code, $current_url );
        if ( (float) ( $data['price'] ?? 0 ) <= 0 || (float) ( $data['package_quantity'] ?? 0 ) <= 0 || ! self::normalize_unit( $data['package_unit'] ?? '' ) ) {
            update_post_meta( $source_id, self::META_STATUS, 'needs_review' );
            update_post_meta( $source_id, self::META_LAST_ERROR, __( 'The page loaded, but price or package size could not be extracted reliably.', 'persiano-hub' ) );
            return;
        }
        if ( is_array( $approved ) && $approved && self::identity_changed( $approved, $current_identity ) ) {
            update_post_meta( $source_id, self::META_STATUS, 'needs_review' );
            self::update_linked_supplier_item( $source_id, 'deactivate' );
            update_post_meta( $source_id, self::META_LAST_CHANGE, __( 'The product identity or package size changed.', 'persiano-hub' ) );
            self::notify( 'price_feed_product_changed', sprintf( 'Price source changed: %s', get_the_title( $source_id ) ), __( 'The retailer page appears to represent a different product or package size. Review it before accepting the new price.', 'persiano-hub' ), $source_id );
            return;
        }
        $ingredient_id = absint( get_post_meta( $source_id, self::META_INGREDIENT_ID, true ) );
        if ( $ingredient_id ) {
            self::apply_to_ingredient( $source_id, $ingredient_id, false );
            update_post_meta( $source_id, self::META_STATUS, self::status_from_availability( $data['availability'] ?? '' ) );
        } else {
            update_post_meta( $source_id, self::META_STATUS, 'needs_review' );
        }
    }

    private static function base_conversion( $unit ) {
        $unit = self::normalize_unit( $unit );
        $map = array(
            'g' => array( 'g', 1 ), 'kg' => array( 'g', 1000 ), 'oz' => array( 'g', 28.349523125 ), 'lb' => array( 'g', 453.59237 ),
            'ml' => array( 'ml', 1 ), 'l' => array( 'ml', 1000 ), 'each' => array( 'each', 1 ),
        );
        return $map[ $unit ] ?? array( '', 0 );
    }

    private static function source_data( $source_id ) {
        return array(
            'name' => get_post_meta( $source_id, self::META_NAME, true ),
            'brand' => get_post_meta( $source_id, self::META_BRAND, true ),
            'supplier' => get_post_meta( $source_id, self::META_SUPPLIER, true ),
            'sku' => get_post_meta( $source_id, self::META_SKU, true ),
            'gtin' => get_post_meta( $source_id, self::META_GTIN, true ),
            'package_quantity' => (float) get_post_meta( $source_id, self::META_PACKAGE_QTY, true ),
            'package_unit' => get_post_meta( $source_id, self::META_PACKAGE_UNIT, true ),
            'price' => (float) get_post_meta( $source_id, self::META_PRICE, true ),
            'regular_price' => (float) get_post_meta( $source_id, self::META_REGULAR_PRICE, true ),
            'currency' => get_post_meta( $source_id, self::META_CURRENCY, true ),
            'availability' => get_post_meta( $source_id, self::META_AVAILABILITY, true ),
            'image' => get_post_meta( $source_id, self::META_IMAGE, true ),
            'url' => get_post_meta( $source_id, self::META_CURRENT_URL, true ) ?: get_post_meta( $source_id, self::META_URL, true ),
        );
    }

    /** Apply a reviewed browser-captured source to an ingredient. */
    public static function apply_captured_source( $source_id, $ingredient_id ) {
        $source_id = absint( $source_id );
        $ingredient_id = absint( $ingredient_id );
        if ( ! $source_id || ! $ingredient_id || self::POST_TYPE !== get_post_type( $source_id ) ) { return false; }
        update_post_meta( $source_id, self::META_INGREDIENT_ID, $ingredient_id );
        update_post_meta( $source_id, self::META_APPROVED_IDENTITY, 1 );
        return self::apply_to_ingredient( $source_id, $ingredient_id, true );
    }

    private static function apply_to_ingredient( $source_id, $ingredient_id, $force_record ) {
        $ingredient_id = absint( $ingredient_id );
        if ( ! $ingredient_id || ! class_exists( 'Persiano_Hub_Costing' ) || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $ingredient_id ) ) { return false; }
        $data = self::source_data( $source_id );
        $qty = max( 0, (float) $data['package_quantity'] );
        $unit = self::normalize_unit( $data['package_unit'] );
        $price = max( 0, (float) $data['price'] );
        list( $base_unit, $multiplier ) = self::base_conversion( $unit );
        $normalized = $qty > 0 && $multiplier > 0 && $price > 0 ? $price / ( $qty * $multiplier ) : 0;
        if ( $qty <= 0 || ! $unit || $price <= 0 || ! $base_unit || $normalized <= 0 ) { return false; }
        $items = get_post_meta( $ingredient_id, '_persiano_supplier_items', true );
        $items = is_array( $items ) ? $items : array();
        $match = -1;
        foreach ( $items as $index => $item ) {
            if ( absint( $item['price_source_id'] ?? 0 ) === $source_id || ( ! empty( $item['product_url'] ) && $item['product_url'] === $data['url'] ) ) { $match = $index; break; }
        }
        $entry = array(
            'record_id' => $match >= 0 ? ( $items[ $match ]['record_id'] ?? wp_generate_uuid4() ) : wp_generate_uuid4(),
            'supplier_id' => 0,
            'supplier_name' => sanitize_text_field( $data['supplier'] ),
            'supplier_item_code' => sanitize_text_field( $data['sku'] ?: $data['gtin'] ),
            'brand' => sanitize_text_field( $data['brand'] ),
            'item_name' => sanitize_text_field( $data['name'] ),
            'package_quantity' => $qty,
            'package_unit' => $unit,
            'current_price' => $price,
            'regular_price' => max( 0, (float) $data['regular_price'] ),
            'tax_amount' => 0,
            'normalized_unit_cost' => $normalized,
            'normalized_unit' => $base_unit,
            'product_url' => esc_url_raw( $data['url'] ),
            'price_source_id' => $source_id,
            'availability' => sanitize_key( $data['availability'] ),
            'last_checked' => time(),
            'active' => 'out_of_stock' === self::status_from_availability( $data['availability'] ?? '' ) ? 0 : 1,
            'notes' => __( 'Maintained by Persiano Price Feeds.', 'persiano-hub' ),
        );
        if ( $match >= 0 ) { $items[ $match ] = array_merge( $items[ $match ], $entry ); }
        else { $items[] = $entry; }
        update_post_meta( $ingredient_id, '_persiano_supplier_items', $items );

        $signature = md5( implode( '|', array( $ingredient_id, $qty, $unit, $price, $data['availability'] ) ) );
        $last_signature = (string) get_post_meta( $source_id, self::META_LAST_RECORDED_SIGNATURE, true );
        if ( $force_record || $signature !== $last_signature ) {
            $history = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, true );
            $history = is_array( $history ) ? $history : array();
            $history[] = array(
                'record_id' => wp_generate_uuid4(),
                'time' => time(),
                'supplier' => sanitize_text_field( $data['supplier'] ),
                'supplier_item_code' => sanitize_text_field( $data['sku'] ?: $data['gtin'] ),
                'purchase_qty' => $qty,
                'purchase_unit' => $unit,
                'net_cost' => $price,
                'tax' => 0,
                'gross_cost' => $price,
                'unit_cost' => $normalized,
                'base_unit' => $base_unit,
                'brand' => sanitize_text_field( $data['brand'] ),
                'sale_price' => (float) $data['regular_price'] > $price && $price > 0 ? 1 : 0,
                'source_type' => 'url_feed',
                'source_url' => esc_url_raw( $data['url'] ),
                'price_source_id' => $source_id,
                'availability' => sanitize_key( $data['availability'] ),
                'approved' => 1,
                'notes' => __( 'Online price source check.', 'persiano-hub' ),
            );
            update_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, $history );
            update_post_meta( $source_id, self::META_LAST_RECORDED_SIGNATURE, $signature );
            update_post_meta( $source_id, self::META_LAST_CHANGE, time() );
            if ( class_exists( 'Persiano_Hub_Ingredient_Master' ) ) { Persiano_Hub_Ingredient_Master::repair_and_apply_current_cost( $ingredient_id ); }
            self::schedule_recalculation();
        }
        return true;
    }

    private static function status_from_availability( $availability ) {
        $availability = strtolower( (string) $availability );
        return false !== strpos( $availability, 'outofstock' ) || false !== strpos( $availability, 'out_of_stock' ) || false !== strpos( $availability, 'soldout' ) ? 'out_of_stock' : 'active';
    }

    private static function save_review_fields( $source_id, $row ) {
        if ( ! is_array( $row ) ) { return; }
        $fields = array(
            'name' => self::META_NAME, 'brand' => self::META_BRAND, 'supplier' => self::META_SUPPLIER,
            'sku' => self::META_SKU, 'gtin' => self::META_GTIN, 'availability' => self::META_AVAILABILITY,
        );
        foreach ( $fields as $field => $meta_key ) {
            if ( array_key_exists( $field, $row ) ) { update_post_meta( $source_id, $meta_key, sanitize_text_field( $row[ $field ] ) ); }
        }
        if ( array_key_exists( 'package_quantity', $row ) ) { update_post_meta( $source_id, self::META_PACKAGE_QTY, max( 0, (float) $row['package_quantity'] ) ); }
        if ( array_key_exists( 'package_unit', $row ) ) { update_post_meta( $source_id, self::META_PACKAGE_UNIT, self::normalize_unit( $row['package_unit'] ) ); }
        if ( array_key_exists( 'price', $row ) ) { update_post_meta( $source_id, self::META_PRICE, max( 0, (float) $row['price'] ) ); }
        if ( array_key_exists( 'regular_price', $row ) ) { update_post_meta( $source_id, self::META_REGULAR_PRICE, max( 0, (float) $row['regular_price'] ) ); }
    }

    public static function approve_selected() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_feed_approve' );
        $ids = self::selected_ids();
        $mappings = isset( $_POST['ingredient_map'] ) && is_array( $_POST['ingredient_map'] ) ? wp_unslash( $_POST['ingredient_map'] ) : array();
        $frequencies = isset( $_POST['frequency'] ) && is_array( $_POST['frequency'] ) ? wp_unslash( $_POST['frequency'] ) : array();
        $review_data = isset( $_POST['feed_data'] ) && is_array( $_POST['feed_data'] ) ? wp_unslash( $_POST['feed_data'] ) : array();
        $approved = 0; $errors = 0;
        foreach ( $ids as $id ) {
            self::save_review_fields( $id, $review_data[ $id ] ?? array() );
            $ingredient_id = absint( $mappings[ $id ] ?? get_post_meta( $id, self::META_SUGGESTED_INGREDIENT, true ) );
            $old_ingredient_id = absint( get_post_meta( $id, self::META_INGREDIENT_ID, true ) );
            if ( $old_ingredient_id && $ingredient_id && $old_ingredient_id !== $ingredient_id ) {
                $old_items = get_post_meta( $old_ingredient_id, '_persiano_supplier_items', true );
                $old_items = is_array( $old_items ) ? array_values( array_filter( $old_items, static function( $item ) use ( $id ) { return absint( $item['price_source_id'] ?? 0 ) !== $id; } ) ) : array();
                update_post_meta( $old_ingredient_id, '_persiano_supplier_items', $old_items );
            }
            $force_record = ! $old_ingredient_id || $old_ingredient_id !== $ingredient_id || ! get_post_meta( $id, self::META_LAST_RECORDED_SIGNATURE, true );
            if ( ! $ingredient_id || ! self::apply_to_ingredient( $id, $ingredient_id, $force_record ) ) {
                update_post_meta( $id, self::META_STATUS, 'needs_review' );
                update_post_meta( $id, self::META_LAST_ERROR, __( 'Select an ingredient and enter a valid package quantity, unit and price before approval.', 'persiano-hub' ) );
                if ( $old_ingredient_id ) { self::update_linked_supplier_item( $id, 'deactivate' ); }
                $errors++;
                continue;
            }
            update_post_meta( $id, self::META_INGREDIENT_ID, $ingredient_id );
            update_post_meta( $id, self::META_APPROVED_IDENTITY, self::identity_from_data( self::source_data( $id ) ) );
            update_post_meta( $id, self::META_ACTIVE, 1 );
            $frequency = sanitize_key( $frequencies[ $id ] ?? 'manual' );
            if ( ! in_array( $frequency, array( 'manual','daily','weekly','monthly' ), true ) ) { $frequency = 'manual'; }
            update_post_meta( $id, self::META_FREQUENCY, $frequency );
            update_post_meta( $id, self::META_STATUS, self::status_from_availability( get_post_meta( $id, self::META_AVAILABILITY, true ) ) );
            delete_post_meta( $id, self::META_LAST_ERROR );
            $approved++;
        }
        wp_safe_redirect( self::page_url( array( 'approved' => $approved, 'errors' => $errors ) ) );
        exit;
    }

    public static function scheduled_sweep() {
        $ids = get_posts( array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => self::META_ACTIVE,
            'meta_value' => 1,
            'no_found_rows' => true,
        ) );
        $intervals = array( 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS, 'monthly' => 30 * DAY_IN_SECONDS );
        $queued = 0;
        foreach ( $ids as $id ) {
            $frequency = (string) get_post_meta( $id, self::META_FREQUENCY, true );
            if ( empty( $intervals[ $frequency ] ) ) { continue; }
            $last = (int) get_post_meta( $id, self::META_LAST_ATTEMPT, true );
            if ( $last && time() - $last < $intervals[ $frequency ] ) { continue; }
            update_post_meta( $id, self::META_STATUS, 'queued' );
            self::schedule_source( $id, 2 + ( $queued * 5 ) );
            $queued++;
        }
    }

    private static function status_counts() {
        $counts = array();
        foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $id ) {
            $status = (string) get_post_meta( $id, self::META_STATUS, true );
            if ( ! $status ) { $status = 'queued'; }
            $counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
        }
        return $counts;
    }

    private static function ingredients() {
        if ( ! class_exists( 'Persiano_Hub_Costing' ) ) { return array(); }
        return get_posts( array(
            'post_type' => Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
            'post_status' => array( 'publish','draft','private' ),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ) );
    }

    private static function status_label( $status ) {
        $labels = array(
            'queued' => 'Queued', 'processing' => 'Processing', 'needs_review' => 'Needs review', 'active' => 'Active',
            'out_of_stock' => 'Out of stock', 'retrying' => 'Retrying', 'needs_attention' => 'Needs attention', 'paused' => 'Paused',
        );
        return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
    }

    public static function export_csv() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_feed_export' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="persiano-price-sources-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        fputcsv( $out, array( 'source_id','status','url','current_url','ingredient_id','ingredient_name','supplier','product_name','brand','sku','gtin','package_quantity','package_unit','price','regular_price','currency','availability','frequency','last_attempt','last_success','failures','last_error','previous_price','price_change','price_change_pct','price_changed_at' ) );
        foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
            fputcsv( $out, array(
                $id, get_post_meta( $id, self::META_STATUS, true ), get_post_meta( $id, self::META_URL, true ), get_post_meta( $id, self::META_CURRENT_URL, true ),
                get_post_meta( $id, self::META_INGREDIENT_ID, true ), get_the_title( absint( get_post_meta( $id, self::META_INGREDIENT_ID, true ) ) ),
                get_post_meta( $id, self::META_SUPPLIER, true ), get_post_meta( $id, self::META_NAME, true ), get_post_meta( $id, self::META_BRAND, true ),
                get_post_meta( $id, self::META_SKU, true ), get_post_meta( $id, self::META_GTIN, true ), get_post_meta( $id, self::META_PACKAGE_QTY, true ),
                get_post_meta( $id, self::META_PACKAGE_UNIT, true ), get_post_meta( $id, self::META_PRICE, true ), get_post_meta( $id, self::META_REGULAR_PRICE, true ),
                get_post_meta( $id, self::META_CURRENCY, true ), get_post_meta( $id, self::META_AVAILABILITY, true ), get_post_meta( $id, self::META_FREQUENCY, true ),
                get_post_meta( $id, self::META_LAST_ATTEMPT, true ), get_post_meta( $id, self::META_LAST_SUCCESS, true ), get_post_meta( $id, self::META_FAILURES, true ),
                get_post_meta( $id, self::META_LAST_ERROR, true ), get_post_meta( $id, self::META_PREVIOUS_PRICE, true ), get_post_meta( $id, self::META_PRICE_CHANGE, true ),
                get_post_meta( $id, self::META_PRICE_CHANGE_PCT, true ), get_post_meta( $id, self::META_PRICE_CHANGED_AT, true ),
            ) );
        }
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    public static function export_records() {
        $records = array();
        foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) ) as $id ) {
            $meta = array();
            foreach ( self::meta_keys() as $key ) { $meta[ $key ] = get_post_meta( $id, $key, true ); }
            $records[] = array( 'id' => $id, 'title' => get_the_title( $id ), 'status' => get_post_status( $id ), 'meta' => $meta );
        }
        return $records;
    }

    public static function meta_keys() {
        return array(
            self::META_URL,self::META_CURRENT_URL,self::META_STATUS,self::META_ACTIVE,self::META_FREQUENCY,self::META_INGREDIENT_ID,self::META_SUPPLIER,
            self::META_NAME,self::META_BRAND,self::META_SKU,self::META_GTIN,self::META_PACKAGE_QTY,self::META_PACKAGE_UNIT,self::META_PRICE,self::META_REGULAR_PRICE,
            self::META_CURRENCY,self::META_AVAILABILITY,self::META_IMAGE,self::META_LAST_ATTEMPT,self::META_LAST_SUCCESS,self::META_FAILURES,self::META_LAST_ERROR,
            self::META_HTTP_CODE,self::META_APPROVED_IDENTITY,self::META_LAST_RECORDED_SIGNATURE,self::META_SUGGESTED_INGREDIENT,self::META_LAST_CHANGE,
            self::META_PREVIOUS_PRICE,self::META_PRICE_CHANGE,self::META_PRICE_CHANGE_PCT,self::META_PRICE_CHANGED_AT,
        );
    }

    public static function render_page() {
        self::require_permission();
        $counts = self::status_counts();
        $filter = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = sanitize_text_field( wp_unslash( $_GET['feed_search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $args = array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 50, 'paged'=>$paged, 'orderby' => 'modified', 'order' => 'DESC' );
        if ( $filter ) { $args['meta_query'] = array( array( 'key' => self::META_STATUS, 'value' => $filter ) ); }
        if ( $search ) { $args['s'] = $search; }
        $source_query = new WP_Query( $args );
        $sources = $source_query->posts;
        $total_pages = max( 1, (int) $source_query->max_num_pages );
        $ingredients = self::ingredients();
        $has_pending = ! empty( $counts['queued'] ) || ! empty( $counts['processing'] ) || ! empty( $counts['retrying'] );
        $export = wp_nonce_url( add_query_arg( 'action', 'persiano_hub_feed_export', admin_url( 'admin-post.php' ) ), 'persiano_hub_feed_export' );
        ?>
        <div class="wrap ph-feed-wrap">
            <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Online supplier intelligence', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Price Feeds', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Drop product URLs into the inbox. Persiano processes them in the background, remembers each source, tracks price history and warns when a page becomes inaccessible or changes identity.', 'persiano-hub' ); ?></p></div><div class="ph-costing-actions"><a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export sources CSV', 'persiano-hub' ); ?></a></div></div>
            <?php if ( isset( $_GET['added'] ) ) : $feed_failed = absint( $_GET['failed'] ?? 0 ); ?><div class="notice <?php echo $feed_failed ? 'notice-warning' : 'notice-success'; ?> is-dismissible"><p><?php printf( esc_html__( '%1$d new URLs queued; %2$d existing sources requeued; %3$d invalid URLs ignored; %4$d records could not be stored.', 'persiano-hub' ), absint( $_GET['added'] ), absint( $_GET['requeued'] ?? 0 ), absint( $_GET['invalid'] ?? 0 ), $feed_failed ); ?><?php if ( $feed_failed && ! empty( $_GET['feed_error'] ) ) : ?> <strong><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['feed_error'] ) ) ) ); ?></strong><?php endif; ?></p></div><?php endif; ?>
            <?php if ( isset( $_GET['queued'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( '%d source checks queued.', 'persiano-hub' ), absint( $_GET['queued'] ) ); ?></p></div><?php endif; ?>
            <section class="ph-feed-inbox"><h2><?php esc_html_e( 'URL inbox', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'Paste one or many product URLs, or paste text containing URLs. Duplicates are recognized automatically. You may leave this page after submitting.', 'persiano-hub' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_feed_add_urls"><?php wp_nonce_field( 'persiano_hub_feed_add_urls' ); ?><textarea name="price_feed_urls" rows="7" class="large-text code" placeholder="https://retailer.ca/product/...&#10;https://another-store.ca/item/..." required></textarea><p><button class="button button-primary button-large"><?php esc_html_e( 'Add URLs to background queue', 'persiano-hub' ); ?></button></p></form></section>
            <div class="ph-feed-stats">
                <?php foreach ( array( 'queued'=>'Queued','processing'=>'Processing','needs_review'=>'Needs review','active'=>'Active','out_of_stock'=>'Out of stock','needs_attention'=>'Needs attention' ) as $key => $label ) : ?><a href="<?php echo esc_url( self::page_url( array( 'status' => $key ) ) ); ?>"><strong><?php echo absint( $counts[ $key ] ?? 0 ); ?></strong><span><?php echo esc_html( $label ); ?></span></a><?php endforeach; ?>
            </div>
            <section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2><?php esc_html_e( 'Monitored product sources', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'Approve a mapping once. Later checks update the same supplier package and add dated price-history records only when something changes.', 'persiano-hub' ); ?></p></div><div class="ph-costing-actions"><form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>"><?php if($filter): ?><input type="hidden" name="status" value="<?php echo esc_attr($filter); ?>"><?php endif; ?><input type="search" name="feed_search" value="<?php echo esc_attr($search); ?>" placeholder="Search product sources"><button class="button"><?php esc_html_e('Search','persiano-hub'); ?></button></form><a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Show all', 'persiano-hub' ); ?></a></div></div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ph-feed-table-form"><input type="hidden" name="action" id="ph-feed-action" value="persiano_hub_feed_approve"><?php wp_nonce_field( 'persiano_hub_feed_approve' ); ?><div class="ph-feed-table-scroll"><table class="widefat striped ph-feed-table"><thead><tr><th><input type="checkbox" id="ph-feed-check-all"></th><th><?php esc_html_e( 'Source / product', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Package & price', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Ingredient mapping', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Refresh', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Status', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Last check', 'persiano-hub' ); ?></th></tr></thead><tbody>
            <?php if ( ! $sources ) : ?><tr><td colspan="7"><?php esc_html_e( 'No price sources yet. Paste product URLs into the inbox above.', 'persiano-hub' ); ?></td></tr><?php else : foreach ( $sources as $source ) : $id=$source->ID; $status=(string)get_post_meta($id,self::META_STATUS,true); $mapped=absint(get_post_meta($id,self::META_INGREDIENT_ID,true)); $suggested=absint(get_post_meta($id,self::META_SUGGESTED_INGREDIENT,true)); $selected=$mapped?:$suggested; $url=get_post_meta($id,self::META_CURRENT_URL,true)?:get_post_meta($id,self::META_URL,true); $last=(int)get_post_meta($id,self::META_LAST_ATTEMPT,true); $error=get_post_meta($id,self::META_LAST_ERROR,true); ?>
                <tr><td><input type="checkbox" class="ph-feed-checkbox" name="source_ids[]" value="<?php echo esc_attr($id); ?>"></td><td><?php $product_name=get_post_meta($id,self::META_NAME,true)?:$source->post_title;$brand=get_post_meta($id,self::META_BRAND,true);$supplier=get_post_meta($id,self::META_SUPPLIER,true);$sku=get_post_meta($id,self::META_SKU,true);$gtin=get_post_meta($id,self::META_GTIN,true); ?><label class="screen-reader-text">Product name</label><input class="regular-text ph-feed-field" name="feed_data[<?php echo esc_attr($id); ?>][name]" value="<?php echo esc_attr($product_name); ?>" placeholder="Product name"><br><input class="small-text ph-feed-field" name="feed_data[<?php echo esc_attr($id); ?>][brand]" value="<?php echo esc_attr($brand); ?>" placeholder="Brand"> <input class="regular-text ph-feed-field" name="feed_data[<?php echo esc_attr($id); ?>][supplier]" value="<?php echo esc_attr($supplier); ?>" placeholder="Supplier / store"><br><input class="regular-text ph-feed-field" name="feed_data[<?php echo esc_attr($id); ?>][sku]" value="<?php echo esc_attr($sku); ?>" placeholder="SKU"> <input class="regular-text ph-feed-field" name="feed_data[<?php echo esc_attr($id); ?>][gtin]" value="<?php echo esc_attr($gtin); ?>" placeholder="Barcode / GTIN"><br><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(wp_parse_url($url,PHP_URL_HOST)); ?> ↗</a><?php if($error): ?><div class="ph-feed-error"><?php echo esc_html($error); ?></div><?php endif; ?></td>
                <td><?php $pq=(float)get_post_meta($id,self::META_PACKAGE_QTY,true); $pu=get_post_meta($id,self::META_PACKAGE_UNIT,true); $price=(float)get_post_meta($id,self::META_PRICE,true);$regular=(float)get_post_meta($id,self::META_REGULAR_PRICE,true);$availability=get_post_meta($id,self::META_AVAILABILITY,true);$change=(float)get_post_meta($id,self::META_PRICE_CHANGE,true);$currency=get_post_meta($id,self::META_CURRENCY,true)?:get_woocommerce_currency(); ?><input type="number" min="0" step="0.0001" class="small-text" name="feed_data[<?php echo esc_attr($id); ?>][package_quantity]" value="<?php echo esc_attr($pq?:''); ?>"> <select name="feed_data[<?php echo esc_attr($id); ?>][package_unit]"><option value="">Unit</option><?php foreach(array('g','kg','oz','lb','ml','l','each')as$unit): ?><option value="<?php echo esc_attr($unit); ?>" <?php selected($pu,$unit); ?>><?php echo esc_html($unit); ?></option><?php endforeach; ?></select><br><small><?php echo esc_html($currency); ?></small> <input type="number" min="0" step="0.01" class="small-text" name="feed_data[<?php echo esc_attr($id); ?>][price]" value="<?php echo esc_attr($price?:''); ?>" placeholder="Price"> <small>regular <?php echo esc_html($currency); ?></small> <input type="number" min="0" step="0.01" class="small-text" name="feed_data[<?php echo esc_attr($id); ?>][regular_price]" value="<?php echo esc_attr($regular?:''); ?>"><br><input class="regular-text ph-feed-field" name="feed_data[<?php echo esc_attr($id); ?>][availability]" value="<?php echo esc_attr($availability); ?>" placeholder="Availability"><?php if(abs($change)>=0.005): ?><br><small class="<?php echo $change>0?'ph-feed-price-up':'ph-feed-price-down'; ?>"><?php echo esc_html(sprintf('%+.2f since previous check',$change)); ?></small><?php endif; ?></td>
                <td><select name="ingredient_map[<?php echo esc_attr($id); ?>]" class="ph-feed-map"><option value="0"><?php esc_html_e('Select ingredient','persiano-hub'); ?></option><?php foreach($ingredients as$ingredient): ?><option value="<?php echo esc_attr($ingredient->ID); ?>" <?php selected($selected,$ingredient->ID); ?>><?php echo esc_html($ingredient->post_title); ?></option><?php endforeach; ?></select><?php if($suggested&&!$mapped): ?><br><small><?php esc_html_e('Suggested match','persiano-hub'); ?></small><?php endif; ?></td>
                <td><select name="frequency[<?php echo esc_attr($id); ?>]"><?php $freq=get_post_meta($id,self::META_FREQUENCY,true)?:'manual'; foreach(array('manual'=>'Manual','daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly')as$k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($freq,$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></td>
                <td><span class="ph-feed-status ph-feed-status--<?php echo esc_attr($status); ?>"><?php echo esc_html(self::status_label($status)); ?></span></td><td><?php echo $last?esc_html(wp_date('M j, Y g:i a',$last)):esc_html__('Never','persiano-hub'); ?><br><small><?php echo esc_html((int)get_post_meta($id,self::META_FAILURES,true).' failures'); ?></small></td></tr>
            <?php endforeach; endif; ?></tbody></table></div>
            <div class="ph-feed-actions"><button class="button button-primary" type="submit" onclick="document.getElementById('ph-feed-action').value='persiano_hub_feed_approve'">Approve/update selected</button><button class="button" type="submit" onclick="document.getElementById('ph-feed-action').value='persiano_hub_feed_queue';document.querySelector('#ph-feed-table-form [name=_wpnonce]').value='<?php echo esc_js(wp_create_nonce('persiano_hub_feed_bulk')); ?>'">Check selected now</button><button class="button" name="check_all" value="1" type="submit" onclick="document.getElementById('ph-feed-action').value='persiano_hub_feed_queue';document.querySelector('#ph-feed-table-form [name=_wpnonce]').value='<?php echo esc_js(wp_create_nonce('persiano_hub_feed_bulk')); ?>'">Check all active sources</button><button class="button" type="submit" onclick="document.getElementById('ph-feed-action').value='persiano_hub_feed_pause';document.querySelector('#ph-feed-table-form [name=_wpnonce]').value='<?php echo esc_js(wp_create_nonce('persiano_hub_feed_bulk')); ?>'">Pause selected</button><button class="button button-link-delete" type="submit" onclick="if(!confirm('Delete selected price sources? Price history already saved to ingredients will remain.'))return false;document.getElementById('ph-feed-action').value='persiano_hub_feed_delete';document.querySelector('#ph-feed-table-form [name=_wpnonce]').value='<?php echo esc_js(wp_create_nonce('persiano_hub_feed_bulk')); ?>'">Delete selected</button></div></form><?php if($total_pages>1): ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post(paginate_links(array('base'=>str_replace('999999999','%#%',add_query_arg('paged','999999999',self::page_url(array_filter(array('status'=>$filter,'feed_search'=>$search))))),'format'=>'','current'=>$paged,'total'=>$total_pages,'prev_text'=>'‹','next_text'=>'›'))); ?></div></div><?php endif; ?></section>
        </div>
        <style>.ph-feed-wrap{max-width:1350px}.ph-feed-inbox{background:#fff;border:1px solid #dcdcde;border-left:5px solid #8e2435;border-radius:14px;padding:22px 24px;margin:18px 0}.ph-feed-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin:18px 0}.ph-feed-stats a{display:flex;flex-direction:column;text-decoration:none;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px}.ph-feed-stats strong{font-size:27px;color:#8e2435}.ph-feed-stats span{color:#50575e}.ph-feed-table-scroll{overflow:auto}.ph-feed-table{min-width:1120px}.ph-feed-table td{vertical-align:top}.ph-feed-map{min-width:210px;max-width:280px}.ph-feed-field{max-width:100%;margin:0 0 4px}.ph-feed-price-up{color:#b32d2e;font-weight:700}.ph-feed-price-down{color:#135e27;font-weight:700}.ph-feed-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px;align-items:center}.ph-feed-status{display:inline-flex;padding:5px 9px;border-radius:999px;background:#e8e8e8;font-weight:700;font-size:12px}.ph-feed-status--active{background:#d7f1dc;color:#135e27}.ph-feed-status--needs_review,.ph-feed-status--retrying{background:#fff3cd;color:#7a4d00}.ph-feed-status--needs_attention{background:#f8d7da;color:#8a1f2a}.ph-feed-status--queued,.ph-feed-status--processing{background:#dbeafe;color:#1d4e89}.ph-feed-status--out_of_stock,.ph-feed-status--paused{background:#ececec;color:#50575e}.ph-feed-error{margin-top:5px;color:#b32d2e;font-size:12px;max-width:360px}@media(max-width:1000px){.ph-feed-stats{grid-template-columns:repeat(3,1fr)}}@media(max-width:620px){.ph-feed-stats{grid-template-columns:repeat(2,1fr)}} </style>
        <script>document.addEventListener('DOMContentLoaded',function(){var all=document.getElementById('ph-feed-check-all');if(all)all.addEventListener('change',function(){document.querySelectorAll('.ph-feed-checkbox').forEach(function(x){x.checked=all.checked;});});<?php if($has_pending): ?>setTimeout(function(){if(!document.hidden)window.location.reload();},15000);<?php endif; ?>});</script>
        <?php
    }
}
