/**
 * Shares (or copies) a URL - the route detail page's share button and each
 * route row's share icon in the index list both use this. Prefers the Web
 * Share API (mobile browsers), falls back to clipboard copy with a brief
 * `.is-copied` visual acknowledgement on the trigger button.
 */
window.Trufi = window.Trufi || {};

Trufi.share = {
    /**
     * @param {string} url
     * @param {string} title
     * @param {HTMLElement} [btnEl] Gets `.is-copied` toggled briefly on clipboard fallback.
     */
    copyOrShare: function (url, title, btnEl) {
        if (!url) return;

        if (navigator.share) {
            navigator.share({ title: title || document.title, url: url }).catch(function () {});
            return;
        }

        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () {
                if (!btnEl) return;
                btnEl.classList.add('is-copied');
                clearTimeout(btnEl._shareT);
                btnEl._shareT = setTimeout(function () { btnEl.classList.remove('is-copied'); }, 1500);
            });
        }
    },
};
