<?php
defined( 'ABSPATH' ) || exit;

/**
 * Offloads existing WordPress media library files to S3.
 * Uses paginated AJAX requests so large libraries don't time out.
 */
class WP_S3_Media_Offloader {

    /** @var WP_S3_Media_Client */
    private $s3;

    /** @var array */
    private $options;

    /** Batch size per AJAX request */
    const BATCH_SIZE = 5;

    public function __construct( WP_S3_Media_Client $s3, array $options ) {
        $this->s3      = $s3;
        $this->options = $options;
    }

    public function register_hooks(): void {
        add_action( 'wp_ajax_wp_s3_offload_batch', [ $this, 'handle_offload_batch' ] );
        add_action( 'wp_ajax_wp_s3_offload_count', [ $this, 'handle_offload_count' ] );
    }

    /**
     * Return total number of attachments not yet on S3.
     */
    public function handle_offload_count(): void {
        check_ajax_referer( 'wp_s3_media_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $count = $this->count_pending();
        wp_send_json_success( [ 'pending' => $count ] );
    }

    /**
     * Process one batch of attachments.
     */
    public function handle_offload_batch(): void {
        check_ajax_referer( 'wp_s3_media_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $offset      = absint( $_POST['offset'] ?? 0 );
        $attachments = $this->get_pending_attachments( $offset, self::BATCH_SIZE );
        $processed   = [];
        $errors      = [];

        foreach ( $attachments as $attachment ) {
            $result = $this->offload_attachment( $attachment->ID );
            if ( is_wp_error( $result ) ) {
                $attached_file = get_attached_file( $attachment->ID );
                $filename      = $attached_file ? basename( $attached_file ) : "ID {$attachment->ID}";
                $errors[] = [
                    'id'      => $attachment->ID,
                    'message' => "[{$filename}] " . $result->get_error_message(),
                ];
            } else {
                $processed[] = $attachment->ID;
            }
        }

        wp_send_json_success( [
            'processed' => $processed,
            'errors'    => $errors,
            'pending'   => $this->count_pending(),
            'has_more'  => count( $attachments ) === self::BATCH_SIZE,
        ] );
    }

    /**
     * Offload all files for a single attachment to S3.
     *
     * @return true|WP_Error
     */
    public function offload_attachment( int $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            $label = $file ? $file : "attachment ID {$attachment_id}";
            return new WP_Error( 'file_missing', "Local file not found: {$label}" );
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        $sub_dir  = trailingslashit( dirname( $file ) );
        $files    = [ $file ];

        if ( ! empty( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size ) {
                if ( ! empty( $size['file'] ) ) {
                    $path = $sub_dir . $size['file'];
                    if ( file_exists( $path ) ) {
                        $files[] = $path;
                    }
                }
            }
        }

        foreach ( $files as $local_path ) {
            $s3_key = $this->s3->key_for_file( $local_path );
            $result = $this->s3->upload( $local_path, $s3_key );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            if ( ! empty( $this->options['delete_local'] ) ) {
                @unlink( $local_path );
            }
        }

        update_post_meta( $attachment_id, '_wp_s3_media_uploaded', '1' );
        return true;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function count_pending(): int {
        $query = new WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_wp_s3_media_uploaded',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );
        return $query->found_posts;
    }

    private function get_pending_attachments( int $offset, int $limit ): array {
        $query = new WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'meta_query'     => [
                [
                    'key'     => '_wp_s3_media_uploaded',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );
        return $query->posts;
    }
}
