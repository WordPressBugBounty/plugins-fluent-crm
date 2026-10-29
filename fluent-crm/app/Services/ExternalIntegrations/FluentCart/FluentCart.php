<?php

namespace FluentCrm\App\Services\ExternalIntegrations\FluentCart;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Helper as CrmHelper;
use FluentCrm\Framework\Support\Arr;

use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Actions\CreateCouponAction;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Benchmarks\OrderSuccessBenchmark;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\SmartCode\SmartCodeParser;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\SmartCode\SmartCodeRegister;

use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\OrderCanceledTrigger;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\OrderPaidTrigger;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\OrderDeliveredTrigger;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\OrderRefundedTrigger;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\OrderShippedTrigger;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\OrderStatusChangedTrigger;

use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\SubscriptionActivatedTrigger;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\SubscriptionCancelledTrigger;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\SubscriptionEndOfTermTrigger;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\SubscriptionExpiredTrigger;
use FluentCrm\App\Services\ExternalIntegrations\FluentCart\Triggers\SubscriptionRenewedTrigger;


class FluentCart
{
    public function init()
    {
        $this->addAutomations();
        $this->addHooks();

        SmartCodeRegister::register();

        (new RevenueTracker())->init();

        (new CustomerWidget())->init();

    }

    public function addAutomations()
    {
        new OrderPaidTrigger();
        new OrderShippedTrigger();
        new OrderDeliveredTrigger();
        new OrderRefundedTrigger();
        new OrderCanceledTrigger();

        new SubscriptionExpiredTrigger();

        new SubscriptionActivatedTrigger();

        new SubscriptionCancelledTrigger();

        new SubscriptionRenewedTrigger();

        new SubscriptionEndOfTermTrigger();

        // Actions
        new CreateCouponAction();

        // Goals
        new OrderSuccessBenchmark();
    }

    public function addHooks()
    {

        add_filter('fluent_crm/get_import_driver_fluent_cart', [CartImporter::class, 'processUserDriver'], 10, 2);
        add_filter('fluent_crm/post_import_driver_fluent_cart', [CartImporter::class, 'importData'], 10, 3);

        add_filter('fluentcrm_ajax_options_fluent_cart_products', [$this, 'getProducts'], 10, 3);
        add_filter('fluentcrm_ajax_options_fluent_cart_product_categories', [$this, 'getProductCategories'], 10, 3);
        add_filter('fluentcrm_ajax_options_fluent_cart_subscription_products', [$this, 'getSubscriptionProducts'], 10, 3);
        add_filter('fluentcrm_ajax_options_fluent_cart_coupons', [$this, 'getCoupons'], 10, 3);
        add_filter('fluentcrm_ajax_options_fluent_cart_product_variations', [$this, 'getProductVariations'], 10, 3);

        // Inject the per-contact dynamic coupon smartcode when a Create Coupon step is created.
        add_action('fluent_crm/sequence_created_fcrm_create_fluentcart_coupon', function ($createdSequence) {
            $settings = $createdSequence->settings;
            $settings['code_settings']['smart_code'] = '{{cart_coupon.' . $createdSequence->funnel_id . '_' . $createdSequence->id . '}}';
            $createdSequence->settings = $settings;
            $createdSequence->save();
        });

        add_filter('fluent_crm/funnel_icons', [$this, 'addCartIcon'], 10 , 1);
        add_filter('fluent_crm/purchase_history_fluent_cart', [$this, 'purchaseHistory'], 10, 2);
        add_filter('fluent_crm/dynamic_contact_item_view_fluent_cart', [$this, 'getFluentCartOrderDetails'], 10, 3);

        add_filter('fluent_crm/smartcode_group_callback_cart_order', [SmartCodeParser::class, 'parseCartOrder'], 10, 4);
        add_filter('fluent_crm/smartcode_group_callback_cart_customer', [SmartCodeParser::class, 'parseCartCustomer'], 10, 4);
        add_filter('fluent_crm/smartcode_group_callback_cart_coupon', [SmartCodeParser::class, 'parseCartCoupon'], 10, 4);
//        add_filter('fluent_crm/smartcode_group_callback_cart_transaction', [SmartCodeParser::class, 'parseCartTransaction'], 10, 4);
        add_filter('fluent_crm/smartcode_group_callback_cart_receipt', [SmartCodeParser::class, 'parseCartReceipt'], 10, 4);


        add_filter('fluentcrm_automation_condition_groups', array($this, 'addAutomationConditions'), 10, 2);
        add_filter('fluentcrm_automation_conditions_assess_fluent_cart', array($this, 'assessAutomationConditions'), 10, 3);
    }

    public function getProducts($items, $search, $ids)
    {
        return CartHelper::getFluentCartProducts($items, $search, $ids);
    }

    public function getProductCategories($items, $search, $ids)
    {
        return CartHelper::getFluentCartProductCategories($items, $search, $ids);
    }

    public function getSubscriptionProducts($items, $search, $ids)
    {
        return CartHelper::getFluentCartSubscriptionProducts($items, $search, $ids);
    }

    public function getCoupons($items, $search, $ids)
    {
        return CartHelper::getFluentCartCoupons($items, $search, $ids);
    }

    public function getProductVariations($items, $search, $ids)
    {
        return CartHelper::getFluentCartProductVariations($items, $search, $ids);
    }

    public function addCartIcon($icons)
    {
        $icons['fluentcart'] = [
            'svg' => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="1" width="18" height="18" rx="1.8" fill="#00009F"/><path d="M9.19408 13.3557H3.82861L4.67063 11.4086C4.91784 10.8369 5.48112 10.4668 6.10395 10.4668H12.4955L12.0607 11.4722C11.5663 12.6155 10.4397 13.3557 9.19408 13.3557Z" fill="white"/><path d="M13.6389 9.54518H6.0918L6.52656 8.5398C7.02098 7.39646 8.14753 6.65625 9.39319 6.65625H15.9142L15.0722 8.60341C14.825 9.17507 14.2617 9.54518 13.6389 9.54518Z" fill="white"/></svg>',
        ];
        return $icons;
    }

    public function purchaseHistory($data, $subscriber)
    {
        $customer = Customer::where('email', $subscriber->email)->first();
        if (!$customer) {
            return [];
        }

        $ordersQuery = Order::with('appliedCoupons')->where('customer_id', $customer->id);
        $totalCount = $ordersQuery->count();

        if (!$totalCount) {
            return [];
        }

        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) { $page = 1; }
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
        if ($perPage < 1) { $perPage = 10; }

        $orders = $ordersQuery
            ->orderBy('id', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        $formattedOrders = $this->formatOrders($orders);

        return [
            'data' => $formattedOrders,
            'total' => $totalCount,
            'sidebar_html' => $this->getSidebarHtml($subscriber),
            'after_html' => '',
            'has_recount' => false,
            'columns_config' => [
                'order' => ['label' => __('Order', 'fluent-crm'), 'width' => '100px', 'sortable' => true, 'key' => 'id'],
                'date' => ['label' => __('Date', 'fluent-crm'), 'sortable' => true, 'key' => 'created_at'],
                'status' => ['label' => __('Status', 'fluent-crm'), 'width' => '140px', 'sortable' => false],
                'total' => ['label' => __('Total', 'fluent-crm'), 'width' => '120px', 'sortable' => true, 'key' => 'total'],
                'action' => ['label' => __('Action', 'fluent-crm'), 'width' => '100px', 'sortable' => false, 'quick_action' => true],
            ],
        ];

    }

    /**
     * A FluentCart timestamp in site time.
     *
     * FluentCart stores created_at in GMT; FluentCRM's date formatting expects site time, so
     * dates were off by the site's UTC offset (a new order read "in 4 hours" on a US site).
     *
     * @param mixed $gmtDate string or DateTimeInterface in GMT
     * @return string|mixed Y-m-d H:i:s in site time, or the input when empty
     */
    private static function siteTime($gmtDate)
    {
        if (!$gmtDate) {
            return $gmtDate;
        }

        if ($gmtDate instanceof \DateTimeInterface) {
            $gmtDate = $gmtDate->format('Y-m-d H:i:s');
        }

        return get_date_from_gmt((string)$gmtDate);
    }

    private function formatOrders($orders)
    {
        $formattedOrders = [];

        foreach ($orders as $order) {
            // Purchase dates follow FluentCRM's global date/time display preference.
            $date = '<span class="order_id">#' . $order->id .'</span><span class="order_date">'.esc_html(CrmHelper::formatDateTime(self::siteTime($order->created_at))).'</span>';
            $status = '<span class="fcrm_badge fcrm_badge_'.esc_attr($order->status).'">'. \FluentCrm\App\Services\Helper::getStatusText($order->status) .'</span>';

            $formattedOrders[] = [
                '__id' => (int) $order->id,
                'date' => $date,
                'status' => $status,
                'total' => Helper::toDecimal($order->total_amount), // Adjust if using a different helper
                'action' => __('View', 'fluent-crm'),
            ];
        }

        return $formattedOrders;
    }

    /**
     * Build the read-only contact-profile drawer for one FluentCart order.
     *
     * The order ID comes from the browser, so both FluentCart permission and
     * ownership by the current CRM contact are re-asserted before rendering.
     *
     * @param array      $dataView  Default dynamic-view response.
     * @param array      $params    Drawer parameters containing __id.
     * @param Subscriber $subscriber Current contact.
     * @return array
     */
    public function getFluentCartOrderDetails($dataView, $params, $subscriber)
    {
        if (!PermissionManager::hasPermission('orders/view')) {
            $dataView['title'] = __('Permission denied', 'fluent-crm');
            $dataView['content_html'] = '<div class="fc-crm-no-data-view"><p>'
                . esc_html__('You do not have permission to view this order.', 'fluent-crm')
                . '</p></div>';
            $dataView['footer_content'] = '';
            return $dataView;
        }

        $orderId = intval(Arr::get($params, '__id'));
        if (!$orderId || empty($subscriber->email)) {
            return $this->getMissingOrderView($dataView);
        }

        $customer = Customer::where('email', $subscriber->email)->first();
        if (!$customer) {
            return $this->getMissingOrderView($dataView);
        }

        $order = Order::with('order_items')
            ->where('id', $orderId)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$order) {
            return $this->getMissingOrderView($dataView);
        }

        $money = function ($amount) use ($order) {
            return esc_html(Helper::toDecimal($amount, true, $order->currency));
        };

        $html = '<div class="fcrm_order_preview fcrm_order_preview_fluentcart">';
        $html .= '<div class="fcrm_order_preview_meta">';
        $html .= '<div class="fcrm_order_preview_date"><span>' . esc_html__('Order date', 'fluent-crm') . '</span><strong>'
            . esc_html(CrmHelper::formatDateTime(self::siteTime($order->created_at))) . '</strong></div>';
        $html .= '<div class="fcrm_order_preview_statuses">';
        $html .= '<div><span>' . esc_html__('Order status', 'fluent-crm') . '</span><strong class="fcrm_badge fcrm_badge_'
            . esc_attr($order->status) . '">' . esc_html(CrmHelper::getStatusText($order->status)) . '</strong></div>';
        $html .= '<div><span>' . esc_html__('Payment status', 'fluent-crm') . '</span><strong class="fcrm_badge fcrm_badge_'
            . esc_attr($order->payment_status) . '">' . esc_html(CrmHelper::getStatusText($order->payment_status)) . '</strong></div>';
        $html .= '</div></div>';

        $html .= '<section class="fcrm_order_preview_card">';
        $html .= '<h3>' . esc_html__('Order items', 'fluent-crm') . '</h3>';
        $html .= '<div class="fcrm_order_preview_items">';
        foreach ($order->order_items as $orderItem) {
            $productTitle = $orderItem->post_title ?: $orderItem->title;
            $variationTitle = ($orderItem->title && $orderItem->post_title !== $orderItem->title) ? $orderItem->title : '';
            $thumbnailUrl = $orderItem->post_id ? get_the_post_thumbnail_url($orderItem->post_id, 'thumbnail') : '';

            $html .= '<div class="fcrm_order_preview_item">';
            if ($thumbnailUrl) {
                $html .= '<img class="fcrm_order_preview_item_image" src="' . esc_url($thumbnailUrl) . '" alt="'
                    . esc_attr($productTitle) . '">';
            } else {
                $html .= '<span class="fcrm_order_preview_item_placeholder" aria-hidden="true">'
                    . esc_html(mb_strtoupper(mb_substr((string) $productTitle, 0, 1))) . '</span>';
            }
            $html .= '<div class="fcrm_order_preview_item_details"><strong>' . esc_html($productTitle) . '</strong>';
            if ($variationTitle) {
                $html .= '<span>' . esc_html($variationTitle) . '</span>';
            }
            $html .= '</div>';
            $html .= '<div class="fcrm_order_preview_item_price"><span>' . $money($orderItem->unit_price)
                . ' &times; ' . esc_html((string) intval($orderItem->quantity)) . '</span><strong><span class="screen-reader-text">'
                . esc_html__('Line total', 'fluent-crm') . ': </span>' . $money($orderItem->line_total) . '</strong></div></div>';
        }
        $html .= '</div></section>';

        $discountTotal = (float) $order->manual_discount_total + (float) $order->coupon_discount_total;
        $html .= '<section class="fcrm_order_preview_card fcrm_order_preview_payment_summary">';
        $html .= '<h3>' . esc_html__('Payment summary', 'fluent-crm') . '</h3>';
        $html .= '<ul>';
        $html .= $this->getOrderDetailRow(__('Subtotal', 'fluent-crm'), $money($order->subtotal), false);
        $html .= $this->getOrderDetailRow(__('Shipping', 'fluent-crm'), $money($order->shipping_total), false);
        $html .= $this->getOrderDetailRow(__('Tax', 'fluent-crm'), $money($order->tax_total), false);
        if ($discountTotal > 0) {
            $html .= $this->getOrderDetailRow(__('Discount', 'fluent-crm'), '-' . $money($discountTotal), false);
        }
        $html .= $this->getOrderDetailRow(__('Total paid', 'fluent-crm'), $money($order->total_paid), false);
        if ((float) $order->total_refund > 0) {
            $html .= $this->getOrderDetailRow(__('Total refund', 'fluent-crm'), '-' . $money($order->total_refund), false);
        }
        $html .= $this->getOrderDetailRow(__('Total', 'fluent-crm'), $money($order->total_amount), false, 'fcrm_order_preview_total');
        $html .= '</ul></section></div>';

        /* translators: %d is the FluentCart order ID. */
        $dataView['title'] = sprintf(__('Order #%d', 'fluent-crm'), $order->id);
        $dataView['content_html'] = $html;
        $orderUrl = admin_url('admin.php?page=fluent-cart#/orders/' . $order->id . '/view');
        $dataView['footer_content'] = '<a class="el-button fcrm_primary_btn" target="_blank" rel="noopener" href="'
            . esc_url($orderUrl) . '">' . esc_html__('View in FluentCart', 'fluent-crm') . '</a>';

        return $dataView;
    }

    /**
     * Return the common safe response for unavailable or non-owned orders.
     */
    private function getMissingOrderView($dataView)
    {
        $dataView['title'] = __('Order not found', 'fluent-crm');
        $dataView['content_html'] = '<div class="fc-crm-no-data-view"><p>'
            . esc_html__('No order found for this contact.', 'fluent-crm')
            . '</p></div>';
        $dataView['footer_content'] = '';
        return $dataView;
    }

    /**
     * Render one escaped label/value row for the drawer summary.
     */
    private function getOrderDetailRow($label, $value, $escapeValue = true, $className = '')
    {
        return '<li class="' . esc_attr($className) . '"><span class="fc_list_sub">' . esc_html($label) . '</span><span class="fc_list_value">'
            . ($escapeValue ? esc_html($value) : $value) . '</span></li>';
    }

    private function getSidebarHtml($subscriber = null)
    {
        // Customer summary plus recently purchased items.
        $customer = null;
        if ($subscriber && !empty($subscriber->email)) {
            $customer = Customer::where('email', $subscriber->email)->first();
        }

        if (!$customer) {
            return '<div class="fluent-crm-sidebar-content">'
                . '<h3>' . __('Product History', 'fluent-crm') . '</h3>'
                . '<p>' . __('View your purchase history from FluentCart.', 'fluent-crm') . '</p>'
                . '</div>';
        }

        // Aggregate order stats
        $ordersQuery = Order::where('customer_id', $customer->id);
        $orderCount = (clone $ordersQuery)->count();

        if (!$orderCount) {
            return '<div class="fluent-crm-sidebar-content">'
                . '<h3>' . __('Product History', 'fluent-crm') . '</h3>'
                . '<p>' . __('No purchases found for this contact in FluentCart.', 'fluent-crm') . '</p>'
                . '</div>';
        }

        // What the customer actually paid: successful orders, net of refunds.
        $paidOrders = (clone $ordersQuery)->whereIn('payment_status', \FluentCart\App\Helpers\Status::getOrderPaymentSuccessStatuses());
        $totalSpent = (clone $paidOrders)->sum('total_paid') - (clone $paidOrders)->sum('total_refund');
        $firstOrder = (clone $ordersQuery)->orderBy('id', 'ASC')->value('created_at');
        $lastOrder = (clone $ordersQuery)->orderBy('id', 'DESC')->value('created_at');

        // Latest 15 order items across the latest orders.
        $recentOrders = (clone $ordersQuery)
            ->with(['order_items' => function($q){
                $q->orderBy('id', 'DESC');
            }])
            ->orderBy('id', 'DESC')
            ->limit(200)
            ->get();

        $items = [];
        foreach ($recentOrders as $order) {
            foreach ($order->order_items as $orderItem) {

                $itemDisplayName = $orderItem->post_title;

                if($orderItem->post_title != $orderItem->title) {
                    $itemDisplayName = $orderItem->post_title . ' - ' . $orderItem->title;
                }

                $items[] = [
                    'name' => $itemDisplayName,
                    'price' => isset($orderItem->line_total) ? Helper::toDecimal($orderItem->line_total) : 0,
                    'created_at' => self::siteTime($order->created_at),
                    'order_id' => $order->id,
                ];
                if (count($items) >= 15) {
                    break 2; // Exit both loops once we have enough
                }
            }
        }

        $html = '<div class="fluent-crm-sidebar-content fc_payment_summary">';
        $html .= '<h3 class="history_title">' . __('Order Summary', 'fluent-crm') . '</h3>';
        $html .= '<div class="fc_history_widget"><ul class="fc_full_listed">';
        $html .= '<li><span class="fc_list_sub">' . __('Total Orders', 'fluent-crm') . '</span><span class="fc_list_value">' . intval($orderCount) . '</span></li>';
        $html .= '<li><span class="fc_list_sub">' . __('Total Spent', 'fluent-crm') . '</span><span class="fc_list_value">' . esc_html(Helper::toDecimal($totalSpent)) . '</span></li>';
        if ($firstOrder) {
            $html .= '<li><span class="fc_list_sub">' . __('First Order', 'fluent-crm') . '</span><span class="fc_list_value">' . esc_html(CrmHelper::formatDateTime(self::siteTime($firstOrder))) . '</span></li>';
        }
        if ($lastOrder) {
            $html .= '<li><span class="fc_list_sub">' . __('Last Order', 'fluent-crm') . '</span><span class="fc_list_value">' . esc_html(CrmHelper::formatDateTime(self::siteTime($lastOrder))) . '</span></li>';
        }
        $html .= '</ul></div>';

        $html .= '<h3 class="history_title">' . __('Purchased Products', 'fluent-crm') . '</h3>';
        $html .= '<div class="fc_history_widget"><ul class="fc_full_listed max_height_550">';
        foreach ($items as $item) {
            $orderUrl = admin_url('admin.php?page=fluent-cart#/orders/' . $item['order_id'] . '/view');
            $badges = '<span class="el-tag el-tag--primary">' . esc_html(Helper::toDecimal($item['price'])) . '</span>';
            $badges .= '<span class="el-tag el-tag--primary"><a target="_blank" rel="noopener" href="' . esc_url($orderUrl) . '">' . esc_html(CrmHelper::formatDateTime($item['created_at'])) . '</a></span>';
            $html .= '<li class="fc_product_name">' . esc_html($item['name']) . ' ' . $badges . '</li>';
        }
        if (!$items) {
            $html .= '<li>' . __('No purchased products found.', 'fluent-crm') . '</li>';
        }
        $html .= '</ul></div>';

        $html .= '</div>';

        return $html;
    }


     public function addAutomationConditions($groups)
    {
        $conditionItems = [
            [
                'value'             => 'commerce_exist',
                'label'             => __('Is a customer?', 'fluent-crm'),
                'type'              => 'selections',
                'is_multiple'       => false,
                'disable_values'    => true,
                'value_description' => __('This filter checks whether the contact has at least one order', 'fluent-crm'),
                'custom_operators'  => [
                    'exist'     => __('Yes', 'fluent-crm'),
                    'not_exist' => __('No', 'fluent-crm'),
                ]
            ],
            [
                'value' => 'ltv',
                'label' => __('Lifetime Value', 'fluent-crm'),
                'type'  => 'numeric'
            ],
            [
                'value' => 'aov',
                'label' => __('Average Order Value', 'fluent-crm'),
                'type'  => 'numeric',
            ],
            [
                'value' => 'first_purchase_date',
                'label' => __('First Order Date', 'fluent-crm'),
                'type'  => 'dates'
            ],
            [
                'value' => 'last_purchase_date',
                'label' => __('Last Order Date', 'fluent-crm'),
                'type'  => 'dates'
            ],
            [
                'value'            => 'purchased_items',
                'label'            => __('Products', 'fluent-crm'),
                'type'             => 'selections',
                'component'        => 'product_selector',
                'is_multiple'      => true,
                'custom_operators' => [
                    'exist'     => __('purchased', 'fluent-crm'),
                    'not_exist' => __('not purchased', 'fluent-crm'),
                ],
                'help'             => __('This filter checks whether the contact has a paid order that includes at least one of the selected products', 'fluent-crm')
            ],
            [
                'value'             => 'variation_purchased',
                'label'             => __('Product Variations', 'fluent-crm'),
                'type'              => 'cascade_selections',
                'provider'          => 'fct_variations',
                'is_multiple'       => true,
                'value_description' => __('This filter checks whether the contact has purchased at least one of the selected product variations', 'fluent-crm'),
                'custom_operators'  => [
                    'exist'     => __('purchased', 'fluent-crm'),
                    'not_exist' => __('not purchased', 'fluent-crm'),
                ]
            ],
            [
                'value'            => 'purchased_categories',
                'label'            => __('Product Categories', 'fluent-crm'),
                'type'             => 'selections',
                'component'        => 'tax_selector',
                'taxonomy'         => 'product-categories',
                'is_multiple'      => true,
                'disabled'         => true,
                'help'             => __('This filter checks whether the contact has a paid order that includes a product from at least one of the selected categories', 'fluent-crm'),
                'custom_operators' => [
                    'exist'     => __('purchased', 'fluent-crm'),
                    'not_exist' => __('not purchased', 'fluent-crm'),
                ]
            ],
            [
                'value'            => 'commerce_coupons',
                'label'            => __('Used Coupons', 'fluent-crm'),
                'type'             => 'selections',
                'component'        => 'ajax_selector',
                'option_key'       => 'fct_coupons',
                'is_multiple'      => true,
                'disabled'         => true,
                'custom_operators' => [
                    'exist'     => __('in', 'fluent-crm'),
                    'not_exist' => __('not in', 'fluent-crm'),
                ],
                'help'             => __('This filter checks whether the contact has used at least one of the selected coupons', 'fluent-crm')
            ]
        ];

        if (ModuleSettings::isActive('license')) {
            $conditionItems[] = [
                'value'            => 'active_licenses',
                'label'            => __('Active Licenses', 'fluent-crm'),
                'type'             => 'selections',
                'component'        => 'product_selector',
                'is_multiple'      => true,
                'custom_operators' => [
                    'exist'     => __('have', 'fluent-crm'),
                    'not_exist' => __('do not have', 'fluent-crm'),
                ],
                'help'             => __('This filter checks whether the contact has a valid (not expired) license for at least one of the selected products', 'fluent-crm')
            ];
            $conditionItems[] = [
                'value'             => 'active_variation_licenses',
                'label'             => __('Active Variation Licenses', 'fluent-crm'),
                'type'              => 'cascade_selections',
                'provider'          => 'fct_variations',
                'is_multiple'       => true,
                'value_description' => __('This filter checks whether the contact has a valid (not expired) license for at least one of the selected variations', 'fluent-crm'),
                'custom_operators'  => [
                    'exist'     => __('have', 'fluent-crm'),
                    'not_exist' => __('do not have', 'fluent-crm'),
                ]
            ];
            $conditionItems[] = [
                'value'            => 'expired_licenses',
                'label'            => __('Expired Licenses', 'fluent-crm'),
                'type'             => 'selections',
                'component'        => 'product_selector',
                'is_multiple'      => true,
                'custom_operators' => [
                    'exist'     => __('have', 'fluent-crm'),
                    'not_exist' => __('do not have', 'fluent-crm'),
                ],
                'help'             => __('This filter checks whether the contact has an expired license for at least one of the selected products', 'fluent-crm')
            ];
            $conditionItems[] = [
                'value'             => 'expired_variation_licenses',
                'label'             => __('Expired Variation Licenses', 'fluent-crm'),
                'type'              => 'cascade_selections',
                'provider'          => 'fct_variations',
                'is_multiple'       => true,
                'value_description' => __('This filter checks whether the contact has an expired license for at least one of the selected variations', 'fluent-crm'),
                'custom_operators'  => [
                    'exist'     => __('have', 'fluent-crm'),
                    'not_exist' => __('do not have', 'fluent-crm'),
                ]
            ];
            $conditionItems[] = [
                'value'             => 'license_exist',
                'label'             => __('Has any active license?', 'fluent-crm'),
                'type'              => 'selections',
                'is_multiple'       => false,
                'disable_values'    => true,
                'value_description' => __('Check if the contact has an active license for any product', 'fluent-crm'),
                'custom_operators'  => [
                    'exist'     => __('Yes', 'fluent-crm'),
                    'not_exist' => __('No', 'fluent-crm'),
                ]
            ];
        }

        $groups['fluent_cart'] = [
            'label'    => __('FluentCart', 'fluent-crm'),
            'value'    => 'fluent_cart',
            'children' => $conditionItems
        ];

        return $groups;
    }

    public function assessAutomationConditions($result, $conditions, $subscriber)
    {
        $legacyConditions = [];
            $formattedConditions = [];

            $commerceProps = [
                'commerce_exist',
                'ltv', // lifetime value
                'aov', // average order value
                'first_purchase_date',
                'last_purchase_date',
                'purchased_items', // products purchased
                'variation_purchased', // product variations purchased
                'purchased_categories', // product categories
                'commerce_coupons', // used coupons
                // Licence conditions (license module); FluentCart's contact filter evaluates them.
                'active_licenses',
                'active_variation_licenses',
                'expired_licenses',
                'expired_variation_licenses',
                'license_exist',
            ];

            $licenseProps = ['active_licenses', 'active_variation_licenses', 'expired_licenses', 'expired_variation_licenses', 'license_exist'];

            foreach ($conditions as $condition) {
                $prop = $condition['data_key'];
                $operator = $condition['operator'];
                // A saved licence condition with the license module off cannot be evaluated: fail closed.
                if (in_array($prop, $licenseProps, true) && !ModuleSettings::isActive('license')) {
                    return false;
                }
                if (in_array($prop, $commerceProps)) {
                    $formattedConditions[] = [
                        'operator' => $operator,
                        'value'    => $condition['data_value'],
                        'property' => $prop,
                    ];
                } else {
                    $legacyConditions[] = $condition;
                }
            }

            if ($formattedConditions) {
                $hasSubscriber = Subscriber::where('id', $subscriber->id)->where(function ($q) use ($formattedConditions) {
                    do_action_ref_array('fluentcrm_contacts_filter_fluent_cart', [&$q, $formattedConditions]);
                })->first();
                if (!$hasSubscriber) {
                    return false;
                }
            }

        if ($legacyConditions) {
            $cartCustomer = Customer::query()
                ->where('email', $subscriber->email)
                ->when($subscriber->user_id, function ($q) use ($subscriber) {
                    return $q->orWhere('user_id', $subscriber->user_id);
                })
                ->first();

            if (!$cartCustomer) {
                return false;
            }
        }

        return $result;
    }
}
