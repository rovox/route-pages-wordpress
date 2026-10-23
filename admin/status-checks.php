<?php

/**
 * Health checks shown at the top of Trufi Routes → Settings, so a site
 * admin without server access can see whether the plugin's dependencies
 * (API, sindicatos dataset, base page, robots.txt, sitemap, caching) are
 * working. Read-only: nothing here writes options or transients.
 *
 * Each check returns ['label' => ..., 'status' => 'ok'|'warning'|'error', 'detail' => ..., 'hint' => ...].
 */

/**
 * @return array<array{label: string, status: string, detail: string, hint: string}>
 */
function trufi_status_checks(): array {
    return [
        trufi_check_php_version(),
        trufi_check_api(),
        trufi_check_sindicatos(),
        trufi_check_base_page(),
        trufi_check_rewrite_rules(),
        trufi_check_robots(),
        trufi_check_sitemap(),
        trufi_check_stores(),
        trufi_check_page_cache(),
    ];
}

function trufi_status_row(string $label, string $status, string $detail, string $hint = ''): array {
    return ['label' => $label, 'status' => $status, 'detail' => $detail, 'hint' => $hint];
}

function trufi_check_php_version(): array {
    $ok = version_compare(PHP_VERSION, '8.1', '>=');

    return trufi_status_row(
        'PHP ≥ 8.1',
        $ok ? 'ok' : 'error',
        'PHP ' . PHP_VERSION,
        $ok ? '' : 'Pide a tu proveedor de hosting que actualice PHP; el plugin no carga con versiones anteriores.'
    );
}

function trufi_check_api(): array {
    $url    = get_option(TRUFI_API_URL_OPTION);
    $result = null;
    try {
        $result = (new App\Api\TrufiApi((string) $url))->routeIndexList();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    $count = count($result['data']['patterns'] ?? []);
    if ($count > 0) {
        return trufi_status_row('API de Trufi', 'ok', $count . ' rutas recibidas de ' . $url);
    }

    return trufi_status_row(
        'API de Trufi',
        'error',
        $error ?? 'La API respondió sin rutas (' . $url . ')',
        'Revisa la URL en Settings. Si el mensaje habla de certificado SSL, el servidor de la API tiene el certificado vencido.'
    );
}

function trufi_check_sindicatos(): array {
    $url = get_option(TRUFI_SINDICATOS_URL_OPTION, TRUFI_SINDICATOS_DEFAULT_URL);
    if (!$url) {
        return trufi_status_row('Datos de sindicatos', 'warning', 'Desactivado (URL vacía).', 'Sin sindicatos no hay contador, filtro ni agrupación por sindicato.');
    }

    // Count the sindicatos that actually match a line in the API feed - the
    // same number the routes index shows in its hero stat.
    $indexData = trufi_fetch_index_data();
    $lines     = trufi_group_patterns_by_line($indexData['data']['patterns'] ?? []);
    $count     = count(array_unique(array_filter(array_map(fn($line) => $line['sindicato']['slug'] ?? null, $lines))));
    if ($count > 0) {
        return trufi_status_row('Datos de sindicatos', 'ok', $count . ' sindicatos asociados a líneas de la API.');
    }

    return trufi_status_row(
        'Datos de sindicatos',
        'warning',
        'No se pudo leer el conjunto de datos (' . $url . ').',
        'La página funciona igual, sin sindicatos. Revisa la URL o vuelve a intentarlo tras una hora (la respuesta vacía se guarda ese tiempo).'
    );
}

function trufi_check_base_page(): array {
    $page_id = (int) get_option(TRUFI_MAP_PAGE_ID_OPTION);
    $page    = $page_id ? get_post($page_id) : null;
    if (!$page || $page->post_status !== 'publish') {
        return trufi_status_row(
            'Página base de rutas',
            'error',
            'No hay una página publicada seleccionada.',
            'Crea una página (p. ej. "Rutas" con slug rutas), publícala y selecciónala en Base Page for Maps.'
        );
    }

    $permalink = get_permalink($page_id);
    $detail    = $page->post_title . ' → ' . $permalink;
    $hint      = $page->post_content && trim(wp_strip_all_tags($page->post_content)) !== ''
        ? 'La página tiene texto propio: se reemplaza por el índice de rutas al mostrarse.'
        : '';

    return trufi_status_row('Página base de rutas', 'ok', $detail, $hint);
}

function trufi_check_rewrite_rules(): array {
    $rules = (array) get_option('rewrite_rules');
    $base  = trufi_get_base_path();
    $ok    = isset($rules['^' . $base . '/([^/]+)/([^/]+)/?'])
        && isset($rules['^llms\.txt$']);

    return trufi_status_row(
        'Reglas de URL',
        $ok ? 'ok' : 'warning',
        $ok ? 'Rutas /' . $base . '/… y /llms.txt registradas.' : 'Faltan reglas de URL para /' . $base . '/ o /llms.txt.',
        $ok ? '' : 'Pulsa "Guardar" en esta página para regenerarlas (Permalinks → Guardar también sirve).'
    );
}

/**
 * Fetches one of this site's own URLs. When the public URL is localhost
 * (local Docker dev stack) PHP can't reach it from inside the container,
 * so the request goes to 127.0.0.1 with the original Host header.
 *
 * @param string $path e.g. '/robots.txt'
 *
 * @return array|WP_Error
 */
function trufi_fetch_own_url(string $path, int $timeout) {
    $url  = home_url($path);
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
        return wp_remote_get($url, ['timeout' => $timeout]);
    }

    // Inside the container the web server listens on port 80, not on the
    // published host port, so only the Host header keeps the original port.
    $port = wp_parse_url($url, PHP_URL_PORT);

    return wp_remote_get('http://127.0.0.1' . $path, [
        'timeout' => $timeout,
        'headers' => ['Host' => $host . ($port ? ':' . $port : '')],
    ]);
}

function trufi_check_robots(): array {
    $response = trufi_fetch_own_url('/robots.txt', 10);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return trufi_status_row('robots.txt', 'warning', 'No se pudo leer ' . home_url('/robots.txt') . '.', 'Compruébalo en el navegador.');
    }

    global $plugin_url;
    $body        = wp_remote_retrieve_body($response);
    $assets_path = wp_parse_url($plugin_url . 'assets/', PHP_URL_PATH);
    $has_allow   = stripos($body, 'Allow: ' . $assets_path) !== false;
    $has_sitemap = stripos($body, 'Sitemap:') !== false;

    if ($has_allow && $has_sitemap) {
        return trufi_status_row('robots.txt', 'ok', 'Permite los assets del plugin y anuncia el sitemap.');
    }

    $missing = array_filter([
        $has_allow ? null : 'Allow: ' . $assets_path,
        $has_sitemap ? null : 'Sitemap: ' . home_url('/wp-sitemap.xml'),
    ]);

    return trufi_status_row(
        'robots.txt',
        'warning',
        'Faltan líneas: ' . implode(' · ', $missing) . '.',
        'Si ya usas Yoast, Rank Math o All in One SEO, añádelas en su editor de robots.txt. Si el archivo es físico, pídelas a quien administre el hosting. Google no podrá cargar el mapa y la lista si el plugin está bloqueado.'
    );
}

function trufi_check_sitemap(): array {
    $url      = home_url('/wp-sitemap-routes-1.xml');
    $response = trufi_fetch_own_url('/wp-sitemap-routes-1.xml', 30);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return trufi_status_row(
            'Sitemap de rutas',
            'error',
            'No responde 200 (' . $url . ').',
            'Revisa la API y los permalinks (guarda esta página). Un 404 aquí significa que no hay rutas que listar.'
        );
    }

    $count = substr_count(wp_remote_retrieve_body($response), '<loc>');

    return trufi_status_row('Sitemap de rutas', $count > 0 ? 'ok' : 'error', $count . ' URLs en ' . $url, $count > 0 ? '' : 'El sitemap está vacío: la API no devolvió rutas.');
}

function trufi_check_stores(): array {
    $stores = [
        'Google Play' => [get_option(TRUFI_GOOGLE_PLAY_URL_OPTION), get_option(TRUFI_GOOGLE_PLAY_IMAGE_OPTION)],
        'App Store'   => [get_option(TRUFI_APPLE_STORE_URL_OPTION), get_option(TRUFI_APPLE_STORE_IMAGE_OPTION)],
    ];
    $parts = [];
    foreach ($stores as $name => [$url, $image]) {
        $parts[] = $name . ': ' . ($url ? ($image ? 'enlace e imagen' : 'enlace con texto') : 'sin configurar');
    }
    $any = array_filter(array_map(fn($store) => $store[0], $stores));

    return trufi_status_row(
        'Botones de tiendas',
        $any ? 'ok' : 'warning',
        implode(' · ', $parts),
        $any ? '' : 'Sin enlaces, la barra de descarga no se muestra en las rutas.'
    );
}

function trufi_check_page_cache(): array {
    $plugins = [
        'wp-rocket/wp-rocket.php'                 => 'WP Rocket',
        'litespeed-cache/litespeed-cache.php'     => 'LiteSpeed Cache',
        'w3-total-cache/w3-total-cache.php'       => 'W3 Total Cache',
        'wp-super-cache/wp-cache.php'             => 'WP Super Cache',
        'wp-fastest-cache/wpFastestCache.php'     => 'WP Fastest Cache',
        'sg-cachepress/sg-cachepress.php'         => 'SiteGround Optimizer',
    ];
    $found = array_values(array_intersect_key($plugins, array_flip(array_filter(array_keys($plugins), 'is_plugin_active'))));
    if (defined('WP_CACHE') && WP_CACHE) {
        $found[] = 'WP_CACHE activo';
    }

    if (!$found) {
        return trufi_status_row('Caché de página', 'ok', 'No se detectó un plugin de caché.');
    }

    return trufi_status_row(
        'Caché de página',
        'warning',
        'Detectado: ' . implode(', ', $found) . '.',
        'Después de cada cambio purga la caché desde ese plugin (o desde el panel de tu hosting), o los usuarios verán la versión anterior.'
    );
}
