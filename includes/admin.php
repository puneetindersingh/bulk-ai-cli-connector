<?php
/**
 * Tools > Agent API Keys: where an administrator makes and revokes API keys.
 *
 * Both forms post back to this screen and are handled before any output. A new
 * key is printed once, in the response to the request that made it, and is
 * never written anywhere: only its hash is stored.
 */

if (!defined('ABSPATH')) exit;

/** What the last form post on this screen did, for the render below. */
function bulkseme_keys_screen_state($set = null) {
    static $state = ['new_key' => '', 'notice' => '', 'error' => ''];
    if (is_array($set)) {
        $state = array_merge($state, $set);
    }
    return $state;
}

add_action('admin_menu', function () {
    $hook = add_management_page(
        __('Agent API Keys', 'bulk-seo-meta-editor-for-ai-agents'),
        __('Agent API Keys', 'bulk-seo-meta-editor-for-ai-agents'),
        'manage_options',
        'bulkseme-api-keys',
        'bulkseme_render_keys_screen'
    );
    if ($hook) {
        add_action('load-' . $hook, 'bulkseme_handle_keys_screen_post');
    }
});

add_action('admin_enqueue_scripts', function ($hook_suffix) {
    if ($hook_suffix !== 'tools_page_bulkseme-api-keys') {
        return;
    }
    wp_enqueue_script('bulkseme-api-keys', plugins_url('js/keys-screen.js', BULKSEME_FILE), [], BULKSEME_VERSION, true);
    wp_localize_script('bulkseme-api-keys', 'bulksemeKeys', [
        'copied'  => __('Copied', 'bulk-seo-meta-editor-for-ai-agents'),
        'cleared' => __('Copied. The key has been cleared from this page.', 'bulk-seo-meta-editor-for-ai-agents'),
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
            bulkseme_keys_screen_state(['new_key' => $key]);
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
    $screen_url = admin_url('tools.php?page=bulkseme-api-keys');

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Agent API Keys', 'bulk-seo-meta-editor-for-ai-agents') . '</h1>';
    echo '<p class="description" style="max-width:62em">'
        . esc_html__('An API key lets an AI agent, a script or another tool reach this site over the WordPress REST API without an application password. Use it where a security plugin or the host blocks application passwords. A key is shown once, stored only as a hash, and can be revoked below at any time.', 'bulk-seo-meta-editor-for-ai-agents')
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
        echo '<div class="notice notice-success"><p><strong>'
            . esc_html__('Your new API key. Copy it now: it will not be shown again.', 'bulk-seo-meta-editor-for-ai-agents')
            . '</strong></p><p>';
        echo '<input type="text" id="bulkseme-new-key" readonly value="' . esc_attr($state['new_key'])
            . '" style="width:42em;max-width:100%;font-family:monospace;font-size:14px;padding:6px"> ';
        echo '<button type="button" class="button button-primary" id="bulkseme-copy-key">'
            . esc_html__('Copy', 'bulk-seo-meta-editor-for-ai-agents') . '</button>';
        echo '</p></div>';
    }

    echo '<h2>' . esc_html__('Generate a key', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';
    echo '<form method="post" action="' . esc_url($screen_url) . '">';
    wp_nonce_field('bulkseme_keys');
    echo '<input type="hidden" name="bulkseme_keys_action" value="generate">';
    echo '<p><label>' . esc_html__('Label', 'bulk-seo-meta-editor-for-ai-agents')
        . ' <input type="text" name="bulkseme_label" maxlength="80" class="regular-text" placeholder="'
        . esc_attr__('for example: content agent', 'bulk-seo-meta-editor-for-ai-agents') . '"></label> ';
    echo '<label>' . esc_html__('Works on', 'bulk-seo-meta-editor-for-ai-agents') . ' <select name="bulkseme_scope">';
    echo '<option value="plugin">' . esc_html__('this plugin\'s routes only (recommended)', 'bulk-seo-meta-editor-for-ai-agents') . '</option>';
    echo '<option value="all">' . esc_html__('every REST route, like an application password', 'bulk-seo-meta-editor-for-ai-agents') . '</option>';
    echo '</select></label> ';
    echo '<button type="submit" class="button button-primary">'
        . esc_html__('Generate API Key', 'bulk-seo-meta-editor-for-ai-agents') . '</button></p>';
    echo '<p class="description" style="max-width:62em">' . esc_html(sprintf(
        /* translators: %s: WordPress username the key will act as */
        __('A key made here signs REST requests in as your WordPress account (%s) and can do only what that account can do. It does not create a user, and it stops working if that account is removed. With the first choice the key is accepted on this plugin\'s own routes and nowhere else. With the second it is accepted on all of WordPress\'s REST routes, which a tool needs if it also lists posts or uploads media, and it is then as powerful as your account. Treat either kind like a password.', 'bulk-seo-meta-editor-for-ai-agents'),
        $user->user_login
    )) . '</p>';
    echo '</form>';

    echo '<h2>' . esc_html__('Active keys', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';
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
            echo '<td>' . esc_html($owner ? $owner->user_login : sprintf(
                /* translators: %d: id of a WordPress user who no longer exists */
                __('user #%d is gone (key no longer works)', 'bulk-seo-meta-editor-for-ai-agents'),
                $owner_id
            )) . '</td>';
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

    echo '<h2>' . esc_html__('How to connect', 'bulk-seo-meta-editor-for-ai-agents') . '</h2>';
    echo '<p class="description" style="max-width:62em">'
        . esc_html__('Most tools only ask you to paste the key. In your own script, send it in the X-Bulkseme-Key header, or as the password of HTTP Basic auth together with your username:', 'bulk-seo-meta-editor-for-ai-agents')
        . '</p>';
    echo '<p><code>curl -H "X-Bulkseme-Key: bsme_..." ' . esc_html($status_url) . '</code></p>';
    echo '<p><code>curl -u "' . esc_html($user->user_login) . ':bsme_..." ' . esc_html($status_url) . '</code></p>';
    echo '</div>';
}
