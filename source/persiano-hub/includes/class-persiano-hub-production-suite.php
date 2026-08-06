<?php
/**
 * Suppliers, ingredients, recipes and production notes.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Production_Suite {
    const SUPPLIER_POST_TYPE   = 'ph_supplier';
    const INGREDIENT_POST_TYPE = 'ph_ingredient';
    const RECIPE_POST_TYPE     = 'ph_recipe';
    const PAGE_SLUG            = 'persiano-hub-production';
    const SUPPLIER_PAGE        = 'persiano-hub-suppliers';
    const INGREDIENT_PAGE      = 'persiano-hub-ingredients';
    const RECIPE_PAGE          = 'persiano-hub-recipes';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_types' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 42 );
        add_action( 'admin_menu', array( __CLASS__, 'reorder_hub_menu' ), 999 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
        add_action( 'save_post_' . self::SUPPLIER_POST_TYPE, array( __CLASS__, 'save_supplier' ) );
        add_action( 'save_post_' . self::INGREDIENT_POST_TYPE, array( __CLASS__, 'save_ingredient' ) );
        add_action( 'save_post_' . self::RECIPE_POST_TYPE, array( __CLASS__, 'save_recipe' ) );
        add_action( 'admin_post_ph_save_batch_note', array( __CLASS__, 'save_batch_note' ) );
        add_action( 'admin_post_ph_delete_batch_note', array( __CLASS__, 'delete_batch_note' ) );
        add_action( 'admin_post_ph_bulk_save_suppliers', array( __CLASS__, 'bulk_save_suppliers' ) );
        add_action( 'admin_post_ph_bulk_save_ingredients', array( __CLASS__, 'bulk_save_ingredients' ) );
        add_action( 'admin_post_ph_bulk_save_recipes', array( __CLASS__, 'bulk_save_recipes' ) );
        add_action( 'admin_post_ph_export_suppliers', array( __CLASS__, 'export_suppliers' ) );
        add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );
        add_action( 'admin_footer', array( __CLASS__, 'admin_js' ) );
    }

    public static function install() {
        self::register_post_types();
    }

    public static function register_post_types() {
        register_post_type( self::SUPPLIER_POST_TYPE, array(
            'labels' => array( 'name' => __( 'Suppliers', 'persiano-hub' ), 'singular_name' => __( 'Supplier', 'persiano-hub' ), 'add_new_item' => __( 'Add Supplier', 'persiano-hub' ), 'edit_item' => __( 'Edit Supplier', 'persiano-hub' ) ),
            'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => array( 'title' ),
            'capability_type' => 'post', 'map_meta_cap' => true,
        ) );
        register_post_type( self::INGREDIENT_POST_TYPE, array(
            'labels' => array( 'name' => __( 'Ingredients', 'persiano-hub' ), 'singular_name' => __( 'Ingredient', 'persiano-hub' ), 'add_new_item' => __( 'Add Ingredient', 'persiano-hub' ), 'edit_item' => __( 'Edit Ingredient', 'persiano-hub' ) ),
            'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => array( 'title' ),
            'capability_type' => 'post', 'map_meta_cap' => true,
        ) );
        register_post_type( self::RECIPE_POST_TYPE, array(
            'labels' => array( 'name' => __( 'Recipes', 'persiano-hub' ), 'singular_name' => __( 'Recipe', 'persiano-hub' ), 'add_new_item' => __( 'Add Recipe', 'persiano-hub' ), 'edit_item' => __( 'Edit Recipe', 'persiano-hub' ) ),
            'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'supports' => array( 'title', 'editor', 'revisions' ),
            'capability_type' => 'post', 'map_meta_cap' => true,
        ) );
    }

    public static function register_admin_menu() {
        add_submenu_page( 'persiano-hub', __( 'Recipes & Production', 'persiano-hub' ), __( 'Recipes & Production', 'persiano-hub' ), 'manage_woocommerce', self::PAGE_SLUG, array( __CLASS__, 'render_production_dashboard' ) );
        add_submenu_page( 'persiano-hub', __( 'Recipes', 'persiano-hub' ), __( 'Recipes', 'persiano-hub' ), 'manage_woocommerce', self::RECIPE_PAGE, array( __CLASS__, 'render_recipe_bulk' ) );
        add_submenu_page( 'persiano-hub', __( 'Ingredients', 'persiano-hub' ), __( 'Ingredients', 'persiano-hub' ), 'manage_woocommerce', self::INGREDIENT_PAGE, array( __CLASS__, 'render_ingredient_bulk' ) );
        add_submenu_page( 'persiano-hub', __( 'Suppliers', 'persiano-hub' ), __( 'Suppliers', 'persiano-hub' ), 'manage_woocommerce', self::SUPPLIER_PAGE, array( __CLASS__, 'render_supplier_bulk' ) );
    }

    public static function register_meta_boxes() {
        add_meta_box( 'ph_supplier_details', __( 'Supplier details', 'persiano-hub' ), array( __CLASS__, 'render_supplier_meta' ), self::SUPPLIER_POST_TYPE, 'normal', 'high' );
        add_meta_box( 'ph_ingredient_details', __( 'Ingredient profile & conversions', 'persiano-hub' ), array( __CLASS__, 'render_ingredient_meta' ), self::INGREDIENT_POST_TYPE, 'normal', 'high' );
        add_meta_box( 'ph_recipe_details', __( 'Recipe setup', 'persiano-hub' ), array( __CLASS__, 'render_recipe_meta' ), self::RECIPE_POST_TYPE, 'normal', 'high' );
        add_meta_box( 'ph_recipe_ingredients', __( 'Ingredients', 'persiano-hub' ), array( __CLASS__, 'render_recipe_ingredients' ), self::RECIPE_POST_TYPE, 'normal', 'high' );
        add_meta_box( 'ph_recipe_notes', __( 'Notes & batch log', 'persiano-hub' ), array( __CLASS__, 'render_recipe_notes' ), self::RECIPE_POST_TYPE, 'normal', 'default' );
    }

    private static function nonce( $action ) { wp_nonce_field( $action, '_ph_nonce' ); }
    private static function text( $post_id, $key ) { return (string) get_post_meta( $post_id, $key, true ); }
    private static function number( $post_id, $key ) { return wc_format_localized_decimal( get_post_meta( $post_id, $key, true ) ); }

    public static function render_supplier_meta( $post ) {
        self::nonce( 'ph_save_supplier' );
        $fields = array(
            '_ph_contact_person' => 'Contact person', '_ph_phone' => 'Phone', '_ph_mobile' => 'Mobile', '_ph_email' => 'Email', '_ph_website' => 'Website',
            '_ph_address' => 'Address', '_ph_categories' => 'Categories supplied', '_ph_account_number' => 'Account/customer number', '_ph_delivery_days' => 'Delivery days',
            '_ph_minimum_order' => 'Minimum order', '_ph_payment_terms' => 'Payment terms', '_ph_preferred_contact' => 'Preferred contact method',
        );
        echo '<div class="ph-grid ph-grid--2">';
        foreach ( $fields as $key => $label ) {
            echo '<p><label><strong>' . esc_html( $label ) . '</strong><input class="widefat" type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( self::text( $post->ID, $key ) ) . '"></label></p>';
        }
        echo '</div><div class="ph-grid ph-grid--3">';
        self::select_field( '_ph_supplier_status', 'Status', self::text( $post->ID, '_ph_supplier_status' ), array( 'active' => 'Active', 'inactive' => 'Inactive' ) );
        self::select_field( '_ph_supplier_priority', 'Supplier role', self::text( $post->ID, '_ph_supplier_priority' ), array( 'primary' => 'Primary', 'backup' => 'Backup', 'other' => 'Other' ) );
        echo '</div><p><label><strong>Notes</strong><textarea class="widefat" rows="5" name="_ph_supplier_notes">' . esc_textarea( self::text( $post->ID, '_ph_supplier_notes' ) ) . '</textarea></label></p>';
    }

    public static function render_ingredient_meta( $post ) {
        self::nonce( 'ph_save_ingredient' );
        echo '<div class="ph-grid ph-grid--3">';
        self::select_field( '_ph_ingredient_type', 'Ingredient type', self::text( $post->ID, '_ph_ingredient_type' ), self::ingredient_types() );
        self::select_field( '_ph_preferred_unit', 'Preferred unit', self::text( $post->ID, '_ph_preferred_unit' ), self::units() );
        self::select_field( '_ph_include_label', 'Include on customer ingredient label', self::text( $post->ID, '_ph_include_label' ), array( 'yes' => 'Yes', 'no' => 'No' ) );
        echo '</div><div class="ph-grid ph-grid--3">';
        self::input_field( '_ph_grams_per_cup', 'Grams per cup', self::number( $post->ID, '_ph_grams_per_cup' ), 'number', '0.01' );
        self::input_field( '_ph_grams_per_tbsp', 'Grams per tablespoon', self::number( $post->ID, '_ph_grams_per_tbsp' ), 'number', '0.01' );
        self::input_field( '_ph_grams_per_tsp', 'Grams per teaspoon', self::number( $post->ID, '_ph_grams_per_tsp' ), 'number', '0.01' );
        echo '</div><div class="ph-grid ph-grid--3">';
        self::input_field( '_ph_density_g_ml', 'Density (g/mL)', self::number( $post->ID, '_ph_density_g_ml' ), 'number', '0.001' );
        self::input_field( '_ph_cost_per_unit', 'Cost per preferred unit', self::number( $post->ID, '_ph_cost_per_unit' ), 'number', '0.01' );
        self::input_field( '_ph_preparation_state', 'Preparation state', self::text( $post->ID, '_ph_preparation_state' ) );
        echo '</div><div class="ph-grid ph-grid--2">';
        self::select_post_field( '_ph_primary_supplier', 'Primary supplier', self::text( $post->ID, '_ph_primary_supplier' ), self::SUPPLIER_POST_TYPE );
        self::select_post_field( '_ph_backup_supplier', 'Backup supplier', self::text( $post->ID, '_ph_backup_supplier' ), self::SUPPLIER_POST_TYPE );
        echo '</div><p class="description">Weight-to-volume conversion is ingredient-specific. Leave conversion fields blank when unknown; recipes will show “Conversion unavailable” rather than guessing.</p>';
    }

    public static function render_recipe_meta( $post ) {
        self::nonce( 'ph_save_recipe' );
        echo '<div class="ph-grid ph-grid--3">';
        self::input_field( '_ph_recipe_yield', 'Base yield', self::number( $post->ID, '_ph_recipe_yield' ), 'number', '0.01' );
        self::input_field( '_ph_recipe_yield_unit', 'Yield unit', self::text( $post->ID, '_ph_recipe_yield_unit' ) );
        self::input_field( '_ph_recipe_portions', 'Base portions', self::number( $post->ID, '_ph_recipe_portions' ), 'number', '0.01' );
        echo '</div><div class="ph-grid ph-grid--3">';
        self::input_field( '_ph_prep_time', 'Preparation time (minutes)', self::number( $post->ID, '_ph_prep_time' ), 'number', '1' );
        self::input_field( '_ph_cook_time', 'Cooking time (minutes)', self::number( $post->ID, '_ph_cook_time' ), 'number', '1' );
        self::select_post_field( '_ph_linked_product', 'Linked WooCommerce product', self::text( $post->ID, '_ph_linked_product' ), 'product' );
        echo '</div><p><label><strong>Pinned / critical note</strong><textarea class="widefat" rows="3" name="_ph_pinned_note">' . esc_textarea( self::text( $post->ID, '_ph_pinned_note' ) ) . '</textarea></label></p>';
        echo '<div class="ph-grid ph-grid--2">';
        foreach ( array( '_ph_storage_notes' => 'Storage instructions', '_ph_reheating_notes' => 'Reheating instructions', '_ph_packaging_notes' => 'Packaging/plating notes', '_ph_troubleshooting_notes' => 'Troubleshooting / next-time notes' ) as $key => $label ) {
            echo '<p><label><strong>' . esc_html( $label ) . '</strong><textarea class="widefat" rows="4" name="' . esc_attr( $key ) . '">' . esc_textarea( self::text( $post->ID, $key ) ) . '</textarea></label></p>';
        }
        echo '</div>';
    }

    public static function render_recipe_ingredients( $post ) {
        $rows = get_post_meta( $post->ID, '_ph_recipe_ingredients', true );
        $rows = is_array( $rows ) ? $rows : array();
        $ingredients = get_posts( array( 'post_type' => self::INGREDIENT_POST_TYPE, 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
        echo '<p><label><strong>Display units</strong> <select id="ph-recipe-display-mode"><option value="metric">Metric</option><option value="volume">Volume</option><option value="both">Both</option></select></label> &nbsp; <label><strong>Scale recipe</strong> <input id="ph-recipe-scale" type="number" min="0.01" step="0.01" value="1" style="width:90px"></label></p>';
        echo '<table class="widefat striped ph-recipe-table"><thead><tr><th>Ingredient</th><th>Quantity</th><th>Unit</th><th>Type</th><th>Preparation / note</th><th>Converted</th></tr></thead><tbody>';
        $count = max( 12, count( $rows ) + 3 );
        for ( $i = 0; $i < $count; $i++ ) {
            $row = isset( $rows[ $i ] ) && is_array( $rows[ $i ] ) ? $rows[ $i ] : array();
            $ingredient_id = absint( $row['ingredient_id'] ?? 0 );
            echo '<tr class="ph-recipe-row" data-index="' . esc_attr( $i ) . '"><td><select class="widefat ph-ing-select" name="ph_recipe_ingredients[' . esc_attr( $i ) . '][ingredient_id]"><option value="">— Select or leave blank —</option>';
            foreach ( $ingredients as $ingredient ) {
                $type = get_post_meta( $ingredient->ID, '_ph_ingredient_type', true );
                $gpc = get_post_meta( $ingredient->ID, '_ph_grams_per_cup', true );
                $gpt = get_post_meta( $ingredient->ID, '_ph_grams_per_tbsp', true );
                $gps = get_post_meta( $ingredient->ID, '_ph_grams_per_tsp', true );
                echo '<option value="' . esc_attr( $ingredient->ID ) . '" data-type="' . esc_attr( $type ) . '" data-gpc="' . esc_attr( $gpc ) . '" data-gpt="' . esc_attr( $gpt ) . '" data-gps="' . esc_attr( $gps ) . '" ' . selected( $ingredient_id, $ingredient->ID, false ) . '>' . esc_html( $ingredient->post_title ) . '</option>';
            }
            echo '</select></td><td><input class="widefat ph-ing-qty" type="number" step="0.001" min="0" name="ph_recipe_ingredients[' . esc_attr( $i ) . '][quantity]" value="' . esc_attr( $row['quantity'] ?? '' ) . '"></td><td><select class="widefat ph-ing-unit" name="ph_recipe_ingredients[' . esc_attr( $i ) . '][unit]">';
            foreach ( self::units() as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $row['unit'] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>'; }
            echo '</select></td><td class="ph-ing-type">' . esc_html( self::ingredient_types()[ $row['type'] ?? '' ] ?? '' ) . '</td><td><input class="widefat" type="text" name="ph_recipe_ingredients[' . esc_attr( $i ) . '][note]" value="' . esc_attr( $row['note'] ?? '' ) . '"></td><td class="ph-ing-converted">—</td></tr>';
        }
        echo '</tbody></table><p class="description">Use “Non-purchasable process ingredient” for boiling water, ice water, steam, greasing oil, dusting flour, or similar production inputs. They remain in the recipe but are excluded from purchasing and inventory.</p>';
    }

    public static function render_recipe_notes( $post ) {
        $notes = get_comments( array( 'post_id' => $post->ID, 'type' => 'ph_recipe_note', 'status' => 'approve', 'number' => 100, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ) );
        echo '<div class="ph-grid ph-grid--2"><div><h3>Add a note or batch record</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ph_save_batch_note"><input type="hidden" name="recipe_id" value="' . esc_attr( $post->ID ) . '">';
        wp_nonce_field( 'ph_save_batch_note_' . $post->ID );
        echo '<p><label><strong>Type</strong><select class="widefat" name="note_type"><option value="general">General</option><option value="quality_issue">Quality issue</option><option value="ingredient">Ingredient</option><option value="technique">Technique</option><option value="timing">Timing</option><option value="packaging">Packaging</option><option value="customer_feedback">Customer feedback</option><option value="cost_idea">Cost-saving idea</option><option value="next_batch">Next batch</option><option value="batch">Production batch</option></select></label></p>';
        echo '<div class="ph-grid ph-grid--2"><p><label>Batch date<input class="widefat" type="date" name="batch_date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '"></label></p><p><label>Batch quantity<input class="widefat" type="number" min="0" step="0.01" name="batch_quantity"></label></p></div>';
        echo '<p><label><strong>Comment</strong><textarea class="widefat" rows="5" required name="comment"></textarea></label></p><div class="ph-grid ph-grid--3"><p><label>Waste/loss value<input class="widefat" type="number" min="0" step="0.01" name="waste_value"></label></p><p><label>Estimated production cost<input class="widefat" type="number" min="0" step="0.01" name="production_cost"></label></p><p><label><input type="checkbox" name="pinned" value="yes"> Pin this note</label></p></div><p><button class="button button-primary">Save note / batch record</button></p></form></div><div><h3>History</h3>';
        if ( ! $notes ) { echo '<p>No notes have been recorded.</p>'; }
        foreach ( $notes as $note ) {
            $type = get_comment_meta( $note->comment_ID, '_ph_note_type', true );
            $pinned = 'yes' === get_comment_meta( $note->comment_ID, '_ph_pinned', true );
            $batch_date = get_comment_meta( $note->comment_ID, '_ph_batch_date', true );
            $waste = (float) get_comment_meta( $note->comment_ID, '_ph_waste_value', true );
            echo '<article class="ph-note-card' . ( $pinned ? ' is-pinned' : '' ) . '"><strong>' . esc_html( ucwords( str_replace( '_', ' ', $type ) ) ) . '</strong> · ' . esc_html( $batch_date ?: mysql2date( 'M j, Y', $note->comment_date ) );
            if ( $waste > 0 ) { echo ' · <strong>' . wp_kses_post( wc_price( $waste ) ) . ' loss</strong>'; }
            echo '<p>' . nl2br( esc_html( $note->comment_content ) ) . '</p><small>' . esc_html( get_the_author_meta( 'display_name', $note->user_id ) ) . ' · ' . esc_html( mysql2date( 'M j, Y g:i a', $note->comment_date ) ) . '</small> <a class="ph-delete-link" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ph_delete_batch_note&comment_id=' . $note->comment_ID . '&recipe_id=' . $post->ID ), 'ph_delete_batch_note_' . $note->comment_ID ) ) . '" onclick="return confirm(\'Delete this note?\')">Delete</a></article>';
        }
        echo '</div></div>';
    }

    public static function save_supplier( $post_id ) {
        if ( ! self::can_save( $post_id, 'ph_save_supplier' ) ) return;
        $fields = array( '_ph_contact_person','_ph_phone','_ph_mobile','_ph_email','_ph_website','_ph_address','_ph_categories','_ph_account_number','_ph_delivery_days','_ph_minimum_order','_ph_payment_terms','_ph_preferred_contact','_ph_supplier_status','_ph_supplier_priority','_ph_supplier_notes' );
        foreach ( $fields as $key ) update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
    }

    public static function save_ingredient( $post_id ) {
        if ( ! self::can_save( $post_id, 'ph_save_ingredient' ) ) return;
        foreach ( array( '_ph_ingredient_type','_ph_preferred_unit','_ph_include_label','_ph_preparation_state','_ph_primary_supplier','_ph_backup_supplier' ) as $key ) update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
        foreach ( array( '_ph_grams_per_cup','_ph_grams_per_tbsp','_ph_grams_per_tsp','_ph_density_g_ml','_ph_cost_per_unit' ) as $key ) update_post_meta( $post_id, $key, wc_format_decimal( wp_unslash( $_POST[ $key ] ?? '' ) ) );
    }

    public static function save_recipe( $post_id ) {
        if ( ! self::can_save( $post_id, 'ph_save_recipe' ) ) return;
        foreach ( array( '_ph_recipe_yield','_ph_recipe_portions','_ph_prep_time','_ph_cook_time' ) as $key ) update_post_meta( $post_id, $key, wc_format_decimal( wp_unslash( $_POST[ $key ] ?? '' ) ) );
        foreach ( array( '_ph_recipe_yield_unit','_ph_linked_product','_ph_pinned_note','_ph_storage_notes','_ph_reheating_notes','_ph_packaging_notes','_ph_troubleshooting_notes' ) as $key ) update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
        $clean = array();
        foreach ( (array) ( $_POST['ph_recipe_ingredients'] ?? array() ) as $row ) {
            $ingredient_id = absint( $row['ingredient_id'] ?? 0 );
            $qty = wc_format_decimal( wp_unslash( $row['quantity'] ?? '' ) );
            if ( ! $ingredient_id || '' === $qty ) continue;
            $clean[] = array( 'ingredient_id' => $ingredient_id, 'quantity' => $qty, 'unit' => sanitize_key( $row['unit'] ?? '' ), 'type' => sanitize_key( get_post_meta( $ingredient_id, '_ph_ingredient_type', true ) ), 'note' => sanitize_text_field( wp_unslash( $row['note'] ?? '' ) ) );
        }
        update_post_meta( $post_id, '_ph_recipe_ingredients', $clean );
    }

    private static function can_save( $post_id, $action ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return false;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return false;
        return isset( $_POST['_ph_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ph_nonce'] ) ), $action );
    }

    public static function save_batch_note() {
        $recipe_id = absint( $_POST['recipe_id'] ?? 0 );
        if ( ! $recipe_id || ! current_user_can( 'edit_post', $recipe_id ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'ph_save_batch_note_' . $recipe_id );
        $comment = sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) );
        if ( '' === $comment ) wp_safe_redirect( get_edit_post_link( $recipe_id, 'url' ) );
        $comment_id = wp_insert_comment( array( 'comment_post_ID' => $recipe_id, 'comment_content' => $comment, 'comment_type' => 'ph_recipe_note', 'comment_approved' => 1, 'user_id' => get_current_user_id(), 'comment_author' => wp_get_current_user()->display_name ) );
        if ( $comment_id ) {
            $meta = array( '_ph_note_type' => sanitize_key( $_POST['note_type'] ?? 'general' ), '_ph_batch_date' => sanitize_text_field( $_POST['batch_date'] ?? current_time( 'Y-m-d' ) ), '_ph_batch_quantity' => wc_format_decimal( $_POST['batch_quantity'] ?? '' ), '_ph_waste_value' => wc_format_decimal( $_POST['waste_value'] ?? '' ), '_ph_production_cost' => wc_format_decimal( $_POST['production_cost'] ?? '' ), '_ph_pinned' => ! empty( $_POST['pinned'] ) ? 'yes' : 'no' );
            foreach ( $meta as $key => $value ) update_comment_meta( $comment_id, $key, $value );
            $waste = (float) $meta['_ph_waste_value'];
            if ( $waste > 0 ) self::append_production_loss( $recipe_id, $comment_id, $waste, $comment, $meta );
        }
        wp_safe_redirect( get_edit_post_link( $recipe_id, 'url' ) . '#ph_recipe_notes' ); exit;
    }

    private static function append_production_loss( $recipe_id, $comment_id, $amount, $comment, $meta ) {
        $ledger = get_option( 'persiano_loss_waste_ledger_v1', array() );
        if ( ! is_array( $ledger ) ) $ledger = array();
        $record_id = 'recipe-note-' . $comment_id;
        $ledger[ $record_id ] = array( 'id' => $record_id, 'date' => $meta['_ph_batch_date'] ?: current_time( 'Y-m-d' ), 'order_id' => 0, 'customer' => '', 'item_name' => get_the_title( $recipe_id ), 'quantity' => $meta['_ph_batch_quantity'], 'outcome' => 'discarded', 'reason' => 'recipe_batch', 'amount' => $amount, 'production_cost' => (float) $meta['_ph_production_cost'], 'note' => $comment, 'recipe_id' => $recipe_id, 'comment_id' => $comment_id, 'currency' => get_woocommerce_currency() );
        update_option( 'persiano_loss_waste_ledger_v1', $ledger, false );
    }

    public static function delete_batch_note() {
        $comment_id = absint( $_GET['comment_id'] ?? 0 ); $recipe_id = absint( $_GET['recipe_id'] ?? 0 );
        if ( ! $comment_id || ! $recipe_id || ! current_user_can( 'edit_post', $recipe_id ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'ph_delete_batch_note_' . $comment_id );
        wp_delete_comment( $comment_id, true );
        $ledger = get_option( 'persiano_loss_waste_ledger_v1', array() );
        if ( is_array( $ledger ) ) update_option( 'persiano_hub_loss_waste_ledger', array_values( array_filter( $ledger, static function( $row ) use ( $comment_id ) { return (int) ( $row['comment_id'] ?? 0 ) !== $comment_id; } ) ), false );
        wp_safe_redirect( get_edit_post_link( $recipe_id, 'url' ) . '#ph_recipe_notes' ); exit;
    }

    public static function render_production_dashboard() {
        self::guard();
        $counts = array( 'Recipes' => wp_count_posts( self::RECIPE_POST_TYPE )->publish ?? 0, 'Ingredients' => wp_count_posts( self::INGREDIENT_POST_TYPE )->publish ?? 0, 'Suppliers' => wp_count_posts( self::SUPPLIER_POST_TYPE )->publish ?? 0 );
        echo '<div class="wrap ph-workspace"><h1>Recipes & Production</h1><p>Manage recipes, ingredients, conversions, batch notes, suppliers and production losses.</p><div class="ph-stat-grid">';
        foreach ( $counts as $label => $count ) echo '<div class="ph-stat"><strong>' . esc_html( $count ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
        echo '</div><div class="ph-action-grid"><a class="button button-primary button-hero" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::RECIPE_POST_TYPE ) ) . '">Add recipe</a><a class="button button-hero" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::INGREDIENT_POST_TYPE ) ) . '">Add ingredient</a><a class="button button-hero" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::SUPPLIER_POST_TYPE ) ) . '">Add supplier</a></div><h2>Quick workspaces</h2><div class="ph-action-grid"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::RECIPE_PAGE ) ) . '">Recipe table</a><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::INGREDIENT_PAGE ) ) . '">Ingredient conversions</a><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SUPPLIER_PAGE ) ) . '">Supplier phonebook</a></div></div>';
    }

    public static function render_supplier_bulk() { self::render_bulk_table( 'supplier' ); }
    public static function render_ingredient_bulk() { self::render_bulk_table( 'ingredient' ); }
    public static function render_recipe_bulk() { self::render_bulk_table( 'recipe' ); }

    private static function render_bulk_table( $kind ) {
        self::guard();
        $map = array( 'supplier' => array( self::SUPPLIER_POST_TYPE, 'Suppliers Phonebook', 'ph_bulk_save_suppliers' ), 'ingredient' => array( self::INGREDIENT_POST_TYPE, 'Ingredient & Conversion Editor', 'ph_bulk_save_ingredients' ), 'recipe' => array( self::RECIPE_POST_TYPE, 'Recipe Editor', 'ph_bulk_save_recipes' ) );
        list( $post_type, $title, $action ) = $map[ $kind ];
        $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $posts = get_posts( array( 'post_type' => $post_type, 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 300, 'orderby' => 'title', 'order' => 'ASC', 's' => $search ) );
        echo '<div class="wrap ph-workspace"><h1>' . esc_html( $title ) . '</h1><div class="ph-toolbar"><form method="get"><input type="hidden" name="page" value="' . esc_attr( $_GET['page'] ?? '' ) . '"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search"><button class="button">Search</button></form><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ) . '">Add new</a>';
        if ( 'supplier' === $kind ) echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ph_export_suppliers' ), 'ph_export_suppliers' ) ) . '">Export CSV</a>';
        echo '</div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">'; wp_nonce_field( $action );
        echo '<div class="ph-table-scroll"><table class="widefat striped"><thead><tr>';
        $headers = 'supplier' === $kind ? array( 'Name','Contact','Phone','Email','Categories','Delivery days','Status','Role','Actions' ) : ( 'ingredient' === $kind ? array( 'Ingredient','Type','Preferred unit','g/cup','g/tbsp','g/tsp','Density','Cost','Primary supplier','Actions' ) : array( 'Recipe','Yield','Unit','Portions','Prep min','Cook min','Linked product','Notes','Actions' ) );
        foreach ( $headers as $h ) echo '<th>' . esc_html( $h ) . '</th>'; echo '</tr></thead><tbody>';
        foreach ( $posts as $p ) {
            echo '<tr><td><input class="widefat" name="rows[' . esc_attr( $p->ID ) . '][title]" value="' . esc_attr( $p->post_title ) . '"></td>';
            if ( 'supplier' === $kind ) {
                self::bulk_input( $p->ID, 'contact', '_ph_contact_person' ); self::bulk_input( $p->ID, 'phone', '_ph_phone' ); self::bulk_input( $p->ID, 'email', '_ph_email' ); self::bulk_input( $p->ID, 'categories', '_ph_categories' ); self::bulk_input( $p->ID, 'delivery_days', '_ph_delivery_days' ); self::bulk_select( $p->ID, 'status', '_ph_supplier_status', array( 'active'=>'Active','inactive'=>'Inactive' ) ); self::bulk_select( $p->ID, 'priority', '_ph_supplier_priority', array( 'primary'=>'Primary','backup'=>'Backup','other'=>'Other' ) );
            } elseif ( 'ingredient' === $kind ) {
                self::bulk_select( $p->ID, 'type', '_ph_ingredient_type', self::ingredient_types() ); self::bulk_select( $p->ID, 'unit', '_ph_preferred_unit', self::units() ); self::bulk_input( $p->ID, 'gpc', '_ph_grams_per_cup', 'number' ); self::bulk_input( $p->ID, 'gpt', '_ph_grams_per_tbsp', 'number' ); self::bulk_input( $p->ID, 'gps', '_ph_grams_per_tsp', 'number' ); self::bulk_input( $p->ID, 'density', '_ph_density_g_ml', 'number' ); self::bulk_input( $p->ID, 'cost', '_ph_cost_per_unit', 'number' ); self::bulk_post_select( $p->ID, 'supplier', '_ph_primary_supplier', self::SUPPLIER_POST_TYPE );
            } else {
                self::bulk_input( $p->ID, 'yield', '_ph_recipe_yield', 'number' ); self::bulk_input( $p->ID, 'yield_unit', '_ph_recipe_yield_unit' ); self::bulk_input( $p->ID, 'portions', '_ph_recipe_portions', 'number' ); self::bulk_input( $p->ID, 'prep', '_ph_prep_time', 'number' ); self::bulk_input( $p->ID, 'cook', '_ph_cook_time', 'number' ); self::bulk_post_select( $p->ID, 'product', '_ph_linked_product', 'product' ); echo '<td>' . esc_html( wp_trim_words( get_post_meta( $p->ID, '_ph_pinned_note', true ), 8 ) ) . '</td>';
            }
            echo '<td><a class="button button-small" href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">Open</a></td></tr>';
        }
        echo '</tbody></table></div><p><button class="button button-primary">Save all changes</button></p></form></div>';
    }

    public static function bulk_save_suppliers() { self::bulk_save( 'supplier' ); }
    public static function bulk_save_ingredients() { self::bulk_save( 'ingredient' ); }
    public static function bulk_save_recipes() { self::bulk_save( 'recipe' ); }
    private static function bulk_save( $kind ) {
        self::guard(); $action = 'ph_bulk_save_' . $kind . 's'; check_admin_referer( $action );
        $rows = (array) ( $_POST['rows'] ?? array() );
        foreach ( $rows as $id => $row ) {
            $id = absint( $id ); if ( ! $id || ! current_user_can( 'edit_post', $id ) ) continue;
            wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( wp_unslash( $row['title'] ?? '' ) ) ) );
            $map = 'supplier' === $kind ? array( 'contact'=>'_ph_contact_person','phone'=>'_ph_phone','email'=>'_ph_email','categories'=>'_ph_categories','delivery_days'=>'_ph_delivery_days','status'=>'_ph_supplier_status','priority'=>'_ph_supplier_priority' ) : ( 'ingredient' === $kind ? array( 'type'=>'_ph_ingredient_type','unit'=>'_ph_preferred_unit','gpc'=>'_ph_grams_per_cup','gpt'=>'_ph_grams_per_tbsp','gps'=>'_ph_grams_per_tsp','density'=>'_ph_density_g_ml','cost'=>'_ph_cost_per_unit','supplier'=>'_ph_primary_supplier' ) : array( 'yield'=>'_ph_recipe_yield','yield_unit'=>'_ph_recipe_yield_unit','portions'=>'_ph_recipe_portions','prep'=>'_ph_prep_time','cook'=>'_ph_cook_time','product'=>'_ph_linked_product' ) );
            foreach ( $map as $field => $meta ) update_post_meta( $id, $meta, sanitize_text_field( wp_unslash( $row[ $field ] ?? '' ) ) );
        }
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); exit;
    }

    public static function export_suppliers() {
        self::guard(); check_admin_referer( 'ph_export_suppliers' );
        nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename=persiano-suppliers-' . gmdate( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' ); fputcsv( $out, array( 'supplier','contact_person','phone','mobile','email','website','address','categories','account_number','delivery_days','minimum_order','payment_terms','preferred_contact','status','role','notes' ) );
        foreach ( get_posts( array( 'post_type'=>self::SUPPLIER_POST_TYPE,'post_status'=>array('publish','draft'),'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC' ) ) as $p ) {
            $row = array( $p->post_title ); foreach ( array( '_ph_contact_person','_ph_phone','_ph_mobile','_ph_email','_ph_website','_ph_address','_ph_categories','_ph_account_number','_ph_delivery_days','_ph_minimum_order','_ph_payment_terms','_ph_preferred_contact','_ph_supplier_status','_ph_supplier_priority','_ph_supplier_notes' ) as $key ) $row[] = get_post_meta( $p->ID, $key, true ); fputcsv( $out, $row );
        }
        fclose( $out ); exit;
    }

    private static function ingredient_types() { return array( 'purchased'=>'Purchased ingredient','pantry'=>'Pantry/basic ingredient','process'=>'Non-purchasable process ingredient','packaging'=>'Packaging item','garnish'=>'Optional garnish' ); }
    private static function units() { return array( 'g'=>'g','kg'=>'kg','ml'=>'mL','l'=>'L','tsp'=>'tsp','tbsp'=>'tbsp','cup'=>'cup','oz'=>'oz','lb'=>'lb','each'=>'each','pinch'=>'pinch','as_needed'=>'as needed' ); }
    private static function select_field( $name, $label, $value, $options ) { echo '<p><label><strong>' . esc_html( $label ) . '</strong><select class="widefat" name="' . esc_attr( $name ) . '">'; foreach ( $options as $v=>$l ) echo '<option value="' . esc_attr( $v ) . '" ' . selected( $value, $v, false ) . '>' . esc_html( $l ) . '</option>'; echo '</select></label></p>'; }
    private static function input_field( $name, $label, $value, $type='text', $step='' ) { echo '<p><label><strong>' . esc_html( $label ) . '</strong><input class="widefat" type="' . esc_attr( $type ) . '" ' . ( $step ? 'step="'.esc_attr($step).'" min="0"' : '' ) . ' name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></label></p>'; }
    private static function select_post_field( $name, $label, $value, $post_type ) { echo '<p><label><strong>' . esc_html( $label ) . '</strong><select class="widefat" name="' . esc_attr( $name ) . '"><option value="">— None —</option>'; foreach ( get_posts( array( 'post_type'=>$post_type,'post_status'=>array('publish','draft'),'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC' ) ) as $p ) echo '<option value="' . esc_attr( $p->ID ) . '" ' . selected( $value, $p->ID, false ) . '>' . esc_html( $p->post_title ) . '</option>'; echo '</select></label></p>'; }
    private static function bulk_input( $id, $field, $meta, $type='text' ) { echo '<td><input class="widefat" type="' . esc_attr( $type ) . '" step="0.001" name="rows[' . esc_attr( $id ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( get_post_meta( $id, $meta, true ) ) . '"></td>'; }
    private static function bulk_select( $id, $field, $meta, $options ) { $value=get_post_meta($id,$meta,true); echo '<td><select name="rows['.esc_attr($id).']['.esc_attr($field).']">'; foreach($options as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($value,$v,false).'>'.esc_html($l).'</option>'; echo '</select></td>'; }
    private static function bulk_post_select( $id, $field, $meta, $post_type ) { $value=get_post_meta($id,$meta,true); echo '<td><select name="rows['.esc_attr($id).']['.esc_attr($field).']"><option value="">—</option>'; foreach(get_posts(array('post_type'=>$post_type,'post_status'=>array('publish','draft'),'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC')) as $p) echo '<option value="'.esc_attr($p->ID).'" '.selected($value,$p->ID,false).'>'.esc_html($p->post_title).'</option>'; echo '</select></td>'; }
    private static function guard() { if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' ); }

    public static function reorder_hub_menu() {
        global $submenu;
        if ( empty( $submenu['persiano-hub'] ) || ! is_array( $submenu['persiano-hub'] ) ) return;
        $priority = array(
            'persiano-hub' => 10,
            'persiano-hub-orders' => 20,
            'persiano-hub-manual-order' => 30,
            'persiano-hub-advance-orders' => 40,
            self::PAGE_SLUG => 50,
            self::RECIPE_PAGE => 51,
            self::INGREDIENT_PAGE => 52,
            self::SUPPLIER_PAGE => 53,
            'persiano-hub-products' => 60,
            'persiano-hub-labels' => 70,
            'persiano-hub-loss-waste' => 80,
            'persiano-hub-loyalty' => 90,
            'persiano-hub-website-content' => 100,
            'persiano-hub-notifications' => 110,
            'persiano-hub-connections' => 120,
            'persiano-hub-maintenance' => 130,
            'persiano-hub-updates' => 140,
        );
        usort( $submenu['persiano-hub'], static function( $a, $b ) use ( $priority ) {
            $pa = $priority[ $a[2] ?? '' ] ?? 1000; $pb = $priority[ $b[2] ?? '' ] ?? 1000;
            if ( $pa === $pb ) return strcasecmp( wp_strip_all_tags( $a[0] ?? '' ), wp_strip_all_tags( $b[0] ?? '' ) );
            return $pa <=> $pb;
        } );
        foreach ( $submenu['persiano-hub'] as &$item ) {
            if ( 'persiano-hub' === ( $item[2] ?? '' ) ) $item[0] = __( 'Dashboard', 'persiano-hub' );
        }
    }

    public static function admin_css() {
        echo '<style>.ph-grid{display:grid;gap:14px}.ph-grid--2{grid-template-columns:repeat(2,minmax(0,1fr))}.ph-grid--3{grid-template-columns:repeat(3,minmax(0,1fr))}.ph-grid p{margin:0 0 12px}.ph-grid label strong{display:block;margin-bottom:5px}.ph-workspace .ph-stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}.ph-stat{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px}.ph-stat strong{display:block;font-size:30px}.ph-stat span{color:#646970}.ph-action-grid{display:flex;gap:10px;flex-wrap:wrap;margin:15px 0 25px}.ph-toolbar{display:flex;gap:10px;justify-content:space-between;align-items:center;margin:14px 0}.ph-toolbar form{display:flex;gap:6px}.ph-table-scroll{overflow:auto;background:#fff;border:1px solid #dcdcde}.ph-table-scroll table{min-width:1100px}.ph-note-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px;margin-bottom:10px}.ph-note-card.is-pinned{border-left:5px solid #b32d2e}.ph-delete-link{float:right;color:#b32d2e}.ph-recipe-table select,.ph-recipe-table input{min-width:90px}@media(max-width:900px){.ph-grid--2,.ph-grid--3,.ph-workspace .ph-stat-grid{grid-template-columns:1fr}.ph-toolbar{align-items:flex-start;flex-direction:column}}</style>';
    }

    public static function admin_js() {
        $screen = get_current_screen(); if ( ! $screen || self::RECIPE_POST_TYPE !== $screen->post_type ) return;
        ?>
        <script>
        (function(){
          function convert(row){
            var scale=parseFloat(document.getElementById('ph-recipe-scale')?.value||1), qty=parseFloat(row.querySelector('.ph-ing-qty')?.value||0)*scale, unit=row.querySelector('.ph-ing-unit')?.value||'', opt=row.querySelector('.ph-ing-select')?.selectedOptions[0], mode=document.getElementById('ph-recipe-display-mode')?.value||'metric', out='—';
            if(!opt||!qty){row.querySelector('.ph-ing-converted').textContent=out;return;}
            var type=opt.dataset.type||''; row.querySelector('.ph-ing-type').textContent=({'purchased':'Purchased ingredient','pantry':'Pantry/basic ingredient','process':'Non-purchasable process ingredient','packaging':'Packaging item','garnish':'Optional garnish'})[type]||'';
            if(unit==='g'&&(mode==='volume'||mode==='both')){var gpc=parseFloat(opt.dataset.gpc||0),gpt=parseFloat(opt.dataset.gpt||0),gps=parseFloat(opt.dataset.gps||0);if(gpc) out=(qty/gpc).toFixed(2)+' cup';else if(gpt) out=(qty/gpt).toFixed(2)+' tbsp';else if(gps) out=(qty/gps).toFixed(2)+' tsp';else out='Conversion unavailable';}
            else if(unit==='kg') out=(qty*1000).toFixed(0)+' g'; else if(unit==='ml'&&qty>=1000) out=(qty/1000).toFixed(2)+' L'; else if(unit==='l') out=(qty*1000).toFixed(0)+' mL'; else if(unit==='tsp') out=(qty/3).toFixed(2)+' tbsp · '+(qty/48).toFixed(2)+' cup'; else if(unit==='tbsp') out=(qty*3).toFixed(2)+' tsp · '+(qty/16).toFixed(2)+' cup'; else if(unit==='cup') out=(qty*16).toFixed(2)+' tbsp · '+(qty*48).toFixed(2)+' tsp'; else if(unit==='oz') out=(qty*28.3495).toFixed(1)+' g'; else if(unit==='lb') out=(qty*453.592).toFixed(1)+' g'; else out=qty.toFixed(2)+' '+unit;
            row.querySelector('.ph-ing-converted').textContent=out;
          }
          function refresh(){document.querySelectorAll('.ph-recipe-row').forEach(convert)}
          document.addEventListener('input',function(e){if(e.target.closest('.ph-recipe-table')||e.target.id==='ph-recipe-scale')refresh()});document.addEventListener('change',function(e){if(e.target.closest('.ph-recipe-table')||e.target.id==='ph-recipe-display-mode')refresh()});refresh();
        })();
        </script>
        <?php
    }
}
