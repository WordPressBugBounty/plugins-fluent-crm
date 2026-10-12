<?php

namespace FluentCrm\App\Services;


use FluentCrm\App\Models\CustomCompanyField;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;
use FluentCrm\Framework\Support\Arr;

class Sanitize
{
    public static function campaign($data)
    {
        $fieldMaps = [
            'title'            => 'sanitize_text_field',
            'slug'             => 'sanitize_text_field',
            'template_id'      => 'intval',
            'email_subject'    => 'sanitize_text_field',
            'email_pre_header' => 'sanitize_text_field',
            'utm_status'       => 'intval',
            'utm_source'       => 'sanitize_text_field',
            'utm_medium'       => 'sanitize_text_field',
            'utm_campaign'     => 'sanitize_text_field',
            'utm_term'         => 'sanitize_text_field',
            'utm_content'      => 'sanitize_text_field',
            'scheduled_at'     => 'sanitize_text_field',
            'design_template'  => 'sanitize_text_field'
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        if (!empty($data['settings']) && is_array($data['settings'])) {
            $data['settings'] = self::sanitizeCampaignSettings($data['settings']);
        }

        return $data;
    }

    private static function sanitizeCampaignSettings($settings)
    {
        if (!is_array($settings)) {
            return [];
        }

        $footerSettings = Arr::get($settings, 'footer_settings');
        if (is_array($footerSettings)) {
            $settings['footer_settings'] = self::sanitizeFooterSettings($footerSettings);
        }

        return $settings;
    }

    private static function sanitizeFooterSettings($footerSettings)
    {
        $fontSize = intval(Arr::get($footerSettings, 'font_size', 13));
        $footerPadding = intval(Arr::get($footerSettings, 'footer_padding', 20));
        $fontColor = sanitize_hex_color(Arr::get($footerSettings, 'font_color', '#202020')) ?: '#202020';
        $backgroundColor = Arr::get($footerSettings, 'background_color', 'transparent');
        $backgroundColor = ($backgroundColor === 'transparent')
            ? 'transparent'
            : (sanitize_hex_color($backgroundColor) ?: 'transparent');

        return [
            'disable_footer'   => Arr::get($footerSettings, 'disable_footer') === 'yes' ? 'yes' : 'no',
            'custom_footer'    => Arr::get($footerSettings, 'custom_footer') === 'yes' ? 'yes' : 'no',
            'footer_content'   => self::sanitizeFooterHtml(Arr::get($footerSettings, 'footer_content', '')),
            'font_size'        => min(24, max(8, $fontSize)),
            'font_color'       => $fontColor,
            'background_color' => $backgroundColor,
            'footer_padding'   => min(80, max(0, $footerPadding))
        ];
    }

    public static function sanitizeFooterHtml($footerHtml)
    {
        if (!is_scalar($footerHtml)) {
            return '';
        }

        $footerHtml = (string) $footerHtml;
        if ($footerHtml === '') {
            return '';
        }

        $allowedTags = wp_kses_allowed_html('post');
        $styleAllowedTags = [
            'a', 'p', 'div', 'span', 'img', 'ul', 'ol', 'li',
            'strong', 'em', 'b', 'i', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th', 'blockquote'
        ];

        foreach ($styleAllowedTags as $tagName) {
            if (!isset($allowedTags[$tagName])) {
                $allowedTags[$tagName] = [];
            }
            $allowedTags[$tagName]['style'] = [];
        }

        if (isset($allowedTags['a'])) {
            $allowedTags['a']['target'] = [];
            $allowedTags['a']['rel'] = [];
        }

        if (isset($allowedTags['img'])) {
            $allowedTags['img']['loading'] = [];
            $allowedTags['img']['decoding'] = [];
        }

        return wp_kses($footerHtml, $allowedTags);
    }

    /**
     * Remove C0 control characters (including NUL) that WordPress's sanitizers let through.
     * Tab, newline and carriage return are kept. The pattern is not /u so invalid UTF-8 does not null the value.
     *
     * @param mixed $value Scalar or nested array; anything else is returned as-is.
     * @return mixed
     */
    private static function stripControlCharacters($value)
    {
        if (is_array($value)) {
            return array_map([__CLASS__, 'stripControlCharacters'], $value);
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // preg_replace() returns null on failure; never silently drop the value.
        return $stripped === null ? $value : $stripped;
    }

    /**
     * A registered contact status, or $default.
     * Strict, string-only and case-sensitive; surrounding spaces are ignored.
     *
     * @param mixed $value
     * @param string|null $default Returned when $value is not a registered status.
     * @return string|null
     */
    public static function contactStatus($value, $default = null)
    {
        if (!is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return in_array($value, fluentcrm_subscriber_statuses(), true) ? $value : $default;
    }

    /**
     * A registered contact type (a key of fluentcrm_contact_types()), or $default.
     * Strict like contactStatus(); a non-string never matches.
     *
     * @param mixed $value
     * @param string|null $default Returned when $value is not a registered type.
     * @return string|null
     */
    public static function contactType($value, $default = null)
    {
        if (!is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return in_array($value, array_map('strval', array_keys(fluentcrm_contact_types())), true) ? $value : $default;
    }

    public static function contact($data)
    {
        $fieldMaps = [
            'hash'            => 'sanitize_text_field',
            'prefix'          => 'sanitize_text_field',
            'first_name'      => 'sanitize_text_field',
            'last_name'       => 'sanitize_text_field',
            'full_name'       => 'sanitize_text_field',
            'user_id'         => 'intval',
            'email'           => 'sanitize_email',
            'status'          => 'sanitize_text_field',
            'contact_type'    => 'sanitize_text_field',
            'address_line_1'  => 'sanitize_text_field',
            'address_line_2'  => 'sanitize_text_field',
            'postal_code'     => 'sanitize_text_field',
            'city'            => 'sanitize_text_field',
            'state'           => 'sanitize_text_field',
            'country'         => 'sanitize_text_field',
            'phone'           => 'sanitize_text_field',
            'timezone'        => 'sanitize_text_field',
            'date_of_birth'   => 'sanitize_text_field',
            'source'          => 'sanitize_text_field',
            'life_time_value' => 'sanitize_text_field',
            'last_activity'   => 'sanitize_text_field',
            'total_points'    => 'intval',
            'latitude'        => 'sanitize_text_field',
            'longitude'       => 'sanitize_text_field',
            'ip'              => 'sanitize_text_field',
            'created_at'      => 'sanitize_text_field',
            'updated_at'      => 'sanitize_text_field',
            'avatar'          => 'esc_url_raw',
            'company_id'      => 'intval',
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        if (isset($data['status'])) {
            $status = self::contactStatus($data['status']);
            if ($status === null) {
                unset($data['status']);
            } else {
                $data['status'] = $status;
            }
        }

        if (isset($data['contact_type'])) {
            $type = self::contactType($data['contact_type']);
            if ($type === null) {
                unset($data['contact_type']);
            } else {
                $data['contact_type'] = $type;
            }
        }

        return $data;
    }

    /**
     * Sanitize custom contact field values before they are persisted.
     * The field's type picks the rule: textarea wp_kses_post, number numeric cast,
     * arrays sanitize_text_field per item, anything else (including unknown keys) text.
     *
     * @param array $values slug => value, as submitted
     * @param array $fields optional pre-loaded field definitions keyed by slug
     * @return array sanitized values, keys preserved
     */
    public static function contactCustomValues($values, $fields = [])
    {
        return self::customFieldValues($values, 'contact_custom_fields', $fields);
    }

    /**
     * Sanitize custom company field values before they are persisted.
     * Same rules as contactCustomValues(), with the company field definitions.
     *
     * @param array $values slug => value, as submitted
     * @param array $fields optional pre-loaded field definitions keyed by slug
     * @return array sanitized values, keys preserved
     */
    public static function companyCustomValues($values, $fields = [])
    {
        return self::customFieldValues($values, 'company_custom_fields', $fields);
    }

    /**
     * Shared implementation behind the contact and company custom-value sanitizers.
     *
     * @param array  $values     slug => value, as submitted
     * @param string $optionName fluentcrm option holding the field definitions
     * @param array  $fields     optional pre-loaded definitions keyed by slug
     * @return array
     */
    protected static function customFieldValues($values, $optionName, $fields = [])
    {
        if (!$values || !is_array($values)) {
            return $values;
        }

        if (!$fields) {
            foreach ((array)fluentcrm_get_option($optionName, []) as $field) {
                if (!empty($field['slug'])) {
                    $fields[$field['slug']] = $field;
                }
            }
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            $key = sanitize_text_field($key);
            $type = Arr::get($fields, $key . '.type', 'text');

            if (is_array($value)) {
                // checkbox and multi-select values are choice labels.
                $sanitized[$key] = array_values(array_map(function ($item) {
                    return is_scalar($item) ? sanitize_text_field($item) : '';
                }, $value));
                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                // Objects/resources have no valid representation in a meta value.
                $sanitized[$key] = '';
                continue;
            }

            $value = (string)$value;

            if ($type === 'textarea') {
                $sanitized[$key] = wp_kses_post($value);
                continue;
            }

            if ($type === 'number') {
                $number = trim($value);
                if ($number === '') {
                    $sanitized[$key] = ''; // explicit empty clears the stored value
                    continue;
                }
                // Accept thousands separators in strict 1,234,567 grouping; "1,5" stays invalid.
                if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $number)) {
                    $number = str_replace(',', '', $number);
                }
                // Skip non-numeric values; an empty string would delete the existing value.
                if (is_numeric($number)) {
                    $sanitized[$key] = $number + 0;
                }
                continue;
            }

            $sanitized[$key] = sanitize_text_field($value);
        }

        return self::stripControlCharacters($sanitized);
    }

    public static function contactNote($data)
    {
        $fieldMaps = [
            'subscriber_id' => 'intval',
            'parent_id'     => 'intval',
            'created_by'    => 'sanitize_text_field',
            'status'        => 'sanitize_text_field',
            'type'          => 'sanitize_text_field',
            'title'         => 'sanitize_text_field',
            'description'   => 'wp_kses_post',
            'created_at'    => 'sanitize_text_field'
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        return $data;
    }

    public static function funnel($data)
    {
        $fieldMaps = [
            'type'         => 'sanitize_text_field',
            'title'        => 'sanitize_text_field',
            'trigger_name' => 'sanitize_text_field',
            'status'       => 'sanitize_text_field',
            'created_by'   => 'intval',
            'updated_at'   => 'sanitize_text_field'
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        return $data;
    }

    public static function company($data)
    {
        $fieldMaps = [
            'name'             => 'sanitize_text_field',
            'description'      => 'wp_kses_post',
            'phone'            => 'sanitize_text_field',
            'email'            => 'sanitize_email',
            'owner_id'         => 'intval',
            'employees_number' => 'intval',
            'industry'         => 'sanitize_text_field',
            'type'             => 'sanitize_text_field',
            'address_line_1'   => 'sanitize_text_field',
            'address_line_2'   => 'sanitize_text_field',
            'postal_code'      => 'sanitize_text_field',
            'city'             => 'sanitize_text_field',
            'state'            => 'sanitize_text_field',
            'country'          => 'sanitize_text_field',
            'website'          => 'esc_url_raw',
            'linkedin_url'     => 'esc_url_raw',
            'facebook_url'     => 'esc_url_raw',
            'twitter_url'      => 'esc_url_raw',
            'logo'             => 'esc_url_raw',
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        if (isset($data['custom_values'])) {
            $customValues = Arr::get($data, 'custom_values', []);

            $customFieldKeys = [];
            $customFields = (new CustomCompanyField())->getGlobalFields()['fields'];

            foreach ($customFields as $field) {
                $customFieldKeys[] = $field['slug'];
            }

            if ($customFieldKeys) {
                if ($customValues) {
                    $customValues = (new CustomCompanyField)->formatCustomFieldValues($customValues);
                }
            }

            $data['custom_values'] = $customValues;
        }

        return $data;
    }

    /**
     * Keep only ids that exist in the given model's table. Confirmed ids are cached per request.
     *
     * @param string $modelClass Tag::class or Lists::class
     * @param int[]  $ids
     * @return int[]
     */
    private static function existingIds($modelClass, $ids)
    {
        static $known = [];

        if (!$ids) {
            return [];
        }

        if (!isset($known[$modelClass])) {
            $known[$modelClass] = [];
        }

        $unknown = array_values(array_diff(array_unique($ids), $known[$modelClass]));
        if ($unknown) {
            foreach ($modelClass::whereIn('id', $unknown)->pluck('id') as $id) {
                $known[$modelClass][] = (int)$id;
            }
        }

        return array_values(array_intersect($ids, $known[$modelClass]));
    }

    public static function sanitizeTagIds($inputTagIds, $willCreate = true)
    {
        if (!$inputTagIds) {
            return [];
        }

        $tagIds = [];
        $nonNumericIds = [];

        foreach ($inputTagIds as $tagId) {
            if (is_numeric($tagId)) {
                $tagIds[] = (int)$tagId;
            } else {
                $nonNumericIds[] = $tagId;
            }
        }

        $tagIds = self::existingIds(Tag::class, $tagIds);

        if (!$nonNumericIds) {
            return $tagIds;
        }

        foreach ($nonNumericIds as $maybeNewTag) {
            if (strlen($maybeNewTag) < 3) {
                continue;
            }

            $exit = Tag::where('title', $maybeNewTag)
                ->orWhere('slug', $maybeNewTag)
                ->first();

            if ($exit) {
                $tagIds[] = $exit->id;
                continue;
            }

            if (!$willCreate) {
                continue;
            }

            $tag = Tag::create([
                'title' => $maybeNewTag,
                'slug'  => sanitize_title($maybeNewTag)
            ]);

            $tagIds[] = $tag->id;
            do_action('fluent_crm/tag_created', $tag);
        }

        return $tagIds;
    }

    public static function sanitizeListIds($inputListIds, $willCreate = true)
    {
        if (!$inputListIds) {
            return [];
        }

        $listIds = [];
        $nonNumericIds = [];

        foreach ($inputListIds as $listId) {
            if (is_numeric($listId)) {
                $listIds[] = (int)$listId;
            } else {
                $nonNumericIds[] = $listId;
            }
        }

        $listIds = self::existingIds(Lists::class, $listIds);

        if (!$nonNumericIds) {
            return $listIds;
        }

        foreach ($nonNumericIds as $maybeNewList) {
            if (strlen($maybeNewList) < 3) {
                continue;
            }

            $exit = Lists::where('title', $maybeNewList)
                ->orWhere('slug', $maybeNewList)
                ->first();

            if ($exit) {
                $listIds[] = $exit->id;
                continue;
            }

            if (!$willCreate) {
                continue;
            }

            $list = Lists::create([
                'title' => sanitize_text_field($maybeNewList),
                'slug'  => sanitize_title($maybeNewList)
            ]);

            $listIds[] = $list->id;
            do_action('fluent_crm/list_created', $list);
        }

        return $listIds;
    }
}
