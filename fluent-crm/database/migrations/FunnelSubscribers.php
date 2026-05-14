<?php

namespace FluentCrmMigrations;

class FunnelSubscribers
{
    /**
     * Migrate the table.
     *
     * @param bool $isForced
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix .'fc_funnel_subscribers';

        $indexPrefix = $wpdb->prefix .'fc_fsx_';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `funnel_id` BIGINT UNSIGNED NULL,
                `starting_sequence_id` BIGINT UNSIGNED NULL,
                `next_sequence` BIGINT UNSIGNED NULL,
                `subscriber_id` BIGINT UNSIGNED NULL,
                `last_sequence_id` BIGINT UNSIGNED NULL,
                `next_sequence_id` BIGINT UNSIGNED NULL,
                `last_sequence_status` VARCHAR(50) DEFAULT 'pending',
                `status` VARCHAR(50) DEFAULT 'active',
                `type` VARCHAR(50) DEFAULT 'funnel',
                `last_executed_time` TIMESTAMP NULL,
                `next_execution_time` TIMESTAMP NULL,
                `notes` TEXT NULL,
                `source_trigger_name` VARCHAR(192) NULL,
                `source_ref_id` BIGINT UNSIGNED NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `{$indexPrefix}_fidx` (`funnel_id` ASC),
                INDEX `{$indexPrefix}_fsq_idx` (`subscriber_id` ASC),
                KEY `status` (`status`),
                KEY `type` (`type`),
                KEY `next_execution_time` (`next_execution_time`),
                KEY `next_sequence` (`next_sequence`),
                UNIQUE KEY `funnel_subscriber_idx` (`funnel_id`, `subscriber_id`),
                KEY `status_next_exec_idx` (`status`, `next_execution_time`)
            ) $charsetCollate;";
            dbDelta($sql);
        } else {

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $indexes = $wpdb->get_results("SHOW INDEX FROM $table");
            $indexedColumns = [];
            foreach ($indexes as $index) {
                $indexedColumns[] = $index->Column_name;
            }

            if(!in_array('status', $indexedColumns)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $sql = "ALTER TABLE {$table} ADD INDEX `status` (`status`),
                        ADD INDEX `type` (`type`),
                        ADD INDEX `next_execution_time` (`next_execution_time`),
                        ADD INDEX `next_sequence` (`next_sequence`);";

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query($sql);
            }

            $indexNames = [];
            foreach ($indexes as $index) {
                $indexNames[] = $index->Key_name;
            }

            // Upgrade non-unique index to UNIQUE: remove duplicates first (keep newest), then replace index
            if (in_array('funnel_subscriber_idx', $indexNames)) {
                // Check if it's already unique
                $isUnique = false;
                foreach ($indexes as $index) {
                    if ($index->Key_name === 'funnel_subscriber_idx' && $index->Non_unique == 0) {
                        $isUnique = true;
                        break;
                    }
                }

                if (!$isUnique) {
                    // Check if any duplicates exist before running cleanup
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $hasDuplicates = $wpdb->get_var("SELECT COUNT(*) FROM (
                        SELECT funnel_id, subscriber_id
                        FROM {$table}
                        GROUP BY funnel_id, subscriber_id
                        HAVING COUNT(*) > 1
                    ) dups");

                    if ($hasDuplicates) {
                        // Remove duplicate funnel subscribers, keeping the newest (highest id) per funnel+subscriber pair
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $wpdb->query("DELETE fs FROM {$table} fs
                            INNER JOIN (
                                SELECT funnel_id, subscriber_id, MAX(id) as keep_id
                                FROM {$table}
                                GROUP BY funnel_id, subscriber_id
                                HAVING COUNT(*) > 1
                            ) dups ON fs.funnel_id = dups.funnel_id
                                AND fs.subscriber_id = dups.subscriber_id
                                AND fs.id != dups.keep_id");
                    }

                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $wpdb->query("ALTER TABLE {$table} DROP INDEX `funnel_subscriber_idx`, ADD UNIQUE KEY `funnel_subscriber_idx` (`funnel_id`, `subscriber_id`)");
                }
            } elseif (!in_array('funnel_subscriber_idx', $indexNames)) {
                // Fresh install: add as unique from the start
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY `funnel_subscriber_idx` (`funnel_id`, `subscriber_id`)");
            }

            // Add composite index for cron heartbeat query (runs every 60 seconds)
            if (!in_array('status_next_exec_idx', $indexNames)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$table} ADD INDEX `status_next_exec_idx` (`status`, `next_execution_time`)");
            }

        }
    }
}
