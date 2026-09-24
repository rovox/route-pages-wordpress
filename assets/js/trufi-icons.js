/**
 * Small inline icon set (lucide-style: 24x24, stroke-based) shared by both
 * pages. Usage: `<span class="trufi-icon" data-icon="search"></span>` -
 * mount() fills every such element with the matching SVG on DOMContentLoaded.
 * Kept as plain strings (no build step, see AGENTS.md) rather than <symbol>
 * sprites so each page only pays for the icons it actually references.
 */
window.Trufi = window.Trufi || {};

Trufi.icons = {
    route: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="19" r="3"></circle><circle cx="18" cy="5" r="3"></circle><path d="M9 19h6a4 4 0 0 0 4-4V9a4 4 0 0 0-4-4H9"></path></svg>',
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
    filterX: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h13"></path><path d="M3 9h9"></path><path d="M3 14h6"></path><path d="M15 4l6 6"></path><path d="M21 4l-6 6"></path></svg>',
    bus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17h16V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v11Z"></path><path d="M4 11h16"></path><path d="M7 17v2"></path><path d="M17 17v2"></path><circle cx="7.5" cy="17.5" r="0.6" fill="currentColor" stroke="none"></circle><circle cx="16.5" cy="17.5" r="0.6" fill="currentColor" stroke="none"></circle></svg>',
    share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.6" y1="10.6" x2="15.4" y2="6.4"></line><line x1="8.6" y1="13.4" x2="15.4" y2="17.6"></line></svg>',
    chevronDown: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"></path></svg>',
    arrowLeft: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>',
    locate: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><line x1="12" y1="2" x2="12" y2="5"></line><line x1="12" y1="19" x2="12" y2="22"></line><line x1="2" y1="12" x2="5" y2="12"></line><line x1="19" y1="12" x2="22" y2="12"></line></svg>',
    pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>',
};

/**
 * A solid, colored map-pin marker (teardrop + white center dot), sized for
 * use as a Leaflet L.divIcon - e.g. the destination marker on a route map.
 * Not in the `icons` map above because it needs a fill color baked in
 * rather than inheriting `currentColor` from CSS.
 *
 * @param {string} color
 * @returns {string} SVG markup, viewBox "0 0 24 34", tip at the bottom-center
 */
Trufi.icons.pinMarkerSvg = function (color) {
    return '<svg viewBox="0 0 24 34" width="26" height="34">'
        + '<path fill="' + color + '" stroke="#fff" stroke-width="1.5" d="M12 0C5.4 0 0 5.4 0 12c0 9 12 22 12 22s12-13 12-22C24 5.4 18.6 0 12 0Z"></path>'
        + '<circle cx="12" cy="12" r="4.5" fill="#fff"></circle>'
        + '</svg>';
};

Trufi.icons.mount = function (root) {
    (root || document).querySelectorAll('[data-icon]').forEach(function (el) {
        const svg = Trufi.icons[el.getAttribute('data-icon')];
        if (svg) {
            el.innerHTML = svg;
        }
    });
};

document.addEventListener('DOMContentLoaded', function () {
    Trufi.icons.mount(document);
});
