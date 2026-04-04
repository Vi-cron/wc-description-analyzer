jQuery(document).ready(function ($) {
    /**
     * Упрощенный скрипт для импорта изображений с автоматической сменой протокола
     * Превью отображается только после успешной загрузки в медиатеку
     */

    // Кэш для превью изображений
    var previewCache = {};

    var EnhancedImageImport = {
        modal: null,
        currentProductId: null,
        currentImagesData: null,
        currentUseShortcode: false,
        currentCreateAttributes: false,
        currentRemoveFromDescription: true,

        init: function () {
            this.createModal();
            this.bindEvents();
        },

        createModal: function () {
            if ($('#wcda-enhanced-import-modal').length) {
                this.modal = $('#wcda-enhanced-import-modal');
                return;
            }

            var modalHtml = `
            <div id="wcda-enhanced-import-modal" class="wcda-modal" style="display: none;">
                <div class="wcda-modal-content wcda-modal-large">
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
                                        <th width="40">№</th>
                                        <th width="100">Превью</th>
                                        <th width="200">Название</th>
                                        <th width="150">Слаг</th>
                                        <th width="120">Статус</th>
                                    </tr>
                                </thead>
                                <tbody id="wcda-enhanced-images-tbody"></tbody>
                            </table>
                        </div>
                        
                        <div class="wcda-modal-errors" style="display: none;">
                            <h4>Ошибки импорта:</h4>
                            <div class="wcda-errors-list"></div>
                        </div>
                    </div>
                    <div class="wcda-modal-footer">
                        <label style="float: left; margin-top: 5px;">
                            <input type="checkbox" id="wcda-remove-from-desc" checked> Удалить изображения из описания
                        </label>
                        <button type="button" class="button button-primary wcda-enhanced-continue" disabled>Продолжить</button>
                        <button type="button" class="button wcda-enhanced-cancel">Отмена</button>
                    </div>
                </div>
            </div>
        `;

            $('body').append(modalHtml);
            this.modal = $('#wcda-enhanced-import-modal');
        },

        bindEvents: function () {
            var self = this;

            $('.wcda-modal-close, .wcda-enhanced-cancel').on('click', function () {
                self.modal.hide();
            });

            $('#wcda-enhanced-continue').on('click', function () {
                if ($(this).prop('disabled')) return;
                self.finishImport();
            });
        },

        start: function (productId, useShortcode, createAttributes) {
            this.currentProductId = productId;
            this.currentUseShortcode = useShortcode;
            this.currentCreateAttributes = createAttributes;

            var self = this;

            // Показываем модальное окно сразу с загрузкой
            this.showModalWithLoading();

            $.post(wcda_ajax.ajax_url, {
                action: 'wcda_enhanced_get_product_images',
                product_id: productId,
                nonce: wcda_ajax.nonce
            }, function (response) {
                if (response.success) {
                    self.currentImagesData = response.data.images;
                    self.renderImagesTable();
                    self.startAutoImport();
                } else {
                    self.showMessage(response.data, 'error');
                    self.modal.hide();
                }
            }).fail(function () {
                self.showMessage('Ошибка загрузки данных', 'error');
                self.modal.hide();
            });
        },

        showModalWithLoading: function () {
            var tbody = $('#wcda-enhanced-images-tbody');
            tbody.html('<tr><td colspan="5" style="text-align: center;">Загрузка данных...<\/td></tr>');
            this.modal.css('display', 'flex');
        },

        renderImagesTable: function () {
            var tbody = $('#wcda-enhanced-images-tbody');
            tbody.empty();

            var self = this;
            $('.wcda-total-count').text(this.currentImagesData.length);

            this.currentImagesData.forEach(function (img, index) {
                var row = $('<tr>');

                // Номер
                row.append($('<td>').text(index + 1));

                // Превью (заглушка)
                var previewImg = $('<img>', {
                    class: 'wcda-image-preview',
                    src: 'https://via.placeholder.com/80x80?text=Loading...',
                    'data-url': img.url,
                    'data-index': index,
                    style: 'max-width:80px;max-height:80px;'
                });
                row.append($('<td>').append(previewImg));

                // Название
                var nameInput = $('<input>', {
                    type: 'text',
                    class: 'wcda-image-name-input',
                    value: img.suggested_name,
                    'data-index': index,
                    style: 'width: 100%;'
                });
                row.append($('<td>').append(nameInput));

                // Слаг
                var slugInput = $('<input>', {
                    type: 'text',
                    class: 'wcda-image-slug-input',
                    value: img.suggested_slug,
                    'data-index': index,
                    style: 'width: 100%;'
                });
                row.append($('<td>').append(slugInput));

                // Статус (с возможной кнопкой ручного ввода)
                var statusCell = $('<td>');
                statusCell.html('<span class="wcda-status-pending">Ожидает</span>');
                row.append(statusCell);

                tbody.append(row);

                // Обновление слага при изменении названия - через AJAX запрос к серверу
                var debounceTimer;
                nameInput.on('input', function () {
                    var idx = $(this).data('index');
                    var newName = $(this).val();

                    var slug = self.transliterateAndSlugify(newName);
                        $('.wcda-image-slug-input[data-index="' + idx + '"]').val(slug);

                    // Дебаунс - ждем паузу в вводе
                    /*clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(function () {
                        // Отправляем запрос на сервер для генерации слага
                        $.post(wcda_ajax.ajax_url, {
                            action: 'wcda_generate_slug',
                            name: newName,
                            nonce: wcda_ajax.nonce
                        }, function (response) {
                            if (response.success) {
                                $('.wcda-image-slug-input[data-index="' + idx + '"]').val(response.data.slug);
                            } else {
                                // fallback - простая транслитерация на клиенте
                                var slug = self.transliterateAndSlugify(newName);
                                $('.wcda-image-slug-input[data-index="' + idx + '"]').val(slug);
                            }
                        }).fail(function () {
                            // fallback при ошибке сети
                            var slug = self.transliterateAndSlugify(newName);
                            $('.wcda-image-slug-input[data-index="' + idx + '"]').val(slug);
                        });
                    }, 300);*/
                });


            });
        },
        transliterateAndSlugify: function (text) {
            if (!text) return '';

            // Карта транслитерации для кириллицы
            var cyrillicMap = {
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

            // Транслитерация
            var slug = '';
            for (var i = 0; i < text.length; i++) {
                var char = text[i];
                if (cyrillicMap[char]) {
                    slug += cyrillicMap[char];
                } else if (/[a-zA-Z0-9]/.test(char)) {
                    slug += char.toLowerCase();
                } else if (char === ' ' || char === '-' || char === '_') {
                    slug += '-';
                }
            }

            // Заменяем пробелы и спецсимволы на дефисы, убираем дублирующиеся дефисы
            slug = slug.toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .substring(0, 50);

            return slug;
        },

        startAutoImport: function () {
            var self = this;
            var progressDiv = $('.wcda-import-progress');
            progressDiv.show();

            var importedCount = 0;
            var totalCount = this.currentImagesData.length;
            var errors = [];

            function importNext(index) {
                if (index >= totalCount) {
                    $('.wcda-progress-fill').css('width', '100%');
                    setTimeout(function () {
                        progressDiv.hide();
                    }, 1000);

                    if (errors.length > 0) {
                        self.showErrors(errors);
                    }

                    self.checkAllReady();
                    return;
                }

                var img = self.currentImagesData[index];
                var nameInput = $('.wcda-image-name-input[data-index="' + index + '"]');
                var slugInput = $('.wcda-image-slug-input[data-index="' + index + '"]');
                var statusCell = nameInput.closest('tr').find('td:eq(4)');
                var previewImg = nameInput.closest('tr').find('.wcda-image-preview');

                statusCell.html('<span class="wcda-status-loading">Загрузка...</span>');

                $.post(wcda_ajax.ajax_url, {
                    action: 'wcda_enhanced_import_image',
                    product_id: self.currentProductId,
                    image_url: img.url,
                    custom_name: nameInput.val(),
                    custom_slug: slugInput.val(),
                    nonce: wcda_ajax.nonce
                }, function (response) {
                    importedCount++;
                    $('.wcda-progress-count').text(importedCount);
                    $('.wcda-progress-fill').css('width', (importedCount / totalCount * 100) + '%');

                    if (response.success) {
                        // Успешная загрузка - обновляем превью
                        if (response.data.url) {
                            if (previewCache[img.url]) {
                                URL.revokeObjectURL(previewCache[img.url]);
                                delete previewCache[img.url];
                            }
                            previewImg.attr('src', response.data.url);
                            previewCache[img.url] = response.data.url;
                        }

                        statusCell.html('<span class="wcda-status-success">✓ Загружено</span>');
                        self.currentImagesData[index].attachment_id = response.data.attachment_id;
                        self.currentImagesData[index].status = 'success';
                    } else {
                        var errorMsg = response.data.message || 'Ошибка загрузки';

                        // Показываем кнопку ручного ввода при ошибке
                        var manualBtn = $('<button>', {
                            type: 'button',
                            class: 'button button-small wcda-manual-upload-btn',
                            'data-index': index,
                            text: '📷 Ручной ввод'
                        });

                        statusCell.html('');
                        statusCell.append($('<span>', {
                            class: 'wcda-status-error',
                            text: '✗ ' + errorMsg
                        }));
                        statusCell.append($('<br>'));
                        statusCell.append(manualBtn);

                        errors.push({
                            index: index + 1,
                            url: img.url,
                            error: errorMsg
                        });
                        self.currentImagesData[index].status = 'error';
                    }

                    importNext(index + 1);
                }).fail(function (xhr) {
                    importedCount++;
                    $('.wcda-progress-count').text(importedCount);
                    $('.wcda-progress-fill').css('width', (importedCount / totalCount * 100) + '%');

                    var errorMsg = 'Ошибка соединения';
                    if (xhr.responseJSON && xhr.responseJSON.data) {
                        errorMsg = xhr.responseJSON.data.message || errorMsg;
                    }

                    // Показываем кнопку ручного ввода при ошибке сети
                    var manualBtn = $('<button>', {
                        type: 'button',
                        class: 'button button-small wcda-manual-upload-btn',
                        'data-index': index,
                        text: '📷 Ручной ввод'
                    });

                    statusCell.html('');
                    statusCell.append($('<span>', {
                        class: 'wcda-status-error',
                        text: '✗ ' + errorMsg
                    }));
                    statusCell.append($('<br>'));
                    statusCell.append(manualBtn);

                    errors.push({
                        index: index + 1,
                        url: img.url,
                        error: errorMsg
                    });
                    self.currentImagesData[index].status = 'error';
                    importNext(index + 1);
                });
            }

            importNext(0);
        },

        checkAllReady: function () {
            var hasSuccess = false;

            this.currentImagesData.forEach(function (img) {
                if (img.attachment_id) {
                    hasSuccess = true;
                }
            });

            var continueBtn = $('.wcda-enhanced-continue');
            if (hasSuccess) {
                continueBtn.prop('disabled', false);
                var successCount = this.currentImagesData.filter(img => img.attachment_id).length;
                continueBtn.text('Продолжить (' + successCount + ' загружено)');
            }
        },

        finishImport: function () {
            var self = this;
            var continueBtn = $('.wcda-enhanced-continue');
            continueBtn.prop('disabled', true).text('Сохранение...');

            var galleryIds = [];
            var attributesMapping = [];
            var removeFromDesc = $('#wcda-remove-from-desc').is(':checked');

            this.currentImagesData.forEach(function (img, index) {
                if (img.attachment_id) {
                    galleryIds.push(img.attachment_id);

                    if (self.currentCreateAttributes) {
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
                action: 'wcda_enhanced_finish_import',
                product_id: this.currentProductId,
                gallery_ids: galleryIds,
                attributes_mapping: attributesMapping,
                use_shortcode: this.currentUseShortcode,
                remove_from_description: removeFromDesc,
                nonce: wcda_ajax.nonce
            }, function (response) {
                if (response.success) {
                    self.showMessage(response.data.message, 'success');
                    self.modal.hide();
                    $('.wcda-product-item[data-product-id="' + self.currentProductId + '"]').addClass('wcda-processed');
                } else {
                    self.showMessage(response.data, 'error');
                }
            }).fail(function () {
                self.showMessage('Ошибка при сохранении', 'error');
            }).always(function () {
                continueBtn.prop('disabled', false).text('Продолжить');
            });
        },

        manualUpload: function (index) {
            var self = this;
            var img = this.currentImagesData[index];
            var nameInput = $('.wcda-image-name-input[data-index="' + index + '"]');
            var slugInput = $('.wcda-image-slug-input[data-index="' + index + '"]');
            var statusCell = nameInput.closest('tr').find('td:eq(4)');
            var previewImg = nameInput.closest('tr').find('.wcda-image-preview');

            var modalHtml = `
            <div id="wcda-manual-upload-modal" class="wcda-modal" style="z-index: 100001;">
                <div class="wcda-modal-content" style="max-width: 500px;">
                    <div class="wcda-modal-header">
                        <h3>Ручная загрузка изображения</h3>
                        <span class="wcda-modal-close">&times;</span>
                    </div>
                    <div class="wcda-modal-body">
                        <p><strong>Оригинальный URL:</strong><br><small style="word-break: break-all;">${escapeHtml(img.url)}</small></p>
                        <p>
                            <label><strong>Новый URL:</strong></label>
                            <input type="text" id="manual-url" class="widefat" value="${escapeHtml(img.url)}" style="width: 100%;">
                        </p>
                        <p>
                            <label><strong>Название:</strong></label>
                            <input type="text" id="manual-name" class="widefat" value="${escapeHtml(nameInput.val())}">
                        </p>
                        <p>
                            <label><strong>Слаг:</strong></label>
                            <input type="text" id="manual-slug" class="widefat" value="${escapeHtml(slugInput.val())}">
                        </p>
                    </div>
                    <div class="wcda-modal-footer">
                        <button type="button" class="button button-primary" id="confirm-manual-upload">Загрузить</button>
                        <button type="button" class="button cancel-manual">Отмена</button>
                    </div>
                </div>
            </div>
        `;

            $('body').append(modalHtml);
            var modal = $('#wcda-manual-upload-modal');

            $('#confirm-manual-upload').on('click', function () {
                var manualUrl = $('#manual-url').val();
                var manualName = $('#manual-name').val();
                var manualSlug = $('#manual-slug').val();

                if (!manualUrl) {
                    alert('Введите URL изображения');
                    return;
                }

                $(this).prop('disabled', true).text('Загрузка...');
                statusCell.html('<span class="wcda-status-loading">Ручная загрузка...</span>');

                $.post(wcda_ajax.ajax_url, {
                    action: 'wcda_enhanced_manual_upload',
                    product_id: self.currentProductId,
                    image_url: manualUrl,
                    custom_name: manualName,
                    custom_slug: manualSlug,
                    nonce: wcda_ajax.nonce
                }, function (response) {
                    if (response.success) {
                        // Обновляем превью
                        if (response.data.url) {
                            if (previewCache[img.url]) {
                                URL.revokeObjectURL(previewCache[img.url]);
                                delete previewCache[img.url];
                            }
                            previewImg.attr('src', response.data.url);
                            previewCache[img.url] = response.data.url;
                        }

                        // Обновляем название и слаг в полях ввода
                        nameInput.val(manualName);
                        slugInput.val(manualSlug);

                        statusCell.html('<span class="wcda-status-success">✓ Загружено</span>');
                        self.currentImagesData[index].attachment_id = response.data.attachment_id;
                        self.currentImagesData[index].status = 'success';
                        self.checkAllReady();
                        modal.remove();
                    } else {
                        var errorMsg = response.data.message || 'Неизвестная ошибка';
                        statusCell.html('');
                        statusCell.append($('<span>', {
                            class: 'wcda-status-error',
                            text: '✗ ' + errorMsg
                        }));
                        statusCell.append($('<br>'));
                        statusCell.append($('<button>', {
                            type: 'button',
                            class: 'button button-small wcda-manual-upload-btn',
                            'data-index': index,
                            text: '📷 Ручной ввод'
                        }));
                        alert('Ошибка: ' + errorMsg);
                        $('#confirm-manual-upload').prop('disabled', false).text('Загрузить');
                    }
                }).fail(function () {
                    statusCell.html('');
                    statusCell.append($('<span>', {
                        class: 'wcda-status-error',
                        text: '✗ Ошибка сети'
                    }));
                    statusCell.append($('<br>'));
                    statusCell.append($('<button>', {
                        type: 'button',
                        class: 'button button-small wcda-manual-upload-btn',
                        'data-index': index,
                        text: '📷 Ручной ввод'
                    }));
                    alert('Ошибка соединения с сервером');
                    $('#confirm-manual-upload').prop('disabled', false).text('Загрузить');
                });
            });

            $('.wcda-modal-close, .cancel-manual').on('click', function () {
                modal.remove();
            });

            modal.css('display', 'flex');
        },

        showMessage: function (message, type) {
            var messageClass = type === 'success' ? 'notice-success' : 'notice-error';
            var messageHtml = '<div class="notice ' + messageClass + ' is-dismissible" style="margin:10px 0;"><p>' + message + '</p></div>';
            $('.wcda-bulk-actions:first .wcda-messages').html(messageHtml);

            setTimeout(function () {
                $('.wcda-bulk-actions:first .wcda-messages').empty();
            }, 5000);
        },

        showErrors: function (errors) {
            var errorsDiv = $('.wcda-modal-errors');
            var errorsList = $('.wcda-errors-list');
            errorsList.empty();

            errors.forEach(function (err) {
                var errorHtml = '<div class="wcda-error-item" style="margin-bottom: 10px; padding: 10px; background: #f8d7da; border-radius: 4px;">';
                errorHtml += '<strong>Изображение #' + err.index + ':</strong><br>';
                errorHtml += 'URL: ' + escapeHtml(err.url) + '<br>';
                errorHtml += 'Ошибка: ' + escapeHtml(err.error);
                errorHtml += '</div>';
                errorsList.append(errorHtml);
            });

            errorsDiv.show();
        }
    };

    // Функция экранирования HTML
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // Инициализация при загрузке документа
    jQuery(document).ready(function ($) {
        EnhancedImageImport.init();

        // Переопределяем обработчики для использования улучшенной версии
        $(document).off('click', '.wcda-extract-single-images');
        $(document).off('click', '.wcda-bulk-extract-images');

        // Одиночное извлечение изображений
        $(document).on('click', '.wcda-extract-single-images', function () {
            var productId = $(this).data('product-id');
            var useShortcode = $('.wcda-image-use-shortcode').is(':checked');
            var createAttributes = $('.wcda-image-create-attributes').is(':checked');

            EnhancedImageImport.start(productId, useShortcode, createAttributes);
        });

        // Массовое извлечение изображений
        $(document).on('click', '.wcda-bulk-extract-images', function () {
            var selectedProducts = [];
            var useShortcode = $('.wcda-image-use-shortcode').is(':checked');
            var createAttributes = $('.wcda-image-create-attributes').is(':checked');

            $('.wcda-product-checkbox:checked').each(function () {
                selectedProducts.push($(this).val());
            });

            if (selectedProducts.length === 0) {
                alert('Пожалуйста, выберите хотя бы один товар');
                return;
            }

            if (selectedProducts.length > 1) {
                if (!confirm('Будет обработано ' + selectedProducts.length + ' товаров последовательно. Продолжить?')) {
                    return;
                }
            }

            processNextProductEnhanced(selectedProducts, 0, useShortcode, createAttributes);
        });

        // Обработчик для кнопки ручного ввода (делегирование)
        $(document).on('click', '.wcda-manual-upload-btn', function () {
            var index = $(this).data('index');
            EnhancedImageImport.manualUpload(index);
        });

        function processNextProductEnhanced(productIds, index, useShortcode, createAttributes) {
            if (index >= productIds.length) {
                EnhancedImageImport.showMessage('Все товары обработаны!', 'success');
                return;
            }

            var productId = productIds[index];
            EnhancedImageImport.showMessage('Обработка товара ' + (index + 1) + ' из ' + productIds.length + '...', 'success');

            // Сохраняем обработчик закрытия
            var checkInterval = setInterval(function () {
                if (!$('#wcda-enhanced-import-modal').is(':visible')) {
                    clearInterval(checkInterval);
                    setTimeout(function () {
                        processNextProductEnhanced(productIds, index + 1, useShortcode, createAttributes);
                    }, 500);
                }
            }, 500);

            EnhancedImageImport.start(productId, useShortcode, createAttributes);
        }
    });

});