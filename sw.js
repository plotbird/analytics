/*! PlotBird Analytics - service worker (PWA shell).
 *
 *  Deliberately narrow: it caches static assets only and passes every other
 *  request straight through to the network.
 *
 *  Why no HTML caching: the dashboard shows one customer's numbers, and a
 *  cached page could resurface after somebody signed out on a shared device.
 *  Losing offline HTML costs a browser error page; caching it could leak data.
 *  Assets are safe to cache because their URLs carry a ?v=<mtime> cache buster,
 *  so a new release is a different URL and stale copies simply never match.
 *
 *  /api/, /install/ and /share/ are never touched.
 */

'use strict';

var CACHE = 'plotbird-assets-v1';

self.addEventListener('install', function () {
    // Take over as soon as possible; there is no precache list to warm up.
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
                // Drop caches from older versions of this worker.
                if (key !== CACHE && key.indexOf('plotbird-assets-') === 0) {
                    return caches.delete(key);
                }
                return Promise.resolve(false);
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

function isStaticAsset(url) {
    return url.origin === self.location.origin && url.pathname.indexOf('/assets/') !== -1;
}

function isDynamic(url) {
    return url.pathname.indexOf('/api/') !== -1
        || url.pathname.indexOf('/install/') !== -1
        || url.pathname.indexOf('/share/') !== -1;
}

self.addEventListener('fetch', function (event) {
    var request = event.request;

    // Only plain GETs are cacheable.
    if (request.method !== 'GET') {
        return;
    }

    var url;
    try {
        url = new URL(request.url);
    } catch (e) {
        return;
    }

    if (isDynamic(url) || !isStaticAsset(url)) {
        return; // straight to the network
    }

    event.respondWith(
        caches.open(CACHE).then(function (cache) {
            return cache.match(request).then(function (cached) {
                // Stale-while-revalidate: answer from the cache when possible and
                // refresh it in the background.
                var network = fetch(request).then(function (response) {
                    if (response && response.ok) {
                        cache.put(request, response.clone());
                    }
                    return response;
                }).catch(function () {
                    return cached;
                });

                return cached || network;
            });
        })
    );
});
