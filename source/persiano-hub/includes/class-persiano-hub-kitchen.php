<?php
/**
 * Persiano kitchen operations for recipes and ingredients.
 *
 * Adds operational recipe details, step timing/media, batch scaling,
 * print/export production sheets, recipe versions, ingredient images and
 * on-hand inventory, duplicate/navigation helpers and a basic order-driven
 * production planner.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Kitchen {
    const ING_IMAGE_ID      = '_persiano_ing_image_id';
    const ING_ON_HAND_QTY   = '_persiano_ing_on_hand_qty';
    const ING_ON_HAND_UNIT  = '_persiano_ing_on_hand_unit';

    const RECIPE_CATEGORY        = '_persiano_recipe_category';
    const RECIPE_SERVING_NOTE    = '_persiano_recipe_serving_note';
    const RECIPE_PREP_MINUTES    = '_persiano_recipe_prep_minutes';
    const RECIPE_PASSIVE_MINUTES = '_persiano_recipe_passive_minutes';
    const RECIPE_STORAGE         = '_persiano_recipe_storage';
    const RECIPE_FREEZING        = '_persiano_recipe_freezing';
    const RECIPE_EQUIPMENT       = '_persiano_recipe_equipment';
    const RECIPE_STEPS           = '_persiano_recipe_steps';
    const RECIPE_MEDIA_IDS       = '_persiano_recipe_media_ids';
    const RECIPE_ACTUAL_COST     = '_persiano_recipe_actual_batch_cost';
    const RECIPE_ACTUAL_YIELD    = '_persiano_recipe_actual_yield';
    const RECIPE_ACTUAL_NOTES    = '_persiano_recipe_actual_notes';
    const RECIPE_VERSIONS        = '_persiano_recipe_versions';
    const RECIPE_VERSION_HASH    = '_persiano_recipe_version_hash';

    private static $last_component_requirements = array();

    public static function init() {
        add_action( 'add_meta_boxes_' . Persiano_Hub_Costing::INGREDIENT_POST_TYPE, array( __CLASS__, 'ingredient_meta_boxes' ), 20 );
        add_action( 'add_meta_boxes_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'recipe_meta_boxes' ), 20 );
        add_action( 'edit_form_after_title', array( __CLASS__, 'render_top_navigation' ) );
        add_action( 'save_post_' . Persiano_Hub_Costing::INGREDIENT_POST_TYPE, array( __CLASS__, 'save_ingredient' ), 30, 3 );
        add_action( 'save_post_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'save_recipe' ), 40, 3 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ), 30 );

        add_action( 'admin_post_persiano_hub_print_recipe', array( __CLASS__, 'print_recipe' ) );
        add_action( 'admin_post_persiano_hub_export_recipe_csv', array( __CLASS__, 'export_recipe_csv' ) );
        add_action( 'admin_post_persiano_hub_duplicate_recipe', array( __CLASS__, 'duplicate_recipe' ) );
        add_action( 'admin_post_persiano_hub_restore_recipe_version', array( __CLASS__, 'restore_recipe_version' ) );
    }

    public static function ingredient_meta_boxes() {
        add_meta_box(
            'persiano_hub_ingredient_product_details',
            __( 'Ingredient Product & Inventory', 'persiano-hub' ),
            array( __CLASS__, 'render_ingredient_details' ),
            Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
            'side',
            'high'
        );
    }

    public static function recipe_meta_boxes() {
        add_meta_box(
            'persiano_hub_recipe_kitchen_details',
            __( 'Kitchen Recipe Details', 'persiano-hub' ),
            array( __CLASS__, 'render_recipe_details' ),
            Persiano_Hub_Costing::RECIPE_POST_TYPE,
            'normal',
            'default'
        );
        add_meta_box(
            'persiano_hub_recipe_steps_media',
            __( 'Method, Timing & Cooking Media', 'persiano-hub' ),
            array( __CLASS__, 'render_recipe_steps' ),
            Persiano_Hub_Costing::RECIPE_POST_TYPE,
            'normal',
            'default'
        );
        add_meta_box(
            'persiano_hub_recipe_scaling',
            __( 'Scale, Print & Export', 'persiano-hub' ),
            array( __CLASS__, 'render_recipe_scaling' ),
            Persiano_Hub_Costing::RECIPE_POST_TYPE,
            'normal',
            'default'
        );
        add_meta_box(
            'persiano_hub_recipe_actuals',
            __( 'Actual Production Cost', 'persiano-hub' ),
            array( __CLASS__, 'render_recipe_actuals' ),
            Persiano_Hub_Costing::RECIPE_POST_TYPE,
            'side',
            'default'
        );
        add_meta_box(
            'persiano_hub_recipe_versions',
            __( 'Recipe Versions', 'persiano-hub' ),
            array( __CLASS__, 'render_recipe_versions' ),
            Persiano_Hub_Costing::RECIPE_POST_TYPE,
            'side',
            'default'
        );
    }

    private static function can_save( $post_id, $nonce_name, $nonce_action ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return false;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return false;
        }
        if ( ! isset( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), $nonce_action ) ) {
            return false;
        }
        return current_user_can( 'manage_woocommerce' );
    }

    private static function decimal( $value, $default = 0 ) {
        if ( '' === $value || null === $value ) {
            return (float) $default;
        }
        return (float) str_replace( ',', '.', (string) $value );
    }

    private static function distinct_meta_values( $meta_key, $post_type = '' ) {
        global $wpdb;
        $values = array();
        $sql = "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm";
        $args = array();
        if ( $post_type ) {
            $sql .= " INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id";
        }
        $sql .= ' WHERE pm.meta_key = %s AND pm.meta_value <> %s';
        $args[] = $meta_key;
        $args[] = '';
        if ( $post_type ) {
            $sql .= ' AND p.post_type = %s AND p.post_status NOT IN (%s,%s)';
            $args[] = $post_type;
            $args[] = 'trash';
            $args[] = 'auto-draft';
        }
        $sql .= ' ORDER BY pm.meta_value ASC LIMIT 250';
        $rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( (array) $rows as $value ) {
            $value = sanitize_text_field( $value );
            if ( $value ) {
                $values[] = $value;
            }
        }
        return array_values( array_unique( $values ) );
    }

    private static function all_ingredient_titles() {
        return get_posts(
            array(
                'post_type'      => Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
    }

    private static function render_datalist( $id, $values ) {
        echo '<datalist id="' . esc_attr( $id ) . '">';
        foreach ( $values as $value ) {
            echo '<option value="' . esc_attr( $value ) . '"></option>';
        }
        echo '</datalist>';
    }

    public static function render_top_navigation( $post ) {
        if ( ! $post || ! in_array( $post->post_type, array( Persiano_Hub_Costing::INGREDIENT_POST_TYPE, Persiano_Hub_Costing::RECIPE_POST_TYPE ), true ) ) {
            return;
        }
        echo '<div class="ph-top-form-navigation">';
        if ( Persiano_Hub_Costing::INGREDIENT_POST_TYPE === $post->post_type ) {
            self::render_ingredient_navigation( $post );
        } else {
            self::render_recipe_navigation( $post );
        }
        echo '</div>';
    }

    public static function render_ingredient_navigation( $post ) {
        $prev = self::sibling_post_id( $post->ID, 'prev' );
        $next = self::sibling_post_id( $post->ID, 'next' );
        $list_url = add_query_arg( array( 'page' => Persiano_Hub_Costing::MENU_SLUG, 'tab' => 'ingredients' ), admin_url( 'admin.php' ) );
        echo '<div class="ph-recipe-nav">';
        echo $prev ? '<a class="button" href="' . esc_url( get_edit_post_link( $prev ) ) . '">← ' . esc_html__( 'Previous ingredient', 'persiano-hub' ) . '</a>' : '<span class="button disabled">← ' . esc_html__( 'Previous ingredient', 'persiano-hub' ) . '</span>';
        echo '<a class="button" href="' . esc_url( $list_url ) . '">' . esc_html__( 'All ingredients', 'persiano-hub' ) . '</a>';
        echo $next ? '<a class="button" href="' . esc_url( get_edit_post_link( $next ) ) . '">' . esc_html__( 'Next ingredient', 'persiano-hub' ) . ' →</a>' : '<span class="button disabled">' . esc_html__( 'Next ingredient', 'persiano-hub' ) . ' →</span>';
        echo '</div>';
    }

    public static function render_ingredient_details( $post ) {
        wp_nonce_field( 'persiano_hub_save_ingredient_kitchen', 'persiano_hub_ingredient_kitchen_nonce' );
        $image_id = absint( get_post_meta( $post->ID, self::ING_IMAGE_ID, true ) );
        $on_hand_qty = self::decimal( get_post_meta( $post->ID, self::ING_ON_HAND_QTY, true ) );
        $on_hand_unit = get_post_meta( $post->ID, self::ING_ON_HAND_UNIT, true );
        if ( ! $on_hand_unit ) {
            $on_hand_unit = get_post_meta( $post->ID, Persiano_Hub_Costing::ING_PURCHASE_UNIT, true );
        }
        if ( ! $on_hand_unit ) {
            $on_hand_unit = 'kg';
        }
        $thumb = $image_id ? wp_get_attachment_image( $image_id, 'medium', false, array( 'class' => 'ph-kitchen-media-preview-image' ) ) : '';
        ?>
        <div class="ph-kitchen-media-picker" data-multiple="0">
            <div class="ph-kitchen-media-preview"><?php echo $thumb ? wp_kses_post( $thumb ) : '<span class="ph-media-empty">' . esc_html__( 'No product image', 'persiano-hub' ) . '</span>'; ?></div>
            <input type="hidden" class="ph-kitchen-media-ids" name="persiano_ing_image_id" value="<?php echo esc_attr( $image_id ); ?>">
            <p><button type="button" class="button ph-kitchen-choose-media"><?php esc_html_e( 'Choose product image', 'persiano-hub' ); ?></button> <button type="button" class="button-link-delete ph-kitchen-clear-media"><?php esc_html_e( 'Remove', 'persiano-hub' ); ?></button></p>
        </div>
        <hr>
        <p><label for="persiano_ing_on_hand_qty"><strong><?php esc_html_e( 'Approx. quantity on hand', 'persiano-hub' ); ?></strong></label></p>
        <div class="ph-inline-fields">
            <input type="number" min="0" step="0.0001" id="persiano_ing_on_hand_qty" name="persiano_ing_on_hand_qty" value="<?php echo esc_attr( $on_hand_qty ); ?>" style="width:55%">
            <select name="persiano_ing_on_hand_unit" style="width:42%">
                <?php foreach ( Persiano_Hub_Costing::unit_options() as $value => $label ) : ?>
                    <?php if ( in_array( $value, array( 'serving', 'batch' ), true ) ) { continue; } ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $on_hand_unit, $value ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <p class="description"><?php esc_html_e( 'Optional. Used by the Production Planner to estimate shopping shortages.', 'persiano-hub' ); ?></p>
        <?php
    }

    public static function save_ingredient( $post_id, $post, $update ) {
        if ( ! self::can_save( $post_id, 'persiano_hub_ingredient_kitchen_nonce', 'persiano_hub_save_ingredient_kitchen' ) ) {
            return;
        }
        $image_id = isset( $_POST['persiano_ing_image_id'] ) ? absint( $_POST['persiano_ing_image_id'] ) : 0;
        $qty = isset( $_POST['persiano_ing_on_hand_qty'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_ing_on_hand_qty'] ) ) ) : 0;
        $unit = isset( $_POST['persiano_ing_on_hand_unit'] ) ? sanitize_key( wp_unslash( $_POST['persiano_ing_on_hand_unit'] ) ) : 'kg';
        if ( ! array_key_exists( $unit, Persiano_Hub_Costing::unit_options() ) || in_array( $unit, array( 'serving', 'batch' ), true ) ) {
            $unit = 'kg';
        }
        update_post_meta( $post_id, self::ING_IMAGE_ID, $image_id );
        $old_qty = max( 0, self::decimal( get_post_meta( $post_id, self::ING_ON_HAND_QTY, true ) ) );
        $old_unit = sanitize_key( get_post_meta( $post_id, self::ING_ON_HAND_UNIT, true ) );
        if ( class_exists( 'Persiano_Hub_Operations' ) && ( abs( $old_qty - $qty ) > 0.0001 || $old_unit !== $unit ) ) {
            Persiano_Hub_Operations::adjust_inventory( $post_id, 'set', $qty, $unit, __( 'Ingredient record stock count', 'persiano-hub' ), '', 'ingredient_editor' );
        } else {
            update_post_meta( $post_id, self::ING_ON_HAND_QTY, $qty );
            update_post_meta( $post_id, self::ING_ON_HAND_UNIT, $unit );
        }
    }

    private static function sibling_post_id( $post_id, $direction ) {
        $posts = get_posts(
            array(
                'post_type'      => get_post_type( $post_id ),
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
        $index = array_search( (int) $post_id, array_map( 'intval', $posts ), true );
        if ( false === $index ) {
            return 0;
        }
        if ( 'prev' === $direction && $index > 0 ) {
            return (int) $posts[ $index - 1 ];
        }
        if ( 'next' === $direction && $index < count( $posts ) - 1 ) {
            return (int) $posts[ $index + 1 ];
        }
        return 0;
    }

    public static function render_recipe_navigation( $post ) {
        $prev = self::sibling_post_id( $post->ID, 'prev' );
        $next = self::sibling_post_id( $post->ID, 'next' );
        $list_url = add_query_arg( array( 'page' => Persiano_Hub_Costing::MENU_SLUG, 'tab' => 'recipes' ), admin_url( 'admin.php' ) );
        $duplicate_url = wp_nonce_url(
            add_query_arg( array( 'action' => 'persiano_hub_duplicate_recipe', 'recipe_id' => $post->ID ), admin_url( 'admin-post.php' ) ),
            'persiano_hub_duplicate_recipe_' . $post->ID
        );
        echo '<div class="ph-recipe-nav">';
        echo $prev ? '<a class="button" href="' . esc_url( get_edit_post_link( $prev ) ) . '">← ' . esc_html__( 'Previous recipe', 'persiano-hub' ) . '</a>' : '<span class="button disabled">← ' . esc_html__( 'Previous recipe', 'persiano-hub' ) . '</span>';
        echo '<a class="button" href="' . esc_url( $list_url ) . '">' . esc_html__( 'All recipes', 'persiano-hub' ) . '</a>';
        echo '<a class="button" href="' . esc_url( $duplicate_url ) . '">' . esc_html__( 'Duplicate recipe', 'persiano-hub' ) . '</a>';
        echo $next ? '<a class="button" href="' . esc_url( get_edit_post_link( $next ) ) . '">' . esc_html__( 'Next recipe', 'persiano-hub' ) . ' →</a>' : '<span class="button disabled">' . esc_html__( 'Next recipe', 'persiano-hub' ) . ' →</span>';
        echo '</div>';
    }

    public static function render_recipe_details( $post ) {
        wp_nonce_field( 'persiano_hub_save_recipe_kitchen', 'persiano_hub_recipe_kitchen_nonce' );
        $category = get_post_meta( $post->ID, self::RECIPE_CATEGORY, true );
        $serving_note = get_post_meta( $post->ID, self::RECIPE_SERVING_NOTE, true );
        $prep_minutes = self::decimal( get_post_meta( $post->ID, self::RECIPE_PREP_MINUTES, true ) );
        $passive_minutes = self::decimal( get_post_meta( $post->ID, self::RECIPE_PASSIVE_MINUTES, true ) );
        $active_minutes = self::decimal( get_post_meta( $post->ID, Persiano_Hub_Costing::RECIPE_LABOUR_MINUTES, true ) );
        $storage = get_post_meta( $post->ID, self::RECIPE_STORAGE, true );
        $freezing = get_post_meta( $post->ID, self::RECIPE_FREEZING, true );
        $equipment = get_post_meta( $post->ID, self::RECIPE_EQUIPMENT, true );
        $media_ids = get_post_meta( $post->ID, self::RECIPE_MEDIA_IDS, true );
        $media_ids = is_array( $media_ids ) ? array_map( 'absint', $media_ids ) : array();
        $categories = self::distinct_meta_values( self::RECIPE_CATEGORY, Persiano_Hub_Costing::RECIPE_POST_TYPE );
        ?>
        <div class="ph-costing-grid ph-costing-grid--3">
            <div class="ph-costing-field">
                <label for="persiano_recipe_category"><?php esc_html_e( 'Recipe category', 'persiano-hub' ); ?></label>
                <input type="text" list="ph-recipe-category-list" class="widefat" id="persiano_recipe_category" name="persiano_recipe_category" value="<?php echo esc_attr( $category ); ?>" placeholder="Main dish, sauce, component…">
                <?php self::render_datalist( 'ph-recipe-category-list', $categories ); ?>
            </div>
            <div class="ph-costing-field ph-costing-field--wide">
                <label for="persiano_recipe_serving_note"><?php esc_html_e( 'Serving / yield description', 'persiano-hub' ); ?></label>
                <input type="text" class="widefat" id="persiano_recipe_serving_note" name="persiano_recipe_serving_note" value="<?php echo esc_attr( $serving_note ); ?>" placeholder="e.g. 8 individual 750 ml trays, or 10 dinner portions">
            </div>
            <div class="ph-costing-field">
                <label for="persiano_recipe_prep_minutes"><?php esc_html_e( 'Prep time', 'persiano-hub' ); ?></label>
                <div class="ph-input-suffix"><input type="number" min="0" step="1" class="widefat" id="persiano_recipe_prep_minutes" name="persiano_recipe_prep_minutes" value="<?php echo esc_attr( $prep_minutes ); ?>"><span>min</span></div>
            </div>
            <div class="ph-costing-field">
                <label><?php esc_html_e( 'Active labour time', 'persiano-hub' ); ?></label>
                <div class="ph-readonly-time"><strong><?php echo esc_html( number_format_i18n( $active_minutes, 0 ) ); ?> min</strong><span><?php esc_html_e( 'This is the labour time used in costing.', 'persiano-hub' ); ?></span></div>
            </div>
            <div class="ph-costing-field">
                <label for="persiano_recipe_passive_minutes"><?php esc_html_e( 'Passive cooking / resting time', 'persiano-hub' ); ?></label>
                <div class="ph-input-suffix"><input type="number" min="0" step="1" class="widefat" id="persiano_recipe_passive_minutes" name="persiano_recipe_passive_minutes" value="<?php echo esc_attr( $passive_minutes ); ?>"><span>min</span></div>
            </div>
        </div>
        <div class="ph-costing-grid ph-costing-grid--3">
            <div class="ph-costing-field"><label for="persiano_recipe_storage"><?php esc_html_e( 'Storage / shelf life', 'persiano-hub' ); ?></label><textarea class="widefat" rows="3" id="persiano_recipe_storage" name="persiano_recipe_storage" placeholder="Refrigerate up to 3 days…"><?php echo esc_textarea( $storage ); ?></textarea></div>
            <div class="ph-costing-field"><label for="persiano_recipe_freezing"><?php esc_html_e( 'Freezing / reheating', 'persiano-hub' ); ?></label><textarea class="widefat" rows="3" id="persiano_recipe_freezing" name="persiano_recipe_freezing" placeholder="Freeze before baking…"><?php echo esc_textarea( $freezing ); ?></textarea></div>
            <div class="ph-costing-field"><label for="persiano_recipe_equipment"><?php esc_html_e( 'Equipment', 'persiano-hub' ); ?></label><textarea class="widefat" rows="3" id="persiano_recipe_equipment" name="persiano_recipe_equipment" placeholder="Large pot, 20 cm pan…"><?php echo esc_textarea( $equipment ); ?></textarea></div>
        </div>
        <div class="ph-costing-field ph-costing-field--full">
            <label><?php esc_html_e( 'Recipe media library', 'persiano-hub' ); ?></label>
            <div class="ph-kitchen-media-picker" data-multiple="1">
                <div class="ph-kitchen-media-preview ph-kitchen-media-preview--grid">
                    <?php foreach ( $media_ids as $media_id ) : ?>
                        <div data-id="<?php echo esc_attr( $media_id ); ?>"><?php echo wp_kses_post( wp_get_attachment_image( $media_id, 'thumbnail' ) ); ?></div>
                    <?php endforeach; ?>
                    <?php if ( ! $media_ids ) : ?><span class="ph-media-empty"><?php esc_html_e( 'No cooking media attached', 'persiano-hub' ); ?></span><?php endif; ?>
                </div>
                <input type="hidden" class="ph-kitchen-media-ids" name="persiano_recipe_media_ids" value="<?php echo esc_attr( implode( ',', $media_ids ) ); ?>">
                <p><button type="button" class="button ph-kitchen-choose-media"><?php esc_html_e( 'Choose photos / videos', 'persiano-hub' ); ?></button> <button type="button" class="button-link-delete ph-kitchen-clear-media"><?php esc_html_e( 'Clear media', 'persiano-hub' ); ?></button></p>
            </div>
        </div>
        <?php
    }

    private static function get_steps( $recipe_id ) {
        $steps = get_post_meta( $recipe_id, self::RECIPE_STEPS, true );
        return is_array( $steps ) ? $steps : array();
    }

    private static function render_step_row( $index, $step ) {
        $title = isset( $step['title'] ) ? $step['title'] : '';
        $instruction = isset( $step['instruction'] ) ? $step['instruction'] : '';
        $minutes = isset( $step['minutes'] ) ? self::decimal( $step['minutes'] ) : 0;
        $time_type = isset( $step['time_type'] ) && 'passive' === $step['time_type'] ? 'passive' : 'active';
        $temperature = isset( $step['temperature'] ) ? $step['temperature'] : '';
        $equipment = isset( $step['equipment'] ) ? $step['equipment'] : '';
        $critical = ! empty( $step['critical'] );
        $internal_note = isset( $step['internal_note'] ) ? $step['internal_note'] : '';
        $media_id = isset( $step['media_id'] ) ? absint( $step['media_id'] ) : 0;
        ?>
        <div class="ph-step-row" data-index="<?php echo esc_attr( $index ); ?>">
            <div class="ph-step-number"><strong><?php echo is_numeric( $index ) ? esc_html( (int) $index + 1 ) : '#'; ?></strong></div>
            <div class="ph-step-main">
                <input type="text" class="widefat" name="persiano_recipe_steps[<?php echo esc_attr( $index ); ?>][title]" value="<?php echo esc_attr( $title ); ?>" placeholder="Step title">
                <textarea class="widefat" rows="3" name="persiano_recipe_steps[<?php echo esc_attr( $index ); ?>][instruction]" placeholder="Describe this step…"><?php echo esc_textarea( $instruction ); ?></textarea>
                <input type="text" class="widefat" name="persiano_recipe_steps[<?php echo esc_attr( $index ); ?>][equipment]" value="<?php echo esc_attr( $equipment ); ?>" placeholder="Equipment">
                <textarea class="widefat" rows="2" name="persiano_recipe_steps[<?php echo esc_attr( $index ); ?>][internal_note]" placeholder="Internal note"><?php echo esc_textarea( $internal_note ); ?></textarea>
                <label><input type="checkbox" name="persiano_recipe_steps[<?php echo esc_attr( $index ); ?>][critical]" value="1" <?php checked( $critical ); ?>> <?php esc_html_e( 'Critical step', 'persiano-hub' ); ?></label>
            </div>
            <div><input type="number" min="0" step="1" class="widefat ph-step-minutes" name="persiano_recipe_steps[<?php echo esc_attr( $index ); ?>][minutes]" value="<?php echo esc_attr( $minutes ); ?>" placeholder="min"></div>
            <div><select class="widefat ph-step-time-type" name="persiano_recipe_steps[<?php echo esc_attr( $index ); ?>][time_type]"><option value="active" <?php selected( $time_type, 'active' ); ?>><?php esc_html_e( 'Active', 'persiano-hub' ); ?></option><option value="passive" <?php selected( $time_type, 'passive' ); ?>><?php esc_html_e( 'Passive', 'persiano-hub' ); ?></option></select></div>
            <div><input type="text" class="widefat" name="persiano_recipe_steps[<?php echo esc_attr( $index ); ?>][temperature]" value="<?php echo esc_attr( $temperature ); ?>" placeholder="180°C / medium"></div>
            <div class="ph-step-media ph-kitchen-media-picker" data-multiple="0">
                <div class="ph-kitchen-media-preview"><?php echo $media_id ? wp_kses_post( wp_get_attachment_image( $media_id, 'thumbnail' ) ) : '<span class="ph-media-empty">+</span>'; ?></div>
                <input type="hidden" class="ph-kitchen-media-ids" name="persiano_recipe_steps[<?php echo esc_attr( $index ); ?>][media_id]" value="<?php echo esc_attr( $media_id ); ?>">
                <button type="button" class="button-link ph-kitchen-choose-media"><?php esc_html_e( 'Media', 'persiano-hub' ); ?></button>
            </div>
            <button type="button" class="button-link-delete ph-remove-step" aria-label="<?php esc_attr_e( 'Remove step', 'persiano-hub' ); ?>">×</button>
        </div>
        <?php
    }

    public static function render_recipe_steps( $post ) {
        $steps = self::get_steps( $post->ID );
        if ( ! $steps ) {
            $steps = array( array( 'instruction' => '', 'minutes' => '', 'time_type' => 'active', 'temperature' => '', 'media_id' => 0 ) );
        }
        ?>
        <div class="ph-costing-heading-row"><div><p><?php esc_html_e( 'Give each step a duration and mark it active or passive. Active step time can be copied into labour costing; passive time helps plan the kitchen without inflating labour cost.', 'persiano-hub' ); ?></p></div><div><button type="button" class="button" id="ph-sync-step-times"><?php esc_html_e( 'Use step times for timing & labour', 'persiano-hub' ); ?></button> <button type="button" class="button" id="ph-add-recipe-step"><?php esc_html_e( '+ Add step', 'persiano-hub' ); ?></button></div></div>
        <div class="ph-step-grid ph-step-grid--header"><span>#</span><span><?php esc_html_e( 'Instruction', 'persiano-hub' ); ?></span><span><?php esc_html_e( 'Time', 'persiano-hub' ); ?></span><span><?php esc_html_e( 'Type', 'persiano-hub' ); ?></span><span><?php esc_html_e( 'Temperature', 'persiano-hub' ); ?></span><span><?php esc_html_e( 'Media', 'persiano-hub' ); ?></span><span></span></div>
        <div id="ph-recipe-steps">
            <?php foreach ( $steps as $index => $step ) { self::render_step_row( $index, $step ); } ?>
        </div>
        <script type="text/template" id="ph-recipe-step-template"><?php self::render_step_row( '__INDEX__', array() ); ?></script>
        <?php
    }

    public static function render_recipe_scaling( $post ) {
        $yield_qty = max( 0.01, self::decimal( get_post_meta( $post->ID, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 ) );
        $yield_label = Persiano_Hub_Costing::canonical_recipe_unit( get_post_meta( $post->ID, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) );
        if ( ! $yield_label ) { $yield_label = 'each'; }
        $target = $yield_qty;
        $print_url = wp_nonce_url( add_query_arg( array( 'action' => 'persiano_hub_print_recipe', 'recipe_id' => $post->ID, 'target_yield' => $target ), admin_url( 'admin-post.php' ) ), 'persiano_hub_print_recipe_' . $post->ID );
        $csv_url = wp_nonce_url( add_query_arg( array( 'action' => 'persiano_hub_export_recipe_csv', 'recipe_id' => $post->ID, 'target_yield' => $target ), admin_url( 'admin-post.php' ) ), 'persiano_hub_export_recipe_' . $post->ID );
        ?>
        <div class="ph-scale-controls" data-print-base="<?php echo esc_attr( remove_query_arg( array( 'target_yield', '_wpnonce' ), $print_url ) ); ?>" data-csv-base="<?php echo esc_attr( remove_query_arg( array( 'target_yield', '_wpnonce' ), $csv_url ) ); ?>" data-print-nonce="<?php echo esc_attr( wp_create_nonce( 'persiano_hub_print_recipe_' . $post->ID ) ); ?>" data-csv-nonce="<?php echo esc_attr( wp_create_nonce( 'persiano_hub_export_recipe_' . $post->ID ) ); ?>">
            <div><label for="ph-scale-target"><strong><?php esc_html_e( 'Base recipe', 'persiano-hub' ); ?></strong></label><p><?php echo esc_html( $yield_qty . ' ' . $yield_label ); ?></p></div>
            <div><label for="ph-scale-target"><strong><?php esc_html_e( 'Make', 'persiano-hub' ); ?></strong></label><input type="number" id="ph-scale-target" min="0.01" step="0.01" value="<?php echo esc_attr( $target ); ?>"> <span><?php echo esc_html( $yield_label ); ?></span></div>
            <div class="ph-scale-actions"><a class="button button-primary" target="_blank" id="ph-print-production-sheet" href="<?php echo esc_url( $print_url ); ?>"><?php esc_html_e( 'Print production sheet', 'persiano-hub' ); ?></a><a class="button" id="ph-export-recipe-csv" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Export CSV', 'persiano-hub' ); ?></a></div>
        </div>
        <p class="description"><?php esc_html_e( 'Ingredient quantities are scaled from the saved base yield. Per-ingredient rounding rules are respected in the production sheet.', 'persiano-hub' ); ?></p>
        <?php
    }

    public static function render_recipe_actuals( $post ) {
        $actual_cost = self::decimal( get_post_meta( $post->ID, self::RECIPE_ACTUAL_COST, true ) );
        $actual_yield = self::decimal( get_post_meta( $post->ID, self::RECIPE_ACTUAL_YIELD, true ) );
        $actual_notes = get_post_meta( $post->ID, self::RECIPE_ACTUAL_NOTES, true );
        $summary = Persiano_Hub_Costing::calculate_recipe( $post->ID );
        $yield_unit = Persiano_Hub_Costing::canonical_recipe_unit( get_post_meta( $post->ID, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) );
        $variance = $actual_cost > 0 && ! empty( $summary['batch_cost'] ) ? ( ( $actual_cost - $summary['batch_cost'] ) / $summary['batch_cost'] ) * 100 : null;
        ?>
        <p><label for="persiano_recipe_actual_batch_cost"><strong><?php esc_html_e( 'Actual batch cost', 'persiano-hub' ); ?></strong></label><input type="number" min="0" step="0.01" class="widefat ph-recipe-live" id="persiano_recipe_actual_batch_cost" name="persiano_recipe_actual_batch_cost" value="<?php echo esc_attr( $actual_cost ); ?>"></p>
        <p><label for="persiano_recipe_actual_yield"><strong><?php echo esc_html( sprintf( __( 'Actual output (%s)', 'persiano-hub' ), $yield_unit ) ); ?></strong></label><input type="number" min="0" step="0.01" class="widefat ph-recipe-live" id="persiano_recipe_actual_yield" name="persiano_recipe_actual_yield" value="<?php echo esc_attr( $actual_yield ); ?>"></p>
        <p class="description"><?php esc_html_e( 'Actual production data replaces the theoretical batch cost and output only when both fields are greater than zero. Leave both at zero to use the recipe calculation.', 'persiano-hub' ); ?></p>
        <?php if ( null !== $variance ) : ?><p><strong><?php esc_html_e( 'Variance vs theoretical:', 'persiano-hub' ); ?></strong> <?php echo esc_html( number_format_i18n( $variance, 1 ) . '%' ); ?></p><?php endif; ?>
        <p><label><strong><?php esc_html_e( 'Production notes', 'persiano-hub' ); ?></strong></label><textarea class="widefat" rows="4" name="persiano_recipe_actual_notes"><?php echo esc_textarea( $actual_notes ); ?></textarea></p>
        <?php
    }

    public static function render_recipe_versions( $post ) {
        $versions = get_post_meta( $post->ID, self::RECIPE_VERSIONS, true );
        $versions = is_array( $versions ) ? array_reverse( $versions, true ) : array();
        if ( ! $versions ) {
            echo '<p>' . esc_html__( 'Saved recipe versions will appear here.', 'persiano-hub' ) . '</p>';
            return;
        }
        echo '<div class="ph-version-list">';
        $shown = 0;
        foreach ( $versions as $index => $version ) {
            if ( $shown >= 8 ) { break; }
            $time = ! empty( $version['time'] ) ? wp_date( 'M j, Y g:i a', absint( $version['time'] ) ) : '';
            $url = wp_nonce_url( add_query_arg( array( 'action' => 'persiano_hub_restore_recipe_version', 'recipe_id' => $post->ID, 'version_index' => $index ), admin_url( 'admin-post.php' ) ), 'persiano_hub_restore_recipe_version_' . $post->ID . '_' . $index );
            echo '<div class="ph-version-entry"><strong>' . esc_html( sprintf( __( 'Version %d', 'persiano-hub' ), isset( $version['number'] ) ? (int) $version['number'] : ( $index + 1 ) ) ) . '</strong><small>' . esc_html( $time ) . '</small><a href="' . esc_url( $url ) . '" onclick="return confirm(\'' . esc_js( __( 'Restore this recipe version? The current recipe will be saved as a new version first.', 'persiano-hub' ) ) . '\');">' . esc_html__( 'Restore', 'persiano-hub' ) . '</a></div>';
            $shown++;
        }
        echo '</div>';
    }

    public static function save_recipe( $post_id, $post, $update ) {
        if ( ! self::can_save( $post_id, 'persiano_hub_recipe_kitchen_nonce', 'persiano_hub_save_recipe_kitchen' ) ) {
            return;
        }
        $category = isset( $_POST['persiano_recipe_category'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_recipe_category'] ) ) : '';
        $serving_note = isset( $_POST['persiano_recipe_serving_note'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_recipe_serving_note'] ) ) : '';
        $prep_minutes = isset( $_POST['persiano_recipe_prep_minutes'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_prep_minutes'] ) ) ) : 0;
        $passive_minutes = isset( $_POST['persiano_recipe_passive_minutes'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_passive_minutes'] ) ) ) : 0;
        $storage = isset( $_POST['persiano_recipe_storage'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_recipe_storage'] ) ) : '';
        $freezing = isset( $_POST['persiano_recipe_freezing'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_recipe_freezing'] ) ) : '';
        $equipment = isset( $_POST['persiano_recipe_equipment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_recipe_equipment'] ) ) : '';
        $media_raw = isset( $_POST['persiano_recipe_media_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_recipe_media_ids'] ) ) : '';
        $media_ids = array_values( array_filter( array_map( 'absint', explode( ',', $media_raw ) ) ) );
        $actual_cost = isset( $_POST['persiano_recipe_actual_batch_cost'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_actual_batch_cost'] ) ) ) : 0;
        $actual_yield = isset( $_POST['persiano_recipe_actual_yield'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_actual_yield'] ) ) ) : 0;
        $actual_notes = isset( $_POST['persiano_recipe_actual_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_recipe_actual_notes'] ) ) : '';

        $steps = array();
        if ( isset( $_POST['persiano_recipe_steps'] ) && is_array( $_POST['persiano_recipe_steps'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            foreach ( wp_unslash( $_POST['persiano_recipe_steps'] ) as $step ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( ! is_array( $step ) ) { continue; }
                $title = isset( $step['title'] ) ? sanitize_text_field( $step['title'] ) : '';
                $instruction = isset( $step['instruction'] ) ? sanitize_textarea_field( $step['instruction'] ) : '';
                $minutes = isset( $step['minutes'] ) ? max( 0, self::decimal( $step['minutes'] ) ) : 0;
                $time_type = isset( $step['time_type'] ) && 'passive' === sanitize_key( $step['time_type'] ) ? 'passive' : 'active';
                $temperature = isset( $step['temperature'] ) ? sanitize_text_field( $step['temperature'] ) : '';
                $equipment = isset( $step['equipment'] ) ? sanitize_text_field( $step['equipment'] ) : '';
                $critical = ! empty( $step['critical'] ) ? 1 : 0;
                $internal_note = isset( $step['internal_note'] ) ? sanitize_textarea_field( $step['internal_note'] ) : '';
                $media_id = isset( $step['media_id'] ) ? absint( $step['media_id'] ) : 0;
                if ( '' === $instruction && '' === $title && ! $minutes && ! $temperature && ! $equipment && ! $media_id ) { continue; }
                $steps[] = compact( 'title', 'instruction', 'minutes', 'time_type', 'temperature', 'equipment', 'critical', 'internal_note', 'media_id' );
            }
        }

        update_post_meta( $post_id, self::RECIPE_CATEGORY, $category );
        update_post_meta( $post_id, self::RECIPE_SERVING_NOTE, $serving_note );
        update_post_meta( $post_id, self::RECIPE_PREP_MINUTES, $prep_minutes );
        update_post_meta( $post_id, self::RECIPE_PASSIVE_MINUTES, $passive_minutes );
        update_post_meta( $post_id, self::RECIPE_STORAGE, $storage );
        update_post_meta( $post_id, self::RECIPE_FREEZING, $freezing );
        update_post_meta( $post_id, self::RECIPE_EQUIPMENT, $equipment );
        update_post_meta( $post_id, self::RECIPE_MEDIA_IDS, $media_ids );
        update_post_meta( $post_id, self::RECIPE_STEPS, $steps );
        update_post_meta( $post_id, self::RECIPE_ACTUAL_COST, $actual_cost );
        update_post_meta( $post_id, self::RECIPE_ACTUAL_YIELD, $actual_yield );
        update_post_meta( $post_id, self::RECIPE_ACTUAL_NOTES, $actual_notes );

        /* Costing saves earlier at priority 10. Recalculate after actual production data is saved at priority 40. */
        Persiano_Hub_Costing::recalculate_recipe( $post_id );
        self::snapshot_recipe_version( $post_id );
    }

    private static function version_meta_keys() {
        return array(
            Persiano_Hub_Costing::RECIPE_PRODUCT_ID,
            Persiano_Hub_Costing::RECIPE_YIELD_QTY,
            Persiano_Hub_Costing::RECIPE_YIELD_LABEL,
            Persiano_Hub_Costing::RECIPE_ITEMS,
            Persiano_Hub_Costing::RECIPE_PACKAGING_COST,
            Persiano_Hub_Costing::RECIPE_LABOUR_MINUTES,
            Persiano_Hub_Costing::RECIPE_LABOUR_RATE,
            Persiano_Hub_Costing::RECIPE_OTHER_COST,
            Persiano_Hub_Costing::RECIPE_MISC_COST,
            Persiano_Hub_Costing::RECIPE_CONTINGENCY_PCT,
            Persiano_Hub_Costing::RECIPE_PROCESSING_FEE_PCT,
            Persiano_Hub_Costing::RECIPE_OVERHEAD_PCT,
            Persiano_Hub_Costing::RECIPE_TARGET_COST_PCT,
            self::RECIPE_CATEGORY,
            self::RECIPE_SERVING_NOTE,
            self::RECIPE_PREP_MINUTES,
            self::RECIPE_PASSIVE_MINUTES,
            self::RECIPE_STORAGE,
            self::RECIPE_FREEZING,
            self::RECIPE_EQUIPMENT,
            self::RECIPE_STEPS,
            self::RECIPE_MEDIA_IDS,
            self::RECIPE_ACTUAL_COST,
            self::RECIPE_ACTUAL_YIELD,
            self::RECIPE_ACTUAL_NOTES,
        );
    }

    private static function snapshot_recipe_version( $post_id ) {
        if ( Persiano_Hub_Costing::RECIPE_POST_TYPE !== get_post_type( $post_id ) ) { return; }
        $snapshot = array( 'title' => get_the_title( $post_id ), 'meta' => array() );
        foreach ( self::version_meta_keys() as $key ) {
            $snapshot['meta'][ $key ] = get_post_meta( $post_id, $key, true );
        }
        $hash = md5( wp_json_encode( $snapshot ) );
        $old_hash = get_post_meta( $post_id, self::RECIPE_VERSION_HASH, true );
        if ( $hash === $old_hash ) { return; }
        $versions = get_post_meta( $post_id, self::RECIPE_VERSIONS, true );
        $versions = is_array( $versions ) ? $versions : array();
        $snapshot['time'] = time();
        $snapshot['user_id'] = get_current_user_id();
        $snapshot['number'] = count( $versions ) + 1;
        $versions[] = $snapshot;
        if ( count( $versions ) > 30 ) { $versions = array_slice( $versions, -30 ); }
        update_post_meta( $post_id, self::RECIPE_VERSIONS, $versions );
        update_post_meta( $post_id, self::RECIPE_VERSION_HASH, $hash );
    }

    public static function duplicate_recipe() {
        $recipe_id = isset( $_GET['recipe_id'] ) ? absint( $_GET['recipe_id'] ) : 0;
        if ( ! $recipe_id || ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Invalid recipe.', 'persiano-hub' ), 403 ); }
        check_admin_referer( 'persiano_hub_duplicate_recipe_' . $recipe_id );
        $source = get_post( $recipe_id );
        if ( ! $source || Persiano_Hub_Costing::RECIPE_POST_TYPE !== $source->post_type ) { wp_die( esc_html__( 'Recipe not found.', 'persiano-hub' ), 404 ); }
        $new_id = wp_insert_post( array( 'post_type' => Persiano_Hub_Costing::RECIPE_POST_TYPE, 'post_status' => 'draft', 'post_title' => sprintf( __( 'Copy of %s', 'persiano-hub' ), $source->post_title ) ), true );
        if ( is_wp_error( $new_id ) ) { wp_die( esc_html( $new_id->get_error_message() ) ); }
        foreach ( self::version_meta_keys() as $key ) {
            if ( Persiano_Hub_Costing::RECIPE_PRODUCT_ID === $key ) { continue; }
            $value = get_post_meta( $recipe_id, $key, true );
            if ( '' !== $value && array() !== $value ) { update_post_meta( $new_id, $key, $value ); }
        }
        Persiano_Hub_Costing::recalculate_recipe( $new_id );
        wp_safe_redirect( get_edit_post_link( $new_id, 'raw' ) );
        exit;
    }

    public static function restore_recipe_version() {
        $recipe_id = isset( $_GET['recipe_id'] ) ? absint( $_GET['recipe_id'] ) : 0;
        $index = isset( $_GET['version_index'] ) ? absint( $_GET['version_index'] ) : -1;
        if ( ! $recipe_id || $index < 0 || ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Invalid recipe version.', 'persiano-hub' ), 403 ); }
        check_admin_referer( 'persiano_hub_restore_recipe_version_' . $recipe_id . '_' . $index );
        self::snapshot_recipe_version( $recipe_id );
        $versions = get_post_meta( $recipe_id, self::RECIPE_VERSIONS, true );
        if ( ! is_array( $versions ) || empty( $versions[ $index ]['meta'] ) ) { wp_die( esc_html__( 'Recipe version not found.', 'persiano-hub' ), 404 ); }
        $version = $versions[ $index ];
        $old_product_id = absint( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_PRODUCT_ID, true ) );
        if ( isset( $version['title'] ) ) { wp_update_post( array( 'ID' => $recipe_id, 'post_title' => sanitize_text_field( $version['title'] ) ) ); }
        foreach ( self::version_meta_keys() as $key ) {
            if ( array_key_exists( $key, $version['meta'] ) ) { update_post_meta( $recipe_id, $key, $version['meta'][ $key ] ); }
        }
        $new_product_id = absint( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_PRODUCT_ID, true ) );
        if ( $old_product_id && $old_product_id !== $new_product_id && absint( get_post_meta( $old_product_id, Persiano_Hub_Costing::PRODUCT_RECIPE_META, true ) ) === $recipe_id ) {
            delete_post_meta( $old_product_id, Persiano_Hub_Costing::PRODUCT_RECIPE_META );
        }
        if ( $new_product_id ) {
            update_post_meta( $new_product_id, Persiano_Hub_Costing::PRODUCT_RECIPE_META, $recipe_id );
        }
        delete_post_meta( $recipe_id, self::RECIPE_VERSION_HASH );
        Persiano_Hub_Costing::recalculate_recipe( $recipe_id );
        wp_safe_redirect( add_query_arg( 'persiano_restored', '1', get_edit_post_link( $recipe_id, 'raw' ) ) );
        exit;
    }

    private static function rounding_options() {
        return array(
            'exact'    => __( 'Exact', 'persiano-hub' ),
            'whole_up' => __( 'Whole units — round up', 'persiano-hub' ),
            'half_up'  => __( 'Nearest 0.5 — round up', 'persiano-hub' ),
            '5'        => __( 'Nearest 5 units', 'persiano-hub' ),
            '10'       => __( 'Nearest 10 units', 'persiano-hub' ),
            '25'       => __( 'Nearest 25 units', 'persiano-hub' ),
            '50'       => __( 'Nearest 50 units', 'persiano-hub' ),
            '100'      => __( 'Nearest 100 units', 'persiano-hub' ),
        );
    }

    public static function scale_quantity( $qty, $factor, $rounding = 'exact' ) {
        $scaled = max( 0, (float) $qty * (float) $factor );
        if ( 'whole_up' === $rounding ) { return ceil( $scaled ); }
        if ( 'half_up' === $rounding ) { return ceil( $scaled * 2 ) / 2; }
        if ( is_numeric( $rounding ) && (float) $rounding > 0 ) {
            $step = (float) $rounding;
            return ceil( $scaled / $step ) * $step;
        }
        return round( $scaled, 3 );
    }

    private static function print_permission_check( $recipe_id, $action ) {
        if ( ! $recipe_id || ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Invalid recipe.', 'persiano-hub' ), 403 ); }
        check_admin_referer( $action . '_' . $recipe_id );
        $post = get_post( $recipe_id );
        if ( ! $post || Persiano_Hub_Costing::RECIPE_POST_TYPE !== $post->post_type ) { wp_die( esc_html__( 'Recipe not found.', 'persiano-hub' ), 404 ); }
        return $post;
    }

    private static function scaled_recipe_rows( $recipe_id, $target_yield ) {
        $base_yield = max( 0.01, self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 ) );
        $factor = max( 0.0001, $target_yield / $base_yield );
        $items = get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_ITEMS, true );
        $items = is_array( $items ) ? $items : array();
        $rows = array();
        foreach ( $items as $item ) {
            $source_type = isset( $item['source_type'] ) ? sanitize_key( $item['source_type'] ) : 'ingredient';
            $source_id = isset( $item['source_id'] ) ? absint( $item['source_id'] ) : ( isset( $item['ingredient_id'] ) ? absint( $item['ingredient_id'] ) : 0 );
            if ( ! $source_id ) { continue; }
            $qty = isset( $item['qty'] ) ? self::decimal( $item['qty'] ) : 0;
            $rounding = isset( $item['rounding'] ) ? sanitize_key( $item['rounding'] ) : 'exact';
            $rows[] = array(
                'name' => get_the_title( $source_id ),
                'source_type' => $source_type,
                'qty' => self::scale_quantity( $qty, $factor, $rounding ),
                'unit' => isset( $item['unit'] ) ? sanitize_key( $item['unit'] ) : '',
                'prep_note' => isset( $item['prep_note'] ) ? sanitize_text_field( $item['prep_note'] ) : '',
            );
        }
        return $rows;
    }

    public static function print_recipe() {
        $recipe_id = isset( $_GET['recipe_id'] ) ? absint( $_GET['recipe_id'] ) : 0;
        $post = self::print_permission_check( $recipe_id, 'persiano_hub_print_recipe' );
        $base_yield = max( 0.01, self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 ) );
        $target_yield = isset( $_GET['target_yield'] ) ? max( 0.01, self::decimal( wp_unslash( $_GET['target_yield'] ), $base_yield ) ) : $base_yield;
        $yield_label = Persiano_Hub_Costing::canonical_recipe_unit( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) );
        $serving_note = get_post_meta( $recipe_id, self::RECIPE_SERVING_NOTE, true );
        $rows = self::scaled_recipe_rows( $recipe_id, $target_yield );
        $steps = self::get_steps( $recipe_id );
        $prep = self::decimal( get_post_meta( $recipe_id, self::RECIPE_PREP_MINUTES, true ) );
        $active = self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_LABOUR_MINUTES, true ) );
        $passive = self::decimal( get_post_meta( $recipe_id, self::RECIPE_PASSIVE_MINUTES, true ) );
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
        ?><!doctype html><html><head><meta charset="<?php bloginfo( 'charset' ); ?>"><title><?php echo esc_html( $post->post_title ); ?></title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#222;max-width:900px;margin:36px auto;padding:0 24px}h1{margin-bottom:4px}.meta{display:flex;gap:18px;flex-wrap:wrap;padding:12px 0 20px;border-bottom:2px solid #222}.meta span{font-weight:600}table{width:100%;border-collapse:collapse;margin:24px 0}th,td{text-align:left;padding:9px;border-bottom:1px solid #ddd}.steps{counter-reset:step}.step{display:grid;grid-template-columns:40px 1fr 120px;gap:12px;padding:14px 0;border-bottom:1px solid #ddd}.step:before{counter-increment:step;content:counter(step);font-size:22px;font-weight:700}.notes{background:#f6f6f6;padding:14px;margin-top:20px}@media print{.no-print{display:none}body{margin:0;max-width:none}}</style></head><body>
        <div class="no-print"><button onclick="window.print()">Print / Save as PDF</button></div>
        <h1><?php echo esc_html( $post->post_title ); ?></h1><p><?php echo esc_html( $serving_note ); ?></p>
        <div class="meta"><span><?php echo esc_html( sprintf( __( 'Production yield: %1$s %2$s', 'persiano-hub' ), $target_yield, $yield_label ) ); ?></span><span><?php echo esc_html( sprintf( __( 'Prep: %s min', 'persiano-hub' ), $prep ) ); ?></span><span><?php echo esc_html( sprintf( __( 'Active: %s min', 'persiano-hub' ), $active ) ); ?></span><span><?php echo esc_html( sprintf( __( 'Passive: %s min', 'persiano-hub' ), $passive ) ); ?></span></div>
        <h2><?php esc_html_e( 'Ingredients', 'persiano-hub' ); ?></h2><table><thead><tr><th><?php esc_html_e( 'Ingredient / component', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Quantity', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Preparation', 'persiano-hub' ); ?></th></tr></thead><tbody><?php foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( $row['name'] ); ?></td><td><?php echo esc_html( $row['qty'] . ' ' . $row['unit'] ); ?></td><td><?php echo esc_html( $row['prep_note'] ); ?></td></tr><?php endforeach; ?></tbody></table>
        <h2><?php esc_html_e( 'Method', 'persiano-hub' ); ?></h2><div class="steps"><?php foreach ( $steps as $step ) : ?><div class="step"><div><?php echo esc_html( $step['instruction'] ?? '' ); ?></div><small><?php echo esc_html( trim( ( $step['minutes'] ?? 0 ) . ' min ' . ( $step['time_type'] ?? '' ) . ' ' . ( $step['temperature'] ?? '' ) ) ); ?></small></div><?php endforeach; ?></div>
        <?php $storage = get_post_meta( $recipe_id, self::RECIPE_STORAGE, true ); $freezing = get_post_meta( $recipe_id, self::RECIPE_FREEZING, true ); if ( $storage || $freezing ) : ?><div class="notes"><strong><?php esc_html_e( 'Storage / reheating notes', 'persiano-hub' ); ?></strong><p><?php echo nl2br( esc_html( trim( $storage . "\n" . $freezing ) ) ); ?></p></div><?php endif; ?>
        </body></html><?php
        exit;
    }

    public static function export_recipe_csv() {
        $recipe_id = isset( $_GET['recipe_id'] ) ? absint( $_GET['recipe_id'] ) : 0;
        $post = self::print_permission_check( $recipe_id, 'persiano_hub_export_recipe' );
        $base_yield = max( 0.01, self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 ) );
        $target_yield = isset( $_GET['target_yield'] ) ? max( 0.01, self::decimal( wp_unslash( $_GET['target_yield'] ), $base_yield ) ) : $base_yield;
        $rows = self::scaled_recipe_rows( $recipe_id, $target_yield );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $post->post_name . '-' . $target_yield . '-production.csv' ) . '"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Recipe', $post->post_title ) );
        fputcsv( $out, array( 'Target yield', $target_yield, get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) ) );
        fputcsv( $out, array() );
        fputcsv( $out, array( 'Ingredient / component', 'Quantity', 'Unit', 'Preparation' ) );
        foreach ( $rows as $row ) { fputcsv( $out, array( $row['name'], $row['qty'], $row['unit'], $row['prep_note'] ) ); }
        fputcsv( $out, array() );
        fputcsv( $out, array( 'Step', 'Instruction', 'Minutes', 'Type', 'Temperature' ) );
        foreach ( self::get_steps( $recipe_id ) as $index => $step ) { fputcsv( $out, array( $index + 1, $step['instruction'] ?? '', $step['minutes'] ?? '', $step['time_type'] ?? '', $step['temperature'] ?? '' ) ); }
        fclose( $out );
        exit;
    }

    public static function render_production_planner() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : wp_date( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : wp_date( 'Y-m-d', strtotime( '+7 days', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $requirements = self::production_requirements( $from, $to );
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Kitchen planning', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Production Planner', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Build a consolidated ingredient requirement list from upcoming WooCommerce orders that are linked to Persiano recipes.', 'persiano-hub' ); ?></p></div></div>
        <section class="ph-costing-panel"><form method="get"><input type="hidden" name="page" value="<?php echo esc_attr( Persiano_Hub_Costing::MENU_SLUG ); ?>"><input type="hidden" name="tab" value="production"><label><?php esc_html_e( 'From', 'persiano-hub' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label> <label><?php esc_html_e( 'To', 'persiano-hub' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label> <?php submit_button( __( 'Build production plan', 'persiano-hub' ), 'secondary', '', false ); ?></form></section>
        <?php if ( ! empty( $requirements['warnings'] ) ) : ?><section class="ph-costing-panel"><h2><?php esc_html_e( 'Needs attention', 'persiano-hub' ); ?></h2><ul><?php foreach ( $requirements['warnings'] as $warning ) : ?><li><?php echo esc_html( $warning ); ?></li><?php endforeach; ?></ul></section><?php endif; ?>
        <section class="ph-costing-panel"><h2><?php esc_html_e( 'Production quantities', 'persiano-hub' ); ?></h2>
        <?php if ( empty( $requirements['recipes'] ) ) : ?><p><?php esc_html_e( 'No linked recipe quantities were found for this date range.', 'persiano-hub' ); ?></p><?php else : ?><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Recipe', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Required yield', 'persiano-hub' ); ?></th></tr></thead><tbody><?php foreach ( $requirements['recipes'] as $recipe_id => $qty ) : ?><tr><td><a href="<?php echo esc_url( get_edit_post_link( $recipe_id ) ); ?>"><?php echo esc_html( get_the_title( $recipe_id ) ); ?></a></td><td><?php echo esc_html( $qty . ' ' . get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) ); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        </section>
        <section class="ph-costing-panel"><h2><?php esc_html_e( 'Consolidated ingredient requirements', 'persiano-hub' ); ?></h2>
        <?php if ( empty( $requirements['ingredients'] ) ) : ?><p><?php esc_html_e( 'No ingredient requirements available yet.', 'persiano-hub' ); ?></p><?php else : ?><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Ingredient', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Required', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'On hand', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Shopping shortage', 'persiano-hub' ); ?></th></tr></thead><tbody><?php foreach ( $requirements['ingredients'] as $ingredient_id => $data ) : ?><tr><td><a href="<?php echo esc_url( get_edit_post_link( $ingredient_id ) ); ?>"><?php echo esc_html( get_the_title( $ingredient_id ) ); ?></a></td><td><?php echo esc_html( round( $data['required'], 2 ) . ' ' . $data['base_unit'] ); ?></td><td><?php echo esc_html( round( $data['on_hand'], 2 ) . ' ' . $data['base_unit'] ); ?></td><td><strong><?php echo $data['shortage'] > 0 ? esc_html( round( $data['shortage'], 2 ) . ' ' . $data['base_unit'] ) : esc_html__( 'Covered', 'persiano-hub' ); ?></strong></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        </section>
        <?php
    }

    public static function production_requirements( $from, $to ) {
        $recipes = array();
        $ingredients = array();
        $warnings = array();
        $included_orders = array();
        $components = array();
        $component_pool = array();
        if ( ! function_exists( 'wc_get_orders' ) ) { return compact( 'recipes', 'ingredients', 'components', 'warnings', 'included_orders' ); }

        $orders = wc_get_orders( array(
            'limit'   => -1,
            'type'    => 'shop_order',
            'status'  => array( 'processing', 'on-hold', 'pending', 'completed' ),
            'orderby' => 'date',
            'order'   => 'DESC',
        ) );
        $from_ts = strtotime( $from . ' 00:00:00' );
        $to_ts = strtotime( $to . ' 23:59:59' );

        foreach ( $orders as $order ) {
            if ( ! is_a( $order, 'WC_Order' ) ) { continue; }
            $order_used = false;
            $shared_service_ts = self::order_shared_service_timestamp( $order );
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $service_ts = self::order_item_explicit_service_timestamp( $item );
                if ( ! $service_ts ) { $service_ts = $shared_service_ts; }
                if ( ! $service_ts || $service_ts < $from_ts || $service_ts > $to_ts ) { continue; }

                $product_id = absint( $item->get_variation_id() ?: $item->get_product_id() );
                $parent_id = absint( $item->get_product_id() );
                $recipe_id = self::linked_recipe_for_product( $product_id );
                if ( ! $recipe_id && $parent_id && $parent_id !== $product_id ) {
                    $recipe_id = self::linked_recipe_for_product( $parent_id );
                }
                if ( ! $recipe_id ) {
                    $warnings[] = sprintf( 'Order #%1$s: “%2$s” (product #%3$d) is not linked to a recipe.', $order->get_order_number(), $item->get_name(), $product_id ?: $parent_id );
                    continue;
                }

                $per_item_yield = self::product_yield_for_recipe( $product_id ?: $parent_id, $recipe_id );
                if ( $per_item_yield <= 0 ) {
                    $per_item_yield = self::fallback_yield_per_product( $recipe_id );
                    $warnings[] = sprintf( 'Order #%1$s: package/yield could not be read for “%2$s”; recipe fallback was used.', $order->get_order_number(), $item->get_name() );
                }
                $required_yield = max( 0, (float) $item->get_quantity() * $per_item_yield );
                if ( $required_yield <= 0 ) { continue; }
                $recipes[ $recipe_id ] = isset( $recipes[ $recipe_id ] ) ? $recipes[ $recipe_id ] + $required_yield : $required_yield;
                $order_used = true;
            }
            if ( $order_used ) { $included_orders[] = $order->get_id(); }
        }

        foreach ( $recipes as $recipe_id => $required_yield ) {
            self::accumulate_recipe_ingredients( $recipe_id, $required_yield, $ingredients, array(), $components, $component_pool, true, 0 );
        }
        foreach ( $ingredients as $ingredient_id => &$data ) {
            $on_qty = self::decimal( get_post_meta( $ingredient_id, self::ING_ON_HAND_QTY, true ) );
            $on_unit = sanitize_key( get_post_meta( $ingredient_id, self::ING_ON_HAND_UNIT, true ) );
            $base_unit = sanitize_key( get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BASE_UNIT, true ) );
            $normalized = self::normalize_ingredient_amount( $ingredient_id, $on_qty, $on_unit, $base_unit );
            $data['on_hand'] = null === $normalized ? 0 : $normalized;
            $data['purchasable'] = 'process' !== get_post_meta( $ingredient_id, '_persiano_ingredient_type', true );
            $data['shortage'] = $data['purchasable'] ? max( 0, $data['required'] - $data['on_hand'] ) : 0;
        }
        unset( $data );
        $warnings = array_values( array_unique( array_filter( $warnings ) ) );
        self::$last_component_requirements = $components;
        return compact( 'recipes', 'ingredients', 'components', 'warnings', 'included_orders' );
    }

    private static function order_item_explicit_service_timestamp( $item ) {
        foreach ( array( 'Requested for', '_persiano_requested_for', '_persiano_fulfilment_date', '_persiano_fulfillment_date', '_persiano_requested_date', '_persiano_requested_datetime' ) as $key ) {
            $value = $item->get_meta( $key, true );
            if ( $value ) { $ts = strtotime( (string) $value ); if ( $ts ) { return $ts; } }
        }
        foreach ( $item->get_meta_data() as $meta ) {
            $key = (string) $meta->key;
            if ( false !== stripos( $key, 'requested for' ) || false !== stripos( $key, 'requested date' ) || false !== stripos( $key, 'fulfilment date' ) || false !== stripos( $key, 'fulfillment date' ) ) {
                $ts = strtotime( (string) $meta->value );
                if ( $ts ) { return $ts; }
            }
        }
        return 0;
    }

    private static function order_shared_service_timestamp( $order ) {
        foreach ( array(
            '_persiano_fulfilment_date', '_persiano_fulfillment_date', '_persiano_requested_date',
            '_persiano_manual_fulfilment_date', '_persiano_requested_fulfilment_date',
            '_persiano_requested_fulfillment_date', '_persiano_requested_datetime',
            '_persiano_advance_requested_date', '_persiano_service_date'
        ) as $key ) {
            $value = $order->get_meta( $key, true );
            if ( $value ) { $ts = strtotime( (string) $value ); if ( $ts ) { return $ts; } }
        }
        foreach ( $order->get_meta_data() as $meta ) {
            $key = (string) $meta->key;
            if ( false !== stripos( $key, 'requested for' ) || false !== stripos( $key, 'requested date' ) || false !== stripos( $key, 'fulfilment date' ) || false !== stripos( $key, 'fulfillment date' ) ) {
                $ts = strtotime( (string) $meta->value );
                if ( $ts ) { return $ts; }
            }
        }

        /* A manual order can contain items added through different screens. If one
         * line contains the requested date, apply that date to the complete order
         * unless another line has its own explicit override. This prevents only a
         * subset of one order from entering the production plan. */
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $ts = self::order_item_explicit_service_timestamp( $item );
            if ( $ts ) { return $ts; }
        }

        /* Product availability is only a last-resort order-level hint. Never apply
         * it to one line while excluding sibling lines from the same order. */
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $product_id = absint( $item->get_variation_id() ?: $item->get_product_id() );
            $available = $product_id ? get_post_meta( $product_id, Persiano_Hub_Product_Fields::META_AVAILABLE_DATE, true ) : '';
            if ( ! $available && $item->get_product_id() ) { $available = get_post_meta( $item->get_product_id(), Persiano_Hub_Product_Fields::META_AVAILABLE_DATE, true ); }
            if ( $available ) {
                $ts = strtotime( $available . ( preg_match( '/\\d{1,2}:\\d{2}/', $available ) ? '' : ' 12:00:00' ) );
                if ( $ts ) { return $ts; }
            }
        }
        return $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
    }

    private static function linked_recipe_for_product( $product_id ) {
        $product_id = absint( $product_id );
        if ( ! $product_id ) { return 0; }
        $recipe_keys = array(
            Persiano_Hub_Costing::PRODUCT_RECIPE_META,
            '_persiano_recipe_link',
            '_persiano_linked_recipe_id',
        );
        foreach ( array_unique( $recipe_keys ) as $recipe_key ) {
            $recipe_id = absint( get_post_meta( $product_id, $recipe_key, true ) );
            if ( $recipe_id && Persiano_Hub_Costing::RECIPE_POST_TYPE === get_post_type( $recipe_id ) ) {
                if ( Persiano_Hub_Costing::PRODUCT_RECIPE_META !== $recipe_key ) {
                    update_post_meta( $product_id, Persiano_Hub_Costing::PRODUCT_RECIPE_META, $recipe_id );
                }
                return $recipe_id;
            }
        }
        $ids = get_posts( array(
            'post_type'      => Persiano_Hub_Costing::RECIPE_POST_TYPE,
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'OR',
                array( 'key' => Persiano_Hub_Costing::RECIPE_PRODUCT_ID, 'value' => $product_id ),
                array( 'key' => '_persiano_linked_product_id', 'value' => $product_id ),
            ),
        ) );
        return $ids ? absint( $ids[0] ) : 0;
    }

    private static function fallback_yield_per_product( $recipe_id ) {
        $base_yield = max( 0.01, self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 ) );
        $portions = max( 0, self::decimal( get_post_meta( $recipe_id, '_persiano_recipe_portions', true ), 0 ) );
        return $portions > 0 ? $base_yield / $portions : $base_yield;
    }

    private static function product_yield_for_recipe( $product_id, $recipe_id ) {
        $product_id = absint( $product_id );
        $recipe_unit = self::canonical_yield_unit( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) );

        /* Count-based recipes represent sellable units. One WooCommerce line-item
         * quantity therefore equals one jar/container/portion, regardless of a
         * descriptive label such as "Jar (250 g)" or the product's package size.
         * The recipe batch yield is used later as the denominator when ingredients
         * are scaled; it must never be multiplied into order demand. */
        if ( 'each' === $recipe_unit ) { return 1; }

        $size = $product_id ? trim( (string) get_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SIZE, true ) ) : '';
        if ( $size && preg_match( '/([0-9]+(?:[.,][0-9]+)?)\s*(kg|g|ml|mL|l|L|oz|lb|cup|tbsp|tsp|each|portion|portions|serves?)\b/u', $size, $m ) ) {
            $qty = (float) str_replace( ',', '.', $m[1] );
            $unit = strtolower( $m[2] );
            if ( in_array( $unit, array( 'portion', 'portions', 'serve', 'serves', 'jar', 'jars', 'container', 'containers', 'package', 'packages', 'tray', 'trays' ), true ) ) { $unit = 'each'; }
            $normalized = self::normalize_yield_amount( $qty, $unit, $recipe_unit );
            if ( null !== $normalized ) { return $normalized; }
        }
        if ( $product_id && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $product_id );
            if ( $product && $product->get_weight() ) {
                $store_unit = get_option( 'woocommerce_weight_unit', 'kg' );
                $normalized = self::normalize_yield_amount( (float) $product->get_weight(), $store_unit, $recipe_unit );
                if ( null !== $normalized ) { return $normalized; }
            }
        }
        return 0;
    }

    private static function canonical_yield_unit( $unit ) {
        $raw = strtolower( trim( wp_strip_all_tags( (string) $unit ) ) );
        $key = strtolower( sanitize_key( $raw ) );
        foreach ( array( 'each', 'portion', 'serving', 'jar', 'container', 'package', 'tray', 'unit', 'piece' ) as $count_word ) {
            if ( preg_match( '/\b' . preg_quote( $count_word, '/' ) . 's?\b/i', $raw ) || preg_match( '/(^|[-_])' . preg_quote( $count_word, '/' ) . 's?($|[-_])/', $key ) ) {
                return 'each';
            }
        }
        if ( preg_match( '/\b(kg|kilograms?)\b/i', $raw ) ) { return 'kg'; }
        if ( preg_match( '/\b(g|grams?)\b/i', $raw ) ) { return 'g'; }
        if ( preg_match( '/\b(ml|millilit(?:er|re)s?)\b/i', $raw ) ) { return 'ml'; }
        if ( preg_match( '/\b(l|lit(?:er|re)s?)\b/i', $raw ) ) { return 'l'; }
        return $key;
    }

    private static function normalize_yield_amount( $qty, $unit, $target_unit ) {
        $unit = self::canonical_yield_unit( $unit );
        $target_unit = self::canonical_yield_unit( $target_unit );
        $mass = array( 'g'=>1, 'kg'=>1000, 'oz'=>28.349523125, 'lb'=>453.59237 );
        $volume = array( 'ml'=>1, 'l'=>1000, 'tsp'=>4.92892159375, 'tbsp'=>14.78676478125, 'cup'=>236.5882365 );
        if ( isset( $mass[ $unit ], $mass[ $target_unit ] ) ) { return $qty * $mass[ $unit ] / $mass[ $target_unit ]; }
        if ( isset( $volume[ $unit ], $volume[ $target_unit ] ) ) { return $qty * $volume[ $unit ] / $volume[ $target_unit ]; }
        if ( 'each' === $unit && 'each' === $target_unit ) { return $qty; }
        return null;
    }


    /**
     * Build ingredient requirements from explicit recipe quantities.
     *
     * @param array<int,float> $recipe_quantities Recipe ID => required yield.
     * @return array<int,array<string,mixed>>
     */
    public static function requirements_from_recipe_quantities( $recipe_quantities, $exclude_plan_id = 0 ) {
        $ingredients = array();
        $components = array();
        $component_pool = array();
        foreach ( (array) $recipe_quantities as $recipe_id => $required_yield ) {
            $recipe_id = absint( $recipe_id );
            $required_yield = max( 0, self::decimal( $required_yield ) );
            if ( ! $recipe_id || $required_yield <= 0 ) {
                continue;
            }
            self::accumulate_recipe_ingredients( $recipe_id, $required_yield, $ingredients, array(), $components, $component_pool, true, absint( $exclude_plan_id ) );
        }
        foreach ( $ingredients as $ingredient_id => &$data ) {
            $on_qty = self::decimal( get_post_meta( $ingredient_id, self::ING_ON_HAND_QTY, true ) );
            $on_unit = get_post_meta( $ingredient_id, self::ING_ON_HAND_UNIT, true );
            $base_unit = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BASE_UNIT, true );
            $normalized = self::normalize_ingredient_amount( $ingredient_id, $on_qty, $on_unit, $base_unit );
            $data['on_hand'] = null === $normalized ? 0 : $normalized;
            $data['shortage'] = max( 0, $data['required'] - $data['on_hand'] );
        }
        unset( $data );
        self::$last_component_requirements = $components;
        return $ingredients;
    }

    public static function last_component_requirements() {
        return is_array( self::$last_component_requirements ) ? self::$last_component_requirements : array();
    }

    private static function normalize_ingredient_amount( $ingredient_id, $qty, $unit, $base_unit ) {
        $unit = strtolower( sanitize_key( $unit ) );
        $base_unit = strtolower( sanitize_key( $base_unit ) );
        $mass = array( 'g'=>1, 'kg'=>1000, 'oz'=>28.349523125, 'lb'=>453.59237 );
        $volume = array( 'ml'=>1, 'l'=>1000, 'tsp'=>4.92892159375, 'tbsp'=>14.78676478125, 'cup'=>236.5882365 );
        if ( isset( $mass[ $unit ] ) && 'g' === $base_unit ) { return $qty * $mass[ $unit ]; }
        if ( isset( $volume[ $unit ] ) && 'ml' === $base_unit ) { return $qty * $volume[ $unit ]; }
        if ( 'each' === $unit && 'each' === $base_unit ) { return $qty; }

        $density = self::decimal( get_post_meta( $ingredient_id, '_persiano_density_g_ml', true ) );
        $grams_per = array(
            'cup'  => self::decimal( get_post_meta( $ingredient_id, '_persiano_grams_per_cup', true ) ),
            'tbsp' => self::decimal( get_post_meta( $ingredient_id, '_persiano_grams_per_tbsp', true ) ),
            'tsp'  => self::decimal( get_post_meta( $ingredient_id, '_persiano_grams_per_tsp', true ) ),
        );
        if ( 'g' === $base_unit && isset( $grams_per[ $unit ] ) && $grams_per[ $unit ] > 0 ) { return $qty * $grams_per[ $unit ]; }
        if ( 'g' === $base_unit && isset( $volume[ $unit ] ) && $density > 0 ) { return $qty * $volume[ $unit ] * $density; }
        if ( 'ml' === $base_unit && isset( $mass[ $unit ] ) && $density > 0 ) { return ( $qty * $mass[ $unit ] ) / $density; }
        return null;
    }

    private static function accumulate_recipe_ingredients( $recipe_id, $required_yield, &$ingredients, $stack, &$components = null, &$component_pool = null, $is_root = true, $exclude_plan_id = 0 ) {
        if ( in_array( $recipe_id, $stack, true ) ) { return; }
        if ( null === $components ) { $components = array(); }
        if ( null === $component_pool ) { $component_pool = array(); }
        $stack[] = $recipe_id;
        $base_yield = max( 0.01, self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 ) );
        $factor = $required_yield / $base_yield;
        $items = get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_ITEMS, true );
        foreach ( (array) $items as $item ) {
            $type = isset( $item['source_type'] ) ? sanitize_key( $item['source_type'] ) : 'ingredient';
            $id = isset( $item['source_id'] ) ? absint( $item['source_id'] ) : ( isset( $item['ingredient_id'] ) ? absint( $item['ingredient_id'] ) : 0 );
            $qty = isset( $item['qty'] ) ? self::decimal( $item['qty'] ) : 0;
            $unit = isset( $item['unit'] ) ? sanitize_key( $item['unit'] ) : '';
            if ( ! $id || $qty <= 0 ) { continue; }
            if ( 'recipe' === $type ) {
                $sub_batch_yield = max( 0.01, self::decimal( get_post_meta( $id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 ) );
                $sub_yield_unit  = self::canonical_yield_unit( get_post_meta( $id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) );

                if ( 'batch' === $unit ) {
                    $sub_yield = $qty * $sub_batch_yield;
                } else {
                    $sub_yield = self::normalize_yield_amount( $qty, $unit, $sub_yield_unit );
                    if ( null === $sub_yield ) {
                        continue;
                    }
                }

                $component_required = max( 0, $sub_yield * $factor );
                $component_shortage = $component_required;
                $from_stock = 0.0;

                if ( class_exists( 'Persiano_Hub_Inventory' ) && Persiano_Hub_Inventory::recipe_is_component( $id ) ) {
                    if ( ! isset( $component_pool[ $id ] ) ) {
                        $availability = Persiano_Hub_Inventory::component_availability( $id, $sub_yield_unit, $exclude_plan_id );
                        $component_pool[ $id ] = max( 0, (float) $availability['available'] );
                        $components[ $id ] = array(
                            'recipe_id' => $id,
                            'required' => 0.0,
                            'unit' => $sub_yield_unit,
                            'on_hand' => (float) $availability['on_hand'],
                            'reserved' => (float) $availability['reserved'],
                            'available' => (float) $availability['available'],
                            'from_stock' => 0.0,
                            'to_produce' => 0.0,
                        );
                    }
                    $from_stock = min( $component_required, max( 0, (float) $component_pool[ $id ] ) );
                    $component_pool[ $id ] = max( 0, (float) $component_pool[ $id ] - $from_stock );
                    $component_shortage = max( 0, $component_required - $from_stock );
                    $components[ $id ]['required'] += $component_required;
                    $components[ $id ]['from_stock'] += $from_stock;
                    $components[ $id ]['to_produce'] += $component_shortage;
                }

                if ( $component_shortage > 0 ) {
                    self::accumulate_recipe_ingredients( $id, $component_shortage, $ingredients, $stack, $components, $component_pool, false, $exclude_plan_id );
                }
                continue;
            }
            $base_unit = get_post_meta( $id, Persiano_Hub_Costing::ING_BASE_UNIT, true );
            $normalized = self::normalize_ingredient_amount( $id, $qty * $factor, $unit, $base_unit );
            if ( null === $normalized ) { continue; }
            if ( ! isset( $ingredients[ $id ] ) ) { $ingredients[ $id ] = array( 'required' => 0, 'base_unit' => $base_unit, 'on_hand' => 0, 'shortage' => 0 ); }
            $ingredients[ $id ]['required'] += $normalized;
        }
    }

    public static function admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) { return; }
        $is_ing = Persiano_Hub_Costing::INGREDIENT_POST_TYPE === $screen->post_type;
        $is_recipe = Persiano_Hub_Costing::RECIPE_POST_TYPE === $screen->post_type;
        if ( ! $is_ing && ! $is_recipe ) { return; }
        wp_enqueue_media();

        $brands = self::distinct_meta_values( Persiano_Hub_Costing::ING_BRAND, Persiano_Hub_Costing::INGREDIENT_POST_TYPE );
        $vendors = self::distinct_meta_values( Persiano_Hub_Costing::ING_SUPPLIER, Persiano_Hub_Costing::INGREDIENT_POST_TYPE );
        $categories = self::distinct_meta_values( Persiano_Hub_Costing::ING_CATEGORY, Persiano_Hub_Costing::INGREDIENT_POST_TYPE );
        $titles = array_map( 'get_the_title', self::all_ingredient_titles() );
        add_action( 'admin_footer', function() use ( $brands, $vendors, $categories, $titles, $is_ing ) {
            if ( ! $is_ing ) { return; }
            self::render_datalist( 'ph-ing-brand-list', $brands );
            self::render_datalist( 'ph-ing-vendor-list', $vendors );
            self::render_datalist( 'ph-ing-category-list', $categories );
            self::render_datalist( 'ph-ing-title-list', $titles );
        } );

        $js = <<<'JS'
(function($){
    function attachIngredientLists(){
        $('#persiano_ing_brand').attr('list','ph-ing-brand-list');
        $('#persiano_ing_supplier').attr('list','ph-ing-vendor-list');
        $('#persiano_ing_category').attr('list','ph-ing-category-list');
        if($('body').hasClass('post-type-persiano_ing')){ $('#title').attr('list','ph-ing-title-list'); }
    }
    function renderMediaPreview($picker, attachments){
        var multiple = String($picker.data('multiple')) === '1';
        var ids = [];
        var html = '';
        attachments.forEach(function(att){
            ids.push(att.id);
            var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : (att.icon || att.url);
            html += '<div data-id="'+att.id+'"><img src="'+url+'" alt=""></div>';
        });
        if(!multiple && ids.length){ ids = [ids[0]]; html = html.split('</div>')[0]+'</div>'; }
        $picker.find('.ph-kitchen-media-ids').val(ids.join(','));
        $picker.find('.ph-kitchen-media-preview').html(html || '<span class="ph-media-empty">No media selected</span>');
    }
    $(document).on('click','.ph-kitchen-choose-media',function(e){
        e.preventDefault();
        var $picker = $(this).closest('.ph-kitchen-media-picker');
        var multiple = String($picker.data('multiple')) === '1';
        var frame = wp.media({title: multiple ? 'Choose recipe media' : 'Choose media', button:{text:'Use selected media'}, multiple:multiple});
        frame.on('select',function(){
            var attachments = frame.state().get('selection').toJSON();
            renderMediaPreview($picker,attachments);
        });
        frame.open();
    });
    $(document).on('click','.ph-kitchen-clear-media',function(e){ e.preventDefault(); var $p=$(this).closest('.ph-kitchen-media-picker'); $p.find('.ph-kitchen-media-ids').val(''); $p.find('.ph-kitchen-media-preview').html('<span class="ph-media-empty">No media selected</span>'); });
    function reindexSteps(){
        $('#ph-recipe-steps .ph-step-row').each(function(i){
            $(this).attr('data-index',i).find('.ph-step-number strong').text(i+1);
            $(this).find('[name]').each(function(){ this.name=this.name.replace(/persiano_recipe_steps\[[^\]]+\]/,'persiano_recipe_steps['+i+']'); });
        });
    }
    $(document).on('click','#ph-add-recipe-step',function(){ var tpl=$('#ph-recipe-step-template').html()||''; var i=$('#ph-recipe-steps .ph-step-row').length; $('#ph-recipe-steps').append(tpl.replace(/__INDEX__/g,i)); reindexSteps(); });
    $(document).on('click','.ph-remove-step',function(){ $(this).closest('.ph-step-row').remove(); reindexSteps(); });
    $(document).on('click','#ph-sync-step-times',function(){
        var active=0, passive=0;
        $('#ph-recipe-steps .ph-step-row').each(function(){ var m=parseFloat($(this).find('.ph-step-minutes').val())||0; if($(this).find('.ph-step-time-type').val()==='passive'){passive+=m;}else{active+=m;} });
        $('#persiano_recipe_labour_minutes').val(active).trigger('input');
        $('#persiano_recipe_passive_minutes').val(passive);
        alert('Step times copied: '+active+' active minutes and '+passive+' passive minutes.');
    });
    function updateScaleLinks(){
        var $c=$('.ph-scale-controls'); if(!$c.length)return;
        var target=parseFloat($('#ph-scale-target').val())||1;
        var print=$c.data('print-base')+'&target_yield='+encodeURIComponent(target)+'&_wpnonce='+encodeURIComponent($c.data('print-nonce'));
        var csv=$c.data('csv-base')+'&target_yield='+encodeURIComponent(target)+'&_wpnonce='+encodeURIComponent($c.data('csv-nonce'));
        $('#ph-print-production-sheet').attr('href',print); $('#ph-export-recipe-csv').attr('href',csv);
    }
    $(document).on('input change','#ph-scale-target',updateScaleLinks);
    $(function(){ attachIngredientLists(); updateScaleLinks(); });
})(jQuery);
JS;
        wp_add_inline_script( 'jquery', $js );

        $css = <<<'CSS'
.ph-top-form-navigation{margin:12px 0 16px}.ph-recipe-nav{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap}.ph-recipe-nav .disabled{opacity:.45;pointer-events:none}.ph-inline-fields{display:flex;gap:6px}.ph-kitchen-media-preview{min-height:80px;border:1px dashed #c3c4c7;border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#fafafa}.ph-kitchen-media-preview img{max-width:100%;height:auto;display:block}.ph-kitchen-media-preview--grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;padding:8px}.ph-kitchen-media-preview--grid div{aspect-ratio:1;overflow:hidden;border-radius:6px;background:#eee}.ph-kitchen-media-preview--grid img{width:100%;height:100%;object-fit:cover}.ph-media-empty{color:#777}.ph-readonly-time{padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px}.ph-readonly-time strong,.ph-readonly-time span{display:block}.ph-readonly-time span{font-size:12px;color:#646970;margin-top:4px}.ph-step-grid--header,.ph-step-row{display:grid;grid-template-columns:36px minmax(260px,2fr) 90px 110px 140px 90px 28px;gap:8px;align-items:center}.ph-step-grid--header{font-weight:600;color:#50575e;padding:8px 10px;background:#f6f7f7;border:1px solid #ddd;border-radius:8px 8px 0 0}.ph-step-row{padding:10px;border:1px solid #ddd;border-top:0;background:#fff}.ph-step-number{text-align:center}.ph-step-media .ph-kitchen-media-preview{min-height:54px}.ph-step-media .ph-kitchen-media-preview img{max-height:54px}.ph-scale-controls{display:grid;grid-template-columns:1fr 1fr 2fr;gap:18px;align-items:end}.ph-scale-controls p{margin:6px 0 0}.ph-scale-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.ph-version-list{display:grid;gap:9px}.ph-version-entry{display:grid;gap:2px;padding-bottom:8px;border-bottom:1px solid #eee}.ph-version-entry small{color:#646970}@media(max-width:1000px){.ph-step-grid--header{display:none}.ph-step-row{grid-template-columns:36px 1fr 100px}.ph-step-main{grid-column:2/4}.ph-scale-controls{grid-template-columns:1fr}}
CSS;
        wp_register_style( 'persiano-hub-kitchen-admin', false, array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-hub-kitchen-admin' );
        wp_add_inline_style( 'persiano-hub-kitchen-admin', $css );
    }
}
