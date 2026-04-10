<?php
/**
 * Модуль управления таблицей wcda_image_attributes
 * 
 * @package WC_Description_Analyzer
 * @subpackage Image_Attributes_Manager
 * @version 1.2.0
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
        add_action('wp_ajax_wcda_sync_attributes', array($this, 'ajax_sync_attributes'));
        add_action('wp_ajax_wcda_import_attributes_json', array($this, 'ajax_import_attributes_json'));
        
        // Регистрируем прямые endpoint для экспорта
        add_action('admin_post_wcda_export_attributes_excel', array($this, 'handle_export_excel_direct'));
        add_action('admin_post_wcda_export_attributes_json', array($this, 'handle_export_json_direct'));
        
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
            '1.2.0'
        );
        
        wp_enqueue_script(
            'wcda-attributes-manager',
            plugin_dir_url(__FILE__) . 'attributes-manager.js',
            array('jquery'),
            '1.2.0',
            true
        );
        
        // Формируем URL для экспорта
        $export_excel_url = add_query_arg(
            array(
                'action' => 'wcda_export_attributes_excel',
                'nonce' => wp_create_nonce('wcda_export_nonce')
            ),
            admin_url('admin-post.php')
        );
        
        $export_json_url = add_query_arg(
            array(
                'action' => 'wcda_export_attributes_json',
                'nonce' => wp_create_nonce('wcda_export_nonce')
            ),
            admin_url('admin-post.php')
        );
        
        wp_localize_script('wcda-attributes-manager', 'wcda_attr', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'export_excel_url' => $export_excel_url,
            'export_json_url' => $export_json_url,
            'nonce' => wp_create_nonce('wcda_attr_nonce'),
            'strings' => array(
                'confirm_delete' => __('Вы уверены, что хотите удалить эту запись?', 'wc-description-analyzer'),
                'confirm_bulk_delete' => __('Вы уверены, что хотите удалить выбранные записи?', 'wc-description-analyzer'),
                'confirm_sync' => __('Синхронизация проверит все изображения в медиатеке и обновит таблицу. Продолжить?', 'wc-description-analyzer'),
                'delete_error' => __('Ошибка при удалении', 'wc-description-analyzer'),
                'update_success' => __('Запись успешно обновлена', 'wc-description-analyzer'),
                'update_error' => __('Ошибка при обновлении', 'wc-description-analyzer'),
                'sync_success' => __('Синхронизация завершена', 'wc-description-analyzer'),
                'sync_error' => __('Ошибка при синхронизации', 'wc-description-analyzer'),
                'export_started' => __('Начинается скачивание файла...', 'wc-description-analyzer')
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
                        <option value="export-excel"><?php _e('Экспорт выбранных в Excel', 'wc-description-analyzer'); ?></option>
                        <option value="export-json"><?php _e('Экспорт выбранных в JSON', 'wc-description-analyzer'); ?></option>
                    </select>
                    <button type="button" class="button" id="wcda-apply-bulk"><?php _e('Применить', 'wc-description-analyzer'); ?></button>
                </div>
                <div class="wcda-attr-search">
                    <input type="text" id="wcda-attr-search" placeholder="<?php _e('Поиск...', 'wc-description-analyzer'); ?>">
                </div>
                <div class="wcda-attr-single-actions">
                    <button type="button" class="button button-primary" id="wcda-sync-attributes">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Синхронизировать', 'wc-description-analyzer'); ?>
                    </button>
                    <button type="button" class="button" id="wcda-export-excel">
                        <span class="dashicons dashicons-download"></span>
                        <?php _e('Экспорт в Excel', 'wc-description-analyzer'); ?>
                    </button>
                    <button type="button" class="button" id="wcda-export-json">
                        <span class="dashicons dashicons-media-code"></span>
                        <?php _e('Экспорт JSON', 'wc-description-analyzer'); ?>
                    </button>
                    <button type="button" class="button" id="wcda-import-json-btn">
                        <span class="dashicons dashicons-upload"></span>
                        <?php _e('Импорт JSON', 'wc-description-analyzer'); ?>
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
                            <th width="80"><?php _e('Действия', 'wc-description-analyzer'); ?></th>
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
            
            <!-- Модальное окно импорта JSON -->
            <div id="wcda-import-modal" class="wcda-attr-modal" style="display: none;">
                <div class="wcda-attr-modal-content">
                    <div class="wcda-attr-modal-header">
                        <h2><?php _e('Импорт из JSON', 'wc-description-analyzer'); ?></h2>
                        <span class="wcda-attr-modal-close">&times;</span>
                    </div>
                    <div class="wcda-attr-modal-body">
                        <form id="wcda-import-form">
                            <div class="wcda-attr-form-field">
                                <label for="wcda-json-file"><?php _e('Выберите JSON файл', 'wc-description-analyzer'); ?></label>
                                <input type="file" id="wcda-json-file" accept=".json">
                                <p class="description"><?php _e('Файл должен быть в формате JSON, экспортированном из этого модуля', 'wc-description-analyzer'); ?></p>
                            </div>
                        </form>
                    </div>
                    <div class="wcda-attr-modal-footer">
                        <button type="button" class="button button-primary" id="wcda-confirm-import"><?php _e('Импортировать', 'wc-description-analyzer'); ?></button>
                        <button type="button" class="button" id="wcda-cancel-import"><?php _e('Отмена', 'wc-description-analyzer'); ?></button>
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
        $params = array();
        if (!empty($search)) {
            $where = " WHERE image_url LIKE %s OR attribute_name LIKE %s OR attribute_slug LIKE %s";
            $search_param = "%{$search}%";
            $params = array($search_param, $search_param, $search_param);
        }
        
        if (!empty($params)) {
            $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name}{$where}", $params));
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name}{$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                array_merge($params, array($per_page, $offset))
            ));
        } else {
            $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ));
        }
        
        // Добавляем URL превью
        foreach ($items as &$item) {
            $item->preview_url = $this->get_preview_url($item->attachment_id, $item->image_url);
            $item->edit_link = get_edit_post_link($item->attachment_id);
        }
        
        wp_send_json_success(array(
            'items' => $items,
            'total' => $total,
            'total_pages' => ceil($total / $per_page),
            'current_page' => $page,
            'stats' => $this->get_table_stats()
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
     * AJAX: Обновление атрибута изображения (поддержка отдельных полей)
     */
    public function ajax_update_image_attribute() {
        check_ajax_referer('wcda_attr_nonce', 'nonce');
        
        $id = intval($_POST['id']);
        $field = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
        $value = isset($_POST['value']) ? sanitize_text_field($_POST['value']) : '';
        
        $allowed_fields = array('attribute_name', 'attribute_slug');
        
        if (!in_array($field, $allowed_fields)) {
            wp_send_json_error(array('message' => __('Недопустимое поле', 'wc-description-analyzer')));
        }
        
        global $wpdb;
        
        // Для слага - дополнительная санитизация
        if ($field === 'attribute_slug') {
            $value = sanitize_title($value);
        }
        
        $update_data = array(
            $field => $value,
            'updated_at' => current_time('mysql')
        );
        
        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $id)
        );
        
        if ($result !== false) {
            // Если обновляем название и есть attachment_id, обновляем название в медиатеке
            if ($field === 'attribute_name') {
                $attachment_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT attachment_id FROM {$this->table_name} WHERE id = %d",
                    $id
                ));
                
                if ($attachment_id && $value) {
                    wp_update_post(array(
                        'ID' => $attachment_id,
                        'post_title' => $value
                    ));
                }
            }
            
            wp_send_json_success(array(
                'message' => __('Запись обновлена', 'wc-description-analyzer'),
                'stats' => $this->get_table_stats()
            ));
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
     * Прямая отдача Excel (CSV) файла
     */
    public function handle_export_excel_direct() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'wcda_export_nonce')) {
            wp_die(__('Ошибка безопасности.', 'wc-description-analyzer'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('У вас нет прав для экспорта данных.', 'wc-description-analyzer'));
        }
        
        $ids = isset($_GET['ids']) ? array_map('intval', explode(',', $_GET['ids'])) : array();
        
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
            wp_die(__('Нет данных для экспорта.', 'wc-description-analyzer'));
        }
        
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $filename = 'wcda_image_attributes_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fprintf($output, "\xEF\xBB\xBF");
        
        $headers = array(
            'ID',
            'URL изображения',
            'Название атрибута',
            'Слаг атрибута',
            'ID вложения',
            'Кол-во использований',
            'Дата создания',
            'Дата обновления'
        );
        fputcsv($output, $headers, ';', '"');
        
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
            fputcsv($output, $row, ';', '"');
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Прямая отдача JSON файла
     */
    public function handle_export_json_direct() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'wcda_export_nonce')) {
            wp_die(__('Ошибка безопасности.', 'wc-description-analyzer'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('У вас нет прав для экспорта данных.', 'wc-description-analyzer'));
        }
        
        $ids = isset($_GET['ids']) ? array_map('intval', explode(',', $_GET['ids'])) : array();
        
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
            wp_die(__('Нет данных для экспорта.', 'wc-description-analyzer'));
        }
        
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $filename = 'wcda_image_attributes_' . date('Y-m-d_H-i-s') . '.json';
        
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo json_encode(array(
            'export_date' => current_time('mysql'),
            'total_items' => count($items),
            'items' => $items
        ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        exit;
    }
    
    /**
     * AJAX: Импорт из JSON
     */
    public function ajax_import_attributes_json() {
        check_ajax_referer('wcda_attr_nonce', 'nonce');
        
        if (!isset($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => __('Ошибка загрузки файла', 'wc-description-analyzer')));
        }
        
        $file_content = file_get_contents($_FILES['json_file']['tmp_name']);
        $data = json_decode($file_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array('message' => __('Неверный формат JSON', 'wc-description-analyzer')));
        }
        
        // Поддержка двух форматов: прямой массив или объект с ключом 'items'
        $items = isset($data['items']) ? $data['items'] : $data;
        
        if (empty($items) || !is_array($items)) {
            wp_send_json_error(array('message' => __('Нет данных для импорта', 'wc-description-analyzer')));
        }
        
        global $wpdb;
        $imported = 0;
        $updated = 0;
        $errors = 0;
        
        foreach ($items as $item) {
            if (!isset($item['image_url']) || empty($item['image_url'])) {
                $errors++;
                continue;
            }
            
            // Проверяем, существует ли запись с таким URL
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE image_url = %s",
                $item['image_url']
            ));
            
            $attribute_name = isset($item['attribute_name']) ? sanitize_text_field($item['attribute_name']) : '';
            $attribute_slug = isset($item['attribute_slug']) ? sanitize_title($item['attribute_slug']) : '';
            $attachment_id = isset($item['attachment_id']) ? intval($item['attachment_id']) : 0;
            $use_count = isset($item['use_count']) ? intval($item['use_count']) : 0;
            
            if ($existing) {
                // Обновляем существующую запись
                $result = $wpdb->update(
                    $this->table_name,
                    array(
                        'attribute_name' => $attribute_name,
                        'attribute_slug' => $attribute_slug,
                        'attachment_id' => $attachment_id,
                        'use_count' => $use_count,
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $existing)
                );
                if ($result !== false) {
                    $updated++;
                } else {
                    $errors++;
                }
            } else {
                // Добавляем новую запись
                $result = $wpdb->insert(
                    $this->table_name,
                    array(
                        'image_url' => $item['image_url'],
                        'attribute_name' => $attribute_name,
                        'attribute_slug' => $attribute_slug,
                        'attachment_id' => $attachment_id,
                        'use_count' => $use_count,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    )
                );
                if ($result) {
                    $imported++;
                } else {
                    $errors++;
                }
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Импорт завершен: добавлено %d, обновлено %d, ошибок %d', 'wc-description-analyzer'),
                $imported, $updated, $errors
            ),
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors
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
                __('Синхронизация завершена. Обновлено: %d', 'wc-description-analyzer'),
                $results['updated']
            ),
            'results' => $results
        ));
    }
    
    /**
     * Поиск вложения по URL
     */
    private function find_attachment_by_url($url) {
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