<?php
/**
 * Plugin Name: Batchly
 * Plugin URI: https://github.com/pooyadehdashti-oss/persiano-releases
 * Description: Configurable business tools for products, orders, correspondence, fulfilment, customer messaging, analytics and multi-channel publishing.
 * Version: 0.56.5
 * Author: Batchly Project
 * Author URI: https://github.com/pooyadehdashti-oss/persiano-releases
 * Update URI: https://github.com/pooyadehdashti-oss/persiano-releases
 * Text Domain: persiano-hub
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PERSIANO_HUB_VERSION', '0.56.5' );
define( 'PERSIANO_HUB_FILE', __FILE__ );
define( 'PERSIANO_HUB_PATH', plugin_dir_path( __FILE__ ) );
define( 'PERSIANO_HUB_URL', plugin_dir_url( __FILE__ ) );


/**
 * Return a value from the per-site business profile.
 *
 * Keeping customer-facing identity in WordPress options allows the same plugin
 * release to serve independent trial businesses without forking the codebase.
 */
function persiano_hub_business_value( $key, $fallback = '' ) {
    return class_exists( 'Persiano_Hub_Business_Profile' )
        ? Persiano_Hub_Business_Profile::get( $key, $fallback )
        : $fallback;
}

function persiano_hub_brand_name() {
    return class_exists( 'Persiano_Hub_Business_Profile' )
        ? Persiano_Hub_Business_Profile::brand_name()
        : ( get_bloginfo( 'name' ) ?: 'Business' );
}

function persiano_hub_support_email() {
    return class_exists( 'Persiano_Hub_Business_Profile' )
        ? Persiano_Hub_Business_Profile::support_email()
        : sanitize_email( get_option( 'admin_email' ) );
}

/** Declare High-Performance Order Storage compatibility. */
add_action(
    'before_woocommerce_init',
    static function() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-business-profile.php';
require_once PERSIANO_HUB_PATH . 'includes/class-batchly-theme-api.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-setup-wizard.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-product-fields.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-tax.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-commerce.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-fulfilment.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-newsletter.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-customer-accounts.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-events.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-manual-orders.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-marketing.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-advance-orders.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-advance-order-center.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-email-branding.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-publishing.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-google-reviews.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-costing.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-kitchen.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-purchasing.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-data-tools.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-operations.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-inventory.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-ai-cost-import.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-admin-ux.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-updater.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-unified-checkout.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-labels.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-order-documents.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-loyalty-admin.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-notifications.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-savings.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-refunds.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-workspace.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-loss-waste.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-maintenance.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-production-suite.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-email-campaigns.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-universal-import-export.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-architecture.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-workflow-enhancements.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-ingredient-master.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-tested-refinements.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-price-feeds.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-package-catalogue.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-structural-data.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-browser-capture.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-square-payments.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-square-transactions.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-customer-messages.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-web-push.php';
require_once PERSIANO_HUB_PATH . 'includes/class-persiano-hub-frontend-pos.php';
require_once PERSIANO_HUB_PATH . 'includes/class-batchly-trial-monitoring.php';

function persiano_hub_boot() {
    // Core services and the public theme API are available independently of WooCommerce.
    Persiano_Hub_Business_Profile::init();
    Batchly_Theme_API::init();

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'persiano_hub_missing_woocommerce_notice' );
        return;
    }

    Persiano_Hub_Product_Fields::init();
    Persiano_Hub_Tax::init();
    Persiano_Hub_Commerce::init();
    Persiano_Hub_Fulfilment::init();
    Persiano_Hub_Newsletter::init();
    Persiano_Hub_Customer_Accounts::init();
    Persiano_Hub_Events::init();
    Persiano_Hub_Manual_Orders::init();
    Persiano_Hub_Marketing::init();
    Persiano_Hub_Advance_Orders::init();
    Persiano_Hub_Advance_Order_Center::init();
    Persiano_Hub_Setup_Wizard::init();
    Persiano_Hub_Email_Branding::init();
    Persiano_Hub_Publishing::init();
    Persiano_Hub_Google_Reviews::init();
    Persiano_Hub_Costing::init();
    Persiano_Hub_Kitchen::init();
    Persiano_Hub_Purchasing::init();
    Persiano_Hub_Data_Tools::init();
    if ( class_exists( 'Persiano_Hub_Universal_Import_Export' ) ) {
        Persiano_Hub_Universal_Import_Export::init();
    }
    Persiano_Hub_Operations::init();
    Persiano_Hub_Inventory::init();
    Persiano_Hub_AI_Cost_Import::init();
    Persiano_Hub_Admin_UX::init();
    Persiano_Hub_Unified_Checkout::init();
    Persiano_Hub_Labels::init();
    Persiano_Hub_Order_Documents::init();
    Persiano_Hub_Savings::init();
    Persiano_Hub_Loyalty_Admin::init();
    Persiano_Hub_Notifications::init();
    Persiano_Hub_Refunds::init();
    Persiano_Hub_Workspace::init();
    Persiano_Hub_Loss_Waste::init();
    Persiano_Hub_Maintenance::init();
    Persiano_Hub_Production_Suite::init();
    Persiano_Hub_Email_Campaigns::init();
    Persiano_Hub_Architecture::init();
    Persiano_Hub_Workflow_Enhancements::init();
    Persiano_Hub_Ingredient_Master::init();
    Persiano_Hub_Tested_Refinements::init();
    Persiano_Hub_Price_Feeds::init();
    Persiano_Hub_Package_Catalogue::init();
    Persiano_Hub_Structural_Data::init();
    Persiano_Hub_Browser_Capture::init();
    Persiano_Hub_Square_Payments::init();
    Persiano_Hub_Square_Transactions::init();
    Persiano_Hub_Customer_Messages::init();
    Persiano_Hub_Web_Push::init();
    Persiano_Hub_Frontend_POS::init();
    Batchly_Trial_Monitoring::init();
}
add_action( 'plugins_loaded', 'persiano_hub_boot' );

function persiano_hub_missing_woocommerce_notice() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    echo '<div class="notice notice-info"><p><strong>Batchly Core is active.</strong> Install WooCommerce only when this site needs the current WooCommerce commerce adapter. Business-profile and theme-integration services remain available without it.</p></div>';
}

function persiano_hub_ensure_categories() {
    if ( ! taxonomy_exists( 'product_cat' ) ) {
        return;
    }

    $categories = array(
        'this-week' => 'This Week',
        'pantry'    => 'Pantry',
    );

    foreach ( $categories as $slug => $name ) {
        if ( ! term_exists( $slug, 'product_cat' ) ) {
            wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
        }
    }
}
add_action( 'init', 'persiano_hub_ensure_categories', 30 );

function persiano_hub_activate() {
    Persiano_Hub_Setup_Wizard::activate();
    Persiano_Hub_Newsletter::install();
    Persiano_Hub_Customer_Accounts::install();
    Persiano_Hub_Events::install();
    Persiano_Hub_Marketing::install();
    Persiano_Hub_Publishing::register_post_type();
    Persiano_Hub_Publishing::register_public_post_types();
    Persiano_Hub_Events::register_post_type();
    Persiano_Hub_Production_Suite::install();
    Persiano_Hub_Structural_Data::install();
    Persiano_Hub_Frontend_POS::activate();
    Persiano_Hub_Customer_Messages::install();
    Batchly_Trial_Monitoring::install();
    if ( class_exists( 'Persiano_Hub_Price_Feeds' ) ) { Persiano_Hub_Price_Feeds::register_post_type(); Persiano_Hub_Price_Feeds::ensure_schedule(); }
    if ( class_exists( 'Persiano_Hub_AI_Cost_Import' ) ) { Persiano_Hub_AI_Cost_Import::register_job_post_type(); Persiano_Hub_AI_Cost_Import::ensure_schedule(); }
    flush_rewrite_rules( false );
    update_option( 'persiano_hub_public_rewrite_version', PERSIANO_HUB_VERSION );
    update_option( 'persiano_hub_db_version', PERSIANO_HUB_VERSION );
}
register_activation_hook( __FILE__, 'persiano_hub_activate' );

function persiano_hub_deactivate() {
    wp_clear_scheduled_hook( Persiano_Hub_Price_Feeds::SWEEP_HOOK );
    wp_clear_scheduled_hook( Persiano_Hub_Price_Feeds::RECALC_HOOK );
    wp_clear_scheduled_hook( Persiano_Hub_AI_Cost_Import::SWEEP_HOOK );
}
register_deactivation_hook( __FILE__, 'persiano_hub_deactivate' );

function persiano_hub_maybe_upgrade() {
    $version = get_option( 'persiano_hub_db_version', '0' );
    if ( version_compare( $version, PERSIANO_HUB_VERSION, '<' ) ) {
        Persiano_Hub_Newsletter::install();
        Persiano_Hub_Customer_Accounts::install();
        Persiano_Hub_Events::install();
        Persiano_Hub_Marketing::install();
        Persiano_Hub_Structural_Data::install();
        Persiano_Hub_Customer_Messages::install();
        Batchly_Trial_Monitoring::install();

        /*
         * Do not create the advance-order placeholder during plugins_loaded.
         * WooCommerce's product data store can still be in an early bootstrap
         * state here, and duplicate-SKU validation can turn an upgrade into a
         * site-wide fatal error. The placeholder is created safely/lazily on
         * init and again when an advance order is actually added to the cart.
         */
        update_option( 'persiano_hub_db_version', PERSIANO_HUB_VERSION );
    }
}
add_action( 'plugins_loaded', 'persiano_hub_maybe_upgrade', 20 );

/**
 * Migrate and recalculate existing recipes once after the normalized yield
 * and package-pricing formula is installed.
 */
function persiano_hub_upgrade_recipe_costs() {
    $formula_version = get_option( 'persiano_hub_recipe_cost_formula_version', '0' );
    if ( version_compare( $formula_version, '0.43.0', '>=' ) ) {
        return;
    }

    $recipe_ids = get_posts(
        array(
            'post_type'      => Persiano_Hub_Costing::RECIPE_POST_TYPE,
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        )
    );

    foreach ( $recipe_ids as $recipe_id ) {
        $saved_unit = get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true );
        $unit       = Persiano_Hub_Costing::canonical_recipe_unit( $saved_unit );
        if ( $unit !== $saved_unit ) {
            update_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, $unit );
        }
        Persiano_Hub_Costing::recalculate_recipe( $recipe_id );
        if ( class_exists( 'Persiano_Hub_Workflow_Enhancements' ) ) {
            Persiano_Hub_Workflow_Enhancements::refresh_price_review( $recipe_id, get_post( $recipe_id ), true );
        }
    }

    update_option( 'persiano_hub_recipe_cost_formula_version', '0.43.0', false );
}
add_action( 'init', 'persiano_hub_upgrade_recipe_costs', 100 );

/**
 * Install the prepared-component inventory workflow and rebuild open plans once.
 *
 * Old plans can contain pre-0.43.2 sub-recipe quantities or no component
 * allocation metadata at all. Rebuilding them here corrects those saved totals,
 * preserves manual overrides, and makes existing shopping lists stale so they
 * clearly request a refresh from their source plan.
 */
function persiano_hub_upgrade_inventory_workflow() {
    $workflow_version = get_option( 'persiano_hub_inventory_workflow_version', '0' );
    if ( version_compare( $workflow_version, '0.44.0', '>=' ) ) {
        return;
    }
    if ( class_exists( 'Persiano_Hub_Operations' ) ) {
        $recalculated = Persiano_Hub_Operations::recalculate_open_plans_for_inventory_upgrade();
        update_option( 'persiano_hub_inventory_upgrade_plan_count', absint( $recalculated ), false );
    }
    update_option( 'persiano_hub_inventory_workflow_version', '0.44.0', false );
}
add_action( 'init', 'persiano_hub_upgrade_inventory_workflow', 120 );


/**
 * Ensure WooCommerce has real Cart and Checkout pages assigned.
 *
 * Some older stores or partially configured installs have page IDs set to 0,
 * which makes wc_get_cart_url() / wc_get_checkout_url() fall back to home.
 * Existing non-empty pages are preserved; missing pages are created with the
 * standard WooCommerce shortcodes so both classic checkout and the custom
 * Persiano theme templates work immediately.
 */
function persiano_hub_ensure_woocommerce_pages() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    $setup_version = get_option( 'persiano_hub_commerce_setup_version', '0' );
    if ( version_compare( $setup_version, PERSIANO_HUB_VERSION, '>=' ) ) {
        return;
    }

    $pages = array(
        'cart' => array(
            'option'  => 'woocommerce_cart_page_id',
            'title'   => __( 'Cart', 'persiano-hub' ),
            'content' => '[woocommerce_cart]',
        ),
        'checkout' => array(
            'option'  => 'woocommerce_checkout_page_id',
            'title'   => __( 'Checkout', 'persiano-hub' ),
            'content' => '[woocommerce_checkout]',
        ),
        'my-account' => array(
            'option'  => 'woocommerce_myaccount_page_id',
            'title'   => __( 'My Account', 'persiano-hub' ),
            'content' => '[woocommerce_my_account]',
        ),
    );

    foreach ( $pages as $slug => $config ) {
        $page_id = absint( get_option( $config['option'], 0 ) );
        $page    = $page_id ? get_post( $page_id ) : null;

        if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
            $existing = get_page_by_path( $slug );

            if ( $existing && 'trash' !== $existing->post_status ) {
                $page_id = (int) $existing->ID;
                $page    = $existing;
            } else {
                $page_id = wp_insert_post(
                    array(
                        'post_title'   => $config['title'],
                        'post_name'    => $slug,
                        'post_status'  => 'publish',
                        'post_type'    => 'page',
                        'post_content' => $config['content'],
                    )
                );
                $page = $page_id && ! is_wp_error( $page_id ) ? get_post( $page_id ) : null;
            }
        }

        if ( $page && $page_id && ! is_wp_error( $page_id ) ) {
            if ( 'publish' !== $page->post_status ) {
                wp_update_post(
                    array(
                        'ID'          => $page_id,
                        'post_status' => 'publish',
                    )
                );
            }

            if ( '' === trim( (string) $page->post_content ) ) {
                wp_update_post(
                    array(
                        'ID'           => $page_id,
                        'post_content' => $config['content'],
                    )
                );
            }

            update_option( $config['option'], $page_id );
        }
    }

    $account_endpoints = array(
        'woocommerce_myaccount_orders_endpoint'          => 'orders',
        'woocommerce_myaccount_view_order_endpoint'      => 'view-order',
        'woocommerce_myaccount_downloads_endpoint'       => 'downloads',
        'woocommerce_myaccount_edit_account_endpoint'    => 'edit-account',
        'woocommerce_myaccount_edit_address_endpoint'    => 'edit-address',
        'woocommerce_myaccount_payment_methods_endpoint' => 'payment-methods',
        'woocommerce_myaccount_lost_password_endpoint'   => 'lost-password',
        'woocommerce_logout_endpoint'                    => 'customer-logout',
    );
    foreach ( $account_endpoints as $option_name => $default_endpoint ) {
        if ( '' === trim( (string) get_option( $option_name, '' ) ) ) {
            update_option( $option_name, $default_endpoint );
        }
    }

    update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
    update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
    update_option( 'woocommerce_registration_generate_username', 'yes' );
    update_option( 'woocommerce_registration_generate_password', 'yes' );

    update_option( 'persiano_hub_commerce_setup_version', PERSIANO_HUB_VERSION );
    flush_rewrite_rules( false );
}
add_action( 'admin_init', 'persiano_hub_ensure_woocommerce_pages', 25 );

/**
 * Public helper for themes and future Batchly modules.
 *
 * @param int $product_id Product ID.
 * @return array<string,mixed>
 */
function persiano_hub_get_product_details( $product_id ) {
    return Persiano_Hub_Product_Fields::get_product_details( $product_id );
}

/** Return Persiano course labels for theme filters. */
function persiano_hub_get_course_options() {
    return Persiano_Hub_Product_Fields::get_course_options();
}

/** Return Persiano dietary labels. */
function persiano_hub_get_dietary_options() {
    return Persiano_Hub_Product_Fields::get_dietary_options();
}

/** Return Persiano available-alteration labels. */
function persiano_hub_get_alteration_options() {
    return Persiano_Hub_Product_Fields::get_alteration_options();
}

/**
 * Render the Persiano mailing-list form from the theme.
 *
 * @param array<string,mixed> $args Form options.
 */
function persiano_hub_render_newsletter_form( $args = array() ) {
    Persiano_Hub_Newsletter::render_form( $args );
}
