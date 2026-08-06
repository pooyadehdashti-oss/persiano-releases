<?php
/**
 * Consolidated Batchly information architecture and master-data migration.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Architecture {
    const MIGRATION_OPTION = 'persiano_hub_master_data_migration_038';

    private static $sections = array(
        'persiano-hub-operations' => array(
            'label' => 'Operations',
            'intro' => 'Run daily orders, production, fulfilment, payments and printing from one place.',
        ),
        'persiano-hub-products-dashboard' => array(
            'label' => 'Products',
            'intro' => 'WooCommerce is the single master product system. Persiano fields extend those same records.',
        ),
        'persiano-hub-recipes-costing' => array(
            'label' => 'Recipes & Costing',
            'intro' => 'One master recipe and ingredient system for costing, production planning, suppliers and batch knowledge.',
        ),
        'persiano-hub-customers-sales' => array(
            'label' => 'Customers & Sales',
            'intro' => 'Manage customer accounts, loyalty, mailing lists, campaigns and payment activity.',
        ),
        'persiano-hub-publishing-content' => array(
            'label' => 'Publishing & Content',
            'intro' => 'Create and publish website, Instagram, Telegram, event and offer content.',
        ),
        'persiano-hub-reports' => array(
            'label' => 'Reports',
            'intro' => 'Review sales, marketing, margins, refunds, loss, waste and service recovery.',
        ),
        'persiano-hub-system-tools' => array(
            'label' => 'System & Tools',
            'intro' => 'Connections, imports, labels, maintenance, diagnostics and updates.',
        ),
    );

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_legacy_types_for_migration' ), 11 );
        add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_master_data' ), 30 );
        add_action( 'admin_menu', array( __CLASS__, 'register_sections' ), 900 );
        add_action( 'admin_menu', array( __CLASS__, 'clean_hub_menu' ), 9999 );
        add_action( 'admin_footer', array( __CLASS__, 'hide_legacy_hub_menu_items' ), 9999 );
        add_action( 'add_meta_boxes_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'recipe_knowledge_boxes' ) );
        add_action( 'save_post_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'save_recipe_knowledge' ), 20, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'woocommerce_import_notice' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_context_tabs' ), 1 );
        add_action( 'admin_head', array( __CLASS__, 'admin_navigation_styles' ) );
    }

    public static function register_legacy_types_for_migration() {
        if ( ! post_type_exists( 'ph_recipe' ) ) {
            register_post_type( 'ph_recipe', array( 'public' => false, 'show_ui' => false, 'supports' => array( 'title', 'editor' ) ) );
        }
        if ( ! post_type_exists( 'ph_ingredient' ) ) {
            register_post_type( 'ph_ingredient', array( 'public' => false, 'show_ui' => false, 'supports' => array( 'title' ) ) );
        }
    }

    public static function register_sections() {
        foreach ( self::$sections as $slug => $section ) {
            if ( class_exists( 'Persiano_Hub_Business_Profile' ) && ! Persiano_Hub_Business_Profile::is_section_enabled( $slug ) ) {
                continue;
            }
            add_submenu_page(
                'persiano-hub',
                $section['label'],
                $section['label'],
                'manage_woocommerce',
                $slug,
                static function() use ( $slug ) { self::render_section( $slug ); }
            );
        }
    }

    public static function clean_hub_menu() {
        // Keep every registered submenu page in WordPress' internal menu structure.
        // Removing entries from $submenu makes direct dashboard links fail the
        // user_can_access_admin_page() check with “not allowed to access”.
        // Legacy/tool entries are hidden visually in hide_legacy_hub_menu_items().
    }

    public static function hide_legacy_hub_menu_items() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $allowed = array_keys( self::$sections );
        if ( class_exists( 'Persiano_Hub_Business_Profile' ) ) {
            $allowed = array_values( array_intersect( $allowed, Persiano_Hub_Business_Profile::enabled_sections() ) );
        }
        // Utility pages registered outside the architecture section map must remain visible.
        $allowed[] = 'batchly-trial-monitoring';
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var menu = document.querySelector('#toplevel_page_persiano-hub .wp-submenu');
            if (!menu) return;
            var allowed = <?php echo wp_json_encode( array_values( $allowed ) ); ?>;
            menu.querySelectorAll('a').forEach(function (link) {
                try {
                    var url = new URL(link.href, window.location.origin);
                    var page = url.searchParams.get('page') || '';
                    var li = link.closest('li');
                    if (li && page && allowed.indexOf(page) === -1) li.style.display = 'none';
                } catch (e) {}
            });
        });
        </script>
        <?php
    }

    private static function tabs_for( $slug ) {
        $u = static function( $page, $extra = '' ) {
            $url = admin_url( $page );
            return $extra ? add_query_arg( $extra, '', $url ) : $url;
        };
        $tabs = array(
            'persiano-hub-operations' => array(
                'overview' => array( 'Overview', '' ),
                'orders' => array( 'Orders', admin_url( 'admin.php?page=persiano-hub-orders' ) ),
                'manual' => array( 'Manual Order', admin_url( 'admin.php?page=persiano-manual-order' ) ),
                'advance' => array( 'Advance Orders', admin_url( 'admin.php?page=persiano-hub-advance-orders' ) ),
                'production' => array( 'Production Planner', admin_url( 'admin.php?page=persiano-hub-costing&tab=production' ) ),
                'fulfilment' => array( 'Fulfilment', admin_url( 'admin.php?page=persiano-fulfilment' ) ),
                'labels' => array( 'Labels & Packing', admin_url( 'admin.php?page=persiano-hub-labels' ) ),
            ),
            'persiano-hub-products-dashboard' => array(
                'overview' => array( 'Overview', '' ),
                'products' => array( 'Products & This Week', admin_url( 'admin.php?page=persiano-hub-products' ) ),
                'all' => array( 'All Products', admin_url( 'edit.php?post_type=product' ) ),
                'new' => array( 'Add Product', admin_url( 'post-new.php?post_type=product' ) ),
                'import' => array( 'Full Import & Export', admin_url( 'admin.php?page=persiano-hub-import-export&dataset=products' ) ),
            ),
            'persiano-hub-recipes-costing' => array(
                'overview' => array( 'Overview', '' ),
                'costing' => array( 'Costing & Recipes', admin_url( 'admin.php?page=persiano-hub-costing' ) ),
                'inventory' => array( 'Inventory', admin_url( 'admin.php?page=persiano-hub-costing&tab=inventory' ) ),
                'recipes' => array( 'All Recipes', admin_url( 'edit.php?post_type=' . Persiano_Hub_Costing::RECIPE_POST_TYPE ) ),
                'ingredients' => array( 'Ingredient Master', admin_url( 'admin.php?page=persiano-hub-ingredient-master' ) ),
                'packages' => array( 'Yield & Packaging', admin_url( 'admin.php?page=persiano-hub-package-catalogue' ) ),
                'feeds' => array( 'Price Feeds', admin_url( 'admin.php?page=persiano-hub-price-feeds' ) ),
                'scan' => array( 'AI Scan Queue', admin_url( 'admin.php?page=persiano-hub-costing&tab=scan' ) ),
                'suppliers' => array( 'Suppliers', admin_url( 'edit.php?post_type=ph_supplier' ) ),
                'import' => array( 'Recipe Workbook Import', admin_url( 'admin.php?page=persiano-hub-import-export&dataset=recipe_workbook' ) ),
            ),
            'persiano-hub-customers-sales' => array(
                'overview' => array( 'Overview', '' ),
                'customers' => array( 'Customers & Loyalty', admin_url( 'admin.php?page=persiano-hub-loyalty-admin' ) ),
                'messages' => array( 'Order Correspondence', admin_url( 'admin.php?page=persiano-hub-messages' ) ),
                'square' => array( 'Square Transactions', admin_url( 'admin.php?page=persiano-hub-square-transactions' ) ),
                'mailing' => array( 'Mailing List', admin_url( 'admin.php?page=persiano-mailing-list' ) ),
                'campaigns' => array( 'Email Campaigns', admin_url( 'admin.php?page=persiano-email-campaigns' ) ),
            ),
            'persiano-hub-publishing-content' => array(
                'overview' => array( 'Overview', '' ),
                'publishing' => array( 'Publishing', admin_url( 'edit.php?post_type=persiano_campaign' ) ),
                'website' => array( 'Website Content', admin_url( 'edit.php?post_type=persiano_update' ) ),
                'events' => array( 'Events', admin_url( 'edit.php?post_type=persiano_event' ) ),
            ),
            'persiano-hub-reports' => array(
                'overview' => array( 'Overview', '' ),
                'marketing' => array( 'Marketing Analytics', admin_url( 'admin.php?page=persiano-marketing-analytics' ) ),
                'loss' => array( 'Loss & Waste', admin_url( 'admin.php?page=persiano-hub-loss-waste' ) ),
                'sales' => array( 'WooCommerce Sales', admin_url( 'admin.php?page=wc-admin&path=/analytics/orders' ) ),
                'costing' => array( 'Cost & Margin', admin_url( 'admin.php?page=persiano-hub-costing' ) ),
            ),
            'persiano-hub-system-tools' => array(
                'overview' => array( 'Overview', '' ),
                'profile' => array( 'Business Profile', admin_url( 'admin.php?page=persiano-hub-business-profile' ) ),
                'import' => array( 'Import & Export', admin_url( 'admin.php?page=persiano-hub-import-export' ) ),
                'connections' => array( 'Connections', admin_url( 'admin.php?page=persiano-hub-connections' ) ),
                'browser_capture' => array( 'Browser Price Capture', admin_url( 'admin.php?page=persiano-hub-browser-capture' ) ),
                'pos_settings' => array( 'POS & Square', admin_url( 'admin.php?page=persiano-hub-pos-settings' ) ),
                'labels' => array( 'Printer & Labels', admin_url( 'admin.php?page=persiano-hub-labels' ) ),
                'maintenance' => array( 'Maintenance', admin_url( 'admin.php?page=persiano-hub-maintenance' ) ),
                'updates' => array( 'Updates', admin_url( 'admin.php?page=persiano-hub-updates' ) ),
            ),
        );
        return $tabs[ $slug ] ?? array();
    }

    private static function section_for_current_screen() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        if ( ! $post_type && isset( $_GET['post'] ) ) {
            $post_type = get_post_type( absint( $_GET['post'] ) );
        }
        $map = array(
            'persiano-hub-orders' => 'persiano-hub-operations',
            'persiano-manual-order' => 'persiano-hub-operations',
            'persiano-hub-advance-orders' => 'persiano-hub-operations',
            'persiano-fulfilment' => 'persiano-hub-operations',
            'persiano-hub-labels' => 'persiano-hub-system-tools',
            'persiano-hub-products' => 'persiano-hub-products-dashboard',
            'persiano-hub-costing' => 'persiano-hub-recipes-costing',
            'persiano-hub-ingredient-master' => 'persiano-hub-recipes-costing',
            'persiano-hub-package-catalogue' => 'persiano-hub-recipes-costing',
            'persiano-hub-price-feeds' => 'persiano-hub-recipes-costing',
            'persiano-hub-loyalty-admin' => 'persiano-hub-customers-sales',
            'persiano-mailing-list' => 'persiano-hub-customers-sales',
            'persiano-email-campaigns' => 'persiano-hub-customers-sales',
            'persiano-hub-messages' => 'persiano-hub-customers-sales',
            'persiano-hub-messages-settings' => 'persiano-hub-customers-sales',
            'persiano-hub-square-transactions' => 'persiano-hub-customers-sales',
            'persiano-marketing-analytics' => 'persiano-hub-reports',
            'persiano-hub-loss-waste' => 'persiano-hub-reports',
            'persiano-hub-business-profile' => 'persiano-hub-system-tools',
            'persiano-hub-import-export' => 'persiano-hub-system-tools',
            'persiano-hub-connections' => 'persiano-hub-system-tools',
            'persiano-hub-pos-settings' => 'persiano-hub-system-tools',
            'persiano-hub-maintenance' => 'persiano-hub-system-tools',
            'persiano-hub-updates' => 'persiano-hub-system-tools',
        );
        if ( isset( $map[ $page ] ) ) { return $map[ $page ]; }
        if ( 'product' === $post_type ) { return 'persiano-hub-products-dashboard'; }
        if ( in_array( $post_type, array( Persiano_Hub_Costing::RECIPE_POST_TYPE, Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'ph_supplier' ), true ) ) { return 'persiano-hub-recipes-costing'; }
        if ( in_array( $post_type, array( 'persiano_campaign', 'persiano_update', 'persiano_event' ), true ) ) { return 'persiano-hub-publishing-content'; }
        return '';
    }

    public static function render_context_tabs() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $section_slug = self::section_for_current_screen();
        if ( $section_slug && class_exists( 'Persiano_Hub_Business_Profile' ) && ! Persiano_Hub_Business_Profile::is_section_enabled( $section_slug ) ) { return; }
        if ( ! $section_slug || isset( self::$sections[ isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ] ) ) { return; }
        $tabs = self::tabs_for( $section_slug );
        if ( ! $tabs ) { return; }
        $current = admin_url( add_query_arg( array(), $GLOBALS['pagenow'] ) );
        echo '<div class="wrap ph-context-tabs" style="margin:12px 20px 6px 2px"><nav class="nav-tab-wrapper">';
        foreach ( $tabs as $key => $tab ) {
            $url = $tab[1] ? $tab[1] : admin_url( 'admin.php?page=' . $section_slug );
            $active = false;
            $target_page = wp_parse_url( $url, PHP_URL_QUERY );
            parse_str( (string) $target_page, $target_args );
            $now_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
            $now_post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
            if ( isset( $target_args['page'] ) && $now_page === $target_args['page'] ) { $active = true; }
            if ( isset( $target_args['post_type'] ) && $now_post_type === $target_args['post_type'] ) { $active = true; }
            echo '<a class="nav-tab ' . ( $active ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $tab[0] ) . '</a>';
        }
        echo '</nav></div>';
    }

    public static function render_section( $slug ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $section = self::$sections[ $slug ];
        $tabs = self::tabs_for( $slug );
        echo '<div class="wrap ph-section-dashboard"><h1>' . esc_html( $section['label'] ) . '</h1><p class="description">' . esc_html( $section['intro'] ) . '</p>';
        echo '<nav class="nav-tab-wrapper ph-section-tabs">';
        foreach ( $tabs as $key => $tab ) {
            $url = $tab[1] ? $tab[1] : admin_url( 'admin.php?page=' . $slug );
            echo '<a class="nav-tab ' . ( 'overview' === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $tab[0] ) . '</a>';
        }
        echo '</nav>';
        self::render_section_content( $slug );
        echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }

    private static function render_section_content( $slug ) {
        $content = array(
            'persiano-hub-operations' => array(
                array( 'Orders', 'Review payment, fulfilment and customer actions.', 'admin.php?page=persiano-hub-orders' ),
                array( 'Production Planner', 'Scale linked master recipes from real order demand.', 'admin.php?page=persiano-hub-costing&tab=production' ),
                array( 'Labels & Packing', 'Print 3×2, 3×3 or continuous-roll labels.', 'admin.php?page=persiano-hub-labels' ),
            ),
            'persiano-hub-products' => array(
                array( 'Single master record', 'Every product is a WooCommerce product. Persiano custom fields live on the same record.', 'edit.php?post_type=product' ),
                array( 'Full importer', 'Use the Persiano importer for WooCommerce and Persiano fields. WooCommerce import remains core-fields only.', 'admin.php?page=persiano-hub-import-export&dataset=products' ),
                array( 'Availability', 'Manage This Week, Available Now, Advance Order and Unavailable.', 'admin.php?page=persiano-hub-products' ),
            ),
            'persiano-hub-recipes-costing' => array(
                array( 'One master recipe system', 'Costing & Recipes is the only recipe database. Product links and production planning read from it.', 'admin.php?page=persiano-hub-costing' ),
                array( 'Unified inventory', 'Raw ingredients, prepared internal components, sellable products and movement history.', 'admin.php?page=persiano-hub-costing&tab=inventory' ),
                array( 'Price Feed inbox', 'Paste product URLs once, process them in the background and monitor future price or availability changes.', 'admin.php?page=persiano-hub-price-feeds' ),
                array( 'Yield & Packaging', 'Review recipe yields, customer package sizes, supplier packages and workflow health.', 'admin.php?page=persiano-hub-package-catalogue' ),
                array( 'AI Scan queue', 'Upload multiple receipts, invoices and supplier PDFs without waiting for them to finish in the browser.', 'admin.php?page=persiano-hub-costing&tab=scan' ),
                array( 'Ingredient Master', 'Canonical ingredient records, aliases, duplicate review, pricing status and price history in one workspace.', 'admin.php?page=persiano-hub-ingredient-master' ),
            ),
            'persiano-hub-customers-sales' => array(
                array( 'Customers & Loyalty', 'Accounts, addresses, points and payment actions.', 'admin.php?page=persiano-hub-loyalty-admin' ),
                array( 'Order Correspondence', 'Email, SMS and system activity grouped into one chronological history for each order.', 'admin.php?page=persiano-hub-messages' ),
                array( 'Square Transactions', 'Sync payments, inspect exact WooCommerce links, process safe refunds and receive webhook updates.', 'admin.php?page=persiano-hub-square-transactions' ),
                array( 'Mailing List', 'Consent-aware subscribers and reusable multi-product campaigns.', 'admin.php?page=persiano-mailing-list' ),
                array( 'Email Campaigns', 'Limited availability, This Week, pantry restock and mixed update templates.', 'admin.php?page=persiano-email-campaigns' ),
            ),
            'persiano-hub-publishing-content' => array(
                array( 'Publishing', 'Website, Instagram feed, Reel, carousel, Story and Telegram from one record.', 'edit.php?post_type=persiano_campaign' ),
                array( 'Instagram retry', 'Failed media is rebuilt with a fresh container before retrying only Instagram.', 'edit.php?post_type=persiano_campaign' ),
                array( 'Events & Offers', 'Keep public updates connected to products and order actions.', 'edit.php?post_type=persiano_event' ),
            ),
            'persiano-hub-reports' => array(
                array( 'Sales', 'WooCommerce order and refund reporting.', 'admin.php?page=wc-admin&path=/analytics/orders' ),
                array( 'Loss & Waste', 'Comped items, waste, replacements and service recovery.', 'admin.php?page=persiano-hub-loss-waste' ),
                array( 'Marketing', 'Traffic, campaigns, attributed orders and net revenue.', 'admin.php?page=persiano-marketing-analytics' ),
            ),
            'persiano-hub-system-tools' => array(
                array( 'Business Profile', 'Branding, terminology, dashboard areas and trial-site settings.', 'admin.php?page=persiano-hub-business-profile' ),
                array( 'Import & Export', 'Mapped CSV, TSV, JSON and XLSX import/export for all master tables.', 'admin.php?page=persiano-hub-import-export' ),
                array( 'Maintenance', 'Remove test transactions and repair reporting data.', 'admin.php?page=persiano-hub-maintenance' ),
                array( 'POS & Square', 'Open the front-end POS and configure the Square application, location and callback.', 'admin.php?page=persiano-hub-pos-settings' ),
                array( 'Connections & Updates', 'Manage integrations and plugin releases.', 'admin.php?page=persiano-hub-connections' ),
            ),
        );
        echo '<div class="ph-section-grid">';
        foreach ( $content[ $slug ] ?? array() as $card ) {
            echo '<section class="ph-section-card"><h2>' . esc_html( $card[0] ) . '</h2><p>' . esc_html( $card[1] ) . '</p><a class="button button-primary" href="' . esc_url( admin_url( $card[2] ) ) . '">Open</a></section>';
        }
        echo '</div><div class="ph-guidance"><h2>How to use this section</h2><ol>';
        if ( 'persiano-hub-recipes-costing' === $slug ) {
            echo '<li>Create or import master ingredients first so every purchased item has a usable cost unit.</li><li>Create one master recipe and record its total physical output with a measurable unit, such as 1000 g, 1 kg, 1500 ml or 12 each.</li><li>For a sellable recipe, link its WooCommerce product and complete Product &gt; Persiano &gt; Size / package, such as 250 g or 500 ml. Suggested pricing will then calculate the cost of that package, not the whole batch or one gram.</li><li>Add prepared components as sub-recipes in their real units. Production Planner, scaling, shopping and price review will use the same normalized recipe data.</li>';
        } elseif ( 'persiano-hub-products' === $slug ) {
            echo '<li>Create or import the WooCommerce product once.</li><li>Complete its Persiano tab for display, availability, labels and SEO.</li><li>Link one master recipe when costing or production planning is needed.</li>';
        } else {
            echo '<li>Start from the Overview tab.</li><li>Use the tabs above for the task you need.</li><li>Return here whenever you need the workflow or setup guidance.</li>';
        }
        echo '</ol></div>';
    }

    private static function styles() {
        return '<style>.ph-section-dashboard{max-width:1280px}.ph-section-tabs{margin-top:22px}.ph-section-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin:22px 0}.ph-section-card,.ph-guidance{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px}.ph-section-card h2{margin-top:0}.ph-guidance{max-width:900px}.ph-guidance ol{font-size:14px;line-height:1.8}@media(max-width:900px){.ph-section-grid{grid-template-columns:1fr}}</style>';
    }


    public static function admin_navigation_styles() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        ?>
        <style>
        .ph-context-tabs .nav-tab-wrapper, .ph-section-tabs, .persiano-master-tabs {
            display:flex; flex-wrap:nowrap; overflow-x:auto; overflow-y:hidden; gap:4px;
            -webkit-overflow-scrolling:touch; scrollbar-width:thin; padding-bottom:1px;
        }
        .ph-context-tabs .nav-tab, .ph-section-tabs .nav-tab, .persiano-master-tabs .nav-tab {
            flex:0 0 auto; white-space:nowrap; margin-left:0;
        }
        @media (max-width:782px) {
            .ph-context-tabs { margin:10px 10px 6px 0 !important; max-width:calc(100vw - 20px); }
            .ph-context-tabs .nav-tab-wrapper, .ph-section-tabs, .persiano-master-tabs {
                border-bottom:1px solid #c3c4c7; padding:0 0 6px;
            }
            .ph-context-tabs .nav-tab, .ph-section-tabs .nav-tab, .persiano-master-tabs .nav-tab {
                font-size:14px; padding:8px 11px; margin-bottom:0;
            }
            .ph-ingredient-master-table thead { display:none; }
            .ph-ingredient-master-table, .ph-ingredient-master-table tbody, .ph-ingredient-master-table tr, .ph-ingredient-master-table td { display:block; width:100%; box-sizing:border-box; }
            .ph-ingredient-master-table tr { background:#fff; border:1px solid #dcdcde; border-radius:10px; margin:10px 0; padding:10px 12px; }
            .ph-ingredient-master-table td { border:0; padding:5px 0; }
            .ph-ingredient-master-table td:before { content:attr(data-label); display:block; color:#646970; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; margin-bottom:2px; }
            .ph-ingredient-master-table td:first-child { font-size:16px; }
        }
        </style>
        <?php
    }

    public static function recipe_knowledge_boxes() {
        // Kitchen is the single master step editor.
        add_meta_box( 'persiano_recipe_knowledge', 'Recipe Notes & Batch Knowledge', array( __CLASS__, 'render_recipe_knowledge' ), Persiano_Hub_Costing::RECIPE_POST_TYPE, 'normal', 'default' );
    }

    public static function render_recipe_steps( $post ) {
        wp_nonce_field( 'persiano_hub_recipe_knowledge', 'persiano_hub_recipe_knowledge_nonce' );
        $steps = get_post_meta( $post->ID, '_persiano_recipe_steps', true );
        if ( ! is_array( $steps ) ) { $steps = array(); }
        echo '<p>Ordered preparation instructions. These are separate from batch notes.</p><table class="widefat striped" id="ph-master-steps"><thead><tr><th>#</th><th>Title</th><th>Instruction</th><th>Minutes</th><th>Temperature</th><th>Equipment</th><th>Critical</th><th></th></tr></thead><tbody>';
        $rows = max( 1, count( $steps ) + 1 );
        for ( $i = 0; $i < $rows; $i++ ) {
            $s = $steps[ $i ] ?? array();
            echo '<tr><td><input type="number" min="1" class="small-text" name="persiano_recipe_steps[' . $i . '][number]" value="' . esc_attr( $s['number'] ?? ( $i + 1 ) ) . '"></td><td><input class="widefat" name="persiano_recipe_steps[' . $i . '][title]" value="' . esc_attr( $s['title'] ?? '' ) . '"></td><td><textarea class="widefat" rows="2" name="persiano_recipe_steps[' . $i . '][instruction]">' . esc_textarea( $s['instruction'] ?? '' ) . '</textarea></td><td><input type="number" min="0" class="small-text" name="persiano_recipe_steps[' . $i . '][duration]" value="' . esc_attr( $s['duration'] ?? '' ) . '"></td><td><input class="widefat" name="persiano_recipe_steps[' . $i . '][temperature]" value="' . esc_attr( $s['temperature'] ?? '' ) . '"></td><td><input class="widefat" name="persiano_recipe_steps[' . $i . '][equipment]" value="' . esc_attr( $s['equipment'] ?? '' ) . '"></td><td><input type="checkbox" name="persiano_recipe_steps[' . $i . '][critical]" value="1" ' . checked( ! empty( $s['critical'] ), true, false ) . '></td><td></td></tr>';
        }
        echo '</tbody></table><p class="description">Save the recipe, then a new blank row will be available.</p>';
    }

    public static function render_recipe_knowledge( $post ) {
        wp_nonce_field( 'persiano_hub_recipe_knowledge', 'persiano_hub_recipe_knowledge_nonce' );
        $fields = array(
            '_persiano_recipe_storage' => 'Storage instructions',
            '_persiano_recipe_reheating' => 'Reheating instructions',
            '_persiano_recipe_packaging' => 'Packaging / plating notes',
            '_persiano_recipe_pinned_note' => 'Pinned critical note',
            '_persiano_recipe_troubleshooting' => 'Troubleshooting / next time',
        );
        foreach ( $fields as $key => $label ) {
            echo '<p><label><strong>' . esc_html( $label ) . '</strong><textarea class="widefat" rows="3" name="' . esc_attr( $key ) . '">' . esc_textarea( get_post_meta( $post->ID, $key, true ) ) . '</textarea></label></p>';
        }
    }

    public static function save_recipe_knowledge( $post_id, $post ) {
        if ( ! isset( $_POST['persiano_hub_recipe_knowledge_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_hub_recipe_knowledge_nonce'] ) ), 'persiano_hub_recipe_knowledge' ) ) { return; }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
        foreach ( array( '_persiano_recipe_storage', '_persiano_recipe_reheating', '_persiano_recipe_packaging', '_persiano_recipe_pinned_note', '_persiano_recipe_troubleshooting' ) as $key ) {
            if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) ); }
        }
    }

    public static function maybe_migrate_master_data() {
        if ( get_option( self::MIGRATION_OPTION ) ) { return; }
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        self::migrate_legacy_ingredients();
        self::migrate_legacy_recipes();
        update_option( self::MIGRATION_OPTION, array( 'completed_at' => current_time( 'mysql' ) ), false );
    }

    private static function find_master_by_legacy( $post_type, $legacy_id, $title ) {
        $found = get_posts( array( 'post_type' => $post_type, 'post_status' => 'any', 'meta_key' => '_persiano_legacy_source_id', 'meta_value' => $legacy_id, 'posts_per_page' => 1, 'fields' => 'ids' ) );
        if ( $found ) { return (int) $found[0]; }
        $by_title = get_page_by_title( $title, OBJECT, $post_type );
        return $by_title ? (int) $by_title->ID : 0;
    }

    private static function migrate_legacy_ingredients() {
        $legacy = get_posts( array( 'post_type' => 'ph_ingredient', 'post_status' => 'any', 'posts_per_page' => -1 ) );
        foreach ( $legacy as $old ) {
            $id = self::find_master_by_legacy( Persiano_Hub_Costing::INGREDIENT_POST_TYPE, $old->ID, $old->post_title );
            if ( ! $id ) {
                $id = wp_insert_post( array( 'post_type' => Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_title' => $old->post_title, 'post_status' => 'publish' ) );
            }
            if ( is_wp_error( $id ) || ! $id ) { continue; }
            update_post_meta( $id, '_persiano_legacy_source_id', $old->ID );
            $unit = get_post_meta( $old->ID, '_ph_preferred_unit', true );
            $cost = get_post_meta( $old->ID, '_ph_cost_per_unit', true );
            update_post_meta( $id, Persiano_Hub_Costing::ING_PURCHASE_QTY, 1 );
            update_post_meta( $id, Persiano_Hub_Costing::ING_PURCHASE_UNIT, $unit ?: 'g' );
            update_post_meta( $id, Persiano_Hub_Costing::ING_PURCHASE_COST, $cost );
            update_post_meta( $id, Persiano_Hub_Costing::ING_NOTES, get_post_meta( $old->ID, '_ph_notes', true ) );
            update_post_meta( $id, '_persiano_ingredient_type', get_post_meta( $old->ID, '_ph_ingredient_type', true ) );
            update_post_meta( $id, '_persiano_grams_per_cup', get_post_meta( $old->ID, '_ph_grams_per_cup', true ) );
            update_post_meta( $id, '_persiano_grams_per_tbsp', get_post_meta( $old->ID, '_ph_grams_per_tbsp', true ) );
            update_post_meta( $id, '_persiano_grams_per_tsp', get_post_meta( $old->ID, '_ph_grams_per_tsp', true ) );
            update_post_meta( $id, '_persiano_density_g_ml', get_post_meta( $old->ID, '_ph_density_g_ml', true ) );
            update_post_meta( $id, '_persiano_include_on_label', get_post_meta( $old->ID, '_ph_include_on_label', true ) );
        }
    }

    private static function migrate_legacy_recipes() {
        $legacy = get_posts( array( 'post_type' => 'ph_recipe', 'post_status' => 'any', 'posts_per_page' => -1 ) );
        foreach ( $legacy as $old ) {
            $id = self::find_master_by_legacy( Persiano_Hub_Costing::RECIPE_POST_TYPE, $old->ID, $old->post_title );
            if ( ! $id ) {
                $id = wp_insert_post( array( 'post_type' => Persiano_Hub_Costing::RECIPE_POST_TYPE, 'post_title' => $old->post_title, 'post_status' => 'publish' ) );
            }
            if ( is_wp_error( $id ) || ! $id ) { continue; }
            update_post_meta( $id, '_persiano_legacy_source_id', $old->ID );
            update_post_meta( $id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, get_post_meta( $old->ID, '_ph_recipe_yield', true ) ?: 1 );
            update_post_meta( $id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, get_post_meta( $old->ID, '_ph_recipe_yield_unit', true ) ?: 'servings' );
            update_post_meta( $id, Persiano_Hub_Costing::RECIPE_PRODUCT_ID, absint( get_post_meta( $old->ID, '_ph_linked_product', true ) ) );
            foreach ( array( 'storage', 'reheating', 'packaging', 'pinned_note', 'troubleshooting' ) as $field ) {
                $value = get_post_meta( $old->ID, '_ph_recipe_' . $field, true );
                if ( $value ) { update_post_meta( $id, '_persiano_recipe_' . $field, $value ); }
            }
            if ( $old->post_content ) { update_post_meta( $id, '_persiano_recipe_legacy_instructions', $old->post_content ); }
        }
    }

    public static function woocommerce_import_notice() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'product_page_product_importer' !== $screen->id ) { return; }
        echo '<div class="notice notice-warning"><p><strong>Basic WooCommerce importer:</strong> this screen supports WooCommerce core fields only. For all Persiano product, SEO, availability, recipe and label fields, use <a href="' . esc_url( admin_url( 'admin.php?page=persiano-hub-import-export&dataset=products' ) ) . '">Batchly → Products → Full Import & Export</a>.</p></div>';
    }
}
