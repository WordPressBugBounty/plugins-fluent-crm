<?php

namespace FluentCrm\App\Http\Controllers;

use FluentCrm\App\Hooks\Handlers\PurchaseHistory;
use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Models\CampaignUrlMetric;
use FluentCrm\App\Models\Company;
use FluentCrm\App\Models\CustomEmailCampaign;
use FluentCrm\App\Models\EventTracker;
use FluentCrm\App\Models\Funnel;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Models\SubscriberNote;
use FluentCrm\App\Models\SubscriberPivot;
use FluentCrm\App\Services\AutoSubscribe;
use FluentCrm\App\Services\ContactQuickPins;
use FluentCrm\App\Services\ContactSearch;
use FluentCrm\App\Services\ContactsQuery;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\PermissionManager;
use FluentCrm\App\Services\Sanitize;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Support\Collection;
use FluentCrm\Framework\Http\Request\Request;

/**
 *  SubscriberController - REST API Handler Class
 *
 *  REST API Handler
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class SubscriberController extends Controller
{
    public function index()
    {
        $with = ['tags', 'lists'];

        if (Helper::isCompanyEnabled()) {
            $with[] = 'company';
            $with[] = 'companies';
        }

        // `relevance` ordering is opt-in; `id` keeps meaning id for API clients.
        $queryArgs = ContactsQuery::argsFromRequest($this->request->all());
        $queryArgs['with'] = $with;
        $searchCustomFields = $queryArgs['search_custom_fields'];
        $filterType = $queryArgs['filter_type'];

        $subscribers = (new ContactsQuery($queryArgs))->paginate();

        $response = [
            'subscribers' => $subscribers,
            'custom'      => $this->request->get('custom_fields'),
            'search_meta' => ContactSearch::describe($queryArgs['search'], $searchCustomFields)
        ];

        // Status tab counts, simple filter mode only.
        if ($filterType != 'advanced' && in_array($this->request->get('with_status_counts'), [true, 1, '1', 'true', 'yes'], true)) {
            $response['status_counts'] = $this->statusCountsFor($queryArgs);
        }

        return $this->sendSuccess($response);
    }

    /**
     * Contacts per status for the current filters.
     * Status counts ignore the status filter itself.
     *
     * @param array $queryArgs The list's ContactsQuery args.
     * @return array<string, int> status => count, every known status present.
     */
    private function statusCountsFor($queryArgs)
    {
        $facetArgs = array_merge($queryArgs, [
            'statuses'       => [],
            'contact_status' => '',
            'with'           => [],
            'sort_by'        => '',
            'custom_fields'  => false,
            'has_commerce'   => false,
        ]);

        $rows = (new ContactsQuery($facetArgs))
            ->getModel()
            ->toBase()
            ->reorder()
            ->select('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->get();

        $counts = array_fill_keys(fluentcrm_subscriber_statuses(), 0);
        foreach ($rows as $row) {
            if ($row->status !== null && $row->status !== '') {
                $counts[$row->status] = (int)$row->total;
            }
        }

        return $counts;
    }

    /**
     * Save the current user's pinned list and tag quick filters.
     *
     * @return \WP_REST_Response
     */
    public function saveQuickPins()
    {
        return $this->sendSuccess([
            'pins' => ContactQuickPins::save(get_current_user_id(), $this->request->get('pins', []))
        ]);
    }

    /**
     * Find a subscriber by id
     *
     * @return \WP_REST_Response $object
     */
    public function show()
    {
        // Accept with[]=a&with[]=b or a comma-separated string.
        $with = $this->request->get('with', []);
        $with = is_array($with) ? $with : array_filter(array_map('trim', explode(',', (string)$with)));

        $contactId = $this->request->get('id');

        $defaultWith = ['tags', 'lists'];

        if (Helper::isCompanyEnabled()) {
            $defaultWith[] = 'companies';
        }

        $subscriber = false;
        if ($contactId) {
            $subscriber = Subscriber::with($defaultWith)->find($contactId);
        } else if ($byEmail = $this->request->get('get_by_email')) {
            $subscriber = Subscriber::with($defaultWith)->where('email', sanitize_email($byEmail))->first();
        }

        if (!$subscriber) {
            return $this->sendError([
                'message' => __('Contact not found', 'fluent-crm')
            ]);
        }

        if (in_array('commerce_stat', $with)) {
            $subscriber->commerce_stat = [];
            /**
             * Determine the commerce provider for FluentCRM.
             *
             * This filter allows you to modify the commerce provider used in FluentCRM.
             *
             * @param string The current commerce provider.
             * @since 2.5.1
             *
             */
            $commerceProvider = apply_filters('fluentcrm_commerce_provider', '');
            if ($commerceProvider) {
                /**
                 * Determine the purchase statistics for a specific subscriber and commerce provider.
                 *
                 * This filter allows modification of the purchase statistics for a given subscriber
                 * based on the specified commerce provider.
                 *
                 * @param array  The current purchase statistics for the subscriber.
                 * @param int $subscriber_id The ID of the subscriber.
                 *
                 * @return array Modified purchase statistics for the subscriber.
                 * @since 2.7.0
                 *
                 */
                $subscriber->commerce_stat = apply_filters('fluent_crm/contact_purchase_stat_' . $commerceProvider, [], $subscriber->id);
            }
        }

        if ($wpUser = $subscriber->getWpUser()) {
            $subscriber->user_edit_url = get_edit_user_link($wpUser->ID);
            $subscriber->user_roles = array_values($wpUser->roles);
        }

        if (in_array('stats', $with)) {
            $subscriber->stats = $subscriber->stats();
        }

        if (in_array('subscriber.custom_values', $with)) {
            $subscriber->custom_values = (object)$subscriber->custom_fields();
        }

        if ($subscriber->date_of_birth == '0000-00-00' || empty($subscriber->date_of_birth)) {
            $subscriber->date_of_birth = '';
        }

        if ($subscriber->status == 'unsubscribed') {
            $subscriber->unsubscribe_reason = $subscriber->unsubscribeReason();
            $subscriber->unsubscribe_date = $subscriber->unsubscribeReasonDate();
        } else if ($subscriber->status == 'bounced' || $subscriber->status == 'complained') {
            $subscriber->unsubscribe_reason = $subscriber->unsubscribeReason('reason');
            $subscriber->unsubscribe_date = $subscriber->unsubscribeReasonDate('reason');
        }

        $data = [
            'subscriber' => $subscriber
        ];

        if (in_array('custom_fields', $with)) {
            $data['custom_fields'] = fluentcrm_get_option('contact_custom_fields', []);
        }


        return $this->sendSuccess($data);
    }

    public function updateProperty()
    {
        $column = $this->request->getSafe('property', 'sanitize_text_field');
        $value = $this->request->getSafe('value', 'sanitize_text_field');
        
        $subscriberIds = $this->request->get('subscribers');
        
        if (!is_array($subscriberIds)) {
            $subscriberIds = [$subscriberIds];
        }

        $subscriberIds = array_map('intval', $subscriberIds);
        $subscriberIds = array_unique(array_filter($subscriberIds));

        $validColumns = ['status', 'contact_type', 'avatar', 'company_id'];
        $this->validate([
            'column'         => $column,
            'subscriber_ids' => $subscriberIds
        ], [
            'column'         => 'required',
            'subscriber_ids' => 'required'
        ]);

        if (!in_array($column, $validColumns)) {
            return $this->sendError([
                'message' => __('Column is not valid', 'fluent-crm')
            ]);
        }

        if ($column == 'status' || $column == 'contact_type') {
            $value = $column == 'status' ? Sanitize::contactStatus($value) : Sanitize::contactType($value);
            if ($value === null) {
                return $this->sendError([
                    'message' => __('Value is not valid', 'fluent-crm')
                ]);
            }
        } else if ($column == 'company_id') {
            Company::findOrFail($value);
        }

        $subscribers = Subscriber::whereIn('id', $subscriberIds)->get();

        foreach ($subscribers as $subscriber) {
            $oldValue = $subscriber->{$column};
            if ($oldValue != $value) {
                $subscriber->{$column} = $value;
                $subscriber->save();
                if (in_array($column, ['status', 'contact_type'])) {
                    do_action('fluentcrm_subscriber_' . $column . '_to_' . $value, $subscriber, $oldValue);

                    if ($column == 'status') {
                        /**
                         * Contact's Status has been changed
                         *
                         * @param Subscriber $subscriber Subscriber Model.
                         * @param string $oldStatus Old Status.
                         * @since 1.0
                         *
                         */
                        do_action('fluent_crm/subscriber_status_changed', $subscriber, $oldValue, $value);
                    }

                }
                if ($column == 'avatar') {
                    do_action('fluent_crm/subscriber_avatar_update', $subscriber, $oldValue);
                }
            }
        }

        return $this->sendSuccess([
            'message' => __('The contacts have been updated.', 'fluent-crm')
        ]);
    }

    public function deleteSubscriber(Request $request, $id)
    {
        $subscriber = Subscriber::findOrFail($id);

        Helper::deleteContacts([$subscriber->id]);

        return $this->sendSuccess([
            'message' => __('The selected contact has been deleted', 'fluent-crm')
        ]);
    }

    public function deleteSubscribers(Request $request)
    {
        $subscriberIds = array_values(array_unique(array_filter(array_map('intval', (array)$request->get('subscribers')))));

        $this->validate(
            ['subscriber_ids' => $subscriberIds],
            ['subscriber_ids' => 'required']
        );

        Helper::deleteContacts($subscriberIds);

        return $this->sendSuccess([
            'message' => __('Selected contacts have been deleted', 'fluent-crm')
        ]);
    }

    /**
     * Tag a subscriber with Tags or Lists.
     */
    public function tagger()
    {
        $model = $this->resolveModel();

        $subscribers = array_filter(array_map('intval', (array)$this->request->get('subscribers', [])));
        if (!$subscribers) {
            return $this->sendError([
                'message' => __('Please select contacts first', 'fluent-crm')
            ], 422);
        }
        $attachments = $this->attachments($model, 'attach');
        $detachments = $this->attachments($model, 'detach');

        $type = $this->request->get('type');

        foreach ($subscribers as $subscriberId) {
            $subscriber = Subscriber::find($subscriberId);

            if (!$subscriber) {
                continue;
            }

            if ($attachments) {
                if ($type == 'tags') {
                    $subscriber->attachTags($attachments);
                } else {
                    $subscriber->attachLists($attachments);
                }
            }

            if ($detachments) {
                if ($type == 'tags') {
                    $subscriber->detachTags($detachments);
                } else {
                    $subscriber->detachLists($detachments);
                }
            }
        }

        return $this->sendSuccess([
            'message'     => _n('The contact has been updated.', 'The contacts have been updated.', count($subscribers), 'fluent-crm'),
            'subscribers' => Subscriber::with('tags', 'lists')->whereIn('id', $subscribers)->get()
        ]);
    }

    /**
     * Store a subscriber.
     *
     * @return array
     */
    public function store(Request $request)
    {
        $forceUpdate = $request->get('__force_update') == 'yes';

        if (!$forceUpdate) {
            $data = $this->validate($request->all(), [
                'email'  => 'required|email|unique:fc_subscribers',
                'status' => 'required'
            ], [
                'email.unique' => __('This email address is already assigned to another contact.', 'fluent-crm')
            ]);
        } else {
            $data = $this->validate($request->all(), [
                'email'  => 'required|email',
                'status' => 'required'
            ]);
        }

        unset($data['__force_update']);

        $data = Sanitize::contact($data);

        $user = get_user_by('email', $data['email']);

        if ($user) {
            $data['user_id'] = $user->ID;
        } else {
            $data['user_id'] = '';
        }

        if ($this->isNew()) {
            $data['created_at'] = current_time('mysql');

            $contact = FluentCrmApi('contacts')->createOrUpdate($data, false, false);

            $double_optin = filter_var($request->get('double_optin'), FILTER_VALIDATE_BOOLEAN);

            if ($double_optin && $contact->status == 'pending') {
                $contact->sendDoubleOptinEmail();
            }

            return [
                'message'     => __('The contact has been added.', 'fluent-crm'),
                'contact'     => $contact,
                'action_type' => 'created'
            ];

        } else if ($forceUpdate) {
            // Force the status only when the existing contact is not subscribed, so a
            // confirmed update can lift suppression but never demote a subscriber to 'pending'.
            $existingContact = Subscriber::where('email', $data['email'])->first();
            $shouldForce = $existingContact && $existingContact->status != 'subscribed';

            $contact = FluentCrmApi('contacts')->createOrUpdate($data, $shouldForce, false);

            $double_optin = filter_var($request->get('double_optin'), FILTER_VALIDATE_BOOLEAN);

            if ($double_optin && $contact && $contact->status == 'pending') {
                $contact->sendDoubleOptinEmail();
            }

            return $this->sendSuccess([
                'message'     => __('The contact has been updated.', 'fluent-crm'),
                'contact'     => $contact,
                'action_type' => 'updated'
            ]);
        }

        return $this->sendError([
            'message' => __('Sorry, contact already exists', 'fluent-crm')
        ], 422);
    }

    public function bulkAddUpdate(Request $request)
    {
        $contacts = Helper::parseArrayOrJson($request->get('contacts'));
        $invalids = [];
        $created = [];
        $updated = [];

        $double_optin = filter_var($request->get('double_optin'), FILTER_VALIDATE_BOOLEAN);
        $forceUpdate = filter_var($request->get('force_update'), FILTER_VALIDATE_BOOLEAN);

        foreach ($contacts as $contact) {
            $contactData = Sanitize::contact($contact);
            // Never accept a client user_id: updateOrCreate() trusts it and it links the contact to a WP user.
            unset($contactData['user_id']);
            if (empty($contactData['email']) || !is_email($contactData['email'])) {
                $invalids[] = $contactData;
                continue;
            }

            $contactData['tags'] = Arr::get($contact, 'tags', []);
            $contactData['lists'] = Arr::get($contact, 'lists', []);
            $createdContact = FluentCrmApi('contacts')->createOrUpdate($contactData, $forceUpdate, false);

            if (!$createdContact) {
                $invalids[] = $contactData;
                continue;
            }

            if ($double_optin && $createdContact->status == 'pending') {
                $createdContact->sendDoubleOptinEmail();
            }

            if ($createdContact->wasRecentlyCreated) {
                $created[] = [
                    'id'     => $createdContact->id,
                    'email'  => $createdContact->email,
                    'status' => $createdContact->status,
                ];
            } else {
                $updated[] = [
                    'id'     => $createdContact->id,
                    'email'  => $createdContact->email,
                    'status' => $createdContact->status,
                ];
            }
        }

        return [
            'message'  => __('Contacts have been added or updated.', 'fluent-crm'),
            'created'  => $created,
            'updated'  => $updated,
            'invalids' => $invalids
        ];
    }

    public function updateSubscriber(Request $request, $id)
    {
        $subscriber = Subscriber::findOrFail($id);
        $originalData = Helper::parseArrayOrJson($request->get('subscriber'));

        if (!$originalData) {
            $originalData = $request->all();
        }

        $data = [];
        if (isset($originalData['email'])) {
            $data = $this->validate($originalData, [
                'email' => 'required|email|unique:fc_subscribers,email,' . $id,
            ], [
                'email.unique' => __('This email address is already assigned to another contact.', 'fluent-crm')
            ]);
        } else {
            $data = $originalData;
        }

        // user_id is derived from the email only; a client value could link an admin's WP account.
        unset($data['user_id']);

        if (isset($data['email'])) {
            $user = get_user_by('email', $data['email']);
            /**
             * Determine whether to update the WordPress user email on change.
             *
             * This filter allows you to control whether the WordPress user email should be updated
             * when there is a change in the FluentCRM subscriber email.
             *
             * @param bool Whether to update the WordPress user email on change. Default false.
             * @since 2.9.25
             *
             */
            if (!$user && apply_filters('fluentcrm_update_wp_user_email_on_change', false)) {
                // Use the stored link, never request input.
                $user = get_user_by('ID', $subscriber->user_id);
            }

            $data['user_id'] = $user ? $user->ID : NULL;
        }

        if (!empty($data['user_id'])) {
            $data['user_id'] = (int)$data['user_id'];
        }

        if (isset($data['date_of_birth']) && empty($data['date_of_birth'])) {
            $data['date_of_birth'] = NULL;
        }

        $validData = Sanitize::contact($data);

        unset($validData['created_at']);
        unset($validData['last_activity']);
        $customValues = Arr::get($originalData, 'custom_values', []);

        $oldEmail = $subscriber->email;

        $oldSubscriber = clone $subscriber;

        // Status changes go through updateStatus() so the status-transition hooks fire.
        $requestedStatus = Arr::get($validData, 'status');
        unset($validData['status']);

        $requestedStatus = Sanitize::contactStatus($requestedStatus);
        if ($requestedStatus === $subscriber->status) {
            $requestedStatus = null;
        }

        $statusChangedEarly = false;

        // Suppressing status changes apply before the tag/list sync so tag-triggered automations see them.
        if ($requestedStatus && in_array($requestedStatus, fluentcrm_strict_statues())) {
            $subscriber->updateStatus($requestedStatus);
            $statusChangedEarly = true;
            $requestedStatus = null;
        }

        $subscriber->fill($validData);

        $dirtyFields = $subscriber->getDirty();

        if ($dirtyFields) {
            $subscriber->save();
        }

        if ($statusChangedEarly) {
            $dirtyFields['status'] = $subscriber->status;
        }

        if ($customValues) {
            $originalCustomFields = $subscriber->custom_fields();
            // syncCustomFieldValues() returns only the values that changed (removed ones as '').
            $dirtyCustomValues = $subscriber->syncCustomFieldValues($customValues, true);

            if ($dirtyCustomValues) {
                do_action('fluent_crm/contact_updated_with_changes', $subscriber, $dirtyCustomValues, $originalCustomFields, ['source' => 'web', 'type' => 'custom_fields_only']);
            }
        }

        if ($tags = Arr::get($originalData, 'attach_tags', [])) {
            $subscriber->attachTags($tags);
        }

        if ($lists = Arr::get($originalData, 'attach_lists', [])) {
            $subscriber->attachLists($lists);
        }

        if ($detachTags = Arr::get($originalData, 'detach_tags', [])) {
            $subscriber->detachTags($detachTags);
        }

        if ($detachLists = Arr::get($originalData, 'detach_lists', [])) {
            $subscriber->detachLists($detachLists);
        }

        // Non-suppressing status changes apply after the sync so listeners see the final tags/lists.
        if ($requestedStatus && $requestedStatus != $subscriber->status) {
            $subscriber->updateStatus($requestedStatus);
            $dirtyFields['status'] = $requestedStatus;
        }

        if ($dirtyFields) {

            if (isset($dirtyFields['email'])) {
                /**
                 * Contact's Email address has been updated
                 *
                 * @param Subscriber $subscriber Subscriber Model.
                 * @param string $oldEmail Old Email Address.
                 * @since 1.0
                 *
                 */
                do_action('fluent_crm/contact_email_changed', $subscriber, $oldEmail);
            }

            do_action('fluentcrm_contact_updated', $subscriber, $dirtyFields);
            do_action('fluent_crm/contact_updated', $subscriber, $dirtyFields);

            do_action('fluent_crm/contact_updated_with_changes', $subscriber, $dirtyFields, $oldSubscriber, ['source' => 'web', 'type' => 'all_fields']);

        }

        return $this->sendSuccess([
            'message' => __('The contact has been updated.', 'fluent-crm'),
            'contact' => $subscriber,
            'isDirty' => !!$dirtyFields,
            'values'  => $customValues
        ], 200);
    }

    /**
     * Resolve the appropriate model e.g. Tag or, Lists
     *
     * @return string
     */
    private function resolveModel()
    {
        $type = $this->request->type;

        return 'FluentCrm\App\Models\\' . ($type === 'tags' ? 'Tag' : 'Lists');
    }

    /**
     * Get the attachment options e.g. attach or, detach
     *
     * @param \FluentCrm\App\Models\Model $model
     * @param string $type
     * @return array
     */
    private function attachments($model, $type = 'attach')
    {
        $attachments = $this->request->get($type, []);
        $findBy = sanitize_text_field($this->request->get('find_by', 'slug'));

        if ($attachments) {
            $items = $model::select('id')->whereIn($findBy, $attachments)->get();

            if (!$items->isEmpty()) {
                return array_map(function ($item) {
                    return $item['id'];
                }, $items->toArray());
            }
        }

        return [];
    }

    /**
     * Handles if subscriber already exists.
     *
     * @return bool
     */
    private function isNew()
    {
        $subscriber = Subscriber::where(
            'email', $this->request->getSafe('email', 'sanitize_email', '')
        )->first();

        if ($subscriber) {
            return false;
        }

        return true;
    }

    public function emails(Request $request, $subscriberId)
    {
        $filter = sanitize_text_field(Arr::get($request->get(), 'filter'));

        $emailsQuery = CampaignEmail::where('subscriber_id', $subscriberId)
            ->orderBy('id', 'DESC');

        // Read-only contact users get metadata only: no bodies, headers or preview hashes.
        if (!PermissionManager::currentUserCan('fcrm_manage_contacts')) {
            $emailsQuery->select([
                'id', 'campaign_id', 'email_type', 'subscriber_id', 'email_subject_id',
                'email_address', 'email_subject', 'is_open', 'is_parsed', 'click_counter',
                'status', 'scheduled_at', 'created_at', 'updated_at',
            ]);
        }

        if ($filter == 'open') {
            $emailsQuery->where('is_open', '1');
        } elseif ($filter == 'click') {
            $emailsQuery->whereNotNull('click_counter');
        } elseif ($filter == 'unopened') {
            $emailsQuery->where('is_open', 0);
        }

        $emails = $emailsQuery->paginate();
        $tab = Arr::get($request->all(), 'tab', 'crm');

        if (defined('FLUENTMAIL_PLUGIN_FILE') && $tab == 'fluentsmtp') {
            $getLogsByCurrentUser = Subscriber::where('id', $subscriberId)->pluck('email')->first();

            $emails = [];

            if (!empty($getLogsByCurrentUser)) {
                global $wpdb;
                // `to` is serialized; matching the quoted address keeps the match exact.
                $emails = fluentMailDb()->table(FLUENT_MAIL_DB_PREFIX . 'email_logs')
                    ->where('to', 'LIKE', '%"' . $wpdb->esc_like($getLogsByCurrentUser) . '"%')
                    ->orderBy('id', 'DESC')
                    ->paginate();

                $emails['data'] = $this->formatResult($emails['data']);
            }
        }

        /**
         * Determine and retrieve emails for a subscriber.
         *
         * This filter allows modifying the list of emails associated with a subscriber.
         *
         * @param array {
         *     Array containing the email data.
         *
         * @type array $emails List of email records or paginated results.
         * }
         * @param int $subscriberId The ID of the subscriber.
         *
         * @return array Filtered email data for the subscriber.
         * @since 1.0.0
         *
         */
        return apply_filters('fluentcrm_contact_emails', [
            'emails' => $emails
        ], $subscriberId);
    }

    protected function formatResult($result)
    {
        $result = is_array($result) ? $result : func_get_args();

        // Only manage_options users see unredacted bodies; edit_users is not enough on multisite.
        $redactSecrets = !current_user_can('manage_options');

        foreach ($result as $key => $row) {
            $result[$key] = array_map('maybe_unserialize', (array)$row);
            $result[$key]['id'] = (int)$result[$key]['id'];
            $result[$key]['retries'] = (int)$result[$key]['retries'];
            $result[$key]['from'] = htmlspecialchars($result[$key]['from']);
            $result[$key]['subject'] = wp_kses_post(wp_unslash($result[$key]['subject']));

            if ($redactSecrets && isset($result[$key]['body']) && $this->emailLogBodyHasSensitiveData($result[$key]['body'])) {
                $result[$key]['body'] = $this->getRedactedEmailBodyNotice();
                $result[$key]['is_redacted'] = true;
            }
        }

        return $result;
    }

    /**
     * Whether an email-log body contains secrets such as password-reset links.
     *
     * @param string $body Rendered email HTML/text.
     * @return bool
     */
    protected function emailLogBodyHasSensitiveData($body)
    {
        if (!$body || !is_string($body)) {
            return false;
        }

        $patterns = [
            '/wp-login\.php\?action=(rp|resetpass)/i',
            '/[?&]action=rp&(amp;)?key=/i',
            '/[?&]key=[^&"\'\s]+&(amp;)?login=/i',
        ];

        /**
         * Filter the regex patterns used to detect sensitive content in email-log bodies.
         *
         * @param array  $patterns Array of PCRE patterns.
         * @param string $body     The rendered email body being inspected.
         * @since 3.1.9
         */
        $patterns = apply_filters('fluent_crm/email_log_sensitive_patterns', $patterns, $body);

        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The placeholder shown in place of a redacted email-log body.
     *
     * @return string
     */
    protected function getRedactedEmailBodyNotice()
    {
        return '<div class="fc_redacted_email_body" style="padding:16px;border:1px solid #E1E4EA;border-radius:6px;background:#F5F7FA;color:#525866;">'
            . esc_html__('This email contains sensitive information (such as a password reset or login link) and its content has been hidden for security reasons.', 'fluent-crm')
            . '</div>';
    }

    public function deleteEmails(Request $request, $subscriberId)
    {
        $emailIds = array_filter(array_map('intval', (array)$request->get('email_ids', [])));
        if (!$emailIds) {
            return $this->sendError([
                'message' => __('Please select emails first', 'fluent-crm')
            ], 422);
        }
        CampaignEmail::where('subscriber_id', $subscriberId)
            ->whereIn('id', $emailIds)
            ->delete();
        return [
            'message' => __('Selected emails have been deleted', 'fluent-crm')
        ];
    }

    public function getNotes()
    {
        $subscriberId = $this->request->get('id');
        $search = $this->request->get('search');
        $includeId = intval($this->request->get('include_id', 0));

        $notes = SubscriberNote::where('subscriber_id', $subscriberId);

        if (!empty($search)) {
            global $wpdb;
            $notes = $notes->where('title', 'LIKE', '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%');
        }

        $notes = $notes->orderBy('id', 'DESC')
            ->paginate();

        foreach ($notes as $note) {
            $note->added_by = $note->createdBy();
        }
        $fields['fields'] = Helper::getNoteSyncFields();

        $response = [
            'notes'  => $notes,
            'fields' => $fields
        ];

        if ($includeId) {
            $noteIds = (new Collection($notes->items()))->pluck('id')->toArray();
            if (!in_array($includeId, $noteIds)) {
                $includedNote = SubscriberNote::where('id', $includeId)
                    ->where('subscriber_id', $subscriberId)
                    ->first();
                if ($includedNote) {
                    $includedNote->added_by = $includedNote->createdBy();
                    $response['included_note'] = $includedNote;
                }
            }
        }

        return $this->sendSuccess($response);
    }

    public function addNote(Request $request, $id)
    {
        $subscriber = Subscriber::findOrFail($id);

        $note = $this->validate($request->get('note'), [
            'title'       => 'required',
            'description' => 'required',
            'type'        => 'required',
            'created_at'  => 'nullable|date'
        ]);

        if (empty($note['created_at'])) {
            $note['created_at'] = current_time('mysql');
        }

        /**
         * Parse the Subscriber's Note Description.
         *
         * @param string $note ['description'] The subscriber's note description.
         * @param object $subscriber The subscriber object.
         * @since 3.2.6
         *
         */
        $note['description'] = apply_filters('fluent_crm/parse_contact_note_text', $note['description'], $subscriber);

        $note['subscriber_id'] = $id;

        $note = Sanitize::contactNote($note);

        // Authorship is always the current user; parent_id and status are not client-settable.
        $note = Arr::only($note, ['subscriber_id', 'title', 'description', 'type', 'created_at']);
        $note['created_by'] = get_current_user_id();

        $subscriberNote = SubscriberNote::create(wp_unslash($note));

        /**
         * Subscriber's Note Added
         *
         * @param SubscriberNote $subscriberNote Note Model.
         * @param Subscriber $subscriber Contact Model.
         * @param array $note Contact Note Data Array.
         * @since 1.0
         */
        do_action('fluent_crm/note_added', $subscriberNote, $subscriber, $note);

        return $this->sendSuccess([
            'note'    => $subscriberNote,
            'message' => __('The note has been added.', 'fluent-crm')
        ]);
    }

    public function updateNote(Request $request, $id, $noteId)
    {
        $subscriber = Subscriber::findOrFail($id);

        $note = $this->validate($request->get('note'), [
            'title'       => 'required',
            'description' => 'required',
            'type'        => 'required',
            'created_at'  => 'sometimes|date'
        ]);

        $note = Arr::only(wp_unslash($note), ['title', 'description', 'type', 'created_at']);

        if (empty($note['created_at'])) {
            unset($note['created_at']);
        }

        /**
         * Parse the updated Subscriber's Note Description.
         *
         * @param string $note ['description'] Subscriber's Note Description.
         * @param object $subscriber The subscriber object data.
         * @since 3.2.6
         *
         */
        $note['description'] = apply_filters('fluent_crm/parse_contact_note_text', $note['description'], $subscriber);

        $note = Sanitize::contactNote($note);

        // Scoped to this contact so another contact's note id is rejected.
        $subsciberNote = SubscriberNote::where('id', $noteId)
            ->where('subscriber_id', $subscriber->id)
            ->firstOrFail();
        $subsciberNote->fill($note);
        $subsciberNote->save();

        /**
         * Subscriber's Note Updated
         *
         * @param SubscriberNote $subscriberNote Note Model.
         * @param Subscriber $subscriber Contact Model.
         * @param array $note Contact Note Data Array.
         * @since 1.0
         */
        do_action('fluent_crm/note_updated', $subsciberNote, $subscriber, $note);

        return $this->sendSuccess([
            'note'    => $subsciberNote,
            'message' => __('The note has been updated.', 'fluent-crm')
        ]);
    }

    public function deleteNote($id, $noteId)
    {
        $subscriber = Subscriber::findOrFail($id);
        // Scoped to this contact so another contact's note id is rejected.
        $deleted = SubscriberNote::where('id', $noteId)
            ->where('subscriber_id', $subscriber->id)
            ->delete();

        if ($deleted) {
            /**
             * Subscriber's Note Delete
             *
             * @param SubscriberNote $subscriberNote Note Model.
             * @param Subscriber $subscriber Contact Model.
             * @since 1.0
             */
            do_action('fluent_crm/note_delete', $noteId, $subscriber);
        }

        return $this->sendSuccess([
            'message' => __('The note has been deleted.', 'fluent-crm')
        ]);
    }

    public function bulkDeleteNotes(Request $request, $id)
    {
        $subscriber = Subscriber::findOrFail($id);
        $noteIds = array_filter(array_map('intval', (array) $request->get('note_ids', [])));

        if (empty($noteIds)) {
            return $this->sendError([
                'message' => __('No note IDs provided', 'fluent-crm')
            ]);
        }

        if (count($noteIds) > 200) {
            return $this->sendError([
                'message' => __('Too many notes selected. Please delete 200 or fewer notes at a time.', 'fluent-crm')
            ]);
        }

        $deletableNoteIds = SubscriberNote::where('subscriber_id', $subscriber->id)
            ->whereIn('id', $noteIds)
            ->pluck('id')
            ->toArray();

        $deletedCount = 0;
        if ($deletableNoteIds) {
            $deletedCount = SubscriberNote::whereIn('id', $deletableNoteIds)->delete();

            foreach ($deletableNoteIds as $deletedNoteId) {
                do_action('fluent_crm/note_delete', $deletedNoteId, $subscriber);
            }
        }

        return $this->sendSuccess([
            'message' => sprintf(
                /* translators: %d: number of deleted notes */
                _n('%d note deleted', '%d notes deleted', $deletedCount, 'fluent-crm'),
                $deletedCount
            )
        ]);
    }

    public function getFormSubmissions()
    {
        $provider = $this->request->get('provider');
        $subscriberId = intval($this->request->get('id'));
        $subscriber = Subscriber::findOrFail($subscriberId);

        /**
         * Filter the form submissions data for a specific provider.
         *
         * The dynamic portion of the hook name, `$provider`, refers to the form provider.
         *
         * @param array {
         *     An array of form submissions data.
         *
         * @type array $data The form submissions data.
         * @type int $total The total number of form submissions.
         * }
         * @param object $subscriber The subscriber object.
         * @since 2.5.1
         *
         * Security: the returned column values are rendered as raw HTML in the admin UI
         * (Vue v-html) without client-side sanitization. Producers hooking this filter MUST
         * escape any subscriber-authored data (e.g. via esc_html() / wp_kses_post()) before
         * returning it, to prevent stored XSS in the admin. Structural markup and intentional
         * rich content are allowed by design.
         */
        $data = apply_filters('fluentcrm_get_form_submissions_' . $provider, [
            'data'  => [],
            'total' => 0
        ], $subscriber);

        return $this->sendSuccess([
            'submissions' => $data
        ]);
    }

    public function getSupportTickets()
    {
        $provider = $this->request->get('provider');
        $subscriberId = intval($this->request->get('id'));
        $subscriber = Subscriber::where('id', $subscriberId)->first();

        /**
         * Determine the support tickets data for a specific provider and subscriber.
         *
         * The dynamic portion of the hook name, `$provider`, refers to the support ticket provider.
         *
         * @param array {
         *     An array of support tickets data.
         *
         * @type array $data The support tickets data.
         * @type int $total The total number of support tickets.
         * }
         * @param object $subscriber The subscriber object.
         * @since 2.5.1
         *
         * Security: the returned column values are rendered as raw HTML in the admin UI
         * (Vue v-html) without client-side sanitization. Producers hooking this filter MUST
         * escape any subscriber-authored data (e.g. via esc_html() / wp_kses_post()) before
         * returning it, to prevent stored XSS in the admin. Structural markup and intentional
         * rich content are allowed by design.
         */
        $data = apply_filters('fluentcrm-get_support_tickets_' . $provider, [
            'data'  => [],
            'total' => 0
        ], $subscriber);

        $data['columns_config'] = [
            'id'           => [
                'label' => __('ID', 'fluent-crm'),
                'width' => '100px'
            ],
            'status'       => [
                'label' => 'Status',
                'width' => '120px'
            ],
            'Submitted at' => [
                'label' => 'Submitted at',
                'width' => '150px'
            ],
            'action'       => [
                'label' => 'Action',
                'width' => '150px'
            ]
        ];

        return $this->sendSuccess([
            'tickets' => $data
        ]);
    }

    public function sendDoubleOptinEmail(Request $request, $id)
    {
        $subscriber = Subscriber::findOrFail($id);

        if ($subscriber->status == 'subscribed') {
            return $this->sendError([
                'message' => __('Contact is already subscribed', 'fluent-crm')
            ]);
        }

        // Status stays unchanged until the contact clicks the confirmation link.
        if (!$subscriber->sendDoubleOptinEmail()) {
            return $this->sendError([
                'message' => __('Double opt-in email could not be sent. Please check the double opt-in settings; resends are also throttled for a couple of minutes.', 'fluent-crm')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('The double opt-in email has been sent.', 'fluent-crm')
        ]);
    }

    public function getTemplateMock(Request $request, $id)
    {
        $emailMock = CustomEmailCampaign::getMock();
        $emailMock['title'] = __('Custom Email to Contact', 'fluent-crm');
        return [
            'email_mock' => $emailMock
        ];
    }

    public function sendCustomEmail(Request $request, $contactId)
    {
        $contact = Subscriber::findOrFail($contactId);

        $validStatuses = ['subscribed', 'transactional'];

        if (!in_array($contact->status, $validStatuses)) {
            return $this->sendError([
                'message' => __('The contact\'s status needs to be Subscribed.', 'fluent-crm')
            ]);
        }

        add_action('wp_mail_failed', function ($wpError) {
            Helper::debugLog(
                'Custom Email failed',
                $wpError->get_error_message(),
                'error'
            );
        }, 10, 1);

        $newCampaign = $request->get('campaign');
        // An email cannot be recalled: refuse to send one without a subject or body.
        if (!is_array($newCampaign) || !trim((string)Arr::get($newCampaign, 'email_subject')) || !trim(strip_tags((string)Arr::get($newCampaign, 'email_body'), '<img><video><iframe>'))) {
            return $this->sendError([
                'message' => __('Please provide an email subject and body', 'fluent-crm')
            ], 422);
        }
        unset($newCampaign['id']);

        $newCampaign = Sanitize::campaign($newCampaign);

        // Title and status are set server side, matching the MCP send-email-to-contact tool.
        $newCampaign['title'] = Helper::oneOffEmailTitle($contact->email);
        $newCampaign['status'] = 'published';

        $campaign = CustomEmailCampaign::create($newCampaign);

        $campaign->subscribe([$contactId], [
            'status'       => 'scheduled',
            'scheduled_at' => current_time('mysql')
        ]);

        do_action('fluentcrm_process_contact_jobs', $contact);

        return [
            'message' => __('The custom email has been sent.', 'fluent-crm')
        ];
    }

    public function getExternalView(Request $request, $subscriberId)
    {
        $subscriber = Subscriber::findOrFail($subscriberId);
        $sectionId = $request->get('section_provider');

        $content = apply_filters_deprecated('fluencrm_profile_section_' . $sectionId, [[
            'heading'      => '',
            'content_html' => ''
        ], $subscriber], '3.2.1', 'fluent_crm/profile_section_' . $sectionId);

        /**
         * Filter the profile section content for a specific section ID.
         *
         * The dynamic portion of the hook name, `$sectionId`, refers to the ID of the profile section.
         *
         * @param array {
         *     An array of profile section data.
         *
         * @type string $heading The heading of the profile section.
         * @type string $content_html The HTML content of the profile section.
         * }
         * @param object $subscriber The subscriber object.
         * @since 3.2.1 Replaces the deprecated fluencrm_profile_section_{$sectionId} hook.
         *
         * Security: `content_html` is rendered in the admin UI through the shared
         * sanitizer ($sanitize, resources/admin/Bits/sanitizeHtml.js): scripts, event
         * handlers, javascript: URLs, iframes, form controls, <style> blocks and
         * fixed/absolute-positioned inline styles are stripped; structural markup,
         * classes, inline colours and target="_blank" links are kept. Producers MUST
         * still escape subscriber-authored data (esc_html() / wp_kses_post()), and put
         * any CSS they need in an enqueued stylesheet rather than an inline <style>.
         */
        return apply_filters('fluent_crm/profile_section_' . $sectionId, $content, $subscriber);
    }

    public function saveExternalViewData(Request $request, $subscriberId)
    {
        $subscriber = Subscriber::findOrFail($subscriberId);
        $sectionId = $request->get('section_provider');

        $data = $request->get('data', []);
        $response = apply_filters_deprecated('fluencrm_profile_section_save_' . $sectionId, [
            '', $data, $subscriber
        ], '3.2.1', 'fluent_crm/profile_section_save_' . $sectionId);

        /**
         * Filter the data being saved for a specific profile section.
         *
         * This filter allows modifying the data before saving it for a profile section
         * identified by the `$sectionId`.
         *
         * @param mixed  The data to be saved for the profile section. Defaults to an empty string.
         * @param array  The input data received from the request. Defaults to an empty array.
         * @param object $subscriber The subscriber object for which the profile section is being updated.
         *
         * @return mixed Filtered data to be saved for the profile section.
         * @since 3.2.1 Replaces the deprecated fluencrm_profile_section_save_{$sectionId} hook.
         *
         */
        $response = apply_filters('fluent_crm/profile_section_save_' . $sectionId, $response, $data, $subscriber);

        if (!$response) {
            return $this->sendError([
                'message' => __('Handler could not be found.', 'fluent-crm')
            ]);
        }

        return $response;
    }

    public function handleBulkActions(Request $request)
    {
        $actionName = sanitize_text_field($request->get('action_name', ''));
        $doingAllBulk = $request->get('is_all') == 'yes';

        if ($doingAllBulk) {

            // Same filters as the list, batched by ascending id.
            $contactQuery = $request->get('contact_query', []);
            $queryArgs = ContactsQuery::argsFromRequest(is_array($contactQuery) ? $contactQuery : []);

            if ($queryArgs['filter_type'] == 'advanced' && (!$queryArgs['filters_groups_raw'] || !is_array($queryArgs['filters_groups_raw']))) {
                return $this->sendError([
                    'message' => __('Invalid Advanced Filters', 'fluent-crm')
                ]);
            }

            $queryArgs = array_merge($queryArgs, [
                'with'          => [],
                'sort_by'       => 'id',
                'sort_type'     => 'ASC',
                'has_commerce'  => false,
                'custom_fields' => false,
            ]);

            if ($actionName == 'send_double_optin' && $request->get('pending_only') == 'yes') {
                $queryArgs['contact_status'] = 'pending';
            }

            $lastId = (int)$request->get('last_id', 0);
            $maxContactId = (int)$request->get('max_contact_id', 0);

            // Select-all delete: refuse if more contacts match than the user confirmed, and
            // fix an id ceiling (max_contact_id) so contacts created mid-run are never deleted.
            $expectedTotal = $request->get('expected_total');
            if ($actionName == 'delete_contacts' && !$lastId && is_numeric($expectedTotal)) {
                // Count and ceiling come from one query so they cannot disagree.
                $bounds = (new ContactsQuery($queryArgs))->getModel()
                    ->reorder()
                    ->selectRaw('COUNT(*) AS matching, MAX(id) AS max_id')
                    ->first();
                $matching = $bounds ? (int)$bounds->matching : 0;
                if ($matching === 0) {
                    return [
                        'is_completed'       => true,
                        'completed_contacts' => 0,
                        'message'            => __('All contacts have been processed', 'fluent-crm')
                    ];
                }
                if ($matching > (int)$expectedTotal) {
                    return $this->sendError([
                        'message'        => sprintf(
                            /* translators: 1: contacts matching now, 2: contacts the user confirmed */
                            __('The list changed: %1$d contacts match now, but %2$d were confirmed. Nothing was changed. Review the list and try again.', 'fluent-crm'),
                            $matching,
                            (int)$expectedTotal
                        ),
                        'matching_total' => $matching
                    ], 409);
                }
                $maxContactId = $bounds ? (int)$bounds->max_id : 0;
            }

            $subscribersModel = (new ContactsQuery($queryArgs))->getModel();
            if ($maxContactId) {
                $subscribersModel->where('id', '<=', $maxContactId);
            }
            $bulkActionLimit = (int)apply_filters('fluent_crm/contact_bulk_action_limit', 400, $request);
            if ($bulkActionLimit < 1) {
                $bulkActionLimit = 1;
            }

            $subscribersModel = $subscribersModel->select(['id'])
                ->limit($bulkActionLimit)
                ->where('id', '>', $lastId)
                ->get();

            if ($subscribersModel->isEmpty()) {
                return [
                    'is_completed'       => true,
                    'completed_contacts' => 0,
                    'message'            => __('All contacts have been processed', 'fluent-crm')
                ];
            }

            $subscriberIds = $subscribersModel->pluck('id')->toArray();

        } else {
            $subscriberIds = array_map('intval', $request->get('subscriber_ids', []));
            $subscriberIds = array_filter($subscriberIds);
        }

        $lastContactId = end($subscriberIds);

        if (!$subscriberIds) {
            return $this->sendError([
                'message' => __('Contact selection is required', 'fluent-crm')
            ]);
        }

        if ($actionName == 'delete_contacts') {
            Helper::deleteContacts($subscriberIds);
            $result = [
                'completed_contacts' => count($subscriberIds),
                'last_contact_id'    => $lastContactId,
                'message'            => __('Selected contacts have been permanently deleted', 'fluent-crm'),
            ];
            if (!empty($maxContactId)) {
                $result['max_contact_id'] = $maxContactId;
            }
            return $this->sendSuccess($result);
        } elseif ($actionName == 'send_double_optin') {
            // The batch cursor was taken before this narrowing, so skipped contacts still advance it.
            if ($request->get('pending_only') == 'yes') {
                $subscriberIds = Subscriber::whereIn('id', $subscriberIds)
                    ->where('status', 'pending')
                    ->pluck('id')
                    ->toArray();
            }
            if ($subscriberIds) {
                Helper::sendDoubleOptin($subscriberIds);
            }
            return $this->sendSuccess([
                'last_contact_id'    => $lastContactId,
                'completed_contacts' => count($subscriberIds),
                'message'            => __('The double opt-in email has been sent to the selected contacts who are not subscribed yet.', 'fluent-crm'),
            ]);
        } elseif ($actionName == 'add_to_email_sequence') {
            if (!defined('FLUENTCAMPAIGN')) {
                return $this->sendError([
                    'message' => __('This action requires FluentCRM Pro', 'fluent-crm')
                ]);
            }

            $sequenceId = (int)$request->get('new_status', '');

            if (!$sequenceId) {
                return $this->sendError([
                    'message' => __('Invalid Email Sequence ID', 'fluent-crm')
                ]);
            }

            $sequence = \FluentCampaign\App\Models\Sequence::findOrFail($sequenceId);

            $validSubscribers = Subscriber::whereIn('id', $subscriberIds)
                ->whereDoesntHave('sequences', function ($q) use ($sequenceId) {
                    $q->where('fc_campaigns.id', $sequenceId);
                })
                ->where('status', 'subscribed')
                ->get();

            if ($validSubscribers->isEmpty()) {
                if ($doingAllBulk) {
                    return $this->sendSuccess([
                        'last_contact_id'    => $lastContactId,
                        'completed_contacts' => count($subscriberIds),
                        'message'            => __('No valid active contacts found in this batch', 'fluent-crm')
                    ]);
                }
                return $this->sendError([
                    'message' => __('No valid active contacts found for this sequence', 'fluent-crm')
                ]);
            }

            $sequence->subscribe($validSubscribers);
            return [
                'last_contact_id'    => $lastContactId,
                'completed_contacts' => count($subscriberIds),
                /* translators: 1. Number of subscribers */
                'message'            => sprintf(__('%d contacts have been added to the selected email sequence', 'fluent-crm'), count($validSubscribers))
            ];

        } elseif ($actionName == 'add_to_company') {
            $companyId = (int)$request->get('new_status', '');

            if (!$companyId) {
                return $this->sendError([
                    'message' => __('Invalid Company ID', 'fluent-crm')
                ]);
            }

            $company = Company::findOrFail($companyId);

            $validSubscribers = Subscriber::whereIn('id', $subscriberIds)
                ->whereDoesntHave('companies', function ($q) use ($companyId) {
                    $q->where('fc_companies.id', $companyId);
                })
                ->get();

            if ($validSubscribers->isEmpty()) {

                if ($doingAllBulk) {
                    return $this->sendSuccess([
                        'last_contact_id'    => $lastContactId,
                        'completed_contacts' => count($subscriberIds),
                        'message'            => __('No valid active contacts found in this batch', 'fluent-crm')
                    ]);
                }

                return $this->sendError([
                    'message' => __('No valid active contacts found for this company', 'fluent-crm')
                ]);
            }

            foreach ($validSubscribers as $contact) {
                if ($contact) {
                    $contact->attachCompanies([$company->id]);
                    if (!$contact->company_id) {
                        $contact->company_id = $company->id;
                        $contact->save();
                    }
                }
            }
            return [
                'last_contact_id'    => $lastContactId,
                'completed_contacts' => count($subscriberIds),
                /* translators: %d is the number of subscribers */
                'message'            => sprintf(__('%d contacts have been attached to the selected company', 'fluent-crm'), count($validSubscribers))
            ];

        } elseif ($actionName == 'remove_from_company') {
            $companyId = (int)$request->get('new_status', '');

            if (!$companyId) {
                return $this->sendError([
                    'message' => __('Invalid Company ID', 'fluent-crm')
                ]);
            }

            $company = Company::findOrFail($companyId);

            $validSubscribers = Subscriber::whereIn('id', $subscriberIds)
                ->whereHas('companies', function ($q) use ($companyId) {
                    $q->where('fc_companies.id', $companyId);
                })
                ->get();

            if ($validSubscribers->isEmpty()) {

                if ($doingAllBulk) {
                    return $this->sendSuccess([
                        'last_contact_id'    => $lastContactId,
                        'completed_contacts' => count($subscriberIds),
                        'message'            => __('No valid active contacts found in this batch', 'fluent-crm')
                    ]);
                }

                return $this->sendError([
                    'message' => __('No valid active contacts found for this company', 'fluent-crm')
                ]);
            }

            foreach ($validSubscribers as $contact) {
                if ($contact) {
                    $contact->detachCompanies([$company->id]);
                    if ($contact->company_id == $company->id) {
                        $contact->company_id = null;
                        $contact->save();
                    }
                }
            }
            return [
                'last_contact_id'    => $lastContactId,
                'completed_contacts' => count($subscriberIds),
                /* translators: %d is the number of subscribers */
                'message'            => sprintf(__('%d contacts have been detached from the selected company', 'fluent-crm'), count($validSubscribers))
            ];

        } elseif ($actionName == 'change_contact_status') {
            $newStatus = Sanitize::contactStatus($request->get('new_status'));
            if (!$newStatus) {
                return $this->sendError([
                    'message' => __('Please select a status', 'fluent-crm')
                ]);
            }

            $subscribers = Subscriber::whereIn('id', $subscriberIds)->get();

            foreach ($subscribers as $subscriber) {
                $oldStatus = $subscriber->status;
                if ($oldStatus != $newStatus) {
                    $subscriber->updateStatus($newStatus);
                }
            }

            return [
                'last_contact_id'    => $lastContactId,
                'completed_contacts' => count($subscriberIds),
                'message'            => __('Status has been changed for the selected contacts', 'fluent-crm')
            ];
        } elseif ($actionName == 'add_to_automation') {
            if (!defined('FLUENTCAMPAIGN')) {
                return $this->sendError([
                    'message' => __('This action requires FluentCRM Pro', 'fluent-crm')
                ]);
            }

            $automationId = (int)$request->get('new_status', '');

            if (!$automationId) {
                return $this->sendError([
                    'message' => __('Invalid automation ID', 'fluent-crm')
                ]);
            }

            $automation = Funnel::findOrFail($automationId);

            $validSubscribers = Subscriber::whereIn('id', $subscriberIds)
                ->whereDoesntHave('funnels', function ($q) use ($automationId) {
                    $q->where('fc_funnels.id', $automationId);
                })
                ->get();

            if ($validSubscribers->isEmpty()) {

                if ($doingAllBulk) {
                    return $this->sendSuccess([
                        'last_contact_id'    => $lastContactId,
                        'completed_contacts' => count($subscriberIds),
                        'message'            => __('No valid active contacts found in this batch', 'fluent-crm')
                    ]);
                }

                return $this->sendError([
                    'message' => __('No valid active contacts found for this automation', 'fluent-crm')
                ]);
            }

            foreach ($validSubscribers as $subscriber) {
                (new FunnelProcessor())->startFunnelSequence($automation, [], [
                    'source_trigger_name' => 'fcrm_manual_attach'
                ], $subscriber);
            }

            return [
                'last_contact_id'    => $lastContactId,
                'completed_contacts' => count($subscriberIds),
                /* translators: %d is the number of subscribers */
                'message'            => sprintf(__('%d contacts have been added to the selected automation', 'fluent-crm'), count($validSubscribers)),
                'subscribers'        => $validSubscribers
            ];
        } else if ($actionName == 'change_contact_type') {
            $subscribers = Subscriber::whereIn('id', $subscriberIds)->get();
            $newType = Sanitize::contactType($request->get('new_status'));
            if (!$newType) {
                return $this->sendError([
                    'message' => __('Please select a new type', 'fluent-crm')
                ]);
            }
            foreach ($subscribers as $subscriber) {
                $oldType = $subscriber->contact_type;
                if ($oldType != $newType) {
                    $subscriber->contact_type = $newType;
                    $subscriber->save();
                    do_action('fluentcrm_subscriber_contact_type_to_' . $newType, $subscriber, $oldType);
                    do_action('fluent_crm/subscriber_contact_type_to_' . $newType, $subscriber, $oldType);
                }
            }

            return [
                'last_contact_id'    => $lastContactId,
                'completed_contacts' => count($subscriberIds),
                'message'            => __('Contact type has been updated for the selected contacts', 'fluent-crm')
            ];
        } else if ($actionName == 'update_custom_fields') {
            $customField = $request->get('custom_field');
            $customFieldKey = sanitize_text_field(Arr::get($customField, 'key', ''));
            $customFieldValue = Arr::get($customField, 'value');
            $operation = sanitize_text_field(Arr::get($customField, 'operation', ''));
            $subscribers = Subscriber::whereIn('id', $subscriberIds)->get();

            if (empty($customFieldKey)) {
                return $this->sendError([
                    'message' => __('Please provide a valid custom field key', 'fluent-crm')
                ]);
            }

            $customFieldDefinitions = [];
            foreach ((array) fluentcrm_get_option('contact_custom_fields', []) as $storedField) {
                $storedFieldSlug = sanitize_text_field(Arr::get($storedField, 'slug', ''));
                if ($storedFieldSlug) {
                    $customFieldDefinitions[$storedFieldSlug] = $storedField;
                }
            }

            $fieldType = Arr::get($customFieldDefinitions, $customFieldKey . '.type', '');
            $isMultiType = in_array($fieldType, ['select-multi', 'checkbox'], true);
            $isNumberType = $fieldType === 'number';
            $useOperation = in_array($operation, ['add', 'subtract'], true) && ($isMultiType || $isNumberType);

            foreach ($subscribers as $subscriber) {
                $newValue = $customFieldValue;

                if ($useOperation) {
                    $existingValue = $subscriber->getMeta($customFieldKey, 'custom_field');

                    if ($isNumberType) {
                        $existingNum = (float) $existingValue;
                        $incomingNum = (float) $customFieldValue;
                        $newValue = $operation === 'subtract'
                            ? $existingNum - $incomingNum
                            : $existingNum + $incomingNum;
                    } else {
                        $existingOptions = $existingValue ? (array) $existingValue : [];
                        $incomingOptions = (array) $customFieldValue;
                        $newValue = $operation === 'subtract'
                            ? array_values(array_diff($existingOptions, $incomingOptions))
                            : array_values(array_unique(array_merge($existingOptions, $incomingOptions)));
                    }
                }

                $subscriber->syncCustomFieldValues([$customFieldKey => $newValue], true, $customFieldDefinitions);
            }

            return [
                'last_contact_id'    => $lastContactId,
                'completed_contacts' => count($subscriberIds),
                'message'            => __('Custom fields have been updated for the selected contacts', 'fluent-crm')
            ];
        }

        $validActions = [
            'add_to_tags'       => 'attachTags',
            'add_to_lists'      => 'attachLists',
            'remove_from_tags'  => 'detachTags',
            'remove_from_lists' => 'detachLists'
        ];

        if (!isset($validActions[$actionName])) {
            $response = $this->sendError([
                'message' => __('The selected action is not valid', 'fluent-crm')
            ]);

            /**
             * Filter the result of a bulk action performed on FluentCRM contacts.
             *
             * The dynamic portion of the hook name, `$actionName`, refers to the specific bulk action being performed.
             *
             * @param mixed $response The initial response for the bulk action. Can be modified by the filter.
             * @param array $subscriberIds An array of subscriber IDs targeted by the bulk action.
             * @param array $request ->all()   The full request data as an associative array.
             *
             * @return mixed Filtered response for the bulk action.
             * @since 2.9.0
             *
             */
            $result = apply_filters('fluent_crm/contact_bulk_action_' . $actionName, $response, $subscriberIds, $request->all());

            if (is_array($result)) {
                $result['last_contact_id'] = $lastContactId;
                $result['completed_contacts'] = count($subscriberIds);
            }

            return $result;
        }

        $options = $request->get('action_options', []);

        $options = array_map(function ($id) {
            return intval($id);
        }, $options);

        $options = array_filter($options);

        if (!$options) {
            return $this->sendError([
                'message' => __('Please provide bulk options', 'fluent-crm')
            ]);
        }

        $method = $validActions[$actionName];

        $subscribers = Subscriber::whereIn('id', $subscriberIds)->get();

        foreach ($subscribers as $subscriber) {
            $subscriber->{$method}($options);
        }

        return [
            'last_contact_id'    => $lastContactId,
            'completed_contacts' => count($subscriberIds),
            'message'            => __('The selected bulk action has been completed.', 'fluent-crm')
        ];
    }

    /**
     * Up to 10 contact ids on each side of `current_id` in the list's filter and sort order.
     * `relevance` order with a search is rejected.
     */
    public function getPrevNextIds(Request $request)
    {
        $filterType = $this->request->get('filter_type');

        $currentId = (int)$this->request->get('current_id');

        if (!$filterType || !$currentId) {
            return $this->sendError([
                'message' => __('filter_type and current_id are required', 'fluent-crm')
            ]);
        }

        $queryArgs = ContactsQuery::argsFromRequest($this->request->all());
        $sortType = $queryArgs['sort_type'];
        $sortBy = $queryArgs['sort_by'];

        if ($sortBy === 'relevance') {
            if ($queryArgs['search'] !== '') {
                return $this->sendError([
                    'message' => __('Previous/next navigation is not available when sorting by relevance', 'fluent-crm')
                ]);
            }
            // Without a search, relevance is newest first.
            $sortBy = 'id';
            $sortType = 'DESC';
        } elseif (!in_array($sortBy, ContactsQuery::SORTABLE_COLUMNS, true)) {
            $sortBy = 'id';
        }

        if ($sortBy === 'id') {
            $currentValue = $currentId;
        } else {
            $current = Subscriber::select(['id', $sortBy])->find($currentId);
            if (!$current) {
                return $this->sendError([
                    'message' => __('Contact not found', 'fluent-crm')
                ], 404);
            }
            $currentValue = $current->getRawOriginal($sortBy);
        }

        $queryArgs = array_merge($queryArgs, [
            'sort_by'   => $sortBy,
            'sort_type' => $sortType,
            'with'      => []
        ]);

        // "prev" runs the reverse order so results come nearest-first.
        $nextQuery = (new ContactsQuery($queryArgs))->getModel();
        $this->whereSortsAfter($nextQuery, $sortBy, $currentValue, $currentId, $sortType === 'ASC');

        $prevQuery = (new ContactsQuery(array_merge($queryArgs, [
            'sort_type' => $sortType === 'ASC' ? 'DESC' : 'ASC'
        ])))->getModel();
        $this->whereSortsAfter($prevQuery, $sortBy, $currentValue, $currentId, $sortType !== 'ASC');

        $formattedNext = array_map('intval', $nextQuery->limit(10)->pluck('id')->toArray());
        $formattedPrev = array_map('intval', $prevQuery->limit(10)->pluck('id')->toArray());

        return [
            'navigation' => [
                'next' => $formattedNext,
                'prev' => $formattedPrev
            ],
            'has_next'   => count($formattedNext) == 10,
            'has_prev'   => count($formattedPrev) == 10
        ];
    }

    /**
     * Restrict a query to rows on one side of a (sort value, id) position.
     * NULL sorts below every value, as in MySQL.
     *
     * @param \FluentCrm\Framework\Database\Orm\Builder $query
     * @param string $column An allowlisted fc_subscribers column.
     * @param mixed $value The current contact's raw value in $column (may be null).
     * @param int $id The current contact's id, the tie-breaker.
     * @param bool $above True for rows ordered after the position ascending, false for before.
     */
    private function whereSortsAfter($query, $column, $value, $id, $above)
    {
        $idOp = $above ? '>' : '<';

        if ($column === 'id') {
            $query->where('id', $idOp, $id);
            return;
        }

        $query->where(function ($q) use ($column, $value, $id, $idOp, $above) {
            if ($value === null) {
                // Ties with the current NULL row, then (going up) every non-NULL row.
                $q->where(function ($tie) use ($column, $id, $idOp) {
                    $tie->whereNull($column)->where('id', $idOp, $id);
                });
                if ($above) {
                    $q->orWhereNotNull($column);
                }
                return;
            }

            $q->where($column, $above ? '>' : '<', $value)
                ->orWhere(function ($tie) use ($column, $value, $id, $idOp) {
                    $tie->where($column, $value)->where('id', $idOp, $id);
                });
            if (!$above) {
                $q->orWhereNull($column);
            }
        });
    }

    public function searchContacts(Request $request)
    {
        $search = trim($request->getSafe('search', 'sanitize_text_field', ''));
        $limit = absint($request->get('limit', 20));
        if (!$limit) {
            $limit = 20;
        }
        $offset = absint($request->get('offset', 0));

        $loadDefault = $request->get('load_default');
        $loadDefault = in_array($loadDefault, [true, 1, '1', 'true', 'yes'], true);

        $contacts = [];

        if ($search) {
            $subscribers = ContactSearch::orderByRelevance(Subscriber::searchBy($search), $search)
                ->offset($offset)
                ->limit($limit)
                ->get();
            foreach ($subscribers as $subscriber) {
                $contacts[$subscriber->id] = [
                    'first_name' => $subscriber->first_name,
                    'last_name'  => $subscriber->last_name,
                    'full_name'  => $subscriber->full_name,
                    'email'      => $subscriber->email,
                    'id'         => (string)$subscriber->id,
                    'photo'      => $subscriber->photo
                ];
            }
        } else if ($loadDefault) {
            $subscribers = Subscriber::orderBy('id', 'DESC')->offset($offset)->limit($limit)->get();
            foreach ($subscribers as $subscriber) {
                $contacts[$subscriber->id] = [
                    'first_name' => $subscriber->first_name,
                    'last_name'  => $subscriber->last_name,
                    'full_name'  => $subscriber->full_name,
                    'email'      => $subscriber->email,
                    'id'         => (string)$subscriber->id,
                    'photo'      => $subscriber->photo
                ];
            }
        }

        $values = (array)$request->get('values', []);

        if ($values) {
            $pushedIds = array_keys($contacts);
            $includedIds = array_diff($values, $pushedIds);
            if ($includedIds) {
                $subscribers = Subscriber::whereIn('id', $includedIds)->get();
                foreach ($subscribers as $subscriber) {
                    $contacts[$subscriber->id] = [
                        'first_name' => $subscriber->first_name,
                        'last_name'  => $subscriber->last_name,
                        'full_name'  => $subscriber->full_name,
                        'email'      => $subscriber->email,
                        'id'         => (string)$subscriber->id,
                        'photo'      => $subscriber->photo
                    ];
                }
            }
        }

        return [
            'contacts' => (object)$contacts
        ];
    }

    public function getInfoWidgets(Request $request, $subscriber)
    {
        if (is_numeric($subscriber)) {
            $subscriber = Subscriber::findOrFail($subscriber);
        }


        if ($byWidget = $request->get('by_widget')) {
            /**
             * Filter the subscriber info widget.
             *
             * This filter allows modification of the subscriber info widget based on the widget type.
             *
             * @param array The array of widgets.
             * @param object $subscriber The subscriber object data.
             * @since 2.8.40
             *
             */
            $widgets = apply_filters('fluent_crm/subscriber_info_widget_' . $byWidget, [], $subscriber);
            $widgets = array_values($widgets);

            if (isset($widgets[0])) {
                $widget = $widgets[0];
            } else {
                $widget = [
                    'content' => 'No content found'
                ];
            }

            return [
                'widget' => $widget
            ];
        }

        $commerce = (new PurchaseHistory())->getCommerceStatWidget($subscriber);

        /**
         * Filter the top widgets for a subscriber.
         *
         * This filter allows modification of the top widgets displayed for a subscriber.
         *
         * @param array The array of top widgets.
         * @param object $subscriber The subscriber object.
         * @since 2.8.0
         *
         * Security: each widget's `content` is rendered as raw HTML in the admin UI (Vue
         * v-html) without client-side sanitization. Producers hooking this filter MUST escape
         * any subscriber-authored data (e.g. via esc_html() / wp_kses_post()) before returning
         * it, to prevent stored XSS in the admin. Structural markup and intentional rich
         * widget content (styles, iframes, scripts) are allowed by design.
         */
        $topWidgets = array_filter(apply_filters('fluent_crm/subscriber_top_widgets', array_filter([$commerce]), $subscriber));

        /**
         * Filter the subscriber info widgets.
         *
         * This filter allows modification of the subscriber info widgets.
         *
         * @param array  An array of existing widgets.
         * @param object $subscriber The subscriber object.
         * @since 2.8.0
         *
         * Security: each widget's `content` is rendered as raw HTML in the admin UI (Vue
         * v-html) without client-side sanitization. Producers hooking this filter MUST escape
         * any subscriber-authored data (e.g. via esc_html() / wp_kses_post()) before returning
         * it, to prevent stored XSS in the admin. Structural markup and intentional rich
         * widget content (styles, iframes, scripts) are allowed by design.
         */
        $otherWidgets = apply_filters('fluent_crm/subscriber_info_widgets', [], $subscriber);

        return [
            'widgets' => [
                'top_widgets'   => $topWidgets,
                'other_widgets' => $otherWidgets,
                'widgets_count' => count($topWidgets) + count($otherWidgets)
            ]
        ];
    }

    public function getTrackingEvents(Request $request, $subscriberId)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return $this->sendError([
                'message'    => __('Event Tracker is not enabled', 'fluent-crm'),
                'error_code' => 'not_enabled'
            ]);
        }

        $subscriber = Subscriber::findOrFail($subscriberId);
        $events = EventTracker::where('subscriber_id', $subscriber->id)
            ->orderBy('id', 'DESC')
            ->paginate();

        return [
            'events' => $events
        ];
    }

    public function trackEvent(Request $request)
    {
        $data = $request->all();
        // `subscriber` is a PHP API-only param; from a request it would skip the contact lookup.
        unset($data['subscriber']);

        $this->validate($data, [
            'event_key' => 'required',
            'title'     => 'required'
        ]);

        $isUnique = $request->get('repeatable', true);
        $result = FluentCrmApi('event_tracker')->track($data, $isUnique);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message'    => $result->get_error_message(),
                'error_code' => $result->get_error_code()
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Event has been tracked', 'fluent-crm'),
            'id'      => $result->id
        ]);
    }

    public function getUrlMetrics(Request $request, $id)
    {
        // Only allowlisted columns and ASC/DESC reach orderBy().
        $sort_by = $this->request->get('sort_by');
        $sort_by = in_array($sort_by, ['id', 'counter', 'created_at', 'updated_at', 'url_id', 'campaign_id'], true) ? $sort_by : 'id';
        $sort_type = strtoupper((string)$this->request->get('sort_type')) === 'ASC' ? 'ASC' : 'DESC';
        $subscriber = Subscriber::findOrFail($id);

        $urlActivityQuery = CampaignUrlMetric::with('url_stores')
            ->where('subscriber_id', $subscriber->id)
            ->where('type', 'click');

        if (!empty($sort_by) && !empty($sort_type)) {
            $urlActivityQuery->orderBy($sort_by, $sort_type);
        } else {
            $urlActivityQuery->orderBy('id', 'DESC');
        }

        $urlActivity = $urlActivityQuery->paginate();

        $urlMetrics = $urlActivity->toArray();

        if (!empty($urlMetrics['data'])) {
            $urlMetrics['data'] = $this->formatUrlActivityData($urlMetrics['data']);
        }

        return [
            'urlMetrics' => $urlMetrics
        ];
    }

    public function formatUrlActivityData($data)
    {
        $result = [];
        foreach ($data as $item) {
            $result[] = [
                'url'   => $item['url_stores']['url'],
                'count' => $item['counter']
            ];
        }
        return $result;
    }

    public function getDynamicItemView(Request $request, $subscriberId)
    {
        $subscriber = Subscriber::findOrFail($subscriberId);
        $provider = (string)$request->get('provider');
        $params = $request->get('params', []);

        /**
         * Filter the dynamic item view for a specific provider.
         *
         * The dynamic portion of the hook name, `$provider`, refers to the specific provider for which the view is being fetched.
         *
         * @param array  {
         *     An array containing the dynamic item view data.
         *
         *      'type' => (string) The type of the dynamic item.
         *      'title' => (string) The title of the dynamic item.
         *      'content_html' => (string) The HTML content of the dynamic item.
         *      'footer_content' => (string) The footer content associated with the dynamic item.
         *
         * }
         * @param object $subscriber The subscriber object.
         * @param array $params Additional parameters passed in the request.
         * @since 3.0.0
         *
         */

        $data = apply_filters('fluent_crm/dynamic_contact_item_view_' . $provider, [
            'type'           => 'html',
            'title'          => 'no title',
            'content_html'   => 'sorry, no content found',
            'footer_content' => ''
        ], $params, $subscriber);

        return [
            'data_view' => $data
        ];
    }
}
