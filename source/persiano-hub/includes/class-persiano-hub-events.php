<?php
/**
 * Events, invitations and RSVP management for Batchly.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Events {
    const POST_TYPE    = 'persiano_event';
    const TABLE_SUFFIX = 'persiano_event_rsvps';
    const META_PREFIX  = '_ph_event_';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'register_meta_boxes' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_event' ), 20, 3 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 24 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

        add_action( 'admin_post_nopriv_persiano_event_rsvp', array( __CLASS__, 'handle_rsvp' ) );
        add_action( 'admin_post_persiano_event_rsvp', array( __CLASS__, 'handle_rsvp' ) );
        add_action( 'admin_post_nopriv_persiano_event_cancel_rsvp', array( __CLASS__, 'handle_cancel_rsvp' ) );
        add_action( 'admin_post_persiano_event_cancel_rsvp', array( __CLASS__, 'handle_cancel_rsvp' ) );
        add_action( 'admin_post_nopriv_persiano_event_feedback', array( __CLASS__, 'handle_feedback' ) );
        add_action( 'admin_post_persiano_event_feedback', array( __CLASS__, 'handle_feedback' ) );

        add_action( 'admin_post_persiano_event_rsvp_bulk', array( __CLASS__, 'handle_admin_rsvp_actions' ) );
        add_action( 'admin_post_persiano_event_export_rsvps', array( __CLASS__, 'export_rsvps' ) );
        add_action( 'admin_post_persiano_event_create_campaign', array( __CLASS__, 'create_invitation_campaign' ) );
        add_action( 'admin_post_persiano_event_send_reminder', array( __CLASS__, 'send_reminder_admin' ) );
        add_action( 'admin_post_persiano_event_send_followup', array( __CLASS__, 'send_followup_admin' ) );

        add_action( 'persiano_hub_event_reminder', array( __CLASS__, 'send_scheduled_reminder' ), 10, 1 );
        add_action( 'persiano_hub_event_followup', array( __CLASS__, 'send_scheduled_followup' ), 10, 1 );

        add_shortcode( 'persiano_event_rsvp', array( __CLASS__, 'rsvp_shortcode' ) );
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'event_columns' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_event_column' ), 10, 2 );

        add_action( 'admin_init', array( __CLASS__, 'maybe_seed_tasting_event' ), 45 );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL,
            phone varchar(80) NOT NULL DEFAULT '',
            guest_count smallint(5) unsigned NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'confirmed',
            dietary_notes text NULL,
            message text NULL,
            mailing_optin tinyint(1) NOT NULL DEFAULT 0,
            checked_in tinyint(1) NOT NULL DEFAULT 0,
            token varchar(64) NOT NULL,
            feedback_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
            feedback_text text NULL,
            feedback_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_email (event_id,email),
            UNIQUE KEY token (token),
            KEY event_status (event_id,status),
            KEY checked_in (checked_in)
        ) {$charset_collate};";
        dbDelta( $sql );
    }

    public static function register_post_type() {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name'               => __( 'Events', 'persiano-hub' ),
                    'singular_name'      => __( 'Event', 'persiano-hub' ),
                    'add_new'            => __( 'Add Event', 'persiano-hub' ),
                    'add_new_item'       => __( 'Create Persiano Event', 'persiano-hub' ),
                    'edit_item'          => __( 'Edit Event', 'persiano-hub' ),
                    'new_item'           => __( 'New Event', 'persiano-hub' ),
                    'view_item'          => __( 'View Event', 'persiano-hub' ),
                    'search_items'       => __( 'Search Events', 'persiano-hub' ),
                    'not_found'          => __( 'No events found.', 'persiano-hub' ),
                    'not_found_in_trash' => __( 'No events found in Trash.', 'persiano-hub' ),
                ),
                'public'             => true,
                'show_ui'            => true,
                'show_in_menu'       => 'persiano-hub',
                'show_in_rest'       => true,
                'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
                'has_archive'        => false,
                'rewrite'            => array( 'slug' => 'events', 'with_front' => false ),
                'publicly_queryable' => true,
                'exclude_from_search'=> false,
                'menu_position'      => 6,
                'menu_icon'          => 'dashicons-tickets-alt',
            )
        );
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Event RSVPs', 'persiano-hub' ),
            __( 'Event RSVPs', 'persiano-hub' ),
            'manage_woocommerce',
            'persiano-event-rsvps',
            array( __CLASS__, 'render_rsvp_admin' )
        );
    }

    public static function register_meta_boxes() {
        add_meta_box(
            'persiano-event-details',
            __( 'Event Details & RSVP', 'persiano-hub' ),
            array( __CLASS__, 'render_details_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );
        add_meta_box(
            'persiano-event-report',
            __( 'Event Report & Gallery', 'persiano-hub' ),
            array( __CLASS__, 'render_report_meta_box' ),
            self::POST_TYPE,
            'normal',
            'default'
        );
        add_meta_box(
            'persiano-event-actions',
            __( 'Invitation & Attendees', 'persiano-hub' ),
            array( __CLASS__, 'render_actions_meta_box' ),
            self::POST_TYPE,
            'side',
            'high'
        );
    }

    public static function render_details_meta_box( $post ) {
        wp_nonce_field( 'persiano_event_save_' . $post->ID, 'persiano_event_nonce' );
        $meta = self::get_event_meta( $post->ID );
        ?>
        <div class="ph-event-admin-grid">
            <label><span><?php esc_html_e( 'Event status', 'persiano-hub' ); ?></span>
                <select name="persiano_event_status">
                    <option value="upcoming" <?php selected( $meta['status'], 'upcoming' ); ?>><?php esc_html_e( 'Upcoming', 'persiano-hub' ); ?></option>
                    <option value="completed" <?php selected( $meta['status'], 'completed' ); ?>><?php esc_html_e( 'Completed / report', 'persiano-hub' ); ?></option>
                    <option value="cancelled" <?php selected( $meta['status'], 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'persiano-hub' ); ?></option>
                </select>
            </label>
            <label><span><?php esc_html_e( 'Event type', 'persiano-hub' ); ?></span>
                <select name="persiano_event_type">
                    <?php foreach ( self::event_types() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $meta['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span><?php esc_html_e( 'Starts', 'persiano-hub' ); ?></span><input type="datetime-local" name="persiano_event_start" value="<?php echo esc_attr( $meta['start'] ); ?>"></label>
            <label><span><?php esc_html_e( 'Ends', 'persiano-hub' ); ?></span><input type="datetime-local" name="persiano_event_end" value="<?php echo esc_attr( $meta['end'] ); ?>"></label>
            <label class="ph-event-checkbox"><input type="checkbox" name="persiano_event_all_day" value="yes" <?php checked( $meta['all_day'], 'yes' ); ?>> <span><?php esc_html_e( 'Date only / hide event time', 'persiano-hub' ); ?></span></label>
            <label><span><?php esc_html_e( 'Public location', 'persiano-hub' ); ?></span><input type="text" name="persiano_event_location" value="<?php echo esc_attr( $meta['location'] ); ?>" placeholder="City, region"><small><?php esc_html_e( 'Only enter the level of location detail you want visible publicly.', 'persiano-hub' ); ?></small></label>
            <label><span><?php esc_html_e( 'Capacity (guests)', 'persiano-hub' ); ?></span><input type="number" min="0" step="1" name="persiano_event_capacity" value="<?php echo esc_attr( $meta['capacity'] ); ?>"><small><?php esc_html_e( 'Use 0 for unlimited.', 'persiano-hub' ); ?></small></label>
            <label><span><?php esc_html_e( 'RSVP deadline', 'persiano-hub' ); ?></span><input type="datetime-local" name="persiano_event_rsvp_deadline" value="<?php echo esc_attr( $meta['rsvp_deadline'] ); ?>"></label>
            <label class="ph-event-checkbox"><input type="checkbox" name="persiano_event_rsvp_enabled" value="yes" <?php checked( $meta['rsvp_enabled'], 'yes' ); ?>> <span><?php esc_html_e( 'Accept RSVPs', 'persiano-hub' ); ?></span></label>
            <label class="ph-event-checkbox"><input type="checkbox" name="persiano_event_waitlist_enabled" value="yes" <?php checked( $meta['waitlist_enabled'], 'yes' ); ?>> <span><?php esc_html_e( 'Allow waitlist when full', 'persiano-hub' ); ?></span></label>
            <label><span><?php esc_html_e( 'Reminder lead time (days)', 'persiano-hub' ); ?></span><input type="number" min="0" max="30" step="1" name="persiano_event_reminder_days" value="<?php echo esc_attr( $meta['reminder_days'] ); ?>"></label>
            <label><span><?php esc_html_e( 'Follow-up delay (days)', 'persiano-hub' ); ?></span><input type="number" min="0" max="30" step="1" name="persiano_event_followup_days" value="<?php echo esc_attr( $meta['followup_days'] ); ?>"></label>
        </div>
        <div class="ph-event-admin-copy">
            <label><span><?php esc_html_e( 'Invitation email subject', 'persiano-hub' ); ?></span><input type="text" name="persiano_event_invitation_subject" value="<?php echo esc_attr( $meta['invitation_subject'] ); ?>" placeholder="You’re invited to Persiano…"></label>
            <label><span><?php esc_html_e( 'Invitation / campaign introduction', 'persiano-hub' ); ?></span><textarea rows="5" name="persiano_event_invitation_body" placeholder="Use this as the starting copy when creating an invitation campaign."><?php echo esc_textarea( $meta['invitation_body'] ); ?></textarea></label>
        </div>
        <?php
    }

    public static function render_report_meta_box( $post ) {
        $meta = self::get_event_meta( $post->ID );
        $gallery = self::get_gallery_ids( $post->ID );
        ?>
        <div class="ph-event-admin-copy">
            <label><span><?php esc_html_e( 'Public event summary / report', 'persiano-hub' ); ?></span><textarea rows="6" name="persiano_event_report_summary"><?php echo esc_textarea( $meta['report_summary'] ); ?></textarea></label>
            <label><span><?php esc_html_e( 'Menu served', 'persiano-hub' ); ?></span><textarea rows="5" name="persiano_event_menu" placeholder="One item per line"><?php echo esc_textarea( $meta['menu'] ); ?></textarea></label>
            <label><span><?php esc_html_e( 'Highlights', 'persiano-hub' ); ?></span><textarea rows="5" name="persiano_event_highlights" placeholder="One highlight per line"><?php echo esc_textarea( $meta['highlights'] ); ?></textarea></label>
            <label><span><?php esc_html_e( 'Guest feedback / testimonial', 'persiano-hub' ); ?></span><textarea rows="4" name="persiano_event_testimonial"><?php echo esc_textarea( $meta['testimonial'] ); ?></textarea></label>
        </div>
        <div class="ph-event-admin-grid">
            <label><span><?php esc_html_e( 'Actual guest count', 'persiano-hub' ); ?></span><input type="number" min="0" step="1" name="persiano_event_actual_guests" value="<?php echo esc_attr( $meta['actual_guests'] ); ?>"></label>
            <label><span><?php esc_html_e( 'Private sales / revenue', 'persiano-hub' ); ?></span><input type="number" min="0" step="0.01" name="persiano_event_sales" value="<?php echo esc_attr( $meta['sales'] ); ?>"><small><?php esc_html_e( 'Admin only; never shown on the public event page.', 'persiano-hub' ); ?></small></label>
        </div>
        <div class="ph-event-admin-copy">
            <label><span><?php esc_html_e( 'What worked well (private)', 'persiano-hub' ); ?></span><textarea rows="4" name="persiano_event_worked_well"><?php echo esc_textarea( $meta['worked_well'] ); ?></textarea></label>
            <label><span><?php esc_html_e( 'Lessons for next time (private)', 'persiano-hub' ); ?></span><textarea rows="4" name="persiano_event_lessons"><?php echo esc_textarea( $meta['lessons'] ); ?></textarea></label>
        </div>
        <div class="ph-event-gallery-admin">
            <input type="hidden" id="persiano_event_gallery_ids" name="persiano_event_gallery_ids" value="<?php echo esc_attr( implode( ',', $gallery ) ); ?>">
            <div id="persiano_event_gallery_preview" class="ph-event-gallery-preview">
                <?php foreach ( $gallery as $attachment_id ) : ?>
                    <div data-id="<?php echo esc_attr( $attachment_id ); ?>"><?php echo wp_get_attachment_image( $attachment_id, array( 120, 120 ) ); ?><button type="button" class="button-link-delete ph-event-gallery-remove">×</button></div>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="button" id="persiano_event_choose_gallery"><?php esc_html_e( 'Choose / reorder gallery images', 'persiano-hub' ); ?></button> <button type="button" class="button-link-delete" id="persiano_event_clear_gallery"><?php esc_html_e( 'Clear gallery', 'persiano-hub' ); ?></button></p>
            <p class="description"><?php esc_html_e( 'Use Media Library captions as the public captions for each photo.', 'persiano-hub' ); ?></p>
        </div>
        <?php
    }

    public static function render_actions_meta_box( $post ) {
        $counts = self::get_rsvp_counts( $post->ID );
        echo '<p><strong>' . esc_html__( 'Confirmed seats:', 'persiano-hub' ) . '</strong> ' . esc_html( $counts['confirmed_guests'] ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Waitlist seats:', 'persiano-hub' ) . '</strong> ' . esc_html( $counts['waitlist_guests'] ) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=persiano-event-rsvps&event_id=' . absint( $post->ID ) ) ) . '">' . esc_html__( 'Manage attendees', 'persiano-hub' ) . '</a></p>';

        if ( 'auto-draft' !== $post->post_status ) {
            $campaign_url = wp_nonce_url( admin_url( 'admin-post.php?action=persiano_event_create_campaign&event_id=' . absint( $post->ID ) ), 'persiano_event_create_campaign_' . $post->ID );
            $reminder_url = wp_nonce_url( admin_url( 'admin-post.php?action=persiano_event_send_reminder&event_id=' . absint( $post->ID ) ), 'persiano_event_send_reminder_' . $post->ID );
            $followup_url = wp_nonce_url( admin_url( 'admin-post.php?action=persiano_event_send_followup&event_id=' . absint( $post->ID ) ), 'persiano_event_send_followup_' . $post->ID );
            echo '<hr><p><a class="button" href="' . esc_url( $campaign_url ) . '">' . esc_html__( 'Create invitation campaign', 'persiano-hub' ) . '</a></p>';
            echo '<p><a class="button" href="' . esc_url( $reminder_url ) . '">' . esc_html__( 'Send reminder now', 'persiano-hub' ) . '</a></p>';
            echo '<p><a class="button" href="' . esc_url( $followup_url ) . '">' . esc_html__( 'Send follow-up now', 'persiano-hub' ) . '</a></p>';
        }
    }

    public static function save_event( $post_id, $post, $update ) {
        if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! isset( $_POST['persiano_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_event_nonce'] ) ), 'persiano_event_save_' . $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $status = isset( $_POST['persiano_event_status'] ) ? sanitize_key( wp_unslash( $_POST['persiano_event_status'] ) ) : 'upcoming';
        if ( ! in_array( $status, array( 'upcoming', 'completed', 'cancelled' ), true ) ) {
            $status = 'upcoming';
        }
        $type = isset( $_POST['persiano_event_type'] ) ? sanitize_key( wp_unslash( $_POST['persiano_event_type'] ) ) : 'tasting';
        if ( ! isset( self::event_types()[ $type ] ) ) {
            $type = 'tasting';
        }

        $fields = array(
            'status'             => $status,
            'type'               => $type,
            'start'              => self::sanitize_datetime_local( isset( $_POST['persiano_event_start'] ) ? wp_unslash( $_POST['persiano_event_start'] ) : '' ),
            'end'                => self::sanitize_datetime_local( isset( $_POST['persiano_event_end'] ) ? wp_unslash( $_POST['persiano_event_end'] ) : '' ),
            'all_day'            => isset( $_POST['persiano_event_all_day'] ) ? 'yes' : 'no',
            'location'           => isset( $_POST['persiano_event_location'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_event_location'] ) ) : '',
            'capacity'           => isset( $_POST['persiano_event_capacity'] ) ? max( 0, absint( $_POST['persiano_event_capacity'] ) ) : 0,
            'rsvp_deadline'      => self::sanitize_datetime_local( isset( $_POST['persiano_event_rsvp_deadline'] ) ? wp_unslash( $_POST['persiano_event_rsvp_deadline'] ) : '' ),
            'rsvp_enabled'       => isset( $_POST['persiano_event_rsvp_enabled'] ) ? 'yes' : 'no',
            'waitlist_enabled'   => isset( $_POST['persiano_event_waitlist_enabled'] ) ? 'yes' : 'no',
            'reminder_days'      => isset( $_POST['persiano_event_reminder_days'] ) ? min( 30, max( 0, absint( $_POST['persiano_event_reminder_days'] ) ) ) : 2,
            'followup_days'      => isset( $_POST['persiano_event_followup_days'] ) ? min( 30, max( 0, absint( $_POST['persiano_event_followup_days'] ) ) ) : 1,
            'invitation_subject' => isset( $_POST['persiano_event_invitation_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_event_invitation_subject'] ) ) : '',
            'invitation_body'    => isset( $_POST['persiano_event_invitation_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_event_invitation_body'] ) ) : '',
            'report_summary'     => isset( $_POST['persiano_event_report_summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_event_report_summary'] ) ) : '',
            'menu'               => isset( $_POST['persiano_event_menu'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_event_menu'] ) ) : '',
            'highlights'         => isset( $_POST['persiano_event_highlights'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_event_highlights'] ) ) : '',
            'testimonial'        => isset( $_POST['persiano_event_testimonial'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_event_testimonial'] ) ) : '',
            'actual_guests'      => isset( $_POST['persiano_event_actual_guests'] ) ? max( 0, absint( $_POST['persiano_event_actual_guests'] ) ) : 0,
            'sales'              => isset( $_POST['persiano_event_sales'] ) ? wc_format_decimal( wp_unslash( $_POST['persiano_event_sales'] ) ) : '',
            'worked_well'        => isset( $_POST['persiano_event_worked_well'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_event_worked_well'] ) ) : '',
            'lessons'            => isset( $_POST['persiano_event_lessons'] ) ? sanitize_textarea_field( wp_unslash( $_POST['persiano_event_lessons'] ) ) : '',
        );

        foreach ( $fields as $key => $value ) {
            update_post_meta( $post_id, self::META_PREFIX . $key, $value );
        }

        $gallery_raw = isset( $_POST['persiano_event_gallery_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['persiano_event_gallery_ids'] ) ) : '';
        $gallery_ids = array_values( array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', $gallery_raw ) ) ) );
        update_post_meta( $post_id, self::META_PREFIX . 'gallery_ids', $gallery_ids );

        self::schedule_messages( $post_id );
    }

    public static function admin_assets( $hook ) {
        $screen = get_current_screen();
        if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
            return;
        }
        wp_enqueue_media();
        wp_register_style( 'persiano-hub-events-admin', false, array(), PERSIANO_HUB_VERSION );
        wp_enqueue_style( 'persiano-hub-events-admin' );
        wp_add_inline_style(
            'persiano-hub-events-admin',
            '.ph-event-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.ph-event-admin-grid label,.ph-event-admin-copy label{display:flex;flex-direction:column;gap:6px}.ph-event-admin-copy{display:grid;gap:16px;margin-top:16px}.ph-event-admin-grid input,.ph-event-admin-grid select,.ph-event-admin-copy input,.ph-event-admin-copy textarea{width:100%}.ph-event-checkbox{flex-direction:row!important;align-items:center;margin-top:28px}.ph-event-checkbox input{width:auto}.ph-event-gallery-preview{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.ph-event-gallery-preview>div{position:relative;width:120px;height:120px;border:1px solid #dcdcde;background:#fff}.ph-event-gallery-preview img{width:100%;height:100%;object-fit:cover}.ph-event-gallery-remove{position:absolute;top:2px;right:5px;background:#fff!important;border-radius:50%;font-size:22px;text-decoration:none}@media(max-width:782px){.ph-event-admin-grid{grid-template-columns:1fr}.ph-event-checkbox{margin-top:0}}'
        );
        $script = <<<'JS'
(function($){
    var galleryFrame = null;
    function ids(){return ($('#persiano_event_gallery_ids').val()||'').split(',').map(function(id){return parseInt(id,10);}).filter(Boolean);}
    function render(selection){
        var wrap=$('#persiano_event_gallery_preview').empty(), values=[];
        selection.each(function(model){
            var att=model.toJSON ? model.toJSON() : model;
            values.push(att.id);
            var url=(att.sizes&&att.sizes.thumbnail)?att.sizes.thumbnail.url:att.url;
            wrap.append('<div data-id="'+att.id+'"><img src="'+url+'" alt=""><button type="button" class="button-link-delete ph-event-gallery-remove" aria-label="Remove image">×</button></div>');
        });
        $('#persiano_event_gallery_ids').val(values.join(',')).trigger('change');
    }
    $(document).on('click','#persiano_event_choose_gallery',function(e){
        e.preventDefault();
        if(typeof wp==='undefined'||!wp.media){window.alert('The WordPress Media Library could not be loaded. Please refresh this page and try again.');return;}
        if(!galleryFrame){
            galleryFrame=wp.media({title:'Choose event gallery images',button:{text:'Use these images'},multiple:true,library:{type:'image'}});
            galleryFrame.on('select',function(){render(galleryFrame.state().get('selection'));});
        }
        galleryFrame.off('open.persianoGallery').on('open.persianoGallery',function(){
            var selection=galleryFrame.state().get('selection');
            selection.reset();
            ids().forEach(function(id){
                var att=wp.media.attachment(id);
                att.fetch();
                selection.add(att);
            });
        });
        galleryFrame.open();
    });
    $(document).on('click','.ph-event-gallery-remove',function(e){e.preventDefault();$(this).parent().remove();var out=[];$('#persiano_event_gallery_preview>div').each(function(){out.push($(this).data('id'));});$('#persiano_event_gallery_ids').val(out.join(',')).trigger('change');});
    $(document).on('click','#persiano_event_clear_gallery',function(e){e.preventDefault();$('#persiano_event_gallery_preview').empty();$('#persiano_event_gallery_ids').val('').trigger('change');});
})(jQuery);
JS;
        wp_add_inline_script( 'jquery', $script );
    }


    public static function admin_notices() {
        $notice = isset( $_GET['ph_event_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['ph_event_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }
    }

    public static function event_types() {
        return array(
            'tasting'   => __( 'Tasting', 'persiano-hub' ),
            'popup'     => __( 'Pop-up', 'persiano-hub' ),
            'workshop'  => __( 'Workshop', 'persiano-hub' ),
            'community' => __( 'Community gathering', 'persiano-hub' ),
            'catering'  => __( 'Catering showcase', 'persiano-hub' ),
            'other'     => __( 'Other', 'persiano-hub' ),
        );
    }

    public static function get_event_meta( $event_id ) {
        $defaults = array(
            'status' => 'upcoming', 'type' => 'tasting', 'start' => '', 'end' => '', 'all_day' => 'no', 'location' => '',
            'capacity' => 0, 'rsvp_deadline' => '', 'rsvp_enabled' => 'yes', 'waitlist_enabled' => 'yes',
            'reminder_days' => 2, 'followup_days' => 1, 'invitation_subject' => '', 'invitation_body' => '',
            'report_summary' => '', 'menu' => '', 'highlights' => '', 'testimonial' => '', 'actual_guests' => 0,
            'sales' => '', 'worked_well' => '', 'lessons' => '',
        );
        foreach ( $defaults as $key => $default ) {
            $value = get_post_meta( $event_id, self::META_PREFIX . $key, true );
            if ( '' !== $value && null !== $value ) {
                $defaults[ $key ] = $value;
            }
        }
        return $defaults;
    }

    public static function get_gallery_ids( $event_id ) {
        $ids = get_post_meta( $event_id, self::META_PREFIX . 'gallery_ids', true );
        return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
    }

    public static function event_timestamp( $event_id, $field = 'start' ) {
        $value = (string) get_post_meta( $event_id, self::META_PREFIX . $field, true );
        if ( ! $value ) {
            return 0;
        }
        try {
            $date = new DateTime( $value, wp_timezone() );
            return $date->getTimestamp();
        } catch ( Exception $e ) {
            return 0;
        }
    }

    public static function event_display_date( $event_id, $include_time = true ) {
        $timestamp = self::event_timestamp( $event_id, 'start' );
        if ( ! $timestamp ) {
            return get_the_date( '', $event_id );
        }
        $all_day = 'yes' === get_post_meta( $event_id, self::META_PREFIX . 'all_day', true );
        $format = ( $all_day || ! $include_time ) ? get_option( 'date_format' ) : get_option( 'date_format' ) . ' · ' . get_option( 'time_format' );
        return wp_date( $format, $timestamp, wp_timezone() );
    }

    public static function get_rsvp_counts( $event_id ) {
        global $wpdb;
        $table = self::table_name();
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT status, COUNT(*) AS people, COALESCE(SUM(guest_count),0) AS guests FROM {$table} WHERE event_id=%d GROUP BY status", $event_id ),
            OBJECT_K
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array(
            'confirmed_people' => isset( $rows['confirmed'] ) ? (int) $rows['confirmed']->people : 0,
            'confirmed_guests' => isset( $rows['confirmed'] ) ? (int) $rows['confirmed']->guests : 0,
            'waitlist_people'  => isset( $rows['waitlist'] ) ? (int) $rows['waitlist']->people : 0,
            'waitlist_guests'  => isset( $rows['waitlist'] ) ? (int) $rows['waitlist']->guests : 0,
            'cancelled_people' => isset( $rows['cancelled'] ) ? (int) $rows['cancelled']->people : 0,
        );
    }

    public static function render_public_interaction( $event_id ) {
        $token = isset( $_GET['manage_rsvp'] ) ? sanitize_text_field( wp_unslash( $_GET['manage_rsvp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $feedback_token = isset( $_GET['feedback_token'] ) ? sanitize_text_field( wp_unslash( $_GET['feedback_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $feedback_token ) {
            self::render_feedback_form( $event_id, $feedback_token );
            return;
        }
        if ( $token ) {
            self::render_manage_rsvp( $event_id, $token );
            return;
        }
        self::render_rsvp_form( $event_id );
    }

    public static function rsvp_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'event_id' => get_the_ID() ), $atts, 'persiano_event_rsvp' );
        ob_start();
        self::render_public_interaction( absint( $atts['event_id'] ) );
        return ob_get_clean();
    }

    public static function render_rsvp_form( $event_id ) {
        $meta = self::get_event_meta( $event_id );
        if ( 'upcoming' !== $meta['status'] || 'yes' !== $meta['rsvp_enabled'] ) {
            return;
        }
        $deadline = self::event_deadline_timestamp( $event_id );
        if ( $deadline && time() > $deadline ) {
            echo '<div class="ph-event-rsvp-note"><strong>' . esc_html__( 'RSVPs are now closed.', 'persiano-hub' ) . '</strong></div>';
            return;
        }
        $notice = isset( $_GET['rsvp'] ) ? sanitize_key( wp_unslash( $_GET['rsvp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( in_array( $notice, array( 'confirmed', 'waitlist', 'updated' ), true ) ) {
            $messages = array(
                'confirmed' => __( 'Your place is confirmed. A confirmation email is on its way.', 'persiano-hub' ),
                'waitlist'  => __( 'The event is currently full, so you have been added to the waitlist.', 'persiano-hub' ),
                'updated'   => __( 'Your RSVP has been updated.', 'persiano-hub' ),
            );
            echo '<div class="ph-event-rsvp-message ph-event-rsvp-message--success">' . esc_html( $messages[ $notice ] ) . '</div>';
        } elseif ( 'error' === $notice ) {
            echo '<div class="ph-event-rsvp-message ph-event-rsvp-message--error">' . esc_html__( 'We could not save the RSVP. Please review the form and try again.', 'persiano-hub' ) . '</div>';
        }
        $counts = self::get_rsvp_counts( $event_id );
        $capacity = absint( $meta['capacity'] );
        $remaining = $capacity ? max( 0, $capacity - $counts['confirmed_guests'] ) : 0;
        ?>
        <section class="ph-event-rsvp" id="rsvp">
            <div class="ph-event-rsvp-copy">
                <span class="ph-event-rsvp-eyebrow"><?php esc_html_e( 'Invitation & RSVP', 'persiano-hub' ); ?></span>
                <h2><?php esc_html_e( 'Join the Persiano table.', 'persiano-hub' ); ?></h2>
                <?php if ( $capacity ) : ?><p><?php echo esc_html( $remaining > 0 ? sprintf( _n( '%d guest place remains.', '%d guest places remain.', $remaining, 'persiano-hub' ), $remaining ) : __( 'The confirmed guest list is full; new RSVPs may join the waitlist.', 'persiano-hub' ) ); ?></p><?php endif; ?>
            </div>
            <form class="ph-event-rsvp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="persiano_event_rsvp">
                <input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( get_permalink( $event_id ) ); ?>">
                <?php wp_nonce_field( 'persiano_event_rsvp_' . $event_id, 'persiano_event_rsvp_nonce' ); ?>
                <div class="ph-event-rsvp-grid">
                    <label><span><?php esc_html_e( 'First name', 'persiano-hub' ); ?></span><input type="text" name="first_name" required autocomplete="given-name"></label>
                    <label><span><?php esc_html_e( 'Last name', 'persiano-hub' ); ?></span><input type="text" name="last_name" autocomplete="family-name"></label>
                    <label><span><?php esc_html_e( 'Email', 'persiano-hub' ); ?></span><input type="email" name="email" required autocomplete="email"></label>
                    <label><span><?php esc_html_e( 'Phone', 'persiano-hub' ); ?></span><input type="tel" name="phone" autocomplete="tel"></label>
                    <label><span><?php esc_html_e( 'Total guests including you', 'persiano-hub' ); ?></span><input type="number" name="guest_count" min="1" max="10" value="1" required></label>
                    <label><span><?php esc_html_e( 'Dietary notes', 'persiano-hub' ); ?></span><input type="text" name="dietary_notes" placeholder="Allergies, vegetarian, etc."></label>
                </div>
                <label><span><?php esc_html_e( 'Message (optional)', 'persiano-hub' ); ?></span><textarea name="message" rows="3"></textarea></label>
                <label class="ph-event-rsvp-consent"><input type="checkbox" name="mailing_optin" value="yes"> <span><?php echo esc_html( class_exists( 'Persiano_Hub_Newsletter' ) ? Persiano_Hub_Newsletter::consent_text() : __( 'Send me future Persiano events and menu updates by email.', 'persiano-hub' ) ); ?></span></label>
                <button class="pd-btn" type="submit"><?php esc_html_e( 'Send RSVP', 'persiano-hub' ); ?></button>
            </form>
        </section>
        <?php
    }

    public static function handle_rsvp() {
        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : get_permalink( $event_id );
        if ( ! $event_id || self::POST_TYPE !== get_post_type( $event_id ) || ! isset( $_POST['persiano_event_rsvp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_event_rsvp_nonce'] ) ), 'persiano_event_rsvp_' . $event_id ) ) {
            self::redirect_with( $redirect, 'rsvp', 'error' );
        }
        $meta = self::get_event_meta( $event_id );
        if ( 'upcoming' !== $meta['status'] || 'yes' !== $meta['rsvp_enabled'] || ( self::event_deadline_timestamp( $event_id ) && time() > self::event_deadline_timestamp( $event_id ) ) ) {
            self::redirect_with( $redirect, 'rsvp', 'error' );
        }

        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        if ( ! is_email( $email ) ) {
            self::redirect_with( $redirect, 'rsvp', 'error' );
        }
        $guest_count = isset( $_POST['guest_count'] ) ? min( 10, max( 1, absint( $_POST['guest_count'] ) ) ) : 1;
        global $wpdb;
        $table = self::table_name();
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id=%d AND email=%s", $event_id, $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $counts = self::get_rsvp_counts( $event_id );
        $confirmed_without_existing = $counts['confirmed_guests'];
        if ( $existing && 'confirmed' === $existing->status ) {
            $confirmed_without_existing = max( 0, $confirmed_without_existing - (int) $existing->guest_count );
        }
        $capacity = absint( $meta['capacity'] );
        $fits = ! $capacity || ( $confirmed_without_existing + $guest_count <= $capacity );
        $status = $fits ? 'confirmed' : ( 'yes' === $meta['waitlist_enabled'] ? 'waitlist' : '' );
        if ( ! $status ) {
            self::redirect_with( $redirect, 'rsvp', 'error' );
        }
        $now = current_time( 'mysql' );
        $data = array(
            'event_id'       => $event_id,
            'first_name'     => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
            'last_name'      => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
            'email'          => $email,
            'phone'          => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
            'guest_count'    => $guest_count,
            'status'         => $status,
            'dietary_notes'  => isset( $_POST['dietary_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dietary_notes'] ) ) : '',
            'message'        => isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '',
            'mailing_optin'  => isset( $_POST['mailing_optin'] ) ? 1 : 0,
            'updated_at'     => $now,
        );
        if ( $existing ) {
            $saved = $wpdb->update( $table, $data, array( 'id' => absint( $existing->id ) ) );
            $token = (string) $existing->token;
        } else {
            $data['token'] = wp_generate_password( 48, false, false );
            $data['created_at'] = $now;
            $saved = $wpdb->insert( $table, $data );
            $token = (string) $data['token'];
        }
        if ( false === $saved ) {
            self::redirect_with( $redirect, 'rsvp', 'error' );
        }

        if ( ! empty( $data['mailing_optin'] ) && class_exists( 'Persiano_Hub_Newsletter' ) ) {
            Persiano_Hub_Newsletter::subscribe(
                $email,
                trim( $data['first_name'] . ' ' . $data['last_name'] ),
                'event_rsvp',
                array( 'tags' => 'events', 'notes' => sprintf( 'RSVP: %s', get_the_title( $event_id ) ) )
            );
        }
        self::send_rsvp_confirmation( $event_id, array_merge( $data, array( 'token' => $token ) ) );
        self::maybe_promote_waitlist( $event_id );
        self::redirect_with( $redirect, 'rsvp', $status );
    }

    private static function render_manage_rsvp( $event_id, $token ) {
        $row = self::get_rsvp_by_token( $token );
        if ( ! $row || (int) $row->event_id !== (int) $event_id ) {
            echo '<div class="ph-event-rsvp-message ph-event-rsvp-message--error">' . esc_html__( 'This RSVP link is invalid.', 'persiano-hub' ) . '</div>';
            return;
        }
        $notice = isset( $_GET['rsvp'] ) ? sanitize_key( wp_unslash( $_GET['rsvp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( 'cancelled' === $notice ) {
            echo '<div class="ph-event-rsvp-message ph-event-rsvp-message--success">' . esc_html__( 'Your RSVP has been cancelled.', 'persiano-hub' ) . '</div>';
            return;
        }
        ?>
        <section class="ph-event-rsvp ph-event-rsvp--manage">
            <div><span class="ph-event-rsvp-eyebrow"><?php esc_html_e( 'Your RSVP', 'persiano-hub' ); ?></span><h2><?php echo esc_html( trim( $row->first_name . ' ' . $row->last_name ) ); ?></h2>
            <p><?php echo esc_html( sprintf( __( '%1$d guest(s) · Status: %2$s', 'persiano-hub' ), (int) $row->guest_count, ucfirst( $row->status ) ) ); ?></p></div>
            <?php if ( 'cancelled' !== $row->status ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Cancel this RSVP?');">
                    <input type="hidden" name="action" value="persiano_event_cancel_rsvp"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
                    <?php wp_nonce_field( 'persiano_event_cancel_' . $token, 'persiano_event_cancel_nonce' ); ?>
                    <button class="pd-btn pd-btn--secondary" type="submit"><?php esc_html_e( 'Cancel RSVP', 'persiano-hub' ); ?></button>
                </form>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function handle_cancel_rsvp() {
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $token || ! isset( $_POST['persiano_event_cancel_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_event_cancel_nonce'] ) ), 'persiano_event_cancel_' . $token ) ) {
            wp_die( esc_html__( 'Invalid RSVP request.', 'persiano-hub' ), 403 );
        }
        $row = self::get_rsvp_by_token( $token );
        if ( ! $row || (int) $row->event_id !== $event_id ) {
            wp_die( esc_html__( 'RSVP not found.', 'persiano-hub' ), 404 );
        }
        global $wpdb;
        $was_confirmed = 'confirmed' === $row->status;
        $wpdb->update( self::table_name(), array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => absint( $row->id ) ) );
        if ( $was_confirmed ) {
            self::maybe_promote_waitlist( $event_id );
        }
        wp_safe_redirect( add_query_arg( array( 'manage_rsvp' => rawurlencode( $token ), 'rsvp' => 'cancelled' ), get_permalink( $event_id ) ) );
        exit;
    }

    /**
     * Promote waitlisted guests in RSVP order when confirmed seats become free.
     * A group is never split; the earliest waitlisted group must fit entirely.
     */
    private static function maybe_promote_waitlist( $event_id ) {
        $meta = self::get_event_meta( $event_id );
        $capacity = absint( $meta['capacity'] );
        if ( ! $capacity || 'yes' !== $meta['waitlist_enabled'] ) {
            return 0;
        }

        global $wpdb;
        $table = self::table_name();
        $promoted = 0;

        while ( true ) {
            $counts = self::get_rsvp_counts( $event_id );
            $remaining = max( 0, $capacity - $counts['confirmed_guests'] );
            if ( ! $remaining ) {
                break;
            }

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE event_id=%d AND status='waitlist' ORDER BY created_at ASC, id ASC LIMIT 1",
                    $event_id
                )
            ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            if ( ! $row || (int) $row->guest_count > $remaining ) {
                break;
            }

            $updated = $wpdb->update(
                $table,
                array( 'status' => 'confirmed', 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => absint( $row->id ) )
            );
            if ( false === $updated ) {
                break;
            }

            $row->status = 'confirmed';
            self::send_rsvp_confirmation( $event_id, (array) $row );
            $promoted++;
        }

        return $promoted;
    }

    private static function render_feedback_form( $event_id, $token ) {
        $row = self::get_rsvp_by_token( $token );
        if ( ! $row || (int) $row->event_id !== (int) $event_id ) {
            echo '<div class="ph-event-rsvp-message ph-event-rsvp-message--error">' . esc_html__( 'This feedback link is invalid.', 'persiano-hub' ) . '</div>';
            return;
        }
        if ( isset( $_GET['feedback'] ) && 'thanks' === sanitize_key( wp_unslash( $_GET['feedback'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="ph-event-rsvp-message ph-event-rsvp-message--success">' . esc_html__( 'Thank you for sharing your feedback.', 'persiano-hub' ) . '</div>';
            return;
        }
        ?>
        <section class="ph-event-rsvp">
            <div><span class="ph-event-rsvp-eyebrow"><?php esc_html_e( 'After the event', 'persiano-hub' ); ?></span><h2><?php esc_html_e( 'Tell us how it went.', 'persiano-hub' ); ?></h2></div>
            <form class="ph-event-rsvp-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="persiano_event_feedback"><input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>"><input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
                <?php wp_nonce_field( 'persiano_event_feedback_' . $token, 'persiano_event_feedback_nonce' ); ?>
                <label><span><?php esc_html_e( 'Rating', 'persiano-hub' ); ?></span><select name="rating" required><option value=""><?php esc_html_e( 'Choose', 'persiano-hub' ); ?></option><?php for ( $i = 5; $i >= 1; $i-- ) : ?><option value="<?php echo esc_attr( $i ); ?>" <?php selected( (int) $row->feedback_rating, $i ); ?>><?php echo esc_html( sprintf( _n( '%d star', '%d stars', $i, 'persiano-hub' ), $i ) ); ?></option><?php endfor; ?></select></label>
                <label><span><?php esc_html_e( 'Comments', 'persiano-hub' ); ?></span><textarea name="feedback_text" rows="5"><?php echo esc_textarea( $row->feedback_text ); ?></textarea></label>
                <button class="pd-btn" type="submit"><?php esc_html_e( 'Send feedback', 'persiano-hub' ); ?></button>
            </form>
        </section>
        <?php
    }

    public static function handle_feedback() {
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $token || ! isset( $_POST['persiano_event_feedback_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['persiano_event_feedback_nonce'] ) ), 'persiano_event_feedback_' . $token ) ) {
            wp_die( esc_html__( 'Invalid feedback request.', 'persiano-hub' ), 403 );
        }
        $row = self::get_rsvp_by_token( $token );
        if ( ! $row || (int) $row->event_id !== $event_id ) {
            wp_die( esc_html__( 'RSVP not found.', 'persiano-hub' ), 404 );
        }
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            array(
                'feedback_rating' => min( 5, max( 1, absint( $_POST['rating'] ) ) ),
                'feedback_text'   => isset( $_POST['feedback_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback_text'] ) ) : '',
                'feedback_at'     => current_time( 'mysql' ),
                'updated_at'      => current_time( 'mysql' ),
            ),
            array( 'id' => absint( $row->id ) )
        );
        wp_safe_redirect( add_query_arg( array( 'feedback_token' => rawurlencode( $token ), 'feedback' => 'thanks' ), get_permalink( $event_id ) ) );
        exit;
    }

    private static function get_rsvp_by_token( $token ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE token=%s', sanitize_text_field( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function event_deadline_timestamp( $event_id ) {
        $deadline = self::event_timestamp( $event_id, 'rsvp_deadline' );
        return $deadline ? $deadline : self::event_timestamp( $event_id, 'start' );
    }

    private static function send_rsvp_confirmation( $event_id, $rsvp ) {
        $status = isset( $rsvp['status'] ) ? $rsvp['status'] : 'confirmed';
        $subject = 'confirmed' === $status
            ? sprintf( __( 'RSVP confirmed: %s', 'persiano-hub' ), get_the_title( $event_id ) )
            : sprintf( __( 'Waitlist: %s', 'persiano-hub' ), get_the_title( $event_id ) );
        $manage_url = add_query_arg( 'manage_rsvp', rawurlencode( $rsvp['token'] ), get_permalink( $event_id ) );
        $body = '<p>' . esc_html( sprintf( __( 'Hi %s,', 'persiano-hub' ), $rsvp['first_name'] ) ) . '</p>';
        $body .= '<p>' . esc_html( 'confirmed' === $status ? __( 'Your place at the event is confirmed.', 'persiano-hub' ) : __( 'The confirmed guest list is full, and you have been added to the waitlist.', 'persiano-hub' ) ) . '</p>';
        $body .= self::event_email_details( $event_id );
        $body .= '<p><a href="' . esc_url( $manage_url ) . '">' . esc_html__( 'View or cancel your RSVP', 'persiano-hub' ) . '</a></p>';
        self::send_event_email( $rsvp['email'], $subject, $body );
    }

    private static function send_event_email( $to, $subject, $body ) {
        $subject = html_entity_decode( wp_specialchars_decode( (string) $subject, ENT_QUOTES ), ENT_QUOTES, 'UTF-8' );
        $brand   = function_exists( 'persiano_hub_brand_name' ) ? persiano_hub_brand_name() : ( get_bloginfo( 'name' ) ?: 'Business' );
        $email   = function_exists( 'persiano_hub_support_email' ) ? persiano_hub_support_email() : sanitize_email( get_option( 'admin_email' ) );
        $html    = class_exists( 'Persiano_Hub_Email_Branding' )
            ? Persiano_Hub_Email_Branding::branded_message( $subject, $body, sprintf( __( 'An event update from %s.', 'persiano-hub' ), $brand ) )
            : $body;
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        if ( is_email( $email ) ) {
            $headers[] = 'From: ' . sanitize_text_field( $brand ) . ' <' . $email . '>';
            $headers[] = 'Reply-To: ' . sanitize_text_field( $brand ) . ' <' . $email . '>';
        }
        return wp_mail( sanitize_email( $to ), $subject, $html, $headers );
    }

    private static function event_email_details( $event_id ) {
        $meta = self::get_event_meta( $event_id );
        $html = '<h2 style="font-family:Georgia,serif">' . esc_html( get_the_title( $event_id ) ) . '</h2>';
        $html .= '<p><strong>' . esc_html__( 'When:', 'persiano-hub' ) . '</strong> ' . esc_html( self::event_display_date( $event_id ) ) . '</p>';
        if ( $meta['location'] ) {
            $html .= '<p><strong>' . esc_html__( 'Where:', 'persiano-hub' ) . '</strong> ' . esc_html( $meta['location'] ) . '</p>';
        }
        return $html;
    }

    private static function schedule_messages( $event_id ) {
        wp_clear_scheduled_hook( 'persiano_hub_event_reminder', array( $event_id ) );
        wp_clear_scheduled_hook( 'persiano_hub_event_followup', array( $event_id ) );
        $meta = self::get_event_meta( $event_id );
        if ( 'upcoming' !== $meta['status'] ) {
            return;
        }
        $start = self::event_timestamp( $event_id, 'start' );
        $end = self::event_timestamp( $event_id, 'end' );
        if ( $start ) {
            $reminder = $start - ( absint( $meta['reminder_days'] ) * DAY_IN_SECONDS );
            if ( $reminder > time() ) {
                wp_schedule_single_event( $reminder, 'persiano_hub_event_reminder', array( $event_id ) );
            }
        }
        $follow_base = $end ?: $start;
        if ( $follow_base ) {
            $follow = $follow_base + ( max( 1, absint( $meta['followup_days'] ) ) * DAY_IN_SECONDS );
            if ( $follow > time() ) {
                wp_schedule_single_event( $follow, 'persiano_hub_event_followup', array( $event_id ) );
            }
        }
    }

    public static function send_scheduled_reminder( $event_id ) {
        self::send_group_message( $event_id, 'reminder', false );
    }

    public static function send_scheduled_followup( $event_id ) {
        self::send_group_message( $event_id, 'followup', false );
    }

    public static function send_reminder_admin() {
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id || ! current_user_can( 'edit_post', $event_id ) ) {
            wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_event_send_reminder_' . $event_id );
        $sent = self::send_group_message( $event_id, 'reminder', true );
        self::redirect_event_edit_notice( $event_id, sprintf( __( 'Reminder sent to %d attendee(s).', 'persiano-hub' ), $sent ) );
    }

    public static function send_followup_admin() {
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id || ! current_user_can( 'edit_post', $event_id ) ) {
            wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_event_send_followup_' . $event_id );
        $sent = self::send_group_message( $event_id, 'followup', true );
        self::redirect_event_edit_notice( $event_id, sprintf( __( 'Follow-up sent to %d attendee(s).', 'persiano-hub' ), $sent ) );
    }

    private static function send_group_message( $event_id, $kind, $force ) {
        $meta_key = self::META_PREFIX . $kind . '_sent_at';
        if ( ! $force && get_post_meta( $event_id, $meta_key, true ) ) {
            return 0;
        }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . " WHERE event_id=%d AND status='confirmed' ORDER BY id ASC", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sent = 0;
        foreach ( $rows as $row ) {
            if ( 'reminder' === $kind ) {
                $subject = sprintf( __( 'Reminder: %s', 'persiano-hub' ), get_the_title( $event_id ) );
                $body = '<p>' . esc_html( sprintf( __( 'Hi %s,', 'persiano-hub' ), $row->first_name ) ) . '</p><p>' . esc_html__( 'A quick reminder that the Persiano event is coming up.', 'persiano-hub' ) . '</p>' . self::event_email_details( $event_id );
                $body .= '<p><a href="' . esc_url( add_query_arg( 'manage_rsvp', rawurlencode( $row->token ), get_permalink( $event_id ) ) ) . '">' . esc_html__( 'View your RSVP', 'persiano-hub' ) . '</a></p>';
            } else {
                $subject = sprintf( __( 'Thank you for joining %s', 'persiano-hub' ), get_the_title( $event_id ) );
                $feedback_url = add_query_arg( 'feedback_token', rawurlencode( $row->token ), get_permalink( $event_id ) );
                $body = '<p>' . esc_html( sprintf( __( 'Hi %s,', 'persiano-hub' ), $row->first_name ) ) . '</p><p>' . esc_html__( 'Thank you for joining the Persiano table. Your feedback helps make the next gathering better.', 'persiano-hub' ) . '</p><p><a href="' . esc_url( $feedback_url ) . '">' . esc_html__( 'Share feedback', 'persiano-hub' ) . '</a></p>';
            }
            if ( self::send_event_email( $row->email, $subject, $body ) ) {
                $sent++;
            }
        }
        update_post_meta( $event_id, $meta_key, current_time( 'mysql' ) );
        return $sent;
    }

    public static function create_invitation_campaign() {
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id || ! current_user_can( 'edit_post', $event_id ) ) {
            wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ), 403 );
        }
        check_admin_referer( 'persiano_event_create_campaign_' . $event_id );
        if ( ! class_exists( 'Persiano_Hub_Publishing' ) ) {
            wp_die( esc_html__( 'Publishing Hub is unavailable.', 'persiano-hub' ), 500 );
        }
        $meta = self::get_event_meta( $event_id );
        $content = $meta['invitation_body'] ? $meta['invitation_body'] : ( get_the_excerpt( $event_id ) ?: wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $event_id ) ), 80 ) );
        $campaign_id = wp_insert_post(
            array(
                'post_type'    => Persiano_Hub_Publishing::POST_TYPE,
                'post_status'  => 'draft',
                'post_title'   => sprintf( __( 'Invitation — %s', 'persiano-hub' ), get_the_title( $event_id ) ),
                'post_content' => $content,
            )
        );
        if ( is_wp_error( $campaign_id ) || ! $campaign_id ) {
            wp_die( esc_html__( 'The invitation campaign could not be created.', 'persiano-hub' ), 500 );
        }
        update_post_meta( $campaign_id, '_ph_pub_type', 'event' );
        update_post_meta( $campaign_id, '_ph_pub_channels', array( 'email', 'instagram', 'telegram' ) );
        update_post_meta( $campaign_id, '_ph_pub_cta_url', get_permalink( $event_id ) );
        update_post_meta( $campaign_id, '_ph_pub_event_start', $meta['start'] );
        update_post_meta( $campaign_id, '_ph_pub_event_end', $meta['end'] );
        update_post_meta( $campaign_id, '_ph_pub_email_subject', $meta['invitation_subject'] ?: sprintf( __( 'You’re invited: %s', 'persiano-hub' ), get_the_title( $event_id ) ) );
        update_post_meta( $campaign_id, '_ph_pub_email_template', 'event' );
        update_post_meta( $campaign_id, '_ph_pub_event_id', $event_id );
        $thumbnail = get_post_thumbnail_id( $event_id );
        if ( $thumbnail ) {
            set_post_thumbnail( $campaign_id, $thumbnail );
        }
        wp_safe_redirect( get_edit_post_link( $campaign_id, 'url' ) );
        exit;
    }

    public static function render_rsvp_admin() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ), 403 );
        }
        global $wpdb;
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $events = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC' ) );
        $where = '1=1';
        $params = array();
        if ( $event_id ) { $where .= ' AND event_id=%d'; $params[] = $event_id; }
        if ( in_array( $status, array( 'confirmed', 'waitlist', 'cancelled' ), true ) ) { $where .= ' AND status=%s'; $params[] = $status; }
        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . $where . ' ORDER BY created_at DESC';
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $notice = isset( $_GET['ph_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['ph_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap"><h1><?php esc_html_e( 'Event RSVPs', 'persiano-hub' ); ?></h1>
        <?php if ( $notice ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
        <form method="get" style="margin:14px 0"><input type="hidden" name="page" value="persiano-event-rsvps"><select name="event_id"><option value="0"><?php esc_html_e( 'All events', 'persiano-hub' ); ?></option><?php foreach ( $events as $event ) : ?><option value="<?php echo esc_attr( $event->ID ); ?>" <?php selected( $event_id, $event->ID ); ?>><?php echo esc_html( $event->post_title ); ?></option><?php endforeach; ?></select> <select name="status"><option value=""><?php esc_html_e( 'All statuses', 'persiano-hub' ); ?></option><option value="confirmed" <?php selected( $status, 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'persiano-hub' ); ?></option><option value="waitlist" <?php selected( $status, 'waitlist' ); ?>><?php esc_html_e( 'Waitlist', 'persiano-hub' ); ?></option><option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'persiano-hub' ); ?></option></select> <button class="button"><?php esc_html_e( 'Filter', 'persiano-hub' ); ?></button>
        <?php if ( $event_id ) : ?><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=persiano_event_export_rsvps&event_id=' . $event_id ), 'persiano_event_export_' . $event_id ) ); ?>"><?php esc_html_e( 'Export CSV', 'persiano-hub' ); ?></a><?php endif; ?></form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="persiano_event_rsvp_bulk"><input type="hidden" name="return_event_id" value="<?php echo esc_attr( $event_id ); ?>"><?php wp_nonce_field( 'persiano_event_rsvp_bulk' ); ?>
        <div style="margin:10px 0"><select name="bulk_action"><option value=""><?php esc_html_e( 'Bulk actions', 'persiano-hub' ); ?></option><option value="confirm"><?php esc_html_e( 'Confirm', 'persiano-hub' ); ?></option><option value="waitlist"><?php esc_html_e( 'Move to waitlist', 'persiano-hub' ); ?></option><option value="cancel"><?php esc_html_e( 'Cancel', 'persiano-hub' ); ?></option><option value="checkin"><?php esc_html_e( 'Mark checked in', 'persiano-hub' ); ?></option><option value="uncheckin"><?php esc_html_e( 'Remove check-in', 'persiano-hub' ); ?></option><option value="delete"><?php esc_html_e( 'Delete permanently', 'persiano-hub' ); ?></option></select> <button class="button"><?php esc_html_e( 'Apply', 'persiano-hub' ); ?></button></div>
        <table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.ph-rsvp-check').forEach(c=>c.checked=this.checked)"></td><th><?php esc_html_e( 'Guest', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Event', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Seats', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Status', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Contact', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Dietary / message', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Check-in', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Feedback', 'persiano-hub' ); ?></th></tr></thead><tbody>
        <?php if ( ! $rows ) : ?><tr><td colspan="9"><?php esc_html_e( 'No RSVPs match this view.', 'persiano-hub' ); ?></td></tr><?php endif; ?>
        <?php foreach ( $rows as $row ) : ?><tr><th class="check-column"><input class="ph-rsvp-check" type="checkbox" name="rsvp_ids[]" value="<?php echo esc_attr( $row->id ); ?>"></th><td><strong><?php echo esc_html( trim( $row->first_name . ' ' . $row->last_name ) ); ?></strong><br><small><?php echo esc_html( $row->created_at ); ?></small></td><td><?php echo esc_html( get_the_title( $row->event_id ) ); ?></td><td><?php echo esc_html( $row->guest_count ); ?></td><td><?php echo esc_html( ucfirst( $row->status ) ); ?></td><td><a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a><br><?php echo esc_html( $row->phone ); ?></td><td><?php echo esc_html( trim( $row->dietary_notes . ( $row->message ? ' · ' . $row->message : '' ) ) ); ?></td><td><?php echo $row->checked_in ? esc_html__( 'Checked in', 'persiano-hub' ) : '—'; ?></td><td><?php echo $row->feedback_rating ? esc_html( $row->feedback_rating . '/5 · ' . $row->feedback_text ) : '—'; ?></td></tr><?php endforeach; ?>
        </tbody></table></form></div>
        <?php
    }

    public static function handle_admin_rsvp_actions() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ), 403 ); }
        check_admin_referer( 'persiano_event_rsvp_bulk' );
        $ids = isset( $_POST['rsvp_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['rsvp_ids'] ) ) ) : array();
        $action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
        $event_id = isset( $_POST['return_event_id'] ) ? absint( $_POST['return_event_id'] ) : 0;
        global $wpdb;
        $changed = 0;
        $affected_events = array();
        foreach ( $ids as $id ) {
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT event_id, status FROM ' . self::table_name() . ' WHERE id=%d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ( $row ) {
                $affected_events[] = absint( $row->event_id );
            }
            if ( 'delete' === $action ) { $changed += (int) $wpdb->delete( self::table_name(), array( 'id' => $id ) ); continue; }
            $data = array( 'updated_at' => current_time( 'mysql' ) );
            if ( 'confirm' === $action ) { $data['status'] = 'confirmed'; }
            elseif ( 'waitlist' === $action ) { $data['status'] = 'waitlist'; }
            elseif ( 'cancel' === $action ) { $data['status'] = 'cancelled'; }
            elseif ( 'checkin' === $action ) { $data['checked_in'] = 1; }
            elseif ( 'uncheckin' === $action ) { $data['checked_in'] = 0; }
            else { continue; }
            $changed += (int) $wpdb->update( self::table_name(), $data, array( 'id' => $id ) );
        }
        if ( in_array( $action, array( 'delete', 'waitlist', 'cancel' ), true ) ) {
            foreach ( array_unique( $affected_events ) as $affected_event_id ) {
                self::maybe_promote_waitlist( $affected_event_id );
            }
        }
        $url = admin_url( 'admin.php?page=persiano-event-rsvps' . ( $event_id ? '&event_id=' . $event_id : '' ) );
        wp_safe_redirect( add_query_arg( 'ph_notice', rawurlencode( sprintf( __( '%d RSVP record(s) updated.', 'persiano-hub' ), $changed ) ), $url ) );
        exit;
    }

    public static function export_rsvps() {
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id || ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Permission denied.', 'persiano-hub' ), 403 ); }
        check_admin_referer( 'persiano_event_export_' . $event_id );
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE event_id=%d ORDER BY created_at ASC', $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="persiano-event-' . $event_id . '-rsvps.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'First name', 'Last name', 'Email', 'Phone', 'Guests', 'Status', 'Dietary notes', 'Message', 'Mailing opt-in', 'Checked in', 'Rating', 'Feedback', 'Created' ) );
        foreach ( $rows as $row ) {
            fputcsv( $out, array( $row['first_name'], $row['last_name'], $row['email'], $row['phone'], $row['guest_count'], $row['status'], $row['dietary_notes'], $row['message'], $row['mailing_optin'], $row['checked_in'], $row['feedback_rating'], $row['feedback_text'], $row['created_at'] ) );
        }
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    public static function event_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['persiano_event_date'] = __( 'Event date', 'persiano-hub' );
                $new['persiano_event_status'] = __( 'Event status', 'persiano-hub' );
                $new['persiano_event_rsvps'] = __( 'RSVPs', 'persiano-hub' );
            }
        }
        return $new;
    }

    public static function render_event_column( $column, $post_id ) {
        if ( 'persiano_event_date' === $column ) { echo esc_html( self::event_display_date( $post_id ) ); }
        elseif ( 'persiano_event_status' === $column ) { echo esc_html( ucfirst( (string) get_post_meta( $post_id, self::META_PREFIX . 'status', true ) ) ); }
        elseif ( 'persiano_event_rsvps' === $column ) { $counts = self::get_rsvp_counts( $post_id ); echo '<a href="' . esc_url( admin_url( 'admin.php?page=persiano-event-rsvps&event_id=' . $post_id ) ) . '">' . esc_html( sprintf( __( '%1$d confirmed · %2$d waitlist', 'persiano-hub' ), $counts['confirmed_guests'], $counts['waitlist_guests'] ) ) . '</a>'; }
    }

    public static function maybe_seed_tasting_event() {
        if ( get_option( 'persiano_hub_seed_tasting_event_1_v020', false ) || ! post_type_exists( self::POST_TYPE ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $brand = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' );
        if ( 'Persiano Dish' !== $brand ) {
            update_option( 'persiano_hub_seed_tasting_event_1_v020', 'skipped_for_white_label_site', false );
            return;
        }
        $existing_posts = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'title'          => 'Persiano Tasting Event 1',
                'fields'         => 'ids',
            )
        );
        if ( $existing_posts ) {
            update_option( 'persiano_hub_seed_tasting_event_1_v020', absint( $existing_posts[0] ) );
            return;
        }
        $event_id = wp_insert_post(
            array(
                'post_type'    => self::POST_TYPE,
                'post_status'  => 'publish',
                'post_title'   => 'Persiano Tasting Event 1',
                'post_excerpt' => 'A warm West Vancouver tasting that introduced guests to a table of small-batch Persian dishes.',
                'post_content' => '<p>Persiano Tasting Event 1 brought together approximately 20–25 guests for an afternoon built around tasting, conversation and homemade Persian food.</p><p>The gathering was an early opportunity to present Persiano Dish as more than a weekly menu: a table where people could discover flavours, ask questions and experience the food in a relaxed setting.</p>',
                'post_date'    => '2026-06-21 12:00:00',
            )
        );
        if ( is_wp_error( $event_id ) || ! $event_id ) {
            return;
        }
        $seed_meta = array(
            'status' => 'completed', 'type' => 'tasting', 'start' => '2026-06-21T00:00', 'end' => '2026-06-21T00:00', 'all_day' => 'yes',
            'location' => 'West Vancouver, BC', 'capacity' => 0, 'rsvp_enabled' => 'no', 'waitlist_enabled' => 'no', 'reminder_days' => 2, 'followup_days' => 1,
            'report_summary' => 'The first Persiano tasting welcomed approximately 20–25 guests and generated strong interest in the food, pantry items and future Persiano gatherings. The event created direct conversations with guests and helped validate the small-batch tasting format.',
            'menu' => "Tahchin — Persian saffron rice cake\nDolmeh — stuffed grape leaves\nPersian tasting bites and seasonal accompaniments",
            'highlights' => "A relaxed outdoor presentation in West Vancouver\nA varied tasting table with individual bites and shared dishes\nDirect guest feedback and follow-up interest\nApproximately $400 in event sales",
            'actual_guests' => 23, 'sales' => '400.00',
            'worked_well' => 'The outdoor presentation, variety of tasting portions and direct conversations with guests made the experience warm and approachable.',
            'lessons' => 'For the next tasting, prepare a clearer guest check-in list, stronger menu signage, a dedicated ordering station and a planned photo checklist.',
            'invitation_subject' => 'You’re invited to the Persiano table',
            'invitation_body' => 'Join Persiano Dish for a small tasting of Persian favourites, prepared in small batches and served in a relaxed gathering.',
        );
        foreach ( $seed_meta as $key => $value ) {
            update_post_meta( $event_id, self::META_PREFIX . $key, $value );
        }
        $gallery = self::import_seed_event_images( $event_id );
        if ( $gallery ) {
            update_post_meta( $event_id, self::META_PREFIX . 'gallery_ids', $gallery );
            set_post_thumbnail( $event_id, $gallery[0] );
        }
        update_option( 'persiano_hub_seed_tasting_event_1_v020', $event_id );
        flush_rewrite_rules( false );
    }

    private static function import_seed_event_images( $event_id ) {
        $files = array(
            'persiano-tasting-event-1-tahchin.jpg' => 'Persiano Tasting Event 1 — Tahchin',
            'persiano-tasting-event-1-dolmeh.jpg' => 'Persiano Tasting Event 1 — Dolmeh platter',
            'persiano-tasting-event-1-bites.jpg' => 'Persiano Tasting Event 1 — Persian tasting bites',
            'persiano-tasting-event-1-saffron-rice.jpg' => 'Persiano Tasting Event 1 — Saffron rice presentation',
        );
        $ids = array();
        require_once ABSPATH . 'wp-admin/includes/image.php';
        foreach ( $files as $filename => $caption ) {
            $source = PERSIANO_HUB_PATH . 'assets/events/' . $filename;
            if ( ! file_exists( $source ) ) { continue; }
            $contents = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( false === $contents ) { continue; }
            $upload = wp_upload_bits( $filename, null, $contents );
            if ( ! empty( $upload['error'] ) ) { continue; }
            $attachment_id = wp_insert_attachment(
                array(
                    'post_mime_type' => 'image/jpeg',
                    'post_title'     => sanitize_text_field( $caption ),
                    'post_excerpt'   => sanitize_text_field( $caption ),
                    'post_status'    => 'inherit',
                    'post_parent'    => $event_id,
                ),
                $upload['file'],
                $event_id
            );
            if ( ! is_wp_error( $attachment_id ) ) {
                $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
                wp_update_attachment_metadata( $attachment_id, $metadata );
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', $caption );
                $ids[] = (int) $attachment_id;
            }
        }
        return $ids;
    }

    private static function sanitize_datetime_local( $value ) {
        $value = sanitize_text_field( (string) $value );
        return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
    }

    private static function redirect_with( $url, $key, $value ) {
        wp_safe_redirect( add_query_arg( $key, $value, $url ?: home_url( '/' ) ) );
        exit;
    }

    private static function redirect_event_edit_notice( $event_id, $message ) {
        wp_safe_redirect( add_query_arg( 'ph_event_notice', rawurlencode( $message ), get_edit_post_link( $event_id, 'url' ) ) );
        exit;
    }
}
