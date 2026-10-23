<?php
/**
 * Updating this plugin from outside wp-admin.
 *
 *   GET  /update   which version is installed and which one WordPress.org offers
 *   POST /update   { "check": true }     ask WordPress to look for a new version now
 *                  { "install": true }   install the version WordPress.org offers, now
 *
 * A site that is managed over the API should not need someone to sign in to
 * wp-admin and press Update before a fix reaches it. Everything here is
 * WordPress's own update code, the code the Plugins screen runs:
 *
 *   - The new version comes from WordPress.org through WordPress itself. No
 *     address, file name or version number can be passed in, so the route can
 *     never install anything but the official build of this plugin.
 *   - Installing needs the right to update plugins: an administrator, or on a
 *     network a network administrator. A key with the default scope cannot
 *     reach this route at all (see bulkseme_own_routes()).
 *   - A site that forbids file changes (DISALLOW_FILE_MODS) is left alone:
 *     WordPress itself withholds the right to update plugins there.
 *   - Nothing about how WordPress updates plugins is changed. Automatic updates
 *     stay whatever the site owner set on the Plugins screen.
 */

if (!defined('ABSPATH')) exit;

/** This plugin as WordPress names it: folder/file.php. */
function bulkseme_update_plugin_file() {
    return plugin_basename(BULKSEME_FILE);
}

/**
 * A yes or no parameter, read strictly. Returns true, false, or null for
 * anything else: "no", a number other than 0 or 1, a list. An install is never
 * started by a value that merely happens to be non-empty.
 */
function bulkseme_param_flag($value) {
    if (is_bool($value)) {
        return $value;
    }
    if ($value === 1 || $value === '1' || $value === 'true') {
        return true;
    }
    if ($value === 0 || $value === '0' || $value === 'false') {
        return false;
    }
    return null;
}

/** What is installed and what WordPress.org offers, as WordPress last saw it. */
function bulkseme_update_state() {
    $file  = bulkseme_update_plugin_file();
    $found = get_site_transient('update_plugins');
    $offer = (is_object($found) && isset($found->response) && is_array($found->response) && isset($found->response[$file]) && is_object($found->response[$file]))
        ? $found->response[$file]
        : null;
    return [
        'plugin'               => $file,
        'installed'            => BULKSEME_VERSION,
        'available'            => ($offer && !empty($offer->new_version) && is_string($offer->new_version)) ? $offer->new_version : null,
        'checked'              => (is_object($found) && !empty($found->last_checked)) ? (int) $found->last_checked : 0,
        // The server's own clock, so a last check stamped in the future is visible without guessing.
        'server_time'          => time(),
        'checked_in_future'    => is_object($found) && !empty($found->last_checked) && (int) $found->last_checked > time(),
        'file_changes_allowed' => (bool) wp_is_file_mod_allowed('capability_update_core'),
    ];
}

/**
 * Ask WordPress to look for plugin updates now, not at its next scheduled check.
 * Returns true when WordPress.org answered, false when it could not be reached.
 *
 * To make WordPress ask again it first has to forget what it last heard, for
 * every plugin. If WordPress.org then does not answer, that knowledge would be
 * gone: the update notices of all plugins would vanish from wp-admin until the
 * next scheduled check, and this plugin would look up to date when it is not.
 * So what WordPress knew is kept, and put back when the check fails.
 */
function bulkseme_update_check_now() {
    $before = get_site_transient('update_plugins');
    // Asked and answered less than a minute ago: that answer still stands. Only an
    // age between 0 and 60 seconds counts. A last check stamped in the future
    // (seen live, cause unknown) would otherwise count as "just checked" until the
    // clock caught up, and the site would answer "up to date" for that long; such
    // a stamp is treated as stale, so the check runs.
    $age = (is_object($before) && !empty($before->last_checked)) ? time() - (int) $before->last_checked : null;
    if (is_object($before) && isset($before->checked) && $age !== null && $age >= 0 && $age < MINUTE_IN_SECONDS) {
        return true;
    }
    if (!function_exists('wp_clean_plugins_cache')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    wp_clean_plugins_cache(true);
    wp_update_plugins();
    $after = get_site_transient('update_plugins');
    if (is_object($after) && isset($after->checked)) {
        return true; // WordPress records the list it checked only when WordPress.org answered
    }
    if (is_object($before)) {
        // Put back what WordPress knew, but not a last check stamped in the future: core
        // takes that as "just checked" too and would hold its own scheduled checks.
        if (!empty($before->last_checked) && (int) $before->last_checked > time()) {
            $before->last_checked = 0;
        }
        set_site_transient('update_plugins', $before);
    } else {
        delete_site_transient('update_plugins');
    }
    return false;
}

/**
 * Install the version WordPress.org offers, the way the Plugins screen does it
 * (wp_ajax_update_plugin): the plugin stays active and the site shows its
 * maintenance notice for the few seconds the files are swapped.
 * Returns ['updated' => bool, ...] or a WP_Error.
 *
 * The caller holds the "bulkseme_update" lock (see the route below). Two
 * upgraders working on the same folder at once empty each other's files.
 */
function bulkseme_update_install() {
    if (!wp_is_file_mod_allowed('capability_update_core')) {
        return new WP_Error('file_changes_disabled', 'this site does not allow plugin files to be changed (DISALLOW_FILE_MODS)', ['status' => 409]);
    }
    $file  = bulkseme_update_plugin_file();
    $state = bulkseme_update_state();
    if ($state['available'] === null || version_compare($state['available'], BULKSEME_VERSION, '<=')) {
        return ['updated' => false, 'reason' => 'up_to_date', 'installed' => BULKSEME_VERSION];
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    // WordPress must be able to write the files without asking for FTP details, as for a REST install.
    if (get_filesystem_method() !== 'direct') {
        ob_start();
        $stored = request_filesystem_credentials(self_admin_url());
        ob_end_clean();
        if (!$stored) {
            return new WP_Error('filesystem_unavailable', 'WordPress cannot write plugin files on this site without FTP details', ['status' => 500]);
        }
    }
    $skin     = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result   = $upgrader->bulk_upgrade([$file]);
    if (is_wp_error($skin->result)) {
        return new WP_Error('update_failed', $skin->result->get_error_message(), ['status' => 500]);
    }
    if ($skin->get_errors()->has_errors()) {
        return new WP_Error('update_failed', $skin->get_error_messages(), ['status' => 500]);
    }
    if (is_array($result) && !empty($result[$file])) {
        if ($result[$file] === true) {
            return ['updated' => false, 'reason' => 'up_to_date', 'installed' => BULKSEME_VERSION];
        }
        $now     = isset($result[$file]['destination_name']) ? get_plugins('/' . $result[$file]['destination_name']) : [];
        $now     = $now ? reset($now) : [];
        $version = isset($now['Version']) ? (string) $now['Version'] : '';
        // This request still runs the old code, so the state is stated here by hand.
        return ['updated' => true, 'from' => BULKSEME_VERSION, 'to' => $version, 'installed' => $version, 'available' => null];
    }
    if ($result === false) {
        return new WP_Error('filesystem_unavailable', 'WordPress could not reach the file system to install the update', ['status' => 500]);
    }
    return new WP_Error('update_failed', 'the update did not complete', ['status' => 500]);
}

add_action('rest_api_init', function () {
    register_rest_route('seo-meta-bridge/v1', '/update', [
        [
            'methods'             => 'GET',
            // Reading the two version numbers is open to an administrator even where
            // WordPress withholds the right to update plugins (a site that forbids file changes).
            'permission_callback' => function () {
                return current_user_can('update_plugins') || current_user_can('manage_options');
            },
            'callback'            => function () {
                return bulkseme_update_state();
            },
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => function () {
                return current_user_can('update_plugins');
            },
            'callback'            => function (WP_REST_Request $req) {
                $flags = [];
                foreach (['check', 'install'] as $name) {
                    $raw = $req->get_param($name);
                    if ($raw === null) {
                        $flags[$name] = null;
                        continue;
                    }
                    $flags[$name] = bulkseme_param_flag($raw);
                    if ($flags[$name] === null) {
                        return new WP_Error('invalid_param', $name . ' must be true or false', ['status' => 400]);
                    }
                }
                if ($flags['check'] === null && $flags['install'] === null) {
                    return new WP_Error('nothing_to_do', 'send check or install', ['status' => 400]);
                }
                // A site that forbids file changes is told so before anything else is done.
                if ($flags['install'] && !wp_is_file_mod_allowed('capability_update_core')) {
                    return new WP_Error('file_changes_disabled', 'this site does not allow plugin files to be changed (DISALLOW_FILE_MODS)', ['status' => 409]);
                }
                if (!$flags['check'] && !$flags['install']) {
                    return bulkseme_update_state(); // both sent as false: nothing to do
                }
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                // One check or install at a time. This is WordPress's own upgrade lock, taken in a
                // single database step, so two calls that arrive together cannot both get past it.
                if (!WP_Upgrader::create_lock('bulkseme_update', 5 * MINUTE_IN_SECONDS)) {
                    return new WP_Error('update_running', 'a check or an update of this plugin is already running', ['status' => 409]);
                }
                try {
                    if (!bulkseme_update_check_now()) {
                        return new WP_Error('check_failed', 'WordPress could not reach WordPress.org; nothing was changed', ['status' => 503]);
                    }
                    $out = [];
                    if ($flags['install']) {
                        $done = bulkseme_update_install();
                        if (is_wp_error($done)) {
                            return $done;
                        }
                        $out = $done;
                    }
                    return array_merge(bulkseme_update_state(), $out);
                } finally {
                    WP_Upgrader::release_lock('bulkseme_update');
                }
            },
        ],
    ]);
});
