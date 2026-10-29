<?php

namespace FluentCrm\App\Models;

use FluentCrm\Framework\Database\Orm\Model as BaseModel;
use FluentCrm\Framework\Support\Str;

class Model extends BaseModel
{
    public function __construct($attributes = [])
    {
        parent::__construct($attributes);
    }

    public function scopeLatest($query, $field = 'created_at')
    {
        return $query->orderBy($field, 'desc');
    }

    public function scopeNewest($query, $field = 'created_at')
    {
        return $query->orderBy($field, 'asc');
    }

    /**
     * Page size for paginate(), read from the request.
     *
     * Clamped to 1-1000: a negative value made limit() a no-op (every row loaded)
     * and 0 divided by zero in the paginator. 1000 is above every UI page size.
     *
     * @return int
     */
    public function getPerPage()
    {
        if (!isset($_REQUEST['per_page'])) {
            return 15;
        }

        return max(1, min(1000, intval($_REQUEST['per_page'])));
    }

    /**
     * Get a fresh timestamp for the model.
     *
     * @return \DateTime
     */
    public function freshTimestamp()
    {
        return new \FluentCrm\Framework\Support\DateTime(current_time('mysql'));
    }

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function getTimezone()
    {
        return wp_timezone();
    }

    protected function asDateTime($value)
    {
        if (is_string($value) && Str::contains($value, 'T')) {
            return new \FluentCrm\Framework\Support\DateTime($value);
        }

        return parent::asDateTime($value);
    }

    protected function originalIsNumericallyEquivalent($key)
    {
        $current = $this->attributes[$key];

        $original = $this->original[$key];

        return is_numeric($current) && is_numeric($original) && strcmp((string) $current, (string) $original) === 0;
    }

}
