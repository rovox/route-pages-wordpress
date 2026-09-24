<?php
namespace App\Providers;

use App\Api\TrufiApi;
use App\Utility;
use WP_Sitemaps_Provider;

class TrufiRoutesSitemapProvider extends WP_Sitemaps_Provider {
    private   string $apiUrl;
    private   int $cache_lifetime_hours;
    private   string $cache_key;
    protected $name;

    private string $base_path;

    public function __construct($apiUrl) {
        $this->apiUrl               = $apiUrl;
        $this->name                 = 'routes';
        $this->object_type          = 'routes';
        $this->cache_lifetime_hours = get_option(TRUFI_CACHE_TTL_OPTION) * 60 * 60;
        $this->cache_key = 'trufi_routes_sitemap_xml';
        $this->base_path = trufi_get_base_path();
    }

    public function get_name(): string {
        return $this->name;
    }

    public function get_max_num_pages($object_subtype = ''): int {
        $max_urls = wp_sitemaps_get_max_urls($this->object_type);
        return (int) max(1, ceil(count($this->getCachedRouteUrls()) / $max_urls));
    }

    public function get_url_list($page_num, $object_subtype = ''): array {
        $max_urls = wp_sitemaps_get_max_urls($this->object_type);
        $offset   = ($page_num - 1) * $max_urls;

        return array_slice($this->getCachedRouteUrls(), $offset, $max_urls);
    }

    /**
     * Fetch the full route URL list, using the transient cache when available.
     *
     * @return array
     */
    private function getCachedRouteUrls(): array {
        $url_list = get_transient($this->cache_key);
        if (false === $url_list) {
            $url_list = $this->getRouteUrls();
            set_transient($this->cache_key, $url_list, $this->cache_lifetime_hours);
        }

        return $url_list;
    }

    /**
     * Fetch route urls from Trufi API and format for sitemap
     *
     * @return array
     */
    private function getRouteUrls(): array {
        $trufiApi = new TrufiApi($this->apiUrl);
        $data     = $trufiApi->routeUrlList();

        $url_list = [];
        foreach ($data['data']['patterns'] as $pattern) {
            $routeCode  = $pattern['code'];
            $routeName  = Utility::replaceArrowWithWord($pattern['route']['longName']);
            $routeName  = sanitize_title($routeName);
            $url_list[] = ['loc' => home_url("/$this->base_path/$routeName/$routeCode")];
        }

        return $url_list;
    }
}
