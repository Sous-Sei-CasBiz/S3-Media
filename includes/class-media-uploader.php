<?php
defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the WordPress media upload pipeline.
 * After a file is added to the Media Library it is pushed to S3,
 * and optionally removed from the local filesystem.
 */
class WP_S3_Media_Uploader {

    /** @var WP_S3_Media_Client */
    private $s3;

    /** @var array */
    private $options;

    public function __construct( WP_S3_Media_Client $s3, array $options ) {
        $this->s3      = $s3;
        $this->options = $options;
    }

    public function register_hooks(): void {
        // Fires after WordPress has generated all image sizes for an attachment
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'upload_attachment_to_s3' ], 20, 2 );
    }

    /**
     * Upload the original file + all generated sizes to S3.
     *
     * @param array $metadata    Attachment metadata array.
     * @param int   $attachment_id
     * @return array             Unmodified metadata (we only side-effect upload).
     */
    public function upload_attachment_to_s3( array $metadata, int $attachment_id ): array {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $sub_dir    = trailingslashit( dirname( $file ) );

        // Collect all file paths to upload: original + generated sizes
        $files_to_upload = [ $file ];

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $size_file = $sub_dir . $size_data['file'];
                    if ( file_exists( $size_file ) ) {
                        $files_to_upload[] = $size_file;
                    }
                }
            }
        }

        foreach ( $files_to_upload as $local_path ) {
            $s3_key = $this->s3->key_for_file( $local_path );
            $result = $this->s3->upload( $local_path, $s3_key );

            if ( is_wp_error( $result ) ) {
                // Log but don't break the upload flow
                error_log( '[WP S3 Media] Upload failed for ' . $local_path . ': ' . $result->get_error_message() );
                continue;
            }

            // Optionally remove the local copy after successful S3 upload
            if ( ! empty( $this->options['delete_local'] ) ) {
                @unlink( $local_path );
            }
        }

        // Store a flag so other features know this attachment is on S3
        update_post_meta( $attachment_id, '_wp_s3_media_uploaded', '1' );

        return $metadata;
    }
}
