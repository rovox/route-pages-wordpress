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
 * Group a flat pattern list into lines (by route shortName), each carrying
 * its patterns (direction/variant) underneath.
 *
 * Grouping is by shortName rather than by operator/"sindicato" because the
 * configured Trufi GraphQL endpoint reports agency "default" for every
 * single route (verified: 471/471 patterns) - there is no real per-operator
 * data to group by.
 *
 * @param array $patterns
 *
 * @return array
 */
function trufi_group_patterns_by_line(array $patterns): array {
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
            'code'     => $pattern['code'],
            'longName' => $pattern['route']['longName'] ?? '',
            'url'      => trufi_pattern_url($pattern['route']['longName'] ?? '', $pattern['code']),
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
 * Split a "{headline}: {from} → {to}" longName (the Trufi API's convention,
 * e.g. "Trufi 119: Villa Taquiña → Loma Pampa") into its headline and
 * from/to subtitle. Falls back to using the whole string as the headline
 * when there's no colon to split on.
 *
 * @param string $longName
 *
 * @return array{headline: string, subtitle: string}
 */
function trufi_split_route_long_name(string $longName): array {
    $parts = explode(':', $longName, 2);

    return [
        'headline' => trim($parts[0] ?? $longName),
        'subtitle' => trim($parts[1] ?? ''),
    ];
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
