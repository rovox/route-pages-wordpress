<?php

define('TRUFI_API_URL_OPTION', 'trufi_api_url');
define('TRUFI_SITE_DESCRIPTION_OPTION', 'trufi_site_description');
define('TRUFI_LINE_COLOR_OPTION', 'trufi_line_color');
define('TRUFI_LINE_WEIGHT_OPTION', 'trufi_line_weight');
define('TRUFI_GOOGLE_PLAY_URL_OPTION', 'trufi_google_play_url');
define('TRUFI_APPLE_STORE_URL_OPTION', 'trufi_apple_store_url');
define('TRUFI_GOOGLE_PLAY_IMAGE_OPTION', 'trufi_google_play_image');
define('TRUFI_APPLE_STORE_IMAGE_OPTION', 'trufi_apple_store_image');
define('TRUFI_CACHE_TTL_OPTION', 'trufi_cache_ttl');
define('TRUFI_MAP_PAGE_ID_OPTION', 'trufi_map_page_id');
define('TRUFI_MAP_CENTER_LAT_OPTION', 'trufi_map_center_lat');
define('TRUFI_MAP_CENTER_LNG_OPTION', 'trufi_map_center_lng');
define('TRUFI_MAP_ZOOM_OPTION', 'trufi_map_zoom');
define('TRUFI_SHOW_LOCATION_OPTION', 'trufi_show_location');
define('TRUFI_SINDICATOS_URL_OPTION', 'trufi_sindicatos_url');
// Base paths of previously used "Base Page for Maps" pages; route URLs under
// them 301 to the current base (see functions/rewrite-rules.php).
define('TRUFI_LEGACY_BASE_PATHS_OPTION', 'trufi_legacy_base_paths');
// Open OSM-derived dataset of Cochabamba's transport operators ("sindicatos"),
// used to fill in what the Trufi feed lacks (every route's agency is "default").
define('TRUFI_SINDICATOS_DEFAULT_URL', 'https://ismadavidaa.github.io/sindicatos-viewer/dashboard-data.json');
