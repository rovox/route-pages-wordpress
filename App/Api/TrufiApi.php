<?php

namespace App\Api;

/**
 * Class TrufiApi
 * @package Api
 *
 * This class is used to interact with the Trufi API
 */
class TrufiApi {
    public string $apiUrl;

    public function __construct($apiUrl) {
        $this->apiUrl = $apiUrl;
    }


    /**
     * Query the Trufi API
     *
     * @param string|array $query
     * @param string|array $variables
     *
     * @return array|null
     */
    public function query(string|array $query, string|array $variables = null): array|null {
        if (is_array($query)) {
            $query = json_encode($query);
        }

        if (is_array($variables)) {
            $variables = json_encode($variables);
        }

        $body = [];

        $body['query'] = $query;
        if ($variables) {
            $body['variables'] = $variables;
        }
        $response = wp_remote_post($this->apiUrl,
            ['body'    => json_encode($body),
             'headers' => [
                 'Content-Type' => 'application/json',
                 'Accept'       => 'application/json',
             ],
            ]);

        if (is_wp_error($response)) {
            return null;
        } else {
            $_responseCode = wp_remote_retrieve_response_code($response);
            if ($_responseCode !== 200) {
                throw new \Exception('Received API response ' . $_responseCode);
            }
            $_body = wp_remote_retrieve_body($response);
            return json_decode($_body, true);
        }
    }

    /**
     * Fetch a route from the Trufi API
     *
     * @param string $routeId
     *
     * @return array|null
     */
    public function fetchRoute(string $routeId): array|null {
        $query     = 'query parking($id: String!) { 
                    pattern(id: $id) { 
                        route { id, shortName, longName }, 
                        geometry { lat, lon } 
                    } 
                 }';
        $variables = '{"id":"' . $routeId . '"}';

        return $this->query($query, $variables);
    }

    /**
     * Fetch a list of Route URLs from the Trufi API
     *
     * @return array|null
     */
    public function routeUrlList(): array|null {
        $body = '{
            patterns{
                code, route{ longName }
            }
        }';
        return $this->query($body);
    }
}
