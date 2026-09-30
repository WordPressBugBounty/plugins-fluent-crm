<?php

use FluentCrm\Framework\Foundation\Application;
use FluentCrm\App\Hooks\Handlers\ActivationHandler;
use FluentCrm\App\Hooks\Handlers\DeactivationHandler;

return function ($file) {

    require_once FLUENTCRM_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

    register_activation_hook($file, function ($network_wide) use ($file) {
        (new ActivationHandler)->handle($network_wide);
    });

    add_action('wp_insert_site', function ($new_site) use ($file) {
        if (is_plugin_active_for_network('fluent-crm/fluent-crm.php')) {
            switch_to_blog($new_site->blog_id);
            (new ActivationHandler)->handle(false);
            restore_current_blog();
        }
    });

    register_deactivation_hook($file, function ($network_wide) {
        (new DeactivationHandler)->handle();
    });

    add_action('plugins_loaded', function () use ($file) {
        $app = new Application($file);
        require_once FLUENTCRM_PLUGIN_PATH . 'app/Functions/helpers.php';

        if (defined('FLUENTCAMPAIGN')) {
            add_filter('fluent_crm/dashboard_notices', function ($notices) {
                if (version_compare(FLUENTCRM_MIN_PRO_VERSION, FLUENTCAMPAIGN_PLUGIN_VERSION, '>')) {
                    $updateUrl = admin_url('plugins.php?s=fluentcampaign-pro&plugin_status=all&fluentcrm_pro_check_update=' . time());
                    $notices[] = '<div><b>Heads UP: </b> FluentCRM Pro needs to be updated to the latest version. <a href="' . esc_url($updateUrl) . '">Click here to update</a></div>';
                }
                return $notices;
            });
        }

        do_action('fluentcrm_loaded', $app);
        do_action('fluentcrm_addons_loaded', $app);

        add_action('init', function () use ($app) {
            do_action('fluent_crm/after_init', $app);
        }, 1000);

    });

    add_filter('cron_schedules', function ($schedules) {
        if (!is_array($schedules)) {
            $schedules = [];
        }

        if (!isset($schedules['fluentcrm_every_minute'])) {
            $schedules['fluentcrm_every_minute'] = array(
                'interval' => 60,
                'display'  => 'Every Minute (FluentCRM)'
            );
        }

        if (!isset($schedules['fluentcrm_scheduled_five_minute_tasks'])) {
            $schedules['fluentcrm_scheduled_five_minute_tasks'] = array(
                'interval' => 300,
                'display'  => 'Every 5 Minutes (FluentCRM)'
            );
        }

        return $schedules;
    }, 11);

    add_action('fluentcrm_loading_app', function () {

        if (!as_has_scheduled_action('fluentcrm_scheduled_every_minute_tasks', [], 'fluent-crm')) {
            as_schedule_recurring_action(time(), 60, 'fluentcrm_scheduled_every_minute_tasks', [], 'fluent-crm');
        }

        $hookName = 'fluentcrm_scheduled_minute_tasks';
        if (!wp_next_scheduled($hookName)) {
            wp_schedule_event(time(), 'fluentcrm_every_minute', $hookName);
        }

        $hourlyHook = 'fluentcrm_scheduled_hourly_tasks';
        if (!wp_next_scheduled($hourlyHook)) {
            wp_schedule_event(time() + 100, 'hourly', $hourlyHook);
        }

        /*
         * Compare the stored SCHEDULE NAME, not merely "is it scheduled".
         *
         * Sites upgraded from earlier versions still hold this event under the
         * 'fluentcrm_every_minute' (60s) schedule, and WP re-derives a recurring
         * event's interval from that saved name -- not from the code that
         * scheduled it. A plain !wp_next_scheduled() guard is therefore a no-op
         * on every existing install, which would leave the five-minute task
         * running once a minute forever.
         *
         * Clearing first also collapses duplicate copies left under the old
         * name. It does not deduplicate copies that already carry the current
         * name -- wp_get_schedule() reports only the next matching event -- but
         * wp_schedule_event() never created those on its own; the one code path
         * that did was fixed in efbc291e6 (Aug 2024).
         */
        $hookName = 'fluentcrm_scheduled_five_minute_tasks';
        if (wp_get_schedule($hookName) !== 'fluentcrm_scheduled_five_minute_tasks') {
            wp_clear_scheduled_hook($hookName);
            wp_schedule_event(time() + 5, 'fluentcrm_scheduled_five_minute_tasks', $hookName);
        }

        $weeklyHook = 'fluentcrm_scheduled_weekly_tasks';
        if (!wp_next_scheduled($weeklyHook)) {
            wp_schedule_event(time() + 1000, 'weekly', $weeklyHook);
        }

        $dailyHook = 'fluentcrm_scheduled_daily_tasks';
        if (!wp_next_scheduled($dailyHook)) {
            wp_schedule_event(time() + 500, 'daily', $dailyHook);
        }

        /*
         * The below schedule is powered by Action Scheduler by WooCommerce
         * It will run every day.
         */
        if (false === as_next_scheduled_action('fluent_crm_ascheduler_runs_daily')) {
            as_schedule_recurring_action(strtotime('midnight today'), DAY_IN_SECONDS, 'fluent_crm_ascheduler_runs_daily', [], 'fluent-crm');
        }

    }, 10);
};
