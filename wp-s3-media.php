<?php
/**
 * Plugin Name: WP S3 Media
 * Plugin URI:  https://github.com/your-repo/wp-s3-media
 * Description: Offload, serve, and sync WordPress media with Amazon S3. Supports IAM key/secret and IAM Role authentication.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0+
 * Text Domain: wp-s3-media
 * Requires PHP: 8.1
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

// Runtime PHP version check — guards against WordPress versions older than 5.2
// that don't read the "Requires PHP" header above.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>WP S3 Media</strong> requires PHP 8.1 or higher. ';
        echo 'You are running PHP ' . esc_html( PHP_VERSION ) . '. Please upgrade PHP to use this plugin.';
        echo '</p></div>';
    } );
    // Deactivate the plugin gracefully
    add_action( 'admin_init', function () {
        deactivate_plugins( plugin_basename( __FILE__ ) );
    } );
    return;
}

define( 'WP_S3_MEDIA_VERSION', '1.0.0' );
define( 'WP_S3_MEDIA_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WP_S3_MEDIA_URL',     plugin_dir_url( __FILE__ ) );

// Autoload AWS SDK via Composer if available
if ( file_exists( WP_S3_MEDIA_DIR . 'vendor/autoload.php' ) ) {
    require_once WP_S3_MEDIA_DIR . 'vendor/autoload.php';
}

require_once WP_S3_MEDIA_DIR . 'includes/class-s3-client.php';
require_once WP_S3_MEDIA_DIR . 'includes/class-media-uploader.php';
require_once WP_S3_MEDIA_DIR . 'includes/class-url-rewriter.php';
require_once WP_S3_MEDIA_DIR . 'includes/class-offloader.php';
require_once WP_S3_MEDIA_DIR . 'includes/class-sync.php';
require_once WP_S3_MEDIA_DIR . 'admin/class-settings-page.php';

/**
 * Bootstrap the plugin.
 */
function wp_s3_media_init() {
    // Admin settings page
    if ( is_admin() ) {
        new WP_S3_Media_Settings_Page();
    }

    $options = get_option( 'wp_s3_media_options', [] );

    // Only activate features if the bucket is configured
    if ( empty( $options['bucket'] ) ) {
        return;
    }

    $s3 = new WP_S3_Media_Client( $options );

    // Feature: auto-upload on media add
    $uploader = new WP_S3_Media_Uploader( $s3, $options );
    $uploader->register_hooks();

    // Feature: rewrite media URLs to S3
    $rewriter = new WP_S3_Media_URL_Rewriter( $options );
    $rewriter->register_hooks();

    // Feature: offload existing uploads (admin AJAX)
    $offloader = new WP_S3_Media_Offloader( $s3, $options );
    $offloader->register_hooks();

    // Feature: sync (delete from S3 when WP deletes)
    $sync = new WP_S3_Media_Sync( $s3, $options );
    $sync->register_hooks();
}
add_action( 'plugins_loaded', 'wp_s3_media_init' );

/**
 * Activation: create default options.
 */
register_activation_hook( __FILE__, function () {
    if ( false === get_option( 'wp_s3_media_options' ) ) {
        add_option( 'wp_s3_media_options', [
            'auth_method'    => 'keys',
            'access_key'     => '',
            'secret_key'     => '',
            'region'         => 'us-east-1',
            'bucket'         => '',
            'path_prefix'    => 'wp-uploads/',
            'serve_from_s3'  => '1',
            'delete_local'   => '0',
            'cloudfront_url' => '',
        ] );
    }
} );
