<?php
/**
 * Quick/manual order entry for phone, social, in-person and repeat customers.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Manual_Orders {
    const PAGE_SLUG = 'persiano-manual-order';

    private static $saving_order_tools = false;
    private static $sending_payment_reminder_order_id = 0;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 23 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_action( 'admin_post_persiano_hub_create_manual_order', array( __CLASS__, 'handle_create_order' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
        add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 20, 2 );
        add_action( 'woocommerce_order_action_persiano_send_payment_link', array( __CLASS__, 'send_payment_link_action' ) );
        add_action( 'woocommerce_order_action_persiano_send_payment_reminder', array( __CLASS__, 'send_payment_reminder_action' ) );
        add_filter( 'woocommerce_email_subject_customer_invoice', array( __CLASS__, 'payment_reminder_subject' ), 99, 3 );
        add_filter( 'woocommerce_email_heading_customer_invoice', array( __CLASS__, 'payment_reminder_heading' ), 99, 3 );
        add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'payment_reminder_intro' ), 4, 4 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_boxes' ) );
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_order_tools' ), 30, 2 );
        add_action( 'woocommerce_update_order', array( __CLASS__, 'save_order_tools' ), 30, 1 );
        add_action( 'woocommerce_order_item_shipping_after_calculate_taxes', array( __CLASS__, 'override_manual_shipping_taxes' ), 20, 2 );
        add_action( 'admin_post_persiano_hub_open_existing_order_pos', array( __CLASS__, 'handle_open_existing_order_pos' ) );
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'New Manual Order', 'persiano-hub' ),
            __( 'New Order', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function admin_assets( $hook ) {
        $created_order_id = isset( $_GET['persiano_manual_created'] ) ? absint( $_GET['persiano_manual_created'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $created_order_id || in_array( $hook, array( 'post.php', 'woocommerce_page_wc-orders' ), true ) ) {
            wp_enqueue_script( 'jquery' );
            $copy_script = <<<'JS'
jQuery(function($){
    $(document).on('click','.ph-copy-payment-link',function(){
        var button=this;
        var field=$(button).siblings('.ph-payment-link-field').get(0);
        if(!field){return;}
        var done=function(){
            var original=$(button).text();
            $(button).text($(button).data('copy-success')||'Copied!');
            window.setTimeout(function(){$(button).text(original);},1600);
        };
        if(navigator.clipboard&&window.isSecureContext){
            navigator.clipboard.writeText(field.value).then(done);
        }else{
            field.focus();field.select();
            try{document.execCommand('copy');done();}catch(e){}
        }
    });
});
JS;
            wp_add_inline_script( 'jquery', $copy_script );
        }
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( self::PAGE_SLUG !== $page ) {
            return;
        }
        wp_enqueue_style( 'woocommerce_admin_styles' );
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_register_style( 'persiano-hub-manual-order-admin', false, array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-hub-manual-order-admin' );
        wp_add_inline_style(
            'persiano-hub-manual-order-admin',
            '.ph-manual-order{max-width:1240px}.ph-manual-order *{box-sizing:border-box}.ph-manual-order-card{display:block!important;background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:22px 24px;margin:18px 0;clear:both}.ph-manual-order-card h2{margin:0 0 16px}.ph-manual-grid{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:16px!important;align-items:start}.ph-manual-grid--3{grid-template-columns:repeat(3,minmax(0,1fr))!important}.ph-manual-order label{display:flex!important;flex-direction:column!important;gap:6px!important;min-width:0;margin:0!important}.ph-manual-order label>span{font-weight:600}.ph-manual-order input[type=text],.ph-manual-order input[type=email],.ph-manual-order input[type=tel],.ph-manual-order input[type=number],.ph-manual-order input[type=datetime-local],.ph-manual-order textarea,.ph-manual-order select{display:block;width:100%!important;max-width:none!important;min-height:38px}.ph-manual-order textarea{min-height:90px;resize:vertical}.ph-manual-order small{display:block;color:#646970;line-height:1.45}.ph-customer-mode{display:flex!important;gap:18px;flex-wrap:wrap;margin:8px 0 18px}.ph-customer-mode label,.ph-manual-order .ph-inline-check{display:flex!important;flex-direction:row!important;align-items:flex-start!important;gap:8px!important}.ph-customer-mode input,.ph-inline-check input{margin-top:2px}.ph-customer-panel{display:none!important}.ph-customer-panel.is-active{display:block!important}.ph-consent-box{margin-top:18px;border:1px solid #dcdcde;border-radius:12px;padding:15px 18px}.ph-consent-box label{display:flex!important;flex-direction:row!important;align-items:flex-start!important;gap:10px!important;margin:10px 0!important;padding:12px;border:1px solid #e2e4e7;border-radius:8px;background:#fbfbfc}.ph-consent-box input{margin-top:3px}.ph-order-item-row{display:grid!important;grid-template-columns:minmax(260px,1fr) 110px 42px!important;gap:10px!important;align-items:end;margin:10px 0}.ph-order-item-row .select2-container{width:100%!important}.ph-remove-order-row{height:38px;align-self:end}.ph-manual-actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;position:sticky;bottom:0;background:#f0f0f1;padding:14px 0;z-index:5}@media(max-width:1000px){.ph-manual-grid--3{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:782px){.ph-manual-grid,.ph-manual-grid--3{grid-template-columns:1fr!important}.ph-order-item-row{grid-template-columns:1fr 90px 42px!important}.ph-manual-order-card{padding:16px}}'
        );
        $script = <<<'JS'
(function($){
    $(function(){
        function customerMode(){
            var mode=$('input[name="customer_mode"]:checked').val()||'existing';
            $('.ph-customer-panel').removeClass('is-active');
            $('.ph-customer-panel[data-mode="'+mode+'"]').addClass('is-active');
        }
        function fulfilmentMode(){
            var type=$('select[name="fulfilment_type"]').val()||'pickup';
            $('.ph-fulfilment-custom-label').toggle(type==='custom');
        }
        function initSearch(scope){
            var $scope=$(scope||document);
            $scope.find('.wc-product-search,.wc-customer-search').filter(':not(.enhanced)').trigger('wc-enhanced-select-init');
            $(document.body).trigger('wc-enhanced-select-init');
        }
        $(document).on('change','input[name="customer_mode"]',customerMode);
        $(document).on('change','select[name="fulfilment_type"]',fulfilmentMode);
        $(document).on('click','#ph-add-order-row',function(e){
            e.preventDefault();
            var $items=$('#ph-order-items');
            var template=$('#ph-order-row-template').html()||'';
            var idx=parseInt($items.attr('data-next'),10)||1;
            if(!template){return;}
            var $row=$(template.replace(/__INDEX__/g,idx));
            $items.append($row).attr('data-next',idx+1);
            window.setTimeout(function(){initSearch($row);},0);
        });
        $(document).on('click','.ph-remove-order-row',function(e){
            e.preventDefault();
            if($('.ph-order-item-row').length>1){
                $(this).closest('.ph-order-item-row').remove();
            }else{
                var $row=$(this).closest('.ph-order-item-row');
                $row.find('select').val(null).trigger('change');
                $row.find('input[type=number]').val(1);
            }
        });
        $(document).on('keydown','.ph-whole-quantity',function(e){if(['.','e','E','+','-'].indexOf(e.key)!==-1){e.preventDefault();}});
        $(document).on('input change blur','.ph-whole-quantity',function(){
            var raw=parseFloat(this.value||'1');
            if(!isFinite(raw)){raw=1;}
            var rounded=Math.max(1,Math.round(raw));
            if(String(this.value)!==String(rounded)){this.value=rounded;}
        });
        $('.ph-manual-order form').on('submit',function(){
            $('.ph-whole-quantity').each(function(){var n=Math.max(1,Math.round(parseFloat(this.value||'1')||1));this.value=String(n);});
        });
        customerMode();
        fulfilmentMode();
        initSearch(document);
    });
})(jQuery);
JS;
        wp_add_inline_script( 'wc-enhanced-select', $script );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to create orders.', 'persiano-hub' ), 403 );
        }
        $source_options = array(
            'phone'     => __( 'Phone', 'persiano-hub' ),
            'instagram' => __( 'Instagram', 'persiano-hub' ),
            'telegram'  => __( 'Telegram', 'persiano-hub' ),
            'whatsapp'  => __( 'WhatsApp', 'persiano-hub' ),
            'email'     => __( 'Email', 'persiano-hub' ),
            'in_person' => __( 'In person / walk-in', 'persiano-hub' ),
            'repeat'    => __( 'Repeat order', 'persiano-hub' ),
            'other'     => __( 'Other', 'persiano-hub' ),
        );
        $payment_options = array(
            'square'            => __( 'Square — already paid', 'persiano-hub' ),
            'etransfer'         => __( 'E-transfer', 'persiano-hub' ),
            'cash'              => __( 'Cash on pickup / delivery', 'persiano-hub' ),
            'invoice'           => __( 'Send for secure online payment', 'persiano-hub' ),
            'complimentary'     => __( 'Complimentary', 'persiano-hub' ),
            'other'             => __( 'Other / paid externally', 'persiano-hub' ),
        );
        ?>
        <div class="wrap ph-manual-order">
            <h1><?php esc_html_e( 'Create Manual Order', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'Use this for phone, Instagram, Telegram, in-person and repeat orders so every sale stays connected to products, production, customer history and reporting.', 'persiano-hub' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="persiano_hub_create_manual_order">
                <?php wp_nonce_field( 'persiano_hub_create_manual_order' ); ?>

                <section class="ph-manual-order-card">
                    <h2><?php esc_html_e( 'Customer', 'persiano-hub' ); ?></h2>
                    <div class="ph-customer-mode">
                        <label><input type="radio" name="customer_mode" value="existing" checked> <?php esc_html_e( 'Existing customer', 'persiano-hub' ); ?></label>
                        <label><input type="radio" name="customer_mode" value="new"> <?php esc_html_e( 'New customer / guest payment', 'persiano-hub' ); ?></label>
                        <label><input type="radio" name="customer_mode" value="guest"> <?php esc_html_e( 'Guest / walk-in', 'persiano-hub' ); ?></label>
                    </div>
                    <div class="ph-customer-panel is-active" data-mode="existing">
                        <label><span><?php esc_html_e( 'Search customer', 'persiano-hub' ); ?></span>
                            <select class="wc-customer-search" name="existing_customer_id" data-action="woocommerce_json_search_customers" data-placeholder="<?php esc_attr_e( 'Search by name, email or customer ID…', 'persiano-hub' ); ?>" data-allow_clear="true" style="width:100%"></select>
                        </label>
                    </div>
                    <div class="ph-customer-panel" data-mode="new">
                        <div class="ph-manual-grid">
                            <label><span><?php esc_html_e( 'First name', 'persiano-hub' ); ?></span><input type="text" name="new_first_name"></label>
                            <label><span><?php esc_html_e( 'Last name', 'persiano-hub' ); ?></span><input type="text" name="new_last_name"></label>
                            <label><span><?php esc_html_e( 'Email (for receipt or payment link)', 'persiano-hub' ); ?></span><input type="email" name="new_email"><small><?php esc_html_e( 'A website login is not required to receive or pay an order.', 'persiano-hub' ); ?></small></label>
                            <label><span><?php esc_html_e( 'Phone', 'persiano-hub' ); ?></span><input type="tel" name="new_phone"></label>
                            <label><span><?php esc_html_e( 'Preferred contact', 'persiano-hub' ); ?></span><select name="new_preferred_contact"><option value="email"><?php esc_html_e( 'Email', 'persiano-hub' ); ?></option><option value="phone"><?php esc_html_e( 'Phone', 'persiano-hub' ); ?></option><option value="text"><?php esc_html_e( 'Text message', 'persiano-hub' ); ?></option><option value="whatsapp"><?php esc_html_e( 'WhatsApp', 'persiano-hub' ); ?></option></select></label>
                            <label><span><?php esc_html_e( 'Customer tags', 'persiano-hub' ); ?></span><input type="text" name="new_customer_tags" placeholder="regular, catering"></label>
                        </div>
                        <label style="margin-top:16px"><span><?php esc_html_e( 'Private customer notes', 'persiano-hub' ); ?></span><textarea name="new_customer_notes" rows="3"></textarea></label>
                        <label class="ph-inline-check" style="margin-top:12px"><input type="checkbox" name="new_invite_account_after_payment" value="yes"> <span><strong><?php esc_html_e( 'Invite the customer to create an account after payment', 'persiano-hub' ); ?></strong><br><small><?php esc_html_e( 'The order remains a guest order unless the email already belongs to an existing account.', 'persiano-hub' ); ?></small></span></label>
                    </div>
                    <div class="ph-customer-panel" data-mode="guest">
                        <div class="ph-manual-grid">
                            <label><span><?php esc_html_e( 'Guest name', 'persiano-hub' ); ?></span><input type="text" name="guest_name"></label>
                            <label><span><?php esc_html_e( 'Email', 'persiano-hub' ); ?></span><input type="email" name="guest_email"></label>
                            <label><span><?php esc_html_e( 'Phone', 'persiano-hub' ); ?></span><input type="tel" name="guest_phone"></label>
                        </div>
                    </div>
                    <fieldset class="ph-consent-box" style="margin-top:18px;border:1px solid #dcdcde;border-radius:12px;padding:15px 18px">
                        <legend style="font-weight:700;padding:0 6px"><?php esc_html_e( 'Marketing consent confirmed by customer', 'persiano-hub' ); ?></legend>
                        <label style="display:flex;flex-direction:row;align-items:flex-start;margin:10px 0"><input type="checkbox" name="marketing_email_consent" value="yes"> <span><?php echo esc_html( class_exists( 'Persiano_Hub_Customer_Accounts' ) ? Persiano_Hub_Customer_Accounts::email_consent_text() : Persiano_Hub_Newsletter::consent_text() ); ?></span></label>
                        <label style="display:flex;flex-direction:row;align-items:flex-start;margin:10px 0"><input type="checkbox" name="marketing_sms_consent" value="yes"> <span><?php echo esc_html( class_exists( 'Persiano_Hub_Customer_Accounts' ) ? Persiano_Hub_Customer_Accounts::sms_consent_text() : __( 'Customer consents to promotional text messages.', 'persiano-hub' ) ); ?></span></label>
                        <small><?php esc_html_e( 'Leave unchecked unless the customer clearly agreed. Email and SMS consent are recorded separately.', 'persiano-hub' ); ?></small>
                    </fieldset>
                </section>

                <section class="ph-manual-order-card">
                    <h2><?php esc_html_e( 'Items', 'persiano-hub' ); ?></h2>
                    <div id="ph-order-items" data-next="1">
                        <?php self::render_item_row( 0 ); ?>
                    </div>
                    <p><button id="ph-add-order-row" type="button" class="button"><?php esc_html_e( 'Add another item', 'persiano-hub' ); ?></button></p>
                    <script type="text/html" id="ph-order-row-template"><?php self::render_item_row( '__INDEX__' ); ?></script>
                </section>

                <section class="ph-manual-order-card">
                    <h2><?php esc_html_e( 'Discount', 'persiano-hub' ); ?></h2>
                    <div class="ph-manual-grid ph-manual-grid--3">
                        <label><span><?php esc_html_e( 'Discount type', 'persiano-hub' ); ?></span><select name="discount_type"><option value="none"><?php esc_html_e( 'No discount', 'persiano-hub' ); ?></option><option value="fixed"><?php esc_html_e( 'Fixed amount', 'persiano-hub' ); ?></option><option value="percent"><?php esc_html_e( 'Percentage', 'persiano-hub' ); ?></option></select></label>
                        <label><span><?php esc_html_e( 'Discount amount', 'persiano-hub' ); ?></span><input type="number" min="0" step="0.01" name="discount_amount" value="0"></label>
                        <label><span><?php esc_html_e( 'Discount reason / label', 'persiano-hub' ); ?></span><input type="text" name="discount_label" placeholder="Returning customer"></label>
                    </div>
                    <p><small><?php esc_html_e( 'The discount appears separately in the order, payment page, email and receipt.', 'persiano-hub' ); ?></small></p>
                </section>

                <section class="ph-manual-order-card">
                    <h2><?php esc_html_e( 'Tip / gratuity', 'persiano-hub' ); ?></h2>
                    <div class="ph-manual-grid">
                        <label><span><?php esc_html_e( 'Tip already collected', 'persiano-hub' ); ?></span><input type="number" min="0" step="0.01" name="manual_tip" value="0"></label>
                        <label><span><?php esc_html_e( 'Tip note', 'persiano-hub' ); ?></span><input type="text" name="manual_tip_label" value="Tip"></label>
                    </div>
                    <p><small><?php esc_html_e( 'Leave this at zero when the customer will choose a tip on the secure payment page.', 'persiano-hub' ); ?></small></p>
                </section>

                <section class="ph-manual-order-card">
                    <h2><?php esc_html_e( 'Fulfilment & payment', 'persiano-hub' ); ?></h2>
                    <div class="ph-manual-grid ph-manual-grid--3">
                        <label><span><?php esc_html_e( 'Order source', 'persiano-hub' ); ?></span><select name="order_source"><?php foreach ( $source_options as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
                        <label><span><?php esc_html_e( 'Fulfilment', 'persiano-hub' ); ?></span><select name="fulfilment_type"><option value="pickup"><?php esc_html_e( 'Pickup', 'persiano-hub' ); ?></option><option value="delivery"><?php esc_html_e( 'Local delivery', 'persiano-hub' ); ?></option><option value="shipping"><?php esc_html_e( 'Shipping', 'persiano-hub' ); ?></option><option value="custom"><?php esc_html_e( 'Custom fulfilment', 'persiano-hub' ); ?></option><option value="none"><?php esc_html_e( 'No fulfilment / service', 'persiano-hub' ); ?></option></select></label>
                        <label><span><?php esc_html_e( 'Requested date and time', 'persiano-hub' ); ?></span><input type="datetime-local" name="fulfilment_datetime"></label>
                        <label class="ph-inline-check" style="margin-top:26px"><input type="checkbox" name="is_advance_order" value="yes"> <span><strong><?php esc_html_e( 'Advance order', 'persiano-hub' ); ?></strong><br><small><?php esc_html_e( 'Adds this manual order to the Advance Orders workspace and uses the requested date/time above.', 'persiano-hub' ); ?></small></span></label>
                        <label><span><?php esc_html_e( 'Payment handling', 'persiano-hub' ); ?></span><select name="payment_method"><?php foreach ( $payment_options as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, 'invoice' ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><small><?php esc_html_e( 'Choose Send for online payment when the customer should receive a secure link and pay by Square on the payment page.', 'persiano-hub' ); ?></small></label>
                        <label><span><?php esc_html_e( 'Fulfilment fee', 'persiano-hub' ); ?></span><input type="number" min="0" step="0.01" name="fulfilment_fee" value="0.00"></label>
                        <label class="ph-fulfilment-custom-label"><span><?php esc_html_e( 'Custom fulfilment label', 'persiano-hub' ); ?></span><input type="text" name="fulfilment_label" placeholder="Local delivery"></label>
                        <label class="ph-inline-check" style="margin-top:26px"><input type="checkbox" name="fulfilment_taxable" value="yes"> <span><?php esc_html_e( 'Apply shipping tax to this fee', 'persiano-hub' ); ?></span></label>
                        <label class="ph-inline-check" style="margin-top:26px"><input type="checkbox" name="payment_received" value="yes"> <span><?php esc_html_e( 'Payment already received', 'persiano-hub' ); ?></span><small><?php esc_html_e( 'When unchecked, the order stays Pending payment, stock is reserved, and a payment link is created.', 'persiano-hub' ); ?></small></label>
                    </div>
                    <div class="ph-manual-grid" style="margin-top:16px">
                        <label><span><?php esc_html_e( 'Address line 1', 'persiano-hub' ); ?></span><input type="text" name="address_1"></label>
                        <label><span><?php esc_html_e( 'Address line 2', 'persiano-hub' ); ?></span><input type="text" name="address_2"></label>
                        <label><span><?php esc_html_e( 'City', 'persiano-hub' ); ?></span><input type="text" name="city"></label>
                        <label><span><?php esc_html_e( 'Province', 'persiano-hub' ); ?></span><input type="text" name="state" value="BC"></label>
                        <label><span><?php esc_html_e( 'Postal code', 'persiano-hub' ); ?></span><input type="text" name="postcode"></label>
                        <label><span><?php esc_html_e( 'Country', 'persiano-hub' ); ?></span><input type="text" name="country" value="CA"></label>
                    </div>
                    <label style="margin-top:16px"><span><?php esc_html_e( 'Customer-visible order note', 'persiano-hub' ); ?></span><textarea name="customer_note" rows="3"></textarea></label>
                    <label style="margin-top:16px"><span><?php esc_html_e( 'Private admin note', 'persiano-hub' ); ?></span><textarea name="admin_note" rows="3"></textarea></label>
                    <div style="margin-top:18px;padding:14px 16px;border:1px solid #d8c8b8;border-radius:10px;background:#fffaf2"><label class="ph-inline-check"><input type="checkbox" name="send_confirmation" value="yes"> <span><strong><?php esc_html_e( 'Send order details and payment link now', 'persiano-hub' ); ?></strong><br><small><?php esc_html_e( 'Leave unchecked to create the order silently. You can send it later from Order actions.', 'persiano-hub' ); ?></small></span></label></div>
                </section>

                <div class="ph-manual-actions"><button class="button button-primary button-hero" type="submit"><?php esc_html_e( 'Create Order', 'persiano-hub' ); ?></button></div>
            </form>
        </div>
        <?php
    }

    private static function render_item_row( $index ) {
        ?>
        <div class="ph-order-item-row">
            <label><span><?php esc_html_e( 'Product', 'persiano-hub' ); ?></span><select class="wc-product-search" name="items[<?php echo esc_attr( $index ); ?>][product_id]" data-placeholder="<?php esc_attr_e( 'Search products…', 'persiano-hub' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true" style="width:100%"></select></label>
            <label><span><?php esc_html_e( 'Quantity', 'persiano-hub' ); ?></span><input type="number" min="1" step="1" inputmode="numeric" class="ph-whole-quantity" name="items[<?php echo esc_attr( $index ); ?>][quantity]" value="1"></label>
            <button type="button" class="button-link-delete ph-remove-order-row" aria-label="<?php esc_attr_e( 'Remove item', 'persiano-hub' ); ?>">×</button>
        </div>
        <?php
    }

    public static function handle_create_order() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to create orders.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_hub_create_manual_order' );

        try {
            $customer = self::resolve_customer();
            if ( is_wp_error( $customer ) ) {
                throw new Exception( $customer->get_error_message() );
            }
            $customer_id = isset( $customer['customer_id'] ) ? absint( $customer['customer_id'] ) : 0;
            $billing = isset( $customer['billing'] ) ? $customer['billing'] : array();
            if ( isset( $_POST['marketing_email_consent'] ) && empty( $billing['email'] ) ) {
                throw new Exception( __( 'Enter the customer email before recording email marketing consent.', 'persiano-hub' ) );
            }
            if ( isset( $_POST['marketing_sms_consent'] ) && empty( $billing['phone'] ) ) {
                throw new Exception( __( 'Enter the customer phone number before recording SMS marketing consent.', 'persiano-hub' ) );
            }

            $posted_address = array(
                'address_1' => isset( $_POST['address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['address_1'] ) ) : '',
                'address_2' => isset( $_POST['address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['address_2'] ) ) : '',
                'city'      => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
                'state'     => isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : 'BC',
                'postcode'  => isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '',
                'country'   => isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : 'CA',
            );
            $has_posted_address = (bool) array_filter(
                array(
                    $posted_address['address_1'],
                    $posted_address['address_2'],
                    $posted_address['city'],
                    $posted_address['postcode'],
                )
            );
            if ( $has_posted_address ) {
                foreach ( $posted_address as $key => $value ) {
                    if ( $value ) { $billing[ $key ] = $value; }
                }
                if ( $customer_id ) {
                    $customer_record = new WC_Customer( $customer_id );
                    $customer_record->set_billing_address_1( $posted_address['address_1'] );
                    $customer_record->set_billing_address_2( $posted_address['address_2'] );
                    $customer_record->set_billing_city( $posted_address['city'] );
                    $customer_record->set_billing_state( $posted_address['state'] );
                    $customer_record->set_billing_postcode( $posted_address['postcode'] );
                    $customer_record->set_billing_country( $posted_address['country'] );
                    $customer_record->set_shipping_address_1( $posted_address['address_1'] );
                    $customer_record->set_shipping_address_2( $posted_address['address_2'] );
                    $customer_record->set_shipping_city( $posted_address['city'] );
                    $customer_record->set_shipping_state( $posted_address['state'] );
                    $customer_record->set_shipping_postcode( $posted_address['postcode'] );
                    $customer_record->set_shipping_country( $posted_address['country'] );
                    $customer_record->save();
                }
            }

            // Square requires a valid ISO country code. Manual orders default to Canada/BC when customer data is incomplete.
            if ( empty( $billing['country'] ) ) { $billing['country'] = 'CA'; }
            if ( empty( $billing['state'] ) ) { $billing['state'] = 'BC'; }
            if ( $customer_id ) {
                $customer_record = new WC_Customer( $customer_id );
                if ( ! $customer_record->get_billing_country() ) { $customer_record->set_billing_country( 'CA' ); }
                if ( ! $customer_record->get_shipping_country() ) { $customer_record->set_shipping_country( 'CA' ); }
                if ( ! $customer_record->get_billing_state() ) { $customer_record->set_billing_state( 'BC' ); }
                if ( ! $customer_record->get_shipping_state() ) { $customer_record->set_shipping_state( 'BC' ); }
                $customer_record->save();
            }

            $order = wc_create_order( array( 'customer_id' => $customer_id, 'created_via' => 'persiano-manual' ) );
            if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
                throw new Exception( is_wp_error( $order ) ? $order->get_error_message() : __( 'WooCommerce could not create the order.', 'persiano-hub' ) );
            }
            if ( $billing ) {
                $order->set_address( $billing, 'billing' );
                $order->set_address( $billing, 'shipping' );
            }
            if ( ! empty( $customer['contact_meta'] ) && is_array( $customer['contact_meta'] ) ) {
                foreach ( $customer['contact_meta'] as $meta_key => $meta_value ) {
                    if ( '' !== (string) $meta_value ) {
                        $order->update_meta_data( '_persiano_manual_contact_' . sanitize_key( $meta_key ), $meta_value );
                    }
                }
            }
            $order->update_meta_data( '_persiano_manual_customer_mode', isset( $customer['mode'] ) ? sanitize_key( $customer['mode'] ) : 'guest' );
            if ( isset( $_POST['new_invite_account_after_payment'] ) ) {
                $order->update_meta_data( '_persiano_invite_account_after_payment', 'yes' );
            }

            $items = isset( $_POST['items'] ) ? (array) wp_unslash( $_POST['items'] ) : array();
            $added = 0;
            foreach ( $items as $item ) {
                $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
                $raw_quantity = isset( $item['quantity'] ) ? (float) wc_format_decimal( $item['quantity'] ) : 0;
                $quantity = max( 1, (int) round( $raw_quantity ) );
                $product = $product_id ? wc_get_product( $product_id ) : false;
                if ( ! $product || $quantity <= 0 ) { continue; }
                $order->add_product( $product, $quantity );
                $added++;
            }
            if ( ! $added ) {
                $order->delete( true );
                throw new Exception( __( 'Add at least one valid product.', 'persiano-hub' ) );
            }

            $discount_type = isset( $_POST['discount_type'] ) ? sanitize_key( wp_unslash( $_POST['discount_type'] ) ) : 'none';
            $discount_amount = isset( $_POST['discount_amount'] ) ? max( 0, (float) wc_format_decimal( wp_unslash( $_POST['discount_amount'] ) ) ) : 0;
            $discount_label = isset( $_POST['discount_label'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_label'] ) ) : '';
            $product_subtotal = 0.0;
            foreach ( $order->get_items( 'line_item' ) as $line_item ) { $product_subtotal += (float) $line_item->get_subtotal(); }
            if ( 'percent' === $discount_type ) { $discount_amount = min( 100, $discount_amount ); $discount_value = $product_subtotal * ( $discount_amount / 100 ); }
            elseif ( 'fixed' === $discount_type ) { $discount_value = min( $product_subtotal, $discount_amount ); }
            else { $discount_value = 0.0; }
            if ( $discount_value > 0 ) {
                $discount_fee = new WC_Order_Item_Fee();
                $discount_fee->set_name( $discount_label ? $discount_label : __( 'Manual discount', 'persiano-hub' ) );
                $discount_fee->set_amount( -$discount_value );
                $discount_fee->set_total( -$discount_value );
                $discount_fee->set_tax_status( 'none' );
                $discount_fee->update_meta_data( '_persiano_manual_discount', 'yes' );
                $discount_fee->update_meta_data( '_persiano_discount_type', $discount_type );
                $discount_fee->update_meta_data( '_persiano_discount_input', $discount_amount );
                $order->add_item( $discount_fee );
            }

            $manual_tip = isset( $_POST['manual_tip'] ) ? max( 0, (float) wc_format_decimal( wp_unslash( $_POST['manual_tip'] ) ) ) : 0;
            $manual_tip_percent = isset( $_POST['manual_tip_percent'] ) ? max( 0, min( 100, (float) wc_format_decimal( wp_unslash( $_POST['manual_tip_percent'] ) ) ) ) : 0;
            if ( $manual_tip_percent > 0 ) {
                $item_subtotal = 0.0;
                foreach ( $order->get_items( 'line_item' ) as $line_item ) { $item_subtotal += (float) $line_item->get_total(); }
                $manual_tip = round( $item_subtotal * $manual_tip_percent / 100, wc_get_price_decimals() );
            }
            if ( $manual_tip > 0 ) {
                $tip_item = new WC_Order_Item_Fee();
                $tip_item->set_name( sanitize_text_field( wp_unslash( $_POST['manual_tip_label'] ?? 'Tip' ) ) ?: __( 'Tip', 'persiano-hub' ) );
                $tip_item->set_amount( $manual_tip );
                $tip_item->set_total( $manual_tip );
                $tip_item->set_tax_status( 'none' );
                $tip_item->update_meta_data( '_persiano_tip', 'yes' );
                $order->add_item( $tip_item );
            }

            $fulfilment_type = isset( $_POST['fulfilment_type'] ) ? sanitize_key( wp_unslash( $_POST['fulfilment_type'] ) ) : 'pickup';
            if ( ! in_array( $fulfilment_type, array( 'pickup', 'delivery', 'shipping', 'custom', 'none' ), true ) ) { $fulfilment_type = 'pickup'; }
            $fulfilment_fee = isset( $_POST['fulfilment_fee'] ) ? max( 0, (float) wc_format_decimal( wp_unslash( $_POST['fulfilment_fee'] ) ) ) : 0;
            $fulfilment_label = isset( $_POST['fulfilment_label'] ) ? sanitize_text_field( wp_unslash( $_POST['fulfilment_label'] ) ) : '';
            $fulfilment_taxable = isset( $_POST['fulfilment_taxable'] );
            if ( 'none' !== $fulfilment_type ) {
                $shipping = new WC_Order_Item_Shipping();
                $labels = array(
                    'pickup'   => sprintf( __( 'Pickup from %s', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) ),
                    'delivery' => __( 'Persiano local delivery', 'persiano-hub' ),
                    'shipping' => __( 'Persiano shipping', 'persiano-hub' ),
                    'custom'   => $fulfilment_label ? $fulfilment_label : __( 'Custom fulfilment', 'persiano-hub' ),
                );
                $shipping->set_method_title( $labels[ $fulfilment_type ] );
                $shipping->set_method_id( 'persiano_manual_' . $fulfilment_type );
                $shipping->set_total( $fulfilment_fee );
                $shipping->update_meta_data( '_persiano_manual_taxable', $fulfilment_taxable ? 'yes' : 'no' );
                $order->add_item( $shipping );
            }

            $requested = isset( $_POST['fulfilment_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['fulfilment_datetime'] ) ) : '';
            $snapshot = array(
                'type'     => $fulfilment_type,
                'label'    => $fulfilment_label,
                'fee'      => $fulfilment_fee,
                'taxable'  => $fulfilment_taxable ? 'yes' : 'no',
                'lines'    => array(),
            );
            if ( $requested ) { $snapshot['lines'][] = array( __( 'Requested date/time', 'persiano-hub' ), $requested ); }
            if ( in_array( $fulfilment_type, array( 'delivery', 'shipping' ), true ) && ! empty( $billing['address_1'] ) ) {
                $snapshot['lines'][] = array( __( 'Destination', 'persiano-hub' ), trim( $billing['address_1'] . ' ' . $billing['address_2'] . ', ' . $billing['city'] . ' ' . $billing['postcode'] ) );
            }
            $order->update_meta_data( '_persiano_fulfilment_snapshot', $snapshot );
            $order->update_meta_data( '_persiano_manual_fulfilment_datetime', $requested );

            if ( isset( $_POST['is_advance_order'] ) ) {
                $order->update_meta_data( '_persiano_advance_order_request', 'yes' );
                $order->update_meta_data( '_persiano_advance_order_confirmed', 'yes' );
                if ( class_exists( 'Persiano_Hub_Advance_Order_Center' ) ) {
                    $order->update_meta_data( Persiano_Hub_Advance_Order_Center::META_STATUS, 'confirmed_payment_pending' );
                }
                foreach ( $order->get_items( 'line_item' ) as $advance_item ) {
                    $advance_item->update_meta_data( '_persiano_advance_order', 'yes' );
                    if ( $requested ) { $advance_item->update_meta_data( '_persiano_requested_date', $requested ); }
                    $advance_item->save();
                }
                $order->add_order_note( __( 'Created as an advance order from the manual-order form.', 'persiano-hub' ) );
            }

            $source = isset( $_POST['order_source'] ) ? sanitize_key( wp_unslash( $_POST['order_source'] ) ) : 'other';
            $order->update_meta_data( '_persiano_manual_order_source', $source );
            $order->update_meta_data( '_persiano_marketing_source', $source );
            $order->update_meta_data( '_persiano_marketing_medium', 'manual' );

            $payment = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : 'other';
            $payment_titles = array(
                'square' => __( 'Square — paid externally', 'persiano-hub' ), 'etransfer' => __( 'E-transfer', 'persiano-hub' ), 'cash' => __( 'Cash on pickup / delivery', 'persiano-hub' ),
                'invoice' => __( 'Secure online payment', 'persiano-hub' ), 'complimentary' => __( 'Complimentary', 'persiano-hub' ), 'other' => __( 'Other / paid externally', 'persiano-hub' ),
            );
            if ( ! isset( $payment_titles[ $payment ] ) ) { $payment = 'other'; }
            $gateway_ids = array( 'square' => 'square_credit_card', 'etransfer' => 'bacs', 'cash' => 'cod', 'invoice' => 'persiano_invoice', 'complimentary' => 'persiano_complimentary', 'other' => 'persiano_other' );
            $order->set_payment_method( $gateway_ids[ $payment ] );
            $order->set_payment_method_title( $payment_titles[ $payment ] );

            $customer_note = isset( $_POST['customer_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_note'] ) ) : '';
            $admin_note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';
            if ( $customer_note ) { $order->set_customer_note( $customer_note ); }
            $order->calculate_totals( true );
            $order->save();
            if ( class_exists( 'Persiano_Hub_Customer_Accounts' ) ) {
                if ( isset( $_POST['marketing_email_consent'] ) && $order->get_billing_email() ) {
                    Persiano_Hub_Customer_Accounts::set_marketing_consent( 'email', $order->get_billing_email(), true, 'manual_order_admin', $order->get_customer_id(), $order->get_id() );
                }
                if ( isset( $_POST['marketing_sms_consent'] ) && $order->get_billing_phone() ) {
                    Persiano_Hub_Customer_Accounts::set_marketing_consent( 'sms', $order->get_billing_phone(), true, 'manual_order_admin', $order->get_customer_id(), $order->get_id() );
                }
            }
            if ( $admin_note ) { $order->add_order_note( $admin_note, false, true ); }
            $order->add_order_note( sprintf( __( 'Manual Persiano order created from %s.', 'persiano-hub' ), $source ), false, true );

            $paid = isset( $_POST['payment_received'] ) || 'complimentary' === $payment;
            if ( $paid ) {
                if ( 'complimentary' === $payment ) {
                    $order->update_status( 'completed', __( 'Complimentary manual order.', 'persiano-hub' ), true );
                } else {
                    $order->payment_complete();
                }
            } else {
                self::place_unpaid_order_pending( $order );
            }

            if ( isset( $_POST['send_confirmation'] ) && $order->get_billing_email() && ! $paid ) {
                self::send_order_email( $order );
            }
            if ( class_exists( 'Persiano_Hub_Notifications' ) ) {
                Persiano_Hub_Notifications::notify_manual_order_created( $order );
            }

            wp_safe_redirect( add_query_arg( array( 'persiano_manual_created' => $order->get_id(), 'persiano_manual_payment' => $paid ? 0 : 1 ), $order->get_edit_order_url() ) );
            exit;
        } catch ( Exception $e ) {
            wp_safe_redirect( add_query_arg( 'persiano_manual_error', rawurlencode( $e->getMessage() ), admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
            exit;
        }
    }

    private static function resolve_customer() {
        $mode = isset( $_POST['customer_mode'] ) ? sanitize_key( wp_unslash( $_POST['customer_mode'] ) ) : 'existing';
        if ( 'existing' === $mode ) {
            $customer_id = isset( $_POST['existing_customer_id'] ) ? absint( $_POST['existing_customer_id'] ) : 0;
            if ( ! $customer_id ) { return new WP_Error( 'missing_customer', __( 'Choose an existing customer or use New customer / Guest.', 'persiano-hub' ) ); }
            $customer = new WC_Customer( $customer_id );
            if ( ! $customer->get_id() ) { return new WP_Error( 'invalid_customer', __( 'The selected customer could not be loaded.', 'persiano-hub' ) ); }
            return array( 'customer_id' => $customer_id, 'billing' => $customer->get_billing(), 'mode' => 'existing' );
        }

        if ( 'new' === $mode ) {
            $email = isset( $_POST['new_email'] ) ? sanitize_email( wp_unslash( $_POST['new_email'] ) ) : '';
            $first = isset( $_POST['new_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_first_name'] ) ) : '';
            $last = isset( $_POST['new_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['new_last_name'] ) ) : '';
            $phone = isset( $_POST['new_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['new_phone'] ) ) : '';
            $preferred_contact = isset( $_POST['new_preferred_contact'] ) ? sanitize_key( wp_unslash( $_POST['new_preferred_contact'] ) ) : 'email';
            $tags = isset( $_POST['new_customer_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['new_customer_tags'] ) ) : '';
            $notes = isset( $_POST['new_customer_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['new_customer_notes'] ) ) : '';
            $create_account = isset( $_POST['new_create_account'] ); // Legacy compatibility; new flow normally invites after payment.

            if ( $email && ! is_email( $email ) ) {
                return new WP_Error( 'invalid_email', __( 'Enter a valid email address or leave it blank.', 'persiano-hub' ) );
            }
            if ( $create_account && ! is_email( $email ) ) {
                return new WP_Error( 'account_email_required', __( 'A valid email is required only when creating a website login account.', 'persiano-hub' ) );
            }

            $billing = array(
                'first_name' => $first,
                'last_name'  => $last,
                'email'      => $email,
                'phone'      => $phone,
                'country'    => 'CA',
                'state'      => 'BC',
            );
            $customer_id = $email ? absint( email_exists( $email ) ) : 0;

            if ( $customer_id ) {
                $customer = new WC_Customer( $customer_id );
                if ( $first && ! $customer->get_billing_first_name() ) { $customer->set_billing_first_name( $first ); }
                if ( $last && ! $customer->get_billing_last_name() ) { $customer->set_billing_last_name( $last ); }
                if ( $phone && ! $customer->get_billing_phone() ) { $customer->set_billing_phone( $phone ); }
                $customer->save();
                $billing = array_merge( $billing, array_filter( $customer->get_billing() ) );
            } elseif ( $create_account ) {
                $existing = email_exists( $email );
                if ( $existing ) {
                    $customer_id = absint( $existing );
                } else {
                    $customer_id = wc_create_new_customer( $email, '', '', array( 'first_name' => $first, 'last_name' => $last ) );
                    if ( is_wp_error( $customer_id ) ) { return $customer_id; }
                }
                $customer = new WC_Customer( $customer_id );
                if ( $first ) { $customer->set_first_name( $first ); $customer->set_billing_first_name( $first ); }
                if ( $last ) { $customer->set_last_name( $last ); $customer->set_billing_last_name( $last ); }
                $customer->set_billing_email( $email );
                if ( $phone ) { $customer->set_billing_phone( $phone ); }
                $customer->save();
                update_user_meta( $customer_id, '_persiano_preferred_contact', $preferred_contact );
                update_user_meta( $customer_id, '_persiano_customer_tags', $tags );
                update_user_meta( $customer_id, '_persiano_customer_notes', $notes );
                $billing = $customer->get_billing();
            }

            return array(
                'customer_id' => $customer_id,
                'billing' => $billing,
                'mode' => $customer_id ? 'existing_email' : ( $create_account ? 'new_account' : 'new_guest' ),
                'contact_meta' => array(
                    'preferred_contact' => $preferred_contact,
                    'tags'              => $tags,
                    'notes'             => $notes,
                ),
            );
        }

        $guest_name = isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '';
        $parts = preg_split( '/\s+/', trim( $guest_name ), 2 );
        return array(
            'customer_id' => 0,
            'mode' => 'guest',
            'billing' => array(
                'first_name' => isset( $parts[0] ) ? $parts[0] : '',
                'last_name'  => isset( $parts[1] ) ? $parts[1] : '',
                'email'      => isset( $_POST['guest_email'] ) ? sanitize_email( wp_unslash( $_POST['guest_email'] ) ) : '',
                'phone'      => isset( $_POST['guest_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_phone'] ) ) : '',
                'country'    => 'CA',
                'state'      => 'BC',
            ),
        );
    }

    /**
     * Keep an unpaid manual order in Pending payment so WooCommerce's secure
     * customer payment page is available, while reserving stock immediately.
     * WooCommerce records the stock-reduced flag, preventing a second stock
     * reduction when payment later completes.
     *
     * @param WC_Order $order Order object.
     */
    private static function place_unpaid_order_pending( $order ) {
        if ( ! $order->has_status( 'pending' ) ) {
            $order->update_status( 'pending', __( 'Manual order awaiting payment. A secure payment link is available.', 'persiano-hub' ), true );
        } else {
            $order->add_order_note( __( 'Manual order awaiting payment. A secure payment link is available and inventory is reserved.', 'persiano-hub' ), false, true );
        }

        if ( function_exists( 'wc_reduce_stock_levels' ) ) {
            wc_reduce_stock_levels( $order->get_id() );
        }
    }

    private static function send_order_email( $order ) {
        $emails = WC()->mailer()->get_emails();
        if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
            $emails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
        }
    }

    public static function admin_notice() {
        $error = isset( $_GET['persiano_manual_error'] ) ? sanitize_text_field( wp_unslash( $_GET['persiano_manual_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $error ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Manual order was not created:', 'persiano-hub' ) . '</strong> ' . esc_html( $error ) . '</p></div>';
        }
        $created = isset( $_GET['persiano_manual_created'] ) ? absint( $_GET['persiano_manual_created'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $created ) {
            return;
        }

        $order = wc_get_order( $created );
        echo '<div class="notice notice-success is-dismissible ph-manual-order-created">';
        echo '<p><strong>' . esc_html( sprintf( __( 'Persiano manual order #%d was created.', 'persiano-hub' ), $created ) ) . '</strong></p>';

        if ( $order instanceof WC_Order && $order->needs_payment() ) {
            $payment_url = class_exists( 'Persiano_Hub_Customer_Accounts' ) ? Persiano_Hub_Customer_Accounts::create_payment_access_url( $order ) : $order->get_checkout_payment_url();
            echo '<p>' . esc_html( $order->get_customer_id() ? __( 'This secure 24-hour link signs the assigned customer in and opens the payment page.', 'persiano-hub' ) : __( 'The customer can pay securely without creating or signing into an account.', 'persiano-hub' ) ) . '</p>';
            echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 14px;">';
            echo '<input type="text" class="regular-text ph-payment-link-field" readonly value="' . esc_attr( $payment_url ) . '" style="min-width:min(680px,100%);">';
            echo '<button type="button" class="button button-primary ph-copy-payment-link" data-copy-success="' . esc_attr__( 'Copied!', 'persiano-hub' ) . '">' . esc_html__( 'Copy payment link', 'persiano-hub' ) . '</button>';
            echo '<a class="button" href="' . esc_url( $payment_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open payment page', 'persiano-hub' ) . '</a>';
            echo '</div>';
        }
        echo '</div>';
    }


    public static function order_actions( $actions, $order = null ) {
        if ( ! $order instanceof WC_Order && isset( $GLOBALS['theorder'] ) && $GLOBALS['theorder'] instanceof WC_Order ) {
            $order = $GLOBALS['theorder'];
        }
        if ( $order instanceof WC_Order && $order->needs_payment() ) {
            $actions['persiano_send_payment_link'] = __( 'Email secure payment link', 'persiano-hub' );
            $actions['persiano_send_payment_reminder'] = __( 'Send friendly payment reminder', 'persiano-hub' );
        }
        return $actions;
    }

    public static function send_payment_link_action( $order ) {
        if ( ! $order instanceof WC_Order || ! $order->needs_payment() || ! $order->get_billing_email() ) {
            return;
        }
        self::send_order_email( $order );
        $order->add_order_note( __( 'Secure payment link emailed to the customer from Order actions.', 'persiano-hub' ), false, true );
    }

    /**
     * Send a polite payment reminder using WooCommerce's secure customer-invoice email.
     * The normal secure payment button/link and global BCC settings remain active.
     *
     * @param WC_Order $order Order being reminded.
     */
    public static function send_payment_reminder_action( $order ) {
        if ( ! $order instanceof WC_Order || ! $order->needs_payment() || ! is_email( $order->get_billing_email() ) ) {
            return;
        }

        self::$sending_payment_reminder_order_id = $order->get_id();
        self::send_order_email( $order );
        self::$sending_payment_reminder_order_id = 0;

        $count = absint( $order->get_meta( '_persiano_payment_reminder_count', true ) ) + 1;
        $order->update_meta_data( '_persiano_payment_reminder_count', $count );
        $order->update_meta_data( '_persiano_last_payment_reminder_sent', current_time( 'mysql' ) );
        $order->save();
        $order->add_order_note( sprintf( __( 'Friendly payment reminder #%1$d emailed to %2$s.', 'persiano-hub' ), $count, $order->get_billing_email() ), false, true );
    }

    private static function is_payment_reminder_email( $order ) {
        return $order instanceof WC_Order && self::$sending_payment_reminder_order_id && (int) $order->get_id() === (int) self::$sending_payment_reminder_order_id;
    }

    public static function payment_reminder_subject( $subject, $order, $email = null ) {
        if ( ! self::is_payment_reminder_email( $order ) ) {
            return $subject;
        }
        return sprintf( __( 'Friendly reminder: Invoice #%s is awaiting payment', 'persiano-hub' ), $order->get_order_number() );
    }

    public static function payment_reminder_heading( $heading, $order, $email = null ) {
        if ( ! self::is_payment_reminder_email( $order ) ) {
            return $heading;
        }
        return sprintf( __( 'Your %s invoice is ready', 'persiano-hub' ), function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' ) );
    }

    public static function payment_reminder_intro( $order, $sent_to_admin, $plain_text, $email ) {
        if ( $sent_to_admin || ! self::is_payment_reminder_email( $order ) ) {
            return;
        }

        $first_name = trim( (string) $order->get_billing_first_name() );
        $greeting = $first_name ? sprintf( __( 'Hi %s,', 'persiano-hub' ), $first_name ) : __( 'Hello,', 'persiano-hub' );
        $amount = wp_strip_all_tags( $order->get_formatted_order_total() );

        if ( $plain_text ) {
            echo "\n" . $greeting . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo sprintf( __( 'I hope you are doing well. This is a friendly reminder that Invoice #%1$s for %2$s is still awaiting payment.', 'persiano-hub' ), $order->get_order_number(), $amount ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo __( 'You may have received the invoice previously, but I wanted to resend it in case the earlier email was missed or filtered into another folder.', 'persiano-hub' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo __( 'Please disregard this message if payment has already been arranged.', 'persiano-hub' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        echo '<div style="margin:0 0 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:#3b302a;">';
        echo '<p style="margin:0 0 14px;">' . esc_html( $greeting ) . '</p>';
        echo '<p style="margin:0 0 14px;">' . esc_html( sprintf( __( 'I hope you are doing well. This is a friendly reminder that Invoice #%1$s for %2$s is still awaiting payment.', 'persiano-hub' ), $order->get_order_number(), $amount ) ) . '</p>';
        echo '<p style="margin:0 0 14px;">' . esc_html__( 'You may have received the invoice previously, but I wanted to resend it in case the earlier email was missed or filtered into another folder.', 'persiano-hub' ) . '</p>';
        echo '<p style="margin:0;">' . esc_html__( 'Please disregard this message if payment has already been arranged.', 'persiano-hub' ) . '</p>';
        echo '</div>';
    }

    public static function add_order_meta_boxes() {
        $screens = array( 'shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
            add_meta_box( 'persiano-order-tools', __( 'Persiano payment & fulfilment', 'persiano-hub' ), array( __CLASS__, 'render_order_tools_meta_box' ), $screen, 'side', 'high' );
        }
    }

    private static function order_from_screen_object( $object ) {
        if ( $object instanceof WC_Order ) {
            return $object;
        }
        if ( $object instanceof WP_Post ) {
            return wc_get_order( $object->ID );
        }
        $order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return $order_id ? wc_get_order( $order_id ) : false;
    }

    public static function render_order_tools_meta_box( $object ) {
        $order = self::order_from_screen_object( $object );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        wp_nonce_field( 'persiano_save_order_tools_' . $order->get_id(), 'persiano_order_tools_nonce' );
        $snapshot = $order->get_meta( '_persiano_fulfilment_snapshot', true );
        $snapshot = is_array( $snapshot ) ? $snapshot : array();
        $type = isset( $snapshot['type'] ) ? sanitize_key( $snapshot['type'] ) : 'pickup';
        $label = isset( $snapshot['label'] ) ? (string) $snapshot['label'] : '';
        $fee = isset( $snapshot['fee'] ) ? (float) $snapshot['fee'] : 0;
        $taxable = isset( $snapshot['taxable'] ) && 'yes' === $snapshot['taxable'];
        foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
            if ( 0 === strpos( (string) $shipping_item->get_method_id(), 'persiano_manual_' ) ) {
                $type = str_replace( 'persiano_manual_', '', (string) $shipping_item->get_method_id() );
                $label = $shipping_item->get_method_title();
                $fee = (float) $shipping_item->get_total();
                $stored_taxable = $shipping_item->get_meta( '_persiano_manual_taxable', true );
                $taxable = '' !== $stored_taxable ? 'yes' === $stored_taxable : ( method_exists( $shipping_item, 'get_tax_status' ) && 'taxable' === $shipping_item->get_tax_status() );
                break;
            }
        }
        if ( $order->needs_payment() ) {
            $payment_url = class_exists( 'Persiano_Hub_Customer_Accounts' ) ? Persiano_Hub_Customer_Accounts::create_payment_access_url( $order ) : $order->get_checkout_payment_url();
            echo '<p><strong>' . esc_html__( 'Payment link', 'persiano-hub' ) . '</strong></p>';
            echo '<input type="text" class="widefat ph-payment-link-field" readonly value="' . esc_attr( $payment_url ) . '">';
            echo '<p style="display:flex;gap:6px;flex-wrap:wrap"><button type="button" class="button ph-copy-payment-link" data-copy-success="' . esc_attr__( 'Copied!', 'persiano-hub' ) . '">' . esc_html__( 'Copy link', 'persiano-hub' ) . '</button><a class="button" target="_blank" rel="noopener" href="' . esc_url( $payment_url ) . '">' . esc_html__( 'Open', 'persiano-hub' ) . '</a></p>';
            echo '<p class="description">' . esc_html( $order->get_customer_id() ? __( 'For account customers, this secure account-access and payment link is valid for 24 hours.', 'persiano-hub' ) : __( 'Guest customers verify the billing email when WooCommerce requests it.', 'persiano-hub' ) ) . '</p><hr>';
        }
        ?>
        <p><label for="persiano_admin_fulfilment_type"><strong><?php esc_html_e( 'Fulfilment method', 'persiano-hub' ); ?></strong></label><select class="widefat" id="persiano_admin_fulfilment_type" name="persiano_admin_fulfilment_type"><option value="pickup" <?php selected( $type, 'pickup' ); ?>><?php esc_html_e( 'Pickup', 'persiano-hub' ); ?></option><option value="delivery" <?php selected( $type, 'delivery' ); ?>><?php esc_html_e( 'Local delivery', 'persiano-hub' ); ?></option><option value="shipping" <?php selected( $type, 'shipping' ); ?>><?php esc_html_e( 'Shipping', 'persiano-hub' ); ?></option><option value="custom" <?php selected( $type, 'custom' ); ?>><?php esc_html_e( 'Custom', 'persiano-hub' ); ?></option><option value="none" <?php selected( $type, 'none' ); ?>><?php esc_html_e( 'None / service only', 'persiano-hub' ); ?></option></select></p>
        <p><label for="persiano_admin_fulfilment_label"><strong><?php esc_html_e( 'Customer-facing label', 'persiano-hub' ); ?></strong></label><input class="widefat" type="text" id="persiano_admin_fulfilment_label" name="persiano_admin_fulfilment_label" value="<?php echo esc_attr( $label ); ?>" placeholder="Local delivery"></p>
        <p><label for="persiano_admin_fulfilment_fee"><strong><?php esc_html_e( 'Fulfilment fee', 'persiano-hub' ); ?></strong></label><input class="widefat" type="number" min="0" step="0.01" id="persiano_admin_fulfilment_fee" name="persiano_admin_fulfilment_fee" value="<?php echo esc_attr( wc_format_localized_price( $fee ) ); ?>"></p>
        <p><label><input type="checkbox" name="persiano_admin_fulfilment_taxable" value="yes" <?php checked( $taxable ); ?>> <?php esc_html_e( 'Taxable shipping fee', 'persiano-hub' ); ?></label></p>
        <?php $manual_discount = 0.0; $manual_discount_label = ''; foreach ( $order->get_items( 'fee' ) as $fee_item ) { if ( 'yes' === $fee_item->get_meta( '_persiano_manual_discount', true ) ) { $manual_discount = abs( (float) $fee_item->get_total() ); $manual_discount_label = $fee_item->get_name(); break; } } ?>
        <hr><p><strong><?php esc_html_e( 'Manual discount', 'persiano-hub' ); ?></strong></p>
        <p><label for="persiano_admin_discount_label"><?php esc_html_e( 'Discount label', 'persiano-hub' ); ?></label><input class="widefat" type="text" id="persiano_admin_discount_label" name="persiano_admin_discount_label" value="<?php echo esc_attr( $manual_discount_label ); ?>"></p>
        <p><label for="persiano_admin_discount_amount"><?php esc_html_e( 'Fixed discount amount', 'persiano-hub' ); ?></label><input class="widefat" type="number" min="0" step="0.01" id="persiano_admin_discount_amount" name="persiano_admin_discount_amount" value="<?php echo esc_attr( wc_format_localized_price( $manual_discount ) ); ?>"></p>
        <p class="description"><?php esc_html_e( 'Save or update the order to recalculate totals after changing fulfilment.', 'persiano-hub' ); ?></p>
        <?php if ( $order->needs_payment() && (float) $order->get_total() > 0 && ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) : ?>
            <hr>
            <p><strong><?php esc_html_e( 'Collect payment in Batchly', 'persiano-hub' ); ?></strong></p>
            <p class="description"><?php esc_html_e( 'Open this existing order in the tested POS payment screen. The same order is used; no duplicate order is created.', 'persiano-hub' ); ?></p>
            <p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_open_existing_order_pos&order_id=' . $order->get_id() ), 'persiano_hub_open_existing_order_pos_' . $order->get_id() ) ); ?>"><?php esc_html_e( 'Take payment in Batchly', 'persiano-hub' ); ?></a></p>
        <?php endif; ?>
        <?php
    }

    /**
     * WooCommerce 9.7+ determines shipping taxability from a registered shipping
     * method, while manual order lines do not have a real shipping instance.
     * Preserve the per-order Persiano choice by clearing calculated taxes when
     * the manual line is explicitly marked non-taxable.
     *
     * @param WC_Order_Item_Shipping $item Shipping line.
     * @param array                  $calculate_tax_for Tax location.
     */
    public static function override_manual_shipping_taxes( $item, $calculate_tax_for = array() ) {
        if ( ! $item instanceof WC_Order_Item_Shipping || 0 !== strpos( (string) $item->get_method_id(), 'persiano_manual_' ) ) {
            return;
        }
        if ( 'no' === $item->get_meta( '_persiano_manual_taxable', true ) ) {
            $item->set_taxes( array( 'total' => array() ) );
        }
    }

    public static function save_order_tools( $order_id, $post = null ) {
        if ( self::$saving_order_tools || empty( $_POST['persiano_order_tools_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        $nonce = sanitize_text_field( wp_unslash( $_POST['persiano_order_tools_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! wp_verify_nonce( $nonce, 'persiano_save_order_tools_' . absint( $order_id ) ) || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        self::$saving_order_tools = true;
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            self::$saving_order_tools = false;
            return;
        }
        $type = isset( $_POST['persiano_admin_fulfilment_type'] ) ? sanitize_key( wp_unslash( $_POST['persiano_admin_fulfilment_type'] ) ) : 'pickup';
        if ( ! in_array( $type, array( 'pickup', 'delivery', 'shipping', 'custom', 'none' ), true ) ) {
            $type = 'pickup';
        }
        $label = isset( $_POST['persiano_admin_fulfilment_label'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_admin_fulfilment_label'] ) ) : '';
        $fee = isset( $_POST['persiano_admin_fulfilment_fee'] ) ? max( 0, (float) wc_format_decimal( wp_unslash( $_POST['persiano_admin_fulfilment_fee'] ) ) ) : 0;
        $taxable = isset( $_POST['persiano_admin_fulfilment_taxable'] );
        $managed_item = false;
        foreach ( $order->get_items( 'shipping' ) as $item_id => $shipping_item ) {
            if ( 0 === strpos( (string) $shipping_item->get_method_id(), 'persiano_manual_' ) ) {
                $managed_item = $shipping_item;
                if ( 'none' === $type ) {
                    $order->remove_item( $item_id );
                    $managed_item = false;
                }
                break;
            }
        }
        if ( 'none' !== $type ) {
            if ( ! $managed_item ) {
                $managed_item = new WC_Order_Item_Shipping();
                $order->add_item( $managed_item );
            }
            $brand = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : get_bloginfo( 'name' );
            $defaults = array( 'pickup' => sprintf( __( 'Pickup from %s', 'persiano-hub' ), $brand ), 'delivery' => sprintf( __( '%s local delivery', 'persiano-hub' ), $brand ), 'shipping' => sprintf( __( '%s shipping', 'persiano-hub' ), $brand ), 'custom' => __( 'Custom fulfilment', 'persiano-hub' ) );
            $managed_item->set_method_title( $label ? $label : $defaults[ $type ] );
            $managed_item->set_method_id( 'persiano_manual_' . $type );
            $managed_item->set_total( $fee );
            $managed_item->update_meta_data( '_persiano_manual_taxable', $taxable ? 'yes' : 'no' );
            if ( ! $taxable ) {
                $managed_item->set_taxes( array( 'total' => array() ) );
            }
            $managed_item->save();
        }
        $discount_label = isset( $_POST['persiano_admin_discount_label'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_admin_discount_label'] ) ) : '';
        $discount_amount = isset( $_POST['persiano_admin_discount_amount'] ) ? max( 0, (float) wc_format_decimal( wp_unslash( $_POST['persiano_admin_discount_amount'] ) ) ) : 0;
        $discount_item = false;
        foreach ( $order->get_items( 'fee' ) as $fee_id => $fee_item ) {
            if ( 'yes' === $fee_item->get_meta( '_persiano_manual_discount', true ) ) { $discount_item = $fee_item; if ( $discount_amount <= 0 ) { $order->remove_item( $fee_id ); $discount_item = false; } break; }
        }
        if ( $discount_amount > 0 ) {
            if ( ! $discount_item ) { $discount_item = new WC_Order_Item_Fee(); $order->add_item( $discount_item ); }
            $discount_item->set_name( $discount_label ? $discount_label : __( 'Manual discount', 'persiano-hub' ) );
            $discount_item->set_amount( -$discount_amount ); $discount_item->set_total( -$discount_amount ); $discount_item->set_tax_status( 'none' );
            $discount_item->update_meta_data( '_persiano_manual_discount', 'yes' ); $discount_item->save();
        }

        $snapshot = $order->get_meta( '_persiano_fulfilment_snapshot', true );
        $snapshot = is_array( $snapshot ) ? $snapshot : array();
        $snapshot['type'] = $type;
        $snapshot['label'] = $label;
        $snapshot['fee'] = $fee;
        $snapshot['taxable'] = $taxable ? 'yes' : 'no';
        $order->update_meta_data( '_persiano_fulfilment_snapshot', $snapshot );
        $order->calculate_totals( true );
        $order->save();
        self::$saving_order_tools = false;
    }


    private static function prepare_existing_order_for_pos( WC_Order $order ) {
        $order->update_meta_data( '_persiano_pos_order', 'yes' );
        $order->update_meta_data( '_persiano_pos_imported_order', 'yes' );
        $order->update_meta_data( '_persiano_pos_origin', 'Manual WooCommerce order' );
        $order->update_meta_data( '_persiano_pos_created_by', get_current_user_id() );
        $order->update_meta_data( '_persiano_pos_cashier_user_id', get_current_user_id() );
        if ( ! $order->get_meta( '_persiano_pos_handoff_code' ) ) {
            $order->update_meta_data( '_persiano_pos_handoff_code', 'PAY-' . str_pad( (string) $order->get_id(), 4, '0', STR_PAD_LEFT ) );
        }
        if ( ! $order->get_meta( '_persiano_square_callback_token' ) ) {
            $order->update_meta_data( '_persiano_square_callback_token', wp_generate_password( 40, false, false ) );
            $order->update_meta_data( '_persiano_square_callback_token_created', time() );
        }
        // Keep the existing order total, items, customer and taxes exactly as entered.
        // Pending is the correct unpaid state and does not complete the payment or reduce stock.
        if ( ! $order->has_status( 'pending' ) ) {
            $order->update_status( 'pending', __( 'Existing order opened in Batchly for payment collection.', 'persiano-hub' ), false );
        }
        $order->save();
    }

    public static function handle_open_existing_order_pos() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        if ( ! $order_id || ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'persiano_hub_open_existing_order_pos_' . $order_id ) ) {
            wp_die( esc_html__( 'You do not have permission to collect this payment.', 'persiano-hub' ), 403 );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded' ) ) || (float) $order->get_total() <= 0 ) {
            wp_die( esc_html__( 'This order is not eligible for payment collection.', 'persiano-hub' ), 400 );
        }
        self::prepare_existing_order_for_pos( $order );
        $order->add_order_note( __( 'Existing order opened in Batchly for payment collection. No duplicate order was created.', 'persiano-hub' ), false, true );
        wp_safe_redirect( home_url( '/hub/pay/' . $order_id . '/' ) );
        exit;
    }

}
