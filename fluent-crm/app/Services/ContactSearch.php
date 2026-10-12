<?php

namespace FluentCrm\App\Services;

use FluentCrm\Framework\Support\Arr;

/**
 * Turns contact search box text into a targeted query against fc_subscribers.
 *
 * Syntax understood by parse():
 *   timothy cook           every word must match first name, last name or email
 *   "timothy cook"         the phrase must appear in the full name or email
 *   jane@acme.com          email (exact match uses the unique index)
 *   +1 (555) 010-2030      phone, compared on digits only
 *   #123                   contact ID
 *   email:acme.com         scoped to one field; see FIELDS for the full list
 *   city:"new york"        quoted value for a scoped field
 *   <custom_slug>:gold     scoped to a contact custom field, by its slug
 *
 * Scoped terms and free text combine with AND. A `key:value` whose key is not a
 * known field is treated as ordinary text, so URLs and times still search.
 */
class ContactSearch
{
    /**
     * Scoped search keys => the field they search. Aliases share a target.
     */
    const FIELDS = [
        'id'          => 'id',
        'email'       => 'email',
        'name'        => 'name',
        'first_name'  => 'first_name',
        'first'       => 'first_name',
        'last_name'   => 'last_name',
        'last'        => 'last_name',
        'phone'       => 'phone',
        'address'     => 'address',
        'city'        => 'city',
        'state'       => 'state',
        'postal_code' => 'postal_code',
        'zip'         => 'postal_code',
        'country'     => 'country',
        'source'      => 'source',
    ];

    /**
     * Minimum digits for bare free text to be treated as a phone number.
     * Shorter numbers (house numbers, postal codes, IDs) stay as text.
     */
    const PHONE_MIN_DIGITS = 6;

    /**
     * Most terms read from one search. Each term is a LIKE scan.
     */
    const MAX_TERMS = 20;

    /**
     * Parse search text into a plan. Reads only custom-field definitions.
     *
     * @param string $raw The text from the search box.
     * @param string[]|null $customSlugs Lowercased custom-field slugs; null reads the site's.
     * @return array{
     *     mode: string,
     *     text: string,
     *     words: string[],
     *     phrases: string[],
     *     ids: int[],
     *     scoped: array<int, array{field: string, key: string, value: string, custom: bool}>,
     *     unknown: string[]
     * } `mode` is one of: empty, words, email, phone, id, scoped.
     */
    public static function parse($raw, $customSlugs = null)
    {
        $plan = [
            'mode'    => 'empty',
            'text'    => '',
            'words'   => [],
            'phrases' => [],
            'ids'     => [],
            'scoped'  => [],
            'unknown' => [],
        ];

        $raw = trim((string)$raw);
        if ($raw === '') {
            return $plan;
        }

        if ($customSlugs === null) {
            $customSlugs = self::customFieldSlugs();
        }

        // key:"quoted value" | key:value (value may be empty) | "quoted phrase" | bare token.
        // Keys allow hyphens and non-ASCII letters.
        $matched = preg_match_all('/([^\s:"#][^\s:"]*):"([^"]*)"?|([^\s:"#][^\s:"]*):(\S*)|"([^"]*)"?|(\S+)/u', $raw, $matches, PREG_SET_ORDER);

        // Invalid UTF-8 fails the /u regex: match nothing rather than drop the search.
        if ($matched === false) {
            $plan['mode'] = 'words';
            $plan['scoped'][] = ['key' => 'id', 'value' => '-1'];
            return $plan;
        }

        $matches = array_slice($matches, 0, self::MAX_TERMS);

        $freeParts = [];
        $count = count($matches);
        for ($i = 0; $i < $count; $i++) {
            $match = $matches[$i];
            $key = '';
            $value = '';
            $quoted = false;
            if (isset($match[1]) && $match[1] !== '') {
                $key = $match[1];
                $value = $match[2];
                $quoted = true;
            } elseif (isset($match[3]) && $match[3] !== '') {
                $key = $match[3];
                $value = $match[4];
            }

            if ($key !== '') {
                $lowerKey = self::lower($key);
                $value = trim($value);
                $field = null;
                $isCustom = false;

                if (isset(self::FIELDS[$lowerKey])) {
                    $field = self::FIELDS[$lowerKey];
                } elseif (in_array($lowerKey, $customSlugs, true)) {
                    $field = $lowerKey;
                    $isCustom = true;
                }

                if ($field === null) {
                    // Unknown key: search as plain text and report it. Times and URLs are not reported.
                    if (preg_match('/\p{L}/u', $lowerKey) && strpos($value, '//') !== 0) {
                        $plan['unknown'][] = $lowerKey;
                    }
                    $freeParts[] = ['type' => 'word', 'value' => $match[0]];
                    continue;
                }

                // "city: berlin": the value is the next token.
                if ($value === '' && !$quoted && $i + 1 < $count && self::isPlainToken($matches[$i + 1])) {
                    $next = $matches[++$i];
                    $value = trim(isset($next[6]) && $next[6] !== '' ? $next[6] : $next[5]);
                }

                // An empty value matches nothing, so select-all bulk actions never hit every contact.
                $plan['scoped'][] = ['field' => $field, 'key' => $lowerKey, 'value' => $value, 'custom' => $isCustom];
                continue;
            }

            if (isset($match[5]) && $match[0][0] === '"') {
                $phrase = trim($match[5]);
                if ($phrase !== '') {
                    $freeParts[] = ['type' => strpos($phrase, ' ') === false ? 'word' : 'phrase', 'value' => $phrase];
                }
                continue;
            }

            $token = $match[6];
            if (preg_match('/^#(\d+)$/', $token, $idMatch)) {
                $plan['ids'][] = (int)$idMatch[1];
                continue;
            }

            $freeParts[] = ['type' => 'word', 'value' => $token];
        }

        $plan['text'] = trim(implode(' ', array_column($freeParts, 'value')));
        foreach ($freeParts as $part) {
            $plan[$part['type'] === 'phrase' ? 'phrases' : 'words'][] = $part['value'];
        }

        if ($plan['text'] !== '') {
            $plan['mode'] = self::detectFreeTextMode($plan['text'], $plan['phrases']);
        } elseif ($plan['ids']) {
            $plan['mode'] = 'id';
        } elseif ($plan['scoped']) {
            $plan['mode'] = 'scoped';
        }

        return $plan;
    }

    /**
     * Apply the search to a Subscriber query, wrapped in one AND group so it
     * can never widen other filters (status, tags, lists, segments).
     *
     * @param \FluentCrm\Framework\Database\Orm\Builder $query Subscriber query.
     * @param string|array $search Raw search text, or a plan from parse().
     * @param array $options include_custom_fields (bool): also match custom-field values.
     * @return \FluentCrm\Framework\Database\Orm\Builder
     */
    public static function apply($query, $search, $options = [])
    {
        $plan = is_array($search) ? $search : self::parse($search);
        if ($plan['mode'] === 'empty') {
            return $query;
        }

        $includeCustom = !empty($options['include_custom_fields']);

        return $query->where(function ($group) use ($plan, $includeCustom) {
            if ($plan['ids']) {
                $group->whereIn('id', $plan['ids']);
            }

            foreach ($plan['scoped'] as $term) {
                self::applyScopedTerm($group, $term);
            }

            if ($plan['text'] === '') {
                return;
            }

            if (!$includeCustom) {
                self::applyFreeText($group, $plan);
                return;
            }

            // Free text OR a custom-field value containing the whole text (not word by word).
            $group->where(function ($either) use ($plan) {
                $either->where(function ($native) use ($plan) {
                    self::applyFreeText($native, $plan);
                })->orWhereHas('custom_field_meta', function ($meta) use ($plan) {
                    $meta->where('value', 'LIKE', self::contains($plan['text']));
                });
            });
        });
    }

    /**
     * Order a searched query by match quality, then newest first.
     * Only free text is ranked; ID and scoped-only searches keep id order.
     *
     * @param \FluentCrm\Framework\Database\Orm\Builder $query
     * @param string|array $search Raw search text, or a plan from parse().
     * @return \FluentCrm\Framework\Database\Orm\Builder
     */
    public static function orderByRelevance($query, $search)
    {
        $plan = is_array($search) ? $search : self::parse($search);

        // Searches under 3 characters keep newest first. Mirrored by isRankableSearch() in _ContactsTable.vue.
        $length = function_exists('mb_strlen') ? mb_strlen($plan['text']) : strlen($plan['text']);
        if ($length < 3 || !in_array($plan['mode'], ['words', 'email'], true)) {
            return $query->orderBy('id', 'DESC');
        }

        global $wpdb;
        $text = trim(str_replace('"', '', $plan['text']));
        $words = array_merge($plan['words'], $plan['phrases']);
        $firstWord = $words ? $words[0] : $text;
        $textPrefix = $wpdb->esc_like($text) . '%';
        $wordPrefix = $wpdb->esc_like($firstWord) . '%';

        $query->orderByRaw(
            "CASE
                WHEN email = ? THEN 0
                WHEN CONCAT_WS(' ', first_name, last_name) = ? THEN 1
                WHEN CONCAT_WS(' ', last_name, first_name) = ? THEN 1
                WHEN CONCAT_WS(' ', first_name, last_name) LIKE ? THEN 2
                WHEN first_name LIKE ? OR last_name LIKE ? OR email LIKE ? THEN 3
                ELSE 4
            END",
            [$text, $text, $text, $textPrefix, $wordPrefix, $wordPrefix, $textPrefix]
        );

        return $query->orderBy('id', 'DESC');
    }

    /**
     * How the search was understood, for the UI. Null for an empty search.
     *
     * @param string $raw
     * @param bool $includeCustomFields Whether free text also matched custom-field values.
     * @return array|null
     */
    public static function describe($raw, $includeCustomFields = false)
    {
        $plan = self::parse($raw);
        if ($plan['mode'] === 'empty') {
            return null;
        }

        return [
            'custom_fields' => (bool)$includeCustomFields && $plan['text'] !== '',
            'mode'    => $plan['mode'],
            'text'    => $plan['text'],
            'words'   => array_values(array_merge($plan['words'], $plan['phrases'])),
            'ids'     => $plan['ids'],
            'scoped'  => array_map(function ($term) {
                return ['key' => $term['key'], 'field' => $term['field'], 'value' => $term['value'], 'custom' => $term['custom']];
            }, $plan['scoped']),
            'unknown' => array_values(array_unique($plan['unknown'])),
        ];
    }

    /**
     * Free text with no scope: pick the cheapest query that matches intent.
     */
    private static function applyFreeText($query, $plan)
    {
        $text = $plan['text'];

        if ($plan['mode'] === 'email') {
            if (is_email($text)) {
                // Full address: equality uses the unique index.
                $query->where('email', $text);
            } else {
                $query->where('email', 'LIKE', self::contains($text));
            }
            return;
        }

        if ($plan['mode'] === 'phone') {
            // Digits-only mailboxes exist (123456789@qq.com), so also match email.
            $like = self::contains($text);
            $query->where(function ($q) use ($text, $like) {
                self::wherePhoneDigits($q, $text);
                $q->orWhere('email', 'LIKE', $like);
            });
            return;
        }

        foreach ($plan['words'] as $word) {
            $like = self::contains($word);
            $query->where(function ($q) use ($like) {
                $q->where('first_name', 'LIKE', $like)
                    ->orWhere('last_name', 'LIKE', $like)
                    ->orWhere('email', 'LIKE', $like);
            });
        }

        foreach ($plan['phrases'] as $phrase) {
            $like = self::contains($phrase);
            $query->where(function ($q) use ($like) {
                $q->whereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$like])
                    ->orWhere('email', 'LIKE', $like);
            });
        }
    }

    /**
     * One `key:value` term. Every scoped term narrows the result (AND).
     */
    private static function applyScopedTerm($query, $term)
    {
        $value = $term['value'];

        if ($value === '') {
            // "city:" with nothing after it: match nothing rather than ignore it.
            $query->whereRaw('1 = 0');
            return;
        }

        if ($term['custom']) {
            $slug = $term['field'];
            $query->whereHas('custom_field_meta', function ($meta) use ($slug, $value) {
                $meta->where('key', $slug)->where('value', 'LIKE', self::contains($value));
            });
            return;
        }

        switch ($term['field']) {
            case 'id':
                $ids = array_filter(array_map('absint', explode(',', $value)));
                if ($ids) {
                    $query->whereIn('id', $ids);
                } else {
                    $query->whereRaw('1 = 0');
                }
                return;
            case 'name':
                foreach (preg_split('/\s+/', $value) as $word) {
                    $like = self::contains($word);
                    $query->where(function ($q) use ($like) {
                        $q->where('first_name', 'LIKE', $like)->orWhere('last_name', 'LIKE', $like);
                    });
                }
                return;
            case 'address':
                $like = self::contains($value);
                $query->where(function ($q) use ($like) {
                    $q->where('address_line_1', 'LIKE', $like)->orWhere('address_line_2', 'LIKE', $like);
                });
                return;
            case 'phone':
                if (preg_match('/\d/', $value)) {
                    self::wherePhoneDigits($query, $value);
                } else {
                    $query->where('phone', 'LIKE', self::contains($value));
                }
                return;
            case 'email':
                if (is_email($value)) {
                    $query->where('email', $value);
                } else {
                    $query->where('email', 'LIKE', self::contains($value));
                }
                return;
            default:
                // Plain column; the key was whitelisted by FIELDS in parse().
                $query->where($term['field'], 'LIKE', self::contains($value));
        }
    }

    /**
     * Match phone numbers on digits only, with leading zeros dropped.
     */
    private static function wherePhoneDigits($query, $value)
    {
        $digits = ltrim(preg_replace('/\D/', '', $value), '0');
        if ($digits === '') {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereRaw(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '.', ''), '+', '') LIKE ?",
            ['%' . $digits . '%']
        );
    }

    /**
     * Decide what bare free text is: an email, a phone number, or words.
     */
    private static function detectFreeTextMode($text, $phrases)
    {
        if (!$phrases && strpos($text, ' ') === false && strpos($text, '@') !== false) {
            return 'email';
        }

        if (!$phrases && preg_match('/^\+?[\d\s().\-]+$/', $text)) {
            $digitCount = strlen(preg_replace('/\D/', '', $text));
            if ($digitCount >= self::PHONE_MIN_DIGITS) {
                return 'phone';
            }
        }

        return 'words';
    }

    /**
     * Lowercased slugs of the site's contact custom fields, minus any that
     * collide with a built-in key (built-ins win).
     */
    private static function customFieldSlugs()
    {
        $slugs = [];
        foreach ((array)fluentcrm_get_custom_contact_fields() as $field) {
            $slug = self::lower((string)Arr::get($field, 'slug'));
            if ($slug !== '' && !isset(self::FIELDS[$slug])) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Whether a parsed match is a bare token or quoted phrase that can serve
     * as the value of a preceding `key:` (not itself a `key:value`).
     */
    private static function isPlainToken($match)
    {
        return (isset($match[6]) && $match[6] !== '' && !preg_match('/^#\d+$/', $match[6]))
            || (isset($match[5]) && $match[0] !== '' && $match[0][0] === '"');
    }

    /**
     * Lowercase that keeps non-ASCII slugs (Город -> город) intact.
     */
    private static function lower($value)
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * `%value%` with LIKE wildcards in the value escaped.
     */
    private static function contains($value)
    {
        global $wpdb;
        return '%' . $wpdb->esc_like($value) . '%';
    }
}
