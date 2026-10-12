<?php

namespace FluentCrm\App\Services;

/**
 * Health-checks and repairs the critical indexes the hot-path queries depend on.
 * Migrations and the runtime repair path both build indexes through ensureCriticalIndex().
 */
class DbPerformanceService
{
    /**
     * Build the health status for every critical index.
     *
     * @param bool $fromDb False serves the cache when it covers every known index.
     * @return array<string, array> Index name => meta + 'status' ('yes'|'no').
     */
    public static function getIndexHealth($fromDb = true)
    {
        $indexActions = self::getCriticalIndexLists();

        if (!$fromDb) {
            $existing = fluentcrm_get_option('_db_index_health', []);
            $broken = false;
            if ($existing) {
                foreach ($indexActions as $indexName => $indexData) {
                    if (!isset($existing[$indexName])) {
                        $broken = true;
                        continue;
                    }
                    $indexData['name'] = $indexName;
                    $indexData['status'] = $existing[$indexName];
                    $indexActions[$indexName] = $indexData;
                }

                if (!$broken) {
                    return $indexActions;
                }
            }
        }

        global $wpdb;

        $formattedIndex = [];
        foreach ($indexActions as $indexName => $indexData) {
            if (!isset($formattedIndex[$indexData['table']])) {
                $formattedIndex[$indexData['table']] = [];
            }
            $formattedIndex[$indexData['table']][$indexName] = $indexData;
        }

        $indexedResults = [];
        $cachedData = [];

        foreach ($formattedIndex as $tableName => $requiredIndexes) {
            $table = $wpdb->prefix . $tableName;

            $info = self::getIndexInfo($table);

            foreach ($requiredIndexes as $indexName => $indexData) {
                $status = self::indexIsHealthy($info, $indexName, $indexData) ? 'yes' : 'no';
                $indexData['name'] = $indexName;
                $indexData['status'] = $status;
                $indexedResults[$indexName] = $indexData;
                $cachedData[$indexName] = $status;
            }
        }

        $ordered = [];
        foreach ($indexActions as $indexName => $indexData) {
            if (isset($indexedResults[$indexName])) {
                $ordered[$indexName] = $indexedResults[$indexName];
            }
        }

        fluentcrm_update_option('_db_index_health', $cachedData);

        return $ordered;
    }

    /**
     * Whether any critical index is currently missing.
     *
     * @param bool $fromDb See getIndexHealth(). Defaults to the cached check.
     * @return bool
     */
    public static function hasBrokenIndex($fromDb = false)
    {
        foreach (self::getIndexHealth($fromDb) as $indexData) {
            if ($indexData['status'] !== 'yes') {
                return true;
            }
        }

        return false;
    }

    /**
     * Ensure a critical index exists with the right columns, deduping first for unique keys.
     * Uses only $wpdb so migrations can call it before the framework boots.
     *
     * @param string $indexName One of the keys from getCriticalIndexLists().
     * @return bool False for an unknown index.
     */
    public static function ensureCriticalIndex($indexName)
    {
        global $wpdb;

        $indexLists = self::getCriticalIndexLists();
        if (!isset($indexLists[$indexName])) {
            return false;
        }

        $index = $indexLists[$indexName];
        $table = $wpdb->prefix . $index['table'];

        if ($index['type'] === 'unique') {
            self::ensureUniqueKey($table, $indexName, $index);
        } else {
            self::ensurePlainIndex($table, $indexName, $index['columns']);
        }

        return true;
    }

    /**
     * Repair a single critical index, then verify it and refresh the health cache.
     *
     * @param string $indexName One of the keys from getCriticalIndexLists().
     * @return array|\WP_Error The refreshed index record, or WP_Error if unknown or still missing.
     */
    public static function repairIndex($indexName)
    {
        if (!self::ensureCriticalIndex($indexName)) {
            return new \WP_Error(
                'invalid_index',
                __('The provided index is not a known critical index.', 'fluent-crm')
            );
        }

        global $wpdb;
        // Captured before the verification queries reset $wpdb->last_error.
        $repairError = $wpdb->last_error;

        $health = self::getIndexHealth(true);

        if (!isset($health[$indexName]) || $health[$indexName]['status'] !== 'yes') {
            $lists = self::getCriticalIndexLists();

            return new \WP_Error(
                'repair_failed',
                self::describeRepairFailure($wpdb->prefix . $lists[$indexName]['table'], $repairError),
                ['db_error' => $repairError]
            );
        }

        return $health[$indexName];
    }

    /**
     * Turn a failed index ALTER into a message an admin can act on.
     * The most common cause is MyISAM's 1000-byte key limit.
     *
     * @param string $table   Fully-prefixed table name.
     * @param string $dbError The MySQL error captured from the ALTER, or ''.
     * @return string Translated message.
     */
    private static function describeRepairFailure($table, $dbError)
    {
        global $wpdb;

        if (stripos($dbError, 'key was too long') !== false) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ));

            if ($engine && strcasecmp($engine, 'MyISAM') === 0) {
                return sprintf(
                    /* translators: 1: table name, 2: SQL statement */
                    __('The %1$s table uses the MyISAM storage engine, which cannot hold this index. After taking a database backup, convert the table to InnoDB (for example, run: %2$s) and click Repair Now again.', 'fluent-crm'),
                    $table,
                    "ALTER TABLE `{$table}` ENGINE=InnoDB;"
                );
            }

            return sprintf(
                /* translators: 1: table name, 2: database error */
                __('Your database cannot create an index this long on the %1$s table. Database error: %2$s', 'fluent-crm'),
                $table,
                $dbError
            );
        }

        if (stripos($dbError, 'denied') !== false) {
            return sprintf(
                /* translators: 1: table name, 2: database error */
                __('Your database user is not allowed to change the %1$s table. Please ask your host to grant the ALTER and INDEX privileges. Database error: %2$s', 'fluent-crm'),
                $table,
                $dbError
            );
        }

        if ($dbError) {
            return sprintf(
                /* translators: 1: table name, 2: database error */
                __('Could not create the index on the %1$s table. Database error: %2$s', 'fluent-crm'),
                $table,
                $dbError
            );
        }

        return sprintf(
            /* translators: %s: table name */
            __('Could not create the index on the %s table. Please try again, or contact your host if it keeps failing.', 'fluent-crm'),
            $table
        );
    }

    /**
     * Repair every missing critical index in one pass.
     *
     * @return array{repaired: string[], failed: string[], errors: array<string, string>, health: array}
     */
    public static function repairBrokenIndexes()
    {
        $health = self::getIndexHealth(true);

        $repaired = [];
        $failed = [];
        $errors = [];
        foreach ($health as $indexName => $indexData) {
            if ($indexData['status'] === 'yes') {
                continue;
            }

            $result = self::repairIndex($indexName);
            if (is_wp_error($result)) {
                $failed[] = $indexName;
                $errors[$indexName] = $result->get_error_message();
            } else {
                $repaired[] = $indexName;
            }
        }

        return [
            'repaired' => $repaired,
            'failed'   => $failed,
            'errors'   => $errors,
            'health'   => self::getIndexHealth(false),
        ];
    }

    /**
     * Ensure a plain index exists with exactly the expected columns.
     * A same-named index with drifted columns is dropped and recreated.
     *
     * @param string $table     Fully-prefixed table name.
     * @param string $indexName Key name to create.
     * @param array  $columns   Expected column spec, see getCriticalIndexLists().
     * @return void
     */
    private static function ensurePlainIndex($table, $indexName, array $columns)
    {
        $info = self::getIndexInfo($table);
        $columnSql = self::buildColumnSql($columns);

        if (isset($info[$indexName])) {
            if (self::indexColumnsMatch($info[$indexName]['columns'], $columns, true)) {
                return;
            }
            self::alterWithOnlineFallback($table, "DROP INDEX `{$indexName}`, ADD INDEX `{$indexName}` ({$columnSql})");
            return;
        }

        self::alterWithOnlineFallback($table, "ADD INDEX `{$indexName}` ({$columnSql})");
    }

    /**
     * Add a UNIQUE KEY after the table-specific dedupe, replacing a same-named
     * key that is non-unique or has drifted columns. Skips the ALTER if cleanup fails.
     *
     * @param string $table     Fully-prefixed table name.
     * @param string $indexName Unique key name to create.
     * @param array  $index     Index descriptor from getCriticalIndexLists().
     * @return void
     */
    private static function ensureUniqueKey($table, $indexName, array $index)
    {
        $info = self::getIndexInfo($table);
        $existing = isset($info[$indexName]) ? $info[$indexName] : null;

        if ($existing
            && $existing['non_unique'] === 0
            && self::indexColumnsMatch($existing['columns'], $index['columns'])) {
            return;
        }

        $clean = false;
        switch ($index['cleanup']) {
            case 'subscriber_pivot':
                $clean = self::cleanupSubscriberPivot($table);
                break;
            case 'funnel_subscribers':
                $clean = self::cleanupFunnelSubscribers($table);
                break;
            case 'funnel_metrics':
                $clean = self::cleanupFunnelMetrics($table);
                break;
        }

        if (!$clean) {
            return;
        }

        $columnSql = self::buildColumnSql($index['columns']);

        if ($existing) {
            self::alterWithOnlineFallback($table, "DROP INDEX `{$indexName}`, ADD UNIQUE KEY `{$indexName}` ({$columnSql})");
        } else {
            self::alterWithOnlineFallback($table, "ADD UNIQUE KEY `{$indexName}` ({$columnSql})");
        }
    }

    /**
     * Run an index ALTER online (ALGORITHM=INPLACE, LOCK=NONE), falling back to a
     * plain ALTER where online DDL is unsupported (pre MySQL 5.6 / MariaDB 10.0).
     *
     * @param string $table      Fully-prefixed table name.
     * @param string $operations The ALTER body, e.g. "ADD UNIQUE KEY `x` (...)"
     *                           or "DROP INDEX `x`, ADD UNIQUE KEY `x` (...)".
     * @return int|bool Result of the successful query, or false if both failed.
     */
    private static function alterWithOnlineFallback($table, $operations)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query("ALTER TABLE {$table} {$operations}, ALGORITHM=INPLACE, LOCK=NONE");
        if ($result !== false) {
            return $result;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->query("ALTER TABLE {$table} {$operations}");
    }

    /**
     * Prepare fc_subscriber_pivot for subscriber_object_type_unique.
     * Deletes rows with a NULL/0 subscriber_id or object_id, then keeps the lowest
     * id per (subscriber_id, object_id, object_type).
     *
     * @param string $table Fully-prefixed fc_subscriber_pivot table name.
     * @return bool True if the table is safe to receive the unique key.
     */
    private static function cleanupSubscriberPivot($table)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DELETE FROM {$table} WHERE subscriber_id IS NULL OR subscriber_id = 0 OR object_id IS NULL OR object_id = 0");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hasDuplicates = (int) $wpdb->get_var("SELECT COUNT(*) FROM (
            SELECT subscriber_id, object_id, object_type
            FROM {$table}
            GROUP BY subscriber_id, object_id, object_type
            HAVING COUNT(*) > 1
        ) dups");

        if ($hasDuplicates <= 0) {
            return true;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query("DELETE sp FROM {$table} sp
            INNER JOIN (
                SELECT subscriber_id, object_id, object_type, MIN(id) as keep_id
                FROM {$table}
                GROUP BY subscriber_id, object_id, object_type
                HAVING COUNT(*) > 1
            ) dups ON sp.subscriber_id = dups.subscriber_id
                AND sp.object_id = dups.object_id
                AND sp.object_type = dups.object_type
                AND sp.id != dups.keep_id");

        return $deleted !== false;
    }

    /**
     * Prepare fc_funnel_subscribers for funnel_subscriber_idx.
     * Deletes rows with a NULL/0 funnel_id or subscriber_id, then keeps the row with
     * the highest IFNULL(last_sequence_id, 0), ties broken by MAX(id), so a
     * contact never re-runs sequences it already received.
     *
     * @param string $table Fully-prefixed fc_funnel_subscribers table name.
     * @return bool True if the table is safe to receive the unique key.
     */
    private static function cleanupFunnelSubscribers($table)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DELETE FROM {$table} WHERE funnel_id IS NULL OR funnel_id = 0 OR subscriber_id IS NULL OR subscriber_id = 0");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hasDuplicates = (int) $wpdb->get_var("SELECT COUNT(*) FROM (
            SELECT funnel_id, subscriber_id
            FROM {$table}
            GROUP BY funnel_id, subscriber_id
            HAVING COUNT(*) > 1
        ) dups");

        if ($hasDuplicates <= 0) {
            return true;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query("DELETE fs FROM {$table} fs
            INNER JOIN (
                SELECT t1.funnel_id, t1.subscriber_id, MAX(t1.id) as keep_id
                FROM {$table} t1
                INNER JOIN (
                    SELECT funnel_id, subscriber_id, MAX(IFNULL(last_sequence_id, 0)) as max_seq
                    FROM {$table}
                    GROUP BY funnel_id, subscriber_id
                    HAVING COUNT(*) > 1
                ) dups ON t1.funnel_id = dups.funnel_id
                    AND t1.subscriber_id = dups.subscriber_id
                    AND IFNULL(t1.last_sequence_id, 0) = dups.max_seq
                GROUP BY t1.funnel_id, t1.subscriber_id
            ) survivors ON fs.funnel_id = survivors.funnel_id
                AND fs.subscriber_id = survivors.subscriber_id
                AND fs.id != survivors.keep_id");

        return $deleted !== false;
    }

    /**
     * Prepare fc_funnel_metrics for funnel_seq_subscriber_unique.
     * Deletes rows with any NULL/0 key column, then keeps the MAX(id) per
     * (funnel_id, sequence_id, subscriber_id).
     *
     * @param string $table Fully-prefixed fc_funnel_metrics table name.
     * @return bool True if the table is safe to receive the unique key.
     */
    private static function cleanupFunnelMetrics($table)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DELETE FROM {$table} WHERE funnel_id IS NULL OR funnel_id = 0 OR sequence_id IS NULL OR sequence_id = 0 OR subscriber_id IS NULL OR subscriber_id = 0");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hasDuplicates = (int) $wpdb->get_var("SELECT COUNT(*) FROM (
            SELECT funnel_id, sequence_id, subscriber_id
            FROM {$table}
            GROUP BY funnel_id, sequence_id, subscriber_id
            HAVING COUNT(*) > 1
        ) dups");

        if ($hasDuplicates <= 0) {
            return true;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query("DELETE fm FROM {$table} fm
            INNER JOIN (
                SELECT funnel_id, sequence_id, subscriber_id, MAX(id) AS keep_id
                FROM {$table}
                GROUP BY funnel_id, sequence_id, subscriber_id
                HAVING COUNT(*) > 1
            ) dups ON fm.funnel_id = dups.funnel_id
                AND fm.sequence_id = dups.sequence_id
                AND fm.subscriber_id = dups.subscriber_id
                AND fm.id != dups.keep_id");

        return $deleted !== false;
    }

    /**
     * Read every index on a table, keyed by index name, with columns ordered by Seq_in_index.
     *
     * @param string $table Fully-prefixed table name.
     * @return array<string, array{non_unique:int, columns:array}>
     */
    private static function getIndexInfo($table)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}");
        if (!$rows) {
            return [];
        }

        $info = [];
        foreach ($rows as $row) {
            $name = $row->Key_name;
            if (!isset($info[$name])) {
                $info[$name] = ['non_unique' => (int) $row->Non_unique, 'columns' => []];
            }
            $info[$name]['columns'][(int) $row->Seq_in_index] = [
                'name'     => $row->Column_name,
                'sub_part' => ($row->Sub_part === null) ? null : (int) $row->Sub_part,
            ];
        }

        foreach ($info as &$data) {
            ksort($data['columns']);
            $data['columns'] = array_values($data['columns']);
        }
        unset($data);

        return $info;
    }

    /**
     * Whether a required index is present, of the right kind, with the expected columns.
     *
     * @param array  $info      Output of getIndexInfo().
     * @param string $indexName Required index name.
     * @param array  $index     Index descriptor from getCriticalIndexLists().
     * @return bool
     */
    private static function indexIsHealthy($info, $indexName, array $index)
    {
        if (!isset($info[$indexName])) {
            return false;
        }

        $actual = $info[$indexName];

        if ($index['type'] === 'unique' && $actual['non_unique'] !== 0) {
            return false;
        }

        return self::indexColumnsMatch($actual['columns'], $index['columns'], $index['type'] !== 'unique');
    }

    /**
     * Compare actual index columns against the expected spec. Names compare
     * case-insensitively and order matters. A full-column index satisfies an
     * expected prefix, since MySQL reports a prefix equal to the column length as full.
     * $allowLongerPrefix must stay false for UNIQUE keys, where a longer prefix is a weaker constraint.
     *
     * @param array $actualColumns     Ordered [['name'=>, 'sub_part'=>], ...].
     * @param array $expectedColumns   Ordered [['name'=>, 'sub_part'=>?], ...].
     * @param bool  $allowLongerPrefix Accept a prefix longer than expected.
     * @return bool
     */
    private static function indexColumnsMatch($actualColumns, array $expectedColumns, $allowLongerPrefix = false)
    {
        if (count($actualColumns) !== count($expectedColumns)) {
            return false;
        }

        foreach ($expectedColumns as $i => $expected) {
            if (!isset($actualColumns[$i])) {
                return false;
            }
            $actual = $actualColumns[$i];

            if (strcasecmp($actual['name'], $expected['name']) !== 0) {
                return false;
            }

            $expectedSub = isset($expected['sub_part']) ? (int) $expected['sub_part'] : null;
            $actualSub = isset($actual['sub_part']) ? $actual['sub_part'] : null;

            if ($expectedSub === null) {
                if ($actualSub !== null) {
                    return false;
                }
            } else {
                if ($actualSub !== null && $actualSub !== $expectedSub
                    && !($allowLongerPrefix && $actualSub > $expectedSub)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Build the SQL column list for ADD [UNIQUE] INDEX, e.g. "`object_id`, `object_type`(50)".
     *
     * @param array $columns Ordered [['name'=>, 'sub_part'=>?], ...].
     * @return string
     */
    private static function buildColumnSql(array $columns)
    {
        $parts = [];
        foreach ($columns as $col) {
            $sql = '`' . $col['name'] . '`';
            if (!empty($col['sub_part'])) {
                $sql .= '(' . (int) $col['sub_part'] . ')';
            }
            $parts[] = $sql;
        }

        return implode(', ', $parts);
    }

    /**
     * The canonical list of performance- and integrity-critical indexes.
     *
     *   - type    : 'index' | 'unique'
     *   - table   : unprefixed table name
     *   - title   : UI label
     *   - columns : ordered [['name' => 'col', 'sub_part' => 50?], ...]
     *   - cleanup : (unique only) 'subscriber_pivot' | 'funnel_subscribers' | 'funnel_metrics'
     *
     * @return array<string, array>
     */
    private static function getCriticalIndexLists()
    {
        global $wpdb;
        // The CampaignEmails migration bakes the table prefix into this index name.
        $campaignEmailIndexPrefix = $wpdb->prefix . 'fc_cam_';

        $indexActions = [
            'subscriber_object_type_unique'          => [
                'type'    => 'unique',
                'table'   => 'fc_subscriber_pivot',
                'title'   => __('Contact Tags / Lists Relationship Uniqueness', 'fluent-crm'),
                'columns' => [
                    ['name' => 'subscriber_id'],
                    ['name' => 'object_id'],
                    ['name' => 'object_type', 'sub_part' => 50],
                ],
                'cleanup' => 'subscriber_pivot',
            ],
            $campaignEmailIndexPrefix . 'cid_status' => [
                'type'    => 'index',
                'table'   => 'fc_campaign_emails',
                'title'   => __('Campaign Emails Sending Index', 'fluent-crm'),
                'columns' => [
                    ['name' => 'campaign_id'],
                    ['name' => 'status'],
                ],
            ],
            'funnel_subscriber_idx'                  => [
                'type'    => 'unique',
                'table'   => 'fc_funnel_subscribers',
                'title'   => __('Per-Automation Contact Uniqueness', 'fluent-crm'),
                'columns' => [
                    ['name' => 'funnel_id'],
                    ['name' => 'subscriber_id'],
                ],
                'cleanup' => 'funnel_subscribers',
            ],
            'status_next_exec_idx'                   => [
                'type'    => 'index',
                'table'   => 'fc_funnel_subscribers',
                'title'   => __('Automation Runner Performance Indexing', 'fluent-crm'),
                'columns' => [
                    ['name' => 'status'],
                    ['name' => 'next_execution_time'],
                ],
            ],
            'type_action_name_idx'                   => [
                'type'    => 'index',
                'table'   => 'fc_funnel_sequences',
                'title'   => __('Automation Goal Quick Lookup Indexing', 'fluent-crm'),
                'columns' => [
                    ['name' => 'type'],
                    ['name' => 'action_name'],
                ],
            ],
            'funnel_seq_subscriber_unique'           => [
                'type'    => 'unique',
                'table'   => 'fc_funnel_metrics',
                'title'   => __('Automation Contact Metrics Uniqueness', 'fluent-crm'),
                'columns' => [
                    ['name' => 'funnel_id'],
                    ['name' => 'sequence_id'],
                    ['name' => 'subscriber_id'],
                ],
                'cleanup' => 'funnel_metrics',
            ],
            // Serves the `_secure_hash` lookup on unsubscribe and manage-subscription requests.
            // Prefixes stay under MyISAM's 1000-byte key limit; older key(191) indexes still match.
            'subscriber_meta_key_value_idx'          => [
                'type'    => 'index',
                'table'   => 'fc_subscriber_meta',
                'title'   => __('Contact Meta Key/Value Lookup Indexing', 'fluent-crm'),
                'columns' => [
                    ['name' => 'key', 'sub_part' => 100],
                    ['name' => 'value', 'sub_part' => 64],
                ],
            ],
            // Dashboard growth chart and recent contacts. Also covers status-only filtering.
            'subscriber_status_created_idx'          => [
                'type'    => 'index',
                'table'   => 'fc_subscribers',
                'title'   => __('Contact Growth Reporting Indexing', 'fluent-crm'),
                'columns' => [
                    ['name' => 'status'],
                    ['name' => 'created_at'],
                ],
            ],
            // Serves the click/open-stats overview: WHERE type = ? AND created_at BETWEEN.
            'url_metrics_type_created_idx'           => [
                'type'    => 'index',
                'table'   => 'fc_campaign_url_metrics',
                'title'   => __('Click Stats Reporting Indexing', 'fluent-crm'),
                'columns' => [
                    ['name' => 'type'],
                    ['name' => 'created_at'],
                ],
            ],
        ];

        return $indexActions;
    }
}
