<?php
/**
 * Persiano Ingredients, Recipes and Pricing.
 *
 * Provides an ingredient cost database, structured recipe costing, WooCommerce
 * product links, suggested tax-inclusive selling prices and a compact costing
 * dashboard. The older Persiano Pricing plugin, when present, is left untouched.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Costing {
    const INGREDIENT_POST_TYPE = 'persiano_ing';
    const RECIPE_POST_TYPE     = 'persiano_recipe';
    const MENU_SLUG            = 'persiano-hub-costing';

    const PRODUCT_RECIPE_META = '_persiano_recipe_id';

    const ING_CATEGORY      = '_persiano_ing_category';
    const ING_BRAND         = '_persiano_ing_brand';
    const ING_PURCHASE_QTY  = '_persiano_ing_purchase_qty';
    const ING_PURCHASE_UNIT = '_persiano_ing_purchase_unit';
    const ING_PURCHASE_COST = '_persiano_ing_purchase_cost';
    const ING_PURCHASE_TAX  = '_persiano_ing_purchase_tax';
    const ING_GROSS_COST    = '_persiano_ing_gross_cost';
    const ING_WASTE_PCT     = '_persiano_ing_waste_pct';
    const ING_SUPPLIER      = '_persiano_ing_supplier';
    const ING_NOTES         = '_persiano_ing_notes';
    const ING_UNIT_COST     = '_persiano_ing_unit_cost';
    const ING_BASE_UNIT     = '_persiano_ing_base_unit';
    const ING_HISTORY       = '_persiano_ing_cost_history';

    const RECIPE_PRODUCT_ID       = '_persiano_recipe_product_id';
    const RECIPE_YIELD_QTY        = '_persiano_recipe_yield_qty';
    const RECIPE_YIELD_LABEL      = '_persiano_recipe_yield_label';
    const RECIPE_ITEMS            = '_persiano_recipe_items';
    const RECIPE_PACKAGING_COST   = '_persiano_recipe_packaging_cost';
    const RECIPE_LABOUR_MINUTES   = '_persiano_recipe_labour_minutes';
    const RECIPE_LABOUR_RATE      = '_persiano_recipe_labour_rate';
    const RECIPE_OTHER_COST       = '_persiano_recipe_other_cost';
    const RECIPE_MISC_COST        = '_persiano_recipe_misc_cost';
    const RECIPE_CONTINGENCY_PCT  = '_persiano_recipe_contingency_pct';
    const RECIPE_PROCESSING_FEE_PCT = '_persiano_recipe_processing_fee_pct';
    const RECIPE_OVERHEAD_PCT     = '_persiano_recipe_overhead_pct';
    const RECIPE_TARGET_COST_PCT  = '_persiano_recipe_target_food_cost_pct';
    const RECIPE_INGREDIENT_COST  = '_persiano_recipe_ingredient_cost';
    const RECIPE_LABOUR_COST      = '_persiano_recipe_labour_cost';
    const RECIPE_OVERHEAD_COST    = '_persiano_recipe_overhead_cost';
    const RECIPE_BATCH_COST       = '_persiano_recipe_batch_cost';
    const RECIPE_COST_PER_SERVING = '_persiano_recipe_cost_per_serving'; // Legacy alias: now stores product/package cost.
    const RECIPE_COST_PER_BASE_UNIT = '_persiano_recipe_cost_per_base_unit';
    const RECIPE_PRODUCT_COST      = '_persiano_recipe_product_cost';
    const RECIPE_PRICING_LABEL     = '_persiano_recipe_pricing_label';
    const RECIPE_SUGGESTED_PRICE  = '_persiano_recipe_suggested_price';
    const RECIPE_NET_SUGGESTED_PRICE = '_persiano_recipe_net_suggested_price';
    const RECIPE_TAX_AMOUNT       = '_persiano_recipe_tax_amount';
    const RECIPE_CONTINGENCY_COST = '_persiano_recipe_contingency_cost';
    const RECIPE_PROCESSING_FEE_COST = '_persiano_recipe_processing_fee_cost';
    const RECIPE_WARNINGS         = '_persiano_recipe_cost_warnings';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_types' ), 12 );
        add_action( 'admin_menu', array( __CLASS__, 'register_dashboard_page' ), 35 );

        add_action( 'add_meta_boxes_' . self::INGREDIENT_POST_TYPE, array( __CLASS__, 'ingredient_meta_boxes' ) );
        add_action( 'add_meta_boxes_' . self::RECIPE_POST_TYPE, array( __CLASS__, 'recipe_meta_boxes' ) );
        add_action( 'save_post_' . self::INGREDIENT_POST_TYPE, array( __CLASS__, 'save_ingredient' ), 10, 3 );
        add_action( 'save_post_' . self::RECIPE_POST_TYPE, array( __CLASS__, 'save_recipe' ), 10, 3 );

        add_filter( 'manage_' . self::INGREDIENT_POST_TYPE . '_posts_columns', array( __CLASS__, 'ingredient_columns' ) );
        add_action( 'manage_' . self::INGREDIENT_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'ingredient_column_content' ), 10, 2 );
        add_filter( 'manage_' . self::RECIPE_POST_TYPE . '_posts_columns', array( __CLASS__, 'recipe_columns' ) );
        add_action( 'manage_' . self::RECIPE_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'recipe_column_content' ), 10, 2 );

        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_action( 'add_meta_boxes_product', array( __CLASS__, 'product_costing_meta_box' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_recipe_link' ) );
        add_action( 'admin_post_persiano_hub_apply_suggested_price', array( __CLASS__, 'apply_suggested_price' ) );
    }

    private static function capabilities() {
        return array(
            'edit_post'              => 'manage_woocommerce',
            'read_post'              => 'manage_woocommerce',
            'delete_post'            => 'manage_woocommerce',
            'edit_posts'             => 'manage_woocommerce',
            'edit_others_posts'      => 'manage_woocommerce',
            'publish_posts'          => 'manage_woocommerce',
            'read_private_posts'     => 'manage_woocommerce',
            'delete_posts'           => 'manage_woocommerce',
            'delete_private_posts'   => 'manage_woocommerce',
            'delete_published_posts' => 'manage_woocommerce',
            'delete_others_posts'    => 'manage_woocommerce',
            'edit_private_posts'     => 'manage_woocommerce',
            'edit_published_posts'   => 'manage_woocommerce',
            'create_posts'           => 'manage_woocommerce',
        );
    }

    public static function register_post_types() {
        register_post_type(
            self::INGREDIENT_POST_TYPE,
            array(
                'labels' => array(
                    'name'               => __( 'Ingredients', 'persiano-hub' ),
                    'singular_name'      => __( 'Ingredient', 'persiano-hub' ),
                    'menu_name'          => __( 'Ingredients', 'persiano-hub' ),
                    'add_new'            => __( 'Add Ingredient', 'persiano-hub' ),
                    'add_new_item'       => __( 'Add Ingredient', 'persiano-hub' ),
                    'edit_item'          => __( 'Edit Ingredient', 'persiano-hub' ),
                    'new_item'           => __( 'New Ingredient', 'persiano-hub' ),
                    'search_items'       => __( 'Search Ingredients', 'persiano-hub' ),
                    'not_found'          => __( 'No ingredients found.', 'persiano-hub' ),
                    'all_items'          => __( 'Ingredients', 'persiano-hub' ),
                ),
                'public'             => false,
                'show_ui'            => true,
                'show_in_menu'       => false,
                'show_in_rest'       => false,
                'supports'           => array( 'title' ),
                'capabilities'       => self::capabilities(),
                'map_meta_cap'       => false,
                'exclude_from_search'=> true,
                'publicly_queryable' => false,
                'query_var'          => false,
                'rewrite'            => false,
                'menu_position'      => 15,
            )
        );

        register_post_type(
            self::RECIPE_POST_TYPE,
            array(
                'labels' => array(
                    'name'               => __( 'Recipes & Pricing', 'persiano-hub' ),
                    'singular_name'      => __( 'Recipe', 'persiano-hub' ),
                    'menu_name'          => __( 'Recipes & Pricing', 'persiano-hub' ),
                    'add_new'            => __( 'Add Recipe', 'persiano-hub' ),
                    'add_new_item'       => __( 'Add Recipe & Costing', 'persiano-hub' ),
                    'edit_item'          => __( 'Edit Recipe & Costing', 'persiano-hub' ),
                    'new_item'           => __( 'New Recipe', 'persiano-hub' ),
                    'search_items'       => __( 'Search Recipes', 'persiano-hub' ),
                    'not_found'          => __( 'No recipes found.', 'persiano-hub' ),
                    'all_items'          => __( 'Recipes & Pricing', 'persiano-hub' ),
                ),
                'public'             => false,
                'show_ui'            => true,
                'show_in_menu'       => false,
                'show_in_rest'       => false,
                'supports'           => array( 'title' ),
                'capabilities'       => self::capabilities(),
                'map_meta_cap'       => false,
                'exclude_from_search'=> true,
                'publicly_queryable' => false,
                'query_var'          => false,
                'rewrite'            => false,
                'menu_position'      => 16,
            )
        );
    }

    public static function register_dashboard_page() {
        add_submenu_page(
            'persiano-hub',
            __( 'Costing & Recipes', 'persiano-hub' ),
            __( 'Costing & Recipes', 'persiano-hub' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( __CLASS__, 'render_dashboard_page' )
        );
    }

    public static function unit_options() {
        return array(
            'g'    => __( 'g', 'persiano-hub' ),
            'kg'   => __( 'kg', 'persiano-hub' ),
            'oz'   => __( 'oz', 'persiano-hub' ),
            'lb'   => __( 'lb', 'persiano-hub' ),
            'ml'   => __( 'ml', 'persiano-hub' ),
            'l'    => __( 'L', 'persiano-hub' ),
            'tsp'  => __( 'tsp', 'persiano-hub' ),
            'tbsp' => __( 'tbsp', 'persiano-hub' ),
            'cup'  => __( 'cup', 'persiano-hub' ),
            'each' => __( 'each', 'persiano-hub' ),
        );
    }

    private static function unit_family( $unit ) {
        if ( in_array( $unit, array( 'g', 'kg', 'oz', 'lb' ), true ) ) {
            return 'mass';
        }
        if ( in_array( $unit, array( 'ml', 'l', 'tsp', 'tbsp', 'cup' ), true ) ) {
            return 'volume';
        }
        if ( 'each' === $unit ) {
            return 'count';
        }
        return '';
    }

    private static function base_unit( $unit ) {
        $family = self::unit_family( $unit );
        if ( 'mass' === $family ) {
            return 'g';
        }
        if ( 'volume' === $family ) {
            return 'ml';
        }
        if ( 'count' === $family ) {
            return 'each';
        }
        return '';
    }

    private static function unit_multiplier( $unit ) {
        $multipliers = array(
            'g'    => 1,
            'kg'   => 1000,
            'oz'   => 28.349523125,
            'lb'   => 453.59237,
            'ml'   => 1,
            'l'    => 1000,
            'tsp'  => 5,
            'tbsp' => 15,
            'cup'  => 250,
            'each' => 1,
        );
        return isset( $multipliers[ $unit ] ) ? (float) $multipliers[ $unit ] : 0;
    }

    private static function normalize_amount( $quantity, $unit, $expected_base_unit ) {
        $quantity = (float) $quantity;
        $base     = self::base_unit( $unit );
        if ( $quantity < 0 || ! $base || $base !== $expected_base_unit ) {
            return null;
        }
        return $quantity * self::unit_multiplier( $unit );
    }


    /**
     * Convert legacy/descriptive recipe yield labels into a measurable unit.
     * Unknown labels such as "servings", "pieces", "jars" or "rolls"
     * are count-based and therefore normalize to "each".
     */
    public static function canonical_recipe_unit( $unit ) {
        $raw = strtolower( trim( wp_strip_all_tags( (string) $unit ) ) );
        $key = sanitize_key( $raw );

        if ( preg_match( '/\b(kg|kilograms?)\b/i', $raw ) ) { return 'kg'; }
        if ( preg_match( '/\b(g|grams?)\b/i', $raw ) ) { return 'g'; }
        if ( preg_match( '/\b(ml|millilit(?:er|re)s?)\b/i', $raw ) ) { return 'ml'; }
        if ( preg_match( '/\b(l|lit(?:er|re)s?)\b/i', $raw ) ) { return 'l'; }
        if ( preg_match( '/\b(oz|ounces?)\b/i', $raw ) ) { return 'oz'; }
        if ( preg_match( '/\b(lb|pounds?)\b/i', $raw ) ) { return 'lb'; }
        if ( preg_match( '/\b(tsp|teaspoons?)\b/i', $raw ) ) { return 'tsp'; }
        if ( preg_match( '/\b(tbsp|tablespoons?)\b/i', $raw ) ) { return 'tbsp'; }
        if ( preg_match( '/\b(cups?)\b/i', $raw ) ) { return 'cup'; }

        if ( array_key_exists( $key, self::unit_options() ) ) {
            return $key;
        }

        return 'each';
    }

    private static function normalize_between_units( $quantity, $from_unit, $to_unit ) {
        $quantity = (float) $quantity;
        $from_unit = self::canonical_recipe_unit( $from_unit );
        $to_unit   = self::canonical_recipe_unit( $to_unit );
        $from_family = self::unit_family( $from_unit );
        $to_family   = self::unit_family( $to_unit );

        if ( $quantity < 0 || ! $from_family || $from_family !== $to_family ) {
            return null;
        }

        $to_multiplier = self::unit_multiplier( $to_unit );
        if ( $to_multiplier <= 0 ) {
            return null;
        }

        return $quantity * self::unit_multiplier( $from_unit ) / $to_multiplier;
    }

    private static function recipe_yield_details( $recipe_id, $override_qty = null ) {
        $qty = null === $override_qty
            ? max( 0.0001, self::decimal( get_post_meta( $recipe_id, self::RECIPE_YIELD_QTY, true ), 1 ) )
            : max( 0.0001, self::decimal( $override_qty, 1 ) );
        $unit = self::canonical_recipe_unit( get_post_meta( $recipe_id, self::RECIPE_YIELD_LABEL, true ) );
        $base = self::base_unit( $unit );
        $normalized = $qty * self::unit_multiplier( $unit );

        return array(
            'qty'        => $qty,
            'unit'       => $unit,
            'base_unit'  => $base,
            'normalized' => $normalized,
        );
    }

    private static function product_pricing_basis( $product_id, $yield_unit, $yield_qty ) {
        $yield_unit = self::canonical_recipe_unit( $yield_unit );
        $base_unit  = self::base_unit( $yield_unit );
        $whole_batch_normalized = max( 0.0001, (float) $yield_qty * self::unit_multiplier( $yield_unit ) );
        $result = array(
            'pricing_qty'        => (float) $yield_qty,
            'pricing_unit'       => $yield_unit,
            'pricing_normalized' => $whole_batch_normalized,
            'pricing_label'      => sprintf( __( 'Whole batch (%1$s %2$s)', 'persiano-hub' ), self::format_decimal( $yield_qty ), $yield_unit ),
            'pricing_source'     => 'batch',
            'can_apply_price'    => false,
            'warnings'           => array(),
        );

        if ( ! $product_id ) {
            return $result;
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
        $size = class_exists( 'Persiano_Hub_Product_Fields' )
            ? trim( (string) get_post_meta( $product_id, Persiano_Hub_Product_Fields::META_SIZE, true ) )
            : '';

        if ( $size ) {
            preg_match_all(
                '/([0-9]+(?:[.,][0-9]+)?)\s*(kg|kilograms?|g|grams?|ml|millilit(?:er|re)s?|l|lit(?:er|re)s?|oz|ounces?|lb|pounds?|cup|cups|tbsp|tablespoons?|tsp|teaspoons?|each|pieces?|portions?|servings?|serves?|jars?|containers?|packages?|trays?|units?|rolls?)\b/iu',
                $size,
                $matches,
                PREG_SET_ORDER
            );
            foreach ( $matches as $match ) {
                $qty = (float) str_replace( ',', '.', $match[1] );
                $unit = self::canonical_recipe_unit( $match[2] );
                $converted = self::normalize_between_units( $qty, $unit, $yield_unit );
                if ( null === $converted ) {
                    continue;
                }
                $result['pricing_qty']        = $qty;
                $result['pricing_unit']       = $unit;
                $result['pricing_normalized'] = $converted * self::unit_multiplier( $yield_unit );
                $result['pricing_label']      = sprintf( __( 'Product package (%1$s %2$s)', 'persiano-hub' ), self::format_decimal( $qty ), $unit );
                $result['pricing_source']     = 'product_size';
                $result['can_apply_price']    = true;
                return $result;
            }
        }

        if ( 'mass' === self::unit_family( $yield_unit ) && $product && $product->get_weight() ) {
            $weight_unit = get_option( 'woocommerce_weight_unit', 'kg' );
            $weight_qty  = (float) $product->get_weight();
            $converted   = self::normalize_between_units( $weight_qty, $weight_unit, $yield_unit );
            if ( null !== $converted ) {
                $result['pricing_qty']        = $weight_qty;
                $result['pricing_unit']       = self::canonical_recipe_unit( $weight_unit );
                $result['pricing_normalized'] = $converted * self::unit_multiplier( $yield_unit );
                $result['pricing_label']      = sprintf( __( 'Product package (%1$s %2$s)', 'persiano-hub' ), self::format_decimal( $weight_qty ), self::canonical_recipe_unit( $weight_unit ) );
                $result['pricing_source']     = 'product_weight';
                $result['can_apply_price']    = true;
                return $result;
            }
        }

        if ( 'count' === self::unit_family( $yield_unit ) ) {
            $result['pricing_qty']        = 1;
            $result['pricing_unit']       = 'each';
            $result['pricing_normalized'] = 1;
            $result['pricing_label']      = __( 'One sellable unit', 'persiano-hub' );
            $result['pricing_source']     = 'count_default';
            $result['can_apply_price']    = true;
            return $result;
        }

        $result['warnings'][] = __( 'The linked product needs a compatible Size / package value (for example 250 g or 500 ml). Until then, the suggested price is for the whole recipe batch and cannot be applied automatically.', 'persiano-hub' );
        return $result;
    }

    private static function format_decimal( $value, $decimals = 4 ) {
        $formatted = number_format( (float) $value, $decimals, '.', '' );
        $formatted = rtrim( rtrim( $formatted, '0' ), '.' );
        return '' === $formatted ? '0' : $formatted;
    }

    private static function precise_money( $value ) {
        $symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
        $value = (float) $value;
        $decimals = abs( $value ) < 0.01 && 0 !== $value ? 5 : 4;
        return $symbol . number_format_i18n( $value, $decimals );
    }

    private static function decimal( $value, $default = 0 ) {
        if ( is_string( $value ) ) {
            $value = str_replace( ',', '.', $value );
        }
        return is_numeric( $value ) ? (float) $value : (float) $default;
    }

    private static function money( $value ) {
        if ( function_exists( 'wc_price' ) ) {
            return wc_price( (float) $value );
        }
        return '$' . number_format_i18n( (float) $value, 2 );
    }

    /** Preserve meaningful precision for low per-gram/per-ml costs. */
    private static function unit_money( $value ) {
        $value = (float) $value;
        $decimals = ( $value > 0 && $value < 0.01 ) ? 5 : 2;
        $symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
        return esc_html( $symbol ) . number_format_i18n( $value, $decimals );
    }

    public static function ingredient_meta_boxes() {
        add_meta_box(
            'persiano_hub_ingredient_costing',
            __( 'Ingredient Cost', 'persiano-hub' ),
            array( __CLASS__, 'render_ingredient_meta_box' ),
            self::INGREDIENT_POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'persiano_hub_ingredient_history',
            __( 'Recent Cost History', 'persiano-hub' ),
            array( __CLASS__, 'render_ingredient_history_meta_box' ),
            self::INGREDIENT_POST_TYPE,
            'side',
            'default'
        );
    }

    public static function render_ingredient_meta_box( $post ) {
        wp_nonce_field( 'persiano_hub_save_ingredient', 'persiano_hub_ingredient_nonce' );

        $category      = get_post_meta( $post->ID, self::ING_CATEGORY, true );
        $brand         = get_post_meta( $post->ID, self::ING_BRAND, true );
        $purchase_qty  = get_post_meta( $post->ID, self::ING_PURCHASE_QTY, true );
        $purchase_unit = get_post_meta( $post->ID, self::ING_PURCHASE_UNIT, true );
        $purchase_cost = get_post_meta( $post->ID, self::ING_PURCHASE_COST, true );
        $purchase_tax  = get_post_meta( $post->ID, self::ING_PURCHASE_TAX, true );
        $gross_cost    = (float) get_post_meta( $post->ID, self::ING_GROSS_COST, true );
        if ( ! $gross_cost ) {
            $gross_cost = (float) $purchase_cost + (float) $purchase_tax;
        }
        $waste_pct     = get_post_meta( $post->ID, self::ING_WASTE_PCT, true );
        $supplier      = get_post_meta( $post->ID, self::ING_SUPPLIER, true );
        $notes         = get_post_meta( $post->ID, self::ING_NOTES, true );
        $unit_cost     = (float) get_post_meta( $post->ID, self::ING_UNIT_COST, true );
        $base_unit     = get_post_meta( $post->ID, self::ING_BASE_UNIT, true );
        $ingredient_type = get_post_meta( $post->ID, '_persiano_ingredient_type', true );
        $preferred_unit = get_post_meta( $post->ID, '_persiano_preferred_unit', true );
        $grams_per_cup = get_post_meta( $post->ID, '_persiano_grams_per_cup', true );
        $grams_per_tbsp = get_post_meta( $post->ID, '_persiano_grams_per_tbsp', true );
        $grams_per_tsp = get_post_meta( $post->ID, '_persiano_grams_per_tsp', true );
        $density_g_ml = get_post_meta( $post->ID, '_persiano_density_g_ml', true );
        $include_on_label = get_post_meta( $post->ID, '_persiano_include_on_label', true );

        if ( ! $purchase_unit ) {
            $purchase_unit = 'kg';
        }
        ?>
        <div class="ph-costing-grid ph-costing-grid--2">
            <div class="ph-costing-field">
                <label for="persiano_ingredient_type"><?php esc_html_e( 'Ingredient type', 'persiano-hub' ); ?></label>
                <select class="widefat" id="persiano_ingredient_type" name="persiano_ingredient_type">
                    <?php foreach ( array( 'purchased' => 'Purchased ingredient', 'pantry' => 'Pantry / basic ingredient', 'process' => 'Non-purchasable process ingredient', 'packaging' => 'Packaging item', 'garnish' => 'Optional garnish' ) as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $ingredient_type, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e( 'Process ingredients such as boiling water remain in recipes but are excluded from purchasing.', 'persiano-hub' ); ?></p>
            </div>
            <div class="ph-costing-field">
                <label for="persiano_preferred_unit"><?php esc_html_e( 'Preferred recipe unit', 'persiano-hub' ); ?></label>
                <select class="widefat" id="persiano_preferred_unit" name="persiano_preferred_unit">
                    <?php foreach ( self::unit_options() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $preferred_unit, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="ph-costing-field">
                <label for="persiano_ing_category"><?php esc_html_e( 'Category', 'persiano-hub' ); ?></label>
                <input type="text" class="widefat" id="persiano_ing_category" name="persiano_ing_category" value="<?php echo esc_attr( $category ); ?>" placeholder="<?php esc_attr_e( 'Produce, meat, dairy, packaging…', 'persiano-hub' ); ?>">
            </div>
            <div class="ph-costing-field">
                <label for="persiano_ing_brand"><?php esc_html_e( 'Brand', 'persiano-hub' ); ?></label>
                <input type="text" class="widefat" id="persiano_ing_brand" name="persiano_ing_brand" value="<?php echo esc_attr( $brand ); ?>" placeholder="<?php esc_attr_e( 'Optional', 'persiano-hub' ); ?>">
            </div>
            <div class="ph-costing-field">
                <label for="persiano_ing_supplier"><?php esc_html_e( 'Supplier / price source', 'persiano-hub' ); ?></label>
                <input type="text" class="widefat" id="persiano_ing_supplier" name="persiano_ing_supplier" value="<?php echo esc_attr( $supplier ); ?>" placeholder="<?php esc_attr_e( 'Required for any purchase or observed price', 'persiano-hub' ); ?>">
                <p class="description"><?php esc_html_e( 'Required whenever package quantity, unit or price data is recorded, including catalogues, websites, flyers and quotations.', 'persiano-hub' ); ?></p>
            </div>
            <div class="ph-costing-field">
                <label for="persiano_ing_purchase_qty"><?php esc_html_e( 'Purchase quantity', 'persiano-hub' ); ?></label>
                <input type="number" min="0" step="0.0001" class="widefat ph-ing-live" id="persiano_ing_purchase_qty" name="persiano_ing_purchase_qty" value="<?php echo esc_attr( $purchase_qty ); ?>" placeholder="10">
            </div>
            <div class="ph-costing-field">
                <label for="persiano_ing_purchase_unit"><?php esc_html_e( 'Purchase unit', 'persiano-hub' ); ?></label>
                <select class="widefat ph-ing-live" id="persiano_ing_purchase_unit" name="persiano_ing_purchase_unit">
                    <?php foreach ( self::unit_options() as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $purchase_unit, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ph-costing-field">
                <label for="persiano_ing_purchase_cost"><?php esc_html_e( 'Item / subtotal cost before purchase tax', 'persiano-hub' ); ?></label>
                <input type="number" min="0" step="0.01" class="widefat ph-ing-live" id="persiano_ing_purchase_cost" name="persiano_ing_purchase_cost" value="<?php echo esc_attr( $purchase_cost ); ?>" placeholder="25.00">
                <p class="description"><?php esc_html_e( 'Use the line or package price before GST/PST when tax is shown separately.', 'persiano-hub' ); ?></p>
            </div>
            <div class="ph-costing-field">
                <label for="persiano_ing_purchase_tax"><?php esc_html_e( 'Purchase tax paid', 'persiano-hub' ); ?></label>
                <input type="number" min="0" step="0.01" class="widefat ph-ing-live" id="persiano_ing_purchase_tax" name="persiano_ing_purchase_tax" value="<?php echo esc_attr( $purchase_tax ); ?>" placeholder="0.00">
                <p class="description"><?php esc_html_e( 'Persiano includes this amount in the ingredient cost used by recipes and purchasing estimates.', 'persiano-hub' ); ?></p>
            </div>
            <div class="ph-costing-field">
                <label for="persiano_ing_waste_pct"><?php esc_html_e( 'Estimated waste / trim', 'persiano-hub' ); ?></label>
                <div class="ph-input-suffix"><input type="number" min="0" max="99" step="0.1" class="widefat ph-ing-live" id="persiano_ing_waste_pct" name="persiano_ing_waste_pct" value="<?php echo esc_attr( $waste_pct ); ?>" placeholder="0"><span>%</span></div>
                <p class="description"><?php esc_html_e( 'Optional. Example: peel, bones, trimming or unusable product.', 'persiano-hub' ); ?></p>
            </div>
        </div>

        <h3><?php esc_html_e( 'Weight ↔ volume conversion profile', 'persiano-hub' ); ?></h3>
        <div class="ph-costing-grid ph-costing-grid--2">
            <div class="ph-costing-field"><label for="persiano_grams_per_cup">g per cup</label><input type="number" min="0" step="0.01" class="widefat" id="persiano_grams_per_cup" name="persiano_grams_per_cup" value="<?php echo esc_attr( $grams_per_cup ); ?>"></div>
            <div class="ph-costing-field"><label for="persiano_grams_per_tbsp">g per tbsp</label><input type="number" min="0" step="0.01" class="widefat" id="persiano_grams_per_tbsp" name="persiano_grams_per_tbsp" value="<?php echo esc_attr( $grams_per_tbsp ); ?>"></div>
            <div class="ph-costing-field"><label for="persiano_grams_per_tsp">g per tsp</label><input type="number" min="0" step="0.01" class="widefat" id="persiano_grams_per_tsp" name="persiano_grams_per_tsp" value="<?php echo esc_attr( $grams_per_tsp ); ?>"></div>
            <div class="ph-costing-field"><label for="persiano_density_g_ml">Density (g/ml)</label><input type="number" min="0" step="0.0001" class="widefat" id="persiano_density_g_ml" name="persiano_density_g_ml" value="<?php echo esc_attr( $density_g_ml ); ?>"></div>
            <div class="ph-costing-field ph-costing-field--full"><label><input type="checkbox" name="persiano_include_on_label" value="1" <?php checked( $include_on_label, '1' ); ?>> <?php esc_html_e( 'Include this ingredient on customer-facing labels', 'persiano-hub' ); ?></label></div>
        </div>

        <div class="ph-cost-summary ph-cost-summary--ingredient">
            <div><span><?php esc_html_e( 'Cost used for costing (incl. purchase tax)', 'persiano-hub' ); ?></span><strong id="ph-live-gross-cost"><?php echo wp_kses_post( self::money( $gross_cost ) ); ?></strong></div>
            <div><span><?php esc_html_e( 'Usable cost', 'persiano-hub' ); ?></span><strong id="ph-live-unit-cost"><?php echo $unit_cost > 0 ? wp_kses_post( self::unit_money( $unit_cost ) . ' / ' . esc_html( $base_unit ) ) : '—'; ?></strong></div>
            <div><span><?php esc_html_e( 'Equivalent', 'persiano-hub' ); ?></span><strong id="ph-live-equivalent"><?php echo $unit_cost > 0 && in_array( $base_unit, array( 'g', 'ml' ), true ) ? wp_kses_post( self::money( $unit_cost * 1000 ) . ( 'g' === $base_unit ? ' / kg' : ' / L' ) ) : '—'; ?></strong></div>
        </div>

        <div class="ph-costing-field ph-costing-field--full">
            <label for="persiano_ing_notes"><?php esc_html_e( 'Notes', 'persiano-hub' ); ?></label>
            <textarea class="widefat" rows="4" id="persiano_ing_notes" name="persiano_ing_notes"><?php echo esc_textarea( $notes ); ?></textarea>
        </div>
        <?php
    }

    public static function render_ingredient_history_meta_box( $post ) {
        $history = get_post_meta( $post->ID, self::ING_HISTORY, true );
        $history = is_array( $history ) ? array_reverse( $history ) : array();
        $history = array_slice( $history, 0, 6 );

        if ( ! $history ) {
            echo '<p>' . esc_html__( 'Cost changes will appear here after you save this ingredient.', 'persiano-hub' ) . '</p>';
            return;
        }

        $best_entry = null;
        foreach ( $history as $candidate ) {
            if ( empty( $candidate['unit_cost'] ) ) {
                continue;
            }
            if ( null === $best_entry || (float) $candidate['unit_cost'] < (float) $best_entry['unit_cost'] ) {
                $best_entry = $candidate;
            }
        }
        if ( $best_entry ) {
            echo '<div class="ph-best-price"><strong>' . esc_html__( 'Best recorded unit cost', 'persiano-hub' ) . '</strong><span>' . wp_kses_post( self::unit_money( (float) $best_entry['unit_cost'] ) ) . ' / ' . esc_html( $best_entry['base_unit'] ?? '' ) . '</span>';
            if ( ! empty( $best_entry['supplier'] ) ) {
                echo '<small>' . esc_html( $best_entry['supplier'] ) . '</small>';
            }
            echo '</div>';
        }

        echo '<div class="ph-history-list">';
        foreach ( $history as $entry ) {
            $date      = ! empty( $entry['time'] ) ? wp_date( 'M j, Y', absint( $entry['time'] ) ) : '';
            $unit_cost = isset( $entry['unit_cost'] ) ? (float) $entry['unit_cost'] : 0;
            $base_unit = ! empty( $entry['base_unit'] ) ? $entry['base_unit'] : '';
            echo '<div class="ph-history-entry">';
            $display_cost = isset( $entry['gross_cost'] ) ? (float) $entry['gross_cost'] : ( (float) ( $entry['purchase_cost'] ?? 0 ) + (float) ( $entry['purchase_tax'] ?? 0 ) );
            echo '<strong>' . wp_kses_post( self::money( $display_cost ) ) . '</strong>';
            echo '<span>' . esc_html( isset( $entry['purchase_qty'] ) ? $entry['purchase_qty'] : '' ) . ' ' . esc_html( isset( $entry['purchase_unit'] ) ? $entry['purchase_unit'] : '' ) . '</span>';
            if ( ! empty( $entry['brand'] ) || ! empty( $entry['supplier'] ) ) {
                echo '<small>' . esc_html( trim( ( $entry['brand'] ?? '' ) . ( ! empty( $entry['brand'] ) && ! empty( $entry['supplier'] ) ? ' · ' : '' ) . ( $entry['supplier'] ?? '' ) ) ) . '</small>';
            }
            if ( $unit_cost > 0 ) {
                echo '<small>' . wp_kses_post( self::money( $unit_cost ) ) . ' / ' . esc_html( $base_unit ) . '</small>';
            }
            echo '<small>' . esc_html( $date ) . '</small>';
            echo '</div>';
        }
        echo '</div>';
    }

    public static function save_ingredient( $post_id, $post, $update ) {
        if ( ! self::can_save_post( $post_id, 'persiano_hub_ingredient_nonce', 'persiano_hub_save_ingredient' ) ) {
            return;
        }

        $old_snapshot = array(
            'purchase_qty'  => get_post_meta( $post_id, self::ING_PURCHASE_QTY, true ),
            'purchase_unit' => get_post_meta( $post_id, self::ING_PURCHASE_UNIT, true ),
            'purchase_cost' => get_post_meta( $post_id, self::ING_PURCHASE_COST, true ),
            'purchase_tax'  => get_post_meta( $post_id, self::ING_PURCHASE_TAX, true ),
            'waste_pct'     => get_post_meta( $post_id, self::ING_WASTE_PCT, true ),
        );

        $category      = isset( $_POST['persiano_ing_category'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_ing_category'] ) ) : '';
        $brand         = isset( $_POST['persiano_ing_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_ing_brand'] ) ) : '';
        $purchase_qty  = isset( $_POST['persiano_ing_purchase_qty'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_ing_purchase_qty'] ) ) ) : 0;
        $purchase_unit = isset( $_POST['persiano_ing_purchase_unit'] ) ? sanitize_key( wp_unslash( $_POST['persiano_ing_purchase_unit'] ) ) : 'kg';
        $purchase_cost = isset( $_POST['persiano_ing_purchase_cost'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_ing_purchase_cost'] ) ) ) : 0;
        $purchase_tax  = isset( $_POST['persiano_ing_purchase_tax'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_ing_purchase_tax'] ) ) ) : 0;
        $waste_pct     = isset( $_POST['persiano_ing_waste_pct'] ) ? min( 99, max( 0, self::decimal( wp_unslash( $_POST['persiano_ing_waste_pct'] ) ) ) ) : 0;
        $supplier      = isset( $_POST['persiano_ing_supplier'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_ing_supplier'] ) ) : '';
        $notes         = isset( $_POST['persiano_ing_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_ing_notes'] ) ) : '';
        $ingredient_type = isset( $_POST['persiano_ingredient_type'] ) ? sanitize_key( wp_unslash( $_POST['persiano_ingredient_type'] ) ) : 'purchased';
        $preferred_unit = isset( $_POST['persiano_preferred_unit'] ) ? sanitize_key( wp_unslash( $_POST['persiano_preferred_unit'] ) ) : $purchase_unit;
        $grams_per_cup = isset( $_POST['persiano_grams_per_cup'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_grams_per_cup'] ) ) ) : 0;
        $grams_per_tbsp = isset( $_POST['persiano_grams_per_tbsp'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_grams_per_tbsp'] ) ) ) : 0;
        $grams_per_tsp = isset( $_POST['persiano_grams_per_tsp'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_grams_per_tsp'] ) ) ) : 0;
        $density_g_ml = isset( $_POST['persiano_density_g_ml'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_density_g_ml'] ) ) ) : 0;
        $include_on_label = ! empty( $_POST['persiano_include_on_label'] ) ? 1 : 0;

        if ( ! array_key_exists( $purchase_unit, self::unit_options() ) ) {
            $purchase_unit = 'kg';
        }

        $base_unit = self::base_unit( $purchase_unit );
        $base_qty  = $purchase_qty * self::unit_multiplier( $purchase_unit );
        $usable     = $base_qty * ( 1 - ( $waste_pct / 100 ) );
        $gross_cost = $purchase_cost + $purchase_tax;
        $unit_cost  = $usable > 0 ? $gross_cost / $usable : 0;

        update_post_meta( $post_id, self::ING_CATEGORY, $category );
        update_post_meta( $post_id, self::ING_BRAND, $brand );
        update_post_meta( $post_id, self::ING_PURCHASE_QTY, $purchase_qty );
        update_post_meta( $post_id, self::ING_PURCHASE_UNIT, $purchase_unit );
        update_post_meta( $post_id, self::ING_PURCHASE_COST, $purchase_cost );
        update_post_meta( $post_id, self::ING_PURCHASE_TAX, $purchase_tax );
        update_post_meta( $post_id, self::ING_GROSS_COST, $gross_cost );
        update_post_meta( $post_id, self::ING_WASTE_PCT, $waste_pct );
        update_post_meta( $post_id, self::ING_SUPPLIER, $supplier );
        update_post_meta( $post_id, self::ING_NOTES, $notes );
        update_post_meta( $post_id, self::ING_UNIT_COST, $unit_cost );
        update_post_meta( $post_id, self::ING_BASE_UNIT, $base_unit );
        update_post_meta( $post_id, '_persiano_ingredient_type', $ingredient_type );
        update_post_meta( $post_id, '_persiano_preferred_unit', $preferred_unit );
        update_post_meta( $post_id, '_persiano_grams_per_cup', $grams_per_cup );
        update_post_meta( $post_id, '_persiano_grams_per_tbsp', $grams_per_tbsp );
        update_post_meta( $post_id, '_persiano_grams_per_tsp', $grams_per_tsp );
        update_post_meta( $post_id, '_persiano_density_g_ml', $density_g_ml );
        update_post_meta( $post_id, '_persiano_include_on_label', $include_on_label );

        $new_snapshot = array(
            'purchase_qty'  => $purchase_qty,
            'purchase_unit' => $purchase_unit,
            'purchase_cost' => $purchase_cost,
            'purchase_tax'  => $purchase_tax,
            'waste_pct'     => $waste_pct,
        );

        if ( ! $update || $old_snapshot !== $new_snapshot ) {
            $history = get_post_meta( $post_id, self::ING_HISTORY, true );
            $history = is_array( $history ) ? $history : array();
            $history[] = array(
                'time'          => time(),
                'purchase_qty'  => $purchase_qty,
                'purchase_unit' => $purchase_unit,
                'purchase_cost' => $purchase_cost,
                'purchase_tax'  => $purchase_tax,
                'gross_cost'    => $gross_cost,
                'waste_pct'     => $waste_pct,
                'brand'         => $brand,
                'supplier'      => $supplier,
                'unit_cost'     => $unit_cost,
                'base_unit'     => $base_unit,
            );
            if ( count( $history ) > 50 ) {
                $history = array_slice( $history, -50 );
            }
            update_post_meta( $post_id, self::ING_HISTORY, $history );
        }

        if ( class_exists( 'Persiano_Hub_Ingredient_Master' ) ) {
            Persiano_Hub_Ingredient_Master::backfill_supplier_packages( $post_id );
            Persiano_Hub_Ingredient_Master::repair_and_apply_current_cost( $post_id );
        }
        self::recalculate_all_recipes();
    }

    public static function recipe_meta_boxes() {
        add_meta_box(
            'persiano_hub_recipe_details',
            __( 'Recipe & Costing', 'persiano-hub' ),
            array( __CLASS__, 'render_recipe_meta_box' ),
            self::RECIPE_POST_TYPE,
            'normal',
            'high'
        );
    }

    private static function get_ingredients_for_select() {
        return get_posts(
            array(
                'post_type'      => self::INGREDIENT_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
    }

    private static function get_products_for_select() {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return array();
        }
        return wc_get_products(
            array(
                'limit'   => -1,
                'status'  => array( 'publish', 'draft', 'private' ),
                'orderby' => 'name',
                'order'   => 'ASC',
                'return'  => 'objects',
            )
        );
    }

    private static function get_recipes_for_select( $exclude_id = 0 ) {
        $args = array(
            'post_type'      => self::RECIPE_POST_TYPE,
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        );
        if ( $exclude_id ) {
            $args['post__not_in'] = array( absint( $exclude_id ) );
        }
        return get_posts( $args );
    }

    private static function get_recipe_items( $recipe_id ) {
        $items = get_post_meta( $recipe_id, self::RECIPE_ITEMS, true );
        return is_array( $items ) ? $items : array();
    }

    public static function render_recipe_meta_box( $post ) {
        wp_nonce_field( 'persiano_hub_save_recipe', 'persiano_hub_recipe_nonce' );

        $product_id      = absint( get_post_meta( $post->ID, self::RECIPE_PRODUCT_ID, true ) );
        $yield_qty       = self::decimal( get_post_meta( $post->ID, self::RECIPE_YIELD_QTY, true ), 1 );
        $yield_label     = self::canonical_recipe_unit( get_post_meta( $post->ID, self::RECIPE_YIELD_LABEL, true ) );
        $items           = self::get_recipe_items( $post->ID );
        $packaging_cost  = self::decimal( get_post_meta( $post->ID, self::RECIPE_PACKAGING_COST, true ) );
        $labour_minutes  = self::decimal( get_post_meta( $post->ID, self::RECIPE_LABOUR_MINUTES, true ) );
        $labour_rate     = self::decimal( get_post_meta( $post->ID, self::RECIPE_LABOUR_RATE, true ) );
        $other_cost      = self::decimal( get_post_meta( $post->ID, self::RECIPE_OTHER_COST, true ) );
        $misc_cost       = self::decimal( get_post_meta( $post->ID, self::RECIPE_MISC_COST, true ) );
        $contingency_pct = self::decimal( get_post_meta( $post->ID, self::RECIPE_CONTINGENCY_PCT, true ) );
        $processing_fee_pct = self::decimal( get_post_meta( $post->ID, self::RECIPE_PROCESSING_FEE_PCT, true ) );
        $overhead_pct    = self::decimal( get_post_meta( $post->ID, self::RECIPE_OVERHEAD_PCT, true ) );
        $target_cost_pct = self::decimal( get_post_meta( $post->ID, self::RECIPE_TARGET_COST_PCT, true ), 35 );
        $ingredients     = self::get_ingredients_for_select();
        $subrecipes      = self::get_recipes_for_select( $post->ID );
        $products        = self::get_products_for_select();
        $summary         = self::calculate_recipe( $post->ID );

        if ( ! $yield_label ) {
            $yield_label = 'each';
        }
        if ( ! $items ) {
            $items = array( array( 'ingredient_id' => 0, 'qty' => '', 'unit' => 'g' ) );
        }
        ?>
        <div class="ph-costing-section">
            <h3><?php esc_html_e( 'Product & Yield', 'persiano-hub' ); ?></h3>
            <div class="ph-costing-grid ph-costing-grid--3">
                <div class="ph-costing-field ph-costing-field--wide">
                    <label for="persiano_recipe_product_id"><?php esc_html_e( 'Linked WooCommerce product', 'persiano-hub' ); ?></label>
                    <select class="widefat ph-recipe-live" id="persiano_recipe_product_id" name="persiano_recipe_product_id">
                        <option value="0"><?php esc_html_e( '— No linked product yet —', 'persiano-hub' ); ?></option>
                        <?php foreach ( $products as $product ) : ?>
                            <?php
                            $option_price      = self::decimal( $product->get_price() );
                            $option_tax_factor = self::add_base_tax_to_price( 1, $product->get_id() );
                            $option_size       = class_exists( 'Persiano_Hub_Product_Fields' ) ? get_post_meta( $product->get_id(), Persiano_Hub_Product_Fields::META_SIZE, true ) : '';
                            $option_weight     = $product->get_weight();
                            $option_weight_unit= get_option( 'woocommerce_weight_unit', 'kg' );
                            ?>
                            <option value="<?php echo esc_attr( $product->get_id() ); ?>" data-price="<?php echo esc_attr( $option_price ); ?>" data-tax-factor="<?php echo esc_attr( $option_tax_factor ); ?>" data-package-size="<?php echo esc_attr( $option_size ); ?>" data-weight="<?php echo esc_attr( $option_weight ); ?>" data-weight-unit="<?php echo esc_attr( $option_weight_unit ); ?>" <?php selected( $product_id, $product->get_id() ); ?>><?php echo esc_html( $product->get_name() . ' (#' . $product->get_id() . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Links this recipe to the item customers buy. Pricing can then compare the real cost with the current product price.', 'persiano-hub' ); ?></p>
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_yield_qty"><?php esc_html_e( 'Total batch output', 'persiano-hub' ); ?></label>
                    <input type="number" min="0.01" step="0.01" class="widefat ph-recipe-live" id="persiano_recipe_yield_qty" name="persiano_recipe_yield_qty" value="<?php echo esc_attr( $yield_qty ); ?>">
                    <p class="description"><?php esc_html_e( 'Enter the total amount the full recipe produces. This is physical output, not the number used to force a selling price.', 'persiano-hub' ); ?></p>
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_yield_label"><?php esc_html_e( 'Output unit', 'persiano-hub' ); ?></label>
                    <select class="widefat ph-recipe-live" id="persiano_recipe_yield_label" name="persiano_recipe_yield_label">
                        <?php foreach ( self::unit_options() as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $yield_label, $value ); ?>><?php echo esc_html( 'each' === $value ? __( 'each / serving / piece', 'persiano-hub' ) : $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Sub-recipes inherit this unit automatically. g and kg, or ml and L, are converted to the same base before costing.', 'persiano-hub' ); ?></p>
                </div>
            </div>
        </div>

        <?php if ( class_exists( 'Persiano_Hub_Structural_Data' ) ) { Persiano_Hub_Structural_Data::render_recipe_fields( $post->ID ); } ?>

        <div class="ph-costing-section">
            <div class="ph-costing-heading-row">
                <div>
                    <h3><?php esc_html_e( 'Ingredients', 'persiano-hub' ); ?></h3>
                    <p><?php esc_html_e( 'Costs are pulled from the Ingredient database automatically.', 'persiano-hub' ); ?></p>
                </div>
                <button type="button" class="button" id="ph-add-recipe-ingredient"><?php esc_html_e( '+ Add ingredient', 'persiano-hub' ); ?></button>
            </div>

            <?php if ( ! $ingredients ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'Add at least one ingredient first. You can still save the recipe now and come back later.', 'persiano-hub' ); ?> <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::INGREDIENT_POST_TYPE ) ); ?>"><?php esc_html_e( 'Add ingredient', 'persiano-hub' ); ?></a></p></div>
            <?php endif; ?>

            <div class="ph-recipe-items" id="ph-recipe-items">
                <div class="ph-recipe-row ph-recipe-row--header"><span><?php esc_html_e( 'Ingredient / sub-recipe', 'persiano-hub' ); ?></span><span><?php esc_html_e( 'Quantity', 'persiano-hub' ); ?></span><span><?php esc_html_e( 'Unit', 'persiano-hub' ); ?></span><span><?php esc_html_e( 'Preparation', 'persiano-hub' ); ?></span><span><?php esc_html_e( 'Scale rounding', 'persiano-hub' ); ?></span><span><?php esc_html_e( 'Cost', 'persiano-hub' ); ?></span><span></span></div>
                <?php foreach ( $items as $index => $item ) : ?>
                    <?php self::render_recipe_item_row( $index, $item, $ingredients, $subrecipes ); ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ph-costing-section">
            <h3><?php esc_html_e( 'Other Costs', 'persiano-hub' ); ?></h3>
            <div class="ph-costing-grid ph-costing-grid--3">
                <div class="ph-costing-field">
                    <label for="persiano_recipe_packaging_cost"><?php esc_html_e( 'Packaging / batch', 'persiano-hub' ); ?></label>
                    <input type="number" min="0" step="0.01" class="widefat ph-recipe-live" id="persiano_recipe_packaging_cost" name="persiano_recipe_packaging_cost" value="<?php echo esc_attr( $packaging_cost ); ?>">
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_labour_minutes"><?php esc_html_e( 'Active labour minutes / batch', 'persiano-hub' ); ?></label>
                    <input type="number" min="0" step="1" class="widefat ph-recipe-live" id="persiano_recipe_labour_minutes" name="persiano_recipe_labour_minutes" value="<?php echo esc_attr( $labour_minutes ); ?>">
                    <p class="description"><?php esc_html_e( 'Only hands-on time should be costed as labour. Passive simmering, baking and resting can be tracked in the recipe steps.', 'persiano-hub' ); ?></p>
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_labour_rate"><?php esc_html_e( 'Labour rate / hour', 'persiano-hub' ); ?></label>
                    <input type="number" min="0" step="0.01" class="widefat ph-recipe-live" id="persiano_recipe_labour_rate" name="persiano_recipe_labour_rate" value="<?php echo esc_attr( $labour_rate ); ?>">
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_other_cost"><?php esc_html_e( 'Other fixed cost / batch', 'persiano-hub' ); ?></label>
                    <input type="number" min="0" step="0.01" class="widefat ph-recipe-live" id="persiano_recipe_other_cost" name="persiano_recipe_other_cost" value="<?php echo esc_attr( $other_cost ); ?>">
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_misc_cost"><?php esc_html_e( 'Misc. / unforeseen cost per batch', 'persiano-hub' ); ?></label>
                    <input type="number" min="0" step="0.01" class="widefat ph-recipe-live" id="persiano_recipe_misc_cost" name="persiano_recipe_misc_cost" value="<?php echo esc_attr( $misc_cost ); ?>">
                    <p class="description"><?php esc_html_e( 'Small hard-to-track costs such as parchment, foil, cleaning supplies or minor waste.', 'persiano-hub' ); ?></p>
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_contingency_pct"><?php esc_html_e( 'Contingency buffer', 'persiano-hub' ); ?></label>
                    <div class="ph-input-suffix"><input type="number" min="0" max="99" step="0.1" class="widefat ph-recipe-live" id="persiano_recipe_contingency_pct" name="persiano_recipe_contingency_pct" value="<?php echo esc_attr( $contingency_pct ); ?>"><span>%</span></div>
                    <p class="description"><?php esc_html_e( 'Percentage buffer added after known costs and overhead.', 'persiano-hub' ); ?></p>
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_processing_fee_pct"><?php esc_html_e( 'Payment / processing allowance', 'persiano-hub' ); ?></label>
                    <div class="ph-input-suffix"><input type="number" min="0" max="50" step="0.1" class="widefat ph-recipe-live" id="persiano_recipe_processing_fee_pct" name="persiano_recipe_processing_fee_pct" value="<?php echo esc_attr( $processing_fee_pct ); ?>"><span>%</span></div>
                    <p class="description"><?php esc_html_e( 'Optional. Builds card/payment processing into the recommended customer-facing price.', 'persiano-hub' ); ?></p>
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_overhead_pct"><?php esc_html_e( 'Overhead', 'persiano-hub' ); ?></label>
                    <div class="ph-input-suffix"><input type="number" min="0" step="0.1" class="widefat ph-recipe-live" id="persiano_recipe_overhead_pct" name="persiano_recipe_overhead_pct" value="<?php echo esc_attr( $overhead_pct ); ?>"><span>%</span></div>
                </div>
                <div class="ph-costing-field">
                    <label for="persiano_recipe_target_food_cost_pct"><?php esc_html_e( 'Target total cost percentage', 'persiano-hub' ); ?></label>
                    <div class="ph-input-suffix"><input type="number" min="1" max="99" step="0.1" class="widefat ph-recipe-live" id="persiano_recipe_target_food_cost_pct" name="persiano_recipe_target_food_cost_pct" value="<?php echo esc_attr( $target_cost_pct ); ?>"><span>%</span></div>
                    <p class="description"><?php esc_html_e( 'The estimated total cost per serving as a percentage of the pre-tax selling price.', 'persiano-hub' ); ?></p>
                </div>
            </div>
        </div>

        <?php self::render_recipe_summary( $post->ID, $summary ); ?>

        <script type="text/template" id="ph-recipe-row-template">
            <?php self::render_recipe_item_row( '__INDEX__', array( 'source_type' => 'ingredient', 'source_id' => 0, 'qty' => '', 'unit' => 'g', 'prep_note' => '', 'rounding' => 'exact' ), $ingredients, $subrecipes ); ?>
        </script>
        <?php
    }

    private static function render_recipe_item_row( $index, $item, $ingredients, $subrecipes = array() ) {
        $source_type = isset( $item['source_type'] ) ? sanitize_key( $item['source_type'] ) : 'ingredient';
        $source_id   = isset( $item['source_id'] ) ? absint( $item['source_id'] ) : ( isset( $item['ingredient_id'] ) ? absint( $item['ingredient_id'] ) : 0 );
        $qty         = isset( $item['qty'] ) ? $item['qty'] : '';
        $unit        = isset( $item['unit'] ) ? sanitize_key( $item['unit'] ) : 'g';
        $prep_note   = isset( $item['prep_note'] ) ? $item['prep_note'] : '';
        $rounding    = isset( $item['rounding'] ) ? sanitize_key( $item['rounding'] ) : 'exact';
        $selected_source = $source_type . ':' . $source_id;
        $rounding_options = array(
            'exact'    => __( 'Exact', 'persiano-hub' ),
            'whole_up' => __( 'Whole ↑', 'persiano-hub' ),
            'half_up'  => __( '0.5 ↑', 'persiano-hub' ),
            '5'        => __( '5 units ↑', 'persiano-hub' ),
            '10'       => __( '10 units ↑', 'persiano-hub' ),
            '25'       => __( '25 units ↑', 'persiano-hub' ),
            '50'       => __( '50 units ↑', 'persiano-hub' ),
            '100'      => __( '100 units ↑', 'persiano-hub' ),
        );
        ?>
        <div class="ph-recipe-row" data-index="<?php echo esc_attr( $index ); ?>">
            <select class="ph-recipe-ingredient widefat" name="persiano_recipe_items[<?php echo esc_attr( $index ); ?>][source]">
                <option value="0"><?php esc_html_e( 'Select ingredient or component…', 'persiano-hub' ); ?></option>
                <?php if ( $ingredients ) : ?><optgroup label="<?php esc_attr_e( 'Ingredients', 'persiano-hub' ); ?>">
                <?php foreach ( $ingredients as $ingredient ) :
                    $unit_cost = (float) get_post_meta( $ingredient->ID, self::ING_UNIT_COST, true );
                    $base_unit = get_post_meta( $ingredient->ID, self::ING_BASE_UNIT, true );
                    $value = 'ingredient:' . $ingredient->ID;
                    ?>
                    <option value="<?php echo esc_attr( $value ); ?>" data-source-type="ingredient" data-unit-cost="<?php echo esc_attr( $unit_cost ); ?>" data-base-unit="<?php echo esc_attr( $base_unit ); ?>" <?php selected( $selected_source, $value ); ?>><?php echo esc_html( $ingredient->post_title ); ?></option>
                <?php endforeach; ?></optgroup><?php endif; ?>
                <?php if ( $subrecipes ) : ?><optgroup label="<?php esc_attr_e( 'Prepared components / sub-recipes', 'persiano-hub' ); ?>">
                <?php foreach ( $subrecipes as $subrecipe ) :
                    $subsummary = self::calculate_recipe( $subrecipe->ID );
                    $value = 'recipe:' . $subrecipe->ID;
                    ?>
                    <option value="<?php echo esc_attr( $value ); ?>" data-source-type="recipe" data-product-cost="<?php echo esc_attr( $subsummary['product_cost'] ); ?>" data-unit-cost="<?php echo esc_attr( $subsummary['cost_per_base_unit'] ); ?>" data-base-unit="<?php echo esc_attr( $subsummary['base_unit'] ); ?>" data-yield-unit="<?php echo esc_attr( $subsummary['yield_unit'] ); ?>" data-yield-qty="<?php echo esc_attr( $subsummary['effective_yield_qty'] ); ?>" data-batch-cost="<?php echo esc_attr( $subsummary['effective_batch_cost'] ); ?>" <?php selected( $selected_source, $value ); ?>><?php echo esc_html( $subrecipe->post_title ); ?></option>
                <?php endforeach; ?></optgroup><?php endif; ?>
            </select>
            <input type="number" min="0" step="0.0001" class="ph-recipe-qty widefat" name="persiano_recipe_items[<?php echo esc_attr( $index ); ?>][qty]" value="<?php echo esc_attr( $qty ); ?>">
            <select class="ph-recipe-unit widefat" name="persiano_recipe_items[<?php echo esc_attr( $index ); ?>][unit]">
                <?php foreach ( self::unit_options() as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $unit, $value ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
                <option value="serving" <?php selected( $unit, 'serving' ); ?>><?php esc_html_e( 'serving', 'persiano-hub' ); ?></option>
                <option value="batch" <?php selected( $unit, 'batch' ); ?>><?php esc_html_e( 'batch', 'persiano-hub' ); ?></option>
            </select>
            <input type="text" class="widefat ph-recipe-prep-note" name="persiano_recipe_items[<?php echo esc_attr( $index ); ?>][prep_note]" value="<?php echo esc_attr( $prep_note ); ?>" placeholder="diced, cooked, divided…">
            <select class="widefat ph-recipe-rounding" name="persiano_recipe_items[<?php echo esc_attr( $index ); ?>][rounding]">
                <?php foreach ( $rounding_options as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rounding, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
            </select>
            <strong class="ph-recipe-line-cost">—</strong>
            <button type="button" class="button-link-delete ph-remove-recipe-row" aria-label="<?php esc_attr_e( 'Remove ingredient', 'persiano-hub' ); ?>">×</button>
        </div>
        <?php
    }

    private static function render_recipe_summary( $recipe_id, $summary ) {
        $product_id    = absint( get_post_meta( $recipe_id, self::RECIPE_PRODUCT_ID, true ) );
        $product       = $product_id ? wc_get_product( $product_id ) : false;
        $current_price = $product ? self::decimal( $product->get_price() ) : 0;
        $net_price     = $product && $current_price > 0 && function_exists( 'wc_get_price_excluding_tax' )
            ? (float) wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $current_price ) )
            : $current_price;
        $margin        = $net_price > 0 ? ( ( $net_price - $summary['product_cost'] ) / $net_price ) * 100 : null;
        $actual_cost   = class_exists( 'Persiano_Hub_Kitchen' ) ? self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Kitchen::RECIPE_ACTUAL_COST, true ) ) : 0;
        $variance      = $actual_cost > 0 && $summary['batch_cost'] > 0 ? ( ( $actual_cost - $summary['batch_cost'] ) / $summary['batch_cost'] ) * 100 : null;
        $cost_source_label = 'actual' === $summary['cost_source'] ? __( 'Actual production data', 'persiano-hub' ) : __( 'Theoretical recipe cost', 'persiano-hub' );
        ?>
        <div class="ph-cost-summary ph-cost-summary--recipe" id="ph-recipe-summary" data-current-price="<?php echo esc_attr( $current_price ); ?>" data-tax-factor="<?php echo esc_attr( self::add_base_tax_to_price( 1, $product_id ) ); ?>">
            <div><span><?php esc_html_e( 'Ingredients / components', 'persiano-hub' ); ?></span><strong data-summary="ingredients"><?php echo wp_kses_post( self::money( $summary['ingredient_cost'] ) ); ?></strong></div>
            <div><span><?php esc_html_e( 'Active labour', 'persiano-hub' ); ?></span><strong data-summary="labour"><?php echo wp_kses_post( self::money( $summary['labour_cost'] ) ); ?></strong></div>
            <div><span><?php esc_html_e( 'Contingency', 'persiano-hub' ); ?></span><strong data-summary="contingency"><?php echo wp_kses_post( self::money( $summary['contingency_cost'] ) ); ?></strong></div>
            <div><span><?php esc_html_e( 'Theoretical batch cost', 'persiano-hub' ); ?></span><strong data-summary="batch"><?php echo wp_kses_post( self::money( $summary['batch_cost'] ) ); ?></strong></div>
            <div><span><?php esc_html_e( 'Cost source used for pricing', 'persiano-hub' ); ?></span><strong data-summary="cost-source"><?php echo esc_html( $cost_source_label ); ?></strong></div>
            <div><span><?php esc_html_e( 'Normalized unit cost', 'persiano-hub' ); ?></span><strong><span data-summary="unit-cost"><?php echo wp_kses_post( self::precise_money( $summary['cost_per_base_unit'] ) ); ?></span> / <span data-summary="base-unit"><?php echo esc_html( $summary['base_unit'] ); ?></span></strong></div>
            <div><span><?php esc_html_e( 'Pricing basis', 'persiano-hub' ); ?></span><strong data-summary="pricing-basis"><?php echo esc_html( $summary['pricing_label'] ); ?></strong></div>
            <div><span><?php esc_html_e( 'Cost for pricing unit', 'persiano-hub' ); ?></span><strong data-summary="per-serving"><?php echo wp_kses_post( self::money( $summary['product_cost'] ) ); ?></strong></div>
            <div><span><?php esc_html_e( 'Current displayed price', 'persiano-hub' ); ?></span><strong data-summary="current-price"><?php echo $current_price > 0 ? wp_kses_post( self::money( $current_price ) ) : '—'; ?></strong></div>
            <div><span><?php esc_html_e( 'Suggested before tax', 'persiano-hub' ); ?></span><strong data-summary="suggested-net"><?php echo wp_kses_post( self::money( $summary['net_suggested_price'] ) ); ?></strong></div>
            <div><span><?php esc_html_e( 'Tax included in final price', 'persiano-hub' ); ?></span><strong data-summary="tax"><?php echo wp_kses_post( self::money( $summary['tax_amount'] ) ); ?></strong></div>
            <div class="ph-cost-summary__highlight"><span><?php esc_html_e( 'Suggested final displayed price', 'persiano-hub' ); ?></span><strong data-summary="suggested"><?php echo wp_kses_post( self::money( $summary['suggested_price'] ) ); ?></strong></div>
            <div><span><?php esc_html_e( 'Estimated gross margin', 'persiano-hub' ); ?></span><strong data-summary="margin"><?php echo null !== $margin ? esc_html( number_format_i18n( $margin, 1 ) . '%' ) : '—'; ?></strong></div>
            <?php if ( null !== $variance ) : ?><div><span><?php esc_html_e( 'Actual vs theoretical variance', 'persiano-hub' ); ?></span><strong><?php echo esc_html( number_format_i18n( $variance, 1 ) . '%' ); ?></strong></div><?php endif; ?>
        </div>

        <p class="description"><strong><?php esc_html_e( 'Formula:', 'persiano-hub' ); ?></strong> <?php esc_html_e( 'batch cost ÷ normalized batch output × product package quantity. Target food-cost percentage, processing fee and tax are applied only after the package cost is known.', 'persiano-hub' ); ?></p>

        <?php if ( ! empty( $summary['warnings'] ) ) : ?>
            <div class="notice notice-warning inline ph-costing-warning"><p><strong><?php esc_html_e( 'Costing needs attention:', 'persiano-hub' ); ?></strong></p><ul>
                <?php foreach ( $summary['warnings'] as $warning ) : ?><li><?php echo esc_html( $warning ); ?></li><?php endforeach; ?>
            </ul></div>
        <?php endif; ?>

        <?php if ( $product && $summary['suggested_price'] > 0 && ! empty( $summary['can_apply_price'] ) ) : ?>
            <?php
            $url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'    => 'persiano_hub_apply_suggested_price',
                        'recipe_id' => $recipe_id,
                    ),
                    admin_url( 'admin-post.php' )
                ),
                'persiano_hub_apply_suggested_price_' . $recipe_id
            );
            ?>
            <p class="ph-apply-price-row"><a class="button button-primary" href="<?php echo esc_url( $url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Replace the linked product regular price with this suggested price?', 'persiano-hub' ) ); ?>');"><?php esc_html_e( 'Apply suggested price to product', 'persiano-hub' ); ?></a> <span><?php esc_html_e( 'The linked product package size was recognized, so this price is safe to apply.', 'persiano-hub' ); ?></span></p>
        <?php elseif ( $product && $summary['suggested_price'] > 0 ) : ?>
            <p class="ph-apply-price-row"><span><?php esc_html_e( 'Complete the linked product Size / package field before applying a suggested price.', 'persiano-hub' ); ?></span></p>
        <?php endif; ?>
        <?php
    }

    public static function save_recipe( $post_id, $post, $update ) {
        if ( ! self::can_save_post( $post_id, 'persiano_hub_recipe_nonce', 'persiano_hub_save_recipe' ) ) {
            return;
        }

        $old_product_id = absint( get_post_meta( $post_id, self::RECIPE_PRODUCT_ID, true ) );
        $product_id     = isset( $_POST['persiano_recipe_product_id'] ) ? absint( $_POST['persiano_recipe_product_id'] ) : 0;
        $yield_qty      = isset( $_POST['persiano_recipe_yield_qty'] ) ? max( 0.0001, self::decimal( wp_unslash( $_POST['persiano_recipe_yield_qty'] ), 1 ) ) : 1;
        $yield_label    = isset( $_POST['persiano_recipe_yield_label'] ) ? self::canonical_recipe_unit( wp_unslash( $_POST['persiano_recipe_yield_label'] ) ) : 'each';
        $packaging      = isset( $_POST['persiano_recipe_packaging_cost'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_packaging_cost'] ) ) ) : 0;
        $labour_minutes = isset( $_POST['persiano_recipe_labour_minutes'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_labour_minutes'] ) ) ) : 0;
        $labour_rate    = isset( $_POST['persiano_recipe_labour_rate'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_labour_rate'] ) ) ) : 0;
        $other_cost     = isset( $_POST['persiano_recipe_other_cost'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_other_cost'] ) ) ) : 0;
        $misc_cost      = isset( $_POST['persiano_recipe_misc_cost'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_misc_cost'] ) ) ) : 0;
        $contingency_pct= isset( $_POST['persiano_recipe_contingency_pct'] ) ? min( 99, max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_contingency_pct'] ) ) ) ) : 0;
        $processing_fee_pct = isset( $_POST['persiano_recipe_processing_fee_pct'] ) ? min( 50, max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_processing_fee_pct'] ) ) ) ) : 0;
        $overhead_pct   = isset( $_POST['persiano_recipe_overhead_pct'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_overhead_pct'] ) ) ) : 0;
        $target_pct     = isset( $_POST['persiano_recipe_target_food_cost_pct'] ) ? min( 99, max( 1, self::decimal( wp_unslash( $_POST['persiano_recipe_target_food_cost_pct'] ), 35 ) ) ) : 35;

        $items = array();
        if ( isset( $_POST['persiano_recipe_items'] ) && is_array( $_POST['persiano_recipe_items'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            foreach ( wp_unslash( $_POST['persiano_recipe_items'] ) as $item ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( ! is_array( $item ) ) { continue; }
                $source_raw = isset( $item['source'] ) ? sanitize_text_field( $item['source'] ) : '';
                $source_type = 'ingredient';
                $source_id = 0;
                if ( false !== strpos( $source_raw, ':' ) ) {
                    list( $source_type, $source_id_raw ) = array_pad( explode( ':', $source_raw, 2 ), 2, '' );
                    $source_type = 'recipe' === sanitize_key( $source_type ) ? 'recipe' : 'ingredient';
                    $source_id = absint( $source_id_raw );
                } elseif ( isset( $item['ingredient_id'] ) ) {
                    $source_id = absint( $item['ingredient_id'] );
                }
                $qty = isset( $item['qty'] ) ? max( 0, self::decimal( $item['qty'] ) ) : 0;
                $unit = isset( $item['unit'] ) ? sanitize_key( $item['unit'] ) : 'g';
                $prep_note = isset( $item['prep_note'] ) ? sanitize_text_field( $item['prep_note'] ) : '';
                $rounding = isset( $item['rounding'] ) ? sanitize_key( $item['rounding'] ) : 'exact';
                $valid_rounding = array( 'exact', 'whole_up', 'half_up', '5', '10', '25', '50', '100' );
                if ( ! in_array( $rounding, $valid_rounding, true ) ) { $rounding = 'exact'; }
                $valid_unit = array_key_exists( $unit, self::unit_options() ) || in_array( $unit, array( 'serving', 'batch' ), true );
                if ( ! $source_id || $qty <= 0 || ! $valid_unit ) { continue; }
                if ( 'recipe' === $source_type && $source_id === $post_id ) { continue; }
                $items[] = array(
                    'source_type'  => $source_type,
                    'source_id'    => $source_id,
                    'ingredient_id'=> 'ingredient' === $source_type ? $source_id : 0,
                    'qty'          => $qty,
                    'unit'         => $unit,
                    'prep_note'    => $prep_note,
                    'rounding'     => $rounding,
                );
            }
        }

        update_post_meta( $post_id, self::RECIPE_PRODUCT_ID, $product_id );
        update_post_meta( $post_id, self::RECIPE_YIELD_QTY, $yield_qty );
        update_post_meta( $post_id, self::RECIPE_YIELD_LABEL, $yield_label );
        update_post_meta( $post_id, self::RECIPE_ITEMS, $items );
        update_post_meta( $post_id, self::RECIPE_PACKAGING_COST, $packaging );
        update_post_meta( $post_id, self::RECIPE_LABOUR_MINUTES, $labour_minutes );
        update_post_meta( $post_id, self::RECIPE_LABOUR_RATE, $labour_rate );
        update_post_meta( $post_id, self::RECIPE_OTHER_COST, $other_cost );
        update_post_meta( $post_id, self::RECIPE_MISC_COST, $misc_cost );
        update_post_meta( $post_id, self::RECIPE_CONTINGENCY_PCT, $contingency_pct );
        update_post_meta( $post_id, self::RECIPE_PROCESSING_FEE_PCT, $processing_fee_pct );
        update_post_meta( $post_id, self::RECIPE_OVERHEAD_PCT, $overhead_pct );
        update_post_meta( $post_id, self::RECIPE_TARGET_COST_PCT, $target_pct );
        if ( class_exists( 'Persiano_Hub_Structural_Data' ) ) { Persiano_Hub_Structural_Data::save_recipe_fields( $post_id ); }

        self::sync_recipe_product_link( $post_id, $product_id, $old_product_id );
        self::recalculate_recipe( $post_id );
    }


    /**
     * Create or update an ingredient from a reviewed import record.
     *
     * @param int                 $ingredient_id Existing ingredient ID or 0.
     * @param array<string,mixed> $data          Ingredient data.
     * @param string              $source        History source label.
     * @return int|WP_Error
     */
    public static function save_imported_ingredient( $ingredient_id, $data, $source = 'import' ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new WP_Error( 'persiano_costing_permission', __( 'You do not have permission to manage ingredients.', 'persiano-hub' ) );
        }

        $name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
        if ( ! $name ) {
            return new WP_Error( 'persiano_costing_missing_name', __( 'Ingredient name is required.', 'persiano-hub' ) );
        }

        if ( $ingredient_id ) {
            $post = get_post( $ingredient_id );
            if ( ! $post || self::INGREDIENT_POST_TYPE !== $post->post_type ) {
                return new WP_Error( 'persiano_costing_invalid_ingredient', __( 'The selected ingredient could not be found.', 'persiano-hub' ) );
            }
            wp_update_post( array( 'ID' => $ingredient_id, 'post_title' => $name ) );
        } else {
            $ingredient_id = wp_insert_post(
                array(
                    'post_type'   => self::INGREDIENT_POST_TYPE,
                    'post_status' => 'publish',
                    'post_title'  => $name,
                ),
                true
            );
            if ( is_wp_error( $ingredient_id ) ) {
                return $ingredient_id;
            }
        }

        $purchase_qty  = isset( $data['purchase_qty'] ) ? max( 0, self::decimal( $data['purchase_qty'] ) ) : 0;
        $purchase_unit = isset( $data['purchase_unit'] ) ? sanitize_key( $data['purchase_unit'] ) : 'each';
        $purchase_cost = isset( $data['purchase_cost'] ) ? max( 0, self::decimal( $data['purchase_cost'] ) ) : 0;
        $purchase_tax  = isset( $data['purchase_tax'] ) ? max( 0, self::decimal( $data['purchase_tax'] ) ) : 0;
        $waste_pct     = isset( $data['waste_pct'] ) ? min( 99, max( 0, self::decimal( $data['waste_pct'] ) ) ) : 0;
        $category      = isset( $data['category'] ) ? sanitize_text_field( $data['category'] ) : '';
        $brand         = isset( $data['brand'] ) ? sanitize_text_field( $data['brand'] ) : '';
        $supplier      = isset( $data['supplier'] ) ? sanitize_text_field( $data['supplier'] ) : '';
        $notes         = isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '';

        if ( ! array_key_exists( $purchase_unit, self::unit_options() ) ) {
            $purchase_unit = 'each';
        }

        $base_unit = self::base_unit( $purchase_unit );
        $base_qty  = $purchase_qty * self::unit_multiplier( $purchase_unit );
        $usable     = $base_qty * ( 1 - ( $waste_pct / 100 ) );
        $gross_cost = $purchase_cost + $purchase_tax;
        $unit_cost  = $usable > 0 ? $gross_cost / $usable : 0;

        update_post_meta( $ingredient_id, self::ING_CATEGORY, $category );
        update_post_meta( $ingredient_id, self::ING_BRAND, $brand );
        update_post_meta( $ingredient_id, self::ING_PURCHASE_QTY, $purchase_qty );
        update_post_meta( $ingredient_id, self::ING_PURCHASE_UNIT, $purchase_unit );
        update_post_meta( $ingredient_id, self::ING_PURCHASE_COST, $purchase_cost );
        update_post_meta( $ingredient_id, self::ING_PURCHASE_TAX, $purchase_tax );
        update_post_meta( $ingredient_id, self::ING_GROSS_COST, $gross_cost );
        update_post_meta( $ingredient_id, self::ING_WASTE_PCT, $waste_pct );
        update_post_meta( $ingredient_id, self::ING_SUPPLIER, $supplier );
        update_post_meta( $ingredient_id, self::ING_NOTES, $notes );
        update_post_meta( $ingredient_id, self::ING_UNIT_COST, $unit_cost );
        update_post_meta( $ingredient_id, self::ING_BASE_UNIT, $base_unit );

        $history   = get_post_meta( $ingredient_id, self::ING_HISTORY, true );
        $history   = is_array( $history ) ? $history : array();
        $history[] = array(
            'time'          => time(),
            'purchase_qty'  => $purchase_qty,
            'purchase_unit' => $purchase_unit,
            'purchase_cost' => $purchase_cost,
            'purchase_tax'  => $purchase_tax,
            'gross_cost'    => $gross_cost,
            'waste_pct'     => $waste_pct,
            'unit_cost'     => $unit_cost,
            'base_unit'     => $base_unit,
            'brand'         => $brand,
            'supplier'      => $supplier,
            'source'        => sanitize_key( $source ),
        );
        if ( count( $history ) > 50 ) {
            $history = array_slice( $history, -50 );
        }
        update_post_meta( $ingredient_id, self::ING_HISTORY, $history );

        if ( class_exists( 'Persiano_Hub_Ingredient_Master' ) ) {
            Persiano_Hub_Ingredient_Master::ensure_canonical_id( $ingredient_id );
            if ( ! empty( $data['aliases'] ) ) {
                $aliases = is_array( $data['aliases'] ) ? $data['aliases'] : preg_split( '/[,;\r\n]+/', (string) $data['aliases'] );
                Persiano_Hub_Ingredient_Master::set_aliases( $ingredient_id, $aliases );
            }
            if ( ! empty( $data['needs_review'] ) ) {
                update_post_meta( $ingredient_id, Persiano_Hub_Ingredient_Master::META_REVIEW, 1 );
            }
        }
        self::recalculate_all_recipes();
        return (int) $ingredient_id;
    }

    /** Find an existing ingredient with the same normalized name. */
    public static function find_matching_ingredient( $name ) {
        if ( class_exists( 'Persiano_Hub_Ingredient_Master' ) ) {
            return Persiano_Hub_Ingredient_Master::find_matching_ingredient( $name );
        }
        $needle = strtolower( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $name ) ) ) );
        if ( ! $needle ) {
            return 0;
        }
        $ids = get_posts(
            array(
                'post_type'      => self::INGREDIENT_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
        foreach ( $ids as $id ) {
            if ( class_exists( 'Persiano_Hub_Operations' ) && ( get_post_meta( $id, Persiano_Hub_Operations::ING_ARCHIVED, true ) || get_post_meta( $id, Persiano_Hub_Operations::ING_AI_EXCLUDE, true ) ) ) {
                continue;
            }
            $title = strtolower( trim( preg_replace( '/\s+/', ' ', get_the_title( $id ) ) ) );
            if ( $title === $needle ) {
                return (int) $id;
            }
        }
        return 0;
    }

    private static function can_save_post( $post_id, $nonce_field, $nonce_action ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return false;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return false;
        }
        if ( ! isset( $_POST[ $nonce_field ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $nonce_action ) ) {
            return false;
        }
        return current_user_can( 'manage_woocommerce' );
    }

    private static function sync_recipe_product_link( $recipe_id, $product_id, $old_product_id = 0 ) {
        if ( $old_product_id && $old_product_id !== $product_id ) {
            $old_link = absint( get_post_meta( $old_product_id, self::PRODUCT_RECIPE_META, true ) );
            if ( $old_link === $recipe_id ) {
                delete_post_meta( $old_product_id, self::PRODUCT_RECIPE_META );
            }
        }

        if ( $product_id ) {
            update_post_meta( $product_id, self::PRODUCT_RECIPE_META, $recipe_id );
        }
    }

    public static function calculate_recipe( $recipe_id, $stack = array() ) {
        if ( in_array( (int) $recipe_id, array_map( 'intval', (array) $stack ), true ) ) {
            return array(
                'ingredient_cost'       => 0,
                'labour_cost'           => 0,
                'misc_cost'             => 0,
                'overhead_cost'         => 0,
                'contingency_cost'      => 0,
                'batch_cost'            => 0,
                'effective_batch_cost'  => 0,
                'yield_qty'             => 1,
                'effective_yield_qty'   => 1,
                'yield_unit'            => 'each',
                'base_unit'             => 'each',
                'cost_per_base_unit'    => 0,
                'product_cost'          => 0,
                'cost_per_serving'      => 0,
                'pricing_qty'           => 1,
                'pricing_unit'          => 'each',
                'pricing_label'         => __( 'One sellable unit', 'persiano-hub' ),
                'pricing_source'        => 'error',
                'can_apply_price'       => false,
                'cost_source'           => 'theoretical',
                'net_suggested_price'   => 0,
                'tax_amount'            => 0,
                'processing_fee_cost'   => 0,
                'suggested_price'       => 0,
                'warnings'              => array( __( 'Circular sub-recipe reference detected.', 'persiano-hub' ) ),
            );
        }

        $stack[] = (int) $recipe_id;
        $items           = self::get_recipe_items( $recipe_id );
        $yield           = self::recipe_yield_details( $recipe_id );
        $yield_qty       = $yield['qty'];
        $yield_unit      = $yield['unit'];
        $base_unit       = $yield['base_unit'];
        $packaging_cost  = max( 0, self::decimal( get_post_meta( $recipe_id, self::RECIPE_PACKAGING_COST, true ) ) );
        $labour_minutes  = max( 0, self::decimal( get_post_meta( $recipe_id, self::RECIPE_LABOUR_MINUTES, true ) ) );
        $labour_rate     = max( 0, self::decimal( get_post_meta( $recipe_id, self::RECIPE_LABOUR_RATE, true ) ) );
        $other_cost      = max( 0, self::decimal( get_post_meta( $recipe_id, self::RECIPE_OTHER_COST, true ) ) );
        $misc_cost       = max( 0, self::decimal( get_post_meta( $recipe_id, self::RECIPE_MISC_COST, true ) ) );
        $contingency_pct = min( 99, max( 0, self::decimal( get_post_meta( $recipe_id, self::RECIPE_CONTINGENCY_PCT, true ) ) ) );
        $processing_fee_pct = min( 50, max( 0, self::decimal( get_post_meta( $recipe_id, self::RECIPE_PROCESSING_FEE_PCT, true ) ) ) );
        $overhead_pct    = max( 0, self::decimal( get_post_meta( $recipe_id, self::RECIPE_OVERHEAD_PCT, true ) ) );
        $target_cost_pct = min( 99, max( 1, self::decimal( get_post_meta( $recipe_id, self::RECIPE_TARGET_COST_PCT, true ), 35 ) ) );
        $product_id      = absint( get_post_meta( $recipe_id, self::RECIPE_PRODUCT_ID, true ) );

        $ingredient_cost = 0;
        $warnings        = array();

        foreach ( $items as $item ) {
            $source_type = isset( $item['source_type'] ) ? sanitize_key( $item['source_type'] ) : 'ingredient';
            $source_id   = isset( $item['source_id'] ) ? absint( $item['source_id'] ) : ( isset( $item['ingredient_id'] ) ? absint( $item['ingredient_id'] ) : 0 );
            $qty         = isset( $item['qty'] ) ? self::decimal( $item['qty'] ) : 0;
            $unit        = isset( $item['unit'] ) ? sanitize_key( $item['unit'] ) : '';
            if ( ! $source_id || $qty <= 0 ) {
                continue;
            }

            if ( 'recipe' === $source_type ) {
                if ( in_array( $source_id, $stack, true ) ) {
                    $warnings[] = sprintf( __( 'Circular sub-recipe reference involving %s was ignored.', 'persiano-hub' ), get_the_title( $source_id ) );
                    continue;
                }

                $sub = self::calculate_recipe( $source_id, $stack );
                if ( 'batch' === $unit ) {
                    $ingredient_cost += $qty * (float) $sub['effective_batch_cost'];
                } elseif ( 'serving' === $unit ) {
                    /* Backward compatibility for rows saved by the old workflow. */
                    $ingredient_cost += $qty * (float) $sub['product_cost'];
                } else {
                    $normalized = self::normalize_amount( $qty, $unit, $sub['base_unit'] );
                    if ( null === $normalized ) {
                        $warnings[] = sprintf(
                            __( 'Sub-recipe %1$s is measured in %2$s and cannot be used as %3$s.', 'persiano-hub' ),
                            get_the_title( $source_id ),
                            $sub['yield_unit'],
                            $unit
                        );
                    } else {
                        $ingredient_cost += $normalized * (float) $sub['cost_per_base_unit'];
                    }
                }

                foreach ( (array) $sub['warnings'] as $warning ) {
                    $warnings[] = $warning;
                }
                continue;
            }

            $ingredient = get_post( $source_id );
            if ( ! $ingredient || self::INGREDIENT_POST_TYPE !== $ingredient->post_type ) {
                continue;
            }

            $unit_cost  = (float) get_post_meta( $source_id, self::ING_UNIT_COST, true );
            $ingredient_base_unit = get_post_meta( $source_id, self::ING_BASE_UNIT, true );
            $normalized = self::normalize_amount( $qty, $unit, $ingredient_base_unit );
            if ( null === $normalized ) {
                $warnings[] = sprintf(
                    __( '%1$s uses %2$s, which cannot be converted to the ingredient cost unit (%3$s).', 'persiano-hub' ),
                    $ingredient->post_title,
                    $unit,
                    $ingredient_base_unit ? $ingredient_base_unit : __( 'unknown', 'persiano-hub' )
                );
                continue;
            }
            if ( $unit_cost <= 0 ) {
                $warnings[] = sprintf( __( '%s does not have a usable purchase cost yet.', 'persiano-hub' ), $ingredient->post_title );
                continue;
            }
            $ingredient_cost += $normalized * $unit_cost;
        }

        $labour_cost = ( $labour_minutes / 60 ) * $labour_rate;
        $known_cost  = $ingredient_cost + $packaging_cost + $labour_cost + $other_cost + $misc_cost;
        $overhead    = $known_cost * ( $overhead_pct / 100 );
        $subtotal    = $known_cost + $overhead;
        $contingency = $subtotal * ( $contingency_pct / 100 );
        $batch_cost  = $subtotal + $contingency;

        $actual_cost  = class_exists( 'Persiano_Hub_Kitchen' ) ? max( 0, self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Kitchen::RECIPE_ACTUAL_COST, true ) ) ) : 0;
        $actual_yield = class_exists( 'Persiano_Hub_Kitchen' ) ? max( 0, self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Kitchen::RECIPE_ACTUAL_YIELD, true ) ) ) : 0;

        $effective_batch_cost = $batch_cost;
        $effective_yield_qty  = $yield_qty;
        $cost_source          = 'theoretical';
        if ( $actual_cost > 0 && $actual_yield > 0 ) {
            $effective_batch_cost = $actual_cost;
            $effective_yield_qty  = $actual_yield;
            $cost_source          = 'actual';
        } elseif ( ( $actual_cost > 0 && $actual_yield <= 0 ) || ( $actual_yield > 0 && $actual_cost <= 0 ) ) {
            $warnings[] = __( 'Actual production costing is incomplete. Enter both Actual batch cost and Actual yield, or leave both at zero.', 'persiano-hub' );
        }

        $effective_normalized_yield = max( 0.0001, $effective_yield_qty * self::unit_multiplier( $yield_unit ) );
        $cost_per_base_unit = $effective_batch_cost / $effective_normalized_yield;

        $basis = self::product_pricing_basis( $product_id, $yield_unit, $effective_yield_qty );
        foreach ( (array) $basis['warnings'] as $warning ) {
            $warnings[] = $warning;
        }

        $product_cost = $cost_per_base_unit * max( 0, (float) $basis['pricing_normalized'] );
        $pre_tax_target = $product_cost / ( $target_cost_pct / 100 );
        $pre_tax_with_fees = $processing_fee_pct > 0
            ? $pre_tax_target / max( 0.01, ( 1 - ( $processing_fee_pct / 100 ) ) )
            : $pre_tax_target;
        $processing_fee_cost = max( 0, $pre_tax_with_fees - $pre_tax_target );
        $gross_target = self::add_base_tax_to_price( $pre_tax_with_fees, $product_id );
        $suggested = self::round_price( $gross_target, 0.50 );
        $tax_factor = self::add_base_tax_to_price( 1, $product_id );
        $net_suggested = $tax_factor > 0 ? $suggested / $tax_factor : $suggested;
        $tax_amount = max( 0, $suggested - $net_suggested );

        return array(
            'ingredient_cost'       => $ingredient_cost,
            'labour_cost'           => $labour_cost,
            'misc_cost'             => $misc_cost,
            'overhead_cost'         => $overhead,
            'contingency_cost'      => $contingency,
            'batch_cost'            => $batch_cost,
            'effective_batch_cost'  => $effective_batch_cost,
            'yield_qty'             => $yield_qty,
            'effective_yield_qty'   => $effective_yield_qty,
            'yield_unit'            => $yield_unit,
            'base_unit'             => $base_unit,
            'cost_per_base_unit'    => $cost_per_base_unit,
            'product_cost'          => $product_cost,
            /* Kept for compatibility with exports and older integrations. */
            'cost_per_serving'      => $product_cost,
            'pricing_qty'           => $basis['pricing_qty'],
            'pricing_unit'          => $basis['pricing_unit'],
            'pricing_label'         => $basis['pricing_label'],
            'pricing_source'        => $basis['pricing_source'],
            'can_apply_price'       => (bool) $basis['can_apply_price'],
            'cost_source'           => $cost_source,
            'net_suggested_price'   => $net_suggested,
            'tax_amount'            => $tax_amount,
            'processing_fee_cost'   => $processing_fee_cost,
            'suggested_price'       => $suggested,
            'warnings'              => array_values( array_unique( $warnings ) ),
        );
    }

    /** Public compatibility entry point used by price-review workflows. */
    public static function calculate_recipe_summary( $recipe_id ) {
        return self::calculate_recipe( $recipe_id );
    }

    private static function add_base_tax_to_price( $net_price, $product_id = 0 ) {
        if ( ! class_exists( 'WC_Tax' ) || $net_price <= 0 ) {
            return $net_price;
        }

        $tax_class = '';
        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( $product && $product->is_taxable() ) {
                $tax_class = $product->get_tax_class();
            } elseif ( $product && ! $product->is_taxable() ) {
                return $net_price;
            }
        }

        if ( method_exists( 'WC_Tax', 'get_base_tax_rates' ) ) {
            $rates = WC_Tax::get_base_tax_rates( $tax_class );
        } else {
            $rates = WC_Tax::get_rates( $tax_class );
        }

        if ( empty( $rates ) ) {
            return $net_price;
        }

        $taxes = WC_Tax::calc_tax( $net_price, $rates, false );
        return $net_price + array_sum( $taxes );
    }

    private static function round_price( $price, $increment = 0.5 ) {
        $increment = max( 0.01, (float) $increment );
        return round( $price / $increment ) * $increment;
    }

    public static function recalculate_recipe( $recipe_id ) {
        if ( self::RECIPE_POST_TYPE !== get_post_type( $recipe_id ) ) {
            return array();
        }

        $summary = self::calculate_recipe( $recipe_id );
        update_post_meta( $recipe_id, self::RECIPE_INGREDIENT_COST, $summary['ingredient_cost'] );
        update_post_meta( $recipe_id, self::RECIPE_LABOUR_COST, $summary['labour_cost'] );
        update_post_meta( $recipe_id, self::RECIPE_OVERHEAD_COST, $summary['overhead_cost'] );
        update_post_meta( $recipe_id, self::RECIPE_CONTINGENCY_COST, $summary['contingency_cost'] );
        update_post_meta( $recipe_id, self::RECIPE_PROCESSING_FEE_COST, $summary['processing_fee_cost'] );
        update_post_meta( $recipe_id, self::RECIPE_BATCH_COST, $summary['batch_cost'] );
        update_post_meta( $recipe_id, self::RECIPE_COST_PER_SERVING, $summary['product_cost'] );
        update_post_meta( $recipe_id, self::RECIPE_COST_PER_BASE_UNIT, $summary['cost_per_base_unit'] );
        update_post_meta( $recipe_id, self::RECIPE_PRODUCT_COST, $summary['product_cost'] );
        update_post_meta( $recipe_id, self::RECIPE_PRICING_LABEL, $summary['pricing_label'] );
        update_post_meta( $recipe_id, self::RECIPE_NET_SUGGESTED_PRICE, $summary['net_suggested_price'] );
        update_post_meta( $recipe_id, self::RECIPE_TAX_AMOUNT, $summary['tax_amount'] );
        update_post_meta( $recipe_id, self::RECIPE_SUGGESTED_PRICE, $summary['suggested_price'] );
        update_post_meta( $recipe_id, self::RECIPE_WARNINGS, $summary['warnings'] );
        return $summary;
    }

    private static function recalculate_all_recipes() {
        $recipe_ids = get_posts(
            array(
                'post_type'      => self::RECIPE_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
        foreach ( $recipe_ids as $recipe_id ) {
            self::recalculate_recipe( $recipe_id );
        }
    }

    public static function product_costing_meta_box() {
        add_meta_box(
            'persiano_hub_product_costing',
            __( 'Persiano Costing', 'persiano-hub' ),
            array( __CLASS__, 'render_product_costing_meta_box' ),
            'product',
            'side',
            'default'
        );
    }

    public static function render_product_costing_meta_box( $post ) {
        wp_nonce_field( 'persiano_hub_save_product_recipe', 'persiano_hub_product_recipe_nonce' );
        $recipe_id = absint( get_post_meta( $post->ID, self::PRODUCT_RECIPE_META, true ) );
        $recipes   = get_posts(
            array(
                'post_type'      => self::RECIPE_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
        ?>
        <p><label for="persiano_hub_product_recipe_id"><strong><?php esc_html_e( 'Linked recipe', 'persiano-hub' ); ?></strong></label></p>
        <select class="widefat" id="persiano_hub_product_recipe_id" name="persiano_hub_product_recipe_id">
            <option value="0"><?php esc_html_e( '— None —', 'persiano-hub' ); ?></option>
            <?php foreach ( $recipes as $recipe ) : ?>
                <option value="<?php echo esc_attr( $recipe->ID ); ?>" <?php selected( $recipe_id, $recipe->ID ); ?>><?php echo esc_html( $recipe->post_title ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
        if ( $recipe_id ) {
            $summary = self::calculate_recipe( $recipe_id );
            echo '<div class="ph-product-costing-mini">';
            echo '<p><span>' . esc_html__( 'Product/package cost', 'persiano-hub' ) . '</span><strong>' . wp_kses_post( self::money( $summary['product_cost'] ) ) . '</strong></p>';
            echo '<p><span>' . esc_html__( 'Pricing basis', 'persiano-hub' ) . '</span><strong>' . esc_html( $summary['pricing_label'] ) . '</strong></p>';
            echo '<p><span>' . esc_html__( 'Suggested', 'persiano-hub' ) . '</span><strong>' . wp_kses_post( self::money( $summary['suggested_price'] ) ) . '</strong></p>';
            echo '<p><a href="' . esc_url( get_edit_post_link( $recipe_id ) ) . '">' . esc_html__( 'Open recipe & costing →', 'persiano-hub' ) . '</a></p>';
            echo '</div>';
        }
    }

    public static function save_product_recipe_link( $product ) {
        if ( ! isset( $_POST['persiano_hub_product_recipe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_hub_product_recipe_nonce'] ) ), 'persiano_hub_save_product_recipe' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $product_id    = $product->get_id();
        $old_recipe_id = absint( get_post_meta( $product_id, self::PRODUCT_RECIPE_META, true ) );
        $recipe_id     = isset( $_POST['persiano_hub_product_recipe_id'] ) ? absint( $_POST['persiano_hub_product_recipe_id'] ) : 0;

        if ( $old_recipe_id && $old_recipe_id !== $recipe_id ) {
            $linked_product = absint( get_post_meta( $old_recipe_id, self::RECIPE_PRODUCT_ID, true ) );
            if ( $linked_product === $product_id ) {
                delete_post_meta( $old_recipe_id, self::RECIPE_PRODUCT_ID );
            }
        }

        if ( $recipe_id ) {
            update_post_meta( $product_id, self::PRODUCT_RECIPE_META, $recipe_id );
            update_post_meta( $recipe_id, self::RECIPE_PRODUCT_ID, $product_id );
            self::recalculate_recipe( $recipe_id );
        } else {
            delete_post_meta( $product_id, self::PRODUCT_RECIPE_META );
        }
    }

    public static function apply_suggested_price() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to change product prices.', 'persiano-hub' ) );
        }

        $recipe_id = isset( $_GET['recipe_id'] ) ? absint( $_GET['recipe_id'] ) : 0;
        check_admin_referer( 'persiano_hub_apply_suggested_price_' . $recipe_id );

        $summary    = self::recalculate_recipe( $recipe_id );
        $product_id = absint( get_post_meta( $recipe_id, self::RECIPE_PRODUCT_ID, true ) );
        $product    = $product_id ? wc_get_product( $product_id ) : false;

        if ( $product && ! empty( $summary['suggested_price'] ) && ! empty( $summary['can_apply_price'] ) ) {
            $product->set_regular_price( wc_format_decimal( $summary['suggested_price'] ) );
            $product->set_sale_price( '' );
            $product->save();
            $message = 'price-updated';
        } else {
            $message = 'price-failed';
        }

        wp_safe_redirect(
            add_query_arg(
                'persiano_costing_notice',
                $message,
                get_edit_post_link( $recipe_id, 'url' )
            )
        );
        exit;
    }

    public static function ingredient_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['persiano_brand'] = __( 'Brand', 'persiano-hub' );
                $new['persiano_purchase'] = __( 'Purchase', 'persiano-hub' );
                $new['persiano_unit_cost'] = __( 'Usable unit cost', 'persiano-hub' );
                $new['persiano_supplier'] = __( 'Supplier', 'persiano-hub' );
            }
        }
        return $new;
    }

    public static function ingredient_column_content( $column, $post_id ) {
        if ( 'persiano_brand' === $column ) {
            $brand = get_post_meta( $post_id, self::ING_BRAND, true );
            echo $brand ? esc_html( $brand ) : '—';
        } elseif ( 'persiano_purchase' === $column ) {
            echo esc_html( get_post_meta( $post_id, self::ING_PURCHASE_QTY, true ) . ' ' . get_post_meta( $post_id, self::ING_PURCHASE_UNIT, true ) );
            $gross = (float) get_post_meta( $post_id, self::ING_GROSS_COST, true );
            if ( ! $gross ) { $gross = (float) get_post_meta( $post_id, self::ING_PURCHASE_COST, true ) + (float) get_post_meta( $post_id, self::ING_PURCHASE_TAX, true ); }
            echo '<br><strong>' . wp_kses_post( self::money( $gross ) ) . '</strong>';
        } elseif ( 'persiano_unit_cost' === $column ) {
            $cost = (float) get_post_meta( $post_id, self::ING_UNIT_COST, true );
            $unit = get_post_meta( $post_id, self::ING_BASE_UNIT, true );
            echo $cost > 0 ? wp_kses_post( self::money( $cost ) ) . ' / ' . esc_html( $unit ) : '—';
        } elseif ( 'persiano_supplier' === $column ) {
            $supplier = get_post_meta( $post_id, self::ING_SUPPLIER, true );
            echo $supplier ? esc_html( $supplier ) : '—';
        }
    }

    public static function recipe_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['persiano_product']   = __( 'Product', 'persiano-hub' );
                $new['persiano_cost']      = __( 'Product/package cost', 'persiano-hub' );
                $new['persiano_price']     = __( 'Current price', 'persiano-hub' );
                $new['persiano_suggested'] = __( 'Suggested', 'persiano-hub' );
                $new['persiano_margin']    = __( 'Margin', 'persiano-hub' );
            }
        }
        return $new;
    }

    public static function recipe_column_content( $column, $post_id ) {
        $summary    = self::calculate_recipe( $post_id );
        $product_id = absint( get_post_meta( $post_id, self::RECIPE_PRODUCT_ID, true ) );
        $product    = $product_id ? wc_get_product( $product_id ) : false;
        $price      = $product ? self::decimal( $product->get_price() ) : 0;
        $net_price  = $product && $price > 0 && function_exists( 'wc_get_price_excluding_tax' ) ? (float) wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $price ) ) : $price;
        $margin     = $net_price > 0 ? ( ( $net_price - $summary['product_cost'] ) / $net_price ) * 100 : null;

        if ( 'persiano_product' === $column ) {
            echo $product ? '<a href="' . esc_url( get_edit_post_link( $product_id ) ) . '">' . esc_html( $product->get_name() ) . '</a>' : '—';
        } elseif ( 'persiano_cost' === $column ) {
            echo wp_kses_post( self::money( $summary['product_cost'] ) );
        } elseif ( 'persiano_price' === $column ) {
            echo $price > 0 ? wp_kses_post( self::money( $price ) ) : '—';
        } elseif ( 'persiano_suggested' === $column ) {
            echo wp_kses_post( self::money( $summary['suggested_price'] ) );
        } elseif ( 'persiano_margin' === $column ) {
            echo null !== $margin ? esc_html( number_format_i18n( $margin, 1 ) . '%' ) : '—';
        }
    }

    private static function legacy_pricing_detected() {
        $active = (array) get_option( 'active_plugins', array() );
        foreach ( $active as $plugin ) {
            if ( false !== strpos( $plugin, 'persiano-dish-pricing' ) ) {
                return true;
            }
        }
        return false;
    }

    private static function dashboard_url( $tab = 'overview' ) {
        return add_query_arg(
            array(
                'page' => self::MENU_SLUG,
                'tab'  => $tab,
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function render_costing_nav( $active ) {
        $tabs = array(
            'overview'    => __( 'Overview', 'persiano-hub' ),
            'inventory'   => __( 'Inventory', 'persiano-hub' ),
            'ingredients' => __( 'Ingredients', 'persiano-hub' ),
            'recipes'     => __( 'Recipes & Pricing', 'persiano-hub' ),
            'production'  => __( 'Production Planner', 'persiano-hub' ),
            'purchasing'  => __( 'Shopping & Vendors', 'persiano-hub' ),
            'price_checker' => __( 'Price Checker', 'persiano-hub' ),
            'data'        => __( 'Import / Export', 'persiano-hub' ),
            'scan'        => __( 'AI Scan', 'persiano-hub' ),
            'settings'    => __( 'AI Settings', 'persiano-hub' ),
        );
        echo '<nav class="nav-tab-wrapper ph-costing-tabs">';
        foreach ( $tabs as $slug => $label ) {
            $class = 'nav-tab' . ( $active === $slug ? ' nav-tab-active' : '' );
            echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::dashboard_url( $slug ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    public static function render_dashboard_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $tab, array( 'overview', 'inventory', 'ingredients', 'recipes', 'production', 'purchasing', 'price_checker', 'data', 'scan', 'settings' ), true ) ) {
            $tab = 'overview';
        }

        echo '<div class="wrap ph-costing-dashboard">';
        self::render_costing_nav( $tab );

        if ( 'inventory' === $tab ) {
            if ( class_exists( 'Persiano_Hub_Inventory' ) ) {
                Persiano_Hub_Inventory::render_inventory_page();
            }
            echo '</div>';
            return;
        }
        if ( 'purchasing' === $tab ) {
            if ( class_exists( 'Persiano_Hub_Purchasing' ) ) {
                Persiano_Hub_Operations::render_purchasing_page();
            }
            echo '</div>';
            return;
        }
        if ( 'price_checker' === $tab ) {
            Persiano_Hub_Operations::render_price_checker();
            echo '</div>';
            return;
        }
        if ( 'data' === $tab ) {
            if ( class_exists( 'Persiano_Hub_Data_Tools' ) ) {
                Persiano_Hub_Data_Tools::render_page();
            }
            echo '</div>';
            return;
        }
        if ( 'scan' === $tab ) {
            Persiano_Hub_AI_Cost_Import::render_scan_page();
            echo '</div>';
            return;
        }
        if ( 'settings' === $tab ) {
            Persiano_Hub_AI_Cost_Import::render_settings_page();
            echo '</div>';
            return;
        }
        if ( 'ingredients' === $tab ) {
            self::render_ingredients_tab();
            echo '</div>';
            return;
        }
        if ( 'recipes' === $tab ) {
            self::render_recipes_tab();
            echo '</div>';
            return;
        }
        if ( 'production' === $tab ) {
            if ( class_exists( 'Persiano_Hub_Kitchen' ) ) {
                Persiano_Hub_Operations::render_production_planner();
            }
            echo '</div>';
            return;
        }

        $ingredient_count = wp_count_posts( self::INGREDIENT_POST_TYPE );
        $recipe_count     = wp_count_posts( self::RECIPE_POST_TYPE );
        $ingredients      = isset( $ingredient_count->publish ) ? (int) $ingredient_count->publish : 0;
        $ingredients     += isset( $ingredient_count->draft ) ? (int) $ingredient_count->draft : 0;
        $recipes          = isset( $recipe_count->publish ) ? (int) $recipe_count->publish : 0;
        $recipes         += isset( $recipe_count->draft ) ? (int) $recipe_count->draft : 0;

        $recipe_ids = get_posts(
            array(
                'post_type'      => self::RECIPE_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => 12,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'fields'         => 'ids',
            )
        );

        $linked = 0;
        $all_recipe_ids = get_posts(
            array(
                'post_type'      => self::RECIPE_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );
        foreach ( $all_recipe_ids as $recipe_id ) {
            if ( absint( get_post_meta( $recipe_id, self::RECIPE_PRODUCT_ID, true ) ) ) {
                $linked++;
            }
        }
        ?>
        <div class="ph-costing-hero">
            <div>
                <span class="ph-costing-eyebrow"><?php esc_html_e( 'Batchly', 'persiano-hub' ); ?></span>
                <h1><?php esc_html_e( 'Costing & Recipes', 'persiano-hub' ); ?></h1>
                <p><?php esc_html_e( 'One workspace for ingredient purchases, recipe quantities, real batch costs, margins and WooCommerce pricing.', 'persiano-hub' ); ?></p>
            </div>
            <div class="ph-costing-actions">
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::INGREDIENT_POST_TYPE ) ); ?>"><?php esc_html_e( 'Add ingredient', 'persiano-hub' ); ?></a>
                <a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::RECIPE_POST_TYPE ) ); ?>"><?php esc_html_e( 'Add recipe & pricing', 'persiano-hub' ); ?></a>
                <a class="button" href="<?php echo esc_url( self::dashboard_url( 'inventory' ) ); ?>"><?php esc_html_e( 'Inventory', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( self::dashboard_url( 'production' ) ); ?>"><?php esc_html_e( 'Production planner', 'persiano-hub' ); ?></a><a class="button" href="<?php echo esc_url( self::dashboard_url( 'scan' ) ); ?>"><?php esc_html_e( 'Scan receipt / price tag', 'persiano-hub' ); ?></a>
            </div>
        </div>

        <div class="ph-costing-stats">
            <div><strong><?php echo esc_html( $ingredients ); ?></strong><span><?php esc_html_e( 'Ingredients', 'persiano-hub' ); ?></span></div>
            <div><strong><?php echo esc_html( $recipes ); ?></strong><span><?php esc_html_e( 'Recipes', 'persiano-hub' ); ?></span></div>
            <div><strong><?php echo esc_html( $linked ); ?></strong><span><?php esc_html_e( 'Linked products', 'persiano-hub' ); ?></span></div>
            <div><strong><?php echo esc_html( max( 0, $recipes - $linked ) ); ?></strong><span><?php esc_html_e( 'Need product link', 'persiano-hub' ); ?></span></div>
        </div>

        <section class="ph-costing-panel">
            <div class="ph-costing-heading-row"><div><h2><?php esc_html_e( 'Recent recipe pricing', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'Prices shown are the current WooCommerce customer-facing prices.', 'persiano-hub' ); ?></p></div><a href="<?php echo esc_url( self::dashboard_url( 'recipes' ) ); ?>"><?php esc_html_e( 'View all recipes →', 'persiano-hub' ); ?></a></div>
            <?php self::render_recipe_table( $recipe_ids ); ?>
        </section>

        <section class="ph-costing-panel ph-costing-panel--workflow">
            <h2><?php esc_html_e( 'How the system fits together', 'persiano-hub' ); ?></h2>
            <div class="ph-costing-flow"><span><?php esc_html_e( 'Receipt / price tag', 'persiano-hub' ); ?></span><b>→</b><span><?php esc_html_e( 'Ingredient purchase cost', 'persiano-hub' ); ?></span><b>→</b><span><?php esc_html_e( 'Recipe quantities', 'persiano-hub' ); ?></span><b>→</b><span><?php esc_html_e( 'Cost & margin', 'persiano-hub' ); ?></span><b>→</b><span><?php esc_html_e( 'WooCommerce price', 'persiano-hub' ); ?></span></div>
        </section>
        <?php
        echo '</div>';
    }

    private static function render_ingredients_tab() {
        Persiano_Hub_Operations::render_ingredients_tab();
    }

    private static function render_recipes_tab() {
        $recipe_ids = get_posts(
            array(
                'post_type'      => self::RECIPE_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
            )
        );
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Recipe economics', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Recipes & Pricing', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Build recipes from ingredient costs and compare the actual cost with the current and suggested selling price.', 'persiano-hub' ); ?></p></div><div class="ph-costing-actions"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . self::RECIPE_POST_TYPE ) ); ?>"><?php esc_html_e( 'Add recipe & pricing', 'persiano-hub' ); ?></a></div></div>
        <section class="ph-costing-panel"><?php self::render_recipe_table( $recipe_ids ); ?></section>
        <?php
    }

    private static function render_recipe_table( $recipe_ids ) {
        if ( ! $recipe_ids ) {
            echo '<div class="ph-costing-empty"><p>' . esc_html__( 'No recipe costing records yet. Start by adding your ingredients, then build your first recipe.', 'persiano-hub' ) . '</p></div>';
            return;
        }
        ?>
        <div class="ph-costing-table-wrap"><table class="widefat striped ph-costing-table"><thead><tr><th><?php esc_html_e( 'Recipe', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Product', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Product/package cost', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Current price', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Suggested', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Margin', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php foreach ( $recipe_ids as $recipe_id ) :
            $summary = self::calculate_recipe( $recipe_id );
            $product_id = absint( get_post_meta( $recipe_id, self::RECIPE_PRODUCT_ID, true ) );
            $product = $product_id ? wc_get_product( $product_id ) : false;
            $price = $product ? self::decimal( $product->get_price() ) : 0;
            $net_price = $product && $price > 0 && function_exists( 'wc_get_price_excluding_tax' ) ? (float) wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $price ) ) : $price;
            $margin = $net_price > 0 ? ( ( $net_price - $summary['product_cost'] ) / $net_price ) * 100 : null;
            ?>
            <tr><td><a href="<?php echo esc_url( get_edit_post_link( $recipe_id ) ); ?>"><strong><?php echo esc_html( get_the_title( $recipe_id ) ); ?></strong></a></td><td><?php echo $product ? esc_html( $product->get_name() ) : '—'; ?></td><td><?php echo wp_kses_post( self::money( $summary['product_cost'] ) ); ?></td><td><?php echo $price > 0 ? wp_kses_post( self::money( $price ) ) : '—'; ?></td><td><strong><?php echo wp_kses_post( self::money( $summary['suggested_price'] ) ); ?></strong></td><td><?php echo null !== $margin ? esc_html( number_format_i18n( $margin, 1 ) . '%' ) : '—'; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php
    }

    public static function admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $is_costing = in_array( $screen->post_type, array( self::INGREDIENT_POST_TYPE, self::RECIPE_POST_TYPE, 'product' ), true ) || self::MENU_SLUG === $screen->id || false !== strpos( $screen->id, self::MENU_SLUG );
        if ( ! $is_costing ) {
            return;
        }

        wp_enqueue_script( 'jquery' );
        $currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) : '$';

        $js = <<<'JS'
(function($){
    'use strict';

    var unitMultipliers = {g:1,kg:1000,oz:28.349523125,lb:453.59237,ml:1,l:1000,tsp:5,tbsp:15,cup:250,each:1};
    var unitFamilies = {g:'mass',kg:'mass',oz:'mass',lb:'mass',ml:'volume',l:'volume',tsp:'volume',tbsp:'volume',cup:'volume',each:'count'};
    var baseUnits = {mass:'g',volume:'ml',count:'each'};
    var currency = window.PersianoCostingCurrency || '$';

    function money(value){
        value = Number(value || 0);
        return currency + value.toFixed(2);
    }

    function recalcIngredient(){
        var qty = parseFloat($('#persiano_ing_purchase_qty').val()) || 0;
        var unit = $('#persiano_ing_purchase_unit').val();
        var cost = parseFloat($('#persiano_ing_purchase_cost').val()) || 0;
        var tax = parseFloat($('#persiano_ing_purchase_tax').val()) || 0;
        var gross = cost + tax;
        var waste = parseFloat($('#persiano_ing_waste_pct').val()) || 0;
        var family = unitFamilies[unit] || '';
        var base = baseUnits[family] || '';
        var usable = qty * (unitMultipliers[unit] || 0) * (1 - Math.min(99,Math.max(0,waste))/100);
        var unitCost = usable > 0 ? gross / usable : 0;
        $('#ph-live-gross-cost').text(money(gross));
        $('#ph-live-unit-cost').text(unitCost > 0 ? money(unitCost) + ' / ' + base : '—');
        if(unitCost > 0 && (base === 'g' || base === 'ml')){
            $('#ph-live-equivalent').text(money(unitCost * 1000) + (base === 'g' ? ' / kg' : ' / L'));
        } else {
            $('#ph-live-equivalent').text('—');
        }
    }

    function canonicalUnit(unit){
        unit = String(unit || '').toLowerCase().trim();
        if(/\b(kg|kilograms?)\b/.test(unit)){ return 'kg'; }
        if(/\b(g|grams?)\b/.test(unit)){ return 'g'; }
        if(/\b(ml|millilit(?:er|re)s?)\b/.test(unit)){ return 'ml'; }
        if(/\b(l|lit(?:er|re)s?)\b/.test(unit)){ return 'l'; }
        if(/\b(oz|ounces?)\b/.test(unit)){ return 'oz'; }
        if(/\b(lb|pounds?)\b/.test(unit)){ return 'lb'; }
        if(/\b(tsp|teaspoons?)\b/.test(unit)){ return 'tsp'; }
        if(/\b(tbsp|tablespoons?)\b/.test(unit)){ return 'tbsp'; }
        if(/\b(cups?)\b/.test(unit)){ return 'cup'; }
        if(unitMultipliers[unit]){ return unit; }
        return 'each';
    }

    function normalizedAmount(qty,unit,expectedBase){
        unit = canonicalUnit(unit);
        var family = unitFamilies[unit] || '';
        var base = baseUnits[family] || '';
        if(!qty || !base || base !== expectedBase){ return null; }
        return qty * (unitMultipliers[unit] || 0);
    }

    function formatQty(value){
        value = Number(value || 0);
        return String(Math.round(value * 10000) / 10000);
    }

    function preciseMoney(value){
        value = Number(value || 0);
        var decimals = Math.abs(value) > 0 && Math.abs(value) < 0.01 ? 5 : 4;
        return currency + value.toFixed(decimals);
    }

    function productPricingBasis($option,yieldUnit,effectiveYieldQty,hasProduct){
        var normalizedYield = effectiveYieldQty * (unitMultipliers[yieldUnit] || 1);
        var fallback = {normalized:normalizedYield,label:'Whole batch ('+formatQty(effectiveYieldQty)+' '+yieldUnit+')',safe:!hasProduct};
        if(!hasProduct){ return fallback; }

        var size = String($option.attr('data-package-size') || '');
        var regex = /([0-9]+(?:[.,][0-9]+)?)\s*(kg|kilograms?|g|grams?|ml|millilit(?:er|re)s?|l|lit(?:er|re)s?|oz|ounces?|lb|pounds?|cup|cups|tbsp|tablespoons?|tsp|teaspoons?|each|pieces?|portions?|servings?|serves?|jars?|containers?|packages?|trays?|units?|rolls?)\b/gi;
        var match;
        while((match = regex.exec(size)) !== null){
            var qty = parseFloat(String(match[1]).replace(',','.')) || 0;
            var unit = canonicalUnit(match[2]);
            var family = unitFamilies[unit] || '';
            if(family && family === (unitFamilies[yieldUnit] || '')){
                return {normalized:qty*(unitMultipliers[unit]||1),label:'Product package ('+formatQty(qty)+' '+unit+')',safe:true};
            }
        }

        var weight = parseFloat($option.attr('data-weight')) || 0;
        var weightUnit = canonicalUnit($option.attr('data-weight-unit') || 'kg');
        if(weight > 0 && unitFamilies[yieldUnit] === 'mass' && unitFamilies[weightUnit] === 'mass'){
            return {normalized:weight*(unitMultipliers[weightUnit]||1),label:'Product package ('+formatQty(weight)+' '+weightUnit+')',safe:true};
        }

        if(unitFamilies[yieldUnit] === 'count'){
            return {normalized:1,label:'One sellable unit',safe:true};
        }
        fallback.safe = false;
        return fallback;
    }

    function rowCost($row){
        var $option = $row.find('.ph-recipe-ingredient option:selected');
        var sourceType = String($option.data('source-type') || 'ingredient');
        var qty = parseFloat($row.find('.ph-recipe-qty').val()) || 0;
        var unit = $row.find('.ph-recipe-unit').val();
        if(sourceType === 'recipe'){
            var batchCost = parseFloat($option.attr('data-batch-cost')) || 0;
            var productCost = parseFloat($option.attr('data-product-cost')) || 0;
            var baseUnit = String($option.attr('data-base-unit') || '');
            var subCost = 0;
            if(unit === 'batch'){
                subCost = qty * batchCost;
            } else if(unit === 'serving'){
                subCost = qty * productCost;
            } else {
                var normalized = normalizedAmount(qty,unit,baseUnit);
                var unitCost = parseFloat($option.attr('data-unit-cost')) || 0;
                if(normalized !== null && unitCost > 0){ subCost = normalized * unitCost; }
            }
            $row.find('.ph-recipe-line-cost').text(subCost > 0 ? money(subCost) : '—');
            return subCost;
        }
        var unitCost = parseFloat($option.data('unit-cost')) || 0;
        var baseUnit = String($option.data('base-unit') || '');
        var normalized = normalizedAmount(qty,unit,baseUnit);
        if(!unitCost || normalized === null){
            $row.find('.ph-recipe-line-cost').text('—');
            return 0;
        }
        var cost = normalized * unitCost;
        $row.find('.ph-recipe-line-cost').text(money(cost));
        return cost;
    }

    function recalcRecipe(){
        if(!$('#ph-recipe-items').length){ return; }
        var ingredients = 0;
        $('#ph-recipe-items .ph-recipe-row:not(.ph-recipe-row--header)').each(function(){ ingredients += rowCost($(this)); });
        var packaging = parseFloat($('#persiano_recipe_packaging_cost').val()) || 0;
        var labourMinutes = parseFloat($('#persiano_recipe_labour_minutes').val()) || 0;
        var labourRate = parseFloat($('#persiano_recipe_labour_rate').val()) || 0;
        var other = parseFloat($('#persiano_recipe_other_cost').val()) || 0;
        var misc = parseFloat($('#persiano_recipe_misc_cost').val()) || 0;
        var contingencyPct = parseFloat($('#persiano_recipe_contingency_pct').val()) || 0;
        var processingFeePct = parseFloat($('#persiano_recipe_processing_fee_pct').val()) || 0;
        var overheadPct = parseFloat($('#persiano_recipe_overhead_pct').val()) || 0;
        var targetPct = parseFloat($('#persiano_recipe_target_food_cost_pct').val()) || 35;
        var yieldQty = parseFloat($('#persiano_recipe_yield_qty').val()) || 1;
        var yieldUnit = canonicalUnit($('#persiano_recipe_yield_label').val() || 'each');
        var labour = (labourMinutes / 60) * labourRate;
        var known = ingredients + packaging + labour + other + misc;
        var overhead = known * overheadPct / 100;
        var subtotal = known + overhead;
        var contingency = subtotal * contingencyPct / 100;
        var batch = subtotal + contingency;

        var actualCost = parseFloat($('[name="persiano_recipe_actual_batch_cost"]').val()) || 0;
        var actualYield = parseFloat($('[name="persiano_recipe_actual_yield"]').val()) || 0;
        var effectiveBatch = (actualCost > 0 && actualYield > 0) ? actualCost : batch;
        var effectiveYieldQty = (actualCost > 0 && actualYield > 0) ? actualYield : yieldQty;
        var normalizedYield = Math.max(0.0001,effectiveYieldQty*(unitMultipliers[yieldUnit]||1));
        var unitCost = effectiveBatch / normalizedYield;

        var $productOption = $('#persiano_recipe_product_id option:selected');
        var hasProduct = (parseInt($productOption.val(),10)||0) > 0;
        var basis = productPricingBasis($productOption,yieldUnit,effectiveYieldQty,hasProduct);
        var productCost = unitCost * Math.max(0,basis.normalized);
        var taxFactor = parseFloat($productOption.data('tax-factor')) || parseFloat($('#ph-recipe-summary').data('tax-factor')) || 1;
        var currentPrice = parseFloat($productOption.data('price')) || 0;
        var preTax = productCost / (Math.max(1,targetPct)/100);
        if(processingFeePct > 0){ preTax = preTax / Math.max(0.01,1-(processingFeePct/100)); }
        var suggested = preTax * taxFactor;
        suggested = Math.round(suggested / 0.5) * 0.5;
        var suggestedNet = suggested / taxFactor;
        var taxAmount = suggested - suggestedNet;
        var currentNet = currentPrice > 0 ? currentPrice / taxFactor : 0;
        var margin = currentNet > 0 ? ((currentNet - productCost) / currentNet) * 100 : null;
        $('[data-summary="ingredients"]').text(money(ingredients));
        $('[data-summary="labour"]').text(money(labour));
        $('[data-summary="contingency"]').text(money(contingency));
        $('[data-summary="batch"]').text(money(batch));
        $('[data-summary="cost-source"]').text((actualCost > 0 && actualYield > 0) ? 'Actual production data' : 'Theoretical recipe cost');
        $('[data-summary="unit-cost"]').text(preciseMoney(unitCost));
        $('[data-summary="base-unit"]').text(baseUnits[unitFamilies[yieldUnit]] || yieldUnit);
        $('[data-summary="pricing-basis"]').text(basis.label);
        $('[data-summary="per-serving"]').text(money(productCost));
        $('[data-summary="current-price"]').text(currentPrice > 0 ? money(currentPrice) : '—');
        $('[data-summary="suggested-net"]').text(money(suggestedNet));
        $('[data-summary="tax"]').text(money(taxAmount));
        $('[data-summary="suggested"]').text(money(suggested));
        $('[data-summary="margin"]').text(margin !== null && isFinite(margin) ? margin.toFixed(1) + '%' : '—');
    }

    function reindexRows(){
        $('#ph-recipe-items .ph-recipe-row:not(.ph-recipe-row--header)').each(function(index){
            $(this).attr('data-index',index);
            $(this).find('[name]').each(function(){
                this.name = this.name.replace(/persiano_recipe_items\[[^\]]+\]/,'persiano_recipe_items['+index+']');
            });
        });
    }

    $(document).on('input change','.ph-ing-live',recalcIngredient);
    $(document).on('input change','.ph-recipe-ingredient,.ph-recipe-qty,.ph-recipe-unit,.ph-recipe-live,[name="persiano_recipe_actual_batch_cost"],[name="persiano_recipe_actual_yield"]',recalcRecipe);
    $(document).on('change','.ph-recipe-ingredient',function(){
        var $row=$(this).closest('.ph-recipe-row');
        var $opt=$(this).find('option:selected');
        var type=String($opt.data('source-type')||'ingredient');
        var $unit=$row.find('.ph-recipe-unit');
        if(type==='recipe'){
            var yieldUnit=canonicalUnit($opt.attr('data-yield-unit')||$opt.attr('data-base-unit')||'each');
            $unit.val(yieldUnit);
        }else if($unit.val()==='serving' || $unit.val()==='batch'){
            var base=String($opt.data('base-unit')||'g');
            $unit.val(base==='each'?'each':base);
        }
        recalcRecipe();
    });

    $(document).on('click','#ph-add-recipe-ingredient',function(){
        var template = $('#ph-recipe-row-template').html() || '';
        var index = $('#ph-recipe-items .ph-recipe-row:not(.ph-recipe-row--header)').length;
        template = template.replace(/__INDEX__/g,index);
        $('#ph-recipe-items').append(template);
        recalcRecipe();
    });

    $(document).on('click','.ph-remove-recipe-row',function(){
        $(this).closest('.ph-recipe-row').remove();
        reindexRows();
        recalcRecipe();
    });

    $(function(){ recalcIngredient(); recalcRecipe(); });
})(jQuery);
JS;
        wp_add_inline_script( 'jquery', 'window.PersianoCostingCurrency=' . wp_json_encode( $currency_symbol ) . ';' . $js );

        $css = <<<'CSS'
.ph-costing-grid{display:grid;gap:18px;margin:12px 0 4px}.ph-costing-grid--2{grid-template-columns:repeat(2,minmax(0,1fr))}.ph-costing-grid--3{grid-template-columns:repeat(3,minmax(0,1fr))}.ph-costing-field label{display:block;font-weight:600;margin-bottom:7px}.ph-costing-field--wide{grid-column:span 2}.ph-costing-field--full{margin-top:18px}.ph-input-suffix{display:flex;align-items:center;max-width:100%}.ph-input-suffix input{border-radius:4px 0 0 4px!important}.ph-input-suffix span{padding:5px 10px;border:1px solid #8c8f94;border-left:0;background:#f6f7f7;border-radius:0 4px 4px 0;min-height:30px;box-sizing:border-box}.ph-cost-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;background:#ddd;border:1px solid #ddd;border-radius:10px;overflow:hidden;margin:22px 0}.ph-cost-summary>div{display:flex;justify-content:space-between;gap:20px;padding:15px 17px;background:#fff}.ph-cost-summary span{color:#646970}.ph-cost-summary strong{font-size:15px}.ph-cost-summary--recipe{grid-template-columns:repeat(3,minmax(0,1fr))}.ph-cost-summary__highlight{background:#fff8e5!important}.ph-costing-section{padding:18px 0;border-bottom:1px solid #e2e4e7}.ph-costing-section:last-of-type{border-bottom:0}.ph-costing-section h3{margin:0 0 12px;font-size:17px}.ph-costing-heading-row{display:flex;justify-content:space-between;gap:20px;align-items:center;margin-bottom:14px}.ph-costing-heading-row h2,.ph-costing-heading-row h3,.ph-costing-heading-row p{margin-top:0}.ph-recipe-items{border:1px solid #dcdcde;border-radius:10px;overflow:hidden}.ph-recipe-row{display:grid;grid-template-columns:minmax(220px,1.7fr) 90px 95px minmax(150px,1fr) 105px 95px 30px;gap:8px;align-items:center;padding:10px 12px;border-top:1px solid #f0f0f1;background:#fff}.ph-recipe-row:first-child{border-top:0}.ph-recipe-row--header{font-weight:600;background:#f6f7f7;color:#50575e}.ph-remove-recipe-row{font-size:24px;text-decoration:none;text-align:center}.ph-recipe-line-cost{text-align:right}.ph-costing-warning ul{list-style:disc;padding-left:24px}.ph-apply-price-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.ph-apply-price-row span{color:#646970}.ph-best-price{display:grid;gap:2px;padding:10px;margin-bottom:12px;background:#fff8e5;border:1px solid #ead6a4;border-radius:8px}.ph-best-price small{color:#646970}.ph-history-list{display:grid;gap:10px}.ph-history-entry{border-bottom:1px solid #eee;padding-bottom:10px;display:grid;gap:2px}.ph-history-entry:last-child{border-bottom:0}.ph-history-entry span,.ph-history-entry small{color:#646970}.ph-product-costing-mini{margin-top:14px;padding-top:10px;border-top:1px solid #ddd}.ph-product-costing-mini p{display:flex;justify-content:space-between;gap:10px}.ph-product-costing-mini p:last-child{display:block}.ph-costing-dashboard{max-width:1280px}.ph-costing-tabs{margin-top:18px;margin-bottom:10px}.ph-costing-tabs .nav-tab{font-weight:600}.ph-costing-hero--compact{padding-top:18px}.ph-scan-upload{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:18px;border:2px dashed #c3c4c7;border-radius:12px;background:#fafafa}.ph-scan-upload input[type=file]{max-width:520px}.ph-scan-table-wrap{overflow:auto}.ph-scan-table{min-width:1180px}.ph-scan-table input.widefat{min-width:145px}.ph-scan-table select{max-width:220px}.ph-scan-table th,.ph-scan-table td{vertical-align:middle;padding:10px}.ph-scan-table .small-text{width:90px}.ph-costing-hero{display:flex;justify-content:space-between;gap:30px;align-items:flex-end;padding:30px 0 22px}.ph-costing-hero h1{font-size:34px;margin:4px 0 8px}.ph-costing-hero p{font-size:16px;color:#646970;max-width:760px}.ph-costing-eyebrow{text-transform:uppercase;letter-spacing:.15em;font-weight:700;color:#9a263a;font-size:12px}.ph-costing-actions{display:flex;gap:10px;flex-wrap:wrap}.ph-costing-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin:22px 0}.ph-costing-stats>div{padding:20px;border:1px solid #ddd;border-radius:12px;background:#fff}.ph-costing-stats strong{display:block;font-size:30px;color:#9a263a}.ph-costing-stats span{color:#646970}.ph-costing-panel{margin:20px 0;padding:22px 24px;background:#fff;border:1px solid #ddd;border-radius:14px}.ph-costing-table-wrap{overflow:auto}.ph-costing-table th,.ph-costing-table td{padding:12px}.ph-costing-empty{padding:24px;text-align:center;background:#f6f7f7;border-radius:10px}.ph-costing-flow{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.ph-costing-flow span{padding:10px 13px;border-radius:999px;background:#f6f0e7}.ph-costing-flow b{color:#9a263a}.ph-price-stale{color:#b32d2e;font-weight:700}.column-persiano_purchase,.column-persiano_unit_cost,.column-persiano_cost,.column-persiano_price,.column-persiano_suggested,.column-persiano_margin{width:120px}.column-persiano_brand,.column-persiano_supplier,.column-persiano_product{width:140px}@media(max-width:900px){.ph-costing-grid--2,.ph-costing-grid--3,.ph-cost-summary--recipe,.ph-costing-stats{grid-template-columns:1fr 1fr}.ph-costing-field--wide{grid-column:span 2}.ph-recipe-row{grid-template-columns:1.5fr 80px 85px 1fr 95px 90px 28px}.ph-costing-hero{display:block}.ph-costing-actions{margin-top:16px}}@media(max-width:620px){.ph-costing-grid--2,.ph-costing-grid--3,.ph-cost-summary,.ph-cost-summary--recipe,.ph-costing-stats{grid-template-columns:1fr}.ph-costing-field--wide{grid-column:auto}.ph-recipe-row--header{display:none}.ph-recipe-row{grid-template-columns:1fr 1fr;gap:8px}.ph-recipe-line-cost{text-align:left}.ph-remove-recipe-row{position:absolute;right:20px}.ph-recipe-row{position:relative;padding-right:46px}}
CSS;
        wp_register_style( 'persiano-hub-costing-admin', false, array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-hub-costing-admin' );
        wp_add_inline_style( 'persiano-hub-costing-admin', $css );

        if ( isset( $_GET['persiano_costing_notice'] ) && 'price-updated' === sanitize_key( wp_unslash( $_GET['persiano_costing_notice'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'WooCommerce product price updated from the recipe suggestion.', 'persiano-hub' ) . '</p></div>';
            } );
        }
    }
}
