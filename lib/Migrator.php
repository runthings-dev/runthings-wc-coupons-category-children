<?php

namespace Runthings\CategoryChildrenCoupons;

if (!defined('WPINC')) {
    die;
}

/**
 * Handles migration of meta keys from old plugin slug to new format.
 * Migrates: runthings_wc_allowed_categories_with_children -> runthings_ccc_allowed_categories_with_children
 *           runthings_wc_excluded_categories_with_children -> runthings_ccc_excluded_categories_with_children
 */
class Migrator
{
    private const OPTION_KEY = 'runthings_ccc_db_version';
    private const CURRENT_DB_VERSION = '1.3.0';

    private const OLD_ALLOWED_META_KEY = 'runthings_wc_allowed_categories_with_children';
    private const OLD_EXCLUDED_META_KEY = 'runthings_wc_excluded_categories_with_children';

    public function __construct()
    {
        add_action('admin_init', [$this, 'maybe_migrate']);
    }

    public function maybe_migrate(): void
    {
        $stored_version = get_option(self::OPTION_KEY, '0');

        if (version_compare($stored_version, self::CURRENT_DB_VERSION, '<')) {
            $this->run_migrations($stored_version);
            update_option(self::OPTION_KEY, self::CURRENT_DB_VERSION);
        }
    }

    private function run_migrations(string $from_version): void
    {
        // Migration from pre-1.3.0 (old meta key format)
        if (version_compare($from_version, '1.3.0', '<')) {
            $this->migrate_meta_keys();
        }
    }

    private function migrate_meta_keys(): void
    {
        global $wpdb;

        $meta_key_mapping = [
            self::OLD_ALLOWED_META_KEY => Plugin::ALLOWED_CATEGORIES_META_KEY,
            self::OLD_EXCLUDED_META_KEY => Plugin::EXCLUDED_CATEGORIES_META_KEY,
        ];

        foreach ($meta_key_mapping as $old_key => $new_key) {
            // Find all coupons with the old meta key
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pm.post_id, pm.meta_value
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                     WHERE pm.meta_key = %s AND p.post_type = 'shop_coupon'",
                    $old_key
                )
            );

            if (empty($results)) {
                continue;
            }

            foreach ($results as $row) {
                $post_id = (int) $row->post_id;
                $meta_value = maybe_unserialize($row->meta_value);

                // Only migrate if there's actual data and new key doesn't exist yet
                if (!empty($meta_value)) {
                    $existing = get_post_meta($post_id, $new_key, true);

                    if (empty($existing)) {
                        update_post_meta($post_id, $new_key, $meta_value);
                    }
                }

                // Delete old meta key after successful migration
                delete_post_meta($post_id, $old_key);
            }
        }
    }
}

