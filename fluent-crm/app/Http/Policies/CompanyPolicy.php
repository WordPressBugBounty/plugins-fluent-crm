<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\App\Http\Policies\BasePolicy;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Http\Request\Request;

class CompanyPolicy extends BasePolicy
{
    private function isEnabled()
    {
        return Helper::isCompanyEnabled();
    }

    /**
     * Check user permission for any method
     * @param  \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return $this->isEnabled() && $this->currentUserCan('fcrm_manage_contact_cats');
    }

    public function delete(Request $request)
    {
        return $this->isEnabled() && $this->currentUserCan('fcrm_manage_contact_cats_delete');
    }

    /**
     * Check user permission for bulk company actions.
     * The delete action requires the delete permission; other actions need manage.
     *
     * @param  \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function handleBulkActions(Request $request)
    {
        $actionName = sanitize_text_field($request->get('action_name', ''));

        if ($actionName == 'delete_companies') {
            return $this->delete($request);
        }

        return $this->verifyRequest($request);
    }

    public function detachSubscribers(Request $request)
    {
        return $this->verifyRequest($request);
    }

    /**
     * Deleting a company note requires the delete permission, as delete() does.
     *
     * @param  \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function deleteNote(Request $request)
    {
        return $this->isEnabled() && $this->currentUserCan('fcrm_manage_contact_cats_delete');
    }

    public function bulkDeleteNotes(Request $request)
    {
        return $this->isEnabled() && $this->currentUserCan('fcrm_manage_contact_cats_delete');
    }

    public function deleteSubscribes(Request $request)
    {
        return $this->detachSubscribers($request);
    }
}
