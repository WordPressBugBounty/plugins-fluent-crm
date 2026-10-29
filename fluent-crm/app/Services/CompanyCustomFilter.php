<?php

namespace FluentCrm\App\Services;

use FluentCrm\App\Models\CustomCompanyField;
use FluentCrm\Framework\Pagination\LengthAwarePaginator;
use FluentCrm\Framework\Pagination\Paginator;
use InvalidArgumentException;

/** Match serialized company custom values without loading all candidate models. */
class CompanyCustomFilter
{
    private $conditions = [];

    /** Validate the JSON/array contract against the current field registry once per request. */
    public function __construct($filters)
    {
        if (is_string($filters)) {
            $filters = substr(ltrim($filters), 0, 1) === '[' ? json_decode($filters, true) : null;
        }
        if (!is_array($filters) || array_values($filters) !== $filters) {
            $this->invalid();
        }
        if (!$filters) {
            return;
        }
        $fields = array_column((new CustomCompanyField())->getGlobalFields()['fields'], null, 'slug');
        foreach ($filters as $filter) {
            $slug = is_array($filter) ? ($filter['field'] ?? null) : null;
            // Exact registry lookup preserves percent-encoded slugs generated from Unicode labels.
            if (!is_string($slug) || !isset($fields[$slug]) || isset($this->conditions[$slug])) {
                $this->invalid();
            }
            $type = $fields[$slug]['type'];
            $operator = $filter['operator'] ?? null;
            $value = $filter['value'] ?? null;
            $isSelection = in_array($type, ['select-one', 'select-multi', 'radio', 'checkbox'], true);
            $operators = $isSelection ? ['in'] : (in_array($type, ['text', 'textarea'], true) ? ['contains', 'eq'] : ['eq', 'gt', 'lt']);
            if (!in_array($operator, $operators, true)) {
                $this->invalid();
            }
            if ($isSelection) {
                if (!is_array($value) || !$value || array_values($value) !== $value) {
                    $this->invalid();
                }
                foreach ($value as $option) {
                    if (!is_string($option) || !in_array($option, $fields[$slug]['options'] ?? [], true)) {
                        $this->invalid();
                    }
                }
                $value = array_values(array_unique($value));
            } else {
                $value = $this->normalize($type, $value);
                if ($value === null) {
                    $this->invalid();
                }
            }
            $this->conditions[$slug] = compact('type', 'operator', 'value');
        }
    }

    /** Preserve SQL pagination without custom conditions; otherwise retain only the requested page IDs. */
    public function paginate($query, $perPage, $page)
    {
        if (!$this->conditions) {
            return $query->paginate($perPage, ['*'], 'page', $page);
        }
        $query = clone $query;
        $query->orderBy('id', 'ASC'); // Stable ties, including nullable sort columns.
        $total = 0;
        $ids = [];
        $offset = ($page - 1) * $perPage;
        $scan = (clone $query)->toBase();
        $columns = ['id', 'meta'];
        foreach ($scan->orders ?? [] as $order) {
            $columns[] = $order['column'];
        }
        $scan->select(array_unique($columns))->chunk(200, function ($rows) use (&$total, &$ids, $offset, $perPage) {
            foreach ($rows as $row) {
                if ($this->matches($row->meta)) {
                    if ($total >= $offset && count($ids) < $perPage) {
                        $ids[] = (int) $row->id;
                    }
                    $total++;
                }
            }
        });
        // Never turn an empty match/page into an unrestricted company query.
        $items = $query->getModel()->newCollection();
        if ($ids) {
            $models = $query->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $id) {
                if (isset($models[$id])) {
                    $items->push($models[$id]);
                }
            }
        }
        return new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => Paginator::resolveCurrentPath()]);
    }

    /** Match AND across fields and OR across selection options, rejecting malformed stored values. */
    private function matches($meta)
    {
        $meta = Helper::safeUnserialize($meta);
        $values = is_array($meta) ? ($meta['custom_values'] ?? null) : null;
        if (!is_array($values)) {
            return false;
        }
        foreach ($this->conditions as $slug => $condition) {
            $value = $values[$slug] ?? null;
            $expected = $condition['value'];
            if ($condition['operator'] === 'in') {
                $multiple = in_array($condition['type'], ['select-multi', 'checkbox'], true);
                if ($multiple ? !is_array($value) : !is_string($value)) {
                    return false;
                }
                $value = $multiple ? $value : [$value];
                if (!$value || count(array_filter($value, 'is_string')) !== count($value) || !array_intersect($value, $expected)) {
                    return false;
                }
                continue;
            }
            $value = $this->normalize($condition['type'], $value);
            if ($value === null) {
                return false;
            }
            if (in_array($condition['type'], ['text', 'textarea'], true)) {
                // Quoted Unicode matching needs no optional mbstring extension; wildcards stay literal.
                $pattern = preg_quote($expected, '~');
                $pattern = $condition['operator'] === 'eq' ? '\A' . $pattern . '\z' : $pattern;
                $matched = preg_match('~' . $pattern . '~iu', $value) === 1;
            } else {
                switch ($condition['operator']) {
                    case 'gt': $matched = $value > $expected; break;
                    case 'lt': $matched = $value < $expected; break;
                    default: $matched = $value === $expected;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
    }

    /** Normalize typed scalars without coercing missing values to zero or shifting local dates to UTC. */
    private function normalize($type, $value)
    {
        if ($type === 'number') {
            return (is_string($value) || is_int($value) || is_float($value)) && is_numeric($value) && is_finite((float) $value)
                ? (float) $value : null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        if (in_array($type, ['text', 'textarea'], true)) {
            // Strip markup, but preserve literal percent sequences and textarea line breaks.
            $value = wp_check_invalid_utf8(wp_strip_all_tags($value));
            return trim($value) === '' ? null : $value;
        }
        if (in_array($type, ['date', 'date_time'], true)) {
            if (strpos($value, "\0") !== false) {
                return null;
            }
            $format = $type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
            return $date && $date->format($format) === $value && checkdate((int) $date->format('m'), (int) $date->format('d'), (int) $date->format('Y'))
                ? $value : null;
        }
        return null;
    }

    /** Fail closed so malformed filters can never expand a listing. */
    private function invalid()
    {
        throw new InvalidArgumentException(__('Invalid company custom filters.', 'fluent-crm'));
    }
}
