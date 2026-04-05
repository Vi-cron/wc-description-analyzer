<?php
/**
 * Упрощенный менеджер импорта изображений с автоматической сменой протокола
 * 
 * @package WC_Description_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Инициализация улучшенного функционала импорта изображений
 */
function wcda_init_enhanced_image_import() {
    // Регистрируем AJAX обработчики
    add_action('wp_ajax_wcda_enhanced_get_product_images', 'wcda_enhanced_get_product_images');
    add_action('wp_ajax_wcda_enhanced_import_image', 'wcda_enhanced_import_image');
    add_action('wp_ajax_wcda_enhanced_finish_import', 'wcda_enhanced_finish_import');
    add_action('wp_ajax_wcda_enhanced_manual_upload', 'wcda_enhanced_manual_upload');
    add_action('wp_ajax_wcda_generate_slug', 'wcda_generate_slug');
    add_action('wp_ajax_wcda_get_attachment_url', 'wcda_get_attachment_url');
    add_action('wp_ajax_wcda_update_image_metadata', 'wcda_update_image_metadata');
}

/**
 * Нормализация URL - преобразование относительных URL в абсолютные
 */
function wcda_normalize_url($url, $product_id = null) {
    // Если URL уже абсолютный, возвращаем как есть
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        return $url;
    }
    
    // Пробуем получить URL сайта
    $site_url = get_site_url();
    
    // Если URL начинается с //
    if (strpos($url, '//') === 0) {
        $protocol = is_ssl() ? 'https:' : 'http:';
        return $protocol . $url;
    }
    
    // Если URL начинается с /
    if (strpos($url, '/') === 0) {
        return $site_url . $url;
    }
    
    // Если URL относительный, пробуем получить из контекста товара
    if ($product_id) {
        $product = wc_get_product($product_id);
        if ($product) {
            $description = $product->get_short_description();
            // Ищем base URL в описании
            if (preg_match('/<base[^>]+href=["\']([^"\']+)["\']/i', $description, $base_match)) {
                $base_url = rtrim($base_match[1], '/');
                return $base_url . '/' . ltrim($url, '/');
            }
        }
    }
    
    // По умолчанию - просто добавляем URL сайта
    return $site_url . '/' . ltrim($url, '/');
}

/**
 * Получение хэша URL изображения
 */
function wcda_get_image_url_hash($url) {
    return md5($url);
}

/**
 * Проверка, существует ли уже изображение с таким URL в таблице wcda_image_attributes
 */
function wcda_find_existing_in_table($url) {
    global $wpdb;
    
    $url_hash = wcda_get_image_url_hash($url);
    $table_name = $wpdb->prefix . 'wcda_image_attributes';
    
    $result = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE image_url_hash = %s",
        $url_hash
    ));
    
    if ($result && $result->attachment_id) {
        // Проверяем, что вложение все еще существует
        $attachment = get_post($result->attachment_id);
        if ($attachment && $attachment->post_type === 'attachment') {
            return array(
                'attachment_id' => $result->attachment_id,
                'attribute_name' => $result->attribute_name,
                'attribute_slug' => $result->attribute_slug,
                'use_count' => $result->use_count
            );
        } else {
            // Вложение не существует, удаляем запись
            $wpdb->delete($table_name, array('id' => $result->id));
        }
    }
    
    return false;
}

/**
 * Сохранение информации об изображении в таблицу
 */
function wcda_save_image_to_table($url, $attachment_id, $attribute_name = '', $attribute_slug = '') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wcda_image_attributes';
    $url_hash = wcda_get_image_url_hash($url);
    
    // Проверяем, существует ли уже запись
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE image_url_hash = %s",
        $url_hash
    ));
    
    if ($existing) {
        // Обновляем существующую запись
        $wpdb->update(
            $table_name,
            array(
                'attachment_id' => $attachment_id,
                'attribute_name' => $attribute_name,
                'attribute_slug' => $attribute_slug,
                'use_count' => $wpdb->get_var($wpdb->prepare(
                    "SELECT use_count + 1 FROM {$table_name} WHERE image_url_hash = %s",
                    $url_hash
                )),
                'updated_at' => current_time('mysql')
            ),
            array('image_url_hash' => $url_hash)
        );
    } else {
        // Создаем новую запись
        $wpdb->insert(
            $table_name,
            array(
                'image_url_hash' => $url_hash,
                'image_url' => $url,
                'attachment_id' => $attachment_id,
                'attribute_name' => $attribute_name,
                'attribute_slug' => $attribute_slug,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'use_count' => 1
            )
        );
    }
    
    return $wpdb->insert_id;
}

/**
 * Обновление названия и слага изображения в таблице
 */
function wcda_update_image_metadata_in_table($attachment_id, $attribute_name, $attribute_slug) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wcda_image_attributes';
    
    $wpdb->update(
        $table_name,
        array(
            'attribute_name' => $attribute_name,
            'attribute_slug' => $attribute_slug,
            'updated_at' => current_time('mysql')
        ),
        array('attachment_id' => $attachment_id)
    );
}

/**
 * Проверка, существует ли уже изображение с таким URL в медиатеке
 */
function wcda_find_existing_attachment_by_url($url, $product_id = 0) {
    global $wpdb;
    
    // Нормализуем URL для поиска
    $normalized_url = wcda_normalize_url($url, $product_id);
    
    // Сначала проверяем в нашей таблице
    $table_result = wcda_find_existing_in_table($normalized_url);
    if ($table_result && $table_result['attachment_id']) {
        return $table_result['attachment_id'];
    }
    
    // Ищем вложение по GUID (полный URL)
    $attachment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s LIMIT 1",
        $normalized_url
    ));
    
    if ($attachment_id) {
        // Сохраняем в таблицу для будущих использований
        wcda_save_image_to_table($normalized_url, $attachment_id, '', '');
        return $attachment_id;
    }
    
    // Ищем по метаполю _wp_attached_file (относительный путь)
    $upload_dir = wp_upload_dir();
    $base_url = $upload_dir['baseurl'];
    
    if (strpos($normalized_url, $base_url) === 0) {
        $relative_path = substr($normalized_url, strlen($base_url) + 1);
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
            $relative_path
        ));
        
        if ($attachment_id) {
            // Сохраняем в таблицу для будущих использований
            wcda_save_image_to_table($normalized_url, $attachment_id, '', '');
            return $attachment_id;
        }
    }
    
    return false;
}

/**
 * Вспомогательная функция: предложить название из URL
 */
function wcda_suggest_image_name($url) {
    // Извлекаем имя файла из URL
    $path = parse_url($url, PHP_URL_PATH);
    if ($path) {
        $filename = basename($path);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/[^a-zA-Z0-9а-яА-Я\s]/u', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim(ucwords($name));
        
        if (!empty($name)) {
            return $name;
        }
    }
    
    return 'Изображение товара';
}

/**
 * Вспомогательная функция: предложить слаг из URL
 */
function wcda_suggest_image_slug($url) {
    $name = wcda_suggest_image_name($url);
    return wcda_sanitize_attribute_slug($name);
}

/**
 * Получить список изображений из описания товара
 */
function wcda_enhanced_get_product_images() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $product = wc_get_product($product_id);
    
    if (!$product) {
        wp_send_json_error('Товар не найден');
    }
    
    $description = $product->get_short_description();
    
    // Расширенный regex для поиска img тегов
    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $description, $matches);
    $image_urls = $matches[1];
    
    // Также ищем URL изображений в style атрибутах (background-image)
    preg_match_all('/background-image:\s*url\(["\']?([^"\')]+)["\']?\)/i', $description, $style_matches);
    if (!empty($style_matches[1])) {
        $image_urls = array_merge($image_urls, $style_matches[1]);
    }
    
    // Убираем дубликаты URL
    $image_urls = array_unique($image_urls);
    
    if (empty($image_urls)) {
        wp_send_json_error('Изображения не найдены в описании');
    }
    
    $images_data = array();
    foreach ($image_urls as $index => $url) {
        // Нормализуем URL
        $normalized_url = wcda_normalize_url(trim($url), $product_id);
        
        // Проверяем, есть ли уже такое изображение в таблице
        $existing_in_table = wcda_find_existing_in_table($normalized_url);
        
        if ($existing_in_table && $existing_in_table['attachment_id']) {
            // Изображение уже есть в таблице - пропускаем его
            continue;
        }
        
        // Проверяем, есть ли уже такое изображение в медиатеке
        $existing_attachment_id = wcda_find_existing_attachment_by_url($normalized_url, $product_id);
        
        $images_data[] = array(
            'index' => $index,
            'url' => $normalized_url,
            'original_url' => $url,
            'suggested_name' => wcda_suggest_image_name($normalized_url),
            'suggested_slug' => wcda_suggest_image_slug($normalized_url),
            'status' => 'pending',
            'existing_attachment_id' => $existing_attachment_id
        );
    }
    
    if (empty($images_data)) {
        wp_send_json_error('Все изображения уже были импортированы ранее');
    }
    
    wp_send_json_success(array('images' => $images_data));
}

/**
 * Проверка доступности URL
 */
function wcda_check_url_availability($url) {
    $args = array(
        'timeout' => 10,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'sslverify' => false,
        'redirection' => 5,
        'method' => 'HEAD'
    );
    
    $response = wp_remote_request($url, $args);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    return $status_code === 200;
}

/**
 * Загрузка изображения с автоматической сменой протокола
 */
function wcda_enhanced_upload_image($url, $parent_post_id = 0, $custom_name = null, $custom_slug = null) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    // Сначала проверяем в нашей таблице
    $table_result = wcda_find_existing_in_table($url);
    if ($table_result && $table_result['attachment_id']) {
        $existing_attachment_id = $table_result['attachment_id'];
        
        // Обновляем название и слаг если нужно
        $post_data = array();
        $need_update = false;
        
        if ($custom_name) {
            $post_data['post_title'] = $custom_name;
            $need_update = true;
        }
        if ($custom_slug) {
            $post_data['post_name'] = $custom_slug;
            $need_update = true;
        }
        
        if ($need_update) {
            $post_data['ID'] = $existing_attachment_id;
            wp_update_post($post_data);
            // Обновляем метаданные в таблице
            wcda_update_image_metadata_in_table($existing_attachment_id, $custom_name, $custom_slug);
        }
        
        return array(
            'success' => true,
            'attachment_id' => $existing_attachment_id,
            'image_url' => wp_get_attachment_url($existing_attachment_id),
            'attempt' => 'existing'
        );
    }
    
    // Проверяем в медиатеке
    $existing_attachment_id = wcda_find_existing_attachment_by_url($url, $parent_post_id);
    if ($existing_attachment_id) {
        // Сохраняем в таблицу
        wcda_save_image_to_table($url, $existing_attachment_id, $custom_name, $custom_slug);
        
        // Обновляем название и слаг если нужно
        $post_data = array();
        $need_update = false;
        
        if ($custom_name) {
            $post_data['post_title'] = $custom_name;
            $need_update = true;
        }
        if ($custom_slug) {
            $post_data['post_name'] = $custom_slug;
            $need_update = true;
        }
        
        if ($need_update) {
            $post_data['ID'] = $existing_attachment_id;
            wp_update_post($post_data);
        }
        
        return array(
            'success' => true,
            'attachment_id' => $existing_attachment_id,
            'image_url' => wp_get_attachment_url($existing_attachment_id),
            'attempt' => 'existing'
        );
    }
    
    $attempts = array();
    $urls_to_try = array();
    
    // Сначала нормализуем URL
    $url = wcda_normalize_url($url, $parent_post_id);
    
    // Генерируем список URL для попыток
    // Сначала пробуем оригинальный протокол
    $urls_to_try[] = array('protocol' => 'original', 'url' => $url);
    
    // Пробуем сменить протокол https -> http или http -> https
    if (strpos($url, 'https://') === 0) {
        $http_url = str_replace('https://', 'http://', $url);
        $urls_to_try[] = array('protocol' => 'http', 'url' => $http_url);
    } elseif (strpos($url, 'http://') === 0) {
        $https_url = str_replace('http://', 'https://', $url);
        $urls_to_try[] = array('protocol' => 'https', 'url' => $https_url);
    }
    
    // Убираем дубликаты
    $unique_urls = array();
    foreach ($urls_to_try as $attempt) {
        $key = $attempt['url'];
        if (!isset($unique_urls[$key])) {
            $unique_urls[$key] = $attempt;
        }
    }
    $urls_to_try = array_values($unique_urls);
    
    // Настройка HTTP запроса
    add_filter('http_request_args', 'wcda_set_image_download_args', 10, 2);
    
    $last_error = null;
    $success_result = null;
    
    // Пробуем каждый URL
    foreach ($urls_to_try as $attempt) {
        // Проверяем доступность URL
        if (!wcda_check_url_availability($attempt['url'])) {
            $attempts[] = array(
                'protocol' => $attempt['protocol'],
                'url' => $attempt['url'],
                'error' => 'URL недоступен'
            );
            continue;
        }
        
        // Скачиваем файл
        $tmp = download_url($attempt['url'], 30);
        
        if (is_wp_error($tmp)) {
            $attempts[] = array(
                'protocol' => $attempt['protocol'],
                'url' => $attempt['url'],
                'error' => $tmp->get_error_message()
            );
            $last_error = $tmp;
            continue;
        }
        
        // Проверяем, что файл существует и не пустой
        if (!$tmp || !file_exists($tmp) || filesize($tmp) === 0) {
            if ($tmp && file_exists($tmp)) {
                @unlink($tmp);
            }
            $attempts[] = array(
                'protocol' => $attempt['protocol'],
                'url' => $attempt['url'],
                'error' => 'Файл пустой'
            );
            continue;
        }
        
        // Проверяем, что файл действительно изображение
        $file_info = @getimagesize($tmp);
        if ($file_info === false) {
            @unlink($tmp);
            $attempts[] = array(
                'protocol' => $attempt['protocol'],
                'url' => $attempt['url'],
                'error' => 'Файл не является изображением'
            );
            continue;
        }
        
        // Определяем расширение файла
        $extensions = array(
            1 => 'gif', 2 => 'jpg', 3 => 'png', 4 => 'swf', 
            5 => 'psd', 6 => 'bmp', 7 => 'tiff', 8 => 'tiff', 
            9 => 'jpc', 10 => 'jp2', 11 => 'jpx', 12 => 'jb2', 
            13 => 'swc', 14 => 'iff', 15 => 'wbmp', 16 => 'xbm'
        );
        $extension = isset($extensions[$file_info[2]]) ? $extensions[$file_info[2]] : 'jpg';
        
        // Генерируем имя файла
        if ($custom_name) {
            $filename = sanitize_title($custom_name) . '.' . $extension;
        } else {
            $parsed_url = parse_url($attempt['url']);
            $path_parts = pathinfo($parsed_url['path']);
            $filename = sanitize_title($path_parts['filename'] ?: 'image') . '.' . $extension;
        }
        
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp
        );
        
        // Загружаем в медиатеку
        $attachment_id = media_handle_sideload($file_array, $parent_post_id);
        
        // Удаляем временный файл
        @unlink($tmp);
        
        if (!is_wp_error($attachment_id)) {
            $success_result = array(
                'success' => true, 
                'attachment_id' => $attachment_id, 
                'attempt' => $attempt['protocol'], 
                'url' => $attempt['url']
            );
            break;
        }
        
        $attempts[] = array(
            'protocol' => $attempt['protocol'],
            'url' => $attempt['url'],
            'error' => $attachment_id->get_error_message()
        );
        $last_error = $attachment_id;
    }
    
    remove_filter('http_request_args', 'wcda_set_image_download_args');
    
    // Возвращаем результат успешной загрузки
    if ($success_result) {
        $final_name = $custom_name ?: wcda_suggest_image_name($url);
        $final_slug = $custom_slug ?: wcda_suggest_image_slug($url);
        
        // Обновляем название и слаг
        $post_data = array(
            'ID' => $success_result['attachment_id'],
            'post_title' => $final_name,
            'post_name' => $final_slug
        );
        wp_update_post($post_data);
        
        // Сохраняем в таблицу
        wcda_save_image_to_table($url, $success_result['attachment_id'], $final_name, $final_slug);
        
        // Возвращаем URL изображения
        $success_result['image_url'] = wp_get_attachment_url($success_result['attachment_id']);
        
        return $success_result;
    }
    
    // Все попытки неудачны
    return array(
        'success' => false,
        'errors' => $attempts,
        'last_error' => $last_error ? $last_error->get_error_message() : 'Unknown error'
    );
}

/**
 * Настройка параметров HTTP запроса
 */
function wcda_set_image_download_args($args, $url) {
    $args['timeout'] = 45;
    $args['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    $args['sslverify'] = false;
    $args['redirection'] = 10;
    $args['reject_unsafe_urls'] = false;
    return $args;
}

/**
 * AJAX обработчик импорта одного изображения
 */
function wcda_enhanced_import_image() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $image_url = sanitize_text_field($_POST['image_url']);
    $custom_name = sanitize_text_field($_POST['custom_name']);
    $custom_slug = sanitize_title($_POST['custom_slug']);
    
    $result = wcda_enhanced_upload_image($image_url, $product_id, $custom_name, $custom_slug);
    
    if ($result['success']) {
        wp_send_json_success(array(
            'attachment_id' => $result['attachment_id'],
            'url' => $result['image_url'],
            'attempt' => $result['attempt']
        ));
    } else {
        // Формируем понятное сообщение об ошибке
        $error_message = 'Не удалось загрузить изображение';
        if (!empty($result['errors'])) {
            $error_details = array();
            foreach ($result['errors'] as $err) {
                $error_details[] = $err['protocol'] . ': ' . $err['error'];
            }
            $error_message .= ' - ' . implode('; ', $error_details);
        }
        
        wp_send_json_error(array(
            'message' => $error_message,
            'errors' => $result['errors']
        ));
    }
}

/**
 * AJAX обработчик завершения импорта
 */
function wcda_enhanced_finish_import() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $gallery_ids = array_map('intval', $_POST['gallery_ids']);
    $attributes_mapping = $_POST['attributes_mapping'];
    $use_shortcode = isset($_POST['use_shortcode']) && $_POST['use_shortcode'] === 'true';
    $remove_from_description = isset($_POST['remove_from_description']) && $_POST['remove_from_description'] === 'true';
    
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error('Товар не найден');
    }
    
    // Обновляем галерею (убираем дубликаты)
    $existing_gallery = $product->get_gallery_image_ids();
    $new_gallery = array_unique(array_merge($existing_gallery, $gallery_ids));
    $product->set_gallery_image_ids($new_gallery);
    
    // Создаем атрибуты если нужно
    if (!empty($attributes_mapping)) {
        $attributes = $product->get_attributes();
        
        foreach ($attributes_mapping as $mapping) {
            if (empty($mapping['name']) || empty($mapping['attachment_id'])) {
                continue;
            }
            
            $attr_slug = wcda_sanitize_attribute_slug($mapping['slug']);
            $taxonomy = 'pa_' . $attr_slug;
            
            wcda_create_attribute($mapping['name']);
            
            if (!term_exists($mapping['name'], $taxonomy)) {
                wp_insert_term($mapping['name'], $taxonomy);
            }
            
            $attributes[$taxonomy] = array(
                'name' => $taxonomy,
                'value' => $mapping['name'],
                'is_visible' => true,
                'is_variation' => false,
                'is_taxonomy' => true
            );
            
            // Обновляем метаданные в таблице для этого изображения
            wcda_update_image_metadata_in_table($mapping['attachment_id'], $mapping['name'], $mapping['slug']);
        }
        
        $product->set_attributes($attributes);
    }
    
    $product->save();
    
    // Обновляем описание
    if ($remove_from_description) {
        $description = $product->get_short_description();
        $clean_description = preg_replace('/<img[^>]+>/i', '', $description);
        $clean_description = preg_replace('/\s+/', ' ', $clean_description);
        
        if ($use_shortcode && !empty($gallery_ids)) {
            $shortcode = '[product_images_gallery]';
            $clean_description = trim($clean_description);
            if (!empty($clean_description)) {
                $clean_description .= "\n\n" . $shortcode;
            } else {
                $clean_description = $shortcode;
            }
        }
        
        wp_update_post(array(
            'ID' => $product_id,
            'post_excerpt' => trim($clean_description)
        ));
    }
    
    wp_send_json_success(array(
        'message' => sprintf(
            'Успешно импортировано %d изображений в галерею%s',
            count($gallery_ids),
            !empty($attributes_mapping) ? ' и создано ' . count($attributes_mapping) . ' атрибутов' : ''
        )
    ));
}

/**
 * AJAX обработчик ручной загрузки через форму
 */
function wcda_enhanced_manual_upload() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $image_url = sanitize_text_field($_POST['image_url']);
    $custom_name = sanitize_text_field($_POST['custom_name']);
    $custom_slug = sanitize_title($_POST['custom_slug']);
    
    if (empty($image_url)) {
        wp_send_json_error('URL изображения не может быть пустым');
    }
    
    $result = wcda_enhanced_upload_image($image_url, $product_id, $custom_name, $custom_slug);
    
    if ($result['success']) {
        wp_send_json_success(array(
            'attachment_id' => $result['attachment_id'],
            'url' => $result['image_url']
        ));
    } else {
        $error_message = 'Не удалось загрузить изображение';
        if (!empty($result['errors'])) {
            $error_details = array();
            foreach ($result['errors'] as $err) {
                $error_details[] = $err['protocol'] . ': ' . $err['error'];
            }
            $error_message .= ' - ' . implode('; ', $error_details);
        }
        
        wp_send_json_error(array(
            'message' => $error_message,
            'details' => $result['errors']
        ));
    }
}

/**
 * AJAX обработчик для генерации слага из названия
 */
function wcda_generate_slug() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $name = sanitize_text_field($_POST['name']);
    $slug = wcda_sanitize_attribute_slug($name);
    
    wp_send_json_success(array('slug' => $slug));
}

/**
 * AJAX обработчик для получения URL вложения по ID
 */
function wcda_get_attachment_url() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $attachment_id = intval($_POST['attachment_id']);
    $url = wp_get_attachment_url($attachment_id);
    
    if ($url) {
        wp_send_json_success(array('url' => $url));
    } else {
        wp_send_json_error('Вложение не найдено');
    }
}


function wcda_update_image_metadata() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $attachment_id = intval($_POST['attachment_id']);
    $attribute_name = sanitize_text_field($_POST['attribute_name']);
    $attribute_slug = sanitize_title($_POST['attribute_slug']);
    
    if (!$attachment_id) {
        wp_send_json_error('ID вложения не указан');
    }
    
    // Обновляем в таблице
    wcda_update_image_metadata_in_table($attachment_id, $attribute_name, $attribute_slug);
    
    // Также обновляем название в самом вложении
    if ($attribute_name) {
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_title' => $attribute_name,
            'post_name' => $attribute_slug
        ));
    }
    
    wp_send_json_success(array(
        'message' => 'Метаданные обновлены',
        'attachment_id' => $attachment_id,
        'name' => $attribute_name,
        'slug' => $attribute_slug
    ));
}
