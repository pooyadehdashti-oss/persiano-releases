<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Front-end Batchly dashboard and WooCommerce-backed POS.
 *
 * This first release deliberately keeps WooCommerce as the only order source.
 * Square is launched on the payment iPhone through the Square POS mobile-web URL.
 */
class Persiano_Hub_Frontend_POS {
    const OPTION = 'persiano_hub_frontend_pos_settings';
    const NONCE  = 'persiano_hub_frontend_pos';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'rewrite' ) );
        add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'route' ), 1 );
        add_action( 'wp_ajax_phub_pos_product_search', array( __CLASS__, 'ajax_product_search' ) );
        add_action( 'wp_ajax_phub_pos_available_products', array( __CLASS__, 'ajax_available_products' ) );
        add_action( 'wp_ajax_phub_pos_email_invoice', array( __CLASS__, 'ajax_email_invoice' ) );
        add_action( 'wp_ajax_phub_pos_customer_search', array( __CLASS__, 'ajax_customer_search' ) );
        add_action( 'wp_ajax_phub_pos_create_order', array( __CLASS__, 'ajax_create_order' ) );
        add_action( 'wp_ajax_phub_pos_pending_orders', array( __CLASS__, 'ajax_pending_orders' ) );
        add_action( 'wp_ajax_phub_pos_payment_count', array( __CLASS__, 'ajax_payment_count' ) );
        add_action( 'wp_ajax_phub_pos_cancel_order', array( __CLASS__, 'ajax_cancel_order' ) );
        add_action( 'wp_ajax_phub_pos_mark_cash_paid', array( __CLASS__, 'ajax_mark_cash_paid' ) );
        add_action( 'wp_ajax_phub_pos_mark_etransfer_paid', array( __CLASS__, 'ajax_mark_etransfer_paid' ) );
        add_action( 'wp_ajax_phub_pos_adjust_cash_payment', array( __CLASS__, 'ajax_adjust_cash_payment' ) );
        add_action( 'wp_ajax_phub_pos_set_payment_route', array( __CLASS__, 'ajax_set_payment_route' ) );
        add_action( 'wp_ajax_phub_pos_verify_square', array( __CLASS__, 'ajax_verify_square' ) );
        add_action( 'wp_ajax_phub_pos_order_status', array( __CLASS__, 'ajax_order_status' ) );
        add_action( 'wp_ajax_phub_push_subscribe', array( 'Persiano_Hub_Web_Push', 'ajax_subscribe' ) );
        add_action( 'wp_ajax_phub_push_unsubscribe', array( 'Persiano_Hub_Web_Push', 'ajax_unsubscribe' ) );
        add_action( 'wp_ajax_phub_push_test', array( 'Persiano_Hub_Web_Push', 'ajax_test' ) );
        add_action( 'wp_ajax_phub_pos_create_customer', array( __CLASS__, 'ajax_create_customer' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 80 );
        add_action( 'admin_init', array( __CLASS__, 'save_settings' ) );
    }

    public static function activate() {
        self::rewrite();
        flush_rewrite_rules( false );
    }

    public static function rewrite() {
        add_rewrite_rule( '^hub/?$', 'index.php?persiano_hub_app=dashboard', 'top' );
        add_rewrite_rule( '^hub/(pos|payments|orders|customers|labels|settings)/?$', 'index.php?persiano_hub_app=$matches[1]', 'top' );
        add_rewrite_rule( '^hub/pay/([0-9]+)/?$', 'index.php?persiano_hub_app=pay&persiano_hub_order=$matches[1]', 'top' );
        add_rewrite_rule( '^hub/square-callback/?$', 'index.php?persiano_hub_app=square-callback', 'top' );
        add_rewrite_rule( '^hub/manifest\.webmanifest$', 'index.php?persiano_hub_app=manifest', 'top' );
        add_rewrite_rule( '^hub/service-worker\.js$', 'index.php?persiano_hub_app=service-worker', 'top' );
    }


    public static function maybe_flush_rewrites() {
        $version = get_option( 'batchly_hub_rewrite_version', '' );
        if ( PERSIANO_HUB_VERSION !== $version ) {
            self::rewrite();
            flush_rewrite_rules( false );
            update_option( 'batchly_hub_rewrite_version', PERSIANO_HUB_VERSION, false );
        }
    }

    private static function app_url( $route = 'dashboard', $order_id = 0 ) {
        $pretty = (string) get_option( 'permalink_structure', '' );
        if ( '' === $pretty ) {
            $args = array( 'persiano_hub_app' => $route );
            if ( $order_id ) { $args['persiano_hub_order'] = absint( $order_id ); }
            return add_query_arg( $args, home_url( '/' ) );
        }
        if ( 'dashboard' === $route ) { return home_url( '/hub/' ); }
        if ( 'pay' === $route ) { return home_url( '/hub/pay/' . absint( $order_id ) . '/' ); }
        return home_url( '/hub/' . trim( $route, '/' ) . '/' );
    }

    public static function query_vars( $vars ) {
        $vars[] = 'persiano_hub_app';
        $vars[] = 'persiano_hub_order';
        return $vars;
    }

    private static function can_use() {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' );
    }

    private static function settings() {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        if ( empty( $saved['square_webhook_token'] ) ) {
            $saved['square_webhook_token'] = wp_generate_password( 32, false, false );
            update_option( self::OPTION, $saved, false );
        }
        return wp_parse_args( $saved, array(
            'square_app_id'               => '',
            'square_location_id'          => '',
            'square_access_token'         => '',
            'square_webhook_signature_key'=> '',
            'square_webhook_token'        => '',
            'currency'                    => 'CAD',
            'business_name'               => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' ),
            'business_legal_name'         => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::get( 'legal_name', '' ) : '',
            'business_address'            => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::get( 'address', '' ) : '',
            'business_gst'                => '',
            'business_phone'              => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::get( 'support_phone', '' ) : '',
            'business_email'              => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_email() : get_option( 'admin_email' ),
            'accent'                      => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'primary_color', '#8d2d2d' ) : '#8d2d2d',
            'guest_rewards'               => 'yes',
            'bcc_customer_emails'         => 'yes',
            'bcc_email'                   => class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_email() : get_option( 'admin_email' ),
            'web_push_enabled'            => 'yes',
        ) );
    }

    private static function hub_name() {
        return class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::hub_name() : 'Batchly';
    }

    private static function hub_mark() {
        $name = trim( self::hub_name() );
        return $name ? strtoupper( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 ) ) : 'H';
    }

    public static function route() {
        $route = get_query_var( 'persiano_hub_app' );
        if ( ! $route ) { return; }

        if ( 'manifest' === $route ) { self::manifest(); }
        if ( 'service-worker' === $route ) { self::service_worker(); }

        nocache_headers();
        // Square returns through an external app/browser context that may not share the
        // WordPress login cookie. Validate the signed order callback token instead of
        // requiring an authenticated browser session for this route.
        if ( ! is_user_logged_in() ) {
            self::login_page();
        }
        if ( ! self::can_use() ) {
            status_header( 403 );
            self::shell_start( 'Access denied' );
            echo '<main class="phub-card"><h1>Access denied</h1><p>Your account does not have permission to use ' . esc_html( self::hub_name() ) . '.</p></main>';
            self::shell_end();
            exit;
        }

        if ( 'pay' === $route ) { self::payment_page( absint( get_query_var( 'persiano_hub_order' ) ) ); }
        if ( 'pos' === $route ) { self::pos_page(); }
        if ( 'payments' === $route ) { self::payments_page(); }
        if ( 'settings' === $route ) { wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-pos-settings' ) ); exit; }
        self::dashboard_page();
    }

    private static function login_page() {
        $error = '';
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['phub_login_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['phub_login_nonce'] ) ), 'phub_front_login' ) ) {
            $creds = array(
                'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
                'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ),
                'remember'      => ! empty( $_POST['rememberme'] ),
            );
            $user = wp_signon( $creds, is_ssl() );
            if ( ! is_wp_error( $user ) ) {
                wp_safe_redirect( home_url( add_query_arg( array(), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
                exit;
            }
            $error = $user->get_error_message();
        }
        self::shell_start( 'Sign in', true );
        echo '<main class="phub-login-wrap"><section class="phub-login-card"><div class="phub-mark">' . esc_html( self::hub_mark() ) . '</div><h1>' . esc_html( self::hub_name() ) . '</h1><p>Sign in to sales, payments and operations.</p>';
        if ( $error ) { echo '<div class="phub-alert">' . wp_kses_post( $error ) . '</div>'; }
        echo '<form method="post">'; wp_nonce_field( 'phub_front_login', 'phub_login_nonce' );
        echo '<label>Username or email<input name="log" autocomplete="username" required></label>';
        echo '<label>Password<input type="password" name="pwd" autocomplete="current-password" required></label>';
        echo '<label class="phub-check"><input type="checkbox" name="rememberme" value="1" checked> Keep me signed in</label>';
        echo '<button class="phub-btn phub-primary" type="submit">Sign in</button></form></section></main>';
        self::shell_end( true );
        exit;
    }

    private static function shell_start( $title, $login = false ) {
        $s = self::settings();
        ?><!doctype html><html <?php language_attributes(); ?>><head>
        <meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
        <meta name="theme-color" content="<?php echo esc_attr( $s['accent'] ); ?>"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default">
        <link rel="manifest" href="<?php echo esc_url( home_url( '/hub/manifest.webmanifest' ) ); ?>"><link rel="apple-touch-icon" href="<?php echo esc_url( self::icon_url() ); ?>">
        <title><?php echo esc_html( $title . ' — ' . self::hub_name() ); ?></title>
        <?php self::styles(); ?><?php if ( ! $login ) { echo '<script src="https://unpkg.com/@zxing/browser@0.2.1" defer></script>'; self::scripts_boot(); } ?>
        </head><body class="phub-app <?php echo $login ? 'phub-login' : ''; ?>">
        <?php if ( ! $login ) { self::header( $title ); } ?>
        <?php
    }

    private static function shell_end( $login = false ) {
        if ( ! $login ) { echo '<div id="phub-toast" class="phub-toast" aria-live="polite"></div>'; }
        echo '</body></html>';
    }

    private static function header( $title ) {
        echo '<header class="phub-top"><a class="phub-brand" href="' . esc_url( self::app_url( 'dashboard' ) ) . '"><span class="phub-mark small">' . esc_html( self::hub_mark() ) . '</span><span>' . esc_html( self::hub_name() ) . '</span></a><strong>' . esc_html( $title ) . '</strong><div class="phub-user">' . esc_html( wp_get_current_user()->display_name ) . ' · <a href="' . esc_url( wp_logout_url( self::app_url( 'dashboard' ) ) ) . '">Sign out</a></div></header>';
        echo '<nav class="phub-nav"><a href="' . esc_url( self::app_url( 'dashboard' ) ) . '">Home</a><a href="' . esc_url( self::app_url( 'pos' ) ) . '">New Sale</a><a href="' . esc_url( self::app_url( 'payments' ) ) . '">Payments <span id="phub-payment-badge" class="phub-badge" hidden>0</span></a><a href="' . esc_url( admin_url( 'admin.php?page=persiano-hub-square-transactions' ) ) . '">Square</a><a href="' . esc_url( admin_url( 'admin.php?page=persiano-hub-messages' ) ) . '">Messages</a><a href="' . esc_url( admin_url( 'admin.php?page=persiano-hub-labels' ) ) . '">Labels</a></nav>';
    }

    private static function dashboard_page() {
        self::shell_start( 'Dashboard' );
        $pending = wc_get_orders( array( 'limit' => -1, 'status' => 'pending', 'meta_key' => '_persiano_pos_order', 'meta_value' => 'yes', 'return' => 'ids' ) );
        echo '<main class="phub-main"><section class="phub-hero"><div><span class="phub-eyebrow">FRONT DESK</span><h1>What are we doing?</h1><p>One mobile-friendly workspace for WooCommerce orders and Square card payments.</p></div><a class="phub-btn phub-primary phub-big" href="' . esc_url( self::app_url( 'pos' ) ) . '">＋ New sale</a></section>';
        echo '<section class="phub-grid">';
        self::dashboard_tile( 'New Sale', 'Create a WooCommerce order, scan a product and take payment.', self::app_url( 'pos' ), '🛒' );
        self::dashboard_tile( 'Ready for Payment', count( $pending ) . ' pending sale' . ( 1 === count( $pending ) ? '' : 's' ), self::app_url( 'payments' ), '◉' );
        self::dashboard_tile( 'Square Transactions', 'Sync Square payments, review receipts and process linked refunds.', admin_url( 'admin.php?page=persiano-hub-square-transactions' ), '▣' );
        self::dashboard_tile( 'Order Correspondence', 'Review email, SMS and system activity grouped by order.', admin_url( 'admin.php?page=persiano-hub-messages' ), '✉' );
        self::dashboard_tile( 'Orders', 'View WooCommerce orders and print documents.', admin_url( 'edit.php?post_type=shop_order' ), '🧾' );
        self::dashboard_tile( 'Labels', 'Open the mixed-product label wizard.', admin_url( 'admin.php?page=persiano-hub-labels' ), '🏷' );
        echo '</section><section class="phub-card phub-install"><h2>Payment notifications</h2><p>Install ' . esc_html( self::hub_name() ) . ' on the iPhone Home Screen, then enable notifications. Enable true background alerts for new card payments, including while the iPhone is locked or ' . esc_html( self::hub_name() ) . ' is closed.</p><button type="button" class="phub-btn phub-primary" id="phub-enable-notifications">Enable background notifications</button><p id="phub-notification-status" class="phub-help"></p></section><section class="phub-card phub-install"><h2>Add to your iPhone Home Screen</h2><p>Open this page in Safari, tap Share, then choose <strong>Add to Home Screen</strong>. It opens as ' . esc_html( self::hub_name() ) . ' without the WordPress admin interface.</p></section></main>';
        self::shell_end(); exit;
    }

    private static function dashboard_tile( $title, $text, $url, $icon ) {
        echo '<a class="phub-tile" href="' . esc_url( $url ) . '"><span class="phub-tile-icon">' . esc_html( $icon ) . '</span><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p><span class="phub-arrow">→</span></a>';
    }

    private static function pos_page() {
        self::shell_start( 'New Sale' );
        ?>
        <main class="phub-main phub-pos" id="phub-pos-app">
            <section class="phub-pos-left">
                <div class="phub-card">
                    <div class="phub-section-head"><div><span class="phub-step">1</span><h2>Customer</h2></div><button class="phub-btn phub-quiet" id="phub-guest">Guest sale</button></div>
                    <div class="phub-search-row"><input id="phub-customer-search" type="search" inputmode="tel" placeholder="Phone, name or email"><button class="phub-btn" id="phub-customer-go">Look up</button></div>
                    <div id="phub-customer-results" class="phub-results"></div><div id="phub-selected-customer"></div>
                </div>
                <div class="phub-card">
                    <div class="phub-section-head"><div><span class="phub-step">2</span><h2>Products</h2></div><button class="phub-btn" id="phub-scan">▣ Scan barcode</button></div>
                    <div class="phub-search-row"><input id="phub-product-search" type="search" placeholder="Product, SKU or barcode"><button class="phub-btn" id="phub-product-go">Search</button></div>
                    <h3 class="phub-available-title">Available now</h3><div id="phub-available-products" class="phub-product-grid"></div><div id="phub-product-results" class="phub-product-grid"></div>
                </div>
            </section>
            <aside class="phub-cart-card">
                <div class="phub-section-head"><div><span class="phub-step">3</span><h2>Cart</h2></div><button class="phub-link" id="phub-clear">Clear</button></div>
                <div id="phub-cart-empty" class="phub-empty">Add a product to start the sale.</div><div id="phub-cart"></div>
                <div class="phub-fields"><label>Fulfilment<select id="phub-fulfilment"><option value="in_person">In person</option><option value="pickup">Pickup</option><option value="delivery">Delivery</option></select></label><label>Order note<textarea id="phub-note" rows="2"></textarea></label></div>
                <div class="phub-total"><span>Estimated total</span><strong id="phub-total">$0.00</strong><small>Final WooCommerce tax is calculated when the pending order is created.</small></div>
                <button class="phub-btn phub-primary phub-pay" id="phub-send">Create pending sale</button>
            </aside>
        </main>
        <dialog id="phub-scanner" class="phub-dialog"><div class="phub-dialog-head"><h2>Scan barcode</h2><button type="button" id="phub-scan-close" aria-label="Close">×</button></div><video id="phub-video" playsinline></video><p id="phub-scan-status">Point the camera at a barcode.</p><input id="phub-barcode-manual" placeholder="Or type barcode"><button class="phub-btn" id="phub-barcode-add">Find product</button></dialog>
        <dialog id="phub-handoff" class="phub-dialog phub-handoff"><div class="phub-dialog-head"><h2>Sale ready</h2><button type="button" id="phub-handoff-close">×</button></div><div id="phub-handoff-body"></div></dialog>
        <dialog id="phub-payment-method" class="phub-dialog phub-handoff"><div class="phub-dialog-head"><h2>How is the customer paying?</h2><button type="button" id="phub-payment-method-close">×</button></div><div id="phub-payment-method-body"></div></dialog>
        <?php self::pos_script(); self::shell_end(); exit;
    }

    private static function payments_page() {
        self::shell_start( 'Payments' );
        $filter = sanitize_key( $_GET['payment_status'] ?? 'pending' );
        $search = sanitize_text_field( wp_unslash( $_GET['payment_search'] ?? '' ) );
        $allowed = array( 'pending', 'verification_pending', 'paid', 'failed', 'cancelled', 'verification_error', 'partially_refunded', 'refunded', 'all' );
        if ( ! in_array( $filter, $allowed, true ) ) { $filter = 'pending'; }
        $orders = wc_get_orders( array( 'limit' => 150, 'orderby' => 'date', 'order' => 'DESC', 'meta_key' => '_persiano_pos_order', 'meta_value' => 'yes' ) );
        $rows = array();
        foreach ( $orders as $o ) {
            $pstatus = class_exists( 'Persiano_Hub_Square_Payments' ) ? Persiano_Hub_Square_Payments::derived_ledger_status( $o ) : 'pending';
            if ( in_array( $pstatus, array( 'created', 'sent_to_square' ), true ) ) { $pstatus = 'pending'; }
            if ( 'all' !== $filter && $pstatus !== $filter ) { continue; }
            if ( $search ) {
                $hay = strtolower( implode( ' ', array(
                    $o->get_id(), $o->get_order_number(), $o->get_formatted_billing_full_name(), $o->get_billing_phone(),
                    $o->get_meta( '_persiano_square_payment_id' ), $o->get_meta( '_persiano_square_transaction_id' ), $o->get_total(),
                ) ) );
                if ( false === strpos( $hay, strtolower( $search ) ) ) { continue; }
            }
            $rows[] = array( 'order' => $o, 'status' => $pstatus );
        }
        $tabs = array( 'pending'=>'Pending', 'paid'=>'Completed', 'failed'=>'Failed', 'cancelled'=>'Cancelled', 'verification_pending'=>'Verification pending', 'verification_error'=>'Verification errors', 'partially_refunded'=>'Partially refunded', 'refunded'=>'Refunded', 'all'=>'All' );
        echo '<main class="phub-main"><section class="phub-hero compact"><div><span class="phub-eyebrow">PAYMENT LEDGER</span><h1>Payments</h1><p>Pending payments are shown first. Every POS payment attempt remains searchable by order number, customer, or Square reference.</p></div></section>';
        echo '<div class="phub-payment-tabs">';
        foreach ( $tabs as $key => $label ) {
            $url = add_query_arg( array( 'payment_status' => $key ), self::app_url( 'payments' ) );
            echo '<a class="phub-btn ' . ( $filter === $key ? 'phub-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div><form class="phub-ledger-search" method="get"><input type="hidden" name="payment_status" value="' . esc_attr( $filter ) . '"><input name="payment_search" value="' . esc_attr( $search ) . '" placeholder="Order number, customer, phone, amount or Square ID"><button class="phub-btn" type="submit">Search</button></form>';
        echo '<div class="phub-order-list">';
        if ( ! $rows ) { echo '<div class="phub-card phub-empty"><h2>No matching payments</h2></div>'; }
        foreach ( $rows as $row ) {
            $o = $row['order']; $status = $row['status'];
            $payment_id = $o->get_meta( '_persiano_square_payment_id' );
            $url = self::app_url( 'pay', $o->get_id() );
            echo '<a class="phub-order" href="' . esc_url( $url ) . '"><div><span class="phub-code">' . esc_html( strtoupper( str_replace( '_', ' ', $status ) ) ) . '</span><h2>Order #' . esc_html( $o->get_order_number() ) . '</h2><p>' . esc_html( $o->get_formatted_billing_full_name() ?: ( $o->get_billing_phone() ?: 'Guest' ) ) . ' · ' . esc_html( $o->get_item_count() ) . ' item(s) · ' . esc_html( $o->get_date_created() ? $o->get_date_created()->date_i18n( 'M j, g:i a' ) : '' ) . '</p>';
            if ( $payment_id ) { echo '<small>Square: ' . esc_html( $payment_id ) . '</small>'; }
            echo '</div><strong>' . wp_kses_post( $o->get_formatted_order_total() ) . '<br><small>' . ( 'pending' === $status ? 'Take payment →' : 'View details →' ) . '</small></strong></a>';
        }
        echo '</div></main>';
        self::shell_end(); exit;
    }

    private static function payment_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) { status_header( 404 ); self::shell_start( 'Sale not found' ); echo '<main class="phub-main"><div class="phub-card"><h1>Sale not found</h1></div></main>'; self::shell_end(); exit; }
        self::shell_start( 'Take Payment' );
        $square_url = self::square_url( $order );
        echo '<main class="phub-main phub-payment"><section class="phub-card"><span class="phub-eyebrow">' . esc_html( $order->get_meta( '_persiano_pos_handoff_code' ) ) . '</span><h1>Order #' . esc_html( $order->get_order_number() ) . '</h1><p>' . esc_html( $order->get_formatted_billing_full_name() ?: 'Guest sale' ) . '</p><div class="phub-payment-items">';
        foreach ( $order->get_items() as $item ) { echo '<div><span>' . esc_html( $item->get_quantity() . ' × ' . $item->get_name() ) . '</span><strong>' . wp_kses_post( wc_price( $item->get_total() + $item->get_total_tax(), array( 'currency' => $order->get_currency() ) ) ) . '</strong></div>'; }
        echo '</div><div class="phub-payment-total"><span>Amount to charge</span><strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></div>';
        if ( isset( $_GET['paid'] ) ) { echo '<div class="phub-selected"><strong>Payment verified and recorded.</strong></div>'; }
        if ( isset( $_GET['square_error'] ) ) { echo '<div class="phub-alert"><strong>Square payment needs review.</strong><br>' . esc_html( sanitize_text_field( wp_unslash( $_GET['square_error'] ) ) ) . '</div>'; }
        if ( $order->is_paid() ) {
            echo '<div class="phub-selected"><strong>Paid — ' . esc_html( $order->get_payment_method_title() ?: 'Payment recorded' ) . '</strong></div>';
            $receipt = $order->get_meta( '_persiano_square_receipt_url' );
            if ( $receipt ) { echo '<p><a class="phub-btn" target="_blank" rel="noopener" href="' . esc_url( $receipt ) . '">Open Square receipt</a></p>'; }
            echo '<div class="phub-payment-actions"><a class="phub-btn" target="_blank" href="' . esc_url( Persiano_Hub_Order_Documents::document_url( $order->get_id(), 'invoice' ) ) . '">Print invoice</a><button class="phub-btn" id="phub-email-invoice">Email invoice</button>';
            if ( 'cash' === $order->get_meta( '_persiano_pos_payment_route' ) && current_user_can( 'manage_woocommerce' ) ) { echo '<button class="phub-btn" id="phub-adjust-cash">Adjust cash transaction</button>'; }
            echo '</div><p><a class="phub-btn phub-primary" href="' . esc_url( home_url( '/hub/pos/?new=1' ) ) . '">Start a new sale</a></p>';
            if ( 'cash' === $order->get_meta( '_persiano_pos_payment_route' ) && current_user_can( 'manage_woocommerce' ) ) {
                echo '<dialog id="phub-cash-adjust-dialog" class="phub-dialog"><div class="phub-dialog-head"><h2>Adjust cash transaction</h2><button type="button" id="phub-cash-adjust-close">×</button></div><div class="phub-form-panel"><p>Original values remain in the order history.</p><label>Cash received<input id="phub-adjust-cash-received" type="number" min="0" step="0.01" value="' . esc_attr( wc_format_decimal( $order->get_meta( '_persiano_cash_received' ), 2 ) ) . '"></label><label>Change given<input id="phub-adjust-change" type="number" min="0" step="0.01" value="' . esc_attr( wc_format_decimal( $order->get_meta( '_persiano_cash_change' ), 2 ) ) . '"></label><label>Additional tip<input id="phub-adjust-tip" type="number" min="0" step="0.01" value="0.00"></label><label>Reason<input id="phub-adjust-reason" type="text" placeholder="Customer left tip after payment"></label><button type="button" class="phub-btn phub-primary" id="phub-save-cash-adjustment">Save adjustment</button></div></dialog>';
            }
        } elseif ( $order->has_status( 'cancelled' ) ) {
            echo '<div class="phub-alert"><strong>Sale cancelled.</strong><br>No payment was recorded and inventory was not reduced.</div><p><a class="phub-btn phub-primary" href="' . esc_url( home_url( '/hub/pos/?new=1' ) ) . '">Start a new sale</a></p>';
        } elseif ( $order->has_status( 'failed' ) ) {
            echo '<div class="phub-alert"><strong>Payment failed.</strong><br>You may return the order to pending in WooCommerce and retry, or start a new sale.</div><p><a class="phub-btn phub-primary" href="' . esc_url( home_url( '/hub/pos/?new=1' ) ) . '">Start a new sale</a></p>';
        } elseif ( $order->has_status( 'refunded' ) ) {
            echo '<div class="phub-selected"><strong>Order refunded.</strong></div><p><a class="phub-btn" target="_blank" href="' . esc_url( Persiano_Hub_Order_Documents::document_url( $order->get_id(), 'invoice' ) ) . '">View invoice</a></p>';
        } elseif ( $square_url ) {
            echo '<a class="phub-btn phub-primary phub-pay" href="' . esc_attr( $square_url ) . '">Open Square Tap to Pay</a><p class="phub-help">Square opens on this device. After payment, return to Batchly or tap Verify Square payment. The server can recover a completed payment even when the Square callback was not received.</p>';
            echo '<div class="phub-payment-actions"><button class="phub-btn" id="phub-verify-square">Verify Square payment</button><button class="phub-btn" id="phub-cash">Record cash payment</button><button class="phub-btn" id="phub-etransfer">Record e-transfer</button><button class="phub-btn phub-danger" id="phub-cancel-order">Cancel sale</button></div>';
            $numeric_total = (float) $order->get_total();
            echo '<dialog id="phub-existing-cash-dialog" class="phub-dialog"><div class="phub-dialog-head"><h2>Cash payment</h2><button type="button" id="phub-existing-cash-close">×</button></div><div class="phub-form-panel"><p>Order total: <strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></p><label>Cash received<input id="phub-existing-cash-received" type="number" inputmode="decimal" min="0" step="0.01" value="' . esc_attr( wc_format_decimal( $numeric_total, 2 ) ) . '"></label><label>Tip<input id="phub-existing-cash-tip" type="number" inputmode="decimal" min="0" step="0.01" value="0.00"></label><div class="phub-cash-summary"><span>Change due</span><strong id="phub-existing-cash-change">' . wp_kses_post( wc_price( 0, array( 'currency' => $order->get_currency() ) ) ) . '</strong></div><button type="button" class="phub-btn phub-primary phub-full" id="phub-existing-cash-complete">Complete cash payment</button></div></dialog>';
            echo '<dialog id="phub-existing-etransfer-dialog" class="phub-dialog"><div class="phub-dialog-head"><h2>E-transfer payment</h2><button type="button" id="phub-existing-etransfer-close">×</button></div><div class="phub-form-panel"><p>Order total: <strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong></p><label>Amount received<input id="phub-existing-etransfer-amount" type="number" inputmode="decimal" min="0" step="0.01" value="' . esc_attr( wc_format_decimal( $numeric_total, 2 ) ) . '"></label><label>Tip<input id="phub-existing-etransfer-tip" type="number" inputmode="decimal" min="0" step="0.01" value="0.00"></label><label>Reference or note<input id="phub-existing-etransfer-reference" type="text"></label><label class="phub-confirm-check"><input id="phub-existing-etransfer-confirmed" type="checkbox"> I confirm the transfer has been received</label><button type="button" class="phub-btn phub-primary phub-full" id="phub-existing-etransfer-complete">Complete e-transfer payment</button></div></dialog>';
        } else {
            echo '<div class="phub-alert"><strong>Square setup is incomplete.</strong><br>Add the Square Application ID and location ID in Batchly → POS Settings.</div>';
        }
        echo '</section></main>';
        self::single_payment_script( $order_id ); self::shell_end(); exit;
    }

    private static function square_url( $order ) {
        if ( ! $order instanceof WC_Order || $order->is_paid() || $order->get_meta( '_persiano_square_payment_id' ) || ! $order->has_status( 'pending' ) ) { return ''; }
        $s = self::settings();
        if ( empty( $s['square_app_id'] ) ) { return ''; }
        $amount_cents = max( 1, (int) round( (float) $order->get_total() * 100 ) );
        // Do not use a WordPress nonce here. Square can return in an in-app browser
        // without the same WordPress user cookie, which makes user-bound nonces fail.
        // Use a persistent, random, order-bound callback token instead.
        $callback_token = (string) $order->get_meta( '_persiano_square_callback_token', true );
        if ( ! $callback_token ) {
            $callback_token = wp_generate_password( 40, false, false );
            $order->update_meta_data( '_persiano_square_callback_token', $callback_token );
            $order->update_meta_data( '_persiano_square_callback_token_created', time() );
            $order->save();
        }
        $state = $order->get_id() . '.' . $callback_token;
        $data = array(
            // Square's mobile-web API expects the smallest currency unit. Sending it as
            // a numeric string follows Square's iOS web examples and avoids a $0.00 handoff.
            'amount_money' => array( 'amount' => (string) $amount_cents, 'currency_code' => $order->get_currency() ?: $s['currency'] ),
            // This must exactly match the Production Web Callback URL registered in Square.
            // Never append order-specific query parameters to this URL.
            'callback_url' => home_url( '/hub/square-callback/' ),
            'client_id'    => trim( $s['square_app_id'] ),
            'options'      => array( 'supported_tender_types' => array( 'CREDIT_CARD' ), 'clear_default_fees' => true, 'auto_return' => true, 'skip_receipt' => false ),
            'version'      => '1.3',
            // Carry the WooCommerce order reference in state instead of the callback URL.
            'state'        => $state,
            'notes'        => 'WooCommerce order #' . $order->get_order_number(),
        );
        if ( ! empty( $s['square_location_id'] ) ) { $data['location_id'] = $s['square_location_id']; }
        return 'square-commerce-v1://payment/create?data=' . rawurlencode( wp_json_encode( $data ) );
    }

    private static function square_callback() {
        $raw = isset( $_REQUEST['data'] ) ? wp_unslash( $_REQUEST['data'] ) : '';
        $data = json_decode( rawurldecode( $raw ), true );
        if ( ! is_array( $data ) ) { $data = array(); }
        // Some Square/iOS versions return the response fields as ordinary query
        // parameters instead of wrapping them in data. Accept both forms.
        foreach ( array( 'state', 'status', 'transaction_id', 'client_transaction_id', 'error_code' ) as $field ) {
            if ( empty( $data[ $field ] ) && isset( $_REQUEST[ $field ] ) ) {
                $data[ $field ] = wp_unslash( $_REQUEST[ $field ] );
            }
        }

        $state = sanitize_text_field( $data['state'] ?? '' );
        $state_parts = explode( '.', $state, 2 );
        $order_id = isset( $state_parts[0] ) ? absint( $state_parts[0] ) : 0;
        $returned_token = isset( $state_parts[1] ) ? (string) $state_parts[1] : '';
        $order = wc_get_order( $order_id );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) { wp_safe_redirect( self::app_url( 'payments' ) ); exit; }
        $stored_token = (string) $order->get_meta( '_persiano_square_callback_token', true );
        $state_ok = $stored_token && $returned_token && hash_equals( $stored_token, $returned_token );
        $callback_status = strtolower( sanitize_text_field( $data['status'] ?? '' ) );
        $transaction_id = sanitize_text_field( $data['transaction_id'] ?? '' );
        $client_transaction_id = sanitize_text_field( $data['client_transaction_id'] ?? '' );

        if ( ! $state_ok ) {
            Persiano_Hub_Square_Payments::record_attempt( $order, 'verification_error', array( 'message' => 'Invalid callback state.' ) );
            wp_safe_redirect( add_query_arg( 'square_error', rawurlencode( 'Invalid Square callback state.' ), self::app_url( 'pay', $order_id ) ) ); exit;
        }

        if ( 'ok' === $callback_status && $transaction_id ) {
            Persiano_Hub_Square_Payments::record_attempt( $order, 'approved', array( 'transaction_id' => $transaction_id ) );
            $result = Persiano_Hub_Square_Payments::complete_order_from_transaction( $order, $transaction_id, $client_transaction_id );
            if ( ! is_wp_error( $result ) ) {
                wp_safe_redirect( add_query_arg( 'paid', '1', self::app_url( 'pay', $order_id ) ) ); exit;
            }
            // Square can return before the payment is visible to the API. Preserve the reference and retry safely.
            $order->update_meta_data( '_persiano_square_transaction_id', $transaction_id );
            $order->update_meta_data( '_persiano_square_client_transaction_id', $client_transaction_id );
            $order->update_meta_data( '_persiano_square_payment_status', 'verification_pending' );
            $order->save();
            Persiano_Hub_Square_Payments::schedule_verification( $order_id, $transaction_id );
            wp_safe_redirect( add_query_arg( 'square_error', rawurlencode( 'Payment received. Verification is pending and will retry automatically.' ), self::app_url( 'pay', $order_id ) ) ); exit;
        }

        $error = sanitize_text_field( $data['error_code'] ?? ( 'cancel' === $callback_status ? 'cancelled' : 'unknown_error' ) );
        $mapped = 'cancelled' === $error || 'cancel' === $callback_status ? 'cancelled' : 'failed';
        Persiano_Hub_Square_Payments::record_attempt( $order, $mapped, array( 'message' => $error ) );
        $order->add_order_note( 'Square POS payment was not completed: ' . $error );
        $order->save();
        wp_safe_redirect( add_query_arg( 'square_error', rawurlencode( $error ), self::app_url( 'pay', $order_id ) ) ); exit;
    }

    public static function ajax_product_search() {
        self::ajax_guard();
        $q = trim( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ) );
        if ( '' === $q ) { wp_send_json_success( array() ); }

        global $wpdb;
        $ids = array();
        $exact_match = false;

        // Exact product/variation ID. Keep this available to the POS even when the
        // storefront has hidden the product from the catalogue.
        if ( ctype_digit( $q ) ) {
            $direct = wc_get_product( absint( $q ) );
            if ( $direct && in_array( get_post_status( $direct->get_id() ), array( 'publish', 'private' ), true ) ) {
                $ids[] = $direct->get_id();
                $exact_match = true;
            }
        }

        // Exact SKU from WooCommerce and the underlying postmeta table. The table
        // query is authoritative when wc_product_meta_lookup is stale.
        $sku_id = wc_get_product_id_by_sku( $q );
        if ( $sku_id ) {
            $ids[] = absint( $sku_id );
            $exact_match = true;
        }
        $sku_rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN ('_sku','sku','_persiano_sku','_persiano_product_code','_persiano_barcode','_barcode','_alg_ean','_global_unique_id')
               AND LOWER(TRIM(CAST(pm.meta_value AS CHAR))) = LOWER(TRIM(%s))
               AND p.post_type IN ('product','product_variation')
               AND p.post_status IN ('publish','private','inherit')
             ORDER BY CASE WHEN pm.meta_key = '_sku' THEN 0 ELSE 1 END, pm.post_id ASC
             LIMIT 40",
            $q
        ) );
        if ( $sku_rows ) {
            $ids = array_merge( $ids, array_map( 'absint', $sku_rows ) );
            $exact_match = true;
        }

        if ( ! $exact_match ) {
            $normalized_q = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '', $q ) );
            if ( '' !== $normalized_q ) {
                $normalized_rows = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT pm.post_id
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key IN ('_sku','sku','_persiano_sku','_persiano_product_code','_persiano_barcode','_barcode','_alg_ean','_global_unique_id')
                       AND LOWER(REGEXP_REPLACE(TRIM(CAST(pm.meta_value AS CHAR)), '[^a-zA-Z0-9]', '')) = %s
                       AND p.post_type IN ('product','product_variation')
                       AND p.post_status IN ('publish','private','inherit')
                     LIMIT 40",
                    $normalized_q
                ) );
                if ( $normalized_rows ) {
                    $ids = array_merge( $ids, array_map( 'absint', $normalized_rows ) );
                    $exact_match = true;
                }
            }
        }

        // Search product titles directly. This deliberately avoids WordPress's global
        // search filters, which were causing unrelated or incomplete POS results.
        if ( ! $exact_match ) {
            $like = '%' . $wpdb->esc_like( $q ) . '%';
            $title_rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_type = 'product'
                   AND post_status IN ('publish','private')
                   AND post_title LIKE %s
                 ORDER BY
                   CASE
                     WHEN LOWER(post_title) = LOWER(%s) THEN 0
                     WHEN LOWER(post_title) LIKE LOWER(%s) THEN 1
                     ELSE 2
                   END,
                   post_title ASC
                 LIMIT 40",
                $like,
                $q,
                $q . '%'
            ) );
            $ids = array_map( 'absint', $title_rows );
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        $out = array();
        foreach ( array_slice( $ids, 0, 24 ) as $id ) {
            $p = wc_get_product( $id );
            if ( ! $p ) { continue; }

            // Do not use is_purchasable() as a search filter. The POS must be able to
            // find hidden/private products and explain their status instead of silently
            // returning no result.
            $sku = trim( (string) $p->get_sku() );
            if ( '' === $sku ) {
                foreach ( array( '_sku', 'sku', '_persiano_sku', '_persiano_product_code', '_persiano_barcode', '_barcode', '_alg_ean', '_global_unique_id' ) as $meta_key ) {
                    $candidate = trim( (string) get_post_meta( $p->get_id(), $meta_key, true ) );
                    if ( '' !== $candidate ) { $sku = $candidate; break; }
                }
            }
            $image = wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' );
            $price = (float) wc_get_price_to_display( $p );
            $out[] = array(
                'id'          => $p->get_id(),
                'name'        => $p->get_name(),
                'price'       => $price,
                'price_html'  => wp_strip_all_tags( wc_price( $price ) ),
                'sku'         => $sku,
                'product_id'  => $p->get_id(),
                'image'       => $image ?: wc_placeholder_img_src( 'thumbnail' ),
                'stock'       => $p->get_stock_status(),
                'purchasable' => $p->is_purchasable(),
            );
        }
        wp_send_json_success( $out );
    }

    public static function ajax_available_products() {
        self::ajax_guard();
        $products = wc_get_products( array(
            'status'       => array( 'publish', 'private' ),
            'limit'        => 80,
            'orderby'      => 'title',
            'order'        => 'ASC',
            'stock_status' => 'instock',
            'type'         => array( 'simple', 'variation' ),
        ) );
        $out = array();
        foreach ( $products as $p ) {
            if ( ! $p instanceof WC_Product ) { continue; }
            if ( $p->managing_stock() && $p->get_stock_quantity() <= 0 ) { continue; }
            $price = (float) wc_get_price_to_display( $p );
            $image = wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' );
            $out[] = array(
                'id'         => $p->get_id(),
                'name'       => $p->get_name(),
                'price'      => $price,
                'price_html' => wp_strip_all_tags( wc_price( $price ) ),
                'sku'        => (string) $p->get_sku(),
                'product_id' => $p->get_id(),
                'image'      => $image ?: wc_placeholder_img_src( 'thumbnail' ),
                'stock'      => $p->get_stock_status(),
            );
        }
        wp_send_json_success( $out );
    }

    public static function ajax_email_invoice() {
        self::ajax_guard();
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order ) { wp_send_json_error( array( 'message' => 'Order not found.' ), 404 ); }
        $email = sanitize_email( $order->get_billing_email() );
        if ( ! is_email( $email ) ) { wp_send_json_error( array( 'message' => 'This order has no valid customer email address.' ), 400 ); }
        $invoice_url = class_exists( 'Persiano_Hub_Order_Documents' ) ? Persiano_Hub_Order_Documents::customer_document_url( $order, 'invoice' ) : '';
        $content  = '<p>Your paid invoice for order <strong>#' . esc_html( $order->get_order_number() ) . '</strong> is ready.</p>';
        $content .= '<p><strong>Total:</strong> ' . wp_kses_post( $order->get_formatted_order_total() ) . '<br><strong>Payment:</strong> ' . esc_html( $order->get_payment_method_title() ?: 'Recorded payment' ) . '</p>';
        if ( $invoice_url ) { $content .= '<p><a href="' . esc_url( $invoice_url ) . '" style="display:inline-block;background:#8e2435;color:#fff;padding:12px 18px;border-radius:999px;text-decoration:none;font-weight:700">View / print invoice</a></p>'; }
        $brand = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' );
        $message = class_exists( 'Persiano_Hub_Email_Branding' ) ? Persiano_Hub_Email_Branding::branded_message( 'Invoice #' . $order->get_order_number(), $content, sprintf( 'Your %s invoice is ready.', $brand ) ) : $content;
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $settings = self::settings();
        if ( 'yes' === $settings['bcc_customer_emails'] && is_email( $settings['bcc_email'] ) && strtolower( $settings['bcc_email'] ) !== strtolower( $email ) ) { $headers[] = 'Bcc: ' . $settings['bcc_email']; }
        $attachments = array();
        if ( class_exists( 'Persiano_Hub_Order_Documents' ) ) {
            $pdf = Persiano_Hub_Order_Documents::generate_pdf_file( $order, 'invoice' );
            if ( ! is_wp_error( $pdf ) && is_string( $pdf ) && file_exists( $pdf ) ) { $attachments[] = $pdf; }
        }
        $invoice_subject = sprintf( 'Your %s invoice #%s', $brand, $order->get_order_number() );
        $sent = wp_mail( $email, $invoice_subject, $message, $headers, $attachments );
        if ( class_exists( 'Persiano_Hub_Customer_Messages' ) ) {
            Persiano_Hub_Customer_Messages::record_order_message( $order->get_id(), 'email', 'outbound', $sent ? 'sent' : 'failed', $email, $invoice_subject, 'Invoice email with a link and PDF attachment.', '', $sent ? '' : 'WordPress could not send the email.' );
        }
        if ( ! $sent ) { $order->add_order_note( 'Batchly invoice email failed to send to ' . $email . '.' ); wp_send_json_error( array( 'message' => 'The mail server did not accept the invoice email.' ), 500 ); }
        $order->add_order_note( 'Customer invoice email sent from Batchly POS to ' . $email . ( ! empty( $settings['bcc_email'] ) ? ' (business BCC enabled).' : '.' ) );
        wp_send_json_success( array( 'message' => 'Invoice emailed to ' . $email . '.' ) );
    }

    public static function ajax_customer_search() {
        self::ajax_guard();
        $q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        if ( strlen( $q ) < 2 ) { wp_send_json_success( array() ); }
        $phone = preg_replace( '/\D+/', '', $q );
        $users = new WP_User_Query( array( 'number' => 12, 'search' => '*' . $q . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name' ) ) );
        $all = $users->get_results();
        if ( $phone ) {
            $phone_users = get_users( array( 'number' => 12, 'meta_query' => array( 'relation' => 'OR', array( 'key' => 'billing_phone', 'value' => $phone, 'compare' => 'LIKE' ), array( 'key' => '_persiano_phone_normalized', 'value' => $phone, 'compare' => 'LIKE' ) ) ) );
            $all = array_merge( $all, $phone_users );
        }
        $out = array(); $seen = array();
        foreach ( $all as $u ) {
            if ( isset( $seen[ $u->ID ] ) ) { continue; } $seen[ $u->ID ] = true;
            $out[] = array( 'id' => $u->ID, 'name' => trim( get_user_meta( $u->ID, 'billing_first_name', true ) . ' ' . get_user_meta( $u->ID, 'billing_last_name', true ) ) ?: $u->display_name, 'phone' => get_user_meta( $u->ID, 'billing_phone', true ), 'email' => $u->user_email, 'points' => (int) get_user_meta( $u->ID, '_persiano_reward_points', true ) );
        }
        wp_send_json_success( $out );
    }

    public static function ajax_create_customer() {
        self::ajax_guard();
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! $phone ) { wp_send_json_error( array( 'message' => 'Phone number is required.' ), 400 ); }
        $parts = preg_split( '/\s+/', trim( $name ), 2 );
        if ( ! $email ) { $email = 'guest-' . preg_replace( '/\D+/', '', $phone ) . '-' . wp_generate_password( 5, false ) . '@persiano.local'; }
        if ( email_exists( $email ) ) { wp_send_json_error( array( 'message' => 'That email already belongs to a customer.' ), 400 ); }
        $username = sanitize_user( 'customer_' . preg_replace( '/\D+/', '', $phone ) );
        if ( username_exists( $username ) ) { $username .= '_' . wp_rand( 100, 999 ); }
        $id = wc_create_new_customer( $email, $username, wp_generate_password( 20 ) );
        if ( is_wp_error( $id ) ) { wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 ); }
        update_user_meta( $id, 'billing_first_name', $parts[0] ?? '' ); update_user_meta( $id, 'billing_last_name', $parts[1] ?? '' ); update_user_meta( $id, 'billing_phone', $phone ); update_user_meta( $id, '_persiano_phone_normalized', preg_replace( '/\D+/', '', $phone ) );
        wp_send_json_success( array( 'id' => $id, 'name' => $name ?: $phone, 'phone' => $phone, 'email' => $email, 'points' => 0 ) );
    }

    public static function ajax_create_order() {
        self::ajax_guard();
        $cart = json_decode( wp_unslash( $_POST['cart'] ?? '[]' ), true );
        if ( ! is_array( $cart ) || ! $cart ) { wp_send_json_error( array( 'message' => 'The cart is empty.' ), 400 ); }
        $cart_token = sanitize_text_field( wp_unslash( $_POST['cart_token'] ?? '' ) );
        if ( $cart_token ) {
            $existing = wc_get_orders( array( 'limit' => 1, 'status' => array( 'pending', 'on-hold' ), 'meta_key' => '_persiano_pos_cart_token', 'meta_value' => $cart_token, 'return' => 'objects' ) );
            foreach ( $existing as $existing_order ) {
                if ( (int) $existing_order->get_meta( '_persiano_pos_created_by' ) === get_current_user_id() ) {
                    $pay_url = self::app_url( 'pay', $existing_order->get_id() );
                    $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=8&data=' . rawurlencode( $pay_url );
                    wp_send_json_success( array( 'order_id' => $existing_order->get_id(), 'order_number' => $existing_order->get_order_number(), 'handoff_code' => $existing_order->get_meta( '_persiano_pos_handoff_code' ), 'total_html' => wp_strip_all_tags( $existing_order->get_formatted_order_total() ), 'pay_url' => $pay_url, 'qr_url' => $qr, 'existing' => true ) );
                }
            }
        }
        try {
            $order = wc_create_order( array( 'status' => 'pending', 'customer_id' => absint( $_POST['customer_id'] ?? 0 ), 'created_via' => 'persiano-hub-pos' ) );
            if ( is_wp_error( $order ) ) { throw new Exception( $order->get_error_message() ); }
            foreach ( $cart as $row ) {
                $product_id = absint( $row['id'] ?? 0 );
                $product    = wc_get_product( $product_id );
                $qty        = max( 1, absint( $row['qty'] ?? 1 ) );

                // A POS sale may legitimately use a hidden, private, out-of-catalogue,
                // or otherwise non-purchasable WooCommerce product. The cashier has
                // already selected the exact product, so do not silently discard it
                // because storefront purchasability rules return false.
                if ( ! $product || ! in_array( $product->get_type(), array( 'simple', 'variation', 'variable', 'grouped', 'external' ), true ) ) {
                    continue;
                }

                // Variable/grouped/external parent products cannot be added as a
                // concrete order line without a purchasable child/variation.
                if ( in_array( $product->get_type(), array( 'variable', 'grouped', 'external' ), true ) ) {
                    continue;
                }

                $item_id = $order->add_product( $product, $qty );
                if ( ! $item_id ) {
                    continue;
                }
                if ( ! empty( $row['price_override'] ) && current_user_can( 'manage_woocommerce' ) ) {
                    $item = $order->get_item( $item_id ); $unit = max( 0, (float) $row['price_override'] ); $item->set_subtotal( $unit * $qty ); $item->set_total( $unit * $qty ); $item->add_meta_data( '_persiano_original_unit_price', $product->get_price() ); $item->save();
                }
            }
            if ( ! $order->get_item_count() ) { throw new Exception( 'No valid products were added.' ); }
            $customer_id = absint( $_POST['customer_id'] ?? 0 );
            if ( $customer_id ) {
                $customer = new WC_Customer( $customer_id );
                $order->set_address( $customer->get_billing(), 'billing' ); $order->set_address( $customer->get_shipping(), 'shipping' );
            } else {
                $guest_phone = sanitize_text_field( wp_unslash( $_POST['guest_phone'] ?? '' ) );
                if ( $guest_phone ) { $order->set_billing_phone( $guest_phone ); $order->update_meta_data( '_persiano_guest_phone_normalized', preg_replace( '/\D+/', '', $guest_phone ) ); }
            }
            $base = wc_get_base_location();
            $order->calculate_taxes( array( 'country' => $base['country'], 'state' => $base['state'], 'postcode' => get_option( 'woocommerce_store_postcode' ), 'city' => get_option( 'woocommerce_store_city' ) ) );
            $order->calculate_totals( false );
            $order->update_meta_data( '_persiano_pos_order', 'yes' );
        $order->update_meta_data( '_persiano_sales_channel', 'Batchly POS' );
        $order->update_meta_data( '_persiano_pos_device', wp_is_mobile() ? 'Mobile / tablet' : 'Desktop / tablet' );
        $order->update_meta_data( '_persiano_pos_cashier_user_id', get_current_user_id() );
        $order->update_meta_data( '_wc_order_attribution_source_type', 'utm' );
        $order->update_meta_data( '_wc_order_attribution_utm_source', 'Batchly POS' );
        $order->update_meta_data( '_wc_order_attribution_utm_medium', 'in_person' );
        $order->update_meta_data( '_wc_order_attribution_device_type', wp_is_mobile() ? 'Mobile' : 'Desktop' );
            $order->update_meta_data( '_persiano_pos_stock_deferred', 'yes' );
            $order->update_meta_data( '_persiano_pos_fulfilment', sanitize_key( $_POST['fulfilment'] ?? 'in_person' ) );
            $order->update_meta_data( '_persiano_pos_created_by', get_current_user_id() );
            if ( $cart_token ) { $order->update_meta_data( '_persiano_pos_cart_token', $cart_token ); }
            $order->update_meta_data( '_persiano_pos_handoff_code', 'PAY-' . str_pad( (string) $order->get_id(), 4, '0', STR_PAD_LEFT ) );
            $note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ); if ( $note ) { $order->set_customer_note( $note ); }
            $order->save();
            Persiano_Hub_Square_Payments::record_attempt( $order, 'created', array( 'amount' => $order->get_total(), 'currency' => $order->get_currency() ) );
            $pay_url = self::app_url( 'pay', $order->get_id() );
            $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=8&data=' . rawurlencode( $pay_url );
            wp_send_json_success( array( 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'handoff_code' => $order->get_meta( '_persiano_pos_handoff_code' ), 'total_html' => wp_strip_all_tags( $order->get_formatted_order_total() ), 'total_value' => (float) $order->get_total(), 'pay_url' => $pay_url, 'qr_url' => $qr ) );
        } catch ( Exception $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 500 ); }
    }

    private static function active_card_orders( $return_ids = false ) {
        $orders = wc_get_orders( array(
            'limit'      => 100,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'status'     => 'pending',
            'meta_query' => array(
                array( 'key' => '_persiano_pos_order', 'value' => 'yes' ),
                array( 'key' => '_persiano_pos_payment_route', 'value' => 'card' ),
            ),
        ) );
        $active = array();
        foreach ( $orders as $order ) {
            if ( ! $order instanceof WC_Order || $order->is_paid() ) { continue; }
            $ledger = class_exists( 'Persiano_Hub_Square_Payments' ) ? Persiano_Hub_Square_Payments::derived_ledger_status( $order ) : 'pending';
            if ( ! in_array( $ledger, array( 'pending', 'verification_pending' ), true ) ) { continue; }
            $active[] = $return_ids ? $order->get_id() : $order;
        }
        return $active;
    }

    public static function ajax_pending_orders() {
        self::ajax_guard();
        $orders = self::active_card_orders( false );
        $out = array();
        foreach ( $orders as $o ) {
            $out[] = array(
                'id' => $o->get_id(), 'number' => $o->get_order_number(),
                'customer' => $o->get_formatted_billing_full_name() ?: ( $o->get_billing_phone() ?: 'Guest' ),
                'items' => $o->get_item_count(), 'total' => wp_strip_all_tags( $o->get_formatted_order_total() ),
                'time' => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'g:i a' ) : '',
                'code' => $o->get_meta( '_persiano_pos_handoff_code' ),
                'url' => self::app_url( 'pay', $o->get_id() ),
            );
        }
        wp_send_json_success( $out );
    }

    public static function ajax_payment_count() {
        self::ajax_guard();
        $ids = self::active_card_orders( true );
        wp_send_json_success( array( 'count' => count( $ids ), 'ids' => array_map( 'absint', $ids ) ) );
    }

    public static function ajax_cancel_order() {
        self::ajax_guard(); $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) { wp_send_json_error( array( 'message' => 'Sale not found.' ), 404 ); }
        $order->update_status( 'cancelled', 'POS sale cancelled before payment.' ); wp_send_json_success();
    }

    public static function ajax_set_payment_route() {
        self::ajax_guard();
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        $route = sanitize_key( wp_unslash( $_POST['route'] ?? '' ) );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) { wp_send_json_error( array( 'message' => 'Sale not found.' ), 404 ); }
        if ( ! in_array( $route, array( 'card', 'cash', 'etransfer' ), true ) ) { wp_send_json_error( array( 'message' => 'Invalid payment route.' ), 400 ); }
        $order->update_meta_data( '_persiano_pos_payment_route', $route );
        if ( 'card' === $route && ! $order->is_paid() ) {
            // Selecting Card means awaiting payment, not a failed verification.
            $order->update_meta_data( '_persiano_square_payment_status', 'pending' );
        }
        $order->save();
        if ( 'card' === $route && class_exists( 'Persiano_Hub_Web_Push' ) ) { Persiano_Hub_Web_Push::send_payment_ready( $order ); }
        wp_send_json_success();
    }

    public static function ajax_mark_cash_paid() {
        self::ajax_guard();
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) { wp_send_json_error( array( 'message' => 'Sale not found.' ), 404 ); }
        if ( $order->is_paid() ) { wp_send_json_error( array( 'message' => 'This order is already paid.' ), 409 ); }
        $received = max( 0, (float) wc_format_decimal( wp_unslash( $_POST['cash_received'] ?? 0 ) ) );
        $tip = max( 0, (float) wc_format_decimal( wp_unslash( $_POST['tip'] ?? 0 ) ) );
        $required = (float) $order->get_total() + $tip;
        if ( $received + 0.0001 < $required ) { wp_send_json_error( array( 'message' => 'Cash received is less than the total plus tip.' ), 400 ); }
        $change = max( 0, $received - $required );
        if ( $tip > 0 ) { $fee = new WC_Order_Item_Fee(); $fee->set_name( 'Tip' ); $fee->set_amount( $tip ); $fee->set_total( $tip ); $fee->set_tax_status( 'none' ); $order->add_item( $fee ); $order->calculate_totals( false ); }
        $order->update_meta_data( '_persiano_pos_payment_route', 'cash' );
        $order->set_payment_method( 'cod' ); $order->set_payment_method_title( 'Cash' );
        $order->update_meta_data( '_persiano_cash_received', $received );
        $order->update_meta_data( '_persiano_cash_change', $change );
        $order->update_meta_data( '_persiano_cash_tip', $tip );
        $order->payment_complete();
        $order->add_order_note( sprintf( 'Cash payment completed. Received %s; tip %s; change %s.', wc_price( $received, array( 'currency' => $order->get_currency() ) ), wc_price( $tip, array( 'currency' => $order->get_currency() ) ), wc_price( $change, array( 'currency' => $order->get_currency() ) ) ) );
        $order->save();
        wp_send_json_success( array( 'change' => $change, 'change_html' => wp_strip_all_tags( wc_price( $change, array( 'currency' => $order->get_currency() ) ) ) ) );
    }

    public static function ajax_mark_etransfer_paid() {
        self::ajax_guard();
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) { wp_send_json_error( array( 'message' => 'Sale not found.' ), 404 ); }
        if ( $order->is_paid() ) { wp_send_json_error( array( 'message' => 'This order is already paid.' ), 409 ); }
        $reference = sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) );
        $received = max( 0, (float) wc_format_decimal( wp_unslash( $_POST['amount_received'] ?? 0 ) ) );
        $tip = max( 0, (float) wc_format_decimal( wp_unslash( $_POST['tip'] ?? 0 ) ) );
        $required = (float) $order->get_total() + $tip;
        if ( abs( $received - $required ) > 0.01 ) { wp_send_json_error( array( 'message' => 'E-transfer received must equal the order total plus tip.' ), 400 ); }
        if ( $tip > 0 ) { $fee = new WC_Order_Item_Fee(); $fee->set_name( 'Tip' ); $fee->set_amount( $tip ); $fee->set_total( $tip ); $fee->set_tax_status( 'none' ); $order->add_item( $fee ); $order->calculate_totals( false ); }
        $order->update_meta_data( '_persiano_pos_payment_route', 'etransfer' );
        $order->set_payment_method( 'bacs' ); $order->set_payment_method_title( 'E-transfer' );
        $order->update_meta_data( '_persiano_etransfer_reference', $reference );
        $order->update_meta_data( '_persiano_etransfer_received', $received );
        $order->update_meta_data( '_persiano_etransfer_tip', $tip );
        $order->payment_complete();
        $order->add_order_note( 'E-transfer marked received in Batchly POS' . ( $reference ? '. Reference: ' . $reference : '.' ) );
        $order->save();
        wp_send_json_success();
    }


    public static function ajax_adjust_cash_payment() {
        self::ajax_guard();
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message' => 'Only an administrator or manager can adjust a completed cash transaction.' ), 403 ); }
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) || 'cash' !== $order->get_meta( '_persiano_pos_payment_route' ) || ! $order->is_paid() ) {
            wp_send_json_error( array( 'message' => 'A completed cash transaction was not found.' ), 404 );
        }
        $cash_received = max( 0, (float) wc_format_decimal( wp_unslash( $_POST['cash_received'] ?? 0 ) ) );
        $change_given  = max( 0, (float) wc_format_decimal( wp_unslash( $_POST['change_given'] ?? 0 ) ) );
        $tip_addition  = max( 0, (float) wc_format_decimal( wp_unslash( $_POST['tip_addition'] ?? 0 ) ) );
        $reason        = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
        if ( '' === $reason ) { wp_send_json_error( array( 'message' => 'Enter a reason for the adjustment.' ), 400 ); }
        $new_total = (float) $order->get_total() + $tip_addition;
        if ( ( $cash_received - $change_given ) + 0.001 < $new_total ) {
            wp_send_json_error( array( 'message' => 'Cash retained must cover the adjusted order total.' ), 400 );
        }
        if ( $tip_addition > 0 ) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name( 'Tip adjustment' );
            $fee->set_amount( $tip_addition );
            $fee->set_total( $tip_addition );
            $fee->set_tax_status( 'none' );
            $order->add_item( $fee );
            $order->calculate_totals( false );
        }
        $history = $order->get_meta( '_persiano_cash_adjustments', true );
        if ( ! is_array( $history ) ) { $history = array(); }
        $history[] = array(
            'time' => current_time( 'mysql' ),
            'user_id' => get_current_user_id(),
            'cash_received' => $cash_received,
            'change_given' => $change_given,
            'tip_addition' => $tip_addition,
            'reason' => $reason,
        );
        $order->update_meta_data( '_persiano_cash_received', $cash_received );
        $order->update_meta_data( '_persiano_cash_change', $change_given );
        $order->update_meta_data( '_persiano_cash_tip', (float) $order->get_meta( '_persiano_cash_tip' ) + $tip_addition );
        $order->update_meta_data( '_persiano_cash_adjustments', $history );
        $order->add_order_note( sprintf( 'Cash transaction adjusted by %s. Cash received %s; change %s; additional tip %s. Reason: %s', wp_get_current_user()->display_name, wc_price( $cash_received, array( 'currency' => $order->get_currency() ) ), wc_price( $change_given, array( 'currency' => $order->get_currency() ) ), wc_price( $tip_addition, array( 'currency' => $order->get_currency() ) ), $reason ) );
        $order->save();
        wp_send_json_success( array( 'message' => 'Cash transaction adjustment saved.', 'total_html' => wp_strip_all_tags( $order->get_formatted_order_total() ) ) );
    }


    public static function ajax_verify_square() {
        self::ajax_guard();
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) { wp_send_json_error( array( 'message' => 'Sale not found.' ), 404 ); }
        if ( $order->is_paid() ) { wp_send_json_success( array( 'message' => 'Payment is already recorded.', 'status' => $order->get_status() ) ); }
        $transaction_id = sanitize_text_field( $order->get_meta( '_persiano_square_transaction_id' ) );
        if ( $transaction_id ) {
            $result = Persiano_Hub_Square_Payments::complete_order_from_transaction( $order, $transaction_id, $order->get_meta( '_persiano_square_client_transaction_id' ) );
        } else {
            $result = Persiano_Hub_Square_Payments::reconcile_order( $order );
        }
        if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
        wp_send_json_success( array( 'message' => 'Square payment verified and order completed.', 'status' => $order->get_status() ) );
    }

    public static function ajax_guard() {
        if ( ! is_user_logged_in() || ! self::can_use() ) { wp_send_json_error( array( 'message' => 'Access denied.' ), 403 ); }
        check_ajax_referer( self::NONCE, 'nonce' );
    }

    public static function admin_menu() {
        add_submenu_page( 'persiano-hub', 'POS Settings', 'POS Settings', 'manage_woocommerce', 'persiano-hub-pos-settings', array( __CLASS__, 'settings_page' ) );
    }

    public static function save_settings() {
        if ( ! isset( $_POST['phub_pos_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['phub_pos_settings_nonce'] ) ), 'phub_pos_settings' ) || ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $current = self::settings();
        $token = $current['square_access_token'];
        if ( ! empty( $_POST['clear_square_access_token'] ) ) { $token = ''; }
        elseif ( ! empty( $_POST['square_access_token'] ) ) { $token = sanitize_text_field( wp_unslash( $_POST['square_access_token'] ) ); }

        $signature_key = $current['square_webhook_signature_key'];
        if ( ! empty( $_POST['clear_square_webhook_signature_key'] ) ) { $signature_key = ''; }
        elseif ( ! empty( $_POST['square_webhook_signature_key'] ) ) { $signature_key = sanitize_text_field( wp_unslash( $_POST['square_webhook_signature_key'] ) ); }

        $updated = array_merge(
            $current,
            array(
                'square_app_id'                => sanitize_text_field( wp_unslash( $_POST['square_app_id'] ?? '' ) ),
                'square_location_id'           => sanitize_text_field( wp_unslash( $_POST['square_location_id'] ?? '' ) ),
                'square_access_token'          => $token,
                'square_webhook_signature_key' => $signature_key,
                'square_webhook_token'         => ! empty( $_POST['rotate_square_webhook_token'] ) ? wp_generate_password( 32, false, false ) : ( $current['square_webhook_token'] ?: wp_generate_password( 32, false, false ) ),
                'currency'                     => in_array( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'CAD' ) ), array( 'CAD', 'USD' ), true ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'CAD' ) ) : 'CAD',
                'business_name'                => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? ( class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' ) ) ) ),
                'accent'                       => sanitize_hex_color( wp_unslash( $_POST['accent'] ?? '#8d2d2d' ) ) ?: '#8d2d2d',
                'guest_rewards'                => ! empty( $_POST['guest_rewards'] ) ? 'yes' : 'no',
                'business_legal_name'          => sanitize_text_field( wp_unslash( $_POST['business_legal_name'] ?? ( class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::legal_name() : get_bloginfo( 'name' ) ) ) ),
                'business_address'             => sanitize_textarea_field( wp_unslash( $_POST['business_address'] ?? '' ) ),
                'business_gst'                 => sanitize_text_field( wp_unslash( $_POST['business_gst'] ?? '' ) ),
                'business_phone'               => sanitize_text_field( wp_unslash( $_POST['business_phone'] ?? '' ) ),
                'business_email'               => sanitize_email( wp_unslash( $_POST['business_email'] ?? get_option( 'admin_email' ) ) ),
                'bcc_customer_emails'          => ! empty( $_POST['bcc_customer_emails'] ) ? 'yes' : 'no',
                'bcc_email'                    => sanitize_email( wp_unslash( $_POST['bcc_email'] ?? 'info@persianodish.com' ) ),
                'web_push_enabled'             => ! empty( $_POST['web_push_enabled'] ) ? 'yes' : 'no',
            )
        );
        update_option( self::OPTION, $updated, false );
        add_settings_error( 'phub_pos', 'saved', 'POS & Square settings saved.', 'updated' );
    }

    public static function settings_page() {
        $s = self::settings();
        settings_errors( 'phub_pos' );
        $webhook_url = class_exists( 'Persiano_Hub_Square_Transactions' ) ? Persiano_Hub_Square_Transactions::webhook_url() : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Batchly POS & Square Settings', 'persiano-hub' ); ?></h1>
            <p>The front-end dashboard is available at <a href="<?php echo esc_url( self::app_url( 'dashboard' ) ); ?>"><strong><?php echo esc_html( self::app_url( 'dashboard' ) ); ?></strong></a>.</p>
            <form method="post">
                <?php wp_nonce_field( 'phub_pos_settings', 'phub_pos_settings_nonce' ); ?>
                <h2>Square connection</h2>
                <table class="form-table">
                    <tr><th>Square Application ID</th><td><input class="regular-text" name="square_app_id" value="<?php echo esc_attr( $s['square_app_id'] ); ?>"><p class="description">The production application ID from Square Developer Console.</p></td></tr>
                    <tr><th>Square Location ID</th><td><input class="regular-text" name="square_location_id" value="<?php echo esc_attr( $s['square_location_id'] ); ?>"></td></tr>
                    <tr><th>Square Production Access Token</th><td><input class="regular-text" type="password" name="square_access_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $s['square_access_token'] ) ? 'Saved — enter only to replace' : 'Paste production token' ); ?>"><p class="description">Required for server-side verification, transaction sync and refunds. It is never shown in this page or sent to the POS browser.</p><?php if ( ! empty( $s['square_access_token'] ) ) : ?><label><input type="checkbox" name="clear_square_access_token" value="1"> Remove saved token</label><?php endif; ?></td></tr>
                    <tr><th>Webhook signature key</th><td><input class="regular-text" type="password" name="square_webhook_signature_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $s['square_webhook_signature_key'] ) ? 'Saved — enter only to replace' : 'Paste webhook signature key' ); ?>"><p class="description">Copy the signature key for this webhook subscription from Square Developer Console.</p><?php if ( ! empty( $s['square_webhook_signature_key'] ) ) : ?><label><input type="checkbox" name="clear_square_webhook_signature_key" value="1"> Remove saved signature key</label><?php endif; ?></td></tr>
                    <tr><th>Webhook URL</th><td><input class="large-text code" readonly value="<?php echo esc_attr( $webhook_url ); ?>"><p class="description">Subscribe this URL to payment.created, payment.updated, refund.created and refund.updated.</p></td></tr>
                    <tr><th>Rotate webhook URL token</th><td><label><input type="checkbox" name="rotate_square_webhook_token" value="1"> Generate a new private URL when saving</label><p class="description">After rotating, replace the notification URL in Square.</p></td></tr>
                    <tr><th>Square callback URL</th><td><input class="large-text code" readonly value="<?php echo esc_attr( home_url( '/hub/square-callback/' ) ); ?>"></td></tr>
                </table>

                <h2>Business and POS</h2>
                <table class="form-table">
                    <tr><th>Currency</th><td><select name="currency"><option value="CAD" <?php selected( $s['currency'], 'CAD' ); ?>>CAD</option><option value="USD" <?php selected( $s['currency'], 'USD' ); ?>>USD</option></select></td></tr>
                    <tr><th>Business name</th><td><input class="regular-text" name="business_name" value="<?php echo esc_attr( $s['business_name'] ); ?>"></td></tr>
                    <tr><th>Legal business name</th><td><input class="regular-text" name="business_legal_name" value="<?php echo esc_attr( $s['business_legal_name'] ); ?>"></td></tr>
                    <tr><th>Business address</th><td><textarea class="large-text" rows="3" name="business_address"><?php echo esc_textarea( $s['business_address'] ); ?></textarea></td></tr>
                    <tr><th>GST number</th><td><input class="regular-text" name="business_gst" value="<?php echo esc_attr( $s['business_gst'] ); ?>"></td></tr>
                    <tr><th>Business phone</th><td><input class="regular-text" name="business_phone" value="<?php echo esc_attr( $s['business_phone'] ); ?>"></td></tr>
                    <tr><th>Invoice email</th><td><input class="regular-text" type="email" name="business_email" value="<?php echo esc_attr( $s['business_email'] ); ?>"></td></tr>
                    <tr><th>BCC customer emails</th><td><label><input type="checkbox" name="bcc_customer_emails" value="1" <?php checked( $s['bcc_customer_emails'], 'yes' ); ?>> Send a hidden business copy of every customer-facing order/invoice email</label><p><input class="regular-text" type="email" name="bcc_email" value="<?php echo esc_attr( $s['bcc_email'] ); ?>"></p></td></tr>
                    <tr><th>Background payment push</th><td><label><input type="checkbox" name="web_push_enabled" value="1" <?php checked( $s['web_push_enabled'], 'yes' ); ?>> Send locked-screen notifications to subscribed devices for new Card / Square orders</label><p class="description">Enable the notification once from the installed Home Screen app. VAPID keys are generated and stored on this server.</p><button type="button" class="button" id="phub-test-push">Send test push to my devices</button></td></tr>
                    <tr><th>Accent colour</th><td><input type="color" name="accent" value="<?php echo esc_attr( $s['accent'] ); ?>"></td></tr>
                    <tr><th>Guest rewards ledger</th><td><label><input type="checkbox" name="guest_rewards" value="1" <?php checked( $s['guest_rewards'], 'yes' ); ?>> Preserve guest purchase identity by normalized phone for future account matching</label></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>document.addEventListener("DOMContentLoaded",()=>{const b=document.getElementById("phub-test-push");if(!b)return;b.addEventListener("click",async()=>{b.disabled=true;b.textContent="Sending…";try{const p=new URLSearchParams({action:"phub_push_test",nonce:"<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>"});const r=await fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:p});const j=await r.json();alert(j.success?j.data.message:(j.data&&j.data.message?j.data.message:"Push test failed."))}catch(e){alert(e.message)}finally{b.disabled=false;b.textContent="Send test push to my devices"}})})</script>
        <?php
    }

    private static function scripts_boot() {
        $cfg = array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE ), 'currency' => self::settings()['currency'], 'hub' => self::app_url( 'dashboard' ), 'icon' => self::icon_url(), 'vapid' => class_exists( 'Persiano_Hub_Web_Push' ) ? Persiano_Hub_Web_Push::public_key() : '' );
        echo '<script>window.PHUB=' . wp_json_encode( $cfg ) . ';</script>';
        ?>
<script>
(()=>{
let swReg=null,lastIds=null,firstPoll=true,pollSeq=0,appliedSeq=0,pollController=null,pollTimer=null;
const setBadge=count=>{const b=document.getElementById('phub-payment-badge');if(!b)return;b.textContent=count;b.hidden=count<1;if('setAppBadge'in navigator){count?navigator.setAppBadge(count).catch(()=>{}):navigator.clearAppBadge().catch(()=>{})}};
const notify=async order=>{if(Notification.permission!=='granted')return;const title='Payment ready — '+order.total;const options={body:'Order #'+order.number+' · '+order.items+' item'+(order.items==1?'':'s')+' · '+order.customer,icon:PHUB.icon,badge:PHUB.icon,tag:'phub-payment-'+order.id,data:{url:order.url}};try{if(swReg)await swReg.showNotification(title,options);else new Notification(title,options)}catch(e){}};
const b64ToUint=s=>{const pad='='.repeat((4-s.length%4)%4),raw=atob((s+pad).replace(/-/g,'+').replace(/_/g,'/'));return Uint8Array.from([...raw].map(c=>c.charCodeAt(0)))};
async function saveSubscription(sub){const p=new URLSearchParams({action:'phub_push_subscribe',nonce:PHUB.nonce,subscription:JSON.stringify(sub.toJSON())});const r=await fetch(PHUB.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:p});const j=await r.json();if(!j.success)throw new Error(j.data?.message||'Could not save push subscription.')}
async function enablePush(){if(!swReg||!PHUB.vapid)throw new Error('Background push is not ready on this site.');let sub=await swReg.pushManager.getSubscription();if(!sub)sub=await swReg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:b64ToUint(PHUB.vapid)});await saveSubscription(sub);return sub}
async function poll(){const seq=++pollSeq;if(pollController)pollController.abort();pollController=new AbortController();try{const p=new URLSearchParams({action:'phub_pos_pending_orders',nonce:PHUB.nonce});const r=await fetch(PHUB.ajax+'?'+p.toString(),{credentials:'same-origin',cache:'no-store',signal:pollController.signal});const j=await r.json();if(!j.success||seq<appliedSeq)return;appliedSeq=seq;const orders=j.data||[],ids=orders.map(o=>String(o.id));setBadge(ids.length);if(lastIds!==null&&!firstPoll){orders.filter(o=>!lastIds.includes(String(o.id))).forEach(notify)}lastIds=ids;firstPoll=false}catch(e){if(e.name!=='AbortError')console.warn('Payment badge refresh failed',e)}}
window.PHUB_REFRESH_PAYMENTS=poll;
navigator.serviceWorker?.addEventListener('message',e=>{if(e.data&&e.data.type==='PHUB_NAVIGATE'&&e.data.url)window.location.assign(e.data.url)});window.addEventListener('load',async()=>{if('serviceWorker'in navigator){try{swReg=await navigator.serviceWorker.register(PHUB.hub+'service-worker.js?v=0.51.0',{scope:PHUB.hub,updateViaCache:'none'});await navigator.serviceWorker.ready}catch(e){}}await poll();if(!pollTimer)pollTimer=setInterval(poll,12000)});
window.addEventListener('focus',poll);document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll()});
window.addEventListener('load',()=>{const btn=document.getElementById('phub-enable-notifications'),status=document.getElementById('phub-notification-status');if(!btn)return;const refresh=async()=>{if(!('Notification'in window)||!('serviceWorker'in navigator)||!('PushManager'in window)){btn.disabled=true;status.textContent='Background notifications are not supported in this browser.';return}if(Notification.permission==='granted'){const sub=swReg?await swReg.pushManager.getSubscription():null;btn.textContent=sub?'Background notifications enabled':'Finish enabling notifications';btn.disabled=!!sub;status.textContent=sub?'This device is subscribed for locked-screen payment alerts.':'Tap once more to register this device.'}else if(Notification.permission==='denied'){btn.disabled=true;status.textContent='Notifications are blocked. Enable them in iPhone Settings for Batchly.'}};setTimeout(refresh,400);btn.addEventListener('click',async()=>{btn.disabled=true;try{const result=await Notification.requestPermission();if(result!=='granted')throw new Error('Notification permission was not granted.');await enablePush();status.textContent='Background notifications enabled. Lock-screen alerts are ready.';btn.textContent='Background notifications enabled';firstPoll=false;await poll()}catch(e){btn.disabled=false;status.textContent=e.message||'Could not enable background notifications.'}})});
})();
</script><?php
    }

    private static function pos_script() { ?>
<script>
(()=>{const $=s=>document.querySelector(s), state={cart:[],customer:null,createdOrder:null,cartToken:(crypto.randomUUID?crypto.randomUUID():Date.now()+'-'+Math.random())};
const toast=m=>{const t=$('#phub-toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2500)};
const money=n=>new Intl.NumberFormat('en-CA',{style:'currency',currency:PHUB.currency}).format(n);
async function api(action,data={},method='GET',timeoutMs=30000){const p=new URLSearchParams({action,nonce:PHUB.nonce,...data});const url=method==='GET'?PHUB.ajax+'?'+p.toString():PHUB.ajax;const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),timeoutMs);const options={method,credentials:'same-origin',signal:controller.signal};if(method==='POST'){options.headers={'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'};options.body=p.toString()}try{const r=await fetch(url,options);const text=await r.text();let j;try{j=JSON.parse(text)}catch(e){throw new Error(text&&text.length<240?text:'The server returned an unreadable response.')}if(!r.ok||j?.success===false){throw new Error(j?.data?.message||'Request failed.')}return j}catch(e){if(e.name==='AbortError')throw new Error('The sale request timed out. Please check Orders before trying again.');throw e}finally{clearTimeout(timeout)}}
function drawCart(){const box=$('#phub-cart'), empty=$('#phub-cart-empty');box.innerHTML='';empty.hidden=state.cart.length>0;let total=0;state.cart.forEach((x,i)=>{total+=(x.priceOverride??x.price)*x.qty;const el=document.createElement('div');el.className='phub-cart-row';el.innerHTML=`<div><strong>${x.name}</strong><small>${x.sku||''}</small></div><div class="phub-qty"><button data-d="-1">−</button><span>${x.qty}</span><button data-d="1">＋</button></div><strong>${money((x.priceOverride??x.price)*x.qty)}</strong><button class="phub-remove">×</button>`;el.querySelectorAll('[data-d]').forEach(b=>b.onclick=()=>{x.qty+=+b.dataset.d;if(x.qty<1)state.cart.splice(i,1);drawCart()});el.querySelector('.phub-remove').onclick=()=>{state.cart.splice(i,1);drawCart()};box.append(el)});$('#phub-total').textContent=money(total)}
function add(p){const x=state.cart.find(i=>i.id==p.id);if(x)x.qty++;else state.cart.push({id:p.id,name:p.name,sku:p.sku,price:+p.price,qty:1});drawCart();toast('Added '+p.name)}
let handoffPollTimer=null;
function stopHandoffPoll(){if(handoffPollTimer){clearTimeout(handoffPollTimer);handoffPollTimer=null}}
function startHandoffPoll(order){stopHandoffPoll();let checks=0;const run=async()=>{if(!state.createdOrder||state.createdOrder.order_id!=order.order_id)return;try{const j=await api('phub_pos_order_status',{order_id:order.order_id},'POST',30000);if(j.success&&j.data?.paid){stopHandoffPoll();const invoice=j.data.invoice_url||'#';$('#phub-handoff-body').innerHTML=`<div class="phub-success">✓</div><h3>Payment completed</h3><p>WooCommerce order #${order.order_number} has been paid.</p><a class="phub-btn phub-primary" target="_blank" href="${invoice}">Print invoice</a><a class="phub-btn" href="${PHUB.hub}pay/${order.order_id}/">Open completed sale</a><button type="button" class="phub-btn" id="phub-finish-new-sale">Start a new sale</button>`;$('#phub-send').textContent='Paid';$('#phub-send').disabled=true;if(!$('#phub-handoff').open)$('#phub-handoff').showModal();$('#phub-finish-new-sale').onclick=()=>{state.createdOrder=null;state.cart=[];state.customer=null;state.cartToken=(crypto.randomUUID?crypto.randomUUID():Date.now()+'-'+Math.random());$('#phub-selected-customer').innerHTML='';$('#phub-customer-search').value='';$('#phub-product-search').value='';$('#phub-product-results').innerHTML='';$('#phub-note').value='';$('#phub-send').disabled=false;$('#phub-send').textContent='Create pending sale';drawCart();$('#phub-handoff').close();toast('Payment completed. New sale ready')};if(window.PHUB_REFRESH_PAYMENTS)window.PHUB_REFRESH_PAYMENTS();return}if(j.success&&['cancelled','failed','refunded'].includes(j.data?.status)){stopHandoffPoll();const label=j.data.status==='cancelled'?'Sale cancelled':(j.data.status==='failed'?'Payment failed':'Order refunded');$('#phub-handoff-body').innerHTML=`<div class="phub-alert"><strong>${label}</strong><br>${j.data.status==='cancelled'?'No payment was recorded.':'The order is no longer awaiting payment.'}</div><button type="button" class="phub-btn phub-primary" id="phub-terminal-new-sale">Start a new sale</button>`;$('#phub-send').textContent=label;$('#phub-send').disabled=true;if(!$('#phub-handoff').open)$('#phub-handoff').showModal();$('#phub-terminal-new-sale').onclick=()=>{state.createdOrder=null;state.cart=[];state.customer=null;state.cartToken=(crypto.randomUUID?crypto.randomUUID():Date.now()+'-'+Math.random());$('#phub-selected-customer').innerHTML='';$('#phub-customer-search').value='';$('#phub-product-search').value='';$('#phub-product-results').innerHTML='';$('#phub-note').value='';$('#phub-send').disabled=false;$('#phub-send').textContent='Create pending sale';drawCart();$('#phub-handoff').close();toast(label+'. New sale ready')};if(window.PHUB_REFRESH_PAYMENTS)window.PHUB_REFRESH_PAYMENTS();return}}catch(e){}checks++;handoffPollTimer=setTimeout(run,checks<12?3000:9000)};handoffPollTimer=setTimeout(run,2500)}
async function available(){const box=$('#phub-available-products');box.innerHTML='<div class="phub-empty">Loading available products…</div>';try{const j=await api('phub_pos_available_products');box.innerHTML='';(j.data||[]).forEach(p=>{const e=document.createElement('button');e.type='button';e.className='phub-product';e.innerHTML=`<img src="${p.image}" alt=""><span><strong>${p.name}</strong><small>${p.sku?'SKU '+p.sku:'Product ID '+p.product_id}</small><b>${p.price_html}</b></span>`;e.onclick=()=>add(p);box.append(e)});if(!box.children.length)box.innerHTML='<div class="phub-empty">No products are currently in stock.</div>'}catch(e){box.innerHTML='<div class="phub-alert">'+e.message+'</div>'}}
async function products(q){q=(q||'').trim();const box=$('#phub-product-results'),availableBox=$('#phub-available-products'),title=$('.phub-available-title');if(!q){box.innerHTML='';availableBox.hidden=false;title.hidden=false;return}availableBox.hidden=true;title.hidden=true;box.innerHTML='<div class="phub-empty">Searching…</div>';try{const j=await api('phub_pos_product_search',{q});box.innerHTML='';(j.data||[]).forEach(p=>{const e=document.createElement('button');e.type='button';e.className='phub-product'+(p.stock==='outofstock'?' phub-outofstock':'');e.innerHTML=`<img src="${p.image}" alt=""><span><strong>${p.name}</strong><small>${p.sku ? 'SKU '+p.sku : 'Product ID '+p.product_id}${p.stock==='outofstock'?' · OUT OF STOCK':''}</small><b>${p.price_html}</b></span>`;e.onclick=()=>{if(p.stock==='outofstock'&&!confirm('This product is out of stock. Add it as a manual sale anyway?'))return;add(p)};box.append(e)});if(!box.children.length)box.innerHTML='<div class="phub-empty">No matching product.</div>'}catch(e){box.innerHTML='<div class="phub-alert">'+e.message+'</div>';toast('Product search failed')}}
async function customers(q){if(!q)return;const box=$('#phub-customer-results');box.innerHTML='<div class="phub-empty">Searching…</div>';try{const j=await api('phub_pos_customer_search',{q});box.innerHTML='';(j.data||[]).forEach(c=>{const e=document.createElement('button');e.type='button';e.className='phub-result';e.innerHTML=`<strong>${c.name}</strong><span>${c.phone||''} ${c.email||''}</span>`;e.onclick=()=>selectCustomer(c);box.append(e)});if(!box.children.length)box.innerHTML='<div class="phub-empty">No customer found. Continue as guest or create one later.</div>'}catch(e){box.innerHTML='<div class="phub-alert">'+e.message+'</div>';toast('Customer search failed')}}
function selectCustomer(c){state.customer=c;$('#phub-selected-customer').innerHTML=`<div class="phub-selected"><div><strong>${c.name}</strong><small>${c.phone||''} · ${c.email||''}</small></div><button>Change</button></div>`;$('#phub-customer-results').innerHTML='';$('#phub-selected-customer button').onclick=()=>{state.customer=null;$('#phub-selected-customer').innerHTML=''}}
$('#phub-product-go').onclick=()=>products($('#phub-product-search').value);$('#phub-product-search').onkeydown=e=>{if(e.key==='Enter')products(e.target.value)};$('#phub-product-search').oninput=e=>{if(!e.target.value.trim())products('')};$('#phub-customer-go').onclick=()=>customers($('#phub-customer-search').value);$('#phub-customer-search').onkeydown=e=>{if(e.key==='Enter')customers(e.target.value)};$('#phub-guest').onclick=()=>selectCustomer({id:0,name:'Guest sale',phone:$('#phub-customer-search').value,email:''});$('#phub-clear').onclick=()=>{if(state.createdOrder)return toast('Start a new sale to clear this stored order');state.cart=[];drawCart()};
$('#phub-send').onclick=async()=>{if(state.createdOrder){$('#phub-handoff').showModal();return}if(!state.cart.length)return toast('Add at least one product');const b=$('#phub-send');b.disabled=true;b.textContent='Creating…';try{const j=await api('phub_pos_create_order',{cart:JSON.stringify(state.cart),cart_token:state.cartToken,customer_id:state.customer?.id||0,guest_phone:state.customer?.id?'':(state.customer?.phone||$('#phub-customer-search').value),fulfilment:$('#phub-fulfilment').value,note:$('#phub-note').value},'POST',45000);const d=j.data;state.createdOrder=d;b.textContent='Choose payment';
const showCard=()=>{const dlg=$('#phub-handoff');$('#phub-handoff-body').innerHTML=`<div class="phub-success">✓</div><h3>${d.handoff_code}</h3><p>WooCommerce order #${d.order_number} · <strong>${d.total_html}</strong></p><img class="phub-qr" src="${d.qr_url}" alt="Payment handoff QR"><p>Scan this QR on the payment iPhone, or open the sale directly on this device.</p><a class="phub-btn phub-primary" href="${d.pay_url}">Open card payment</a><button type="button" class="phub-btn" id="phub-keep-order">Close and keep this sale</button><button type="button" class="phub-btn" id="phub-new-sale">Start a new sale</button>`;dlg.showModal();$('#phub-keep-order').onclick=()=>dlg.close();$('#phub-handoff-close').onclick=()=>dlg.close();$('#phub-new-sale').onclick=resetSale;startHandoffPoll(d)};
const resetSale=()=>{stopHandoffPoll();state.createdOrder=null;state.cart=[];state.customer=null;state.cartToken=(crypto.randomUUID?crypto.randomUUID():Date.now()+'-'+Math.random());$('#phub-selected-customer').innerHTML='';$('#phub-customer-search').value='';$('#phub-product-search').value='';products('');$('#phub-note').value='';b.disabled=false;b.textContent='Create pending sale';drawCart();$('#phub-handoff').open&&$('#phub-handoff').close();$('#phub-payment-method').open&&$('#phub-payment-method').close();toast('New sale ready')};
const pm=$('#phub-payment-method');const paymentMenu=()=>{ $('#phub-payment-method-body').innerHTML=`<p>WooCommerce order #${d.order_number} · <strong>${d.total_html}</strong></p><div class="phub-payment-choice"><button class="phub-btn phub-primary" id="phub-pay-card">Card / Square</button><button class="phub-btn" id="phub-pay-cash">Cash</button><button class="phub-btn" id="phub-pay-etransfer">E-transfer</button></div><button class="phub-btn" id="phub-payment-later">Close and keep pending</button>`;pm.showModal();$('#phub-payment-method-close').onclick=()=>pm.close();$('#phub-payment-later').onclick=()=>pm.close();$('#phub-pay-card').onclick=async()=>{try{await api('phub_pos_set_payment_route',{order_id:d.order_id,route:'card'},'POST');pm.close();showCard();if(window.PHUB_REFRESH_PAYMENTS)window.PHUB_REFRESH_PAYMENTS()}catch(e){toast(e.message)}};$('#phub-pay-cash').onclick=()=>cashForm();$('#phub-pay-etransfer').onclick=()=>etransferForm()};
const orderTotal=Number.isFinite(Number(d.total_value))?Number(d.total_value):0;
const cashForm=()=>{ $('#phub-payment-method-body').innerHTML=`<div class="phub-form-panel"><button type="button" class="phub-link phub-form-back">← Payment methods</button><h3>Cash payment</h3><p>Order total: <strong>${d.total_html}</strong></p><label>Cash received<input id="phub-cash-received" type="number" inputmode="decimal" min="0" step="0.01" value="${orderTotal.toFixed(2)}"></label><label>Tip (optional)<input id="phub-cash-tip" type="number" inputmode="decimal" min="0" step="0.01" value="0.00"></label><div class="phub-cash-summary"><span>Change due</span><strong id="phub-cash-change">${money(0)}</strong></div><button type="button" class="phub-btn phub-primary phub-full" id="phub-complete-cash">Complete cash payment</button></div>`;const received=$('#phub-cash-received'),tip=$('#phub-cash-tip'),change=$('#phub-cash-change');const calc=()=>{const r=parseFloat(received.value)||0,t=parseFloat(tip.value)||0;change.textContent=money(Math.max(0,r-orderTotal-t))};received.oninput=calc;tip.oninput=calc;calc();$('.phub-form-back').onclick=paymentMenu;$('#phub-complete-cash').onclick=async(e)=>{const btn=e.currentTarget,r=parseFloat(received.value),t=parseFloat(tip.value)||0;if(!Number.isFinite(r)||r<orderTotal+t){toast('Cash received must cover the order and tip.');return}btn.disabled=true;btn.textContent='Completing…';try{const result=await api('phub_pos_mark_cash_paid',{order_id:d.order_id,cash_received:r,tip:t},'POST');pm.close();toast('Cash payment completed. Change: '+(result.data?.change_html||money(0)));startHandoffPoll(d)}catch(err){btn.disabled=false;btn.textContent='Complete cash payment';toast(err.message)}}};
const etransferForm=()=>{ $('#phub-payment-method-body').innerHTML=`<div class="phub-form-panel"><button type="button" class="phub-link phub-form-back">← Payment methods</button><h3>E-transfer payment</h3><p>Order total: <strong>${d.total_html}</strong></p><label>Amount received<input id="phub-etransfer-amount" type="number" inputmode="decimal" min="0" step="0.01" value="${orderTotal.toFixed(2)}"></label><label>Reference or note (optional)<input id="phub-etransfer-reference" type="text" placeholder="Confirmation number or note"></label><label>Tip (optional)<input id="phub-etransfer-tip" type="number" inputmode="decimal" min="0" step="0.01" value="0.00"></label><label class="phub-confirm-check"><input id="phub-etransfer-confirmed" type="checkbox"> I confirm the transfer has been received</label><button type="button" class="phub-btn phub-primary phub-full" id="phub-complete-etransfer">Complete e-transfer payment</button></div>`;$('.phub-form-back').onclick=paymentMenu;$('#phub-complete-etransfer').onclick=async(e)=>{if(!$('#phub-etransfer-confirmed').checked){toast('Confirm that the transfer was received.');return}const amount=parseFloat($('#phub-etransfer-amount').value),tip=parseFloat($('#phub-etransfer-tip').value)||0;if(!Number.isFinite(amount)||Math.abs(amount-(orderTotal+tip))>0.01){toast('Amount received must equal the order total plus tip.');return}const btn=e.currentTarget;btn.disabled=true;btn.textContent='Completing…';try{await api('phub_pos_mark_etransfer_paid',{order_id:d.order_id,amount_received:amount,reference:$('#phub-etransfer-reference').value,tip:tip},'POST');pm.close();toast('E-transfer payment completed.');startHandoffPoll(d)}catch(err){btn.disabled=false;btn.textContent='Complete e-transfer payment';toast(err.message)}}};
paymentMenu();if(window.PHUB_REFRESH_PAYMENTS)window.PHUB_REFRESH_PAYMENTS()}catch(e){b.disabled=false;b.textContent='Create pending sale';toast(e.message||'Could not create sale');alert(e.message||'Could not create sale.')}};
$('#phub-handoff-close').onclick=()=>$('#phub-handoff').close();
let stream=null,timer=null,zxingControls=null;const dlg=$('#phub-scanner'),video=$('#phub-video');async function stop(){clearInterval(timer);timer=null;if(zxingControls&&zxingControls.stop)zxingControls.stop();zxingControls=null;if(stream)stream.getTracks().forEach(t=>t.stop());stream=null;video.srcObject=null}async function handleCode(v){if(!v)return;$('#phub-barcode-manual').value=v;await stop();dlg.close();products(v)}$('#phub-scan').onclick=async()=>{dlg.showModal();$('#phub-scan-status').textContent='Starting camera…';if(!navigator.mediaDevices?.getUserMedia){$('#phub-scan-status').textContent='Camera access is not supported in this browser. Type the barcode below.';return}try{if('BarcodeDetector'in window){stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}});video.srcObject=stream;await video.play();const formats=await BarcodeDetector.getSupportedFormats().catch(()=>['code_128','ean_13','ean_8','upc_a','upc_e','qr_code']);const wanted=['code_128','ean_13','ean_8','upc_a','upc_e','qr_code'].filter(x=>formats.includes(x));const det=new BarcodeDetector(wanted.length?{formats:wanted}:undefined);$('#phub-scan-status').textContent='Point the camera at the barcode.';timer=setInterval(async()=>{try{const codes=await det.detect(video);if(codes[0])handleCode(codes[0].rawValue)}catch(e){}},400)}else if(window.ZXingBrowser?.BrowserMultiFormatReader){const reader=new ZXingBrowser.BrowserMultiFormatReader();$('#phub-scan-status').textContent='Point the camera at the barcode.';zxingControls=await reader.decodeFromConstraints({video:{facingMode:{ideal:'environment'}}},video,(result,error,controls)=>{if(result){zxingControls=controls;handleCode(result.getText())}})}else{$('#phub-scan-status').textContent='The scanner library could not load. Check your connection or type the barcode below.'}}catch(e){$('#phub-scan-status').textContent='Camera could not start. Check Safari camera permission, then try again.'}};$('#phub-scan-close').onclick=async()=>{await stop();dlg.close()};$('#phub-barcode-add').onclick=async()=>{const v=$('#phub-barcode-manual').value.trim();await stop();dlg.close();products(v)};drawCart();available();})();
</script><?php }

    private static function payments_script() { ?>
<script>(async()=>{const box=document.querySelector('#phub-pending-list');const p=new URLSearchParams({action:'phub_pos_pending_orders',nonce:PHUB.nonce});const j=await fetch(PHUB.ajax+'?'+p).then(r=>r.json());box.innerHTML='';(j.data||[]).forEach(o=>{const a=document.createElement('a');a.className='phub-order';a.href=o.url;a.innerHTML=`<div><span class="phub-code">${o.code}</span><h2>Order #${o.number}</h2><p>${o.customer} · ${o.items} item${o.items==1?'':'s'} · ${o.time}</p></div><strong>${o.total}<br><small>Take payment →</small></strong>`;box.append(a)});if(!box.children.length)box.innerHTML='<div class="phub-card phub-empty"><h2>No pending sales</h2><p>Create a sale on the phone, tablet or computer and it will appear here.</p></div>'})();</script><?php }


    public static function ajax_order_status() {
        self::ajax_guard();
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! $order || 'yes' !== $order->get_meta( '_persiano_pos_order' ) ) {
            wp_send_json_error( array( 'message' => 'POS order was not found.' ), 404 );
        }

        // Do not rely only on Square's browser callback or WP-Cron. While the
        // payment page or the computer/tablet handoff is open, use the existing
        // status poll as a safe server-side reconciliation opportunity. The
        // reconciler still requires the exact WooCommerce order reference in
        // Square and never falls back to amount-only matching.
        if ( ! $order->is_paid() && $order->has_status( 'pending' ) && class_exists( 'Persiano_Hub_Square_Payments' ) ) {
            $last_check = absint( $order->get_meta( '_persiano_square_last_poll_reconcile', true ) );
            if ( time() - $last_check >= 4 ) {
                $order->update_meta_data( '_persiano_square_last_poll_reconcile', time() );
                $order->save();
                $result = Persiano_Hub_Square_Payments::reconcile_order( $order );
                if ( is_wp_error( $result ) ) {
                    $code = $result->get_error_code();
                    if ( ! in_array( $code, array( 'square_payment_not_found', 'square_order_missing', 'square_payment_missing' ), true ) ) {
                        $order->update_meta_data( '_persiano_square_payment_status', 'verification_pending' );
                        $order->save();
                    }
                } else {
                    $order = wc_get_order( $order->get_id() );
                }
            }
        }

        $payment_status = sanitize_key( (string) $order->get_meta( '_persiano_square_payment_status', true ) );
        wp_send_json_success( array(
            'order_id'       => $order->get_id(),
            'status'         => $order->get_status(),
            'paid'           => $order->is_paid(),
            'payment_status' => $payment_status ?: ( $order->is_paid() ? 'paid' : 'pending' ),
            'payment_id'     => (string) $order->get_meta( '_persiano_square_payment_id', true ),
            'invoice_url'    => class_exists( 'Persiano_Hub_Order_Documents' ) ? Persiano_Hub_Order_Documents::document_url( $order->get_id(), 'invoice' ) : '',
        ) );
    }

    private static function single_payment_script( $order_id ) {
        $order = wc_get_order( $order_id );
        $order_total = $order instanceof WC_Order ? (float) $order->get_total() : 0;
        $currency = $order instanceof WC_Order ? $order->get_currency() : get_woocommerce_currency();
        ?>
<script>(()=>{
const orderTotal=<?php echo wp_json_encode( $order_total ); ?>;
const currency=<?php echo wp_json_encode( $currency ); ?>;
const money=n=>new Intl.NumberFormat(undefined,{style:'currency',currency}).format(Number(n)||0);
const call=async(action,data={})=>{const p=new URLSearchParams({action,nonce:PHUB.nonce,order_id:'<?php echo absint( $order_id ); ?>',...data});const r=await fetch(PHUB.ajax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:p});let j;try{j=await r.json()}catch(e){throw new Error('The server returned an invalid response.')}if(!j.success)throw new Error(j.data?.message||'The request could not be completed.');return j};
document.querySelector('#phub-verify-square')?.addEventListener('click',async(e)=>{const b=e.currentTarget;b.disabled=true;b.textContent='Verifying…';try{const j=await call('phub_pos_verify_square');alert(j.data.message);location.reload()}catch(err){alert(err.message);b.disabled=false;b.textContent='Verify Square payment'}});
const cashDialog=document.querySelector('#phub-existing-cash-dialog');
document.querySelector('#phub-cash')?.addEventListener('click',()=>cashDialog?.showModal());
document.querySelector('#phub-existing-cash-close')?.addEventListener('click',()=>cashDialog?.close());
const cashReceived=document.querySelector('#phub-existing-cash-received'),cashTip=document.querySelector('#phub-existing-cash-tip'),cashChange=document.querySelector('#phub-existing-cash-change');
const updateCash=()=>{if(!cashChange)return;const r=parseFloat(cashReceived?.value)||0,t=parseFloat(cashTip?.value)||0;cashChange.textContent=money(Math.max(0,r-orderTotal-t))};cashReceived?.addEventListener('input',updateCash);cashTip?.addEventListener('input',updateCash);updateCash();
document.querySelector('#phub-existing-cash-complete')?.addEventListener('click',async(e)=>{const b=e.currentTarget,r=parseFloat(cashReceived.value),t=parseFloat(cashTip.value)||0;if(!Number.isFinite(r)||r+0.0001<orderTotal+t){alert('Cash received must cover the order total plus tip.');return}b.disabled=true;b.textContent='Completing…';try{const j=await call('phub_pos_mark_cash_paid',{cash_received:r,tip:t});cashDialog.close();alert('Cash payment recorded. Change due: '+(j.data?.change_html||money(0)));location.reload()}catch(err){alert(err.message);b.disabled=false;b.textContent='Complete cash payment'}});
const etDialog=document.querySelector('#phub-existing-etransfer-dialog');
document.querySelector('#phub-etransfer')?.addEventListener('click',()=>etDialog?.showModal());
document.querySelector('#phub-existing-etransfer-close')?.addEventListener('click',()=>etDialog?.close());
document.querySelector('#phub-existing-etransfer-complete')?.addEventListener('click',async(e)=>{if(!document.querySelector('#phub-existing-etransfer-confirmed')?.checked){alert('Confirm that the transfer has been received.');return}const amount=parseFloat(document.querySelector('#phub-existing-etransfer-amount').value),tip=parseFloat(document.querySelector('#phub-existing-etransfer-tip').value)||0;if(!Number.isFinite(amount)||Math.abs(amount-(orderTotal+tip))>0.01){alert('Amount received must equal the order total plus tip.');return}const b=e.currentTarget;b.disabled=true;b.textContent='Completing…';try{await call('phub_pos_mark_etransfer_paid',{amount_received:amount,tip,reference:document.querySelector('#phub-existing-etransfer-reference').value});etDialog.close();alert('E-transfer payment recorded.');location.reload()}catch(err){alert(err.message);b.disabled=false;b.textContent='Complete e-transfer payment'}});
document.querySelector('#phub-email-invoice')?.addEventListener('click',async(e)=>{const b=e.currentTarget;b.disabled=true;b.textContent='Sending…';try{const j=await call('phub_pos_email_invoice');alert(j.data?.message||'Invoice email request completed.')}catch(err){alert(err.message)}b.disabled=false;b.textContent='Email invoice'});
document.querySelector('#phub-adjust-cash')?.addEventListener('click',()=>document.querySelector('#phub-cash-adjust-dialog')?.showModal());document.querySelector('#phub-cash-adjust-close')?.addEventListener('click',()=>document.querySelector('#phub-cash-adjust-dialog')?.close());document.querySelector('#phub-save-cash-adjustment')?.addEventListener('click',async(e)=>{const b=e.currentTarget;b.disabled=true;b.textContent='Saving…';try{const j=await call('phub_pos_adjust_cash_payment',{cash_received:document.querySelector('#phub-adjust-cash-received').value,change_given:document.querySelector('#phub-adjust-change').value,tip_addition:document.querySelector('#phub-adjust-tip').value,reason:document.querySelector('#phub-adjust-reason').value});alert(j.data.message);location.reload()}catch(err){alert(err.message);b.disabled=false;b.textContent='Save adjustment'}});
document.querySelector('#phub-cancel-order')?.addEventListener('click',async()=>{if(confirm('Cancel this pending sale?')){try{await call('phub_pos_cancel_order');location.href=PHUB.hub+'payments/'}catch(err){alert(err.message)}}});let checks=0;const poll=async()=>{try{const j=await call('phub_pos_order_status');if(j.data.paid||['cancelled','failed','refunded'].includes(j.data.status)){location.reload();return}}catch(e){}checks++;setTimeout(poll,checks<10?3000:9000)};setTimeout(poll,2500)})();</script><?php }

    private static function icon_url() {
        $custom = get_site_icon_url( 512 );
        return $custom ?: includes_url( 'images/w-logo-blue-white-bg.png' );
    }

    private static function manifest() {
        header( 'Content-Type: application/manifest+json; charset=utf-8' );
        echo wp_json_encode( array( 'name' => self::hub_name(), 'short_name' => self::hub_name(), 'start_url' => self::app_url( 'dashboard' ), 'scope' => self::app_url( 'dashboard' ), 'display' => 'standalone', 'background_color' => '#f6f1e8', 'theme_color' => self::settings()['accent'], 'icons' => array( array( 'src' => self::icon_url(), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ) ) ) ); exit;
    }

    private static function service_worker() {
        header( 'Content-Type: application/javascript; charset=utf-8' ); header( 'Service-Worker-Allowed: /hub/' );
        $rest = esc_url_raw( rest_url( 'persiano-hub/v1/push-latest' ) );
        $hub_name = wp_json_encode( self::hub_name() );
        echo "const PHUB_NAME=" . $hub_name . ";self.addEventListener('install',e=>self.skipWaiting());self.addEventListener('activate',e=>e.waitUntil(self.clients.claim()));self.addEventListener('fetch',()=>{});self.addEventListener('push',e=>{e.waitUntil((async()=>{let data={title:PHUB_NAME,body:'A payment is ready.',url:'/hub/payments/',tag:'phub-payment'};try{const sub=await self.registration.pushManager.getSubscription();if(sub){const bytes=new TextEncoder().encode(sub.endpoint),digest=await crypto.subtle.digest('SHA-256',bytes),hash=[...new Uint8Array(digest)].map(b=>b.toString(16).padStart(2,'0')).join('');const r=await fetch('" . $rest . "?subscription='+hash,{cache:'no-store'});if(r.ok)data=await r.json()}}catch(x){}await self.registration.showNotification(data.title||PHUB_NAME,{body:data.body||'A payment is ready.',icon:'" . esc_url_raw( self::icon_url() ) . "',badge:'" . esc_url_raw( self::icon_url() ) . "',tag:data.tag||'phub-payment',data:{url:data.url||'/hub/payments/'}})})())});self.addEventListener('notificationclick',e=>{e.notification.close();const raw=e.notification.data&&e.notification.data.url?e.notification.data.url:'/hub/payments/';const target=new URL(raw,self.location.origin).href;e.waitUntil((async()=>{const cs=await clients.matchAll({type:'window',includeUncontrolled:true});for(const c of cs){if(c.url===target&&'focus'in c)return c.focus()}for(const c of cs){if('postMessage'in c){c.postMessage({type:'PHUB_NAVIGATE',url:target});if('focus'in c)await c.focus();return}}return clients.openWindow(target)})())});"; exit;
    }

    private static function styles() { $accent = self::settings()['accent']; ?>
<style>
:root{--phub-accent:<?php echo esc_html( $accent ); ?>;--phub-bg:#f6f1e8;--phub-card:#fff;--phub-ink:#251c18;--phub-muted:#746963;--phub-line:#e5ddd3;--phub-radius:18px;--phub-shadow:0 10px 30px rgba(49,34,26,.08)}*{box-sizing:border-box}html{background:var(--phub-bg)}body.phub-app{margin:0;background:var(--phub-bg);color:var(--phub-ink);font:15px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.phub-top{height:64px;padding:0 max(18px,env(safe-area-inset-left));display:flex;align-items:center;justify-content:space-between;background:#fff;border-bottom:1px solid var(--phub-line);position:sticky;top:0;z-index:20}.phub-brand{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--phub-ink);font-weight:800}.phub-mark{width:64px;height:64px;border-radius:20px;background:var(--phub-accent);color:#fff;display:grid;place-items:center;font:700 34px Georgia}.phub-mark.small{width:34px;height:34px;border-radius:11px;font-size:20px}.phub-user{font-size:13px;color:var(--phub-muted)}.phub-user a{color:inherit}.phub-nav{display:flex;gap:8px;justify-content:center;padding:10px;background:rgba(246,241,232,.94);position:sticky;top:64px;z-index:19;border-bottom:1px solid var(--phub-line)}.phub-nav a{text-decoration:none;color:var(--phub-ink);padding:8px 12px;border-radius:999px;font-weight:650}.phub-nav a:hover{background:#fff}.phub-badge{display:inline-grid;place-items:center;min-width:20px;height:20px;padding:0 6px;margin-left:4px;border-radius:999px;background:var(--phub-accent);color:#fff;font-size:11px;font-weight:800}.phub-badge[hidden]{display:none}.phub-main{max-width:1240px;margin:0 auto;padding:28px 22px 90px}.phub-hero{display:flex;justify-content:space-between;align-items:center;gap:24px;margin-bottom:24px}.phub-hero.compact h1{font-size:30px}.phub-hero h1{font:700 clamp(32px,5vw,58px)/1.05 Georgia;margin:5px 0}.phub-hero p{color:var(--phub-muted);max-width:600px}.phub-eyebrow{font-size:11px;letter-spacing:.16em;font-weight:800;color:var(--phub-accent)}.phub-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.phub-tile,.phub-card{background:var(--phub-card);border:1px solid var(--phub-line);border-radius:var(--phub-radius);box-shadow:var(--phub-shadow)}.phub-tile{padding:24px;color:var(--phub-ink);text-decoration:none;position:relative;min-height:210px}.phub-tile:hover{transform:translateY(-2px)}.phub-tile-icon{font-size:30px}.phub-tile h2{margin:25px 0 6px}.phub-tile p{color:var(--phub-muted)}.phub-arrow{position:absolute;right:20px;bottom:16px;font-size:24px}.phub-card{padding:22px}.phub-install{margin-top:20px}.phub-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid var(--phub-line);background:#fff;color:var(--phub-ink);border-radius:12px;padding:11px 15px;font-weight:750;text-decoration:none;cursor:pointer}.phub-btn:hover{filter:brightness(.98)}.phub-primary{background:var(--phub-accent);color:#fff;border-color:var(--phub-accent)}.phub-big{padding:15px 22px}.phub-quiet{background:transparent}.phub-danger{color:#9d2020}.phub-link{border:0;background:none;text-decoration:underline;color:var(--phub-muted);cursor:pointer}.phub-pos{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(330px,.75fr);gap:18px;align-items:start}.phub-pos-left{display:grid;gap:18px}.phub-cart-card{background:#fff;border:1px solid var(--phub-line);border-radius:var(--phub-radius);box-shadow:var(--phub-shadow);padding:22px;position:sticky;top:130px}.phub-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}.phub-section-head>div{display:flex;align-items:center;gap:10px}.phub-section-head h2{margin:0}.phub-step{display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:#efe2d8;color:var(--phub-accent);font-weight:800}.phub-search-row{display:grid;grid-template-columns:1fr auto;gap:8px}.phub-app input,.phub-app select,.phub-app textarea{width:100%;border:1px solid #d8cfc5;border-radius:12px;padding:12px 13px;background:#fff;font:inherit;color:var(--phub-ink)}.phub-results{display:grid;gap:6px;margin-top:8px}.phub-result{display:flex;justify-content:space-between;text-align:left;border:1px solid var(--phub-line);background:#fff;border-radius:10px;padding:10px;cursor:pointer}.phub-result span,.phub-selected small{color:var(--phub-muted)}.phub-selected{display:flex;align-items:center;justify-content:space-between;background:#edf6ed;border-radius:12px;padding:12px;margin-top:10px}.phub-selected div{display:grid}.phub-selected button{border:0;background:none;text-decoration:underline}.phub-available-title{margin:18px 0 8px}.phub-product-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}.phub-product-grid[hidden],.phub-available-title[hidden]{display:none!important}.phub-product{display:flex;gap:10px;text-align:left;border:1px solid var(--phub-line);background:#fff;border-radius:13px;padding:9px;cursor:pointer}.phub-product img{width:62px;height:62px;border-radius:9px;object-fit:cover}.phub-product span{display:grid;min-width:0}.phub-product strong{font-size:13px;line-height:1.2}.phub-product small{color:var(--phub-muted)}.phub-product b{color:var(--phub-accent)}.phub-product.phub-outofstock{border-color:#d99;background:#fff7f7}.phub-product.phub-outofstock small{color:#a33;font-weight:700}.phub-payment-choice{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:18px 0}.phub-form-panel{display:grid;gap:14px;text-align:left}.phub-form-panel h3{margin:0;font-size:24px}.phub-form-panel p{margin:0}.phub-form-panel label{display:grid;gap:6px;font-weight:700}.phub-form-panel .phub-link{justify-self:start}.phub-cash-summary{display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--phub-line);border-bottom:1px solid var(--phub-line);padding:14px 0}.phub-cash-summary strong{font-size:24px}.phub-full{width:100%}.phub-confirm-check{grid-template-columns:auto 1fr!important;align-items:center}.phub-confirm-check input{width:auto!important}.phub-empty{text-align:center;color:var(--phub-muted);padding:22px}.phub-cart-row{display:grid;grid-template-columns:1fr auto auto auto;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid var(--phub-line)}.phub-cart-row>div:first-child{display:grid}.phub-cart-row small{color:var(--phub-muted)}.phub-qty{display:flex;align-items:center;gap:4px}.phub-qty button,.phub-remove{border:0;background:#f3eee8;border-radius:8px;width:28px;height:28px;cursor:pointer}.phub-fields{display:grid;gap:10px;margin:18px 0}.phub-fields label{display:grid;gap:5px;font-weight:650}.phub-total{border-top:2px solid var(--phub-ink);padding-top:14px;display:grid;grid-template-columns:1fr auto;align-items:end}.phub-total strong{font-size:27px}.phub-total small{grid-column:1/-1;color:var(--phub-muted);margin-top:4px}.phub-pay{width:100%;font-size:17px;padding:15px;margin-top:16px}.phub-dialog{border:0;border-radius:20px;padding:20px;max-width:460px;width:calc(100% - 30px);box-shadow:0 25px 80px rgba(0,0,0,.3)}.phub-dialog::backdrop{background:rgba(24,18,15,.62)}.phub-dialog-head{display:flex;justify-content:space-between;align-items:center}.phub-dialog-head h2{margin:0}.phub-dialog-head button{font-size:28px;border:0;background:none}.phub-dialog video{width:100%;min-height:250px;background:#111;border-radius:14px;margin:12px 0;object-fit:cover}.phub-handoff{text-align:center}.phub-success{width:60px;height:60px;border-radius:50%;display:grid;place-items:center;background:#daf0dd;color:#246c31;font-size:30px;margin:auto}.phub-qr{width:220px;max-width:80%}.phub-handoff .phub-btn{margin:5px}.phub-order-list{display:grid;gap:10px}.phub-order{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid var(--phub-line);border-radius:16px;padding:18px 20px;text-decoration:none;color:var(--phub-ink)}.phub-order h2{margin:4px 0}.phub-order p{margin:0;color:var(--phub-muted)}.phub-order>strong{text-align:right;font-size:20px}.phub-order small{font-size:12px;color:var(--phub-accent)}.phub-code{font-size:11px;font-weight:800;letter-spacing:.12em;color:var(--phub-accent)}.phub-payment{max-width:650px}.phub-payment .phub-card{padding:30px}.phub-payment h1{font:700 40px Georgia;margin:5px 0}.phub-payment-items>div{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--phub-line)}.phub-payment-total{display:flex;justify-content:space-between;align-items:end;margin-top:20px}.phub-payment-total strong{font-size:36px}.phub-payment-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}.phub-help{font-size:13px;color:var(--phub-muted)}.phub-alert{background:#fff1d7;border:1px solid #e8c67f;border-radius:12px;padding:12px;margin:12px 0}.phub-toast{position:fixed;left:50%;bottom:30px;transform:translate(-50%,20px);background:#211b18;color:#fff;padding:11px 16px;border-radius:12px;opacity:0;transition:.2s;z-index:99}.phub-toast.show{opacity:1;transform:translate(-50%,0)}.phub-login-wrap{min-height:100vh;display:grid;place-items:center;padding:22px}.phub-login-card{width:min(420px,100%);background:#fff;border-radius:24px;padding:34px;box-shadow:var(--phub-shadow)}.phub-login-card .phub-mark{margin:auto}.phub-login-card h1{text-align:center;font:700 36px Georgia;margin:15px 0 4px}.phub-login-card>p{text-align:center;color:var(--phub-muted)}.phub-login-card form{display:grid;gap:14px;margin-top:22px}.phub-login-card label{display:grid;gap:6px;font-weight:650}.phub-login-card .phub-check{display:flex;align-items:center}.phub-login-card .phub-check input{width:auto}
.phub-payment-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.phub-ledger-search{display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:16px}.phub-order small{display:block;margin-top:4px}
@media(max-width:900px){.phub-grid{grid-template-columns:1fr 1fr}.phub-pos{grid-template-columns:1fr}.phub-cart-card{position:static}.phub-product-grid{grid-template-columns:1fr 1fr}.phub-user{display:none}}
@media(max-width:600px){.phub-payment-choice{grid-template-columns:1fr}.phub-top>strong{display:none}.phub-nav{justify-content:flex-start;overflow:auto}.phub-nav a{white-space:nowrap}.phub-main{padding:18px 12px 90px}.phub-hero{align-items:flex-start;flex-direction:column}.phub-grid{grid-template-columns:1fr}.phub-tile{min-height:145px}.phub-product-grid{grid-template-columns:1fr}.phub-search-row{grid-template-columns:1fr}.phub-cart-row{grid-template-columns:1fr auto auto}.phub-cart-row .phub-remove{grid-column:3}.phub-payment-actions{grid-template-columns:1fr}.phub-order{align-items:flex-start}.phub-top{padding-top:env(safe-area-inset-top);height:calc(60px + env(safe-area-inset-top))}.phub-nav{top:calc(60px + env(safe-area-inset-top))}.phub-cart-card{padding:16px}.phub-card{padding:16px}}
</style><?php }
}
