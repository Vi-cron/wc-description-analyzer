jQuery(document).ready(function($) {
    var currentPage = 1;
    var searchTimeout = null;
    var editTimeouts = {};
    
    // Загрузка таблицы
    function loadTable(page, preserveScroll = false) {
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
                updateStats(response.data.stats);
            } else {
                $('#wcda-attributes-tbody').html('<tr><td colspan="10" class="wcda-loading">Ошибка загрузки данных</td></tr>');
            }
        });
    }
    
    // Обновление статистики
    function updateStats(stats) {
        if (stats) {
            $('.wcda-attr-stat-card:eq(0) .wcda-attr-stat-number').text(stats.total || 0);
            $('.wcda-attr-stat-card:eq(1) .wcda-attr-stat-number').text(stats.with_attributes || 0);
            $('.wcda-attr-stat-card:eq(2) .wcda-attr-stat-number').text(stats.total_uses || 0);
        }
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
            html += '<td><img src="' + escapeHtml(previewUrl) + '" class="wcda-preview-img" onerror="this.src=\'https://via.placeholder.com/50?text=No+Image\'"></td>';
            html += '<td><div class="wcda-attr-url" title="' + escapeHtml(item.image_url) + '">' + escapeHtml(item.image_url) + '</div></td>';
            html += '<td class="attr-name-cell-' + item.id + '">';
            html += '<span class="wcda-editable-cell" data-id="' + item.id + '" data-field="attribute_name" data-value="' + escapeHtml(item.attribute_name || '') + '">';
            html += escapeHtml(item.attribute_name || '—');
            html += '</span></td>';
            html += '<td class="attr-slug-cell-' + item.id + '">';
            html += '<span class="wcda-editable-cell" data-id="' + item.id + '" data-field="attribute_slug" data-value="' + escapeHtml(item.attribute_slug || '') + '">';
            html += escapeHtml(item.attribute_slug || '—');
            html += '</span></td>';
            html += '<td>' + (item.attachment_id || '—') + '</td>';
            html += '<td>' + item.use_count + '</td>';
            html += '<td>' + formatDate(item.created_at) + '</td>';
            html += '<td class="wcda-attr-actions">';
            html += '<button type="button" class="button wcda-delete-attr" data-id="' + item.id + '">🗑️</button>';
            if (item.attachment_id) {
                html += '<a href="' + editLink + '" class="button" target="_blank">🔍</a>';
            }
            html += '</td>';
            html += '</tr>';
        });
        
        $('#wcda-attributes-tbody').html(html);
        attachEditHandlers();
    }
    
    // Привязка обработчиков редактирования
    function attachEditHandlers() {
        $('.wcda-editable-cell').off('click').on('click', function(e) {
            e.stopPropagation();
            var $cell = $(this);
            var id = $cell.data('id');
            var field = $cell.data('field');
            var currentValue = $cell.data('value') || '';
            
            // Если уже в режиме редактирования - игнорируем
            if ($cell.find('input').length) return;
            
            var $input = $('<input type="text" class="wcda-editable-input" value="' + escapeHtml(currentValue) + '">');
            $cell.html($input);
            $input.trigger('focus');
            
            var saveHandler = function() {
                var newValue = $input.val().trim();
                if (newValue === currentValue) {
                    restoreCell($cell, id, field, currentValue);
                    return;
                }
                
                // Для поля attribute_name - генерируем слаг
                if (field === 'attribute_name') {
                    var slug = generateSlug(newValue);
                    if (slug) {
                        updateField(id, 'attribute_slug', slug);
                        var $slugCell = $('.attr-slug-cell-' + id + ' .wcda-editable-cell');
                        if ($slugCell.length && $slugCell.data('value') !== slug) {
                            $slugCell.data('value', slug);
                            $slugCell.html(escapeHtml(slug || '—'));
                        }
                    }
                }
                
                updateField(id, field, newValue);
                restoreCell($cell, id, field, newValue);
            };
            
            $input.on('blur', saveHandler);
            $input.on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    saveHandler();
                }
            });
        });
    }
    
    // Генерация слага из названия
    function generateSlug(text) {
        if (!text) return '';
        
        // Транслитерация
        var cyrillic = {
            'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e',
            'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm',
            'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
            'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch', 'ъ': '',
            'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya',
            'А': 'a', 'Б': 'b', 'В': 'v', 'Г': 'g', 'Д': 'd', 'Е': 'e', 'Ё': 'e',
            'Ж': 'zh', 'З': 'z', 'И': 'i', 'Й': 'y', 'К': 'k', 'Л': 'l', 'М': 'm',
            'Н': 'n', 'О': 'o', 'П': 'p', 'Р': 'r', 'С': 's', 'Т': 't', 'У': 'u',
            'Ф': 'f', 'Х': 'h', 'Ц': 'ts', 'Ч': 'ch', 'Ш': 'sh', 'Щ': 'sch', 'Ъ': '',
            'Ы': 'y', 'Ь': '', 'Э': 'e', 'Ю': 'yu', 'Я': 'ya'
        };
        
        var slug = text.split('').map(function(char) {
            return cyrillic[char] || (char.match(/[a-zA-Z0-9]/) ? char : '');
        }).join('');
        
        slug = slug.toLowerCase()
            .replace(/[^\w\s-]/g, '')
            .replace(/[\s_]+/g, '-')
            .replace(/--+/g, '-')
            .replace(/^-+|-+$/g, '');
        
        return slug;
    }
    
    // Восстановление ячейки
    function restoreCell($cell, id, field, value) {
        $cell.html(escapeHtml(value || '—'));
        $cell.data('value', value);
    }
    
    // Обновление поля с debounce
    function updateField(id, field, value) {
        if (editTimeouts[id + '_' + field]) {
            clearTimeout(editTimeouts[id + '_' + field]);
        }
        
        editTimeouts[id + '_' + field] = setTimeout(function() {
            $.post(wcda_attr.ajax_url, {
                action: 'wcda_update_image_attribute',
                id: id,
                field: field,
                value: value,
                nonce: wcda_attr.nonce
            }, function(response) {
                if (response.success) {
                    showNotice(response.data.message || 'Обновлено', 'success');
                    // Обновляем значение в data-атрибуте
                    $('.attr-' + field + '-cell-' + id + ' .wcda-editable-cell').data('value', value);
                    // Обновляем статистику
                    if (response.data.stats) {
                        updateStats(response.data.stats);
                    }
                } else {
                    showNotice(response.data.message || 'Ошибка обновления', 'error');
                    // Перезагружаем таблицу для восстановления значений
                    loadTable(currentPage);
                }
            }).fail(function() {
                showNotice('Ошибка соединения', 'error');
            });
        }, 800);
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
        
        $('.page-numbers[data-page]').off('click').on('click', function() {
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
        }, 3000);
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
    
    // Экспорт в Excel (CSV)
    function exportToExcel(ids) {
        var exportUrl = wcda_attr.export_excel_url;
        
        if (ids && ids.length > 0) {
            exportUrl += '&ids=' + ids.join(',');
        }
        
        showNotice(wcda_attr.strings.export_started, 'success');
        window.open(exportUrl, '_blank');
    }
    
    // Экспорт в JSON
    function exportToJSON(ids) {
        var idsParam = (ids && ids.length > 0) ? '&ids=' + ids.join(',') : '';
        var exportUrl = wcda_attr.export_json_url + idsParam;
        
        showNotice('Подготовка JSON файла...', 'success');
        window.open(exportUrl, '_blank');
    }
    
    // Импорт из JSON
    function importFromJSON(file) {
        var formData = new FormData();
        formData.append('action', 'wcda_import_attributes_json');
        formData.append('json_file', file);
        formData.append('nonce', wcda_attr.nonce);
        
        $('#wcda-import-json').prop('disabled', true).text('Импорт...');
        
        $.ajax({
            url: wcda_attr.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    loadTable(1);
                    $('#wcda-import-modal').hide();
                } else {
                    showNotice(response.data.message || 'Ошибка импорта', 'error');
                }
            },
            error: function() {
                showNotice('Ошибка при импорте', 'error');
            },
            complete: function() {
                $('#wcda-import-json').prop('disabled', false).text('Импортировать');
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
            $('#wcda-sync-attributes').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Синхронизировать');
        }).fail(function() {
            showNotice(wcda_attr.strings.sync_error, 'error');
            $('#wcda-sync-attributes').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Синхронизировать');
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
        } else if (action === 'export-excel') {
            var ids = [];
            $('.wcda-attr-checkbox:checked').each(function() {
                ids.push($(this).val());
            });
            if (ids.length === 0) {
                showNotice('Выберите записи для экспорта', 'error');
                return;
            }
            exportToExcel(ids);
        } else if (action === 'export-json') {
            var ids = [];
            $('.wcda-attr-checkbox:checked').each(function() {
                ids.push($(this).val());
            });
            if (ids.length === 0) {
                showNotice('Выберите записи для экспорта', 'error');
                return;
            }
            exportToJSON(ids);
        } else {
            showNotice('Выберите действие', 'error');
        }
    });
    
    $('#wcda-export-excel').on('click', function() {
        exportToExcel();
    });
    
    $('#wcda-export-json').on('click', function() {
        exportToJSON();
    });
    
    $('#wcda-import-json-btn').on('click', function() {
        $('#wcda-import-modal').show();
    });
    
    $('#wcda-sync-attributes').on('click', syncAttributes);
    
    // Делегирование событий для динамических элементов
    $(document).on('click', '.wcda-delete-attr', function() {
        deleteAttribute($(this).data('id'));
    });
    
    $('#wcda-cancel-import, .wcda-attr-modal-close').on('click', function() {
        $('#wcda-import-modal').hide();
    });
    
    $('#wcda-confirm-import').on('click', function() {
        var fileInput = $('#wcda-json-file')[0];
        if (fileInput.files.length === 0) {
            showNotice('Выберите JSON файл', 'error');
            return;
        }
        importFromJSON(fileInput.files[0]);
    });
    
    $(window).on('click', function(e) {
        if ($(e.target).is('#wcda-import-modal')) {
            $('#wcda-import-modal').hide();
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
});