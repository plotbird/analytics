/* ==========================================================================
   PlotBird Analytics - application JavaScript
   Alpine.js components shared by the dashboard, the share pages and every
   form. Loaded before Alpine (Alpine is deferred), so the component factories
   already exist when Alpine initialises the page.
   ========================================================================== */

(function () {
    'use strict';

    var PB = window.PB || {};

    /* ---------------------------------------------------------------- utils */

    function fmtNumber(value) {
        var n = Number(value || 0);
        var sep = PB.thousandsSeparator || ',';
        var dec = PB.decimalSeparator || '.';
        var parts = n.toFixed(0).split('');
        var out = '';
        var count = 0;
        for (var i = parts.length - 1; i >= 0; i--) {
            out = parts[i] + out;
            count++;
            if (count % 3 === 0 && i > 0) {
                out = sep + out;
            }
        }
        return out.split('.').join(dec);
    }

    function fmtPercent(ratio, decimals) {
        var value = Number(ratio || 0) * 100;
        return value.toFixed(decimals === undefined ? 0 : decimals) + '%';
    }

    function fmtDuration(seconds) {
        var s = Math.round(Number(seconds || 0));
        if (s <= 0) return '0s';
        if (s < 60) return s + 's';
        var m = Math.floor(s / 60);
        var rest = s % 60;
        if (m < 60) return m + 'm ' + String(rest).padStart(2, '0') + 's';
        var h = Math.floor(m / 60);
        return h + 'h ' + String(m % 60).padStart(2, '0') + 'm';
    }

    function post(url, data) {
        var body = new FormData();
        Object.keys(data || {}).forEach(function (key) {
            body.append(key, data[key]);
        });
        return fetch(url, {
            method: 'POST',
            body: body,
            headers: { 'X-CSRF-Token': PB.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () { return {}; });
        });
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var args = arguments;
            var self = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(self, args); }, wait);
        };
    }

    window.pbUtils = {
        fmtNumber: fmtNumber,
        fmtPercent: fmtPercent,
        fmtDuration: fmtDuration,
        post: post,
        debounce: debounce
    };

    /* ----------------------------------------------------------- app shell */

    window.pbShell = function () {
        return {
            dark: document.documentElement.classList.contains('dark'),
            deferredPrompt: null,
            canInstall: false,

            init: function () {
                var self = this;

                // PWA: keep the install prompt for the button in the user menu.
                window.addEventListener('beforeinstallprompt', function (event) {
                    event.preventDefault();
                    self.deferredPrompt = event;
                    self.canInstall = true;
                });
                window.addEventListener('appinstalled', function () {
                    self.deferredPrompt = null;
                    self.canInstall = false;
                });

                // The service worker only caches static assets (see /sw.js).
                if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
                    var swUrl = (PB.endpoints && PB.endpoints.serviceWorker) ? PB.endpoints.serviceWorker : null;
                    if (swUrl) {
                        window.addEventListener('load', function () {
                            navigator.serviceWorker.register(swUrl).catch(function () {
                                // A failed registration must never break the app.
                            });
                        });
                    }
                }
            },

            /** Show the browser's own "install app" dialog. */
            installApp: function () {
                if (!this.deferredPrompt) {
                    return;
                }
                this.deferredPrompt.prompt();
                var self = this;
                this.deferredPrompt.userChoice.then(function () {
                    self.deferredPrompt = null;
                    self.canInstall = false;
                }).catch(function () { /* dismissed */ });
            },

            toggleTheme: function () {
                this.dark = !this.dark;
                document.documentElement.classList.toggle('dark', this.dark);
                try {
                    localStorage.setItem('pb-theme', this.dark ? 'dark' : 'light');
                } catch (e) { /* private mode */ }
                window.dispatchEvent(new CustomEvent('pb:theme-change', { detail: { dark: this.dark } }));
            },

            /**
             * Site switcher: when a dashboard is mounted the change happens over
             * AJAX; otherwise the link navigates normally (and works without JS).
             */
            switchSite: function (event, id) {
                if (window.pbDashboardMounted) {
                    event.preventDefault();
                    window.dispatchEvent(new CustomEvent('pb:switch-site', { detail: { id: id } }));
                }
            },

            fmtNumber: fmtNumber,
            fmtPercent: fmtPercent,
            fmtDuration: fmtDuration
        };
    };

    /* ----------------------------------------------------------- dashboard */

    window.pbDashboard = function (initial, options) {
        options = options || {};

        return {
            payload: initial || {},
            siteId: (initial && initial.site) ? initial.site.id : 0,
            preset: (initial && initial.period) ? initial.period.preset : 'last7',
            from: (initial && initial.period) ? initial.period.from : '',
            to: (initial && initial.period) ? initial.period.to : '',
            loading: false,
            error: '',
            layout: [],
            readonly: !!options.readonly,
            storageKey: 'pb-widgets-' + ((initial && initial.site) ? initial.site.id : 0),
            chart: null,
            map: null,
            sortable: null,
            realtimeTimer: null,

            /* Visitor log state */
            visitsLimit: Number((options && options.visitsLimit) || 50),
            openVisit: 0,
            visitPages: [],
            visitLoading: false,

            /* ------------------------------------------------------ lifecycle */

            init: function () {
                this.layout = this.loadLayout();
                this.applyLayoutOrder();
                this.$nextTick(function () {
                    this.initSortable();
                    this.renderChart();
                    this.renderMap();
                }.bind(this));

                window.pbDashboardMounted = true;

                window.addEventListener('pb:switch-site', function (event) {
                    if (event.detail && event.detail.id) {
                        this.siteId = event.detail.id;
                        this.reload();
                    }
                }.bind(this));

                // The map is sized from its container, so it has to be told when
                // that container changes: after a window resize, and when the
                // countries tab brings it back from display:none.
                this.mapResizeTimer = null;
                window.addEventListener('resize', this.scheduleMapResize.bind(this));
                window.addEventListener('pb:map-resize', this.scheduleMapResize.bind(this));
                if (!this.readonly && options.realtime !== false) {
                    this.realtimeTimer = setInterval(this.refreshRealtime.bind(this), 15000);
                }
            },

            destroy: function () {
                if (this.realtimeTimer) clearInterval(this.realtimeTimer);
                if (this.sortable) this.sortable.destroy();
                if (this.chart) this.chart.destroy();
            },

            /* ---------------------------------------------------------- data */

            reload: function () {
                var self = this;
                this.loading = true;
                this.error = '';

                var params = new URLSearchParams({
                    site: this.siteId,
                    preset: this.preset,
                    visits: this.visitsLimit
                });
                if (this.preset === 'custom') {
                    params.set('from', this.from);
                    params.set('to', this.to);
                }
                if (this.readonly && options.shareToken) {
                    params.set('share', options.shareToken);
                }

                var url = (PB.endpoints && PB.endpoints.stats ? PB.endpoints.stats : 'api/stats.php') + '?' + params.toString();

                return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data || data.error) {
                            self.error = (data && data.error) ? data.error : 'error';
                            return;
                        }
                        self.payload = data;
                        self.preset = data.period.preset;
                        self.from = data.period.from;
                        self.to = data.period.to;
                        self.updateUrl();
                        self.$nextTick(function () {
                            self.renderChart();
                            self.renderMap();
                        });
                    })
                    .catch(function () { self.error = 'network'; })
                    .then(function () { self.loading = false; });
            },

            /**
             * The URL of a coloured brand mark, or null when there is none.
             */
            brandIconUrl: function (value) {
                var key     = String(value || '').toLowerCase();
                var aliases = window.PB_BRAND_ALIASES || {};
                var files   = window.PB_BRAND_FILES || {};
                var slug    = files[aliases[key] || key];

                if (!slug) {
                    return null;
                }

                var base = (window.PB && PB.basePath) ? PB.basePath : '';

                return base + '/assets/icons/brand/' + slug + '.svg';
            },

            /**
             * Interface glyphs for the device list: a monitor, a phone and a tablet.
             * They are not brands, so they follow the text colour.
             *
             * @return {string[]}
             */
            deviceGlyph: function (value) {
                var glyphs = window.PB_DEVICE_GLYPHS || {};

                return glyphs[String(value || '').toLowerCase()] || [];
            },

            /**
             * The monogram fallback for a value with no mark at all.
             */
            monogram: function (value) {
                var key = String(value || '').toLowerCase();
                var map = {
                    'windows phone': ['WP', '#0078d4'],
                    'chrome os': ['OS', '#4285f4'],
                    kaios: ['K', '#7c3aed'],
                    freebsd: ['B', '#ab2b28'],
                    edge: ['E', '#0078d4'],
                    yandex: ['Y', '#fc3f1d'],
                    'uc browser': ['UC', '#ff6a00'],
                    duckduckgo: ['D', '#de5833'],
                    'internet explorer': ['IE', '#0078d4'],
                    curl: ['$', '#64748b'],
                    wget: ['W', '#64748b']
                };

                var hit = map[key] || [(key.charAt(0) || '?').toUpperCase(), '#94a3b8'];

                return { label: hit[0], color: hit[1] };
            },
            /**
             * The drill-down URL for one value, carrying the current site and
             * period so the page opens with the same frame of reference.
             */
            detailUrl: function (dimension, value) {
                return this.stateUrl((options.urls && options.urls.details) || 'details', dimension, value);
            },

            /** The export URL for the current site and period. */
            exportUrl: function () {
                return this.stateUrl((options.urls && options.urls.export) || 'export', '', '');
            },

            /**
             * Append the current state to a base URL.
             */
            stateUrl: function (base, dimension, value) {
                // A share link has no drill-down page and no export.
                if (!base) {
                    return null;
                }

                var url = base;

                var params = new URLSearchParams({
                    site: this.siteId,
                    preset: this.preset
                });

                if (this.preset === 'custom') {
                    params.set('from', this.from);
                    params.set('to', this.to);
                }

                if (dimension && value !== '') {
                    params.set('dimension', dimension);
                    params.set('value', value);
                }

                return url + (url.indexOf('?') === -1 ? '?' : '&') + params.toString();
            },
            setPeriod: function (preset, from, to) {
                this.preset = preset;
                if (from) this.from = from;
                if (to) this.to = to;
                this.reload();
            },

            refreshRealtime: function () {
                if (this.readonly) return;
                var url = (PB.endpoints && PB.endpoints.stats ? PB.endpoints.stats : 'api/stats.php')
                    + '?only=realtime&site=' + encodeURIComponent(this.siteId);

                fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.realtime) {
                            this.payload.realtime = data.realtime;
                        }
                    }.bind(this))
                    .catch(function () { /* a missed poll is not worth a message */ });
            },

            updateUrl: function () {
                try {
                    var params = new URLSearchParams(window.location.search);
                    params.set('site', this.siteId);
                    params.set('preset', this.preset);
                    if (this.preset === 'custom') {
                        params.set('from', this.from);
                        params.set('to', this.to);
                    } else {
                        params.delete('from');
                        params.delete('to');
                    }
                    window.history.replaceState({}, '', window.location.pathname + '?' + params.toString());
                } catch (e) { /* older browsers simply keep the old URL */ }
            },

            /* -------------------------------------------------------- layout */

            /** Formatting helpers exposed to templates. */
            fmtNumber: fmtNumber,
            fmtPercent: fmtPercent,
            fmtDuration: fmtDuration,

            /**
             * Width of the progress bar of one list row, relative to the
             * biggest row of the same list.
             */
            widthPct: function (item, list) {
                var rows = list || [];
                var max = 0;
                rows.forEach(function (row) {
                    var value = Number(row.pageviews || 0);
                    if (value > max) max = value;
                });
                if (max <= 0) return '0%';
                return Math.max(2, Math.round((Number(item.pageviews || 0) / max) * 100)) + '%';
            },

            /** Share of the total, used for the "N% of all" hints. */
            sharePct: function (item, list) {
                var rows = list || [];
                var total = 0;
                rows.forEach(function (row) { total += Number(row.pageviews || 0); });
                if (total <= 0) return '0%';
                return fmtPercent(Number(item.pageviews || 0) / total, 1);
            },

            /**
             * An SVG path for a tiny inline sparkline, drawn in a 100x28 box.
             */
            sparkPath: function (values) {
                var series = Array.isArray(values) ? values.map(Number) : [];
                if (series.length < 2) return '';
                var max = Math.max.apply(null, series);
                var min = Math.min.apply(null, series);
                var span = (max - min) || 1;
                var step = 100 / (series.length - 1);
                var points = series.map(function (value, index) {
                    var x = index * step;
                    var y = 26 - ((value - min) / span) * 24;
                    return x.toFixed(2) + ',' + y.toFixed(2);
                });
                return 'M' + points.join(' L');
            },

            /** Delta badge: returns up/down/flat and a formatted percentage. */
            delta: function (key) {
                var deltas = (this.payload.previous && this.payload.previous.delta) ? this.payload.previous.delta : {};
                var value = deltas[key];
                if (value === null || value === undefined) {
                    return { direction: 'new', text: options.labels ? options.labels.new : 'new' };
                }
                var direction = value > 0.0005 ? 'up' : (value < -0.0005 ? 'down' : 'flat');
                return { direction: direction, text: (value > 0 ? '+' : '') + fmtPercent(value, 1) };
            },

            /**
             * Compact "▲ 12,5%" text for a nullable ratio, used by the goals
             * widget. A null ratio means "nothing to compare with", which is
             * printed as nothing rather than as +100%.
             */
            deltaText: function (value) {
                if (value === null || value === undefined || value === 0) {
                    return '';
                }
                return (value > 0 ? '▲ ' : '▼ ') + fmtPercent(Math.abs(value), 1);
            },

            defaultLayout: function () {
                return (options.widgets || []).map(function (id) {
                    return { id: id, visible: true };
                });
            },

            loadLayout: function () {
                var stored = null;
                try {
                    stored = JSON.parse(localStorage.getItem(this.storageKey) || 'null');
                } catch (e) { stored = null; }

                var known = options.widgets || [];
                var layout = [];

                if (Array.isArray(stored)) {
                    stored.forEach(function (entry) {
                        var id = typeof entry === 'string' ? entry : (entry && entry.id);
                        if (id && known.indexOf(id) !== -1 && !layout.some(function (w) { return w.id === id; })) {
                            layout.push({ id: id, visible: typeof entry === 'object' ? entry.visible !== false : true });
                        }
                    });
                }
                known.forEach(function (id) {
                    if (!layout.some(function (w) { return w.id === id; })) {
                        layout.push({ id: id, visible: true });
                    }
                });
                return layout;
            },

            saveLayout: function () {
                try {
                    localStorage.setItem(this.storageKey, JSON.stringify(this.layout));
                } catch (e) { /* storage full or blocked */ }
            },

            isVisible: function (id) {
                var entry = this.layout.filter(function (w) { return w.id === id; })[0];
                return entry ? entry.visible : true;
            },

            toggleWidget: function (id) {
                var entry = this.layout.filter(function (w) { return w.id === id; })[0];
                if (entry) {
                    entry.visible = !entry.visible;
                    this.layout = this.layout.slice();
                    this.saveLayout();
                }
            },

            resetLayout: function () {
                this.layout = this.defaultLayout();
                this.saveLayout();
                this.applyLayoutOrder();
                this.$nextTick(function () {
                    this.initSortable();
                    this.renderChart();
                    this.renderMap();
                }.bind(this));
            },

            applyLayoutOrder: function () {
                var grid = this.$refs.grid;
                if (!grid) return;

                this.layout.forEach(function (entry) {
                    var node = grid.querySelector('[data-widget="' + entry.id + '"]');
                    if (node) {
                        grid.appendChild(node);
                    }
                });
            },

            initSortable: function () {
                if (this.readonly || typeof window.Sortable === 'undefined') return;
                if (this.sortable) this.sortable.destroy();

                var self = this;
                var grid = this.$refs.grid;
                if (!grid) return;

                this.sortable = window.Sortable.create(grid, {
                    handle: '.pb-drag-handle',
                    animation: 160,
                    ghostClass: 'pb-widget-ghost',
                    chosenClass: 'pb-widget-chosen',
                    draggable: '[data-widget]',
                    onEnd: function () {
                        var order = [];
                        Array.prototype.forEach.call(grid.children, function (node) {
                            var id = node.getAttribute('data-widget');
                            if (!id) return;
                            var entry = self.layout.filter(function (w) { return w.id === id; })[0];
                            order.push({ id: id, visible: entry ? entry.visible : true });
                        });
                        self.layout = order;
                        self.saveLayout();
                        self.$nextTick(function () {
                            if (self.chart) self.chart.resize();
                            if (self.map) self.renderMap();
                        });
                    }
                });
            },

            /* ------------------------------------------------- visitor log */

            /** Ask for more rows of the visitor log. */
            loadMoreVisits: function () {
                this.visitsLimit = Math.min(200, this.visitsLimit + 50);
                this.reload();
            },

            /**
             * Expand or collapse one session. The pages of a session are only
             * fetched when the row is opened, so the dashboard itself stays a
             * single request.
             */
            toggleVisit: function (id) {
                if (this.openVisit === id) {
                    this.openVisit = 0;
                    return;
                }

                this.openVisit = id;
                this.visitPages = [];
                this.visitLoading = true;

                var params = new URLSearchParams({ only: 'visit', site: this.siteId, visit: id });
                if (options && options.shareToken) {
                    params.set('share', options.shareToken);
                }

                var url = (PB.endpoints && PB.endpoints.stats ? PB.endpoints.stats : 'api/stats.php')
                    + '?' + params.toString();

                fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        // A newer row may have been opened while this was in flight.
                        if (this.openVisit !== id) return;
                        this.visitLoading = false;
                        this.visitPages = (data && data.pages) ? data.pages : [];
                    }.bind(this))
                    .catch(function () {
                        this.visitLoading = false;
                    }.bind(this));
            },

            /* -------------------------------------------------- chart */

            renderChart: function () {
                if (typeof window.Chart === 'undefined') return;
                var canvas = document.getElementById('pb-main-chart');
                if (!canvas) return;

                var series = this.payload.series || { labels: [], pageviews: [], visitors: [] };
                var dark = document.documentElement.classList.contains('dark');
                var grid = dark ? 'rgba(148,163,184,0.16)' : 'rgba(100,116,139,0.14)';
                var tick = dark ? '#94a3b8' : '#64748b';

                var labels = series.labels.map(function (label) {
                    if (series.granularity === 'hour') return label;
                    var parts = String(label).split('-');
                    return parts.length === 3 ? parts[2] + '.' + parts[1] : label;
                });

                var datasets = [
                    {
                        label: options.labels ? options.labels.pageviews : 'Pageviews',
                        data: series.pageviews || [],
                        borderColor: '#0ea5e9',
                        backgroundColor: 'rgba(14,165,233,0.14)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        pointHoverBackgroundColor: '#0ea5e9'
                    },
                    {
                        label: options.labels ? options.labels.visitors : 'Visitors',
                        data: series.visitors || [],
                        borderColor: '#22d3ee',
                        backgroundColor: 'rgba(34,211,238,0.10)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        pointHoverBackgroundColor: '#22d3ee'
                    }
                ];

                if (this.chart) {
                    this.chart.data.labels = labels;
                    this.chart.data.datasets[0].data = series.pageviews || [];
                    this.chart.data.datasets[1].data = series.visitors || [];
                    this.chart.update('none');
                    return;
                }

                this.chart = new window.Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: { labels: labels, datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: {
                                position: 'top',
                                align: 'end',
                                labels: { color: tick, boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle' }
                            },
                            tooltip: {
                                backgroundColor: dark ? '#0f172a' : '#ffffff',
                                titleColor: dark ? '#e2e8f0' : '#0f172a',
                                bodyColor: dark ? '#cbd5e1' : '#334155',
                                borderColor: grid,
                                borderWidth: 1,
                                padding: 10,
                                displayColors: true,
                                callbacks: {
                                    label: function (context) {
                                        return ' ' + context.dataset.label + ': ' + fmtNumber(context.parsed.y);
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: tick, maxRotation: 0, autoSkipPadding: 18, font: { size: 11 } },
                                border: { color: grid }
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: grid },
                                ticks: {
                                    color: tick,
                                    precision: 0,
                                    font: { size: 11 },
                                    callback: function (value) { return fmtNumber(value); }
                                },
                                border: { display: false }
                            }
                        }
                    }
                });
            },

            /* ----------------------------------------------------------- map */

            /**
             * Ask the map to remeasure itself, once the layout has settled.
             */
            scheduleMapResize: function () {
                if (this.mapResizeTimer) {
                    clearTimeout(this.mapResizeTimer);
                }

                this.mapResizeTimer = setTimeout(function () {
                    if (!this.map || typeof this.map.updateSize !== 'function') {
                        return;
                    }

                    try {
                        this.map.updateSize();
                    } catch (error) {
                        /* the map was replaced while the timer was pending */
                    }
                }.bind(this), 150);
            },
            renderMap: function () {
                if (typeof window.jsVectorMap === 'undefined') return;
                var container = document.getElementById('pb-world-map');
                if (!container) return;

                var rows = (this.payload.top && this.payload.top.country) ? this.payload.top.country : [];
                var values = {};
                var max = 0;
                rows.forEach(function (row) {
                    if (!row.value) return;
                    values[row.value] = Number(row.pageviews || 0);
                    if (values[row.value] > max) max = values[row.value];
                });

                if (this.map) {
                    try { this.map.destroy(); } catch (e) { /* already gone */ }
                    container.innerHTML = '';
                    this.map = null;
                }

                var dark = document.documentElement.classList.contains('dark');

                this.map = new window.jsVectorMap({
                    selector: '#pb-world-map',
                    map: 'world',
                    zoomButtons: false,
                    zoomOnScroll: false,
                    backgroundColor: 'transparent',
                    regionStyle: {
                        initial: {
                            fill: dark ? '#334155' : '#e2e8f0',
                            stroke: dark ? '#1e293b' : '#f8fafc',
                            strokeWidth: 0.4
                        },
                        hover: { fill: '#0ea5e9' }
                    },
                    series: {
                        regions: [{
                            attribute: 'fill',
                            scale: ['#bae6fd', '#0284c7'],
                            normalizeFunction: 'polynomial',
                            values: values
                        }]
                    },
                    onRegionTooltipShow: function (event, tooltip, code) {
                        var value = values[code];
                        tooltip.text(
                            tooltip.text() + (value ? ' — ' + fmtNumber(value) : ' — 0'),
                            true
                        );
                    }
                });
            }
        };
    };

    /* -------------------------------------------------------- geoip import */

    window.pbGeoIpImport = function () {
        return {
            running: false,
            percent: 0,
            message: '',
            count: 0,
            fmt: '',
            done: false,

            endpoint: function () {
                return (PB.endpoints && PB.endpoints.geoip) ? PB.endpoints.geoip : 'index.php?r=settings/geoip';
            },

            start: function () {
                if (this.running) return;
                this.running = true;
                this.done = false;
                this.percent = 0;
                this.message = '';

                var self = this;
                var endpoint = this.endpoint();

                post(endpoint, { phase: 'prepare' }).then(function (data) {
                    if (!data.ok) {
                        self.fail(data.error);
                        return;
                    }
                    self.tick(endpoint, 0);
                }).catch(function () { self.fail('network'); });
            },

            /** Fetch a newer DB-IP Lite release, then import it. */
            download: function () {
                if (this.running) return;
                this.running = true;
                this.done = false;
                this.message = '';

                var self = this;
                var endpoint = this.endpoint();

                post(endpoint, { phase: 'download' }).then(function (data) {
                    self.running = false;
                    if (!data.ok) {
                        self.fail(data.error);
                        return;
                    }
                    self.start();
                }).catch(function () {
                    self.running = false;
                    self.fail('network');
                });
            },

            tick: function (endpoint, offset) {
                var self = this;
                post(endpoint, { phase: 'chunk', offset: offset }).then(function (data) {
                    if (!data.ok) {
                        self.fail(data.error);
                        return;
                    }
                    self.percent = data.percent;
                    if (data.done) {
                        self.running = false;
                        self.done = true;
                        self.count = data.count;
                        self.fmt = fmtNumber(data.count);
                        self.message = '';
                        return;
                    }
                    self.tick(endpoint, data.offset);
                }).catch(function () { self.fail('network'); });
            },

            fail: function (error) {
                this.running = false;
                this.message = String(error || 'error');
            }
        };
    };

    /* ------------------------------------------------------- tracker check */

    window.pbTrackerCheck = function (siteId, endpoint) {
        return {
            siteId: siteId,
            endpoint: endpoint,
            checking: false,
            ok: false,
            message: '',

            check: function () {
                var self = this;
                this.checking = true;
                post(this.endpoint, { site_id: this.siteId }).then(function (data) {
                    self.checking = false;
                    self.ok = !!data.ok;
                    self.message = data.message || '';
                }).catch(function () {
                    self.checking = false;
                    self.message = 'network';
                });
            }
        };
    };

    /* ------------------------------------------------- installer onboarding */

    /**
     * Step 6 of the installer: create the first website, show its tracking
     * code and verify that hits arrive.
     *
     * The component lives here rather than inline in the template so that no
     * JavaScript ever has to be escaped into an HTML attribute - a single
     * unescaped double quote in an inline handler silently breaks the page.
     *
     * @param {{siteId:number, snippet:string, createEndpoint:string, checkEndpoint:string}} config
     */
    window.pbOnboarding = function (config) {
        config = config || {};

        return {
            siteId: Number(config.siteId || 0),
            snippet: String(config.snippet || ''),
            createEndpoint: String(config.createEndpoint || ''),
            checkEndpoint: String(config.checkEndpoint || ''),

            creating: false,
            error: '',
            copied: false,
            checking: false,
            checkOk: false,
            checkMessage: '',

            create: function () {
                var form = document.getElementById('pb-site-form');
                if (!form || (typeof form.reportValidity === 'function' && !form.reportValidity())) {
                    return;
                }

                var self = this;
                this.creating = true;
                this.error = '';

                var body = new FormData(form);
                body.append('_token', PB.csrf || '');

                fetch(this.createEndpoint, {
                    method: 'POST',
                    body: body,
                    headers: { 'X-CSRF-Token': PB.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        self.creating = false;
                        if (!data || !data.ok) {
                            self.error = (data && (data.message || data.error)) || 'error';
                            return;
                        }
                        self.siteId = data.site_id;
                        self.snippet = data.snippet;
                    })
                    .catch(function () {
                        self.creating = false;
                        self.error = 'network';
                    });
            },

            check: function () {
                var self = this;
                this.checking = true;
                this.checkMessage = '';

                var body = new FormData();
                body.append('site_id', this.siteId);
                body.append('_token', PB.csrf || '');

                fetch(this.checkEndpoint, {
                    method: 'POST',
                    body: body,
                    headers: { 'X-CSRF-Token': PB.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        self.checking = false;
                        self.checkOk = !!(data && data.ok);
                        self.checkMessage = (data && data.message) || '';
                    })
                    .catch(function () {
                        self.checking = false;
                        self.checkMessage = 'network';
                    });
            },

            copy: function () {
                var self = this;
                var done = function () {
                    self.copied = true;
                    setTimeout(function () { self.copied = false; }, 2000);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(this.snippet).then(done).catch(function () {});
                    return;
                }
                // Fallback for plain HTTP, where the Clipboard API is unavailable.
                var area = document.createElement('textarea');
                area.value = this.snippet;
                document.body.appendChild(area);
                area.select();
                try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
                document.body.removeChild(area);
            }
        };
    };

    /* ------------------------------------------------------ site management */

    window.pbSites = function (sites, clients) {
        // Same reason as pbUsers: the four checkboxes in this form lose their
        // value when the model starts undefined.
        var blankForm = function () {
            return {
                id: 0,
                name: '',
                domain: '',
                timezone: 'UTC',
                is_active: true,
                exclude_paths: '',
                exclude_ips: '',
                allowed_hosts: '',
                respect_dnt: false,
                track_query: false,
                exclude_bots: true,
                users: []
            };
        };

        return {
            sites: sites || [],
            clients: clients || [],
            modal: '',
            copied: '',
            deleteTarget: null,
            form: blankForm(),

            blankForm: blankForm,

            openCreate: function () {
                this.form = this.blankForm();
                this.modal = 'site';
            },

            openEdit: function (id) {
                var site = null;
                this.sites.forEach(function (candidate) {
                    if (Number(candidate.id) === Number(id)) site = candidate;
                });
                if (!site) return;
                this.form = Object.assign(this.blankForm(), site.settings || {}, {
                    id: site.id,
                    name: site.name,
                    domain: site.domain,
                    timezone: site.timezone,
                    is_active: !!site.is_active,
                    users: (site.users || []).map(Number)
                });
                this.modal = 'site';
            },

            close: function () {
                this.modal = '';
            },

            askDelete: function (id) {
                var site = null;
                this.sites.forEach(function (candidate) {
                    if (Number(candidate.id) === Number(id)) site = candidate;
                });
                this.deleteTarget = site;
                this.modal = 'delete';
            },

            copy: function (text) {
                var self = this;
                var done = function () {
                    self.copied = text;
                    setTimeout(function () { self.copied = ''; }, 2000);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(function () {});
                } else {
                    var area = document.createElement('textarea');
                    area.value = text;
                    document.body.appendChild(area);
                    area.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    document.body.removeChild(area);
                }
            }
        };
    };

    /* ------------------------------------------------------ user management */

    window.pbUsers = function (users, assignments) {
        // Declared before Alpine starts. An undefined model makes Alpine blank the
        // value of every checkbox it binds, and a checkbox with no value reaches the
        // server as an empty string, which reads as "unchecked".
        var blankForm = function () {
            return {
                id: 0,
                email: '',
                name: '',
                role: 'client',
                locale: 'en',
                is_active: true,
                sites: []
            };
        };

        return {
            users: users || [],
            assignments: assignments || {},
            modal: '',
            form: blankForm(),
            deleteTarget: null,

            blankForm: blankForm,

            openCreate: function () {
                this.form = this.blankForm();
                this.modal = 'user';
            },

            openEdit: function (id) {
                var user = null;
                this.users.forEach(function (candidate) {
                    if (Number(candidate.id) === Number(id)) user = candidate;
                });
                if (!user) return;
                this.form = Object.assign(this.blankForm(), {
                    id: user.id,
                    email: user.email,
                    name: user.name || '',
                    role: user.role,
                    locale: user.locale || 'en',
                    is_active: Number(user.is_active) === 1,
                    sites: (this.assignments[user.id] || []).map(Number)
                });
                this.modal = 'user';
            },

            askDelete: function (id) {
                var user = null;
                this.users.forEach(function (candidate) {
                    if (Number(candidate.id) === Number(id)) user = candidate;
                });
                this.deleteTarget = user;
                this.modal = 'delete';
            },

            close: function () {
                this.modal = '';
            }
        };
    };
})();
