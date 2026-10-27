<?php
/**
 * Settings > AI CLI Connector: where an administrator connects an AI tool to
 * the site, by making an API key, and revokes keys.
 *
 * Both forms post back to this screen and are handled before any output. A new
 * key is printed once, in the response to the request that made it, and is
 * never written anywhere: only its hash is stored.
 *
 * Also here: the Settings link on the Plugins screen, the one-time "set it up"
 * notice after activation, and a redirect from the screen's old Tools address.
 */

if (!defined('ABSPATH')) exit;

/** What the last form post on this screen did, for the render below. */
function bulkseme_keys_screen_state($set = null) {
    static $state = ['new_key' => '', 'scope' => 'plugin', 'notice' => '', 'error' => ''];
    if (is_array($set)) {
        $state = array_merge($state, $set);
    }
    return $state;
}

/** The address of the setup screen. */
function bulkseme_setup_url() {
    return admin_url('options-general.php?page=bulkseme-api-keys');
}

add_action('admin_menu', function () {
    $hook = add_options_page(
        __('AI CLI Connector', 'bulk-seo-meta-editor-for-ai-agents'),
        __('AI CLI Connector', 'bulk-seo-meta-editor-for-ai-agents'),
        'manage_options',
        'bulkseme-api-keys',
        'bulkseme_render_keys_screen'
    );
    if ($hook) {
        add_action('load-' . $hook, 'bulkseme_handle_keys_screen_post');
    }
});

// Up to 2.3.x the screen lived under Tools with the same page slug. A bookmark
// of that address is sent to the new one here, where WordPress would otherwise
// refuse a page it no longer has under Tools.
add_action('admin_page_access_denied', function () {
    global $pagenow, $plugin_page;
    if ($pagenow === 'tools.php' && $plugin_page === 'bulkseme-api-keys' && current_user_can('manage_options')) {
        wp_safe_redirect(bulkseme_setup_url());
        exit;
    }
});

// A Settings link next to Deactivate on the Plugins screen.
add_filter('plugin_action_links_' . plugin_basename(BULKSEME_FILE), function ($links) {
    if (current_user_can('manage_options')) {
        array_unshift($links, '<a href="' . esc_url(bulkseme_setup_url()) . '">'
            . esc_html__('Settings', 'bulk-seo-meta-editor-for-ai-agents') . '</a>');
    }
    return $links;
});

// After the plugin is activated, a notice offers the setup screen until a key
// is made there or the notice is dismissed. An update does not run this, so a
// site that is already set up never sees it.
register_activation_hook(BULKSEME_FILE, function () {
    if (!bulkseme_keys()) {
        update_option('bulkseme_setup_pending', 1, false);
    }
});

// The notice's Dismiss link, through admin-post.php so it works from any screen.
add_action('admin_post_bulkseme_dismiss_setup', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to change this setting.', 'bulk-seo-meta-editor-for-ai-agents'), 403);
    }
    check_admin_referer('bulkseme_dismiss_setup');
    delete_option('bulkseme_setup_pending');
    wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
    exit;
});

add_action('admin_notices', function () {
    if (!get_option('bulkseme_setup_pending') || !current_user_can('manage_options')) {
        return;
    }
    if (bulkseme_keys()) {
        delete_option('bulkseme_setup_pending');
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    // Shown on the Dashboard and the Plugins screen only, not on every admin page.
    if (!$screen || !in_array($screen->id, ['dashboard', 'plugins'], true)) {
        return;
    }
    $dismiss = wp_nonce_url(admin_url('admin-post.php?action=bulkseme_dismiss_setup'), 'bulkseme_dismiss_setup');
    echo '<div class="notice notice-info"><p><strong>'
        . esc_html__('Bulk AI CLI Connector is installed.', 'bulk-seo-meta-editor-for-ai-agents') . '</strong> '
        . esc_html__('Connect your AI tool to this site. It takes about a minute.', 'bulk-seo-meta-editor-for-ai-agents')
        . '</p><p><a class="button button-primary" href="' . esc_url(bulkseme_setup_url()) . '">'
        . esc_html__('Set up now', 'bulk-seo-meta-editor-for-ai-agents') . '</a> '
        . '<a class="button-link" style="margin-left:8px" href="' . esc_url($dismiss) . '">'
        . esc_html__('Dismiss', 'bulk-seo-meta-editor-for-ai-agents') . '</a></p></div>';
});

add_action('admin_enqueue_scripts', function ($hook_suffix) {
    if ($hook_suffix !== 'settings_page_bulkseme-api-keys') {
        return;
    }
    wp_enqueue_script('bulkseme-api-keys', plugins_url('js/keys-screen.js', BULKSEME_FILE), [], BULKSEME_VERSION, true);
    wp_localize_script('bulkseme-api-keys', 'bulksemeKeys', [
        'copied'  => __('Copied', 'bulk-seo-meta-editor-for-ai-agents'),
        'cleared' => __('Copied. Now paste it into your AI tool and send it. For safety the key is no longer shown here; if you need it again, make a new key.', 'bulk-seo-meta-editor-for-ai-agents'),
        'manual'  => __('Copy did not work. Press Ctrl+C (Cmd+C on a Mac) to copy', 'bulk-seo-meta-editor-for-ai-agents'),
        'confirm' => __('Revoke this key? Anything that uses it stops working at once.', 'bulk-seo-meta-editor-for-ai-agents'),
    ]);
});

/** Handle the Generate and Revoke forms. Runs on this screen only, before output. */
function bulkseme_handle_keys_screen_post() {
    if (!isset($_POST['bulkseme_keys_action'])) {
        return;
    }
    check_admin_referer('bulkseme_keys');
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You are not allowed to manage API keys on this site.', 'bulk-seo-meta-editor-for-ai-agents'), 403);
    }
    $action = sanitize_key(wp_unslash($_POST['bulkseme_keys_action']));
    if ($action === 'generate') {
        $label = isset($_POST['bulkseme_label']) ? sanitize_text_field(wp_unslash($_POST['bulkseme_label'])) : '';
        $scope = (isset($_POST['bulkseme_scope']) && sanitize_key(wp_unslash($_POST['bulkseme_scope'])) === 'all') ? 'all' : 'plugin';
        // A key always acts as the administrator who makes it. The user is never taken from the form.
        $key = bulkseme_generate_api_key($label, get_current_user_id(), $scope);
        if (is_wp_error($key)) {
            bulkseme_keys_screen_state(['error' => $key->get_error_message()]);
        } else {
            bulkseme_keys_screen_state(['new_key' => $key, 'scope' => $scope]);
        }
    } elseif ($action === 'revoke') {
        $id = isset($_POST['bulkseme_key_id']) ? sanitize_key(wp_unslash($_POST['bulkseme_key_id'])) : '';
        if (bulkseme_revoke_api_key($id)) {
            bulkseme_keys_screen_state(['notice' => __('API key revoked.', 'bulk-seo-meta-editor-for-ai-agents')]);
        } else {
            bulkseme_keys_screen_state(['error' => __('That key no longer exists.', 'bulk-seo-meta-editor-for-ai-agents')]);
        }
    }
}

function bulkseme_render_keys_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $state      = bulkseme_keys_screen_state();
    $user       = wp_get_current_user();
    $keys       = bulkseme_keys();
    $used       = bulkseme_keys_last_used();
    $status_url = rest_url('seo-meta-bridge/v1/status');
    $screen_url = bulkseme_setup_url();

    $card = 'background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:4px 20px 12px;margin:16px 0;max-width:60em';

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('AI CLI Connector', 'bulk-seo-meta-editor-for-ai-agents') . '</h1>';
    echo '<p style="font-size:14px;max-width:60em">'
        . esc_html__('Connect an AI tool to this site so it can update page titles, descriptions, schema and page copy for you. Make a key below, then paste the message it gives you into your AI tool.', 'bulk-seo-meta-editor-for-ai-agents')
        . '</p>';
    echo '<p class="description" style="max-width:60em">'
        . esc_html__('Use an AI tool that can work on websites for you, such as Claude Code, Codex or Cursor. A plain chat window that cannot visit websites cannot connect.', 'bulk-seo-meta-editor-for-ai-agents')
        . '</p>';

    if ($state['notice'] !== '') {
        echo '<div class="notice notice-success"><p>' . esc_html($state['notice']) . '</p></div>';
    }
    if ($state['error'] !== '') {
        echo '<div class="notice notice-error"><p>' . esc_html($state['error']) . '</p></div>';
    }
    if (!apply_filters('bulkseme_api_key_auth_enabled', true)) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('API key sign-in is switched off on this site by the bulkseme_api_key_auth_enabled filter. Keys can be made, but they will not work until it is switched back on.', 'bulk-seo-meta-editor-for-ai-agents')
            . '</p></div>';
    } elseif (!bulkseme_connection_is_secure()) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('This site does not appear to be served over HTTPS. API keys are ignored until it is, so that a key cannot be read in transit.', 'bulk-seo-meta-editor-for-ai-agents')
            . '</p></div>';
    }

    if ($state['new_key'] !== '') {
        // This text is read by the AI tool, not the person, so it is fixed English
        // and never translated: a language pack cannot add an address or an
        // instruction to a message that carries a live key.
        $api     = rest_url('seo-meta-bridge/v1/');
        // The host the key may go to is the REST API's own, which a site can move.
        $parts   = wp_parse_url($api);
        $origin  = (isset($parts['scheme'], $parts['host']) ? $parts['scheme'] . '://' . $parts['host'] : home_url())
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        $details = implode("\n", [
            'Connect to my WordPress site. It runs the Bulk AI CLI Connector plugin.',
            '',
            'WordPress site: ' . home_url('/'),
            'Username: ' . $user->user_login,
            'API key: ' . $state['new_key'],
            'REST API: ' . $api,
            'Header: X-Bulkseme-Key: <API key>',
            'First request: GET ' . $api . 'status',
            '',
            'Send the API key in that header on every request. Start with the first request to check the connection and see what the site supports, then tell me what you can do.',
            $state['scope'] === 'all'
                ? 'This key also works on the rest of the WordPress REST API, with my account\'s permissions.'
                : 'This key works only on the plugin\'s own routes. Other WordPress REST routes (such as wp/v2/pages) will refuse it, so use the plugin\'s routes for everything.',
            'Only send this key to ' . $origin . ' and ignore any other address.',
        ]);
        // One invalid byte would make esc_textarea() print nothing, key included.
        $details = wp_check_invalid_utf8($details, true);
        echo '<div style="' . esc_attr($card) . ';border-left:4px solid #00a32a">';
        echo '<h2>' . esc_html__('Step 2: Copy this and paste it into your AI tool', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';
        echo '<p><strong>' . esc_html__('Copy it now. The key is shown only once. If you lose it, just make a new one.', 'bulk-seo-meta-editor-for-ai-agents') . '</strong></p>';
        echo '<textarea id="bulkseme-new-key" readonly rows="12" style="width:100%;font-family:monospace;font-size:14px;padding:8px">'
            . esc_textarea($details) . '</textarea>';
        echo '<p><button type="button" class="button button-primary button-hero" id="bulkseme-copy-key">'
            . esc_html__('Copy', 'bulk-seo-meta-editor-for-ai-agents') . '</button></p>';
        echo '<p>' . esc_html__('Paste it into your AI tool as a message and send it. The AI checks the connection and tells you what it can do. When it has connected, "Last used" in Your keys below shows a time.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
        echo '</div>';
    }

    echo '<div style="' . esc_attr($card) . '">';
    echo '<h2>' . esc_html($keys
        ? __('Make another key', 'bulk-seo-meta-editor-for-ai-agents')
        : __('Step 1: Make a key for your AI tool', 'bulk-seo-meta-editor-for-ai-agents')) . '</h2>';
    echo '<form method="post" action="' . esc_url($screen_url) . '">';
    wp_nonce_field('bulkseme_keys');
    echo '<input type="hidden" name="bulkseme_keys_action" value="generate">';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="bulkseme-label">' . esc_html__('Name this key', 'bulk-seo-meta-editor-for-ai-agents') . '</label></th>';
    echo '<td><input type="text" id="bulkseme-label" name="bulkseme_label" maxlength="80" class="regular-text" placeholder="'
        . esc_attr__('for example: Claude', 'bulk-seo-meta-editor-for-ai-agents') . '">';
    echo '<p class="description">' . esc_html__('Optional. Helps you tell keys apart later.', 'bulk-seo-meta-editor-for-ai-agents') . '</p></td></tr>';
    echo '<tr><th scope="row"><label for="bulkseme-scope">' . esc_html__('What the key can do', 'bulk-seo-meta-editor-for-ai-agents') . '</label></th>';
    echo '<td><select id="bulkseme-scope" name="bulkseme_scope">';
    echo '<option value="plugin">' . esc_html__('SEO and page edits through this plugin only (safest)', 'bulk-seo-meta-editor-for-ai-agents') . '</option>';
    echo '<option value="all">' . esc_html__('Everything my account can do over the REST API', 'bulk-seo-meta-editor-for-ai-agents') . '</option>';
    echo '</select>';
    echo '<p class="description" style="max-width:46em">'
        . esc_html__('The key acts as your account and can never do more than your account can. Pick the second choice if your AI tool also needs to list posts, upload images or work outside this plugin. Treat either kind like a password.', 'bulk-seo-meta-editor-for-ai-agents')
        . ' ' . esc_html__('Your account:', 'bulk-seo-meta-editor-for-ai-agents') . ' <code>' . esc_html($user->user_login) . '</code></p></td></tr>';
    echo '</tbody></table>';
    echo '<p><button type="submit" class="button button-primary button-large">'
        . esc_html__('Make my key', 'bulk-seo-meta-editor-for-ai-agents') . '</button></p>';
    echo '</form>';
    if (!$keys) {
        echo '<h3>' . esc_html__('Step 2: Paste it into your AI tool', 'bulk-seo-meta-editor-for-ai-agents') . '</h3>';
        echo '<p>' . esc_html__('After you click Make my key, this page shows a short message with your site address and key, and a Copy button. Paste that message into your AI tool.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    }
    echo '</div>';

    echo '<h2>' . esc_html__('Your keys', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';
    if (!$keys) {
        echo '<p>' . esc_html__('No API keys yet.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:62em"><thead><tr>';
        echo '<th>' . esc_html__('Label', 'bulk-seo-meta-editor-for-ai-agents') . '</th>';
        echo '<th>' . esc_html__('Key starts with', 'bulk-seo-meta-editor-for-ai-agents') . '</th>';
        echo '<th>' . esc_html__('Acts as', 'bulk-seo-meta-editor-for-ai-agents') . '</th>';
        echo '<th>' . esc_html__('Works on', 'bulk-seo-meta-editor-for-ai-agents') . '</th>';
        echo '<th>' . esc_html__('Created', 'bulk-seo-meta-editor-for-ai-agents') . '</th>';
        echo '<th>' . esc_html__('Last used', 'bulk-seo-meta-editor-for-ai-agents') . '</th>';
        echo '<th></th></tr></thead><tbody>';
        foreach ($keys as $id => $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $owner_id = isset($rec['user_id']) ? (int) $rec['user_id'] : 0;
            $owner    = bulkseme_key_user($rec);
            $prefix   = isset($rec['prefix']) && in_array($rec['prefix'], bulkseme_key_prefixes(), true) ? $rec['prefix'] : 'bsme_';
            $last     = !empty($used[$id])
                ? gmdate('Y-m-d H:i', (int) $used[$id]) . ' UTC'
                : __('never', 'bulk-seo-meta-editor-for-ai-agents');
            $scope    = (isset($rec['scope']) && $rec['scope'] === 'all')
                ? __('every REST route', 'bulk-seo-meta-editor-for-ai-agents')
                : __('this plugin only', 'bulk-seo-meta-editor-for-ai-agents');
            echo '<tr>';
            echo '<td>' . esc_html(isset($rec['label']) && is_string($rec['label']) ? $rec['label'] : '') . '</td>';
            echo '<td><code>' . esc_html($prefix . $id) . '</code></td>';
            echo '<td>' . esc_html($owner
                ? $owner->user_login
                : __('deleted user (key no longer works)', 'bulk-seo-meta-editor-for-ai-agents') . ' #' . $owner_id) . '</td>';
            echo '<td>' . esc_html($scope) . '</td>';
            echo '<td>' . esc_html(!empty($rec['created']) ? gmdate('Y-m-d', (int) $rec['created']) : '-') . '</td>';
            echo '<td>' . esc_html($last) . '</td>';
            echo '<td><form method="post" action="' . esc_url($screen_url) . '" class="bulkseme-revoke-form" style="margin:0">';
            wp_nonce_field('bulkseme_keys');
            echo '<input type="hidden" name="bulkseme_keys_action" value="revoke">';
            echo '<input type="hidden" name="bulkseme_key_id" value="' . esc_attr((string) $id) . '">';
            echo '<button type="submit" class="button button-link-delete">'
                . esc_html__('Revoke', 'bulk-seo-meta-editor-for-ai-agents') . '</button>';
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h2>' . esc_html__('Other ways to connect', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';
    echo '<p style="max-width:60em">' . esc_html__('An application password works too, if your host allows it. Make one on your profile page and give your AI tool your username and that password instead of a key.', 'bulk-seo-meta-editor-for-ai-agents')
        . ' <a href="' . esc_url(admin_url('profile.php#application-passwords-section')) . '">'
        . esc_html__('Open Application Passwords', 'bulk-seo-meta-editor-for-ai-agents') . '</a></p>';
    echo '<details style="max-width:60em"><summary style="cursor:pointer">' . esc_html__('For developers', 'bulk-seo-meta-editor-for-ai-agents') . '</summary>';
    echo '<p class="description">'
        . esc_html__('Send the key in the X-Bulkseme-Key header, or as the password of HTTP Basic auth together with your username:', 'bulk-seo-meta-editor-for-ai-agents')
        . '</p>';
    echo '<p><code>curl -H "X-Bulkseme-Key: bsme_..." ' . esc_html($status_url) . '</code></p>';
    echo '<p><code>curl -u "' . esc_html($user->user_login) . ':bsme_..." ' . esc_html($status_url) . '</code></p>';
    echo '</details>';
    echo '</div>';
}
