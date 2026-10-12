<?php

namespace FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers;

use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\CartHelper;

class OrderShippedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fluent_cart/shipping_status_changed_to_shipped';
        $this->priority = 20;
        $this->actionArgNum = 1;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category' => __('FluentCart', 'fluent-crm'),
            'label' => __('Order Shipped', 'fluent-crm'),
            'description' => __('This will start when a successful order is shipped', 'fluent-crm'),
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24px" height="24px" viewBox="0 0 24 24" version="1.1"><g id="surface1"><path style=" stroke:none;fill-rule:nonzero;fill:rgb(100%,100%,100%);fill-opacity:1;" d="M 2.398438 0 L 21.601562 0 C 22.925781 0 24 1.074219 24 2.398438 L 24 21.601562 C 24 22.925781 22.925781 24 21.601562 24 L 2.398438 24 C 1.074219 24 0 22.925781 0 21.601562 L 0 2.398438 C 0 1.074219 1.074219 0 2.398438 0 Z M 2.398438 0 "/><path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,62.352943%);fill-opacity:1;" d="M 10.925781 16.476562 L 3.769531 16.476562 L 4.894531 13.878906 C 5.222656 13.117188 5.972656 12.625 6.804688 12.625 L 15.328125 12.625 L 14.746094 13.964844 C 14.085938 15.488281 12.585938 16.476562 10.925781 16.476562 Z M 10.925781 16.476562 "/><path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,62.352943%);fill-opacity:1;" d="M 16.851562 11.394531 L 6.789062 11.394531 L 7.367188 10.054688 C 8.027344 8.53125 9.53125 7.542969 11.191406 7.542969 L 19.886719 7.542969 L 18.761719 10.140625 C 18.433594 10.902344 17.683594 11.394531 16.851562 11.394531 Z M 16.851562 11.394531 "/></g></svg>'
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title' => __('Order Shipped', 'fluent-crm'),
            'sub_title' => __('This will start when an order is shipped', 'fluent-crm'),
            'fields' => [
                'subscription_status' => [
                    'type' => 'option_selectors',
                    'option_key' => 'editable_statuses',
                    'is_multiple' => false,
                    'label' => __('Subscription Status', 'fluent-crm'),
                    'placeholder' => __('Select status', 'fluent-crm')
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => '<b>' . __('An automated double opt-in email will be sent to new contacts', 'fluent-crm') . '</b>',
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending'
                    ]
                ]
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'product_ids'        => [],
            'product_categories' => [],
            'run_multiple'       => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'product_ids' => [
                'type' => 'rest_selector',
                'label' => __('Target Products', 'fluent-crm'),
                'option_key' => 'fluent_cart_products',
                'is_multiple' => true,
                'help'        => __('Select the products this automation will run for', 'fluent-crm'),
                'inline_help' => __('Leave blank to run for any product purchase', 'fluent-crm')
            ],

            'product_categories' => [
                'type' => 'rest_selector',
                'label' => __('Or Target Product Categories', 'fluent-crm'),
                'option_key' => 'fluent_cart_product_categories',
                'is_multiple' => true,
                'help'        => __('Select the product categories this automation will run for', 'fluent-crm'),
                'inline_help' => __('Leave blank to run for products in any category', 'fluent-crm')
            ],

            'run_multiple' => [
                'type' => 'yes_no_check',
                'label' => '',
                'check_label' => __('Allow this event to restart the automation for the same contact', 'fluent-crm'),
                'inline_help' => __('If enabled, it will restart the automation for a contact who is already in it. Otherwise, the contact will be skipped.', 'fluent-crm')
            ]
        ];
    }


    public function handle($funnel, $originalArgs)
    {
        $orderData = $originalArgs[0] ?? [];

        $order = Arr::get($orderData, 'order', []);
        $customer = Arr::get($order, 'customer', []);

        $orderId = Arr::get($order, 'id', 0);

        $settings = Arr::get($funnel, 'settings', []);

        $subscriberData = CartHelper::prepareSubscriberData($customer);

        if (!is_email($subscriberData['email'])) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $order, $subscriberData);

        $willProcess = apply_filters('fluentcrm_funnel_will_process_' . $this->triggerName, $willProcess, $funnel, $subscriberData, $originalArgs);

        if (!$willProcess) {
            return;
        }

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);

        $subscriberData['status'] = (!empty($subscriberData['subscription_status'])) ? $subscriberData['subscription_status'] : 'subscribed';
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $orderId
        ]);
    }

    private function isProcessable($funnel, $order, $subscriberData)
    {
        $conditions = Arr::get($funnel, 'conditions', []);
        $isProcessable = $this->checkConditions($conditions, $order, $subscriberData);

        if(!$isProcessable){
            return false;
        }

        $subscriber = FunnelHelper::getSubscriber($subscriberData['email']);

        if ($subscriber) {
            $funnelSub = FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id);
            if ($funnelSub) {
                $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
                if ($multipleRun) {
                    if ($funnelSub->source_ref_id == $order->id) {
                        return false;
                    }
                    FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
                }
                return $multipleRun;
            }
        }

        return true;
    }

    public function checkConditions($conditions, $order, $subscriber)
    {
        $orderItems = Arr::get($order, 'order_items', []);
        // Post IDs of ordered products are the product IDs in FluentCart

        $orderedProductIds = [];
        foreach ($orderItems as $item) {
            $productId = $item->post_id;
            if ($productId) {
                $orderedProductIds[] = $productId;
            }
        }

        $orderProductCategories = CartHelper::getProductCategoriesByIds($orderedProductIds);

        $selectedProductIds = Arr::get($conditions, 'product_ids', []);

        $selectedProductCategories = Arr::get($conditions, 'product_categories', []);

        if (empty($selectedProductIds) && empty($selectedProductCategories)) {
            return true;
        }

        $productMatch = !empty($selectedProductIds) && !empty(array_intersect($selectedProductIds, $orderedProductIds));
        $categoryMatch = !empty($selectedProductCategories) && !empty(array_intersect($selectedProductCategories, $orderProductCategories));

        return $productMatch || $categoryMatch;
    }


}
