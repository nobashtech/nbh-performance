(function () {
	'use strict';
	var config = window.NBHPerformanceRum;
	if (!config || !window.PerformanceObserver || Math.random() > Number(config.sampleRate || 0)) { return; }
	if (document.prerendering) {
		document.addEventListener('prerenderingchange', start, { once: true });
	} else {
		start();
	}

	function start() {
		var token = null;
		var sent = false;
		var pendingSend = false;
		var metrics = resetMetrics();
		var interactions = {};
		var observers = [];
		var clsSession = { value: 0, first: 0, last: 0, max: 0 };
		var nav = performance.getEntriesByType('navigation')[0];
		if (nav && nav.responseStart > 0) { metrics.ttfb = Math.max(0, nav.responseStart); }

		fetch(config.tokenEndpoint + (config.tokenEndpoint.indexOf('?') === -1 ? '?' : '&') + 'context=' + encodeURIComponent(config.context || 'other') + '&path=' + encodeURIComponent(location.pathname), { credentials: 'omit', cache: 'no-store' })
			.then(function (response) { return response.ok ? response.json() : Promise.reject(); })
			.then(function (data) { token = data; if (pendingSend) { send(); } })
			.catch(function () {});

		observe('largest-contentful-paint', function (entries) {
			var last = entries[entries.length - 1];
			if (last) { metrics.lcp = last.startTime; }
		});
		if (PerformanceObserver.supportedEntryTypes && PerformanceObserver.supportedEntryTypes.indexOf('layout-shift') !== -1) { metrics.cls = 0; }
		observe('layout-shift', function (entries) {
			entries.forEach(function (entry) {
				if (entry.hadRecentInput) { return; }
				if (!clsSession.first || entry.startTime - clsSession.last > 1000 || entry.startTime - clsSession.first > 5000) {
					clsSession.value = entry.value;
					clsSession.first = entry.startTime;
				} else {
					clsSession.value += entry.value;
				}
				clsSession.last = entry.startTime;
				clsSession.max = Math.max(clsSession.max, clsSession.value);
				metrics.cls = clsSession.max;
			});
		});
		observe('event', function (entries) {
			entries.forEach(function (entry) {
				if (!entry.duration || !entry.interactionId) { return; }
				var id = entry.interactionId || ('event-' + entry.startTime);
				interactions[id] = Math.max(interactions[id] || 0, entry.duration);
			});
			var values = Object.keys(interactions).map(function (key) { return interactions[key]; }).sort(function (a, b) { return a - b; });
			if (values.length) {
				var index = values.length < 50 ? values.length - 1 : Math.max(0, Math.ceil(values.length * 0.98) - 1);
				metrics.inp = values[index];
			}
		}, false);
		observe('paint', function (entries) {
			entries.forEach(function (entry) { if (entry.name === 'first-contentful-paint') { metrics.fcp = entry.startTime; } });
		});

		function observe(type, callback) {
			try {
				var observer = new PerformanceObserver(function (list) { callback(list.getEntries()); });
				observer.observe({ type: type, buffered: true, durationThreshold: type === 'event' ? 40 : undefined });
				observers.push({ observer: observer, callback: callback });
			} catch (error) { /* Unsupported metric. */ }
		}

		function send() {
			if (sent) { return; }
			observers.forEach(function (item) { var entries = item.observer.takeRecords(); if (entries.length) { item.callback(entries); } });
			if (!token) { pendingSend = true; return; }
			if (metrics.lcp === null && metrics.inp === null && metrics.cls === null && metrics.fcp === null && metrics.ttfb === null) { return; }
			sent = true;
			var connection = navigator.connection && navigator.connection.effectiveType ? navigator.connection.effectiveType : 'unknown';
			var payload = {
				urlHash: token.urlHash, context: token.context, timestamp: token.timestamp, signature: token.signature,
				device: device(), connection: connection, lcp: metrics.lcp, inp: metrics.inp, cls: metrics.cls,
				ttfb: metrics.ttfb, fcp: metrics.fcp
			};
			var body = JSON.stringify(payload);
			try {
				if (navigator.sendBeacon && navigator.sendBeacon(config.endpoint, new Blob([body], { type: 'application/json' }))) { return; }
			} catch (error) { /* Fall back when the browser refuses a beacon. */ }
			fetch(config.endpoint, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true, credentials: 'omit' }).catch(function () { sent = false; });
		}
		document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { send(); } }, { passive: true });
		window.addEventListener('pagehide', send, { passive: true });
		window.addEventListener('pageshow', function (event) { if (event.persisted) { sent = true; pendingSend = false; } }, { passive: true });
	}

	function resetMetrics() { return { lcp: null, inp: null, cls: null, fcp: null, ttfb: null }; }
	function device() { var width = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0); return width < 768 ? 'mobile' : (width < 1024 ? 'tablet' : 'desktop'); }
}());
