<?php

namespace FluentCrm\App\Modules\MCP\Tools;

use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Modules\MCP\Helpers\MCPHelper;
use FluentCrm\App\Services\PermissionManager;

/**
 * Tag/list read and management tools (list-tags, list-lists, manage-tag, manage-list).
 * Merge moves every subscriber from the source rows onto the target, then deletes the sources.
 * Not called "segments" on the agent surface: that is the separate Pro dynamic-segment feature.
 */
class SegmentTools
{
    /**
     * Max refs `list-tags`/`list-lists` resolve in one call.
     */
    const MAX_LOOKUP_REFS = 100;

    /**
     * Max source pivot rows a synchronous merge processes. Filterable.
     */
    const DEFAULT_MERGE_MAX_PIVOT_ROWS = 5000;

    /**
     * Max merge source ids, enforced for direct Ability/REST callers too.
     */
    const MAX_MERGE_SOURCE_IDS = 100;

    /**
     * Rows hydrated per merge batch.
     */
    const MERGE_BATCH_SIZE = 200;

    /**
     * Follow-up passes actionMerge() makes for memberships created mid-merge.
     * Anything left after the last pass is handled by mergeRepointStragglers().
     */
    const MERGE_MAX_SWEEPS = 3;

    // -----------------------------------------------------------------
    // Read: list-tags / list-lists
    // -----------------------------------------------------------------

    public static function listTags($params)
    {
        return self::listSegments($params, 'tag');
    }

    public static function listLists($params)
    {
        return self::listSegments($params, 'list');
    }

    /**
     * Paginated tag/list registry with opt-in size and recency metrics.
     * last_used_at is computed for the current page only, so it cannot be a sort key.
     *
     * @param array  $params Tool arguments.
     * @param string $kind   'tag' or 'list'.
     * @return array
     */
    private static function listSegments($params, $kind)
    {
        $params = (array) $params;

        MCPHelper::paginationFromInput($params);

        $modelClass = $kind === 'list' ? Lists::class : Tag::class;
        $query      = $modelClass::query();

        $search = sanitize_text_field((string) ($params['search'] ?? ''));
        if ($search !== '') {
            $query->searchBy($search);
        }

        // Unresolvable refs are reported in not_found.
        $notFoundRefs = [];
        if (!empty($params['ids'])) {
            $refs = (array) $params['ids'];
            if (count($refs) > self::MAX_LOOKUP_REFS) {
                return MCPHelper::error('invalid_param', sprintf(
                    'ids accepts at most %1$d refs per call; received %2$d. Page through with search instead.',
                    self::MAX_LOOKUP_REFS,
                    count($refs)
                ), ['max_refs' => self::MAX_LOOKUP_REFS, 'received' => count($refs)]);
            }

            $resolved     = self::resolveRefs($refs, $kind);
            $notFoundRefs = $resolved['not_found'];

            // Fail closed: an empty IN list would return the whole registry.
            $query->whereIn('id', $resolved['ids'] ?: [0]);
        }

        $allowedSortBy = ['id', 'title', 'slug', 'created_at', 'updated_at', 'subscribers_count'];
        $sortBy = sanitize_key((string) ($params['sort_by'] ?? 'title'));
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'title';
        }
        $sortType = strtoupper(sanitize_text_field((string) ($params['sort_type'] ?? 'ASC')));
        $sortType = in_array($sortType, ['ASC', 'DESC'], true) ? $sortType : 'ASC';

        $withCounts = !empty($params['include_counts']) || $sortBy === 'subscribers_count';
        if ($withCounts) {
            $query->withCount('subscribers');
        }

        $paginated = $query->orderBy($sortBy, $sortType)->paginate();
        $rows      = $paginated->items();

        $lastUsed = [];
        if (!empty($params['include_last_used'])) {
            $lastUsed = self::lastUsedMap(array_map(function ($row) {
                return (int) $row->id;
            }, $rows), $kind);
        }

        $items = [];
        foreach ($rows as $row) {
            $item = self::format($row);

            if ($kind === 'list') {
                $item['is_public'] = !empty($row->is_public);
            }

            $item['created_at'] = MCPHelper::toIso8601($row->created_at);

            if ($withCounts) {
                $item['subscribers_count'] = (int) $row->subscribers_count;
            }

            if (!empty($params['include_last_used'])) {
                $stats = $lastUsed[(int) $row->id] ?? ['last_used_at' => null, 'pivot_rows' => 0];
                $item['last_used_at'] = MCPHelper::toIso8601($stats['last_used_at']);

                // Rows exist but predate timestamp stamping: not the same as unused.
                if (!$item['last_used_at'] && $stats['pivot_rows'] > 0) {
                    $item['last_used_at_unknown'] = true;
                }
            }

            $items[] = $item;
        }

        $out = [
            'object_type' => $kind,
            'items'       => $items,
            'total'       => (int) $paginated->total(),
            'page'        => (int) $paginated->currentPage(),
            'per_page'    => (int) $paginated->perPage(),
            'pages'       => (int) $paginated->lastPage(),
        ];

        if (!empty($params['ids'])) {
            $out['not_found'] = $notFoundRefs;
        }

        return $out;
    }

    /**
     * Resolve a mixed list of ids / slugs / titles to row ids, reporting the
     * refs that matched nothing.
     *
     * @return array{ids: int[], not_found: string[]}
     */
    private static function resolveRefs($refs, $kind)
    {
        $modelClass = $kind === 'list' ? Lists::class : Tag::class;

        $numericIds = [];
        $textRefs   = [];
        foreach ($refs as $ref) {
            if ($ref === '' || $ref === null || is_array($ref)) {
                continue;
            }
            if (is_numeric($ref)) {
                $numericIds[] = (int) $ref;
            } else {
                $textRefs[] = sanitize_text_field((string) $ref);
            }
        }

        if (!$numericIds && !$textRefs) {
            return ['ids' => [], 'not_found' => []];
        }

        $slugs = array_map('sanitize_title', $textRefs);
        $rows  = $modelClass::query()
            ->where(function ($q) use ($numericIds, $textRefs, $slugs) {
                if ($numericIds) {
                    $q->orWhereIn('id', $numericIds);
                }
                if ($textRefs) {
                    $q->orWhereIn('title', $textRefs);
                    $q->orWhereIn('slug', $slugs);
                }
            })
            ->get();

        $ids       = [];
        $byId      = [];
        $byTitle   = [];
        $bySlug    = [];
        foreach ($rows as $row) {
            $ids[]                              = (int) $row->id;
            $byId[(int) $row->id]               = true;
            $byTitle[strtolower($row->title)]   = true;
            $bySlug[(string) $row->slug]        = true;
        }

        $notFound = [];
        foreach ($numericIds as $id) {
            if (!isset($byId[$id])) {
                $notFound[] = (string) $id;
            }
        }
        foreach ($textRefs as $i => $ref) {
            if (!isset($byTitle[strtolower($ref)]) && !isset($bySlug[$slugs[$i]])) {
                $notFound[] = $ref;
            }
        }

        return [
            'ids'       => array_values(array_unique($ids)),
            'not_found' => array_values(array_unique($notFound)),
        ];
    }

    /**
     * Most recent attach time and pivot row count per tag/list id.
     * Re-attaching an existing tag does not bump the pivot's created_at.
     *
     * @param int[]  $ids  Ids on the current page.
     * @param string $kind 'tag' or 'list'.
     * @return array<int, array{last_used_at: ?string, pivot_rows: int}>
     */
    private static function lastUsedMap($ids, $kind)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (!$ids) {
            return [];
        }

        global $wpdb;
        $pivotTable   = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType   = self::segmentObjectType($kind);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // fc_subscriber_pivot is shared by tags, lists and companies.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id, COUNT(*) AS pivot_rows, MAX(created_at) AS last_used_at
             FROM {$pivotTable}
             WHERE object_type = %s AND object_id IN ({$placeholders})
             GROUP BY object_id",
            array_merge([$objectType], $ids)
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->object_id] = [
                'last_used_at' => $row->last_used_at,
                'pivot_rows'   => (int) $row->pivot_rows,
            ];
        }

        return $map;
    }

    // -----------------------------------------------------------------
    // manage-tag
    // -----------------------------------------------------------------

    public static function manageTag($params)
    {
        return self::manageSegment($params, 'tag');
    }

    // -----------------------------------------------------------------
    // manage-list
    // -----------------------------------------------------------------

    public static function manageList($params)
    {
        return self::manageSegment($params, 'list');
    }

    private static function manageSegment($params, $kind)
    {
        $params = (array) $params;
        $action = sanitize_key((string) ($params['action'] ?? ''));

        if (!in_array($action, ['create', 'update', 'delete', 'merge'], true)) {
            return MCPHelper::error('invalid_param', 'action must be one of: create, update, delete, merge');
        }

        // delete and merge need the delete capability.
        $needsDeleteCap = in_array($action, ['delete', 'merge'], true);
        $cap = $needsDeleteCap ? 'fcrm_manage_contact_cats_delete' : 'fcrm_manage_contact_cats';
        if (!PermissionManager::currentUserCan($cap)) {
            return MCPHelper::error('forbidden', sprintf(
                'This action requires the %s capability.',
                $cap
            ), ['required' => $cap]);
        }

        switch ($action) {
            case 'create':
                return self::actionCreate($params, $kind);
            case 'update':
                return self::actionUpdate($params, $kind);
            case 'delete':
                return self::actionDelete($params, $kind);
            case 'merge':
                return self::actionMerge($params, $kind);
        }

        return MCPHelper::error('invalid_param', 'Unhandled action');
    }

    private static function actionCreate($params, $kind)
    {
        $title = trim((string) ($params['title'] ?? ''));
        if ($title === '') {
            return MCPHelper::error('invalid_param', 'title is required for create');
        }
        $slug = isset($params['slug']) && $params['slug'] !== ''
            ? sanitize_title((string) $params['slug'])
            : sanitize_title($title);
        $description = sanitize_textarea_field((string) ($params['description'] ?? ''));

        $existing = self::lookupByTitleOrSlug($title, $slug, $kind);
        if ($existing) {
            return MCPHelper::error('contact_exists', sprintf(
                'A %1$s with that title or slug already exists (id %2$d). Use update or merge to change it.',
                $kind,
                (int) $existing->id
            ), ['existing_id' => (int) $existing->id]);
        }

        $modelClass = $kind === 'list' ? Lists::class : Tag::class;
        $row = $modelClass::create([
            'title'       => sanitize_text_field($title),
            'slug'        => $slug,
            'description' => $description,
        ]);

        do_action(self::createdHook($kind), $row);

        MCPHelper::flushSegmentRefCache();

        return [
            'ok'      => true,
            'action'  => 'create',
            'kind'    => $kind,
            $kind     => self::format($row),
            'note'    => sprintf(
                '%1$s "%2$s" created. No subscribers are attached yet.',
                ucfirst($kind),
                $row->title
            ),
        ];
    }

    private static function actionUpdate($params, $kind)
    {
        $id = (int) ($params[$kind . '_id'] ?? 0);
        $row = $id ? self::find($id, $kind) : null;
        if (!$row) {
            return MCPHelper::error('not_found', sprintf('%s not found', ucfirst($kind)), [$kind . '_id' => $id]);
        }

        $changes = [];
        if (isset($params['title']) && $params['title'] !== '' && $params['title'] !== $row->title) {
            $changes['title'] = ['from' => $row->title, 'to' => sanitize_text_field((string) $params['title'])];
            $row->title = $changes['title']['to'];
        }
        if (isset($params['slug']) && $params['slug'] !== '') {
            $newSlug = sanitize_title((string) $params['slug']);
            if ($newSlug !== $row->slug) {
                $changes['slug'] = ['from' => $row->slug, 'to' => $newSlug];
                $row->slug = $newSlug;
            }
        }
        if (array_key_exists('description', $params)) {
            $newDesc = sanitize_textarea_field((string) $params['description']);
            if ($newDesc !== $row->description) {
                $changes['description'] = ['from' => $row->description, 'to' => $newDesc];
                $row->description = $newDesc;
            }
        }

        if (empty($changes)) {
            return [
                'ok'     => true,
                'action' => 'update',
                'kind'   => $kind,
                $kind    => self::format($row),
                'note'   => 'No changes: provided fields matched the current values.',
            ];
        }

        // A title or slug held by another row would make later references ambiguous.
        if (isset($changes['title']) || isset($changes['slug'])) {
            $conflict = self::identityConflict($row, $kind);
            if (is_wp_error($conflict)) {
                return $conflict;
            }
        }

        $row->save();

        MCPHelper::flushSegmentRefCache();

        return [
            'ok'      => true,
            'action'  => 'update',
            'kind'    => $kind,
            $kind     => self::format($row),
            'changes' => $changes,
        ];
    }

    /**
     * Refuse a title or slug that already belongs to a different tag/list.
     * No unique index exists: existing installs may already hold duplicates.
     *
     * @param object $row  Tag/Lists model carrying the proposed title + slug
     * @param string $kind 'tag' or 'list'
     * @return true|\WP_Error
     */
    private static function identityConflict($row, $kind)
    {
        $modelClass = $kind === 'list' ? Lists::class : Tag::class;

        $clash = $modelClass::where('id', '!=', $row->id)
            ->where(function ($query) use ($row) {
                $query->where('title', $row->title)->orWhere('slug', $row->slug);
            })
            ->first();

        if (!$clash) {
            return true;
        }

        return MCPHelper::error('contact_exists', sprintf(
            'Another %1$s (id %2$d) already uses that title or slug. Pick a different one, or merge the two.',
            $kind,
            (int) $clash->id
        ), [
            'conflict_id'    => (int) $clash->id,
            'conflict_title' => $clash->title,
            'conflict_slug'  => $clash->slug,
        ]);
    }

    private static function actionDelete($params, $kind)
    {
        $id = (int) ($params[$kind . '_id'] ?? 0);
        $row = $id ? self::find($id, $kind) : null;
        if (!$row) {
            return MCPHelper::error('not_found', sprintf('%s not found', ucfirst($kind)), [$kind . '_id' => $id]);
        }

        $force = !empty($params['force']);
        $attachedCount = self::attachedSubscriberCount($row, $kind);

        if ($attachedCount > 0 && !$force) {
            return MCPHelper::error('not_supported', sprintf(
                '%1$s has %2$d subscribers attached. Pass force=true to delete anyway, or merge into another %1$s first.',
                ucfirst($kind),
                $attachedCount
            ), [
                'attached_subscribers' => $attachedCount,
                'force_required'       => true,
            ]);
        }

        $deletedId    = (int) $row->id;
        $deletedTitle = (string) $row->title;

        // A non-force delete re-checks emptiness inside the transaction: the delete hook purges all pivot rows.
        fluentCrmDb()->beginTransaction();

        try {
            if (!$force) {
                $recount = self::attachedSubscriberCount($row, $kind);
                if ($recount > 0) {
                    fluentCrmDb()->rollBack();

                    return MCPHelper::error('not_supported', sprintf(
                        '%1$s gained %2$d subscriber(s) while the delete was being processed. Nothing was deleted. Re-check and retry, or pass force=true.',
                        ucfirst($kind),
                        $recount
                    ), [
                        'attached_subscribers' => $recount,
                        'force_required'       => true,
                        'raced'                => true,
                    ]);
                }
            }

            $row->delete();
        } catch (\Throwable $e) {
            fluentCrmDb()->rollBack();
            throw $e;
        }

        fluentCrmDb()->commit();

        // Fired after commit so listeners never act on a rolled-back delete.
        do_action(self::deletedHook($kind), $deletedId);

        MCPHelper::flushSegmentRefCache();

        return [
            'ok'                 => true,
            'action'             => 'delete',
            'kind'               => $kind,
            'deleted_id'         => $deletedId,
            'deleted_title'      => $deletedTitle,
            'detached_subscribers' => $attachedCount,
            'note'               => $attachedCount > 0
                ? 'Deleted with subscribers attached. Pivot rows are orphaned and cleaned up by the cleanup hook.'
                : 'Deleted. No subscribers were attached.',
        ];
    }

    private static function actionMerge($params, $kind)
    {
        $fromIds = isset($params['from_' . $kind . '_ids']) ? (array) $params['from_' . $kind . '_ids'] : [];
        $fromIds = array_values(array_unique(array_filter(array_map('intval', $fromIds))));
        $toId    = (int) ($params['to_' . $kind . '_id'] ?? 0);

        if (!$toId) {
            return MCPHelper::error('invalid_param', 'to_*_id is required for merge');
        }
        if (!$fromIds) {
            return MCPHelper::error('invalid_param', 'from_*_ids must be a non-empty array of ids');
        }
        if (count($fromIds) > self::MAX_MERGE_SOURCE_IDS) {
            return MCPHelper::error('invalid_param', sprintf(
                'from_*_ids accepts at most %d unique ids per merge.',
                self::MAX_MERGE_SOURCE_IDS
            ), [
                'max_source_ids' => self::MAX_MERGE_SOURCE_IDS,
                'received'       => count($fromIds),
            ]);
        }
        if (in_array($toId, $fromIds, true)) {
            return MCPHelper::error('invalid_param', 'to_*_id cannot also be in from_*_ids');
        }

        $to = self::find($toId, $kind);
        if (!$to) {
            return MCPHelper::error('not_found', sprintf('Target %s not found', $kind), ['to_id' => $toId]);
        }

        $modelClass = $kind === 'list' ? Lists::class : Tag::class;
        $fromRows = $modelClass::whereIn('id', $fromIds)->get();
        $foundFromIds = $fromRows->pluck('id')->map('intval')->toArray();
        $missingFromIds = array_values(array_diff($fromIds, $foundFromIds));

        $maxPivotRows = max(1, (int) apply_filters(
            'fluent_crm/mcp_segment_merge_max_pivot_rows',
            self::DEFAULT_MERGE_MAX_PIVOT_ROWS,
            $kind
        ));

        $preflight = self::mergePreflight($foundFromIds, $kind, $maxPivotRows);
        $preflight['found_from_ids'] = $foundFromIds;
        $preflight['missing_from_ids'] = $missingFromIds;

        if (!empty($params['dry_run'])) {
            return [
                'ok'               => true,
                'dry_run'          => true,
                'action'           => 'merge',
                'kind'             => $kind,
                'would_merge_from' => $foundFromIds,
                'would_merge_into' => self::format($to),
                'preflight'        => $preflight,
                'over_cap'         => $preflight['source_pivot_rows'] > $maxPivotRows,
                // A merge does not rewrite these; each still names a source that will be deleted.
                'automations_using_sources' => PermissionManager::currentUserCan('fcrm_read_funnels')
                    ? self::automationsUsing($kind, $foundFromIds)
                    : 'Not shown: needs the fcrm_read_funnels permission.',
                'note'             => 'Dry run only. No subscriber memberships or source rows were changed. Actual merges are synchronous and are not wrapped in a database transaction.',
            ];
        }

        if ($preflight['source_pivot_rows'] > $maxPivotRows) {
            $overBy = $preflight['source_pivot_rows'] - $maxPivotRows;

            return MCPHelper::error('limit_exceeded', sprintf(
                'Merge would process %1$d source pivot rows across %2$d unique subscribers; the synchronous cap is %3$d (%4$d rows over). Split from_%5$s_ids into smaller requests whose source_pivot_rows stay within the cap. If one source alone exceeds the cap, use the wp-admin/manual bulk workflow or deliberately raise fluent_crm/mcp_segment_merge_max_pivot_rows.',
                $preflight['source_pivot_rows'],
                $preflight['unique_subscribers'],
                $maxPivotRows,
                $overBy,
                $kind
            ), $preflight + [
                'over_by' => $overBy,
                'suggested_action' => sprintf(
                    'Split from_%s_ids into smaller requests; dry_run each group first.',
                    $kind
                ),
            ]);
        }

        $subscriberWork = self::mergeSubscriberWork($foundFromIds, $kind);
        $totals = self::mergeRepivot($subscriberWork, $foundFromIds, $to, $kind);

        $repivoted        = $totals['repivoted'];
        $uniqueRepivoted  = $totals['unique'];
        $batchesProcessed = $totals['batches'];

        // Re-read for memberships attached mid-merge; deleting the sources would drop them.
        $sweeps = 0;
        for ($i = 0; $i < self::MERGE_MAX_SWEEPS; $i++) {
            $fresh = self::mergeSubscriberWork($foundFromIds, $kind);
            if (!$fresh) {
                break;
            }
            $sweeps++;
            $sweptTotals = self::mergeRepivot($fresh, $foundFromIds, $to, $kind);
            $repivoted        += $sweptTotals['repivoted'];
            $uniqueRepivoted  += $sweptTotals['unique'];
            $batchesProcessed += $sweptTotals['batches'];
        }

        // Final phase is atomic: re-point leftover source rows, delete sources, fire hooks after commit.
        fluentCrmDb()->beginTransaction();

        $deferredDeletedIds = [];
        $stragglers = 0;

        try {
            $stragglers = self::mergeRepointStragglers($foundFromIds, (int) $to->id, $kind);

            foreach ($fromRows as $fromRow) {
                $fromId = (int) $fromRow->id;
                $fromRow->delete();
                $deferredDeletedIds[] = $fromId;
            }
        } catch (\Throwable $e) {
            fluentCrmDb()->rollBack();
            throw $e;
        }

        fluentCrmDb()->commit();

        foreach ($deferredDeletedIds as $fromId) {
            do_action(self::deletedHook($kind), $fromId);
        }

        MCPHelper::flushSegmentRefCache();

        return [
            'ok'                 => true,
            'action'             => 'merge',
            'kind'               => $kind,
            'merged_from'        => $foundFromIds,
            'merged_into'        => self::format($to),
            'subscribers_repivoted' => $repivoted,
            'subscribers_seen'      => $preflight['source_pivot_rows'],
            'unique_subscribers_repivoted' => $uniqueRepivoted,
            'batches_processed'     => $batchesProcessed,
            'concurrent_sweeps'     => $sweeps,
            'stragglers_repivoted'  => $stragglers,
            'preflight'             => $preflight,
            'missing_from_ids'      => $missingFromIds,
            'note'                  => 'Each subscriber attached to a "from" target is now attached to "to" and the "from" rows are deleted. Re-running this merge with the same ids is a safe no-op. Memberships created while the merge was running are picked up by follow-up sweeps, and those still outstanding when the final phase begins are moved to the target directly, preserving the membership but not firing the per-contact attach hooks (see stragglers_repivoted). A write landing inside that final transaction is not covered: FluentCRM has no lock shared with the attach paths, so avoid merging a segment that is actively being written to.',
        ];
    }

    /**
     * Re-pivot one work set onto the merge target via attach/detach, so per-contact hooks fire.
     *
     * @param array  $work      subscriber_id => count of source pivot rows
     * @param array  $sourceIds
     * @param object $to        Merge target row
     * @param string $kind      'tag' or 'list'
     * @return array{repivoted:int, unique:int, batches:int}
     */
    private static function mergeRepivot($work, $sourceIds, $to, $kind)
    {
        $repivoted = 0;
        $unique    = 0;
        $batches   = 0;

        foreach (array_chunk(array_keys($work), self::MERGE_BATCH_SIZE) as $subscriberIds) {
            $batches++;
            $subscribers = Subscriber::whereIn('id', $subscriberIds)->get();
            foreach ($subscribers as $sub) {
                if ($kind === 'list') {
                    $sub->attachLists([$to->id]);
                    $sub->detachLists($sourceIds);
                } else {
                    $sub->attachTags([$to->id]);
                    $sub->detachTags($sourceIds);
                }
                $repivoted += $work[(int) $sub->id] ?? 0;
                $unique++;
            }
        }

        return ['repivoted' => $repivoted, 'unique' => $unique, 'batches' => $batches];
    }

    /**
     * Move any source pivot rows still present onto the merge target with raw SQL.
     * Attach hooks do not fire for these rows.
     *
     * @param array  $sourceIds
     * @param int    $toId
     * @param string $kind
     * @return int Memberships that would otherwise have been lost.
     */
    private static function mergeRepointStragglers($sourceIds, $toId, $kind)
    {
        if (!$sourceIds) {
            return 0;
        }

        global $wpdb;
        $pivotTable   = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType   = self::segmentObjectType($kind);
        $placeholders = implode(',', array_fill(0, count($sourceIds), '%d'));

        $subscriberIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT subscriber_id FROM {$pivotTable}
             WHERE object_type = %s AND object_id IN ({$placeholders})",
            array_merge([$objectType], $sourceIds)
        ));

        $subscriberIds = array_values(array_filter(array_map('intval', (array) $subscriberIds)));
        if (!$subscriberIds) {
            return 0;
        }

        $alreadyOnTarget = [];
        foreach (array_chunk($subscriberIds, self::MERGE_BATCH_SIZE) as $chunk) {
            $chunkPlaceholders = implode(',', array_fill(0, count($chunk), '%d'));
            $found = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT subscriber_id FROM {$pivotTable}
                 WHERE object_type = %s AND object_id = %d AND subscriber_id IN ({$chunkPlaceholders})",
                array_merge([$objectType, $toId], $chunk)
            ));
            foreach ((array) $found as $id) {
                $alreadyOnTarget[(int) $id] = true;
            }
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$pivotTable} WHERE object_type = %s AND object_id IN ({$placeholders})",
            array_merge([$objectType], $sourceIds)
        ));

        $needTarget = array_values(array_filter($subscriberIds, function ($id) use ($alreadyOnTarget) {
            return !isset($alreadyOnTarget[(int) $id]);
        }));

        if (!$needTarget) {
            return 0;
        }

        // Same insert shape as Subscriber::attachTags().
        $now = current_time('mysql');
        foreach (array_chunk($needTarget, self::MERGE_BATCH_SIZE) as $chunk) {
            $values = [];
            $args   = [];
            foreach ($chunk as $subscriberId) {
                $values[] = '(%d, %d, %s, %s, %s)';
                array_push($args, (int) $subscriberId, $toId, $objectType, $now, $now);
            }
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$pivotTable} (subscriber_id, object_id, object_type, created_at, updated_at) VALUES " . implode(',', $values),
                $args
            ));
        }

        return count($needTarget);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * Automations that name any of these tags or lists, as {id, title, status, uses}.
     */
    private static function automationsUsing($kind, array $ids)
    {
        $usage = FunnelTools::automationsUsingSegments([$kind => $ids]);
        if (!$usage) {
            return [];
        }

        $out = [];
        foreach (\FluentCrm\App\Models\Funnel::whereIn('id', array_keys($usage))->orderBy('id', 'ASC')->get() as $funnel) {
            $out[] = [
                'id'     => (int) $funnel->id,
                'title'  => $funnel->title,
                'status' => $funnel->status,
                'uses'   => $usage[(int) $funnel->id],
            ];
        }

        return $out;
    }

    private static function find($id, $kind)
    {
        return $kind === 'list' ? Lists::find($id) : Tag::find($id);
    }

    private static function lookupByTitleOrSlug($title, $slug, $kind)
    {
        $modelClass = $kind === 'list' ? Lists::class : Tag::class;
        return $modelClass::where('title', $title)->orWhere('slug', $slug)->first();
    }

    private static function format($row)
    {
        return [
            'id'          => (int) $row->id,
            'title'       => $row->title,
            'slug'        => $row->slug,
            'description' => $row->description ?? '',
        ];
    }

    private static function attachedSubscriberCount($row, $kind)
    {
        return (int) ($kind === 'list'
            ? $row->subscribers()->count()
            : $row->subscribers()->count());
    }

    /**
     * Return fixed-cost aggregate counts before a merge writes anything.
     */
    private static function mergePreflight($sourceIds, $kind, $maxPivotRows)
    {
        $sourceCounts = [];
        foreach ($sourceIds as $sourceId) {
            $sourceCounts[(int) $sourceId] = 0;
        }

        if (!$sourceIds) {
            return [
                'source_pivot_rows'     => 0,
                'unique_subscribers'    => 0,
                'per_source'            => $sourceCounts,
                'max_source_pivot_rows' => $maxPivotRows,
                'batches_required'      => 0,
            ];
        }

        global $wpdb;
        $pivotTable = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType = self::segmentObjectType($kind);
        $placeholders = implode(',', array_fill(0, count($sourceIds), '%d'));
        $whereArgs = array_merge([$objectType], $sourceIds);

        $aggregate = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS source_pivot_rows, COUNT(DISTINCT subscriber_id) AS unique_subscribers
             FROM {$pivotTable}
             WHERE object_type = %s AND object_id IN ({$placeholders})",
            $whereArgs
        ));

        $perSourceRows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id, COUNT(*) AS source_pivot_rows
             FROM {$pivotTable}
             WHERE object_type = %s AND object_id IN ({$placeholders})
             GROUP BY object_id",
            $whereArgs
        ));

        foreach ($perSourceRows as $row) {
            $sourceCounts[(int) $row->object_id] = (int) $row->source_pivot_rows;
        }

        $sourcePivotRows = (int) ($aggregate->source_pivot_rows ?? 0);
        $uniqueSubscribers = (int) ($aggregate->unique_subscribers ?? 0);

        return [
            'source_pivot_rows'     => $sourcePivotRows,
            'unique_subscribers'    => $uniqueSubscribers,
            'per_source'            => $sourceCounts,
            'max_source_pivot_rows' => $maxPivotRows,
            'batches_required'      => $uniqueSubscribers
                ? (int) ceil($uniqueSubscribers / self::MERGE_BATCH_SIZE)
                : 0,
        ];
    }

    /**
     * Map subscriber id to its number of source pivot rows.
     */
    private static function mergeSubscriberWork($sourceIds, $kind)
    {
        if (!$sourceIds) {
            return [];
        }

        global $wpdb;
        $pivotTable = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType = self::segmentObjectType($kind);
        $placeholders = implode(',', array_fill(0, count($sourceIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT subscriber_id, COUNT(*) AS source_pivot_rows
             FROM {$pivotTable}
             WHERE object_type = %s AND object_id IN ({$placeholders})
             GROUP BY subscriber_id
             ORDER BY subscriber_id ASC",
            array_merge([$objectType], $sourceIds)
        ));

        $work = [];
        foreach ($rows as $row) {
            $work[(int) $row->subscriber_id] = (int) $row->source_pivot_rows;
        }

        return $work;
    }

    private static function segmentObjectType($kind)
    {
        return $kind === 'list' ? Lists::class : Tag::class;
    }

    private static function createdHook($kind)
    {
        return $kind === 'list' ? 'fluent_crm/list_created' : 'fluent_crm/tag_created';
    }

    private static function deletedHook($kind)
    {
        return $kind === 'list' ? 'fluent_crm/list_deleted' : 'fluent_crm/tag_deleted';
    }
}
