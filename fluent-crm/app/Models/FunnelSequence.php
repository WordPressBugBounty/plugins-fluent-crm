<?php

namespace FluentCrm\App\Models;

use FluentCrm\App\Services\Helper;

/**
 *  FunnelSequence Model - DB Model for Automation Sequences
 *
 *  Database Model
 *
 * @package FluentCrm\App\Models
 *
 * @version 1.0.0
 */
class FunnelSequence extends Model
{
    protected $table = 'fc_funnel_sequences';

    protected $fillable = [
        'funnel_id',
        'action_name',
        'parent_id',
        'condition_type',
        'title',
        'description',
        'status',
        'conditions',
        'settings',
        'delay',
        'c_delay',
        'sequence',
        'created_by',
        'type',
        'note',
    ];

    public static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            if (isset($model->settings) && is_array($model->settings)) {
                $model->settings = \maybe_serialize($model->settings);
            }
            if (isset($model->conditions) && is_array($model->conditions)) {
                $model->conditions = \maybe_serialize($model->conditions);
            }
        });
    }

    public function funnel()
    {
        return $this->belongsTo(
            __NAMESPACE__ . '\Funnel', 'funnel_id', 'id'
        );
    }

    public function parent()
    {
        return $this->belongsTo(
            __NAMESPACE__ . '\FunnelSequence', 'parent_id', 'id'
        );
    }

    public function children()
    {
        return $this->hasMany(
            __NAMESPACE__ . '\FunnelSequence', 'parent_id', 'id'
        );
    }

    public function setSettingsAttribute($settings)
    {
        $this->attributes['settings'] = self::serializeArrayValue($settings);
    }

    public function getSettingsAttribute($settings)
    {
        return Helper::safeUnserialize($settings);
    }

    public function setConditionsAttribute($conditions)
    {
        $this->attributes['conditions'] = self::serializeArrayValue($conditions);
    }

    public function getConditionsAttribute($conditions)
    {
        return Helper::safeUnserialize($conditions);
    }

    /**
     * Serialize step settings or conditions, always as an array.
     *
     * New steps reach this setter with request data, so a string is decoded without
     * building objects instead of being stored as is (PHP Object Injection).
     * A pre-serialized array string keeps working; any other value is stored as [].
     *
     * @param mixed $value Array, serialized string, or anything else from the caller.
     * @return string Serialized array.
     */
    private static function serializeArrayValue($value)
    {
        if (is_string($value)) {
            $value = Helper::safeUnserialize($value);
        }

        return \maybe_serialize(is_array($value) ? $value : []);
    }
}
