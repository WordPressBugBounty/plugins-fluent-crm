<?php

namespace FluentCrm\App\Hooks\Handlers;

/**
 *  DeactivationHandler Class
 *
 * FluentCRM Deactivation Handler Class.
 *
 * @package FluentCrm\App\Hooks
 *
 * @version 1.0.0
 */
class DeactivationHandler
{
    public function handle()
    {
        /*
         * Cancel every Action Scheduler action this plugin can queue.
         *
         * Given a hook with no args and no group, as_unschedule_all_actions()
         * takes the cancel_actions_by_hook() path, so one call per hook clears
         * that hook's actions in every group.
         *
         * The two multi-thread hooks are queued lazily -- the recurring sender
         * only exists while the multi_threading_emails experiment is on, and the
         * cancel action only between a stop request and the next tick -- so they
         * are usually absent. Cancelling an absent hook is a no-op, and leaving
         * a live one behind means Action Scheduler keeps retrying a hook with no
         * listener until it gives up and records the action as failed.
         */
        if (function_exists('\as_unschedule_all_actions')) {
            as_unschedule_all_actions('fluentcrm_scheduled_every_minute_tasks');
            as_unschedule_all_actions('fluent_crm_ascheduler_runs_daily');
            as_unschedule_all_actions('fluent_crm_send_multi_thread_emails');
            as_unschedule_all_actions('fluent_crm_cancel_multi_thread_mailing');
        }

        wp_clear_scheduled_hook('fluentcrm_scheduled_minute_tasks');
        wp_clear_scheduled_hook('fluentcrm_scheduled_hourly_tasks');
        wp_clear_scheduled_hook('fluentcrm_scheduled_weekly_tasks');
        wp_clear_scheduled_hook('fluentcrm_scheduled_five_minute_tasks');
        wp_clear_scheduled_hook('fluentcrm_scheduled_daily_tasks');
    }
}
