<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the WP S3 Media settings page under Settings → S3 Media.
 */
class WP_S3_Media_Settings_Page {

    const OPTION_KEY = 'wp_s3_media_options';
    const PAGE_SLUG  = 'wp-s3-media-settings';

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'add_menu_page' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    // -----------------------------------------------------------------------
    // Menu & Settings registration
    // -----------------------------------------------------------------------

    public function add_menu_page(): void {
        add_options_page(
            'S3 Media Settings',
            'S3 Media',
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( self::OPTION_KEY, self::OPTION_KEY, [
            'sanitize_callback' => [ $this, 'sanitize_options' ],
        ] );
    }

    public function sanitize_options( $input ): array {
        $clean = [];
        $clean['auth_method']    = in_array( $input['auth_method'] ?? '', [ 'keys', 'iam_role' ], true )
                                    ? $input['auth_method']
                                    : 'keys';
        $clean['access_key']     = sanitize_text_field( $input['access_key'] ?? '' );
        $clean['secret_key']     = sanitize_text_field( $input['secret_key'] ?? '' );
        $clean['region']         = sanitize_text_field( $input['region'] ?? 'us-east-1' );
        $clean['bucket']         = sanitize_text_field( $input['bucket'] ?? '' );
        $clean['path_prefix']    = trailingslashit( sanitize_text_field( $input['path_prefix'] ?? 'wp-uploads' ) );
        $clean['cloudfront_url'] = esc_url_raw( $input['cloudfront_url'] ?? '' );
        $clean['serve_from_s3']  = ! empty( $input['serve_from_s3'] ) ? '1' : '0';
        $clean['delete_local']   = ! empty( $input['delete_local'] ) ? '1' : '0';
        return $clean;
    }

    // -----------------------------------------------------------------------
    // Scripts / styles for the offload progress UI
    // -----------------------------------------------------------------------

    public function enqueue_scripts( string $hook ): void {
        if ( $hook !== 'settings_page_' . self::PAGE_SLUG ) {
            return;
        }
        wp_enqueue_script(
            'wp-s3-media-admin',
            WP_S3_MEDIA_URL . 'admin/admin.js',
            [ 'jquery' ],
            WP_S3_MEDIA_VERSION,
            true
        );
        wp_localize_script( 'wp-s3-media-admin', 'wpS3Media', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wp_s3_media_nonce' ),
        ] );
    }

    // -----------------------------------------------------------------------
    // Page render
    // -----------------------------------------------------------------------

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $options = get_option( self::OPTION_KEY, [] );
        $auth    = $options['auth_method'] ?? 'keys';
        ?>
        <div class="wrap">
            <h1>
                <span style="font-size:1.4em;vertical-align:middle;margin-right:6px;">☁</span>
                S3 Media Settings
            </h1>

            <?php settings_errors( self::OPTION_KEY ); ?>

            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_KEY ); ?>

                <!-- ── Authentication ──────────────────────────────────── -->
                <h2 class="title">Authentication</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Auth method</th>
                        <td>
                            <label>
                                <input type="radio" name="<?= self::OPTION_KEY ?>[auth_method]"
                                       value="keys" <?php checked( $auth, 'keys' ); ?>>
                                IAM Access Key &amp; Secret
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="<?= self::OPTION_KEY ?>[auth_method]"
                                       value="iam_role" <?php checked( $auth, 'iam_role' ); ?>>
                                IAM Role (EC2 instance profile)
                            </label>
                            <p class="description">
                                For IAM Role, attach an S3-access role to your EC2 instance — no keys needed.<br>
                                For extra security with keys, define <code>WP_S3_MEDIA_ACCESS_KEY</code> and
                                <code>WP_S3_MEDIA_SECRET_KEY</code> in <code>wp-config.php</code> instead of saving them here.
                            </p>
                        </td>
                    </tr>

                    <tbody id="s3-key-fields" style="<?= $auth === 'iam_role' ? 'display:none' : '' ?>">
                        <tr>
                            <th scope="row"><label for="s3_access_key">Access Key ID</label></th>
                            <td>
                                <input type="text" id="s3_access_key" class="regular-text"
                                       name="<?= self::OPTION_KEY ?>[access_key]"
                                       value="<?= esc_attr( $options['access_key'] ?? '' ) ?>">
                                <?php if ( defined( 'WP_S3_MEDIA_ACCESS_KEY' ) ) : ?>
                                    <p class="description" style="color:green;">✔ Loaded from <code>wp-config.php</code></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="s3_secret_key">Secret Access Key</label></th>
                            <td>
                                <input type="password" id="s3_secret_key" class="regular-text"
                                       name="<?= self::OPTION_KEY ?>[secret_key]"
                                       value="<?= esc_attr( $options['secret_key'] ?? '' ) ?>">
                                <?php if ( defined( 'WP_S3_MEDIA_SECRET_KEY' ) ) : ?>
                                    <p class="description" style="color:green;">✔ Loaded from <code>wp-config.php</code></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- ── Bucket ──────────────────────────────────────────── -->
                <h2 class="title">Bucket</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="s3_region">AWS Region</label></th>
                        <td>
                            <input type="text" id="s3_region" class="regular-text"
                                   name="<?= self::OPTION_KEY ?>[region]"
                                   value="<?= esc_attr( $options['region'] ?? 'us-east-1' ) ?>">
                            <p class="description">e.g. <code>us-east-1</code>, <code>ap-southeast-1</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="s3_bucket">Bucket name</label></th>
                        <td>
                            <input type="text" id="s3_bucket" class="regular-text"
                                   name="<?= self::OPTION_KEY ?>[bucket]"
                                   value="<?= esc_attr( $options['bucket'] ?? '' ) ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="s3_prefix">Path prefix</label></th>
                        <td>
                            <input type="text" id="s3_prefix" class="regular-text"
                                   name="<?= self::OPTION_KEY ?>[path_prefix]"
                                   value="<?= esc_attr( $options['path_prefix'] ?? 'wp-uploads/' ) ?>">
                            <p class="description">Folder inside the bucket where files are stored. Default: <code>wp-uploads/</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="s3_cloudfront">CloudFront URL</label></th>
                        <td>
                            <input type="url" id="s3_cloudfront" class="regular-text"
                                   name="<?= self::OPTION_KEY ?>[cloudfront_url]"
                                   placeholder="https://d1234abcd.cloudfront.net"
                                   value="<?= esc_attr( $options['cloudfront_url'] ?? '' ) ?>">
                            <p class="description">Optional. If set, all media URLs will use CloudFront instead of the S3 endpoint.</p>
                        </td>
                    </tr>
                </table>

                <!-- ── Behaviour ───────────────────────────────────────── -->
                <h2 class="title">Behaviour</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Serve media from S3</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?= self::OPTION_KEY ?>[serve_from_s3]"
                                       value="1" <?php checked( $options['serve_from_s3'] ?? '1', '1' ); ?>>
                                Rewrite URLs for S3-uploaded files to serve directly from S3 / CloudFront
                            </label>
                            <p class="description">
                                Only rewrites URLs for files that have already been uploaded to S3.
                                Old images not yet offloaded will continue to load from your server.
                                Uncheck to use S3 as backup storage only without affecting live URLs.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Remove local copies</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?= self::OPTION_KEY ?>[delete_local]"
                                       value="1" <?php checked( $options['delete_local'] ?? '0', '1' ); ?>>
                                Delete local files after a successful S3 upload
                            </label>
                            <p class="description" style="color:#c0392b;">
                                ⚠ Only enable this if you're certain S3 is your primary storage.
                                Deleted local files cannot be recovered through WordPress.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save settings' ); ?>
            </form>

            <hr>

            <!-- ── Offload existing media ──────────────────────────────── -->
            <h2>Offload existing media to S3</h2>
            <p>
                Move media files that are currently stored locally to S3.
                This runs in small batches so it won't time out on large libraries.
            </p>
            <div id="s3-offload-ui">
                <button id="s3-offload-start" class="button button-secondary">Start offload</button>
                <span id="s3-offload-status" style="margin-left:12px;color:#666;"></span>
                <div id="s3-offload-bar-wrap"
                     style="display:none;margin-top:10px;background:#e0e0e0;border-radius:4px;height:18px;width:400px;">
                    <div id="s3-offload-bar"
                         style="height:18px;background:#2271b1;border-radius:4px;width:0%;transition:width .3s;"></div>
                </div>
                <ul id="s3-offload-errors" style="color:#c0392b;margin-top:8px;"></ul>
            </div>
        </div>

        <script>
        // Toggle key fields based on auth method selection
        document.querySelectorAll('input[name="<?= self::OPTION_KEY ?>[auth_method]"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.getElementById('s3-key-fields').style.display =
                    this.value === 'iam_role' ? 'none' : '';
            });
        });
        </script>
        <?php
    }
}
