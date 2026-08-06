<?php
/**
 * Canonical ingredient identity, aliases, duplicate review and safe merges.
 *
 * @package Persiano_Hub
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Ingredient_Master {
    const MENU_SLUG      = 'persiano-hub-ingredient-master';
    const META_CANONICAL = '_persiano_ing_canonical_id';
    const META_ALIASES   = '_persiano_ing_aliases';
    const META_FAMILY    = '_persiano_ing_family';
    const META_REVIEW    = '_persiano_ing_needs_review';
    const META_MERGED_TO = '_persiano_ing_merged_to';
    const OPTION_LOG     = 'persiano_hub_ingredient_merge_log';
    const OPTION_SCAN    = 'persiano_hub_ingredient_duplicate_scan';
    const META_PRICE_POLICY = '_persiano_ing_price_policy';
    const META_CONFLICT = '_persiano_ing_identity_conflict';
    const META_UNRESOLVED = '_persiano_ing_unresolved_purchase_data';
    const OPTION_REPAIR_LOG = 'persiano_hub_identity_repair_log';
    const OPTION_PACKAGE_REPAIR_LOG = 'persiano_hub_supplier_package_repair_log';
    const META_MERGED_ARCHIVED = '_persiano_ing_merged_archived';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 36 );
        add_action( 'add_meta_boxes_' . Persiano_Hub_Costing::INGREDIENT_POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_' . Persiano_Hub_Costing::INGREDIENT_POST_TYPE, array( __CLASS__, 'save_meta' ), 20, 3 );
        add_action( 'admin_init', array( __CLASS__, 'ensure_canonical_ids' ) );
        add_action( 'admin_init', array( __CLASS__, 'upgrade_price_records' ), 25 );
        add_action( 'admin_init', array( __CLASS__, 'scan_identity_conflicts' ), 28 );
        add_action( 'admin_init', array( __CLASS__, 'repair_supplier_package_links' ), 30 );
        add_action( 'admin_post_persiano_hub_scan_ingredient_duplicates', array( __CLASS__, 'handle_scan' ) );
        add_action( 'admin_post_persiano_hub_merge_ingredients', array( __CLASS__, 'handle_merge' ) );
        add_action( 'admin_post_persiano_hub_keep_ingredients_separate', array( __CLASS__, 'handle_keep_separate' ) );
        add_action( 'admin_post_persiano_hub_resolve_identity_conflict', array( __CLASS__, 'handle_resolve_identity_conflict' ) );
        add_action( 'admin_post_persiano_hub_rescan_identity_conflicts', array( __CLASS__, 'handle_rescan_identity_conflicts' ) );
        add_filter( 'manage_' . Persiano_Hub_Costing::INGREDIENT_POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
        add_action( 'manage_' . Persiano_Hub_Costing::INGREDIENT_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
    }

    public static function register_page() {
        add_submenu_page(
            'persiano-hub',
            __( 'Ingredient Master', 'persiano-hub' ),
            __( 'Ingredient Master', 'persiano-hub' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function add_meta_box() {
        add_meta_box(
            'persiano-ingredient-identity',
            __( 'Ingredient identity & aliases', 'persiano-hub' ),
            array( __CLASS__, 'render_meta_box' ),
            Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'persiano_ingredient_identity_' . $post->ID, 'persiano_ingredient_identity_nonce' );
        $canonical = self::canonical_id( $post->ID );
        $aliases   = self::aliases( $post->ID );
        $family    = (string) get_post_meta( $post->ID, self::META_FAMILY, true );
        $review    = (bool) get_post_meta( $post->ID, self::META_REVIEW, true );
        ?>
        <p><label><strong><?php esc_html_e( 'Canonical ingredient ID', 'persiano-hub' ); ?></strong></label><br>
        <input class="widefat" type="text" value="<?php echo esc_attr( $canonical ); ?>" readonly></p>
        <p class="description"><?php esc_html_e( 'This permanent ID is used by recipes and imports even when the displayed name changes.', 'persiano-hub' ); ?></p>
        <p><label for="persiano_ing_aliases"><strong><?php esc_html_e( 'Aliases / alternate names', 'persiano-hub' ); ?></strong></label><br>
        <textarea class="widefat" rows="5" id="persiano_ing_aliases" name="persiano_ing_aliases" placeholder="Onion, Yellow&#10;Yellow onions&#10;پیاز زرد"><?php echo esc_textarea( implode( "\n", $aliases ) ); ?></textarea></p>
        <p class="description"><?php esc_html_e( 'Enter one alias per line. Imports may match any of these names.', 'persiano-hub' ); ?></p>
        <p><label for="persiano_ing_family"><strong><?php esc_html_e( 'Ingredient family', 'persiano-hub' ); ?></strong></label><br>
        <input class="widefat" type="text" id="persiano_ing_family" name="persiano_ing_family" value="<?php echo esc_attr( $family ); ?>" placeholder="Onion"></p>
        <?php $policy = (string) get_post_meta( $post->ID, self::META_PRICE_POLICY, true ); if ( ! $policy ) { $policy = 'latest_approved'; } ?>
        <p><label for="persiano_ing_price_policy"><strong><?php esc_html_e( 'Current-cost policy', 'persiano-hub' ); ?></strong></label><br>
        <select class="widefat" id="persiano_ing_price_policy" name="persiano_ing_price_policy">
            <option value="latest_approved" <?php selected( $policy, 'latest_approved' ); ?>><?php esc_html_e( 'Latest approved purchase', 'persiano-hub' ); ?></option>
            <option value="preferred_supplier" <?php selected( $policy, 'preferred_supplier' ); ?>><?php esc_html_e( 'Preferred supplier item', 'persiano-hub' ); ?></option>
            <option value="weighted_average" <?php selected( $policy, 'weighted_average' ); ?>><?php esc_html_e( 'Weighted average of approved history', 'persiano-hub' ); ?></option>
            <option value="manual" <?php selected( $policy, 'manual' ); ?>><?php esc_html_e( 'Manual fixed cost', 'persiano-hub' ); ?></option>
        </select></p>
        <p><label><input type="checkbox" name="persiano_ing_needs_review" value="1" <?php checked( $review ); ?>> <?php esc_html_e( 'Needs identity review', 'persiano-hub' ); ?></label></p>
        <?php $conflict = get_post_meta( $post->ID, self::META_CONFLICT, true ); if ( is_array( $conflict ) && $conflict ) : ?>
        <div class="notice notice-error inline" style="padding:8px 10px;margin:10px 0"><strong><?php esc_html_e( 'Identity conflict detected', 'persiano-hub' ); ?></strong><br><?php echo esc_html( $conflict['summary'] ?? '' ); ?><br><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=identity_repair&ingredient_id=' . $post->ID ) ); ?>"><?php esc_html_e( 'Open controlled repair', 'persiano-hub' ); ?></a></div>
        <?php endif; ?>
        <?php
    }

    public static function save_meta( $post_id, $post, $update ) {
        if ( ! isset( $_POST['persiano_ingredient_identity_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_ingredient_identity_nonce'] ) ), 'persiano_ingredient_identity_' . $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) || wp_is_post_revision( $post_id ) ) { return; }
        self::ensure_canonical_id( $post_id );
        $aliases = isset( $_POST['persiano_ing_aliases'] ) ? preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['persiano_ing_aliases'] ) ) : array();
        self::set_aliases( $post_id, $aliases );
        update_post_meta( $post_id, self::META_FAMILY, isset( $_POST['persiano_ing_family'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_ing_family'] ) ) : '' );
        update_post_meta( $post_id, self::META_REVIEW, isset( $_POST['persiano_ing_needs_review'] ) ? 1 : 0 );
        $policy = sanitize_key( $_POST['persiano_ing_price_policy'] ?? 'latest_approved' );
        if ( ! in_array( $policy, array( 'latest_approved', 'preferred_supplier', 'weighted_average', 'manual' ), true ) ) { $policy = 'latest_approved'; }
        update_post_meta( $post_id, self::META_PRICE_POLICY, $policy );
        self::backfill_supplier_packages( $post_id );
        self::repair_and_apply_current_cost( $post_id );
    }


    /** Normalize supplier/import unit labels to costing units. */
    public static function normalize_price_unit( $unit ) {
        $unit = strtolower( trim( wp_strip_all_tags( (string) $unit ) ) );
        $unit = str_replace( array( '.', '_', '-' ), ' ', $unit );
        $unit = preg_replace( '/\s+/', ' ', $unit );
        $map = array(
            'g'=>'g','gram'=>'g','grams'=>'g','gr'=>'g',
            'kg'=>'kg','kgs'=>'kg','kilogram'=>'kg','kilograms'=>'kg','kilo'=>'kg','kilos'=>'kg',
            'oz'=>'oz','ounce'=>'oz','ounces'=>'oz',
            'lb'=>'lb','lbs'=>'lb','pound'=>'lb','pounds'=>'lb',
            'ml'=>'ml','milliliter'=>'ml','milliliters'=>'ml','millilitre'=>'ml','millilitres'=>'ml',
            'l'=>'l','lt'=>'l','liter'=>'l','liters'=>'l','litre'=>'l','litres'=>'l',
            'tsp'=>'tsp','teaspoon'=>'tsp','teaspoons'=>'tsp',
            'tbsp'=>'tbsp','tablespoon'=>'tbsp','tablespoons'=>'tbsp',
            'cup'=>'cup','cups'=>'cup',
            'each'=>'each','ea'=>'each','unit'=>'each','units'=>'each','piece'=>'each','pieces'=>'each','pc'=>'each','pcs'=>'each',
            'dozen'=>'dozen','doz'=>'dozen',
        );
        return isset( $map[ $unit ] ) ? $map[ $unit ] : sanitize_key( $unit );
    }

    /** Return base unit and multiplier for a purchasable package unit. */
    public static function price_unit_conversion( $unit ) {
        $unit = self::normalize_price_unit( $unit );
        $map = array(
            'g'=>array('g',1.0),'kg'=>array('g',1000.0),'oz'=>array('g',28.349523125),'lb'=>array('g',453.59237),
            'ml'=>array('ml',1.0),'l'=>array('ml',1000.0),'tsp'=>array('ml',5.0),'tbsp'=>array('ml',15.0),'cup'=>array('ml',250.0),
            'each'=>array('each',1.0),'dozen'=>array('each',12.0),
        );
        return isset( $map[ $unit ] ) ? $map[ $unit ] : array( '', 0.0 );
    }

    /** Normalize a price-history row, deriving unit cost when package data is complete. */
    public static function normalize_history_entry( $entry ) {
        $entry = is_array( $entry ) ? $entry : array();
        $entry['purchase_qty']  = max( 0, (float) ( $entry['purchase_qty'] ?? 0 ) );
        $entry['purchase_unit'] = self::normalize_price_unit( $entry['purchase_unit'] ?? '' );
        $entry['net_cost']      = max( 0, (float) ( $entry['net_cost'] ?? $entry['purchase_cost'] ?? 0 ) );
        $entry['tax']           = max( 0, (float) ( $entry['tax'] ?? $entry['purchase_tax'] ?? 0 ) );
        $entry['gross_cost']    = max( 0, (float) ( $entry['gross_cost'] ?? 0 ) );
        if ( $entry['gross_cost'] <= 0 ) { $entry['gross_cost'] = $entry['net_cost'] + $entry['tax']; }
        $entry['approved'] = isset( $entry['approved'] ) ? ( $entry['approved'] ? 1 : 0 ) : 1;
        list( $base, $multiplier ) = self::price_unit_conversion( $entry['purchase_unit'] );
        $base_qty = $entry['purchase_qty'] * $multiplier;
        if ( (float) ( $entry['unit_cost'] ?? 0 ) <= 0 && $base_qty > 0 && $entry['gross_cost'] > 0 ) {
            $entry['unit_cost'] = $entry['gross_cost'] / $base_qty;
        } else {
            $entry['unit_cost'] = max( 0, (float) ( $entry['unit_cost'] ?? 0 ) );
        }
        if ( empty( $entry['base_unit'] ) && $base ) { $entry['base_unit'] = $base; }
        $entry['base_unit'] = self::normalize_price_unit( $entry['base_unit'] ?? '' );
        if ( ! in_array( $entry['base_unit'], array( 'g','ml','each' ), true ) ) { $entry['base_unit'] = $base; }
        $entry['normalization_status'] = ( $entry['unit_cost'] > 0 && in_array( $entry['base_unit'], array('g','ml','each'), true ) ) ? 'ready' : 'needs_review';
        return $entry;
    }

    /** Repair saved history and apply the ingredient's current-cost policy. */
    public static function repair_and_apply_current_cost( $ingredient_id ) {
        $ingredient_id = absint( $ingredient_id );
        if ( ! $ingredient_id || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $ingredient_id ) ) { return false; }
        $history = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, true );
        $history = is_array( $history ) ? $history : array();
        $changed = false;
        foreach ( $history as $i => $entry ) {
            $normalized = self::normalize_history_entry( $entry );
            if ( $normalized != $entry ) { $history[$i] = $normalized; $changed = true; }
        }
        if ( $changed ) { update_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, $history ); }

        $policy = (string) get_post_meta( $ingredient_id, self::META_PRICE_POLICY, true );
        if ( ! $policy ) { $policy = 'latest_approved'; }
        if ( 'manual' === $policy ) { return true; }
        $chosen_cost = 0.0; $chosen_unit = '';
        $valid = array_values( array_filter( $history, static function( $h ) {
            return ! empty( $h['approved'] ) && (float) ( $h['unit_cost'] ?? 0 ) > 0 && in_array( $h['base_unit'] ?? '', array('g','ml','each'), true );
        } ) );
        if ( 'weighted_average' === $policy && $valid ) {
            $groups = array();
            foreach ( $valid as $h ) {
                $unit = $h['base_unit'];
                list( $base, $mult ) = self::price_unit_conversion( $h['purchase_unit'] ?? '' );
                $weight = max( 0.000001, (float) ( $h['purchase_qty'] ?? 0 ) * $mult );
                if ( $base !== $unit ) { $weight = 1; }
                if ( ! isset( $groups[$unit] ) ) { $groups[$unit] = array('sum'=>0.0,'weight'=>0.0,'latest'=>0); }
                $groups[$unit]['sum'] += (float)$h['unit_cost'] * $weight;
                $groups[$unit]['weight'] += $weight;
                $groups[$unit]['latest'] = max( $groups[$unit]['latest'], (int)($h['time']??0) );
            }
            uasort( $groups, static function($a,$b){ return $b['latest'] <=> $a['latest']; } );
            $chosen_unit = (string) key( $groups ); $g = reset( $groups );
            $chosen_cost = $g['weight'] > 0 ? $g['sum'] / $g['weight'] : 0;
        } elseif ( 'preferred_supplier' === $policy ) {
            $items = (array) get_post_meta( $ingredient_id, '_persiano_supplier_items', true );
            foreach ( $items as $item ) {
                if ( isset($item['active']) && ! $item['active'] ) { continue; }
                $cost=(float)($item['normalized_unit_cost']??0); $unit=self::normalize_price_unit($item['normalized_unit']??'');
                if($cost>0 && in_array($unit,array('g','ml','each'),true)){ $chosen_cost=$cost; $chosen_unit=$unit; break; }
            }
            if ( $chosen_cost <= 0 && $valid ) { $policy = 'latest_approved'; }
        }
        if ( 'latest_approved' === $policy && $valid ) {
            usort( $valid, static function($a,$b){ return (int)($b['time']??0) <=> (int)($a['time']??0); } );
            $chosen_cost = (float)$valid[0]['unit_cost']; $chosen_unit = (string)$valid[0]['base_unit'];
        }
        if ( $chosen_cost > 0 && $chosen_unit ) {
            update_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_UNIT_COST, $chosen_cost );
            update_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BASE_UNIT, $chosen_unit );
            delete_post_meta( $ingredient_id, '_persiano_pricing_needed' );
            update_post_meta( $ingredient_id, '_persiano_active_price_status', 'ready' );
        } else {
            delete_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_UNIT_COST );
            delete_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BASE_UNIT );
            update_post_meta( $ingredient_id, '_persiano_pricing_needed', 1 );
            update_post_meta( $ingredient_id, '_persiano_active_price_status', 'needs_review' );
        }
        return true;
    }

    /** One-time repair for imported price histories from earlier releases. */
    public static function upgrade_price_records() {
        if ( ! current_user_can( 'manage_woocommerce' ) || get_option( 'persiano_hub_price_normalization_version' ) === PERSIANO_HUB_VERSION ) { return; }
        $ids = get_posts( array('post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true) );
        foreach ( $ids as $id ) { self::repair_and_apply_current_cost( $id ); }
        if ( method_exists( 'Persiano_Hub_Costing', 'recalculate_recipe' ) ) {
            $recipes = get_posts( array('post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true) );
            foreach ( $recipes as $recipe_id ) { Persiano_Hub_Costing::recalculate_recipe( $recipe_id ); }
        }
        update_option( 'persiano_hub_price_normalization_version', PERSIANO_HUB_VERSION, false );
    }


    /**
     * Normalize supplier-package rows to a list and preserve only arrays.
     *
     * @param mixed $stored Stored post meta.
     * @return array
     */
    public static function supplier_item_rows( $stored ) {
        if ( ! is_array( $stored ) || ! $stored ) { return array(); }
        $keys = array_keys( $stored );
        if ( $keys !== range( 0, count( $stored ) - 1 ) ) { $stored = array( $stored ); }
        return array_values( array_filter( $stored, 'is_array' ) );
    }

    /** Build a strict equivalence key for supplier packages on one ingredient. */
    private static function supplier_item_key( $item ) {
        $item = is_array( $item ) ? $item : array();
        $supplier_id = absint( $item['supplier_id'] ?? 0 );
        $supplier = strtolower( trim( sanitize_text_field( $item['supplier_name'] ?? $item['supplier'] ?? $item['vendor'] ?? '' ) ) );
        $code = strtolower( trim( sanitize_text_field( $item['supplier_item_code'] ?? $item['sku'] ?? $item['barcode'] ?? '' ) ) );
        $brand = strtolower( trim( sanitize_text_field( $item['brand'] ?? '' ) ) );
        $qty = round( max( 0, (float) ( $item['package_quantity'] ?? $item['purchase_qty'] ?? 0 ) ), 6 );
        $unit = self::normalize_price_unit( $item['package_unit'] ?? $item['purchase_unit'] ?? '' );
        // Quantity/unit identity wins over optional codes so a current-field package
        // and the same historical package consolidate rather than duplicate.
        if ( $qty > 0 && $unit ) { return 'package|' . $supplier_id . '|' . $supplier . '|' . $brand . '|' . $qty . '|' . $unit; }
        if ( $supplier_id && $code ) { return 'idcode|' . $supplier_id . '|' . $code; }
        if ( $supplier && $code ) { return 'namecode|' . $supplier . '|' . $code; }
        return 'incomplete|' . $supplier_id . '|' . $supplier . '|' . $brand . '|' . $code;
    }

    /** Merge package rows without creating an equivalent duplicate. */
    public static function consolidate_supplier_items( $rows ) {
        $out = array(); $index = array();
        foreach ( self::supplier_item_rows( $rows ) as $row ) {
            $key = self::supplier_item_key( $row );
            if ( isset( $index[ $key ] ) ) {
                $i = $index[ $key ];
                foreach ( $row as $field => $value ) {
                    if ( ( ! isset( $out[$i][$field] ) || '' === $out[$i][$field] || null === $out[$i][$field] ) && '' !== $value && null !== $value ) {
                        $out[$i][$field] = $value;
                    }
                }
                // Keep the newest useful price data when equivalent packages collide.
                if ( (float) ( $row['current_price'] ?? 0 ) > 0 ) {
                    $out[$i]['current_price'] = (float) $row['current_price'];
                    $out[$i]['tax_amount'] = (float) ( $row['tax_amount'] ?? 0 );
                    $out[$i]['normalized_unit_cost'] = (float) ( $row['normalized_unit_cost'] ?? $out[$i]['normalized_unit_cost'] ?? 0 );
                    $out[$i]['normalized_unit'] = sanitize_key( $row['normalized_unit'] ?? $out[$i]['normalized_unit'] ?? '' );
                }
                continue;
            }
            if ( empty( $row['record_id'] ) ) { $row['record_id'] = wp_generate_uuid4(); }
            $row['package_quantity'] = max( 0, (float) ( $row['package_quantity'] ?? $row['purchase_qty'] ?? 0 ) );
            $row['package_unit'] = self::normalize_price_unit( $row['package_unit'] ?? $row['purchase_unit'] ?? '' );
            $index[$key] = count( $out );
            $out[] = $row;
        }
        return array_values( $out );
    }

    /** Return true when this ingredient should never require a supplier package. */
    public static function is_non_purchasable_ingredient( $ingredient_id ) {
        $type = sanitize_key( get_post_meta( $ingredient_id, '_persiano_ingredient_type', true ) );
        if ( in_array( $type, array( 'process', 'garnish' ), true ) ) { return true; }
        $name = strtolower( trim( remove_accents( get_the_title( $ingredient_id ) ) ) );
        return in_array( $name, array( 'water', 'boiling water', 'ice water', 'steam' ), true );
    }

    /** Return true when a supplier name contains meaningful data. */
    private static function valid_supplier_name( $supplier ) {
        $supplier = trim( sanitize_text_field( (string) $supplier ) );
        return '' !== $supplier && ! in_array( strtolower( $supplier ), array( '-', 'n/a', 'na', 'unknown', 'none' ), true );
    }

    /** Normalize one package row and calculate its unit cost where possible. */
    private static function normalize_supplier_item( $row, $ingredient_id ) {
        $row = is_array( $row ) ? $row : array();
        if ( empty( $row['record_id'] ) ) { $row['record_id'] = wp_generate_uuid4(); }
        $row['supplier_id'] = absint( $row['supplier_id'] ?? 0 );
        $row['supplier_name'] = sanitize_text_field( $row['supplier_name'] ?? $row['supplier'] ?? $row['vendor'] ?? '' );
        $row['brand'] = sanitize_text_field( $row['brand'] ?? '' );
        $row['item_name'] = sanitize_text_field( $row['item_name'] ?? get_the_title( $ingredient_id ) );
        $row['package_quantity'] = max( 0, (float) ( $row['package_quantity'] ?? $row['purchase_qty'] ?? 0 ) );
        $row['package_unit'] = self::normalize_price_unit( $row['package_unit'] ?? $row['purchase_unit'] ?? '' );
        $row['current_price'] = max( 0, (float) ( $row['current_price'] ?? $row['net_cost'] ?? $row['purchase_cost'] ?? 0 ) );
        $row['tax_amount'] = max( 0, (float) ( $row['tax_amount'] ?? $row['tax'] ?? $row['purchase_tax'] ?? 0 ) );
        list( $base, $multiplier ) = self::price_unit_conversion( $row['package_unit'] );
        $base_qty = $row['package_quantity'] * $multiplier;
        if ( $base_qty > 0 && ( $row['current_price'] + $row['tax_amount'] ) > 0 ) {
            $row['normalized_unit_cost'] = ( $row['current_price'] + $row['tax_amount'] ) / $base_qty;
            $row['normalized_unit'] = $base;
        } else {
            $row['normalized_unit_cost'] = max( 0, (float) ( $row['normalized_unit_cost'] ?? 0 ) );
            $row['normalized_unit'] = self::normalize_price_unit( $row['normalized_unit'] ?? '' );
        }
        $row['active'] = isset( $row['active'] ) ? ( $row['active'] ? 1 : 0 ) : 1;
        return $row;
    }

    /** Build a supplier package from the ingredient's current purchase fields. */
    private static function package_from_current_fields( $ingredient_id ) {
        $supplier = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_SUPPLIER, true );
        $qty = (float) get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_QTY, true );
        $unit = self::normalize_price_unit( get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_UNIT, true ) );
        $price = (float) get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_COST, true );
        $tax = (float) get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_TAX, true );
        if ( ! self::valid_supplier_name( $supplier ) || $qty <= 0 || ! $unit || ( $price + $tax ) <= 0 ) { return array(); }
        return self::normalize_supplier_item( array(
            'record_id' => wp_generate_uuid4(),
            'supplier_name' => $supplier,
            'brand' => get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BRAND, true ),
            'item_name' => get_the_title( $ingredient_id ),
            'package_quantity' => $qty,
            'package_unit' => $unit,
            'current_price' => $price,
            'tax_amount' => $tax,
            'active' => 1,
            'repair_source' => 'current_purchase_fields',
            'notes' => __( 'Rebuilt automatically from the ingredient purchase fields.', 'persiano-hub' ),
        ), $ingredient_id );
    }

    /** Return normalized supplier-package rows used by health, shopping and repair. */
    public static function supplier_packages( $ingredient_id, $valid_only = false ) {
        $rows = self::supplier_item_rows( get_post_meta( absint( $ingredient_id ), '_persiano_supplier_items', true ) );
        $out = array();
        foreach ( $rows as $row ) {
            $row = self::normalize_supplier_item( $row, absint( $ingredient_id ) );
            $valid = self::valid_supplier_name( $row['supplier_name'] ?? '' )
                && (float) ( $row['package_quantity'] ?? 0 ) > 0
                && '' !== self::normalize_price_unit( $row['package_unit'] ?? '' )
                && ( (float) ( $row['current_price'] ?? 0 ) + (float) ( $row['tax_amount'] ?? 0 ) ) > 0;
            $row['_persiano_valid'] = $valid ? 1 : 0;
            if ( ! $valid_only || $valid ) { $out[] = $row; }
        }
        return self::consolidate_supplier_items( $out );
    }

    /** Explain exactly why a purchasable ingredient has no valid supplier package. */
    public static function supplier_package_issue( $ingredient_id ) {
        $ingredient_id = absint( $ingredient_id );
        if ( get_post_meta( $ingredient_id, self::META_CONFLICT, true ) || get_post_meta( $ingredient_id, self::META_REVIEW, true ) ) {
            return __( 'Identity review required before package repair', 'persiano-hub' );
        }
        if ( get_post_meta( $ingredient_id, self::META_UNRESOLVED, true ) ) {
            return __( 'Purchase assignment pending', 'persiano-hub' );
        }
        if ( self::supplier_packages( $ingredient_id, true ) ) { return ''; }
        $supplier = trim( (string) get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_SUPPLIER, true ) );
        $qty = (float) get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_QTY, true );
        $unit = self::normalize_price_unit( get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_UNIT, true ) );
        $price = (float) get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_COST, true );
        $tax = (float) get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_PURCHASE_TAX, true );
        if ( ! self::valid_supplier_name( $supplier ) ) { return __( 'Supplier / price source missing', 'persiano-hub' ); }
        if ( $qty <= 0 ) { return __( 'Package quantity missing', 'persiano-hub' ); }
        if ( ! $unit ) { return __( 'Package unit missing', 'persiano-hub' ); }
        if ( ( $price + $tax ) <= 0 ) { return __( 'Package price missing', 'persiano-hub' ); }
        return __( 'Package record missing despite complete data', 'persiano-hub' );
    }

    /**
     * Rebuild missing supplier-package links from current fields and approved history.
     * Matching is scoped to the ingredient ID; names are never loosely remapped.
     */
    public static function backfill_supplier_packages( $ingredient_id ) {
        $ingredient_id = absint( $ingredient_id );
        if ( ! $ingredient_id || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $ingredient_id ) ) { return 0; }
        if ( get_post_meta( $ingredient_id, self::META_MERGED_TO, true ) || self::is_non_purchasable_ingredient( $ingredient_id ) ) { return 0; }
        if ( get_post_meta( $ingredient_id, self::META_CONFLICT, true ) || get_post_meta( $ingredient_id, self::META_REVIEW, true ) || get_post_meta( $ingredient_id, self::META_UNRESOLVED, true ) ) { return 0; }

        $rows = self::supplier_item_rows( get_post_meta( $ingredient_id, '_persiano_supplier_items', true ) );
        $normalized_rows = array();
        foreach ( $rows as $row ) { $normalized_rows[] = self::normalize_supplier_item( $row, $ingredient_id ); }
        $rows = self::consolidate_supplier_items( $normalized_rows );
        $known = array(); foreach ( $rows as $row ) { $known[ self::supplier_item_key( $row ) ] = true; }
        $created = 0;

        $current = self::package_from_current_fields( $ingredient_id );
        if ( $current ) {
            $key = self::supplier_item_key( $current );
            if ( ! isset( $known[ $key ] ) ) { $rows[] = $current; $known[$key] = true; $created++; }
        }

        $history = get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_HISTORY, true );
        $history = is_array( $history ) ? $history : array();
        foreach ( $history as $purchase ) {
            $purchase = self::normalize_history_entry( $purchase );
            if ( empty( $purchase['approved'] ) ) { continue; }
            $supplier_id = absint( $purchase['supplier_id'] ?? 0 );
            $supplier = sanitize_text_field( $purchase['supplier'] ?? $purchase['supplier_name'] ?? $purchase['vendor'] ?? '' );
            $qty = max( 0, (float) ( $purchase['purchase_qty'] ?? 0 ) );
            $unit = self::normalize_price_unit( $purchase['purchase_unit'] ?? '' );
            $price = max( 0, (float) ( $purchase['net_cost'] ?? $purchase['purchase_cost'] ?? 0 ) );
            $gross = max( 0, (float) ( $purchase['gross_cost'] ?? 0 ) );
            if ( ! ( $supplier_id || self::valid_supplier_name( $supplier ) ) || $qty <= 0 || ! $unit || ( $price <= 0 && $gross <= 0 ) ) { continue; }
            $row = self::normalize_supplier_item( array(
                'record_id' => wp_generate_uuid4(), 'supplier_id' => $supplier_id, 'supplier_name' => $supplier,
                'supplier_item_code' => sanitize_text_field( $purchase['supplier_item_code'] ?? '' ),
                'brand' => sanitize_text_field( $purchase['brand'] ?? get_post_meta( $ingredient_id, Persiano_Hub_Costing::ING_BRAND, true ) ),
                'item_name' => get_the_title( $ingredient_id ), 'package_quantity' => $qty, 'package_unit' => $unit,
                'current_price' => $price > 0 ? $price : max( 0, $gross - (float) ( $purchase['tax'] ?? 0 ) ),
                'tax_amount' => max( 0, (float) ( $purchase['tax'] ?? $purchase['purchase_tax'] ?? 0 ) ),
                'product_url' => esc_url_raw( $purchase['product_url'] ?? '' ), 'active' => 1,
                'notes' => trim( sanitize_textarea_field( $purchase['notes'] ?? '' ) . "\n" . __( 'Rebuilt automatically from existing purchase history.', 'persiano-hub' ) ),
                'source_purchase_id' => sanitize_text_field( $purchase['record_id'] ?? '' ), 'repair_source' => 'purchase_history',
            ), $ingredient_id );
            $key = self::supplier_item_key( $row );
            if ( isset( $known[$key] ) ) { continue; }
            $rows[] = $row; $known[$key] = true; $created++;
        }
        update_post_meta( $ingredient_id, '_persiano_supplier_items', self::consolidate_supplier_items( $rows ) );
        self::repair_and_apply_current_cost( $ingredient_id );
        return $created;
    }

    /** Merge price history without duplicating the same purchase observation. */
    private static function consolidate_history( $rows ) {
        $out = array(); $seen = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $row = self::normalize_history_entry( $row );
            $key = implode( '|', array(
                sanitize_text_field( $row['record_id'] ?? '' ), (int) ( $row['time'] ?? 0 ),
                strtolower( sanitize_text_field( $row['supplier'] ?? '' ) ), round( (float) $row['purchase_qty'], 6 ),
                $row['purchase_unit'], round( (float) $row['gross_cost'], 6 ),
            ) );
            if ( isset( $seen[$key] ) ) { continue; }
            $seen[$key] = true; $out[] = $row;
        }
        return $out;
    }

    /** Controlled, repeatable reconciliation of merged records and supplier packages. */
    public static function repair_supplier_package_links() {
        if ( ! current_user_can( 'manage_woocommerce' ) || get_option( 'persiano_hub_supplier_package_repair_version' ) === PERSIANO_HUB_VERSION ) { return; }
        $report = array( 'version'=>PERSIANO_HUB_VERSION, 'started'=>time(), 'scanned'=>0, 'packages_created'=>0, 'packages_reused'=>0, 'merged_transferred'=>0, 'merged_ignored'=>0, 'process_ignored'=>0, 'identity_skipped'=>0, 'insufficient_data'=>0, 'completed'=>0 );
        $ids = get_posts( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_status'=>array('publish','draft','private'), 'posts_per_page'=>-1, 'fields'=>'ids', 'no_found_rows'=>true ) );
        foreach ( $ids as $id ) {
            $report['scanned']++;
            $merged_to = absint( get_post_meta( $id, self::META_MERGED_TO, true ) );
            if ( $merged_to && Persiano_Hub_Costing::INGREDIENT_POST_TYPE === get_post_type( $merged_to ) ) {
                $target_rows = self::supplier_item_rows( get_post_meta( $merged_to, '_persiano_supplier_items', true ) );
                $source_rows = self::supplier_item_rows( get_post_meta( $id, '_persiano_supplier_items', true ) );
                $before = count( self::consolidate_supplier_items( $target_rows ) );
                $combined = self::consolidate_supplier_items( array_merge( $target_rows, $source_rows ) );
                update_post_meta( $merged_to, '_persiano_supplier_items', $combined );
                $report['merged_transferred'] += max( 0, count( $combined ) - $before );

                $target_history = get_post_meta( $merged_to, Persiano_Hub_Costing::ING_HISTORY, true );
                $source_history = get_post_meta( $id, Persiano_Hub_Costing::ING_HISTORY, true );
                update_post_meta( $merged_to, Persiano_Hub_Costing::ING_HISTORY, self::consolidate_history( array_merge( is_array($target_history)?$target_history:array(), is_array($source_history)?$source_history:array() ) ) );
                // Preserve useful current purchase fields when the canonical record is blank.
                foreach ( array( Persiano_Hub_Costing::ING_SUPPLIER, Persiano_Hub_Costing::ING_BRAND, Persiano_Hub_Costing::ING_PURCHASE_QTY, Persiano_Hub_Costing::ING_PURCHASE_UNIT, Persiano_Hub_Costing::ING_PURCHASE_COST, Persiano_Hub_Costing::ING_PURCHASE_TAX, Persiano_Hub_Costing::ING_GROSS_COST, Persiano_Hub_Costing::ING_WASTE_PCT ) as $meta_key ) {
                    $target_value = get_post_meta( $merged_to, $meta_key, true );
                    $source_value = get_post_meta( $id, $meta_key, true );
                    if ( ( '' === $target_value || null === $target_value ) && '' !== $source_value && null !== $source_value ) { update_post_meta( $merged_to, $meta_key, $source_value ); }
                }
                update_post_meta( $id, self::META_MERGED_ARCHIVED, 1 );
                $report['packages_created'] += self::backfill_supplier_packages( $merged_to );
                self::repair_and_apply_current_cost( $merged_to );
                $report['merged_ignored']++;
                continue;
            }
            if ( self::is_non_purchasable_ingredient( $id ) ) { $report['process_ignored']++; continue; }
            if ( get_post_meta( $id, self::META_CONFLICT, true ) || get_post_meta( $id, self::META_REVIEW, true ) || get_post_meta( $id, self::META_UNRESOLVED, true ) ) { $report['identity_skipped']++; continue; }
            $before = count( self::supplier_item_rows( get_post_meta( $id, '_persiano_supplier_items', true ) ) );
            $created = self::backfill_supplier_packages( $id );
            $after = count( self::supplier_item_rows( get_post_meta( $id, '_persiano_supplier_items', true ) ) );
            $report['packages_created'] += $created;
            if ( 0 === $created && $after > 0 ) { $report['packages_reused']++; }
            if ( 0 === $after ) { $report['insufficient_data']++; }
        }
        $report['completed'] = time();
        update_option( self::OPTION_PACKAGE_REPAIR_LOG, $report, false );
        update_option( 'persiano_hub_supplier_package_repair_version', PERSIANO_HUB_VERSION, false );
        update_option( 'persiano_hub_schema_consistency_audit', array(
            'plugin_version'=>PERSIANO_HUB_VERSION, 'db_version'=>get_option('persiano_hub_db_version','0'),
            'recipe_cost_formula_version'=>get_option('persiano_hub_recipe_cost_formula_version','0'),
            'price_normalization_version'=>get_option('persiano_hub_price_normalization_version','0'),
            'supplier_package_repair_version'=>PERSIANO_HUB_VERSION, 'checked_at'=>time(),
        ), false );
    }


    /** Return a strong food-family token used only to identify obvious contradictions. */
    private static function identity_group( $text ) {
        $text = strtolower( remove_accents( wp_strip_all_tags( (string) $text ) ) );
        $groups = array(
            'tomato' => array( 'tomato', 'tomatoes' ),
            'onion' => array( 'onion', 'onions', 'shallot' ),
            'garlic' => array( 'garlic' ),
            'pepper' => array( 'pepper', 'peppers', 'capsicum' ),
            'potato' => array( 'potato', 'potatoes' ),
            'rice' => array( 'rice' ),
            'flour' => array( 'flour' ),
            'sugar' => array( 'sugar' ),
            'salt' => array( 'salt' ),
            'egg' => array( 'egg', 'eggs' ),
            'walnut' => array( 'walnut', 'walnuts' ),
            'herb' => array( 'cilantro', 'coriander', 'parsley', 'dill', 'mint' ),
        );
        foreach ( $groups as $group => $words ) {
            foreach ( $words as $word ) {
                if ( preg_match( '/(^|[^a-z])' . preg_quote( $word, '/' ) . '([^a-z]|$)/', $text ) ) { return $group; }
            }
        }
        return '';
    }

    /** Detect only strong identity contradictions; ambiguous records are not guessed. */
    public static function detect_identity_conflict( $ingredient_id ) {
        $name = get_the_title( $ingredient_id );
        $family = (string) get_post_meta( $ingredient_id, self::META_FAMILY, true );
        $aliases = self::aliases( $ingredient_id );
        $name_group = self::identity_group( $name );
        $family_group = self::identity_group( $family );
        $alias_groups = array_values( array_filter( array_unique( array_map( array( __CLASS__, 'identity_group' ), $aliases ) ) ) );
        $reasons = array();
        if ( $name_group && $family_group && $name_group !== $family_group ) {
            $reasons[] = sprintf( 'Name suggests %1$s but family suggests %2$s.', $name_group, $family_group );
        }
        if ( $name_group && count( $alias_groups ) === 1 && $alias_groups[0] !== $name_group ) {
            $reasons[] = sprintf( 'Aliases suggest %1$s but the ingredient name suggests %2$s.', $alias_groups[0], $name_group );
        }
        if ( ! $reasons ) { return array(); }
        return array(
            'detected_at' => current_time( 'mysql' ),
            'name_group' => $name_group,
            'family_group' => $family_group,
            'alias_groups' => $alias_groups,
            'summary' => implode( ' ', $reasons ),
        );
    }

    /** Scan existing ingredients once per release and mark only obvious conflicts. */
    public static function scan_identity_conflicts( $force = false ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $key = 'persiano_hub_identity_scan_version';
        if ( ! $force && get_option( $key ) === PERSIANO_HUB_VERSION ) { return; }
        foreach ( self::ingredient_ids() as $id ) {
            $conflict = self::detect_identity_conflict( $id );
            if ( $conflict ) {
                update_post_meta( $id, self::META_CONFLICT, $conflict );
                update_post_meta( $id, self::META_REVIEW, 1 );
            } else {
                delete_post_meta( $id, self::META_CONFLICT );
            }
        }
        update_option( $key, PERSIANO_HUB_VERSION, false );
    }

    public static function handle_rescan_identity_conflicts() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Not allowed.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_rescan_identity_conflicts' );
        delete_option( 'persiano_hub_identity_scan_version' );
        self::scan_identity_conflicts( true );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=identity_repair&rescanned=1' ) ); exit;
    }

    private static function clear_current_purchase_fields( $id ) {
        foreach ( array( Persiano_Hub_Costing::ING_PURCHASE_QTY, Persiano_Hub_Costing::ING_PURCHASE_UNIT, Persiano_Hub_Costing::ING_PURCHASE_COST, Persiano_Hub_Costing::ING_PURCHASE_TAX, Persiano_Hub_Costing::ING_GROSS_COST, Persiano_Hub_Costing::ING_UNIT_COST, Persiano_Hub_Costing::ING_BASE_UNIT, Persiano_Hub_Costing::ING_SUPPLIER, Persiano_Hub_Costing::ING_BRAND ) as $key ) {
            delete_post_meta( $id, $key );
        }
    }

    /** Apply an administrator-approved identity repair without changing recipe references. */
    public static function handle_resolve_identity_conflict() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Not allowed.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_resolve_identity_conflict' );
        $id = absint( $_POST['ingredient_id'] ?? 0 );
        if ( ! $id || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type( $id ) ) { wp_die( esc_html__( 'Invalid ingredient.', 'persiano-hub' ) ); }
        $old = array(
            'family' => get_post_meta( $id, self::META_FAMILY, true ),
            'aliases' => self::aliases( $id ),
            'history_count' => count( (array) get_post_meta( $id, Persiano_Hub_Costing::ING_HISTORY, true ) ),
            'package_count' => count( self::supplier_item_rows( get_post_meta( $id, '_persiano_supplier_items', true ) ) ),
        );
        update_post_meta( $id, self::META_FAMILY, sanitize_text_field( wp_unslash( $_POST['correct_family'] ?? '' ) ) );
        $aliases = preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['correct_aliases'] ?? '' ) );
        self::set_aliases( $id, $aliases );
        $disposition = sanitize_key( $_POST['purchase_disposition'] ?? 'unresolved' );
        $destination = absint( $_POST['destination_ingredient_id'] ?? 0 );
        $history = (array) get_post_meta( $id, Persiano_Hub_Costing::ING_HISTORY, true );
        $packages = self::supplier_item_rows( get_post_meta( $id, '_persiano_supplier_items', true ) );
        if ( 'move' === $disposition && $destination && $destination !== $id && Persiano_Hub_Costing::INGREDIENT_POST_TYPE === get_post_type( $destination ) ) {
            $dest_history = (array) get_post_meta( $destination, Persiano_Hub_Costing::ING_HISTORY, true );
            update_post_meta( $destination, Persiano_Hub_Costing::ING_HISTORY, array_values( array_merge( $dest_history, $history ) ) );
            $dest_packages = self::supplier_item_rows( get_post_meta( $destination, '_persiano_supplier_items', true ) );
            update_post_meta( $destination, '_persiano_supplier_items', self::consolidate_supplier_items( array_merge( $dest_packages, $packages ) ) );
            delete_post_meta( $id, Persiano_Hub_Costing::ING_HISTORY );
            delete_post_meta( $id, '_persiano_supplier_items' );
            self::clear_current_purchase_fields( $id );
            self::repair_and_apply_current_cost( $destination );
            self::backfill_supplier_packages( $destination );
        } elseif ( 'unresolved' === $disposition ) {
            $unresolved = (array) get_post_meta( $id, self::META_UNRESOLVED, true );
            $unresolved[] = array( 'time'=>current_time('mysql'), 'history'=>$history, 'packages'=>$packages, 'current'=>array(
                'supplier'=>get_post_meta($id,Persiano_Hub_Costing::ING_SUPPLIER,true), 'brand'=>get_post_meta($id,Persiano_Hub_Costing::ING_BRAND,true),
                'qty'=>get_post_meta($id,Persiano_Hub_Costing::ING_PURCHASE_QTY,true), 'unit'=>get_post_meta($id,Persiano_Hub_Costing::ING_PURCHASE_UNIT,true),
                'cost'=>get_post_meta($id,Persiano_Hub_Costing::ING_PURCHASE_COST,true), 'tax'=>get_post_meta($id,Persiano_Hub_Costing::ING_PURCHASE_TAX,true)
            ) );
            update_post_meta( $id, self::META_UNRESOLVED, $unresolved );
            delete_post_meta( $id, Persiano_Hub_Costing::ING_HISTORY );
            delete_post_meta( $id, '_persiano_supplier_items' );
            self::clear_current_purchase_fields( $id );
        } else {
            self::repair_and_apply_current_cost( $id );
            self::backfill_supplier_packages( $id );
        }
        delete_post_meta( $id, self::META_CONFLICT );
        update_post_meta( $id, self::META_REVIEW, 0 );
        $log = (array) get_option( self::OPTION_REPAIR_LOG, array() );
        $log[] = array( 'time'=>current_time('mysql'), 'user'=>get_current_user_id(), 'ingredient_id'=>$id, 'ingredient'=>get_the_title($id), 'disposition'=>$disposition, 'destination'=>$destination, 'before'=>$old );
        if ( count($log) > 200 ) { $log = array_slice($log,-200); }
        update_option( self::OPTION_REPAIR_LOG, $log, false );
        delete_option( 'persiano_hub_identity_scan_version' );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=identity_repair&repaired=1' ) ); exit;
    }

    private static function render_identity_repair() {
        $focus = absint( $_GET['ingredient_id'] ?? 0 );
        $ids = self::ingredient_ids();
        $conflicts = array();

        // Re-evaluate identity evidence whenever this review screen is opened.
        // The previous version relied on a once-per-release background scan, so
        // records imported or edited after that scan could remain invisible.
        foreach ( $ids as $id ) {
            if ( get_post_meta( $id, self::META_MERGED_TO, true ) ) { continue; }
            $c = self::detect_identity_conflict( $id );
            if ( $c ) {
                update_post_meta( $id, self::META_CONFLICT, $c );
                update_post_meta( $id, self::META_REVIEW, 1 );
                $conflicts[ $id ] = $c;
                continue;
            }

            delete_post_meta( $id, self::META_CONFLICT );

            // A record may have been explicitly marked for identity review even
            // when its wording is outside the limited automatic food-family map.
            if ( get_post_meta( $id, self::META_REVIEW, true ) ) {
                $conflicts[ $id ] = array(
                    'detected_at' => current_time( 'mysql' ),
                    'summary'     => __( 'This ingredient is marked for identity review. Confirm its family, aliases and purchase evidence before clearing it.', 'persiano-hub' ),
                );
            }
        }
        update_option( 'persiano_hub_identity_scan_version', PERSIANO_HUB_VERSION, false );
        echo '<div class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2>'.esc_html__('Ingredient Identity Repair','persiano-hub').'</h2><p>'.esc_html__('Review contradictory names, aliases and families before purchase history or supplier packages are reassigned. The system never guesses an ambiguous destination.','persiano-hub').'</p></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="persiano_hub_rescan_identity_conflicts">';
        wp_nonce_field('persiano_hub_rescan_identity_conflicts'); submit_button(__('Rescan','persiano-hub'),'secondary','submit',false); echo '</form></div>';
        if(!$conflicts){echo '<p><strong>'.esc_html__('No strong identity conflicts detected.','persiano-hub').'</strong></p></div>';return;}
        foreach($conflicts as$id=>$c){ if($focus && $focus!==$id) continue; $aliases=self::aliases($id); $history=(array)get_post_meta($id,Persiano_Hub_Costing::ING_HISTORY,true); $packages=self::supplier_item_rows(get_post_meta($id,'_persiano_supplier_items',true)); echo '<form class="ph-costing-panel" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="persiano_hub_resolve_identity_conflict"><input type="hidden" name="ingredient_id" value="'.esc_attr($id).'">'; wp_nonce_field('persiano_hub_resolve_identity_conflict'); echo '<h3><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html(get_the_title($id)).'</a> <code>'.esc_html(self::canonical_id($id)).'</code></h3><p class="notice notice-error inline" style="padding:8px 10px">'.esc_html($c['summary']??'').'</p><table class="form-table"><tr><th>'.esc_html__('Correct family','persiano-hub').'</th><td><input class="regular-text" name="correct_family" value="'.esc_attr(get_post_meta($id,self::META_FAMILY,true)).'"></td></tr><tr><th>'.esc_html__('Correct aliases','persiano-hub').'</th><td><textarea class="large-text" rows="4" name="correct_aliases">'.esc_textarea(implode("\n",$aliases)).'</textarea></td></tr><tr><th>'.esc_html__('Purchase evidence','persiano-hub').'</th><td>'.esc_html(sprintf(__('%1$d history rows and %2$d supplier packages','persiano-hub'),count($history),count($packages))).'</td></tr><tr><th>'.esc_html__('Purchase disposition','persiano-hub').'</th><td><select name="purchase_disposition"><option value="unresolved">'.esc_html__('Leave unresolved (safest)','persiano-hub').'</option><option value="keep">'.esc_html__('Keep purchases with this ingredient','persiano-hub').'</option><option value="move">'.esc_html__('Move purchases and packages to another ingredient','persiano-hub').'</option></select><p><select name="destination_ingredient_id" class="ph-searchable-select"><option value="0">'.esc_html__('Select destination ingredient','persiano-hub').'</option>'; foreach($ids as$dest){if($dest===$id)continue;echo '<option value="'.esc_attr($dest).'">'.esc_html(get_the_title($dest)).' (#'.$dest.')</option>';} echo '</select></p><p class="description">'.esc_html__('Recipe references stay on this ingredient unless you separately perform an approved ingredient merge.','persiano-hub').'</p></td></tr></table>'; submit_button(__('Apply controlled repair','persiano-hub'),'primary'); echo '</form>'; }
        echo '</div>';
    }

    public static function columns( $columns ) {
        $columns['persiano_identity'] = __( 'Identity', 'persiano-hub' );
        return $columns;
    }

    public static function column_content( $column, $post_id ) {
        if ( 'persiano_identity' !== $column ) { return; }
        echo '<code>' . esc_html( self::canonical_id( $post_id ) ) . '</code>';
        $aliases = self::aliases( $post_id );
        if ( $aliases ) { echo '<br><small>' . esc_html( count( $aliases ) . ' alias(es)' ) . '</small>'; }
        if ( get_post_meta( $post_id, self::META_REVIEW, true ) ) { echo '<br><span style="color:#b32d2e">' . esc_html__( 'Needs review', 'persiano-hub' ) . '</span>'; }
    }

    public static function ensure_canonical_ids() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $ids = get_posts( array(
            'post_type'      => Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'meta_query'     => array( array( 'key' => self::META_CANONICAL, 'compare' => 'NOT EXISTS' ) ),
            'no_found_rows'  => true,
        ) );
        foreach ( $ids as $id ) { self::ensure_canonical_id( $id ); }
    }

    public static function ensure_canonical_id( $post_id ) {
        $existing = (string) get_post_meta( $post_id, self::META_CANONICAL, true );
        if ( $existing ) { return $existing; }
        $canonical = 'ING-' . strtoupper( base_convert( (string) max( 1, (int) $post_id ), 10, 36 ) ) . '-' . strtoupper( substr( md5( $post_id . '|' . wp_salt( 'nonce' ) ), 0, 5 ) );
        update_post_meta( $post_id, self::META_CANONICAL, $canonical );
        return $canonical;
    }

    public static function canonical_id( $post_id ) { return self::ensure_canonical_id( $post_id ); }

    public static function aliases( $post_id ) {
        $aliases = get_post_meta( $post_id, self::META_ALIASES, true );
        return is_array( $aliases ) ? array_values( array_filter( array_map( 'sanitize_text_field', $aliases ) ) ) : array();
    }

    public static function set_aliases( $post_id, $aliases ) {
        $out = array();
        $title_key = self::normalize_name( get_the_title( $post_id ) );
        foreach ( (array) $aliases as $alias ) {
            $alias = sanitize_text_field( trim( (string) $alias ) );
            if ( ! $alias || self::normalize_name( $alias ) === $title_key ) { continue; }
            $out[ self::normalize_name( $alias ) ] = $alias;
        }
        update_post_meta( $post_id, self::META_ALIASES, array_values( $out ) );
    }

    public static function normalize_name( $name ) {
        $name = html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' );
        $name = remove_accents( $name );
        $name = mb_strtolower( trim( $name ), 'UTF-8' );
        $name = preg_replace( '/\b(fresh|frozen|chopped|diced|minced|peeled|sliced|whole|raw|cooked|organic|large|small|medium)\b/u', ' ', $name );
        $name = preg_replace( '/\b\d+(?:[.,]\d+)?\s*(kg|g|lb|lbs|oz|ml|l|litre|liter|pack|bag|case)\b/u', ' ', $name );
        $name = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name );
        $name = preg_replace( '/\s+/u', ' ', trim( $name ) );
        $tokens = $name ? explode( ' ', $name ) : array();
        $tokens = array_map( static function( $token ) {
            if ( strlen( $token ) > 4 && substr( $token, -3 ) === 'ies' ) { return substr( $token, 0, -3 ) . 'y'; }
            if ( strlen( $token ) > 3 && substr( $token, -1 ) === 's' && substr( $token, -2 ) !== 'ss' ) { return substr( $token, 0, -1 ); }
            return $token;
        }, $tokens );
        sort( $tokens, SORT_STRING );
        return implode( ' ', $tokens );
    }

    public static function resolve( $name, $canonical_id = '' ) {
        $name = trim( (string) $name );
        if ( $canonical_id ) {
            $ids = get_posts( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_status'=>'any', 'posts_per_page'=>1, 'fields'=>'ids', 'meta_key'=>self::META_CANONICAL, 'meta_value'=>sanitize_text_field( $canonical_id ) ) );
            if ( $ids ) { return array( 'id'=>(int)$ids[0], 'confidence'=>100, 'method'=>'canonical_id' ); }
        }
        $needle = self::normalize_name( $name );
        if ( ! $needle ) { return array( 'id'=>0, 'confidence'=>0, 'method'=>'none' ); }
        $ids = get_posts( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_status'=>array('publish','draft','private'), 'posts_per_page'=>-1, 'fields'=>'ids', 'no_found_rows'=>true ) );
        $best = array( 'id'=>0, 'confidence'=>0, 'method'=>'none' );
        foreach ( $ids as $id ) {
            if ( get_post_meta( $id, self::META_MERGED_TO, true ) ) { continue; }
            $title_norm = self::normalize_name( get_the_title( $id ) );
            if ( $title_norm === $needle ) { return array( 'id'=>(int)$id, 'confidence'=>100, 'method'=>'canonical_name' ); }
            foreach ( self::aliases( $id ) as $alias ) {
                if ( self::normalize_name( $alias ) === $needle ) { return array( 'id'=>(int)$id, 'confidence'=>100, 'method'=>'alias' ); }
            }
            similar_text( $needle, $title_norm, $pct );
            if ( $pct > $best['confidence'] ) { $best = array( 'id'=>(int)$id, 'confidence'=>(int)round($pct), 'method'=>'fuzzy' ); }
        }
        return $best;
    }

    public static function find_matching_ingredient( $name ) {
        $match = self::resolve( $name );
        return $match['confidence'] >= 92 ? (int) $match['id'] : 0;
    }

    private static function duplicate_name_score( $left, $right ) {
        $a = self::normalize_name( $left );
        $b = self::normalize_name( $right );
        if ( ! $a || ! $b ) { return 0; }
        similar_text( $a, $b, $pct );
        $tokens_a = array_values( array_unique( array_filter( explode( ' ', $a ) ) ) );
        $tokens_b = array_values( array_unique( array_filter( explode( ' ', $b ) ) ) );
        $intersection = count( array_intersect( $tokens_a, $tokens_b ) );
        $union = count( array_unique( array_merge( $tokens_a, $tokens_b ) ) );
        $jaccard = $union ? ( $intersection / $union ) * 100 : 0;
        $score = max( $pct, $jaccard );
        $smaller = count( $tokens_a ) <= count( $tokens_b ) ? $tokens_a : $tokens_b;
        $larger  = count( $tokens_a ) <= count( $tokens_b ) ? $tokens_b : $tokens_a;
        if ( $smaller && ! array_diff( $smaller, $larger ) ) {
            $difference = count( array_diff( $larger, $smaller ) );
            if ( $difference <= 1 ) { $score = max( $score, 90 ); }
            elseif ( 2 === $difference ) { $score = max( $score, 84 ); }
            elseif ( 3 === $difference ) { $score = max( $score, 78 ); }
        }
        return (int) round( $score );
    }

    private static function scan_pairs() {
        $ids = get_posts( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE, 'post_status'=>array('publish','draft','private'), 'posts_per_page'=>-1, 'fields'=>'ids', 'no_found_rows'=>true ) );
        $pairs = array();
        $ignored = get_option( 'persiano_hub_ingredient_separate_pairs', array() );
        $ignored = is_array( $ignored ) ? $ignored : array();
        $count = count( $ids );
        for ( $i=0; $i<$count; $i++ ) {
            if ( get_post_meta( $ids[$i], self::META_MERGED_TO, true ) ) { continue; }
            $names_a = array_merge( array( get_the_title( $ids[$i] ) ), self::aliases( $ids[$i] ) );
            for ( $j=$i+1; $j<$count; $j++ ) {
                if ( get_post_meta( $ids[$j], self::META_MERGED_TO, true ) ) { continue; }
                $key = min($ids[$i],$ids[$j]) . ':' . max($ids[$i],$ids[$j]);
                if ( in_array( $key, $ignored, true ) ) { continue; }
                $names_b = array_merge( array( get_the_title( $ids[$j] ) ), self::aliases( $ids[$j] ) );
                $score = 0;
                foreach ( $names_a as $name_a ) {
                    foreach ( $names_b as $name_b ) {
                        $score = max( $score, self::duplicate_name_score( $name_a, $name_b ) );
                    }
                }
                if ( $score >= 72 ) {
                    $pairs[] = array( 'a'=>$ids[$i], 'b'=>$ids[$j], 'score'=>$score );
                }
            }
        }
        usort( $pairs, static function($x,$y){ return $y['score'] <=> $x['score']; } );
        return array_slice( $pairs, 0, 300 );
    }

    public static function handle_scan() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Not allowed.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_scan_ingredient_duplicates' );
        update_option( self::OPTION_SCAN, self::scan_pairs(), false );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=duplicates&scanned=1' ) ); exit;
    }

    public static function handle_keep_separate() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Not allowed.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_keep_ingredients_separate' );
        $a = absint( $_POST['a'] ?? 0 ); $b = absint( $_POST['b'] ?? 0 );
        if ( $a && $b ) {
            $pair_key = min( $a, $b ) . ':' . max( $a, $b );

            // Persist the decision so future scans do not suggest this pair again.
            $pairs = get_option( 'persiano_hub_ingredient_separate_pairs', array() );
            $pairs = is_array( $pairs ) ? $pairs : array();
            $pairs[] = $pair_key;
            update_option( 'persiano_hub_ingredient_separate_pairs', array_values( array_unique( $pairs ) ), false );

            // Also remove it from the currently cached scan immediately. Previously the
            // decision was saved, but the row stayed visible until Scan for duplicates
            // was run again, which made the button appear not to work.
            $scan = get_option( self::OPTION_SCAN, array() );
            $scan = is_array( $scan ) ? $scan : array();
            $scan = array_values( array_filter( $scan, static function ( $row ) use ( $pair_key ) {
                $row_a = absint( $row['a'] ?? 0 );
                $row_b = absint( $row['b'] ?? 0 );
                if ( ! $row_a || ! $row_b ) { return true; }
                return min( $row_a, $row_b ) . ':' . max( $row_a, $row_b ) !== $pair_key;
            } ) );
            update_option( self::OPTION_SCAN, $scan, false );
        }
        wp_safe_redirect( add_query_arg( 'kept_separate', 1, admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=duplicates' ) ) ); exit;
    }

    public static function handle_merge() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Not allowed.', 'persiano-hub' ) ); }
        check_admin_referer( 'persiano_hub_merge_ingredients' );
        $target = absint( $_POST['target'] ?? 0 ); $source = absint( $_POST['source'] ?? 0 );
        $result = self::merge( $target, $source );
        $args = is_wp_error($result) ? array('merge_error'=>rawurlencode($result->get_error_message())) : array('merged'=>1);
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=duplicates' ) ) ); exit;
    }

    public static function merge( $target, $source ) {
        if ( ! $target || ! $source || $target === $source || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type($target) || Persiano_Hub_Costing::INGREDIENT_POST_TYPE !== get_post_type($source) ) {
            return new WP_Error( 'invalid_merge', __( 'Invalid ingredient merge.', 'persiano-hub' ) );
        }
        $snapshot = array( 'time'=>time(), 'user'=>get_current_user_id(), 'target'=>$target, 'source'=>$source, 'target_title'=>get_the_title($target), 'source_title'=>get_the_title($source) );
        $aliases = array_merge( self::aliases($target), self::aliases($source), array(get_the_title($source)) );
        self::set_aliases( $target, $aliases );

        $merge_array_meta = array( Persiano_Hub_Costing::ING_HISTORY, '_persiano_ing_inventory_history' );
        foreach ( $merge_array_meta as $key ) {
            $a = get_post_meta($target,$key,true); $b = get_post_meta($source,$key,true);
            $a = is_array($a)?$a:array(); $b=is_array($b)?$b:array();
            if ($b) update_post_meta($target,$key,array_values(array_merge($a,$b)));
        }
        // Supplier packages belong to the canonical ingredient. Move and deduplicate them before retiring the source.
        $target_packages = self::supplier_item_rows( get_post_meta( $target, '_persiano_supplier_items', true ) );
        $source_packages = self::supplier_item_rows( get_post_meta( $source, '_persiano_supplier_items', true ) );
        update_post_meta( $target, '_persiano_supplier_items', self::consolidate_supplier_items( array_merge( $target_packages, $source_packages ) ) );

        $copy_if_empty = array(
            Persiano_Hub_Costing::ING_CATEGORY, Persiano_Hub_Costing::ING_BRAND, Persiano_Hub_Costing::ING_SUPPLIER,
            Persiano_Hub_Costing::ING_PURCHASE_QTY, Persiano_Hub_Costing::ING_PURCHASE_UNIT, Persiano_Hub_Costing::ING_PURCHASE_COST,
            Persiano_Hub_Costing::ING_PURCHASE_TAX, Persiano_Hub_Costing::ING_GROSS_COST, Persiano_Hub_Costing::ING_WASTE_PCT,
            Persiano_Hub_Costing::ING_NOTES, Persiano_Hub_Costing::ING_UNIT_COST, Persiano_Hub_Costing::ING_BASE_UNIT,
            '_persiano_ingredient_type','_persiano_g_per_cup','_persiano_g_per_tbsp','_persiano_g_per_tsp','_persiano_density_g_ml','_persiano_include_on_label'
        );
        foreach ($copy_if_empty as $key) {
            $tv=get_post_meta($target,$key,true); $sv=get_post_meta($source,$key,true);
            if ( (''===$tv || null===$tv || array()===$tv) && ''!==$sv && null!==$sv ) update_post_meta($target,$key,$sv);
        }

        self::backfill_supplier_packages( $target );

        $recipes = get_posts(array('post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true));
        foreach($recipes as $rid){
            $items=get_post_meta($rid,Persiano_Hub_Costing::RECIPE_ITEMS,true); if(!is_array($items)) continue; $changed=false;
            foreach($items as &$row){ $iid=absint($row['ingredient_id']??$row['source_id']??0); if($iid===$source){ if(isset($row['ingredient_id']))$row['ingredient_id']=$target; if(isset($row['source_id']))$row['source_id']=$target; $changed=true; } } unset($row);
            if($changed){ update_post_meta($rid,Persiano_Hub_Costing::RECIPE_ITEMS,$items); Persiano_Hub_Costing::recalculate_recipe($rid); }
        }
        if ( class_exists('Persiano_Hub_Operations') ) {
            foreach(array(Persiano_Hub_Operations::PLAN_POST_TYPE,Persiano_Hub_Operations::LIST_POST_TYPE) as $pt){
                $posts=get_posts(array('post_type'=>$pt,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true));
                foreach($posts as $pid){
                    foreach(get_post_meta($pid) as $key=>$vals){
                        $value=get_post_meta($pid,$key,true); $new=self::replace_ingredient_id($value,$source,$target); if($new!==$value) update_post_meta($pid,$key,$new);
                    }
                }
            }
        }
        update_post_meta($source,self::META_MERGED_TO,$target);
        wp_update_post(array('ID'=>$source,'post_status'=>'draft','post_title'=>get_the_title($source).' (merged)'));
        $log=get_option(self::OPTION_LOG,array()); $log=is_array($log)?$log:array(); $log[]=$snapshot; if(count($log)>100)$log=array_slice($log,-100); update_option(self::OPTION_LOG,$log,false);
        delete_option(self::OPTION_SCAN);
        return true;
    }

    private static function replace_ingredient_id( $value, $source, $target ) {
        if ( is_array($value) ) { foreach($value as $k=>$v){ if((in_array($k,array('ingredient_id','source_id'),true)) && absint($v)===$source){$value[$k]=$target;} else {$value[$k]=self::replace_ingredient_id($v,$source,$target);} } return $value; }
        return $value;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Not allowed.', 'persiano-hub' ) ); }
        $tab = sanitize_key( $_GET['tab'] ?? 'master' );
        $tabs = array('master'=>__('Master Ingredients','persiano-hub'),'pricing'=>__('Needs Pricing','persiano-hub'),'review'=>__('Needs Review','persiano-hub'),'duplicates'=>__('Possible Duplicates','persiano-hub'),'aliases'=>__('Aliases','persiano-hub'),'history'=>__('Price History','persiano-hub'),'supplier_items'=>__('Supplier Items','persiano-hub'),'identity_repair'=>__('Identity Repair','persiano-hub'),'merge_history'=>__('Merge History','persiano-hub'));
        echo '<div class="wrap"><h1>'.esc_html__('Ingredient Master','persiano-hub').'</h1><p>'.esc_html__('One canonical record per ingredient, with aliases, duplicate review and consolidated price history.','persiano-hub').'</p><nav class="nav-tab-wrapper persiano-master-tabs">';
        foreach($tabs as $key=>$label){echo '<a class="nav-tab '.($tab===$key?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page='.self::MENU_SLUG.'&tab='.$key)).'">'.esc_html($label).'</a>';}
        echo '</nav>';
        if('duplicates'===$tab){self::render_duplicates();}
        elseif('pricing'===$tab){self::render_ingredient_table(false,false,true);}
        elseif('review'===$tab){self::render_ingredient_table(true,false,false);}
        elseif('aliases'===$tab){self::render_aliases();}
        elseif('history'===$tab){self::render_history();}
        elseif('supplier_items'===$tab){self::render_supplier_items();}
        elseif('identity_repair'===$tab){self::render_identity_repair();}
        elseif('merge_history'===$tab){self::render_merge_history();}
        else{self::render_ingredient_table(false,true,false);} echo '</div>';
    }

    private static function ingredient_ids(){return get_posts(array('post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'title','order'=>'ASC','no_found_rows'=>true));}
    private static function ingredient_needs_review( $id ) {
        if ( get_post_meta( $id, self::META_REVIEW, true ) ) { return true; }
        $history = get_post_meta( $id, Persiano_Hub_Costing::ING_HISTORY, true );
        if ( ! is_array( $history ) ) { return false; }
        foreach ( $history as $entry ) {
            if ( ! is_array( $entry ) ) { return true; }
            if ( isset( $entry['approved'] ) && ! $entry['approved'] ) { return true; }
            if ( 'needs_review' === ( $entry['normalization_status'] ?? '' ) ) { return true; }
        }
        return false;
    }
    private static function render_ingredient_table($review_only=false,$all=true,$pricing_only=false){
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('post-new.php?post_type='.Persiano_Hub_Costing::INGREDIENT_POST_TYPE)).'">'.esc_html__('Add ingredient','persiano-hub').'</a> <a class="button" href="'.esc_url(admin_url('edit.php?post_type='.Persiano_Hub_Costing::INGREDIENT_POST_TYPE)).'">'.esc_html__('Open WordPress list','persiano-hub').'</a></p><table class="widefat striped ph-ingredient-master-table"><thead><tr><th>'.esc_html__('Ingredient','persiano-hub').'</th><th>'.esc_html__('Canonical ID','persiano-hub').'</th><th>'.esc_html__('Aliases','persiano-hub').'</th><th>'.esc_html__('Family','persiano-hub').'</th><th>'.esc_html__('Current cost','persiano-hub').'</th><th>'.esc_html__('Status','persiano-hub').'</th></tr></thead><tbody>';
        $shown=0; foreach(self::ingredient_ids() as $id){if(get_post_meta($id,self::META_MERGED_TO,true))continue; $review=self::ingredient_needs_review($id); if($review_only&&!$review)continue; $unit_cost=(float)get_post_meta($id,Persiano_Hub_Costing::ING_UNIT_COST,true); $pricing_needed=$unit_cost<=0 || (bool)get_post_meta($id,'_persiano_pricing_needed',true); if($pricing_only&&!$pricing_needed)continue; $shown++; $unit=get_post_meta($id,Persiano_Hub_Costing::ING_BASE_UNIT,true); $cost=$unit_cost; echo '<tr><td data-label="'.esc_attr__('Ingredient','persiano-hub').'"><a href="'.esc_url(get_edit_post_link($id)).'"><strong>'.esc_html(get_the_title($id)).'</strong></a></td><td data-label="'.esc_attr__('Canonical ID','persiano-hub').'"><code>'.esc_html(self::canonical_id($id)).'</code></td><td data-label="'.esc_attr__('Aliases','persiano-hub').'">'.esc_html(implode(', ',self::aliases($id))).'</td><td data-label="'.esc_attr__('Family','persiano-hub').'">'.esc_html(get_post_meta($id,self::META_FAMILY,true)).'</td><td data-label="'.esc_attr__('Current cost','persiano-hub').'">'.esc_html($cost?('$'.number_format($cost,4).'/'.$unit):'—').'</td><td data-label="'.esc_attr__('Status','persiano-hub').'">'.($review?'<span style="color:#b32d2e">'.esc_html__('Needs review','persiano-hub').'</span>':esc_html__('Ready','persiano-hub')).'</td></tr>';}
        if(!$shown)echo '<tr><td colspan="6">'.esc_html__('No matching ingredients found.','persiano-hub').'</td></tr>'; echo '</tbody></table>';
    }
    private static function render_duplicates(){
        $pairs=get_option(self::OPTION_SCAN,array()); $pairs=is_array($pairs)?$pairs:array(); echo '<p>'.esc_html__('Scan compares normalized names and aliases. Nothing is merged automatically.','persiano-hub').'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="persiano_hub_scan_ingredient_duplicates">';wp_nonce_field('persiano_hub_scan_ingredient_duplicates');submit_button(__('Scan for duplicates','persiano-hub'),'primary','',false);echo '</form>';
        if(!$pairs){echo '<p>'.esc_html(!empty($_GET['scanned'])?__('No possible duplicate pairs met the review threshold.','persiano-hub'):__('Run the scan to review possible duplicates.','persiano-hub')).'</p>';return;} echo '<table class="widefat striped ph-ingredient-master-table"><thead><tr><th>'.esc_html__('Ingredient A','persiano-hub').'</th><th>'.esc_html__('Ingredient B','persiano-hub').'</th><th>'.esc_html__('Confidence','persiano-hub').'</th><th>'.esc_html__('Action','persiano-hub').'</th></tr></thead><tbody>';
        foreach($pairs as $p){$a=absint($p['a']);$b=absint($p['b']);if(!get_post($a)||!get_post($b))continue;echo '<tr><td><a href="'.esc_url(get_edit_post_link($a)).'">'.esc_html(get_the_title($a)).'</a></td><td><a href="'.esc_url(get_edit_post_link($b)).'">'.esc_html(get_the_title($b)).'</a></td><td>'.esc_html($p['score'].'%').'</td><td><form style="display:inline" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="persiano_hub_merge_ingredients"><input type="hidden" name="target" value="'.$a.'"><input type="hidden" name="source" value="'.$b.'">';wp_nonce_field('persiano_hub_merge_ingredients');echo '<button class="button button-primary">'.esc_html__('Merge B into A','persiano-hub').'</button></form> <form style="display:inline" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="persiano_hub_keep_ingredients_separate"><input type="hidden" name="a" value="'.$a.'"><input type="hidden" name="b" value="'.$b.'">';wp_nonce_field('persiano_hub_keep_ingredients_separate');echo '<button class="button">'.esc_html__('Keep separate','persiano-hub').'</button></form></td></tr>';}
        echo '</tbody></table>';
    }
    private static function render_aliases(){echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=persiano-hub-import-export&dataset=ingredient_aliases')).'">'.esc_html__('Import aliases','persiano-hub').'</a> <a class="button" href="'.esc_url(self::export_url('ingredient_aliases','csv')).'">'.esc_html__('Export CSV','persiano-hub').'</a></p>';echo '<table class="widefat striped ph-ingredient-master-table"><thead><tr><th>'.esc_html__('Ingredient','persiano-hub').'</th><th>'.esc_html__('Aliases','persiano-hub').'</th></tr></thead><tbody>';foreach(self::ingredient_ids() as$id){if(get_post_meta($id,self::META_MERGED_TO,true))continue;$aliases=self::aliases($id);echo '<tr><td><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html(get_the_title($id)).'</a></td><td>'.esc_html($aliases?implode(', ',$aliases):'—').'</td></tr>';}echo '</tbody></table>';}
    private static function render_supplier_items(){
        echo '<p>'.esc_html__('Supplier-specific names and package details remain linked to the same canonical ingredient.','persiano-hub').'</p>';
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=persiano-hub-import-export&dataset=supplier_items')).'">'.esc_html__('Import supplier items','persiano-hub').'</a> <a class="button" href="'.esc_url(self::export_url('supplier_items','csv')).'">'.esc_html__('Export CSV','persiano-hub').'</a> <a class="button" href="'.esc_url(self::export_url('supplier_items','xlsx')).'">'.esc_html__('Export XLSX','persiano-hub').'</a> <a class="button" href="'.esc_url(self::export_url('supplier_items','template')).'">'.esc_html__('Download template','persiano-hub').'</a></p>';
        echo '<table class="widefat striped ph-ingredient-master-table"><thead><tr><th>'.esc_html__('Ingredient','persiano-hub').'</th><th>'.esc_html__('Supplier','persiano-hub').'</th><th>'.esc_html__('Brand','persiano-hub').'</th><th>'.esc_html__('Purchase package','persiano-hub').'</th><th>'.esc_html__('Current normalized cost','persiano-hub').'</th></tr></thead><tbody>';
        $shown=0;
        foreach(self::ingredient_ids() as $id){
            if(get_post_meta($id,self::META_MERGED_TO,true))continue;
            $supplier_items=get_post_meta($id,'_persiano_supplier_items',true); $supplier_items=is_array($supplier_items)?$supplier_items:array();
            foreach($supplier_items as $item){$shown++;echo '<tr><td data-label="'.esc_attr__('Ingredient','persiano-hub').'"><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html(get_the_title($id)).'</a></td><td data-label="'.esc_attr__('Supplier','persiano-hub').'">'.esc_html($item['supplier_name']??'—').'</td><td data-label="'.esc_attr__('Brand','persiano-hub').'">'.esc_html($item['brand']??'—').'</td><td data-label="'.esc_attr__('Purchase package','persiano-hub').'">'.esc_html(trim((string)($item['package_quantity']??'').' '.($item['package_unit']??''))?:'—').'</td><td data-label="'.esc_attr__('Current normalized cost','persiano-hub').'">'.esc_html(!empty($item['normalized_unit_cost'])?'$'.number_format((float)$item['normalized_unit_cost'],4).'/'.($item['normalized_unit']??'unit'):'—').'</td></tr>';}
            $supplier=(string)get_post_meta($id,Persiano_Hub_Costing::ING_SUPPLIER,true);
            $brand=(string)get_post_meta($id,Persiano_Hub_Costing::ING_BRAND,true);
            $qty=get_post_meta($id,Persiano_Hub_Costing::ING_PURCHASE_QTY,true);
            $unit=(string)get_post_meta($id,Persiano_Hub_Costing::ING_PURCHASE_UNIT,true);
            $cost=(float)get_post_meta($id,Persiano_Hub_Costing::ING_UNIT_COST,true);
            if(''===$supplier && ''===$brand && ''===(string)$qty)continue;
            $shown++;
            echo '<tr><td data-label="'.esc_attr__('Ingredient','persiano-hub').'"><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html(get_the_title($id)).'</a></td><td data-label="'.esc_attr__('Supplier','persiano-hub').'">'.esc_html($supplier?:'—').'</td><td data-label="'.esc_attr__('Brand','persiano-hub').'">'.esc_html($brand?:'—').'</td><td data-label="'.esc_attr__('Purchase package','persiano-hub').'">'.esc_html(trim((string)$qty.' '.$unit)?:'—').'</td><td data-label="'.esc_attr__('Current normalized cost','persiano-hub').'">'.esc_html($cost?'$'.number_format($cost,4):'—').'</td></tr>';
        }
        if(!$shown)echo '<tr><td colspan="5">'.esc_html__('No supplier package records found yet. Open an ingredient to add supplier and purchase details.','persiano-hub').'</td></tr>';
        echo '</tbody></table>';
    }

    private static function render_history(){echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=persiano-hub-import-export&dataset=ingredient_price_history')).'">'.esc_html__('Import price history','persiano-hub').'</a> <a class="button" href="'.esc_url(self::export_url('ingredient_price_history','csv')).'">'.esc_html__('Export CSV','persiano-hub').'</a> <a class="button" href="'.esc_url(self::export_url('ingredient_price_history','xlsx')).'">'.esc_html__('Export XLSX','persiano-hub').'</a> <a class="button" href="'.esc_url(self::export_url('ingredient_price_history','template')).'">'.esc_html__('Download template','persiano-hub').'</a></p>';echo '<table class="widefat striped ph-ingredient-master-table"><thead><tr><th>'.esc_html__('Ingredient','persiano-hub').'</th><th>'.esc_html__('Date','persiano-hub').'</th><th>'.esc_html__('Supplier','persiano-hub').'</th><th>'.esc_html__('Package','persiano-hub').'</th><th>'.esc_html__('Gross cost','persiano-hub').'</th><th>'.esc_html__('Normalized cost','persiano-hub').'</th></tr></thead><tbody>';$rows=array();foreach(self::ingredient_ids()as$id){foreach((array)get_post_meta($id,Persiano_Hub_Costing::ING_HISTORY,true)as$h){$rows[]=array('id'=>$id,'h'=>$h);}}usort($rows,static function($a,$b){return(int)($b['h']['time']??0)<=>(int)($a['h']['time']??0);});foreach($rows as$row){$h=$row['h'];echo '<tr><td><a href="'.esc_url(get_edit_post_link($row['id'])).'">'.esc_html(get_the_title($row['id'])).'</a></td><td>'.esc_html(!empty($h['time'])?wp_date('Y-m-d',intval($h['time'])):'—').'</td><td>'.esc_html($h['supplier']??$h['vendor']??'—').'</td><td>'.esc_html(($h['purchase_qty']??'').' '.($h['purchase_unit']??'')).'</td><td>'.esc_html(isset($h['gross_cost'])?'$'.number_format((float)$h['gross_cost'],2):'—').'</td><td>'.esc_html(isset($h['unit_cost'])?'$'.number_format((float)$h['unit_cost'],4).'/'.($h['base_unit']??'unit'):'—').'</td></tr>';}echo '</tbody></table>';}

    private static function export_url( $dataset, $format = 'csv' ) {
        return wp_nonce_url(
            add_query_arg(
                array( 'action' => 'ph_universal_export', 'dataset' => $dataset, 'format' => $format ),
                admin_url( 'admin-post.php' )
            ),
            'ph_universal_export'
        );
    }

    private static function render_merge_history() {
        $log = get_option( self::OPTION_LOG, array() );
        $log = is_array( $log ) ? array_reverse( $log ) : array();
        echo '<p>' . esc_html__( 'Audit trail of ingredient merges. Source records remain as drafts and keep a pointer to the surviving canonical ingredient.', 'persiano-hub' ) . '</p>';
        echo '<table class="widefat striped ph-ingredient-master-table"><thead><tr><th>' . esc_html__( 'Date', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Surviving ingredient', 'persiano-hub' ) . '</th><th>' . esc_html__( 'Merged source', 'persiano-hub' ) . '</th><th>' . esc_html__( 'User', 'persiano-hub' ) . '</th></tr></thead><tbody>';
        if ( ! $log ) { echo '<tr><td colspan="4">' . esc_html__( 'No merges recorded.', 'persiano-hub' ) . '</td></tr>'; }
        foreach ( $log as $entry ) {
            $user = ! empty( $entry['user'] ) ? get_userdata( absint( $entry['user'] ) ) : false;
            echo '<tr><td>' . esc_html( ! empty( $entry['time'] ) ? wp_date( 'Y-m-d H:i', absint( $entry['time'] ) ) : '—' ) . '</td><td>';
            if ( ! empty( $entry['target'] ) && get_post( absint( $entry['target'] ) ) ) { echo '<a href="' . esc_url( get_edit_post_link( absint( $entry['target'] ) ) ) . '">' . esc_html( get_the_title( absint( $entry['target'] ) ) ) . '</a>'; } else { echo esc_html( $entry['target_title'] ?? '—' ); }
            echo '</td><td>';
            if ( ! empty( $entry['source'] ) && get_post( absint( $entry['source'] ) ) ) { echo '<a href="' . esc_url( get_edit_post_link( absint( $entry['source'] ) ) ) . '">' . esc_html( get_the_title( absint( $entry['source'] ) ) ) . '</a>'; } else { echo esc_html( $entry['source_title'] ?? '—' ); }
            echo '</td><td>' . esc_html( $user ? $user->display_name : '—' ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

}
