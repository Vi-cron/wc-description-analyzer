jQuery(document).ready(function($) {
    // Извлечение одного изображения
    $('.wcda-extract-single-images').on('click', function() {
        var button = $(this);
        var productId = button.data('product-id');
        var productItem = button.closest('.wcda-product-item');
        
        button.prop('disabled', true).text('Обработка...');
        
        $.post(wcda_ajax.ajax_url, {
            action: 'wcda_extract_images_to_gallery',
            product_id: productId,
            nonce: wcda_ajax.nonce
        }, function(response) {
            if (response.success) {
                productItem.addClass('wcda-processed');
                button.text('✓ Готово').css('background', '#46b450');
                showResultMessage(response.data.message, 'success');
            } else {
                button.text('Ошибка').css('background', '#dc3232');
                showResultMessage(response.data, 'error');
            }
            setTimeout(function() {
                button.prop('disabled', false).text('Извлечь в галерею').css('background', '');
            }, 3000);
        });
    });
    
    // Извлечение одного размера
    $('.wcda-extract-single-dimensions').on('click', function() {
        var button = $(this);
        var productId = button.data('product-id');
        var productItem = button.closest('.wcda-product-item');
        
        button.prop('disabled', true).text('Обработка...');
        
        $.post(wcda_ajax.ajax_url, {
            action: 'wcda_extract_dimensions_to_attributes',
            product_id: productId,
            nonce: wcda_ajax.nonce
        }, function(response) {
            if (response.success) {
                productItem.addClass('wcda-processed');
                button.text('✓ Готово').css('background', '#46b450');
                showResultMessage(response.data.message, 'success');
            } else {
                button.text('Ошибка').css('background', '#dc3232');
                showResultMessage(response.data, 'error');
            }
            setTimeout(function() {
                button.prop('disabled', false).text('Извлечь размеры').css('background', '');
            }, 3000);
        });
    });
    
    // Выбор всех чекбоксов
    $('.wcda-select-all-checkbox').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('.wcda-product-checkbox').prop('checked', isChecked);
    });
    
    // Массовое извлечение изображений
    $('.wcda-bulk-extract-images').on('click', function() {
        var button = $(this);
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
            bulk_action: 'images_to_gallery',
            product_ids: selectedProducts,
            nonce: wcda_ajax.nonce
        }, function(response) {
            if (response.success) {
                showResultMessage(
                    'Обработано товаров: ' + response.data.success + 
                    ', ошибок: ' + response.data.failed,
                    'success'
                );
                // Отмечаем обработанные товары
                $('.wcda-product-checkbox:checked').each(function() {
                    $(this).closest('.wcda-product-item').addClass('wcda-processed');
                });
            } else {
                showResultMessage('Ошибка при массовой обработке', 'error');
            }
            button.prop('disabled', false);
            button.siblings('.spinner').removeClass('is-active');
        });
    });
    
    // Массовое извлечение размеров
    $('.wcda-bulk-extract-dimensions').on('click', function() {
        var button = $(this);
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
            nonce: wcda_ajax.nonce
        }, function(response) {
            if (response.success) {
                showResultMessage(
                    'Обработано товаров: ' + response.data.success + 
                    ', ошибок: ' + response.data.failed,
                    'success'
                );
                $('.wcda-product-checkbox:checked').each(function() {
                    $(this).closest('.wcda-product-item').addClass('wcda-processed');
                });
            } else {
                showResultMessage('Ошибка при массовой обработке', 'error');
            }
            button.prop('disabled', false);
            button.siblings('.spinner').removeClass('is-active');
        });
    });
    
    // Функция показа сообщений
    function showResultMessage(message, type) {
        var resultsDiv = $('#wcda-results');
        var resultsContent = $('#wcda-results-content');
        
        var messageClass = type === 'success' ? 'notice-success' : 'notice-error';
        var messageHtml = '<div class="notice ' + messageClass + ' is-dismissible"><p>' + message + '</p></div>';
        
        resultsContent.prepend(messageHtml);
        resultsDiv.show();
        
        // Автоскролл к результатам
        $('html, body').animate({
            scrollTop: resultsDiv.offset().top - 50
        }, 500);
        
        // Удаляем сообщение через 5 секунд
        setTimeout(function() {
            resultsContent.find('.notice').first().fadeOut(function() {
                $(this).remove();
                if (resultsContent.children().length === 0) {
                    resultsDiv.hide();
                }
            });
        }, 5000);
    }
});