(function () {
	'use strict';
	function load(element) {
		var url = element.getAttribute('data-nbh-performance-bg');
		if (!url) { return; }
		element.style.backgroundImage = 'url("' + String(url).replace(/"/g, '\\"') + '")';
		element.removeAttribute('data-nbh-performance-bg');
		element.classList.remove('nbh-performance-lazy-bg');
	}
	var elements = Array.prototype.slice.call(document.querySelectorAll('[data-nbh-performance-bg]'));
	if (!('IntersectionObserver' in window)) { elements.forEach(load); return; }
	var observer = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) { if (entry.isIntersecting) { load(entry.target); observer.unobserve(entry.target); } });
	}, {rootMargin: '300px 0px'});
	elements.forEach(function (element) { observer.observe(element); });
}());
