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

});