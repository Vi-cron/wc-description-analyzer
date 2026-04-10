<?php
/**
 * Module: WooCommerce Attribute Image Manager (Simplified with Term Meta)
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Attribute_Image_Manager_Simple {
    
    private static $instance = null;
    private $meta_key = '_wcda_attribute_image_id';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', array($this, 'init'), 5);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Ключевое исправление: используем правильные хуки для WooCommerce атрибутов
        add_filter('manage_edit-pa_tramp_columns', array($this, 'add_attribute_column'), 20);
        add_filter('manage_edit-pa_color_columns', array($this, 'add_attribute_column'), 20);
        add_filter('manage_edit-pa_size_columns', array($this, 'add_attribute_column'), 20);
        
        // Универсальный фильтр для всех атрибутов
        add_filter('manage_edit-product_attributes_columns', array($this, 'add_attribute_column'), 20);
        
        // Добавляем содержимое колонки для всех таксономий pa_
        add_action('manage_pa_tramp_custom_column', array($this, 'render_attribute_column'), 10, 3);
        add_action('manage_pa_color_custom_column', array($this, 'render_attribute_column'), 10, 3);
        add_action('manage_pa_size_custom_column', array($this, 'render_attribute_column'), 10, 3);
        
        // Универсальный хук для всех атрибутов
        add_action('manage_product_attribute_custom_column', array($this, 'render_attribute_column'), 10, 3);
        
        // Добавляем колонку через JavaScript как запасной вариант
        add_action('admin_footer-edit-tags.php', array($this, 'add_column_js_fallback'));
        
        // Модифицируем формы термина
        add_action('pa_tramp_edit_form_fields', array($this, 'add_term_image_field'), 10, 2);
        add_action('pa_tramp_add_form_fields', array($this, 'add_term_image_field_simple'));
        add_action('pa_color_edit_form_fields', array($this, 'add_term_image_field'), 10, 2);
        add_action('pa_color_add_form_fields', array($this, 'add_term_image_field_simple'));
        add_action('pa_size_edit_form_fields', array($this, 'add_term_image_field'), 10, 2);
        add_action('pa_size_add_form_fields', array($this, 'add_term_image_field_simple'));
        
        // Универсальные хуки для всех атрибутов
        add_action('product_attribute_term_edit_form_fields', array($this, 'add_term_image_field'), 10, 2);
        add_action('product_attribute_term_add_form_fields', array($this, 'add_term_image_field_simple'));
        
        // Сохраняем мета-поле
        add_action('created_term', array($this, 'save_term_image'), 10, 3);
        add_action('edited_term', array($this, 'save_term_image'), 10, 3);
        
        // AJAX для быстрого сохранения
        add_action('wp_ajax_wcda_save_attribute_image_simple', array($this, 'ajax_save_attribute_image'));
        add_action('wp_ajax_wcda_remove_attribute_image_simple', array($this, 'ajax_remove_attribute_image'));
        add_action('wp_ajax_wcda_get_term_image', array($this, 'ajax_get_term_image'));
        
        // Добавляем стили
        add_action('admin_head', array($this, 'add_admin_styles'));
        
        // Добавляем колонку через JavaScript
        add_action('admin_head-edit-tags.php', array($this, 'add_column_via_js'));
    }
    
    public function init() {
        register_meta('term', $this->meta_key, array(
            'type' => 'integer',
            'description' => 'ID изображения для значения атрибута',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('manage_product_terms');
            }
        ));
    }
    
    public function get_term_image($term_id) {
        $image_id = (int) get_term_meta($term_id, $this->meta_key, true);
        
        if ($image_id) {
            $image = wp_get_attachment_image_src($image_id, 'thumbnail');
            if ($image) {
                return array(
                    'id' => $image_id,
                    'url' => $image[0]
                );
            }
        }
        
        return false;
    }
    
    public function add_attribute_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'cb') {
                $new_columns['attribute_image'] = __('Изображение', 'wc-description-analyzer');
            }
        }
        
        // Если колонка не добавилась, добавляем в начало
        if (!isset($new_columns['attribute_image'])) {
            $new_columns = array_slice($columns, 0, 1, true) + 
                          array('attribute_image' => __('Изображение', 'wc-description-analyzer')) + 
                          array_slice($columns, 1, null, true);
        }
        
        return $new_columns;
    }
    
    public function render_attribute_column($content, $column_name, $term_id) {
        if ($column_name !== 'attribute_image') {
            return $content;
        }
        
        $image = $this->get_term_image($term_id);
        $term = get_term($term_id);
        
        if ($image) {
            return sprintf(
                '<div class="wcda-attr-image-preview" data-term-id="%d" data-term-slug="%s" style="background-image:url(%s); cursor:pointer;" title="%s"></div>',
                esc_attr($term_id),
                esc_attr($term->slug),
                esc_url($image['url']),
                __('Изменить изображение', 'wc-description-analyzer')
            );
        }
        
        // Возвращаем кликабельную пустую заглушку
        return sprintf(
            '<div class="wcda-attr-image-preview wcda-empty-preview" data-term-id="%d" data-term-slug="%s" style="cursor:pointer;" title="%s"></div>',
            esc_attr($term_id),
            esc_attr($term->slug),
            __('Добавить изображение', 'wc-description-analyzer')
        );
    }
    
    public function add_column_js_fallback() {
        global $current_screen;
        
        if ($current_screen && strpos($current_screen->taxonomy, 'pa_') === 0) {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Проверяем, есть ли уже колонка с изображениями
                if ($('.column-attribute_image').length === 0) {
                    console.log('Adding column via JS fallback');
                    
                    // Добавляем заголовок колонки
                    $('thead tr').each(function() {
                        var $firstTh = $(this).find('th:first-child');
                        if ($firstTh.length && !$(this).find('.column-attribute_image').length) {
                            $('<th class="column-attribute_image" style="width:60px">Изображение</th>').insertAfter($firstTh);
                        }
                    });
                    
                    // Добавляем пустые ячейки в каждую строку
                    $('tbody tr').each(function() {
                        var $firstTd = $(this).find('td:first-child');
                        if ($firstTd.length && !$(this).find('.column-attribute_image').length) {
                            var termId = $(this).attr('id').replace('tag-', '');
                            $('<td class="column-attribute_image" data-colname="Изображение"><div class="wcda-attr-image-preview wcda-empty-preview" data-term-id="' + termId + '" style="cursor:pointer;"></div></td>').insertAfter($firstTd);
                        }
                    });
                }
            });
            </script>
            <?php
        }
    }
    
    public function add_column_via_js() {
        global $current_screen;
        
        if ($current_screen && strpos($current_screen->taxonomy, 'pa_') === 0) {
            ?>
            <style type="text/css">
                .column-attribute_image {
                    width: 60px;
                    text-align: center;
                }
            </style>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                setTimeout(function() {
                    // Загружаем изображения для всех терминов
                    var termIds = [];
                    $('.wcda-attr-image-preview[data-term-id]').each(function() {
                        var termId = $(this).data('term-id');
                        if (termId && !$(this).hasClass('loaded')) {
                            termIds.push(termId);
                        }
                    });
                    
                    if (termIds.length > 0) {
                        $.ajax({
                            url: wcda_attr_img.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'wcda_get_term_image',
                                nonce: wcda_attr_img.nonce,
                                term_ids: termIds
                            },
                            success: function(response) {
                                if (response.success && response.data.images) {
                                    for (var termId in response.data.images) {
                                        var imageUrl = response.data.images[termId];
                                        var $preview = $('.wcda-attr-image-preview[data-term-id="' + termId + '"]');
                                        if (imageUrl) {
                                            $preview.css('background-image', 'url(' + imageUrl + ')');
                                            $preview.removeClass('wcda-empty-preview');
                                        }
                                        $preview.addClass('loaded');
                                    }
                                }
                            }
                        });
                    }
                }, 500);
            });
            </script>
            <?php
        }
    }
    
    public function add_term_image_field($term, $taxonomy) {
        $image = $this->get_term_image($term->term_id);
        $image_url = $image ? $image['url'] : '';
        $image_id = $image ? $image['id'] : 0;
        ?>
        <tr class="form-field term-image-wrap">
            <th scope="row">
                <label for="wcda_attribute_image"><?php _e('Изображение атрибута', 'wc-description-analyzer'); ?></label>
            </th>
            <td>
                <div class="wcda-attribute-image-container">
                    <div class="wcda-image-preview-wrapper">
                        <div class="wcda-image-preview <?php echo !$image_url ? 'wcda-no-preview' : ''; ?>" style="<?php echo $image_url ? 'background-image:url(' . esc_url($image_url) . ');' : ''; ?>"></div>
                    </div>
                    <div class="wcda-image-buttons">
                        <input type="hidden" id="wcda_attribute_image_id" name="wcda_attribute_image_id" value="<?php echo esc_attr($image_id); ?>">
                        <button type="button" class="button wcda-upload-image-btn"><?php _e('Выбрать изображение', 'wc-description-analyzer'); ?></button>
                        <button type="button" class="button wcda-remove-image-btn" <?php echo !$image_id ? 'style="display:none;"' : ''; ?>><?php _e('Удалить', 'wc-description-analyzer'); ?></button>
                    </div>
                    <p class="description"><?php _e('Изображение будет отображаться рядом с названием атрибута.', 'wc-description-analyzer'); ?></p>
                </div>
            </td>
        </table>
        <?php
    }
    
    public function add_term_image_field_simple($taxonomy) {
        ?>
        <div class="form-field term-image-wrap">
            <label for="wcda_attribute_image"><?php _e('Изображение атрибута', 'wc-description-analyzer'); ?></label>
            <div class="wcda-attribute-image-container">
                <div class="wcda-image-preview-wrapper">
                    <div class="wcda-image-preview wcda-no-preview"></div>
                </div>
                <div class="wcda-image-buttons">
                    <input type="hidden" id="wcda_attribute_image_id" name="wcda_attribute_image_id" value="">
                    <button type="button" class="button wcda-upload-image-btn"><?php _e('Выбрать изображение', 'wc-description-analyzer'); ?></button>
                    <button type="button" class="button wcda-remove-image-btn" style="display:none;"><?php _e('Удалить', 'wc-description-analyzer'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function save_term_image($term_id, $tt_id, $taxonomy) {
        if (!current_user_can('manage_product_terms')) {
            return;
        }
        
        if (strpos($taxonomy, 'pa_') !== 0) {
            return;
        }
        
        if (isset($_POST['wcda_attribute_image_id'])) {
            $image_id = intval($_POST['wcda_attribute_image_id']);
            
            if ($image_id > 0) {
                update_term_meta($term_id, $this->meta_key, $image_id);
            } else {
                delete_term_meta($term_id, $this->meta_key);
            }
        }
    }
    
    public function ajax_get_term_image() {
        check_ajax_referer('wcda_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_product_terms')) {
            wp_send_json_error('Недостаточно прав');
        }
        
        $term_ids = isset($_POST['term_ids']) ? array_map('intval', (array)$_POST['term_ids']) : array();
        $images = array();
        
        foreach ($term_ids as $term_id) {
            $image = $this->get_term_image($term_id);
            if ($image) {
                $images[$term_id] = $image['url'];
            } else {
                $images[$term_id] = false;
            }
        }
        
        wp_send_json_success(array('images' => $images));
    }
    
    public function ajax_save_attribute_image() {
        check_ajax_referer('wcda_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_product_terms')) {
            wp_send_json_error('Недостаточно прав');
        }
        
        $term_id = intval($_POST['term_id']);
        $image_id = intval($_POST['image_id']);
        
        if (!$term_id || !$image_id) {
            wp_send_json_error('Неверные параметры');
        }
        
        update_term_meta($term_id, $this->meta_key, $image_id);
        
        $image = wp_get_attachment_image_src($image_id, 'thumbnail');
        
        wp_send_json_success(array(
            'message' => 'Изображение сохранено',
            'image_url' => $image ? $image[0] : ''
        ));
    }
    
    public function ajax_remove_attribute_image() {
        check_ajax_referer('wcda_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_product_terms')) {
            wp_send_json_error('Недостаточно прав');
        }
        
        $term_id = intval($_POST['term_id']);
        
        if (!$term_id) {
            wp_send_json_error('Неверные параметры');
        }
        
        delete_term_meta($term_id, $this->meta_key);
        
        wp_send_json_success(array(
            'message' => 'Изображение удалено'
        ));
    }
    
    public function enqueue_scripts($hook) {
        global $pagenow;
        
        $is_term_page = in_array($pagenow, array('edit-tags.php', 'term.php'));
        $is_attribute_taxonomy = isset($_GET['taxonomy']) && strpos($_GET['taxonomy'], 'pa_') === 0;
        
        if ($is_term_page && $is_attribute_taxonomy) {
            wp_enqueue_media();
            
            wp_enqueue_script(
                'wcda-attribute-image-simple',
                plugin_dir_url(__FILE__) . 'simple-image-attribute.js',
                array('jquery'),
                '1.0.1',
                true
            );
            
            wp_localize_script('wcda-attribute-image-simple', 'wcda_attr_img', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wcda_ajax_nonce'),
                'meta_key' => $this->meta_key
            ));
        }
    }
    
    public function add_admin_styles() {
        global $pagenow;
        
        $is_term_page = in_array($pagenow, array('edit-tags.php', 'term.php'));
        $is_attribute_taxonomy = isset($_GET['taxonomy']) && strpos($_GET['taxonomy'], 'pa_') === 0;
        
        if ($is_term_page && $is_attribute_taxonomy) {
            ?>
            <style type="text/css">
                .column-attribute_image {
                    width: 60px;
                    text-align: center;
                }
                
                .wcda-attr-image-preview {
                    width: 40px;
                    height: 40px;
                    background-size: cover;
                    background-position: center;
                    border-radius: 4px;
                    margin: 0 auto;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
                }
                
                .wcda-attr-image-preview.wcda-empty-preview {
                    background-color: #f0f0f0;
                    border: 1px dashed #ccc;
                    position: relative;
                }
                
                .wcda-attr-image-preview.wcda-empty-preview:hover {
                    background-color: #e0e0e0;
                    border-color: #999;
                }
                
                .wcda-attr-image-preview.wcda-empty-preview::after {
                    content: '+';
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    font-size: 20px;
                    color: #999;
                    font-weight: bold;
                }
                
                .wcda-attr-image-preview.wcda-empty-preview:hover::after {
                    color: #666;
                }
                
                .wcda-image-preview {
                    width: 80px;
                    height: 80px;
                    background-size: cover;
                    background-position: center;
                    background-color: #f1f1f1;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
                
                .wcda-image-preview.wcda-no-preview {
                    background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23ccc"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zM7 10h2v7H7zm4-3h2v10h-2zm4-3h2v13h-2z"/></svg>');
                    background-size: 40px;
                    background-repeat: no-repeat;
                    background-position: center;
                }
                
                .wcda-image-buttons {
                    margin-top: 10px;
                }
                
                .wcda-image-buttons .button {
                    margin-right: 5px;
                }
                
                .term-image-wrap th {
                    vertical-align: top;
                }
                
                .form-field .wcda-image-preview {
                    margin-bottom: 10px;
                }
            </style>
            <?php
        }
    }
}

// Инициализация модуля
function wcda_init_attribute_image_manager_simple() {
    return WC_Attribute_Image_Manager_Simple::get_instance();
}