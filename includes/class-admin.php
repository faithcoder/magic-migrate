<?php

if (!defined('ABSPATH')) {
    exit;
}

class Magic_Migrate_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function add_admin_menu() {
        add_menu_page(
            'Magic Migrate',
            'Magic Migrate',
            'import',
            'magic-migrate',
            [__CLASS__, 'render_page'],
            'dashicons-migrate',
            75
        );

        add_submenu_page(
            'magic-migrate',
            'Import',
            'Import',
            'import',
            'magic-migrate',
            [__CLASS__, 'render_page']
        );

        add_submenu_page(
            'magic-migrate',
            'Export',
            'Export',
            'export',
            'magic-migrate-export',
            [__CLASS__, 'render_page']
        );

        add_submenu_page(
            'magic-migrate',
            'Backups',
            'Backups',
            'import',
            'magic-migrate-backups',
            [__CLASS__, 'render_page']
        );
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'magic-migrate') === false) {
            return;
        }

        wp_enqueue_style(
            'magic-migrate-admin',
            MAGIC_MIGRATE_URL . 'assets/css/magic-migrate.css',
            [],
            MAGIC_MIGRATE_VERSION
        );

        wp_enqueue_script(
            'magic-migrate-drop',
            MAGIC_MIGRATE_URL . 'assets/js/magic-migrate.js',
            [],
            MAGIC_MIGRATE_VERSION,
            true
        );

        wp_localize_script('magic-migrate-drop', 'MagicMigrate', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('magic_migrate_nonce'),
            'chunk_size' => MAGIC_MIGRATE_CHUNK_SIZE,
            'max_upload_size' => wp_max_upload_size(),
            'strings' => [
                'drag_drop' => __('Drag & drop your backup file here', 'magic-migrate'),
                'or_click' => __('or click to browse', 'magic-migrate'),
                'uploading' => __('Uploading...', 'magic-migrate'),
                'upload_complete' => __('Upload complete!', 'magic-migrate'),
                'processing' => __('Processing...', 'magic-migrate'),
                'confirm_import' => __('Are you sure? This will overwrite your existing site content.', 'magic-migrate'),
                'exporting' => __('Exporting...', 'magic-migrate'),
                'export_complete' => __('Export complete!', 'magic-migrate'),
                'error' => __('An error occurred.', 'magic-migrate'),
                'delete_confirm' => __('Are you sure you want to delete this backup?', 'magic-migrate'),
            ],
        ]);
    }

    public static function render_page() {
        $current_page = sanitize_key($_GET['page'] ?? 'magic-migrate');
        ?>
        <div class="wrap magic-migrate-wrap">
            <h1 class="magic-migrate-header">
                <span class="dashicons dashicons-migrate"></span>
                <?php esc_html_e('Magic Migrate', 'magic-migrate'); ?>
            </h1>

            <nav class="magic-migrate-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=magic-migrate')); ?>"
                   class="magic-migrate-tab <?php echo $current_page === 'magic-migrate' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-upload"></span> <?php esc_html_e('Import', 'magic-migrate'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=magic-migrate-export')); ?>"
                   class="magic-migrate-tab <?php echo $current_page === 'magic-migrate-export' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-migrate"></span> <?php esc_html_e('Export', 'magic-migrate'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=magic-migrate-backups')); ?>"
                   class="magic-migrate-tab <?php echo $current_page === 'magic-migrate-backups' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-backup"></span> <?php esc_html_e('Backups', 'magic-migrate'); ?>
                </a>
            </nav>

            <div class="magic-migrate-content">
                <?php
                if ($current_page === 'magic-migrate') {
                    self::render_import();
                } elseif ($current_page === 'magic-migrate-export') {
                    self::render_export();
                } elseif ($current_page === 'magic-migrate-backups') {
                    self::render_backups();
                }
                ?>
            </div>
        </div>
        <?php
    }

    public static function render_import() {
        ?>
        <div class="magic-migrate-section">
            <h2><?php esc_html_e('Import Site', 'magic-migrate'); ?></h2>
            <p class="description"><?php esc_html_e('Upload a backup file (.zip, .sql, .xml, .wpress, .tar.gz) to import your site content. Unlimited file size supported via chunked upload.', 'magic-migrate'); ?></p>

            <div class="magic-migrate-dropzone" id="magic-migrate-dropzone">
                <div class="magic-migrate-dropzone-content">
                    <span class="dashicons dashicons-cloud-upload magic-migrate-upload-icon"></span>
                    <p class="magic-migrate-dropzone-text">
                        <strong><?php esc_html_e('Drag & drop your backup file here', 'magic-migrate'); ?></strong><br>
                        <small><?php esc_html_e('or click to browse — no file size limit', 'magic-migrate'); ?></small>
                    </p>
                    <input type="file" id="magic-migrate-file-input" accept=".zip,.sql,.xml,.wpress,.tar.gz,.tar,.gz" style="display: none;">
                </div>
            </div>

            <div class="magic-migrate-progress" id="magic-migrate-progress" style="display: none;">
                <div class="magic-migrate-progress-header">
                    <span id="magic-migrate-filename">—</span>
                    <span id="magic-migrate-filesize">—</span>
                </div>
                <div class="magic-migrate-progress-bar">
                    <div class="magic-migrate-progress-fill" id="magic-migrate-progress-fill" style="width: 0%;"></div>
                </div>
                <div class="magic-migrate-progress-stats">
                    <span id="magic-migrate-progress-text">0%</span>
                    <span id="magic-migrate-progress-chunks">—</span>
                </div>
            </div>

            <div class="magic-migrate-import-step" id="magic-migrate-import-step" style="display: none;">
                <div id="magic-migrate-import-message"></div>
                <div class="magic-migrate-actions" style="display: none;" id="magic-migrate-import-actions">
                    <button class="button button-primary" id="magic-migrate-start-import"><?php esc_html_e('Start Import', 'magic-migrate'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function render_export() {
        ?>
        <div class="magic-migrate-section">
            <h2><?php esc_html_e('Export Site', 'magic-migrate'); ?></h2>
            <p class="description"><?php esc_html_e('Create a complete backup of your WordPress site.', 'magic-migrate'); ?></p>

            <div class="magic-migrate-export-form">
                <div class="magic-migrate-finding-text" id="magic-migrate-finding" style="display: none;">
                    <span class="spinner is-active"></span> Scanning site contents...
                </div>

                <div class="magic-migrate-export-options" id="magic-migrate-export-options">
                    <h3><?php esc_html_e('Export Options', 'magic-migrate'); ?></h3>

                    <label class="magic-migrate-checkbox">
                        <input type="checkbox" id="include-database" checked>
                        <div class="magic-migrate-checkbox-icon"><span class="dashicons dashicons-database"></span></div>
                        <div class="magic-migrate-checkbox-body">
                            <span><?php esc_html_e('Include Database', 'magic-migrate'); ?></span>
                            <small><?php esc_html_e('Export all tables and content', 'magic-migrate'); ?></small>
                        </div>
                    </label>

                    <label class="magic-migrate-checkbox">
                        <input type="checkbox" id="include-uploads" checked>
                        <div class="magic-migrate-checkbox-icon"><span class="dashicons dashicons-images-alt2"></span></div>
                        <div class="magic-migrate-checkbox-body">
                            <span><?php esc_html_e('Include Media Uploads', 'magic-migrate'); ?></span>
                            <small><?php esc_html_e('Export wp-content/uploads', 'magic-migrate'); ?></small>
                        </div>
                    </label>

                    <label class="magic-migrate-checkbox">
                        <input type="checkbox" id="include-plugins">
                        <div class="magic-migrate-checkbox-icon"><span class="dashicons dashicons-admin-plugins"></span></div>
                        <div class="magic-migrate-checkbox-body">
                            <span><?php esc_html_e('Include Plugins', 'magic-migrate'); ?></span>
                            <small><?php esc_html_e('Export active plugins', 'magic-migrate'); ?></small>
                        </div>
                    </label>

                    <label class="magic-migrate-checkbox">
                        <input type="checkbox" id="include-themes">
                        <div class="magic-migrate-checkbox-icon"><span class="dashicons dashicons-admin-appearance"></span></div>
                        <div class="magic-migrate-checkbox-body">
                            <span><?php esc_html_e('Include Themes', 'magic-migrate'); ?></span>
                            <small><?php esc_html_e('Export active theme', 'magic-migrate'); ?></small>
                        </div>
                    </label>
                </div>

                <div class="magic-migrate-export-actions">
                    <button class="button button-primary" id="magic-migrate-start-export">
                        <span class="dashicons dashicons-migrate"></span> <?php esc_html_e('Export To File', 'magic-migrate'); ?>
                    </button>
                </div>

                <div class="magic-migrate-export-progress" id="magic-migrate-export-progress" style="display: none;">
                    <div class="magic-migrate-progress-bar">
                        <div class="magic-migrate-progress-fill magic-migrate-progress-indeterminate" id="magic-migrate-export-progress-fill" style="width: 100%;"></div>
                    </div>
                    <p id="magic-migrate-export-message" style="text-align: center; margin-top: 10px;"><?php esc_html_e('Creating backup...', 'magic-migrate'); ?></p>
                </div>

                <div class="magic-migrate-export-result" id="magic-migrate-export-result" style="display: none;"></div>
            </div>
        </div>
        <?php
    }

    public static function render_backups() {
        $backups = [];

        if (file_exists(MAGIC_MIGRATE_BACKUPS_DIR)) {
            $dirs = scandir(MAGIC_MIGRATE_BACKUPS_DIR);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..' || $dir === 'index.php' || $dir === '.htaccess') {
                    continue;
                }

                $full_path = MAGIC_MIGRATE_BACKUPS_DIR . '/' . $dir;
                if (!is_dir($full_path)) {
                    continue;
                }

                $archive_files = array_merge(
                    glob($full_path . '/*.wpress'),
                    glob($full_path . '/*.zip')
                );
                $export_data = get_transient('magic_migrate_export_' . $dir);

                $size = 0;
                foreach ($archive_files as $zf) {
                    $size += filesize($zf);
                }

                $backups[] = [
                    'id' => $dir,
                    'size' => $size,
                    'size_formatted' => size_format($size),
                    'files' => array_map('basename', $archive_files),
                    'date' => $export_data['created_at'] ?? date('Y-m-d H:i:s', filemtime($full_path)),
                ];
            }
        }

        usort($backups, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
        ?>
        <div class="magic-migrate-section">
            <h2><?php esc_html_e('Backups', 'magic-migrate'); ?></h2>
            <p class="description"><?php esc_html_e('Previously created backups and uploaded files are listed below.', 'magic-migrate'); ?></p>

            <?php if (empty($backups)): ?>
                <div class="magic-migrate-empty">
                    <span class="dashicons dashicons-backup"></span>
                    <p><?php esc_html_e('No backups found. Export your site or import a backup to get started.', 'magic-migrate'); ?></p>
                </div>
            <?php else: ?>
                <table class="magic-migrate-backups-table wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'magic-migrate'); ?></th>
                            <th><?php esc_html_e('ID', 'magic-migrate'); ?></th>
                            <th><?php esc_html_e('Size', 'magic-migrate'); ?></th>
                            <th><?php esc_html_e('Files', 'magic-migrate'); ?></th>
                            <th><?php esc_html_e('Actions', 'magic-migrate'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><?php echo esc_html($backup['date']); ?></td>
                            <td><code><?php echo esc_html($backup['id']); ?></code></td>
                            <td><?php echo esc_html($backup['size_formatted']); ?></td>
                            <td><?php echo esc_html(implode(', ', $backup['files'])); ?></td>
                            <td>
                                <?php $download_url = add_query_arg([
                                    'action' => 'magic_migrate_download_export',
                                    'export_id' => $backup['id'],
                                    'nonce' => wp_create_nonce('magic_migrate_nonce'),
                                ], admin_url('admin-ajax.php')); ?>
                                <a href="<?php echo esc_url($download_url); ?>" class="button button-small"><?php esc_html_e('Download', 'magic-migrate'); ?></a>
                                <button class="button button-small magic-migrate-delete-backup"
                                        data-backup-id="<?php echo esc_attr($backup['id']); ?>">
                                    <?php esc_html_e('Delete', 'magic-migrate'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
