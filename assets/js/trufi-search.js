/**
 * Pure filtering logic for the routes index sidebar - kept separate from
 * trufi-index.js (which owns rendering/DOM) so the matching rules can be
 * read/tested on their own.
 */
window.Trufi = window.Trufi || {};

Trufi.search = {
    /**
     * @param {Array} lines Array of {shortName, mode, patterns: [{code, longName}]}
     * @param {{query?: string, mode?: string}} filters
     * @returns {Array} filtered lines
     */
    filterLines: function (lines, filters) {
        const query = (filters.query || '').trim().toLowerCase();
        const mode = filters.mode || '';

        return lines.filter(function (line) {
            if (mode && line.mode !== mode) return false;
            if (!query) return true;
            if (line.shortName.toLowerCase().includes(query)) return true;
            return line.patterns.some(function (p) { return p.longName.toLowerCase().includes(query); });
        });
    },
};
