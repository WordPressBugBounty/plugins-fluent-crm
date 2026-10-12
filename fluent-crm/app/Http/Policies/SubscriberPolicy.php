<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\Framework\Http\Request\Request;

/**
 *  SubscriberPolicy - REST API Permission Policy
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class SubscriberPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if ($this->requestMethod($request) == 'GET') {
            return $this->currentUserCan('fcrm_read_contacts');
        }

        return $this->currentUserCan('fcrm_manage_contacts');
    }

    /**
     * Authorize sending a one-off email to a single contact from the profile page.
     * Gated on `fcrm_manage_contacts`, not `fcrm_manage_emails`, matching the profile UI.
     *
     * @param \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function sendCustomEmail(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_contacts');
    }

    public function deleteSubscriber(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_contacts_delete');
    }

    public function deleteSubscribers(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_contacts_delete');
    }

    public function deleteNote(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_contacts_delete');
    }

    public function bulkDeleteNotes(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_contacts_delete');
    }

    /**
     * Pinned quick filters are a per-user preference, so read access is enough.
     */
    public function saveQuickPins(Request $request)
    {
        return $this->currentUserCan('fcrm_read_contacts');
    }

    public function deleteEmails(Request $request)
    {
        // "Delete emails" has no dependencies, so the contact permission is re-asserted.
        return $this->verifyRequest($request) && $this->currentUserCan('fcrm_manage_email_delete');
    }

    public function handleBulkActions(Request $request)
    {
        // Match the controller's normalization before selecting a capability.
        $actionName = sanitize_text_field($request->get('action_name', ''));

        if (!$actionName) {
            return $this->currentUserCan('fcrm_manage_contacts');
        }


        $actionMaps = [
            'add_to_email_sequence' => 'fcrm_manage_emails',
            'add_to_automation'     => 'fcrm_write_funnels',
            'delete_contacts'       => 'fcrm_manage_contacts_delete'
        ];

        if (isset($actionMaps[$actionName])) {
            return $this->currentUserCan($actionMaps[$actionName]);
        }

        return $this->currentUserCan('fcrm_manage_contacts');
    }
}
