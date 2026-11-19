<?php
/**
 * Pure-logic tests. Run inside WordPress:
 *   wp eval-file wp-content/plugins/<plugin>/tests/logic.php
 * Exits non-zero on failure.
 */
$fail = 0;
function t(string $name, $got, $want): void {
    global $fail;
    if ($got === $want) { echo "ok   $name\n"; return; }
    $fail++;
    echo "FAIL $name\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n";
}

$p = fn($code, $short, $long, $mode, $agency, $gtfs) => [
    'code' => $code, 'headsign' => 'Destino',
    'route' => ['gtfsId' => $gtfs, 'shortName' => $short, 'longName' => $long, 'mode' => $mode, 'agency' => ['name' => $agency]],
];
$lines = trufi_group_patterns_by_line([
    $p('c:1', 'M', 'Trufi M', 'BUS', 'Sindicato A', 'g:1'),
    $p('c:2', 'M', 'MicroBus M', 'BUS', 'Sindicato B', 'g:2'),
    $p('c:3', 'M', 'Trufi M', 'BUS', 'Sindicato A', 'g:1'),
    $p('c:4', '103', 'Trufi 103', 'BUS', 'Linea 103', 'g:3'),
]);
t('same shortName, different route ids stay separate lines', count($lines), 3);
$titles = array_column($lines, 'title');
t('titles come from feed longName', in_array('Trufi M', $titles) && in_array('MicroBus M', $titles), true);
$m = array_values(array_filter($lines, fn($l) => $l['title'] === 'Trufi M'))[0];
t('patterns of one route are grouped', count($m['patterns']), 2);

$r103 = ['shortName' => '103', 'longName' => 'Trufi 103'];
t('line-like agency name rejected', trufi_is_line_like_name('Linea 103', $r103), true);
t('real sindicato accepted', trufi_is_line_like_name('Sindicato mixto de transporte Bolivia', $r103), false);
t('"Linea 103" agency falls back to dataset',
    trufi_line_sindicato($r103 + ['agency' => ['name' => 'Linea 103']], ['byName' => ['Trufi 103' => ['name' => 'Sindicato mixto de transporte Bolivia', 'slug' => 'x']]])['name'],
    'Sindicato mixto de transporte Bolivia');
t('agency "default" ignored', trufi_line_sindicato($r103 + ['agency' => ['name' => 'default']], []), null);
t('agency name used', trufi_line_sindicato($r103 + ['agency' => ['name' => 'Sindicato Real']], [])['name'], 'Sindicato Real');

// Lines without a usable sindicato stay unassigned (they form the "Sin sindicato identificado" group).
$unassigned = trufi_group_patterns_by_line([
    $p('c:5', 'Cotapachi', 'Trufi Cotapachi', 'BUS', 'Cotapachi', 'g:5'),
    $p('c:6', '25', 'Trufi 25', 'BUS', 'default', 'g:6'),
    $p('c:7', 'M', 'Trufi M', 'BUS', 'Sindicato A', 'g:1'),
]);
$none = array_values(array_filter($unassigned, fn($l) => empty($l['sindicato']['slug'])));
$short = array_column($none, 'shortName');
sort($short);
t('agency named like the line is unassigned', $short, ['25', 'Cotapachi']);
t('unassigned count excludes real sindicatos', count($none), 2);

t('title: leading generic Bus dropped', trufi_line_title(['longName' => 'Bus Colomi', 'shortName' => 'C']), 'Colomi');
t('title: no vehicle word invented', trufi_line_title(['longName' => 'Trufi M', 'shortName' => 'M']), 'Trufi M');
t('title: shortName when no longName', trufi_line_title(['longName' => '', 'shortName' => 'M']), 'M');

$l = trufi_pattern_labels('Trufi 1', 'Reducto', ['origins' => []], 'La Tamborada');
t('sentence with origin', $l['sentence'], 'Parte desde La Tamborada hacia el destino Reducto');
t('subtitle uses "hacia", not arrow', $l['subtitle'], 'La Tamborada hacia Reducto');
t('Innominada origin ignored', trufi_pattern_labels('T', 'Reducto', ['origins' => []], 'Innominada')['sentence'], 'Va hacia el destino Reducto');

t('minify keeps spaces between inline tags', minify_html("<p>a <strong>b</strong> <span>c</span>\n  <b>d</b></p>"), '<p>a <strong>b</strong> <span>c</span><b>d</b></p>');

exit($fail ? 1 : 0);
