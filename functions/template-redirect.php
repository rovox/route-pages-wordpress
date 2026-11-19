<?php

// A route URL under a previous base page (see trufi_maps_add_rewrite_rules()):
// send it permanently to the same route under the current base. Priority 1,
// before core's redirect_canonical, so it's a single 301 hop.
add_action('template_redirect', function () {
    if (get_query_var('trufi_legacy') && get_query_var('trufi_map_id') && get_query_var('trufi_map_name')) {
        wp_safe_redirect(trufi_route_url(sanitize_title(get_query_var('trufi_map_name')), rawurldecode(get_query_var('trufi_map_id'))), 301);
        exit;
    }
}, 1);

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
        trufi_maps_route_not_available($routeData === null);
        return;
    }

    $route             = $pattern['route'] ?? [];
    $long_name         = $route['longName'] ?? '';
    $route_short_name  = $route['shortName'] ?? '';
    $line_title        = trufi_line_title($route);
    $stops             = $pattern['stops'] ?? [];
    $sindicatos        = trufi_fetch_sindicatos_data();
    $labels            = trufi_pattern_labels($long_name, $pattern['headsign'] ?? '', $sindicatos, $stops[0]['name'] ?? null, $stops ? end($stops)['name'] : null);
    $labels['headline'] = $line_title;
    $sindicato         = trufi_line_sindicato($route, $sindicatos);
    $route_title       = $labels['subtitle'] !== '' ? $line_title . ': ' . $labels['subtitle'] : $line_title;
    $page_title        = $route_title . ' | ' . get_bloginfo('name');
    $google_play_url   = get_option(TRUFI_GOOGLE_PLAY_URL_OPTION);
    $apple_store_url   = get_option(TRUFI_APPLE_STORE_URL_OPTION);
    $google_play_image = get_option(TRUFI_GOOGLE_PLAY_IMAGE_OPTION);
    $apple_store_image = get_option(TRUFI_APPLE_STORE_IMAGE_OPTION);
    $map_page_id       = get_option(TRUFI_MAP_PAGE_ID_OPTION);
    $show_location     = get_option(TRUFI_SHOW_LOCATION_OPTION, '1');

    $distance_km      = trufi_route_distance_km($pattern['geometry'] ?? []);
    $distance_label   = trufi_format_distance_km($distance_km);
    $route_url        = trufi_pattern_url($long_name, $mapId);
    $places           = trufi_route_place_names($stops);
    $meta_description = trufi_meta_description(sprintf(
        '%s%s. %d paradas, %s%s.%s Sin garantía de servicio las 24 horas.',
        $line_title,
        $labels['sentence'] !== '' ? ': ' . $labels['sentence'] : '',
        count($stops),
        $distance_label,
        $places ? ', pasa por ' . trufi_join_es(array_slice($places, 0, 3)) : '',
        $sindicato ? ' Sindicato: ' . $sindicato['name'] . '.' : ''
    ), 175);
    $stops_list_html    = trufi_render_stops_list_html($stops);
    $summary_html       = trufi_render_route_summary_html($line_title, $route_short_name, $labels, $sindicato, $distance_label, count($stops), $places);
    $subtitle_html      = trufi_route_sentence_html($labels);
    $line_color         = sanitize_hex_color((string) get_option(TRUFI_LINE_COLOR_OPTION)) ?: '#00BCD4';
    $store_buttons_html = trufi_render_store_buttons_html([
        ['url' => $google_play_url, 'image' => $google_play_image, 'label' => 'Disponible en Google Play', 'alt' => 'Descargar en Google Play'],
        ['url' => $apple_store_url, 'image' => $apple_store_image, 'label' => 'Descargar en App Store', 'alt' => 'Descargar en la App Store'],
    ]);

    trufi_setup_page_head([
        'title'       => $page_title,
        'description' => $meta_description,
        'canonical'   => $route_url,
        'jsonld'      => [
            trufi_breadcrumb_jsonld([
                ['name' => get_post_field('post_title', $map_page_id), 'url' => get_permalink($map_page_id)],
                ['name' => $route_title, 'url' => $route_url],
            ]),
            trufi_route_bustrip_jsonld($pattern, $labels, $sindicato, $route_title, $route_url, $meta_description),
        ],
    ]);

    $replacement_values = [
        "{{pageTitle}}"        => esc_html($page_title),
        "{{routeHeadline}}"    => esc_html($labels['headline']),
        "{{routeSubtitleHtml}}" => $subtitle_html,
        "{{routeSummaryHtml}}" => $summary_html,
        "{{lineColor}}"        => esc_attr($line_color),
        "{{mapLabel}}"         => esc_attr('Mapa interactivo del recorrido de ' . $route_title),
        "{{routeSindicato}}"   => esc_html($sindicato['name'] ?? ''),
        "{{routeSindicatoHidden}}" => $sindicato ? '' : 'hidden',
        "{{routeShortName}}"   => esc_html($route_short_name),
        "{{routeDescription}}" => esc_html($meta_description),
        "{{routeDistance}}"    => esc_html($distance_label),
        "{{routeStopCount}}"   => esc_html((string) count($stops)),
        "{{stopsListHtml}}"    => $stops_list_html,
        "{{indexUrl}}"         => esc_url(get_permalink($map_page_id)),
        "{{storeButtonsHtml}}" => $store_buttons_html,
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
    $post->post_title     = $labels['headline'];
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
 * A route URL whose pattern can't be shown: 404 when the API answered but
 * has no such pattern, 503 (temporary, retry later) when the API itself
 * failed - so search engines neither index an empty page nor drop a real
 * route page because of a short outage.
 *
 * @param bool $apiFailed
 *
 * @return void
 */
function trufi_maps_route_not_available(bool $apiFailed) {
    global $wp_query;

    if ($apiFailed) {
        status_header(503);
        header('Retry-After: 3600');
        nocache_headers();
        wp_die(
            esc_html__('Route information is temporarily unavailable. Please try again later.', 'TrufiApi-maps'),
            esc_html__('Temporarily unavailable', 'TrufiApi-maps'),
            ['response' => 503]
        );
    }

    $wp_query->set_404();
    status_header(404);
    nocache_headers();
}

/**
 * schema.org BusTrip node for a route detail page: line number/name,
 * origin and destination stops (with coordinates) and, when known, the
 * operating sindicato - the facts an AI answer engine needs to cite this
 * page for "which bus goes from A to B".
 *
 * @param array      $pattern   Pattern from trufi_fetch_route_data().
 * @param array      $labels    trufi_pattern_labels().
 * @param array|null $sindicato trufi_line_sindicato().
 * @param string     $name
 * @param string     $url
 *
 * @return array
 */
function trufi_route_bustrip_jsonld(array $pattern, array $labels, ?array $sindicato, string $name, string $url, string $description = ''): array {
    $stops = $pattern['stops'] ?? [];
    $stop  = function (?array $stop, string $label) {
        if (!$stop && $label === '') {
            return null;
        }
        $node = ['@type' => 'BusStop', 'name' => $label !== '' ? $label : ($stop['name'] ?? '')];
        if ($stop) {
            $node['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float) $stop['lat'], 'longitude' => (float) $stop['lon']];
        }
        return $node;
    };

    $node = array_filter([
        '@type'            => 'BusTrip',
        'name'             => $name,
        'url'              => $url,
        'description'      => $description,
        'busNumber'        => $pattern['route']['shortName'] ?? '',
        'busName'          => trufi_line_title($pattern['route'] ?? []),
        'departureBusStop' => $stop($stops[0] ?? null, $labels['from']),
        'arrivalBusStop'   => $stop($stops ? $stops[count($stops) - 1] : null, $labels['to']),
        'provider'         => $sindicato ? ['@type' => 'Organization', 'name' => $sindicato['name']] : null,
    ]);

    return $node;
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
        $role    = $i === 0 ? 'Origen: ' : ($i === $lastIndex ? 'Destino: ' : '');
        // A <button> (not a bare clickable <li>) so keyboard and screen-reader
        // users can focus a stop and have the map jump to it (WCAG 2.1.1).
        $html    .= sprintf(
            '<li class="trufi-stop-item"><button type="button" class="trufi-stop %s" data-stop-index="%d" data-lat="%s" data-lon="%s"><span class="trufi-stop-dot" aria-hidden="true"></span><span class="trufi-stop-name">%s%s</span></button></li>',
            esc_attr($variant),
            (int) $i,
            esc_attr($stop['lat']),
            esc_attr($stop['lon']),
            $role !== '' ? '<span class="trufi-stop-role">' . esc_html($role) . '</span>' : '',
            esc_html($name)
        );
    }

    return $html;
}

/**
 * The hero sentence of a route page as HTML: "Parte desde <origin> hacia el
 * destino <destination>", with "hacia" in the site's pink (a styled span, not
 * an arrow glyph, so screen readers read a real word).
 *
 * @param array $labels trufi_pattern_labels()
 */
function trufi_route_sentence_html(array $labels): string {
    $html = '';
    if ($labels['from'] !== '' && $labels['to'] !== '') {
        $html = sprintf(
            'Parte desde <strong>%s</strong> <span class="trufi-hacia">hacia</span> el destino <strong>%s</strong>',
            esc_html($labels['from']),
            esc_html($labels['to'])
        );
    } elseif ($labels['to'] !== '') {
        $html = sprintf('Va <span class="trufi-hacia">hacia</span> el destino <strong>%s</strong>', esc_html($labels['to']));
    }
    if (($labels['variant'] ?? '') !== '') {
        $html .= sprintf(
            '%s<span class="trufi-variant">Variante del recorrido: %s</span>',
            $html !== '' ? '<br>' : '',
            esc_html($labels['variant'])
        );
    }

    return $html;
}

/**
 * Longer "about this route" copy for the route page (real indexable text for
 * search engines and answer engines, and context for people who can't read
 * the map): what the line is, who runs it, where this variant starts and
 * ends, how long it is, which streets/zones it passes, and that schedules
 * are not guaranteed.
 *
 * @param string     $title        Line display name.
 * @param string     $short_name
 * @param array      $labels       trufi_pattern_labels()
 * @param array|null $sindicato
 * @param string     $distance     Formatted distance, e.g. "12,3 km".
 * @param int        $stop_count
 * @param string[]   $places       trufi_route_place_names()
 */
function trufi_render_route_summary_html(string $title, string $short_name, array $labels, ?array $sindicato, string $distance, int $stop_count, array $places): string {
    $e = 'esc_html';

    $intro = sprintf('<strong>%s</strong> es una línea de transporte público de Cochabamba%s.', $e($title), $sindicato ? ', registrada en el <strong>' . $e($sindicato['name']) . '</strong>' : '');
    if ($labels['sentence'] !== '') {
        $intro .= ' Este recorrido ' . lcfirst(trufi_route_sentence_html($labels)) . '.';
    }
    $intro .= sprintf(
        ' Su trazado mide aproximadamente %s y tiene %d %s registradas.',
        $e($distance),
        $stop_count,
        $stop_count === 1 ? 'parada' : 'paradas'
    );

    $html = '<p>' . $intro . '</p>';

    if ($places) {
        $shown = array_slice($places, 0, 14);
        $rest  = count($places) - count($shown);
        $html .= '<p>En el sentido del recorrido pasa por: ' . $e(trufi_join_es($shown))
            . ($rest > 0 ? ' y otras ' . $rest . ' calles o zonas' : '') . '.</p>';
    }

    $html .= '<p class="trufi-route-note">Esta página es un servicio complementario para ubicar rápidamente las líneas registradas y ver su trayecto completo en el mapa. '
        . 'Los horarios y la frecuencia no están garantizados: las líneas no necesariamente operan las 24 horas ni todos los días. '
        . 'Confirma con el sindicato o en la app Trufi antes de viajar.</p>';

    return $html;
}

/**
 * Server-renders the app store download toolbar for the route detail page.
 * A store is only shown once its URL is configured (Settings -> Trufi
 * Routes); without an uploaded badge image it falls back to a plain text
 * button instead of a broken <img>. Returns '' (no toolbar at all) when no
 * store is configured.
 *
 * @param array $stores List of ['url'=>, 'image'=>, 'label'=>, 'alt'=>].
 *
 * @return string
 */
function trufi_render_store_buttons_html(array $stores): string {
    $buttons = '';
    foreach ($stores as $store) {
        if (empty($store['url'])) {
            continue;
        }
        $inner    = !empty($store['image'])
            ? sprintf('<img src="%s" alt="%s">', esc_url($store['image']), esc_attr($store['alt']))
            : sprintf('<span class="trufi-store-badge">%s</span>', esc_html($store['label']));
        $buttons .= sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($store['url']), $inner);
    }

    if ($buttons === '') {
        return '';
    }

    return '<div class="trufi-route-toolbar"><div class="trufi-store-buttons">' . $buttons . '</div></div>';
}

/**
 * Renders the routes index/directory page: the base "Routes" page itself
 * (no route segments in the URL) gets the searchable sidebar + map view,
 * built from a lightweight list of all patterns from the Trufi API.
 *
 * Lines are grouped by route shortName; each line's operator ("sindicato")
 * comes from the external sindicatos dataset (trufi_fetch_sindicatos_data()),
 * since the Trufi feed reports agency "default" for every route. The
 * Sindicatos stat counts the distinct operators behind the listed lines.
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
        'sindicatos' => count(array_unique(array_filter(array_map(fn($line) => $line['sindicato']['slug'] ?? null, $lines)))),
        'unassigned' => count(array_filter($lines, fn($line) => empty($line['sindicato']['slug']))),
        'lines'      => count($lines),
        'patterns'   => count($patterns),
    ];

    $map_page_id   = (int) get_option(TRUFI_MAP_PAGE_ID_OPTION);
    $index_url     = get_permalink($map_page_id);
    $page_title    = 'Rutas de micros, trufis y taxi trufis en Cochabamba | ' . get_bloginfo('name');
    $show_location = get_option(TRUFI_SHOW_LOCATION_OPTION, '1');
    $description   = trufi_meta_description(sprintf(
        'Encuentra las %d líneas de Cochabamba%s y el trayecto completo de sus %d recorridos en el mapa, con paradas y calles. Sin garantía de servicio 24 horas.',
        $stats['lines'],
        $stats['sindicatos'] ? sprintf(' y su sindicato (%d)', $stats['sindicatos']) : '',
        $stats['patterns']
    ), 175);
    $item_list = [];
    foreach ($lines as $line) {
        foreach ($line['patterns'] as $pattern) {
            $item_list[] = [
                '@type'    => 'ListItem',
                'position' => count($item_list) + 1,
                'name'     => $line['title'] . ': ' . trufi_pattern_row_label($pattern),
                'url'      => $pattern['url'],
            ];
        }
    }

    trufi_setup_page_head([
        'title'       => $page_title,
        'description' => $description,
        // Never the filtered URL (?q=, ?sindicato=, ?modo=): one canonical index page.
        'canonical'   => $index_url,
        'jsonld'      => [
            trufi_breadcrumb_jsonld([['name' => get_post_field('post_title', $map_page_id), 'url' => $index_url]]),
            [
                '@type'           => 'ItemList',
                'name'            => 'Rutas de transporte público en Cochabamba',
                'numberOfItems'   => count($item_list),
                'itemListElement' => $item_list,
            ],
        ],
    ]);

    $replacement_values = [
        "{{pageTitle}}"     => esc_html($page_title),
        "{{routesListHtml}}"        => trufi_render_routes_list_html($lines, $index_url),
        "{{statsSindicatos}}"       => esc_html((string) $stats['sindicatos']),
        "{{sindicatosStatHidden}}"  => $stats['sindicatos'] ? '' : 'hidden',
        "{{sindicatoFilterHidden}}" => $stats['sindicatos'] ? '' : 'hidden',
        "{{modeFilterHidden}}" => count(array_unique(array_column($lines, 'mode'))) > 1 ? '' : 'hidden',
        "{{statsUnassigned}}"       => esc_html((string) $stats['unassigned']),
        "{{unassignedStatHidden}}"  => $stats['unassigned'] ? '' : 'hidden',
        "{{statsLines}}"    => esc_html((string) $stats['lines']),
        "{{statsPatterns}}" => esc_html((string) $stats['patterns']),
        "{{showLocation}}"  => $show_location === '1' ? '1' : '0',
    ];

    $template_path    = plugin_dir_path(__FILE__) . '../templates/routes-index-template.html';
    $template_content = file_get_contents($template_path);
    $template_content = str_replace(array_keys($replacement_values), array_values($replacement_values), $template_content);

    global $post;
    $post->post_content = minify_html($template_content);
}

/**
 * A route row's label, e.g. "Villa Taquiña hacia Loma Pampa", "Hacia Paraiso",
 * or the line name when neither end is known. Mirrors `patternLabel()` in
 * assets/js/trufi-index.js.
 *
 * @param array $pattern A pattern from trufi_group_patterns_by_line().
 */
function trufi_pattern_row_label(array $pattern): string {
    if (($pattern['label'] ?? '') !== '') {
        return $pattern['label'];
    }
    if ($pattern['from'] !== '' && $pattern['to'] !== '') {
        return $pattern['from'] . ' hacia ' . $pattern['to'];
    }

    return $pattern['to'] !== '' ? 'Hacia ' . $pattern['to'] : $pattern['longName'];
}

/**
 * Server-renders the routes index list (sindicato groups > lines > route
 * links), so search engines and AI crawlers - which mostly don't run
 * JavaScript - see every route link in the page HTML. trufi-index.js only
 * replaces it when the visitor filters.
 *
 * The markup MUST stay identical to what renderGroup()/renderLine() build
 * in assets/js/trufi-index.js (same classes, same data-share-* attributes
 * the JS share handler reads, same group order and labels).
 *
 * @param array  $lines     trufi_group_patterns_by_line().
 * @param string $index_url The index page's permalink (for sindicato share links).
 *
 * @return string
 */
function trufi_render_routes_list_html(array $lines, string $index_url): string {
    if (empty($lines)) {
        return '<p class="trufi-index-empty">No se encontraron líneas.</p>';
    }

    $share_button = fn(string $url, string $title, string $label) => sprintf(
        '<button type="button" class="trufi-share-btn" data-share-url="%s" data-share-title="%s" aria-label="%s">Compartir</button>',
        esc_url($url),
        esc_attr($title),
        esc_attr($label)
    );

    // Heading level of a line title: h4 under a sindicato's h3, h3 in the flat list.
    $render_line = function (array $line, string $tag) use ($share_button): string {
        $html = sprintf(
            '<div class="trufi-line"><div class="trufi-line-header"><span class="trufi-line-badge" aria-hidden="true"><span class="trufi-icon" data-icon="bus"></span>%s</span><%s class="trufi-line-title">%s</%s></div><ul class="trufi-pattern-list">',
            esc_html($line['shortName']),
            $tag,
            esc_html($line['title']),
            $tag
        );
        foreach ($line['patterns'] as $pattern) {
            $label = trufi_pattern_row_label($pattern);
            $html .= sprintf(
                '<li class="trufi-pattern"><a class="trufi-pattern-link" href="%s"><span class="trufi-icon" data-icon="route" aria-hidden="true"></span><span class="trufi-pattern-label">%s</span></a>%s</li>',
                esc_url($pattern['url']),
                esc_html($label),
                $share_button($pattern['url'], $line['title'] . ' · ' . $label, 'Compartir la ruta ' . $line['title'] . ', ' . $label)
            );
        }

        return $html . '</ul></div>';
    };

    $render_group = function (string $name, int $total, ?string $slug, array $group_lines) use ($render_line, $share_button, $index_url): string {
        $html = sprintf(
            '<section class="trufi-group" aria-label="%s"><div class="trufi-group-header"><div class="trufi-group-info"><h3 class="trufi-group-name">%s</h3><span class="trufi-group-count">%s</span></div>%s</div>',
            esc_attr($name),
            esc_html($name),
            esc_html($total . ($total === 1 ? ' ruta trazada' : ' rutas trazadas')),
            $slug ? $share_button(add_query_arg('sindicato', $slug, $index_url), $name, 'Compartir las rutas de ' . $name) : ''
        );

        return $html . implode('', array_map(fn($line) => $render_line($line, 'h4'), $group_lines)) . '</section>';
    };

    $groups = [];
    $others = [];
    foreach ($lines as $line) {
        if ($line['sindicato']) {
            $groups[$line['sindicato']['slug']]['name']    = $line['sindicato']['name'];
            $groups[$line['sindicato']['slug']]['lines'][] = $line;
        } else {
            $others[] = $line;
        }
    }

    // No operator data at all (no agency names, dataset off): plain line list.
    if (empty($groups)) {
        return implode('', array_map(fn($line) => $render_line($line, 'h3'), $lines));
    }

    $sort_key = fn(string $name) => strtolower(remove_accents($name));
    uasort($groups, fn($a, $b) => strcmp($sort_key($a['name']), $sort_key($b['name'])));

    $html        = '';
    $count_paths = fn(array $group_lines) => array_sum(array_map(fn($line) => count($line['patterns']), $group_lines));
    foreach ($groups as $slug => $group) {
        $html .= $render_group($group['name'], $count_paths($group['lines']), $slug, $group['lines']);
    }
    if ($others) {
        $html .= $render_group('Sin sindicato identificado', $count_paths($others), null, $others);
    }

    return $html;
}

/**
 * Questions people (and answer engines) ask about this directory. Rendered
 * in /llms.txt only. It is not shown on the page, so there is no FAQPage
 * markup without visible content.
 *
 * @return array<array{q: string, a: string}>
 */
function trufi_index_faq(int $lines, int $routes): array {
    return [
        [
            'q' => '¿Qué es esta página?',
            'a' => sprintf('Es un servicio complementario de Trufi App para encontrar rápidamente las líneas de transporte público registradas en Cochabamba (%d líneas y %d recorridos), saber a qué sindicato pertenecen y ver su trayecto completo en el mapa.', $lines, $routes),
        ],
        [
            'q' => '¿Cómo encuentro una línea?',
            'a' => 'Escribe el nombre o número de la línea en el buscador (por ejemplo "M" o "103"). Si no hay una línea con ese nombre, la búsqueda también revisa los lugares de origen y destino. Puedes filtrar por sindicato.',
        ],
        [
            'q' => '¿Cómo sé por qué calles o zonas pasa una línea?',
            'a' => 'Abre el recorrido de la línea: la página muestra el trazado en el mapa, la lista ordenada de paradas con el nombre de cada calle o intersección y el texto con las calles y zonas por las que pasa.',
        ],
        [
            'q' => '¿Las líneas funcionan las 24 horas?',
            'a' => 'No se garantiza. Esta guía muestra los recorridos registrados, no los horarios ni la frecuencia. Las líneas no necesariamente operan las 24 horas ni todos los días; confirma con el sindicato o en la app Trufi.',
        ],
        [
            'q' => '¿De dónde salen los datos?',
            'a' => 'Los recorridos y paradas provienen del mapeo abierto de Trufi (GTFS construido a partir de OpenStreetMap por voluntarios) y se actualizan periódicamente, por lo que pueden cambiar.',
        ],
    ];
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
