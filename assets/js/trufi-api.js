/**
 * Thin wrapper around the plugin's REST endpoint (functions/rest-api.php)
 * that fetches a single pattern's full data (geometry + stops) on demand -
 * used by the index page when a route is selected in the sidebar.
 */
window.Trufi = window.Trufi || {};

Trufi.api = {
    _cache: {},

    /**
     * @param {string} restUrlBase Trailing-slash REST base, e.g. ".../trufi/v1/pattern/"
     * @param {string} code Pattern code
     * @returns {Promise<Object>}
     */
    fetchPattern: function (restUrlBase, code) {
        if (Trufi.api._cache[code]) {
            return Promise.resolve(Trufi.api._cache[code]);
        }
        return fetch(restUrlBase + encodeURIComponent(code))
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data.geometry) throw new Error('Sin geometría');
                Trufi.api._cache[code] = data;
                return data;
            });
    },
};
