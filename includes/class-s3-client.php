<?php
defined( 'ABSPATH' ) || exit;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Thin wrapper around the AWS S3Client.
 * Supports both IAM Access Key / Secret and IAM Role (instance profile).
 */
class WP_S3_Media_Client {

    /** @var S3Client */
    private $client;

    /** @var array Plugin options */
    private $options;

    public function __construct( array $options ) {
        $this->options = $options;
        $this->client  = $this->build_client();
    }

    /**
     * Build the S3Client based on the chosen auth method.
     */
    private function build_client(): S3Client {
        $config = [
            'version' => 'latest',
            'region'  => $this->options['region'] ?? 'us-east-1',
        ];

        $auth_method = $this->options['auth_method'] ?? 'keys';

        if ( $auth_method === 'keys' ) {
            // Prefer wp-config.php constants over saved options for security
            $key    = defined( 'WP_S3_MEDIA_ACCESS_KEY' )
                        ? WP_S3_MEDIA_ACCESS_KEY
                        : ( $this->options['access_key'] ?? '' );
            $secret = defined( 'WP_S3_MEDIA_SECRET_KEY' )
                        ? WP_S3_MEDIA_SECRET_KEY
                        : ( $this->options['secret_key'] ?? '' );

            if ( $key && $secret ) {
                $config['credentials'] = [
                    'key'    => $key,
                    'secret' => $secret,
                ];
            }
            // If keys are empty the SDK will still try the default chain
            // (env vars, instance profile) — a safe fallback.
        }
        // 'iam_role' → omit credentials entirely; SDK uses instance profile / ECS task role

        return new S3Client( $config );
    }

    /** @return S3Client */
    public function get_client(): S3Client {
        return $this->client;
    }

    /** @return string */
    public function get_bucket(): string {
        return $this->options['bucket'] ?? '';
    }

    /** @return string */
    public function get_prefix(): string {
        return rtrim( $this->options['path_prefix'] ?? 'wp-uploads/', '/' ) . '/';
    }

    /**
     * Upload a local file to S3.
     *
     * ACL is intentionally omitted — AWS buckets created after April 2023 have
     * Object Ownership set to "Bucket owner enforced", which rejects any request
     * that includes an ACL parameter. Access is controlled via bucket policy instead.
     *
     * @param string $local_path  Absolute path to the file.
     * @param string $s3_key      Destination key inside the bucket.
     * @return string|WP_Error    Public S3 URL on success, WP_Error on failure.
     */
    public function upload( string $local_path, string $s3_key ) {
        try {
            $mime = mime_content_type( $local_path ) ?: 'application/octet-stream';
            $this->client->putObject( [
                'Bucket'      => $this->get_bucket(),
                'Key'         => $s3_key,
                'SourceFile'  => $local_path,
                'ContentType' => $mime,
            ] );
            return $this->get_url( $s3_key );
        } catch ( AwsException $e ) {
            return new WP_Error( 'wp_s3_upload_failed', $e->getAwsErrorMessage() );
        }
    }

    /**
     * Delete an object from S3.
     *
     * @param string $s3_key
     * @return true|WP_Error
     */
    public function delete( string $s3_key ) {
        try {
            $this->client->deleteObject( [
                'Bucket' => $this->get_bucket(),
                'Key'    => $s3_key,
            ] );
            return true;
        } catch ( AwsException $e ) {
            return new WP_Error( 'wp_s3_delete_failed', $e->getAwsErrorMessage() );
        }
    }

    /**
     * Check whether an object exists in S3.
     */
    public function exists( string $s3_key ): bool {
        try {
            $this->client->headObject( [
                'Bucket' => $this->get_bucket(),
                'Key'    => $s3_key,
            ] );
            return true;
        } catch ( AwsException $e ) {
            return false;
        }
    }

    /**
     * Build the public URL for an S3 key.
     * Uses CloudFront base URL if configured.
     */
    public function get_url( string $s3_key ): string {
        $cf = rtrim( $this->options['cloudfront_url'] ?? '', '/' );
        if ( $cf ) {
            return $cf . '/' . ltrim( $s3_key, '/' );
        }
        $region = $this->options['region'] ?? 'us-east-1';
        $bucket = $this->get_bucket();
        // Path-style URL works in all regions
        return "https://s3.{$region}.amazonaws.com/{$bucket}/{$s3_key}";
    }

    /**
     * Build the S3 key for a given attachment file path.
     */
    public function key_for_file( string $file_path ): string {
        $upload_dir  = wp_upload_dir();
        $uploads_base = trailingslashit( $upload_dir['basedir'] );
        $relative     = str_replace( $uploads_base, '', $file_path );
        return $this->get_prefix() . $relative;
    }
}
