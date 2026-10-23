/**
 * Shares (or copies) a URL - used by every "Compartir" button: the route
 * detail page's, each route row's and each sindicato group's in the index
 * list. On touch devices it opens the native share sheet (WhatsApp, etc.);
 * elsewhere it copies the link and shows a toast telling the user what to
 * do with it, so the action is never silent.
 */
window.Trufi = window.Trufi || {};

Trufi.share = {
    copiedMessage: 'Enlace copiado al portapapeles. Pégalo en WhatsApp u otra app para compartirlo.',

    /**
     * @param {string} url
     * @param {string} [title]
     */
    copyOrShare: function (url, title) {
        if (!url) return;

        const isTouch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
        if (navigator.share && isTouch) {
            navigator.share({ title: title || document.title, url: url }).catch(function (err) {
                // AbortError = the user closed the sheet; anything else, fall back to copying.
                if (!err || err.name !== 'AbortError') Trufi.share.copy(url);
            });
            return;
        }

        Trufi.share.copy(url);
    },

    copy: function (url) {
        const done = function () { Trufi.share.toast(Trufi.share.copiedMessage); };
        const failed = function () { Trufi.share.toast('Copia este enlace para compartirlo:', url); };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(done, function () {
                Trufi.share.legacyCopy(url) ? done() : failed();
            });
            return;
        }
        Trufi.share.legacyCopy(url) ? done() : failed();
    },

    /** execCommand fallback for non-secure (plain HTTP) origins, where navigator.clipboard is unavailable. */
    legacyCopy: function (text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(textarea);
        return ok;
    },

    /**
     * Brief status message at the bottom of the screen (one shared element,
     * announced to screen readers). With `url`, it also shows the link in a
     * selectable field for when copying wasn't possible.
     *
     * @param {string} message
     * @param {string} [url]
     */
    toast: function (message, url) {
        let el = document.getElementById('trufi-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'trufi-toast';
            el.className = 'trufi-toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }

        el.textContent = message;
        if (url) {
            const input = document.createElement('input');
            input.type = 'text';
            input.readOnly = true;
            input.value = url;
            input.addEventListener('focus', function () { input.select(); });
            el.appendChild(input);
        }

        el.classList.add('is-visible');
        clearTimeout(el._t);
        el._t = setTimeout(function () { el.classList.remove('is-visible'); }, url ? 8000 : 3500);
    },
};
