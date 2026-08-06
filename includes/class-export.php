<?php

if (!defined('ABSPATH')) {
    exit;
}

class Magic_Migrate_Export {

    public static function create_export($export_dir, $options) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error(
                'zip_missing',
                __('ZipArchive PHP extension is not available on this server. Please contact your hosting provider.', 'magic-migrate')
            );
        }

        wp_mkdir_p($export_dir);

        $site_name = get_bloginfo('name');
        $safe_name = sanitize_title($site_name);
        $date = gmdate('Y-m-d-His');
        $zip_filename = $safe_name . '-' . $date . '.wpress';
        $zip_path = $export_dir . '/' . $zip_filename;

        $package_info = [
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'site_name' => $site_name,
            'export_date' => current_time('mysql'),
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'options' => $options,
        ];

        file_put_contents(
            $export_dir . '/package.json',
            wp_json_encode($package_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error(
                'zip_create',
                __('Failed to create backup file. Check disk permissions.', 'magic-migrate')
            );
        }

        $zip->addFile($export_dir . '/package.json', 'package.json');

        if (!empty($options['include_database'])) {
            $db_file = $export_dir . '/database.sql';
            $db_result = self::export_database($db_file);
            if (!is_wp_error($db_result)) {
                $zip->addFile($db_file, 'database.sql');
            }
        }

        if (!empty($options['include_uploads'])) {
            $uploads_dir = WP_CONTENT_DIR . '/uploads';
            if (is_dir($uploads_dir)) {
                self::add_dir_to_zip($zip, $uploads_dir, 'uploads');
            }
        }

        if (!empty($options['include_plugins'])) {
            $plugins_dir = WP_CONTENT_DIR . '/plugins';
            if (is_dir($plugins_dir)) {
                self::add_dir_to_zip($zip, $plugins_dir, 'plugins');
            }
        }

        if (!empty($options['include_themes'])) {
            $active_theme = get_template_directory();
            if (is_dir($active_theme)) {
                $theme_name = basename($active_theme);
                self::add_dir_to_zip($zip, $active_theme, 'themes/' . $theme_name);
            }
        }

        $zip->close();

        if (file_exists($export_dir . '/database.sql')) {
            unlink($export_dir . '/database.sql');
        }

        return [
            'path' => $zip_path,
            'filename' => $zip_filename,
        ];
    }

    private static function export_database($output_file) {
        global $wpdb;

        $tables = $wpdb->get_col('SHOW TABLES');

        if (empty($tables)) {
            return new WP_Error('no_tables', __('No tables found in database.', 'magic-migrate'));
        }

        $handle = fopen($output_file, 'w');
        if (!$handle) {
            return new WP_Error('file_error', __('Could not create SQL file.', 'magic-migrate'));
        }

        fwrite($handle, "-- Magic Migrate SQL Export\n");
        fwrite($handle, "-- Generated: " . current_time('mysql') . "\n");
        fwrite($handle, "-- Site URL: " . get_site_url() . "\n\n");

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($handle, "SET AUTOCOMMIT = 0;\n");
        fwrite($handle, "START TRANSACTION;\n\n");

        foreach ($tables as $table) {
            $create_table = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE `%1s`', $table), ARRAY_N);
            if ($create_table && isset($create_table[1])) {
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $create_table[1] . ";\n\n");
            }

            $offset = 0;
            $batch_size = 100;
            do {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `%1s` LIMIT %d, %d", $table, $offset, $batch_size),
                    ARRAY_A
                );

                if (!empty($rows)) {
                    fwrite($handle, "LOCK TABLES `{$table}` WRITE;\n");

                    foreach ($rows as $row) {
                        $values = array_map(function ($value) use ($wpdb) {
                            if ($value === null) {
                                return 'NULL';
                            }
                            return "'" . $wpdb->_real_escape($value) . "'";
                        }, array_values($row));

                        fwrite($handle, "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n");
                    }

                    fwrite($handle, "UNLOCK TABLES;\n\n");
                }

                $offset += $batch_size;
            } while (!empty($rows));
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fwrite($handle, "COMMIT;\n");

        fclose($handle);

        return true;
    }

    private static function add_dir_to_zip($zip, $dir, $zip_path) {
        if (!is_dir($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $file_path = $file->getRealPath();
            $relative_path = $zip_path . '/' . str_replace($dir, '', $file_path);
            $relative_path = str_replace('\\', '/', $relative_path);

            if ($file->isDir()) {
                $zip->addEmptyDir($relative_path);
            } else {
                $zip->addFile($file_path, $relative_path);
            }
        }
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

    public static function get_site_size() {
        $total_size = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ABSPATH, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $total_size += $file->getSize();
            }
        }

        return $total_size;
    }
}
