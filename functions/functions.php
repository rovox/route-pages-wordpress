<?php

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
