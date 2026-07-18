<?php
/*
Plugin Name: Advanced Settings
Plugin URI: https://github.com/Bluscream/yourls-advanced-settings
Description: A centralized manager that allows any plugin setting to be overridden per domain, with a fallback default profile.
Version: 1.3
Author: Antigravity.AI
*/

if ( !defined( 'YOURLS_ABSPATH' ) ) die();

// Clear options cache when a plugin state changes
yourls_add_action('activated_plugin', 'yas_clear_options_cache');
yourls_add_action('deactivated_plugin', 'yas_clear_options_cache');
function yas_clear_options_cache() {
    yourls_delete_option('dsb_cached_grouped_keys');
}

// Helper to recursively scan a folder for PHP files containing yourls_get_option/yourls_update_option calls
function yas_scan_directory_for_options($dir, $ignored_keys) {
    $option_keys = [];
    if (!is_dir($dir)) {
        return $option_keys;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $content = @file_get_contents($file->getPathname());
            if ($content === false) continue;

            // Regex match option functions and wrappers
            if (preg_match_all('/(?:yourls_get_option|yourls_update_option|ps_env_or_option|env_or_option|yourls_get_db_option|yourls_update_db_option|get_option|update_option)\s*\(\s*[\'"]([a-zA-Z0-9_-]+)[\'"]/i', $content, $matches)) {
                if (isset($matches[1])) {
                    foreach ($matches[1] as $key) {
                        if (!in_array($key, $ignored_keys)) {
                            $option_keys[] = $key;
                        }
                    }
                }
            }
        }
    }

    return array_unique($option_keys);
}

// Array of options grouped by plugin/theme area dynamically parsed from the database and active plugins
function yas_get_grouped_keys() {
    // Cache bypassed during active development/configuration checks
    $groups = [];
    $ignored_keys = [
        'active_plugins', 'core_version', 'db_version', 'site_name', 'site_url', 
        'stats_clicks', 'stats_shorturls', 'next_id', 'plugins_site_url', 
        'registered_plugins', 'dsb_domain_profiles', 'ps_domain_settings',
        'nonce_key', 'cookie_key', 'dsb_cached_grouped_keys'
    ];

    // 1. Scan active plugins
    $active_plugins = yourls_get_option('active_plugins');
    if (is_array($active_plugins)) {
        foreach ($active_plugins as $plugin_rel_path) {
            $plugin_file = YOURLS_PLUGINDIR . '/' . $plugin_rel_path;
            if (!file_exists($plugin_file)) {
                continue;
            }

            // Get Plugin Name from header
            $plugin_name = basename(dirname($plugin_rel_path));
            $content = @file_get_contents($plugin_file);
            if ($content && preg_match('/Plugin Name:\s*(.*)$/mi', $content, $matches)) {
                $plugin_name = trim($matches[1]);
            }

            // Ignore our own plugin to avoid recursive settings management
            if ($plugin_name === 'Advanced Settings' || $plugin_name === 'Domain Settings Bridge') {
                continue;
            }

            $plugin_dir = dirname($plugin_file);
            $option_keys = yas_scan_directory_for_options($plugin_dir, $ignored_keys);

            if (!empty($option_keys)) {
                $groups[$plugin_name] = [];
                foreach ($option_keys as $key) {
                    // Turn "cf_ts_site_key" into "Cf Ts Site Key"
                    $label = ucwords(str_replace('_', ' ', str_replace(['cf_ts_', 'ps_', 'logo_suite_'], '', $key)));
                    $groups[$plugin_name][$key] = $label;
                }
            }
        }
    }

    // 2. Also query options table for any dangling options not mapped by active plugins
    $db_options = [];
    try {
        $db = yourls_get_db();
        $table = defined('YOURLS_DB_TABLE_OPTIONS') ? YOURLS_DB_TABLE_OPTIONS : 'yourls_options';
        if (is_object($db)) {
            $db_options = $db->fetchCol("SELECT option_name FROM `$table` ORDER BY option_name ASC");
        }
    } catch (Throwable $e) {}

    $mapped_keys = [];
    foreach ($groups as $g => $keys) {
        $mapped_keys = array_merge($mapped_keys, array_keys($keys));
    }

    $dangling = [];
    foreach ($db_options as $option) {
        if (in_array($option, $ignored_keys) || in_array($option, $mapped_keys)) {
            continue;
        }
        $label = ucwords(str_replace('_', ' ', $option));
        $dangling[$option] = $label;
    }

    if (!empty($dangling)) {
        $groups['Other Options'] = $dangling;
    }

    // Cache the resolved groups
    yourls_update_option('dsb_cached_grouped_keys', $groups);

    return $groups;
}

// Flattened list of all supported option keys
function yas_get_supported_keys() {
    $flat = [];
    foreach ( yas_get_grouped_keys() as $group => $keys ) {
        foreach ( $keys as $key => $label ) {
            $flat[$key] = $label;
        }
    }
    return $flat;
}

// Get configurations array
function yas_get_configurations() {
    $configs = yourls_get_option('dsb_domain_profiles');
    if (!is_array($configs)) {
        $configs = ['default' => []];
    }
    return $configs;
}

// Dynamically intercept options on get_option using standard YOURLS filter hooks
yourls_add_action( 'plugins_loaded', 'yas_init_option_overrides', 1 );
function yas_init_option_overrides() {
    $supported_keys = yas_get_supported_keys();
    foreach ( array_keys($supported_keys) as $option_name ) {
        yourls_add_filter( 'shunt_option_' . $option_name, function( $value ) use ( $option_name ) {
            $configs = yas_get_configurations();
            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
            
            // 1. Resolve host specific setting
            if (!empty($host) && isset($configs[$host][$option_name]) && $configs[$host][$option_name] !== '') {
                return $configs[$host][$option_name];
            }
            // 2. Resolve default profile setting
            elseif (isset($configs['default'][$option_name]) && $configs['default'][$option_name] !== '') {
                return $configs[$host][$option_name];
            }
            
            return yourls_shunt_default();
        } );
    }
}

// Setup Admin UI
yourls_add_action( 'plugins_loaded', 'yas_admin_init' );
function yas_admin_init() {
    yourls_register_plugin_page( 'advanced_settings', 'Advanced Plugin Settings', 'yas_admin_page' );
}

function yas_admin_page() {
    $db = yourls_get_db();
    $table = YOURLS_DB_TABLE_OPTIONS;
    $configs = yas_get_configurations();
    $grouped_keys = yas_get_grouped_keys();
    $supported_keys = yas_get_supported_keys();
    $nonce = yourls_create_nonce('yas_settings_nonce');

    if (isset($_POST['yas_action'])) {
        yourls_verify_nonce('yas_settings_nonce');

        if ($_POST['yas_action'] === 'save') {
            $domain = trim($_POST['domain_name']);
            if ($domain !== '') {
                $domain = strtolower($domain);
                if ($domain === 'default') {
                    // Update standard YOURLS options table directly
                    foreach ($supported_keys as $key => $label) {
                        $val = isset($_POST[$key]) ? trim($_POST[$key]) : '';
                        yourls_update_option($key, $val);
                    }
                    // Clean up default key from config profile override if it exists
                    if (isset($configs['default'])) {
                        unset($configs['default']);
                        yourls_update_option('dsb_domain_profiles', $configs);
                    }
                    echo '<div class="updated"><p>Default settings saved directly to the database options successfully!</p></div>';
                } else {
                    $configs[$domain] = [];
                    foreach ($supported_keys as $key => $label) {
                        $configs[$domain][$key] = isset($_POST[$key]) ? trim($_POST[$key]) : '';
                    }
                    yourls_update_option('dsb_domain_profiles', $configs);
                    echo '<div class="updated"><p>Profile for <strong>' . htmlspecialchars($domain) . '</strong> saved successfully!</p></div>';
                }
            }
        } elseif ($_POST['yas_action'] === 'delete') {
            $domain = trim($_POST['domain_name']);
            if ($domain !== 'default' && isset($configs[$domain])) {
                unset($configs[$domain]);
                yourls_update_option('dsb_domain_profiles', $configs);
                echo '<div class="updated"><p>Profile for <strong>' . htmlspecialchars($domain) . '</strong> deleted.</p></div>';
            }
        }
    }

    $active_domain = isset($_GET['edit_domain']) ? trim($_GET['edit_domain']) : 'default';
    if ($active_domain !== 'default' && !isset($configs[$active_domain])) {
        $active_domain = 'default';
    }

    // Load active values: Default profile reads directly from YOURLS options table
    $active_values = [];
    foreach ($supported_keys as $key => $label) {
        if ($active_domain === 'default') {
            $active_values[$key] = yourls_get_option($key);
        } else {
            $active_values[$key] = isset($configs[$active_domain][$key]) ? $configs[$active_domain][$key] : '';
        }
    }

    ?>
    <div style="display: flex; gap: 20px; margin-top: 10px; width: 100%;">
        <!-- Profiles List / Sidebar -->
        <div style="width: 250px; padding-right: 15px; box-sizing: border-box; border-right: 1px solid #ccd0d4;">
            <h3 style="margin-top: 0; font-size: 14px; border-bottom: 1px solid #ccd0d4; padding-bottom: 8px;">Active Profiles</h3>
            <ul style="list-style: none; padding: 0; margin: 0 0 15px 0;">
                <li style="margin-bottom: 6px;">
                    <a href="?page=advanced_settings&edit_domain=default" 
                       style="display: block; padding: 8px 12px; text-decoration: none; border-radius: 3px; font-weight: 600; <?php echo $active_domain === 'default' ? 'background: #0073aa; color: #fff;' : 'color: #0073aa;'; ?>">
                        default (Fallback)
                    </a>
                </li>
                <?php foreach ($configs as $domain => $settings): if ($domain === 'default') continue; ?>
                    <li style="margin-bottom: 6px;">
                        <a href="?page=advanced_settings&edit_domain=<?php echo urlencode($domain); ?>" 
                           style="display: block; padding: 8px 12px; text-decoration: none; border-radius: 3px; font-weight: 600; <?php echo $active_domain === $domain ? 'background: #0073aa; color: #fff;' : 'color: #0073aa;'; ?>">
                            <?php echo htmlspecialchars($domain); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <hr style="border: 0; border-top: 1px solid #ccd0d4; margin: 15px 0;">
            <form method="post" action="?page=advanced_settings">
                <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
                <input type="hidden" name="yas_action" value="save">
                <input type="text" name="domain_name" placeholder="e.g. short.domain.com" style="width: 100%; margin-bottom: 10px; padding: 5px; box-sizing: border-box;" required>
                <input type="submit" class="button" style="width: 100%; text-align: center;" value="Add New Profile">
            </form>
        </div>
        
        <!-- Profile Settings Form -->
        <div style="flex: 1; box-sizing: border-box;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ccd0d4; padding-bottom: 10px; margin-bottom: 20px;">
                <h3 style="margin: 0; font-size: 16px;">Editing Profile: <span style="color:#0073aa;"><?php echo htmlspecialchars($active_domain); ?></span></h3>
                <div style="display: flex; gap: 10px;">
                    <?php if ($active_domain !== 'default'): ?>
                        <button type="button" class="button" onclick="yasResetProfile();" style="color: #b32d2e; border-color: #b32d2e;">Reset All Overrides</button>
                        <form method="post" onsubmit="return confirm('Delete this domain profile?');" style="margin: 0;">
                            <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
                            <input type="hidden" name="yas_action" value="delete">
                            <input type="hidden" name="domain_name" value="<?php echo htmlspecialchars($active_domain); ?>">
                            <input type="submit" class="button" style="color: #b32d2e; border-color: #b32d2e; background: #fcf0f0;" value="Delete Profile">
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" id="yas-settings-form">
                <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
                <input type="hidden" name="yas_action" value="save">
                <input type="hidden" name="domain_name" value="<?php echo htmlspecialchars($active_domain); ?>">

                <?php foreach ($grouped_keys as $group_name => $keys): 
                    $group_class = 'yas-group-' . preg_replace('/[^a-zA-Z0-9-]/', '', strtolower($group_name));
                ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; background: #f7f7f7; padding: 8px 12px; margin-top: 25px; border: 1px solid #ccd0d4; border-bottom: none; border-radius: 4px 4px 0 0;">
                        <h3 style="margin: 0; font-size: 14px;"><?php echo htmlspecialchars($group_name); ?></h3>
                        <?php if ($active_domain !== 'default'): ?>
                            <button type="button" class="button button-secondary" onclick="yasResetGroup('<?php echo $group_class; ?>');" style="font-size: 11px; padding: 2px 8px; height: auto; line-height: normal;">Reset Group Overrides</button>
                        <?php endif; ?>
                    </div>
                    <table class="form-table <?php echo $group_class; ?>" style="width: 100%; border-collapse: collapse; border: 1px solid #ccd0d4; margin-top: 0; margin-bottom: 20px; border-top: none;">
                        <?php foreach ($keys as $key => $label): 
                            $val = isset($active_values[$key]) ? $active_values[$key] : '';
                            if (is_array($val) || is_object($val)) {
                                $val = json_encode($val);
                            }
                            $is_color = (strpos($key, 'ps_bg_') === 0 || strpos($key, 'ps_text_') === 0 || strpos($key, 'ps_accent') === 0);
                            $type = ($key === 'cf_ts_secret_key' || $key === 'ps_secret_key') ? 'password' : ($is_color ? 'color' : 'text');
                            
                            // Fetch raw option value from core YOURLS db (bypassing interceptor filter)
                            $db_default_value = '';
                            try {
                                    $db_default_value = $db->fetchValue("SELECT option_value FROM `$table` WHERE option_name = :key", ['key' => $key]);
                            } catch (Throwable $e) {}
                            if ($db_default_value === false || $db_default_value === null) {
                                $db_default_value = '(Not set)';
                            } else {
                                // If it's a serialized string, unserialize it to see if it's an array/object, then JSON format it for readability
                                $unserialized = @unserialize($db_default_value);
                                if ($unserialized !== false || $db_default_value === 'b:0;') {
                                    if (is_array($unserialized) || is_object($unserialized)) {
                                        $db_default_value = json_encode($unserialized);
                                    }
                                }
                            }
                            $db_default_value_str = is_array($db_default_value) || is_object($db_default_value) ? json_encode($db_default_value) : (string)$db_default_value;
                        ?>
                            <tr style="border-bottom: 1px solid #f0f0f1;">
                                <th style="width: 250px; text-align: left; padding: 12px 15px; font-weight: 600; font-size: 13px;">
                                    <label for="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></label>
                                    <div style="font-size: 10px; color: #888; font-weight: normal; margin-top: 4px; word-break: break-all;">
                                        Key: <code><?php echo htmlspecialchars($key); ?></code>
                                    </div>
                                </th>
                                <td style="padding: 12px 15px;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <?php if ($is_color): ?>
                                            <input type="color" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($val ?: '#ffffff'); ?>">
                                            <code style="font-family: monospace; font-size: 12px; color: #555;"><?php echo htmlspecialchars($val); ?></code>
                                        <?php else: ?>
                                            <input type="<?php echo $type; ?>" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($val); ?>" size="50" style="padding: 5px;">
                                        <?php endif; ?>
                                        
                                        <?php if ($active_domain !== 'default'): ?>
                                            <button type="button" class="button" onclick="yasResetField('<?php echo $key; ?>');" title="Reset this override" style="padding: 0 8px; font-size: 11px; height: 28px; line-height: 28px;">&times; Clear</button>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($active_domain !== 'default'): ?>
                                        <div style="font-size: 11px; color: #777; margin-top: 6px;">
                                            Database Default: <strong style="color: #444; font-family: monospace;"><?php echo htmlspecialchars(strlen($db_default_value_str) > 80 ? substr($db_default_value_str, 0, 77) . '...' : $db_default_value_str); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endforeach; ?>
                
                <p style="margin-top: 20px;">
                    <input type="submit" class="button button-primary" value="Save Profile Settings">
                </p>
            </form>
        </div>
    </div>

    <script type="text/javascript">
        function yasResetField(id) {
            var field = document.getElementById(id);
            if (field) {
                if (field.type === 'color') {
                    field.value = '#ffffff';
                } else {
                    field.value = '';
                }
                // Visual highlight effect
                field.style.transition = 'background-color 0.5s';
                field.style.backgroundColor = '#fff9e6';
                setTimeout(function() {
                    field.style.backgroundColor = '';
                }, 500);
            }
        }

        function yasResetGroup(groupClass) {
            if (confirm('Clear all configuration overrides inside this group?')) {
                var table = document.querySelector('.' + groupClass);
                if (table) {
                    var inputs = table.querySelectorAll('input[type="text"], input[type="password"], input[type="color"]');
                    inputs.forEach(function(input) {
                        if (input.type === 'color') {
                            input.value = '#ffffff';
                        } else {
                            input.value = '';
                        }
                    });
                }
            }
        }

        function yasResetProfile() {
            if (confirm('Are you sure you want to clear all overrides for this domain profile? All options will fall back to their database values.')) {
                var form = document.getElementById('yas-settings-form');
                if (form) {
                    var inputs = form.querySelectorAll('input[type="text"], input[type="password"], input[type="color"]');
                    inputs.forEach(function(input) {
                        if (input.type === 'color') {
                            input.value = '#ffffff';
                        } else {
                            input.value = '';
                        }
                    });
                }
            }
        }
    </script>
    <?php
}
