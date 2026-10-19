(function (wp) {
	'use strict';

	var root = document.getElementById('nbh-performance-hub-root');
	if (!root || !wp || !wp.element || !wp.apiFetch) {
		if (root && window.NBHPerformanceQualityRuntime) { window.NBHPerformanceQualityRuntime.renderBootError(root); }
		return;
	}

	var rawEl = wp.element.createElement;
	var translate = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (value) { return value; };
	function tr(value) { return typeof value === 'string' ? translate(value, 'nbh-performance') : value; }
	function el(type, props) {
		var args = Array.prototype.slice.call(arguments);
		if (props && typeof props === 'object' && !Array.isArray(props)) {
			props = Object.assign({}, props);
			['title', 'placeholder', 'aria-label', 'alt'].forEach(function (key) { if (typeof props[key] === 'string') { props[key] = tr(props[key]); } });
			args[1] = props;
		}
		for (var index = 2; index < args.length; index += 1) { if (typeof args[index] === 'string') { args[index] = tr(args[index]); } }
		return rawEl.apply(null, args);
	}
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var apiFetch = wp.apiFetch;
	var nonce = window.nbhPerformanceHub && window.nbhPerformanceHub.nonce ? window.nbhPerformanceHub.nonce : '';

	apiFetch.use(apiFetch.createNonceMiddleware(nonce));

	var HUB_CATALOG = window.NBHPerformanceHubCatalog || {};
	var NAV_ITEMS = HUB_CATALOG.navItems || [];

	var SECTION_META = HUB_CATALOG.sectionMeta || {};


	var FIELD_GUIDANCE = HUB_CATALOG.fieldGuidance || {};

	function fieldGuidance(key, field) {
		if (FIELD_GUIDANCE[key]) { return FIELD_GUIDANCE[key]; }
		if (field && field.sanitize === 'urls') { return { placeholder: 'Add a complete URL or site-relative path…', hint: 'Add one precise resource or path per line.', examples: ['https://example.com/path/resource.woff2', '/wp-content/uploads/resource.webp'] }; }
		if (field && field.sanitize === 'exclusions') { return { placeholder: 'Add a handle, path, selector, or URL fragment to exclude…', hint: 'Use a short, unique pattern that identifies only the item you want to exclude.', examples: ['/checkout/', 'plugin-script-handle'] }; }
		return { placeholder: 'Add one value on each new line…', hint: 'Press Enter after each value.', examples: [] };
	}

	var FEATURE_COPY = HUB_CATALOG.featureCopy || {};


	var ONE_CLICK_EXCLUSIONS = HUB_CATALOG.oneClickExclusions || {};
	function Icon(name, className) {
		var paths = {
			home: [el('path', { key: 'a', d: 'M3 11.5 12 4l9 7.5' }), el('path', { key: 'b', d: 'M5 10.5V20h14v-9.5' }), el('path', { key: 'c', d: 'M9 20v-6h6v6' })],
			layers: [el('path', { key: 'a', d: 'm12 3 9 5-9 5-9-5 9-5Z' }), el('path', { key: 'b', d: 'm3 12 9 5 9-5' }), el('path', { key: 'c', d: 'm3 16 9 5 9-5' })],
			mobile: [el('rect', { key: 'a', x: '7', y: '2.5', width: '10', height: '19', rx: '2' }), el('path', { key: 'b', d: 'M10 5h4' }), el('path', { key: 'c', d: 'M11.5 18.5h1' })],
			sliders: [el('path', { key: 'a', d: 'M4 6h10' }), el('path', { key: 'b', d: 'M18 6h2' }), el('circle', { key: 'c', cx: '16', cy: '6', r: '2' }), el('path', { key: 'd', d: 'M4 12h2' }), el('path', { key: 'e', d: 'M10 12h10' }), el('circle', { key: 'f', cx: '8', cy: '12', r: '2' }), el('path', { key: 'g', d: 'M4 18h8' }), el('path', { key: 'h', d: 'M16 18h4' }), el('circle', { key: 'i', cx: '14', cy: '18', r: '2' })],
			image: [el('rect', { key: 'a', x: '3', y: '4', width: '18', height: '16', rx: '2' }), el('circle', { key: 'b', cx: '8.5', cy: '9', r: '1.5' }), el('path', { key: 'c', d: 'm21 15-5-5L5 20' })],
			globe: [el('circle', { key: 'a', cx: '12', cy: '12', r: '9' }), el('path', { key: 'b', d: 'M3 12h18' }), el('path', { key: 'c', d: 'M12 3c3 3 3 15 0 18' }), el('path', { key: 'd', d: 'M12 3c-3 3-3 15 0 18' })],
			database: [el('ellipse', { key: 'a', cx: '12', cy: '5', rx: '8', ry: '3' }), el('path', { key: 'b', d: 'M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5' }), el('path', { key: 'c', d: 'M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7' })],
			type: [el('path', { key: 'a', d: 'M5 19 12 4l7 15' }), el('path', { key: 'b', d: 'M8 13h8' })],
			wrench: [el('path', { key: 'a', d: 'M14.7 6.3a4 4 0 0 0-5 5L3 18l3 3 6.7-6.7a4 4 0 0 0 5-5l-2.6 2.6-3-3 2.6-2.6Z' })],
			shield: [el('path', { key: 'a', d: 'M12 3 20 6v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3Z' }), el('path', { key: 'b', d: 'm9 12 2 2 4-4' })],
			refresh: [el('path', { key: 'a', d: 'M20 6v5h-5' }), el('path', { key: 'b', d: 'M4 18v-5h5' }), el('path', { key: 'c', d: 'M18.5 9A7 7 0 0 0 6 6.5L4 9' }), el('path', { key: 'd', d: 'M5.5 15A7 7 0 0 0 18 17.5L20 15' })],
			setup: [el('path', { key: 'a', d: 'm4 20 10-10' }), el('path', { key: 'b', d: 'm14 4 1.5 3.5L19 9l-3.5 1.5L14 14l-1.5-3.5L9 9l3.5-1.5L14 4Z' }), el('path', { key: 'c', d: 'm5 4 .8 1.8L8 6.5l-2.2.7L5 9l-.8-1.8L2 6.5l2.2-.7L5 4Z' })],
			check: [el('circle', { key: 'a', cx: '12', cy: '12', r: '9' }), el('path', { key: 'b', d: 'm8 12 2.5 2.5L16 9' })],
			alert: [el('path', { key: 'a', d: 'M12 3 21 20H3L12 3Z' }), el('path', { key: 'b', d: 'M12 9v4' }), el('path', { key: 'c', d: 'M12 17h.01' })],
			magic: [el('path', { key: 'a', d: 'm4 20 10-10' }), el('path', { key: 'b', d: 'm14 4 1 2 2 1-2 1-1 2-1-2-2-1 2-1 1-2Z' }), el('path', { key: 'c', d: 'm19 13 .7 1.3L21 15l-1.3.7L19 17l-.7-1.3L17 15l1.3-.7L19 13Z' })],
			lock: [el('rect', { key: 'a', x: '5', y: '10', width: '14', height: '11', rx: '2' }), el('path', { key: 'b', d: 'M8 10V7a4 4 0 0 1 8 0v3' })],
			arrow: [el('path', { key: 'a', d: 'm9 18 6-6-6-6' })],
			convert: [el('rect', { key: 'a', x: '3', y: '4', width: '14', height: '12', rx: '2' }), el('circle', { key: 'b', cx: '7.5', cy: '8', r: '1.3' }), el('path', { key: 'c', d: 'm4 15 4-4 3 3 2-2 4 4' }), el('path', { key: 'd', d: 'M18 9h3v3' }), el('path', { key: 'e', d: 'm21 9-4 4' }), el('path', { key: 'f', d: 'M21 17h-3v-3' }), el('path', { key: 'g', d: 'm18 17 4-4' })],
			rocket: [el('path', { key: 'a', d: 'M14 4c3-1 5-1 6-1 0 1 0 3-1 6l-5 5-5-5 5-5Z' }), el('path', { key: 'b', d: 'm9 10-4 1-2 3 5 1' }), el('path', { key: 'c', d: 'm14 15-1 4-3 2-1-5' }), el('circle', { key: 'd', cx: '15', cy: '8', r: '1.5' })],
			close: [el('path', { key: 'a', d: 'M6 6l12 12' }), el('path', { key: 'b', d: 'M18 6 6 18' })],
			info: [el('circle', { key: 'a', cx: '12', cy: '12', r: '9' }), el('path', { key: 'b', d: 'M12 11v5' }), el('path', { key: 'c', d: 'M12 8h.01' })],
			user: [el('circle', { key: 'a', cx: '12', cy: '8', r: '4' }), el('path', { key: 'b', d: 'M4 21a8 8 0 0 1 16 0' })],
			css: [el('path', { key: 'a', d: 'M8 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h2' }), el('path', { key: 'b', d: 'M16 5h2a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-2' }), el('path', { key: 'c', d: 'M10 9 8 12l2 3' }), el('path', { key: 'd', d: 'm14 9 2 3-2 3' })],
			js: [el('path', { key: 'a', d: 'M8 7h8v10H8Z' }), el('path', { key: 'b', d: 'M16 7h2a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-2' }), el('path', { key: 'c', d: 'M10 11v4' }), el('path', { key: 'd', d: 'M13 11v1.5a1.5 1.5 0 1 0 3 0V11' })],
			defer: [el('path', { key: 'a', d: 'M12 5v7l4 2' }), el('circle', { key: 'b', cx: '12', cy: '12', r: '8' }), el('path', { key: 'c', d: 'm18 18 2 2' })],
			clock: [el('circle', { key: 'a', cx: '12', cy: '12', r: '8' }), el('path', { key: 'b', d: 'M12 8v5l3 2' })],
			bolt: [el('path', { key: 'a', d: 'M13 2 5 13h5l-1 9 8-11h-5l1-9Z' })],
			broom: [el('path', { key: 'a', d: 'm14 5 5 5' }), el('path', { key: 'b', d: 'm3 21 6-6' }), el('path', { key: 'c', d: 'm8 16 6-11 4 4-11 6Z' }), el('path', { key: 'd', d: 'M5 19 3 17' })],
			close: [el('path', { key: 'a', d: 'm6 6 12 12' }), el('path', { key: 'b', d: 'M18 6 6 18' })],
			warning: [el('path', { key: 'a', d: 'M12 3 21 20H3L12 3Z' }), el('path', { key: 'b', d: 'M12 9v4' }), el('path', { key: 'c', d: 'M12 17h.01' })],
			chart: [el('path', { key: 'a', d: 'M4 19V9' }), el('path', { key: 'b', d: 'M10 19V5' }), el('path', { key: 'c', d: 'M16 19v-7' }), el('path', { key: 'd', d: 'M22 19H2' })],
			backup: [el('path', { key: 'a', d: 'M4 12a8 8 0 1 0 2.3-5.7' }), el('path', { key: 'b', d: 'M4 4v6h6' }), el('path', { key: 'c', d: 'M12 8v4l3 2' })],
			building: [el('rect', { key: 'a', x: '4', y: '3', width: '16', height: '18', rx: '2' }), el('path', { key: 'b', d: 'M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2M10 21v-3h4v3' })],
			cart: [el('path', { key: 'a', d: 'M3 4h2l2.2 10.5a2 2 0 0 0 2 1.5h7.8a2 2 0 0 0 2-1.6L21 8H7' }), el('circle', { key: 'b', cx: '10', cy: '20', r: '1' }), el('circle', { key: 'c', cx: '18', cy: '20', r: '1' })],
			cloud: [el('path', { key: 'a', d: 'M7 18h10a4 4 0 0 0 .7-7.9A6 6 0 0 0 6.4 8.4 4.5 4.5 0 0 0 7 18Z' })],
			trash: [el('path', { key: 'a', d: 'M4 7h16' }), el('path', { key: 'b', d: 'M9 7V4h6v3' }), el('path', { key: 'c', d: 'm7 7 1 14h8l1-14' }), el('path', { key: 'd', d: 'M10 11v6M14 11v6' })],
			download: [el('path', { key: 'a', d: 'M12 3v12' }), el('path', { key: 'b', d: 'm7 10 5 5 5-5' }), el('path', { key: 'c', d: 'M5 21h14' })],
			upload: [el('path', { key: 'a', d: 'M12 21V9' }), el('path', { key: 'b', d: 'm7 14 5-5 5 5' }), el('path', { key: 'c', d: 'M5 3h14' })],
			undo: [el('path', { key: 'a', d: 'M9 14 4 9l5-5' }), el('path', { key: 'b', d: 'M4 9h10.5a5.5 5.5 0 1 1 0 11H13' })],
			play: [el('path', { key: 'a', d: 'M8 5.5v13l11-6.5-11-6.5Z' })],
			pause: [el('rect', { key: 'a', x: '6', y: '5', width: '4', height: '14', rx: '1' }), el('rect', { key: 'b', x: '14', y: '5', width: '4', height: '14', rx: '1' })],
			eye: [el('path', { key: 'a', d: 'M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z' }), el('circle', { key: 'b', cx: '12', cy: '12', r: '2.5' })],
			filter: [el('path', { key: 'a', d: 'M4 5h16l-6 7v5l-4 2v-7L4 5Z' })],
			pulse: [el('path', { key: 'a', d: 'M3 12h4l2-5 4 10 2-5h6' })],
			search: [el('circle', { key: 'a', cx: '11', cy: '11', r: '7' }), el('path', { key: 'b', d: 'm20 20-4-4' })]
		};
		return el('svg', { className: 'np-icon ' + (className || ''), viewBox: '0 0 24 24', width: '20', height: '20', fill: 'none', stroke: 'currentColor', strokeWidth: '1.8', strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true', focusable: 'false' }, paths[name] || paths.shield);
	}

	var ADVANCED_SETTINGS_KEY = 'nbh_performance_show_advanced_settings';
	function readAdvancedSettings() {
		try {
			return !!(window.localStorage && window.localStorage.getItem(ADVANCED_SETTINGS_KEY) === '1');
		} catch (error) {
			return false;
		}
	}
	function writeAdvancedSettings(enabled) {
		try {
			if (window.localStorage) {
				window.localStorage.setItem(ADVANCED_SETTINGS_KEY, enabled ? '1' : '0');
			}
		} catch (error) { /* ignore */ }
	}


	function App() {
		var dataState = useState(null), data = dataState[0], setData = dataState[1];
		var activeState = useState(readHash()), active = activeState[0], setActive = activeState[1];
		var tabState = useState('features'), tab = tabState[0], setTab = tabState[1];
		var draftState = useState({}), drafts = draftState[0], setDrafts = draftState[1];
		var originalState = useState({}), originals = originalState[0], setOriginals = originalState[1];
		var busyState = useState({}), busy = busyState[0], setBusy = busyState[1];
		var progressState = useState({}), progress = progressState[0], setProgress = progressState[1];
		var noticeState = useState(null), notice = noticeState[0], setNotice = noticeState[1];
		var modalState = useState(null), modal = modalState[0], setModal = modalState[1];
		var confirmState = useState(null), confirmModal = confirmState[0], setConfirmModal = confirmState[1];
		var compatibilityState = useState(null), compatibilityModal = compatibilityState[0], setCompatibilityModal = compatibilityState[1];
		var onboardingState = useState(false), showOnboarding = onboardingState[0], setShowOnboarding = onboardingState[1];
		var loadErrorState = useState(''), loadError = loadErrorState[0], setLoadError = loadErrorState[1];
		var advancedSettingsState = useState(readAdvancedSettings()), showAdvancedSettings = advancedSettingsState[0], setShowAdvancedSettings = advancedSettingsState[1];

		function setAdvancedSettings(enabled) {
			var next = !!enabled;
			writeAdvancedSettings(next);
			setShowAdvancedSettings(next);
			if (!next && (tab === 'settings' || tab === 'exclusions')) {
				setTab('features');
			}
		}

		useEffect(function () { loadStatus(); }, []);
		useEffect(function () {
			function syncHash() { setActive(readHash()); }
			window.addEventListener('hashchange', syncHash);
			return function () { window.removeEventListener('hashchange', syncHash); };
		}, []);
		useEffect(function () {
			window.location.hash = active === 'overview' ? '' : active;
			document.title = sectionLabel(active) + ' — NBH Performance';
			setTab('features');
		}, [active]);
		useEffect(function () {
			if (data && active === 'enterprise' && !hasAgencyAccess(data)) {
				setActive('overview');
			}
		}, [data, active]);
		useEffect(function () {
			window.nbhPerformanceLicenseUrl = data && data.license && data.license.manage_url ? data.license.manage_url : '';
			return function () { window.nbhPerformanceLicenseUrl = ''; };
		}, [data]);

		useEffect(function () {
			document.body.classList.toggle('nbh-performance-onboarding-open', !!showOnboarding);
			return function () { document.body.classList.remove('nbh-performance-onboarding-open'); };
		}, [showOnboarding]);

		function markBusy(key, value) {
			setBusy(function (current) {
				var next = Object.assign({}, current);
				if (value) { next[key] = true; } else { delete next[key]; }
				return next;
			});
		}

		function startProgress(key) {
			window.nbhPerformanceProgressTimers = window.nbhPerformanceProgressTimers || {};
			window.clearInterval(window.nbhPerformanceProgressTimers[key]);
			setProgress(function (current) { var next = Object.assign({}, current); next[key] = 8; return next; });
			window.nbhPerformanceProgressTimers[key] = window.setInterval(function () {
				setProgress(function (current) {
					var next = Object.assign({}, current);
					var value = Number(next[key] || 8);
					next[key] = value >= 90 ? 90 : Math.min(90, value + Math.max(3, Math.round((100 - value) / 12)));
					return next;
				});
			}, 420);
		}

		function finishProgress(key) {
			window.nbhPerformanceProgressTimers = window.nbhPerformanceProgressTimers || {};
			window.clearInterval(window.nbhPerformanceProgressTimers[key]);
			delete window.nbhPerformanceProgressTimers[key];
			setProgress(function (current) { var next = Object.assign({}, current); next[key] = 100; return next; });
			window.setTimeout(function () {
				setProgress(function (current) { var next = Object.assign({}, current); delete next[key]; return next; });
			}, 500);
		}

		function notify(text, tone) {
			setNotice({ text: text, tone: tone || 'success' });
			window.clearTimeout(window.nbhPerformanceNoticeTimer);
			window.nbhPerformanceNoticeTimer = window.setTimeout(function () { setNotice(null); }, 4500);
		}

		function loadStatus(silent) {
			markBusy('load', true);
			setLoadError('');
			var timedOut = false;
			var timeoutId = window.setTimeout(function () {
				timedOut = true;
				setLoadError('The dashboard request timed out. Check the WordPress REST API, PHP error log, or a security plugin blocking /wp-json/.');
				markBusy('load', false);
			}, 20000);
			return apiFetch({ path: '/nbh-performance/v1/hub/status' }).then(function (response) {
				window.clearTimeout(timeoutId);
				if (timedOut) { return response; }
				var flat = flattenSettings(response.settings || {});
				setData(response);
				setDrafts(flat);
				setOriginals(flat);
				if (active === 'enterprise' && !hasAgencyAccess(response)) { setActive('overview'); }
				setShowOnboarding(!(response.onboarding && response.onboarding.completed));
				if (!silent) { notify('Dashboard synchronized.', 'success'); }
				return response;
			}).catch(function (error) {
				window.clearTimeout(timeoutId);
				var message = error && error.message ? error.message : 'Unable to load NBH Performance.';
				setLoadError(message);
				notify(message, 'error');
			}).then(function () { if (!timedOut) { markBusy('load', false); } });
		}

		function conflictFromError(error) {
			if (!error) { return null; }
			if (error.data && error.data.conflict) { return error.data.conflict; }
			if (error.conflict) { return error.conflict; }
			return null;
		}

		function submitToggle(option, checked) {
			var payload = {}; payload[option] = checked;
			markBusy(option, true);
			return apiFetch({ path: '/nbh-performance/v1/hub/toggles', method: 'POST', data: { values: payload } }).then(function (response) {
				setData(function (current) { return Object.assign({}, current || {}, { features: response.features }); });
				notify('Feature updated.', 'success');
				return response;
			}).catch(function (error) {
				var conflict = conflictFromError(error);
				if (conflict && checked) {
					setCompatibilityModal({ conflict: conflict, option: option, checked: checked });
					return null;
				}
				notify(error && error.message ? error.message : 'Feature could not be updated.', 'error');
				return null;
			}).then(function (response) { markBusy(option, false); return response; });
		}

		function toggle(option, checked) {
			return submitToggle(option, checked);
		}

		function resolveCompatibility(choice) {
			if (!compatibilityModal || !compatibilityModal.conflict) { return; }
			if (!choice || choice.resolution === 'cancel') {
				setCompatibilityModal(null);
				notify('NBH Performance left this feature off. The existing optimization remains unchanged.', 'info');
				return;
			}
			var conflict = compatibilityModal.conflict;
			var option = compatibilityModal.option;
			var checked = compatibilityModal.checked;
			markBusy('compatibility-resolution', true);
			apiFetch({
				path: '/nbh-performance/v1/compatibility/resolve',
				method: 'POST',
				data: {
					resolution: choice.resolution,
					plugin: conflict.plugin || '',
					groups: conflict.groups || []
				}
			}).then(function (response) {
				setCompatibilityModal(null);
				notify(response && response.message ? response.message : 'Compatibility conflict resolved.', 'success');
				return loadStatus(true);
			}).then(function () {
				return submitToggle(option, checked);
			}).catch(function (error) {
				notify(error && error.message ? error.message : 'The compatibility fix could not be completed.', 'error');
			}).then(function () { markBusy('compatibility-resolution', false); });
		}

		function saveSection(sectionId) {
			var section = data.settings[sectionId];
			var payload = {};
			Object.keys(section.fields || {}).forEach(function (key) { payload[key] = drafts[key]; });
			markBusy('save-' + sectionId, true);
			apiFetch({ path: '/nbh-performance/v1/hub/settings', method: 'POST', data: { values: payload } }).then(function (response) {
				var flat = flattenSettings(response.settings || {});
				setData(Object.assign({}, data, { settings: response.settings }));
				setDrafts(flat);
				setOriginals(flat);
				notify(section.label + ' settings saved.', 'success');
			}).catch(function (error) {
				notify(error.message || 'Settings could not be saved.', 'error');
			}).then(function () { markBusy('save-' + sectionId, false); });
		}

		function resetDrafts(sectionId, toDefaults) {
			var next = Object.assign({}, drafts);
			Object.keys(data.settings[sectionId].fields || {}).forEach(function (key) {
				next[key] = toDefaults ? data.settings[sectionId].fields[key].default : originals[key];
			});
			setDrafts(next);
			notify(toDefaults ? 'Defaults loaded. Save changes to apply.' : 'Unsaved changes discarded.', 'info');
		}

		function safeMode(enabled) {
			markBusy('safe-mode', true);
			apiFetch({ path: '/nbh-performance/v1/hub/safe-mode', method: 'POST', data: { enabled: enabled, restore: false } }).then(function (response) {
				var flat = flattenSettings(response.settings || {});
				setData(response); setDrafts(flat); setOriginals(flat);
				notify(enabled ? 'Safe Mode activated.' : 'Safe Mode turned off.', enabled ? 'warning' : 'success');
			}).catch(function (error) {
				notify(error.message || 'Safe Mode action failed.', 'error');
			}).then(function () { markBusy('safe-mode', false); });
		}

		function requestConfirmation(config) {
			return new Promise(function (resolve) {
				setConfirmModal({ config: config, resolve: resolve });
			});
		}

		function closeConfirmation(confirmed, payload) {
			if (confirmModal && typeof confirmModal.resolve === 'function') {
				if (confirmed) {
					confirmModal.resolve(payload && typeof payload === 'object' ? payload : true);
				} else {
					confirmModal.resolve(false);
				}
			}
			setConfirmModal(null);
		}

		function runAction(action, payload) {
			if (action === 'database_cleanup') { runDatabaseCleanup(); return; }
			if (action === 'database_optimize') { runDatabaseOptimize(); return; }
			if (action === 'database_backup') { runDatabaseBackup(); return; }
			var map = {
				purge_cache: { path: '/nbh-performance/v1/cache/purge', method: 'POST', data: payload || {}, success: 'Page cache purged.' },
				repair_cache: { path: '/nbh-performance/v1/cache/repair', method: 'POST', data: {}, success: 'Page cache repaired and warmed.' },
				purge_edge: { path: '/nbh-performance/v1/edge/purge', method: 'POST', data: payload || {}, success: 'Edge purge request completed.' },
				database_preview: { path: '/nbh-performance/v1/database/preview', method: 'GET', success: 'Database preview generated.' },
				diagnostics: { path: '/nbh-performance/v1/diagnostics/readiness', method: 'GET', success: 'Production readiness scan completed.' },
				support_report: { path: '/nbh-performance/v1/diagnostics/support-report', method: 'GET', success: 'Support report generated.' },
				performance_assessment: { path: '/nbh-performance/v1/performance/assessment', method: 'GET', success: 'Performance target assessment completed.' },
				performance_apply: { path: '/nbh-performance/v1/performance/apply', method: 'POST', data: payload || { mode: 'balanced' }, success: 'Automatic performance profile applied.' },
				smart_optimize_start: { path: '/nbh-performance/v1/smart-optimize/start', method: 'POST', data: payload || { mode: 'balanced' }, success: 'Smart Optimize started with validation and rollback protection.' },
				smart_optimize_report: { path: '/nbh-performance/v1/smart-optimize/report', method: 'GET', success: 'Smart Optimize impact report generated.' },
				smart_optimize_rollback: { path: '/nbh-performance/v1/smart-optimize/rollback', method: 'POST', data: {}, success: 'Smart Optimize changes rolled back.' },
				browser_engine_test: { path: '/nbh-performance/v1/browser-engine/test', method: 'POST', data: {}, success: 'PHP CSS engine check completed.' },
				browser_css_generate: { path: '/nbh-performance/v1/browser-engine/generate', method: 'POST', data: payload || {}, success: 'Homepage PHP CSS generation queued.' },
				priority_elements_status: { path: '/nbh-performance/v1/browser-engine/status', method: 'GET', success: 'Browser intelligence status loaded.' },
				support_bundle: { path: '/nbh-performance/v1/operations/support-bundle', method: 'POST', data: {}, success: 'Support bundle created.' },
				operations_logs_clear: { path: '/nbh-performance/v1/operations/logs/clear', method: 'POST', data: {}, success: 'Operational logs cleared.' },
				images_status: { path: '/nbh-performance/v1/images/status', method: 'GET', success: 'Image library scan completed.' },
				images_convert: { path: '/nbh-performance/v1/images/convert', method: 'POST', data: payload || { force: false }, success: 'Image conversion batch completed.' },
				config_snapshot: { path: '/nbh-performance/v1/hub/config/snapshot', method: 'POST', data: {}, success: 'Configuration snapshot created.' },
				config_restore: { path: '/nbh-performance/v1/hub/config/restore', method: 'POST', data: {}, success: 'Configuration snapshot restored.' },
				config_export: { path: '/nbh-performance/v1/hub/config/export', method: 'GET', success: 'Configuration exported.' },
				config_import: { path: '/nbh-performance/v1/hub/config/import', method: 'POST', data: payload || {}, success: 'Settings imported successfully.' },
				config_reset: { path: '/nbh-performance/v1/hub/config/reset', method: 'POST', data: {}, success: 'NBH Performance settings reset safely.' },
				recovery_stabilize: { path: '/nbh-performance/v1/hub/recovery/stabilize', method: 'POST', data: {}, success: 'Recovery Mode activated safely.' },
				recovery_resume: { path: '/nbh-performance/v1/hub/recovery/resume', method: 'POST', data: {}, success: 'Saved optimization configuration restored.' },
				compatibility_matrix: { path: '/nbh-performance/v1/compatibility/matrix/run', method: 'POST', data: {}, success: 'Compatibility test matrix completed.' },
				staging_validation: { path: '/nbh-performance/v1/validation/run', method: 'POST', data: {}, success: 'Frontend validation completed.' },
				jobs_run: { path: '/nbh-performance/v1/jobs/run', method: 'POST', data: { limit: 10 }, success: 'Background queue processed.' },
				jobs_pause: { path: '/nbh-performance/v1/jobs/pause', method: 'POST', data: {}, success: 'Background jobs paused.' },
				jobs_resume: { path: '/nbh-performance/v1/jobs/resume', method: 'POST', data: {}, success: 'Background jobs resumed.' },
				edge_webhook_test: { path: '/nbh-performance/v1/enterprise/edge/test', method: 'POST', data: {}, success: 'Edge purge webhook verified.' },
				cache_tags_purge: { path: '/nbh-performance/v1/enterprise/cache-tags/purge', method: 'POST', data: payload || { tags: ['site-wide'] }, success: 'Cache-tag invalidation queued.' },
				ai_token_rotate: { path: '/nbh-performance/v1/enterprise/ai/token', method: 'POST', data: payload || { scope: 'read' }, success: 'AI gateway token rotated.' },
				portfolio_report: { path: '/nbh-performance/v1/enterprise/report?format=html', method: 'GET', success: 'Portfolio report generated.' },
				agency_profile_save: { path: '/nbh-performance/v1/enterprise/profiles/save', method: 'POST', data: payload || {}, success: 'Reusable agency profile saved.' },
				preload_cache: { path: '/nbh-performance/v1/preload/start', method: 'POST', data: {}, success: 'Sitemap preload run started.' },
				create_cache: { path: '/nbh-performance/v1/cache/repair', method: 'POST', data: {}, success: 'Homepage cache created successfully.' }
			};
			var config = map[action];
			if (!config) { return; }
			markBusy(action, true);
			startProgress(action);
			apiFetch(config).then(function (response) {
				if (action === 'support_report') {
					downloadJson('nbh-performance-support-report.json', response);
				} else if (action === 'config_export') {
					downloadJson('nbh-performance-configuration.json', response);
				} else if (action === 'portfolio_report') {
					downloadJson('nbh-performance-portfolio-report.json', response);
				} else if (action === 'smart_optimize_report') {
					downloadJson('nbh-performance-performance-impact.json', response);
				} else if (action === 'account_connect' && response.url) {
					window.open(response.url, '_blank', 'noopener,noreferrer');
					setModal({ type: action, data: response });
				} else if (action === 'support_bundle' && response.download_url) {
					window.open(response.download_url, '_blank', 'noopener,noreferrer');
					setModal({ type: action, data: response });
				} else {
					setModal({ type: action, data: response });
				}
				notify(config.success, 'success');
				if (action === 'purge_cache' || action === 'repair_cache' || action === 'performance_apply' || action === 'smart_optimize_start' || action === 'smart_optimize_rollback' || action === 'agency_profile_save' || action === 'account_connect' || action === 'config_restore' || action === 'config_snapshot' || action === 'config_import' || action === 'config_reset' || action === 'compatibility_matrix' || action === 'staging_validation' || action === 'jobs_run' || action === 'jobs_pause' || action === 'jobs_resume' || action === 'cache_tags_purge' || action === 'ai_token_rotate' || action === 'edge_webhook_test' || action === 'images_status' || action === 'images_convert' || action === 'recovery_stabilize' || action === 'recovery_resume' || action === 'diagnostics' || action === 'performance_assessment' || action === 'support_bundle' || action === 'operations_logs_clear') { loadStatus(true); }
			}).catch(function (error) {
				notify(error.message || 'Action failed.', 'error');
			}).then(function () { finishProgress(action); markBusy(action, false); });
		}

		async function runDatabaseBackup() {
			markBusy('database_backup', true);
			startProgress('database_backup');
			notify('Creating and verifying a database backup…', 'info');
			apiFetch({ path: '/nbh-performance/v1/database/backup', method: 'POST', data: { provider: 'manual' } }).then(function (response) {
				setModal({ type: 'database_backup', data: response });
				notify('Verified database backup created.', 'success');
			}).catch(function (error) {
				notify(error.message || 'Database backup failed.', 'error');
			}).then(function () { finishProgress('database_backup'); markBusy('database_backup', false); });
		}

		async function runDatabaseCleanup() {
			var labels = databaseCleanupLabels();
			markBusy('database_cleanup', true);
			startProgress('database_cleanup');
			apiFetch({ path: '/nbh-performance/v1/database/preview', method: 'GET' }).then(async function (response) {
				var preview = response.preview || {};
				var available = Object.keys(labels).filter(function (key) { return Number(preview[key] || 0) > 0; });
				if (!available.length) {
					setModal({ type: 'database_cleanup', data: { message: 'Your database is already clean. No safe clutter was found.', preview: preview, deleted: {} } });
					return null;
				}
				var decision = await requestConfirmation({
					variant: 'warning',
					kicker: 'Before database cleanup',
					title: 'Remove this safe database clutter?',
					message: 'Choose which temporary WordPress clutter to remove. Posts, pages, users, orders and media stay untouched.',
					selectable: true,
					rows: available.map(function (key) {
						return { key: key, label: labels[key], value: String(Number(preview[key] || 0)), selected: true };
					}),
					summary: available.length + ' clutter types · ' + available.reduce(function (sum, key) { return sum + Number(preview[key] || 0); }, 0) + ' items',
					note: 'After you confirm, NBH Performance creates and verifies a private SQL backup first. Cleanup never starts if backup verification fails. Large cleanups run in safe batches until finished.',
					confirmText: 'Yes, clean selected items',
					cancelText: 'No, keep everything',
					icon: 'broom'
				});
				if (!decision) { return null; }
				var operations = Array.isArray(decision.selected) ? decision.selected.filter(function (key) { return available.indexOf(key) !== -1; }) : available;
				if (!operations.length) { throw new Error('Select at least one clutter type to clean.'); }

				notify('Creating and verifying a database backup…', 'info');
				var confirmation = await apiFetch({ path: '/nbh-performance/v1/database/confirm', method: 'POST', data: { operations: operations } });
				if (!confirmation || !confirmation.backup_id || !confirmation.confirmation_token) {
					throw new Error('The verified backup response was incomplete. No database changes were made.');
				}

				var totals = {};
				var rounds = 0;
				var maxRounds = 20;
				var lastResult = null;
				var backupId = confirmation.backup_id;
				var token = confirmation.confirmation_token;
				var backup = confirmation.backup || null;

				notify('Verified backup ready. Cleaning database in safe batches…', 'success');
				do {
					rounds += 1;
					notify('Cleaning database batch ' + rounds + '…', 'info');
					lastResult = await apiFetch({
						path: '/nbh-performance/v1/database/cleanup',
						method: 'POST',
						data: { operations: operations, backup_id: backupId, confirmation_token: token }
					});
					Object.keys(lastResult.deleted || {}).forEach(function (key) {
						totals[key] = Number(totals[key] || 0) + Number(lastResult.deleted[key] || 0);
					});
					if (!lastResult.repeat_required || rounds >= maxRounds) { break; }
					var next = await apiFetch({ path: '/nbh-performance/v1/database/confirm', method: 'POST', data: { operations: operations, backup_id: backupId } });
					if (!next || !next.confirmation_token) { throw new Error('Could not continue the cleanup batches safely. Partial progress was kept.'); }
					token = next.confirmation_token;
					backupId = next.backup_id || backupId;
					backup = next.backup || backup;
				} while (rounds < maxRounds);

				lastResult = lastResult || {};
				lastResult.deleted = totals;
				lastResult.backup = backup;
				lastResult.backup_id = backupId;
				lastResult.rounds = rounds;
				lastResult.repeat_required = !!(lastResult.repeat_required && rounds >= maxRounds);
				if (lastResult.repeat_required) {
					lastResult.message = 'Safe batch limit reached. Run cleanup again to continue removing remaining clutter.';
				}
				return lastResult;
			}).then(function (result) {
				if (!result) { return; }
				setModal({ type: 'database_cleanup', data: result });
				notify(result.repeat_required ? 'Cleanup paused safely after multiple batches.' : 'Database cleanup completed safely.', result.repeat_required ? 'warning' : 'success');
			}).catch(function (error) {
				notify(error.message || 'Database cleanup failed.', 'error');
			}).then(function () { finishProgress('database_cleanup'); markBusy('database_cleanup', false); });
		}

		async function runDatabaseOptimize() {
			var confirmed = await requestConfirmation({
				variant: 'info',
				kicker: 'Before table optimization',
				title: 'Optimize WordPress database tables now?',
				message: 'NBH Performance will optimize only WordPress-related tables to keep the database efficient. Non-WordPress tables stay untouched.',
				note: 'After you confirm, NBH Performance automatically creates and verifies a fresh private SQL backup. Optimization starts only after that verification succeeds.',
				confirmText: 'Yes, optimize tables',
				cancelText: 'Not now',
				icon: 'database'
			});
			if (!confirmed) { return; }
			markBusy('database_optimize', true);
			startProgress('database_optimize');
			notify('Creating and verifying a database backup…', 'info');
			apiFetch({ path: '/nbh-performance/v1/database/confirm', method: 'POST', data: { operations: ['optimize_tables'] } }).then(function (confirmation) {
				if (!confirmation || !confirmation.backup_id || !confirmation.confirmation_token) { throw new Error('The verified backup response was incomplete. No database tables were changed.'); }
				notify('Verified backup created. Optimizing database tables…', 'success');
				return apiFetch({ path: '/nbh-performance/v1/database/optimize', method: 'POST', data: { backup_id: confirmation.backup_id, confirmation_token: confirmation.confirmation_token, tables: [] } }).then(function (optimizeResult) {
					optimizeResult = optimizeResult || {};
					optimizeResult.backup = confirmation.backup || null;
					return optimizeResult;
				});
			}).then(function (result) {
				setModal({ type: 'database_optimize', data: result });
				notify('Database table optimization completed.', 'success');
			}).catch(function (error) {
				notify(error.message || 'Database optimization failed.', 'error');
			}).then(function () { finishProgress('database_optimize'); markBusy('database_optimize', false); });
		}


		function completeOnboarding(payload) {
			markBusy('onboarding', true);
			return apiFetch({ path: '/nbh-performance/v1/hub/onboarding', method: 'POST', data: payload }).then(function (response) {
				var flat = flattenSettings(response.settings || {});
				setData(response); setDrafts(flat); setOriginals(flat); setShowOnboarding(false);
				setActive('overview'); setTab('features'); window.location.hash = ''; window.scrollTo({ top: 0, behavior: 'smooth' });
				notify(payload.skip ? 'Dashboard ready — run Smart Optimize next for your first measured win.' : 'NBH Performance is live. Run Smart Optimize next to capture your first measured win.', 'success');
			}).catch(function (error) {
				notify(error.message || 'Onboarding could not be completed.', 'error');
			}).then(function () { markBusy('onboarding', false); });
		}

		function reopenOnboarding() { setShowOnboarding(true); }

		var dirtySections = useMemo(function () { return calculateDirty(data, drafts, originals); }, [data, drafts, originals]);
		useEffect(function () {
			function warn(event) { if (Object.keys(dirtySections).some(function (key) { return !!dirtySections[key]; })) { event.preventDefault(); event.returnValue = ''; } }
			window.addEventListener('beforeunload', warn);
			return function () { window.removeEventListener('beforeunload', warn); };
		}, [dirtySections]);

		if (!data) {
			if (loadError) {
				return el('div', { className: 'np-app-loading np-app-load-error' },
					el('span', { className: 'np-load-error-icon', 'aria-hidden': 'true' }, Icon('warning')),
					el('strong', null, 'Dashboard unavailable'),
					el('p', null, loadError),
					el('button', { className: 'np-button np-button-primary', onClick: function () { loadStatus(false); } }, 'Try again'),
					el('p', { className: 'np-load-error-help' }, 'Check Tools → Site Health or wp-content/debug.log if the problem continues.')
				);
			}
			return el('div', { className: 'np-app-loading' }, el('span', { className: 'np-loader' }), el('strong', null, 'Loading dashboard'), el('p', null, 'One moment…'));
		}

		return el('div', { className: 'np-shell np-theme-v2' + (showOnboarding ? ' is-onboarding' : '') },
			el('a', { className: 'np-skip-link', href: '#np-main-content' }, 'Skip to main content'),
			showOnboarding ? null : el(Sidebar, { active: active, setActive: setActive, data: data }),
			el('main', { className: 'np-main' + (showOnboarding ? ' is-onboarding' : ''), id: 'np-main-content', tabIndex: -1 },
				showOnboarding ? null : el(Topbar, { active: active, data: data, busy: busy, safeMode: safeMode, refresh: function () { loadStatus(false); }, onboarding: reopenOnboarding, runAction: runAction, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings }),
				notice ? el('div', { className: 'np-toast is-' + notice.tone, role: 'status' }, Icon(notice.tone === 'error' ? 'warning' : 'check', 'np-toast-icon'), notice.text) : null,
				showOnboarding ? el(OnboardingWizard, { busy: !!busy.onboarding, recommendedProfile: data.onboarding && data.onboarding.recommended_profile ? data.onboarding.recommended_profile : 'business', siteName: (window.nbhPerformanceHub && window.nbhPerformanceHub.siteName) || '', complete: completeOnboarding, close: data.onboarding && data.onboarding.completed ? function () { setShowOnboarding(false); setActive('overview'); } : null }) : el(wp.element.Fragment, null,
					active === 'overview' ? el(Overview, { data: data, setActive: setActive, runAction: runAction, busy: busy, confirmAction: requestConfirmation, refresh: function () { return loadStatus(true); }, notify: notify }) : null,
					active === 'insights' ? el(PerformanceInsightsView, { notify: notify }) : null,
					active === 'cdn' ? el(CdnView, { data: data, notify: notify, toggle: toggle, busy: busy || {}, confirmAction: requestConfirmation }) : null,
					active === 'optimization' ? el(OptimizationView, { data: data, notify: notify, toggle: toggle, busy: busy || {}, confirmAction: requestConfirmation, runAction: runAction, setActive: setActive, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings, drafts: drafts, setDrafts: setDrafts, save: saveSection, reset: resetDrafts, dirty: !!dirtySections.optimization }) : null,
					active === 'preload' ? el(PreloadView, { data: data, notify: notify, toggle: toggle, busy: busy || {}, confirmAction: requestConfirmation, runAction: runAction, refresh: function () { return loadStatus(true); }, setActive: setActive, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings, drafts: drafts, setDrafts: setDrafts, save: saveSection, reset: resetDrafts, dirty: !!dirtySections.preload }) : null,
					active === 'tools' ? el(ToolsView, { data: data, notify: notify, toggle: toggle, busy: busy || {}, progress: progress || {}, confirmAction: requestConfirmation, runAction: runAction, refresh: function () { return loadStatus(true); }, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings, drafts: drafts, setDrafts: setDrafts, save: saveSection, reset: resetDrafts, dirty: !!dirtySections.tools }) : null,
					active === 'database' ? el(DatabaseView, { data: data, notify: notify, toggle: toggle, busy: busy || {}, progress: progress || {}, confirmAction: requestConfirmation, runAction: runAction, refresh: function () { return loadStatus(true); }, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings, drafts: drafts, setDrafts: setDrafts, save: saveSection, reset: resetDrafts, dirty: !!dirtySections.database }) : null,
					active === 'caching' ? el(PageCacheView, { data: data, notify: notify, toggle: toggle, busy: busy || {}, progress: progress || {}, confirmAction: requestConfirmation, runAction: runAction, refresh: function () { return loadStatus(true); }, setActive: setActive, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings, drafts: drafts, setDrafts: setDrafts, save: saveSection, reset: resetDrafts, dirty: !!dirtySections.caching }) : null,
					active === 'media' ? el(MediaView, { data: data, notify: notify, toggle: toggle, busy: busy || {}, confirmAction: requestConfirmation, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings, drafts: drafts, setDrafts: setDrafts, save: saveSection, reset: resetDrafts, dirty: !!dirtySections.media }) : null,
					active === 'images' ? el(ImagesView, { data: data, notify: notify, toggle: toggle, busy: busy || {}, confirmAction: requestConfirmation, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings, drafts: drafts, setDrafts: setDrafts, save: saveSection, reset: resetDrafts, dirty: !!dirtySections.images }) : null,
					active === 'script_manager' ? el(ScriptManagerView, { data: data, notify: notify, toggle: toggle, busy: busy || {}, confirmAction: requestConfirmation, refresh: function () { return loadStatus(true); }, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings, drafts: drafts, setDrafts: setDrafts, save: saveSection, reset: resetDrafts, dirty: !!dirtySections.script_manager, setActive: setActive }) : null,
					data.features[active] && data.settings[active] && active !== 'cdn' && active !== 'images' && active !== 'media' && active !== 'optimization' && active !== 'tools' && active !== 'script_manager' && active !== 'preload' && active !== 'database' && active !== 'caching' ? el(SettingsSection, { id: active, featureGroup: data.features[active], settings: data.settings[active], dashboardData: data, drafts: drafts, setDrafts: setDrafts, tab: tab, setTab: setTab, dirty: !!dirtySections[active], busy: busy || {}, progress: progress || {}, toggle: toggle, save: saveSection, reset: resetDrafts, safeMode: data.safe_mode.enabled, reliability: data.reliability || {}, runAction: runAction, confirmAction: requestConfirmation, notify: notify, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings }) : (active !== 'overview' && active !== 'insights' && active !== 'object_cache' && active !== 'license' && active !== 'help' && active !== 'cdn' && active !== 'images' && active !== 'media' && active !== 'optimization' && active !== 'tools' && active !== 'script_manager' && active !== 'preload' && active !== 'database' && active !== 'caching' ? el(SectionRecoveryState, { active: active, data: data, retry: function () { loadStatus(false); } }) : null),
					active === 'object_cache' ? el(ObjectCacheView, { notify: notify, confirmAction: requestConfirmation, setActive: setActive, showAdvancedSettings: showAdvancedSettings, setAdvancedSettings: setAdvancedSettings, refresh: function () { return loadStatus(true); } }) : null,
					active === 'license' ? el(AboutView, { data: data, setActive: setActive }) : null,
					active === 'help' ? el(HelpView, { setActive: setActive }) : null
				)
			),
			modal ? el(ResultModal, { modal: modal, close: function () { setModal(null); } }) : null,
			confirmModal ? el(ConfirmModal, { modal: confirmModal.config, cancel: function () { closeConfirmation(false); }, confirm: function (payload) { closeConfirmation(true, payload); } }) : null,
			compatibilityModal ? el(CompatibilityModal, {
				modal: compatibilityModal,
				busy: !!busy['compatibility-resolution'],
				cancel: function () { resolveCompatibility({ resolution: 'cancel' }); },
				resolve: resolveCompatibility
			}) : null
		);
	}

	function SectionRecoveryState(props) {
		var detail = props.data && props.data.dashboard_error && props.data.dashboard_error.detail ? props.data.dashboard_error.detail : '';
		return el('div', { className: 'np-view' },
			el('section', { className: 'np-panel np-section-recovery', role: 'alert' },
				el('span', { className: 'np-section-recovery-icon' }, Icon('warning')),
				el('div', null,
					el('strong', null, sectionLabel(props.active) + ' is unavailable'),
					el('p', null, 'Nothing was changed. Try again or check the WordPress debug log.'),
					detail ? el('code', null, detail) : null
				),
				el('button', { className: 'np-button np-button-primary', onClick: props.retry }, Icon('refresh'), 'Try Again')
			)
		);
	}


	function Sidebar(props) {
		var iconUrl = window.nbhPerformanceHub && window.nbhPerformanceHub.iconUrl;
		return el('aside', { className: 'np-sidebar' },
			el('button', { className: 'np-brand', onClick: function () { props.setActive('overview'); } },
				el('span', { className: 'np-brand-mark', 'aria-hidden': 'true' }, iconUrl ? el('img', { className: 'np-brand-image', src: iconUrl, alt: '' }) : Icon('layers', 'np-brand-icon')),
				el('span', { className: 'np-brand-copy' }, el('strong', null, 'NBH Performance'), el('small', null, 'Performance & Reliability'))
			),
			el('div', { className: 'np-sidebar-divider', 'aria-hidden': 'true' }),
			el('nav', { className: 'np-nav', 'aria-label': 'NBH Performance sections' }, navGroups(props.data).map(function (group) {
				return el('div', { className: 'np-nav-group', key: group.label },
					el('p', { className: 'np-nav-heading' }, group.label),
					group.items.map(function (item) {
						return el('button', { key: item.id, className: 'np-nav-item' + (!item.external && props.active === item.id ? ' is-active' : ''), 'aria-current': !item.external && props.active === item.id ? 'page' : undefined, onClick: function () {
							if (item.external) { window.location.href = (window.nbhPerformanceHub && window.nbhPerformanceHub.scriptManagerUrl) || 'admin.php?page=nbh-performance-script-manager'; return; }
							props.setActive(item.id);
						} }, Icon(item.icon, 'np-nav-icon'), el('span', null, item.label));
					})
				);
			}))
		);
	}

	function Topbar(props) {
		if (props.active === 'license') {
			return el('header', { className: 'np-topbar' }, el('div', { className: 'np-topbar-copy' }, el('h1', null, 'About'), el('p', { className: 'np-topbar-description' }, SECTION_META.license)));
		}
		var isCache = props.active === 'caching';
		var isHelp = props.active === 'help';
		var noAdvanced = { overview: 1, insights: 1, license: 1, help: 1, cdn: 1 };
		var showSettingsToggle = !noAdvanced[props.active];
		return el('header', { className: 'np-topbar' + (isCache ? ' np-topbar-cache' : '') },
			el('div', { className: 'np-topbar-copy' },
				el('h1', null, isCache ? 'Page Cache' : sectionLabel(props.active)),
				el('p', { className: 'np-topbar-description' }, SECTION_META[props.active] || 'Manage NBH Performance safely and clearly.')
			),
			el('div', { className: 'np-topbar-actions' },
				!isHelp ? el('button', { className: 'np-icon-button', title: 'Refresh dashboard', disabled: !!props.busy.load, onClick: props.refresh }, Icon('refresh')) : null,
				showSettingsToggle ? el('button', { className: 'np-button ' + (props.showAdvancedSettings ? 'np-button-ghost' : 'np-button-ghost np-button-blue'), onClick: function () { props.setAdvancedSettings(!props.showAdvancedSettings); } }, Icon('sliders'), props.showAdvancedSettings ? 'Hide settings' : 'Advanced') : null,
				isCache ? el('button', { className: 'np-button np-button-ghost np-button-blue', disabled: !!props.busy.purge_cache, onClick: function () { props.runAction('purge_cache'); } }, Icon('trash'), props.busy.purge_cache ? 'Purging…' : 'Purge cache') : null,
				!isCache && !isHelp ? el('button', { className: 'np-button np-button-ghost np-setup-button', onClick: props.onboarding }, Icon('setup'), props.active === 'overview' ? 'Guided setup' : 'Setup') : null,
				!isHelp ? el('span', { className: 'np-engine-status ' + (props.data.safe_mode.enabled ? 'is-safe' : 'is-live') }, Icon(props.data.safe_mode.enabled ? 'shield' : 'check'), props.data.safe_mode.enabled ? 'Safe Mode' : 'Healthy') : null,
				!isCache && !isHelp ? el('button', { className: 'np-button ' + (props.data.safe_mode.enabled ? 'np-button-primary' : 'np-button-ghost'), disabled: !!props.busy['safe-mode'], onClick: function () { props.safeMode(!props.data.safe_mode.enabled); } }, Icon(props.data.safe_mode.enabled ? 'check' : 'shield'), props.busy['safe-mode'] ? 'Working…' : (props.data.safe_mode.enabled ? (props.active === 'overview' ? 'Resume normal mode' : 'Exit Safe Mode') : (props.active === 'overview' ? 'Protection' : 'Safe Mode'))) : null
			)
		);
	}

	function EnvironmentNotice(data) {
		var env = data && data.environment ? data.environment : {};
		if (!env.is_multisite || !env.page_cache_message) { return null; }
		return el('div', { className: 'np-env-banner', role: 'status' },
			el('span', { className: 'np-env-banner-icon' }, Icon('warning')),
			el('div', null,
				el('strong', null, 'Single-site page cache'),
				el('p', null, env.page_cache_message)
			)
		);
	}

	function Overview(props) {
		var data = props.data;
		var phaseC = data.phase_c || {};
		var smartRun = phaseC.smart_optimize || {};
		var proof = phaseC.proof || {};
		var conflicts = Array.isArray(data.conflicts) ? data.conflicts : [];
		var cache = data.cache || {};
		var cacheHealth = data.cache_health || {};
		var jobs = (data.reliability && data.reliability.jobs) || {};
		var enabled = countEnabled(data.features);
		var safeMode = !!(data.safe_mode && data.safe_mode.enabled);
		var smartStatus = String(smartRun.status || 'not_started');
		var smartActive = smartStatus === 'applying' || smartStatus === 'validating' || smartStatus === 'measuring';
		var cacheReady = !!cacheHealth.runtime_readable && !!cacheHealth.wp_cache && !!cacheHealth.dropin_owned && !!cacheHealth.enabled && !safeMode;
		var cacheOn = !!cacheHealth.enabled;
		var dropinConflict = !!cacheHealth.dropin_exists && !cacheHealth.dropin_owned;
		var failedJobs = Number(jobs.failed || 0);
		var needsAttention = !!(conflicts.length || dropinConflict || (cacheOn && !cacheReady) || failedJobs > 0 || smartStatus === 'needs_rollback');
		var anyBusy = !!(props.busy && (props.busy.smart_optimize_start || props.busy.performance_assessment || props.busy.diagnostics || props.busy.purge_cache || props.busy.support_report || props.busy.smart_optimize_rollback));

		var heroTone = safeMode || needsAttention ? 'warning' : (smartActive ? 'muted' : (cacheReady || enabled > 0 ? 'good' : 'muted'));
		var heroLabel = safeMode ? 'Protection mode' : (smartActive ? 'Optimization running' : (needsAttention ? 'Needs attention' : 'Everything looks good'));
		var heroTitle = safeMode ? 'Your site is in protection mode' : (smartActive ? 'Your site is being optimized' : (needsAttention ? 'One item needs your attention' : 'Your site is running smoothly'));

		var nextAction = null;
		if (safeMode) {
			nextAction = { title: 'Review protection mode', copy: 'Some speed features are paused to keep the site safe. Open Tools when you are ready to review them.', cta: 'Open Tools', icon: 'shield', go: 'tools' };
		} else if (conflicts.length) {
			nextAction = { title: 'Review one setup issue', copy: conflicts[0].message || conflicts[0].title || 'Another plugin may be using the same performance feature.', cta: 'See issue', icon: 'warning', go: null, scroll: 'np-overview-issues' };
		} else if (dropinConflict || (cacheOn && !cacheReady)) {
			nextAction = { title: 'Finish setting up Page Cache', copy: 'Page Cache is switched on but needs one quick repair before it can serve saved pages.', cta: 'Fix Page Cache', icon: 'layers', go: 'caching' };
		} else if (proof.show_first_win_cta || smartStatus === 'not_started' || !smartRun.started_at) {
			nextAction = { title: 'Optimize your site safely', copy: 'Balanced mode applies recommended improvements and creates a restore point first.', cta: 'Optimize my site', icon: 'magic', action: 'smart_optimize' };
		} else if (failedJobs > 0) {
			nextAction = { title: 'Review background tasks', copy: failedJobs + ' background task' + (failedJobs === 1 ? ' needs' : 's need') + ' attention. Your site can continue running.', cta: 'Review tasks', icon: 'wrench', go: 'tools' };
		} else {
			nextAction = { title: 'No urgent action needed', copy: 'Your main performance features are working. You can check the latest speed results whenever you like.', cta: 'View performance', icon: 'chart', go: 'insights' };
		}

		function runSmartBalanced() {
			props.confirmAction({
				variant: 'info',
				kicker: 'Before Smart Optimize',
				title: 'Run the Balanced Smart Optimize plan?',
				message: 'NBH Performance will measure the current state, apply safe high-impact improvements, rebuild the cache and validate important pages.',
				note: 'A recovery point is created first so you can roll back if needed.',
				confirmText: 'Yes, run Balanced',
				cancelText: 'Cancel',
				icon: 'magic'
			}).then(function (confirmed) { if (confirmed) { props.runAction('smart_optimize_start', { mode: 'balanced' }); } });
		}

		function followNext() {
			if (!nextAction) { return; }
			if (nextAction.action === 'smart_optimize') { runSmartBalanced(); return; }
			if (nextAction.go) { props.setActive(nextAction.go); return; }
			if (nextAction.scroll) {
				var node = document.getElementById(nextAction.scroll);
				if (node && node.scrollIntoView) { node.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
			}
		}

		function DashCard(icon, title, description, onClick, cta, loading, workingLabel) {
			return el('article', { className: 'np-overview-action-card' + (loading ? ' is-working' : '') },
				el('div', { className: 'np-overview-action-top' },
					el('span', { className: 'np-overview-action-icon' }, Icon(icon)),
					el('div', null, el('strong', null, title), el('p', null, description))
				),
				el('button', {
					type: 'button',
					className: 'np-button np-button-primary np-overview-action-cta',
					disabled: !!loading || anyBusy,
					onClick: onClick
				}, loading ? el('span', { className: 'np-spinner' }) : Icon(icon), loading ? (workingLabel || 'Working…') : cta)
			);
		}

		return el('div', { className: 'np-view np-view-overview np-overview-view' },
			EnvironmentNotice(data),
			el('section', { className: 'np-panel np-overview-hero' },
				el('div', { className: 'np-overview-hero-copy' },
					el('span', { className: 'np-kicker' }, 'Welcome'),
					el('h3', null, heroTitle),
					el('p', null, 'NBH Performance handles the technical work. Start with the recommended action below, or leave the site as it is when everything looks good.')
				),
				el('div', { className: 'np-overview-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: !!props.busy.load, onClick: function () { if (typeof props.refresh === 'function') { props.refresh(); } } }, Icon('refresh'), props.busy.load ? 'Refreshing…' : 'Refresh'),
					el('span', { className: 'np-quality-pill is-' + heroTone }, heroLabel)
				)
			),
			safeMode ? el('div', { className: 'np-object-alert is-warning' }, Icon('shield'), el('div', null, el('strong', null, 'Safe Mode is active'), el('p', null, 'Advanced optimizations are paused. Exit Safe Mode from the top bar when the site is stable.'))) : null,
			dropinConflict ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'Page Cache needs attention'), el('p', null, 'Another plugin is managing the cache file. Open Page Cache for guided repair steps.'))) : null,
			el('section', { className: 'np-panel np-overview-next' },
				el('div', { className: 'np-overview-next-copy' },
					el('span', { className: 'np-kicker' }, 'What to do next'),
					el('h3', null, nextAction.title),
					el('p', null, nextAction.copy)
				),
				el('button', { className: 'np-button np-button-primary', disabled: anyBusy, onClick: followNext }, Icon(nextAction.icon), nextAction.cta)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Quick actions', description: 'Three safe actions cover most everyday needs.' }),
				el('div', { className: 'np-overview-action-grid np-beginner-action-grid' },
					DashCard('magic', 'Optimize my site', 'Apply the recommended Balanced plan. A restore point is created first.', runSmartBalanced, 'Optimize safely', !!props.busy.smart_optimize_start, 'Starting…'),
					DashCard('trash', 'Clear saved pages', 'Use this after changing content or design. Pages rebuild automatically.', function () {
						props.confirmAction({
							variant: 'warning',
							kicker: 'Clear saved pages',
							title: 'Purge all page cache?',
							message: 'Every saved HTML copy will be deleted. Pages rebuild on the next visit.',
							note: 'Safe for production. Visitors may see a brief miss until pages regenerate.',
							confirmText: 'Purge cache',
							cancelText: 'Keep cache',
							icon: 'trash'
						}).then(function (confirmed) { if (confirmed) { props.runAction('purge_cache'); } });
					}, 'Clear cache', !!props.busy.purge_cache, 'Clearing…'),
					DashCard('shield', 'Check for problems', 'Run a safe check without changing your site.', function () { props.runAction('diagnostics'); }, 'Run check', !!props.busy.diagnostics, 'Checking…')
				)
			),
			conflicts.length ? el('section', { className: 'np-panel', id: 'np-overview-issues' },
				el(PanelHeader, { title: 'Needs attention', description: 'Review these items before turning on overlapping performance features.' }),
				el('div', { className: 'np-conflict-list' }, conflicts.slice(0, 6).map(CompatibilityIssue))
			) : null,
			el('details', { className: 'np-panel np-overview-advanced' },
				el('summary', null,
					el('span', { className: 'np-overview-advanced-icon' }, Icon('sliders')),
					el('span', null, el('strong', null, 'Advanced details'), el('small', null, 'Optimization modes, technical status and reports')),
					Icon('arrow', 'np-overview-advanced-arrow')
				),
				el('div', { className: 'np-overview-advanced-content' },
					el('section', { className: 'np-overview-simple-status' },
						el('article', null, el('small', null, 'Site mode'), el('strong', null, safeMode ? 'Protection mode' : 'Live')),
						el('article', null, el('small', null, 'Saved pages'), el('strong', null, cacheReady ? String(Number(cache.files || 0)) : (cacheOn ? 'Not ready' : 'Off'))),
						el('article', null, el('small', null, 'Active features'), el('strong', null, String(enabled))),
						el('article', null, el('small', null, 'Setup issues'), el('strong', null, String(conflicts.length)))
					),
					el(SmartOptimizePanel, { run: smartRun, rollback: phaseC.automatic_rollback || {}, proof: proof, busy: props.busy, runAction: props.runAction, confirmAction: props.confirmAction, refresh: props.refresh, notify: props.notify }),
					el(ImpactProofCard, { proof: proof, run: smartRun, runAction: props.runAction, busy: props.busy })
				)
			)
		);
	}

	function HelpView(props) {
		var queryState = useState(''), query = queryState[0], setQuery = queryState[1];
		var guides = [
			{
				id: 'getting-started', icon: 'rocket', title: 'Getting started', description: 'The safest first setup for a new site.', target: 'overview', cta: 'Open Dashboard',
				steps: [
					'Open Dashboard and follow the single recommended action.',
					'Run Guided setup if this is the first time NBH Performance is being configured.',
					'Use Balanced optimization first. A restore point is created before changes.',
					'Check the public site after optimization, especially forms, cart and checkout pages.'
				]
			},
			{
				id: 'page-cache', icon: 'layers', title: 'Page Cache', description: 'Serve saved pages safely and know when to clear them.', target: 'caching', cta: 'Open Page Cache',
				steps: [
					'Turn Page Cache on and confirm the status says it is serving cache.',
					'Clear saved pages after changing content, theme layout or important settings.',
					'Cart, checkout, logged-in and other personalized pages should stay excluded.',
					'If another cache plugin owns the drop-in, follow the guided repair message before continuing.'
				]
			},
			{
				id: 'smart-optimize', icon: 'magic', title: 'Smart Optimize', description: 'Apply recommended improvements with a recovery point.', target: 'tools', cta: 'Open Tools',
				steps: [
					'Start with Balanced mode for the best mix of speed and compatibility.',
					'NBH Performance saves the current configuration before applying changes.',
					'Allow the validation steps to finish before making more performance changes.',
					'Use rollback from Tools if a verified layout or interaction problem appears.'
				]
			},
			{
				id: 'css-javascript', icon: 'sliders', title: 'CSS & JavaScript', description: 'Fine-tune frontend files without breaking interactions.', target: 'optimization', cta: 'Open CSS & JavaScript',
				steps: [
					'Enable one major optimization at a time when configuring manually.',
					'Test menus, forms, popups, sliders, cart and checkout after each change.',
					'Add a precise exclusion when one script or stylesheet must remain untouched.',
					'Clear saved pages after changing CSS or JavaScript optimization settings.'
				]
			},
			{
				id: 'images-media', icon: 'image', title: 'Images & media', description: 'Optimize images, lazy loading and embedded media.', target: 'images', cta: 'Open Image Conversion',
				steps: [
					'Keep original images; generated WebP or AVIF files are separate variants.',
					'Process a small image batch first and review several public pages.',
					'Exclude logos, animations or protected media only when they need special handling.',
					'Do not lazy-load the main above-the-fold image when it is the page LCP image.'
				]
			},
			{
				id: 'database', icon: 'database', title: 'Database cleanup', description: 'Preview and remove safe clutter without touching site content.', target: 'database', cta: 'Open Database Cleanup',
				steps: [
					'Always review the preview counts before starting cleanup.',
					'Confirm that the private SQL backup is available.',
					'Cleanup runs in controlled batches and should not remove published content, customers or orders.',
					'Run cleanup during a quieter period on a large or busy site.'
				]
			},
			{
				id: 'license', icon: 'shield', title: 'About NBH Performance', description: 'Meet the plugin, its purpose and the people behind it.', target: 'license', cta: 'About the plugin',
				steps: [
					'All bundled features are free under GPL version 2 or later.',
					'No account, license key or email verification is required.',
					'Optional external integrations need your own configuration.',
					'Plugin updates appear in the standard WordPress Updates screen.'
				]
			},
			{
				id: 'troubleshooting', icon: 'wrench', title: 'Troubleshooting', description: 'A simple order for diagnosing a problem.', target: 'tools', cta: 'Open Tools',
				steps: [
					'Run Check for problems from Dashboard or Tools.',
					'Turn on Protection mode if the frontend is unstable while you investigate.',
					'Clear saved pages and test again in a private browser window.',
					'Download the privacy-safe support report when technical help is required.'
				]
			}
		];
		var normalized = String(query || '').trim().toLowerCase();
		var visibleGuides = guides.filter(function (guide) {
			if (!normalized) { return true; }
			return [guide.title, guide.description].concat(guide.steps).join(' ').toLowerCase().indexOf(normalized) !== -1;
		});

		return el('div', { className: 'np-view np-help-view' },
			el('section', { className: 'np-panel np-help-hero' },
				el('div', { className: 'np-help-hero-copy' },
					el('span', { className: 'np-help-hero-icon' }, Icon('info')),
					el('div', null,
						el('span', { className: 'np-kicker' }, 'Built-in help'),
						el('h3', null, 'Clear answers without leaving WordPress'),
						el('p', null, 'Search the guides or open a topic below. Instructions are written for site owners and beginners.')
					)
				),
				el('label', { className: 'np-help-search' },
					Icon('search'),
					el('span', { className: 'screen-reader-text' }, 'Search help guides'),
					el('input', { type: 'search', value: query, placeholder: 'Search help…', 'aria-label': 'Search help guides', onChange: function (event) { setQuery(event.target.value); } })
				)
			),
			el('section', { className: 'np-panel np-help-start' },
				el(PanelHeader, { title: 'Start here', description: 'A safe three-step path for a newly installed site.' }),
				el('div', { className: 'np-help-start-grid' },
					el('article', null, el('span', null, '1'), el('div', null, el('strong', null, 'Run Guided setup'), el('p', null, 'Choose the site profile and apply a safe starting configuration.'))),
					el('article', null, el('span', null, '2'), el('div', null, el('strong', null, 'Use Balanced mode'), el('p', null, 'Apply recommended improvements with a restore point.'))),
					el('article', null, el('span', null, '3'), el('div', null, el('strong', null, 'Review the public site'), el('p', null, 'Check important pages, forms and purchase flows.')))
				)
			),
			el('section', { className: 'np-panel np-help-library' },
				el(PanelHeader, { title: 'Help library', description: normalized ? (visibleGuides.length + ' matching guide' + (visibleGuides.length === 1 ? '' : 's')) : 'Setup, optimization, maintenance, licensing and troubleshooting.' }),
				visibleGuides.length ? el('div', { className: 'np-help-guide-grid' }, visibleGuides.map(function (guide) {
					return el('details', { className: 'np-help-guide', key: guide.id },
						el('summary', null,
							el('span', { className: 'np-help-guide-icon' }, Icon(guide.icon)),
							el('span', { className: 'np-help-guide-copy' }, el('strong', null, guide.title), el('small', null, guide.description)),
							Icon('arrow', 'np-help-guide-arrow')
						),
						el('div', { className: 'np-help-guide-body' },
							el('ol', null, guide.steps.map(function (step, index) { return el('li', { key: guide.id + '-' + index }, step); })),
							el('button', { type: 'button', className: 'np-button np-button-ghost', onClick: function () { props.setActive(guide.target); } }, guide.cta, Icon('arrow'))
						)
					);
				})) : el('div', { className: 'np-help-empty' }, Icon('search'), el('strong', null, 'No matching guide'), el('p', null, 'Try a shorter search such as cache, license, images or database.'))
			),
			el('section', { className: 'np-panel np-help-footer' },
				el('div', null, el('span', { className: 'np-kicker' }, 'Need technical details?'), el('h3', null, 'Run a health check or create a support report'), el('p', null, 'Tools can diagnose the site and generate a privacy-safe report without changing content.')),
				el('button', { type: 'button', className: 'np-button np-button-primary', onClick: function () { props.setActive('tools'); } }, Icon('wrench'), 'Open Tools')
			)
		);
	}

	function ImpactProofCard(props) {
		var proof = props.proof || {};
		var summary = proof.summary || {};
		var run = props.run || {};
		var status = proof.status || run.status || '';
		if (!status || status === 'not_started') { return null; }
		if (['complete', 'needs_review', 'measuring', 'validating', 'applying', 'needs_rollback'].indexOf(status) === -1 && !proof.first_win_at) { return null; }
		function deltaLabel(value, betterWhenLower) {
			if (value === null || value === undefined || value === '') { return { text: 'Pending', pending: true }; }
			var num = Number(value);
			if (!isFinite(num)) { return { text: 'Pending', pending: true }; }
			var sign = num > 0 ? '+' : '';
			var good = betterWhenLower ? num < 0 : num > 0;
			return { text: sign + String(num), good: good, neutral: num === 0 };
		}
		function deltaClass(item) {
			if (!item || item.pending || item.neutral) { return ''; }
			return item.good ? 'is-good' : 'is-bad';
		}
		var health = deltaLabel(summary.health_delta, false);
		var lcp = deltaLabel(summary.lcp_delta, true);
		var ttfb = deltaLabel(summary.ttfb_delta, true);
		var score = deltaLabel(summary.score_delta, false);
		return el('section', { className: 'np-panel np-impact-proof' },
			el('div', { className: 'np-impact-proof-head' },
				el('div', null,
					el('span', { className: 'np-kicker' }, 'Measured impact'),
					el('h3', null, status === 'complete' ? 'First-win evidence' : 'Smart Optimize progress'),
					el('p', null, summary.headline || (status === 'complete' ? 'Before/after evidence from the latest Smart Optimize run.' : 'Capturing validation and lab evidence for this run.'))
				),
				el('span', { className: 'np-smart-status is-' + (status === 'complete' ? 'success' : (status === 'needs_rollback' ? 'warning' : 'running')) }, humanizeStatus(status))
			),
			el('div', { className: 'np-impact-proof-grid' },
				el('article', null, el('small', null, 'Health'), el('strong', { className: deltaClass(health) }, health.text)),
				el('article', null, el('small', null, 'LCP Δ'), el('strong', { className: deltaClass(lcp) }, lcp.text)),
				el('article', null, el('small', null, 'TTFB Δ'), el('strong', { className: deltaClass(ttfb) }, ttfb.text)),
				el('article', null, el('small', null, 'Score Δ'), el('strong', { className: deltaClass(score) }, score.text))
			),
			el('div', { className: 'np-impact-proof-meta' },
				el('span', null, (summary.features_applied || 0) + ' features · ' + (summary.settings_applied || 0) + ' settings'),
				el('span', null, summary.lab_results ? (summary.lab_results + ' lab result' + (summary.lab_results === 1 ? '' : 's')) : 'Lab proof queued'),
				el('button', { className: 'np-button np-button-ghost', disabled: !!props.busy.smart_optimize_report, onClick: function () { props.runAction('smart_optimize_report'); } }, Icon('download'), 'Full report')
			)
		);
	}





	function BrowserIntelligencePanel(props) {
		var data = props.data || {};
		var status = data.generator_status || {};
		var state = String(status.state || (data.generator_configured ? 'ready' : 'unconfigured'));
		var message = status.message || (data.generator_configured
			? 'Built-in PHP background generation is available.'
			: 'PHP DOM and the bundled CSS parser are required for background generation.');
		var cssRows = Array.isArray(data.css_status) ? data.css_status.slice(-5).reverse() : [];
		var stateLabel = ({
			ready: 'Ready',
			pending: 'Pending generation',
			local_fallback: 'Local fallback',
			degraded: 'Needs attention',
			insecure: 'Insecure endpoint',
			unconfigured: 'Offline / unconfigured'
		})[state] || 'Unknown';
		var tone = (state === 'ready' || state === 'local_fallback') ? 'good' : (state === 'pending' ? 'muted' : 'warning');

		return el('section', { className: 'np-panel np-browser-intelligence', 'aria-labelledby': 'np-browser-intelligence-title' },
			el('div', { className: 'np-browser-intelligence-head' },
				el('div', null,
					el('span', { className: 'np-kicker' }, 'Critical CSS status'),
					el('h3', { id: 'np-browser-intelligence-title' }, 'Background CSS generation'),
					el('p', null, message)
				),
				el('div', { className: 'np-quality-pills', 'aria-label': 'PHP CSS engine status' },
					el('span', { className: 'np-quality-pill is-' + tone }, stateLabel),
					el('span', { className: 'np-quality-pill is-good' }, 'Local only'),
					el('span', { className: 'np-quality-pill is-' + (status.configured ? 'good' : 'muted') }, status.configured ? 'Generator ready' : 'Generator not installed')
				)
			),
			el('div', { className: 'np-browser-intelligence-grid' },
				el('article', { className: 'np-quality-evidence' },
					el('h4', null, 'Engine readiness'),
					el('dl', null,
						el('div', null, el('dt', null, 'Critical CSS'), el('dd', null, status.critical_enabled ? 'Enabled' : 'Off')),
						el('div', null, el('dt', null, 'Used CSS'), el('dd', null, status.used_css_enabled ? 'Enabled' : 'Off')),
						el('div', null, el('dt', null, 'Safety checks'), el('dd', null, 'Static analysis; no screenshot verification')),
						el('div', null, el('dt', null, 'Critical CSS mode'), el('dd', null, 'Conservative; viewport not measured'))
					)
				),
				el('article', { className: 'np-quality-evidence' },
					el('h4', null, 'Recent CSS jobs'),
					cssRows.length
						? el('ul', { className: 'np-browser-css-log' }, cssRows.map(function (row, index) {
							var label = (row && (row.status || row.state)) ? String(row.status || row.state) : (row && row.pending ? 'pending' : (row && row.generated_at ? 'generated' : 'recorded'));
							var when = row && (row.at || row.timestamp || row.generated_at) ? Number(row.at || row.timestamp || row.generated_at) : 0;
							var url = row && row.url ? String(row.url) : '';
							return el('li', { key: 'css-' + index },
								el('strong', null, label),
								el('span', null, when ? new Date(when * 1000).toLocaleString() : 'No timestamp'),
								url ? el('em', null, url) : null,
								row && row.message ? el('span', null, row.message) : null
							);
						}))
						: el('p', { className: 'np-browser-empty' }, state === 'unconfigured' || state === 'insecure'
							? 'No jobs yet — enable PHP DOM and the bundled parser, then generate homepage CSS.'
							: 'No CSS results yet. Background jobs continue after this tab closes.')
				)
			)
		);
	}

	function SettingsSection(props) {
		var fields = props.settings.fields || {};
		var regular = Object.keys(fields).filter(function (key) { return !isExclusion(fields[key]); });
		var exclusions = Object.keys(fields).filter(function (key) { return isExclusion(fields[key]); });

		var currentTab = props.tab === 'exclusions' ? 'exclusions' : 'settings';
		var operations = [];
		var secondaryOperations = [];
		var primaryOperationTitle = '';
		var secondaryOperationTitle = '';

		if (props.id === 'optimization') {
			primaryOperationTitle = 'Background CSS generation';
			operations = [
				ActionButton('Open Script Manager', 'Inspect frontend assets and create URL, device, login or role-aware unload and loading rules.', 'admin-settings', false, function () { window.location.hash = 'script_manager'; }),
				ActionButton('Check PHP engine', 'Check PHP DOM and the bundled CSS parser. No external engine is required.', 'visibility', !!props.busy.browser_engine_test, function () { props.runAction('browser_engine_test'); }),
				ActionButton('Generate homepage CSS', 'Queue conservative PHP CSS generation for the homepage. You may close this tab.', 'performance', !!props.busy.browser_css_generate, function () { props.runAction('browser_css_generate'); }),
			];
		}
		if (props.id === 'images') {
			primaryOperationTitle = '';
			operations = [];
		}
		if (props.id === 'cdn') {
			primaryOperationTitle = 'CDN Operations';
			operations = [];
		}
		if (props.id === 'enterprise') {
			primaryOperationTitle = 'Enterprise Operations';
			operations = [
				ActionButton('Test edge purge webhook', 'Verify the configured HTTPS edge purge service without purging production cache.', 'globe', !!props.busy.edge_webhook_test, function () { props.runAction('edge_webhook_test'); }),
				ActionButton('Purge site cache tags', 'Queue a coordinated page, object and edge cache invalidation for the site-wide tag.', 'trash', !!props.busy.cache_tags_purge, function () { props.runAction('cache_tags_purge', { tags: ['site-wide'] }); }),
				ActionButton('Rotate read-only AI token', 'Create a new bearer token that can inspect status, jobs and performance without changing the site.', 'lock', !!props.busy.ai_token_rotate, function () { props.runAction('ai_token_rotate', { scope: 'read' }); }),
				ActionButton('Rotate approved-write AI token', 'Create a read/write token. Every write still requires a matching administrator approval.', 'shield', !!props.busy.ai_token_rotate, function () { props.confirmAction({ variant: 'warning', kicker: 'Security confirmation', title: 'Create a new AI read/write token?', message: 'NBH Performance will replace the current AI token. The old token will stop working right away, and write actions will still need administrator approval.', note: 'Copy the new token from the result and store it somewhere secure. You will only see it once.', confirmText: 'Create new token', cancelText: 'Keep current token', icon: 'lock' }).then(function (confirmed) { if (confirmed) { props.runAction('ai_token_rotate', { scope: 'read_write' }); } }); }),
				ActionButton('Export portfolio report', 'Generate a branded portfolio and performance report for this site or multisite network.', 'download', !!props.busy.portfolio_report, function () { props.runAction('portfolio_report'); })
			];
		}

		return el('div', { className: 'np-view np-unified-workspace' },
			el(FeaturePanel, { group: props.featureGroup, busy: props.busy, toggle: props.toggle }),
			props.id === 'enterprise' ? el(AgencyProfilesPanel, { notify: props.notify, confirmAction: props.confirmAction }) : null,
			operations.length ? el(OperationPanel, { title: primaryOperationTitle || 'Operations', actions: operations }) : null,
			secondaryOperations.length ? el(OperationPanel, { title: secondaryOperationTitle || 'Additional Operations', actions: secondaryOperations }) : null,
			(regular.length || exclusions.length) ? (
				props.showAdvancedSettings
					? el('div', { className: 'np-unified-settings-area' },
						el('div', { className: 'np-tabs', role: 'tablist' },
							Tab('settings', 'Settings', currentTab, props.setTab, regular.length),
							Tab('exclusions', 'Exclusions', currentTab, props.setTab, exclusions.length)
						),
						currentTab === 'exclusions'
							? el(FieldPanel, { sectionId: props.id, title: 'Exclusion manager', description: 'Protect sensitive URLs, assets, selectors, cookies and integrations with precise exclusion rules.', keys: exclusions, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts })
							: el(FieldPanel, { sectionId: props.id, title: 'Settings', description: 'Adjust how this area works. NBH Performance validates every value before saving.', keys: regular, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts }),
						el(StickyAdvancedSave, { dirty: props.dirty, busy: !!props.busy['save-' + props.id], setAdvancedSettings: props.setAdvancedSettings, onDiscard: function () { props.reset(props.id, false); }, onDefaults: function () { props.reset(props.id, true); }, onSave: function () { props.save(props.id); }, saveLabel: 'Save changes' })
					)
					: el(AdvancedSettingsGate, { count: regular.length + exclusions.length, onOpen: function () { props.setAdvancedSettings(true); } })
			) : null
		);
	}

	function AdvancedSettingsGate(props) {
		return el('section', { className: 'np-panel np-advanced-settings-gate' },
			el('div', { className: 'np-advanced-settings-gate-main' },
				el('span', { className: 'np-advanced-settings-gate-icon', 'aria-hidden': 'true' }, Icon('sliders')),
				el('div', { className: 'np-advanced-settings-gate-copy' },
					el('span', { className: 'np-kicker' }, 'Advanced'),
					el('strong', null, props.title || 'Detailed settings stay tucked away'),
					el('p', null, props.description || 'Features and actions above still work. Open Advanced only when you need Settings or Exclusions.')
				)
			),
			el('button', { className: 'np-button np-button-primary', onClick: props.onOpen }, Icon('sliders'), props.buttonLabel || ('Show settings' + (props.count ? ' (' + props.count + ')' : '')))
		);
	}

	function StickyAdvancedSave(props) {
		return el('div', { className: 'np-sticky-save' },
			el('div', null,
				el('strong', null, props.dirty ? 'Unsaved changes' : 'All changes saved'),
				el('span', null, props.dirty ? 'Save when you are ready.' : 'No unsaved changes.')
			),
			el('div', { className: 'np-sticky-save-actions' },
				el('button', { type: 'button', className: 'np-button np-button-ghost', onClick: function () { props.setAdvancedSettings(false); } }, Icon('sliders'), 'Hide settings'),
				el('button', { type: 'button', className: 'np-button np-button-ghost', disabled: !props.dirty || !!props.busy, onClick: props.onDiscard }, 'Discard'),
				props.onDefaults ? el('button', { type: 'button', className: 'np-button np-button-ghost', onClick: props.onDefaults }, 'Load defaults') : null,
				el('button', { type: 'button', className: 'np-button np-button-primary', disabled: !props.dirty || !!props.busy, onClick: props.onSave }, props.busy ? 'Saving…' : (props.saveLabel || 'Save settings'))
			)
		);
	}




	function SmartOptimizePanel(props) {
		var run = props.run || {};
		var rollback = props.rollback || {};
		var proof = props.proof || {};
		var status = run.status || 'not_started';
		var active = status === 'applying' || status === 'validating' || status === 'measuring';
		var statusLabel = status === 'not_started' ? 'Ready' : humanizeStatus(status);
		var statusTone = status === 'complete' ? 'success' : (status === 'rolled_back' || status === 'needs_rollback' ? 'warning' : (active ? 'running' : 'neutral'));
		var previewState = useState(null);
		var preview = previewState[0];
		var setPreview = previewState[1];
		var previewBusyState = useState('');
		var previewBusy = previewBusyState[0];
		var setPreviewBusy = previewBusyState[1];
		var rawSteps = (run.plan && Array.isArray(run.plan.steps) ? run.plan.steps : null) || (proof.steps && Array.isArray(proof.steps) ? proof.steps : []) || (preview && Array.isArray(preview.steps) ? preview.steps : []);
		var steps = normalizeSmartSteps(rawSteps, status);

		useEffect(function () {
			if (!active || typeof props.refresh !== 'function') { return undefined; }
			var timer = window.setInterval(function () { props.refresh(); }, 4000);
			return function () { window.clearInterval(timer); };
		}, [active, status]);

		function loadPlan(mode) {
			setPreviewBusy(mode);
			return apiFetch({ path: '/nbh-performance/v1/smart-optimize/plan?mode=' + encodeURIComponent(mode) }).then(function (plan) {
				setPreview(plan);
				return plan;
			}).catch(function (error) {
				if (props.notify) { props.notify(error.message || 'Could not load Smart Optimize plan.', 'error'); }
				return null;
			}).then(function (plan) {
				setPreviewBusy('');
				return plan;
			});
		}

		function start(mode) {
			var labels = { safe: 'Safe', balanced: 'Balanced', maximum: 'Maximum Performance' };
			loadPlan(mode).then(function (plan) {
				if (!plan) { return; }
				if (!plan.eligible) {
					var blocker = (plan.blockers && plan.blockers[0] && plan.blockers[0].message) ? plan.blockers[0].message : 'Smart Optimize is blocked by the current environment.';
					props.confirmAction({ variant: 'warning', kicker: 'Plan blocked', title: 'Cannot start ' + labels[mode], message: blocker, note: 'Resolve the blockers, then try again.', confirmText: 'Understood', cancelText: 'Close', icon: 'warning' });
					return;
				}
				var warningNote = (plan.warnings && plan.warnings.length) ? plan.warnings.map(function (item) { return item.message; }).join(' ') : (mode === 'maximum' ? 'Maximum mode can enable stronger features when the PHP CSS engine is available. A recovery point is saved first.' : 'A recovery point is saved first, so you can roll back later if needed.');
				var featureCount = plan.features ? Object.keys(plan.features).length : 0;
				props.confirmAction({
					variant: mode === 'maximum' ? 'warning' : 'info',
					kicker: 'Plan preview',
					title: 'Run the ' + labels[mode] + ' plan?',
					message: 'NBH Performance will apply about ' + featureCount + ' optimization settings for a ' + (plan.site_type || 'wordpress') + ' site on ' + (plan.hosting_tier || 'unknown') + ' hosting, then validate and measure impact.',
					rows: (plan.warnings || []).slice(0, 4).map(function (item) { return { label: 'Warning', value: item.message }; }),
					note: warningNote,
					confirmText: 'Yes, start ' + labels[mode],
					cancelText: 'Not now',
					icon: 'magic'
				}).then(function (confirmed) { if (confirmed) { props.runAction('smart_optimize_start', { mode: mode }); } });
			});
		}

		return el('section', { className: 'np-panel np-smart-optimize-panel' },
			el('div', { className: 'np-smart-optimize-head' },
				el('div', null,
					el('span', { className: 'np-kicker' }, 'Autopilot'),
					el('h3', null, 'Smart Optimize'),
					el('p', null, 'Pick a plan, apply safely, measure impact—with rollback ready.')
				),
				el('span', { className: 'np-smart-status is-' + statusTone }, active ? el('span', { className: 'np-running-dot' }) : Icon(status === 'complete' ? 'check' : (status === 'rolled_back' ? 'undo' : 'shield')), statusLabel)
			),
			el('div', { className: 'np-smart-layout' },
				el('div', { className: 'np-smart-summary' },
					el('div', { className: 'np-smart-summary-grid' },
						el('article', { className: 'np-smart-card' }, el('small', null, 'Last mode'), el('strong', null, run.mode ? humanizeStatus(run.mode) : 'Not run yet'), el('p', null, 'The most recent optimization level used on this site.')),
						el('article', { className: 'np-smart-card' }, el('small', null, 'Safety rollback'), el('strong', null, rollback.enabled ? (rollback.production_enabled ? 'Production protected' : 'Staging protected') : 'Off'), el('p', null, 'A saved recovery point that helps you go back if needed.')),
						el('article', { className: 'np-smart-card' }, el('small', null, 'Last started'), el('strong', null, run.started_at ? formatTimestamp(run.started_at) : 'Not started'), el('p', null, 'When Smart Optimize was last started.')),
						el('article', { className: 'np-smart-card' }, el('small', null, 'Applied'), el('strong', null, run.updated_features ? String((run.updated_features || []).length) + ' features' : 'Not available'), el('p', null, run.updated_settings ? String((run.updated_settings || []).length) + ' settings changed.' : 'Run a plan to see applied changes.'))
					),
					preview && preview.blockers && preview.blockers.length ? el('div', { className: 'np-smart-plan-notes is-blocked' },
						el('strong', null, 'Current blockers'),
						el('ul', null, preview.blockers.map(function (item, index) { return el('li', { key: item.id || index }, item.message); }))
					) : null
				),
				el('div', { className: 'np-smart-action-panel' },
					el('div', { className: 'np-smart-action-group' },
						el('small', { className: 'np-smart-action-label' }, 'Choose a mode'),
						el('div', { className: 'np-smart-plan-actions np-smart-plan-actions-primary np-mode-segment' },
							el('button', { className: 'np-button np-button-ghost', disabled: active || !!props.busy.smart_optimize_start || !!previewBusy, onClick: function () { start('safe'); } }, previewBusy === 'safe' ? 'Checking…' : 'Safe'),
							el('button', { className: 'np-button np-button-primary', disabled: active || !!props.busy.smart_optimize_start || !!previewBusy, onClick: function () { start('balanced'); } }, (props.busy.smart_optimize_start || previewBusy === 'balanced') ? 'Starting…' : 'Balanced'),
							el('button', { className: 'np-button np-button-ghost', disabled: active || !!props.busy.smart_optimize_start || !!previewBusy, onClick: function () { start('maximum'); } }, previewBusy === 'maximum' ? 'Checking…' : 'Maximum')
						)
					),
					el('div', { className: 'np-smart-action-group' },
						el('small', { className: 'np-smart-action-label' }, 'Other actions'),
						el('div', { className: 'np-smart-plan-actions' },
							el('button', { className: 'np-button np-button-ghost', disabled: !!props.busy.smart_optimize_report, onClick: function () { props.runAction('smart_optimize_report'); } }, Icon('download'), 'Download report'),
							run.transaction_id ? el('button', { className: 'np-button np-button-danger-ghost', disabled: active || !!props.busy.smart_optimize_rollback, onClick: function () { props.confirmAction({ variant: 'warning', kicker: 'Before rollback', title: 'Restore the settings from before Smart Optimize?', message: 'NBH Performance will restore the saved configuration from before the latest Smart Optimize run and rebuild the cache.', note: 'Your WordPress content, orders, users and media files will stay unchanged.', confirmText: 'Yes, restore previous settings', cancelText: 'No, keep current changes', icon: 'undo' }).then(function (confirmed) { if (confirmed) { props.runAction('smart_optimize_rollback'); } }); } }, 'Rollback changes') : null
						)
					)
				)
			),
			steps.length ? el('div', { className: 'np-smart-steps-wrap' },
				el('div', { className: 'np-smart-steps-head' },
					el('small', null, 'Run checklist'),
					el('strong', null, 'Optimization steps')
				),
				el('ol', { className: 'np-smart-steps' }, steps.map(function (step, index) {
					var stepStatus = String(step.status || 'planned');
					return el('li', { key: step.id || ('step-' + index), className: 'np-smart-step-card is-' + stepStatus },
						el('span', { className: 'np-smart-step-index', 'aria-hidden': 'true' }, String(index + 1)),
						el('div', { className: 'np-smart-step-body' },
							el('strong', null, step.title || ('Step ' + (index + 1))),
							el('span', { className: 'np-smart-step-status' }, humanizeStatus(stepStatus))
						)
					);
				}))
			) : null
		);
	}

	function normalizeSmartSteps(steps, runStatus) {
		if (!Array.isArray(steps) || !steps.length) { return []; }
		var status = String(runStatus || 'not_started');
		return steps.map(function (step) {
			var next = Object.assign({}, step);
			var stepStatus = String(next.status || 'planned');
			if ((status === 'complete' || status === 'needs_review') && (stepStatus === 'planned' || stepStatus === 'running' || stepStatus === 'deferred')) {
				next.status = 'complete';
			} else if (status === 'rolled_back' && stepStatus === 'planned') {
				next.status = step.id === 'protect' ? 'complete' : 'skipped';
			} else if (status === 'needs_rollback' && stepStatus === 'planned') {
				next.status = step.id === 'protect' ? 'running' : (step.id === 'validate' ? 'failed' : 'skipped');
			}
			return next;
		});
	}

	function DatabaseView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var featureGroup = props.data && props.data.features ? props.data.features.database : null;
		var settings = props.data && props.data.settings ? props.data.settings.database : null;
		var fields = settings && settings.fields ? settings.fields : {};
		var fieldKeys = Object.keys(fields);
		var exclusions = fieldKeys.filter(function (key) { return isExclusion(fields[key]); });
		var regular = fieldKeys.filter(function (key) { return !isExclusion(fields[key]); });
		var tabState = useState('settings'), settingsTab = tabState[0], setSettingsTab = tabState[1];
		var labels = databaseCleanupLabels();
		var parentBusy = props.busy || {};
		var wasBusyRef = useRef(false);

		function load(silent) {
			if (!silent) { setActionBusy('load'); }
			return apiFetch({ path: '/nbh-performance/v1/database/status', method: 'GET' }).then(function (response) {
				setStatus(response);
				return response;
			}).catch(function (error) {
				if (!silent) { props.notify(error.message || 'Unable to load Database Cleanup status.', 'error'); }
				throw error;
			}).then(function (response) {
				if (!silent) { setActionBusy(''); }
				return response;
			}, function () {
				if (!silent) { setActionBusy(''); }
			});
		}

		useEffect(function () { load(false); }, []);

		useEffect(function () {
			var nowBusy = !!(parentBusy.database_preview || parentBusy.database_backup || parentBusy.database_cleanup || parentBusy.database_optimize);
			if (wasBusyRef.current && !nowBusy) {
				load(true);
				if (typeof props.refresh === 'function') { props.refresh(); }
			}
			wasBusyRef.current = nowBusy;
		}, [parentBusy.database_preview, parentBusy.database_backup, parentBusy.database_cleanup, parentBusy.database_optimize]);

		function run(actionKey) {
			props.runAction(actionKey);
		}

		if (!status) {
			return el('div', { className: 'np-app-loading np-db-loading' },
				el('span', { className: 'np-loader' }),
				el('strong', null, 'Checking database health'),
				el('p', null, 'Counting safe clutter and verifying the latest private backup…')
			);
		}

		var preview = status.preview || {};
		var clutterTypes = Object.keys(labels).filter(function (key) { return Number(preview[key] || 0) > 0; });
		var clutterTotal = Number(status.clutter_total || 0);
		var backup = status.latest_backup || null;
		var last = status.last_cleanup || {};
		var schedule = String(status.schedule || 'weekly');
		var scheduleOn = !!status.cleanup_enabled && schedule !== 'off';
		var anyBusy = !!actionBusy || !!parentBusy.database_preview || !!parentBusy.database_backup || !!parentBusy.database_cleanup || !!parentBusy.database_optimize;
		var heroTone = status.safe_mode ? 'warning' : (last.status === 'failed' ? 'warning' : (clutterTotal > 0 ? 'warning' : (status.latest_backup_usable ? 'good' : 'muted')));
		var heroLabel = status.safe_mode ? 'Safe Mode on' : (last.status === 'failed' ? 'Last cleanup failed' : (clutterTotal > 0 ? clutterTypes.length + ' clutter types' : (status.latest_backup_usable ? 'Database tidy' : 'Ready to scan')));

		function DbCard(icon, title, description, actionKey, cta, workingLabel) {
			var loading = !!parentBusy[actionKey];
			return el('article', { className: 'np-db-action-card' + (loading ? ' is-working' : '') },
				el('div', { className: 'np-db-action-top' },
					el('span', { className: 'np-db-action-icon' }, Icon(icon)),
					el('div', null, el('strong', null, title), el('p', null, description))
				),
				el('button', {
					type: 'button',
					className: 'np-button np-button-primary np-db-action-cta',
					disabled: anyBusy,
					onClick: function () { run(actionKey); }
				}, loading ? el('span', { className: 'np-spinner' }) : Icon(icon), loading ? (workingLabel || 'Working…') : cta)
			);
		}

		return el('div', { className: 'np-view np-db-view' },
			featureGroup ? el(FeaturePanel, { group: featureGroup, busy: parentBusy, toggle: function (option, checked) {
				var result = props.toggle(option, checked);
				if (result && typeof result.then === 'function') {
					result.then(function () { load(true); }, function () {});
				} else {
					setTimeout(function () { load(true); }, 350);
				}
			} }) : null,
			el('section', { className: 'np-panel np-db-hero' },
				el('div', { className: 'np-db-hero-copy' },
					el('span', { className: 'np-kicker' }, 'Database Cleanup'),
					el('h3', null, 'Safe clutter removal with verified backups'),
					el('p', null, 'Preview revisions, spam, expired transients and more. Every cleanup and optimize run verifies a private SQL backup first — posts, users, orders and media stay untouched.')
				),
				el('div', { className: 'np-db-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: anyBusy, onClick: function () { load(false); } }, Icon('refresh'), actionBusy === 'load' ? 'Refreshing…' : 'Refresh'),
					el('span', { className: 'np-quality-pill is-' + heroTone }, heroLabel)
				)
			),
			status.safe_mode ? el('div', { className: 'np-object-alert is-warning' }, Icon('shield'), el('div', null, el('strong', null, 'Safe Mode is active'), el('p', null, 'You can still preview and back up. Prefer confirming cleanups after exiting Safe Mode if the site is under recovery.'))) : null,
			last.status === 'failed' && last.error ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'Previous cleanup did not finish'), el('p', null, last.error))) : null,
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Cleanup status', description: 'Live clutter counts, backup readiness and schedule state.' }),
				el('div', { className: 'np-db-status-grid' },
					el('article', { className: 'np-db-status-card' }, el('small', null, 'Safe clutter'), el('strong', null, String(clutterTotal)), el('p', null, clutterTypes.length ? clutterTypes.length + ' types ready to review.' : 'No safe clutter found.')),
					el('article', { className: 'np-db-status-card' }, el('small', null, 'Verified backup'), el('strong', null, status.latest_backup_usable ? 'Ready' : (backup ? 'Unverified' : 'None')), el('p', null, backup && backup.created_at ? ('Created ' + formatTimestamp(backup.created_at)) : 'A private backup is required before changes.')),
					el('article', { className: 'np-db-status-card' }, el('small', null, 'Last cleanup'), el('strong', null, last.completed_at ? humanizeStatus(last.status || 'complete') : 'Never'), el('p', null, last.completed_at ? (formatTimestamp(last.completed_at) + (last.deleted_total ? (' · ' + last.deleted_total + ' removed') : '')) : 'Run a previewed cleanup when ready.')),
					el('article', { className: 'np-db-status-card' }, el('small', null, 'Schedule'), el('strong', null, scheduleOn ? humanizeStatus(schedule) : 'Off'), el('p', null, scheduleOn && status.next_scheduled ? ('Next ' + formatTimestamp(status.next_scheduled)) : (scheduleOn ? 'Waiting for cron.' : 'Enable Scheduled safe cleanup to automate.')))
				)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Safe clutter preview', description: clutterTypes.length ? 'Only these non-destructive types are eligible. Counts refresh with Preview or Refresh.' : 'Your database looks clean for the safe cleanup set.' }),
				clutterTypes.length
					? el('div', { className: 'np-db-clutter-grid' }, clutterTypes.map(function (key) {
						return el('article', { className: 'np-db-clutter-card', key: key },
							el('small', null, labels[key]),
							el('strong', null, String(Number(preview[key] || 0)))
						);
					}))
					: el('p', { className: 'np-db-empty' }, 'No revisions, spam, expired sessions or orphaned meta matched the safe cleanup rules.')
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Operations', description: 'Preview first. Cleanup and optimize always create and verify a private SQL backup before changing data.' }),
				el('div', { className: 'np-db-action-grid' },
					DbCard('eye', 'Preview cleanup', 'Recount every safe clutter type without changing the database.', 'database_preview', 'Preview', 'Scanning…'),
					DbCard('backup', 'Create verified backup', 'Write a private SQL backup into the NBH vault and verify it.', 'database_backup', 'Back up now', 'Backing up…'),
					DbCard('broom', 'Remove safe clutter', 'Choose clutter types, verify a backup, then clean in safe batches.', 'database_cleanup', 'Clean selected', 'Cleaning…'),
					DbCard('database', 'Optimize tables', 'Optimize WordPress-owned tables after a verified backup. Non-WP tables stay untouched.', 'database_optimize', 'Optimize', 'Optimizing…')
				)
			),
			settings && props.showAdvancedSettings ? el('div', { className: 'np-unified-settings-area' },
				el('div', { className: 'np-tabs', role: 'tablist' },
					Tab('settings', 'Settings', settingsTab, setSettingsTab, regular.length),
					Tab('exclusions', 'Exclusions', settingsTab, setSettingsTab, exclusions.length)
				),
				settingsTab === 'exclusions'
					? el(FieldPanel, { sectionId: 'database', title: 'Protected tables', description: 'Additional WordPress-prefixed tables to keep out of optimization.', keys: exclusions, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts })
					: el(FieldPanel, { sectionId: 'database', title: 'Database Cleanup settings', description: 'Schedule, retention windows and batch size for conservative maintenance.', keys: regular, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts }),
				el(StickyAdvancedSave, { dirty: props.dirty, busy: !!parentBusy['save-database'], setAdvancedSettings: props.setAdvancedSettings, onDiscard: function () { props.reset('database'); }, onSave: function () { props.save('database'); } })
			) : el(AdvancedSettingsGate, { title: 'Advanced database settings', description: 'Cleanup schedule, revision retention, batch size and protected tables.', count: fieldKeys.length, onOpen: function () { props.setAdvancedSettings(true); } })
		);
	}

	function ToolsView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var featureGroup = props.data && props.data.features ? props.data.features.tools : null;
		var settings = props.data && props.data.settings ? props.data.settings.tools : null;
		var fields = settings && settings.fields ? settings.fields : {};
		var fieldKeys = Object.keys(fields);
		var exclusions = fieldKeys.filter(function (key) { return isExclusion(fields[key]); });
		var regular = fieldKeys.filter(function (key) { return !isExclusion(fields[key]); });
		var tabState = useState('settings'), settingsTab = tabState[0], setSettingsTab = tabState[1];

		function load(silent) {
			if (!silent) { setActionBusy('load'); }
			return apiFetch({ path: '/nbh-performance/v1/tools/status' }).then(function (response) {
				setStatus(response);
				return response;
			}).catch(function (error) {
				if (!silent) { props.notify(error.message || 'Unable to load Tools status.', 'error'); }
				throw error;
			}).then(function (response) {
				if (!silent) { setActionBusy(''); }
				return response;
			}, function () {
				if (!silent) { setActionBusy(''); }
			});
		}

		useEffect(function () { load(false); }, []);

		function afterAction() {
			load(true);
			if (typeof props.refresh === 'function') { props.refresh(); }
		}

		function run(action, confirmConfig, payload) {
			var start = function () {
				var data = payload;
				if (!data && action === 'smart_optimize_start') { data = { mode: 'balanced' }; }
				props.runAction(action, data);
				setTimeout(afterAction, 700);
			};
			if (confirmConfig) {
				props.confirmAction(confirmConfig).then(function (confirmed) { if (confirmed) { start(); } });
				return;
			}
			start();
		}

		var data = status || {};
		var ops = data.operations || {};
		var jobs = data.jobs || (props.data && props.data.reliability ? props.data.reliability.jobs : {}) || {};
		var snapshot = data.configuration_snapshot || (props.data && props.data.configuration_snapshot) || {};
		var smart = data.smart_optimize || {};
		var recovery = (data.product_quality && data.product_quality.recovery) || (props.data && props.data.product_quality && props.data.product_quality.recovery) || {};
		var queuePaused = !!jobs.paused;
		var opsStatus = String(ops.status || 'healthy');
		var heroTone = data.safe_mode || recovery.active || opsStatus === 'critical' ? 'warning' : (opsStatus === 'warning' || Number(jobs.failed || 0) > 0 ? 'warning' : (data.runtime_ready ? 'good' : 'muted'));
		var heroLabel = recovery.active ? 'Recovery mode' : (data.safe_mode ? 'Safe Mode on' : (opsStatus === 'critical' ? 'Needs attention' : (queuePaused ? 'Queue paused' : (opsStatus === 'warning' ? 'Review recommended' : 'Operations healthy'))));
		var quality = data.product_quality || (props.data && props.data.product_quality) || {};
		var runbook = data.recovery_runbook || (props.data && props.data.recovery_runbook) || [];
		function ToolCard(icon, title, description, actionKey, confirmConfig, cta) {
			var loading = !!props.busy[actionKey];
			return el('article', { className: 'np-tools-action-card' + (loading ? ' is-working' : '') },
				el('div', { className: 'np-tools-action-top' },
					el('span', { className: 'np-tools-action-icon' }, Icon(icon)),
					el('div', null, el('strong', null, title), el('p', null, description))
				),
				el('button', {
					type: 'button',
					className: 'np-button np-button-primary np-tools-action-cta',
					disabled: loading || !!actionBusy,
					onClick: function () { run(actionKey, confirmConfig); }
				}, loading ? el('span', { className: 'np-spinner' }) : Icon(icon), loading ? 'Working…' : (cta || 'Run'))
			);
		}

		return el('div', { className: 'np-view np-tools-view' },
			featureGroup ? el(FeaturePanel, { group: featureGroup, busy: props.busy, toggle: function (option, checked) {
				var result = props.toggle(option, checked);
				if (result && typeof result.then === 'function') {
					result.then(function () { load(true); }, function () {});
				} else {
					setTimeout(function () { load(true); }, 350);
				}
			} }) : null,
			el('section', { className: 'np-panel np-tools-hero' },
				el('div', { className: 'np-tools-hero-copy' },
					el('span', { className: 'np-kicker' }, 'Tools'),
					el('h3', null, 'The few tools you actually need'),
					el('p', null, 'Check health, run Smart Optimize, back up settings, or download a support report — without a wall of rare actions.')
				),
				el('div', { className: 'np-tools-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { load(false); afterAction(); } }, Icon('refresh'), actionBusy === 'load' ? 'Refreshing…' : 'Refresh'),
					el('span', { className: 'np-quality-pill is-' + heroTone }, heroLabel)
				)
			),
			data.safe_mode ? el('div', { className: 'np-object-alert is-warning' }, Icon('shield'), el('div', null, el('strong', null, 'Safe Mode is active'), el('p', null, 'Optimization runtime is paused. Use Recovery Center below when you are ready to stabilize or resume.'))) : null,
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Status', description: 'Queue health, settings backup and the last Smart Optimize run.' }),
				el('div', { className: 'np-tools-status-grid' },
					el('article', { className: 'np-tools-status-card' }, el('small', null, 'Ops health'), el('strong', null, humanizeStatus(opsStatus)), el('p', null, ops.checked_at ? ('Checked ' + formatTimestamp(ops.checked_at)) : 'Refresh to load health.')),
					el('article', { className: 'np-tools-status-card' }, el('small', null, 'Background queue'), el('strong', null, queuePaused ? 'Paused' : String(Number(jobs.pending || 0))), el('p', null, Number(jobs.running || 0) + ' running · ' + Number(jobs.failed || 0) + ' failed')),
					el('article', { className: 'np-tools-status-card' }, el('small', null, 'Settings backup'), el('strong', null, snapshot.available ? 'Available' : 'None'), el('p', null, snapshot.created_at ? ('Created ' + formatTimestamp(snapshot.created_at)) : 'Create a snapshot before risky changes.')),
					el('article', { className: 'np-tools-status-card' }, el('small', null, 'Smart Optimize'), el('strong', null, smart.has_run ? humanizeStatus(smart.status || 'complete') : 'Not run'), el('p', null, smart.mode ? (humanizeStatus(smart.mode) + (smart.updated ? ' · ' + formatTimestamp(smart.updated) : '')) : 'Balanced plan is ready when you are.'))
				)
			),
			window.NBHPerformanceProductQuality ? el(window.NBHPerformanceProductQuality.RecoveryCenterPanel, {
				data: quality,
				runbook: runbook,
				busy: props.busy,
				runAction: function (action) { run(action); },
				confirmAction: props.confirmAction,
				icon: Icon
			}) : null,
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Essential actions', description: 'Everyday checks, optimization and recovery. Advanced transfer tools stay under Advanced.' }),
				el('div', { className: 'np-tools-action-grid np-tools-essential-grid' },
					ToolCard('magic', 'Smart Optimize', 'Apply a balanced plan with a recovery point, then rebuild and validate.', 'smart_optimize_start', {
						variant: 'info',
						kicker: 'Before Smart Optimize',
						title: 'Run the balanced Smart Optimize plan?',
						message: 'NBH Performance will measure the current state, apply safe high-impact improvements, rebuild the cache and then test the frontend for problems.',
						note: 'A recovery point is kept so you can roll back later. Automatic production rollback is never turned on silently.',
						confirmText: 'Yes, run Balanced mode',
						cancelText: 'Not now',
						icon: 'magic'
					}, 'Start'),
					ToolCard('shield', 'Check site health', 'Non-destructive environment and readiness scan for blockers.', 'diagnostics', null, 'Run check'),
					ToolCard('download', 'Support report', 'Privacy-safe diagnostic JSON to share when you need help.', 'support_report', null, 'Download'),
					ToolCard('backup', 'Back up settings', 'Snapshot the current NBH Performance configuration.', 'config_snapshot', null, 'Back up'),
					ToolCard('refresh', 'Restore backup', 'Return to the latest saved configuration snapshot.', 'config_restore', {
						variant: 'warning',
						kicker: 'Before restoring settings',
						title: 'Restore the latest NBH Performance settings backup?',
						message: 'NBH Performance will replace your current plugin settings with the most recent saved backup.',
						note: 'This changes only NBH Performance settings. Your license, WordPress content, customers and media are not included in the backup.',
						confirmText: 'Yes, restore backup',
						cancelText: 'No, keep current settings',
						icon: 'backup'
					}, 'Restore'),
					Number(jobs.pending || 0) > 0 || Number(jobs.failed || 0) > 0 || queuePaused
						? ToolCard('refresh', 'Process jobs', 'Run a bounded batch of queued background tasks.', 'jobs_run', null, 'Process now')
						: null,
					smart.has_run ? ToolCard('undo', 'Undo Smart Optimize', 'Restore settings from the last Smart Optimize run.', 'smart_optimize_rollback', {
						variant: 'warning',
						kicker: 'Before rollback',
						title: 'Roll back the latest Smart Optimize changes?',
						message: 'NBH Performance will restore the saved settings from before the latest Smart Optimize run and rebuild the cache afterwards.',
						note: 'Your WordPress content, users, orders and media files will not be changed.',
						confirmText: 'Yes, roll back changes',
						cancelText: 'No, keep current setup',
						icon: 'undo'
					}, 'Roll back') : null,
					ToolCard('undo', 'Reset to defaults', 'Return to safe defaults. License and content stay intact.', 'config_reset', {
						variant: 'danger',
						kicker: 'Before resetting settings',
						title: 'Reset all NBH Performance settings to safe defaults?',
						message: 'NBH Performance will turn settings back to its recommended safe starting point. Custom optimization choices you made will be removed.',
						note: 'A recovery backup is created first. Your license, audit history, site content and onboarding status will stay untouched.',
						confirmText: 'Yes, reset settings',
						cancelText: 'No, keep my settings',
						icon: 'undo'
					}, 'Reset')
				)
			),
			settings && props.showAdvancedSettings ? el('div', { className: 'np-unified-settings-area' },
				el('section', { className: 'np-panel' },
					el(PanelHeader, { title: 'Settings transfer', description: 'Export or import a portable configuration when moving between sites.' }),
					el('div', { className: 'np-tools-action-grid' },
						ToolCard('download', 'Export settings', 'Download a portable JSON export of NBH Performance settings.', 'config_export', null, 'Export'),
						el('article', { className: 'np-tools-action-card' },
							el('div', { className: 'np-tools-action-top' },
								el('span', { className: 'np-tools-action-icon' }, Icon('upload')),
								el('div', null, el('strong', null, 'Import settings'), el('p', null, 'Apply a validated JSON export from another site or backup.'))
							),
							el('button', {
								type: 'button',
								className: 'np-button np-button-primary np-tools-action-cta',
								disabled: !!props.busy.config_import || !!actionBusy,
								onClick: function () { chooseConfigurationFile(props.runAction, props.notify); setTimeout(afterAction, 900); }
							}, props.busy.config_import ? el('span', { className: 'np-spinner' }) : Icon('upload'), props.busy.config_import ? 'Working…' : 'Import file')
						)
					)
				),
				el('div', { className: 'np-tabs', role: 'tablist' },
					Tab('settings', 'Settings', settingsTab, setSettingsTab, regular.length),
					Tab('exclusions', 'Exclusions', settingsTab, setSettingsTab, exclusions.length)
				),
				settingsTab === 'exclusions'
					? el(FieldPanel, { sectionId: 'tools', title: 'Exclusion manager', description: 'Diagnostic and validation exclusions that must stay untouched.', keys: exclusions, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts })
					: el(FieldPanel, { sectionId: 'tools', title: 'Tools settings', description: 'RUM, heartbeat, lab testing, jobs, staging safeguards and rollback windows.', keys: regular, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts }),
				el(StickyAdvancedSave, { dirty: props.dirty, busy: !!props.busy['save-tools'], setAdvancedSettings: props.setAdvancedSettings, onDiscard: function () { props.reset('tools'); }, onSave: function () { props.save('tools'); } })
			) : el(AdvancedSettingsGate, { title: 'Advanced Tools settings', description: 'Settings transfer, RUM, heartbeat, lab testing, job batch size and rollback windows.', count: fieldKeys.length, onOpen: function () { props.setAdvancedSettings(true); } })
		);
	}

	function OptimizationView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var featureGroup = props.data && props.data.features ? props.data.features.optimization : null;
		var settings = props.data && props.data.settings ? props.data.settings.optimization : null;
		var fields = settings && settings.fields ? settings.fields : {};
		var fieldKeys = Object.keys(fields);
		var exclusions = fieldKeys.filter(function (key) { return isExclusion(fields[key]); });
		var regular = fieldKeys.filter(function (key) { return !isExclusion(fields[key]); });
		var tabState = useState('settings'), settingsTab = tabState[0], setSettingsTab = tabState[1];
		var conflicts = Array.isArray(props.data && props.data.conflicts) ? props.data.conflicts.filter(function (item) {
			return item && (item.group === 'assets' || /css|javascript|script|minify|defer|delay/i.test(String(item.message || '') + String(item.title || '')));
		}).slice(0, 3) : [];
		var intelligence = props.data && props.data.browser_intelligence ? props.data.browser_intelligence : {};

		function load(silent) {
			if (!silent) { setActionBusy('load'); }
			return apiFetch({ path: '/nbh-performance/v1/optimization/status' }).then(function (response) {
				setStatus(response);
				return response;
			}).catch(function (error) {
				if (!silent) { props.notify(error.message || 'Unable to load CSS & JavaScript status.', 'error'); }
				throw error;
			}).then(function (response) {
				if (!silent) { setActionBusy(''); }
				return response;
			}, function () {
				if (!silent) { setActionBusy(''); }
			});
		}

		useEffect(function () { load(false); }, []);

		function runOpt(path, confirmConfig, successMessage) {
			var start = function () {
				setActionBusy(path);
				apiFetch({ path: '/nbh-performance/v1/optimization/' + path, method: 'POST', data: {} }).then(function (response) {
					if (response.optimization) { setStatus(response.optimization); }
					else { load(true); }
					props.notify(successMessage, 'success');
				}).catch(function (error) {
					props.notify(error.message || 'Optimization action failed.', 'error');
				}).then(function () { setActionBusy(''); });
			};
			if (confirmConfig) {
				props.confirmAction(confirmConfig).then(function (confirmed) { if (confirmed) { start(); } });
				return;
			}
			start();
		}

		function runEngine(action, confirmConfig) {
			var start = function () {
				var result = props.runAction(action);
				if (result && typeof result.then === 'function') {
					result.then(function () { load(true); }, function () { load(true); });
				} else {
					setTimeout(function () { load(true); }, 600);
				}
			};
			if (confirmConfig) {
				props.confirmAction(confirmConfig).then(function (confirmed) { if (confirmed) { start(); } });
				return;
			}
			start();
		}

		var data = status || {};
		var assets = data.assets || {};
		var critical = data.critical || {};
		var used = data.used || {};
		var emergency = !!assets.emergency_rollback;

		return el('div', { className: 'np-view np-opt-view' },
			featureGroup ? el(FeaturePanel, { group: featureGroup, busy: props.busy, toggle: function (option, checked) {
				var result = props.toggle(option, checked);
				if (result && typeof result.then === 'function') {
					result.then(function () { load(true); }, function () {});
				} else {
					setTimeout(function () { load(true); }, 350);
				}
			} }) : null,
			el('section', { className: 'np-panel np-opt-hero' },
				el('div', { className: 'np-opt-hero-copy' },
					el('span', { className: 'np-kicker' }, 'CSS & JavaScript'),
					el('h3', null, 'Faster assets without breaking interactions'),
					el('p', null, 'Minify safely, defer and delay scripts, then generate conservative PHP Critical and Used CSS with static safeguards. Flush caches or resume after emergency rollback when needed.')
				),
				el('div', { className: 'np-opt-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { load(false); } }, Icon('refresh'), actionBusy === 'load' ? 'Refreshing…' : 'Refresh'),
					el('span', { className: 'np-quality-pill is-' + (emergency ? 'warning' : (data.runtime_ready ? 'good' : (data.safe_mode ? 'warning' : 'muted'))) }, emergency ? 'Emergency rollback on' : (data.safe_mode ? 'Safe Mode pauses assets' : (data.runtime_ready ? Number(data.active_features || 0) + ' features active' : 'No CSS/JS features on')))
				)
			),
			conflicts.length ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'Asset optimizer overlap detected'), el('p', null, conflicts[0].message || conflicts[0].title || 'Another plugin may also be optimizing CSS or JavaScript.'))) : null,
			data.safe_mode ? el('div', { className: 'np-object-alert is-warning' }, Icon('shield'), el('div', null, el('strong', null, 'Safe Mode is active'), el('p', null, 'CSS & JavaScript runtime stays paused until Safe Mode is turned off.'))) : null,
			emergency ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null,
				el('strong', null, 'Emergency asset rollback is active'),
				el('p', null, 'Recent minify failures tripped protection. Clear the failure log to resume defer, delay and minification.'),
				el('button', { type: 'button', className: 'np-button np-button-primary', style: { marginTop: '10px' }, disabled: !!actionBusy, onClick: function () {
					runOpt('health/resume', {
						variant: 'warning',
						kicker: 'Before resuming assets',
						title: 'Clear failures and resume optimization?',
						message: 'NBH Performance will clear the asset failure log and turn off emergency rollback.',
						note: 'Watch the frontend after this. If issues return, Safe Mode or exclusions may be needed.',
						confirmText: 'Yes, resume assets',
						cancelText: 'Keep rollback',
						icon: 'shield'
					}, 'Asset optimization resumed.');
				} }, Icon('shield'), actionBusy === 'health/resume' ? 'Resuming…' : 'Resume assets')
			)) : null,
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Optimization status', description: 'Minify cache and conservative PHP Critical/Used CSS. Static analysis does not measure the first screen.' }),
				el('div', { className: 'np-opt-status-grid' },
					el('article', { className: 'np-opt-status-card' }, el('small', null, 'Minified CSS'), el('strong', null, String(Number(assets.css_files || 0))), el('p', null, formatBytes(assets.css_bytes || 0) + (data.css_minify ? ' · active' : ' · off'))),
					el('article', { className: 'np-opt-status-card' }, el('small', null, 'Minified JS'), el('strong', null, String(Number(assets.js_files || 0))), el('p', null, formatBytes(assets.js_bytes || 0) + (data.js_minify ? ' · active' : ' · off'))),
					el('article', { className: 'np-opt-status-card' }, el('small', null, 'Critical CSS'), el('strong', null, String(Number(critical.entries || critical.files || 0))), el('p', null, formatBytes(critical.bytes || 0) + (data.critical_css ? ' · active' : ' · off'))),
					el('article', { className: 'np-opt-status-card' }, el('small', null, 'Used CSS'), el('strong', null, String(Number(used.files || 0))), el('p', null, formatBytes(used.bytes || 0) + (data.unused_css ? ' · active' : ' · off')))
				)
			),
			el(BrowserIntelligencePanel, { data: intelligence }),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Essential actions', description: 'Flush minify cache, queue Critical CSS, or generate homepage CSS. Engine tools stay under Advanced.' }),
				el('div', { className: 'np-opt-action-grid np-opt-essential-grid' },
					el('article', { className: 'np-opt-action-card' },
						el('div', { className: 'np-opt-action-top' }, el('span', { className: 'np-opt-action-icon' }, Icon('broom')), el('div', null, el('strong', null, 'Flush minify cache'), el('p', null, 'Delete cached minified CSS/JS and combined manifests. Files rebuild on demand.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-opt-action-cta', disabled: !!actionBusy, onClick: function () {
							runOpt('assets/flush', {
								variant: 'warning',
								kicker: 'Before flushing assets',
								title: 'Clear the minify and combine cache?',
								message: 'NBH Performance will delete cached minified CSS/JS files and combined manifests.',
								note: 'Original theme and plugin files stay untouched. Optimized copies rebuild as pages load.',
								confirmText: 'Yes, flush assets',
								cancelText: 'Keep cache',
								icon: 'trash'
							}, 'Minify cache cleared.');
						} }, Icon('trash'), actionBusy === 'assets/flush' ? 'Flushing…' : 'Flush assets')
					),
					el('article', { className: 'np-opt-action-card' },
						el('div', { className: 'np-opt-action-top' }, el('span', { className: 'np-opt-action-icon' }, Icon('bolt')), el('div', null, el('strong', null, 'Queue Critical CSS'), el('p', null, 'Enqueue homepage plus recently updated posts for desktop and mobile generation.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-opt-action-cta', disabled: !!actionBusy || !!data.safe_mode, onClick: function () { runOpt('critical/queue', null, 'Critical CSS jobs queued for key pages.'); } }, Icon('bolt'), actionBusy === 'critical/queue' ? 'Queueing…' : 'Queue Critical CSS')
					),
					el('article', { className: 'np-opt-action-card' },
						el('div', { className: 'np-opt-action-top' }, el('span', { className: 'np-opt-action-icon' }, Icon('trash')), el('div', null, el('strong', null, 'Flush Critical CSS'), el('p', null, 'Remove stored Critical CSS. Pages fall back until regeneration completes.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-opt-action-cta', disabled: !!actionBusy, onClick: function () {
							runOpt('critical/flush', {
								variant: 'warning',
								kicker: 'Before flushing Critical CSS',
								title: 'Clear all Critical CSS files?',
								message: 'NBH Performance will delete stored Critical CSS for every URL.',
								note: 'Queue generation again after this so conservative Critical CSS can rebuild.',
								confirmText: 'Yes, flush Critical CSS',
								cancelText: 'Keep files',
								icon: 'trash'
							}, 'Critical CSS cache cleared.');
						} }, Icon('trash'), actionBusy === 'critical/flush' ? 'Flushing…' : 'Flush Critical CSS')
					),
					el('article', { className: 'np-opt-action-card' },
						el('div', { className: 'np-opt-action-top' }, el('span', { className: 'np-opt-action-icon' }, Icon('rocket')), el('div', null, el('strong', null, 'Generate homepage CSS'), el('p', null, 'Queue conservative PHP CSS generation for the homepage. You may close this tab.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-opt-action-cta', disabled: !!actionBusy || !!props.busy.browser_css_generate, onClick: function () { runEngine('browser_css_generate'); } }, Icon('rocket'), props.busy.browser_css_generate ? 'Queueing…' : 'Generate homepage')
					)
				)
			),
			settings && props.showAdvancedSettings ? el('div', { className: 'np-unified-settings-area' },
				el('section', { className: 'np-panel' },
					el(PanelHeader, { title: 'Engine & Used CSS tools', description: 'Flush generated CSS and check the built-in PHP engine.' }),
					el('div', { className: 'np-opt-action-grid' },
						el('article', { className: 'np-opt-action-card' },
							el('div', { className: 'np-opt-action-top' }, el('span', { className: 'np-opt-action-icon' }, Icon('broom')), el('div', null, el('strong', null, 'Flush Used CSS'), el('p', null, 'Remove Used CSS bundles. Full stylesheets load until new bundles are generated.'))),
							el('button', { type: 'button', className: 'np-button np-button-primary np-opt-action-cta', disabled: !!actionBusy, onClick: function () {
								runOpt('used/flush', {
									variant: 'warning',
									kicker: 'Before flushing Used CSS',
									title: 'Clear all Used CSS bundles?',
									message: 'NBH Performance will delete Used CSS files and manifests.',
									note: 'Visual regression guard still applies when new bundles are generated.',
									confirmText: 'Yes, flush Used CSS',
									cancelText: 'Keep bundles',
									icon: 'trash'
								}, 'Used CSS cache cleared.');
							} }, Icon('trash'), actionBusy === 'used/flush' ? 'Flushing…' : 'Flush Used CSS')
						),
						el('article', { className: 'np-opt-action-card' },
							el('div', { className: 'np-opt-action-top' }, el('span', { className: 'np-opt-action-icon' }, Icon('eye')), el('div', null, el('strong', null, 'Check PHP engine'), el('p', null, 'Check PHP DOM and the bundled CSS parser. No external engine is required.'))),
							el('button', { type: 'button', className: 'np-button np-button-primary np-opt-action-cta', disabled: !!actionBusy || !!props.busy.browser_engine_test, onClick: function () { runEngine('browser_engine_test'); } }, Icon('eye'), props.busy.browser_engine_test ? 'Testing…' : 'Test engine')
						),

					)
				),
				el('div', { className: 'np-tabs', role: 'tablist' },
					Tab('settings', 'Settings', settingsTab, setSettingsTab, regular.length),
					Tab('exclusions', 'Exclusions', settingsTab, setSettingsTab, exclusions.length)
				),
				settingsTab === 'exclusions'
					? el(FieldPanel, { sectionId: 'optimization', title: 'Exclusion manager', description: 'Protect builder styles, critical scripts, delay exclusions and Used CSS safelists.', keys: exclusions, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts })
					: el(FieldPanel, { sectionId: 'optimization', title: 'CSS & JavaScript settings', description: 'Combine mode, Script Manager, bloat controls, delay timeout and visual thresholds.', keys: regular, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts }),
				el(StickyAdvancedSave, { dirty: props.dirty, busy: !!props.busy['save-optimization'], setAdvancedSettings: props.setAdvancedSettings, onDiscard: function () { props.reset('optimization'); }, onSave: function () { props.save('optimization'); } })
			) : el(AdvancedSettingsGate, { title: 'Advanced CSS & JavaScript settings', description: 'Used CSS flush, PHP engine checks, combine mode, delay timeout and exclusions.', count: fieldKeys.length, onOpen: function () { props.setAdvancedSettings(true); } })
		);
	}

	function PreloadView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var featureGroup = props.data && props.data.features ? props.data.features.preload : null;
		var settings = props.data && props.data.settings ? props.data.settings.preload : null;
		var fields = settings && settings.fields ? settings.fields : {};
		var fieldKeys = Object.keys(fields);
		var exclusions = fieldKeys.filter(function (key) { return isExclusion(fields[key]); });
		var regular = fieldKeys.filter(function (key) { return !isExclusion(fields[key]); });
		var tabState = useState('settings'), settingsTab = tabState[0], setSettingsTab = tabState[1];

		function load(silent) {
			if (!silent) { setActionBusy('load'); }
			return apiFetch({ path: '/nbh-performance/v1/preload/status' }).then(function (response) {
				setStatus(response);
				return response;
			}).catch(function (error) {
				if (!silent) { props.notify(error.message || 'Unable to load Preload & Navigation status.', 'error'); }
				throw error;
			}).then(function (response) {
				if (!silent) { setActionBusy(''); }
				return response;
			}, function () {
				if (!silent) { setActionBusy(''); }
			});
		}

		useEffect(function () { load(false); }, []);

		useEffect(function () {
			var run = status && status.run ? status.run : {};
			if (!run || (run.status !== 'running' && run.status !== 'paused')) { return undefined; }
			var timer = window.setInterval(function () { load(true); }, 4000);
			return function () { window.clearInterval(timer); };
		}, [status && status.run && status.run.status]);

		function runPreload(path, confirmConfig, successMessage) {
			var start = function () {
				setActionBusy(path);
				apiFetch({ path: '/nbh-performance/v1/preload/' + path, method: 'POST', data: {} }).then(function (response) {
					if (response.preload) { setStatus(response.preload); }
					else { load(true); }
					props.notify(successMessage, 'success');
					if (typeof props.refresh === 'function') { props.refresh(); }
				}).catch(function (error) {
					props.notify(error.message || 'Preload action failed.', 'error');
				}).then(function () { setActionBusy(''); });
			};
			if (confirmConfig) {
				props.confirmAction(confirmConfig).then(function (confirmed) { if (confirmed) { start(); } });
				return;
			}
			start();
		}

		var data = status || {};
		var run = data.run || {};
		var priority = data.priority_elements || {};
		var runStatus = String(data.run_status || run.status || 'idle');
		var runActive = runStatus === 'running' || runStatus === 'paused';
		var heroTone = data.safe_mode || !data.cache_enabled ? 'warning' : (data.runtime_ready ? (runStatus === 'running' ? 'good' : (runStatus === 'paused' ? 'warning' : 'good')) : 'muted');
		var heroLabel = data.safe_mode ? 'Safe Mode pauses navigation' : (!data.cache_enabled ? 'Page cache required' : (runStatus === 'running' ? 'Preload running' : (runStatus === 'paused' ? 'Preload paused' : (data.runtime_ready ? Number(data.active_features || 0) + ' features active' : 'No navigation features on'))));
		var completed = Number(run.completed || 0);
		var failed = Number(run.failed || 0);
		var skipped = Number(run.skipped || 0);
		var queued = Number(run.queued || 0);
		var done = completed + failed + skipped;

		return el('div', { className: 'np-view np-preload-view' },
			featureGroup ? el(FeaturePanel, { group: featureGroup, busy: props.busy, toggle: function (option, checked) {
				var result = props.toggle(option, checked);
				if (result && typeof result.then === 'function') {
					result.then(function () { load(true); }, function () {});
				} else {
					setTimeout(function () { load(true); }, 350);
				}
			} }) : null,
			el('section', { className: 'np-panel np-preload-hero' },
				el('div', { className: 'np-preload-hero-copy' },
					el('span', { className: 'np-kicker' }, 'Preload & Navigation'),
					el('h3', null, 'Warm pages and speed up navigation'),
					el('p', null, 'Queue sitemap preload, Instant Page and Speculation Rules, and use Priority Elements learning to prioritize LCP assets — with safe run controls.')
				),
				el('div', { className: 'np-preload-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { load(false); } }, Icon('refresh'), actionBusy === 'load' ? 'Refreshing…' : 'Refresh'),
					el('span', { className: 'np-quality-pill is-' + heroTone }, heroLabel)
				)
			),
			data.safe_mode ? el('div', { className: 'np-object-alert is-warning' }, Icon('shield'), el('div', null, el('strong', null, 'Safe Mode is active'), el('p', null, 'Sitemap preload and navigation runtime stay paused until Safe Mode is turned off.'))) : null,
			!data.cache_enabled ? el('div', { className: 'np-object-alert is-warning' }, Icon('layers'), el('div', null, el('strong', null, 'Page caching is off'), el('p', null, 'Enable Page Cache before starting a sitemap preload run. Instant Page and Speculation Rules can still be configured.'))) : null,
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Navigation status', description: 'Current preload run, sitemap warming and Priority Elements learning.' }),
				el('div', { className: 'np-preload-status-grid' },
					el('article', { className: 'np-preload-status-card' }, el('small', null, 'Preload run'), el('strong', null, humanizeStatus(runStatus)), el('p', null, queued ? (done + ' of ' + queued + ' processed') : (run.started_at ? ('Started ' + formatTimestamp(run.started_at)) : 'No active run'))),
					el('article', { className: 'np-preload-status-card' }, el('small', null, 'Warm results'), el('strong', null, String(completed)), el('p', null, failed + ' failed · ' + skipped + ' skipped')),
					el('article', { className: 'np-preload-status-card' }, el('small', null, 'Navigation'), el('strong', null, data.instant_page || data.speculation_rules ? 'Active' : 'Off'), el('p', null, data.speculation_rules ? ('Speculation · ' + humanizeStatus(data.speculation_mode || 'prefetch')) : (data.instant_page ? 'Instant Page ready' : 'Enable Instant Page or Speculation Rules'))),
					el('article', { className: 'np-preload-status-card' }, el('small', null, 'Priority Elements'), el('strong', null, String(Number(priority.urls || 0))), el('p', null, Number(priority.samples || 0) + ' samples' + (priority.last_report_at ? (' · ' + formatTimestamp(priority.last_report_at)) : '')))
				)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Run controls', description: 'Start, pause, resume or cancel sitemap preload. Warm the homepage independently when needed.' }),
				el('div', { className: 'np-preload-action-grid' },
					el('article', { className: 'np-preload-action-card' },
						el('div', { className: 'np-preload-action-top' }, el('span', { className: 'np-preload-action-icon' }, Icon('rocket')), el('div', null, el('strong', null, 'Start sitemap preload'), el('p', null, 'Discover sitemap URLs and queue a bounded warm-up run (up to ' + String(Number(data.max_urls || 500)) + ' URLs).'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-preload-action-cta', disabled: !!actionBusy || !data.can_start_preload || runActive, onClick: function () {
							runPreload('start', {
								variant: 'info',
								kicker: 'Before starting preload',
								title: 'Start a sitemap preload run?',
								message: 'NBH Performance will discover sitemap URLs and warm pages in the background using the page cache.',
								note: 'Safe Mode must stay off and Page Cache must stay enabled. You can pause or cancel the run at any time.',
								confirmText: 'Yes, start preload',
								cancelText: 'Not now',
								icon: 'rocket'
							}, 'Sitemap preload started.');
						} }, Icon('rocket'), actionBusy === 'start' ? 'Starting…' : 'Start preload')
					),
					runStatus === 'paused'
						? el('article', { className: 'np-preload-action-card' },
							el('div', { className: 'np-preload-action-top' }, el('span', { className: 'np-preload-action-icon' }, Icon('play')), el('div', null, el('strong', null, 'Resume preload'), el('p', null, 'Continue the paused sitemap warm-up run.'))),
							el('button', { type: 'button', className: 'np-button np-button-primary np-preload-action-cta', disabled: !!actionBusy || !data.can_start_preload, onClick: function () { runPreload('resume', null, 'Preload resumed.'); } }, Icon('play'), actionBusy === 'resume' ? 'Resuming…' : 'Resume')
						)
						: el('article', { className: 'np-preload-action-card' },
							el('div', { className: 'np-preload-action-top' }, el('span', { className: 'np-preload-action-icon' }, Icon('clock')), el('div', null, el('strong', null, 'Pause preload'), el('p', null, 'Pause workers without discarding the current run progress.'))),
							el('button', { type: 'button', className: 'np-button np-button-primary np-preload-action-cta', disabled: !!actionBusy || runStatus !== 'running', onClick: function () { runPreload('pause', null, 'Preload paused.'); } }, Icon('clock'), actionBusy === 'pause' ? 'Pausing…' : 'Pause')
						),
					el('article', { className: 'np-preload-action-card' },
						el('div', { className: 'np-preload-action-top' }, el('span', { className: 'np-preload-action-icon' }, Icon('trash')), el('div', null, el('strong', null, 'Cancel preload'), el('p', null, 'Stop the active run. Remaining queued URLs will not be warmed.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-preload-action-cta', disabled: !!actionBusy || !runActive, onClick: function () {
							runPreload('cancel', {
								variant: 'warning',
								kicker: 'Before cancelling',
								title: 'Cancel the current preload run?',
								message: 'NBH Performance will stop the active sitemap preload. Progress already completed stays in history.',
								note: 'You can start a new run later. Page cache files already warmed are not deleted.',
								confirmText: 'Yes, cancel run',
								cancelText: 'Keep running',
								icon: 'warning'
							}, 'Preload cancelled.');
						} }, Icon('trash'), actionBusy === 'cancel' ? 'Cancelling…' : 'Cancel run')
					),
					el('article', { className: 'np-preload-action-card' },
						el('div', { className: 'np-preload-action-top' }, el('span', { className: 'np-preload-action-icon' }, Icon('layers')), el('div', null, el('strong', null, 'Warm homepage'), el('p', null, 'Prime the homepage cache now' + (data.mobile_variant ? ' for desktop and mobile.' : '.')))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-preload-action-cta', disabled: !!actionBusy || !data.can_start_preload, onClick: function () { runPreload('warm-homepage', null, 'Homepage warm request finished.'); } }, Icon('layers'), actionBusy === 'warm-homepage' ? 'Warming…' : 'Warm homepage')
					)
				)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Priority Elements', description: 'Browser learning for LCP images, fonts and useful origins. Clear only when you want a fresh start.' }),
				el('div', { className: 'np-preload-action-grid' },
					el('article', { className: 'np-preload-action-card' },
						el('div', { className: 'np-preload-action-top' }, el('span', { className: 'np-preload-action-icon' }, Icon('chart')), el('div', null, el('strong', null, 'Learning snapshot'), el('p', null, Number(priority.urls || 0) + ' URLs · sample rate ' + String(Number(data.sample_rate || 0)) + '% · avg quality ' + String(Number(priority.average_quality || 0))))),
						el('button', { type: 'button', className: 'np-button np-button-ghost np-preload-action-cta', disabled: !!actionBusy, onClick: function () { load(false); } }, Icon('refresh'), 'Refresh learning')
					),
					el('article', { className: 'np-preload-action-card' },
						el('div', { className: 'np-preload-action-top' }, el('span', { className: 'np-preload-action-icon' }, Icon('broom')), el('div', null, el('strong', null, 'Clear learned data'), el('p', null, 'Remove Priority Elements aggregates. New browser reports will rebuild learning.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-preload-action-cta', disabled: !!actionBusy || !Number(priority.urls || 0), onClick: function () {
							runPreload('priority-elements/clear', {
								variant: 'warning',
								kicker: 'Before clearing learning',
								title: 'Clear Priority Elements learning?',
								message: 'NBH Performance will delete learned LCP, font and origin signals for this site.',
								note: 'Manual priority and font preload URL lists in settings are not changed.',
								confirmText: 'Yes, clear learning',
								cancelText: 'Keep data',
								icon: 'broom'
							}, 'Priority Elements learning cleared.');
						} }, Icon('broom'), actionBusy === 'priority-elements/clear' ? 'Clearing…' : 'Clear learning')
					),
					el('article', { className: 'np-preload-action-card' },
						el('div', { className: 'np-preload-action-top' }, el('span', { className: 'np-preload-action-icon' }, Icon('eye')), el('div', null, el('strong', null, 'Regenerate homepage CSS'), el('p', null, 'Queue conservative desktop and mobile PHP CSS generation. Browser resource learning is collected separately from page visits.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-preload-action-cta', disabled: !!actionBusy || !!props.busy.browser_css_generate, onClick: function () { props.runAction('browser_css_generate'); setTimeout(function () { load(true); }, 900); } }, Icon('rocket'), props.busy.browser_css_generate ? 'Queueing…' : 'Generate homepage')
					)
				)
			),
			settings && props.showAdvancedSettings ? el('div', { className: 'np-unified-settings-area' },
				el('div', { className: 'np-tabs', role: 'tablist' },
					Tab('settings', 'Settings', settingsTab, setSettingsTab, regular.length),
					Tab('exclusions', 'Exclusions', settingsTab, setSettingsTab, exclusions.length)
				),
				settingsTab === 'exclusions'
					? el(FieldPanel, { sectionId: 'preload', title: 'Exclusion manager', description: 'Keep transactional or private URLs out of preload and Speculation Rules.', keys: exclusions, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts })
					: el(FieldPanel, { sectionId: 'preload', title: 'Preload settings', description: 'Batch size, timeouts, speculation mode, Priority Elements sampling and manual URL lists.', keys: regular, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts }),
				el(StickyAdvancedSave, { dirty: props.dirty, busy: !!props.busy['save-preload'], setAdvancedSettings: props.setAdvancedSettings, onDiscard: function () { props.reset('preload'); }, onSave: function () { props.save('preload'); } })
			) : el(AdvancedSettingsGate, { title: 'Advanced Preload & Navigation settings', description: 'Batch size, timeouts, speculation mode, Priority Elements sampling, manual preload URLs and exclusions.', count: fieldKeys.length, onOpen: function () { props.setAdvancedSettings(true); } })
		);
	}

	function MediaView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var featureGroup = props.data && props.data.features ? props.data.features.media : null;
		var settings = props.data && props.data.settings ? props.data.settings.media : null;
		var fields = settings && settings.fields ? settings.fields : {};
		var fieldKeys = Object.keys(fields);
		var exclusions = fieldKeys.filter(function (key) { return isExclusion(fields[key]); });
		var regular = fieldKeys.filter(function (key) { return !isExclusion(fields[key]); });
		var tabState = useState('settings'), settingsTab = tabState[0], setSettingsTab = tabState[1];
		var conflicts = Array.isArray(props.data && props.data.conflicts) ? props.data.conflicts.filter(function (item) {
			return item && (item.group === 'lazyload' || item.group === 'media' || /lazy|youtube|font|media/i.test(String(item.message || '') + String(item.title || '')));
		}).slice(0, 3) : [];

		function load(silent) {
			if (!silent) { setActionBusy('load'); }
			return apiFetch({ path: '/nbh-performance/v1/media/status' }).then(function (response) {
				setStatus(response);
				return response;
			}).catch(function (error) {
				if (!silent) { props.notify(error.message || 'Unable to load media delivery status.', 'error'); }
				throw error;
			}).then(function (response) {
				if (!silent) { setActionBusy(''); }
				return response;
			}, function () {
				if (!silent) { setActionBusy(''); }
			});
		}

		useEffect(function () { load(false); }, []);


		var data = status || {};

		return el('div', { className: 'np-view np-media-view' },
			featureGroup ? el(FeaturePanel, { group: featureGroup, busy: props.busy, toggle: function (option, checked) {
				var result = props.toggle(option, checked);
				if (result && typeof result.then === 'function') {
					result.then(function () { load(true); }, function () {});
				} else {
					setTimeout(function () { load(true); }, 350);
				}
			} }) : null,
			el('section', { className: 'np-panel np-media-hero' },
				el('div', { className: 'np-media-hero-copy' },
					el('span', { className: 'np-kicker' }, 'Media Delivery'),
					el('h3', null, 'Faster images and video'),
					el('p', null, 'Lazy-load off-screen media, replace heavy YouTube embeds, and delay existing chat widgets until interaction or consent.')
				),
				el('div', { className: 'np-media-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { load(false); } }, Icon('refresh'), actionBusy === 'load' ? 'Refreshing…' : 'Refresh'),
					el('span', { className: 'np-quality-pill is-' + (data.runtime_ready ? 'good' : (data.safe_mode ? 'warning' : 'muted')) }, data.safe_mode ? 'Safe Mode pauses media' : (data.runtime_ready ? Number(data.active_features || 0) + ' features active' : 'No media features on'))
				)
			),
			conflicts.length ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'Lazy-load overlap detected'), el('p', null, conflicts[0].message || conflicts[0].title || 'Another plugin may also be managing lazy loading.'))) : null,
			data.safe_mode ? el('div', { className: 'np-object-alert is-warning' }, Icon('shield'), el('div', null, el('strong', null, 'Safe Mode is active'), el('p', null, 'Media Delivery runtime stays paused until Safe Mode is turned off.'))) : null,
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Delivery status', description: 'What is active right now for images, video, responsive delivery and consent.' }),
				el('div', { className: 'np-media-status-grid' },
					el('article', { className: 'np-media-status-card' }, el('small', null, 'Lazy loading'), el('strong', null, data.lazy_load ? 'Active' : 'Off'), el('p', null, data.eager_images !== undefined ? Number(data.eager_images || 0) + ' early images stay eager.' : 'Prioritize LCP media.')),
					el('article', { className: 'np-media-status-card' }, el('small', null, 'YouTube facade'), el('strong', null, data.youtube_facade ? 'Active' : 'Off'), el('p', null, data.youtube_autoload ? 'Autoloads after media consent.' : 'Click-to-load embeds.')),
					el('article', { className: 'np-media-status-card' }, el('small', null, 'Responsive images'), el('strong', null, data.responsive_images ? 'Active' : 'Off'), el('p', null, 'Deliver correctly sized image sources.')),
					el('article', { className: 'np-media-status-card' }, el('small', null, 'Chat delay'), el('strong', null, data.chat_facade ? 'Active' : 'Off'), el('p', null, 'Load existing widgets after interaction or consent.'))
				)
			),
			settings && props.showAdvancedSettings ? el('div', { className: 'np-unified-settings-area' },
				el('div', { className: 'np-tabs', role: 'tablist' },
					Tab('settings', 'Settings', settingsTab, setSettingsTab, regular.length),
					Tab('exclusions', 'Exclusions', settingsTab, setSettingsTab, exclusions.length)
				),
				settingsTab === 'exclusions'
					? el(FieldPanel, { sectionId: 'media', title: 'Exclusion manager', description: 'Protect embeds, lazy-load classes, LCP candidates and font families.', keys: exclusions, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts })
					: el(FieldPanel, { sectionId: 'media', title: 'Media settings', description: 'Eager image count, background lazy-load, video preload and font-display.', keys: regular, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts }),
				el(StickyAdvancedSave, { dirty: props.dirty, busy: !!props.busy['save-media'], setAdvancedSettings: props.setAdvancedSettings, onDiscard: function () { props.reset('media'); }, onSave: function () { props.save('media'); } })
			) : el(AdvancedSettingsGate, { title: 'Advanced media settings', description: 'Eager image count, background lazy-load, video preload, font-display and exclusions.', count: fieldKeys.length, onOpen: function () { props.setAdvancedSettings(true); } })
		);
	}

	function ImagesView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var autoState = useState(false), autoRunning = autoState[0], setAutoRunning = autoState[1];
		var stopRef = useRef(false);
		var featureGroup = props.data && props.data.features ? props.data.features.images : null;
		var settings = props.data && props.data.settings ? props.data.settings.images : null;
		var fields = settings && settings.fields ? settings.fields : {};
		var fieldKeys = Object.keys(fields);
		var exclusions = fieldKeys.filter(function (key) { return isExclusion(fields[key]); });
		var regular = fieldKeys.filter(function (key) { return !isExclusion(fields[key]); });
		var tabState = useState('settings'), settingsTab = tabState[0], setSettingsTab = tabState[1];

		function load(silent) {
			if (!silent) { setActionBusy('load'); }
			return apiFetch({ path: '/nbh-performance/v1/images/status' }).then(function (response) {
				setStatus(response);
				return response;
			}).catch(function (error) {
				if (!silent) { props.notify(error.message || 'Unable to load image status.', 'error'); }
				throw error;
			}).then(function (response) {
				if (!silent) { setActionBusy(''); }
				return response;
			}, function (error) {
				if (!silent) { setActionBusy(''); }
				throw error;
			});
		}

		useEffect(function () { load(false); }, []);

		useEffect(function () {
			var batch = status && status.batch ? status.batch : {};
			if (!batch || batch.status !== 'running') { return undefined; }
			var timer = window.setInterval(function () { load(true); }, 4000);
			return function () { window.clearInterval(timer); };
		}, [status && status.batch && status.batch.status]);

		function runConvertRound(mode) {
			return apiFetch({ path: '/nbh-performance/v1/images/convert', method: 'POST', data: { mode: mode, force: mode === 'rebuild' } });
		}

		async function optimizeNext() {
			setActionBusy('convert');
			try {
				var result = await runConvertRound('pending');
				setStatus(function (current) {
					return Object.assign({}, current || {}, {
						remaining: result.remaining,
						converted: result.converted_total,
						total_eligible: result.total_eligible,
						progress_percent: result.progress_percent,
						last_batch: result
					});
				});
				props.notify(Number(result.remaining || 0) > 0 ? 'Optimized ' + Number(result.converted || 0) + ' images. ' + Number(result.remaining || 0) + ' remaining.' : 'All eligible images are optimized.', 'success');
				await load(true);
			} catch (error) {
				props.notify((error && error.message) || 'Image conversion failed.', 'error');
			}
			setActionBusy('');
		}

		async function optimizeAll() {
			stopRef.current = false;
			setAutoRunning(true);
			setActionBusy('convert-all');
			var rounds = 0;
			var totals = { processed: 0, converted: 0, skipped: 0, failed: 0 };
			try {
				while (!stopRef.current && rounds < 200) {
					rounds += 1;
					props.notify('Optimizing images · batch ' + rounds + '…', 'info');
					var result = await runConvertRound('pending');
					totals.processed += Number(result.processed || 0);
					totals.converted += Number(result.converted || 0);
					totals.skipped += Number(result.skipped || 0);
					totals.failed += Number(result.failed || 0);
					setStatus(function (current) {
						return Object.assign({}, current || {}, {
							remaining: result.remaining,
							converted: result.converted_total,
							total_eligible: result.total_eligible,
							progress_percent: result.progress_percent,
							last_batch: result
						});
					});
					if (!result.repeat_required || Number(result.processed || 0) === 0) { break; }
				}
				props.notify(stopRef.current ? 'Image optimization stopped safely.' : 'Library optimization finished. ' + totals.converted + ' images processed across ' + rounds + ' batches.', stopRef.current ? 'warning' : 'success');
				await load(true);
			} catch (error) {
				props.notify((error && error.message) || 'Image optimization failed.', 'error');
			}
			setAutoRunning(false);
			setActionBusy('');
		}

		function stopAuto() {
			stopRef.current = true;
			props.notify('Stopping after the current batch…', 'info');
		}

		function rebuildBatch() {
			props.confirmAction({
				variant: 'warning',
				kicker: 'Before image rebuild',
				title: 'Rebuild optimized images for the next batch?',
				message: 'NBH Performance will regenerate WebP and AVIF for recently optimized images using your current quality settings. Original JPEG and PNG files stay safe.',
				note: 'Best during low traffic. CPU and disk activity will rise briefly.',
				confirmText: 'Yes, rebuild images',
				cancelText: 'Not now',
				icon: 'convert'
			}).then(function (confirmed) {
				if (!confirmed) { return; }
				setActionBusy('rebuild');
				runConvertRound('rebuild').then(function (result) {
					props.notify('Rebuilt ' + Number(result.converted || 0) + ' images.', 'success');
					return load(true);
				}).catch(function (error) {
					props.notify(error.message || 'Rebuild failed.', 'error');
				}).then(function () { setActionBusy(''); });
			});
		}

		function startBackgroundBatch(mode) {
			var force = mode === 'rebuild';
			var title = force ? 'Queue a background rebuild for up to 1,000 images?' : 'Start a background optimization batch?';
			props.confirmAction({
				variant: force ? 'warning' : 'info',
				kicker: 'Background image batch',
				title: title,
				message: 'NBH Performance will enqueue image jobs and process them through the background queue. You can pause or cancel anytime.',
				note: 'Original files stay untouched. Progress updates live while the batch is running.',
				confirmText: force ? 'Queue rebuild' : 'Start background batch',
				cancelText: 'Cancel',
				icon: 'convert'
			}).then(function (confirmed) {
				if (!confirmed) { return; }
				setActionBusy('batch');
				apiFetch({ path: '/nbh-performance/v1/images/batch/start', method: 'POST', data: { mode: mode, force: force, limit: 1000 } }).then(function () {
					props.notify('Background image batch started.', 'success');
					return load(true);
				}).catch(function (error) {
					props.notify(error.message || 'Could not start image batch.', 'error');
				}).then(function () { setActionBusy(''); });
			});
		}

		function batchControl(action) {
			setActionBusy(action);
			apiFetch({ path: '/nbh-performance/v1/images/batch/' + action, method: 'POST', data: {} }).then(function () {
				props.notify('Batch ' + action + ' requested.', 'success');
				return load(true);
			}).catch(function (error) {
				props.notify(error.message || 'Batch action failed.', 'error');
			}).then(function () { setActionBusy(''); });
		}

		function queueMore() {
			setActionBusy('queue');
			apiFetch({ path: '/nbh-performance/v1/images/queue', method: 'POST', data: { mode: 'pending', limit: 100 } }).then(function (response) {
				props.notify('Queued ' + Number(response.queued || 0) + ' image jobs.', 'success');
				return load(true);
			}).catch(function (error) {
				props.notify(error.message || 'Could not queue images.', 'error');
			}).then(function () { setActionBusy(''); });
		}

		var data = status || (props.data && props.data.image_library) || {};
		var batch = data.batch || {};
		var queue = data.queue || {};
		var progress = Math.max(0, Math.min(100, Number(data.progress_percent || 0)));
		var formats = [];
		if (data.webp_enabled) { formats.push('WebP' + (data.webp_supported === false ? ' (limited)' : '')); }
		if (data.avif_enabled) { formats.push('AVIF' + (data.avif_supported === false ? ' (limited)' : '')); }
		var batchRunning = batch.status === 'running' || autoRunning;

		return el('div', { className: 'np-view np-images-view' },
			featureGroup ? el(FeaturePanel, { group: featureGroup, busy: props.busy, toggle: props.toggle }) : null,
			el('section', { className: 'np-panel np-images-hero' },
				el('div', { className: 'np-images-hero-copy' },
					el('span', { className: 'np-kicker' }, 'Image Conversion'),
					el('h3', null, 'Optimize the media library safely'),
					el('p', null, 'Generate WebP and AVIF while keeping every original file. Run a quick batch, finish the whole library, or queue durable background work.')
				),
				el('div', { className: 'np-images-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { load(false); } }, Icon('refresh'), actionBusy === 'load' ? 'Refreshing…' : 'Refresh'),
					autoRunning
						? el('button', { className: 'np-button np-button-danger-solid', onClick: stopAuto }, Icon('warning'), 'Stop')
						: el('button', { className: 'np-button np-button-primary', disabled: !!actionBusy || !Number(data.remaining || 0), onClick: optimizeAll }, Icon('magic'), 'Optimize all remaining')
				)
			),
			data.offload_warning ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'Media offload detected'), el('p', null, data.offload_warning))) : null,
			!data.conversion_enabled ? el('div', { className: 'np-object-alert is-info' }, Icon('info'), el('div', null, el('strong', null, 'Conversion is off'), el('p', null, 'Turn on WebP/AVIF conversion above before optimizing the library.'))) : null,
			data.safe_mode ? el('div', { className: 'np-object-alert is-warning' }, Icon('shield'), el('div', null, el('strong', null, 'Safe Mode is active'), el('p', null, 'Next-gen serving stays paused until Safe Mode is turned off.'))) : null,
			el('section', { className: 'np-panel np-images-progress-panel' },
				el('div', { className: 'np-images-progress-head' },
					el('div', null, el('strong', null, progress + '% complete'), el('p', null, Number(data.converted || 0) + ' of ' + Number(data.total_eligible || 0) + ' eligible images optimized')),
					el('span', { className: 'np-quality-pill is-' + (data.serving_active ? 'good' : 'muted') }, data.serving_active ? 'Serving next-gen' : 'Serving paused')
				),
				el('div', { className: 'np-images-progress-track', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': progress },
					el('span', { style: { width: progress + '%' } })
				),
				el('div', { className: 'np-image-summary-grid' },
					el('article', { className: 'np-image-summary-card' }, el('small', null, 'Remaining'), el('strong', null, String(Number(data.remaining || 0))), el('p', null, 'Still waiting for conversion.')),
					el('article', { className: 'np-image-summary-card' }, el('small', null, 'Storage saved'), el('strong', null, formatBytes(data.source_bytes_saved || 0)), el('p', null, 'From source compression so far.')),
					el('article', { className: 'np-image-summary-card' }, el('small', null, 'Formats'), el('strong', null, formats.length ? formats.join(' + ') : 'None'), el('p', null, data.originals_preserved === false ? 'Review originals setting.' : 'Originals always preserved.')),
					el('article', { className: 'np-image-summary-card' }, el('small', null, 'Queue'), el('strong', null, String(Number(queue.pending || 0))), el('p', null, Number(queue.failed || 0) > 0 ? Number(queue.failed || 0) + ' failed jobs need attention.' : 'Background image jobs waiting.'))
				)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Conversion controls', description: 'Quick sync batches for small libraries, or durable background jobs for larger media libraries.' }),
				el('div', { className: 'np-images-action-grid' },
					el('article', { className: 'np-images-action-card' + ((!Number(data.remaining || 0) || !!actionBusy) ? ' is-disabled' : '') },
						el('div', { className: 'np-images-action-top' },
							el('span', { className: 'np-images-action-icon' }, Icon('convert')),
							el('div', { className: 'np-images-action-copy' },
								el('strong', null, 'Optimize next batch'),
								el('p', null, 'Convert up to ' + String(Number(data.batch_size || 10)) + ' pending images now.')
							)
						),
						el('button', {
							type: 'button',
							className: 'np-button np-button-primary np-images-action-cta',
							disabled: !!actionBusy || !Number(data.remaining || 0),
							onClick: optimizeNext
						}, Icon('convert'), actionBusy === 'convert' ? 'Running…' : 'Run now')
					),
					el('article', { className: 'np-images-action-card' + ((batchRunning || !!actionBusy) ? ' is-disabled' : '') },
						el('div', { className: 'np-images-action-top' },
							el('span', { className: 'np-images-action-icon' }, Icon('rocket')),
							el('div', { className: 'np-images-action-copy' },
								el('strong', null, 'Start background batch'),
								el('p', null, 'Enqueue up to 1,000 remaining images for the job queue.')
							)
						),
						el('button', {
							type: 'button',
							className: 'np-button np-button-primary np-images-action-cta',
							disabled: !!actionBusy || batchRunning,
							onClick: function () { startBackgroundBatch('pending'); }
						}, Icon('rocket'), actionBusy === 'batch' ? 'Starting…' : 'Start batch')
					),
					el('article', { className: 'np-images-action-card' + (!!actionBusy ? ' is-disabled' : '') },
						el('div', { className: 'np-images-action-top' },
							el('span', { className: 'np-images-action-icon' }, Icon('layers')),
							el('div', { className: 'np-images-action-copy' },
								el('strong', null, 'Queue 100 more'),
								el('p', null, 'Add another wave of pending images to the queue.')
							)
						),
						el('button', {
							type: 'button',
							className: 'np-button np-button-primary np-images-action-cta',
							disabled: !!actionBusy,
							onClick: queueMore
						}, Icon('layers'), actionBusy === 'queue' ? 'Queuing…' : 'Queue jobs')
					),
					el('article', { className: 'np-images-action-card' + (!!actionBusy ? ' is-disabled' : '') },
						el('div', { className: 'np-images-action-top' },
							el('span', { className: 'np-images-action-icon' }, Icon('refresh')),
							el('div', { className: 'np-images-action-copy' },
								el('strong', null, 'Rebuild recent batch'),
								el('p', null, 'Regenerate variants for already-optimized images.')
							)
						),
						el('button', {
							type: 'button',
							className: 'np-button np-button-primary np-images-action-cta',
							disabled: !!actionBusy,
							onClick: rebuildBatch
						}, Icon('refresh'), actionBusy === 'rebuild' ? 'Rebuilding…' : 'Rebuild')
					)
				),
				batch && batch.run_id ? el('div', { className: 'np-images-batch-bar' },
					el('div', null,
						el('strong', null, 'Batch ' + String(batch.status || 'idle')),
						el('p', null, Number(batch.processed || 0) + ' / ' + Number(batch.total || 0) + ' processed · ' + Number(batch.succeeded || 0) + ' ok · ' + Number(batch.failed || 0) + ' failed')
					),
					el('div', { className: 'np-images-batch-actions' },
						batch.status === 'running' ? el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { batchControl('pause'); } }, 'Pause') : null,
						batch.status === 'paused' ? el('button', { className: 'np-button np-button-primary', disabled: !!actionBusy, onClick: function () { batchControl('resume'); } }, 'Resume') : null,
						batch.status === 'running' || batch.status === 'paused' ? el('button', { className: 'np-button np-button-danger-solid', disabled: !!actionBusy, onClick: function () { batchControl('cancel'); } }, 'Cancel') : null
					)
				) : null
			),
			settings && props.showAdvancedSettings ? el('div', { className: 'np-unified-settings-area' },
				el('div', { className: 'np-tabs', role: 'tablist' },
					Tab('settings', 'Settings', settingsTab, setSettingsTab, regular.length),
					Tab('exclusions', 'Exclusions', settingsTab, setSettingsTab, exclusions.length)
				),
				settingsTab === 'exclusions'
					? el(FieldPanel, { sectionId: 'images', title: 'Exclusion manager', description: 'Skip private, signed, or incompatible images.', keys: exclusions, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts })
					: el(FieldPanel, { sectionId: 'images', title: 'Image settings', description: 'Quality, formats, batch size and upload conversion.', keys: regular, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts }),
				el(StickyAdvancedSave, { dirty: props.dirty, busy: !!props.busy['save-images'], setAdvancedSettings: props.setAdvancedSettings, onDiscard: function () { props.reset('images'); }, onSave: function () { props.save('images'); } })
			) : el(AdvancedSettingsGate, { title: 'Advanced image settings', description: 'Quality, formats, exclusions and conversion defaults.', count: fieldKeys.length, onOpen: function () { props.setAdvancedSettings(true); } })
		);
	}

	function ImageSummaryPanel(props) {
		var data = props.data || {};
		var queue = data.queue || {};
		var formats = [];
		if (data.webp_enabled) { formats.push('WebP'); }
		if (data.avif_enabled) { formats.push('AVIF'); }
		return el('section', { className: 'np-panel np-image-summary-panel' },
			el(PanelHeader, { title: 'Image summary', description: 'See how many images you have, how many are optimized, and how much space NBH Performance has saved so far.' }),
			el('div', { className: 'np-image-summary-grid' },
				el('article', { className: 'np-image-summary-card' }, el('small', null, 'Images found'), el('strong', null, String(Number(data.total_eligible || 0))), el('p', null, 'Eligible media library images NBH Performance can optimize.')),
				el('article', { className: 'np-image-summary-card' }, el('small', null, 'Images optimized'), el('strong', null, String(Number(data.converted || 0))), el('p', null, Number(data.remaining || 0) > 0 ? String(Number(data.remaining || 0)) + ' still left to optimize.' : 'Everything currently eligible has been processed.')),
				el('article', { className: 'np-image-summary-card' }, el('small', null, 'Storage saved'), el('strong', null, formatBytes(data.source_bytes_saved || 0)), el('p', null, 'Saved from source image compression so far.')),
				el('article', { className: 'np-image-summary-card' }, el('small', null, 'Current setup'), el('strong', null, formats.length ? formats.join(' + ') : 'Not enabled'), el('p', null, queue.pending ? String(Number(queue.pending || 0)) + ' background jobs waiting.' : 'No image jobs waiting right now.'))
			)
		);
	}

	function AgencyProfilesPanel(props) {
		var profileState = useState({ loading: true, profiles: [], active: '' });
		var state = profileState[0];
		var setState = profileState[1];
		function load() {
			setState(function (current) { return Object.assign({}, current, { loading: true }); });
			apiFetch({ path: '/nbh-performance/v1/enterprise/profiles' }).then(function (response) {
				setState({ loading: false, profiles: response.profiles || [], active: response.active || '' });
			}).catch(function (error) {
				setState({ loading: false, profiles: [], active: '' });
				props.notify(error.message || 'Agency profiles could not be loaded.', 'error');
			});
		}
		useEffect(function () { load(); }, []);
		function save() {
			var name = window.prompt('Profile name', 'Balanced client profile');
			if (!name) { return; }
			apiFetch({ path: '/nbh-performance/v1/enterprise/profiles/save', method: 'POST', data: { name: name, description: 'Saved from ' + window.location.hostname } }).then(function () {
				props.notify('Reusable agency profile saved.', 'success'); load();
			}).catch(function (error) { props.notify(error.message || 'Profile could not be saved.', 'error'); });
		}
		function apply(profile) {
			props.confirmAction({ variant: 'info', kicker: 'Before applying profile', title: 'Apply “' + profile.name + '” to this site?', message: 'NBH Performance will apply the saved non-secret settings from this profile to the current site.', note: 'Your current configuration is backed up first, so you can restore it if needed.', confirmText: 'Yes, apply profile', cancelText: 'Not now', icon: 'backup' }).then(function (confirmed) {
				if (!confirmed) { return; }
				apiFetch({ path: '/nbh-performance/v1/enterprise/profiles/apply', method: 'POST', data: { id: profile.id } }).then(function () { props.notify('Agency profile applied and validation queued.', 'success'); load(); }).catch(function (error) { props.notify(error.message || 'Profile could not be applied.', 'error'); });
			});
		}
		function remove(profile) {
			props.confirmAction({ variant: 'warning', kicker: 'Before deleting profile', title: 'Delete “' + profile.name + '”?', message: 'This will remove only the saved reusable profile. The current site settings will stay exactly as they are.', confirmText: 'Yes, delete profile', cancelText: 'No, keep profile', icon: 'trash' }).then(function (confirmed) {
				if (!confirmed) { return; }
				apiFetch({ path: '/nbh-performance/v1/enterprise/profiles/delete', method: 'DELETE', data: { id: profile.id } }).then(function () { props.notify('Agency profile deleted.', 'success'); load(); }).catch(function (error) { props.notify(error.message || 'Profile could not be deleted.', 'error'); });
			});
		}
		return el('section', { className: 'np-panel np-agency-profiles' },
			el('div', { className: 'np-agency-profile-head' }, el(PanelHeader, { title: 'Reusable Agency Profiles', description: 'Capture and deploy non-secret optimization policies across client sites.' }), el('button', { className: 'np-button np-button-primary', onClick: save }, Icon('backup'), 'Save current profile')),
			state.loading ? el('div', { className: 'np-inline-loading' }, el('span', { className: 'np-loader' }), 'Loading profiles…') :
				state.profiles.length ? el('div', { className: 'np-agency-profile-list' }, state.profiles.map(function (profile) {
					var active = state.active === profile.id;
					return el('article', { className: 'np-agency-profile-card' + (active ? ' is-active' : ''), key: profile.id },
						el('div', null, el('div', { className: 'np-agency-profile-title' }, el('strong', null, profile.name), active ? el('span', { className: 'np-pill' }, 'Active') : null), el('p', null, profile.description || 'Reusable NBH Performance configuration'), el('small', null, 'Updated ' + formatTimestamp(profile.updated_at))),
						el('div', { className: 'np-agency-profile-actions' }, el('button', { className: 'np-button np-button-ghost np-button-blue', onClick: function () { apply(profile); } }, active ? 'Reapply' : 'Apply'), el('button', { className: 'np-icon-button', title: 'Delete profile', onClick: function () { remove(profile); } }, Icon('trash')))
					);
				})) : el('div', { className: 'np-empty-state' }, el('strong', null, 'No agency profiles yet'), el('p', null, 'Save the current configuration to reuse it on another client site.'))
		);
	}


	function ReliabilityPanel(props) {
		var data = props.data || {};
		var jobs = data.jobs || {};
		var validation = data.validation || {};
		var compatibility = data.compatibility || {};
		var transaction = data.latest_transaction || {};
		var queuePaused = !!jobs.paused;
		var queueHealthy = Number(jobs.failed || 0) === 0 && !queuePaused;
		var validationLabel = validation.checked_at ? (validation.passed ? 'Passed' : 'Needs review') : 'Not run';
		var compatibilityLabel = compatibility.checked_at ? (compatibility.passed ? 'Passed' : 'Needs review') : 'Not run';
		return el('section', { className: 'np-panel np-reliability-panel' },
			el(PanelHeader, { title: 'Production Reliability', description: 'Background jobs, compatibility checks, frontend validation and rollback status.' }),
			el('div', { className: 'np-reliability-grid' },
				ReliabilityCard('refresh', 'Background queue', queuePaused ? 'Paused' : (queueHealthy ? 'Healthy' : 'Failed jobs'), String(Number(jobs.pending || 0)) + ' pending · ' + String(Number(jobs.running || 0)) + ' running · ' + String(Number(jobs.failed || 0)) + ' failed', queueHealthy ? 'success' : 'warning'),
				ReliabilityCard('visibility', 'Frontend validation', validationLabel, validation.checked_at ? String(Number(validation.checked_urls || 0)) + ' URLs checked' : 'Run after significant setting changes', validation.passed ? 'success' : (validation.checked_at ? 'warning' : 'neutral')),
				ReliabilityCard('shield', 'Compatibility matrix', compatibilityLabel, compatibility.checked_at ? String(Number(compatibility.warnings || 0)) + ' warnings · ' + String(Number(compatibility.critical_failures || 0)) + ' critical' : 'Hosting and plugin safety checks', compatibility.passed ? 'success' : (compatibility.checked_at ? 'warning' : 'neutral')),
				ReliabilityCard('backup', 'Latest transaction', transaction.status ? humanizeStatus(transaction.status) : 'No changes yet', transaction.updated_count ? String(Number(transaction.updated_count)) + ' managed settings changed' : 'Rollback snapshots are created automatically', transaction.status === 'validated' ? 'success' : (transaction.status === 'validation_failed' ? 'warning' : 'neutral'))
			)
		);
	}

	function ReliabilityCard(icon, title, value, description, tone) {
		return el('article', { className: 'np-reliability-card is-' + (tone || 'neutral') },
			el('span', { className: 'np-reliability-icon' }, Icon(icon)),
			el('div', null, el('small', null, title), el('strong', null, value), el('p', null, description))
		);
	}

	function humanizeStatus(value) {
		return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
	}

	function formatTimestamp(value) {
		var date = new Date(Number(value || 0) * 1000);
		return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
	}

	function PageCacheView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var featureGroup = props.data && props.data.features ? props.data.features.caching : null;
		var settings = props.data && props.data.settings ? props.data.settings.caching : null;
		var fields = settings && settings.fields ? settings.fields : {};
		var fieldKeys = Object.keys(fields);
		var exclusions = fieldKeys.filter(function (key) { return isExclusion(fields[key]); });
		var regular = fieldKeys.filter(function (key) { return !isExclusion(fields[key]); });
		var tabState = useState('settings'), settingsTab = tabState[0], setSettingsTab = tabState[1];
		var parentBusy = props.busy || {};
		var wasBusyRef = useRef(false);

		function load(silent) {
			if (!silent) { setActionBusy('load'); }
			return apiFetch({ path: '/nbh-performance/v1/cache/status', method: 'GET' }).then(function (response) {
				setStatus(response);
				return response;
			}).catch(function (error) {
				if (!silent) { props.notify(error.message || 'Unable to load Page Cache status.', 'error'); }
				throw error;
			}).then(function (response) {
				if (!silent) { setActionBusy(''); }
				return response;
			}, function () {
				if (!silent) { setActionBusy(''); }
			});
		}

		useEffect(function () { load(false); }, []);

		useEffect(function () {
			var nowBusy = !!(parentBusy.purge_cache || parentBusy.repair_cache || parentBusy.create_cache);
			if (wasBusyRef.current && !nowBusy) {
				load(true);
				if (typeof props.refresh === 'function') { props.refresh(); }
			}
			wasBusyRef.current = nowBusy;
		}, [parentBusy.purge_cache, parentBusy.repair_cache, parentBusy.create_cache]);

		if (!status) {
			return el('div', { className: 'np-app-loading np-cache-loading' },
				el('span', { className: 'np-loader' }),
				el('strong', null, 'Checking page cache'),
				el('p', null, 'Verifying WP_CACHE, the drop-in, runtime files and saved pages…')
			);
		}

		var stats = status.stats || {};
		var lastWarm = status.last_warm || {};
		var lastWrite = status.last_write || {};
		var ops = status.ops_available || {};
		var anyBusy = !!actionBusy || !!parentBusy.purge_cache || !!parentBusy.repair_cache || !!parentBusy.create_cache;
		var heroTone = status.multisite ? 'warning' : (status.safe_mode ? 'warning' : (status.dropin_conflict ? 'warning' : (status.runtime_ready ? 'good' : (status.enabled ? 'warning' : 'muted'))));
		var heroLabel = status.multisite ? 'Multisite limited' : (status.safe_mode ? 'Safe Mode on' : (status.dropin_conflict ? 'Drop-in conflict' : (status.runtime_ready ? 'Serving cache' : (status.enabled ? 'Needs repair' : 'Cache off'))));
		var ttlLabel = status.ttl >= 86400 ? Math.round(status.ttl / 86400) + 'd' : (status.ttl >= 3600 ? Math.round(status.ttl / 3600) + 'h' : Math.round(status.ttl / 60) + 'm');

		function CacheCard(icon, title, description, onClick, cta, loading, workingLabel, disabled) {
			return el('article', { className: 'np-cache-action-card' + (loading ? ' is-working' : '') },
				el('div', { className: 'np-cache-action-top' },
					el('span', { className: 'np-cache-action-icon' }, Icon(icon)),
					el('div', null, el('strong', null, title), el('p', null, description))
				),
				el('button', {
					type: 'button',
					className: 'np-button np-button-primary np-cache-action-cta',
					disabled: !!disabled || anyBusy,
					onClick: onClick
				}, loading ? el('span', { className: 'np-spinner' }) : Icon(icon), loading ? (workingLabel || 'Working…') : cta)
			);
		}

		function purgeAll() {
			var go = function () { props.runAction('purge_cache'); };
			if (typeof props.confirmAction === 'function') {
				props.confirmAction({
					variant: 'warning',
					kicker: 'Clear saved pages',
					title: 'Purge all page cache?',
					message: 'Every saved HTML copy will be deleted. Pages rebuild on the next visit or after Repair & Warm.',
					note: 'Safe for production. Visitors may see a brief miss until pages are regenerated.',
					confirmText: 'Purge all cache',
					cancelText: 'Keep cache',
					icon: 'trash'
				}).then(function (confirmed) { if (confirmed) { go(); } });
				return;
			}
			go();
		}

		return el('div', { className: 'np-view np-cache-view' },
			EnvironmentNotice(props.data || {}),
			featureGroup ? el(FeaturePanel, { group: featureGroup, busy: parentBusy, toggle: function (option, checked) {
				var result = props.toggle(option, checked);
				if (result && typeof result.then === 'function') {
					result.then(function () { load(true); }, function () {});
				} else {
					setTimeout(function () { load(true); }, 350);
				}
			} }) : null,
			el('section', { className: 'np-panel np-cache-hero' },
				el('div', { className: 'np-cache-hero-copy' },
					el('span', { className: 'np-kicker' }, 'Page Cache'),
					el('h3', null, 'Serve saved HTML for anonymous visitors'),
					el('p', null, 'Enable page cache, keep the drop-in healthy, then purge or warm when content changes. Mobile variants and exclusions live under Advanced.')
				),
				el('div', { className: 'np-cache-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: anyBusy, onClick: function () { load(false); } }, Icon('refresh'), actionBusy === 'load' ? 'Refreshing…' : 'Refresh'),
					el('span', { className: 'np-quality-pill is-' + heroTone }, heroLabel)
				)
			),
			status.safe_mode ? el('div', { className: 'np-object-alert is-warning' }, Icon('shield'), el('div', null, el('strong', null, 'Safe Mode is active'), el('p', null, 'Page cache will not serve until Safe Mode is exited. You can still repair bootstrap files and review status.'))) : null,
			status.multisite ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'Single-site page cache'), el('p', null, 'This build keeps public page cache limited on Multisite. Object cache and other modules remain available.'))) : null,
			status.dropin_conflict ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'advanced-cache.php is not ours'), el('p', null, 'Another plugin owns the drop-in. Run Repair & Warm after resolving the conflict, or remove the foreign file.'))) : null,
			status.enabled && !status.wp_cache && !status.multisite ? el('div', { className: 'np-object-alert is-warning' }, Icon('wrench'), el('div', null, el('strong', null, 'WP_CACHE is not defined'), el('p', null, 'Page cache needs WP_CACHE in wp-config.php. Repair & Warm installs the drop-in and runtime configuration.'))) : null,
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Cache status', description: 'Runtime readiness, saved pages, drop-in ownership and last warm result.' }),
				el('div', { className: 'np-cache-status-grid' },
					el('article', { className: 'np-cache-status-card' }, el('small', null, 'Runtime'), el('strong', null, status.runtime_ready ? 'Ready' : (status.enabled ? 'Not ready' : 'Off')), el('p', null, status.runtime_ready ? 'Drop-in, WP_CACHE and runtime.json are healthy.' : (status.enabled ? 'Repair bootstrap or exit Safe Mode.' : 'Turn on Page Cache to start serving.'))),
					el('article', { className: 'np-cache-status-card' }, el('small', null, 'Cached pages'), el('strong', null, String(Number(stats.files || 0))), el('p', null, formatBytes(stats.bytes || 0) + (stats.updated_at ? (' · updated ' + formatTimestamp(stats.updated_at)) : ''))),
					el('article', { className: 'np-cache-status-card' }, el('small', null, 'Drop-in'), el('strong', null, status.dropin_owned ? 'NBH owned' : (status.dropin_exists ? 'Foreign' : 'Missing')), el('p', null, status.pages_writable ? ('TTL ' + ttlLabel + ' · pages writable') : 'Cache directory needs write access.')),
					el('article', { className: 'np-cache-status-card' }, el('small', null, 'Last warm'), el('strong', null, lastWarm.checked_at ? (lastWarm.success ? 'Success' : 'Failed') : 'Never'), el('p', null, lastWarm.checked_at ? (formatTimestamp(lastWarm.checked_at) + (lastWarm.message ? (' · ' + lastWarm.message) : '')) : (lastWrite.checked_at ? ('Last write ' + formatTimestamp(lastWrite.checked_at)) : 'Warm the homepage after enabling cache.')))
				)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Essential actions', description: 'Purge when content changes, repair when bootstrap drifts, warm the homepage, or open Preload for deeper warming.' }),
				el('div', { className: 'np-cache-essential-grid' },
					CacheCard('trash', 'Purge all cache', 'Delete every saved HTML copy. Pages rebuild on the next visit or after a warm.', purgeAll, 'Purge now', !!parentBusy.purge_cache, 'Purging…', ops.purge === false),
					CacheCard('wrench', 'Repair & warm', 'Fix WP_CACHE, the drop-in, runtime files and orphans, then warm the homepage.', function () { props.runAction('repair_cache'); }, 'Repair now', !!parentBusy.repair_cache, 'Repairing…', ops.repair === false),
					CacheCard('layers', 'Warm homepage', 'Create or refresh the homepage cache entry without a full purge.', function () { props.runAction('create_cache'); }, 'Warm now', !!parentBusy.create_cache, 'Warming…', ops.warm === false),
					CacheCard('rocket', 'Open Preload', 'Queue sitemap URLs and navigation warmup from the Preload workspace.', function () {
						if (typeof props.setActive === 'function') { props.setActive('preload'); }
						else { window.location.hash = 'preload'; }
					}, 'Open Preload', false, '', false)
				)
			),
			settings && props.showAdvancedSettings ? el('div', { className: 'np-unified-settings-area' },
				el('div', { className: 'np-tabs', role: 'tablist' },
					Tab('settings', 'Settings', settingsTab, setSettingsTab, regular.length),
					Tab('exclusions', 'Exclusions', settingsTab, setSettingsTab, exclusions.length)
				),
				settingsTab === 'exclusions'
					? el(FieldPanel, { sectionId: 'caching', title: 'Exclusions', description: 'Keep selected URLs, cookies or query strings out of the page cache.', keys: exclusions, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts })
					: el(FieldPanel, { sectionId: 'caching', title: 'Page Cache settings', description: 'TTL, mobile variants, cookies and cache behavior. Values are checked before saving.', keys: regular, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts }),
				el(StickyAdvancedSave, { dirty: props.dirty, busy: !!parentBusy['save-caching'], setAdvancedSettings: props.setAdvancedSettings, onDiscard: function () { props.reset('caching', false); }, onDefaults: function () { props.reset('caching', true); }, onSave: function () { props.save('caching'); }, saveLabel: 'Save changes' })
			) : el(AdvancedSettingsGate, { title: 'Advanced page cache settings', description: 'TTL, mobile cache, cookies, query strings and URL exclusions.', count: fieldKeys.length, onOpen: function () { props.setAdvancedSettings(true); } })
		);
	}

	function FeaturePanel(props) {
		return el('section', { className: 'np-panel np-feature-panel' }, el(PanelHeader, { title: 'Features', description: 'Enable only what you need. Dependencies are handled automatically.' }), el('div', { className: 'np-feature-list' }, Object.keys(props.group.features).map(function (option) {
			var feature = props.group.features[option];
			return el('div', {
				className: 'np-feature-row',
				key: option,
			}, el('span', { className: 'np-feature-icon' }, Icon(featureIconFor(option))), el('div', { className: 'np-feature-copy' }, el('div', null, el('strong', null, feature.label)), el('p', null, FEATURE_COPY[option] || 'Control this optimization capability.'), feature.requires && feature.requires.length ? el('small', { className: 'np-feature-requirement' }, 'Required settings are handled automatically.') : null), Switch(feature.enabled, !!props.busy[option], function (checked) { props.toggle(option, checked); }, feature.label));
		})));
	}

	function FieldPanel(props) {
		var searchState = useState(''), query = searchState[0], setQuery = searchState[1];
		var normalized = String(query || '').toLowerCase().trim();
		var visibleKeys = props.keys.filter(function (key) {
			if (!normalized) { return true; }
			var field = props.fields[key] || {};
			return [key, field.label, field.description].join(' ').toLowerCase().indexOf(normalized) !== -1;
		});
		return el('section', { className: 'np-panel' },
			el('div', { className: 'np-panel-toolbar' }, el(PanelHeader, { title: props.title, description: props.description }), props.keys.length > 4 ? el('label', { className: 'np-setting-search' }, Icon('search'), el('input', { type: 'search', value: query, placeholder: 'Search settings…', onChange: function (event) { setQuery(event.target.value); }, 'aria-label': 'Search settings' })) : null),
			props.sectionId === 'optimization' && props.title === 'Exclusion manager' ? el(OneClickExclusionManager, { fields: props.fields, drafts: props.drafts, setDrafts: props.setDrafts }) : null,
			visibleKeys.length ? el('div', { className: 'np-field-grid' }, visibleKeys.map(function (key) { return FieldCard(key, props.fields[key], props.drafts[key], function (value) { var next = Object.assign({}, props.drafts); next[key] = value; props.setDrafts(next); }); })) : EmptyState('search', 'No matching settings', 'Try a different keyword or clear the search field.')
		);
	}

	function OneClickExclusionManager(props) {
		return el('div', { className: 'np-oneclick-wrap' },
			el(OneClickExclusionSet, { config: ONE_CLICK_EXCLUSIONS.css, drafts: props.drafts, setDrafts: props.setDrafts }),
			el(OneClickExclusionSet, { config: ONE_CLICK_EXCLUSIONS.delay, drafts: props.drafts, setDrafts: props.setDrafts })
		);
	}

	function OneClickExclusionSet(props) {
		var config = props.config;
		var selected = exclusionLines(props.drafts[config.target]);
		function setValue(value, checked) {
			var nextLines = exclusionLines(props.drafts[config.target]);
			if (checked && nextLines.indexOf(value) === -1) { nextLines.push(value); }
			if (!checked) { nextLines = nextLines.filter(function (line) { return line !== value; }); }
			var next = Object.assign({}, props.drafts);
			next[config.target] = nextLines.join('\n');
			props.setDrafts(next);
		}
		function setGroup(group, checked) {
			var nextLines = exclusionLines(props.drafts[config.target]);
			var groupValues = group.items.map(function (item) { return item.value; });
			if (checked) { groupValues.forEach(function (value) { if (nextLines.indexOf(value) === -1) { nextLines.push(value); } }); }
			else { nextLines = nextLines.filter(function (line) { return groupValues.indexOf(line) === -1; }); }
			var next = Object.assign({}, props.drafts);
			next[config.target] = nextLines.join('\n');
			props.setDrafts(next);
		}
		return el('section', { className: 'np-oneclick-set' },
			el('div', { className: 'np-oneclick-head' }, el('div', null, el('h4', null, config.title), el('p', null, config.description)), el('span', { className: 'np-oneclick-count' }, selected.length + ' selected')),
			el('div', { className: 'np-oneclick-groups' }, config.groups.map(function (group, groupIndex) {
				var selectedCount = group.items.filter(function (item) { return selected.indexOf(item.value) !== -1; }).length;
				var allSelected = selectedCount === group.items.length;
				return el('details', { className: 'np-oneclick-group', key: group.id, open: groupIndex === 0 },
					el('summary', null, el('span', { className: 'np-oneclick-group-icon' }, Icon(group.icon)), el('strong', null, group.label), el('span', { className: 'np-oneclick-group-meta' }, selectedCount + '/' + group.items.length), Icon('arrow', 'np-oneclick-chevron')),
					el('div', { className: 'np-oneclick-options' },
						el('label', { className: 'np-oneclick-option is-select-all' }, el('input', { type: 'checkbox', checked: allSelected, onChange: function (event) { setGroup(group, event.target.checked); } }), el('span', null, 'Select all')),
						group.items.map(function (item) { return el('label', { className: 'np-oneclick-option', key: item.value }, el('input', { type: 'checkbox', checked: selected.indexOf(item.value) !== -1, onChange: function (event) { setValue(item.value, event.target.checked); } }), el('span', null, item.label)); })
					)
				);
			}))
		);
	}

	function exclusionLines(value) {
		return String(value || '').split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean).filter(function (line, index, lines) { return lines.indexOf(line) === index; });
	}

	function FieldCard(key, field, value, update) {
		var guidance = field.type === 'textarea' ? fieldGuidance(key, field) : null;
		return el('div', { className: 'np-field-card' + (field.type === 'textarea' ? ' is-wide' : ''), key: key },
			el('div', { className: 'np-field-header' }, el('label', { htmlFor: key }, field.label), field.suffix ? el('span', null, field.suffix) : null),
			el('p', null, field.description),
			FieldInput(key, field, value, update, guidance),
			guidance ? el('div', { className: 'np-field-guidance' },
				el('div', { className: 'np-field-hint' }, Icon('info'), el('span', null, guidance.hint)),
				guidance.examples && guidance.examples.length ? el('div', { className: 'np-field-examples' }, el('strong', null, 'Examples'), el('div', null, guidance.examples.map(function (example) { return el('code', { key: example }, example); }))) : null
			) : null
		);
	}

	function FieldInput(key, field, value, update, guidance) {
		if (field.type === 'textarea') { return el('textarea', { id: key, rows: 8, value: value || '', placeholder: guidance ? guidance.placeholder : 'Add one value on each new line…', onChange: function (event) { update(event.target.value); } }); }
		if (field.type === 'select') { return el('select', { id: key, value: value === undefined ? field.default : value, onChange: function (event) { update(event.target.value); } }, Object.keys(field.choices || {}).map(function (choice) { return el('option', { key: choice, value: choice }, field.choices[choice]); })); }
		if (field.type === 'boolean') { return Switch(!!Number(value), false, update, field.label); }
		return el('div', { className: 'np-input-group' }, el('input', { id: key, type: field.type === 'number' ? 'number' : 'text', min: field.min, max: field.max, value: value === undefined ? field.default : value, onChange: function (event) { update(event.target.value); } }), field.suffix ? el('span', null, field.suffix) : null);
	}


	function CdnView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var choicesState = useState([]), choices = choicesState[0], setChoices = choicesState[1];
		var secretState = useState(''), oneTimeSecret = secretState[0], setOneTimeSecret = secretState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var formState = useState({
			provider: 'cloudflare',
			api_token: '',
			api_key: '',
			zone_id: '',
			pull_zone_id: '',
			webhook_url: '',
			cdn_base_url: '',
			edge_cache_ttl: 3600
		}), form = formState[0], setForm = formState[1];

		function update(key, value) {
			setForm(function (current) {
				var next = Object.assign({}, current);
				next[key] = value;
				if (key === 'provider') {
					next.api_token = '';
					next.api_key = '';
					next.zone_id = '';
					next.pull_zone_id = '';
					setChoices([]);
					setOneTimeSecret('');
				}
				return next;
			});
		}

		function applyStatus(response) {
			setStatus(response);
			var creds = response.credentials || {};
			setForm(function (current) {
				return Object.assign({}, current, {
					provider: response.provider || current.provider || 'custom',
					cdn_base_url: response.cdn_base_url || '',
					edge_cache_ttl: response.edge_cache_ttl || 3600,
					zone_id: creds.cloudflare_zone_id || current.zone_id || '',
					pull_zone_id: creds.bunny_pull_zone_id ? String(creds.bunny_pull_zone_id) : (current.pull_zone_id || ''),
					webhook_url: creds.webhook_url || current.webhook_url || '',
					api_token: '',
					api_key: ''
				});
			});
		}

		function load() {
			setActionBusy('load');
			apiFetch({ path: '/nbh-performance/v1/edge/status' }).then(function (response) {
				applyStatus(response);
				var detection = response.detection || {};
				if (detection.suggested && !response.configured) {
					setForm(function (current) { return Object.assign({}, current, { provider: detection.suggested }); });
				}
			}).catch(function (error) {
				props.notify(error.message || 'Unable to load CDN status.', 'error');
			}).then(function () { setActionBusy(''); });
		}

		useEffect(function () { load(); }, []);

		function run(endpoint, payload, successMessage) {
			setActionBusy(endpoint);
			return apiFetch({ path: '/nbh-performance/v1/edge/' + endpoint, method: endpoint === 'status' || endpoint === 'detect' ? 'GET' : 'POST', data: payload || {} }).then(function (response) {
				if (response.status) { applyStatus(response.status); }
				else if (endpoint === 'status' || endpoint === 'detect') { applyStatus(response); }
				if (response.choices && response.choices.length) { setChoices(response.choices); }
				else if (endpoint === 'connect' && (!response.choices || !response.choices.length)) { setChoices([]); }
				if (response.secret) { setOneTimeSecret(response.secret); }
				props.notify(response.message || successMessage || 'CDN action completed.', 'success');
				return response;
			}).catch(function (error) {
				props.notify(error.message || 'CDN action failed.', 'error');
				return null;
			}).then(function (response) {
				setActionBusy('');
				return response;
			});
		}

		function connectPayload() {
			var payload = {
				provider: form.provider,
				cdn_base_url: form.cdn_base_url,
				edge_cache_ttl: Number(form.edge_cache_ttl) || 3600
			};
			if (form.provider === 'cloudflare') {
				payload.api_token = form.api_token;
				if (form.zone_id) { payload.zone_id = form.zone_id; }
			} else if (form.provider === 'bunnycdn') {
				payload.api_key = form.api_key;
				if (form.pull_zone_id) { payload.pull_zone_id = Number(form.pull_zone_id); }
			} else if (form.provider === 'keycdn') {
				payload.api_key = form.api_key;
				if (form.zone_id) { payload.zone_id = Number(form.zone_id); }
			} else {
				payload.webhook_url = form.webhook_url;
			}
			return payload;
		}

		function selectChoice(choice) {
			if (form.provider === 'cloudflare') {
				update('zone_id', choice.id);
			} else if (form.provider === 'bunnycdn') {
				update('pull_zone_id', String(choice.id));
				if (choice.cdn_url && !form.cdn_base_url) { update('cdn_base_url', choice.cdn_url); }
			} else if (form.provider === 'keycdn') {
				update('zone_id', String(choice.id));
				if (choice.cdn_url && !form.cdn_base_url) { update('cdn_base_url', choice.cdn_url); }
			}
		}

		function toggleFeature(option, checked) {
			props.toggle(option, checked);
		}

		if (!status) {
			return el('div', { className: 'np-app-loading np-object-cache-loading' }, el('span', { className: 'np-loader' }), el('strong', null, 'Checking CDN & edge'), el('p', null, 'Detecting Cloudflare, Bunny, KeyCDN and custom webhook settings…'));
		}

		var provider = form.provider || status.provider || 'custom';
		var creds = status.credentials || {};
		var detection = status.detection || {};
		var connection = status.last_connection || {};
		var features = (props.data && props.data.features && props.data.features.cdn && props.data.features.cdn.features) ? props.data.features.cdn.features : {};
		var providerMeta = {
			cloudflare: { title: 'Cloudflare', badge: 'Auto zone', copy: 'Paste an API token with Zone.Cache Purge and Zone.Zone Read. NBH Performance finds the matching zone automatically.' },
			bunnycdn: { title: 'Bunny CDN', badge: 'Auto pull zone', copy: 'Paste your Bunny AccessKey. Pull zones are discovered and the best match is selected for you.' },
			keycdn: { title: 'KeyCDN', badge: 'Auto zone', copy: 'Paste your KeyCDN API key. Zones are listed and matched to this site hostname when possible.' },
			custom: { title: 'Custom CDN', badge: 'Webhook', copy: 'Point NBH Performance at your HTTPS purge webhook. A signed secret is generated and verified before saving.' }
		};

		return el('div', { className: 'np-view np-cdn-view np-object-cache-view' },
			el('section', { className: 'np-panel np-feature-panel' },
				el(PanelHeader, { title: 'CDN features', description: 'Turn these on when your provider is connected. Dependencies are handled automatically.' }),
				el('div', { className: 'np-feature-list' },
					[
						['nbh_performance_cdn_rewrite_enabled', 'CDN URL rewriting', 'Serve static assets from your CDN hostname.'],
						['nbh_performance_edge_auto_purge_enabled', 'Automatic edge purge', 'Purge the edge when local page cache is cleared.'],
						['nbh_performance_edge_cache_headers_enabled', 'Edge cache headers', 'Send shared-cache Cache-Control headers for eligible HTML.'],
						['nbh_performance_resource_hints_enabled', 'CDN resource hints', 'Add preconnect / dns-prefetch for the CDN origin.']
					].map(function (row) {
						var option = row[0], label = row[1], copy = row[2];
						var feature = features[option] || { enabled: false, locked: false };
						var enabled = feature.enabled;
						if (option === 'nbh_performance_cdn_rewrite_enabled') { enabled = !!status.rewrite_enabled; }
						if (option === 'nbh_performance_edge_auto_purge_enabled') { enabled = !!status.auto_purge_enabled; }
						if (option === 'nbh_performance_edge_cache_headers_enabled') { enabled = !!status.edge_headers_enabled; }
						if (option === 'nbh_performance_resource_hints_enabled') { enabled = !!status.resource_hints_enabled; }
						return el('div', {
							className: 'np-feature-row',
							key: option,
						},
							el('span', { className: 'np-feature-icon' }, Icon(featureIconFor(option))),
							el('div', { className: 'np-feature-copy' },
								el('div', null, el('strong', null, label)),
								el('p', null, copy)
							),
							Switch(!!enabled, !!!!props.busy[option], function (checked) {
								toggleFeature(option, checked);
								setStatus(function (current) {
									var next = Object.assign({}, current || {});
									if (option === 'nbh_performance_cdn_rewrite_enabled') { next.rewrite_enabled = checked; }
									if (option === 'nbh_performance_edge_auto_purge_enabled') { next.auto_purge_enabled = checked; }
									if (option === 'nbh_performance_edge_cache_headers_enabled') { next.edge_headers_enabled = checked; }
									if (option === 'nbh_performance_resource_hints_enabled') { next.resource_hints_enabled = checked; }
									return next;
								});
							}, label)
						);
					})
				)
			),
			detection.message ? el('section', { className: 'np-object-alert ' + (detection.suggested ? 'is-info' : 'is-warning') }, Icon(detection.suggested ? 'globe' : 'shield'), el('div', null, el('strong', null, detection.suggested ? (providerMeta[detection.suggested] ? providerMeta[detection.suggested].title : 'CDN') + ' detected' : 'Choose a CDN provider'), el('p', null, detection.message))) : null,
			oneTimeSecret ? el('section', { className: 'np-object-alert is-info' }, Icon('lock'), el('div', null, el('strong', null, 'Copy your webhook secret now'), el('p', null, oneTimeSecret), el('p', null, 'This secret is shown once. Store it with your custom CDN / edge purge service.'))) : null,
			el('section', { className: 'np-object-summary-grid' },
				ObjectCacheMetric('Provider', providerMeta[provider] ? providerMeta[provider].title : capitalize(provider), status.configured ? 'Connected and ready to purge.' : 'Connect credentials to enable purge.', 'globe'),
				ObjectCacheMetric('Connection', status.configured ? 'Verified' : 'Not connected', connection.message || 'Save & Connect tests the provider before enabling purge.', 'pulse'),
				ObjectCacheMetric('CDN hostname', status.cdn_base_url ? String(status.cdn_base_url).replace(/^https?:\/\//, '') : 'Origin only', status.rewrite_enabled ? 'URL rewriting is on.' : 'Rewriting is optional.', 'layers'),
				ObjectCacheMetric('Last purge', status.last_purge && status.last_purge.at ? formatTimestamp(status.last_purge.at) : 'Never', status.last_purge && status.last_purge.success === false ? (status.last_purge.error || 'Last purge failed') : 'Edge invalidation history.', 'trash')
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Choose a CDN provider', description: 'Selecting Cloudflare, Bunny or KeyCDN unlocks auto-connect. Custom CDN uses a signed webhook.' }),
				el('div', { className: 'np-object-provider-grid np-cdn-provider-grid' },
					['cloudflare', 'bunnycdn', 'keycdn', 'custom'].map(function (id) {
						var meta = providerMeta[id];
						var available = !!(status.providers && status.providers[id] && status.providers[id].available !== false);
						return el('button', {
							key: id,
							className: 'np-object-provider' + (provider === id ? ' is-selected' : '') + (!available ? ' is-unavailable' : ''),
							disabled: !available,
							onClick: function () { update('provider', id); }
						}, el('span', { className: 'np-object-provider-icon' }, Icon('globe')), el('div', null, el('span', { className: 'np-object-provider-heading' }, el('strong', null, meta.title), el('em', null, meta.badge)), el('p', null, meta.copy)), provider === id ? Icon('check', 'np-object-provider-check') : null);
					})
				)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Connect & auto-configure', description: 'Credentials are stored securely. Auto-connect discovers zones when possible, then verifies before enabling purge.' }),
				el('div', { className: 'np-object-form-grid' },
					provider === 'cloudflare' ? ObjectField('Cloudflare API token', 'password', form.api_token, function (value) { update('api_token', value); }, creds.cloudflare_token_set ? 'Stored — leave blank to keep it' : 'Zone Cache Purge + Zone Read') : null,
					provider === 'cloudflare' ? ObjectField('Zone ID (optional)', 'text', form.zone_id, function (value) { update('zone_id', value); }, 'Auto-detected when empty') : null,
					provider === 'bunnycdn' ? ObjectField('Bunny AccessKey', 'password', form.api_key, function (value) { update('api_key', value); }, creds.bunny_key_set ? 'Stored — leave blank to keep it' : 'Account API AccessKey') : null,
					provider === 'bunnycdn' ? ObjectField('Pull zone ID (optional)', 'number', form.pull_zone_id, function (value) { update('pull_zone_id', value); }, 'Auto-selected when empty') : null,
					provider === 'keycdn' ? ObjectField('KeyCDN API key', 'password', form.api_key, function (value) { update('api_key', value); }, creds.keycdn_key_set ? 'Stored — leave blank to keep it' : 'KeyCDN API key') : null,
					provider === 'keycdn' ? ObjectField('Zone ID (optional)', 'number', form.zone_id, function (value) { update('zone_id', value); }, 'Auto-selected when empty') : null,
					provider === 'custom' ? ObjectField('Purge webhook URL', 'url', form.webhook_url, function (value) { update('webhook_url', value); }, 'https://edge.example.com/purge') : null,
					ObjectField('CDN hostname', 'url', form.cdn_base_url, function (value) { update('cdn_base_url', value); }, 'https://cdn.example.com'),
					ObjectField('Edge cache TTL (seconds)', 'number', form.edge_cache_ttl, function (value) { update('edge_cache_ttl', Number(value)); }, '3600')
				),
				choices.length ? el('div', { className: 'np-cdn-choices' },
					el('strong', null, 'Choose a discovered zone'),
					el('div', { className: 'np-cdn-choice-list' }, choices.map(function (choice) {
						return el('button', {
							key: String(choice.id),
							type: 'button',
							className: 'np-cdn-choice',
							onClick: function () { selectChoice(choice); run('connect', Object.assign(connectPayload(), form.provider === 'bunnycdn' ? { pull_zone_id: choice.id } : { zone_id: choice.id }), 'CDN connected.'); }
						}, el('strong', null, choice.name || ('Zone ' + choice.id)), el('span', null, choice.cdn_url || choice.id));
					}))
				) : null
			),
			el('section', { className: 'np-object-actionbar' },
				el('div', null, el('strong', null, status.configured ? (providerMeta[provider] ? providerMeta[provider].title : 'CDN') + ' is connected' : 'Verify before you purge'), el('span', null, 'Auto-connect tests the API, saves credentials, and only then marks the provider ready.')),
				el('div', { className: 'np-object-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { run('test', connectPayload(), 'Connection verified.'); } }, actionBusy === 'test' ? el('span', { className: 'np-spinner' }) : Icon('pulse'), actionBusy === 'test' ? 'Testing…' : 'Test connection'),
					provider === 'custom' ? el('button', { className: 'np-button np-button-ghost np-button-blue', disabled: !!actionBusy, onClick: function () { run('rotate-secret', {}, 'Secret rotated.'); } }, actionBusy === 'rotate-secret' ? el('span', { className: 'np-spinner' }) : Icon('lock'), actionBusy === 'rotate-secret' ? 'Rotating…' : 'Rotate secret') : null,
					el('button', { className: 'np-button np-button-primary', disabled: !!actionBusy, onClick: function () { run('connect', connectPayload(), 'CDN connected.'); } }, actionBusy === 'connect' ? el('span', { className: 'np-spinner' }) : Icon('globe'), actionBusy === 'connect' ? 'Connecting…' : (status.configured ? 'Save & reconnect' : 'Save & connect')),
					el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy || !status.configured, onClick: function () { run('purge', { provider: provider, purge_all: true, urls: [] }, 'Edge cache purged.'); } }, actionBusy === 'purge' ? el('span', { className: 'np-spinner' }) : Icon('trash'), actionBusy === 'purge' ? 'Purging…' : 'Purge all'),
					el('button', { className: 'np-button np-button-ghost np-button-blue', disabled: !!actionBusy || !status.configured, onClick: function () { run('purge', { provider: provider, purge_all: false, urls: [window.location.origin + '/'] }, 'Homepage purged at the edge.'); } }, Icon('rocket'), 'Purge homepage')
				)
			)
		);
	}

	function ObjectCacheView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var formState = useState({
			provider: 'redis', host: '127.0.0.1', port: 6379, database: 0, username: '', password: '',
			timeout: 1, read_timeout: 1, persistent: true, tls: false, prefix: '', redis_mode: 'standalone',
			nodes_text: '', sentinel_service: '', compression: true, compression_threshold: 1024,
			igbinary: true, max_object_size: 1048576, failure_threshold: 3, retry_interval: 30
		}), form = formState[0], setForm = formState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var dirtyState = useState(false), formDirty = dirtyState[0], setFormDirty = dirtyState[1];

		function update(key, value) {
			setForm(function (current) {
				var next = Object.assign({}, current);
				next[key] = value;
				if (key === 'provider') {
					next.port = value === 'redis' ? 6379 : 11211;
					next.database = 0;
					next.tls = value === 'redis' ? next.tls : false;
				}
				return next;
			});
			setFormDirty(true);
		}

		function applyFormFromStatus(response) {
			var config = (response && response.config) || {};
			var nodes = Array.isArray(config.nodes) ? config.nodes.map(function (node) {
				return String(node.host || '') + ':' + String(node.port || 6379);
			}).join('\n') : '';
			setForm(function (current) {
				return Object.assign({}, current, config, {
					provider: config.provider || response.recommended_provider || 'redis',
					password: '',
					redis_mode: config.redis_mode || 'standalone',
					nodes_text: nodes,
					compression: config.compression !== false,
					igbinary: config.igbinary !== false,
					compression_threshold: Number(config.compression_threshold || 1024),
					max_object_size: Number(config.max_object_size || 1048576),
					failure_threshold: Number(config.failure_threshold || 3),
					retry_interval: Number(config.retry_interval || 30)
				});
			});
			setFormDirty(false);
		}

		function load(silent) {
			if (!silent) { setActionBusy('load'); }
			return apiFetch({ path: '/nbh-performance/v1/object-cache/status', method: 'GET' }).then(function (response) {
				setStatus(response);
				applyFormFromStatus(response);
				return response;
			}).catch(function (error) {
				if (!silent) { props.notify(error.message || 'Unable to load object-cache status.', 'error'); }
				throw error;
			}).then(function (response) {
				if (!silent) { setActionBusy(''); }
				return response;
			}, function () {
				if (!silent) { setActionBusy(''); }
			});
		}

		useEffect(function () { load(false); }, []);

		function payloadFromForm() {
			return Object.assign({}, form, {
				nodes: String(form.nodes_text || '').split(/\r?\n|,/).map(function (value) { return value.trim(); }).filter(Boolean)
			});
		}

		function run(endpoint, successMessage, confirmConfig, useForm) {
			var start = function () {
				setActionBusy(endpoint);
				var data = useForm === false ? {} : payloadFromForm();
				apiFetch({ path: '/nbh-performance/v1/object-cache/' + endpoint, method: 'POST', data: data }).then(function (response) {
					props.notify(response.message || successMessage, 'success');
					if (response.status) {
						setStatus(response.status);
						applyFormFromStatus(response.status);
					} else if (response.connection) {
						setStatus(function (current) {
							return Object.assign({}, current || {}, {
								connection: response.connection,
								config: response.config || (current && current.config) || {},
								last_test: { success: true, tested_at: Math.floor(Date.now() / 1000), latency_ms: response.connection.latency_ms || 0 }
							});
						});
					} else {
						load(true);
					}
					if (typeof props.refresh === 'function') { props.refresh(); }
				}).catch(function (error) {
					props.notify(error.message || 'Object-cache action failed.', 'error');
				}).then(function () { setActionBusy(''); });
			};
			if (confirmConfig && typeof props.confirmAction === 'function') {
				props.confirmAction(confirmConfig).then(function (confirmed) { if (confirmed) { start(); } });
				return;
			}
			start();
		}

		if (!status) {
			return el('div', { className: 'np-app-loading np-object-cache-loading' },
				el('span', { className: 'np-loader' }),
				el('strong', null, 'Checking object cache'),
				el('p', null, 'Detecting Redis, Memcached and the active WordPress drop-in…')
			);
		}

		var provider = form.provider || 'redis';
		var extensionReady = !!(status.extensions && status.extensions[provider]);
		var connection = status.connection || {};
		var lastTest = status.last_test || {};
		var ops = status.ops_available || {};
		var heroTone = status.dropin_conflict ? 'warning' : (status.active ? 'good' : (status.configured ? 'warning' : 'muted'));
		var heroLabel = status.dropin_conflict ? 'Drop-in conflict' : (status.active ? 'Object cache active' : (status.configured ? 'Installed · waiting' : 'Not configured'));
		var showAdvanced = !!props.showAdvancedSettings;

		return el('div', { className: 'np-view np-object-cache-view' },
			el('section', { className: 'np-panel np-object-hero' },
				el('div', { className: 'np-object-hero-copy' },
					el('span', { className: 'np-kicker' }, 'Object Cache'),
					el('h3', null, 'Persistent Redis or Memcached for WordPress'),
					el('p', null, 'Test the backend, install a safe drop-in, flush this site’s namespace, and reconnect without overwriting a hosting-owned object-cache.php.')
				),
				el('div', { className: 'np-object-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { load(false); } }, Icon('refresh'), actionBusy === 'load' ? 'Refreshing…' : 'Refresh'),
					el('span', { className: 'np-quality-pill is-' + heroTone }, heroLabel)
				)
			),
			status.dropin_conflict ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'Existing object-cache.php detected'), el('p', null, 'Another plugin or your hosting provider owns the current object-cache drop-in. NBH Performance will not overwrite it.'))) : null,
			status.next_request_notice ? el('div', { className: 'np-object-alert' }, Icon('refresh'), el('div', null, el('strong', null, 'Object cache installed'), el('p', null, 'Reload WordPress once so normal requests pick up the new drop-in.'))) : null,
			!extensionReady ? el('div', { className: 'np-object-alert is-warning' }, Icon('wrench'), el('div', null, el('strong', null, capitalize(provider) + ' PHP extension missing'), el('p', null, 'Install or enable the required PHP extension before testing or enabling this provider.'))) : null,
			connection && connection.success === false ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'Connection unhealthy'), el('p', null, connection.message || 'The stored configuration could not reach the cache backend.'))) : null,
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Choose an engine', description: 'Redis is the recommended default. Memcached remains available when the PHP extension is present.' }),
				el('div', { className: 'np-object-provider-grid' },
					ObjectProviderCard('redis', 'Redis', 'Recommended', 'Strong WordPress compatibility, database selection, authentication, TLS and reliable namespace invalidation.', !!(status.extensions && status.extensions.redis), provider, update),
					ObjectProviderCard('memcached', 'Memcached', 'Alternative', 'Fast distributed memory caching for hosting stacks that already provide Memcached.', !!(status.extensions && status.extensions.memcached), provider, update)
				)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Cache status', description: 'Drop-in ownership, provider health and last verified latency.' }),
				el('div', { className: 'np-object-status-grid' },
					el('article', { className: 'np-object-status-card' }, el('small', null, 'Runtime'), el('strong', null, status.active ? 'Active' : (status.configured ? 'Installed' : 'Off')), el('p', null, status.wp_using_ext_object_cache ? 'WordPress is using an external object cache.' : 'WordPress is using the default request cache.')),
					el('article', { className: 'np-object-status-card' }, el('small', null, 'Provider'), el('strong', null, status.provider ? capitalize(status.provider) : 'Not selected'), el('p', null, connection.version ? ('Server ' + connection.version) : (status.recommended_provider ? ('Recommended: ' + capitalize(status.recommended_provider)) : 'Choose Redis or Memcached'))),
					el('article', { className: 'np-object-status-card' }, el('small', null, 'Latency'), el('strong', null, connection.success ? (String(connection.latency_ms) + ' ms') : '—'), el('p', null, lastTest.tested_at ? ('Last test ' + formatTimestamp(lastTest.tested_at)) : 'Run a connection test before enabling.')),
					el('article', { className: 'np-object-status-card' }, el('small', null, 'Drop-in'), el('strong', null, status.dropin_owned ? 'NBH owned' : (status.dropin_conflict ? 'Conflict' : 'Missing')), el('p', null, status.last_flush ? ('Last flush ' + formatTimestamp(status.last_flush)) : 'Namespace flush when configured.'))
				)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Operations', description: 'Verify first. Enable only writes NBH-owned files. Flush affects this site namespace only.' }),
				el('div', { className: 'np-object-action-grid' },
					el('article', { className: 'np-object-action-card' },
						el('div', { className: 'np-object-action-top' }, el('span', { className: 'np-object-action-icon' }, Icon('pulse')), el('div', null, el('strong', null, 'Test connection'), el('p', null, 'Probe the selected Redis or Memcached backend without changing the site.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-object-action-cta', disabled: !!actionBusy || !extensionReady, onClick: function () { run('test', 'Connection verified.'); } }, actionBusy === 'test' ? el('span', { className: 'np-spinner' }) : Icon('pulse'), actionBusy === 'test' ? 'Testing…' : 'Test connection')
					),
					el('article', { className: 'np-object-action-card' },
						el('div', { className: 'np-object-action-top' }, el('span', { className: 'np-object-action-icon' }, Icon('database')), el('div', null, el('strong', null, status.configured ? 'Save & reconnect' : 'Enable object cache'), el('p', null, status.configured ? 'Rewrite the config and keep the NBH drop-in after a successful test.' : 'Install object-cache.php and the private config after a successful test.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-object-action-cta', disabled: !!actionBusy || !extensionReady || status.dropin_conflict || ops.enable === false, onClick: function () {
							run('enable', status.configured ? 'Object-cache connection updated.' : 'Object cache enabled.', {
								variant: 'info',
								kicker: 'Before enabling object cache',
								title: status.configured ? 'Save and reconnect object cache?' : 'Enable NBH Performance object cache?',
								message: 'NBH Performance will test the connection, then write a private config file and install wp-content/object-cache.php when ownership is safe.',
								note: 'A failed test never changes the site. Hosting-owned drop-ins are never overwritten.',
								confirmText: status.configured ? 'Yes, save & reconnect' : 'Yes, enable',
								cancelText: 'Cancel',
								icon: 'database'
							});
						} }, actionBusy === 'enable' ? el('span', { className: 'np-spinner' }) : Icon('database'), actionBusy === 'enable' ? 'Working…' : (status.configured ? 'Save & reconnect' : 'Enable'))
					),
					el('article', { className: 'np-object-action-card' },
						el('div', { className: 'np-object-action-top' }, el('span', { className: 'np-object-action-icon' }, Icon('trash')), el('div', null, el('strong', null, 'Flush namespace'), el('p', null, 'Invalidate NBH Performance keys for this site. Does not run a global FLUSHALL.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-object-action-cta', disabled: !!actionBusy || !status.configured || ops.flush === false, onClick: function () {
							run('flush', 'Object cache flushed.', {
								variant: 'warning',
								kicker: 'Before flushing',
								title: 'Flush this site’s object-cache namespace?',
								message: 'NBH Performance will invalidate its own namespace so WordPress rebuilds cached objects on demand.',
								note: 'Other sites or applications sharing the same Redis/Memcached server are not wiped with FLUSHALL.',
								confirmText: 'Yes, flush namespace',
								cancelText: 'Keep cache',
								icon: 'trash'
							}, false);
						} }, actionBusy === 'flush' ? el('span', { className: 'np-spinner' }) : Icon('trash'), actionBusy === 'flush' ? 'Flushing…' : 'Flush')
					),
					el('article', { className: 'np-object-action-card' },
						el('div', { className: 'np-object-action-top' }, el('span', { className: 'np-object-action-icon' }, Icon('undo')), el('div', null, el('strong', null, 'Disable object cache'), el('p', null, 'Remove the NBH-owned drop-in and private config. Hosting-owned files stay untouched.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-object-action-cta', disabled: !!actionBusy || (!status.configured && !status.dropin_owned) || ops.disable === false, onClick: function () {
							run('disable', 'Object cache disabled.', {
								variant: 'danger',
								kicker: 'Before disabling',
								title: 'Disable NBH Performance object cache?',
								message: 'NBH Performance will remove its object-cache.php drop-in and private configuration file.',
								note: 'WordPress returns to the default request-level object cache on the next request. Your Redis/Memcached server itself is not deleted.',
								confirmText: 'Yes, disable',
								cancelText: 'Keep enabled',
								icon: 'undo'
							}, false);
						} }, actionBusy === 'disable' ? el('span', { className: 'np-spinner' }) : Icon('undo'), actionBusy === 'disable' ? 'Disabling…' : 'Disable')
					)
				)
			),
			showAdvanced ? el('div', { className: 'np-unified-settings-area' },
				el('section', { className: 'np-panel' },
					el(PanelHeader, { title: 'Connection settings', description: 'NBH Performance tests the server before writing object-cache.php. A failed test never changes the site.' }),
					el('div', { className: 'np-object-form-grid' },
						ObjectField('Host or socket', 'text', form.host, function (value) { update('host', value); }, '127.0.0.1 or /path/to/redis.sock'),
						ObjectField('Port', 'number', form.port, function (value) { update('port', Number(value)); }, provider === 'redis' ? '6379' : '11211'),
						provider === 'redis' ? ObjectField('Redis database', 'number', form.database, function (value) { update('database', Number(value)); }, '0') : null,
						provider === 'redis' ? ObjectSelect('Redis topology', form.redis_mode || 'standalone', function (value) { update('redis_mode', value); }, { standalone: 'Standalone', sentinel: 'Sentinel high availability', cluster: 'Redis Cluster' }) : null,
						provider === 'redis' && form.redis_mode !== 'standalone' ? ObjectTextArea('Topology nodes', form.nodes_text, function (value) { update('nodes_text', value); }, 'One host:port per line') : null,
						provider === 'redis' && form.redis_mode === 'sentinel' ? ObjectField('Sentinel master service', 'text', form.sentinel_service, function (value) { update('sentinel_service', value); }, 'mymaster') : null,
						ObjectField('Key prefix', 'text', form.prefix, function (value) { update('prefix', value); }, 'Automatically generated when empty'),
						ObjectField('Username', 'text', form.username, function (value) { update('username', value); }, 'Optional'),
						ObjectField('Password', 'password', form.password, function (value) { update('password', value); }, status.config && status.config.password_set ? 'Stored — leave blank to keep it' : 'Optional'),
						ObjectField('Connect timeout', 'number', form.timeout, function (value) { update('timeout', Number(value)); }, 'Seconds', '0.05'),
						ObjectField('Read timeout', 'number', form.read_timeout, function (value) { update('read_timeout', Number(value)); }, 'Seconds', '0.05'),
						ObjectField('Compression threshold', 'number', form.compression_threshold, function (value) { update('compression_threshold', Number(value)); }, 'Bytes'),
						ObjectField('Max object size', 'number', form.max_object_size, function (value) { update('max_object_size', Number(value)); }, 'Bytes'),
						ObjectField('Failure threshold', 'number', form.failure_threshold, function (value) { update('failure_threshold', Number(value)); }, 'Failures'),
						ObjectField('Retry interval', 'number', form.retry_interval, function (value) { update('retry_interval', Number(value)); }, 'Seconds')
					),
					el('div', { className: 'np-object-options' },
						el('div', { className: 'np-object-option' }, Switch(!!form.persistent, false, function (checked) { update('persistent', checked); }, 'Persistent PHP connection'), el('div', null, el('strong', null, 'Persistent PHP connection'), el('small', null, 'Reuse the backend connection when the PHP runtime supports it.'))),
						provider === 'redis' ? el('div', { className: 'np-object-option' }, Switch(!!form.tls, false, function (checked) { update('tls', checked); }, 'TLS connection'), el('div', null, el('strong', null, 'TLS connection'), el('small', null, 'Use tls:// for a Redis server configured with encryption.'))) : null,
						el('div', { className: 'np-object-option' }, Switch(!!form.compression, false, function (checked) { update('compression', checked); }, 'Compression'), el('div', null, el('strong', null, 'Compress large values'), el('small', null, 'Use gzip when values exceed the compression threshold.'))),
						el('div', { className: 'np-object-option' }, Switch(!!form.igbinary, false, function (checked) { update('igbinary', checked); }, 'igbinary'), el('div', null, el('strong', null, 'Prefer igbinary'), el('small', null, 'Use igbinary serialization when the PHP extension is available.')))
					)
				),
				el(StickyAdvancedSave, {
					dirty: formDirty,
					busy: !!actionBusy,
					setAdvancedSettings: props.setAdvancedSettings,
					onDiscard: function () { applyFormFromStatus(status); props.notify('Connection draft discarded.', 'info'); },
					onSave: function () {
						run('enable', status.configured ? 'Object-cache connection updated.' : 'Object cache enabled.', {
							variant: 'info',
							kicker: 'Before saving connection settings',
							title: 'Test and save these object-cache settings?',
							message: 'NBH Performance will verify the connection, then update the private config and drop-in when ownership is safe.',
							note: 'Leave the password blank to keep the currently stored secret.',
							confirmText: 'Yes, test & save',
							cancelText: 'Cancel',
							icon: 'database'
						});
					},
					saveLabel: status.configured ? 'Test & save' : 'Test & enable'
				})
			) : el(AdvancedSettingsGate, {
				title: 'Connection & tuning settings',
				description: 'Host, auth, topology, timeouts, compression and failure thresholds stay tucked away until you need them.',
				count: 12,
				onOpen: function () { props.setAdvancedSettings(true); }
			})
		);
	}

	function ObjectCacheMetric(label, value, copy, icon) { return el('article', { className: 'np-object-metric' }, el('span', { className: 'np-object-metric-icon' }, Icon(icon)), el('div', null, el('small', null, label), el('strong', null, value), el('p', null, copy))); }
	function ObjectProviderCard(id, title, badge, copy, available, selected, update) { return el('button', { className: 'np-object-provider' + (selected === id ? ' is-selected' : '') + (!available ? ' is-unavailable' : ''), disabled: !available, onClick: function () { update('provider', id); } }, el('span', { className: 'np-object-provider-icon' }, Icon('database')), el('div', null, el('span', { className: 'np-object-provider-heading' }, el('strong', null, title), el('em', null, available ? badge : 'Extension missing')), el('p', null, copy)), selected === id ? Icon('check', 'np-object-provider-check') : null); }
	function ObjectField(label, type, value, update, placeholder, step) { return el('label', { className: 'np-object-field' }, el('span', null, label), el('input', { type: type, value: value === undefined || value === null ? '' : value, placeholder: placeholder || '', step: step, onChange: function (event) { update(event.target.value); } })); }
	function ObjectSelect(label, value, update, choices) { return el('label', { className: 'np-object-field' }, el('span', null, label), el('select', { value: value || '', onChange: function (event) { update(event.target.value); } }, Object.keys(choices || {}).map(function (key) { return el('option', { key: key, value: key }, choices[key]); }))); }
	function ObjectTextArea(label, value, update, placeholder) { return el('label', { className: 'np-object-field is-wide' }, el('span', null, label), el('textarea', { rows: 4, value: value || '', placeholder: placeholder || '', onChange: function (event) { update(event.target.value); } })); }
	function capitalize(value) { value = String(value || ''); return value ? value.charAt(0).toUpperCase() + value.slice(1) : ''; }


	function PerformanceInsightsView(props) {
		var summaryState = useState(null), summary = summaryState[0], setSummary = summaryState[1];
		var historyState = useState(null), history = historyState[0], setHistory = historyState[1];
		var loadingState = useState(false), loading = loadingState[0], setLoading = loadingState[1];
		var runningState = useState(false), running = runningState[0], setRunning = runningState[1];

		var actionState = useState(''), action = actionState[0], setAction = actionState[1];
		useEffect(function () { loadInsights(); }, []);
		var pendingJobs = summary && summary.lab && summary.lab.jobs ? summary.lab.jobs.filter(function (job) { return job.status === 'pending' || job.status === 'running'; }).length : 0;
		useEffect(function () {
			if (!pendingJobs) { return; }
			var timer = window.setInterval(loadInsights, 10000);
			return function () { window.clearInterval(timer); };
		}, [pendingJobs]);

		function runLocalCheck() {
			setAction('local');
			apiFetch({ path: '/nbh-performance/v1/performance-intelligence/local-check', method: 'POST' }).then(function () {
				props.notify('Homepage check complete. Measurements are shown below.', 'success');
				return loadInsights();
			}).catch(function (error) { props.notify(error.message || 'Homepage check failed.', 'error'); }).then(function () { setAction(''); });
		}

		function toggleMonitoring() {
			setAction('monitoring');
			apiFetch({ path: '/nbh-performance/v1/performance-intelligence/monitoring', method: 'POST', data: { enabled: !collection.enabled } }).then(function (response) {
				props.notify(response.enabled ? 'Visitor monitoring enabled. Anonymous visits can now be sampled when Safe Mode is off.' : 'Visitor monitoring disabled.', 'success');
				return loadInsights();
			}).catch(function (error) { props.notify(error.message || 'Monitoring could not be updated.', 'error'); }).then(function () { setAction(''); });
		}


		function loadInsights() {
			setLoading(true);
			return Promise.all([
				apiFetch({ path: '/nbh-performance/v1/performance-intelligence/summary?days=28' }),
				apiFetch({ path: '/nbh-performance/v1/performance-intelligence/history?limit=40' })
			]).then(function (responses) {
				setSummary(responses[0]);
				setHistory(responses[1]);
			}).catch(function (error) {
				props.notify(error.message || 'Performance Insights could not be loaded.', 'error');
			}).then(function () { setLoading(false); });
		}

		function runTests() {
			setRunning(true);
			apiFetch({ path: '/nbh-performance/v1/performance-intelligence/run', method: 'POST', data: {} }).then(function (response) {
				props.notify((response.queued || []).length + ' lab tests queued.', 'success');
				return loadInsights();
			}).catch(function (error) {
				props.notify(error.message || 'Lab tests could not be queued.', 'error');
			}).then(function () { setRunning(false); });
		}

		function setBaseline(id) {
			apiFetch({ path: '/nbh-performance/v1/performance-intelligence/baseline', method: 'POST', data: { result_id: id } }).then(function () {
				props.notify('Performance baseline updated.', 'success');
				loadInsights();
			}).catch(function (error) { props.notify(error.message || 'Baseline could not be updated.', 'error'); });
		}

		function acknowledge(id) {
			apiFetch({ path: '/nbh-performance/v1/performance-intelligence/alerts/acknowledge', method: 'POST', data: { alert_id: id } }).then(function () {
				props.notify('Regression alert acknowledged.', 'success');
				loadInsights();
			}).catch(function (error) { props.notify(error.message || 'Alert could not be acknowledged.', 'error'); });
		}

		if (loading && !summary) {
			return el('div', { className: 'np-app-loading np-insights-loading' }, el('span', { className: 'np-loader' }), el('strong', null, 'Loading Performance Insights'), el('p', null, 'Combining laboratory and real-user performance evidence…'));
		}

		var lab = summary && summary.lab ? summary.lab : { averages: {}, latest: [] };
		var averages = lab.averages || {};
		var availability = lab.availability || { available: false, reason: 'Checking laboratory availability…' };
		var collection = summary && summary.collection ? summary.collection : {};
		var localCheck = summary && summary.local_check ? summary.local_check : null;
		var jobs = lab.jobs || [];
		var failedLabs = history && history.lab ? history.lab.filter(function (row) { return row.status === 'failed'; }).slice(0, 3) : [];		var errorJobs = jobs.filter(function (job) { return job.last_error && (job.status === 'failed' || job.status === 'pending'); });
		var labError = errorJobs.length ? errorJobs[0].last_error : (failedLabs.length ? failedLabs[0].error_message : '');
		var labErrorSummary = /quota|rate.?limit/i.test(labError) ? 'Google’s testing quota was reached. Try again later; local checks and visitor monitoring remain available.' : 'A previous lab test could not finish. The provider response is below.';


		var rum = summary && summary.rum ? summary.rum : { overall: {}, samples: 0 };
		var overall = rum.overall || {};
		var alerts = summary && summary.alerts ? summary.alerts : [];
		var impacts = history && history.impacts ? history.impacts : [];
		var latest = lab.latest || [];
		var visibleImpacts = impacts.slice(0, 6);
		var olderImpacts = impacts.slice(6, 10);

		function ImpactRow(impact) {
			var delta = impact.delta || {};
			var valueCount = (impact.updated || []).length;
			var hasHealth = delta.health !== null && delta.health !== undefined;
			var hasLab = delta.lab_performance_score !== null && delta.lab_performance_score !== undefined;
			var tone = !hasHealth && !hasLab ? 'is-pending' : ((Number(delta.health || 0) < 0 || Number(delta.lab_performance_score || 0) < 0) ? 'is-negative' : 'is-positive');
			var result = 'Waiting for lab';
			if (hasHealth || hasLab) {
				var resultParts = [];
				if (hasHealth) { resultParts.push('Health ' + (Number(delta.health) > 0 ? '+' : '') + delta.health); }
				if (hasLab) { resultParts.push('Score ' + (Number(delta.lab_performance_score) > 0 ? '+' : '') + delta.lab_performance_score); }
				if (!hasLab) { resultParts.push('Lab pending'); }
				result = resultParts.join(' · ');
			}
			return el('div', { className: 'np-impact-row', key: impact.id },
				el('div', null,
					el('strong', null, impact.kind === 'features' ? 'Feature change' : 'Settings change'),
					el('small', null, valueCount + ' value' + (valueCount === 1 ? '' : 's') + ' changed')
				),
				el('span', { className: 'np-impact-result ' + tone }, result)
			);
		}

		return el('div', { className: 'np-view np-insights-view' },
			el('section', { className: 'np-insights-toolbar' },
				el('div', null, el('strong', null, 'Performance Intelligence'), el('p', null, 'Homepage checks and real visitor measurements.')),
				el('div', { className: 'np-insights-toolbar-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: loading, onClick: loadInsights }, Icon('refresh'), loading ? 'Refreshing…' : 'Refresh'),
					el('button', { className: 'np-button np-button-primary', disabled: !!action, onClick: runLocalCheck }, Icon('chart'), action === 'local' ? 'Checking…' : 'Run Local Check')
				)
			),
			el('section', { className: 'np-insights-metric-grid' },
				InsightMetric('Unified health', summary ? summary.health : '—', summary && summary.health !== null ? 'Based on available measurements' : 'No measurements yet', 'pulse', insightTone(summary ? summary.health : null, 90, 75)),
				InsightMetric('Lab performance', formatInsightMetric(averages.performance_score, 'score'), (averages.pages || 0) + ' monitored device/page results', 'chart', insightTone(averages.performance_score, 90, 75)),
				InsightMetric('RUM p75 LCP', formatInsightMetric(overall.p75_lcp, 'ms'), (rum.samples || 0) + ' real-user samples', 'pulse', lowerMetricTone(overall.p75_lcp, 2500, 4000)),
				InsightMetric('Open regressions', alerts.length, alerts.length ? 'Review detected changes' : (latest.length ? 'No unacknowledged regressions' : 'Waiting for comparable lab results'), 'warning', alerts.length ? 'warning' : (latest.length ? 'success' : 'neutral'))
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Local Homepage Check', description: 'A lightweight homepage check using your own hosting.' }),
				localCheck ? el(wp.element.Fragment, null,
					el('p', { className: 'np-insights-meta' }, 'Last checked: ' + new Date(localCheck.checked_at * 1000).toLocaleString() + ' · ' + compactUrl(localCheck.url)),
					el('div', { className: 'np-insight-cwv-grid' },
						InsightValue('Server round trip', formatInsightMetric(localCheck.response_ms, 'ms')),
						InsightValue('HTML size', (localCheck.html_bytes / 1024).toFixed(1) + ' KB'),
						InsightValue('Script files', localCheck.external_scripts),
						InsightValue('Stylesheets', localCheck.stylesheets),
						InsightValue('Page cache header', localCheck.cache_status || 'Not reported'),
						InsightValue('Visitor beacon', localCheck.rum_beacon_present ? 'Found in HTML' : 'Not found')
					),
					el('details', { className: 'np-insights-details' }, el('summary', null, 'About these measurements'), el('p', null, 'One anonymous homepage request, without an external API. This measures the server round trip and HTML assets, not browser rendering, Core Web Vitals or a Lighthouse score. Scripts may load additional files.')),
					localCheck.html_bytes > 512000 ? el('p', null, 'Large HTML response: review page-builder output and reduce unnecessary markup.') : null,
					localCheck.external_scripts > 20 ? el('p', null, 'Many script files are present. Review unnecessary plugins and page-specific scripts before enabling deferral.') : null
				) : EmptyState('chart', 'Ready for a local check', 'Click Run Local Check to see actual homepage response time, HTML size, asset counts, cache header and visitor beacon status.')
			),
			el('section', { className: 'np-panel np-insights-latest' },
				el('div', { className: 'np-insights-section-head' },
					el(PanelHeader, { title: 'Laboratory Results', description: 'Optional mobile and desktop tests with Google PageSpeed.' }),
					el('button', { className: 'np-button np-button-ghost', disabled: running || !availability.available || pendingJobs > 0, title: availability.reason, onClick: runTests }, Icon('chart'), running ? 'Queuing…' : 'Run Lab Test')
				),
				pendingJobs ? el('div', { className: 'np-insights-status', role: 'status' }, Icon('refresh'), pendingJobs + ' tests in progress. Results update automatically.') : null,
				!availability.available ? el('div', { className: 'np-insights-status', role: 'status' }, Icon('info'), el('div', null, el('strong', null, 'Public website required'), el('span', null, 'Lighthouse cannot test local sites. Use Local Check or visitor monitoring here.'))) : null,
				labError ? el('details', { className: 'np-insights-details' }, el('summary', null, 'Previous test details'), el('p', null, labErrorSummary), el('pre', null, labError)) : null,
				latest.length ? el('div', { className: 'np-insights-results-grid' }, latest.map(function (row) {
					return el('article', { className: 'np-insight-result-card', key: row.id },
						el('div', { className: 'np-insight-result-head' }, el('span', { className: 'np-insight-device' }, Icon(row.strategy === 'mobile' ? 'mobile' : 'chart'), row.strategy), row.is_baseline ? el('span', { className: 'np-insight-baseline' }, 'Baseline') : null),
						el('strong', { className: 'np-insight-url', title: row.canonical_url }, compactUrl(row.canonical_url)),
						el('div', { className: 'np-insight-result-metrics' },
							InsightValue('Score', formatInsightMetric(row.performance_score, 'score')),
							InsightValue('LCP', formatInsightMetric(row.lcp, 'ms')),
							InsightValue('TBT', formatInsightMetric(row.tbt, 'ms')),
							InsightValue('CLS', formatInsightMetric(row.cls, 'cls'))
						),
						el('div', { className: 'np-insight-result-actions' }, el('small', null, row.captured_at || ''), row.is_baseline ? null : el('button', { className: 'np-button np-button-ghost', onClick: function () { setBaseline(row.id); } }, 'Use as baseline'))
					);
				})) : (availability.available ? EmptyState('chart', 'No laboratory results yet', 'Run a lab test to measure a public page.') : null)
			),
			el('section', { className: 'np-insights-two-column' },
				el('div', { className: 'np-panel' },
					el(PanelHeader, { title: 'Real User Core Web Vitals', description: 'Privacy-safe p75 field metrics from eligible visitors during the last 28 days.' }),
					el('div', { className: 'np-insights-monitoring' },
						el('span', { className: 'np-insights-badge' + (collection.enabled && !collection.safe_mode ? ' is-active' : ''), role: 'status' }, !collection.enabled ? 'Monitoring off' : (collection.safe_mode ? 'Paused · Safe Mode' : 'Active · ' + collection.sample_rate + '% sampling')),
						el('button', { className: 'np-button np-button-ghost', disabled: !!action, onClick: toggleMonitoring }, action === 'monitoring' ? 'Saving…' : (collection.enabled ? 'Disable Monitoring' : 'Enable Monitoring'))
					),
					el('details', { className: 'np-insights-details' },
						el('summary', null, 'How to collect measurements'),
						el('p', null, 'Enable monitoring and turn Safe Mode off when ready. Logged-in users are excluded. Visit a public page in a private window, wait for it to load, interact with it, then leave or switch tabs and refresh Insights. Only sampled visits are sent; INP needs an interaction and browser support.'),
						el('p', null, 'Measurements stay on your site with no IP address, cookie or user ID. No Google API is used. Small samples are preliminary and are not Chrome field data or Search Console results.')
					),
					el('div', { className: 'np-insight-cwv-grid' },
						InsightValue('LCP', formatInsightMetric(overall.p75_lcp, 'ms'), lowerMetricTone(overall.p75_lcp, 2500, 4000)),
						InsightValue('INP', formatInsightMetric(overall.p75_inp, 'ms'), lowerMetricTone(overall.p75_inp, 200, 500)),
						InsightValue('CLS', formatInsightMetric(overall.p75_cls, 'cls'), lowerMetricTone(overall.p75_cls, 0.1, 0.25)),
						InsightValue('TTFB', formatInsightMetric(overall.p75_ttfb, 'ms'), lowerMetricTone(overall.p75_ttfb, 800, 1800))
					)
				),
				el('div', { className: 'np-panel' },
					el(PanelHeader, { title: 'Regression Alerts', description: 'Alerts compare each result with its explicit baseline or previous successful run.' }),
					alerts.length ? el('div', { className: 'np-insight-alert-list' }, alerts.map(function (alert) {
						return el('div', { className: 'np-insight-alert is-' + alert.severity, key: alert.id },
							el('span', { className: 'np-insight-alert-icon' }, Icon('warning')),
							el('div', null, el('strong', null, compactUrl(alert.url) + ' · ' + alert.strategy), el('p', null, (alert.issues || []).map(function (issue) { return String(issue.metric || '').toUpperCase() + ' ' + issue.change + (issue.unit === 'percent' ? '%' : ''); }).join(' · '))),
							el('button', { className: 'np-button np-button-ghost', onClick: function () { acknowledge(alert.id); } }, 'Acknowledge')
						);
					})) : EmptyState('shield', 'No open regressions', latest.length ? 'No regression has been detected in available lab comparisons.' : 'Run successful lab tests and collect a later result before regressions can be compared.')
				)
			),
			el('section', { className: 'np-panel np-impact-panel' },
				el(PanelHeader, { title: 'Before & after impact', description: 'Recent managed changes compared with laboratory and real-user evidence.' }),
				impacts.length ? el(wp.element.Fragment, null,
					el('div', { className: 'np-impact-list' }, visibleImpacts.map(ImpactRow)),
					olderImpacts.length ? el('details', { className: 'np-impact-older' },
						el('summary', null, el('span', null, 'Show ' + olderImpacts.length + ' older change' + (olderImpacts.length === 1 ? '' : 's')), Icon('arrow')),
						el('div', { className: 'np-impact-list' }, olderImpacts.map(ImpactRow))
					) : null
				) : EmptyState('pulse', 'No measured changes yet', 'Save a managed NBH Performance feature or setting to begin before/after impact measurement.')
			)
		);
	}

	function InsightMetric(title, value, copy, icon, tone) {
		return el('article', { className: 'np-insights-metric is-' + (tone || 'neutral') }, el('span', { className: 'np-insights-metric-icon' }, Icon(icon)), el('div', null, el('small', null, title), el('strong', null, value === null || value === undefined ? '—' : value), el('p', null, copy)));
	}

	function InsightValue(label, value, tone) {
		return el('div', { className: 'np-insight-value is-' + (tone || 'neutral') }, el('small', null, label), el('strong', null, value));
	}

	function formatInsightMetric(value, type) {
		if (value === null || value === undefined || value === '') { return '—'; }
		if (type === 'score') { return Math.round(Number(value)); }
		if (type === 'cls') { return Number(value).toFixed(3); }
		if (type === 'ms') { return Number(value) >= 1000 ? (Number(value) / 1000).toFixed(2) + ' s' : Math.round(Number(value)) + ' ms'; }
		return String(value);
	}

	function compactUrl(url) {
		try { var parsed = new URL(url); return parsed.pathname === '/' ? parsed.hostname : parsed.hostname + parsed.pathname; } catch (error) { return String(url || ''); }
	}

	function insightTone(value, good, warning) {
		if (value === null || value === undefined) { return 'neutral'; }
		return Number(value) >= good ? 'success' : (Number(value) >= warning ? 'warning' : 'danger');
	}

	function lowerMetricTone(value, good, poor) {
		if (value === null || value === undefined) { return 'neutral'; }
		return Number(value) <= good ? 'success' : (Number(value) <= poor ? 'warning' : 'danger');
	}

	function ScriptManagerView(props) {
		var statusState = useState(null), status = statusState[0], setStatus = statusState[1];
		var rulesState = useState([]), rules = rulesState[0], setRules = rulesState[1];
		var testingState = useState(true), testing = testingState[0], setTesting = testingState[1];
		var assetsState = useState([]), assets = assetsState[0], setAssets = assetsState[1];
		var historyState = useState([]), historyRows = historyState[0], setHistoryRows = historyState[1];
		var warningsState = useState([]), warnings = warningsState[0], setWarnings = warningsState[1];
		var protectedState = useState([]), protectedHandles = protectedState[0], setProtectedHandles = protectedState[1];
		var busyState = useState(''), actionBusy = busyState[0], setActionBusy = busyState[1];
		var dirtyRulesState = useState(false), dirtyRules = dirtyRulesState[0], setDirtyRules = dirtyRulesState[1];
		var filterState = useState(''), ruleFilter = filterState[0], setRuleFilter = filterState[1];
		var actionFilterState = useState('all'), actionFilter = actionFilterState[0], setActionFilter = actionFilterState[1];
		var inventoryFilterState = useState(''), inventoryFilter = inventoryFilterState[0], setInventoryFilter = inventoryFilterState[1];
		var inventoryTypeState = useState('all'), inventoryType = inventoryTypeState[0], setInventoryType = inventoryTypeState[1];
		var workspaceState = useState('rules'), workspace = workspaceState[0], setWorkspace = workspaceState[1];
		var analysisState = useState(null), analysis = analysisState[0], setAnalysis = analysisState[1];
		var editingIdState = useState(''), editingId = editingIdState[0], setEditingId = editingIdState[1];
		var draftState = useState({
			handle: '', type: 'script', action: 'unload', scope: 'global', value: '', device: 'all', auth: 'all',
			roles: '', language: '', query: '', cookie: '', note: '', cascade: false, priority: 50
		}), draft = draftState[0], setDraft = draftState[1];
		var featureGroup = props.data && props.data.features ? props.data.features.script_manager : null;
		var settings = props.data && props.data.settings ? props.data.settings.script_manager : null;
		var fields = settings && settings.fields ? settings.fields : {};
		var fieldKeys = Object.keys(fields);
		var scopes = {
			global: 'Everywhere', url_exact: 'Exact URL', url_contains: 'URL contains', url_regex: 'URL regex', url_except: 'Everywhere except',
			post_type: 'Post type', taxonomy: 'Taxonomy archive', front_page: 'Front page', home_page: 'Posts page', archive: 'Archives',
			author_archive: 'Author archive', search: 'Search', '404': '404 pages', singular_id: 'Post/page ID', template: 'Page template'
		};
		var actions = {
			unload: 'Do not load', delay: 'After interaction', defer: 'Defer', async: 'Async', move_footer: 'Move to footer',
			preload: 'Preload', preconnect: 'Preconnect', 'dns-prefetch': 'DNS prefetch', remove_version: 'Remove version'
		};

		function emptyDraft() {
			return {
				handle: '', type: 'script', action: 'unload', scope: 'global', value: '', device: 'all', auth: 'all',
				roles: '', language: '', query: '', cookie: '', note: '', cascade: false, priority: 50
			};
		}

		function applyPayload(response) {
			if (!response) { return; }
			setStatus(response);
			setRules(Array.isArray(response.rules) ? response.rules : []);
			setTesting(!!response.testing_mode);
			setAssets(Array.isArray(response.assets) ? response.assets : []);
			setHistoryRows(Array.isArray(response.history) ? response.history : []);
			setWarnings(Array.isArray(response.warnings) ? response.warnings : []);
			setProtectedHandles(Array.isArray(response.protected) ? response.protected : []);
			setDirtyRules(false);
		}

		function load(silent) {
			if (!silent) { setActionBusy('load'); }
			return apiFetch({ path: '/nbh-performance/v1/script-manager/status' }).then(function (response) {
				applyPayload(response);
				return response;
			}).catch(function (error) {
				if (!silent) { props.notify(error.message || 'Unable to load Script Manager.', 'error'); }
				throw error;
			}).then(function (response) {
				if (!silent) { setActionBusy(''); }
				return response;
			}, function () {
				if (!silent) { setActionBusy(''); }
			});
		}

		useEffect(function () {
			load(false);
			try {
				var params = new URLSearchParams(window.location.search || '');
				var inspect = params.get('inspect_url');
				if (inspect) {
					setDraft(function (current) { return Object.assign({}, current, { scope: 'url_exact', value: inspect }); });
					setWorkspace('rules');
				}
			} catch (error) {}
		}, []);

		function updateDraft(key, value) {
			setDraft(function (current) {
				var next = Object.assign({}, current);
				next[key] = value;
				return next;
			});
			setAnalysis(null);
		}

		function newRuleId() {
			return 'rule-' + Date.now() + '-' + Math.random().toString(16).slice(2);
		}

		function parsePair(raw) {
			var parts = String(raw || '').split('=');
			return { key: (parts.shift() || '').trim(), value: parts.join('=').trim() };
		}

		function ruleFromDraft(idValue) {
			var query = parsePair(draft.query);
			var cookie = parsePair(draft.cookie);
			return {
				id: idValue || newRuleId(),
				handle: String(draft.handle || '').trim(),
				type: draft.type || 'script',
				action: draft.action || 'unload',
				scope: draft.scope || 'global',
				value: String(draft.value || '').trim(),
				device: draft.device || 'all',
				auth: draft.auth || 'all',
				roles: String(draft.roles || '').split(',').map(function (role) { return role.trim(); }).filter(Boolean),
				cascade: !!draft.cascade,
				enabled: true,
				priority: Math.max(1, Math.min(100, Number(draft.priority || 50))),
				language: String(draft.language || '').trim(),
				query_key: query.key,
				query_value: query.value,
				cookie_key: cookie.key,
				cookie_value: cookie.value,
				note: String(draft.note || '').trim()
			};
		}

		function draftFromRule(rule) {
			return {
				handle: rule.handle || '',
				type: rule.type || 'script',
				action: rule.action || 'unload',
				scope: rule.scope || 'global',
				value: rule.value || '',
				device: rule.device || 'all',
				auth: rule.auth || 'all',
				roles: Array.isArray(rule.roles) ? rule.roles.join(', ') : '',
				language: rule.language || '',
				query: (rule.query_key || '') + (rule.query_key || rule.query_value ? '=' : '') + (rule.query_value || ''),
				cookie: (rule.cookie_key || '') + (rule.cookie_key || rule.cookie_value ? '=' : '') + (rule.cookie_value || ''),
				note: rule.note || '',
				cascade: !!rule.cascade,
				priority: Number(rule.priority || 50)
			};
		}

		function saveRules(nextRules, nextTesting, successMessage) {
			setActionBusy('save');
			return apiFetch({
				path: '/nbh-performance/v1/script-manager/rules',
				method: 'POST',
				data: { rules: nextRules, testing_mode: nextTesting }
			}).then(function (response) {
				applyPayload(Object.assign({}, status || {}, response));
				props.notify(successMessage || 'Rules saved.', 'success');
				load(true);
			}).catch(function (error) {
				props.notify(error.message || 'Rules could not be saved.', 'error');
			}).then(function () { setActionBusy(''); });
		}

		function setTestingMode(checked) {
			var apply = function () {
				setTesting(checked);
				setActionBusy('testing');
				apiFetch({
					path: '/nbh-performance/v1/script-manager/rules',
					method: 'POST',
					data: { rules: rules, testing_mode: checked }
				}).then(function (response) {
					applyPayload(Object.assign({}, status || {}, response));
					props.notify(checked ? 'Testing Mode enabled.' : 'Testing Mode turned off. Matching visitors can receive rules.', checked ? 'success' : 'warning');
					load(true);
				}).catch(function (error) {
					setTesting(!checked);
					props.notify(error.message || 'Testing Mode could not be updated.', 'error');
				}).then(function () { setActionBusy(''); });
			};
			if (!checked) {
				props.confirmAction({
					variant: 'warning',
					kicker: 'Before going live',
					title: 'Turn Testing Mode off?',
					message: 'Matching visitors can receive unload, delay, defer and related Script Manager actions.',
					note: 'Keep Testing Mode on until critical templates are verified as an administrator.',
					confirmText: 'Yes, go live',
					cancelText: 'Keep Testing Mode',
					icon: 'warning'
				}).then(function (confirmed) { if (confirmed) { apply(); } });
				return;
			}
			apply();
		}

		function addOrUpdateRule() {
			var handle = String(draft.handle || '').trim();
			if (!handle) {
				props.notify('Choose a script or stylesheet handle first.', 'error');
				return;
			}
			var proposed = ruleFromDraft(editingId || undefined);
			if (editingId) { proposed.enabled = true; }
			setActionBusy('analyze');
			apiFetch({ path: '/nbh-performance/v1/script-manager/analyze', method: 'POST', data: { rule: proposed } }).then(function (result) {
				if (result.risk === 'critical') {
					throw new Error('This asset is protected and cannot be unloaded safely.');
				}
				setAnalysis(result);
				var next;
				if (editingId) {
					next = rules.map(function (rule) {
						if (rule.id !== editingId) { return rule; }
						return Object.assign({}, rule, proposed, { id: editingId, enabled: rule.enabled !== false });
					});
					setEditingId('');
					props.notify('Rule updated (' + (result.risk || 'unknown') + ' risk). Save when ready.', result.risk === 'high' ? 'warning' : 'success');
				} else {
					next = rules.concat([proposed]);
					props.notify('Rule added (' + (result.risk || 'unknown') + ' risk). Save when ready.', result.risk === 'high' ? 'warning' : 'success');
				}
				setRules(next);
				setDirtyRules(true);
				setDraft(emptyDraft());
				setWorkspace('rules');
			}).catch(function (error) {
				props.notify(error.message || 'Rule was not added.', 'error');
			}).then(function () { setActionBusy(''); });
		}

		function editRule(rule) {
			setEditingId(rule.id || '');
			setDraft(draftFromRule(rule));
			setAnalysis(null);
			setWorkspace('rules');
			props.notify('Editing ' + rule.handle + '. Update the form and save the draft.', 'info');
		}

		function duplicateRule(rule) {
			var copy = Object.assign({}, rule, { id: newRuleId(), note: (rule.note ? rule.note + ' ' : '') + '(copy)', enabled: false });
			setRules(function (current) { return current.concat([copy]); });
			setDirtyRules(true);
			props.notify('Duplicated ' + rule.handle + ' as a disabled draft rule.', 'success');
		}

		function exportRules() {
			setActionBusy('export');
			apiFetch({ path: '/nbh-performance/v1/script-manager/export' }).then(function (payload) {
				var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
				var link = document.createElement('a');
				link.href = URL.createObjectURL(blob);
				link.download = 'nbh-performance-script-manager-rules.json';
				link.click();
				URL.revokeObjectURL(link.href);
				props.notify('Rules exported.', 'success');
			}).catch(function (error) {
				props.notify(error.message || 'Export failed.', 'error');
			}).then(function () { setActionBusy(''); });
		}

		function importRules() {
			var input = document.createElement('input');
			input.type = 'file';
			input.accept = 'application/json,.json';
			input.onchange = function () {
				var file = input.files && input.files[0];
				if (!file) { return; }
				var reader = new FileReader();
				reader.onload = function () {
					props.confirmAction({
						variant: 'warning',
						kicker: 'Before importing rules',
						title: 'Replace Script Manager rules with this file?',
						message: 'NBH Performance will validate and replace the current rule set with the imported JSON.',
						note: 'A history snapshot is kept so you can restore the previous rules.',
						confirmText: 'Yes, import rules',
						cancelText: 'Cancel',
						icon: 'upload'
					}).then(function (confirmed) {
						if (!confirmed) { return; }
						setActionBusy('import');
						apiFetch({ path: '/nbh-performance/v1/script-manager/import', method: 'POST', data: { payload: reader.result } }).then(function (response) {
							applyPayload(Object.assign({}, status || {}, response));
							props.notify('Rules imported.', 'success');
							load(true);
						}).catch(function (error) {
							props.notify(error.message || 'Import failed.', 'error');
						}).then(function () { setActionBusy(''); });
					});
				};
				reader.readAsText(file);
			};
			input.click();
		}

		function restoreSnapshot(snapshotId) {
			props.confirmAction({
				variant: 'warning',
				kicker: 'Before restoring history',
				title: 'Restore this Script Manager snapshot?',
				message: 'NBH Performance will replace the current rules with the selected history snapshot.',
				note: 'Your WordPress content is untouched. A new history entry is created for the restore.',
				confirmText: 'Yes, restore snapshot',
				cancelText: 'Keep current rules',
				icon: 'undo'
			}).then(function (confirmed) {
				if (!confirmed) { return; }
				setActionBusy('restore');
				apiFetch({ path: '/nbh-performance/v1/script-manager/history', method: 'POST', data: { snapshot_id: snapshotId } }).then(function (response) {
					applyPayload(Object.assign({}, status || {}, response));
					props.notify('Rules restored from history.', 'success');
					load(true);
				}).catch(function (error) {
					props.notify(error.message || 'Restore failed.', 'error');
				}).then(function () { setActionBusy(''); });
			});
		}

		function patchRule(index, patch) {
			setRules(function (current) {
				return current.map(function (rule, i) {
					if (i !== index) { return rule; }
					return Object.assign({}, rule, patch);
				});
			});
			setDirtyRules(true);
		}

		function removeRule(index, rule) {
			props.confirmAction({
				variant: 'warning',
				kicker: 'Before removing',
				title: 'Remove the rule for ' + ((rule && rule.handle) || 'this asset') + '?',
				message: 'The rule will leave the draft. Save rules afterwards to store the change.',
				note: 'You can restore an earlier snapshot from History if needed.',
				confirmText: 'Yes, remove rule',
				cancelText: 'Keep rule',
				icon: 'trash'
			}).then(function (confirmed) {
				if (!confirmed) { return; }
				setRules(function (current) { return current.filter(function (_rule, i) { return i !== index; }); });
				setDirtyRules(true);
				if (rule && rule.id && editingId === rule.id) {
					setEditingId('');
					setDraft(emptyDraft());
				}
				props.notify('Rule removed from draft. Save to persist.', 'info');
			});
		}

		function useAsset(asset) {
			if (!asset) { return; }
			setDraft(function (current) {
				return Object.assign({}, current, { handle: asset.handle || '', type: asset.type === 'style' ? 'style' : 'script' });
			});
			setEditingId('');
			setAnalysis(null);
			setWorkspace('rules');
			props.notify('Loaded ' + asset.handle + ' into the rule builder.', 'success');
		}

		function discardDraft() {
			load(false);
			setEditingId('');
			setDraft(emptyDraft());
			setAnalysis(null);
			props.notify('Draft discarded.', 'info');
		}

		var data = status || {};
		var inventory = data.inventory || {};
		var activeRules = rules.filter(function (rule) { return rule.enabled !== false; }).length;
		var heroTone = data.safe_mode ? 'warning' : (data.runtime_ready ? (testing ? 'warning' : 'good') : 'muted');
		var heroLabel = data.safe_mode ? 'Safe Mode pauses rules' : (data.runtime_ready ? (testing ? 'Testing Mode on' : (activeRules + ' rules live')) : 'Script Manager off');
		var normalizedRuleFilter = String(ruleFilter || '').toLowerCase().trim();
		var visibleRules = rules.filter(function (rule) {
			if (actionFilter !== 'all' && rule.action !== actionFilter) { return false; }
			if (!normalizedRuleFilter) { return true; }
			return [rule.handle, rule.action, rule.scope, rule.value, rule.note, (rule.roles || []).join(' ')].join(' ').toLowerCase().indexOf(normalizedRuleFilter) !== -1;
		});
		var normalizedInventoryFilter = String(inventoryFilter || '').toLowerCase().trim();
		var visibleAssets = assets.filter(function (asset) {
			if (inventoryType !== 'all' && asset.type !== inventoryType) { return false; }
			if (!normalizedInventoryFilter) { return true; }
			return [asset.handle, asset.type, asset.owner, asset.source].join(' ').toLowerCase().indexOf(normalizedInventoryFilter) !== -1;
		}).slice().sort(function (a, b) {
			return Number(b.bytes || 0) - Number(a.bytes || 0);
		}).slice(0, 100);

		function WorkspaceTab(id, label, count) {
			return el('button', {
				type: 'button',
				className: 'np-tab' + (workspace === id ? ' is-active' : ''),
				onClick: function () { setWorkspace(id); }
			}, label, el('span', null, count));
		}

		return el('div', { className: 'np-view np-script-manager-view' },
			featureGroup ? el(FeaturePanel, { group: featureGroup, busy: props.busy, toggle: function (option, checked) {
				var result = props.toggle(option, checked);
				if (result && typeof result.then === 'function') {
					result.then(function () { load(true); if (typeof props.refresh === 'function') { props.refresh(); } }, function () {});
				} else {
					setTimeout(function () { load(true); }, 350);
				}
			} }) : null,
			el('section', { className: 'np-panel np-script-hero' },
				el('div', { className: 'np-script-hero-copy' },
					el('span', { className: 'np-kicker' }, 'Script Manager'),
					el('h3', null, 'Per-file script and stylesheet control'),
					el('p', null, 'Unload, defer, delay, preload or relocate assets with URL, device, login and role conditions. Testing Mode keeps changes admin-only until you are ready.')
				),
				el('div', { className: 'np-script-hero-actions' },
					el('button', { className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { load(false); } }, Icon('refresh'), actionBusy === 'load' ? 'Refreshing…' : 'Refresh'),
					el('span', { className: 'np-quality-pill is-' + heroTone }, heroLabel)
				)
			),
			dirtyRules ? el('div', { className: 'np-script-draft-banner' },
				el('div', null, el('strong', null, 'Unsaved rule draft'), el('p', null, 'Save to store policy changes, or discard to reload the last saved rules.')),
				el('div', { className: 'np-script-draft-actions' },
					el('button', { type: 'button', className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: discardDraft }, 'Discard draft'),
					el('button', { type: 'button', className: 'np-button np-button-primary', disabled: !!actionBusy, onClick: function () {
						var goLive = !testing;
						var start = function () { saveRules(rules, testing, testing ? 'Rules saved. Testing Mode remains on.' : 'Rules saved and can affect matching visitors.'); };
						if (goLive) {
							props.confirmAction({
								variant: 'warning',
								kicker: 'Before going live',
								title: 'Save rules with Testing Mode off?',
								message: 'Matching visitors can receive unload, delay, defer and related Script Manager actions.',
								note: 'Keep Testing Mode on until you have verified critical templates as an administrator.',
								confirmText: 'Yes, save live rules',
								cancelText: 'Cancel',
								icon: 'warning'
							}).then(function (confirmed) { if (confirmed) { start(); } });
							return;
						}
						start();
					} }, actionBusy === 'save' ? 'Saving…' : 'Save rules')
				)
			) : null,
			data.safe_mode ? el('div', { className: 'np-object-alert is-warning' }, Icon('shield'), el('div', null, el('strong', null, 'Safe Mode is active'), el('p', null, 'Script Manager runtime stays paused for visitors until Safe Mode is turned off.'))) : null,
			!data.enabled ? el('div', { className: 'np-object-alert is-warning' }, Icon('warning'), el('div', null, el('strong', null, 'Script Manager is disabled'), el('p', null, 'Enable Per-file Script Manager above to apply rules on the frontend.'))) : null,
			testing && data.enabled ? el('div', { className: 'np-object-alert' }, Icon('eye'), el('div', null, el('strong', null, 'Testing Mode is on'), el('p', null, 'Rules affect only logged-in administrators. Turn Testing Mode off only after verifying pages.'))) : null,
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Status', description: 'Rules, inventory and safety posture at a glance.' }),
				el('div', { className: 'np-script-status-grid' },
					el('article', { className: 'np-script-status-card' }, el('small', null, 'Active rules'), el('strong', null, String(activeRules)), el('p', null, rules.length + ' total in this draft')),
					el('article', { className: 'np-script-status-card' }, el('small', null, 'Inventoried assets'), el('strong', null, String(Number(inventory.asset_count || assets.length || 0))), el('p', null, inventory.captured_at ? ('Captured ' + formatTimestamp(inventory.captured_at)) : 'Visit the frontend to refresh inventory.')),
					el('article', { className: 'np-script-status-card' }, el('small', null, 'Testing Mode'), el('strong', null, testing ? 'On' : 'Off'), el('p', null, testing ? 'Administrators only' : 'Can affect matching visitors')),
					el('article', { className: 'np-script-status-card' }, el('small', null, 'Safety warnings'), el('strong', null, String(Number(data.warning_count || warnings.length || 0))), el('p', null, warnings.length ? 'Review dependent files before going live.' : 'No dependency warnings stored.'))
				)
			),
			el('section', { className: 'np-panel' },
				el(PanelHeader, { title: 'Safety & library', description: 'Testing Mode saves immediately. Export or restore anytime.' }),
				el('div', { className: 'np-script-action-grid' },
					el('article', { className: 'np-script-action-card' },
						el('div', { className: 'np-script-action-top' },
							el('span', { className: 'np-script-action-icon' }, Icon('shield')),
							el('div', null, el('strong', null, 'Testing Mode'), el('p', null, 'Apply rules only while a logged-in administrator views the frontend.'))
						),
						el('div', { className: 'np-script-switch-row' },
							el('span', null, testing ? 'On for admins' : 'Live for visitors'),
							Switch(testing, !!actionBusy, setTestingMode, 'Testing Mode')
						)
					),
					el('article', { className: 'np-script-action-card' },
						el('div', { className: 'np-script-action-top' }, el('span', { className: 'np-script-action-icon' }, Icon('download')), el('div', null, el('strong', null, 'Export rules'), el('p', null, 'Download a portable JSON backup of the current saved rules.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-script-action-cta', disabled: !!actionBusy, onClick: exportRules }, Icon('download'), actionBusy === 'export' ? 'Exporting…' : 'Export')
					),
					el('article', { className: 'np-script-action-card' },
						el('div', { className: 'np-script-action-top' }, el('span', { className: 'np-script-action-icon' }, Icon('upload')), el('div', null, el('strong', null, 'Import rules'), el('p', null, 'Replace the current rule set with a validated JSON export.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-script-action-cta', disabled: !!actionBusy, onClick: importRules }, Icon('upload'), actionBusy === 'import' ? 'Importing…' : 'Import')
					),
					el('article', { className: 'np-script-action-card' },
						el('div', { className: 'np-script-action-top' }, el('span', { className: 'np-script-action-icon' }, Icon('check')), el('div', null, el('strong', null, 'Save all rules'), el('p', null, dirtyRules ? 'You have unsaved rule changes.' : 'Draft matches the last saved policy.'))),
						el('button', { type: 'button', className: 'np-button np-button-primary np-script-action-cta', disabled: !!actionBusy || !dirtyRules, onClick: function () { saveRules(rules, testing, testing ? 'Rules saved. Testing Mode remains on.' : 'Rules saved and can affect matching visitors.'); } }, Icon('check'), actionBusy === 'save' ? 'Saving…' : 'Save rules')
					)
				)
			),
			el('div', { className: 'np-tabs np-script-workspace-tabs', role: 'tablist' },
				WorkspaceTab('rules', 'Rules', rules.length),
				WorkspaceTab('inventory', 'Inventory', assets.length),
				WorkspaceTab('history', 'History', historyRows.length)
			),
			workspace === 'rules' ? el(wp.element.Fragment, null,
				el('section', { className: 'np-panel' },
					el(PanelHeader, { title: editingId ? 'Edit rule' : 'Create a rule', description: editingId ? 'Update the selected rule, then save the draft.' : 'Choose a file, action, location and audience. Dependency analysis runs before the rule is added.' }),
					el('div', { className: 'np-script-builder-grid' },
						el('label', { className: 'np-script-field is-wide' }, el('span', null, 'Script or style handle'), el('input', { list: 'np-script-assets', value: draft.handle, placeholder: 'Start typing a handle…', onChange: function (event) { updateDraft('handle', event.target.value); } }), el('datalist', { id: 'np-script-assets' }, assets.slice(0, 400).map(function (asset) {
							return el('option', { key: asset.handle + '-' + asset.type, value: asset.handle }, asset.handle + ' · ' + asset.type + ' · ' + (asset.owner || 'unknown'));
						}))),
						el('label', { className: 'np-script-field' }, el('span', null, 'File type'), el('select', { value: draft.type, onChange: function (event) { updateDraft('type', event.target.value); } }, el('option', { value: 'script' }, 'JavaScript'), el('option', { value: 'style' }, 'Stylesheet'))),
						el('label', { className: 'np-script-field' }, el('span', null, 'Action'), el('select', { value: draft.action, onChange: function (event) { updateDraft('action', event.target.value); } }, Object.keys(actions).map(function (key) { return el('option', { key: key, value: key }, actions[key]); }))),
						el('label', { className: 'np-script-field' }, el('span', null, 'Where it applies'), el('select', { value: draft.scope, onChange: function (event) { updateDraft('scope', event.target.value); } }, Object.keys(scopes).map(function (key) { return el('option', { key: key, value: key }, scopes[key]); }))),
						el('label', { className: 'np-script-field is-wide' }, el('span', null, 'URL or matching value'), el('input', { value: draft.value, placeholder: 'Example: /checkout/ or product', onChange: function (event) { updateDraft('value', event.target.value); } })),
						el('label', { className: 'np-script-field' }, el('span', null, 'Devices'), el('select', { value: draft.device, onChange: function (event) { updateDraft('device', event.target.value); } }, el('option', { value: 'all' }, 'All devices'), el('option', { value: 'desktop' }, 'Desktop'), el('option', { value: 'mobile' }, 'Mobile'))),
						el('label', { className: 'np-script-field' }, el('span', null, 'Visitors'), el('select', { value: draft.auth, onChange: function (event) { updateDraft('auth', event.target.value); } }, el('option', { value: 'all' }, 'All visitors'), el('option', { value: 'guest' }, 'Logged out'), el('option', { value: 'logged_in' }, 'Logged in'))),
						el('label', { className: 'np-script-field' }, el('span', null, 'Priority'), el('input', { type: 'number', min: 1, max: 100, value: draft.priority, onChange: function (event) { updateDraft('priority', event.target.value); } })),
						el('label', { className: 'np-script-field is-wide' }, el('span', null, 'Roles (optional)'), el('input', { value: draft.roles, placeholder: 'administrator, shop_manager', onChange: function (event) { updateDraft('roles', event.target.value); } })),
						el('label', { className: 'np-script-field' }, el('span', null, 'Language'), el('input', { value: draft.language, placeholder: 'en or en-us', onChange: function (event) { updateDraft('language', event.target.value); } })),
						el('label', { className: 'np-script-field' }, el('span', null, 'Query'), el('input', { value: draft.query, placeholder: 'campaign=spring', onChange: function (event) { updateDraft('query', event.target.value); } })),
						el('label', { className: 'np-script-field' }, el('span', null, 'Cookie'), el('input', { value: draft.cookie, placeholder: 'currency=USD', onChange: function (event) { updateDraft('cookie', event.target.value); } })),
						el('label', { className: 'np-script-field is-wide' }, el('span', null, 'Internal note'), el('input', { value: draft.note, placeholder: 'Why this rule exists', onChange: function (event) { updateDraft('note', event.target.value); } }))
					),
					analysis ? el('div', { className: 'np-script-analysis' },
						el('strong', null, 'Last analysis · ' + humanizeStatus(analysis.risk || 'unknown') + ' risk'),
						el('p', null, (analysis.dependents && analysis.dependents.length ? ('Dependents: ' + analysis.dependents.join(', ')) : 'No queued dependents detected.') + (analysis.protected ? ' This handle is protected.' : ''))
					) : null,
					el('div', { className: 'np-script-builder-footer' },
						el('div', { className: 'np-script-switch-row' },
							el('span', null, 'Include dependent files when unloading'),
							Switch(!!draft.cascade, !!actionBusy, function (checked) { updateDraft('cascade', checked); }, 'Cascade dependents')
						),
						el('div', { className: 'np-script-builder-actions' },
							editingId ? el('button', { type: 'button', className: 'np-button np-button-ghost', disabled: !!actionBusy, onClick: function () { setEditingId(''); setDraft(emptyDraft()); setAnalysis(null); } }, 'Cancel edit') : null,
							el('button', { type: 'button', className: 'np-button np-button-primary', disabled: !!actionBusy, onClick: addOrUpdateRule }, Icon('filter'), actionBusy === 'analyze' ? 'Analyzing…' : (editingId ? 'Update rule' : 'Add this rule'))
						)
					),
					protectedHandles.length ? el('p', { className: 'np-script-meta-note' }, 'Protected handles include: ' + protectedHandles.slice(0, 8).join(', ') + (protectedHandles.length > 8 ? '…' : '')) : null
				),
				el('section', { className: 'np-panel' },
					el('div', { className: 'np-panel-toolbar' },
						el(PanelHeader, { title: 'Saved policy', description: 'Enable, change actions, edit, duplicate or remove rules. Save to store the policy.' }),
						el('div', { className: 'np-script-filters' },
							el('select', { value: actionFilter, onChange: function (event) { setActionFilter(event.target.value); }, 'aria-label': 'Filter by action' },
								el('option', { value: 'all' }, 'All actions'),
								Object.keys(actions).map(function (key) { return el('option', { key: key, value: key }, actions[key]); })
							),
							el('label', { className: 'np-setting-search' }, Icon('search'), el('input', { type: 'search', value: ruleFilter, placeholder: 'Filter rules…', onChange: function (event) { setRuleFilter(event.target.value); }, 'aria-label': 'Filter rules' }))
						)
					),
					visibleRules.length ? el('div', { className: 'np-script-table-wrap' },
						el('table', { className: 'np-script-table' },
							el('thead', null, el('tr', null, el('th', null, 'Status'), el('th', null, 'File'), el('th', null, 'Action'), el('th', null, 'Location'), el('th', null, 'Audience'), el('th', null, 'Deps'), el('th', null, 'Manage'))),
							el('tbody', null, visibleRules.map(function (rule) {
								var index = rules.indexOf(rule);
								return el('tr', { key: rule.id || (rule.handle + '-' + index), className: editingId && editingId === rule.id ? 'is-editing' : '' },
									el('td', null, el('label', { className: 'np-script-mini-switch' }, el('input', { type: 'checkbox', checked: rule.enabled !== false, onChange: function (event) { patchRule(index, { enabled: event.target.checked }); } }), el('b', null, rule.enabled !== false ? 'On' : 'Off'))),
									el('td', null, el('strong', null, rule.handle), el('small', null, (rule.type === 'style' ? 'Stylesheet' : 'JavaScript') + (rule.note ? ' · ' + rule.note : ''))),
									el('td', null, el('select', { value: rule.action, onChange: function (event) { patchRule(index, { action: event.target.value }); } }, Object.keys(actions).map(function (key) { return el('option', { key: key, value: key }, actions[key]); }))),
									el('td', null, el('strong', null, scopes[rule.scope] || rule.scope), el('small', null, rule.value || 'No extra value')),
									el('td', null, el('strong', null, rule.device === 'all' ? 'All devices' : rule.device), el('small', null, (rule.auth === 'all' ? 'All visitors' : rule.auth) + ((rule.roles || []).length ? ' · ' + rule.roles.join(', ') : ''))),
									el('td', null, el('span', { className: 'np-script-badge' + (rule.cascade ? ' is-on' : '') }, rule.cascade ? 'Included' : 'Protected')),
									el('td', null, el('div', { className: 'np-script-row-actions' },
										el('button', { type: 'button', className: 'np-button np-button-ghost', onClick: function () { editRule(rule); } }, 'Edit'),
										el('button', { type: 'button', className: 'np-button np-button-ghost', onClick: function () { duplicateRule(rule); } }, 'Copy'),
										el('button', { type: 'button', className: 'np-icon-button', 'aria-label': 'Remove ' + rule.handle, onClick: function () { removeRule(index, rule); } }, Icon('trash'))
									))
								);
							}))
						)
					) : el('div', { className: 'np-script-empty' }, el('strong', null, 'No matching rules'), el('p', null, rules.length ? 'Try a different filter.' : 'Create your first rule above. Keep Testing Mode enabled while checking the frontend.'))
				)
			) : null,
			workspace === 'inventory' ? el('section', { className: 'np-panel' },
				el('div', { className: 'np-panel-toolbar' },
					el(PanelHeader, { title: 'Asset inventory', description: inventory.url ? ('Last capture from ' + inventory.url) : 'Frontend visits populate this list when Script Manager or inventory tracking is on.' }),
					el('div', { className: 'np-script-filters' },
						el('select', { value: inventoryType, onChange: function (event) { setInventoryType(event.target.value); }, 'aria-label': 'Filter by type' },
							el('option', { value: 'all' }, 'All types'),
							el('option', { value: 'script' }, 'JavaScript'),
							el('option', { value: 'style' }, 'Stylesheets')
						),
						el('label', { className: 'np-setting-search' }, Icon('search'), el('input', { type: 'search', value: inventoryFilter, placeholder: 'Filter assets…', onChange: function (event) { setInventoryFilter(event.target.value); }, 'aria-label': 'Filter assets' }))
					)
				),
				visibleAssets.length
					? el('ul', { className: 'np-script-asset-list is-dense' }, visibleAssets.map(function (asset, index) {
						return el('li', { key: (asset.handle || 'asset') + '-' + index },
							el('div', null,
								el('strong', null, asset.handle || 'unknown'),
								el('span', null, (asset.type || 'script') + ' · ' + (asset.owner || 'unknown') + (asset.bytes ? (' · ' + formatBytes(asset.bytes)) : '') + ((asset.dependents && asset.dependents.length) ? (' · ' + asset.dependents.length + ' dependents') : ''))
							),
							el('button', { type: 'button', className: 'np-button np-button-ghost', onClick: function () { useAsset(asset); } }, 'Use in rule')
						);
					}))
					: el('p', { className: 'np-script-empty' }, 'No inventoried assets yet. Open a public page while Script Manager is enabled, then refresh.')
			) : null,
			workspace === 'history' ? el('div', { className: 'np-script-side-grid' },
				el('section', { className: 'np-panel' },
					el(PanelHeader, { title: 'History', description: 'Restore a previous rules snapshot if a change causes trouble.' }),
					historyRows.length
						? el('ul', { className: 'np-script-history-list' }, historyRows.slice(0, 12).map(function (row) {
							return el('li', { key: row.id || String(row.created_at) },
								el('div', null,
									el('strong', null, humanizeStatus(row.reason || 'snapshot')),
									el('span', null, (row.created_at ? formatTimestamp(row.created_at) : '—') + ' · ' + String(Number(row.rule_count || 0)) + ' rules')
								),
								el('button', { type: 'button', className: 'np-button np-button-ghost', disabled: !!actionBusy || !row.id, onClick: function () { restoreSnapshot(row.id); } }, 'Restore')
							);
						}))
						: el('p', { className: 'np-script-empty' }, 'History appears after the first save or import.')
				),
				el('section', { className: 'np-panel' },
					el(PanelHeader, { title: 'Dependency warnings', description: 'Recent unload attempts that were blocked because dependents still needed the asset.' }),
					warnings.length
						? el('ul', { className: 'np-script-warning-list' }, warnings.slice(-10).reverse().map(function (row, index) {
							return el('li', { key: (row.rule_id || 'warn') + '-' + index },
								el('strong', null, row.handle || 'asset'),
								el('span', null, humanizeStatus(row.reason || 'warning') + ((row.blocked_by || []).length ? (' · blocked by ' + row.blocked_by.join(', ')) : ''))
							);
						}))
						: el('p', { className: 'np-script-empty' }, 'No dependency warnings stored.')
				)
			) : null,
			settings && props.showAdvancedSettings ? el('div', { className: 'np-unified-settings-area' },
				el(FieldPanel, { sectionId: 'script_manager', title: 'Script Manager settings', description: 'Testing Mode defaults and optional inventory capture.', keys: fieldKeys, fields: fields, drafts: props.drafts, setDrafts: props.setDrafts }),
				el(StickyAdvancedSave, { dirty: props.dirty, busy: !!props.busy['save-script_manager'], setAdvancedSettings: props.setAdvancedSettings, onDiscard: function () { props.reset('script_manager'); }, onSave: function () { props.save('script_manager'); } })
			) : el(AdvancedSettingsGate, { title: 'Advanced Script Manager settings', description: 'Testing Mode default and inventory capture for the asset browser.', count: fieldKeys.length, onOpen: function () { props.setAdvancedSettings(true); } })
		);
	}

	function AboutView(props) {
		var version = (props.data && props.data.version) || '1.0.0';
		var iconUrl = window.nbhPerformanceHub && window.nbhPerformanceHub.iconUrl;
		var highlights = [
			{ icon: 'layers', title: 'Faster everyday visits', copy: 'Page caching and practical CSS and JavaScript tools help reduce the work behind each page.' },
			{ icon: 'image', title: 'Lighter pages', copy: 'Image conversion, lazy loading and media controls help your content load more efficiently.' },
			{ icon: 'sliders', title: 'You stay in control', copy: 'Choose what to enable, review performance insights and adjust settings as your site grows.' }
		];
		return el('div', { className: 'np-view np-about-view' },
			el('section', { className: 'np-panel np-about-hero', 'aria-labelledby': 'np-about-product-title' },
				el('div', { className: 'np-about-identity' },
					iconUrl ? el('img', { className: 'np-about-logo', src: iconUrl, alt: '', width: 76, height: 76 }) : null,
					el('div', null, el('span', { className: 'np-kicker' }, 'Performance & Reliability'), el('h2', { id: 'np-about-product-title' }, 'NBH Performance'), el('p', { className: 'np-about-byline' }, 'By ', el('a', { href: 'https://nobash.com', target: '_blank', rel: 'noopener noreferrer' }, 'Nobash')))
				),
				el('p', { className: 'np-about-intro' }, "Improve your WordPress site's speed and performance with reliable page caching, image optimization, and practical optimization tools. Built with simplicity, stability, and control in mind."),
				el('div', { className: 'np-about-hero-footer' },
					el('div', { className: 'np-about-actions' },
						el('a', { className: 'np-button np-button-primary', href: 'https://nbhperformance.com', target: '_blank', rel: 'noopener noreferrer' }, Icon('globe'), 'Visit plugin website'),
						el('button', { type: 'button', className: 'np-button np-button-ghost', onClick: function () { props.setActive('help'); } }, Icon('info'), 'Help & guides')
					),
					el('span', { className: 'np-about-release' }, 'Version ', el('strong', null, version), el('span', { 'aria-hidden': 'true' }, ' · '), 'Free & open source')
				)
			),
			el('section', { className: 'np-panel np-about-purpose', 'aria-labelledby': 'np-about-purpose-title' },
				el('div', { className: 'np-about-section-intro' }, el('span', { className: 'np-kicker' }, 'Why NBH Performance'), el('h3', { id: 'np-about-purpose-title' }, 'A faster site. A simpler workflow.')),
				el('div', { className: 'np-about-highlights' }, highlights.map(function (item) {
					return el('article', { className: 'np-about-highlight', key: item.title }, el('span', { className: 'np-about-feature-icon' }, Icon(item.icon)), el('h4', null, item.title), el('p', null, item.copy));
				}))
			),
			el('div', { className: 'np-about-bottom-grid' },
				el('section', { className: 'np-panel np-about-maker' },
					el('span', { className: 'np-kicker' }, 'The people behind the plugin'),
					el('h3', null, 'Made by ', el('a', { href: 'https://nobash.com', target: '_blank', rel: 'noopener noreferrer' }, 'Nobash')),
					el('p', null, 'We built NBH Performance to make WordPress optimization easier to understand and manage. The focus is straightforward: useful tools, clear choices and control over your own website.'),
					el('a', { className: 'np-about-text-link', href: 'https://nobash.com', target: '_blank', rel: 'noopener noreferrer' }, 'Meet Nobash', Icon('arrow'))
				),
				el('section', { className: 'np-panel np-about-updates' },
					el('span', { className: 'np-kicker' }, 'Version & updates'),
					el('h3', null, 'Keep your plugin up to date'),
					el('p', null, 'Manage this installation from the WordPress Plugins screen. To update with a release ZIP, choose Add Plugin → Upload Plugin and select the newer file.'),
					el('a', { className: 'np-about-text-link', href: 'plugins.php' }, 'Manage plugin updates', Icon('arrow'))
				)
			),
			el('footer', { className: 'np-about-footer' },
				el('span', null, 'Free to use, study, modify and share. ', el('a', { href: 'https://www.gnu.org/licenses/gpl-2.0.html', target: '_blank', rel: 'noopener noreferrer' }, 'GPLv2 or later')),
				el('a', { href: 'https://nbhperformance.com', target: '_blank', rel: 'noopener noreferrer' }, 'nbhperformance.com')
			)
		);
	}


	function OnboardingWizard(props) {
		var stepState = useState(0), step = stepState[0], setStep = stepState[1];
		var pathState = useState(''), path = pathState[0], setPath = pathState[1];
		var recommendedProfile = ['business', 'woocommerce', 'shared', 'maximum'].indexOf(props.recommendedProfile) !== -1 ? props.recommendedProfile : 'business';
		var formState = useState(Object.assign({ path: '', profile: recommendedProfile, site_type: recommendedProfile, hosting: 'balanced', cdn_provider: 'custom', cdn_url: '', database_schedule: 'weekly', font_display: 'swap' }, onboardingProfileDefaults(recommendedProfile))), form = formState[0], setForm = formState[1];
		var steps = onboardingStepsForPath(path);
		var profiles = [
			{ id: 'business', icon: 'building', title: 'Business / Blog', copy: 'Balanced caching, minify, lazy load and preload.', badge: recommendedProfile === 'business' ? 'Recommended' : 'Balanced', chips: ['Page cache', 'Minify', 'Lazy load', 'Preload'] },
			{ id: 'woocommerce', icon: 'cart', title: 'WooCommerce', copy: 'Cart, checkout and session safe caching.', badge: recommendedProfile === 'woocommerce' ? 'Recommended' : 'Store safe', chips: ['Cart safe', 'Checkout', 'Sessions'] },
			{ id: 'shared', icon: 'cloud', title: 'Shared hosting', copy: 'Gentle concurrency for limited resources.', badge: recommendedProfile === 'shared' ? 'Recommended' : 'Low resource', chips: ['Light preload', 'Longer TTL'] },
			{ id: 'maximum', icon: 'performance', title: 'Maximum', copy: 'Delay JS, Critical CSS, next-gen images and RUM.', badge: 'Advanced', chips: ['Delay JS', 'Critical CSS', 'RUM'] }
		];
		function update(key, value) {
			var next = Object.assign({}, form);
			next[key] = value;
			if (key === 'profile') {
				next = Object.assign(next, onboardingProfileDefaults(value));
				next.profile = value;
				next.site_type = value;
			}
			setForm(next);
		}
		function choosePath(nextPath) {
			setPath(nextPath);
			update('path', nextPath);
			setStep(1);
		}
		function submit() {
			props.complete({
				profile: form.profile,
				settings: {
					nbh_performance_cache_ttl: Number(form.cache_ttl),
					nbh_performance_preload_batch_size: Number(form.preload_batch),
					nbh_performance_webp_quality: Number(form.image_quality),
					nbh_performance_avif_quality: Math.max(30, Number(form.image_quality) - 12),
					nbh_performance_edge_provider: form.cdn_provider,
					nbh_performance_cdn_base_url: form.cdn_url,
					nbh_performance_database_schedule: form.database_schedule,
					nbh_performance_font_display: form.font_display
				},
				features: {
					nbh_performance_rum_enabled: !!form.rum,
					nbh_performance_cdn_rewrite_enabled: !!form.cdn_url
				}
			});
		}
		function goNext() {
			if (step >= steps.length - 1) { submit(); return; }
			setStep(step + 1);
		}
		var currentId = steps[step] || 'welcome';
		var progress = Math.round(((step + 1) / Math.max(steps.length, 1)) * 100);
		var recLabel = { business: 'Business / Blog', woocommerce: 'WooCommerce', shared: 'Shared hosting', maximum: 'Maximum' }[recommendedProfile] || 'Business / Blog';
		var logoUrl = window.nbhPerformanceHub && window.nbhPerformanceHub.logoUrl;

		return el('div', { className: 'np-onboarding-screen' + (currentId === 'welcome' ? ' is-welcome' : ''), role: 'region', 'aria-label': 'NBH Performance onboarding' },
			el('div', { className: 'np-onboarding-atmosphere', 'aria-hidden': 'true' }),
			el('div', { className: 'np-onboarding-frame' },
				el('header', { className: 'np-onboarding-top' },
					el('div', { className: 'np-onboarding-brandline' },
						logoUrl ? el('img', { className: 'np-onboarding-logo', src: logoUrl, alt: 'NBH Performance' }) : el('span', { className: 'np-onboarding-mark' }, Icon('layers')),
						el('small', null, props.siteName || 'WordPress')
					),
					path ? el('div', { className: 'np-onboarding-stepper', 'aria-hidden': 'true' },
						steps.filter(function (id) { return id !== 'welcome'; }).map(function (id, index) {
							var realIndex = index + 1;
							var activeIndex = step;
							return el('span', {
								key: id,
								className: 'np-onboarding-dot' + (realIndex === activeIndex ? ' is-current' : (realIndex < activeIndex ? ' is-done' : ''))
							}, onboardingStepLabel(id));
						})
					) : el('span', { className: 'np-onboarding-edition' }, 'Free'),
					props.close ? el('button', { className: 'np-icon-button', onClick: props.close, 'aria-label': 'Close onboarding' }, Icon('close')) : el('span', { className: 'np-onboarding-top-spacer' })
				),
				el('div', { className: 'np-onboarding-progress', 'aria-hidden': 'true' }, el('span', { style: { width: progress + '%' } })),
				el('section', { className: 'np-onboarding-stage' },
					currentId !== 'welcome' ? el('header', { className: 'np-onboarding-head' },
						el('div', null,
							el('span', { className: 'np-kicker' }, 'Step ' + step + ' of ' + (steps.length - 1)),
							el('h2', null, onboardingTitle(currentId, props.siteName)),
							el('p', null, onboardingCopy(currentId, recommendedProfile))
						)
					) : null,
					el('div', { className: 'np-onboarding-body', key: currentId },
						currentId === 'welcome' ? OnboardingWelcome({ siteName: props.siteName, recommendedLabel: recLabel, choosePath: choosePath, skip: function () { props.complete({ skip: true }); }, busy: props.busy }) : null,
						currentId === 'profile' ? OnboardingProfiles(profiles, form, update) : null,
						currentId === 'performance' ? OnboardingPerformance(form, update) : null,
						currentId === 'delivery' ? OnboardingDelivery(form, update) : null,
						currentId === 'review' || currentId === 'launch' ? OnboardingReview(form, profiles, path) : null
					),
					currentId !== 'welcome' ? el('footer', { className: 'np-onboarding-footer' },
						el('button', { className: 'np-button np-button-ghost', disabled: props.busy, onClick: function () {
							if (step <= 1) { setPath(''); update('path', ''); setStep(0); return; }
							setStep(Math.max(0, step - 1));
						} }, 'Back'),
						el('div', { className: 'np-onboarding-footer-actions' },
							el('button', { className: 'np-button np-button-link', disabled: props.busy, onClick: function () { props.complete({ skip: true }); } }, 'Skip setup'),
							el('button', { className: 'np-button np-button-primary np-button-launch', disabled: props.busy, onClick: goNext },
								props.busy ? el('span', { className: 'np-spinner' }) : null,
								props.busy ? 'Applying…' : (step >= steps.length - 1 ? 'Launch' : 'Continue')
							)
						)
					) : null
				)
			)
		);
	}

	function onboardingStepsForPath(path) {
		if (path === 'express') { return ['welcome', 'profile', 'launch']; }
		if (path === 'guided') { return ['welcome', 'profile', 'performance', 'delivery', 'review']; }
		return ['welcome'];
	}

	function onboardingProfileDefaults(profile) {
		var map = {
			business: { cache_ttl: 3600, preload_batch: 3, image_quality: 82, rum: false, database_schedule: 'weekly' },
			woocommerce: { cache_ttl: 1800, preload_batch: 2, image_quality: 84, rum: false, database_schedule: 'weekly' },
			shared: { cache_ttl: 7200, preload_batch: 1, image_quality: 80, rum: false, database_schedule: 'monthly' },
			maximum: { cache_ttl: 43200, preload_batch: 6, image_quality: 82, rum: true, database_schedule: 'weekly' }
		};
		return Object.assign({}, map[profile] || map.business);
	}

	function onboardingStepLabel(id) {
		return { welcome: 'Start', profile: 'Profile', performance: 'Engine', delivery: 'Delivery', review: 'Review', launch: 'Launch' }[id] || id;
	}

	function OnboardingWelcome(props) {
		return el('div', { className: 'np-onboarding-welcome' },
			el('div', { className: 'np-welcome-hero-block' },
				el('p', { className: 'np-welcome-eyebrow' }, 'Enterprise WordPress performance'),
				el('h1', null, 'Make your site faster — without stacking five plugins.'),
				el('p', { className: 'np-welcome-sub' }, 'One control plane for cache, assets, media, CDN and Safe Mode recovery.'),
				el('div', { className: 'np-welcome-detect' },
					el('span', { className: 'np-welcome-detect-dot' }),
					'Detected fit: ',
					el('strong', null, props.recommendedLabel)
				)
			),
			el('div', { className: 'np-path-list' },
				el('button', { type: 'button', className: 'np-path-row is-featured', disabled: props.busy, onClick: function () { props.choosePath('express'); } },
					el('span', { className: 'np-path-row-icon' }, Icon('bolt')),
					el('span', { className: 'np-path-row-copy' },
						el('span', { className: 'np-path-row-meta' }, el('strong', null, 'Recommended Fast'), el('em', null, '2 steps')),
						el('span', { className: 'np-path-row-desc' }, 'Apply a site-aware profile and exit Safe Mode.')
					),
					el('span', { className: 'np-path-row-action' }, 'Start', Icon('arrow'))
				),
				el('button', { type: 'button', className: 'np-path-row', disabled: props.busy, onClick: function () { props.choosePath('guided'); } },
					el('span', { className: 'np-path-row-icon' }, Icon('setup')),
					el('span', { className: 'np-path-row-copy' },
						el('span', { className: 'np-path-row-meta' }, el('strong', null, 'Guided setup'), el('em', null, 'Custom')),
						el('span', { className: 'np-path-row-desc' }, 'Tune cache, images, CDN and maintenance before launch.')
					),
					el('span', { className: 'np-path-row-action is-ghost' }, 'Customize', Icon('arrow'))
				)
			),
			el('ul', { className: 'np-welcome-trust' },
				el('li', null, Icon('shield'), 'Commerce & form safe'),
				el('li', null, Icon('backup'), 'Safe Mode rollback'),
				el('li', null, Icon('chart'), 'Optional RUM field data')
			),
			el('div', { className: 'np-welcome-skip' },
				el('button', { type: 'button', className: 'np-button np-button-link', disabled: props.busy, onClick: props.skip }, 'Skip — open dashboard')
			)
		);
	}

	function OnboardingProfiles(profiles, form, update) {
		return el('div', { className: 'np-profile-grid' }, profiles.map(function (profile) {
			return el('button', {
				key: profile.id,
				type: 'button',
				className: 'np-profile-card' + (form.profile === profile.id ? ' is-selected' : ''),
				onClick: function () { update('profile', profile.id); }
			},
				el('span', { className: 'np-profile-icon' }, Icon(genericIconFor(profile.icon))),
				el('span', { className: 'np-profile-badge' }, profile.badge),
				el('strong', null, profile.title),
				el('p', null, profile.copy),
				el('div', { className: 'np-profile-chips' }, (profile.chips || []).map(function (chip) { return el('span', { key: chip }, chip); })),
				el('i', { className: 'np-profile-check', 'aria-hidden': 'true' }, Icon('check'))
			);
		}));
	}

	function OnboardingPerformance(form, update) {
		return el('div', { className: 'np-onboarding-form' },
			WizardField('Cache lifespan', 'How long public HTML remains fresh.',
				el('select', { value: form.cache_ttl, onChange: function (e) { update('cache_ttl', e.target.value); } },
					el('option', { value: 1800 }, '30 minutes'),
					el('option', { value: 3600 }, '1 hour'),
					el('option', { value: 7200 }, '2 hours'),
					el('option', { value: 43200 }, '12 hours')
				)
			),
			WizardField('Preload intensity', 'Background URLs processed per run.',
				el('select', { value: form.preload_batch, onChange: function (e) { update('preload_batch', e.target.value); } },
					el('option', { value: 1 }, 'Low — shared hosting'),
					el('option', { value: 3 }, 'Balanced'),
					el('option', { value: 6 }, 'High capacity')
				)
			),
			WizardField('Image quality', 'WebP quality; AVIF is derived conservatively.',
				el('input', { type: 'range', min: 60, max: 95, value: form.image_quality, onChange: function (e) { update('image_quality', e.target.value); } }),
				el('strong', { className: 'np-range-value' }, form.image_quality + '%')
			),
			WizardField('Real User Monitoring', 'Privacy-safe LCP, INP and CLS field data.',
				Switch(form.rum, false, function (checked) { update('rum', checked); }, 'Real User Monitoring')
			)
		);
	}

	function OnboardingDelivery(form, update) {
		return el('div', { className: 'np-onboarding-form' },
			WizardField('CDN provider', 'Optional edge delivery and purge integration.',
				el('select', { value: form.cdn_provider, onChange: function (e) { update('cdn_provider', e.target.value); } },
					el('option', { value: 'custom' }, 'No CDN / Custom'),
					el('option', { value: 'cloudflare' }, 'Cloudflare'),
					el('option', { value: 'bunnycdn' }, 'BunnyCDN'),
					el('option', { value: 'keycdn' }, 'KeyCDN')
				)
			),
			WizardField('CDN hostname', 'Leave empty when assets should remain on the origin.',
				el('input', { type: 'url', value: form.cdn_url, placeholder: 'https://cdn.example.com', onChange: function (e) { update('cdn_url', e.target.value); } })
			),
			WizardField('Database maintenance', 'Conservative scheduled work only.',
				el('select', { value: form.database_schedule, onChange: function (e) { update('database_schedule', e.target.value); } },
					el('option', { value: 'off' }, 'Disabled'),
					el('option', { value: 'weekly' }, 'Weekly'),
					el('option', { value: 'monthly' }, 'Monthly')
				)
			),
			WizardField('Font display', 'Perceived text rendering during font load.',
				el('select', { value: form.font_display, onChange: function (e) { update('font_display', e.target.value); } },
					el('option', { value: 'swap' }, 'Swap — recommended'),
					el('option', { value: 'optional' }, 'Optional'),
					el('option', { value: 'fallback' }, 'Fallback')
				)
			)
		);
	}

	function WizardField(title, copy) {
		var children = Array.prototype.slice.call(arguments, 2);
		return el.apply(null, ['div', { className: 'np-wizard-field' }, el('div', null, el('strong', null, title), el('p', null, copy)), el.apply(null, ['div', { className: 'np-wizard-control' }].concat(children))]);
	}

	function OnboardingReview(form, profiles, path) {
		var selected = profiles.filter(function (p) { return p.id === form.profile; })[0] || profiles[0];
		var ttlHours = Number(form.cache_ttl) / 3600;
		var ttlLabel = ttlHours < 1 ? (Number(form.cache_ttl) / 60) + ' min' : ttlHours + ' hour lifespan';
		return el('div', { className: 'np-review' },
			el('div', { className: 'np-review-banner' },
				el('span', { className: 'np-review-banner-icon' }, Icon(path === 'express' ? 'bolt' : 'check')),
				el('div', null,
					el('strong', null, path === 'express' ? 'Ready to launch' : 'Confirm your plan'),
					el('p', null, 'Applies this profile, exits Safe Mode, and keeps every setting editable.')
				)
			),
			el('div', { className: 'np-review-profile' },
				el('span', { className: 'np-review-profile-icon' }, Icon(genericIconFor(selected.icon))),
				el('div', null, el('small', null, 'Selected profile'), el('strong', null, selected.title), el('p', null, selected.copy))
			),
			el('div', { className: 'np-review-list' },
				ReviewRow('Page cache', ttlLabel),
				ReviewRow('Preloading', form.preload_batch + ' URLs / run'),
				ReviewRow('Image quality', form.image_quality + '% WebP'),
				ReviewRow('CDN', form.cdn_url || 'Origin'),
				ReviewRow('Database', form.database_schedule),
				ReviewRow('Fonts', form.font_display),
				ReviewRow('RUM', form.rum ? 'On' : 'Off')
			)
		);
	}

	function ReviewRow(label, value) { return el('div', null, el('span', null, label), el('strong', null, value)); }

	function onboardingTitle(id) {
		return {
			profile: 'Choose a profile',
			performance: 'Tune the engine',
			delivery: 'Delivery & care',
			review: 'Review & launch',
			launch: 'Launch'
		}[id] || 'Setup';
	}

	function onboardingCopy(id, recommendedProfile) {
		var rec = { business: 'business sites', woocommerce: 'WooCommerce', shared: 'shared hosting', maximum: 'high-capacity stacks' }[recommendedProfile] || 'most sites';
		return {
			profile: 'Defaults are tuned for ' + rec + '. You can change anything later.',
			performance: 'Cache freshness, preload load and media quality.',
			delivery: 'Optional CDN and maintenance — skip if unsure.',
			review: 'Nothing is hidden. Confirm before apply.',
			launch: 'One click applies the recommended profile.'
		}[id] || '';
	}

	function databaseCleanupLabels() {
		return {
			revisions: 'Post revisions',
			auto_drafts: 'Auto-drafts',
			trashed_posts: 'Trashed posts',
			spam_comments: 'Spam comments',
			trashed_comments: 'Trashed comments',
			expired_transients: 'Expired transients',
			expired_site_transients: 'Expired site transients',
			orphan_postmeta: 'Orphaned post metadata',
			orphan_termmeta: 'Orphaned term metadata',
			orphan_commentmeta: 'Orphaned comment metadata',
			orphan_term_relationships: 'Orphaned term relationships',
			woocommerce_sessions: 'Expired WooCommerce sessions',
			woocommerce_transients: 'Expired WooCommerce transients',
			action_scheduler: 'Old Action Scheduler jobs'
		};
	}

	function useModalLifecycle(onClose) {
		var dialogRef = useRef(null);
		useEffect(function () {
			var previous = document.activeElement;
			var node = dialogRef.current;
			var focusables = function () {
				if (!node) { return []; }
				return Array.prototype.slice.call(node.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter(function (elNode) {
					return !elNode.disabled && elNode.getAttribute('aria-hidden') !== 'true';
				});
			};
			var first = focusables()[0];
			if (first && first.focus) { first.focus(); }
			function onKey(event) {
				if (event.key === 'Escape') {
					event.preventDefault();
					onClose();
					return;
				}
				if (event.key !== 'Tab') { return; }
				var items = focusables();
				if (!items.length) { return; }
				var firstItem = items[0];
				var lastItem = items[items.length - 1];
				if (event.shiftKey && document.activeElement === firstItem) {
					event.preventDefault();
					lastItem.focus();
				} else if (!event.shiftKey && document.activeElement === lastItem) {
					event.preventDefault();
					firstItem.focus();
				}
			}
			document.addEventListener('keydown', onKey);
			return function () {
				document.removeEventListener('keydown', onKey);
				if (previous && previous.focus) { previous.focus(); }
			};
		}, []);
		return dialogRef;
	}

	function ModalNote(text, tone) {
		if (!text) { return null; }
		return el('div', { className: 'np-result-note np-modal-note is-' + (tone || 'info') }, Icon('info'), el('span', null, text));
	}

	function CompatibilityModal(props) {
		var conflict = props.modal && props.modal.conflict ? props.modal.conflict : {};
		var choices = Array.isArray(conflict.choices) ? conflict.choices : [];
		var groups = Array.isArray(conflict.groups) ? conflict.groups : [];
		var groupLabels = { cache: 'Page cache', assets: 'CSS & JavaScript', lazyload: 'Lazy loading', images: 'Image optimization', cdn: 'CDN' };
		var dialogRef = useModalLifecycle(function () { if (!props.busy) { props.cancel(); } });
		return el('div', { className: 'np-modal-backdrop', onMouseDown: function (event) { if (event.target === event.currentTarget && !props.busy) { props.cancel(); } } },
			el('div', { className: 'np-modal np-compatibility-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'np-compatibility-modal-title', ref: dialogRef },
				el('div', { className: 'np-modal-head' },
					el('div', { className: 'np-result-heading' },
						el('span', { className: 'np-result-icon is-warning' }, Icon('warning')),
						el('div', { className: 'np-modal-title-stack' },
							el('span', { className: 'np-kicker' }, 'Compatibility'),
							el('h3', { id: 'np-compatibility-modal-title' }, conflict.title || 'Choose which plugin should manage this feature')
						)
					),
					el('button', { className: 'np-icon-button', disabled: props.busy, onClick: props.cancel, 'aria-label': 'Close' }, Icon('close'))
				),
				el('div', { className: 'np-modal-body np-compatibility-body' },
					el('p', { className: 'np-result-message' }, conflict.message || 'Another plugin is already managing this feature.'),
					el('div', { className: 'np-compatibility-summary' },
						conflict.plugin_name ? el('div', { className: 'np-result-row' }, el('span', null, 'Plugin'), el('strong', null, conflict.plugin_name)) : null,
						groups.length ? el('div', { className: 'np-result-row' }, el('span', null, 'Feature'), el('strong', null, groups.map(function (group) { return groupLabels[group] || group; }).join(', '))) : null
					),
					el('div', { className: 'np-compatibility-choices' }, choices.map(function (choice, index) {
						var style = choice.style === 'primary' ? 'np-button-primary' : 'np-button-ghost';
						return el('button', { key: choice.resolution || index, type: 'button', className: 'np-button ' + style, disabled: props.busy, onClick: function () { props.resolve(choice); } }, props.busy && index === 0 ? 'Applying…' : choice.label);
					}))
				),
				el('div', { className: 'np-modal-footer' },
					el('p', { className: 'np-compatibility-footer-note' }, 'Only one product should own each optimization layer.'),
					el('button', { className: 'np-button np-button-ghost', disabled: props.busy, onClick: props.cancel }, 'Later')
				)
			)
		);
	}

	function ConfirmModal(props) {
		var modal = props.modal || {};
		var variant = modal.variant || 'info';
		var selectable = !!modal.selectable;
		var initialRows = Array.isArray(modal.rows) ? modal.rows : [];
		var selectedState = useState(function () {
			var map = {};
			initialRows.forEach(function (row) {
				if (row && row.key) { map[row.key] = row.selected !== false; }
			});
			return map;
		});
		var selected = selectedState[0];
		var setSelected = selectedState[1];
		var dialogRef = useModalLifecycle(props.cancel);
		var selectedKeys = Object.keys(selected).filter(function (key) { return !!selected[key]; });
		var selectedCount = selectedKeys.length;
		var confirmDisabled = selectable && selectedCount === 0;

		function toggleRow(key) {
			setSelected(function (current) {
				var next = Object.assign({}, current);
				next[key] = !next[key];
				return next;
			});
		}

		function selectAll(value) {
			setSelected(function () {
				var next = {};
				initialRows.forEach(function (row) {
					if (row && row.key) { next[row.key] = !!value; }
				});
				return next;
			});
		}

		return el('div', { className: 'np-modal-backdrop', onMouseDown: function (event) { if (event.target === event.currentTarget) { props.cancel(); } } },
			el('div', { className: 'np-modal np-confirm-modal is-' + variant, role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'np-confirm-modal-title', ref: dialogRef },
				el('div', { className: 'np-modal-head' },
					el('div', { className: 'np-result-heading' },
						el('span', { className: 'np-result-icon is-' + variant }, Icon(modal.icon || (variant === 'warning' || variant === 'danger' ? 'warning' : 'info'))),
						el('div', { className: 'np-modal-title-stack' },
							modal.kicker ? el('span', { className: 'np-kicker' }, modal.kicker) : null,
							el('h3', { id: 'np-confirm-modal-title' }, modal.title || 'Confirm action')
						)
					),
					el('button', { className: 'np-icon-button', onClick: props.cancel, 'aria-label': 'Close' }, Icon('close'))
				),
				el('div', { className: 'np-modal-body np-confirm-body' },
					el('p', { className: 'np-result-message' }, modal.message || 'Continue with this action?'),
					modal.summary ? el('div', { className: 'np-modal-summary' }, el('strong', null, modal.summary)) : null,
					selectable && initialRows.length ? el('div', { className: 'np-modal-select-bar' },
						el('button', { type: 'button', className: 'np-button np-button-ghost', onClick: function () { selectAll(true); } }, 'Select all'),
						el('button', { type: 'button', className: 'np-button np-button-ghost', onClick: function () { selectAll(false); } }, 'Clear'),
						el('span', null, selectedCount + ' selected')
					) : null,
					initialRows.length ? el('div', { className: 'np-result-rows np-confirm-rows' + (selectable ? ' is-selectable' : '') }, initialRows.map(function (row, index) {
						var key = row.key || ('row-' + index);
						if (selectable && row.key) {
							return el('label', { className: 'np-result-row np-confirm-check' + (selected[row.key] ? ' is-checked' : ''), key: key },
								el('input', { type: 'checkbox', checked: !!selected[row.key], onChange: function () { toggleRow(row.key); } }),
								el('span', { className: 'np-confirm-check-label' }, row.label),
								el('strong', null, row.value)
							);
						}
						return el('div', { className: 'np-result-row', key: key }, el('span', null, row.label), el('strong', null, row.value));
					})) : null,
					ModalNote(modal.note, variant === 'danger' ? 'danger' : (variant === 'warning' ? 'warning' : 'info'))
				),
				el('div', { className: 'np-modal-footer' },
					el('button', { className: 'np-button np-button-ghost', onClick: props.cancel }, modal.cancelText || 'Cancel'),
					el('button', {
						className: 'np-button ' + (variant === 'warning' || variant === 'danger' ? 'np-button-danger-solid' : 'np-button-primary'),
						disabled: confirmDisabled,
						onClick: function () {
							if (selectable) { props.confirm({ selected: selectedKeys }); }
							else { props.confirm(true); }
						}
					}, modal.confirmText || 'Continue')
				)
			)
		);
	}

	function ResultModal(props) {
		var result = formatActionResult(props.modal.type, props.modal.data || {});
		var rows = Array.isArray(result.rows) ? result.rows : [];
		var dialogRef = useModalLifecycle(props.close);
		return el('div', { className: 'np-modal-backdrop', onMouseDown: function (event) { if (event.target === event.currentTarget) { props.close(); } } },
			el('div', { className: 'np-modal np-result-modal is-' + (result.tone || 'success'), role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'np-result-modal-title', ref: dialogRef },
				el('div', { className: 'np-modal-head' },
					el('div', { className: 'np-result-heading' },
						el('span', { className: 'np-result-icon is-' + result.tone }, Icon(result.icon)),
						el('div', { className: 'np-modal-title-stack' },
							result.kicker ? el('span', { className: 'np-kicker' }, result.kicker) : null,
							el('h3', { id: 'np-result-modal-title' }, result.title)
						)
					),
					el('button', { className: 'np-icon-button', onClick: props.close, 'aria-label': 'Close' }, Icon('close'))
				),
				el('div', { className: 'np-modal-body np-result-body' },
					result.message ? el('p', { className: 'np-result-message' }, result.message) : null,
					rows.length ? el('div', { className: 'np-result-rows' }, rows.map(function (row, index) {
						return el('div', { className: 'np-result-row', key: index }, el('span', null, row.label), el('strong', null, row.value));
					})) : null,
					ModalNote(result.note, result.tone === 'danger' ? 'danger' : (result.tone === 'warning' ? 'warning' : 'info'))
				),
				el('div', { className: 'np-modal-footer' }, el('button', { className: 'np-button np-button-primary', onClick: props.close }, 'Done'))
			)
		);
	}


	function chooseConfigurationFile(runAction, notify) {
		var input = document.createElement('input');
		input.type = 'file'; input.accept = 'application/json,.json';
		input.onchange = function () {
			var file = input.files && input.files[0];
			if (!file) { return; }
			if (file.size > 1048576) { notify('The settings file must be smaller than 1 MB.', 'error'); return; }
			var reader = new FileReader();
			reader.onerror = function () { notify('NBH Performance could not read that settings file.', 'error'); };
			reader.onload = function () {
				try {
					var configuration = JSON.parse(String(reader.result || ''));
					if (!configuration || configuration.product !== 'NBH Performance') { throw new Error('invalid'); }
					runAction('config_import', { configuration: configuration });
				} catch (error) { notify('Select a valid NBH Performance settings export file.', 'error'); }
			};
			reader.readAsText(file);
		};
		input.click();
	}


	function formatActionResult(type, data) {
		var labels = databaseCleanupLabels();
		var result = { kicker: 'Update complete', title: 'Done', message: 'NBH Performance finished the requested task successfully.', icon: 'check', tone: 'success', rows: [], note: '' };

		if (type === 'repair_cache') {
			result.kicker = 'Cache maintenance';
			result.title = 'Page cache repaired';
			result.message = data.warmed ? 'NBH Performance repaired the cache setup and warmed the homepage, so fresh cache files can start building right away.' : 'NBH Performance repaired the cache setup, but the homepage warm-up did not finish. Please review the cache details below.';
			result.icon = 'wrench';
			result.rows.push({ label: 'Cached pages', value: String(data.cache && data.cache.files ? data.cache.files : 0) });
			result.rows.push({ label: 'Advanced cache file', value: data.bootstrap && data.bootstrap.dropin_installed ? 'Installed' : 'Missing' });
			result.rows.push({ label: 'Runtime status', value: data.runtime ? 'Ready' : 'Needs attention' });
			result.note = data.warmed ? 'New visits and preload jobs will keep building more cache automatically.' : 'If cached pages stay at zero, open the support report and review the cache health details.';
		} else if (type === 'purge_cache') {
			result.kicker = 'Cache maintenance';
			result.title = 'Page cache cleared';
			result.message = 'NBH Performance removed the saved page cache. Fresh cache files will be created automatically as visitors browse the site or preload runs.';
			result.icon = 'trash';
			result.note = 'The first uncached page load may be a little slower while the cache is being rebuilt.';
		} else if (type === 'purge_edge') {
			result.kicker = 'CDN and edge cache';
			result.title = 'Edge cache cleared';
			result.message = 'Your connected CDN or edge service accepted the purge request.';
			result.icon = 'globe';
			if (data.provider) { result.rows.push({ label: 'Provider', value: String(data.provider) }); }
			result.note = 'It can take a short time for every edge location to refresh.';
		} else if (type === 'database_preview') {
			result.kicker = 'Preview only';
			result.title = 'Database cleanup preview';
			result.message = 'Nothing was deleted. These are the temporary or unnecessary WordPress items NBH Performance can safely clean.';
			result.icon = 'eye';
			result.tone = 'info';
			Object.keys(labels).forEach(function (key) {
				var count = Number((data.preview || {})[key] || 0);
				if (count > 0) { result.rows.push({ label: labels[key], value: String(count) }); }
			});
			if (!result.rows.length) { result.rows.push({ label: 'Cleanable items', value: '0' }); }
			if (data.preview && data.preview.total_rows) { result.rows.push({ label: 'Total cleanable rows', value: String(Number(data.preview.total_rows || 0)) }); }
			if (data.latest_backup_usable) { result.rows.push({ label: 'Verified backup', value: 'Ready' }); }
			result.note = 'Cleanup always creates and verifies a private SQL backup before removing anything.';
		} else if (type === 'database_backup') {
			var backup = data.backup || data || {};
			result.kicker = 'Safety backup';
			result.title = 'Verified database backup ready';
			result.message = 'NBH Performance created a private SQL backup and verified the artifact checksum before marking it usable.';
			result.icon = 'backup';
			result.rows.push({ label: 'Status', value: backup.status === 'verified' ? 'Verified' : String(backup.status || 'Ready') });
			if (backup.tables) { result.rows.push({ label: 'Tables backed up', value: String(Number(backup.tables || 0)) }); }
			if (backup.size_bytes) { result.rows.push({ label: 'Backup size', value: formatBytes(Number(backup.size_bytes || 0)) }); }
			result.note = 'This backup is stored in the private NBH Performance vault and is required before cleanup or table optimization.';
		} else if (type === 'database_cleanup') {
			result.kicker = 'Database cleanup';
			result.title = data.repeat_required ? 'Cleanup paused safely' : 'Database cleanup finished';
			result.message = data.message ? data.message : 'NBH Performance removed the selected clutter and kept protected WordPress data safe.';
			result.icon = 'broom';
			result.tone = data.repeat_required ? 'warning' : 'success';
			Object.keys(data.deleted || {}).forEach(function (key) {
				if (labels[key]) { result.rows.push({ label: labels[key] + ' removed', value: String(Number(data.deleted[key] || 0)) }); }
			});
			if (data.rounds) { result.rows.push({ label: 'Safe batches', value: String(Number(data.rounds || 0)) }); }
			if (data.backup) {
				result.rows.push({ label: 'Safety backup', value: data.backup.status === 'verified' ? 'Created and verified' : 'Verified' });
				if (data.backup.tables) { result.rows.push({ label: 'Tables backed up', value: String(Number(data.backup.tables || 0)) }); }
				if (data.backup.size_bytes) { result.rows.push({ label: 'Backup size', value: formatBytes(Number(data.backup.size_bytes || 0)) }); }
			}
			result.note = data.repeat_required
				? 'A verified private backup already exists. Run cleanup again to continue with remaining clutter.'
				: 'NBH Performance created and verified a private database backup before making these changes.';
		} else if (type === 'database_optimize') {
			var tables = Object.keys(data.tables || {});
			result.kicker = 'Database maintenance';
			result.title = 'Database tables optimized';
			result.message = 'NBH Performance optimized your WordPress-related database tables successfully.';
			result.icon = 'database';
			result.rows.push({ label: 'Tables processed', value: String(tables.length) });
			if (data.backup) {
				result.rows.push({ label: 'Safety backup', value: data.backup.status === 'verified' ? 'Created and verified' : 'Verified' });
				if (data.backup.size_bytes) { result.rows.push({ label: 'Backup size', value: formatBytes(Number(data.backup.size_bytes || 0)) }); }
			}
			result.note = 'Only WordPress-related tables were included, and a verified private backup was created first.';
		} else if (type === 'images_status') {
			result.kicker = 'Image summary';
			result.title = 'Image library checked';
			result.message = 'NBH Performance checked eligible media, generated formats and available processors.';
			result.icon = 'convert';
			result.tone = 'info';
			result.rows.push({ label: 'Images found', value: String(Number(data.total_eligible || 0)) });
			result.rows.push({ label: 'Images optimized', value: String(Number(data.converted || 0)) });
			result.rows.push({ label: 'Still waiting', value: String(Number(data.remaining || 0)) });
			result.rows.push({ label: 'Progress', value: String(Number(data.progress_percent || 0)) + '%' });
			result.rows.push({ label: 'WebP', value: data.webp_enabled ? 'On' : 'Off' });
			result.rows.push({ label: 'AVIF', value: data.avif_enabled ? 'On' : 'Off' });
			result.rows.push({ label: 'Serving', value: data.serving_active ? 'Active' : 'Paused' });
			var caps = data.capabilities || {};
			var available = Object.keys(caps).filter(function (key) { return !!caps[key]; }).join(', ');
			result.rows.push({ label: 'Processors available', value: available || 'WordPress fallback only' });
			if (data.offload_provider) { result.rows.push({ label: 'Offload plugin', value: String(data.offload_provider) }); }
			result.note = 'Original JPEG and PNG files always stay safe.';
		} else if (type === 'images_convert') {
			result.kicker = 'Image optimization';
			result.title = data.mode === 'rebuild' || data.force ? 'Image rebuild finished' : 'Image optimization finished';
			result.message = data.mode === 'rebuild' || data.force ? 'NBH Performance rebuilt the next batch of optimized image files using your current settings.' : 'NBH Performance processed the next batch of images and kept every original source file safe.';
			result.icon = 'convert';
			result.tone = Number(data.failed || 0) > 0 ? 'warning' : 'success';
			result.rows.push({ label: 'Images processed', value: String(Number(data.processed || 0)) });
			result.rows.push({ label: 'Optimized or reused', value: String(Number(data.converted || 0)) });
			result.rows.push({ label: 'Skipped', value: String(Number(data.skipped || 0)) });
			result.rows.push({ label: 'Failed', value: String(Number(data.failed || 0)) });
			result.rows.push({ label: 'Still waiting', value: String(Number(data.remaining || 0)) });
			if (data.progress_percent !== undefined) { result.rows.push({ label: 'Library progress', value: String(Number(data.progress_percent || 0)) + '%' }); }
			result.note = Number(data.remaining || 0) > 0 ? 'Run Optimize all remaining or start a background batch to continue.' : 'All currently eligible images have been processed.';
		} else if (type === 'config_snapshot') {
			result.kicker = 'Settings backup';
			result.title = 'Settings backup created';
			result.message = 'NBH Performance saved a backup of your current non-secret settings so you can restore them later if needed.';
			result.icon = 'backup';
			result.note = 'Create another backup after major setting changes.';
		} else if (type === 'config_restore') {
			result.kicker = 'Settings restore';
			result.title = 'Settings restored';
			result.message = 'NBH Performance restored your latest saved settings backup.';
			result.icon = 'refresh';
			result.note = 'It is a good idea to review the dashboard and clear cache after a major restore.';
		} else if (type === 'config_import') {
			result.kicker = 'Settings import';
			result.title = 'Settings imported';
			result.message = 'NBH Performance checked the file and imported the settings successfully.';
			result.icon = 'upload';
			result.rows.push({ label: 'Features imported', value: String(Number(data.features_imported || 0)) });
			result.rows.push({ label: 'Settings imported', value: String(Number(data.settings_imported || 0)) });
			result.note = 'A recovery backup of your previous settings was created automatically.';
		} else if (type === 'config_reset') {
			result.kicker = 'Settings reset';
			result.title = 'Safe defaults restored';
			result.message = 'NBH Performance reset its performance settings back to the recommended safe starting point.';
			result.icon = 'undo';
			result.rows.push({ label: 'Features reset', value: String(Number(data.features_reset || 0)) });
			result.rows.push({ label: 'Settings reset', value: String(Number(data.settings_reset || 0)) });
			result.note = 'Your license, site content and latest recovery backup were kept.';
		} else if (type === 'diagnostics') {
			result.kicker = 'Site readiness';
			result.title = 'Readiness check finished';
			result.message = 'NBH Performance checked your hosting environment and important setup requirements.';
			result.icon = 'shield';
			if (data.score !== undefined) { result.rows.push({ label: 'Readiness score', value: String(data.score) + '%' }); }
			result.note = 'Review the detailed recommendations in the dashboard for the next steps.';
		} else if (type === 'performance_assessment') {
			result.kicker = 'Performance review';
			result.title = '90+ readiness check finished';
			result.message = 'NBH Performance reviewed your setup and highlighted the safest path toward stronger Core Web Vitals.';
			result.icon = 'chart';
			if (data.score !== undefined) { result.rows.push({ label: 'Readiness score', value: String(data.score) + '%' }); }
			result.note = 'Use Smart Optimize or the recommended settings to improve the score step by step.';
		} else if (type === 'performance_apply') {
			result.kicker = 'Automatic optimization';
			result.title = 'Balanced optimization applied';
			result.message = 'NBH Performance applied a balanced optimization plan while keeping compatibility protections in place.';
			result.icon = 'magic';
			result.note = 'Review your site on the frontend and run a readiness check if you want to measure the impact.';
		} else if (type === 'smart_optimize_start') {
			var startedRun = data.run || data;
			var startedPlan = data.plan || {};
			result.kicker = 'Smart Optimize';
			result.title = 'Smart Optimize started';
			result.message = 'NBH Performance is applying the plan, rebuilding cache, validating important pages, and queueing homepage lab proof.';
			result.icon = 'magic';
			if (startedRun.mode || data.mode) { result.rows.push({ label: 'Mode', value: humanizeStatus(startedRun.mode || data.mode) }); }
			if (startedRun.updated_features) { result.rows.push({ label: 'Features applied', value: String((startedRun.updated_features || []).length) }); }
			if (startedPlan.site_type) { result.rows.push({ label: 'Site type', value: humanizeStatus(startedPlan.site_type) }); }
			if (startedRun.transaction_id || data.transaction_id) { result.rows.push({ label: 'Recovery ID', value: String(startedRun.transaction_id || data.transaction_id).slice(0, 12) + '…' }); }
			result.note = 'Watch step progress on Overview. Rollback stays available if validation fails.';
		} else if (type === 'smart_optimize_rollback') {
			result.kicker = 'Smart Optimize';
			result.title = 'Changes rolled back';
			result.message = 'NBH Performance restored the saved settings from before the latest Smart Optimize run.';
			result.icon = 'undo';
			result.note = 'Cache will be refreshed again so the site can rebuild with the restored settings.';
		} else if (type === 'browser_engine_test') {
			result.kicker = 'PHP CSS engine';
			result.title = data.healthy ? 'PHP engine is ready' : 'PHP engine needs attention';
			result.message = data.healthy ? 'The built-in PHP engine is available. It uses static safeguards; it does not perform browser screenshot comparisons.' : 'Enable PHP DOM and restore the bundled CSS parser.';
			result.icon = data.healthy ? 'check' : 'warning';
			result.tone = data.healthy ? 'success' : 'warning';
			result.rows.push({ label: 'Configured', value: data.configured ? 'Yes' : 'No' });
			result.rows.push({ label: 'Protocol', value: String(Number(data.protocol || 0)) });
			result.rows.push({ label: 'Capabilities', value: Array.isArray(data.capabilities) && data.capabilities.length ? data.capabilities.join(', ') : 'Not reported' });
			if (data.error) { result.note = String(data.error); }
		} else if (type === 'browser_css_generate') {
			result.kicker = 'Background CSS queue';
			result.title = data.queued ? 'Homepage analysis queued' : 'Could not queue the analysis';
			result.message = data.queued ? 'PHP Critical CSS and Used CSS jobs were queued. No browser tab needs to remain open.' : 'CSS jobs could not be queued. Check the PHP engine and background queue.';
			result.icon = data.queued ? 'refresh' : 'warning';
			result.tone = data.queued ? 'success' : 'warning';
			if (data.url) { result.rows.push({ label: 'URL', value: String(data.url) }); }
			result.note = data.queued ? 'The jobs will run in the background.' : 'Check PHP DOM, the bundled CSS parser and queue settings.';
		} else if (type === 'priority_elements_status') {
			var priority = data.priority_elements || {};
			result.kicker = 'Priority learning';
			result.title = 'Browser learning status';
			result.message = 'NBH Performance is learning important above-the-fold resources, LCP images, critical fonts and useful external origins from privacy-safe browser samples.';
			result.icon = 'chart';
			result.tone = 'info';
			result.rows.push({ label: 'Learned URLs', value: String(Number(priority.urls || 0)) });
			result.rows.push({ label: 'Last report', value: priority.last_report_at ? new Date(Number(priority.last_report_at) * 1000).toLocaleString() : 'No reports yet' });
			result.rows.push({ label: 'Visual issues', value: String(Number(data.visual_failures || 0)) });
			result.rows.push({ label: 'PHP CSS engine', value: data.configured ? 'Configured' : 'Not configured' });
			result.note = 'More real user visits usually improve the quality of this learning data.';
		} else if (type === 'edge_webhook_test') {
			result.kicker = 'Edge connection';
			result.title = 'Signed webhook verified';
			result.message = 'The connected edge service accepted NBH Performance’s signed test request.';
			result.icon = 'globe';
			result.rows.push({ label: 'HTTP response', value: String(Number(data.http_code || 0)) });
		} else if (type === 'cache_tags_purge') {
			result.kicker = 'Targeted cache clear';
			result.title = 'Cache invalidation queued';
			result.message = 'NBH Performance queued a targeted cache clear across page cache, object cache and edge cache.';
			result.icon = 'trash';
			result.rows.push({ label: 'Job ID', value: String(data.job_id || 'Queued') });
			result.rows.push({ label: 'Tags', value: Array.isArray(data.tags) ? data.tags.join(', ') : 'site-wide' });
			result.note = 'This helps clear only the related cache instead of wiping everything.';
		} else if (type === 'ai_token_rotate') {
			result.kicker = 'Security update';
			result.title = 'New AI token created';
			result.message = 'Copy this token now. NBH Performance stores only a one-way hash and will not show the token again after you close this popup.';
			result.icon = 'lock';
			result.tone = 'warning';
			if (data.token) { result.rows.push({ label: 'New token', value: String(data.token) }); }
			result.rows.push({ label: 'Access scope', value: String(data.scope || 'read') });
			result.note = 'Store it in a secure password or secret manager. Rotate it right away if it is exposed.';
		} else if (type === 'jobs_run') {
			result.kicker = 'Background jobs';
			result.title = 'Background queue processed';
			result.message = 'NBH Performance ran the next group of background jobs.';
			result.icon = 'refresh';
			if (data.processed !== undefined) { result.rows.push({ label: 'Jobs processed', value: String(Number(data.processed || 0)) }); }
			if (data.remaining !== undefined) { result.rows.push({ label: 'Jobs remaining', value: String(Number(data.remaining || 0)) }); }
		} else if (type === 'jobs_pause') {
			result.kicker = 'Background jobs';
			result.title = 'Background jobs paused';
			result.message = 'NBH Performance paused the background queue. New jobs will wait until you resume processing.';
			result.icon = 'clock';
		} else if (type === 'jobs_resume') {
			result.kicker = 'Background jobs';
			result.title = 'Background jobs resumed';
			result.message = 'NBH Performance resumed the background queue.';
			result.icon = 'check';
		} else if (type === 'compatibility_matrix') {
			result.kicker = 'Compatibility check';
			result.title = 'Compatibility test finished';
			result.message = 'NBH Performance finished the compatibility matrix run.';
			result.icon = 'shield';
			if (data.score !== undefined) { result.rows.push({ label: 'Compatibility score', value: String(data.score) + '%' }); }
			result.note = 'Review detected issues or warnings in the compatibility area if any appear.';
		} else if (type === 'staging_validation') {
			result.kicker = 'Frontend validation';
			result.title = 'Frontend check finished';
			result.message = 'NBH Performance completed the frontend validation run.';
			result.icon = 'eye';
			if (data.checked !== undefined) { result.rows.push({ label: 'Pages checked', value: String(Number(data.checked || 0)) }); }
			if (data.failures !== undefined) { result.rows.push({ label: 'Issues found', value: String(Number(data.failures || 0)) }); }
			result.note = 'If issues were found, review the related feature changes before enabling stronger optimization.';
		} else if (type === 'preload_cache') {
			result.kicker = 'Cache preload';
			result.title = 'Preload started';
			result.message = 'NBH Performance started preloading important pages so the cache can be ready sooner for visitors.';
			result.icon = 'rocket';
			result.note = 'Large sites may continue warming more pages in the background.';
		} else if (type === 'create_cache') {
			result.kicker = 'Cache creation';
			result.title = 'Cache created';
			result.message = 'NBH Performance created fresh cache for the homepage and key pages.';
			result.icon = 'layers';
		} else if (type === 'support_report') {
			result.kicker = 'Support report';
			result.title = 'Support report downloaded';
			result.message = 'NBH Performance generated and downloaded a privacy-safe support report.';
			result.icon = 'download';
		} else if (type === 'support_bundle') {
			result.kicker = 'Support bundle';
			result.title = 'Support bundle created';
			result.message = 'NBH Performance packaged a privacy-redacted operations snapshot.';
			result.note = 'The download should open automatically in a new tab.';
			result.icon = 'backup';
		} else if (type === 'operations_logs_clear') {
			result.kicker = 'Operations';
			result.title = 'Operational logs cleared';
			result.message = 'Recent ops log entries were removed from the private vault.';
			result.icon = 'trash';
		} else if (type === 'smart_optimize_report') {
			result.kicker = 'Impact report';
			result.title = 'Impact report downloaded';
			result.message = 'NBH Performance generated and downloaded the latest performance impact report.';
			result.icon = 'download';
		}

		return result;
	}

	function CompatibilityIssue(item, index) {
		var actions = Array.isArray(item.actions) ? item.actions : [];
		return el('div', { className: 'np-conflict-item', key: item.id || index },
			Icon(item.severity === 'error' || item.severity === 'critical' ? 'warning' : 'shield', 'np-conflict-icon'),
			el('div', { className: 'np-conflict-content' },
				el('strong', null, item.title || item.plugin || 'Compatibility notice'),
				el('p', null, item.message || 'Review this integration.'),
				item.description ? el('small', null, item.description) : null,
				actions.length ? el('div', { className: 'np-conflict-actions' }, actions.map(function (action, actionIndex) {
					var confirmClass = action.confirm_title || action.confirm_message ? ' nbh-performance-confirm-action' : '';
					return el('a', {
						key: actionIndex,
						href: action.url || '#',
						className: 'np-button ' + (action.style === 'primary' ? 'np-button-primary' : 'np-button-ghost') + confirmClass,
						'data-confirm-title': action.confirm_title || '',
						'data-confirm-message': action.confirm_message || '',
						'data-confirm-label': action.label || 'Confirm'
					}, action.label || 'Review');
				})) : null
			)
		);
	}

	function PanelHeader(props) { return el('div', { className: 'np-panel-header' }, el('div', null, el('h3', null, props.title), el('p', null, props.description))); }
	function Metric(title, value, copy, icon) { return el('article', { className: 'np-metric-card' }, el('span', { className: 'np-metric-icon' }, Icon(icon)), el('div', null, el('small', null, title), el('strong', null, value), el('p', null, copy))); }
	function Tab(id, label, active, setActive, count) { return el('button', { id: 'np-tab-' + id, role: 'tab', 'aria-selected': active === id, 'aria-controls': 'np-tabpanel-' + id, tabIndex: active === id ? 0 : -1, className: 'np-tab' + (active === id ? ' is-active' : ''), onClick: function () { setActive(id); } }, label, el('span', null, count)); }
	function Switch(checked, disabled, change, label) { return el('label', { className: 'np-switch' + (disabled ? ' is-disabled' : '') }, el('input', { type: 'checkbox', checked: !!checked, disabled: disabled, 'aria-label': label || 'Toggle setting', onChange: function (event) { change(event.target.checked); } }), el('span', { className: 'np-switch-track' }, el('span', { className: 'np-switch-knob' }))); }
	function ActionButton(title, description, icon, loading, action) { return el('button', { className: 'np-action-card' + (loading ? ' is-working' : ''), disabled: loading, onClick: action }, el('div', { className: 'np-action-top' }, el('span', { className: 'np-action-icon' }, Icon(genericIconFor(icon))), el('div', { className: 'np-action-copy' }, el('strong', null, title), el('small', null, description))), loading ? el('div', { className: 'np-action-meta' }, el('span', { className: 'np-action-status is-running' }, el('span', { className: 'np-running-dot' }), 'Running')) : null, loading ? el('span', { className: 'np-action-progress' }, el('span', { style: { width: '66%' } })) : null, el('span', { className: 'np-action-end' }, loading ? el('span', { className: 'np-spinner' }) : Icon('arrow', 'np-action-arrow'), loading ? 'Working…' : 'Run')); }
	function OperationPanel(props) { return el('section', { className: 'np-panel np-operation-panel' }, el(PanelHeader, { title: props.title, description: props.description || 'Run a task when you need it.' }), el('div', { className: 'np-action-grid' }, props.actions)); }
	function EmptyState(icon, title, copy) { return el('div', { className: 'np-empty' }, el('span', { className: 'np-empty-icon' }, Icon(genericIconFor(icon))), el('strong', null, title), el('p', null, copy)); }
	function isExclusion(field) { return field.sanitize === 'exclusions' || field.sanitize === 'urls'; }
	function flattenSettings(sections) { var out = {}; Object.keys(sections || {}).forEach(function (section) { Object.keys(sections[section].fields || {}).forEach(function (key) { out[key] = sections[section].fields[key].value; }); }); return out; }
	function calculateDirty(data, drafts, originals) { var dirty = {}; if (!data) { return dirty; } Object.keys(data.settings || {}).forEach(function (section) { dirty[section] = Object.keys(data.settings[section].fields || {}).some(function (key) { return String(drafts[key] === undefined ? '' : drafts[key]) !== String(originals[key] === undefined ? '' : originals[key]); }); }); return dirty; }
	function enabledIn(group) { return Object.keys(group.features || {}).filter(function (key) { return group.features[key].enabled; }).length; }
	function countEnabled(groups) { return Object.keys(groups || {}).reduce(function (total, key) { return total + enabledIn(groups[key]); }, 0); }
	function countTotal(groups) { return Object.keys(groups || {}).reduce(function (total, key) { return total + Object.keys(groups[key].features || {}).length; }, 0); }
	function hasAgencyAccess(data) {
		return !!(data && data.license && data.license.is_agency);
	}

	function visibleNavItems(data) {
		return NAV_ITEMS.filter(function (item) {
			return item.id !== 'enterprise' || hasAgencyAccess(data);
		});
	}

	function navGroups(data) {
		var groups = [];
		visibleNavItems(data).forEach(function (item) {
			var group = groups.filter(function (candidate) { return candidate.label === item.group; })[0];
			if (!group) { group = { label: item.group, items: [] }; groups.push(group); }
			group.items.push(item);
		});
		return groups;
	}

	function sectionLabel(id) { var found = NAV_ITEMS.filter(function (item) { return item.id === id; })[0]; return found ? found.label : 'NBH Performance'; }
	function iconFor(id) { return { caching: 'layers', object_cache: 'database', optimization: 'sliders', media: 'image', images: 'convert', preload: 'rocket', cdn: 'globe', enterprise: 'building', database: 'database', script_manager: 'sliders', tools: 'wrench' }[id] || 'shield'; }
	function genericIconFor(name) {
		return {
			'shield-alt': 'shield', shield: 'shield', 'admin-generic': 'sliders', 'chart-line': 'chart', backup: 'backup',
			building: 'building', cart: 'cart', cloud: 'cloud', performance: 'pulse', trash: 'trash', download: 'download', upload: 'upload', undo: 'undo',
			visibility: 'eye', 'yes-alt': 'check', filter: 'filter', database: 'database', wrench: 'wrench', play: 'arrow', pause: 'clock',
			layers: 'layers', image: 'image', globe: 'globe', warning: 'warning', lock: 'lock'
		}[name] || name || 'shield';
	}
	function featureIconFor(option) {
		return {
			nbh_performance_css_minify_enabled: 'css',
			nbh_performance_js_minify_enabled: 'js',
			nbh_performance_defer_js_enabled: 'defer',
			nbh_performance_delay_js_enabled: 'clock',
			nbh_performance_script_manager_enabled: 'sliders',
			nbh_performance_critical_css_enabled: 'bolt',
			nbh_performance_unused_css_enabled: 'broom',
			nbh_performance_cache_enabled: 'layers',
			nbh_performance_mobile_cache_enabled: 'layers',
			nbh_performance_sitemap_preload_enabled: 'rocket',
			nbh_performance_instant_page: 'defer',
			nbh_performance_speculation_rules_enabled: 'magic',
			nbh_performance_resource_priority_enabled: 'bolt',
			nbh_performance_automatic_preconnect_enabled: 'globe',
			nbh_performance_visual_regression_guard_enabled: 'eye',
			nbh_performance_image_conversion_enabled: 'convert',
			nbh_performance_nextgen_image_serving_enabled: 'convert',
			nbh_performance_lazy_load_enabled: 'image',
			nbh_performance_responsive_images_enabled: 'image',
			nbh_performance_youtube_facade_enabled: 'image',
			nbh_performance_youtube_autoload_after_consent: 'image',
			nbh_performance_cdn_rewrite_enabled: 'globe',
			nbh_performance_edge_auto_purge_enabled: 'globe',
			nbh_performance_edge_cache_headers_enabled: 'globe',
			nbh_performance_resource_hints_enabled: 'globe',
			nbh_performance_database_cleanup_enabled: 'database',
			nbh_performance_chat_facade_enabled: 'clock',
			nbh_performance_consent_mode_enabled: 'shield',
			nbh_performance_font_preload_enabled: 'rocket',
			nbh_performance_rum_enabled: 'shield',
			nbh_performance_heartbeat_control_enabled: 'clock'
		}[option] || 'shield';
	}
	function formatBytes(bytes) { bytes = Number(bytes || 0); if (bytes < 1024) { return bytes + ' B'; } if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; } if (bytes < 1073741824) { return (bytes / 1048576).toFixed(1) + ' MB'; } return (bytes / 1073741824).toFixed(1) + ' GB'; }
	function readHash() { var hash = String(window.location.hash || '').replace('#', ''); return NAV_ITEMS.some(function (item) { return !item.external && item.id === hash; }) ? hash : 'overview'; }
	function downloadJson(filename, data) { var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }); var url = URL.createObjectURL(blob); var link = document.createElement('a'); link.href = url; link.download = filename; document.body.appendChild(link); link.click(); document.body.removeChild(link); URL.revokeObjectURL(url); }

	wp.element.render(el(App), root);
}(window.wp));
