<?php

namespace FluentCrm\App\Services;

trait ReportingHelperTrait
{
    protected static $daily = 'P1D';
    protected static $weekly = 'P1W';
    protected static $monthly = 'P1M';

    protected function makeFromDate($from)
    {
        $from = $from ?: '-30 days';

        // Site timezone: the compared columns are written with current_time(),
        // so UTC-based bounds shift every day boundary by the site offset.
        return new \DateTime($from, wp_timezone());
    }

    protected function makeToDate($to)
    {
        $to = $to ?: '+1 days';

        return new \DateTime($to, wp_timezone());
    }

    protected function makeDatePeriod($from, $to, $interval = null)
    {
        $interval = $interval ?: static::$daily;

        return new \DatePeriod($from, new \DateInterval($interval), $to);
    }

    protected function getFrequency($from, $to)
    {
        $numDays = $to->diff($from)->format("%a");

        if ($numDays > 62 && $numDays <= 92) {
            return static::$weekly;
        } else if ($numDays > 92) {
            return static::$monthly;
        }

        return static::$daily;
    }

    /**
     * SELECT columns for a chart query: a row count plus the date the row is keyed by.
     *
     * Daily and weekly charts select per-day counts (weekly days are summed into their buckets in
     * getResult()). Monthly charts group by year-month, so every other column is aggregated to stay
     * valid under ONLY_FULL_GROUP_BY (MySQL 8 default).
     */
    protected function prepareSelect($frequency, $dateField = 'created_at')
    {
        $select = [
            fluentCrmDb()->raw('COUNT(id) AS count')
        ];

        if ($frequency == static::$monthly) {
            $select[] = fluentCrmDb()->raw('MIN(DATE(' . $dateField . ')) AS date');
            // Keep existing month for backward compatibility
            $select[] = fluentCrmDb()->raw('MIN(MONTH(' . $dateField . ')) AS month');
            // Year-month key to disambiguate months across years
            $select[] = fluentCrmDb()->raw("DATE_FORMAT($dateField, '%Y-%m') AS ym");
        } else {
            $select[] = fluentCrmDb()->raw('DATE(' . $dateField . ') AS date');
        }

        return $select;
    }

    protected function getGroupAndOrder($frequency)
    {
        $orderBy = $groupBy = 'date';

        if ($frequency == static::$monthly) {
            $orderBy = $groupBy = 'ym';
        }

        return [$groupBy, $orderBy];
    }

    protected function getDateRangeArray($period)
    {
        $range = [];

        $formatter = 'basicFormatter';

        if ($this->isMonthly($period)) {
            $formatter = 'monYearFormatter';
        }

        foreach ($period as $date) {
            $date = $this->{$formatter}($date);
            $range[$date] = 0;
        }

        return $range;
    }

    protected function getResult($period, $items)
    {
        $range = $this->getDateRangeArray($period);

        $formatter = 'basicFormatter';

        if ($this->isMonthly($period)) {
            $formatter = 'monYearFormatter';
        }

        if ($this->isWeekly($period)) {
            return $this->sumIntoWeeks($period, $range, $items);
        }

        foreach ($items as $item) {
            $date = $this->{$formatter}($item->date);
            $range[$date] = (int)$item->count;
        }

        return $range;
    }

    /**
     * Add per-day counts into weekly buckets that start on the period's first day.
     *
     * The bucket keys are the period dates (from, from + 7 days, ...), so a day belongs to the bucket
     * floor(days since from / 7). Days outside the range are ignored.
     */
    protected function sumIntoWeeks($period, $range, $items)
    {
        $start = new \DateTime($period->getStartDate()->format('Y-m-d'));
        $keys = array_keys($range);

        foreach ($items as $item) {
            $days = (int)$start->diff(new \DateTime($this->basicFormatter($item->date)))->format('%r%a');
            $index = (int)floor($days / 7);
            if ($days < 0 || !isset($keys[$index])) {
                continue;
            }
            $range[$keys[$index]] += (int)$item->count;
        }

        return $range;
    }

    protected function isWeekly($period)
    {
        return $period->getDateInterval()->d == 7;
    }

    protected function isMonthly($period)
    {
        return !!$period->getDateInterval()->m;
    }

    protected function basicFormatter($date)
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }

        return $date->format('Y-m-d');
    }

    protected function monYearFormatter($date)
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }

        return $date->format('M Y');
    }
}
