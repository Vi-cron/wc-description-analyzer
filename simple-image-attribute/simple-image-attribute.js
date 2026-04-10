jQuery(document).ready(function($) {
    console.log('WCDA Attribute Image: Script loaded');
    
    var file_frame;
    
    // Функция для обновления превью в колонке таблицы
    function updateColumnPreview(termId, imageUrl) {
        var $preview = $('.wcda-attr-image-preview[data-term-id="' + termId + '"]');
        if ($preview.length) {
            if (imageUrl && imageUrl !== '') {
                $preview.css('background-image', 'url(' + imageUrl + ')');
                $preview.removeClass('wcda-empty-preview');
            } else {
                $preview.css('background-image', '');
                $preview.addClass('wcda-empty-preview');
            }
        }
    }
    
    // Обработчик клика по превью (и пустому, и с изображением)
    $(document).on('click', '.wcda-attr-image-preview', function(e) {
        e.preventDefault();
        
        var $preview = $(this);
        var termId = $preview.data('term-id');
        
        if (!termId) {
            console.log('No term ID found');
            return;
        }
        
        console.log('Preview clicked for term:', termId);
        
        if (file_frame) {
            file_frame.open();
            return;
        }
        
        file_frame = wp.media({
            title: 'Выберите изображение для атрибута',
            button: { text: 'Выбрать' },
            multiple: false
        });
        
        file_frame.on('select', function() {
            var attachment = file_frame.state().get('selection').first().toJSON();
            
            console.log('Image selected:', attachment.id, attachment.url);
            
            // Сохраняем изображение через AJAX
            $.ajax({
                url: wcda_attr_img.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcda_save_attribute_image_simple',
                    nonce: wcda_attr_img.nonce,
                    term_id: termId,
                    image_id: attachment.id
                },
                success: function(response) {
                    if (response.success) {
                        console.log('Image saved successfully');
                        updateColumnPreview(termId, attachment.url);
                        
                        // Обновляем форму редактирования, если она открыта
                        if ($('#wcda_attribute_image_id').length && $('#tag_ID').val() == termId) {
                            $('#wcda_attribute_image_id').val(attachment.id);
                            $('.wcda-image-preview').css('background-image', 'url(' + attachment.url + ')');
                            $('.wcda-image-preview').removeClass('wcda-no-preview');
                            $('.wcda-remove-image-btn').show();
                        }
                    } else {
                        console.error('Error saving image:', response);
                        alert('Ошибка при сохранении изображения');
                    }
                },
                error: function(xhr, status, error) {
                    console.log('AJAX error:', error);
                    alert('Ошибка AJAX: ' + error);
                }
            });
        });
        
        file_frame.open();
    });
    
    // Обработчик загрузки изображения из формы
    $(document).on('click', '.wcda-upload-image-btn', function(e) {
        e.preventDefault();
        
        console.log('Upload button clicked');
        
        var container = $(this).closest('.term-image-wrap, .form-field');
        var imageIdInput = container.find('#wcda_attribute_image_id');
        var preview = container.find('.wcda-image-preview');
        var removeBtn = container.find('.wcda-remove-image-btn');
        var termId = $('#tag_ID').val();
        
        if (file_frame) {
            file_frame.open();
            return;
        }
        
        file_frame = wp.media({
            title: 'Выберите изображение для атрибута',
            button: { text: 'Выбрать' },
            multiple: false
        });
        
        file_frame.on('select', function() {
            var attachment = file_frame.state().get('selection').first().toJSON();
            
            console.log('Image selected:', attachment.id, attachment.url);
            
            imageIdInput.val(attachment.id);
            preview.css('background-image', 'url(' + attachment.url + ')');
            preview.removeClass('wcda-no-preview');
            removeBtn.show();
            
            if (termId) {
                console.log('Saving image via AJAX for term:', termId);
                $.ajax({
                    url: wcda_attr_img.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wcda_save_attribute_image_simple',
                        nonce: wcda_attr_img.nonce,
                        term_id: termId,
                        image_id: attachment.id
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('AJAX success:', response);
                            updateColumnPreview(termId, attachment.url);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX error:', error);
                    }
                });
            }
        });
        
        file_frame.open();
    });
    
    // Удаление изображения
    $(document).on('click', '.wcda-remove-image-btn', function(e) {
        e.preventDefault();
        
        console.log('Remove button clicked');
        
        var container = $(this).closest('.term-image-wrap, .form-field');
        var imageIdInput = container.find('#wcda_attribute_image_id');
        var preview = container.find('.wcda-image-preview');
        var removeBtn = $(this);
        var termId = $('#tag_ID').val();
        
        if (termId) {
            console.log('Removing image via AJAX for term:', termId);
            $.ajax({
                url: wcda_attr_img.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcda_remove_attribute_image_simple',
                    nonce: wcda_attr_img.nonce,
                    term_id: termId
                },
                success: function(response) {
                    if (response.success) {
                        console.log('AJAX success:', response);
                        imageIdInput.val('');
                        preview.css('background-image', '');
                        preview.addClass('wcda-no-preview');
                        removeBtn.hide();
                        updateColumnPreview(termId, null);
                    }
                },
                error: function(xhr, status, error) {
                    console.log('AJAX error:', error);
                }
            });
        } else {
            imageIdInput.val('');
            preview.css('background-image', '');
            preview.addClass('wcda-no-preview');
            removeBtn.hide();
        }
    });
});