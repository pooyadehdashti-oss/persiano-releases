<?php
/**
 * Google Business Profile review syncing and website display.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Google_Reviews {
    const POST_TYPE   = 'persiano_review';
    const CRON_HOOK   = 'persiano_hub_sync_google_reviews';
    const META_PREFIX = '_ph_review_';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'register_meta_boxes' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_review_settings' ), 10, 2 );
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
        add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
        add_action( 'admin_post_persiano_hub_sync_google_reviews', array( __CLASS__, 'handle_manual_sync' ) );
        add_action( 'admin_post_persiano_hub_toggle_review_featured', array( __CLASS__, 'handle_toggle_featured' ) );
        add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'review_list_toolbar' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_sync' ) );
        add_shortcode( 'persiano_google_reviews', array( __CLASS__, 'shortcode' ) );
        add_action( 'admin_init', array( __CLASS__, 'ensure_schedule' ) );
        add_action( 'admin_init', array( __CLASS__, 'ensure_reviews_page' ), 40 );
    }

    public static function register_post_type() {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name'          => __( 'Google Reviews', 'persiano-hub' ),
                    'singular_name' => __( 'Google Review', 'persiano-hub' ),
                    'edit_item'     => __( 'Review Details', 'persiano-hub' ),
                    'search_items'  => __( 'Search Reviews', 'persiano-hub' ),
                    'not_found'     => __( 'No imported reviews yet.', 'persiano-hub' ),
                ),
                'public'             => false,
                'show_ui'            => true,
                'show_in_menu'       => 'persiano-hub',
                'show_in_rest'       => false,
                'supports'           => array( 'title', 'editor' ),
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'menu_position'       => 9,
                'exclude_from_search' => true,
            )
        );
    }

    public static function register_meta_boxes() {
        add_meta_box(
            'persiano_google_review_settings',
            __( 'Website Display', 'persiano-hub' ),
            array( __CLASS__, 'render_settings_box' ),
            self::POST_TYPE,
            'side',
            'high'
        );
        add_meta_box(
            'persiano_google_review_source',
            __( 'Google Review Data', 'persiano-hub' ),
            array( __CLASS__, 'render_source_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    public static function render_settings_box( $post ) {
        wp_nonce_field( 'persiano_hub_save_review_settings', 'persiano_hub_review_nonce' );
        $featured = (bool) get_post_meta( $post->ID, self::META_PREFIX . 'featured', true );
        $hidden   = (bool) get_post_meta( $post->ID, self::META_PREFIX . 'hidden', true );
        ?>
        <p><label><input type="checkbox" name="persiano_review_featured" value="1" <?php checked( $featured ); ?>> <?php esc_html_e( 'Feature prominently on the website', 'persiano-hub' ); ?></label></p>
        <p><label><input type="checkbox" name="persiano_review_hidden" value="1" <?php checked( $hidden ); ?>> <?php esc_html_e( 'Hide this review from the website', 'persiano-hub' ); ?></label></p>
        <p class="description"><?php esc_html_e( 'The review text and rating are synced from Google and are not edited here.', 'persiano-hub' ); ?></p>
        <?php
    }

    public static function render_source_box( $post ) {
        $rating = absint( get_post_meta( $post->ID, self::META_PREFIX . 'rating', true ) );
        $date   = get_post_meta( $post->ID, self::META_PREFIX . 'update_time', true );
        $name   = get_post_meta( $post->ID, self::META_PREFIX . 'resource_name', true );
        echo '<p><strong>' . esc_html__( 'Rating:', 'persiano-hub' ) . '</strong> ' . esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', max( 0, 5 - $rating ) ) ) . '</p>';
        if ( $date ) {
            echo '<p><strong>' . esc_html__( 'Google updated:', 'persiano-hub' ) . '</strong> ' . esc_html( $date ) . '</p>';
        }
        if ( $name ) {
            echo '<p><strong>' . esc_html__( 'Google resource:', 'persiano-hub' ) . '</strong><br><code>' . esc_html( $name ) . '</code></p>';
        }
    }

    public static function save_review_settings( $post_id, $post ) {
        if ( ! isset( $_POST['persiano_hub_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_hub_review_nonce'] ) ), 'persiano_hub_save_review_settings' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        update_post_meta( $post_id, self::META_PREFIX . 'featured', isset( $_POST['persiano_review_featured'] ) ? 1 : 0 );
        update_post_meta( $post_id, self::META_PREFIX . 'hidden', isset( $_POST['persiano_review_hidden'] ) ? 1 : 0 );
    }

    public static function columns( $columns ) {
        return array(
            'cb'                   => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
            'title'                => __( 'Reviewer', 'persiano-hub' ),
            'persiano_rating'      => __( 'Rating', 'persiano-hub' ),
            'persiano_review_text' => __( 'Review', 'persiano-hub' ),
            'persiano_featured'    => __( 'Website', 'persiano-hub' ),
            'date'                 => __( 'Imported', 'persiano-hub' ),
        );
    }

    public static function column_content( $column, $post_id ) {
        if ( 'persiano_rating' === $column ) {
            $rating = absint( get_post_meta( $post_id, self::META_PREFIX . 'rating', true ) );
            echo '<span style="color:#d79a2d;letter-spacing:.08em">' . esc_html( str_repeat( '★', $rating ) ) . '</span>';
        } elseif ( 'persiano_review_text' === $column ) {
            echo esc_html( wp_trim_words( get_post_field( 'post_content', $post_id ), 24 ) );
        } elseif ( 'persiano_featured' === $column ) {
            if ( get_post_meta( $post_id, self::META_PREFIX . 'hidden', true ) ) {
                esc_html_e( 'Hidden', 'persiano-hub' );
            } elseif ( get_post_meta( $post_id, self::META_PREFIX . 'featured', true ) ) {
                echo '<strong>' . esc_html__( 'Featured', 'persiano-hub' ) . '</strong>';
            } else {
                esc_html_e( 'Visible', 'persiano-hub' );
            }
        }
    }

    public static function row_actions( $actions, $post ) {
        if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }
        $featured = (bool) get_post_meta( $post->ID, self::META_PREFIX . 'featured', true );
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=persiano_hub_toggle_review_featured&review_id=' . $post->ID ),
            'persiano_hub_toggle_review_featured_' . $post->ID
        );
        $actions['persiano_feature_review'] = '<a href="' . esc_url( $url ) . '">' . ( $featured ? esc_html__( 'Unfeature', 'persiano-hub' ) : esc_html__( 'Feature on website', 'persiano-hub' ) ) . '</a>';
        return $actions;
    }


    public static function review_list_toolbar( $which ) {
        global $typenow;
        if ( self::POST_TYPE !== $typenow || 'top' !== $which || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        echo '<div class="alignleft actions"><a class="button button-primary" href="' . esc_url( self::sync_button_url() ) . '">' . esc_html__( 'Sync Google Reviews', 'persiano-hub' ) . '</a></div>';
    }

    public static function handle_toggle_featured() {
        $review_id = isset( $_GET['review_id'] ) ? absint( $_GET['review_id'] ) : 0;
        if ( ! $review_id || ! current_user_can( 'edit_post', $review_id ) ) {
            wp_die( esc_html__( 'You do not have permission to change this review.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_toggle_review_featured_' . $review_id );
        $current = (bool) get_post_meta( $review_id, self::META_PREFIX . 'featured', true );
        update_post_meta( $review_id, self::META_PREFIX . 'featured', $current ? 0 : 1 );
        wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
        exit;
    }

    public static function handle_manual_sync() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to sync reviews.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_sync_google_reviews' );
        $result = self::sync_reviews();
        Persiano_Hub_Publishing::set_external_admin_notice( $result['success'] ? 'success' : 'error', $result['message'] );
        wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
        exit;
    }

    public static function cron_sync() {
        self::sync_reviews();
    }

    public static function ensure_reviews_page() {
        $setup = get_option( 'persiano_hub_reviews_page_version', '0' );
        if ( version_compare( $setup, PERSIANO_HUB_VERSION, '>=' ) ) {
            return;
        }
        $page = get_page_by_path( 'reviews' );
        if ( ! $page ) {
            wp_insert_post(
                array(
                    'post_title'   => __( 'Reviews', 'persiano-hub' ),
                    'post_name'    => 'reviews',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_content' => '[persiano_google_reviews limit="50" featured="no"]',
                )
            );
        } elseif ( '' === trim( (string) $page->post_content ) ) {
            wp_update_post( array( 'ID' => $page->ID, 'post_content' => '[persiano_google_reviews limit="50" featured="no"]' ) );
        }
        update_option( 'persiano_hub_reviews_page_version', PERSIANO_HUB_VERSION );
    }

    public static function ensure_schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function sync_reviews() {
        if ( ! class_exists( 'Persiano_Hub_Publishing' ) ) {
            return array( 'success' => false, 'message' => __( 'Publishing module is unavailable.', 'persiano-hub' ) );
        }
        $connections = Persiano_Hub_Publishing::get_connections();
        $account_id  = self::resource_id( $connections['google_account_id'], 'accounts' );
        $location_id = self::resource_id( $connections['google_location_id'], 'locations' );
        if ( ! $account_id || ! $location_id ) {
            return array( 'success' => false, 'message' => __( 'Connect Google and select a Business Profile location first.', 'persiano-hub' ) );
        }
        $token = Persiano_Hub_Publishing::google_access_token();
        if ( is_wp_error( $token ) ) {
            return array( 'success' => false, 'message' => $token->get_error_message() );
        }

        $page_token = '';
        $imported   = 0;
        $seen       = 0;
        do {
            $url = sprintf(
                'https://mybusiness.googleapis.com/v4/accounts/%1$s/locations/%2$s/reviews?pageSize=50&orderBy=update_time%%20desc',
                rawurlencode( $account_id ),
                rawurlencode( $location_id )
            );
            if ( $page_token ) {
                $url = add_query_arg( 'pageToken', $page_token, $url );
            }
            $response = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
            if ( is_wp_error( $response ) ) {
                return array( 'success' => false, 'message' => $response->get_error_message() );
            }
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( wp_remote_retrieve_response_code( $response ) >= 400 || isset( $data['error'] ) ) {
                return array( 'success' => false, 'message' => isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Google review sync failed.', 'persiano-hub' ) );
            }
            foreach ( isset( $data['reviews'] ) && is_array( $data['reviews'] ) ? $data['reviews'] : array() as $review ) {
                $seen++;
                if ( self::upsert_review( $review ) ) {
                    $imported++;
                }
            }
            $page_token = ! empty( $data['nextPageToken'] ) ? sanitize_text_field( $data['nextPageToken'] ) : '';
        } while ( $page_token && $seen < 500 );

        update_option( 'persiano_hub_google_reviews_last_sync', time(), false );
        return array(
            'success' => true,
            'message' => sprintf( __( 'Google reviews synced. %1$d reviews checked, %2$d added or updated.', 'persiano-hub' ), $seen, $imported ),
        );
    }

    private static function upsert_review( $review ) {
        if ( empty( $review['name'] ) ) {
            return false;
        }
        $existing = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'meta_key'       => self::META_PREFIX . 'resource_name',
                'meta_value'     => sanitize_text_field( $review['name'] ),
                'fields'         => 'ids',
            )
        );
        $reviewer = ! empty( $review['reviewer']['displayName'] ) ? sanitize_text_field( $review['reviewer']['displayName'] ) : __( 'Google customer', 'persiano-hub' );
        $comment  = isset( $review['comment'] ) ? sanitize_textarea_field( $review['comment'] ) : '';
        $rating   = self::rating_number( isset( $review['starRating'] ) ? $review['starRating'] : '' );
        $postarr  = array(
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $reviewer,
            'post_content' => $comment,
        );
        if ( $existing ) {
            $postarr['ID'] = (int) $existing[0];
            $post_id = wp_update_post( $postarr, true );
        } else {
            $post_id = wp_insert_post( $postarr, true );
        }
        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return false;
        }
        update_post_meta( $post_id, self::META_PREFIX . 'resource_name', sanitize_text_field( $review['name'] ) );
        update_post_meta( $post_id, self::META_PREFIX . 'rating', $rating );
        update_post_meta( $post_id, self::META_PREFIX . 'create_time', isset( $review['createTime'] ) ? sanitize_text_field( $review['createTime'] ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'update_time', isset( $review['updateTime'] ) ? sanitize_text_field( $review['updateTime'] ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'review_id', isset( $review['reviewId'] ) ? sanitize_text_field( $review['reviewId'] ) : '' );
        update_post_meta( $post_id, self::META_PREFIX . 'reviewer_photo', isset( $review['reviewer']['profilePhotoUrl'] ) ? esc_url_raw( $review['reviewer']['profilePhotoUrl'] ) : '' );

        if ( ! $existing ) {
            $featured_count = (int) ( new WP_Query(
                array(
                    'post_type'      => self::POST_TYPE,
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'meta_query'     => array(
                        array( 'key' => self::META_PREFIX . 'featured', 'value' => '1' ),
                    ),
                )
            ) )->found_posts;
            if ( $rating >= 4 && $featured_count < 3 ) {
                update_post_meta( $post_id, self::META_PREFIX . 'featured', 1 );
            }
        }
        return true;
    }

    private static function rating_number( $rating ) {
        $map = array( 'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5 );
        if ( is_numeric( $rating ) ) {
            return min( 5, max( 1, absint( $rating ) ) );
        }
        return isset( $map[ strtoupper( (string) $rating ) ] ) ? $map[ strtoupper( (string) $rating ) ] : 5;
    }

    private static function resource_id( $value, $prefix ) {
        $value = trim( (string) $value );
        if ( false !== strpos( $value, '/' ) ) {
            $parts = explode( '/', trim( $value, '/' ) );
            return end( $parts );
        }
        return preg_replace( '/^' . preg_quote( $prefix, '/' ) . '\//', '', $value );
    }

    public static function has_reviews() {
        $query = new WP_Query(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'     => self::META_PREFIX . 'hidden',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            )
        );
        return $query->have_posts();
    }

    public static function get_reviews( $limit = 3, $featured_only = true ) {
        $meta_query = array(
            'relation' => 'AND',
            array(
                'relation' => 'OR',
                array( 'key' => self::META_PREFIX . 'hidden', 'compare' => 'NOT EXISTS' ),
                array( 'key' => self::META_PREFIX . 'hidden', 'value' => '0' ),
            ),
        );
        if ( $featured_only ) {
            $meta_query[] = array( 'key' => self::META_PREFIX . 'featured', 'value' => '1' );
        }
        $query = new WP_Query(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => absint( $limit ),
                'meta_key'       => self::META_PREFIX . 'update_time',
                'orderby'        => 'meta_value',
                'order'          => 'DESC',
                'meta_query'     => $meta_query,
            )
        );
        if ( $featured_only && ! $query->have_posts() ) {
            return self::get_reviews( $limit, false );
        }
        return $query->posts;
    }

    public static function render_reviews( $args = array() ) {
        $args = wp_parse_args( $args, array( 'limit' => 3, 'featured_only' => true ) );
        $reviews = self::get_reviews( absint( $args['limit'] ), (bool) $args['featured_only'] );
        if ( ! $reviews ) {
            return false;
        }
        echo '<div class="pd-reviews">';
        foreach ( $reviews as $review ) {
            $rating = absint( get_post_meta( $review->ID, self::META_PREFIX . 'rating', true ) );
            echo '<article class="pd-review">';
            echo '<div class="pd-stars" aria-label="' . esc_attr( sprintf( __( '%d out of 5 stars', 'persiano-hub' ), $rating ) ) . '">' . esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', max( 0, 5 - $rating ) ) ) . '</div>';
            if ( trim( (string) $review->post_content ) ) {
                echo '<blockquote>' . esc_html( $review->post_content ) . '</blockquote>';
            }
            echo '<cite>' . esc_html( $review->post_title ) . ' · Google</cite>';
            echo '</article>';
        }
        echo '</div>';
        return true;
    }

    public static function shortcode( $atts ) {
        $atts = shortcode_atts( array( 'limit' => 3, 'featured' => 'yes' ), $atts, 'persiano_google_reviews' );
        ob_start();
        self::render_reviews( array( 'limit' => absint( $atts['limit'] ), 'featured_only' => 'no' !== strtolower( (string) $atts['featured'] ) ) );
        return ob_get_clean();
    }

    public static function sync_button_url() {
        return wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_sync_google_reviews' ), 'persiano_hub_sync_google_reviews' );
    }
}
