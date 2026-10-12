<?php

namespace FluentCrm\App\Http\Controllers;

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Http\Request\Request;

/**
 *  CampaignAnalyticsController - REST API Handler Class
 *
 *  REST API Handler
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class CampaignAnalyticsController extends Controller
{
    public function getLinksReport(CampaignUrlMetric $campaignUrlMetric, $campaignId)
    {
        $campaign = Campaign::withoutGlobalScopes()->findOrFail($campaignId);
        $clickStatus = $campaign->getClickTrackingStatus();
        $openStatus = $campaign->getOpenTrackingStatus();

        if ($clickStatus === '') {
            $clickStatus = fluentcrmTrackClicking();
        }

        if ($openStatus === '') {
            $openStatus = fluentcrmTrackEmailOpen();
        }

        $links = array_values($campaignUrlMetric->getLinksReport($campaign));

        return $this->sendSuccess([
            'links'        => $links,
            'click_status' => $clickStatus,
            'open_status'  => $openStatus
        ]);
    }

    public function getRevenueReport(Request $request, $campaignId)
    {
        $limit = intval($request->get('per_page', 10));
        $offset = (intval($request->get('page', 1)) - 1) * $limit;

        $sources = $this->getActiveRevenueSources();
        $multiSource = count($sources) > 1;

        if (empty($sources)) {
            return [
                'orders' => [],
                'labels' => $this->getRevenueLabels(false),
                'total'  => 0
            ];
        }

        // One index across all active sources so pagination spans them; newest first within each source.
        $index = [];
        foreach ($sources as $source) {
            foreach ($this->getAttributedOrderIds($source, $campaignId) as $orderId) {
                $index[] = ['source' => $source, 'order_id' => (int) $orderId];
            }
        }

        $totalOrders = count($index);
        $pageEntries = array_slice($index, $offset, $limit);

        $orders = [];
        foreach ($pageEntries as $entry) {
            $row = $this->formatRevenueRow($entry['source'], $entry['order_id'], $multiSource);
            if ($row) {
                $orders[] = $row;
            }
        }

        return [
            'orders' => $orders,
            'labels' => $this->getRevenueLabels($multiSource),
            'total'  => $totalOrders
        ];
    }

    public function getRevenueReSyncReport(Request $request, $campaignId)
    {
        $sources = $this->getActiveRevenueSources();
        if (empty($sources)) {
            return [
                'message' => __('No revenue found for this campaign', 'fluent-crm')
            ];
        }

        // auto=yes is the silent recount on page load: it runs only when every stored order is still visible and never clears the record.
        $isAuto = $request->get('auto') === 'yes';

        // Auto re-sync runs at most once per 10 minutes per campaign; manual re-sync is not throttled.
        if ($isAuto) {
            $throttleKey = 'fcrm_revenue_auto_resync_' . (int) $campaignId;
            if (get_transient($throttleKey)) {
                return [
                    'message' => __('Revenue was re-synced recently', 'fluent-crm')
                ];
            }
            set_transient($throttleKey, 1, 10 * MINUTE_IN_SECONDS);
        }

        if ($isAuto && !$this->storedRevenueOrdersAreVisible($campaignId, $sources)) {
            return [
                'message' => __('Revenue re-sync skipped: some recorded orders are not available from the active store plugins.', 'fluent-crm')
            ];
        }

        $revenueData = ['orderIds' => []];
        $primaryCurrency = null;

        foreach ($sources as $source) {
            $sourceData = $this->reSyncSourceRevenue($source, $campaignId);
            foreach ($sourceData['orderIds'] as $oid) {
                if (!in_array($oid, $revenueData['orderIds'])) {
                    $revenueData['orderIds'][] = $oid;
                }
            }
            foreach ($sourceData['totals'] as $currency => $cents) {
                if (!isset($revenueData[$currency])) {
                    $revenueData[$currency] = 0;
                    if ($primaryCurrency === null) {
                        $primaryCurrency = $currency;
                    }
                }
                $revenueData[$currency] += $cents;
            }
        }

        if (empty($revenueData['orderIds'])) {
            if ($isAuto) {
                return [
                    'message' => __('No order found to re-sync', 'fluent-crm')
                ];
            }

            // Manual re-sync clears stale revenue, including revenue from a deactivated store plugin.
            if (fluentcrm_get_campaign_meta($campaignId, '_campaign_revenue')) {
                fluentcrm_delete_campaign_meta($campaignId, '_campaign_revenue');

                return [
                    'message' => __('No attributed orders found. Stale revenue data has been cleared.', 'fluent-crm'),
                    'total'   => number_format(0, 2)
                ];
            }

            return [
                'message' => __('No order found to re-sync', 'fluent-crm'),
                'total'   => number_format(0, 2)
            ];
        }

        fluentcrm_update_campaign_meta($campaignId, '_campaign_revenue', $revenueData);

        $primaryTotal = $primaryCurrency ? $revenueData[$primaryCurrency] : 0;

        return [
            'message' => __('The revenue has been re-synced.', 'fluent-crm'),
            'total'   => number_format($primaryTotal / 100, 2)
        ];
    }

    /**
     * Whether every stored revenue order is still attributed to this campaign in an active source.
     * Refunded or cancelled orders keep their _fc_cid meta and count as visible.
     */
    protected function storedRevenueOrdersAreVisible($campaignId, $sources)
    {
        $stored = fluentcrm_get_campaign_meta($campaignId, '_campaign_revenue', true);
        if (!is_array($stored) || empty($stored['orderIds']) || !is_array($stored['orderIds'])) {
            return true;
        }

        $visible = [];
        foreach ($sources as $source) {
            $visible = array_merge($visible, $this->getAttributedOrderIds($source, $campaignId));
        }

        return !array_diff(array_map('intval', $stored['orderIds']), $visible);
    }

    /**
     * Active commerce sources for campaign revenue, in display order.
     */
    protected function getActiveRevenueSources()
    {
        $sources = [];
        if (defined('WC_PLUGIN_FILE')) {
            $sources[] = 'woo';
        }
        if (Helper::isEdd3()) {
            $sources[] = 'edd';
        }
        if (defined('FLUENTCART_VERSION')) {
            $sources[] = 'fct';
        }
        return $sources;
    }

    /**
     * Order IDs attributed to this campaign in one source, newest first.
     */
    protected function getAttributedOrderIds($source, $campaignId)
    {
        if ($source === 'woo') {
            if (Helper::isWooHposEnabled()) {
                return fluentCrmDb()->table('wc_orders_meta')
                    ->where('meta_key', '_fc_cid')
                    ->where('meta_value', $campaignId)
                    ->orderBy('id', 'DESC')
                    ->get()
                    ->pluck('order_id')
                    ->map(function ($orderId) {
                        return intval($orderId);
                    })
                    ->all();
            }
            return fluentCrmDb()->table('postmeta')
                ->where('meta_key', '_fc_cid')
                ->where('meta_value', $campaignId)
                ->orderBy('meta_id', 'DESC')
                ->get()
                ->pluck('post_id')
                ->map(function ($orderId) {
                    return intval($orderId);
                })
                ->all();
        }

        if ($source === 'edd') {
            // EDD 3 stores attribution in edd_ordermeta, not legacy postmeta.
            return fluentCrmDb()->table('edd_ordermeta')
                ->where('meta_key', '_fc_cid')
                ->where('meta_value', $campaignId)
                ->orderBy('meta_id', 'DESC')
                ->get()
                ->pluck('edd_order_id')
                ->map(function ($orderId) {
                    return intval($orderId);
                })
                ->all();
        }

        if ($source === 'fct') {
            return fluentCrmDb()->table('fct_order_meta')
                ->where('meta_key', '_fc_cid')
                ->where('meta_value', $campaignId)
                ->orderBy('id', 'DESC')
                ->get()
                ->pluck('order_id')
                ->map(function ($orderId) {
                    return intval($orderId);
                })
                ->all();
        }

        return [];
    }

    /**
     * Net revenue per currency for one source, from paid orders minus refunds.
     * Returns ['orderIds' => [...], 'totals' => ['usd' => cents]]; orders netting to zero or less are skipped.
     */
    protected function reSyncSourceRevenue($source, $campaignId)
    {
        $result = ['orderIds' => [], 'totals' => []];
        $orderIds = $this->getAttributedOrderIds($source, $campaignId);
        if (!$orderIds) {
            return $result;
        }

        if ($source === 'woo') {
            $paidStatuses = function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : ['processing', 'completed'];
            $currency = strtolower(get_woocommerce_currency());
            foreach ($orderIds as $orderId) {
                $order = wc_get_order($orderId);
                if (!$order || !$order->get_id()) {
                    continue;
                }
                if (!in_array($order->get_status(), $paidStatuses, true)) {
                    continue;
                }
                $netCents = intval(((float) $order->get_total() - (float) $order->get_total_refunded()) * 100);
                if ($netCents <= 0) {
                    continue;
                }
                $result['orderIds'][] = (int) $order->get_id();
                $result['totals'][$currency] = ($result['totals'][$currency] ?? 0) + $netCents;
            }
            return $result;
        }

        if ($source === 'edd') {
            // EDD 3 keeps canonical status and refund data in order tables.
            $completeStatuses = ['complete', 'completed', 'partially_refunded'];
            foreach ($orderIds as $orderId) {
                $payment = new \EDD_Payment($orderId);
                if (!$payment || !$payment->ID) {
                    continue;
                }
                if (!in_array($payment->status, $completeStatuses, true)) {
                    continue;
                }
                $netTotal = function_exists('edd_get_order_total')
                    ? edd_get_order_total($payment->ID)
                    : $payment->total;
                $netCents = intval(((float) $netTotal) * 100);
                if ($netCents <= 0) {
                    continue;
                }
                $currency = strtolower(edd_get_payment_currency_code($payment->ID) ?: 'usd');
                $result['orderIds'][] = (int) $payment->ID;
                $result['totals'][$currency] = ($result['totals'][$currency] ?? 0) + $netCents;
            }
            return $result;
        }

        if ($source === 'fct') {
            // Partial refunds still count; total_refund is subtracted below.
            $successStatuses = \FluentCart\App\Helpers\Status::getOrderPaymentSuccessStatuses();
            $orders = \FluentCart\App\Models\Order::query()
                ->whereIn('id', $orderIds)
                ->whereIn('payment_status', $successStatuses)
                ->get();
            foreach ($orders as $order) {
                $netCents = (int) $order->total_amount - (int) ($order->total_refund ?? 0);
                if ($netCents <= 0) {
                    continue;
                }
                $currency = strtolower($order->currency ?: 'usd');
                $result['orderIds'][] = (int) $order->id;
                $result['totals'][$currency] = ($result['totals'][$currency] ?? 0) + $netCents;
            }
            return $result;
        }

        return $result;
    }

    /**
     * One merged revenue table row; adds `source` when several platforms contribute.
     */
    protected function formatRevenueRow($source, $orderId, $multiSource)
    {
        $row = null;
        if ($source === 'woo') {
            $row = $this->formatWooOrderRow($orderId);
        } else if ($source === 'edd') {
            $row = $this->formatEddOrderRow($orderId);
        } else if ($source === 'fct') {
            $row = $this->formatFluentCartOrderRow($orderId);
        }

        if (!$row) {
            return null;
        }

        if ($multiSource) {
            $row = ['source' => $this->getSourceLabel($source)] + $row;
        }

        return $row;
    }

    protected function getSourceLabel($source)
    {
        $labels = [
            'woo' => 'WooCommerce',
            'edd' => 'EDD',
            'fct' => 'FluentCart',
        ];
        return $labels[$source] ?? $source;
    }

    protected function getRevenueLabels($multiSource)
    {
        $labels = [
            'order'  => '#',
            'title'  => __('Customer', 'fluent-crm'),
            'status' => __('Status', 'fluent-crm'),
            'date'   => __('Date', 'fluent-crm'),
            'total'  => __('Total', 'fluent-crm'),
            'action' => __('View', 'fluent-crm'),
        ];
        if ($multiSource) {
            $labels = ['source' => __('Source', 'fluent-crm')] + $labels;
        }
        return $labels;
    }

    protected function formatWooOrderRow($orderId)
    {
        $order = wc_get_order($orderId);
        if (!$order || !$order->get_id()) {
            return null;
        }

        /* translators: 1: billing first name, 2: billing last name */
        $buyer = trim(sprintf(_x('%1$s %2$s', 'full name', 'fluent-crm'), $order->get_billing_first_name(), $order->get_billing_last_name()));

        $order_timestamp = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : '';

        if (!$order_timestamp) {
            $show_date = '&ndash;';
        } else if ($order_timestamp > strtotime('-1 day', time()) && $order_timestamp <= time()) {
            $show_date = sprintf(
            /* translators: %s: human-readable time difference */
                _x('%s ago', '%s = human-readable time difference', 'fluent-crm'),
                human_time_diff($order->get_date_created()->getTimestamp(), time())
            );
        } else {
            /**
             * Determine the date format for displaying the order creation date in the WooCommerce admin in FluentCRM.
             *
             * @param string The date format to be used. Default is 'M j, Y'.
             * @param string The context for the date format. Default is 'woocommerce'.
             * @since 2.2.0
             */
            $show_date = $order->get_date_created()->date_i18n(apply_filters('woocommerce_admin_order_date_format', __('M j, Y', 'fluent-crm')));
        }

        $editUrl = admin_url('post.php?post=' . absint($order->get_id()) . '&action=edit');

        return [
            'order'  => '#' . esc_html($order->get_order_number()),
            'title'  => '<a href="' . esc_url($editUrl) . '" class="order-view"><strong>' . esc_html($buyer) . '</strong></a>',
            'status' => wc_get_order_status_name($order->get_status()),
            'date'   => $show_date,
            'total'  => $order->get_formatted_order_total(),
            'action' => '<a href="' . esc_url($editUrl) . '">' . esc_html__('View', 'fluent-crm') . '</a>',
        ];
    }

    protected function formatEddOrderRow($orderId)
    {
        $payment = new \EDD_Payment($orderId);
        if (!$payment || !$payment->ID) {
            return null;
        }

        $orderActionHtml = '<a href="' . add_query_arg('id', $payment->ID, admin_url('edit.php?post_type=download&page=edd-payment-history&view=view-order-details')) . '">' . esc_html__('View', 'fluent-crm') . '</a>';
        $amount = !empty($payment->total) ? $payment->total : 0;
        $customer_id = edd_get_payment_customer_id($payment->ID);

        if (!empty($customer_id)) {
            $customer = new \EDD_Customer($customer_id);
            $customerName = '<a href="' . esc_url(admin_url("edit.php?post_type=download&page=edd-customers&view=overview&id=$customer_id")) . '">' . esc_html($customer->name) . '</a>';
        } else {
            $email = edd_get_payment_user_email($payment->ID);
            $customerName = '<a href="' . esc_url(admin_url("edit.php?post_type=download&page=edd-payment-history&s=$email")) . '">' . esc_html__('(customer missing)', 'fluent-crm') . '</a>';
        }

        return [
            'order'  => '#' . $payment->number,
            'title'  => $customerName,
            'status' => $payment->status_nicename,
            'date'   => date_i18n(get_option('date_format'), strtotime($payment->date)),
            'total'  => edd_currency_filter(edd_format_amount($amount), edd_get_payment_currency_code($payment->ID)),
            'action' => $orderActionHtml,
        ];
    }

    protected function formatFluentCartOrderRow($orderId)
    {
        $order = \FluentCart\App\Models\Order::with('customer')->find($orderId);
        if (!$order) {
            return null;
        }

        $customerName = '';
        if ($order->customer) {
            $customerName = trim($order->customer->first_name . ' ' . $order->customer->last_name);
            if (!$customerName) {
                $customerName = $order->customer->email;
            }
        }

        $orderUrl = admin_url('admin.php?page=fluent-cart#/orders/' . $order->id . '/view');

        return [
            'order'  => '#' . ($order->invoice_no ?: $order->id),
            'title'  => '<a target="_blank" rel="noopener" href="' . esc_url($orderUrl) . '">' . esc_html($customerName) . '</a>',
            'status' => esc_html(\FluentCrm\App\Services\Helper::getStatusText($order->status)),
            'date'   => date_i18n(get_option('date_format'), strtotime($order->created_at)),
            'total'  => \FluentCart\App\Helpers\Helper::toDecimal($order->total_amount, true, $order->currency),
            'action' => '<a target="_blank" rel="noopener" href="' . esc_url($orderUrl) . '">' . esc_html__('View', 'fluent-crm') . '</a>',
        ];
    }

    public function getUnsubscribers(Request $request, $campaignId)
    {
        $unsubscribes = CampaignUrlMetric::with('subscriber')
            ->where('campaign_id', $campaignId)
            ->where('type', 'unsubscribe')
            ->paginate();

        foreach ($unsubscribes as $unsubscribe) {
            $unsubscribe->subscriber->reason = $unsubscribe->subscriber->unsubscribeReason();
        }

        return [
            'unsubscribes' => $unsubscribes
        ];
    }

    public function getSegmentedContacts(Request $request, $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        $contactsModel = $campaign->getSubscribersModel();

        $search = $request->getSafe('search', 'sanitize_text_field');

        if ($search) {
            $contactsModel->searchBy($search);
        }

        if ($orderBy = Helper::sanitizeOrderBy($request->get('sort_by'), 'id')) {
            $orderType = Helper::sanitizeOrderBy($request->get('sort_type'), 'desc');
            $contactsModel->orderBy($orderBy, $orderType);
        }

        $contacts = $contactsModel->with(['lists', 'tags'])->paginate();

        return [
            'subscribers' => $contacts
        ];
    }
}
