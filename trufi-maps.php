<?php

/**
 * Plugin Name: Trufi Route Pages
 * Plugin URI: https://trufi.app/
 * Description: A plugin for displaying Trufi Maps.
 * Version: 0.9.1
 * Author: Trufi Association
 * Author URI: https://trufi.app/
 * Requires at least: 6.4.1
 * Tested up to: 6.5.4
 * Requires PHP: 8.1
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: TrufiApi-maps
 */

if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
$plugin_data = get_plugin_data(__FILE__, false, false);
define('TRUFI_ROUTES_PLUGIN_VERSION', ($plugin_data && $plugin_data['Version']) ? $plugin_data['Version'] : '1.0.0');

// check php version if less than 8.0 don't load
if (version_compare(phpversion(), $plugin_data['RequiresPHP'], '<')) {
    add_action('admin_notices', 'trufi_php_version_notice');
    function trufi_php_version_notice() {
        global $plugin_data;
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo 'Trufi Route Pages plugin requires PHP ' . esc_html($plugin_data['RequiresPHP']) . ' or higher. Please update your PHP version.'; ?></p>
        </div>
        <?php
    }

    return;
}
$plugin_dir = plugin_dir_path(__FILE__);
$plugin_url = plugin_dir_url(__FILE__);

// Load plugin text domain for translations (must be 'init' or later per WP 6.7+)
add_action('init', function() {
    load_plugin_textdomain('TrufiApi-maps', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

require($plugin_dir . 'App/Utility.php');
require($plugin_dir . 'functions/constants.php');
require($plugin_dir . 'App/Api/TrufiApi.php');
require($plugin_dir . 'functions/functions.php');

require($plugin_dir . 'functions/rewrite-rules.php');
require($plugin_dir . 'functions/template-redirect.php');
require($plugin_dir . 'functions/enqueue-scripts.php');
require($plugin_dir . 'functions/sitemap-provider.php');
require($plugin_dir . 'functions/rest-api.php');

if (is_admin()) {
    require($plugin_dir . 'admin/admin-settings.php');
    require($plugin_dir . 'admin/transient-cache-clear.php');
    require($plugin_dir . 'admin/functions/enqueue-scripts.php');
}
