<?php
defined( 'ABSPATH' ) || exit;

/**
 * Keeps S3 in sync with WordPress:
 *  - Deletes S3 objects when an attachment is deleted in WordPress.
 *  - (Extensible) Can be expanded to handle renames / regenerated thumbnails.
 */
class WP_S3_Media_Sync {

    /** @var WP_S3_Media_Client */
    private $s3;

    /** @var array */
    private $options;

    public function __construct( WP_S3_Media_Client $s3, array $options ) {
        $this->s3      = $s3;
        $this->options = $options;
    }

    public function register_hooks(): void {
        // Fires just before WordPress deletes the attachment post + files
        add_action( 'delete_attachment', [ $this, 'delete_from_s3' ], 10, 1 );
    }

    /**
     * Delete the attachment's original file + all sized thumbnails from S3.
     *
     * @param int $attachment_id
     */
    public function delete_from_s3( int $attachment_id ): void {
        // Only act on attachments we know were uploaded to S3
        if ( ! get_post_meta( $attachment_id, '_wp_s3_media_uploaded', true ) ) {
            return;
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file ) {
            return;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        $sub_dir  = trailingslashit( dirname( $file ) );
        $files    = [ $file ];

        if ( ! empty( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size ) {
                if ( ! empty( $size['file'] ) ) {
                    $files[] = $sub_dir . $size['file'];
                }
            }
        }

        foreach ( $files as $local_path ) {
            $s3_key = $this->s3->key_for_file( $local_path );
            $result = $this->s3->delete( $s3_key );

            if ( is_wp_error( $result ) ) {
                error_log( '[WP S3 Media] Failed to delete from S3: ' . $s3_key . ' – ' . $result->get_error_message() );
            }
        }
    }
}
