<?php

namespace FluentCrm\App\Services;

use FluentCrm\Framework\Support\Arr;

class AutoSubscribe
{
    public function getRegistrationSettings()
    {
        $defaults = [
            'status'       => 'no',
            'target_list'  => '',
            'target_tags'  => [],
            'double_optin' => 'no'
        ];

        $settings = fluentcrm_get_option('user_registration_subscribe_settings', []);

        if (!$settings) {
            return $defaults;
        }

        return wp_parse_args($settings, $defaults);
    }

    public function getRegistrationFields()
    {
        return [
            'title'     => __('User Signup Opt-in Settings', 'fluent-crm'),
            'sub_title' => __('Automatically add new user signups as contacts in FluentCRM', 'fluent-crm'),
            'fields'    => [
                'status'       => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'checkbox_label' => __('Create new contacts in FluentCRM when users register in WordPress', 'fluent-crm'),
                    'checkbox_description' => __('Automatically add new user signups as contacts in FluentCRM', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no'
                ],
                'target_list'  => [
                    'type'        => 'option-selector',
                    'label'       => __('Assign List', 'fluent-crm'),
                    'option_key'  => 'lists',
                    'is_multiple' => false,
                    'creatable'   => true,
                    'placeholder' => __('Select a List', 'fluent-crm'),
                    'inline_help' => __('Select the list to assign to new users who register on your site', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'target_tags'  => [
                    'type'        => 'option-selector',
                    'label'       => __('Assign Tags', 'fluent-crm'),
                    'option_key'  => 'tags',
                    'is_multiple' => true,
                    'creatable'   => true,
                    'placeholder' => __('Select tags', 'fluent-crm'),
                    'inline_help' => __('Select the tags to assign to new users who register on your site', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'double_optin' => [
                    'type'           => 'inline-checkbox',
                    'label'          => __('Double Opt-in', 'fluent-crm'),
                    'checkbox_label' => __('Enable double opt-in email confirmation', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
            ]
        ];
    }

    public function getCommentSettings()
    {
        $defaults = [
            'status'         => 'no',
            'checkbox_label' => __('Subscribe to newsletter', 'fluent-crm'),
            'auto_checked'   => 'no',
            'target_list'    => '',
            'show_only_new'  => 'yes',
            'target_tags'    => [],
            'double_optin'   => 'yes'
        ];

        $settings = fluentcrm_get_option('comment_form_subscribe_settings', []);

        if (!$settings) {
            return $defaults;
        }

        return wp_parse_args($settings, $defaults);
    }

    public function getCommentFields()
    {
        return [
            'title'     => __('Comment Form Subscription Settings', 'fluent-crm'),
            'sub_title' => __('Automatically add people who comment on your site as contacts in FluentCRM', 'fluent-crm'),
            'fields'    => [
                'status'         => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'checkbox_label' => __('Create new contacts in FluentCRM when a visitor adds a comment', 'fluent-crm'),
                    'checkbox_description' => __('Automatically add people who comment on your site as contacts in FluentCRM', 'fluent-crm'),
                ],
                'checkbox_label' => [
                    'label'       => __('Checkbox Label for Comment Form', 'fluent-crm'),
                    'type'        => 'input-text',
                    'placeholder' => __('Checkbox Label for Comment Form', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'target_list'    => [
                    'type'        => 'option-selector',
                    'label'       => __('Assign List', 'fluent-crm'),
                    'option_key'  => 'lists',
                    'is_multiple' => false,
                    'placeholder' => __('Select a List', 'fluent-crm'),
                    'inline_help' => __('Select the list to assign when a visitor comments', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'target_tags'    => [
                    'type'        => 'option-selector',
                    'label'       => __('Assign Tags', 'fluent-crm'),
                    'option_key'  => 'tags',
                    'is_multiple' => true,
                    'placeholder' => __('Select tags', 'fluent-crm'),
                    'inline_help' => __('Select the tags to assign when a visitor comments', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'auto_checked'   => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'checkbox_label' => __('Check the subscription checkbox on the comment form by default', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'show_only_new'  => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'checkbox_label' => __('Do not show the checkbox if the current user is already subscribed', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'double_optin'   => [
                    'type'           => 'inline-checkbox',
                    'label'          => __('Double Opt-in', 'fluent-crm'),
                    'checkbox_label' => __('Enable double opt-in email confirmation', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ]
            ]
        ];
    }

    public function getUserSyncSettings()
    {
        $defaults = [
            'status'                        => 'no',
            'delete_contact_on_user_delete' => 'no'
        ];

        $settings = fluentcrm_get_option('user_syncing_settings', []);

        if (!$settings) {
            return $defaults;
        }

        return wp_parse_args($settings, $defaults);
    }

    public function getUserSyncFields()
    {
        return [
            'title'     => __('Auto Sync User Data and Contact Data', 'fluent-crm'),
            'sub_title' => __('Automatically sync WordPress user data with FluentCRM contact data', 'fluent-crm'),
            'fields'    => [
                'status'                        => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'checkbox_label' => __('Enable sync between WordPress user data and FluentCRM contact data', 'fluent-crm'),
                    'checkbox_description' => __('When enabled, changes to WordPress user profile fields (name, email) will be automatically synced to the corresponding FluentCRM contact', 'fluent-crm')
                ],
                'delete_contact_on_user_delete' => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'checkbox_label' => __('Delete the FluentCRM contact when the WordPress user is deleted', 'fluent-crm'),
                    'checkbox_description' => __('When enabled, deleting a WordPress user will also permanently delete the associated FluentCRM contact record', 'fluent-crm')
                ]
            ]
        ];
    }

    public function getWooCheckoutSettings()
    {
        $defaults = [
            'auto_checkout_fill' => 'no',
            'status'         => 'no',
            'checkbox_label' => __('Sign me up for the newsletter!', 'fluent-crm'),
            'auto_checked'   => 'no',
            'target_list'    => '',
            'show_only_new'  => 'yes',
            'target_tags'    => [],
            'double_optin'   => 'yes'
        ];

        $settings = fluentcrm_get_option('woo_checkout_form_subscribe_settings', []);

        if (!$settings) {
            return $defaults;
        }

        return wp_parse_args($settings, $defaults);
    }

    public function getWooCheckoutFields()
    {
        return [
            'title'     => __('WooCommerce Checkout Subscription Field', 'fluent-crm'),
            'sub_title' => __('Let customers subscribe to your emails when they check out', 'fluent-crm'),
            'fields'    => [
                'auto_checkout_fill' => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'checkbox_label' => __('Automatically fill checkout fields with the current contact\'s details', 'fluent-crm')
                ],
                'status'             => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'checkbox_label' => __('Add a subscription checkbox to the WooCommerce checkout page', 'fluent-crm')
                ],
                'checkbox_label'     => [
                    'label'       => __('Checkout Checkbox Label', 'fluent-crm'),
                    'type'        => 'input-text',
                    'placeholder' => __('Checkout Checkbox Label', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'target_list'        => [
                    'type'        => 'option-selector',
                    'label'       => __('Assign List', 'fluent-crm'),
                    'option_key'  => 'lists',
                    'is_multiple' => false,
                    'placeholder' => __('Select a List', 'fluent-crm'),
                    'inline_help' => __('Select the list to assign when the checkbox is checked', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'target_tags'        => [
                    'type'        => 'option-selector',
                    'label'       => __('Assign Tags', 'fluent-crm'),
                    'option_key'  => 'tags',
                    'is_multiple' => true,
                    'placeholder' => __('Select tags', 'fluent-crm'),
                    'inline_help' => __('Select the tags to assign when the checkbox is checked', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'auto_checked'       => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'checkbox_label' => __('Check the checkout page checkbox by default', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'show_only_new'      => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'checkbox_label' => __('Do not show the checkbox if the current user is already subscribed', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'double_optin'       => [
                    'type'           => 'inline-checkbox',
                    'label'          => __('Double Opt-in', 'fluent-crm'),
                    'checkbox_label' => __('Enable double opt-in email confirmation', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get the saved FluentCart checkout subscription settings merged with defaults.
     * FluentCart has no 'auto_checkout_fill' option.
     */
    public function getFluentCartCheckoutSettings()
    {
        $defaults = [
            'status'         => 'no',
            'checkbox_label' => __('Sign me up for the newsletter!', 'fluent-crm'),
            'auto_checked'   => 'no',
            'target_list'    => '',
            'show_only_new'  => 'yes',
            'target_tags'    => [],
            'double_optin'   => 'yes'
        ];

        $settings = fluentcrm_get_option('fluent_cart_checkout_form_subscribe_settings', []);

        if (!$settings) {
            return $defaults;
        }

        $settings = wp_parse_args($settings, $defaults);

        // String to match _OptionSelector.vue option values.
        if ($settings['target_list']) {
            $settings['target_list'] = (string) $settings['target_list'];
        }

        return $settings;
    }

    /**
     * Normalize and whitelist FluentCart checkout subscription settings before persisting.
     */
    public function sanitizeFluentCartCheckoutSettings($settings)
    {
        $settings = is_array($settings) ? $settings : [];

        $yesNo = function ($value, $default = 'no') {
            if ($value === 'yes' || $value === 'no') {
                return $value;
            }
            return $default;
        };

        $listId = Arr::get($settings, 'target_list');
        $tagIds = array_filter(array_map('intval', (array)Arr::get($settings, 'target_tags', [])));

        return [
            'status'         => $yesNo(Arr::get($settings, 'status')),
            'checkbox_label' => sanitize_text_field(Arr::get($settings, 'checkbox_label', '')),
            'auto_checked'   => $yesNo(Arr::get($settings, 'auto_checked')),
            'target_list'    => $listId ? (string) intval($listId) : '',
            'show_only_new'  => $yesNo(Arr::get($settings, 'show_only_new'), 'yes'),
            'target_tags'    => array_values($tagIds),
            'double_optin'   => $yesNo(Arr::get($settings, 'double_optin'), 'yes')
        ];
    }

    /**
     * Field definitions for the FluentCart checkout subscription settings panel.
     */
    public function getFluentCartCheckoutFields()
    {
        return [
            'title'     => __('FluentCart Checkout Subscription Field', 'fluent-crm'),
            'sub_title' => __('Add a subscription box to FluentCart Checkout Form', 'fluent-crm'),
            'fields'    => [
                'status'         => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'checkbox_label' => __('Add a subscription checkbox to the FluentCart checkout page', 'fluent-crm')
                ],
                'checkbox_label' => [
                    'label'       => __('Checkout Checkbox Label', 'fluent-crm'),
                    'type'        => 'input-text',
                    'placeholder' => __('Checkout Checkbox Label', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'target_list'    => [
                    'type'        => 'option-selector',
                    'label'       => __('Assign List', 'fluent-crm'),
                    'option_key'  => 'lists',
                    'is_multiple' => false,
                    'placeholder' => __('Select a List', 'fluent-crm'),
                    'inline_help' => __('Select the list to assign when the checkbox is checked', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'target_tags'    => [
                    'type'        => 'option-selector',
                    'label'       => __('Assign Tags', 'fluent-crm'),
                    'option_key'  => 'tags',
                    'is_multiple' => true,
                    'placeholder' => __('Select tags', 'fluent-crm'),
                    'inline_help' => __('Select the tags to assign when the checkbox is checked', 'fluent-crm'),
                    'dependency'  => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'auto_checked'   => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'checkbox_label' => __('Check the checkout page checkbox by default', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'show_only_new'  => [
                    'type'           => 'inline-checkbox',
                    'label'          => '',
                    'checkbox_label' => __('Do not show the checkbox if the current user is already subscribed', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ],
                'double_optin'   => [
                    'type'           => 'inline-checkbox',
                    'label'          => __('Double Opt-in', 'fluent-crm'),
                    'checkbox_label' => __('Enable double opt-in email confirmation', 'fluent-crm'),
                    'true_label'     => 'yes',
                    'false_label'    => 'no',
                    'dependency'     => [
                        'depends_on' => 'status',
                        'operator'   => '=',
                        'value'      => 'yes'
                    ]
                ]
            ]
        ];
    }

}
