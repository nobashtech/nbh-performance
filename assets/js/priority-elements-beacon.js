(function () {
	'use strict';
	var config = window.nbhPerformancePriorityElements || {};
	if (!config.endpoint || !config.url || !config.token || Math.random() > Number(config.sample || 0)) { return; }
	var maxItems = Math.max(4, Math.min(20, Number(config.maxItems || 12)));
	var lcpCandidate = null;

	function cleanUrl(value) {
		try {
			var url = new URL(String(value || ''), window.location.href);
			if (url.protocol !== 'http:' && url.protocol !== 'https:') { return ''; }
			url.hash = '';
			return url.href;
		} catch (error) { return ''; }
	}

	function imageUrl(element) {
		if (!element) { return ''; }
		if (element.currentSrc || element.src) { return cleanUrl(element.currentSrc || element.src); }
		if (element.poster) { return cleanUrl(element.poster); }
		try {
			var background = window.getComputedStyle(element).backgroundImage || '';
			var match = background.match(/url\(["']?([^"')]+)["']?\)/i);
			return match ? cleanUrl(match[1]) : '';
		} catch (error) { return ''; }
	}

	function selector(element) {
		if (!element || !element.tagName) { return ''; }
		var result = String(element.tagName).toLowerCase();
		var id = String(element.id || '');
		if (id && /^[a-z][a-z0-9_-]{0,50}$/i.test(id) && !/\d{4,}/.test(id)) { return result + '#' + id; }
		var classes = Array.prototype.slice.call(element.classList || []).filter(function (name) {
			return /^[a-z][a-z0-9_-]{0,40}$/i.test(name) && !/\d{4,}/.test(name);
		}).slice(0, 3);
		return result + (classes.length ? '.' + classes.join('.') : '');
	}

	function item(url, type, score, element) {
		url = cleanUrl(url);
		if (!url) { return null; }
		return { key: url, url: url, type: type || '', score: Math.max(1, Math.min(100, Math.round(score || 1))), selector: selector(element) };
	}

	function dedupe(items) {
		var map = {};
		items.forEach(function (entry) {
			if (!entry || !entry.key) { return; }
			if (!map[entry.key] || Number(entry.score || 0) > Number(map[entry.key].score || 0)) { map[entry.key] = entry; }
		});
		return Object.keys(map).map(function (key) { return map[key]; }).sort(function (a, b) { return Number(b.score || 0) - Number(a.score || 0); }).slice(0, maxItems);
	}

	function observeLcp() {
		if (!('PerformanceObserver' in window)) { return; }
		try {
			var observer = new PerformanceObserver(function (list) {
				var entries = list.getEntries();
				var entry = entries[entries.length - 1];
				if (!entry) { return; }
				var element = entry.element || null;
				var url = entry.url || imageUrl(element);
				if (url) { lcpCandidate = item(url, 'lcp', 100, element); }
			});
			observer.observe({ type: 'largest-contentful-paint', buffered: true });
		} catch (error) { /* Browser does not support buffered LCP. */ }
	}

	function collectImages() {
		var viewportHeight = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
		var viewportWidth = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
		var viewportArea = Math.max(1, viewportHeight * viewportWidth);
		return dedupe(Array.prototype.slice.call(document.images || []).map(function (image) {
			var rect = image.getBoundingClientRect();
			if (rect.bottom <= 0 || rect.top > viewportHeight * 1.25 || rect.width < 32 || rect.height < 32) { return null; }
			var score = 40 + Math.min(50, (rect.width * rect.height / viewportArea) * 100);
			return item(image.currentSrc || image.src, 'image', score, image);
		}));
	}

	function collectBackgrounds() {
		var viewportHeight = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
		var nodes = Array.prototype.slice.call(document.querySelectorAll('body *'), 0, 350);
		return dedupe(nodes.map(function (element) {
			var rect = element.getBoundingClientRect();
			if (rect.bottom <= 0 || rect.top > viewportHeight * 1.1 || rect.width < 80 || rect.height < 40) { return null; }
			var url = imageUrl(element);
			if (!url || element.currentSrc || element.src) { return null; }
			return item(url, 'background', 45, element);
		}));
	}

	function resourceEntries() {
		try { return window.performance && performance.getEntriesByType ? performance.getEntriesByType('resource') : []; }
		catch (error) { return []; }
	}

	function criticalFontFamilies() {
		var viewportHeight = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
		var families = {};
		Array.prototype.slice.call(document.querySelectorAll('body *'), 0, 450).forEach(function (element) {
			var rect = element.getBoundingClientRect();
			if (rect.bottom <= 0 || rect.top > viewportHeight * 1.1 || rect.width < 2 || rect.height < 2 || !String(element.textContent || '').trim()) { return; }
			try {
				String(window.getComputedStyle(element).fontFamily || '').split(',').forEach(function (family) {
					family = family.replace(/["']/g, '').trim().toLowerCase();
					if (family) { families[family] = true; }
				});
			} catch (error) { /* Ignore detached nodes. */ }
		});
		return families;
	}

	function fontFaceSources() {
		var sources = {};
		Array.prototype.slice.call(document.styleSheets || []).forEach(function (sheet) {
			var rules;
			try { rules = sheet.cssRules || []; } catch (error) { return; }
			Array.prototype.slice.call(rules).forEach(function (rule) {
				if (!rule || !rule.style || String(rule.type) !== String(window.CSSRule && window.CSSRule.FONT_FACE_RULE || 5)) { return; }
				var family = String(rule.style.getPropertyValue('font-family') || '').replace(/["']/g, '').trim().toLowerCase();
				var src = String(rule.style.getPropertyValue('src') || '');
				var match;
				var pattern = /url\(["']?([^"')]+)["']?\)/ig;
				while ((match = pattern.exec(src)) !== null) {
					var url = cleanUrl(match[1]);
					if (url) { sources[url] = family; }
				}
			});
		});
		return sources;
	}

	function collectFonts(entries) {
		var criticalFamilies = criticalFontFamilies();
		var sourceFamilies = fontFaceSources();
		return dedupe(entries.map(function (entry) {
			var url = cleanUrl(entry.name);
			if (!url) { return null; }
			var parsed = new URL(url);
			var path = parsed.pathname.toLowerCase();
			var isFont = entry.initiatorType === 'font' || /\.(woff2?|ttf|otf)(\?|$)/i.test(path);
			if (!isFont || parsed.origin !== window.location.origin) { return null; }
			var family = String(sourceFamilies[url] || '').toLowerCase();
			var isCritical = family && criticalFamilies[family];
			var timingBoost = Math.max(0, 15 - Math.round(Number(entry.startTime || 0) / 150));
			var result = item(url, path.indexOf('.woff2') !== -1 ? 'woff2' : 'font', (isCritical ? 85 : 50) + timingBoost, null);
			if (result && family) { result.selector = family; }
			return result;
		}));
	}

	function collectOrigins(entries) {
		var counts = {};
		entries.forEach(function (entry) {
			try {
				var url = new URL(entry.name);
				if (!/^https?:$/.test(url.protocol) || url.origin === window.location.origin) { return; }
				counts[url.origin] = (counts[url.origin] || 0) + 1 + Math.min(9, Math.round(Number(entry.transferSize || 0) / 50000));
			} catch (error) { /* Ignore malformed resource URLs. */ }
		});
		return Object.keys(counts).map(function (origin) { return { key: origin, url: origin, type: 'origin', score: counts[origin], selector: '' }; }).sort(function (a, b) { return b.score - a.score; }).slice(0, maxItems);
	}

	function send() {
		var entries = resourceEntries();
		var payload = {
			url: config.url,
			token: config.token,
			variant: window.innerWidth <= 767 ? 'mobile' : 'desktop',
			viewport: { width: Math.round(window.innerWidth || 0), height: Math.round(window.innerHeight || 0) },
			lcp: lcpCandidate ? [lcpCandidate] : [],
			images: collectImages(),
			backgrounds: collectBackgrounds(),
			fonts: collectFonts(entries),
			origins: collectOrigins(entries)
		};
		var body = JSON.stringify(payload);
		if (navigator.sendBeacon) {
			var blob = new Blob([body], { type: 'application/json' });
			if (navigator.sendBeacon(config.endpoint, blob)) { return; }
		}
		fetch(config.endpoint, { method: 'POST', credentials: 'omit', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: body }).catch(function () {});
	}

	observeLcp();
	window.addEventListener('load', function () {
		window.setTimeout(function () {
			if ('requestIdleCallback' in window) { window.requestIdleCallback(send, { timeout: 3000 }); }
			else { send(); }
		}, 1200);
	}, { once: true });
}());
