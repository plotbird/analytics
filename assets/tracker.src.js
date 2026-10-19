/*! PlotBird Analytics tracker - cookieless, dependency free, under 2 KB.
 *  Reads its own script tag for data-website-id and reports page views.
 *  JSON is sent as text/plain: that keeps the request CORS-safelisted (no
 *  preflight) and needs no cookies, storage or fingerprinting.
 *
 *  Custom events (goal conversions):
 *      plotbird.track('signup');
 *
 *  If a call can happen before this script finishes loading, queue it first:
 *      window.plotbird = window.plotbird || { q: [] };
 *      window.plotbird.track = function (n) { plotbird.q.push(n); };
 *  The queue is flushed as soon as the tracker starts. */
(function () {
    'use strict';

    var scripts = document.getElementsByTagName('script');
    var tag = document.currentScript || scripts[scripts.length - 1];
    if (!tag) return;

    var id = tag.getAttribute('data-website-id');
    if (!id) return;

    // The tracker is served by the analytics installation itself, so its own
    // URL reveals where to post - sub-directory installs included.
    var ep = tag.getAttribute('data-endpoint') ||
        (tag.src || '').replace(/\/assets\/tracker\.js.*$/, '') + '/api/collect.php';

    var last = '';

    function body(eventName) {
        var w = window.screen ? screen.width : 0;
        var h = window.screen ? screen.height : 0;
        return JSON.stringify({
            w: id,
            p: location.pathname + location.search,
            e: eventName || '',
            r: document.referrer || '',
            t: document.title || '',
            s: w + 'x' + h,
            l: navigator.language || '',
            dnt: (navigator.doNotTrack || window.doNotTrack) === '1' ? '1' : '0'
        });
    }

    function post(payload) {
        if (navigator.sendBeacon) {
            try { if (navigator.sendBeacon(ep, payload)) return; } catch (e) { /* use fetch */ }
        }
        try {
            fetch(ep, {
                method: 'POST',
                body: payload,
                headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
                mode: 'cors',
                credentials: 'omit',
                keepalive: true
            });
        } catch (e) { /* nothing else to do */ }
    }

    function pageview() {
        var p = location.pathname + location.search;
        if (p === last) return; // ignore duplicate history events
        last = p;
        post(body(''));
    }

    function track(name) {
        if (typeof name !== 'string') return;
        // Keep the name to the same character set the server accepts.
        name = name.replace(/[^A-Za-z0-9_.:-]/g, '').slice(0, 120);
        if (!name) return;
        post(body(name));
    }

    window.plotbird = window.plotbird || {};
    window.plotbird.track = track;

    // Flush events queued by a stub before this script was ready.
    if (window.plotbird.q && window.plotbird.q.length) {
        var queued = window.plotbird.q;
        window.plotbird.q = [];
        for (var i = 0; i < queued.length; i++) track(queued[i]);
    }

    // Single page applications: report every client side navigation.
    ['pushState', 'replaceState'].forEach(function (m) {
        var original = history[m];
        if (typeof original !== 'function') return;
        history[m] = function () {
            var result = original.apply(this, arguments);
            setTimeout(pageview, 0);
            return result;
        };
    });
    addEventListener('popstate', function () { setTimeout(pageview, 0); });

    if (document.readyState === 'complete') pageview();
    else addEventListener('load', pageview);
})();
