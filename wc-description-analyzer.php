<?php
/**
 * Plugin Name: WooCommerce Description Analyzer & Auto-Fixer
 * Plugin URI: https://github.com/Vi-cron/wc-description-analyzer
 * Description: Анализирует короткие описания товаров WooCommerce, выявляет закономерности и автоматически переносит данные в атрибуты и галерею.
 * Version: 2.0.0
 * Author: Victor R.
 * Text Domain: wc-description-analyzer
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.8
 */

if (!defined('ABSPATH')) {
    exit;
}

// Проверка наличия WooCommerce
function wcda_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php _e('WooCommerce Description Analyzer требует установленный и активированный WooCommerce.', 'wc-description-analyzer'); ?></p>
            </div>
            <?php
        });
        return false;
    }
    return true;
}

// Добавление пункта меню в админку
function wcda_add_admin_menu() {
    if (!wcda_check_woocommerce()) {
        return;
    }
    
    add_submenu_page(
        'woocommerce',
        __('Анализ описаний товаров', 'wc-description-analyzer'),
        __('Анализ описаний', 'wc-description-analyzer'),
        'manage_woocommerce',
        'wc-description-analyzer',
        'wcda_render_admin_page'
    );
}
add_action('admin_menu', 'wcda_add_admin_menu');

// Добавляем AJAX обработчики
add_action('wp_ajax_wcda_extract_images_to_gallery', 'wcda_extract_images_to_gallery');
add_action('wp_ajax_wcda_extract_dimensions_to_attributes', 'wcda_extract_dimensions_to_attributes');
add_action('wp_ajax_wcda_bulk_process', 'wcda_bulk_process');

// Функция для получения всех товаров и их описаний
function wcda_get_products_descriptions($product_ids = null) {
    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    );
    
    if ($product_ids && is_array($product_ids)) {
        $args['post__in'] = $product_ids;
    }
    
    $product_ids = get_posts($args);
    $descriptions = array();
    $total_products = 0;
    
    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            continue;
        }
        
        $short_description = $product->get_short_description();
        $total_products++;
        
        $descriptions[] = array(
            'id' => $product_id,
            'title' => $product->get_name(),
            'description' => $short_description,
            'edit_link' => get_edit_post_link($product_id),
            'permalink' => get_permalink($product_id)
        );
    }
    
    return array(
        'descriptions' => $descriptions,
        'total_products' => $total_products,
        'total_with_description' => count($descriptions)
    );
}

// Функция для поиска дублирующихся описаний
function wcda_find_duplicates($descriptions) {
    $duplicates = array();
    $seen = array();
    
    foreach ($descriptions as $item) {
        $desc = trim($item['description']);
        if (empty($desc)) {
            continue;
        }
        
        $hash = md5($desc);
        
        if (!isset($seen[$hash])) {
            $seen[$hash] = array(
                'description' => $desc,
                'products' => array()
            );
        }
        
        $seen[$hash]['products'][] = array(
            'id' => $item['id'],
            'title' => $item['title'],
            'edit_link' => $item['edit_link'],
            'permalink' => $item['permalink']
        );
    }
    
    foreach ($seen as $hash => $data) {
        if (count($data['products']) > 1) {
            $duplicates[$hash] = $data;
        }
    }
    
    return $duplicates;
}

// Функция для поиска повторяющихся фраз
function wcda_find_repeating_phrases($descriptions, $min_occurrences = 3) {
    $all_text = '';
    $phrases_count = array();
    
    foreach ($descriptions as $item) {
        $text = strip_tags($item['description']);
        $text = preg_replace('/\s+/', ' ', $text);
        $all_text .= ' ' . $text;
    }
    
    $sentences = preg_split('/(?<=[.?!])\s+/', $all_text, -1, PREG_SPLIT_NO_EMPTY);
    
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if (str_word_count($sentence) >= 3) {
            $key = md5($sentence);
            if (!isset($phrases_count[$key])) {
                $phrases_count[$key] = array(
                    'phrase' => $sentence,
                    'count' => 0
                );
            }
            $phrases_count[$key]['count']++;
        }
    }
    
    $phrases_count = array_filter($phrases_count, function($item) use ($min_occurrences) {
        return $item['count'] >= $min_occurrences;
    });
    
    usort($phrases_count, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    return $phrases_count;
}

// Функция для поиска HTML-тегов
function wcda_find_html_tags($descriptions) {
    $html_stats = array(
        'total_with_html' => 0,
        'img_tags' => array(),
        'a_tags' => array(),
        'other_tags' => array(),
        'products_with_html' => array(),
        'products_with_images' => array()
    );
    
    foreach ($descriptions as $item) {
        $desc = $item['description'];
        $has_html = false;
        $tags_found = array();
        $images_found = array();
        
        // Поиск img тегов
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $desc, $matches)) {
            $html_stats['img_tags'] = array_merge($html_stats['img_tags'], $matches[0]);
            $images_found = $matches[1];
            $has_html = true;
            $tags_found[] = 'img';
        }
        
        // Поиск a тегов
        if (preg_match_all('/<a[^>]+>.*?<\/a>/is', $desc, $matches)) {
            $html_stats['a_tags'] = array_merge($html_stats['a_tags'], $matches[0]);
            $has_html = true;
            $tags_found[] = 'a';
        }
        
        // Поиск других тегов
        if (preg_match('/<(?!img|a\/?)[^>]+>/i', $desc)) {
            $has_html = true;
            $tags_found[] = 'other';
        }
        
        if ($has_html) {
            $html_stats['total_with_html']++;
            $html_stats['products_with_html'][] = array(
                'id' => $item['id'],
                'title' => $item['title'],
                'edit_link' => $item['edit_link'],
                'tags' => array_unique($tags_found)
            );
            
            if (!empty($images_found)) {
                $html_stats['products_with_images'][] = array(
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'edit_link' => $item['edit_link'],
                    'images' => $images_found
                );
            }
        }
    }
    
    $html_stats['img_tags'] = array_unique($html_stats['img_tags']);
    $html_stats['a_tags'] = array_unique($html_stats['a_tags']);
    
    return $html_stats;
}

// Функция для поиска шаблонов параметров
function wcda_find_parameter_patterns($descriptions) {
    $patterns = array();
    $param_regex = '/([А-Яа-яA-Za-z\s]+):\s*([\d\.,]+\s*(?:мм|см|м|mm|cm|m)?)/iu';
    
    foreach ($descriptions as $item) {
        $desc = $item['description'];
        preg_match_all($param_regex, $desc, $matches, PREG_SET_ORDER);
        
        if (count($matches) >= 2) {
            $pattern_parts = array();
            $original_parts = array();
            $values = array();
            
            foreach ($matches as $match) {
                $param_name = trim($match[1]);
                $param_value = trim($match[2]);
                
                $pattern_parts[] = $param_name . ': {{NUMBER}}';
                $original_parts[] = $param_name . ': ' . $param_value;
                $values[$param_name] = $param_value;
            }
            
            $pattern_string = implode(' ', $pattern_parts);
            $original_string = implode(' ', $original_parts);
            $pattern_key = md5($pattern_string);
            
            if (!isset($patterns[$pattern_key])) {
                $patterns[$pattern_key] = array(
                    'pattern' => $pattern_string,
                    'example' => $original_string,
                    'products' => array(),
                    'count' => 0,
                    'param_names' => array_keys($values)
                );
            }
            
            $patterns[$pattern_key]['products'][] = array(
                'id' => $item['id'],
                'title' => $item['title'],
                'edit_link' => $item['edit_link'],
                'values' => $values,
                'full_text' => $original_string
            );
            $patterns[$pattern_key]['count']++;
        }
    }
    
    usort($patterns, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    return $patterns;
}

// Функция для извлечения размеров из описания
function wcda_extract_dimensions_from_description($description) {
    $dimensions = array();
    $dimension_names = array('Длина', 'Ширина', 'Глубина', 'Высота', 'Length', 'Width', 'Depth', 'Height');
    $dimension_regex = '/(' . implode('|', $dimension_names) . '):\s*([\d\.,]+)\s*(?:мм|см|м|mm|cm|m)?/iu';
    
    preg_match_all($dimension_regex, $description, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $name = $match[1];
        $value = $match[2];
        // Очищаем значение от запятых и пробелов
        $value = str_replace(',', '.', $value);
        $dimensions[$name] = floatval($value);
    }
    
    return $dimensions;
}

// AJAX обработчик: извлечение изображений в галерею
function wcda_extract_images_to_gallery() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $product = wc_get_product($product_id);
    
    if (!$product) {
        wp_send_json_error('Товар не найден');
    }
    
    $description = $product->get_short_description();
    
    // Извлекаем все изображения из описания
    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $description, $matches);
    $image_urls = $matches[1];
    
    if (empty($image_urls)) {
        wp_send_json_error('Изображения не найдены в описании');
    }
    
    $gallery_ids = array();
    $attachment_ids = $product->get_gallery_image_ids();
    
    foreach ($image_urls as $url) {
        // Проверяем, не добавлено ли уже это изображение
        $attachment_id = attachment_url_to_postid($url);
        
        if (!$attachment_id) {
            // Загружаем изображение в медиатеку
            $attachment_id = wcda_upload_image_from_url($url, $product_id);
        }
        
        if ($attachment_id && !in_array($attachment_id, $attachment_ids)) {
            $gallery_ids[] = $attachment_id;
        }
    }
    
    // Обновляем галерею
    $new_gallery = array_merge($attachment_ids, $gallery_ids);
    $product->set_gallery_image_ids($new_gallery);
    $product->save();
    
    // Удаляем изображения из описания
    $clean_description = preg_replace('/<img[^>]+>/i', '', $description);
    $clean_description = preg_replace('/\s+/', ' ', $clean_description);
    wp_update_post(array(
        'ID' => $product_id,
        'post_excerpt' => trim($clean_description)
    ));
    
    wp_send_json_success(array(
        'message' => sprintf('Добавлено %d изображений в галерею', count($gallery_ids)),
        'images_count' => count($gallery_ids)
    ));
}

// AJAX обработчик: извлечение размеров в атрибуты
function wcda_extract_dimensions_to_attributes() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $product = wc_get_product($product_id);
    
    if (!$product) {
        wp_send_json_error('Товар не найден');
    }
    
    $description = $product->get_short_description();
    $dimensions = wcda_extract_dimensions_from_description($description);
    
    if (empty($dimensions)) {
        wp_send_json_error('Размеры не найдены в описании');
    }
    
    $updated_attributes = array();
    
    foreach ($dimensions as $name => $value) {
        // Нормализуем название атрибута
        $attr_name = sanitize_title($name);
        
        // Проверяем существование атрибута
        $attribute_id = wc_attribute_taxonomy_id_by_name($name);
        
        if (!$attribute_id) {
            // Создаем атрибут, если его нет
            $attribute_id = wcda_create_attribute($name);
        }
        
        if ($attribute_id) {
            $taxonomy = 'pa_' . sanitize_title($name);
            
            // Добавляем значение атрибута
            if (!term_exists($value, $taxonomy)) {
                wp_insert_term($value, $taxonomy);
            }
            
            // Устанавливаем атрибут для товара
            $product_attributes = $product->get_attributes();
            $product_attributes[$taxonomy] = array(
                'name' => $taxonomy,
                'value' => $value,
                'is_visible' => true,
                'is_variation' => false,
                'is_taxonomy' => true
            );
            $product->set_attributes($product_attributes);
            $updated_attributes[$name] = $value;
        }
    }
    
    $product->save();
    
    // Удаляем размеры из описания
    $clean_description = preg_replace('/([А-Яа-яA-Za-z\s]+):\s*[\d\.,]+\s*(?:мм|см|м|mm|cm|m)?/iu', '', $description);
    $clean_description = preg_replace('/\s+/', ' ', $clean_description);
    wp_update_post(array(
        'ID' => $product_id,
        'post_excerpt' => trim($clean_description)
    ));
    
    wp_send_json_success(array(
        'message' => 'Размеры успешно перенесены в атрибуты',
        'attributes' => $updated_attributes
    ));
}

// AJAX обработчик: массовая обработка
function wcda_bulk_process() {
    check_ajax_referer('wcda_ajax_nonce', 'nonce');
    
    $action = sanitize_text_field($_POST['bulk_action']);
    $product_ids = array_map('intval', $_POST['product_ids']);
    
    if (empty($product_ids)) {
        wp_send_json_error('Не выбраны товары');
    }
    
    $results = array(
        'success' => 0,
        'failed' => 0,
        'details' => array()
    );
    
    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            $results['failed']++;
            continue;
        }
        
        $description = $product->get_short_description();
        $updated = false;
        
        if ($action === 'images_to_gallery') {
            preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $description, $matches);
            $image_urls = $matches[1];
            
            if (!empty($image_urls)) {
                $gallery_ids = $product->get_gallery_image_ids();
                foreach ($image_urls as $url) {
                    $attachment_id = attachment_url_to_postid($url);
                    if (!$attachment_id) {
                        $attachment_id = wcda_upload_image_from_url($url, $product_id);
                    }
                    if ($attachment_id && !in_array($attachment_id, $gallery_ids)) {
                        $gallery_ids[] = $attachment_id;
                    }
                }
                $product->set_gallery_image_ids($gallery_ids);
                $description = preg_replace('/<img[^>]+>/i', '', $description);
                $updated = true;
            }
        }
        
        if ($action === 'dimensions_to_attributes') {
            $dimensions = wcda_extract_dimensions_from_description($description);
            if (!empty($dimensions)) {
                foreach ($dimensions as $name => $value) {
                    $taxonomy = 'pa_' . sanitize_title($name);
                    if (!term_exists($value, $taxonomy)) {
                        wp_insert_term($value, $taxonomy);
                    }
                    $product_attributes = $product->get_attributes();
                    $product_attributes[$taxonomy] = array(
                        'name' => $taxonomy,
                        'value' => $value,
                        'is_visible' => true,
                        'is_variation' => false,
                        'is_taxonomy' => true
                    );
                    $product->set_attributes($product_attributes);
                }
                $description = preg_replace('/([А-Яа-яA-Za-z\s]+):\s*[\d\.,]+\s*(?:мм|см|м|mm|cm|m)?/iu', '', $description);
                $updated = true;
            }
        }
        
        if ($updated) {
            $product->save();
            wp_update_post(array(
                'ID' => $product_id,
                'post_excerpt' => trim(preg_replace('/\s+/', ' ', $description))
            ));
            $results['success']++;
            $results['details'][] = $product->get_name();
        } else {
            $results['failed']++;
        }
    }
    
    wp_send_json_success($results);
}

// Вспомогательная функция: загрузка изображения по URL
function wcda_upload_image_from_url($url, $parent_post_id = 0) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    $attachment_id = media_sideload_image($url, $parent_post_id, null, 'id');
    
    if (is_wp_error($attachment_id)) {
        return false;
    }
    
    return $attachment_id;
}

// Вспомогательная функция: создание атрибута
function wcda_create_attribute($attribute_name) {
    global $wpdb;
    
    $attribute_name = sanitize_text_field($attribute_name);
    $attribute_slug = sanitize_title($attribute_name);
    
    $attribute_id = $wpdb->get_var($wpdb->prepare("
        SELECT attribute_id 
        FROM {$wpdb->prefix}woocommerce_attribute_taxonomies 
        WHERE attribute_name = %s
    ", $attribute_slug));
    
    if ($attribute_id) {
        return $attribute_id;
    }
    
    $data = array(
        'attribute_name' => $attribute_slug,
        'attribute_label' => $attribute_name,
        'attribute_type' => 'select',
        'attribute_orderby' => 'menu_order',
        'attribute_public' => 1,
    );
    
    $wpdb->insert($wpdb->prefix . 'woocommerce_attribute_taxonomies', $data);
    
    // Очищаем кэш атрибутов
    delete_transient('wc_attribute_taxonomies');
    
    // Регистрируем таксономию
    register_taxonomy('pa_' . $attribute_slug, 'product', array(
        'labels' => array('name' => $attribute_name),
        'hierarchical' => true,
        'show_ui' => false,
        'query_var' => true,
        'rewrite' => false,
    ));
    
    return $wpdb->insert_id;
}

// Подключение стилей и скриптов
function wcda_admin_scripts($hook) {
    if ('woocommerce_page_wc-description-analyzer' !== $hook) {
        return;
    }
    
    wp_enqueue_script('wcda-admin-script', plugin_dir_url(__FILE__) . 'admin/js/admin-script.js', array('jquery'), '1.0', true);
    wp_localize_script('wcda-admin-script', 'wcda_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wcda_ajax_nonce')
    ));
    
    wp_enqueue_style('wcda-admin-style', plugin_dir_url(__FILE__) . 'admin/css/admin-style.css', array(), '1.0');
}
add_action('admin_enqueue_scripts', 'wcda_admin_scripts');

// Функция рендеринга страницы администратора
function wcda_render_admin_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('У вас нет прав для доступа к этой странице.', 'wc-description-analyzer'));
    }
    
    $data = wcda_get_products_descriptions();
    $descriptions = $data['descriptions'];
    
    $duplicates = wcda_find_duplicates($descriptions);
    $repeating_phrases = wcda_find_repeating_phrases($descriptions, 3);
    $html_stats = wcda_find_html_tags($descriptions);
    $parameter_patterns = wcda_find_parameter_patterns($descriptions);
    
    include_once plugin_dir_path(__FILE__) . 'admin-page.php';
}

// Добавление ссылки на настройки
function wcda_add_settings_link($links) {
    if (wcda_check_woocommerce()) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-description-analyzer') . '">' . __('Анализ описаний', 'wc-description-analyzer') . '</a>';
        array_unshift($links, $settings_link);
    }
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wcda_add_settings_link');

// Объявление поддержки HPOS
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});