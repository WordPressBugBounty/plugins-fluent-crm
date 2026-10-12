<?php

namespace FluentCrm\App\Modules\MCP\Helpers;

use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\SubscriberNote;
use FluentCrm\App\Services\Sanitize;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\ContactsQuery;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\PermissionManager;

/**
 * Shared utilities for FluentCRM MCP tools: identifier resolution, formatting,
 * filter translation, pagination and structured errors.
 */
class MCPHelper
{
    /**
     * Version of the result shapes, returned as meta.schema_version on every tool result.
     * Bump the major version when a result field is renamed, removed or changes meaning;
     * adding a field needs no bump.
     */
    const SCHEMA_VERSION = '2.0.0';

    /**
     * Ceiling on the `emails` lookup array. Matches the 100-row per_page cap.
     */
    const MAX_EMAIL_LOOKUP = 100;

    /**
     * Ceiling on a single tags[]/lists[] reference array.
     */
    const MAX_SEGMENT_REFS = 100;

    /**
     * Per-request memo for resolveSegmentRefs().
     */
    private static $segmentRefCache = [];

    // ---------------------------------------------------------------------
    // Identifier resolution
    // ---------------------------------------------------------------------

    /**
     * Resolve a contact from an input array that may carry contact_id or email.
     *
     * @param array $input
     * @return Subscriber|\WP_Error
     */
    public static function resolveContact($input)
    {
        $contactId = isset($input['contact_id']) ? (int) $input['contact_id'] : 0;
        $email     = isset($input['email']) ? sanitize_email($input['email']) : '';

        if ($contactId) {
            $subscriber = Subscriber::find($contactId);
            if (!$subscriber) {
                return self::error('not_found', 'Contact not found', ['contact_id' => $contactId]);
            }
            return $subscriber;
        }

        if ($email) {
            $subscriber = Subscriber::where('email', $email)->first();
            if (!$subscriber) {
                return self::error('not_found', 'Contact not found', ['email' => $email]);
            }
            return $subscriber;
        }

        return self::error('invalid_param', 'Provide contact_id or email');
    }

    /**
     * Batch-resolve tag/list references (ids, titles, or slugs) to IDs,
     * reporting resolved ids, created rows and unmatched refs.
     *
     * @param mixed  $items      ids, titles, or slugs (mixed freely)
     * @param string $kind       'tag' or 'list'
     * @param bool   $autoCreate Create refs that match nothing. Caller must
     *                           have checked `fcrm_manage_contact_cats`.
     * @return array{ids: int[], created: array<int, array{id:int,title:string}>, unknown: array}
     */
    public static function resolveSegmentRefs($items, $kind = 'tag', $autoCreate = false)
    {
        $isList     = $kind === 'list';
        $modelClass = $isList ? Lists::class : Tag::class;

        // Read-only lookups are memoized per request. Creating rows bypasses and invalidates the memo.
        $cacheKey = null;
        if (!$autoCreate) {
            $refs = [];
            foreach ((array) $items as $item) {
                if (!is_array($item) && !is_object($item)) {
                    $refs[] = (string) $item;
                }
            }
            sort($refs);
            $cacheKey = $kind . '|' . implode("\0", $refs);
            if (isset(self::$segmentRefCache[$cacheKey])) {
                return self::$segmentRefCache[$cacheKey];
            }
        }

        // Numeric refs match by id; everything else matches title or slug.
        $numericRefs = [];
        $nameRefs    = [];

        foreach ((array) $items as $item) {
            if ($item === '' || $item === null || is_array($item) || is_object($item)) {
                continue;
            }

            if (is_numeric($item)) {
                $numericRefs[(int) $item] = $item;
                continue;
            }

            $value = sanitize_text_field((string) $item);
            if ($value === '') {
                continue;
            }
            $nameRefs[$value] = sanitize_title($value);
        }

        $ids     = [];
        $created = [];
        $unknown = [];
        // segmentRefKey(ref) => resolved id.
        $map = [];

        if ($numericRefs) {
            $foundIds = [];
            foreach ($modelClass::whereIn('id', array_keys($numericRefs))->get() as $row) {
                $foundIds[] = (int) $row->id;
            }
            foreach ($numericRefs as $id => $original) {
                if (in_array($id, $foundIds, true)) {
                    $ids[] = $id;
                    $map[self::segmentRefKey($original)] = $id;
                } else {
                    $unknown[] = $original;
                }
            }
        }

        if ($nameRefs) {
            $titles = array_keys($nameRefs);
            $slugs  = array_values($nameRefs);

            $rows = $modelClass::where(function ($query) use ($titles, $slugs) {
                $query->whereIn('title', $titles)->orWhereIn('slug', $slugs);
            })->get();

            // Titles are lowercased to match the case-insensitive column collation.
            $byTitle = [];
            $bySlug  = [];
            foreach ($rows as $row) {
                $byTitle[strtolower((string) $row->title)] = (int) $row->id;
                $bySlug[(string) $row->slug]               = (int) $row->id;
            }

            $missing = [];
            foreach ($nameRefs as $value => $slug) {
                $lower = strtolower((string) $value);
                if (isset($byTitle[$lower])) {
                    $ids[] = $byTitle[$lower];
                    $map[self::segmentRefKey($value)] = $byTitle[$lower];
                } elseif (isset($bySlug[$slug])) {
                    $ids[] = $bySlug[$slug];
                    $map[self::segmentRefKey($value)] = $bySlug[$slug];
                } elseif ($autoCreate) {
                    $missing[] = $value;
                } else {
                    $unknown[] = $value;
                }
            }

            // Core's create helpers re-check by title, so a concurrent insert returns the existing row.
            if ($missing) {
                $newIds = $isList
                    ? Helper::createNewLists($missing)
                    : Helper::createNewTags($missing);

                $newIds = array_values(array_filter(array_map('intval', (array) $newIds)));

                if ($newIds) {
                    // Read titles back: the helper omits failed inserts, so $missing cannot be paired positionally.
                    foreach ($modelClass::whereIn('id', $newIds)->get() as $row) {
                        $ids[]     = (int) $row->id;
                        $created[] = ['id' => (int) $row->id, 'title' => $row->title];
                        $map[self::segmentRefKey($row->title)] = (int) $row->id;
                        $map[self::segmentRefKey($row->slug)]  = (int) $row->id;
                    }
                }
            }
        }

        $result = [
            'ids'     => array_values(array_unique($ids)),
            'created' => $created,
            'unknown' => array_values(array_unique($unknown)),
            'map'     => $map,
        ];

        if ($created) {
            self::$segmentRefCache = [];
        } elseif ($cacheKey !== null) {
            self::$segmentRefCache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * Drop the resolveSegmentRefs() memo. Call after creating, renaming or deleting tags/lists.
     */
    public static function flushSegmentRefCache()
    {
        self::$segmentRefCache = [];
    }

    /**
     * Normalize a tag/list reference into the key used by resolveSegmentRefs()'s `map`.
     *
     * @param mixed $ref
     * @return string
     */
    public static function segmentRefKey($ref)
    {
        if (is_numeric($ref)) {
            return (string) (int) $ref;
        }

        return strtolower(sanitize_text_field((string) $ref));
    }

    /**
     * Error for an oversized tags[]/lists[] array. Refused rather than truncated.
     *
     * @param string $property 'tags' or 'lists'
     * @param int    $received
     * @return \WP_Error
     */
    private static function tooManySegmentRefsError($property, $received)
    {
        return self::error('invalid_param', sprintf(
            '"%1$s" accepts at most %2$d references per call.',
            $property,
            self::MAX_SEGMENT_REFS
        ), [
            'property' => $property,
            'max_refs' => self::MAX_SEGMENT_REFS,
            'received' => $received,
        ]);
    }

    /**
     * Resolve tag identifiers (ids, titles or slugs) to IDs. Wrapper over resolveSegmentRefs().
     *
     * @param array $items
     * @param bool  $autoCreate
     * @return array{ids: int[], created: array<int, array{id:int,title:string}>, unknown: array}
     */
    public static function resolveTagIds($items, $autoCreate = false)
    {
        return self::resolveSegmentRefs($items, 'tag', $autoCreate);
    }

    /**
     * Same as resolveTagIds() but for lists.
     *
     * @param array $items
     * @param bool  $autoCreate
     * @return array{ids: int[], created: array<int, array{id:int,title:string}>, unknown: array}
     */
    public static function resolveListIds($items, $autoCreate = false)
    {
        return self::resolveSegmentRefs($items, 'list', $autoCreate);
    }

    /**
     * Return the tag/list references that resolve to nothing.
     *
     * @param array  $items
     * @param string $kind 'tag' or 'list'
     * @return array Unresolved references, as passed by the caller.
     */
    public static function unresolvedSegmentRefs($items, $kind = 'tag')
    {
        $resolved = self::resolveSegmentRefs($items, $kind, false);
        return $resolved['unknown'];
    }

    /**
     * Normalize an email list into deduped, lowercased addresses.
     * Lowercased so callers can diff requested against returned addresses.
     *
     * @param mixed $items
     * @return string[]
     */
    public static function normalizeEmailList($items)
    {
        $emails = [];
        foreach ((array) $items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $email = sanitize_email(trim((string) $item));
            if ($email && is_email($email)) {
                $emails[] = strtolower($email);
            }
        }
        return array_values(array_unique($emails));
    }

    // ---------------------------------------------------------------------
    // Formatting
    // ---------------------------------------------------------------------

    /**
     * Build the rich contact record consumed by get-contact / upsert-contact.
     *
     * @param Subscriber $subscriber
     * @param array      $opts {
     *     @type array $include One or more of: notes, email_history, automations,
     *                          activity, purchase_history, support_tickets,
     *                          info_widgets.
     * }
     * @return array
     */
    public static function formatContactForMCP($subscriber, $opts = [])
    {
        $include = (array) ($opts['include'] ?? []);

        $address = [
            'line_1'      => $subscriber->address_line_1,
            'line_2'      => $subscriber->address_line_2,
            'city'        => $subscriber->city,
            'state'       => $subscriber->state,
            'postal_code' => $subscriber->postal_code,
            'country'     => $subscriber->country,
        ];

        $data = [
            'id'              => (int) $subscriber->id,
            'email'           => $subscriber->email,
            'first_name'      => $subscriber->first_name,
            'last_name'       => $subscriber->last_name,
            'full_name'       => trim((string) $subscriber->full_name),
            'prefix'          => $subscriber->prefix,
            'status'          => $subscriber->status,
            'contact_type'    => $subscriber->contact_type,
            'phone'           => $subscriber->phone,
            'address'         => array_filter($address, function ($v) { return $v !== null && $v !== ''; }),
            'date_of_birth'   => $subscriber->date_of_birth,
            'timezone'        => $subscriber->timezone,
            'source'          => $subscriber->source,
            'avatar'          => $subscriber->avatar,
            // life_time_value is omitted: the column is never written and always reads 0.
            'total_points'    => isset($subscriber->total_points) ? (int) $subscriber->total_points : 0,
            'last_activity'   => self::toIso8601($subscriber->last_activity),
            'created_at'      => self::toIso8601($subscriber->created_at),
        ];

        $data['tags']  = self::formatTagList($subscriber->tags ?? []);
        $data['lists'] = self::formatListList($subscriber->lists ?? []);

        $data['custom_fields'] = self::customFieldsFor($subscriber);

        if ($subscriber->user_id) {
            $data['wp_user'] = [
                'id'       => (int) $subscriber->user_id,
                'edit_url' => admin_url('user-edit.php?user_id=' . (int) $subscriber->user_id),
            ];
            $user = get_user_by('ID', $subscriber->user_id);
            if ($user) {
                $data['wp_user']['roles'] = (array) $user->roles;
            }
        } else {
            $data['wp_user'] = null;
        }

        if (in_array('notes', $include, true)) {
            $data['notes'] = self::formatNotesFor($subscriber, $opts);
        }
        if (in_array('email_history', $include, true)) {
            $data['email_history'] = self::formatEmailHistoryFor($subscriber, (int) ($opts['email_history_limit'] ?? 10));
        }
        if (in_array('automations', $include, true)) {
            $data['automations'] = self::formatAutomationsFor($subscriber, (int) ($opts['automations_limit'] ?? 25));
        }

        return $data;
    }

    public static function formatContactSummary($subscriber)
    {
        return [
            'id'            => (int) $subscriber->id,
            'email'         => $subscriber->email,
            'first_name'    => $subscriber->first_name,
            'last_name'     => $subscriber->last_name,
            'full_name'     => trim((string) $subscriber->full_name),
            'status'        => $subscriber->status,
            'contact_type'  => $subscriber->contact_type,
            'tags'          => self::formatTagList($subscriber->tags ?? []),
            'lists'         => self::formatListList($subscriber->lists ?? []),
            'country'       => $subscriber->country,
            'city'          => $subscriber->city,
            'source'        => $subscriber->source,
            'last_activity' => self::toIso8601($subscriber->last_activity),
            'created_at'    => self::toIso8601($subscriber->created_at),
        ];
    }

    /**
     * Custom-field values for one contact, preferring the batch-loaded attribute.
     * Reads the attribute bag directly: `$subscriber->custom_fields` throws when
     * not preloaded because the ORM treats it as a relation.
     *
     * @return array
     */
    public static function customFieldsFor($subscriber)
    {
        $attributes = method_exists($subscriber, 'getAttributes') ? $subscriber->getAttributes() : [];
        if (isset($attributes['custom_fields']) && is_array($attributes['custom_fields'])) {
            return $attributes['custom_fields'];
        }

        return (array) $subscriber->custom_fields();
    }

    public static function formatContactList($paginated, $includeCustomFields = false)
    {
        $items = [];
        foreach ($paginated->items() as $subscriber) {
            $item = self::formatContactSummary($subscriber);
            if ($includeCustomFields) {
                $item['custom_fields'] = self::customFieldsFor($subscriber);
            }
            $items[] = $item;
        }

        return [
            'items'    => $items,
            'total'    => (int) $paginated->total(),
            'page'     => (int) $paginated->currentPage(),
            'per_page' => (int) $paginated->perPage(),
            'pages'    => (int) $paginated->lastPage(),
        ];
    }

    public static function formatTagList($tags)
    {
        $out = [];
        foreach ($tags as $tag) {
            $out[] = [
                'id'    => (int) $tag->id,
                'title' => $tag->title,
                'slug'  => $tag->slug,
            ];
        }
        return $out;
    }

    public static function formatListList($lists)
    {
        $out = [];
        foreach ($lists as $list) {
            $out[] = [
                'id'    => (int) $list->id,
                'title' => $list->title,
                'slug'  => $list->slug,
            ];
        }
        return $out;
    }

    /**
     * Shape a rich-text body for MCP output with bounded token cost.
     *
     * @param string $html     Raw stored HTML/rich text.
     * @param string $format   'text' (default), 'html', or 'both'.
     * @param int    $maxChars When > 0, clip each representation to this many characters.
     * @return array Keyed fields ready to merge into the output object.
     */
    public static function bodyShapeFor($html, $format = 'text', $maxChars = 0)
    {
        $html     = (string) $html;
        $format   = in_array($format, ['text', 'html', 'both'], true) ? $format : 'text';
        $maxChars = (int) $maxChars;

        $out            = [];
        $truncated      = false;
        $originalLength = 0;

        if ($format === 'text' || $format === 'both') {
            $text = self::htmlToText($html);
            $len  = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            if ($maxChars > 0 && $len > $maxChars) {
                $text           = function_exists('mb_substr') ? mb_substr($text, 0, $maxChars) : substr($text, 0, $maxChars);
                $truncated      = true;
                $originalLength = max($originalLength, $len);
            }
            $out['description_text'] = $text;
        }

        if ($format === 'html' || $format === 'both') {
            $htmlOut = $html;
            $len     = function_exists('mb_strlen') ? mb_strlen($htmlOut) : strlen($htmlOut);
            if ($maxChars > 0 && $len > $maxChars) {
                $htmlOut        = function_exists('mb_substr') ? mb_substr($htmlOut, 0, $maxChars) : substr($htmlOut, 0, $maxChars);
                $truncated      = true;
                $originalLength = max($originalLength, $len);
            }
            $out['description_html'] = $htmlOut;
        }

        $out['truncated'] = $truncated;
        if ($truncated) {
            $out['original_length'] = $originalLength;
        }

        return $out;
    }

    /**
     * @param object $note
     * @param array  $opts { @type string $body_format; @type int $note_body_max_chars }
     */
    public static function formatNoteForMCP($note, $opts = [])
    {
        $addedBy   = null;
        $createdBy = method_exists($note, 'createdBy') ? $note->createdBy() : null;
        if (is_array($createdBy)) {
            $addedBy = [
                'id'   => (int) $createdBy['ID'],
                'name' => $createdBy['display_name'],
            ];
        }

        $format   = isset($opts['body_format']) ? (string) $opts['body_format'] : 'text';
        $maxChars = isset($opts['note_body_max_chars']) ? (int) $opts['note_body_max_chars'] : 800;

        return array_merge([
            'id'            => (int) $note->id,
            'subscriber_id' => (int) $note->subscriber_id,
            'type'          => $note->type,
            'title'         => $note->title,
        ], self::bodyShapeFor($note->description, $format, $maxChars), [
            'added_by'   => $addedBy,
            'created_at' => self::toIso8601($note->created_at),
        ]);
    }

    /**
     * Return recent notes for a subscriber.
     *
     * @param object $subscriber
     * @param array  $opts { @type int $notes_limit (default 10, max 50);
     *                       @type string $body_format; @type int $note_body_max_chars }
     */
    public static function formatNotesFor($subscriber, $opts = [])
    {
        $limit = isset($opts['notes_limit']) ? (int) $opts['notes_limit'] : 10;
        $limit = max(1, min($limit, 50));

        // SubscriberNote's global scope excludes company notes and system logs.
        $notes = SubscriberNote::where('subscriber_id', $subscriber->id)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();

        $formatted = [];
        foreach ($notes as $note) {
            $formatted[] = self::formatNoteForMCP($note, $opts);
        }
        return $formatted;
    }

    /**
     * Recent campaign emails sent to a subscriber.
     */
    public static function formatEmailHistoryFor($subscriber, $limit = 10)
    {
        $emails = $subscriber->campaignEmails()
            ->orderBy('id', 'DESC')
            ->limit(max(1, (int) $limit))
            ->get();

        $out = [];
        foreach ($emails as $email) {
            $out[] = [
                'id'             => (int) $email->id,
                'subject'        => $email->email_subject,
                'campaign_id'    => $email->campaign_id ? (int) $email->campaign_id : null,
                'campaign_title' => $email->campaign ? $email->campaign->title : null,
                'status'         => $email->status,
                'is_open'        => !empty($email->is_open),
                'is_clicked'     => isset($email->click_counter) ? ((int) $email->click_counter > 0) : false,
                'sent_at'        => self::toIso8601($email->updated_at),
            ];
        }
        return $out;
    }

    /**
     * Recent automation enrollments for a subscriber.
     *
     * @param object $subscriber
     * @param int    $limit default 25, max 100
     */
    public static function formatAutomationsFor($subscriber, $limit = 25)
    {
        $limit       = max(1, min((int) $limit, 100));
        $automations = $subscriber->funnel_subscribers()->with('funnel')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($automations as $row) {
            if (!$row->funnel) {
                continue;
            }
            $out[] = [
                'funnel_id'         => (int) $row->funnel_id,
                'title'             => $row->funnel->title,
                'status'            => $row->status,
                'last_executed_at'  => self::toIso8601($row->last_executed_time),
                'next_scheduled_at' => self::toIso8601($row->next_execution_time),
                // next_step_id is the fc_funnel_sequences row id (advance_to_step_id takes it);
                // next_step_position is its run-order position (get-automation steps[].position).
                'next_step_id'       => $row->next_sequence_id ? (int) $row->next_sequence_id : null,
                'next_step_position' => $row->next_sequence ? (int) $row->next_sequence : null,
                'enrolled_at'       => self::toIso8601($row->created_at),
            ];
        }
        return $out;
    }

    public static function formatCampaignSummary($campaign, $includeStats = true)
    {
        // sent_at stays null for drafts and pre-send states; updated_at stands in once sending has started.
        $sentStatuses = ['archived', 'working', 'paused'];
        $sentAt = in_array($campaign->status, $sentStatuses, true)
            ? self::toIso8601($campaign->updated_at)
            : null;

        $item = [
            'id'              => (int) $campaign->id,
            'title'           => $campaign->title,
            'email_subject'   => $campaign->email_subject,
            'status'          => $campaign->status,
            'design_template' => $campaign->design_template,
            'scheduled_at'    => self::toIso8601($campaign->scheduled_at),
            'sent_at'         => $sentAt,
            'created_at'      => self::toIso8601($campaign->created_at),
        ];

        // One-off sends never advance `status`, so status/sent_at are not lifecycle values for them.
        if ($campaign->type === 'custom_email_campaign') {
            $item['kind'] = 'one_off_email';
            $item['note'] = 'One-off send: status/sent_at are not lifecycle values. Call get-campaign for one_off_status and the real sent_at.';
            $item['title'] = self::oneOffTitleForOutput($campaign->title);
        }

        if ($includeStats) {
            $item['stats'] = self::campaignStatsCompact($campaign);
        }

        return $item;
    }

    /**
     * Redact a one-off campaign title for callers without contact-read.
     * One-off titles can carry the recipient address, so the whole title is replaced.
     *
     * @param string $title
     * @return string
     */
    public static function oneOffTitleForOutput($title)
    {
        if (PermissionManager::currentUserCan('fcrm_read_contacts')) {
            return (string) $title;
        }

        return 'One-off email (title hidden: requires contact read permission)';
    }

    /**
     * Compact stats for a single campaign. Unsubscribers come from fc_campaign_url_metrics.
     * Anonymous tracking reads aggregate counts from campaign meta.
     */
    public static function campaignStatsCompact($campaign)
    {
        $campaignId = (int) $campaign->id;
        $total      = (int) $campaign->recipients_count;

        $clickStatus = method_exists($campaign, 'getClickTrackingStatus') ? $campaign->getClickTrackingStatus(false) : 'yes';
        $openStatus  = method_exists($campaign, 'getOpenTrackingStatus') ? $campaign->getOpenTrackingStatus(false) : 'yes';

        $row = fluentCrmDb()->table('fc_campaign_emails')
            ->where('campaign_id', $campaignId)
            ->selectRaw("SUM(CASE WHEN status IN ('sent', 'bounced') THEN 1 ELSE 0 END) as sent")
            ->selectRaw("SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END) as views")
            ->selectRaw("SUM(CASE WHEN click_counter IS NOT NULL THEN 1 ELSE 0 END) as clicks")
            ->first();

        $sent   = (int) ($row->sent ?? 0);
        $views  = (int) ($row->views ?? 0);
        $clicks = (int) ($row->clicks ?? 0);

        // Anonymous tracking: opens are an int, clicks a url => count map in campaign meta.
        if ($openStatus === 'anonymous') {
            $views = (int) fluentcrm_get_campaign_meta($campaignId, '_ano_open_count', true);
        }
        if ($clickStatus === 'anonymous') {
            $rawUrlClicks = fluentcrm_get_campaign_meta($campaignId, '_ano_url_clicks', true);
            if (is_array($rawUrlClicks)) {
                $clicks = (int) array_sum(array_filter($rawUrlClicks, 'is_numeric'));
            }
        }

        $unsubs = (int) fluentCrmDb()->table('fc_campaign_url_metrics')
            ->where('campaign_id', $campaignId)
            ->where('type', 'unsubscribe')
            ->distinct()
            ->count('subscriber_id');

        return [
            'total'         => $total,
            'sent'          => $sent,
            'views'         => $views,
            'clicks'        => $clicks,
            'unsubscribers' => $unsubs,
            // Anonymous totals count every pixel load and click, so they are not a rate of emails sent.
            'open_rate'     => $openStatus === 'anonymous' ? null : ($sent ? round($views / max(1, $sent) * 100, 2) : 0),
            'click_rate'    => $clickStatus === 'anonymous' ? null : ($sent ? round($clicks / max(1, $sent) * 100, 2) : 0),
            'opens_are_totals'  => $openStatus === 'anonymous',
            'clicks_are_totals' => $clickStatus === 'anonymous',
            'tracking_mode' => [
                'opens'  => $openStatus,
                'clicks' => $clickStatus,
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Filter translation
    // ---------------------------------------------------------------------

    /**
     * Translate the universal MCP filter shape into ContactsQuery args.
     */
    public static function buildContactsQueryArgs($filter)
    {
        $filter = (array) $filter;
        $args   = [];

        if (!empty($filter['search'])) {
            $args['search'] = sanitize_text_field((string) $filter['search']);
            // The tool contract promises search over custom-field values too.
            $args['custom_fields'] = true;
            $args['search_custom_fields'] = true;
        }

        if (!empty($filter['tags'])) {
            $resolved = self::resolveTagIds((array) $filter['tags']);
            $args['tags'] = $resolved['ids'];
        }

        if (!empty($filter['lists'])) {
            $resolved = self::resolveListIds((array) $filter['lists']);
            $args['lists'] = $resolved['ids'];
        }

        if (!empty($filter['statuses'])) {
            $args['statuses'] = array_values(array_filter(
                array_map('sanitize_text_field', (array) $filter['statuses'])
            ));
        }

        if (!empty($filter['contact_ids'])) {
            $args['contact_ids'] = array_values(array_filter(array_map('intval', (array) $filter['contact_ids'])));
        }

        // Fail closed: an empty emails arg would match every contact.
        if (!empty($filter['emails'])) {
            $emails = self::normalizeEmailList($filter['emails']);
            if ($emails) {
                $args['emails'] = $emails;
            } else {
                $args['contact_ids'] = [0];
            }
        }

        // contact_type must stay a direct arg: the advanced branch ignores simple tags/lists/statuses.
        if (!empty($filter['contact_type'])) {
            $args['contact_type'] = sanitize_text_field((string) $filter['contact_type']);
        }

        // Fail closed on invalid advanced filters: contact_ids [0] matches nothing.
        $advanced = [];
        if (!empty($filter['advanced_filters'])) {
            $normalized = AdvancedFilters::normalize($filter['advanced_filters']);
            if (is_wp_error($normalized)) {
                $args['contact_ids'] = [0];
            } else {
                $advanced = $normalized['groups'];
            }
        }

        // Date ranges are applied by applyDateFilters(), not advanced_filters.

        if (!empty($advanced)) {
            $args['filter_type']        = 'advanced';
            $args['filters_groups_raw'] = $advanced;
        }

        // All fc_subscribers columns. orderBy() throws on an invalid column name.
        $allowedSortBy = [
            'id', 'user_id', 'hash', 'contact_owner', 'company_id', 'prefix',
            'first_name', 'last_name', 'email', 'timezone', 'address_line_1',
            'address_line_2', 'postal_code', 'city', 'state', 'country', 'ip',
            'latitude', 'longitude', 'total_points', 'life_time_value', 'phone',
            'status', 'contact_type', 'source', 'avatar', 'date_of_birth',
            'created_at', 'last_activity', 'updated_at',
        ];
        $sortBy = sanitize_key((string) ($filter['sort_by'] ?? 'id'));
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'id';
        }
        $args['sort_by'] = $sortBy;
        $sortType = strtoupper(sanitize_text_field((string) ($filter['sort_type'] ?? 'DESC')));
        $args['sort_type'] = in_array($sortType, ['ASC', 'DESC'], true) ? $sortType : 'DESC';

        if (isset($filter['custom_fields']) && $filter['custom_fields']) {
            $args['custom_fields'] = true;
        }

        return $args;
    }

    /**
     * Normalize a date boundary (created_after / created_before) into the
     * site-local `Y-m-d H:i:s` the datetime columns hold. Returns null when absent.
     * Offsets are converted to site time; MySQL below 8.0.19 cannot parse them.
     *
     * @return string|null|\WP_Error
     */
    public static function normalizeDateBoundary($value, $paramName)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        // Match the whole string so relative phrases like "yesterday 09:30Z" are rejected.
        $isoWithTz = (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $raw);
        $isoNaive  = (bool) preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $raw);

        if (!$isoWithTz && !$isoNaive) {
            return self::dateBoundaryError($paramName, $raw);
        }
        $hasTz = $isoWithTz;

        // DateTime rolls over invalid dates and times instead of failing, so range-check them.
        preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $d);
        if (!checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            return self::dateBoundaryError($paramName, $raw);
        }

        $h = $i = $s = 0;
        if (preg_match('/[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $raw, $t)) {
            $h = (int) $t[1];
            $i = (int) $t[2];
            $s = isset($t[3]) ? (int) $t[3] : 0;
            if ($h > 23 || $i > 59 || $s > 59) {
                return self::dateBoundaryError($paramName, $raw);
            }
        }

        $siteTz = self::siteTimezoneObject();
        try {
            $dt = $hasTz ? new \DateTime($raw) : new \DateTime($raw, $siteTz);
        } catch (\Exception $e) {
            return self::dateBoundaryError($paramName, $raw);
        }

        // Reject naive times inside a DST gap, which DateTime silently shifts an hour.
        if (!$hasTz) {
            $expected = sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int) $d[1], (int) $d[2], (int) $d[3], $h, $i, $s);
            if ($dt->format('Y-m-d H:i:s') !== $expected) {
                return self::dateBoundaryError($paramName, $raw);
            }
        }

        $dt->setTimezone($siteTz);

        return $dt->format('Y-m-d H:i:s');
    }

    private static function dateBoundaryError($paramName, $raw)
    {
        return self::error('invalid_param', sprintf(
            '%s must be a date: YYYY-MM-DD, "YYYY-MM-DD HH:MM:SS" (site timezone), or a full ISO 8601 timestamp with an offset. Refusing: an unparseable value would reach the database verbatim and fail the whole call.',
            $paramName
        ), [
            'param'         => $paramName,
            'received'      => $raw,
            'site_timezone' => self::siteTimezoneName(),
        ]);
    }

    /**
     * Apply created_after / created_before to a ContactsQuery or a query builder.
     */
    public static function applyDateFilters($queryOrCq, $filter)
    {
        if (!is_array($filter)) {
            return $queryOrCq;
        }
        $query = method_exists($queryOrCq, 'getModel') ? $queryOrCq->getModel() : $queryOrCq;
        if (!is_object($query)) {
            return $queryOrCq;
        }

        $map = ['created_after' => '>=', 'created_before' => '<='];
        foreach ($map as $key => $operator) {
            if (empty($filter[$key])) {
                continue;
            }
            $value = self::normalizeDateBoundary($filter[$key], $key);
            // Fail closed: dropping a date bound would widen the match.
            if (is_wp_error($value)) {
                $query->whereRaw('1 = 0');
                continue;
            }
            if ($value !== null) {
                $query->where('created_at', $operator, $value);
            }
        }

        return $queryOrCq;
    }

    /**
     * Build a ContactsQuery from the universal filter shape.
     * Callers that paginate must run paginationFromInput() first.
     */
    public static function buildContactsQuery($filter)
    {
        $args = self::buildContactsQueryArgs($filter);
        return new ContactsQuery($args);
    }

    /**
     * Validate the universal-filter shape. Returns true or an invalid_param WP_Error.
     * Fails closed: an unmatched value would otherwise widen the result set.
     */
    public static function validateUniversalFilter($filter)
    {
        if (!is_array($filter) || empty($filter)) {
            return true;
        }

        if (!empty($filter['statuses']) && is_array($filter['statuses'])) {
            $allowed = fluentcrm_subscriber_statuses();
            $bad = array_values(array_filter(
                array_map(function ($s) {
                    return is_scalar($s) ? sanitize_text_field((string) $s) : '';
                }, $filter['statuses']),
                function ($s) {
                    return $s !== '' && Sanitize::contactStatus($s) === null;
                }
            ));
            if (!empty($bad)) {
                return self::error('invalid_param', 'statuses contains values not in the contact-status enum. Refusing: silently ignoring would widen the audience instead of narrowing it.', [
                    'unknown_statuses' => $bad,
                    'allowed_statuses' => array_values($allowed),
                ]);
            }
        }

        if (!empty($filter['contact_type'])) {
            $allowed = array_keys(fluentcrm_contact_types());
            $value = is_scalar($filter['contact_type']) ? sanitize_text_field((string) $filter['contact_type']) : '';
            if (Sanitize::contactType($value) === null) {
                return self::error('invalid_param', 'contact_type is not a registered type.', [
                    'unknown_contact_type' => $value,
                    'allowed_contact_types' => $allowed,
                ]);
            }
        }

        // Every tag/list ref must resolve: an empty resolved set means "no filter".
        if (!empty($filter['tags']) && is_array($filter['tags'])) {
            if (count($filter['tags']) > self::MAX_SEGMENT_REFS) {
                return self::tooManySegmentRefsError('tags', count($filter['tags']));
            }
            $unknown = self::unresolvedSegmentRefs($filter['tags'], 'tag');
            if ($unknown) {
                return self::error('invalid_param', 'tags contains references that do not match any existing tag by id, title, or slug. Refusing: an unmatched tag filter would silently widen to the full contact list.', [
                    'unknown_tags' => $unknown,
                    'tip'          => 'Call list-tags for the full registry (get-crm-context include=["segments"] returns only the first 50 tags).',
                ]);
            }
        }
        if (!empty($filter['lists']) && is_array($filter['lists'])) {
            if (count($filter['lists']) > self::MAX_SEGMENT_REFS) {
                return self::tooManySegmentRefsError('lists', count($filter['lists']));
            }
            $unknown = self::unresolvedSegmentRefs($filter['lists'], 'list');
            if ($unknown) {
                return self::error('invalid_param', 'lists contains references that do not match any existing list by id, title, or slug. Refusing: an unmatched list filter would silently widen to the full contact list.', [
                    'unknown_lists' => $unknown,
                    'tip'           => 'Call list-lists for the full registry (get-crm-context include=["segments"] returns only the first 50 lists).',
                ]);
            }
        }

        // Every email must be valid: an empty normalized set means "no filter".
        if (!empty($filter['emails'])) {
            if (!is_array($filter['emails'])) {
                return self::error('invalid_param', 'emails must be an array of email addresses.');
            }

            if (count($filter['emails']) > self::MAX_EMAIL_LOOKUP) {
                return self::error('invalid_param', sprintf(
                    'emails accepts at most %d addresses per call. Split the batch across calls.',
                    self::MAX_EMAIL_LOOKUP
                ), ['max_emails' => self::MAX_EMAIL_LOOKUP, 'given' => count($filter['emails'])]);
            }

            $bad = array_values(array_filter($filter['emails'], function ($item) {
                return !is_scalar($item) || !self::normalizeEmailList([$item]);
            }));
            if ($bad) {
                return self::error('invalid_param', 'emails contains entries that are not valid email addresses. Refusing: dropping them would widen the query to every contact instead of narrowing it.', [
                    'invalid_emails' => array_map(function ($item) {
                        return is_scalar($item) ? (string) $item : gettype($item);
                    }, $bad),
                ]);
            }
        }

        foreach (['created_after', 'created_before'] as $dateKey) {
            if (empty($filter[$dateKey])) {
                continue;
            }
            $normalized = self::normalizeDateBoundary($filter[$dateKey], $dateKey);
            if (is_wp_error($normalized)) {
                return $normalized;
            }
        }

        $original = $filter['advanced_filters'] ?? null;
        if (!empty($original) && is_array($original)) {
            // ContactsQuery applies either advanced groups or tags/lists/statuses, never both.
            $conflicting = array_values(array_filter(
                ['tags', 'lists', 'statuses'],
                function ($key) use ($filter) {
                    return !empty($filter[$key]);
                }
            ));
            if ($conflicting) {
                return self::error('invalid_param', 'advanced_filters cannot be combined with tags/lists/statuses: the query engine applies one or the other, so the simple filters would be silently ignored. Express the constraint inside advanced_filters instead (e.g. {property: "segment.tags", operator: "in", value: [tag ids or slugs]}) or drop advanced_filters. search, contact_type, and created_after/before DO combine with advanced_filters.', [
                    'conflicting_fields' => $conflicting,
                    'tip'                => 'Call get-contact-filter-schema for the full advanced_filters reference.',
                ]);
            }

            $normalized = AdvancedFilters::normalize($original);
            if (is_wp_error($normalized)) {
                return $normalized;
            }
        }
        return true;
    }

    // ---------------------------------------------------------------------
    // Content handling
    // ---------------------------------------------------------------------

    /**
     * Strip HTML tags, decode entities, collapse whitespace. Keeps anchor URLs
     * inline as `[text](url)` so plain-text consumers don't lose them.
     */
    public static function htmlToText($html)
    {
        if (!$html) {
            return '';
        }

        $text = preg_replace_callback(
            '/<a[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)<\/a>/is',
            function ($m) {
                $url   = trim($m[1]);
                $label = trim(wp_strip_all_tags($m[2]));
                if ($label === '' || $label === $url) {
                    return $url;
                }
                return $label . ' (' . $url . ')';
            },
            (string) $html
        );

        // Block boundaries become line breaks and list items get a "- " bullet; stripping
        // tags alone glued "</p><p>" neighbours into one word ("reviewPoint one").
        $text = preg_replace('/<li\b[^>]*>/i', "\n- ", $text);
        $text = preg_replace('/<br\s*\/?>|<\/(?:p|div|li|h[1-6]|tr|blockquote|ul|ol|table|section|article|header|footer|pre)>/i', "$0\n", $text);

        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = preg_replace('/ *\n[\s]*/u', "\n", $text);

        return trim($text);
    }

    public static function detectContentType($body)
    {
        $body = (string) $body;
        // Cheap sniff: an early `<` followed by an ASCII letter signals HTML.
        if (preg_match('/<[a-zA-Z]/', substr($body, 0, 200))) {
            return 'html';
        }
        return 'text';
    }

    public static function dualBodyShape($html, $format = 'both')
    {
        $html   = (string) $html;
        $format = in_array($format, ['text', 'html', 'both'], true) ? $format : 'both';
        $out    = [];
        if ($format === 'html' || $format === 'both') {
            $out['body_html'] = $html;
        }
        if ($format === 'text' || $format === 'both') {
            $out['body_text'] = self::htmlToText($html);
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // TRC-006 — strict nested-input validation
    // ---------------------------------------------------------------------

    /**
     * UTM keys the write tools accept. 'status' is the 0|1 toggle; the rest map to utm_<key>.
     *
     * @return string[]
     */
    public static function utmAllowedKeys()
    {
        return ['status', 'source', 'medium', 'campaign', 'term', 'content'];
    }

    /**
     * Dotted paths of keys in $object not present in $allowed.
     *
     * @return string[] e.g. ['address.zip', 'utm.foo']
     */
    public static function unknownKeys($object, array $allowed, $pathPrefix)
    {
        if (!is_array($object)) {
            return [];
        }
        $unknown = [];
        foreach (array_keys($object) as $key) {
            if (!in_array($key, $allowed, true)) {
                $unknown[] = $pathPrefix . '.' . $key;
            }
        }
        return $unknown;
    }

    /**
     * invalid_param error for unrecognized nested keys.
     * The message carries the details because the MCP adapter drops WP_Error data.
     */
    public static function unknownPropertiesError($unknownPaths, array $allowed, $shapeLabel)
    {
        return self::error('invalid_param', sprintf(
            '%1$s contains unrecognized keys: %2$s. Allowed keys: %3$s. Refusing: unknown keys would otherwise be silently dropped.',
            $shapeLabel,
            implode(', ', $unknownPaths),
            implode(', ', $allowed)
        ), [
            'unknown_properties' => array_values($unknownPaths),
            'allowed'            => array_values($allowed),
        ]);
    }

    /**
     * Validate a closed-shape nested member of $params: an object with no keys outside $allowed.
     * Returns null when valid or absent.
     *
     * @return \WP_Error|null
     */
    public static function validateClosedShape($params, $key, array $allowed)
    {
        if (!array_key_exists($key, $params) || $params[$key] === null) {
            return null;
        }
        if (!is_array($params[$key])) {
            return self::error('invalid_param', sprintf(
                '%s must be an object.',
                $key
            ), ['param' => $key]);
        }
        $unknown = self::unknownKeys($params[$key], $allowed, $key);
        if ($unknown) {
            return self::unknownPropertiesError($unknown, $allowed, $key);
        }
        return null;
    }

    /**
     * invalid_param error: a value has the wrong type. Names the full path.
     */
    public static function invalidTypeError($path, $expected)
    {
        return self::error('invalid_param', sprintf(
            '%1$s must be %2$s.',
            $path,
            $expected
        ), ['param' => $path]);
    }

    /**
     * invalid_param error: a value is outside its enum. Names the full path.
     */
    public static function enumError($path, array $allowed)
    {
        return self::error('invalid_param', sprintf(
            '%1$s must be one of: %2$s.',
            $path,
            implode(', ', $allowed)
        ), ['param' => $path, 'allowed' => $allowed]);
    }

    /**
     * Validate a utm payload: known keys, status bool or 0|1, the rest scalar.
     * Returns null when valid.
     *
     * @return \WP_Error|null
     */
    public static function validateUtm($params)
    {
        if (!array_key_exists('utm', $params) || $params['utm'] === null) {
            return null;
        }
        if (!is_array($params['utm'])) {
            return self::invalidTypeError('utm', 'an object');
        }
        $utm = $params['utm'];
        $unknown = self::unknownKeys($utm, self::utmAllowedKeys(), 'utm');
        if ($unknown) {
            return self::unknownPropertiesError($unknown, self::utmAllowedKeys(), 'utm');
        }
        if (array_key_exists('status', $utm) && $utm['status'] !== null) {
            $s = $utm['status'];
            $ok = is_bool($s)
                || (is_int($s) && ($s === 0 || $s === 1))
                || (is_string($s) && in_array($s, ['0', '1'], true));
            if (!$ok) {
                return self::invalidTypeError('utm.status', 'a boolean or 0|1');
            }
        }
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            if (array_key_exists($key, $utm) && $utm[$key] !== null && !is_scalar($utm[$key])) {
                return self::invalidTypeError('utm.' . $key, 'a string');
            }
        }
        return null;
    }

    /**
     * Validate and sanitize the closed sub-objects of an email/campaign settings payload.
     * Top-level settings and template_config keys stay open.
     *
     * @return array|\WP_Error sanitized settings (possibly empty) or error
     */
    public static function sanitizeSettingsShape($settings)
    {
        if ($settings === null) {
            return [];
        }
        if (!is_array($settings)) {
            return self::invalidTypeError('settings', 'an object');
        }

        if (array_key_exists('mailer_settings', $settings) && $settings['mailer_settings'] !== null) {
            $ms = $settings['mailer_settings'];
            if (!is_array($ms)) {
                return self::invalidTypeError('settings.mailer_settings', 'an object');
            }
            $allowed = ['from_name', 'from_email', 'reply_to_name', 'reply_to_email', 'is_custom'];
            $unknown = self::unknownKeys($ms, $allowed, 'settings.mailer_settings');
            if ($unknown) {
                return self::unknownPropertiesError($unknown, $allowed, 'settings.mailer_settings');
            }
            foreach (['from_name', 'reply_to_name'] as $f) {
                if (array_key_exists($f, $ms) && $ms[$f] !== null) {
                    if (!is_scalar($ms[$f])) {
                        return self::invalidTypeError('settings.mailer_settings.' . $f, 'a string');
                    }
                    $ms[$f] = sanitize_text_field((string) $ms[$f]);
                }
            }
            foreach (['from_email', 'reply_to_email'] as $f) {
                if (array_key_exists($f, $ms) && $ms[$f] !== null && $ms[$f] !== '') {
                    if (!is_scalar($ms[$f])) {
                        return self::invalidTypeError('settings.mailer_settings.' . $f, 'a string');
                    }
                    $email = sanitize_email((string) $ms[$f]);
                    if (!is_email($email)) {
                        return self::error('invalid_param', sprintf(
                            '%s must be a valid email address.',
                            'settings.mailer_settings.' . $f
                        ), ['param' => 'settings.mailer_settings.' . $f]);
                    }
                    $ms[$f] = $email;
                }
            }
            if (array_key_exists('is_custom', $ms) && $ms['is_custom'] !== null
                && !in_array($ms['is_custom'], ['yes', 'no'], true)) {
                return self::enumError('settings.mailer_settings.is_custom', ['yes', 'no']);
            }
            $settings['mailer_settings'] = $ms;
        }

        if (array_key_exists('footer_settings', $settings) && $settings['footer_settings'] !== null) {
            $fs = $settings['footer_settings'];
            if (!is_array($fs)) {
                return self::invalidTypeError('settings.footer_settings', 'an object');
            }
            $allowed = ['disable_footer'];
            $unknown = self::unknownKeys($fs, $allowed, 'settings.footer_settings');
            if ($unknown) {
                return self::unknownPropertiesError($unknown, $allowed, 'settings.footer_settings');
            }
            if (array_key_exists('disable_footer', $fs) && $fs['disable_footer'] !== null
                && !in_array($fs['disable_footer'], ['yes', 'no'], true)) {
                return self::enumError('settings.footer_settings.disable_footer', ['yes', 'no']);
            }
            $settings['footer_settings'] = $fs;
        }

        if (array_key_exists('template_config', $settings) && $settings['template_config'] !== null
            && !is_array($settings['template_config'])) {
            return self::invalidTypeError('settings.template_config', 'an object');
        }

        return $settings;
    }

    // ---------------------------------------------------------------------
    // Pagination
    // ---------------------------------------------------------------------

    /**
     * Normalize page/per_page from input and inject them where the paginator reads them.
     */
    public static function paginationFromInput($input, $defaultPerPage = 15, $maxPerPage = 100)
    {
        $page    = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? $defaultPerPage);
        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        }
        $perPage = min($perPage, $maxPerPage);

        // Model::getPerPage() reads $_REQUEST['per_page'] live at paginate time.
        $_REQUEST['page']     = $page;
        $_REQUEST['per_page'] = $perPage;

        // Paginator::resolveCurrentPage() reads the Request object, snapshotted at boot.
        // all() forces the lazy JSON-body merge first so it cannot overwrite these.
        $request = FluentCrm('request');
        $request->all();
        $request->set('page', $page)->set('per_page', $perPage);

        return ['page' => $page, 'per_page' => $perPage];
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    /**
     * The registered custom-field slugs for contacts.
     *
     * @return string[]
     */
    public static function knownContactCustomFieldSlugs()
    {
        $slugs = [];
        foreach ((array) fluentcrm_get_custom_contact_fields() as $field) {
            if (!empty($field['slug'])) {
                $slugs[] = (string) $field['slug'];
            }
        }
        return $slugs;
    }

    /**
     * Split caller-supplied custom-field keys into registered and unknown.
     * Subscriber::syncCustomFieldValues silently drops unknown keys.
     *
     * @param  array $customFields
     * @return array{known: array<string,mixed>, unknown: string[]}
     */
    public static function diffCustomFields($customFields)
    {
        $known   = [];
        $unknown = [];
        if (!is_array($customFields) || empty($customFields)) {
            return ['known' => $known, 'unknown' => $unknown];
        }
        $allowed = self::knownContactCustomFieldSlugs();
        foreach ($customFields as $key => $value) {
            $slug = sanitize_key((string) $key);
            if ($slug === '') {
                continue;
            }
            if (in_array($slug, $allowed, true)) {
                $known[$slug] = $value;
            } else {
                $unknown[] = (string) $key;
            }
        }
        return ['known' => $known, 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * Parse and validate scheduled_at into a DateTime in the site timezone.
     * An explicit offset is respected; a bare datetime is read as site time.
     */
    public static function validateScheduledAt($iso, $minFutureSeconds = 60)
    {
        if (!$iso) {
            return self::error('invalid_param', 'scheduled_at is required');
        }

        $siteTz = self::siteTimezoneObject();
        $input  = (string) $iso;

        try {
            if (self::stringHasTimezone($input)) {
                $dt = new \DateTime($input);
            } else {
                $dt = new \DateTime($input, $siteTz);
            }
        } catch (\Exception $e) {
            return self::error('invalid_param', 'scheduled_at must be ISO 8601 (e.g. 2026-05-08T09:00:00+01:00) or a bare datetime in site timezone (2026-05-08 09:00:00).', [
                'scheduled_at_input' => $input,
                'site_timezone'      => $siteTz->getName(),
            ]);
        }

        // Stored datetimes are site-local.
        $dt->setTimezone($siteTz);

        if ($dt->getTimestamp() < (time() + $minFutureSeconds)) {
            return self::error('validation_failed', 'scheduled_at must be in the future', [
                'scheduled_at_input' => $input,
                'parsed_utc'         => gmdate('c', $dt->getTimestamp()),
                'parsed_site_local'  => $dt->format('Y-m-d H:i:s'),
                'now_utc'            => gmdate('c'),
                'site_timezone'      => $siteTz->getName(),
                'now_site_local'     => wp_date('Y-m-d H:i:s', time()),
            ]);
        }

        return $dt;
    }

    /**
     * Heuristic: does the string carry timezone info (Z or ±HH:MM / ±HHMM)
     * after the time component? Date-only strings always count as bare.
     */
    private static function stringHasTimezone($s)
    {
        return (bool) preg_match('/T?\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', trim((string) $s));
    }

    /**
     * Site timezone as a DateTimeZone.
     */
    public static function siteTimezoneObject()
    {
        return wp_timezone();
    }

    /**
     * Format a stored site-local datetime as both UTC and site-local values.
     *
     * @return array{utc:?string, site_local:?string, site_timezone:string}|null
     */
    public static function formatScheduledAtDual($value)
    {
        if (!$value) {
            return null;
        }
        $siteTz = self::siteTimezoneObject();

        try {
            if ($value instanceof \DateTimeInterface) {
                $dt = (new \DateTime('@' . $value->getTimestamp()))->setTimezone($siteTz);
            } elseif (self::stringHasTimezone((string) $value)) {
                $dt = (new \DateTime((string) $value))->setTimezone($siteTz);
            } else {
                $dt = new \DateTime((string) $value, $siteTz);
            }
        } catch (\Exception $e) {
            return null;
        }

        return [
            'utc'           => gmdate('c', $dt->getTimestamp()),
            'site_local'    => $dt->format('Y-m-d H:i:s'),
            'site_timezone' => self::siteTimezoneName(),
        ];
    }

    /**
     * Site timezone as a name an agent can use: the IANA zone ("Asia/Dhaka") when the site
     * has one, otherwise "UTC" or a fixed offset ("UTC+05:30"). wp_timezone_string() alone
     * returns a bare "+00:00" for offset-only sites, which agents misread as ambiguous.
     */
    public static function siteTimezoneName()
    {
        $zone = (string) fluentCrmGetTimezoneString();
        if ($zone === '' || preg_match('/^[+-]00:?00$/', $zone)) {
            return 'UTC';
        }
        if (preg_match('/^[+-]\d{2}:?\d{2}$/', $zone)) {
            return 'UTC' . $zone;
        }

        return $zone;
    }

    /**
     * Current UTC offset of the site timezone, as "+06:00".
     */
    public static function siteUtcOffset()
    {
        return (new \DateTime('now', self::siteTimezoneObject()))->format('P');
    }

    /**
     * The name an MCP client calls an ability by: the adapter turns "fluent-crm/get-contact"
     * into "fluent-crm-get-contact".
     */
    public static function toolName($abilityName)
    {
        return str_replace('/', '-', (string) $abilityName);
    }

    public static function permissionGuard($cap)
    {
        if (PermissionManager::currentUserCan($cap)) {
            return true;
        }
        return self::error('forbidden', 'You do not have permission to perform this action', ['required' => $cap]);
    }

    /**
     * Ability permission check that explains a denial. Returns true when the current user
     * holds at least one of $caps, otherwise a `forbidden` error naming them. A bare false
     * would reach the agent as "Permission denied" with nothing to act on.
     *
     * @param string[] $caps
     * @return true|\WP_Error
     */
    public static function requireAnyCap(array $caps)
    {
        foreach ($caps as $cap) {
            if (PermissionManager::currentUserCan($cap)) {
                return true;
            }
        }

        return self::forbiddenError($caps, 'any');
    }

    /**
     * As requireAnyCap(), but every capability in $caps is required.
     *
     * @param string[] $caps
     * @return true|\WP_Error
     */
    public static function requireAllCaps(array $caps)
    {
        $missing = array_values(array_filter($caps, function ($cap) {
            return !PermissionManager::currentUserCan($cap);
        }));

        return $missing ? self::forbiddenError($caps, 'all', $missing) : true;
    }

    private static function forbiddenError(array $caps, $mode, array $missing = [])
    {
        $details = [
            'required_permissions' => array_values($caps),
            'required_mode'        => $mode,
            'next_step'            => 'Your permissions are listed by get-crm-context (you.permissions). Ask a FluentCRM administrator to grant one, or stop.',
        ];
        if ($missing) {
            $details['missing_permissions'] = $missing;
        }

        $message = $mode === 'all'
            ? 'You need all of these FluentCRM permissions for this tool.'
            : 'You need one of these FluentCRM permissions for this tool.';

        return self::error('forbidden', $message, $details);
    }

    // ---------------------------------------------------------------------
    // Errors
    // ---------------------------------------------------------------------

    /**
     * Structured tool error.
     *
     * The MCP adapter forwards only a WP_Error's message to the agent, never its code or
     * data, so the message carries the whole error as JSON:
     * {"error": {"code", "message", "retryable", "details"}}. The code and data stay on the
     * WP_Error for PHP callers. Pass details['retryable'] = true when retrying the same
     * call can succeed.
     */
    public static function error($code, $message, $details = [])
    {
        $details   = (array) $details;
        $retryable = !empty($details['retryable']);
        unset($details['retryable']);

        $payload = [
            'code'      => (string) $code,
            'message'   => (string) $message,
            'retryable' => $retryable,
        ];
        if ($details) {
            $payload['details'] = $details;
        }

        $json = wp_json_encode(['error' => $payload]);

        return new \WP_Error($code, $json ?: (string) $message, $details);
    }

    // ---------------------------------------------------------------------
    // Misc
    // ---------------------------------------------------------------------

    /**
     * Format a stored datetime as ISO 8601. Naive values are read as site time,
     * since PHP's default timezone is UTC under WordPress.
     */
    public static function toIso8601($value)
    {
        if (!$value) {
            return null;
        }

        try {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('c');
            }
            $value = (string) $value;
            $dt = self::stringHasTimezone($value)
                ? new \DateTime($value)
                : new \DateTime($value, self::siteTimezoneObject());

            return $dt->format('c');
        } catch (\Exception $e) {
            return null;
        }
    }
}
