<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\Framework\Http\Request\Request;

class AiPolicy extends BasePolicy
{
    public function getSettings(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_settings');
    }

    public function saveSettings(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_settings');
    }

    public function testConnection(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_settings');
    }

    public function getModels(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_settings');
    }

    public function generate(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_emails');
    }

    public function generateEmailBody(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_emails');
    }

    /**
     * Readers may view a summary and create the first one (it is cached on the contact).
     * Regenerate skips the cache and calls the paid AI provider every time, so it needs
     * write access to contacts.
     *
     * @param Request $request
     * @return bool
     */
    public function contactSummary(Request $request)
    {
        if ($request->get('regenerate') == 'yes') {
            return $this->currentUserCan('fcrm_manage_contacts');
        }

        return $this->currentUserCan('fcrm_read_contacts');
    }
}
