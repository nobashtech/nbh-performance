(function (wp) {
	'use strict';

	var domain = 'nbh-performance';
	var translate = wp && wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (value) { return value; };

	function tr(value) {
		return typeof value === 'string' ? translate(value, domain) : value;
	}

	function translateTextNode(node) {
		var original = node && typeof node.nodeValue === 'string' ? node.nodeValue : '';
		if (!original || !original.trim()) { return; }
		var leading = original.match(/^\s*/)[0];
		var trailing = original.match(/\s*$/)[0];
		var core = original.trim();
		var translated = tr(core);
		if (translated !== core) {
			node.nodeValue = leading + translated + trailing;
		}
	}

	function localizeTree(root) {
		if (!root || !document.createTreeWalker) { return; }
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
			acceptNode: function (node) {
				var parent = node.parentNode;
				if (!parent || /^(SCRIPT|STYLE|CODE|PRE)$/i.test(parent.nodeName)) { return NodeFilter.FILTER_REJECT; }
				return node.nodeValue && node.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
			}
		});
		var nodes = [];
		while (walker.nextNode()) { nodes.push(walker.currentNode); }
		nodes.forEach(translateTextNode);

		Array.prototype.forEach.call(root.querySelectorAll('[placeholder], [aria-label], [title], [alt]'), function (element) {
			['placeholder', 'aria-label', 'title', 'alt'].forEach(function (attribute) {
				if (!element.hasAttribute(attribute)) { return; }
				var value = element.getAttribute(attribute);
				var translated = tr(value);
				if (translated !== value) { element.setAttribute(attribute, translated); }
			});
		});
	}

	window.NBHPerformanceI18n = {
		tr: tr,
		localizeTree: localizeTree
	};
}(window.wp || {}));
