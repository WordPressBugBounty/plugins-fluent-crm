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
         * Cancel every Action Scheduler action this plugin can queue, in every group.
         * A live hook left behind keeps retrying with no listener until it fails.
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
