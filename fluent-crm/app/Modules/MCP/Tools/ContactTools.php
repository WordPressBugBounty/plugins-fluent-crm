<?php

namespace FluentCrm\App\Modules\MCP\Tools;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Modules\MCP\Helpers\AdvancedFilters;
use FluentCrm\App\Modules\MCP\Helpers\MCPHelper;
use FluentCrm\App\Services\Sanitize;
use FluentCrm\App\Services\ContactsQuery;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

/**
 * Contact-centric MCP tools.
 */
class ContactTools
{
    /**
     * Ceiling on one bulk row's tags[] / lists[].
     */
    const MAX_SEGMENT_REFS_PER_ROW = 100;

    /**
     * Ceiling on distinct tag+list references across a whole bulk call.
     * Bounds auto_create from minting a segment per value of a mis-mapped column.
     */
    const MAX_BULK_SEGMENT_REFS = 100;

    // -----------------------------------------------------------------
    // Read: list-contacts
    // -----------------------------------------------------------------

    public static function listContacts($params)
    {
        $params = (array) $params;

        $validation = MCPHelper::validateUniversalFilter($params);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $pagination = MCPHelper::paginationFromInput($params);

        $args = MCPHelper::buildContactsQueryArgs($params);
        $args['with'] = ['tags', 'lists'];

        if (!empty($params['include_custom_fields'])) {
            $args['custom_fields'] = true;
        }

        $cq = new ContactsQuery($args);
        MCPHelper::applyDateFilters($cq, $params);
        $paginated = $cq->paginate();

        $out = MCPHelper::formatContactList($paginated, !empty($params['include_custom_fields']));

        // not_found lists requested addresses with no contact record, not ones excluded by other filters.
        if (!empty($params['emails'])) {
            $requested = MCPHelper::normalizeEmailList($params['emails']);
            $existing  = array_map('strtolower', Subscriber::whereIn('email', $requested)->pluck('email')->toArray());
            $missing   = array_values(array_diff($requested, $existing));
            if ($missing) {
                $out['not_found'] = $missing;
            }
        }

        // Non-fatal advisories from advanced_filters normalization. normalize() is memoized.
        if (!empty($params['advanced_filters'])) {
            $normalized = AdvancedFilters::normalize($params['advanced_filters']);
            if (!is_wp_error($normalized) && !empty($normalized['warnings'])) {
                $out['warnings'] = $normalized['warnings'];
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Read: get-contact-filter-schema
    // -----------------------------------------------------------------

    /**
     * Serve the advanced_filters reference on demand.
     */
    public static function getFilterSchema($params = [])
    {
        return AdvancedFilters::schema();
    }

    // -----------------------------------------------------------------
    // Read: get-contact
    // -----------------------------------------------------------------

    public static function getContact($params)
    {
        $params = (array) $params;

        $defaultIncludes = ['notes', 'email_history', 'automations'];
        $allowedIncludes = ['notes', 'email_history', 'automations', 'activity', 'purchase_history', 'support_tickets', 'info_widgets'];
        // Omitted => defaults, [] => metadata only, explicit list => exactly those sections.
        if (array_key_exists('include', $params) && is_array($params['include'])) {
            $include = array_values(array_intersect($params['include'], $allowedIncludes));
        } else {
            $include = $defaultIncludes;
        }

        $bodyFormat = $params['body_format'] ?? 'text';
        if (!in_array($bodyFormat, ['text', 'html', 'both'], true)) {
            $bodyFormat = 'text';
        }
        $profileOpts = [
            'include'             => $include,
            'body_format'         => $bodyFormat,
            'note_body_max_chars' => max(80, min(isset($params['note_body_max_chars']) ? (int) $params['note_body_max_chars'] : 800, 20000)),
            'notes_limit'         => isset($params['notes_limit']) ? (int) $params['notes_limit'] : 10,
            'automations_limit'   => isset($params['automations_limit']) ? (int) $params['automations_limit'] : 25,
            'email_history_limit' => max(1, min(isset($params['email_history_limit']) ? (int) $params['email_history_limit'] : 10, 50)),
        ];

        $contactId = isset($params['contact_id']) ? (int) $params['contact_id'] : 0;
        $email     = isset($params['email']) ? sanitize_email($params['email']) : '';

        $with = ['tags', 'lists'];

        $subscriber = null;
        if ($contactId) {
            $subscriber = Subscriber::with($with)->find($contactId);
        } elseif ($email) {
            $subscriber = Subscriber::with($with)->where('email', $email)->first();
        }

        if (!$subscriber) {
            if (!$contactId && !$email) {
                return MCPHelper::error('invalid_param', 'Provide contact_id or email');
            }
            return MCPHelper::error('not_found', 'Contact not found', array_filter([
                'contact_id' => $contactId ?: null,
                'email'      => $email ?: null,
            ]));
        }

        $data = MCPHelper::formatContactForMCP($subscriber, $profileOpts);

        if (in_array('activity', $include, true)) {
            $data['activity'] = self::buildActivityTimeline($subscriber);
        }

        if (in_array('purchase_history', $include, true)) {
            $data['purchase_history'] = self::buildPurchaseHistory($subscriber);
        }

        if (in_array('support_tickets', $include, true)) {
            $data['support_tickets'] = self::buildSupportTickets($subscriber);
        }

        if (in_array('info_widgets', $include, true)) {
            $data['info_widgets'] = self::buildInfoWidgets($subscriber);
        }

        if (in_array($subscriber->status, ['unsubscribed', 'bounced', 'complained', 'spammed'], true)) {
            $data['unsubscribe_reason'] = method_exists($subscriber, 'unsubscribeReason')
                ? $subscriber->unsubscribeReason()
                : null;
        }

        return $data;
    }

    /**
     * Tracked events for a contact, with an availability marker so "feature off" differs from "no events".
     * Probes for the table first: legacy installs may lack it, and wpdb's print_error leaks HTML.
     */
    private static function buildActivityTimeline($subscriber)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return [
                'available' => false,
                'reason'    => 'event_tracking_disabled',
                'note'      => 'Enable Event Tracking under FluentCRM Settings > Experimental Features to record contact activity.',
                'events'    => [],
            ];
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'fc_event_tracking';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName)) === $tableName;
        if (!$exists) {
            return [
                'available' => false,
                'reason'    => 'event_tracking_table_missing',
                'events'    => [],
            ];
        }

        try {
            $events = $subscriber->trackingEvents()
                ->orderBy('id', 'DESC')
                ->limit(50)
                ->get();
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'reason'    => 'event_tracking_query_failed',
                'events'    => [],
            ];
        }

        $out = [];
        foreach ($events as $event) {
            $out[] = [
                'id'         => (int) $event->id,
                'event_key'  => $event->event_key,
                'title'      => $event->title,
                'value'      => $event->value,
                'provider'   => $event->provider ?? null,
                'counter'    => isset($event->counter) ? (int) $event->counter : null,
                'created_at' => MCPHelper::toIso8601($event->created_at),
            ];
        }

        return [
            'available' => true,
            'events'    => $out,
        ];
    }

    /**
     * Orders across every purchase-history provider, as on the contact-profile Purchase History tab.
     * Reports explicitly when no provider is active.
     */
    private static function buildPurchaseHistory($subscriber)
    {
        $providers = Helper::getPurchaseHistoryProviders();

        if (!$providers) {
            return [
                'available' => false,
                'reason'    => 'no_purchase_history_provider_active',
                'note'      => 'No commerce plugin with a FluentCRM purchase-history integration is active on this install. If orders live in a separate store install, query that store\'s own API/MCP.',
                'providers' => [],
            ];
        }

        $out = [];
        foreach ($providers as $key => $provider) {
            $key = sanitize_key($key);
            if (!$key) {
                continue;
            }

            $data = apply_filters('fluent_crm/purchase_history_' . $key, [
                'data'  => [],
                'total' => 0,
            ], $subscriber);

            // Rows arrive under 'data', or 'orders' for legacy third-party providers.
            $orders = Arr::get($data, 'data', []) ?: Arr::get($data, 'orders', []);

            $out[$key] = [
                'name'   => Arr::get((array) $provider, 'name', $key),
                'total'  => (int) Arr::get($data, 'total', 0),
                'orders' => self::normalizeIntegrationRows($orders),
            ];
        }

        return [
            'available' => true,
            'providers' => $out,
        ];
    }

    /**
     * Tickets across every helpdesk provider, as on the contact-profile Support Tickets tab.
     */
    private static function buildSupportTickets($subscriber)
    {
        $providers = apply_filters('fluentcrm-support_tickets_providers', []);

        if (!$providers || !is_array($providers)) {
            return [
                'available' => false,
                'reason'    => 'no_support_ticket_provider_active',
                'note'      => 'No helpdesk plugin with a FluentCRM ticket integration (e.g. Fluent Support) is active on this install.',
                'providers' => [],
            ];
        }

        $out = [];
        foreach ($providers as $key => $provider) {
            $key = sanitize_key($key);
            if (!$key) {
                continue;
            }

            $data = apply_filters('fluentcrm-get_support_tickets_' . $key, [
                'data'  => [],
                'total' => 0,
            ], $subscriber);

            $out[$key] = [
                'name'    => Arr::get((array) $provider, 'name', $key),
                'total'   => (int) Arr::get($data, 'total', 0),
                'tickets' => self::normalizeIntegrationRows(Arr::get($data, 'data', [])),
            ];
        }

        return [
            'available' => true,
            'providers' => $out,
        ];
    }

    /**
     * Contact-profile sidebar widgets from integrations, flattened to plain text.
     */
    private static function buildInfoWidgets($subscriber)
    {
        $widgets = apply_filters('fluent_crm/subscriber_info_widgets', [], $subscriber);

        $out = [];
        if (is_array($widgets)) {
            foreach ($widgets as $key => $widget) {
                $widget = (array) $widget;
                $entry = array_filter([
                    'key'     => is_string($key) ? $key : null,
                    'title'   => self::htmlToText((string) Arr::get($widget, 'title', '')),
                    'content' => self::htmlToText((string) Arr::get($widget, 'content', '')),
                ]);
                if ($entry) {
                    $out[] = $entry;
                }
            }
        }

        $result = ['widgets' => $out];
        if (!$out) {
            $result['note'] = 'No integration pushed widget data for this contact — either no widget-producing plugin is active, or none had data to report.';
        }
        return $result;
    }

    /**
     * Flatten integration HTML rows into plain-text cells, dropping action cells.
     */
    private static function normalizeIntegrationRows($rows, $limit = 10)
    {
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice(array_values($rows), 0, $limit) as $row) {
            $row = (array) $row;
            $item = [];
            foreach ($row as $key => $value) {
                $key = sanitize_key($key);
                if (in_array($key, ['action', 'actions'], true)) {
                    continue;
                }
                if (!is_scalar($value) && $value !== null) {
                    continue;
                }
                $item[$key] = self::htmlToText((string) $value);
            }
            if ($item) {
                $normalized[] = $item;
            }
        }
        return $normalized;
    }

    /**
     * Strip markup, inserting a space at tag boundaries so adjacent values stay separate.
     */
    private static function htmlToText($html)
    {
        if ($html === '') {
            return '';
        }
        $text = wp_strip_all_tags(str_replace('<', ' <', $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Top-level contact columns the MCP write tools accept. Other fillable columns are never written.
     *
     * @return string[]
     */
    private static function contactWritePassthru()
    {
        return ['first_name', 'last_name', 'prefix', 'phone', 'status', 'contact_type', 'date_of_birth', 'timezone', 'source'];
    }

    // -----------------------------------------------------------------
    // Write: upsert-contact
    // -----------------------------------------------------------------

    public static function upsertContact($params)
    {
        $params = (array) $params;

        $contactId = isset($params['contact_id']) ? (int) $params['contact_id'] : 0;
        $email     = isset($params['email']) ? sanitize_email($params['email']) : '';
        $newEmail  = isset($params['new_email']) ? sanitize_email($params['new_email']) : '';

        if (!$contactId && !$email) {
            return MCPHelper::error('invalid_param', 'Provide contact_id or email');
        }

        $existing = null;
        if ($contactId) {
            $existing = Subscriber::find($contactId);
            if (!$existing) {
                return MCPHelper::error('not_found', 'Contact not found', ['contact_id' => $contactId]);
            }
            // contact_id wins over email.
            $email = $existing->email;
        } else {
            $existing = Subscriber::where('email', $email)->first();
        }

        // new_email renames an existing contact. Without a match it was ignored and a new
        // contact was created at the old address, the opposite of what the caller asked.
        if (!$existing && $newEmail) {
            return MCPHelper::error('not_found', 'No contact has this email, so there is nothing to rename. new_email only changes the address of an existing contact.', [
                'email'     => $email,
                'new_email' => $newEmail,
                'next_step' => 'Find the contact with list-contacts emails=[...] or get-contact, then call again with its contact_id and new_email.',
            ]);
        }

        $ifExists = $params['if_exists'] ?? 'merge';
        if ($existing && $ifExists === 'skip') {
            return [
                'ok'      => true,
                'action'  => 'skipped',
                'contact' => MCPHelper::formatContactForMCP($existing, ['include' => ['notes', 'email_history', 'automations']]),
                'changes' => null,
            ];
        }
        if ($existing && $ifExists === 'error') {
            return MCPHelper::error('contact_exists', 'A contact with this email already exists', [
                'id' => (int) $existing->id,
            ]);
        }

        // Creating tags/lists needs an extra capability.
        $autoCreateTags  = !empty($params['auto_create_tags']);
        $autoCreateLists = !empty($params['auto_create_lists']);
        if (($autoCreateTags || $autoCreateLists)
            && !\FluentCrm\App\Services\PermissionManager::currentUserCan('fcrm_manage_contact_cats')) {
            return MCPHelper::error('forbidden', 'Creating new tags/lists requires fcrm_manage_contact_cats');
        }

        // Validation phase: every rejecting check runs before any write.

        // Invalid status is a hard error here; bulk upsert warns per row instead.
        if (array_key_exists('status', $params) && $params['status'] !== null && $params['status'] !== ''
            && Sanitize::contactStatus($params['status']) === null) {
            return MCPHelper::error('invalid_param', 'status is not a recognized contact status.', [
                'status'  => $params['status'],
                'allowed' => array_values(fluentcrm_subscriber_statuses()),
            ]);
        }

        // Unknown custom-field slugs are rejected rather than silently dropped.
        $customValues = null;
        if (!empty($params['custom_fields']) && is_array($params['custom_fields'])) {
            $diff = MCPHelper::diffCustomFields($params['custom_fields']);
            if (!empty($diff['unknown'])) {
                return MCPHelper::error('invalid_param', 'custom_fields contains slugs not in the contact custom-field schema. Refusing: silently dropping them would make the agent think the values persisted.', [
                    'unknown_custom_field_slugs' => $diff['unknown'],
                    'allowed_custom_field_slugs' => MCPHelper::knownContactCustomFieldSlugs(),
                    'tip' => 'Call get-crm-context with include=["custom_fields"] and read the top-level custom_fields_schema before retrying.',
                ]);
            }
            $customValues = $diff['known'];
        }

        // Checked before any write: a malformed date would wipe the birthday or fail the call.
        if (isset($params['date_of_birth']) && $params['date_of_birth'] !== '' && \FluentCrm\App\Services\Helper::normalizeDateOfBirth($params['date_of_birth']) === false) {
            return MCPHelper::error('invalid_param', 'date_of_birth must be a real date in YYYY-MM-DD.', [
                'received' => (string) $params['date_of_birth'],
            ]);
        }

        $addressError = MCPHelper::validateClosedShape($params, 'address', self::addressAllowedKeys());
        if (is_wp_error($addressError)) {
            return $addressError;
        }

        // Email-rename target must be free.
        if ($existing && $newEmail && $newEmail !== $existing->email) {
            $clash = Subscriber::where('email', $newEmail)->where('id', '!=', $existing->id)->first();
            if ($clash) {
                return MCPHelper::error('contact_exists', 'Another contact already uses the new_email; refusing to merge silently. Resolve manually or pick a different new_email.', [
                    'new_email'   => $newEmail,
                    'conflict_id' => (int) $clash->id,
                    'subject_id'  => (int) $existing->id,
                ]);
            }
        }

        if ($unknownError = self::unknownSegmentsError($params, $autoCreateTags, $autoCreateLists)) {
            return $unknownError;
        }

        // Write phase: every mutation lives below this point.

        // Snapshot before the rename mutates $existing->email.
        $previousStatus   = $existing ? $existing->status : null;
        $previousEmail    = $existing ? $existing->email : null;
        $previousSnapshot = $existing ? self::snapshotCompareFields($existing) : null;

        fluentCrmDb()->beginTransaction();

        // Non-DB side effects run only after commit. attachTags()/attachLists() hooks still fire pre-commit.
        $deferredEffects = [];

        try {
            $addTags     = MCPHelper::resolveTagIds($params['add_tags'] ?? [], $autoCreateTags);
            $removeTags  = MCPHelper::resolveTagIds($params['remove_tags'] ?? [], false);
            $addLists    = MCPHelper::resolveListIds($params['add_lists'] ?? [], $autoCreateLists);
            $removeLists = MCPHelper::resolveListIds($params['remove_lists'] ?? [], false);

            // Rename in place first: createOrUpdate looks up by email and would create a new contact.
            if ($existing && $newEmail && $newEmail !== $existing->email) {
                $oldEmail = $existing->email;
                $existing->email = $newEmail;
                $existing->save();

                $renamed = $existing;
                $deferredEffects[] = function () use ($renamed, $oldEmail) {
                    do_action('fluent_crm/contact_email_changed', $renamed, $oldEmail);
                };
            }

            // Payload is built from an allowlist, never the raw params.
            $payload = [
                'email' => $existing && $newEmail ? $newEmail : ($email ?: ($existing->email ?? null)),
            ];

            foreach (self::contactWritePassthru() as $field) {
                if (array_key_exists($field, $params) && $params[$field] !== null && $params[$field] !== '') {
                    $payload[$field] = $params[$field];
                }
            }

            self::applyAddressShape($payload, $params['address'] ?? null);

            if ($customValues !== null) {
                $payload['custom_values'] = $customValues;
            }

            // source defaults to 'mcp' on creation only; updates keep the existing source.
            if (!$existing && (!isset($payload['source']) || $payload['source'] === '')) {
                $payload['source'] = 'mcp';
            } elseif ($existing && (!isset($payload['source']) || $payload['source'] === '')) {
                unset($payload['source']);
            }

            // Force the status write only for an explicit status, so a field edit cannot resubscribe a suppressed contact.
            $forceUpdate = !empty($payload['status']);
            $contact = FluentCrmApi('contacts')->createOrUpdate($payload, $forceUpdate, false);

            if (!$contact) {
                fluentCrmDb()->rollBack();
                return MCPHelper::error('failed', 'Could not create or update the contact');
            }

            $action = !empty($contact->wasRecentlyCreated) ? 'created' : 'updated';

            $tagsAdded = [];
            $tagsRemoved = [];
            $listsAdded = [];
            $listsRemoved = [];

            if (!empty($addTags['ids'])) {
                $contact->attachTags($addTags['ids']);
                foreach ($addTags['ids'] as $id) {
                    $tagsAdded[] = ['id' => (int) $id];
                }
            }
            if (!empty($removeTags['ids'])) {
                $contact->detachTags($removeTags['ids']);
                foreach ($removeTags['ids'] as $id) {
                    $tagsRemoved[] = ['id' => (int) $id];
                }
            }
            if (!empty($addLists['ids'])) {
                $contact->attachLists($addLists['ids']);
                foreach ($addLists['ids'] as $id) {
                    $listsAdded[] = ['id' => (int) $id];
                }
            }
            if (!empty($removeLists['ids'])) {
                $contact->detachLists($removeLists['ids']);
                foreach ($removeLists['ids'] as $id) {
                    $listsRemoved[] = ['id' => (int) $id];
                }
            }

            if (!empty($params['status_change_reason']) && $previousStatus && $previousStatus !== $contact->status) {
                \FluentCrm\App\Models\SubscriberNote::create([
                    'subscriber_id' => $contact->id,
                    'type'          => 'note',
                    'title'         => 'Status changed via MCP',
                    'description'   => sanitize_text_field((string) $params['status_change_reason']),
                ]);
            }

            if (!empty($params['double_optin']) && $contact->status === 'pending') {
                $optinContact = $contact;
                $deferredEffects[] = function () use ($optinContact) {
                    $optinContact->sendDoubleOptinEmail();
                };
            }
        } catch (\Throwable $e) {
            fluentCrmDb()->rollBack();
            // AbilitiesRegistrar::wrapExecuteCallback() logs and returns the generic error.
            throw $e;
        }

        fluentCrmDb()->commit();

        foreach ($deferredEffects as $effect) {
            $effect();
        }

        $contact = Subscriber::with(['tags', 'lists'])->find($contact->id);

        return [
            'ok'      => true,
            'action'  => $action,
            'contact' => MCPHelper::formatContactForMCP($contact, ['include' => ['notes', 'email_history', 'automations']]),
            'changes' => [
                'fields_updated'   => self::diffFields($previousSnapshot, $contact),
                'tags_added'       => $tagsAdded,
                'tags_removed'     => $tagsRemoved,
                'lists_added'      => $listsAdded,
                'lists_removed'    => $listsRemoved,
                'previous_status'  => $previousStatus,
                'current_status'   => $contact->status,
                'previous_email'   => $previousEmail,
                'current_email'    => $contact->email,
                'tags_created'     => $addTags['created'],
                'lists_created'    => $addLists['created'],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Write: delete-contact-note (round 3 review #11)
    // -----------------------------------------------------------------

    public static function deleteContactNote($params)
    {
        $params = (array) $params;
        $noteId = (int) ($params['note_id'] ?? 0);
        if (!$noteId) {
            return MCPHelper::error('invalid_param', 'note_id is required');
        }

        $note = \FluentCrm\App\Models\SubscriberNote::find($noteId);
        if (!$note) {
            return MCPHelper::error('not_found', 'Note not found', ['note_id' => $noteId]);
        }

        $deletedId        = (int) $note->id;
        $subscriberId     = (int) $note->subscriber_id;
        $title            = (string) $note->title;
        $note->delete();

        do_action('fluent_crm/note_deleted', $deletedId, $subscriberId);

        return [
            'ok'             => true,
            'action'         => 'deleted',
            'deleted_id'     => $deletedId,
            'subscriber_id'  => $subscriberId,
            'deleted_title'  => $title,
            'note'           => 'Note row removed. The contact\'s other notes and email history are unaffected.',
        ];
    }

    /**
     * Names a dry run would create. Numeric inputs are id lookups and are skipped.
     */
    /**
     * The unknown_segments error for tag/list references that match nothing, or null.
     *
     * Same rule as bulk-upsert-contacts: without auto-create an unknown name refuses the whole
     * call before any write, so the agent never reports a tag as applied when it was dropped.
     * Removals always need existing tags and lists; auto-create applies to adds only.
     */
    private static function unknownSegmentsError(array $params, $autoCreateTags, $autoCreateLists)
    {
        $unknownTags = array_merge(
            $autoCreateTags ? [] : MCPHelper::unresolvedSegmentRefs((array) ($params['add_tags'] ?? []), 'tag'),
            MCPHelper::unresolvedSegmentRefs((array) ($params['remove_tags'] ?? []), 'tag')
        );
        $unknownLists = array_merge(
            $autoCreateLists ? [] : MCPHelper::unresolvedSegmentRefs((array) ($params['add_lists'] ?? []), 'list'),
            MCPHelper::unresolvedSegmentRefs((array) ($params['remove_lists'] ?? []), 'list')
        );
        if (!$unknownTags && !$unknownLists) {
            return null;
        }

        return MCPHelper::error('unknown_segments', 'Some tags or lists do not exist. Nothing was changed.', array_filter([
            'unknown_tags'  => array_values($unknownTags),
            'unknown_lists' => array_values($unknownLists),
            'next_step'     => 'Check the names with list-tags / list-lists. Create the ones you need with manage-tag / manage-list action=create, or pass auto_create_tags / auto_create_lists = true to create the added ones.',
        ]));
    }

    private static function wouldCreateNames($items, $kind = 'tag')
    {
        $out = [];
        foreach ((array) $items as $item) {
            if (is_numeric($item) || $item === '' || $item === null) {
                continue;
            }
            $name = sanitize_text_field((string) $item);
            $slug = sanitize_title($name);
            if ($kind === 'list') {
                $hit = \FluentCrm\App\Models\Lists::where('title', $name)->orWhere('slug', $slug)->first();
            } else {
                $hit = \FluentCrm\App\Models\Tag::where('title', $name)->orWhere('slug', $slug)->first();
            }
            if (!$hit) {
                $out[] = $name;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Address keys the contact write tools accept.
     *
     * @return string[]
     */
    private static function addressAllowedKeys()
    {
        return ['line_1', 'line_2', 'city', 'state', 'postal_code', 'country'];
    }

    /**
     * Map the agent-facing address shape onto column names in $payload (by reference).
     */
    private static function applyAddressShape(array &$payload, $address)
    {
        if (empty($address) || !is_array($address)) {
            return;
        }
        $map = [
            'line_1'      => 'address_line_1',
            'line_2'      => 'address_line_2',
            'city'        => 'city',
            'state'       => 'state',
            'postal_code' => 'postal_code',
            'country'     => 'country',
        ];
        foreach ($map as $key => $col) {
            if (isset($address[$key]) && $address[$key] !== '') {
                $payload[$col] = $address[$key];
            }
        }
    }

    /**
     * Snapshot the columns diffFields() compares, taken before any mutation.
     *
     * @return array<string,string>
     */
    private static function snapshotCompareFields($subscriber)
    {
        $snapshot = [];
        foreach (self::compareFieldNames() as $field) {
            $snapshot[$field] = (string) ($subscriber->{$field} ?? '');
        }
        return $snapshot;
    }

    private static function compareFieldNames()
    {
        return ['email', 'first_name', 'last_name', 'prefix', 'phone', 'status', 'contact_type', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'date_of_birth', 'timezone', 'source'];
    }

    /**
     * @param array<string,string>|null $before  Snapshot from snapshotCompareFields()
     * @param object                    $after   Subscriber model post-save
     */
    private static function diffFields($before, $after)
    {
        if (!$before) {
            return ['*'];
        }
        $changed = [];
        foreach (self::compareFieldNames() as $field) {
            if (($before[$field] ?? '') !== (string) ($after->{$field} ?? '')) {
                $changed[] = $field;
            }
        }
        return $changed;
    }

    // -----------------------------------------------------------------
    // Write: bulk-upsert-contacts
    // -----------------------------------------------------------------

    public static function bulkUpsertContacts($params)
    {
        $params = (array) $params;
        $contacts = (array) ($params['contacts'] ?? []);
        if (!$contacts) {
            return MCPHelper::error('invalid_param', 'contacts is required');
        }

        $maxBatch = (int) apply_filters('fluent_crm/mcp_bulk_cap', 500, 'bulk-upsert-contacts');
        if (count($contacts) > $maxBatch) {
            return MCPHelper::error('cap_reached', 'Too many contacts in a single call', [
                'max'    => $maxBatch,
                'matched' => count($contacts),
            ]);
        }

        // Off by default, as in upsert-contact: free text must not grow the tag and list registries.
        $autoCreateTags  = !empty($params['auto_create_tags']);
        $autoCreateLists = !empty($params['auto_create_lists']);
        $ifExists        = $params['if_exists'] ?? 'merge';
        $doubleOptin     = !empty($params['double_optin']);

        if (($autoCreateTags || $autoCreateLists)
            && !\FluentCrm\App\Services\PermissionManager::currentUserCan('fcrm_manage_contact_cats')) {
            return MCPHelper::error('forbidden', 'Creating new tags/lists requires fcrm_manage_contact_cats');
        }

        $created = $updated = $skipped = $invalid = $warnings = [];

        // Pre-resolve existing contacts in one query, keyed by lowercased email (the column is case-insensitive).
        $lookupEmails = [];
        foreach ($contacts as $row) {
            if (is_array($row) && !empty($row['email']) && is_email($row['email'])) {
                $lookupEmails[strtolower(sanitize_email($row['email']))] = true;
            }
        }

        $existingByEmail = [];
        if ($lookupEmails) {
            foreach (Subscriber::whereIn('email', array_keys($lookupEmails))->get() as $found) {
                $existingByEmail[strtolower($found->email)] = $found;
            }
        }

        // Resolve the union of every row's tag/list refs once, then map per row.
        // Capped per row and in aggregate because auto-create defaults to true.
        $tagRefUnion  = [];
        $listRefUnion = [];
        foreach ($contacts as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Skip over-sized rows so they cannot create tags before being rejected below.
            if (count((array) ($row['tags'] ?? [])) > self::MAX_SEGMENT_REFS_PER_ROW
                || count((array) ($row['lists'] ?? [])) > self::MAX_SEGMENT_REFS_PER_ROW) {
                continue;
            }
            foreach ((array) ($row['tags'] ?? []) as $ref) {
                if (!is_array($ref) && !is_object($ref) && $ref !== '' && $ref !== null) {
                    $tagRefUnion[MCPHelper::segmentRefKey($ref)] = $ref;
                }
            }
            foreach ((array) ($row['lists'] ?? []) as $ref) {
                if (!is_array($ref) && !is_object($ref) && $ref !== '' && $ref !== null) {
                    $listRefUnion[MCPHelper::segmentRefKey($ref)] = $ref;
                }
            }
        }

        // Refuse the whole call before any segment is auto-created.
        $distinctRefs = count($tagRefUnion) + count($listRefUnion);
        if ($distinctRefs > self::MAX_BULK_SEGMENT_REFS) {
            return MCPHelper::error('cap_reached', sprintf(
                'This batch references %1$d distinct tags/lists; the limit is %2$d per call. That usually means a high-cardinality field was mapped into tags or lists. Split the batch, or set auto_create_tags/auto_create_lists to false and pre-create the segments you actually want.',
                $distinctRefs,
                self::MAX_BULK_SEGMENT_REFS
            ), [
                'distinct_segment_refs' => $distinctRefs,
                'max_segment_refs'      => self::MAX_BULK_SEGMENT_REFS,
            ]);
        }

        // Without auto_create, an unknown tag or list refuses the whole call before any write;
        // dropping it per row would import contacts without the segment the caller asked for.
        $unknownTags  = (!$autoCreateTags && $tagRefUnion) ? MCPHelper::unresolvedSegmentRefs(array_values($tagRefUnion), 'tag') : [];
        $unknownLists = (!$autoCreateLists && $listRefUnion) ? MCPHelper::unresolvedSegmentRefs(array_values($listRefUnion), 'list') : [];
        if ($unknownTags || $unknownLists) {
            return MCPHelper::error('unknown_segments', 'Some tags or lists in this batch do not exist. Nothing was imported.', array_filter([
                'unknown_tags'  => $unknownTags,
                'unknown_lists' => $unknownLists,
                'next_step'     => 'Check the names with list-tags / list-lists. Create the ones you need with manage-tag / manage-list action=create, or pass auto_create_tags / auto_create_lists = true to create them during the import.',
            ]));
        }

        $tagMap  = $tagRefUnion
            ? MCPHelper::resolveSegmentRefs(array_values($tagRefUnion), 'tag', $autoCreateTags)['map']
            : [];
        $listMap = $listRefUnion
            ? MCPHelper::resolveSegmentRefs(array_values($listRefUnion), 'list', $autoCreateLists)['map']
            : [];

        foreach ($contacts as $row) {
            if (!is_array($row) || empty($row['email']) || !is_email($row['email'])) {
                $invalid[] = ['email' => $row['email'] ?? null, 'reason' => 'invalid_email'];
                continue;
            }

            if (count((array) ($row['tags'] ?? [])) > self::MAX_SEGMENT_REFS_PER_ROW
                || count((array) ($row['lists'] ?? [])) > self::MAX_SEGMENT_REFS_PER_ROW) {
                $invalid[] = [
                    'email'  => $row['email'],
                    'reason' => 'too_many_segment_refs',
                    'max'    => self::MAX_SEGMENT_REFS_PER_ROW,
                ];
                continue;
            }

            $emailKey = strtolower(sanitize_email($row['email']));
            $existing = isset($existingByEmail[$emailKey]) ? $existingByEmail[$emailKey] : null;
            if ($existing && $ifExists === 'skip') {
                $skipped[] = ['id' => (int) $existing->id, 'email' => $existing->email];
                continue;
            }
            if ($existing && $ifExists === 'error') {
                $invalid[] = ['email' => $row['email'], 'reason' => 'contact_exists', 'id' => (int) $existing->id];
                continue;
            }

            $tagIds  = ['ids' => self::mapSegmentRefs($row['tags'] ?? [], $tagMap)];
            $listIds = ['ids' => self::mapSegmentRefs($row['lists'] ?? [], $listMap)];

            // Payload is built from an allowlist, never the raw row.
            $payload = [
                'email' => sanitize_email($row['email']),
            ];
            foreach (self::contactWritePassthru() as $field) {
                if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                    $payload[$field] = $row[$field];
                }
            }
            $payload['tags']  = $tagIds['ids'];
            $payload['lists'] = $listIds['ids'];

            // Unknown address keys are a per-row warning; the row still saves without them.
            if (array_key_exists('address', $row) && $row['address'] !== null) {
                if (!is_array($row['address'])) {
                    $warnings[] = ['email' => $row['email'], 'reason' => 'invalid_address_not_object'];
                } else {
                    $unknownAddr = MCPHelper::unknownKeys($row['address'], self::addressAllowedKeys(), 'address');
                    if ($unknownAddr) {
                        $warnings[] = [
                            'email'              => $row['email'],
                            'reason'             => 'unknown_address_keys',
                            'unknown_properties' => $unknownAddr,
                        ];
                    }
                }
            }

            self::applyAddressShape($payload, $row['address'] ?? null);

            // source defaults to 'mcp_bulk' on creation only.
            if (!$existing && (!isset($payload['source']) || $payload['source'] === '')) {
                $payload['source'] = 'mcp_bulk';
            } elseif ($existing && (!isset($payload['source']) || $payload['source'] === '')) {
                unset($payload['source']);
            }

            if (!empty($row['custom_fields']) && is_array($row['custom_fields'])) {
                // Unknown slugs are a per-row warning; known keys still persist.
                $diff = MCPHelper::diffCustomFields($row['custom_fields']);
                if (!empty($diff['unknown'])) {
                    $warnings[] = [
                        'email'                       => $row['email'],
                        'reason'                      => 'unknown_custom_field_slugs',
                        'unknown_custom_field_slugs'  => $diff['unknown'],
                    ];
                }
                $payload['custom_values'] = $diff['known'];
            }

            // A malformed date of birth is dropped with a warning; the rest of the row saves.
            if (isset($payload['date_of_birth']) && \FluentCrm\App\Services\Helper::normalizeDateOfBirth($payload['date_of_birth']) === false) {
                $warnings[] = [
                    'email'         => $row['email'],
                    'reason'        => 'invalid_date_of_birth_ignored',
                    'date_of_birth' => (string) $payload['date_of_birth'],
                ];
                unset($payload['date_of_birth']);
            }

            // Unknown statuses are dropped with a warning; only an explicit valid status forces the write.
            if (!empty($payload['status']) && Sanitize::contactStatus($payload['status']) === null) {
                $warnings[] = [
                    'email'  => $row['email'],
                    'reason' => 'unknown_status_ignored',
                    'status' => $payload['status'],
                ];
                unset($payload['status']);
            }

            $contact = FluentCrmApi('contacts')->createOrUpdate($payload, !empty($payload['status']), false);
            if (!$contact) {
                $invalid[] = ['email' => $row['email'], 'reason' => 'failed_to_save'];
                continue;
            }

            // Keep the map current so a repeated address in the same batch counts as existing.
            $existingByEmail[strtolower($contact->email)] = $contact;

            if ($doubleOptin && $contact->status === 'pending') {
                $contact->sendDoubleOptinEmail();
            }

            $entry = [
                'id'     => (int) $contact->id,
                'email'  => $contact->email,
                'status' => $contact->status,
            ];
            if (!empty($contact->wasRecentlyCreated)) {
                $created[] = $entry;
            } else {
                $updated[] = $entry;
            }
        }

        return [
            'ok'      => true,
            'summary' => [
                'created'  => count($created),
                'updated'  => count($updated),
                'skipped'  => count($skipped),
                'invalid'  => count($invalid),
                'warnings' => count($warnings),
            ],
            'created'  => $created,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'invalid'  => $invalid,
            'warnings' => $warnings,
        ];
    }

    /**
     * Translate one row's tag/list refs into ids via a resolveSegmentRefs() map. Unmatched refs are dropped.
     *
     * @param mixed $refs
     * @param array $map ref key => id
     * @return int[]
     */
    private static function mapSegmentRefs($refs, $map)
    {
        $ids = [];

        foreach ((array) $refs as $ref) {
            if (is_array($ref) || is_object($ref) || $ref === '' || $ref === null) {
                continue;
            }
            $key = MCPHelper::segmentRefKey($ref);
            if (isset($map[$key])) {
                $ids[] = (int) $map[$key];
            }
        }

        return array_values(array_unique($ids));
    }

    // -----------------------------------------------------------------
    // Write: delete-contact
    // -----------------------------------------------------------------

    public static function deleteContact($params)
    {
        $resolved = MCPHelper::resolveContact((array) $params);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $contact = $resolved;
        $deletedId    = (int) $contact->id;
        $deletedEmail = (string) $contact->email;
        // The email log goes with the contact: Cleanup::deleteSubscribersAssets() removes it on
        // fluentcrm_after_subscribers_deleted, so a keep-the-log option cannot be honoured.
        $ok = \FluentCrm\App\Services\Helper::deleteContacts([$deletedId]);
        if (!$ok) {
            return MCPHelper::error('failed', 'Could not delete the contact');
        }

        return [
            'ok'             => true,
            'deleted_id'     => $deletedId,
            'deleted_email'  => $deletedEmail,
            'emails_purged'  => true,
        ];
    }

    // -----------------------------------------------------------------
    // Write: apply-tags-lists-to-contacts
    // -----------------------------------------------------------------

    public static function applySegmentsToContacts($params)
    {
        $params = (array) $params;

        $autoCreateTags  = !empty($params['auto_create_tags']);
        $autoCreateLists = !empty($params['auto_create_lists']);
        if (($autoCreateTags || $autoCreateLists)
            && !\FluentCrm\App\Services\PermissionManager::currentUserCan('fcrm_manage_contact_cats')) {
            return MCPHelper::error('forbidden', 'Creating new tags/lists requires fcrm_manage_contact_cats');
        }

        $contactIds = isset($params['contact_ids']) ? array_filter(array_map('intval', (array) $params['contact_ids'])) : [];
        $filter     = $params['filter'] ?? null;
        $dryRun     = !empty($params['dry_run']);

        if (!$contactIds && empty($filter)) {
            return MCPHelper::error('invalid_param', 'Provide contact_ids or filter');
        }

        if ($contactIds && !empty($filter)) {
            return MCPHelper::error('invalid_param', 'Provide contact_ids OR filter, not both');
        }

        $cap = (int) apply_filters('fluent_crm/mcp_bulk_cap', 5000, 'apply-tags-lists-to-contacts');

        if (!$contactIds) {
            $validation = MCPHelper::validateUniversalFilter((array) $filter);
            if (is_wp_error($validation)) {
                return $validation;
            }
            $args = MCPHelper::buildContactsQueryArgs((array) $filter);
            $args['with'] = []; // we just need ids
            $cq = new ContactsQuery($args);
            MCPHelper::applyDateFilters($cq, (array) $filter);
            $query = $cq->getModel();

            $matched = (int) $query->count();
            // A dry run reports the matched count even above the cap.
            if ($matched > $cap && !$dryRun) {
                return MCPHelper::error('cap_reached', 'Too many contacts match the filter', [
                    'max'     => $cap,
                    'matched' => $matched,
                ]);
            }

            $contactIds = array_map('intval', $query->limit($cap)->pluck('id')->toArray());
            // pluck() above returns at most $cap rows; keep the true count for dry runs.
            $matchedTotal = $matched;
        } else {
            if (count($contactIds) > $cap && !$dryRun) {
                return MCPHelper::error('cap_reached', 'Too many contact_ids in a single call', [
                    'max'     => $cap,
                    'matched' => count($contactIds),
                ]);
            }
            $matchedTotal = count($contactIds);
        }

        // A dry run refuses unknown names too, so it predicts the real call.
        if ($unknownError = self::unknownSegmentsError($params, $autoCreateTags, $autoCreateLists)) {
            return $unknownError;
        }

        // Dry runs never auto-create.
        $addTags    = MCPHelper::resolveTagIds((array) ($params['add_tags'] ?? []), $autoCreateTags && !$dryRun);
        $removeTags = MCPHelper::resolveTagIds((array) ($params['remove_tags'] ?? []), false);
        $addLists   = MCPHelper::resolveListIds((array) ($params['add_lists'] ?? []), $autoCreateLists && !$dryRun);
        $removeLists = MCPHelper::resolveListIds((array) ($params['remove_lists'] ?? []), false);

        $tagsWouldCreate  = $autoCreateTags ? self::wouldCreateNames((array) ($params['add_tags'] ?? []), 'tag') : [];
        $listsWouldCreate = $autoCreateLists ? self::wouldCreateNames((array) ($params['add_lists'] ?? []), 'list') : [];

        // In a dry run, would-create names count as work.
        $hasAnyWork = $addTags['ids'] || $removeTags['ids']
            || $addLists['ids'] || $removeLists['ids']
            || ($dryRun && ($tagsWouldCreate || $listsWouldCreate));
        if (!$hasAnyWork) {
            return MCPHelper::error('invalid_param', 'Provide at least one of add_tags, remove_tags, add_lists, remove_lists');
        }

        if ($dryRun) {
            $formatRefs = function ($ids) {
                $out = [];
                foreach ($ids as $id) {
                    $out[] = ['id' => (int) $id];
                }
                return $out;
            };
            $exceedsCap = $matchedTotal > $cap;
            return [
                'ok'                  => true,
                'dry_run'             => true,
                'matched_contacts'    => $matchedTotal,
                'cap'                 => $cap,
                'exceeds_cap'         => $exceedsCap,
                'batches_required'    => $exceedsCap ? (int) ceil($matchedTotal / max(1, $cap)) : 1,
                'applied_to_contacts' => 0,
                'tags_added'          => $formatRefs($addTags['ids']),
                'tags_removed'        => $formatRefs($removeTags['ids']),
                'lists_added'         => $formatRefs($addLists['ids']),
                'lists_removed'       => $formatRefs($removeLists['ids']),
                'tags_would_create'   => $tagsWouldCreate,
                'lists_would_create'  => $listsWouldCreate,
                'note'                => $exceedsCap
                    ? 'Dry run: match exceeds the per-call cap. Apply by passing contact_ids in batches.'
                    : 'Dry run: nothing was applied. Re-run without dry_run=true to commit.',
            ];
        }

        // Touched ids are returned so the agent can reverse precisely.
        $chunkSize = 200;
        $applied = 0;
        $appliedIds = [];
        foreach (array_chunk($contactIds, $chunkSize) as $batchIds) {
            $subscribers = Subscriber::whereIn('id', $batchIds)->get();
            foreach ($subscribers as $sub) {
                if ($addTags['ids']) {
                    $sub->attachTags($addTags['ids']);
                }
                if ($removeTags['ids']) {
                    $sub->detachTags($removeTags['ids']);
                }
                if ($addLists['ids']) {
                    $sub->attachLists($addLists['ids']);
                }
                if ($removeLists['ids']) {
                    $sub->detachLists($removeLists['ids']);
                }
                $applied++;
                $appliedIds[] = (int) $sub->id;
            }
        }

        $formatRefs = function ($ids) {
            $out = [];
            foreach ($ids as $id) {
                $out[] = ['id' => (int) $id];
            }
            return $out;
        };

        return [
            'ok'                  => true,
            'matched_contacts'    => count($contactIds),
            'applied_to_contacts' => $applied,
            'applied_contact_ids' => $appliedIds,
            'tags_added'          => $formatRefs($addTags['ids']),
            'tags_removed'        => $formatRefs($removeTags['ids']),
            'lists_added'         => $formatRefs($addLists['ids']),
            'lists_removed'       => $formatRefs($removeLists['ids']),
            'tags_created'        => $addTags['created'],
            'lists_created'       => $addLists['created'],
            'reverse_with'        => 'To reverse: re-call apply-tags-lists-to-contacts with contact_ids=applied_contact_ids and add_*/remove_* swapped.',
        ];
    }

    // -----------------------------------------------------------------
    // Write: add-contact-note
    // -----------------------------------------------------------------

    public static function addContactNote($params)
    {
        $params = (array) $params;

        $resolved = MCPHelper::resolveContact($params);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $subscriber = $resolved;

        $title       = trim((string) ($params['title'] ?? ''));
        $description = (string) ($params['description'] ?? '');
        $allowedTypes = ['note', 'call', 'email', 'meeting', 'quote'];
        $type         = sanitize_key($params['type'] ?? 'note');
        // Unknown note type is a hard error, not a silent coercion to 'note'.
        if (array_key_exists('type', $params) && $params['type'] !== '' && !in_array($type, $allowedTypes, true)) {
            return MCPHelper::error('invalid_param', 'type is not a recognized note type.', [
                'type'    => $params['type'],
                'allowed' => $allowedTypes,
            ]);
        }

        if ($title === '' || $description === '') {
            return MCPHelper::error('invalid_param', 'title and description are required');
        }

        // Same parsing as the other MCP dates: an offset is converted to site time, a naive
        // value is site time (as the admin's date picker sends), anything else is refused.
        $createdAt = MCPHelper::normalizeDateBoundary($params['created_at'] ?? '', 'created_at');
        if (is_wp_error($createdAt)) {
            return $createdAt;
        }

        $noteData = [
            'subscriber_id' => $subscriber->id,
            'type'          => $type,
            'title'         => $title,
            'description'   => $description,
            'created_at'    => $createdAt ?: current_time('mysql'),
        ];

        // Same filter the controller applies, so smartcodes resolve.
        $noteData['description'] = apply_filters('fluent_crm/parse_contact_note_text', $noteData['description'], $subscriber);
        $noteData = \FluentCrm\App\Services\Sanitize::contactNote($noteData);

        $note = \FluentCrm\App\Models\SubscriberNote::create(wp_unslash($noteData));

        do_action('fluent_crm/note_added', $note, $subscriber, $noteData);

        return [
            'ok'   => true,
            'note' => MCPHelper::formatNoteForMCP($note),
        ];
    }
}
