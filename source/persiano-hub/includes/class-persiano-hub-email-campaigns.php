<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Persiano_Hub_Email_Campaigns {
    const OPTION_DRAFTS = 'persiano_hub_email_campaign_drafts';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 42 );
        add_action( 'admin_post_persiano_email_campaign_save', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_persiano_email_campaign_test', array( __CLASS__, 'handle_test' ) );
        add_action( 'admin_post_persiano_email_campaign_send', array( __CLASS__, 'handle_send' ) );
    }

    public static function admin_menu() {
        add_submenu_page(
            'persiano-hub',
            __( 'Email Campaigns', 'persiano-hub' ),
            __( 'Email Campaigns', 'persiano-hub' ),
            'manage_woocommerce',
            'persiano-email-campaigns',
            array( __CLASS__, 'render_page' )
        );
    }

    private static function templates() {
        $brand = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' );
        $brand = $brand ? $brand : __( 'Our business', 'persiano-hub' );

        return array(
            'limited' => array(
                'label' => __( 'Limited Availability', 'persiano-hub' ),
                'subject' => __( 'A few small-batch items are still available', 'persiano-hub' ),
                'intro' => __( 'We have a few small-batch items still available. Quantities are limited and ordering closes when they sell out.', 'persiano-hub' ),
            ),
            'this_week' => array(
                'label' => __( 'This Week', 'persiano-hub' ),
                'subject' => sprintf( __( 'This week at %s', 'persiano-hub' ), $brand ),
                'intro' => __( 'Here is what we are cooking this week. Order before the listed deadline for pickup or delivery.', 'persiano-hub' ),
            ),
            'pantry' => array(
                'label' => __( 'Pantry Restock', 'persiano-hub' ),
                'subject' => sprintf( __( 'The %s pantry has been restocked', 'persiano-hub' ), $brand ),
                'intro' => __( 'A fresh batch of pantry favourites is ready. Choose several items and add them to your next order.', 'persiano-hub' ),
            ),
            'mixed' => array(
                'label' => __( 'Mixed Update', 'persiano-hub' ),
                'subject' => sprintf( __( 'What is new at %s', 'persiano-hub' ), $brand ),
                'intro' => sprintf( __( 'A quick update with current products, customer favourites and other news from %s.', 'persiano-hub' ), $brand ),
            ),
        );
    }

    private static function current_data() {
        $templates = self::templates();
        $template = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( $_GET['template'] ) ) : 'limited'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $templates[ $template ] ) ) { $template = 'limited'; }
        $draft_id = isset( $_GET['draft'] ) ? sanitize_key( wp_unslash( $_GET['draft'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $drafts = get_option( self::OPTION_DRAFTS, array() );
        if ( $draft_id && isset( $drafts[ $draft_id ] ) && is_array( $drafts[ $draft_id ] ) ) {
            return $drafts[ $draft_id ];
        }
        return array(
            'id' => '',
            'template' => $template,
            'name' => '',
            'subject' => $templates[ $template ]['subject'],
            'preview_text' => '',
            'intro' => $templates[ $template ]['intro'],
            'product_ids' => array(),
            'quantity_notes' => array(),
            'button_labels' => array(),
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $data = self::current_data();
        $templates = self::templates();
        $products = wc_get_products( array( 'limit' => 200, 'status' => array( 'publish', 'draft' ), 'orderby' => 'name', 'order' => 'ASC' ) );
        $drafts = get_option( self::OPTION_DRAFTS, array() );
        $notice = isset( $_GET['ph_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['ph_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Email Campaigns', 'persiano-hub' ); ?></h1>
            <p><?php esc_html_e( 'Build one semi-custom email with several products, save it as a draft, preview it and send a test before mailing the list.', 'persiano-hub' ); ?></p>
            <?php if ( $notice ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
            <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:20px;align-items:start">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ph-email-campaign-form">
                    <?php wp_nonce_field( 'persiano_email_campaign' ); ?>
                    <input type="hidden" name="draft_id" value="<?php echo esc_attr( $data['id'] ?? '' ); ?>">
                    <div class="postbox"><div class="inside">
                        <p><label><strong><?php esc_html_e( 'Format', 'persiano-hub' ); ?></strong><br><select name="template" style="min-width:280px"><?php foreach ( $templates as $key => $template ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $data['template'], $key ); ?>><?php echo esc_html( $template['label'] ); ?></option><?php endforeach; ?></select></label></p>
                        <p><label><strong><?php esc_html_e( 'Draft name', 'persiano-hub' ); ?></strong><br><input class="widefat" type="text" name="campaign_name" value="<?php echo esc_attr( $data['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Tuesday limited availability', 'persiano-hub' ); ?>"></label></p>
                        <p><label><strong><?php esc_html_e( 'Subject', 'persiano-hub' ); ?></strong><br><input class="widefat" type="text" name="subject" required value="<?php echo esc_attr( $data['subject'] ?? '' ); ?>"></label></p>
                        <p><label><strong><?php esc_html_e( 'Preview text', 'persiano-hub' ); ?></strong><br><input class="widefat" type="text" name="preview_text" value="<?php echo esc_attr( $data['preview_text'] ?? '' ); ?>"></label></p>
                        <p><label><strong><?php esc_html_e( 'Opening message', 'persiano-hub' ); ?></strong><br><textarea class="widefat" rows="5" name="intro"><?php echo esc_textarea( $data['intro'] ?? '' ); ?></textarea></label></p>
                    </div></div>
                    <div class="postbox"><div class="inside">
                        <h2><?php esc_html_e( 'Products in this email', 'persiano-hub' ); ?></h2>
                        <p><?php esc_html_e( 'Select several products. You can add a campaign-only quantity note and button label without changing the product itself.', 'persiano-hub' ); ?></p>
                        <table class="widefat striped"><thead><tr><th style="width:40px"></th><th><?php esc_html_e( 'Product', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Quantity / availability note', 'persiano-hub' ); ?></th><th><?php esc_html_e( 'Button label', 'persiano-hub' ); ?></th></tr></thead><tbody>
                        <?php foreach ( $products as $product ) : $id=$product->get_id(); $checked=in_array($id,array_map('absint',(array)($data['product_ids']??array())),true); ?>
                            <tr><td><input type="checkbox" name="product_ids[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $checked ); ?>></td><td><strong><?php echo esc_html( $product->get_name() ); ?></strong><br><small><?php echo wp_kses_post( $product->get_price_html() ); ?></small></td><td><input class="widefat" type="text" name="quantity_notes[<?php echo esc_attr($id); ?>]" value="<?php echo esc_attr( $data['quantity_notes'][$id] ?? '' ); ?>" placeholder="3 portions left"></td><td><input class="widefat" type="text" name="button_labels[<?php echo esc_attr($id); ?>]" value="<?php echo esc_attr( $data['button_labels'][$id] ?? '' ); ?>" placeholder="Order now"></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    </div></div>
                    <p>
                        <button class="button button-primary" name="action" value="persiano_email_campaign_save"><?php esc_html_e( 'Save draft', 'persiano-hub' ); ?></button>
                        <button class="button" name="action" value="persiano_email_campaign_test"><?php esc_html_e( 'Send test to me', 'persiano-hub' ); ?></button>
                        <button class="button" name="action" value="persiano_email_campaign_send" onclick="return confirm('Send this campaign to all active subscribers now?');"><?php esc_html_e( 'Send to active list', 'persiano-hub' ); ?></button>
                    </p>
                </form>
                <aside>
                    <div class="postbox"><div class="inside"><h2><?php esc_html_e( 'Saved drafts', 'persiano-hub' ); ?></h2><?php if ( $drafts ) : ?><ul><?php foreach ( array_reverse( $drafts, true ) as $id => $draft ) : ?><li><a href="<?php echo esc_url( admin_url( 'admin.php?page=persiano-email-campaigns&draft=' . rawurlencode( $id ) ) ); ?>"><?php echo esc_html( $draft['name'] ?: $draft['subject'] ); ?></a></li><?php endforeach; ?></ul><?php else : ?><p><?php esc_html_e( 'No drafts yet.', 'persiano-hub' ); ?></p><?php endif; ?></div></div>
                    <div class="postbox"><div class="inside"><h2><?php esc_html_e( 'Included formats', 'persiano-hub' ); ?></h2><ul><?php foreach($templates as $t): ?><li><?php echo esc_html($t['label']); ?></li><?php endforeach; ?></ul></div></div>
                </aside>
            </div>
        </div>
        <?php
    }

    private static function posted_data() {
        $templates = self::templates();
        $template = isset( $_POST['template'] ) ? sanitize_key( wp_unslash( $_POST['template'] ) ) : 'limited';
        if ( ! isset( $templates[ $template ] ) ) { $template='limited'; }
        $ids = isset( $_POST['product_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ) ) ) ) : array();
        $quantity_notes = array();
        foreach ( (array) ( $_POST['quantity_notes'] ?? array() ) as $id => $value ) { $quantity_notes[absint($id)] = sanitize_text_field( wp_unslash($value) ); }
        $button_labels = array();
        foreach ( (array) ( $_POST['button_labels'] ?? array() ) as $id => $value ) { $button_labels[absint($id)] = sanitize_text_field( wp_unslash($value) ); }
        return array(
            'id' => isset($_POST['draft_id']) ? sanitize_key(wp_unslash($_POST['draft_id'])) : '',
            'template' => $template,
            'name' => isset($_POST['campaign_name']) ? sanitize_text_field(wp_unslash($_POST['campaign_name'])) : '',
            'subject' => isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '',
            'preview_text' => isset($_POST['preview_text']) ? sanitize_text_field(wp_unslash($_POST['preview_text'])) : '',
            'intro' => isset($_POST['intro']) ? sanitize_textarea_field(wp_unslash($_POST['intro'])) : '',
            'product_ids' => $ids,
            'quantity_notes' => $quantity_notes,
            'button_labels' => $button_labels,
            'updated_at' => current_time('mysql'),
        );
    }

    private static function save_draft( $data ) {
        $drafts = get_option( self::OPTION_DRAFTS, array() );
        $id = $data['id'] ?: 'campaign_' . wp_generate_password( 8, false, false );
        $data['id']=$id;
        $drafts[$id]=$data;
        update_option( self::OPTION_DRAFTS, $drafts, false );
        return $id;
    }

    public static function handle_save() {
        self::guard();
        $id=self::save_draft(self::posted_data());
        self::redirect( __( 'Campaign draft saved.', 'persiano-hub' ), $id );
    }

    public static function handle_test() {
        self::guard();
        $data=self::posted_data(); $id=self::save_draft($data);
        $email=wp_get_current_user()->user_email;
        $sent=self::send_one($email,wp_get_current_user()->display_name,$data);
        self::redirect($sent?__( 'Test email sent.', 'persiano-hub' ):__( 'The test email could not be sent.', 'persiano-hub' ),$id);
    }

    public static function handle_send() {
        self::guard();
        $data=self::posted_data(); $id=self::save_draft($data);
        $rows=Persiano_Hub_Newsletter::get_active_subscribers(); $sent=0;
        foreach($rows as $row){ if(self::send_one($row->email,$row->name,$data)){ $sent++; } }
        self::redirect(sprintf(__( 'Campaign sent to %d active subscribers.', 'persiano-hub' ),$sent),$id);
    }

    private static function guard() {
        if(!current_user_can('manage_woocommerce')){ wp_die(esc_html__('You do not have permission to send campaigns.','persiano-hub'),403); }
        check_admin_referer('persiano_email_campaign');
    }

    private static function redirect($message,$id='') {
        wp_safe_redirect(add_query_arg(array('page'=>'persiano-email-campaigns','draft'=>$id,'ph_notice'=>$message),admin_url('admin.php'))); exit;
    }

    private static function send_one( $email, $name, $data ) {
        if ( ! is_email( $email ) || empty( $data['subject'] ) ) {
            return false;
        }

        $brand = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' );
        $from  = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::support_email() : get_option( 'admin_email' );
        $html  = self::render_email( $data, $email, $name );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        if ( is_email( $from ) ) {
            $headers[] = 'From: ' . $brand . ' <' . $from . '>';
            $headers[] = 'Reply-To: ' . $brand . ' <' . $from . '>';
        }

        return wp_mail( $email, $data['subject'], $html, $headers );
    }

    private static function render_email( $data, $email, $name ) {
        $brand        = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::brand_name() : get_bloginfo( 'name' );
        $tagline      = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::tagline() : get_bloginfo( 'description' );
        $service_area = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::service_area() : '';
        $website      = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::website_url() : home_url( '/' );
        $logo         = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::logo_url() : '';
        $primary      = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'primary_color', '#8e2435' ) : '#8e2435';
        $dark         = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'dark_color', '#2f231d' ) : '#2f231d';
        $background   = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'background_color', '#f8f3e9' ) : '#f8f3e9';
        $surface      = class_exists( 'Persiano_Hub_Business_Profile' ) ? Persiano_Hub_Business_Profile::color( 'surface_color', '#fffaf2' ) : '#fffaf2';
        $brand        = $brand ? $brand : __( 'Our business', 'persiano-hub' );
        $cards        = '';

        foreach ( (array) $data['product_ids'] as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) {
                continue;
            }
            $image       = wp_get_attachment_image_url( $product->get_image_id(), 'medium' );
            $description = wp_trim_words( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ), 28 );
            $note        = $data['quantity_notes'][ $id ] ?? '';
            $button      = $data['button_labels'][ $id ] ?? __( 'View product', 'persiano-hub' );

            $cards .= '<tr><td style="padding:20px;border:1px solid #eadfce;border-radius:14px;background:#ffffff">';
            if ( $image ) {
                $cards .= '<img src="' . esc_url( $image ) . '" alt="" style="display:block;width:100%;max-width:520px;height:auto;border-radius:10px">';
            }
            $cards .= '<h2 style="font-family:Georgia,serif;margin:16px 0 6px;color:' . esc_attr( $dark ) . '">' . esc_html( $product->get_name() ) . '</h2>';
            if ( $note ) {
                $cards .= '<p style="font-weight:700;color:' . esc_attr( $primary ) . '">' . esc_html( $note ) . '</p>';
            }
            if ( $description ) {
                $cards .= '<p style="line-height:1.65;color:#55483f">' . esc_html( $description ) . '</p>';
            }
            $cards .= '<p style="font-size:18px;font-weight:700">' . wp_kses_post( $product->get_price_html() ) . '</p>';
            $cards .= '<p><a href="' . esc_url( $product->get_permalink() ) . '" style="display:inline-block;background:' . esc_attr( $primary ) . ';color:#fff;text-decoration:none;padding:12px 22px;border-radius:24px;font-weight:700">' . esc_html( $button ?: __( 'Order now', 'persiano-hub' ) ) . '</a></p></td></tr><tr><td style="height:16px"></td></tr>';
        }

        $unsubscribe = Persiano_Hub_Newsletter::get_unsubscribe_url( $email );
        $greeting    = $name ? sprintf( __( 'Hello %s,', 'persiano-hub' ), $name ) : __( 'Hello,', 'persiano-hub' );
        $footer_bits = array_filter( array( $tagline, $service_area ) );
        $footer      = $footer_bits ? implode( ' · ', $footer_bits ) : $brand;
        $logo_html   = $logo ? '<p style="margin:0 0 14px"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $brand ) . '" style="display:block;max-width:150px;max-height:72px;width:auto;height:auto"></p>' : '';

        return '<!doctype html><html><body style="margin:0;background:' . esc_attr( $background ) . ';font-family:Arial,sans-serif;color:' . esc_attr( $dark ) . '"><span style="display:none;max-height:0;overflow:hidden">' . esc_html( $data['preview_text'] ) . '</span><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:' . esc_attr( $surface ) . ';border-radius:18px;overflow:hidden"><tr><td style="padding:28px 30px 18px">' . $logo_html . '<h1 style="font-family:Georgia,serif;color:' . esc_attr( $primary ) . ';margin:0 0 20px">' . esc_html( $brand ) . '</h1><p style="font-size:16px">' . esc_html( $greeting ) . '</p><p style="font-size:17px;line-height:1.7">' . nl2br( esc_html( $data['intro'] ) ) . '</p></td></tr><tr><td style="padding:0 30px">' . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $cards . '</table></td></tr><tr><td style="padding:22px 30px;background:' . esc_attr( $dark ) . ';font-size:12px;line-height:1.6;color:#fff"><p style="margin:0 0 8px"><a href="' . esc_url( $website ) . '" style="color:#fff;text-decoration:none;font-weight:700">' . esc_html( $brand ) . '</a> · ' . esc_html( $footer ) . '</p><p style="margin:0"><a href="' . esc_url( $unsubscribe ) . '" style="color:#fff">' . esc_html__( 'Unsubscribe', 'persiano-hub' ) . '</a></p></td></tr></table></td></tr></table></body></html>';
    }
}
