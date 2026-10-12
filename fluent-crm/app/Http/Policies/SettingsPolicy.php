<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Http\Request\Request;

/**
 * SettingsPolicy - REST API Permission Policy
 *
 * Settings routes require `fcrm_manage_settings`. Routes that install plugins, reset data
 * or mint REST credentials also require a core WordPress capability, re-checked in the controllers.
 */
class SettingsPolicy extends BasePolicy
{
    public function verifyRequest(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_settings');
    }

    /**
     * Installing a companion plugin also requires `install_plugins`.
     *
     * @param Request $request
     * @return bool
     */
    protected function canInstallPlugins(Request $request)
    {
        return $this->verifyRequest($request) && current_user_can('install_plugins');
    }

    /**
     * Routes that reset plugin data or mint REST credentials require `manage_options`.
     *
     * @param Request $request
     * @return bool
     */
    protected function isAdministrator(Request $request)
    {
        return $this->verifyRequest($request) && current_user_can('manage_options');
    }

    public function handleFluentFormInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentSmtpInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentSupportInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentBoardsInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentCommunityInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentCartInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentBookingInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    /**
     * Finishing onboarding requires `install_plugins` when the request installs companion plugins.
     * Flags must be read through the same Helper as SetupController::CompleteWizard().
     *
     * @param Request $request
     * @return bool
     */
    public function CompleteWizard(Request $request)
    {
        $installFlags = Helper::getWizardPluginInstallFlags($request);

        if (in_array(true, $installFlags, true)) {
            return $this->canInstallPlugins($request);
        }

        return $this->verifyRequest($request);
    }

    /**
     * Double opt-in previews are read-only and gated by the settings capability, not the campaign one.
     */
    public function previewDoubleOptin(Request $request)
    {
        return $this->verifyRequest($request);
    }

    /**
     * MCP adapter installation is a plugin install like the ones above.
     */
    public function installAdapter(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function resetDB(Request $request)
    {
        return $this->isAdministrator($request);
    }

    public function createRestKey(Request $request)
    {
        return $this->isAdministrator($request);
    }

    public function deleteRestKey(Request $request)
    {
        return $this->isAdministrator($request);
    }
}
