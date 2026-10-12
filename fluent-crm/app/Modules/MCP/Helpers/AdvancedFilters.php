<?php

namespace FluentCrm\App\Modules\MCP\Helpers;

use FluentCrm\App\Services\Helper;

/**
 * Validates MCP advanced_filters payloads and translates them into the admin
 * advanced filter engine's wire format. The engine fails silently on bad input,
 * so every condition is checked against the live Helper::getAdvancedFilterOptions() catalog.
 * Operator sets mirror the admin UI's RichFilters/_FilterItem.vue rules.
 */
class AdvancedFilters
{
    const MAX_GROUPS = 5;

    const MAX_CONDITIONS_PER_GROUP = 10;

    // Exceeding this is an error, not a slice: slicing a not_in list would widen the match.
    const MAX_VALUES_PER_CONDITION = 100;

    /**
     * Whether this operator carries no value on this property.
     * exist / not_exist are valueless only when the property sets disable_values.
     */
    private static function isValuelessOperator($operator, array $def)
    {
        if (in_array($operator, ['is_null', 'not_null', 'never'], true)) {
            return true;
        }
        if (in_array($operator, ['exist', 'not_exist'], true)) {
            return !empty($def['disable_values']);
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // Catalog
    // ---------------------------------------------------------------------

    /**
     * Live property map: 'provider.property' => {provider, property, def, locked}.
     *
     * `locked` marks disabled children and providers with no
     * fluentcrm_contacts_filter_{provider} handler, which would match nothing.
     * Memoized per request.
     *
     * @return array<string, array{provider:string, property:string, def:array, locked:bool}>
     */
    public static function catalog()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        // The provider key lives in each group's 'value', not the array key.
        foreach ((array) Helper::getAdvancedFilterOptions() as $group) {
            if (!is_array($group)) {
                continue;
            }
            $provider = (string) ($group['value'] ?? '');
            $children = $group['children'] ?? [];
            if ($provider === '' || !is_array($children)) {
                continue;
            }

            $handlerMissing = !has_action('fluentcrm_contacts_filter_' . $provider);

            foreach ($children as $child) {
                if (!is_array($child) || empty($child['value']) || !is_string($child['value'])) {
                    continue;
                }
                $key = $provider . '.' . $child['value'];
                if (isset($cache[$key])) {
                    continue;
                }
                $cache[$key] = [
                    'provider' => $provider,
                    'property' => $child['value'],
                    'def'      => $child,
                    'locked'   => !empty($child['disabled']) || $handlerMissing,
                ];
            }
        }

        return $cache;
    }

    // ---------------------------------------------------------------------
    // Schema (what get-contact-filter-schema returns)
    // ---------------------------------------------------------------------

    /**
     * The agent-facing advanced_filters reference: properties, operators, options, format and an example.
     */
    public static function schema()
    {
        $properties = [];
        $locked     = [];

        foreach (self::catalog() as $key => $entry) {
            if ($entry['locked']) {
                $locked[] = $key;
                continue;
            }

            $def  = $entry['def'];
            $kind = self::valueKind($def);

            $prop = [
                'property'  => $key,
                'label'     => (string) ($def['label'] ?? $key),
                'type'      => $kind,
                'operators' => self::resolveOperators($entry),
            ];

            if ($options = self::optionValues($entry)) {
                $prop['options'] = $options;
            }
            if ($hint = self::valueHint($entry, $kind)) {
                $prop['value_hint'] = $hint;
            }
            // Integrations document properties via help and/or value_description.
            $note = trim(implode(' ', array_filter([
                !empty($def['help']) ? wp_strip_all_tags((string) $def['help']) : '',
                !empty($def['value_description']) ? wp_strip_all_tags((string) $def['value_description']) : '',
            ])));
            if ($note !== '') {
                $prop['note'] = $note;
            }

            $properties[] = $prop;
        }

        $out = [
            'entity'     => 'contacts',
            'usage'      => [
                'parameter' => 'advanced_filters',
                'tools'     => ['list-contacts', 'apply-tags-lists-to-contacts (inside filter)'],
                'format'    => 'Array of OR groups; each group is an array of AND conditions {property, operator, value}. [[A,B],[C]] means (A AND B) OR C. A flat array of conditions is one group (all AND-ed). A single condition object is also accepted.',
                'combining' => 'advanced_filters composes with search, contact_type, created_after/before, sorting and paging — but NOT with the simple tags/lists/statuses parameters (the engine applies one branch or the other). Express those as segment.* conditions instead.',
                'limits'    => sprintf('Up to %1$d OR groups, %2$d conditions per group, %3$d values per condition.', self::MAX_GROUPS, self::MAX_CONDITIONS_PER_GROUP, self::MAX_VALUES_PER_CONDITION),
                'example'   => [
                    'meaning' => 'subscribed leads carrying tag 12 or 15 created in the last 90 days, PLUS anyone who opened campaign 123',
                    'value'   => [
                        [
                            ['property' => 'segment.status', 'operator' => 'in', 'value' => ['subscribed']],
                            ['property' => 'segment.tags', 'operator' => 'in', 'value' => [12, 15]],
                            ['property' => 'subscriber.created_at', 'operator' => 'days_within', 'value' => 90],
                        ],
                        [
                            ['property' => 'activities.campaign_email_activity', 'operator' => 'open', 'value' => 123],
                        ],
                    ],
                ],
            ],
            'properties' => $properties,
        ];

        if ($locked) {
            $out['unavailable_properties'] = $locked;
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Validation + translation to the engine wire format
    // ---------------------------------------------------------------------

    /**
     * Validate a payload and translate it into the groups ContactsQuery::formatAdvancedFilters consumes.
     * Returns a WP_Error naming the first offending condition. Memoized per input for the request.
     *
     * @param mixed $raw
     * @return array{groups: array, warnings: string[]}|\WP_Error
     */
    public static function normalize($raw)
    {
        static $memo = [];
        $memoKey = md5(wp_json_encode($raw));
        if (isset($memo[$memoKey])) {
            return $memo[$memoKey];
        }

        return $memo[$memoKey] = self::doNormalize($raw);
    }

    private static function doNormalize($raw)
    {
        if (!is_array($raw)) {
            return self::structureError();
        }

        // Accepts groups [[c,c],[c]], a flat list [c,c] (one AND group), or a single condition.
        if (isset($raw['property']) || isset($raw['source'])) {
            $raw = [[$raw]];
        } elseif (self::isConditionList($raw)) {
            $raw = [$raw];
        }

        if (count($raw) > self::MAX_GROUPS) {
            return self::limitError();
        }

        $groups   = [];
        $warnings = [];

        $groupNo = 0;
        foreach ($raw as $group) {
            $groupNo++;
            if (!is_array($group)) {
                return self::structureError();
            }
            if (count($group) > self::MAX_CONDITIONS_PER_GROUP) {
                return self::limitError();
            }

            $engineGroup = [];
            $conditionNo = 0;
            foreach ($group as $condition) {
                $conditionNo++;
                $item = self::normalizeCondition($condition, $groupNo, $conditionNo, $warnings);
                if (is_wp_error($item)) {
                    return $item;
                }
                $engineGroup[] = $item;
            }

            if ($engineGroup) {
                $groups[] = $engineGroup;
            }
        }

        if (!$groups) {
            return MCPHelper::error('invalid_param', 'advanced_filters contained no conditions. Provide at least one {property, operator, value} condition, or omit the parameter.');
        }

        return ['groups' => $groups, 'warnings' => $warnings];
    }

    /** True when every element looks like a condition object (flat list form). */
    private static function isConditionList(array $raw)
    {
        foreach ($raw as $element) {
            if (!is_array($element) || (!isset($element['property']) && !isset($element['source']))) {
                return false;
            }
        }
        return (bool) $raw;
    }

    /**
     * Validate one condition and build the engine item.
     *
     * @return array|\WP_Error
     */
    private static function normalizeCondition($condition, $groupNo, $conditionNo, array &$warnings)
    {
        if (!is_array($condition)) {
            return self::structureError();
        }

        // Lean format: property "provider.property". Wire format: source tuple.
        $key = null;
        if (isset($condition['property']) && is_string($condition['property']) && strpos($condition['property'], '.') !== false) {
            $key = $condition['property'];
        } elseif (isset($condition['source']) && is_array($condition['source']) && count($condition['source']) === 2) {
            $source = array_values($condition['source']);
            if (is_scalar($source[0]) && is_scalar($source[1])) {
                $key = $source[0] . '.' . $source[1];
            }
        }

        if ($key === null) {
            return MCPHelper::error('invalid_param', sprintf(
                'The condition in group %1$d position %2$d has no usable property. Each condition needs {property: "provider.property", operator, value}. Call get-contact-filter-schema for the reference.',
                $groupNo,
                $conditionNo
            ), ['group' => $groupNo, 'condition' => $conditionNo]);
        }

        $catalog = self::catalog();
        if (!isset($catalog[$key])) {
            return MCPHelper::error('invalid_param', sprintf(
                'Unknown advanced filter property "%1$s" (group %2$d, condition %3$d). The engine would silently mismatch, so the call is refused. Call get-contact-filter-schema for valid properties.',
                $key,
                $groupNo,
                $conditionNo
            ), ['property' => $key, 'valid_properties' => array_keys(array_filter($catalog, function ($e) {
                return !$e['locked'];
            }))]);
        }

        $entry = $catalog[$key];
        if ($entry['locked']) {
            return MCPHelper::error('property_unavailable', sprintf(
                'Advanced filter property "%s" is not available on this site: its provider marks it disabled (usually FluentCRM Pro or the integration\'s full version is missing). The engine would silently match nothing, so this call is rejected instead.',
                $key
            ), ['property' => $key]);
        }

        $operator = isset($condition['operator']) && is_scalar($condition['operator'])
            ? (string) $condition['operator']
            : '';
        $allowed = self::resolveOperators($entry);
        if ($operator === '' || ($allowed !== null && !in_array($operator, $allowed, true))) {
            return MCPHelper::error('invalid_param', sprintf(
                'Operator "%1$s" is not valid for "%2$s". Allowed: %3$s.',
                $operator,
                $key,
                $allowed === null ? '(see admin UI)' : implode(', ', $allowed)
            ), ['property' => $key, 'operator' => $operator, 'allowed_operators' => $allowed]);
        }

        $valueless = self::isValuelessOperator($operator, $entry['def']);

        $value = array_key_exists('value', $condition) ? $condition['value'] : null;
        if ($value === null && !$valueless) {
            return MCPHelper::error('invalid_param', sprintf(
                'The condition on "%1$s" (operator %2$s) has no value.',
                $key,
                $operator
            ), ['property' => $key, 'operator' => $operator]);
        }

        if (!$valueless) {
            $value = self::normalizeValue($entry, $operator, $value, $warnings);
            if (is_wp_error($value)) {
                return $value;
            }
        } else {
            $value = '';
        }

        $item = [
            'source'   => [$entry['provider'], $entry['property']],
            'operator' => $operator,
            'value'    => $value,
        ];

        // extra_value is a second input (e.g. the event key); required for composite_optioned_compare.
        if (array_key_exists('extra_value', $condition) && is_scalar($condition['extra_value'])) {
            $item['extra_value'] = (string) $condition['extra_value'];
        }
        if (($entry['def']['type'] ?? '') === 'composite_optioned_compare' && empty($item['extra_value'])) {
            return MCPHelper::error('invalid_param', sprintf(
                '"%s" also needs extra_value (the event key to compare against). Without it the engine silently skips the condition.',
                $key
            ), ['property' => $key]);
        }

        return $item;
    }

    /**
     * Coerce and validate the value for the property's kind, as the engine executes it.
     *
     * @return mixed|\WP_Error
     */
    private static function normalizeValue(array $entry, $operator, $value, array &$warnings)
    {
        $def  = $entry['def'];
        $key  = $entry['provider'] . '.' . $entry['property'];
        $kind = self::valueKind($def);

        if ($kind === 'numeric') {
            if (!is_numeric($value)) {
                return self::valueError($key, 'a number');
            }
            $num = (float) $value;
            if (!is_finite($num) || abs($num) > 1000000000000) {
                return self::valueError($key, 'a number within a sensible range');
            }
            return (string) $value;
        }

        if ($kind === 'date') {
            if ($operator === 'days_before' || $operator === 'days_within') {
                if (!is_numeric($value) || (int) $value < 0 || (int) $value > 36500) {
                    return self::valueError($key, 'a number of days between 0 and 36500');
                }
                return (string) (int) $value;
            }
            if (!is_string($value) || trim($value) === '') {
                return self::valueError($key, 'a date (YYYY-MM-DD or "YYYY-MM-DD HH:MM:SS", site timezone)');
            }
            // Same parser as the other MCP dates: real calendar dates only, offsets to site time.
            $normalized = MCPHelper::normalizeDateBoundary($value, $key);
            if (is_wp_error($normalized)) {
                return $normalized;
            }
            // date_equal matches within a day, so only the date part is compared.
            if ($operator === 'date_equal') {
                return substr($normalized, 0, 10);
            }
            // A date-only value keeps its date-only form.
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) ? trim($value) : $normalized;
        }

        // Tag/list refs resolve to ids; unknown refs fail closed.
        if ($entry['provider'] === 'segment' && in_array($entry['property'], ['tags', 'lists'], true)) {
            $kindName = $entry['property'] === 'tags' ? 'tag' : 'list';
            $items    = is_array($value) ? $value : [$value];
            if (count($items) > self::MAX_VALUES_PER_CONDITION) {
                return self::tooManyValuesError($key);
            }
            $resolved = MCPHelper::resolveSegmentRefs($items, $kindName);
            if ($resolved['unknown']) {
                return MCPHelper::error('invalid_param', sprintf(
                    '"%s" contains references that do not match any existing tag/list by id, title, or slug.',
                    $key
                ), ['property' => $key, 'unknown_refs' => $resolved['unknown']]);
            }
            if (empty($resolved['ids'])) {
                return self::valueError($key, 'at least one tag/list reference');
            }
            return $resolved['ids'];
        }

        if ($kind === 'enum' || $kind === 'id') {
            // Single-value selections: the engine reads one scalar.
            $isMultiple = !empty($def['is_multiple']);
            if (!$isMultiple || $kind === 'id') {
                if (is_array($value)) {
                    if (count($value) !== 1) {
                        return self::valueError($key, 'a single value');
                    }
                    $value = reset($value);
                }
                if (!is_scalar($value)) {
                    return self::valueError($key, 'a single value');
                }
                if ($kind === 'id') {
                    if (!is_numeric($value)) {
                        return self::valueError($key, 'a numeric id');
                    }
                    return (int) $value;
                }
                $value = (string) $value;
                return self::checkEnumValues($entry, [$value], $warnings) ?: $value;
            }

            // = and != read one scalar even on multi selections.
            if ($operator === '=' || $operator === '!=') {
                if (is_array($value)) {
                    if (count($value) !== 1) {
                        return self::valueError($key, 'a single value with the = / != operators (use in / not_in for sets)');
                    }
                    $value = reset($value);
                }
                if (!is_scalar($value)) {
                    return self::valueError($key, 'a single value');
                }
                $value = (string) $value;
                return self::checkEnumValues($entry, [$value], $warnings) ?: $value;
            }

            if (!is_array($value)) {
                $value = [$value];
            }
            if (count($value) > self::MAX_VALUES_PER_CONDITION) {
                return self::tooManyValuesError($key);
            }
            $flat = [];
            foreach ($value as $entryValue) {
                if (!is_scalar($entryValue)) {
                    return self::valueError($key, 'a value or array of values');
                }
                $flat[] = (string) $entryValue;
            }
            if (!$flat) {
                return self::valueError($key, 'a non-empty array of values');
            }
            return self::checkEnumValues($entry, $flat, $warnings) ?: $flat;
        }

        // Cascade and unknown add-on types pass through; the integration handler owns the semantics.
        if ($kind === 'cascade' || $kind === 'unknown') {
            if (is_scalar($value)) {
                return $kind === 'cascade' ? [(string) $value] : $value;
            }
            if (is_array($value)) {
                if (count($value) > self::MAX_VALUES_PER_CONDITION) {
                    return self::tooManyValuesError($key);
                }
                $flat = [];
                foreach ($value as $entryValue) {
                    if (!is_scalar($entryValue)) {
                        return self::valueError($key, 'a value or array of scalar values');
                    }
                    $flat[] = (string) $entryValue;
                }
                if (!$flat) {
                    return self::valueError($key, 'a non-empty array of values');
                }
                return $flat;
            }
            return self::valueError($key, 'a value or array of scalar values');
        }

        // Text in/not_in: only reachable for subscriber.email.
        if ($operator === 'in' || $operator === 'not_in') {
            $items = is_array($value) ? array_values($value) : [$value];
            if (count($items) > self::MAX_VALUES_PER_CONDITION) {
                return self::tooManyValuesError($key);
            }
            $flat = [];
            foreach ($items as $item) {
                if (!is_scalar($item)) {
                    return self::valueError($key, 'an array of text values');
                }
                $item = trim((string) $item);
                if ($item !== '') {
                    $flat[] = $item;
                }
            }
            // Fail closed: the engine drops an empty whereIn, leaving the group unconstrained.
            if (!$flat) {
                return self::valueError($key, 'a non-empty array of text values');
            }
            return array_values(array_unique($flat));
        }

        // Text takes one scalar; the engine silently skips an array.
        if (!is_scalar($value)) {
            return self::valueError($key, 'a single text value');
        }
        return (string) $value;
    }

    /**
     * Off-catalog enum values are an error for contact status/type and a warning elsewhere.
     *
     * @return \WP_Error|null null when fine (possibly with warnings pushed)
     */
    private static function checkEnumValues(array $entry, array $values, array &$warnings)
    {
        $known = self::optionValues($entry);
        if (!$known) {
            return null;
        }

        $unknown = array_values(array_diff($values, $known));
        if (!$unknown) {
            return null;
        }

        $key    = $entry['provider'] . '.' . $entry['property'];
        $strict = in_array($entry['property'], ['status', 'contact_type'], true) && $entry['provider'] === 'segment';

        if ($strict) {
            return MCPHelper::error('invalid_param', sprintf(
                '"%s" contains values outside the registered enum.',
                $key
            ), ['property' => $key, 'unknown_values' => $unknown, 'allowed_values' => $known]);
        }

        $warnings[] = sprintf(
            'Values "%1$s" on %2$s are not among the documented options (%3$s); they are matched literally, so typos return zero rows.',
            implode(', ', $unknown),
            $key,
            implode(', ', $known)
        );
        return null;
    }

    // ---------------------------------------------------------------------
    // Per-property metadata (operators / kinds / options / hints)
    // ---------------------------------------------------------------------

    /**
     * The operators a property accepts, per the admin UI's rules.
     * Returns null for unknown add-on widget types.
     *
     * @return string[]|null
     */
    public static function resolveOperators(array $entry)
    {
        $def      = $entry['def'];
        $provider = $entry['provider'];

        if (!empty($def['custom_operators']) && is_array($def['custom_operators'])) {
            return array_map('strval', array_keys($def['custom_operators']));
        }

        $type = $def['type'] ?? 'text';

        switch ($type) {
            case 'extended_text':
                $ops = ['=', '!=', 'contains', 'not_contains', 'startsWith', 'endsWith'];
                break;
            case 'numeric':
            case 'times_numeric':
                $ops = ['>', '<', '=', '!='];
                break;
            case 'single_assert_option':
                $ops = ['='];
                break;
            case 'straight_assert_option':
                $ops = ['=', '!='];
                break;
            case 'dates':
                $ops = ['before', 'after', 'date_equal', 'days_before', 'days_within'];
                if ($provider === 'activities' && in_array($def['value'] ?? '', ['email_opened', 'email_link_clicked'], true)) {
                    $ops[] = 'never';
                }
                break;
            case 'selections':
                if (($def['option_key'] ?? '') === 'countries') {
                    $ops = ['in', 'not_in'];
                } elseif (!empty($def['is_only_in'])) {
                    $ops = ['in'];
                } elseif ($provider === 'custom_fields') {
                    $ops = ['in', 'not_in', '=', '!='];
                } elseif (!empty($def['is_multiple']) && $provider !== 'subscriber' && empty($def['is_singular_value'])) {
                    // Relation-backed multi selects (tags, lists, purchased items).
                    $ops = ['in', 'not_in', 'in_all', 'not_in_all'];
                } else {
                    $ops = ['in', 'not_in'];
                }
                break;
            case 'nullable_text':
                $ops = ['=', '!=', 'contains', 'not_contains', 'is_null', 'not_null'];
                break;
            case 'text':
                $ops = ['=', '!=', 'contains', 'not_contains'];
                break;
            default:
                return null;
        }

        if ($provider === 'custom_fields' || !empty($def['is_nullable'])) {
            $ops[] = 'is_null';
            $ops[] = 'not_null';
        }

        // The engine supports in/not_in on email even though the admin UI does not offer it.
        if ($provider === 'subscriber' && ($def['value'] ?? '') === 'email') {
            $ops[] = 'in';
            $ops[] = 'not_in';
        }

        return array_values(array_unique($ops));
    }

    /**
     * Agent-facing value kind. 'id' is a single numeric id from an ajax selector.
     */
    private static function valueKind(array $def)
    {
        $type = $def['type'] ?? 'text';

        if ($type === 'dates') {
            return 'date';
        }
        if ($type === 'numeric' || $type === 'times_numeric') {
            return 'numeric';
        }
        if ($type === 'composite_optioned_compare') {
            return 'composite';
        }
        if ($type === 'cascade_selections') {
            return 'cascade';
        }
        if ($type === 'selections' || $type === 'single_assert_option' || $type === 'straight_assert_option') {
            if (($def['component'] ?? '') === 'ajax_selector' && empty($def['is_multiple'])) {
                return 'id';
            }
            return 'enum';
        }
        if (in_array($type, ['text', 'nullable_text', 'extended_text'], true)) {
            return 'text';
        }
        return 'unknown';
    }

    /**
     * A property's closed option list, or [] when the set is open or too large to inline.
     *
     * @return string[]
     */
    private static function optionValues(array $entry)
    {
        $def = $entry['def'];

        if (!empty($def['options']) && is_array($def['options'])) {
            return array_map('strval', array_keys($def['options']));
        }

        switch ($def['option_key'] ?? '') {
            case 'statuses':
                return array_values(fluentcrm_subscriber_statuses());
            case 'contact_types':
                return array_keys(fluentcrm_contact_types());
            case 'user_roles_options':
                return array_keys((array) wp_roles()->roles);
        }

        return [];
    }

    /** Format guidance for kinds whose values can't be enumerated inline. */
    private static function valueHint(array $entry, $kind)
    {
        $def = $entry['def'];
        $key = $entry['provider'] . '.' . $entry['property'];

        if ($key === 'subscriber.search') {
            return 'Matches name, email, phone, address, city, state, postal code, country and status with the chosen operator. For word-by-word smart search use the `search` parameter on list-contacts.';
        }
        if ($key === 'subscriber.country') {
            return 'Array of ISO 3166-1 alpha-2 codes as stored on contacts (e.g. ["US", "NL"]).';
        }
        if ($key === 'subscriber.email') {
            return 'One address for =/!=, a substring for contains, or an array of exact addresses for in/not_in (matching is case-insensitive). To simply fetch the contacts for a set of addresses, prefer the `emails` parameter on list-contacts — it composes with tags/lists/statuses, which advanced_filters does not.';
        }
        if ($entry['provider'] === 'segment' && in_array($entry['property'], ['tags', 'lists'], true)) {
            return 'Array of ids, slugs, or titles (resolved server-side, unknown refs are rejected). in_all = carries ALL of them; is_null/not_null = has none/any at all.';
        }
        if ($kind === 'id') {
            $optionKey = $def['option_key'] ?? '';
            $source = [
                'campaigns'       => 'list-campaigns',
                'funnels'         => 'list-automations',
                'email_sequences' => 'list-sequences',
                'companies'       => 'the companies UI',
            ];
            return 'One numeric id' . (isset($source[$optionKey]) ? ' — find it via ' . $source[$optionKey] : '') . '.';
        }
        if ($kind === 'date') {
            return 'before/after/date_equal take YYYY-MM-DD (site timezone; date_equal matches within that date). days_before = more than N days ago, days_within = within the last N days (both take a plain number).';
        }
        if ($kind === 'composite') {
            return 'value = the compare value; extra_value = the event key it applies to (required).';
        }
        if ($kind === 'cascade') {
            return 'Array of "parentId||childId" strings as the cascade selector emits — e.g. "123||456" = product 123, variation 456. Plain ids without "||" match nothing.';
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Errors
    // ---------------------------------------------------------------------

    private static function structureError()
    {
        return MCPHelper::error('invalid_param', 'advanced_filters must be an array of OR groups, each an array of AND conditions {property, operator, value}. A flat array of conditions is also accepted and treated as one group.', [
            'hint' => 'Example: [[{"property":"segment.tags","operator":"in","value":[12]}]] — call get-contact-filter-schema for the full reference.',
        ]);
    }

    private static function limitError()
    {
        return MCPHelper::error('invalid_param', sprintf(
            'advanced_filters allows at most %1$d OR groups with %2$d conditions each.',
            self::MAX_GROUPS,
            self::MAX_CONDITIONS_PER_GROUP
        ), ['max_groups' => self::MAX_GROUPS, 'max_conditions_per_group' => self::MAX_CONDITIONS_PER_GROUP]);
    }

    private static function tooManyValuesError($property)
    {
        return MCPHelper::error('invalid_param', sprintf(
            'The condition on "%1$s" carries more than %2$d values. Split it across calls; trimming it silently would change what matches.',
            $property,
            self::MAX_VALUES_PER_CONDITION
        ), ['property' => $property, 'max_values' => self::MAX_VALUES_PER_CONDITION]);
    }

    private static function valueError($property, $expected)
    {
        return MCPHelper::error('invalid_param', sprintf(
            'The value for "%1$s" must be %2$s.',
            $property,
            $expected
        ), ['property' => $property]);
    }
}
