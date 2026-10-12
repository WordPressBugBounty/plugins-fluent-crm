<?php

namespace FluentCrm\App\Services\ExternalIntegrations\FluentCart;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\Tag;
use FluentCrm\App\Services\PermissionManager;
use FluentCrm\Framework\Support\Arr;

/**
 * Renders the FluentCRM contact widget on FluentCart's customer and order pages.
 * Pushes ContactWidgets.vue as a 'vue-template' widget; tag/list changes go to FluentCRM's REST API.
 */
class CustomerWidget
{
    public function init()
    {
        add_filter('fluent_cart/widgets/customer', [$this, 'pushCustomerWidget'], 10, 2);
        add_filter('fluent_cart/widgets/single_order_page', [$this, 'pushOrderWidget'], 10, 2);
    }

    /**
     * Customer page: the contact is the customer being viewed.
     *
     * @param array $widgets Widgets registered so far.
     * @param array $data    Request data from FluentCart's WidgetsController, contains 'customer_id'.
     * @return array
     */
    public function pushCustomerWidget($widgets, $data)
    {
        if (!PermissionManager::currentUserCan('fcrm_read_contacts')) {
            return $widgets;
        }

        $customerId = (int)Arr::get((array)$data, 'customer_id');
        if (!$customerId) {
            return $widgets;
        }

        return $this->pushWidgetForCustomer($widgets, Customer::find($customerId));
    }

    /**
     * Order page: the contact is the customer who placed the order.
     *
     * @param array $widgets Widgets registered so far.
     * @param array $data    Request data, contains the pre-loaded 'order' and 'order_id'.
     * @return array
     */
    public function pushOrderWidget($widgets, $data)
    {
        if (!PermissionManager::currentUserCan('fcrm_read_contacts')) {
            return $widgets;
        }

        $order = Arr::get((array)$data, 'order');
        if (!$order) {
            $orderId = (int)Arr::get((array)$data, 'order_id');
            $order = $orderId ? Order::find($orderId) : null;
        }

        if (!$order) {
            return $widgets;
        }

        // A guest order may not be linked to a customer record
        return $this->pushWidgetForCustomer($widgets, $order->customer);
    }

    /**
     * Match the FluentCart customer to a CRM contact by email and push the widget.
     * Callers verify CRM read access.
     *
     * @param array                                $widgets
     * @param \FluentCart\App\Models\Customer|null  $customer
     * @return array
     */
    private function pushWidgetForCustomer($widgets, $customer)
    {
        if (!$customer || !$customer->email) {
            return $widgets;
        }

        $subscriber = Subscriber::with(['tags', 'lists'])
            ->where('email', $customer->email)
            ->first();

        if (!$subscriber) {
            return $widgets;
        }

        $component = $this->getComponentSource();
        if (!$component) {
            return $widgets;
        }

        $widgets[] = [
            'type'      => 'vue-template',
            'use_card'  => false, // the template renders its own fct-card markup
            'component' => $component,
            'payload'   => $this->buildPayload($subscriber, $customer)
        ];

        return $widgets;
    }

    /**
     * Raw SFC source for FluentCart's runtime template loader.
     *
     * @return string
     */
    private function getComponentSource()
    {
        $file = FLUENTCRM_PLUGIN_PATH . 'app/Services/ExternalIntegrations/FluentCart/VueTemplates/ContactWidgets.vue';

        if (!file_exists($file)) {
            return '';
        }

        return file_get_contents($file);
    }

    /**
     * Everything the Vue template needs: subscriber summary, applied and
     * available segments, permission flags, URLs and translated labels.
     *
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     * @param \FluentCart\App\Models\Customer  $customer
     * @return array
     */
    private function buildPayload($subscriber, $customer)
    {
        $canManage = PermissionManager::currentUserCan('fcrm_manage_contacts');

        $subscriberName = trim($subscriber->full_name);

        // Show the CRM name only when it differs from the FluentCart name.
        if ($subscriberName === trim((string)$customer->full_name)) {
            $subscriberName = '';
        }

        return [
            'subscriber'  => [
                'id'        => $subscriber->id,
                'full_name' => $subscriberName,
                'status'    => $subscriber->status,
                'stats'     => $subscriber->stats()
            ],
            'applied'     => [
                'lists' => $this->formatSegments($subscriber->lists),
                'tags'  => $this->formatSegments($subscriber->tags)
            ],
            // Pickers render only for managers.
            'options'     => [
                'lists' => $canManage ? $this->formatSegments(Lists::orderBy('title', 'ASC')->get()) : [],
                'tags'  => $canManage ? $this->formatSegments(Tag::orderBy('title', 'ASC')->get()) : []
            ],
            'permissions' => [
                'can_manage' => $canManage,
                'can_create' => $canManage && PermissionManager::currentUserCan('fcrm_manage_contact_cats')
            ],
            'urls'        => [
                'crm_profile' => admin_url('admin.php?page=fluentcrm-admin#/subscribers/' . $subscriber->id),
                'rest_base'   => untrailingslashit(rest_url('fluent-crm/v2'))
            ],
            'i18n'        => [
                'section_title'   => __('Contact Info', 'fluent-crm'),
                'view_in_crm'     => __('View', 'fluent-crm'),
                'name'            => __('Name:', 'fluent-crm'),
                'status'          => __('Status:', 'fluent-crm'),
                'emails_sent'     => __('Total emails sent', 'fluent-crm'),
                'open_rate'       => __('Open Rate', 'fluent-crm'),
                'click_rate'      => __('Click Rate', 'fluent-crm'),
                'lists'           => __('Lists', 'fluent-crm'),
                'tags'            => __('Tags', 'fluent-crm'),
                'add'             => __('Add', 'fluent-crm'),
                'search_lists'    => __('Search lists', 'fluent-crm'),
                'search_tags'     => __('Search tags', 'fluent-crm'),
                'no_lists'        => __('No lists found.', 'fluent-crm'),
                'no_tags'         => __('No tags found.', 'fluent-crm'),
                'apply'           => __('Apply', 'fluent-crm'),
                'add_new'         => __('Add New', 'fluent-crm'),
                'remove'          => __('Remove', 'fluent-crm'),
                'cancel'          => __('Cancel', 'fluent-crm'),
                /* translators: %s: tag or list title */
                'remove_question' => __('Remove "%s" from this contact?', 'fluent-crm'),
                /* translators: %s: tag or list title. Accessible label for the remove button */
                'remove_item'     => __('Remove %s', 'fluent-crm'),
                'updated'         => __('The contact has been updated.', 'fluent-crm'),
                'failed'          => __('Something went wrong. Please try again.', 'fluent-crm')
            ]
        ];
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Collection $segments Tag or Lists models.
     * @return array [{id: int, title: string}, ...]
     */
    private function formatSegments($segments)
    {
        $formatted = [];

        foreach ($segments as $segment) {
            $formatted[] = [
                'id'    => (int)$segment->id,
                'title' => $segment->title
            ];
        }

        return $formatted;
    }
}
