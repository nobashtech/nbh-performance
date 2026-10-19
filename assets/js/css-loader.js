(function () {
	'use strict';
	var links = document.querySelectorAll('link[data-nbh-performance-async-style="1"]');
	Array.prototype.forEach.call(links, function (link) {
		var stylesheet = document.createElement('link');
		stylesheet.rel = 'stylesheet';
		stylesheet.href = link.href;
		stylesheet.media = link.getAttribute('data-media') || 'all';
		stylesheet.setAttribute('data-nbh-performance-promoted', '1');
		link.parentNode.insertBefore(stylesheet, link.nextSibling);
	});
}());
