<?php

use App\Api\TrufiApi;
use App\Utility;

/**
 * Data-access helpers: every place that needs route/pattern data from the
 * Trufi GraphQL API (the virtual route page, the routes index page, the
 * REST endpoint, and the frontend asset loader) goes through these instead
 * of duplicating the "check transient, else fetch + cache" dance.
 */

/**
 * Fetch a single pattern (route variant) by id, transient-cached.
 *
 * @param string $mapId
 *
 * @return array|null
 */
function trufi_fetch_route_data(string $mapId): array|null {
    $cacheKey  = 'trufi_route_data_' . $mapId;
    $routeData = get_transient($cacheKey);
    if (is_array($routeData)) {
        return $routeData;
    }

    $routeData = trufi_api_call(fn(TrufiApi $api) => $api->fetchRoute($mapId));
    if (!empty($routeData['data']['pattern'])) {
        set_transient($cacheKey, $routeData, get_option(TRUFI_CACHE_TTL_OPTION) * 60 * 60);
    }

    return $routeData;
}

/**
 * Fetch the lightweight list of all patterns used to build the routes
 * index/directory page, transient-cached.
 *
 * @return array|null
 */
function trufi_fetch_index_data(): array|null {
    $cacheKey  = 'trufi_routes_index_list';
    $indexData = get_transient($cacheKey);
    if (is_array($indexData)) {
        return $indexData;
    }

    $indexData = trufi_api_call(fn(TrufiApi $api) => $api->routeIndexList());
    if (!empty($indexData['data']['patterns'])) {
        set_transient($cacheKey, $indexData, get_option(TRUFI_CACHE_TTL_OPTION) * 60 * 60);
    }

    return $indexData;
}

/**
 * Runs one TrufiApi call against the configured endpoint, turning failures
 * (unreachable host, expired TLS certificate, non-200 response - TrufiApi
 * throws on those) into a null result instead of a fatal error. Callers
 * only cache successful, non-empty results, so a temporary API outage is
 * retried on the next request instead of being cached for the whole TTL.
 *
 * @param callable(TrufiApi): (array|null) $call
 *
 * @return array|null
 */
function trufi_api_call(callable $call): array|null {
    try {
        return $call(new TrufiApi((string) get_option(TRUFI_API_URL_OPTION)));
    } catch (\Throwable $e) {
        error_log('Trufi Route Pages: API request failed - ' . $e->getMessage());
        return null;
    }
}

/**
 * Fallback operator ("sindicato") and origin data from the open OSM-derived
 * dataset configured in TRUFI_SINDICATOS_URL_OPTION, transient-cached.
 *
 * The feed's route `agency.name` is the primary sindicato (see
 * trufi_line_sindicato()); this dataset only covers the few routes whose
 * agency is "default", and the origin of a pattern - longName is just the
 * line name (e.g. "Trufi 303") with no origin. The
 * dataset's routes carry `name` (same string as the feed's longName), `ref`
 * (same as shortName), `sindicato`, and `from`/`to` - so it's reduced here
 * to three small lookup maps before caching (the raw file is ~2 MB, mostly
 * coordinates we don't need):
 *  - byName:  route name => ['name' => sindicato, 'slug' => slug]
 *  - byRef:   ref => same, only for refs that belong to a single sindicato
 *  - origins: "{name}|{normalized to}" => from, only when unambiguous
 *
 * Returns empty maps when the option is empty (feature off) or the fetch
 * fails; a failure is cached for an hour so a dead URL doesn't cost a slow
 * request on every page view.
 *
 * @return array{byName: array, byRef: array, origins: array}
 */
function trufi_fetch_sindicatos_data(): array {
    $empty = ['byName' => [], 'byRef' => [], 'origins' => []];
    $url   = get_option(TRUFI_SINDICATOS_URL_OPTION, TRUFI_SINDICATOS_DEFAULT_URL);
    if (!$url) {
        return $empty;
    }

    $cacheKey = 'trufi_route_sindicatos';
    $data     = get_transient($cacheKey);
    if (false !== $data) {
        return $data;
    }

    $response = wp_remote_get($url, ['timeout' => 15]);
    $json     = is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200
        ? null
        : json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($json) || empty($json['routes']) || !is_array($json['routes'])) {
        set_transient($cacheKey, $empty, HOUR_IN_SECONDS);
        return $empty;
    }

    $nameCounts = [];
    $refSets    = [];
    $slugs      = [];
    $origins    = [];
    foreach ($json['routes'] as $route) {
        $sindicato = trim($route['sindicato'] ?? '');
        $name      = trim($route['name'] ?? '');
        if ($sindicato === '' || strtolower($sindicato) === 'default' || $name === '') {
            continue;
        }
        $slugs[$sindicato] = $route['sindicatoSlug'] ?? sanitize_title($sindicato);
        $nameCounts[$name][$sindicato] = ($nameCounts[$name][$sindicato] ?? 0) + 1;
        if (($route['ref'] ?? '') !== '') {
            $refSets[$route['ref']][$sindicato] = true;
        }
        if (($route['to'] ?? '') !== '' && ($route['from'] ?? '') !== '') {
            $origins[$name . '|' . trufi_normalize_place($route['to'])][$route['from']] = true;
        }
    }

    $data = $empty;
    foreach ($nameCounts as $name => $counts) {
        arsort($counts);
        $sindicato             = array_key_first($counts);
        $data['byName'][$name] = ['name' => $sindicato, 'slug' => $slugs[$sindicato]];
    }
    foreach ($refSets as $ref => $set) {
        if (count($set) === 1) {
            $sindicato                    = array_key_first($set);
            $data['byRef'][(string) $ref] = ['name' => $sindicato, 'slug' => $slugs[$sindicato]];
        }
    }
    foreach ($origins as $key => $set) {
        if (count($set) === 1) {
            $data['origins'][$key] = array_key_first($set);
        }
    }

    set_transient($cacheKey, $data, get_option(TRUFI_CACHE_TTL_OPTION) * 60 * 60);

    return $data;
}

/**
 * Lowercased, accent-stripped, trimmed place name - used to compare a
 * pattern's headsign with the sindicatos dataset's `to` field.
 */
function trufi_normalize_place(string $place): string {
    return strtolower(trim(remove_accents($place)));
}

/**
 * True when a "sindicato" name is really just a line name ("Linea 103",
 * "Línea M", or the line's own short/long name). The third-party dataset has
 * a few of those, which used to surface lines as if they were associations
 * (QA bug: "Linea 103" listed as a sindicato).
 */
function trufi_is_line_like_name(string $name, array $route): bool {
    $name = trim($name);
    if ($name === '') {
        return true;
    }
    if (preg_match('/^l[ií]neas?\s+\S+$/iu', $name)) {
        return true;
    }
    $own = array_filter([trim((string) ($route['shortName'] ?? '')), trim((string) ($route['longName'] ?? ''))]);

    return in_array(mb_strtolower($name), array_map('mb_strtolower', $own), true);
}

/**
 * The operator ("sindicato") running a line. The feed's route agency is the
 * source of truth (it carries the real sindicato name for every line but a
 * handful tagged "default"); only for those does the third-party OSM-derived
 * dataset fill in, by route name first and shortName second - ignoring any
 * dataset "sindicato" that is really a line name.
 *
 * @param array $route      The pattern's `route` node (gtfsId, shortName, longName, agency{name}).
 * @param array $sindicatos trufi_fetch_sindicatos_data()
 *
 * @return array{name: string, slug: string}|null
 */
function trufi_line_sindicato(array $route, array $sindicatos): ?array {
    $agency = trim((string) ($route['agency']['name'] ?? ''));
    if ($agency !== '' && strtolower($agency) !== 'default' && !trufi_is_line_like_name($agency, $route)) {
        return ['name' => $agency, 'slug' => sanitize_title($agency)];
    }

    $found = $sindicatos['byName'][$route['longName'] ?? ''] ?? $sindicatos['byRef'][$route['shortName'] ?? ''] ?? null;

    return $found && !trufi_is_line_like_name($found['name'], $route) ? $found : null;
}

/**
 * The line's display name. The feed's longName ("Trufi M", "MicroBus 16",
 * "Bus Colomi") is used as-is - the vehicle type isn't reliably known, so we
 * never prepend one ourselves - except a generic leading "Bus " is dropped.
 */
function trufi_line_title(array $route): string {
    $long  = trim((string) ($route['longName'] ?? ''));
    $short = trim((string) ($route['shortName'] ?? ''));
    $title = preg_replace('/^Bus\s+(?=\S)/u', '', $long);

    return $title !== '' ? $title : $short;
}

/**
 * The feed's headsign is often NOT a destination: volunteers fill it with the
 * marking painted on the vehicle ("Panter naranja", "Bandera roja: Nro.
 * blanco"), which only tells variants of a line apart. Those must never be
 * shown as "hacia ...".
 */
function trufi_is_vehicle_marker(string $headsign): bool {
    return (bool) preg_match('/^\s*(panter|pantera|bandera|letrero|cartel)\b|\bnro\.?\s/iu', $headsign);
}

/**
 * OTP's pattern name looks like "46 to Calle X (cochabamba:123) from Calle Y
 * (cochabamba:456)" (the "from" part only when needed to tell patterns
 * apart). Extracts the real last / first stop names from it.
 *
 * @return array{from: string, to: string}
 */
function trufi_pattern_endpoints_from_name(string $name): array {
    $clean = static function (string $text): string {
        $text = trim(preg_replace('/\s*\([^()]*:[^()]*\)\s*$/u', '', trim($text)));

        return strcasecmp($text, 'Innominada') === 0 ? '' : $text;
    };
    $from = $to = '';
    if (preg_match('/\sto\s(.+?)(?:\sfrom\s(.+))?$/u', $name, $m)) {
        $to   = $clean($m[1]);
        $from = isset($m[2]) ? $clean($m[2]) : '';
    }

    return ['from' => $from, 'to' => $to];
}

/**
 * Display labels for one pattern: the feed's longName is just the line name
 * ("Trufi 303"), the destination is the pattern's headsign, and the origin
 * comes from the sindicatos dataset when it can be matched unambiguously,
 * else from `$originFallback` (the route's first stop, on the detail page).
 *
 * @param string      $longName
 * @param string      $headsign
 * @param array       $sindicatos     trufi_fetch_sindicatos_data()
 * @param string|null $originFallback e.g. the first stop's name
 *
 * @return array{headline: string, from: string, to: string, subtitle: string, sentence: string}
 */
function trufi_pattern_labels(string $longName, string $headsign, array $sindicatos, ?string $originFallback = null, ?string $destFallback = null): array {
    $headsign = trim($headsign);
    $variant  = '';
    $to       = $headsign;
    if (trufi_is_vehicle_marker($headsign)) {
        // A vehicle marking, not a place: the real destination is the last stop.
        $variant = $headsign;
        $to      = ($destFallback !== null && strcasecmp(trim($destFallback), 'Innominada') !== 0) ? trim($destFallback) : '';
    }
    $from = $to !== '' ? ($sindicatos['origins'][$longName . '|' . trufi_normalize_place($to)] ?? '') : '';
    if ($from === '' && $originFallback !== null && strcasecmp(trim($originFallback), 'Innominada') !== 0) {
        $from = trim($originFallback);
    }

    if ($from !== '' && $to !== '') {
        $subtitle = $from . ' hacia ' . $to;
        $sentence = 'Parte desde ' . $from . ' hacia el destino ' . $to;
    } elseif ($to !== '') {
        $subtitle = 'Hacia ' . $to;
        $sentence = 'Va hacia el destino ' . $to;
    } else {
        $subtitle = '';
        $sentence = '';
    }

    return [
        'headline' => $longName,
        'from'     => $from,
        'to'       => $to,
        'subtitle' => $subtitle,
        'sentence' => $sentence,
        'variant'  => $variant,
    ];
}

/**
 * Group a flat pattern list into lines - one per feed route (`gtfsId`), NOT
 * per shortName: different routes can share an identifier ("M", "A", "R",
 * "16") and used to be merged into one card with the first one's mode and
 * sindicato (QA bug). Each line carries its patterns (direction/variant), its
 * operator ("sindicato", from the feed's agency) and each pattern's
 * origin/destination labels.
 *
 * Grouping by sindicato happens on top of this, in trufi_render_routes_list_html()
 * and client-side in trufi-index.js.
 *
 * @param array $patterns
 *
 * @return array
 */
function trufi_group_patterns_by_line(array $patterns): array {
    $sindicatos = trufi_fetch_sindicatos_data();
    $lines      = [];
    foreach ($patterns as $pattern) {
        $route     = $pattern['route'] ?? [];
        $shortName = (string) ($route['shortName'] ?? '');
        $longName  = (string) ($route['longName'] ?? '');
        if ($shortName === '' && $longName === '') {
            continue;
        }
        $mode = (string) ($route['mode'] ?? 'BUS');
        $key  = !empty($route['gtfsId']) ? (string) $route['gtfsId'] : $shortName . '|' . $longName . '|' . $mode;
        if (!isset($lines[$key])) {
            $lines[$key] = [
                'id'        => $key,
                'shortName' => $shortName !== '' ? $shortName : $longName,
                'title'     => trufi_line_title($route),
                'mode'      => $mode,
                'sindicato' => trufi_line_sindicato($route, $sindicatos),
                'patterns'  => [],
            ];
        }
        $ends   = trufi_pattern_endpoints_from_name((string) ($pattern['name'] ?? ''));
        $labels = trufi_pattern_labels($longName, $pattern['headsign'] ?? '', $sindicatos, $ends['from'] ?: null, $ends['to'] ?: null);
        $lines[$key]['patterns'][] = [
            'code'     => $pattern['code'],
            'longName' => $longName,
            'from'     => $labels['from'],
            'to'       => $labels['to'],
            'variant'  => $labels['variant'],
            'label'    => '',
            'url'      => trufi_pattern_url($longName, $pattern['code']),
        ];
    }
    foreach ($lines as &$line) {
        trufi_label_line_patterns($line['patterns']);
    }
    unset($line);
    $lines = array_values($lines);
    usort($lines, fn($a, $b) => strnatcasecmp($a['shortName'], $b['shortName']) ?: strnatcasecmp($a['title'], $b['title']));

    return $lines;
}

/**
 * Sets each pattern's final row label. Labels that would repeat inside one
 * line (e.g. several branches all "Hacia Olmedo") get the vehicle marking when
 * known ("... (Panter azul)") and otherwise a "variante N" suffix, so no two
 * rows in a card read the same.
 *
 * @param array $patterns Line patterns, modified in place.
 */
function trufi_label_line_patterns(array &$patterns): void {
    $base = [];
    foreach ($patterns as $i => $p) {
        $label = ($p['from'] !== '' && $p['to'] !== '') ? $p['from'] . ' hacia ' . $p['to']
            : ($p['to'] !== '' ? 'Hacia ' . $p['to'] : ($p['variant'] !== '' ? 'Ruta ' . $p['variant'] : $p['longName']));
        $base[$i] = $label;
    }
    $counts = array_count_values($base);
    $seen   = [];
    foreach ($patterns as $i => &$p) {
        $label = $base[$i];
        if ($counts[$label] > 1) {
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            if ($p['variant'] !== '' && stripos($label, $p['variant']) === false) {
                $label .= ' (' . $p['variant'] . ')';
            } else {
                $label .= ' (variante ' . $seen[$label] . ')';
            }
        }
        $p['label'] = $label;
    }
}

/**
 * Distinct stop/street names along a route, in travel order, skipping the
 * feed's "Innominada" placeholder. Indexable text for "which line passes
 * through X".
 *
 * @param array $stops List of ['name'=>...].
 *
 * @return string[]
 */
function trufi_route_place_names(array $stops): array {
    $names = [];
    foreach ($stops as $stop) {
        $name = trim((string) ($stop['name'] ?? ''));
        if ($name === '' || strcasecmp($name, 'Innominada') === 0) {
            continue;
        }
        $names[mb_strtolower($name)] ??= $name;
    }

    return array_values($names);
}

/**
 * Joins items as Spanish prose: "A, B y C".
 *
 * @param string[] $items
 */
function trufi_join_es(array $items): string {
    $last = array_pop($items);

    return $items ? implode(', ', $items) . ' y ' . $last : (string) $last;
}

/**
 * The public URL of a single route detail page - the single source of truth
 * for route URLs: the sitemap provider, the index page's links/share
 * buttons, canonicals, JSON-LD and llms.txt all build them through here.
 *
 * @param string $longName
 * @param string $code
 *
 * @return string
 */
function trufi_pattern_url(string $longName, string $code): string {
    return trufi_route_url(sanitize_title(Utility::replaceArrowWithWord($longName)), $code);
}

/**
 * URL of a route detail page from its already-slugified name segment. With
 * the permalink structure's trailing slash, so sitemap links and canonicals
 * point at the final URL instead of one that 301s to it.
 */
function trufi_route_url(string $slug, string $code): string {
    $base_path = trufi_get_base_path();

    return home_url(user_trailingslashit("/$base_path/$slug/$code"));
}

/**
 * Great-circle distance between two lat/lon points, in kilometers.
 */
function trufi_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadiusKm = 6371;
    $dLat          = deg2rad($lat2 - $lat1);
    $dLon          = deg2rad($lon2 - $lon1);
    $a             = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

    return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Total length of a route's geometry (list of ['lat'=>, 'lon'=>] points), in
 * kilometers, summing the haversine distance between consecutive points.
 *
 * @param array $geometry
 */
function trufi_route_distance_km(array $geometry): float {
    $total = 0.0;
    for ($i = 0; $i < count($geometry) - 1; $i++) {
        $total += trufi_haversine_km(
            (float) $geometry[$i]['lat'], (float) $geometry[$i]['lon'],
            (float) $geometry[$i + 1]['lat'], (float) $geometry[$i + 1]['lon']
        );
    }

    return $total;
}

/**
 * Formats a km distance the way the UI displays it, e.g. 24.0 -> "24,0 km"
 * (comma decimal separator, matching the site's locale).
 */
function trufi_format_distance_km(float $km): string {
    return number_format($km, 1, ',', '.') . ' km';
}

/**
 * Recursively defangs "</script" in API string data before it's embedded
 * inside an inline <script> block (via wp_localize_script or json_encode).
 * Route/stop names come from an external GraphQL API we don't control, so
 * without this a name containing "</script><script>..." would break out of
 * the data block and execute. Mirrors what wp_json_encode alone does not do.
 *
 * @param mixed $value
 *
 * @return mixed
 */
function trufi_defang_script_close($value) {
    if (is_array($value)) {
        return array_map('trufi_defang_script_close', $value);
    }
    if (is_string($value)) {
        return str_ireplace('</script', '<\/script', $value);
    }

    return $value;
}

/**
 * Single source of truth for the route base path, shared by the rewrite
 * rule and the sitemap provider so they can't drift apart.
 *
 * @return string
 */
function trufi_get_base_path(): string {
  $map_page_id = get_option(TRUFI_MAP_PAGE_ID_OPTION);
  return $map_page_id ? get_post_field('post_name', $map_page_id) : 'routes';
}

/**
 * Prepares the <head> of one of this plugin's two virtual pages (routes
 * index, route detail) for search engines and AI crawlers. Must run before
 * wp_head (it's called from the template_redirect render functions):
 *
 *  - replaces WordPress's own rel_canonical, which points every virtual
 *    route page at the base page (the route pages have no post of their own)
 *    and would mark all of them as duplicates of it;
 *  - sets the document title (no "- Site - Site" duplication) and
 *    `lang="es"` (the page content is Spanish whatever the site locale is);
 *  - prints description, canonical, Open Graph/Twitter tags and JSON-LD.
 *
 * Robots meta is left to WordPress core (wp_robots), so there's only one.
 *
 * @param array{title: string, description: string, canonical: string, og_type?: string, jsonld?: array} $meta
 *        `jsonld` is a list of schema.org nodes, printed as one @graph.
 */
function trufi_setup_page_head(array $meta): void {
    remove_action('wp_head', 'rel_canonical');
    ob_start('trufi_demote_theme_page_title');

    add_filter('pre_get_document_title', fn() => $meta['title']);
    add_filter('language_attributes', fn($output) => preg_replace('/lang="[^"]*"/', 'lang="es"', $output));
    add_action('wp_head', fn() => trufi_add_header_tags($meta), 7);
}

/**
 * Output-buffer callback: the theme prints its own page-title <h1>
 * ("Routes", class entry-title / wp-block-post-title) above the_content on
 * these virtual pages, and the plugin's hero has the real <h1>. Two h1s hurt
 * the document outline, so the theme's becomes a plain <div> with the same
 * attributes (styling and the CSS that hides it keep working).
 */
function trufi_demote_theme_page_title(string $html): string {
    return preg_replace_callback(
        '#<h1(\s[^>]*class="[^"]*(?:entry-title|page-title|wp-block-post-title)[^"]*"[^>]*)>(.*?)</h1>#s',
        fn($m) => '<div' . $m[1] . '>' . $m[2] . '</div>',
        $html
    );
}

/**
 * @param array $meta See trufi_setup_page_head().
 */
function trufi_add_header_tags(array $meta): void {
  $title       = $meta['title'];
  $description = $meta['description'];
  $canonical   = esc_url($meta['canonical']);
  $image       = apply_filters('trufi_og_image', get_site_icon_url(512));

  echo '<meta name="description" content="' . esc_attr($description) . '">';
  echo '<link rel="canonical" href="' . $canonical . '">';
  echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">';
  echo '<meta property="og:locale" content="es_ES">';
  echo '<meta property="og:title" content="' . esc_attr($title) . '">';
  echo '<meta property="og:description" content="' . esc_attr($description) . '">';
  echo '<meta property="og:type" content="' . esc_attr($meta['og_type'] ?? 'website') . '">';
  echo '<meta property="og:url" content="' . $canonical . '">';
  if ($image) {
    echo '<meta property="og:image" content="' . esc_url($image) . '">';
  }
  echo '<meta name="twitter:card" content="summary">';
  echo '<meta name="twitter:title" content="' . esc_attr($title) . '">';
  echo '<meta name="twitter:description" content="' . esc_attr($description) . '">';
  echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />';
  echo '<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>';

  // schema.org structured data (BreadcrumbList, BusTrip, ItemList - see the
  // render functions in template-redirect.php): an explicit,
  // machine-readable description of the page for search engines and AI
  // crawlers. wp_json_encode escapes "/", so API strings can't close the tag.
  if (!empty($meta['jsonld'])) {
    $graph = ['@context' => 'https://schema.org', '@graph' => $meta['jsonld']];
    echo '<script type="application/ld+json">' . wp_json_encode($graph, JSON_UNESCAPED_UNICODE) . '</script>';
  }
}

/**
 * schema.org BreadcrumbList node: Home > base page [> current page].
 *
 * @param array<array{name: string, url: string}> $trail After "Home".
 */
function trufi_breadcrumb_jsonld(array $trail): array {
  $items = [['name' => get_bloginfo('name'), 'url' => home_url('/')], ...$trail];

  return [
    '@type'           => 'BreadcrumbList',
    'itemListElement' => array_map(
      fn($item, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $item['name'], 'item' => $item['url']],
      $items,
      array_keys($items)
    ),
  ];
}

/**
 * Untruncated description text cut to a search-snippet-friendly length.
 */
function trufi_meta_description(string $text, int $max = 160): string {
  $text = trim(preg_replace('/\s+/', ' ', $text));

  return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
}

/**
 * Adds an Allow rule for this plugin's CSS/JS (some sites disallow
 * /wp-content/plugins/ wholesale, which stops Google from rendering the
 * route pages - the map and list need those assets) and makes sure the
 * sitemap is advertised. Only affects WordPress's virtual robots.txt; a
 * physical robots.txt file in the site root bypasses this filter.
 */
add_filter('robots_txt', function ($output, $public) {
  if (!$public) {
    return $output;
  }

  global $plugin_url;
  $assets_path = wp_parse_url($plugin_url . 'assets/', PHP_URL_PATH);
  $allow       = 'Allow: ' . $assets_path;
  if (stripos($output, $allow) === false) {
    $output = preg_match('/^User-agent:\s*\*\s*$/mi', $output)
      ? preg_replace('/^(User-agent:\s*\*\s*)$/mi', "$1\n" . $allow, $output, 1)
      : "User-agent: *\n" . $allow . "\n" . $output;
  }
  if (stripos($output, 'Sitemap:') === false) {
    $output .= "\nSitemap: " . home_url('/wp-sitemap.xml') . "\n";
  }

  return $output;
}, 20, 2);

/**
 * Light HTML minifier for the page templates: drops comments and the
 * newline+indent whitespace between tags. Spaces on one line (e.g. between
 * "</strong> <span>hacia</span>") are kept, since they separate words.
 */
function minify_html($input)
{
  return preg_replace(array('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '/>\s*\n\s*</', '/\s*\n\s*/', '/[ \t]{2,}/'), array('', '><', ' ', ' '), $input);
}
