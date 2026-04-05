jQuery(document).ready(function($) {
    var currentPage = 1;
    var searchTimeout = null;
    
    // Загрузка таблицы
    function loadTable(page) {
        currentPage = page || 1;
        var search = $('#wcda-attr-search').val();
        
        $('#wcda-attributes-tbody').html('<tr><td colspan="10" class="wcda-loading">Загрузка...</td></tr>');
        
        $.post(wcda_attr.ajax_url, {
            action: 'wcda_get_image_attributes',
            page: currentPage,
            search: search,
            nonce: wcda_attr.nonce
        }, function(response) {
            if (response.success) {
                renderTable(response.data.items);
                renderPagination(response.data.total_pages, response.data.current_page);
            } else {
                $('#wcda-attributes-tbody').html('<tr><td colspan="10" class="wcda-loading">Ошибка загрузки данных</td></tr>');
            }
        });
    }
    
    // Рендеринг таблицы
    function renderTable(items) {
        if (!items || items.length === 0) {
            $('#wcda-attributes-tbody').html('<tr><td colspan="10" class="wcda-loading">Нет данных</td></tr>');
            return;
        }
        
        var html = '';
        $.each(items, function(index, item) {
            var previewUrl = item.preview_url || item.image_url;
            var editLink = item.edit_link || '#';
            
            html += '<tr data-id="' + item.id + '">';
            html += '<td><input type="checkbox" class="wcda-attr-checkbox" value="' + item.id + '"></td>';
            html += '<td>' + item.id + '</td>';
            html += '<td><img src="' + escapeHtml(previewUrl) + '" class="wcda-attr-preview-img" onerror="this.src=\'https://via.placeholder.com/50?text=No+Image\'"></td>';
            html += '<td><div class="wcda-attr-url" title="' + escapeHtml(item.image_url) + '">' + escapeHtml(item.image_url) + '</div></td>';
            html += '<td class="attr-name-' + item.id + '">' + escapeHtml(item.attribute_name || '—') + '</td>';
            html += '<td class="attr-slug-' + item.id + '">' + escapeHtml(item.attribute_slug || '—') + '</td>';
            html += '<td>' + (item.attachment_id || '—') + '</td>';
            html += '<td>' + item.use_count + '</td>';
            html += '<td>' + formatDate(item.created_at) + '</td>';
            html += '<td class="wcda-attr-actions">';
            html += '<button type="button" class="button wcda-edit-attr" data-id="' + item.id + '">✏️</button>';
            html += '<button type="button" class="button wcda-delete-attr" data-id="' + item.id + '">🗑️</button>';
            if (item.attachment_id) {
                html += '<a href="' + editLink + '" class="button" target="_blank">🔍</a>';
            }
            html += '</td>';
            html += '</tr>';
        });
        
        $('#wcda-attributes-tbody').html(html);
    }
    
    // Рендеринг пагинации
    function renderPagination(totalPages, currentPage) {
        if (totalPages <= 1) {
            $('#wcda-attr-pagination').empty();
            return;
        }
        
        var html = '';
        for (var i = 1; i <= totalPages; i++) {
            if (i === currentPage) {
                html += '<span class="page-numbers current">' + i + '</span>';
            } else {
                html += '<span class="page-numbers" data-page="' + i + '">' + i + '</span>';
            }
        }
        
        $('#wcda-attr-pagination').html(html);
        
        $('.page-numbers[data-page]').on('click', function() {
            loadTable($(this).data('page'));
        });
    }
    
    // Форматирование даты
    function formatDate(dateString) {
        if (!dateString) return '—';
        var date = new Date(dateString);
        return date.toLocaleDateString('ru-RU') + ' ' + date.toLocaleTimeString('ru-RU', {hour: '2-digit', minute:'2-digit'});
    }
    
    // Экранирование HTML
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
    
    // Показ уведомления
    function showNotice(message, type) {
        var notice = $('<div class="wcda-attr-notice wcda-attr-notice-' + type + '">' +
            '<span>' + message + '</span>' +
            '<button type="button" class="notice-dismiss" style="margin-left: auto;">×</button>' +
            '</div>');
        
        $('.wcda-attributes-manager h1').after(notice);
        
        notice.find('.notice-dismiss').on('click', function() {
            notice.remove();
        });
        
        setTimeout(function() {
            notice.fadeOut(function() { $(this).remove(); });
        }, 5000);
    }
    
    // Редактирование записи
    function editAttribute(id) {
        var row = $('tr[data-id="' + id + '"]');
        var imageUrl = row.find('td:eq(3) .wcda-attr-url').text();
        var attrName = row.find('.attr-name-' + id).text();
        var attrSlug = row.find('.attr-slug-' + id).text();
        var attachmentId = row.find('td:eq(6)').text();
        
        $('#edit-id').val(id);
        $('#edit-image-url').val(imageUrl);
        $('#edit-attribute-name').val(attrName !== '—' ? attrName : '');
        $('#edit-attribute-slug').val(attrSlug !== '—' ? attrSlug : '');
        $('#edit-attachment-id').val(attachmentId !== '—' ? attachmentId : '');
        
        $('#wcda-edit-modal').show();
    }
    
    // Сохранение редактирования
    function saveEdit() {
        var id = $('#edit-id').val();
        var attributeName = $('#edit-attribute-name').val();
        var attributeSlug = $('#edit-attribute-slug').val();
        var attachmentId = $('#edit-attachment-id').val();
        
        $.post(wcda_attr.ajax_url, {
            action: 'wcda_update_image_attribute',
            id: id,
            attribute_name: attributeName,
            attribute_slug: attributeSlug,
            attachment_id: attachmentId,
            nonce: wcda_attr.nonce
        }, function(response) {
            if (response.success) {
                showNotice(wcda_attr.strings.update_success, 'success');
                loadTable(currentPage);
                $('#wcda-edit-modal').hide();
            } else {
                showNotice(response.data.message || wcda_attr.strings.update_error, 'error');
            }
        }).fail(function() {
            showNotice(wcda_attr.strings.update_error, 'error');
        });
    }
    
    // Удаление записи
    function deleteAttribute(id) {
        if (!confirm(wcda_attr.strings.confirm_delete)) return;
        
        $.post(wcda_attr.ajax_url, {
            action: 'wcda_delete_image_attribute',
            id: id,
            nonce: wcda_attr.nonce
        }, function(response) {
            if (response.success) {
                loadTable(currentPage);
                showNotice('Запись удалена', 'success');
            } else {
                showNotice(wcda_attr.strings.delete_error, 'error');
            }
        });
    }
    
    // Массовое удаление
    function bulkDelete() {
        var ids = [];
        $('.wcda-attr-checkbox:checked').each(function() {
            ids.push($(this).val());
        });
        
        if (ids.length === 0) {
            showNotice('Выберите записи для удаления', 'error');
            return;
        }
        
        if (!confirm(wcda_attr.strings.confirm_bulk_delete)) return;
        
        $.post(wcda_attr.ajax_url, {
            action: 'wcda_bulk_delete_attributes',
            ids: ids,
            nonce: wcda_attr.nonce
        }, function(response) {
            if (response.success) {
                loadTable(currentPage);
                showNotice('Удалено записей: ' + response.data.deleted, 'success');
                $('#wcda-select-all').prop('checked', false);
            } else {
                showNotice('Ошибка при массовом удалении', 'error');
            }
        });
    }
    
    // Экспорт в CSV
    function exportCSV(ids) {
        var data = {
            action: 'wcda_export_attributes_csv',
            nonce: wcda_attr.nonce
        };
        
        if (ids && ids.length > 0) {
            data.ids = ids;
        }
        
        $.post(wcda_attr.ajax_url, data, function(response) {
            if (response.success) {
                var binary = atob(response.data.csv);
                var blob = new Blob(["\uFEFF" + binary], {type: 'text/csv;charset=utf-8;'});
                var link = document.createElement('a');
                var url = URL.createObjectURL(blob);
                link.href = url;
                link.download = response.data.filename;
                link.click();
                URL.revokeObjectURL(url);
                showNotice('Экспорт завершен', 'success');
            } else {
                showNotice('Ошибка при экспорте', 'error');
            }
        });
    }
    
    // Синхронизация с медиатекой
    function syncAttributes() {
        if (!confirm(wcda_attr.strings.confirm_sync)) return;
        
        $('#wcda-sync-attributes').prop('disabled', true).text('Синхронизация...');
        
        $.post(wcda_attr.ajax_url, {
            action: 'wcda_sync_attributes',
            nonce: wcda_attr.nonce
        }, function(response) {
            if (response.success) {
                showNotice(response.data.message, 'success');
                loadTable(currentPage);
            } else {
                showNotice(wcda_attr.strings.sync_error, 'error');
            }
            $('#wcda-sync-attributes').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Синхронизировать с медиатекой');
        }).fail(function() {
            showNotice(wcda_attr.strings.sync_error, 'error');
            $('#wcda-sync-attributes').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Синхронизировать с медиатекой');
        });
    }
    
    // Обработчики событий
    $('#wcda-select-all').on('change', function() {
        $('.wcda-attr-checkbox').prop('checked', $(this).is(':checked'));
    });
    
    $('#wcda-apply-bulk').on('click', function() {
        var action = $('#wcda-bulk-action').val();
        if (action === 'delete') {
            bulkDelete();
        } else if (action === 'export') {
            var ids = [];
            $('.wcda-attr-checkbox:checked').each(function() {
                ids.push($(this).val());
            });
            exportCSV(ids);
        } else {
            showNotice('Выберите действие', 'error');
        }
    });
    
    $('#wcda-export-all-csv').on('click', function() {
        exportCSV();
    });
    
    $('#wcda-sync-attributes').on('click', syncAttributes);
    
    // Делегирование событий для динамических элементов
    $(document).on('click', '.wcda-edit-attr', function() {
        editAttribute($(this).data('id'));
    });
    
    $(document).on('click', '.wcda-delete-attr', function() {
        deleteAttribute($(this).data('id'));
    });
    
    $('#wcda-save-edit').on('click', saveEdit);
    
    $('#wcda-cancel-edit, .wcda-attr-modal-close').on('click', function() {
        $('#wcda-edit-modal').hide();
    });
    
    $(window).on('click', function(e) {
        if ($(e.target).is('#wcda-edit-modal')) {
            $('#wcda-edit-modal').hide();
        }
    });
    
    // Поиск с debounce
    $('#wcda-attr-search').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            loadTable(1);
        }, 500);
    });
    
    // Загрузка таблицы
    loadTable(1);
    
    // Добавляем поле поиска
    $('.wcda-attr-actions-bar .wcda-attr-single-actions').before(
        '<div class="wcda-attr-search">' +
        '<input type="text" id="wcda-attr-search" placeholder="Поиск..." style="padding: 5px 10px; width: 250px;">' +
        '</div>'
    );
});