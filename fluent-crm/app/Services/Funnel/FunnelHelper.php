<?php

namespace FluentCrm\App\Services\Funnel;

use FluentCrm\App\Hooks\Handlers\FunnelHandler;
use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\FunnelMetric;
use FluentCrm\App\Models\FunnelSequence;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

class FunnelHelper
{
    /**
     * Meta key holding an automation's sticky note.
     * Kept outside `Funnel::settings`, which saveFunnelSequence() overwrites wholesale.
     */
    const STICKY_NOTE_META_KEY = 'sticky_note';

    /**
     * Normalize a plain-text sticky note for storage. Returns null for an empty note (delete it).
     *
     * @param mixed $content Raw note body from the request.
     * @return array|null {content: string, updated_by: int, updated_at: string}
     */
    public static function sanitizeStickyNote($content)
    {
        $content = sanitize_textarea_field((string)$content);
        if ($content === '' || trim($content) === '') {
            return null;
        }

        if (mb_strlen($content) > 2000) {
            $content = mb_substr($content, 0, 2000);
        }

        return [
            'content'    => $content,
            'updated_by' => get_current_user_id(),
            // Site time, matching Funnel::$updated_at.
            'updated_at' => current_time('mysql')
        ];
    }

    /**
     * Read a funnel's sticky note in the shape the editor expects, including the author name.
     * Accepts a legacy bare-string value.
     *
     * @param Funnel $funnel
     * @return array|null
     */
    public static function getStickyNote($funnel)
    {
        $note = $funnel->getMeta(static::STICKY_NOTE_META_KEY, null);

        if (empty($note)) {
            return null;
        }

        if (is_string($note)) {
            $note = ['content' => $note];
        }

        if (!is_array($note) || empty($note['content'])) {
            return null;
        }

        $userId = (int)Arr::get($note, 'updated_by');
        $author = '';
        if ($userId) {
            $user = get_userdata($userId);
            $author = $user ? $user->display_name : '';
        }

        return [
            'content'      => (string)$note['content'],
            'updated_by'   => $userId,
            'author_name'  => $author,
            'updated_at'   => (string)Arr::get($note, 'updated_at', '')
        ];
    }

    /**
     * Move a contact to a step of their automation and run from there now.
     *
     * Shared by the Web UI "Advance to Step" (REST forceAdvanceSubscriber) and MCP advance_now.
     * A goal the contact was waiting on is recorded as skipped. The processor always resumes
     * after a stored step, so the first step of a Yes/No branch cannot be reached through the
     * cursor: that branch is entered the way the conditional itself does it, through
     * FunnelProcessor::initChildSequences(). Any other step resumes from the step before it in
     * the same branch (or top level).
     *
     * @param FunnelSubscriber $funnelSubscriber
     * @param FunnelSequence   $target     a step of the same automation
     * @param string           $skipNote   note on the skipped goal metric
     * @return true|\WP_Error
     */
    public static function advanceSubscriberToSequence($funnelSubscriber, $target, $skipNote)
    {
        if (in_array($funnelSubscriber->status, ['completed', 'cancelled', 'pending'], true)) {
            return new \WP_Error('invalid_status', sprintf(
                /* translators: %s: status of the contact in the automation */
                esc_html__('Cannot advance a contact with status: %s', 'fluent-crm'),
                $funnelSubscriber->status
            ));
        }

        if ((int)$target->funnel_id !== (int)$funnelSubscriber->funnel_id) {
            return new \WP_Error('not_found', __('Target sequence not found', 'fluent-crm'));
        }

        if ($funnelSubscriber->status === 'waiting') {
            $benchmarkSeq = FunnelSequence::find($funnelSubscriber->next_sequence_id);
            if ($benchmarkSeq) {
                FunnelMetric::updateOrCreate(
                    [
                        'funnel_id'     => (int)$funnelSubscriber->funnel_id,
                        'sequence_id'   => $benchmarkSeq->id,
                        'subscriber_id' => (int)$funnelSubscriber->subscriber_id,
                    ],
                    [
                        'benchmark_value'    => 0,
                        'benchmark_currency' => 'USD',
                        'status'             => 'skipped',
                        'notes'              => $skipNote,
                    ]
                );
                self::changeFunnelSubSequenceStatus($funnelSubscriber->id, $benchmarkSeq->id, 'skipped');
            }
        }

        // The step before the target in its own branch, or at the top level.
        $prevQuery = FunnelSequence::where('funnel_id', (int)$funnelSubscriber->funnel_id)
            ->where('sequence', '<', $target->sequence)
            ->orderBy('sequence', 'DESC');
        if ($target->parent_id) {
            $prevQuery->where('parent_id', $target->parent_id)
                ->where('condition_type', $target->condition_type);
        } else {
            $prevQuery->where(function ($q) {
                $q->whereNull('parent_id')->orWhere('parent_id', '0');
            });
        }
        $prevSequence = $prevQuery->first();

        $processor = new FunnelProcessor();

        if ($target->parent_id && !$prevSequence) {
            $parent = FunnelSequence::where('id', $target->parent_id)
                ->where('funnel_id', (int)$funnelSubscriber->funnel_id)
                ->first();
            $subscriber = Subscriber::find($funnelSubscriber->subscriber_id);
            if (!$parent || !$subscriber) {
                return new \WP_Error('not_found', __('Target sequence not found', 'fluent-crm'));
            }

            $funnelSubscriber->last_sequence_id = $parent->id;
            $funnelSubscriber->next_sequence_id = $target->id;
            $funnelSubscriber->next_sequence = $target->sequence;
            $funnelSubscriber->status = 'active';
            $funnelSubscriber->next_execution_time = current_time('mysql');
            $funnelSubscriber->save();

            // The same contact-status guard processFunnelAction() applies on every other target.
            $funnel = Funnel::find((int)$funnelSubscriber->funnel_id);
            $forceRun = $funnel && Arr::get($funnel->settings, '__force_run_actions') === 'yes';
            if (!in_array($subscriber->status, ['subscribed', 'transactional'], true) && !$forceRun) {
                FunnelSubscriber::where('id', $funnelSubscriber->id)->update(['status' => 'cancelled']);
                return true;
            }

            $processor->initChildSequences($parent, $target->condition_type === 'yes', $subscriber, $funnelSubscriber->id, null);
            return true;
        }

        $funnelSubscriber->last_sequence_id = $prevSequence ? $prevSequence->id : 0;
        $funnelSubscriber->next_sequence_id = $target->id;
        $funnelSubscriber->next_sequence = $target->sequence;
        $funnelSubscriber->status = 'active';
        $funnelSubscriber->next_execution_time = current_time('mysql');
        $funnelSubscriber->save();

        $processor->processFunnelAction($funnelSubscriber);

        return true;
    }

    public static function changeFunnelSubSequenceStatus($funnelSubId, $sequenceId, $status = 'complete')
    {
        return FunnelSubscriber::where('id', $funnelSubId)
            ->update([
                'last_sequence_status' => $status,
                'last_sequence_id'     => $sequenceId,
                'last_executed_time'   => current_time('mysql')
            ]);
    }

    public static function getUpdateOptions()
    {
        return [
            [
                'id'    => 'update',
                'title' => __('Update if Exists', 'fluent-crm')
            ],
            [
                'id'    => 'skip_all_if_exist',
                'title' => __('Skip this automation if the contact already exists', 'fluent-crm')
            ]
        ];
    }

    public static function prepareUserData($user)
    {
        $subscriber = Helper::getWPMapUserInfo($user);
        $subscriber['source'] = 'web';
        return $subscriber;
    }

    public static function getSubscriber($emailOrUserId)
    {
        $column = 'email';
        if (is_int($emailOrUserId)) {
            $column = 'user_id';
        }

        return Subscriber::where($column, $emailOrUserId)->first();
    }

    public static function createOrUpdateContact($data)
    {
        return FluentCrmApi('contacts')->createOrUpdate($data);
    }

    /**
     * The contact an automation goal (benchmark) runs for, without touching consent.
     *
     * Goal handlers fire for every matching event on the site, whether or not the person is in
     * the automation. An existing contact is returned unchanged, so a goal never subscribes or
     * confirms anyone. A new contact is created only when the goal lets new contacts enter
     * (can_enter is not "no"), and as "transactional": they get this automation's emails but
     * not marketing campaigns, which go to subscribed contacts only.
     *
     * @param array  $subscriberData contact fields from the event (email required)
     * @param object $benchMark      the goal step
     * @return \FluentCrm\App\Models\Subscriber|null
     */
    public static function contactForBenchmark($subscriberData, $benchMark)
    {
        $email = (string)Arr::get($subscriberData, 'email');
        if (!is_email($email)) {
            return null;
        }

        if ($existing = self::getSubscriber($email)) {
            return $existing;
        }

        if (Arr::get((array)$benchMark->settings, 'can_enter') === 'no') {
            return null;
        }

        $subscriberData['status'] = 'transactional';

        return self::createOrUpdateContact($subscriberData) ?: null;
    }

    public static function getUserRoles($keyed = false)
    {
        if (!function_exists('get_editable_roles')) {
            require_once(ABSPATH . '/wp-admin/includes/user.php');
        }

        $roles = \get_editable_roles();
        $formattedRoles = [];
        foreach ($roles as $roleKey => $role) {
            if ($keyed) {
                $formattedRoles[$roleKey] = $role['name'];
            } else {
                $formattedRoles[] = [
                    'id'    => $roleKey,
                    'title' => $role['name']
                ];
            }

        }
        return $formattedRoles;
    }

    public static function ifAlreadyInFunnel($funnelId, $subscriberId)
    {
        return FunnelSubscriber::where('funnel_id', $funnelId)
            ->where('subscriber_id', $subscriberId)
            ->first();
    }

    public static function maybeExplodeFullName($data)
    {
        if (empty($data['first_name']) && empty($data['last_name'])) {
            return $data;
        }

        if (empty($data['first_name']) || !empty($data['last_name'])) {
            return $data;
        }

        $fullNameArray = explode(' ', $data['first_name']);
        $data['first_name'] = array_shift($fullNameArray);
        if ($fullNameArray) {
            $data['last_name'] = implode(' ', $fullNameArray);
        }

        return $data;
    }

    public static function syncTags($subscriber, $tags = [])
    {
        if ($tags) {
            $subscriber->attachTags($tags);
        }
    }

    public static function syncLists($subscriber, $lists = [])
    {
        if ($lists) {
            $subscriber->attachLists($lists);
        }
    }

    public static function getPrimaryContactFieldMaps()
    {
        return [
            'first_name' => [
                'type'  => 'value_options',
                'label' => __('First name', 'fluent-crm')
            ],
            'last_name'  => [
                'type'  => 'value_options',
                'label' => __('Last name', 'fluent-crm')
            ],
            'email'      => [
                'type'  => 'value_options',
                'label' => __('Email', 'fluent-crm')
            ]
        ];
    }

    public static function getSecondaryContactFieldMaps()
    {
        $mainFields = [
            'prefix'         => [
                'type'  => 'value_options',
                'label' => __('Name Prefix', 'fluent-crm')
            ],
            'address_line_1' => [
                'type'  => 'value_options',
                'label' => __('Address Line 1', 'fluent-crm')
            ],
            'address_line_2' => [
                'type'  => 'value_options',
                'label' => __('Address Line 2', 'fluent-crm')
            ],
            'postal_code'    => [
                'type'  => 'value_options',
                'label' => __('Postal code', 'fluent-crm')
            ],
            'city'           => [
                'type'  => 'value_options',
                'label' => __('City', 'fluent-crm')
            ],
            'state'          => [
                'type'  => 'value_options',
                'label' => __('State', 'fluent-crm')
            ],
            'country'        => [
                'type'  => 'value_options',
                'label' => __('Country', 'fluent-crm')
            ],
            'phone'          => [
                'type'  => 'value_options',
                'label' => __('Phone', 'fluent-crm')
            ]
        ];

        $customFields = fluentcrm_get_option('contact_custom_fields', []);
        if ($customFields) {
            foreach ($customFields as $item) {
                $mainFields['custom.' . $item['slug']] = [
                    'type'  => 'value_options',
                    'label' => $item['label']
                ];
            }
        }

        return $mainFields;

    }

    public static function removeSubscribersFromFunnel($funnelId, $subscriberIds)
    {
        FunnelSubscriber::where('funnel_id', $funnelId)
            ->whereIn('subscriber_id', $subscriberIds)
            ->delete();

        FunnelMetric::where('funnel_id', $funnelId)
            ->whereIn('subscriber_id', $subscriberIds)
            ->delete();

        return true;
    }

    /**
     * Save an automation's settings and steps from the editor payload.
     *
     * All writes run in one transaction: a step's saving filter can reject the save partway through,
     * and without the rollback the funnel was left published with only some of its steps written.
     *
     * @param int $funnelId
     * @param array $data Editor payload (sequences, funnel_settings, conditions, funnel_title, status, funnel_description)
     * @return Funnel
     * @throws \Exception When the payload is invalid or a step's saving filter rejects it; nothing is saved.
     */
    public static function saveFunnelSequence($funnelId, $data)
    {
        $db = fluentCrmDb();
        $db->beginTransaction();
        try {
            $funnel = self::writeFunnelSequence($funnelId, $data);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $funnel;
    }

    private static function writeFunnelSequence($funnelId, $data)
    {
        // Steps missing from the payload are deleted, so a missing or malformed payload aborts before any write.
        if (!array_key_exists('sequences', $data)) {
            throw new \Exception(esc_html__('The sequences payload is missing. Automation steps were not saved.', 'fluent-crm'));
        }

        $sequences = $data['sequences'];
        if (!is_array($sequences)) {
            $sequences = \json_decode((string)$sequences, true);
        }

        if (!is_array($sequences)) {
            throw new \Exception(esc_html__('The sequences payload is malformed. Automation steps were not saved.', 'fluent-crm'));
        }

        // The engine supports exactly one level of conditionals.
        foreach ($sequences as $sequenceItem) {
            if (Arr::get($sequenceItem, 'type') != 'conditional') {
                continue;
            }
            foreach ((array)Arr::get($sequenceItem, 'children', []) as $branchChildren) {
                foreach ((array)$branchChildren as $childSequence) {
                    if (is_array($childSequence) && Arr::get($childSequence, 'type') == 'conditional') {
                        throw new \Exception(esc_html__('Nested conditional blocks are not supported. Automation steps were not saved.', 'fluent-crm'));
                    }
                }
            }
        }

        PrivilegedSteps::assertCanSave($sequences, (int)$funnelId);

        $funnelSettings = \json_decode(Arr::get($data, 'funnel_settings'), true);
        $funnelConditions = \json_decode(Arr::get($data, 'conditions', '[]'), true);

        $funnel = Funnel::findOrFail($funnelId);

        if (is_array($funnelSettings)) {
            $funnel->settings = $funnelSettings;
        }

        if ($funnelTitle = Arr::get($data, 'funnel_title')) {
            $funnel->title = sanitize_text_field($funnelTitle);
        }

        if (is_array($funnelConditions)) {
            $funnel->conditions = $funnelConditions;
        }
        // Only known statuses are accepted; a NULL status freezes in-flight subscribers.
        $requestedStatus = Arr::get($data, 'status');
        if (in_array($requestedStatus, ['draft', 'published'], true)) {
            $funnel->status = $requestedStatus;
        }
        $funnel->updated_at = current_time('mysql');
        $funnel->save();

        // An omitted description is left alone; an empty one clears it.
        if (array_key_exists('funnel_description', $data)) {
            $rawDescription = Arr::get($data, 'funnel_description');
            // A non-scalar description payload clears it.
            $funnelDescription = is_scalar($rawDescription)
                ? sanitize_textarea_field((string)$rawDescription)
                : '';
            if ($funnelDescription) {
                $funnel->updateMeta('description', $funnelDescription);
            } else {
                $funnel->deleteMeta('description');
            }
        }

        // Step order before this save, to re-point contacts whose next step gets deleted.
        $previousSteps = FunnelSequence::where('funnel_id', $funnel->id)
            ->orderBy('sequence', 'ASC')
            ->get(['id', 'parent_id', 'condition_type']);

        $sequenceIds = [];
        $cDelay = 0;
        $delay = 0;

        $indexCount = 0;

        foreach ($sequences as $index => $sequence) {
            $sequence['funnel_id'] = $funnel->id;
            $sequence['status'] = 'published';
            $sequence['conditions'] = [];
            $sequence['sequence'] = $indexCount + 1;
            $sequence['c_delay'] = $cDelay;
            $sequence['delay'] = $delay;
            $delay = 0;

            $actionName = $sequence['action_name'];

            if ($actionName == 'fluentcrm_wait_times') {
                $delay = self::getDelayInSecond($sequence['settings']);
                $cDelay += $delay;
            }

            $sequence = apply_filters('fluentcrm_funnel_sequence_saving_' . $sequence['action_name'], $sequence, $funnel);

            if (Arr::get($sequence, 'type') == 'benchmark') {
                $delay = $sequence['delay'];
            }

            $sequence['id'] = self::createOrUpdateSequence($sequence);
            $sequenceIds[] = $sequence['id'];

            if ($sequence['type'] == 'conditional') {
                $childIds = self::saveChildSequences($sequence, $funnel);
                $indexCount += count($childIds);
                $sequenceIds = array_unique(array_merge($sequenceIds, $childIds));
            }

            $indexCount += 1;
        }

        if ($sequenceIds) {
            $deletingSequences = FunnelSequence::whereNotIn('id', $sequenceIds)
                ->where('funnel_id', $funnel->id)
                ->get();
        } else {
            $deletingSequences = FunnelSequence::where('funnel_id', $funnel->id)->get();
        }

        if ($deletingSequences->count()) {
            foreach ($deletingSequences as $deletingSequence) {
                do_action('fluentcrm_funnel_sequence_deleting_' . $deletingSequence->action_name, $deletingSequence, $funnel);
                $deletingSequence->delete();
            }

            // Unstick subscribers waiting on deleted benchmark sequences
            FunnelSubscriber::where('funnel_id', $funnel->id)
                ->where('status', 'waiting')
                ->whereNotIn('next_sequence_id', $sequenceIds)
                ->update([
                    'status'              => 'active',
                    'next_execution_time' => current_time('mysql')
                ]);

            self::repointToSurvivingSteps($funnel->id, $previousSteps, $deletingSequences, $sequenceIds);

            // Clear pointers to deleted steps; the engine then resumes from last_sequence_id.
            FunnelSubscriber::where('funnel_id', $funnel->id)
                ->whereIn('status', ['active', 'waiting'])
                ->whereNotNull('next_sequence_id')
                ->whereNotIn('next_sequence_id', $sequenceIds)
                ->update([
                    'next_sequence_id' => null
                ]);

            // Active rows with no pointer and no execution time are invisible to cron; schedule them.
            FunnelSubscriber::where('funnel_id', $funnel->id)
                ->where('status', 'active')
                ->whereNull('next_sequence_id')
                ->whereNull('next_execution_time')
                ->update([
                    'next_execution_time' => current_time('mysql')
                ]);
        }

        // Resync next_sequence ordinals after renumbering.
        global $wpdb;
        $subscribersTable = $wpdb->prefix . 'fc_funnel_subscribers';
        $sequencesTable = $wpdb->prefix . 'fc_funnel_sequences';

        $wpdb->query($wpdb->prepare(
            "UPDATE {$subscribersTable} fs
             JOIN {$sequencesTable} seq ON fs.next_sequence_id = seq.id
             SET fs.next_sequence = seq.sequence
             WHERE fs.funnel_id = %d
               AND fs.status IN ('active', 'waiting')
               AND (fs.next_sequence IS NULL OR fs.next_sequence != seq.sequence)",
            $funnel->id
        ));

        (new FunnelHandler())->resetFunnelIndexes();

        return $funnel;
    }

    /**
     * Move contacts whose next step was deleted on to the first surviving step after it.
     *
     * Without this the pointer was only cleared, and when the contact's last step was deleted too
     * the engine resumed from a stale position number and skipped a step that still exists. The
     * replacement is the next surviving step in the old order on the same path: the same branch
     * for a branch step, the main path for a top-level step. Contacts with no surviving step after
     * theirs are left to the existing cleanup.
     *
     * @param int $funnelId
     * @param \FluentCrm\Framework\Support\Collection $previousSteps steps before the save, in order
     * @param \FluentCrm\Framework\Support\Collection $deletedSteps steps removed by the save
     * @param int[] $keptIds step ids that remain
     */
    private static function repointToSurvivingSteps($funnelId, $previousSteps, $deletedSteps, $keptIds)
    {
        $keptIds = array_map('intval', $keptIds);
        $order = $previousSteps->values();

        foreach ($deletedSteps as $deleted) {
            $position = $order->search(function ($step) use ($deleted) {
                return (int)$step->id === (int)$deleted->id;
            });
            if ($position === false) {
                continue;
            }

            $replacement = null;
            for ($i = $position + 1; $i < $order->count(); $i++) {
                $candidate = $order[$i];
                if (!in_array((int)$candidate->id, $keptIds, true)) {
                    continue;
                }
                $samePath = $deleted->parent_id
                    ? ((int)$candidate->parent_id === (int)$deleted->parent_id && $candidate->condition_type === $deleted->condition_type)
                    : !$candidate->parent_id;
                if ($samePath || ($deleted->parent_id && !$candidate->parent_id)) {
                    $replacement = FunnelSequence::find($candidate->id);
                    break;
                }
            }

            if (!$replacement) {
                continue;
            }

            FunnelSubscriber::where('funnel_id', $funnelId)
                ->whereIn('status', ['active', 'waiting'])
                ->where('next_sequence_id', $deleted->id)
                ->update([
                    'next_sequence_id' => $replacement->id,
                    'next_sequence'    => $replacement->sequence
                ]);
        }

        self::repointBranchCursors($funnelId, $order, $deletedSteps, $keptIds);
    }

    /**
     * Keep contacts whose last completed step was deleted inside a Yes/No branch in that branch.
     *
     * The engine resumes after last_sequence_id, and only a step inside the branch makes it
     * continue there; with that step gone it fell back to top-level steps and skipped the rest of
     * the branch. The cursor moves to the nearest surviving earlier step of the same branch. When
     * none is left, the contact is pointed at the branch's condition, which runs again (its
     * metric is cleared) and enters the matching branch from its first surviving step.
     *
     * @param int $funnelId
     * @param \FluentCrm\Framework\Support\Collection $order the automation's steps before the save, in order
     * @param \FluentCrm\Framework\Support\Collection $deletedSteps
     * @param int[] $keptIds
     */
    private static function repointBranchCursors($funnelId, $order, $deletedSteps, $keptIds)
    {
        // The nearest kept step before $step at the same level (same branch, or top level).
        $earlierSibling = function ($step) use ($order, $keptIds) {
            $position = $order->search(function ($s) use ($step) {
                return (int)$s->id === (int)$step->id;
            });
            for ($i = ($position === false ? -1 : $position - 1); $i >= 0; $i--) {
                $candidate = $order[$i];
                $sameLevel = $step->parent_id
                    ? ((int)$candidate->parent_id === (int)$step->parent_id && $candidate->condition_type === $step->condition_type)
                    : !$candidate->parent_id;
                if ($sameLevel && in_array((int)$candidate->id, $keptIds, true)) {
                    return $candidate;
                }
            }
            return null;
        };

        foreach ($deletedSteps as $deleted) {
            if (!$deleted->parent_id) {
                continue;
            }

            $rows = FunnelSubscriber::where('funnel_id', $funnelId)
                ->whereIn('status', ['active', 'waiting'])
                ->where('last_sequence_id', $deleted->id);
            if (!(clone $rows)->exists()) {
                continue;
            }

            if ($cursor = $earlierSibling($deleted)) {
                $rows->update(['last_sequence_id' => $cursor->id]);
                continue;
            }

            $condition = in_array((int)$deleted->parent_id, $keptIds, true) ? FunnelSequence::find($deleted->parent_id) : null;
            if (!$condition) {
                continue;
            }
            $before = $earlierSibling($condition);
            if (!$before && $condition->parent_id) {
                continue; // a condition nested first in another branch: left as it was
            }

            // The engine runs a step only when it records the step's metric, so the condition's
            // metric is removed to let it run again (it is recorded again when it does).
            // A subquery, not a list of ids: the affected contacts can be the automation's whole audience.
            $deletedId = (int)$deleted->id;
            FunnelMetric::where('funnel_id', $funnelId)
                ->where('sequence_id', $condition->id)
                ->whereIn('subscriber_id', function ($q) use ($funnelId, $deletedId) {
                    $q->from('fc_funnel_subscribers')
                        ->select('subscriber_id')
                        ->where('funnel_id', $funnelId)
                        ->whereIn('status', ['active', 'waiting'])
                        ->where('last_sequence_id', $deletedId);
                })
                ->delete();

            $rows->update([
                'last_sequence_id' => $before ? $before->id : 0,
                'next_sequence_id' => $condition->id,
                'next_sequence'    => $condition->sequence
            ]);
        }
    }

    /**
     * Create a step, or update one that belongs to the automation being saved.
     *
     * A step id from another automation (a crafted or stale payload) is saved as a new step here,
     * so it can never rewrite and move that automation's step.
     *
     * @param array $sequence step data; funnel_id is the automation being saved
     * @return int the step id
     */
    private static function createOrUpdateSequence($sequence)
    {
        if (!empty($sequence['id']) && !FunnelSequence::where('id', $sequence['id'])->where('funnel_id', $sequence['funnel_id'])->exists()) {
            unset($sequence['id']);
        }

        if (empty($sequence['id'])) {
            $sequence['created_by'] = get_current_user_id();
            $createdSequence = FunnelSequence::create($sequence);
            do_action('fluent_crm/sequence_created_' . $createdSequence->action_name, $createdSequence);
            return $createdSequence->id;
        }

        $sequence['updated_at'] = current_time('mysql');
        $sequence['settings'] = \maybe_serialize($sequence['settings']);
        $sequence['conditions'] = \maybe_serialize($sequence['conditions']);

        $sequenceId = $sequence['id'];
        $data = Arr::only($sequence, (new FunnelSequence())->getFillable());

        FunnelSequence::where('id', $sequenceId)->where('funnel_id', $sequence['funnel_id'])->update($data);

        return $sequenceId;
    }

    public static function getDelayInSecond($settings)
    {
        $waitType = Arr::get($settings, 'wait_type');

        if (!$waitType && Arr::get($settings, 'is_timestamp_wait') == 'yes') {
            return 1;
        }

        if ($waitType == 'timestamp_wait' || $waitType == 'to_day') {
            return 1;
        }

        $unit = Arr::get($settings, 'wait_time_unit');
        $time = (int) Arr::get($settings, 'wait_time_amount');

        if ($unit == 'months') {
            // Months vary in length, so anchor to the current time.
            $now = current_time('timestamp');
            $delay = strtotime('+' . $time . ' months', $now) - $now;
        } else {
            $converter = 86400; // default day
            if ($unit == 'hours') {
                $converter = 3600; // hour
            } else if ($unit == 'minutes') {
                $converter = 60;
            }
            $delay = $time * $converter;
        }

        if (!$delay || $delay < 1) {
            $delay = 1;
        }

        return $delay;
    }

    public static function getCurrentDelayInSeconds($settings, $sequence = null, $funnelSubId = null)
    {
        $waitType = Arr::get($settings, 'wait_type');

        /*
         * For Specific Date and Time
         */
        if ((!$waitType && Arr::get($settings, 'is_timestamp_wait') == 'yes') || $waitType == 'timestamp_wait') {
            $timeStamp = current_time('timestamp');
            $waitTimes = strtotime(Arr::get($settings, 'wait_date_time'), $timeStamp) - $timeStamp;
            if ($waitTimes < 1) {
                $waitTimes = 0;
            }
            return apply_filters('fluent_crm/funnel_seq_delay_in_seconds', $waitTimes, $settings, $sequence, $funnelSubId);
        }

        if ($waitType && $waitType == 'to_day') {
            $nextDays = Arr::get($settings, 'to_day', []);
            $timeStampNow = current_time('timestamp');

            $nextDays = array_map(function ($dayName) {
                return substr($dayName, 0, 3);
            }, $nextDays);

            if (empty($nextDays)) { // if no day is selected
                $nextDays = [gmdate('D', $timeStampNow), gmdate('D', strtotime('+1 day', $timeStampNow))];
            }

            $nextTime = Arr::get($settings, 'to_day_time');
            if (!$nextTime) {
                $nextTime = gmdate('H:i', $timeStampNow);
            }

            $date = self::getEarliestDay($nextDays, $nextTime);

            $seconds = strtotime($date) - current_time('timestamp');
            $waitTimes = ($seconds < 1) ? 0 : $seconds;
            return apply_filters('fluent_crm/funnel_seq_delay_in_seconds', $waitTimes, $settings, $sequence, $funnelSubId);
        }


        if ($waitType == 'by_custom_field') {
            if (!$funnelSubId) {
                return apply_filters('fluent_crm/funnel_seq_delay_in_seconds', 60, $settings, $sequence, $funnelSubId);
            }

            $funnelSub = FunnelSubscriber::where('id', $funnelSubId)->first();

            if (!$funnelSub || !$funnelSub->subscriber) {
                return apply_filters('fluent_crm/funnel_seq_delay_in_seconds', 60, $settings, $sequence, $funnelSubId);
            }

            $customFieldKey = Arr::get($settings, 'by_custom_field', '');

            if (!$customFieldKey) {
                return apply_filters('fluent_crm/funnel_seq_delay_in_seconds', 60, $settings, $sequence, $funnelSubId);
            }

            $dateTime = null;

            if ($customFieldKey == '__date_of_birth__') {
                $dateTime = $funnelSub->subscriber->date_of_birth;
                if ($dateTime && !self::isValidYmd($dateTime)) {
                    $dateTime = null;
                }
                if ($dateTime) {
                    // Site-local year; roll forward only after the whole birthday has passed.
                    $localNow = current_time('timestamp');
                    $localYear = (int)gmdate('Y', $localNow);
                    $monthDay = gmdate('m-d', strtotime($dateTime));

                    $dateTime = $localYear . '-' . $monthDay;

                    if (strtotime($dateTime . ' 23:59:59') < $localNow) {
                        $dateTime = ($localYear + 1) . '-' . $monthDay;
                    }
                }
            } else {
                $meta = $funnelSub->subscriber->custom_field_meta()->where('key', $customFieldKey)->first();
                if ($meta) {
                    $dateTime = $meta->value;
                }
            }

            if (!$dateTime) {
                return apply_filters('fluent_crm/funnel_seq_delay_in_seconds', 60, $settings, $sequence, $funnelSubId);
            }

            $timeStamp = strtotime($dateTime);

            $waitTimes = $timeStamp - current_time('timestamp');

            if ($waitTimes < 1) {
                $waitTimes = 60;
            }

            return apply_filters('fluent_crm/funnel_seq_delay_in_seconds', $waitTimes, $settings, $sequence, $funnelSubId);
        }

        $unit = Arr::get($settings, 'wait_time_unit');
        $time = (int) Arr::get($settings, 'wait_time_amount');

        if ($unit == 'months') {
            // Months vary in length, so anchor to the current time.
            $now = current_time('timestamp');
            $waitTimes = strtotime('+' . $time . ' months', $now) - $now;
        } else {
            $converter = 86400; // default day
            if ($unit == 'hours') {
                $converter = 3600; // hour
            } else if ($unit == 'minutes') {
                $converter = 60;
            }
            $waitTimes = $time * $converter;
        }

        if (!$waitTimes || $waitTimes < 1) {
            $waitTimes = 1;
        }

        return apply_filters('fluent_crm/funnel_seq_delay_in_seconds', $waitTimes, $settings, $sequence, $funnelSubId);
    }

    /*
     * Get the next earliest day provided to $days array as ['Mon', Wed, 'Fri']
     * @param array $days
     * @return string $earliest
     */
    private static function getEarliestDay($days, $time = '')
    {
        $timestamp = current_time('timestamp');
        $timeStampsArray = [];
        for ($i = 0; $i < 8; $i++) {
            $timeStampsArray[] = $timestamp + ($i * 86400);
        }

        $earliest = gmdate('Y-m-d ' . $time . ':s', $timestamp);

        foreach ($timeStampsArray as $timeStampVal) {
            if (in_array(gmdate('D', $timeStampVal), $days)) {
                $earliest = gmdate('Y-m-d ' . $time . ':s', $timeStampVal);
                if (strtotime($earliest) - $timestamp > -60) {
                    return $earliest;
                }
            }
        }

        return $earliest;
    }

    /**
     * Check if a string is a valid calendar date in Y-m-d format (avoids strtotime normalizing invalid dates).
     *
     * @param string $ymd Date string (e.g. 2024-02-31).
     * @return bool
     */
    private static function isValidYmd($ymd)
    {
        if (!is_string($ymd) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function saveChildSequences($sequence, $funnel)
    {
        $sequenceIds = [];
        $indexCount = $sequence['sequence'];
        $childCats = Arr::get($sequence, 'children');
        foreach ($childCats as $category => $blocks) {
            $childDelay = 0;
            $childCDelay = 0;
            foreach ($blocks as $childIndex => $childSequence) {
                $childSequence['funnel_id'] = $funnel->id;
                $childSequence['status'] = 'published';
                $childSequence['parent_id'] = $sequence['id'];
                $childSequence['condition_type'] = $category;
                $childSequence['conditions'] = [];
                $childSequence['sequence'] = $indexCount + 1;
                $childSequence['c_delay'] = $sequence['c_delay'] + $childCDelay;
                $childSequence['delay'] = $sequence['delay'] + $childDelay;

                $childDelay = 0;

                /*
                 * For Delay Calculation
                 */
                $actionName = $childSequence['action_name'];
                if ($actionName == 'fluentcrm_wait_times') {
                    $childDelay = self::getDelayInSecond($childSequence['settings']);
                    $childCDelay += $childDelay;
                }

                $childSequence = apply_filters('fluentcrm_funnel_sequence_saving_' . $childSequence['action_name'], $childSequence, $funnel);
                $sequenceIds[] = self::createOrUpdateSequence($childSequence);
                $indexCount += 1;
            }
        }
        return $sequenceIds;
    }

    public static function getFunnelSequences($funnel, $isFiltered = false)
    {
        $sequences = FunnelSequence::where('funnel_id', $funnel->id)
            ->orderBy('sequence', 'ASC')
            ->get();

        if (!$isFiltered) {
            return $sequences;
        }

        $formattedSequences = [];
        foreach ($sequences as $sequence) {
            $sequenceArray = $sequence->toArray();
            $formattedSequences[] = apply_filters('fluentcrm_funnel_sequence_filtered_' . $sequence->action_name, $sequenceArray, $funnel);
        }

        return $formattedSequences;
    }

    public static function maybeMigrateConditions($funnelId)
    {
        $conditionSequences = FunnelSequence::where('funnel_id', $funnelId)
            ->where('type', 'conditional')
            ->where('action_name', '!=', 'funnel_condition')
            ->get();

        foreach ($conditionSequences as $conditionSequence) {
            self::migrateConditionSequence($conditionSequence);
        }

        return !$conditionSequences->isEmpty();
    }

    public static function migrateConditionSequence($sequence, $dryRun = false)
    {
        $conditionalBlocks = [
            'funnel_condition',
            'funnel_ab_testing'
        ];

        if ($sequence->type != 'conditional' || in_array($sequence->action_name, $conditionalBlocks)) {
            return $sequence;
        }

        $simpleMaps = [
            'fcrm_has_contact_tag'         => [
                'source'        => ['segment', 'tags'],
                'operator'      => 'in',
                'value_access'  => 'tags',
                'default_value' => [],
            ],
            'fcrm_has_contact_list'        => [
                'source'        => ['segment', 'lists'],
                'operator'      => 'in',
                'value_access'  => 'lists',
                'default_value' => [],
            ],
            'fcrm_has_user_role'           => [
                'source'        => ['segment', 'user_role'],
                'operator'      => 'in',
                'value_access'  => 'roles',
                'default_value' => [],
            ],
            'fcrm_woo_is_purchased'        => [
                'source'        => ['woo', 'purchased_items'],
                'operator'      => 'in',
                'value_access'  => 'product_ids',
                'default_value' => [],
            ],
            'fcrm_wishlist_is_in_level'    => [
                'source'        => ['wishlist', 'in_membership'],
                'operator'      => 'in',
                'value_access'  => 'level_ids',
                'default_value' => [],
            ],
            'fcrm_tutor_is_in_course'      => [
                'source'        => ['tutorlms', 'is_in_course'],
                'operator'      => 'in',
                'value_access'  => 'course_ids',
                'default_value' => [],
            ],
            'fcrm_pmpro_is_in_membership'  => [
                'source'        => ['pmpro', 'in_membership'],
                'operator'      => 'in',
                'value_access'  => 'level_ids',
                'default_value' => [],
            ],
            'fcrm_lifter_is_in_course'     => [
                'source'        => ['lifterlms', 'purchased_items'],
                'operator'      => 'in',
                'value_access'  => 'course_ids',
                'default_value' => [],
            ],
            'fcrm_lifter_is_in_membership' => [
                'source'        => ['lifterlms', 'purchased_groups'],
                'operator'      => 'in',
                'value_access'  => 'course_ids',
                'default_value' => [],
            ],
            'fcrm_learndhash_is_in_course' => [
                'source'        => ['learndash', 'purchased_items'],
                'operator'      => 'in',
                'value_access'  => 'course_ids',
                'default_value' => [],
            ],
            'fcrm_learndhash_is_in_group'  => [
                'source'        => ['learndash', 'purchased_groups'],
                'operator'      => 'in',
                'value_access'  => 'group_ids',
                'default_value' => [],
            ],
            'fcrm_edd_is_purchased'        => [
                'source'        => ['edd', 'purchased_items'],
                'operator'      => 'in',
                'value_access'  => 'product_ids',
                'default_value' => [],
            ],
            'fcrm_rcp_is_in_membership'    => [
                'source'        => ['rcp', 'in_membership'],
                'operator'      => 'in',
                'value_access'  => 'level_ids',
                'default_value' => [],
            ]
        ];

        $operatorMaps = [
            'match_all'     => 'in_all',
            'match_none_of' => 'not_in_all',
            'contains'      => 'contains',
            'doNotContains' => 'not_contains',
            'startsWith'    => 'startsWith',
            'endsWith'      => 'endsWith'
        ];

        $conditionName = $sequence->action_name;

        $oldSettings = $sequence->settings;

        if (isset($simpleMaps[$conditionName])) {
            $map = $simpleMaps[$conditionName];
            $sequence->action_name = 'funnel_condition';
            $sequence->settings = [
                'conditions' => [
                    [
                        [
                            'source'   => $map['source'],
                            'operator' => $map['operator'],
                            'value'    => Arr::get($sequence->settings, $map['value_access'], $map['default_value'])
                        ]
                    ]
                ]
            ];
            if (!$dryRun) {
                $sequence->save();
            }
        } else if ($conditionName == 'fcrm_check_user_prop') {
            $sequence->action_name = 'funnel_condition';
            $conditionGroups = $oldSettings['condition_groups'];

            $formattedConditions = [[]];

            if (isset($conditionGroups[0])) {
                $conditions = $conditionGroups[0]['conditions'];
                $conditionType = $conditionGroups[0]['match_type'];

                if ($conditionType != 'match_all') {
                    $formattedConditions = [];
                }

                foreach ($conditions as $condition) {
                    $dataKey = $condition['data_key'];
                    $operator = $condition['operator'];
                    $dataValue = $condition['data_value'];
                    if (!$dataKey || !$operator) {
                        continue;
                    }

                    if (isset($operatorMaps[$operator])) {
                        $operator = $operatorMaps[$operator];
                    }

                    if ($dataKey == 'contact_type' || $dataKey == 'country') {
                        if ($operator == '=') {
                            $operator = 'in';
                        } else {
                            $operator = 'not_in';
                        }
                        if ($dataKey == 'country') {
                            $dataValue = (array)$dataValue;
                        }

                    }

                    $provider = 'subscriber';

                    if (strpos($dataKey, 'custom.') === 0) {
                        $provider = 'custom_fields';
                        $dataKey = str_replace('custom.', '', $dataKey);
                    }

                    $item = [
                        'source'   => [
                            $provider,
                            $dataKey
                        ],
                        'operator' => $operator,
                        'value'    => $dataValue
                    ];

                    if ($conditionType == 'match_all') {
                        $formattedConditions[0][] = $item;
                    } else {
                        $formattedConditions[] = [$item];
                    }
                }
            }
            $sequence->settings = [
                'conditions' => $formattedConditions
            ];
            if (!$dryRun) {
                $sequence->save();
            }
        } else if ($conditionName == 'fcrm_woo_conditions') {
            $sequence->action_name = 'funnel_condition';
            $conditionGroups = $sequence->settings['conditional_groups'];

            $formattedConditions = [[]];

            if (isset($conditionGroups[0])) {
                $conditions = $conditionGroups[0]['conditions'];

                $keyMaps = [
                    'order_total_value'     => [
                        'woo_order',
                        'total_value'
                    ],
                    'order_product_ids'     => [
                        'woo_order',
                        'product_ids'
                    ],
                    'order_cat_purchased'   => [
                        'woo_order',
                        'cat_purchased'
                    ],
                    'order_billing_country' => [
                        'woo_order',
                        'billing_country'
                    ],
                    'order_shipping_method' => [
                        'woo_order',
                        'shipping_method'
                    ],
                    'order_payment_gateway' => [
                        'woo_order',
                        'payment_gateway'
                    ],

                    'customer_total_spend'        => [
                        'woo',
                        'total_order_value'
                    ],
                    'customer_order_count'        => [
                        'woo',
                        'total_spend'
                    ],
                    'customer_guest_user'         => [
                        'woo',
                        'guest_user'
                    ],
                    'customer_billing_country'    => [
                        'woo',
                        'billing_country'
                    ],
                    'customer_cat_purchased'      => [
                        'woo',
                        'purchased_categories'
                    ],
                    'customer_purchased_products' => [
                        'woo',
                        'purchased_items'
                    ],
                ];

                foreach ($conditions as $condition) {
                    $dataKey = $condition['data_key'];
                    $operator = $condition['operator'];
                    $dataValue = $condition['data_value'];

                    if (!$dataKey || !$operator || !isset($keyMaps[$dataKey])) {
                        continue;
                    }

                    if (isset($operatorMaps[$operator])) {
                        $operator = $operatorMaps[$operator];
                    }

                    if ($dataKey == 'order_billing_country' || $dataKey == 'customer_billing_country') {
                        if ($operator != '=') {
                            $operator = 'not_in';
                        } else {
                            $operator = 'in';
                        }

                        $dataValue = (array)$dataValue;
                    }

                    $item = [
                        'source'   => $keyMaps[$dataKey],
                        'operator' => $operator,
                        'value'    => $dataValue
                    ];
                    $formattedConditions[0][] = $item;
                }
            }

            $sequence->settings = [
                'conditions' => $formattedConditions
            ];

            if (!$dryRun) {
                $sequence->save();
            }
        }

        return $sequence;
    }


    public static function createWpUserFromSubscriber($subscriber, $sendWelcomeEmail = false, $password = '', $role = '', $metaData = [])
    {
        if ($userId = $subscriber->getWpUserId()) {
            return $userId;
        }

        if (!$password) {
            $password = wp_generate_password(8);
        }

        $userId = wp_create_user(sanitize_user($subscriber->email), $password, $subscriber->email);
        if (is_wp_error($userId)) {
            return $userId;
        }

        if (!$role) {
            // get default user role of WordPress
            $role = get_option('default_role');
            if (!$role) {
                $role = 'subscriber';
            }
        }


        $user = new \WP_User($userId);
        $user->set_role($role);

        $metaData['first_name'] = $subscriber->first_name;
        $metaData['last_name'] = $subscriber->last_name;

        $userMetas = array_filter($metaData);

        foreach ($userMetas as $metaKey => $metaValue) {
            update_user_meta($userId, $metaKey, $metaValue);
        }

        if ($sendWelcomeEmail) {
            wp_send_new_user_notifications($userId, 'user');
        }

        $subscriber->user_id = $userId;
        $subscriber->save();
        return $userId;
    }


    public static function getCountryShortName($countryName)
    {
        if (!function_exists('getFluentFormCountryList')) {
            return null;
        }

        $countries = getFluentFormCountryList();
        if (isset($countries[strtoupper($countryName)])) {
            return $countryName;
        }

        $countries = array_flip($countries);
        if (isset($countries[$countryName])) {
            return $countries[$countryName];
        }
        return null;
    }

    public static function getFunnelSubscriberStatus($defaultStatus, $funnel, $subscriber)
    {
        if ($defaultStatus == 'active' || $defaultStatus == 'waiting') {
            return $defaultStatus;
        }

        $processableStatuses = ['subscribed', 'transactional'];
        if (in_array($subscriber->status, $processableStatuses, true)) {
            return 'active';
        }

        if (Arr::get($funnel->settings, '__force_run_actions') == 'yes') {
            return 'active';
        }

        return $defaultStatus;
    }
}
