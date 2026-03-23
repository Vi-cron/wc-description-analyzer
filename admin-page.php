<?php
/**
 * Шаблон страницы администратора для WooCommerce Description Analyzer
 *
 * @package WC_Description_Analyzer
 */

// Защита от прямого доступа
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
        
        <!-- Дублирующиеся описания -->
        <div class="wcda-section">
            <h2><?php _e('Дублирующиеся описания', 'wc-description-analyzer'); ?></h2>
            <?php if (empty($duplicates)): ?>
                <p><?php _e('Дублирующихся описаний не найдено!', 'wc-description-analyzer'); ?></p>
            <?php else: ?>
                <p><?php printf(__('Найдено групп дубликатов: %d', 'wc-description-analyzer'), count($duplicates)); ?></p>
                <?php foreach ($duplicates as $hash => $data): ?>
                    <div class="wcda-duplicate-item">
                        <strong><?php _e('Описание:', 'wc-description-analyzer'); ?></strong>
                        <div style="background: #fff; padding: 10px; margin: 10px 0; border-left: 4px solid #dc3232;">
                            <?php echo wp_kses_post($data['description']); ?>
                        </div>
                        <strong><?php _e('Товары с этим описанием:', 'wc-description-analyzer'); ?></strong>
                        <div class="wcda-product-list">
                            <?php foreach ($data['products'] as $product): ?>
                                <div style="padding: 5px 0;">
                                    <a href="<?php echo esc_url($product['edit_link']); ?>" target="_blank" class="wcda-product-link">
                                        <?php echo esc_html($product['title']); ?>
                                    </a>
                                    <a href="<?php echo esc_url($product['permalink']); ?>" target="_blank" class="button button-small">
                                        <?php _e('Просмотр', 'wc-description-analyzer'); ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Повторяющиеся фразы -->
        <div class="wcda-section">
            <h2><?php _e('Повторяющиеся фразы (минимум 3 слова, встречаются 3+ раз)', 'wc-description-analyzer'); ?></h2>
            <?php if (empty($repeating_phrases)): ?>
                <p><?php _e('Повторяющихся фраз не найдено.', 'wc-description-analyzer'); ?></p>
            <?php else: ?>
                <?php foreach ($repeating_phrases as $phrase_data): ?>
                    <div class="wcda-phrase-item">
                        "<?php echo esc_html($phrase_data['phrase']); ?>" 
                        <strong>(<?php echo esc_html($phrase_data['count']); ?> <?php _e('раз', 'wc-description-analyzer'); ?>)</strong>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- HTML-теги в описаниях -->
        <div class="wcda-section">
            <h2><?php _e('HTML-теги в описаниях', 'wc-description-analyzer'); ?></h2>
            
            <div class="wcda-stats-grid">
                <div class="wcda-stat-card">
                    <h3><?php _e('Товаров с HTML', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-stat-number"><?php echo esc_html($html_stats['total_with_html']); ?></div>
                </div>
                <div class="wcda-stat-card">
                    <h3><?php _e('Уникальных img тегов', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-stat-number"><?php echo count($html_stats['img_tags']); ?></div>
                </div>
                <div class="wcda-stat-card">
                    <h3><?php _e('Уникальных ссылок (a теги)', 'wc-description-analyzer'); ?></h3>
                    <div class="wcda-stat-number"><?php echo count($html_stats['a_tags']); ?></div>
                </div>
            </div>
            
            <?php if (!empty($html_stats['products_with_html'])): ?>
                <h3><?php _e('Товары с HTML-вставками:', 'wc-description-analyzer'); ?></h3>
                <div class="wcda-product-list" style="max-height: 300px;">
                    <?php foreach ($html_stats['products_with_html'] as $product): ?>
                        <div style="padding: 5px 0; border-bottom: 1px solid #eee;">
                            <a href="<?php echo esc_url($product['edit_link']); ?>" target="_blank" style="text-decoration: none; font-weight: bold;">
                                <?php echo esc_html($product['title']); ?>
                            </a>
                            <span style="color: #666; margin-left: 10px;">
                                <?php _e('Теги:', 'wc-description-analyzer'); ?> 
                                <?php echo implode(', ', $product['tags']); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($html_stats['img_tags'])): ?>
                <h3><?php _e('Примеры найденных изображений:', 'wc-description-analyzer'); ?></h3>
                <?php 
                $sample_imgs = array_slice($html_stats['img_tags'], 0, 5);
                foreach ($sample_imgs as $img): 
                ?>
                    <div class="wcda-html-item">
                        <code><?php echo esc_html($img); ?></code>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Информация о сканировании -->
        <div class="wcda-section">
            <h2><?php _e('Действия', 'wc-description-analyzer'); ?></h2>
            <p><?php _e('Сканирование выполнено на основе текущих данных каталога. Для обновления статистики просто перезагрузите страницу.', 'wc-description-analyzer'); ?></p>
            <a href="<?php echo admin_url('admin.php?page=wc-description-analyzer'); ?>" class="button button-primary">
                <?php _e('Обновить анализ', 'wc-description-analyzer'); ?>
            </a>
            
            <button type="button" class="button" onclick="window.print()" style="margin-left: 10px;">
                <?php _e('Распечатать отчет', 'wc-description-analyzer'); ?>
            </button>
        </div>
    </div>
</div>