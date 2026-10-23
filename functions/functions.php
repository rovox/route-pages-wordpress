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
 * Operator ("sindicato") and origin data from the open OSM-derived dataset
 * configured in TRUFI_SINDICATOS_URL_OPTION, transient-cached.
 *
 * The Trufi feed has neither: every route's agency is "default", and
 * longName is just the line name (e.g. "Trufi 303") with no origin. The
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
 * The operator ("sindicato") running a line, looked up by route name first
 * and by shortName as a fallback.
 *
 * @param string $longName
 * @param string $shortName
 * @param array  $sindicatos trufi_fetch_sindicatos_data()
 *
 * @return array{name: string, slug: string}|null
 */
function trufi_line_sindicato(string $longName, string $shortName, array $sindicatos): ?array {
    return $sindicatos['byName'][$longName] ?? $sindicatos['byRef'][$shortName] ?? null;
}

/**
 * Display labels for one pattern: the feed's longName is just the line name
 * ("Trufi 303"), the destination is the pattern's headsign, and the origin
 * comes from the sindicatos dataset when it can be matched unambiguously.
 *
 * @param string $longName
 * @param string $headsign
 * @param array  $sindicatos trufi_fetch_sindicatos_data()
 *
 * @return array{headline: string, from: string, to: string, subtitle: string}
 */
function trufi_pattern_labels(string $longName, string $headsign, array $sindicatos): array {
    $to   = trim($headsign);
    $from = $to !== '' ? ($sindicatos['origins'][$longName . '|' . trufi_normalize_place($to)] ?? '') : '';

    if ($from !== '') {
        $subtitle = $from . ' → ' . $to;
    } elseif ($to !== '') {
        $subtitle = 'Hacia ' . $to;
    } else {
        $subtitle = '';
    }

    return [
        'headline' => $longName,
        'from'     => $from,
        'to'       => $to,
        'subtitle' => $subtitle,
    ];
}

/**
 * Group a flat pattern list into lines (by route shortName), each carrying
 * its patterns (direction/variant) underneath, plus the line's operator
 * ("sindicato", or null when the sindicatos dataset has no match) and each
 * pattern's origin/destination labels.
 *
 * Grouping by sindicato happens client-side (trufi-index.js) on top of this,
 * since the feed itself has no operator data (agency is "default" for every
 * route) - it comes from trufi_fetch_sindicatos_data().
 *
 * @param array $patterns
 *
 * @return array
 */
function trufi_group_patterns_by_line(array $patterns): array {
    $sindicatos = trufi_fetch_sindicatos_data();
    $lines      = [];
    foreach ($patterns as $pattern) {
        $shortName = $pattern['route']['shortName'] ?? '';
        if ($shortName === '') {
            continue;
        }
        $longName = $pattern['route']['longName'] ?? '';
        if (!isset($lines[$shortName])) {
            $lines[$shortName] = [
                'shortName' => $shortName,
                'mode'      => $pattern['route']['mode'] ?? 'BUS',
                'sindicato' => trufi_line_sindicato($longName, $shortName, $sindicatos),
                'patterns'  => [],
            ];
        }
        $labels = trufi_pattern_labels($longName, $pattern['headsign'] ?? '', $sindicatos);
        $lines[$shortName]['patterns'][] = [
            'code'     => $pattern['code'],
            'longName' => $longName,
            'from'     => $labels['from'],
            'to'       => $labels['to'],
            'url'      => trufi_pattern_url($longName, $pattern['code']),
        ];
    }
    $lines = array_values($lines);
    usort($lines, fn($a, $b) => strnatcasecmp($a['shortName'], $b['shortName']));

    return $lines;
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

    add_filter('pre_get_document_title', fn() => $meta['title']);
    add_filter('language_attributes', fn($output) => preg_replace('/lang="[^"]*"/', 'lang="es"', $output));
    add_action('wp_head', fn() => trufi_add_header_tags($meta), 7);
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

function minify_html($input)
{
  return preg_replace(array('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '/\s*(<[^>]+>)\s*/', '/\s\s+/', '/\n/',), array('', '$1', ' ', '',), $input);
}
