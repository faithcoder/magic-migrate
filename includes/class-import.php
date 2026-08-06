<?php

if (!defined('ABSPATH')) {
    exit;
}

class Magic_Migrate_Import {

    public static function extract_archive($file_path, $extract_path) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_missing', __('Uploaded file not found.', 'magic-migrate'));
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error(
                'zip_missing',
                __('ZipArchive PHP extension is not available on this server.', 'magic-migrate')
            );
        }

        wp_mkdir_p($extract_path);

        $zip = new ZipArchive();
        $result = $zip->open($file_path);

        if ($result !== true) {
            return new WP_Error(
                'zip_error',
                sprintf(
                    /* translators: %d: error code from ZipArchive */
                    __('Failed to open archive. Error code: %d', 'magic-migrate'),
                    $result
                )
            );
        }

        $zip->extractTo($extract_path);
        $zip->close();

        return true;
    }

    public static function get_import_info($extract_path) {
        $info = [
            'has_database' => false,
            'has_uploads' => false,
            'has_plugins' => false,
            'has_themes' => false,
            'db_file' => null,
            'wp_content' => false,
            'site_url' => '',
            'file_count' => 0,
        ];

        $sql_files = array_merge(
            glob($extract_path . '/*.sql'),
            glob($extract_path . '/database/*.sql'),
            glob($extract_path . '/db/*.sql')
        );

        if (!empty($sql_files)) {
            $info['has_database'] = true;
            $info['db_file'] = $sql_files[0];
        }

        if (file_exists($extract_path . '/uploads')) {
            $info['has_uploads'] = true;
        }

        if (file_exists($extract_path . '/plugins')) {
            $info['has_plugins'] = true;
        }

        if (file_exists($extract_path . '/themes') || file_exists($extract_path . '/theme')) {
            $info['has_themes'] = true;
        }

        if (file_exists($extract_path . '/wp-content')) {
            $info['wp_content'] = true;
            if (!$info['has_uploads'] && file_exists($extract_path . '/wp-content/uploads')) {
                $info['has_uploads'] = true;
            }
            if (!$info['has_plugins'] && file_exists($extract_path . '/wp-content/plugins')) {
                $info['has_plugins'] = true;
            }
            if (!$info['has_themes'] && file_exists($extract_path . '/wp-content/themes')) {
                $info['has_themes'] = true;
            }
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extract_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            $info['file_count']++;
        }

        return $info;
    }

    public static function perform_import($extract_path) {
        $info = self::get_import_info($extract_path);

        if ($info['has_database'] && $info['db_file'] && file_exists($info['db_file'])) {
            $db_result = self::import_database($info['db_file'], $extract_path);
            if (is_wp_error($db_result)) {
                return $db_result;
            }
        }

        if ($info['has_uploads']) {
            self::import_uploads($extract_path, $info);
        }

        if ($info['has_plugins'] && file_exists($extract_path . '/wp-content/plugins')) {
            self::import_plugins(
                $extract_path . '/wp-content/plugins',
                WP_CONTENT_DIR . '/plugins'
            );
        } elseif ($info['has_plugins'] && file_exists($extract_path . '/plugins')) {
            self::import_plugins(
                $extract_path . '/plugins',
                WP_CONTENT_DIR . '/plugins'
            );
        }

        if ($info['has_themes'] && file_exists($extract_path . '/wp-content/themes')) {
            self::import_themes(
                $extract_path . '/wp-content/themes',
                WP_CONTENT_DIR . '/themes'
            );
        } elseif ($info['has_themes'] && file_exists($extract_path . '/themes')) {
            self::import_themes(
                $extract_path . '/themes',
                WP_CONTENT_DIR . '/themes'
            );
        } elseif ($info['has_themes'] && file_exists($extract_path . '/theme')) {
            self::import_themes(
                $extract_path . '/theme',
                WP_CONTENT_DIR . '/themes'
            );
        }

        self::update_site_url($extract_path);

        return true;
    }

    private static function import_database($sql_file, $extract_path) {
        global $wpdb;

        $handle = fopen($sql_file, 'r');
        if (!$handle) {
            return new WP_Error('sql_open', __('Could not read SQL file.', 'magic-migrate'));
        }

        $sql_content = '';
        $sql_content .= self::replace_site_urls_in_line('', $extract_path);

        $query = '';
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if (empty($line) || strpos($line, '--') === 0 || strpos($line, '#') === 0) {
                continue;
            }

            $query .= self::replace_site_urls_in_line($line, $extract_path) . "\n";

            if (substr($line, -1) === ';') {
                $query = trim($query);
                if (!empty($query)) {
                    $result = $wpdb->query($query);

                    if ($result === false && $wpdb->last_error) {
                        error_log(
                            'Magic Migrate SQL Error: ' . $wpdb->last_error .
                            ' | Query: ' . substr($query, 0, 200)
                        );
                    }
                }
                $query = '';
                unset($result);
            }
        }

        if (!empty(trim($query))) {
            $wpdb->query($query);
        }

        fclose($handle);

        return true;
    }

    private static function replace_site_urls_in_line($line, $extract_path) {
        static $replacements = null;

        if ($replacements === null) {
            $replacements = [];
            $package_json = $extract_path . '/package.json';

            if (file_exists($package_json)) {
                $pkg = json_decode(file_get_contents($package_json), true);
                if (!empty($pkg['site_url'])) {
                    $old_url = rtrim($pkg['site_url'], '/');
                    $new_url = rtrim(get_site_url(), '/');
                    $replacements[$old_url] = $new_url;

                    $old_domain = wp_parse_url($old_url, PHP_URL_HOST);
                    $new_domain = wp_parse_url($new_url, PHP_URL_HOST);
                    if ($old_domain && $new_domain && $old_domain !== $new_domain) {
                        $replacements[$old_domain] = $new_domain;
                    }
                }
            }
        }

        return strtr($line, $replacements);
    }

    private static function import_uploads($extract_path, $info) {
        $src_uploads = $info['wp_content']
            ? $extract_path . '/wp-content/uploads'
            : $extract_path . '/uploads';

        if (!file_exists($src_uploads)) {
            return;
        }

        $dest_uploads = WP_CONTENT_DIR . '/uploads';
        self::copy_directory($src_uploads, $dest_uploads);
    }

    private static function import_plugins($src, $dest) {
        if (!file_exists($src)) {
            return;
        }
        self::copy_directory($src, $dest);
    }

    private static function import_themes($src, $dest) {
        if (!file_exists($src)) {
            return;
        }
        self::copy_directory($src, $dest);
    }

    private static function copy_directory($src, $dest) {
        if (!is_dir($src)) {
            return;
        }

        if (!is_dir($dest)) {
            wp_mkdir_p($dest);
        }

        $dir = opendir($src);
        if (!$dir) {
            return;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $src_path = $src . '/' . $file;
            $dest_path = $dest . '/' . $file;

            if (is_dir($src_path)) {
                self::copy_directory($src_path, $dest_path);
            } else {
                copy($src_path, $dest_path);
            }
        }
        closedir($dir);
    }

    private static function update_site_url($extract_path) {
        $package_json = $extract_path . '/package.json';
        if (!file_exists($package_json)) {
            return;
        }

        $pkg = json_decode(file_get_contents($package_json), true);
        if (empty($pkg['site_url'])) {
            return;
        }

        global $wpdb;

        $old_url = rtrim($pkg['site_url'], '/');
        $new_url = rtrim(get_site_url(), '/');

        $tables = ['options', 'posts', 'postmeta', 'usermeta', 'termmeta'];

        foreach ($tables as $table) {
            $full_table = $wpdb->prefix . $table;

            $table_exists = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $full_table)
            );
            if ($table_exists !== $full_table) {
                continue;
            }

            if ($table === 'options') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE `{$full_table}` SET `option_value` = REPLACE(`option_value`, %s, %s) WHERE `option_name` != 'cron'",
                    $old_url,
                    $new_url
                ));
            } elseif ($table === 'posts') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE `{$full_table}` SET `post_content` = REPLACE(`post_content`, %s, %s)",
                    $old_url,
                    $new_url
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE `{$full_table}` SET `meta_value` = REPLACE(`meta_value`, %s, %s)",
                    $old_url,
                    $new_url
                ));
            }
        }
    }

    public static function cleanup_temp($file_uuid) {
        $tmp_dir = MAGIC_MIGRATE_TEMP_DIR . '/' . sanitize_key($file_uuid);
        self::delete_directory($tmp_dir);
    }

    public static function delete_directory($path) {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            unlink($path);
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($path);
    }
}
