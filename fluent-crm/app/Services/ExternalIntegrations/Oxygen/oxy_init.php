<?php

(new \FluentCrm\App\Services\ExternalIntegrations\Oxygen\ConditionBuilder())->init();

/*
 * The conditions below decide which elements end up in the page HTML, and a full-page cache can
 * serve that HTML to every visitor. So they match only the logged-in user's contact, never one
 * identified by the email-click cookie (see Helper::getLoggedInContact()).
 */

function fcrmOxyCheckTagCondition($value, $operator)
{
    $contact = \FluentCrm\App\Services\Helper::getLoggedInContact();

    if(!$contact) {
        if($operator == 'exist') {
            return false;
        } else {
            return true;
        }
    }

    $contactTags = $contact->tags;

    if($operator == 'exist') {
        foreach ($contactTags as $tag) {
            if($tag->slug == $value) {
                return true;
            }
        }
        return false;
    }

    foreach ($contactTags as $tag) {
        if($tag->slug == $value) {
            return false;
        }
    }

    return true;
}

function fcrmOxyCheckStatusCondition($value, $operator)
{
    $contact = \FluentCrm\App\Services\Helper::getLoggedInContact();

    if(!$contact) {
        if($operator == '=') {
            return false;
        } else {
            return true;
        }
    }

    if($operator == '=') {
        return $contact->status == $value;
    }

    return $contact->status != $value;

}

function fcrmOxyCheckContactExistCondition($value, $operator)
{
    $contact = \FluentCrm\App\Services\Helper::getLoggedInContact();

    if($value == 'yes') {
        return !!$contact;
    }

    return !$contact;
}
