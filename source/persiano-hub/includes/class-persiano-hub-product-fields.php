<?php
/**
 * WooCommerce product fields for Persiano Dish.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Product_Fields {
    const META_CONTENT_TYPE      = '_persiano_content_type';
    const META_SHOW_THIS_WEEK    = '_persiano_show_this_week';
    const META_SHOW_PANTRY       = '_persiano_show_pantry';
    const META_AVAILABLE_DATE    = '_persiano_available_date';
    const META_ORDER_DEADLINE    = '_persiano_order_deadline';
    const META_CLOSE_DEADLINE    = '_persiano_close_at_deadline';
    const META_SIZE              = '_persiano_size';
    const META_PICKUP            = '_persiano_fulfilment_pickup';
    const META_DELIVERY          = '_persiano_fulfilment_delivery';
    const META_SHIPPING          = '_persiano_fulfilment_shipping';
    const META_ALLOW_ADVANCE     = '_persiano_allow_advance_order';
    const META_ADVANCE_HOURS     = '_persiano_advance_notice_hours';
    const META_COURSE            = '_persiano_course';
    const META_DIETARY           = '_persiano_dietary_labels';
    const META_ALTERATIONS       = '_persiano_available_alterations';
    const META_ALTERATION_NOTE   = '_persiano_alteration_note';
    const META_TAX_TREATMENT     = '_persiano_tax_treatment';
    const META_FA_TITLE          = '_persiano_fa_title';
    const META_PRODUCT_FORMAT    = '_persiano_product_format';
    const META_INCLUDES          = '_persiano_includes';
    const META_MAIN_INGREDIENTS  = '_persiano_main_ingredients';
    const META_DIETARY_INFO      = '_persiano_dietary_information';
    const META_ALLERGEN_INFO     = '_persiano_allergen_information';
    const META_STORAGE           = '_persiano_storage_instructions';
    const META_REHEATING         = '_persiano_reheating_instructions';
    const META_INTERNAL_NOTES    = '_persiano_internal_notes';
    const META_LABEL_NOTES       = '_persiano_label_notes';
    const META_RECIPE_LINK       = '_persiano_recipe_link';
    const META_SUPPLIER_LINK     = '_persiano_supplier_link';
    const META_SEO_TITLE         = '_persiano_seo_title';
    const META_META_DESCRIPTION  = '_persiano_meta_description';

    public static function init() {
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_panel' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ) );

        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ) );

        add_action( 'persiano_dish_product_card_details', array( __CLASS__, 'render_product_card_details' ), 10, 1 );
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_single_product_details' ), 24 );
        add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'render_loop_product_details' ), 11 );

        add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'maybe_close_purchasing_at_deadline' ), 20, 2 );
        add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'maybe_close_purchasing_at_deadline' ), 20, 2 );

        add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_admin_product_column' ), 30 );
        add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_admin_product_column' ), 10, 2 );

        add_filter( 'woocommerce_csv_product_import_mapping_options', array( __CLASS__, 'import_mapping_options' ) );
        add_filter( 'woocommerce_csv_product_import_mapping_default_columns', array( __CLASS__, 'import_default_columns' ) );
        add_action( 'woocommerce_product_import_inserted_product_object', array( __CLASS__, 'normalize_imported_product' ), 20, 2 );
    }

    public static function get_course_options() {
        return array(
            ''             => __( 'Not specified', 'persiano-hub' ),
            'starter'      => __( 'Starter', 'persiano-hub' ),
            'snack'        => __( 'Snack & Small Plate', 'persiano-hub' ),
            'soup_ash'     => __( 'Soup & Ash', 'persiano-hub' ),
            'main'         => __( 'Main Dish', 'persiano-hub' ),
            'side'         => __( 'Side Dish', 'persiano-hub' ),
            'bread'        => __( 'Bread', 'persiano-hub' ),
            'dessert'      => __( 'Dessert & Sweet', 'persiano-hub' ),
            'condiment'    => __( 'Condiment & Accompaniment', 'persiano-hub' ),
            'other'        => __( 'Other', 'persiano-hub' ),
        );
    }

    public static function get_dietary_options() {
        return array(
            'vegetarian'         => __( 'Vegetarian', 'persiano-hub' ),
            'vegan'              => __( 'Vegan', 'persiano-hub' ),
            'gluten_free_recipe' => __( 'Gluten-free recipe', 'persiano-hub' ),
            'dairy_free_recipe'  => __( 'Dairy-free recipe', 'persiano-hub' ),
            'contains_dairy'     => __( 'Contains dairy', 'persiano-hub' ),
            'contains_nuts'      => __( 'Contains nuts', 'persiano-hub' ),
        );
    }

    public static function get_alteration_options() {
        return array(
            'vegetarian_option'  => __( 'Vegetarian option available', 'persiano-hub' ),
            'vegan_option'       => __( 'Vegan option available', 'persiano-hub' ),
            'gluten_free_option' => __( 'Gluten-free variation available', 'persiano-hub' ),
            'dairy_free_option'  => __( 'Dairy-free variation available', 'persiano-hub' ),
            'protein_choice'     => __( 'Protein choice available', 'persiano-hub' ),
            'spice_level'        => __( 'Spice level can be adjusted', 'persiano-hub' ),
            'custom_request'     => __( 'Other custom requests may be possible', 'persiano-hub' ),
        );
    }

    public static function add_product_tab( $tabs ) {
        $tabs['persiano_hub'] = array(
            'label'    => __( 'Persiano', 'persiano-hub' ),
            'target'   => 'persiano_hub_product_data',
            'class'    => array(),
            'priority' => 75,
        );
        return $tabs;
    }

    public static function render_product_panel() {
        global $post;

        $product_id = $post ? absint( $post->ID ) : 0;
        $details    = self::get_product_details( $product_id );
        ?>
        <div id="persiano_hub_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group ph-admin-intro">
                <p><strong><?php esc_html_e( 'Persiano product details', 'persiano-hub' ); ?></strong><br>
                    <?php esc_html_e( 'Use these fields for Persiano-specific display and placement. Price and stock quantity continue to use the normal WooCommerce fields.', 'persiano-hub' ); ?>
                </p>
            </div>

            <div class="options_group">
                <?php
                woocommerce_wp_text_input(
                    array(
                        'id'          => self::META_FA_TITLE,
                        'label'       => __( 'Persian title', 'persiano-hub' ),
                        'description' => __( 'Persian/Farsi product name used in bilingual content and labels.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'value'       => $details['fa_title'],
                    )
                );
                woocommerce_wp_text_input(
                    array(
                        'id'          => self::META_PRODUCT_FORMAT,
                        'label'       => __( 'Product format', 'persiano-hub' ),
                        'placeholder' => __( 'e.g. Prepared Meal, Pantry Product, Catering Tray', 'persiano-hub' ),
                        'description' => __( 'Customer-facing product type. This is separate from WooCommerce Simple/Variable product type.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'value'       => $details['product_format'],
                    )
                );
                woocommerce_wp_select(
                    array(
                        'id'          => self::META_CONTENT_TYPE,
                        'label'       => __( 'Persiano type', 'persiano-hub' ),
                        'description' => __( 'How this item is presented across the configured business channels.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'options'     => array(
                            ''              => __( 'Not specified', 'persiano-hub' ),
                            'prepared_meal' => __( 'Prepared Meal', 'persiano-hub' ),
                            'pantry'        => __( 'Pantry Product', 'persiano-hub' ),
                            'other'         => __( 'Other', 'persiano-hub' ),
                        ),
                        'value'       => $details['content_type'],
                    )
                );

                woocommerce_wp_select(
                    array(
                        'id'          => self::META_COURSE,
                        'label'       => __( 'Course / dish type', 'persiano-hub' ),
                        'description' => __( 'Used for filtering the full Our Dishes catalogue.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'options'     => self::get_course_options(),
                        'value'       => $details['course'],
                    )
                );

                woocommerce_wp_select(
                    array(
                        'id'          => self::META_TAX_TREATMENT,
                        'label'       => __( 'Tax treatment', 'persiano-hub' ),
                        'description' => __( 'Persiano prices are displayed tax-inclusive. Prepared meals default to the standard taxable rate; Pantry items can be reviewed individually.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'options'     => array(
                            'inherit'   => __( 'Keep current WooCommerce tax setting', 'persiano-hub' ),
                            'standard'  => __( 'Standard taxable — tax included in price', 'persiano-hub' ),
                            'not_taxed' => __( 'No tax on this product', 'persiano-hub' ),
                        ),
                        'value'       => $details['tax_treatment'],
                    )
                );

                woocommerce_wp_checkbox(
                    array(
                        'id'          => self::META_SHOW_THIS_WEEK,
                        'label'       => __( 'Show in This Week', 'persiano-hub' ),
                        'description' => __( 'Adds this product to the current weekly menu page.', 'persiano-hub' ),
                        'value'       => $details['show_this_week'] ? 'yes' : 'no',
                    )
                );

                woocommerce_wp_checkbox(
                    array(
                        'id'          => self::META_SHOW_PANTRY,
                        'label'       => __( 'Show in The Pantry', 'persiano-hub' ),
                        'description' => __( 'Adds this product to the Persiano Pantry page.', 'persiano-hub' ),
                        'value'       => $details['show_pantry'] ? 'yes' : 'no',
                    )
                );
                ?>
            </div>

            <div class="options_group">
                <?php
                woocommerce_wp_text_input(
                    array(
                        'id'          => self::META_AVAILABLE_DATE,
                        'label'       => __( 'Available date', 'persiano-hub' ),
                        'type'        => 'date',
                        'description' => __( 'For example, the day a prepared meal is available for pickup or delivery.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'value'       => $details['available_date'],
                    )
                );

                woocommerce_wp_text_input(
                    array(
                        'id'          => self::META_ORDER_DEADLINE,
                        'label'       => __( 'Order deadline', 'persiano-hub' ),
                        'type'        => 'datetime-local',
                        'description' => __( 'Displayed to customers as the order cutoff.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'value'       => $details['order_deadline'],
                    )
                );

                woocommerce_wp_checkbox(
                    array(
                        'id'          => self::META_CLOSE_DEADLINE,
                        'label'       => __( 'Close ordering automatically', 'persiano-hub' ),
                        'description' => __( 'Make the product unavailable for purchase once the order deadline passes.', 'persiano-hub' ),
                        'value'       => $details['close_at_deadline'] ? 'yes' : 'no',
                    )
                );

                woocommerce_wp_text_input(
                    array(
                        'id'          => self::META_SIZE,
                        'label'       => __( 'Size / package', 'persiano-hub' ),
                        'placeholder' => __( 'e.g. 500 ml, 250 g, serves 2', 'persiano-hub' ),
                        'description' => __( 'A short customer-facing size or serving description.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'value'       => $details['size'],
                    )
                );
                ?>
            </div>

            <div class="options_group ph-persiano-content-group">
                <p class="form-field"><strong><?php esc_html_e( 'Product content, labels and care', 'persiano-hub' ); ?></strong><br>
                    <span class="description"><?php esc_html_e( 'These fields are available in the Persiano CSV importer and on the product record.', 'persiano-hub' ); ?></span>
                </p>
                <?php
                $textareas = array(
                    self::META_INCLUDES         => array( __( 'Includes', 'persiano-hub' ), $details['includes'], __( 'What is included with the product or meal.', 'persiano-hub' ) ),
                    self::META_MAIN_INGREDIENTS => array( __( 'Main ingredients', 'persiano-hub' ), $details['main_ingredients'], __( 'Customer-facing ingredient summary.', 'persiano-hub' ) ),
                    self::META_DIETARY_INFO     => array( __( 'Dietary information', 'persiano-hub' ), $details['dietary_information'], __( 'Free-text dietary information in addition to the structured badges below.', 'persiano-hub' ) ),
                    self::META_ALLERGEN_INFO    => array( __( 'Allergen information', 'persiano-hub' ), $details['allergen_information'], __( 'Allergen and shared-kitchen notice.', 'persiano-hub' ) ),
                    self::META_STORAGE          => array( __( 'Storage instructions', 'persiano-hub' ), $details['storage_instructions'], __( 'How the customer should store the product.', 'persiano-hub' ) ),
                    self::META_REHEATING        => array( __( 'Reheating instructions', 'persiano-hub' ), $details['reheating_instructions'], __( 'Customer-facing reheating directions.', 'persiano-hub' ) ),
                    self::META_LABEL_NOTES      => array( __( 'Label notes', 'persiano-hub' ), $details['label_notes'], __( 'Extra information intended for printed labels.', 'persiano-hub' ) ),
                    self::META_INTERNAL_NOTES   => array( __( 'Internal product notes', 'persiano-hub' ), $details['internal_notes'], __( 'Private operational notes; not shown to customers.', 'persiano-hub' ) ),
                );
                foreach ( $textareas as $field_id => $field_data ) {
                    woocommerce_wp_textarea_input(
                        array(
                            'id'          => $field_id,
                            'label'       => $field_data[0],
                            'value'       => $field_data[1],
                            'description' => $field_data[2],
                            'desc_tip'    => true,
                        )
                    );
                }
                woocommerce_wp_text_input(
                    array(
                        'id'          => self::META_RECIPE_LINK,
                        'label'       => __( 'Recipe reference', 'persiano-hub' ),
                        'placeholder' => __( 'Recipe ID, name or URL', 'persiano-hub' ),
                        'value'       => $details['recipe_link'],
                    )
                );
                woocommerce_wp_text_input(
                    array(
                        'id'          => self::META_SUPPLIER_LINK,
                        'label'       => __( 'Supplier reference', 'persiano-hub' ),
                        'placeholder' => __( 'Supplier ID, name or URL', 'persiano-hub' ),
                        'value'       => $details['supplier_link'],
                    )
                );
                woocommerce_wp_text_input(
                    array(
                        'id'          => 'persiano_product_slug',
                        'label'       => __( 'Product slug', 'persiano-hub' ),
                        'description' => __( 'URL-friendly product name. Leave blank to let WordPress generate it.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'value'       => $product_id ? get_post_field( 'post_name', $product_id ) : '',
                    )
                );
                woocommerce_wp_text_input(
                    array(
                        'id'          => self::META_SEO_TITLE,
                        'label'       => __( 'SEO title', 'persiano-hub' ),
                        'description' => __( 'Used by Batchly and copied to Yoast or Rank Math when available.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'value'       => $details['seo_title'],
                    )
                );
                woocommerce_wp_textarea_input(
                    array(
                        'id'          => self::META_META_DESCRIPTION,
                        'label'       => __( 'Meta description', 'persiano-hub' ),
                        'description' => __( 'Recommended length is about 150–160 characters.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'value'       => $details['meta_description'],
                    )
                );
                ?>
            </div>

            <div class="options_group ph-dietary-group">
                <p class="form-field"><strong><?php esc_html_e( 'Dietary profile', 'persiano-hub' ); ?></strong><br>
                    <span class="description"><?php esc_html_e( 'Describe the standard recipe. These labels do not guarantee an allergen-free kitchen.', 'persiano-hub' ); ?></span>
                </p>
                <div class="ph-admin-choice-grid">
                    <?php foreach ( self::get_dietary_options() as $key => $label ) : ?>
                        <label><input type="checkbox" name="persiano_dietary[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $details['dietary'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="options_group ph-alterations-group">
                <p class="form-field"><strong><?php esc_html_e( 'Available alterations', 'persiano-hub' ); ?></strong><br>
                    <span class="description"><?php esc_html_e( 'Show customers which variations may be requested. These are informational for now; availability can still be confirmed manually.', 'persiano-hub' ); ?></span>
                </p>
                <div class="ph-admin-choice-grid">
                    <?php foreach ( self::get_alteration_options() as $key => $label ) : ?>
                        <label><input type="checkbox" name="persiano_alterations[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $details['alterations'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
                    <?php endforeach; ?>
                </div>
                <?php
                woocommerce_wp_textarea_input(
                    array(
                        'id'          => self::META_ALTERATION_NOTE,
                        'label'       => __( 'Alteration note', 'persiano-hub' ),
                        'placeholder' => __( 'e.g. Vegetarian version available with 24 hours notice.', 'persiano-hub' ),
                        'description' => __( 'Optional customer-facing note shown on the product page.', 'persiano-hub' ),
                        'desc_tip'    => true,
                        'value'       => $details['alteration_note'],
                    )
                );
                ?>
            </div>

            <div class="options_group ph-advance-order-group">
                <p class="form-field"><strong><?php esc_html_e( 'Advance orders when unavailable', 'persiano-hub' ); ?></strong><br>
                    <span class="description"><?php esc_html_e( 'Allow customers to order this item for a future date when the regular item is sold out or its current order window has closed.', 'persiano-hub' ); ?></span>
                </p>
                <?php
                woocommerce_wp_checkbox(
                    array(
                        'id'          => self::META_ALLOW_ADVANCE,
                        'label'       => __( 'Allow advance orders', 'persiano-hub' ),
                        'description' => __( 'Shows a future-date ordering form when this product is unavailable.', 'persiano-hub' ),
                        'value'       => $details['allow_advance_order'] ? 'yes' : 'no',
                    )
                );
                woocommerce_wp_text_input(
                    array(
                        'id'                => self::META_ADVANCE_HOURS,
                        'label'             => __( 'Minimum notice (hours)', 'persiano-hub' ),
                        'type'              => 'number',
                        'description'       => __( 'Customers cannot select a requested date sooner than this. Default: 24 hours.', 'persiano-hub' ),
                        'desc_tip'          => true,
                        'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
                        'value'             => $details['advance_notice_hours'],
                    )
                );
                ?>
            </div>

            <div class="options_group ph-fulfilment-group">
                <p class="form-field"><strong><?php esc_html_e( 'Fulfilment shown to customers', 'persiano-hub' ); ?></strong><br>
                    <span class="description"><?php esc_html_e( 'These options control which checkout fulfilment methods this item is allowed to use. Parcel shipping rates still come from your configured WooCommerce shipping services.', 'persiano-hub' ); ?></span>
                </p>
                <?php
                woocommerce_wp_checkbox(
                    array(
                        'id'    => self::META_PICKUP,
                        'label' => __( 'Pickup', 'persiano-hub' ),
                        'value' => $details['pickup'] ? 'yes' : 'no',
                    )
                );
                woocommerce_wp_checkbox(
                    array(
                        'id'    => self::META_DELIVERY,
                        'label' => __( 'Local delivery', 'persiano-hub' ),
                        'value' => $details['delivery'] ? 'yes' : 'no',
                    )
                );
                woocommerce_wp_checkbox(
                    array(
                        'id'    => self::META_SHIPPING,
                        'label' => __( 'Shipping', 'persiano-hub' ),
                        'value' => $details['shipping'] ? 'yes' : 'no',
                    )
                );
                ?>
            </div>

            <div class="options_group ph-admin-stock-note">
                <p><?php esc_html_e( 'Quantity available is managed in WooCommerce → Product data → Inventory. Batchly reads the same stock number for “Only X left” messaging.', 'persiano-hub' ); ?></p>
            </div>
        </div>
        <?php
    }

    public static function save_product_fields( $product ) {
        $product_id = $product->get_id();

        $content_type   = isset( $_POST[ self::META_CONTENT_TYPE ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_CONTENT_TYPE ] ) ) : '';
        $allowed_types  = array( '', 'prepared_meal', 'pantry', 'other' );
        $content_type   = in_array( $content_type, $allowed_types, true ) ? $content_type : '';
        $available_date = isset( $_POST[ self::META_AVAILABLE_DATE ] ) ? self::sanitize_date( wp_unslash( $_POST[ self::META_AVAILABLE_DATE ] ) ) : '';
        $deadline       = isset( $_POST[ self::META_ORDER_DEADLINE ] ) ? self::sanitize_datetime_local( wp_unslash( $_POST[ self::META_ORDER_DEADLINE ] ) ) : '';
        $size           = isset( $_POST[ self::META_SIZE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_SIZE ] ) ) : '';
        $fa_title       = isset( $_POST[ self::META_FA_TITLE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_FA_TITLE ] ) ) : '';
        $product_format = isset( $_POST[ self::META_PRODUCT_FORMAT ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_PRODUCT_FORMAT ] ) ) : '';
        $includes       = isset( $_POST[ self::META_INCLUDES ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_INCLUDES ] ) ) : '';
        $main_ingredients = isset( $_POST[ self::META_MAIN_INGREDIENTS ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_MAIN_INGREDIENTS ] ) ) : '';
        $dietary_info   = isset( $_POST[ self::META_DIETARY_INFO ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_DIETARY_INFO ] ) ) : '';
        $allergen_info  = isset( $_POST[ self::META_ALLERGEN_INFO ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_ALLERGEN_INFO ] ) ) : '';
        $storage        = isset( $_POST[ self::META_STORAGE ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_STORAGE ] ) ) : '';
        $reheating      = isset( $_POST[ self::META_REHEATING ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_REHEATING ] ) ) : '';
        $internal_notes = isset( $_POST[ self::META_INTERNAL_NOTES ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_INTERNAL_NOTES ] ) ) : '';
        $label_notes    = isset( $_POST[ self::META_LABEL_NOTES ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_LABEL_NOTES ] ) ) : '';
        $recipe_link    = isset( $_POST[ self::META_RECIPE_LINK ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_RECIPE_LINK ] ) ) : '';
        $supplier_link  = isset( $_POST[ self::META_SUPPLIER_LINK ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_SUPPLIER_LINK ] ) ) : '';
        $advance_hours  = isset( $_POST[ self::META_ADVANCE_HOURS ] ) ? max( 1, absint( $_POST[ self::META_ADVANCE_HOURS ] ) ) : 24;
        $course          = isset( $_POST[ self::META_COURSE ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_COURSE ] ) ) : '';
        $course_options  = self::get_course_options();
        $course          = isset( $course_options[ $course ] ) ? $course : '';
        $dietary         = self::sanitize_choice_list( isset( $_POST['persiano_dietary'] ) ? wp_unslash( $_POST['persiano_dietary'] ) : array(), array_keys( self::get_dietary_options() ) );
        $alterations     = self::sanitize_choice_list( isset( $_POST['persiano_alterations'] ) ? wp_unslash( $_POST['persiano_alterations'] ) : array(), array_keys( self::get_alteration_options() ) );
        $alteration_note = isset( $_POST[ self::META_ALTERATION_NOTE ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::META_ALTERATION_NOTE ] ) ) : '';
        $tax_treatment   = isset( $_POST[ self::META_TAX_TREATMENT ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_TAX_TREATMENT ] ) ) : 'inherit';
        $tax_treatment   = in_array( $tax_treatment, array( 'inherit', 'standard', 'not_taxed' ), true ) ? $tax_treatment : 'inherit';
        $previous_tax_treatment = sanitize_key( get_post_meta( $product_id, self::META_TAX_TREATMENT, true ) );
        if ( 'prepared_meal' === $content_type && ! $previous_tax_treatment && 'inherit' === $tax_treatment ) {
            $tax_treatment = 'standard';
        }

        $product->update_meta_data( self::META_CONTENT_TYPE, $content_type );
        $product->update_meta_data( self::META_FA_TITLE, $fa_title );
        $product->update_meta_data( self::META_PRODUCT_FORMAT, $product_format );
        $product->update_meta_data( self::META_INCLUDES, $includes );
        $product->update_meta_data( self::META_MAIN_INGREDIENTS, $main_ingredients );
        $product->update_meta_data( self::META_DIETARY_INFO, $dietary_info );
        $product->update_meta_data( self::META_ALLERGEN_INFO, $allergen_info );
        $product->update_meta_data( self::META_STORAGE, $storage );
        $product->update_meta_data( self::META_REHEATING, $reheating );
        $product->update_meta_data( self::META_INTERNAL_NOTES, $internal_notes );
        $product->update_meta_data( self::META_LABEL_NOTES, $label_notes );
        $product->update_meta_data( self::META_RECIPE_LINK, $recipe_link );
        $product->update_meta_data( self::META_SUPPLIER_LINK, $supplier_link );
        $product->update_meta_data( self::META_COURSE, $course );
        $product->update_meta_data( self::META_DIETARY, $dietary );
        $product->update_meta_data( self::META_ALTERATIONS, $alterations );
        $product->update_meta_data( self::META_ALTERATION_NOTE, $alteration_note );
        $product->update_meta_data( self::META_TAX_TREATMENT, $tax_treatment );
        $product->update_meta_data( self::META_AVAILABLE_DATE, $available_date );
        $product->update_meta_data( self::META_ORDER_DEADLINE, $deadline );
        $product->update_meta_data( self::META_SIZE, $size );
        $product->update_meta_data( self::META_ALLOW_ADVANCE, isset( $_POST[ self::META_ALLOW_ADVANCE ] ) ? 'yes' : 'no' );
        $product->update_meta_data( self::META_ADVANCE_HOURS, $advance_hours );
        $product->update_meta_data( self::META_SHOW_THIS_WEEK, isset( $_POST[ self::META_SHOW_THIS_WEEK ] ) ? 'yes' : 'no' );
        $product->update_meta_data( self::META_SHOW_PANTRY, isset( $_POST[ self::META_SHOW_PANTRY ] ) ? 'yes' : 'no' );
        $product->update_meta_data( self::META_CLOSE_DEADLINE, isset( $_POST[ self::META_CLOSE_DEADLINE ] ) ? 'yes' : 'no' );
        $product->update_meta_data( self::META_PICKUP, isset( $_POST[ self::META_PICKUP ] ) ? 'yes' : 'no' );
        $product->update_meta_data( self::META_DELIVERY, isset( $_POST[ self::META_DELIVERY ] ) ? 'yes' : 'no' );
        $product->update_meta_data( self::META_SHIPPING, isset( $_POST[ self::META_SHIPPING ] ) ? 'yes' : 'no' );

        if ( 'standard' === $tax_treatment ) {
            $product->set_tax_status( 'taxable' );
            $product->set_tax_class( '' );
        } elseif ( 'not_taxed' === $tax_treatment ) {
            $product->set_tax_status( 'none' );
            $product->set_tax_class( '' );
        }

        self::sync_special_category( $product_id, 'this-week', isset( $_POST[ self::META_SHOW_THIS_WEEK ] ) );
        self::sync_special_category( $product_id, 'pantry', isset( $_POST[ self::META_SHOW_PANTRY ] ) );
    }

    private static function sync_special_category( $product_id, $slug, $enabled ) {
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

    public static function get_product_details( $product_id ) {
        $product_id   = absint( $product_id );
        $content_type = sanitize_key( get_post_meta( $product_id, self::META_CONTENT_TYPE, true ) );

        if ( ! $content_type ) {
            if ( has_term( 'this-week', 'product_cat', $product_id ) ) {
                $content_type = 'prepared_meal';
            } elseif ( has_term( 'pantry', 'product_cat', $product_id ) ) {
                $content_type = 'pantry';
            }
        }

        return array(
            'fa_title'            => sanitize_text_field( get_post_meta( $product_id, self::META_FA_TITLE, true ) ),
            'product_format'       => sanitize_text_field( get_post_meta( $product_id, self::META_PRODUCT_FORMAT, true ) ),
            'includes'             => sanitize_textarea_field( get_post_meta( $product_id, self::META_INCLUDES, true ) ),
            'main_ingredients'     => sanitize_textarea_field( get_post_meta( $product_id, self::META_MAIN_INGREDIENTS, true ) ),
            'dietary_information'  => sanitize_textarea_field( get_post_meta( $product_id, self::META_DIETARY_INFO, true ) ),
            'allergen_information' => sanitize_textarea_field( get_post_meta( $product_id, self::META_ALLERGEN_INFO, true ) ),
            'storage_instructions' => sanitize_textarea_field( get_post_meta( $product_id, self::META_STORAGE, true ) ),
            'reheating_instructions' => sanitize_textarea_field( get_post_meta( $product_id, self::META_REHEATING, true ) ),
            'internal_notes'       => sanitize_textarea_field( get_post_meta( $product_id, self::META_INTERNAL_NOTES, true ) ),
            'label_notes'          => sanitize_textarea_field( get_post_meta( $product_id, self::META_LABEL_NOTES, true ) ),
            'recipe_link'          => sanitize_text_field( get_post_meta( $product_id, self::META_RECIPE_LINK, true ) ),
            'supplier_link'        => sanitize_text_field( get_post_meta( $product_id, self::META_SUPPLIER_LINK, true ) ),
            'seo_title'            => sanitize_text_field( get_post_meta( $product_id, self::META_SEO_TITLE, true ) ),
            'meta_description'     => sanitize_textarea_field( get_post_meta( $product_id, self::META_META_DESCRIPTION, true ) ),
            'content_type'      => $content_type,
            'show_this_week'    => 'yes' === get_post_meta( $product_id, self::META_SHOW_THIS_WEEK, true ) || has_term( 'this-week', 'product_cat', $product_id ),
            'show_pantry'       => 'yes' === get_post_meta( $product_id, self::META_SHOW_PANTRY, true ) || has_term( 'pantry', 'product_cat', $product_id ),
            'available_date'    => self::sanitize_date( get_post_meta( $product_id, self::META_AVAILABLE_DATE, true ) ),
            'order_deadline'    => self::sanitize_datetime_local( get_post_meta( $product_id, self::META_ORDER_DEADLINE, true ) ),
            'close_at_deadline' => 'yes' === get_post_meta( $product_id, self::META_CLOSE_DEADLINE, true ),
            'size'              => sanitize_text_field( get_post_meta( $product_id, self::META_SIZE, true ) ),
            'pickup'            => 'yes' === get_post_meta( $product_id, self::META_PICKUP, true ),
            'delivery'          => 'yes' === get_post_meta( $product_id, self::META_DELIVERY, true ),
            'shipping'          => 'yes' === get_post_meta( $product_id, self::META_SHIPPING, true ),
            'allow_advance_order' => 'yes' === get_post_meta( $product_id, self::META_ALLOW_ADVANCE, true ),
            'advance_notice_hours' => max( 1, absint( get_post_meta( $product_id, self::META_ADVANCE_HOURS, true ) ) ?: 24 ),
            'course'               => self::sanitize_choice( get_post_meta( $product_id, self::META_COURSE, true ), array_keys( self::get_course_options() ) ),
            'dietary'              => self::sanitize_choice_list( get_post_meta( $product_id, self::META_DIETARY, true ), array_keys( self::get_dietary_options() ) ),
            'alterations'          => self::sanitize_choice_list( get_post_meta( $product_id, self::META_ALTERATIONS, true ), array_keys( self::get_alteration_options() ) ),
            'alteration_note'      => sanitize_textarea_field( get_post_meta( $product_id, self::META_ALTERATION_NOTE, true ) ),
            'tax_treatment'        => self::get_tax_treatment( $product_id, $content_type ),
        );
    }

    public static function import_mapping_options( $options ) {
        $fields = self::import_field_map();
        foreach ( $fields as $column => $field ) {
            $options[ $field['map'] ] = $field['label'];
        }
        return $options;
    }

    public static function import_default_columns( $columns ) {
        foreach ( self::import_field_map() as $column => $field ) {
            $columns[ $column ] = $field['map'];
        }
        // Common aliases used in earlier Persiano CSV files.
        $columns['persian_name'] = 'meta:' . self::META_FA_TITLE;
        $columns['fa_title'] = 'meta:' . self::META_FA_TITLE;
        $columns['product_type'] = 'meta:' . self::META_PRODUCT_FORMAT;
        $columns['serving_size'] = 'meta:' . self::META_SIZE;
        $columns['minimum_quantity'] = 'meta:' . Persiano_Hub_Workspace::META_ADVANCE_MIN_QTY;
        $columns['minimum_advance_order_quantity'] = 'meta:' . Persiano_Hub_Workspace::META_ADVANCE_MIN_QTY;
        $columns['advance_notice_hours'] = 'meta:' . self::META_ADVANCE_HOURS;
        $columns['seasonal_return_date'] = 'meta:' . Persiano_Hub_Workspace::META_RETURN_DATE;
        $columns['return_date'] = 'meta:' . Persiano_Hub_Workspace::META_RETURN_DATE;
        $columns['unavailable_message'] = 'meta:' . Persiano_Hub_Workspace::META_UNAVAILABLE_REASON;
        $columns['slug'] = 'slug';
        $columns['seo_title'] = 'meta:' . self::META_SEO_TITLE;
        $columns['meta_description'] = 'meta:' . self::META_META_DESCRIPTION;
        return $columns;
    }

    private static function import_field_map() {
        return array(
            'persian_name' => array( 'map' => 'meta:' . self::META_FA_TITLE, 'label' => __( 'Persiano: Persian title', 'persiano-hub' ) ),
            'product_format' => array( 'map' => 'meta:' . self::META_PRODUCT_FORMAT, 'label' => __( 'Persiano: Product format', 'persiano-hub' ) ),
            'serving_size' => array( 'map' => 'meta:' . self::META_SIZE, 'label' => __( 'Persiano: Serving size / package', 'persiano-hub' ) ),
            'includes' => array( 'map' => 'meta:' . self::META_INCLUDES, 'label' => __( 'Persiano: Includes', 'persiano-hub' ) ),
            'main_ingredients' => array( 'map' => 'meta:' . self::META_MAIN_INGREDIENTS, 'label' => __( 'Persiano: Main ingredients', 'persiano-hub' ) ),
            'dietary_information' => array( 'map' => 'meta:' . self::META_DIETARY_INFO, 'label' => __( 'Persiano: Dietary information', 'persiano-hub' ) ),
            'allergen_information' => array( 'map' => 'meta:' . self::META_ALLERGEN_INFO, 'label' => __( 'Persiano: Allergen information', 'persiano-hub' ) ),
            'storage_instructions' => array( 'map' => 'meta:' . self::META_STORAGE, 'label' => __( 'Persiano: Storage instructions', 'persiano-hub' ) ),
            'reheating_instructions' => array( 'map' => 'meta:' . self::META_REHEATING, 'label' => __( 'Persiano: Reheating instructions', 'persiano-hub' ) ),
            'persiano_availability' => array( 'map' => 'meta:' . Persiano_Hub_Workspace::META_AVAILABILITY, 'label' => __( 'Persiano: Availability', 'persiano-hub' ) ),
            'available_date' => array( 'map' => 'meta:' . self::META_AVAILABLE_DATE, 'label' => __( 'Persiano: Available / fulfilment date', 'persiano-hub' ) ),
            'order_deadline' => array( 'map' => 'meta:' . self::META_ORDER_DEADLINE, 'label' => __( 'Persiano: Order deadline', 'persiano-hub' ) ),
            'advance_notice_hours' => array( 'map' => 'meta:' . self::META_ADVANCE_HOURS, 'label' => __( 'Persiano: Advance notice hours', 'persiano-hub' ) ),
            'minimum_quantity' => array( 'map' => 'meta:' . Persiano_Hub_Workspace::META_ADVANCE_MIN_QTY, 'label' => __( 'Persiano: Minimum advance-order quantity', 'persiano-hub' ) ),
            'seasonal_return_date' => array( 'map' => 'meta:' . Persiano_Hub_Workspace::META_RETURN_DATE, 'label' => __( 'Persiano: Seasonal return date', 'persiano-hub' ) ),
            'unavailable_message' => array( 'map' => 'meta:' . Persiano_Hub_Workspace::META_UNAVAILABLE_REASON, 'label' => __( 'Persiano: Unavailable message', 'persiano-hub' ) ),
            'label_notes' => array( 'map' => 'meta:' . self::META_LABEL_NOTES, 'label' => __( 'Persiano: Label notes', 'persiano-hub' ) ),
            'internal_notes' => array( 'map' => 'meta:' . self::META_INTERNAL_NOTES, 'label' => __( 'Persiano: Internal notes', 'persiano-hub' ) ),
            'recipe_link' => array( 'map' => 'meta:' . self::META_RECIPE_LINK, 'label' => __( 'Persiano: Recipe reference', 'persiano-hub' ) ),
            'supplier_link' => array( 'map' => 'meta:' . self::META_SUPPLIER_LINK, 'label' => __( 'Persiano: Supplier reference', 'persiano-hub' ) ),
            'slug' => array( 'map' => 'slug', 'label' => __( 'Product slug', 'persiano-hub' ) ),
            'seo_title' => array( 'map' => 'meta:' . self::META_SEO_TITLE, 'label' => __( 'Persiano: SEO title', 'persiano-hub' ) ),
            'meta_description' => array( 'map' => 'meta:' . self::META_META_DESCRIPTION, 'label' => __( 'Persiano: Meta description', 'persiano-hub' ) ),
        );
    }

    public static function normalize_imported_product( $product, $data ) {
        if ( ! $product instanceof WC_Product ) {
            return;
        }
        $availability = strtolower( trim( (string) $product->get_meta( Persiano_Hub_Workspace::META_AVAILABILITY, true ) ) );
        $availability_aliases = array(
            'this week' => 'this_week', 'this_week' => 'this_week',
            'available now' => 'available', 'available' => 'available', 'in stock' => 'available',
            'advance order' => 'advance', 'advanced order' => 'advance', 'advance' => 'advance',
            'unavailable' => 'unavailable', 'seasonal' => 'unavailable',
        );
        if ( isset( $availability_aliases[ $availability ] ) ) {
            Persiano_Hub_Workspace::sync_availability( $product->get_id(), $availability_aliases[ $availability ] );
        }

        $format = trim( (string) $product->get_meta( self::META_PRODUCT_FORMAT, true ) );
        if ( $format && ! $product->get_meta( self::META_CONTENT_TYPE, true ) ) {
            $format_lc = strtolower( $format );
            $type = false !== strpos( $format_lc, 'pantry' ) ? 'pantry' : ( false !== strpos( $format_lc, 'meal' ) ? 'prepared_meal' : 'other' );
            $product->update_meta_data( self::META_CONTENT_TYPE, $type );
        }

        $seo_title = trim( (string) $product->get_meta( self::META_SEO_TITLE, true ) );
        $meta_description = trim( (string) $product->get_meta( self::META_META_DESCRIPTION, true ) );
        if ( $seo_title ) {
            $product->update_meta_data( '_yoast_wpseo_title', $seo_title );
            $product->update_meta_data( 'rank_math_title', $seo_title );
        }
        if ( $meta_description ) {
            $product->update_meta_data( '_yoast_wpseo_metadesc', $meta_description );
            $product->update_meta_data( 'rank_math_description', $meta_description );
        }

        $product->set_backorders( 'no' );
        $product->save();
    }

    public static function render_product_card_details( $product ) {
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $details     = self::get_product_details( $product->get_id() );
        $fulfilment  = self::get_fulfilment_label( $details );
        $rows        = array();

        if ( 'prepared_meal' === $details['content_type'] || $details['show_this_week'] ) {
            if ( $details['available_date'] ) {
                $rows[] = array( 'label' => __( 'Available', 'persiano-hub' ), 'value' => self::format_date( $details['available_date'] ) );
            }
            if ( $details['order_deadline'] ) {
                $rows[] = array( 'label' => __( 'Order by', 'persiano-hub' ), 'value' => self::format_datetime( $details['order_deadline'] ) );
            }
            if ( $fulfilment ) {
                $rows[] = array( 'label' => __( 'Available by', 'persiano-hub' ), 'value' => $fulfilment );
            }
        } else {
            if ( $details['size'] ) {
                $rows[] = array( 'label' => __( 'Size', 'persiano-hub' ), 'value' => $details['size'] );
            }
            if ( $fulfilment ) {
                $rows[] = array( 'label' => __( 'Available by', 'persiano-hub' ), 'value' => $fulfilment );
            }
        }

        if ( ! empty( $rows ) ) {
            echo '<div class="ph-card-summary" aria-label="' . esc_attr__( 'Product availability', 'persiano-hub' ) . '">';
            foreach ( array_slice( $rows, 0, 3 ) as $row ) {
                printf(
                    '<div class="ph-card-summary-row"><span>%1$s</span><strong>%2$s</strong></div>',
                    esc_html( $row['label'] ),
                    esc_html( $row['value'] )
                );
            }
            echo '</div>';
        }

        self::render_dietary_badges( $details, 'card' );
        self::render_alteration_hint( $details, 'card' );
    }

    public static function render_loop_product_details() {
        global $product;

        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $details = self::get_product_details( $product->get_id() );
        $facts   = self::build_display_facts( $details, 'loop' );

        if ( ! empty( $facts ) ) {
            echo '<div class="ph-loop-facts">';
            foreach ( $facts as $fact ) {
                printf( '<span>%1$s %2$s</span>', esc_html( $fact['label'] ), esc_html( $fact['value'] ) );
            }
            echo '</div>';
        }
        self::render_dietary_badges( $details, 'loop' );
    }

    public static function render_single_product_details() {
        global $product;

        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $details = self::get_product_details( $product->get_id() );
        $facts   = self::build_display_facts( $details, 'single' );

        if ( ! empty( $facts ) ) {
            echo '<div class="ph-product-facts ph-product-facts--single" aria-label="' . esc_attr__( 'Product details', 'persiano-hub' ) . '">';
            foreach ( $facts as $fact ) {
                printf( '<div class="ph-product-fact"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $fact['label'] ), esc_html( $fact['value'] ) );
            }
            echo '</div>';
        }

        self::render_dietary_badges( $details, 'single' );
        self::render_alteration_hint( $details, 'single' );
    }

    private static function build_display_facts( $details, $context = 'single' ) {
        $facts = array();

        if ( ! empty( $details['available_date'] ) ) {
            $facts[] = array(
                'label' => __( 'Available', 'persiano-hub' ),
                'value' => self::format_date( $details['available_date'] ),
            );
        }

        if ( ! empty( $details['order_deadline'] ) ) {
            $facts[] = array(
                'label' => __( 'Order by', 'persiano-hub' ),
                'value' => self::format_datetime( $details['order_deadline'] ),
            );
        }

        if ( ! empty( $details['size'] ) ) {
            $facts[] = array(
                'label' => __( 'Size', 'persiano-hub' ),
                'value' => $details['size'],
            );
        }

        $fulfilment = self::get_fulfilment_label( $details );
        if ( $fulfilment && 'loop' !== $context ) {
            $facts[] = array(
                'label' => __( 'Available by', 'persiano-hub' ),
                'value' => $fulfilment,
            );
        }

        if ( 'card' === $context ) {
            return array_slice( $facts, 0, 3 );
        }

        if ( 'loop' === $context ) {
            return array_slice( $facts, 0, 2 );
        }

        return $facts;
    }

    private static function render_dietary_badges( $details, $context = 'single' ) {
        if ( empty( $details['dietary'] ) ) {
            return;
        }

        $options = self::get_dietary_options();
        $class   = 'single' === $context ? 'ph-dietary-badges ph-dietary-badges--single' : 'ph-dietary-badges';
        echo '<div class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Dietary information', 'persiano-hub' ) . '">';
        foreach ( $details['dietary'] as $key ) {
            if ( isset( $options[ $key ] ) ) {
                printf( '<span class="ph-dietary-badge ph-dietary-badge--%1$s">%2$s</span>', esc_attr( $key ), esc_html( $options[ $key ] ) );
            }
        }
        echo '</div>';
    }

    private static function render_alteration_hint( $details, $context = 'single' ) {
        if ( empty( $details['alterations'] ) && empty( $details['alteration_note'] ) ) {
            return;
        }

        $options = self::get_alteration_options();
        $labels  = array();
        foreach ( $details['alterations'] as $key ) {
            if ( isset( $options[ $key ] ) ) {
                $labels[] = $options[ $key ];
            }
        }

        if ( 'card' === $context ) {
            if ( $labels ) {
                echo '<div class="ph-card-alteration">' . esc_html( $labels[0] ) . '</div>';
            }
            return;
        }

        if ( 'loop' === $context ) {
            return;
        }

        echo '<div class="ph-alteration-box">';
        echo '<strong>' . esc_html__( 'Available variations', 'persiano-hub' ) . '</strong>';
        if ( $labels ) {
            echo '<ul>';
            foreach ( $labels as $label ) {
                echo '<li>' . esc_html( $label ) . '</li>';
            }
            echo '</ul>';
        }
        if ( ! empty( $details['alteration_note'] ) ) {
            echo '<p>' . esc_html( $details['alteration_note'] ) . '</p>';
        }
        echo '</div>';
    }

    private static function get_tax_treatment( $product_id, $content_type ) {
        $saved = sanitize_key( get_post_meta( $product_id, self::META_TAX_TREATMENT, true ) );
        if ( in_array( $saved, array( 'inherit', 'standard', 'not_taxed' ), true ) ) {
            return $saved;
        }
        return 'prepared_meal' === $content_type ? 'standard' : 'inherit';
    }

    private static function sanitize_choice( $value, $allowed ) {
        $value = sanitize_key( (string) $value );
        return in_array( $value, $allowed, true ) ? $value : '';
    }

    private static function sanitize_choice_list( $value, $allowed ) {
        if ( ! is_array( $value ) ) {
            $value = $value ? array( $value ) : array();
        }
        $clean = array();
        foreach ( $value as $item ) {
            $item = sanitize_key( (string) $item );
            if ( in_array( $item, $allowed, true ) ) {
                $clean[] = $item;
            }
        }
        return array_values( array_unique( $clean ) );
    }

    private static function get_fulfilment_label( $details ) {
        $methods = array();
        if ( ! empty( $details['pickup'] ) ) {
            $methods[] = __( 'Pickup', 'persiano-hub' );
        }
        if ( ! empty( $details['delivery'] ) ) {
            $methods[] = __( 'Local delivery', 'persiano-hub' );
        }
        if ( ! empty( $details['shipping'] ) ) {
            $methods[] = __( 'Shipping', 'persiano-hub' );
        }
        return implode( ' · ', $methods );
    }

    public static function maybe_close_purchasing_at_deadline( $purchasable, $product ) {
        if ( ! $purchasable || ! $product instanceof WC_Product ) {
            return $purchasable;
        }

        $parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
        $details   = self::get_product_details( $parent_id );

        if ( empty( $details['close_at_deadline'] ) || empty( $details['order_deadline'] ) ) {
            return $purchasable;
        }

        $deadline = self::datetime_from_local_value( $details['order_deadline'] );
        if ( ! $deadline ) {
            return $purchasable;
        }

        $now = new DateTimeImmutable( 'now', wp_timezone() );
        return $now < $deadline;
    }

    public static function add_admin_product_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            if ( 'name' === $key ) {
                $new_columns['persiano_hub'] = __( 'Persiano', 'persiano-hub' );
            }
        }
        return $new_columns;
    }

    public static function render_admin_product_column( $column, $post_id ) {
        if ( 'persiano_hub' !== $column ) {
            return;
        }

        $details = self::get_product_details( $post_id );
        $labels  = array(
            'prepared_meal' => __( 'Prepared Meal', 'persiano-hub' ),
            'pantry'        => __( 'Pantry', 'persiano-hub' ),
            'other'         => __( 'Other', 'persiano-hub' ),
        );

        if ( ! empty( $details['content_type'] ) && isset( $labels[ $details['content_type'] ] ) ) {
            echo '<strong>' . esc_html( $labels[ $details['content_type'] ] ) . '</strong><br>';
        }
        if ( $details['show_this_week'] ) {
            echo '<span class="ph-admin-pill">' . esc_html__( 'This Week', 'persiano-hub' ) . '</span> ';
        }
        if ( $details['show_pantry'] ) {
            echo '<span class="ph-admin-pill">' . esc_html__( 'Pantry', 'persiano-hub' ) . '</span>';
        }
        if ( $details['available_date'] ) {
            echo '<br><small>' . esc_html( self::format_date( $details['available_date'] ) ) . '</small>';
        }
    }

    public static function admin_assets( $hook ) {
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook && 'edit.php' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'persiano-hub-admin', PERSIANO_HUB_URL . 'assets/css/admin.css', array(), PERSIANO_HUB_VERSION );
    }

    public static function frontend_assets() {
        wp_enqueue_style( 'persiano-hub', PERSIANO_HUB_URL . 'assets/css/frontend.css', array(), PERSIANO_HUB_VERSION );
    }

    private static function sanitize_date( $value ) {
        $value = sanitize_text_field( (string) $value );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
    }

    private static function sanitize_datetime_local( $value ) {
        $value = sanitize_text_field( (string) $value );
        return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
    }

    private static function format_date( $value ) {
        try {
            $date = new DateTimeImmutable( $value . ' 12:00:00', wp_timezone() );
            return wp_date( 'l, M j', $date->getTimestamp(), wp_timezone() );
        } catch ( Exception $e ) {
            return $value;
        }
    }

    private static function format_datetime( $value ) {
        $date = self::datetime_from_local_value( $value );
        if ( ! $date ) {
            return $value;
        }
        return wp_date( 'D, M j · g:i a', $date->getTimestamp(), wp_timezone() );
    }

    private static function datetime_from_local_value( $value ) {
        try {
            return new DateTimeImmutable( $value, wp_timezone() );
        } catch ( Exception $e ) {
            return null;
        }
    }
}
