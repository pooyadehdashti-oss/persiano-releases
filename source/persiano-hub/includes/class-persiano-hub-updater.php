<?php
/**
 * Batchly direct updater.
 *
 * Uses the Batchly Core GitHub Releases repository as the plugin update source.
 * Themes are separate products with independent versions and update channels.
 * Core release assets must be named batchly-vX.Y.Z.zip.
 *
 * The repository may be public or private. Private repositories require a
 * fine-grained GitHub token with read-only Contents access.
 *
 * @package Persiano_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Persiano_Hub_Updater {
    const OPTION_KEY       = 'persiano_hub_update_settings';
    const CACHE_KEY        = 'persiano_hub_latest_release';
    const CACHE_TTL        = 15 * MINUTE_IN_SECONDS;
    const MENU_SLUG        = 'persiano-hub-updates';
    const PLUGIN_SLUG      = 'persiano-hub';
    const PLUGIN_PREFIX    = 'batchly-v';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 60 );
        add_action( 'admin_init', array( __CLASS__, 'handle_settings_save' ) );
        add_action( 'admin_post_persiano_hub_check_updates', array( __CLASS__, 'handle_check_now' ) );

        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_plugin_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
        add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_private_release_asset' ), 10, 4 );
        add_filter( 'auto_update_plugin', array( __CLASS__, 'allow_automatic_plugin_update' ), 10, 2 );
    }

    public static function register_admin_page() {
        add_submenu_page(
            'persiano-hub',
            __( 'Batchly Updates', 'persiano-hub' ),
            __( 'Updates', 'persiano-hub' ),
            'update_plugins',
            self::MENU_SLUG,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    private static function defaults() {
        return array(
            'repository' => defined( 'PERSIANO_HUB_GITHUB_REPOSITORY' ) ? (string) PERSIANO_HUB_GITHUB_REPOSITORY : '',
            'token'      => defined( 'PERSIANO_HUB_GITHUB_TOKEN' ) ? (string) PERSIANO_HUB_GITHUB_TOKEN : '',
            'auto_updates' => true,
        );
    }

    public static function get_settings() {
        $saved = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
    }

    private static function normalize_repository( $value ) {
        $value = trim( (string) $value );
        $value = preg_replace( '#^https?://github\.com/#i', '', $value );
        $value = preg_replace( '#\.git$#i', '', $value );
        $value = trim( $value, "/ \t\n\r\0\x0B" );

        if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value ) ) {
            return '';
        }

        return $value;
    }

    public static function handle_settings_save() {
        if ( empty( $_POST['persiano_hub_update_settings_nonce'] ) ) {
            return;
        }

        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to change update settings.', 'persiano-hub' ) );
        }

        check_admin_referer( 'persiano_hub_save_update_settings', 'persiano_hub_update_settings_nonce' );

        $existing   = self::get_settings();
        $repository = isset( $_POST['persiano_hub_update_repository'] ) ? self::normalize_repository( wp_unslash( $_POST['persiano_hub_update_repository'] ) ) : '';
        $token      = isset( $_POST['persiano_hub_update_token'] ) ? trim( (string) wp_unslash( $_POST['persiano_hub_update_token'] ) ) : '';
        $clear      = ! empty( $_POST['persiano_hub_update_clear_token'] );
        $auto_updates = ! empty( $_POST['persiano_hub_update_auto_updates'] );

        if ( $clear ) {
            $saved_token = '';
        } elseif ( '' !== $token ) {
            $saved_token = sanitize_text_field( $token );
        } else {
            $saved_token = isset( $existing['token'] ) ? (string) $existing['token'] : '';
        }

        update_option(
            self::OPTION_KEY,
            array(
                'repository' => $repository,
                'token'      => $saved_token,
                'auto_updates' => $auto_updates,
            ),
            false
        );

        self::clear_cache();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'    => self::MENU_SLUG,
                    'updated' => '1',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function handle_check_now() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to check for updates.', 'persiano-hub' ) );
        }

        check_admin_referer( 'persiano_hub_check_updates' );
        self::clear_cache();
        delete_site_transient( 'update_plugins' );

        if ( function_exists( 'wp_update_plugins' ) ) {
            wp_update_plugins();
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'    => self::MENU_SLUG,
                    'checked' => '1',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function clear_cache() {
        delete_site_transient( self::CACHE_KEY );
    }

    private static function github_headers( $accept = 'application/vnd.github+json' ) {
        $settings = self::get_settings();
        $headers  = array(
            'Accept'     => $accept,
            'User-Agent' => 'Batchly/' . PERSIANO_HUB_VERSION . '; ' . home_url( '/' ),
        );

        if ( ! empty( $settings['token'] ) ) {
            $headers['Authorization'] = 'Bearer ' . trim( (string) $settings['token'] );
        }

        return $headers;
    }

    private static function get_latest_release( $force = false ) {
        $settings   = self::get_settings();
        $repository = self::normalize_repository( $settings['repository'] );

        if ( ! $repository ) {
            return new WP_Error( 'persiano_update_not_configured', __( 'The GitHub release repository has not been configured yet.', 'persiano-hub' ) );
        }

        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) && ! empty( $cached['release'] ) ) {
                return $cached['release'];
            }
        }

        $url      = 'https://api.github.com/repos/' . rawurlencode( strtok( $repository, '/' ) ) . '/' . rawurlencode( substr( $repository, strpos( $repository, '/' ) + 1 ) ) . '/releases/latest';
        $response = wp_remote_get(
            $url,
            array(
                'headers' => self::github_headers(),
                'timeout' => 20,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || ! is_array( $body ) ) {
            $message = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : sprintf( __( 'GitHub returned HTTP %d.', 'persiano-hub' ), $code );
            return new WP_Error( 'persiano_update_github_error', sanitize_text_field( $message ) );
        }

        if ( empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
            return new WP_Error( 'persiano_update_no_assets', __( 'The latest GitHub release does not contain any ZIP assets.', 'persiano-hub' ) );
        }

        set_site_transient(
            self::CACHE_KEY,
            array(
                'release'    => $body,
                'checked_at' => time(),
            ),
            self::CACHE_TTL
        );

        return $body;
    }

    private static function parse_asset_version( $name, $prefix ) {
        $pattern = '#^' . preg_quote( $prefix, '#' ) . '([0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9.-]+)?)\.zip$#i';
        if ( preg_match( $pattern, (string) $name, $matches ) ) {
            return $matches[1];
        }
        return '';
    }

    private static function find_release_asset( $release, $prefix ) {
        if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
            return null;
        }

        $best = null;
        foreach ( $release['assets'] as $asset ) {
            if ( empty( $asset['name'] ) || empty( $asset['url'] ) ) {
                continue;
            }

            $version = self::parse_asset_version( $asset['name'], $prefix );
            if ( ! $version ) {
                continue;
            }

            if ( null === $best || version_compare( $version, $best['version'], '>' ) ) {
                $best = array(
                    'version'      => $version,
                    'name'         => (string) $asset['name'],
                    'api_url'      => esc_url_raw( (string) $asset['url'] ),
                    'browser_url'  => ! empty( $asset['browser_download_url'] ) ? esc_url_raw( (string) $asset['browser_download_url'] ) : '',
                    'size'         => isset( $asset['size'] ) ? absint( $asset['size'] ) : 0,
                );
            }
        }

        return $best;
    }

    private static function get_update_payload() {
        $release = self::get_latest_release();
        if ( is_wp_error( $release ) ) {
            return $release;
        }

        return array(
            'release' => $release,
            'plugin'  => self::find_release_asset( $release, self::PLUGIN_PREFIX ),
        );
    }

    /**
     * Select the correct GitHub package URL.
     *
     * Public repositories use browser_download_url so WordPress receives the
     * ZIP directly. Private repositories use the authenticated API asset URL.
     */
    private static function package_url_for_asset( $asset ) {
        $settings = self::get_settings();

        if ( ! empty( $settings['token'] ) && ! empty( $asset['api_url'] ) ) {
            return $asset['api_url'];
        }

        if ( ! empty( $asset['browser_url'] ) ) {
            return $asset['browser_url'];
        }

        return ! empty( $asset['api_url'] ) ? $asset['api_url'] : '';
    }

    public static function inject_plugin_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        $payload = self::get_update_payload();
        if ( is_wp_error( $payload ) || empty( $payload['plugin'] ) ) {
            return $transient;
        }

        $asset       = $payload['plugin'];
        $plugin_file = plugin_basename( PERSIANO_HUB_FILE );

        if ( version_compare( $asset['version'], PERSIANO_HUB_VERSION, '>' ) ) {
            $update                 = new stdClass();
            $update->id             = 'github.com/' . self::get_settings()['repository'];
            $update->slug           = self::PLUGIN_SLUG;
            $update->plugin         = $plugin_file;
            $update->new_version    = $asset['version'];
            $update->url            = ! empty( $payload['release']['html_url'] ) ? esc_url_raw( $payload['release']['html_url'] ) : 'https://github.com/pooyadehdashti-oss/persiano-releases';
            $update->package        = self::package_url_for_asset( $asset );
            $update->requires_php   = '7.4';
            $update->tested         = get_bloginfo( 'version' );
            $update->icons          = array(
                '1x' => 'https://raw.githubusercontent.com/pooyadehdashti-oss/persiano-releases/main/docs/assets/batchly-logo.svg',
                '2x' => 'https://raw.githubusercontent.com/pooyadehdashti-oss/persiano-releases/main/docs/assets/batchly-logo.svg',
            );
            $update->banners        = array(
                'low'  => 'https://raw.githubusercontent.com/pooyadehdashti-oss/persiano-releases/main/docs/assets/batchly-banner.svg',
                'high' => 'https://raw.githubusercontent.com/pooyadehdashti-oss/persiano-releases/main/docs/assets/batchly-banner.svg',
            );
            $transient->response[ $plugin_file ] = $update;
        }

        return $transient;
    }


    /** Enable unattended updates only for Batchly assets when opted in. */
    public static function allow_automatic_plugin_update( $update, $item ) {
        $settings = self::get_settings();
        if ( empty( $settings['auto_updates'] ) ) {
            return $update;
        }
        $plugin_file = plugin_basename( PERSIANO_HUB_FILE );
        if ( is_object( $item ) && ! empty( $item->plugin ) && $plugin_file === $item->plugin ) {
            return true;
        }
        return $update;
    }


    public static function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
            return $result;
        }

        $payload = self::get_update_payload();
        if ( is_wp_error( $payload ) || empty( $payload['plugin'] ) ) {
            return $result;
        }

        $release = $payload['release'];
        $asset   = $payload['plugin'];
        $info    = new stdClass();
        $info->name          = 'Batchly';
        $info->slug          = self::PLUGIN_SLUG;
        $info->version       = $asset['version'];
        $info->author        = '<a href="https://persianodish.com/">Persiano Dish</a>';
        $info->homepage      = 'https://github.com/pooyadehdashti-oss/persiano-releases';
        $info->external      = true;
        $info->requires      = '6.5';
        $info->requires_php  = '7.4';
        $info->tested        = get_bloginfo( 'version' );
        $info->last_updated  = ! empty( $release['published_at'] ) ? sanitize_text_field( $release['published_at'] ) : '';
        $info->downloaded    = 0;
        $info->active_installs = 0;
        $info->short_description = __( 'Orders, customers, correspondence, fulfilment, labels, costing and publishing in one configurable workspace.', 'persiano-hub' );
        $info->download_link = self::package_url_for_asset( $asset );
        $info->sections      = array(
            'description' => __( 'Batchly combines products, customers, orders, secure payment requests, order-based email and SMS correspondence, recipes, costing, labels, fulfilment, publishing and trial monitoring in one configurable WordPress workspace.', 'persiano-hub' ),
            'installation'=> __( 'Upload the Batchly ZIP through Plugins → Add New Plugin → Upload Plugin, or use WordPress automatic updates after connecting the GitHub release repository under Batchly → Updates.', 'persiano-hub' ),
            'changelog'   => ! empty( $release['body'] ) ? wp_kses_post( wpautop( $release['body'] ) ) : __( 'See the maintained release notes in the Batchly release repository.', 'persiano-hub' ),
        );
        $info->icons = array(
            '1x' => 'https://raw.githubusercontent.com/pooyadehdashti-oss/persiano-releases/main/docs/assets/batchly-logo.svg',
            '2x' => 'https://raw.githubusercontent.com/pooyadehdashti-oss/persiano-releases/main/docs/assets/batchly-logo.svg',
        );
        $info->banners = array(
            'low'  => 'https://raw.githubusercontent.com/pooyadehdashti-oss/persiano-releases/main/docs/assets/batchly-banner.svg',
            'high' => 'https://raw.githubusercontent.com/pooyadehdashti-oss/persiano-releases/main/docs/assets/batchly-banner.svg',
        );

        return $info;
    }

    public static function download_private_release_asset( $reply, $package, $upgrader, $hook_extra ) {
        if ( false !== $reply || ! is_string( $package ) ) {
            return $reply;
        }

        $settings   = self::get_settings();
        $repository = self::normalize_repository( $settings['repository'] );
        if ( ! $repository ) {
            return $reply;
        }

        $parts = explode( '/', $repository, 2 );
        if ( 2 !== count( $parts ) ) {
            return $reply;
        }

        $prefix = 'https://api.github.com/repos/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] ) . '/releases/assets/';
        if ( 0 !== strpos( $package, $prefix ) ) {
            return $reply;
        }

        $tmp = wp_tempnam( basename( $package ) . '.zip' );
        if ( ! $tmp ) {
            return new WP_Error( 'persiano_update_temp_file', __( 'Could not create a temporary file for the Batchly update.', 'persiano-hub' ) );
        }

        $response = wp_remote_get(
            $package,
            array(
                'headers'  => self::github_headers( 'application/octet-stream' ),
                'timeout'  => 300,
                'stream'   => true,
                'filename' => $tmp,
            )
        );

        if ( is_wp_error( $response ) ) {
            @unlink( $tmp );
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            @unlink( $tmp );
            return new WP_Error(
                'persiano_update_download_failed',
                sprintf( __( 'GitHub could not provide the update package (HTTP %d).', 'persiano-hub' ), $code )
            );
        }

        $handle    = @fopen( $tmp, 'rb' );
        $signature = $handle ? fread( $handle, 4 ) : '';

        if ( $handle ) {
            fclose( $handle );
        }

        if ( 0 !== strpos( $signature, 'PK' ) ) {
            $content_type = wp_remote_retrieve_header( $response, 'content-type' );
            @unlink( $tmp );

            return new WP_Error(
                'persiano_update_invalid_package',
                sprintf(
                    __( 'GitHub returned an invalid update package instead of a ZIP file%s.', 'persiano-hub' ),
                    $content_type ? ' (' . sanitize_text_field( $content_type ) . ')' : ''
                )
            );
        }

        return $tmp;
    }

    private static function status_for_asset( $asset, $installed_version ) {
        if ( ! $asset ) {
            return array( 'state' => 'missing', 'label' => __( 'No matching ZIP in latest release', 'persiano-hub' ) );
        }
        if ( version_compare( $asset['version'], $installed_version, '>' ) ) {
            return array( 'state' => 'update', 'label' => sprintf( __( 'Update available: %s', 'persiano-hub' ), $asset['version'] ) );
        }
        return array( 'state' => 'current', 'label' => __( 'Up to date', 'persiano-hub' ) );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        $settings = self::get_settings();
        $payload  = self::get_update_payload();
        $error    = is_wp_error( $payload ) ? $payload : null;
        $plugin   = ! $error && ! empty( $payload['plugin'] ) ? $payload['plugin'] : null;
        $plugin_status = self::status_for_asset( $plugin, PERSIANO_HUB_VERSION );
        ?>
        <div class="wrap ph-updates-wrap">
            <style>
                .ph-updates-wrap{max-width:1050px}.ph-updates-hero{background:#fffaf2;border:1px solid #eadfce;border-radius:18px;padding:26px 30px;margin:20px 0}.ph-updates-hero h1{margin:0 0 8px}.ph-updates-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;margin:18px 0}.ph-update-card{background:#fff;border:1px solid #ddd;border-radius:14px;padding:20px}.ph-update-card h2{margin-top:0}.ph-status{display:inline-block;border-radius:999px;padding:5px 10px;font-weight:600}.ph-status.current{background:#e9f7ee;color:#176b3a}.ph-status.update{background:#fff3cd;color:#7a5700}.ph-status.missing{background:#f4f4f4;color:#555}.ph-settings-card{background:#fff;border:1px solid #ddd;border-radius:14px;padding:24px;margin-top:18px}.ph-settings-card input[type=text],.ph-settings-card input[type=password]{width:100%;max-width:720px}.ph-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#f6f7f7;padding:2px 6px;border-radius:5px}@media(max-width:760px){.ph-updates-grid{grid-template-columns:1fr}}
            </style>
            <div class="ph-updates-hero">
                <h1><?php esc_html_e( 'Hub Updates', 'persiano-hub' ); ?></h1>
                <p><?php esc_html_e( 'Connect one GitHub Releases repository and WordPress will detect new Batchly packages through the normal Updates screen. Each site keeps its own business profile, credentials and operational data.', 'persiano-hub' ); ?></p>
            </div>

            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Update settings saved.', 'persiano-hub' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['checked'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'WordPress checked the configured Batchly release source.', 'persiano-hub' ); ?></p></div>
            <?php endif; ?>

            <div class="ph-updates-grid">
                <div class="ph-update-card">
                    <h2><?php esc_html_e( 'Batchly', 'persiano-hub' ); ?></h2>
                    <p><?php printf( esc_html__( 'Installed: %s', 'persiano-hub' ), esc_html( PERSIANO_HUB_VERSION ) ); ?></p>
                    <span class="ph-status <?php echo esc_attr( $plugin_status['state'] ); ?>"><?php echo esc_html( $plugin_status['label'] ); ?></span>
                </div>
            </div>

            <?php if ( $error && 'persiano_update_not_configured' !== $error->get_error_code() ) : ?>
                <div class="notice notice-error"><p><strong><?php esc_html_e( 'Release source error:', 'persiano-hub' ); ?></strong> <?php echo esc_html( $error->get_error_message() ); ?></p></div>
            <?php endif; ?>

            <div class="ph-settings-card">
                <h2><?php esc_html_e( 'Release source', 'persiano-hub' ); ?></h2>
                <p><?php esc_html_e( 'This repository updates Batchly Core only. Storefront themes are separate products with independent versions, customization and update channels.', 'persiano-hub' ); ?></p>
                <form method="post">
                    <?php wp_nonce_field( 'persiano_hub_save_update_settings', 'persiano_hub_update_settings_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="persiano_hub_update_repository"><?php esc_html_e( 'GitHub repository', 'persiano-hub' ); ?></label></th>
                            <td>
                                <input id="persiano_hub_update_repository" name="persiano_hub_update_repository" type="text" value="<?php echo esc_attr( $settings['repository'] ); ?>" placeholder="your-account/batchly-releases">
                                <p class="description"><?php esc_html_e( 'Enter owner/repository or paste the GitHub repository URL. Public release repositories need no token.', 'persiano-hub' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="persiano_hub_update_token"><?php esc_html_e( 'GitHub access token', 'persiano-hub' ); ?></label></th>
                            <td>
                                <input id="persiano_hub_update_token" name="persiano_hub_update_token" type="password" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( ! empty( $settings['token'] ) ? 'Token saved — leave blank to keep it' : 'Optional for public repositories' ); ?>">
                                <p class="description"><?php esc_html_e( 'For a private repository, use a fine-grained token with read-only Contents access to that repository. The token is stored in your WordPress database and is never added to update URLs. Advanced deployments may define PERSIANO_HUB_GITHUB_REPOSITORY and PERSIANO_HUB_GITHUB_TOKEN in wp-config.php.', 'persiano-hub' ); ?></p>
                                <?php if ( ! empty( $settings['token'] ) ) : ?>
                                    <label><input type="checkbox" name="persiano_hub_update_clear_token" value="1"> <?php esc_html_e( 'Remove the saved token', 'persiano-hub' ); ?></label>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Automatic updates', 'persiano-hub' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="persiano_hub_update_auto_updates" value="1" <?php checked( ! empty( $settings['auto_updates'] ) ); ?>> <?php esc_html_e( 'Automatically install Batchly Core plugin updates from this repository', 'persiano-hub' ); ?></label>
                                <p class="description"><?php esc_html_e( 'Recommended for connected client and demo sites. WordPress installs updates during its normal background update cycle.', 'persiano-hub' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save Update Settings', 'persiano-hub' ) ); ?>
                </form>

                <hr>
                <h3><?php esc_html_e( 'Required release asset names', 'persiano-hub' ); ?></h3>
                <p><span class="ph-code">batchly-v0.56.5.zip</span></p>
                <p class="description"><?php esc_html_e( 'Future versions can use any higher semantic version number; the updater detects the version from each ZIP filename.', 'persiano-hub' ); ?></p>

                <p>
                    <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=persiano_hub_check_updates' ), 'persiano_hub_check_updates' ) ); ?>"><?php esc_html_e( 'Check for Updates Now', 'persiano-hub' ); ?></a>
                    <a class="button" href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Open WordPress Updates', 'persiano-hub' ); ?></a>
                </p>
            </div>
        </div>
        <?php
    }
}
