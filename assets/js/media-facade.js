(function () {
	'use strict';

	function hasConsent(node) {
		var cookieName = node.dataset.nbhPerformanceConsentCookie;
		if (!cookieName) {
			return true;
		}
		return document.cookie.split(';').some(function (part) {
			return part.trim() === cookieName + '=1';
		});
	}

	function activateDelayedScripts() {
		document.querySelectorAll('script[data-nbh-performance-delay="1"][data-nbh-performance-src]').forEach(function (node) {
			if (node.dataset.nbhPerformanceLoaded === '1' || !hasConsent(node)) {
				return;
			}
			var script = document.createElement('script');
			Array.prototype.forEach.call(node.attributes, function (attribute) {
				if (attribute.name.indexOf('data-nbh-performance-') !== 0 && attribute.name !== 'type') {
					script.setAttribute(attribute.name, attribute.value);
				}
			});
			script.src = node.dataset.nbhPerformanceSrc;
			node.dataset.nbhPerformanceLoaded = '1';
			node.parentNode.insertBefore(script, node.nextSibling);
		});
	}

	function loadFacade(facade) {
		var source = facade.dataset.nbhPerformanceSrc;
		if (!source || facade.dataset.nbhPerformanceLoaded === '1') {
			return;
		}
		var iframe = document.createElement('iframe');
		iframe.src = source;
		iframe.loading = 'eager';
		iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
		iframe.allowFullscreen = true;
		iframe.referrerPolicy = 'strict-origin-when-cross-origin';
		iframe.title = 'YouTube video player';
		facade.dataset.nbhPerformanceLoaded = '1';
		facade.replaceChildren(iframe);
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.nbh-performance-media-facade__button');
		if (button) {
			loadFacade(button.closest('.nbh-performance-media-facade'));
			return;
		}
		activateDelayedScripts();
	}, { passive: true });

	['scroll', 'keydown', 'touchstart', 'pointerdown'].forEach(function (eventName) {
		window.addEventListener(eventName, activateDelayedScripts, { once: true, passive: true });
	});

	window.addEventListener('nbh-performance:consent-updated', activateDelayedScripts);
}());
