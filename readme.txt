=== WP S3 Media ===
Contributors: yourwordpressusername
Tags: s3, amazon, media, uploads, offload, cdn, cloudfront
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload, serve, and sync your WordPress media library with Amazon S3.

== Description ==

WP S3 Media is a lightweight plugin that connects your WordPress media library to Amazon S3. It automatically uploads new media to S3, rewrites media URLs to serve files directly from S3 or CloudFront, bulk-offloads existing uploads, and syncs deletions between WordPress and S3.

= Features =

* **Auto-upload** — New media uploads are pushed to S3 automatically
* **Serve from S3** — Media URLs are rewritten to point directly to S3 or CloudFront
* **Bulk offload** — Migrate existing uploads to S3 via a progress bar in the admin
* **Sync on delete** — Deleting an attachment in WordPress removes it from S3 too
* **Dual auth** — Supports both IAM Access Key / Secret and IAM Role (EC2 instance profile)
* **Multi-site friendly** — Use one S3 bucket with different path prefixes per site

= Requirements =

* PHP 8.0 or higher
* Composer (to install the AWS SDK)
* An Amazon Web Services account with an S3 bucket

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/wp-s3-media/`
2. Run `composer install --no-dev` inside the plugin folder
3. Activate the plugin through the **Plugins** menu in WordPress
4. Go to **Settings → S3 Media** and configure your bucket details

== Frequently Asked Questions ==

= Do I need Composer? =

Yes. The plugin depends on the AWS SDK for PHP, which is installed via Composer. Run `composer install --no-dev --optimize-autoloader` inside the plugin folder before activating.

= Can I use one bucket for multiple WordPress sites? =

Yes. Install the plugin on each site and set a different **Path prefix** per site (e.g. `site1/uploads/`, `site2/uploads/`). All sites share the same bucket but store files in separate folders.

= What happens to existing media when I activate the plugin? =

Existing media is not affected until you run the **Offload existing media** tool in Settings → S3 Media. Only new uploads are automatically sent to S3 after activation.

= Is it safe to enable "Remove local copies"? =

Only enable this after you have confirmed S3 is working correctly. Once local files are deleted they cannot be recovered through WordPress. We recommend keeping local copies until you are fully confident in your S3 setup.

= Does it support CloudFront? =

Yes. Enter your CloudFront distribution URL in the settings and all media URLs will use CloudFront instead of the S3 endpoint directly.

= Which AWS regions are supported? =

All AWS regions are supported. Enter the region code (e.g. `ap-southeast-1`, `us-east-1`) in the settings page.

== Screenshots ==

1. Settings page — authentication, bucket configuration, and behaviour options
2. Offload tool — progress bar for bulk migrating existing media to S3

== Changelog ==

= 1.0.0 =
* Initial release
* Auto-upload new media to S3
* Serve media from S3 / CloudFront
* Bulk offload existing uploads with progress bar
* Sync deletions between WordPress and S3
* IAM Access Key and IAM Role authentication support

== Upgrade Notice ==

= 1.0.0 =
Initial release.
