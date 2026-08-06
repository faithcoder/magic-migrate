# Magic Migrate

**Contributors:** faithcoder  
**Tags:** migration, backup, export, import, transfer, migrate, database, all-in-one migration, site transfer, unlimited upload  
**Requires at least:** 5.7  
**Tested up to:** 6.7  
**Requires PHP:** 7.4  
**Stable tag:** 1.0.0  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Easily export and import your WordPress site across any environment — local to live, live to local, or anywhere in between. Unlimited file size support via chunked uploads.

## Description

Magic Migrate empowers you to move your WordPress site effortlessly between servers, domains, and hosting providers. Whether you are migrating from a local development environment to production, cloning a live site for staging, or simply creating backups — Magic Migrate handles it all.

### Key Features

- **Unlimited File Size** — Powered by chunked upload technology. Split large backup files into small pieces in the browser and reassemble them on the server. No PHP `upload_max_filesize` or nginx `client_max_body_size` restrictions apply.
- **Drag & Drop Import** — Simply drag your `.wpress`, `.zip`, `.sql`, or `.xml` backup file into the upload zone and watch the progress bar.
- **One-Click Export** — Create a complete backup of your site in `.wpress` format with selectable options: database, media uploads, plugins, and themes.
- **Smart URL Replacement** — Automatically detects and replaces the old site URL and domain throughout your database during import.
- **Cross-Environment Ready** — Works on local development environments (Local by Flywheel, MAMP, XAMPP, Docker), shared hosting, VPS, dedicated servers, and cloud platforms.
- **Security First** — Nonce verification, capability checks, path traversal protection, file type validation, and output escaping at every layer.
- **Backup Manager** — View, download, and delete previously created backups from a clean admin interface.
- **Chunked Export/Import** — Memory-efficient database dump and restore using batched queries and streaming file operations.
- **Zero Dependencies** — Uses only built-in WordPress APIs and PHP extensions (`ZipArchive` required).

### Supported File Formats

- **`.wpress`** — Magic Migrate native format (recommended)
- **`.zip`** — Standard ZIP archives
- **`.sql`** — SQL database dumps
- **`.xml`** — WordPress WXR export files
- **`.tar.gz` / `.tar`** — Compressed tar archives

### How Unlimited Upload Works

Magic Migrate slices your backup file into 512KB chunks directly in the browser using the File API. Each chunk is sent as an individual AJAX request — well below any server-imposed upload limit. On the server side, chunks are reassembled into the original file. This means a 5GB backup imports just as smoothly as a 5MB one.

### Environment Compatibility

| Environment | Supported |
|---|---|
| Local by Flywheel | Yes |
| MAMP / XAMPP / WAMP | Yes |
| Docker / Lando / DDEV | Yes |
| Shared Hosting (cPanel, Plesk) | Yes |
| VPS / Dedicated Server | Yes |
| Cloud (AWS, DigitalOcean, etc.) | Yes |
| Apache / Nginx / LiteSpeed | Yes |
| PHP 7.4 – 8.4 | Yes |
| WordPress 5.7 – 6.7+ | Yes |

## Installation

### From the WordPress Admin

1. Go to **Plugins > Add New**
2. Click **Upload Plugin**
3. Choose the `magic-migrate.zip` file
4. Click **Install Now** and then **Activate**

### Manual Installation

1. Download and unzip the plugin
2. Upload the `magic-migrate` folder to `/wp-content/plugins/`
3. Activate the plugin through the **Plugins** menu

### Requirements

- WordPress 5.7 or higher
- PHP 7.4 or higher
- PHP `ZipArchive` extension (typically enabled by default)

> **Note:** If `ZipArchive` is not available, an admin notice will appear. Contact your hosting provider to enable it.

## Usage

### Exporting Your Site

1. Go to **Magic Migrate > Export**
2. Choose which components to include: Database, Media Uploads, Plugins, Themes
3. Click **Export To File**
4. Once complete, click **Download Backup** to save the `.wpress` file

### Importing a Site

1. Go to **Magic Migrate > Import**
2. Drag & drop your backup file into the upload zone, or click to browse
3. Watch the progress bar as chunks upload
4. Review the import summary and click **Start Import**
5. Your site content will be imported with automatic URL replacement

### Managing Backups

1. Go to **Magic Migrate > Backups**
2. View all previously created exports and uploaded imports
3. Download or delete backups as needed

## Frequently Asked Questions

### What is the maximum file size I can import?

There is no hard limit. The chunked upload technology bypasses server restrictions. However, you should ensure your server has sufficient disk space to store the uploaded and extracted files.

### Does the plugin handle URL replacement?

Yes. Magic Migrate automatically detects the original site URL from the backup's metadata and replaces it throughout your database, post content, and meta values during import.

### Will it work on shared hosting?

Yes. The plugin uses only standard PHP and WordPress APIs. As long as `ZipArchive` is available and your host allows WordPress to run, Magic Migrate will work.

### Does the export include the entire wp-content folder?

By default, exports include the database and media uploads. You can optionally include plugins and themes by checking the respective boxes on the Export screen.

### Can I use this to clone a site?

Yes. Export from the source site, then import the backup file into the destination site. All URLs will be automatically updated.

### What about large databases?

Database export uses batched queries (100 rows at a time) and streams directly to disk. Import reads SQL line-by-line. This prevents memory exhaustion even with very large databases.

## Screenshots

1. Import page with drag & drop upload zone and chunked progress bar
2. Export page with component selection and progress indicator
3. Backups management table with download and delete actions

## Changelog

### 1.0.0
- Initial release
- Chunked upload with unlimited file size support
- Export to `.wpress` format with component selection
- Drag & drop import with progress tracking
- Automatic URL replacement during import
- Backup management interface
- Cross-environment compatibility (local, shared, cloud, VPS)
