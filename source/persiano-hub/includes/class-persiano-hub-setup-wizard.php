<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Guided first-run configuration and service credential handbook. */
class Persiano_Hub_Setup_Wizard {
    const PAGE = 'persiano-hub-setup-wizard';
    const OPTION = 'persiano_hub_setup_wizard';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 57 );
        add_action( 'admin_post_persiano_hub_save_setup_wizard', array( __CLASS__, 'save' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
    }

    public static function activate() {
        if ( ! get_option( self::OPTION ) ) {
            update_option( self::OPTION, array( 'completed' => 'no', 'step' => 1 ), false );
        }
    }

    public static function menu() {
        add_submenu_page( 'persiano-hub', 'Setup Wizard', 'Setup Wizard', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
    }

    public static function assets( $hook ) {
        if ( false === strpos( (string) $hook, self::PAGE ) ) { return; }
        wp_enqueue_media();
        wp_add_inline_style( 'wp-admin', self::css() );
        wp_add_inline_script( 'jquery-core', self::js() );
    }

    private static function settings() {
        return wp_parse_args( get_option( self::OPTION, array() ), array(
            'completed'=>'no','step'=>1,'instagram_url'=>'','facebook_url'=>'','tiktok_url'=>'','whatsapp'=>'','telegram_url'=>'',
            'business_hours'=>'','lead_time'=>'','minimum_order'=>'0','currency'=>'CAD','fulfilment'=>'pickup,delivery',
            'smtp_host'=>'','smtp_port'=>'587','smtp_encryption'=>'tls','smtp_username'=>'','smtp_password'=>'',
            'quickbooks_client_id'=>'','quickbooks_client_secret'=>'','quickbooks_environment'=>'sandbox',
            'github_repository'=>'','update_channel'=>'beta','installation_key'=>'',
        ) );
    }

    public static function notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) || isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) { return; }
        $s = self::settings();
        if ( 'yes' === $s['completed'] ) { return; }
        echo '<div class="notice notice-info"><p><strong>Batchly setup is not finished.</strong> <a class="button button-primary" href="'.esc_url( admin_url( 'admin.php?page='.self::PAGE ) ).'">Open Setup Wizard</a></p></div>';
    }

    private static function field( $name, $label, $value='', $type='text', $help='' ) {
        echo '<label class="phw-field"><span>'.esc_html( $label ).'</span><input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'" autocomplete="'.('password'===$type?'new-password':'off').'">';
        if ( $help ) echo '<small>'.wp_kses_post($help).'</small>';
        echo '</label>';
    }

    private static function guide( $title, $steps, $url='' ) {
        echo '<details class="phw-guide"><summary>'.esc_html($title).'</summary><ol>';
        foreach ( $steps as $step ) echo '<li>'.wp_kses_post($step).'</li>';
        echo '</ol>';
        if ($url) echo '<p><a class="button" href="'.esc_url($url).'" target="_blank" rel="noopener">Open official service page ↗</a></p>';
        echo '</details>';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $s = self::settings();
        $p = class_exists('Persiano_Hub_Business_Profile') ? Persiano_Hub_Business_Profile::get_profile() : array();
        $m = get_option('persiano_hub_messages_settings', array());
        $sq = get_option('persiano_hub_frontend_pos_settings', array());
        $pub = get_option('persiano_hub_publishing_connections', array());
        $step = max(1,min(8,absint($_GET['step'] ?? $s['step'])));
        $steps = array(1=>'Identity',2=>'Contact & social',3=>'Store',4=>'Email & SMS',5=>'Payments & accounting',6=>'Publishing',7=>'Updates & access',8=>'Review');
        echo '<div class="wrap phw"><h1>Business Setup Wizard</h1><p>Complete the business workspace in one guided flow. You can skip any service and return later.</p><nav class="phw-steps">';
        foreach($steps as $n=>$label) echo '<a class="'.($n===$step?'active':'').'" href="'.esc_url(admin_url('admin.php?page='.self::PAGE.'&step='.$n)).'"><b>'.$n.'</b><span>'.esc_html($label).'</span></a>';
        echo '</nav><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('persiano_hub_save_setup_wizard'); echo '<input type="hidden" name="action" value="persiano_hub_save_setup_wizard"><input type="hidden" name="step" value="'.$step.'"><section class="phw-card">';

        if (1===$step) {
            echo '<h2>Business identity</h2><div class="phw-grid">';
            self::field('brand_name','Business name',$p['brand_name']??get_bloginfo('name'));
            self::field('legal_name','Legal name',$p['legal_name']??'');
            self::field('tagline','Slogan / tagline',$p['tagline']??'');
            self::field('business_type','Business type',$p['business_type']??'food_service');
            self::field('logo_url','Logo URL',$p['logo_url']??'','url','Use Select logo or paste a Media Library URL.');
            echo '<p><button type="button" class="button phw-media">Select logo</button></p>';
            self::field('primary_color','Primary colour',$p['primary_color']??'#8e2435','color');
            self::field('accent_color','Accent colour',$p['accent_color']??'#d79a2d','color'); echo '</div>';
        } elseif (2===$step) {
            echo '<h2>Contact and social profiles</h2><div class="phw-grid">';
            self::field('support_email','Customer email',$p['support_email']??get_option('admin_email'),'email'); self::field('support_phone','Phone',$p['support_phone']??'');
            self::field('website_url','Website',$p['website_url']??home_url('/'),'url'); self::field('service_area','Service area',$p['service_area']??'');
            echo '<label class="phw-field phw-wide"><span>Address</span><textarea name="address" rows="3">'.esc_textarea($p['address']??'').'</textarea></label>';
            self::field('instagram_url','Instagram profile',$s['instagram_url'],'url'); self::field('facebook_url','Facebook page',$s['facebook_url'],'url');
            self::field('tiktok_url','TikTok profile',$s['tiktok_url'],'url'); self::field('whatsapp','WhatsApp number',$s['whatsapp']); self::field('telegram_url','Telegram link',$s['telegram_url'],'url'); echo '</div>';
        } elseif (3===$step) {
            echo '<h2>Store defaults</h2><div class="phw-grid">';
            self::field('currency','Currency',$s['currency']); self::field('business_hours','Business hours',$s['business_hours'],'text','Example: Tue–Sun, 10 AM–7 PM');
            self::field('lead_time','Preparation lead time',$s['lead_time'],'text','Example: 24 hours'); self::field('minimum_order','Minimum order',$s['minimum_order'],'number');
            self::field('fulfilment','Fulfilment methods',$s['fulfilment'],'text','Comma-separated: pickup,delivery,shipping');
            echo '<label class="phw-field"><span>Default payment handling</span><select name="default_payment"><option value="online" selected>Send for secure online payment</option><option value="paid">Already paid</option><option value="later">Pay later</option></select></label></div>';
        } elseif (4===$step) {
            echo '<h2>Email and SMS</h2><h3>Email identity / SMTP</h3><div class="phw-grid">';
            self::field('email_from_name','From name',$m['email_from_name']??($p['brand_name']??'')); self::field('email_from_address','From address',$m['email_from_address']??($p['support_email']??''),'email');
            self::field('smtp_host','SMTP host',$s['smtp_host']); self::field('smtp_port','SMTP port',$s['smtp_port']); self::field('smtp_username','SMTP username',$s['smtp_username']); self::field('smtp_password','SMTP password / app password','','password','Leave blank to keep the saved value.'); echo '</div>';
            self::guide('Where to find SMTP information',array('Open the email provider or hosting control panel used by the business.','Look for <strong>Connect Devices</strong>, <strong>Mail Client Manual Settings</strong>, or <strong>SMTP settings</strong>.','Copy the outgoing server, port, encryption type, username and password.','Use a dedicated mailbox such as info@business.com. Where available, generate an app password instead of using the main account password.'));
            echo '<h3>Twilio SMS</h3><div class="phw-grid">'; self::field('twilio_account_sid','Account SID',$m['twilio_account_sid']??''); self::field('twilio_auth_token','Auth Token','','password','Leave blank to keep saved token.'); self::field('twilio_service_sid','Messaging Service SID',$m['twilio_service_sid']??''); self::field('twilio_from_number','Twilio From number',$m['twilio_from_number']??''); echo '</div>';
            self::guide('Generate Twilio credentials',array('Create or sign in to the business’s own Twilio account.','On the Account Dashboard, copy the <strong>Account SID</strong> and reveal/copy the <strong>Auth Token</strong>.','Open Phone Numbers and obtain an SMS-capable number. Copy it in international format, such as +16045551234.','Optional: open Messaging → Services, create a Messaging Service, add the number to its sender pool, and copy the SID beginning with <strong>MG</strong>.','After saving this wizard, open Batchly → Message Settings and copy the inbound webhook URL into the Twilio number or Messaging Service. Use HTTP POST.','Send an outbound test, then reply from the receiving phone to verify inbound messages.'),'https://console.twilio.com/');
        } elseif (5===$step) {
            echo '<h2>Payments and accounting</h2><h3>Square</h3><div class="phw-grid">'; self::field('square_app_id','Application ID',$sq['square_app_id']??''); self::field('square_location_id','Location ID',$sq['square_location_id']??''); self::field('square_access_token','Access token','','password','Leave blank to keep the saved token.'); echo '</div>';
            self::guide('Generate Square credentials',array('Sign in to the business’s own Square Developer Dashboard and create an application.','Choose <strong>Sandbox</strong> while testing or <strong>Production</strong> for live business data.','Open Credentials and copy the Application ID and access token for the selected environment.','Find the Location ID in Locations or by using the API Explorer.','After saving, open Batchly → Square settings and configure the displayed webhook URL and signature key.','Never copy Persiano Dish’s access token to another business.'),'https://developer.squareup.com/apps');
            echo '<h3>QuickBooks Online</h3><div class="phw-grid">'; self::field('quickbooks_client_id','Client ID',$s['quickbooks_client_id']); self::field('quickbooks_client_secret','Client Secret','','password','Leave blank to keep saved secret.'); echo '<label class="phw-field"><span>Environment</span><select name="quickbooks_environment"><option value="sandbox" '.selected($s['quickbooks_environment'],'sandbox',false).'>Sandbox</option><option value="production" '.selected($s['quickbooks_environment'],'production',false).'>Production</option></select></label></div>';
            self::guide('Create a QuickBooks connection',array('Sign in to the Intuit Developer Portal using an account controlled by the business or your integration company.','Create an app and select the <strong>QuickBooks Online Accounting</strong> scope.','Open Keys & credentials and copy the Client ID and Client Secret for Sandbox first.','Add the OAuth redirect URL displayed by Batchly once the QuickBooks connector is enabled.','Use Intuit’s sandbox company to test. Move to production credentials only after the workflow is verified.','The business will authorize access using the Connect to QuickBooks button; their QuickBooks password is never entered into Batchly.'),'https://developer.intuit.com/app/developer/dashboard');
        } elseif (6===$step) {
            echo '<h2>Social publishing</h2><h3>Instagram / Meta</h3><div class="phw-grid">'; self::field('instagram_meta_app_id','Meta App ID',$pub['instagram_meta_app_id']??''); self::field('instagram_meta_app_secret','Meta App Secret','','password','Leave blank to keep saved secret.'); self::field('instagram_user_id','Instagram User ID',$pub['instagram_user_id']??''); echo '</div>';
            self::guide('Connect Instagram publishing',array('Convert the Instagram profile to a Professional account if it is not already Business or Creator.','Connect that Instagram account to a Facebook Page owned by the business.','Open Meta for Developers and create a Business app.','Add the Instagram API and Facebook Login for Business products.','Add the OAuth redirect URL shown in Batchly → Publishing Connections.','Request only the permissions needed for publishing and page/account discovery.','Return to Batchly and use Connect Instagram. Complete Meta login as the business owner, then publish a private test.','Do not paste another business’s token; each business authorizes its own account.'),'https://developers.facebook.com/apps/');
            echo '<h3>Telegram</h3><div class="phw-grid">'; self::field('telegram_bot_token','Bot token','','password','Leave blank to keep saved token.'); self::field('telegram_chat_id','Channel/chat ID',$pub['telegram_chat_id']??''); echo '</div>';
            self::guide('Create a Telegram publishing bot',array('In Telegram, open the verified <strong>@BotFather</strong> account.','Send /newbot and follow the prompts. Copy the bot token.','Add the bot as an administrator of the target channel with permission to post.','Use the public channel username (for example @mychannel) or retrieve the numeric chat ID.','Save the details and send a test post from Batchly.'),'https://core.telegram.org/bots/tutorial');
        } elseif (7===$step) {
            echo '<h2>Automatic updates and account access</h2><div class="phw-grid">'; self::field('github_repository','GitHub repository',$s['github_repository'],'text','Format: owner/repository'); echo '<label class="phw-field"><span>Update channel</span><select name="update_channel"><option value="beta" '.selected($s['update_channel'],'beta',false).'>Beta (trial sites)</option><option value="stable" '.selected($s['update_channel'],'stable',false).'>Stable (production)</option></select></label>'; self::field('installation_key','Installation key',$s['installation_key'],'text','Unique key for this site; generate a random value if blank.'); echo '</div>';
            self::guide('Prepare GitHub automatic updates',array('Create one private or public repository for the Batchly source and release packages.','Create one GitHub Release for each version and attach batchly-vX.Y.Z.zip (top-level folder persiano-hub) and batchly-theme-vX.Y.Z.zip (top-level folder batchly-theme).','Use semantic version tags such as v0.53.0. Mark trial-only releases as prereleases.','Enter owner/repository above. Trial sites use Beta; production sites use Stable.','Do not store your personal GitHub password in client sites. Private downloads should later be served through a controlled update endpoint or per-installation authorization.','Test each release on your development site before making it available to trial or production sites.'),'https://github.com/new');
            echo '<h3>Trial user</h3><p>Create the client user after setup under Users → Add New. Use a restricted Batchly Tester role when available; do not provide this setup administrator account.</p>';
        } else {
            $checks = array('Business name'=>!empty($p['brand_name']),'Email'=>!empty($p['support_email']),'Phone'=>!empty($p['support_phone']),'Logo'=>!empty($p['logo_url']),'Twilio'=>!empty($m['twilio_account_sid'])&&!empty($m['twilio_auth_token']),'Square'=>!empty($sq['square_access_token']),'Instagram'=>!empty($pub['instagram_user_id'])&&!empty($pub['instagram_access_token']),'GitHub updates'=>!empty($s['github_repository']));
            echo '<h2>Review and launch</h2><table class="widefat striped"><tbody>'; foreach($checks as $label=>$ok) echo '<tr><td><strong>'.esc_html($label).'</strong></td><td>'.($ok?'<span class="phw-ok">Configured</span>':'<span class="phw-skip">Not configured — can finish later</span>').'</td></tr>'; echo '</tbody></table><p><label><input type="checkbox" name="completed" value="yes" '.checked($s['completed'],'yes',false).'> Mark setup complete and hide the reminder</label></p>';
        }
        echo '</section><div class="phw-actions">'; if($step>1) echo '<a class="button" href="'.esc_url(admin_url('admin.php?page='.self::PAGE.'&step='.($step-1))).'">← Back</a>'; echo '<button class="button button-primary" type="submit">Save and '.($step<8?'continue →':'finish').'</button></div></form></div>';
    }

    public static function save() {
        if ( ! current_user_can('manage_woocommerce') ) wp_die('Unauthorized'); check_admin_referer('persiano_hub_save_setup_wizard');
        $step=max(1,min(8,absint($_POST['step']??1))); $s=self::settings();
        $text=array('instagram_url','facebook_url','tiktok_url','whatsapp','telegram_url','business_hours','lead_time','currency','fulfilment','smtp_host','smtp_port','smtp_username','quickbooks_client_id','quickbooks_environment','github_repository','update_channel','installation_key');
        foreach($text as $k) if(isset($_POST[$k])) $s[$k]=('instagram_url'===$k||'facebook_url'===$k||'tiktok_url'===$k||'telegram_url'===$k)?esc_url_raw(wp_unslash($_POST[$k])):sanitize_text_field(wp_unslash($_POST[$k]));
        if(isset($_POST['minimum_order'])) $s['minimum_order']=(string)max(0,(float)$_POST['minimum_order']);
        foreach(array('smtp_password','quickbooks_client_secret') as $k) if(!empty($_POST[$k])) $s[$k]=sanitize_text_field(wp_unslash($_POST[$k]));
        if(isset($_POST['completed'])) $s['completed']='yes'; $s['step']=min(8,$step+1); update_option(self::OPTION,$s,false);

        $profile=get_option('persiano_hub_business_profile',array());
        foreach(array('brand_name','legal_name','tagline','business_type','support_email','support_phone','website_url','service_area','address','logo_url','primary_color','accent_color') as $k) if(isset($_POST[$k])) { $v=wp_unslash($_POST[$k]); $profile[$k]=('support_email'===$k)?sanitize_email($v):(in_array($k,array('website_url','logo_url'),true)?esc_url_raw($v):('address'===$k?sanitize_textarea_field($v):(false!==strpos($k,'color')?sanitize_hex_color($v):sanitize_text_field($v)))); }
        update_option('persiano_hub_business_profile',$profile,false);

        $m=get_option('persiano_hub_messages_settings',array()); foreach(array('email_from_name','email_from_address','twilio_account_sid','twilio_service_sid','twilio_from_number') as $k) if(isset($_POST[$k])) $m[$k]=sanitize_text_field(wp_unslash($_POST[$k])); if(!empty($_POST['twilio_auth_token']))$m['twilio_auth_token']=sanitize_text_field(wp_unslash($_POST['twilio_auth_token'])); update_option('persiano_hub_messages_settings',$m,false);
        $sq=get_option('persiano_hub_frontend_pos_settings',array()); foreach(array('square_app_id','square_location_id') as $k)if(isset($_POST[$k]))$sq[$k]=sanitize_text_field(wp_unslash($_POST[$k])); if(!empty($_POST['square_access_token']))$sq['square_access_token']=sanitize_text_field(wp_unslash($_POST['square_access_token'])); update_option('persiano_hub_frontend_pos_settings',$sq,false);
        $pub=get_option('persiano_hub_publishing_connections',array()); foreach(array('instagram_meta_app_id','instagram_user_id','telegram_chat_id') as $k)if(isset($_POST[$k]))$pub[$k]=sanitize_text_field(wp_unslash($_POST[$k])); foreach(array('instagram_meta_app_secret','telegram_bot_token') as $k)if(!empty($_POST[$k]))$pub[$k]=sanitize_text_field(wp_unslash($_POST[$k])); update_option('persiano_hub_publishing_connections',$pub,false);
        if(isset($_POST['default_payment'])) update_option('persiano_hub_manual_order_default_payment',sanitize_key($_POST['default_payment']),false);
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE.'&step='.min(8,$step+1).'&saved=1')); exit;
    }

    private static function css(){return '.phw{max-width:1180px}.phw-steps{display:grid;grid-template-columns:repeat(8,1fr);gap:6px;margin:20px 0}.phw-steps a{background:#fff;border:1px solid #dcdcde;padding:10px;text-decoration:none;color:#50575e;border-radius:8px}.phw-steps a.active{border-color:#8e2435;box-shadow:inset 0 0 0 1px #8e2435}.phw-steps b{display:block;color:#8e2435}.phw-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px}.phw-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.phw-field span{display:block;font-weight:600;margin-bottom:6px}.phw-field input,.phw-field select,.phw-field textarea{width:100%;max-width:none}.phw-field small{display:block;color:#646970;margin-top:5px}.phw-wide{grid-column:1/-1}.phw-guide{margin:16px 0;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;background:#f6f7f7}.phw-guide summary{font-weight:700;cursor:pointer}.phw-guide li{margin:8px 0}.phw-actions{display:flex;justify-content:space-between;margin-top:18px}.phw-ok{color:#008a20;font-weight:700}.phw-skip{color:#996800}@media(max-width:900px){.phw-steps{grid-template-columns:repeat(2,1fr)}.phw-grid{grid-template-columns:1fr}}';}
    private static function js(){ return <<<'JS'
jQuery(function($){$('.phw-media').on('click',function(e){e.preventDefault();var f=wp.media({title:'Select business logo',multiple:false,library:{type:'image'}});f.on('select',function(){var a=f.state().get('selection').first().toJSON();$('input[name=logo_url]').val(a.url);});f.open();});});
JS; }
}
