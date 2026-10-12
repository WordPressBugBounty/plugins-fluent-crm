<?php

namespace FluentCrm\App\Services;

class BlockParserHelper
{
    private static $subscriber;

    public static function setSubscriber($subscriber)
    {
        static::$subscriber = $subscriber;
    }

    public static function getSubscriber()
    {
        return static::$subscriber;
    }

    private static $anonymousViewer = false;

    /**
     * Render as seen by an anonymous visitor (public share link, newsletter archive).
     *
     * Conditional content treats "no contact" as an admin preview and shows every section. A
     * public page has no contact either, so without this a "tagged VIP only" section was shown to
     * anyone with the link. While this runs, a missing contact counts as a contact with no tags.
     *
     * @param callable $callback
     * @return mixed the callback's result
     */
    public static function renderForAnonymousViewer(callable $callback)
    {
        $previous = static::$anonymousViewer;
        static::$anonymousViewer = true;

        try {
            return $callback();
        } finally {
            static::$anonymousViewer = $previous;
        }
    }

    /**
     * Whether conditional content without a contact is being rendered for an anonymous visitor
     * (true) or for an admin preview (false).
     *
     * @return bool
     */
    public static function isAnonymousViewer()
    {
        return static::$anonymousViewer;
    }
}
