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
    $apiUrl        = get_option(TRUFI_API_URL_OPTION);
    $cacheKey      = 'trufi_route_data_' . $mapId;
    $cacheLifetime = get_option(TRUFI_CACHE_TTL_OPTION) * 60 * 60;

    $routeData = get_transient($cacheKey);
    if (false === $routeData) {
        $trufiApi  = new TrufiApi($apiUrl);
        $routeData = $trufiApi->fetchRoute($mapId);
        set_transient($cacheKey, $routeData, $cacheLifetime);
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
    $apiUrl        = get_option(TRUFI_API_URL_OPTION);
    $cacheKey      = 'trufi_routes_index_list';
    $cacheLifetime = get_option(TRUFI_CACHE_TTL_OPTION) * 60 * 60;

    $indexData = get_transient($cacheKey);
    if (false === $indexData) {
        $trufiApi  = new TrufiApi($apiUrl);
        $indexData = $trufiApi->routeIndexList();
        set_transient($cacheKey, $indexData, $cacheLifetime);
    }

    return $indexData;
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
 * The public URL of a single route detail page - the same URL the sitemap
 * provider generates (`App\Providers\TrufiRoutesSitemapProvider::getRouteUrls()`),
 * reimplemented here so the index page's per-route "share" button can link
 * straight to it without a page reload. Keep this in sync with that method
 * if the slug scheme ever changes.
 *
 * @param string $longName
 * @param string $code
 *
 * @return string
 */
function trufi_pattern_url(string $longName, string $code): string {
    $base_path = trufi_get_base_path();
    $slug      = sanitize_title(Utility::replaceArrowWithWord($longName));

    return home_url("/$base_path/$slug/$code");
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

function trufi_add_header_tags($page_title, $page_description, $route_name = '', $map_page_id = 0)
{
  $current_url = esc_url(home_url($_SERVER['REQUEST_URI']));

  echo '<meta name="description" content="' . esc_attr($page_description) . '">';
  echo '<meta name="robots" content="index, follow">';
  echo '<link rel="canonical" href="' . $current_url . '">';
  echo '<meta property="og:title" content="' . esc_attr($page_title) . '">';
  echo '<meta property="og:description" content="' . esc_attr($page_description) . '">';
  echo '<meta property="og:type" content="website">';
  echo '<meta property="og:url" content="' . $current_url . '">';
  echo '<meta name="twitter:card" content="summary_large_image">';
  echo '<meta name="twitter:title" content="' . esc_attr($page_title) . '">';
  echo '<meta name="twitter:description" content="' . esc_attr($page_description) . '">';
  echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />';
  echo '<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>';

  // BreadcrumbList structured data: gives search engines and AI crawlers an
  // explicit, machine-readable page hierarchy (Home > base page > route).
  if ($route_name && $map_page_id) {
    $breadcrumb = [
      '@context'        => 'https://schema.org',
      '@type'           => 'BreadcrumbList',
      'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => get_bloginfo('name'), 'item' => home_url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => get_the_title($map_page_id), 'item' => get_permalink($map_page_id)],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $route_name, 'item' => $current_url],
      ],
    ];
    echo '<script type="application/ld+json">' . wp_json_encode($breadcrumb) . '</script>';
  }
}

function minify_html($input)
{
  return preg_replace(array('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '/\s*(<[^>]+>)\s*/', '/\s\s+/', '/\n/',), array('', '$1', ' ', '',), $input);
}
