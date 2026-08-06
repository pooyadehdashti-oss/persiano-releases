<?php
/**
 * Unified raw ingredient, prepared component and sellable product inventory.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Inventory {
    const RECIPE_TRACK_COMPONENT = '_persiano_component_inventory_enabled';
    const RECIPE_COMPONENT_LOTS  = '_persiano_component_inventory_lots';
    const RECIPE_COMPONENT_HISTORY = '_persiano_component_inventory_history';
    const PLAN_COMPONENTS        = '_persiano_plan_components';
    const PLAN_INVENTORY_APPLIED = '_persiano_plan_inventory_applied';

    private static $component_cache = array();

    public static function init() {
        add_action( 'add_meta_boxes_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'add_recipe_meta_box' ), 25 );
        add_action( 'save_post_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'save_recipe_component_setting' ), 45, 3 );
        add_action( 'admin_post_persiano_hub_add_component_batch', array( __CLASS__, 'add_component_batch_action' ) );
        add_action( 'admin_post_persiano_hub_adjust_component_inventory', array( __CLASS__, 'adjust_component_inventory_action' ) );
    }

    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage inventory.', 'persiano-hub' ) );
        }
    }

    private static function decimal( $value, $default = 0 ) {
        if ( '' === $value || null === $value ) {
            return (float) $default;
        }
        return is_numeric( $value ) ? (float) $value : (float) $default;
    }

    public static function canonical_unit( $unit ) {
        return Persiano_Hub_Costing::canonical_recipe_unit( $unit );
    }

    private static function unit_family( $unit ) {
        $unit = self::canonical_unit( $unit );
        if ( in_array( $unit, array( 'g', 'kg', 'oz', 'lb' ), true ) ) {
            return 'mass';
        }
        if ( in_array( $unit, array( 'ml', 'l', 'tsp', 'tbsp', 'cup' ), true ) ) {
            return 'volume';
        }
        return 'each' === $unit ? 'count' : '';
    }

    private static function unit_multiplier( $unit ) {
        $map = array(
            'g' => 1,
            'kg' => 1000,
            'oz' => 28.349523125,
            'lb' => 453.59237,
            'ml' => 1,
            'l' => 1000,
            'tsp' => 4.92892159375,
            'tbsp' => 14.78676478125,
            'cup' => 236.5882365,
            'each' => 1,
        );
        $unit = self::canonical_unit( $unit );
        return isset( $map[ $unit ] ) ? (float) $map[ $unit ] : 0;
    }

    public static function convert_quantity( $quantity, $from_unit, $to_unit ) {
        $quantity = self::decimal( $quantity );
        $from_unit = self::canonical_unit( $from_unit );
        $to_unit = self::canonical_unit( $to_unit );
        if ( self::unit_family( $from_unit ) !== self::unit_family( $to_unit ) ) {
            return null;
        }
        $from_multiplier = self::unit_multiplier( $from_unit );
        $to_multiplier = self::unit_multiplier( $to_unit );
        if ( $from_multiplier <= 0 || $to_multiplier <= 0 ) {
            return null;
        }
        return $quantity * $from_multiplier / $to_multiplier;
    }

    public static function component_unit( $recipe_id ) {
        return self::canonical_unit( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) );
    }

    public static function recipe_is_component( $recipe_id ) {
        $recipe_id = absint( $recipe_id );
        if ( ! $recipe_id || Persiano_Hub_Costing::RECIPE_POST_TYPE !== get_post_type( $recipe_id ) ) {
            return false;
        }
        if ( array_key_exists( $recipe_id, self::$component_cache ) ) {
            return self::$component_cache[ $recipe_id ];
        }
        if ( 'yes' === get_post_meta( $recipe_id, self::RECIPE_TRACK_COMPONENT, true ) ) {
            self::$component_cache[ $recipe_id ] = true;
            return true;
        }
        $lots = get_post_meta( $recipe_id, self::RECIPE_COMPONENT_LOTS, true );
        if ( is_array( $lots ) && ! empty( $lots ) ) {
            self::$component_cache[ $recipe_id ] = true;
            return true;
        }
        foreach ( self::recipe_ids() as $parent_id ) {
            if ( $parent_id === $recipe_id ) {
                continue;
            }
            $items = get_post_meta( $parent_id, Persiano_Hub_Costing::RECIPE_ITEMS, true );
            foreach ( (array) $items as $item ) {
                if ( 'recipe' === sanitize_key( $item['source_type'] ?? '' ) && $recipe_id === absint( $item['source_id'] ?? 0 ) ) {
                    self::$component_cache[ $recipe_id ] = true;
                    return true;
                }
            }
        }
        self::$component_cache[ $recipe_id ] = false;
        return false;
    }

    private static function recipe_ids() {
        return get_posts(
            array(
                'post_type' => Persiano_Hub_Costing::RECIPE_POST_TYPE,
                'post_status' => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'fields' => 'ids',
                'no_found_rows' => true,
            )
        );
    }

    public static function component_recipe_ids() {
        $ids = array();
        foreach ( self::recipe_ids() as $recipe_id ) {
            if ( self::recipe_is_component( $recipe_id ) ) {
                $ids[] = (int) $recipe_id;
            }
        }
        return $ids;
    }

    public static function get_component_lots( $recipe_id ) {
        $lots = get_post_meta( $recipe_id, self::RECIPE_COMPONENT_LOTS, true );
        $lots = is_array( $lots ) ? $lots : array();
        $clean = array();
        foreach ( $lots as $lot ) {
            if ( ! is_array( $lot ) ) {
                continue;
            }
            $qty = max( 0, self::decimal( $lot['qty'] ?? 0 ) );
            $remaining = max( 0, min( $qty, self::decimal( $lot['remaining_qty'] ?? $qty ) ) );
            if ( $qty <= 0 && $remaining <= 0 ) {
                continue;
            }
            $clean[] = array(
                'id' => sanitize_text_field( $lot['id'] ?? wp_generate_uuid4() ),
                'qty' => $qty,
                'remaining_qty' => $remaining,
                'unit' => self::canonical_unit( $lot['unit'] ?? self::component_unit( $recipe_id ) ),
                'produced_at' => sanitize_text_field( $lot['produced_at'] ?? '' ),
                'best_before' => sanitize_text_field( $lot['best_before'] ?? '' ),
                'cost' => max( 0, self::decimal( $lot['cost'] ?? 0 ) ),
                'location' => sanitize_text_field( $lot['location'] ?? '' ),
                'lot_code' => sanitize_text_field( $lot['lot_code'] ?? '' ),
                'note' => sanitize_textarea_field( $lot['note'] ?? '' ),
                'created_at' => absint( $lot['created_at'] ?? time() ),
                'inputs_consumed' => is_array( $lot['inputs_consumed'] ?? null ) ? $lot['inputs_consumed'] : array(),
            );
        }
        return $clean;
    }

    private static function save_component_lots( $recipe_id, $lots ) {
        self::$component_cache[ absint( $recipe_id ) ] = true;
        update_post_meta( $recipe_id, self::RECIPE_COMPONENT_LOTS, array_values( $lots ) );
        update_post_meta( $recipe_id, self::RECIPE_TRACK_COMPONENT, 'yes' );
        if ( class_exists( 'Persiano_Hub_Operations' ) ) {
            Persiano_Hub_Operations::bump_data_revision();
        }
    }

    private static function lot_is_expired( $lot ) {
        $best_before = sanitize_text_field( $lot['best_before'] ?? '' );
        if ( ! $best_before ) {
            return false;
        }
        $ts = strtotime( $best_before . ' 23:59:59' );
        return $ts && $ts < current_time( 'timestamp' );
    }

    public static function component_on_hand( $recipe_id, $target_unit = '', $include_expired = false ) {
        $target_unit = $target_unit ? self::canonical_unit( $target_unit ) : self::component_unit( $recipe_id );
        $total = 0.0;
        foreach ( self::get_component_lots( $recipe_id ) as $lot ) {
            if ( ! $include_expired && self::lot_is_expired( $lot ) ) {
                continue;
            }
            $converted = self::convert_quantity( $lot['remaining_qty'], $lot['unit'], $target_unit );
            if ( null !== $converted ) {
                $total += $converted;
            }
        }
        return max( 0, $total );
    }

    public static function component_reserved( $recipe_id, $target_unit = '', $exclude_plan_id = 0 ) {
        $target_unit = $target_unit ? self::canonical_unit( $target_unit ) : self::component_unit( $recipe_id );
        $total = 0.0;
        $plans = get_posts(
            array(
                'post_type' => Persiano_Hub_Operations::PLAN_POST_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_query' => array(
                    array(
                        'key' => Persiano_Hub_Operations::META_STATUS,
                        'value' => 'active',
                    ),
                ),
            )
        );
        foreach ( $plans as $plan_id ) {
            if ( absint( $exclude_plan_id ) === absint( $plan_id ) ) {
                continue;
            }
            $rows = get_post_meta( $plan_id, self::PLAN_COMPONENTS, true );
            foreach ( (array) $rows as $row ) {
                if ( absint( $row['recipe_id'] ?? 0 ) !== absint( $recipe_id ) ) {
                    continue;
                }
                $converted = self::convert_quantity( $row['from_stock'] ?? 0, $row['unit'] ?? $target_unit, $target_unit );
                if ( null !== $converted ) {
                    $total += $converted;
                }
            }
        }
        return max( 0, $total );
    }

    public static function component_availability( $recipe_id, $target_unit = '', $exclude_plan_id = 0 ) {
        $target_unit = $target_unit ? self::canonical_unit( $target_unit ) : self::component_unit( $recipe_id );
        $on_hand = self::component_on_hand( $recipe_id, $target_unit, false );
        $reserved = self::component_reserved( $recipe_id, $target_unit, $exclude_plan_id );
        return array(
            'unit' => $target_unit,
            'on_hand' => $on_hand,
            'reserved' => $reserved,
            'available' => max( 0, $on_hand - $reserved ),
        );
    }

    /**
     * Deduct the recipe inputs used to produce a prepared-component batch.
     *
     * This is deliberately separate from setting opening stock. A stock count
     * records food that already exists; a newly produced batch can deduct the
     * raw ingredients and any nested prepared components used to make it.
     *
     * @param int                 $recipe_id Component recipe ID.
     * @param float               $quantity  Produced quantity.
     * @param string              $unit      Produced quantity unit.
     * @param string              $note      Optional inventory note.
     * @return array|WP_Error
     */
    private static function consume_component_recipe_inputs( $recipe_id, $quantity, $unit, $note = '' ) {
        $yield_unit = self::component_unit( $recipe_id );
        $required_yield = self::convert_quantity( $quantity, $unit, $yield_unit );
        if ( null === $required_yield || $required_yield <= 0 ) {
            return new WP_Error( 'persiano_component_input_unit_mismatch', __( 'The produced quantity cannot be converted to the recipe yield unit.', 'persiano-hub' ) );
        }

        $ingredients = Persiano_Hub_Kitchen::requirements_from_recipe_quantities(
            array( $recipe_id => $required_yield ),
            0
        );
        $nested_components = Persiano_Hub_Kitchen::last_component_requirements();
        $result = array(
            'ingredients' => array(),
            'components'  => array(),
            'warnings'    => array(),
        );
        $reason = sprintf( __( 'Produced prepared component: %s', 'persiano-hub' ), get_the_title( $recipe_id ) );

        foreach ( (array) $ingredients as $ingredient_id => $row ) {
            $required = max( 0, self::decimal( $row['required'] ?? 0 ) );
            $base_unit = sanitize_key( $row['base_unit'] ?? 'each' );
            if ( ! $ingredient_id || $required <= 0 ) {
                continue;
            }
            $before_in_base = Persiano_Hub_Operations::inventory_quantity_in_unit( $ingredient_id, $base_unit );
            $new_total = Persiano_Hub_Operations::adjust_inventory(
                $ingredient_id,
                'subtract',
                $required,
                $base_unit,
                $reason,
                $note,
                'prepared_component'
            );
            if ( is_wp_error( $new_total ) ) {
                return $new_total;
            }
            $result['ingredients'][ $ingredient_id ] = array(
                'required' => $required,
                'unit'     => $base_unit,
                'new_total'=> $new_total,
            );
            if ( null !== $before_in_base && $before_in_base + 0.0001 < $required ) {
                $result['warnings'][] = sprintf(
                    __( '%1$s had only %2$s %3$s available; stock was reduced to zero.', 'persiano-hub' ),
                    get_the_title( $ingredient_id ),
                    self::format_qty( $before_in_base ),
                    $base_unit
                );
            }
        }

        foreach ( (array) $nested_components as $component_id => $row ) {
            $from_stock = max( 0, self::decimal( $row['from_stock'] ?? 0 ) );
            $component_unit = self::canonical_unit( $row['unit'] ?? self::component_unit( $component_id ) );
            if ( ! $component_id || $from_stock <= 0 ) {
                continue;
            }
            $consumed = self::consume_component_stock( $component_id, $from_stock, $component_unit, $reason, $note );
            $result['components'][ $component_id ] = array(
                'required' => $from_stock,
                'consumed' => $consumed,
                'unit'     => $component_unit,
            );
            if ( $consumed + 0.0001 < $from_stock ) {
                $result['warnings'][] = sprintf(
                    __( '%1$s supplied %2$s of the required %3$s %4$s.', 'persiano-hub' ),
                    get_the_title( $component_id ),
                    self::format_qty( $consumed ),
                    self::format_qty( $from_stock ),
                    $component_unit
                );
            }
        }

        return $result;
    }

    public static function add_component_batch( $recipe_id, $quantity, $unit, $data = array() ) {
        $recipe_id = absint( $recipe_id );
        if ( ! $recipe_id || Persiano_Hub_Costing::RECIPE_POST_TYPE !== get_post_type( $recipe_id ) ) {
            return new WP_Error( 'persiano_invalid_component', __( 'Prepared component recipe could not be found.', 'persiano-hub' ) );
        }
        $quantity = max( 0, self::decimal( $quantity ) );
        $unit = self::canonical_unit( $unit );
        $component_unit = self::component_unit( $recipe_id );
        if ( null === self::convert_quantity( $quantity, $unit, $component_unit ) || $quantity <= 0 ) {
            return new WP_Error( 'persiano_component_unit_mismatch', __( 'The batch quantity must use a unit compatible with the recipe yield.', 'persiano-hub' ) );
        }
        $input_consumption = array();
        if ( ! empty( $data['consume_inputs'] ) ) {
            $input_consumption = self::consume_component_recipe_inputs(
                $recipe_id,
                $quantity,
                $unit,
                sanitize_textarea_field( $data['note'] ?? '' )
            );
            if ( is_wp_error( $input_consumption ) ) {
                return $input_consumption;
            }
        }

        $lot_cost = max( 0, self::decimal( $data['cost'] ?? 0 ) );
        if ( $lot_cost <= 0 ) {
            $batch_cost = max( 0, self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_BATCH_COST, true ) ) );
            $batch_yield = max( 0.0001, self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 ) );
            $quantity_in_yield_unit = self::convert_quantity( $quantity, $unit, $component_unit );
            if ( $batch_cost > 0 && null !== $quantity_in_yield_unit ) {
                $lot_cost = $batch_cost * $quantity_in_yield_unit / $batch_yield;
            }
        }

        $lots = self::get_component_lots( $recipe_id );
        $lot = array(
            'id' => wp_generate_uuid4(),
            'qty' => $quantity,
            'remaining_qty' => $quantity,
            'unit' => $unit,
            'produced_at' => sanitize_text_field( $data['produced_at'] ?? wp_date( 'Y-m-d' ) ),
            'best_before' => sanitize_text_field( $data['best_before'] ?? '' ),
            'cost' => $lot_cost,
            'location' => sanitize_text_field( $data['location'] ?? '' ),
            'lot_code' => sanitize_text_field( $data['lot_code'] ?? '' ),
            'note' => sanitize_textarea_field( $data['note'] ?? '' ),
            'created_at' => time(),
            'inputs_consumed' => is_array( $input_consumption ) ? $input_consumption : array(),
        );
        $lots[] = $lot;
        self::save_component_lots( $recipe_id, $lots );
        $history_note = sanitize_textarea_field( $data['note'] ?? '' );
        if ( ! empty( $data['consume_inputs'] ) ) {
            $history_note = trim( $history_note . ( $history_note ? "\n" : '' ) . __( 'Recipe inputs were deducted from inventory.', 'persiano-hub' ) );
        }
        self::add_component_history( $recipe_id, 'add', $quantity, $unit, $data['reason'] ?? __( 'Prepared batch', 'persiano-hub' ), $history_note, $lot['id'] );
        return $lot;
    }

    private static function add_component_history( $recipe_id, $mode, $quantity, $unit, $reason = '', $note = '', $lot_id = '' ) {
        $history = get_post_meta( $recipe_id, self::RECIPE_COMPONENT_HISTORY, true );
        $history = is_array( $history ) ? $history : array();
        $history[] = array(
            'time' => time(),
            'mode' => sanitize_key( $mode ),
            'quantity' => self::decimal( $quantity ),
            'unit' => self::canonical_unit( $unit ),
            'reason' => sanitize_text_field( $reason ),
            'note' => sanitize_textarea_field( $note ),
            'lot_id' => sanitize_text_field( $lot_id ),
            'user_id' => get_current_user_id(),
        );
        if ( count( $history ) > 200 ) {
            $history = array_slice( $history, -200 );
        }
        update_post_meta( $recipe_id, self::RECIPE_COMPONENT_HISTORY, $history );
    }

    public static function consume_component_stock( $recipe_id, $quantity, $unit, $reason = '', $note = '' ) {
        $recipe_id = absint( $recipe_id );
        $quantity = max( 0, self::decimal( $quantity ) );
        $unit = self::canonical_unit( $unit );
        if ( $quantity <= 0 ) {
            return 0;
        }
        $lots = self::get_component_lots( $recipe_id );
        usort(
            $lots,
            static function( $a, $b ) {
                $a_date = ! empty( $a['best_before'] ) ? strtotime( $a['best_before'] ) : PHP_INT_MAX;
                $b_date = ! empty( $b['best_before'] ) ? strtotime( $b['best_before'] ) : PHP_INT_MAX;
                if ( $a_date === $b_date ) {
                    return (int) ( $a['created_at'] ?? 0 ) <=> (int) ( $b['created_at'] ?? 0 );
                }
                return $a_date <=> $b_date;
            }
        );
        $remaining_target = $quantity;
        $consumed_target = 0.0;
        foreach ( $lots as &$lot ) {
            if ( $remaining_target <= 0 || self::lot_is_expired( $lot ) || $lot['remaining_qty'] <= 0 ) {
                continue;
            }
            $lot_available_target = self::convert_quantity( $lot['remaining_qty'], $lot['unit'], $unit );
            if ( null === $lot_available_target || $lot_available_target <= 0 ) {
                continue;
            }
            $take_target = min( $remaining_target, $lot_available_target );
            $take_lot = self::convert_quantity( $take_target, $unit, $lot['unit'] );
            if ( null === $take_lot ) {
                continue;
            }
            $lot['remaining_qty'] = max( 0, $lot['remaining_qty'] - $take_lot );
            $remaining_target -= $take_target;
            $consumed_target += $take_target;
        }
        unset( $lot );
        self::save_component_lots( $recipe_id, $lots );
        if ( $consumed_target > 0 ) {
            self::add_component_history( $recipe_id, 'subtract', $consumed_target, $unit, $reason, $note );
        }
        return $consumed_target;
    }

    public static function set_component_stock( $recipe_id, $quantity, $unit, $data = array() ) {
        $recipe_id = absint( $recipe_id );
        $quantity = max( 0, self::decimal( $quantity ) );
        $unit = self::canonical_unit( $unit );
        if ( null === self::convert_quantity( $quantity, $unit, self::component_unit( $recipe_id ) ) ) {
            return new WP_Error( 'persiano_component_unit_mismatch', __( 'The stock count unit is incompatible with the recipe yield.', 'persiano-hub' ) );
        }
        $lots = array();
        if ( $quantity > 0 ) {
            $lots[] = array(
                'id' => wp_generate_uuid4(),
                'qty' => $quantity,
                'remaining_qty' => $quantity,
                'unit' => $unit,
                'produced_at' => sanitize_text_field( $data['produced_at'] ?? wp_date( 'Y-m-d' ) ),
                'best_before' => sanitize_text_field( $data['best_before'] ?? '' ),
                'cost' => max( 0, self::decimal( $data['cost'] ?? 0 ) ),
                'location' => sanitize_text_field( $data['location'] ?? '' ),
                'lot_code' => sanitize_text_field( $data['lot_code'] ?? '' ),
                'note' => sanitize_textarea_field( $data['note'] ?? '' ),
                'created_at' => time(),
            );
        }
        self::save_component_lots( $recipe_id, $lots );
        self::add_component_history( $recipe_id, 'set', $quantity, $unit, $data['reason'] ?? __( 'Stock count', 'persiano-hub' ), $data['note'] ?? '' );
        return $quantity;
    }

    public static function add_recipe_meta_box() {
        add_meta_box(
            'persiano_hub_prepared_component_inventory',
            __( 'Prepared Component Inventory', 'persiano-hub' ),
            array( __CLASS__, 'render_recipe_meta_box' ),
            Persiano_Hub_Costing::RECIPE_POST_TYPE,
            'side',
            'default'
        );
    }

    public static function render_recipe_meta_box( $post ) {
        wp_nonce_field( 'persiano_hub_save_component_setting', 'persiano_hub_component_setting_nonce' );
        $enabled = self::recipe_is_component( $post->ID );
        $unit = self::component_unit( $post->ID );
        $availability = self::component_availability( $post->ID, $unit );
        $url = add_query_arg(
            array(
                'page' => Persiano_Hub_Costing::MENU_SLUG,
                'tab' => 'inventory',
                'component_id' => $post->ID,
            ),
            admin_url( 'admin.php' )
        );
        ?>
        <p><label><input type="checkbox" name="persiano_component_inventory_enabled" value="yes" <?php checked( $enabled ); ?>> <strong><?php esc_html_e( 'Track this recipe as prepared stock', 'persiano-hub' ); ?></strong></label></p>
        <p class="description"><?php esc_html_e( 'Use for sauces, cooked bases, doughs and other internal sub-recipes. No WooCommerce product is required.', 'persiano-hub' ); ?></p>
        <p><strong><?php esc_html_e( 'Usable on hand:', 'persiano-hub' ); ?></strong> <?php echo esc_html( self::format_qty( $availability['on_hand'] ) . ' ' . $unit ); ?><br><strong><?php esc_html_e( 'Reserved:', 'persiano-hub' ); ?></strong> <?php echo esc_html( self::format_qty( $availability['reserved'] ) . ' ' . $unit ); ?><br><strong><?php esc_html_e( 'Available:', 'persiano-hub' ); ?></strong> <?php echo esc_html( self::format_qty( $availability['available'] ) . ' ' . $unit ); ?></p>
        <p><a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open inventory', 'persiano-hub' ); ?></a></p>
        <?php
    }

    public static function save_recipe_component_setting( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! isset( $_POST['persiano_hub_component_setting_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_hub_component_setting_nonce'] ) ), 'persiano_hub_save_component_setting' ) ) {
            return;
        }
        $enabled = ! empty( $_POST['persiano_component_inventory_enabled'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, self::RECIPE_TRACK_COMPONENT, $enabled );
        unset( self::$component_cache[ absint( $post_id ) ] );
    }

    public static function add_component_batch_action() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_add_component_batch' );
        $recipe_id = absint( $_POST['recipe_id'] ?? 0 );
        $result = self::add_component_batch(
            $recipe_id,
            $_POST['quantity'] ?? 0,
            $_POST['unit'] ?? '',
            array(
                'produced_at' => sanitize_text_field( wp_unslash( $_POST['produced_at'] ?? '' ) ),
                'best_before' => sanitize_text_field( wp_unslash( $_POST['best_before'] ?? '' ) ),
                'cost' => $_POST['cost'] ?? 0,
                'location' => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
                'lot_code' => sanitize_text_field( wp_unslash( $_POST['lot_code'] ?? '' ) ),
                'note' => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
                'consume_inputs' => ! empty( $_POST['consume_inputs'] ),
            )
        );
        $args = array( 'component_id' => $recipe_id );
        if ( is_wp_error( $result ) ) {
            $args['inventory_error'] = rawurlencode( $result->get_error_message() );
        } else {
            $args['inventory_saved'] = 1;
            $warnings = is_array( $result ) && ! empty( $result['inputs_consumed']['warnings'] ) ? (array) $result['inputs_consumed']['warnings'] : array();
            if ( $warnings ) {
                $args['inventory_warning'] = rawurlencode( implode( ' ', array_map( 'sanitize_text_field', $warnings ) ) );
            }
        }
        wp_safe_redirect( self::inventory_url( $args ) );
        exit;
    }

    public static function adjust_component_inventory_action() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_adjust_component_inventory' );
        $recipe_id = absint( $_POST['recipe_id'] ?? 0 );
        $mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'subtract' ) );
        $quantity = max( 0, self::decimal( $_POST['quantity'] ?? 0 ) );
        $unit = self::canonical_unit( $_POST['unit'] ?? self::component_unit( $recipe_id ) );
        $data = array(
            'produced_at' => sanitize_text_field( wp_unslash( $_POST['produced_at'] ?? '' ) ),
            'best_before' => sanitize_text_field( wp_unslash( $_POST['best_before'] ?? '' ) ),
            'cost' => $_POST['cost'] ?? 0,
            'location' => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
            'lot_code' => sanitize_text_field( wp_unslash( $_POST['lot_code'] ?? '' ) ),
            'reason' => sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ),
            'note' => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
            'consume_inputs' => ! empty( $_POST['consume_inputs'] ),
        );
        if ( 'set' === $mode ) {
            $result = self::set_component_stock( $recipe_id, $quantity, $unit, $data );
        } elseif ( 'add' === $mode ) {
            $result = self::add_component_batch( $recipe_id, $quantity, $unit, $data );
        } else {
            $reason = $data['reason'] ?: ucfirst( $mode );
            $result = self::consume_component_stock( $recipe_id, $quantity, $unit, $reason, $data['note'] );
        }
        $args = array( 'component_id' => $recipe_id );
        if ( is_wp_error( $result ) ) {
            $args['inventory_error'] = rawurlencode( $result->get_error_message() );
        } else {
            $args['inventory_saved'] = 1;
            $warnings = is_array( $result ) && ! empty( $result['inputs_consumed']['warnings'] ) ? (array) $result['inputs_consumed']['warnings'] : array();
            if ( $warnings ) {
                $args['inventory_warning'] = rawurlencode( implode( ' ', array_map( 'sanitize_text_field', $warnings ) ) );
            }
        }
        wp_safe_redirect( self::inventory_url( $args ) );
        exit;
    }

    private static function inventory_url( $args = array() ) {
        return add_query_arg(
            array_merge(
                array(
                    'page' => Persiano_Hub_Costing::MENU_SLUG,
                    'tab' => 'inventory',
                ),
                $args
            ),
            admin_url( 'admin.php' )
        );
    }

    public static function apply_production_plan_inventory( $plan_id ) {
        $plan_id = absint( $plan_id );
        if ( ! $plan_id || Persiano_Hub_Operations::PLAN_POST_TYPE !== get_post_type( $plan_id ) ) {
            return new WP_Error( 'persiano_invalid_plan', __( 'Production plan could not be found.', 'persiano-hub' ) );
        }
        $applied = get_post_meta( $plan_id, self::PLAN_INVENTORY_APPLIED, true );
        if ( is_array( $applied ) && ! empty( $applied['time'] ) ) {
            return $applied;
        }
        $results = array( 'ingredients' => array(), 'components' => array() );
        $ingredient_rows = get_post_meta( $plan_id, Persiano_Hub_Operations::META_PLAN_INGREDIENTS, true );
        foreach ( (array) $ingredient_rows as $row ) {
            if ( empty( $row['include'] ) ) {
                continue;
            }
            $ingredient_id = absint( $row['ingredient_id'] ?? 0 );
            $qty = max( 0, self::decimal( $row['planned_qty'] ?? 0 ) );
            $unit = sanitize_key( $row['base_unit'] ?? 'each' );
            if ( ! $ingredient_id || $qty <= 0 ) {
                continue;
            }
            $new_total = Persiano_Hub_Operations::adjust_inventory( $ingredient_id, 'subtract', $qty, $unit, __( 'Production plan completed', 'persiano-hub' ), get_the_title( $plan_id ), 'production_plan' );
            $results['ingredients'][ $ingredient_id ] = is_wp_error( $new_total ) ? $new_total->get_error_message() : $new_total;
        }
        $component_rows = get_post_meta( $plan_id, self::PLAN_COMPONENTS, true );
        foreach ( (array) $component_rows as $row ) {
            $recipe_id = absint( $row['recipe_id'] ?? 0 );
            $qty = max( 0, self::decimal( $row['from_stock'] ?? 0 ) );
            $unit = self::canonical_unit( $row['unit'] ?? self::component_unit( $recipe_id ) );
            if ( ! $recipe_id || $qty <= 0 ) {
                continue;
            }
            $results['components'][ $recipe_id ] = self::consume_component_stock( $recipe_id, $qty, $unit, __( 'Production plan completed', 'persiano-hub' ), get_the_title( $plan_id ) );
        }
        $record = array(
            'time' => time(),
            'user_id' => get_current_user_id(),
            'results' => $results,
        );
        update_post_meta( $plan_id, self::PLAN_INVENTORY_APPLIED, $record );
        return $record;
    }

    public static function render_inventory_page() {
        self::require_permission();
        $component_id = absint( $_GET['component_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['inventory_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Inventory updated. Refresh any open production plan so prepared-stock allocations and raw ingredient requirements are recalculated.', 'persiano-hub' ) . '</p></div>';
        }
        if ( ! empty( $_GET['inventory_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-error inline"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['inventory_error'] ) ) ) . '</p></div>';
        }
        if ( ! empty( $_GET['inventory_warning'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-warning inline"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['inventory_warning'] ) ) ) . '</p></div>';
        }

        $ingredient_ids = get_posts(
            array(
                'post_type' => Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
                'post_status' => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'fields' => 'ids',
                'no_found_rows' => true,
            )
        );
        $component_ids = self::component_recipe_ids();
        $products = function_exists( 'wc_get_products' ) ? wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ), 'orderby' => 'name', 'order' => 'ASC' ) ) : array();
        if ( function_exists( 'wc_get_product' ) ) {
            $variation_ids = get_posts(
                array(
                    'post_type'      => 'product_variation',
                    'post_status'    => array( 'publish', 'private' ),
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                )
            );
            foreach ( $variation_ids as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( $variation ) {
                    $products[] = $variation;
                }
            }
            usort(
                $products,
                static function( $a, $b ) {
                    return strcasecmp( $a->get_name(), $b->get_name() );
                }
            );
        }
        $ingredient_stocked = 0;
        foreach ( $ingredient_ids as $ingredient_id ) {
            if ( self::decimal( get_post_meta( $ingredient_id, Persiano_Hub_Kitchen::ING_ON_HAND_QTY, true ) ) > 0 ) {
                $ingredient_stocked++;
            }
        }
        $component_stocked = 0;
        foreach ( $component_ids as $recipe_id ) {
            if ( self::component_on_hand( $recipe_id ) > 0 ) {
                $component_stocked++;
            }
        }
        $managed_products = 0;
        foreach ( $products as $product ) {
            if ( $product && $product->managing_stock() ) {
                $managed_products++;
            }
        }
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Stock control', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Inventory', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'See raw ingredients, internal prepared components and customer-facing WooCommerce stock in one place.', 'persiano-hub' ); ?></p></div></div>
        <div class="ph-costing-stats"><div><strong><?php echo esc_html( $ingredient_stocked ); ?></strong><span><?php esc_html_e( 'Ingredients with stock', 'persiano-hub' ); ?></span></div><div><strong><?php echo esc_html( $component_stocked ); ?></strong><span><?php esc_html_e( 'Prepared components with stock', 'persiano-hub' ); ?></span></div><div><strong><?php echo esc_html( $managed_products ); ?></strong><span><?php esc_html_e( 'Products managing stock', 'persiano-hub' ); ?></span></div></div>
        <section class="ph-costing-panel"><h2><?php esc_html_e( 'How inventory flows', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'Purchases add raw ingredients. Production plans use prepared-component stock first and expand only the shortage into raw ingredients. Marking a production plan done deducts the included raw ingredients and prepared components once. WooCommerce product stock remains separate and is shown for visibility.', 'persiano-hub' ); ?></p></section>
        <?php self::render_component_section( $component_ids, $component_id ); ?>
        <?php self::render_ingredient_section( $ingredient_ids ); ?>
        <?php self::render_product_section( $products ); ?>
        <?php self::render_recent_inventory_history( $ingredient_ids, $component_ids ); ?>
        <?php
    }

    private static function render_component_section( $component_ids, $component_id ) {
        ?>
        <section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2><?php esc_html_e( 'Prepared components', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'Internal sauces, bases, doughs and other sub-recipes. They do not need to be sellable products.', 'persiano-hub' ); ?></p></div></div>
        <?php if ( ! $component_ids ) : ?><p><?php esc_html_e( 'No component recipes were found. Add a recipe as a sub-recipe or enable prepared inventory on its edit screen.', 'persiano-hub' ); ?></p><?php else : ?>
        <div class="ph-costing-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Component', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'On hand', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Reserved', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Available', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Next best-before', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Location', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Action', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php foreach ( $component_ids as $recipe_id ) : $unit = self::component_unit( $recipe_id ); $availability = self::component_availability( $recipe_id, $unit ); $lots = self::get_component_lots( $recipe_id ); $next_expiry = ''; $locations = array(); foreach ( $lots as $lot ) { if ( $lot['remaining_qty'] <= 0 ) { continue; } if ( ! empty( $lot['best_before'] ) && ( ! $next_expiry || $lot['best_before'] < $next_expiry ) ) { $next_expiry = $lot['best_before']; } if ( ! empty( $lot['location'] ) ) { $locations[] = $lot['location']; } } $url = self::inventory_url( array( 'component_id' => $recipe_id ) ); ?>
        <tr><td><strong><a href="<?php echo esc_url( get_edit_post_link( $recipe_id ) ); ?>"><?php echo esc_html( get_the_title( $recipe_id ) ); ?></a></strong><br><small><?php echo esc_html( count( $lots ) . ' ' . __( 'lots', 'persiano-hub' ) ); ?></small></td><td><?php echo esc_html( self::format_qty( $availability['on_hand'] ) . ' ' . $unit ); ?></td><td><?php echo esc_html( self::format_qty( $availability['reserved'] ) . ' ' . $unit ); ?></td><td><strong><?php echo esc_html( self::format_qty( $availability['available'] ) . ' ' . $unit ); ?></strong></td><td><?php echo $next_expiry ? esc_html( $next_expiry ) : '—'; ?></td><td><?php echo $locations ? esc_html( implode( ', ', array_unique( $locations ) ) ) : '—'; ?></td><td><a class="button button-small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Add / adjust', 'persiano-hub' ); ?></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <?php if ( $component_id && in_array( $component_id, array_map( 'intval', $component_ids ), true ) ) { self::render_component_adjustment_form( $component_id ); self::render_component_lots( $component_id ); } ?>
        </section>
        <?php
    }

    private static function render_component_adjustment_form( $recipe_id ) {
        $unit = self::component_unit( $recipe_id );
        ?>
        <hr><h3><?php printf( esc_html__( 'Update prepared stock: %s', 'persiano-hub' ), esc_html( get_the_title( $recipe_id ) ) ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_adjust_component_inventory"><input type="hidden" name="recipe_id" value="<?php echo esc_attr( $recipe_id ); ?>"><?php wp_nonce_field( 'persiano_hub_adjust_component_inventory' ); ?>
        <div class="ph-ops-inline-fields"><label><?php esc_html_e( 'Action', 'persiano-hub' ); ?><select name="mode"><option value="add"><?php esc_html_e( 'Add prepared batch', 'persiano-hub' ); ?></option><option value="subtract"><?php esc_html_e( 'Use / remove stock', 'persiano-hub' ); ?></option><option value="waste"><?php esc_html_e( 'Waste', 'persiano-hub' ); ?></option><option value="spoilage"><?php esc_html_e( 'Spoilage', 'persiano-hub' ); ?></option><option value="personal"><?php esc_html_e( 'Personal use', 'persiano-hub' ); ?></option><option value="set"><?php esc_html_e( 'Set exact stock count', 'persiano-hub' ); ?></option></select></label><label><?php esc_html_e( 'Quantity', 'persiano-hub' ); ?><input type="number" min="0" step="0.0001" name="quantity" required></label><label><?php esc_html_e( 'Unit', 'persiano-hub' ); ?><select name="unit"><?php foreach ( Persiano_Hub_Costing::unit_options() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $unit, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Produced', 'persiano-hub' ); ?><input type="date" name="produced_at" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></label><label><?php esc_html_e( 'Best before', 'persiano-hub' ); ?><input type="date" name="best_before"></label><label><?php esc_html_e( 'Batch cost', 'persiano-hub' ); ?><input type="number" min="0" step="0.01" name="cost" placeholder="<?php esc_attr_e( 'Auto if blank', 'persiano-hub' ); ?>"></label><label><?php esc_html_e( 'Location', 'persiano-hub' ); ?><input type="text" name="location" placeholder="Fridge / freezer"></label><label><?php esc_html_e( 'Lot code', 'persiano-hub' ); ?><input type="text" name="lot_code"></label></div>
        <p><label><input type="checkbox" name="consume_inputs" value="1" checked> <strong><?php esc_html_e( 'Deduct recipe inputs when adding a newly produced batch', 'persiano-hub' ); ?></strong></label><br><span class="description"><?php esc_html_e( 'Leave checked for a batch you just made. Choose Set exact stock count for opening inventory or food made before inventory tracking, so ingredients are not deducted twice.', 'persiano-hub' ); ?></span></p>
        <p><input type="text" class="regular-text" name="reason" placeholder="<?php esc_attr_e( 'Reason', 'persiano-hub' ); ?>"> <input type="text" class="large-text" name="note" placeholder="<?php esc_attr_e( 'Optional note', 'persiano-hub' ); ?>"></p><?php submit_button( __( 'Save prepared inventory', 'persiano-hub' ), 'primary', 'submit', false ); ?></form>
        <?php
    }

    private static function render_component_lots( $recipe_id ) {
        $lots = self::get_component_lots( $recipe_id );
        if ( ! $lots ) {
            return;
        }
        echo '<h3>' . esc_html__( 'Current lots', 'persiano-hub' ) . '</h3><div class="ph-costing-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Lot', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Produced', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Best before', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Remaining', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Location', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Cost', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Recipe inputs', 'persiano-hub' ) . '</th></tr></thead><tbody>';
        foreach ( $lots as $lot ) {
            $expired = self::lot_is_expired( $lot );
            $inputs_deducted = ! empty( $lot['inputs_consumed']['ingredients'] ) || ! empty( $lot['inputs_consumed']['components'] );
            echo '<tr><td>' . esc_html( $lot['lot_code'] ?: substr( $lot['id'], 0, 8 ) ) . '</td><td>' . esc_html( $lot['produced_at'] ?: '—' ) . '</td><td>' . ( $lot['best_before'] ? '<span class="' . ( $expired ? 'ph-price-stale' : '' ) . '">' . esc_html( $lot['best_before'] ) . ( $expired ? ' ' . esc_html__( '(expired)', 'persiano-hub' ) : '' ) . '</span>' : '—' ) . '</td><td><strong>' . esc_html( self::format_qty( $lot['remaining_qty'] ) . ' ' . $lot['unit'] ) . '</strong></td><td>' . esc_html( $lot['location'] ?: '—' ) . '</td><td>' . ( $lot['cost'] > 0 ? wp_kses_post( wc_price( $lot['cost'] ) ) : '—' ) . '</td><td>' . esc_html( $inputs_deducted ? __( 'Deducted', 'persiano-hub' ) : __( 'Not deducted / opening stock', 'persiano-hub' ) ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_ingredient_section( $ingredient_ids ) {
        ?>
        <section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2><?php esc_html_e( 'Raw ingredients', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'Ingredient Master quantities and the latest stock movement. Use Adjust to add, remove, count, waste or record spoilage.', 'persiano-hub' ); ?></p></div></div><div class="ph-costing-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Ingredient', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'On hand', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Base unit', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Last movement', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Recorded best-before', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Action', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php foreach ( $ingredient_ids as $ingredient_id ) : $qty = self::decimal( get_post_meta( $ingredient_id, Persiano_Hub_Kitchen::ING_ON_HAND_QTY, true ) ); $unit = sanitize_key( get_post_meta( $ingredient_id, Persiano_Hub_Kitchen::ING_ON_HAND_UNIT, true ) ); $base = sanitize_key( get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BASE_UNIT, true ) ); $history = get_post_meta( $ingredient_id, Persiano_Hub_Operations::ING_INVENTORY_HISTORY, true ); $history = is_array( $history ) ? $history : array(); $last = $history ? end( $history ) : array(); $best_before = ''; foreach ( array_reverse( $history ) as $movement ) { if ( ! empty( $movement['best_before'] ) ) { $best_before = $movement['best_before']; break; } } $adjust_url = add_query_arg( array( 'page' => Persiano_Hub_Costing::MENU_SLUG, 'tab' => 'ingredients', 'ingredient_action' => 'stock', 'ingredient_id' => $ingredient_id ), admin_url( 'admin.php' ) ); ?>
        <tr><td><a href="<?php echo esc_url( get_edit_post_link( $ingredient_id ) ); ?>"><strong><?php echo esc_html( get_the_title( $ingredient_id ) ); ?></strong></a></td><td><?php echo esc_html( self::format_qty( $qty ) . ' ' . ( $unit ?: $base ) ); ?></td><td><?php echo esc_html( $base ?: '—' ); ?></td><td><?php if ( $last ) : echo esc_html( ucfirst( $last['mode'] ?? '' ) . ' · ' . wp_date( 'M j, Y', absint( $last['time'] ?? 0 ) ) ); else : echo '—'; endif; ?></td><td><?php echo $best_before ? esc_html( $best_before ) : '—'; ?></td><td><a class="button button-small" href="<?php echo esc_url( $adjust_url ); ?>"><?php esc_html_e( 'Adjust', 'persiano-hub' ); ?></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></section>
        <?php
    }

    private static function render_product_section( $products ) {
        ?>
        <section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2><?php esc_html_e( 'Sellable WooCommerce products', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'Customer-facing stock is separate from raw ingredients and prepared components. It is shown here without creating internal components as products.', 'persiano-hub' ); ?></p></div></div>
        <?php if ( ! $products ) : ?><p><?php esc_html_e( 'No WooCommerce products found.', 'persiano-hub' ); ?></p><?php else : ?><div class="ph-costing-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Product', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Stock', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Status', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Package / size', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Linked recipe', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Action', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php foreach ( $products as $product ) : if ( ! $product ) { continue; } $product_id = $product->get_id(); $parent_id = method_exists( $product, 'get_parent_id' ) ? absint( $product->get_parent_id() ) : 0; $recipe_id = absint( get_post_meta( $product_id, Persiano_Hub_Costing::PRODUCT_RECIPE_META, true ) ); if ( ! $recipe_id ) { $recipe_ids = get_posts( array( 'post_type' => Persiano_Hub_Costing::RECIPE_POST_TYPE, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => Persiano_Hub_Costing::RECIPE_PRODUCT_ID, 'meta_value' => $product_id ) ); $recipe_id = $recipe_ids ? absint( $recipe_ids[0] ) : 0; } if ( ! $recipe_id && $parent_id ) { $recipe_id = absint( get_post_meta( $parent_id, Persiano_Hub_Costing::PRODUCT_RECIPE_META, true ) ); if ( ! $recipe_id ) { $recipe_ids = get_posts( array( 'post_type' => Persiano_Hub_Costing::RECIPE_POST_TYPE, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => Persiano_Hub_Costing::RECIPE_PRODUCT_ID, 'meta_value' => $parent_id ) ); $recipe_id = $recipe_ids ? absint( $recipe_ids[0] ) : 0; } } $size = get_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SIZE, true ); if ( ! $size && $parent_id ) { $size = get_post_meta( $parent_id, Persiano_Hub_Product_Fields::META_SIZE, true ); } ?>
        <tr><td><strong><?php echo esc_html( $product->get_name() ); ?></strong></td><td><?php echo $product->managing_stock() ? esc_html( null === $product->get_stock_quantity() ? '0' : self::format_qty( $product->get_stock_quantity() ) ) : esc_html__( 'Not tracked', 'persiano-hub' ); ?></td><td><?php echo esc_html( wc_get_stock_html( $product ) ? wp_strip_all_tags( wc_get_stock_html( $product ) ) : $product->get_stock_status() ); ?></td><td><?php echo $size ? esc_html( $size ) : '—'; ?></td><td><?php echo $recipe_id ? '<a href="' . esc_url( get_edit_post_link( $recipe_id ) ) . '">' . esc_html( get_the_title( $recipe_id ) ) . '</a>' : '—'; ?></td><td><a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>"><?php esc_html_e( 'Edit product', 'persiano-hub' ); ?></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div><?php endif; ?></section>
        <?php
    }

    private static function render_recent_inventory_history( $ingredient_ids, $component_ids ) {
        $rows = array();
        foreach ( $ingredient_ids as $ingredient_id ) {
            $history = get_post_meta( $ingredient_id, Persiano_Hub_Operations::ING_INVENTORY_HISTORY, true );
            foreach ( (array) $history as $movement ) {
                $rows[] = array(
                    'time' => absint( $movement['time'] ?? 0 ),
                    'type' => __( 'Ingredient', 'persiano-hub' ),
                    'name' => get_the_title( $ingredient_id ),
                    'mode' => sanitize_key( $movement['mode'] ?? '' ),
                    'quantity' => self::decimal( $movement['quantity'] ?? abs( $movement['change'] ?? 0 ) ),
                    'unit' => sanitize_key( $movement['quantity_unit'] ?? $movement['unit'] ?? '' ),
                    'reason' => sanitize_text_field( $movement['reason'] ?? '' ),
                );
            }
        }
        foreach ( $component_ids as $recipe_id ) {
            $history = get_post_meta( $recipe_id, self::RECIPE_COMPONENT_HISTORY, true );
            foreach ( (array) $history as $movement ) {
                $rows[] = array(
                    'time' => absint( $movement['time'] ?? 0 ),
                    'type' => __( 'Prepared component', 'persiano-hub' ),
                    'name' => get_the_title( $recipe_id ),
                    'mode' => sanitize_key( $movement['mode'] ?? '' ),
                    'quantity' => self::decimal( $movement['quantity'] ?? 0 ),
                    'unit' => self::canonical_unit( $movement['unit'] ?? '' ),
                    'reason' => sanitize_text_field( $movement['reason'] ?? '' ),
                );
            }
        }
        usort( $rows, static function( $a, $b ) { return (int) $b['time'] <=> (int) $a['time']; } );
        $rows = array_slice( $rows, 0, 30 );
        ?>
        <section class="ph-costing-panel"><h2><?php esc_html_e( 'Recent stock ledger', 'persiano-hub' ); ?></h2><?php if ( ! $rows ) : ?><p><?php esc_html_e( 'No inventory movements recorded yet.', 'persiano-hub' ); ?></p><?php else : ?><div class="ph-costing-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Type', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Item', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Movement', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Reason', 'persiano-hub' ); ?></th></tr></thead><tbody><?php foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( $row['time'] ? wp_date( 'M j, Y g:i a', $row['time'] ) : '—' ); ?></td><td><?php echo esc_html( $row['type'] ); ?></td><td><?php echo esc_html( $row['name'] ); ?></td><td><?php echo esc_html( ucfirst( $row['mode'] ) . ' ' . self::format_qty( $row['quantity'] ) . ' ' . $row['unit'] ); ?></td><td><?php echo esc_html( $row['reason'] ?: '—' ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        <?php
    }

    public static function format_qty( $value ) {
        $value = self::decimal( $value );
        if ( abs( $value - round( $value ) ) < 0.0001 ) {
            return number_format_i18n( $value, 0 );
        }
        return rtrim( rtrim( number_format_i18n( $value, 3 ), '0' ), '.' );
    }
}
