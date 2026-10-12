<?php

namespace FluentCrm\App\Services\CrmMigrator;

use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

abstract class BaseMigrator
{
    abstract public function getInfo();

    abstract public function verifyCredentials($credential);

    abstract public function getSummary($settings);

    abstract public function runImport($settings);

    abstract public function getListTagMappings($settings);

    public function getFillables($with = ['main_fields', 'address', 'custom_fields'])
    {
        $mainFields = [
            'prefix'     => __('Name Prefix', 'fluent-crm'),
            'first_name' => __('First name', 'fluent-crm'),
            'last_name'  => __('Last name', 'fluent-crm'),
            'full_name'  => __('Full Name', 'fluent-crm'),
            'phone'      => __('Phone', 'fluent-crm')
        ];

        if (!$with) {
            return $mainFields;
        }

        $addressFields = [
            'address_line_1' => __('Address Line 1', 'fluent-crm'),
            'address_line_2' => __('Address Line 2', 'fluent-crm'),
            'postal_code'    => __('Postal code', 'fluent-crm'),
            'city'           => __('City', 'fluent-crm'),
            'state'          => __('State', 'fluent-crm'),
            'country'        => __('Country', 'fluent-crm'),
        ];

        $customFields = fluentcrm_get_custom_contact_fields();

        $customs = [];
        foreach ($customFields as $customField) {
            $customs[$customField['slug']] = $customField['label'] . ' (Custom Field)';
        }

        if (in_array('address', $with)) {
            $mainFields = array_merge($mainFields, $addressFields);
        }

        if ($customs && in_array('custom_fields', $with)) {
            $mainFields = array_merge($mainFields, $customs);
        }

        if (in_array('custom_fields_only', $with)) {
            return $customs;
        }

        return $mainFields;

    }

    /**
     * Parse a remote date value into Y-m-d.
     *
     * Mailchimp sends its display format (MM/DD/YYYY, DD/MM/YYYY), whose letters mean month and
     * day names in PHP, so the tokens are translated first; month and day may be unpadded (2/3/1990).
     * A value that does not match falls back to Helper::parseImportDate(), never to a guess.
     *
     * @param string $value
     * @param string $format remote date format
     * @return string|false Y-m-d, or false when the value is not a date (the field is then skipped)
     */
    protected function parseRemoteDate($value, $format)
    {
        $value = trim((string)$value);
        $order = strtoupper((string)$format);

        if (preg_match('/^(\d{1,4})\D(\d{1,4})\D(\d{1,4})$/', $value, $m)) {
            $positions = [];
            foreach (['YYYY', 'MM', 'DD'] as $token) {
                $positions[$token] = strpos($order, $token);
            }
            if (!in_array(false, $positions, true)) {
                asort($positions);
                $parts = array_combine(array_keys($positions), array_slice($m, 1, 3));
                if (strlen($parts['YYYY']) === 4 && checkdate((int)$parts['MM'], (int)$parts['DD'], (int)$parts['YYYY'])) {
                    return sprintf('%04d-%02d-%02d', $parts['YYYY'], $parts['MM'], $parts['DD']);
                }
                return false;
            }
        }

        return Helper::parseImportDate($value);
    }

    public function getMergedData($remoteData, $fieldMaps)
    {
        $formattedData = [];
        foreach ($fieldMaps as $fieldMap) {
            if ($fieldMap['will_skip'] == 'yes' || empty($fieldMap['fluentcrm_field']) || empty($fieldMap['remote_tag'])) {
                continue;
            }
            $tagName = $fieldMap['remote_tag'];
            if (empty($remoteData[$tagName])) {
                continue;
            }

            $fluentCrmTag = $fieldMap['fluentcrm_field'];

            if (!empty($fieldMap['date_format'])) {
                $date = $this->parseRemoteDate($remoteData[$tagName], $fieldMap['date_format']);
                if ($date) {
                    $formattedData[$fluentCrmTag] = $date;
                }
            } else {
                $formattedData[$fluentCrmTag] = $remoteData[$tagName];
            }
        }

        $formattedData = array_filter($formattedData);

        $customFields = fluentcrm_get_custom_contact_fields();

        if (!$customFields) {
            return $formattedData;
        }

        $keys = array_map(function ($item) {
            return $item['slug'];
        }, $customFields);

        $customs = array_filter(Arr::only($formattedData, $keys));

        foreach ($keys as $key) {
            unset($formattedData[$key]);
        }

        $formattedData['custom_values'] = $customs;

        return $formattedData;

    }

    /**
     * Maybe create field options.
     *
     * When importing multi-checkbox or multi-select data, we can save time by
     * auto-populating the options into the FluentCRM custom field config.
     *
     * @param array $mergeData The loaded custom field data.
     * @since 2.8.34
     *
     */
    public function maybeCreateFieldOptions($mergeData)
    {

        $needsUpdate = false;
        $customFields = fluentcrm_get_custom_contact_fields();

        foreach ($mergeData['custom_values'] as $key => $value) {
            if (is_array($value)) {
                foreach ($customFields as $i => $field) {
                    if ($key === $field['slug'] && in_array($field['type'], ['checkbox', 'select-multi']) && !empty(array_diff($value, $field['options']))) {
                        $value = array_map('sanitize_text_field', $value);
                        $customFields[$i]['options'] = array_merge($field['options'], array_diff($value, $field['options']));
                        $needsUpdate = true;
                    }
                }
            }
        }

        if ($needsUpdate) {
            fluentcrm_update_option('contact_custom_fields', $customFields);
        }

    }

    public function mapTags($tagMappings)
    {
        $formattedMaps = [];

        foreach ($tagMappings as $tagMapping) {
            $remoteId = $tagMapping['remote_id'];
            if ($tagMapping['will_create'] == 'yes') {
                $remoteName = sanitize_text_field($tagMapping['remote_name']);
                if (!$remoteName) {
                    continue;
                }
                $tagMapping['fluentcrm_id'] = $this->getTagId($remoteName);
            }

            if (empty($tagMapping['fluentcrm_id'])) {
                continue;
            }

            $formattedMaps[$remoteId] = (int)$tagMapping['fluentcrm_id'];
        }

        return $formattedMaps;

    }

    public function getTagId($tagName)
    {
        $slug = sanitize_title($tagName);

        $tag = Tag::updateOrCreate(
            ['slug' => sanitize_title($slug, 'display')],
            ['title' => $tagName]
        );

        do_action('fluentcrm_tag_created', $tag->id);

        do_action('fluent_crm/tag_created', $tag);

        return $tag->id;
    }

    public function getListId($listName)
    {
        $slug = sanitize_title($listName);

        $list = Lists::updateOrCreate(
            ['slug' => sanitize_title($slug, 'display')],
            ['title' => $listName]
        );

        do_action('fluentcrm_list_created', $list->id);
        do_action('fluent_crm/list_created', $list);

        return $list->id;
    }

    public function getInfoIcon()
    {
        $infoSvg = '<span class="fc-inline-help-icon" aria-hidden="true" style="display:inline-flex;vertical-align:middle">'
            . '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" xmlns="http://www.w3.org/2000/svg">'
            . '<path d="M8 1.333a6.667 6.667 0 1 0 0 13.334A6.667 6.667 0 0 0 8 1.333zm0 2a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm1.333 9.334H6.667v-1.333h1.333V7.333H6.667V6h2.666v5.333h1.333v1.334z"/>'
            . '</svg></span>';

        return $infoSvg;
    }
}
