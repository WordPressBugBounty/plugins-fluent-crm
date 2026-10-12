<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\Framework\Http\Request\Request;

/**
 *  ImportUserPolicy - Import Contact Policy
 *
 * @package FluentCrm\App\Http
 */
class ImportUserPolicy extends BasePolicy {
    /**
     * Check user permission for any method
     *
     * @param \FluentCrm\Framework\Http\Request\Request $request
     *
     * @return Boolean
     */
    public function verifyRequest( Request $request )
    {
        return $this->currentUserCan('fcrm_manage_contacts');
    }

    /**
     * WordPress-user imports require both contact management and WordPress user-list access.
     */
    public function importUsers(Request $request)
    {
        return $this->verifyRequest($request) && current_user_can('list_users');
    }

    /**
     * Apply the WordPress-user boundary only to the built-in users driver.
     * `driver` is a route parameter, so it arrives as an argument, not on the Request.
     */
    public function getDriver(Request $request, $driver = null)
    {
        if ($driver === 'users') {
            return $this->importUsers($request);
        }

        return $this->verifyRequest($request);
    }

    /**
     * Use the same driver-specific permission for import execution.
     */
    public function importData(Request $request, $driver = null)
    {
        return $this->getDriver($request, $driver);
    }
}
