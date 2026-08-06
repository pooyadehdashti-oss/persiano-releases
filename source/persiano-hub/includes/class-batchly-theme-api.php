<?php
/**
 * Stable public integration layer for Batchly-compatible themes.
 *
 * Themes should use these helpers, REST endpoints, actions and filters rather
 * than reading Batchly's internal tables or legacy Persiano-specific options.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Batchly_Theme_API {
    const API_VERSION = '1.0';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'after_setup_theme', array( __CLASS__, 'announce_support' ), 20 );
    }

    public static function announce_support() {
        do_action( 'batchly_theme_api_ready', self::API_VERSION );
    }

    public static function register_routes() {
        register_rest_route( 'batchly/v1', '/business-profile', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_business_profile' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'batchly/v1', '/compatibility', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_compatibility' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function rest_business_profile() {
        return rest_ensure_response( batchly_get_business_profile() );
    }

    public static function rest_compatibility() {
        return rest_ensure_response( array(
            'batchly_version'  => defined( 'PERSIANO_HUB_VERSION' ) ? PERSIANO_HUB_VERSION : '',
            'theme_api'        => self::API_VERSION,
            'commerce_adapter' => class_exists( 'WooCommerce' ) ? 'woocommerce' : 'none',
            'woocommerce'      => class_exists( 'WooCommerce' ),
        ) );
    }
}

function batchly_get_business_profile() {
    $profile = array(
        'name'          => function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ),
        'support_email' => function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : sanitize_email( get_option( 'admin_email' ) ),
        'site_url'      => home_url( '/' ),
        'logo_id'       => class_exists( 'Persiano_Hub_Business_Profile' ) ? absint( Persiano_Hub_Business_Profile::get( 'logo_id', 0 ) ) : 0,
    );
    return apply_filters( 'batchly_business_profile', $profile );
}

function batchly_get_theme_api_version() {
    return Batchly_Theme_API::API_VERSION;
}

function batchly_get_commerce_adapter() {
    return apply_filters( 'batchly_commerce_adapter', class_exists( 'WooCommerce' ) ? 'woocommerce' : 'none' );
}

function batchly_theme_is_compatible( $required_api = '1.0' ) {
    return version_compare( Batchly_Theme_API::API_VERSION, (string) $required_api, '>=' );
}
