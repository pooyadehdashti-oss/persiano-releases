<?php
/**
 * Multi-channel publishing for Batchly.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Publishing {
    const POST_TYPE           = 'persiano_campaign';
    const UPDATE_POST_TYPE    = 'persiano_update';
    const PROMOTION_POST_TYPE = 'persiano_promotion';
    const OPTION_CONNECTIONS  = 'persiano_hub_publishing_connections';
    const META_PREFIX         = '_ph_pub_';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'init', array( __CLASS__, 'register_public_post_types' ) );
        // The Persiano campaign editor relies on custom meta boxes and media fields.
        // Force the stable classic post editor instead of Gutenberg for this private
        // operational post type; this also avoids blank editor screens caused by
        // block-editor/plugin conflicts in wp-admin.
        add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor_for_campaigns' ), 10, 2 );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
        add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'register_meta_boxes' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_campaign' ), 20, 3 );
        add_action( 'transition_post_status', array( __CLASS__, 'schedule_initial_dispatch' ), 20, 3 );
        add_action( 'persiano_hub_dispatch_campaign', array( __CLASS__, 'dispatch_scheduled_campaign' ), 10, 1 );
        add_action( 'persiano_hub_dispatch_campaign_mode', array( __CLASS__, 'dispatch_scheduled_campaign_mode' ), 10, 2 );
        add_action( 'persiano_hub_dispatch_channel', array( __CLASS__, 'dispatch_scheduled_channel' ), 10, 3 );
        add_action( 'persiano_hub_end_product_availability', array( __CLASS__, 'end_product_availability' ), 10, 2 );

        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'campaign_columns' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_campaign_column' ), 10, 2 );
        add_filter( 'post_row_actions', array( __CLASS__, 'campaign_row_actions' ), 10, 2 );

        add_action( 'admin_post_persiano_hub_publish_campaign', array( __CLASS__, 'handle_manual_publish' ) );
        add_action( 'admin_post_persiano_hub_schedule_campaign', array( __CLASS__, 'handle_schedule_campaign' ) );
        add_action( 'admin_post_persiano_hub_schedule_control', array( __CLASS__, 'handle_schedule_control' ) );
        add_action( 'admin_post_persiano_hub_repair_website_destination', array( __CLASS__, 'handle_repair_website_destination' ) );
        add_action( 'admin_post_persiano_hub_create_product_campaign', array( __CLASS__, 'handle_create_product_campaign' ) );
        add_action( 'admin_post_persiano_hub_save_connections', array( __CLASS__, 'handle_save_connections' ) );
        add_action( 'admin_post_persiano_hub_test_connection', array( __CLASS__, 'handle_test_connection' ) );
        add_action( 'admin_post_persiano_hub_refresh_google_resources', array( __CLASS__, 'handle_refresh_google_resources' ) );
        add_action( 'admin_post_persiano_hub_meta_instagram_oauth', array( __CLASS__, 'handle_meta_instagram_oauth_callback' ) );
        add_action( 'admin_post_persiano_hub_google_oauth', array( __CLASS__, 'handle_google_oauth_callback' ) );
        // Legacy callback support for Google connections started before v0.8.1.
        add_action( 'admin_init', array( __CLASS__, 'handle_google_oauth_callback' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_instagram_oauth_callback' ) );
        // Legacy callback support for connections started with v0.7.1 or earlier.
        add_action( 'admin_init', array( __CLASS__, 'handle_meta_instagram_oauth_callback' ) );

        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_action( 'admin_footer-post.php', array( __CLASS__, 'title_required_script' ) );
        add_action( 'admin_footer-post-new.php', array( __CLASS__, 'title_required_script' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_promotion_expiry_redirect' ) );

        add_shortcode( 'persiano_updates', array( __CLASS__, 'updates_shortcode' ) );
        add_shortcode( 'persiano_offers', array( __CLASS__, 'offers_shortcode' ) );
        add_filter( 'post_type_link', array( __CLASS__, 'filter_public_content_permalink' ), 10, 2 );
        add_action( 'admin_init', array( __CLASS__, 'ensure_updates_page' ), 35 );
    }

    /**
     * Require a title only when a Persiano publishing record is actually
     * published or scheduled. Drafts may still be saved without a title.
     */
    public static function title_required_script() {
        global $post, $typenow;

        $post_type = $post instanceof WP_Post ? $post->post_type : (string) $typenow;
        if ( self::POST_TYPE !== $post_type ) {
            return;
        }
        ?>
        <script>
        (function () {
            'use strict';

            var form = document.getElementById('post');
            var title = document.getElementById('title');
            if (!form || !title) {
                return;
            }

            function isDispatchButton(el) {
                return !!(el && el.matches && el.matches('[data-ph-publish-action="all"], [data-ph-publish-action="schedule"]'));
            }

            function requireTitle(event) {
                var submitter = event.submitter || document.activeElement;
                if (!isDispatchButton(submitter)) {
                    return;
                }
                if (title.value.trim() !== '') {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                title.setAttribute('aria-invalid', 'true');
                title.focus();

                var existing = document.getElementById('ph-title-required-notice');
                if (!existing) {
                    existing = document.createElement('div');
                    existing.id = 'ph-title-required-notice';
                    existing.className = 'notice notice-error inline';
                    existing.innerHTML = '<p><strong>Title is required before publishing or scheduling.</strong></p>';
                    title.parentNode.insertBefore(existing, title.nextSibling);
                }
                window.scrollTo({ top: Math.max(0, title.getBoundingClientRect().top + window.pageYOffset - 120), behavior: 'smooth' });
            }

            form.addEventListener('submit', requireTitle, true);
            title.addEventListener('input', function () {
                if (title.value.trim() !== '') {
                    title.removeAttribute('aria-invalid');
                    var notice = document.getElementById('ph-title-required-notice');
                    if (notice) {
                        notice.remove();
                    }
                }
            });
        }());
        </script>
        <?php
    }

    public static function register_post_type() {
        $labels = array(
            'name'               => __( 'Publishing', 'persiano-hub' ),
            'singular_name'      => __( 'Campaign', 'persiano-hub' ),
            'add_new'            => __( 'Create Content', 'persiano-hub' ),
            'add_new_item'       => __( 'Create Persiano Content', 'persiano-hub' ),
            'edit_item'          => __( 'Edit Persiano Content', 'persiano-hub' ),
            'new_item'           => __( 'New Persiano Content', 'persiano-hub' ),
            'view_item'          => __( 'View Campaign', 'persiano-hub' ),
            'search_items'       => __( 'Search Content', 'persiano-hub' ),
            'not_found'          => __( 'No content found.', 'persiano-hub' ),
            'not_found_in_trash' => __( 'No content found in Trash.', 'persiano-hub' ),
        );

        register_post_type(
            self::POST_TYPE,
            array(
                'labels'              => $labels,
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => 'persiano-hub',
                'show_in_rest'        => false,
                'supports'            => array( 'title' ),
                'capability_type'      => 'post',
                'map_meta_cap'         => true,
                'menu_position'        => 3,
                'exclude_from_search'  => true,
                'publicly_queryable'   => false,
                'query_var'            => false,
                'rewrite'              => false,
                'show_in_nav_menus'    => false,
            )
        );
    }


    /**
     * Register the public content destinations owned by Batchly.
     *
     * Regular updates live under /updates/ and promotion landing pages live
     * under /offers/. Keeping them separate from the site's generic blog posts
     * gives Persiano stable destination URLs and lets promotions have their own
     * conversion-focused template without duplicating WooCommerce products.
     */
    public static function register_public_post_types() {
        register_post_type(
            self::UPDATE_POST_TYPE,
            array(
                'labels' => array(
                    'name'          => __( 'Updates', 'persiano-hub' ),
                    'singular_name' => __( 'Update', 'persiano-hub' ),
                    'add_new_item'  => __( 'Add Update', 'persiano-hub' ),
                    'edit_item'     => __( 'Edit Update', 'persiano-hub' ),
                ),
                'public'             => true,
                'show_ui'            => true,
                'show_in_menu'       => 'persiano-hub',
                'show_in_rest'       => true,
                'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
                'has_archive'        => false,
                'rewrite'            => array( 'slug' => 'updates', 'with_front' => false ),
                'publicly_queryable' => true,
                'exclude_from_search'=> false,
                'menu_position'      => 4,
            )
        );

        register_post_type(
            self::PROMOTION_POST_TYPE,
            array(
                'labels' => array(
                    'name'          => __( 'Offers', 'persiano-hub' ),
                    'singular_name' => __( 'Offer', 'persiano-hub' ),
                    'add_new_item'  => __( 'Add Offer', 'persiano-hub' ),
                    'edit_item'     => __( 'Edit Offer', 'persiano-hub' ),
                ),
                'public'             => true,
                'show_ui'            => true,
                'show_in_menu'       => 'persiano-hub',
                'show_in_rest'       => true,
                'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
                'has_archive'        => false,
                'rewrite'            => array( 'slug' => 'offers', 'with_front' => false ),
                'publicly_queryable' => true,
                'exclude_from_search'=> false,
                'menu_position'      => 5,
            )
        );
    }

    /**
     * Keep destination permalinks stable even when a site's generic post
     * permalink structure contains a /blog/ prefix.
     */
    public static function filter_public_content_permalink( $permalink, $post ) {
        if ( ! $post instanceof WP_Post ) {
            return $permalink;
        }
        if ( self::UPDATE_POST_TYPE === $post->post_type ) {
            return home_url( '/updates/' . $post->post_name . '/' );
        }
        if ( self::PROMOTION_POST_TYPE === $post->post_type ) {
            return home_url( '/offers/' . $post->post_name . '/' );
        }
        return $permalink;
    }

    /**
     * Keep Persiano Publishing on the classic editor screen.
     *
     * @param bool   $use_block_editor Whether Gutenberg would be used.
     * @param string $post_type        Current post type.
     * @return bool
     */
    public static function disable_block_editor_for_campaigns( $use_block_editor, $post_type ) {
        if ( self::POST_TYPE === $post_type ) {
            return false;
        }
        return $use_block_editor;
    }

    public static function register_admin_menu() {
        $hub_name = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::hub_name() : __( 'Batchly', 'persiano-hub' );
        add_menu_page(
            $hub_name,
            $hub_name,
            'manage_woocommerce',
            'persiano-hub',
            array( __CLASS__, 'render_dashboard_page' ),
            'dashicons-megaphone',
            56
        );

        add_submenu_page(
            'persiano-hub',
            $hub_name,
            __( 'Overview', 'persiano-hub' ),
            'manage_woocommerce',
            'persiano-hub',
            array( __CLASS__, 'render_dashboard_page' )
        );

        add_submenu_page(
            'persiano-hub',
            __( 'Website Content', 'persiano-hub' ),
            __( 'Website Content', 'persiano-hub' ),
            'manage_woocommerce',
            'persiano-hub-website-content',
            array( __CLASS__, 'render_website_content_page' )
        );

        add_submenu_page(
            'persiano-hub',
            __( 'Connections', 'persiano-hub' ),
            __( 'Connections', 'persiano-hub' ),
            'manage_woocommerce',
            'persiano-hub-connections',
            array( __CLASS__, 'render_connections_page' )
        );
    }

    public static function register_meta_boxes() {
        // The core submit box has been unreliable for this private operational
        // post type on some WordPress/plugin combinations. Replace it with a
        // dedicated Persiano action box so Save/Publish controls are always visible.
        remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );

        add_meta_box(
            'persiano_hub_campaign_settings',
            __( 'Persiano Publishing', 'persiano-hub' ),
            array( __CLASS__, 'render_campaign_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'persiano_hub_campaign_actions',
            __( 'Save & Publish', 'persiano-hub' ),
            array( __CLASS__, 'render_actions_meta_box' ),
            self::POST_TYPE,
            'side',
            'high'
        );

        add_meta_box(
            'persiano_hub_campaign_status',
            __( 'Publishing Status & Destinations', 'persiano-hub' ),
            array( __CLASS__, 'render_status_meta_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    public static function render_dashboard_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $counts = wp_count_posts( self::POST_TYPE );
        $recent = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'future' ),
                'posts_per_page' => 6,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );
        $connections = self::get_connections();
        $scheduled_query = new WP_Query(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => self::META_PREFIX . 'overall_status',
                'meta_value'     => 'scheduled',
                'no_found_rows'  => false,
            )
        );
        $scheduled_count = absint( $scheduled_query->found_posts );
        $upcoming_queue = self::get_upcoming_channel_schedules( 12 );
        ?>
        <div class="wrap ph-publishing-wrap">
            <div class="ph-publishing-hero">
                <div>
                    <span class="ph-admin-eyebrow"><?php esc_html_e( 'Batchly', 'persiano-hub' ); ?></span>
                    <h1><?php esc_html_e( 'Create once. Publish everywhere.', 'persiano-hub' ); ?></h1>
                    <p><?php esc_html_e( 'Prepare one Persiano content item, choose its destinations, and publish it to the website and connected channels from one place.', 'persiano-hub' ); ?></p>
                </div>
                <a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ); ?>"><?php esc_html_e( 'Create Content', 'persiano-hub' ); ?></a>
            </div>

            <?php self::render_admin_notice(); ?>

            <div class="ph-stat-grid">
                <div class="ph-stat-card"><strong><?php echo esc_html( isset( $counts->publish ) ? $counts->publish : 0 ); ?></strong><span><?php esc_html_e( 'Published campaigns', 'persiano-hub' ); ?></span></div>
                <div class="ph-stat-card"><strong><?php echo esc_html( isset( $counts->draft ) ? $counts->draft : 0 ); ?></strong><span><?php esc_html_e( 'Drafts', 'persiano-hub' ); ?></span></div>
                <div class="ph-stat-card"><strong><?php echo esc_html( $scheduled_count ); ?></strong><span><?php esc_html_e( 'Scheduled', 'persiano-hub' ); ?></span></div>
            </div>

            <div class="ph-admin-grid ph-admin-grid--2">
                <section class="ph-admin-panel">
                    <div class="ph-panel-head"><h2><?php esc_html_e( 'Channels', 'persiano-hub' ); ?></h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=persiano-hub-connections' ) ); ?>"><?php esc_html_e( 'Manage', 'persiano-hub' ); ?></a></div>
                    <?php self::render_connection_summary( $connections ); ?>
                </section>
                <section class="ph-admin-panel">
                    <div class="ph-panel-head"><h2><?php esc_html_e( 'Recent content', 'persiano-hub' ); ?></h2><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ); ?>"><?php esc_html_e( 'View all', 'persiano-hub' ); ?></a></div>
                    <?php if ( $recent ) : ?>
                        <div class="ph-recent-list">
                            <?php foreach ( $recent as $campaign ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $campaign->ID ) ); ?>">
                                    <strong><?php echo esc_html( get_the_title( $campaign ) ?: __( '(Untitled)', 'persiano-hub' ) ); ?></strong>
                                    <span><?php echo esc_html( self::format_campaign_state( $campaign->ID ) ); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p><?php esc_html_e( 'Your first campaign will appear here.', 'persiano-hub' ); ?></p>
                    <?php endif; ?>
                </section>
            </div>

            <section class="ph-admin-panel ph-publishing-queue">
                <div class="ph-panel-head"><h2><?php esc_html_e( 'Upcoming publishing queue', 'persiano-hub' ); ?></h2><span class="ph-muted"><?php esc_html_e( 'Per-channel schedule', 'persiano-hub' ); ?></span></div>
                <?php if ( $upcoming_queue ) : ?>
                    <div class="ph-responsive-table"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Campaign', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Channel', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Scheduled time', 'persiano-hub' ); ?></th></tr></thead><tbody>
                    <?php foreach ( $upcoming_queue as $queue_item ) : ?>
                        <tr><td><a href="<?php echo esc_url( get_edit_post_link( $queue_item['post_id'] ) ); ?>"><?php echo esc_html( $queue_item['title'] ); ?></a></td><td><?php echo esc_html( $queue_item['channel_label'] ); ?></td><td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $queue_item['timestamp'] ) ); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php else : ?>
                    <p><?php esc_html_e( 'Nothing is scheduled yet.', 'persiano-hub' ); ?></p>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public static function render_website_content_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $campaigns = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'future' ),
                'posts_per_page' => 200,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );
        $updates_page_id = self::ensure_updates_page_ready();
        $offers_page_id  = self::ensure_offers_page_ready();
        ?>
        <div class="wrap ph-publishing-wrap ph-website-content-page">
            <div class="ph-publishing-hero ph-publishing-hero--compact">
                <div>
                    <span class="ph-admin-eyebrow"><?php esc_html_e( 'Batchly', 'persiano-hub' ); ?></span>
                    <h1><?php esc_html_e( 'Website Content', 'persiano-hub' ); ?></h1>
                    <p><?php esc_html_e( 'See exactly what each campaign uses on the website. Product introductions and availability use WooCommerce products, promotions use dedicated landing pages, and editorial content uses Persiano Updates.', 'persiano-hub' ); ?></p>
                </div>
                <div class="ph-hero-actions">
                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ); ?>"><?php esc_html_e( 'Create Content', 'persiano-hub' ); ?></a>
                    <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>"><?php esc_html_e( 'Manage Products', 'persiano-hub' ); ?></a>
                    <?php if ( $updates_page_id ) : ?>
                        <a class="button" href="<?php echo esc_url( get_permalink( $updates_page_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Updates Page', 'persiano-hub' ); ?></a>
                    <?php endif; ?>
                    <?php if ( $offers_page_id ) : ?>
                        <a class="button" href="<?php echo esc_url( get_permalink( $offers_page_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Offers Page', 'persiano-hub' ); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ph-website-model ph-website-model--3">
                <div><strong><?php esc_html_e( 'Master product', 'persiano-hub' ); ?></strong><span><?php esc_html_e( 'Product introductions point to the existing WooCommerce product. One permanent product can be promoted many times without duplication.', 'persiano-hub' ); ?></span></div>
                <div><strong><?php esc_html_e( 'Availability', 'persiano-hub' ); ?></strong><span><?php esc_html_e( 'Activates the linked product in This Week, updates its date/deadline/stock, and can automatically remove it when the availability ends.', 'persiano-hub' ); ?></span></div>
                <div><strong><?php esc_html_e( 'Promotion / ad', 'persiano-hub' ); ?></strong><span><?php esc_html_e( 'Creates a dedicated /offers/ landing page for a campaign. Its CTA leads to the related product and checkout journey.', 'persiano-hub' ); ?></span></div>
            </div>

            <div class="ph-admin-panel ph-website-content-table-wrap">
                <div class="ph-panel-head">
                    <h2><?php esc_html_e( 'Campaign → website destination', 'persiano-hub' ); ?></h2>
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ); ?>"><?php esc_html_e( 'Manage all campaigns', 'persiano-hub' ); ?></a>
                </div>
                <?php if ( $campaigns ) : ?>
                    <div class="ph-responsive-table">
                        <table class="widefat striped ph-website-content-table">
                            <thead><tr>
                                <th><?php esc_html_e( 'Campaign', 'persiano-hub' ); ?></th>
                                <th><?php esc_html_e( 'Type', 'persiano-hub' ); ?></th>
                                <th><?php esc_html_e( 'Website destination', 'persiano-hub' ); ?></th>
                                <th><?php esc_html_e( 'Selected?', 'persiano-hub' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'persiano-hub' ); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ( $campaigns as $campaign ) : ?>
                                <?php
                                $type        = get_post_meta( $campaign->ID, self::META_PREFIX . 'type', true ) ?: 'update';
                                $channels    = self::get_array_meta( $campaign->ID, self::META_PREFIX . 'channels' );
                                $destination = self::get_website_destination( $campaign->ID );
                                $selected    = in_array( 'website', $channels, true );
                                ?>
                                <tr>
                                    <td><strong><a href="<?php echo esc_url( get_edit_post_link( $campaign->ID ) ); ?>"><?php echo esc_html( get_the_title( $campaign ) ?: __( '(Untitled)', 'persiano-hub' ) ); ?></a></strong><br><span class="ph-muted"><?php echo esc_html( self::format_campaign_state( $campaign->ID ) ); ?></span></td>
                                    <td><?php echo esc_html( isset( self::content_types()[ $type ] ) ? self::content_types()[ $type ] : ucfirst( $type ) ); ?></td>
                                    <td>
                                        <?php if ( $destination['exists'] ) : ?>
                                            <strong><?php echo esc_html( $destination['label'] ); ?></strong><br>
                                            <span class="ph-mini-status"><?php echo esc_html( $destination['status_label'] ); ?></span>
                                        <?php else : ?>
                                            <span class="ph-muted"><?php echo esc_html( $destination['message'] ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="ph-selection-pill <?php echo $selected ? 'is-on' : 'is-off'; ?>"><?php echo esc_html( $selected ? __( 'Website selected', 'persiano-hub' ) : __( 'Not selected', 'persiano-hub' ) ); ?></span></td>
                                    <td>
                                        <a href="<?php echo esc_url( get_edit_post_link( $campaign->ID ) ); ?>"><?php esc_html_e( 'Edit campaign', 'persiano-hub' ); ?></a>
                                        <?php if ( $destination['exists'] && $destination['edit_url'] ) : ?> · <a href="<?php echo esc_url( $destination['edit_url'] ); ?>"><?php esc_html_e( 'Edit destination', 'persiano-hub' ); ?></a><?php endif; ?>
                                        <?php if ( $destination['exists'] && $destination['view_url'] ) : ?> · <a href="<?php echo esc_url( $destination['view_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'persiano-hub' ); ?></a><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <p><?php esc_html_e( 'No publishing campaigns exist yet.', 'persiano-hub' ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_connection_summary( $connections ) {
        $items = array(
            'website'   => array( __( 'Website', 'persiano-hub' ), true ),
            'telegram'  => array( __( 'Telegram', 'persiano-hub' ), ! empty( $connections['telegram_bot_token'] ) && ! empty( $connections['telegram_chat_id'] ) ),
            'instagram' => array( __( 'Instagram', 'persiano-hub' ), ! empty( $connections['instagram_user_id'] ) && ! empty( $connections['instagram_access_token'] ) ),
            'google'    => array( __( 'Google Business', 'persiano-hub' ), ! empty( $connections['google_account_id'] ) && ! empty( $connections['google_location_id'] ) && ( ! empty( $connections['google_refresh_token'] ) || ! empty( $connections['google_access_token'] ) ) ),
            'email'     => array( __( 'Email List', 'persiano-hub' ), class_exists( 'Persiano_Hub_Marketing' ) && Persiano_Hub_Marketing::active_subscriber_count() > 0 ),
        );
        echo '<div class="ph-channel-list">';
        foreach ( $items as $key => $item ) {
            printf(
                '<div><span class="dashicons %1$s"></span><strong>%2$s</strong><em class="%3$s">%4$s</em></div>',
                esc_attr( self::channel_icon( $key ) ),
                esc_html( $item[0] ),
                $item[1] ? 'is-connected' : 'is-disconnected',
                esc_html( $item[1] ? __( 'Ready', 'persiano-hub' ) : __( 'Not connected', 'persiano-hub' ) )
            );
        }
        echo '</div>';
    }

    public static function render_campaign_meta_box( $post ) {
        wp_nonce_field( 'persiano_hub_save_campaign', 'persiano_hub_campaign_nonce' );

        $type          = get_post_meta( $post->ID, self::META_PREFIX . 'type', true ) ?: 'update';
        $channels      = self::get_array_meta( $post->ID, self::META_PREFIX . 'channels' );
        $product_id    = absint( get_post_meta( $post->ID, self::META_PREFIX . 'product_id', true ) );
        $cta_url       = get_post_meta( $post->ID, self::META_PREFIX . 'cta_url', true );
        $instagram     = get_post_meta( $post->ID, self::META_PREFIX . 'instagram_caption', true );
        $telegram      = get_post_meta( $post->ID, self::META_PREFIX . 'telegram_caption', true );
        $google        = get_post_meta( $post->ID, self::META_PREFIX . 'google_caption', true );
        $email_subject = get_post_meta( $post->ID, self::META_PREFIX . 'email_subject', true );
        $email_preview = get_post_meta( $post->ID, self::META_PREFIX . 'email_preview', true );
        $email_body    = get_post_meta( $post->ID, self::META_PREFIX . 'email_body', true );
        $email_tags    = get_post_meta( $post->ID, self::META_PREFIX . 'email_tags', true );
        $email_template = get_post_meta( $post->ID, self::META_PREFIX . 'email_template', true ) ?: 'auto';
        $email_image_id = absint( get_post_meta( $post->ID, self::META_PREFIX . 'email_image_id', true ) );
        $email_image_mode = sanitize_key( (string) get_post_meta( $post->ID, self::META_PREFIX . 'email_image_mode', true ) ) ?: 'fit';
        $email_image_position = sanitize_key( (string) get_post_meta( $post->ID, self::META_PREFIX . 'email_image_position', true ) ) ?: 'center';
        $video_id      = absint( get_post_meta( $post->ID, self::META_PREFIX . 'video_id', true ) );
        $instagram_format = sanitize_key( (string) get_post_meta( $post->ID, self::META_PREFIX . 'instagram_format', true ) ) ?: 'auto';
        $instagram_carousel_ids = (string) get_post_meta( $post->ID, self::META_PREFIX . 'instagram_carousel_ids', true );
        $google_img_id = absint( get_post_meta( $post->ID, self::META_PREFIX . 'google_image_id', true ) );
        $event_start   = get_post_meta( $post->ID, self::META_PREFIX . 'event_start', true );
        $event_end     = get_post_meta( $post->ID, self::META_PREFIX . 'event_end', true );
        $featured_id       = get_post_thumbnail_id( $post->ID );
        $shared_content    = (string) $post->post_content;
        $connections       = self::get_connections();
        $schedules         = self::get_array_meta( $post->ID, self::META_PREFIX . 'schedules' );
        $schedule_times     = self::get_array_meta( $post->ID, self::META_PREFIX . 'schedule_times' );
        $availability_date = get_post_meta( $post->ID, self::META_PREFIX . 'availability_date', true );
        $order_deadline    = get_post_meta( $post->ID, self::META_PREFIX . 'availability_deadline', true );
        $availability_end  = get_post_meta( $post->ID, self::META_PREFIX . 'availability_end', true );
        $availability_stock = get_post_meta( $post->ID, self::META_PREFIX . 'availability_stock', true );
        $availability_close = get_post_meta( $post->ID, self::META_PREFIX . 'availability_close', true );
        $availability_remove = get_post_meta( $post->ID, self::META_PREFIX . 'availability_remove', true );
        $promotion_kicker  = get_post_meta( $post->ID, self::META_PREFIX . 'promotion_kicker', true );
        $promotion_offer   = get_post_meta( $post->ID, self::META_PREFIX . 'promotion_offer', true );
        $promotion_urgency = get_post_meta( $post->ID, self::META_PREFIX . 'promotion_urgency', true );
        $promotion_expiry  = get_post_meta( $post->ID, self::META_PREFIX . 'promotion_expiry', true );
        $promotion_expiry_action = get_post_meta( $post->ID, self::META_PREFIX . 'promotion_expiry_action', true ) ?: 'message';

        // Default a brand-new campaign to Website, but preserve an intentionally
        // empty selection after the user has saved the campaign once.
        if ( ! metadata_exists( 'post', $post->ID, self::META_PREFIX . 'channels' ) ) {
            $channels = array( 'website' );
        }

        $products = class_exists( 'WooCommerce' ) ? wc_get_products( array( 'limit' => 100, 'orderby' => 'name', 'order' => 'ASC', 'status' => array( 'publish', 'draft', 'private' ) ) ) : array();
        ?>
        <div class="ph-campaign-editor">
            <?php if ( ! empty( $_GET['ph_email_notice'] ) ) : ?><div class="notice notice-info inline"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ph_email_notice'] ) ) ); ?></p></div><?php endif; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="ph-campaign-row ph-campaign-row--2">
                <label>
                    <span><?php esc_html_e( 'Content type', 'persiano-hub' ); ?></span>
                    <select name="persiano_pub_type" id="persiano_pub_type">
                        <?php foreach ( self::content_types() as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e( 'Related WooCommerce product', 'persiano-hub' ); ?></span>
                    <select name="persiano_pub_product_id" id="persiano_pub_product_id">
                        <option value="0"><?php esc_html_e( 'None / create a new product draft when applicable', 'persiano-hub' ); ?></option>
                        <?php foreach ( $products as $product ) : ?>
                            <option value="<?php echo esc_attr( $product->get_id() ); ?>" <?php selected( $product_id, $product->get_id() ); ?>><?php echo esc_html( $product->get_name() ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small id="ph-product-link-help" class="ph-product-link-help"></small>
                </label>
            </div>

            <div class="ph-campaign-block">
                <h3><?php esc_html_e( 'Publish to', 'persiano-hub' ); ?></h3>
                <div class="ph-channel-checks">
                    <?php foreach ( self::channels() as $channel => $label ) : ?>
                        <?php $ready = self::channel_is_ready( $channel, $connections ); ?>
                        <label class="<?php echo $ready ? 'is-ready' : 'is-not-ready'; ?>">
                            <input type="checkbox" name="persiano_pub_channels[]" value="<?php echo esc_attr( $channel ); ?>" <?php checked( in_array( $channel, $channels, true ) ); ?>>
                            <span class="dashicons <?php echo esc_attr( self::channel_icon( $channel ) ); ?>"></span>
                            <span class="ph-channel-name"><?php echo esc_html( $label ); ?></span>
                            <em><?php echo esc_html( $ready ? __( 'Ready', 'persiano-hub' ) : ( 'email' === $channel ? __( 'No subscribers', 'persiano-hub' ) : __( 'Not connected', 'persiano-hub' ) ) ); ?></em>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="description"><?php esc_html_e( 'Only checked destinations are published. Product introductions and availability campaigns use the linked WooCommerce product; promotions can create a dedicated /offers/ landing page.', 'persiano-hub' ); ?></p>
                <div id="ph-website-behaviour-note" class="ph-website-behaviour-note"></div>
            </div>

            <div class="ph-campaign-block ph-schedule-block">
                <div class="ph-field-head">
                    <div>
                        <h3><?php esc_html_e( 'Channel schedule', 'persiano-hub' ); ?></h3>
                        <p class="description"><?php echo esc_html( sprintf( __( 'Set a separate publishing time for each channel. Times use the WordPress timezone: %s.', 'persiano-hub' ), wp_timezone_string() ?: 'UTC' ) ); ?></p>
                    </div>
                </div>
                <div class="ph-channel-schedule-grid">
                    <?php foreach ( self::channels() as $channel => $label ) : ?>
                        <?php
                        $schedule_entry = isset( $schedules[ $channel ] ) && is_array( $schedules[ $channel ] ) ? $schedules[ $channel ] : array();
                        $schedule_value = isset( $schedule_times[ $channel ] ) ? sanitize_text_field( $schedule_times[ $channel ] ) : ( ! empty( $schedule_entry['timestamp'] ) ? self::timestamp_to_local_input( absint( $schedule_entry['timestamp'] ) ) : '' );
                        $schedule_state = ! empty( $schedule_entry['state'] ) ? sanitize_key( $schedule_entry['state'] ) : '';
                        ?>
                        <label class="ph-channel-schedule-row">
                            <span><span class="dashicons <?php echo esc_attr( self::channel_icon( $channel ) ); ?>"></span><?php echo esc_html( $label ); ?></span>
                            <input type="datetime-local" name="persiano_pub_schedule[<?php echo esc_attr( $channel ); ?>]" value="<?php echo esc_attr( $schedule_value ); ?>">
                            <small><?php echo esc_html( $schedule_state ? ucfirst( $schedule_state ) : __( 'Not scheduled', 'persiano-hub' ) ); ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="description"><?php esc_html_e( 'Use “Schedule Selected Channels” to apply the dates above. Publish Now ignores these times and sends immediately.', 'persiano-hub' ); ?></p>
            </div>

            <div class="ph-campaign-block ph-type-fields ph-availability-fields <?php echo 'availability' === $type ? 'is-visible' : ''; ?>">
                <h3><?php esc_html_e( 'Product availability', 'persiano-hub' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Publishing Website for this campaign activates the linked product in This Week. Social channels promote that same availability and send customers to the product ordering page.', 'persiano-hub' ); ?></p>
                <div class="ph-campaign-row ph-campaign-row--3">
                    <label><span><?php esc_html_e( 'Available date', 'persiano-hub' ); ?></span><input type="date" name="persiano_pub_availability_date" value="<?php echo esc_attr( $availability_date ); ?>"></label>
                    <label><span><?php esc_html_e( 'Order deadline', 'persiano-hub' ); ?></span><input type="datetime-local" name="persiano_pub_availability_deadline" value="<?php echo esc_attr( $order_deadline ); ?>"></label>
                    <label><span><?php esc_html_e( 'Remove from This Week after', 'persiano-hub' ); ?></span><input type="datetime-local" name="persiano_pub_availability_end" value="<?php echo esc_attr( $availability_end ); ?>"></label>
                </div>
                <div class="ph-campaign-row ph-campaign-row--3">
                    <label><span><?php esc_html_e( 'Available quantity (optional)', 'persiano-hub' ); ?></span><input type="number" min="0" step="1" name="persiano_pub_availability_stock" value="<?php echo esc_attr( $availability_stock ); ?>" placeholder="e.g. 12"><small><?php esc_html_e( 'Leave blank to keep the product’s current stock settings.', 'persiano-hub' ); ?></small></label>
                    <label class="ph-checkbox-field"><span><?php esc_html_e( 'Ordering', 'persiano-hub' ); ?></span><span><input type="checkbox" name="persiano_pub_availability_close" value="1" <?php checked( '' === $availability_close || 'yes' === $availability_close ); ?>> <?php esc_html_e( 'Close ordering at deadline', 'persiano-hub' ); ?></span></label>
                    <label class="ph-checkbox-field"><span><?php esc_html_e( 'Menu cleanup', 'persiano-hub' ); ?></span><span><input type="checkbox" name="persiano_pub_availability_remove" value="1" <?php checked( '' === $availability_remove || 'yes' === $availability_remove ); ?>> <?php esc_html_e( 'Automatically remove from This Week', 'persiano-hub' ); ?></span></label>
                </div>
            </div>

            <div class="ph-campaign-block ph-type-fields ph-promotion-fields <?php echo 'promotion' === $type ? 'is-visible' : ''; ?>">
                <h3><?php esc_html_e( 'Promotion landing page', 'persiano-hub' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Website creates a dedicated /offers/ landing page. Social posts can link to that page, and its Order button leads to the linked WooCommerce product.', 'persiano-hub' ); ?></p>
                <div class="ph-campaign-row ph-campaign-row--3">
                    <label><span><?php esc_html_e( 'Kicker', 'persiano-hub' ); ?></span><input type="text" name="persiano_pub_promotion_kicker" value="<?php echo esc_attr( $promotion_kicker ); ?>" placeholder="This Thursday only"></label>
                    <label><span><?php esc_html_e( 'Offer line', 'persiano-hub' ); ?></span><input type="text" name="persiano_pub_promotion_offer" value="<?php echo esc_attr( $promotion_offer ); ?>" placeholder="Limited small-batch availability"></label>
                    <label><span><?php esc_html_e( 'Urgency / deadline line', 'persiano-hub' ); ?></span><input type="text" name="persiano_pub_promotion_urgency" value="<?php echo esc_attr( $promotion_urgency ); ?>" placeholder="Order before Wednesday evening"></label>
                </div>
                <div class="ph-campaign-row ph-campaign-row--2">
                    <label><span><?php esc_html_e( 'Promotion expires', 'persiano-hub' ); ?></span><input type="datetime-local" name="persiano_pub_promotion_expiry" value="<?php echo esc_attr( $promotion_expiry ); ?>"></label>
                    <label><span><?php esc_html_e( 'After expiry', 'persiano-hub' ); ?></span><select name="persiano_pub_promotion_expiry_action"><option value="message" <?php selected( $promotion_expiry_action, 'message' ); ?>><?php esc_html_e( 'Show “offer ended” and current ordering links', 'persiano-hub' ); ?></option><option value="redirect_product" <?php selected( $promotion_expiry_action, 'redirect_product' ); ?>><?php esc_html_e( 'Redirect to the related product', 'persiano-hub' ); ?></option><option value="keep" <?php selected( $promotion_expiry_action, 'keep' ); ?>><?php esc_html_e( 'Keep the landing page unchanged', 'persiano-hub' ); ?></option></select></label>
                </div>
            </div>

            <div class="ph-campaign-block ph-shared-content-block">
                <div class="ph-field-head">
                    <div>
                        <h3><?php esc_html_e( 'Shared content / caption', 'persiano-hub' ); ?></h3>
                        <p class="description"><?php esc_html_e( 'Write the main text once. Instagram, Telegram, Google and Email can override it below when needed.', 'persiano-hub' ); ?></p>
                    </div>
                    <button type="button" class="button ph-insert-content-media"><?php esc_html_e( 'Add Media', 'persiano-hub' ); ?></button>
                </div>
                <textarea id="ph_shared_content" name="content" rows="12" placeholder="<?php esc_attr_e( 'Write the main Persiano content here…', 'persiano-hub' ); ?>"><?php echo esc_textarea( $shared_content ); ?></textarea>
            </div>

            <div class="ph-campaign-row ph-campaign-row--2">
                <div class="ph-media-field">
                    <span><?php esc_html_e( 'Main image', 'persiano-hub' ); ?></span>
                    <input type="hidden" class="ph-media-id" name="persiano_pub_featured_image_id" value="<?php echo esc_attr( $featured_id ); ?>">
                    <div class="ph-media-preview">
                        <?php if ( $featured_id ) : ?>
                            <?php echo wp_get_attachment_image( $featured_id, array( 180, 135 ) ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'No main image selected', 'persiano-hub' ); ?>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="button ph-select-media" data-media-type="image"><?php esc_html_e( 'Choose Main Image', 'persiano-hub' ); ?></button>
                    <button type="button" class="button-link-delete ph-clear-media"><?php esc_html_e( 'Clear', 'persiano-hub' ); ?></button>
                </div>
                <label>
                    <span><?php esc_html_e( 'Call-to-action URL (optional)', 'persiano-hub' ); ?></span>
                    <input type="url" name="persiano_pub_cta_url" value="<?php echo esc_attr( $cta_url ); ?>" placeholder="https://persianodish.com/this-week/">
                    <small><?php esc_html_e( 'Leave blank to use the related product or the most relevant Persiano page automatically.', 'persiano-hub' ); ?></small>
                </label>
            </div>

            <?php
            $tracking_destination = self::resolve_cta_url( $post->ID );
            if ( $tracking_destination && class_exists( 'Persiano_Hub_Marketing' ) ) :
                $tracking_links = array(
                    'instagram'       => array( __( 'Instagram / Meta ad', 'persiano-hub' ), Persiano_Hub_Marketing::tracked_url( $tracking_destination, $post->ID, 'instagram', 'social' ) ),
                    'telegram'        => array( __( 'Telegram', 'persiano-hub' ), Persiano_Hub_Marketing::tracked_url( $tracking_destination, $post->ID, 'telegram', 'social' ) ),
                    'google_business' => array( __( 'Google Business', 'persiano-hub' ), Persiano_Hub_Marketing::tracked_url( $tracking_destination, $post->ID, 'google_business', 'organic' ) ),
                    'email'           => array( __( 'Email', 'persiano-hub' ), Persiano_Hub_Marketing::tracked_url( $tracking_destination, $post->ID, 'email', 'email' ) ),
                );
            ?>
            <details class="ph-tracking-links">
                <summary><?php esc_html_e( 'Tracked campaign links', 'persiano-hub' ); ?></summary>
                <p class="description"><?php esc_html_e( 'Telegram, Google and Email use tracked links automatically. Copy the Instagram link when setting up an ad, bio link or Story link so Persiano can attribute visits and orders to this campaign.', 'persiano-hub' ); ?></p>
                <div class="ph-tracking-link-grid">
                    <?php foreach ( $tracking_links as $tracking_link ) : ?>
                        <label><span><?php echo esc_html( $tracking_link[0] ); ?></span><input type="text" readonly value="<?php echo esc_attr( $tracking_link[1] ); ?>" onclick="this.select();"></label>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>

            <?php
            $suggested_media_ids = array();
            if ( $product_id ) {
                $suggested_media_ids[] = get_post_thumbnail_id( $product_id );
                $product_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
                if ( $product_gallery ) { $suggested_media_ids = array_merge( $suggested_media_ids, array_map( 'absint', explode( ',', $product_gallery ) ) ); }
                $linked_recipe_id = absint( get_post_meta( $product_id, '_persiano_recipe_id', true ) );
                if ( ! $linked_recipe_id ) { $linked_recipe_id = absint( get_post_meta( $product_id, '_persiano_recipe_link', true ) ); }
                if ( $linked_recipe_id ) {
                    foreach ( array( '_persiano_recipe_media_ids', '_persiano_media_ids', '_persiano_recipe_gallery' ) as $media_key ) {
                        $recipe_media = get_post_meta( $linked_recipe_id, $media_key, true );
                        if ( is_array( $recipe_media ) ) { $suggested_media_ids = array_merge( $suggested_media_ids, array_map( 'absint', $recipe_media ) ); }
                        elseif ( is_string( $recipe_media ) && $recipe_media ) { $suggested_media_ids = array_merge( $suggested_media_ids, array_map( 'absint', preg_split( '/\s*,\s*/', $recipe_media ) ) ); }
                    }
                }
            }
            $suggested_media_ids = array_values( array_unique( array_filter( array_map( 'absint', $suggested_media_ids ) ) ) );
            ?>
            <div class="ph-campaign-block ph-instagram-format-block">
                <h3><?php esc_html_e( 'Instagram format', 'persiano-hub' ); ?></h3>
                <input type="hidden" name="persiano_pub_instagram_format" id="persiano_pub_instagram_format" value="<?php echo esc_attr( $instagram_format ); ?>">
                <div class="ph-instagram-format-cards">
                    <?php foreach ( array( 'post'=>array('Feed Post','One image, 1:1 or 4:5'), 'reel'=>array('Reel','One video with optional cover'), 'carousel'=>array('Carousel','2–10 images or videos'), 'story'=>array('Story','One vertical image or video') ) as $format_key => $format_data ) : ?>
                        <button type="button" class="ph-instagram-format-card <?php echo $instagram_format === $format_key ? 'is-selected' : ''; ?>" data-format="<?php echo esc_attr( $format_key ); ?>"><strong><?php echo esc_html( $format_data[0] ); ?></strong><small><?php echo esc_html( $format_data[1] ); ?></small></button>
                    <?php endforeach; ?>
                </div>
                <?php if ( $suggested_media_ids ) : ?>
                <div class="ph-suggested-media"><strong><?php esc_html_e( 'Suggested media from linked product or recipe', 'persiano-hub' ); ?></strong><p class="description"><?php esc_html_e( 'Select what you need, then remove, reorder or add more. Recipe step media is not included.', 'persiano-hub' ); ?></p><div class="ph-suggested-media-grid">
                    <?php foreach ( $suggested_media_ids as $suggested_id ) : $thumb = wp_get_attachment_image_url( $suggested_id, 'thumbnail' ); if ( ! $thumb ) continue; ?><button type="button" class="ph-add-suggested-media" data-id="<?php echo esc_attr( $suggested_id ); ?>"><img src="<?php echo esc_url( $thumb ); ?>" alt=""></button><?php endforeach; ?>
                </div></div>
                <?php endif; ?>
                <div class="ph-carousel-media-field">
                    <input type="hidden" id="persiano_pub_instagram_carousel_ids" name="persiano_pub_instagram_carousel_ids" value="<?php echo esc_attr( $instagram_carousel_ids ); ?>">
                    <div class="ph-carousel-preview"></div>
                    <button type="button" class="button ph-select-carousel-media"><?php esc_html_e( 'Choose carousel media', 'persiano-hub' ); ?></button>
                    <small class="ph-carousel-count"></small>
                </div>
                <div class="ph-campaign-row ph-campaign-row--2 ph-instagram-framing">
                    <label><span><?php esc_html_e( 'Canvas', 'persiano-hub' ); ?></span><select name="persiano_pub_instagram_ratio"><option value="4:5"><?php esc_html_e( 'Portrait 4:5', 'persiano-hub' ); ?></option><option value="1:1"><?php esc_html_e( 'Square 1:1', 'persiano-hub' ); ?></option><option value="9:16"><?php esc_html_e( 'Vertical 9:16', 'persiano-hub' ); ?></option><option value="original"><?php esc_html_e( 'Original', 'persiano-hub' ); ?></option></select></label>
                    <label><span><?php esc_html_e( 'Framing', 'persiano-hub' ); ?></span><select name="persiano_pub_instagram_fit"><option value="cover"><?php esc_html_e( 'Fill / crop', 'persiano-hub' ); ?></option><option value="contain"><?php esc_html_e( 'Fit whole media', 'persiano-hub' ); ?></option><option value="original"><?php esc_html_e( 'Original', 'persiano-hub' ); ?></option></select></label>
                </div>
            </div>

            <div class="ph-campaign-row ph-campaign-row--2">
                <div class="ph-media-field ph-media-field--wide">
                    <span><?php esc_html_e( 'Video (Instagram Reel / Story / Telegram post)', 'persiano-hub' ); ?></span>
                    <input type="hidden" class="ph-media-id" name="persiano_pub_video_id" value="<?php echo esc_attr( $video_id ); ?>">
                    <div class="ph-media-preview"><?php echo $video_id ? esc_html( basename( get_attached_file( $video_id ) ) ) : esc_html__( 'No video selected', 'persiano-hub' ); ?></div>
                    <button type="button" class="button ph-select-media" data-media-type="video"><?php esc_html_e( 'Choose Video', 'persiano-hub' ); ?></button>
                    <button type="button" class="button-link-delete ph-clear-media"><?php esc_html_e( 'Clear', 'persiano-hub' ); ?></button>
                    <small><?php esc_html_e( 'When a video is selected, Instagram publishes it as a Reel and Telegram sends the video instead of the Main image. Each selected channel receives one post per dispatch.', 'persiano-hub' ); ?></small>
                </div>
            </div>

            <details class="ph-platform-overrides" <?php echo ( $instagram || $telegram || $google ) ? 'open' : ''; ?>>
                <summary><?php esc_html_e( 'Platform-specific text (optional)', 'persiano-hub' ); ?></summary>
                <p class="description"><?php esc_html_e( 'Leave these blank to reuse the Shared content / caption exactly as written.', 'persiano-hub' ); ?></p>
                <div class="ph-platform-captions">
                    <label>
                        <span><?php esc_html_e( 'Instagram caption', 'persiano-hub' ); ?></span>
                        <textarea name="persiano_pub_instagram_caption" rows="5" placeholder="<?php esc_attr_e( 'Leave blank to use the shared content.', 'persiano-hub' ); ?>"><?php echo esc_textarea( $instagram ); ?></textarea>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Telegram caption', 'persiano-hub' ); ?></span>
                        <textarea name="persiano_pub_telegram_caption" rows="5" placeholder="<?php esc_attr_e( 'Leave blank to use the shared content.', 'persiano-hub' ); ?>"><?php echo esc_textarea( $telegram ); ?></textarea>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Google Business text', 'persiano-hub' ); ?></span>
                        <textarea name="persiano_pub_google_caption" rows="5" placeholder="<?php esc_attr_e( 'Leave blank to use the shared content.', 'persiano-hub' ); ?>"><?php echo esc_textarea( $google ); ?></textarea>
                    </label>
                </div>
            </details>

            <div class="ph-campaign-block ph-email-campaign-block">
                <div class="ph-field-head">
                    <div>
                        <h3><?php esc_html_e( 'Email campaign', 'persiano-hub' ); ?></h3>
                        <p class="description"><?php esc_html_e( 'Used only when the Email channel is selected. Leave the body blank to reuse the shared campaign content inside the Persiano branded email template.', 'persiano-hub' ); ?></p>
                    </div>
                </div>
                <div class="ph-campaign-row ph-campaign-row--2">
                    <label><span><?php esc_html_e( 'Email subject', 'persiano-hub' ); ?></span><input type="text" name="persiano_pub_email_subject" value="<?php echo esc_attr( $email_subject ); ?>" placeholder="<?php esc_attr_e( 'Defaults to the campaign title', 'persiano-hub' ); ?>"></label>
                    <label><span><?php esc_html_e( 'Preview text', 'persiano-hub' ); ?></span><input type="text" name="persiano_pub_email_preview" value="<?php echo esc_attr( $email_preview ); ?>" placeholder="<?php esc_attr_e( 'Short inbox preview text', 'persiano-hub' ); ?>"></label>
                </div>
                <div class="ph-campaign-row ph-campaign-row--2">
                    <label><span><?php esc_html_e( 'Template', 'persiano-hub' ); ?></span><select name="persiano_pub_email_template"><option value="auto" <?php selected( $email_template, 'auto' ); ?>><?php esc_html_e( 'Automatic from campaign type', 'persiano-hub' ); ?></option><option value="weekly_menu" <?php selected( $email_template, 'weekly_menu' ); ?>><?php esc_html_e( 'Weekly Menu', 'persiano-hub' ); ?></option><option value="promotion" <?php selected( $email_template, 'promotion' ); ?>><?php esc_html_e( 'Promotion / Offer', 'persiano-hub' ); ?></option><option value="product" <?php selected( $email_template, 'product' ); ?>><?php esc_html_e( 'Product / Availability', 'persiano-hub' ); ?></option><option value="event" <?php selected( $email_template, 'event' ); ?>><?php esc_html_e( 'Event', 'persiano-hub' ); ?></option><option value="update" <?php selected( $email_template, 'update' ); ?>><?php esc_html_e( 'Update', 'persiano-hub' ); ?></option></select></label>
                    <label><span><?php esc_html_e( 'Audience tags (optional)', 'persiano-hub' ); ?></span><input type="text" name="persiano_pub_email_tags" value="<?php echo esc_attr( $email_tags ); ?>" placeholder="weekly-menu, pantry"><small><?php esc_html_e( 'Comma-separated. Leave blank to send to all active subscribers.', 'persiano-hub' ); ?></small></label>
                </div>
                <label><span><?php esc_html_e( 'Email body override (optional)', 'persiano-hub' ); ?></span><textarea name="persiano_pub_email_body" rows="8" placeholder="<?php esc_attr_e( 'Leave blank to use Shared content / caption.', 'persiano-hub' ); ?>"><?php echo esc_textarea( $email_body ); ?></textarea></label>
                <div class="ph-campaign-row ph-campaign-row--2">
                    <div class="ph-media-field">
                        <span><?php esc_html_e( 'Dedicated email image (optional)', 'persiano-hub' ); ?></span>
                        <input type="hidden" class="ph-media-id" name="persiano_pub_email_image_id" value="<?php echo esc_attr( $email_image_id ); ?>">
                        <div class="ph-media-preview"><?php echo $email_image_id ? wp_get_attachment_image( $email_image_id, array( 160, 120 ) ) : esc_html__( 'Uses the Main image when left blank.', 'persiano-hub' ); ?></div>
                        <button type="button" class="button ph-select-media" data-media-type="image"><?php esc_html_e( 'Choose Email Image', 'persiano-hub' ); ?></button>
                        <button type="button" class="button-link-delete ph-clear-media"><?php esc_html_e( 'Clear', 'persiano-hub' ); ?></button>
                    </div>
                    <div>
                        <label><span><?php esc_html_e( 'Email image display', 'persiano-hub' ); ?></span><select name="persiano_pub_email_image_mode"><option value="fit" <?php selected( $email_image_mode, 'fit' ); ?>><?php esc_html_e( 'Fit whole image — recommended', 'persiano-hub' ); ?></option><option value="original" <?php selected( $email_image_mode, 'original' ); ?>><?php esc_html_e( 'Original size, responsive', 'persiano-hub' ); ?></option><option value="cover" <?php selected( $email_image_mode, 'cover' ); ?>><?php esc_html_e( 'Banner crop — best effort', 'persiano-hub' ); ?></option></select><small><?php esc_html_e( 'Fit keeps posters and food photos fully visible in Outlook and other email clients.', 'persiano-hub' ); ?></small></label>
                        <label><span><?php esc_html_e( 'Crop focus', 'persiano-hub' ); ?></span><select name="persiano_pub_email_image_position"><option value="top" <?php selected( $email_image_position, 'top' ); ?>><?php esc_html_e( 'Top', 'persiano-hub' ); ?></option><option value="center" <?php selected( $email_image_position, 'center' ); ?>><?php esc_html_e( 'Center', 'persiano-hub' ); ?></option><option value="bottom" <?php selected( $email_image_position, 'bottom' ); ?>><?php esc_html_e( 'Bottom', 'persiano-hub' ); ?></option></select></label>
                    </div>
                </div>
                <?php if ( $post->ID ) : ?>
                    <div class="ph-email-tools">
                        <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_preview_email&campaign_id=' . $post->ID ), 'persiano_hub_preview_email_' . $post->ID ) ); ?>"><?php esc_html_e( 'Preview Saved Email', 'persiano-hub' ); ?></a>
                        <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_send_test_email&campaign_id=' . $post->ID ), 'persiano_hub_send_test_email_' . $post->ID ) ); ?>"><?php esc_html_e( 'Send Test to My Email', 'persiano-hub' ); ?></a>
                        <small><?php esc_html_e( 'Save changes first so preview and test use the latest content.', 'persiano-hub' ); ?></small>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ph-campaign-row ph-campaign-row--2">
                <div class="ph-media-field">
                    <span><?php esc_html_e( 'Google Business image (optional)', 'persiano-hub' ); ?></span>
                    <input type="hidden" class="ph-media-id" name="persiano_pub_google_image_id" value="<?php echo esc_attr( $google_img_id ); ?>">
                    <div class="ph-media-preview">
                        <?php if ( $google_img_id ) : ?>
                            <?php echo wp_get_attachment_image( $google_img_id, array( 120, 90 ) ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Uses the featured image when left blank.', 'persiano-hub' ); ?>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="button ph-select-media" data-media-type="image"><?php esc_html_e( 'Choose Image', 'persiano-hub' ); ?></button>
                    <button type="button" class="button-link-delete ph-clear-media"><?php esc_html_e( 'Clear', 'persiano-hub' ); ?></button>
                </div>
                <div class="ph-event-fields <?php echo 'event' === $type ? 'is-visible' : ''; ?>">
                    <label><span><?php esc_html_e( 'Event starts', 'persiano-hub' ); ?></span><input type="datetime-local" name="persiano_pub_event_start" value="<?php echo esc_attr( $event_start ); ?>"></label>
                    <label><span><?php esc_html_e( 'Event ends', 'persiano-hub' ); ?></span><input type="datetime-local" name="persiano_pub_event_end" value="<?php echo esc_attr( $event_end ); ?>"></label>
                </div>
            </div>

            <div class="ph-campaign-tip">
                <strong><?php esc_html_e( 'How this works:', 'persiano-hub' ); ?></strong>
                <?php esc_html_e( 'Save changes without sending anything, or use Publish Selected Channels to send only the destinations currently checked above. Unchecked channels are never part of that dispatch.', 'persiano-hub' ); ?>
            </div>
        </div>
        <?php
    }

    public static function render_actions_meta_box( $post ) {
        $is_published = 'publish' === $post->post_status;
        echo '<div class="ph-campaign-actions">';

        echo '<button type="submit" name="save" value="Update" class="button button-large">' . esc_html__( $is_published ? 'Save Changes' : 'Save Draft', 'persiano-hub' ) . '</button>';
        $publish_name  = $is_published ? 'save' : 'publish';
        $publish_value = $is_published ? 'Update' : 'Publish';
        echo '<button type="submit" name="' . esc_attr( $publish_name ) . '" value="' . esc_attr( $publish_value ) . '" data-ph-publish-action="all" class="button button-primary button-large">' . esc_html__( 'Publish Selected Channels Now', 'persiano-hub' ) . '</button>';
        echo '<button type="submit" name="save" value="Update" data-ph-publish-action="schedule" class="button button-large ph-schedule-button">' . esc_html__( 'Schedule Selected Channels', 'persiano-hub' ) . '</button>';
        echo '<button type="submit" name="save" value="Update" data-ph-publish-action="failed" class="button button-large ph-retry-button">' . esc_html__( 'Retry Failed Channels', 'persiano-hub' ) . '</button>';

        echo '<p class="description ph-action-safety-note">' . esc_html__( 'The form is saved first. Publish Now sends only checked destinations immediately; Schedule uses the per-channel times in the editor.', 'persiano-hub' ) . '</p>';
        echo '</div>';
    }

    public static function render_status_meta_box( $post ) {
        $selected = self::get_array_meta( $post->ID, self::META_PREFIX . 'channels' );
        $statuses = self::get_array_meta( $post->ID, self::META_PREFIX . 'statuses' );
        $last     = get_post_meta( $post->ID, self::META_PREFIX . 'last_published', true );
        $logs     = self::get_array_meta( $post->ID, self::META_PREFIX . 'log' );
        $schedules = self::get_array_meta( $post->ID, self::META_PREFIX . 'schedules' );

        echo '<div class="ph-status-summary">';
        echo '<div><strong>' . esc_html__( 'Selected now', 'persiano-hub' ) . '</strong><span>' . esc_html( $selected ? implode( ', ', array_map( static function( $channel ) { return isset( self::channels()[ $channel ] ) ? self::channels()[ $channel ] : ucfirst( $channel ); }, $selected ) ) : __( 'No channels selected', 'persiano-hub' ) ) . '</span></div>';
        if ( $last ) {
            echo '<div><strong>' . esc_html__( 'Last publish attempt', 'persiano-hub' ) . '</strong><span>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last ) ) . '</span></div>';
        }
        echo '</div>';

        echo '<div class="ph-status-grid">';
        foreach ( self::channels() as $channel => $label ) {
            $is_selected = in_array( $channel, $selected, true );
            $entry       = isset( $statuses[ $channel ] ) && is_array( $statuses[ $channel ] ) ? $statuses[ $channel ] : array();
            $status      = isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : ( $is_selected ? 'not_sent' : 'not_selected' );

            echo '<section class="ph-status-card ' . ( $is_selected ? 'is-selected' : 'is-unselected' ) . '">';
            echo '<div class="ph-status-card-head">';
            echo '<span class="dashicons ' . esc_attr( self::channel_icon( $channel ) ) . '"></span>';
            echo '<strong>' . esc_html( $label ) . '</strong>';
            echo '<em class="ph-status ph-status--' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</em>';
            echo '</div>';
            echo '<span class="ph-selection-pill ' . ( $is_selected ? 'is-on' : 'is-off' ) . '">' . esc_html( $is_selected ? __( 'Selected for next publish', 'persiano-hub' ) : __( 'Not selected now', 'persiano-hub' ) ) . '</span>';

            $schedule_entry = isset( $schedules[ $channel ] ) && is_array( $schedules[ $channel ] ) ? $schedules[ $channel ] : array();
            $schedule_state = isset( $schedule_entry['state'] ) ? sanitize_key( $schedule_entry['state'] ) : '';
            if ( $schedule_state && ! empty( $schedule_entry['timestamp'] ) && in_array( $schedule_state, array( 'scheduled', 'paused', 'cancelled', 'failed' ), true ) ) {
                echo '<div class="ph-schedule-status-line"><strong>' . esc_html__( 'Schedule:', 'persiano-hub' ) . '</strong> ' . esc_html( ucfirst( $schedule_state ) . ' · ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $schedule_entry['timestamp'] ) ) ) . '</div>';
                $controls = array();
                if ( 'scheduled' === $schedule_state ) {
                    $pause_url = wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_schedule_control&post_id=' . $post->ID . '&channel=' . $channel . '&mode=pause' ), 'persiano_hub_schedule_control_' . $post->ID . '_' . $channel . '_pause' );
                    $cancel_url = wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_schedule_control&post_id=' . $post->ID . '&channel=' . $channel . '&mode=cancel' ), 'persiano_hub_schedule_control_' . $post->ID . '_' . $channel . '_cancel' );
                    $controls[] = '<a href="' . esc_url( $pause_url ) . '">' . esc_html__( 'Pause', 'persiano-hub' ) . '</a>';
                    $controls[] = '<a href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancel', 'persiano-hub' ) . '</a>';
                } elseif ( 'paused' === $schedule_state ) {
                    $resume_url = wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_schedule_control&post_id=' . $post->ID . '&channel=' . $channel . '&mode=resume' ), 'persiano_hub_schedule_control_' . $post->ID . '_' . $channel . '_resume' );
                    $cancel_url = wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_schedule_control&post_id=' . $post->ID . '&channel=' . $channel . '&mode=cancel' ), 'persiano_hub_schedule_control_' . $post->ID . '_' . $channel . '_cancel' );
                    $controls[] = '<a href="' . esc_url( $resume_url ) . '">' . esc_html__( 'Resume', 'persiano-hub' ) . '</a>';
                    $controls[] = '<a href="' . esc_url( $cancel_url ) . '">' . esc_html__( 'Cancel', 'persiano-hub' ) . '</a>';
                }
                if ( $controls ) {
                    echo '<div class="ph-schedule-controls">' . wp_kses_post( implode( ' · ', $controls ) ) . '</div>';
                }
            }

            if ( ! empty( $entry['message'] ) ) {
                echo '<p class="ph-status-message">' . esc_html( $entry['message'] ) . '</p>';
            } elseif ( ! $is_selected ) {
                echo '<p class="ph-status-message">' . esc_html__( 'This destination will not receive the next publish request.', 'persiano-hub' ) . '</p>';
            } else {
                echo '<p class="ph-status-message">' . esc_html__( 'Selected and waiting for the next publish request.', 'persiano-hub' ) . '</p>';
            }

            $links = array();
            if ( ! empty( $entry['url'] ) ) {
                $url_label = ! empty( $entry['url_label'] ) ? $entry['url_label'] : __( 'View published item', 'persiano-hub' );
                $links[] = '<a href="' . esc_url( $entry['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $url_label ) . '</a>';
            }
            if ( 'website' === $channel && ! empty( $entry['hub_url'] ) ) {
                $hub_label = ! empty( $entry['hub_url_label'] ) ? $entry['hub_url_label'] : __( 'Manage website content', 'persiano-hub' );
                $links[] = '<a href="' . esc_url( $entry['hub_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $hub_label ) . '</a>';
            }
            if ( 'website' === $channel && ( 'failed' === $status || ! empty( $entry['url'] ) ) ) {
                $repair_url = wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_repair_website_destination&post_id=' . $post->ID ), 'persiano_hub_repair_website_destination_' . $post->ID );
                $links[] = '<a href="' . esc_url( $repair_url ) . '">' . esc_html__( 'Repair website destination', 'persiano-hub' ) . '</a>';
            }
            if ( $links ) {
                echo '<p class="ph-status-links">' . wp_kses_post( implode( ' · ', $links ) ) . '</p>';
            }
            echo '</section>';
        }
        echo '</div>';

        if ( $logs ) {
            $latest = array_slice( array_reverse( $logs ), 0, 6 );
            echo '<details class="ph-publish-log"><summary>' . esc_html__( 'Recent publishing activity', 'persiano-hub' ) . '</summary><ul>';
            foreach ( $latest as $log ) {
                if ( ! is_array( $log ) ) {
                    continue;
                }
                $channel = isset( $log['channel'] ) ? sanitize_key( $log['channel'] ) : '';
                $message = isset( $log['message'] ) ? (string) $log['message'] : '';
                if ( '' === $channel && '' === $message ) {
                    continue;
                }
                echo '<li><strong>' . esc_html( $channel && isset( self::channels()[ $channel ] ) ? self::channels()[ $channel ] : __( 'System', 'persiano-hub' ) ) . ':</strong> ' . esc_html( $message ) . '</li>';
            }
            echo '</ul></details>';
        }
    }

    public static function save_campaign( $post_id, $post, $update ) {
        if ( ! isset( $_POST['persiano_hub_campaign_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_hub_campaign_nonce'] ) ), 'persiano_hub_save_campaign' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $types = self::content_types();
        $type  = isset( $_POST['persiano_pub_type'] ) ? sanitize_key( wp_unslash( $_POST['persiano_pub_type'] ) ) : 'update';
        if ( ! isset( $types[ $type ] ) ) {
            $type = 'update';
        }

        $allowed_channels = array_keys( self::channels() );
        $channels = isset( $_POST['persiano_pub_channels'] ) ? (array) wp_unslash( $_POST['persiano_pub_channels'] ) : array();
        $channels = array_values( array_intersect( $allowed_channels, array_map( 'sanitize_key', $channels ) ) );

        update_post_meta( $post_id, self::META_PREFIX . 'type', $type );
        update_post_meta( $post_id, self::META_PREFIX . 'channels', $channels );

        // A channel that has been unchecked must never publish later because of
        // an older scheduled job. Cancel its active schedule immediately while
        // retaining the historical delivery record for audit/reference.
        $saved_schedules = self::get_array_meta( $post_id, self::META_PREFIX . 'schedules' );
        $saved_statuses_for_schedule = self::get_array_meta( $post_id, self::META_PREFIX . 'statuses' );
        $schedule_changed = false;
        foreach ( $saved_schedules as $scheduled_channel => $schedule_entry ) {
            if ( in_array( $scheduled_channel, $channels, true ) || ! is_array( $schedule_entry ) ) {
                continue;
            }
            $schedule_state = isset( $schedule_entry['state'] ) ? sanitize_key( $schedule_entry['state'] ) : '';
            if ( ! in_array( $schedule_state, array( 'scheduled', 'paused' ), true ) ) {
                continue;
            }
            self::clear_channel_schedule_event( $post_id, $scheduled_channel, $schedule_entry );
            $saved_schedules[ $scheduled_channel ]['state'] = 'cancelled';
            $saved_schedules[ $scheduled_channel ]['updated'] = time();
            $previous = isset( $saved_statuses_for_schedule[ $scheduled_channel ] ) && is_array( $saved_statuses_for_schedule[ $scheduled_channel ] ) ? $saved_statuses_for_schedule[ $scheduled_channel ] : array();
            $previous['status'] = 'cancelled';
            $previous['message'] = __( 'Scheduled publishing was cancelled because this channel was unselected.', 'persiano-hub' );
            $previous['time'] = time();
            $saved_statuses_for_schedule[ $scheduled_channel ] = $previous;
            $schedule_changed = true;
        }
        if ( $schedule_changed ) {
            update_post_meta( $post_id, self::META_PREFIX . 'schedules', $saved_schedules );
            update_post_meta( $post_id, self::META_PREFIX . 'statuses', $saved_statuses_for_schedule );
        }

        // Preserve historical delivery status even when a channel is unchecked.
        // Dispatch and retry always start from the CURRENT selected-channel list,
        // so retaining history cannot cause an unchecked destination to publish.
        // It does keep useful links to previously-created website/social content.
        $saved_statuses = self::get_array_meta( $post_id, self::META_PREFIX . 'statuses' );
        update_post_meta( $post_id, self::META_PREFIX . 'statuses', $saved_statuses );

        // Cancel any stale immediate jobs left by an older plugin version before
        // scheduling a fresh request from the current saved form.
        wp_clear_scheduled_hook( 'persiano_hub_dispatch_campaign', array( $post_id ) );
        wp_clear_scheduled_hook( 'persiano_hub_dispatch_campaign_mode', array( $post_id, 'all' ) );
        wp_clear_scheduled_hook( 'persiano_hub_dispatch_campaign_mode', array( $post_id, 'failed' ) );

        update_post_meta( $post_id, self::META_PREFIX . 'product_id', isset( $_POST['persiano_pub_product_id'] ) ? absint( $_POST['persiano_pub_product_id'] ) : 0 );
        update_post_meta( $post_id, self::META_PREFIX . 'cta_url', isset( $_POST['persiano_pub_cta_url'] ) ? esc_url_raw( wp_unslash( $_POST['persiano_pub_cta_url'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'instagram_caption', isset( $_POST['persiano_pub_instagram_caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_pub_instagram_caption'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'telegram_caption', isset( $_POST['persiano_pub_telegram_caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_pub_telegram_caption'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'google_caption', isset( $_POST['persiano_pub_google_caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_pub_google_caption'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'email_subject', isset( $_POST['persiano_pub_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_email_subject'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'email_preview', isset( $_POST['persiano_pub_email_preview'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_email_preview'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'email_body', isset( $_POST['persiano_pub_email_body'] ) ? wp_kses_post( wp_unslash( $_POST['persiano_pub_email_body'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'email_tags', isset( $_POST['persiano_pub_email_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_email_tags'] ) ) : '' );
        $email_template = isset( $_POST['persiano_pub_email_template'] ) ? sanitize_key( wp_unslash( $_POST['persiano_pub_email_template'] ) ) : 'auto';
        if ( ! in_array( $email_template, array( 'auto', 'weekly_menu', 'promotion', 'product', 'event', 'update' ), true ) ) { $email_template = 'auto'; }
        update_post_meta( $post_id, self::META_PREFIX . 'email_template', $email_template );
        update_post_meta( $post_id, self::META_PREFIX . 'email_image_id', isset( $_POST['persiano_pub_email_image_id'] ) ? absint( $_POST['persiano_pub_email_image_id'] ) : 0 );
        $email_image_mode = isset( $_POST['persiano_pub_email_image_mode'] ) ? sanitize_key( wp_unslash( $_POST['persiano_pub_email_image_mode'] ) ) : 'fit';
        if ( ! in_array( $email_image_mode, array( 'fit', 'original', 'cover' ), true ) ) { $email_image_mode = 'fit'; }
        update_post_meta( $post_id, self::META_PREFIX . 'email_image_mode', $email_image_mode );
        $email_image_position = isset( $_POST['persiano_pub_email_image_position'] ) ? sanitize_key( wp_unslash( $_POST['persiano_pub_email_image_position'] ) ) : 'center';
        if ( ! in_array( $email_image_position, array( 'top', 'center', 'bottom' ), true ) ) { $email_image_position = 'center'; }
        update_post_meta( $post_id, self::META_PREFIX . 'email_image_position', $email_image_position );
        update_post_meta( $post_id, self::META_PREFIX . 'video_id', isset( $_POST['persiano_pub_video_id'] ) ? absint( $_POST['persiano_pub_video_id'] ) : 0 );
        $instagram_format = isset( $_POST['persiano_pub_instagram_format'] ) ? sanitize_key( wp_unslash( $_POST['persiano_pub_instagram_format'] ) ) : 'auto'; if ( ! in_array( $instagram_format, array( 'auto','post','reel','carousel','story' ), true ) ) { $instagram_format = 'auto'; } update_post_meta( $post_id, self::META_PREFIX . 'instagram_format', $instagram_format );
        $carousel_ids = isset( $_POST['persiano_pub_instagram_carousel_ids'] ) ? implode( ',', array_slice( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', wp_unslash( $_POST['persiano_pub_instagram_carousel_ids'] ) ) ) ), 0, 10 ) ) : ''; update_post_meta( $post_id, self::META_PREFIX . 'instagram_carousel_ids', $carousel_ids );
        update_post_meta( $post_id, self::META_PREFIX . 'google_image_id', isset( $_POST['persiano_pub_google_image_id'] ) ? absint( $_POST['persiano_pub_google_image_id'] ) : 0 );
        update_post_meta( $post_id, self::META_PREFIX . 'event_start', isset( $_POST['persiano_pub_event_start'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_event_start'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'event_end', isset( $_POST['persiano_pub_event_end'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_event_end'] ) ) : '' );

        update_post_meta( $post_id, self::META_PREFIX . 'availability_date', isset( $_POST['persiano_pub_availability_date'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_availability_date'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'availability_deadline', isset( $_POST['persiano_pub_availability_deadline'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_availability_deadline'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'availability_end', isset( $_POST['persiano_pub_availability_end'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_availability_end'] ) ) : '' );
        $availability_stock = isset( $_POST['persiano_pub_availability_stock'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['persiano_pub_availability_stock'] ) ) ) : '';
        update_post_meta( $post_id, self::META_PREFIX . 'availability_stock', '' === $availability_stock ? '' : max( 0, absint( $availability_stock ) ) );
        update_post_meta( $post_id, self::META_PREFIX . 'availability_close', isset( $_POST['persiano_pub_availability_close'] ) ? 'yes' : 'no' );
        update_post_meta( $post_id, self::META_PREFIX . 'availability_remove', isset( $_POST['persiano_pub_availability_remove'] ) ? 'yes' : 'no' );

        update_post_meta( $post_id, self::META_PREFIX . 'promotion_kicker', isset( $_POST['persiano_pub_promotion_kicker'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_promotion_kicker'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'promotion_offer', isset( $_POST['persiano_pub_promotion_offer'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_promotion_offer'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'promotion_urgency', isset( $_POST['persiano_pub_promotion_urgency'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_promotion_urgency'] ) ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'promotion_expiry', isset( $_POST['persiano_pub_promotion_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_pub_promotion_expiry'] ) ) : '' );
        $expiry_action = isset( $_POST['persiano_pub_promotion_expiry_action'] ) ? sanitize_key( wp_unslash( $_POST['persiano_pub_promotion_expiry_action'] ) ) : 'message';
        if ( ! in_array( $expiry_action, array( 'message', 'redirect_product', 'keep' ), true ) ) {
            $expiry_action = 'message';
        }
        update_post_meta( $post_id, self::META_PREFIX . 'promotion_expiry_action', $expiry_action );

        $schedule_times = array();
        $posted_schedule = isset( $_POST['persiano_pub_schedule'] ) ? (array) wp_unslash( $_POST['persiano_pub_schedule'] ) : array();
        foreach ( array_keys( self::channels() ) as $channel_key ) {
            $schedule_times[ $channel_key ] = isset( $posted_schedule[ $channel_key ] ) ? sanitize_text_field( $posted_schedule[ $channel_key ] ) : '';
        }
        update_post_meta( $post_id, self::META_PREFIX . 'schedule_times', $schedule_times );

        if ( isset( $_POST['persiano_pub_featured_image_id'] ) ) {
            $featured_id = absint( $_POST['persiano_pub_featured_image_id'] );
            if ( $featured_id ) {
                set_post_thumbnail( $post_id, $featured_id );
            } else {
                delete_post_thumbnail( $post_id );
            }
        }

        // Publishing buttons submit this same form. At this point title, content,
        // media and the exact checkbox selection are all saved, so the delayed job
        // cannot accidentally use a stale set of destinations.
        $publish_action = isset( $_POST['persiano_hub_publish_action'] ) ? sanitize_key( wp_unslash( $_POST['persiano_hub_publish_action'] ) ) : '';
        if ( 'schedule' === $publish_action ) {
            $result = self::schedule_selected_channels( $post_id, $channels, $schedule_times );
            self::set_admin_notice( $result['success'] ? 'success' : 'warning', $result['message'] );
        } elseif ( in_array( $publish_action, array( 'all', 'failed' ), true ) ) {
            update_post_meta( $post_id, self::META_PREFIX . 'last_dispatch_selection', $channels );
            if ( 'all' === $publish_action ) {
                self::cancel_channel_schedules( $post_id, $channels, 'cancelled' );
            }
            wp_schedule_single_event( time() + 2, 'persiano_hub_dispatch_campaign_mode', array( $post_id, $publish_action ) );
        }
    }

    public static function schedule_initial_dispatch( $new_status, $old_status, $post ) {
        // Immediate publishing is dispatched only from save_campaign(), after the
        // current checkbox selection has been saved. This transition hook is kept
        // solely for WordPress-scheduled campaigns moving from future -> publish.
        if ( self::POST_TYPE !== $post->post_type || 'publish' !== $new_status || 'future' !== $old_status ) {
            return;
        }

        wp_clear_scheduled_hook( 'persiano_hub_dispatch_campaign', array( $post->ID ) );
        wp_schedule_single_event( time() + 3, 'persiano_hub_dispatch_campaign', array( $post->ID ) );
    }

    public static function dispatch_scheduled_campaign( $post_id ) {
        self::dispatch_campaign( absint( $post_id ), false );
    }

    public static function dispatch_scheduled_campaign_mode( $post_id, $mode ) {
        $mode = sanitize_key( $mode );
        self::dispatch_campaign( absint( $post_id ), true, 'failed' === $mode );
    }


    /**
     * Schedule each selected channel independently.
     */
    private static function schedule_selected_channels( $post_id, $channels, $schedule_times ) {
        $channels = array_values( array_intersect( array_keys( self::channels() ), (array) $channels ) );
        if ( ! $channels ) {
            return array( 'success' => false, 'message' => __( 'Select at least one publishing channel before scheduling.', 'persiano-hub' ) );
        }

        $schedules = self::get_array_meta( $post_id, self::META_PREFIX . 'schedules' );
        $statuses  = self::get_array_meta( $post_id, self::META_PREFIX . 'statuses' );
        $scheduled = 0;
        $invalid   = array();

        foreach ( $channels as $channel ) {
            $raw = isset( $schedule_times[ $channel ] ) ? trim( (string) $schedule_times[ $channel ] ) : '';
            $timestamp = self::local_input_to_timestamp( $raw );
            if ( ! $timestamp || $timestamp <= time() + 15 ) {
                $invalid[] = self::channels()[ $channel ];
                continue;
            }

            self::clear_channel_schedule_event( $post_id, $channel, isset( $schedules[ $channel ] ) ? $schedules[ $channel ] : array() );
            $token = wp_generate_uuid4();
            $schedules[ $channel ] = array(
                'timestamp' => $timestamp,
                'state'     => 'scheduled',
                'token'     => $token,
                'updated'   => time(),
            );
            wp_schedule_single_event( $timestamp, 'persiano_hub_dispatch_channel', array( $post_id, $channel, $token ) );
            $previous_status = isset( $statuses[ $channel ] ) && is_array( $statuses[ $channel ] ) ? $statuses[ $channel ] : array();
            $statuses[ $channel ] = array(
                'status'        => 'scheduled',
                'message'       => sprintf( __( 'Scheduled for %s.', 'persiano-hub' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ),
                'external_id'   => isset( $previous_status['external_id'] ) ? $previous_status['external_id'] : '',
                'url'           => isset( $previous_status['url'] ) ? $previous_status['url'] : '',
                'hub_url'       => isset( $previous_status['hub_url'] ) ? $previous_status['hub_url'] : '',
                'url_label'     => isset( $previous_status['url_label'] ) ? $previous_status['url_label'] : '',
                'hub_url_label' => isset( $previous_status['hub_url_label'] ) ? $previous_status['hub_url_label'] : '',
                'time'          => time(),
            );
            self::add_log( $post_id, $channel, 'scheduled', $statuses[ $channel ]['message'] );
            $scheduled++;
        }

        update_post_meta( $post_id, self::META_PREFIX . 'schedules', $schedules );
        update_post_meta( $post_id, self::META_PREFIX . 'statuses', $statuses );
        self::recalculate_overall_status( $post_id );

        if ( ! $scheduled ) {
            return array( 'success' => false, 'message' => __( 'No channels were scheduled. Enter a future date and time for each selected channel.', 'persiano-hub' ) );
        }
        if ( $invalid ) {
            return array( 'success' => true, 'message' => sprintf( __( '%1$d channel(s) scheduled. Add a valid future time for: %2$s.', 'persiano-hub' ), $scheduled, implode( ', ', $invalid ) ) );
        }
        return array( 'success' => true, 'message' => sprintf( _n( '%d channel scheduled.', '%d channels scheduled.', $scheduled, 'persiano-hub' ), $scheduled ) );
    }

    private static function clear_channel_schedule_event( $post_id, $channel, $entry ) {
        if ( ! empty( $entry['token'] ) ) {
            wp_clear_scheduled_hook( 'persiano_hub_dispatch_channel', array( absint( $post_id ), sanitize_key( $channel ), (string) $entry['token'] ) );
        }
    }

    private static function cancel_channel_schedules( $post_id, $channels, $state = 'cancelled' ) {
        $schedules = self::get_array_meta( $post_id, self::META_PREFIX . 'schedules' );
        foreach ( (array) $channels as $channel ) {
            $channel = sanitize_key( $channel );
            if ( empty( $schedules[ $channel ] ) || ! is_array( $schedules[ $channel ] ) ) {
                continue;
            }
            self::clear_channel_schedule_event( $post_id, $channel, $schedules[ $channel ] );
            $schedules[ $channel ]['state'] = $state;
            $schedules[ $channel ]['updated'] = time();
        }
        update_post_meta( $post_id, self::META_PREFIX . 'schedules', $schedules );
    }

    public static function dispatch_scheduled_channel( $post_id, $channel, $token ) {
        $post_id = absint( $post_id );
        $channel = sanitize_key( $channel );
        $schedules = self::get_array_meta( $post_id, self::META_PREFIX . 'schedules' );
        $statuses  = self::get_array_meta( $post_id, self::META_PREFIX . 'statuses' );
        $entry = isset( $schedules[ $channel ] ) && is_array( $schedules[ $channel ] ) ? $schedules[ $channel ] : array();
        if ( empty( $entry['token'] ) || ! hash_equals( (string) $entry['token'], (string) $token ) || 'scheduled' !== ( isset( $entry['state'] ) ? $entry['state'] : '' ) ) {
            return;
        }

        // Defensive guard: even if an old cron event survives a cache/plugin
        // race, an unchecked channel is never allowed to publish.
        $selected_channels = self::get_array_meta( $post_id, self::META_PREFIX . 'channels' );
        if ( ! in_array( $channel, $selected_channels, true ) ) {
            $schedules[ $channel ]['state'] = 'cancelled';
            $schedules[ $channel ]['updated'] = time();
            update_post_meta( $post_id, self::META_PREFIX . 'schedules', $schedules );
            self::recalculate_overall_status( $post_id );
            return;
        }

        $result = self::dispatch_campaign( $post_id, true, false, array( $channel ) );
        $schedules = self::get_array_meta( $post_id, self::META_PREFIX . 'schedules' );
        if ( isset( $schedules[ $channel ] ) && is_array( $schedules[ $channel ] ) && ! empty( $schedules[ $channel ]['token'] ) && hash_equals( (string) $schedules[ $channel ]['token'], (string) $token ) ) {
            $schedules[ $channel ]['state']   = $result['success'] ? 'completed' : 'failed';
            $schedules[ $channel ]['updated'] = time();
            update_post_meta( $post_id, self::META_PREFIX . 'schedules', $schedules );
        }
        self::recalculate_overall_status( $post_id );
    }

    public static function handle_schedule_campaign() {
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'You do not have permission to schedule this campaign.', 'persiano-hub' ), 403 );
        }
        wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) );
        exit;
    }

    public static function handle_schedule_control() {
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        $channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
        $mode    = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
        if ( ! $post_id || ! isset( self::channels()[ $channel ] ) || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'You do not have permission to change this schedule.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_schedule_control_' . $post_id . '_' . $channel . '_' . $mode );

        $schedules = self::get_array_meta( $post_id, self::META_PREFIX . 'schedules' );
        $statuses  = self::get_array_meta( $post_id, self::META_PREFIX . 'statuses' );
        $entry = isset( $schedules[ $channel ] ) && is_array( $schedules[ $channel ] ) ? $schedules[ $channel ] : array();
        if ( ! $entry ) {
            self::set_admin_notice( 'warning', __( 'No schedule exists for that channel.', 'persiano-hub' ) );
        } elseif ( 'pause' === $mode ) {
            self::clear_channel_schedule_event( $post_id, $channel, $entry );
            $entry['state'] = 'paused';
            $entry['updated'] = time();
            $schedules[ $channel ] = $entry;
            update_post_meta( $post_id, self::META_PREFIX . 'schedules', $schedules );
            $statuses[ $channel ]['status'] = 'paused';
            $statuses[ $channel ]['message'] = __( 'Scheduled publishing is paused.', 'persiano-hub' );
            update_post_meta( $post_id, self::META_PREFIX . 'statuses', $statuses );
            self::set_admin_notice( 'success', __( 'Channel schedule paused.', 'persiano-hub' ) );
        } elseif ( 'cancel' === $mode ) {
            self::clear_channel_schedule_event( $post_id, $channel, $entry );
            $entry['state'] = 'cancelled';
            $entry['updated'] = time();
            $schedules[ $channel ] = $entry;
            update_post_meta( $post_id, self::META_PREFIX . 'schedules', $schedules );
            $statuses[ $channel ]['status'] = 'cancelled';
            $statuses[ $channel ]['message'] = __( 'Scheduled publishing was cancelled.', 'persiano-hub' );
            update_post_meta( $post_id, self::META_PREFIX . 'statuses', $statuses );
            self::set_admin_notice( 'success', __( 'Channel schedule cancelled.', 'persiano-hub' ) );
        } elseif ( 'resume' === $mode ) {
            self::clear_channel_schedule_event( $post_id, $channel, $entry );
            $timestamp = ! empty( $entry['timestamp'] ) ? absint( $entry['timestamp'] ) : 0;
            if ( $timestamp <= time() + 15 ) {
                $timestamp = time() + 60;
                $entry['timestamp'] = $timestamp;
            }
            $entry['state'] = 'scheduled';
            $entry['token'] = wp_generate_uuid4();
            $entry['updated'] = time();
            wp_schedule_single_event( $timestamp, 'persiano_hub_dispatch_channel', array( $post_id, $channel, $entry['token'] ) );
            $schedules[ $channel ] = $entry;
            update_post_meta( $post_id, self::META_PREFIX . 'schedules', $schedules );
            $statuses[ $channel ]['status'] = 'scheduled';
            $statuses[ $channel ]['message'] = sprintf( __( 'Scheduled for %s.', 'persiano-hub' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
            update_post_meta( $post_id, self::META_PREFIX . 'statuses', $statuses );
            self::set_admin_notice( 'success', __( 'Channel schedule resumed.', 'persiano-hub' ) );
        }
        self::recalculate_overall_status( $post_id );
        wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) );
        exit;
    }

    public static function handle_manual_publish() {
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'You do not have permission to publish this campaign.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_publish_campaign_' . $post_id );

        $only_failed = ! empty( $_GET['mode'] ) && 'failed' === sanitize_key( wp_unslash( $_GET['mode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $result = self::dispatch_campaign( $post_id, true, $only_failed );
        self::set_admin_notice( $result['success'] ? 'success' : 'warning', $result['message'] );
        wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) );
        exit;
    }

    public static function dispatch_campaign( $post_id, $force = false, $only_failed = false, $channels_override = null ) {
        $post = get_post( $post_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type || ( ! $force && 'publish' !== $post->post_status ) ) {
            return array( 'success' => false, 'message' => __( 'Campaign is not ready to publish.', 'persiano-hub' ) );
        }

        $channels = is_array( $channels_override ) ? array_values( array_intersect( array_keys( self::channels() ), array_map( 'sanitize_key', $channels_override ) ) ) : self::get_array_meta( $post_id, self::META_PREFIX . 'channels' );
        if ( $force && null === $channels_override ) {
            $snapshot = self::get_array_meta( $post_id, self::META_PREFIX . 'last_dispatch_selection' );
            if ( $snapshot ) {
                // A manual publish uses the exact selection saved by the button
                // click that created this dispatch request.
                $channels = array_values( array_intersect( array_keys( self::channels() ), $snapshot ) );
            }
        }
        if ( ! $channels ) {
            return array( 'success' => false, 'message' => __( 'No publishing channels are selected.', 'persiano-hub' ) );
        }

        $existing_statuses = self::get_array_meta( $post_id, self::META_PREFIX . 'statuses' );

        // Scheduled initial dispatches are idempotent. If WordPress cron fires the
        // same event twice, channels that already succeeded are not posted again.
        // Explicit Publish Selected Channels remains a forced republish.
        if ( ! $force ) {
            $channels = array_values(
                array_filter(
                    $channels,
                    static function( $channel ) use ( $existing_statuses ) {
                        return empty( $existing_statuses[ $channel ]['status'] ) || 'success' !== $existing_statuses[ $channel ]['status'];
                    }
                )
            );
            if ( ! $channels ) {
                return array( 'success' => true, 'message' => __( 'All currently selected channels were already published. Duplicate dispatch skipped.', 'persiano-hub' ) );
            }
        }

        if ( $only_failed ) {
            $channels = array_values(
                array_filter(
                    $channels,
                    static function( $channel ) use ( $existing_statuses ) {
                        return isset( $existing_statuses[ $channel ]['status'] ) && 'failed' === $existing_statuses[ $channel ]['status'];
                    }
                )
            );
            if ( ! $channels ) {
                return array( 'success' => true, 'message' => __( 'There are no failed channels to retry.', 'persiano-hub' ) );
            }
        }

        $results = array();

        foreach ( $channels as $channel ) {
            // Prevent two overlapping cron/browser requests from posting the same
            // campaign to the same destination at the same moment.
            $lock_key = 'ph_pub_lock_' . absint( $post_id ) . '_' . sanitize_key( $channel );
            if ( get_transient( $lock_key ) ) {
                $results[ $channel ] = array(
                    'success' => true,
                    'message' => __( 'Duplicate dispatch skipped because this channel is already being processed.', 'persiano-hub' ),
                );
                continue;
            }
            set_transient( $lock_key, 1, 90 );

            switch ( $channel ) {
                case 'website':
                    $results[ $channel ] = self::publish_website( $post_id );
                    break;
                case 'telegram':
                    $results[ $channel ] = self::publish_telegram( $post_id );
                    break;
                case 'instagram':
                    $results[ $channel ] = self::publish_instagram( $post_id );
                    break;
                case 'google':
                    $results[ $channel ] = self::publish_google( $post_id );
                    break;
                case 'email':
                    $results[ $channel ] = class_exists( 'Persiano_Hub_Marketing' ) ? Persiano_Hub_Marketing::publish_email( $post_id ) : self::error_result( __( 'Marketing email tools are unavailable.', 'persiano-hub' ) );
                    break;
            }

            delete_transient( $lock_key );
        }

        $statuses = self::get_array_meta( $post_id, self::META_PREFIX . 'statuses' );
        $success_count = 0;
        foreach ( $results as $channel => $result ) {
            $statuses[ $channel ] = array(
                'status'      => $result['success'] ? 'success' : 'failed',
                'message'     => $result['message'],
                'external_id' => isset( $result['external_id'] ) ? $result['external_id'] : '',
                'url'         => isset( $result['url'] ) ? $result['url'] : '',
                'hub_url'       => isset( $result['hub_url'] ) ? $result['hub_url'] : '',
                'url_label'     => isset( $result['url_label'] ) ? $result['url_label'] : '',
                'hub_url_label' => isset( $result['hub_url_label'] ) ? $result['hub_url_label'] : '',
                'time'          => time(),
            );
            self::add_log( $post_id, $channel, $result['success'] ? 'success' : 'failed', $result['message'] );
            if ( $result['success'] ) {
                $success_count++;
            }
        }
        update_post_meta( $post_id, self::META_PREFIX . 'statuses', $statuses );
        update_post_meta( $post_id, self::META_PREFIX . 'last_published', time() );

        $total = count( $results );
        self::recalculate_overall_status( $post_id );
        if ( $total && $success_count === $total ) {
            return array( 'success' => true, 'message' => _n( 'Selected channel published successfully.', 'All selected channels published successfully.', $total, 'persiano-hub' ) );
        }
        if ( $success_count > 0 ) {
            return array( 'success' => false, 'message' => sprintf( __( '%1$d of %2$d selected channels published successfully. Check the Publishing Status box for details.', 'persiano-hub' ), $success_count, $total ) );
        }
        return array( 'success' => false, 'message' => __( 'The selected channels could not be published. Check the Publishing Status box and connection settings.', 'persiano-hub' ) );
    }

    private static function publish_website( $campaign_id ) {
        $campaign = get_post( $campaign_id );
        if ( ! $campaign ) {
            return self::error_result( __( 'Campaign not found.', 'persiano-hub' ) );
        }

        $type = sanitize_key( get_post_meta( $campaign_id, self::META_PREFIX . 'type', true ) );

        // Product introductions do not create duplicate website content.
        if ( in_array( $type, array( 'dish', 'pantry' ), true ) ) {
            return self::publish_product_website_destination( $campaign_id, $type );
        }

        // Availability is operational website content: it activates the existing
        // product in This Week instead of creating another post.
        if ( 'availability' === $type ) {
            return self::apply_product_availability( $campaign_id );
        }

        $is_promotion = 'promotion' === $type;
        $target_post_type = $is_promotion ? self::PROMOTION_POST_TYPE : self::UPDATE_POST_TYPE;
        $website_post_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'website_post_id', true ) );
        $content = (string) $campaign->post_content;
        if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
            $content = wpautop( esc_html( self::shared_caption( $campaign_id ) ) );
        }

        if ( ! $is_promotion ) {
            $cta_url = self::resolve_cta_url( $campaign_id, $website_post_id );
            if ( $cta_url ) {
                $content .= "\n<!-- wp:buttons --><div class=\"wp-block-buttons\"><!-- wp:button --><div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"" . esc_url( $cta_url ) . "\">" . esc_html( self::cta_label( $campaign_id ) ) . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
            }
        }

        $title = trim( (string) $campaign->post_title );
        if ( '' === $title ) {
            $title = $is_promotion ? sprintf( __( 'Persiano Promotion %d', 'persiano-hub' ), $campaign_id ) : sprintf( __( 'Persiano Update %d', 'persiano-hub' ), $campaign_id );
        }
        $slug = sanitize_title( $title );
        if ( '' === $slug ) {
            $slug = ( $is_promotion ? 'offer-' : 'update-' ) . absint( $campaign_id );
        }

        $meta_input = array(
            '_persiano_campaign_id'  => $campaign_id,
            '_persiano_content_type' => $type,
        );
        if ( $is_promotion ) {
            $meta_input['_persiano_related_product_id']      = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'product_id', true ) );
            $meta_input['_persiano_promotion_kicker']        = (string) get_post_meta( $campaign_id, self::META_PREFIX . 'promotion_kicker', true );
            $meta_input['_persiano_promotion_offer']         = (string) get_post_meta( $campaign_id, self::META_PREFIX . 'promotion_offer', true );
            $meta_input['_persiano_promotion_urgency']       = (string) get_post_meta( $campaign_id, self::META_PREFIX . 'promotion_urgency', true );
            $meta_input['_persiano_promotion_expiry']        = (string) get_post_meta( $campaign_id, self::META_PREFIX . 'promotion_expiry', true );
            $meta_input['_persiano_promotion_expiry_action'] = (string) get_post_meta( $campaign_id, self::META_PREFIX . 'promotion_expiry_action', true );
            $meta_input['_persiano_promotion_cta_url']       = self::resolve_product_cta_url( $campaign_id );
            $meta_input['_persiano_promotion_cta_label']     = self::cta_label( $campaign_id );
        }

        $args = array(
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $content,
            'post_excerpt' => wp_trim_words( self::shared_caption( $campaign_id ), 34 ),
            'post_status'  => 'publish',
            'post_type'    => $target_post_type,
            'post_author'  => $campaign->post_author,
            'meta_input'   => $meta_input,
        );

        if ( $website_post_id && get_post( $website_post_id ) && 'trash' !== get_post_status( $website_post_id ) ) {
            $args['ID'] = $website_post_id;
            $result = wp_update_post( wp_slash( $args ), true );
        } else {
            $result = wp_insert_post( wp_slash( $args ), true );
        }

        if ( is_wp_error( $result ) ) {
            return self::error_result( $result->get_error_message() );
        }

        $website_post_id = absint( $result );
        $thumb_id = get_post_thumbnail_id( $campaign_id );
        if ( $thumb_id ) {
            set_post_thumbnail( $website_post_id, $thumb_id );
        }
        update_post_meta( $campaign_id, self::META_PREFIX . 'website_post_id', $website_post_id );

        $url = self::validated_destination_url( $website_post_id );
        if ( is_wp_error( $url ) ) {
            return self::error_result( $url->get_error_message() );
        }

        return array(
            'success'       => true,
            'message'       => $is_promotion ? __( 'Promotion landing page published.', 'persiano-hub' ) : __( 'Website update published.', 'persiano-hub' ),
            'external_id'   => (string) $website_post_id,
            'url'           => $url,
            'hub_url'       => admin_url( 'admin.php?page=persiano-hub-website-content' ),
            'url_label'     => $is_promotion ? __( 'View promotion page', 'persiano-hub' ) : __( 'View website update', 'persiano-hub' ),
            'hub_url_label' => __( 'Manage website content', 'persiano-hub' ),
        );
    }

    /**
     * Use WooCommerce as the website source of truth for dish/pantry campaigns.
     */
    private static function publish_product_website_destination( $campaign_id, $type ) {
        if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_get_product' ) ) {
            return self::error_result( __( 'WooCommerce is not available.', 'persiano-hub' ) );
        }

        $campaign  = get_post( $campaign_id );
        $product_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'product_id', true ) );
        $product   = $product_id ? wc_get_product( $product_id ) : false;
        $created   = false;

        if ( ! $product ) {
            $product = new WC_Product_Simple();
            $product->set_name( $campaign->post_title );
            $product->set_status( 'draft' );
            $product->set_catalog_visibility( 'visible' );
            $description = trim( (string) $campaign->post_content );
            if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
                $description = wpautop( esc_html( self::shared_caption( $campaign_id ) ) );
            }
            $product->set_description( wp_kses_post( $description ) );
            $product->set_short_description( wp_trim_words( self::shared_caption( $campaign_id ), 34 ) );
            $thumb_id = get_post_thumbnail_id( $campaign_id );
            if ( $thumb_id ) {
                $product->set_image_id( $thumb_id );
            }
            $product_id = $product->save();
            if ( ! $product_id ) {
                return self::error_result( __( 'WooCommerce could not create the product draft.', 'persiano-hub' ) );
            }
            $created = true;
            update_post_meta( $campaign_id, self::META_PREFIX . 'product_id', $product_id );

            if ( 'pantry' === $type ) {
                $category_slug = 'pantry';
                $term = get_term_by( 'slug', $category_slug, 'product_cat' );
                if ( ! $term || is_wp_error( $term ) ) {
                    $created_term = wp_insert_term( __( 'Pantry', 'persiano-hub' ), 'product_cat', array( 'slug' => $category_slug ) );
                    $term_id = is_wp_error( $created_term ) ? 0 : absint( $created_term['term_id'] );
                } else {
                    $term_id = absint( $term->term_id );
                }
                if ( $term_id ) {
                    wp_set_object_terms( $product_id, array( $term_id ), 'product_cat', true );
                }
            }

            $persiano_type = 'dish' === $type ? 'prepared_meal' : 'pantry';
            update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_CONTENT_TYPE, $persiano_type );
            update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_THIS_WEEK, 'no' );
            update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_PANTRY, 'pantry' === $type ? 'yes' : 'no' );

            if ( 'dish' === $type ) {
                $product->set_tax_status( 'taxable' );
                $product->set_tax_class( '' );
                $product->update_meta_data( Persiano_Hub_Product_Fields::META_TAX_TREATMENT, 'standard' );
                $product->save();
            }
        }

        update_post_meta( $product_id, '_persiano_campaign_id', $campaign_id );

        $product_status = get_post_status( $product_id );
        $is_public      = 'publish' === $product_status;
        $product_url    = $is_public ? get_permalink( $product_id ) : get_edit_post_link( $product_id, 'url' );

        if ( $created ) {
            $message = __( 'WooCommerce product draft created and linked. Add its price, stock and remaining product details in Products, then publish it when ready.', 'persiano-hub' );
        } elseif ( $is_public ) {
            $message = __( 'The linked WooCommerce product is the website destination. No duplicate website update was created.', 'persiano-hub' );
        } else {
            $message = __( 'The linked WooCommerce product is saved but is not public yet. Publish it from Products when ready.', 'persiano-hub' );
        }

        return array(
            'success'       => true,
            'message'       => $message,
            'external_id'   => (string) $product_id,
            'url'           => $product_url,
            'hub_url'       => admin_url( 'admin.php?page=persiano-hub-website-content' ),
            'url_label'     => $is_public ? __( 'View product', 'persiano-hub' ) : __( 'Edit product', 'persiano-hub' ),
            'hub_url_label' => __( 'Manage website content', 'persiano-hub' ),
        );
    }


    /**
     * Activate an existing product for the current weekly menu.
     */
    private static function apply_product_availability( $campaign_id ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return self::error_result( __( 'WooCommerce is not available.', 'persiano-hub' ) );
        }
        $product_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'product_id', true ) );
        $product = $product_id ? wc_get_product( $product_id ) : false;
        if ( ! $product ) {
            return self::error_result( __( 'Choose the WooCommerce product that is becoming available.', 'persiano-hub' ) );
        }

        update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_THIS_WEEK, 'yes' );
        $this_week = get_term_by( 'slug', 'this-week', 'product_cat' );
        if ( ! $this_week || is_wp_error( $this_week ) ) {
            $created = wp_insert_term( __( 'This Week', 'persiano-hub' ), 'product_cat', array( 'slug' => 'this-week' ) );
            $term_id = is_wp_error( $created ) ? 0 : absint( $created['term_id'] );
        } else {
            $term_id = absint( $this_week->term_id );
        }
        if ( $term_id ) {
            wp_set_object_terms( $product_id, array( $term_id ), 'product_cat', true );
        }

        $available_date = (string) get_post_meta( $campaign_id, self::META_PREFIX . 'availability_date', true );
        $deadline       = (string) get_post_meta( $campaign_id, self::META_PREFIX . 'availability_deadline', true );
        $end            = (string) get_post_meta( $campaign_id, self::META_PREFIX . 'availability_end', true );
        $stock          = get_post_meta( $campaign_id, self::META_PREFIX . 'availability_stock', true );
        $close          = 'yes' === get_post_meta( $campaign_id, self::META_PREFIX . 'availability_close', true );
        $auto_remove    = 'yes' === get_post_meta( $campaign_id, self::META_PREFIX . 'availability_remove', true );

        update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_AVAILABLE_DATE, $available_date );
        update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_ORDER_DEADLINE, $deadline );
        update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_CLOSE_DEADLINE, $close ? 'yes' : 'no' );
        update_post_meta( $product_id, '_persiano_active_availability_campaign', $campaign_id );
        update_post_meta( $campaign_id, self::META_PREFIX . 'availability_state', 'active' );

        if ( '' !== (string) $stock ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( max( 0, absint( $stock ) ) );
            $product->set_stock_status( absint( $stock ) > 0 ? 'instock' : 'outofstock' );
            $product->save();
        }

        wp_clear_scheduled_hook( 'persiano_hub_end_product_availability', array( $campaign_id, $product_id ) );
        if ( $auto_remove ) {
            $end_timestamp = self::local_input_to_timestamp( $end ?: $deadline );
            if ( $end_timestamp > time() + 15 ) {
                wp_schedule_single_event( $end_timestamp, 'persiano_hub_end_product_availability', array( $campaign_id, $product_id ) );
            }
        }

        return array(
            'success'       => true,
            'message'       => __( 'Product activated in This Week and ordering availability updated.', 'persiano-hub' ),
            'external_id'   => (string) $product_id,
            'url'           => 'publish' === get_post_status( $product_id ) ? get_permalink( $product_id ) : get_edit_post_link( $product_id, 'url' ),
            'hub_url'       => admin_url( 'admin.php?page=persiano-hub-website-content' ),
            'url_label'     => 'publish' === get_post_status( $product_id ) ? __( 'View & order product', 'persiano-hub' ) : __( 'Edit product', 'persiano-hub' ),
            'hub_url_label' => __( 'Manage website content', 'persiano-hub' ),
        );
    }

    public static function end_product_availability( $campaign_id, $product_id ) {
        $campaign_id = absint( $campaign_id );
        $product_id  = absint( $product_id );
        if ( ! $campaign_id || ! $product_id ) {
            return;
        }
        $active_campaign = absint( get_post_meta( $product_id, '_persiano_active_availability_campaign', true ) );
        if ( $active_campaign && $active_campaign !== $campaign_id ) {
            return;
        }
        update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_THIS_WEEK, 'no' );
        $term = get_term_by( 'slug', 'this-week', 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            wp_remove_object_terms( $product_id, array( absint( $term->term_id ) ), 'product_cat' );
        }
        delete_post_meta( $product_id, '_persiano_active_availability_campaign' );
        update_post_meta( $campaign_id, self::META_PREFIX . 'availability_state', 'ended' );
        self::add_log( $campaign_id, 'website', 'completed', __( 'Availability ended and the product was removed from This Week.', 'persiano-hub' ) );
    }

    /**
     * The product/order destination used by promotion landing-page buttons.
     */
    private static function resolve_product_cta_url( $campaign_id ) {
        $explicit = get_post_meta( $campaign_id, self::META_PREFIX . 'cta_url', true );
        if ( $explicit ) {
            return $explicit;
        }
        $product_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'product_id', true ) );
        if ( $product_id && 'publish' === get_post_status( $product_id ) ) {
            return get_permalink( $product_id );
        }
        $page = get_page_by_path( 'this-week' );
        return $page ? get_permalink( $page ) : home_url( '/' );
    }

    private static function validated_destination_url( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( self::UPDATE_POST_TYPE, self::PROMOTION_POST_TYPE ), true ) ) {
            return new WP_Error( 'persiano_destination_invalid', __( 'The website destination was created but is not publicly queryable yet.', 'persiano-hub' ) );
        }
        if ( '' === trim( (string) $post->post_name ) ) {
            return new WP_Error( 'persiano_destination_slug', __( 'The website destination does not have a valid public slug.', 'persiano-hub' ) );
        }
        $match = get_page_by_path( $post->post_name, OBJECT, $post->post_type );
        if ( ! $match || absint( $match->ID ) !== absint( $post_id ) ) {
            return new WP_Error( 'persiano_destination_lookup', __( 'The website destination could not be resolved internally. Use Repair website destination and try again.', 'persiano-hub' ) );
        }
        $url = get_permalink( $post_id );
        if ( ! $url ) {
            return new WP_Error( 'persiano_destination_permalink', __( 'WordPress could not create a public URL for the website destination.', 'persiano-hub' ) );
        }

        // Validate the generated public route against WordPress' active rewrite
        // rules before reporting the destination as published. This catches the
        // exact class of issue where a post exists in wp-admin but its public
        // permalink still resolves to a 404 because rewrite rules are stale.
        $resolved_id = url_to_postid( $url );
        if ( absint( $resolved_id ) !== absint( $post_id ) ) {
            return new WP_Error( 'persiano_destination_route', __( 'The website destination exists, but its public URL is not resolving correctly yet. Use Repair website destination and try again.', 'persiano-hub' ) );
        }
        return $url;
    }

    public static function handle_repair_website_destination() {
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'You do not have permission to repair this destination.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_repair_website_destination_' . $post_id );
        flush_rewrite_rules( false );
        $result = self::publish_website( $post_id );
        $statuses = self::get_array_meta( $post_id, self::META_PREFIX . 'statuses' );
        $statuses['website'] = array(
            'status'      => $result['success'] ? 'success' : 'failed',
            'message'     => $result['message'],
            'external_id' => isset( $result['external_id'] ) ? $result['external_id'] : '',
            'url'         => isset( $result['url'] ) ? $result['url'] : '',
            'hub_url'     => isset( $result['hub_url'] ) ? $result['hub_url'] : '',
            'url_label'   => isset( $result['url_label'] ) ? $result['url_label'] : '',
            'hub_url_label' => isset( $result['hub_url_label'] ) ? $result['hub_url_label'] : '',
            'time'        => time(),
        );
        update_post_meta( $post_id, self::META_PREFIX . 'statuses', $statuses );
        self::recalculate_overall_status( $post_id );
        self::set_admin_notice( $result['success'] ? 'success' : 'warning', $result['message'] );
        wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) );
        exit;
    }

    private static function publish_telegram( $campaign_id ) {
        $connections = self::get_connections();
        $token = trim( (string) $connections['telegram_bot_token'] );
        $chat  = trim( (string) $connections['telegram_chat_id'] );
        if ( ! $token || ! $chat ) {
            return self::error_result( __( 'Telegram is not connected.', 'persiano-hub' ) );
        }

        $caption = self::platform_caption( $campaign_id, 'telegram' );
        $cta_url = self::resolve_cta_url( $campaign_id );
        if ( $cta_url && class_exists( 'Persiano_Hub_Marketing' ) ) {
            $cta_url = Persiano_Hub_Marketing::tracked_url( $cta_url, $campaign_id, 'telegram', 'social' );
        }
        if ( $cta_url && false === strpos( $caption, $cta_url ) ) {
            $caption .= "\n\n" . $cta_url;
        }

        $video_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'video_id', true ) );
        $image_id = get_post_thumbnail_id( $campaign_id );
        $media_result = null;

        if ( $video_id && wp_attachment_is( 'video', $video_id ) ) {
            $file_path = get_attached_file( $video_id );
            if ( $file_path && is_readable( $file_path ) ) {
                $media_result = self::telegram_upload_file(
                    $token,
                    'sendVideo',
                    $chat,
                    'video',
                    $file_path,
                    self::truncate( $caption, 1024 ),
                    array( 'supports_streaming' => 'true' )
                );
            }
        } elseif ( $image_id ) {
            $file_path = get_attached_file( $image_id );
            if ( $file_path && is_readable( $file_path ) ) {
                $media_result = self::telegram_upload_file(
                    $token,
                    'sendPhoto',
                    $chat,
                    'photo',
                    $file_path,
                    self::truncate( $caption, 1024 )
                );
            }
        }

        if ( is_array( $media_result ) && ! empty( $media_result['success'] ) ) {
            return $media_result;
        }

        // A media failure must not lose the campaign. Send the text/call-to-action
        // as a fallback and surface the attachment warning in the delivery status.
        $response = wp_remote_post(
            'https://api.telegram.org/bot' . $token . '/sendMessage',
            array(
                'timeout' => 30,
                'body'    => array(
                    'chat_id' => $chat,
                    'text'    => self::truncate( $caption, 4096 ),
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return self::error_result( $response->get_error_message() );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['ok'] ) ) {
            return self::error_result( isset( $data['description'] ) ? $data['description'] : __( 'Telegram rejected the post.', 'persiano-hub' ) );
        }

        $warning = is_array( $media_result ) && ! empty( $media_result['message'] ) ? $media_result['message'] : __( 'The selected media file could not be uploaded directly.', 'persiano-hub' );
        $message_id = isset( $data['result']['message_id'] ) ? (string) $data['result']['message_id'] : '';
        return array(
            'success'     => true,
            'message'     => sprintf( __( 'Telegram text was sent, but the attachment was skipped: %s', 'persiano-hub' ), $warning ),
            'external_id' => $message_id,
        );
    }

    private static function telegram_upload_file( $token, $method, $chat, $field, $file_path, $caption, $extra = array() ) {
        if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_file_create' ) ) {
            return array( 'success' => false, 'message' => __( 'PHP cURL file uploads are unavailable on this server.', 'persiano-hub' ) );
        }

        $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $file_path ) : '';
        if ( ! $mime ) {
            $filetype = wp_check_filetype( basename( $file_path ) );
            $mime = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';
        }

        $payload = array_merge(
            array(
                'chat_id' => $chat,
                $field    => curl_file_create( $file_path, $mime, basename( $file_path ) ),
                'caption' => $caption,
            ),
            $extra
        );

        $ch = curl_init( 'https://api.telegram.org/bot' . $token . '/' . $method );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
        $raw = curl_exec( $ch );
        $error = curl_error( $ch );
        $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( false === $raw ) {
            return array( 'success' => false, 'message' => $error ?: __( 'Telegram file upload failed.', 'persiano-hub' ) );
        }
        $data = json_decode( $raw, true );
        if ( $http_code >= 400 || empty( $data['ok'] ) ) {
            return array( 'success' => false, 'message' => isset( $data['description'] ) ? $data['description'] : sprintf( __( 'Telegram file upload failed with HTTP %d.', 'persiano-hub' ), $http_code ) );
        }
        $message_id = isset( $data['result']['message_id'] ) ? (string) $data['result']['message_id'] : '';
        return array(
            'success'     => true,
            'message'     => __( 'Telegram post sent with the uploaded media file.', 'persiano-hub' ),
            'external_id' => $message_id,
        );
    }

    private static function publish_instagram( $campaign_id ) {
        $connections = self::get_connections();
        $user_id = trim( (string) $connections['instagram_user_id'] );
        $token_result = self::get_instagram_access_token();
        $token = is_wp_error( $token_result ) ? '' : trim( (string) $token_result );
        if ( ! $user_id || ! $token ) return self::error_result( __( 'Instagram is not connected.', 'persiano-hub' ) );
        $permission_check = self::instagram_permission_preflight( $connections, $token );
        if ( is_wp_error( $permission_check ) ) return self::error_result( $permission_check->get_error_message() );

        $caption = self::truncate( self::platform_caption( $campaign_id, 'instagram' ), 2200 );
        $format = sanitize_key( (string) get_post_meta( $campaign_id, self::META_PREFIX . 'instagram_format', true ) ) ?: 'auto';
        $video_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'video_id', true ) );
        $image_id = get_post_thumbnail_id( $campaign_id );
        if ( 'auto' === $format ) $format = $video_id && wp_attachment_is( 'video', $video_id ) ? 'reel' : 'post';

        if ( 'carousel' === $format ) {
            $ids = array_slice( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) get_post_meta( $campaign_id, self::META_PREFIX . 'instagram_carousel_ids', true ) ) ) ), 0, 10 );
            if ( count( $ids ) < 2 ) return self::error_result( __( 'Instagram carousel needs 2–10 Media Library attachment IDs.', 'persiano-hub' ) );
            $children = array();
            foreach ( $ids as $attachment_id ) {
                $url = wp_get_attachment_url( $attachment_id );
                $is_video = wp_attachment_is( 'video', $attachment_id );
                $check = self::validate_public_media_url( $url, $is_video ? 'video' : 'image' );
                if ( is_wp_error( $check ) ) return self::error_result( $check->get_error_message() );
                $payload = array( 'is_carousel_item' => 'true', 'access_token' => $token );
                if ( $is_video ) { $payload['media_type'] = 'VIDEO'; $payload['video_url'] = $url; } else { $payload['image_url'] = $url; }
                $created = self::instagram_request( $connections, '/' . rawurlencode( $user_id ) . '/media', 'POST', $payload );
                if ( ! $created['success'] || empty( $created['data']['id'] ) ) return self::error_result( self::append_media_hint( $created['message'], $url ) );
                $child_id = (string) $created['data']['id'];
                $ready = self::wait_for_instagram_container( $connections, $child_id, $token, $is_video ? 30 : 15 );
                if ( is_wp_error( $ready ) ) return self::error_result( self::append_media_hint( $ready->get_error_message(), $url ) );
                $children[] = $child_id;
            }
            $parent = self::instagram_request( $connections, '/' . rawurlencode( $user_id ) . '/media', 'POST', array( 'media_type'=>'CAROUSEL', 'children'=>implode(',',$children), 'caption'=>$caption, 'access_token'=>$token ) );
            if ( ! $parent['success'] || empty( $parent['data']['id'] ) ) return self::error_result( $parent['message'] );
            $container_id = (string) $parent['data']['id'];
            $ready = self::wait_for_instagram_container( $connections, $container_id, $token, 20 );
            if ( is_wp_error( $ready ) ) return self::error_result( $ready->get_error_message() );
            $success_message = __( 'Instagram carousel published.', 'persiano-hub' );
        } else {
            $use_video = in_array( $format, array( 'reel','story' ), true ) && $video_id && wp_attachment_is( 'video', $video_id );
            $attachment_id = $use_video ? $video_id : $image_id;
            if ( ! $attachment_id ) return self::error_result( __( 'Instagram needs suitable media for the selected format.', 'persiano-hub' ) );
            $url = wp_get_attachment_url( $attachment_id );
            $check = self::validate_public_media_url( $url, $use_video ? 'video' : 'image' );
            if ( is_wp_error( $check ) ) return self::error_result( $check->get_error_message() );
            $payload = array( 'access_token'=>$token );
            if ( 'story' === $format ) {
                $payload['media_type'] = 'STORIES';
                if ( $use_video ) $payload['video_url'] = $url; else $payload['image_url'] = $url;
                $success_message = __( 'Instagram Story published.', 'persiano-hub' );
            } elseif ( 'reel' === $format ) {
                if ( ! $use_video ) return self::error_result( __( 'A Reel requires a selected video.', 'persiano-hub' ) );
                $payload['media_type'] = 'REELS'; $payload['video_url'] = $url; $payload['caption'] = $caption; $payload['share_to_feed'] = 'true';
                $success_message = __( 'Instagram Reel published.', 'persiano-hub' );
            } else {
                $payload['image_url'] = $url; $payload['caption'] = $caption;
                $success_message = __( 'Instagram post published.', 'persiano-hub' );
            }
            $create = self::instagram_request( $connections, '/' . rawurlencode( $user_id ) . '/media', 'POST', $payload );
            if ( ! $create['success'] || empty( $create['data']['id'] ) ) return self::error_result( self::append_media_hint( $create['message'], $url ) );
            $container_id = (string) $create['data']['id'];
            $ready = self::wait_for_instagram_container( $connections, $container_id, $token, $use_video ? 30 : 15 );
            if ( is_wp_error( $ready ) ) return self::error_result( self::append_media_hint( $ready->get_error_message(), $url ) );
        }

        $publish = self::instagram_request( $connections, '/' . rawurlencode( $user_id ) . '/media_publish', 'POST', array( 'creation_id'=>$container_id, 'access_token'=>$token ) );
        if ( ! $publish['success'] || empty( $publish['data']['id'] ) ) {
            $message = (string) ( $publish['message'] ?? '' );
            if ( false !== stripos( $message, '9007' ) || false !== stripos( $message, 'Media ID is not available' ) ) { sleep(5); $publish = self::instagram_request( $connections, '/' . rawurlencode( $user_id ) . '/media_publish', 'POST', array( 'creation_id'=>$container_id, 'access_token'=>$token ) ); }
            if ( ! $publish['success'] || empty( $publish['data']['id'] ) ) return self::error_result( (string) ( $publish['message'] ?? $message ) );
        }
        $media_id = (string) $publish['data']['id']; $permalink = '';
        $lookup = self::instagram_request( $connections, '/' . rawurlencode( $media_id ), 'GET', array( 'fields'=>'permalink', 'access_token'=>$token ) );
        if ( $lookup['success'] && ! empty( $lookup['data']['permalink'] ) ) $permalink = esc_url_raw( $lookup['data']['permalink'] );
        return array( 'success'=>true, 'message'=>$success_message, 'external_id'=>$media_id, 'url'=>$permalink );
    }

    private static function wait_for_instagram_container( $connections, $container_id, $token, $max_checks = 15 ) {
        for ( $i=0; $i<$max_checks; $i++ ) {
            sleep(2);
            $status=self::instagram_request($connections,'/'.rawurlencode($container_id),'GET',array('fields'=>'status_code,status','access_token'=>$token));
            if($status['success']&&isset($status['data']['status_code'])){
                if('FINISHED'===$status['data']['status_code'])return true;
                if(in_array($status['data']['status_code'],array('ERROR','EXPIRED'),true))return new WP_Error('instagram_processing',(string)($status['data']['status']??__('Instagram could not process this media.','persiano-hub')));
            }
        }
        return new WP_Error('instagram_processing_timeout',__('Instagram accepted the media but it is still processing. Retry Instagram after a minute.','persiano-hub'));
    }

    /**
     * Verify that the connected Facebook token still includes publishing access.
     * A failed debugger request is non-blocking, but a confirmed missing scope is
     * surfaced before attempting to create an Instagram media container.
     */
    private static function instagram_permission_preflight( $connections, $token ) {
        if ( empty( $connections['instagram_connection_type'] ) || 'facebook_login' !== $connections['instagram_connection_type'] ) {
            return true;
        }
        if ( empty( $connections['instagram_meta_app_id'] ) || empty( $connections['instagram_meta_app_secret'] ) ) {
            return true;
        }
        $version = trim( (string) $connections['instagram_graph_version'], '/' );
        if ( '' === $version ) {
            $version = 'v25.0';
        }
        $debug_url = add_query_arg(
            array(
                'input_token'  => $token,
                'access_token' => $connections['instagram_meta_app_id'] . '|' . $connections['instagram_meta_app_secret'],
            ),
            'https://graph.facebook.com/' . $version . '/debug_token'
        );
        $response = wp_remote_get( $debug_url, array( 'timeout' => 20 ) );
        if ( is_wp_error( $response ) ) {
            return true;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
            return true;
        }
        if ( isset( $data['data']['is_valid'] ) && ! $data['data']['is_valid'] ) {
            return new WP_Error( 'persiano_instagram_token_invalid', __( 'The Instagram connection token is no longer valid. Reconnect Instagram in Batchly → Connections.', 'persiano-hub' ) );
        }
        $scopes = isset( $data['data']['scopes'] ) && is_array( $data['data']['scopes'] ) ? $data['data']['scopes'] : array();
        if ( $scopes && ! in_array( 'instagram_content_publish', $scopes, true ) ) {
            return new WP_Error( 'persiano_instagram_publish_scope_missing', __( 'Instagram is connected, but Meta did not grant the instagram_content_publish permission to this connection. Add that permission to the Facebook Login for Business configuration, then reconnect Instagram once.', 'persiano-hub' ) );
        }
        return true;
    }

    /**
     * Make sure Meta can fetch the selected media URL from the public site.
     */
    private static function validate_public_media_url( $url, $expected_type ) {
        if ( ! $url || ! wp_http_validate_url( $url ) ) {
            return new WP_Error( 'persiano_instagram_media_url', __( 'The selected Instagram media does not have a valid public URL.', 'persiano-hub' ) );
        }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return new WP_Error( 'persiano_instagram_media_scheme', __( 'Instagram can only fetch media from a public HTTP or HTTPS URL.', 'persiano-hub' ) );
        }

        $response = wp_remote_head( $url, array( 'timeout' => 15, 'redirection' => 5 ) );
        $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        if ( is_wp_error( $response ) || 405 === $code || 403 === $code ) {
            $response = wp_remote_get(
                $url,
                array(
                    'timeout'             => 20,
                    'redirection'         => 5,
                    'limit_response_size' => 2048,
                    'headers'             => array( 'Range' => 'bytes=0-2047' ),
                )
            );
            $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        }
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'persiano_instagram_media_fetch', sprintf( __( 'The Instagram media URL could not be reached from the server: %s', 'persiano-hub' ), $response->get_error_message() ) );
        }
        if ( $code >= 400 || 0 === $code ) {
            return new WP_Error( 'persiano_instagram_media_http', sprintf( __( 'The selected Instagram media URL is not publicly reachable (HTTP %d).', 'persiano-hub' ), $code ) );
        }
        $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( $content_type ) {
            if ( 'image' === $expected_type && 0 !== strpos( $content_type, 'image/' ) ) {
                return new WP_Error( 'persiano_instagram_media_type', sprintf( __( 'The selected Main image URL returns %s instead of an image.', 'persiano-hub' ), $content_type ) );
            }
            if ( 'video' === $expected_type && 0 !== strpos( $content_type, 'video/' ) && false === strpos( $content_type, 'application/octet-stream' ) ) {
                return new WP_Error( 'persiano_instagram_media_type', sprintf( __( 'The selected video URL returns %s instead of a video.', 'persiano-hub' ), $content_type ) );
            }
        }
        return true;
    }

    private static function append_media_hint( $message, $media_url ) {
        if ( ! $media_url ) {
            return $message;
        }
        return rtrim( (string) $message ) . ' ' . sprintf( __( 'Media URL checked: %s', 'persiano-hub' ), $media_url );
    }

    private static function instagram_request( $connections, $path, $method, $params ) {
        $version = trim( (string) $connections['instagram_graph_version'] );
        $host = ( isset( $connections['instagram_connection_type'] ) && 'instagram_login' === $connections['instagram_connection_type'] ) ? 'https://graph.instagram.com/' : 'https://graph.facebook.com/';
        $base = $host . ( $version ? trim( $version, '/' ) . '/' : '' );
        $url  = $base . ltrim( $path, '/' );

        if ( 'GET' === $method ) {
            $url = add_query_arg( $params, $url );
            $response = wp_remote_get( $url, array( 'timeout' => 30 ) );
        } else {
            $response = wp_remote_post( $url, array( 'timeout' => 45, 'body' => $params ) );
        }
        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'message' => $response->get_error_message(), 'data' => array() );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code >= 400 || isset( $data['error'] ) ) {
            $error   = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
            $message = isset( $error['message'] ) ? $error['message'] : __( 'Instagram API request failed.', 'persiano-hub' );
            $details = array();
            if ( $http_code ) {
                $details[] = 'HTTP ' . absint( $http_code );
            }
            if ( isset( $error['code'] ) ) {
                $details[] = 'Meta code ' . sanitize_text_field( (string) $error['code'] );
            }
            if ( isset( $error['error_subcode'] ) ) {
                $details[] = 'subcode ' . sanitize_text_field( (string) $error['error_subcode'] );
            }
            if ( ! empty( $error['type'] ) ) {
                $details[] = sanitize_text_field( (string) $error['type'] );
            }
            if ( $details ) {
                $message .= ' [' . implode( ' · ', $details ) . ']';
            }
            return array( 'success' => false, 'message' => $message, 'data' => is_array( $data ) ? $data : array() );
        }
        return array( 'success' => true, 'message' => '', 'data' => is_array( $data ) ? $data : array() );
    }

    private static function publish_google( $campaign_id ) {
        $connections = self::get_connections();
        $account_id  = self::normalize_google_resource_id( $connections['google_account_id'], 'accounts' );
        $location_id = self::normalize_google_resource_id( $connections['google_location_id'], 'locations' );
        if ( ! $account_id || ! $location_id ) {
            return self::error_result( __( 'Google Business account and location IDs are not configured.', 'persiano-hub' ) );
        }

        $token = self::get_google_access_token();
        if ( is_wp_error( $token ) ) {
            return self::error_result( $token->get_error_message() );
        }

        $type    = get_post_meta( $campaign_id, self::META_PREFIX . 'type', true ) ?: 'update';
        $summary = self::truncate( self::platform_caption( $campaign_id, 'google' ), 1500 );
        $cta_url = self::resolve_cta_url( $campaign_id );
        if ( $cta_url && class_exists( 'Persiano_Hub_Marketing' ) ) {
            $cta_url = Persiano_Hub_Marketing::tracked_url( $cta_url, $campaign_id, 'google_business', 'organic' );
        }
        $body    = array(
            'languageCode' => 'en-CA',
            'summary'      => $summary,
            'topicType'    => 'STANDARD',
        );

        if ( 'event' === $type ) {
            $start = self::parse_local_datetime( get_post_meta( $campaign_id, self::META_PREFIX . 'event_start', true ) );
            $end   = self::parse_local_datetime( get_post_meta( $campaign_id, self::META_PREFIX . 'event_end', true ) );
            if ( $start && $end ) {
                $body['topicType'] = 'EVENT';
                $body['event'] = array(
                    'title'    => get_the_title( $campaign_id ),
                    'schedule' => array(
                        'startDate' => array( 'year' => (int) $start->format( 'Y' ), 'month' => (int) $start->format( 'n' ), 'day' => (int) $start->format( 'j' ) ),
                        'startTime' => array( 'hours' => (int) $start->format( 'G' ), 'minutes' => (int) $start->format( 'i' ) ),
                        'endDate'   => array( 'year' => (int) $end->format( 'Y' ), 'month' => (int) $end->format( 'n' ), 'day' => (int) $end->format( 'j' ) ),
                        'endTime'   => array( 'hours' => (int) $end->format( 'G' ), 'minutes' => (int) $end->format( 'i' ) ),
                    ),
                );
            }
        }

        if ( $cta_url ) {
            $body['callToAction'] = array(
                'actionType' => self::google_action_type( $type ),
                'url'        => $cta_url,
            );
        }

        $google_image_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'google_image_id', true ) );
        $image_id = $google_image_id ?: get_post_thumbnail_id( $campaign_id );
        if ( $image_id ) {
            $body['media'] = array(
                array(
                    'mediaFormat' => 'PHOTO',
                    'sourceUrl'   => wp_get_attachment_url( $image_id ),
                ),
            );
        }

        $url = sprintf(
            'https://mybusiness.googleapis.com/v4/accounts/%1$s/locations/%2$s/localPosts',
            rawurlencode( $account_id ),
            rawurlencode( $location_id )
        );
        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 45,
                'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $body ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return self::error_result( $response->get_error_message() );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( wp_remote_retrieve_response_code( $response ) >= 400 || isset( $data['error'] ) ) {
            $message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Google Business rejected the post.', 'persiano-hub' );
            return self::error_result( $message );
        }

        return array(
            'success'     => true,
            'message'     => __( 'Google Business post published.', 'persiano-hub' ),
            'external_id' => isset( $data['name'] ) ? (string) $data['name'] : '',
            'url'         => isset( $data['searchUrl'] ) ? (string) $data['searchUrl'] : '',
        );
    }

    public static function render_connections_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $c = self::get_connections();
        $google_redirect    = self::google_redirect_uri();
        $instagram_redirect = self::meta_instagram_redirect_uri();
        $google_locations   = ! empty( $c['google_discovered_locations'] ) && is_array( $c['google_discovered_locations'] ) ? $c['google_discovered_locations'] : array();
        $google_connected   = ! empty( $c['google_refresh_token'] ) || ! empty( $c['google_access_token'] );
        $instagram_connected = ! empty( $c['instagram_user_id'] ) && ! empty( $c['instagram_access_token'] );
        ?>
        <div class="wrap ph-publishing-wrap">
            <span class="ph-admin-eyebrow"><?php esc_html_e( 'Batchly', 'persiano-hub' ); ?></span>
            <h1><?php esc_html_e( 'Channel Connections', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'Connect each account once. Batchly stores the connection and uses it for publishing and, for Google, review syncing.', 'persiano-hub' ); ?></p>
            <?php self::render_admin_notice(); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ph-connections-form">
                <input type="hidden" name="action" value="persiano_hub_save_connections">
                <?php wp_nonce_field( 'persiano_hub_save_connections' ); ?>

                <section class="ph-connection-card">
                    <div class="ph-connection-title"><span class="dashicons dashicons-format-chat"></span><div><h2>Telegram</h2><p><?php esc_html_e( 'Publish text, photos or the same video used for Instagram.', 'persiano-hub' ); ?></p></div></div>
                    <div class="ph-campaign-row ph-campaign-row--2">
                        <label><span><?php esc_html_e( 'Bot token', 'persiano-hub' ); ?></span><input type="password" name="telegram_bot_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $c['telegram_bot_token'] ) ? __( 'Configured — leave blank to keep', 'persiano-hub' ) : '' ); ?>"></label>
                        <label><span><?php esc_html_e( 'Channel / chat ID', 'persiano-hub' ); ?></span><input type="text" name="telegram_chat_id" value="<?php echo esc_attr( $c['telegram_chat_id'] ); ?>" placeholder="@persianodish"></label>
                    </div>
                    <?php self::test_connection_button( 'telegram' ); ?>
                </section>

                <section class="ph-connection-card">
                    <div class="ph-connection-title">
                        <span class="dashicons dashicons-instagram"></span>
                        <div>
                            <h2>Instagram <?php if ( $instagram_connected ) : ?><span class="ph-connected-pill"><?php esc_html_e( 'Connected', 'persiano-hub' ); ?></span><?php endif; ?></h2>
                            <p><?php echo $instagram_connected && ! empty( $c['instagram_username'] ) ? esc_html( sprintf( __( 'Connected as @%s through its linked Facebook Page.', 'persiano-hub' ), $c['instagram_username'] ) ) : esc_html__( 'Connect the Instagram professional account through the Facebook Page that already manages it.', 'persiano-hub' ); ?></p>
                        </div>
                    </div>

                    <div class="ph-campaign-row ph-campaign-row--2">
                        <label><span><?php esc_html_e( 'Meta App ID', 'persiano-hub' ); ?></span><input type="text" name="instagram_meta_app_id" value="<?php echo esc_attr( $c['instagram_meta_app_id'] ); ?>" placeholder="<?php esc_attr_e( 'Use the App ID from the main Meta app dashboard', 'persiano-hub' ); ?>"></label>
                        <label><span><?php esc_html_e( 'Meta App Secret', 'persiano-hub' ); ?></span><input type="password" name="instagram_meta_app_secret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $c['instagram_meta_app_secret'] ) ? __( 'Configured — leave blank to keep', 'persiano-hub' ) : '' ); ?>"></label>
                    </div>
                    <div class="ph-campaign-row ph-campaign-row--2">
                        <label><span><?php esc_html_e( 'Facebook Login Configuration ID', 'persiano-hub' ); ?></span><input type="text" name="instagram_meta_config_id" value="<?php echo esc_attr( $c['instagram_meta_config_id'] ); ?>" placeholder="<?php esc_attr_e( 'Required for Facebook Login for Business', 'persiano-hub' ); ?>"><small><?php esc_html_e( 'Copy this from Meta → Facebook Login for Business → Configurations.', 'persiano-hub' ); ?></small></label>
                        <label><span><?php esc_html_e( 'Graph API version', 'persiano-hub' ); ?></span><input type="text" name="instagram_graph_version" value="<?php echo esc_attr( $c['instagram_graph_version'] ); ?>" placeholder="v25.0"></label>
                    </div>

                    <div class="notice notice-info inline" style="margin:16px 0 12px;padding:10px 14px;">
                        <p style="margin:.25em 0;"><strong><?php esc_html_e( 'Meta setup:', 'persiano-hub' ); ?></strong> <?php esc_html_e( 'Add the exact URL below to Facebook Login for Business → Settings → Valid OAuth Redirect URIs. The Connect button now uses your Facebook Login Configuration ID, so permissions are controlled by that Meta configuration instead of being sent as raw OAuth scopes.', 'persiano-hub' ); ?></p>
                        <p style="margin:.4em 0 0;"><code style="word-break:break-all;"><?php echo esc_html( $instagram_redirect ); ?></code></p>
                    </div>

                    <div class="ph-connection-actions">
                        <?php if ( $c['instagram_meta_app_id'] && $c['instagram_meta_app_secret'] && $c['instagram_meta_config_id'] ) : ?>
                            <a class="button button-primary" href="<?php echo esc_url( self::meta_instagram_authorization_url() ); ?>"><?php echo $instagram_connected ? esc_html__( 'Reconnect Instagram with Facebook', 'persiano-hub' ) : esc_html__( 'Connect Instagram with Facebook', 'persiano-hub' ); ?></a>
                        <?php else : ?>
                            <span class="description"><?php esc_html_e( 'Save the Meta App ID, App Secret and Facebook Login Configuration ID first; the Connect button will then appear.', 'persiano-hub' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $instagram_connected ) : self::test_connection_button( 'instagram', false ); endif; ?>
                    </div>

                    <?php if ( $instagram_connected && ! empty( $c['instagram_page_name'] ) ) : ?>
                        <p class="description"><?php echo esc_html( sprintf( __( 'Facebook Page: %s', 'persiano-hub' ), $c['instagram_page_name'] ) ); ?></p>
                    <?php endif; ?>

                    <details class="ph-advanced-settings">
                        <summary><?php esc_html_e( 'Advanced / fallback settings', 'persiano-hub' ); ?></summary>
                        <hr>
                        <p class="description"><?php esc_html_e( 'Manual fallback only. Normally these fields are filled automatically after Facebook login.', 'persiano-hub' ); ?></p>
                        <div class="ph-campaign-row ph-campaign-row--2">
                            <label><span><?php esc_html_e( 'Instagram User ID', 'persiano-hub' ); ?></span><input type="text" name="instagram_user_id" value="<?php echo esc_attr( $c['instagram_user_id'] ); ?>"></label>
                            <label><span><?php esc_html_e( 'Facebook Page access token', 'persiano-hub' ); ?></span><input type="password" name="instagram_access_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $c['instagram_access_token'] ) ? __( 'Configured — leave blank to keep', 'persiano-hub' ) : '' ); ?>"></label>
                        </div>
                    </details>
                </section>

                <section class="ph-connection-card">
                    <div class="ph-connection-title">
                        <span class="dashicons dashicons-google"></span>
                        <div>
                            <h2>Google Business Profile <?php if ( $google_connected ) : ?><span class="ph-connected-pill"><?php esc_html_e( 'Connected', 'persiano-hub' ); ?></span><?php endif; ?></h2>
                            <p><?php esc_html_e( 'One Google connection powers Business Profile posts and Google review syncing for the website.', 'persiano-hub' ); ?></p>
                        </div>
                    </div>
                    <div class="ph-campaign-row ph-campaign-row--2">
                        <label><span><?php esc_html_e( 'Google OAuth Client ID', 'persiano-hub' ); ?></span><input type="text" name="google_client_id" value="<?php echo esc_attr( $c['google_client_id'] ); ?>"></label>
                        <label><span><?php esc_html_e( 'Google OAuth Client Secret', 'persiano-hub' ); ?></span><input type="password" name="google_client_secret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $c['google_client_secret'] ) ? __( 'Configured — leave blank to keep', 'persiano-hub' ) : '' ); ?>"></label>
                    </div>
                    <p class="description"><strong><?php esc_html_e( 'Authorized redirect URI:', 'persiano-hub' ); ?></strong> <code><?php echo esc_html( $google_redirect ); ?></code></p>
                    <div class="ph-connection-actions">
                        <?php if ( $c['google_client_id'] && $c['google_client_secret'] ) : ?>
                            <a class="button button-primary" href="<?php echo esc_url( self::google_authorization_url() ); ?>"><?php echo $google_connected ? esc_html__( 'Reconnect Google', 'persiano-hub' ) : esc_html__( 'Connect Google', 'persiano-hub' ); ?></a>
                        <?php endif; ?>
                        <?php if ( $google_connected ) : ?>
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_refresh_google_resources' ), 'persiano_hub_refresh_google_resources' ) ); ?>"><?php esc_html_e( 'Refresh business locations', 'persiano-hub' ); ?></a>
                        <?php endif; ?>
                    </div>

                    <?php if ( $google_connected ) : ?>
                        <div class="ph-google-location-picker">
                            <label>
                                <span><?php echo esc_html( sprintf( __( '%s Business Profile', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ); ?></span>
                                <select name="google_location_selection">
                                    <option value=""><?php esc_html_e( 'Select a business location', 'persiano-hub' ); ?></option>
                                    <?php foreach ( $google_locations as $location ) :
                                        $account_id  = isset( $location['account_id'] ) ? (string) $location['account_id'] : '';
                                        $location_id = isset( $location['location_id'] ) ? (string) $location['location_id'] : '';
                                        $value       = $account_id . '::' . $location_id;
                                        $selected    = $account_id === self::normalize_google_resource_id( $c['google_account_id'], 'accounts' ) && $location_id === self::normalize_google_resource_id( $c['google_location_id'], 'locations' );
                                        $label       = isset( $location['title'] ) ? $location['title'] : $location_id;
                                        if ( ! empty( $location['address'] ) ) {
                                            $label .= ' — ' . $location['address'];
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <?php if ( $c['google_account_id'] && $c['google_location_id'] ) : ?>
                                <div class="ph-connection-actions">
                                    <?php self::test_connection_button( 'google', false ); ?>
                                    <?php if ( class_exists( 'Persiano_Hub_Google_Reviews' ) ) : ?>
                                        <a class="button" href="<?php echo esc_url( Persiano_Hub_Google_Reviews::sync_button_url() ); ?>"><?php esc_html_e( 'Sync Google Reviews', 'persiano-hub' ); ?></a>
                                        <a class="button-link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=persiano_review' ) ); ?>"><?php esc_html_e( 'Manage imported reviews', 'persiano-hub' ); ?></a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <details class="ph-advanced-settings">
                        <summary><?php esc_html_e( 'Advanced / manual Google IDs', 'persiano-hub' ); ?></summary>
                        <div class="ph-campaign-row ph-campaign-row--2">
                            <label><span><?php esc_html_e( 'Business account ID', 'persiano-hub' ); ?></span><input type="text" name="google_account_id" value="<?php echo esc_attr( $c['google_account_id'] ); ?>"></label>
                            <label><span><?php esc_html_e( 'Location ID', 'persiano-hub' ); ?></span><input type="text" name="google_location_id" value="<?php echo esc_attr( $c['google_location_id'] ); ?>"></label>
                        </div>
                    </details>
                </section>

                <p><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save Connections', 'persiano-hub' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    private static function test_connection_button( $channel, $wrap = true ) {
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_test_connection&channel=' . $channel ), 'persiano_hub_test_connection_' . $channel );
        $html = '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Test connection', 'persiano-hub' ) . '</a>';
        echo $wrap ? '<div class="ph-connection-actions">' . $html . '</div>' : $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function handle_save_connections() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to change these settings.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_save_connections' );

        $old = self::get_connections();
        $new = $old;
        $plain_fields = array( 'telegram_chat_id', 'instagram_meta_app_id', 'instagram_meta_config_id', 'instagram_user_id', 'instagram_graph_version', 'google_client_id', 'google_account_id', 'google_location_id' );
        foreach ( $plain_fields as $field ) {
            $new[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : $new[ $field ];
        }
        foreach ( array( 'telegram_bot_token', 'instagram_meta_app_secret', 'instagram_access_token', 'google_client_secret' ) as $secret ) {
            $incoming = isset( $_POST[ $secret ] ) ? trim( (string) wp_unslash( $_POST[ $secret ] ) ) : '';
            if ( '' !== $incoming ) {
                $new[ $secret ] = sanitize_text_field( $incoming );
            }
        }
        if ( ! empty( $_POST['google_location_selection'] ) ) {
            $selection = sanitize_text_field( wp_unslash( $_POST['google_location_selection'] ) );
            $parts = explode( '::', $selection, 2 );
            if ( 2 === count( $parts ) ) {
                $new['google_account_id']  = $parts[0];
                $new['google_location_id'] = $parts[1];
            }
        }

        update_option( self::OPTION_CONNECTIONS, $new, false );
        self::set_admin_notice( 'success', __( 'Publishing connections saved.', 'persiano-hub' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
        exit;
    }

    public static function handle_test_connection() {
        $channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
        if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( self::channels()[ $channel ] ) ) {
            wp_die( esc_html__( 'You do not have permission to test this connection.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_test_connection_' . $channel );

        $result = self::test_connection( $channel );
        self::set_admin_notice( $result['success'] ? 'success' : 'error', $result['message'] );
        wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
        exit;
    }

    private static function test_connection( $channel ) {
        $c = self::get_connections();
        if ( 'telegram' === $channel ) {
            if ( ! $c['telegram_bot_token'] ) {
                return self::error_result( __( 'Add a Telegram bot token first.', 'persiano-hub' ) );
            }
            $response = wp_remote_get( 'https://api.telegram.org/bot' . $c['telegram_bot_token'] . '/getMe', array( 'timeout' => 20 ) );
            if ( is_wp_error( $response ) ) {
                return self::error_result( $response->get_error_message() );
            }
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            return ! empty( $data['ok'] ) ? array( 'success' => true, 'message' => __( 'Telegram bot connection is working.', 'persiano-hub' ) ) : self::error_result( isset( $data['description'] ) ? $data['description'] : __( 'Telegram connection failed.', 'persiano-hub' ) );
        }
        if ( 'instagram' === $channel ) {
            $token = self::get_instagram_access_token();
            if ( is_wp_error( $token ) ) {
                return self::error_result( $token->get_error_message() );
            }
            if ( ! $c['instagram_user_id'] ) {
                return self::error_result( __( 'Instagram account ID is missing. Reconnect Instagram.', 'persiano-hub' ) );
            }
            $result = self::instagram_request( $c, '/' . rawurlencode( $c['instagram_user_id'] ), 'GET', array( 'fields' => 'id,username', 'access_token' => $token ) );
            return $result['success'] ? array( 'success' => true, 'message' => isset( $result['data']['username'] ) ? sprintf( __( 'Instagram connected as @%s.', 'persiano-hub' ), $result['data']['username'] ) : __( 'Instagram connection is working.', 'persiano-hub' ) ) : self::error_result( $result['message'] );
        }
        if ( 'google' === $channel ) {
            $token = self::get_google_access_token();
            if ( is_wp_error( $token ) ) {
                return self::error_result( $token->get_error_message() );
            }
            if ( ! $c['google_account_id'] || ! $c['google_location_id'] ) {
                return self::error_result( __( 'Select a Google Business Profile location first.', 'persiano-hub' ) );
            }
            $account_id = self::normalize_google_resource_id( $c['google_account_id'], 'accounts' );
            $location_id = self::normalize_google_resource_id( $c['google_location_id'], 'locations' );
            $url = sprintf( 'https://mybusiness.googleapis.com/v4/accounts/%1$s/locations/%2$s/localPosts?pageSize=1', rawurlencode( $account_id ), rawurlencode( $location_id ) );
            $response = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
            if ( is_wp_error( $response ) ) {
                return self::error_result( $response->get_error_message() );
            }
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( wp_remote_retrieve_response_code( $response ) >= 400 || isset( $data['error'] ) ) {
                return self::error_result( isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Google Business connection failed.', 'persiano-hub' ) );
            }
            return array( 'success' => true, 'message' => __( 'Google Business connection is working.', 'persiano-hub' ) );
        }
        return self::error_result( __( 'Unknown channel.', 'persiano-hub' ) );
    }

    public static function handle_instagram_oauth_callback() {
        if ( ! is_admin() || empty( $_GET['page'] ) || 'persiano-hub-connections' !== $_GET['page'] || empty( $_GET['persiano_instagram_oauth'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $state, 'persiano_hub_instagram_oauth' ) ) {
            self::set_admin_notice( 'error', __( 'Instagram connection could not be verified. Please try again.', 'persiano-hub' ) );
            return;
        }
        if ( ! empty( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            self::set_admin_notice( 'error', sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $code ) {
            self::set_admin_notice( 'error', __( 'Google did not return an authorization code. Please try connecting again.', 'persiano-hub' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }
        $c = self::get_connections();
        if ( empty( $c['instagram_app_id'] ) || empty( $c['instagram_app_secret'] ) ) {
            self::set_admin_notice( 'error', __( 'Save your Instagram App ID and App Secret before connecting.', 'persiano-hub' ) );
            return;
        }
        $response = wp_remote_post(
            'https://api.instagram.com/oauth/access_token',
            array(
                'timeout' => 30,
                'body'    => array(
                    'client_id'     => $c['instagram_app_id'],
                    'client_secret' => $c['instagram_app_secret'],
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => self::instagram_redirect_uri(),
                    'code'          => $code,
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            self::set_admin_notice( 'error', $response->get_error_message() );
            return;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            self::set_admin_notice( 'error', isset( $data['error_message'] ) ? $data['error_message'] : __( 'Instagram did not return an access token.', 'persiano-hub' ) );
            return;
        }
        $short_token = sanitize_text_field( $data['access_token'] );
        $long_url = add_query_arg(
            array(
                'grant_type'    => 'ig_exchange_token',
                'client_secret' => $c['instagram_app_secret'],
                'access_token'  => $short_token,
            ),
            'https://graph.instagram.com/access_token'
        );
        $long_response = wp_remote_get( $long_url, array( 'timeout' => 30 ) );
        $long_data = is_wp_error( $long_response ) ? array() : json_decode( wp_remote_retrieve_body( $long_response ), true );
        $token = ! empty( $long_data['access_token'] ) ? sanitize_text_field( $long_data['access_token'] ) : $short_token;
        $expires = ! empty( $long_data['expires_in'] ) ? absint( $long_data['expires_in'] ) : 3600;
        $user_id = ! empty( $data['user_id'] ) ? sanitize_text_field( $data['user_id'] ) : '';
        $profile_url = add_query_arg(
            array(
                'fields'       => 'id,user_id,username,account_type',
                'access_token' => $token,
            ),
            'https://graph.instagram.com/' . ( $c['instagram_graph_version'] ? trim( $c['instagram_graph_version'], '/' ) . '/' : '' ) . 'me'
        );
        $profile_response = wp_remote_get( $profile_url, array( 'timeout' => 30 ) );
        $profile = is_wp_error( $profile_response ) ? array() : json_decode( wp_remote_retrieve_body( $profile_response ), true );
        if ( ! empty( $profile['id'] ) ) {
            $user_id = sanitize_text_field( $profile['id'] );
        } elseif ( ! empty( $profile['user_id'] ) ) {
            $user_id = sanitize_text_field( $profile['user_id'] );
        }
        if ( ! $user_id ) {
            self::set_admin_notice( 'error', __( 'Instagram connected, but Batchly could not determine the professional account ID.', 'persiano-hub' ) );
            return;
        }
        $c['instagram_user_id']          = $user_id;
        $c['instagram_access_token']     = $token;
        $c['instagram_access_expires']   = time() + max( 300, $expires );
        $c['instagram_username']         = ! empty( $profile['username'] ) ? sanitize_text_field( $profile['username'] ) : '';
        $c['instagram_connection_type']  = 'instagram_login';
        update_option( self::OPTION_CONNECTIONS, $c, false );
        self::set_admin_notice( 'success', $c['instagram_username'] ? sprintf( __( 'Instagram connected as @%s.', 'persiano-hub' ), $c['instagram_username'] ) : __( 'Instagram account connected.', 'persiano-hub' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
        exit;
    }

    /**
     * Handle Instagram connection through Facebook Login for Business.
     *
     * The OAuth callback returns a Facebook user access token. We exchange it
     * for a long-lived token when possible, discover Pages the user manages,
     * and store the Page access token for the Page linked to the Instagram
     * professional account. Instagram Graph publishing then uses the existing
     * graph.facebook.com code path.
     */
    public static function handle_meta_instagram_oauth_callback() {
        $is_admin_post_callback = ! empty( $_GET['action'] ) && 'persiano_hub_meta_instagram_oauth' === sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_legacy_callback     = ! empty( $_GET['page'] ) && 'persiano-hub-connections' === sanitize_key( wp_unslash( $_GET['page'] ) ) && ! empty( $_GET['persiano_meta_instagram_oauth'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! is_admin() || ( ! $is_admin_post_callback && ! $is_legacy_callback ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to connect Instagram.', 'persiano-hub' ) );
        }

        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $state, 'persiano_hub_meta_instagram_oauth' ) ) {
            self::set_admin_notice( 'error', __( 'Instagram connection could not be verified. Please start the connection again from Batchly.', 'persiano-hub' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }

        if ( ! empty( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $message = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : sanitize_text_field( wp_unslash( $_GET['error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            self::set_admin_notice( 'error', $message );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }

        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $code ) {
            self::set_admin_notice( 'error', __( 'Meta did not return an authorization code.', 'persiano-hub' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }

        $c = self::get_connections();
        if ( empty( $c['instagram_meta_app_id'] ) || empty( $c['instagram_meta_app_secret'] ) ) {
            self::set_admin_notice( 'error', __( 'Save the Meta App ID and App Secret before connecting Instagram.', 'persiano-hub' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }

        $version = trim( (string) $c['instagram_graph_version'], '/' );
        if ( '' === $version ) {
            $version = 'v25.0';
        }

        // Exchange the authorization code for a short-lived Facebook user token.
        $token_url = add_query_arg(
            array(
                'client_id'     => $c['instagram_meta_app_id'],
                'client_secret' => $c['instagram_meta_app_secret'],
                'redirect_uri'  => self::meta_instagram_redirect_uri(),
                'code'          => $code,
            ),
            'https://graph.facebook.com/' . $version . '/oauth/access_token'
        );
        $response = wp_remote_get( $token_url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) {
            self::set_admin_notice( 'error', $response->get_error_message() );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            $message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Meta did not return an access token.', 'persiano-hub' );
            self::set_admin_notice( 'error', $message );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }
        $user_token = sanitize_text_field( $data['access_token'] );
        $expires    = ! empty( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600;

        // Exchange for a long-lived user token when Meta allows it. A Page
        // access token generated from this token is then used by Instagram.
        $long_url = add_query_arg(
            array(
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $c['instagram_meta_app_id'],
                'client_secret'     => $c['instagram_meta_app_secret'],
                'fb_exchange_token' => $user_token,
            ),
            'https://graph.facebook.com/' . $version . '/oauth/access_token'
        );
        $long_response = wp_remote_get( $long_url, array( 'timeout' => 30 ) );
        if ( ! is_wp_error( $long_response ) ) {
            $long_data = json_decode( wp_remote_retrieve_body( $long_response ), true );
            if ( ! empty( $long_data['access_token'] ) ) {
                $user_token = sanitize_text_field( $long_data['access_token'] );
                $expires    = ! empty( $long_data['expires_in'] ) ? absint( $long_data['expires_in'] ) : $expires;
            }
        }

        // Official Instagram/Facebook flow: use the user token to retrieve the
        // Pages it manages, including each Page access token and linked IG ID.
        $pages_url = add_query_arg(
            array(
                'fields'       => 'id,name,access_token,tasks,instagram_business_account',
                'limit'        => 100,
                'access_token' => $user_token,
            ),
            'https://graph.facebook.com/' . $version . '/me/accounts'
        );
        $pages_response = wp_remote_get( $pages_url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $pages_response ) ) {
            self::set_admin_notice( 'error', $pages_response->get_error_message() );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }
        $pages_data = json_decode( wp_remote_retrieve_body( $pages_response ), true );
        if ( isset( $pages_data['error'] ) ) {
            self::set_admin_notice( 'error', isset( $pages_data['error']['message'] ) ? $pages_data['error']['message'] : __( 'Meta could not list your Facebook Pages.', 'persiano-hub' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }

        $candidates = array();
        foreach ( (array) ( isset( $pages_data['data'] ) ? $pages_data['data'] : array() ) as $page ) {
            if ( empty( $page['access_token'] ) || empty( $page['instagram_business_account']['id'] ) ) {
                continue;
            }
            $page_token = sanitize_text_field( $page['access_token'] );
            $ig_user_id = sanitize_text_field( $page['instagram_business_account']['id'] );
            $ig_username = '';
            $profile_url = add_query_arg(
                array(
                    'fields'       => 'id,username',
                    'access_token' => $page_token,
                ),
                'https://graph.facebook.com/' . $version . '/' . rawurlencode( $ig_user_id )
            );
            $profile_response = wp_remote_get( $profile_url, array( 'timeout' => 20 ) );
            if ( ! is_wp_error( $profile_response ) ) {
                $profile_data = json_decode( wp_remote_retrieve_body( $profile_response ), true );
                if ( ! empty( $profile_data['username'] ) ) {
                    $ig_username = sanitize_text_field( $profile_data['username'] );
                }
            }
            $candidate = array(
                'page_id'      => isset( $page['id'] ) ? sanitize_text_field( $page['id'] ) : '',
                'page_name'    => isset( $page['name'] ) ? sanitize_text_field( $page['name'] ) : '',
                'page_token'   => $page_token,
                'ig_user_id'   => $ig_user_id,
                'ig_username'  => $ig_username,
            );
            $candidates[] = $candidate;
        }

        if ( ! $candidates ) {
            self::set_admin_notice( 'error', __( 'Meta login worked, but no Facebook Page with a linked professional Instagram account was returned. Confirm that the business Facebook Page is linked to the intended professional Instagram account and that your Facebook account can manage that Page.', 'persiano-hub' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }

        // Prefer the existing account, then a clearly named Persiano account,
        // otherwise use the only/first eligible linked account.
        $selected = null;
        foreach ( $candidates as $candidate ) {
            if ( ! empty( $c['instagram_user_id'] ) && $candidate['ig_user_id'] === $c['instagram_user_id'] ) {
                $selected = $candidate;
                break;
            }
        }
        if ( ! $selected ) {
            foreach ( $candidates as $candidate ) {
                $haystack = strtolower( $candidate['page_name'] . ' ' . $candidate['ig_username'] );
                if ( false !== strpos( $haystack, 'persiano' ) ) {
                    $selected = $candidate;
                    break;
                }
            }
        }
        if ( ! $selected ) {
            $selected = $candidates[0];
        }

        $c['instagram_user_id']            = $selected['ig_user_id'];
        $c['instagram_username']           = $selected['ig_username'];
        $c['instagram_access_token']       = $selected['page_token'];
        $c['instagram_access_expires']     = time() + max( 300, $expires );
        $c['instagram_connection_type']    = 'facebook_login';
        $c['instagram_page_id']            = $selected['page_id'];
        $c['instagram_page_name']          = $selected['page_name'];
        $c['instagram_discovered_accounts'] = array_map(
            static function( $candidate ) {
                return array(
                    'page_id'     => $candidate['page_id'],
                    'page_name'   => $candidate['page_name'],
                    'ig_user_id'  => $candidate['ig_user_id'],
                    'ig_username' => $candidate['ig_username'],
                );
            },
            $candidates
        );
        update_option( self::OPTION_CONNECTIONS, $c, false );

        $label = $selected['ig_username'] ? '@' . $selected['ig_username'] : $selected['page_name'];
        self::set_admin_notice( 'success', sprintf( __( 'Instagram connected successfully as %s through Facebook.', 'persiano-hub' ), $label ) );
        wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
        exit;
    }

    public static function handle_google_oauth_callback() {
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_dedicated_callback = 'persiano_hub_google_oauth' === $action;
        $is_legacy_callback    = is_admin()
            && ! empty( $_GET['page'] )
            && 'persiano-hub-connections' === $_GET['page']
            && ! empty( $_GET['persiano_google_oauth'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $is_dedicated_callback && ! $is_legacy_callback ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to connect Google.', 'persiano-hub' ), 403 );
        }
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $state, 'persiano_hub_google_oauth' ) ) {
            self::set_admin_notice( 'error', __( 'Google connection could not be verified. Please try again.', 'persiano-hub' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }
        if ( ! empty( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $error_message = sanitize_text_field( wp_unslash( $_GET['error'] ) );
            if ( ! empty( $_GET['error_description'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $error_message .= ': ' . sanitize_text_field( wp_unslash( $_GET['error_description'] ) );
            }
            self::set_admin_notice( 'error', $error_message );
            wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
            exit;
        }
        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $code ) {
            return;
        }

        $c = self::get_connections();
        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 30,
                'body' => array(
                    'code'          => $code,
                    'client_id'     => $c['google_client_id'],
                    'client_secret' => $c['google_client_secret'],
                    'redirect_uri'  => self::google_redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            self::set_admin_notice( 'error', $response->get_error_message() );
            return;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            self::set_admin_notice( 'error', isset( $data['error_description'] ) ? $data['error_description'] : __( 'Google did not return an access token.', 'persiano-hub' ) );
            return;
        }

        $c['google_access_token'] = sanitize_text_field( $data['access_token'] );
        $c['google_access_expires'] = time() + max( 60, absint( isset( $data['expires_in'] ) ? $data['expires_in'] : 3600 ) );
        if ( ! empty( $data['refresh_token'] ) ) {
            $c['google_refresh_token'] = sanitize_text_field( $data['refresh_token'] );
        }
        update_option( self::OPTION_CONNECTIONS, $c, false );
        $discovery = self::discover_google_resources( $c['google_access_token'] );
        if ( $discovery['success'] ) {
            self::set_admin_notice( 'success', $discovery['message'] );
        } else {
            self::set_admin_notice( 'warning', sprintf( __( 'Google connected, but Batchly could not load the business locations yet: %s', 'persiano-hub' ), $discovery['message'] ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
        exit;
    }

    public static function handle_refresh_google_resources() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to refresh Google locations.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_refresh_google_resources' );
        $token = self::get_google_access_token();
        if ( is_wp_error( $token ) ) {
            self::set_admin_notice( 'error', $token->get_error_message() );
        } else {
            $result = self::discover_google_resources( $token );
            self::set_admin_notice( $result['success'] ? 'success' : 'error', $result['message'] );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=persiano-hub-connections' ) );
        exit;
    }

    private static function discover_google_resources( $token ) {
        $headers = array( 'Authorization' => 'Bearer ' . $token );
        $response = wp_remote_get( 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20', array( 'timeout' => 30, 'headers' => $headers ) );
        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'message' => $response->get_error_message() );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( wp_remote_retrieve_response_code( $response ) >= 400 || isset( $data['error'] ) ) {
            return array( 'success' => false, 'message' => isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Google account discovery failed.', 'persiano-hub' ) );
        }
        $accounts  = array();
        $locations = array();
        foreach ( isset( $data['accounts'] ) && is_array( $data['accounts'] ) ? $data['accounts'] : array() as $account ) {
            if ( empty( $account['name'] ) ) {
                continue;
            }
            $account_id = self::normalize_google_resource_id( $account['name'], 'accounts' );
            $accounts[] = array(
                'id'    => $account_id,
                'name'  => sanitize_text_field( $account['name'] ),
                'label' => ! empty( $account['accountName'] ) ? sanitize_text_field( $account['accountName'] ) : $account_id,
            );
            $url = 'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/' . rawurlencode( $account_id ) . '/locations';
            $url = add_query_arg(
                array(
                    'pageSize' => 100,
                    'readMask' => 'name,title,storefrontAddress',
                ),
                $url
            );
            $location_response = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => $headers ) );
            if ( is_wp_error( $location_response ) ) {
                continue;
            }
            $location_data = json_decode( wp_remote_retrieve_body( $location_response ), true );
            if ( wp_remote_retrieve_response_code( $location_response ) >= 400 || isset( $location_data['error'] ) ) {
                continue;
            }
            foreach ( isset( $location_data['locations'] ) && is_array( $location_data['locations'] ) ? $location_data['locations'] : array() as $location ) {
                if ( empty( $location['name'] ) ) {
                    continue;
                }
                $address = '';
                if ( ! empty( $location['storefrontAddress']['addressLines'] ) && is_array( $location['storefrontAddress']['addressLines'] ) ) {
                    $address = implode( ', ', array_map( 'sanitize_text_field', $location['storefrontAddress']['addressLines'] ) );
                }
                if ( ! empty( $location['storefrontAddress']['locality'] ) ) {
                    $address .= ( $address ? ', ' : '' ) . sanitize_text_field( $location['storefrontAddress']['locality'] );
                }
                $locations[] = array(
                    'account_id'  => $account_id,
                    'location_id' => self::normalize_google_resource_id( $location['name'], 'locations' ),
                    'title'       => ! empty( $location['title'] ) ? sanitize_text_field( $location['title'] ) : __( 'Google Business location', 'persiano-hub' ),
                    'address'     => $address,
                );
            }
        }
        $c = self::get_connections();
        $c['google_discovered_accounts']  = $accounts;
        $c['google_discovered_locations'] = $locations;
        if ( 1 === count( $locations ) ) {
            $c['google_account_id']  = $locations[0]['account_id'];
            $c['google_location_id'] = $locations[0]['location_id'];
        }
        update_option( self::OPTION_CONNECTIONS, $c, false );
        if ( ! $locations ) {
            return array( 'success' => false, 'message' => __( 'Google connected, but no Business Profile locations were returned. Make sure the Business Profile APIs are enabled and the connected Google account manages the location.', 'persiano-hub' ) );
        }
        return array(
            'success' => true,
            'message' => 1 === count( $locations ) ? __( 'Google connected and the business location was selected automatically.', 'persiano-hub' ) : sprintf( __( 'Google connected. %d business locations were found; select the correct business location and save.', 'persiano-hub' ), count( $locations ) ),
        );
    }

    private static function get_instagram_access_token() {
        $c = self::get_connections();
        if ( empty( $c['instagram_access_token'] ) ) {
            return new WP_Error( 'persiano_instagram_not_connected', __( 'Instagram is not connected yet.', 'persiano-hub' ) );
        }
        if ( 'instagram_login' !== $c['instagram_connection_type'] || empty( $c['instagram_access_expires'] ) || (int) $c['instagram_access_expires'] > time() + DAY_IN_SECONDS * 7 ) {
            return $c['instagram_access_token'];
        }
        $url = add_query_arg(
            array(
                'grant_type'   => 'ig_refresh_token',
                'access_token' => $c['instagram_access_token'],
            ),
            'https://graph.instagram.com/refresh_access_token'
        );
        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) {
            return $c['instagram_access_token'];
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            return $c['instagram_access_token'];
        }
        $c['instagram_access_token']   = sanitize_text_field( $data['access_token'] );
        $c['instagram_access_expires'] = time() + max( 300, absint( isset( $data['expires_in'] ) ? $data['expires_in'] : 5184000 ) );
        update_option( self::OPTION_CONNECTIONS, $c, false );
        return $c['instagram_access_token'];
    }

    private static function get_google_access_token() {
        $c = self::get_connections();
        if ( ! empty( $c['google_access_token'] ) && (int) $c['google_access_expires'] > time() + 60 ) {
            return $c['google_access_token'];
        }
        if ( empty( $c['google_refresh_token'] ) || empty( $c['google_client_id'] ) || empty( $c['google_client_secret'] ) ) {
            return new WP_Error( 'persiano_google_not_connected', __( 'Google Business is not connected yet.', 'persiano-hub' ) );
        }

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 30,
                'body' => array(
                    'client_id'     => $c['google_client_id'],
                    'client_secret' => $c['google_client_secret'],
                    'refresh_token' => $c['google_refresh_token'],
                    'grant_type'    => 'refresh_token',
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            return new WP_Error( 'persiano_google_refresh_failed', isset( $data['error_description'] ) ? $data['error_description'] : __( 'Google access token refresh failed.', 'persiano-hub' ) );
        }
        $c['google_access_token'] = sanitize_text_field( $data['access_token'] );
        $c['google_access_expires'] = time() + max( 60, absint( isset( $data['expires_in'] ) ? $data['expires_in'] : 3600 ) );
        update_option( self::OPTION_CONNECTIONS, $c, false );
        return $c['google_access_token'];
    }

    public static function google_access_token() {
        return self::get_google_access_token();
    }

    public static function set_external_admin_notice( $type, $message ) {
        self::set_admin_notice( $type, $message );
    }

    private static function meta_instagram_authorization_url() {
        $c = self::get_connections();
        $version = trim( (string) $c['instagram_graph_version'], '/' );
        if ( '' === $version ) {
            $version = 'v25.0';
        }

        // Facebook Login for Business uses a Configuration ID. Permissions are
        // defined in that Meta configuration; sending the Instagram/Page scopes
        // directly in the OAuth URL can be rejected as invalid by the Business
        // Login dialog even though they are valid Graph API permissions.
        $args = array(
            'client_id'                      => $c['instagram_meta_app_id'],
            'redirect_uri'                   => self::meta_instagram_redirect_uri(),
            'response_type'                  => 'code',
            'config_id'                      => $c['instagram_meta_config_id'],
            'override_default_response_type' => 'true',
            'state'                          => wp_create_nonce( 'persiano_hub_meta_instagram_oauth' ),
        );

        return add_query_arg( $args, 'https://www.facebook.com/' . $version . '/dialog/oauth' );
    }

    private static function meta_instagram_redirect_uri() {
        // Keep the callback deliberately simple. Meta performs an exact match
        // against Valid OAuth Redirect URIs, and a dedicated admin-post action
        // avoids nested query parameters in the redirect_uri value.
        return admin_url( 'admin-post.php?action=persiano_hub_meta_instagram_oauth' );
    }

    private static function instagram_authorization_url() {
        $c = self::get_connections();
        return add_query_arg(
            array(
                'client_id'     => $c['instagram_app_id'],
                'redirect_uri'  => self::instagram_redirect_uri(),
                'response_type' => 'code',
                'scope'         => 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments',
                'state'         => wp_create_nonce( 'persiano_hub_instagram_oauth' ),
            ),
            'https://www.instagram.com/oauth/authorize'
        );
    }

    private static function instagram_redirect_uri() {
        return admin_url( 'admin.php?page=persiano-hub-connections&persiano_instagram_oauth=1' );
    }

    private static function google_authorization_url() {
        $c = self::get_connections();
        return add_query_arg(
            array(
                'client_id'     => $c['google_client_id'],
                'redirect_uri'  => self::google_redirect_uri(),
                'response_type' => 'code',
                'scope'         => 'https://www.googleapis.com/auth/business.manage',
                'access_type'   => 'offline',
                'prompt'        => 'consent',
                'state'         => wp_create_nonce( 'persiano_hub_google_oauth' ),
            ),
            'https://accounts.google.com/o/oauth2/v2/auth'
        );
    }

    private static function google_redirect_uri() {
        // Google requires an exact redirect URI match. A dedicated admin-post
        // callback keeps the URI stable and avoids nested query parameters.
        return admin_url( 'admin-post.php?action=persiano_hub_google_oauth' );
    }

    public static function campaign_columns( $columns ) {
        return array(
            'cb'                => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
            'title'             => __( 'Content', 'persiano-hub' ),
            'persiano_type'     => __( 'Type', 'persiano-hub' ),
            'persiano_website'  => __( 'Website destination', 'persiano-hub' ),
            'persiano_channels' => __( 'Selected channels', 'persiano-hub' ),
            'persiano_delivery' => __( 'Publishing status', 'persiano-hub' ),
            'date'              => isset( $columns['date'] ) ? $columns['date'] : __( 'Date', 'persiano-hub' ),
        );
    }

    public static function render_campaign_column( $column, $post_id ) {
        if ( 'persiano_type' === $column ) {
            $type = get_post_meta( $post_id, self::META_PREFIX . 'type', true ) ?: 'update';
            echo esc_html( isset( self::content_types()[ $type ] ) ? self::content_types()[ $type ] : ucfirst( $type ) );
            return;
        }

        if ( 'persiano_website' === $column ) {
            $destination = self::get_website_destination( $post_id );
            if ( ! $destination['exists'] ) {
                echo '<span class="ph-muted">' . esc_html( $destination['message'] ) . '</span>';
                return;
            }
            echo '<strong>' . esc_html( $destination['label'] ) . '</strong><br>';
            echo '<span class="ph-mini-status">' . esc_html( $destination['status_label'] ) . '</span>';
            $links = array();
            if ( $destination['edit_url'] ) {
                $links[] = '<a href="' . esc_url( $destination['edit_url'] ) . '">' . esc_html__( 'Edit', 'persiano-hub' ) . '</a>';
            }
            if ( $destination['view_url'] ) {
                $links[] = '<a href="' . esc_url( $destination['view_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'persiano-hub' ) . '</a>';
            }
            if ( $links ) {
                echo '<div class="ph-table-links">' . wp_kses_post( implode( ' · ', $links ) ) . '</div>';
            }
            return;
        }

        if ( 'persiano_channels' === $column ) {
            $channels = self::get_array_meta( $post_id, self::META_PREFIX . 'channels' );
            if ( ! $channels ) {
                echo '<span class="ph-muted">' . esc_html__( 'None', 'persiano-hub' ) . '</span>';
                return;
            }
            echo '<div class="ph-table-channel-chips">';
            foreach ( $channels as $channel ) {
                if ( ! isset( self::channels()[ $channel ] ) ) {
                    continue;
                }
                echo '<span><span class="dashicons ' . esc_attr( self::channel_icon( $channel ) ) . '"></span>' . esc_html( self::channels()[ $channel ] ) . '</span>';
            }
            echo '</div>';
            return;
        }

        if ( 'persiano_delivery' === $column ) {
            echo '<span class="ph-publish-state">' . esc_html( self::format_campaign_state( $post_id ) ) . '</span>';
        }
    }

    public static function campaign_row_actions( $actions, $post ) {
        if ( self::POST_TYPE === $post->post_type ) {
            $destination = self::get_website_destination( $post->ID );
            if ( $destination['exists'] && $destination['edit_url'] ) {
                $actions['persiano_website_destination'] = '<a href="' . esc_url( $destination['edit_url'] ) . '">' . esc_html__( 'Edit website destination', 'persiano-hub' ) . '</a>';
            }
            return $actions;
        }

        if ( 'product' === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
            foreach ( array(
                'intro'        => __( 'Create product campaign', 'persiano-hub' ),
                'availability' => __( 'Make available / campaign', 'persiano-hub' ),
                'promotion'    => __( 'Create promotion', 'persiano-hub' ),
            ) as $mode => $label ) {
                $url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=persiano_hub_create_product_campaign&product_id=' . absint( $post->ID ) . '&mode=' . $mode ),
                    'persiano_hub_create_product_campaign_' . absint( $post->ID ) . '_' . $mode
                );
                $actions[ 'persiano_campaign_' . $mode ] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
            }
        }
        return $actions;
    }

    public static function handle_create_product_campaign() {
        $product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
        $mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'intro';
        if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) || ! function_exists( 'wc_get_product' ) ) {
            wp_die( esc_html__( 'You do not have permission to create a campaign for this product.', 'persiano-hub' ), 403 );
        }
        if ( ! in_array( $mode, array( 'intro', 'availability', 'promotion' ), true ) ) {
            $mode = 'intro';
        }
        check_admin_referer( 'persiano_hub_create_product_campaign_' . $product_id . '_' . $mode );
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_die( esc_html__( 'Product not found.', 'persiano-hub' ) );
        }

        $type = 'availability' === $mode ? 'availability' : ( 'promotion' === $mode ? 'promotion' : ( 'pantry' === get_post_meta( $product_id, Persiano_Hub_Product_Fields::META_CONTENT_TYPE, true ) ? 'pantry' : 'dish' ) );
        $title = $product->get_name();
        if ( 'availability' === $mode ) {
            $title .= ' — ' . __( 'Availability', 'persiano-hub' );
        } elseif ( 'promotion' === $mode ) {
            $title .= ' — ' . __( 'Promotion', 'persiano-hub' );
        }
        $content = $product->get_short_description() ?: $product->get_description();
        $campaign_id = wp_insert_post(
            array(
                'post_type'    => self::POST_TYPE,
                'post_status'  => 'draft',
                'post_title'   => $title,
                'post_content' => $content,
                'post_author'  => get_current_user_id(),
            ),
            true
        );
        if ( is_wp_error( $campaign_id ) ) {
            wp_die( esc_html( $campaign_id->get_error_message() ) );
        }

        $connections = self::get_connections();
        $channels = array( 'website' );
        foreach ( array( 'instagram', 'telegram', 'google' ) as $channel ) {
            if ( self::channel_is_ready( $channel, $connections ) ) {
                $channels[] = $channel;
            }
        }
        update_post_meta( $campaign_id, self::META_PREFIX . 'type', $type );
        update_post_meta( $campaign_id, self::META_PREFIX . 'product_id', $product_id );
        update_post_meta( $campaign_id, self::META_PREFIX . 'channels', $channels );
        if ( $product->get_image_id() ) {
            set_post_thumbnail( $campaign_id, $product->get_image_id() );
        }
        if ( 'availability' === $mode ) {
            update_post_meta( $campaign_id, self::META_PREFIX . 'availability_close', 'yes' );
            update_post_meta( $campaign_id, self::META_PREFIX . 'availability_remove', 'yes' );
        }
        if ( 'promotion' === $mode ) {
            update_post_meta( $campaign_id, self::META_PREFIX . 'promotion_expiry_action', 'message' );
        }

        wp_safe_redirect( get_edit_post_link( $campaign_id, 'url' ) );
        exit;
    }

    private static function get_website_destination( $campaign_id ) {
        $type        = sanitize_key( get_post_meta( $campaign_id, self::META_PREFIX . 'type', true ) ?: 'update' );
        $channels    = self::get_array_meta( $campaign_id, self::META_PREFIX . 'channels' );
        $is_selected = in_array( 'website', $channels, true );

        if ( in_array( $type, array( 'dish', 'pantry', 'availability' ), true ) ) {
            $product_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'product_id', true ) );
            $product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
            if ( ! $product ) {
                return array(
                    'exists'       => false,
                    'kind'         => 'product',
                    'message'      => $is_selected ? __( 'Linked product not available yet', 'persiano-hub' ) : __( 'Website not selected', 'persiano-hub' ),
                    'label'        => '', 'status_label' => '', 'edit_url' => '', 'view_url' => '',
                );
            }
            $status = get_post_status( $product_id );
            $availability_state = 'availability' === $type ? get_post_meta( $campaign_id, self::META_PREFIX . 'availability_state', true ) : '';
            $status_label = 'publish' === $status ? __( 'WooCommerce product · Live', 'persiano-hub' ) : __( 'WooCommerce product · Draft', 'persiano-hub' );
            if ( 'availability' === $type && $availability_state ) {
                $status_label .= ' · ' . ucfirst( $availability_state );
            }
            return array(
                'exists'       => true,
                'kind'         => 'product',
                'message'      => '',
                'label'        => $product->get_name(),
                'status_label' => $status_label,
                'edit_url'     => get_edit_post_link( $product_id, 'url' ),
                'view_url'     => 'publish' === $status ? get_permalink( $product_id ) : '',
            );
        }

        $website_post_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'website_post_id', true ) );
        $website_post    = $website_post_id ? get_post( $website_post_id ) : null;
        if ( ! $website_post || 'trash' === $website_post->post_status ) {
            return array(
                'exists'       => false,
                'kind'         => 'promotion' === $type ? 'promotion' : 'update',
                'message'      => $is_selected ? ( 'promotion' === $type ? __( 'Promotion landing page not published yet', 'persiano-hub' ) : __( 'Website update not published yet', 'persiano-hub' ) ) : __( 'Website not selected', 'persiano-hub' ),
                'label'        => '', 'status_label' => '', 'edit_url' => '', 'view_url' => '',
            );
        }

        $kind = self::PROMOTION_POST_TYPE === $website_post->post_type ? 'promotion' : 'update';
        $status_label = 'promotion' === $kind ? __( 'Promotion page', 'persiano-hub' ) : __( 'Website update', 'persiano-hub' );
        if ( 'promotion' === $kind && 'publish' === $website_post->post_status ) {
            $expiry = (string) get_post_meta( $website_post_id, '_persiano_promotion_expiry', true );
            $expiry_ts = self::local_input_to_timestamp( $expiry );
            $status_label .= $expiry_ts && $expiry_ts <= time() ? ' · ' . __( 'Expired', 'persiano-hub' ) : ' · ' . __( 'Active', 'persiano-hub' );
        } else {
            $status_label .= 'publish' === $website_post->post_status ? ' · ' . __( 'Live', 'persiano-hub' ) : ' · ' . __( 'Draft', 'persiano-hub' );
        }

        return array(
            'exists'       => true,
            'kind'         => $kind,
            'message'      => '',
            'label'        => get_the_title( $website_post_id ),
            'status_label' => $status_label,
            'edit_url'     => get_edit_post_link( $website_post_id, 'url' ),
            'view_url'     => 'publish' === $website_post->post_status ? get_permalink( $website_post_id ) : '',
        );
    }

    private static function format_campaign_state( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }
        if ( 'future' === $post->post_status ) {
            return sprintf( __( 'Scheduled · %s', 'persiano-hub' ), get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ) );
        }
        $overall = get_post_meta( $post_id, self::META_PREFIX . 'overall_status', true );
        if ( 'scheduled' === $overall ) {
            return __( 'Scheduled', 'persiano-hub' );
        }
        if ( 'cancelled' === $overall ) {
            return __( 'Cancelled', 'persiano-hub' );
        }
        if ( 'published' === $overall ) {
            return __( 'Published', 'persiano-hub' );
        }
        if ( 'partial' === $overall ) {
            return __( 'Partially published', 'persiano-hub' );
        }
        if ( 'failed' === $overall ) {
            return __( 'Needs attention', 'persiano-hub' );
        }
        return 'publish' === $post->post_status ? __( 'Ready to publish', 'persiano-hub' ) : __( 'Draft', 'persiano-hub' );
    }


    public static function handle_promotion_expiry_redirect() {
        if ( ! is_singular( self::PROMOTION_POST_TYPE ) ) {
            return;
        }
        $post_id = get_queried_object_id();
        $expiry = get_post_meta( $post_id, '_persiano_promotion_expiry', true );
        $action = get_post_meta( $post_id, '_persiano_promotion_expiry_action', true );
        $expiry_ts = self::local_input_to_timestamp( $expiry );
        if ( ! $expiry_ts || $expiry_ts > time() || 'redirect_product' !== $action ) {
            return;
        }
        $product_id = absint( get_post_meta( $post_id, '_persiano_related_product_id', true ) );
        if ( $product_id && 'publish' === get_post_status( $product_id ) ) {
            wp_safe_redirect( get_permalink( $product_id ), 302 );
            exit;
        }
    }

    public static function ensure_updates_page() {
        // Repair the public Updates and Offers pages whenever the publishing module loads.
        self::ensure_updates_page_ready();
        self::ensure_offers_page_ready();
        self::migrate_legacy_content_types();
        self::migrate_legacy_website_destinations();
        update_option( 'persiano_hub_updates_page_version', PERSIANO_HUB_VERSION );
        update_option( 'persiano_hub_offers_page_version', PERSIANO_HUB_VERSION );
        if ( get_option( 'persiano_hub_public_rewrite_version', '' ) !== PERSIANO_HUB_VERSION ) {
            flush_rewrite_rules( false );
            update_option( 'persiano_hub_public_rewrite_version', PERSIANO_HUB_VERSION );
        }
    }


    /**
     * Normalize publishing types from older releases. General Update becomes
     * Update / Announcement, while the old Special Offer type becomes a true
     * Promotion so it belongs under /offers/. Existing campaign IDs and public
     * destination IDs are preserved.
     */
    private static function migrate_legacy_content_types() {
        if ( get_option( 'persiano_hub_content_type_cleanup_version', '' ) === PERSIANO_HUB_VERSION ) {
            return;
        }

        $campaign_ids = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
            )
        );

        $changed = false;
        foreach ( $campaign_ids as $campaign_id ) {
            $type = sanitize_key( get_post_meta( $campaign_id, self::META_PREFIX . 'type', true ) );
            if ( ! in_array( $type, array( 'general', 'offer' ), true ) ) {
                continue;
            }

            $new_type = 'offer' === $type ? 'promotion' : 'update';
            update_post_meta( $campaign_id, self::META_PREFIX . 'type', $new_type );

            $destination_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'website_post_id', true ) );
            $destination    = $destination_id ? get_post( $destination_id ) : null;
            if ( $destination && 'trash' !== $destination->post_status ) {
                $target_type = 'promotion' === $new_type ? self::PROMOTION_POST_TYPE : self::UPDATE_POST_TYPE;
                if ( $destination->post_type !== $target_type && in_array( $destination->post_type, array( 'post', self::UPDATE_POST_TYPE, self::PROMOTION_POST_TYPE ), true ) ) {
                    wp_update_post(
                        array(
                            'ID'        => $destination_id,
                            'post_type' => $target_type,
                        )
                    );
                }
                update_post_meta( $destination_id, '_persiano_content_type', $new_type );

                $statuses = self::get_array_meta( $campaign_id, self::META_PREFIX . 'statuses' );
                if ( ! empty( $statuses['website'] ) && is_array( $statuses['website'] ) ) {
                    $statuses['website']['url'] = get_permalink( $destination_id );
                    $statuses['website']['url_label'] = 'promotion' === $new_type ? __( 'View promotion page', 'persiano-hub' ) : __( 'View website update', 'persiano-hub' );
                    update_post_meta( $campaign_id, self::META_PREFIX . 'statuses', $statuses );
                }
            }
            $changed = true;
        }

        // Clean up destination metadata even when the original campaign is gone.
        $legacy_updates = get_posts(
            array(
                'post_type'      => array( self::UPDATE_POST_TYPE, self::PROMOTION_POST_TYPE ),
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => '_persiano_content_type',
                        'value'   => array( 'general', 'offer' ),
                        'compare' => 'IN',
                    ),
                ),
            )
        );
        foreach ( $legacy_updates as $destination_id ) {
            $legacy_type = sanitize_key( get_post_meta( $destination_id, '_persiano_content_type', true ) );
            $new_type    = 'offer' === $legacy_type ? 'promotion' : 'update';
            $target_type = 'promotion' === $new_type ? self::PROMOTION_POST_TYPE : self::UPDATE_POST_TYPE;
            if ( get_post_type( $destination_id ) !== $target_type ) {
                wp_update_post( array( 'ID' => $destination_id, 'post_type' => $target_type ) );
            }
            update_post_meta( $destination_id, '_persiano_content_type', $new_type );
            $changed = true;
        }

        if ( $changed ) {
            flush_rewrite_rules( false );
        }
        update_option( 'persiano_hub_content_type_cleanup_version', PERSIANO_HUB_VERSION );
    }


    /**
     * Move website destinations created by older releases out of generic blog
     * posts and into Persiano-owned public post types. IDs are preserved so
     * campaign relationships remain intact while URLs become /updates/ or
     * /offers/ instead of relying on the site's blog permalink rules.
     */
    private static function migrate_legacy_website_destinations() {
        if ( get_option( 'persiano_hub_destination_migration_version', '' ) === PERSIANO_HUB_VERSION ) {
            return;
        }
        $campaign_ids = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => self::META_PREFIX . 'website_post_id',
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );
        $changed = false;
        foreach ( $campaign_ids as $campaign_id ) {
            $type = sanitize_key( get_post_meta( $campaign_id, self::META_PREFIX . 'type', true ) );
            if ( in_array( $type, array( 'dish', 'pantry', 'availability' ), true ) ) {
                continue;
            }
            $destination_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'website_post_id', true ) );
            $destination = $destination_id ? get_post( $destination_id ) : null;
            if ( ! $destination || 'post' !== $destination->post_type ) {
                continue;
            }
            $target_type = in_array( $type, array( 'promotion', 'offer' ), true ) ? self::PROMOTION_POST_TYPE : self::UPDATE_POST_TYPE;
            $slug = $destination->post_name ?: sanitize_title( $destination->post_title );
            if ( ! $slug ) {
                $slug = ( in_array( $type, array( 'promotion', 'offer' ), true ) ? 'offer-' : 'update-' ) . absint( $campaign_id );
            }
            $updated = wp_update_post(
                array(
                    'ID'        => $destination_id,
                    'post_type' => $target_type,
                    'post_name' => $slug,
                ),
                true
            );
            if ( is_wp_error( $updated ) ) {
                continue;
            }
            update_post_meta( $destination_id, '_persiano_campaign_id', $campaign_id );
            update_post_meta( $destination_id, '_persiano_content_type', $type );
            $statuses = self::get_array_meta( $campaign_id, self::META_PREFIX . 'statuses' );
            if ( ! empty( $statuses['website'] ) && is_array( $statuses['website'] ) ) {
                $statuses['website']['url'] = get_permalink( $destination_id );
                $statuses['website']['url_label'] = in_array( $type, array( 'promotion', 'offer' ), true ) ? __( 'View promotion page', 'persiano-hub' ) : __( 'View website update', 'persiano-hub' );
                update_post_meta( $campaign_id, self::META_PREFIX . 'statuses', $statuses );
            }
            $changed = true;
        }
        if ( $changed ) {
            flush_rewrite_rules( false );
        }
        update_option( 'persiano_hub_destination_migration_version', PERSIANO_HUB_VERSION );
    }

    /**
     * Create or repair the public Updates page used by website publishing.
     *
     * @return int Page ID, or 0 on failure.
     */
    private static function ensure_updates_page_ready() {
        $page = get_page_by_path( 'updates', OBJECT, 'page' );
        if ( ! $page ) {
            $result = wp_insert_post(
                array(
                    'post_title'   => __( 'Updates', 'persiano-hub' ),
                    'post_name'    => 'updates',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_content' => '[persiano_updates]',
                ),
                true
            );
            return is_wp_error( $result ) ? 0 : absint( $result );
        }

        $needs_update = 'publish' !== $page->post_status || false === strpos( (string) $page->post_content, '[persiano_updates' );
        if ( $needs_update ) {
            $content = trim( (string) $page->post_content );
            if ( false === strpos( $content, '[persiano_updates' ) ) {
                $content = $content ? $content . "

[persiano_updates]" : '[persiano_updates]';
            }
            wp_update_post(
                array(
                    'ID'           => $page->ID,
                    'post_status'  => 'publish',
                    'post_content' => $content,
                )
            );
        }

        return absint( $page->ID );
    }

    /**
     * Create or repair the public Offers page. Individual promotion landing
     * pages still use /offers/{slug}/ while this page is the active-offers index.
     *
     * @return int Page ID, or 0 on failure.
     */
    private static function ensure_offers_page_ready() {
        $page = get_page_by_path( 'offers', OBJECT, 'page' );
        if ( ! $page ) {
            $result = wp_insert_post(
                array(
                    'post_title'   => __( 'Offers', 'persiano-hub' ),
                    'post_name'    => 'offers',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_excerpt' => sprintf( __( 'Current %s promotions and limited-time offers.', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ),
                    'post_content' => '[persiano_offers]',
                ),
                true
            );
            return is_wp_error( $result ) ? 0 : absint( $result );
        }

        $needs_update = 'publish' !== $page->post_status || false === strpos( (string) $page->post_content, '[persiano_offers' );
        if ( $needs_update ) {
            $content = trim( (string) $page->post_content );
            if ( false === strpos( $content, '[persiano_offers' ) ) {
                $content = $content ? $content . "

[persiano_offers]" : '[persiano_offers]';
            }
            wp_update_post(
                array(
                    'ID'           => $page->ID,
                    'post_status'  => 'publish',
                    'post_content' => $content,
                )
            );
        }

        return absint( $page->ID );
    }

    /**
     * Return active promotion IDs, newest first. Expired offers stay available
     * at their direct URL according to their expiry behavior, but disappear
     * from the active Offers index.
     *
     * @param int $limit Maximum IDs to return.
     * @return int[]
     */
    public static function get_active_promotion_ids( $limit = 12 ) {
        $candidate_ids = get_posts(
            array(
                'post_type'      => self::PROMOTION_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        $active = array();
        foreach ( $candidate_ids as $promotion_id ) {
            $expiry    = (string) get_post_meta( $promotion_id, '_persiano_promotion_expiry', true );
            $expiry_ts = self::local_input_to_timestamp( $expiry );
            if ( $expiry_ts && $expiry_ts <= time() ) {
                continue;
            }
            $active[] = absint( $promotion_id );
            if ( $limit > 0 && count( $active ) >= $limit ) {
                break;
            }
        }
        return $active;
    }

    public static function offers_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'limit' => 12 ), $atts, 'persiano_offers' );
        $ids  = self::get_active_promotion_ids( min( 30, max( 1, absint( $atts['limit'] ) ) ) );

        ob_start();
        ?>
        <section class="ph-offers-grid">
            <?php if ( $ids ) : ?>
                <?php foreach ( $ids as $promotion_id ) : ?>
                    <?php
                    $kicker  = (string) get_post_meta( $promotion_id, '_persiano_promotion_kicker', true );
                    $offer   = (string) get_post_meta( $promotion_id, '_persiano_promotion_offer', true );
                    $urgency = (string) get_post_meta( $promotion_id, '_persiano_promotion_urgency', true );
                    ?>
                    <article class="ph-offer-card">
                        <a class="ph-offer-card__media" href="<?php echo esc_url( get_permalink( $promotion_id ) ); ?>">
                            <?php echo get_the_post_thumbnail( $promotion_id, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </a>
                        <div class="ph-offer-card__body">
                            <span><?php echo esc_html( $kicker ?: __( 'Current offer', 'persiano-hub' ) ); ?></span>
                            <h2><a href="<?php echo esc_url( get_permalink( $promotion_id ) ); ?>"><?php echo esc_html( get_the_title( $promotion_id ) ); ?></a></h2>
                            <?php if ( $offer ) : ?><p class="ph-offer-card__offer"><?php echo esc_html( $offer ); ?></p><?php endif; ?>
                            <?php if ( $urgency ) : ?><p><?php echo esc_html( $urgency ); ?></p><?php endif; ?>
                            <a class="ph-update-card__link" href="<?php echo esc_url( get_permalink( $promotion_id ) ); ?>"><?php esc_html_e( 'View offer', 'persiano-hub' ); ?> →</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="ph-offers-empty">
                    <h2><?php esc_html_e( 'No active offers right now.', 'persiano-hub' ); ?></h2>
                    <p><?php esc_html_e( 'See what is currently cooking on the This Week menu.', 'persiano-hub' ); ?></p>
                    <a class="ph-update-card__link" href="<?php echo esc_url( home_url( '/this-week/' ) ); ?>"><?php esc_html_e( 'Explore This Week', 'persiano-hub' ); ?> →</a>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function updates_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'limit' => 12 ), $atts, 'persiano_updates' );
        $query = new WP_Query(
            array(
                'post_type'      => self::UPDATE_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => min( 30, max( 1, absint( $atts['limit'] ) ) ),
                'meta_query'     => array(
                    array(
                        'key'     => '_persiano_campaign_id',
                        'compare' => 'EXISTS',
                    ),
                ),
            )
        );
        ob_start();
        ?>
        <section class="ph-updates-grid">
            <?php if ( $query->have_posts() ) : ?>
                <?php while ( $query->have_posts() ) : $query->the_post(); ?>
                    <article class="ph-update-card">
                        <a class="ph-update-card__media" href="<?php the_permalink(); ?>"><?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'large' ); } ?></a>
                        <div class="ph-update-card__body">
                            <?php
                            $content_type = sanitize_key( get_post_meta( get_the_ID(), '_persiano_content_type', true ) ?: 'update' );
                            $type_labels  = self::content_types();
                            ?>
                            <span><?php echo esc_html( isset( $type_labels[ $content_type ] ) ? $type_labels[ $content_type ] : __( 'Update', 'persiano-hub' ) ); ?></span>
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <p><?php echo esc_html( wp_trim_words( get_the_excerpt(), 24 ) ); ?></p>
                            <a class="ph-update-card__link" href="<?php the_permalink(); ?>"><?php esc_html_e( 'Read more', 'persiano-hub' ); ?> →</a>
                        </div>
                    </article>
                <?php endwhile; wp_reset_postdata(); ?>
            <?php else : ?>
                <p><?php esc_html_e( 'New Persiano updates are coming soon.', 'persiano-hub' ); ?></p>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }
        $is_campaign = self::POST_TYPE === $screen->post_type;
        $is_hub_page = false !== strpos( (string) $screen->id, 'persiano-hub' );
        if ( ! $is_campaign && ! $is_hub_page ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script( 'media-editor' );
        wp_enqueue_script( 'media-views' );
        wp_enqueue_style( 'persiano-hub-publishing-admin', PERSIANO_HUB_URL . 'assets/css/publishing-admin.css', array(), PERSIANO_HUB_VERSION );
        wp_enqueue_script( 'persiano-hub-publishing-admin', PERSIANO_HUB_URL . 'assets/js/publishing-admin.js', array( 'jquery', 'media-editor', 'media-views' ), PERSIANO_HUB_VERSION, true );
    }

    public static function frontend_assets() {
        if ( is_page( array( 'updates', 'offers' ) ) ) {
            wp_enqueue_style( 'persiano-hub-updates', PERSIANO_HUB_URL . 'assets/css/publishing-frontend.css', array(), PERSIANO_HUB_VERSION );
        }
    }

    private static function content_types() {
        return array(
            'weekly_menu' => __( 'Weekly Menu', 'persiano-hub' ),
            'dish'        => __( 'Product Introduction — Prepared Dish', 'persiano-hub' ),
            'pantry'      => __( 'Product Introduction — Pantry Product', 'persiano-hub' ),
            'availability'=> __( 'Product Availability / This Week', 'persiano-hub' ),
            'promotion'   => __( 'Promotion / Ad Campaign', 'persiano-hub' ),
            'event'       => __( 'Event', 'persiano-hub' ),
            'catering'    => __( 'Catering', 'persiano-hub' ),
            'update'      => __( 'Update / Announcement', 'persiano-hub' ),
        );
    }

    private static function channels() {
        return array(
            'website'   => __( 'Website', 'persiano-hub' ),
            'instagram' => __( 'Instagram', 'persiano-hub' ),
            'telegram'  => __( 'Telegram', 'persiano-hub' ),
            'google'    => __( 'Google Business', 'persiano-hub' ),
            'email'     => __( 'Email List', 'persiano-hub' ),
        );
    }

    private static function channel_is_ready( $channel, $connections = null ) {
        $connections = is_array( $connections ) ? $connections : self::get_connections();
        switch ( $channel ) {
            case 'website':
                return true;
            case 'telegram':
                return ! empty( $connections['telegram_bot_token'] ) && ! empty( $connections['telegram_chat_id'] );
            case 'instagram':
                return ! empty( $connections['instagram_user_id'] ) && ! empty( $connections['instagram_access_token'] );
            case 'google':
                return ! empty( $connections['google_account_id'] ) && ! empty( $connections['google_location_id'] ) && ( ! empty( $connections['google_refresh_token'] ) || ! empty( $connections['google_access_token'] ) );
            case 'email':
                return class_exists( 'Persiano_Hub_Marketing' ) && Persiano_Hub_Marketing::active_subscriber_count() > 0;
        }
        return false;
    }

    private static function channel_icon( $channel ) {
        $icons = array(
            'website'   => 'dashicons-admin-site-alt3',
            'instagram' => 'dashicons-instagram',
            'telegram'  => 'dashicons-format-chat',
            'google'    => 'dashicons-google',
            'email'     => 'dashicons-email-alt',
        );
        return isset( $icons[ $channel ] ) ? $icons[ $channel ] : 'dashicons-share';
    }

    private static function shared_caption( $campaign_id ) {
        $post = get_post( $campaign_id );
        if ( ! $post ) {
            return '';
        }
        $content = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
        return $content ?: $post->post_title;
    }

    private static function platform_caption( $campaign_id, $platform ) {
        $specific = get_post_meta( $campaign_id, self::META_PREFIX . $platform . '_caption', true );
        return $specific ? trim( $specific ) : self::shared_caption( $campaign_id );
    }

    /** Public read-only destination helper for marketing reports and email campaigns. */
    public static function marketing_destination_url( $campaign_id ) {
        return self::resolve_cta_url( absint( $campaign_id ) );
    }

    /** Public read-only CTA label helper for branded email campaigns. */
    public static function marketing_cta_label( $campaign_id ) {
        return self::cta_label( absint( $campaign_id ) );
    }

    private static function resolve_cta_url( $campaign_id, $website_post_id = 0 ) {
        $explicit = get_post_meta( $campaign_id, self::META_PREFIX . 'cta_url', true );
        if ( $explicit ) {
            return $explicit;
        }
        $type = sanitize_key( get_post_meta( $campaign_id, self::META_PREFIX . 'type', true ) );
        if ( 'promotion' === $type ) {
            $promotion_id = $website_post_id ? absint( $website_post_id ) : absint( get_post_meta( $campaign_id, self::META_PREFIX . 'website_post_id', true ) );
            if ( $promotion_id && self::PROMOTION_POST_TYPE === get_post_type( $promotion_id ) && 'publish' === get_post_status( $promotion_id ) ) {
                return get_permalink( $promotion_id );
            }
        }
        $product_id = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'product_id', true ) );
        if ( $product_id && 'publish' === get_post_status( $product_id ) ) {
            return get_permalink( $product_id );
        }
        $map  = array(
            'weekly_menu' => 'this-week',
            'dish'        => 'this-week',
            'pantry'      => 'the-pantry',
            'availability'=> 'this-week',
            'promotion'   => 'this-week',
            'catering'    => 'catering',
            'event'       => 'events',
        );
        if ( isset( $map[ $type ] ) ) {
            $page = get_page_by_path( $map[ $type ] );
            if ( $page ) {
                return get_permalink( $page );
            }
        }
        if ( $website_post_id ) {
            return get_permalink( $website_post_id );
        }
        $linked = absint( get_post_meta( $campaign_id, self::META_PREFIX . 'website_post_id', true ) );
        return $linked ? get_permalink( $linked ) : '';
    }

    private static function cta_label( $campaign_id ) {
        $type = get_post_meta( $campaign_id, self::META_PREFIX . 'type', true );
        $labels = array(
            'weekly_menu' => __( 'Order This Week', 'persiano-hub' ),
            'dish'        => __( 'View & Order', 'persiano-hub' ),
            'pantry'      => __( 'Shop the Pantry', 'persiano-hub' ),
            'event'       => __( 'View Event', 'persiano-hub' ),
            'catering'    => __( 'Explore Catering', 'persiano-hub' ),
            'availability'=> __( 'View & Order', 'persiano-hub' ),
            'promotion'   => __( 'Order Now', 'persiano-hub' ),
            'offer'       => __( 'View Offer', 'persiano-hub' ),
            'update'      => __( 'Learn More', 'persiano-hub' ),
            'general'     => __( 'Learn More', 'persiano-hub' ), // Legacy alias.
        );
        return isset( $labels[ $type ] ) ? $labels[ $type ] : __( 'Learn More', 'persiano-hub' );
    }

    private static function google_action_type( $type ) {
        $map = array(
            'weekly_menu' => 'ORDER',
            'dish'        => 'ORDER',
            'pantry'      => 'SHOP',
            'availability'=> 'ORDER',
            'promotion'   => 'ORDER',
            'offer'       => 'SHOP', // Legacy alias.
            'event'       => 'LEARN_MORE',
            'catering'    => 'LEARN_MORE',
            'update'      => 'LEARN_MORE',
            'general'     => 'LEARN_MORE', // Legacy alias.
        );
        return isset( $map[ $type ] ) ? $map[ $type ] : 'LEARN_MORE';
    }

    private static function normalize_google_resource_id( $value, $prefix ) {
        $value = trim( (string) $value );
        $value = preg_replace( '#^' . preg_quote( $prefix, '#' ) . '/#', '', $value );
        if ( false !== strpos( $value, '/' ) ) {
            $parts = array_values( array_filter( explode( '/', $value ) ) );
            $value = end( $parts );
        }
        return (string) $value;
    }


    private static function get_upcoming_channel_schedules( $limit = 12 ) {
        $campaign_ids = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
                'posts_per_page' => 200,
                'fields'         => 'ids',
                'orderby'        => 'modified',
                'order'          => 'DESC',
            )
        );
        $items = array();
        foreach ( $campaign_ids as $campaign_id ) {
            $schedules = self::get_array_meta( $campaign_id, self::META_PREFIX . 'schedules' );
            foreach ( $schedules as $channel => $entry ) {
                if ( ! is_array( $entry ) || 'scheduled' !== ( isset( $entry['state'] ) ? $entry['state'] : '' ) || empty( $entry['timestamp'] ) || absint( $entry['timestamp'] ) <= time() ) {
                    continue;
                }
                if ( ! isset( self::channels()[ $channel ] ) ) {
                    continue;
                }
                $items[] = array(
                    'post_id'       => absint( $campaign_id ),
                    'title'         => get_the_title( $campaign_id ) ?: __( '(Untitled)', 'persiano-hub' ),
                    'channel'       => $channel,
                    'channel_label' => self::channels()[ $channel ],
                    'timestamp'     => absint( $entry['timestamp'] ),
                );
            }
        }
        usort(
            $items,
            static function( $a, $b ) {
                return $a['timestamp'] <=> $b['timestamp'];
            }
        );
        return array_slice( $items, 0, max( 1, absint( $limit ) ) );
    }

    private static function parse_local_datetime( $value ) {
        if ( ! $value ) {
            return false;
        }
        try {
            return new DateTimeImmutable( $value, wp_timezone() );
        } catch ( Exception $e ) {
            return false;
        }
    }


    private static function local_input_to_timestamp( $value ) {
        $date = self::parse_local_datetime( $value );
        return $date ? $date->getTimestamp() : 0;
    }

    private static function timestamp_to_local_input( $timestamp ) {
        return wp_date( 'Y-m-d\TH:i', absint( $timestamp ), wp_timezone() );
    }

    private static function recalculate_overall_status( $post_id ) {
        $channels  = self::get_array_meta( $post_id, self::META_PREFIX . 'channels' );
        $statuses  = self::get_array_meta( $post_id, self::META_PREFIX . 'statuses' );
        $schedules = self::get_array_meta( $post_id, self::META_PREFIX . 'schedules' );
        $has_success = false;
        $has_failed = false;
        $has_pending = false;
        $has_cancelled = false;

        foreach ( $channels as $channel ) {
            $state = isset( $statuses[ $channel ]['status'] ) ? sanitize_key( $statuses[ $channel ]['status'] ) : '';
            $schedule_state = isset( $schedules[ $channel ]['state'] ) ? sanitize_key( $schedules[ $channel ]['state'] ) : '';
            if ( 'success' === $state ) {
                $has_success = true;
            } elseif ( 'failed' === $state || 'failed' === $schedule_state ) {
                $has_failed = true;
            } elseif ( 'cancelled' === $schedule_state || 'cancelled' === $state ) {
                $has_cancelled = true;
            } elseif ( in_array( $schedule_state, array( 'scheduled', 'paused' ), true ) || in_array( $state, array( 'scheduled', 'paused' ), true ) || '' === $state || 'not_sent' === $state ) {
                $has_pending = true;
            }
        }

        if ( $has_failed && $has_success ) {
            $overall = 'partial';
        } elseif ( $has_failed && ! $has_success ) {
            $overall = 'failed';
        } elseif ( $has_pending && $has_success ) {
            $overall = 'partial';
        } elseif ( $has_cancelled && $has_success ) {
            $overall = 'partial';
        } elseif ( $has_pending ) {
            $overall = 'scheduled';
        } elseif ( $has_success ) {
            $overall = 'published';
        } elseif ( $has_cancelled ) {
            $overall = 'cancelled';
        } else {
            $overall = '';
        }
        update_post_meta( $post_id, self::META_PREFIX . 'overall_status', $overall );
        return $overall;
    }

    /**
     * Read array-valued post meta without turning an empty string into array( '' ).
     * WordPress returns an empty string for missing single-value meta; a direct
     * `(array)` cast makes that value truthy and can break new campaign screens.
     */
    private static function get_array_meta( $post_id, $key ) {
        $value = get_post_meta( $post_id, $key, true );
        return is_array( $value ) ? $value : array();
    }

    private static function truncate( $text, $length ) {
        $text = trim( (string) $text );
        if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
            return mb_substr( $text, 0, $length - 1 ) . '…';
        }
        return strlen( $text ) > $length ? substr( $text, 0, $length - 3 ) . '...' : $text;
    }

    private static function error_result( $message ) {
        return array( 'success' => false, 'message' => (string) $message );
    }

    private static function add_log( $post_id, $channel, $status, $message ) {
        $logs = self::get_array_meta( $post_id, self::META_PREFIX . 'log' );
        $logs[] = array( 'time' => time(), 'channel' => $channel, 'status' => $status, 'message' => $message );
        if ( count( $logs ) > 50 ) {
            $logs = array_slice( $logs, -50 );
        }
        update_post_meta( $post_id, self::META_PREFIX . 'log', $logs );
    }

    private static function status_label( $status ) {
        $labels = array(
            'success'      => __( 'Published', 'persiano-hub' ),
            'failed'       => __( 'Failed', 'persiano-hub' ),
            'scheduled'    => __( 'Scheduled', 'persiano-hub' ),
            'paused'       => __( 'Paused', 'persiano-hub' ),
            'cancelled'    => __( 'Cancelled', 'persiano-hub' ),
            'not_sent'     => __( 'Not sent yet', 'persiano-hub' ),
            'not_selected' => __( 'Not selected', 'persiano-hub' ),
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
    }

    public static function get_connections() {
        $defaults = array(
            'telegram_bot_token'           => '',
            'telegram_chat_id'             => '',
            'instagram_meta_app_id'        => '',
            'instagram_meta_app_secret'    => '',
            'instagram_meta_config_id'     => '',
            'instagram_app_id'             => '',
            'instagram_app_secret'         => '',
            'instagram_user_id'            => '',
            'instagram_username'           => '',
            'instagram_access_token'       => '',
            'instagram_access_expires'     => 0,
            'instagram_connection_type'    => '',
            'instagram_graph_version'      => 'v25.0',
            'instagram_page_id'            => '',
            'instagram_page_name'          => '',
            'instagram_discovered_accounts'=> array(),
            'google_client_id'             => '',
            'google_client_secret'         => '',
            'google_account_id'            => '',
            'google_location_id'           => '',
            'google_access_token'          => '',
            'google_refresh_token'         => '',
            'google_access_expires'        => 0,
            'google_discovered_accounts'   => array(),
            'google_discovered_locations'  => array(),
        );
        return wp_parse_args( (array) get_option( self::OPTION_CONNECTIONS, array() ), $defaults );
    }

    private static function set_admin_notice( $type, $message ) {
        set_transient( 'persiano_hub_admin_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
    }

    private static function render_admin_notice() {
        $key = 'persiano_hub_admin_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! $notice ) {
            return;
        }
        delete_transient( $key );
        $class = 'success' === $notice['type'] ? 'notice-success' : ( 'error' === $notice['type'] ? 'notice-error' : 'notice-warning' );
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
    }
}
