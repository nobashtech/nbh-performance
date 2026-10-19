(function () {
	'use strict';
	if (!window.fetch || !window.requestIdleCallback) return;
	var seen = Object.create(null);
	var timer = 0;
	function eligible(link) {
		if (!link || !link.href || link.origin !== location.origin) return false;
		if (link.target || link.hasAttribute('download') || link.rel.indexOf('nofollow') !== -1) return false;
		if (link.pathname.indexOf('/wp-admin') === 0 || link.pathname.indexOf('/wp-login') === 0) return false;
		return !seen[link.href] && link.href !== location.href;
	}
	function preload(link) {
		if (!eligible(link)) return;
		seen[link.href] = true;
		var node = document.createElement('link');
		node.rel = 'prefetch';
		node.href = link.href;
		document.head.appendChild(node);
	}
	document.addEventListener('mouseover', function (event) {
		var link = event.target.closest && event.target.closest('a');
		clearTimeout(timer);
		timer = setTimeout(function () { preload(link); }, 80);
	}, {passive: true});
	document.addEventListener('touchstart', function (event) {
		var link = event.target.closest && event.target.closest('a');
		preload(link);
	}, {passive: true});
}());
