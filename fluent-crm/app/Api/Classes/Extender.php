<?php

namespace FluentCrm\App\Api\Classes;

use FluentCrm\App\Models\Subscriber;

defined('ABSPATH') || exit;


/**
 * Extend API Wrapper for FluentCRM FluentCrmApi('extend')
 *
 * Contacts API Wrapper Class that can be used as <code>FluentCrmApi('extend')</code> to get the class instance
 *
 * @package FluentCrm\App\Api\Classes
 * @namespace FluentCrm\App\Api\Classes
 *
 * @version 1.0.0
 */
final class Extender
{
    public function addProfileSection($key, $sectionTitle, $callback, $saveCallback = null)
    {
        add_filter('fluentcrm_profile_sections', function ($sections) use ($key, $sectionTitle) {
            $sections[$key] = [
                'name'    => 'fluentcrm_profile_extended',
                'title'   => $sectionTitle,
                'handler' => 'route',
                'query'   => [
                    'handler' => $key
                ]
            ];
            return $sections;
        });

        add_filter('fluent_crm/profile_section_' . $key, function ($content, $subscriber) use ($callback) {
            if (is_callable($callback)) {
                return $callback($content, $subscriber);
            }
            return $content;
        }, 10, 2);

        if ($saveCallback) {
            add_filter('fluent_crm/profile_section_save_' . $key, function ($response, $data, $subscriber) use ($saveCallback) {
                if (is_callable($saveCallback)) {
                    return $saveCallback($response, $data, $subscriber);
                }
                return $response;
            }, 10, 3);
        }
    }

    public function addCompanyProfileSection($key, $sectionTitle, $callback, $saveCallback = null)
    {
        add_filter('fluent_crm/company_profile_sections', function ($sections) use ($key, $sectionTitle) {
            $sections[$key] = [
                'name'    => 'fluent_crm_company_section_extended',
                'title'   => $sectionTitle,
                'handler' => 'route',
                'query'   => [
                    'handler' => $key
                ]
            ];
            return $sections;
        });

        add_filter('fluent_crm/company_profile_section_' . $key, function ($content, $subscriber) use ($callback) {
            if (is_callable($callback)) {
                return $callback($content, $subscriber);
            }
            return $content;
        }, 10, 2);

        if ($saveCallback) {
            add_filter('fluent_crm/company_profile_section_save_' . $key, function ($response, $data, $company) use ($saveCallback) {
                if (is_callable($saveCallback)) {
                    return $saveCallback($response, $data, $company);
                }
                return $response;
            }, 10, 3);
        }
    }

    /**
     * Register a smartcode group, e.g. {{my_plugin.name}}.
     *
     * Each code has a sensitivity level that decides where it may resolve (see ShortcodeParser::parse()):
     * basic (front-end pages too), private (the default: staff-facing output, notes, webhooks) or
     * credential (only an email to the contact's own inbox). A code that grants access to anything,
     * such as a login link, download link or personal token, must be declared credential.
     *
     * @param string   $key        Group key; core and Pro group names are reserved.
     * @param string   $title      Group title shown in the smartcode picker.
     * @param array    $shortcodes Code => label, e.g. ['name' => 'Member name'].
     * @param callable $callback   function ($code, $valueKey, $defaultValue, $subscriber, $level): string
     * @param array    $args       Optional. 'level' => default level for the group,
     *                             'levels' => [code => level] overrides, e.g. ['magic_link' => 'credential'].
     */
    public function addSmartCode($key, $title, $shortcodes, $callback, $args = [])
    {
        $reservedKeys = [
            'crm',
            'other',
            'contact',
            'wp',
            'fluentcrm',
            'user',
            'wp_user',
            'learndash',
            'ld',
            'tutorlms',
            'tl',
            'lifterlms',
            'lifter',
            'aff_wp',
            'edd_customer',
            'edd_order',
            'edd_license',
            'woo_customer',
            'woo_order',
            'woo_last_order',
            'woo_coupon',
            'woo_subscription',
            'cart_order',
            'cart_customer',
            'cart_transaction',
            'cart_receipt',
            'cart_coupon',
            'ab_cart_woo',
            'ab_cart_fluent_cart'
        ];

        if (in_array(strtolower(trim((string)$key)), $reservedKeys, true)) {
            return;
        }

        // Keys are lower-cased: the parser always checks the lower-cased code too, so any spelling matches.
        $levels = [];
        if (!empty($args['level'])) {
            $levels[strtolower($key . '.*')] = $args['level'];
        }
        foreach ((array)(isset($args['levels']) ? $args['levels'] : []) as $code => $level) {
            $levels[strtolower($key . '.' . $code)] = $level;
        }

        if ($levels) {
            add_filter('fluent_crm/smartcode_levels', function ($map) use ($levels) {
                return array_merge($map, $levels);
            });
        }

        /*
         * this is shortcode processor function
         */
        add_filter('fluent_crm/extended_smart_codes', function ($groups) use ($key, $title, $shortcodes) {
            $groups[] = [
                'key'        => $key,
                'title'      => $title,
                'shortcodes' => $this->formatShortcodes($key, $shortcodes)
            ];
            return $groups;
        }, 100);
        /*
         * This is the callback function for the shortcode parser
         */
        add_filter('fluent_crm/smartcode_group_callback_' . $key, function ($code, $valueKey, $defaultValue, $subscriber, $level = 'private') use ($callback) {
            if (is_callable($callback)) {
                return $callback($code, $valueKey, $defaultValue, $subscriber, $level);
            }
            return $code; // return the code if no parser function is provided
        }, 10, 5);
    }

    /**
     * @param $groupKey string
     * @param $shortcodes array
     * @return array
     */
    private function formatShortcodes($groupKey, $shortcodes)
    {
        $processed = [];
        foreach ($shortcodes as $key => $title) {
            $processed['{{' . $groupKey . '.' . $key . '}}'] = $title;
        }
        return $processed;
    }

    public function addContactWidget($callback, $priority = 20)
    {
        add_filter('fluent_crm/subscriber_info_widgets', function ($widgets, $subscriber) use ($callback) {
            if (is_callable($callback)) {
                $data = $callback($subscriber);
                if (is_array($data) && isset($data['title']) && isset($data['content'])) {
                    $widgets[] = [
                        'title'   => $data['title'],
                        'content' => $data['content']
                    ];
                }
            }
            return $widgets;
        }, $priority, 2);
    }

    public function getCompaniesByContactEmail($email)
    {
        $subscriber = Subscriber::where('email', $email)->with('companies')->first();
        if (!$subscriber) {
            return [];
        }

        return $subscriber->companies;
    }
}
