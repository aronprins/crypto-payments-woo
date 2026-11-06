<?php
/**
 * CPW_Updater - Updates from GitHub releases.
 *
 * The plugin header's "Update URI" points at GitHub, so WordPress asks this class (through the
 * update_plugins_github.com filter) instead of WordPress.org. The newest published, non-draft,
 * non-prerelease GitHub release is the available version. Its "crypto-payments-woo.zip" asset is the
 * package. WordPress's own update screens, one-click updates and automatic background updates then
 * work as for any other plugin.
 *
 * Each release also carries an "update.json" asset (written by the Release workflow) with the version,
 * requirements and readme sections. It is fetched as a plain download from github.com, which, unlike
 * api.github.com (60 requests an hour per IP address), isn't rate limited for sites on shared hosting.
 * Releases without update.json fall back to the REST API. A release without the zip is never offered:
 * GitHub's own source zip contains development files and skips the build.
 *
 * Loaded whether or not WooCommerce is active, so the plugin can always be updated.
 *
 * @package CryptoPaymentsWoo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPW_Updater {

    const REPO        = 'aronprins/crypto-payments-woo';
    const CACHE       = 'cpw_github_release';
    const CACHE_TTL   = 6 * HOUR_IN_SECONDS;
    const ERROR_TTL   = HOUR_IN_SECONDS;
    const ASSET_NAME  = 'crypto-payments-woo.zip';
    const META_NAME   = 'update.json';
    const SLUG        = 'crypto-payments-woo';
    const MAIN_FILE   = 'crypto-payments-woo.php';
    const CHECK_NONCE = 'cpw_check_updates';

    /**
     * Hooks the updater into WordPress.
     */
    public static function init() {
        add_filter( 'update_plugins_github.com', [ __CLASS__, 'update_info' ], 10, 3 );
        add_filter( 'plugins_api', [ __CLASS__, 'plugin_information' ], 20, 3 );
        add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_folder_name' ], 10, 4 );
        add_filter( 'plugin_row_meta', [ __CLASS__, 'row_meta' ], 10, 2 );
        add_action( 'upgrader_process_complete', [ __CLASS__, 'after_upgrade' ], 10, 2 );
        add_action( 'admin_post_cpw_check_updates', [ __CLASS__, 'handle_check_now' ] );
        add_action( 'admin_post_cpw_auto_update', [ __CLASS__, 'handle_auto_update' ] );
        add_action( 'admin_notices', [ __CLASS__, 'check_notice' ] );
    }

    /**
     * GitHub repository URL.
     */
    public static function repo_url(): string {
        return 'https://github.com/' . self::REPO;
    }

    /**
     * URL of the gateway's settings screen, at its Updates section.
     */
    private static function settings_url(): string {
        return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=crypto_payments' ) . '#cpw-updates';
    }

    // Reading the latest release.

    /**
     * Latest release, cached.
     *
     * @param bool $force Skip the cache.
     * @return array|null Release data, or null when none could be fetched.
     */
    public static function release( bool $force = false ): ?array {
        $cached = get_site_transient( self::CACHE );
        if ( ! $force && is_array( $cached ) ) {
            return $cached['release'] ?? null;
        }

        $release = self::from_update_json();
        if ( null === $release ) {
            $release = self::from_api();
        }
        if ( is_string( $release ) ) {
            self::cache( null, $release );
            return null;
        }
        self::cache( $release, '' );
        return $release;
    }

    /**
     * Reads the latest release from its update.json asset.
     *
     * @return array|string|null Release data, an error message, or null when there is no update.json to read.
     */
    private static function from_update_json() {
        $response = self::get( self::repo_url() . '/releases/latest/download/' . self::META_NAME, 'application/json' );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['tag'] ) || empty( $data['version'] ) ) {
            return null;
        }

        $tag     = (string) $data['tag'];
        $version = (string) $data['version'];
        if ( ! self::is_version( $version ) ) {
            /* translators: %s: Git tag name. */
            return sprintf( __( 'The latest release tag "%s" is not a version number.', 'crypto-payments-woo' ), $tag );
        }

        // Only ever install the zip attached to a release of this repository.
        $package = self::repo_url() . '/releases/download/' . rawurlencode( $tag ) . '/' . self::ASSET_NAME;

        $sections = [];
        foreach ( (array) ( $data['sections'] ?? [] ) as $key => $text ) {
            if ( is_string( $text ) ) {
                $sections[ sanitize_key( (string) $key ) ] = $text;
            }
        }

        return [
            'version'   => $version,
            'tag'       => $tag,
            'package'   => $package,
            'html_url'  => self::repo_url() . '/releases/tag/' . rawurlencode( $tag ),
            'notes'     => is_string( $data['notes'] ?? null ) ? $data['notes'] : '',
            'published' => is_string( $data['published'] ?? null ) ? $data['published'] : '',
            'readme'    => [
                'requires'     => is_string( $data['requires'] ?? null ) ? $data['requires'] : '',
                'tested'       => is_string( $data['tested'] ?? null ) ? $data['tested'] : '',
                'requires_php' => is_string( $data['requires_php'] ?? null ) ? $data['requires_php'] : '',
                'sections'     => $sections,
            ],
        ];
    }

    /**
     * Reads the latest release through the GitHub REST API (for releases without update.json).
     *
     * @return array|string Release data, or an error message.
     */
    private static function from_api() {
        $response = self::get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', 'application/vnd.github+json' );
        if ( is_wp_error( $response ) ) {
            return $response->get_error_message();
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            if ( 404 === $code ) {
                return __( 'No published release found on GitHub yet.', 'crypto-payments-woo' );
            }
            if ( ( 403 === $code || 429 === $code ) && '0' === wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
                return __( 'GitHub\'s rate limit for this server was reached. Crypto Payments will try again later.', 'crypto-payments-woo' );
            }
            /* translators: %d: HTTP status code. */
            return sprintf( __( 'GitHub answered with HTTP %d.', 'crypto-payments-woo' ), $code );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
            return __( 'GitHub returned an unexpected response.', 'crypto-payments-woo' );
        }

        $version = ltrim( (string) $data['tag_name'], 'vV' );
        if ( ! self::is_version( $version ) ) {
            /* translators: %s: Git tag name. */
            return sprintf( __( 'The latest release tag "%s" is not a version number.', 'crypto-payments-woo' ), $data['tag_name'] );
        }

        $package = '';
        foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
            if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) ) {
                $package = (string) $asset['browser_download_url'];
                break;
            }
        }
        if ( '' === $package ) {
            /* translators: %s: file name. */
            return sprintf( __( 'The latest release has no %s attached yet.', 'crypto-payments-woo' ), self::ASSET_NAME );
        }

        return [
            'version'   => $version,
            'tag'       => (string) $data['tag_name'],
            'package'   => $package,
            'html_url'  => (string) ( $data['html_url'] ?? self::repo_url() . '/releases' ),
            'notes'     => (string) ( $data['body'] ?? '' ),
            'published' => (string) ( $data['published_at'] ?? '' ),
            'readme'    => self::fetch_readme( (string) $data['tag_name'] ),
        ];
    }

    /**
     * Whether a string looks like a version number (1.2.0, 1.2.0-beta.1).
     *
     * @param string $version Version.
     */
    private static function is_version( string $version ): bool {
        return (bool) preg_match( '/^\d+(\.\d+)*([-.+][0-9A-Za-z.-]+)?$/', $version );
    }

    /**
     * Caches a release (or the reason none was found).
     *
     * @param array|null $release Release data.
     * @param string     $error   Error message.
     */
    private static function cache( ?array $release, string $error ) {
        set_site_transient(
            self::CACHE,
            [
                'release' => $release,
                'error'   => $error,
                'checked' => time(),
            ],
            $release ? self::CACHE_TTL : self::ERROR_TTL
        );
    }

    /**
     * Cached release, error and time of the last check.
     */
    public static function cached_state(): array {
        $cached = get_site_transient( self::CACHE );
        return is_array( $cached ) ? $cached : [];
    }

    /**
     * Forgets the cached release.
     */
    public static function flush() {
        delete_site_transient( self::CACHE );
    }

    /**
     * GET request to GitHub (an identifying User-Agent is required by GitHub's API).
     *
     * @param string $url    URL.
     * @param string $accept Accept header.
     */
    private static function get( string $url, string $accept ) {
        return wp_remote_get(
            $url,
            [
                'timeout' => 10,
                'headers' => [
                    'Accept'     => $accept,
                    'User-Agent' => 'Crypto-Payments-Woo-Updater/' . CPW_VERSION,
                ],
            ]
        );
    }

    /**
     * Reads readme.txt from the release tag for requirements, description and changelog.
     *
     * @param string $tag Release tag.
     */
    private static function fetch_readme( string $tag ): array {
        // The contents API with the "raw" media type returns the file itself.
        $response = self::get( 'https://api.github.com/repos/' . self::REPO . '/contents/readme.txt?ref=' . rawurlencode( $tag ), 'application/vnd.github.raw' );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return [];
        }
        return self::parse_readme( (string) wp_remote_retrieve_body( $response ) );
    }

    /**
     * Minimal WordPress readme.txt parser.
     *
     * @param string $text Readme contents.
     */
    public static function parse_readme( string $text ): array {
        $text   = str_replace( "\r", '', $text );
        $fields = [];
        foreach ( [
            'requires'     => 'Requires at least',
            'tested'       => 'Tested up to',
            'requires_php' => 'Requires PHP',
        ] as $key => $label ) {
            if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $text, $match ) ) {
                $fields[ $key ] = trim( $match[1] );
            }
        }

        $sections = [];
        $parts    = preg_split( '/^==\s*(.+?)\s*==\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
        $count    = count( $parts );
        for ( $i = 1; $i < $count; $i += 2 ) {
            $sections[ sanitize_key( $parts[ $i ] ) ] = trim( $parts[ $i + 1 ] ?? '' );
        }
        $fields['sections'] = $sections;
        return $fields;
    }

    /**
     * Converts readme/Markdown text to the limited HTML WordPress shows in the details popup.
     *
     * @param string $text Text.
     */
    public static function to_html( string $text ): string {
        $text  = str_replace( "\r", '', trim( $text ) );
        $lines = explode( "\n", $text );
        $html  = '';
        $list  = false;
        $para  = [];

        $inline     = static function ( string $line ): string {
            $line = esc_html( $line );
            $line = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $line );
            $line = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $line );
            $line = preg_replace_callback(
                '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
                static function ( $m ) {
                    return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '">' . $m[1] . '</a>';
                },
                $line
            );
            return (string) $line;
        };
        $flush_para = static function () use ( &$para, &$html, $inline ) {
            if ( $para ) {
                $html .= '<p>' . $inline( implode( ' ', $para ) ) . "</p>\n";
                $para  = [];
            }
        };
        $close_list = static function () use ( &$list, &$html ) {
            if ( $list ) {
                $html .= "</ul>\n";
                $list  = false;
            }
        };

        foreach ( $lines as $line ) {
            $trim = trim( $line );
            if ( '' === $trim ) {
                $flush_para();
                $close_list();
                continue;
            }
            if ( preg_match( '/^(?:=\s*(.+?)\s*=|#{1,6}\s+(.+))$/', $trim, $m ) ) {
                $flush_para();
                $close_list();
                $html .= '<h4>' . $inline( '' !== $m[1] ? $m[1] : $m[2] ) . "</h4>\n";
                continue;
            }
            if ( preg_match( '/^[*\-+]\s+(.+)$/', $trim, $m ) ) {
                $flush_para();
                if ( ! $list ) {
                    $html .= "<ul>\n";
                    $list  = true;
                }
                $html .= '<li>' . $inline( $m[1] ) . "</li>\n";
                continue;
            }
            $close_list();
            $para[] = $trim;
        }
        $flush_para();
        $close_list();
        return $html;
    }

    /**
     * "Tested up to: 7.1" covers every 7.1.x release, the way WordPress.org reports it.
     * WordPress itself compares full versions, so 7.1.4 would otherwise count as untested.
     *
     * @param string $tested Tested-up-to value from readme.txt.
     */
    public static function tested( string $tested ): string {
        $current = (string) get_bloginfo( 'version' );
        if ( '' !== $tested && 0 === strpos( $current, rtrim( $tested, '.' ) . '.' ) ) {
            return $current;
        }
        return $tested;
    }

    // WordPress update integration.

    /**
     * Tells WordPress about the latest release.
     *
     * @param array|false $update      Update data so far.
     * @param array       $plugin_data Plugin headers.
     * @param string      $plugin_file Plugin file.
     */
    public static function update_info( $update, $plugin_data, $plugin_file ) {
        if ( CPW_PLUGIN_BASENAME !== $plugin_file ) {
            return $update;
        }
        $release = self::release();
        if ( ! $release || '' === $release['package'] ) {
            return $update;
        }
        $readme = $release['readme'];
        return [
            'slug'         => self::SLUG,
            'version'      => $release['version'],
            'url'          => self::repo_url(),
            'package'      => $release['package'],
            'requires'     => $readme['requires'] ?? '',
            'tested'       => self::tested( $readme['tested'] ?? '' ),
            'requires_php' => $readme['requires_php'] ?? '',
            'icons'        => [],
        ];
    }

    /**
     * Fills the "View details" popup.
     *
     * @param false|object|array $result Result so far.
     * @param string             $action API action.
     * @param object             $args   Arguments.
     */
    public static function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
            return $result;
        }

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $main_file = CPW_PLUGIN_DIR . self::MAIN_FILE;
        $local     = get_plugin_data( $main_file, false, false );
        $release   = self::release();
        $readme    = $release['readme'] ?? [];
        $sections  = $readme['sections'] ?? self::local_readme_sections();

        // The readme changelog already holds this release's entry; the release notes are only a fallback.
        $changelog = '';
        if ( ! empty( $sections['changelog'] ) ) {
            $changelog = self::to_html( $sections['changelog'] );
        } elseif ( $release && '' !== trim( $release['notes'] ) ) {
            $changelog = '<h4>' . esc_html( $release['tag'] ) . '</h4>' . self::to_html( $release['notes'] );
        }

        $html_sections = array_filter(
            [
                'description'  => isset( $sections['description'] ) ? self::to_html( $sections['description'] ) : esc_html( $local['Description'] ),
                'installation' => isset( $sections['installation'] ) ? self::to_html( $sections['installation'] ) : '',
                'changelog'    => $changelog,
            ]
        );

        return (object) [
            'name'           => __( 'Crypto Payments for WooCommerce', 'crypto-payments-woo' ),
            'slug'           => self::SLUG,
            'version'        => $release['version'] ?? $local['Version'],
            'author'         => '<a href="https://aronandsharon.com">' . esc_html__( 'Aron & Sharon', 'crypto-payments-woo' ) . '</a>',
            'author_profile' => 'https://aronandsharon.com',
            'homepage'       => self::repo_url(),
            'requires'       => $readme['requires'] ?? $local['RequiresWP'],
            'tested'         => self::tested( $readme['tested'] ?? '' ),
            'requires_php'   => $readme['requires_php'] ?? $local['RequiresPHP'],
            'last_updated'   => $release['published'] ?? '',
            'download_link'  => $release['package'] ?? '',
            'sections'       => $html_sections,
            'banners'        => [],
            'external'       => true,
        ];
    }

    /**
     * Sections of the installed readme.txt, for when no release could be read.
     */
    private static function local_readme_sections(): array {
        $file = CPW_PLUGIN_DIR . 'readme.txt';
        if ( ! is_readable( $file ) ) {
            return [];
        }
        return self::parse_readme( (string) file_get_contents( $file ) )['sections'];
    }

    /**
     * Makes sure the unpacked update ends up in the plugin's own folder, whatever the zip's top folder
     * is called (GitHub's source zips use "owner-repo-hash").
     *
     * @param string       $source        Unpacked source folder.
     * @param string       $remote_source Temporary parent folder.
     * @param \WP_Upgrader $upgrader      Upgrader.
     * @param array        $hook_extra    Context.
     */
    public static function fix_folder_name( $source, $remote_source, $upgrader = null, $hook_extra = [] ) {
        global $wp_filesystem;

        $is_ours = ( isset( $hook_extra['plugin'] ) && CPW_PLUGIN_BASENAME === $hook_extra['plugin'] )
            || ( is_string( $source ) && file_exists( trailingslashit( $source ) . self::MAIN_FILE ) );
        if ( ! $is_ours || ! is_string( $source ) || ! $wp_filesystem ) {
            return $source;
        }

        $wanted = trailingslashit( $remote_source ) . dirname( CPW_PLUGIN_BASENAME );
        if ( untrailingslashit( $source ) === $wanted ) {
            return $source;
        }
        if ( ! file_exists( trailingslashit( $source ) . self::MAIN_FILE ) ) {
            return new WP_Error( 'cpw_bad_package', __( 'The update package does not contain Crypto Payments for WooCommerce.', 'crypto-payments-woo' ) );
        }
        if ( $wp_filesystem->move( $source, $wanted, true ) ) {
            return trailingslashit( $wanted );
        }
        return new WP_Error( 'cpw_rename_failed', __( 'Could not prepare the Crypto Payments update folder.', 'crypto-payments-woo' ) );
    }

    /**
     * Clears the cache once this plugin itself was updated.
     *
     * @param \WP_Upgrader $upgrader Upgrader.
     * @param array        $options  Context.
     */
    public static function after_upgrade( $upgrader, $options ) {
        if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) && in_array( CPW_PLUGIN_BASENAME, (array) ( $options['plugins'] ?? [] ), true ) ) {
            self::flush();
        }
    }

    // Admin: "Check for updates" and automatic updates.

    /**
     * Nonce-protected "Check for updates" URL.
     *
     * @param string $return_to Screen to come back to: 'plugins' or 'settings'.
     */
    public static function check_url( string $return_to = 'plugins' ): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=cpw_check_updates&return=' . $return_to ), self::CHECK_NONCE );
    }

    /**
     * Adds "Check for updates" and "Releases on GitHub" links on the Plugins screen.
     *
     * @param mixed $links Plugin action links.
     * @param mixed $file  Plugin file.
     */
    public static function row_meta( $links, $file ) {
        if ( CPW_PLUGIN_BASENAME !== $file || ! current_user_can( 'update_plugins' ) ) {
            return $links;
        }
        $links[] = '<a href="' . esc_url( self::check_url() ) . '">' . esc_html__( 'Check for updates', 'crypto-payments-woo' ) . '</a>';
        $links[] = '<a href="' . esc_url( self::repo_url() . '/releases' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Releases on GitHub', 'crypto-payments-woo' ) . '</a>';
        return $links;
    }

    /**
     * Checks GitHub for a new release right away.
     */
    public static function handle_check_now() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'crypto-payments-woo' ), 403 );
        }
        check_admin_referer( self::CHECK_NONCE );

        self::flush();
        self::release( true );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        set_transient( 'cpw_update_check_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
        $return = isset( $_GET['return'] ) && 'settings' === $_GET['return']
            ? self::settings_url()
            : admin_url( 'plugins.php' );
        wp_safe_redirect( $return );
        exit;
    }

    /**
     * Result of "Check for updates", shown once.
     */
    public static function check_notice() {
        $key = 'cpw_update_check_' . get_current_user_id();
        if ( ! get_transient( $key ) ) {
            return;
        }
        delete_transient( $key );

        $state   = self::cached_state();
        $release = $state['release'] ?? null;
        if ( ! $release ) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s %s</p></div>',
                wp_kses_post( __( '<strong>Crypto Payments:</strong> could not check for updates.', 'crypto-payments-woo' ) ),
                esc_html( $state['error'] ?? '' )
            );
        } elseif ( version_compare( $release['version'], CPW_VERSION, '>' ) ) {
            printf(
                '<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
                wp_kses_post(
                    sprintf(
                        /* translators: 1: new version number, 2: installed version number. */
                        __( '<strong>Crypto Payments %1$s is available</strong> (you have %2$s).', 'crypto-payments-woo' ),
                        esc_html( $release['version'] ),
                        esc_html( CPW_VERSION )
                    )
                ),
                esc_url( admin_url( 'update-core.php' ) ),
                esc_html__( 'Go to Updates', 'crypto-payments-woo' )
            );
        } else {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                wp_kses_post(
                    sprintf(
                        /* translators: %s: version number. */
                        __( '<strong>Crypto Payments</strong> is up to date (version %s).', 'crypto-payments-woo' ),
                        esc_html( CPW_VERSION )
                    )
                )
            );
        }
    }

    /**
     * Whether automatic updates are on for this plugin.
     */
    public static function auto_update_enabled(): bool {
        return in_array( CPW_PLUGIN_BASENAME, (array) get_site_option( 'auto_update_plugins', [] ), true );
    }

    /**
     * Nonce-protected URL that turns automatic updates on or off.
     *
     * A link rather than a form: WooCommerce wraps the gateway settings in its own form, and forms can't nest.
     *
     * @param bool $enable Whether the link turns them on.
     */
    private static function auto_update_url( bool $enable ): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=cpw_auto_update&enable=' . ( $enable ? '1' : '0' ) ), self::CHECK_NONCE );
    }

    /**
     * Turns automatic updates for this plugin on or off.
     */
    public static function handle_auto_update() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'crypto-payments-woo' ), 403 );
        }
        check_admin_referer( self::CHECK_NONCE );

        $enabled = (array) get_site_option( 'auto_update_plugins', [] );
        $enabled = array_diff( $enabled, [ CPW_PLUGIN_BASENAME ] );
        if ( ! empty( $_GET['enable'] ) ) {
            $enabled[] = CPW_PLUGIN_BASENAME;
        }
        update_site_option( 'auto_update_plugins', array_values( array_unique( $enabled ) ) );

        wp_safe_redirect( self::settings_url() );
        exit;
    }

    /**
     * "Updates" section on the gateway's settings screen.
     */
    public static function render_settings_section() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        $state   = self::cached_state();
        $release = $state['release'] ?? null;
        $newer   = $release && version_compare( $release['version'], CPW_VERSION, '>' );
        ?>
        <h2 id="cpw-updates"><?php esc_html_e( 'Updates', 'crypto-payments-woo' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Installed version', 'crypto-payments-woo' ); ?></th>
                <td><?php echo esc_html( CPW_VERSION ); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Latest release', 'crypto-payments-woo' ); ?></th>
                <td>
                    <?php if ( $release ) : ?>
                        <?php echo esc_html( $release['version'] ); ?>
                        <?php if ( $newer ) : ?>
                            <strong><?php esc_html_e( 'Update available.', 'crypto-payments-woo' ); ?></strong> <a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Go to Updates', 'crypto-payments-woo' ); ?></a>
                        <?php else : ?>
                            <?php esc_html_e( 'You\'re up to date.', 'crypto-payments-woo' ); ?>
                        <?php endif; ?>
                    <?php elseif ( ! empty( $state['error'] ) ) : ?>
                        <?php echo esc_html( $state['error'] ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Not checked yet.', 'crypto-payments-woo' ); ?>
                    <?php endif; ?>
                    <?php if ( ! empty( $state['checked'] ) ) : ?>
                        <?php
                        /* translators: %s: time span, e.g. "5 mins". */
                        echo esc_html( sprintf( __( '(checked %s ago)', 'crypto-payments-woo' ), human_time_diff( (int) $state['checked'] ) ) );
                        ?>
                    <?php endif; ?>
                    <p>
                        <a class="button" href="<?php echo esc_url( self::check_url( 'settings' ) ); ?>"><?php esc_html_e( 'Check for updates now', 'crypto-payments-woo' ); ?></a>
                        <a class="button-link" href="<?php echo esc_url( self::repo_url() . '/releases' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Releases on GitHub', 'crypto-payments-woo' ); ?></a>
                    </p>
                    <p class="description">
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* translators: %s: link to the GitHub repository. */
                                __( 'Updates come from %s. WordPress checks twice a day and shows new versions under Dashboard → Updates like any other plugin.', 'crypto-payments-woo' ),
                                '<a href="' . esc_url( self::repo_url() ) . '" target="_blank" rel="noopener">github.com/' . esc_html( self::REPO ) . '</a>'
                            )
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <?php if ( function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' ) ) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Automatic updates', 'crypto-payments-woo' ); ?></th>
                    <td>
                        <?php if ( self::auto_update_enabled() ) : ?>
                            <?php esc_html_e( 'On. New releases install automatically in the background.', 'crypto-payments-woo' ); ?>
                            <a class="button" href="<?php echo esc_url( self::auto_update_url( false ) ); ?>"><?php esc_html_e( 'Turn off', 'crypto-payments-woo' ); ?></a>
                        <?php else : ?>
                            <?php esc_html_e( 'Off. You install new releases yourself from Dashboard → Updates.', 'crypto-payments-woo' ); ?>
                            <a class="button" href="<?php echo esc_url( self::auto_update_url( true ) ); ?>"><?php esc_html_e( 'Turn on', 'crypto-payments-woo' ); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }
}
