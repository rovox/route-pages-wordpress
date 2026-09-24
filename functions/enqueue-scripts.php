<?php

/**
 * Loads the frontend CSS/JS modules for the two virtual pages this plugin
 * renders (the routes index and a single route detail page) via WordPress's
 * normal asset pipeline, and hands each page's data to its JS bootstrap
 * script through wp_localize_script instead of splicing it into inline
 * <script>/<style> blocks (see AGENTS.md "Templates & rendering").
 *
 * Runs on every request, so it must independently work out which of the two
 * page types (if either) is being rendered - it can't rely on state set by
 * template_redirect.php, since wp_enqueue_scripts fires later in the same
 * request but the two hooks don't share data.
 */
add_action('wp_enqueue_scripts', 'trufi_maps_enqueue_frontend_assets');
function trufi_maps_enqueue_frontend_assets() {
    $map_page_id = (int) get_option(TRUFI_MAP_PAGE_ID_OPTION);
    $map_id      = get_query_var('trufi_map_id');
    $map_name    = get_query_var('trufi_map_name');

    $is_route_detail = $map_id && $map_name;
    $is_index_page    = !$is_route_detail && $map_page_id && is_page($map_page_id);

    if (!$is_route_detail && !$is_index_page) {
        return;
    }

    global $plugin_url;
    $ver = TRUFI_ROUTES_PLUGIN_VERSION;

    // Shared across both pages: icon set, geolocation button behaviour,
    // distance math, and the Leaflet "draw a route" logic (polyline,
    // direction arrows, endpoint/stop markers). Leaflet itself is not an
    // explicit WP dependency here: it's loaded as a raw <script src> by
    // trufi_add_header_tags() (functions.php) on wp_head at priority 7,
    // which runs before WP's own script printing (~priority 9) - so `L` is
    // already defined by the time these print. Registering it a second time
    // as a WP dependency would just load it twice.
    wp_enqueue_style('trufi-shared', $plugin_url . 'assets/css/trufi-shared.css', [], $ver);
    wp_enqueue_script('trufi-icons', $plugin_url . 'assets/js/trufi-icons.js', [], $ver, true);
    wp_enqueue_script('trufi-geo', $plugin_url . 'assets/js/trufi-geo.js', [], $ver, true);
    wp_enqueue_script('trufi-distance', $plugin_url . 'assets/js/trufi-distance.js', [], $ver, true);
    wp_enqueue_script('trufi-share', $plugin_url . 'assets/js/trufi-share.js', [], $ver, true);
    wp_enqueue_script('trufi-route-draw', $plugin_url . 'assets/js/trufi-route-draw.js', ['trufi-icons'], $ver, true);

    $line_color    = get_option(TRUFI_LINE_COLOR_OPTION);
    $show_location = get_option(TRUFI_SHOW_LOCATION_OPTION, '1') === '1';

    if ($is_route_detail) {
        $routeData = trufi_fetch_route_data(rawurldecode($map_id));
        $pattern   = $routeData['data']['pattern'] ?? null;

        wp_enqueue_style('trufi-route', $plugin_url . 'assets/css/trufi-route.css', ['trufi-shared'], $ver);
        wp_enqueue_script(
            'trufi-route',
            $plugin_url . 'assets/js/trufi-route.js',
            ['trufi-icons', 'trufi-geo', 'trufi-distance', 'trufi-share', 'trufi-route-draw'],
            $ver,
            true
        );

        wp_localize_script('trufi-route', 'trufiRouteConfig', trufi_defang_script_close([
            'pattern'      => $pattern,
            'lineColor'    => $line_color,
            'lineWeight'   => (int) get_option(TRUFI_LINE_WEIGHT_OPTION),
            'showLocation' => $show_location,
        ]));

        return;
    }

    $indexData = trufi_fetch_index_data();
    $patterns  = $indexData['data']['patterns'] ?? [];
    $lines     = trufi_group_patterns_by_line($patterns);

    wp_enqueue_style('trufi-index', $plugin_url . 'assets/css/trufi-index.css', ['trufi-shared'], $ver);
    wp_enqueue_script('trufi-api', $plugin_url . 'assets/js/trufi-api.js', [], $ver, true);
    wp_enqueue_script('trufi-search', $plugin_url . 'assets/js/trufi-search.js', [], $ver, true);
    wp_enqueue_script(
        'trufi-index',
        $plugin_url . 'assets/js/trufi-index.js',
        ['trufi-icons', 'trufi-geo', 'trufi-share', 'trufi-route-draw', 'trufi-api', 'trufi-search'],
        $ver,
        true
    );

    wp_localize_script('trufi-index', 'trufiIndexConfig', trufi_defang_script_close([
        'lines'        => $lines,
        'restUrl'      => esc_url_raw(rest_url('trufi/v1/pattern/')),
        'lineColor'    => $line_color,
        'mapCenter'    => [
            'lat' => (float) (get_option(TRUFI_MAP_CENTER_LAT_OPTION) ?: -17.3895),
            'lng' => (float) (get_option(TRUFI_MAP_CENTER_LNG_OPTION) ?: -66.1568),
        ],
        'mapZoom'      => (int) (get_option(TRUFI_MAP_ZOOM_OPTION) ?: 13),
        'showLocation' => $show_location,
    ]));
}
