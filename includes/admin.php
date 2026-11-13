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
        'mcpUrl'      => bulkseme_mcp_url(),
        'testing'     => __('Checking...', 'bulk-seo-meta-editor-for-ai-agents'),
        'testOk'      => __('Reachable. AI tools can reach this site; if yours still cannot connect, check the key.', 'bulk-seo-meta-editor-for-ai-agents'),
        'test404'     => __('Not found. The REST API address does not answer: check Settings > Permalinks, or a security plugin that turns the REST API off.', 'bulk-seo-meta-editor-for-ai-agents'),
        /* translators: %s: HTTP status code */
        'testBlocked' => __('Something is in the way (HTTP %s). A firewall or security plugin may be blocking the REST API.', 'bulk-seo-meta-editor-for-ai-agents'),
        'testFailed'  => __('No answer from the site. A firewall may be blocking the REST API.', 'bulk-seo-meta-editor-for-ai-agents'),
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

/**
 * When an AI tool last used the site: the newest of the MCP endpoint's note and
 * the keys' last-used times. Returns ['time' => int, 'client' => string] or [].
 */
function bulkseme_last_ai_use() {
    $seen   = get_option('bulkseme_mcp_seen', []);
    $seen   = is_array($seen) ? $seen : [];
    $time   = isset($seen['time']) ? (int) $seen['time'] : 0;
    $client = '';
    $who    = isset($seen['user']) && is_string($seen['user']) ? $seen['user'] : '';
    if (!empty($seen['client']) && is_string($seen['client'])) {
        $client = $seen['client'] . (!empty($seen['client_version']) && is_string($seen['client_version']) ? ' ' . $seen['client_version'] : '');
    }
    foreach (bulkseme_keys_last_used() as $t) {
        if ((int) $t > $time) {
            $time   = (int) $t;
            $client = '';
            $who    = '';
        }
    }
    return $time ? ['time' => $time, 'client' => $client, 'user' => $who] : [];
}

/** One step of the checklist: a tick or a number, a heading and its body. */
function bulkseme_step_open($done, $number, $title) {
    $circle = 'display:inline-block;width:26px;height:26px;border-radius:50%;text-align:center;line-height:26px;font-weight:600;margin-right:10px;box-sizing:border-box;';
    echo '<div style="border-top:1px solid #dcdcde;padding:14px 0 6px"><h3 style="margin:0 0 6px;display:flex;align-items:center">';
    if ($done) {
        echo '<span aria-hidden="true" style="' . esc_attr($circle . 'background:#00a32a;color:#fff') . '">&#10003;</span>';
    } else {
        echo '<span aria-hidden="true" style="' . esc_attr($circle . 'border:1px solid #8c8f94;color:#50575e;line-height:24px') . '">' . esc_html((string) (int) $number) . '</span>';
    }
    echo esc_html($title);
    if ($done) {
        echo ' <span class="screen-reader-text">' . esc_html__('(done)', 'bulk-seo-meta-editor-for-ai-agents') . '</span>';
    }
    echo '</h3><div style="margin-left:36px">';
}

function bulkseme_step_close() {
    echo '</div></div>';
}

/**
 * The ready-to-use setups for a new key. Fixed English, never translated: they
 * carry a live key, so a language pack must not be able to change an address
 * or add an instruction to them.
 */
function bulkseme_connection_setups($key, $scope, $user_login) {
    $api    = rest_url('seo-meta-bridge/v1/');
    $mcp    = bulkseme_mcp_url();
    $parts  = wp_parse_url($api);
    $origin = (isset($parts['scheme'], $parts['host']) ? $parts['scheme'] . '://' . $parts['host'] : home_url())
        . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    $host   = wp_parse_url(home_url(), PHP_URL_HOST);
    $name   = 'wp-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower(is_string($host) ? $host : 'site'));
    $name   = trim(substr($name, 0, 40), '-');

    // The user pastes this into a shell. The site address comes from settings and filters, so the
    // command is left out entirely when the address holds anything but plain URL characters, and none
    // of those is special inside double quotes in bash, zsh, fish, PowerShell or cmd.exe (no $ ` \ " ! % &). The key and the name are fixed shapes ([a-z0-9_] and [a-z0-9-]).
    $claude = preg_match('#^https?://[A-Za-z0-9._~:/?\#=+@-]+$#D', $mcp) && bulkseme_looks_like_key($key)
        ? 'claude mcp add --transport http ' . $name . ' "' . $mcp . '" --header "Authorization: Bearer ' . $key . '"'
        : '# This site address holds characters that are not safe to paste into a terminal. Use the Cursor, VS Code setup instead.';
    $json   = (string) wp_json_encode(['mcpServers' => [$name => [
        'url'     => $mcp,
        'headers' => ['Authorization' => 'Bearer ' . $key],
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $message = implode("\n", [
        'Connect to my WordPress site. It runs the Bulk AI CLI Connector plugin.',
        '',
        'WordPress site: ' . home_url('/'),
        'Username: ' . $user_login,
        'API key: ' . $key,
        'MCP server (Streamable HTTP): ' . $mcp,
        'REST API: ' . $api,
        'Header: X-Bulkseme-Key: <API key>  (or Authorization: Bearer <API key>)',
        'First request: GET ' . $api . 'status',
        '',
        'If you can add MCP servers, add the MCP server above with the header. Otherwise send the API key in that header on every REST request. Start with the first request to check the connection and see what the site supports, then tell me what you can do.',
        $scope === 'all'
            ? 'This key also works on the rest of the WordPress REST API, with my account\'s permissions.'
            : 'This key works only on the plugin\'s own routes. Other WordPress REST routes (such as wp/v2/pages) will refuse it, so use the plugin\'s routes for everything.',
        'Only send this key to ' . $origin . ' and ignore any other address.',
    ]);
    // One invalid byte would make esc_textarea() print nothing, key included.
    return [
        'claude' => wp_check_invalid_utf8($claude, true),
        'json'   => wp_check_invalid_utf8($json, true),
        'other'  => wp_check_invalid_utf8($message, true),
    ];
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
    $last       = bulkseme_last_ai_use();
    $has_key    = (bool) $keys;
    $working    = apply_filters('bulkseme_api_key_auth_enabled', true) && bulkseme_connection_is_secure();

    $card = 'background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:6px 20px 14px;margin:16px 0;max-width:62em';

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('AI CLI Connector', 'bulk-seo-meta-editor-for-ai-agents') . '</h1>';
    if ($last) {
        // No sprintf over translated text: a language pack's format string must never be able to stop the screen.
        echo '<p style="font-size:14px;color:#00a32a;font-weight:600">'
            . esc_html__('Connected. Your AI last used this site at', 'bulk-seo-meta-editor-for-ai-agents') . ' '
            . esc_html(gmdate('Y-m-d H:i', $last['time']) . ' UTC') . '</p>';
    }
    echo '<p style="font-size:14px;max-width:62em">'
        . esc_html__('Let an AI tool update page titles, descriptions, schema, image alt text, redirects and page copy on this site. Every content change is backed up first and can be undone.', 'bulk-seo-meta-editor-for-ai-agents')
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

    echo '<div style="' . esc_attr($card) . '">';
    echo '<h2>' . esc_html__('Connect your AI to this site', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';

    // 1. The plugin.
    bulkseme_step_open(true, 1, __('Install the plugin', 'bulk-seo-meta-editor-for-ai-agents'));
    echo '<p>' . esc_html__('Done. The plugin is active.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    bulkseme_step_close();

    // 2. A key, or the setups for the key just made.
    bulkseme_step_open($has_key, 2, __('Make a key for your AI tool', 'bulk-seo-meta-editor-for-ai-agents'));
    if ($state['new_key'] !== '') {
        $setups = bulkseme_connection_setups($state['new_key'], $state['scope'], $user->user_login);
        $tabs   = [
            'claude' => [__('Claude Code', 'bulk-seo-meta-editor-for-ai-agents'), __('Run this in a terminal. Claude Code then has the site\'s tools in every session.', 'bulk-seo-meta-editor-for-ai-agents'), 3],
            'json'   => [__('Cursor, VS Code, Windsurf', 'bulk-seo-meta-editor-for-ai-agents'), __('Add this to the tool\'s MCP settings file (in Cursor: Settings > MCP > Add new server). Use your user-level settings, not a project file that may be shared or committed.', 'bulk-seo-meta-editor-for-ai-agents'), 9],
            'other'  => [__('Any other AI tool', 'bulk-seo-meta-editor-for-ai-agents'), __('Paste this into your AI tool as a message and send it. It works for tools that can reach websites, such as Codex.', 'bulk-seo-meta-editor-for-ai-agents'), 14],
        ];
        echo '<div style="border-left:4px solid #00a32a;padding-left:14px">';
        echo '<p><strong>' . esc_html__('Your key is ready. Pick your AI tool and copy its setup now: the key is shown only once. If you lose it, just make a new one.', 'bulk-seo-meta-editor-for-ai-agents') . '</strong></p>';
        echo '<p class="bulkseme-tabs" role="tablist">';
        $first = true;
        foreach ($tabs as $slug => $tab) {
            echo '<button type="button" role="tab" class="button' . ($first ? ' button-primary' : '') . '" data-bulkseme-tab="' . esc_attr($slug) . '" aria-selected="' . ($first ? 'true' : 'false') . '" style="margin:0 6px 6px 0">'
                . esc_html($tab[0]) . '</button>';
            $first = false;
        }
        echo '</p>';
        $first = true;
        foreach ($tabs as $slug => $tab) {
            echo '<div class="bulkseme-setup" data-bulkseme-panel="' . esc_attr($slug) . '"' . ($first ? '' : ' hidden') . '>';
            echo '<p>' . esc_html($tab[1]) . '</p>';
            echo '<textarea class="bulkseme-secret" readonly rows="' . (int) $tab[2] . '" style="width:100%;font-family:monospace;font-size:13px;padding:8px">'
                . esc_textarea($setups[$slug]) . '</textarea>';
            echo '<p><button type="button" class="button button-primary button-hero bulkseme-copy">'
                . esc_html__('Copy', 'bulk-seo-meta-editor-for-ai-agents') . '</button></p>';
            echo '</div>';
            $first = false;
        }
        echo '<p class="description">' . esc_html__('claude.ai and the ChatGPT app add sites through a sign-in screen that this version does not offer yet. Use one of the tools above.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
        echo '</div>';
    } else {
        if ($has_key) {
            echo '<p>' . esc_html__('Keys on this site:', 'bulk-seo-meta-editor-for-ai-agents') . ' ' . esc_html((string) count($keys)) . '. '
                . esc_html__('Make another one for a new AI tool or computer.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
        }
        echo '<form method="post" action="' . esc_url($screen_url) . '">';
        wp_nonce_field('bulkseme_keys');
        echo '<input type="hidden" name="bulkseme_keys_action" value="generate">';
        echo '<p><label for="bulkseme-label"><strong>' . esc_html__('Name this key', 'bulk-seo-meta-editor-for-ai-agents') . '</strong></label><br>';
        echo '<input type="text" id="bulkseme-label" name="bulkseme_label" maxlength="80" class="regular-text" placeholder="'
            . esc_attr__('for example: Claude on my laptop', 'bulk-seo-meta-editor-for-ai-agents') . '"></p>';
        echo '<p><label for="bulkseme-scope"><strong>' . esc_html__('What the key can do', 'bulk-seo-meta-editor-for-ai-agents') . '</strong></label><br>';
        echo '<select id="bulkseme-scope" name="bulkseme_scope">';
        echo '<option value="plugin">' . esc_html__('SEO and page edits through this plugin only (safest)', 'bulk-seo-meta-editor-for-ai-agents') . '</option>';
        echo '<option value="all">' . esc_html__('Everything my account can do over the REST API', 'bulk-seo-meta-editor-for-ai-agents') . '</option>';
        echo '</select></p>';
        echo '<p class="description" style="max-width:48em">'
            . esc_html__('The key acts as your account and can never do more than your account can. The first choice covers every tool this plugin gives your AI. Pick the second only if your AI also needs to work outside this plugin, such as uploading images. Treat either kind like a password.', 'bulk-seo-meta-editor-for-ai-agents')
            . ' ' . esc_html__('Your account:', 'bulk-seo-meta-editor-for-ai-agents') . ' <code>' . esc_html($user->user_login) . '</code></p>';
        echo '<p><button type="submit" class="button button-primary button-large">'
            . esc_html__('Make my key', 'bulk-seo-meta-editor-for-ai-agents') . '</button></p>';
        echo '</form>';
    }
    bulkseme_step_close();

    // 3. Add it to the AI tool.
    bulkseme_step_open((bool) $last, 3, __('Add it to your AI tool', 'bulk-seo-meta-editor-for-ai-agents'));
    echo '<p>' . esc_html__('After you make a key, this page gives you a ready setup for Claude Code, for Cursor and VS Code, and a message for any other AI tool. Nothing to install on your computer.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    echo '<p>' . esc_html__('MCP server address:', 'bulk-seo-meta-editor-for-ai-agents') . ' <code>' . esc_html(bulkseme_mcp_url()) . '</code></p>';
    bulkseme_step_close();

    // 4. Proof that it worked.
    bulkseme_step_open((bool) $last, 4, __('Your AI has used this site', 'bulk-seo-meta-editor-for-ai-agents'));
    if ($last) {
        echo '<p>' . esc_html__('Confirmed at', 'bulk-seo-meta-editor-for-ai-agents') . ' ' . esc_html(gmdate('Y-m-d H:i:s', $last['time']) . ' UTC.');
        if ($last['client'] !== '') {
            echo ' ' . esc_html__('Client:', 'bulk-seo-meta-editor-for-ai-agents') . ' <code>' . esc_html($last['client']) . '</code>';
        }
        if ($last['user'] !== '') {
            echo ' ' . esc_html__('Account:', 'bulk-seo-meta-editor-for-ai-agents') . ' <code>' . esc_html($last['user']) . '</code>';
        }
        echo '</p>';
    } else {
        echo '<p>' . esc_html__('Not yet. This turns green the first time your AI tool reaches the site with a key.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    }
    echo '<p><button type="button" class="button" id="bulkseme-test">' . esc_html__('Check the site can be reached', 'bulk-seo-meta-editor-for-ai-agents') . '</button> '
        . '<span id="bulkseme-test-result" role="status" style="margin-left:8px"></span></p>';
    echo '<p class="description" style="max-width:48em">' . esc_html__('Run this if your AI reports a connection problem. It asks the MCP server address from your browser without signing in, the way an AI tool first reaches it, so it shows whether a firewall or security plugin is in the way. Nothing is sent anywhere else.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    if (!$working) {
        echo '<p><strong>' . esc_html__('Keys will not work until the warning at the top of this page is fixed.', 'bulk-seo-meta-editor-for-ai-agents') . '</strong></p>';
    }
    bulkseme_step_close();
    echo '</div>';

    // Undo.
    echo '<div style="' . esc_attr($card) . '">';
    echo '<h2>' . esc_html__('Undo', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';
    echo '<p style="max-width:60em">' . esc_html__('Before your AI changes the text of a page or a category description, the old version is saved. The ten newest versions of each page are kept. To go back, ask your AI, for example: "Undo your last change to the About page."', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    echo '</div>';

    // Keys.
    echo '<div style="' . esc_attr($card) . '">';
    echo '<h2>' . esc_html__('Your keys', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';
    if (!$keys) {
        echo '<p>' . esc_html__('No API keys yet.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr>';
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
            $owner_id  = isset($rec['user_id']) ? (int) $rec['user_id'] : 0;
            $owner     = bulkseme_key_user($rec);
            $prefix    = isset($rec['prefix']) && in_array($rec['prefix'], bulkseme_key_prefixes(), true) ? $rec['prefix'] : 'bsme_';
            $last_used = !empty($used[$id])
                ? gmdate('Y-m-d H:i', (int) $used[$id]) . ' UTC'
                : __('never', 'bulk-seo-meta-editor-for-ai-agents');
            $scope     = (isset($rec['scope']) && $rec['scope'] === 'all')
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
            echo '<td>' . esc_html($last_used) . '</td>';
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
    echo '</div>';

    // Disconnecting.
    echo '<div style="' . esc_attr($card) . '">';
    echo '<h2>' . esc_html__('Disconnecting an AI tool', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';
    echo '<p style="max-width:60em">' . esc_html__('Revoke its key in the table above. It stops working at once. Deactivating this plugin also stops every key, because only this plugin accepts them.', 'bulk-seo-meta-editor-for-ai-agents') . '</p>';
    echo '<p style="max-width:60em">' . esc_html__('If you ever gave an AI tool an application password instead of a key, deactivating this plugin does not stop it: revoke it on your profile page.', 'bulk-seo-meta-editor-for-ai-agents')
        . ' <a href="' . esc_url(admin_url('profile.php#application-passwords-section')) . '">'
        . esc_html__('Open Application Passwords', 'bulk-seo-meta-editor-for-ai-agents') . '</a></p>';
    echo '</div>';

    echo '<details style="max-width:62em"><summary style="cursor:pointer">' . esc_html__('For developers', 'bulk-seo-meta-editor-for-ai-agents') . '</summary>';
    echo '<p class="description">'
        . esc_html__('The MCP server answers JSON-RPC over POST (Streamable HTTP, no event stream). Send the key as Authorization: Bearer, in the X-Bulkseme-Key header, or as the password of HTTP Basic auth together with your username. An application password works on every route too.', 'bulk-seo-meta-editor-for-ai-agents')
        . '</p>';
    echo '<p><code>curl -H "X-Bulkseme-Key: bsme_..." ' . esc_html($status_url) . '</code></p>';
    echo '<p><code>curl -u "' . esc_html($user->user_login) . ':bsme_..." ' . esc_html($status_url) . '</code></p>';
    echo '</details>';
    echo '</div>';
}
