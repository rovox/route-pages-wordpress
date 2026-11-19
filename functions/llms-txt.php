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
    $sindicato_of = fn($line) => $line['sindicato']['name'] ?? 'Sin sindicato identificado';

    $out  = "# {$site} - Rutas de transporte público de Cochabamba\n\n";
    $out .= "> Recorridos de " . count($lines) . " líneas y {$route_count} rutas de micros, trufis y taxi trufis en Cochabamba, Bolivia: "
        . "origen, destino, paradas (nombres de calles) y mapa de cada ruta.\n\n";
    $out .= "Es un servicio complementario para encontrar rápido las líneas registradas, el sindicato al que pertenece cada una y su trayecto completo en el mapa. "
        . "Cada ruta tiene su propia página con el trazado, la lista ordenada de paradas (nombres de calles e intersecciones por donde pasa), la distancia y el sindicato. "
        . "Los recorridos vienen de Trufi (GTFS / OpenTripPlanner, mapeados por voluntarios en OpenStreetMap) y el sindicato de cada línea es el operador (agency) registrado en ese GTFS. "
        . "No se garantiza que las líneas operen las 24 horas ni todos los días: aquí no se publican horarios ni frecuencias.\n\n";
    $out .= "## Índice\n\n";
    $out .= "- [Todas las rutas]({$index_url}): buscador por nombre de línea (por ejemplo \"M\" o \"103\"), filtro por sindicato y mapa.\n\n";
    $out .= "## Preguntas frecuentes\n\n";
    foreach (trufi_index_faq(count($lines), $route_count) as $item) {
        $out .= "**{$item['q']}**\n{$item['a']}\n\n";
    }
    $out .= "## Rutas por sindicato\n";

    $groups = [];
    foreach ($lines as $line) {
        $groups[$sindicato_of($line)][] = $line;
    }
    uksort($groups, function ($a, $b) {
        if ($a === 'Sin sindicato identificado' || $b === 'Sin sindicato identificado') {
            return $a === 'Sin sindicato identificado' ? 1 : -1;
        }

        return strcmp(strtolower(remove_accents($a)), strtolower(remove_accents($b)));
    });

    foreach ($groups as $name => $group_lines) {
        $out .= "\n### {$name}\n\n";
        foreach ($group_lines as $line) {
            foreach ($line['patterns'] as $pattern) {
                $out .= '- [' . $line['title'] . ': ' . trufi_pattern_row_label($pattern) . '](' . $pattern['url'] . ")\n";
            }
        }
    }

    return $out;
}
