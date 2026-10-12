<?php

namespace FluentCrm\App\Services\ExternalIntegrations\MailComplaince;


use FluentCrm\App\Hooks\Handlers\ExternalPages;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

class Webhook
{
    /**
     * @param $serviceName
     * @param $request \FluentCrm\Framework\Http\Request\Request
     */
    public function handle($serviceName, $request)
    {
        $method = 'handle' . ucfirst(strtolower($serviceName));

        if (method_exists($this, $method)) {
            return $this->{$method}($request);
        }

        return null;
    }

    /**
     * @param $request \FluentCrm\Framework\Http\Request\Request
     */
    private function handleMailgun($request)
    {
        $payload = $this->resolvePayload($request, []);

        $eventData = Arr::get($payload, 'event-data', []);

        if (!$eventData) {
            // Fallback: try reading from request params directly
            $eventData = $request->get('event-data', []);
        }

        if (!$eventData) {
            return false;
        }

        $event = Arr::get($eventData, 'event');

        $catchEvents = ['failed', 'unsubscribed', 'complained'];

        if (!in_array($event, $catchEvents)) {
            return false;
        }

        $recipientEmail = Arr::get($eventData, 'recipient');
        if (!$recipientEmail) {
            return false;
        }

        $externalPages = new ExternalPages();

        // For failed events, check severity to distinguish soft vs hard bounce
        if ($event == 'failed') {
            $severity = Arr::get($eventData, 'severity', 'permanent');
            if ($severity == 'temporary') {
                $description = Arr::get($eventData, 'delivery-status.message', '');
                if (!$description) {
                    $description = Arr::get($eventData, 'delivery-status.description', '');
                }
                return $externalPages->recordSoftBounce([
                    'email'  => $recipientEmail,
                    /* translators: %1$s: bounce description from Mailgun, %2$s: date and time the event was recorded */
                    'reason' => sprintf(__('Soft bounce reported by the Mailgun webhook API. %1$s Recorded at: %2$s', 'fluent-crm'), $description, current_time('mysql'))
                ]);
            }
        }

        $newStatus = 'bounced';
        if ($event == 'complained') {
            $newStatus = 'complained';
        } else if ($event == 'unsubscribed') {
            $newStatus = 'unsubscribed';
        }

        $unsubscribeData = [
            'email'  => $recipientEmail,
            /* translators: %1$s: new contact status (bounced, complained or unsubscribed), %2$s: Mailgun event name, %3$s: date and time */
            'reason' => sprintf(__('Status %1$s was set by the Mailgun webhook API (event: %2$s) at %3$s', 'fluent-crm'), $newStatus, $event, current_time('mysql')),
            'status' => $newStatus
        ];

        return $externalPages->recordUnsubscribe($unsubscribeData);
    }

    /**
     * @param $request \FluentCrm\Framework\Http\Request\Request
     * @return boolean
     */
    private function handleSendgrid($request)
    {
        $events = $this->resolvePayload($request, []);

        if (!$events || !count($events)) {
            return false;
        }

        $externalPages = new ExternalPages();
        $processed = false;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $eventName = Arr::get($event, 'event');
            $email = Arr::get($event, 'email');

            if (!$email || !in_array($eventName, ['dropped', 'bounce', 'spamreport', 'unsubscribe'])) {
                continue;
            }

            if ($eventName == 'unsubscribe') {
                $externalPages->recordUnsubscribe([
                    'email'  => $email,
                    /* translators: %s: date and time the event was recorded */
                    'reason' => sprintf(__('Unsubscribed status was set by the SendGrid webhook API. Recorded at: %s', 'fluent-crm'), current_time('mysql')),
                    'status' => 'unsubscribed'
                ]);
                $processed = true;
                continue;
            }

            if ($eventName == 'spamreport') {
                $externalPages->recordUnsubscribe([
                    'email'  => $email,
                    /* translators: %s: date and time the event was recorded */
                    'reason' => sprintf(__('Complained status was set by the SendGrid webhook API. Recorded at: %s', 'fluent-crm'), current_time('mysql')),
                    'status' => 'complained'
                ]);
                $processed = true;
                continue;
            }

            $reason = Arr::get($event, 'reason', '');

            if ($eventName == 'dropped') {
                $dropStatus = self::sendgridDropStatus($reason);
                if (!$dropStatus) {
                    continue; // A sender-side drop (spam filter, bad header, quota) says nothing about the address.
                }
                if ($dropStatus != 'bounced') {
                    $externalPages->recordUnsubscribe([
                        'email'  => $email,
                        /* translators: %1$s: contact status, %2$s: drop reason from SendGrid, %3$s: date and time the event was recorded */
                        'reason' => sprintf(__('Status %1$s was set by the SendGrid webhook API (dropped: %2$s). Recorded at: %3$s', 'fluent-crm'), $dropStatus, $reason, current_time('mysql')),
                        'status' => $dropStatus
                    ]);
                    $processed = true;
                    continue;
                }
            }

            // A bounce event's type is "bounce" (hard) or "blocked" (soft); a dropped event that reaches here is a known bad address.
            $bounceType = $eventName == 'dropped' ? 'bounce' : Arr::get($event, 'type', 'bounce');
            if ($bounceType == 'blocked') {
                $externalPages->recordSoftBounce([
                    'email'  => $email,
                    /* translators: %1$s: bounce reason from SendGrid, %2$s: date and time the event was recorded */
                    'reason' => sprintf(__('Soft bounce from the SendGrid webhook API. Reason: %1$s. Recorded at: %2$s', 'fluent-crm'), $reason, current_time('mysql'))
                ]);
            } else {
                $externalPages->recordUnsubscribe([
                    'email'  => $email,
                    /* translators: %1$s: bounce reason from SendGrid, %2$s: date and time the event was recorded */
                    'reason' => sprintf(__('Bounced status was set by the SendGrid webhook API. Reason: %1$s. Recorded at: %2$s', 'fluent-crm'), $reason, current_time('mysql')),
                    'status' => 'bounced'
                ]);
            }
            $processed = true;
        }

        return $processed;
    }

    /**
     * @param $request \FluentCrm\Framework\Http\Request\Request
     * @return boolean
     */
    private function handlePepipost($request)
    {
        $events = $this->resolvePayload($request, []);

        if (!$events || !count($events)) {
            return false;
        }

        $externalPages = new ExternalPages();
        $processed = false;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $eventName = Arr::get($event, 'EVENT');

            if (!in_array($eventName, ['bounced', 'invalid', 'spam', 'unsubscribed'])) {
                continue;
            }

            $newStatus = 'bounced';
            if ($eventName == 'unsubscribed') {
                $newStatus = 'unsubscribed';
            } else if ($eventName == 'spam') {
                $newStatus = 'complained';
            }

            /* translators: %1$s: new contact status (bounced, complained or unsubscribed), %2$s: bounce type, %3$s: date and time */
            $reason = sprintf(__('Status %1$s was set by the Pepipost webhook API. Reason: %2$s. Recorded at: %3$s', 'fluent-crm'), $newStatus, Arr::get($event, 'BOUNCE_TYPE'), current_time('mysql'));

            if ($sourceResponse = Arr::get($event, 'RESPONSE')) {
                $reason = $sourceResponse;
            }

            $email = Arr::get($event, 'EMAIL');
            if ($email) {
                $externalPages->recordUnsubscribe([
                    'email'  => $email,
                    'reason' => $reason,
                    'status' => $newStatus
                ]);
                $processed = true;
            }
        }

        return $processed;
    }

    /**
     * @param $request \FluentCrm\Framework\Http\Request\Request
     * @return boolean
     */
    private function handleSparkpost($request)
    {
        $events = $this->resolvePayload($request, []);

        if (!$events || !count($events)) {
            return false;
        }

        $externalPages = new ExternalPages();
        $processed = false;

        // SparkPost hard bounce classes: 10 (Invalid Recipient), 25 (Admin Failure),
        // 30 (Generic Bounce: No RCPT), 90 (Unsubscribe)
        $hardBounceClasses = [10, 25, 30, 90];

        foreach ($events as $eventWrapper) {
            if (!is_array($eventWrapper)) {
                continue;
            }

            // SparkPost wraps events in msys.message_event or msys.unsubscribe_event
            $event = Arr::get($eventWrapper, 'msys.message_event');
            if (!$event || !is_array($event)) {
                $event = Arr::get($eventWrapper, 'msys.unsubscribe_event');
            }
            if (!$event || !is_array($event)) {
                continue;
            }

            $eventName = Arr::get($event, 'type');
            if (!in_array($eventName, ['bounce', 'out_of_band', 'spam_complaint', 'link_unsubscribe', 'list_unsubscribe'])) {
                continue;
            }

            $email = Arr::get($event, 'rcpt_to');
            if (!$email) {
                continue;
            }

            $reason = Arr::get($event, 'raw_reason', '');
            if (!$reason) {
                /* translators: %1$s: SparkPost event name, %2$s: date and time the event was recorded */
                $reason = sprintf(__('Status %1$s was set by the SparkPost webhook API. Recorded at: %2$s', 'fluent-crm'), $eventName, current_time('mysql'));
            }

            // Both bounce and out_of_band events carry bounce_class
            if (in_array($eventName, ['bounce', 'out_of_band'])) {
                $bounceClass = (int)Arr::get($event, 'bounce_class', 0);
                if (!in_array($bounceClass, $hardBounceClasses)) {
                    $externalPages->recordSoftBounce([
                        'email'  => $email,
                        'reason' => $reason
                    ]);
                    $processed = true;
                    continue;
                }
            }

            $newStatus = 'bounced';
            if (in_array($eventName, ['link_unsubscribe', 'list_unsubscribe'])) {
                $newStatus = 'unsubscribed';
            } else if ($eventName == 'spam_complaint') {
                $newStatus = 'complained';
            }

            $externalPages->recordUnsubscribe([
                'email'  => $email,
                'reason' => $reason,
                'status' => $newStatus
            ]);
            $processed = true;
        }

        return $processed;
    }

    /**
     * @param $request \FluentCrm\Framework\Http\Request\Request
     * @return boolean
     */
    private function handlePostmark($request)
    {
        $event = $this->resolvePayload($request, []);

        if (!$event || !is_array($event)) {
            return false;
        }

        $eventName = Arr::get($event, 'RecordType');
        if (!in_array($eventName, ['Bounce', 'SpamComplaint'])) {
            return false;
        }

        $email = Arr::get($event, 'Email');
        if (!$email) {
            return false;
        }

        $reason = Arr::get($event, 'Description', '');
        if (!$reason) {
            /* translators: %1$s: Postmark event name, %2$s: date and time the event was recorded */
            $reason = sprintf(__('Status %1$s was set by the Postmark webhook API. Recorded at: %2$s', 'fluent-crm'), $eventName, current_time('mysql'));
        }

        $externalPages = new ExternalPages();

        if ($eventName == 'SpamComplaint') {
            return $externalPages->recordUnsubscribe([
                'email'  => $email,
                'reason' => $reason,
                'status' => 'complained'
            ]);
        }

        $action = self::postmarkBounceAction(Arr::get($event, 'Type', ''), Arr::get($event, 'Inactive'));

        if ($action == 'ignore') {
            return false;
        }

        if ($action == 'soft') {
            return $externalPages->recordSoftBounce([
                'email'  => $email,
                'reason' => $reason
            ]);
        }

        return $externalPages->recordUnsubscribe([
            'email'  => $email,
            'reason' => $reason,
            'status' => $action
        ]);
    }

    /**
     * What a Postmark bounce record means for the contact.
     *
     * Postmark posts a Bounce record for every bounce type, including auto-replies and
     * sender-side errors (https://postmarkapp.com/developer/api/bounce-api#bounce-types).
     * Only permanent address failures, or a bounce that made Postmark deactivate the address
     * (Inactive = true), bounce the contact; temporary failures count toward the soft-bounce
     * limit; replies and sender-side errors are ignored.
     *
     * @param string $type Postmark bounce Type
     * @param mixed $inactive Postmark's Inactive flag
     * @return string 'bounced', 'complained', 'unsubscribed', 'soft' or 'ignore'
     */
    public static function postmarkBounceAction($type, $inactive = false)
    {
        if ($type == 'SpamComplaint') {
            return 'complained';
        }

        if ($type == 'Unsubscribe') {
            return 'unsubscribed';
        }

        if (in_array($type, ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'], true) || $inactive === true || $inactive === 'true') {
            return 'bounced';
        }

        $ignored = ['Subscribe', 'AutoResponder', 'AddressChange', 'OpenRelayTest', 'ChallengeVerification', 'VirusNotification',
            'Unconfirmed', 'SMTPApiError', 'InboundError', 'TemplateRenderingFailed'];
        if (in_array($type, $ignored, true)) {
            return 'ignore';
        }

        // Transient, SoftBounce, DnsError, SpamNotification, Blocked, DMARCPolicy, Unknown and anything new.
        return 'soft';
    }

    /**
     * The contact status a SendGrid "dropped" event implies, or null when the drop is sender-side.
     *
     * SendGrid drops a message for an address already on one of its suppression lists, or for a
     * problem with the message itself (https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/event).
     * Only the former says anything about the contact.
     *
     * @param string $reason the event's reason
     * @return string|null 'bounced', 'unsubscribed', 'complained' or null
     */
    public static function sendgridDropStatus($reason)
    {
        $reason = strtolower((string)$reason);

        if (strpos($reason, 'bounced address') !== false || strpos($reason, 'invalid address') !== false) {
            return 'bounced';
        }
        if (strpos($reason, 'unsubscribed address') !== false) {
            return 'unsubscribed';
        }
        if (strpos($reason, 'spam reporting address') !== false) {
            return 'complained';
        }

        // Spam Content, Invalid SMTPAPI header, Recipient List over Package Quota, Invalid (template/header).
        return null;
    }

    private function handleElasticemail($request)
    {
        $status = strtolower($request->get('status'));

        $processStatuses = [
            'error',
            'abusereport',
            'unsubscribed'
        ];

        if (!in_array($status, $processStatuses)) {
            return [
                'message' => 'unknown_status'
            ];
        }

        $email = $request->get('to');
        if (!$email) {
            return false;
        }

        $externalPages = new ExternalPages();

        $softBounceCategories = [
            'AccountProblem',
            'Throttled',
            'SPFProblem',
            'Timeout',
            'ConnectionProblem',
            'GreyListed',
            'WhitelistingProblem',
            'CodeError'
        ];

        $category = $request->get('category', 'unknown');

        if ($status == 'error') {
            if (in_array($category, $softBounceCategories)) {
                return $externalPages->recordSoftBounce([
                    'email'  => $email,
                    /* translators: %1$s: bounce category from Elastic Email, %2$s: date and time the event was recorded */
                    'reason' => sprintf(__('Soft bounce from the Elastic Email webhook API. Category: %1$s. Recorded at: %2$s', 'fluent-crm'), $category, current_time('mysql'))
                ]);
            }

            return $externalPages->recordUnsubscribe([
                'email'  => $email,
                /* translators: %1$s: bounce category from Elastic Email, %2$s: date and time the event was recorded */
                'reason' => sprintf(__('Bounced status was set by the Elastic Email webhook API. Category: %1$s. Recorded at: %2$s', 'fluent-crm'), $category, current_time('mysql')),
                'status' => 'bounced'
            ]);
        }

        if ($status == 'abusereport') {
            return $externalPages->recordUnsubscribe([
                'email'  => $email,
                /* translators: %s: date and time the event was recorded */
                'reason' => sprintf(__('Complained status was set by the Elastic Email webhook API. Recorded at: %s', 'fluent-crm'), current_time('mysql')),
                'status' => 'complained'
            ]);
        }

        // unsubscribed
        return $externalPages->recordUnsubscribe([
            'email'  => $email,
            /* translators: %s: date and time the event was recorded */
            'reason' => sprintf(__('Unsubscribed status was set by the Elastic Email webhook API. Recorded at: %s', 'fluent-crm'), current_time('mysql')),
            'status' => 'unsubscribed'
        ]);
    }

    private function handlePostalserver($request)
    {
        $event = strtolower($request->get('event'));

        $processStatuses = [
            'messagebounced',
            'messagedeliveryfailed',
            'messagedelayed'
        ];

        if (!in_array($event, $processStatuses)) {
            return false;
        }

        $payload = $request->get('payload');

        if (!$payload || !is_array($payload)) {
            return false;
        }

        $externalPages = new ExternalPages();

        if ($event == 'messagedeliveryfailed') {
            $payloadStatus = Arr::get($payload, 'status');
            $toEmail = Arr::get($payload, 'message.to');
            $reason = Arr::get($payload, 'details', 'Unknown Reason');

            if (!$toEmail || !is_email($toEmail)) {
                return false;
            }

            // SoftFail → record as soft bounce
            if ($payloadStatus != 'HardFail') {
                return $externalPages->recordSoftBounce([
                    'email'  => $toEmail,
                    /* translators: %1$s: bounce reason, %2$s: date and time the event was recorded */
                    'reason' => sprintf(__('Soft bounce from Postal Server. Reason: %1$s. Recorded at: %2$s', 'fluent-crm'), $reason, current_time('mysql'))
                ]);
            }

            return $externalPages->recordUnsubscribe([
                'email'  => $toEmail,
                'reason' => $reason,
                'status' => 'bounced'
            ]);
        }

        if ($event == 'messagedelayed') {
            $toEmail = Arr::get($payload, 'message.to');
            $reason = Arr::get($payload, 'details', 'Unknown Reason');

            if (!$toEmail || !is_email($toEmail)) {
                return false;
            }

            return $externalPages->recordSoftBounce([
                'email'  => $toEmail,
                /* translators: %1$s: bounce reason, %2$s: date and time the event was recorded */
                'reason' => sprintf(__('Soft bounce (delayed) from Postal Server. Reason: %1$s. Recorded at: %2$s', 'fluent-crm'), $reason, current_time('mysql'))
            ]);
        }

        // messagebounced: use original_message.to; bounce.to is the return-path.
        $toEmail = Arr::get($payload, 'original_message.to');
        if (!$toEmail || !is_email($toEmail)) {
            return false;
        }

        /* translators: %s: date and time the event was recorded */
        $reason = sprintf(__('Bounce notification received from Postal Server. Recorded at: %s', 'fluent-crm'), current_time('mysql'));

        return $externalPages->recordUnsubscribe([
            'email'  => $toEmail,
            'reason' => $reason,
            'status' => 'bounced'
        ]);
    }

    private function handleSmtp2go($request)
    {
        $event = strtolower($request->get('event'));

        $processStatuses = [
            'bounce',
            'spam',
            'unsubscribe'
        ];

        if (!in_array($event, $processStatuses)) {
            return false;
        }

        $toEmail = $request->get('rcpt');
        if (!$toEmail || !is_email($toEmail)) {
            return false;
        }

        $reason = sanitize_textarea_field($request->get('message', 'Unknown Reason'));
        $externalPages = new ExternalPages();

        if ($event == 'bounce') {
            $bounceType = $request->get('bounce');
            if ($bounceType == 'soft') {
                return $externalPages->recordSoftBounce([
                    'email'  => $toEmail,
                    /* translators: %1$s: bounce reason, %2$s: date and time the event was recorded */
                    'reason' => sprintf(__('Soft bounce from the SMTP2GO webhook API. Reason: %1$s. Recorded at: %2$s', 'fluent-crm'), $reason, current_time('mysql'))
                ]);
            }
        }

        $newStatus = 'bounced';
        if ($event == 'unsubscribe') {
            $newStatus = 'unsubscribed';
        } else if ($event == 'spam') {
            $newStatus = 'complained';
        }

        return $externalPages->recordUnsubscribe([
            'email'  => $toEmail,
            'reason' => $reason,
            'status' => $newStatus
        ]);
    }

    /**
     * @param $request \FluentCrm\Framework\Http\Request\Request
     * @return boolean
     */
    private function handleBrevo($request)
    {
        $event = $this->resolvePayload($request, []);

        if (!$event || !count($event)) {
            return false;
        }

        $eventName = Arr::get($event, 'event');
        if (!in_array($eventName, ['soft_bounce', 'hard_bounce', 'invalid', 'invalid_email', 'spam', 'error', 'blocked', 'deferred', 'unsubscribe', 'unsubscribed'])) {
            return false;
        }

        $email = Arr::get($event, 'email');
        if (!$email) {
            return false;
        }

        $externalPages = new ExternalPages();
        $reason = Arr::get($event, 'reason', '');

        // Soft bounces and deferred deliveries should be tracked separately
        if (in_array($eventName, ['soft_bounce', 'deferred'])) {
            $reasonMessage = $eventName === 'deferred'
                /* translators: %1$s: reason from Brevo, %2$s: date and time the event was recorded */
                ? sprintf(__('Deferred delivery from the Brevo webhook API. Reason: %1$s. Recorded at: %2$s', 'fluent-crm'), $reason, current_time('mysql'))
                /* translators: %1$s: bounce reason from Brevo, %2$s: date and time the event was recorded */
                : sprintf(__('Soft bounce from the Brevo webhook API. Reason: %1$s. Recorded at: %2$s', 'fluent-crm'), $reason, current_time('mysql'));
            return $externalPages->recordSoftBounce([
                'email'  => $email,
                'reason' => $reasonMessage
            ]);
        }

        $newStatus = 'bounced';
        if (in_array($eventName, ['unsubscribe', 'unsubscribed'])) {
            $newStatus = 'unsubscribed';
        } else if ($eventName == 'spam') {
            $newStatus = 'complained';
        }
        // hard_bounce, invalid, invalid_email, error, blocked → bounced

        return $externalPages->recordUnsubscribe([
            'email'  => $email,
            /* translators: %1$s: new contact status (bounced, complained or unsubscribed), %2$s: reason from Brevo, %3$s: date and time */
            'reason' => sprintf(__('Status %1$s was set by the Brevo webhook API. Reason: %2$s. Recorded at: %3$s', 'fluent-crm'), $newStatus, $reason, current_time('mysql')),
            'status' => $newStatus
        ]);
    }

    private function handleTosend($request)
    {
        $event = $this->resolvePayload($request, []);

        if (!$event || !is_array($event)) {
            return false;
        }

        // ToSend sends `bounced`; `bounce` is accepted for legacy payloads.
        $eventType = Arr::get($event, 'type');
        if (!in_array($eventType, ['bounce', 'bounced', 'complaint'])) {
            return false;
        }

        // ToSend nests event fields under `data`; fall back to the top level for flat payloads.
        $data = Arr::get($event, 'data');
        if (!$data || !is_array($data)) {
            $data = $event;
        }

        $email = Arr::get($data, 'email');
        if (!$email) {
            return false;
        }

        // Tolerate a "Display Name <addr>" recipient; ExternalPages matches on a bare address.
        if (strpos($email, '<') !== false && preg_match('/<([^>]+)>/', $email, $matches)) {
            $email = trim($matches[1]);
        }

        $externalPages = new ExternalPages();

        if ($eventType == 'complaint') {
            return $externalPages->recordUnsubscribe([
                'email'  => $email,
                /* translators: %s: date and time the event was recorded */
                'reason' => sprintf(__('Complaint received from the ToSend webhook API. Recorded at: %s', 'fluent-crm'), current_time('mysql')),
                'status' => 'complained'
            ]);
        }

        // Bounce event: check the is_hard_bounce flag.
        $reason = Arr::get($data, 'reason', 'Unknown');
        $isHardBounce = Arr::get($data, 'is_hard_bounce', false);

        // Sender-fault bounces do not penalise the subscriber; `bounce_sub_type` covers older payloads.
        $senderFaultSubTypes = ['MessageTooLarge', 'ContentRejected', 'AttachmentRejected'];
        $isSenderFault = (bool) Arr::get($data, 'sender_fault', false)
            || (!$isHardBounce && in_array(Arr::get($data, 'bounce_sub_type'), $senderFaultSubTypes, true));

        if ($isSenderFault) {
            return true;
        }

        if (!$isHardBounce) {
            return $externalPages->recordSoftBounce([
                'email'  => $email,
                /* translators: %1$s: bounce reason, %2$s: date and time the event was recorded */
                'reason' => sprintf(__('Soft bounce from the ToSend webhook API. Reason: %1$s. Recorded at: %2$s', 'fluent-crm'), $reason, current_time('mysql'))
            ]);
        }

        return $externalPages->recordUnsubscribe([
            'email'  => $email,
            /* translators: %1$s: bounce reason, %2$s: date and time the event was recorded */
            'reason' => sprintf(__('Hard bounce from the ToSend webhook API. Reason: %1$s. Recorded at: %2$s', 'fluent-crm'), $reason, current_time('mysql')),
            'status' => 'bounced'
        ]);
    }

    private function resolvePayload($request, $default = [])
    {
        $contentPayload = Helper::parseArrayOrJson($request->getContent(), []);

        if ($contentPayload) {
            return $contentPayload;
        }

        return Helper::parseArrayOrJson($request->get(), $default);
    }
}
