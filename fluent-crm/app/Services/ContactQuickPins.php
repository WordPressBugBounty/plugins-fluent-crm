<?php

namespace FluentCrm\App\Services;

use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;
use FluentCrm\Framework\Support\Arr;

/**
 * A user's pinned list and tag quick filters on the contacts list.
 * Stored as a site-scoped user option.
 * Shape: [['type' => 'list'|'tag', 'id' => int], ...], user's order, at most MAX.
 */
class ContactQuickPins
{
    const META_KEY = '_fcrm_contact_quick_pins';

    const MAX = 8;

    /**
     * The user's pins, minus any list or tag deleted since they were saved.
     *
     * @param int $userId
     * @return array<int, array{type: string, id: int}>
     */
    public static function get($userId)
    {
        if (!$userId) {
            return [];
        }

        return self::sanitize(get_user_option(self::META_KEY, $userId));
    }

    /**
     * Replace the user's pins.
     *
     * @param int $userId
     * @param mixed $pins Raw request input.
     * @return array The pins as stored.
     */
    public static function save($userId, $pins)
    {
        $pins = self::sanitize($pins);
        update_user_option($userId, self::META_KEY, $pins);

        return $pins;
    }

    /**
     * Normalize pins: known types, existing ids, no duplicates, order kept, at most MAX.
     *
     * @param mixed $pins
     * @return array<int, array{type: string, id: int}>
     */
    public static function sanitize($pins)
    {
        if (!is_array($pins)) {
            return [];
        }

        $candidates = [];
        foreach ($pins as $pin) {
            $pin = (array)$pin;
            $type = sanitize_key((string)Arr::get($pin, 'type', ''));
            $id = absint(Arr::get($pin, 'id', 0));
            if ($id && in_array($type, ['list', 'tag'], true)) {
                $candidates[$type . ':' . $id] = ['type' => $type, 'id' => $id];
            }
        }

        if (!$candidates) {
            return [];
        }

        $idsOf = function ($type) use ($candidates) {
            return array_values(array_map(function ($pin) {
                return $pin['id'];
            }, array_filter($candidates, function ($pin) use ($type) {
                return $pin['type'] === $type;
            })));
        };

        $listIds = $idsOf('list');
        $tagIds = $idsOf('tag');
        $existing = [
            'list' => $listIds ? array_map('intval', Lists::whereIn('id', $listIds)->pluck('id')->all()) : [],
            'tag'  => $tagIds ? array_map('intval', Tag::whereIn('id', $tagIds)->pluck('id')->all()) : [],
        ];

        $clean = array_filter($candidates, function ($pin) use ($existing) {
            return in_array($pin['id'], $existing[$pin['type']], true);
        });

        return array_slice(array_values($clean), 0, self::MAX);
    }
}
