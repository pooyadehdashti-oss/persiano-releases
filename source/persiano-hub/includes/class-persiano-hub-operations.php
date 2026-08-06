<?php
/**
 * Persiano operational planning, inventory and shopping management.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Operations {
    const PLAN_POST_TYPE = 'persiano_prod_plan';
    const LIST_POST_TYPE = 'persiano_shop_list';

    const META_STATUS      = '_persiano_ops_status';
    const META_PLAN_FROM   = '_persiano_plan_from';
    const META_PLAN_TO     = '_persiano_plan_to';
    const META_PLAN_RECIPES = '_persiano_plan_recipes';
    const META_PLAN_INGREDIENTS = '_persiano_plan_ingredients';
    const META_PLAN_COMPONENTS = '_persiano_plan_components';
    const META_PLAN_NOTES  = '_persiano_plan_notes';
    const META_PLAN_SOURCE = '_persiano_plan_source';
    const META_PLAN_SYNCED = '_persiano_plan_synced_revision';

    const META_LIST_ITEMS  = '_persiano_shop_items';
    const META_LIST_NOTES  = '_persiano_shop_notes';
    const META_LIST_SOURCE = '_persiano_shop_source';
    const META_LIST_SYNCED = '_persiano_shop_synced_revision';

    const ING_ARCHIVED     = '_persiano_ing_archived';
    const ING_AI_EXCLUDE   = '_persiano_ing_ai_exclude';
    const ING_INVENTORY_HISTORY = '_persiano_ing_inventory_history';

    private static $calculated_plan_components = array();

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_types' ), 13 );

        add_action( 'admin_post_persiano_hub_create_production_plan', array( __CLASS__, 'create_production_plan' ) );
        add_action( 'admin_post_persiano_hub_save_production_plan', array( __CLASS__, 'save_production_plan' ) );
        add_action( 'admin_post_persiano_hub_plan_quick_action', array( __CLASS__, 'plan_quick_action' ) );
        add_action( 'admin_post_persiano_hub_refresh_production_plan', array( __CLASS__, 'refresh_production_plan' ) );
        add_action( 'admin_post_persiano_hub_export_production_plan', array( __CLASS__, 'export_production_plan' ) );
        add_action( 'admin_post_persiano_hub_export_production_notes', array( __CLASS__, 'export_production_notes' ) );

        add_action( 'admin_post_persiano_hub_create_shopping_list', array( __CLASS__, 'create_shopping_list' ) );
        add_action( 'admin_post_persiano_hub_refresh_shopping_list', array( __CLASS__, 'refresh_shopping_list' ) );
        add_action( 'admin_post_persiano_hub_save_shopping_list', array( __CLASS__, 'save_shopping_list' ) );
        add_action( 'admin_post_persiano_hub_shopping_quick_action', array( __CLASS__, 'shopping_quick_action' ) );
        add_action( 'admin_post_persiano_hub_add_quote_to_list', array( __CLASS__, 'add_quote_to_list' ) );
        add_action( 'admin_post_persiano_hub_print_shopping_list', array( __CLASS__, 'print_shopping_list' ) );

        add_action( 'admin_post_persiano_hub_ingredient_quick_action', array( __CLASS__, 'ingredient_quick_action' ) );
        add_action( 'admin_post_persiano_hub_ingredient_bulk_action', array( __CLASS__, 'ingredient_bulk_action' ) );
        add_action( 'admin_post_persiano_hub_adjust_inventory', array( __CLASS__, 'adjust_inventory_action' ) );
        add_action( 'admin_post_persiano_hub_add_manual_price', array( __CLASS__, 'add_manual_price_action' ) );
        add_action( 'admin_footer', array( __CLASS__, 'admin_footer_scripts' ) );
        add_action( 'woocommerce_new_order', array( __CLASS__, 'bump_data_revision' ) );
        add_action( 'woocommerce_update_order', array( __CLASS__, 'bump_data_revision' ) );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'bump_data_revision' ) );
        add_action( 'save_post_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'bump_data_revision' ) );
        add_action( 'save_post_' . Persiano_Hub_Costing::INGREDIENT_POST_TYPE, array( __CLASS__, 'bump_data_revision' ) );
    }

    public static function bump_data_revision() {
        update_option( 'persiano_hub_ops_revision', microtime( true ), false );
    }

    private static function current_revision() {
        return (float) get_option( 'persiano_hub_ops_revision', 1 );
    }

    /**
     * Return current raw-ingredient inventory converted to a requested unit.
     *
     * @param int    $ingredient_id Ingredient post ID.
     * @param string $unit          Target unit.
     * @return float|null
     */
    public static function inventory_quantity_in_unit( $ingredient_id, $unit ) {
        $unit = sanitize_key( $unit );
        $target_base = self::base_unit( $unit );
        $multiplier = self::unit_multiplier( $unit );
        if ( ! $target_base || $multiplier <= 0 ) {
            return null;
        }
        $base_total = self::inventory_in_base_unit( absint( $ingredient_id ), $target_base );
        return $base_total / $multiplier;
    }

    /**
     * Recalculate all open plans after the prepared-component workflow changes.
     * Existing ingredient overrides and include/exclude choices are preserved.
     * Active plans are calculated first so their prepared-stock reservations are
     * deterministic; draft plans can then see what active plans have reserved.
     *
     * @return int Number of plans recalculated.
     */
    public static function recalculate_open_plans_for_inventory_upgrade() {
        $plan_ids = get_posts(
            array(
                'post_type'      => self::PLAN_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'     => self::META_STATUS,
                        'value'   => array( 'draft', 'active' ),
                        'compare' => 'IN',
                    ),
                ),
            )
        );
        if ( ! $plan_ids ) {
            return 0;
        }

        usort(
            $plan_ids,
            static function( $a, $b ) {
                $status_a = get_post_meta( $a, self::META_STATUS, true );
                $status_b = get_post_meta( $b, self::META_STATUS, true );
                if ( $status_a !== $status_b ) {
                    return 'active' === $status_a ? -1 : 1;
                }
                $from_a = (string) get_post_meta( $a, self::META_PLAN_FROM, true );
                $from_b = (string) get_post_meta( $b, self::META_PLAN_FROM, true );
                if ( $from_a === $from_b ) {
                    return (int) $a <=> (int) $b;
                }
                return strcmp( $from_a, $from_b );
            }
        );

        // Remove old reservations before rebuilding them in priority order.
        foreach ( $plan_ids as $plan_id ) {
            delete_post_meta( $plan_id, self::META_PLAN_COMPONENTS );
        }

        self::bump_data_revision();
        $revision = self::current_revision();
        $count = 0;
        foreach ( $plan_ids as $plan_id ) {
            $recipes = get_post_meta( $plan_id, self::META_PLAN_RECIPES, true );
            $recipes = is_array( $recipes ) ? $recipes : array();
            $existing = get_post_meta( $plan_id, self::META_PLAN_INGREDIENTS, true );
            $existing = is_array( $existing ) ? $existing : array();
            $ingredients = self::plan_ingredient_rows( $recipes, $existing, $plan_id );
            update_post_meta( $plan_id, self::META_PLAN_INGREDIENTS, $ingredients );
            update_post_meta( $plan_id, self::META_PLAN_COMPONENTS, self::$calculated_plan_components );
            update_post_meta( $plan_id, self::META_PLAN_SYNCED, $revision );
            $count++;
        }
        return $count;
    }


    public static function admin_footer_scripts() {
        $screen = get_current_screen();
        if ( ! $screen || false === strpos( $screen->id, Persiano_Hub_Costing::MENU_SLUG ) ) {
            return;
        }
        ?>
        <script>
        (function(){
            function reindex(table){
                if(!table) return;
                table.querySelectorAll('tbody tr').forEach(function(row,index){
                    row.querySelectorAll('[name]').forEach(function(field){
                        field.name=field.name.replace(/\[[^\]]+\](?=\[[^\]]+\]$)/,'['+index+']');
                    });
                });
            }
            function addFromTemplate(templateId, tableId){
                var t=document.getElementById(templateId), table=document.getElementById(tableId);
                if(!t||!table) return;
                var index=table.querySelectorAll('tbody tr').length;
                table.querySelector('tbody').insertAdjacentHTML('beforeend',t.innerHTML.replace(/__INDEX__/g,index));
            }
            document.addEventListener('click',function(e){
                if(e.target.closest('.ph-add-plan-recipe')){e.preventDefault();addFromTemplate('ph-plan-recipe-template','ph-plan-recipes');}
                if(e.target.closest('.ph-add-plan-ingredient')){e.preventDefault();addFromTemplate('ph-plan-ingredient-template','ph-plan-ingredients');}
                if(e.target.closest('.ph-add-shop-item')){e.preventDefault();addFromTemplate('ph-shop-item-template','ph-shop-items');}
                var del=e.target.closest('.ph-row-delete');
                if(del){e.preventDefault();var table=del.closest('table');del.closest('tr').remove();reindex(table);}
                var dup=e.target.closest('.ph-row-duplicate');
                if(dup){e.preventDefault();var row=dup.closest('tr'),clone=row.cloneNode(true),table=dup.closest('table');row.after(clone);reindex(table);}
                var toggle=e.target.closest('.ph-row-toggle');
                if(toggle){e.preventDefault();var cb=toggle.closest('tr').querySelector('input[type=checkbox][name$="[include]"]');if(cb){cb.checked=!cb.checked;toggle.textContent=cb.checked?'Exclude':'Include';toggle.closest('tr').classList.toggle('ph-row-excluded',!cb.checked);}}
            });
        })();
        </script>
        <?php
    }

    public static function register_post_types() {
        $caps = array( 'create_posts' => 'do_not_allow' );
        register_post_type(
            self::PLAN_POST_TYPE,
            array(
                'label'        => __( 'Production Plans', 'persiano-hub' ),
                'public'       => false,
                'show_ui'      => false,
                'supports'     => array( 'title' ),
                'map_meta_cap' => true,
                'capabilities' => $caps,
            )
        );
        register_post_type(
            self::LIST_POST_TYPE,
            array(
                'label'        => __( 'Shopping Lists', 'persiano-hub' ),
                'public'       => false,
                'show_ui'      => false,
                'supports'     => array( 'title' ),
                'map_meta_cap' => true,
                'capabilities' => $caps,
            )
        );
    }

    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage Persiano operations.', 'persiano-hub' ) );
        }
    }

    private static function decimal( $value, $default = 0 ) {
        if ( '' === $value || null === $value ) {
            return (float) $default;
        }
        return is_numeric( $value ) ? (float) $value : (float) $default;
    }

    private static function money( $value ) {
        return function_exists( 'wc_price' ) ? wc_price( (float) $value ) : '$' . number_format_i18n( (float) $value, 2 );
    }


    private static function format_quantity( $value ) {
        $value = (float) $value;
        if ( abs( $value - round( $value ) ) < 0.0001 ) {
            return number_format_i18n( $value, 0 );
        }
        return rtrim( rtrim( number_format_i18n( $value, 3 ), '0' ), '.' );
    }

    /**
     * Return a customer-friendly normalized comparison price.
     *
     * Internal costing stores mass prices per gram and volume prices per millilitre.
     * Those values are correct for calculations, but displaying $0.0022 / g as
     * currency rounds to $0.00. The price checker therefore presents the same
     * normalized value as $/kg, $/L, or $/each.
     */
    private static function normalized_price_display( $unit_cost, $base_unit ) {
        $unit_cost = (float) $unit_cost;
        if ( 'g' === $base_unit ) {
            return array( 'price' => $unit_cost * 1000, 'unit' => 'kg' );
        }
        if ( 'ml' === $base_unit ) {
            return array( 'price' => $unit_cost * 1000, 'unit' => 'L' );
        }
        return array( 'price' => $unit_cost, 'unit' => 'each' );
    }

    private static function page_url( $tab, $args = array() ) {
        return add_query_arg(
            array_merge(
                array(
                    'page' => Persiano_Hub_Costing::MENU_SLUG,
                    'tab'  => $tab,
                ),
                $args
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function status_options() {
        return array(
            'draft'    => __( 'Draft', 'persiano-hub' ),
            'active'   => __( 'Active', 'persiano-hub' ),
            'done'     => __( 'Done', 'persiano-hub' ),
            'archived' => __( 'Archived', 'persiano-hub' ),
        );
    }

    private static function clean_status( $status ) {
        $status = sanitize_key( $status );
        return isset( self::status_options()[ $status ] ) ? $status : 'draft';
    }

    private static function post_status_value( $post_id ) {
        return self::clean_status( get_post_meta( $post_id, self::META_STATUS, true ) );
    }

    private static function operation_posts( $post_type, $statuses = array( 'draft', 'active' ) ) {
        $ids = get_posts(
            array(
                'post_type'      => $post_type,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
        if ( ! $statuses ) {
            return $ids;
        }
        return array_values(
            array_filter(
                $ids,
                function( $id ) use ( $statuses ) {
                    return in_array( self::post_status_value( $id ), $statuses, true );
                }
            )
        );
    }

    private static function render_record_navigation( $id, $post_type, $tab, $param ) {
        $ids = self::operation_posts( $post_type, array() );
        $ids = array_values( array_map( 'intval', $ids ) );
        $index = array_search( (int) $id, $ids, true );
        $prev = false !== $index && $index > 0 ? $ids[ $index - 1 ] : 0;
        $next = false !== $index && $index < count( $ids ) - 1 ? $ids[ $index + 1 ] : 0;
        echo '<div class="ph-global-record-nav ph-global-record-nav--ops">';
        echo $prev ? '<a class="button" href="' . esc_url( self::page_url( $tab, array( $param => $prev ) ) ) . '">← ' . esc_html__( 'Previous', 'persiano-hub' ) . '</a>' : '<span class="button disabled">← ' . esc_html__( 'Previous', 'persiano-hub' ) . '</span>';
        echo '<a class="button ph-global-record-nav__list" href="' . esc_url( self::page_url( $tab ) ) . '">' . esc_html__( 'Back to list', 'persiano-hub' ) . '</a>';
        echo $next ? '<a class="button" href="' . esc_url( self::page_url( $tab, array( $param => $next ) ) ) . '">' . esc_html__( 'Next', 'persiano-hub' ) . ' →</a>' : '<span class="button disabled">' . esc_html__( 'Next', 'persiano-hub' ) . ' →</span>';
        echo '</div>';
    }

    private static function ingredient_ids( $include_archived = false ) {
        $ids = get_posts(
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
        if ( $include_archived ) {
            return $ids;
        }
        return array_values(
            array_filter(
                $ids,
                function( $id ) {
                    return ! (bool) get_post_meta( $id, self::ING_ARCHIVED, true );
                }
            )
        );
    }

    private static function recipe_ids() {
        return get_posts(
            array(
                'post_type'      => Persiano_Hub_Costing::RECIPE_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
    }

    private static function select_ingredient( $name, $selected = 0, $class = '' ) {
        echo '<select name="' . esc_attr( $name ) . '" class="' . esc_attr( $class ) . '"><option value="0">' . esc_html__( 'Select ingredient…', 'persiano-hub' ) . '</option>';
        foreach ( self::ingredient_ids( true ) as $id ) {
            echo '<option value="' . esc_attr( $id ) . '" ' . selected( $selected, $id, false ) . '>' . esc_html( get_the_title( $id ) ) . '</option>';
        }
        echo '</select>';
    }

    private static function select_recipe( $name, $selected = 0 ) {
        echo '<select name="' . esc_attr( $name ) . '"><option value="0">' . esc_html__( 'Select recipe…', 'persiano-hub' ) . '</option>';
        foreach ( self::recipe_ids() as $id ) {
            echo '<option value="' . esc_attr( $id ) . '" ' . selected( $selected, $id, false ) . '>' . esc_html( get_the_title( $id ) ) . '</option>';
        }
        echo '</select>';
    }

    private static function select_status( $name, $selected ) {
        echo '<select name="' . esc_attr( $name ) . '">';
        foreach ( self::status_options() as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    /* --------------------------------------------------------------------- */
    /* Inventory and ingredient quick actions                                */
    /* --------------------------------------------------------------------- */

    public static function adjust_inventory( $ingredient_id, $mode, $quantity, $unit = '', $reason = '', $note = '', $source = 'manual', $lot_code = '', $best_before = '', $location = '' ) {
        $ingredient_id = absint( $ingredient_id );
        if ( ! $ingredient_id || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $ingredient_id ) ) {
            return new WP_Error( 'persiano_invalid_ingredient', __( 'Ingredient could not be found.', 'persiano-hub' ) );
        }

        $quantity = max( 0, self::decimal( $quantity ) );
        $current  = max( 0, self::decimal( get_post_meta( $ingredient_id, Persiano_Hub_Kitchen::ING_ON_HAND_QTY, true ) ) );
        $current_unit = sanitize_key( get_post_meta( $ingredient_id, Persiano_Hub_Kitchen::ING_ON_HAND_UNIT, true ) );
        $unit = sanitize_key( $unit ? $unit : $current_unit );
        if ( ! array_key_exists( $unit, Persiano_Hub_Costing::unit_options() ) ) {
            $unit = $current_unit && array_key_exists( $current_unit, Persiano_Hub_Costing::unit_options() ) ? $current_unit : 'each';
        }
        if ( $current_unit && ! array_key_exists( $current_unit, Persiano_Hub_Costing::unit_options() ) ) {
            $current_unit = '';
        }

        $mode = sanitize_key( $mode );
        $stored_unit = $current_unit ? $current_unit : $unit;
        $original_quantity = $quantity;
        $original_unit = $unit;

        if ( 'set' === $mode ) {
            // A direct stock count is stored exactly in the unit selected by the user.
            $stored_unit = $unit;
            $old_in_new_unit = $current_unit ? self::convert_quantity( $current, $current_unit, $stored_unit ) : $current;
            if ( null === $old_in_new_unit ) {
                $old_in_new_unit = 0;
            }
            $new = $quantity;
            $change = $new - $old_in_new_unit;
        } else {
            // Add/subtract adjustments are converted into the unit already used by inventory.
            $converted = self::convert_quantity( $quantity, $unit, $stored_unit );
            if ( null === $converted ) {
                return new WP_Error(
                    'persiano_inventory_unit_mismatch',
                    sprintf(
                        /* translators: 1: adjustment unit, 2: inventory unit */
                        __( 'Cannot apply an inventory adjustment in %1$s to stock stored in %2$s. Use a compatible unit or use Set quantity.', 'persiano-hub' ),
                        $unit,
                        $stored_unit
                    )
                );
            }
            if ( in_array( $mode, array( 'subtract', 'waste', 'spoilage', 'personal' ), true ) ) {
                $change = -abs( $converted );
            } else {
                $change = abs( $converted );
                $mode = 'add';
            }
            $new = max( 0, $current + $change );
            // Record the effective change after preventing stock from going below zero.
            $change = $new - $current;
        }

        update_post_meta( $ingredient_id, Persiano_Hub_Kitchen::ING_ON_HAND_QTY, $new );
        update_post_meta( $ingredient_id, Persiano_Hub_Kitchen::ING_ON_HAND_UNIT, $stored_unit );

        $history = get_post_meta( $ingredient_id, self::ING_INVENTORY_HISTORY, true );
        $history = is_array( $history ) ? $history : array();
        $history[] = array(
            'time'              => time(),
            'mode'              => $mode,
            'change'            => $change,
            'quantity'          => $original_quantity,
            'quantity_unit'     => $original_unit,
            'new_total'         => $new,
            'unit'              => $stored_unit,
            'reason'            => sanitize_text_field( $reason ),
            'note'              => sanitize_textarea_field( $note ),
            'source'            => sanitize_key( $source ),
            'user_id'           => get_current_user_id(),
            'lot_code'           => sanitize_text_field( $lot_code ),
            'best_before'        => sanitize_text_field( $best_before ),
            'location'           => sanitize_text_field( $location ),
        );
        if ( count( $history ) > 100 ) {
            $history = array_slice( $history, -100 );
        }
        update_post_meta( $ingredient_id, self::ING_INVENTORY_HISTORY, $history );
        return $new;
    }

    public static function add_price_record( $ingredient_id, $data, $source_type = 'observation' ) {
        $ingredient_id = absint( $ingredient_id );
        if ( ! $ingredient_id || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $ingredient_id ) ) {
            return new WP_Error( 'persiano_invalid_ingredient', __( 'Ingredient could not be found.', 'persiano-hub' ) );
        }
        $unit = sanitize_key( isset( $data['purchase_unit'] ) ? $data['purchase_unit'] : 'each' );
        if ( ! array_key_exists( $unit, Persiano_Hub_Costing::unit_options() ) ) {
            $unit = 'each';
        }
        $qty = max( 0, self::decimal( isset( $data['purchase_qty'] ) ? $data['purchase_qty'] : 0 ) );
        $subtotal = max( 0, self::decimal( isset( $data['purchase_cost'] ) ? $data['purchase_cost'] : 0 ) );
        $tax = max( 0, self::decimal( isset( $data['purchase_tax'] ) ? $data['purchase_tax'] : 0 ) );
        $gross = $subtotal + $tax;
        $waste = min( 99, max( 0, self::decimal( isset( $data['waste_pct'] ) ? $data['waste_pct'] : 0 ) ) );
        $multiplier = self::unit_multiplier( $unit );
        $usable = $qty * $multiplier * ( 1 - ( $waste / 100 ) );
        $base_unit = self::base_unit( $unit );
        $unit_cost = $usable > 0 ? $gross / $usable : 0;

        $history = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, true );
        $history = is_array( $history ) ? $history : array();
        $history[] = array(
            'time'          => ! empty( $data['time'] ) ? absint( $data['time'] ) : time(),
            'purchase_qty'  => $qty,
            'purchase_unit' => $unit,
            'purchase_cost' => $subtotal,
            'purchase_tax'  => $tax,
            'gross_cost'    => $gross,
            'waste_pct'     => $waste,
            'unit_cost'     => $unit_cost,
            'base_unit'     => $base_unit,
            'brand'         => sanitize_text_field( isset( $data['brand'] ) ? $data['brand'] : '' ),
            'supplier'      => sanitize_text_field( isset( $data['supplier'] ) ? $data['supplier'] : '' ),
            'source'        => sanitize_key( isset( $data['source'] ) ? $data['source'] : 'manual' ),
            'source_type'   => sanitize_key( $source_type ),
            'valid_until'   => sanitize_text_field( isset( $data['valid_until'] ) ? $data['valid_until'] : '' ),
            'notes'         => sanitize_textarea_field( isset( $data['notes'] ) ? $data['notes'] : '' ),
        );
        if ( count( $history ) > 100 ) {
            $history = array_slice( $history, -100 );
        }
        update_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, $history );
        return true;
    }

    private static function unit_multiplier( $unit ) {
        $map = array( 'g'=>1, 'kg'=>1000, 'oz'=>28.349523125, 'lb'=>453.59237, 'ml'=>1, 'l'=>1000, 'tsp'=>4.92892159375, 'tbsp'=>14.78676478125, 'cup'=>236.5882365, 'each'=>1 );
        return isset( $map[ $unit ] ) ? (float) $map[ $unit ] : 0;
    }

    private static function base_unit( $unit ) {
        if ( in_array( $unit, array( 'g','kg','oz','lb' ), true ) ) { return 'g'; }
        if ( in_array( $unit, array( 'ml','l','tsp','tbsp','cup' ), true ) ) { return 'ml'; }
        return 'each';
    }

    private static function convert_quantity( $quantity, $from_unit, $to_unit ) {
        $quantity = self::decimal( $quantity );
        $from_unit = sanitize_key( $from_unit );
        $to_unit = sanitize_key( $to_unit );
        if ( $from_unit === $to_unit ) {
            return $quantity;
        }
        if ( self::base_unit( $from_unit ) !== self::base_unit( $to_unit ) ) {
            return null;
        }
        $from_multiplier = self::unit_multiplier( $from_unit );
        $to_multiplier = self::unit_multiplier( $to_unit );
        if ( $from_multiplier <= 0 || $to_multiplier <= 0 ) {
            return null;
        }
        return ( $quantity * $from_multiplier ) / $to_multiplier;
    }

    public static function adjust_inventory_action() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_adjust_inventory' );
        $id = absint( isset( $_POST['ingredient_id'] ) ? $_POST['ingredient_id'] : 0 );
        $result = self::adjust_inventory(
            $id,
            isset( $_POST['mode'] ) ? wp_unslash( $_POST['mode'] ) : 'add',
            isset( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : 0,
            isset( $_POST['unit'] ) ? wp_unslash( $_POST['unit'] ) : '',
            isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '',
            isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '',
            'manual',
            isset( $_POST['lot_code'] ) ? wp_unslash( $_POST['lot_code'] ) : '',
            isset( $_POST['best_before'] ) ? wp_unslash( $_POST['best_before'] ) : '',
            isset( $_POST['location'] ) ? wp_unslash( $_POST['location'] ) : ''
        );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( self::page_url( 'ingredients', array( 'inventory_error' => rawurlencode( $result->get_error_message() ) ) ) );
            exit;
        }
        wp_safe_redirect( self::page_url( 'ingredients', array( 'inventory_saved' => 1 ) ) );
        exit;
    }

    public static function add_manual_price_action() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_add_manual_price' );
        $id = absint( isset( $_POST['ingredient_id'] ) ? $_POST['ingredient_id'] : 0 );
        $source_type = isset( $_POST['source_type'] ) ? sanitize_key( wp_unslash( $_POST['source_type'] ) ) : 'observation';
        $data = array(
            'name'          => get_the_title( $id ),
            'category'      => get_post_meta( $id, Persiano_Hub_Costing::ING_CATEGORY, true ),
            'purchase_qty'  => isset( $_POST['purchase_qty'] ) ? wp_unslash( $_POST['purchase_qty'] ) : 0,
            'purchase_unit' => isset( $_POST['purchase_unit'] ) ? wp_unslash( $_POST['purchase_unit'] ) : 'each',
            'purchase_cost' => isset( $_POST['purchase_cost'] ) ? wp_unslash( $_POST['purchase_cost'] ) : 0,
            'purchase_tax'  => isset( $_POST['purchase_tax'] ) ? wp_unslash( $_POST['purchase_tax'] ) : 0,
            'waste_pct'     => isset( $_POST['waste_pct'] ) ? wp_unslash( $_POST['waste_pct'] ) : 0,
            'brand'         => isset( $_POST['brand'] ) ? wp_unslash( $_POST['brand'] ) : '',
            'supplier'      => isset( $_POST['supplier'] ) ? wp_unslash( $_POST['supplier'] ) : '',
            'valid_until'   => isset( $_POST['valid_until'] ) ? wp_unslash( $_POST['valid_until'] ) : '',
            'notes'         => isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '',
            'source'        => 'manual',
        );
        if ( 'purchase' === $source_type ) {
            Persiano_Hub_Costing::save_imported_ingredient( $id, $data, 'manual_purchase' );
        } else {
            self::add_price_record( $id, $data, 'observation' );
        }
        wp_safe_redirect( self::page_url( 'ingredients', array( 'price_saved' => 1 ) ) );
        exit;
    }

    public static function ingredient_quick_action() {
        self::require_permission();
        $id = absint( isset( $_GET['ingredient_id'] ) ? $_GET['ingredient_id'] : 0 );
        $do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
        check_admin_referer( 'persiano_hub_ingredient_' . $do . '_' . $id );
        if ( ! $id || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $id ) ) {
            wp_die( esc_html__( 'Ingredient not found.', 'persiano-hub' ) );
        }
        if ( 'duplicate' === $do ) {
            self::duplicate_ingredient( $id );
        } elseif ( 'archive' === $do ) {
            update_post_meta( $id, self::ING_ARCHIVED, 1 );
        } elseif ( 'restore' === $do ) {
            delete_post_meta( $id, self::ING_ARCHIVED );
        } elseif ( 'exclude_ai' === $do ) {
            update_post_meta( $id, self::ING_AI_EXCLUDE, 1 );
        } elseif ( 'include_ai' === $do ) {
            delete_post_meta( $id, self::ING_AI_EXCLUDE );
        } elseif ( 'trash' === $do ) {
            wp_trash_post( $id );
        }
        wp_safe_redirect( self::page_url( 'ingredients' ) );
        exit;
    }

    private static function duplicate_ingredient( $id ) {
        $new_id = wp_insert_post(
            array(
                'post_type'   => Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
                'post_status' => 'draft',
                'post_title'  => get_the_title( $id ) . ' ' . __( 'Copy', 'persiano-hub' ),
            )
        );
        if ( ! $new_id || is_wp_error( $new_id ) ) {
            return 0;
        }
        $keys = array(
            Persiano_Hub_Costing::ING_CATEGORY,
            Persiano_Hub_Costing::ING_BRAND,
            Persiano_Hub_Costing::ING_PURCHASE_QTY,
            Persiano_Hub_Costing::ING_PURCHASE_UNIT,
            Persiano_Hub_Costing::ING_PURCHASE_COST,
            Persiano_Hub_Costing::ING_PURCHASE_TAX,
            Persiano_Hub_Costing::ING_GROSS_COST,
            Persiano_Hub_Costing::ING_WASTE_PCT,
            Persiano_Hub_Costing::ING_SUPPLIER,
            Persiano_Hub_Costing::ING_NOTES,
            Persiano_Hub_Costing::ING_UNIT_COST,
            Persiano_Hub_Costing::ING_BASE_UNIT,
            Persiano_Hub_Kitchen::ING_IMAGE_ID,
            Persiano_Hub_Kitchen::ING_ON_HAND_UNIT,
        );
        foreach ( $keys as $key ) {
            $value = get_post_meta( $id, $key, true );
            if ( '' !== $value ) {
                update_post_meta( $new_id, $key, $value );
            }
        }
        return $new_id;
    }

    public static function ingredient_bulk_action() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_ingredient_bulk_action' );
        $action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $ids = isset( $_POST['ingredient_ids'] ) && is_array( $_POST['ingredient_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ingredient_ids'] ) ) : array();
        foreach ( $ids as $id ) {
            if ( Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $id ) ) { continue; }
            if ( 'archive' === $action ) { update_post_meta( $id, self::ING_ARCHIVED, 1 ); }
            elseif ( 'restore' === $action ) { delete_post_meta( $id, self::ING_ARCHIVED ); }
            elseif ( 'exclude_ai' === $action ) { update_post_meta( $id, self::ING_AI_EXCLUDE, 1 ); }
            elseif ( 'include_ai' === $action ) { delete_post_meta( $id, self::ING_AI_EXCLUDE ); }
            elseif ( 'trash' === $action ) { wp_trash_post( $id ); }
        }
        wp_safe_redirect( self::page_url( 'ingredients' ) );
        exit;
    }

    private static function ingredient_action_url( $id, $do ) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action'        => 'persiano_hub_ingredient_quick_action',
                    'ingredient_id' => $id,
                    'do'            => $do,
                ),
                admin_url( 'admin-post.php' )
            ),
            'persiano_hub_ingredient_' . $do . '_' . $id
        );
    }

    public static function render_ingredients_tab() {
        self::require_permission();
        $show = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ids = self::ingredient_ids( true );
        if ( 'active' === $show ) {
            $ids = array_values( array_filter( $ids, function( $id ) { return ! get_post_meta( $id, self::ING_ARCHIVED, true ); } ) );
        } elseif ( 'archived' === $show ) {
            $ids = array_values( array_filter( $ids, function( $id ) { return (bool) get_post_meta( $id, self::ING_ARCHIVED, true ); } ) );
        }

        $action_id = isset( $_GET['ingredient_id'] ) ? absint( $_GET['ingredient_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $form_action = isset( $_GET['ingredient_action'] ) ? sanitize_key( wp_unslash( $_GET['ingredient_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact">
            <div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Cost database', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Ingredients', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Manage ingredients, inventory, purchase history and price observations from one table.', 'persiano-hub' ); ?></p></div>
            <div class="ph-costing-actions"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Persiano_Hub_Costing::INGREDIENT_POST_TYPE ) ); ?>"><?php esc_html_e( 'Add ingredient', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( self::page_url( 'scan' ) ); ?>"><?php esc_html_e( 'AI Scan', 'persiano-hub' ); ?></a></div>
        </div>
        <p class="subsubsub"><a class="<?php echo 'active' === $show ? 'current' : ''; ?>" href="<?php echo esc_url( self::page_url( 'ingredients', array( 'show' => 'active' ) ) ); ?>"><?php esc_html_e( 'Active', 'persiano-hub' ); ?></a> | <a class="<?php echo 'archived' === $show ? 'current' : ''; ?>" href="<?php echo esc_url( self::page_url( 'ingredients', array( 'show' => 'archived' ) ) ); ?>"><?php esc_html_e( 'Archived', 'persiano-hub' ); ?></a> | <a class="<?php echo 'all' === $show ? 'current' : ''; ?>" href="<?php echo esc_url( self::page_url( 'ingredients', array( 'show' => 'all' ) ) ); ?>"><?php esc_html_e( 'All', 'persiano-hub' ); ?></a></p>
        <div style="clear:both"></div>
        <?php
        if ( ! empty( $_GET['inventory_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Inventory updated.', 'persiano-hub' ) . '</p></div>';
        }
        if ( ! empty( $_GET['price_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Price record saved.', 'persiano-hub' ) . '</p></div>';
        }
        if ( ! empty( $_GET['inventory_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['inventory_error'] ) ) ) . '</p></div>';
        }
        if ( $action_id && 'stock' === $form_action ) {
            self::render_inventory_adjustment_form( $action_id );
        } elseif ( $action_id && 'price' === $form_action ) {
            self::render_manual_price_form( $action_id );
        }
        ?>
        <section class="ph-costing-panel">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="persiano_hub_ingredient_bulk_action">
                <?php wp_nonce_field( 'persiano_hub_ingredient_bulk_action' ); ?>
                <div class="ph-costing-heading-row"><div><select name="bulk_action"><option value=""><?php esc_html_e( 'Bulk actions…', 'persiano-hub' ); ?></option><option value="archive"><?php esc_html_e( 'Archive', 'persiano-hub' ); ?></option><option value="restore"><?php esc_html_e( 'Restore', 'persiano-hub' ); ?></option><option value="exclude_ai"><?php esc_html_e( 'Exclude from AI matching', 'persiano-hub' ); ?></option><option value="include_ai"><?php esc_html_e( 'Include in AI matching', 'persiano-hub' ); ?></option><option value="trash"><?php esc_html_e( 'Move to Trash', 'persiano-hub' ); ?></option></select> <button class="button"><?php esc_html_e( 'Apply', 'persiano-hub' ); ?></button></div></div>
                <div class="ph-costing-table-wrap"><table class="widefat striped ph-costing-table"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.ph-ing-check').forEach(cb=>cb.checked=this.checked)"></td><th><?php esc_html_e( 'Ingredient', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Brand / Category', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Inventory', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Latest cost', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Data quality', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Quick actions', 'persiano-hub' ); ?></th></tr></thead><tbody>
                <?php if ( ! $ids ) : ?><tr><td colspan="7"><?php esc_html_e( 'No ingredients in this view.', 'persiano-hub' ); ?></td></tr><?php endif; ?>
                <?php foreach ( $ids as $id ) :
                    $brand = get_post_meta( $id, Persiano_Hub_Costing::ING_BRAND, true );
                    $category = get_post_meta( $id, Persiano_Hub_Costing::ING_CATEGORY, true );
                    $supplier = get_post_meta( $id, Persiano_Hub_Costing::ING_SUPPLIER, true );
                    $gross = (float) get_post_meta( $id, Persiano_Hub_Costing::ING_GROSS_COST, true );
                    $qty = get_post_meta( $id, Persiano_Hub_Costing::ING_PURCHASE_QTY, true );
                    $punit = get_post_meta( $id, Persiano_Hub_Costing::ING_PURCHASE_UNIT, true );
                    $on = get_post_meta( $id, Persiano_Hub_Kitchen::ING_ON_HAND_QTY, true );
                    $onunit = get_post_meta( $id, Persiano_Hub_Kitchen::ING_ON_HAND_UNIT, true );
                    $history = get_post_meta( $id, Persiano_Hub_Costing::ING_HISTORY, true );
                    $history = is_array( $history ) ? $history : array();
                    $last_time = $history ? absint( end( $history )['time'] ?? 0 ) : 0;
                    $age = $last_time ? floor( ( time() - $last_time ) / DAY_IN_SECONDS ) : null;
                    $flags = array();
                    if ( ! $gross ) { $flags[] = __( 'No price', 'persiano-hub' ); }
                    if ( null !== $age && $age > 180 ) { $flags[] = __( 'Price > 6 months old', 'persiano-hub' ); }
                    if ( get_post_meta( $id, self::ING_AI_EXCLUDE, true ) ) { $flags[] = __( 'AI excluded', 'persiano-hub' ); }
                    ?>
                    <tr><th class="check-column"><input class="ph-ing-check" type="checkbox" name="ingredient_ids[]" value="<?php echo esc_attr( $id ); ?>"></th>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><strong><?php echo esc_html( get_the_title( $id ) ); ?></strong></a><br><small><?php echo $supplier ? esc_html( $supplier ) : '—'; ?></small></td>
                    <td><?php echo $brand ? esc_html( $brand ) : '—'; ?><br><small><?php echo $category ? esc_html( $category ) : '—'; ?></small></td>
                    <td><strong><?php echo esc_html( ( '' === $on ? '0' : $on ) . ' ' . $onunit ); ?></strong></td>
                    <td><?php echo $gross > 0 ? wp_kses_post( self::money( $gross ) ) : '—'; ?><br><small><?php echo esc_html( $qty . ' ' . $punit ); ?></small></td>
                    <td><?php echo $flags ? esc_html( implode( ' · ', $flags ) ) : '<span class="dashicons dashicons-yes-alt"></span>'; ?></td>
                    <td class="ph-quick-actions"><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'persiano-hub' ); ?></a> · <a href="<?php echo esc_url( self::page_url( 'ingredients', array( 'ingredient_action'=>'stock','ingredient_id'=>$id ) ) ); ?>"><?php esc_html_e( 'Stock', 'persiano-hub' ); ?></a> · <a href="<?php echo esc_url( self::page_url( 'ingredients', array( 'ingredient_action'=>'price','ingredient_id'=>$id ) ) ); ?>"><?php esc_html_e( 'Add price', 'persiano-hub' ); ?></a> · <a href="<?php echo esc_url( self::page_url( 'price_checker', array( 'ingredient_id'=>$id ) ) ); ?>"><?php esc_html_e( 'Compare / Add to list', 'persiano-hub' ); ?></a><br>
                    <a href="<?php echo esc_url( self::ingredient_action_url( $id, 'duplicate' ) ); ?>"><?php esc_html_e( 'Duplicate', 'persiano-hub' ); ?></a> · <?php if ( get_post_meta( $id, self::ING_AI_EXCLUDE, true ) ) : ?><a href="<?php echo esc_url( self::ingredient_action_url( $id, 'include_ai' ) ); ?>"><?php esc_html_e( 'Include in AI', 'persiano-hub' ); ?></a><?php else : ?><a href="<?php echo esc_url( self::ingredient_action_url( $id, 'exclude_ai' ) ); ?>"><?php esc_html_e( 'Exclude from AI', 'persiano-hub' ); ?></a><?php endif; ?> · <a href="<?php echo esc_url( get_edit_post_link( $id ) . '#persiano_hub_ingredient_history' ); ?>"><?php esc_html_e( 'History', 'persiano-hub' ); ?></a><br><?php if ( get_post_meta( $id, self::ING_ARCHIVED, true ) ) : ?><a href="<?php echo esc_url( self::ingredient_action_url( $id, 'restore' ) ); ?>"><?php esc_html_e( 'Restore', 'persiano-hub' ); ?></a><?php else : ?><a href="<?php echo esc_url( self::ingredient_action_url( $id, 'archive' ) ); ?>"><?php esc_html_e( 'Archive', 'persiano-hub' ); ?></a><?php endif; ?> · <a onclick="return confirm('<?php echo esc_js( __( 'Move this ingredient to Trash?', 'persiano-hub' ) ); ?>')" href="<?php echo esc_url( self::ingredient_action_url( $id, 'trash' ) ); ?>"><?php esc_html_e( 'Trash', 'persiano-hub' ); ?></a></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </form>
        </section>
        <?php
    }

    private static function render_inventory_adjustment_form( $id ) {
        $current = get_post_meta( $id, Persiano_Hub_Kitchen::ING_ON_HAND_QTY, true );
        $unit = get_post_meta( $id, Persiano_Hub_Kitchen::ING_ON_HAND_UNIT, true );
        ?>
        <section class="ph-costing-panel"><h2><?php printf( esc_html__( 'Adjust inventory: %s', 'persiano-hub' ), esc_html( get_the_title( $id ) ) ); ?></h2><p><?php printf( esc_html__( 'Current on hand: %1$s %2$s', 'persiano-hub' ), esc_html( $current ? $current : 0 ), esc_html( $unit ) ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_adjust_inventory"><input type="hidden" name="ingredient_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'persiano_hub_adjust_inventory' ); ?>
        <label><?php esc_html_e( 'Action', 'persiano-hub' ); ?> <select name="mode"><option value="add"><?php esc_html_e( 'Add stock', 'persiano-hub' ); ?></option><option value="subtract"><?php esc_html_e( 'Remove stock', 'persiano-hub' ); ?></option><option value="set"><?php esc_html_e( 'Set exact quantity', 'persiano-hub' ); ?></option><option value="waste"><?php esc_html_e( 'Waste', 'persiano-hub' ); ?></option><option value="spoilage"><?php esc_html_e( 'Spoilage', 'persiano-hub' ); ?></option><option value="personal"><?php esc_html_e( 'Personal use', 'persiano-hub' ); ?></option></select></label> <label><?php esc_html_e( 'Quantity', 'persiano-hub' ); ?> <input type="number" min="0" step="0.0001" name="quantity" required></label> <label><?php esc_html_e( 'Unit', 'persiano-hub' ); ?> <select name="unit"><?php foreach ( Persiano_Hub_Costing::unit_options() as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $unit, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><br><br><label><?php esc_html_e( 'Reason / note', 'persiano-hub' ); ?> <input type="text" class="regular-text" name="reason"></label> <input type="text" class="regular-text" name="note" placeholder="<?php esc_attr_e( 'Optional detail', 'persiano-hub' ); ?>"><br><br><label><?php esc_html_e( 'Lot / batch code', 'persiano-hub' ); ?> <input type="text" name="lot_code"></label> <label><?php esc_html_e( 'Best before', 'persiano-hub' ); ?> <input type="date" name="best_before"></label> <label><?php esc_html_e( 'Location', 'persiano-hub' ); ?> <input type="text" name="location" placeholder="Pantry / fridge / freezer"></label> <?php submit_button( __( 'Save inventory adjustment', 'persiano-hub' ), 'primary', 'submit', false ); ?></form></section>
        <?php
    }

    private static function render_manual_price_form( $id ) {
        ?>
        <section class="ph-costing-panel"><h2><?php printf( esc_html__( 'Add price: %s', 'persiano-hub' ), esc_html( get_the_title( $id ) ) ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_add_manual_price"><input type="hidden" name="ingredient_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'persiano_hub_add_manual_price' ); ?>
        <label><?php esc_html_e( 'Record type', 'persiano-hub' ); ?> <select name="source_type"><option value="observation"><?php esc_html_e( 'Observed price', 'persiano-hub' ); ?></option><option value="purchase"><?php esc_html_e( 'Actual purchase', 'persiano-hub' ); ?></option></select></label> <label><?php esc_html_e( 'Vendor', 'persiano-hub' ); ?> <input type="text" name="supplier" required></label> <label><?php esc_html_e( 'Brand', 'persiano-hub' ); ?> <input type="text" name="brand"></label><br><br>
        <label><?php esc_html_e( 'Package quantity', 'persiano-hub' ); ?> <input type="number" min="0" step="0.0001" name="purchase_qty" required></label> <label><?php esc_html_e( 'Unit', 'persiano-hub' ); ?> <select name="purchase_unit"><?php foreach ( Persiano_Hub_Costing::unit_options() as $value=>$label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label> <label><?php esc_html_e( 'Subtotal', 'persiano-hub' ); ?> <input type="number" min="0" step="0.01" name="purchase_cost" required></label> <label><?php esc_html_e( 'Tax', 'persiano-hub' ); ?> <input type="number" min="0" step="0.01" name="purchase_tax" value="0"></label> <label><?php esc_html_e( 'Waste %', 'persiano-hub' ); ?> <input type="number" min="0" max="99" step="0.1" name="waste_pct" value="0"></label> <label><?php esc_html_e( 'Valid until', 'persiano-hub' ); ?> <input type="date" name="valid_until"></label><br><br><input type="text" class="large-text" name="note" placeholder="<?php esc_attr_e( 'Optional note', 'persiano-hub' ); ?>"> <?php submit_button( __( 'Save price record', 'persiano-hub' ), 'primary', 'submit', false ); ?></form></section>
        <?php
    }

    /* --------------------------------------------------------------------- */
    /* Production plans                                                      */
    /* --------------------------------------------------------------------- */

    private static function plan_recipe_rows_from_orders( $from, $to ) {
        $requirements = Persiano_Hub_Kitchen::production_requirements( $from, $to );
        $rows = array();
        foreach ( $requirements['recipes'] as $recipe_id => $qty ) {
            $rows[] = array(
                'recipe_id'      => (int) $recipe_id,
                'calculated_qty' => (float) $qty,
                'planned_qty'    => (float) $qty,
                'include'        => 1,
            );
        }
        return $rows;
    }

    private static function plan_ingredient_rows( $recipe_rows, $existing_rows = array(), $plan_id = 0 ) {
        $recipe_map = array();
        foreach ( (array) $recipe_rows as $row ) {
            if ( empty( $row['include'] ) ) { continue; }
            $recipe_id = absint( isset( $row['recipe_id'] ) ? $row['recipe_id'] : 0 );
            $qty = max( 0, self::decimal( isset( $row['planned_qty'] ) ? $row['planned_qty'] : 0 ) );
            if ( $recipe_id && $qty > 0 ) { $recipe_map[ $recipe_id ] = isset( $recipe_map[ $recipe_id ] ) ? $recipe_map[ $recipe_id ] + $qty : $qty; }
        }
        $calculated = Persiano_Hub_Kitchen::requirements_from_recipe_quantities( $recipe_map, absint( $plan_id ) );
        self::$calculated_plan_components = Persiano_Hub_Kitchen::last_component_requirements();
        $existing_by_id = array();
        foreach ( (array) $existing_rows as $row ) {
            $id = absint( isset( $row['ingredient_id'] ) ? $row['ingredient_id'] : 0 );
            if ( $id ) { $existing_by_id[ $id ] = $row; }
        }
        $rows = array();
        foreach ( $calculated as $ingredient_id => $data ) {
            $old = isset( $existing_by_id[ $ingredient_id ] ) ? $existing_by_id[ $ingredient_id ] : array();
            $rows[] = array(
                'ingredient_id'  => (int) $ingredient_id,
                'calculated_qty' => (float) $data['required'],
                'planned_qty'    => ( isset( $old['planned_qty'], $old['calculated_qty'] ) && abs( self::decimal( $old['planned_qty'] ) - self::decimal( $old['calculated_qty'] ) ) > 0.0001 ) ? max( 0, self::decimal( $old['planned_qty'] ) ) : (float) $data['required'],
                'base_unit'      => $data['base_unit'],
                'include'        => isset( $old['include'] ) ? (int) ! empty( $old['include'] ) : 1,
                'manual'         => 0,
            );
            unset( $existing_by_id[ $ingredient_id ] );
        }
        foreach ( $existing_by_id as $ingredient_id => $old ) {
            if ( ! empty( $old['manual'] ) ) {
                $rows[] = array(
                    'ingredient_id'  => (int) $ingredient_id,
                    'calculated_qty' => 0,
                    'planned_qty'    => max( 0, self::decimal( isset( $old['planned_qty'] ) ? $old['planned_qty'] : 0 ) ),
                    'base_unit'      => sanitize_key( isset( $old['base_unit'] ) ? $old['base_unit'] : get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BASE_UNIT, true ) ),
                    'include'        => isset( $old['include'] ) ? (int) ! empty( $old['include'] ) : 1,
                    'manual'         => 1,
                );
            }
        }
        return $rows;
    }

    public static function create_production_plan() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_create_production_plan' );
        $mode = isset( $_POST['plan_mode'] ) ? sanitize_key( wp_unslash( $_POST['plan_mode'] ) ) : 'orders';
        $from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : wp_date( 'Y-m-d' );
        $to = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : $from;
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        if ( ! $title ) { $title = 'orders' === $mode ? sprintf( __( 'Production %1$s to %2$s', 'persiano-hub' ), $from, $to ) : __( 'New Production Plan', 'persiano-hub' ); }
        $id = wp_insert_post( array( 'post_type'=>self::PLAN_POST_TYPE, 'post_status'=>'publish', 'post_title'=>$title ) );
        if ( $id && ! is_wp_error( $id ) ) {
            $recipes = 'orders' === $mode ? self::plan_recipe_rows_from_orders( $from, $to ) : array();
            $ingredients = self::plan_ingredient_rows( $recipes, array(), $id );
            update_post_meta( $id, self::META_STATUS, 'draft' );
            update_post_meta( $id, self::META_PLAN_FROM, $from );
            update_post_meta( $id, self::META_PLAN_TO, $to );
            update_post_meta( $id, self::META_PLAN_RECIPES, $recipes );
            update_post_meta( $id, self::META_PLAN_INGREDIENTS, $ingredients );
            update_post_meta( $id, self::META_PLAN_COMPONENTS, self::$calculated_plan_components );
            update_post_meta( $id, self::META_PLAN_SOURCE, $mode );
            update_post_meta( $id, self::META_PLAN_SYNCED, self::current_revision() );
        }
        wp_safe_redirect( self::page_url( 'production', array( 'plan_id'=>$id ) ) ); exit;
    }

    public static function refresh_production_plan() {
        self::require_permission();
        $id = absint( $_POST['plan_id'] ?? 0 );
        check_admin_referer( 'persiano_hub_refresh_production_plan_' . $id );
        if ( ! $id || self::PLAN_POST_TYPE !== get_post_type( $id ) ) { wp_die( esc_html__( 'Production plan not found.', 'persiano-hub' ) ); }
        $from = get_post_meta( $id, self::META_PLAN_FROM, true );
        $to = get_post_meta( $id, self::META_PLAN_TO, true );
        $old_recipes = get_post_meta( $id, self::META_PLAN_RECIPES, true );
        $old_recipes = is_array( $old_recipes ) ? $old_recipes : array();
        $old_by_id = array();
        foreach ( $old_recipes as $row ) { if ( ! empty( $row['recipe_id'] ) ) { $old_by_id[ absint( $row['recipe_id'] ) ] = $row; } }
        $new = self::plan_recipe_rows_from_orders( $from, $to );
        foreach ( $new as &$row ) {
            $old = $old_by_id[ $row['recipe_id'] ] ?? array();
            if ( $old ) {
                $was_manual = abs( self::decimal( $old['planned_qty'] ?? 0 ) - self::decimal( $old['calculated_qty'] ?? 0 ) ) > 0.0001;
                $row['planned_qty'] = $was_manual ? max( 0, self::decimal( $old['planned_qty'] ?? 0 ) ) : $row['calculated_qty'];
                $row['include'] = isset( $old['include'] ) ? (int) ! empty( $old['include'] ) : 1;
                unset( $old_by_id[ $row['recipe_id'] ] );
            }
        }
        unset( $row );
        foreach ( $old_by_id as $old ) {
            if ( abs( self::decimal( $old['planned_qty'] ?? 0 ) - self::decimal( $old['calculated_qty'] ?? 0 ) ) > 0.0001 ) { $new[] = $old; }
        }
        $old_ingredients = get_post_meta( $id, self::META_PLAN_INGREDIENTS, true );
        $ingredients = self::plan_ingredient_rows( $new, is_array( $old_ingredients ) ? $old_ingredients : array(), $id );
        update_post_meta( $id, self::META_PLAN_RECIPES, $new );
        update_post_meta( $id, self::META_PLAN_INGREDIENTS, $ingredients );
        update_post_meta( $id, self::META_PLAN_COMPONENTS, self::$calculated_plan_components );
        update_post_meta( $id, self::META_PLAN_SYNCED, self::current_revision() );
        wp_safe_redirect( self::page_url( 'production', array( 'plan_id'=>$id, 'refreshed'=>1 ) ) ); exit;
    }

    private static function sanitize_recipe_rows( $rows ) {
        $clean = array();
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $id = absint( isset( $row['recipe_id'] ) ? $row['recipe_id'] : 0 );
            if ( ! $id || Persiano_Hub_Costing::RECIPE_POST_TYPE !== get_post_type( $id ) ) { continue; }
            $clean[] = array(
                'recipe_id'      => $id,
                'calculated_qty' => max( 0, self::decimal( isset( $row['calculated_qty'] ) ? $row['calculated_qty'] : 0 ) ),
                'planned_qty'    => max( 0, self::decimal( isset( $row['planned_qty'] ) ? $row['planned_qty'] : 0 ) ),
                'include'        => ! empty( $row['include'] ) ? 1 : 0,
            );
        }
        return $clean;
    }

    private static function sanitize_ingredient_rows( $rows ) {
        $clean = array();
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $id = absint( isset( $row['ingredient_id'] ) ? $row['ingredient_id'] : 0 );
            if ( ! $id || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $id ) ) { continue; }
            $base = sanitize_key( isset( $row['base_unit'] ) ? $row['base_unit'] : get_post_meta( $id, Persiano_Hub_Costing::ING_BASE_UNIT, true ) );
            $clean[] = array(
                'ingredient_id'  => $id,
                'calculated_qty' => max( 0, self::decimal( isset( $row['calculated_qty'] ) ? $row['calculated_qty'] : 0 ) ),
                'planned_qty'    => max( 0, self::decimal( isset( $row['planned_qty'] ) ? $row['planned_qty'] : 0 ) ),
                'base_unit'      => $base,
                'include'        => ! empty( $row['include'] ) ? 1 : 0,
                'manual'         => ! empty( $row['manual'] ) ? 1 : 0,
            );
        }
        return $clean;
    }

    public static function save_production_plan() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_save_production_plan' );
        $id = absint( isset( $_POST['plan_id'] ) ? $_POST['plan_id'] : 0 );
        if ( ! $id || self::PLAN_POST_TYPE !== get_post_type( $id ) ) { wp_die( esc_html__( 'Production plan not found.', 'persiano-hub' ) ); }
        $title = isset( $_POST['plan_title'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_title'] ) ) : get_the_title( $id );
        wp_update_post( array( 'ID'=>$id, 'post_title'=>$title ) );
        $new_status = self::clean_status( isset( $_POST['plan_status'] ) ? wp_unslash( $_POST['plan_status'] ) : 'draft' );
        update_post_meta( $id, self::META_STATUS, $new_status );
        update_post_meta( $id, self::META_PLAN_FROM, isset( $_POST['plan_from'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_from'] ) ) : '' );
        update_post_meta( $id, self::META_PLAN_TO, isset( $_POST['plan_to'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_to'] ) ) : '' );
        update_post_meta( $id, self::META_PLAN_NOTES, isset( $_POST['plan_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['plan_notes'] ) ) : '' );
        $recipes = self::sanitize_recipe_rows( isset( $_POST['plan_recipes'] ) ? wp_unslash( $_POST['plan_recipes'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $posted_ingredients = self::sanitize_ingredient_rows( isset( $_POST['plan_ingredients'] ) ? wp_unslash( $_POST['plan_ingredients'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $ingredients = self::plan_ingredient_rows( $recipes, $posted_ingredients, $id );
        update_post_meta( $id, self::META_PLAN_RECIPES, $recipes );
        update_post_meta( $id, self::META_PLAN_INGREDIENTS, $ingredients );
        update_post_meta( $id, self::META_PLAN_COMPONENTS, self::$calculated_plan_components );
        update_post_meta( $id, self::META_PLAN_SYNCED, self::current_revision() );
        if ( 'done' === $new_status && class_exists( 'Persiano_Hub_Inventory' ) ) {
            Persiano_Hub_Inventory::apply_production_plan_inventory( $id );
        }
        wp_safe_redirect( self::page_url( 'production', array( 'plan_id'=>$id, 'saved'=>1 ) ) ); exit;
    }


    public static function export_production_plan() {
        self::require_permission();
        $id = absint( $_GET['plan_id'] ?? 0 );
        check_admin_referer( 'persiano_hub_export_production_plan_' . $id );
        if ( ! $id || self::PLAN_POST_TYPE !== get_post_type( $id ) ) {
            wp_die( esc_html__( 'Production plan not found.', 'persiano-hub' ) );
        }
        $recipes = get_post_meta( $id, self::META_PLAN_RECIPES, true );
        $ingredients = get_post_meta( $id, self::META_PLAN_INGREDIENTS, true );
        $components = get_post_meta( $id, self::META_PLAN_COMPONENTS, true );
        $recipes = is_array( $recipes ) ? $recipes : array();
        $ingredients = is_array( $ingredients ) ? $ingredients : array();
        $components = is_array( $components ) ? $components : array();
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="production-plan-' . $id . '-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
        echo "\xEF\xBB\xBF";
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Plan', get_the_title( $id ) ) );
        fputcsv( $out, array( 'From', get_post_meta( $id, self::META_PLAN_FROM, true ), 'To', get_post_meta( $id, self::META_PLAN_TO, true ) ) );
        fputcsv( $out, array() );
        fputcsv( $out, array( 'Recipes' ) );
        fputcsv( $out, array( 'Include', 'Recipe', 'Calculated', 'Planned' ) );
        foreach ( $recipes as $row ) {
            $rid = absint( $row['recipe_id'] ?? 0 );
            fputcsv( $out, array( ! empty( $row['include'] ) ? 'Yes' : 'No', $rid ? get_the_title( $rid ) : '', $row['calculated_qty'] ?? 0, $row['planned_qty'] ?? 0 ) );
        }
        fputcsv( $out, array() );
        fputcsv( $out, array( 'Prepared components' ) );
        fputcsv( $out, array( 'Component', 'Required', 'Unit', 'On hand', 'Reserved elsewhere', 'Use from stock', 'Make now' ) );
        foreach ( $components as $row ) {
            $rid = absint( $row['recipe_id'] ?? 0 );
            fputcsv( $out, array( $rid ? get_the_title( $rid ) : '', $row['required'] ?? 0, $row['unit'] ?? '', $row['on_hand'] ?? 0, $row['reserved'] ?? 0, $row['from_stock'] ?? 0, $row['to_produce'] ?? 0 ) );
        }
        fputcsv( $out, array() );
        fputcsv( $out, array( 'Ingredient requirements' ) );
        fputcsv( $out, array( 'Include', 'Ingredient', 'Calculated', 'Planned', 'Unit', 'On hand', 'Shortage' ) );
        foreach ( $ingredients as $row ) {
            $iid = absint( $row['ingredient_id'] ?? 0 );
            $base = sanitize_key( $row['base_unit'] ?? 'each' );
            $planned = self::decimal( $row['planned_qty'] ?? 0 );
            $on = $iid ? self::inventory_in_base_unit( $iid, $base ) : 0;
            fputcsv( $out, array( ! empty( $row['include'] ) ? 'Yes' : 'No', $iid ? get_the_title( $iid ) : '', $row['calculated_qty'] ?? 0, $planned, $base, $on, max( 0, $planned - $on ) ) );
        }
        fputcsv( $out, array() );
        fputcsv( $out, array( 'Notes', get_post_meta( $id, self::META_PLAN_NOTES, true ) ) );
        fclose( $out );
        exit;
    }

    public static function plan_quick_action() {
        self::require_permission();
        $id = absint( isset( $_GET['plan_id'] ) ? $_GET['plan_id'] : 0 );
        $do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
        check_admin_referer( 'persiano_hub_plan_' . $do . '_' . $id );
        if ( self::PLAN_POST_TYPE !== get_post_type( $id ) ) { wp_die( esc_html__( 'Production plan not found.', 'persiano-hub' ) ); }
        if ( in_array( $do, array( 'active','done','archived','draft' ), true ) ) {
            if ( 'done' === $do && class_exists( 'Persiano_Hub_Inventory' ) ) {
                Persiano_Hub_Inventory::apply_production_plan_inventory( $id );
            }
            update_post_meta( $id, self::META_STATUS, $do );
        }
        elseif ( 'duplicate' === $do ) {
            $new = wp_insert_post( array( 'post_type'=>self::PLAN_POST_TYPE, 'post_status'=>'publish', 'post_title'=>get_the_title( $id ) . ' ' . __( 'Copy', 'persiano-hub' ) ) );
            foreach ( array( self::META_STATUS,self::META_PLAN_FROM,self::META_PLAN_TO,self::META_PLAN_RECIPES,self::META_PLAN_INGREDIENTS,self::META_PLAN_COMPONENTS,self::META_PLAN_NOTES ) as $key ) { update_post_meta( $new, $key, get_post_meta( $id, $key, true ) ); }
            $id = $new;
        } elseif ( 'trash' === $do ) { wp_trash_post( $id ); }
        wp_safe_redirect( self::page_url( 'production', 'duplicate' === $do ? array( 'plan_id'=>$id ) : array() ) ); exit;
    }

    private static function plan_action_url( $id, $do ) {
        return wp_nonce_url( add_query_arg( array( 'action'=>'persiano_hub_plan_quick_action','plan_id'=>$id,'do'=>$do ), admin_url( 'admin-post.php' ) ), 'persiano_hub_plan_' . $do . '_' . $id );
    }

    public static function get_plan_requirements( $plan_id ) {
        $rows = get_post_meta( $plan_id, self::META_PLAN_INGREDIENTS, true );
        $rows = is_array( $rows ) ? $rows : array();
        $ingredients = array();
        foreach ( $rows as $row ) {
            if ( empty( $row['include'] ) ) { continue; }
            $id = absint( isset( $row['ingredient_id'] ) ? $row['ingredient_id'] : 0 );
            if ( ! $id ) { continue; }
            $required = max( 0, self::decimal( isset( $row['planned_qty'] ) ? $row['planned_qty'] : 0 ) );
            $base = sanitize_key( isset( $row['base_unit'] ) ? $row['base_unit'] : get_post_meta( $id, Persiano_Hub_Costing::ING_BASE_UNIT, true ) );
            $on = self::inventory_in_base_unit( $id, $base );
            $ingredients[ $id ] = array( 'required'=>$required, 'base_unit'=>$base, 'on_hand'=>$on, 'shortage'=>max( 0, $required-$on ) );
        }
        return array( 'recipes'=>array(), 'ingredients'=>$ingredients );
    }

    private static function inventory_in_base_unit( $ingredient_id, $base_unit ) {
        $qty = self::decimal( get_post_meta( $ingredient_id, Persiano_Hub_Kitchen::ING_ON_HAND_QTY, true ) );
        $unit = sanitize_key( get_post_meta( $ingredient_id, Persiano_Hub_Kitchen::ING_ON_HAND_UNIT, true ) );
        if ( self::base_unit( $unit ) !== $base_unit ) { return 0; }
        return $qty * self::unit_multiplier( $unit );
    }

    public static function render_production_planner() {
        self::require_permission();
        $plan_id = isset( $_GET['plan_id'] ) ? absint( $_GET['plan_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active = self::operation_posts( self::PLAN_POST_TYPE, array( 'draft','active' ) );
        $done_ids = self::operation_posts( self::PLAN_POST_TYPE, array( 'done' ) );
        $archived_ids = self::operation_posts( self::PLAN_POST_TYPE, array( 'archived' ) );
        $done = count( $done_ids );
        $archived = count( $archived_ids );
        $visible_plans = 'done' === $view ? $done_ids : ( 'archived' === $view ? $archived_ids : $active );
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Kitchen planning', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Production Planner', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Create editable plans from upcoming orders or build a production plan manually. Calculated quantities stay visible when you override them.', 'persiano-hub' ); ?></p></div></div>
        <div class="ph-costing-stats"><div><strong><?php echo esc_html( count( $active ) ); ?></strong><span><?php esc_html_e( 'Open plans', 'persiano-hub' ); ?></span></div><div><strong><?php echo esc_html( $done ); ?></strong><span><?php esc_html_e( 'Done', 'persiano-hub' ); ?></span></div><div><strong><?php echo esc_html( $archived ); ?></strong><span><?php esc_html_e( 'Archived', 'persiano-hub' ); ?></span></div></div>
        <section class="ph-costing-panel"><h2><?php esc_html_e( 'Create a plan', 'persiano-hub' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_create_production_plan"><?php wp_nonce_field( 'persiano_hub_create_production_plan' ); ?><input type="text" name="title" placeholder="<?php esc_attr_e( 'Plan name (optional)', 'persiano-hub' ); ?>"> <input type="date" name="from" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"> <input type="date" name="to" value="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+7 days', current_time( 'timestamp' ) ) ) ); ?>"> <button class="button button-primary" name="plan_mode" value="orders"><?php esc_html_e( 'Build from orders', 'persiano-hub' ); ?></button> <button class="button" name="plan_mode" value="blank"><?php esc_html_e( 'Create blank plan', 'persiano-hub' ); ?></button></form></section>
        <p class="subsubsub"><a class="<?php echo 'open' === $view ? 'current' : ''; ?>" href="<?php echo esc_url( self::page_url( 'production', array( 'view'=>'open' ) ) ); ?>"><?php esc_html_e( 'Open', 'persiano-hub' ); ?></a> | <a class="<?php echo 'done' === $view ? 'current' : ''; ?>" href="<?php echo esc_url( self::page_url( 'production', array( 'view'=>'done' ) ) ); ?>"><?php esc_html_e( 'Done', 'persiano-hub' ); ?></a> | <a class="<?php echo 'archived' === $view ? 'current' : ''; ?>" href="<?php echo esc_url( self::page_url( 'production', array( 'view'=>'archived' ) ) ); ?>"><?php esc_html_e( 'Archived', 'persiano-hub' ); ?></a></p><div style="clear:both"></div>
        <?php self::render_plan_cards( $visible_plans, 'production' ); ?>
        <?php if ( $plan_id && self::PLAN_POST_TYPE === get_post_type( $plan_id ) ) { self::render_plan_editor( $plan_id ); } ?>
        <?php
    }

    private static function render_plan_cards( $ids, $tab ) {
        if ( ! $ids ) { return; }
        echo '<section class="ph-costing-panel"><h2>' . esc_html__( 'Open plans', 'persiano-hub' ) . '</h2><div class="ph-ops-card-grid">';
        foreach ( $ids as $id ) {
            $status = self::post_status_value( $id );
            $edit = self::page_url( $tab, array( 'plan_id'=>$id ) );
            $actions = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'persiano-hub' ) . '</a> · <a href="' . esc_url( self::plan_action_url( $id, 'duplicate' ) ) . '">' . esc_html__( 'Duplicate', 'persiano-hub' ) . '</a>';
            if ( 'done' === $status || 'archived' === $status ) {
                $actions .= ' · <a href="' . esc_url( self::plan_action_url( $id, 'active' ) ) . '">' . esc_html__( 'Reopen', 'persiano-hub' ) . '</a>';
            } else {
                $actions .= ' · <a href="' . esc_url( self::plan_action_url( $id, 'done' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Marking done deducts included ingredient and prepared-component inventory once. Continue?', 'persiano-hub' ) ) . '\')">' . esc_html__( 'Done & consume stock', 'persiano-hub' ) . '</a> · <a href="' . esc_url( self::plan_action_url( $id, 'archived' ) ) . '">' . esc_html__( 'Archive', 'persiano-hub' ) . '</a>';
            }
            echo '<article class="ph-ops-card"><h3><a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3><p><span class="ph-status-chip ph-status-' . esc_attr( $status ) . '">' . esc_html( self::status_options()[ $status ] ) . '</span></p><p class="ph-quick-actions">' . $actions . '</p></article>';
        }
        echo '</div></section>';
    }

    public static function export_production_notes() {
        $id = absint( $_GET['plan_id'] ?? 0 );
        check_admin_referer( 'persiano_hub_export_production_notes_' . $id );
        if ( ! current_user_can( 'manage_woocommerce' ) || self::PLAN_POST_TYPE !== get_post_type( $id ) ) { wp_die( esc_html__( 'Invalid production plan.', 'persiano-hub' ) ); }
        $recipes = get_post_meta( $id, self::META_PLAN_RECIPES, true ); $recipes = is_array($recipes)?$recipes:array();
        $ingredients = get_post_meta( $id, self::META_PLAN_INGREDIENTS, true ); $ingredients = is_array($ingredients)?$ingredients:array();
        $components = get_post_meta( $id, self::META_PLAN_COMPONENTS, true ); $components = is_array($components)?$components:array();
        $lines = array( get_the_title($id), get_post_meta($id,self::META_PLAN_FROM,true).' to '.get_post_meta($id,self::META_PLAN_TO,true), '', 'RECIPES' );
        foreach($recipes as $r){ if(empty($r['include'])) continue; $lines[]='☐ '.get_the_title(absint($r['recipe_id']??0)).' — '.($r['planned_qty']??0); }
        if($components){$lines[]='';$lines[]='PREPARED COMPONENTS';foreach($components as $r){$lines[]='☐ '.get_the_title(absint($r['recipe_id']??0)).' — use '.($r['from_stock']??0).' '.($r['unit']??'').' from stock; make '.($r['to_produce']??0).' '.($r['unit']??'');}}
        $lines[]=''; $lines[]='INGREDIENTS';
        foreach($ingredients as $r){ if(empty($r['include'])) continue; $lines[]='☐ '.get_the_title(absint($r['ingredient_id']??0)).' — '.($r['planned_qty']??0).' '.($r['base_unit']??''); }
        $notes=(string)get_post_meta($id,self::META_PLAN_NOTES,true); if($notes){$lines[]='';$lines[]='NOTES';$lines[]=$notes;}
        nocache_headers(); header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="production-plan-'.$id.'.txt"'); echo implode("
",$lines); exit;
    }

    private static function render_plan_editor( $id ) {
        $recipes = get_post_meta( $id, self::META_PLAN_RECIPES, true ); $recipes = is_array( $recipes ) ? $recipes : array();
        $ingredients = get_post_meta( $id, self::META_PLAN_INGREDIENTS, true ); $ingredients = is_array( $ingredients ) ? $ingredients : array();
        $components = get_post_meta( $id, self::META_PLAN_COMPONENTS, true ); $components = is_array( $components ) ? $components : array();
        $status = self::post_status_value( $id );
        ?>
        <?php self::render_record_navigation( $id, self::PLAN_POST_TYPE, 'production', 'plan_id' ); ?>
        <?php if ( self::current_revision() > (float) get_post_meta( $id, self::META_PLAN_SYNCED, true ) ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'Orders, recipes, raw ingredient stock, or prepared-component inventory changed after this plan was calculated. Refresh or save the plan to rebuild allocations. Manual overrides will be preserved.', 'persiano-hub' ); ?></p></div><?php endif; ?>
        <?php $inventory_applied = class_exists( 'Persiano_Hub_Inventory' ) ? get_post_meta( $id, Persiano_Hub_Inventory::PLAN_INVENTORY_APPLIED, true ) : array(); if ( is_array( $inventory_applied ) && ! empty( $inventory_applied['time'] ) ) : ?><div class="notice notice-info inline"><p><?php printf( esc_html__( 'Inventory was consumed for this plan on %s. Reopening or editing the plan does not automatically restore stock.', 'persiano-hub' ), esc_html( wp_date( 'M j, Y g:i a', absint( $inventory_applied['time'] ) ) ) ); ?></p></div><?php endif; ?>
        <section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2><?php echo esc_html( get_the_title( $id ) ); ?></h2><p><?php esc_html_e( 'Edit planned quantities, exclude recipes or ingredients, and save to rebuild the ingredient list. Refresh from current orders only reloads order demand.', 'persiano-hub' ); ?></p></div><div class="ph-costing-actions"><?php if ( 'orders' === get_post_meta( $id, self::META_PLAN_SOURCE, true ) || ! get_post_meta( $id, self::META_PLAN_SOURCE, true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><input type="hidden" name="action" value="persiano_hub_refresh_production_plan"><input type="hidden" name="plan_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'persiano_hub_refresh_production_plan_' . $id ); ?><button class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Refresh reloads recipe demand from current orders. Save any checkbox or quantity changes first. Continue?', 'persiano-hub' ) ); ?>')"><?php esc_html_e( 'Refresh from current orders', 'persiano-hub' ); ?></button></form><?php endif; ?><a class="button" href="<?php echo esc_url( self::plan_action_url( $id, 'duplicate' ) ); ?>"><?php esc_html_e( 'Duplicate', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( self::plan_action_url( $id, 'done' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Marking done deducts included ingredient and prepared-component inventory once. Continue?', 'persiano-hub' ) ); ?>')"><?php esc_html_e( 'Mark done & consume stock', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( self::plan_action_url( $id, 'archived' ) ); ?>"><?php esc_html_e( 'Archive', 'persiano-hub' ); ?></a></div></div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ph-production-plan-form"><input type="hidden" name="action" value="persiano_hub_save_production_plan"><input type="hidden" name="plan_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'persiano_hub_save_production_plan' ); ?>
        <div class="ph-ops-inline-fields"><label><?php esc_html_e( 'Plan name', 'persiano-hub' ); ?><input type="text" name="plan_title" value="<?php echo esc_attr( get_the_title( $id ) ); ?>"></label><label><?php esc_html_e( 'Status', 'persiano-hub' ); ?><?php self::select_status( 'plan_status', $status ); ?></label><label><?php esc_html_e( 'From', 'persiano-hub' ); ?><input type="date" name="plan_from" value="<?php echo esc_attr( get_post_meta( $id, self::META_PLAN_FROM, true ) ); ?>"></label><label><?php esc_html_e( 'To', 'persiano-hub' ); ?><input type="date" name="plan_to" value="<?php echo esc_attr( get_post_meta( $id, self::META_PLAN_TO, true ) ); ?>"></label></div>
        <div class="ph-costing-heading-row"><h3><?php esc_html_e( 'Recipes / production quantities', 'persiano-hub' ); ?></h3><div><strong><?php echo esc_html( count( $recipes ) ); ?></strong> <?php esc_html_e( 'recipe rows', 'persiano-hub' ); ?> &nbsp; <strong><?php echo esc_html( count( $components ) ); ?></strong> <?php esc_html_e( 'component rows', 'persiano-hub' ); ?> &nbsp; <strong><?php echo esc_html( count( $ingredients ) ); ?></strong> <?php esc_html_e( 'ingredient rows', 'persiano-hub' ); ?></div></div><table class="widefat striped ph-ops-edit-table" id="ph-plan-recipes"><thead><tr><th><?php esc_html_e( 'Include', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Recipe', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Calculated', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Planned', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Actions', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php foreach ( $recipes as $i=>$row ) { self::render_plan_recipe_row( $i, $row ); } ?>
        </tbody></table><p><button type="button" class="button ph-add-plan-recipe"><?php esc_html_e( 'Add recipe', 'persiano-hub' ); ?></button> <?php submit_button( __( 'Apply recipe selection & recalculate', 'persiano-hub' ), 'secondary', 'recalculate_recipes', false ); ?></p>
        <?php if ( $components ) : ?><h3><?php esc_html_e( 'Prepared component usage', 'persiano-hub' ); ?></h3><p class="description"><?php esc_html_e( 'Usable prepared stock is allocated before sub-recipes are expanded. Only Make now is converted into raw ingredients. Active plans reserve their Use from stock amount; marking this plan done deducts it once. When you make a full component batch for future use, record it in Inventory and refresh this plan.', 'persiano-hub' ); ?></p><div class="ph-costing-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Component', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Required', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'On hand', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Reserved elsewhere', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Use from stock', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Make now', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Inventory', 'persiano-hub' ); ?></th></tr></thead><tbody><?php foreach ( $components as $component ) : $rid=absint($component['recipe_id']??0); $unit=sanitize_key($component['unit']??'each'); $inventory_url=add_query_arg(array('page'=>Persiano_Hub_Costing::MENU_SLUG,'tab'=>'inventory','component_id'=>$rid),admin_url('admin.php')); ?><tr><td><a href="<?php echo esc_url( get_edit_post_link( $rid ) ); ?>"><strong><?php echo esc_html( get_the_title( $rid ) ); ?></strong></a></td><td><?php echo esc_html( self::format_quantity( $component['required']??0 ) . ' ' . $unit ); ?></td><td><?php echo esc_html( self::format_quantity( $component['on_hand']??0 ) . ' ' . $unit ); ?></td><td><?php echo esc_html( self::format_quantity( $component['reserved']??0 ) . ' ' . $unit ); ?></td><td><strong><?php echo esc_html( self::format_quantity( $component['from_stock']??0 ) . ' ' . $unit ); ?></strong></td><td><strong><?php echo esc_html( self::format_quantity( $component['to_produce']??0 ) . ' ' . $unit ); ?></strong></td><td><a class="button button-small" href="<?php echo esc_url( $inventory_url ); ?>"><?php esc_html_e( 'Record batch', 'persiano-hub' ); ?></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <h3><?php esc_html_e( 'Ingredient requirements', 'persiano-hub' ); ?></h3><p class="description"><?php esc_html_e( 'Calculated is rebuilt from the recipes currently checked above. Planned is your manual override. After changing recipe checkboxes, click Save & recalculate plan; unchecked recipes will no longer contribute ingredients.', 'persiano-hub' ); ?></p><table class="widefat striped ph-ops-edit-table" id="ph-plan-ingredients"><thead><tr><th><?php esc_html_e( 'Include', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Ingredient', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Calculated', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Planned', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'On hand', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Shortage', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Actions', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php foreach ( $ingredients as $i=>$row ) { self::render_plan_ingredient_row( $i, $row ); } ?>
        </tbody></table><p><button type="button" class="button ph-add-plan-ingredient"><?php esc_html_e( 'Add manual ingredient', 'persiano-hub' ); ?></button></p>
        <label><?php esc_html_e( 'Notes', 'persiano-hub' ); ?><textarea class="widefat" rows="3" name="plan_notes"><?php echo esc_textarea( get_post_meta( $id, self::META_PLAN_NOTES, true ) ); ?></textarea></label><p><?php submit_button( __( 'Save & recalculate plan', 'persiano-hub' ), 'primary', 'submit', false ); ?> <button type="button" class="button" onclick="document.body.classList.add('ph-print-production');window.print();setTimeout(function(){document.body.classList.remove('ph-print-production');},500)"><?php esc_html_e( 'Print plan', 'persiano-hub' ); ?></button> <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action'=>'persiano_hub_export_production_plan', 'plan_id'=>$id ), admin_url( 'admin-post.php' ) ), 'persiano_hub_export_production_plan_' . $id ) ); ?>"><?php esc_html_e( 'Export CSV', 'persiano-hub' ); ?></a> <a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action'=>'persiano_hub_export_production_notes', 'plan_id'=>$id ), admin_url( 'admin-post.php' ) ), 'persiano_hub_export_production_notes_' . $id ) ); ?>"><?php esc_html_e( 'Apple Notes / Text', 'persiano-hub' ); ?></a></p></form></section>
        <?php self::render_record_navigation( $id, self::PLAN_POST_TYPE, 'production', 'plan_id' ); ?>
        <style>@media print{body.ph-print-production #adminmenumain,body.ph-print-production #wpadminbar,body.ph-print-production #screen-meta-links,body.ph-print-production .ph-costing-tabs,body.ph-print-production .ph-section-tabs,body.ph-print-production .ph-costing-actions,body.ph-print-production .button,body.ph-print-production input[type=submit],body.ph-print-production .ph-record-navigation{display:none!important}body.ph-print-production #wpcontent,body.ph-print-production #wpbody-content{margin:0!important;padding:0!important}body.ph-print-production .ph-costing-panel{box-shadow:none!important;border:0!important}body.ph-print-production input,body.ph-print-production textarea,body.ph-print-production select{border:0!important;background:transparent!important;appearance:none!important;padding:0!important}body.ph-print-production .wrap>*:not(.ph-costing-panel){display:none!important}}</style>
        <script type="text/template" id="ph-plan-recipe-template"><?php self::render_plan_recipe_row( '__INDEX__', array( 'recipe_id'=>0,'calculated_qty'=>0,'planned_qty'=>1,'include'=>1 ) ); ?></script>
        <script type="text/template" id="ph-plan-ingredient-template"><?php self::render_plan_ingredient_row( '__INDEX__', array( 'ingredient_id'=>0,'calculated_qty'=>0,'planned_qty'=>1,'base_unit'=>'each','include'=>1,'manual'=>1 ) ); ?></script>
        <?php
    }

    private static function render_plan_recipe_row( $i, $row ) {
        $id = absint( isset( $row['recipe_id'] ) ? $row['recipe_id'] : 0 );
        ?><tr><td><input type="hidden" name="plan_recipes[<?php echo esc_attr( $i ); ?>][include]" value="0"><input type="checkbox" name="plan_recipes[<?php echo esc_attr( $i ); ?>][include]" value="1" <?php checked( ! empty( $row['include'] ) ); ?>></td><td><?php self::select_recipe( 'plan_recipes[' . $i . '][recipe_id]', $id ); ?></td><td><input type="number" step="0.01" min="0" readonly name="plan_recipes[<?php echo esc_attr( $i ); ?>][calculated_qty]" value="<?php echo esc_attr( $row['calculated_qty'] ?? 0 ); ?>"></td><td><input type="number" step="0.01" min="0" name="plan_recipes[<?php echo esc_attr( $i ); ?>][planned_qty]" value="<?php echo esc_attr( $row['planned_qty'] ?? 0 ); ?>"></td><td><button type="button" class="button-link ph-row-duplicate"><?php esc_html_e( 'Duplicate', 'persiano-hub' ); ?></button> · <button type="button" class="button-link-delete ph-row-delete"><?php esc_html_e( 'Delete', 'persiano-hub' ); ?></button></td></tr><?php
    }

    private static function render_plan_ingredient_row( $i, $row ) {
        $id = absint( isset( $row['ingredient_id'] ) ? $row['ingredient_id'] : 0 );
        $base = sanitize_key( isset( $row['base_unit'] ) ? $row['base_unit'] : 'each' );
        $planned = self::decimal( isset( $row['planned_qty'] ) ? $row['planned_qty'] : 0 );
        $on = $id ? self::inventory_in_base_unit( $id, $base ) : 0;
        $shortage = max( 0, $planned-$on );
        ?><tr><td><input type="hidden" name="plan_ingredients[<?php echo esc_attr( $i ); ?>][include]" value="0"><input type="checkbox" name="plan_ingredients[<?php echo esc_attr( $i ); ?>][include]" value="1" <?php checked( ! empty( $row['include'] ) ); ?>><input type="hidden" name="plan_ingredients[<?php echo esc_attr( $i ); ?>][manual]" value="<?php echo ! empty( $row['manual'] ) ? '1' : '0'; ?>"></td><td><?php self::select_ingredient( 'plan_ingredients[' . $i . '][ingredient_id]', $id ); ?><input type="hidden" name="plan_ingredients[<?php echo esc_attr( $i ); ?>][base_unit]" value="<?php echo esc_attr( $base ); ?>"></td><td><input type="number" step="0.0001" min="0" readonly name="plan_ingredients[<?php echo esc_attr( $i ); ?>][calculated_qty]" value="<?php echo esc_attr( $row['calculated_qty'] ?? 0 ); ?>"> <?php echo esc_html( $base ); ?></td><td><input type="number" step="0.0001" min="0" name="plan_ingredients[<?php echo esc_attr( $i ); ?>][planned_qty]" value="<?php echo esc_attr( $planned ); ?>"> <?php echo esc_html( $base ); ?></td><td><?php echo esc_html( round( $on, 3 ) . ' ' . $base ); ?></td><td><?php echo esc_html( round( $shortage, 3 ) . ' ' . $base ); ?></td><td><button type="button" class="button-link ph-row-toggle"><?php echo ! empty( $row['include'] ) ? esc_html__( 'Exclude', 'persiano-hub' ) : esc_html__( 'Include', 'persiano-hub' ); ?></button><?php if ( ! empty( $row['manual'] ) ) : ?> · <button type="button" class="button-link ph-row-duplicate"><?php esc_html_e( 'Duplicate', 'persiano-hub' ); ?></button> · <button type="button" class="button-link-delete ph-row-delete"><?php esc_html_e( 'Delete', 'persiano-hub' ); ?></button><?php endif; ?></td></tr><?php
    }

    /* --------------------------------------------------------------------- */
    /* Shopping lists and purchasing                                         */
    /* --------------------------------------------------------------------- */

    private static function shopping_items_from_lines( $lines ) {
        $items = array();
        foreach ( (array) $lines as $line ) {
            if ( empty( $line['quote'] ) ) { continue; }
            $q = $line['quote'];
            $items[] = array(
                'ingredient_id' => absint( $line['ingredient_id'] ),
                'include'       => 1,
                'vendor'        => sanitize_text_field( $q['vendor'] ),
                'brand'         => sanitize_text_field( $q['brand'] ?? '' ),
                'packages'      => max( 1, absint( $q['packages'] ?? 1 ) ),
                'package_qty'   => self::decimal( $q['purchase_qty'] ?? 0 ),
                'package_unit'  => sanitize_key( $q['purchase_unit'] ?? 'each' ),
                'estimated_subtotal' => self::decimal( $q['subtotal_cost'] ?? 0 ),
                'estimated_tax' => self::decimal( $q['purchase_tax'] ?? 0 ),
                'estimated_total' => self::decimal( $q['estimated_cost'] ?? 0 ),
                'required_qty'  => self::decimal( $line['required'] ?? 0 ),
                'required_unit' => sanitize_key( $line['base_unit'] ?? 'each' ),
                'purchased'     => 0,
                'actual_qty'    => '',
                'actual_unit'   => sanitize_key( $q['purchase_unit'] ?? 'each' ),
                'actual_subtotal' => '',
                'actual_tax'    => '',
                'note'          => '',
            );
        }
        return $items;
    }

    private static function plan_for_source( $plan_id, $from, $to ) {
        if ( $plan_id && self::PLAN_POST_TYPE === get_post_type( $plan_id ) ) {
            return Persiano_Hub_Purchasing::build_from_requirements( self::get_plan_requirements( $plan_id ), $from, $to );
        }
        return Persiano_Hub_Purchasing::build_plan( $from, $to );
    }

    public static function create_shopping_list() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_create_shopping_list' );
        $source_plan = absint( isset( $_POST['plan_id'] ) ? $_POST['plan_id'] : 0 );
        $from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : wp_date( 'Y-m-d' );
        $to = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : $from;
        $strategy = isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : 'cheapest';
        $vendor = isset( $_POST['vendor'] ) ? sanitize_text_field( wp_unslash( $_POST['vendor'] ) ) : '';
        $plan = self::plan_for_source( $source_plan, $from, $to );
        $lines = Persiano_Hub_Purchasing::lines_for_strategy_public( $plan, $strategy, $vendor );
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        if ( ! $title ) { $title = 'cheapest' === $strategy ? __( 'Cheapest Practical Mix', 'persiano-hub' ) : ( $vendor ? $vendor . ' ' . __( 'Shopping List', 'persiano-hub' ) : __( 'Shopping List', 'persiano-hub' ) ); }
        $id = wp_insert_post( array( 'post_type'=>self::LIST_POST_TYPE, 'post_status'=>'publish', 'post_title'=>$title ) );
        if ( $id && ! is_wp_error( $id ) ) {
            update_post_meta( $id, self::META_STATUS, 'active' );
            update_post_meta( $id, self::META_LIST_ITEMS, self::shopping_items_from_lines( $lines ) );
            update_post_meta( $id, self::META_LIST_SOURCE, array( 'plan_id'=>$source_plan,'from'=>$from,'to'=>$to,'strategy'=>$strategy,'vendor'=>$vendor ) );
            update_post_meta( $id, self::META_LIST_SYNCED, self::current_revision() );
        }
        wp_safe_redirect( self::page_url( 'purchasing', array( 'list_id'=>$id ) ) ); exit;
    }

    public static function refresh_shopping_list() {
        self::require_permission();
        $id = absint( $_POST['list_id'] ?? 0 );
        check_admin_referer( 'persiano_hub_refresh_shopping_list_' . $id );
        if ( ! $id || self::LIST_POST_TYPE !== get_post_type( $id ) ) { wp_die( esc_html__( 'Shopping list not found.', 'persiano-hub' ) ); }
        $source = get_post_meta( $id, self::META_LIST_SOURCE, true );
        $source = is_array( $source ) ? $source : array();
        $plan = self::plan_for_source( absint( $source['plan_id'] ?? 0 ), sanitize_text_field( $source['from'] ?? wp_date( 'Y-m-d' ) ), sanitize_text_field( $source['to'] ?? wp_date( 'Y-m-d' ) ) );
        $lines = Persiano_Hub_Purchasing::lines_for_strategy_public( $plan, sanitize_key( $source['strategy'] ?? 'cheapest' ), sanitize_text_field( $source['vendor'] ?? '' ) );
        $fresh = self::shopping_items_from_lines( $lines );
        $old = get_post_meta( $id, self::META_LIST_ITEMS, true );
        $old = is_array( $old ) ? $old : array();
        $old_by_id = array();
        foreach ( $old as $row ) { if ( ! empty( $row['ingredient_id'] ) ) { $old_by_id[ absint( $row['ingredient_id'] ) ] = $row; } }
        foreach ( $fresh as &$row ) {
            $prev = $old_by_id[ $row['ingredient_id'] ] ?? array();
            if ( $prev ) {
                foreach ( array( 'include','purchased','reconciled','actual_qty','actual_unit','actual_subtotal','actual_tax','note' ) as $key ) {
                    if ( array_key_exists( $key, $prev ) ) { $row[ $key ] = $prev[ $key ]; }
                }
                unset( $old_by_id[ $row['ingredient_id'] ] );
            }
        }
        unset( $row );
        foreach ( $old_by_id as $prev ) {
            if ( ! empty( $prev['purchased'] ) || ! empty( $prev['reconciled'] ) || ! empty( $prev['note'] ) ) { $fresh[] = $prev; }
        }
        update_post_meta( $id, self::META_LIST_ITEMS, $fresh );
        update_post_meta( $id, self::META_LIST_SYNCED, self::current_revision() );
        wp_safe_redirect( self::page_url( 'purchasing', array( 'list_id'=>$id, 'refreshed'=>1 ) ) ); exit;
    }

    private static function sanitize_shopping_rows( $rows ) {
        $clean = array();
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $id = absint( isset( $row['ingredient_id'] ) ? $row['ingredient_id'] : 0 );
            if ( ! $id || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $id ) ) { continue; }
            $unit = sanitize_key( isset( $row['package_unit'] ) ? $row['package_unit'] : 'each' );
            $actual_unit = sanitize_key( isset( $row['actual_unit'] ) ? $row['actual_unit'] : $unit );
            $packages = max( 0, absint( isset( $row['packages'] ) ? $row['packages'] : 0 ) );
            $subtotal_per_package = max( 0, self::decimal( isset( $row['estimated_subtotal'] ) ? $row['estimated_subtotal'] : 0 ) );
            $tax_per_package = max( 0, self::decimal( isset( $row['estimated_tax'] ) ? $row['estimated_tax'] : 0 ) );
            $clean[] = array(
                'ingredient_id'=>$id,
                'include'=>! empty( $row['include'] ) ? 1 : 0,
                'vendor'=>sanitize_text_field( isset( $row['vendor'] ) ? $row['vendor'] : '' ),
                'brand'=>sanitize_text_field( isset( $row['brand'] ) ? $row['brand'] : '' ),
                'packages'=>$packages,
                'package_qty'=>max( 0, self::decimal( isset( $row['package_qty'] ) ? $row['package_qty'] : 0 ) ),
                'package_unit'=>array_key_exists( $unit, Persiano_Hub_Costing::unit_options() ) ? $unit : 'each',
                'estimated_subtotal'=>$subtotal_per_package,
                'estimated_tax'=>$tax_per_package,
                'estimated_total'=>$packages * ( $subtotal_per_package + $tax_per_package ),
                'required_qty'=>max( 0, self::decimal( isset( $row['required_qty'] ) ? $row['required_qty'] : 0 ) ),
                'required_unit'=>sanitize_key( isset( $row['required_unit'] ) ? $row['required_unit'] : 'each' ),
                'purchased'=>! empty( $row['purchased'] ) ? 1 : 0,
                'reconciled'=>! empty( $row['reconciled'] ) ? 1 : 0,
                'actual_qty'=>'' === (string) ( $row['actual_qty'] ?? '' ) ? '' : max( 0, self::decimal( $row['actual_qty'] ) ),
                'actual_unit'=>array_key_exists( $actual_unit, Persiano_Hub_Costing::unit_options() ) ? $actual_unit : 'each',
                'actual_subtotal'=>'' === (string) ( $row['actual_subtotal'] ?? '' ) ? '' : max( 0, self::decimal( $row['actual_subtotal'] ) ),
                'actual_tax'=>'' === (string) ( $row['actual_tax'] ?? '' ) ? '' : max( 0, self::decimal( $row['actual_tax'] ) ),
                'note'=>sanitize_text_field( isset( $row['note'] ) ? $row['note'] : '' ),
            );
        }
        return $clean;
    }

    public static function save_shopping_list() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_save_shopping_list' );
        $id = absint( isset( $_POST['list_id'] ) ? $_POST['list_id'] : 0 );
        if ( ! $id || self::LIST_POST_TYPE !== get_post_type( $id ) ) { wp_die( esc_html__( 'Shopping list not found.', 'persiano-hub' ) ); }
        wp_update_post( array( 'ID'=>$id, 'post_title'=>sanitize_text_field( wp_unslash( $_POST['list_title'] ?? get_the_title( $id ) ) ) ) );
        update_post_meta( $id, self::META_STATUS, self::clean_status( isset( $_POST['list_status'] ) ? wp_unslash( $_POST['list_status'] ) : 'active' ) );
        update_post_meta( $id, self::META_LIST_NOTES, isset( $_POST['list_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['list_notes'] ) ) : '' );
        $items = self::sanitize_shopping_rows( isset( $_POST['shop_items'] ) ? wp_unslash( $_POST['shop_items'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $operation = isset( $_POST['save_operation'] ) ? sanitize_key( wp_unslash( $_POST['save_operation'] ) ) : 'save';
        if ( 'complete_purchases' === $operation ) {
            $items = self::reconcile_purchased_items( $items );
        }
        update_post_meta( $id, self::META_LIST_ITEMS, $items );
        wp_safe_redirect( self::page_url( 'purchasing', array( 'list_id'=>$id,'saved'=>1 ) ) ); exit;
    }

    private static function reconcile_purchased_items( $items ) {
        foreach ( $items as &$row ) {
            if ( empty( $row['include'] ) || empty( $row['purchased'] ) || ! empty( $row['reconciled'] ) ) { continue; }
            $qty = '' !== $row['actual_qty'] ? self::decimal( $row['actual_qty'] ) : self::decimal( $row['package_qty'] ) * max( 1, absint( $row['packages'] ) );
            $unit = $row['actual_unit'] ? $row['actual_unit'] : $row['package_unit'];
            $subtotal = '' !== $row['actual_subtotal'] ? self::decimal( $row['actual_subtotal'] ) : self::decimal( $row['estimated_subtotal'] ) * max( 1, absint( $row['packages'] ) );
            $tax = '' !== $row['actual_tax'] ? self::decimal( $row['actual_tax'] ) : self::decimal( $row['estimated_tax'] ) * max( 1, absint( $row['packages'] ) );
            self::add_price_record( $row['ingredient_id'], array( 'purchase_qty'=>$qty,'purchase_unit'=>$unit,'purchase_cost'=>$subtotal,'purchase_tax'=>$tax,'brand'=>$row['brand'],'supplier'=>$row['vendor'],'source'=>'shopping_list','notes'=>$row['note'] ), 'purchase' );
            self::adjust_inventory( $row['ingredient_id'], 'add', $qty, $unit, 'shopping_purchase', $row['note'], 'shopping_list' );
            $row['reconciled'] = 1;
        }
        unset( $row );
        return $items;
    }

    public static function shopping_quick_action() {
        self::require_permission();
        $id = absint( isset( $_GET['list_id'] ) ? $_GET['list_id'] : 0 );
        $do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
        check_admin_referer( 'persiano_hub_list_' . $do . '_' . $id );
        if ( self::LIST_POST_TYPE !== get_post_type( $id ) ) { wp_die( esc_html__( 'Shopping list not found.', 'persiano-hub' ) ); }
        if ( in_array( $do, array( 'active','done','archived','draft' ), true ) ) { update_post_meta( $id, self::META_STATUS, $do ); }
        elseif ( 'duplicate' === $do ) {
            $new = wp_insert_post( array( 'post_type'=>self::LIST_POST_TYPE,'post_status'=>'publish','post_title'=>get_the_title( $id ) . ' ' . __( 'Copy', 'persiano-hub' ) ) );
            foreach ( array( self::META_STATUS,self::META_LIST_ITEMS,self::META_LIST_NOTES,self::META_LIST_SOURCE ) as $key ) { update_post_meta( $new, $key, get_post_meta( $id, $key, true ) ); }
            $id = $new;
        } elseif ( 'trash' === $do ) { wp_trash_post( $id ); }
        wp_safe_redirect( self::page_url( 'purchasing', 'duplicate' === $do ? array( 'list_id'=>$id ) : array() ) ); exit;
    }

    private static function list_action_url( $id, $do ) {
        return wp_nonce_url( add_query_arg( array( 'action'=>'persiano_hub_shopping_quick_action','list_id'=>$id,'do'=>$do ), admin_url( 'admin-post.php' ) ), 'persiano_hub_list_' . $do . '_' . $id );
    }

    public static function add_quote_to_list() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_add_quote_to_list' );
        $ingredient_id = absint( isset( $_POST['ingredient_id'] ) ? $_POST['ingredient_id'] : 0 );
        $list_id = absint( isset( $_POST['list_id'] ) ? $_POST['list_id'] : 0 );
        if ( ! $list_id ) {
            $list_id = wp_insert_post( array( 'post_type'=>self::LIST_POST_TYPE,'post_status'=>'publish','post_title'=>__( 'Shopping List', 'persiano-hub' ) . ' ' . wp_date( 'M j' ) ) );
            update_post_meta( $list_id, self::META_STATUS, 'active' );
        }
        if ( self::LIST_POST_TYPE !== get_post_type( $list_id ) ) { wp_die( esc_html__( 'Shopping list not found.', 'persiano-hub' ) ); }
        $items = get_post_meta( $list_id, self::META_LIST_ITEMS, true ); $items = is_array( $items ) ? $items : array();
        $items[] = array(
            'ingredient_id'=>$ingredient_id,'include'=>1,
            'vendor'=>sanitize_text_field( wp_unslash( $_POST['vendor'] ?? '' ) ),
            'brand'=>sanitize_text_field( wp_unslash( $_POST['brand'] ?? '' ) ),
            'packages'=>max( 1, absint( $_POST['packages'] ?? 1 ) ),
            'package_qty'=>max( 0, self::decimal( $_POST['package_qty'] ?? 0 ) ),
            'package_unit'=>sanitize_key( wp_unslash( $_POST['package_unit'] ?? 'each' ) ),
            'estimated_subtotal'=>max( 0, self::decimal( $_POST['subtotal_cost'] ?? 0 ) ),
            'estimated_tax'=>max( 0, self::decimal( $_POST['purchase_tax'] ?? 0 ) ),
            'estimated_total'=>max( 0, self::decimal( $_POST['estimated_total'] ?? 0 ) ),
            'required_qty'=>max( 0, self::decimal( $_POST['required_qty'] ?? 0 ) ),
            'required_unit'=>sanitize_key( wp_unslash( $_POST['required_unit'] ?? 'each' ) ),
            'purchased'=>0,'actual_qty'=>'','actual_unit'=>sanitize_key( wp_unslash( $_POST['package_unit'] ?? 'each' ) ),'actual_subtotal'=>'','actual_tax'=>'','note'=>'',
        );
        update_post_meta( $list_id, self::META_LIST_ITEMS, $items );
        wp_safe_redirect( self::page_url( 'purchasing', array( 'list_id'=>$list_id ) ) ); exit;
    }

    public static function render_purchasing_page() {
        self::require_permission();
        $list_id = isset( $_GET['list_id'] ) ? absint( $_GET['list_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_plan_ids = self::operation_posts( self::PLAN_POST_TYPE, array( 'active' ) );
        $draft_plan_ids = self::operation_posts( self::PLAN_POST_TYPE, array( 'draft' ) );
        $plan_choices = array_values( array_unique( array_merge( $active_plan_ids, $draft_plan_ids ) ) );
        $plan_id = isset( $_GET['plan_id'] ) ? absint( $_GET['plan_id'] ) : ( $plan_choices ? absint( $plan_choices[0] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $saved_from = $plan_id ? sanitize_text_field( get_post_meta( $plan_id, self::META_PLAN_FROM, true ) ) : '';
        $saved_to = $plan_id ? sanitize_text_field( get_post_meta( $plan_id, self::META_PLAN_TO, true ) ) : '';
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ( $saved_from ?: wp_date( 'Y-m-d' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ( $saved_to ?: wp_date( 'Y-m-d', strtotime( '+7 days', current_time( 'timestamp' ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_lists = self::operation_posts( self::LIST_POST_TYPE, array( 'draft','active' ) );
        $done_ids = self::operation_posts( self::LIST_POST_TYPE, array( 'done' ) );
        $archived_ids = self::operation_posts( self::LIST_POST_TYPE, array( 'archived' ) );
        $done = count( $done_ids );
        $archived = count( $archived_ids );
        $visible_lists = 'done' === $view ? $done_ids : ( 'archived' === $view ? $archived_ids : $active_lists );
        $plan = self::plan_for_source( $plan_id, $from, $to );
        $groups = Persiano_Hub_Purchasing::grouped_lines_public( Persiano_Hub_Purchasing::lines_for_strategy_public( $plan, 'cheapest' ) );
        $price_feed_active = 0;
        $price_feed_attention = 0;
        if ( class_exists( 'Persiano_Hub_Price_Feeds' ) ) {
            $feed_ids = get_posts( array( 'post_type'=>Persiano_Hub_Price_Feeds::POST_TYPE,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true ) );
            foreach ( $feed_ids as $feed_id ) {
                if ( (int) get_post_meta( $feed_id, Persiano_Hub_Price_Feeds::META_ACTIVE, true ) ) { $price_feed_active++; }
                if ( in_array( get_post_meta( $feed_id, Persiano_Hub_Price_Feeds::META_STATUS, true ), array( 'needs_review','needs_attention' ), true ) ) { $price_feed_attention++; }
            }
        }
        $purchasing_return_url = self::page_url( 'purchasing', array( 'plan_id'=>$plan_id,'from'=>$from,'to'=>$to,'view'=>$view ) );
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Purchasing', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Shopping & Vendors', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Compare practical vendor options, create editable shopping lists, mark them done or archive them, and reconcile actual purchases back into inventory and price history.', 'persiano-hub' ); ?></p></div></div>
        <div class="ph-costing-stats"><div><strong><?php echo esc_html( count( $active_lists ) ); ?></strong><span><?php esc_html_e( 'Open lists', 'persiano-hub' ); ?></span></div><div><strong><?php echo esc_html( $done ); ?></strong><span><?php esc_html_e( 'Done', 'persiano-hub' ); ?></span></div><div><strong><?php echo esc_html( $archived ); ?></strong><span><?php esc_html_e( 'Archived', 'persiano-hub' ); ?></span></div><div><strong><?php echo wp_kses_post( self::money( $plan['cheapest_total'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Cheapest practical mix', 'persiano-hub' ); ?></span></div></div>
        <?php if ( isset( $_GET['price_feeds_queued'] ) ) : ?><div class="notice notice-success inline"><p><?php printf( esc_html__( '%d online price sources were queued for background checking. Refresh this comparison after the checks finish.', 'persiano-hub' ), absint( $_GET['price_feeds_queued'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div><?php endif; ?>
        <?php if ( class_exists( 'Persiano_Hub_Price_Feeds' ) ) : ?><section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2><?php esc_html_e( 'Online price sources', 'persiano-hub' ); ?></h2><p><?php printf( esc_html__( '%1$d active sources. %2$d require review or attention. Checks run in the background and do not block this page.', 'persiano-hub' ), absint( $price_feed_active ), absint( $price_feed_attention ) ); ?></p></div><div class="ph-costing-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_feed_queue"><input type="hidden" name="check_all" value="1"><input type="hidden" name="return_to" value="<?php echo esc_attr( $purchasing_return_url ); ?>"><?php wp_nonce_field( 'persiano_hub_feed_bulk' ); ?><button class="button"><?php esc_html_e( 'Check all online prices', 'persiano-hub' ); ?></button></form><a class="button" href="<?php echo esc_url( Persiano_Hub_Price_Feeds::page_url() ); ?>"><?php esc_html_e( 'Manage Price Feeds', 'persiano-hub' ); ?></a></div></div></section><?php endif; ?>
        <p class="subsubsub"><a class="<?php echo 'open' === $view ? 'current' : ''; ?>" href="<?php echo esc_url( self::page_url( 'purchasing', array( 'view'=>'open' ) ) ); ?>"><?php esc_html_e( 'Open', 'persiano-hub' ); ?></a> | <a class="<?php echo 'done' === $view ? 'current' : ''; ?>" href="<?php echo esc_url( self::page_url( 'purchasing', array( 'view'=>'done' ) ) ); ?>"><?php esc_html_e( 'Done', 'persiano-hub' ); ?></a> | <a class="<?php echo 'archived' === $view ? 'current' : ''; ?>" href="<?php echo esc_url( self::page_url( 'purchasing', array( 'view'=>'archived' ) ) ); ?>"><?php esc_html_e( 'Archived', 'persiano-hub' ); ?></a></p><div style="clear:both"></div>
        <?php self::render_shopping_list_cards( $visible_lists ); ?>
        <section class="ph-costing-panel"><h2><?php esc_html_e( 'Build shopping comparison', 'persiano-hub' ); ?></h2><form method="get"><input type="hidden" name="page" value="<?php echo esc_attr( Persiano_Hub_Costing::MENU_SLUG ); ?>"><input type="hidden" name="tab" value="purchasing"><label><?php esc_html_e( 'Production plan', 'persiano-hub' ); ?> <select name="plan_id"><option value="0"><?php esc_html_e( 'Direct from orders/date range — bypasses plan overrides', 'persiano-hub' ); ?></option><?php foreach ( $plan_choices as $pid ) : ?><option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $plan_id, $pid ); ?>><?php echo esc_html( get_the_title( $pid ) . ' — ' . ucfirst( self::post_status_value( $pid ) ) ); ?></option><?php endforeach; ?></select></label> <label><?php esc_html_e( 'From', 'persiano-hub' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label> <label><?php esc_html_e( 'To', 'persiano-hub' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label> <?php submit_button( __( 'Refresh comparison', 'persiano-hub' ), 'secondary', '', false ); ?></form><?php if ( ! $plan_id ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'This comparison is being built directly from orders. It ignores saved production-plan overrides, exclusions, manual ingredients, and prepared-component reservations. For the normal workflow, create or select a saved production plan first.', 'persiano-hub' ); ?> <a href="<?php echo esc_url( self::page_url( 'production' ) ); ?>"><?php esc_html_e( 'Open Production Planner', 'persiano-hub' ); ?></a></p></div><?php else : ?><p class="description"><?php printf( esc_html__( 'Using saved plan: %s. Shopping shortages follow this plan’s included recipes, prepared-component allocation, ingredient overrides, and current inventory.', 'persiano-hub' ), esc_html( get_the_title( $plan_id ) ) ); ?></p><?php endif; ?></section>
        <section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2><?php esc_html_e( 'Cheapest Practical Mix', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'One complete plan, grouped by store. Persiano picks the lowest practical package checkout cost for each included shortage.', 'persiano-hub' ); ?></p></div><?php if ( $groups ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_create_shopping_list"><input type="hidden" name="strategy" value="cheapest"><input type="hidden" name="plan_id" value="<?php echo esc_attr( $plan_id ); ?>"><input type="hidden" name="from" value="<?php echo esc_attr( $from ); ?>"><input type="hidden" name="to" value="<?php echo esc_attr( $to ); ?>"><?php wp_nonce_field( 'persiano_hub_create_shopping_list' ); ?><button class="button button-primary"><?php esc_html_e( 'Add entire plan to Shopping List', 'persiano-hub' ); ?></button></form><?php endif; ?></div>
        <?php if ( ! $groups ) : ?><p><?php esc_html_e( 'No shortages with saved vendor prices were found.', 'persiano-hub' ); ?></p><?php else : $grand=0; foreach ( $groups as $vendor=>$lines ) : $vendor_total=0; foreach ( $lines as $line ) { if ( ! empty( $line['quote']['estimated_cost'] ) ) { $vendor_total += $line['quote']['estimated_cost']; $grand += $line['quote']['estimated_cost']; } } ?><div class="ph-practical-vendor"><h3><?php echo esc_html( $vendor ); ?> <small><?php echo wp_kses_post( self::money( $vendor_total ) ); ?></small></h3><ul><?php foreach ( $lines as $line ) : $q=is_array($line['quote'] ?? null)?$line['quote']:array(); $packages=$q['packages']??0; $purchase_qty=$q['purchase_qty']??0; $purchase_unit=$q['purchase_unit']??''; $estimated_cost=$q['estimated_cost']??0; $required_qty=$line['required']??0; $required_unit=$line['base_unit']??''; $overbuy_ratio=$q['overbuy_ratio']??0; ?><li><strong><?php echo esc_html( $line['ingredient'] ); ?></strong> — <span><?php echo esc_html( sprintf( __( 'need %1$s %2$s', 'persiano-hub' ), self::format_quantity( $required_qty ), $required_unit ) ); ?></span> — <?php if($packages && $purchase_qty && $purchase_unit): echo esc_html( sprintf( __( 'buy %1$d × %2$s %3$s', 'persiano-hub' ), $packages, self::format_quantity( $purchase_qty ), $purchase_unit ) ); ?> — <?php echo wp_kses_post( self::money( $estimated_cost ) ); ?><?php if ( $overbuy_ratio >= 10 ) : ?> <em class="ph-price-stale"><?php esc_html_e( 'large package compared with need', 'persiano-hub' ); ?></em><?php endif; ?><?php else: ?><em><?php esc_html_e('No supplier package linked','persiano-hub'); ?></em><?php endif; ?></li><?php endforeach; ?></ul></div><?php endforeach; ?><p class="ph-grand-total"><strong><?php esc_html_e( 'Estimated checkout total:', 'persiano-hub' ); ?> <?php echo wp_kses_post( self::money( $grand ) ); ?></strong></p><?php endif; ?></section>
        <?php if ( $list_id && self::LIST_POST_TYPE === get_post_type( $list_id ) ) { self::render_shopping_list_editor( $list_id ); } ?>
        <?php self::render_one_store_comparison( $plan, $plan_id, $from, $to ); ?>
        <?php
    }

    private static function render_shopping_list_cards( $ids ) {
        if ( ! $ids ) { return; }
        echo '<section class="ph-costing-panel"><h2>' . esc_html__( 'Open Shopping Lists', 'persiano-hub' ) . '</h2><div class="ph-ops-card-grid">';
        foreach ( $ids as $id ) {
            $status = self::post_status_value( $id );
            $items = get_post_meta( $id, self::META_LIST_ITEMS, true ); $items = is_array( $items ) ? $items : array();
            $edit = self::page_url( 'purchasing', array( 'list_id'=>$id ) );
            $print = wp_nonce_url( add_query_arg( array( 'action'=>'persiano_hub_print_shopping_list', 'list_id'=>$id ), admin_url( 'admin-post.php' ) ), 'persiano_hub_print_shopping_list_' . $id );
            $actions = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'persiano-hub' ) . '</a> · <a target="_blank" href="' . esc_url( $print ) . '">' . esc_html__( 'Print', 'persiano-hub' ) . '</a> · <a href="' . esc_url( self::list_action_url( $id, 'duplicate' ) ) . '">' . esc_html__( 'Duplicate', 'persiano-hub' ) . '</a>';
            if ( 'done' === $status || 'archived' === $status ) {
                $actions .= ' · <a href="' . esc_url( self::list_action_url( $id, 'active' ) ) . '">' . esc_html__( 'Reopen', 'persiano-hub' ) . '</a>';
            } else {
                $actions .= ' · <a href="' . esc_url( self::list_action_url( $id, 'done' ) ) . '">' . esc_html__( 'Mark done', 'persiano-hub' ) . '</a> · <a href="' . esc_url( self::list_action_url( $id, 'archived' ) ) . '">' . esc_html__( 'Archive', 'persiano-hub' ) . '</a>';
            }
            echo '<article class="ph-ops-card"><h3><a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( $id ) ) . '</a></h3><p>' . esc_html( count( $items ) ) . ' ' . esc_html__( 'items', 'persiano-hub' ) . ' · <span class="ph-status-chip ph-status-' . esc_attr( $status ) . '">' . esc_html( self::status_options()[ $status ] ) . '</span></p><p class="ph-quick-actions">' . $actions . '</p></article>';
        }
        echo '</div></section>';
    }

    private static function render_shopping_list_editor( $id ) {
        $items = get_post_meta( $id, self::META_LIST_ITEMS, true ); $items = is_array( $items ) ? $items : array();
        $status = self::post_status_value( $id );
        $print_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'  => 'persiano_hub_print_shopping_list',
                    'list_id' => $id,
                ),
                admin_url( 'admin-post.php' )
            ),
            'persiano_hub_print_shopping_list_' . $id
        );
        ?>
        <?php self::render_record_navigation( $id, self::LIST_POST_TYPE, 'purchasing', 'list_id' ); ?>
        <?php if ( self::current_revision() > (float) get_post_meta( $id, self::META_LIST_SYNCED, true ) ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'The source production plan, orders, recipes, prices, or inventory changed after this list was created. Refresh it to update required quantities and estimates; purchased rows and notes will be preserved.', 'persiano-hub' ); ?></p></div><?php endif; ?>
        <section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2><?php echo esc_html( get_the_title( $id ) ); ?></h2><p><?php esc_html_e( 'Edit quantities, vendors, prices and purchased status. Excluding an item keeps it in the list without counting it.', 'persiano-hub' ); ?></p></div><div class="ph-costing-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><input type="hidden" name="action" value="persiano_hub_refresh_shopping_list"><input type="hidden" name="list_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'persiano_hub_refresh_shopping_list_' . $id ); ?><button class="button button-primary"><?php esc_html_e( 'Refresh from source', 'persiano-hub' ); ?></button></form><a class="button" target="_blank" href="<?php echo esc_url( $print_url ); ?>"><?php esc_html_e( 'Print list', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( self::list_action_url( $id, 'duplicate' ) ); ?>"><?php esc_html_e( 'Duplicate', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( self::list_action_url( $id, 'done' ) ); ?>"><?php esc_html_e( 'Mark done', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( self::list_action_url( $id, 'archived' ) ); ?>"><?php esc_html_e( 'Archive', 'persiano-hub' ); ?></a></div></div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ph-shopping-list-form"><input type="hidden" name="action" value="persiano_hub_save_shopping_list"><input type="hidden" name="list_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'persiano_hub_save_shopping_list' ); ?><div class="ph-ops-inline-fields"><label><?php esc_html_e( 'List name', 'persiano-hub' ); ?><input type="text" name="list_title" value="<?php echo esc_attr( get_the_title( $id ) ); ?>"></label><label><?php esc_html_e( 'Status', 'persiano-hub' ); ?><?php self::select_status( 'list_status', $status ); ?></label></div>
        <div class="ph-costing-table-wrap"><table class="widefat striped ph-ops-edit-table" id="ph-shop-items"><thead><tr><th><?php esc_html_e( 'Include', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Ingredient', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Vendor / Brand', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Plan', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Estimated total', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Purchased / actual', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Actions', 'persiano-hub' ); ?></th></tr></thead><tbody><?php foreach ( $items as $i=>$row ) { self::render_shopping_row( $i, $row ); } ?></tbody></table></div><p><button type="button" class="button ph-add-shop-item"><?php esc_html_e( 'Add manual item', 'persiano-hub' ); ?></button></p><label><?php esc_html_e( 'Notes', 'persiano-hub' ); ?><textarea class="widefat" rows="3" name="list_notes"><?php echo esc_textarea( get_post_meta( $id, self::META_LIST_NOTES, true ) ); ?></textarea></label><p><button class="button button-primary" name="save_operation" value="save"><?php esc_html_e( 'Save list', 'persiano-hub' ); ?></button> <button class="button" name="save_operation" value="complete_purchases" onclick="return confirm('<?php echo esc_js( __( 'Update inventory and price history for every checked Purchased item?', 'persiano-hub' ) ); ?>')"><?php esc_html_e( 'Save & reconcile purchased items', 'persiano-hub' ); ?></button></p></form></section>
        <?php self::render_record_navigation( $id, self::LIST_POST_TYPE, 'purchasing', 'list_id' ); ?>
        <script type="text/template" id="ph-shop-item-template"><?php self::render_shopping_row( '__INDEX__', array( 'ingredient_id'=>0,'include'=>1,'vendor'=>'','brand'=>'','packages'=>1,'package_qty'=>1,'package_unit'=>'each','estimated_subtotal'=>0,'estimated_tax'=>0,'estimated_total'=>0,'required_qty'=>0,'required_unit'=>'each','purchased'=>0,'actual_qty'=>'','actual_unit'=>'each','actual_subtotal'=>'','actual_tax'=>'','note'=>'' ) ); ?></script>
        <?php
    }

    /**
     * Render a clean, standalone print view for a saved shopping list.
     *
     * Browser-printing the WordPress admin screen produces sidebars, controls,
     * narrow columns and many mostly-empty pages. This endpoint intentionally
     * outputs only the information needed while shopping.
     */
    public static function print_shopping_list() {
        self::require_permission();

        $id = isset( $_GET['list_id'] ) ? absint( $_GET['list_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $id || self::LIST_POST_TYPE !== get_post_type( $id ) ) {
            wp_die( esc_html__( 'Shopping list not found.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_hub_print_shopping_list_' . $id );

        $raw_items = get_post_meta( $id, self::META_LIST_ITEMS, true );
        $raw_items = is_array( $raw_items ) ? $raw_items : array();
        $groups    = array();
        $grand     = 0.0;
        $item_count = 0;
        $purchased_count = 0;

        foreach ( $raw_items as $row ) {
            if ( empty( $row['include'] ) ) {
                continue;
            }

            $ingredient_id = absint( $row['ingredient_id'] ?? 0 );
            $ingredient    = $ingredient_id ? get_the_title( $ingredient_id ) : '';
            if ( ! $ingredient ) {
                $ingredient = sanitize_text_field( $row['ingredient'] ?? __( 'Manual item', 'persiano-hub' ) );
            }

            $vendor = trim( sanitize_text_field( $row['vendor'] ?? '' ) );
            if ( '' === $vendor ) {
                $vendor = __( 'Unassigned store', 'persiano-hub' );
            }

            $estimated_total = self::decimal( $row['estimated_total'] ?? 0 );
            if ( $estimated_total <= 0 ) {
                $estimated_total = self::decimal( $row['estimated_subtotal'] ?? 0 ) + self::decimal( $row['estimated_tax'] ?? 0 );
            }

            $item = array(
                'ingredient'   => $ingredient,
                'brand'        => sanitize_text_field( $row['brand'] ?? '' ),
                'required_qty' => self::decimal( $row['required_qty'] ?? 0 ),
                'required_unit'=> sanitize_key( $row['required_unit'] ?? '' ),
                'packages'     => max( 0, absint( $row['packages'] ?? 0 ) ),
                'package_qty'  => self::decimal( $row['package_qty'] ?? 0 ),
                'package_unit' => sanitize_key( $row['package_unit'] ?? '' ),
                'estimated'    => max( 0, $estimated_total ),
                'purchased'    => ! empty( $row['purchased'] ),
                'note'         => sanitize_text_field( $row['note'] ?? '' ),
            );

            if ( ! isset( $groups[ $vendor ] ) ) {
                $groups[ $vendor ] = array(
                    'items'    => array(),
                    'subtotal' => 0.0,
                );
            }
            $groups[ $vendor ]['items'][] = $item;
            $groups[ $vendor ]['subtotal'] += $item['estimated'];
            $grand += $item['estimated'];
            $item_count++;
            if ( $item['purchased'] ) {
                $purchased_count++;
            }
        }

        uksort( $groups, 'strnatcasecmp' );

        $status_options = self::status_options();
        $status         = self::post_status_value( $id );
        $status_label   = $status_options[ $status ] ?? ucfirst( $status );
        $notes          = trim( (string) get_post_meta( $id, self::META_LIST_NOTES, true ) );
        $logo_id        = absint( get_theme_mod( 'custom_logo' ) );
        $logo_url       = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        $back_url       = self::page_url( 'purchasing', array( 'list_id' => $id ) );
        $printed_at     = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title><?php echo esc_html( get_the_title( $id ) ); ?></title>
            <style>
                :root{--ink:#28231f;--muted:#736b64;--line:#ddd6cf;--paper:#fff;--wash:#f6f2ed;--brand:#8e2435;--success:#466446}
                *{box-sizing:border-box}
                html{background:#ece8e3}
                body{margin:0;color:var(--ink);font:14px/1.42 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;background:#ece8e3}
                .toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 18px;background:#241f1b;color:#fff}
                .toolbar p{margin:0;font-size:12px;opacity:.8}
                .toolbar-actions{display:flex;gap:8px}
                .toolbar a,.toolbar button{border:0;border-radius:999px;padding:9px 15px;background:#fff;color:#241f1b;text-decoration:none;font-weight:700;cursor:pointer}
                .toolbar button{background:var(--brand);color:#fff}
                .sheet{width:min(920px,calc(100% - 32px));margin:24px auto;padding:34px 38px 26px;background:var(--paper);box-shadow:0 8px 32px rgba(35,29,24,.12)}
                .masthead{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;padding-bottom:20px;border-bottom:3px solid var(--brand)}
                .brand{display:flex;gap:12px;align-items:center}
                .brand img{max-width:100px;max-height:52px;width:auto;height:auto}
                .brand-name{font:700 20px/1.1 Georgia,serif;color:var(--brand)}
                .eyebrow{margin:0 0 5px;text-transform:uppercase;letter-spacing:.12em;font-size:10px;font-weight:800;color:var(--brand)}
                h1{margin:0;font:700 31px/1.12 Georgia,serif}
                .mast-meta{text-align:right;color:var(--muted);font-size:12px}
                .mast-meta strong{display:block;color:var(--ink);font-size:13px}
                .summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:18px 0 24px}
                .summary div{padding:11px 12px;background:var(--wash);border:1px solid #eee7e0;border-radius:8px}
                .summary strong{display:block;font-size:17px;color:var(--brand)}
                .summary span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}
                .notes{margin:0 0 22px;padding:12px 14px;border-left:4px solid var(--brand);background:#fbf8f5;white-space:pre-wrap}
                .store{margin:0 0 24px;break-inside:auto}
                .store-heading{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin:0;padding:9px 12px;background:var(--ink);color:#fff;break-after:avoid}
                .store-heading h2{margin:0;font-size:16px}
                .store-heading strong{font-size:14px}
                table{width:100%;border-collapse:collapse;table-layout:fixed}
                thead{display:table-header-group}
                th{padding:8px 7px;border-bottom:1px solid var(--line);color:var(--muted);font-size:9px;text-align:left;text-transform:uppercase;letter-spacing:.07em}
                td{padding:10px 7px;border-bottom:1px solid var(--line);vertical-align:top}
                tr{break-inside:avoid}
                .col-check{width:30px;text-align:center}
                .col-item{width:auto}
                .col-need{width:105px}
                .col-buy{width:145px}
                .col-est{width:88px;text-align:right}
                .checkbox{display:inline-flex;width:16px;height:16px;border:1.6px solid #39332e;border-radius:3px;align-items:center;justify-content:center;font-size:13px;line-height:1;color:var(--success)}
                .checkbox.checked:before{content:"✓";font-weight:900}
                .item-name{display:block;font-weight:800}
                .item-meta{display:block;margin-top:2px;color:var(--muted);font-size:11px}
                .money{text-align:right;font-weight:800;white-space:nowrap}
                .grand{display:flex;justify-content:flex-end;align-items:baseline;gap:18px;margin-top:8px;padding-top:15px;border-top:3px solid var(--brand);font-size:16px}
                .grand strong{font-size:24px;color:var(--brand)}
                .empty{padding:28px;text-align:center;background:var(--wash);color:var(--muted)}
                .footer{display:flex;justify-content:space-between;gap:12px;margin-top:30px;padding-top:12px;border-top:1px solid var(--line);color:var(--muted);font-size:10px}
                @page{margin:12mm;size:auto}
                @media print{
                    html,body{background:#fff}
                    .toolbar,.footer{display:none!important}
                    .sheet{width:auto;margin:0;padding:0;box-shadow:none}
                    .store-heading{-webkit-print-color-adjust:exact;print-color-adjust:exact}
                    .summary div,.notes{-webkit-print-color-adjust:exact;print-color-adjust:exact}
                    a{color:inherit;text-decoration:none}
                }
                @media(max-width:700px){
                    .sheet{width:100%;margin:0;padding:24px 18px}
                    .masthead{display:block}.mast-meta{text-align:left;margin-top:12px}
                    .summary{grid-template-columns:repeat(2,minmax(0,1fr))}
                    .col-need{width:84px}.col-buy{width:112px}.col-est{width:70px}
                }
            </style>
        </head>
        <body>
            <div class="toolbar">
                <div><strong><?php esc_html_e( 'Shopping list print preview', 'persiano-hub' ); ?></strong><p><?php esc_html_e( 'Only the clean list below will print.', 'persiano-hub' ); ?></p></div>
                <div class="toolbar-actions"><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to list', 'persiano-hub' ); ?></a><button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'persiano-hub' ); ?></button></div>
            </div>
            <main class="sheet">
                <header class="masthead">
                    <div>
                        <div class="brand">
                            <?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt=""><?php endif; ?>
                            <div><p class="eyebrow"><?php echo esc_html( sprintf( __( '%s purchasing', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ); ?></p><div class="brand-name"><?php echo esc_html( function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ); ?></div></div>
                        </div>
                        <h1><?php echo esc_html( get_the_title( $id ) ); ?></h1>
                    </div>
                    <div class="mast-meta"><strong><?php echo esc_html( $status_label ); ?></strong><?php echo esc_html( sprintf( __( 'Printed %s', 'persiano-hub' ), $printed_at ) ); ?></div>
                </header>

                <div class="summary">
                    <div><strong><?php echo esc_html( $item_count ); ?></strong><span><?php esc_html_e( 'Items', 'persiano-hub' ); ?></span></div>
                    <div><strong><?php echo esc_html( count( $groups ) ); ?></strong><span><?php esc_html_e( 'Stores', 'persiano-hub' ); ?></span></div>
                    <div><strong><?php echo esc_html( $purchased_count . '/' . $item_count ); ?></strong><span><?php esc_html_e( 'Purchased', 'persiano-hub' ); ?></span></div>
                    <div><strong><?php echo wp_kses_post( self::money( $grand ) ); ?></strong><span><?php esc_html_e( 'Estimated total', 'persiano-hub' ); ?></span></div>
                </div>

                <?php if ( '' !== $notes ) : ?><div class="notes"><strong><?php esc_html_e( 'Notes:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $notes ); ?></div><?php endif; ?>

                <?php if ( ! $groups ) : ?>
                    <div class="empty"><?php esc_html_e( 'This list has no included items.', 'persiano-hub' ); ?></div>
                <?php else : ?>
                    <?php foreach ( $groups as $vendor => $group ) : ?>
                        <section class="store">
                            <div class="store-heading"><h2><?php echo esc_html( $vendor ); ?></h2><strong><?php echo wp_kses_post( self::money( $group['subtotal'] ) ); ?></strong></div>
                            <table>
                                <thead><tr><th class="col-check"></th><th class="col-item"><?php esc_html_e( 'Item', 'persiano-hub' ); ?></th><th class="col-need"><?php esc_html_e( 'Need', 'persiano-hub' ); ?></th><th class="col-buy"><?php esc_html_e( 'Buy', 'persiano-hub' ); ?></th><th class="col-est"><?php esc_html_e( 'Estimate', 'persiano-hub' ); ?></th></tr></thead>
                                <tbody>
                                <?php foreach ( $group['items'] as $item ) : ?>
                                    <tr>
                                        <td class="col-check"><span class="checkbox <?php echo $item['purchased'] ? 'checked' : ''; ?>"></span></td>
                                        <td class="col-item"><span class="item-name"><?php echo esc_html( $item['ingredient'] ); ?></span><?php if ( $item['brand'] || $item['note'] ) : ?><span class="item-meta"><?php echo esc_html( implode( ' · ', array_filter( array( $item['brand'], $item['note'] ) ) ) ); ?></span><?php endif; ?></td>
                                        <td class="col-need"><?php echo esc_html( self::print_quantity( $item['required_qty'], $item['required_unit'] ) ); ?></td>
                                        <td class="col-buy"><?php echo esc_html( self::print_purchase_quantity( $item ) ); ?></td>
                                        <td class="col-est money"><?php echo $item['estimated'] > 0 ? wp_kses_post( self::money( $item['estimated'] ) ) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </section>
                    <?php endforeach; ?>
                    <div class="grand"><span><?php esc_html_e( 'Estimated checkout total', 'persiano-hub' ); ?></span><strong><?php echo wp_kses_post( self::money( $grand ) ); ?></strong></div>
                <?php endif; ?>

                <footer class="footer"><span><?php esc_html_e( 'Package estimates use the saved supplier prices and taxes.', 'persiano-hub' ); ?></span><span><?php echo esc_html( function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ); ?></span></footer>
            </main>
        </body>
        </html><?php
        exit;
    }

    private static function print_quantity( $quantity, $unit ) {
        $quantity = (float) $quantity;
        $number = abs( $quantity - round( $quantity ) ) < 0.00001 ? number_format_i18n( $quantity, 0 ) : rtrim( rtrim( number_format_i18n( $quantity, 2 ), '0' ), '.' );
        return trim( $number . ' ' . $unit );
    }

    private static function print_purchase_quantity( $item ) {
        if ( empty( $item['packages'] ) || empty( $item['package_qty'] ) || empty( $item['package_unit'] ) ) {
            return __( 'Confirm package', 'persiano-hub' );
        }
        return sprintf(
            '%1$s × %2$s',
            number_format_i18n( (int) $item['packages'] ),
            self::print_quantity( $item['package_qty'], $item['package_unit'] )
        );
    }

    private static function render_shopping_row( $i, $row ) {
        ?><tr><td><input type="hidden" name="shop_items[<?php echo esc_attr( $i ); ?>][include]" value="0"><input type="hidden" name="shop_items[<?php echo esc_attr( $i ); ?>][reconciled]" value="<?php echo ! empty( $row['reconciled'] ) ? '1' : '0'; ?>"><input type="checkbox" name="shop_items[<?php echo esc_attr( $i ); ?>][include]" value="1" <?php checked( ! empty( $row['include'] ) ); ?>></td><td><?php self::select_ingredient( 'shop_items['.$i.'][ingredient_id]', absint( $row['ingredient_id'] ?? 0 ) ); ?><br><small><?php echo esc_html( ( $row['required_qty'] ?? 0 ) . ' ' . ( $row['required_unit'] ?? '' ) . ' ' . __( 'needed', 'persiano-hub' ) ); ?></small><input type="hidden" name="shop_items[<?php echo esc_attr( $i ); ?>][required_qty]" value="<?php echo esc_attr( $row['required_qty'] ?? 0 ); ?>"><input type="hidden" name="shop_items[<?php echo esc_attr( $i ); ?>][required_unit]" value="<?php echo esc_attr( $row['required_unit'] ?? 'each' ); ?>"></td><td><input type="text" name="shop_items[<?php echo esc_attr( $i ); ?>][vendor]" value="<?php echo esc_attr( $row['vendor'] ?? '' ); ?>" placeholder="Vendor"><br><input type="text" name="shop_items[<?php echo esc_attr( $i ); ?>][brand]" value="<?php echo esc_attr( $row['brand'] ?? '' ); ?>" placeholder="Brand"></td><td><input type="number" min="0" step="1" name="shop_items[<?php echo esc_attr( $i ); ?>][packages]" value="<?php echo esc_attr( $row['packages'] ?? 1 ); ?>" style="width:65px"> × <input type="number" min="0" step="0.0001" name="shop_items[<?php echo esc_attr( $i ); ?>][package_qty]" value="<?php echo esc_attr( $row['package_qty'] ?? 1 ); ?>" style="width:80px"><select name="shop_items[<?php echo esc_attr( $i ); ?>][package_unit]"><?php foreach ( Persiano_Hub_Costing::unit_options() as $u=>$label ) : ?><option value="<?php echo esc_attr( $u ); ?>" <?php selected( $row['package_unit'] ?? 'each', $u ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td><td><input type="number" min="0" step="0.01" name="shop_items[<?php echo esc_attr( $i ); ?>][estimated_subtotal]" value="<?php echo esc_attr( $row['estimated_subtotal'] ?? 0 ); ?>" placeholder="Subtotal" style="width:90px"><br>+ <input type="number" min="0" step="0.01" name="shop_items[<?php echo esc_attr( $i ); ?>][estimated_tax]" value="<?php echo esc_attr( $row['estimated_tax'] ?? 0 ); ?>" placeholder="Tax" style="width:90px"><input type="hidden" name="shop_items[<?php echo esc_attr( $i ); ?>][estimated_total]" value="<?php echo esc_attr( $row['estimated_total'] ?? 0 ); ?>"></td><td><label><input type="hidden" name="shop_items[<?php echo esc_attr( $i ); ?>][purchased]" value="0"><input type="checkbox" name="shop_items[<?php echo esc_attr( $i ); ?>][purchased]" value="1" <?php checked( ! empty( $row['purchased'] ) ); ?>> <?php esc_html_e( 'Purchased', 'persiano-hub' ); ?></label><?php if ( ! empty( $row['reconciled'] ) ) : ?> <span class="ph-status-chip"><?php esc_html_e( 'Reconciled', 'persiano-hub' ); ?></span><?php endif; ?><br><input type="number" min="0" step="0.0001" name="shop_items[<?php echo esc_attr( $i ); ?>][actual_qty]" value="<?php echo esc_attr( $row['actual_qty'] ?? '' ); ?>" placeholder="Actual qty" style="width:90px"><select name="shop_items[<?php echo esc_attr( $i ); ?>][actual_unit]"><?php foreach ( Persiano_Hub_Costing::unit_options() as $u=>$label ) : ?><option value="<?php echo esc_attr( $u ); ?>" <?php selected( $row['actual_unit'] ?? 'each', $u ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><br><input type="number" min="0" step="0.01" name="shop_items[<?php echo esc_attr( $i ); ?>][actual_subtotal]" value="<?php echo esc_attr( $row['actual_subtotal'] ?? '' ); ?>" placeholder="Actual subtotal" style="width:110px"> + <input type="number" min="0" step="0.01" name="shop_items[<?php echo esc_attr( $i ); ?>][actual_tax]" value="<?php echo esc_attr( $row['actual_tax'] ?? '' ); ?>" placeholder="Tax" style="width:75px"></td><td><button type="button" class="button-link ph-row-toggle"><?php echo ! empty( $row['include'] ) ? esc_html__( 'Exclude', 'persiano-hub' ) : esc_html__( 'Include', 'persiano-hub' ); ?></button> · <button type="button" class="button-link ph-row-duplicate"><?php esc_html_e( 'Duplicate', 'persiano-hub' ); ?></button> · <button type="button" class="button-link-delete ph-row-delete"><?php esc_html_e( 'Delete', 'persiano-hub' ); ?></button></td></tr><?php
    }

    private static function render_one_store_comparison( $plan, $plan_id, $from, $to ) {
        echo '<section class="ph-costing-panel"><h2>' . esc_html__( 'One-store comparison', 'persiano-hub' ) . '</h2>';
        if ( empty( $plan['vendors'] ) ) { echo '<p>' . esc_html__( 'No vendor prices available yet.', 'persiano-hub' ) . '</p></section>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Vendor', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Estimated checkout', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Coverage', 'persiano-hub' ) . '</th><th></th></tr></thead><tbody>';
        foreach ( $plan['vendors'] as $vendor ) {
            echo '<tr><td><strong>' . esc_html( $vendor['name'] ) . '</strong></td><td>' . wp_kses_post( self::money( $vendor['total'] ) ) . '</td><td>' . esc_html( $vendor['coverage'] . ' / ' . count( $plan['items'] ) ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="persiano_hub_create_shopping_list"><input type="hidden" name="strategy" value="vendor"><input type="hidden" name="vendor" value="' . esc_attr( $vendor['name'] ) . '"><input type="hidden" name="plan_id" value="' . esc_attr( $plan_id ) . '"><input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '">'; wp_nonce_field( 'persiano_hub_create_shopping_list' ); echo '<button class="button button-small">' . esc_html__( 'Create list', 'persiano-hub' ) . '</button></form></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    /* --------------------------------------------------------------------- */
    /* Price checker                                                         */
    /* --------------------------------------------------------------------- */

    public static function render_price_checker() {
        self::require_permission();
        $ingredient_id = isset( $_GET['ingredient_id'] ) ? absint( $_GET['ingredient_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sort = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'unit_cost'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $need = isset( $_GET['need'] ) ? max( 0, self::decimal( wp_unslash( $_GET['need'] ) ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $lists = self::operation_posts( self::LIST_POST_TYPE, array( 'draft','active' ) );
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Vendor intelligence', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Price Checker', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Pick an ingredient and compare tax-inclusive supplier offers using normalized unit cost. Sort by the factor that matters and add any exact offer to a shopping list.', 'persiano-hub' ); ?></p></div></div>
        <?php $need_unit = $ingredient_id ? get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BASE_UNIT, true ) : ''; ?>
        <section class="ph-costing-panel"><form method="get"><input type="hidden" name="page" value="<?php echo esc_attr( Persiano_Hub_Costing::MENU_SLUG ); ?>"><input type="hidden" name="tab" value="price_checker"><label><?php esc_html_e( 'Ingredient', 'persiano-hub' ); ?> <?php self::select_ingredient( 'ingredient_id', $ingredient_id ); ?></label> <label><?php echo esc_html( $need_unit ? sprintf( __( 'Quantity needed (%s)', 'persiano-hub' ), $need_unit ) : __( 'Quantity needed', 'persiano-hub' ) ); ?> <input type="number" min="0" step="0.0001" name="need" value="<?php echo esc_attr( $need ); ?>"></label> <label><?php esc_html_e( 'Sort by', 'persiano-hub' ); ?> <select name="sort"><option value="unit_cost" <?php selected( $sort,'unit_cost' ); ?>><?php esc_html_e( 'Lowest unit price', 'persiano-hub' ); ?></option><option value="practical" <?php selected( $sort,'practical' ); ?>><?php esc_html_e( 'Lowest practical checkout', 'persiano-hub' ); ?></option><option value="gross_cost" <?php selected( $sort,'gross_cost' ); ?>><?php esc_html_e( 'Lowest package price', 'persiano-hub' ); ?></option><option value="newest" <?php selected( $sort,'newest' ); ?>><?php esc_html_e( 'Newest price', 'persiano-hub' ); ?></option><option value="vendor" <?php selected( $sort,'vendor' ); ?>><?php esc_html_e( 'Vendor', 'persiano-hub' ); ?></option><option value="package" <?php selected( $sort,'package' ); ?>><?php esc_html_e( 'Package size', 'persiano-hub' ); ?></option></select></label> <?php submit_button( __( 'Compare prices', 'persiano-hub' ), 'primary', '', false ); ?></form></section>
        <?php if ( $ingredient_id ) : $quotes = Persiano_Hub_Purchasing::quotes_for_ingredient( $ingredient_id, true ); foreach ( $quotes as &$q ) { $q['packages'] = $need > 0 ? max( 1, (int) ceil( $need / max( 0.000001, $q['usable_qty'] ) ) ) : 1; $q['practical_cost'] = $q['packages'] * $q['gross_cost']; } unset( $q ); self::sort_quotes( $quotes, $sort ); ?>
        <section class="ph-costing-panel"><h2><?php echo esc_html( get_the_title( $ingredient_id ) ); ?></h2><?php if ( ! $quotes ) : ?><p><?php esc_html_e( 'No saved supplier prices yet.', 'persiano-hub' ); ?></p><?php else : ?><div class="ph-costing-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Vendor', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Brand', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Package', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Final price', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Normalized unit', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Practical buy', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Source / age', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Add to list', 'persiano-hub' ); ?></th></tr></thead><tbody><?php foreach ( $quotes as $q ) : ?><tr><td><strong><?php echo esc_html( $q['vendor'] ); ?></strong></td><td><?php echo $q['brand'] ? esc_html( $q['brand'] ) : '—'; ?></td><td><?php echo esc_html( $q['purchase_qty'] . ' ' . $q['purchase_unit'] ); ?></td><td><?php echo wp_kses_post( self::money( $q['gross_cost'] ) ); ?></td><td><?php $normalized = self::normalized_price_display( $q['unit_cost'], $q['base_unit'] ); ?><strong><?php echo wp_kses_post( self::money( $normalized['price'] ) ); ?></strong> / <?php echo esc_html( $normalized['unit'] ); ?></td><td><?php echo esc_html( $q['packages'] . ' package(s)' ); ?><br><strong><?php echo wp_kses_post( self::money( $q['practical_cost'] ) ); ?></strong></td><td><?php echo esc_html( ucfirst( $q['source_type'] ?? 'purchase' ) ); ?><br><small><?php echo null !== $q['age_days'] ? esc_html( $q['age_days'] . ' days old' ) : '—'; ?></small></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_add_quote_to_list"><input type="hidden" name="ingredient_id" value="<?php echo esc_attr( $ingredient_id ); ?>"><input type="hidden" name="vendor" value="<?php echo esc_attr( $q['vendor'] ); ?>"><input type="hidden" name="brand" value="<?php echo esc_attr( $q['brand'] ); ?>"><input type="hidden" name="packages" value="<?php echo esc_attr( $q['packages'] ); ?>"><input type="hidden" name="package_qty" value="<?php echo esc_attr( $q['purchase_qty'] ); ?>"><input type="hidden" name="package_unit" value="<?php echo esc_attr( $q['purchase_unit'] ); ?>"><input type="hidden" name="subtotal_cost" value="<?php echo esc_attr( $q['subtotal_cost'] ); ?>"><input type="hidden" name="purchase_tax" value="<?php echo esc_attr( $q['purchase_tax'] ); ?>"><input type="hidden" name="estimated_total" value="<?php echo esc_attr( $q['practical_cost'] ); ?>"><input type="hidden" name="required_qty" value="<?php echo esc_attr( $need ); ?>"><input type="hidden" name="required_unit" value="<?php echo esc_attr( $q['base_unit'] ); ?>"><?php wp_nonce_field( 'persiano_hub_add_quote_to_list' ); ?><select name="list_id"><option value="0"><?php esc_html_e( 'New shopping list', 'persiano-hub' ); ?></option><?php foreach ( $lists as $lid ) : ?><option value="<?php echo esc_attr( $lid ); ?>"><?php echo esc_html( get_the_title( $lid ) ); ?></option><?php endforeach; ?></select><button class="button button-small"><?php esc_html_e( 'Add', 'persiano-hub' ); ?></button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        <?php endif;
    }

    private static function sort_quotes( &$quotes, $sort ) {
        usort( $quotes, function( $a, $b ) use ( $sort ) {
            if ( 'practical' === $sort ) { return $a['practical_cost'] <=> $b['practical_cost']; }
            if ( 'gross_cost' === $sort ) { return $a['gross_cost'] <=> $b['gross_cost']; }
            if ( 'newest' === $sort ) { return (int) $b['time'] <=> (int) $a['time']; }
            if ( 'vendor' === $sort ) { return strcasecmp( $a['vendor'], $b['vendor'] ); }
            if ( 'package' === $sort ) { return $b['usable_qty'] <=> $a['usable_qty']; }
            return $a['unit_cost'] <=> $b['unit_cost'];
        } );
    }
}
