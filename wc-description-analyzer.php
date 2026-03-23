<?php
/**
 * Plugin Name: WooCommerce Description Analyzer
 * Plugin URI: https://github.com/Vi-cron/wc-description-analyzer
 * Description: Анализирует короткие описания товаров WooCommerce для выявления дубликатов, повторяющихся фраз и HTML-вставок (картинки, ссылки).
 * Version: 1.0.0
 * Author: Victor R.
 * Text Domain: wc-description-analyzer
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.8
 */

// Предотвращение прямого доступа к файлу
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

// Подключение стилей для админки
function wcda_admin_styles($hook) {
    if ('woocommerce_page_wc-description-analyzer' !== $hook) {
        return;
    }
    ?>
    <style>
        .wcda-container {
            max-width: 1200px;
            margin: 20px 0;
        }
        .wcda-section {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin-bottom: 20px;
            padding: 20px;
        }
        .wcda-section h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .wcda-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .wcda-stat-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 15px;
        }
        .wcda-stat-card h3 {
            margin: 0 0 10px 0;
            color: #1d2327;
        }
        .wcda-stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #0073aa;
        }
        .wcda-duplicate-item {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .wcda-phrase-item {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
            display: inline-block;
            margin-right: 10px;
        }
        .wcda-html-item {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
        }
        .wcda-button {
            margin-top: 10px !important;
        }
        .wcda-product-link {
            text-decoration: none;
            margin-right: 10px;
        }
        .wcda-product-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            background: #fff;
        }
    </style>
    <?php
}
add_action('admin_enqueue_scripts', 'wcda_admin_styles');

// Функция для получения всех товаров и их описаний
function wcda_get_products_descriptions() {
    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    );
    
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
        
        if (!empty(trim($short_description))) {
            $descriptions[] = array(
                'id' => $product_id,
                'title' => $product->get_name(),
                'description' => $short_description,
                'edit_link' => get_edit_post_link($product_id),
                'permalink' => get_permalink($product_id)
            );
        }
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
    
    // Оставляем только те, у которых больше 1 товара
    foreach ($seen as $hash => $data) {
        if (count($data['products']) > 1) {
            $duplicates[$hash] = $data;
        }
    }
    
    return $duplicates;
}

// Функция для поиска повторяющихся фраз (3+ слова)
function wcda_find_repeating_phrases($descriptions, $min_occurrences = 3) {
    $all_text = '';
    $phrases_count = array();
    
    // Собираем весь текст
    foreach ($descriptions as $item) {
        $text = strip_tags($item['description']); // Убираем HTML
        $text = preg_replace('/\s+/', ' ', $text); // Нормализуем пробелы
        $all_text .= ' ' . $text;
    }
    
    // Разбиваем на предложения
    $sentences = preg_split('/(?<=[.?!])\s+/', $all_text, -1, PREG_SPLIT_NO_EMPTY);
    
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if (str_word_count($sentence) >= 3) { // Фразы от 3 слов
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
    
    // Фильтруем по минимальному количеству вхождений
    $phrases_count = array_filter($phrases_count, function($item) use ($min_occurrences) {
        return $item['count'] >= $min_occurrences;
    });
    
    // Сортируем по убыванию
    usort($phrases_count, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    return $phrases_count;
}

// Функция для поиска HTML-тегов в описаниях
function wcda_find_html_tags($descriptions) {
    $html_stats = array(
        'total_with_html' => 0,
        'img_tags' => array(),
        'a_tags' => array(),
        'other_tags' => array(),
        'products_with_html' => array()
    );
    
    foreach ($descriptions as $item) {
        $desc = $item['description'];
        $has_html = false;
        $tags_found = array();
        
        // Поиск img тегов
        if (preg_match_all('/<img[^>]+>/i', $desc, $matches)) {
            $html_stats['img_tags'] = array_merge($html_stats['img_tags'], $matches[0]);
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
        }
    }
    
    // Убираем дубликаты тегов для статистики
    $html_stats['img_tags'] = array_unique($html_stats['img_tags']);
    $html_stats['a_tags'] = array_unique($html_stats['a_tags']);
    
    return $html_stats;
}

// Функция для рендеринга страницы администратора
function wcda_render_admin_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('У вас нет прав для доступа к этой странице.', 'wc-description-analyzer'));
    }
    
    // Получаем данные
    $data = wcda_get_products_descriptions();
    $descriptions = $data['descriptions'];
    
    // Анализируем
    $duplicates = wcda_find_duplicates($descriptions);
    $repeating_phrases = wcda_find_repeating_phrases($descriptions, 3);
    $html_stats = wcda_find_html_tags($descriptions);
    
    // Подключаем шаблон страницы
    include_once plugin_dir_path(__FILE__) . 'admin-page.php';
}

// Функция для добавления ссылки на настройки в списке плагинов
function wcda_add_settings_link($links) {
    if (wcda_check_woocommerce()) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-description-analyzer') . '">' . __('Анализ описаний', 'wc-description-analyzer') . '</a>';
        array_unshift($links, $settings_link);
    }
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wcda_add_settings_link');

// Объявление поддержки HPOS (High-Performance Order Storage)
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});