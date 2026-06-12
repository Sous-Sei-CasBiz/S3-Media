<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rewrites WordPress media URLs to point directly to S3 (or CloudFront).
 * Works via WordPress's wp_get_attachment_url filter and a global
 * output-buffer content filter for cases where URLs are hardcoded in content.
 */
class WP_S3_Media_URL_Rewriter {

    /** @var array */
    private $options;

    /** @var string Local uploads base URL */
    private $local_base_url;

    /** @var string S3 / CloudFront base URL */
    private $s3_base_url;

    public function __construct( array $options ) {
        $this->options = $options;

        $upload_dir           = wp_upload_dir();
        $this->local_base_url = trailingslashit( $upload_dir['baseurl'] );

        // Derive the S3 base URL (without trailing slash)
        $cf = rtrim( $options['cloudfront_url'] ?? '', '/' );
        if ( $cf ) {
            $this->s3_base_url = $cf . '/' . ltrim( $options['path_prefix'] ?? 'wp-uploads/', '/' );
        } else {
            $region = $options['region'] ?? 'us-east-1';
            $bucket = $options['bucket'] ?? '';
            $prefix = ltrim( $options['path_prefix'] ?? 'wp-uploads/', '/' );
            $this->s3_base_url = "https://s3.{$region}.amazonaws.com/{$bucket}/{$prefix}";
        }
        $this->s3_base_url = trailingslashit( $this->s3_base_url );
    }

    public function register_hooks(): void {
        if ( empty( $this->options['serve_from_s3'] ) ) {
            return;
        }

        // Rewrite individual attachment URLs
        add_filter( 'wp_get_attachment_url', [ $this, 'rewrite_attachment_url' ], 20, 2 );

        // Rewrite URLs embedded in post content
        add_filter( 'the_content', [ $this, 'rewrite_content_urls' ], 20 );

        // Rewrite srcset URLs
        add_filter( 'wp_calculate_image_srcset', [ $this, 'rewrite_srcset' ], 20 );
    }

    /**
     * Rewrite a single attachment URL.
     */
    public function rewrite_attachment_url( string $url, int $attachment_id ): string {
        // Only rewrite attachments that have been uploaded to S3
        if ( ! get_post_meta( $attachment_id, '_wp_s3_media_uploaded', true ) ) {
            return $url;
        }
        return $this->swap_base( $url );
    }

    /**
     * Rewrite media URLs found in post content.
     * Only rewrites URLs belonging to attachments confirmed to be on S3
     * (i.e. have the _wp_s3_media_uploaded meta flag set).
     * Old images not yet offloaded are left pointing to the local server.
     */
    public function rewrite_content_urls( string $content ): string {
        $s3_ids = $this->get_s3_attachment_ids();
        if ( empty( $s3_ids ) ) {
            return $content;
        }

        // Build a find-and-replace map: only swap URLs for files we know are on S3
        foreach ( $s3_ids as $id ) {
            $local_url = wp_get_attachment_url( $id );
            if ( ! $local_url ) {
                continue;
            }

            // Temporarily unhook this filter to avoid infinite recursion
            remove_filter( 'wp_get_attachment_url', [ $this, 'rewrite_attachment_url' ], 20 );
            $original_url = wp_get_attachment_url( $id );
            add_filter( 'wp_get_attachment_url', [ $this, 'rewrite_attachment_url' ], 20, 2 );

            if ( ! $original_url ) {
                continue;
            }

            $s3_url  = $this->swap_base( $original_url );
            $content = str_replace( $original_url, $s3_url, $content );

            // Also swap resized thumbnail URLs (e.g. image-300x169.jpg)
            $meta = wp_get_attachment_metadata( $id );
            if ( ! empty( $meta['sizes'] ) ) {
                $base_local = trailingslashit( dirname( $original_url ) );
                $base_s3    = trailingslashit( dirname( $s3_url ) );
                foreach ( $meta['sizes'] as $size ) {
                    if ( ! empty( $size['file'] ) ) {
                        $content = str_replace(
                            $base_local . $size['file'],
                            $base_s3    . $size['file'],
                            $content
                        );
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Rewrite URLs inside a srcset array.
     * Only rewrites entries for attachments confirmed to be on S3.
     */
    public function rewrite_srcset( array $sources ): array {
        $s3_ids = $this->get_s3_attachment_ids();
        if ( empty( $s3_ids ) ) {
            return $sources;
        }

        // Build a flat list of S3-confirmed file basenames for quick lookup
        $s3_files = [];
        foreach ( $s3_ids as $id ) {
            $meta = wp_get_attachment_metadata( $id );
            if ( ! empty( $meta['file'] ) ) {
                $s3_files[] = basename( $meta['file'] );
            }
            if ( ! empty( $meta['sizes'] ) ) {
                foreach ( $meta['sizes'] as $size ) {
                    if ( ! empty( $size['file'] ) ) {
                        $s3_files[] = $size['file'];
                    }
                }
            }
        }

        foreach ( $sources as &$source ) {
            if ( empty( $source['url'] ) ) {
                continue;
            }
            $filename = basename( (string) parse_url( $source['url'], PHP_URL_PATH ) );
            if ( in_array( $filename, $s3_files, true ) ) {
                $source['url'] = $this->swap_base( $source['url'] );
            }
        }

        return $sources;
    }

    /**
     * Get all attachment IDs that have been confirmed uploaded to S3.
     * Cached per request to avoid repeated DB queries.
     *
     * @return int[]
     */
    private function get_s3_attachment_ids(): array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache;
        }

        $query = new WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_wp_s3_media_uploaded',
                    'value' => '1',
                ],
            ],
        ] );

        $cache = $query->posts;
        return $cache;
    }

    /**
     * Swap the uploads base URL for the S3 base URL.
     */
    private function swap_base( string $url ): string {
        $local = rtrim( $this->local_base_url, '/' );
        if ( strpos( $url, $local ) === 0 ) {
            return rtrim( $this->s3_base_url, '/' ) . substr( $url, strlen( $local ) );
        }
        return $url;
    }
}
