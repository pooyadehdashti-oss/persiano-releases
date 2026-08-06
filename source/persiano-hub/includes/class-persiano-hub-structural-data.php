<?php
/**
 * Standard yield/package data and searchable long selectors.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Structural_Data {
    const MENU_SLUG = 'persiano-hub-yield-standards';

    const META_TOTAL_OUTPUT_QTY  = '_persiano_recipe_total_output_qty';
    const META_TOTAL_OUTPUT_UNIT = '_persiano_recipe_total_output_unit';
    const META_PACKAGE_TYPE_ID   = '_persiano_recipe_package_type_id';
    const META_PACKAGE_SIZE      = '_persiano_recipe_package_size';
    const META_PACKAGE_UNIT      = '_persiano_recipe_package_unit';
    const META_UNITS_PER_BATCH   = '_persiano_recipe_units_per_batch';
    const META_REMAINDER_QTY     = '_persiano_recipe_remainder_qty';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 39 );
        add_action( 'admin_post_persiano_hub_save_yield_standard', array( __CLASS__, 'save_standard' ) );
        add_action( 'admin_post_persiano_hub_delete_yield_standard', array( __CLASS__, 'delete_standard' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_searchable_selects' ), 50 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
        add_action( 'admin_notices', array( __CLASS__, 'yield_warning_notice' ) );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'persiano_yield_standards';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            singular varchar(100) NOT NULL,
            plural varchar(100) NOT NULL,
            unit_type varchar(20) NOT NULL DEFAULT 'count',
            default_size decimal(18,4) NOT NULL DEFAULT 1,
            default_unit varchar(20) NOT NULL DEFAULT 'each',
            display_label varchar(190) NOT NULL DEFAULT '',
            rounding_rule varchar(20) NOT NULL DEFAULT 'whole_up',
            partial_allowed tinyint(1) NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY active_sort (active,sort_order),
            UNIQUE KEY singular (singular)
        ) {$charset};";
        dbDelta( $sql );
        self::seed_defaults();
    }

    private static function seed_defaults() {
        global $wpdb;
        $table = self::table_name();
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $count ) {
            return;
        }
        $defaults = array(
            array( 'Jar', 'Jars', 'count', 250, 'g', '250 g jar', 'whole_up', 0 ),
            array( 'Container', 'Containers', 'count', 300, 'g', '300 g container', 'whole_up', 0 ),
            array( 'Portion', 'Portions', 'count', 1, 'each', '1 portion', 'whole_up', 0 ),
            array( 'Serving', 'Servings', 'count', 1, 'each', '1 serving', 'whole_up', 0 ),
            array( 'Tray', 'Trays', 'count', 750, 'ml', '750 mL tray', 'whole_up', 0 ),
            array( 'Bottle', 'Bottles', 'count', 500, 'ml', '500 mL bottle', 'whole_up', 0 ),
            array( 'Bag', 'Bags', 'count', 250, 'g', '250 g bag', 'whole_up', 0 ),
            array( 'Piece', 'Pieces', 'count', 1, 'each', '1 piece', 'whole_up', 0 ),
            array( 'Batch', 'Batches', 'count', 1, 'batch', '1 batch', 'decimal', 1 ),
        );
        foreach ( $defaults as $i => $row ) {
            $wpdb->insert(
                $table,
                array(
                    'singular'        => $row[0],
                    'plural'          => $row[1],
                    'unit_type'       => $row[2],
                    'default_size'    => $row[3],
                    'default_unit'    => $row[4],
                    'display_label'   => $row[5],
                    'rounding_rule'   => $row[6],
                    'partial_allowed' => $row[7],
                    'active'          => 1,
                    'sort_order'      => $i * 10,
                ),
                array( '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%d', '%d' )
            );
        }
    }

    public static function get_standards( $active_only = true ) {
        global $wpdb;
        $table = self::table_name();
        $where = $active_only ? 'WHERE active = 1' : '';
        return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, singular ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function get_standard( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Yield & Package Standards', 'persiano-hub' ),
            __( 'Yield & Package Standards', 'persiano-hub' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage yield standards.', 'persiano-hub' ) );
        }
        $edit = isset( $_GET['edit'] ) ? self::get_standard( absint( $_GET['edit'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rows = self::get_standards( false );
        ?>
        <div class="wrap ph-yield-standards">
            <h1><?php esc_html_e( 'Yield & Package Standards', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'These records standardize recipe output, sellable package size and Production Planner calculations. Display labels never control calculations.', 'persiano-hub' ); ?></p>
            <div style="display:grid;grid-template-columns:minmax(320px,520px) minmax(600px,1fr);gap:24px;align-items:start">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card" style="max-width:none">
                    <h2><?php echo $edit ? esc_html__( 'Edit standard', 'persiano-hub' ) : esc_html__( 'Add standard', 'persiano-hub' ); ?></h2>
                    <input type="hidden" name="action" value="persiano_hub_save_yield_standard">
                    <input type="hidden" name="id" value="<?php echo esc_attr( $edit ? $edit->id : 0 ); ?>">
                    <?php wp_nonce_field( 'persiano_hub_save_yield_standard' ); ?>
                    <table class="form-table"><tbody>
                    <?php self::text_row( 'singular', __( 'Singular name', 'persiano-hub' ), $edit ? $edit->singular : '' ); ?>
                    <?php self::text_row( 'plural', __( 'Plural name', 'persiano-hub' ), $edit ? $edit->plural : '' ); ?>
                    <tr><th><label for="unit_type"><?php esc_html_e( 'Type', 'persiano-hub' ); ?></label></th><td><select id="unit_type" name="unit_type"><option value="count" <?php selected( $edit ? $edit->unit_type : 'count', 'count' ); ?>><?php esc_html_e( 'Sellable count', 'persiano-hub' ); ?></option><option value="weight" <?php selected( $edit ? $edit->unit_type : '', 'weight' ); ?>><?php esc_html_e( 'Weight', 'persiano-hub' ); ?></option><option value="volume" <?php selected( $edit ? $edit->unit_type : '', 'volume' ); ?>><?php esc_html_e( 'Volume', 'persiano-hub' ); ?></option></select></td></tr>
                    <?php self::text_row( 'default_size', __( 'Default package size', 'persiano-hub' ), $edit ? $edit->default_size : 1, 'number', '0.0001' ); ?>
                    <?php self::text_row( 'default_unit', __( 'Default unit', 'persiano-hub' ), $edit ? $edit->default_unit : 'each' ); ?>
                    <?php self::text_row( 'display_label', __( 'Display label', 'persiano-hub' ), $edit ? $edit->display_label : '' ); ?>
                    <tr><th><label for="rounding_rule"><?php esc_html_e( 'Production rounding', 'persiano-hub' ); ?></label></th><td><select id="rounding_rule" name="rounding_rule"><option value="whole_up" <?php selected( $edit ? $edit->rounding_rule : 'whole_up', 'whole_up' ); ?>><?php esc_html_e( 'Whole units, round up', 'persiano-hub' ); ?></option><option value="decimal" <?php selected( $edit ? $edit->rounding_rule : '', 'decimal' ); ?>><?php esc_html_e( 'Allow decimals', 'persiano-hub' ); ?></option></select></td></tr>
                    <tr><th><?php esc_html_e( 'Options', 'persiano-hub' ); ?></th><td><label><input type="checkbox" name="partial_allowed" value="1" <?php checked( $edit ? $edit->partial_allowed : 0, 1 ); ?>> <?php esc_html_e( 'Partial sellable units allowed', 'persiano-hub' ); ?></label><br><label><input type="checkbox" name="active" value="1" <?php checked( $edit ? $edit->active : 1, 1 ); ?>> <?php esc_html_e( 'Active', 'persiano-hub' ); ?></label></td></tr>
                    <?php self::text_row( 'sort_order', __( 'Sort order', 'persiano-hub' ), $edit ? $edit->sort_order : 0, 'number', '1' ); ?>
                    </tbody></table>
                    <?php submit_button( $edit ? __( 'Update standard', 'persiano-hub' ) : __( 'Add standard', 'persiano-hub' ) ); ?>
                </form>
                <div class="card" style="max-width:none"><h2><?php esc_html_e( 'Standards', 'persiano-hub' ); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Name', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Type', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Default package', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Rounding', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Status', 'persiano-hub' ); ?></th><th></th></tr></thead><tbody>
                <?php foreach ( $rows as $row ) : ?><tr><td><strong><?php echo esc_html( $row->singular ); ?></strong><br><small><?php echo esc_html( $row->plural ); ?></small></td><td><?php echo esc_html( ucfirst( $row->unit_type ) ); ?></td><td><?php echo esc_html( rtrim( rtrim( number_format( (float) $row->default_size, 4, '.', '' ), '0' ), '.' ) . ' ' . $row->default_unit ); ?></td><td><?php echo esc_html( 'whole_up' === $row->rounding_rule ? 'Whole ↑' : 'Decimal' ); ?></td><td><?php echo $row->active ? esc_html__( 'Active', 'persiano-hub' ) : esc_html__( 'Inactive', 'persiano-hub' ); ?></td><td><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'edit' => $row->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'persiano-hub' ); ?></a></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
        </div>
        <?php
    }

    private static function text_row( $name, $label, $value, $type = 'text', $step = '' ) {
        echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $step ? ' step="' . esc_attr( $step ) . '"' : '' ) . '></td></tr>';
    }

    public static function save_standard() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ) );
        }
        check_admin_referer( 'persiano_hub_save_yield_standard' );
        global $wpdb;
        $id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $singular = isset( $_POST['singular'] ) ? sanitize_text_field( wp_unslash( $_POST['singular'] ) ) : '';
        $plural   = isset( $_POST['plural'] ) ? sanitize_text_field( wp_unslash( $_POST['plural'] ) ) : '';
        if ( ! $singular ) {
            wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'ph_notice' => 'missing_name' ), admin_url( 'admin.php' ) ) );
            exit;
        }
        if ( ! $plural ) {
            $plural = $singular . 's';
        }
        $data = array(
            'singular'        => $singular,
            'plural'          => $plural,
            'unit_type'       => isset( $_POST['unit_type'] ) && in_array( $_POST['unit_type'], array( 'count', 'weight', 'volume' ), true ) ? sanitize_key( $_POST['unit_type'] ) : 'count',
            'default_size'    => isset( $_POST['default_size'] ) ? max( 0.0001, (float) wc_format_decimal( wp_unslash( $_POST['default_size'] ) ) ) : 1,
            'default_unit'    => isset( $_POST['default_unit'] ) ? sanitize_key( wp_unslash( $_POST['default_unit'] ) ) : 'each',
            'display_label'   => isset( $_POST['display_label'] ) ? sanitize_text_field( wp_unslash( $_POST['display_label'] ) ) : '',
            'rounding_rule'   => isset( $_POST['rounding_rule'] ) && 'decimal' === $_POST['rounding_rule'] ? 'decimal' : 'whole_up',
            'partial_allowed' => empty( $_POST['partial_allowed'] ) ? 0 : 1,
            'active'          => empty( $_POST['active'] ) ? 0 : 1,
            'sort_order'      => isset( $_POST['sort_order'] ) ? intval( $_POST['sort_order'] ) : 0,
        );
        if ( $id ) {
            $wpdb->update( self::table_name(), $data, array( 'id' => $id ) );
        } else {
            $wpdb->insert( self::table_name(), $data );
        }
        wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'ph_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function delete_standard() {
        wp_die( esc_html__( 'Yield standards are preserved for historical recipe data. Mark a standard inactive instead of deleting it.', 'persiano-hub' ) );
    }

    public static function admin_notice() {
        if ( empty( $_GET['ph_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $notice = sanitize_key( wp_unslash( $_GET['ph_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( 'saved' === $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Yield/package standard saved.', 'persiano-hub' ) . '</p></div>';
        } elseif ( 'missing_name' === $notice ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'A singular name is required.', 'persiano-hub' ) . '</p></div>';
        }
    }

    public static function enqueue_searchable_selects( $hook ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_persiano = $screen && ( false !== strpos( (string) $screen->id, 'persiano' ) || 'product' === $screen->post_type || 'shop_order' === $screen->post_type || 'woocommerce_page_wc-orders' === $screen->id );
        if ( ! $is_persiano ) {
            return;
        }
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_style( 'woocommerce_admin_styles' );
        $js = <<<'JS'
(function($){
    function containsMatcher(params, data){
        var term = $.trim(params.term || '').toLowerCase();
        if (!term) return data;
        if (data.children && data.children.length) {
            var copy = $.extend(true, {}, data);
            copy.children = $.grep(copy.children, function(child){
                return ((child.text || '') + ' ' + (child.id || '')).toLowerCase().indexOf(term) !== -1;
            });
            return copy.children.length ? copy : null;
        }
        return (((data.text || '') + ' ' + (data.id || '')).toLowerCase().indexOf(term) !== -1) ? data : null;
    }
    function enhance(root){
        var $root = root ? $(root) : $(document);
        $root.find('select').filter(function(){
            var $s=$(this), count=$s.find('option').length;
            return !$s.hasClass('select2-hidden-accessible') && !$s.hasClass('ph-no-search') && (count >= 8 || $s.hasClass('ph-searchable') || $s.hasClass('ph-recipe-ingredient'));
        }).each(function(){
            var $s=$(this);
            $s.selectWoo({width:'100%',allowClear:!$s.prop('required'),placeholder:$s.find('option:first').text() || 'Type to filter…',matcher:containsMatcher});
        });
    }
    $(function(){ enhance(document); });
    $(document).on('phub:rows-added', function(e, root){ enhance(root || document); });
    var observer = new MutationObserver(function(mutations){
        mutations.forEach(function(m){ if(m.addedNodes && m.addedNodes.length){ enhance(m.target); } });
    });
    $(function(){ observer.observe(document.body,{childList:true,subtree:true}); });
})(jQuery);
JS;
        wp_add_inline_script( 'wc-enhanced-select', $js );
        wp_add_inline_style( 'woocommerce_admin_styles', '.ph-costing-field .select2-container,.ph-recipe-row .select2-container,.ph-yield-grid .select2-container{width:100%!important}.ph-yield-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.ph-yield-result{background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin-top:12px}.ph-yield-result strong{font-size:16px}@media(max-width:900px){.ph-yield-grid{grid-template-columns:1fr 1fr}}@media(max-width:600px){.ph-yield-grid{grid-template-columns:1fr}}' );
    }

    public static function decimal( $value, $default = 0 ) {
        if ( '' === $value || null === $value ) {
            return (float) $default;
        }
        return (float) wc_format_decimal( $value );
    }

    public static function migrate_recipe_values( $recipe_id ) {
        if ( get_post_meta( $recipe_id, self::META_UNITS_PER_BATCH, true ) !== '' ) {
            return;
        }
        $old_qty   = self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 );
        $old_label = trim( (string) get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) );
        $standards = self::get_standards();
        $match     = null;
        foreach ( $standards as $standard ) {
            if ( false !== stripos( $old_label, $standard->singular ) || false !== stripos( $old_label, $standard->plural ) ) {
                $match = $standard;
                break;
            }
        }
        update_post_meta( $recipe_id, self::META_UNITS_PER_BATCH, max( 0.0001, $old_qty ) );
        if ( $match ) {
            update_post_meta( $recipe_id, self::META_PACKAGE_TYPE_ID, $match->id );
            update_post_meta( $recipe_id, self::META_PACKAGE_SIZE, $match->default_size );
            update_post_meta( $recipe_id, self::META_PACKAGE_UNIT, $match->default_unit );
            if ( 'each' !== $match->default_unit && 'batch' !== $match->default_unit ) {
                update_post_meta( $recipe_id, self::META_TOTAL_OUTPUT_QTY, $old_qty * (float) $match->default_size );
                update_post_meta( $recipe_id, self::META_TOTAL_OUTPUT_UNIT, $match->default_unit );
            }
        }
    }

    public static function recipe_values( $recipe_id ) {
        $type_id  = absint( get_post_meta( $recipe_id, self::META_PACKAGE_TYPE_ID, true ) );
        $standard = $type_id ? self::get_standard( $type_id ) : null;
        $total_qty = self::decimal( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true ), 1 );
        $total_unit = Persiano_Hub_Costing::canonical_recipe_unit( get_post_meta( $recipe_id, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) );
        $units_per_batch = self::decimal( get_post_meta( $recipe_id, self::META_UNITS_PER_BATCH, true ) );
        if ( $units_per_batch <= 0 ) {
            $legacy = self::decimal( get_post_meta( $recipe_id, '_persiano_recipe_portions', true ) );
            $units_per_batch = $legacy > 0 ? $legacy : 1;
        }
        return array(
            'total_output_qty'  => $total_qty,
            'total_output_unit' => $total_unit ?: 'each',
            'package_type_id'   => $type_id,
            'package_size'      => self::decimal( get_post_meta( $recipe_id, self::META_PACKAGE_SIZE, true ), $standard ? $standard->default_size : 1 ),
            'package_unit'      => sanitize_key( get_post_meta( $recipe_id, self::META_PACKAGE_UNIT, true ) ?: ( $standard ? $standard->default_unit : 'each' ) ),
            'units_per_batch'   => $units_per_batch,
            'remainder_qty'     => self::decimal( get_post_meta( $recipe_id, self::META_REMAINDER_QTY, true ) ),
            'standard'          => $standard,
        );
    }

    public static function render_recipe_fields( $recipe_id ) {
        $values    = self::recipe_values( $recipe_id );
        $standards = self::get_standards();
        $units = array( 'g' => 'g', 'kg' => 'kg', 'ml' => 'mL', 'l' => 'L', 'each' => 'each' );
        ?>
        <div class="ph-costing-section ph-structured-yield">
            <h3><?php esc_html_e( 'Sellable Yield & Packaging', 'persiano-hub' ); ?></h3>
            <p><?php esc_html_e( 'The Total batch output and Output unit above describe the physical recipe output. These fields define how that output is packaged and sold.', 'persiano-hub' ); ?></p>
            <div class="ph-yield-grid">
                <div class="ph-costing-field"><label for="persiano_package_type_id"><?php esc_html_e( 'Sellable package type', 'persiano-hub' ); ?></label><select class="widefat ph-searchable ph-yield-calc" id="persiano_package_type_id" name="persiano_package_type_id"><option value="0"><?php esc_html_e( 'Select package type…', 'persiano-hub' ); ?></option><?php foreach ( $standards as $standard ) : ?><option value="<?php echo esc_attr( $standard->id ); ?>" data-singular="<?php echo esc_attr( $standard->singular ); ?>" data-plural="<?php echo esc_attr( $standard->plural ); ?>" data-size="<?php echo esc_attr( $standard->default_size ); ?>" data-unit="<?php echo esc_attr( $standard->default_unit ); ?>" <?php selected( $values['package_type_id'], $standard->id ); ?>><?php echo esc_html( $standard->singular . ( $standard->display_label ? ' — ' . $standard->display_label : '' ) ); ?></option><?php endforeach; ?></select><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Manage package standards', 'persiano-hub' ); ?></a></p></div>
                <div class="ph-costing-field"><label for="persiano_package_size"><?php esc_html_e( 'Package size', 'persiano-hub' ); ?></label><input class="widefat ph-yield-calc" type="number" min="0.0001" step="0.0001" id="persiano_package_size" name="persiano_package_size" value="<?php echo esc_attr( $values['package_size'] ); ?>"></div>
                <div class="ph-costing-field"><label for="persiano_package_unit"><?php esc_html_e( 'Package unit', 'persiano-hub' ); ?></label><select class="widefat ph-no-search ph-yield-calc" id="persiano_package_unit" name="persiano_package_unit"><?php foreach ( $units as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $values['package_unit'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
                <div class="ph-costing-field"><label for="persiano_units_per_batch"><?php esc_html_e( 'Sellable units per batch', 'persiano-hub' ); ?></label><input class="widefat ph-recipe-live ph-yield-calc" type="number" min="0.0001" step="0.0001" id="persiano_units_per_batch" name="persiano_units_per_batch" value="<?php echo esc_attr( $values['units_per_batch'] ); ?>"><p class="description"><?php esc_html_e( 'Production Planner scales ingredient quantities using this number. Ordered units are never multiplied by a display label.', 'persiano-hub' ); ?></p></div>
            </div>
            <div class="ph-yield-result"><strong id="ph-yield-summary"></strong><div id="ph-yield-validation"></div></div>
        </div>
        <script>
        jQuery(function($){
            function sameDimension(a,b){ var w=['g','kg'],v=['ml','l']; return a===b || (w.indexOf(a)>=0&&w.indexOf(b)>=0) || (v.indexOf(a)>=0&&v.indexOf(b)>=0); }
            function baseValue(q,u){ q=parseFloat(q||0); if(u==='kg'||u==='l') return q*1000; return q; }
            function recalc(fromPackage){
                var $type=$('#persiano_package_type_id option:selected');
                if(fromPackage && $type.val()){ $('#persiano_package_size').val($type.data('size')); $('#persiano_package_unit').val($type.data('unit')); }
                var total=parseFloat($('#persiano_recipe_yield_qty').val()||0), outUnit=$('#persiano_recipe_yield_label').val();
                var size=parseFloat($('#persiano_package_size').val()||0), packUnit=$('#persiano_package_unit').val();
                var units=parseFloat($('#persiano_units_per_batch').val()||0), calculated=0, remainder=0;
                if(total>0&&size>0&&sameDimension(outUnit,packUnit)) { calculated=baseValue(total,outUnit)/baseValue(size,packUnit); remainder=baseValue(total,outUnit)-(Math.floor(calculated)*baseValue(size,packUnit)); }
                var singular=$type.data('singular')||'unit', plural=$type.data('plural')||singular+'s';
                $('#ph-yield-summary').text((units||calculated||0)+' '+((units||calculated)===1?singular:plural)+' per batch'+(remainder>0?' · remainder '+remainder.toFixed(2)+' '+packUnit:''));
                var warning='';
                if(total>0&&size>0&&!sameDimension(outUnit,packUnit)) warning='Output unit and package unit are incompatible.';
                else if(calculated>0&&units>0&&Math.abs(calculated-units)>0.01) warning='Check the values: total output ÷ package size equals '+calculated.toFixed(2)+', not '+units+'.';
                $('#ph-yield-validation').text(warning).css('color',warning?'#b32d2e':'');
                if(calculated>0 && !units) $('#persiano_units_per_batch').val(calculated.toFixed(4).replace(/0+$/,'').replace(/\.$/,''));
            }
            $('.ph-yield-calc,#persiano_recipe_yield_qty,#persiano_recipe_yield_label').on('input change',function(){ recalc(this.id==='persiano_package_type_id'); });
            recalc(false);
        });
        </script>
        <?php
    }

    public static function save_recipe_fields( $recipe_id ) {
        $total_qty   = isset( $_POST['persiano_recipe_yield_qty'] ) ? max( 0, self::decimal( wp_unslash( $_POST['persiano_recipe_yield_qty'] ) ) ) : 0;
        $output_unit = isset( $_POST['persiano_recipe_yield_label'] ) ? Persiano_Hub_Costing::canonical_recipe_unit( wp_unslash( $_POST['persiano_recipe_yield_label'] ) ) : '';
        $type_id     = isset( $_POST['persiano_package_type_id'] ) ? absint( $_POST['persiano_package_type_id'] ) : 0;
        $size        = isset( $_POST['persiano_package_size'] ) ? max( 0.0001, self::decimal( wp_unslash( $_POST['persiano_package_size'] ), 1 ) ) : 1;
        $pack_unit   = isset( $_POST['persiano_package_unit'] ) ? sanitize_key( wp_unslash( $_POST['persiano_package_unit'] ) ) : 'each';
        $units       = isset( $_POST['persiano_units_per_batch'] ) ? max( 0.0001, self::decimal( wp_unslash( $_POST['persiano_units_per_batch'] ), 1 ) ) : 1;
        $remainder   = 0;
        $factors     = array( 'g' => 1, 'kg' => 1000, 'ml' => 1, 'l' => 1000 );
        if ( $total_qty > 0 && $size > 0 && isset( $factors[ $output_unit ], $factors[ $pack_unit ] ) ) {
            $total_base = $total_qty * $factors[ $output_unit ];
            $size_base  = $size * $factors[ $pack_unit ];
            $calculated = $total_base / $size_base;
            $remainder  = max( 0, $total_base - floor( $calculated ) * $size_base );
            if ( abs( $calculated - $units ) > 0.01 ) {
                set_transient( 'persiano_hub_yield_warning_' . get_current_user_id(), sprintf( 'Recipe #%d: total output divided by package size is %.2f, but sellable units per batch is %.2f. Values were saved; please review them.', $recipe_id, $calculated, $units ), 60 );
            }
        }
        update_post_meta( $recipe_id, self::META_TOTAL_OUTPUT_QTY, $total_qty );
        update_post_meta( $recipe_id, self::META_TOTAL_OUTPUT_UNIT, $output_unit );
        update_post_meta( $recipe_id, self::META_PACKAGE_TYPE_ID, $type_id );
        update_post_meta( $recipe_id, self::META_PACKAGE_SIZE, $size );
        update_post_meta( $recipe_id, self::META_PACKAGE_UNIT, $pack_unit );
        update_post_meta( $recipe_id, self::META_UNITS_PER_BATCH, $units );
        update_post_meta( $recipe_id, self::META_REMAINDER_QTY, $remainder );
        update_post_meta( $recipe_id, '_persiano_recipe_portions', $units );
    }

    public static function yield_warning_notice() {
        $key = 'persiano_hub_yield_warning_' . get_current_user_id();
        $message = get_transient( $key );
        if ( $message ) {
            delete_transient( $key );
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }
    }
}
