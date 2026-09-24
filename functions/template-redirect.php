<?php

use App\Api\TrufiApi;

add_action('template_redirect', 'trufi_maps_template_redirect');
function trufi_maps_template_redirect() {
    if (!get_query_var('trufi_map_id') && !get_query_var('trufi_map_name') && is_page((int) get_option(TRUFI_MAP_PAGE_ID_OPTION))) {
        trufi_maps_render_routes_index();
        return;
    }

    if (get_query_var('trufi_map_id') && get_query_var('trufi_map_name')) {
        $mapId         = rawurldecode(get_query_var('trufi_map_id'));
        $mapName       = get_query_var('trufi_map_name');
        $apiUrl        = get_option(TRUFI_API_URL_OPTION);
        $cacheKey      = 'trufi_route_data_' . $mapId;
        $cacheLifetime = get_option(TRUFI_CACHE_TTL_OPTION) * 60 * 60;

        // Check if the route data is cached
        $routeData = get_transient($cacheKey);
        if (false === $routeData) {
            // Not cached, fetch route data from Trufi API
            $trufiApi  = new TrufiApi($apiUrl);
            $routeData = $trufiApi->fetchRoute($mapId);
            set_transient($cacheKey, $routeData, $cacheLifetime);
        }

        $route_name        = $routeData['data']['pattern']['route']['longName'];
        $route_short_name  = $routeData['data']['pattern']['route']['shortName'] ?? '';
        $page_title        = $route_name . ' - ' . get_bloginfo('name');
        $page_description  = get_option(TRUFI_SITE_DESCRIPTION_OPTION);
        $meta_description  = $page_description . ' - ' . $route_name;
        $line_color        = get_option(TRUFI_LINE_COLOR_OPTION);
        $line_weight       = get_option(TRUFI_LINE_WEIGHT_OPTION);
        $google_play_url   = get_option(TRUFI_GOOGLE_PLAY_URL_OPTION);
        $apple_store_url   = get_option(TRUFI_APPLE_STORE_URL_OPTION);
        $google_play_image = get_option(TRUFI_GOOGLE_PLAY_IMAGE_OPTION);
        $apple_store_image = get_option(TRUFI_APPLE_STORE_IMAGE_OPTION);
        $map_page_id       = get_option(TRUFI_MAP_PAGE_ID_OPTION);

        add_action('wp_head', function () use ($page_title, $meta_description, $route_name, $map_page_id) {
            trufi_add_header_tags($page_title, $meta_description, $route_name, $map_page_id);
        }, 7);

        $replacement_values = [
            "{{mapId}}"           => $mapId,
            "{{apiUrl}}"          => $apiUrl,
            "{{pageTitle}}"       => $page_title,
            "{{routeName}}"       => esc_html($route_name),
            "{{routeShortName}}"  => esc_html($route_short_name),
            "{{routeDescription}}" => esc_html($meta_description),
            "{{lineColor}}"       => $line_color,
            "{{lineWeight}}"      => $line_weight,
            "{{callToAction}}"    => $page_description,
            "{{googlePlayUrl}}"   => $google_play_url,
            "{{appleStoreUrl}}"   => $apple_store_url,
            "{{googlePlayImage}}" => $google_play_image,
            "{{appleStoreImage}}" => $apple_store_image,
            "{{routeData}}"       => json_encode($routeData),
        ];

        $template_path    = plugin_dir_path(__FILE__) . '../templates/map-template.html';
        $template_content = file_get_contents($template_path);
        $template_content = str_replace(array_keys($replacement_values), array_values($replacement_values), $template_content);

        // Like the index page, the injected template embeds markup-bearing JS
        // (HTML strings feeding Leaflet divIcons). the_content's default
        // transforms (wpautop, shortcodes, ...) corrupt it, so bypass them.
        remove_all_filters('the_content');

        global $post;
        $post->ID             = $map_page_id;
        $post->post_title     = $page_title;
        $post->post_name      = $mapName;
        $post->post_content   = minify_html($template_content);
        $post->comment_status = 'closed';
        $post->post_type      = 'page';
        $post->post_status    = 'publish';
        $post->post_parent    = $map_page_id;
        $post->menu_order     = 0;
        $post->comment_count  = 0;

        /*add_filter('the_content', function ($content) use ($template_content) {
            return $template_content;
        });*/


        function set_route_title($title, $id = null) {
            // if we are on the map page, set the title to the route name, previously set in post_title
            if ($id == get_option(TRUFI_MAP_PAGE_ID_OPTION)) {
                global $post;
                return $post->post_title;
            }
            return $title;
        }

        add_filter('the_title', 'set_route_title', 10, 2);

        function trufi_remove_title_filter_nav_menu($nav_menu, $args) {
            // we are working with menu, so remove the title filter
            remove_filter('the_title', 'set_route_title', 10, 2);
            return $nav_menu;
        }

        // this filter fires just before the nav menu item creation process
        add_filter('pre_wp_nav_menu', 'trufi_remove_title_filter_nav_menu', 10, 2);

        function trufi_add_title_filter_non_menu($items, $args) {
            // we are done working with menu, so add the title filter back
            add_filter('the_title', 'set_route_title', 10, 2);
            return $items;
        }

        // this filter fires after nav menu item creation is done
        add_filter('wp_nav_menu_items', 'trufi_add_title_filter_non_menu', 10, 2);


        global $wp_query;
        $wp_query->is_singular = true;
        $wp_query->is_page     = true;
        $wp_query->is_home     = false;
    }
}

/**
 * Renders the routes index/directory page: the base "Routes" page itself
 * (no route segments in the URL) gets the searchable sidebar + map view,
 * built from a lightweight list of all patterns from the Trufi API.
 *
 * Lines are grouped by route shortName since the configured Trufi GraphQL
 * endpoint has no per-operator "agency" data (every route reports agency
 * "default") - there is no reliable "sindicato" grouping to build from.
 *
 * @return void
 */
function trufi_maps_render_routes_index() {
    // This page's content is fully self-contained interactive markup (JSON
    // + inline JS with embedded HTML-like strings). None of the_content's
    // default transforms (wpautop, do_shortcode, wp_filter_content_tags,
    // convert_smilies, etc.) are HTML/JS-aware, and each has been observed
    // corrupting the script by treating fragments of it as real markup.
    // Bypass the whole chain rather than chase filters one at a time.
    remove_all_filters('the_content');

    $apiUrl        = get_option(TRUFI_API_URL_OPTION);
    $cacheKey      = 'trufi_routes_index_list';
    $cacheLifetime = get_option(TRUFI_CACHE_TTL_OPTION) * 60 * 60;

    $indexData = get_transient($cacheKey);
    if (false === $indexData) {
        $trufiApi  = new TrufiApi($apiUrl);
        $indexData = $trufiApi->routeIndexList();
        set_transient($cacheKey, $indexData, $cacheLifetime);
    }

    $patterns = $indexData['data']['patterns'] ?? [];

    // Group patterns by route shortName so each sidebar entry is a line,
    // with each pattern underneath as one direction/variant of that line.
    $lines = [];
    foreach ($patterns as $pattern) {
        $shortName = $pattern['route']['shortName'] ?? '';
        if ($shortName === '') {
            continue;
        }
        if (!isset($lines[$shortName])) {
            $lines[$shortName] = [
                'shortName' => $shortName,
                'mode'      => $pattern['route']['mode'] ?? 'BUS',
                'patterns'  => [],
            ];
        }
        $lines[$shortName]['patterns'][] = [
            'code'      => $pattern['code'],
            'longName'  => $pattern['route']['longName'] ?? '',
        ];
    }
    $lines = array_values($lines);
    usort($lines, fn($a, $b) => strnatcasecmp($a['shortName'], $b['shortName']));

    $stats = [
        'lines'    => count($lines),
        'patterns' => count($patterns),
    ];

    $page_title       = get_the_title() . ' - ' . get_bloginfo('name');
    $page_description = get_option(TRUFI_SITE_DESCRIPTION_OPTION);
    $line_color       = get_option(TRUFI_LINE_COLOR_OPTION);
    $map_center_lat   = get_option(TRUFI_MAP_CENTER_LAT_OPTION) ?: '-17.3895';
    $map_center_lng   = get_option(TRUFI_MAP_CENTER_LNG_OPTION) ?: '-66.1568';
    $map_zoom         = get_option(TRUFI_MAP_ZOOM_OPTION) ?: '13';

    add_action('wp_head', function () use ($page_title, $page_description) {
        trufi_add_header_tags($page_title, $page_description);
    }, 7);

    $replacement_values = [
        "{{pageTitle}}"    => esc_html($page_title),
        "{{callToAction}}" => esc_html($page_description),
        "{{lineColor}}"    => $line_color,
        "{{statsLines}}"   => $stats['lines'],
        "{{statsPatterns}}" => $stats['patterns'],
        "{{linesData}}"    => wp_json_encode($lines),
        "{{restUrl}}"      => esc_url_raw(rest_url('trufi/v1/pattern/')),
        "{{mapCenterLat}}" => (float) $map_center_lat,
        "{{mapCenterLng}}" => (float) $map_center_lng,
        "{{mapZoom}}"      => (int) $map_zoom,
    ];

    $template_path    = plugin_dir_path(__FILE__) . '../templates/routes-index-template.html';
    $template_content = file_get_contents($template_path);
    $template_content = str_replace(array_keys($replacement_values), array_values($replacement_values), $template_content);

    global $post;
    $post->post_content = minify_html($template_content);
}

/**
 * Disables other posts from being displayed when a route page is requested
 * Not sure why this happens, seems a bit of a hack but this has corrected it.
 * @param $query
 * @return void
 */
function disable_other_posts($query) {
    if (get_query_var('trufi_map_id') && get_query_var('trufi_map_name')) {
        if ($query->is_main_query() && !is_admin()) {
            $query->set('posts_per_page', '1');
            $query->set('p', 0);
        }
    }
}

add_action('pre_get_posts', 'disable_other_posts');
