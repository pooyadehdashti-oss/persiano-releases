<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Workflow_Enhancements {
    const ORDER_ADVANCE = '_persiano_is_advance_order';
    const ORDER_LAST_EMAIL = '_persiano_last_customer_email_sent';
    const RECIPE_VARIANTS = '_persiano_recipe_variants';
    const PRICE_REVIEW = '_persiano_price_review_needed';
    private static $explicit_email = false;
    private static $saving_order_workflow = false;

    public static function init() {
        add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic_publishing_editor' ), 99, 2 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'order_meta_box' ), 40 );
        add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_order_workflow' ), 50, 2 );
        add_action( 'woocommerce_pay_order_before_payment', array( __CLASS__, 'pay_order_note_field' ) );
        add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'save_pay_order_note' ) );
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_note_copy' ), 99 );
        add_action( 'add_meta_boxes_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'recipe_variant_box' ), 30 );
        add_action( 'save_post_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'save_recipe_variants' ), 40, 3 );
        add_action( 'save_post_' . Persiano_Hub_Costing::RECIPE_POST_TYPE, array( __CLASS__, 'refresh_price_review' ), 60, 3 );
        add_action( 'save_post_' . Persiano_Hub_Costing::INGREDIENT_POST_TYPE, array( __CLASS__, 'refresh_linked_recipe_reviews' ), 60, 3 );
        add_action( 'admin_notices', array( __CLASS__, 'price_review_notice' ) );
        add_filter( 'woocommerce_order_actions', array( __CLASS__, 'email_actions' ), 80, 2 );
        add_action( 'woocommerce_order_action_persiano_send_updated_details', array( __CLASS__, 'send_updated_details' ) );

        $emails = array( 'new_order','cancelled_order','failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order','customer_refunded_order','customer_invoice','customer_note','customer_reset_password','customer_new_account' );
        foreach ( $emails as $email ) {
            add_filter( 'woocommerce_email_enabled_' . $email, array( __CLASS__, 'suppress_manual_order_email' ), 99, 3 );
        }
    }

    public static function classic_publishing_editor( $use, $post_type ) {
        return in_array( $post_type, array( Persiano_Hub_Publishing::POST_TYPE, Persiano_Hub_Publishing::UPDATE_POST_TYPE ), true ) ? false : $use;
    }

    private static function order_from_object( $object ) {
        if ( $object instanceof WC_Order ) return $object;
        if ( $object instanceof WP_Post ) return wc_get_order( $object->ID );
        $id = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
        return $id ? wc_get_order($id) : false;
    }

    public static function order_meta_box() {
        $screens = array('shop_order');
        if ( function_exists('wc_get_page_screen_id') ) $screens[] = wc_get_page_screen_id('shop-order');
        foreach ( array_unique(array_filter($screens)) as $screen ) {
            add_meta_box('persiano-order-workflow', __('Persiano order workflow','persiano-hub'), array(__CLASS__,'render_order_workflow'), $screen, 'side', 'high');
        }
    }

    public static function render_order_workflow( $object ) {
        $order = self::order_from_object($object); if ( ! $order ) return;
        wp_nonce_field('persiano_order_workflow_'.$order->get_id(),'persiano_order_workflow_nonce');
        $advance = 'yes' === $order->get_meta(self::ORDER_ADVANCE,true) || 'yes' === $order->get_meta('_persiano_advance_order',true);
        $date = $order->get_meta('_persiano_manual_fulfilment_datetime',true);
        $last = $order->get_meta(self::ORDER_LAST_EMAIL,true);
        echo '<p><label><input type="checkbox" name="persiano_convert_advance" value="yes" '.checked($advance,true,false).'> <strong>'.esc_html__('Advance order','persiano-hub').'</strong></label></p>';
        echo '<p><label>'.esc_html__('Requested fulfilment date/time','persiano-hub').'<input class="widefat" type="datetime-local" name="persiano_advance_datetime" value="'.esc_attr($date).'"></label></p>';
        echo '<p><label>'.esc_html__('Customer order note','persiano-hub').'<textarea class="widefat" rows="4" name="persiano_customer_order_note">'.esc_textarea($order->get_customer_note()).'</textarea></label></p>';
        echo '<p class="description">'.($last ? esc_html(sprintf(__('Last customer email sent: %s','persiano-hub'),$last)) : esc_html__('Customer has not been notified from Batchly.','persiano-hub')).'</p>';
        echo '<p class="description">'.esc_html__('Saving or recalculating this order does not email the customer. Use an explicit Order action to send details.','persiano-hub').'</p>';
    }

    public static function save_order_workflow( $order_id, $post = null ) {
        if ( self::$saving_order_workflow ) return;
        if ( empty($_POST['persiano_order_workflow_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['persiano_order_workflow_nonce'])),'persiano_order_workflow_'.$order_id) ) return;
        if ( ! current_user_can('edit_shop_order',$order_id) && ! current_user_can('manage_woocommerce') ) return;
        $order = wc_get_order($order_id); if(!$order) return;
        self::$saving_order_workflow = true;
        $is_advance = ! empty($_POST['persiano_convert_advance']);
        $date = isset($_POST['persiano_advance_datetime']) ? sanitize_text_field(wp_unslash($_POST['persiano_advance_datetime'])) : '';
        $old = 'yes' === $order->get_meta(self::ORDER_ADVANCE,true);
        $order->update_meta_data(self::ORDER_ADVANCE,$is_advance?'yes':'no');
        $order->update_meta_data('_persiano_advance_order',$is_advance?'yes':'no');
        if($date) $order->update_meta_data('_persiano_manual_fulfilment_datetime',$date);
        if(isset($_POST['persiano_customer_order_note'])) $order->set_customer_note(sanitize_textarea_field(wp_unslash($_POST['persiano_customer_order_note'])));
        if($old !== $is_advance) $order->add_order_note($is_advance?__('Converted to an advance order.','persiano-hub'):__('Converted back to a regular order.','persiano-hub'),false,true);
        $order->save();
        self::$saving_order_workflow = false;
    }

    public static function checkout_note_copy($fields){
        if(isset($fields['order']['order_comments'])){
            $fields['order']['order_comments']['label']=__('Order note (optional)','persiano-hub');
            $fields['order']['order_comments']['placeholder']=__('Dietary notes, packaging requests, pickup or delivery instructions, or anything else we should know.','persiano-hub');
        }
        return $fields;
    }

    public static function pay_order_note_field($order){
        if(!$order instanceof WC_Order) return;
        echo '<div class="ph-pay-order-note"><h3>'.esc_html__('Order note (optional)','persiano-hub').'</h3><p><textarea name="persiano_pay_order_note" rows="3" style="width:100%" placeholder="'.esc_attr__('Dietary notes, packaging requests, pickup or delivery instructions.','persiano-hub').'">'.esc_textarea($order->get_customer_note()).'</textarea></p></div>';
    }
    public static function save_pay_order_note($order){
        if($order instanceof WC_Order && isset($_POST['persiano_pay_order_note'])){$order->set_customer_note(sanitize_textarea_field(wp_unslash($_POST['persiano_pay_order_note'])));$order->save();}
    }

    public static function suppress_manual_order_email($enabled,$object=null,$email=null){
        if(self::$explicit_email) return $enabled;
        $order = $object instanceof WC_Order ? $object : false;
        if(!$order && is_object($email) && isset($email->object) && $email->object instanceof WC_Order) $order=$email->object;
        if(!$order) return $enabled;
        $manual = 'persiano-manual' === $order->get_created_via() || (bool) $order->get_meta('_persiano_manual_order_source',true) || (bool) $order->get_meta('_persiano_manual_created',true);
        return $manual ? false : $enabled;
    }

    public static function email_actions($actions,$order){ if($order instanceof WC_Order) $actions['persiano_send_updated_details']=__('Send updated order details','persiano-hub'); return $actions; }
    public static function send_updated_details($order){
        if(!$order instanceof WC_Order || !$order->get_billing_email()) return;
        self::$explicit_email=true;
        $emails=WC()->mailer()->get_emails(); if(isset($emails['WC_Email_Customer_Invoice'])) $emails['WC_Email_Customer_Invoice']->trigger($order->get_id(),$order);
        self::$explicit_email=false;
        $stamp=current_time('M j, Y g:i a'); $order->update_meta_data(self::ORDER_LAST_EMAIL,$stamp); $order->add_order_note(__('Updated order details emailed to the customer.','persiano-hub'),false,true); $order->save();
    }

    public static function recipe_variant_box(){ add_meta_box('persiano-recipe-variants',__('Recipe variants & substitutions','persiano-hub'),array(__CLASS__,'render_recipe_variants'),Persiano_Hub_Costing::RECIPE_POST_TYPE,'normal','default'); }
    public static function render_recipe_variants($post){
        wp_nonce_field('persiano_recipe_variants_'.$post->ID,'persiano_recipe_variants_nonce');
        $rows=get_post_meta($post->ID,self::RECIPE_VARIANTS,true); $rows=is_array($rows)?$rows:array(); $rows[] = array();
        echo '<p>'.esc_html__('Use one master recipe and record the differences for Chicken, Vegetarian, Vegan or other versions. Link each row to a WooCommerce product or variation.','persiano-hub').'</p><table class="widefat striped"><thead><tr><th>Variant</th><th>Product/variation ID</th><th>Action</th><th>Ingredient</th><th>Replacement / quantity note</th></tr></thead><tbody>';
        foreach($rows as $i=>$r){echo '<tr><td><input name="ph_variant['.$i.'][name]" value="'.esc_attr($r['name']??'').'" placeholder="Vegetarian"></td><td><input type="number" name="ph_variant['.$i.'][product_id]" value="'.esc_attr($r['product_id']??'').'"></td><td><select name="ph_variant['.$i.'][action]"><option value="">—</option><option '.selected($r['action']??'','remove',false).' value="remove">Remove</option><option '.selected($r['action']??'','substitute',false).' value="substitute">Substitute</option><option '.selected($r['action']??'','add',false).' value="add">Add</option><option '.selected($r['action']??'','quantity',false).' value="quantity">Change quantity</option></select></td><td><input name="ph_variant['.$i.'][ingredient]" value="'.esc_attr($r['ingredient']??'').'"></td><td><input class="widefat" name="ph_variant['.$i.'][detail]" value="'.esc_attr($r['detail']??'').'" placeholder="Chicken → soy, 120 g"></td></tr>';}
        echo '</tbody></table><p class="description">'.esc_html__('Production Planner can use the linked product or variation ID to distinguish demand. More than one row may use the same variant name.','persiano-hub').'</p>';
    }
    public static function save_recipe_variants($post_id,$post,$update){
        if(empty($_POST['persiano_recipe_variants_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['persiano_recipe_variants_nonce'])),'persiano_recipe_variants_'.$post_id))return;
        $out=array(); foreach((array)($_POST['ph_variant']??array()) as $r){$name=sanitize_text_field(wp_unslash($r['name']??'')); if(!$name)continue;$out[]=array('name'=>$name,'product_id'=>absint($r['product_id']??0),'action'=>sanitize_key($r['action']??''),'ingredient'=>sanitize_text_field(wp_unslash($r['ingredient']??'')),'detail'=>sanitize_text_field(wp_unslash($r['detail']??'')));} update_post_meta($post_id,self::RECIPE_VARIANTS,$out);
    }

    public static function refresh_price_review($post_id,$post,$update){
        if(wp_is_post_revision($post_id)||Persiano_Hub_Costing::RECIPE_POST_TYPE!==$post->post_type)return;
        if(!method_exists('Persiano_Hub_Costing','calculate_recipe_summary')) return;
        $summary=Persiano_Hub_Costing::calculate_recipe_summary($post_id); $product_id=absint(get_post_meta($post_id,Persiano_Hub_Costing::RECIPE_PRODUCT_ID,true)); $product=$product_id?wc_get_product($product_id):false;
        $current=$product?(float)$product->get_regular_price():0; $suggested=(float)($summary['suggested_price']??0); $net=$product?(float)wc_get_price_excluding_tax($product,array('price'=>$current)):0; $cost=(float)($summary['product_cost']??0); $margin=$net>0?(($net-$cost)/$net)*100:null; $target=100-(float)get_post_meta($post_id,Persiano_Hub_Costing::RECIPE_TARGET_COST_PCT,true); if($target<=1)$target=65;
        $basis_ready=!empty($summary['can_apply_price']);
        $needed=$product&&(!$basis_ready||($margin!==null&&$margin<$target)||($current>0&&$suggested>0&&abs($suggested-$current)>=0.50));
        update_post_meta($post_id,self::PRICE_REVIEW,$needed?'yes':'no'); update_post_meta($post_id,'_persiano_price_review_snapshot',array('current'=>$current,'suggested'=>$suggested,'margin'=>$margin,'target_margin'=>$target,'pricing_basis'=>$summary['pricing_label']??'','basis_ready'=>$basis_ready?'yes':'no','checked'=>current_time('mysql')));
    }
    public static function refresh_linked_recipe_reviews($post_id,$post,$update){
        if(wp_is_post_revision($post_id)||Persiano_Hub_Costing::INGREDIENT_POST_TYPE!==$post->post_type)return;
        $recipes=get_posts(array('post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'posts_per_page'=>-1,'post_status'=>'any','fields'=>'ids'));
        foreach($recipes as $rid){$items=get_post_meta($rid,Persiano_Hub_Costing::RECIPE_ITEMS,true); foreach((array)$items as $item){if(absint($item['source_id']??0)===$post_id){self::refresh_price_review($rid,get_post($rid),true);break;}}}
    }
    public static function price_review_notice(){
        $post_id=isset($_GET['post'])?absint($_GET['post']):0; if(!$post_id||Persiano_Hub_Costing::RECIPE_POST_TYPE!==get_post_type($post_id)||'yes'!==get_post_meta($post_id,self::PRICE_REVIEW,true))return;
        $s=get_post_meta($post_id,'_persiano_price_review_snapshot',true); $basis=($s['basis_ready']??'yes')==='yes'?'':__(' Set a compatible product Size / package before applying the suggested price.','persiano-hub'); echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Price review needed.','persiano-hub').'</strong> '.esc_html(sprintf(__('Current price: $%1$.2f · Suggested: $%2$.2f · Current margin: %3$s · Target margin: %4$.1f%%','persiano-hub'),(float)($s['current']??0),(float)($s['suggested']??0),isset($s['margin'])?number_format_i18n((float)$s['margin'],1).'%':'—',(float)($s['target_margin']??0))).esc_html($basis).'</p></div>';
    }
}
