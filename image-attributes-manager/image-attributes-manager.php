<?php
/**
 * Модуль управления таблицей wcda_image_attributes
 * 
 * @package WC_Description_Analyzer
 * @subpackage Image_Attributes_Manager
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Класс для управления таблицей атрибутов изображений
 */
class WCDA_Image_Attributes_Manager {
    
    /**
     * Имя таблицы
     */
    private $table_name;
    
    /**
     * Конструктор
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wcda_image_attributes';
        
        // Добавляем пункт меню
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Регистрируем AJAX обработчики
        add_action('wp_ajax_wcda_get_image_attributes', array($this, 'ajax_get_image_attributes'));
        add_action('wp_ajax_wcda_update_image_attribute', array($this, 'ajax_update_image_attribute'));
        add_action('wp_ajax_wcda_delete_image_attribute', array($this, 'ajax_delete_image_attribute'));
        add_action('wp_ajax_wcda_bulk_delete_attributes', array($this, 'ajax_bulk_delete_attributes'));
        add_action('wp_ajax_wcda_export_attributes_csv', array($this, 'ajax_export_attributes_csv'));
        add_action('wp_ajax_wcda_sync_attributes', array($this, 'ajax_sync_attributes'));
        
        // Подключаем стили и скрипты
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Добавление пункта меню
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Управление атрибутами изображений', 'wc-description-analyzer'),
            __('Атрибуты изображений', 'wc-description-analyzer'),
            'manage_woocommerce',
            'wcda-image-attributes',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Подключение скриптов и стилей
     */
    public function enqueue_scripts($hook) {
        if ('woocommerce_page_wcda-image-attributes' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'wcda-attributes-manager',
            plugin_dir_url(__FILE__) . 'attributes-manager.css',
            array(),
            '1.0.0'
        );
        
        wp_enqueue_script(
            'wcda-attributes-manager',
            plugin_dir_url(__FILE__) . 'attributes-manager.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        wp_localize_script('wcda-attributes-manager', 'wcda_attr', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcda_attr_nonce'),
            'strings' => array(
                'confirm_delete' => __('Вы уверены, что хотите удалить эту запись?', 'wc-description-analyzer'),
                'confirm_bulk_delete' => __('Вы уверены, что хотите удалить выбранные записи?', 'wc-description-analyzer'),
                'confirm_sync' => __('Синхронизация проверит все изображения в медиатеке и обновит таблицу. Продолжить?', 'wc-description-analyzer'),
                'delete_error' => __('Ошибка при удалении', 'wc-description-analyzer'),
                'update_success' => __('Запись успешно обновлена', 'wc-description-analyzer'),
                'update_error' => __('Ошибка при обновлении', 'wc-description-analyzer'),
                'sync_success' => __('Синхронизация завершена', 'wc-description-analyzer'),
                'sync_error' => __('Ошибка при синхронизации', 'wc-description-analyzer')
            )
        ));
    }
    
    /**
     * Рендеринг страницы администрирования
     */
    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('У вас нет прав для доступа к этой странице.', 'wc-description-analyzer'));
        }
        
        $stats = $this->get_table_stats();
        ?>
        <div class="wrap wcda-attributes-manager">
            <h1><?php _e('Управление атрибутами изображений', 'wc-description-analyzer'); ?></h1>
            
            <!-- Статистика -->
            <div class="wcda-attr-stats">
                <div class="wcda-attr-stat-card">
                    <h3><?php _e('Всего изображений', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-attr-stat-number"><?php echo esc_html($stats['total']); ?></div>
                </div>
                <div class="wcda-attr-stat-card">
                    <h3><?php _e('С атрибутами', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-attr-stat-number"><?php echo esc_html($stats['with_attributes']); ?></div>
                </div>
                <div class="wcda-attr-stat-card">
                    <h3><?php _e('Всего использований', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-attr-stat-number"><?php echo esc_html($stats['total_uses']); ?></div>
                </div>
            </div>
            
            <!-- Панель действий -->
            <div class="wcda-attr-actions-bar">
                <div class="wcda-attr-bulk-actions">
                    <select id="wcda-bulk-action">
                        <option value=""><?php _e('Массовые действия', 'wc-description-analyzer'); ?></option>
                        <option value="delete"><?php _e('Удалить выбранные', 'wc-description-analyzer'); ?></option>
                        <option value="export"><?php _e('Экспорт в CSV', 'wc-description-analyzer'); ?></option>
                    </select>
                    <button type="button" class="button" id="wcda-apply-bulk"><?php _e('Применить', 'wc-description-analyzer'); ?></button>
                </div>
                <div class="wcda-attr-single-actions">
                    <button type="button" class="button button-primary" id="wcda-sync-attributes">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Синхронизировать с медиатекой', 'wc-description-analyzer'); ?>
                    </button>
                    <button type="button" class="button" id="wcda-export-all-csv">
                        <span class="dashicons dashicons-download"></span>
                        <?php _e('Экспорт всех данных', 'wc-description-analyzer'); ?>
                    </button>
                </div>
            </div>
            
            <!-- Таблица данных -->
            <div class="wcda-attr-table-container">
                <table class="wp-list-table widefat fixed striped" id="wcda-attributes-table">
                    <thead>
                        <tr>
                            <th width="40">
                                <input type="checkbox" id="wcda-select-all">
                            </th>
                            <th width="80"><?php _e('ID', 'wc-description-analyzer'); ?></th>
                            <th width="100"><?php _e('Превью', 'wc-description-analyzer'); ?></th>
                            <th><?php _e('URL изображения', 'wc-description-analyzer'); ?></th>
                            <th width="200"><?php _e('Название атрибута', 'wc-description-analyzer'); ?></th>
                            <th width="200"><?php _e('Слаг атрибута', 'wc-description-analyzer'); ?></th>
                            <th width="100"><?php _e('ID вложения', 'wc-description-analyzer'); ?></th>
                            <th width="100"><?php _e('Использований', 'wc-description-analyzer'); ?></th>
                            <th width="150"><?php _e('Дата создания', 'wc-description-analyzer'); ?></th>
                            <th width="120"><?php _e('Действия', 'wc-description-analyzer'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wcda-attributes-tbody">
                        <tr>
                            <td colspan="10" class="wcda-loading"><?php _e('Загрузка данных...', 'wc-description-analyzer'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Пагинация -->
            <div class="wcda-attr-pagination" id="wcda-attr-pagination"></div>
            
            <!-- Модальное окно редактирования -->
            <div id="wcda-edit-modal" class="wcda-attr-modal" style="display: none;">
                <div class="wcda-attr-modal-content">
                    <div class="wcda-attr-modal-header">
                        <h2><?php _e('Редактирование атрибута изображения', 'wc-description-analyzer'); ?></h2>
                        <span class="wcda-attr-modal-close">&times;</span>
                    </div>
                    <div class="wcda-attr-modal-body">
                        <form id="wcda-edit-form">
                            <input type="hidden" id="edit-id" name="id">
                            <div class="wcda-attr-form-field">
                                <label for="edit-image-url"><?php _e('URL изображения', 'wc-description-analyzer'); ?></label>
                                <input type="text" id="edit-image-url" name="image_url" class="widefat" readonly>
                            </div>
                            <div class="wcda-attr-form-field">
                                <label for="edit-attribute-name"><?php _e('Название атрибута', 'wc-description-analyzer'); ?></label>
                                <input type="text" id="edit-attribute-name" name="attribute_name" class="widefat">
                            </div>
                            <div class="wcda-attr-form-field">
                                <label for="edit-attribute-slug"><?php _e('Слаг атрибута', 'wc-description-analyzer'); ?></label>
                                <input type="text" id="edit-attribute-slug" name="attribute_slug" class="widefat">
                                <p class="description"><?php _e('Только латинские буквы, цифры и дефисы', 'wc-description-analyzer'); ?></p>
                            </div>
                            <div class="wcda-attr-form-field">
                                <label for="edit-attachment-id"><?php _e('ID вложения', 'wc-description-analyzer'); ?></label>
                                <input type="number" id="edit-attachment-id" name="attachment_id" class="widefat">
                            </div>
                        </form>
                    </div>
                    <div class="wcda-attr-modal-footer">
                        <button type="button" class="button button-primary" id="wcda-save-edit"><?php _e('Сохранить', 'wc-description-analyzer'); ?></button>
                        <button type="button" class="button" id="wcda-cancel-edit"><?php _e('Отмена', 'wc-description-analyzer'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Получение статистики таблицы
     */
    private function get_table_stats() {
        global $wpdb;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $with_attributes = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE attribute_name != '' AND attribute_name IS NOT NULL");
        $total_uses = $wpdb->get_var("SELECT SUM(use_count) FROM {$this->table_name}");
        
        return array(
            'total' => intval($total),
            'with_attributes' => intval($with_attributes),
            'total_uses' => intval($total_uses)
        );
    }
    
    /**
     * AJAX: Получение списка атрибутов
     */
    public function ajax_get_image_attributes() {
        check_ajax_referer('wcda_attr_nonce', 'nonce');
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        global $wpdb;
        
        $where = '';
        if ($search) {
            $where = $wpdb->prepare(" WHERE image_url LIKE %s OR attribute_name LIKE %s OR attribute_slug LIKE %s", 
                "%{$search}%", "%{$search}%", "%{$search}%");
        }
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}{$where}");
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}{$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        
        // Добавляем URL превью
        foreach ($items as &$item) {
            $item->preview_url = $this->get_preview_url($item->attachment_id, $item->image_url);
            $item->edit_link = get_edit_post_link($item->attachment_id);
        }
        
        wp_send_json_success(array(
            'items' => $items,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
            'current_page' => $page
        ));
    }
    
    /**
     * Получение URL превью
     */
    private function get_preview_url($attachment_id, $image_url) {
        if ($attachment_id) {
            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                return $url;
            }
        }
        return $image_url;
    }
    
    /**
     * AJAX: Обновление атрибута изображения
     */
    public function ajax_update_image_attribute() {
        check_ajax_referer('wcda_attr_nonce', 'nonce');
        
        $id = intval($_POST['id']);
        $attribute_name = sanitize_text_field($_POST['attribute_name']);
        $attribute_slug = sanitize_title($_POST['attribute_slug']);
        $attachment_id = intval($_POST['attachment_id']);
        
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'attribute_name' => $attribute_name,
                'attribute_slug' => $attribute_slug,
                'attachment_id' => $attachment_id,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $id)
        );
        
        if ($result !== false) {
            // Если есть attachment_id, обновляем название в медиатеке
            if ($attachment_id && $attribute_name) {
                wp_update_post(array(
                    'ID' => $attachment_id,
                    'post_title' => $attribute_name,
                    'post_name' => $attribute_slug
                ));
            }
            
            wp_send_json_success(array('message' => __('Запись обновлена', 'wc-description-analyzer')));
        } else {
            wp_send_json_error(array('message' => __('Ошибка при обновлении', 'wc-description-analyzer')));
        }
    }
    
    /**
     * AJAX: Удаление записи
     */
    public function ajax_delete_image_attribute() {
        check_ajax_referer('wcda_attr_nonce', 'nonce');
        
        $id = intval($_POST['id']);
        
        global $wpdb;
        $result = $wpdb->delete($this->table_name, array('id' => $id));
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    }
    
    /**
     * AJAX: Массовое удаление
     */
    public function ajax_bulk_delete_attributes() {
        check_ajax_referer('wcda_attr_nonce', 'nonce');
        
        $ids = array_map('intval', $_POST['ids']);
        
        if (empty($ids)) {
            wp_send_json_error(array('message' => __('Не выбраны записи', 'wc-description-analyzer')));
        }
        
        global $wpdb;
        $ids_placeholder = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$this->table_name} WHERE id IN ({$ids_placeholder})", $ids)
        );
        
        if ($result !== false) {
            wp_send_json_success(array('deleted' => $result));
        } else {
            wp_send_json_error();
        }
    }
    
    /**
     * AJAX: Экспорт в CSV
     */
    public function ajax_export_attributes_csv() {
        check_ajax_referer('wcda_attr_nonce', 'nonce');
        
        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
        
        global $wpdb;
        
        if (!empty($ids)) {
            $ids_placeholder = implode(',', array_fill(0, count($ids), '%d'));
            $items = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id IN ({$ids_placeholder})", $ids),
                ARRAY_A
            );
        } else {
            $items = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY id DESC", ARRAY_A);
        }
        
        if (empty($items)) {
            wp_send_json_error(array('message' => __('Нет данных для экспорта', 'wc-description-analyzer')));
        }
        
        // Формируем CSV
        $csv_output = fopen('php://temp', 'r+');
        
        // Заголовки
        $headers = array('ID', 'URL изображения', 'Название атрибута', 'Слаг атрибута', 'ID вложения', 'Кол-во использований', 'Дата создания', 'Дата обновления');
        fputcsv($csv_output, $headers);
        
        // Данные
        foreach ($items as $item) {
            $row = array(
                $item['id'],
                $item['image_url'],
                $item['attribute_name'],
                $item['attribute_slug'],
                $item['attachment_id'],
                $item['use_count'],
                $item['created_at'],
                $item['updated_at']
            );
            fputcsv($csv_output, $row);
        }
        
        rewind($csv_output);
        $csv_content = stream_get_contents($csv_output);
        fclose($csv_output);
        
        $filename = 'wcda_image_attributes_' . date('Y-m-d_H-i-s') . '.csv';
        
        wp_send_json_success(array(
            'csv' => base64_encode($csv_content),
            'filename' => $filename
        ));
    }
    
    /**
     * AJAX: Синхронизация с медиатекой
     */
    public function ajax_sync_attributes() {
        check_ajax_referer('wcda_attr_nonce', 'nonce');
        
        global $wpdb;
        
        $results = array(
            'updated' => 0,
            'deleted' => 0,
            'errors' => 0
        );
        
        // Получаем все записи
        $items = $wpdb->get_results("SELECT * FROM {$this->table_name}");
        
        foreach ($items as $item) {
            $needs_update = false;
            
            // Проверяем, существует ли вложение
            if ($item->attachment_id) {
                $attachment = get_post($item->attachment_id);
                if (!$attachment || $attachment->post_type !== 'attachment') {
                    // Вложение не существует, очищаем attachment_id
                    $wpdb->update(
                        $this->table_name,
                        array('attachment_id' => null),
                        array('id' => $item->id)
                    );
                    $results['updated']++;
                    continue;
                }
                
                // Проверяем соответствие названия
                if ($attachment->post_title !== $item->attribute_name && !empty($attachment->post_title)) {
                    $wpdb->update(
                        $this->table_name,
                        array('attribute_name' => $attachment->post_title),
                        array('id' => $item->id)
                    );
                    $results['updated']++;
                }
                
                // Проверяем соответствие слага
                if ($attachment->post_name !== $item->attribute_slug && !empty($attachment->post_name)) {
                    $wpdb->update(
                        $this->table_name,
                        array('attribute_slug' => $attachment->post_name),
                        array('id' => $item->id)
                    );
                    $results['updated']++;
                }
            } else {
                // Пытаемся найти вложение по URL
                $found_attachment_id = $this->find_attachment_by_url($item->image_url);
                if ($found_attachment_id) {
                    $wpdb->update(
                        $this->table_name,
                        array('attachment_id' => $found_attachment_id),
                        array('id' => $item->id)
                    );
                    $results['updated']++;
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Синхронизация завершена. Обновлено: %d, Очищено: %d', 'wc-description-analyzer'),
                $results['updated'],
                $results['deleted']
            ),
            'results' => $results
        ));
    }
    
    /**
     * Поиск вложения по URL
     */
    private function find_attachment_by_url($url) {
        // Используем функцию из image-import.php если она доступна
        if (function_exists('wcda_find_existing_attachment_by_url')) {
            return wcda_find_existing_attachment_by_url($url);
        }
        
        // Собственная реализация
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s LIMIT 1",
            $url
        ));
        
        return $attachment_id ? intval($attachment_id) : null;
    }
}

// Инициализация модуля
function wcda_init_image_attributes_manager() {
    new WCDA_Image_Attributes_Manager();
}
add_action('plugins_loaded', 'wcda_init_image_attributes_manager');