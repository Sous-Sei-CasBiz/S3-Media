# WP S3 Media

A WordPress plugin that offloads, serves, and syncs your media library with Amazon S3.

## Features

| Feature | Description |
|---|---|
| **Auto-upload** | New media uploads are pushed to S3 automatically |
| **Serve from S3** | Media URLs are rewritten to point directly to S3 or CloudFront |
| **Offload existing** | Bulk-migrate your current `/wp-content/uploads/` to S3 via the admin UI |
| **Sync on delete** | When you delete an attachment in WordPress, it is removed from S3 too |

---

## Requirements

- PHP 8.0+
- WordPress 6.0+
- Composer
- AWS SDK for PHP v3 (installed via Composer)

---

## Installation

### 1. Upload the plugin

Place the `wp-s3-media` folder in `/wp-content/plugins/`.

### 2. Install the AWS SDK

```bash
cd wp-content/plugins/wp-s3-media
composer install --no-dev --optimize-autoloader
```

### 3. Activate

Go to **Plugins → Installed Plugins** and activate **WP S3 Media**.

### 4. Configure

Go to **Settings → S3 Media** and fill in your details.

---

## Authentication

### Option A — IAM Access Key & Secret (recommended for most setups)

1. Create an IAM user in the AWS console with an attached policy like:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject",
        "s3:HeadObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::YOUR-BUCKET-NAME",
        "arn:aws:s3:::YOUR-BUCKET-NAME/*"
      ]
    }
  ]
}
```

2. Generate an Access Key and Secret for that user.
3. **For better security**, add these to `wp-config.php` instead of the settings page:

```php
define( 'WP_S3_MEDIA_ACCESS_KEY', 'AKIAIOSFODNN7EXAMPLE' );
define( 'WP_S3_MEDIA_SECRET_KEY', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
```

### Option B — IAM Role (EC2 instance profile)

1. Create an IAM Role with the same S3 permissions as above.
2. Attach the role to your EC2 instance.
3. Select **IAM Role** on the settings page — no credentials to store.

---

## Settings Reference

| Setting | Description |
|---|---|
| Auth method | Keys or IAM Role |
| AWS Region | e.g. `ap-southeast-1` for Singapore |
| Bucket name | Your S3 bucket name |
| Path prefix | Folder prefix inside the bucket (default: `wp-uploads/`) |
| CloudFront URL | Optional — use a CloudFront distribution in front of S3 |
| Serve from S3 | Rewrite WordPress media URLs to S3/CloudFront |
| Remove local copies | Delete local files after a successful S3 upload |

---

## Offloading Existing Media

1. Go to **Settings → S3 Media**.
2. Scroll to **Offload existing media to S3**.
3. Click **Start offload** and monitor the progress bar.

Files are processed in batches of 5 to avoid PHP timeouts. Errors are listed below the progress bar.

---

## Security Notes

- Never commit your Access Key or Secret to version control.
- Store credentials in `wp-config.php` using the constants above.
- Restrict bucket permissions to the minimum required (see IAM policy above).
- Set a bucket policy that denies public `s3:PutObject` from outside your server.

---

## File Structure

```
wp-s3-media/
├── wp-s3-media.php               # Plugin entry point
├── composer.json                 # AWS SDK dependency
├── includes/
│   ├── class-s3-client.php       # S3 client wrapper (auth, upload, delete, URL)
│   ├── class-media-uploader.php  # Auto-upload on media add
│   ├── class-url-rewriter.php    # Rewrite media URLs to S3
│   ├── class-offloader.php       # Bulk offload existing uploads
│   └── class-sync.php            # Delete from S3 on WP delete
└── admin/
    ├── class-settings-page.php   # Settings UI
    └── admin.js                  # Offload progress bar (jQuery)
```
