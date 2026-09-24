<?php

add_action('template_redirect', 'trufi_maps_template_redirect');
function trufi_maps_template_redirect() {
    if (!get_query_var('trufi_map_id') && !get_query_var('trufi_map_name') && is_page((int) get_option(TRUFI_MAP_PAGE_ID_OPTION))) {
        trufi_maps_render_routes_index();
        return;
    }

    if (get_query_var('trufi_map_id') && get_query_var('trufi_map_name')) {
        trufi_maps_render_route_page();
    }
}

/**
 * Renders a single virtual route page: the map, the "paradas" (stops) list,
 * and the route's headline/stats. Data (geometry + stops) is embedded for
 * Leaflet via wp_localize_script (functions/enqueue-scripts.php), the
 * template itself only carries the server-rendered text content.
 *
 * @return void
 */
function trufi_maps_render_route_page() {
    $mapId   = rawurldecode(get_query_var('trufi_map_id'));
    $mapName = get_query_var('trufi_map_name');

    $routeData = trufi_fetch_route_data($mapId);
    $pattern   = $routeData['data']['pattern'] ?? null;

    if (!$pattern) {
        return;
    }

    $long_name         = $pattern['route']['longName'] ?? '';
    $route_short_name  = $pattern['route']['shortName'] ?? '';
    $split              = trufi_split_route_long_name($long_name);
    $page_title        = $long_name . ' - ' . get_bloginfo('name');
    $page_description  = get_option(TRUFI_SITE_DESCRIPTION_OPTION);
    $meta_description  = $page_description . ' - ' . $long_name;
    $google_play_url   = get_option(TRUFI_GOOGLE_PLAY_URL_OPTION);
    $apple_store_url   = get_option(TRUFI_APPLE_STORE_URL_OPTION);
    $google_play_image = get_option(TRUFI_GOOGLE_PLAY_IMAGE_OPTION);
    $apple_store_image = get_option(TRUFI_APPLE_STORE_IMAGE_OPTION);
    $map_page_id       = get_option(TRUFI_MAP_PAGE_ID_OPTION);
    $show_location     = get_option(TRUFI_SHOW_LOCATION_OPTION, '1');

    $stops         = $pattern['stops'] ?? [];
    $distance_km   = trufi_route_distance_km($pattern['geometry'] ?? []);
    $stops_list_html = trufi_render_stops_list_html($stops);

    add_action('wp_head', function () use ($page_title, $meta_description, $long_name, $map_page_id) {
        trufi_add_header_tags($page_title, $meta_description, $long_name, $map_page_id);
    }, 7);

    $replacement_values = [
        "{{pageTitle}}"        => esc_html($page_title),
        "{{routeHeadline}}"    => esc_html($split['headline']),
        "{{routeSubtitle}}"    => esc_html($split['subtitle']),
        "{{routeShortName}}"   => esc_html($route_short_name),
        "{{routeDescription}}" => esc_html($meta_description),
        "{{routeDistance}}"    => esc_html(trufi_format_distance_km($distance_km)),
        "{{routeStopCount}}"   => esc_html((string) count($stops)),
        "{{stopsListHtml}}"    => $stops_list_html,
        "{{indexUrl}}"         => esc_url(get_permalink($map_page_id)),
        "{{googlePlayUrl}}"    => esc_url($google_play_url),
        "{{appleStoreUrl}}"    => esc_url($apple_store_url),
        "{{googlePlayImage}}"  => esc_url($google_play_image),
        "{{appleStoreImage}}"  => esc_url($apple_store_image),
        "{{showLocation}}"     => $show_location === '1' ? '1' : '0',
    ];

    $template_path    = plugin_dir_path(__FILE__) . '../templates/map-template.html';
    $template_content = file_get_contents($template_path);
    $template_content = str_replace(array_keys($replacement_values), array_values($replacement_values), $template_content);

    // The template's content is fully static HTML now (map/script config
    // travels via wp_localize_script, see enqueue-scripts.php), but keep
    // bypassing the_content's filters: wpautop etc. still reformat/escape
    // plain markup in ways that break the map container's layout.
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

/**
 * Server-renders the "Paradas" (stops) <li> list for the route detail page:
 * doing it in PHP (rather than only via JS from the localized pattern data)
 * means stop names - real content, and useful for SEO - are present even
 * without JS, and match what map-template.html's script decorates with
 * marker interactivity.
 *
 * @param array $stops List of ['name'=>, 'lat'=>, 'lon'=>, 'code'=>].
 *
 * @return string
 */
function trufi_render_stops_list_html(array $stops): string {
    if (empty($stops)) {
        return '<li class="trufi-index-empty">No hay paradas registradas para esta ruta.</li>';
    }

    $lastIndex = count($stops) - 1;
    $html      = '';
    foreach ($stops as $i => $stop) {
        $variant = $i === 0 ? 'is-start' : ($i === $lastIndex ? 'is-end' : '');
        $name    = $stop['name'] !== '' ? $stop['name'] : 'Innominada';
        $html    .= sprintf(
            '<li class="trufi-stop %s" data-stop-index="%d" data-lat="%s" data-lon="%s"><span class="trufi-stop-dot" aria-hidden="true"></span><span class="trufi-stop-name">%s</span></li>',
            esc_attr($variant),
            (int) $i,
            esc_attr($stop['lat']),
            esc_attr($stop['lon']),
            esc_html($name)
        );
    }

    return $html;
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
    // This page's content is static server-rendered HTML now; the search UI
    // and map are wired up by enqueued assets (see enqueue-scripts.php), not
    // inline script. Still bypass the_content's filters (wpautop etc.): they
    // reformat plain markup in ways that break the fixed-height app layout.
    remove_all_filters('the_content');

    $indexData = trufi_fetch_index_data();
    $patterns  = $indexData['data']['patterns'] ?? [];
    $lines     = trufi_group_patterns_by_line($patterns);

    $stats = [
        'lines'    => count($lines),
        'patterns' => count($patterns),
    ];

    $page_title       = 'Rutas · Explora las líneas sobre el mapa - ' . get_bloginfo('name');
    $page_description = get_option(TRUFI_SITE_DESCRIPTION_OPTION);
    $show_location     = get_option(TRUFI_SHOW_LOCATION_OPTION, '1');

    add_action('wp_head', function () use ($page_title, $page_description) {
        trufi_add_header_tags($page_title, $page_description);
    }, 7);
    add_filter('pre_get_document_title', function () use ($page_title) {
        return $page_title;
    });

    $replacement_values = [
        "{{pageTitle}}"     => esc_html($page_title),
        "{{statsLines}}"    => esc_html((string) $stats['lines']),
        "{{statsPatterns}}" => esc_html((string) $stats['patterns']),
        "{{showLocation}}"  => $show_location === '1' ? '1' : '0',
    ];

    $template_path    = plugin_dir_path(__FILE__) . '../templates/routes-index-template.html';
    $template_content = file_get_contents($template_path);
    $template_content = str_replace(array_keys($replacement_values), array_values($replacement_values), $template_content);

    global $post;
    $post->post_title   = $page_title;
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
