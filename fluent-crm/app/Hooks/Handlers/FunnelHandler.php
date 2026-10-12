<?php

namespace FluentCrm\App\Hooks\Handlers;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\FunnelCampaign;
use FluentCrm\App\Models\FunnelSequence;
use FluentCrm\App\Models\FunnelSubscriber;
use FluentCrm\App\Services\Funnel\Actions\ApplyCompanyAction;
use FluentCrm\App\Services\Funnel\Actions\ApplyListAction;
use FluentCrm\App\Services\Funnel\Actions\ApplyTagAction;
use FluentCrm\App\Services\Funnel\Actions\DetachCompanyAction;
use FluentCrm\App\Services\Funnel\Actions\DetachListAction;
use FluentCrm\App\Services\Funnel\Actions\DetachTagAction;
use FluentCrm\App\Services\Funnel\Actions\SendEmailAction;
use FluentCrm\App\Services\Funnel\Actions\WaitTimeAction;
use FluentCrm\App\Services\Funnel\Benchmarks\ListAppliedBenchmark;
use FluentCrm\App\Services\Funnel\Benchmarks\RemoveFromListBenchmark;
use FluentCrm\App\Services\Funnel\Benchmarks\RemoveFromTagBenchmark;
use FluentCrm\App\Services\Funnel\Benchmarks\TagAppliedBenchmark;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\App\Services\Funnel\SequencePoints;
use FluentCrm\App\Services\Funnel\Triggers\FluentFormSubmissionTrigger;
use FluentCrm\App\Services\Funnel\Triggers\FluentFormSubscriptionCancelledTrigger;
use FluentCrm\App\Services\Funnel\Triggers\FluentFormSubscriptionPaymentReceivedTrigger;
use FluentCrm\App\Services\Funnel\Triggers\UserRegistrationTrigger;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\PermissionManager;
use FluentCrm\App\Services\Sanitize;
use FluentCrm\Framework\Support\Arr;

/**
 *  FunnelHandler Class - Automation Funnel Handler
 *
 * Automation Funnel Handler Class
 *
 * @package FluentCrm\App\Hooks
 *
 * @version 1.0.0
 */
class FunnelHandler
{
    private $settingsKey = 'fluentcrm_funnel_settings';

    private $lockKey = '_fc_funnel_processor_lock';

    private $lockTimeout = 90;

    protected $funnelFired = false;

    private $registeredFunnelTriggers = [];

    private $registeredTriggerFallbacks = [];

    private $funnelItemsRegistered = false;

    public function register()
    {
        /*
         * Funnel items register at init@1 so their fluentcrm_funnel_arg_num_* filters exist for the
         * init@2 early pass, which catches events fired at init@10. Triggers whose arg-count filter
         * is not registered yet are attached by the init@20 pass.
         */
        add_action('init', [$this, 'registerFunnelItems'], 1);
        add_action('init', [$this, 'registerEarlyActiveTriggers'], 2);
        add_action('init', [$this, 'handle'], 10);
        add_action('init', [$this, 'registerActiveTriggers'], 20);
    }

    /**
     * Register core funnel actions, benchmarks, triggers, and free Pro placeholders once.
     *
     * @return void
     */
    public function registerFunnelItems()
    {
        if ($this->funnelItemsRegistered) {
            return;
        }

        $this->funnelItemsRegistered = true;

        $this->initBlockActions();
        $this->initBenchMarkBlocks();
        $this->initTriggers();

        if (!defined('FLUENTCAMPAIGN_DIR_FILE')) {
            new \FluentCrm\App\Services\Funnel\ProFunnelItems();
        }
    }

    public function registerEarlyActiveTriggers()
    {
        $this->registerActiveTriggers(true);
    }

    public function registerActiveTriggers($onlyRegisteredArgFilters = false)
    {
        $triggers = get_option($this->settingsKey, []);
        $triggers = array_unique($triggers);

        if (!$triggers) {
            return;
        }

        foreach ($triggers as $triggerName) {
            if ($this->shouldSkipEddTriggerRegistration($triggerName)) {
                continue;
            }

            if (isset($this->registeredFunnelTriggers[$triggerName])) {
                continue;
            }

            // The early pass only attaches triggers whose arg-count filter is already registered.
            $argNumFilterName = 'fluentcrm_funnel_arg_num_' . $triggerName;
            if ($onlyRegisteredArgFilters && !has_filter($argNumFilterName)) {
                continue;
            }

            $argNum = apply_filters($argNumFilterName, 1);
            add_action($triggerName, function () use ($triggerName, $argNum) {
                $this->mapTriggers($triggerName, func_get_args(), $argNum);
            }, 10, $argNum);

            $this->registeredFunnelTriggers[$triggerName] = true;
        }

        // edd_complete_purchase fallback, attached once after edd_update_payment_status.
        if (
            isset($this->registeredFunnelTriggers['edd_update_payment_status']) &&
            empty($this->registeredTriggerFallbacks['edd_complete_purchase'])
        ) {
            add_action('edd_complete_purchase', function ($paymentId) {
                $this->mapTriggers('edd_update_payment_status', [$paymentId, 'complete', 'pending'], 3);
            });

            $this->registeredTriggerFallbacks['edd_complete_purchase'] = true;
        }
    }

    /**
     * Skip stored EDD automation hooks unless the site runs EDD 3 or newer.
     *
     * @param string $triggerName
     * @return bool
     */
    private function shouldSkipEddTriggerRegistration($triggerName)
    {
        if (Helper::isEdd3()) {
            return false;
        }

        return in_array($triggerName, [
            'edd_update_payment_status',
            'edd_sl_post_set_status',
            'edd_recurring_add_subscription_payment',
            'edd_subscription_status_change',
            'edd_fc_order_refunded_simulation'
        ], true);
    }

    public function handle()
    {
        $this->registerFunnelItems();

        add_action('fluent_crm_process_automation', function () {
            if ($this->funnelFired) {
                return;
            }

            $this->funnelFired = true;

            if (!$this->acquireFunnelProcessorLock()) {
                return;
            }

            try {
                (new FunnelProcessor())->followUpSequenceActions();
            } finally {
                $this->releaseFunnelProcessorLock();
            }
        });
    }

    private function mapTriggers($triggerName, $originalArgs, $argNumber)
    {
        $triggerNameBase = $triggerName;

        // Per-request memoization; bulk operations route every contact's event through here.
        static $triggerCache = [];

        if (!isset($triggerCache[$triggerNameBase])) {
            $triggerCache[$triggerNameBase] = [
                'funnels'    => Funnel::where('status', 'published')
                    ->where('trigger_name', $triggerNameBase)
                    ->get(),
                'benchmarks' => FunnelSequence::where('type', 'benchmark')
                    ->where('action_name', $triggerNameBase)
                    ->whereHas('funnel', function ($q) {
                        return $q->where('status', 'published');
                    })
                    ->orderBy('id', 'ASC')
                    ->get(),
            ];
        }

        $funnels = $triggerCache[$triggerNameBase]['funnels'];

        foreach ($funnels as $funnel) {
            ob_start();
            /**
             * Automation Funnel Start Trigger from specific action
             * @param Funnel $funnel
             * @param array $originalArgs Original Arguments from the trigger
             */
            do_action("fluentcrm_funnel_start_{$triggerName}", $funnel, $originalArgs);
            $maybeErrors = ob_get_clean();
        }

        $benchMarks = $triggerCache[$triggerNameBase]['benchmarks'];

        foreach ($benchMarks as $benchMark) {
            ob_start();
            /**
             * Automation Funnel's Benchmark Start Trigger from specific action trigger
             * @param Funnel $funnel
             * @param array $originalArgs Original Arguments from the trigger
             */
            do_action("fluentcrm_funnel_benchmark_start_{$triggerName}", $benchMark, $originalArgs);
            $maybeErrors = ob_get_clean();
        }
    }

    /**
     * Claim the funnel-processor lock via Helper::acquireDbLock().
     */
    private function acquireFunnelProcessorLock()
    {
        return Helper::acquireDbLock($this->lockKey, $this->lockTimeout);
    }

    private function releaseFunnelProcessorLock()
    {
        Helper::releaseDbLock($this->lockKey);
    }

    public function resetFunnelIndexes()
    {
        $funnels = Funnel::select('trigger_name')
            ->where('status', 'published')
            ->groupBy('trigger_name')
            ->get();

        $funnelArrays = [];
        foreach ($funnels as $funnel) {
            $funnelArrays[] = $funnel->trigger_name;
        }

        $sequenceMetrics = FunnelSequence::select('action_name')
            ->where('status', 'published')
            ->where('type', 'benchmark')
            ->whereHas('funnel', function ($q) {
                return $q->where('status', 'published');
            })
            ->groupBy('action_name')
            ->get();

        foreach ($sequenceMetrics as $sequenceMetric) {
            $funnelArrays[] = $sequenceMetric->action_name;
        }

        update_option($this->settingsKey, array_unique($funnelArrays), 'yes');
    }

    private function initTriggers()
    {
        new UserRegistrationTrigger();
        new FluentFormSubmissionTrigger();
        if (defined('FLUENTFORMPRO')) {
            new FluentFormSubscriptionPaymentReceivedTrigger();
            new FluentFormSubscriptionCancelledTrigger();
        }
    }

    private function initBlockActions()
    {
        if (Helper::isCompanyEnabled()) {
            new ApplyCompanyAction();
            new DetachCompanyAction();
        }
        new ApplyListAction();
        new ApplyTagAction();
        new DetachListAction();
        new DetachTagAction();
        new WaitTimeAction();
        new SendEmailAction();
    }

    private function initBenchMarkBlocks()
    {
        new ListAppliedBenchmark();
        new TagAppliedBenchmark();
        new RemoveFromListBenchmark();
        new RemoveFromTagBenchmark();
    }

    public function resumeSubscriberFunnels($subscriber, $oldStatus)
    {
        $funnelSubscribers = FunnelSubscriber::where('status', 'pending')
            ->with(['funnel'])
            ->where('subscriber_id', $subscriber->id)
            ->whereHas('funnel', function ($query) {
                return $query->where('status', 'published');
            })
            ->get();

        $funnelProcessorClass = new FunnelProcessor();

        foreach ($funnelSubscribers as $funnelSubscriber) {
            $funnel = $funnelSubscriber->funnel;

            if (!$funnel || $funnel->status != 'published') {
                continue;
            }

            $funnelProcessorClass->resumeFunnelSubscriber($funnel, $subscriber, $funnelSubscriber);
        }
    }

    public function saveSequences()
    {
        check_ajax_referer('fluentcrm_ajax_nonce', '_nonce');

        $hasPermission = PermissionManager::currentUserCan('fcrm_write_funnels');

        if (!$hasPermission) {
            wp_send_json([
                'message' => __('Sorry, you do not have permission to perform this action', 'fluent-crm')
            ], 422);
        }

        $data = FluentCrm('request')->all();

        try {
            $funnel = FunnelHelper::saveFunnelSequence($data['funnel_id'], $data);
        } catch (\Exception $e) {
            wp_send_json([
                'message' => $e->getMessage()
            ], 422);
        }

        wp_send_json([
            'sequences' => FunnelHelper::getFunnelSequences($funnel, true),
            'message'   => __('The sequence has been updated.', 'fluent-crm')
        ]);
    }

    public function saveEmailAction()
    {
        check_ajax_referer('fluentcrm_ajax_nonce', '_nonce');

        $hasPermission = PermissionManager::currentUserCan('fcrm_write_funnels');

        if (!$hasPermission) {
            wp_send_json([
                'message' => __('Sorry, you do not have permission to perform this action', 'fluent-crm')
            ], 422);
        }

        $request = FluentCrm('request');
        $funnelId = $request->get('funnel_id');
        $funnel = Funnel::findOrFail($funnelId);

        $settings = Helper::parseArrayOrJson($request->get('action_data'));

        $settings['action_name'] = 'send_custom_email';

        $funnelCampaign = Arr::get($settings, 'campaign', []);

        $funnelCampaignId = Arr::get($funnelCampaign, 'id');

        $data = Arr::only($funnelCampaign, array_keys(FunnelCampaign::getMock()));
        $data['settings']['mailer_settings'] = Arr::get($settings, 'mailer_settings', []);

        $type = 'created';

        if ($funnelCampaignId && $funnel->id == Arr::get($data, 'parent_id')) {
            // We have this campaign
            $data['settings'] = \maybe_serialize($data['settings']);
            $data['type'] = 'funnel_email_campaign';
            $data['title'] = $funnel->title . ' (' . $funnel->id . ')';
            FunnelCampaign::where('id', $funnelCampaignId)->update($data);
            $type = 'updated';
        } else {
            $data['parent_id'] = $funnel->id;
            $data['type'] = 'funnel_email_campaign';
            $data['title'] = $funnel->title . ' (' . $funnel->id . ')';
            $campaign = FunnelCampaign::create($data);
            $funnelCampaignId = $campaign->id;
        }

        if (Arr::get($funnelCampaign, 'design_template') == 'visual_builder') {
            $design = Arr::get($funnelCampaign, '_visual_builder_design', []);
            fluentcrm_update_campaign_meta($funnelCampaignId, '_visual_builder_design', $design);
        } else {
            fluentcrm_delete_campaign_meta($funnelCampaignId, '_visual_builder_design');
        }

        $refCampaign = FunnelCampaign::find($funnelCampaignId);

        wp_send_json([
            'type'               => $type,
            'reference_campaign' => $funnelCampaignId,
            'campaign'           => Arr::only($refCampaign->toArray(), array_keys(FunnelCampaign::getMock()))
        ], 200);
    }

    public function saveCampaignEmail()
    {
        check_ajax_referer('fluentcrm_ajax_nonce', '_nonce');

        $hasPermission = PermissionManager::currentUserCan('fcrm_manage_emails');

        if (!$hasPermission) {
            wp_send_json([
                'message' => __('Sorry, you do not have permission to perform this action', 'fluent-crm')
            ], 422);
        }

        $request = FluentCrm('request');
        $id = $request->get('campaign_id');

        $data = Helper::parseArrayOrJson($request->get('action_data'));

        if (empty($data)) {
            wp_send_json([
                'message' => __('Invalid Data', 'fluent-crm')
            ], 422);
        }

        $updateData = Arr::only($data, [
            'title',
            'slug',
            'template_id',
            'email_subject',
            'email_pre_header',
            'email_body',
            'utm_status',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'scheduled_at',
            'design_template'
        ]);

        if (!empty($data['settings'])) {
            $updateData['settings'] = $data['settings'];
        }

        $updateData = Sanitize::campaign($updateData);

        $campaign = Campaign::findOrFail($id);

        $campaign->fill($updateData)->save();

        $nextStep = Arr::get($data, 'next_step');

        if ($nextStep) {
            do_action('fluent_crm/update_campaign_compose', $data, $campaign);
            fluentcrm_update_campaign_meta($id, '_next_config_step', $nextStep);
        }

        wp_send_json([
            'campaign' => $campaign
        ], 200);
    }
}
