<?php
/**
 * Uninstall script — runs when the plugin is deleted (NOT just deactivated).
 *
 * Drops the submissions table, removes the plugin's options, sweeps rate-limit
 * transients, and cleans the tmp PDF directory.
 *
 * @package estimation-tool-for-woocommerce
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * All uninstall logic is wrapped in a single function so we don't leak
 * variables into the global scope (Plugin Check flags that as a warning).
 */
function estitofo_uninstall_cleanup() {
    global $wpdb;

    // Drop the submissions table.
    $table = $wpdb->prefix . 'estimation_submissions';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema change required for uninstall cleanup; table name is built from $wpdb->prefix.
    $wpdb->query("DROP TABLE IF EXISTS {$table}");

    // Remove plugin options (current + legacy v2.x keys).
    $options = array(
        'estitofo_settings',
        'estitofo_options_migrated',
        'estitofo_db_version',
        'estitofo_company_name',
        'estitofo_company_tagline',
        'estitofo_logo_url',
        'estitofo_footer_text',
        'estitofo_locations',
        'estitofo_heading',
        'estitofo_default_country',
        'estitofo_restrict_country',
        'estitofo_pdf_author',
    );
    foreach ($options as $opt) {
        delete_option($opt);
    }

    // Sweep rate-limit transients we set during form posting. These are stored
    // with the `estitofo_rl_` key prefix (see Estitofo_Plugin::rate_limit()), so
    // the option_name rows are `_transient_estitofo_rl_*` and
    // `_transient_timeout_estitofo_rl_*`.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_estitofo_rl_%' OR option_name LIKE '_transient_timeout_estitofo_rl_%'");

    // Clean up any tmp PDFs left behind by the mailer.
    $upload  = wp_upload_dir();
    $tmp_dir = isset($upload['basedir']) ? trailingslashit($upload['basedir']) . 'wc-estimation-tmp' : '';
    if ($tmp_dir && is_dir($tmp_dir)) {
        $files = glob($tmp_dir . '/*.pdf');
        if (is_array($files)) {
            foreach ($files as $f) {
                wp_delete_file($f);
            }
        }
        // Use WP_Filesystem for the directory removal — Plugin Check requires
        // it for any direct file-system operation.
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        if (WP_Filesystem()) {
            $wp_filesystem->rmdir($tmp_dir);
        }
    }
}

estitofo_uninstall_cleanup();
