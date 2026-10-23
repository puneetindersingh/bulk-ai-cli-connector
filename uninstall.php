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

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $bulkseme_site_id) {
        switch_to_blog((int) $bulkseme_site_id);
        delete_option('bulkseme_api_keys');
        delete_option('bulkseme_api_keys_used');
        delete_post_meta_by_key('_bulkseme_seo_history');
        restore_current_blog();
    }
} else {
    delete_option('bulkseme_api_keys');
    delete_option('bulkseme_api_keys_used');
    delete_post_meta_by_key('_bulkseme_seo_history');
}
