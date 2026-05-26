<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$estitofo_table = $wpdb->prefix . 'estimation_submissions';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema change required for uninstall cleanup; table name is built from $wpdb->prefix.
$wpdb->query("DROP TABLE IF EXISTS {$estitofo_table}");

$estitofo_options = array(
    // v3.x consolidated option (this is the main one now).
    'estitofo_settings',
    'estitofo_options_migrated',
    'estitofo_db_version',
    // v2.x legacy individual options.
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
foreach ($estitofo_options as $estitofo_opt) {
    delete_option($estitofo_opt);
}

// Sweep any rate-limit transients we set during form posting.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_est_rl_%' OR option_name LIKE '_transient_timeout_wc_est_rl_%'");

// Clean up any tmp PDFs left behind by the mailer.
$upload   = wp_upload_dir();
$tmp_dir  = isset($upload['basedir']) ? trailingslashit($upload['basedir']) . 'wc-estimation-tmp' : '';
if ($tmp_dir && is_dir($tmp_dir)) {
    $files = glob($tmp_dir . '/*.pdf');
    if (is_array($files)) {
        foreach ($files as $f) {
            @unlink($f); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }
    @rmdir($tmp_dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
