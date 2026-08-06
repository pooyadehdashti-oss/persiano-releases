<?php
/** Universal mapped import/export for Batchly. */
if ( ! defined( 'ABSPATH' ) ) exit;

class Persiano_Hub_Universal_Import_Export {
    const PAGE = 'persiano-hub-import-export';
    const TRANSIENT = 'ph_universal_import_';
    private static $current_import_batch = '';
    private static $defer_recipe_recalc = false;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 80 );
        add_action( 'admin_post_ph_universal_upload', array( __CLASS__, 'upload' ) );
        add_action( 'admin_post_ph_universal_import', array( __CLASS__, 'run_import' ) );
        add_action( 'admin_post_ph_universal_export', array( __CLASS__, 'export' ) );
    }

    public static function menu() {
        add_submenu_page( 'persiano-hub', 'Import & Export', 'Import & Export', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
    }

    private static function datasets() {
        return array(
            'products' => 'Products', 'recipe_workbook' => 'Complete Recipe Workbook (XLSX)', 'recipes' => 'Recipes', 'recipe_ingredients' => 'Recipe Ingredients', 'recipe_steps' => 'Recipe Steps', 'recipe_notes' => 'Recipe Notes', 'batch_logs' => 'Batch Logs', 'ingredients' => 'Master Ingredients', 'suppliers' => 'Suppliers',
            'ingredient_aliases' => 'Ingredient Aliases', 'ingredient_price_history' => 'Ingredient Price History', 'supplier_items' => 'Supplier Items',
            'subscribers' => 'Mailing-list subscribers', 'customers' => 'Customers', 'orders' => 'Orders', 'loss_waste' => 'Loss & Waste',
        );
    }

    private static function schemas() {
        return array(
            'products' => array(
                'id'=>array('ID','product_id'), 'sku'=>array('sku','product_sku'), 'type'=>array('type','product_type_wc'), 'name'=>array('name','product_name','product_name_en','title'), 'slug'=>array('slug','post_name'), 'published'=>array('published','is_published'), 'featured'=>array('featured','is_featured'), 'catalog_visibility'=>array('catalog_visibility','visibility_in_catalog'),
                'status'=>array('status','post_status'), 'regular_price'=>array('regular_price','price','normal_price'), 'sale_price'=>array('sale_price','discount_price'),
                'manage_stock'=>array('manage_stock','manage_inventory'), 'stock_status'=>array('stock_status','inventory_status','in_stock'), 'stock_quantity'=>array('stock_quantity','stock','qty','quantity'), 'categories'=>array('categories','category'), 'brands'=>array('brands','brand'), 'tags'=>array('tags','tag'), 'shipping_class'=>array('shipping_class'), 'weight_kg'=>array('weight_kg','weight','net_weight_kg'), 'dimensions'=>array('dimensions','dimensions_l_w_h_cm'), 'image_url'=>array('image_url','main_image_url','images'), 'gallery_urls'=>array('gallery_urls','gallery_images'),
                'short_description'=>array('short_description','short_description_en','excerpt'), 'description'=>array('description','description_en','full_description','content'), 'fa_short_description'=>array('fa_short_description','short_description_fa'), 'fa_description'=>array('fa_description','description_fa'),
                'persian_name'=>array('persian_name','product_name_fa','fa_title','farsi_title'), 'product_format'=>array('product_format','product_type','persiano_type'),
                'serving_size'=>array('serving_size','net_weight','package_size'), 'label_notes'=>array('label_notes'), 'internal_notes'=>array('internal_notes','internal_product_notes'), 'recipe_reference'=>array('recipe_reference','linked_recipe','recipe_id'), 'supplier_reference'=>array('supplier_reference','supplier_id'), 'serving_suggestions'=>array('serving_suggestions','serving_suggestions_en'), 'includes'=>array('includes','included_items'), 'main_ingredients'=>array('main_ingredients','ingredients'),
                'dietary_information'=>array('dietary_information','dietary'), 'allergen_information'=>array('allergen_information','allergens'),
                'storage_instructions'=>array('storage_instructions','storage'), 'reheating_instructions'=>array('reheating_instructions','reheating'),
                'availability'=>array('availability','persiano_availability'), 'available_date'=>array('available_date','fulfilment_date'), 'order_deadline'=>array('order_deadline','cutoff'),
                'advance_notice_hours'=>array('advance_notice_hours','notice_hours'), 'minimum_quantity'=>array('minimum_quantity','min_qty'),
                'seasonal_return_date'=>array('seasonal_return_date','return_date'), 'unavailable_message'=>array('unavailable_message'),
                'show_in_this_week'=>array('show_in_this_week','this_week'), 'show_in_pantry'=>array('show_in_pantry','pantry'), 'tax_status'=>array('tax_status'), 'tax_class'=>array('tax_class'), 'seo_title'=>array('seo_title','meta_title'), 'meta_description'=>array('meta_description','seo_description'),
            ),
            'recipes' => array(
                'id'=>array('id','recipe_id'), 'recipe_key'=>array('recipe_key','key','slug'), 'name'=>array('name','recipe_name','title'), 'status'=>array('status'),
                'linked_product_sku'=>array('linked_product_sku','product_sku'), 'linked_product_id'=>array('linked_product_id','product_id'),
                'yield_qty'=>array('yield_qty','yield','base_yield'), 'yield_unit'=>array('yield_unit','yield_label'), 'portions'=>array('portions','base_portions'),
                'prep_minutes'=>array('prep_minutes','prep_time'), 'cook_minutes'=>array('cook_minutes','cook_time','passive_minutes'), 'category'=>array('category','recipe_category'), 'serving_note'=>array('serving_note','serving_yield_description'), 'equipment'=>array('equipment'), 'packaging_cost'=>array('packaging_cost'),
                'labour_minutes'=>array('labour_minutes'), 'labour_rate'=>array('labour_rate'), 'other_cost'=>array('other_cost'), 'overhead_pct'=>array('overhead_pct'), 'target_food_cost_pct'=>array('target_food_cost_pct'),
                'normalized_base_unit'=>array('normalized_base_unit'), 'cost_per_base_unit'=>array('cost_per_base_unit'), 'pricing_basis'=>array('pricing_basis'), 'product_package_cost'=>array('product_package_cost'), 'suggested_price_tax_included'=>array('suggested_price_tax_included'),
                'storage'=>array('storage','storage_instructions'), 'reheating'=>array('reheating','reheating_instructions'), 'packaging'=>array('packaging','plating_notes'),
                'pinned_note'=>array('pinned_note','critical_note'), 'troubleshooting'=>array('troubleshooting','next_time_notes'),
            ),
            'recipe_ingredients' => array('recipe_key'=>array('recipe_key'),'recipe_id'=>array('recipe_id'),'recipe_name'=>array('recipe_name','recipe'),'line_number'=>array('line_number','row_number','position','sort_order'),'source_type'=>array('source_type'),'ingredient_id'=>array('ingredient_id','ingredient_or_recipe_id'),'ingredient_name'=>array('ingredient_name','ingredient','ingredient_or_recipe_name'),'quantity'=>array('quantity','qty'),'unit'=>array('unit'),'ingredient_type'=>array('ingredient_type','type'),'preparation_note'=>array('preparation_note','preparation','prep_note','note'),'optional'=>array('optional'),'include_on_label'=>array('include_on_label','label'),'waste_percentage'=>array('waste_percentage','waste_pct'),'rounding'=>array('rounding')),
            'recipe_steps' => array('recipe_key'=>array('recipe_key'),'recipe_id'=>array('recipe_id'),'recipe_name'=>array('recipe_name','recipe'),'step_number'=>array('step_number','number','position'),'step_title'=>array('step_title','title'),'instruction'=>array('instruction','step','method'),'duration_minutes'=>array('duration_minutes','duration','minutes'),'time_type'=>array('time_type','activity_type','active_or_passive'),'temperature'=>array('temperature','temp'),'equipment'=>array('equipment'),'critical_step'=>array('critical_step','critical'),'internal_note'=>array('internal_note','note')),
            'recipe_notes' => array('id'=>array('id','comment_id'),'recipe_key'=>array('recipe_key'),'recipe_id'=>array('recipe_id'),'recipe_name'=>array('recipe_name','recipe'),'note_type'=>array('note_type','type'),'comment'=>array('comment','note'),'pinned'=>array('pinned','pin'),'author_email'=>array('author_email'),'date'=>array('date')),
            'batch_logs' => array('id'=>array('id','comment_id'),'recipe_key'=>array('recipe_key'),'recipe_id'=>array('recipe_id'),'recipe_name'=>array('recipe_name','recipe'),'batch_date'=>array('batch_date','date'),'batch_quantity'=>array('batch_quantity','quantity'),'result'=>array('result'),'changes_made'=>array('changes_made'),'quality_notes'=>array('quality_notes'),'waste_value'=>array('waste_value','loss_value'),'production_cost'=>array('production_cost','batch_cost'),'next_batch_note'=>array('next_batch_note'),'author_email'=>array('author_email')),
            'ingredients' => array(
                'id'=>array('id','ingredient_id'), 'name'=>array('name','ingredient_name','title'), 'status'=>array('status'), 'type'=>array('type','ingredient_type'), 'category'=>array('category'), 'brand'=>array('brand'),
                'preferred_unit'=>array('preferred_unit','unit'), 'purchase_quantity'=>array('purchase_quantity','package_quantity'), 'purchase_unit'=>array('purchase_unit','package_unit'), 'purchase_cost'=>array('purchase_cost','package_cost'), 'purchase_tax'=>array('purchase_tax','tax'), 'waste_percentage'=>array('waste_percentage','waste_pct'), 'grams_per_cup'=>array('grams_per_cup','g_per_cup','gpc'),
                'grams_per_tbsp'=>array('grams_per_tbsp','g_per_tbsp','gpt'), 'grams_per_tsp'=>array('grams_per_tsp','g_per_tsp','gps'),
                'density_g_ml'=>array('density_g_ml','density'), 'cost_per_unit'=>array('cost_per_unit','unit_cost'), 'include_on_label'=>array('include_on_label','label'),
                'primary_supplier'=>array('primary_supplier','supplier'), 'backup_supplier'=>array('backup_supplier'), 'notes'=>array('notes'),
            ),
            'ingredient_aliases' => array(
                'canonical_id'=>array('canonical_id','ingredient_canonical_id'), 'ingredient_id'=>array('ingredient_id','id'), 'ingredient_name'=>array('ingredient_name','name'),
                'alias'=>array('alias','alternate_name','synonym'), 'family'=>array('family','ingredient_family'), 'needs_review'=>array('needs_review','review')
            ),
            'ingredient_price_history' => array(
                'record_id'=>array('record_id','id'), 'canonical_id'=>array('canonical_id','ingredient_canonical_id'), 'ingredient_id'=>array('ingredient_id'), 'ingredient_name'=>array('ingredient_name','name'),
                'supplier_id'=>array('supplier_id'), 'supplier_name'=>array('supplier_name','supplier','vendor'), 'supplier_item_code'=>array('supplier_item_code','item_code','vendor_sku'),
                'purchase_date'=>array('purchase_date','date'), 'package_quantity'=>array('package_quantity','purchase_quantity','qty'), 'package_unit'=>array('package_unit','purchase_unit','unit'),
                'price_before_tax'=>array('price_before_tax','net_price'), 'tax_amount'=>array('tax_amount','tax'), 'total_paid'=>array('total_paid','gross_cost','purchase_cost'),
                'normalized_unit_cost'=>array('normalized_unit_cost','unit_cost','cost_per_unit'), 'normalized_unit'=>array('normalized_unit','base_unit'), 'sale_price'=>array('sale_price','is_sale'),
                'source_type'=>array('source_type','source'), 'invoice_reference'=>array('invoice_reference','invoice','receipt'), 'notes'=>array('notes'), 'approved'=>array('approved','is_approved'), 'import_batch_id'=>array('import_batch_id','batch_id')
            ),
            'supplier_items' => array(
                'record_id'=>array('record_id','id'), 'canonical_id'=>array('canonical_id','ingredient_canonical_id'), 'ingredient_id'=>array('ingredient_id'), 'ingredient_name'=>array('ingredient_name','name'),
                'supplier_id'=>array('supplier_id'), 'supplier_name'=>array('supplier_name','supplier','vendor'), 'supplier_item_code'=>array('supplier_item_code','item_code','vendor_sku'),
                'brand'=>array('brand'), 'item_name'=>array('item_name','supplier_item_name'), 'package_quantity'=>array('package_quantity','purchase_quantity'), 'package_unit'=>array('package_unit','purchase_unit'),
                'current_price'=>array('current_price','price','gross_cost'), 'tax_amount'=>array('tax_amount','tax'), 'normalized_unit_cost'=>array('normalized_unit_cost','unit_cost'), 'normalized_unit'=>array('normalized_unit','base_unit'),
                'product_url'=>array('product_url','url'), 'active'=>array('active','status'), 'notes'=>array('notes'), 'import_batch_id'=>array('import_batch_id','batch_id')
            ),
            'suppliers' => array(
                'id'=>array('id','supplier_id'), 'name'=>array('name','supplier_name','business_name','title'), 'status'=>array('status'), 'contact_person'=>array('contact_person','contact'),
                'phone'=>array('phone','telephone'), 'mobile'=>array('mobile','cell'), 'email'=>array('email'), 'website'=>array('website','url'), 'address'=>array('address'),
                'categories'=>array('categories','category'), 'account_number'=>array('account_number','customer_number'), 'delivery_days'=>array('delivery_days'),
                'minimum_order'=>array('minimum_order','min_order'), 'payment_terms'=>array('payment_terms'), 'preferred_contact'=>array('preferred_contact'),
                'role'=>array('role','supplier_role'), 'active'=>array('active','is_active'), 'notes'=>array('notes'),
            ),
            'subscribers' => array('id'=>array('id'),'email'=>array('email','email_address'),'name'=>array('name','full_name'),'status'=>array('status'),'source'=>array('source'),'tags'=>array('tags'),'consent_at'=>array('consent_at','consent_date')),
            'customers' => array('id'=>array('id','customer_id'),'email'=>array('email','billing_email'),'first_name'=>array('first_name'),'last_name'=>array('last_name'),'phone'=>array('phone','billing_phone'),'address_1'=>array('address_1','street'),'address_2'=>array('address_2','unit'),'city'=>array('city'),'state'=>array('state','province'),'postcode'=>array('postcode','postal_code'),'country'=>array('country')),
            'orders' => array('id'=>array('id','order_id','order_number'),'status'=>array('status','order_status'),'customer_email'=>array('customer_email','billing_email'),'total'=>array('total','order_total'),'payment_method'=>array('payment_method'),'fulfilment'=>array('fulfilment'),'fulfilment_date'=>array('fulfilment_date'),'customer_note'=>array('customer_note'),'internal_note'=>array('internal_note')),
            'loss_waste' => array('id'=>array('id','record_id'),'date'=>array('date','recorded_at'),'order_id'=>array('order_id'),'item_name'=>array('item_name','product'),'quantity'=>array('quantity','qty'),'amount'=>array('amount','value'),'outcome'=>array('outcome','type'),'reason'=>array('reason'),'note'=>array('note','notes')),
        );
    }

    private static function normalize( $value ) { return strtolower( preg_replace( '/[^a-z0-9]+/', '_', trim( (string) $value ) ) ); }
    private static function auto_map( $dataset, $headers ) {
        $schema = self::schemas()[ $dataset ] ?? array(); $map = array(); $saved = get_option( 'persiano_hub_import_mapping_' . $dataset, array() );
        foreach ( $headers as $i => $header ) {
            $norm = self::normalize( $header ); $best = '';
            // Exact schema aliases always beat an older saved mapping. This prevents SKU → ID, Type → Categories and Tax Status → Tax Class mistakes.
            foreach ( $schema as $field => $aliases ) {
                $candidates = array_merge( array( $field ), $aliases );
                foreach ( $candidates as $candidate ) { if ( $norm === self::normalize( $candidate ) ) { $best = $field; break 2; } }
            }
            if ( ! $best && isset( $saved[ $norm ] ) && isset( $schema[ $saved[ $norm ] ] ) ) { $best = $saved[ $norm ]; }
            $map[$i] = $best;
        }
        return $map;
    }

    public static function render() {
        if ( ! current_user_can('manage_woocommerce') ) return;
        $datasets = self::datasets();
        echo '<div class="wrap"><h1>Persiano Import & Export</h1><p>Move data between Batchly and CSV, TSV, JSON or XLSX files. Column names are matched automatically and can be corrected before import.</p>';
        if ( ! empty($_GET['ph_notice']) ) echo '<div class="notice notice-success"><p>'.esc_html(wp_unslash($_GET['ph_notice'])).'</p></div>';
        $last_report = get_user_meta( get_current_user_id(), '_persiano_hub_last_import_report', true );
        if ( is_array( $last_report ) && ! empty( $last_report['failed'] ) ) {
            echo '<div class="notice notice-warning"><p><strong>'.esc_html__('The last import had failed rows. Nothing from those rows was saved.','persiano-hub').'</strong></p>';
            echo '<p>'.esc_html(sprintf(__('Table: %1$s · Created: %2$d · Updated: %3$d · Failed: %4$d · Batch: %5$s','persiano-hub'), self::datasets()[$last_report['dataset']] ?? $last_report['dataset'], absint($last_report['created'] ?? 0), absint($last_report['updated'] ?? 0), absint($last_report['failed'] ?? 0), $last_report['batch_id'] ?? '—')).'</p>';
            if ( ! empty( $last_report['errors'] ) ) {
                echo '<details><summary>'.esc_html__('Show failed rows','persiano-hub').'</summary><table class="widefat striped" style="margin:12px 0"><thead><tr><th>'.esc_html__('Row','persiano-hub').'</th><th>'.esc_html__('Ingredient / record','persiano-hub').'</th><th>'.esc_html__('Reason','persiano-hub').'</th></tr></thead><tbody>';
                foreach ( array_slice( (array) $last_report['errors'], 0, 100 ) as $error ) {
                    echo '<tr><td>'.esc_html($error['row'] ?? '').'</td><td>'.esc_html($error['label'] ?? '').'</td><td>'.esc_html($error['message'] ?? '').'</td></tr>';
                }
                echo '</tbody></table></details>';
            }
            echo '</div>';
        }
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1200px"><div class="card"><h2>Import</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data"><input type="hidden" name="action" value="ph_universal_upload">'; wp_nonce_field('ph_universal_upload');
        echo '<p><label><strong>Table</strong><br><select name="dataset">'; $selected_dataset=sanitize_key($_GET['dataset']??''); foreach($datasets as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($selected_dataset,$k,false).'>'.esc_html($v).'</option>'; echo '</select></label></p>';
        echo '<p><label><strong>File</strong><br><input type="file" name="import_file" accept=".csv,.tsv,.txt,.json,.xlsx" required></label></p><p><label><input type="checkbox" name="overwrite_blanks" value="1"> Allow blank cells to erase existing values</label></p><p><button class="button button-primary">Upload and map columns</button></p></form></div>';
        echo '<div class="card"><h2>Export</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('ph_universal_export'); echo '<input type="hidden" name="action" value="ph_universal_export"><p><label><strong>Table</strong><br><select name="dataset">'; foreach($datasets as $k=>$v) echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>'; echo '</select></label></p><p><label><strong>Format</strong><br><select name="format"><option value="csv">CSV</option><option value="tsv">TSV</option><option value="json">JSON</option><option value="xlsx">XLSX</option></select></label></p><p><button class="button button-primary">Download export</button></p></form></div></div>';
        echo '<h2>Import safety</h2><p>Products, recipes, ingredients, suppliers, subscribers and customers can be created or updated. Orders are update-only and require an existing order ID. Loss & Waste records are merged by record ID. A preview and mapping step is always shown before changes are saved.</p></div>';
    }

    public static function upload() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Forbidden',403); check_admin_referer('ph_universal_upload');
        $dataset = sanitize_key($_POST['dataset'] ?? ''); if ( ! isset(self::datasets()[$dataset]) ) wp_die('Invalid table');
        if ( empty($_FILES['import_file']['tmp_name']) ) wp_die('No file uploaded');
        $parsed = ('recipe_workbook' === $dataset) ? self::parse_recipe_workbook($_FILES['import_file']['tmp_name'], $_FILES['import_file']['name']) : self::parse_file($_FILES['import_file']['tmp_name'], $_FILES['import_file']['name']);
        if ( is_wp_error($parsed) ) wp_die(esc_html($parsed->get_error_message()));
        $token = wp_generate_password(20,false,false); if('recipe_workbook'===$dataset){ set_transient(self::TRANSIENT.get_current_user_id().'_'.$token,array('dataset'=>$dataset,'sheets'=>$parsed['sheets'],'overwrite'=>!empty($_POST['overwrite_blanks'])),HOUR_IN_SECONDS); self::render_workbook_mapping($token,$parsed['sheets']); } else { set_transient(self::TRANSIENT.get_current_user_id().'_'.$token, array('dataset'=>$dataset,'headers'=>$parsed['headers'],'rows'=>$parsed['rows'],'overwrite'=>!empty($_POST['overwrite_blanks'])), HOUR_IN_SECONDS); self::render_mapping($token,$dataset,$parsed['headers'],$parsed['rows']); } exit;
    }

    private static function render_mapping($token,$dataset,$headers,$rows) {
        $schema = self::schemas()[$dataset] ?? array(); $auto=self::auto_map($dataset,$headers);
        echo '<div class="wrap"><h1>Map columns: '.esc_html(self::datasets()[$dataset]).'</h1><p>'.count($rows).' data rows detected. Review the automatic mapping before importing.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('ph_universal_import'); echo '<input type="hidden" name="action" value="ph_universal_import"><input type="hidden" name="token" value="'.esc_attr($token).'"><table class="widefat striped"><thead><tr><th>File column</th><th>Sample</th><th>Map to</th></tr></thead><tbody>';
        foreach($headers as $i=>$h){ echo '<tr><td><strong>'.esc_html($h).'</strong></td><td>'.esc_html(mb_strimwidth((string)($rows[0][$i]??''),0,90,'…')).'</td><td><select name="map['.$i.']"><option value="">Do not import</option>'; foreach($schema as $field=>$aliases){ echo '<option value="'.esc_attr($field).'" '.selected($auto[$i]??'',$field,false).'>'.esc_html(ucwords(str_replace('_',' ',$field))).'</option>'; } echo '</select></td></tr>'; }
        echo '</tbody></table><p><label><input type="checkbox" name="save_mapping" value="1" checked> Save this mapping as the default for future imports of this table.</label></p><p><label><input type="checkbox" name="confirm" value="1" required> I reviewed the mapping and want to import these rows.</label></p><p><button class="button button-primary button-hero">Run import</button> <a class="button" href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">Cancel</a></p></form></div>';
    }

    public static function run_import() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Forbidden',403); check_admin_referer('ph_universal_import');
        $token=sanitize_text_field($_POST['token']??''); $key=self::TRANSIENT.get_current_user_id().'_'.$token; $data=get_transient($key); if(!$data) wp_die('Import session expired.');
        if('recipe_workbook'===($data['dataset']??'')){ self::run_workbook_import($data,$_POST); delete_transient($key); exit; }
        $map=array_map('sanitize_key',(array)($_POST['map']??array()));
        if(!empty($_POST['save_mapping'])){$saved=array();foreach((array)$data['headers'] as $i=>$header){if(!empty($map[$i]))$saved[self::normalize($header)]=$map[$i];}update_option('persiano_hub_import_mapping_'.$data['dataset'],$saved,false);}
        $created=0;$updated=0;$failed=0;$errors=array();
        self::$current_import_batch = wp_generate_uuid4();
        self::$defer_recipe_recalc = true;
        foreach($data['rows'] as $row_index=>$row){
            $record=array(); foreach($map as $i=>$field){ if($field!=='') $record[$field]=$row[(int)$i]??''; }
            if(!$record) continue;
            $result=self::save_record($data['dataset'],$record,!empty($data['overwrite']));
            if(is_wp_error($result)){
                $failed++;
                $errors[]=array('row'=>$row_index+2,'label'=>sanitize_text_field($record['ingredient_name']??$record['name']??$record['record_id']??''),'message'=>$result->get_error_message());
            } elseif($result==='created'){$created++;} else {$updated++;}
        }
        $report=array('time'=>time(),'dataset'=>$data['dataset'],'batch_id'=>self::$current_import_batch,'created'=>$created,'updated'=>$updated,'failed'=>$failed,'errors'=>$errors);
        update_user_meta(get_current_user_id(),'_persiano_hub_last_import_report',$report);
        self::$defer_recipe_recalc = false;
        if('ingredient_price_history'===$data['dataset'] && ($created||$updated) && method_exists('Persiano_Hub_Costing','recalculate_recipe')){foreach(get_posts(array('post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true))as$recipe_id){Persiano_Hub_Costing::recalculate_recipe($recipe_id);}}
        self::$current_import_batch = '';
        delete_transient($key);
        $msg=sprintf('Import complete: %d created, %d updated, %d failed. Batch: %s',$created,$updated,$failed,$report['batch_id']);
        wp_safe_redirect(add_query_arg('ph_notice',$msg,admin_url('admin.php?page='.self::PAGE))); exit;
    }

    private static function value($record,$key,$overwrite,$existing=''){ if(!array_key_exists($key,$record)) return $existing; if($record[$key]===''&&!$overwrite) return $existing; return $record[$key]; }
    private static function save_record($dataset,$r,$overwrite){
        if('products'===$dataset){
            $product=false;
            if(!empty($r['id'])) $product=wc_get_product(absint($r['id']));
            if(!$product&&!empty($r['sku'])){ $id=wc_get_product_id_by_sku($r['sku']); if($id)$product=wc_get_product($id); }
            $new=!$product;
            if(!$product) $product=new WC_Product_Simple();
            if(isset($r['name']))$product->set_name(self::value($r,'name',$overwrite,$product->get_name()));
            if(isset($r['slug']))$product->set_slug(sanitize_title(self::value($r,'slug',$overwrite,$product->get_slug())));
            if(isset($r['status']))$product->set_status(self::normalize_post_status($r['status'],$product->get_status()));
            if(isset($r['published'])&&!isset($r['status']))$product->set_status(self::truthy($r['published'])?'publish':'draft');
            if(isset($r['featured']))$product->set_featured(self::truthy($r['featured']));
            if(isset($r['catalog_visibility']))$product->set_catalog_visibility(sanitize_key($r['catalog_visibility']));
            if(isset($r['sku'])&&self::value($r,'sku',$overwrite,$product->get_sku())!=='')$product->set_sku(self::value($r,'sku',$overwrite,$product->get_sku()));
            if(isset($r['regular_price']))$product->set_regular_price(wc_format_decimal(self::value($r,'regular_price',$overwrite,$product->get_regular_price())));
            if(isset($r['sale_price']))$product->set_sale_price(wc_format_decimal(self::value($r,'sale_price',$overwrite,$product->get_sale_price())));
            if(isset($r['manage_stock']))$product->set_manage_stock(self::truthy($r['manage_stock']));
            if(isset($r['stock_status']))$product->set_stock_status(self::normalize_stock_status($r['stock_status']));
            if(isset($r['stock_quantity'])&&''!==$r['stock_quantity']){$product->set_manage_stock(true);$product->set_stock_quantity((int)$r['stock_quantity']);}
            if(isset($r['tax_status']))$product->set_tax_status(self::normalize_tax_status($r['tax_status']));
            if(isset($r['tax_class']))$product->set_tax_class(sanitize_key($r['tax_class']));
            if(isset($r['weight_kg']))$product->set_weight(wc_format_decimal($r['weight_kg']));
            if(isset($r['short_description']))$product->set_short_description(wp_kses_post(self::value($r,'short_description',$overwrite,$product->get_short_description())));
            if(isset($r['description']))$product->set_description(wp_kses_post(self::value($r,'description',$overwrite,$product->get_description())));
            $id=$product->save();
            $meta=array(
                'persian_name'=>'_persiano_fa_title','fa_short_description'=>'_persiano_fa_short_description','fa_description'=>'_persiano_fa_description',
                'product_format'=>'_persiano_product_format','serving_size'=>'_persiano_serving_size','serving_suggestions'=>'_persiano_serving_suggestions',
                'includes'=>'_persiano_includes','main_ingredients'=>'_persiano_main_ingredients','dietary_information'=>'_persiano_dietary_information',
                'allergen_information'=>'_persiano_allergen_information','storage_instructions'=>'_persiano_storage_instructions','reheating_instructions'=>'_persiano_reheating_instructions',
                'label_notes'=>'_persiano_label_notes','internal_notes'=>'_persiano_internal_notes','supplier_reference'=>'_persiano_supplier_reference',
                'availability'=>'_persiano_availability','available_date'=>'_persiano_available_date','order_deadline'=>'_persiano_order_deadline',
                'advance_notice_hours'=>'_persiano_advance_notice_hours','minimum_quantity'=>'_persiano_minimum_quantity','seasonal_return_date'=>'_persiano_seasonal_return_date',
                'unavailable_message'=>'_persiano_unavailable_message','show_in_this_week'=>'_persiano_show_this_week','show_in_pantry'=>'_persiano_show_pantry',
                'seo_title'=>'_persiano_seo_title','meta_description'=>'_persiano_meta_description'
            );
            foreach($meta as $f=>$m){if(array_key_exists($f,$r)&&($r[$f]!==''||$overwrite))update_post_meta($id,$m,sanitize_textarea_field($r[$f]));}
            if(isset($r['recipe_reference'])&&''!==$r['recipe_reference']){
                $recipe_id=self::resolve_recipe($r['recipe_reference'],$r['recipe_reference']);
                if($recipe_id){update_post_meta($id,Persiano_Hub_Costing::PRODUCT_RECIPE_META,$recipe_id);update_post_meta($recipe_id,Persiano_Hub_Costing::RECIPE_PRODUCT_ID,$id);}
            }
            if(isset($r['categories']))wp_set_object_terms($id,array_filter(array_map('trim',preg_split('/[;,|]/',$r['categories']))),'product_cat');
            if(isset($r['tags']))wp_set_object_terms($id,array_filter(array_map('trim',preg_split('/[;,|]/',$r['tags']))),'product_tag');
            return $new?'created':'updated';
        }

        if('recipes'===$dataset){
            $id=self::resolve_recipe($r['id']??0,$r['recipe_key']??($r['name']??''));
            $new=!$id;
            $title=sanitize_text_field($r['name']??($id?get_the_title($id):'Untitled Recipe'));
            $id=wp_insert_post(array('ID'=>$id,'post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_title'=>$title,'post_status'=>self::normalize_post_status($r['status']??'publish','publish')),true);
            if(is_wp_error($id))return $id;
            if(!empty($r['recipe_key']))update_post_meta($id,'_persiano_recipe_key',sanitize_title($r['recipe_key']));
            $product_id=absint($r['linked_product_id']??0);
            if(!$product_id&&!empty($r['linked_product_sku']))$product_id=wc_get_product_id_by_sku(sanitize_text_field($r['linked_product_sku']));
            $map=array(
                'yield_qty'=>Persiano_Hub_Costing::RECIPE_YIELD_QTY,'yield_unit'=>Persiano_Hub_Costing::RECIPE_YIELD_LABEL,
                'packaging_cost'=>Persiano_Hub_Costing::RECIPE_PACKAGING_COST,'labour_minutes'=>Persiano_Hub_Costing::RECIPE_LABOUR_MINUTES,
                'labour_rate'=>Persiano_Hub_Costing::RECIPE_LABOUR_RATE,'other_cost'=>Persiano_Hub_Costing::RECIPE_OTHER_COST,
                'overhead_pct'=>Persiano_Hub_Costing::RECIPE_OVERHEAD_PCT,'target_food_cost_pct'=>Persiano_Hub_Costing::RECIPE_TARGET_COST_PCT,
                'storage'=>'_persiano_recipe_storage','reheating'=>'_persiano_recipe_reheating','packaging'=>'_persiano_recipe_packaging',
                'pinned_note'=>'_persiano_recipe_pinned_note','troubleshooting'=>'_persiano_recipe_troubleshooting','prep_minutes'=>'_persiano_recipe_prep_minutes',
                'cook_minutes'=>'_persiano_recipe_passive_minutes','portions'=>'_persiano_recipe_portions','category'=>'_persiano_recipe_category','serving_note'=>'_persiano_recipe_serving_note','equipment'=>'_persiano_recipe_equipment'
            );
            foreach($map as$f=>$m){if(array_key_exists($f,$r)&&($r[$f]!==''||$overwrite)){ $value='yield_unit'===$f?Persiano_Hub_Costing::canonical_recipe_unit($r[$f]):sanitize_textarea_field($r[$f]); update_post_meta($id,$m,$value); }}
            if($product_id){update_post_meta($id,Persiano_Hub_Costing::RECIPE_PRODUCT_ID,$product_id);update_post_meta($product_id,Persiano_Hub_Costing::PRODUCT_RECIPE_META,$id);}
            Persiano_Hub_Costing::recalculate_recipe($id);
            return $new?'created':'updated';
        }

        if('ingredients'===$dataset){
            $id=!empty($r['id'])?absint($r['id']):0;
            if(!$id&&!empty($r['name'])){$found=get_page_by_title(sanitize_text_field($r['name']),OBJECT,Persiano_Hub_Costing::INGREDIENT_POST_TYPE);if($found)$id=$found->ID;}
            $new=!$id;
            $id=wp_insert_post(array('ID'=>$id,'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_title'=>sanitize_text_field($r['name']??($id?get_the_title($id):'Untitled Ingredient')),'post_status'=>self::normalize_post_status($r['status']??'publish','publish')),true);
            if(is_wp_error($id))return$id;
            $map=array(
                'category'=>Persiano_Hub_Costing::ING_CATEGORY,'brand'=>Persiano_Hub_Costing::ING_BRAND,'purchase_quantity'=>Persiano_Hub_Costing::ING_PURCHASE_QTY,
                'purchase_unit'=>Persiano_Hub_Costing::ING_PURCHASE_UNIT,'purchase_cost'=>Persiano_Hub_Costing::ING_PURCHASE_COST,'purchase_tax'=>Persiano_Hub_Costing::ING_PURCHASE_TAX,
                'waste_percentage'=>Persiano_Hub_Costing::ING_WASTE_PCT,'notes'=>Persiano_Hub_Costing::ING_NOTES,'type'=>'_persiano_ingredient_type',
                'preferred_unit'=>'_persiano_preferred_unit','grams_per_cup'=>'_persiano_grams_per_cup','grams_per_tbsp'=>'_persiano_grams_per_tbsp',
                'grams_per_tsp'=>'_persiano_grams_per_tsp','density_g_ml'=>'_persiano_density_g_ml','include_on_label'=>'_persiano_include_on_label',
                'primary_supplier'=>'_persiano_primary_supplier','backup_supplier'=>'_persiano_backup_supplier'
            );
            foreach($map as$f=>$m){if(array_key_exists($f,$r)&&($r[$f]!==''||$overwrite))update_post_meta($id,$m,sanitize_textarea_field($r[$f]));}
            return$new?'created':'updated';
        }

        if('suppliers'===$dataset){
            $pt=Persiano_Hub_Production_Suite::SUPPLIER_POST_TYPE;$id=!empty($r['id'])?absint($r['id']):0;
            if(!$id&&!empty($r['name'])){$found=get_page_by_title(sanitize_text_field($r['name']),OBJECT,$pt);if($found)$id=$found->ID;}
            $new=!$id;$id=wp_insert_post(array('ID'=>$id,'post_type'=>$pt,'post_title'=>sanitize_text_field($r['name']??($id?get_the_title($id):'Untitled Supplier')),'post_status'=>'publish'),true);if(is_wp_error($id))return$id;
            foreach(self::production_meta_map('suppliers')as$f=>$m){if(array_key_exists($f,$r)&&($r[$f]!==''||$overwrite))update_post_meta($id,$m,sanitize_textarea_field($r[$f]));}
            return$new?'created':'updated';
        }

        if('recipe_ingredients'===$dataset){
            $recipe_id=self::resolve_recipe($r['recipe_id']??0,$r['recipe_key']??($r['recipe_name']??''));
            if(!$recipe_id)return new WP_Error('missing_recipe','Recipe not found: '.sanitize_text_field($r['recipe_name']??$r['recipe_key']??''));

            $source_type='recipe'===sanitize_key($r['source_type']??'ingredient')?'recipe':'ingredient';
            $source_name=sanitize_text_field($r['ingredient_name']??'');
            $source_id=0;
            $created_ingredient=false;

            if('recipe'===$source_type){
                $source_id=self::resolve_recipe($r['ingredient_id']??0,$source_name);
                if(!$source_id)return new WP_Error('missing_subrecipe','Prepared component recipe not found: '.$source_name);
                if($source_id===$recipe_id)return new WP_Error('circular_subrecipe','A recipe cannot include itself as a prepared component');
            }else{
                $source_id=self::resolve_ingredient($r['ingredient_id']??0,$source_name);
                if(!$source_id && $source_name!==''){
                    $source_id=wp_insert_post(array(
                        'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,
                        'post_title'=>$source_name,
                        'post_status'=>'publish'
                    ),true);
                    if(is_wp_error($source_id))return $source_id;
                    $created_ingredient=true;
                    update_post_meta($source_id,'_persiano_ingredient_type','purchased');
                    update_post_meta($source_id,'_persiano_pricing_needed',1);
                    update_post_meta($source_id,'_persiano_import_created',current_time('mysql'));
                    $preferred=sanitize_key($r['unit']??'');
                    if($preferred!=='')update_post_meta($source_id,'_persiano_preferred_unit',$preferred);
                }
                if(!$source_id)return new WP_Error('missing_ingredient','Ingredient name or valid ingredient ID is required');
                if(!empty($r['ingredient_type']))update_post_meta($source_id,'_persiano_ingredient_type',sanitize_key($r['ingredient_type']));
                if(array_key_exists('include_on_label',$r))update_post_meta($source_id,'_persiano_include_on_label',self::truthy($r['include_on_label'])?1:0);
            }

            $items=get_post_meta($recipe_id,Persiano_Hub_Costing::RECIPE_ITEMS,true);$items=is_array($items)?$items:array();
            $raw_unit=sanitize_key($r['unit']??('recipe'===$source_type?'each':'g'));
            $unit=in_array($raw_unit,array('batch','serving'),true)?$raw_unit:Persiano_Hub_Costing::canonical_recipe_unit($raw_unit);
            $line=array(
                'source_type'=>$source_type,
                'source_id'=>$source_id,
                'ingredient_id'=>'ingredient'===$source_type?$source_id:0,
                'qty'=>wc_format_decimal($r['quantity']??0),
                'unit'=>$unit,
                'prep_note'=>sanitize_text_field($r['preparation_note']??''),
                'rounding'=>sanitize_key($r['rounding']??'exact'),
                'optional'=>self::truthy($r['optional']??0)?1:0
            );
            $pos=max(1,absint($r['line_number']??(count($items)+1)))-1;
            $items[$pos]=$line;ksort($items);
            update_post_meta($recipe_id,Persiano_Hub_Costing::RECIPE_ITEMS,array_values($items));
            if(method_exists('Persiano_Hub_Costing','recalculate_recipe'))Persiano_Hub_Costing::recalculate_recipe($recipe_id);
            return $created_ingredient?'created':'updated';
        }

        if('recipe_steps'===$dataset){
            $recipe_id=self::resolve_recipe($r['recipe_id']??0,$r['recipe_key']??($r['recipe_name']??''));if(!$recipe_id)return new WP_Error('missing_recipe','Recipe not found');
            $steps=get_post_meta($recipe_id,'_persiano_recipe_steps',true);$steps=is_array($steps)?$steps:array();$pos=max(1,absint($r['step_number']??(count($steps)+1)))-1;
            $time_type=sanitize_key($r['time_type']??'active');if(!in_array($time_type,array('active','passive'),true))$time_type='active';
            $steps[$pos]=array('title'=>sanitize_text_field($r['step_title']??''),'instruction'=>sanitize_textarea_field($r['instruction']??''),'minutes'=>absint($r['duration_minutes']??0),'time_type'=>$time_type,'temperature'=>sanitize_text_field($r['temperature']??''),'equipment'=>sanitize_text_field($r['equipment']??''),'critical'=>self::truthy($r['critical_step']??0)?1:0,'internal_note'=>sanitize_textarea_field($r['internal_note']??''),'media_id'=>absint($r['media_id']??0));
            ksort($steps);update_post_meta($recipe_id,'_persiano_recipe_steps',array_values($steps));return'updated';
        }

        if(in_array($dataset,array('recipe_notes','batch_logs'),true)){
            $recipe_id=self::resolve_recipe($r['recipe_id']??0,$r['recipe_key']??($r['recipe_name']??''));if(!$recipe_id)return new WP_Error('missing_recipe','Recipe not found');
            $content='batch_logs'===$dataset?sanitize_textarea_field(($r['result']??'').("\n".($r['quality_notes']??'')).("\nNext batch: ".($r['next_batch_note']??''))):sanitize_textarea_field($r['comment']??'');
            $cid=wp_insert_comment(array('comment_post_ID'=>$recipe_id,'comment_content'=>$content,'comment_type'=>'persiano_recipe_note','comment_approved'=>1,'comment_author_email'=>sanitize_email($r['author_email']??wp_get_current_user()->user_email),'comment_author'=>wp_get_current_user()->display_name));
            if(!$cid)return new WP_Error('comment','Could not save note');
            update_comment_meta($cid,'_ph_note_type','batch_logs'===$dataset?'production_batch':sanitize_key($r['note_type']??'general'));
            foreach(array('batch_date'=>'_ph_batch_date','batch_quantity'=>'_ph_batch_quantity','waste_value'=>'_ph_waste_value','production_cost'=>'_ph_production_cost','pinned'=>'_ph_pinned','changes_made'=>'_ph_changes_made')as$f=>$m){if(isset($r[$f]))update_comment_meta($cid,$m,sanitize_text_field($r[$f]));}
            return'created';
        }

        if('ingredient_aliases'===$dataset){
            if(!class_exists('Persiano_Hub_Ingredient_Master')) return new WP_Error('ingredient_master','Ingredient Master unavailable');
            $resolved=self::resolve_import_ingredient($r); if(is_wp_error($resolved))return $resolved;
            $id=absint($resolved['id']??0);
            $aliases=Persiano_Hub_Ingredient_Master::aliases($id); $alias=sanitize_text_field($r['alias']??''); if($alias)$aliases[]=$alias; Persiano_Hub_Ingredient_Master::set_aliases($id,$aliases);
            if(isset($r['family'])&&($r['family']!==''||$overwrite))update_post_meta($id,Persiano_Hub_Ingredient_Master::META_FAMILY,sanitize_text_field($r['family']));
            if(isset($r['needs_review']))update_post_meta($id,Persiano_Hub_Ingredient_Master::META_REVIEW,self::truthy($r['needs_review'])?1:0);
            return 'updated';
        }
        if('ingredient_price_history'===$dataset){
            if(!class_exists('Persiano_Hub_Ingredient_Master')) return new WP_Error('ingredient_master','Ingredient Master unavailable');
            $resolved=self::resolve_import_ingredient($r); if(is_wp_error($resolved))return $resolved;
            $id=absint($resolved['id']??0);
            $history=get_post_meta($id,Persiano_Hub_Costing::ING_HISTORY,true); $history=is_array($history)?$history:array();
            $date=sanitize_text_field($r['purchase_date']??''); $time=self::parse_purchase_date($date); if(!$time)return new WP_Error('purchase_date','Invalid purchase date: '.$date);
            $entry=array('record_id'=>(sanitize_text_field($r['record_id']??'')?:wp_generate_uuid4()),'time'=>$time,'supplier'=>sanitize_text_field($r['supplier_name']??''),'supplier_id'=>absint($r['supplier_id']??0),'supplier_item_code'=>sanitize_text_field($r['supplier_item_code']??''),'purchase_qty'=>(float)($r['package_quantity']??0),'purchase_unit'=>sanitize_text_field($r['package_unit']??''),'net_cost'=>(float)($r['price_before_tax']??0),'tax'=>(float)($r['tax_amount']??0),'gross_cost'=>(float)($r['total_paid']??0),'unit_cost'=>(float)($r['normalized_unit_cost']??0),'base_unit'=>sanitize_text_field($r['normalized_unit']??''),'sale_price'=>self::truthy($r['sale_price']??0)?1:0,'source_type'=>sanitize_key($r['source_type']??'import'),'invoice_reference'=>sanitize_text_field($r['invoice_reference']??''),'notes'=>sanitize_textarea_field($r['notes']??''),'approved'=>isset($r['approved'])?(self::truthy($r['approved'])?1:0):1,'import_batch_id'=>sanitize_text_field($r['import_batch_id']??self::$current_import_batch),'ingredient_match_method'=>sanitize_key($resolved['method']??''),'ingredient_match_confidence'=>absint($resolved['confidence']??0));
            $entry=Persiano_Hub_Ingredient_Master::normalize_history_entry($entry);
            $key=md5($entry['supplier'].'|'.$entry['supplier_item_code'].'|'.$entry['time'].'|'.$entry['purchase_qty'].'|'.$entry['purchase_unit'].'|'.$entry['gross_cost']); $exists=false; foreach($history as$h){$hk=md5(($h['supplier']??$h['vendor']??'').'|'.($h['supplier_item_code']??'').'|'.(int)($h['time']??0).'|'.(float)($h['purchase_qty']??0).'|'.($h['purchase_unit']??'').'|'.(float)($h['gross_cost']??0));if($hk===$key){$exists=true;break;}}
            if($exists){Persiano_Hub_Ingredient_Master::repair_and_apply_current_cost($id);return 'updated';} $history[]=$entry; update_post_meta($id,Persiano_Hub_Costing::ING_HISTORY,$history); Persiano_Hub_Ingredient_Master::repair_and_apply_current_cost($id); Persiano_Hub_Ingredient_Master::backfill_supplier_packages($id); if(!self::$defer_recipe_recalc && method_exists('Persiano_Hub_Costing','recalculate_recipe')){foreach(get_posts(array('post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true))as$recipe_id){Persiano_Hub_Costing::recalculate_recipe($recipe_id);}} return 'created';
        }
        if('supplier_items'===$dataset){
            if(!class_exists('Persiano_Hub_Ingredient_Master')) return new WP_Error('ingredient_master','Ingredient Master unavailable');
            $resolved=self::resolve_import_ingredient($r); if(is_wp_error($resolved))return $resolved; $id=absint($resolved['id']??0);
            $items=self::supplier_item_rows(get_post_meta($id,'_persiano_supplier_items',true));$rid=sanitize_text_field($r['record_id']??'');$code=sanitize_text_field($r['supplier_item_code']??'');$match=-1;foreach($items as$i=>$item){if(($rid&&($item['record_id']??'')===$rid)||($code&&($item['supplier_item_code']??'')===$code&&sanitize_text_field($item['supplier_name']??'')===sanitize_text_field($r['supplier_name']??''))){$match=$i;break;}}
            $entry=array('record_id'=>$rid?:wp_generate_uuid4(),'supplier_id'=>absint($r['supplier_id']??0),'supplier_name'=>sanitize_text_field($r['supplier_name']??''),'supplier_item_code'=>$code,'brand'=>sanitize_text_field($r['brand']??''),'item_name'=>sanitize_text_field($r['item_name']??''),'package_quantity'=>(float)($r['package_quantity']??0),'package_unit'=>sanitize_text_field($r['package_unit']??''),'current_price'=>(float)($r['current_price']??0),'tax_amount'=>(float)($r['tax_amount']??0),'normalized_unit_cost'=>(float)($r['normalized_unit_cost']??0),'normalized_unit'=>sanitize_text_field($r['normalized_unit']??''),'product_url'=>esc_url_raw($r['product_url']??''),'active'=>isset($r['active'])?(self::truthy($r['active'])?1:0):1,'notes'=>sanitize_textarea_field($r['notes']??''),'import_batch_id'=>sanitize_text_field($r['import_batch_id']??self::$current_import_batch),'ingredient_match_method'=>sanitize_key($resolved['method']??''),'ingredient_match_confidence'=>absint($resolved['confidence']??0)); if($match>=0){$items[$match]=array_merge($items[$match],$entry);$result='updated';}else{$items[]=$entry;$result='created';}update_post_meta($id,'_persiano_supplier_items',$items); Persiano_Hub_Ingredient_Master::backfill_supplier_packages($id); return$result;
        }
        if('subscribers'===$dataset){global$wpdb;$table=$wpdb->prefix.Persiano_Hub_Newsletter::TABLE_SUFFIX;$email=sanitize_email($r['email']??'');if(!$email)return new WP_Error('email','Email required');$existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE email=%s",$email));$data=array('email'=>$email,'name'=>sanitize_text_field($r['name']??''),'status'=>sanitize_key($r['status']??'active'),'source'=>sanitize_text_field($r['source']??'import'),'tags'=>sanitize_text_field($r['tags']??''));if($existing){$wpdb->update($table,$data,array('id'=>$existing));return'updated';}$wpdb->insert($table,$data);return'created';}
        if('customers'===$dataset){$email=sanitize_email($r['email']??'');if(!$email)return new WP_Error('email','Email required');$u=get_user_by('email',$email);$new=!$u;if(!$u){$uid=wc_create_new_customer($email,'',wp_generate_password());if(is_wp_error($uid))return$uid;$u=get_user_by('id',$uid);}foreach(array('first_name'=>'billing_first_name','last_name'=>'billing_last_name','phone'=>'billing_phone','address_1'=>'billing_address_1','address_2'=>'billing_address_2','city'=>'billing_city','state'=>'billing_state','postcode'=>'billing_postcode','country'=>'billing_country')as$f=>$m){if(isset($r[$f])&&($r[$f]!==''||$overwrite))update_user_meta($u->ID,$m,sanitize_text_field($r[$f]));}return$new?'created':'updated';}
        if('orders'===$dataset){$o=wc_get_order(absint($r['id']??0));if(!$o)return new WP_Error('order','Existing order ID required');if(isset($r['status']))$o->update_status(sanitize_key($r['status']));if(isset($r['customer_note']))$o->set_customer_note(sanitize_textarea_field($r['customer_note']));if(isset($r['fulfilment']))$o->update_meta_data('_persiano_fulfilment_method',sanitize_key($r['fulfilment']));if(isset($r['fulfilment_date']))$o->update_meta_data('_persiano_fulfilment_date',sanitize_text_field($r['fulfilment_date']));$o->save();if(!empty($r['internal_note']))$o->add_order_note(sanitize_textarea_field($r['internal_note']),false,true);return'updated';}
        if('loss_waste'===$dataset){$ledger=get_option(Persiano_Hub_Loss_Waste::LEDGER_OPTION,array());$id=sanitize_key($r['id']??wp_generate_uuid4());$new=!isset($ledger[$id]);$ledger[$id]=array_merge($ledger[$id]??array(),array_map('sanitize_text_field',$r),array('id'=>$id));update_option(Persiano_Hub_Loss_Waste::LEDGER_OPTION,$ledger,false);return$new?'created':'updated';}
        return new WP_Error('unsupported','Unsupported table');
    }

    private static function resolve_import_ingredient( $record ) {
        $id = absint( $record['ingredient_id'] ?? 0 );
        if ( $id && Persiano_Hub_Costing::INGREDIENT_POST_TYPE === get_post_type( $id ) && ! get_post_meta( $id, Persiano_Hub_Ingredient_Master::META_MERGED_TO, true ) ) {
            return array( 'id' => $id, 'confidence' => 100, 'method' => 'ingredient_id' );
        }
        $name = sanitize_text_field( $record['ingredient_name'] ?? '' );
        $canonical_id = sanitize_text_field( $record['canonical_id'] ?? '' );
        $resolved = Persiano_Hub_Ingredient_Master::resolve( $name, $canonical_id );
        $exact_methods = array( 'canonical_id', 'canonical_name', 'alias' );
        if ( ! empty( $resolved['id'] ) && in_array( $resolved['method'] ?? '', $exact_methods, true ) ) {
            return $resolved;
        }
        $message = sprintf( 'No exact ingredient match for "%s".', $name ?: $canonical_id );
        if ( ! empty( $resolved['id'] ) ) {
            $message .= sprintf( ' Closest suggestion: "%s" (%d%%). Add an alias, or import with the correct Ingredient ID or Canonical ID.', get_the_title( absint( $resolved['id'] ) ), absint( $resolved['confidence'] ?? 0 ) );
        } else {
            $message .= ' Add the canonical ingredient first, or import with a valid Ingredient ID or Canonical ID.';
        }
        return new WP_Error( 'ingredient_match', $message );
    }

    private static function parse_purchase_date( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) { return time(); }
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
            if ( $date instanceof DateTimeImmutable ) { return $date->setTime( 12, 0, 0 )->getTimestamp(); }
        }
        try {
            $date = new DateTimeImmutable( $value, $timezone );
            return $date->getTimestamp();
        } catch ( Exception $e ) {
            return 0;
        }
    }

    private static function supplier_item_rows( $stored ) {
        if ( ! is_array( $stored ) || ! $stored ) { return array(); }
        $keys = array_keys( $stored );
        $is_list = $keys === range( 0, count( $stored ) - 1 );
        if ( ! $is_list ) { $stored = array( $stored ); }
        return array_values( array_filter( $stored, 'is_array' ) );
    }

    private static function truthy($v){return in_array(strtolower(trim((string)$v)),array('1','yes','true','on','y','published','in_stock'),true);}
    private static function normalize_post_status($v,$fallback='draft'){$v=strtolower(trim((string)$v));if(in_array($v,array('1','yes','true','published','publish'),true))return'publish';if(in_array($v,array('0','no','false','draft'),true))return'draft';return in_array(sanitize_key($v),array('publish','draft','private','pending'),true)?sanitize_key($v):$fallback;}
    private static function normalize_stock_status($v){$v=strtolower(trim((string)$v));return in_array($v,array('1','yes','true','in_stock','instock'),true)?'instock':(in_array($v,array('onbackorder','backorder'),true)?'onbackorder':'outofstock');}
    private static function normalize_tax_status($v){$v=strtolower(trim((string)$v));if(false!==strpos($v,'taxable'))return'taxable';if(false!==strpos($v,'shipping'))return'shipping';return'none';}
    private static function resolve_recipe($id_or_key,$name=''){$id=absint($id_or_key);if($id&&Persiano_Hub_Costing::RECIPE_POST_TYPE===get_post_type($id))return$id;$key=sanitize_title((string)$id_or_key);if($key){$found=get_posts(array('post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_status'=>'any','meta_key'=>'_persiano_recipe_key','meta_value'=>$key,'fields'=>'ids','posts_per_page'=>1));if($found)return(int)$found[0];}$title=sanitize_text_field($name?:$id_or_key);if($title){$p=get_page_by_title($title,OBJECT,Persiano_Hub_Costing::RECIPE_POST_TYPE);if($p)return(int)$p->ID;}return 0;}
    private static function resolve_ingredient($id,$name=''){
        $id=absint($id);
        if($id&&Persiano_Hub_Costing::INGREDIENT_POST_TYPE===get_post_type($id))return$id;
        $name=sanitize_text_field($name);
        if($name==='')return 0;
        $p=get_page_by_title($name,OBJECT,Persiano_Hub_Costing::INGREDIENT_POST_TYPE);
        if($p)return(int)$p->ID;
        $needle=self::normalize($name);
        $posts=get_posts(array('post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true));
        foreach($posts as$post_id){if(self::normalize(get_the_title($post_id))===$needle)return(int)$post_id;}
        return 0;
    }

    private static function production_meta_map($dataset){
        if('suppliers'===$dataset)return array('contact_person'=>'_ph_contact_person','phone'=>'_ph_phone','mobile'=>'_ph_mobile','email'=>'_ph_email','website'=>'_ph_website','address'=>'_ph_address','categories'=>'_ph_categories','account_number'=>'_ph_account_number','delivery_days'=>'_ph_delivery_days','minimum_order'=>'_ph_minimum_order','payment_terms'=>'_ph_payment_terms','preferred_contact'=>'_ph_preferred_contact','role'=>'_ph_supplier_role','active'=>'_ph_active','notes'=>'_ph_notes');
        return array();
    }

    public static function export(){if(!current_user_can('manage_woocommerce'))wp_die('Forbidden',403);check_admin_referer('ph_universal_export');$dataset=sanitize_key($_REQUEST['dataset']??'');$format=sanitize_key($_REQUEST['format']??'csv');if('recipe_workbook'===$dataset){self::output_recipe_workbook('persiano-complete-recipes-'.gmdate('Y-m-d-His'));exit;}$headers=array_keys(self::schemas()[$dataset]??array());$rows=('template'===$format)?array():self::export_rows($dataset);if(is_wp_error($rows))wp_die(esc_html($rows->get_error_message()));$filename=('template'===$format?'persiano-'.$dataset.'-template':'persiano-'.$dataset.'-'.gmdate('Y-m-d-His'));nocache_headers();if('json'===$format){header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'.json"');echo wp_json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}if('xlsx'===$format){self::output_xlsx($filename,$headers,$rows);exit;}$delimiter='tsv'===$format?"\t":',';header('Content-Type: '.('tsv'===$format?'text/tab-separated-values':'text/csv').'; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'.'.('tsv'===$format?'tsv':'csv').'"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');fputcsv($out,$headers,$delimiter);foreach($rows as $r){$line=array();foreach($headers as $h)$line[]=$r[$h]??'';fputcsv($out,$line,$delimiter);}fclose($out);exit;}

    private static function export_rows($dataset){
        if('ingredient_aliases'===$dataset){$rows=array();foreach(get_posts(array('post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true))as$id){if(get_post_meta($id,Persiano_Hub_Ingredient_Master::META_MERGED_TO,true))continue;$aliases=Persiano_Hub_Ingredient_Master::aliases($id);if(!$aliases)$aliases=array('');foreach($aliases as$alias)$rows[]=array('canonical_id'=>Persiano_Hub_Ingredient_Master::canonical_id($id),'ingredient_id'=>$id,'ingredient_name'=>get_the_title($id),'alias'=>$alias,'family'=>get_post_meta($id,Persiano_Hub_Ingredient_Master::META_FAMILY,true),'needs_review'=>get_post_meta($id,Persiano_Hub_Ingredient_Master::META_REVIEW,true)?1:0);}return$rows;}
        if('ingredient_price_history'===$dataset){$rows=array();foreach(get_posts(array('post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true))as$id){foreach((array)get_post_meta($id,Persiano_Hub_Costing::ING_HISTORY,true)as$h)$rows[]=array('record_id'=>$h['record_id']??'','canonical_id'=>Persiano_Hub_Ingredient_Master::canonical_id($id),'ingredient_id'=>$id,'ingredient_name'=>get_the_title($id),'supplier_id'=>$h['supplier_id']??'','supplier_name'=>$h['supplier']??$h['vendor']??'','supplier_item_code'=>$h['supplier_item_code']??'','purchase_date'=>!empty($h['time'])?wp_date('Y-m-d',(int)$h['time']):'','package_quantity'=>$h['purchase_qty']??'','package_unit'=>$h['purchase_unit']??'','price_before_tax'=>$h['net_cost']??'','tax_amount'=>$h['tax']??'','total_paid'=>$h['gross_cost']??'','normalized_unit_cost'=>$h['unit_cost']??'','normalized_unit'=>$h['base_unit']??'','sale_price'=>$h['sale_price']??0,'source_type'=>$h['source_type']??'','invoice_reference'=>$h['invoice_reference']??'','notes'=>$h['notes']??'','approved'=>isset($h['approved'])?$h['approved']:1,'import_batch_id'=>$h['import_batch_id']??'');}return$rows;}
        if('supplier_items'===$dataset){$rows=array();foreach(get_posts(array('post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true))as$id){foreach(self::supplier_item_rows(get_post_meta($id,'_persiano_supplier_items',true))as$item)$rows[]=array_merge(array('canonical_id'=>Persiano_Hub_Ingredient_Master::canonical_id($id),'ingredient_id'=>$id,'ingredient_name'=>get_the_title($id)),$item);}return$rows;}
        $rows=array();
        if('products'===$dataset){
            foreach(wc_get_products(array('limit'=>-1,'status'=>array('publish','draft','private'))) as$p){
                $rows[]=array(
                    'id'=>$p->get_id(),'sku'=>$p->get_sku(),'type'=>$p->get_type(),'name'=>$p->get_name(),'slug'=>$p->get_slug(),'published'=>'publish'===$p->get_status()?1:0,
                    'featured'=>$p->get_featured()?1:0,'catalog_visibility'=>$p->get_catalog_visibility(),'status'=>$p->get_status(),'regular_price'=>$p->get_regular_price(),'sale_price'=>$p->get_sale_price(),
                    'manage_stock'=>$p->get_manage_stock()?1:0,'stock_status'=>$p->get_stock_status(),'stock_quantity'=>$p->get_stock_quantity(),
                    'categories'=>implode('; ',wp_get_post_terms($p->get_id(),'product_cat',array('fields'=>'names'))),'brands'=>'','tags'=>implode('; ',wp_get_post_terms($p->get_id(),'product_tag',array('fields'=>'names'))),
                    'weight_kg'=>$p->get_weight(),'short_description'=>$p->get_short_description(),'description'=>$p->get_description(),
                    'persian_name'=>get_post_meta($p->get_id(),'_persiano_fa_title',true),'fa_short_description'=>get_post_meta($p->get_id(),'_persiano_fa_short_description',true),'fa_description'=>get_post_meta($p->get_id(),'_persiano_fa_description',true),
                    'product_format'=>get_post_meta($p->get_id(),'_persiano_product_format',true),'serving_size'=>get_post_meta($p->get_id(),'_persiano_serving_size',true),'serving_suggestions'=>get_post_meta($p->get_id(),'_persiano_serving_suggestions',true),
                    'includes'=>get_post_meta($p->get_id(),'_persiano_includes',true),'main_ingredients'=>get_post_meta($p->get_id(),'_persiano_main_ingredients',true),'dietary_information'=>get_post_meta($p->get_id(),'_persiano_dietary_information',true),
                    'allergen_information'=>get_post_meta($p->get_id(),'_persiano_allergen_information',true),'storage_instructions'=>get_post_meta($p->get_id(),'_persiano_storage_instructions',true),'reheating_instructions'=>get_post_meta($p->get_id(),'_persiano_reheating_instructions',true),
                    'label_notes'=>get_post_meta($p->get_id(),'_persiano_label_notes',true),'internal_notes'=>get_post_meta($p->get_id(),'_persiano_internal_notes',true),
                    'availability'=>get_post_meta($p->get_id(),'_persiano_availability',true),'available_date'=>get_post_meta($p->get_id(),'_persiano_available_date',true),'order_deadline'=>get_post_meta($p->get_id(),'_persiano_order_deadline',true),
                    'advance_notice_hours'=>get_post_meta($p->get_id(),'_persiano_advance_notice_hours',true),'minimum_quantity'=>get_post_meta($p->get_id(),'_persiano_minimum_quantity',true),'seasonal_return_date'=>get_post_meta($p->get_id(),'_persiano_seasonal_return_date',true),
                    'unavailable_message'=>get_post_meta($p->get_id(),'_persiano_unavailable_message',true),'show_in_this_week'=>get_post_meta($p->get_id(),'_persiano_show_this_week',true),'show_in_pantry'=>get_post_meta($p->get_id(),'_persiano_show_pantry',true),
                    'recipe_reference'=>get_post_meta($p->get_id(),Persiano_Hub_Costing::PRODUCT_RECIPE_META,true),'seo_title'=>get_post_meta($p->get_id(),'_persiano_seo_title',true),'meta_description'=>get_post_meta($p->get_id(),'_persiano_meta_description',true)
                );
            }
            return$rows;
        }
        if('recipes'===$dataset){
            foreach(get_posts(array('post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1))as$p){
                $product_id=absint(get_post_meta($p->ID,Persiano_Hub_Costing::RECIPE_PRODUCT_ID,true));$product=$product_id?wc_get_product($product_id):false;
                $summary=Persiano_Hub_Costing::calculate_recipe($p->ID);
                $rows[]=array('id'=>$p->ID,'recipe_key'=>get_post_meta($p->ID,'_persiano_recipe_key',true),'name'=>$p->post_title,'status'=>$p->post_status,'linked_product_id'=>$product_id,'linked_product_sku'=>$product?$product->get_sku():'','yield_qty'=>$summary['yield_qty'],'yield_unit'=>$summary['yield_unit'],'portions'=>get_post_meta($p->ID,'_persiano_recipe_portions',true),'prep_minutes'=>get_post_meta($p->ID,'_persiano_recipe_prep_minutes',true),'cook_minutes'=>get_post_meta($p->ID,'_persiano_recipe_cook_minutes',true),'packaging_cost'=>get_post_meta($p->ID,Persiano_Hub_Costing::RECIPE_PACKAGING_COST,true),'labour_minutes'=>get_post_meta($p->ID,Persiano_Hub_Costing::RECIPE_LABOUR_MINUTES,true),'labour_rate'=>get_post_meta($p->ID,Persiano_Hub_Costing::RECIPE_LABOUR_RATE,true),'other_cost'=>get_post_meta($p->ID,Persiano_Hub_Costing::RECIPE_OTHER_COST,true),'overhead_pct'=>get_post_meta($p->ID,Persiano_Hub_Costing::RECIPE_OVERHEAD_PCT,true),'target_food_cost_pct'=>get_post_meta($p->ID,Persiano_Hub_Costing::RECIPE_TARGET_COST_PCT,true),'normalized_base_unit'=>$summary['base_unit'],'cost_per_base_unit'=>$summary['cost_per_base_unit'],'pricing_basis'=>$summary['pricing_label'],'product_package_cost'=>$summary['product_cost'],'suggested_price_tax_included'=>$summary['suggested_price'],'storage'=>get_post_meta($p->ID,'_persiano_recipe_storage',true),'reheating'=>get_post_meta($p->ID,'_persiano_recipe_reheating',true),'packaging'=>get_post_meta($p->ID,'_persiano_recipe_packaging',true),'pinned_note'=>get_post_meta($p->ID,'_persiano_recipe_pinned_note',true),'troubleshooting'=>get_post_meta($p->ID,'_persiano_recipe_troubleshooting',true));
            }
            return$rows;
        }
        if('ingredients'===$dataset){
            foreach(get_posts(array('post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1))as$p){
                $rows[]=array('id'=>$p->ID,'name'=>$p->post_title,'status'=>$p->post_status,'type'=>get_post_meta($p->ID,'_persiano_ingredient_type',true),'category'=>get_post_meta($p->ID,Persiano_Hub_Costing::ING_CATEGORY,true),'brand'=>get_post_meta($p->ID,Persiano_Hub_Costing::ING_BRAND,true),'preferred_unit'=>get_post_meta($p->ID,'_persiano_preferred_unit',true),'purchase_quantity'=>get_post_meta($p->ID,Persiano_Hub_Costing::ING_PURCHASE_QTY,true),'purchase_unit'=>get_post_meta($p->ID,Persiano_Hub_Costing::ING_PURCHASE_UNIT,true),'purchase_cost'=>get_post_meta($p->ID,Persiano_Hub_Costing::ING_PURCHASE_COST,true),'purchase_tax'=>get_post_meta($p->ID,Persiano_Hub_Costing::ING_PURCHASE_TAX,true),'waste_percentage'=>get_post_meta($p->ID,Persiano_Hub_Costing::ING_WASTE_PCT,true),'grams_per_cup'=>get_post_meta($p->ID,'_persiano_grams_per_cup',true),'grams_per_tbsp'=>get_post_meta($p->ID,'_persiano_grams_per_tbsp',true),'grams_per_tsp'=>get_post_meta($p->ID,'_persiano_grams_per_tsp',true),'density_g_ml'=>get_post_meta($p->ID,'_persiano_density_g_ml',true),'include_on_label'=>get_post_meta($p->ID,'_persiano_include_on_label',true),'primary_supplier'=>get_post_meta($p->ID,'_persiano_primary_supplier',true),'backup_supplier'=>get_post_meta($p->ID,'_persiano_backup_supplier',true),'notes'=>get_post_meta($p->ID,Persiano_Hub_Costing::ING_NOTES,true));
            }
            return$rows;
        }
        if('suppliers'===$dataset){foreach(get_posts(array('post_type'=>Persiano_Hub_Production_Suite::SUPPLIER_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1))as$p){$r=array('id'=>$p->ID,'name'=>$p->post_title,'status'=>$p->post_status);foreach(self::production_meta_map('suppliers')as$f=>$m)$r[$f]=get_post_meta($p->ID,$m,true);$rows[]=$r;}return$rows;}
        if('recipe_ingredients'===$dataset){foreach(get_posts(array('post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_status'=>'any','posts_per_page'=>-1))as$recipe){foreach((array)get_post_meta($recipe->ID,Persiano_Hub_Costing::RECIPE_ITEMS,true)as$i=>$line){$source_type='recipe'===($line['source_type']??'')?'recipe':'ingredient';$source_id=absint($line['source_id']??0);if(!$source_id&&'ingredient'===$source_type)$source_id=absint($line['ingredient_id']??0);$rows[]=array('recipe_key'=>get_post_meta($recipe->ID,'_persiano_recipe_key',true),'recipe_id'=>$recipe->ID,'recipe_name'=>$recipe->post_title,'line_number'=>$i+1,'source_type'=>$source_type,'ingredient_id'=>$source_id,'ingredient_name'=>$source_id?get_the_title($source_id):'','quantity'=>$line['qty']??'','unit'=>$line['unit']??'','ingredient_type'=>'ingredient'===$source_type&&$source_id?get_post_meta($source_id,'_persiano_ingredient_type',true):'prepared_component','preparation_note'=>$line['prep_note']??'','include_on_label'=>'ingredient'===$source_type&&$source_id?get_post_meta($source_id,'_persiano_include_on_label',true):0,'rounding'=>$line['rounding']??'exact');}}return$rows;}
        if('recipe_steps'===$dataset){foreach(get_posts(array('post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_status'=>'any','posts_per_page'=>-1))as$recipe){foreach((array)get_post_meta($recipe->ID,'_persiano_recipe_steps',true)as$i=>$step)$rows[]=array('recipe_key'=>get_post_meta($recipe->ID,'_persiano_recipe_key',true),'recipe_id'=>$recipe->ID,'recipe_name'=>$recipe->post_title,'step_number'=>($i+1),'step_title'=>$step['title']??'','instruction'=>$step['instruction']??'','duration_minutes'=>$step['minutes']??'','temperature'=>$step['temperature']??'','equipment'=>$step['equipment']??'','critical_step'=>$step['critical']??0,'internal_note'=>$step['internal_note']??'');}return$rows;}
        if(in_array($dataset,array('recipe_notes','batch_logs'),true)){foreach(get_comments(array('type'=>'persiano_recipe_note','status'=>'approve','number'=>0))as$note){$type=get_comment_meta($note->comment_ID,'_ph_note_type',true);if('batch_logs'===$dataset&&'production_batch'!==$type)continue;if('recipe_notes'===$dataset&&'production_batch'===$type)continue;$rows[]=array('id'=>$note->comment_ID,'recipe_key'=>get_post_meta($note->comment_post_ID,'_persiano_recipe_key',true),'recipe_id'=>$note->comment_post_ID,'recipe_name'=>get_the_title($note->comment_post_ID),'note_type'=>$type,'batch_date'=>get_comment_meta($note->comment_ID,'_ph_batch_date',true),'batch_quantity'=>get_comment_meta($note->comment_ID,'_ph_batch_quantity',true),'comment'=>$note->comment_content,'waste_value'=>get_comment_meta($note->comment_ID,'_ph_waste_value',true),'production_cost'=>get_comment_meta($note->comment_ID,'_ph_production_cost',true),'pinned'=>get_comment_meta($note->comment_ID,'_ph_pinned',true),'author_email'=>$note->comment_author_email); }return$rows;}
        if('subscribers'===$dataset){global$wpdb;$table=$wpdb->prefix.Persiano_Hub_Newsletter::TABLE_SUFFIX;return array_map('get_object_vars',$wpdb->get_results("SELECT id,email,name,status,source,tags,consent_at FROM $table ORDER BY id"));}
        if('customers'===$dataset){foreach(get_users(array('role__in'=>array('customer','subscriber')))as$u)$rows[]=array('id'=>$u->ID,'email'=>$u->user_email,'first_name'=>get_user_meta($u->ID,'billing_first_name',true),'last_name'=>get_user_meta($u->ID,'billing_last_name',true),'phone'=>get_user_meta($u->ID,'billing_phone',true),'address_1'=>get_user_meta($u->ID,'billing_address_1',true),'address_2'=>get_user_meta($u->ID,'billing_address_2',true),'city'=>get_user_meta($u->ID,'billing_city',true),'state'=>get_user_meta($u->ID,'billing_state',true),'postcode'=>get_user_meta($u->ID,'billing_postcode',true),'country'=>get_user_meta($u->ID,'billing_country',true));return$rows;}
        if('orders'===$dataset){foreach(wc_get_orders(array('limit'=>-1,'type'=>'shop_order'))as$o)$rows[]=array('id'=>$o->get_id(),'status'=>$o->get_status(),'customer_email'=>$o->get_billing_email(),'total'=>$o->get_total(),'payment_method'=>$o->get_payment_method_title(),'fulfilment'=>$o->get_meta('_persiano_fulfilment_method'),'fulfilment_date'=>$o->get_meta('_persiano_fulfilment_date'),'customer_note'=>$o->get_customer_note(),'internal_note'=>'');return$rows;}
        if('loss_waste'===$dataset)return array_values((array)get_option(Persiano_Hub_Loss_Waste::LEDGER_OPTION,array()));
        return new WP_Error('unsupported','Unsupported table');
    }

    private static function parse_recipe_workbook($path,$name){
        if('xlsx'!==strtolower(pathinfo($name,PATHINFO_EXTENSION)))return new WP_Error('format','Complete recipe workbook must be an XLSX file.');
        $book=self::parse_xlsx_sheets($path);if(is_wp_error($book))return$book;
        $aliases=array('recipes'=>'recipes','recipe'=>'recipes','ingredients'=>'recipe_ingredients','recipe ingredients'=>'recipe_ingredients','recipe_ingredients'=>'recipe_ingredients','steps'=>'recipe_steps','recipe steps'=>'recipe_steps','recipe_steps'=>'recipe_steps','notes'=>'recipe_notes','recipe notes'=>'recipe_notes','batch logs'=>'batch_logs','batch_logs'=>'batch_logs','master ingredients'=>'ingredients','master_ingredients'=>'ingredients','suppliers'=>'suppliers');
        $sheets=array();foreach($book as$name=>$data){$key=strtolower(trim($name));if(isset($aliases[$key]))$sheets[$aliases[$key]]=$data;}
        foreach(array('recipes','recipe_ingredients','recipe_steps')as$required){if(empty($sheets[$required]))return new WP_Error('sheets','Workbook must contain Recipes, Ingredients and Steps sheets.');}
        return array('sheets'=>$sheets);
    }

    private static function render_workbook_mapping($token,$sheets){
        echo '<div class="wrap"><h1>Map Complete Recipe Workbook</h1><p>All sheets will write to the single master Costing & Recipes system.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('ph_universal_import');echo '<input type="hidden" name="action" value="ph_universal_import"><input type="hidden" name="token" value="'.esc_attr($token).'">';
        foreach($sheets as$dataset=>$data){$schema=self::schemas()[$dataset]??array();$auto=self::auto_map($dataset,$data['headers']);echo '<h2>'.esc_html(self::datasets()[$dataset]??ucwords(str_replace('_',' ',$dataset))).' <small>('.count($data['rows']).' rows)</small></h2><table class="widefat striped"><thead><tr><th>File column</th><th>Sample</th><th>Map to</th></tr></thead><tbody>';foreach($data['headers']as$i=>$h){echo '<tr><td><strong>'.esc_html($h).'</strong></td><td>'.esc_html(mb_strimwidth((string)($data['rows'][0][$i]??''),0,80,'…')).'</td><td><select name="workbook_map['.esc_attr($dataset).']['.$i.']"><option value="">Do not import</option>';foreach($schema as$field=>$aliases)echo '<option value="'.esc_attr($field).'" '.selected($auto[$i]??'',$field,false).'>'.esc_html(ucwords(str_replace('_',' ',$field))).'</option>';echo '</select></td></tr>';}echo '</tbody></table>';}
        echo '<p><label><input type="checkbox" name="save_mapping" value="1" checked> Save mappings for future recipe workbooks.</label></p><p><label><input type="checkbox" name="confirm" value="1" required> I reviewed every sheet and want to import it.</label></p><p><button class="button button-primary button-hero">Import Complete Workbook</button> <a class="button" href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">Cancel</a></p></form></div>';
    }

    private static function run_workbook_import($data,$post){
        $maps=(array)($post['workbook_map']??array());$created=0;$updated=0;$failed=0;
        $order=array('suppliers','ingredients','recipes','recipe_ingredients','recipe_steps','recipe_notes','batch_logs');
        foreach($order as$dataset){if(empty($data['sheets'][$dataset]))continue;$sheet=$data['sheets'][$dataset];$map=array_map('sanitize_key',(array)($maps[$dataset]??array()));if(!empty($post['save_mapping'])){$saved=array();foreach($sheet['headers']as$i=>$header){if(!empty($map[$i]))$saved[self::normalize($header)]=$map[$i];}update_option('persiano_hub_import_mapping_'.$dataset,$saved,false);}foreach($sheet['rows']as$row){$record=array();foreach($map as$i=>$field){if($field!=='')$record[$field]=$row[(int)$i]??'';}if(!$record)continue;$result=self::save_record($dataset,$record,!empty($data['overwrite']));if(is_wp_error($result))$failed++;elseif('created'===$result)$created++;else$updated++;}}
        $msg=sprintf('Recipe workbook import complete: %d created, %d updated, %d failed.',$created,$updated,$failed);wp_safe_redirect(add_query_arg('ph_notice',$msg,admin_url('admin.php?page='.self::PAGE.'&dataset=recipe_workbook')));exit;
    }

    private static function xlsx_cell_value($cell, $shared){
        $type = (string) $cell['t'];
        if ($type === 'inlineStr') {
            $text = '';
            if (isset($cell->is)) {
                foreach ($cell->is->xpath('.//*[local-name()="t"]') ?: array() as $t) {
                    $text .= (string) $t;
                }
            }
            return $text;
        }
        $value_nodes = $cell->xpath('./*[local-name()="v"]');
        $value = $value_nodes ? (string) $value_nodes[0] : '';
        if ($type === 's') {
            return $shared[(int) $value] ?? '';
        }
        if ($type === 'b') {
            return $value === '1' ? '1' : '0';
        }
        return $value;
    }

    private static function xlsx_sheet_matrix($raw, $shared){
        if ($raw === false || $raw === null || $raw === '') return new WP_Error('xlsx', 'Worksheet data is missing.');
        // Strip UTF-8 BOM and XML-illegal control characters occasionally
        // written by Excel alternatives while preserving tabs/newlines.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        if (!$xml) {
            // DOM is more tolerant of namespace/encoding declarations from
            // Numbers, LibreOffice and some online spreadsheet generators.
            $dom = new DOMDocument();
            $loaded = @$dom->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($loaded) $xml = simplexml_import_dom($dom);
        }
        if (!$xml) return new WP_Error('xlsx', 'Worksheet XML could not be read. Re-save the file as a standard Excel Workbook (.xlsx) or export it as CSV.');
        $rows = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
        if (!$rows) return array();
        $matrix = array();
        foreach ($rows as $row) {
            $line = array();
            $cells = $row->xpath('./*[local-name()="c"]') ?: array();
            foreach ($cells as $cell) {
                $ref = (string) $cell['r'];
                if (!preg_match('/([A-Z]+)(\d+)/', $ref, $m)) continue;
                $col = 0;
                foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col--;
                $line[$col] = self::xlsx_cell_value($cell, $shared);
            }
            if ($line) {
                $max = max(array_keys($line));
                $matrix[] = array_replace(array_fill(0, $max + 1, ''), $line);
            }
        }
        return $matrix;
    }

    private static function parse_xlsx_sheets($path){
        if (!class_exists('ZipArchive')) return new WP_Error('xlsx','XLSX requires the PHP Zip extension.');
        $zip = new ZipArchive();
        if (true !== $zip->open($path)) return new WP_Error('xlsx','Cannot open XLSX file.');
        $shared = array();
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss) {
            $xml = simplexml_load_string($ss);
            if ($xml) foreach ($xml->xpath('//*[local-name()="si"]') ?: array() as $si) {
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') ?: array() as $t) $text .= (string) $t;
                $shared[] = $text;
            }
        }
        $workbook_raw = $zip->getFromName('xl/workbook.xml');
        $rels_raw = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $workbook = $workbook_raw ? simplexml_load_string($workbook_raw) : false;
        $rels = $rels_raw ? simplexml_load_string($rels_raw) : false;

        // Some spreadsheet generators omit or relocate workbook relationship
        // metadata. Fall back to reading worksheet XML files directly instead
        // of rejecting an otherwise valid XLSX archive.
        if (!$workbook || !$rels) {
            $out = array();
            $sheet_files = array();
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $entry)) {
                    $sheet_files[] = $entry;
                }
            }
            sort($sheet_files, SORT_NATURAL);
            foreach ($sheet_files as $index => $entry) {
                $matrix = self::xlsx_sheet_matrix($zip->getFromName($entry), $shared);
                if (is_wp_error($matrix)) { $zip->close(); return $matrix; }
                if (!$matrix) { continue; }
                $headers = array_shift($matrix);
                $out['Sheet ' . ($index + 1)] = array('headers' => $headers, 'rows' => $matrix);
            }
            $zip->close();
            return $out ? $out : new WP_Error('xlsx', 'No readable worksheets were found in this XLSX file.');
        }
        $relmap = array();
        foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: array() as $rel) {
            $relmap[(string) $rel['Id']] = (string) $rel['Target'];
        }
        $out = array();
        foreach ($workbook->xpath('//*[local-name()="sheet"]') ?: array() as $sheet) {
            $rid = '';
            foreach ($sheet->attributes() as $k => $v) if ((string)$k === 'id') $rid = (string)$v;
            if (!$rid) {
                $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rid = (string)($attrs['id'] ?? '');
            }
            $target = $relmap[$rid] ?? '';
            if (!$target) continue;
            // Relationship targets may be relative (worksheets/sheet1.xml),
            // absolute (/xl/worksheets/sheet1.xml), or already xl-prefixed.
            $target = str_replace('\\', '/', trim($target));
            $target = preg_replace('#^/+#', '', $target);
            while (strpos($target, '../') === 0) $target = substr($target, 3);
            $full = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
            $raw_sheet = $zip->getFromName($full);
            if ($raw_sheet === false) {
                // Last-resort lookup by worksheet basename for non-standard
                // relationship paths produced by some applications.
                $base = basename($full);
                for ($zi = 0; $zi < $zip->numFiles; $zi++) {
                    $candidate = $zip->getNameIndex($zi);
                    if (preg_match('#^xl/worksheets/.*' . preg_quote($base, '#') . '$#', $candidate)) {
                        $raw_sheet = $zip->getFromName($candidate);
                        break;
                    }
                }
            }
            $matrix = self::xlsx_sheet_matrix($raw_sheet, $shared);
            if (is_wp_error($matrix)) { $zip->close(); return $matrix; }
            if ($matrix) {
                $headers = array_shift($matrix);
                $out[(string)$sheet['name']] = array('headers'=>$headers,'rows'=>$matrix);
            }
        }
        $zip->close();
        return $out;
    }

    private static function parse_file($path,$name){$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if('json'===$ext){$data=json_decode(file_get_contents($path),true);if(!is_array($data))return new WP_Error('json','Invalid JSON file.');if(isset($data[0])&&is_array($data[0])){$headers=array_keys($data[0]);$rows=array();foreach($data as$r){$line=array();foreach($headers as$h)$line[]=$r[$h]??'';$rows[]=$line;}return array('headers'=>$headers,'rows'=>$rows);}return new WP_Error('json','JSON must contain an array of records.');}if('xlsx'===$ext)return self::parse_xlsx($path);$delimiter='tsv'===$ext||'txt'===$ext?"\t":',';$fh=fopen($path,'r');if(!$fh)return new WP_Error('file','Cannot open file.');$headers=fgetcsv($fh,0,$delimiter);$rows=array();while(($row=fgetcsv($fh,0,$delimiter))!==false){if(count(array_filter($row,'strlen'))) $rows[]=$row;}fclose($fh);return array('headers'=>$headers?:array(),'rows'=>$rows);}

    private static function parse_xlsx($path){
        if (!class_exists('ZipArchive')) return new WP_Error('xlsx','XLSX requires the PHP Zip extension.');
        $zip = new ZipArchive();
        if (true !== $zip->open($path)) return new WP_Error('xlsx','Cannot open XLSX file.');
        $shared = array();
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss) {
            $xml = simplexml_load_string($ss);
            if ($xml) foreach ($xml->xpath('//*[local-name()="si"]') ?: array() as $si) {
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') ?: array() as $t) $text .= (string)$t;
                $shared[] = $text;
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheet) {
            $names = array();
            for ($i=0; $i<$zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) $names[] = $name;
            }
            sort($names, SORT_NATURAL);
            if ($names) $sheet = $zip->getFromName($names[0]);
        }
        $matrix = self::xlsx_sheet_matrix($sheet, $shared);
        $zip->close();
        if (is_wp_error($matrix)) return $matrix;
        if (!$matrix) return new WP_Error('xlsx','Worksheet is empty or could not be read.');
        $headers = array_shift($matrix);
        return array('headers'=>$headers,'rows'=>$matrix);
    }

    private static function output_recipe_workbook($filename){
        if(!class_exists('ZipArchive'))wp_die('XLSX export requires the PHP Zip extension.');
        $datasets=array('recipes'=>'Recipes','recipe_ingredients'=>'Ingredients','recipe_steps'=>'Steps','recipe_notes'=>'Notes','batch_logs'=>'Batch Logs','ingredients'=>'Master Ingredients','suppliers'=>'Suppliers');
        $tmp=wp_tempnam($filename.'.xlsx');$zip=new ZipArchive();$zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE);
        $content='<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $workbook='<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels='<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';$i=0;
        foreach($datasets as$dataset=>$label){$i++;$content.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';$workbook.='<sheet name="'.htmlspecialchars(substr($label,0,31),ENT_XML1|ENT_COMPAT,'UTF-8').'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';$rels.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';$headers=array_keys(self::schemas()[$dataset]??array());$rows=self::export_rows($dataset);$all=array_merge(array($headers),array_map(function($r)use($headers){$x=array();foreach($headers as$h)$x[]=$r[$h]??'';return$x;},is_wp_error($rows)?array():$rows));$xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';foreach($all as$ri=>$row){$xml.='<row r="'.($ri+1).'">';foreach($row as$ci=>$v){$n=$ci+1;$letters='';while($n){$n--; $letters=chr(65+$n%26).$letters;$n=intdiv($n,26);}$xml.='<c r="'.$letters.($ri+1).'" t="inlineStr"><is><t>'.htmlspecialchars((string)$v,ENT_XML1|ENT_COMPAT,'UTF-8').'</t></is></c>';}$xml.='</row>';}$xml.='</sheetData></worksheet>';$zip->addFromString('xl/worksheets/sheet'.$i.'.xml',$xml);}
        $content.='</Types>';$workbook.='</sheets></workbook>';$rels.='</Relationships>';$zip->addFromString('[Content_Types].xml',$content);$zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');$zip->addFromString('xl/workbook.xml',$workbook);$zip->addFromString('xl/_rels/workbook.xml.rels',$rels);$zip->close();nocache_headers();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');readfile($tmp);unlink($tmp);
    }

    private static function output_xlsx($filename,$headers,$rows){if(!class_exists('ZipArchive'))wp_die('XLSX export requires the PHP Zip extension.');$tmp=wp_tempnam($filename.'.xlsx');$zip=new ZipArchive();$zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE);$zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');$zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');$zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.esc_attr(substr($filename,0,31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');$zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');$all=array_merge(array($headers),array_map(function($r)use($headers){$x=array();foreach($headers as$h)$x[]=$r[$h]??'';return$x;},$rows));$xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';foreach($all as$ri=>$row){$xml.='<row r="'.($ri+1).'">';foreach($row as$ci=>$v){$n=$ci+1;$letters='';while($n){$n--; $letters=chr(65+$n%26).$letters;$n=intdiv($n,26);} $xml.='<c r="'.$letters.($ri+1).'" t="inlineStr"><is><t>'.htmlspecialchars((string)$v,ENT_XML1|ENT_COMPAT,'UTF-8').'</t></is></c>';}$xml.='</row>';}$xml.='</sheetData></worksheet>';$zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');readfile($tmp);unlink($tmp);}
}
