<?php
/**
 * Управление импортом изображений из описаний товаров
 * 
 * @package WC_Description_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Image_Import_Manager {
    
    private $product_id;
    private $image_urls;
    private $imported_images;
    private $errors;
    
    public function __construct($product_id, $image_urls) {
        $this->product_id = $product_id;
        $this->image_urls = $image_urls;
        $this->imported_images = array();
        $this->errors = array();
    }
    
    /**
     * Получить список изображений для импорта с проверкой статуса
     */
    public function get_images_for_import() {
        $images_data = array();
        
        foreach ($this->image_urls as $index => $url) {
            $images_data[] = array(
                'index' => $index,
                'url' => $url,
                'original_url' => $url,
                'status' => 'pending',
                'status_message' => 'Ожидает импорта',
                'attachment_id' => null,
                'filename' => $this->get_filename_from_url($url),
                'suggested_name' => $this->suggest_name_from_url($url),
                'suggested_slug' => $this->suggest_slug_from_url($url),
                'error_details' => null
            );
        }
        
        return $images_data;
    }
    
    /**
     * Импорт изображения с обработкой ошибок
     */
    public function import_image($index, $custom_name = null, $custom_slug = null) {
        if (!isset($this->image_urls[$index])) {
            return array('success' => false, 'error' => 'Изображение не найдено');
        }
        
        $url = $this->image_urls[$index];
        $original_url = $url;
        
        // Попытка исправить URL
        $fixed_url = $this->fix_image_url($url);
        
        if ($fixed_url !== $url) {
            $url = $fixed_url;
        }
        
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Проверка доступности URL
        $headers = @get_headers($url, 1);
        if (!$headers || strpos($headers[0], '200') === false) {
            $error = $this->analyze_url_error($url);
            return array('success' => false, 'error' => $error);
        }
        
        // Подготовка имени файла
        $filename = $custom_name ? sanitize_title($custom_name) . '.jpg' : $this->get_filename_from_url($url);
        $filename = $custom_slug ? $custom_slug . '.jpg' : $filename;
        
        // Попытка импорта
        $attachment_id = media_sideload_image($url, $this->product_id, $custom_name, 'id');
        
        if (is_wp_error($attachment_id)) {
            $error_message = $this->handle_import_error($attachment_id, $url);
            return array('success' => false, 'error' => $error_message);
        }
        
        // Обновление названия и слага
        if ($custom_name || $custom_slug) {
            $post_data = array(
                'ID' => $attachment_id,
                'post_title' => $custom_name ?: $this->suggest_name_from_url($original_url),
                'post_name' => $custom_slug ?: $this->suggest_slug_from_url($original_url)
            );
            wp_update_post($post_data);
        }
        
        return array(
            'success' => true,
            'attachment_id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'filename' => $filename
        );
    }
    
    /**
     * Исправление проблемного URL
     */
    private function fix_image_url($url) {
        // Попытка сменить протокол
        if (strpos($url, 'https://') === 0) {
            $http_url = str_replace('https://', 'http://', $url);
            $headers = @get_headers($http_url, 1);
            if ($headers && strpos($headers[0], '200') !== false) {
                return $http_url;
            }
        } elseif (strpos($url, 'http://') === 0) {
            $https_url = str_replace('http://', 'https://', $url);
            $headers = @get_headers($https_url, 1);
            if ($headers && strpos($headers[0], '200') !== false) {
                return $https_url;
            }
        }
        
        // Очистка URL от лишних параметров
        $url = preg_replace('/\?.*$/', '', $url);
        
        // Декодирование URL
        $url = urldecode($url);
        
        return $url;
    }
    
    /**
     * Анализ ошибки URL
     */
    private function analyze_url_error($url) {
        $host = parse_url($url, PHP_URL_HOST);
        
        $error = "Не удалось загрузить изображение: " . $url;
        $error .= "\n\nВозможные причины и решения:\n";
        
        // Проверка SSL
        if (strpos($url, 'https://') === 0) {
            $error .= "- SSL сертификат может быть недействительным. Попробуйте использовать HTTP вместо HTTPS.\n";
        }
        
        // Проверка внешних хостов
        if (!in_array($host, array(parse_url(home_url(), PHP_URL_HOST), 'localhost', '127.0.0.1'))) {
            $error .= "- Изображение находится на внешнем хосте ({$host}). ";
            $error .= "Убедитесь, что в файле wp-config.php добавлена строка:\n";
            $error .= "define('WP_HTTP_BLOCK_EXTERNAL', false);\n";
            $error .= "Или разрешите конкретный хост:\n";
            $error .= "define('WP_ACCESSIBLE_HOSTS', '{$host}');\n";
        }
        
        // Проверка прав доступа
        $error .= "- Проверьте, доступно ли изображение по прямой ссылке в браузере.\n";
        $error .= "- Убедитесь, что хост не блокирует запросы от вашего сервера.";
        
        return $error;
    }
    
    /**
     * Обработка ошибок импорта WordPress
     */
    private function handle_import_error($wp_error, $url) {
        $error_code = $wp_error->get_error_code();
        $error_message = $wp_error->get_error_message();
        
        $result = "Ошибка импорта: " . $error_message . "\n\n";
        
        switch ($error_code) {
            case 'http_404':
                $result .= "Файл не найден (404). Проверьте URL: " . $url;
                break;
            case 'http_403':
                $result .= "Доступ запрещен (403). Возможно, требуется авторизация или хост блокирует запросы.";
                break;
            case 'invalid_image':
                $result .= "Файл не является корректным изображением. Проверьте формат файла.";
                break;
            default:
                $result .= "Рекомендации:\n";
                $result .= "- Скачайте изображение вручную и загрузите через медиабиблиотеку\n";
                $result .= "- Проверьте настройки безопасности вашего хостинга\n";
                $result .= "- Убедитесь, что в php.ini разрешена функция allow_url_fopen";
        }
        
        return $result;
    }
    
    /**
     * Получить имя файла из URL
     */
    private function get_filename_from_url($url) {
        $path = parse_url($url, PHP_URL_PATH);
        $filename = basename($path);
        
        if (empty($filename)) {
            $filename = 'image_' . md5($url) . '.jpg';
        }
        
        return $filename;
    }
    
    /**
     * Предложить название из URL
     */
    private function suggest_name_from_url($url) {
        $filename = $this->get_filename_from_url($url);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/[^a-zA-Z0-9а-яА-Я\s]/u', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim(ucwords($name));
    }
    
    /**
     * Предложить слаг из URL
     */
    private function suggest_slug_from_url($url) {
        $name = $this->suggest_name_from_url($url);
        return sanitize_title($name);
    }
    
    /**
     * Сохранить сопоставления атрибутов
     */
    public function save_attributes_mapping($mappings) {
        $product = wc_get_product($this->product_id);
        if (!$product) {
            return false;
        }
        
        $attributes = $product->get_attributes();
        
        foreach ($mappings as $mapping) {
            if (empty($mapping['name']) || empty($mapping['attachment_id'])) {
                continue;
            }
            
            $attr_slug = sanitize_title($mapping['slug']);
            $taxonomy = 'pa_' . $attr_slug;
            
            // Создаем атрибут, если его нет
            if (!taxonomy_exists($taxonomy)) {
                $this->create_product_attribute($mapping['name'], $attr_slug);
            }
            
            // Добавляем значение
            $term = term_exists($mapping['name'], $taxonomy);
            if (!$term) {
                $term = wp_insert_term($mapping['name'], $taxonomy);
            }
            
            if (!is_wp_error($term) && isset($term['term_id'])) {
                $attributes[$taxonomy] = array(
                    'name' => $taxonomy,
                    'value' => $mapping['name'],
                    'is_visible' => true,
                    'is_variation' => false,
                    'is_taxonomy' => true
                );
            }
        }
        
        $product->set_attributes($attributes);
        $product->save();
        
        return true;
    }
    
    /**
     * Создание атрибута продукта
     */
    private function create_product_attribute($name, $slug) {
        global $wpdb;
        
        $attribute_id = $wpdb->get_var($wpdb->prepare("
            SELECT attribute_id 
            FROM {$wpdb->prefix}woocommerce_attribute_taxonomies 
            WHERE attribute_name = %s
        ", $slug));
        
        if ($attribute_id) {
            return $attribute_id;
        }
        
        $data = array(
            'attribute_name' => $slug,
            'attribute_label' => $name,
            'attribute_type' => 'select',
            'attribute_orderby' => 'menu_order',
            'attribute_public' => 1,
        );
        
        $wpdb->insert($wpdb->prefix . 'woocommerce_attribute_taxonomies', $data);
        delete_transient('wc_attribute_taxonomies');
        
        register_taxonomy('pa_' . $slug, 'product', array(
            'labels' => array('name' => $name),
            'hierarchical' => true,
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => false,
        ));
        
        return $wpdb->insert_id;
    }
    
    /**
     * Получить ошибки импорта
     */
    public function get_errors() {
        return $this->errors;
    }
}