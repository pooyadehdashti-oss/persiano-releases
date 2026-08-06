<?php
/**
 * Unified yield, sellable-package and supplier-package catalogue with workflow checks.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Package_Catalogue {
    const PAGE_SLUG = 'persiano-hub-package-catalogue';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 33 );
        add_action( 'admin_post_persiano_hub_export_package_catalogue', array( __CLASS__, 'export_csv' ) );
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Yield & Packaging', 'persiano-hub' ),
            __( 'Yield & Packaging', 'persiano-hub' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ) ); }
    }

    public static function page_url( $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
    }

    private static function product_package( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) { return array( 'label'=>'', 'quantity'=>0, 'unit'=>'', 'source'=>'' ); }
        $size = trim( (string) get_post_meta( $product_id, '_persiano_size', true ) );
        $parsed_size = array( 'label'=>'', 'quantity'=>0, 'unit'=>'', 'source'=>'' );
        if ( $size ) {
            $parsed_size = self::parse_package( $size );
            $parsed_size['label'] = $size;
            $parsed_size['source'] = 'Persiano Size / package';
            if ( ! empty( $parsed_size['quantity'] ) && ! empty( $parsed_size['unit'] ) ) { return $parsed_size; }
        }
        $weight = (float) $product->get_weight();
        if ( $weight > 0 ) {
            $unit = get_option( 'woocommerce_weight_unit', 'kg' );
            return array( 'label'=>trim( $weight . ' ' . $unit ), 'quantity'=>$weight, 'unit'=>sanitize_key($unit), 'source'=>'WooCommerce weight' );
        }
        return $parsed_size;
    }

    private static function parse_package( $text ) {
        $text = strtolower( trim( wp_strip_all_tags( (string) $text ) ) );
        if ( preg_match( '/(\d+(?:[\.,]\d+)?)\s*[x×]\s*(\d+(?:[\.,]\d+)?)\s*(kg|g|ml|l|oz|lb|each|pc|pcs|piece|pieces|serving|servings|portion|portions|meal|meals|container|containers|tray|trays|jar|jars|bottle|bottles|pack|packs)\b/i', $text, $m ) ) {
            return array( 'quantity'=>(float)str_replace(',','.',$m[1])*(float)str_replace(',','.',$m[2]), 'unit'=>self::unit($m[3]) );
        }
        // Prefer a measurable mass or volume when descriptive counts also appear, e.g. “2 portions, 150 ml”.
        if ( preg_match( '/(\d+(?:[\.,]\d+)?)\s*(kg|g|ml|l|oz|lb)\b/i', $text, $m ) ) {
            return array( 'quantity'=>(float)str_replace(',','.',$m[1]), 'unit'=>self::unit($m[2]) );
        }
        if ( preg_match( '/(\d+(?:[\.,]\d+)?)\s*(?:(?:individual|single)\s+)?(each|pc|pcs|piece|pieces|serving|servings|portion|portions|meal|meals|container|containers|tray|trays|jar|jars|bottle|bottles|pack|packs)\b/i', $text, $m ) ) {
            return array( 'quantity'=>(float)str_replace(',','.',$m[1]), 'unit'=>self::unit($m[2]) );
        }
        return array( 'quantity'=>0, 'unit'=>'' );
    }

    private static function unit( $unit ) {
        $unit = strtolower( trim( (string) $unit ) );
        if ( in_array( $unit, array('pc','pcs','piece','pieces','serving','servings','portion','portions','meal','meals','container','containers','tray','trays','jar','jars','bottle','bottles','pack','packs'), true ) ) { return 'each'; }
        return $unit;
    }

    private static function family( $unit ) {
        $unit = self::unit( $unit );
        if ( in_array( $unit, array('g','kg','oz','lb'), true ) ) { return 'mass'; }
        if ( in_array( $unit, array('ml','l'), true ) ) { return 'volume'; }
        if ( 'each' === $unit ) { return 'count'; }
        return '';
    }

    private static function recipe_rows() {
        $rows = array();
        if ( ! class_exists( 'Persiano_Hub_Costing' ) ) { return $rows; }
        $recipes = get_posts( array( 'post_type'=>Persiano_Hub_Costing::RECIPE_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC' ) );
        foreach ( $recipes as $recipe ) {
            $yield_qty = (float) get_post_meta( $recipe->ID, Persiano_Hub_Costing::RECIPE_YIELD_QTY, true );
            $yield_unit = Persiano_Hub_Costing::canonical_recipe_unit( get_post_meta( $recipe->ID, Persiano_Hub_Costing::RECIPE_YIELD_LABEL, true ) );
            $product_id = absint( get_post_meta( $recipe->ID, Persiano_Hub_Costing::RECIPE_PRODUCT_ID, true ) );
            $package = $product_id ? self::product_package( $product_id ) : array('label'=>'','quantity'=>0,'unit'=>'','source'=>'');
            $issues = array();
            if ( $yield_qty <= 0 || ! self::family( $yield_unit ) ) { $issues[] = 'Invalid or missing physical yield'; }
            if ( $product_id && empty( $package['quantity'] ) ) { $issues[] = 'Linked product has no measurable package size'; }
            if ( $product_id && ! empty( $package['unit'] ) && self::family( $yield_unit ) && self::family( $package['unit'] ) !== self::family( $yield_unit ) ) { $issues[] = 'Yield and product package use incompatible unit families'; }
            $rows[] = array(
                'recipe_id'=>$recipe->ID,'recipe_name'=>$recipe->post_title,'yield_qty'=>$yield_qty,'yield_unit'=>$yield_unit,'product_id'=>$product_id,
                'product_name'=>$product_id?get_the_title($product_id):'','package_label'=>$package['label'],'package_qty'=>$package['quantity'],'package_unit'=>$package['unit'],'package_source'=>$package['source'],'issues'=>$issues,
            );
        }
        return $rows;
    }

    private static function supplier_rows() {
        $rows = array();
        if ( ! class_exists( 'Persiano_Hub_Costing' ) ) { return $rows; }
        $ingredients = get_posts( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC' ) );
        foreach ( $ingredients as $ingredient ) {
            $merged_to = absint( get_post_meta( $ingredient->ID, Persiano_Hub_Ingredient_Master::META_MERGED_TO, true ) );
            if ( $merged_to ) { continue; }
            if ( Persiano_Hub_Ingredient_Master::is_non_purchasable_ingredient( $ingredient->ID ) ) { continue; }
            $items = Persiano_Hub_Ingredient_Master::supplier_packages( $ingredient->ID, false );
            $valid_items = Persiano_Hub_Ingredient_Master::supplier_packages( $ingredient->ID, true );
            if ( ! $valid_items ) {
                $issue = Persiano_Hub_Ingredient_Master::supplier_package_issue( $ingredient->ID );
                $rows[] = array( 'ingredient_id'=>$ingredient->ID,'ingredient_name'=>$ingredient->post_title,'supplier'=>'','brand'=>'','item_name'=>'','package_qty'=>0,'package_unit'=>'','price'=>0,'url'=>'','active'=>0,'issues'=>array($issue) );
                continue;
            }
            $items = $valid_items;
            foreach ( $items as $item ) {
                $issues = array();
                $qty=(float)($item['package_quantity']??0); $unit=self::unit($item['package_unit']??''); $price=(float)($item['current_price']??0);
                if($qty<=0||!self::family($unit))$issues[]='Incomplete package quantity or unit';
                if($price<=0)$issues[]='No current package price';
                if(empty($item['supplier_name']))$issues[]='Supplier missing';
                $rows[] = array(
                    'ingredient_id'=>$ingredient->ID,'ingredient_name'=>$ingredient->post_title,'supplier'=>$item['supplier_name']??'','brand'=>$item['brand']??'','item_name'=>$item['item_name']??'',
                    'package_qty'=>$qty,'package_unit'=>$unit,'price'=>$price,'url'=>$item['product_url']??'','active'=>isset($item['active'])?(int)$item['active']:1,'issues'=>$issues,
                );
            }
        }
        return $rows;
    }

    private static function health_checks( $recipe_rows, $supplier_rows ) {
        $checks = array();
        foreach ( $recipe_rows as $row ) {
            foreach ( $row['issues'] as $issue ) { $checks[] = array( 'area'=>'Recipe / product','record'=>$row['recipe_name'],'issue'=>$issue,'url'=>get_edit_post_link($row['recipe_id']) ); }
        }
        foreach ( $supplier_rows as $row ) {
            foreach ( $row['issues'] as $issue ) { $checks[] = array( 'area'=>'Ingredient / supplier','record'=>$row['ingredient_name'],'issue'=>$issue,'url'=>get_edit_post_link($row['ingredient_id']) ); }
        }
        if ( class_exists( 'Persiano_Hub_Operations' ) ) {
            $lists = get_posts( array('post_type'=>Persiano_Hub_Operations::LIST_POST_TYPE,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids') );
            foreach($lists as$id){$status=get_post_meta($id,Persiano_Hub_Operations::META_STATUS,true);if('active'!==$status)continue;$source=get_post_meta($id,Persiano_Hub_Operations::META_LIST_SOURCE,true);if(is_array($source)&&empty($source['plan_id']))$checks[]=array('area'=>'Shopping list','record'=>get_the_title($id),'issue'=>'Active list bypasses a saved production plan (plan_id is 0)','url'=>get_edit_post_link($id));}
        }
        if ( class_exists( 'Persiano_Hub_Price_Feeds' ) ) {
            $ids=get_posts(array('post_type'=>Persiano_Hub_Price_Feeds::POST_TYPE,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids'));
            foreach($ids as$id){$status=get_post_meta($id,Persiano_Hub_Price_Feeds::META_STATUS,true);if(in_array($status,array('needs_attention','needs_review'),true))$checks[]=array('area'=>'Price feed','record'=>get_the_title($id),'issue'=>'needs_attention'===$status?'URL is inaccessible or repeatedly failing':'Product or package requires review','url'=>Persiano_Hub_Price_Feeds::page_url(array('source'=>$id)));}
        }
        if ( class_exists( 'Persiano_Hub_AI_Cost_Import' ) && defined( 'Persiano_Hub_AI_Cost_Import::JOB_POST_TYPE' ) ) {
            $jobs=get_posts(array('post_type'=>Persiano_Hub_AI_Cost_Import::JOB_POST_TYPE,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids'));
            foreach($jobs as$id){$status=get_post_meta($id,'_persiano_ai_job_status',true);$updated=(int)get_post_meta($id,'_persiano_ai_job_updated',true);if('failed'===$status||('processing'===$status&&$updated&&time()-$updated>HOUR_IN_SECONDS))$checks[]=array('area'=>'AI Scan','record'=>get_the_title($id),'issue'=>'failed'===$status?'Scan job failed':'Scan job appears stuck','url'=>admin_url('admin.php?page='.Persiano_Hub_Costing::MENU_SLUG.'&tab=scan'));}
        }
        return $checks;
    }

    public static function export_csv() {
        self::require_permission(); check_admin_referer('persiano_hub_export_package_catalogue');
        nocache_headers();header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename="persiano-yield-packaging-'.gmdate('Y-m-d-His').'.csv"');
        $out=fopen('php://output','w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        fputcsv($out,array('record_type','record_id','record_name','yield_or_package_quantity','unit','linked_record','supplier','price','source','issues'));
        foreach(self::recipe_rows()as$r)fputcsv($out,array('recipe',$r['recipe_id'],$r['recipe_name'],$r['yield_qty'],$r['yield_unit'],$r['product_name'],'','',$r['package_label'],implode('; ',$r['issues'])));
        foreach(self::supplier_rows()as$r)fputcsv($out,array('supplier_package',$r['ingredient_id'],$r['ingredient_name'],$r['package_qty'],$r['package_unit'],$r['item_name'],$r['supplier'],$r['price'],$r['url'],implode('; ',$r['issues'])));
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    public static function render_page() {
        self::require_permission();
        $recipes=self::recipe_rows();$suppliers=self::supplier_rows();$checks=self::health_checks($recipes,$suppliers);$tab=sanitize_key(wp_unslash($_GET['view']??'recipes')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $export=wp_nonce_url(add_query_arg('action','persiano_hub_export_package_catalogue',admin_url('admin-post.php')),'persiano_hub_export_package_catalogue');
        ?>
        <div class="wrap ph-package-wrap"><div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e('Master data control','persiano-hub'); ?></span><h1><?php esc_html_e('Yield & Packaging','persiano-hub'); ?></h1><p><?php esc_html_e('Review recipe outputs, customer package sizes and supplier purchase packages in one place. This catalogue does not duplicate the records; it shows the connected master data and flags gaps.','persiano-hub'); ?></p></div><div class="ph-costing-actions"><a class="button" href="<?php echo esc_url($export); ?>">Export CSV</a></div></div>
        <div class="ph-package-stats"><div><strong><?php echo count($recipes); ?></strong><span>Recipe yields</span></div><div><strong><?php echo count($suppliers); ?></strong><span>Supplier package rows</span></div><div class="<?php echo $checks?'has-issues':''; ?>"><strong><?php echo count($checks); ?></strong><span>Items needing attention</span></div></div>
        <nav class="nav-tab-wrapper"><a class="nav-tab <?php echo 'recipes'===$tab?'nav-tab-active':''; ?>" href="<?php echo esc_url(self::page_url(array('view'=>'recipes'))); ?>">Recipe & product packages</a><a class="nav-tab <?php echo 'suppliers'===$tab?'nav-tab-active':''; ?>" href="<?php echo esc_url(self::page_url(array('view'=>'suppliers'))); ?>">Supplier packages</a><a class="nav-tab <?php echo 'health'===$tab?'nav-tab-active':''; ?>" href="<?php echo esc_url(self::page_url(array('view'=>'health'))); ?>">Workflow health <?php if($checks): ?><span class="update-plugins count-<?php echo count($checks); ?>"><span class="plugin-count"><?php echo count($checks); ?></span></span><?php endif; ?></a></nav>
        <?php if('suppliers'===$tab): ?>
        <section class="ph-costing-panel"><h2>Ingredient supplier packages</h2><p>Each row is a package the shopping comparison is allowed to buy. A product URL may be maintained by Price Feeds.</p><div class="ph-package-scroll"><table class="widefat striped"><thead><tr><th>Ingredient</th><th>Supplier item</th><th>Package</th><th>Price</th><th>URL</th><th>Status</th></tr></thead><tbody><?php foreach($suppliers as$r): ?><tr><td><a href="<?php echo esc_url(get_edit_post_link($r['ingredient_id'])); ?>"><?php echo esc_html($r['ingredient_name']); ?></a></td><td><?php echo esc_html(trim($r['supplier'].' · '.$r['brand'].' · '.$r['item_name'],' ·')); ?></td><td><?php echo $r['package_qty']>0?esc_html(number_format_i18n($r['package_qty'],4).' '.$r['package_unit']):'—'; ?></td><td><?php echo $r['price']>0?wp_kses_post(wc_price($r['price'])):'—'; ?></td><td><?php echo $r['url']?'<a href="'.esc_url($r['url']).'" target="_blank" rel="noopener">Open ↗</a>':'—'; ?></td><td><?php echo $r['issues']?'<span class="ph-health-bad">'.esc_html(implode('; ',$r['issues'])).'</span>':'<span class="ph-health-good">Ready</span>'; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php elseif('health'===$tab): ?>
        <?php $repair_log = get_option( Persiano_Hub_Ingredient_Master::OPTION_PACKAGE_REPAIR_LOG, array() ); if ( is_array($repair_log) && $repair_log ) : ?>
        <section class="ph-costing-panel"><h2>Latest supplier-package repair</h2><p><strong>Version <?php echo esc_html($repair_log['version']??''); ?></strong> · <?php echo !empty($repair_log['completed'])?esc_html(wp_date('M j, Y g:i a',(int)$repair_log['completed'])):''; ?></p><div class="ph-package-stats"><div><strong><?php echo (int)($repair_log['packages_created']??0); ?></strong><span>Packages created</span></div><div><strong><?php echo (int)($repair_log['merged_transferred']??0); ?></strong><span>Merged packages transferred</span></div><div><strong><?php echo (int)($repair_log['identity_skipped']??0)+(int)($repair_log['insufficient_data']??0); ?></strong><span>Require review or more data</span></div></div></section>
        <?php endif; ?>
        <section class="ph-costing-panel"><h2>Workflow health</h2><p>These checks catch master-data problems before they produce unrealistic costing, production or shopping quantities.</p><table class="widefat striped"><thead><tr><th>Area</th><th>Record</th><th>Issue</th><th></th></tr></thead><tbody><?php if(!$checks): ?><tr><td colspan="4"><span class="ph-health-good">No detected workflow issues.</span></td></tr><?php else:foreach($checks as$c): ?><tr><td><?php echo esc_html($c['area']); ?></td><td><?php echo esc_html($c['record']); ?></td><td><span class="ph-health-bad"><?php echo esc_html($c['issue']); ?></span></td><td><?php if($c['url']): ?><a class="button button-small" href="<?php echo esc_url($c['url']); ?>">Review</a><?php endif; ?></td></tr><?php endforeach;endif; ?></tbody></table></section>
        <?php else: ?>
        <section class="ph-costing-panel"><h2>Recipe yields and customer packages</h2><p>A recipe records the full physical output. The linked WooCommerce product records what one customer-facing unit contains.</p><div class="ph-package-scroll"><table class="widefat striped"><thead><tr><th>Recipe</th><th>Full batch yield</th><th>Linked product</th><th>Sellable package</th><th>Package source</th><th>Status</th></tr></thead><tbody><?php foreach($recipes as$r): ?><tr><td><a href="<?php echo esc_url(get_edit_post_link($r['recipe_id'])); ?>"><?php echo esc_html($r['recipe_name']); ?></a></td><td><?php echo esc_html(number_format_i18n($r['yield_qty'],4).' '.$r['yield_unit']); ?></td><td><?php echo $r['product_id']?'<a href="'.esc_url(get_edit_post_link($r['product_id'])).'">'.esc_html($r['product_name']).'</a>':'Internal component / no product'; ?></td><td><?php echo esc_html($r['package_label']?:'—'); ?></td><td><?php echo esc_html($r['package_source']?:'—'); ?></td><td><?php echo $r['issues']?'<span class="ph-health-bad">'.esc_html(implode('; ',$r['issues'])).'</span>':'<span class="ph-health-good">Ready</span>'; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
        <?php endif; ?></div>
        <style>.ph-package-wrap{max-width:1320px}.ph-package-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}.ph-package-stats>div{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px}.ph-package-stats strong{display:block;font-size:28px;color:#8e2435}.ph-package-stats span{color:#646970}.ph-package-stats .has-issues{border-color:#d63638;background:#fff7f7}.ph-package-scroll{overflow:auto}.ph-package-scroll table{min-width:1000px}.ph-health-good{color:#135e27;font-weight:700}.ph-health-bad{color:#b32d2e;font-weight:600}@media(max-width:782px){.ph-package-stats{grid-template-columns:1fr}}</style>
        <?php
    }
}
