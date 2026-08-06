<?php

if (!defined('ABSPATH')) {
    exit;
}

class Magic_Migrate_Ajax {

    public static function init() {
        add_action('wp_ajax_magic_migrate_upload_chunk', [__CLASS__, 'handle_upload_chunk']);
        add_action('wp_ajax_magic_migrate_get_progress', [__CLASS__, 'handle_get_progress']);
        add_action('wp_ajax_magic_migrate_prepare_export', [__CLASS__, 'handle_prepare_export']);
        add_action('wp_ajax_magic_migrate_check_export_progress', [__CLASS__, 'handle_check_export_progress']);
        add_action('wp_ajax_magic_migrate_download_export', [__CLASS__, 'handle_download_export']);
        add_action('wp_ajax_magic_migrate_delete_backup', [__CLASS__, 'handle_delete_backup']);
        add_action('wp_ajax_magic_migrate_prepare_import', [__CLASS__, 'handle_prepare_import']);
        add_action('wp_ajax_magic_migrate_import_file_content', [__CLASS__, 'handle_import_file_content']);
    }

    public static function handle_upload_chunk() {
        @set_time_limit(0);
        @ini_set('display_errors', 0);
        check_ajax_referer('magic_migrate_nonce', 'nonce');

        if (!current_user_can('import')) {
            wp_send_json_error(['message' => __('Permission denied.', 'magic-migrate')]);
        }

        $filename = isset($_GET['filename']) ? sanitize_file_name(wp_unslash($_GET['filename'])) : '';
        $chunk_index = isset($_GET['chunk']) ? intval(wp_unslash($_GET['chunk'])) : -1;
        $total_chunks = isset($_GET['chunks']) ? intval(wp_unslash($_GET['chunks'])) : 0;
        $file_uuid = isset($_GET['file_uuid']) ? sanitize_key(wp_unslash($_GET['file_uuid'])) : '';

        if (empty($filename) || $chunk_index < 0 || empty($file_uuid)) {
            wp_send_json_error(['message' => __('Invalid upload parameters.', 'magic-migrate')]);
        }

        $tmp_dir = MAGIC_MIGRATE_TEMP_DIR . '/' . $file_uuid;
        if (!file_exists($tmp_dir)) {
            wp_mkdir_p($tmp_dir);
        }

        $dest = $tmp_dir . '/' . sprintf('%s.part.%05d', $filename, $chunk_index);

        $input = fopen('php://input', 'rb');
        $output = fopen($dest, 'wb');
        if (!$input || !$output) {
            if ($input) fclose($input);
            if ($output) fclose($output);
            wp_send_json_error(['message' => __('Failed to write chunk to disk.', 'magic-migrate')]);
        }

        $written = 0;
        while (!feof($input)) {
            $buffer = fread($input, 8192);
            fwrite($output, $buffer);
            $written += strlen($buffer);
        }
        fclose($input);
        fclose($output);

        $chunks_remaining = $total_chunks - ($chunk_index + 1);

        if ($chunks_remaining <= 0) {
            $final_path = $tmp_dir . '/' . $filename;
            $final_fp = fopen($final_path, 'wb');
            if (!$final_fp) {
                wp_send_json_error(['message' => __('Failed to create final file.', 'magic-migrate')]);
            }

            for ($i = 0; $i < $total_chunks; $i++) {
                $part_file = $tmp_dir . '/' . sprintf('%s.part.%05d', $filename, $i);
                if (!file_exists($part_file)) {
                    fclose($final_fp);
                    wp_send_json_error(['message' => sprintf(__('Missing chunk %d.', 'magic-migrate'), $i)]);
                }
                $handle = fopen($part_file, 'rb');
                while (!feof($handle)) {
                    fwrite($final_fp, fread($handle, 8192));
                }
                fclose($handle);
                unlink($part_file);
            }
            fclose($final_fp);

            $file_size = filesize($final_path);

            wp_send_json_success([
                'complete' => true,
                'message' => sprintf(
                    /* translators: 1: filename, 2: formatted file size */
                    __('Upload complete \u2014 %1$s (%2$s)', 'magic-migrate'),
                    $filename,
                    size_format($file_size)
                ),
                'filename' => $filename,
                'file_uuid' => $file_uuid,
                'file_size' => $file_size,
                'file_size_formatted' => size_format($file_size),
            ]);
        }

        wp_send_json_success([
            'complete' => false,
            'chunk' => $chunk_index,
            'total' => $total_chunks,
            'remaining' => $chunks_remaining,
            'percent' => round(($chunk_index + 1) / $total_chunks * 100, 1),
        ]);
    }

    public static function handle_get_progress() {
        @set_time_limit(0);
        check_ajax_referer('magic_migrate_nonce', 'nonce');

        if (!current_user_can('import')) {
            wp_send_json_error(['message' => __('Permission denied.', 'magic-migrate')]);
        }

        $file_uuid = isset($_GET['file_uuid']) ? sanitize_key(wp_unslash($_GET['file_uuid'])) : '';
        $filename = isset($_GET['filename']) ? sanitize_file_name(wp_unslash($_GET['filename'])) : '';
        $total_chunks = isset($_GET['chunks']) ? intval(wp_unslash($_GET['chunks'])) : 0;

        if (empty($file_uuid) || empty($filename)) {
            wp_send_json_error(['message' => __('Invalid parameters.', 'magic-migrate')]);
        }

        $tmp_dir = MAGIC_MIGRATE_TEMP_DIR . '/' . $file_uuid;
        $received = 0;

        for ($i = 0; $i < $total_chunks; $i++) {
            $part_file = $tmp_dir . '/' . sprintf('%s.part.%05d', $filename, $i);
            if (file_exists($part_file)) {
                $received++;
            }
        }

        $final_path = $tmp_dir . '/' . $filename;
        if (file_exists($final_path)) {
            wp_send_json_success([
                'complete' => true,
                'received' => $total_chunks,
                'total' => $total_chunks,
            ]);
        }

        wp_send_json_success([
            'complete' => false,
            'received' => $received,
            'total' => $total_chunks,
            'percent' => $total_chunks > 0 ? round($received / $total_chunks * 100, 1) : 0,
        ]);
    }

    public static function handle_prepare_import() {
        @set_time_limit(0);
        check_ajax_referer('magic_migrate_nonce', 'nonce');

        if (!current_user_can('import')) {
            wp_send_json_error(['message' => __('Permission denied.', 'magic-migrate')]);
        }

        $file_uuid = isset($_POST['file_uuid']) ? sanitize_key(wp_unslash($_POST['file_uuid'])) : '';
        $filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : '';

        if (empty($file_uuid) || empty($filename)) {
            wp_send_json_error(['message' => __('Invalid parameters.', 'magic-migrate')]);
        }

        $tmp_dir = MAGIC_MIGRATE_TEMP_DIR . '/' . $file_uuid;
        $final_path = $tmp_dir . '/' . $filename;

        if (!file_exists($final_path)) {
            wp_send_json_error(['message' => __('Uploaded file not found.', 'magic-migrate')]);
        }

        $allowed_extensions = ['zip', 'sql', 'xml', 'wpress', 'tar', 'gz', 'tar.gz'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'gz') {
            $name_no_gz = pathinfo(substr($filename, 0, -3), PATHINFO_EXTENSION);
            if ($name_no_gz === 'tar') {
                $ext = 'tar.gz';
            }
        }
        if (!in_array($ext, $allowed_extensions, true)) {
            wp_send_json_error(['message' => __('Unsupported file type.', 'magic-migrate')]);
        }

        $backup_dir = MAGIC_MIGRATE_BACKUPS_DIR . '/' . $file_uuid;
        wp_mkdir_p($backup_dir);

        $extract_path = $backup_dir . '/extracted';
        $result = Magic_Migrate_Import::extract_archive($final_path, $extract_path);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $info = Magic_Migrate_Import::get_import_info($extract_path);

        set_transient('magic_migrate_import_' . $file_uuid, [
            'uuid' => $file_uuid,
            'filename' => $filename,
            'extract_path' => $extract_path,
            'file_size' => filesize($final_path),
            'info' => $info,
            'status' => 'prepared',
            'created_at' => current_time('mysql'),
        ], DAY_IN_SECONDS);

        wp_send_json_success([
            'message' => __('File prepared for import.', 'magic-migrate'),
            'info' => $info,
            'file_uuid' => $file_uuid,
        ]);
    }

    public static function handle_import_file_content() {
        @set_time_limit(0);
        check_ajax_referer('magic_migrate_nonce', 'nonce');

        if (!current_user_can('import')) {
            wp_send_json_error(['message' => __('Permission denied.', 'magic-migrate')]);
        }

        $file_uuid = isset($_POST['file_uuid']) ? sanitize_key(wp_unslash($_POST['file_uuid'])) : '';
        $confirm = isset($_POST['confirm']) ? rest_sanitize_boolean(wp_unslash($_POST['confirm'])) : false;

        if (empty($file_uuid)) {
            wp_send_json_error(['message' => __('Invalid parameters.', 'magic-migrate')]);
        }

        $data = get_transient('magic_migrate_import_' . $file_uuid);
        if (!$data) {
            wp_send_json_error(['message' => __('Import session expired. Please re-upload.', 'magic-migrate')]);
        }

        if (!$confirm) {
            wp_send_json_error(['message' => __('Please confirm the import.', 'magic-migrate')]);
        }

        if (!isset($data['extract_path']) || !file_exists($data['extract_path'])) {
            wp_send_json_error(['message' => __('Extracted files not found.', 'magic-migrate')]);
        }

        $result = Magic_Migrate_Import::perform_import($data['extract_path']);

        if (is_wp_error($result)) {
            $data['status'] = 'failed';
            set_transient('magic_migrate_import_' . $file_uuid, $data, DAY_IN_SECONDS);
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $data['status'] = 'completed';
        set_transient('magic_migrate_import_' . $file_uuid, $data, DAY_IN_SECONDS);

        Magic_Migrate_Import::cleanup_temp($file_uuid);

        wp_send_json_success([
            'message' => __('Import completed successfully! Your site content has been migrated.', 'magic-migrate'),
            'file_uuid' => $file_uuid,
        ]);
    }

    public static function handle_prepare_export() {
        @set_time_limit(0);
        check_ajax_referer('magic_migrate_nonce', 'nonce');

        if (!current_user_can('export')) {
            wp_send_json_error(['message' => __('Permission denied.', 'magic-migrate')]);
        }

        $export_options = [
            'include_database' => isset($_POST['include_database']) ? rest_sanitize_boolean(wp_unslash($_POST['include_database'])) : true,
            'include_uploads' => isset($_POST['include_uploads']) ? rest_sanitize_boolean(wp_unslash($_POST['include_uploads'])) : true,
            'include_plugins' => isset($_POST['include_plugins']) ? rest_sanitize_boolean(wp_unslash($_POST['include_plugins'])) : false,
            'include_themes' => isset($_POST['include_themes']) ? rest_sanitize_boolean(wp_unslash($_POST['include_themes'])) : false,
        ];

        if (!function_exists('wp_generate_uuid4')) {
            $export_uuid = wp_generate_password(32, false);
        } else {
            $export_uuid = wp_generate_uuid4();
        }
        $export_id = substr(md5($export_uuid), 0, 12);

        $export_dir = MAGIC_MIGRATE_BACKUPS_DIR . '/' . $export_id;
        wp_mkdir_p($export_dir);

        $result = Magic_Migrate_Export::create_export($export_dir, $export_options);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        set_transient('magic_migrate_export_' . $export_id, [
            'id' => $export_id,
            'options' => $export_options,
            'path' => $result['path'],
            'file_size' => filesize($result['path']),
            'status' => 'completed',
            'created_at' => current_time('mysql'),
        ], DAY_IN_SECONDS);

        wp_send_json_success([
            'message' => __('Export completed!', 'magic-migrate'),
            'export_id' => $export_id,
            'file_size' => size_format(filesize($result['path'])),
            'filename' => basename($result['path']),
        ]);
    }

    public static function handle_check_export_progress() {
        @set_time_limit(0);
        check_ajax_referer('magic_migrate_nonce', 'nonce');

        if (!current_user_can('export')) {
            wp_send_json_error(['message' => __('Permission denied.', 'magic-migrate')]);
        }

        $export_id = isset($_GET['export_id']) ? sanitize_key(wp_unslash($_GET['export_id'])) : '';

        if (empty($export_id)) {
            wp_send_json_error(['message' => __('Invalid export ID.', 'magic-migrate')]);
        }

        $data = get_transient('magic_migrate_export_' . $export_id);
        if (!$data) {
            wp_send_json_error(['message' => __('Export not found.', 'magic-migrate')]);
        }

        wp_send_json_success($data);
    }

    public static function handle_download_export() {
        check_ajax_referer('magic_migrate_nonce', 'nonce');

        if (!current_user_can('export')) {
            status_header(403);
            die(esc_html__('Permission denied.', 'magic-migrate'));
        }

        $export_id = isset($_GET['export_id']) ? sanitize_key(wp_unslash($_GET['export_id'])) : '';

        if (empty($export_id)) {
            status_header(400);
            die(esc_html__('Invalid export ID.', 'magic-migrate'));
        }

        $data = get_transient('magic_migrate_export_' . $export_id);
        if (!$data || empty($data['path']) || !file_exists($data['path'])) {
            status_header(404);
            die(esc_html__('Export file not found.', 'magic-migrate'));
        }

        $file_path = realpath($data['path']);
        if (!$file_path || strpos($file_path, realpath(MAGIC_MIGRATE_BACKUPS_DIR)) !== 0) {
            status_header(403);
            die(esc_html__('Invalid file path.', 'magic-migrate'));
        }

        $filename = basename($file_path);
        $filesize = filesize($file_path);

        if (ob_get_level()) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $filesize);
        header('X-Content-Type-Options: nosniff');

        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            status_header(500);
            die(esc_html__('Could not read file.', 'magic-migrate'));
        }

        while (!feof($handle)) {
            echo fread($handle, 8192);
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }
        fclose($handle);
        exit;
    }

    public static function handle_delete_backup() {
        @set_time_limit(0);
        check_ajax_referer('magic_migrate_nonce', 'nonce');

        if (!current_user_can('delete_plugins')) {
            wp_send_json_error(['message' => __('Permission denied.', 'magic-migrate')]);
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_key(wp_unslash($_POST['backup_id'])) : '';

        if (empty($backup_id)) {
            wp_send_json_error(['message' => __('Invalid backup ID.', 'magic-migrate')]);
        }

        $backup_path = MAGIC_MIGRATE_BACKUPS_DIR . '/' . $backup_id;
        if (!file_exists($backup_path)) {
            wp_send_json_error(['message' => __('Backup not found.', 'magic-migrate')]);
        }

        $real_backup_path = realpath($backup_path);
        $real_backups_dir = realpath(MAGIC_MIGRATE_BACKUPS_DIR);
        if (!$real_backup_path || !$real_backups_dir || strpos($real_backup_path, $real_backups_dir) !== 0) {
            wp_send_json_error(['message' => __('Invalid backup path.', 'magic-migrate')]);
        }

        Magic_Migrate_Export::delete_directory($real_backup_path);
        delete_transient('magic_migrate_export_' . $backup_id);

        wp_send_json_success(['message' => __('Backup deleted successfully.', 'magic-migrate')]);
    }
}
