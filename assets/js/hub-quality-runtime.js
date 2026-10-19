(function (wp) {
	'use strict';
	var translate = wp && wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (value) { return value; };
	function tr(value) { return translate(value, 'nbh-performance'); }

	var lastFocused = null;
	var activeDialog = null;

	function focusable(container) {
		return Array.prototype.slice.call(container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (node) {
			return !node.hidden && node.offsetParent !== null;
		});
	}

	function activateDialog(dialog) {
		if (!dialog || dialog === activeDialog) { return; }
		lastFocused = document.activeElement;
		activeDialog = dialog;
		dialog.setAttribute('tabindex', '-1');
		window.setTimeout(function () {
			var items = focusable(dialog);
			(items[0] || dialog).focus();
		}, 0);
	}

	function releaseDialog() {
		if (!activeDialog) { return; }
		activeDialog = null;
		if (lastFocused && document.contains(lastFocused) && typeof lastFocused.focus === 'function') {
			lastFocused.focus();
		}
		lastFocused = null;
	}

	function inspectDialogs() {
		var dialog = document.querySelector('#nbh-performance-hub-root [role="dialog"][aria-modal="true"]');
		if (dialog) { activateDialog(dialog); } else { releaseDialog(); }
	}

	document.addEventListener('keydown', function (event) {
		if (activeDialog) {
			if (event.key === 'Escape') {
				var close = activeDialog.querySelector('[aria-label="Close"], [data-dialog-close]');
				if (close && !close.disabled) { event.preventDefault(); close.click(); }
				return;
			}
			if (event.key === 'Tab') {
				var items = focusable(activeDialog);
				if (!items.length) { event.preventDefault(); activeDialog.focus(); return; }
				var first = items[0], last = items[items.length - 1];
				if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
				else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
			}
			return;
		}

		var tab = event.target && event.target.closest ? event.target.closest('[role="tab"]') : null;
		if (!tab || ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) { return; }
		var tabs = Array.prototype.slice.call(tab.parentNode.querySelectorAll('[role="tab"]:not([disabled])'));
		var index = tabs.indexOf(tab);
		if (index < 0 || !tabs.length) { return; }
		event.preventDefault();
		if (event.key === 'Home') { index = 0; }
		else if (event.key === 'End') { index = tabs.length - 1; }
		else { index = (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length; }
		tabs[index].focus();
		tabs[index].click();
	});

	function renderBootError(root, strings) {
		if (!root) { return; }
		strings = strings || {};
		while (root.firstChild) { root.removeChild(root.firstChild); }
		var box = document.createElement('div');
		box.className = 'np-boot-error';
		box.setAttribute('role', 'alert');
		var title = document.createElement('strong');
		title.textContent = strings.title || tr('NBH Performance could not start.');
		var copy = document.createElement('p');
		copy.textContent = strings.message || tr('The WordPress React or REST client dependency is unavailable. Reload the page, disable admin asset optimizers, and check the browser console.');
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'button button-primary';
		button.textContent = strings.reload || tr('Reload dashboard');
		button.addEventListener('click', function () { window.location.reload(); });
		box.appendChild(title); box.appendChild(copy); box.appendChild(button); root.appendChild(box);
	}

	var observer = new MutationObserver(inspectDialogs);
	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('nbh-performance-hub-root');
		if (root) { observer.observe(root, { childList: true, subtree: true }); }
		inspectDialogs();
	});

	window.NBHPerformanceQualityRuntime = {
		renderBootError: renderBootError,
		inspectDialogs: inspectDialogs
	};
}(window.wp || {}));
