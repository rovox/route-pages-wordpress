<?php

/**
 * Serves /llms.txt (https://llmstxt.org): a plain-markdown summary of the
 * routes for AI crawlers and answer engines - what the site is, where the
 * data comes from, and every line grouped by operator ("sindicato") with a
 * link to each route page. Built from the same cached data as the routes
 * index (trufi_fetch_index_data() + trufi_group_patterns_by_line()).
 *
 * Routed by the `^llms\.txt$` rewrite rule (rewrite-rules.php). A physical
 * llms.txt file in the site root would take precedence over this.
 */
add_action('template_redirect', function () {
    if (!get_query_var('trufi_llms')) {
        return;
    }

    $map_page_id = (int) get_option(TRUFI_MAP_PAGE_ID_OPTION);
    $indexData   = trufi_fetch_index_data();
    $lines       = trufi_group_patterns_by_line($indexData['data']['patterns'] ?? []);

    status_header(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo trufi_llms_txt($lines, $map_page_id ? get_permalink($map_page_id) : home_url('/'));
    exit;
}, 1);

/**
 * @param array  $lines     trufi_group_patterns_by_line().
 * @param string $index_url
 *
 * @return string
 */
function trufi_llms_txt(array $lines, string $index_url): string {
    $site         = get_bloginfo('name');
    $route_count  = array_sum(array_map(fn($line) => count($line['patterns']), $lines));
    $sindicato_of = fn($line) => $line['sindicato']['name'] ?? 'Otras líneas';

    $out  = "# {$site} - Rutas de transporte público de Cochabamba\n\n";
    $out .= "> Recorridos de " . count($lines) . " líneas y {$route_count} rutas de micros, trufis y taxi trufis en Cochabamba, Bolivia: "
        . "origen, destino, paradas (nombres de calles) y mapa de cada ruta.\n\n";
    $out .= "Cada ruta tiene su propia página con el trazado en el mapa, la lista de paradas, la distancia y el sindicato que la opera. "
        . "Los recorridos vienen de Trufi (OpenTripPlanner / GTFS, mapeados en OpenStreetMap); los sindicatos y orígenes, de un conjunto de datos abierto derivado de OpenStreetMap.\n\n";
    $out .= "## Índice\n\n";
    $out .= "- [Todas las rutas]({$index_url}): buscador por línea, origen, destino o sindicato, con mapa.\n\n";
    $out .= "## Rutas por sindicato\n";

    $groups = [];
    foreach ($lines as $line) {
        $groups[$sindicato_of($line)][] = $line;
    }
    uksort($groups, function ($a, $b) {
        if ($a === 'Otras líneas' || $b === 'Otras líneas') {
            return $a === 'Otras líneas' ? 1 : -1;
        }

        return strcmp(strtolower(remove_accents($a)), strtolower(remove_accents($b)));
    });

    foreach ($groups as $name => $group_lines) {
        $out .= "\n### {$name}\n\n";
        foreach ($group_lines as $line) {
            foreach ($line['patterns'] as $pattern) {
                $out .= '- [' . $pattern['longName'] . ': ' . trufi_pattern_row_label($pattern) . '](' . $pattern['url'] . ")\n";
            }
        }
    }

    return $out;
}
