<?php
/**
 * AI-assisted ingredient cost and price-observation intake.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_AI_Cost_Import {
    const OPTION_API_KEY = 'persiano_hub_openai_api_key';
    const OPTION_MODEL   = 'persiano_hub_openai_cost_model';
    const TRANSIENT_KEY  = 'persiano_hub_ai_cost_scan_';
    const JOB_POST_TYPE  = 'persiano_ai_scan_job';
    const PROCESS_JOB_HOOK = 'persiano_hub_process_ai_scan_job';
    const SWEEP_HOOK = 'persiano_hub_ai_scan_queue_sweep';
    const META_JOB_STATUS = '_persiano_ai_job_status';
    const META_JOB_MODE = '_persiano_ai_job_mode';
    const META_JOB_ATTACHMENT = '_persiano_ai_job_attachment';
    const META_JOB_RESULT = '_persiano_ai_job_result';
    const META_JOB_PARTIAL = '_persiano_ai_job_partial';
    const META_JOB_NEXT_PAGE = '_persiano_ai_job_next_page';
    const META_JOB_PAGE_COUNT = '_persiano_ai_job_page_count';
    const META_JOB_ATTEMPTS = '_persiano_ai_job_attempts';
    const META_JOB_ERROR = '_persiano_ai_job_error';
    const META_JOB_UPDATED = '_persiano_ai_job_updated';
    const META_JOB_USER = '_persiano_ai_job_user';
    const META_JOB_RETRY_AFTER = '_persiano_ai_job_retry_after';
    const META_JOB_SCHEDULED_FOR = '_persiano_ai_job_scheduled_for';
    const META_JOB_LOCK = '_persiano_ai_job_lock';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_job_post_type' ), 12 );
        add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 46 );
        add_action( 'admin_post_persiano_hub_save_ai_cost_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_persiano_hub_scan_cost_image', array( __CLASS__, 'scan_image' ) );
        add_action( 'admin_post_persiano_hub_queue_cost_scans', array( __CLASS__, 'queue_scans' ) );
        add_action( 'admin_post_persiano_hub_retry_ai_scan_job', array( __CLASS__, 'retry_job' ) );
        add_action( 'admin_post_persiano_hub_run_ai_scan_job_now', array( __CLASS__, 'run_job_now' ) );
        add_action( 'admin_post_persiano_hub_delete_ai_scan_job', array( __CLASS__, 'delete_job' ) );
        add_action( 'admin_post_persiano_hub_import_scanned_costs', array( __CLASS__, 'import_scanned_costs' ) );
        add_action( self::PROCESS_JOB_HOOK, array( __CLASS__, 'process_job' ), 10, 1 );
        add_action( self::SWEEP_HOOK, array( __CLASS__, 'scheduled_sweep' ) );
    }

    public static function get_api_key() {
        if ( defined( 'PERSIANO_OPENAI_API_KEY' ) && PERSIANO_OPENAI_API_KEY ) {
            return trim( (string) PERSIANO_OPENAI_API_KEY );
        }
        return trim( (string) get_option( self::OPTION_API_KEY, '' ) );
    }

    public static function get_model() {
        $model = trim( (string) get_option( self::OPTION_MODEL, 'gpt-5-mini' ) );
        return $model ? $model : 'gpt-5-mini';
    }

    private static function require_permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to use Persiano Costing.', 'persiano-hub' ) );
        }
    }

    private static function page_url( $tab = 'scan', $args = array() ) {
        return add_query_arg(
            array_merge(
                array(
                    'page' => Persiano_Hub_Costing::MENU_SLUG,
                    'tab'  => $tab,
                ),
                $args
            ),
            admin_url( 'admin.php' )
        );
    }

    public static function save_settings() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_save_ai_cost_settings' );

        if ( ! empty( $_POST['persiano_hub_clear_openai_key'] ) ) {
            delete_option( self::OPTION_API_KEY );
        } elseif ( isset( $_POST['persiano_hub_openai_api_key'] ) ) {
            $key = trim( sanitize_text_field( wp_unslash( $_POST['persiano_hub_openai_api_key'] ) ) );
            if ( $key ) {
                update_option( self::OPTION_API_KEY, $key, false );
            }
        }

        if ( isset( $_POST['persiano_hub_openai_cost_model'] ) ) {
            $model = preg_replace( '/[^a-zA-Z0-9._-]/', '', wp_unslash( $_POST['persiano_hub_openai_cost_model'] ) );
            update_option( self::OPTION_MODEL, $model ? $model : 'gpt-5-mini', false );
        }

        wp_safe_redirect( self::page_url( 'settings', array( 'saved' => '1' ) ) );
        exit;
    }

    private static function scan_mode( $value ) {
        return 'observation' === sanitize_key( $value ) ? 'observation' : 'purchase';
    }

    public static function register_job_post_type() {
        if ( post_type_exists( self::JOB_POST_TYPE ) ) { return; }
        register_post_type(
            self::JOB_POST_TYPE,
            array(
                'labels' => array( 'name' => __( 'AI Scan Jobs', 'persiano-hub' ), 'singular_name' => __( 'AI Scan Job', 'persiano-hub' ) ),
                'public' => false,
                'show_ui' => false,
                'show_in_menu' => false,
                'supports' => array( 'title' ),
                'capability_type' => 'post',
                'map_meta_cap' => true,
            )
        );
    }

    public static function ensure_schedule() {
        if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) { wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'hourly', self::SWEEP_HOOK ); }
    }

    public static function scheduled_sweep() {
        $ids = get_posts( array(
            'post_type' => self::JOB_POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 100, 'fields' => 'ids',
            'meta_query' => array( array( 'key' => self::META_JOB_STATUS, 'value' => array( 'queued','retrying','processing' ), 'compare' => 'IN' ) ),
            'orderby' => 'modified', 'order' => 'ASC', 'no_found_rows' => true,
        ) );
        $offset = 0;
        foreach ( $ids as $job_id ) {
            $status = (string) get_post_meta( $job_id, self::META_JOB_STATUS, true );
            $updated = (int) get_post_meta( $job_id, self::META_JOB_UPDATED, true );
            $retry_after = (int) get_post_meta( $job_id, self::META_JOB_RETRY_AFTER, true );
            $due = ( 'queued' === $status && ( ! $updated || time() - $updated > 10 * MINUTE_IN_SECONDS ) )
                || ( 'retrying' === $status && ( ! $retry_after || $retry_after <= time() ) )
                || ( 'processing' === $status && $updated && time() - $updated > 30 * MINUTE_IN_SECONDS );
            if ( ! $due ) { continue; }
            if ( 'processing' === $status ) { update_post_meta( $job_id, self::META_JOB_STATUS, 'queued' ); }
            self::schedule_job( $job_id, 2 + ( $offset * 4 ) );
            $offset++;
        }
    }

    private static function schedule_job( $job_id, $delay = 1 ) {
        $job_id = absint( $job_id );
        if ( ! $job_id ) { return false; }

        $delay        = max( 1, (int) $delay );
        $scheduled_at = time() + $delay;
        $existing     = (int) get_post_meta( $job_id, self::META_JOB_SCHEDULED_FOR, true );
        $args         = array( $job_id );

        // A stored timestamp does not prove that the background action still exists.
        // Verify the actual queue so a missed cron/action cannot strand a job at 0 pages.
        if ( $existing && $existing >= time() - MINUTE_IN_SECONDS ) {
            $really_scheduled = false;
            if ( function_exists( 'as_has_scheduled_action' ) ) {
                $really_scheduled = (bool) as_has_scheduled_action( self::PROCESS_JOB_HOOK, $args, 'persiano-hub' );
            } elseif ( wp_next_scheduled( self::PROCESS_JOB_HOOK, $args ) ) {
                $really_scheduled = true;
            }
            if ( $really_scheduled ) { return true; }
            delete_post_meta( $job_id, self::META_JOB_SCHEDULED_FOR );
        }

        $scheduled = false;
        if ( function_exists( 'as_enqueue_async_action' ) && $delay <= 5 ) {
            $action_id = as_enqueue_async_action( self::PROCESS_JOB_HOOK, $args, 'persiano-hub', false );
            $scheduled = ! empty( $action_id );
        } elseif ( function_exists( 'as_schedule_single_action' ) ) {
            $action_id = as_schedule_single_action( $scheduled_at, self::PROCESS_JOB_HOOK, $args, 'persiano-hub', false );
            $scheduled = ! empty( $action_id );
        } elseif ( ! wp_next_scheduled( self::PROCESS_JOB_HOOK, $args ) ) {
            $scheduled = (bool) wp_schedule_single_event( $scheduled_at, self::PROCESS_JOB_HOOK, $args );
        } else {
            $scheduled = true;
        }

        if ( $scheduled ) { update_post_meta( $job_id, self::META_JOB_SCHEDULED_FOR, $scheduled_at ); }
        return $scheduled;
    }

    private static function acquire_job_lock( $job_id ) {
        $now      = time();
        $existing = (int) get_post_meta( $job_id, self::META_JOB_LOCK, true );
        if ( $existing && $now - $existing < 5 * MINUTE_IN_SECONDS ) {
            return false;
        }
        if ( $existing ) {
            delete_post_meta( $job_id, self::META_JOB_LOCK );
        }
        return (bool) add_post_meta( $job_id, self::META_JOB_LOCK, $now, true );
    }

    private static function release_job_lock( $job_id ) {
        delete_post_meta( $job_id, self::META_JOB_LOCK );
    }

    private static function uploaded_file_rows() {
        $files = array();
        if ( ! empty( $_FILES['persiano_cost_scan_files']['name'] ) ) {
            $raw = $_FILES['persiano_cost_scan_files']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( is_array( $raw['name'] ) ) {
                foreach ( $raw['name'] as $index => $name ) {
                    $files[] = array(
                        'name' => $name,
                        'type' => $raw['type'][ $index ] ?? '',
                        'tmp_name' => $raw['tmp_name'][ $index ] ?? '',
                        'error' => $raw['error'][ $index ] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $raw['size'][ $index ] ?? 0,
                    );
                }
            }
        } elseif ( ! empty( $_FILES['persiano_cost_scan_image']['name'] ) ) {
            $files[] = $_FILES['persiano_cost_scan_image']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }
        return array_slice( $files, 0, 10 );
    }

    public static function queue_scans() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_queue_cost_scans' );
        $mode = self::scan_mode( isset( $_POST['scan_mode'] ) ? wp_unslash( $_POST['scan_mode'] ) : 'purchase' );
        if ( ! self::get_api_key() ) {
            wp_safe_redirect( self::page_url( 'scan', array( 'scan_error' => __( 'Add an OpenAI API key in AI Settings first.', 'persiano-hub' ) ) ) );
            exit;
        }
        $files = self::uploaded_file_rows();
        if ( ! $files ) {
            wp_safe_redirect( self::page_url( 'scan', array( 'scan_error' => __( 'Choose one or more scan files first.', 'persiano-hub' ) ) ) );
            exit;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $queued = 0; $errors = 0;
        foreach ( $files as $index => $file_row ) {
            if ( ! empty( $file_row['error'] ) || empty( $file_row['tmp_name'] ) || (int) ( $file_row['size'] ?? 0 ) > 25 * MB_IN_BYTES ) { $errors++; continue; }
            $attachment_id = media_handle_sideload( $file_row, 0 );
            if ( is_wp_error( $attachment_id ) ) { $errors++; continue; }
            $mime = get_post_mime_type( $attachment_id );
            if ( ! in_array( $mime, array( 'image/jpeg','image/png','image/webp','application/pdf' ), true ) ) {
                wp_delete_attachment( $attachment_id, true );
                $errors++;
                continue;
            }
            $job_id = wp_insert_post( array(
                'post_type' => self::JOB_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => sprintf( '%s — %s', get_the_title( $attachment_id ) ?: basename( get_attached_file( $attachment_id ) ), ucfirst( $mode ) ),
            ), true );
            if ( is_wp_error( $job_id ) ) { $errors++; continue; }
            update_post_meta( $job_id, self::META_JOB_STATUS, 'queued' );
            update_post_meta( $job_id, self::META_JOB_MODE, $mode );
            update_post_meta( $job_id, self::META_JOB_ATTACHMENT, (int) $attachment_id );
            update_post_meta( $job_id, self::META_JOB_NEXT_PAGE, 1 );
            update_post_meta( $job_id, self::META_JOB_ATTEMPTS, 0 );
            update_post_meta( $job_id, self::META_JOB_UPDATED, time() );
            update_post_meta( $job_id, self::META_JOB_USER, get_current_user_id() );
            self::schedule_job( $job_id, 2 + ( $index * 4 ) );
            $queued++;
        }
        wp_safe_redirect( self::page_url( 'scan', array( 'queued_jobs'=>$queued, 'upload_errors'=>$errors ) ) );
        exit;
    }

    /** Keep the old single-file action compatible, but queue it instead of processing in the browser request. */
    public static function scan_image() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_scan_cost_image' );
        $_POST['_wpnonce'] = wp_create_nonce( 'persiano_hub_queue_cost_scans' );
        self::queue_scans();
    }

    private static function merge_scan_results( $merged, $part ) {
        $merged = is_array( $merged ) ? $merged : array();
        $merged = wp_parse_args( $merged, array(
            'document_type'=>'','vendor'=>'','document_date'=>'','reference_number'=>'','valid_until'=>'','currency'=>'CAD','document_tax_total'=>0,'items'=>array(),
        ) );
        foreach ( array( 'document_type','vendor','document_date','reference_number','valid_until','currency' ) as $field ) {
            if ( empty( $merged[ $field ] ) && ! empty( $part[ $field ] ) ) { $merged[ $field ] = $part[ $field ]; }
        }
        $merged['document_tax_total'] = max( (float) $merged['document_tax_total'], (float) ( $part['document_tax_total'] ?? 0 ) );
        $seen = array();
        foreach ( (array) $merged['items'] as $item ) {
            $seen[ md5( strtolower( implode( '|', array( $item['name']??'', $item['brand']??'', $item['purchase_qty']??'', $item['purchase_unit']??'', $item['purchase_cost']??'', $item['purchase_tax']??'' ) ) ) ) ] = true;
        }
        foreach ( (array) ( $part['items'] ?? array() ) as $item ) {
            $sig = md5( strtolower( implode( '|', array( $item['name']??'', $item['brand']??'', $item['purchase_qty']??'', $item['purchase_unit']??'', $item['purchase_cost']??'', $item['purchase_tax']??'' ) ) ) );
            if ( isset( $seen[ $sig ] ) ) { continue; }
            $seen[ $sig ] = true; $merged['items'][] = $item;
        }
        return $merged;
    }

    private static function fail_job( $job_id, $error ) {
        $attempts = (int) get_post_meta( $job_id, self::META_JOB_ATTEMPTS, true ) + 1;
        update_post_meta( $job_id, self::META_JOB_ATTEMPTS, $attempts );
        update_post_meta( $job_id, self::META_JOB_ERROR, sanitize_text_field( $error ) );
        update_post_meta( $job_id, self::META_JOB_UPDATED, time() );
        if ( $attempts < 3 ) {
            $delay = (int) pow( 4, max( 1, $attempts ) ) * MINUTE_IN_SECONDS;
            update_post_meta( $job_id, self::META_JOB_STATUS, 'retrying' );
            update_post_meta( $job_id, self::META_JOB_RETRY_AFTER, time() + $delay );
            self::schedule_job( $job_id, $delay );
        } else {
            delete_post_meta( $job_id, self::META_JOB_RETRY_AFTER );
            update_post_meta( $job_id, self::META_JOB_STATUS, 'failed' );
            if ( class_exists( 'Persiano_Hub_Notifications' ) && method_exists( 'Persiano_Hub_Notifications', 'add_system_notification' ) ) {
                Persiano_Hub_Notifications::add_system_notification( 'ai_scan_failed', 'AI Scan job failed: ' . get_the_title( $job_id ), $error, self::page_url( 'scan' ) );
            }
        }
    }

    public static function process_job( $job_id ) {
        $job_id = absint( $job_id );
        if ( ! $job_id || self::JOB_POST_TYPE !== get_post_type( $job_id ) ) { return; }
        if ( ! self::acquire_job_lock( $job_id ) ) { return; }
        delete_post_meta( $job_id, self::META_JOB_SCHEDULED_FOR );

        try {
        $status = (string) get_post_meta( $job_id, self::META_JOB_STATUS, true );
        if ( in_array( $status, array( 'complete','imported','cancelled' ), true ) ) { return; }
        if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'admin' ); }
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 180 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        delete_post_meta( $job_id, self::META_JOB_RETRY_AFTER );
        $attachment_id = absint( get_post_meta( $job_id, self::META_JOB_ATTACHMENT, true ) );
        $file = $attachment_id ? get_attached_file( $attachment_id ) : '';
        $mime = $attachment_id ? get_post_mime_type( $attachment_id ) : '';
        if ( ! $file || ! is_readable( $file ) || ! in_array( $mime, array( 'image/jpeg','image/png','image/webp','application/pdf' ), true ) ) {
            self::fail_job( $job_id, __( 'The uploaded scan file is missing or unreadable.', 'persiano-hub' ) );
            return;
        }
        $bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $bytes || strlen( $bytes ) > 25 * MB_IN_BYTES ) {
            self::fail_job( $job_id, __( 'The scan file could not be read or is larger than 25 MB.', 'persiano-hub' ) );
            return;
        }
        update_post_meta( $job_id, self::META_JOB_STATUS, 'processing' );
        update_post_meta( $job_id, self::META_JOB_UPDATED, time() );
        $mode = self::scan_mode( get_post_meta( $job_id, self::META_JOB_MODE, true ) );
        if ( 'application/pdf' !== $mime ) {
            $input = array( 'type'=>'input_image','image_url'=>'data:'.$mime.';base64,'.base64_encode($bytes) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            $result = self::call_openai( $input, $mode );
            if ( is_wp_error( $result ) ) { self::fail_job( $job_id, $result->get_error_message() ); return; }
            update_post_meta( $job_id, self::META_JOB_ATTEMPTS, 0 );
            $result['scan_mode']=$mode;$result['attachment_id']=$attachment_id;$result['scanned_at']=time();
            update_post_meta( $job_id, self::META_JOB_RESULT, $result );
            update_post_meta( $job_id, self::META_JOB_STATUS, 'complete' );
            update_post_meta( $job_id, self::META_JOB_UPDATED, time() );
        } else {
            $page_count = (int) get_post_meta( $job_id, self::META_JOB_PAGE_COUNT, true );
            if ( $page_count < 1 ) { $page_count=self::estimate_pdf_pages($file,$bytes);update_post_meta($job_id,self::META_JOB_PAGE_COUNT,$page_count); }
            $start = max( 1, (int) get_post_meta( $job_id, self::META_JOB_NEXT_PAGE, true ) );
            $chunk_size = $page_count <= 6 ? 2 : 4;
            $end = min( $page_count, $start + $chunk_size - 1 );
            $instruction = $start===$end ? sprintf('Process page %d only. Ignore items on all other pages so this pass does not duplicate results.',$start) : sprintf('Process pages %d through %d inclusive. Inspect every page in this range and ignore items outside this range.',$start,$end);
            $input=array('type'=>'input_file','filename'=>basename($file),'file_data'=>'data:application/pdf;base64,'.base64_encode($bytes)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            $part=self::call_openai($input,$mode,$instruction);
            if(is_wp_error($part)){self::fail_job($job_id,$part->get_error_message());return;}
            update_post_meta( $job_id, self::META_JOB_ATTEMPTS, 0 );
            $merged=self::merge_scan_results(get_post_meta($job_id,self::META_JOB_PARTIAL,true),$part);
            update_post_meta($job_id,self::META_JOB_PARTIAL,$merged);
            update_post_meta($job_id,self::META_JOB_NEXT_PAGE,$end+1);
            update_post_meta($job_id,self::META_JOB_UPDATED,time());
            if($end >= $page_count){
                if(empty($merged['items'])){self::fail_job($job_id,__('No ingredient or packaging price items were found in the PDF.','persiano-hub'));return;}
                $merged['scan_mode']=$mode;$merged['attachment_id']=$attachment_id;$merged['scanned_at']=time();
                update_post_meta($job_id,self::META_JOB_RESULT,$merged);delete_post_meta($job_id,self::META_JOB_PARTIAL);
                update_post_meta($job_id,self::META_JOB_STATUS,'complete');
            }else{
                update_post_meta($job_id,self::META_JOB_STATUS,'queued');
                self::schedule_job($job_id,4);
                return;
            }
        }
        if(class_exists('Persiano_Hub_Notifications')&&method_exists('Persiano_Hub_Notifications','add_system_notification')){
            Persiano_Hub_Notifications::add_system_notification('ai_scan_complete','AI Scan ready for review: '.get_the_title($job_id),__('The background scan finished successfully. Review the extracted rows before importing them.','persiano-hub'),self::page_url('scan',array('job'=>$job_id)));
        }
        } finally {
            self::release_job_lock( $job_id );
        }
    }

    public static function retry_job() {
        self::require_permission(); $job_id=absint($_GET['job']??0); check_admin_referer('persiano_hub_retry_ai_scan_job_'.$job_id);
        if($job_id&&self::JOB_POST_TYPE===get_post_type($job_id)){update_post_meta($job_id,self::META_JOB_STATUS,'queued');delete_post_meta($job_id,self::META_JOB_ERROR);delete_post_meta($job_id,self::META_JOB_RETRY_AFTER);delete_post_meta($job_id,self::META_JOB_SCHEDULED_FOR);delete_post_meta($job_id,self::META_JOB_LOCK);update_post_meta($job_id,self::META_JOB_ATTEMPTS,0);update_post_meta($job_id,self::META_JOB_UPDATED,time());self::schedule_job($job_id,1);}
        wp_safe_redirect(self::page_url('scan'));exit;
    }

    public static function run_job_now() {
        self::require_permission();
        $job_id = absint( $_GET['job'] ?? 0 );
        check_admin_referer( 'persiano_hub_run_ai_scan_job_now_' . $job_id );
        if ( $job_id && self::JOB_POST_TYPE === get_post_type( $job_id ) ) {
            delete_post_meta( $job_id, self::META_JOB_LOCK );
            delete_post_meta( $job_id, self::META_JOB_SCHEDULED_FOR );
            delete_post_meta( $job_id, self::META_JOB_RETRY_AFTER );
            update_post_meta( $job_id, self::META_JOB_STATUS, 'queued' );
            update_post_meta( $job_id, self::META_JOB_UPDATED, time() );
            self::process_job( $job_id );
        }
        wp_safe_redirect( self::page_url( 'scan', array( 'ran_job' => $job_id ) ) );
        exit;
    }

    public static function delete_job() {
        self::require_permission(); $job_id=absint($_GET['job']??0); check_admin_referer('persiano_hub_delete_ai_scan_job_'.$job_id);
        if($job_id&&self::JOB_POST_TYPE===get_post_type($job_id))wp_delete_post($job_id,true);
        wp_safe_redirect(self::page_url('scan'));exit;
    }

    /**
     * Estimate the number of pages in a PDF so multi-page files can be scanned in focused passes.
     * Imagick is preferred when the host supports PDF metadata; a lightweight PDF object count is
     * used as a fallback for common PDFs.
     */
    private static function estimate_pdf_pages( $file, $bytes ) {
        $count = 0;

        if ( class_exists( 'Imagick' ) ) {
            try {
                $pdf = new Imagick();
                $pdf->pingImage( $file );
                $count = (int) $pdf->getNumberImages();
                $pdf->clear();
                $pdf->destroy();
            } catch ( Exception $e ) {
                $count = 0;
            }
        }

        if ( $count < 1 && is_string( $bytes ) && '' !== $bytes ) {
            $matches = array();
            $count   = preg_match_all( '/\/Type\s*\/Page\b/', $bytes, $matches );
            $count   = false === $count ? 0 : (int) $count;
        }

        return max( 1, min( 200, $count ) );
    }

    private static function schema() {
        return array(
            'type'=>'object', 'additionalProperties'=>false,
            'properties'=>array(
                'document_type'=>array( 'type'=>'string' ),
                'vendor'=>array( 'type'=>'string' ),
                'document_date'=>array( 'type'=>'string' ),
                'reference_number'=>array( 'type'=>'string' ),
                'valid_until'=>array( 'type'=>'string' ),
                'currency'=>array( 'type'=>'string' ),
                'document_tax_total'=>array( 'type'=>'number' ),
                'items'=>array(
                    'type'=>'array',
                    'items'=>array(
                        'type'=>'object', 'additionalProperties'=>false,
                        'properties'=>array(
                            'name'=>array( 'type'=>'string' ),
                            'brand'=>array( 'type'=>'string' ),
                            'category'=>array( 'type'=>'string' ),
                            'purchase_qty'=>array( 'type'=>'number' ),
                            'purchase_unit'=>array( 'type'=>'string', 'enum'=>array_keys( Persiano_Hub_Costing::unit_options() ) ),
                            'purchase_cost'=>array( 'type'=>'number' ),
                            'purchase_tax'=>array( 'type'=>'number' ),
                            'quantity_purchased'=>array( 'type'=>'number' ),
                            'tax_status'=>array( 'type'=>'string' ),
                            'confidence'=>array( 'type'=>'number' ),
                            'notes'=>array( 'type'=>'string' ),
                        ),
                        'required'=>array( 'name','brand','category','purchase_qty','purchase_unit','purchase_cost','purchase_tax','quantity_purchased','tax_status','confidence','notes' ),
                    ),
                ),
            ),
            'required'=>array( 'document_type','vendor','document_date','reference_number','valid_until','currency','document_tax_total','items' ),
        );
    }

    private static function scan_prompt( $mode, $page_instruction = '' ) {
        $shared = <<<'PROMPT'
Extract food-business ingredient or packaging price records from the provided file.
Normalize units to: g, kg, oz, lb, ml, l, tsp, tbsp, cup, each.
For package quantity, purchase_qty means the total physical amount represented by the listed line/package price. For two 500 g packs sold together for $10, use purchase_qty=1 and purchase_unit=kg.
Use purchase_cost for the price before separately shown tax and purchase_tax for tax attributable to that specific item only. Do not spread a receipt-level tax total across products when allocation is uncertain.
Return only ingredients or packaging useful to a food business. Ignore payment methods, loyalty lines, gift cards and unrelated merchandise.
Do not invent missing values. Use 0 or an empty string and explain uncertainty in notes. Confidence must be between 0 and 1.
For PDF documents, inspect every page that the page instruction asks you to process. Do not stop after the first page. Collect every qualifying line item from the requested page or page range before producing the final JSON.
PROMPT;
        if ( $page_instruction ) {
            $shared .= "\n\nPAGE INSTRUCTION: " . $page_instruction;
        }
        if ( 'observation' === $mode ) {
            return $shared . "\nThis is PRICE RESEARCH, not proof of purchase. Treat shelf tags, flyers, catalogues, screenshots and supplier price lists as observed prices. quantity_purchased must be 0. Capture sale price as purchase_cost when clearly active; otherwise use the normal listed price. Put tax status such as tax included, tax extra, exempt or unknown in tax_status. Capture a visible offer expiry in valid_until. Do not imply inventory was purchased.";
        }
        return $shared . "\nThis is an ACTUAL PURCHASE workflow for receipts and invoices. Extract vendor, transaction date and invoice/receipt number when visible. quantity_purchased should be the physical quantity actually bought, expressed in purchase_unit. Preserve discounts in the effective line purchase_cost. The resulting records will update purchase history and may increase inventory after review.";
    }

    private static function call_openai( $file_input, $mode, $page_instruction = '' ) {
        $body = array(
            'model'=>self::get_model(),
            'input'=>array( array( 'role'=>'user', 'content'=>array( array( 'type'=>'input_text','text'=>self::scan_prompt( $mode, $page_instruction ) ), $file_input ) ) ),
            'text'=>array( 'format'=>array( 'type'=>'json_schema','name'=>'persiano_cost_scan','strict'=>true,'schema'=>self::schema() ) ),
            'max_output_tokens'=>12000,
        );
        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            array(
                'timeout'=>120,
                'headers'=>array( 'Authorization'=>'Bearer ' . self::get_api_key(), 'Content-Type'=>'application/json' ),
                'body'=>wp_json_encode( $body ),
            )
        );
        if ( is_wp_error( $response ) ) { return $response; }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 ) {
            $message = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( __( 'OpenAI returned HTTP %d.', 'persiano-hub' ), $status );
            return new WP_Error( 'persiano_ai_cost_api', sanitize_text_field( $message ) );
        }
        $text = '';
        if ( ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
            $text = $data['output_text'];
        } elseif ( ! empty( $data['output'] ) && is_array( $data['output'] ) ) {
            foreach ( $data['output'] as $output_item ) {
                foreach ( (array) ( $output_item['content'] ?? array() ) as $content ) {
                    if ( isset( $content['type'], $content['text'] ) && 'output_text' === $content['type'] ) { $text .= $content['text']; }
                }
            }
        }
        $parsed = $text ? json_decode( $text, true ) : null;
        if ( ! is_array( $parsed ) || ! isset( $parsed['items'] ) || ! is_array( $parsed['items'] ) ) {
            return new WP_Error( 'persiano_ai_cost_parse', __( 'The AI response could not be converted into cost records. Try a clearer file.', 'persiano-hub' ) );
        }
        $clean = array(
            'document_type'=>sanitize_text_field( $parsed['document_type'] ?? '' ),
            'vendor'=>sanitize_text_field( $parsed['vendor'] ?? '' ),
            'document_date'=>sanitize_text_field( $parsed['document_date'] ?? '' ),
            'reference_number'=>sanitize_text_field( $parsed['reference_number'] ?? '' ),
            'valid_until'=>sanitize_text_field( $parsed['valid_until'] ?? '' ),
            'currency'=>sanitize_text_field( $parsed['currency'] ?? 'CAD' ),
            'document_tax_total'=>max( 0, (float) ( $parsed['document_tax_total'] ?? 0 ) ),
            'items'=>array(),
        );
        foreach ( $parsed['items'] as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $name = sanitize_text_field( $item['name'] ?? '' );
            if ( ! $name ) { continue; }
            $unit = sanitize_key( $item['purchase_unit'] ?? 'each' );
            if ( ! array_key_exists( $unit, Persiano_Hub_Costing::unit_options() ) ) { $unit='each'; }
            $clean['items'][] = array(
                'name'=>$name,
                'brand'=>sanitize_text_field( $item['brand'] ?? '' ),
                'category'=>sanitize_text_field( $item['category'] ?? '' ),
                'purchase_qty'=>max( 0, (float) ( $item['purchase_qty'] ?? 0 ) ),
                'purchase_unit'=>$unit,
                'purchase_cost'=>max( 0, (float) ( $item['purchase_cost'] ?? 0 ) ),
                'purchase_tax'=>max( 0, (float) ( $item['purchase_tax'] ?? 0 ) ),
                'quantity_purchased'=>max( 0, (float) ( $item['quantity_purchased'] ?? 0 ) ),
                'tax_status'=>sanitize_text_field( $item['tax_status'] ?? '' ),
                'confidence'=>min( 1, max( 0, (float) ( $item['confidence'] ?? 0 ) ) ),
                'notes'=>sanitize_textarea_field( $item['notes'] ?? '' ),
                'existing_id'=>Persiano_Hub_Costing::find_matching_ingredient( $name ),
            );
        }
        if ( ! $clean['items'] ) { return new WP_Error( 'persiano_ai_cost_empty', __( 'No ingredient or packaging price items were found.', 'persiano-hub' ) ); }
        return $clean;
    }

    private static function ensure_ingredient( $existing_id, $row ) {
        $existing_id = absint( $existing_id );
        if ( $existing_id && Persiano_Hub_Costing::INGREDIENT_POST_TYPE === get_post_type( $existing_id ) ) { return $existing_id; }
        $name = sanitize_text_field( $row['name'] ?? '' );
        if ( ! $name ) { return new WP_Error( 'persiano_scan_name', __( 'Ingredient name is required.', 'persiano-hub' ) ); }
        $id = wp_insert_post( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>'publish','post_title'=>$name ), true );
        if ( is_wp_error( $id ) ) { return $id; }
        update_post_meta( $id, Persiano_Hub_Costing::ING_CATEGORY, sanitize_text_field( $row['category'] ?? '' ) );
        update_post_meta( $id, Persiano_Hub_Costing::ING_BRAND, sanitize_text_field( $row['brand'] ?? '' ) );
        return $id;
    }

    public static function import_scanned_costs() {
        self::require_permission();
        check_admin_referer( 'persiano_hub_import_scanned_costs' );
        $mode = self::scan_mode( isset( $_POST['scan_mode'] ) ? wp_unslash( $_POST['scan_mode'] ) : 'purchase' );
        $job_id = absint( $_POST['scan_job_id'] ?? 0 );
        $rows = isset( $_POST['persiano_scan_items'] ) && is_array( $_POST['persiano_scan_items'] ) ? wp_unslash( $_POST['persiano_scan_items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $selected = isset( $_POST['persiano_scan_selected'] ) && is_array( $_POST['persiano_scan_selected'] ) ? array_map( 'absint', wp_unslash( $_POST['persiano_scan_selected'] ) ) : array();
        $imported=0; $errors=0;
        foreach ( $selected as $index ) {
            if ( ! isset( $rows[ $index ] ) || ! is_array( $rows[ $index ] ) ) { continue; }
            $row=$rows[$index];
            $existing_id=absint( $row['existing_id'] ?? 0 );
            if ( 'purchase' === $mode ) {
                $result = Persiano_Hub_Costing::save_imported_ingredient(
                    $existing_id,
                    array(
                        'name'=>$row['name'] ?? '', 'brand'=>$row['brand'] ?? '', 'category'=>$row['category'] ?? '', 'supplier'=>$row['supplier'] ?? '',
                        'purchase_qty'=>$row['purchase_qty'] ?? 0, 'purchase_unit'=>$row['purchase_unit'] ?? 'each', 'purchase_cost'=>$row['purchase_cost'] ?? 0,
                        'purchase_tax'=>$row['purchase_tax'] ?? 0, 'waste_pct'=>$row['waste_pct'] ?? 0, 'notes'=>$row['notes'] ?? '',
                    ),
                    'ai_purchase'
                );
                if ( is_wp_error( $result ) ) { $errors++; continue; }
                $inv_qty = max( 0, (float) ( $row['quantity_purchased'] ?? 0 ) );
                if ( $inv_qty > 0 ) {
                    Persiano_Hub_Operations::adjust_inventory( $result, 'add', $inv_qty, $row['purchase_unit'] ?? 'each', 'AI receipt/invoice', $row['notes'] ?? '', 'ai_purchase' );
                }
                $imported++;
            } else {
                $id = self::ensure_ingredient( $existing_id, $row );
                if ( is_wp_error( $id ) ) { $errors++; continue; }
                Persiano_Hub_Operations::add_price_record(
                    $id,
                    array(
                        'purchase_qty'=>$row['purchase_qty'] ?? 0, 'purchase_unit'=>$row['purchase_unit'] ?? 'each', 'purchase_cost'=>$row['purchase_cost'] ?? 0,
                        'purchase_tax'=>$row['purchase_tax'] ?? 0, 'waste_pct'=>$row['waste_pct'] ?? 0, 'brand'=>$row['brand'] ?? '', 'supplier'=>$row['supplier'] ?? '',
                        'valid_until'=>$row['valid_until'] ?? '', 'notes'=>$row['notes'] ?? '', 'source'=>'ai_observation',
                    ),
                    'observation'
                );
                $imported++;
            }
        }
        if ( $job_id && self::JOB_POST_TYPE === get_post_type( $job_id ) ) {
            update_post_meta( $job_id, self::META_JOB_STATUS, 'imported' );
            update_post_meta( $job_id, self::META_JOB_UPDATED, time() );
        } else {
            delete_transient( self::TRANSIENT_KEY . get_current_user_id() );
        }
        wp_safe_redirect( self::page_url( 'ingredients', array( 'imported'=>$imported,'errors'=>$errors,'scan_mode'=>$mode ) ) );
        exit;
    }

    public static function render_scan_page() {
        self::require_permission();
        $job_id = absint( $_GET['job'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $result = $job_id && self::JOB_POST_TYPE === get_post_type( $job_id ) ? get_post_meta( $job_id, self::META_JOB_RESULT, true ) : get_transient( self::TRANSIENT_KEY . get_current_user_id() );
        $error = isset( $_GET['scan_error'] ) ? sanitize_text_field( wp_unslash( $_GET['scan_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        // Visiting the queue page also wakes any chunk that became stranded by a missed cron/action.
        self::scheduled_sweep();
        $jobs = get_posts( array( 'post_type'=>self::JOB_POST_TYPE,'post_status'=>'any','posts_per_page'=>30,'orderby'=>'modified','order'=>'DESC' ) );
        $pending = false;
        foreach ( $jobs as $job ) { if ( in_array( get_post_meta($job->ID,self::META_JOB_STATUS,true), array('queued','processing','retrying'), true ) ) { $pending=true; break; } }
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Background AI intake', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'AI Scan Queue', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Upload several receipts, invoices, screenshots or PDFs together. They are stored immediately and processed one file—and one PDF page range—at a time in the background.', 'persiano-hub' ); ?></p></div></div>
        <?php if ( $error ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
        <?php if ( isset($_GET['queued_jobs']) ) : ?><div class="notice notice-success is-dismissible"><p><?php printf(esc_html__('%1$d files added to the background queue. %2$d files could not be accepted.','persiano-hub'),absint($_GET['queued_jobs']),absint($_GET['upload_errors']??0)); ?></p></div><?php endif; ?>
        <?php if ( ! self::get_api_key() ) : ?><div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'AI scanning is not configured yet.', 'persiano-hub' ); ?></strong> <a href="<?php echo esc_url( self::page_url( 'settings' ) ); ?>"><?php esc_html_e( 'Open AI Settings', 'persiano-hub' ); ?></a></p></div><?php endif; ?>
        <div class="ph-scan-mode-grid">
            <?php self::render_upload_card( 'purchase', __( 'Receipts & Invoices', 'persiano-hub' ), __( 'Actual purchases', 'persiano-hub' ), __( 'Confirmed rows update purchase history and may increase raw-ingredient inventory.', 'persiano-hub' ), __( 'Add purchases to queue', 'persiano-hub' ) ); ?>
            <?php self::render_upload_card( 'observation', __( 'Price Tags, Flyers & Price Lists', 'persiano-hub' ), __( 'Price research', 'persiano-hub' ), __( 'Observed prices improve supplier comparisons without changing inventory or recording a purchase.', 'persiano-hub' ), __( 'Add price files to queue', 'persiano-hub' ) ); ?>
        </div>
        <?php self::render_job_table( $jobs ); ?>
        <?php if ( is_array( $result ) && ! empty( $result['items'] ) ) { self::render_scan_result( $result, $job_id ); } ?>
        <?php if($pending): ?><script>
        (function(){
            var dirty = false;
            var statusNodes = document.querySelectorAll('.ph-scan-refresh-status');
            function selectedFiles(){
                var inputs = document.querySelectorAll('input[type="file"][name="persiano_cost_scan_files[]"]');
                for(var i=0;i<inputs.length;i++){if(inputs[i].files && inputs[i].files.length){return true;}}
                return false;
            }
            function updateStatus(){
                var paused = dirty || selectedFiles();
                for(var i=0;i<statusNodes.length;i++){
                    statusNodes[i].textContent = paused ? 'Auto-refresh paused to protect selected files or unsaved edits.' : 'Job status refreshes automatically.';
                }
                return paused;
            }
            document.addEventListener('change',function(event){
                if(event.target && event.target.closest && event.target.closest('.ph-costing-panel')){dirty=true;updateStatus();}
            });
            document.addEventListener('input',function(event){
                if(event.target && event.target.closest && event.target.closest('.ph-costing-panel')){dirty=true;updateStatus();}
            });
            document.addEventListener('submit',function(){dirty=false;});
            function refreshLater(){
                window.setTimeout(function(){
                    if(document.hidden || updateStatus()){refreshLater();return;}
                    window.location.reload();
                },15000);
            }
            updateStatus();
            refreshLater();
        }());
        </script><?php endif; ?>
        <?php
    }

    private static function render_upload_card( $mode, $title, $eyebrow, $description, $button ) {
        $server_limit = min( 25 * MB_IN_BYTES, wp_max_upload_size() );
        ?><section class="ph-costing-panel ph-scan-mode-card"><span class="ph-costing-eyebrow"><?php echo esc_html( $eyebrow ); ?></span><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $description ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data"><input type="hidden" name="action" value="persiano_hub_queue_cost_scans"><input type="hidden" name="scan_mode" value="<?php echo esc_attr( $mode ); ?>"><?php wp_nonce_field( 'persiano_hub_queue_cost_scans' ); ?><input type="file" name="persiano_cost_scan_files[]" accept="image/jpeg,image/png,image/webp,application/pdf,.pdf" multiple required> <?php submit_button( $button, 'primary', 'submit', false ); ?><p class="description"><?php printf( esc_html__( 'Up to 10 files per submission. Current server limit: %s per file/request. You may leave after they enter the queue.', 'persiano-hub' ), esc_html( size_format( $server_limit ) ) ); ?></p><p class="description ph-scan-refresh-status" aria-live="polite"></p></form></section><?php
    }

    private static function render_job_table( $jobs ) {
        ?><section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><h2><?php esc_html_e('Recent scan jobs','persiano-hub'); ?></h2><p><?php esc_html_e('PDFs are processed in small page ranges. A failed range retries without restarting completed ranges. WooCommerce Action Scheduler is used when available, with WP-Cron as a fallback.','persiano-hub'); ?></p><p class="description ph-scan-refresh-status" aria-live="polite"></p></div><div><a class="button" href="<?php echo esc_url( self::page_url('scan') ); ?>"><?php esc_html_e('Refresh jobs','persiano-hub'); ?></a></div></div><table class="widefat striped"><thead><tr><th>File</th><th>Workflow</th><th>Progress</th><th>Status</th><th>Updated</th><th></th></tr></thead><tbody><?php if(!$jobs): ?><tr><td colspan="6">No queued scans yet.</td></tr><?php else:foreach($jobs as$job):$id=$job->ID;$status=get_post_meta($id,self::META_JOB_STATUS,true);$pages=(int)get_post_meta($id,self::META_JOB_PAGE_COUNT,true);$next=(int)get_post_meta($id,self::META_JOB_NEXT_PAGE,true);$error=get_post_meta($id,self::META_JOB_ERROR,true);$attachment=absint(get_post_meta($id,self::META_JOB_ATTACHMENT,true)); ?><tr><td><strong><?php echo esc_html($job->post_title); ?></strong><?php if($attachment): ?><br><a href="<?php echo esc_url(wp_get_attachment_url($attachment)); ?>" target="_blank" rel="noopener">Open file ↗</a><?php endif; ?><?php if($error): ?><br><span style="color:#b32d2e"><?php echo esc_html($error); ?></span><?php endif; ?></td><td><?php echo esc_html(ucfirst(get_post_meta($id,self::META_JOB_MODE,true))); ?></td><td><?php if($pages>1):echo esc_html(min($pages,max(0,$next-1)).' / '.$pages.' pages');else:echo '—';endif; ?></td><td><strong><?php echo esc_html(ucwords(str_replace('_',' ',$status))); ?></strong></td><td><?php $updated=(int)get_post_meta($id,self::META_JOB_UPDATED,true);echo $updated?esc_html(wp_date('M j, g:i a',$updated)):'—'; ?></td><td><?php if('complete'===$status): ?><a class="button button-primary" href="<?php echo esc_url(self::page_url('scan',array('job'=>$id))); ?>">Review</a><?php elseif(in_array($status,array('failed','retrying'),true)): ?><a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action'=>'persiano_hub_retry_ai_scan_job','job'=>$id),admin_url('admin-post.php')),'persiano_hub_retry_ai_scan_job_'.$id)); ?>">Retry</a><<?php elseif(in_array($status,array('queued','processing'),true)): ?><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action'=>'persiano_hub_run_ai_scan_job_now','job'=>$id),admin_url('admin-post.php')),'persiano_hub_run_ai_scan_job_now_'.$id)); ?>">Run next chunk now</a><?php endif; ?> <a class="button-link-delete" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action'=>'persiano_hub_delete_ai_scan_job','job'=>$id),admin_url('admin-post.php')),'persiano_hub_delete_ai_scan_job_'.$id)); ?>" onclick="return confirm('Delete this scan job?')">Delete</a></td></tr><?php endforeach;endif; ?></tbody></table></section><?php
    }

    private static function render_scan_result( $result, $job_id = 0 ) {
        $mode = self::scan_mode( $result['scan_mode'] ?? 'purchase' );
        $purchase = 'purchase' === $mode;
        $ingredients = get_posts( array( 'post_type'=>Persiano_Hub_Costing::INGREDIENT_POST_TYPE,'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC' ) );
        ?>
        <section class="ph-costing-panel"><div class="ph-costing-heading-row"><div><span class="ph-status-chip"><?php echo $purchase ? esc_html__( 'Actual purchase', 'persiano-hub' ) : esc_html__( 'Price observation', 'persiano-hub' ); ?></span><h2><?php echo $purchase ? esc_html__( 'Review detected purchase', 'persiano-hub' ) : esc_html__( 'Review observed prices', 'persiano-hub' ); ?></h2><p><?php printf( esc_html__( 'Vendor: %1$s · Document: %2$s · Date: %3$s · Reference: %4$s', 'persiano-hub' ), esc_html( $result['vendor'] ?: '—' ), esc_html( $result['document_type'] ?: '—' ), esc_html( $result['document_date'] ?: '—' ), esc_html( $result['reference_number'] ?: '—' ) ); ?></p><?php if ( ! $purchase ) : ?><p class="description"><?php esc_html_e( 'Saving these rows will only add vendor price observations. It will not change inventory or record a purchase.', 'persiano-hub' ); ?></p><?php endif; ?></div></div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_import_scanned_costs"><input type="hidden" name="scan_mode" value="<?php echo esc_attr( $mode ); ?>"><input type="hidden" name="scan_job_id" value="<?php echo esc_attr( $job_id ); ?>"><?php wp_nonce_field( 'persiano_hub_import_scanned_costs' ); ?><div class="ph-scan-table-wrap"><table class="widefat striped ph-scan-table"><thead><tr><th><?php esc_html_e( 'Save', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Ingredient', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Brand', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Vendor', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Package', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Subtotal', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Tax', 'persiano-hub' ); ?></th><?php if ( $purchase ) : ?><th><?php esc_html_e( 'Inventory add', 'persiano-hub' ); ?></th><?php endif; ?><th><?php esc_html_e( 'Match', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Confidence', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php foreach ( $result['items'] as $index=>$item ) : ?><tr><td><input type="checkbox" name="persiano_scan_selected[]" value="<?php echo esc_attr( $index ); ?>" checked></td><td><input class="widefat" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $item['name'] ); ?>"><input type="hidden" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][category]" value="<?php echo esc_attr( $item['category'] ); ?>"><input type="hidden" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][notes]" value="<?php echo esc_attr( $item['notes'] ); ?>"><input type="hidden" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][waste_pct]" value="0"><input type="hidden" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][valid_until]" value="<?php echo esc_attr( $result['valid_until'] ?? '' ); ?>"></td><td><input class="widefat" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][brand]" value="<?php echo esc_attr( $item['brand'] ); ?>"></td><td><input class="widefat" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][supplier]" value="<?php echo esc_attr( $result['vendor'] ); ?>"></td><td><input type="number" min="0" step="0.0001" class="small-text" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][purchase_qty]" value="<?php echo esc_attr( $item['purchase_qty'] ); ?>"> <select name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][purchase_unit]"><?php foreach ( Persiano_Hub_Costing::unit_options() as $unit=>$label ) : ?><option value="<?php echo esc_attr( $unit ); ?>" <?php selected( $item['purchase_unit'],$unit ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td><td><input type="number" min="0" step="0.01" class="small-text" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][purchase_cost]" value="<?php echo esc_attr( $item['purchase_cost'] ); ?>"></td><td><input type="number" min="0" step="0.01" class="small-text" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][purchase_tax]" value="<?php echo esc_attr( $item['purchase_tax'] ); ?>"><br><small><?php echo esc_html( $item['tax_status'] ); ?></small></td><?php if ( $purchase ) : ?><td><input type="number" min="0" step="0.0001" class="small-text" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][quantity_purchased]" value="<?php echo esc_attr( $item['quantity_purchased'] ); ?>"> <?php echo esc_html( $item['purchase_unit'] ); ?></td><?php else : ?><td style="display:none"><input type="hidden" name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][quantity_purchased]" value="0"></td><?php endif; ?><td><select name="persiano_scan_items[<?php echo esc_attr( $index ); ?>][existing_id]" style="min-width:220px"><option value="0"><?php esc_html_e( 'Create new ingredient', 'persiano-hub' ); ?></option><?php foreach ( $ingredients as $ingredient ) : ?><option value="<?php echo esc_attr( $ingredient->ID ); ?>" <?php selected( absint( $item['existing_id'] ?? 0 ), $ingredient->ID ); ?>><?php echo esc_html( $ingredient->post_title ); ?></option><?php endforeach; ?></select></td><td><?php echo esc_html( number_format_i18n( (float) $item['confidence']*100, 0 ) . '%' ); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><p><?php submit_button( $purchase ? __( 'Save selected purchases', 'persiano-hub' ) : __( 'Save selected price observations', 'persiano-hub' ), 'primary', 'submit', false ); ?></p></form></section>
        <?php
    }

    public static function render_settings_page() {
        self::require_permission();
        $has_key=(bool) self::get_api_key();
        ?>
        <div class="ph-costing-hero ph-costing-hero--compact"><div><span class="ph-costing-eyebrow"><?php esc_html_e( 'Persiano AI', 'persiano-hub' ); ?></span><h1><?php esc_html_e( 'AI Settings', 'persiano-hub' ); ?></h1><p><?php esc_html_e( 'Configure the server-side AI connection used by purchase and price-research scanning. The secret key is never sent to the browser.', 'persiano-hub' ); ?></p></div></div>
        <?php if ( ! empty( $_GET['saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'AI settings saved.', 'persiano-hub' ); ?></p></div><?php endif; ?>
        <section class="ph-costing-panel"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_hub_save_ai_cost_settings"><?php wp_nonce_field( 'persiano_hub_save_ai_cost_settings' ); ?><table class="form-table" role="presentation"><tr><th><label for="persiano_hub_openai_api_key"><?php esc_html_e( 'OpenAI API key', 'persiano-hub' ); ?></label></th><td><input type="password" class="regular-text" id="persiano_hub_openai_api_key" name="persiano_hub_openai_api_key" value="" autocomplete="new-password" placeholder="<?php echo $has_key ? esc_attr__( 'Saved — enter a new key only to replace it', 'persiano-hub' ) : esc_attr__( 'sk-…', 'persiano-hub' ); ?>"><?php if ( $has_key && ! defined( 'PERSIANO_OPENAI_API_KEY' ) ) : ?><p><label><input type="checkbox" name="persiano_hub_clear_openai_key" value="1"> <?php esc_html_e( 'Remove saved key', 'persiano-hub' ); ?></label></p><?php endif; ?></td></tr><tr><th><label for="persiano_hub_openai_cost_model"><?php esc_html_e( 'Vision model', 'persiano-hub' ); ?></label></th><td><input type="text" class="regular-text" id="persiano_hub_openai_cost_model" name="persiano_hub_openai_cost_model" value="<?php echo esc_attr( self::get_model() ); ?>"><p class="description"><?php esc_html_e( 'Default: gpt-5-mini.', 'persiano-hub' ); ?></p></td></tr></table><?php submit_button( __( 'Save AI Settings', 'persiano-hub' ) ); ?></form></section>
        <?php
    }
}
