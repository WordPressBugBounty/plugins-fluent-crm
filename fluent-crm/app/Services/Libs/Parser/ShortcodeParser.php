<?php

namespace FluentCrm\App\Services\Libs\Parser;

use FluentCart\App\Services\ShortCodeParser\SmartCodeParser;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

class ShortcodeParser
{
    const LEVEL_BASIC = 'basic';
    const LEVEL_PRIVATE = 'private';
    const LEVEL_CREDENTIAL = 'credential';

    /**
     * Highest smartcode level this parse may resolve; see getSmartCodeLevel().
     *
     * @var string
     */
    /**
     * crm.* links that embed the contact's managed hash.
     */
    const MANAGED_LINK_KEYS = ['unsubscribe_url', 'unsubscribe_html', 'manage_subscription_url', 'manage_subscription_html'];

    private $allowedLevel = self::LEVEL_PRIVATE;

    /**
     * Codes withheld for their level since startWithheldLog(), or null when not recording.
     *
     * @var array|null
     */
    private static $withheldCodes = null;

    /**
     * Start recording smartcodes that render their fallback because of their level,
     * so a preview can tell the viewer why they are not filled in.
     */
    public static function startWithheldLog()
    {
        self::$withheldCodes = [];
    }

    /**
     * Stop recording and return the distinct codes withheld since startWithheldLog().
     *
     * @return string[]
     */
    public static function stopWithheldLog()
    {
        $codes = self::$withheldCodes ?: [];
        self::$withheldCodes = null;

        return array_values(array_unique($codes));
    }

    private static function logWithheld($code)
    {
        if (self::$withheldCodes !== null) {
            self::$withheldCodes[] = trim($code);
        }
    }

    /**
     * Parse smartcodes for a contact.
     *
     * $level caps what may resolve; a code above it renders its fallback value:
     * - basic:      front-end output and other text whose author is not trusted with the viewer's data.
     * - private:    staff-facing output and integrations (previews, test emails, webhooks). The default.
     * - credential: an email delivered to this contact's own inbox. Only then do reset links,
     *               contact hashes and similar access tokens resolve.
     *
     * @param string|array $templateString
     * @param \FluentCrm\App\Models\Subscriber|mixed $data
     * @param string $level basic|private|credential
     * @return string|array
     */
    public function parse($templateString, $data, $level = self::LEVEL_PRIVATE)
    {
        $this->allowedLevel = self::normalizeLevel($level);

        $result = [];
        $isSingle = false;

        if (!is_array($templateString)) {
            $isSingle = true;
        }

        foreach ((array)$templateString as $key => $string) {
            $result[$key] = $this->parseShortcode($string, $data);
        }

        if ($isSingle) {
            return reset($result);
        }

        return $result;
    }

    /**
     * Sensitivity of one smartcode: basic, private or credential.
     *
     * Looked up as "group.key", then "group.first_segment.*", then "group.*", then "*", against
     * both the code as written and its lower-cased form; the higher of the two wins, so a change
     * of case can never lower a code's level. Unknown addon groups are private.
     *
     * @param string $group e.g. "user"
     * @param string $key   e.g. "meta.billing_phone"
     * @return string
     */
    public static function getSmartCodeLevel($group, $key)
    {
        $levels = apply_filters('fluent_crm/smartcode_levels', [
            // Access tokens: only ever resolved into an email to the contact's own inbox.
            'user.password_reset_direct_link'  => self::LEVEL_CREDENTIAL,
            'wp_user.password_reset_url'       => self::LEVEL_CREDENTIAL,
            'contact.meta.*'                   => self::LEVEL_CREDENTIAL,
            'crm.activate_button'              => self::LEVEL_CREDENTIAL,
            // These carry the contact's managed hash, which lets anyone change their details and preferences.
            'crm.unsubscribe_url'              => self::LEVEL_CREDENTIAL,
            'crm.unsubscribe_html'             => self::LEVEL_CREDENTIAL,
            'crm.manage_subscription_url'      => self::LEVEL_CREDENTIAL,
            'crm.manage_subscription_html'     => self::LEVEL_CREDENTIAL,
            'ab_cart_woo.recovery_url'         => self::LEVEL_CREDENTIAL,
            'ab_cart_fluent_cart.recovery_url' => self::LEVEL_CREDENTIAL,
            'edd_license.license_key'          => self::LEVEL_CREDENTIAL,
            'edd_license.renew_url'            => self::LEVEL_CREDENTIAL,
            // Signed file links: anyone holding one can download the paid file.
            'edd_order.download_lists'         => self::LEVEL_CREDENTIAL,
            // For guest orders this is the order-received URL, which carries the order key.
            'woo_order.view_url'               => self::LEVEL_CREDENTIAL,
            'woo_last_order.view_url'          => self::LEVEL_CREDENTIAL,
            // FluentBooking: the booking hash lets anyone cancel or reschedule the booking.
            'fcal.booking.cancelation_url'     => self::LEVEL_CREDENTIAL,
            'fcal.booking.reschedule_url'      => self::LEVEL_CREDENTIAL,
            'fcal.booking.booking_hash'        => self::LEVEL_CREDENTIAL,
            'fcal.booking.hash'                => self::LEVEL_CREDENTIAL,
            // FluentCart treats the order uuid as the credential for receipt and review links.
            'cart_order.uuid'                  => self::LEVEL_CREDENTIAL,
            'cart_customer.uuid'               => self::LEVEL_CREDENTIAL,
            'cart_transaction.uuid'            => self::LEVEL_CREDENTIAL,
            // WP_User::get() falls through to user meta, so only display fields are basic.
            'user.display_name'                => self::LEVEL_BASIC,
            'user.first_name'                  => self::LEVEL_BASIC,
            'user.last_name'                   => self::LEVEL_BASIC,
            'user.nickname'                    => self::LEVEL_BASIC,
            'user.user_login'                  => self::LEVEL_BASIC,
            'user.user_nicename'               => self::LEVEL_BASIC,
            'user.*'                           => self::LEVEL_PRIVATE,
            'wp_user.meta.*'                   => self::LEVEL_PRIVATE,
            'wp_user.*'                        => self::LEVEL_BASIC,
            'contact.*'                        => self::LEVEL_BASIC,
            'crm.*'                            => self::LEVEL_BASIC,
            'wp.*'                             => self::LEVEL_BASIC,
            'other.*'                          => self::LEVEL_BASIC,
            '*'                                => self::LEVEL_PRIVATE,
        ]);

        $code = implode('.', array_map('trim', explode('.', trim($group) . '.' . trim($key))));

        $parts = explode('.', strtolower($code));

        // User meta often holds secrets (2FA keys, API tokens); credential-like keys never leave the contact's inbox.
        // Every segment is checked: the resolvers ignore trailing segments, so user.meta.api_token.x still reads api_token.
        if (in_array($parts[0], ['user', 'wp_user'], true)) {
            foreach (array_slice($parts, 1) as $segment) {
                if (self::isSensitiveStoredUserKey($segment)) {
                    return self::LEVEL_CREDENTIAL;
                }
            }
        }

        $asWritten = self::lookupSmartCodeLevel($levels, $code);
        $lowered = self::lookupSmartCodeLevel($levels, strtolower($code));

        return self::levelRank($asWritten) >= self::levelRank($lowered) ? $asWritten : $lowered;
    }

    /**
     * Whether a smartcode may resolve at the given level.
     *
     * @param string $group
     * @param string $key
     * @param string $level basic|private|credential
     * @return bool
     */
    public static function isSmartCodeAllowed($group, $key, $level)
    {
        return self::levelRank(self::getSmartCodeLevel($group, $key)) <= self::levelRank($level);
    }

    private static function lookupSmartCodeLevel($levels, $code)
    {
        if (isset($levels[$code])) {
            return self::normalizeLevel($levels[$code]);
        }

        $parts = explode('.', $code);
        if (count($parts) > 2 && isset($levels[$parts[0] . '.' . $parts[1] . '.*'])) {
            return self::normalizeLevel($levels[$parts[0] . '.' . $parts[1] . '.*']);
        }

        if (isset($levels[$parts[0] . '.*'])) {
            return self::normalizeLevel($levels[$parts[0] . '.*']);
        }

        return self::normalizeLevel(isset($levels['*']) ? $levels['*'] : self::LEVEL_PRIVATE);
    }

    private static function normalizeLevel($level)
    {
        return in_array($level, [self::LEVEL_BASIC, self::LEVEL_PRIVATE, self::LEVEL_CREDENTIAL], true) ? $level : self::LEVEL_PRIVATE;
    }

    private static function levelRank($level)
    {
        $ranks = [self::LEVEL_BASIC => 1, self::LEVEL_PRIVATE => 2, self::LEVEL_CREDENTIAL => 3];

        return $ranks[self::normalizeLevel($level)];
    }

    /**
     * @deprecated 3.2.6 Use parse() with the private level.
     */
    public function parseStoredText($templateString, $subscriber)
    {
        return $this->parse($templateString, $subscriber, self::LEVEL_PRIVATE);
    }

    /**
     * @deprecated 3.2.6 Smartcode levels replace stored-text parsing; credential codes are blocked before dispatch.
     */
    public static function isParsingStoredText()
    {
        return false;
    }

    /**
     * Whether a user field or meta key looks like a credential: private "_" keys and token-like names.
     * getSmartCodeLevel() treats matching user.* and wp_user.* codes as credential level.
     *
     * @param string $key A wp_users column or user meta key.
     * @return bool
     */
    public static function isSensitiveStoredUserKey($key)
    {
        $key = strtolower(trim((string)$key));
        $pattern = apply_filters(
            'fluent_crm/stored_text_sensitive_user_key_pattern',
            '/^_|pass|token|secret|key|auth|nonce|hash|otp|2fa|api|session|capabilit|license|credential/'
        );

        return (bool)preg_match($pattern, $key);
    }

    /**
     * Resolve the crm.* codes the main parse leaves for last (unsubscribe and preference links, activate button).
     *
     * @param string $templateString
     * @param \FluentCrm\App\Models\Subscriber $subscriber
     * @param string $level basic|private|credential, as in parse()
     * @return string
     */
    public function parseCrmValue($templateString, $subscriber, $level = self::LEVEL_PRIVATE)
    {
        $this->allowedLevel = self::normalizeLevel($level);

        return preg_replace_callback('/({{|##)+(.*?)(}}|##)/', function ($matches) use ($subscriber) {
            return $this->replaceExtendedCrmValue($matches, $subscriber);
        }, $templateString);
    }

    public function replaceExtendedCrmValue($matches, $subscriber)
    {
        if (empty($matches[2])) {
            return apply_filters('fluentcrm_smartcode_fallback', $matches[0], $subscriber);
        }

        $matches[2] = trim($matches[2]);

        $matched = explode('.', $matches[2]);

        if (count($matched) <= 1) {
            return apply_filters('fluentcrm_smartcode_fallback', $matches[0], $subscriber);
        }

        $dataKey = trim(array_shift($matched));

        $valueKey = trim(implode('.', $matched));

        if (!$valueKey) {
            return apply_filters('fluentcrm_smartcode_fallback', $matches[0], $subscriber);
        }

        $valueKeys = explode('|', $valueKey);

        $valueKey = $valueKeys[0];
        $defaultValue = '';

        $valueCounts = count($valueKeys);

        if ($valueCounts >= 3) {
            $defaultValue = trim($valueKeys[1]);
        } else if ($valueCounts === 2) {
            $defaultValue = trim($valueKeys[1]);
        }

        // getCrmValue() ignores the group, so {{any.activate_button}} is a crm code.
        if (!self::isSmartCodeAllowed('crm', $valueKey, $this->allowedLevel)) {
            if (defined('FLUENTCRM_PREVIEWING_EMAIL') && $subscriber && in_array($valueKey, self::MANAGED_LINK_KEYS, true)) {
                // Previews keep footer links visible but inert, instead of flagging every footer as withheld.
                return str_replace(fluentCrmGetContactManagedHash($subscriber->id), 'preview', $this->getCrmValue($valueKey, $defaultValue, $subscriber));
            }

            self::logWithheld($matches[0]);
            return $defaultValue;
        }

        return $this->getCrmValue($valueKey, $defaultValue, $subscriber);
    }

    public function parseShortcode($string, $data)
    {
        return preg_replace_callback('/({{|##)+(.*?)(}}|##)/', function ($matches) use ($data) {
            $value = $this->replace($matches, $data);

            // A code left for a later pass comes back as written and must stay parseable.
            if ($value === $matches[0]) {
                return $value;
            }

            return self::neutralizeSmartCodeDelimiters($value);
        }, $string);
    }

    /**
     * Make a resolved smartcode value unable to form a smartcode on a later parse.
     *
     * Values come from data the contact or a third party controls (names, addresses, custom fields,
     * user meta, order data). Parsed text can be parsed again, e.g. a campaign subject is parsed at
     * queue time, stored, then parsed again on the web preview. Without this, a first name of
     * "{{user.password_reset_direct_link}}" survives the first parse and resolves on the second.
     *
     * A zero-width space goes inside every opening delimiter ({{ and ##), so no code can start
     * within the value. Closing braces and lone characters are left alone: "}}" can close nothing
     * without an opener, and a lone "#" is common in real values such as "#ff0000" used in CSS.
     * The space is invisible in HTML and subjects, and the stored contact data is unchanged.
     *
     * @param mixed $value
     * @return mixed The value with delimiters broken up; non-strings are returned as is.
     */
    public static function neutralizeSmartCodeDelimiters($value)
    {
        if (!is_string($value) || (strpos($value, '{{') === false && strpos($value, '##') === false)) {
            return $value;
        }

        // The lookahead also splits runs such as "{{{", which a plain str_replace() would leave half done.
        return preg_replace('/([{#])(?=\1)/', "\$1\u{200B}", $value);
    }

    protected function replace($matches, $subscriber)
    {
        if (empty($matches[2])) {
            return apply_filters('fluentcrm_smartcode_fallback', $matches[0], $subscriber);
        }

        $matches[2] = trim($matches[2]);

        $matched = explode('.', $matches[2]);

        if (count($matched) <= 1) {
            return apply_filters('fluentcrm_smartcode_fallback', $matches[0], $subscriber);
        }

        $dataKey = trim(array_shift($matched));

        $valueKey = trim(implode('.', $matched));

        if (!$valueKey) {
            return apply_filters('fluentcrm_smartcode_fallback', $matches[0], $subscriber);
        }

        $valueKeys = explode('|', $valueKey);

        $valueKey = $valueKeys[0];
        $defaultValue = '';
        $transformer = '';

        $valueCounts = count($valueKeys);

        if ($valueCounts >= 3) {
            $defaultValue = trim($valueKeys[1]);
            $transformer = trim($valueKeys[2]);
        } else if ($valueCounts === 2) {
            $defaultValue = trim($valueKeys[1]);
        }

        // Managed links stay literal here and are gated in parseCrmValue(), which renders them inert in previews.
        if ($dataKey === 'crm' && in_array($valueKey, self::MANAGED_LINK_KEYS, true)) {
            return $matches[0];
        }

        if (!self::isSmartCodeAllowed($dataKey, $valueKey, $this->allowedLevel)) {
            self::logWithheld($matches[0]);
            return $defaultValue;
        }

        if (!$subscriber) {
            return $defaultValue;
        }

        $value = '';

        switch ($dataKey) {
            case 'contact':
                $value = $this->getSubscriberValue($subscriber, $valueKey, $defaultValue);
                break;
            case 'wp':
                $value = $this->getWpValue($valueKey, $defaultValue, $subscriber);
                break;
            case 'crm':
                // @todo: Confirm these URL codes are always replaced later in the pipeline.
                $urlKeys = [
                    'unsubscribe_url',
                    'manage_subscription_url',
                    'unsubscribe_html',
                    'manage_subscription_html'
                ];

                if (in_array($valueKey, $urlKeys)) {
                    return $matches[0]; //  we will replace these later.
                }

                $value = $this->getCrmValue($valueKey, $defaultValue, $subscriber);
                break;
            case 'user':
                $value = $this->getUserValue($valueKey, $defaultValue, $subscriber);
                break;
            case 'other':
                $value = $this->parseOtherValue($valueKey, $defaultValue, $subscriber);
                break;
            default:
                $value = apply_filters('fluent_crm/smartcode_group_callback_' . $dataKey, $matches[0], $valueKey, $defaultValue, $subscriber, $this->allowedLevel);
        }

        if ($transformer && is_string($transformer) && $value) {
            switch ($transformer) {
                case 'urlencode':
                    // Encode one URL parameter value: {{contact.email||urlencode}}.
                    return rawurlencode((string) $value);
                case 'trim':
                    return trim($value);
                case 'ucfirst':
                    return ucfirst($value);
                case 'strtolower':
                    return strtolower($value);
                case 'strtoupper':
                    return strtoupper($value);
                case 'ucwords':
                    return ucwords($value);
                case 'concat_first': // usage: {{contact.first_name||concat_first|Hi
                    if (isset($valueKeys[3])) {
                        $value = trim($valueKeys[3] . ' ' . $value);
                    }
                    return $value;
                case 'concat_last': // usage: {{contact.first_name||concat_last|, => FIRST_NAME,
                    if (isset($valueKeys[3])) {
                        $value = trim($value . '' . $valueKeys[3]);
                    }
                    return $value;
                case 'show_if': // usage {{contact.first_name||show_if|First name exist
                    if (isset($valueKeys[3])) {
                        $value = $valueKeys[3];
                    }
                    return $value;
                default:
                    return $value;
            }
        }

        return $value;

    }

    protected function getWpValue($valueKey, $defaultValue, $subscriber = [])
    {
        $value = get_bloginfo($valueKey);
        if (!$value) {
            return $defaultValue;
        }
        return $value;
    }

    protected function getCrmValue($valueKey, $defaultValue = '', $subscriber = [])
    {
        switch ($valueKey) {
            case "unsubscribe_url":
                return add_query_arg(array_filter([
                    FLUENTCRM_EXTERNAL_URL_PARAM => 1,
                    'route'                      => 'unsubscribe',
                    'ce_id'                      => $subscriber->email_id,
                    'secure_hash'                => fluentCrmGetContactManagedHash($subscriber->id)
                ]), site_url('/'));
            case "manage_subscription_url":
                return add_query_arg(array_filter([
                    FLUENTCRM_EXTERNAL_URL_PARAM => 1,
                    'route'                      => 'manage_subscription',
                    'ce_id'                      => $subscriber->id,
                    'secure_hash'                => fluentCrmGetContactManagedHash($subscriber->id)
                ]), site_url('/'));
            case "unsubscribe_html":
                if (!$defaultValue) {
                    $defaultValue = __('Unsubscribe', 'fluent-crm');
                }

                $url = add_query_arg(array_filter([
                    FLUENTCRM_EXTERNAL_URL_PARAM => 1,
                    'route'                      => 'unsubscribe',
                    'ce_id'                      => $subscriber->email_id,
                    'secure_hash'                => fluentCrmGetContactManagedHash($subscriber->id)
                ]), site_url('/'));

                return '<a class="fc_unsub_url" href="' . $url . '">' . $defaultValue . '</a>';
            case "manage_subscription_html":
                if (!$defaultValue) {
                    $defaultValue = __('Email Preference', 'fluent-crm');
                }

                $url = add_query_arg(array_filter([
                    FLUENTCRM_EXTERNAL_URL_PARAM => 1,
                    'route'                      => 'manage_subscription',
                    'ce_id'                      => $subscriber->id,
                    'secure_hash'                => fluentCrmGetContactManagedHash($subscriber->id)
                ]), site_url('/'));

                return '<a class="fc_msub_url" href="' . $url . '">' . $defaultValue . '</a>';
            case "activate_button":
                if (!$defaultValue) {
                    $defaultValue = __('Confirm Subscription', 'fluent-crm');
                }
                $url = add_query_arg(array_filter([
                    FLUENTCRM_EXTERNAL_URL_PARAM => 1,
                    'route'                      => 'confirmation',
                    'hash'                       => $subscriber->hash,
                    'secure_hash'                => $subscriber->getSecureHash()
                ]), site_url('/'));

                return '<a style="color: #ffffff; background-color: #454545; font-size: 16px; border-radius: 5px; text-decoration: none; font-weight: normal; font-style: normal; padding: 0.8rem 1rem; border-color: #0072ff;" href="' . $url . '">' . $defaultValue . '</a>';
            case "business_name":
                $business = fluentcrmGetGlobalSettings('business_settings', []);
                $businessName = Arr::get($business, 'business_name');
                return (!empty($businessName)) ? $businessName : $defaultValue;
            case "business_address":
                $business = fluentcrmGetGlobalSettings('business_settings', []);
                $address = Arr::get($business, 'business_address', $defaultValue);
                return (!empty($address)) ? $address : $defaultValue;
            default:
                return $defaultValue;
        }
    }

    protected function getSubscriberValue($subscriber, $valueKey, $defaultValue)
    {
        if (!$subscriber || !$subscriber instanceof Subscriber) {
            return $defaultValue; // We don't have subscriber
        }

        $valueKeys = explode('.', $valueKey);

        if (count($valueKeys) == 1) {
            $data = $subscriber->toArray();

            if ($valueKey == 'full_name') {
                return $subscriber->full_name !== '' ? $subscriber->full_name : $defaultValue;
            }

            $value = Arr::get($data, $valueKey);

            // A stored 0 is a real value; only missing or empty values use the fallback.
            return ($value === null || $value === '') ? $defaultValue : $value;
        }

        $customKey = $valueKeys[0];
        $customProperty = $valueKeys[1];

        if ($customKey == 'custom') {
            $existingCustomFields = fluentcrm_get_custom_contact_fields();
            $customValues = $subscriber->custom_fields();

            $value = Arr::get($customValues, $customProperty, $defaultValue);
            if (is_array($value)) {
                return $value ? implode(', ', $value) : $defaultValue;
            }

            if ($value === null || $value === '') {
                return $defaultValue;
            }

            $multiLines = preg_split("/\r\n|\n|\r/", $value);

            if (!$multiLines) {
                return $value;
            }

            $formattedValue = implode('<br/> ', $multiLines);

            $fieldKeys = array_column($existingCustomFields, 'slug');
            $customFieldIndex = array_search($customProperty, $fieldKeys);

            if ($customFieldIndex === false) {
                return $formattedValue;
            }

            $matchedObject = $existingCustomFields[$customFieldIndex];

            $timestamp = strtotime($formattedValue);

            if ($timestamp && in_array($matchedObject['type'], ['date', 'date_time'])) {
                $date_format = get_option('date_format');

                if ($matchedObject['type'] === 'date_time') {
                    $time_format = get_option('time_format');
                    $date_format .= ' ' . $time_format; // Append time format
                }

                $formattedValue = date_i18n($date_format, $timestamp);
            }
            return $formattedValue;
        }

        if ($customKey == 'company') {
            if (!Helper::isCompanyEnabled()) {
                return $defaultValue;
            }

            $company = $subscriber->company;
            if (!$company) {
                return $defaultValue;
            }

            if ($customProperty == 'address') {
                $address = array_filter([
                    $company->address_line_1,
                    $company->address_line_2,
                    $company->city,
                    $company->state,
                    $company->postal_code,
                    $company->country
                ]);

                if (!$address) {
                    return $defaultValue;
                }

                return implode(', ', $address);
            }

            $acceptedFields = [
                'name',
                'industry',
                'email',
                'timezone',
                'address_line_1',
                'address_line_2',
                'postal_code',
                'city',
                'state',
                'country',
                'employees_number',
                'description',
                'phone',
                'logo',
                'website',
                'linkedin_url',
                'twitter_url',
                'facebook_url',
                'date_of_start',
            ];

            if (!in_array($customProperty, $acceptedFields)) {
                return $defaultValue;
            }

            $companyValue = $company->{$customProperty};

            return ($companyValue) ? $companyValue : $defaultValue;
        }

        if ($customKey == 'tags') {
            $tagsArray = [];
            foreach ($subscriber->tags as $tag) {
                $tagsArray[] = $tag->{$customProperty};
            }

            if ($tagsArray) {
                return implode(', ', $tagsArray);
            }
        } else if ($customKey == 'lists') {
            $tagsArray = [];
            foreach ($subscriber->lists as $tag) {
                $tagsArray[] = $tag->{$customProperty};
            }

            if ($tagsArray) {
                return implode(', ', $tagsArray);
            }
        } else if ($customKey == 'meta') {
            if ($customProperty == '_secure_hash') {
                return fluentCrmGetContactSecureHash($subscriber->id);
            }

            if ($customProperty == '_secure_managed_hash') {
                return fluentCrmGetContactManagedHash($subscriber->id);
            }
        }

        return $defaultValue;
    }

    protected function getUserValue($valueKey, $defaultValue, $subscriber)
    {
        if (!$subscriber || !$subscriber instanceof Subscriber) {
            return $defaultValue;
        }

        $wpUser = $subscriber->getWpUser();

        if (!$wpUser) {
            return $defaultValue;
        }

        // Hard-blocked directly and via user.meta.*; the contact's user_id link is untrusted.
        $blockedKeys = ['user_pass', 'user_activation_key', 'session_tokens'];

        if ($valueKey == 'password_reset_direct_link') {

            if (defined('FLUENTCRM_PREVIEWING_EMAIL')) {
                return '#pasword_reset_link_will_be_inserted_on_real_email';
            }

            // The contact email must match the linked WP user's email, so a spoofed user_id cannot mint a link.
            if (!$subscriber->email || strtolower($subscriber->email) !== strtolower($wpUser->user_email)) {
                return $defaultValue;
            }

            // No reset links for administrators: the rendered value can surface in records lower roles read.
            if (user_can($wpUser, 'manage_options') && !apply_filters('fluent_crm/allow_privileged_reset_link_smartcode', false, $wpUser, $subscriber)) {
                return $defaultValue;
            }

            // Each get_password_reset_key() call replaces the previous key, so a second
            // use of this smartcode in the same email would kill the first link.
            static $linkCache = [];
            if (isset($linkCache[$wpUser->ID])) {
                return $linkCache[$wpUser->ID];
            }

            $key = get_password_reset_key($wpUser);
            if (is_wp_error($key)) {
                return $defaultValue;
            }

            $linkCache[$wpUser->ID] = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($wpUser->user_login), 'login');
            return $linkCache[$wpUser->ID];
        }

        $valueKeys = explode('.', $valueKey);
        if (count($valueKeys) == 1) {
            if (in_array(strtolower($valueKey), $blockedKeys, true)) {
                return $defaultValue;
            }

            $value = $wpUser->get($valueKey);
            if (!$value) {
                return $defaultValue;
            }

            // Only scalar values are safe to inline; never expose arrays/objects.
            if (!is_array($value) && !is_object($value)) {
                return $value;
            }

            return $defaultValue;
        }

        $customKey = $valueKeys[0];
        $customProperty = $valueKeys[1];

        if ($customKey == 'meta') {
            if (in_array(strtolower($customProperty), $blockedKeys, true)) {
                return $defaultValue;
            }

            $metaValue = get_user_meta($wpUser->id, $customProperty, true);
            if (!$metaValue) {
                return $defaultValue;
            }

            // Only scalar values are safe to inline; never expose arrays/objects.
            if (!is_array($metaValue) && !is_object($metaValue)) {
                return $metaValue;
            }

            return $defaultValue;
        }

        return $defaultValue;
    }

    protected function parseOtherValue($valueKey, $defaultValue, $subscriber)
    {
        $valueKeys = explode('.', $valueKey);

        if (count($valueKeys) == 1) {
            return $defaultValue;
        }

        $key = $valueKeys[0];

        $otherKey = $valueKeys[1];

        if (!$otherKey) {
            return $defaultValue;
        }

        if ($key == 'latest_post') {
            $posts = get_posts([
                'post_type'           => 'post',
                'post_status'         => 'publish',
                'posts_per_page'      => 1,
                'orderby'             => 'date',
                'order'               => 'DESC',
                'ignore_sticky_posts' => 1
            ]);

            if (!count($posts)) {
                return $defaultValue;
            }

            $post = $posts[0];

            if ($otherKey == 'title') {
                return $post->post_title;
            }

            if ($otherKey == 'content') {
                return get_the_content(null, false, $post);
            }

            if ($otherKey == 'excerpt') {
                return get_the_excerpt($post);
            }

            return $post->post_title;
        }

        if ($key == 'date_format') {
            array_shift($valueKeys);
            $formatKey = implode('.', $valueKeys);

            if (!$formatKey) {
                $formatKey = get_option('date_format');
            }

            return date_i18n($formatKey, current_time('timestamp'));
        }

        if ($key == 'date') {
            array_shift($valueKeys);
            array_shift($valueKeys);
            $formatKey = implode('.', $valueKeys);

            if (!$formatKey) {
                $formatKey = get_option('date_format');
            }

            $timeStamp = strtotime($otherKey);

            $timeStamp += (int)(get_option('gmt_offset') * HOUR_IN_SECONDS);

            return date_i18n($formatKey, $timeStamp);
        }

        return $defaultValue;

    }
}
