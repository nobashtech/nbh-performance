(function () {
	'use strict';
	var config = window.nbhPerformanceDelay || {};
	var activated = false;
	var captured = null;
	var triggerEvents = Array.isArray(config.events) && config.events.length ? config.events : ['pointerdown', 'touchstart', 'keydown', 'mousemove', 'scroll'];

	function rememberEvent(event) {
		if (!captured && event && event.type !== 'mousemove' && event.type !== 'scroll') {
			captured = {type: event.type, target: event.target};
		}
		activate();
	}

	function copyAttributes(placeholder, script) {
		Array.prototype.forEach.call(placeholder.attributes, function (attribute) {
			if (attribute.name.indexOf('data-nbh-performance-') === 0 || ['type', 'src', 'async', 'defer', 'nomodule'].indexOf(attribute.name) !== -1) {
				return;
			}
			script.setAttribute(attribute.name, attribute.value);
		});
		var originalType = placeholder.getAttribute('data-nbh-performance-original-type');
		if (originalType) { script.setAttribute('type', originalType); }
		if (placeholder.getAttribute('data-nbh-performance-original-nomodule') === '1') { script.noModule = true; }
		if (placeholder.getAttribute('data-nbh-performance-original-defer') === '1') { script.defer = true; }
	}

	function loadOne(placeholder) {
		return new Promise(function (resolve) {
			if (!placeholder.parentNode) { resolve(); return; }
			var script = document.createElement('script');
			copyAttributes(placeholder, script);
			script.src = placeholder.getAttribute('data-nbh-performance-src');
			script.onload = resolve;
			script.onerror = resolve;
			placeholder.parentNode.replaceChild(script, placeholder);
		});
	}

	function loadAll() {
		var delayed = Array.prototype.slice.call(document.querySelectorAll('script[data-nbh-performance-delay="1"][data-nbh-performance-src]'));
		var serial = Promise.resolve();
		var parallel = [];
		delayed.forEach(function (placeholder) {
			if (placeholder.getAttribute('data-nbh-performance-original-async') === '1') {
				parallel.push(loadOne(placeholder));
			} else {
				serial = serial.then(function () { return loadOne(placeholder); });
			}
		});
		return Promise.all([serial].concat(parallel));
	}

	function callListener(listener, target, event) {
		try {
			if (typeof listener === 'function') { listener.call(target, event); }
			else if (listener && typeof listener.handleEvent === 'function') { listener.handleEvent(event); }
		} catch (error) { window.setTimeout(function () { throw error; }, 0); }
	}

	function installLifecycleBridge() {
		var originalDocumentAdd = document.addEventListener;
		var originalWindowAdd = window.addEventListener;
		document.addEventListener = function (type, listener, options) {
			if (type === 'DOMContentLoaded' && document.readyState !== 'loading') {
				window.setTimeout(function () { callListener(listener, document, new Event('DOMContentLoaded')); }, 0);
				return;
			}
			return originalDocumentAdd.call(document, type, listener, options);
		};
		window.addEventListener = function (type, listener, options) {
			if ((type === 'load' || type === 'pageshow') && document.readyState === 'complete') {
				window.setTimeout(function () { callListener(listener, window, new Event(type)); }, 0);
				return;
			}
			return originalWindowAdd.call(window, type, listener, options);
		};
		return function () { document.addEventListener = originalDocumentAdd; window.addEventListener = originalWindowAdd; };
	}

	function replayInteraction() {
		if (captured && captured.target && captured.target.dispatchEvent) {
			try { captured.target.dispatchEvent(new Event(captured.type, {bubbles: true, cancelable: true})); } catch (ignoreReplay) {}
		}
	}

	function activate() {
		if (activated) { return; }
		activated = true;
		triggerEvents.forEach(function (eventName) { window.removeEventListener(eventName, rememberEvent, true); });
		document.dispatchEvent(new CustomEvent('nbh-performance:delay-start'));
		var restoreLifecycle = installLifecycleBridge();
		loadAll().then(function () {
			window.setTimeout(function () { restoreLifecycle(); replayInteraction(); document.dispatchEvent(new CustomEvent('nbh-performance:delay-complete')); }, 0);
		});
	}

	triggerEvents.forEach(function (eventName) {
		window.addEventListener(eventName, rememberEvent, {passive: true, once: true, capture: true});
	});
	window.setTimeout(activate, Math.max(1000, Number(config.timeout || 8000)));
}());
