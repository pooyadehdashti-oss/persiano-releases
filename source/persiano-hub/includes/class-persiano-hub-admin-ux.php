<?php
/**
 * Shared Batchly admin navigation and WooCommerce product bulk tools.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Admin_UX {
    const FILTER_PARAM = 'persiano_section';

    public static function init() {
        add_action( 'edit_form_top', array( __CLASS__, 'render_top_navigation' ) );
        add_action( 'edit_form_after_editor', array( __CLASS__, 'render_bottom_navigation' ) );
        add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );

        add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'product_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_product_bulk_action' ), 20, 3 );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'product_filters' ), 20, 2 );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_product_filters' ) );
        add_action( 'admin_notices', array( __CLASS__, 'bulk_action_notice' ) );
    }

    private static function supported_post_types() {
        $types = array( 'product' );
        if ( class_exists( 'Persiano_Hub_Publishing' ) ) {
            $types[] = Persiano_Hub_Publishing::POST_TYPE;
            $types[] = Persiano_Hub_Publishing::UPDATE_POST_TYPE;
            $types[] = Persiano_Hub_Publishing::PROMOTION_POST_TYPE;
        }
        if ( class_exists( 'Persiano_Hub_Events' ) ) {
            $types[] = Persiano_Hub_Events::POST_TYPE;
        }
        return array_unique( array_filter( $types ) );
    }

    public static function render_top_navigation( $post ) {
        self::render_navigation( $post, 'top' );
    }

    public static function render_bottom_navigation( $post ) {
        self::render_navigation( $post, 'bottom' );
    }

    private static function render_navigation( $post, $position ) {
        if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::supported_post_types(), true ) ) {
            return;
        }
        if ( 'auto-draft' === $post->post_status ) {
            return;
        }

        $ids = get_posts(
            array(
                'post_type'      => $post->post_type,
                'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
        $ids = array_map( 'intval', $ids );
        $index = array_search( (int) $post->ID, $ids, true );
        $prev = false !== $index && $index > 0 ? $ids[ $index - 1 ] : 0;
        $next = false !== $index && $index < count( $ids ) - 1 ? $ids[ $index + 1 ] : 0;
        $list_url = self::list_url_for_type( $post->post_type );

        echo '<div class="ph-global-record-nav ph-global-record-nav--' . esc_attr( $position ) . '">';
        if ( $prev ) {
            echo '<a class="button" href="' . esc_url( get_edit_post_link( $prev ) ) . '">← ' . esc_html__( 'Previous', 'persiano-hub' ) . '</a>';
        } else {
            echo '<span class="button disabled">← ' . esc_html__( 'Previous', 'persiano-hub' ) . '</span>';
        }
        echo '<a class="button button-secondary ph-global-record-nav__list" href="' . esc_url( $list_url ) . '">' . esc_html__( 'Back to list', 'persiano-hub' ) . '</a>';
        if ( $next ) {
            echo '<a class="button" href="' . esc_url( get_edit_post_link( $next ) ) . '">' . esc_html__( 'Next', 'persiano-hub' ) . ' →</a>';
        } else {
            echo '<span class="button disabled">' . esc_html__( 'Next', 'persiano-hub' ) . ' →</span>';
        }
        echo '</div>';
    }

    private static function list_url_for_type( $post_type ) {
        if ( class_exists( 'Persiano_Hub_Publishing' ) && Persiano_Hub_Publishing::POST_TYPE === $post_type ) {
            return admin_url( 'edit.php?post_type=' . Persiano_Hub_Publishing::POST_TYPE );
        }
        return admin_url( 'edit.php?post_type=' . $post_type );
    }

    public static function admin_css() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }
        ?>
        <style>
            .ph-global-record-nav{display:flex;align-items:center;gap:8px;justify-content:flex-end;margin:8px 0 14px;padding:10px 12px;background:#fff;border:1px solid #dcdcde;border-radius:8px;box-sizing:border-box}
            .ph-global-record-nav--bottom{margin-top:18px}
            .ph-global-record-nav .button.disabled{opacity:.45;pointer-events:none}
            .ph-global-record-nav__list{margin-inline:auto}
            .ph-product-filter-note{margin-left:8px;color:#646970}
            @media(max-width:782px){.ph-global-record-nav{flex-wrap:wrap;justify-content:space-between}.ph-global-record-nav__list{order:-1;width:100%;text-align:center;margin:0 0 4px}}
        </style>
        <?php
    }

    public static function product_bulk_actions( $actions ) {
        $actions['persiano_add_this_week']       = __( 'Persiano: Add to This Week', 'persiano-hub' );
        $actions['persiano_remove_this_week']    = __( 'Persiano: Remove from This Week', 'persiano-hub' );
        $actions['persiano_add_pantry']          = __( 'Persiano: Add to Pantry', 'persiano-hub' );
        $actions['persiano_remove_pantry']       = __( 'Persiano: Remove from Pantry', 'persiano-hub' );
        $actions['persiano_type_prepared']       = __( 'Persiano: Set type → Prepared Meal', 'persiano-hub' );
        $actions['persiano_type_pantry']         = __( 'Persiano: Set type → Pantry Product', 'persiano-hub' );
        $actions['persiano_type_other']          = __( 'Persiano: Set type → Other', 'persiano-hub' );
        $actions['persiano_enable_advance']      = __( 'Persiano: Enable advance ordering', 'persiano-hub' );
        $actions['persiano_disable_advance']     = __( 'Persiano: Disable advance ordering', 'persiano-hub' );
        $actions['persiano_publish_products']    = __( 'Persiano: Publish', 'persiano-hub' );
        $actions['persiano_archive_products']    = __( 'Persiano: Archive as draft', 'persiano-hub' );
        return $actions;
    }

    public static function handle_product_bulk_action( $redirect_url, $action, $post_ids ) {
        $supported = array(
            'persiano_add_this_week', 'persiano_remove_this_week',
            'persiano_add_pantry', 'persiano_remove_pantry',
            'persiano_type_prepared', 'persiano_type_pantry', 'persiano_type_other',
            'persiano_enable_advance', 'persiano_disable_advance',
            'persiano_publish_products', 'persiano_archive_products',
        );
        if ( ! in_array( $action, $supported, true ) ) {
            return $redirect_url;
        }

        $changed = 0;
        foreach ( array_map( 'absint', (array) $post_ids ) as $product_id ) {
            if ( ! $product_id || ! current_user_can( 'edit_post', $product_id ) || 'product' !== get_post_type( $product_id ) ) {
                continue;
            }
            switch ( $action ) {
                case 'persiano_add_this_week':
                    update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_THIS_WEEK, 'yes' );
                    self::set_special_category( $product_id, 'this-week', true );
                    break;
                case 'persiano_remove_this_week':
                    update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_THIS_WEEK, 'no' );
                    self::set_special_category( $product_id, 'this-week', false );
                    break;
                case 'persiano_add_pantry':
                    update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_PANTRY, 'yes' );
                    self::set_special_category( $product_id, 'pantry', true );
                    break;
                case 'persiano_remove_pantry':
                    update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SHOW_PANTRY, 'no' );
                    self::set_special_category( $product_id, 'pantry', false );
                    break;
                case 'persiano_type_prepared':
                    update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_CONTENT_TYPE, 'prepared_meal' );
                    break;
                case 'persiano_type_pantry':
                    update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_CONTENT_TYPE, 'pantry' );
                    break;
                case 'persiano_type_other':
                    update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_CONTENT_TYPE, 'other' );
                    break;
                case 'persiano_enable_advance':
                    update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_ALLOW_ADVANCE, 'yes' );
                    break;
                case 'persiano_disable_advance':
                    update_post_meta( $product_id, Persiano_Hub_Product_Fields::META_ALLOW_ADVANCE, 'no' );
                    break;
                case 'persiano_publish_products':
                    wp_update_post( array( 'ID' => $product_id, 'post_status' => 'publish' ) );
                    break;
                case 'persiano_archive_products':
                    wp_update_post( array( 'ID' => $product_id, 'post_status' => 'draft' ) );
                    break;
            }
            clean_post_cache( $product_id );
            $changed++;
        }

        return add_query_arg( array( 'persiano_bulk_updated' => $changed ), $redirect_url );
    }

    private static function set_special_category( $product_id, $slug, $enabled ) {
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            $created = wp_insert_term( ucwords( str_replace( '-', ' ', $slug ) ), 'product_cat', array( 'slug' => $slug ) );
            if ( is_wp_error( $created ) ) {
                return;
            }
            $term_id = (int) $created['term_id'];
        } else {
            $term_id = (int) $term->term_id;
        }
        if ( $enabled ) {
            wp_set_object_terms( $product_id, array( $term_id ), 'product_cat', true );
        } else {
            wp_remove_object_terms( $product_id, array( $term_id ), 'product_cat' );
        }
    }

    public static function product_filters( $post_type, $which = 'top' ) {
        if ( 'product' !== $post_type || 'top' !== $which ) {
            return;
        }
        $selected = isset( $_GET[ self::FILTER_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_PARAM ] ) ) : '';
        $options = array(
            ''              => __( 'All Batchly sections', 'persiano-hub' ),
            'this_week'     => __( 'This Week', 'persiano-hub' ),
            'pantry'        => __( 'Pantry', 'persiano-hub' ),
            'prepared_meal' => __( 'Prepared Meals', 'persiano-hub' ),
            'advance'       => __( 'Advance ordering enabled', 'persiano-hub' ),
            'unclassified'  => __( 'Not classified', 'persiano-hub' ),
        );
        echo '<select name="' . esc_attr( self::FILTER_PARAM ) . '">';
        foreach ( $options as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public static function apply_product_filters( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        global $pagenow;
        if ( 'edit.php' !== $pagenow || 'product' !== $query->get( 'post_type' ) ) {
            return;
        }
        $filter = isset( $_GET[ self::FILTER_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_PARAM ] ) ) : '';
        if ( ! $filter ) {
            return;
        }

        if ( in_array( $filter, array( 'this_week', 'pantry' ), true ) ) {
            $slug = 'this_week' === $filter ? 'this-week' : 'pantry';
            $tax_query = (array) $query->get( 'tax_query' );
            $tax_query[] = array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => array( $slug ) );
            $query->set( 'tax_query', $tax_query );
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );
        if ( 'prepared_meal' === $filter ) {
            $meta_query[] = array( 'key' => Persiano_Hub_Product_Fields::META_CONTENT_TYPE, 'value' => 'prepared_meal' );
        } elseif ( 'advance' === $filter ) {
            $meta_query[] = array( 'key' => Persiano_Hub_Product_Fields::META_ALLOW_ADVANCE, 'value' => 'yes' );
        } elseif ( 'unclassified' === $filter ) {
            $meta_query[] = array(
                'relation' => 'OR',
                array( 'key' => Persiano_Hub_Product_Fields::META_CONTENT_TYPE, 'compare' => 'NOT EXISTS' ),
                array( 'key' => Persiano_Hub_Product_Fields::META_CONTENT_TYPE, 'value' => '' ),
            );
        }
        $query->set( 'meta_query', $meta_query );
    }

    public static function bulk_action_notice() {
        if ( empty( $_GET['persiano_bulk_updated'] ) ) {
            return;
        }
        $count = absint( $_GET['persiano_bulk_updated'] );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d Persiano product updated.', '%d Persiano products updated.', $count, 'persiano-hub' ), $count ) ) . '</p></div>';
    }
}
