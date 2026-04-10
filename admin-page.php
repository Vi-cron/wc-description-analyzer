<?php
/**
 * Шаблон страницы администратора для WooCommerce Description Analyzer
 *
 * @package WC_Description_Analyzer
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Анализ коротких описаний товаров WooCommerce', 'wc-description-analyzer'); ?></h1>
    
    <div class="wcda-container">
        <!-- Общая статистика -->
        <div class="wcda-section">
            <h2><?php _e('Общая статистика', 'wc-description-analyzer'); ?></h2>
            <div class="wcda-stats-grid">
                <div class="wcda-stat-card">
                    <h3><?php _e('Всего товаров', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-stat-number"><?php echo esc_html($data['total_products']); ?></div>
                </div>
                <div class="wcda-stat-card">
                    <h3><?php _e('С описанием', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-stat-number"><?php echo esc_html($data['total_with_description']); ?></div>
                    <div><?php printf(__('(%.1f%%)', 'wc-description-analyzer'), ($data['total_with_description'] / max(1, $data['total_products']) * 100)); ?></div>
                </div>
                <div class="wcda-stat-card">
                    <h3><?php _e('Без описания', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-stat-number"><?php echo esc_html($data['total_products'] - $data['total_with_description']); ?></div>
                    <div><?php printf(__('(%.1f%%)', 'wc-description-analyzer'), (($data['total_products'] - $data['total_with_description']) / max(1, $data['total_products']) * 100)); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Изображения в описаниях -->
		<div class="wcda-section">
		<h2><?php _e('Изображения в описаниях', 'wc-description-analyzer'); ?></h2>
		<p><?php printf(__('Найдено товаров с изображениями: %d', 'wc-description-analyzer'), count($html_stats['products_with_images'])); ?></p>
    
		<?php if (!empty($html_stats['products_with_images'])): ?>
        <div class="wcda-bulk-actions">
            <h3><?php _e('Массовые действия:', 'wc-description-analyzer'); ?></h3>
            
            <!-- НОВЫЕ ОПЦИИ ДЛЯ ИЗОБРАЖЕНИЙ -->
            <div class="wcda-action-options">
                <label>
                    <input type="checkbox" class="wcda-image-use-shortcode" value="1">
                    <?php _e('Заменить на шорткод', 'wc-description-analyzer'); ?>
                </label>
                <label>
                    <input type="checkbox" class="wcda-image-create-attributes" value="1">
                    <?php _e('Создать атрибуты из изображений', 'wc-description-analyzer'); ?>
                </label>
                <label>
                    <input type="checkbox" class="wcda-image-remove-from-desc" value="1">
                    <?php _e('Удалить значения из описания', 'wc-description-analyzer'); ?>
                </label>
            </div>
            
            <button type="button" class="button button-primary wcda-bulk-extract-images" data-action="images_to_gallery">
                <?php _e('Извлечь ВСЕ изображения в галерею', 'wc-description-analyzer'); ?>
            </button>
            <span class="spinner"></span>
            <div class="wcda-messages"></div>
        </div>
        
        <div class="wcda-product-list-with-checkboxes">
            <div class="wcda-select-all">
                <label>
                    <input type="checkbox" class="wcda-select-all-checkbox"> 
                    <?php _e('Выбрать все', 'wc-description-analyzer'); ?>
                </label>
            </div>
            
            <?php foreach ($html_stats['products_with_images'] as $product): ?>
                <div class="wcda-product-item" data-product-id="<?php echo $product['id']; ?>">
                    <input type="checkbox" class="wcda-product-checkbox" value="<?php echo $product['id']; ?>">
                    <a href="<?php echo esc_url($product['edit_link']); ?>" target="_blank" class="wcda-product-title">
                        <?php echo esc_html($product['title']); ?>
                    </a>
                    <span class="wcda-images-count">(<?php echo count($product['images']); ?> <?php _e('изображений', 'wc-description-analyzer'); ?>)</span>
                    <button type="button" class="button button-small wcda-extract-single-images" data-product-id="<?php echo $product['id']; ?>">
                        <?php _e('Извлечь в галерею', 'wc-description-analyzer'); ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
		<?php endif; ?>
		</div>
        
        <!-- Параметры и размеры -->
        <div class="wcda-section">
            <h2><?php _e('Параметры и размеры (Длина, Глубина, Высота)', 'wc-description-analyzer'); ?></h2>
            
            <?php if (!empty($parameter_patterns)): ?>
                <div class="wcda-bulk-actions">
                    <h3><?php _e('Массовые действия:', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-action-options">
                        <label>
                            <input type="checkbox" class="wcda-global-use-shortcode" value="1">
                            <?php _e('Заменить на шорткод [product_dimensions]', 'wc-description-analyzer'); ?>
                        </label>
                        <label>
                            <input type="checkbox" class="wcda-global-attach-to-product" value="1">
                            <?php _e('Привязать атрибуты к товару', 'wc-description-analyzer'); ?>
                        </label>
                    </div>
                    <button type="button" class="button button-primary wcda-bulk-extract-dimensions" data-action="dimensions_to_attributes">
                        <?php _e('Извлечь ВСЕ размеры в атрибуты', 'wc-description-analyzer'); ?>
                    </button>
                    <span class="spinner"></span>
                    <div class="wcda-messages"></div>
                </div>
                
                <?php foreach ($parameter_patterns as $index => $pattern): ?>
                    <div class="wcda-pattern-group" data-pattern-index="<?php echo $index; ?>">
                        <h3><?php _e('Шаблон', 'wc-description-analyzer'); ?> #<?php echo $index + 1; ?></h3>
                        <code><?php echo esc_html($pattern['pattern']); ?></code>
                        <p><?php printf(__('Используется в %d товарах', 'wc-description-analyzer'), $pattern['count']); ?></p>
                        
                        <div class="wcda-pattern-actions">
                            <label>
                                <input type="checkbox" class="wcda-pattern-use-shortcode" data-pattern="<?php echo $index; ?>" value="1">
                                <?php _e('Заменить на шорткод', 'wc-description-analyzer'); ?>
                            </label>
                            <label>
                                <input type="checkbox" class="wcda-pattern-attach-to-product" data-pattern="<?php echo $index; ?>" value="1">
                                <?php _e('Привязать к товару', 'wc-description-analyzer'); ?>
                            </label>
                            <button type="button" class="button button-secondary wcda-bulk-pattern-dimensions" data-pattern="<?php echo $index; ?>">
                                <?php _e('Извлечь размеры для этого шаблона', 'wc-description-analyzer'); ?>
                            </button>
                        </div>
                        
                        <div class="wcda-products-in-pattern">
                            <div class="wcda-select-pattern-all" data-pattern="<?php echo $index; ?>">
                                <label>
                                    <input type="checkbox" class="wcda-pattern-select-all" data-pattern="<?php echo $index; ?>">
                                    <?php _e('Выбрать все товары этого шаблона', 'wc-description-analyzer'); ?>
                                </label>
                            </div>
                            <?php foreach ($pattern['products'] as $product): ?>
                                <div class="wcda-product-item" data-product-id="<?php echo $product['id']; ?>">
                                    <input type="checkbox" class="wcda-product-checkbox wcda-product-checkbox-pattern-<?php echo $index; ?>" data-pattern="<?php echo $index; ?>" value="<?php echo $product['id']; ?>">
                                    <a href="<?php echo esc_url($product['edit_link']); ?>" target="_blank">
                                        <?php echo esc_html($product['title']); ?>
                                    </a>
                                    <span class="wcda-dimension-values">
                                        (<?php 
                                        $vals = array();
                                        foreach ($product['values'] as $name => $value) {
                                            $vals[] = $name . ': ' . $value;
                                        }
                                        echo implode(', ', $vals);
                                        ?>)
                                    </span>
                                    <button type="button" class="button button-small wcda-extract-single-dimensions" data-product-id="<?php echo $product['id']; ?>">
                                        <?php _e('Извлечь размеры', 'wc-description-analyzer'); ?>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?php _e('Параметры не найдены.', 'wc-description-analyzer'); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Кнопка обновления -->
        <div class="wcda-section">
            <a href="<?php echo admin_url('admin.php?page=wc-description-analyzer'); ?>" class="button button-primary">
                <?php _e('Обновить анализ', 'wc-description-analyzer'); ?>
            </a>
        </div>
    </div>
</div>