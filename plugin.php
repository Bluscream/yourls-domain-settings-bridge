<?php
/*
Plugin Name: Domain Settings Bridge
Plugin URI: https://github.com/yourls/domain-settings-bridge
Description: A centralized manager that allows any plugin setting to be overridden per domain, with a fallback default profile.
Version: 1.0
Author: Antigravity.AI
*/

if ( !defined( 'YOURLS_ABSPATH' ) ) die();

// Array of options we support configuring per-domain
function dsb_get_supported_keys() {
    return yourls_apply_filters('dsb_supported_keys', [
        'cf_ts_site_key' => 'Turnstile Site Key (Admin)',
        'cf_ts_secret_key' => 'Turnstile Secret Key (Admin)',
        'ps_site_key' => 'Turnstile Site Key (Public)',
        'ps_secret_key' => 'Turnstile Secret Key (Public)',
        'ps_title' => 'Public Shortener Title',
        'ps_subtitle' => 'Public Shortener Subtitle',
        'ps_bg_start' => 'Public Shortener BG Start',
        'ps_bg_end' => 'Public Shortener BG End',
        'ps_text_primary' => 'Public Shortener Text Primary',
        'ps_text_secondary' => 'Public Shortener Text Secondary',
        'ps_accent' => 'Public Shortener Accent',
        'ps_accent_hover' => 'Public Shortener Accent Hover',
        'ozh_yourls_gsb' => 'Google Safe Browsing API Key',
        'youtube_title_fix_api_key' => 'YouTube Data API Key',
        'fallback_url' => 'Fallback URL',
        'random_shorturls_length' => 'Random URL Length',
        'logo_suite_image_url' => 'Logo Suite URL',
        'logo_suite_custom_title' => 'Logo Suite Title',
    ]);
}

// Get configurations array
function dsb_get_configurations() {
    $configs = yourls_get_option('dsb_domain_profiles');
    if (!is_array($configs)) {
        $configs = ['default' => []];
    }
    return $configs;
}

// Dynamically intercept options on get_option using wildcard ArrayObject filter registry
class DomainSettingsBridgeArray extends ArrayObject {
    private function get_override_val($key) {
        if (strpos($key, 'shunt_option_') === 0) {
            $option_name = substr($key, 13);
            $supported_keys = dsb_get_supported_keys();

            if (array_key_exists($option_name, $supported_keys)) {
                $configs = dsb_get_configurations();
                $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
                
                // 1. Resolve host specific setting
                if (!empty($host) && isset($configs[$host][$option_name]) && $configs[$host][$option_name] !== '') {
                    return $configs[$host][$option_name];
                }
                // 2. Resolve default profile setting
                elseif (isset($configs['default'][$option_name]) && $configs['default'][$option_name] !== '') {
                    return $configs['default'][$option_name];
                }
            }
        }
        return null;
    }

    public function offsetExists($key): bool {
        $exists = parent::offsetExists($key);
        if ($exists) {
            return true;
        }
        return ($this->get_override_val($key) !== null);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($key) {
        $resolved_val = $this->get_override_val($key);
        if ($resolved_val !== null) {
            $bridge_filter = [
                10 => [
                    'dsb_override' => [
                        'function' => function($value) use ($resolved_val) {
                            return $resolved_val;
                        },
                        'accepted_args' => 1,
                        'type' => 'filter'
                    ]
                ]
            ];
            
            $val = parent::offsetExists($key) ? parent::offsetGet($key) : null;
            return is_array($val) ? $bridge_filter + $val : $bridge_filter;
        }
        
        return parent::offsetExists($key) ? parent::offsetGet($key) : null;
    }
}

// Initialize the filter interceptor registry
global $yourls_filters;
if (is_array($yourls_filters)) {
    $yourls_filters = new DomainSettingsBridgeArray($yourls_filters);
} elseif ($yourls_filters instanceof ArrayObject) {
    $yourls_filters = new DomainSettingsBridgeArray($yourls_filters->getArrayCopy());
} else {
    $yourls_filters = new DomainSettingsBridgeArray();
}

// Setup Admin UI
yourls_add_action( 'plugins_loaded', 'dsb_admin_init' );
function dsb_admin_init() {
    yourls_register_plugin_page( 'domain_settings_bridge', 'Domain Profiles', 'dsb_admin_page' );
}

function dsb_admin_page() {
    $configs = dsb_get_configurations();
    $supported_keys = dsb_get_supported_keys();
    $nonce = yourls_create_nonce('dsb_settings_nonce');

    if (isset($_POST['dsb_action'])) {
        yourls_verify_nonce('dsb_settings_nonce');

        if ($_POST['dsb_action'] === 'save') {
            $domain = trim($_POST['domain_name']);
            if ($domain !== '') {
                $domain = strtolower($domain);
                $configs[$domain] = [];
                foreach ($supported_keys as $key => $label) {
                    $configs[$domain][$key] = isset($_POST[$key]) ? trim($_POST[$key]) : '';
                }
                yourls_update_option('dsb_domain_profiles', $configs);
                echo '<div class="updated"><p>Profile for <strong>' . htmlspecialchars($domain) . '</strong> saved successfully!</p></div>';
            }
        } elseif ($_POST['dsb_action'] === 'delete') {
            $domain = trim($_POST['domain_name']);
            if ($domain !== 'default' && isset($configs[$domain])) {
                unset($configs[$domain]);
                yourls_update_option('dsb_domain_profiles', $configs);
                echo '<div class="updated"><p>Profile for <strong>' . htmlspecialchars($domain) . '</strong> deleted.</p></div>';
            }
        }
    }

    $active_domain = isset($_GET['edit_domain']) ? trim($_GET['edit_domain']) : 'default';
    if (!isset($configs[$active_domain])) {
        $active_domain = 'default';
    }
    $active_values = $configs[$active_domain];

    ?>
    <div id="wrap" style="max-width: 98%; width: 98%;">
        <h2>Domain Profiles Manager</h2>
        <p>Define settings per domain name. These profiles automatically override options for Turnstile, Public Shortener, and Logo Suite plugins dynamically based on the requested host.</p>
        
        <div style="display: flex; gap: 20px; margin-top: 20px;">
            <!-- Profiles List / Sidebar -->
            <div style="width: 250px; background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); box-sizing: border-box;">
                <h3 style="margin-top: 0; font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 8px;">Active Profiles</h3>
                <ul style="list-style: none; padding: 0; margin: 0 0 15px 0;">
                    <li style="margin-bottom: 6px;">
                        <a href="?page=domain_settings_bridge&edit_domain=default" 
                           style="display: block; padding: 8px 12px; text-decoration: none; border-radius: 3px; font-weight: 600; <?php echo $active_domain === 'default' ? 'background: #0073aa; color: #fff;' : 'color: #0073aa;'; ?>">
                            default (Fallback)
                        </a>
                    </li>
                    <?php foreach ($configs as $domain => $settings): if ($domain === 'default') continue; ?>
                        <li style="margin-bottom: 6px;">
                            <a href="?page=domain_settings_bridge&edit_domain=<?php echo urlencode($domain); ?>" 
                               style="display: block; padding: 8px 12px; text-decoration: none; border-radius: 3px; font-weight: 600; <?php echo $active_domain === $domain ? 'background: #0073aa; color: #fff;' : 'color: #0073aa;'; ?>">
                                <?php echo htmlspecialchars($domain); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <hr style="border: 0; border-top: 1px solid #eee; margin: 15px 0;">
                <form method="post" action="?page=domain_settings_bridge">
                    <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
                    <input type="hidden" name="dsb_action" value="save">
                    <input type="text" name="domain_name" placeholder="e.g. short.domain.com" style="width: 100%; margin-bottom: 10px; padding: 5px; box-sizing: border-box;" required>
                    <input type="submit" class="button" style="width: 100%; text-align: center;" value="Add New Profile">
                </form>
            </div>
            
            <!-- Profile Settings Form -->
            <div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); box-sizing: border-box;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ccd0d4; padding-bottom: 10px; margin-bottom: 20px;">
                    <h3 style="margin: 0; font-size: 16px;">Editing Profile: <span style="color:#0073aa;"><?php echo htmlspecialchars($active_domain); ?></span></h3>
                    <?php if ($active_domain !== 'default'): ?>
                        <form method="post" onsubmit="return confirm('Delete this domain profile?');">
                            <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
                            <input type="hidden" name="dsb_action" value="delete">
                            <input type="hidden" name="domain_name" value="<?php echo htmlspecialchars($active_domain); ?>">
                            <input type="submit" class="button" style="color: #b32d2e; border-color: #b32d2e;" value="Delete Profile">
                        </form>
                    <?php endif; ?>
                </div>

                <form method="post">
                    <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
                    <input type="hidden" name="dsb_action" value="save">
                    <input type="hidden" name="domain_name" value="<?php echo htmlspecialchars($active_domain); ?>">

                    <table class="form-table" style="width: 100%; border-collapse: collapse;">
                        <?php foreach ($supported_keys as $key => $label): 
                            $val = isset($active_values[$key]) ? $active_values[$key] : '';
                            $is_color = (strpos($key, 'ps_bg_') === 0 || strpos($key, 'ps_text_') === 0 || strpos($key, 'ps_accent') === 0);
                            $type = ($key === 'cf_ts_secret_key' || $key === 'ps_secret_key') ? 'password' : ($is_color ? 'color' : 'text');
                        ?>
                            <tr>
                                <th style="width: 250px; text-align: left; padding: 12px 10px; font-weight: 600; font-size: 13px; border-bottom: 1px solid #f0f0f1;">
                                    <label for="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></label>
                                </th>
                                <td style="padding: 12px 10px; border-bottom: 1px solid #f0f0f1;">
                                    <?php if ($is_color): ?>
                                        <input type="color" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($val ?: '#ffffff'); ?>">
                                        <code style="margin-left: 10px; font-family: monospace; font-size: 12px; color: #555;"><?php echo htmlspecialchars($val); ?></code>
                                    <?php else: ?>
                                        <input type="<?php echo $type; ?>" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($val); ?>" size="50" style="padding: 5px;">
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    
                    <p style="margin-top: 20px;">
                        <input type="submit" class="button button-primary" value="Save Profile Settings">
                    </p>
                </form>
            </div>
        </div>
    </div>
    <?php
}
