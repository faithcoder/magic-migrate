<?php
/**
 * Plugin Name: Magic Migrate
 * Plugin URI:  https://magicmigrate.local
 * Description: Effortlessly export and import your WordPress site with unlimited file size support via chunked uploads. Works local-to-live, live-to-local, and any environment in between.
 * Version:     1.0.0
 * Author:      Magic Migrate Team
 * Author URI:  https://magicmigrate.local
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: magic-migrate
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.7
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MAGIC_MIGRATE_VERSION', '1.0.0');
define('MAGIC_MIGRATE_DIR', plugin_dir_path(__FILE__));
define('MAGIC_MIGRATE_URL', plugin_dir_url(__FILE__));
define('MAGIC_MIGRATE_BACKUPS_DIR', MAGIC_MIGRATE_DIR . 'backups');
define('MAGIC_MIGRATE_TEMP_DIR', MAGIC_MIGRATE_DIR . 'temp');
define('MAGIC_MIGRATE_CHUNK_SIZE', 512 * 1024);

require_once MAGIC_MIGRATE_DIR . 'includes/class-ajax.php';
require_once MAGIC_MIGRATE_DIR . 'includes/class-admin.php';
require_once MAGIC_MIGRATE_DIR . 'includes/class-import.php';
require_once MAGIC_MIGRATE_DIR . 'includes/class-export.php';

function magic_migrate_init() {
    Magic_Migrate_Ajax::init();
    Magic_Migrate_Admin::init();
}
add_action('init', 'magic_migrate_init');

function magic_migrate_activate() {
    $dirs = [MAGIC_MIGRATE_BACKUPS_DIR, MAGIC_MIGRATE_TEMP_DIR];
    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', '<?php /* Silence is golden. */');
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }
}
register_activation_hook(__FILE__, 'magic_migrate_activate');

function magic_migrate_admin_notices() {
    if (!class_exists('ZipArchive')) {
        echo '<div class="notice notice-error"><p>';
        echo wp_kses_post(
            __('<strong>Magic Migrate</strong> requires the ZipArchive PHP extension. Please contact your hosting provider to enable it.', 'magic-migrate')
        );
        echo '</p></div>';
    }
}
add_action('admin_notices', 'magic_migrate_admin_notices');

function magic_migrate_check_dirs() {
    if (!file_exists(MAGIC_MIGRATE_BACKUPS_DIR) || !file_exists(MAGIC_MIGRATE_TEMP_DIR)) {
        magic_migrate_activate();
    }
}
add_action('admin_init', 'magic_migrate_check_dirs');

function magic_migrate_load_textdomain() {
    load_plugin_textdomain('magic-migrate', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'magic_migrate_load_textdomain');

if (!function_exists('rest_sanitize_boolean')) {
    function rest_sanitize_boolean($value) {
        if (is_string($value)) {
            $value = strtolower($value);
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }
        return (bool) $value;
    }
}
