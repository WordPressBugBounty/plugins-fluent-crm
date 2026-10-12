<?php

namespace FluentCrm\App\Services\Funnel;

use FluentCrm\Framework\Support\Arr;

/**
 * Computes step delays (delay, c_delay) for a flat, sequence-ordered step list the way the editor's
 * save does (FunnelHelper::saveFunnelSequence() and saveChildSequences()).
 *
 * Top-level steps share one running counter. Each Yes/No branch counts from its parent
 * conditional's delays with its own counter, and its waits never reach the main path or the
 * other branch. Used when cloning, importing and creating automations from templates, which
 * copy the stored rows instead of the editor's nested payload.
 */
class SequenceDelays
{
    private $cDelay = 0;

    private $delay = 0;

    /** @var array old step id => ['c_delay' => int, 'delay' => int] for top-level steps */
    private $parents = [];

    /** @var array "parentId|branch" => ['c_delay' => int, 'delay' => int] running branch counters */
    private $branches = [];

    /**
     * Delays for the next step in the list, which must come after its parent conditional.
     *
     * @param array $sequence step row (parent_id, condition_type, action_name, settings)
     * @param int $oldId the step's id in the source automation
     * @return array ['c_delay' => int, 'delay' => int]
     */
    public function next(array $sequence, $oldId)
    {
        $isWait = Arr::get($sequence, 'action_name') == 'fluentcrm_wait_times';
        $parentId = Arr::get($sequence, 'parent_id');

        if ($parentId && isset($this->parents[$parentId])) {
            $parent = $this->parents[$parentId];
            $key = $parentId . '|' . Arr::get($sequence, 'condition_type');
            $branch = isset($this->branches[$key]) ? $this->branches[$key] : ['c_delay' => 0, 'delay' => 0];

            $delays = [
                'c_delay' => $parent['c_delay'] + $branch['c_delay'],
                'delay'   => $parent['delay'] + $branch['delay'],
            ];

            $branch['delay'] = 0;
            if ($isWait) {
                $branch['delay'] = FunnelHelper::getDelayInSecond($sequence['settings']);
                $branch['c_delay'] += $branch['delay'];
            }
            $this->branches[$key] = $branch;

            return $delays;
        }

        $delays = ['c_delay' => $this->cDelay, 'delay' => $this->delay];
        $this->parents[$oldId] = $delays;

        $this->delay = 0;
        if ($isWait) {
            $this->delay = FunnelHelper::getDelayInSecond($sequence['settings']);
            $this->cDelay += $this->delay;
        }

        return $delays;
    }

    /**
     * A top-level goal carries its own delay forward to the next step, as in the editor save.
     *
     * @param array $sequence the step after its saving filter ran
     */
    public function afterSavingFilter(array $sequence)
    {
        if (!Arr::get($sequence, 'parent_id') && Arr::get($sequence, 'type') == 'benchmark') {
            $this->delay = $sequence['delay'];
        }
    }
}
