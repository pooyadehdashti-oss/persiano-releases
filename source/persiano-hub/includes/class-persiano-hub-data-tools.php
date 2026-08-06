<?php
/**
 * Import/export tools for Persiano costing data.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Data_Tools {
    const IMPORT_TRANSIENT = 'persiano_hub_data_import_';

    public static function init() {
        add_action( 'admin_post_persiano_hub_export_costing_json', array( __CLASS__, 'export_json' ) );
        add_action( 'admin_post_persiano_hub_export_costing_csv', array( __CLASS__, 'export_csv' ) );
        add_action( 'admin_post_persiano_hub_import_costing_json', array( __CLASS__, 'import_json' ) );
    }

    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage Persiano costing data.', 'persiano-hub' ) );
        }
    }

    private static function dashboard_url( $args = array() ) {
        return add_query_arg(
            array_merge(
                array(
                    'page' => Persiano_Hub_Costing::MENU_SLUG,
                    'tab'  => 'data',
                ),
                $args
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function ingredient_meta_keys() {
        return array(
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
            Persiano_Hub_Costing::ING_HISTORY,
            Persiano_Hub_Kitchen::ING_IMAGE_ID,
            Persiano_Hub_Kitchen::ING_ON_HAND_QTY,
            Persiano_Hub_Kitchen::ING_ON_HAND_UNIT,
            Persiano_Hub_Operations::ING_ARCHIVED,
            Persiano_Hub_Operations::ING_AI_EXCLUDE,
            Persiano_Hub_Operations::ING_INVENTORY_HISTORY,
        );
    }

    private static function recipe_meta_keys() {
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
            Persiano_Hub_Costing::RECIPE_INGREDIENT_COST,
            Persiano_Hub_Costing::RECIPE_LABOUR_COST,
            Persiano_Hub_Costing::RECIPE_OVERHEAD_COST,
            Persiano_Hub_Costing::RECIPE_BATCH_COST,
            Persiano_Hub_Costing::RECIPE_COST_PER_SERVING,
            Persiano_Hub_Costing::RECIPE_COST_PER_BASE_UNIT,
            Persiano_Hub_Costing::RECIPE_PRODUCT_COST,
            Persiano_Hub_Costing::RECIPE_PRICING_LABEL,
            Persiano_Hub_Costing::RECIPE_SUGGESTED_PRICE,
            Persiano_Hub_Costing::RECIPE_NET_SUGGESTED_PRICE,
            Persiano_Hub_Costing::RECIPE_TAX_AMOUNT,
            Persiano_Hub_Costing::RECIPE_CONTINGENCY_COST,
            Persiano_Hub_Costing::RECIPE_PROCESSING_FEE_COST,
            Persiano_Hub_Costing::RECIPE_WARNINGS,
            Persiano_Hub_Kitchen::RECIPE_CATEGORY,
            Persiano_Hub_Kitchen::RECIPE_SERVING_NOTE,
            Persiano_Hub_Kitchen::RECIPE_PREP_MINUTES,
            Persiano_Hub_Kitchen::RECIPE_PASSIVE_MINUTES,
            Persiano_Hub_Kitchen::RECIPE_STORAGE,
            Persiano_Hub_Kitchen::RECIPE_FREEZING,
            Persiano_Hub_Kitchen::RECIPE_EQUIPMENT,
            Persiano_Hub_Kitchen::RECIPE_STEPS,
            Persiano_Hub_Kitchen::RECIPE_MEDIA_IDS,
            Persiano_Hub_Kitchen::RECIPE_ACTUAL_COST,
            Persiano_Hub_Kitchen::RECIPE_ACTUAL_YIELD,
            Persiano_Hub_Kitchen::RECIPE_ACTUAL_NOTES,
            Persiano_Hub_Kitchen::RECIPE_VERSIONS,
            Persiano_Hub_Kitchen::RECIPE_VERSION_HASH,
            Persiano_Hub_Inventory::RECIPE_TRACK_COMPONENT,
            Persiano_Hub_Inventory::RECIPE_COMPONENT_LOTS,
            Persiano_Hub_Inventory::RECIPE_COMPONENT_HISTORY,
        );
    }


    private static function production_plan_meta_keys() {
        return array(
            Persiano_Hub_Operations::META_STATUS,
            Persiano_Hub_Operations::META_PLAN_FROM,
            Persiano_Hub_Operations::META_PLAN_TO,
            Persiano_Hub_Operations::META_PLAN_RECIPES,
            Persiano_Hub_Operations::META_PLAN_INGREDIENTS,
            Persiano_Hub_Operations::META_PLAN_COMPONENTS,
            Persiano_Hub_Operations::META_PLAN_NOTES,
            Persiano_Hub_Operations::META_PLAN_SOURCE,
            Persiano_Hub_Operations::META_PLAN_SYNCED,
            Persiano_Hub_Inventory::PLAN_INVENTORY_APPLIED,
        );
    }

    private static function shopping_list_meta_keys() {
        return array(
            Persiano_Hub_Operations::META_STATUS,
            Persiano_Hub_Operations::META_LIST_ITEMS,
            Persiano_Hub_Operations::META_LIST_NOTES,
            Persiano_Hub_Operations::META_LIST_SOURCE,
            Persiano_Hub_Operations::META_LIST_SYNCED,
        );
    }

    private static function export_post_record( $post_id, $keys ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return array();
        }
        $meta = array();
        foreach ( $keys as $key ) {
            $meta[ $key ] = get_post_meta( $post_id, $key, true );
        }
        return array(
            'id'          => (int) $post_id,
            'title'       => $post->post_title,
            'status'      => $post->post_status,
            'date'        => $post->post_date_gmt,
            'modified'    => $post->post_modified_gmt,
            'meta'        => $meta,
        );
    }

    private static function build_backup() {
        $ingredient_ids = get_posts(
            array(
                'post_type'      => Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );
        $recipe_ids = get_posts(
            array(
                'post_type'      => Persiano_Hub_Costing::RECIPE_POST_TYPE,
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        $plan_ids = get_posts( array( 'post_type'=>Persiano_Hub_Operations::PLAN_POST_TYPE, 'post_status'=>array( 'publish','draft','private' ), 'posts_per_page'=>-1, 'fields'=>'ids', 'orderby'=>'ID', 'order'=>'ASC' ) );
        $list_ids = get_posts( array( 'post_type'=>Persiano_Hub_Operations::LIST_POST_TYPE, 'post_status'=>array( 'publish','draft','private' ), 'posts_per_page'=>-1, 'fields'=>'ids', 'orderby'=>'ID', 'order'=>'ASC' ) );
        $price_sources = class_exists( 'Persiano_Hub_Price_Feeds' ) ? Persiano_Hub_Price_Feeds::export_records() : array();

        $ingredients = array();
        foreach ( $ingredient_ids as $id ) {
            $ingredients[] = self::export_post_record( $id, self::ingredient_meta_keys() );
        }

        $recipes = array();
        foreach ( $recipe_ids as $id ) {
            $record = self::export_post_record( $id, self::recipe_meta_keys() );
            $product_id = absint( $record['meta'][ Persiano_Hub_Costing::RECIPE_PRODUCT_ID ] ?? 0 );
            $product = $product_id ? wc_get_product( $product_id ) : false;
            $record['linked_product'] = $product ? array(
                'id'   => $product_id,
                'sku'  => $product->get_sku(),
                'name' => $product->get_name(),
            ) : array();
            $recipes[] = $record;
        }


        $production_plans = array();
        foreach ( $plan_ids as $id ) {
            $production_plans[] = self::export_post_record( $id, self::production_plan_meta_keys() );
        }
        $shopping_lists = array();
        foreach ( $list_ids as $id ) {
            $shopping_lists[] = self::export_post_record( $id, self::shopping_list_meta_keys() );
        }

        return array(
            'format'       => 'persiano-costing-backup',
            'format_version'=> 3,
            'plugin_version'=> defined( 'PERSIANO_HUB_VERSION' ) ? PERSIANO_HUB_VERSION : '',
            'site_url'     => home_url( '/' ),
            'exported_at'  => gmdate( 'c' ),
            'ingredients'  => $ingredients,
            'recipes'      => $recipes,
            'production_plans' => $production_plans,
            'shopping_lists'   => $shopping_lists,
            'price_sources'     => $price_sources,
        );
    }

    public static function export_json() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_export_costing_json' );
        $backup = self::build_backup();
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="persiano-costing-backup-' . gmdate( 'Y-m-d-His' ) . '.json"' );
        echo wp_json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    private static function csv_headers( $table ) {
        $headers = array(
            'ingredients' => array( 'ingredient_id', 'name', 'category', 'brand', 'vendor', 'purchase_qty', 'purchase_unit', 'subtotal_cost', 'purchase_tax', 'gross_cost', 'waste_pct', 'usable_unit_cost', 'base_unit', 'on_hand_qty', 'on_hand_unit' ),
            'price_history'=> array( 'ingredient_id', 'ingredient_name', 'recorded_at', 'brand', 'vendor', 'purchase_qty', 'purchase_unit', 'subtotal_cost', 'purchase_tax', 'gross_cost', 'waste_pct', 'usable_unit_cost', 'base_unit', 'source' ),
            'recipes'     => array( 'recipe_id', 'name', 'linked_product_id', 'linked_product_sku', 'yield_qty', 'yield_unit', 'normalized_base_unit', 'ingredient_cost', 'labour_cost', 'theoretical_batch_cost', 'effective_batch_cost', 'cost_per_base_unit', 'pricing_basis', 'product_package_cost', 'suggested_price_tax_included', 'cost_source', 'category', 'prep_minutes', 'active_labour_minutes', 'passive_minutes' ),
            'recipe_items'=> array( 'recipe_id', 'recipe_name', 'row_number', 'source_type', 'ingredient_or_recipe_id', 'ingredient_or_recipe_name', 'quantity', 'unit', 'preparation', 'rounding' ),
            'recipe_steps'=> array( 'recipe_id', 'recipe_name', 'step_number', 'instruction', 'minutes', 'time_type', 'temperature', 'media_id' ),
            'inventory_history'=> array( 'ingredient_id','ingredient_name','recorded_at','mode','change','quantity','new_total','unit','reason','note','source' ),
            'prepared_components'=> array( 'recipe_id','recipe_name','tracking_enabled','yield_qty','yield_unit','usable_on_hand','reserved','available','lot_count','earliest_best_before','recorded_lot_cost' ),
            'component_lots'=> array( 'recipe_id','recipe_name','lot_id','produced_at','best_before','original_qty','remaining_qty','unit','cost','location','lot_code','inputs_deducted','note' ),
            'component_inventory_history'=> array( 'recipe_id','recipe_name','recorded_at','mode','quantity','unit','reason','note','lot_id' ),
            'product_inventory'=> array( 'product_id','parent_id','product_type','name','sku','post_status','manage_stock','stock_quantity','stock_status','linked_recipe_id','package_size' ),
            'production_plans'=> array( 'plan_id','name','status','from','to','recipes_json','ingredients_json','components_json','source','inventory_applied_json','notes' ),
            'shopping_lists'=> array( 'list_id','name','status','items_json','source_json','synced_revision','notes' ),
            'price_sources'=> array( 'source_id','name','status','url','current_url','ingredient_id','ingredient_name','supplier','brand','sku','gtin','package_quantity','package_unit','price','regular_price','currency','availability','frequency','last_attempt','last_success','failures','last_error','previous_price','price_change','price_change_pct','price_changed_at' ),
        );
        return $headers[ $table ] ?? array();
    }

    private static function csv_rows( $table ) {
        $rows = array();
        if ( 'price_sources' === $table && class_exists( 'Persiano_Hub_Price_Feeds' ) ) {
            foreach ( Persiano_Hub_Price_Feeds::export_records() as $record ) {
                $m = (array) ( $record['meta'] ?? array() );
                $ingredient_id = absint( $m[ Persiano_Hub_Price_Feeds::META_INGREDIENT_ID ] ?? 0 );
                $rows[] = array(
                    $record['id'] ?? 0, $record['title'] ?? '', $m[ Persiano_Hub_Price_Feeds::META_STATUS ] ?? '',
                    $m[ Persiano_Hub_Price_Feeds::META_URL ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_CURRENT_URL ] ?? '',
                    $ingredient_id, $ingredient_id ? get_the_title( $ingredient_id ) : '', $m[ Persiano_Hub_Price_Feeds::META_SUPPLIER ] ?? '',
                    $m[ Persiano_Hub_Price_Feeds::META_BRAND ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_SKU ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_GTIN ] ?? '',
                    $m[ Persiano_Hub_Price_Feeds::META_PACKAGE_QTY ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_PACKAGE_UNIT ] ?? '',
                    $m[ Persiano_Hub_Price_Feeds::META_PRICE ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_REGULAR_PRICE ] ?? '',
                    $m[ Persiano_Hub_Price_Feeds::META_CURRENCY ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_AVAILABILITY ] ?? '',
                    $m[ Persiano_Hub_Price_Feeds::META_FREQUENCY ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_LAST_ATTEMPT ] ?? '',
                    $m[ Persiano_Hub_Price_Feeds::META_LAST_SUCCESS ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_FAILURES ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_LAST_ERROR ] ?? '',
                    $m[ Persiano_Hub_Price_Feeds::META_PREVIOUS_PRICE ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_PRICE_CHANGE ] ?? '',
                    $m[ Persiano_Hub_Price_Feeds::META_PRICE_CHANGE_PCT ] ?? '', $m[ Persiano_Hub_Price_Feeds::META_PRICE_CHANGED_AT ] ?? '',
                );
            }
            return $rows;
        }
        if ( 'production_plans' === $table ) {
            $ids = get_posts( array( 'post_type'=>Persiano_Hub_Operations::PLAN_POST_TYPE, 'post_status'=>array( 'publish','draft','private' ), 'posts_per_page'=>-1, 'fields'=>'ids' ) );
            foreach ( $ids as $id ) {
                $rows[] = array(
                    $id,
                    get_the_title( $id ),
                    get_post_meta( $id, Persiano_Hub_Operations::META_STATUS, true ),
                    get_post_meta( $id, Persiano_Hub_Operations::META_PLAN_FROM, true ),
                    get_post_meta( $id, Persiano_Hub_Operations::META_PLAN_TO, true ),
                    wp_json_encode( get_post_meta( $id, Persiano_Hub_Operations::META_PLAN_RECIPES, true ) ),
                    wp_json_encode( get_post_meta( $id, Persiano_Hub_Operations::META_PLAN_INGREDIENTS, true ) ),
                    wp_json_encode( get_post_meta( $id, Persiano_Hub_Operations::META_PLAN_COMPONENTS, true ) ),
                    get_post_meta( $id, Persiano_Hub_Operations::META_PLAN_SOURCE, true ),
                    wp_json_encode( get_post_meta( $id, Persiano_Hub_Inventory::PLAN_INVENTORY_APPLIED, true ) ),
                    get_post_meta( $id, Persiano_Hub_Operations::META_PLAN_NOTES, true ),
                );
            }
            return $rows;
        }
        if ( 'shopping_lists' === $table ) {
            $ids = get_posts( array( 'post_type'=>Persiano_Hub_Operations::LIST_POST_TYPE, 'post_status'=>array( 'publish','draft','private' ), 'posts_per_page'=>-1, 'fields'=>'ids' ) );
            foreach ( $ids as $id ) {
                $rows[] = array(
                    $id,
                    get_the_title( $id ),
                    get_post_meta( $id, Persiano_Hub_Operations::META_STATUS, true ),
                    wp_json_encode( get_post_meta( $id, Persiano_Hub_Operations::META_LIST_ITEMS, true ) ),
                    wp_json_encode( get_post_meta( $id, Persiano_Hub_Operations::META_LIST_SOURCE, true ) ),
                    get_post_meta( $id, Persiano_Hub_Operations::META_LIST_SYNCED, true ),
                    get_post_meta( $id, Persiano_Hub_Operations::META_LIST_NOTES, true ),
                );
            }
            return $rows;
        }
        if ( in_array( $table, array( 'prepared_components', 'component_lots', 'component_inventory_history' ), true ) ) {
            foreach ( Persiano_Hub_Inventory::component_recipe_ids() as $recipe_id ) {
                $unit = Persiano_Hub_Inventory::component_unit( $recipe_id );
                $lots = Persiano_Hub_Inventory::get_component_lots( $recipe_id );
                if ( 'prepared_components' === $table ) {
                    $on_hand = Persiano_Hub_Inventory::component_on_hand( $recipe_id, $unit );
                    $reserved = Persiano_Hub_Inventory::component_reserved( $recipe_id, $unit );
                    $earliest = '';
                    $cost = 0.0;
                    foreach ( $lots as $lot ) {
                        if ( ! empty( $lot['best_before'] ) && ( ! $earliest || $lot['best_before'] < $earliest ) ) {
                            $earliest = $lot['best_before'];
                        }
                        $cost += (float) ( $lot['cost'] ?? 0 );
                    }
                    $rows[] = array(
                        $recipe_id,
                        get_the_title( $recipe_id ),
                        get_post_meta( $recipe_id, Persiano_Hub_Inventory::RECIPE_TRACK_COMPONENT, true ),
                        get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ),
                        $unit,
                        $on_hand,
                        $reserved,
                        max( 0, $on_hand - $reserved ),
                        count( $lots ),
                        $earliest,
                        $cost,
                    );
                } elseif ( 'component_lots' === $table ) {
                    foreach ( $lots as $lot ) {
                        $rows[] = array(
                            $recipe_id,
                            get_the_title( $recipe_id ),
                            $lot['id'] ?? '',
                            $lot['produced_at'] ?? '',
                            $lot['best_before'] ?? '',
                            $lot['qty'] ?? '',
                            $lot['remaining_qty'] ?? '',
                            $lot['unit'] ?? '',
                            $lot['cost'] ?? '',
                            $lot['location'] ?? '',
                            $lot['lot_code'] ?? '',
                            ! empty( $lot['inputs_consumed'] ) ? 'yes' : 'no',
                            $lot['note'] ?? '',
                        );
                    }
                } else {
                    $history = get_post_meta( $recipe_id, Persiano_Hub_Inventory::RECIPE_COMPONENT_HISTORY, true );
                    foreach ( is_array( $history ) ? $history : array() as $entry ) {
                        $rows[] = array(
                            $recipe_id,
                            get_the_title( $recipe_id ),
                            ! empty( $entry['time'] ) ? gmdate( 'c', absint( $entry['time'] ) ) : '',
                            $entry['mode'] ?? '',
                            $entry['quantity'] ?? '',
                            $entry['unit'] ?? '',
                            $entry['reason'] ?? '',
                            $entry['note'] ?? '',
                            $entry['lot_id'] ?? '',
                        );
                    }
                }
            }
            return $rows;
        }
        if ( 'product_inventory' === $table ) {
            if ( ! function_exists( 'wc_get_product' ) ) {
                return $rows;
            }
            $ids = get_posts(
                array(
                    'post_type' => array( 'product', 'product_variation' ),
                    'post_status' => array( 'publish', 'draft', 'private' ),
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'orderby' => 'title',
                    'order' => 'ASC',
                )
            );
            foreach ( $ids as $id ) {
                $product = wc_get_product( $id );
                if ( ! $product ) {
                    continue;
                }
                $parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
                $recipe_id = absint( get_post_meta( $id, Persiano_Hub_Costing::PRODUCT_RECIPE_META, true ) );
                if ( ! $recipe_id && $parent_id ) {
                    $recipe_id = absint( get_post_meta( $parent_id, Persiano_Hub_Costing::PRODUCT_RECIPE_META, true ) );
                }
                $package_size = get_post_meta( $id, '_persiano_size', true );
                if ( ! $package_size && $parent_id ) {
                    $package_size = get_post_meta( $parent_id, '_persiano_size', true );
                }
                $rows[] = array(
                    $id,
                    $parent_id,
                    $product->get_type(),
                    $product->get_name(),
                    $product->get_sku(),
                    get_post_status( $id ),
                    $product->managing_stock() ? 'yes' : 'no',
                    null === $product->get_stock_quantity() ? '' : $product->get_stock_quantity(),
                    $product->get_stock_status(),
                    $recipe_id,
                    $package_size,
                );
            }
            return $rows;
        }
        if ( 'inventory_history' === $table ) {
            $ids = get_posts( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_status'=>array( 'publish','draft','private' ), 'posts_per_page'=>-1, 'fields'=>'ids' ) );
            foreach ( $ids as $id ) {
                foreach ( (array) get_post_meta( $id, Persiano_Hub_Operations::ING_INVENTORY_HISTORY, true ) as $entry ) {
                    $rows[] = array( $id, get_the_title( $id ), ! empty( $entry['time'] ) ? gmdate( 'c', absint( $entry['time'] ) ) : '', $entry['mode'] ?? '', $entry['change'] ?? '', $entry['quantity'] ?? '', $entry['new_total'] ?? '', $entry['unit'] ?? '', $entry['reason'] ?? '', $entry['note'] ?? '', $entry['source'] ?? '' );
                }
            }
            return $rows;
        }
        if ( in_array( $table, array( 'ingredients', 'price_history' ), true ) ) {
            $ids = get_posts( array( 'post_type' => Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ) );
            foreach ( $ids as $id ) {
                if ( 'ingredients' === $table ) {
                    $subtotal = (float) get_post_meta( $id, Persiano_Hub_Costing::ING_PURCHASE_COST, true );
                    $tax = (float) get_post_meta( $id, Persiano_Hub_Costing::ING_PURCHASE_TAX, true );
                    $gross = (float) get_post_meta( $id, Persiano_Hub_Costing::ING_GROSS_COST, true );
                    if ( ! $gross ) { $gross = $subtotal + $tax; }
                    $rows[] = array(
                        $id,
                        get_the_title( $id ),
                        get_post_meta( $id, Persiano_Hub_Costing::ING_CATEGORY, true ),
                        get_post_meta( $id, Persiano_Hub_Costing::ING_BRAND, true ),
                        get_post_meta( $id, Persiano_Hub_Costing::ING_SUPPLIER, true ),
                        get_post_meta( $id, Persiano_Hub_Costing::ING_PURCHASE_QTY, true ),
                        get_post_meta( $id, Persiano_Hub_Costing::ING_PURCHASE_UNIT, true ),
                        $subtotal,
                        $tax,
                        $gross,
                        get_post_meta( $id, Persiano_Hub_Costing::ING_WASTE_PCT, true ),
                        get_post_meta( $id, Persiano_Hub_Costing::ING_UNIT_COST, true ),
                        get_post_meta( $id, Persiano_Hub_Costing::ING_BASE_UNIT, true ),
                        get_post_meta( $id, Persiano_Hub_Kitchen::ING_ON_HAND_QTY, true ),
                        get_post_meta( $id, Persiano_Hub_Kitchen::ING_ON_HAND_UNIT, true ),
                    );
                } else {
                    $history = get_post_meta( $id, Persiano_Hub_Costing::ING_HISTORY, true );
                    foreach ( is_array( $history ) ? $history : array() as $entry ) {
                        $subtotal = (float) ( $entry['purchase_cost'] ?? 0 );
                        $tax = (float) ( $entry['purchase_tax'] ?? 0 );
                        $gross = isset( $entry['gross_cost'] ) ? (float) $entry['gross_cost'] : $subtotal + $tax;
                        $rows[] = array(
                            $id,
                            get_the_title( $id ),
                            ! empty( $entry['time'] ) ? gmdate( 'c', absint( $entry['time'] ) ) : '',
                            $entry['brand'] ?? '',
                            $entry['supplier'] ?? '',
                            $entry['purchase_qty'] ?? '',
                            $entry['purchase_unit'] ?? '',
                            $subtotal,
                            $tax,
                            $gross,
                            $entry['waste_pct'] ?? '',
                            $entry['unit_cost'] ?? '',
                            $entry['base_unit'] ?? '',
                            $entry['source'] ?? '',
                        );
                    }
                }
            }
            return $rows;
        }

        $ids = get_posts( array( 'post_type' => Persiano_Hub_Costing::RECIPE_POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ) );
        foreach ( $ids as $id ) {
            if ( 'recipes' === $table ) {
                $product_id = absint( get_post_meta( $id, Persiano_Hub_Costing::RECIPE_PRODUCT_ID, true ) );
                $product = $product_id ? wc_get_product( $product_id ) : false;
                $summary = Persiano_Hub_Costing::calculate_recipe( $id );
                $rows[] = array(
                    $id,
                    get_the_title( $id ),
                    $product_id,
                    $product ? $product->get_sku() : '',
                    $summary['yield_qty'],
                    $summary['yield_unit'],
                    $summary['base_unit'],
                    $summary['ingredient_cost'],
                    $summary['labour_cost'],
                    $summary['batch_cost'],
                    $summary['effective_batch_cost'],
                    $summary['cost_per_base_unit'],
                    $summary['pricing_label'],
                    $summary['product_cost'],
                    $summary['suggested_price'],
                    $summary['cost_source'],
                    get_post_meta( $id, Persiano_Hub_Kitchen::RECIPE_CATEGORY, true ),
                    get_post_meta( $id, Persiano_Hub_Kitchen::RECIPE_PREP_MINUTES, true ),
                    get_post_meta( $id, Persiano_Hub_Costing::RECIPE_LABOUR_MINUTES, true ),
                    get_post_meta( $id, Persiano_Hub_Kitchen::RECIPE_PASSIVE_MINUTES, true ),
                );
            } elseif ( 'recipe_items' === $table ) {
                $items = get_post_meta( $id, Persiano_Hub_Costing::RECIPE_ITEMS, true );
                foreach ( is_array( $items ) ? $items : array() as $index => $item ) {
                    $source_type = $item['source_type'] ?? ( ! empty( $item['recipe_id'] ) ? 'recipe' : 'ingredient' );
                    $source_id = 'recipe' === $source_type ? absint( $item['recipe_id'] ?? 0 ) : absint( $item['ingredient_id'] ?? 0 );
                    $rows[] = array( $id, get_the_title( $id ), $index + 1, $source_type, $source_id, $source_id ? get_the_title( $source_id ) : '', $item['qty'] ?? '', $item['unit'] ?? '', $item['prep_note'] ?? '', $item['rounding'] ?? '' );
                }
            } elseif ( 'recipe_steps' === $table ) {
                $steps = get_post_meta( $id, Persiano_Hub_Kitchen::RECIPE_STEPS, true );
                foreach ( is_array( $steps ) ? $steps : array() as $index => $step ) {
                    $rows[] = array( $id, get_the_title( $id ), $index + 1, $step['instruction'] ?? '', $step['minutes'] ?? '', $step['time_type'] ?? '', $step['temperature'] ?? '', $step['media_id'] ?? '' );
                }
            }
        }
        return $rows;
    }

    public static function export_csv() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_export_costing_csv' );
        $table = isset( $_GET['table'] ) ? sanitize_key( wp_unslash( $_GET['table'] ) ) : 'ingredients';
        $headers = self::csv_headers( $table );
        if ( ! $headers ) {
            wp_die( esc_html__( 'Unknown Persiano export table.', 'persiano-hub' ) );
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="persiano-' . $table . '-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, $headers );
        foreach ( self::csv_rows( $table ) as $row ) {
            fputcsv( $out, $row );
        }
        fclose( $out );
        exit;
    }

    private static function find_post_by_title( $title, $post_type ) {
        $posts = get_posts( array( 'post_type' => $post_type, 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => 1, 'title' => $title, 'fields' => 'ids', 'no_found_rows' => true ) );
        return $posts ? (int) $posts[0] : 0;
    }

    private static function import_meta( $post_id, $meta, $allowed_keys ) {
        foreach ( $allowed_keys as $key ) {
            if ( array_key_exists( $key, $meta ) ) {
                update_post_meta( $post_id, $key, $meta[ $key ] );
            }
        }
    }

    public static function import_json() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_import_costing_json' );
        if ( empty( $_FILES['persiano_costing_backup']['tmp_name'] ) || ! is_uploaded_file( $_FILES['persiano_costing_backup']['tmp_name'] ) ) {
            wp_safe_redirect( self::dashboard_url( array( 'import_error' => rawurlencode( __( 'Choose a Persiano JSON backup first.', 'persiano-hub' ) ) ) ) );
            exit;
        }
        $raw = file_get_contents( $_FILES['persiano_costing_backup']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) || 'persiano-costing-backup' !== ( $data['format'] ?? '' ) ) {
            wp_safe_redirect( self::dashboard_url( array( 'import_error' => rawurlencode( __( 'That file is not a valid Persiano costing backup.', 'persiano-hub' ) ) ) ) );
            exit;
        }

        $update_matching = ! empty( $_POST['persiano_update_matching'] );
        $ingredient_map = array();
        $recipe_map = array();
        $plan_map = array();
        $created = 0;
        $updated = 0;

        foreach ( (array) ( $data['ingredients'] ?? array() ) as $record ) {
            $old_id = absint( $record['id'] ?? 0 );
            $title = sanitize_text_field( $record['title'] ?? '' );
            if ( ! $title ) { continue; }
            $post_id = $update_matching ? self::find_post_by_title( $title, Persiano_Hub_Costing::INGREDIENT_POST_TYPE ) : 0;
            if ( $post_id ) {
                $updated++;
            } else {
                $post_id = wp_insert_post( array( 'post_type' => Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_status' => sanitize_key( $record['status'] ?? 'publish' ), 'post_title' => $title ), true );
                if ( is_wp_error( $post_id ) ) { continue; }
                $created++;
            }
            self::import_meta( $post_id, (array) ( $record['meta'] ?? array() ), self::ingredient_meta_keys() );
            $ingredient_map[ $old_id ] = (int) $post_id;
        }

        foreach ( (array) ( $data['recipes'] ?? array() ) as $record ) {
            $old_id = absint( $record['id'] ?? 0 );
            $title = sanitize_text_field( $record['title'] ?? '' );
            if ( ! $title ) { continue; }
            $post_id = $update_matching ? self::find_post_by_title( $title, Persiano_Hub_Costing::RECIPE_POST_TYPE ) : 0;
            if ( $post_id ) {
                $updated++;
            } else {
                $post_id = wp_insert_post( array( 'post_type' => Persiano_Hub_Costing::RECIPE_POST_TYPE, 'post_status' => sanitize_key( $record['status'] ?? 'publish' ), 'post_title' => $title ), true );
                if ( is_wp_error( $post_id ) ) { continue; }
                $created++;
            }
            $recipe_map[ $old_id ] = (int) $post_id;
        }

        foreach ( (array) ( $data['recipes'] ?? array() ) as $record ) {
            $old_id = absint( $record['id'] ?? 0 );
            if ( empty( $recipe_map[ $old_id ] ) ) { continue; }
            $post_id = $recipe_map[ $old_id ];
            $meta = (array) ( $record['meta'] ?? array() );
            if ( isset( $meta[ Persiano_Hub_Costing::RECIPE_ITEMS ] ) && is_array( $meta[ Persiano_Hub_Costing::RECIPE_ITEMS ] ) ) {
                foreach ( $meta[ Persiano_Hub_Costing::RECIPE_ITEMS ] as &$item ) {
                    $source_type = sanitize_key( $item['source_type'] ?? ( ! empty( $item['recipe_id'] ) ? 'recipe' : 'ingredient' ) );
                    if ( 'recipe' === $source_type ) {
                        $old_source = absint( $item['source_id'] ?? $item['recipe_id'] ?? 0 );
                        if ( isset( $recipe_map[ $old_source ] ) ) {
                            $item['source_id'] = $recipe_map[ $old_source ];
                            if ( isset( $item['recipe_id'] ) ) {
                                $item['recipe_id'] = $recipe_map[ $old_source ];
                            }
                        }
                    } else {
                        $old_source = absint( $item['source_id'] ?? $item['ingredient_id'] ?? 0 );
                        if ( isset( $ingredient_map[ $old_source ] ) ) {
                            $item['source_id'] = $ingredient_map[ $old_source ];
                            $item['ingredient_id'] = $ingredient_map[ $old_source ];
                        }
                    }
                }
                unset( $item );
            }
            if ( isset( $meta[ Persiano_Hub_Inventory::RECIPE_COMPONENT_LOTS ] ) && is_array( $meta[ Persiano_Hub_Inventory::RECIPE_COMPONENT_LOTS ] ) ) {
                foreach ( $meta[ Persiano_Hub_Inventory::RECIPE_COMPONENT_LOTS ] as &$lot ) {
                    if ( empty( $lot['inputs_consumed'] ) || ! is_array( $lot['inputs_consumed'] ) ) {
                        continue;
                    }
                    foreach ( array( 'ingredients' => $ingredient_map, 'components' => $recipe_map ) as $group => $id_map ) {
                        if ( empty( $lot['inputs_consumed'][ $group ] ) || ! is_array( $lot['inputs_consumed'][ $group ] ) ) {
                            continue;
                        }
                        $remapped = array();
                        foreach ( $lot['inputs_consumed'][ $group ] as $old_input_id => $details ) {
                            $old_input_id = absint( $old_input_id );
                            $new_input_id = isset( $id_map[ $old_input_id ] ) ? $id_map[ $old_input_id ] : $old_input_id;
                            $remapped[ $new_input_id ] = $details;
                        }
                        $lot['inputs_consumed'][ $group ] = $remapped;
                    }
                }
                unset( $lot );
            }
            $product = (array) ( $record['linked_product'] ?? array() );
            $mapped_product_id = 0;
            if ( ! empty( $product['sku'] ) ) {
                $mapped_product_id = wc_get_product_id_by_sku( sanitize_text_field( $product['sku'] ) );
            }
            if ( ! $mapped_product_id && ! empty( $product['name'] ) ) {
                $product_post = get_page_by_title( sanitize_text_field( $product['name'] ), OBJECT, 'product' );
                $mapped_product_id = $product_post ? (int) $product_post->ID : 0;
            }
            if ( $mapped_product_id ) {
                $meta[ Persiano_Hub_Costing::RECIPE_PRODUCT_ID ] = $mapped_product_id;
                update_post_meta( $mapped_product_id, Persiano_Hub_Costing::PRODUCT_RECIPE_META, $post_id );
            } else {
                unset( $meta[ Persiano_Hub_Costing::RECIPE_PRODUCT_ID ] );
            }
            self::import_meta( $post_id, $meta, self::recipe_meta_keys() );
            Persiano_Hub_Costing::recalculate_recipe( $post_id );
        }


        foreach ( (array) ( $data['production_plans'] ?? array() ) as $record ) {
            $old_plan_id = absint( $record['id'] ?? 0 );
            $title = sanitize_text_field( $record['title'] ?? '' );
            if ( ! $title ) {
                continue;
            }
            $post_id = $update_matching ? self::find_post_by_title( $title, Persiano_Hub_Operations::PLAN_POST_TYPE ) : 0;
            if ( $post_id ) {
                $updated++;
            } else {
                $post_id = wp_insert_post( array( 'post_type'=>Persiano_Hub_Operations::PLAN_POST_TYPE, 'post_status'=>'publish', 'post_title'=>$title ), true );
                if ( is_wp_error( $post_id ) ) {
                    continue;
                }
                $created++;
            }
            $plan_map[ $old_plan_id ] = (int) $post_id;
            $meta = (array) ( $record['meta'] ?? array() );
            if ( isset( $meta[ Persiano_Hub_Operations::META_PLAN_RECIPES ] ) && is_array( $meta[ Persiano_Hub_Operations::META_PLAN_RECIPES ] ) ) {
                foreach ( $meta[ Persiano_Hub_Operations::META_PLAN_RECIPES ] as &$row ) {
                    $old = absint( $row['recipe_id'] ?? 0 );
                    if ( isset( $recipe_map[ $old ] ) ) {
                        $row['recipe_id'] = $recipe_map[ $old ];
                    }
                }
                unset( $row );
            }
            if ( isset( $meta[ Persiano_Hub_Operations::META_PLAN_INGREDIENTS ] ) && is_array( $meta[ Persiano_Hub_Operations::META_PLAN_INGREDIENTS ] ) ) {
                foreach ( $meta[ Persiano_Hub_Operations::META_PLAN_INGREDIENTS ] as &$row ) {
                    $old = absint( $row['ingredient_id'] ?? 0 );
                    if ( isset( $ingredient_map[ $old ] ) ) {
                        $row['ingredient_id'] = $ingredient_map[ $old ];
                    }
                }
                unset( $row );
            }
            if ( isset( $meta[ Persiano_Hub_Operations::META_PLAN_COMPONENTS ] ) && is_array( $meta[ Persiano_Hub_Operations::META_PLAN_COMPONENTS ] ) ) {
                foreach ( $meta[ Persiano_Hub_Operations::META_PLAN_COMPONENTS ] as &$row ) {
                    $old = absint( $row['recipe_id'] ?? 0 );
                    if ( isset( $recipe_map[ $old ] ) ) {
                        $row['recipe_id'] = $recipe_map[ $old ];
                    }
                }
                unset( $row );
            }
            if ( isset( $meta[ Persiano_Hub_Inventory::PLAN_INVENTORY_APPLIED ]['results'] ) ) {
                $results = (array) $meta[ Persiano_Hub_Inventory::PLAN_INVENTORY_APPLIED ]['results'];
                foreach ( array( 'ingredients' => $ingredient_map, 'components' => $recipe_map ) as $group => $id_map ) {
                    if ( empty( $results[ $group ] ) || ! is_array( $results[ $group ] ) ) {
                        continue;
                    }
                    $remapped = array();
                    foreach ( $results[ $group ] as $old_result_id => $value ) {
                        $old_result_id = absint( $old_result_id );
                        $new_result_id = isset( $id_map[ $old_result_id ] ) ? $id_map[ $old_result_id ] : $old_result_id;
                        $remapped[ $new_result_id ] = $value;
                    }
                    $results[ $group ] = $remapped;
                }
                $meta[ Persiano_Hub_Inventory::PLAN_INVENTORY_APPLIED ]['results'] = $results;
            }
            self::import_meta( $post_id, $meta, self::production_plan_meta_keys() );
        }
        foreach ( (array) ( $data['shopping_lists'] ?? array() ) as $record ) {
            $title = sanitize_text_field( $record['title'] ?? '' );
            if ( ! $title ) {
                continue;
            }
            $post_id = $update_matching ? self::find_post_by_title( $title, Persiano_Hub_Operations::LIST_POST_TYPE ) : 0;
            if ( $post_id ) {
                $updated++;
            } else {
                $post_id = wp_insert_post( array( 'post_type'=>Persiano_Hub_Operations::LIST_POST_TYPE, 'post_status'=>'publish', 'post_title'=>$title ), true );
                if ( is_wp_error( $post_id ) ) {
                    continue;
                }
                $created++;
            }
            $meta = (array) ( $record['meta'] ?? array() );
            if ( isset( $meta[ Persiano_Hub_Operations::META_LIST_ITEMS ] ) && is_array( $meta[ Persiano_Hub_Operations::META_LIST_ITEMS ] ) ) {
                foreach ( $meta[ Persiano_Hub_Operations::META_LIST_ITEMS ] as &$row ) {
                    $old = absint( $row['ingredient_id'] ?? 0 );
                    if ( isset( $ingredient_map[ $old ] ) ) {
                        $row['ingredient_id'] = $ingredient_map[ $old ];
                    }
                }
                unset( $row );
            }
            if ( isset( $meta[ Persiano_Hub_Operations::META_LIST_SOURCE ] ) && is_array( $meta[ Persiano_Hub_Operations::META_LIST_SOURCE ] ) ) {
                $old_plan_id = absint( $meta[ Persiano_Hub_Operations::META_LIST_SOURCE ]['plan_id'] ?? 0 );
                if ( $old_plan_id && isset( $plan_map[ $old_plan_id ] ) ) {
                    $meta[ Persiano_Hub_Operations::META_LIST_SOURCE ]['plan_id'] = $plan_map[ $old_plan_id ];
                }
            }
            self::import_meta( $post_id, $meta, self::shopping_list_meta_keys() );
        }

        if ( class_exists( 'Persiano_Hub_Price_Feeds' ) ) {
            foreach ( (array) ( $data['price_sources'] ?? array() ) as $record ) {
                $title = sanitize_text_field( $record['title'] ?? '' );
                $meta = (array) ( $record['meta'] ?? array() );
                $url = esc_url_raw( $meta[ Persiano_Hub_Price_Feeds::META_URL ] ?? '' );
                if ( ! $url ) { continue; }
                $existing = 0;
                if ( $update_matching ) {
                    $found = get_posts( array( 'post_type'=>Persiano_Hub_Price_Feeds::POST_TYPE,'post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_key'=>Persiano_Hub_Price_Feeds::META_URL,'meta_value'=>$url ) );
                    $existing = $found ? (int) $found[0] : 0;
                }
                if ( $existing ) { $source_id = $existing; $updated++; }
                else {
                    $source_id = wp_insert_post( array( 'post_type'=>Persiano_Hub_Price_Feeds::POST_TYPE,'post_status'=>'publish','post_title'=>$title ?: $url ), true );
                    if ( is_wp_error( $source_id ) ) { continue; }
                    $created++;
                }
                $old_ingredient = absint( $meta[ Persiano_Hub_Price_Feeds::META_INGREDIENT_ID ] ?? 0 );
                if ( $old_ingredient && isset( $ingredient_map[ $old_ingredient ] ) ) { $meta[ Persiano_Hub_Price_Feeds::META_INGREDIENT_ID ] = $ingredient_map[ $old_ingredient ]; }
                $old_suggested = absint( $meta[ Persiano_Hub_Price_Feeds::META_SUGGESTED_INGREDIENT ] ?? 0 );
                if ( $old_suggested && isset( $ingredient_map[ $old_suggested ] ) ) { $meta[ Persiano_Hub_Price_Feeds::META_SUGGESTED_INGREDIENT ] = $ingredient_map[ $old_suggested ]; }
                foreach ( Persiano_Hub_Price_Feeds::meta_keys() as $key ) {
                    if ( array_key_exists( $key, $meta ) ) { update_post_meta( $source_id, $key, $meta[ $key ] ); }
                }
            }
        }

        wp_safe_redirect( self::dashboard_url( array( 'imported' => $created, 'updated' => $updated ) ) );
        exit;
    }

    public static function render_page() {
        self::require_permission();
        $export_json = wp_nonce_url( add_query_arg( array( 'action' => 'persiano_hub_export_costing_json' ), admin_url( 'admin-post.php' ) ), 'persiano_hub_export_costing_json' );
        $tables = array(
            'ingredients'  => __( 'Ingredients', 'persiano-hub' ),
            'price_history'=> __( 'Price history', 'persiano-hub' ),
            'recipes'      => __( 'Recipes', 'persiano-hub' ),
            'recipe_items' => __( 'Recipe ingredient lines', 'persiano-hub' ),
            'recipe_steps' => __( 'Recipe steps', 'persiano-hub' ),
            'inventory_history' => __( 'Raw ingredient inventory history', 'persiano-hub' ),
            'prepared_components' => __( 'Prepared component inventory summary', 'persiano-hub' ),
            'component_lots' => __( 'Prepared component lots', 'persiano-hub' ),
            'component_inventory_history' => __( 'Prepared component inventory history', 'persiano-hub' ),
            'product_inventory' => __( 'WooCommerce product inventory', 'persiano-hub' ),
            'production_plans' => __( 'Production plans', 'persiano-hub' ),
            'shopping_lists' => __( 'Shopping lists', 'persiano-hub' ),
            'price_sources' => __( 'Online price sources', 'persiano-hub' ),
        );
        $import_error = isset( $_GET['import_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['import_error'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $created = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Data portability', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'Import & Export', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Back up the complete Persiano costing and operations database, restore it, or export clean CSV tables for spreadsheets and analysis.', 'persiano-hub' ); ?></p></div></div>
        <?php if ( $import_error ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $import_error ); ?></p></div><?php endif; ?>
        <?php if ( $created || $updated ) : ?><div class="notice notice-success inline"><p><?php printf( esc_html__( 'Import completed: %1$d records created and %2$d matching records updated.', 'persiano-hub' ), $created, $updated ); ?></p></div><?php endif; ?>
        <section class="ph-costing-panel"><h2><?php esc_html_e( 'Full backup', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'JSON preserves relationships, price history, recipe steps, versions, raw inventory, prepared-component lots and histories, production allocations, online price sources, and costing settings. This is the recommended backup format.', 'persiano-hub' ); ?></p><p><a class="button button-primary" href="<?php echo esc_url( $export_json ); ?>"><?php esc_html_e( 'Download full JSON backup', 'persiano-hub' ); ?></a></p></section>
        <section class="ph-costing-panel"><h2><?php esc_html_e( 'CSV table exports', 'persiano-hub' ); ?></h2><div class="ph-costing-actions"><?php foreach ( $tables as $table => $label ) : $url = wp_nonce_url( add_query_arg( array( 'action' => 'persiano_hub_export_costing_csv', 'table' => $table ), admin_url( 'admin-post.php' ) ), 'persiano_hub_export_costing_csv' ); ?><a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></div></section>
        <section class="ph-costing-panel"><h2><?php esc_html_e( 'Restore / import full backup', 'persiano-hub' ); ?></h2><p><?php esc_html_e( 'Import a JSON backup created by Batchly. Ingredient and sub-recipe relationships are remapped automatically. Product links are matched by SKU, then product name.', 'persiano-hub' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data"><input type="hidden" name="action" value="persiano_hub_import_costing_json"><?php wp_nonce_field( 'persiano_hub_import_costing_json' ); ?><p><input type="file" name="persiano_costing_backup" accept="application/json,.json" required></p><p><label><input type="checkbox" name="persiano_update_matching" value="1" checked> <?php esc_html_e( 'Update existing ingredients/recipes with the same title instead of creating duplicates', 'persiano-hub' ); ?></label></p><?php submit_button( __( 'Import backup', 'persiano-hub' ), 'secondary', 'submit', false, array( 'onclick' => "return confirm('Import this Persiano costing backup?');" ) ); ?></form></section>
        <?php
    }
}
