(function ($, win) {
    'use strict';

    var MG = window.MagicMigrate || {};

    MG.Importer = {
        file: null,
        fileUuid: '',
        totalChunks: 0,
        currentChunk: 0,
        retryCount: 0,
        maxRetries: 5,

        init: function () {
            var self = this;
            var dropzone = document.getElementById('magic-migrate-dropzone');
            var fileInput = document.getElementById('magic-migrate-file-input');

            if (!dropzone || !fileInput) return;

            dropzone.addEventListener('click', function () {
                fileInput.click();
            });

            fileInput.addEventListener('change', function (e) {
                if (e.target.files.length) {
                    self.file = e.target.files[0];
                    self.startUpload();
                }
            });

            dropzone.addEventListener('dragover', function (e) {
                e.preventDefault();
                dropzone.classList.add('magic-migrate-dropzone--active');
            });

            dropzone.addEventListener('dragleave', function () {
                dropzone.classList.remove('magic-migrate-dropzone--active');
            });

            dropzone.addEventListener('drop', function (e) {
                e.preventDefault();
                dropzone.classList.remove('magic-migrate-dropzone--active');
                if (e.dataTransfer.files.length) {
                    self.file = e.dataTransfer.files[0];
                    self.startUpload();
                }
            });

            var importBtn = document.getElementById('magic-migrate-start-import');
            if (importBtn) {
                importBtn.addEventListener('click', function () {
                    self.performImport();
                });
            }
        },

        startUpload: function () {
            var chunkSize = MG.chunk_size || 512 * 1024;
            this.totalChunks = Math.ceil(this.file.size / chunkSize);
            this.currentChunk = 0;
            this.retryCount = 0;
            this.fileUuid = this.generateUUID();

            document.getElementById('magic-migrate-dropzone').style.display = 'none';
            document.getElementById('magic-migrate-progress').style.display = 'block';
            document.getElementById('magic-migrate-filename').textContent = this.file.name;
            document.getElementById('magic-migrate-filesize').textContent = this.formatSize(this.file.size);

            this.uploadChunk();
        },

        uploadChunk: function () {
            var self = this;
            var start = this.currentChunk * MG.chunk_size;
            var end = Math.min(start + MG.chunk_size, this.file.size);
            var chunk = this.file.slice(start, end);

            var formData = new FormData();
            formData.append('action', 'magic_migrate_upload_chunk');
            formData.append('nonce', MG.nonce);
            formData.append('filename', this.file.name);
            formData.append('chunk', this.currentChunk);
            formData.append('chunks', this.totalChunks);
            formData.append('file_uuid', this.fileUuid);
            formData.append('chunk_file', chunk, this.file.name + '.part');

            $('#magic-migrate-progress-chunks').text(
                'Chunk ' + (this.currentChunk + 1) + ' of ' + this.totalChunks
            );

            $.ajax({
                url: MG.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function () {
                    return win.XMLHttpRequest ? new win.XMLHttpRequest() : null;
                },
                success: function (response) {
                    if (response.success !== true) {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Upload failed';
                        self.showError(msg);
                        return;
                    }

                    self.currentChunk++;
                    self.retryCount = 0;

                    var percent = Math.round((self.currentChunk / self.totalChunks) * 100);
                    self.updateProgress(percent);

                    if (response.data.complete) {
                        self.onUploadComplete(response.data);
                    } else {
                        setTimeout(function () {
                            self.uploadChunk();
                        }, 50);
                    }
                },
                error: function (xhr, status) {
                    self.retryCount++;
                    if (self.retryCount > self.maxRetries) {
                        self.showError('Upload failed after ' + self.maxRetries + ' retries. Please try again.');
                        return;
                    }
                    setTimeout(function () {
                        self.uploadChunk();
                    }, 2000);
                },
            });
        },

        onUploadComplete: function () {
            var self = this;
            document.getElementById('magic-migrate-progress-text').textContent = '100% - Upload complete!';
            document.getElementById('magic-migrate-progress-chunks').textContent = 'Finalizing...';

            $.ajax({
                url: MG.ajax_url,
                type: 'POST',
                data: {
                    action: 'magic_migrate_prepare_import',
                    nonce: MG.nonce,
                    file_uuid: this.fileUuid,
                    filename: this.file.name,
                },
                success: function (response) {
                    if (response.success !== true) {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Failed to prepare import';
                        self.showError(msg);
                        return;
                    }

                    var fileSize = response.data.file_size_formatted ? response.data.file_size_formatted : 'Unknown';
                    $('#magic-migrate-import-step').show();
                    $('#magic-migrate-import-message').html(
                        $('<div>').addClass('magic-migrate-import-info').append(
                            $('<h3>').text('Import Ready'),
                            $('<p>').append($('<strong>').text('File: '), document.createTextNode(self.file.name)),
                            $('<p>').append($('<strong>').text('Size: '), document.createTextNode(fileSize))
                        )
                    );
                    $('#magic-migrate-import-actions').show();
                },
                error: function () {
                    self.showError('Server error during import preparation.');
                },
            });
        },

        performImport: function () {
            var self = this;
            var btn = document.getElementById('magic-migrate-start-import');
            btn.disabled = true;
            btn.textContent = 'Importing...';

            $.ajax({
                url: MG.ajax_url,
                type: 'POST',
                data: {
                    action: 'magic_migrate_import_file_content',
                    nonce: MG.nonce,
                    file_uuid: this.fileUuid,
                    confirm: true,
                },
                success: function (response) {
                    if (response.success !== true) {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Import failed';
                        self.showError(msg);
                        btn.disabled = false;
                        btn.textContent = 'Retry Import';
                        return;
                    }

                    $('#magic-migrate-import-message').html(
                        $('<div>').addClass('notice notice-success inline').append(
                            $('<p>').text(response.data.message)
                        )
                    );
                    $('#magic-migrate-import-actions').hide();
                },
                error: function () {
                    self.showError('Server error during import.');
                    btn.disabled = false;
                    btn.textContent = 'Retry Import';
                },
            });
        },

        updateProgress: function (percent) {
            document.getElementById('magic-migrate-progress-fill').style.width = percent + '%';
            document.getElementById('magic-migrate-progress-text').textContent = percent + '%';
        },

        showError: function (message) {
            $('#magic-migrate-import-step').show();
            $('#magic-migrate-import-message').html(
                $('<div>').addClass('notice notice-error inline').append(
                    $('<p>').text(message)
                )
            );
        },

        formatSize: function (bytes) {
            if (bytes === 0) return '0 B';
            var k = 1024;
            var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        generateUUID: function () {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                var r = (Math.random() * 16) | 0;
                var v = c === 'x' ? r : (r & 0x3) | 0x8;
                return v.toString(16);
            });
        },
    };

    MG.Exporter = {
        init: function () {
            var btn = document.getElementById('magic-migrate-start-export');
            if (!btn) return;

            btn.addEventListener('click', function () {
                MG.Exporter.start();
            });
        },

        start: function () {
            var self = this;
            var btn = document.getElementById('magic-migrate-start-export');
            btn.disabled = true;

            document.getElementById('magic-migrate-export-options').style.display = 'none';
            btn.style.display = 'none';
            document.getElementById('magic-migrate-export-progress').style.display = 'block';

            $.ajax({
                url: MG.ajax_url,
                type: 'POST',
                data: {
                    action: 'magic_migrate_prepare_export',
                    nonce: MG.nonce,
                    include_database: document.getElementById('include-database').checked ? 1 : 0,
                    include_uploads: document.getElementById('include-uploads').checked ? 1 : 0,
                    include_plugins: document.getElementById('include-plugins').checked ? 1 : 0,
                    include_themes: document.getElementById('include-themes').checked ? 1 : 0,
                },
                success: function (response) {
                    if (response.success !== true) {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Export failed';
                        self.showExportError(msg);
                        return;
                    }

                    document.getElementById('magic-migrate-export-progress').style.display = 'none';
                    document.getElementById('magic-migrate-export-result').style.display = 'block';

                    var downloadUrl = MG.ajax_url + '?action=magic_migrate_download_export&export_id=' +
                        encodeURIComponent(response.data.export_id) + '&nonce=' + encodeURIComponent(MG.nonce);

                    document.getElementById('magic-migrate-export-result').innerHTML = '';
                    var resultEl = document.getElementById('magic-migrate-export-result');

                    var notice = document.createElement('div');
                    notice.className = 'notice notice-success inline';
                    notice.innerHTML = '<p>Export completed! File: <strong>' +
                        MG.escapeHtml(response.data.filename) + '</strong> (' +
                        MG.escapeHtml(response.data.file_size) + ')</p>';
                    resultEl.appendChild(notice);

                    var dlP = document.createElement('p');
                    var dlA = document.createElement('a');
                    dlA.href = downloadUrl;
                    dlA.className = 'button button-primary';
                    dlA.textContent = 'Download Backup';
                    dlP.appendChild(dlA);
                    resultEl.appendChild(dlP);
                },
                error: function () {
                    self.showExportError('Server error during export.');
                },
            });
        },

        showExportError: function (message) {
            document.getElementById('magic-migrate-export-progress').style.display = 'none';
            document.getElementById('magic-migrate-export-options').style.display = 'block';
            var btn = document.getElementById('magic-migrate-start-export');
            btn.style.display = '';
            btn.disabled = false;

            document.getElementById('magic-migrate-export-result').style.display = 'block';
            document.getElementById('magic-migrate-export-result').innerHTML =
                '<div class="notice notice-error inline"><p>' + MG.escapeHtml(message) + '</p></div>';
        },
    };

    MG.Backups = {
        init: function () {
            $(document).on('click', '.magic-migrate-delete-backup', function () {
                var backupId = $(this).data('backup-id');
                if (confirm(MG.strings.delete_confirm || 'Are you sure?')) {
                    MG.Backups.delete(backupId, $(this).closest('tr'));
                }
            });
        },

        delete: function (id, row) {
            $.ajax({
                url: MG.ajax_url,
                type: 'POST',
                data: {
                    action: 'magic_migrate_delete_backup',
                    nonce: MG.nonce,
                    backup_id: id,
                },
                success: function (response) {
                    if (response.success) {
                        row.fadeOut(300, function () {
                            row.remove();
                            if ($('.magic-migrate-backups-table tbody tr').length === 0) {
                                location.reload();
                            }
                        });
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Delete failed';
                        alert(msg);
                    }
                },
            });
        },
    };

    MG.escapeHtml = function (text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    };

    $(function () {
        window.MagicMigrate = $.extend(MG, window.MagicMigrate || {});
        MG.Importer.init();
        MG.Exporter.init();
        MG.Backups.init();
    });
})(jQuery, window);
