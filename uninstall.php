<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes the API key hashes, on every site of a network, so no way of signing
 * in is left behind, and the SEO change history, which names the users who made
 * the changes. Content the site may still want is kept: stored schema,
 * redirect rules, the merchant policy and content backups. Delete those by
 * hand if they are no longer needed.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

/** Remove one-click sign-in connection rows and end markers (2.6.0), found through their two index options. */
function bulkseme_uninstall_oauth_rows() {
    foreach (array('bulkseme_oauth_grants' => 'bulkseme_oauth_g_', 'bulkseme_oauth_revoked' => 'bulkseme_oauth_rv_') as $bulkseme_list => $bulkseme_prefix) {
        $bulkseme_ids = get_option($bulkseme_list, array());
        foreach (is_array($bulkseme_ids) ? array_keys($bulkseme_ids) : array() as $bulkseme_id) {
            // An all-digit id becomes an integer array key, so cast before checking.
            $bulkseme_id = (string) $bulkseme_id;
            if (preg_match('/^[0-9a-f]{12}$/D', $bulkseme_id)) {
                delete_option($bulkseme_prefix . $bulkseme_id);
            }
        }
    }
    // Rows no list points to any more (a write interrupted half way) are found by their prefix.
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall clean-up by prefix; nothing reads these rows afterwards.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('bulkseme_oauth_g_') . '%', $wpdb->esc_like('bulkseme_oauth_rv_') . '%'));
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $bulkseme_site_id) {
        switch_to_blog((int) $bulkseme_site_id);
        delete_option('bulkseme_api_keys');
        delete_option('bulkseme_api_keys_used');
        delete_option('bulkseme_setup_pending');
        delete_option('bulkseme_mcp_seen');
        bulkseme_uninstall_oauth_rows();
        delete_option('bulkseme_oauth_grants');
        delete_option('bulkseme_oauth_revoked');
        delete_option('bulkseme_oauth_used');
        delete_option('bulkseme_oauth_off');
        delete_post_meta_by_key('_bulkseme_seo_history');
        restore_current_blog();
    }
} else {
    delete_option('bulkseme_api_keys');
    delete_option('bulkseme_api_keys_used');
    delete_option('bulkseme_setup_pending');
    delete_option('bulkseme_mcp_seen');
    bulkseme_uninstall_oauth_rows();
    delete_option('bulkseme_oauth_grants');
    delete_option('bulkseme_oauth_revoked');
    delete_option('bulkseme_oauth_used');
    delete_option('bulkseme_oauth_off');
    delete_post_meta_by_key('_bulkseme_seo_history');
}
