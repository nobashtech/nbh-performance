(function () {
	'use strict';

	function closeModal(modal) {
		if (modal && modal.parentNode) {
			modal.parentNode.removeChild(modal);
		}
		if (!document.querySelector('.nbh-performance-confirm-backdrop, .nbh-performance-issue-backdrop')) {
			document.body.classList.remove('nbh-performance-modal-open');
		}
	}

	function addEscapeHandler(backdrop) {
		function escape(event) {
			if (event.key === 'Escape') {
				document.removeEventListener('keydown', escape);
				closeModal(backdrop);
			}
		}
		document.addEventListener('keydown', escape);
	}

	function firstSentence(value) {
		var text = String(value || '').replace(/\s+/g, ' ').trim();
		if (!text) { return ''; }
		var match = text.match(/^(.+?[.!?।])(?:\s+|$)/);
		var shortText = match ? match[1] : text;
		if (shortText.length <= 180) { return shortText; }
		return shortText.slice(0, 177).replace(/\s+\S*$/, '') + '…';
	}

	function openConfirmModal(link) {
		var backdrop = document.createElement('div');
		backdrop.className = 'nbh-performance-confirm-backdrop';
		backdrop.setAttribute('role', 'presentation');

		var dialog = document.createElement('div');
		dialog.className = 'nbh-performance-confirm-dialog';
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-labelledby', 'nbh-performance-confirm-title');

		var title = document.createElement('h2');
		title.id = 'nbh-performance-confirm-title';
		title.textContent = link.getAttribute('data-confirm-title') || 'Confirm this change?';

		var message = document.createElement('p');
		message.textContent = firstSentence(link.getAttribute('data-confirm-message') || 'NBH Performance will apply this change.');

		var actions = document.createElement('div');
		actions.className = 'nbh-performance-confirm-dialog__actions';

		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'button button-secondary';
		cancel.textContent = 'Cancel';

		var confirm = document.createElement('button');
		confirm.type = 'button';
		confirm.className = 'button button-primary';
		confirm.textContent = link.getAttribute('data-confirm-label') || 'Continue';

		actions.appendChild(cancel);
		actions.appendChild(confirm);
		dialog.appendChild(title);
		dialog.appendChild(message);
		dialog.appendChild(actions);
		backdrop.appendChild(dialog);
		document.body.appendChild(backdrop);
		if (window.NBHPerformanceI18n) { window.NBHPerformanceI18n.localizeTree(backdrop); }
		document.body.classList.add('nbh-performance-modal-open');

		cancel.addEventListener('click', function () { closeModal(backdrop); });
		backdrop.addEventListener('click', function (event) { if (event.target === backdrop) { closeModal(backdrop); } });
		confirm.addEventListener('click', function () {
			confirm.disabled = true;
			cancel.disabled = true;
			confirm.textContent = 'Applying…';
			window.location.href = link.href;
		});
		addEscapeHandler(backdrop);
		cancel.focus();
	}

	function textOf(notice, selector, fallback) {
		var element = notice.querySelector(selector);
		return element && element.textContent ? element.textContent.trim() : fallback;
	}

	function cloneIssueActions(notice, container, close) {
		var sourceActions = notice.querySelectorAll('.nbh-performance-notice__actions a');
		Array.prototype.forEach.call(sourceActions, function (source, index) {
			var action = source.cloneNode(true);
			action.classList.add('nbh-performance-issue-dialog__action');
			if (index === 0) {
				action.classList.add('is-recommended');
			}
			action.addEventListener('click', function () {
				if (!action.classList.contains('nbh-performance-confirm-action')) {
					close();
				}
			});
			container.appendChild(action);
		});
	}

	function openIssueModal(notice) {
		if (!notice || document.querySelector('.nbh-performance-issue-backdrop')) { return; }

		var issueId = notice.getAttribute('data-nbh-issue-id') || 'compatibility-issue';
		var titleText = textOf(notice, '.nbh-performance-notice__title', 'Compatibility issue');
		var messageText = firstSentence(textOf(notice, '.nbh-performance-notice__message', 'Another plugin is managing the same feature.'));
		var detailText = firstSentence(textOf(notice, '.nbh-performance-notice__detail', 'Choose which plugin should manage this feature.'));

		var backdrop = document.createElement('div');
		backdrop.className = 'nbh-performance-issue-backdrop';
		backdrop.setAttribute('role', 'presentation');

		var dialog = document.createElement('div');
		dialog.className = 'nbh-performance-issue-dialog';
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-labelledby', 'nbh-performance-issue-title-' + issueId);

		var head = document.createElement('div');
		head.className = 'nbh-performance-issue-dialog__head';
		var icon = document.createElement('span');
		icon.className = 'dashicons dashicons-shield-alt nbh-performance-issue-dialog__icon';
		icon.setAttribute('aria-hidden', 'true');
		var title = document.createElement('h2');
		title.id = 'nbh-performance-issue-title-' + issueId;
		title.textContent = titleText;
		var closeButton = document.createElement('button');
		closeButton.type = 'button';
		closeButton.className = 'nbh-performance-issue-dialog__close';
		closeButton.setAttribute('aria-label', 'Close');
		closeButton.innerHTML = '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>';
		head.appendChild(icon);
		head.appendChild(title);
		head.appendChild(closeButton);

		var body = document.createElement('div');
		body.className = 'nbh-performance-issue-dialog__body';
		var message = document.createElement('p');
		message.className = 'nbh-performance-issue-dialog__message';
		message.textContent = messageText;
		body.appendChild(message);
		if (detailText && detailText !== messageText) {
			var detail = document.createElement('p');
			detail.className = 'nbh-performance-issue-dialog__detail';
			detail.textContent = detailText;
			body.appendChild(detail);
		}
		var actions = document.createElement('div');
		actions.className = 'nbh-performance-issue-dialog__actions';
		cloneIssueActions(notice, actions, function () { closeModal(backdrop); });
		body.appendChild(actions);

		var footer = document.createElement('div');
		footer.className = 'nbh-performance-issue-dialog__footer';
		var later = document.createElement('button');
		later.type = 'button';
		later.className = 'button button-secondary';
		later.textContent = 'Later';
		footer.appendChild(later);

		dialog.appendChild(head);
		dialog.appendChild(body);
		dialog.appendChild(footer);
		backdrop.appendChild(dialog);
		document.body.appendChild(backdrop);
		if (window.NBHPerformanceI18n) { window.NBHPerformanceI18n.localizeTree(backdrop); }
		document.body.classList.add('nbh-performance-modal-open');

		function closeIssue() { closeModal(backdrop); }
		closeButton.addEventListener('click', closeIssue);
		later.addEventListener('click', closeIssue);
		backdrop.addEventListener('click', function (event) { if (event.target === backdrop) { closeIssue(); } });
		addEscapeHandler(backdrop);
		closeButton.focus();
	}

	function popupStorageKey(notice) {
		var issueId = notice.getAttribute('data-nbh-issue-id') || 'issue';
		var message = textOf(notice, '.nbh-performance-notice__message', '');
		var fingerprint = 0;
		for (var i = 0; i < message.length; i += 1) {
			fingerprint = ((fingerprint << 5) - fingerprint) + message.charCodeAt(i);
			fingerprint |= 0;
		}
		return 'nbh-performance-popup-' + issueId + '-' + Math.abs(fingerprint);
	}

	function showFirstActionableIssue() {
		var notices = document.querySelectorAll('.nbh-performance-popup-issue');
		for (var i = 0; i < notices.length; i += 1) {
			var notice = notices[i];
			var key = popupStorageKey(notice);
			var seen = false;
			try { seen = window.sessionStorage.getItem(key) === '1'; } catch (error) { seen = false; }
			if (seen) { continue; }
			try { window.sessionStorage.setItem(key, '1'); } catch (error) { /* Storage may be disabled. */ }
			openIssueModal(notice);
			break;
		}
	}

	document.addEventListener('click', function (event) {
		var link = event.target.closest('.nbh-performance-confirm-action');
		if (!link) { return; }
		event.preventDefault();
		var issueBackdrop = link.closest('.nbh-performance-issue-backdrop');
		if (issueBackdrop) { closeModal(issueBackdrop); }
		openConfirmModal(link);
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', showFirstActionableIssue);
	} else {
		showFirstActionableIssue();
	}
}());
