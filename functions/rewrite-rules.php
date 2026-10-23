<?php

/**
 * Registers the plugin's rewrite rules (on every request, as WordPress
 * requires). It does NOT flush them - flushing rewrites the rules option in
 * the database, so it only happens when they actually change: on settings
 * save and on plugin activation/upgrade (trufi_maps_flush_rewrite_rules()).
 *
 *  - {base}/{name}/{id}      -> a route detail page
 *  - {old base}/{name}/{id}  -> 301 to the same route under the current base,
 *                               for every base page used before (see
 *                               TRUFI_LEGACY_BASE_PATHS_OPTION), so old links
 *                               (e.g. shared from the app) keep working
 *  - llms.txt                -> functions/llms-txt.php
 */
function trufi_maps_add_rewrite_rules() {
    $base_path = trufi_get_base_path();
    add_rewrite_tag('%trufi_map_id%', '([^/]+)');
    add_rewrite_tag('%trufi_map_name%', '([^/]+)');
    add_rewrite_tag('%trufi_legacy%', '([01])');
    add_rewrite_tag('%trufi_llms%', '([01])');

    foreach ((array) get_option(TRUFI_LEGACY_BASE_PATHS_OPTION, []) as $legacy_path) {
        if ($legacy_path && $legacy_path !== $base_path) {
            $legacy_path = preg_quote($legacy_path, '#');
            add_rewrite_rule("^{$legacy_path}/([^/]+)/([^/]+)/?", 'index.php?trufi_legacy=1&trufi_map_name=$matches[1]&trufi_map_id=$matches[2]', 'top');
        }
    }

    add_rewrite_rule("^{$base_path}/([^/]+)/([^/]+)/?", 'index.php?trufi_map_name=$matches[1]&trufi_map_id=$matches[2]', 'top');
    add_rewrite_rule('^llms\.txt$', 'index.php?trufi_llms=1', 'top');
    add_rewrite_endpoint($base_path, EP_PERMALINK | EP_PAGES);
}

add_action('init', 'trufi_maps_add_rewrite_rules');

/**
 * Re-registers the rules (e.g. after the base page changed) and writes them
 * to the database.
 */
function trufi_maps_flush_rewrite_rules() {
    trufi_maps_add_rewrite_rules();
    flush_rewrite_rules();
}

/**
 * Plugin upgrades (uploading a new zip over the old one) don't fire the
 * activation hook, so flush once whenever the plugin version changes -
 * otherwise new rules (llms.txt, legacy redirects) wouldn't exist until
 * someone saved the settings.
 */
add_action('init', function () {
    if (get_option('trufi_rewrite_rules_version') !== TRUFI_ROUTES_PLUGIN_VERSION) {
        flush_rewrite_rules(); // the rules were registered at init priority 10
        update_option('trufi_rewrite_rules_version', TRUFI_ROUTES_PLUGIN_VERSION);
    }
}, 20);
