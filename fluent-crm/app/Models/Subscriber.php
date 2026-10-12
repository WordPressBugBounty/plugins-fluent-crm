<?php

namespace FluentCrm\App\Models;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Services\ContactSearch;
use FluentCrm\App\Services\Helper;
use FluentCrm\App\Services\Sanitize;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\App\Services\Libs\Mailer\Handler;
use FluentCrm\Framework\Database\Orm\Collection;
use FluentCrm\Framework\Support\Str;
use FluentCrm\Framework\Database\QueryException;


/**
 *  Subscriber Model - DB Model for Contacts
 *
 *  Database Model
 *
 * @package FluentCrm\App\Models
 *
 * @version 1.0.0
 */
class Subscriber extends Model
{
    /**
     * Mirrors the DEFAULT on the fc_subscribers.status column.
     * A row inserted without an explicit status persists as 'subscribed', but the model
     * returned by self::create() has no status attribute; resolve it through this constant.
     */
    const DEFAULT_STATUS = 'subscribed';

    protected $table = 'fc_subscribers';

    protected $guarded = ['id'];

    protected $appends = ['full_name', 'photo'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'hash',
        'prefix',
        'first_name',
        'last_name',
        'user_id',
        'company_id',
        'email',
        'status', // pending / subscribed / bounced / unsubscribed; Default: subscriber
        'contact_type', // lead / customer
        'address_line_1',
        'address_line_2',
        'postal_code',
        'city',
        'state',
        'country',
        'phone',
        'timezone',
        'date_of_birth',
        'source',
        'life_time_value',
        'last_activity',
        'total_points',
        'latitude',
        'longitude',
        'ip',
        'created_at',
        'updated_at',
        'avatar'
    ];

    public static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->hash = md5($model->email);
        });

        static::updating(function ($model) {
            // Bulk imports skip the WP user sync unless a synced field changed.
            if (defined('FLUENTCRM_DOING_BULK_IMPORT') && !$model->isDirty(['first_name', 'last_name', 'email'])) {
                return;
            }

            if ($model->user_id && Helper::isUserSyncEnabled()) {
                $user = get_user_by('email', $model->email);

                $email_mismatch = false;

                if (!$user) {
                    $email_mismatch = true;
                    $user = get_user_by('ID', $model->user_id);
                }

                if ($user) {
                    if ($model->first_name && $model->first_name != $user->first_name) {
                        update_user_meta($user->ID, 'first_name', $model->first_name);
                    }
                    if ($model->last_name && $model->last_name != $user->last_name) {
                        update_user_meta($user->ID, 'last_name', $model->last_name);
                    }

                    /**
                     * Determine whether to update the WordPress user email when there is a mismatch.
                     *
                     * This filter allows you to control whether the WordPress user email should be updated
                     * when there is a mismatch between the subscriber email and the WordPress user email.
                     *
                     * @param bool Whether to update the WordPress user email. Default false.
                     * @since 2.3.1
                     *
                     */
                    if ($email_mismatch && apply_filters('fluentcrm_update_wp_user_email_on_change', false)) {
                        $user->user_email = $model->email;
                        wp_update_user($user);
                    }

                    $model->user_id = $user->ID; // in case user id mismatch
                }
            }
        });
    }

    /**
     * $searchable Columns in table to search
     * @var array
     */
    protected $searchable = [
        'email',
        'first_name',
        'last_name',
        'address_line_1',
        'address_line_2',
        'postal_code',
        'city',
        'state',
        'country',
        'phone',
        'status'
    ];

    /**
     * Local scope to filter subscribers by search/query string
     * @param \FluentCrm\Framework\Database\Query\Builder $query
     * @param string $search
     * @param boolean $custom_fields
     * @return \FluentCrm\Framework\Database\Query\Builder $query
     */
    public function scopeSearchBy($query, $search, $custom_fields = false)
    {
        // The whole search is one AND group, so it never widens surrounding filters.
        return ContactSearch::apply($query, (string)$search, [
            'include_custom_fields' => (bool)$custom_fields,
        ]);
    }

    /**
     * Local scope to filter subscribers by search/query string
     * @param \FluentCrm\Framework\Database\Query\Builder $query
     * @param array $statuses
     * @return \FluentCrm\Framework\Database\Query\Builder $query
     */
    public function scopeFilterByStatues($query, $statuses)
    {
        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

        return $query;
    }

    /**
     * Local scope to filter subscribers by contact type
     * @param \FluentCrm\Framework\Database\Query\Builder $query
     * @param array $statuses
     * @return \FluentCrm\Framework\Database\Query\Builder $query
     */
    public function scopeFilterByContactType($query, $type)
    {
        if ($type) {
            $query->where('contact_type', $type);
        }

        return $query;
    }

    /**
     * Local scope to filter subscribers by tags
     * @param \FluentCrm\Framework\Database\Query\Builder $query
     * @param array $keys
     * @param string $filterBy id/slug
     * @return \FluentCrm\Framework\Database\Query\Builder $query
     */
    public function scopeFilterByTags($query, $keys, $filterBy = 'id')
    {
        $prefix = 'fc_';

        $keys = Sanitize::sanitizeTagIds($keys, false);

        return $query->whereIn('id', function ($q) use ($prefix, $keys, $filterBy) {
            $q->from($prefix . 'tags')
                ->join(
                    $prefix . 'subscriber_pivot',
                    $prefix . 'subscriber_pivot.object_id',
                    '=',
                    $prefix . 'tags.id'
                )
                ->where($prefix . 'subscriber_pivot.object_type', 'FluentCrm\App\Models\Tag')
                ->whereIn($prefix . 'tags.' . $filterBy, $keys)
                ->groupBy($prefix . 'subscriber_pivot.subscriber_id')
                ->select($prefix . 'subscriber_pivot.subscriber_id');
        });
    }

    /**
     * Local scope to filter subscribers by not in tags
     * @param \FluentCrm\Framework\Database\Query\Builder $query
     * @param array $keys
     * @param string $filterBy id/slug
     * @return \FluentCrm\Framework\Database\Query\Builder $query
     */
    public function scopeFilterByNotInTags($query, $keys, $filterBy = 'id')
    {
        $prefix = 'fc_';

        $keys = Sanitize::sanitizeTagIds($keys, false);

        return $query->whereNotIn('id', function ($q) use ($prefix, $keys, $filterBy) {
            $q->from($prefix . 'tags')
                ->join(
                    $prefix . 'subscriber_pivot',
                    $prefix . 'subscriber_pivot.object_id',
                    '=',
                    $prefix . 'tags.id'
                )
                ->where($prefix . 'subscriber_pivot.object_type', 'FluentCrm\App\Models\Tag')
                ->whereIn($prefix . 'tags.' . $filterBy, $keys)
                ->groupBy($prefix . 'subscriber_pivot.subscriber_id')
                ->select($prefix . 'subscriber_pivot.subscriber_id');
        });
    }

    /**
     * Local scope to filter subscribers by lists
     * @param \FluentCrm\Framework\Database\Query\Builder $query
     * @param array $keys
     * @param string $filterBy id/slug
     * @return \FluentCrm\Framework\Database\Query\Builder $query
     */
    public function scopeFilterByLists($query, $keys, $filterBy = 'id')
    {
        $prefix = 'fc_';

        $keys = Sanitize::sanitizeListIds($keys, false);

        return $query->whereIn('id', function ($q) use ($prefix, $keys, $filterBy) {
            $q->from($prefix . 'lists')
                ->join(
                    $prefix . 'subscriber_pivot',
                    $prefix . 'subscriber_pivot.object_id',
                    '=',
                    $prefix . 'lists.id'
                )
                ->where($prefix . 'subscriber_pivot.object_type', 'FluentCrm\App\Models\Lists')
                ->whereIn($prefix . 'lists.' . $filterBy, $keys)
                ->groupBy($prefix . 'subscriber_pivot.subscriber_id')
                ->select($prefix . 'subscriber_pivot.subscriber_id');
        });
    }

    /**
     * Local scope to filter subscribers by not in lists
     * @param \FluentCrm\Framework\Database\Query\Builder $query
     * @param array $keys
     * @param string $filterBy id/slug
     * @return \FluentCrm\Framework\Database\Query\Builder $query
     */
    public function scopeFilterByNotInLists($query, $keys, $filterBy = 'id')
    {
        $prefix = 'fc_';

        $keys = Sanitize::sanitizeListIds($keys, false);

        return $query->whereNotIn('id', function ($q) use ($prefix, $keys, $filterBy) {
            $q->from($prefix . 'lists')
                ->join(
                    $prefix . 'subscriber_pivot',
                    $prefix . 'subscriber_pivot.object_id',
                    '=',
                    $prefix . 'lists.id'
                )
                ->where($prefix . 'subscriber_pivot.object_type', 'FluentCrm\App\Models\Lists')
                ->whereIn($prefix . 'lists.' . $filterBy, $keys)
                ->groupBy($prefix . 'subscriber_pivot.subscriber_id')
                ->select($prefix . 'subscriber_pivot.subscriber_id');
        });
    }

    /**
     * Many2Many: Subscriber belongs to many tags
     * @return \FluentCrm\Framework\Database\Orm\Relations\BelongsToMany
     */
    public function tags()
    {
        $class = __NAMESPACE__ . '\Tag';

        return $this->belongsToMany(
            $class,
            'fc_subscriber_pivot',
            'subscriber_id',
            'object_id'
        )
            ->wherePivot('object_type', $class)
            ->withPivot('object_type')
            ->orderBy('title', 'ASC')
            ->withTimestamps();
    }

    public function company()
    {
        return $this->belongsTo(__NAMESPACE__ . '\Company', 'company_id', 'id');
    }

    public function companies()
    {
        $class = __NAMESPACE__ . '\Company';

        return $this->belongsToMany(
            $class,
            'fc_subscriber_pivot',
            'subscriber_id',
            'object_id'
        )
            ->wherePivot('object_type', $class)
            ->withPivot('object_type')
            ->withTimestamps();
    }

    /**
     * Local scope to filter subscribers by companies
     * @param \FluentCrm\Framework\Database\Query\Builder $query
     * @param array $keys
     * @param string $filterBy id/slug
     * @return \FluentCrm\Framework\Database\Query\Builder $query
     */
    public function scopeFilterByCompanies($query, $keys, $filterBy = 'id')
    {
        $prefix = 'fc_';

        return $query->whereIn('id', function ($q) use ($prefix, $keys, $filterBy) {
            $q->from($prefix . 'companies')
                ->join(
                    $prefix . 'subscriber_pivot',
                    $prefix . 'subscriber_pivot.object_id',
                    '=',
                    $prefix . 'companies.id'
                )
                ->where($prefix . 'subscriber_pivot.object_type', 'FluentCrm\App\Models\Company')
                ->whereIn($prefix . 'companies.' . $filterBy, $keys)
                ->groupBy($prefix . 'subscriber_pivot.subscriber_id')
                ->select($prefix . 'subscriber_pivot.subscriber_id');
        });
    }

    /**
     * Many2Many: Subscriber has many email sequences
     * @return \FluentCrm\Framework\Database\Orm\Relations\BelongsToMany
     */
    public function sequences()
    {
        $class = '\FluentCampaign\App\Models\Sequence';

        return $this->belongsToMany(
            $class,
            'fc_sequence_tracker',
            'subscriber_id',
            'campaign_id'
        )
            ->withoutGlobalScopes()
            ->withTimestamps();
    }

    /**
     * Many2Many: Subscriber has many sequence trackers
     * @return \FluentCrm\Framework\Database\Orm\Relations\HasMany
     */
    public function sequence_trackers()
    {
        $class = '\FluentCampaign\App\Models\SequenceTracker';

        return $this->hasMany(
            $class,
            'subscriber_id',
            'id'
        );
    }

    /**
     * Many2Many: Subscriber has many funnels
     * @return \FluentCrm\Framework\Database\Orm\Relations\BelongsToMany
     */
    public function funnels()
    {
        $class = __NAMESPACE__ . '\Funnel';

        return $this->belongsToMany(
            $class,
            'fc_funnel_subscribers',
            'subscriber_id',
            'funnel_id'
        )
            ->withoutGlobalScopes()
            ->withTimestamps();
    }

    /**
     * Many2Many: Subscriber has many funnel subscribers
     * @return \FluentCrm\Framework\Database\Orm\Relations\hasMany
     */
    public function funnel_subscribers()
    {
        return $this->hasMany(
            __NAMESPACE__ . '\FunnelSubscriber',
            'subscriber_id',
            'id'
        );
    }

    /**
     * hasMany: Subscriber has many commerce items
     * @return \FluentCrm\Framework\Database\Orm\Relations\HasMany
     */
    public function contact_commerce()
    {
        $class = '\FluentCampaign\App\Services\Commerce\ContactRelationModel';
        return $this->hasMany($class, 'subscriber_id', 'id');
    }

    /**
     * hasOne: Subscriber has a commerce for a specific provider
     * @return \FluentCrm\Framework\Database\Orm\Relations\hasOne
     */
    public function commerce_by_provider()
    {
        $class = '\FluentCampaign\App\Services\Commerce\ContactRelationModel';
        return $this->hasOne($class, 'subscriber_id', 'id');
    }


    /**
     * hasOne: Subscriber has many commerce items
     * @return \FluentCrm\Framework\Database\Orm\Relations\HasMany
     */
    public function contact_commerce_items()
    {
        $class = '\FluentCampaign\App\Services\Commerce\ContactRelationItemsModel';
        return $this->hasMany($class, 'subscriber_id', 'id');
    }

    public function scopeCommerceItemsItemIds($query, $provider, $method, $column, $values)
    {
        $query->whereHas('contact_commerce_items', $provider)
            ->{$method}($column, $values);
    }

    public function affiliate_wp()
    {
        $class = '\FluentCampaign\App\Services\Integrations\AffiliateWP\AffiliateWPModel';
        return $this->hasOne($class, 'user_id', 'user_id');
    }

    /**
     * Many2Many: Subscriber belongs to many lists
     * @return Model Collection
     */
    public function lists()
    {
        $class = __NAMESPACE__ . '\Lists';

        return $this->belongsToMany(
            $class,
            'fc_subscriber_pivot',
            'subscriber_id',
            'object_id'
        )
            ->wherePivot('object_type', $class)
            ->withPivot('object_type')
            ->orderBy('title', 'ASC')
            ->withTimestamps();
    }


    /**
     * One2Many: Subscriber has to many SubscriberMeta
     * @return Model Collection
     */
    public function meta()
    {
        $class = __NAMESPACE__ . '\SubscriberMeta';
        return $this->hasMany(
            $class,
            'subscriber_id',
            'id'
        );
    }

    /**
     * One2Many: Subscriber has to many SubscriberMeta
     * @return Model Collection
     */
    public function custom_field_meta()
    {
        $class = __NAMESPACE__ . '\SubscriberMeta';
        return $this->hasMany(
            $class,
            'subscriber_id',
            'id'
        )->where('object_type', '=', 'custom_field');
    }

    /**
     * One2Many: Subscriber has to many Click Metrics
     * @return Model Collection
     */
    public function urlMetrics()
    {
        $class = __NAMESPACE__ . '\CampaignUrlMetric';

        return $this->hasMany(
            $class,
            'subscriber_id',
            'id'
        );
    }

    /**
     * A subscriber has many campaign emails.
     *
     * @return \FluentCrm\Framework\Database\Orm\Relations\HasMany
     */
    public function campaignEmails()
    {
        return $this->hasMany(CampaignEmail::class, 'subscriber_id', 'id');
    }

    /**
     * A subscriber has many notes and activities.
     *
     * @return \FluentCrm\Framework\Database\Orm\Relations\HasMany
     */
    public function notes()
    {
        return $this->hasMany(SubscriberNote::class, 'subscriber_id', 'id');
    }

    /**
     * A subscriber has many tracking events.
     *
     * @return \FluentCrm\Framework\Database\Orm\Relations\HasMany
     */
    public function trackingEvents()
    {
        return $this->hasMany(EventTracker::class, 'subscriber_id', 'id');
    }

    /**
     * One2Many: Subscriber has to many custom fields value
     * @return array
     */
    public function custom_fields()
    {
        $customFields = fluentcrm_get_custom_contact_fields();

        if (!$customFields || !is_array($customFields)) {
            return [];
        }

        $keys = array_map(function ($item) {
            return $item['slug'];
        }, $customFields);


        if (!$keys) {
            return [];
        }

        $items = $this->custom_field_meta()->whereIn('key', $keys)->get();
        $formattedValues = [];
        foreach ($items as $item) {
            $formattedValues[$item->key] = apply_filters('fluent_crm/modify_custom_field_value', $item->value);
        }
        return $formattedValues;
    }

    /**
     * Update Custom Field Values
     * @param $values array of custom values
     * @param bool $deleteOtherValues
     * @param array $customFields optional pre-loaded field definitions keyed by slug
     * @return array of updated values
     */
    public function syncCustomFieldValues($values, $deleteOtherValues = true, $customFields = [])
    {
        // Every custom-field write passes through here; Sanitize::contact() does not cover these keys.
        $values = Sanitize::contactCustomValues($values, $customFields);

        $emptyValues = array_filter($values, function ($value) {
            return $value === '';
        });

        $updateValues = [];

        if ($deleteOtherValues) {
            $deleteMetaKeys = array_keys($emptyValues);

            if ($deleteMetaKeys) {
                // Only a key that had a stored value changed; clearing one that was never set is a no-op.
                $clearedKeys = $this->custom_field_meta()->whereIn('key', $deleteMetaKeys)->pluck('key')->toArray();
                if ($clearedKeys) {
                    $this->custom_field_meta()->whereIn('key', $clearedKeys)->delete();
                }
                foreach (array_unique($clearedKeys) as $key) {
                    $updateValues[$key] = '';
                }
            }
        }

        $newValues = array_filter($values, function ($value) {
            return $value !== '';
        });

        foreach ($newValues as $key => $value) {
            // Scoped to object_type = 'custom_field' so internal meta keys of the same name are not overwritten.
            $exist = $this->custom_field_meta()->where('key', $key)->first();
            if ($exist) {
                // Compare as text: PHP 8's == treats '123' and '0123' (or '5' and '5.0') as equal.
                if ((string)maybe_serialize($exist->value) === (string)maybe_serialize($value)) {
                    continue;
                }
                $updateValues[$key] = $value;
                $exist->fill(['value' => $value])->save();
            } else {
                $meta = new SubscriberMeta();
                $meta->fill([
                    'subscriber_id' => $this->id,
                    'object_type'   => 'custom_field',
                    'key'           => $key,
                    'value'         => $value,
                    'created_by'    => get_current_user_id()
                ]);
                $meta->save();
                $updateValues[$key] = $value;
            }
        }

        if ($updateValues) {
            do_action('fluentcrm_contact_custom_data_updated', $newValues, $this, $updateValues);
            do_action('fluent_crm/contact_custom_data_updated', $newValues, $this, $updateValues);
        }

        return $updateValues;
    }

    public function stats()
    {
        return [
            'emails' => CampaignEmail::where('subscriber_id', $this->id)
                ->whereIn('status', CampaignEmail::SENT_STATUSES)
                ->count(),
            'opens'  => CampaignEmail::where('subscriber_id', $this->id)
                ->where('is_open', '>', 0)
                ->where('status', 'sent')
                ->count(),
            'clicks' => CampaignEmail::where('subscriber_id', $this->id)
                ->whereNotNull('click_counter')
                ->where('status', 'sent')
                ->count()
        ];
    }

    /**
     * Save the subscriber.
     *
     * @param array $data
     */
    public static function store($data = [])
    {
        $model = static::create($data);

        if ($customValues = Arr::get($data, 'custom_values')) {
            $model->syncCustomFieldValues($customValues);
        }

        $tagIds = Arr::get($data, 'tags', []);
        if ($tagIds) {
            $model->attachTags($tagIds);
        }

        $listIds = Arr::get($data, 'lists', []);
        if ($listIds) {
            $model->attachLists($listIds);
        }

        $companyId = Arr::get($data, 'company_id');
        if ($companyId && Helper::isExperimentalEnabled('company_module')) {
            $model->attachCompanies([$companyId]);
        }

        return $model;
    }

    /**
     * Get subscriber mappable fields.
     *
     * @return array
     */
    public static function mappables()
    {
        $fields = [
            'prefix'         => __('Name Prefix', 'fluent-crm'),
            'first_name'     => __('First name', 'fluent-crm'),
            'last_name'      => __('Last name', 'fluent-crm'),
            'full_name'      => __('Full Name', 'fluent-crm'),
            'email'          => __('Email', 'fluent-crm'),
            'contact_type'   => __('Contact Type', 'fluent-crm'),
            'timezone'       => __('Timezone', 'fluent-crm'),
            'address_line_1' => __('Address Line 1', 'fluent-crm'),
            'address_line_2' => __('Address Line 2', 'fluent-crm'),
            'city'           => __('City', 'fluent-crm'),
            'state'          => __('State', 'fluent-crm'),
            'postal_code'    => __('Postal code', 'fluent-crm'),
            'country'        => __('Country', 'fluent-crm'),
            'ip'             => __('IP Address', 'fluent-crm'),
            'phone'          => __('Phone', 'fluent-crm'),
            'source'         => __('Source', 'fluent-crm'),
            'date_of_birth'  => __('Date of Birth (Y-m-d format only)', 'fluent-crm')
        ];

        if (Helper::isCompanyEnabled()) {
            $fields['company_id'] = __('Primary Company', 'fluent-crm');
        }

        return $fields;
    }

    /**
     * Accessor to get dynamic photo attribute
     * @return string
     */
    public function getPhotoAttribute()
    {
        if (!empty($this->attributes['avatar'])) {
            return $this->attributes['avatar'];
        }

        if (empty($this->attributes['email'])) {
            return '';
        }

        $fallBack = '';
        if (isset($this->attributes['first_name'])) {
            $fallBack = $this->attributes['first_name'];
        }

        if (isset($this->attributes['last_name'])) {
            $fallBack .= ' ' . $this->attributes['last_name'];
        }

        return fluentcrmGravatar($this->attributes['email'], trim($fallBack));
    }

    /**
     * Accessor to get dynamic full_name attribute
     * @return string
     */
    public function getFullNameAttribute()
    {
        $fname = isset($this->attributes['first_name']) ? $this->attributes['first_name'] : '';
        $lname = isset($this->attributes['last_name']) ? $this->attributes['last_name'] : '';
        return trim("{$fname} {$lname}");
    }

    /**
     * Import csv/wpusers into subscribers
     * @param array $data
     * @param array $tags
     * @param array $lists
     * @param mixed $update string true/false or boolean true/false
     * @param string $newStatus status for the new subscribers
     * @param boolean $doubleOptin Send Double Optin Emails for new pending contacts
     * @param string $source Fallback Source for New Contacts
     * @return array affected records/collection
     */
    public static function import($data, $tags, $lists, $update, $newStatus = '', $doubleOptin = false, $forceStatusChange = false, $source = '')
    {
        if (!defined('FLUENTCRM_DOING_BULK_IMPORT')) {
            define('FLUENTCRM_DOING_BULK_IMPORT', true);
        }

        ob_start();
        $insertables = [];
        $updateables = [];
        $updatedModels = new Collection;
        $shouldUpdate = $update === 'true' || $update === true;

        $records = [];

        $uniqueEmails = [];

        foreach ($data as $index => $record) {

            $email = $record['email'];

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || in_array(strtolower($email), $uniqueEmails)) {
                unset($data[$index]);
                continue;
            }

            $uniqueEmails[] = strtolower($email);

            $record = self::explodeFullName($record);
            $data[$index] = $record;

            $records[] = $email;
        }

        $existingSubscribers = [];
        $oldSubscribers = static::whereIn('email', $records)->get();

        foreach ($oldSubscribers as $model) {
            $existingSubscribers[strtolower($model->email)] = $model;
        }

        // Preload existing tag/list pivots for the whole chunk in one query.
        $existingListIdMap = [];
        $existingTagIdMap = [];
        if (!$oldSubscribers->isEmpty()) {
            $existingIds = $oldSubscribers->pluck('id')->toArray();
            $pivotRows = fluentCrmDb()->table('fc_subscriber_pivot')
                ->whereIn('subscriber_id', $existingIds)
                ->whereIn('object_type', ['FluentCrm\App\Models\Lists', 'FluentCrm\App\Models\Tag'])
                ->get();

            foreach ($pivotRows as $pivotRow) {
                if ($pivotRow->object_type == 'FluentCrm\App\Models\Lists') {
                    $existingListIdMap[$pivotRow->subscriber_id][] = $pivotRow->object_id;
                } else {
                    $existingTagIdMap[$pivotRow->subscriber_id][] = $pivotRow->object_id;
                }
            }
        }

        $strictStatuses = fluentcrm_strict_statues();

        $newContactCustomFields = [];
        $newRecords = [];
        $skips = [];
        $newLists = [];
        $newTags = [];
        foreach ($data as $item) {
            $item['hash'] = md5($item['email']);
            $lowEmail = strtolower($item['email']);
            if (isset($existingSubscribers[$lowEmail])) {
                if (!$forceStatusChange && $newStatus && !in_array($newStatus, $strictStatuses)) {
                    $item['status'] = $existingSubscribers[$lowEmail]->status;
                } else if ($newStatus) {
                    $item['status'] = $newStatus;
                }

                unset($item['source']);

                $customValues = Arr::get($item, 'custom_values');
                if ($shouldUpdate && $customValues) {
                    $existingSubscribers[$lowEmail]->syncCustomFieldValues($customValues, false);
                }

                //if item has lists or tags that need to be processed then mapping to email
                $existingSubscriberId = $existingSubscribers[$lowEmail]->id;
                if (Arr::get($item, 'lists')) {
                    $existingListIdsOfUser = isset($existingListIdMap[$existingSubscriberId]) ? $existingListIdMap[$existingSubscriberId] : [];
                    $newLists[$item['email']] = Helper::getNewAttachableLists(Arr::get($item, 'lists'), $existingListIdsOfUser, $lists);
                }
                if (Arr::get($item, 'tags')) {
                    $existingTagIdsOfUser = isset($existingTagIdMap[$existingSubscriberId]) ? $existingTagIdMap[$existingSubscriberId] : [];
                    $newTags[$item['email']] = Helper::getNewAttachableTags(Arr::get($item, 'tags'), $existingTagIdsOfUser, $tags);
                }

                unset($item['custom_values']);
                unset($item['id']);
                unset($item['created_at']);
                unset($item['lists']);
                unset($item['tags']);

                $item['updated_at'] = fluentCrmTimestamp();
                // Drop only empty strings and nulls; keep falsy values like "0".
                $updateables[] = array_filter($item, function ($value) {
                    return $value !== '' && $value !== null;
                });
            } else {
                if (isset($newRecords[$item['email']])) {
                    $skips[] = $item;
                    continue;
                }
                $extraValues = [
                    'created_at' => fluentCrmTimestamp()
                ];
                if ($newStatus) {
                    $extraValues['status'] = $newStatus;
                }

                if ($customValues = Arr::get($item, 'custom_values')) {
                    $newContactCustomFields[$item['email']] = $customValues;
                }

                //if item has lists or tags that need to be processed then mapping to email
                if (Arr::get($item, 'lists')) {
                    $newLists[$item['email']] = Arr::get($item, 'lists');
                }
                if (Arr::get($item, 'tags')) {
                    $newTags[$item['email']] = Arr::get($item, 'tags');
                }

                $itemEmail = $item['email'];

                unset($item['custom_values']);
                unset($item['id']);
                unset($item['lists']);
                unset($item['tags']);

                if (empty($item['source']) && $source) {
                    $item['source'] = $source;
                }

                $newRecords[$itemEmail] = 1;
                $insertables[] = array_filter(array_merge($item, $extraValues), function ($value) {
                    return $value !== '' && $value !== null;
                });
            }
        }

        $insertedModels = [];
        if ($insertables) {
            foreach ($insertables as $insertable) {
                $attachableTags = $tags;
                $attachableLists = $lists;
                $insertedModel = self::create($insertable);
                if ($newContactCustomFields) {
                    if (isset($newContactCustomFields[$insertedModel->email])) {
                        $insertedModel->syncCustomFieldValues(
                            $newContactCustomFields[$insertedModel->email],
                            false
                        );
                    }
                }

                if (!empty($newTags[$insertedModel->email])) {
                    $newlyCreateTagIds = Helper::createNewTags($newTags[$insertedModel->email]);
                    $attachableTags = array_merge($tags, $newlyCreateTagIds);
                }

                if (!empty($newLists[$insertedModel->email])) {
                    $newlyCreateListIds = Helper::createNewLists($newLists[$insertedModel->email]);
                    $attachableLists = array_merge($lists, $newlyCreateListIds);
                }

                if ($attachableTags || $attachableLists || $doubleOptin) {
                    $attachableTags && $insertedModel->attachTags($attachableTags);
                    $attachableLists && $insertedModel->attachLists($attachableLists);

                    // A row inserted without a status persists with the column default.
                    $importedStatus = Arr::get($insertable, 'status') ?: self::DEFAULT_STATUS;

                    if ($doubleOptin && $importedStatus == 'pending') {
                        $insertedModel->sendDoubleOptinEmail();
                    }
                }


                if (!empty($insertable['company_id']) && Helper::isCompanyEnabled()) {
                    $insertedModel->attachCompanies([$insertable['company_id']]);
                }

                // "Import silently" also suppresses the contact-created triggers.
                if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                    /*
                     * @deprecated since 2.8.0. Use fluent_crm/contact_created instead
                     */
                    do_action('fluentcrm_contact_created', $insertedModel);
                    do_action('fluent_crm/contact_created', $insertedModel);
                }

                $insertedModels[] = $insertedModel;
            }
        }

        if ($shouldUpdate) {
            foreach ($updateables as $updateable) {
                $existingModel = $existingSubscribers[strtolower($updateable['email'])];
                $oldStatus = $existingModel->status;
                $existingModel->fill($updateable);

                $updateData = $existingModel->getDirty();

                if ($updateData) {
                    $existingModel->save();

                    if (!empty($updateable['status']) && $updateable['status'] != $oldStatus) {
                        $newStatus = $updateable['status'];
                        do_action('fluent_crm/subscriber_status_changed', $existingModel, $oldStatus, $newStatus);
                        do_action('fluentcrm_subscriber_status_to_' . $newStatus, $existingModel, $oldStatus);
                    }
                }

                // The row's company, lists and tags apply even when none of its fields changed. A row
                // whose company is already the contact's has it attached: skip the pivot write
                // (one INSERT and reload per row on big re-imports).
                if (!empty($updateable['company_id']) && Helper::isCompanyEnabled()
                    && ($updateData || (int)$existingModel->company_id !== (int)$updateable['company_id'])) {
                    $existingModel->attachCompanies([$updateable['company_id']]);
                }

                if (!empty($newLists[$updateable['email']])) {
                    $existingModel->attachLists($newLists[$updateable['email']]);
                }
                if (!empty($newTags[$updateable['email']])) {
                    $existingModel->attachTags($newTags[$updateable['email']]);
                }

                if ($updateData) {
                    do_action('fluentcrm_contact_updated', $existingModel, $updateData);
                    do_action('fluent_crm/contact_updated', $existingModel, $updateData);
                }

                $updatedModels->push($existingModel);
            }
        }

        // Syncing Tags & Lists
        if ($tags || $lists || $doubleOptin) {
            if ($shouldUpdate) {
                foreach ($oldSubscribers as $model) {
                    $tags && $model->attachTags($tags);
                    $lists && $model->attachLists($lists);
                }
            }
        }

        do_action('fluentcrm_contacts_imported_bulk', $insertedModels);
        do_action('fluentcrm_contacts_updated_bulk', $updatedModels);

        $errors = ob_get_clean();

        return [
            'inserted' => $insertedModels,
            'updated'  => $updatedModels,
            'skips'    => $skips,
            'errors'   => $errors
        ];
    }

    public function updateOrCreate($data, $forceUpdate = false, $deleteOtherValues = false, $sync = false)
    {
        return $this->persistContact($data, $forceUpdate, $deleteOtherValues, $sync);
    }

    /**
     * Apply normal contact writes, optionally to the winner of an email insert race.
     * Passing the winning row forces recovery through the update branch only.
     */
    protected function persistContact($data, $forceUpdate, $deleteOtherValues, $sync, $existingContact = null)
    {
        $subscriberData = static::explodeFullName($data);
        $subscriberData = array_filter(Arr::only($subscriberData, $this->getFillable()));
        $tags = Arr::get($data, 'tags', []);
        $lists = Arr::get($data, 'lists', []);
        $companies = Arr::get($data, 'companies', []);

        $exist = $existingContact ?: static::where('email', $subscriberData['email'])->first();

        if (empty($subscriberData['user_id'])) {
            $user = get_user_by('email', $subscriberData['email']);
            if ($user) {
                $subscriberData['user_id'] = $user->ID;
            }
        }

        $isNew = true;
        $oldStatus = '';
        if ($exist) {
            $isNew = false;
            $oldStatus = $exist->status;
        }

        // Loose spellings ("Unsubscribed", "pending ") are read as the status they name. A status
        // that is still not a FluentCRM status (e.g. Fluent Support's "active") is ignored for an
        // existing contact, and makes a new one pending, never subscribed: consent is not assumed.
        if (!empty($data['status']) && is_string($data['status'])) {
            $data['status'] = strtolower(trim($data['status']));
            if (isset($subscriberData['status'])) {
                $subscriberData['status'] = $data['status'];
            }
        }
        if (!empty($data['status']) && !in_array($data['status'], fluentcrm_subscriber_statuses(), true)) {
            unset($data['status'], $subscriberData['status']);
            if (!$exist) {
                $data['status'] = $subscriberData['status'] = 'pending';
            }
        }

        if (!empty($data['status'])) {
            $status = $data['status'];

            if ($forceUpdate) {
                // A forced write is applied unconditionally, including out of suppression.
                $subscriberData['status'] = $status;
            } else if (in_array($status, fluentcrm_strict_statues())) {
                // Moving into a suppressed status is always allowed.
                $subscriberData['status'] = $status;
            } else if ($exist && in_array($exist->status, array_merge(fluentcrm_strict_statues(), ['subscribed']))) {
                // A non-forced write never downgrades a subscribed contact or resurrects a suppressed one.
                unset($subscriberData['status']);
            } else {
                $subscriberData['status'] = $status;
            }
        }

        $dirtyFields = [];

        if ($exist) {
            $oldEmail = $exist->email;
            $exist->fill($subscriberData);
            $dirtyFields = $exist->getDirty();

            if ($dirtyFields) {
                $exist->save();
                if (isset($dirtyFields['email'])) {
                    do_action('fluent_crm/contact_email_changed', $exist, $oldEmail);
                }
            }
        } else {
            if (!isset($subscriberData['created_at'])) {
                $subscriberData['created_at'] = current_time('mysql');
            }
            try {
                $exist = static::create($subscriberData);
            } catch (QueryException $e) {
                // A concurrent request may have inserted this email between our lookup
                // and insert. If a row now exists, apply this payload to it as an update;
                // otherwise the failure is unrelated and must surface.
                $winner = static::where('email', $subscriberData['email'])->first();
                if (!$winner) {
                    throw $e;
                }

                return $this->persistContact($data, $forceUpdate, $deleteOtherValues, $sync, $winner);
            }
            $exist = $this->find($exist->id);
            $exist->wasRecentlyCreated = true;
        }

        $customFieldsChanges = [];
        if ($customValues = Arr::get($data, 'custom_values', [])) {
            $customFieldsChanges = $exist->syncCustomFieldValues($customValues, $deleteOtherValues);
        }

        /*
         * TODO: investigate this attachTags and attachLists method. for Masiur
         */
        if ($lists) {
            $exist->attachLists($lists);
        }

        if ($tags) {
            $exist->attachTags($tags);
        }


        if (Helper::isCompanyEnabled()) {
            $companyId = $exist->company_id;
            if (empty($companyId)) {
                $companies && $exist->attachCompanies($companies);
            } else {
                $exist->attachCompanies([$companyId]);
            }
        }

        if ($detachTags = Arr::get($data, 'detach_tags', [])) {
            $exist->detachTags($detachTags);
        }

        if ($detachLists = Arr::get($data, 'detach_lists', [])) {
            $exist->detachLists($detachLists);
        }

        if ($isNew) {
            do_action('fluentcrm_contact_created', $exist); // @deprecated since 2.8.0. Use fluent_crm/contact_created instead
            do_action('fluent_crm/contact_created', $exist);
        } else if ($dirtyFields || $customFieldsChanges) {
            do_action('fluentcrm_contact_updated', $exist, $dirtyFields); // @deprecated since 2.8.0. Use fluent_crm/contact_updated instead
            do_action('fluent_crm/contact_updated', $exist, $dirtyFields);
        }

        // Status hooks fire for every real transition; Cleanup@handleUnsubscribe depends on them.
        if (!$isNew && $oldStatus && $oldStatus != $exist->status) {
            do_action('fluent_crm/subscriber_status_changed', $exist, $oldStatus, $exist->status);
            do_action('fluentcrm_subscriber_status_to_' . $exist->status, $exist, $oldStatus);
        } else if ($isNew && !empty($subscriberData['status']) && $subscriberData['status'] === 'subscribed') {
            // Fires only when the status was set explicitly, not inherited from the DB default.
            do_action('fluentcrm_subscriber_status_to_subscribed', $exist, $oldStatus);
        }

        return $exist;
    }

    public function sendDoubleOptinEmail()
    {
        if ($this->status == 'subscribed') {
            // Any non-subscribed status may be re-invited; the 150s throttle bounds abuse.
            return false;
        }

        $lastDoubleOptin = fluentcrm_get_subscriber_meta($this->id, '_last_double_optin_timestamp');
        if ($lastDoubleOptin && (time() - $lastDoubleOptin < 150)) {
            return false;
        }

        $isSent = (new Handler())->sendDoubleOptInEmail($this);

        // The resend throttle is consumed only when an email actually went out.
        if ($isSent) {
            fluentcrm_update_subscriber_meta($this->id, '_last_double_optin_timestamp', time());
        }

        return $isSent;
    }

    public static function explodeFullName($record)
    {
        if (!empty($record['first_name']) || !empty($record['last_name'])) {
            return $record;
        }
        if (!empty($record['full_name'])) {
            $fullNameArray = explode(' ', $record['full_name']);
            $record['first_name'] = array_shift($fullNameArray);
            if ($fullNameArray) {
                $record['last_name'] = implode(' ', $fullNameArray);
            }
            unset($record['full_name']);
        }

        return $record;
    }

    public function attachLists($listIds)
    {
        if (!$listIds) {
            return $this;
        }

        // Unsaved subscriber: skip before sanitizeListIds(), which can create lists.
        if (empty($this->id)) {
            return $this;
        }

        $listIds = Sanitize::sanitizeListIds($listIds);
        $listIds = array_filter(array_map('intval', $listIds));

        if (!$listIds) {
            return $this;
        }

        global $wpdb;
        $pivotTable = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType = 'FluentCrm\App\Models\Lists';

        // Per-row INSERT IGNORE on the unique pivot key; the event fires only for rows actually inserted.
        // Timestamps use site time to match freshTimestamp().
        $now = current_time('mysql');
        $newListIds = [];

        foreach ($listIds as $listId) {
            $affected = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$pivotTable} (subscriber_id, object_id, object_type, created_at, updated_at) VALUES (%d, %d, %s, %s, %s)",
                $this->id, $listId, $objectType, $now, $now
            ));
            // false means a DB error (deadlock, lock wait), not an existing row.
            if ($affected === false) {
                Helper::debugLog('Subscriber::attachLists pivot insert failed', $wpdb->last_error, 'error');
                continue;
            }
            if ($affected > 0) {
                $newListIds[] = $listId;
            }
        }

        // Always refresh the relation, even on the no-op path.
        $this->load('lists');

        if ($newListIds) {
            fluentcrm_contact_added_to_lists($newListIds, $this);

            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                do_action('fluent_crm/contact_added_to_lists', $this, $newListIds);
            }
        }

        return $this;
    }

    public function attachTags($tagIds)
    {
        if (!$tagIds) {
            return $this;
        }

        if (empty($this->id)) {
            return $this;
        }

        $tagIds = Sanitize::sanitizeTagIds($tagIds);
        $tagIds = array_filter(array_map('intval', $tagIds));

        if (!$tagIds) {
            return $this;
        }

        global $wpdb;
        $pivotTable = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType = 'FluentCrm\App\Models\Tag';

        $now = current_time('mysql');
        $newTagIds = [];

        foreach ($tagIds as $tagId) {
            $affected = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$pivotTable} (subscriber_id, object_id, object_type, created_at, updated_at) VALUES (%d, %d, %s, %s, %s)",
                $this->id, $tagId, $objectType, $now, $now
            ));
            if ($affected === false) {
                Helper::debugLog('Subscriber::attachTags pivot insert failed', $wpdb->last_error, 'error');
                continue;
            }
            if ($affected > 0) {
                $newTagIds[] = $tagId;
            }
        }

        $this->load('tags');

        if ($newTagIds) {
            fluentcrm_contact_added_to_tags($newTagIds, $this);

            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                do_action('fluent_crm/contact_added_to_tags', $this, $newTagIds);
            }
        }

        return $this;
    }

    public function attachCompanies($companyIds)
    {
        if (!$companyIds) {
            return $this;
        }

        if (empty($this->id)) {
            return $this;
        }

        $companyIds = array_filter(array_map('intval', $companyIds));

        if (!$companyIds) {
            return $this;
        }

        global $wpdb;
        $pivotTable = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType = 'FluentCrm\App\Models\Company';

        $now = current_time('mysql');
        $newCompanyIds = [];

        foreach ($companyIds as $companyId) {
            $affected = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$pivotTable} (subscriber_id, object_id, object_type, created_at, updated_at) VALUES (%d, %d, %s, %s, %s)",
                $this->id, $companyId, $objectType, $now, $now
            ));
            if ($affected === false) {
                Helper::debugLog('Subscriber::attachCompanies pivot insert failed', $wpdb->last_error, 'error');
                continue;
            }
            if ($affected > 0) {
                $newCompanyIds[] = $companyId;
            }
        }

        $this->load('companies');

        if ($newCompanyIds) {
            fluentcrm_contact_added_to_companies($newCompanyIds, $this);
        }

        return $this;
    }

    public function detachLists($listIds)
    {
        if (!$listIds) {
            return $this;
        }

        if (empty($this->id)) {
            return $this;
        }

        $listIds = Sanitize::sanitizeListIds($listIds, false);
        $listIds = array_filter(array_map('intval', $listIds));

        if (!$listIds) {
            return $this;
        }

        global $wpdb;
        $pivotTable = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType = 'FluentCrm\App\Models\Lists';

        // Fresh DB read, not the cached relation.
        $placeholders = implode(',', array_fill(0, count($listIds), '%d'));
        $existingListIds = $wpdb->get_col($wpdb->prepare(
            "SELECT object_id FROM {$pivotTable} WHERE subscriber_id = %d AND object_type = %s AND object_id IN ({$placeholders})",
            array_merge([$this->id, $objectType], $listIds)
        ));

        $existingListIds = array_map('intval', $existingListIds);
        $validListIds = array_values(array_intersect($listIds, $existingListIds));

        if (!$validListIds) {
            return $this;
        }

        // Per-row DELETE; the event fires only for rows this call actually removed.
        $removedListIds = [];
        foreach ($validListIds as $listId) {
            $affected = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$pivotTable} WHERE subscriber_id = %d AND object_type = %s AND object_id = %d",
                $this->id, $objectType, $listId
            ));
            if ($affected === false) {
                Helper::debugLog('Subscriber::detachLists pivot delete failed', $wpdb->last_error, 'error');
                continue;
            }
            if ($affected > 0) {
                $removedListIds[] = $listId;
            }
        }

        $this->load('lists');

        if ($removedListIds) {
            fluentcrm_contact_removed_from_lists($removedListIds, $this);

            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                do_action('fluent_crm/contact_removed_from_lists', $this, $removedListIds);
            }
        }

        return $this;
    }

    public function detachTags($tagsIds)
    {
        if (!$tagsIds) {
            return $this;
        }

        if (empty($this->id)) {
            return $this;
        }

        $tagsIds = Sanitize::sanitizeTagIds($tagsIds, false);
        $tagsIds = array_filter(array_map('intval', $tagsIds));

        if (!$tagsIds) {
            return $this;
        }

        global $wpdb;
        $pivotTable = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType = 'FluentCrm\App\Models\Tag';

        $placeholders = implode(',', array_fill(0, count($tagsIds), '%d'));
        $existingTagIds = $wpdb->get_col($wpdb->prepare(
            "SELECT object_id FROM {$pivotTable} WHERE subscriber_id = %d AND object_type = %s AND object_id IN ({$placeholders})",
            array_merge([$this->id, $objectType], $tagsIds)
        ));

        $existingTagIds = array_map('intval', $existingTagIds);
        $validTagIds = array_values(array_intersect($tagsIds, $existingTagIds));

        if (!$validTagIds) {
            return $this;
        }

        $removedTagIds = [];
        foreach ($validTagIds as $tagId) {
            $affected = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$pivotTable} WHERE subscriber_id = %d AND object_type = %s AND object_id = %d",
                $this->id, $objectType, $tagId
            ));
            if ($affected === false) {
                Helper::debugLog('Subscriber::detachTags pivot delete failed', $wpdb->last_error, 'error');
                continue;
            }
            if ($affected > 0) {
                $removedTagIds[] = $tagId;
            }
        }

        $this->load('tags');

        if ($removedTagIds) {
            fluentcrm_contact_removed_from_tags($removedTagIds, $this);

            if (!defined('FLUENTCRM_DISABLE_TAG_LIST_EVENTS')) {
                do_action('fluent_crm/contact_removed_from_tags', $this, $removedTagIds);
            }
        }

        return $this;
    }

    public function detachCompanies($companyIds)
    {
        if (!$companyIds) {
            return $this;
        }

        if (empty($this->id)) {
            return $this;
        }

        $companyIds = array_filter(array_map('intval', $companyIds));

        if (!$companyIds) {
            return $this;
        }

        global $wpdb;
        $pivotTable = $wpdb->prefix . 'fc_subscriber_pivot';
        $objectType = 'FluentCrm\App\Models\Company';

        $placeholders = implode(',', array_fill(0, count($companyIds), '%d'));
        $existingCompanyIds = $wpdb->get_col($wpdb->prepare(
            "SELECT object_id FROM {$pivotTable} WHERE subscriber_id = %d AND object_type = %s AND object_id IN ({$placeholders})",
            array_merge([$this->id, $objectType], $companyIds)
        ));

        $existingCompanyIds = array_map('intval', $existingCompanyIds);
        $validCompanyIds = array_values(array_intersect($companyIds, $existingCompanyIds));

        if (!$validCompanyIds) {
            return $this;
        }

        $removedCompanyIds = [];
        foreach ($validCompanyIds as $companyId) {
            $affected = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$pivotTable} WHERE subscriber_id = %d AND object_type = %s AND object_id = %d",
                $this->id, $objectType, $companyId
            ));
            if ($affected === false) {
                Helper::debugLog('Subscriber::detachCompanies pivot delete failed', $wpdb->last_error, 'error');
                continue;
            }
            if ($affected > 0) {
                $removedCompanyIds[] = $companyId;
            }
        }

        $this->load('companies');

        if ($removedCompanyIds) {
            fluentcrm_contact_removed_from_companies($removedCompanyIds, $this);
        }

        return $this;
    }

    public function unsubscribeReason($metaKey = 'unsubscribe_reason')
    {
        return fluentcrm_get_subscriber_meta($this->id, $metaKey, '');
    }

    public function unsubscribeReasonDate($metaKey = 'unsubscribe_reason')
    {
        // Exclude custom fields that share the meta key.
        $item = SubscriberMeta::where('key', $metaKey)
            ->where('subscriber_id', $this->id)
            ->where(function ($q) {
                $q->whereNull('object_type')->orWhere('object_type', '!=', 'custom_field');
            })
            ->first();

        if ($item) {
            return (string)$item->updated_at;
        }
        return '';
    }

    public function hasAnyTagId($tagIds)
    {
        if (!$tagIds || !is_array($tagIds)) {
            return false;
        }

        $tagIds = Sanitize::sanitizeTagIds($tagIds, false);

        $this->load('tags');

        foreach ($this->tags as $tag) {
            if (in_array($tag->id, $tagIds)) {
                return true;
            }
        }
        return false;
    }

    public function hasAnyListId($listIds)
    {
        if (!$listIds || !is_array($listIds)) {
            return false;
        }

        $listIds = Sanitize::sanitizeListIds($listIds, false);

        $this->load('lists');

        foreach ($this->lists as $list) {
            if (in_array($list->id, $listIds)) {
                return true;
            }
        }
        return false;
    }

    public function updateMeta($metaKey, $metaValue, $objectType)
    {
        $exist = $this->meta()
            ->where('key', $metaKey)
            ->where('object_type', $objectType)
            ->first();

        if ($exist) {
            $exist->value = $metaValue;
            $exist->save();
            return true;
        }
        // created_by is NOT NULL with no default; strict SQL mode rejects an insert without it.
        $this->meta()->create([
            'key'         => $metaKey,
            'object_type' => $objectType,
            'value'       => $metaValue,
            'created_by'  => get_current_user_id()
        ]);

        return true;
    }

    public function getMeta($metaKey, $objectType)
    {
        $exist = $this->meta()
            ->where('key', $metaKey)
            ->where('object_type', $objectType)
            ->first();

        if ($exist) {
            return $exist->value;
        }

        return false;
    }

    /**
     * Parse filter to set proper operator and value for the filter query for date operators
     *
     * @param array $filter
     * @return array
     */
    public static function filterParser($filter)
    {

        switch ($filter['operator']) {
            case 'before':
                $filter['operator'] = '<';
                if (!empty($filter['value']) && strlen($filter['value']) < 11) {
                    $filter['value'] = $filter['value'] . ' 00:00:00';
                }
                break;

            case 'after':
                $filter['operator'] = '>';
                if (!empty($filter['value']) && strlen($filter['value']) < 11) {
                    $filter['value'] = $filter['value'] . ' 00:00:00';
                }
                break;

            case 'date_equal':
            case 'contains':
                $filter['operator'] = 'LIKE';
                $filter['value'] = '%' . $filter['value'] . '%';
                break;
            case 'not_contains':
                $filter['operator'] = 'NOT LIKE';
                $filter['value'] = '%' . $filter['value'] . '%';
                break;

            case 'days_before':
                $daysToSeconds = intval($filter['value']) * 24 * 60 * 60;
                $filter['operator'] = '<';
                $filter['value'] = gmdate('Y-m-d', current_time('timestamp') - $daysToSeconds);
                break;

            case 'days_within':
                $daysToSeconds = intval($filter['value']) * 24 * 60 * 60;
                $filter['operator'] = 'BETWEEN';
                $filter['value'] = [
                    gmdate('Y-m-d 00:00:01', current_time('timestamp') - $daysToSeconds),
                    // Site-local "today", matching the lower bound.
                    gmdate('Y-m-d 23:59:59', current_time('timestamp'))
                ];
                break;
        }

        return $filter;
    }

    public static function applyGeneralFilterQuery($query, $filter, $referenceColumn = 'value')
    {

        $exactOperators = ['=', '!=', '>', '<'];

        $operator = self::parseCustomFieldsFilterOperator($filter);

        if (in_array($operator, $exactOperators)) {
            if ($operator == '>' || $operator == '<') {
                $filter['value'] = (float)$filter['value'];
            } else {
                $filter['value'] = sanitize_text_field($filter['value']);
            }
            $query->where($referenceColumn, $operator, $filter['value']);
        } else {
            $rawValue = $filter['value'];
            $filter = self::filterParser($filter);

            if ($filter['operator'] != $operator) {
                $newOperator = $filter['operator'];
                if ($newOperator == 'BETWEEN') {
                    $query->whereBetween($referenceColumn, $filter['value']);
                } elseif ($newOperator == 'LIKE' || $newOperator == 'NOT LIKE') {
                    $query->where($referenceColumn, $newOperator, '%' . $filter['value'] . '%');
                } else {
                    // Date operators arrive normalized by filterParser() to '<' / '>' with a datetime value.
                    $value = $filter['value'];
                    // Custom field values are stored as 'Y-m-d' text: compare with the bare date, or
                    // '2026-10-03' < '2026-10-03 00:00:00' puts day X itself "before X".
                    if ($referenceColumn === 'value' && is_string($rawValue) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($rawValue))) {
                        $value = trim($rawValue);
                    }
                    $query->where($referenceColumn, $newOperator, $value);
                }
            } else {
                // Build from the raw value; sanitizing the %-wrapped value strips "%50" as a URL-encoded byte.
                global $wpdb;
                $value = sanitize_text_field(is_scalar($rawValue) ? (string)$rawValue : '');
                $query->where($referenceColumn, $operator, '%' . $wpdb->esc_like($value) . '%');
            }
        }

        return $query;
    }

    /**
     * Build the inclusive end-of-day bound for "last activity" date filters.
     * Derived from the normalized date so relative-day values never reach TIMESTAMP comparisons.
     *
     * @param array $filter
     * @return string|false
     */
    protected static function getActivityLatestDateUpperBound($filter)
    {
        if (empty($filter['value']) || is_array($filter['value'])) {
            return false;
        }

        $value = trim((string) $filter['value'], "% \t\n\r\0\x0B");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $match)) {
            return false;
        }

        return $match[0] . ' 23:59:59';
    }

    /**
     * Dynamically build relation filter query for the Subscriber
     * model. It handles purchase, lists, tags relations.
     *
     * @param string $relation
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param string $method
     * @param string $subMethod
     * @param string $subField
     * @param array $filter
     * @param string $provider
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public static function buildRelationFilterQuery($relation, $query, $method, $subMethod, $subField, $filter, $provider = false)
    {
        if (in_array($filter['operator'], ['in_all', 'not_in_all'])) {
            foreach ($filter['value'] as $item) {
                $query = static::buildRelationFilterQuery($relation, $query, $method, $subMethod, $subField, ['value' => $item, 'operator' => ''], $provider);
            }
        } else {
            $query = $query->{$method}($relation, function ($relationQuery) use ($subMethod, $subField, $filter, $provider) {
                $relationQuery = $relationQuery->{$subMethod}($subField, $filter['value']);

                if ($provider) {
                    $relationQuery = $relationQuery->where('provider', $provider);
                }

                return $relationQuery;
            });
        }

        return $query;
    }


    /**
     * Dynamically build relation filter query for the Subscriber model.
     * It handles taxonomy query for subscribers purchase history mainly
     *
     * @param string $primaryRelation
     * @param string $childRelation
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $mainQuery
     * @param string $method
     * @param string $subField
     * @param array $itemIds
     * @param $checkAll bool
     * @param string $provider
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public static function buildChildRelationFilterQuery($primaryRelation, $childRelation, $mainQuery, $method, $subField, $itemIds, $checkAll = false, $provider = false)
    {
        if (!$checkAll) {
            return $mainQuery->{$method}($primaryRelation, function ($query) use ($itemIds, $provider, $childRelation, $subField) {
                $query->whereHas($childRelation, function ($q) use ($itemIds, $subField) {
                    $q->whereIn($subField, $itemIds);
                    return $q;
                });

                if ($provider) {
                    $query->where('provider', $provider);
                }

                return $query;
            });
        }

        return $mainQuery->{$method}($primaryRelation, function ($query) use ($itemIds, $provider, $childRelation, $subField) {

            $query->whereHas($childRelation, function ($q) use ($itemIds, $subField) {
                $q->distinct()->whereIn($subField, $itemIds);
            }, '=', count($itemIds));

            if ($provider) {
                $query->where('provider', $provider);
            }

            return $query;
        });
    }

    /**
     * LIKE pattern matching one whole quoted element of a JSON-encoded string list.
     *
     * @param string|int $value One list element, as the filter UI sends it.
     * @return string
     */
    private static function jsonListElementPattern($value)
    {
        global $wpdb;

        // Writers store the list with plain json_encode(), so encode the needle the same way.
        return '%' . $wpdb->esc_like(json_encode((string)$value)) . '%';
    }

    /**
     * Builds purchase provider related filter query. It handles woo, edd filter now.
     *
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @param string $provider
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public static function providerQueryBuilder($query, $filters, $provider = 'woo')
    {
        $filters = array_reduce($filters, function ($carry, $filter) {
            if ($filter['property'] == 'purchased_items') {
                $carry['contactRelationsItems'][] = $filter;
            } else if (in_array($filter['property'], ['purchased_categories', 'purchased_tags', 'purchased_groups'])) {
                $filter['property'] = 'commerce_taxonomies';
                $carry['itemTaxonomyRelations'][] = $filter;
            } elseif ($filter['property'] == 'commerce_coupons') {
                $carry['contactCommerceIn'][] = $filter;
            } elseif ($filter['property'] == 'commerce_exist') {
                $filter['method'] = 'whereHas';
                if ($filter['operator'] == 'not_exist') {
                    $filter['method'] = 'whereDoesntHave';
                }
                $carry['contactCommerceCheck'][] = $filter;
            } else if ($filter['property'] == 'variation_purchased') {
                $filter['property'] = 'item_sub_id';
                $filter['method'] = 'whereHas';
                if ($filter['operator'] == 'not_exist') {
                    $filter['method'] = 'whereDoesntHave';
                }

                if (is_array($filter['value']) && $filter['value']) {
                    $formattedVariationIds = [];
                    foreach ($filter['value'] as $value) {
                        $ids = explode('||', $value);
                        if ($ids && count($ids) == 2) {
                            $formattedVariationIds[] = (int)$ids[1];
                        }
                    }
                    $formattedVariationIds = array_values(array_unique($formattedVariationIds));
                    if ($formattedVariationIds) {
                        $filter['value'] = $formattedVariationIds;
                        $carry['contactSubFieldItems'][] = $filter;
                    }
                }
            } else {
                $carry['contactRelations'][] = $filter;
            }

            return $carry;
        }, []);

        if (array_key_exists('contactRelations', $filters)) {
            $query->whereHas('contact_commerce', function ($contactCommerceQuery) use ($filters, $provider) {
                foreach ($filters['contactRelations'] as $filter) {
                    $filter = static::filterParser($filter);
                    if ($filter['operator'] == 'BETWEEN') {
                        $contactCommerceQuery->whereBetween($filter['property'], $filter['value']);
                    } else {
                        $contactCommerceQuery->where($filter['property'], $filter['operator'], $filter['value']);
                    }
                }

                return $contactCommerceQuery->where('provider', $provider);
            });
        }

        if (array_key_exists('contactRelationsItems', $filters)) {
            foreach ($filters['contactRelationsItems'] as $filter) {
                if ($filter['operator'] == 'not_in_all') {
                    $query = static::buildChildRelationFilterQuery('commerce_by_provider', 'items', $query, 'whereDoesntHave', 'item_id', $filter['value'], true, $provider);
                } else {
                    list($method, $subMethod) = static::parseRelationalFilterQueryMethods($filter);
                    $query = static::buildRelationFilterQuery('contact_commerce_items', $query, $method, $subMethod, 'item_id', $filter, $provider);
                }
            }
        }

        if (array_key_exists('contactSubFieldItems', $filters)) {
            foreach ($filters['contactSubFieldItems'] as $filter) {
                $query = static::buildRelationFilterQuery('contact_commerce_items', $query, $filter['method'], 'whereIn', $filter['property'], $filter, $provider);
            }
        }

        if (array_key_exists('itemTaxonomyRelations', $filters)) {
            $childTaxMaps = [
                'in'         => 'whereHas',
                'not_in'     => 'whereDoesntHave',
                'in_all'     => 'whereHas',
                'not_in_all' => 'whereDoesntHave'
            ];

            foreach ($filters['itemTaxonomyRelations'] as $filter) {
                $operator = $filter['operator'];
                if (!isset($childTaxMaps[$operator]) || empty($filter['value'])) {
                    continue;
                }
                $method = $childTaxMaps[$operator];
                $checkAll = in_array($operator, ['in_all', 'not_in_all']);

                // The selector stores term ids; the item taxonomies relation holds term_taxonomy_ids,
                // which differ on most older sites.
                $termIds = array_values(array_unique(array_map('intval', (array)$filter['value'])));
                $filter['value'] = fluentCrmDb()->table('term_taxonomy')
                    ->whereIn('term_id', $termIds)
                    ->pluck('term_taxonomy_id')
                    ->toArray();
                // "All of" needs every selected term: a deleted one stays as a value nothing has,
                // so the rule cannot quietly become "all of the remaining ones".
                if ($checkAll) {
                    for ($missing = count($termIds) - count($filter['value']); $missing > 0; $missing--) {
                        $filter['value'][] = 0;
                    }
                }
                if (!$filter['value']) {
                    $filter['value'] = [0];
                }

                if ($checkAll) {
                    $query = static::buildChildRelationFilterQuery('commerce_by_provider', 'taxonomies', $query, $method, 'term_taxonomy_id', $filter['value'], $checkAll, $provider);
                } else {
                    $query = static::buildChildRelationFilterQuery('contact_commerce_items', 'taxonomies', $query, $method, 'term_taxonomy_id', $filter['value'], $checkAll, $provider);
                }

            }
        }

        if (array_key_exists('contactCommerceIn', $filters)) {
            foreach ($filters['contactCommerceIn'] as $filter) {
                $filter['value'] = array_values(array_filter((array)$filter['value'], function ($value) {
                    return is_scalar($value) && (string)$value !== '';
                }));
                // A row with nothing selected adds no constraint, as for the taxonomy filters above.
                if (!$filter['value']) {
                    continue;
                }

                $method = in_array($filter['operator'], ['in', 'in_all']) ? 'whereHas' : 'whereDoesntHave';

                if (in_array($filter['operator'], ['in', 'not_in'])) {
                    $query->{$method}('contact_commerce', function ($contactCommerceQuery) use ($filter, $provider) {
                        $contactCommerceQuery
                            ->where('provider', $provider)
                            ->where(function ($query) use ($filter) {
                                foreach ($filter['value'] as $value) {
                                    $query->orWhere($filter['property'], 'LIKE', static::jsonListElementPattern($value));
                                }
                            });

                    });
                } else {
                    foreach ($filter['value'] as $value) {
                        $query->{$method}('contact_commerce', function ($contactCommerceQuery) use ($filter, $value, $provider) {
                            $contactCommerceQuery
                                ->where('provider', $provider)
                                ->where($filter['property'], 'LIKE', static::jsonListElementPattern($value));
                        });
                    }
                }
            }
        }

        if (array_key_exists('contactCommerceCheck', $filters)) {
            foreach ($filters['contactCommerceCheck'] as $filter) {
                $method = $filter['method'];
                $query->{$method}('contact_commerce', function ($q) use ($provider) {
                    $q->where('provider', $provider);
                });
            }
        }

        return $query;
    }

    public function buildSearchableQuery($query, $search, $operator = 'LIKE')
    {
        $fields = $this->searchable;

        $query->where(function ($query) use ($fields, $search, $operator) {
            $query->where(array_shift($fields), $operator, $search);

            $nameArray = explode(' ', $search);

            if (count($nameArray) >= 2) {
                $query->orWhere(function ($q) use ($nameArray, $operator) {
                    $firstName = array_shift($nameArray);
                    $lastName = implode(' ', $nameArray);

                    $q->where('first_name', $operator, $firstName);
                    $q->where('last_name', $operator, $lastName);
                });
            }

            foreach ($fields as $field) {
                $query->orWhere($field, $operator, $search);
            }
        });

        return $query;
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function buildGeneralPropertiesFilterQuery($query, $filters)
    {
        foreach ($filters as $filter) {

            $operator = $filter['operator'];
            $searchTerm = $filter['value'];

            if ($filter['operator'] == 'contains') {
                if (is_array($filter['value'])) {
                    continue;
                }
                global $wpdb;
                $operator = 'LIKE';
                $searchTerm = '%' . $wpdb->esc_like($filter['value']) . '%';
            } elseif ($filter['operator'] == 'not_contains') {
                if (is_array($filter['value'])) {
                    continue;
                }
                global $wpdb;
                $operator = 'NOT LIKE';
                $searchTerm = '%' . $wpdb->esc_like($filter['value']) . '%';
            }

            $dateFields = ['created_at', 'last_activity', 'date_of_birth'];

            if (in_array($filter['property'], $dateFields)) {

                if (empty($filter['value'])) {
                    continue;
                }

                // Zero-date guard compares as CHAR; a '0000-00-00' literal raises ER 1525 under NO_ZERO_DATE.
                // The property is whitelisted by $dateFields.
                $query = $query->where(function ($q) use ($filter) {
                    $q->whereNotNull($filter['property'])
                        ->whereRaw('CAST(`' . $filter['property'] . '` AS CHAR) NOT LIKE \'0000-00-00%\'');
                });

                $query = self::applyGeneralFilterQuery($query, $filter, $filter['property']);
            } else if ($filter['property'] == 'search') {
                $query = $this->buildSearchableQuery($query, $searchTerm, $operator);
            } else if ($operator == 'in') {
                if (!is_array($searchTerm)) {
                    $searchTerm = (array)$searchTerm;
                }
                if ($searchTerm) {
                    $query = $query->whereIn($filter['property'], $searchTerm);
                }
            } else if ($operator == 'not_in') {
                if (!is_array($searchTerm)) {
                    $searchTerm = (array)$searchTerm;
                }
                if ($searchTerm) {
                    // An empty (NULL) field is "not in" any list, as in automation conditions.
                    $query = $query->where(function ($q) use ($filter, $searchTerm) {
                        $q->whereNull($filter['property'])
                            ->orWhereNotIn($filter['property'], $searchTerm);
                    });
                }
            } else if ($operator == 'is_null') {
                $query = $query->where(function ($q) use ($filter) {
                    return $q->whereNull($filter['property'])
                        ->orWhere($filter['property'], '=', '');
                });
            } else if ($operator == 'not_null') {
                $query = $query->where(function ($q) use ($filter) {
                    return $q->whereNotNull($filter['property'])
                        ->where($filter['property'], '!=', '');
                });
            } else if ($operator == '!=' || $operator == 'NOT LIKE') {
                // NULL != 'x' and NULL NOT LIKE '%x%' are not true in SQL; an empty field does not match.
                $query = $query->where(function ($q) use ($filter, $operator, $searchTerm) {
                    $q->whereNull($filter['property'])
                        ->orWhere($filter['property'], $operator, $searchTerm);
                });
            } else {
                $query = $query->where($filter['property'], $operator, $searchTerm);
            }
        }
        return $query;
    }

    /**
     * @param array $filter
     * @return string[]
     */
    public static function parseRelationalFilterQueryMethods($filter)
    {
        // default operator = in
        $method = 'whereHas';
        $subMethod = 'whereIn';

        switch ($filter['operator']) {
            case 'not_in':
                $method = 'whereDoesntHave';
                $subMethod = 'whereIn';

                break;
            case 'in_all':
                $method = 'whereHas';
                $subMethod = 'where';

                break;
            case 'not_in_all':
                $method = 'whereDoesntHave';
                $subMethod = 'where';

                break;
        }

        return [$method, $subMethod];
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function buildSegmentFilterQuery($query, $filters)
    {
        foreach ($filters as $filter) {
            $prop = $filter['property'];

            // Existence operators carry no value, so they run before the empty-value guard.
            if (in_array($filter['operator'], ['is_null', 'not_null']) && in_array($prop, ['tags', 'lists', 'companies'])) {
                $countOperator = $filter['operator'] == 'is_null' ? '<' : '>=';
                $query = $query->has($prop, $countOperator, 1);
                continue;
            }

            if (empty($filter['value'])) {
                continue;
            }


            if (in_array($prop, ['tags', 'lists', 'companies'])) {
                if ($filter['operator'] == 'not_in_all') {
                    $query->has($prop, '<', count($filter['value']), 'and', function ($query) use ($filter) {
                        $query->whereIn('object_id', $filter['value']);
                    });
                } else {
                    list($method, $subMethod) = static::parseRelationalFilterQueryMethods($filter);
                    $query = static::buildRelationFilterQuery($filter['property'], $query, $method, $subMethod, 'object_id', $filter);
                }
            } else if ($prop == 'user_role') {
                global $wpdb;
                $userRole = $wpdb->esc_like((string)$filter['value']);

                $operator = $filter['operator'];
                $method = ($operator == 'in' || $operator == 'contains') ? 'whereHas' : 'whereDoesntHave';

                $query = $query->{$method}('user', function ($userQuery) use ($userRole) {
                    return $userQuery->whereExists(function ($subQuery) use ($userRole) {
                        global $wpdb;
                        return $subQuery->select(fluentCrmDb()->raw(1))
                            ->from('usermeta')
                            ->whereRaw("{$wpdb->usermeta}.user_id = {$wpdb->users}.ID")
                            ->where('usermeta.meta_key', '=', $wpdb->prefix . 'capabilities')
                            ->where('usermeta.meta_value', 'LIKE', '%"' . $userRole . '"%');
                    });
                });
            } else if ($prop == 'company_industry') {
                $operator = $filter['operator'];
                $queryOperator = '>=';
                if ($operator == 'not_in') {
                    $queryOperator = '<';
                }

                $query = $query->has('companies', $queryOperator, 1, 'and', function ($q) use ($filter) {
                    $values = (array)$filter['value'];
                    $q->whereIn('industry', $values);
                });
            } else if ($prop == 'company_type') {
                $operator = $filter['operator'];
                $queryOperator = '>=';
                if ($operator == 'not_in') {
                    $queryOperator = '<';
                }
                $query = $query->has('companies', $queryOperator, 1, 'and', function ($q) use ($filter) {
                    $values = (array)$filter['value'];
                    $q->whereIn('type', $values);
                });
            } else if (!in_array($prop, $this->fillable, true)) {
                // A non-column property belongs to the plugin that registered it; fail closed when unclaimed.
                if (has_action('fluent_crm/contacts_filter_segment_' . $prop)) {
                    do_action_ref_array('fluent_crm/contacts_filter_segment_' . $prop, [&$query, $filter]);
                } else {
                    $query = $query->whereRaw('1 = 0');
                }
            } else {
                $operator = $filter['operator'];
                $method = ($operator == 'in' || $operator == 'contains') ? 'whereIn' : 'whereNotIn';

                $query = $query->{$method}($prop, (array)$filter['value']);
            }
        }

        return $query;
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Query\Builder
     */
    public function buildCustomFieldsFilterQuery($query, $filters)
    {
        $numericFieldKeys = [];
        foreach ((array)fluentcrm_get_option('contact_custom_fields', []) as $field) {
            if (Arr::get($field, 'type') === 'number' && Arr::get($field, 'slug')) {
                $numericFieldKeys[] = $field['slug'];
            }
        }

        $numericOperators = ['>', '<', '=', '!='];
        foreach ($filters as $filter) {
            $isNumericFilter = in_array(Arr::get($filter, 'property'), $numericFieldKeys, true)
                && in_array(Arr::get($filter, 'operator'), $numericOperators, true);
            $value = Arr::get($filter, 'value');

            // An invalid numeric threshold fails the whole AND group.
            if ($isNumericFilter && (!is_scalar($value) || !is_numeric(trim((string)$value)))) {
                return $query->whereRaw('1 = 0');
            }
        }

        $filters = array_reduce($filters, function ($carry, $filter) {
            $operator = $filter['operator'];

            if ($operator == 'not_in') {
                $carry['notIn'][] = $filter;
            } else if ($operator == '!=' || $operator == 'not_contains') {
                $carry['notEqualNorExist'][] = $filter;
            } else if ($operator == 'is_null' || $operator == 'not_null') {
                if ($operator == 'is_null') {
                    $filter['method'] = 'whereDoesntHave';
                } else {
                    $filter['method'] = 'whereHas';
                }

                $carry['exist_or_not'][] = $filter;
            } else {
                if ($operator == 'in') {
                    $filter['operator'] = 'contains';
                } else if ($operator == 'not_in') {
                    $filter['operator'] = 'not_contains';
                }
                $carry['regular'][] = $filter;
            }
            return $carry;
        }, []);

        if (array_key_exists('regular', $filters)) {
            foreach ($filters['regular'] as $filter) {
                $isNumericFilter = in_array($filter['property'], $numericFieldKeys, true)
                    && in_array($filter['operator'], $numericOperators, true);

                $query->whereHas('custom_field_meta', function ($customFieldQuery) use ($filter, $isNumericFilter) {
                    $customFieldQuery->where('key', $filter['property']);
                    $operator = self::parseCustomFieldsFilterOperator($filter);
                    if (is_array($filter['value'])) {
                        // Match the exact scalar or the quoted item inside a serialized multi-value array.
                        $customFieldQuery->where(function ($valueQuery) use ($filter) {
                            global $wpdb;
                            $first = true;
                            foreach ($filter['value'] as $value) {
                                $boolean = $first ? 'where' : 'orWhere';
                                $valueQuery->{$boolean}(function ($q) use ($value, $wpdb) {
                                    $q->where('value', '=', $value)
                                        ->orWhere('value', 'LIKE', '%"' . $wpdb->esc_like($value) . '"%');
                                });
                                $first = false;
                            }
                        });
                    } else if ($isNumericFilter) {
                        $customFieldQuery = self::applyNumericCustomFieldFilterQuery(
                            $customFieldQuery,
                            $operator,
                            $filter['value']
                        );
                    } else {
                        $customFieldQuery = self::applyGeneralFilterQuery($customFieldQuery, $filter, 'value');
                    }
                    return $customFieldQuery;
                });
            }
        }

        if (array_key_exists('notIn', $filters)) {
            foreach ($filters['notIn'] as $filter) {
                $filter['value'] = (array)$filter['value'];

                foreach ($filter['value'] as $value) {
                    $query->whereDoesntHave('custom_field_meta', function ($customFieldQuery) use ($value, $filter) {
                        global $wpdb;
                        // Same delimiter-aware matching as the "in" branch above.
                        $customFieldQuery->where('key', $filter['property'])
                            ->where(function ($q) use ($value, $wpdb) {
                                $q->where('value', '=', $value)
                                    ->orWhere('value', 'LIKE', '%"' . $wpdb->esc_like($value) . '"%');
                            });
                    });
                }
            }
        }

        if (array_key_exists('notEqualNorExist', $filters)) {
            foreach ($filters['notEqualNorExist'] as $filter) {
                $value = (string)trim($filter['value']);
                $operator = $filter['operator'];

                if ($operator == '!=' && in_array($filter['property'], $numericFieldKeys, true)) {
                    $query->whereDoesntHave('custom_field_meta', function ($customFieldQuery) use ($value, $filter) {
                        $customFieldQuery->where('key', $filter['property']);
                        self::applyNumericCustomFieldFilterQuery($customFieldQuery, '=', $value);
                    });
                    continue;
                }

                if ($operator == 'not_contains') {
                    global $wpdb;
                    $operator = 'LIKE';
                    $value = '%' . $wpdb->esc_like($value) . '%';
                } else {
                    $operator = '=';
                }

                $query->whereDoesntHave('custom_field_meta', function ($customFieldQuery) use ($value, $filter, $operator) {
                    $customFieldQuery->where('key', $filter['property'])
                        ->where('value', $operator, $value);
                });

            }
        }

        if (array_key_exists('exist_or_not', $filters)) {
            foreach ($filters['exist_or_not'] as $filter) {
                $query->{$filter['method']}('custom_field_meta', function ($customFieldQuery) use ($filter) {
                    $customFieldQuery->where('key', $filter['property']);
                });
            }
        }

        return $query;
    }

    /**
     * Apply a bound numeric comparison to a LONGTEXT custom-field value.
     *
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param string $operator
     * @param mixed $value
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    protected static function applyNumericCustomFieldFilterQuery($query, $operator, $value)
    {
        if (!in_array($operator, ['>', '<', '=', '!='], true) || !is_scalar($value) || !is_numeric(trim((string)$value))) {
            return $query->whereRaw('1 = 0');
        }

        $numericPattern = '^[+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)$';

        return $query
            ->whereRaw('TRIM(`value`) REGEXP ?', [$numericPattern])
            ->whereRaw(
                'CAST(TRIM(`value`) AS DECIMAL(20,6)) ' . $operator . ' CAST(? AS DECIMAL(20,6))',
                [trim((string)$value)]
            );
    }

    public static function parseCustomFieldsFilterOperator($filter)
    {
        $operator = $filter['operator'];

        switch ($filter['operator']) {
            case 'contains':
            case 'in':
                $operator = 'LIKE';
                break;
            case 'not_contains':
            case 'not_in':
                $operator = 'NOT LIKE';
                break;
        }

        return $operator;
    }

    /**
     * @param \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder $query
     * @param array $filters
     * @return \FluentCrm\Framework\Database\Orm\Builder|\FluentCrm\Framework\Database\Query\Builder
     */
    public function buildActivitiesFilterQuery($query, $filters)
    {
        foreach ($filters as $filter) {
            if (empty($filter['value'])) {
                if (in_array($filter['property'], ['email_opened', 'email_link_clicked'])) {
                    if ($filter['operator'] != 'never') {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            $relation = 'campaignEmails';

            // Bounced emails were sent too (CampaignEmail::SENT_STATUSES).
            $filter['where'] = [
                'prop'  => 'status',
                'value' => CampaignEmail::SENT_STATUSES,
                'field' => 'scheduled_at'
            ];

            $filterProp = $filter['property'];

            if ($filterProp == 'email_opened' && $filter['operator'] == 'never') {
                $query->whereDoesntHave('campaignEmails', function ($q) {
                    $q->where('is_open', 1);
                });
                continue;
            }

            if ($filterProp == 'email_link_clicked' && $filter['operator'] == 'never') {
                $query->whereDoesntHave('campaignEmails', function ($q) {
                    $q->whereNotNull('click_counter');
                });
                continue;
            }

            if ($filterProp == 'campaign_email_activity') {
                $campaignId = (int)$filter['value'];
                $operator = $filter['operator'];

                if ($operator == 'not_in') {
                    $query->whereDoesntHave('campaignEmails', function ($q) use ($campaignId) {
                        $q->where('campaign_id', $campaignId);
                    });
                } else {
                    $query->whereHas('campaignEmails', function ($q) use ($campaignId, $operator) {
                        $q->where('campaign_id', $campaignId);
                        if ($operator == 'clicked') {
                            $q->whereNotNull('click_counter');
                        } else if ($operator == 'not_clicked') {
                            $q->whereNull('click_counter');
                        } else if ($operator == 'open') {
                            $q->where('is_open', 1);
                        } else if ($operator == 'no_open') {
                            $q->where('is_open', '0');
                        }
                    });
                }
                continue;
            } else if ($filterProp == 'automation_activity') {

                $funnelId = (int)$filter['value'];
                $operator = $filter['operator'];

                if ($operator == 'not_in') {
                    $query->whereDoesntHave('funnel_subscribers', function ($q) use ($funnelId) {
                        $q->where('funnel_id', $funnelId);
                    });
                } else {
                    $query->whereHas('funnel_subscribers', function ($q) use ($funnelId, $operator) {
                        $q->where('funnel_id', $funnelId);
                        $statusItems = ['completed', 'active', 'cancelled', 'waiting'];
                        if (in_array($operator, $statusItems)) {
                            $q->where('status', $operator);
                        }
                    });
                }

                continue;
            } else if ($filterProp == 'email_sequence_activity') {

                $sequenceId = (int)$filter['value'];
                $operator = $filter['operator'];

                if ($operator == 'not_in') {
                    $query->whereDoesntHave('sequence_trackers', function ($q) use ($sequenceId) {
                        $q->where('campaign_id', $sequenceId);
                    });
                } else {
                    $query->whereHas('sequence_trackers', function ($q) use ($sequenceId, $operator) {
                        $q->where('campaign_id', $sequenceId);
                        $statusItems = ['completed', 'active', 'cancelled'];
                        if (in_array($operator, $statusItems)) {
                            $q->where('status', $operator);
                        }
                    });
                }

                continue;
            } else if ($filterProp == 'email_opened') {
                $relation = 'campaignEmails';
                $filter['where'] = [
                    'prop'  => 'is_open',
                    'value' => 1,
                    'field' => 'updated_at'
                ];
            } else if ($filterProp != 'email_sent') {
                $relation = 'urlMetrics';
                $filter['where'] = [
                    'prop'  => 'type',
                    'value' => 'click',
                    'field' => 'updated_at'
                ];
            }

            $filter = static::filterParser($filter);

            $query->whereHas($relation, function ($campaignEmailQuery) use ($filter, $relation) {
                if (is_array($filter['where']['value'])) {
                    $campaignEmailQuery->whereIn($filter['where']['prop'], $filter['where']['value']);
                } else {
                    $campaignEmailQuery->where($filter['where']['prop'], $filter['where']['value']);
                }
                if ($filter['operator'] == 'BETWEEN') {
                    $campaignEmailQuery->whereBetween($filter['where']['field'], $filter['value']);
                } else {
                    $campaignEmailQuery->where($filter['where']['field'], $filter['operator'], $filter['value']);
                }
            });

            $operator = $filter['operator'];
            if ($operator == '<' || $operator == 'LIKE') {
                $compareValue = static::getActivityLatestDateUpperBound($filter);
                if (!$compareValue) {
                    // Invalid date bounds must not become broad activity matches.
                    $query->whereRaw('1 = 0');
                    continue;
                }

                $query->whereDoesntHave($relation, function ($campaignEmailQuery) use ($filter, $compareValue) {
                    $campaignEmailQuery->where($filter['where']['prop'], $filter['where']['value']);
                    $campaignEmailQuery->where($filter['where']['field'], '>', $compareValue);
                });
            }
        }

        return $query;
    }

    public function lastActivityDate($activityName)
    {
        $validNames = ['email_sent', 'email_link_clicked', 'email_opened'];
        if (!in_array($activityName, $validNames)) {
            return false;
        }

        if ($activityName == 'email_sent') {
            $lastEmail = CampaignEmail::where('subscriber_id', $this->id)
                ->whereIn('status', CampaignEmail::SENT_STATUSES)
                ->orderBy('scheduled_at', 'DESC')
                ->first();
            if ($lastEmail) {
                return $lastEmail->scheduled_at;
            }
            return false;
        }

        if ($activityName == 'email_opened') {
            $lastOpen = CampaignEmail::where('subscriber_id', $this->id)
                ->where('is_open', 1)
                ->orderBy('updated_at', 'DESC')
                ->first();
            return $lastOpen ? $lastOpen->updated_at : false;
        }

        $lastActivity = CampaignUrlMetric::where('subscriber_id', $this->id)
            ->where('type', 'click')
            ->orderBy('updated_at', 'DESC')
            ->first();

        if ($lastActivity) {
            return $lastActivity->updated_at;
        }

        return false;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'ID');
    }

    public function getWpUser()
    {
        if ($this->user_id) {
            return get_user_by('ID', $this->user_id);
        }

        $user = get_user_by('email', $this->email);

        if ($user) {
            $this->user_id = $user->ID;
            $this->save();

            // remove the same user_id for other subscribers
            self::where('user_id', $user->ID)
                ->where('id', '!=', $this->id)
                ->update([
                    'user_id' => NULL
                ]);
        }

        return $user;
    }

    public function getWpUserId()
    {
        if ($this->user_id) {
            return $this->user_id;
        }

        $user = $this->getWpUser();

        if ($user) {
            return $user->ID;
        }

        return null;
    }

    /**
     * Get the attributes that have been changed since last sync.
     *
     * @return array
     */
    public function getDirty()
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->fillable)) {
                continue;
            }

            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif ($value !== $this->original[$key] &&
                !$this->originalIsNumericallyEquivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function getSecureHash()
    {
        return fluentCrmGetContactSecureHash($this->id);
    }

    public function trackEvent($eventData, $isUnique = false)
    {
        $eventData['subscriber'] = $this;
        return FluentCrmApi('event_tracker')->track($eventData, $isUnique);
    }

    public function updateStatus($status)
    {

        if ($this->status == $status) {
            return $this;
        }

        $oldStatus = $this->status;
        $newStatus = $status;

        $this->status = $status;
        $this->save();

        do_action('fluent_crm/subscriber_status_changed', $this, $oldStatus, $newStatus);
        do_action('fluentcrm_subscriber_status_to_' . $newStatus, $this, $oldStatus);

        return $this;
    }
}
