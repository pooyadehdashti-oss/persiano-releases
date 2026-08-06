<?php
/**
 * Food label generation for Persiano Dish.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Labels {
    const PAGE_SLUG = 'persiano-hub-labels';

    const META_TITLE_FA      = '_persiano_label_title_fa';
    const META_SUBTITLE      = '_persiano_label_subtitle';
    const META_INGREDIENTS   = '_persiano_label_ingredients';
    const META_ALLERGENS     = '_persiano_label_allergens';
    const META_STORAGE       = '_persiano_label_storage';
    const META_PREPARATION   = '_persiano_label_preparation';
    const META_BEST_BEFORE   = '_persiano_label_best_before';
    const META_NET_QUANTITY  = '_persiano_label_net_quantity';
    const META_NOTES         = '_persiano_label_notes';
    const META_DEFAULT_FORMAT = '_persiano_label_default_format';
    const META_SHELF_DAYS     = '_persiano_label_shelf_life_days';
    const META_SHOW_BARCODE   = '_persiano_label_show_barcode';
    const META_SHOW_QR        = '_persiano_label_show_qr';
    const META_QR_MODE        = '_persiano_label_qr_mode';
    const META_QR_CUSTOM      = '_persiano_label_qr_custom';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 24 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_action( 'admin_post_persiano_hub_print_labels', array( __CLASS__, 'handle_print' ) );
        add_filter( 'post_row_actions', array( __CLASS__, 'product_row_action' ), 10, 2 );
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Food Labels', 'persiano-hub' ),
            __( 'Food Labels', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function admin_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( self::PAGE_SLUG !== $page && 'persiano-hub_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_register_style( 'persiano-hub-labels-admin', false, array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-hub-labels-admin' );
        wp_add_inline_style(
            'persiano-hub-labels-admin',
            '.ph-labels-wrap{max-width:1240px}.ph-labels-wrap *{box-sizing:border-box}.ph-label-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:22px 24px;margin:18px 0;clear:both}.ph-label-card h2,.ph-label-card h3{margin-top:0}.ph-label-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}.ph-label-grid--3{grid-template-columns:repeat(3,minmax(0,1fr))}.ph-label-field{display:flex!important;flex-direction:column;gap:6px;min-width:0;margin:0!important}.ph-label-field>span{font-weight:600}.ph-label-field input[type=text],.ph-label-field input[type=url],.ph-label-field input[type=number],.ph-label-field input[type=date],.ph-label-field textarea,.ph-label-field select{width:100%!important;max-width:none!important;min-height:38px}.ph-label-field textarea{min-height:88px;resize:vertical}.ph-label-help{color:#646970;font-size:12px;line-height:1.45}.ph-label-actions{display:flex;gap:10px;align-items:center;justify-content:flex-end}.ph-labels-table{width:100%;border-collapse:separate;border-spacing:0 10px}.ph-labels-table td{vertical-align:top;padding:6px 12px 6px 0;min-width:170px}.ph-labels-table tr{display:grid;grid-template-columns:repeat(3,minmax(210px,1fr));gap:12px}.ph-label-product-heading{display:flex;gap:14px;align-items:center}.ph-label-product-heading img{width:72px;height:72px;object-fit:cover;border-radius:10px;border:1px solid #e5e5e5}.ph-label-muted{color:#646970}.ph-label-inline{display:flex;align-items:center;gap:8px}.ph-label-inline label{margin:0}.ph-label-checkbox{display:flex!important;gap:8px;align-items:flex-start;line-height:1.35}.ph-label-checkbox input{margin-top:3px;flex:0 0 auto}.ph-slot-tools{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.ph-sheet-slots{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;max-width:720px;margin-top:12px}.ph-sheet-slot{border:1px solid #c3c4c7;border-radius:8px;padding:10px;background:#f8f9fa;display:grid;grid-template-columns:auto 34px 1fr;align-items:center;gap:8px}.ph-sheet-slot strong{min-width:26px}.ph-sheet-options{display:none}.ph-sheet-note{margin-top:8px;color:#646970}.ph-mixed-sheet{margin-top:18px;padding-top:16px;border-top:1px solid #dcdcde}.ph-mixed-row{display:grid;grid-template-columns:minmax(220px,1.4fr) 80px repeat(3,minmax(120px,.8fr));gap:10px;margin:10px 0;align-items:end}.ph-mixed-row select,.ph-mixed-row input{width:100%}.ph-sheet-item-card{border:1px solid #dcdcde;border-radius:10px;padding:16px;margin:12px 0;background:#fbfbfc}.ph-sheet-item-grid{display:grid;grid-template-columns:minmax(240px,2fr) 90px repeat(3,minmax(145px,1fr));gap:12px}.ph-sheet-item-overrides{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}.ph-wizard-step{display:inline-flex;width:28px;height:28px;border-radius:50%;background:#8e2435;color:#fff;align-items:center;justify-content:center;margin-right:8px;font-weight:700}.ph-mixed-help{color:#646970;font-size:12px;max-width:820px}.ph-wizard-panel{border:1px solid #dcdcde;border-radius:12px;padding:18px;margin:14px 0;background:#fff}.ph-wizard-panel[hidden]{display:none!important}.ph-wizard-nav{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}.ph-wizard-progress{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.ph-wizard-progress span{padding:7px 11px;border-radius:999px;background:#eef0f2;color:#50575e;font-weight:600}.ph-wizard-progress span.is-active{background:#8e2435;color:#fff}.ph-slot-status{font-weight:700;color:#8e2435}.ph-sheet-item-card.is-inactive{display:none}.ph-review-list{margin:0;padding-left:20px}.ph-review-list li{margin:6px 0}.ph-label-fit-note{background:#fff8e5;border-left:4px solid #dba617;padding:10px 12px;margin:12px 0}@media(max-width:1100px){.ph-sheet-item-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ph-labels-table tr{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:782px){.ph-label-grid,.ph-label-grid--3,.ph-sheet-item-grid,.ph-sheet-item-overrides,.ph-labels-table tr,.ph-sheet-slots{grid-template-columns:1fr}.ph-label-actions,.ph-wizard-nav{justify-content:flex-start}.ph-label-card{padding:16px}}'
        );
        wp_enqueue_script( 'jquery' );
        wp_add_inline_script( 'jquery', <<<'JS'
jQuery(function($){
 function calc(packed,days){days=parseInt(days,10)||0;if(!packed||days<1)return '';var d=new Date(packed+'T12:00:00');d.setDate(d.getDate()+days);return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
 function updateBestBefore(){var v=calc($('[name=packed_on]').val(),$('[name=shelf_life_days]').val());if(v)$('[name=best_before_date]').val(v);}
 $(document).on('change input','[name=packed_on],[name=shelf_life_days]',updateBestBefore);
 $(document).on('change input','.ph-sheet-item-card [data-packed],.ph-sheet-item-card [data-shelf]',function(){var card=$(this).closest('.ph-sheet-item-card'),v=calc(card.find('[data-packed]').val(),card.find('[data-shelf]').val());if(v)card.find('[data-best]').val(v);});
 function toggleFormatFields(){var format=$('#ph-label-format').val()||$('input[name=label_format]').val();$('.ph-continuous-height').toggle(format==='continuous_3');$('.ph-sheet-options').toggle(format==='sheet_avery_5163');$('.ph-standard-copy-field').toggle(format!=='sheet_avery_5163');}
 $(document).on('change','#ph-label-format',function(){this.form.submit();});toggleFormatFields();updateBestBefore();
 var $wizard=$('.ph-label-wizard'); if(!$wizard.length)return;
 var step=1, maxStep=3, itemIndex=0;
 function selectedSlots(){return $('.ph-sheet-slot input:checked').map(function(){return this.value;}).get();}
 $(document).on('click','[data-slot-action]',function(e){
   e.preventDefault();
   var action=$(this).data('slot-action'), boxes=$('.ph-sheet-slot input:not(:disabled)');
   if(action==='all') boxes.prop('checked',true);
   if(action==='clear') boxes.prop('checked',false);
   if(action==='left') boxes.prop('checked',false).filter(function(){return /^L/.test(this.value);}).prop('checked',true);
   if(action==='right') boxes.prop('checked',false).filter(function(){return /^R/.test(this.value);}).prop('checked',true);
   if(action==='reverse') boxes.each(function(){this.checked=!this.checked;});
   if(action==='first'){var n=parseInt($('[name=copies]').val(),10)||1;boxes.prop('checked',false).slice(0,n).prop('checked',true);}
   refresh();
 });
 function usedQty(){var total=0;$('.ph-sheet-item-card').each(function(){total+=parseInt($(this).find('[name$="[quantity]"]').val(),10)||0;});return total;}
 function refresh(){
   $('.ph-wizard-panel').attr('hidden',true).filter('[data-wizard-step="'+step+'"]').removeAttr('hidden');
   $('.ph-wizard-progress span').removeClass('is-active').filter('[data-progress="'+step+'"]').addClass('is-active');
   var slots=selectedSlots(); $('.ph-slot-status').text(slots.length+' label spot'+(slots.length===1?'':'s')+' selected');
   $('.ph-sheet-item-card').addClass('is-inactive').eq(itemIndex).removeClass('is-inactive');
   var review=[]; $('.ph-sheet-item-card').each(function(){var q=parseInt($(this).find('[name$="[quantity]"]').val(),10)||0;if(!q)return;var name=$(this).find('select option:selected').text();var custom=$(this).find('[name$="[title_override]"]').val();review.push((custom||name)+' × '+q);});
   $('.ph-review-list').html(review.length?review.map(function(x){return '<li>'+ $('<div>').text(x).html()+'</li>';}).join(''):'<li>No products added yet.</li>');
   $('.ph-review-count').text(usedQty()+' of '+slots.length+' selected spots assigned');
 }
 $(document).on('click','[data-wizard-next]',function(e){e.preventDefault();if(step===1 && !selectedSlots().length){alert('Select at least one available label spot.');return;} if(step===2 && !usedQty()){alert('Add at least one product label.');return;} step=Math.min(maxStep,step+1);refresh();});
 $(document).on('click','[data-wizard-back]',function(e){e.preventDefault();step=Math.max(1,step-1);refresh();});
 $(document).on('click','[data-add-next-product]',function(e){e.preventDefault();var slots=selectedSlots().length;if(usedQty()>=slots){alert('All selected label spots are assigned.');step=3;refresh();return;}var next=-1;$('.ph-sheet-item-card').each(function(i){if(i>itemIndex && next<0 && !(parseInt($(this).find('[name$="[quantity]"]').val(),10)||0))next=i;});if(next<0){step=3;}else{itemIndex=next;}refresh();});
 $(document).on('change input','.ph-sheet-slot input,.ph-sheet-item-card input,.ph-sheet-item-card select',refresh);
 refresh();
});
JS
        );
    }

    public static function product_row_action( $actions, $post ) {
        if ( 'product' !== $post->post_type || ! current_user_can( 'edit_product', $post->ID ) ) {
            return $actions;
        }

        $url = add_query_arg(
            array(
                'page'       => self::PAGE_SLUG,
                'product_id' => (int) $post->ID,
            ),
            admin_url( 'admin.php' )
        );
        $actions['persiano_label'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Generate label', 'persiano-hub' ) . '</a>';
        return $actions;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'persiano-hub' ) );
        }

        $product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $product    = $product_id ? wc_get_product( $product_id ) : null;
        $prefill    = self::get_prefill( $product_id );
        $selected_format = self::sanitize_choice( isset( $_GET['label_format'] ) ? wp_unslash( $_GET['label_format'] ) : ( $product_id ? $prefill['default_format'] : 'sheet_avery_5163' ), array( 'thermal_3x2', 'thermal_3x3', 'continuous_3', 'sheet_avery_5163' ) );
        ?>
        <div class="wrap ph-labels-wrap">
            <h1><?php esc_html_e( 'Food Labels', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'Generate ready-to-print labels for pantry items, prepared meals, desserts and thermal-printer stickers.', 'persiano-hub' ); ?></p>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ph-label-card">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                <?php if ( $product_id ) : ?><input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>">
                    <input type="hidden" name="label_format" value="<?php echo esc_attr( $selected_format ); ?>"><?php endif; ?>
                <h2><span class="ph-wizard-step">1</span><?php esc_html_e( 'Choose label format', 'persiano-hub' ); ?></h2>
                <div class="ph-label-grid">
                    <label class="ph-label-field"><span><?php esc_html_e( 'Format', 'persiano-hub' ); ?></span>
                        <select name="label_format" id="ph-label-format">
                            <option value="sheet_avery_5163" <?php selected( $selected_format, 'sheet_avery_5163' ); ?>><?php esc_html_e( 'Avery 5163 compatible — 4 × 2 in, 10-up sheet', 'persiano-hub' ); ?></option>
                            <option value="thermal_3x2" <?php selected( $selected_format, 'thermal_3x2' ); ?>><?php esc_html_e( 'Precut thermal 3 × 2 in', 'persiano-hub' ); ?></option>
                            <option value="thermal_3x3" <?php selected( $selected_format, 'thermal_3x3' ); ?>><?php esc_html_e( 'Precut thermal 3 × 3 in', 'persiano-hub' ); ?></option>
                            <option value="continuous_3" <?php selected( $selected_format, 'continuous_3' ); ?>><?php esc_html_e( 'Continuous roll — 3 in wide', 'persiano-hub' ); ?></option>
                        </select>
                    </label>
                </div>
            </form>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ph-label-card">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                <input type="hidden" name="label_format" value="<?php echo esc_attr( $selected_format ); ?>">
                <h2><span class="ph-wizard-step">2</span><?php esc_html_e( 'Choose a starting product', 'persiano-hub' ); ?></h2>
                <p class="ph-label-help"><?php esc_html_e( 'For Avery sheets this is the first item. You can add different products and custom variants in the next step.', 'persiano-hub' ); ?></p>
                <div class="ph-label-grid"><label class="ph-label-field"><span><?php esc_html_e( 'Product', 'persiano-hub' ); ?></span>
                    <select name="product_id" onchange="this.form.submit()"><option value="0"><?php esc_html_e( 'Select a product…', 'persiano-hub' ); ?></option>
                    <?php foreach ( self::get_product_options() as $id => $title ) : ?><option value="<?php echo esc_attr( $id ); ?>" <?php selected( $product_id, $id ); ?>><?php echo esc_html( $title ); ?></option><?php endforeach; ?>
                    </select></label></div>
            </form>

            <?php if ( $product ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank">
                    <?php wp_nonce_field( 'persiano_hub_print_labels_' . $product_id ); ?>
                    <input type="hidden" name="action" value="persiano_hub_print_labels">
                    <input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>">
                    <input type="hidden" name="label_format" value="<?php echo esc_attr( $selected_format ); ?>">

                    <div class="ph-label-card">
                        <div class="ph-label-product-heading">
                            <?php echo wp_kses_post( $product->get_image( 'thumbnail' ) ); ?>
                            <div>
                                <h2 style="margin:0"><?php echo esc_html( $product->get_name() ); ?></h2>
                                <div class="ph-label-muted"><?php echo esc_html( $product->get_sku() ? 'SKU: ' . $product->get_sku() : __( 'No SKU yet', 'persiano-hub' ) ); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="ph-label-card">
                        <h2><span class="ph-wizard-step">3</span><?php esc_html_e( 'Build labels', 'persiano-hub' ); ?></h2>
                        <div class="ph-label-grid ph-label-grid--3">
                            <div class="ph-label-field"><span><?php esc_html_e( 'Format', 'persiano-hub' ); ?></span><strong><?php echo esc_html( 'sheet_avery_5163' === $selected_format ? 'Avery 5163 — 10-up sheet' : $selected_format ); ?></strong></div>
                            <label class="ph-label-field ph-standard-copy-field">
                                <span><?php esc_html_e( 'Copies', 'persiano-hub' ); ?></span>
                                <input type="number" name="copies" min="1" max="50" value="1">
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Template', 'persiano-hub' ); ?></span>
                                <select name="label_template"><option value="compact"><?php esc_html_e( 'Compact', 'persiano-hub' ); ?></option><option value="detailed"><?php esc_html_e( 'Detailed', 'persiano-hub' ); ?></option></select>
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Orientation', 'persiano-hub' ); ?></span>
                                <select name="label_orientation"><option value="landscape"><?php esc_html_e( 'Landscape', 'persiano-hub' ); ?></option><option value="portrait"><?php esc_html_e( 'Portrait', 'persiano-hub' ); ?></option></select>
                            </label>
                            <label class="ph-label-field ph-continuous-height">
                                <span><?php esc_html_e( 'Continuous label height (inches)', 'persiano-hub' ); ?></span>
                                <input type="number" name="custom_height" min="1" max="12" step="0.25" value="3">
                                <small class="ph-label-help"><?php esc_html_e( 'Used only for continuous rolls. Width is fixed at 3 inches.', 'persiano-hub' ); ?></small>
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Batch / lot code', 'persiano-hub' ); ?></span>
                                <input type="text" name="batch_code" placeholder="<?php esc_attr_e( 'Optional', 'persiano-hub' ); ?>">
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Packed on', 'persiano-hub' ); ?></span>
                                <input type="date" name="packed_on" value="<?php echo esc_attr( gmdate( 'Y-m-d', current_time( 'timestamp' ) ) ); ?>">
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Shelf life (days)', 'persiano-hub' ); ?></span>
                                <input type="number" name="shelf_life_days" min="0" max="3650" value="<?php echo esc_attr( $prefill['shelf_life_days'] ); ?>">
                                <small class="ph-label-help"><?php esc_html_e( 'Automatically calculates the best-before date from the packed-on date.', 'persiano-hub' ); ?></small>
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Best before date', 'persiano-hub' ); ?></span>
                                <input type="date" name="best_before_date">
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Price override', 'persiano-hub' ); ?></span>
                                <input type="text" name="price_override" placeholder="<?php echo esc_attr( wp_strip_all_tags( wc_price( (float) $product->get_price() ) ) ); ?>">
                            </label>
                        </div>
                        <div class="ph-sheet-options ph-label-wizard">
                            <div class="ph-wizard-progress"><span data-progress="1" class="is-active">1. Available spots</span><span data-progress="2">2. Add products</span><span data-progress="3">3. Review</span></div>
                            <section class="ph-wizard-panel" data-wizard-step="1">
                            <h3><?php esc_html_e( 'Choose available label spots', 'persiano-hub' ); ?></h3>
                            <p class="ph-sheet-note"><?php esc_html_e( 'Select only the unused labels on the sheet. Codes run left and right by row: L1/R1 through L5/R5.', 'persiano-hub' ); ?></p>
                            <div class="ph-slot-tools" aria-label="<?php esc_attr_e( 'Label selection tools', 'persiano-hub' ); ?>">
                                <button type="button" class="button" data-slot-action="all"><?php esc_html_e( 'Select all', 'persiano-hub' ); ?></button>
                                <button type="button" class="button" data-slot-action="clear"><?php esc_html_e( 'Clear all', 'persiano-hub' ); ?></button>
                                <button type="button" class="button" data-slot-action="left"><?php esc_html_e( 'Left', 'persiano-hub' ); ?></button>
                                <button type="button" class="button" data-slot-action="right"><?php esc_html_e( 'Right', 'persiano-hub' ); ?></button>
                                <button type="button" class="button" data-slot-action="reverse"><?php esc_html_e( 'Reverse', 'persiano-hub' ); ?></button>
                                <button type="button" class="button" data-slot-action="first"><?php esc_html_e( 'Select first N', 'persiano-hub' ); ?></button>
                            </div>
                            <div class="ph-sheet-slots">
                                <?php foreach ( array( 'L1', 'R1', 'L2', 'R2', 'L3', 'R3', 'L4', 'R4', 'L5', 'R5' ) as $slot_code ) : ?>
                                    <label class="ph-sheet-slot"><input type="checkbox" name="sheet_slots[]" value="<?php echo esc_attr( $slot_code ); ?>" <?php checked( 'L1', $slot_code ); ?>> <strong><?php echo esc_html( $slot_code ); ?></strong><span><?php esc_html_e( 'Print this label here', 'persiano-hub' ); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                            <p class="ph-slot-status"></p><div class="ph-wizard-nav"><button type="button" class="button button-primary" data-wizard-next><?php esc_html_e( 'Continue to products', 'persiano-hub' ); ?></button></div></section>
                            <section class="ph-wizard-panel ph-mixed-sheet" data-wizard-step="2" hidden>
                                <h3><?php esc_html_e( 'Add and customize a product', 'persiano-hub' ); ?></h3>
                                <p class="ph-mixed-help"><?php esc_html_e( 'Each row is a label group. Add the same product twice when the portions differ, such as regular Olivieh and Vegetarian Olivieh. Dates and text can be changed independently for every row.', 'persiano-hub' ); ?></p>
                                <?php $product_options = self::get_product_options(); $today = gmdate( 'Y-m-d', current_time( 'timestamp' ) ); ?>
                                <?php for ( $mix_i = 0; $mix_i < 6; $mix_i++ ) : ?>
                                    <div class="ph-sheet-item-card">
                                        <div class="ph-sheet-item-grid">
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Product', 'persiano-hub' ); ?></span><select name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][product_id]"><option value="0"><?php esc_html_e( 'Choose product…', 'persiano-hub' ); ?></option><?php foreach ( $product_options as $mix_product_id => $mix_product_title ) : ?><option value="<?php echo esc_attr( $mix_product_id ); ?>" <?php selected( 0 === $mix_i ? $product_id : 0, $mix_product_id ); ?>><?php echo esc_html( $mix_product_title ); ?></option><?php endforeach; ?></select></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Qty', 'persiano-hub' ); ?></span><input type="number" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][quantity]" min="0" max="10" value="<?php echo 0 === $mix_i ? '1' : '0'; ?>"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Packed on', 'persiano-hub' ); ?></span><input data-packed type="date" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][packed_on]" value="<?php echo esc_attr( $today ); ?>"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Shelf life', 'persiano-hub' ); ?></span><input data-shelf type="number" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][shelf_life_days]" min="0" max="3650" value="<?php echo 0 === $mix_i ? esc_attr( $prefill['shelf_life_days'] ) : '0'; ?>"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Best before', 'persiano-hub' ); ?></span><input data-best type="date" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][best_before_date]"></label>
                                        </div>
                                        <div class="ph-sheet-item-overrides">
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Custom label title / variant', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][title_override]" placeholder="<?php esc_attr_e( 'Example: Vegetarian Olivieh Salad', 'persiano-hub' ); ?>"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Net quantity override', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][net_quantity_override]" placeholder="<?php esc_attr_e( 'Example: 300 g', 'persiano-hub' ); ?>"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Ingredients override', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][ingredients_override]" placeholder="<?php esc_attr_e( 'Leave blank to use product ingredients', 'persiano-hub' ); ?>"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Allergens / note override', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][allergens_override]" placeholder="<?php esc_attr_e( 'Example: Vegetarian · Contains dairy', 'persiano-hub' ); ?>"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Persian title override', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][title_fa_override]"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Short description override', 'persiano-hub' ); ?></span><textarea name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][subtitle_override]"></textarea></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Storage override', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][storage_override]"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Preparation / reheating override', 'persiano-hub' ); ?></span><textarea name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][preparation_override]"></textarea></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Custom note', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][notes_override]" placeholder="<?php esc_attr_e( 'Example: Vegetarian', 'persiano-hub' ); ?>"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Batch / lot code', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][batch_code_override]"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Price override', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][price_override]" placeholder="<?php echo esc_attr( wp_strip_all_tags( wc_price( 0 === $mix_i ? (float) $product->get_price() : 0 ) ) ); ?>"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'Barcode / SKU override', 'persiano-hub' ); ?></span><input type="text" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][sku_override]"></label>
                                            <label class="ph-label-field"><span><?php esc_html_e( 'QR destination override', 'persiano-hub' ); ?></span><input type="url" name="sheet_items[<?php echo esc_attr( $mix_i ); ?>][qr_override]"></label>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                                <div class="ph-wizard-nav"><button type="button" class="button" data-wizard-back><?php esc_html_e( 'Back', 'persiano-hub' ); ?></button><button type="button" class="button" data-add-next-product><?php esc_html_e( 'Store and add next product', 'persiano-hub' ); ?></button><button type="button" class="button button-primary" data-wizard-next><?php esc_html_e( 'Review sheet', 'persiano-hub' ); ?></button></div>
                            </section>
                            <section class="ph-wizard-panel" data-wizard-step="3" hidden><h3><?php esc_html_e( 'Review and generate layout', 'persiano-hub' ); ?></h3><p class="ph-review-count"></p><ul class="ph-review-list"></ul><div class="ph-wizard-nav"><button type="button" class="button" data-wizard-back><?php esc_html_e( 'Back', 'persiano-hub' ); ?></button><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Generate label layout', 'persiano-hub' ); ?></button></div></section>
                        </div>
                        <table class="ph-labels-table"><tr>
                            <td>
                                <label class="ph-label-checkbox"><input type="checkbox" name="show_price" value="1" checked> <span><?php esc_html_e( 'Show price on label', 'persiano-hub' ); ?></span></label>
                            </td>
                            <td>
                                <label class="ph-label-checkbox"><input type="checkbox" name="show_logo" value="1" checked> <span><?php echo esc_html( sprintf( __( 'Show %s logo', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ) ); ?></span></label>
                            </td>
                            <td>
                                <label class="ph-label-checkbox"><input type="checkbox" name="show_image" value="1"> <span><?php esc_html_e( 'Show product photo when space allows', 'persiano-hub' ); ?></span></label>
                            </td>
                            <td>
                                <label class="ph-label-checkbox"><input type="checkbox" name="show_barcode" value="1" <?php checked( $prefill['show_barcode'] ); ?>> <span><?php esc_html_e( 'Show Code 128 barcode from SKU', 'persiano-hub' ); ?></span></label>
                            </td>
                            <td>
                                <label class="ph-label-checkbox"><input type="checkbox" name="show_qr" value="1" <?php checked( $prefill['show_qr'] ); ?>> <span><?php esc_html_e( 'Show QR code', 'persiano-hub' ); ?></span></label>
                            </td><td><label class="ph-label-checkbox"><input type="checkbox" name="show_tear_guide" value="1" checked> <span><?php esc_html_e( 'Show tear/cut guide on continuous paper', 'persiano-hub' ); ?></span></label></td>
                            <td>
                                <label class="ph-label-checkbox"><input type="checkbox" name="save_defaults" value="1"> <span><?php esc_html_e( 'Save these details as this product’s default label content', 'persiano-hub' ); ?></span></label>
                            </td>
                        </tr></table>
                    </div>

                    <div class="ph-label-card">
                        <h2><?php esc_html_e( '3) Label content', 'persiano-hub' ); ?></h2>
                        <div class="ph-label-grid">
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'English title', 'persiano-hub' ); ?></span>
                                <input type="text" name="title_en" value="<?php echo esc_attr( $prefill['title_en'] ); ?>">
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Persian title', 'persiano-hub' ); ?></span>
                                <input type="text" name="title_fa" value="<?php echo esc_attr( $prefill['title_fa'] ); ?>">
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Subtitle / short descriptor', 'persiano-hub' ); ?></span>
                                <input type="text" name="subtitle" value="<?php echo esc_attr( $prefill['subtitle'] ); ?>" placeholder="<?php esc_attr_e( 'Ready to heat · Homemade · Small batch', 'persiano-hub' ); ?>">
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Net quantity / size', 'persiano-hub' ); ?></span>
                                <input type="text" name="net_quantity" value="<?php echo esc_attr( $prefill['net_quantity'] ); ?>">
                            </label>
                        </div>
                        <div class="ph-label-grid">
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Ingredients', 'persiano-hub' ); ?></span>
                                <textarea name="ingredients" rows="4" placeholder="<?php esc_attr_e( 'List ingredients in descending order by weight when possible.', 'persiano-hub' ); ?>"><?php echo esc_textarea( $prefill['ingredients'] ); ?></textarea>
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Preparation / reheating instructions', 'persiano-hub' ); ?></span>
                                <textarea name="preparation" rows="4" placeholder="<?php esc_attr_e( 'For example: Reheat gently on the stove until hot.', 'persiano-hub' ); ?>"><?php echo esc_textarea( $prefill['preparation'] ); ?></textarea>
                            </label>
                        </div>
                        <div class="ph-label-grid">
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Allergen statement', 'persiano-hub' ); ?></span>
                                <input type="text" name="allergens" value="<?php echo esc_attr( $prefill['allergens'] ); ?>" placeholder="<?php esc_attr_e( 'Contains eggs, dairy, walnuts…', 'persiano-hub' ); ?>">
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Storage instructions', 'persiano-hub' ); ?></span>
                                <input type="text" name="storage" value="<?php echo esc_attr( $prefill['storage'] ); ?>" placeholder="<?php esc_attr_e( 'Keep refrigerated. Freeze if not using within 3 days.', 'persiano-hub' ); ?>">
                            </label>
                        </div>
                        <div class="ph-label-grid">
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Best-before guidance (text)', 'persiano-hub' ); ?></span>
                                <input type="text" name="best_before_text" value="<?php echo esc_attr( $prefill['best_before_text'] ); ?>" placeholder="<?php esc_attr_e( 'Best within 3 days of packing', 'persiano-hub' ); ?>">
                            </label>
                            <label class="ph-label-field">
                                <span><?php esc_html_e( 'Extra note', 'persiano-hub' ); ?></span>
                                <input type="text" name="notes" value="<?php echo esc_attr( $prefill['notes'] ); ?>" placeholder="<?php esc_attr_e( 'Pickup only · Keep upright · Limited batch', 'persiano-hub' ); ?>">
                            </label>
                        </div>
                    </div>

                    <div class="ph-label-card">
                        <h2><span class="ph-wizard-step">4</span><?php esc_html_e( 'Preview and print', 'persiano-hub' ); ?></h2>
                        <p class="ph-label-help"><?php esc_html_e( 'A print-ready label will open in a new tab. Then use your browser’s Print command and choose your thermal printer or regular printer.', 'persiano-hub' ); ?></p><div class="ph-label-fit-note"><?php esc_html_e( 'Compact 4 × 2 labels prioritize product name, quantity, ingredients, allergens, dates, storage and codes. Long marketing descriptions may be shortened so required information remains visible.', 'persiano-hub' ); ?></div>
                        <div class="ph-label-actions">
                            <button class="button button-primary" type="submit"><?php esc_html_e( 'Open print view', 'persiano-hub' ); ?></button>
                        </div>
                    </div>
                </form>
            <?php else : ?>
                <div class="ph-label-card"><p><?php esc_html_e( 'Choose a product above to start designing a label.', 'persiano-hub' ); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function get_product_options() {
        $posts = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'orderby'        => 'title',
                'order'          => 'ASC',
                'posts_per_page' => 300,
                'fields'         => 'ids',
            )
        );

        $options = array();
        foreach ( $posts as $post_id ) {
            $product = wc_get_product( $post_id );
            if ( ! $product ) {
                continue;
            }
            $label = $product->get_name();
            if ( $product->get_sku() ) {
                $label .= ' (' . $product->get_sku() . ')';
            }
            $options[ $post_id ] = $label;
        }
        return $options;
    }

    private static function get_prefill( $product_id ) {
        $product  = $product_id ? wc_get_product( $product_id ) : null;
        $details  = $product_id ? Persiano_Hub_Product_Fields::get_product_details( $product_id ) : array();
        $subtitle = $product ? wp_strip_all_tags( $product->get_short_description() ) : '';
        if ( strlen( $subtitle ) > 120 ) {
            $subtitle = wp_html_excerpt( $subtitle, 120, '…' );
        }

        $legacy_title_fa = self::find_product_value( $product_id, $product, array( 'title fa', 'fa title', 'persian title', 'title_fa', 'نام فارسی', 'عنوان فارسی' ) );
        $ingredients = sanitize_textarea_field( get_post_meta( $product_id, self::META_INGREDIENTS, true ) );
        if ( ! $ingredients && $product ) {
            $ingredients = sanitize_textarea_field( self::find_product_value( $product_id, $product, array( 'ingredients', 'ingredient', 'مواد تشکیل دهنده', 'مواد اولیه' ) ) );
        }
        $allergens = sanitize_text_field( get_post_meta( $product_id, self::META_ALLERGENS, true ) );
        if ( ! $allergens && ! empty( $details['dietary'] ) ) {
            $auto_allergens = array();
            if ( in_array( 'contains_dairy', $details['dietary'], true ) ) { $auto_allergens[] = __( 'dairy', 'persiano-hub' ); }
            if ( in_array( 'contains_nuts', $details['dietary'], true ) ) { $auto_allergens[] = __( 'nuts', 'persiano-hub' ); }
            $allergens = implode( ', ', $auto_allergens );
        }

        return array(
            'title_en'         => $product ? $product->get_name() : '',
            'title_fa'         => sanitize_text_field( get_post_meta( $product_id, self::META_TITLE_FA, true ) ) ?: $legacy_title_fa,
            'subtitle'         => sanitize_text_field( get_post_meta( $product_id, self::META_SUBTITLE, true ) ) ?: $subtitle,
            'ingredients'      => $ingredients,
            'allergens'        => $allergens,
            'storage'          => sanitize_text_field( get_post_meta( $product_id, self::META_STORAGE, true ) ) ?: sanitize_text_field( self::find_product_value( $product_id, $product, array( 'storage', 'storage instructions', 'نگهداری' ) ) ),
            'preparation'      => sanitize_textarea_field( get_post_meta( $product_id, self::META_PREPARATION, true ) ) ?: sanitize_textarea_field( self::find_product_value( $product_id, $product, array( 'preparation', 'reheating', 'heating instructions', 'دستور مصرف', 'روش گرم کردن' ) ) ),
            'best_before_text' => sanitize_text_field( get_post_meta( $product_id, self::META_BEST_BEFORE, true ) ),
            'net_quantity'     => sanitize_text_field( get_post_meta( $product_id, self::META_NET_QUANTITY, true ) ) ?: ( isset( $details['size'] ) ? sanitize_text_field( $details['size'] ) : '' ),
            'notes'            => sanitize_text_field( get_post_meta( $product_id, self::META_NOTES, true ) ),
            'default_format'   => self::sanitize_choice( get_post_meta( $product_id, self::META_DEFAULT_FORMAT, true ) ?: 'thermal_3x2', array( 'thermal_3x2', 'thermal_3x3', 'continuous_3', 'sheet_avery_5163' ) ),
            'shelf_life_days'  => absint( get_post_meta( $product_id, self::META_SHELF_DAYS, true ) ),
            'show_barcode'    => 'yes' === get_post_meta( $product_id, self::META_SHOW_BARCODE, true ) || ( $product && $product->get_sku() && '' === get_post_meta( $product_id, self::META_SHOW_BARCODE, true ) ),
            'show_qr'         => 'yes' === get_post_meta( $product_id, self::META_SHOW_QR, true ),
            'qr_mode'         => self::sanitize_choice( get_post_meta( $product_id, self::META_QR_MODE, true ) ?: 'product', array( 'product', 'reorder', 'custom' ) ),
            'qr_custom'       => esc_url_raw( get_post_meta( $product_id, self::META_QR_CUSTOM, true ) ),
        );
    }


    private static function find_product_value( $product_id, $product, $labels ) {
        $normalize = static function( $value ) {
            $value = strtolower( wp_strip_all_tags( (string) $value ) );
            $value = str_replace( array( '_', '-', ':' ), ' ', $value );
            return trim( preg_replace( '/\s+/', ' ', $value ) );
        };
        $wanted = array_map( $normalize, $labels );
        if ( $product instanceof WC_Product ) {
            $attributes = $product->get_attributes();
            if ( ! is_array( $attributes ) && ! $attributes instanceof Traversable ) {
                $attributes = array();
            }
            foreach ( $attributes as $attribute ) {
                $name = $normalize( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ? $attribute->get_name() : '' );
                foreach ( $wanted as $candidate ) {
                    if ( $name === $candidate || false !== strpos( $name, $candidate ) ) {
                        if ( is_object( $attribute ) && method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() ) {
                            $terms = wc_get_product_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) );
                            return implode( ', ', $terms );
                        }
                        if ( is_object( $attribute ) && method_exists( $attribute, 'get_options' ) ) {
                            return implode( ', ', array_map( 'strval', $attribute->get_options() ) );
                        }
                    }
                }
            }
        }
        $all_meta = get_post_meta( $product_id );
        if ( ! is_array( $all_meta ) ) {
            $all_meta = array();
        }
        foreach ( $all_meta as $key => $values ) {
            $name = $normalize( $key );
            foreach ( $wanted as $candidate ) {
                if ( $name === $candidate || false !== strpos( $name, $candidate ) ) {
                    if ( ! is_array( $values ) ) {
                        $values = array( $values );
                    }
                    $value = maybe_unserialize( reset( $values ) );
                    if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) { return (string) $value; }
                }
            }
        }
        return '';
    }

    public static function handle_print() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'persiano-hub' ) );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        check_admin_referer( 'persiano_hub_print_labels_' . $product_id );

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_die( esc_html__( 'Product not found.', 'persiano-hub' ) );
        }

        $data = array(
            'format'           => self::sanitize_choice( isset( $_POST['label_format'] ) ? wp_unslash( $_POST['label_format'] ) : 'thermal_3x2', array( 'thermal_3x2', 'thermal_3x3', 'continuous_3', 'sheet_avery_5163' ) ),
            'template'         => self::sanitize_choice( wp_unslash( $_POST['label_template'] ?? 'compact' ), array( 'compact', 'detailed' ) ),
            'orientation'      => self::sanitize_choice( wp_unslash( $_POST['label_orientation'] ?? 'landscape' ), array( 'landscape', 'portrait' ) ),
            'custom_height'    => max( 1, min( 12, (float) wc_format_decimal( wp_unslash( $_POST['custom_height'] ?? 3 ), 2 ) ) ),
            'show_tear_guide'  => ! empty( $_POST['show_tear_guide'] ),
            'copies'           => max( 1, min( 50, absint( isset( $_POST['copies'] ) ? $_POST['copies'] : 1 ) ) ),
            'batch_code'       => sanitize_text_field( wp_unslash( $_POST['batch_code'] ?? '' ) ),
            'packed_on'        => sanitize_text_field( wp_unslash( $_POST['packed_on'] ?? '' ) ),
            'shelf_life_days' => absint( $_POST['shelf_life_days'] ?? 0 ),
            'best_before_date' => sanitize_text_field( wp_unslash( $_POST['best_before_date'] ?? '' ) ),
            'show_price'       => ! empty( $_POST['show_price'] ),
            'show_logo'        => ! empty( $_POST['show_logo'] ),
            'show_image'       => ! empty( $_POST['show_image'] ),
            'show_barcode'     => ! empty( $_POST['show_barcode'] ),
            'show_qr'          => ! empty( $_POST['show_qr'] ),
            'qr_mode'          => self::sanitize_choice( wp_unslash( $_POST['qr_mode'] ?? 'product' ), array( 'product', 'reorder', 'custom' ) ),
            'qr_custom'        => esc_url_raw( wp_unslash( $_POST['qr_custom'] ?? '' ) ),
            'save_defaults'    => ! empty( $_POST['save_defaults'] ),
            'title_en'         => sanitize_text_field( wp_unslash( $_POST['title_en'] ?? '' ) ),
            'title_fa'         => sanitize_text_field( wp_unslash( $_POST['title_fa'] ?? '' ) ),
            'subtitle'         => sanitize_text_field( wp_unslash( $_POST['subtitle'] ?? '' ) ),
            'net_quantity'     => sanitize_text_field( wp_unslash( $_POST['net_quantity'] ?? '' ) ),
            'ingredients'      => sanitize_textarea_field( wp_unslash( $_POST['ingredients'] ?? '' ) ),
            'preparation'      => sanitize_textarea_field( wp_unslash( $_POST['preparation'] ?? '' ) ),
            'allergens'        => sanitize_text_field( wp_unslash( $_POST['allergens'] ?? '' ) ),
            'storage'          => sanitize_text_field( wp_unslash( $_POST['storage'] ?? '' ) ),
            'best_before_text' => sanitize_text_field( wp_unslash( $_POST['best_before_text'] ?? '' ) ),
            'notes'            => sanitize_text_field( wp_unslash( $_POST['notes'] ?? '' ) ),
            'price_override'   => sanitize_text_field( wp_unslash( $_POST['price_override'] ?? '' ) ),
            'sheet_slots'      => self::sanitize_sheet_slots( isset( $_POST['sheet_slots'] ) ? (array) wp_unslash( $_POST['sheet_slots'] ) : array() ),
            'mixed_products'   => self::sanitize_mixed_products( isset( $_POST['mixed_products'] ) ? (array) wp_unslash( $_POST['mixed_products'] ) : array() ),
            'sheet_items'      => self::sanitize_sheet_items( isset( $_POST['sheet_items'] ) ? (array) wp_unslash( $_POST['sheet_items'] ) : array() ),
        );

        if ( ! $data['best_before_date'] && $data['packed_on'] && $data['shelf_life_days'] > 0 ) {
            $date = DateTimeImmutable::createFromFormat( 'Y-m-d', $data['packed_on'], wp_timezone() );
            if ( $date ) { $data['best_before_date'] = $date->modify( '+' . $data['shelf_life_days'] . ' days' )->format( 'Y-m-d' ); }
        }
        if ( $data['save_defaults'] ) {
            self::save_defaults( $product_id, $data );
        }

        self::render_print_view( $product, $data );
        exit;
    }

    private static function save_defaults( $product_id, $data ) {
        update_post_meta( $product_id, self::META_TITLE_FA, $data['title_fa'] );
        update_post_meta( $product_id, self::META_SUBTITLE, $data['subtitle'] );
        update_post_meta( $product_id, self::META_INGREDIENTS, $data['ingredients'] );
        update_post_meta( $product_id, self::META_ALLERGENS, $data['allergens'] );
        update_post_meta( $product_id, self::META_STORAGE, $data['storage'] );
        update_post_meta( $product_id, self::META_PREPARATION, $data['preparation'] );
        update_post_meta( $product_id, self::META_BEST_BEFORE, $data['best_before_text'] );
        update_post_meta( $product_id, self::META_NET_QUANTITY, $data['net_quantity'] );
        update_post_meta( $product_id, self::META_NOTES, $data['notes'] );
        update_post_meta( $product_id, self::META_DEFAULT_FORMAT, $data['format'] );
        update_post_meta( $product_id, self::META_SHELF_DAYS, $data['shelf_life_days'] );
        update_post_meta( $product_id, self::META_SHOW_BARCODE, ! empty( $data['show_barcode'] ) ? 'yes' : 'no' );
        update_post_meta( $product_id, self::META_SHOW_QR, ! empty( $data['show_qr'] ) ? 'yes' : 'no' );
        update_post_meta( $product_id, self::META_QR_MODE, sanitize_key( $data['qr_mode'] ?? 'product' ) );
        update_post_meta( $product_id, self::META_QR_CUSTOM, esc_url_raw( $data['qr_custom'] ?? '' ) );
    }

    private static function render_print_view( $product, $data ) {
        if ( 'sheet_avery_5163' === $data['format'] ) {
            self::render_avery_5163_sheet( $product, $data );
            return;
        }
        $site_name = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ?: 'Business' );
        $logo_url  = class_exists( 'Persiano_Hub_Email_Branding' ) ? Persiano_Hub_Email_Branding::get_logo_url() : '';
        $price     = $data['price_override'];
        $regular_price = '';
        $saving_price  = '';
        if ( '' === $price ) {
            $price = wp_strip_all_tags( wc_price( (float) $product->get_price() ) );
            if ( $product->is_on_sale() && (float) $product->get_regular_price() > (float) $product->get_price() ) {
                $regular_price = wp_strip_all_tags( wc_price( (float) $product->get_regular_price() ) );
                $saving_price  = wp_strip_all_tags( wc_price( (float) $product->get_regular_price() - (float) $product->get_price() ) );
            }
        }
        $product_image = $data['show_image'] ? wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) : '';
        $sku           = $product->get_sku();
        $qr_target     = $product->get_permalink();
        if ( 'reorder' === ( $data['qr_mode'] ?? '' ) ) { $qr_target = add_query_arg( 'add-to-cart', $product->get_id(), wc_get_cart_url() ); }
        if ( 'custom' === ( $data['qr_mode'] ?? '' ) && ! empty( $data['qr_custom'] ) ) { $qr_target = $data['qr_custom']; }
        $barcode_url   = $sku ? 'https://barcode.tec-it.com/barcode.ashx?data=' . rawurlencode( $sku ) . '&code=Code128&translate-esc=on' : '';
        $qr_url        = $qr_target ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' . rawurlencode( $qr_target ) : '';
        $contact_email = function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : sanitize_email( get_option( 'admin_email' ) );
        $website_url   = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::website_url() : home_url( '/' );
        $website_label = preg_replace( '#^www\.#', '', wp_parse_url( $website_url, PHP_URL_HOST ) ?: wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $wizard        = get_option( 'persiano_hub_setup_wizard', array() );
        $instagram_url = is_array( $wizard ) ? (string) ( $wizard['instagram_url'] ?? '' ) : '';
        $instagram_handle = $instagram_url ? basename( untrailingslashit( wp_parse_url( $instagram_url, PHP_URL_PATH ) ) ) : '';
        if ( $instagram_handle && '@' !== substr( $instagram_handle, 0, 1 ) ) { $instagram_handle = '@' . ltrim( $instagram_handle, '@' ); }
        $format_style  = self::format_style( $data );

        nocache_headers();
        ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $product->get_name() . ' — Labels' ); ?></title>
            <style>
                <?php echo $format_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                *{box-sizing:border-box}body{margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f3f1ed;color:#231f1a}.ph-toolbar{position:sticky;top:0;z-index:20;background:#231f1a;color:#fff;padding:12px 16px;display:flex;gap:10px;align-items:center;justify-content:space-between}.ph-toolbar button,.ph-toolbar a{background:#8e2435;color:#fff;border:0;border-radius:999px;padding:10px 16px;font-size:14px;text-decoration:none;cursor:pointer}.ph-toolbar small{opacity:.8}.ph-print-area{padding:16px}.ph-label-sheet{page-break-after:always;display:flex;align-items:center;justify-content:center}.ph-label{width:100%;height:100%;background:#fff;border:2px solid #8e2435;border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:10px;overflow:hidden}.ph-label--compact{gap:6px;padding:8px}.ph-label--detailed{gap:8px;padding:10px}.thermal-three-inch .ph-label{border-radius:10px;border-width:1px}.thermal-three-inch .ph-brand-logo{max-width:78px;max-height:30px}.thermal-three-inch .ph-code-row{flex:0 0 54px;min-height:54px;gap:8px}.thermal-three-inch .ph-barcode{max-width:68%;height:42px}.thermal-three-inch .ph-qr{width:46px;height:46px}.thermal-three-inch .ph-footer{font-size:9px}.ph-tear-guide{position:absolute;left:.06in;right:.06in;bottom:.025in;border-bottom:1px dashed #777}.ph-label{position:relative}.ph-brand{display:flex;align-items:center;justify-content:space-between;gap:10px}.ph-brand-logo{max-width:120px;max-height:48px;width:auto;height:auto}.ph-brand-name{font-family:Georgia,serif;font-weight:700;color:#8e2435;font-size:20px}.ph-photo{width:100%;height:130px;object-fit:cover;border-radius:12px;border:1px solid #ece7df}.ph-title{font-family:Georgia,serif;font-size:28px;line-height:1.12;font-weight:700;color:#231f1a;margin:0}.ph-title-fa{font-size:18px;direction:rtl;text-align:right;color:#5a4a3f}.ph-subtitle{font-size:13px;color:#6d6358}.ph-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px}.ph-meta strong{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#8e2435;margin-bottom:2px}.ph-meta span,.ph-copy{font-size:11.5px;line-height:1.34}.ph-copy{min-height:0;overflow:hidden}.ph-copy p{margin:.3em 0}.ph-copy strong{color:#231f1a}.ph-footer{flex:0 0 auto;margin-top:2px;display:flex;justify-content:space-between;gap:10px;align-items:flex-end}.ph-price{font-weight:700;font-size:22px;color:#8e2435;white-space:nowrap}.ph-price-old{text-decoration:line-through;color:#7a7168;font-size:12px;text-align:right}.ph-price-save{color:#5f7046;font-size:11px;font-weight:700;text-align:right}.ph-code-row{display:flex;gap:12px;align-items:flex-end;justify-content:space-between;margin-top:auto;flex:0 0 68px;min-height:68px;padding-top:6px;background:#fff;position:relative;z-index:2}.ph-barcode{max-width:72%;height:54px;object-fit:contain}.ph-qr{width:58px;height:58px}.ph-sku{font-size:11px;color:#7a7168;text-align:right}.ph-note{font-size:11px;color:#6d6358}.ph-divider{height:1px;background:#e7ded3;margin:2px 0}.thermal-small .ph-title{font-size:18px}.thermal-small .ph-title-fa{font-size:14px}.thermal-small .ph-brand-name{font-size:15px}.thermal-small .ph-copy,.thermal-small .ph-meta span{font-size:10px;line-height:1.35}.thermal-small .ph-price{font-size:16px}.thermal-small .ph-grid{gap:6px 10px}.thermal-small .ph-photo{height:74px}.thermal-square .ph-grid{grid-template-columns:1fr}.thermal-square .ph-footer{align-items:flex-start;flex-direction:column}.no-print{display:block}@media print{body{background:#fff}.no-print,.ph-toolbar{display:none!important}.ph-print-area{padding:0}.ph-label-sheet{margin:0;page-break-after:always;break-after:page}}@media screen and (max-width:782px){.ph-toolbar{position:static;flex-direction:column;align-items:flex-start}.ph-print-area{padding:10px}}
            </style>
        </head>
        <body>
            <div class="ph-toolbar no-print">
                <div>
                    <strong><?php esc_html_e( 'Print-ready labels', 'persiano-hub' ); ?></strong><br>
                    <small><?php esc_html_e( 'Use your browser’s Print command and choose the right paper size on your thermal or regular printer.', 'persiano-hub' ); ?></small>
                </div>
                <div>
                    <button type="button" onclick="window.print()"><?php esc_html_e( 'Print labels', 'persiano-hub' ); ?></button>
                    <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'product_id' => $product->get_id() ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Back to generator', 'persiano-hub' ); ?></a>
                </div>
            </div>
            <div class="ph-print-area">
                <?php for ( $i = 0; $i < $data['copies']; $i++ ) : ?>
                    <section class="ph-label-sheet <?php echo esc_attr( self::format_body_class( $data ) ); ?>">
                        <article class="ph-label <?php echo esc_attr( self::format_label_class( $data ) ); ?>">
                            <?php if ( $data['show_logo'] ) : ?>
                                <div class="ph-brand">
                                    <?php if ( $logo_url ) : ?>
                                        <img class="ph-brand-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>">
                                    <?php else : ?>
                                        <div class="ph-brand-name"><?php echo esc_html( $site_name ); ?></div>
                                    <?php endif; ?>
                                    <?php if ( $data['show_price'] && $price ) : ?><div><div class="ph-price"><?php echo esc_html( $price ); ?></div><?php if ( $regular_price ) : ?><div class="ph-price-old"><?php echo esc_html( $regular_price ); ?></div><div class="ph-price-save"><?php echo esc_html( sprintf( __( 'Save %s', 'persiano-hub' ), $saving_price ) ); ?></div><?php endif; ?></div><?php endif; ?>
                                </div>
                            <?php elseif ( $data['show_price'] && $price ) : ?>
                                <div class="ph-footer"><div></div><div class="ph-price"><?php echo esc_html( $price ); ?></div></div>
                            <?php endif; ?>

                            <?php if ( $product_image && 'detailed' === $data['template'] && ( 'thermal_3x3' === $data['format'] || 'continuous_3' === $data['format'] ) ) : ?>
                                <img class="ph-photo" src="<?php echo esc_url( $product_image ); ?>" alt="">
                            <?php endif; ?>

                            <div>
                                <h1 class="ph-title"><?php echo esc_html( $data['title_en'] ?: $product->get_name() ); ?></h1>
                                <?php if ( $data['title_fa'] ) : ?><div class="ph-title-fa"><?php echo esc_html( $data['title_fa'] ); ?></div><?php endif; ?>
                                <?php if ( $data['subtitle'] ) : ?><div class="ph-subtitle"><?php echo esc_html( $data['subtitle'] ); ?></div><?php endif; ?>
                            </div>

                            <div class="ph-grid">
                                <?php if ( $data['net_quantity'] ) : ?><div class="ph-meta"><strong><?php esc_html_e( 'Net', 'persiano-hub' ); ?></strong><span><?php echo esc_html( $data['net_quantity'] ); ?></span></div><?php endif; ?>
                                <?php if ( $data['packed_on'] ) : ?><div class="ph-meta"><strong><?php esc_html_e( 'Packed on', 'persiano-hub' ); ?></strong><span><?php echo esc_html( self::format_date( $data['packed_on'] ) ); ?></span></div><?php endif; ?>
                                <?php if ( $data['best_before_date'] ) : ?><div class="ph-meta"><strong><?php esc_html_e( 'Best before', 'persiano-hub' ); ?></strong><span><?php echo esc_html( self::format_date( $data['best_before_date'] ) ); ?></span></div><?php endif; ?>
                                <?php if ( $data['batch_code'] ) : ?><div class="ph-meta"><strong><?php esc_html_e( 'Batch', 'persiano-hub' ); ?></strong><span><?php echo esc_html( $data['batch_code'] ); ?></span></div><?php endif; ?>
                            </div>

                            <?php if ( $data['ingredients'] || $data['allergens'] || $data['storage'] || $data['preparation'] || $data['best_before_text'] || $data['notes'] ) : ?><div class="ph-divider"></div><?php endif; ?>

                            <div class="ph-copy">
                                <?php if ( $data['ingredients'] ) : ?><p><strong><?php esc_html_e( 'Ingredients:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $data['ingredients'] ); ?></p><?php endif; ?>
                                <?php if ( $data['allergens'] ) : ?><p><strong><?php esc_html_e( 'Allergens:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $data['allergens'] ); ?></p><?php endif; ?>
                                <?php if ( $data['storage'] ) : ?><p><strong><?php esc_html_e( 'Storage:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $data['storage'] ); ?></p><?php endif; ?>
                                <?php if ( $data['preparation'] ) : ?><p><strong><?php esc_html_e( 'How to enjoy:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $data['preparation'] ); ?></p><?php endif; ?>
                                <?php if ( $data['best_before_text'] ) : ?><p><strong><?php esc_html_e( 'Best before:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $data['best_before_text'] ); ?></p><?php endif; ?>
                                <?php if ( $data['notes'] ) : ?><p class="ph-note"><?php echo esc_html( $data['notes'] ); ?></p><?php endif; ?>
                            </div>

                            <?php if ( ( ! empty( $data['show_barcode'] ) && $barcode_url ) || ( ! empty( $data['show_qr'] ) && $qr_url ) ) : ?>
                                <div class="ph-code-row">
                                    <?php if ( ! empty( $data['show_barcode'] ) && $barcode_url ) : ?><div><img class="ph-barcode" src="<?php echo esc_url( $barcode_url ); ?>" alt="<?php echo esc_attr( $sku ); ?>"><div class="ph-sku"><?php echo esc_html( $sku ); ?></div></div><?php endif; ?>
                                    <?php if ( ! empty( $data['show_qr'] ) && $qr_url ) : ?><img class="ph-qr" src="<?php echo esc_url( $qr_url ); ?>" alt="QR code"><?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="ph-footer">
                                <div class="ph-note"><?php echo esc_html( $site_name ); ?><?php if ( $contact_email ) : ?> · <?php echo esc_html( $contact_email ); ?><?php endif; ?></div>
                                <?php if ( $sku ) : ?><div class="ph-sku"><?php echo esc_html( 'SKU ' . $sku ); ?></div><?php endif; ?>
                            </div><?php if ( 'continuous_3' === $data['format'] && $data['show_tear_guide'] ) : ?><div class="ph-tear-guide" aria-hidden="true"></div><?php endif; ?>
                        </article>
                    </section>
                <?php endfor; ?>
            </div>
            <script>window.addEventListener('load',function(){document.title=<?php echo wp_json_encode( $product->get_name() . ' Labels' ); ?>;});</script>
        </body>
        </html>
        <?php
    }

    private static function sanitize_sheet_slots( $slots ) {
        $allowed = array( 'L1', 'R1', 'L2', 'R2', 'L3', 'R3', 'L4', 'R4', 'L5', 'R5' );
        $clean   = array();
        foreach ( (array) $slots as $slot ) {
            $slot = strtoupper( sanitize_text_field( $slot ) );
            if ( in_array( $slot, $allowed, true ) ) {
                $clean[] = $slot;
            }
        }
        return array_values( array_unique( $clean ) );
    }

    private static function sanitize_mixed_products( $rows ) {
        $clean = array();
        foreach ( (array) $rows as $row ) {
            $product_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
            $quantity   = isset( $row['quantity'] ) ? max( 0, min( 10, absint( $row['quantity'] ) ) ) : 0;
            if ( $product_id && $quantity && wc_get_product( $product_id ) ) {
                $clean[] = array( 'product_id' => $product_id, 'quantity' => $quantity );
            }
        }
        return $clean;
    }

    private static function sanitize_sheet_items( $rows ) {
        $clean = array();
        foreach ( (array) $rows as $row ) {
            $product_id = absint( $row['product_id'] ?? 0 );
            $quantity = max( 0, min( 10, absint( $row['quantity'] ?? 0 ) ) );
            if ( ! $product_id || ! $quantity || ! wc_get_product( $product_id ) ) { continue; }
            $packed_on = sanitize_text_field( wp_unslash( $row['packed_on'] ?? '' ) );
            $shelf = absint( $row['shelf_life_days'] ?? 0 );
            $best = sanitize_text_field( wp_unslash( $row['best_before_date'] ?? '' ) );
            if ( ! $best && $packed_on && $shelf ) {
                $date = DateTimeImmutable::createFromFormat( 'Y-m-d', $packed_on, wp_timezone() );
                if ( $date ) { $best = $date->modify( '+' . $shelf . ' days' )->format( 'Y-m-d' ); }
            }
            $clean[] = array(
                'product_id' => $product_id, 'quantity' => $quantity, 'packed_on' => $packed_on,
                'shelf_life_days' => $shelf, 'best_before_date' => $best,
                'title_override' => sanitize_text_field( wp_unslash( $row['title_override'] ?? '' ) ),
                'net_quantity_override' => sanitize_text_field( wp_unslash( $row['net_quantity_override'] ?? '' ) ),
                'ingredients_override' => sanitize_textarea_field( wp_unslash( $row['ingredients_override'] ?? '' ) ),
                'allergens_override' => sanitize_text_field( wp_unslash( $row['allergens_override'] ?? '' ) ),
                'title_fa_override' => sanitize_text_field( wp_unslash( $row['title_fa_override'] ?? '' ) ),
                'subtitle_override' => sanitize_textarea_field( wp_unslash( $row['subtitle_override'] ?? '' ) ),
                'storage_override' => sanitize_text_field( wp_unslash( $row['storage_override'] ?? '' ) ),
                'preparation_override' => sanitize_textarea_field( wp_unslash( $row['preparation_override'] ?? '' ) ),
                'notes_override' => sanitize_text_field( wp_unslash( $row['notes_override'] ?? '' ) ),
                'batch_code_override' => sanitize_text_field( wp_unslash( $row['batch_code_override'] ?? '' ) ),
                'price_override' => sanitize_text_field( wp_unslash( $row['price_override'] ?? '' ) ),
                'sku_override' => sanitize_text_field( wp_unslash( $row['sku_override'] ?? '' ) ),
                'qr_override' => esc_url_raw( wp_unslash( $row['qr_override'] ?? '' ) ),
            );
        }
        return $clean;
    }

    private static function build_sheet_item_map( $slots, $items, $fallback_product_id, $data ) {
        $queue = array();
        $map   = array();
        foreach ( $items as $item ) {
            for ( $i = 0; $i < $item['quantity']; $i++ ) {
                $queue[] = $item;
            }
        }
        $fallback = array(
            'product_id'          => $fallback_product_id,
            'packed_on'           => $data['packed_on'],
            'best_before_date'     => $data['best_before_date'],
            'title_override'       => '',
            'net_quantity_override'=> '',
            'ingredients_override' => '',
            'allergens_override'   => '',
        );
        if ( empty( $queue ) ) {
            $queue[] = $fallback;
        }
        // When the user selected several sheet positions but configured only the
        // starting product, repeat that product through every selected position.
        $repeat = count( $queue ) === 1 ? $queue[0] : $fallback;
        foreach ( $slots as $index => $slot ) {
            $map[ $slot ] = $queue[ $index ] ?? $repeat;
        }
        return $map;
    }

    private static function build_mixed_slot_map( $slots, $rows, $fallback_product_id ) {
        $map   = array();
        $queue = array();
        foreach ( $rows as $row ) {
            for ( $i = 0; $i < $row['quantity']; $i++ ) {
                $queue[] = $row['product_id'];
            }
        }
        foreach ( $slots as $index => $slot ) {
            $map[ $slot ] = isset( $queue[ $index ] ) ? $queue[ $index ] : ( empty( $queue ) ? $fallback_product_id : 0 );
        }
        return $map;
    }

    private static function render_avery_5163_sheet( $product, $data ) {
        $slots = ! empty( $data['sheet_slots'] ) ? $data['sheet_slots'] : array( 'L1' );
        $all_slots = array( 'L1', 'R1', 'L2', 'R2', 'L3', 'R3', 'L4', 'R4', 'L5', 'R5' );
        $slot_map = self::build_sheet_item_map( $slots, $data['sheet_items'] ?? array(), $product->get_id(), $data );
        $site_name = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ?: 'Business' );
        $logo_url  = class_exists( 'Persiano_Hub_Email_Branding' ) ? Persiano_Hub_Email_Branding::get_logo_url() : '';
        $contact_email = function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : sanitize_email( get_option( 'admin_email' ) );
        $website_url   = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::website_url() : home_url( '/' );
        $website_label = preg_replace( '#^www\.#', '', wp_parse_url( $website_url, PHP_URL_HOST ) ?: wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        $wizard        = get_option( 'persiano_hub_setup_wizard', array() );
        $instagram_url = is_array( $wizard ) ? (string) ( $wizard['instagram_url'] ?? '' ) : '';
        $instagram_handle = $instagram_url ? basename( untrailingslashit( wp_parse_url( $instagram_url, PHP_URL_PATH ) ) ) : '';
        if ( $instagram_handle && '@' !== substr( $instagram_handle, 0, 1 ) ) { $instagram_handle = '@' . ltrim( $instagram_handle, '@' ); }
        nocache_headers();
        ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?php echo esc_html( $product->get_name() . ' — Avery 5163 Labels' ); ?></title>
        <style>
        @page{size:letter;margin:0}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#eee;font-family:Arial,Helvetica,sans-serif;color:#231f1a}.ph-toolbar{position:sticky;top:0;z-index:10;background:#231f1a;color:#fff;padding:10px 16px;display:flex;justify-content:space-between;gap:12px;align-items:center}.ph-toolbar button,.ph-toolbar a{display:inline-block;border:0;border-radius:6px;padding:9px 13px;background:#fff;color:#231f1a;text-decoration:none;font-weight:700;cursor:pointer}.ph-overflow-summary{background:#fff4d6;color:#6b4b00;padding:8px 16px;font-size:13px;display:none}.ph-overflow-summary.is-visible{display:block}.ph-sheet-page{width:8.5in;height:11in;margin:18px auto;background:#fff;padding:.5in .18in;display:grid;grid-template-columns:4in 4in;grid-template-rows:repeat(5,2in);column-gap:.14in;row-gap:0}.ph-sheet-cell{width:4in;height:2in;overflow:hidden;position:relative}.ph-sheet-cell.is-blank{background:transparent}.ph-food-label{width:100%;height:100%;padding:.09in .13in .08in;display:grid;grid-template-rows:auto auto auto minmax(0,1fr) auto auto;gap:2px;overflow:hidden;border:1px dashed transparent}.ph-brand{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:25px}.ph-brand img{max-height:30px;max-width:150px;object-fit:contain;object-position:left center}.ph-brand-name{font-size:12px;font-weight:800}.ph-price{font-size:15px;font-weight:800;white-space:nowrap}.ph-heading{min-width:0}.ph-title{font-size:16px;line-height:1.02;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ph-title-fa{font-size:11.5px;font-weight:700;direction:rtl;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ph-subtitle{font-size:8px;line-height:1.1;color:#5f574f;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.ph-meta{display:flex;gap:6px;flex-wrap:wrap;font-size:7.5px;line-height:1.05}.ph-copy{font-size:7.5px;line-height:1.08;overflow:hidden;min-height:0}.ph-copy p{margin:1px 0}.ph-copy .ph-required{font-weight:600}.ph-copy .ph-optional{font-size:7px}.ph-code-row{display:flex;align-items:end;justify-content:space-between;gap:6px;min-height:31px;max-height:34px}.ph-code-row .ph-barcode-wrap{min-width:0;flex:1}.ph-code-row .ph-barcode{display:block;max-width:215px;width:100%;height:25px;object-fit:fill;object-position:left bottom}.ph-code-row .ph-qr{display:block;width:30px;height:30px;object-fit:contain}.ph-code-row .ph-sku{font-size:6px;color:#6d6358;line-height:1}.ph-footer{display:flex;justify-content:space-between;gap:6px;font-size:6.4px;line-height:1;color:#6d6358;white-space:nowrap}.ph-footer .ph-contact{overflow:hidden;text-overflow:ellipsis}.ph-slot-code{display:none}.ph-overflow-flag{display:none}.no-print{display:block}@media print{html,body{background:#fff}.no-print{display:none!important}.ph-sheet-page{margin:0}.ph-food-label{border:0}}@media screen{.ph-food-label{border-color:#d8d0c7}.ph-slot-code{display:block;position:absolute;z-index:2;top:2px;left:2px;background:#231f1a;color:#fff;font-size:8px;padding:2px 4px}.ph-food-label.is-overflowing{outline:2px solid #d63638;outline-offset:-2px}.ph-food-label.is-overflowing:after{content:'Content shortened to fit';position:absolute;right:4px;bottom:3px;background:#d63638;color:#fff;font-size:7px;padding:2px 4px;border-radius:3px}}
        </style></head><body>
        <div class="ph-toolbar no-print"><div><strong><?php esc_html_e( 'Avery 5163 compatible sheet', 'persiano-hub' ); ?></strong><br><small><?php esc_html_e( 'Print on US Letter at 100% / Actual size with browser margins and headers disabled.', 'persiano-hub' ); ?></small></div><div><button onclick="window.print()"><?php esc_html_e( 'Print sheet', 'persiano-hub' ); ?></button> <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'product_id' => $product->get_id() ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Back', 'persiano-hub' ); ?></a></div></div><div id="ph-overflow-summary" class="ph-overflow-summary no-print"><?php esc_html_e( 'One or more labels contain more text than a 4 × 2 inch label can display. Batchly has prioritized required food-label information and shortened optional text.', 'persiano-hub' ); ?></div>
        <main class="ph-sheet-page">
        <?php foreach ( $all_slots as $slot ) : ?>
            <?php
            $slot_item       = $slot_map[ $slot ] ?? null;
            $slot_product_id = is_array( $slot_item ) ? absint( $slot_item['product_id'] ?? 0 ) : 0;
            $slot_product    = $slot_product_id ? wc_get_product( $slot_product_id ) : null;
            $slot_data       = $slot_product ? array_merge( $data, self::get_prefill( $slot_product_id ) ) : array();
            if ( $slot_product && $slot_product_id === $product->get_id() ) {
                $slot_data = array_merge( $slot_data, array_intersect_key( $data, array_flip( array( 'title_en', 'title_fa', 'subtitle', 'net_quantity', 'ingredients', 'allergens', 'storage', 'preparation', 'best_before_text', 'notes', 'packed_on', 'best_before_date' ) ) ) );
            }
            if ( $slot_product && is_array( $slot_item ) ) {
                if ( ! empty( $slot_item['title_override'] ) ) { $slot_data['title_en'] = $slot_item['title_override']; }
                if ( ! empty( $slot_item['net_quantity_override'] ) ) { $slot_data['net_quantity'] = $slot_item['net_quantity_override']; }
                if ( ! empty( $slot_item['ingredients_override'] ) ) { $slot_data['ingredients'] = $slot_item['ingredients_override']; }
                if ( ! empty( $slot_item['allergens_override'] ) ) { $slot_data['allergens'] = $slot_item['allergens_override']; }
                if ( ! empty( $slot_item['title_fa_override'] ) ) { $slot_data['title_fa'] = $slot_item['title_fa_override']; }
                if ( ! empty( $slot_item['subtitle_override'] ) ) { $slot_data['subtitle'] = $slot_item['subtitle_override']; }
                if ( ! empty( $slot_item['storage_override'] ) ) { $slot_data['storage'] = $slot_item['storage_override']; }
                if ( ! empty( $slot_item['preparation_override'] ) ) { $slot_data['preparation'] = $slot_item['preparation_override']; }
                if ( ! empty( $slot_item['notes_override'] ) ) { $slot_data['notes'] = $slot_item['notes_override']; }
                $slot_data['packed_on'] = $slot_item['packed_on'] ?? $data['packed_on'];
                $slot_data['best_before_date'] = $slot_item['best_before_date'] ?? $data['best_before_date'];
            }
            $slot_price = '';
            if ( $slot_product ) {
                $slot_price = ! empty( $slot_item['price_override'] ) ? $slot_item['price_override'] : ( ( $slot_product_id === $product->get_id() && '' !== $data['price_override'] ) ? $data['price_override'] : wp_strip_all_tags( wc_price( (float) $slot_product->get_price() ) ) );
            }
            ?>
            <section class="ph-sheet-cell <?php echo $slot_product ? '' : 'is-blank'; ?>">
            <?php if ( $slot_product ) : ?>
                <article class="ph-food-label"><span class="ph-slot-code"><?php echo esc_html( $slot ); ?></span>
                    <div class="ph-brand"><?php if ( $data['show_logo'] ) : ?><?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>"><?php else : ?><span class="ph-brand-name"><?php echo esc_html( $site_name ); ?></span><?php endif; ?><?php endif; ?><?php if ( $data['show_price'] && $slot_price ) : ?><span class="ph-price"><?php echo esc_html( $slot_price ); ?></span><?php endif; ?></div>
                    <div class="ph-heading"><h1 class="ph-title"><?php echo esc_html( $slot_data['title_en'] ?: $slot_product->get_name() ); ?></h1><?php if ( $slot_data['title_fa'] ) : ?><div class="ph-title-fa"><?php echo esc_html( $slot_data['title_fa'] ); ?></div><?php endif; ?><?php if ( $slot_data['subtitle'] ) : ?><div class="ph-subtitle"><?php echo esc_html( $slot_data['subtitle'] ); ?></div><?php endif; ?></div>
                    <div class="ph-meta"><?php if ( $slot_data['net_quantity'] ) : ?><span><strong><?php esc_html_e( 'Net:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $slot_data['net_quantity'] ); ?></span><?php endif; ?><?php if ( ! empty( $slot_data['packed_on'] ) ) : ?><span><strong><?php esc_html_e( 'Packed:', 'persiano-hub' ); ?></strong> <?php echo esc_html( self::format_date( $slot_data['packed_on'] ) ); ?></span><?php endif; ?><?php if ( ! empty( $slot_data['best_before_date'] ) ) : ?><span><strong><?php esc_html_e( 'Best before:', 'persiano-hub' ); ?></strong> <?php echo esc_html( self::format_date( $slot_data['best_before_date'] ) ); ?></span><?php endif; ?><?php $slot_batch = ! empty( $slot_item['batch_code_override'] ) ? $slot_item['batch_code_override'] : $data['batch_code']; if ( $slot_batch ) : ?><span><strong><?php esc_html_e( 'Batch:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $slot_batch ); ?></span><?php endif; ?></div>
                    <div class="ph-copy"><?php if ( $slot_data['ingredients'] ) : ?><p><strong><?php esc_html_e( 'Ingredients:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $slot_data['ingredients'] ); ?></p><?php endif; ?><?php if ( $slot_data['allergens'] ) : ?><p><strong><?php esc_html_e( 'Allergens:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $slot_data['allergens'] ); ?></p><?php endif; ?><?php if ( $slot_data['storage'] ) : ?><p><strong><?php esc_html_e( 'Storage:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $slot_data['storage'] ); ?></p><?php endif; ?><?php if ( ! empty( $slot_data['preparation'] ) ) : ?><p><strong><?php esc_html_e( 'Preparation:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $slot_data['preparation'] ); ?></p><?php endif; ?><?php if ( ! empty( $slot_data['best_before_text'] ) ) : ?><p class="ph-required"><strong><?php esc_html_e( 'Best-before guidance:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $slot_data['best_before_text'] ); ?></p><?php endif; ?><?php if ( ! empty( $slot_data['notes'] ) ) : ?><p class="ph-optional"><strong><?php echo esc_html( $slot_data['notes'] ); ?></strong></p><?php endif; ?></div>
                    <?php
                    $slot_sku = ! empty( $slot_item['sku_override'] ) ? $slot_item['sku_override'] : $slot_product->get_sku();
                    $slot_qr_target = ! empty( $slot_item['qr_override'] ) ? $slot_item['qr_override'] : $slot_product->get_permalink();
                    if ( 'reorder' === ( $data['qr_mode'] ?? '' ) ) { $slot_qr_target = add_query_arg( 'add-to-cart', $slot_product->get_id(), wc_get_cart_url() ); }
                    if ( 'custom' === ( $data['qr_mode'] ?? '' ) && ! empty( $data['qr_custom'] ) ) { $slot_qr_target = $data['qr_custom']; }
                    $slot_barcode_url = $slot_sku ? 'https://barcode.tec-it.com/barcode.ashx?data=' . rawurlencode( $slot_sku ) . '&code=Code128&translate-esc=on' : '';
                    $slot_qr_url = $slot_qr_target ? 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=0&data=' . rawurlencode( $slot_qr_target ) : '';
                    ?>
                    <?php if ( ( ! empty( $data['show_barcode'] ) && $slot_barcode_url ) || ( ! empty( $data['show_qr'] ) && $slot_qr_url ) ) : ?>
                        <div class="ph-code-row">
                            <?php if ( ! empty( $data['show_barcode'] ) && $slot_barcode_url ) : ?><div class="ph-barcode-wrap"><img class="ph-barcode" src="<?php echo esc_url( $slot_barcode_url ); ?>" alt="<?php echo esc_attr( $slot_sku ); ?>"><div class="ph-sku"><?php echo esc_html( 'SKU ' . $slot_sku ); ?></div></div><?php endif; ?>
                            <?php if ( ! empty( $data['show_qr'] ) && $slot_qr_url ) : ?><img class="ph-qr" src="<?php echo esc_url( $slot_qr_url ); ?>" alt="QR code"><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="ph-footer"><span class="ph-contact"><?php echo esc_html( $website_label ); ?><?php if ( $instagram_handle ) : ?> · <?php echo esc_html( $instagram_handle ); ?><?php endif; ?><?php if ( $contact_email ) : ?> · <?php echo esc_html( $contact_email ); ?><?php endif; ?></span><?php if ( $slot_sku && empty( $data['show_barcode'] ) ) : ?><span><?php echo esc_html( 'SKU ' . $slot_sku ); ?></span><?php endif; ?></div>
                </article>
            <?php endif; ?>
            </section>
        <?php endforeach; ?>
        </main><script>(function(){document.title=<?php echo wp_json_encode( $product->get_name() . ' Avery 5163 Labels' ); ?>;var any=false;document.querySelectorAll('.ph-food-label').forEach(function(label){var copy=label.querySelector('.ph-copy');if(copy&&copy.scrollHeight>copy.clientHeight+1){label.classList.add('is-overflowing');any=true;}});if(any){document.getElementById('ph-overflow-summary').classList.add('is-visible');}})();</script></body></html>
        <?php
    }

    private static function format_style( $data ) {
        $format = $data['format'];
        $orientation = $data['orientation'];
        $width = 3.0;
        $height = 2.0;
        if ( 'thermal_3x3' === $format ) { $height = 3.0; }
        if ( 'continuous_3' === $format ) { $height = max( 1.0, min( 12.0, (float) $data['custom_height'] ) ); }
        if ( 'portrait' === $orientation && 'thermal_3x2' === $format ) { $width = 2.0; $height = 3.0; }
        $margin = 0.06;
        return sprintf( '@page{size:%1$sin %2$sin;margin:%3$sin}.ph-label-sheet{width:%1$sin;height:%2$sin}.ph-label{width:calc(100%% - %4$sin);height:calc(100%% - %4$sin)}', $width, $height, $margin, $margin * 2 );
    }

    private static function format_body_class( $data ) {
        $classes = array( 'thermal-three-inch' );
        if ( 'thermal_3x2' === $data['format'] ) { $classes[] = 'thermal-small'; }
        if ( 'thermal_3x3' === $data['format'] ) { $classes[] = 'thermal-square'; }
        if ( 'continuous_3' === $data['format'] ) { $classes[] = 'thermal-continuous'; }
        if ( 'portrait' === $data['orientation'] ) { $classes[] = 'is-portrait'; }
        return implode( ' ', $classes );
    }

    private static function format_label_class( $data ) {
        return 'ph-label--' . sanitize_html_class( $data['template'] );
    }

    private static function sanitize_choice( $value, $allowed ) {
        $value = sanitize_key( $value );
        return in_array( $value, $allowed, true ) ? $value : reset( $allowed );
    }

    private static function format_date( $date ) {
        $timestamp = strtotime( $date );
        return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $date;
    }
}
