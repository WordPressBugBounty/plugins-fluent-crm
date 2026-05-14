<?php

namespace FluentCrm\App\Services;

class LegacyCoreBlockNormalizer
{
    const ENABLED_FILTER = 'fluent_crm/legacy_core_block_normalizer_enabled';

    private static $styleBlocks = [
        'core/heading',
        'core/paragraph',
        'core/group',
        'core/columns',
        'core/column',
        'core/buttons',
        'core/button',
        'core/image',
        'core/list',
        'core/quote'
    ];

    private static $neverNormalize = [
        'core/html',
        'core/freeform',
        'core/code',
        'core/preformatted'
    ];

    private static $legacyFontFamilyClassMap = [
        'has-georgia-times-times-new-roman-serif-font-family'                  => "Georgia, Times, 'Times New Roman', serif",
        'has-arial-helvetica-neue-helvetica-sans-serif-font-family'           => "Arial, 'Helvetica Neue', Helvetica, sans-serif",
        'has-times-new-roman-times-baskerville-georgia-serif-font-family'     => "'Times New Roman', Times, Baskerville, Georgia, serif",
        'has-courier-new-courier-lucida-sans-typewriter-monospace-font-family'=> "'Courier New', Courier, 'Lucida Sans Typewriter', monospace"
    ];

    private static $fontSizeFallbacks = [
        '13px'      => 'small',
        '0.8125rem' => 'small',
        '20px'      => 'medium',
        '1.25rem'   => 'medium',
        '36px'      => 'large',
        '2.25rem'   => 'large',
        '42px'      => 'x-large',
        '2.625rem'  => 'x-large'
    ];

    private static $legacySpacingAttributeMap = [
        'marginTop'     => ['spacing', 'margin', 'top'],
        'marginRight'   => ['spacing', 'margin', 'right'],
        'marginBottom'  => ['spacing', 'margin', 'bottom'],
        'marginLeft'    => ['spacing', 'margin', 'left'],
        'paddingTop'    => ['spacing', 'padding', 'top'],
        'paddingRight'  => ['spacing', 'padding', 'right'],
        'paddingBottom' => ['spacing', 'padding', 'bottom'],
        'paddingLeft'   => ['spacing', 'padding', 'left']
    ];

    /**
     * Temporary v2 -> v3 migration shim for legacy core block attributes.
     *
     * Disable with:
     * - define('FLUENTCRM_ENABLE_LEGACY_CORE_BLOCK_NORMALIZER', false)
     * - add_filter('fluent_crm/legacy_core_block_normalizer_enabled', '__return_false')
     *
     * @param string $content
     * @return string
     */
    public static function normalize($content)
    {
        if (!self::isEnabled()) {
            return is_string($content) ? $content : '';
        }

        if (!is_string($content) || $content === '') {
            return is_string($content) ? $content : '';
        }

        if (strpos($content, '<!-- wp:') === false || !preg_match('/(?:\sstyle\s*=|has-[a-z0-9-]+-(?:font-size|font-family)|has-text-align-)/i', $content)) {
            return $content;
        }

        if (!function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return $content;
        }

        try {
            $blocks = parse_blocks($content);
            $changed = false;
            $blocks = self::normalizeBlocks($blocks, $changed);

            if (!$changed) {
                return $content;
            }

            $normalized = serialize_blocks($blocks);
            return $normalized ?: $content;
        } catch (\Throwable $e) {
            return $content;
        }
    }

    /**
     * Whether the temporary legacy normalizer should run.
     *
     * This is intentionally centralized so the v2 -> v3 migration shim can be
     * bypassed during rollout or removed after the migration window.
     *
     * @return bool
     */
    public static function isEnabled()
    {
        $enabled = true;

        if (defined('FLUENTCRM_ENABLE_LEGACY_CORE_BLOCK_NORMALIZER')) {
            $enabled = (bool)FLUENTCRM_ENABLE_LEGACY_CORE_BLOCK_NORMALIZER;
        }

        return (bool)apply_filters(self::ENABLED_FILTER, $enabled);
    }

    private static function normalizeBlocks($blocks, &$changed)
    {
        if (!is_array($blocks)) {
            return $blocks;
        }

        foreach ($blocks as $index => $block) {
            $blocks[$index] = self::normalizeBlock($block, $changed);
        }

        return $blocks;
    }

    private static function normalizeBlock($block, &$changed)
    {
        if (!is_array($block)) {
            return $block;
        }

        $blockName = isset($block['blockName']) ? $block['blockName'] : '';
        if (!$blockName || in_array($blockName, self::$neverNormalize, true)) {
            return $block;
        }

        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            $block['innerBlocks'] = self::normalizeBlocks($block['innerBlocks'], $changed);
        }

        if (!in_array($blockName, self::$styleBlocks, true)) {
            return $block;
        }

        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $nextAttrs = self::normalizeAttributes($blockName, $attrs, isset($block['innerHTML']) ? $block['innerHTML'] : '');

        if ($nextAttrs !== $attrs) {
            $block['attrs'] = $nextAttrs;
            $changed = true;
        }

        return $block;
    }

    private static function normalizeAttributes($blockName, $attrs, $innerHtml)
    {
        $next = $attrs;
        self::applyLegacyAttributeMappings($next, $blockName);
        self::normalizeBlockAttributeValues($next, $blockName);

        $element = self::getFirstElement($innerHtml);
        if (!$element) {
            self::pruneEmptyStyleTree($next);
            return $next;
        }

        $classNames = self::getClassNames(isset($element['class']) ? $element['class'] : '');

        self::applyImageClassMappings($next, $blockName, $classNames);
        self::applyClassMappings($next, $classNames);
        self::applyInlineStyleMappings($next, isset($element['style']) ? $element['style'] : '', $classNames, $blockName);
        self::applyImageElementMappings($next, $blockName, $innerHtml);
        self::pruneEmptyStyleTree($next);

        return $next;
    }

    private static function getFirstElement($html)
    {
        if (!$html || !class_exists('\WP_HTML_Tag_Processor')) {
            return null;
        }

        $processor = new \WP_HTML_Tag_Processor($html);
        if (!$processor->next_tag()) {
            return null;
        }

        return [
            'tag'   => strtolower($processor->get_tag()),
            'class' => self::attributeToString($processor->get_attribute('class')),
            'style' => self::attributeToString($processor->get_attribute('style'))
        ];
    }

    private static function getImageElement($html)
    {
        if (!$html || !class_exists('\WP_HTML_Tag_Processor')) {
            return null;
        }

        $processor = new \WP_HTML_Tag_Processor($html);
        if (!$processor->next_tag(['tag_name' => 'img'])) {
            return null;
        }

        return [
            'class' => self::attributeToString($processor->get_attribute('class')),
            'style' => self::attributeToString($processor->get_attribute('style'))
        ];
    }

    private static function attributeToString($value)
    {
        return is_string($value) ? $value : '';
    }

    private static function applyLegacyAttributeMappings(&$attrs, $blockName)
    {
        foreach (self::$legacySpacingAttributeMap as $attributeKey => $path) {
            if (!array_key_exists($attributeKey, $attrs)) {
                continue;
            }

            $value = self::normalizeCssLengthValue($attrs[$attributeKey]);
            if ($value !== '') {
                self::setNestedStyleIfMissing($attrs, self::getLegacySpacingAttributePath($blockName, $attributeKey), $value);
            }

            unset($attrs[$attributeKey]);
        }
    }

    private static function getLegacySpacingAttributePath($blockName, $attributeKey)
    {
        if ($blockName === 'core/image' && strpos($attributeKey, 'padding') === 0) {
            return ['spacing', 'margin', self::$legacySpacingAttributeMap[$attributeKey][2]];
        }

        return self::$legacySpacingAttributeMap[$attributeKey];
    }

    private static function normalizeBlockAttributeValues(&$attrs, $blockName)
    {
        self::normalizeSpacingStyleValues($attrs);

        if ($blockName !== 'core/image') {
            return;
        }

        self::normalizeImageScalarAttribute($attrs, 'width');
        self::normalizeImageScalarAttribute($attrs, 'height');
        self::normalizeImageScalarAttribute($attrs, 'aspectRatio', false);
        self::normalizeImageIdAttribute($attrs);
        self::normalizeBorderRadiusValue($attrs);
    }

    private static function normalizeSpacingStyleValues(&$attrs)
    {
        if (empty($attrs['style']['spacing']) || !is_array($attrs['style']['spacing'])) {
            return;
        }

        foreach (['margin', 'padding'] as $spacingType) {
            if (empty($attrs['style']['spacing'][$spacingType]) || !is_array($attrs['style']['spacing'][$spacingType])) {
                continue;
            }

            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                if (!array_key_exists($side, $attrs['style']['spacing'][$spacingType])) {
                    continue;
                }

                $normalized = self::normalizeCssLengthValue($attrs['style']['spacing'][$spacingType][$side]);
                if ($normalized !== '') {
                    $attrs['style']['spacing'][$spacingType][$side] = $normalized;
                }
            }
        }
    }

    private static function normalizeImageScalarAttribute(&$attrs, $key, $appendPx = true)
    {
        if (!array_key_exists($key, $attrs)) {
            return;
        }

        $normalized = $appendPx ? self::normalizeCssLengthValue($attrs[$key]) : self::normalizeScalarStringValue($attrs[$key]);
        if ($normalized !== '') {
            $attrs[$key] = $normalized;
        }
    }

    private static function normalizeImageIdAttribute(&$attrs)
    {
        if (!isset($attrs['id']) || !is_string($attrs['id'])) {
            return;
        }

        $id = absint($attrs['id']);
        if ($id) {
            $attrs['id'] = $id;
        }
    }

    private static function normalizeBorderRadiusValue(&$attrs)
    {
        if (empty($attrs['style']['border']['radius'])) {
            return;
        }

        $normalized = self::normalizeCssLengthValue($attrs['style']['border']['radius']);
        if ($normalized !== '') {
            $attrs['style']['border']['radius'] = $normalized;
        }
    }

    private static function applyImageClassMappings(&$attrs, $blockName, $classNames)
    {
        if ($blockName !== 'core/image') {
            return;
        }

        foreach ($classNames as $className) {
            if (preg_match('/^align(left|center|right|wide|full)$/', $className, $match)) {
                self::setIfMissing($attrs, 'align', $match[1]);
            }

            if (preg_match('/^size-([a-z0-9-]+)$/i', $className, $match)) {
                self::setIfMissing($attrs, 'sizeSlug', $match[1]);
            }
        }

        $extraClassNames = [];
        foreach ($classNames as $className) {
            if (in_array($className, ['wp-block-image', 'is-resized', 'has-custom-border'], true)) {
                continue;
            }

            if (preg_match('/^align(left|center|right|wide|full)$/', $className) || preg_match('/^size-[a-z0-9-]+$/i', $className)) {
                continue;
            }

            $extraClassNames[] = $className;
        }

        if ($extraClassNames) {
            self::setIfMissing($attrs, 'className', implode(' ', $extraClassNames));
        }
    }

    private static function applyClassMappings(&$attrs, $classNames)
    {
        foreach ($classNames as $className) {
            if (strpos($className, 'has-text-align-') === 0) {
                self::setIfMissing($attrs, 'textAlign', str_replace('has-text-align-', '', $className));
                continue;
            }

            if (isset(self::$legacyFontFamilyClassMap[$className])) {
                self::addClassNameIfMissing($attrs, $className);
                self::setNestedStyleIfMissing($attrs, ['typography', 'fontFamily'], self::$legacyFontFamilyClassMap[$className]);
                continue;
            }

            if ($className === 'has-link-color') {
                self::addClassNameIfMissing($attrs, $className);
                continue;
            }

            if (preg_match('/^has-(.+)-font-size$/', $className, $match)) {
                self::setIfMissing($attrs, 'fontSize', $match[1]);
            }
        }
    }

    private static function applyInlineStyleMappings(&$attrs, $styleText, $classNames, $blockName)
    {
        $styles = self::parseStyleDeclarations($styleText);

        foreach ($styles as $property => $value) {
            switch ($property) {
                case 'color':
                    if (empty($attrs['textColor'])) {
                        self::setNestedStyleIfMissing($attrs, ['color', 'text'], $value);
                    }
                    break;

                case 'background-color':
                    if (empty($attrs['backgroundColor'])) {
                        self::setNestedStyleIfMissing($attrs, ['color', 'background'], $value);
                    }
                    break;

                case 'font-family':
                    self::applyFontFamilyStyle($attrs, $value, $classNames);
                    break;

                case 'font-size':
                    self::applyFontSizeStyle($attrs, $value);
                    break;

                case 'line-height':
                    self::setNestedStyleIfMissing($attrs, ['typography', 'lineHeight'], $value);
                    break;

                case 'margin-top':
                    self::setNestedStyleIfMissing($attrs, ['spacing', 'margin', 'top'], $value);
                    break;

                case 'margin-right':
                    self::setNestedStyleIfMissing($attrs, ['spacing', 'margin', 'right'], $value);
                    break;

                case 'margin-bottom':
                    self::setNestedStyleIfMissing($attrs, ['spacing', 'margin', 'bottom'], $value);
                    break;

                case 'margin-left':
                    self::setNestedStyleIfMissing($attrs, ['spacing', 'margin', 'left'], $value);
                    break;

                case 'padding-top':
                    self::setNestedStyleIfMissing($attrs, self::getSpacingPath($blockName, 'padding', 'top'), $value);
                    break;

                case 'padding-right':
                    self::setNestedStyleIfMissing($attrs, self::getSpacingPath($blockName, 'padding', 'right'), $value);
                    break;

                case 'padding-bottom':
                    self::setNestedStyleIfMissing($attrs, self::getSpacingPath($blockName, 'padding', 'bottom'), $value);
                    break;

                case 'padding-left':
                    self::setNestedStyleIfMissing($attrs, self::getSpacingPath($blockName, 'padding', 'left'), $value);
                    break;

                case 'text-align':
                    self::setIfMissing($attrs, 'textAlign', $value);
                    break;
            }
        }
    }

    private static function getSpacingPath($blockName, $spacingType, $side)
    {
        if ($blockName === 'core/image' && $spacingType === 'padding') {
            return ['spacing', 'margin', $side];
        }

        return ['spacing', $spacingType, $side];
    }

    private static function applyImageElementMappings(&$attrs, $blockName, $innerHtml)
    {
        if ($blockName !== 'core/image') {
            return;
        }

        $image = self::getImageElement($innerHtml);
        if (!$image) {
            return;
        }

        $imageStyles = self::parseStyleDeclarations(isset($image['style']) ? $image['style'] : '');
        if (!empty($imageStyles['border-radius'])) {
            self::setNestedStyleIfMissing($attrs, ['border', 'radius'], $imageStyles['border-radius']);
        }
        if (!empty($imageStyles['aspect-ratio'])) {
            self::setIfMissing($attrs, 'aspectRatio', $imageStyles['aspect-ratio']);
        }
        if (!empty($imageStyles['object-fit'])) {
            self::setIfMissing($attrs, 'scale', $imageStyles['object-fit']);
        }
        if (!empty($imageStyles['width'])) {
            self::setIfMissing($attrs, 'width', $imageStyles['width']);
        }
        if (!empty($imageStyles['height'])) {
            self::setIfMissing($attrs, 'height', $imageStyles['height']);
        }

        foreach (self::getClassNames(isset($image['class']) ? $image['class'] : '') as $className) {
            if (preg_match('/^wp-image-(\d+)$/', $className, $match)) {
                self::setIfMissing($attrs, 'id', absint($match[1]));
                return;
            }
        }
    }

    private static function applyFontFamilyStyle(&$attrs, $value, $classNames)
    {
        if (!empty($attrs['fontFamily'])) {
            return;
        }

        foreach ($classNames as $className) {
            if (isset(self::$legacyFontFamilyClassMap[$className])) {
                self::addClassNameIfMissing($attrs, $className);
                self::setNestedStyleIfMissing($attrs, ['typography', 'fontFamily'], $value ?: self::$legacyFontFamilyClassMap[$className]);
                return;
            }
        }

        self::setNestedStyleIfMissing($attrs, ['typography', 'fontFamily'], $value);
    }

    private static function applyFontSizeStyle(&$attrs, $value)
    {
        if (!empty($attrs['fontSize'])) {
            return;
        }

        $slug = self::findFontSizePresetSlug($value);
        if ($slug) {
            self::setIfMissing($attrs, 'fontSize', $slug);
            return;
        }

        self::setNestedStyleIfMissing($attrs, ['typography', 'fontSize'], $value);
    }

    private static function findFontSizePresetSlug($value)
    {
        $normalized = self::normalizeCssSize($value);
        return isset(self::$fontSizeFallbacks[$normalized]) ? self::$fontSizeFallbacks[$normalized] : '';
    }

    private static function parseStyleDeclarations($styleText)
    {
        if (!is_string($styleText) || $styleText === '') {
            return [];
        }

        $styles = [];
        foreach (explode(';', $styleText) as $declaration) {
            $separatorIndex = strpos($declaration, ':');
            if ($separatorIndex === false) {
                continue;
            }

            $property = strtolower(trim(substr($declaration, 0, $separatorIndex)));
            $value = trim(substr($declaration, $separatorIndex + 1));

            if ($property && $value !== '') {
                $styles[$property] = $value;
            }
        }

        return $styles;
    }

    private static function getClassNames($className)
    {
        if (!is_string($className) || $className === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', $className)));
    }

    private static function normalizeCssLengthValue($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return $value . 'px';
        }

        $normalized = trim((string)$value);
        if (preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            return $normalized . 'px';
        }

        return $normalized;
    }

    private static function normalizeScalarStringValue($value)
    {
        if ($value === null || $value === '') {
            return '';
        }

        return trim((string)$value);
    }

    private static function normalizeCssSize($value)
    {
        return preg_replace('/\s+/', '', strtolower(trim((string)$value)));
    }

    private static function setIfMissing(&$target, $key, $value)
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (!array_key_exists($key, $target) || $target[$key] === null || $target[$key] === '') {
            $target[$key] = $value;
            return true;
        }

        return false;
    }

    private static function addClassNameIfMissing(&$attrs, $className)
    {
        if (!$className) {
            return false;
        }

        $classNames = self::getClassNames(isset($attrs['className']) ? $attrs['className'] : '');
        if (in_array($className, $classNames, true)) {
            return false;
        }

        $classNames[] = $className;
        $attrs['className'] = implode(' ', $classNames);
        return true;
    }

    private static function setNestedStyleIfMissing(&$attrs, $path, $value)
    {
        if (!$path || $value === null || $value === '') {
            return false;
        }

        if (empty($attrs['style']) || !is_array($attrs['style'])) {
            $attrs['style'] = [];
        }

        $cursor = &$attrs['style'];
        $lastIndex = count($path) - 1;

        foreach ($path as $index => $part) {
            if ($index === $lastIndex) {
                if (!array_key_exists($part, $cursor) || $cursor[$part] === null || $cursor[$part] === '') {
                    $cursor[$part] = $value;
                    return true;
                }

                return false;
            }

            if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                $cursor[$part] = [];
            }

            $cursor = &$cursor[$part];
        }

        return false;
    }

    private static function pruneEmptyStyleTree(&$attrs)
    {
        if (empty($attrs['style']) || !is_array($attrs['style'])) {
            return;
        }

        self::pruneEmptyArray($attrs['style']);
        if (!$attrs['style']) {
            unset($attrs['style']);
        }
    }

    private static function pruneEmptyArray(&$value)
    {
        foreach ($value as $key => &$child) {
            if (!is_array($child)) {
                continue;
            }

            self::pruneEmptyArray($child);
            if (!$child) {
                unset($value[$key]);
            }
        }
    }
}
