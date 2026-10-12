<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\App\Services\PermissionManager;
use FluentCrm\Framework\Http\Request\Request;

/**
 *  CustomFieldsPolicy - REST API Permission Policy
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */

class CustomFieldsPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_settings');
    }

    /**
     * Authorize reading the global labels list.
     * Requires any FluentCRM access, since several screens with different capabilities read labels.
     *
     * @param \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function getLabels(Request $request)
    {
        return !empty(PermissionManager::currentUserPermissions());
    }

    /**
     * Authorize global label creation for settings or email managers.
     *
     * @param Request $request
     * @return bool
     */
    public function create(Request $request)
    {
        return $this->verifyRequest($request) || $this->currentUserCan('fcrm_manage_emails');
    }

    /**
     * Authorize global label updates for settings or email managers.
     *
     * @param Request $request
     * @return bool
     */
    public function update(Request $request)
    {
        return $this->verifyRequest($request) || $this->currentUserCan('fcrm_manage_emails');
    }

    /**
     * Authorize global label deletion for settings or email delete managers.
     *
     * @param Request $request
     * @return bool
     */
    public function delete(Request $request)
    {
        return $this->verifyRequest($request) || $this->currentUserCan('fcrm_manage_email_delete');
    }
}
