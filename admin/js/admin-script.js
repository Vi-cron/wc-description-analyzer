jQuery(document).ready(function($) {
    // Функция показа сообщений (всплывает под кнопками массовых действий)
    function showResultMessage(message, type, targetElement) {
        var targetDiv = targetElement || $('.wcda-bulk-actions:first .wcda-messages');
        if (targetDiv.length === 0) {
            targetDiv = $('.wcda-bulk-actions:first');
        }
        
        var messageClass = type === 'success' ? 'notice-success' : 'notice-error';
        var messageId = 'wcda-msg-' + Date.now();
        var messageHtml = '<div id="' + messageId + '" class="notice ' + messageClass + ' is-dismissible" style="margin-top:10px;"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Закрыть</span></button></div>';
        
        targetDiv.append(messageHtml);
        
        // Автоматическое скрытие через 5 секунд
        setTimeout(function() {
            $('#' + messageId).fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        // Обработчик закрытия
        $('#' + messageId).on('click', '.notice-dismiss', function() {
            $('#' + messageId).remove();
        });
    }
    
    // Извлечение одного размера
    $('.wcda-extract-single-dimensions').on('click', function() {
        var button = $(this);
        var productId = button.data('product-id');
        var productItem = button.closest('.wcda-product-item');
        var patternGroup = button.closest('.wcda-pattern-group');
        
        var useShortcode = patternGroup.find('.wcda-pattern-use-shortcode').is(':checked');
        var attachToProduct = patternGroup.find('.wcda-pattern-attach-to-product').is(':checked');
        
        button.prop('disabled', true).text('Обработка...');
        
        $.post(wcda_ajax.ajax_url, {
            action: 'wcda_extract_dimensions_to_attributes',
            product_id: productId,
            use_shortcode: useShortcode,
            attach_to_product: attachToProduct,
            nonce: wcda_ajax.nonce
        }, function(response) {
            if (response.success) {
                productItem.addClass('wcda-processed');
                button.text('✓ Готово').css('background', '#46b450');
                var msg = response.data.message;
                if (response.data.shortcode_added) msg += ' Шорткод добавлен.';
                if (response.data.attached) msg += ' Атрибуты привязаны к товару.';
                showResultMessage(msg, 'success', patternGroup.find('.wcda-messages').length ? patternGroup.find('.wcda-messages') : patternGroup);
            } else {
                button.text('Ошибка').css('background', '#dc3232');
                showResultMessage(response.data, 'error', patternGroup.find('.wcda-messages').length ? patternGroup.find('.wcda-messages') : patternGroup);
            }
            setTimeout(function() {
                button.prop('disabled', false).text('Извлечь размеры').css('background', '');
            }, 3000);
        });
    });
    
    // Выбор всех чекбоксов (глобальный)
    $('.wcda-select-all-checkbox').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('.wcda-product-checkbox').prop('checked', isChecked);
    });
    
    // Выбор всех чекбоксов внутри шаблона
    $('.wcda-pattern-select-all').on('change', function() {
        var patternIndex = $(this).data('pattern');
        var isChecked = $(this).is(':checked');
        $('.wcda-product-checkbox-pattern-' + patternIndex).prop('checked', isChecked);
    });
    
    // Массовое извлечение размеров (глобальное)
    $('.wcda-bulk-extract-dimensions').on('click', function() {
        var button = $(this);
        var useShortcode = $('.wcda-global-use-shortcode').is(':checked');
        var attachToProduct = $('.wcda-global-attach-to-product').is(':checked');
        var selectedProducts = [];
        
        $('.wcda-product-checkbox:checked').each(function() {
            selectedProducts.push($(this).val());
        });
        
        if (selectedProducts.length === 0) {
            alert('Пожалуйста, выберите хотя бы один товар');
            return;
        }
        
        button.prop('disabled', true);
        button.siblings('.spinner').addClass('is-active');
        
        $.post(wcda_ajax.ajax_url, {
            action: 'wcda_bulk_process',
            bulk_action: 'dimensions_to_attributes',
            product_ids: selectedProducts,
            use_shortcode: useShortcode,
            attach_to_product: attachToProduct,
            nonce: wcda_ajax.nonce
        }, function(response) {
            if (response.success) {
                showResultMessage(
                    'Обработано товаров: ' + response.data.success + 
                    ', ошибок: ' + response.data.failed,
                    'success',
                    button.closest('.wcda-bulk-actions').find('.wcda-messages')
                );
                $('.wcda-product-checkbox:checked').each(function() {
                    $(this).closest('.wcda-product-item').addClass('wcda-processed');
                });
            } else {
                showResultMessage('Ошибка при массовой обработке', 'error', button.closest('.wcda-bulk-actions').find('.wcda-messages'));
            }
            button.prop('disabled', false);
            button.siblings('.spinner').removeClass('is-active');
        });
    });
    
    // Массовое извлечение размеров для конкретного шаблона
    $('.wcda-bulk-pattern-dimensions').on('click', function() {
        var button = $(this);
        var patternIndex = button.data('pattern');
        var patternGroup = button.closest('.wcda-pattern-group');
        var useShortcode = patternGroup.find('.wcda-pattern-use-shortcode').is(':checked');
        var attachToProduct = patternGroup.find('.wcda-pattern-attach-to-product').is(':checked');
        var selectedProducts = [];
        
        $('.wcda-product-checkbox-pattern-' + patternIndex + ':checked').each(function() {
            selectedProducts.push($(this).val());
        });
        
        if (selectedProducts.length === 0) {
            alert('Пожалуйста, выберите хотя бы один товар в этом шаблоне');
            return;
        }
        
        button.prop('disabled', true);
        button.siblings('.spinner').addClass('is-active');
        
        $.post(wcda_ajax.ajax_url, {
            action: 'wcda_bulk_process',
            bulk_action: 'dimensions_to_attributes',
            product_ids: selectedProducts,
            use_shortcode: useShortcode,
            attach_to_product: attachToProduct,
            nonce: wcda_ajax.nonce
        }, function(response) {
            if (response.success) {
                showResultMessage(
                    'Обработано товаров: ' + response.data.success + 
                    ', ошибок: ' + response.data.failed,
                    'success',
                    patternGroup
                );
                $('.wcda-product-checkbox-pattern-' + patternIndex + ':checked').each(function() {
                    $(this).closest('.wcda-product-item').addClass('wcda-processed');
                });
            } else {
                showResultMessage('Ошибка при массовой обработке', 'error', patternGroup);
            }
            button.prop('disabled', false);
            button.siblings('.spinner').removeClass('is-active');
        });
    });

// Добавляем в конец файла

// Переменные для модального окна
var imageImportModal = null;
var currentProductId = null;
var currentImagesData = null;
var currentUseShortcode = false;
var currentCreateAttributes = false;

// Функция создания модального окна
function createImageImportModal() {
    if ($('#wcda-image-import-modal').length) {
        return $('#wcda-image-import-modal');
    }
    
    var modalHtml = `
        <div id="wcda-image-import-modal" class="wcda-modal" style="display: none;">
            <div class="wcda-modal-content">
                <div class="wcda-modal-header">
                    <h2>Импорт изображений в галерею товара</h2>
                    <span class="wcda-modal-close">&times;</span>
                </div>
                <div class="wcda-modal-body">
                    <div class="wcda-import-progress" style="display: none;">
                        <p>Импорт изображений... <span class="wcda-progress-count">0</span>/<span class="wcda-total-count"></span></p>
                        <div class="wcda-progress-bar">
                            <div class="wcda-progress-fill"></div>
                        </div>
                    </div>
                    
                    <div class="wcda-images-table-container">
                        <table class="wcda-images-table">
                            <thead>
                                <tr>
                                    <th>№</th>
                                    <th>Изображение</th>
                                    <th>Название (будет использовано для атрибута)</th>
                                    <th>Слаг (автоматически)</th>
                                    <th>Статус</th>
                                </tr>
                            </thead>
                            <tbody id="wcda-images-tbody">
                                <!-- Динамическое заполнение -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="wcda-modal-errors" style="display: none;">
                        <h4>Ошибки импорта:</h4>
                        <div class="wcda-errors-list"></div>
                    </div>
                </div>
                <div class="wcda-modal-footer">
                    <button type="button" class="button button-primary wcda-continue-import" disabled>Продолжить</button>
                    <button type="button" class="button wcda-cancel-import">Отмена</button>
                </div>
            </div>
        </div>
    `;
    
    $('body').append(modalHtml);
    
    // Стили модального окна
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .wcda-modal {
                position: fixed;
                z-index: 100000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.5);
            }
            .wcda-modal-content {
                background-color: #fff;
                margin: 5% auto;
                padding: 0;
                width: 90%;
                max-width: 1200px;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.2);
                max-height: 85vh;
                display: flex;
                flex-direction: column;
            }
            .wcda-modal-header {
                padding: 15px 20px;
                border-bottom: 1px solid #ddd;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .wcda-modal-header h2 {
                margin: 0;
            }
            .wcda-modal-close {
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
                color: #aaa;
            }
            .wcda-modal-close:hover {
                color: #000;
            }
            .wcda-modal-body {
                padding: 20px;
                overflow-y: auto;
                flex: 1;
            }
            .wcda-images-table {
                width: 100%;
                border-collapse: collapse;
            }
            .wcda-images-table th,
            .wcda-images-table td {
                border: 1px solid #ddd;
                padding: 10px;
                text-align: left;
                vertical-align: top;
            }
            .wcda-images-table th {
                background: #f1f1f1;
                font-weight: bold;
            }
            .wcda-image-preview {
                max-width: 80px;
                max-height: 80px;
            }
            .wcda-image-name-input,
            .wcda-image-slug-input {
                width: 100%;
                padding: 5px;
            }
            .wcda-status-pending {
                color: #f0ad4e;
            }
            .wcda-status-success {
                color: #5cb85c;
            }
            .wcda-status-error {
                color: #d9534f;
            }
            .wcda-progress-bar {
                width: 100%;
                height: 20px;
                background-color: #f0f0f0;
                border-radius: 10px;
                overflow: hidden;
                margin: 10px 0;
            }
            .wcda-progress-fill {
                height: 100%;
                background-color: #5cb85c;
                width: 0%;
                transition: width 0.3s;
            }
            .wcda-modal-footer {
                padding: 15px 20px;
                border-top: 1px solid #ddd;
                text-align: right;
            }
            .wcda-modal-footer .button {
                margin-left: 10px;
            }
            .wcda-modal-errors {
                margin-top: 20px;
                padding: 10px;
                background: #f2dede;
                border: 1px solid #ebccd1;
                border-radius: 4px;
                color: #a94442;
            }
            .wcda-error-item {
                padding: 5px;
                border-bottom: 1px solid #ebccd1;
                white-space: pre-wrap;
                font-family: monospace;
                font-size: 12px;
            }
        `)
        .appendTo('head');
    
    // Обработчики событий
    $('.wcda-modal-close, .wcda-cancel-import').on('click', function() {
        $('#wcda-image-import-modal').hide();
    });
    
    return $('#wcda-image-import-modal');
}

// Функция запуска импорта изображений
function startImageImport(productId, useShortcode, createAttributes) {
    currentProductId = productId;
    currentUseShortcode = useShortcode;
    currentCreateAttributes = createAttributes;
    
    // Получаем список изображений
    $.post(wcda_ajax.ajax_url, {
        action: 'wcda_get_product_images',
        product_id: productId,
        nonce: wcda_ajax.nonce
    }, function(response) {
        if (response.success) {
            currentImagesData = response.data.images;
            showImageImportModal();
        } else {
            showResultMessage(response.data, 'error', $('.wcda-bulk-actions:first .wcda-messages'));
        }
    });
}

// Показать модальное окно с таблицей изображений
function showImageImportModal() {
    var modal = createImageImportModal();
    var tbody = $('#wcda-images-tbody');
    tbody.empty();
    
    currentImagesData.forEach(function(img, index) {
        var row = $('<tr>');
        row.append($('<td>').text(index + 1));
        row.append($('<td>').html('<img src="' + img.url + '" class="wcda-image-preview" onerror="this.src=\'https://via.placeholder.com/80?text=No+Image\'">'));
        
        var nameInput = $('<input>', {
            type: 'text',
            class: 'wcda-image-name-input',
            value: img.suggested_name,
            'data-index': index
        });
        
        var slugInput = $('<input>', {
            type: 'text',
            class: 'wcda-image-slug-input',
            value: img.suggested_slug,
            'data-index': index
        });
        
        // Обновление слага при изменении названия
        nameInput.on('input', function() {
            var idx = $(this).data('index');
            var newName = $(this).val();
            var slug = newName.toLowerCase()
                .replace(/[^a-z0-9а-яё\s]/gi, '')
                .replace(/[_\s]+/g, '-')
                .substring(0, 50);
            $('.wcda-image-slug-input[data-index="' + idx + '"]').val(slug);
            checkAllImagesReady();
        });
        
        slugInput.on('input', function() {
            checkAllImagesReady();
        });
        
        row.append($('<td>').append(nameInput));
        row.append($('<td>').append(slugInput));
        row.append($('<td>').html('<span class="wcda-status-pending">Ожидает импорта</span>'));
        
        tbody.append(row);
    });
    
    $('.wcda-total-count').text(currentImagesData.length);
    modal.show();
    
    // Запускаем автоматический импорт
    startAutoImport();
}

// Автоматический импорт всех изображений
function startAutoImport() {
    var progressDiv = $('.wcda-import-progress');
    progressDiv.show();
    
    var importedCount = 0;
    var totalCount = currentImagesData.length;
    var errors = [];
    
    function importNext(index) {
        if (index >= totalCount) {
            // Импорт завершен
            $('.wcda-progress-fill').css('width', '100%');
            setTimeout(function() {
                progressDiv.hide();
            }, 1000);
            
            if (errors.length > 0) {
                showImportErrors(errors);
            }
            
            checkAllImagesReady();
            return;
        }
        
        var img = currentImagesData[index];
        var nameInput = $('.wcda-image-name-input[data-index="' + index + '"]');
        var slugInput = $('.wcda-image-slug-input[data-index="' + index + '"]');
        var statusCell = nameInput.closest('tr').find('td:last');
        
        statusCell.html('<span class="wcda-status-pending">Импорт...</span>');
        
        $.post(wcda_ajax.ajax_url, {
            action: 'wcda_import_single_image',
            product_id: currentProductId,
            image_index: index,
            image_url: img.url,
            custom_name: nameInput.val(),
            custom_slug: slugInput.val(),
            nonce: wcda_ajax.nonce
        }, function(response) {
            if (response.success) {
                importedCount++;
                $('.wcda-progress-count').text(importedCount);
                $('.wcda-progress-fill').css('width', (importedCount / totalCount * 100) + '%');
                
                statusCell.html('<span class="wcda-status-success">✓ Загружено (ID: ' + response.data.attachment_id + ')</span>');
                
                // Сохраняем attachment_id в данных
                currentImagesData[index].attachment_id = response.data.attachment_id;
                currentImagesData[index].imported_url = response.data.url;
                currentImagesData[index].status = 'success';
            } else {
                errors.push({
                    index: index + 1,
                    url: img.url,
                    error: response.data
                });
                statusCell.html('<span class="wcda-status-error">✗ Ошибка</span>');
                currentImagesData[index].status = 'error';
                currentImagesData[index].error = response.data;
                importedCount++;
                $('.wcda-progress-count').text(importedCount);
                $('.wcda-progress-fill').css('width', (importedCount / totalCount * 100) + '%');
            }
            
            importNext(index + 1);
        }).fail(function() {
            errors.push({
                index: index + 1,
                url: img.url,
                error: 'Ошибка соединения с сервером'
            });
            statusCell.html('<span class="wcda-status-error">✗ Ошибка сети</span>');
            importedCount++;
            $('.wcda-progress-count').text(importedCount);
            $('.wcda-progress-fill').css('width', (importedCount / totalCount * 100) + '%');
            importNext(index + 1);
        });
    }
    
    importNext(0);
}

// Проверка готовности всех изображений
function checkAllImagesReady() {
    var allReady = true;
    var allSuccess = true;
    
    currentImagesData.forEach(function(img, index) {
        if (!img.attachment_id) {
            allReady = false;
        }
        if (img.status !== 'success') {
            allSuccess = false;
        }
    });
    
    var continueBtn = $('.wcda-continue-import');
    if (allReady) {
        continueBtn.prop('disabled', false);
        if (allSuccess) {
            continueBtn.text('Продолжить (все изображения загружены)');
        } else {
            continueBtn.text('Продолжить (с ошибками)');
        }
    } else {
        continueBtn.prop('disabled', true);
        continueBtn.text('Ожидание завершения импорта...');
    }
}

// Показать ошибки импорта
function showImportErrors(errors) {
    var errorsDiv = $('.wcda-modal-errors');
    var errorsList = $('.wcda-errors-list');
    errorsList.empty();
    
    errors.forEach(function(err) {
        errorsList.append('<div class="wcda-error-item"><strong>Изображение #' + err.index + ':</strong><br>' + 
                         'URL: ' + err.url + '<br>' +
                         'Ошибка: ' + err.error.replace(/\n/g, '<br>') + '</div>');
    });
    
    errorsDiv.show();
}

// Завершение импорта и сохранение
function finishImageImport() {
    var continueBtn = $('.wcda-continue-import');
    continueBtn.prop('disabled', true).text('Сохранение...');
    
    // Собираем данные для сохранения
    var galleryIds = [];
    var attributesMapping = [];
    
    currentImagesData.forEach(function(img, index) {
        if (img.attachment_id && img.status === 'success') {
            galleryIds.push(img.attachment_id);
            
            if (currentCreateAttributes) {
                var nameInput = $('.wcda-image-name-input[data-index="' + index + '"]');
                var slugInput = $('.wcda-image-slug-input[data-index="' + index + '"]');
                attributesMapping.push({
                    name: nameInput.val(),
                    slug: slugInput.val(),
                    attachment_id: img.attachment_id
                });
            }
        }
    });
    
    $.post(wcda_ajax.ajax_url, {
        action: 'wcda_finish_image_import',
        product_id: currentProductId,
        gallery_ids: galleryIds,
        attributes_mapping: attributesMapping,
        use_shortcode: currentUseShortcode,
        nonce: wcda_ajax.nonce
    }, function(response) {
        if (response.success) {
            showResultMessage(response.data.message, 'success', $('.wcda-bulk-actions:first .wcda-messages'));
            $('#wcda-image-import-modal').hide();
            
            // Обновляем интерфейс
            $('.wcda-product-item[data-product-id="' + currentProductId + '"]').addClass('wcda-processed');
        } else {
            showResultMessage(response.data, 'error', $('.wcda-bulk-actions:first .wcda-messages'));
        }
    });
}

// Обработчик кнопки "Продолжить"
$(document).on('click', '.wcda-continue-import', function() {
    if ($(this).prop('disabled')) return;
    finishImageImport();
});

// Обновленный обработчик для одиночного извлечения изображений
$(document).on('click', '.wcda-extract-single-images', function() {
    var button = $(this);
    var productId = button.data('product-id');
    var useShortcode = $('.wcda-image-use-shortcode').is(':checked');
    var createAttributes = $('.wcda-image-create-attributes').is(':checked');
    
    startImageImport(productId, useShortcode, createAttributes);
});

// Обновленный обработчик массового извлечения изображений
$(document).on('click', '.wcda-bulk-extract-images', function() {
    var button = $(this);
    var selectedProducts = [];
    var useShortcode = $('.wcda-image-use-shortcode').is(':checked');
    var createAttributes = $('.wcda-image-create-attributes').is(':checked');
    
    $('.wcda-product-checkbox:checked').each(function() {
        selectedProducts.push($(this).val());
    });
    
    if (selectedProducts.length === 0) {
        alert('Пожалуйста, выберите хотя бы один товар');
        return;
    }
    
    if (selectedProducts.length > 1) {
        alert('Массовый импорт изображений будет выполнен последовательно для каждого товара.\n\n' +
              'Для каждого товара будет открыто модальное окно для настройки изображений.\n' +
              'Подготовьтесь к последовательной обработке ' + selectedProducts.length + ' товаров.');
    }
    
    processNextProduct(selectedProducts, 0, useShortcode, createAttributes);
});

// Последовательная обработка товаров
function processNextProduct(productIds, index, useShortcode, createAttributes) {
    if (index >= productIds.length) {
        showResultMessage('Все товары обработаны!', 'success', $('.wcda-bulk-actions:first .wcda-messages'));
        return;
    }
    
    var productId = productIds[index];
    showResultMessage('Обработка товара ' + (index + 1) + ' из ' + productIds.length + '...', 'success', $('.wcda-bulk-actions:first .wcda-messages'));
    
    // Создаем временный обработчик закрытия модального окна
    var modalCloseHandler = function() {
        setTimeout(function() {
            processNextProduct(productIds, index + 1, useShortcode, createAttributes);
        }, 500);
    };
    
    // Сохраняем оригинальный обработчик закрытия
    var originalClose = $('.wcda-modal-close, .wcda-cancel-import').off('click');
    
    startImageImport(productId, useShortcode, createAttributes);
    
    // Добавляем обработчик для продолжения после закрытия
    $(document).one('wcda-modal-closed', modalCloseHandler);
}

// Триггер закрытия модального окна
$(document).on('click', '.wcda-modal-close, .wcda-cancel-import', function() {
    $(document).trigger('wcda-modal-closed');
});


});