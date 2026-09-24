<?php

use App\Api\TrufiApi;

/**
 * Lightweight REST endpoint used by the routes index page to fetch a single
 * pattern's geometry on demand (clicking a route in the sidebar), instead of
 * embedding all patterns' geometry in the initial page load.
 *
 * Reuses the same transient cache key format as the per-route virtual page
 * (see template-redirect.php) so both entry points share one cache entry per
 * pattern and both are cleared by the existing trufi_route% prefix cleanup.
 */
add_action('rest_api_init', function () {
    register_rest_route('trufi/v1', '/pattern/(?P<id>[^/]+)', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'trufi_rest_get_pattern',
        'args'                => [
            'id' => [
                'required'          => true,
                'validate_callback' => fn($param) => is_string($param) && $param !== '',
            ],
        ],
    ]);
});

function trufi_rest_get_pattern(WP_REST_Request $request): WP_REST_Response {
    // Path segments captured by the rewrite are NOT url-decoded by WP. The
    // index page encodes pattern codes (encodeURIComponent) so colons arrive
    // as %3A; decode them or the GraphQL lookup fails with 404.
    $mapId         = rawurldecode($request->get_param('id'));
    $apiUrl        = get_option(TRUFI_API_URL_OPTION);
    $cacheKey      = 'trufi_route_data_' . $mapId;
    $cacheLifetime = get_option(TRUFI_CACHE_TTL_OPTION) * 60 * 60;

    $routeData = get_transient($cacheKey);
    if (false === $routeData) {
        $trufiApi  = new TrufiApi($apiUrl);
        $routeData = $trufiApi->fetchRoute($mapId);
        set_transient($cacheKey, $routeData, $cacheLifetime);
    }

    if (empty($routeData['data']['pattern'])) {
        return new WP_REST_Response(['error' => 'Route not found'], 404);
    }

    return new WP_REST_Response($routeData['data']['pattern'], 200);
}
