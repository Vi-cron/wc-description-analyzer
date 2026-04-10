<?php
/**
 * Универсальный парсер: извлекает ВСЕ группы (произвольный заголовок + идущие за ним изображения)
 * и ВСЕ пары "ключ: значение" (не только предопределённые размеры).
 * Возвращает также модифицированную строку, где найденные блоки заменены на шорткоды.
 *
 * @param string $html Входной HTML/текст
 * @return array ['properties' => [...], 'groups' => [...], 'modified_html' => '...']
 */

function parseProductDescriptionUniversal(string $html): array
{
    // ------------------------------------------------------------
    // 0. Определяем позиции всех HTML-тегов
    // ------------------------------------------------------------
    $tagRanges = [];
    preg_match_all('/<[^>]*>/', $html, $tagMatches, PREG_OFFSET_CAPTURE);
    foreach ($tagMatches[0] as $match) {
        $tagRanges[] = [
            'start' => $match[1],
            'end' => $match[1] + strlen($match[0])
        ];
    }

    // ------------------------------------------------------------
    // 1. Сбор всех изображений – сохраняем полный src
    // ------------------------------------------------------------
    $images = [];
    preg_match_all('/<img[^>]+src=["\']([^"\']+\.(?:jpg|png|gif|webp))["\'][^>]*>/i', $html, $imgMatches, PREG_OFFSET_CAPTURE);
    foreach ($imgMatches[0] as $idx => $fullTagMatch) {
        $offset = $fullTagMatch[1];
        $fullTag = $fullTagMatch[0];
        $src = $imgMatches[1][$idx][0];
        $images[$offset] = [
            'tag' => $fullTag,
            'length' => strlen($fullTag),
            'src' => $src
        ];
    }

    // ------------------------------------------------------------
    // 2. Сбор заголовков групп (h1..h6 и строки с двоеточием)
    // ------------------------------------------------------------
    $headers = [];
    $colonCandidates = [];

    // 2.1 HTML-заголовки
    preg_match_all('/<(h[1-6])[^>]*>(.*?)<\/\1>/is', $html, $hMatches, PREG_OFFSET_CAPTURE);
    foreach ($hMatches[0] as $idx => $fullMatch) {
        $offset = $fullMatch[1];
        $fullTag = $fullMatch[0];
        $title = trim(strip_tags($hMatches[2][$idx][0]));
        $title = preg_replace('/:\s*$/', '', $title);
        if ($title !== '') {
            $headers[$offset] = [
                'title' => $title,
                'length' => strlen($fullTag),
                'full' => $fullTag
            ];
        }
    }

    // 2.2 Строки с двоеточием (пропускаем теги)
    preg_match_all('/([^<>:\n\r]+?)\s*:/', $html, $colonMatches, PREG_OFFSET_CAPTURE);
    foreach ($colonMatches[1] as $idx => $keyMatch) {
        $offset = $keyMatch[1];
        $key = trim($keyMatch[0]);
        $fullMatchStr = $key . ':';

        if ($key === '' || strpos($key, '<') !== false) {
            continue;
        }

        $insideTag = false;
        foreach ($tagRanges as $tagRange) {
            if ($offset >= $tagRange['start'] && $offset < $tagRange['end']) {
                $insideTag = true;
                break;
            }
        }
        if ($insideTag) {
            continue;
        }

        $colonCandidates[] = [
            'offset' => $offset,
            'length' => strlen($fullMatchStr),
            'title' => $key,
            'full' => $fullMatchStr
        ];

        $headers[$offset] = [
            'title' => $key,
            'length' => strlen($fullMatchStr),
            'full' => $fullMatchStr
        ];
    }

    ksort($headers);

    // ------------------------------------------------------------
    // 3. Группировка изображений по заголовкам – используем полный src
    // ------------------------------------------------------------
    $groups = [];
    $groupRanges = [];
    $headerOffsets = array_keys($headers);
    $headerCount = count($headerOffsets);
    $imageOffsets = array_keys($images);
    $imgIndex = 0;

    foreach ($headerOffsets as $idx => $hOffset) {
        $title = $headers[$hOffset]['title'];
        $nextHeaderOffset = ($idx + 1 < $headerCount) ? $headerOffsets[$idx + 1] : PHP_INT_MAX;

        $groupImageSources = [];
        $groupImageEnd = $hOffset + $headers[$hOffset]['length'];

        while ($imgIndex < count($imageOffsets) && $imageOffsets[$imgIndex] < $nextHeaderOffset) {
            if ($imageOffsets[$imgIndex] > $hOffset) {
                $imgOffset = $imageOffsets[$imgIndex];
                $groupImageSources[] = $images[$imgOffset]['src'];
                $imgEnd = $imgOffset + $images[$imgOffset]['length'];
                if ($imgEnd > $groupImageEnd)
                    $groupImageEnd = $imgEnd;
            }
            $imgIndex++;
        }

        if (!empty($groupImageSources)) {
            $groups[$title] = $groupImageSources;
            $groupRanges[] = [
                'start' => $hOffset,
                'end' => $groupImageEnd
            ];
        }
    }

    // ------------------------------------------------------------
    // 4. Извлечение свойств "ключ: значение"
    // ------------------------------------------------------------
    $groupTitles = array_keys($groups);
    $properties = [];
    $propertyMatches = [];

    foreach ($colonCandidates as $candidate) {
        $title = $candidate['title'];
        if (!in_array($title, $groupTitles)) {
            $startPos = $candidate['offset'] + $candidate['length'];
            $endPos = strpos($html, "\n", $startPos);
            if ($endPos === false) {
                $endPos = strlen($html);
            }
            $nextTag = strpos($html, '<', $startPos);
            if ($nextTag !== false && $nextTag < $endPos) {
                $endPos = $nextTag;
            }
            $valuePart = substr($html, $startPos, $endPos - $startPos);
            $valuePart = trim($valuePart, " \t\n\r\0\x0B");
            if ($valuePart === '') {
                continue;
            }
            if (is_numeric($valuePart) && strpos($valuePart, '.') === false) {
                $valuePart = (int) $valuePart;
            }
            $properties[$title] = $valuePart;
            $propertyMatches[] = [
                'start' => $candidate['offset'],
                'end' => $endPos
            ];
        }
    }

    // ------------------------------------------------------------
    // 5. Единые диапазоны для замен 
    // ------------------------------------------------------------
    $combinedGroupRange = null;
    if (!empty($groupRanges)) {
        $minStart = min(array_column($groupRanges, 'start'));
        $maxEnd = max(array_column($groupRanges, 'end'));
        $combinedGroupRange = ['start' => $minStart, 'end' => $maxEnd];
    }

    $combinedDimRange = null;
    if (!empty($propertyMatches)) {
        $minStart = min(array_column($propertyMatches, 'start'));
        $maxEnd = max(array_column($propertyMatches, 'end'));
        $combinedDimRange = ['start' => $minStart, 'end' => $maxEnd];
    }

    // ------------------------------------------------------------
    // 6. Формирование шорткодов
    // ------------------------------------------------------------
    $dimShortcode = '';
    if (!empty($properties)) {
        $paramStr = implode(',', array_keys($properties));
        $dimShortcode = "[wcda_specs specs=\"{$paramStr}\"]";
    }

    $groupShortcode = '';
    if (!empty($groups)) {
        $groupNames = array_keys($groups);
        $attrStr = implode(', ', $groupNames);
        $groupShortcode = "[wcda_groups groups=\"{$attrStr}\"]";
    }

    // ------------------------------------------------------------
    // 7. Построение модифицированной строки
    // ------------------------------------------------------------
    $modifiedHtml = $html;
    if ($combinedDimRange || $combinedGroupRange) {
        $replacements = [];
        if ($combinedDimRange) {
            $replacements[] = [
                'start' => $combinedDimRange['start'],
                'end' => $combinedDimRange['end'],
                'code' => $dimShortcode
            ];
        }
        if ($combinedGroupRange) {
            $replacements[] = [
                'start' => $combinedGroupRange['start'],
                'end' => $combinedGroupRange['end'],
                'code' => $groupShortcode
            ];
        }
        usort($replacements, fn($a, $b) => $a['start'] <=> $b['start']);

        $result = '';
        $lastPos = 0;
        foreach ($replacements as $rep) {
            $result .= substr($html, $lastPos, $rep['start'] - $lastPos);
            $result .= $rep['code'];
            $lastPos = $rep['end'];
        }
        $result .= substr($html, $lastPos);
        $modifiedHtml = $result;
    }

    return [
        'properties' => $properties,
        'groups' => $groups,
        'modified_html' => $modifiedHtml
    ];
}

/**
 * Функция для присвоения глобального атрибута товару
 */
function assign_global_attribute_to_product( $product_id, $attribute_name, $terms ) {

    // 1. Создаем глобальный атрибут, если он не существует.
    $taxonomy_slag = wcda_sanitize_attribute_slug( $attribute_name );
    $taxonomy_name = 'pa_' . $taxonomy_slag;
    if ( ! taxonomy_exists( $taxonomy_name ) ) {
        // Регистрируем атрибут
        $args = array(
            'public'      => true,
            'hierarchical' => false,
            'rewrite'     => false,
            'query_var'   => true,
        );
        register_taxonomy( $taxonomy_name, array( 'product' ), $args );
        
        // Сохраняем информацию об атрибуте в таблицу WooCommerce
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'woocommerce_attribute_taxonomies',
            array(
                'attribute_name'    => $taxonomy_slag,
                'attribute_label'   => mb_ucfirst( $attribute_name ),
                'attribute_type'    => 'select',
                'attribute_orderby' => 'menu_order',
                'attribute_public'  => 1,
            )
        );
        
        // Обновляем кэш
        delete_transient( 'wc_attribute_taxonomies' );
    }

    // 2. Добавляем значения (термины)
    $term_ids = array();
    foreach ( $terms as $term_value ) {
		
		$term_name = mb_ucfirst( $term_value['name'] );
        if (empty($term_value['slag'])) $term_value['slag']=wcda_sanitize_attribute_slug( $term_name );
        $term = get_term_by( 'name', $term_name, $taxonomy_name );
        
        if ( ! $term ) {
            $term_data = wp_insert_term( $term_name, $taxonomy_name, array( 'slug' => $term_value['slag']  ) );
				if ( ! is_wp_error( $term_data ) ) $term_id = (int) $term_data['term_id'];
			} else $term_id = (int) $term->term_id;
		$term_ids[]=$term_id;
        
        if (!empty($term_value['image']))
		    update_term_meta($term_id, '_wcda_attribute_image_id', $term_value['image']);
    }
	
	// Получаем уже существующие
	$existing_terms = wp_get_object_terms( $product_id, $taxonomy_name, array( 'fields' => 'ids' ) );
	$term_ids = array_unique( array_merge( $existing_terms, $term_ids ) );
	
    // 3. Назначаем термины товару
    wp_set_object_terms( $product_id, $term_ids, $taxonomy_name );

    // 4. Обновляем мета-поле _product_attributes
    $product_attributes = get_post_meta( $product_id, '_product_attributes', true );
    if ( ! is_array( $product_attributes ) ) {
        $product_attributes = array();
    }

    $new_attribute_data = array(
        'name'         => $taxonomy_name,
        'value'        => '',
        'position'     => count( $product_attributes ),
        'is_visible'   => 1,
        'is_variation' => 0,
        'is_taxonomy'  => 1,
    );
    
    $product_attributes[ $taxonomy_name ] = $new_attribute_data;
    update_post_meta( $product_id, '_product_attributes', $product_attributes );
    
    return true;
}


/**
 * AJAX обработчик завершения импорта
 */
function wcda_enhanced_finish_import()
{
    // Проверка nonce и прав доступа 
    check_ajax_referer('wcda_ajax_nonce', 'nonce');

    $product_id = intval($_POST['product_id']);

    //$gallery_ids = array_map('intval', $_POST['gallery_ids']);
    //$attributes_mapping = $_POST['attributes_mapping'];
    $use_shortcode = isset($_POST['use_shortcode']) && $_POST['use_shortcode'] === 'true';
    $remove_from_description = isset($_POST['remove_from_description']) && $_POST['remove_from_description'] === 'true';

    $product = wc_get_product($product_id);

    if (!$product) {
        wp_send_json_error('Товар не найден');
    }

    // 1. Получаем короткое описание продукта
    $short_description = $product->get_short_description();
    if (empty($short_description)) {
        $short_description = $product->get_description();
    }

    // 2. Запускаем универсальный парсер
    $parsed = parseProductDescriptionUniversal($short_description);
    $properties = $parsed['properties'] ?? [];
    $groups = $parsed['groups'] ?? [];
    $modified_html = $parsed['modified_html'] ?? $short_description;

    // 3. Обрабатываем свойства (ключ => значение)
    $length = null;
    $width  = null;
    $weight = null;
    $height = null;
    $other_properties = [];

    foreach ($properties as $key => $value) {
        $key_lower = mb_strtolower(trim($key));
        $numeric_value = wcda_extract_numeric($value);

        if (in_array($key_lower, ['длина', 'length'])) {
            $length = $numeric_value;
        } elseif (in_array($key_lower, ['ширина', 'width', 'глубина', 'depth'])) {
            $width = $numeric_value;
        } elseif (in_array($key_lower, ['высота', 'height'])) {
            $height = $numeric_value;
        } elseif (in_array($key_lower, ['вес', 'weight', 'масса'])) { 
            $weight = $numeric_value;
        } else {
            $other_properties[$key] = $value;
        }
    }

    // Устанавливаем параметры продукта (если получены)
    if ($length !== null) {
        $product->set_length($length);
    }
    if ($width !== null) {
        $product->set_width($width);
    }
    if ($height !== null) {
        $product->set_height($height);
    }
    if ($weight !== null) {
        $product->set_weight($weight);
    }

    // 4. Создаём глобальные атрибуты для остальных свойств
    foreach ($other_properties as $attr_name => $attr_value){
        $attr_value_slag = str_replace(['Х', 'х','X','x'], '-', $attr_value);
        assign_global_attribute_to_product( 
            $product_id, $attr_name, 
            Array(['name'=>$attr_value,'slag'=>wcda_sanitize_attribute_slug($attr_value_slag)])//
        );
    } 
    
    // 5. Обрабатываем группы изображений
    foreach ($groups as $group_name => $image_urls) {
        $globalAttributeImages = Array();
        foreach ($image_urls as $image_url) {
            // Ищем запись по image_url 
            $record = wcda_find_existing_in_table($image_url);
            if (!$record || !$record['attachment_id']) {
                continue;
            }
            $globalAttributeImages[] = Array (
                'name'  => $record['attribute_name'],
                'slag'  => $record['attribute_slug'],
                'image' => $record['attachment_id']
            );
        }
        assign_global_attribute_to_product($product_id, $group_name, $globalAttributeImages);
    }

    // 7. Обновляем короткое описание на очищенную версию (с шорткодами или без)
    $final_description=$short_description;
    if ($use_shortcode) $final_description=$modified_html;
    if ($remove_from_description) $final_description=preg_replace('/\[wcda_(specs|groups)[^\]]*\]/', '', $modified_html);
    
    $product->set_short_description($final_description);

    // 8. Сохраняем изменения
    $product->save();

    wp_send_json_success([
        'message' => 'Импорт завершён успешно',
        'properties_count' => count($properties),
        'groups_count' => count($groups)
    ]);
}

function log_data($data) {
    // Записываем весь POST-запрос в файл лога
    $log_data = [
        'time' => current_time('mysql'),
        'post_data' => $data,
        'files_data' => $_FILES,
        'server_data' => [
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
            'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? '',
            'REQUEST_URI' => $_SERVER['REQUEST_URI']
        ]
    ];
    
    $log_content = print_r($log_data, true);
    file_put_contents(WP_CONTENT_DIR . '/wcda-debug.log', $log_content . "\n\n---\n\n", FILE_APPEND);
    
    // Ваш остальной код...
}

/**
 * Регистрация AJAX обработчика finish_import
 * Эта функция должна быть вызвана из основного файла плагина
 */
function wcda_register_finish_import_ajax()
{
    add_action('wp_ajax_wcda_enhanced_finish_import', 'wcda_enhanced_finish_import');
}

// Вспомогательная функция: извлечение числа из строки
function wcda_extract_numeric($value)
{
    if (is_numeric($value)) {
        return floatval($value);
    }
    preg_match('/(\d+([.,]\d+)?)/', (string) $value, $matches);
    if (isset($matches[1])) {
        $number = str_replace(',', '.', $matches[1]);
        return floatval($number);
    }
    return null;
}

// Вспомогательная функция: формируем Заголовок
function mb_ucfirst($string, $encoding = 'UTF-8') {
    $firstChar = mb_substr($string, 0, 1, $encoding);
    $rest = mb_substr($string, 1, null, $encoding);
    return mb_strtoupper($firstChar, $encoding) . mb_strtolower($rest, $encoding);
}
