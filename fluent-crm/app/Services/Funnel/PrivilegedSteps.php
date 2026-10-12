<?php

namespace FluentCrm\App\Services\Funnel;

use FluentCrm\App\Models\FunnelSequence;
use FluentCrm\Framework\Support\Arr;

/**
 * Automation steps that change WordPress users (roles, user meta, new accounts).
 *
 * Holding fcrm_write_funnels is not enough to add or change such a step: the author also needs
 * the WordPress capability the step's action declares, so a CRM manager cannot use an automation
 * to do what WordPress itself would not let them do. Removing a step is never gated, and steps
 * already saved keep running whoever edits the rest of the automation.
 *
 * Add-ons declare their steps through the `fluent_crm/funnel_privileged_actions` filter as
 * action_name => capability.
 */
class PrivilegedSteps
{
    /**
     * @return array<string, string> action_name => required WordPress capability
     */
    public static function capabilities()
    {
        /**
         * Automation actions that need a WordPress capability on top of fcrm_write_funnels to
         * be added or changed.
         *
         * @param array<string, string> $actions action_name => capability
         * @since 3.3.0
         */
        $actions = apply_filters('fluent_crm/funnel_privileged_actions', []);

        return is_array($actions) ? array_filter($actions, 'is_string') : [];
    }

    /**
     * Throw unless the current user may save these steps into the automation.
     *
     * A privileged step passes when the user holds its capability, or when it is an existing
     * step of this automation saved with the same action and settings.
     *
     * @param array $sequences step payloads, nested (children) or flat (parent_id)
     * @param int   $funnelId  0 for a new automation (clone, import, template)
     * @throws \Exception code 403 naming the step and the capability
     */
    public static function assertCanSave(array $sequences, $funnelId = 0)
    {
        $capabilities = self::capabilities();
        if (!$capabilities) {
            return;
        }

        $existing = null;
        foreach (self::flatten($sequences) as $step) {
            $actionName = (string) Arr::get($step, 'action_name', '');
            if (!isset($capabilities[$actionName]) || current_user_can($capabilities[$actionName])) {
                continue;
            }

            if ($funnelId) {
                if ($existing === null) {
                    $existing = FunnelSequence::where('funnel_id', (int) $funnelId)->get()->keyBy('id');
                }
                $saved = $existing->get((int) Arr::get($step, 'id'));
                if ($saved && $saved->action_name === $actionName
                    && self::canonical($saved->settings) === self::canonical(Arr::get($step, 'settings', []))) {
                    continue;
                }
            }

            throw new \Exception(esc_html(sprintf(
                /* translators: 1: automation step title, 2: WordPress capability name */
                __('Only users with the %2$s capability can add or change the "%1$s" step. Ask an administrator to make this change.', 'fluent-crm'),
                (string) Arr::get($step, 'title', $actionName),
                $capabilities[$actionName]
            )), 403);
        }
    }

    /**
     * Every step in a payload: top level, conditional branch children, and flat rows.
     */
    private static function flatten(array $sequences)
    {
        $steps = [];
        foreach ($sequences as $sequence) {
            if (is_object($sequence)) {
                $sequence = (array) $sequence;
            }
            if (!is_array($sequence)) {
                continue;
            }
            if (isset($sequence['action_name'])) {
                $steps[] = $sequence;
            }
            foreach ((array) Arr::get($sequence, 'children', []) as $branch) {
                if (is_array($branch)) {
                    $steps = array_merge($steps, self::flatten($branch));
                }
            }
        }

        return $steps;
    }

    /**
     * Settings as a comparable string: keys sorted, scalars as strings, so a step the editor
     * sends back untouched matches what was saved even if the JSON round trip re-typed values.
     */
    private static function canonical($settings)
    {
        $normalise = function ($value) use (&$normalise) {
            if (is_object($value)) {
                $value = (array) $value;
            }
            if (is_array($value)) {
                ksort($value);
                return array_map($normalise, $value);
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            return $value === null ? '' : (string) $value;
        };

        return wp_json_encode($normalise(is_array($settings) || is_object($settings) ? $settings : []));
    }
}
