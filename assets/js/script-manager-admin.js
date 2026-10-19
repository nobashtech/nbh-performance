(function () {
	'use strict';
	var config = window.nbhPerformanceScriptManager || {};
	var root = document.getElementById('nbh-performance-script-manager-root');
	if (!root) { return; }

	var state = { rules: [], assets: [], testing: true, warnings: [], history: [], busy: false, notice: null };
	var scopes = {
		global: 'Everywhere',
		url_exact: 'Only on an exact URL',
		url_contains: 'URLs containing a value',
		url_regex: 'URLs matching a regular expression',
		url_except: 'Everywhere except matching URLs',
		post_type: 'A post type',
		taxonomy: 'A taxonomy archive',
		front_page: 'Front page only',
		home_page: 'Posts page only',
		archive: 'Archive pages',
		author_archive: 'Author archive',
		search: 'Search results',
		'404': '404 pages',
		singular_id: 'A specific post or page ID',
		template: 'A page template'
	};

	function request(path, options) {
		options = options || {};
		options.headers = Object.assign({ 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, options.headers || {});
		return fetch(config.root.replace(/\/$/, '') + path, options).then(function (response) {
			return response.json().then(function (body) {
				if (!response.ok) { throw new Error(body.message || 'The request could not be completed.'); }
				return body;
			});
		});
	}

	function escape(value) {
		var node = document.createElement('div');
		node.textContent = value == null ? '' : String(value);
		return node.innerHTML;
	}

	function optionList(values, selected) {
		return Object.keys(values).map(function (key) {
			return '<option value="' + escape(key) + '"' + (key === selected ? ' selected' : '') + '>' + escape(values[key]) + '</option>';
		}).join('');
	}

	function assetOptions() {
		return state.assets.map(function (asset) {
			return '<option value="' + escape(asset.handle) + '">' + escape(asset.handle + ' · ' + asset.type + ' · ' + asset.owner) + '</option>';
		}).join('');
	}

	function id() {
		return 'rule-' + Date.now() + '-' + Math.random().toString(16).slice(2);
	}

	function activeRuleCount() {
		return state.rules.filter(function (rule) { return rule.enabled !== false; }).length;
	}

	function toggleMarkup(idValue, checked, label, description, className) {
		return '<label class="npsm-toggle-row ' + (className || '') + '" for="' + escape(idValue) + '">' +
			'<span class="npsm-toggle-copy"><b>' + escape(label) + '</b><small>' + escape(description) + '</small></span>' +
			'<span class="npsm-switch-control"><input id="' + escape(idValue) + '" type="checkbox"' + (checked ? ' checked' : '') + '><span class="npsm-switch-track"><span></span></span></span>' +
		'</label>';
	}

	function renderNotice() {
		if (!state.notice) { return ''; }
		return '<div class="npsm-notice is-' + escape(state.notice.tone || 'info') + '"><span class="dashicons dashicons-' + (state.notice.tone === 'error' ? 'warning' : 'yes-alt') + '"></span><div><strong>' + escape(state.notice.title || 'Script Manager') + '</strong><p>' + escape(state.notice.message || '') + '</p></div><button type="button" class="npsm-notice-close" aria-label="Dismiss notice">×</button></div>';
	}

	function renderStats() {
		return '<section class="npsm-stats" aria-label="Script Manager summary">' +
			statCard('Inventoried assets', state.assets.length, 'Scripts and styles currently found', 'media-code', 'assets') +
			statCard('Active rules', activeRuleCount(), state.rules.length + ' total saved rules', 'filter', 'active-rules') +
			statCard('Testing Mode', state.testing ? 'On' : 'Off', state.testing ? 'Only administrators see the rules' : 'Rules can affect visitors', state.testing ? 'shield-alt' : 'visibility', 'testing') +
			statCard('Safety warnings', state.warnings.length, state.warnings.length ? 'Review dependent files before going live' : 'No dependency warnings detected', 'warning', 'warnings') +
		'</section>';
	}

	function statCard(label, value, description, icon, key) {
		return '<article class="npsm-stat" data-stat="' + escape(key || '') + '"><span class="npsm-stat-icon dashicons dashicons-' + escape(icon) + '"></span><div><small>' + escape(label) + '</small><strong>' + escape(value) + '</strong><p>' + escape(description) + '</p></div></article>';
	}

	function field(label, control, help, wide) {
		return '<label class="npsm-field' + (wide ? ' is-wide' : '') + '"><span>' + escape(label) + '</span>' + control + (help ? '<small>' + escape(help) + '</small>' : '') + '</label>';
	}

	function render() {
		var warning = state.warnings.length ? '<div class="npsm-warning"><span class="dashicons dashicons-warning"></span><div><strong>Some rules were protected</strong><p>' + escape(state.warnings.length + ' recent rule checks found files that other assets depend on. Review them before disabling Testing Mode.') + '</p></div></div>' : '';

		root.innerHTML = renderNotice() + renderStats() + warning +
		'<section class="npsm-toolbar">' +
			'<div class="npsm-toolbar-safety">' + toggleMarkup('npsm-testing', state.testing, 'Testing Mode', 'Apply rules only while a logged-in administrator is viewing the frontend.', 'is-primary') + '</div>' +
			'<div class="npsm-toolbar-actions"><button type="button" class="button npsm-button-secondary" id="npsm-export"><span class="dashicons dashicons-download"></span> Export</button><button type="button" class="button npsm-button-secondary" id="npsm-import"><span class="dashicons dashicons-upload"></span> Import</button><input id="npsm-import-file" type="file" accept="application/json" hidden><button type="button" class="button npsm-button-secondary" id="npsm-refresh"><span class="dashicons dashicons-update"></span> Refresh files</button><button type="button" class="button button-primary npsm-button-primary" id="npsm-save"' + (state.busy ? ' disabled' : '') + '><span class="dashicons dashicons-saved"></span> ' + (state.busy ? 'Saving…' : 'Save all rules') + '</button></div>' +
		'</section>' +
		'<section class="npsm-card npsm-builder-card">' +
			'<div class="npsm-card-head"><div><span class="npsm-kicker">Create a rule</span><h2>Control a script or stylesheet</h2><p>Choose a file, decide what NBH Performance should do, and limit the rule to the right pages and visitors.</p></div><span class="npsm-count">' + state.assets.length + ' files found</span></div>' +
			'<div class="npsm-builder">' +
				'<section class="npsm-form-section"><div class="npsm-form-section-head"><span>1</span><div><strong>Choose the file</strong><small>Select the script or stylesheet you want to control.</small></div></div><div class="npsm-form-grid">' +
					field('Script or style name', '<input id="npsm-handle" list="npsm-assets" placeholder="Start typing a file handle…"><datalist id="npsm-assets">' + assetOptions() + '</datalist>', 'Use the WordPress asset handle shown in the inventory.', true) +
					field('File type', '<select id="npsm-type"><option value="script">JavaScript</option><option value="style">Stylesheet</option></select>', 'Choose the correct file type.') +
					field('What should happen?', '<select id="npsm-action"><option value="unload">Do not load it</option><option value="delay">Load after interaction</option><option value="defer">Load later with defer</option><option value="async">Load independently with async</option><option value="move_footer">Move to footer</option><option value="preload">Preload this file</option><option value="preconnect">Preconnect to its origin</option><option value="dns-prefetch">DNS-prefetch its origin</option><option value="remove_version">Remove version query string</option></select>', 'Unload is the strongest option. Test it carefully.') +
				'</div></section>' +
				'<section class="npsm-form-section"><div class="npsm-form-section-head"><span>2</span><div><strong>Choose where it applies</strong><small>Keep the rule global or limit it to specific URLs and content.</small></div></div><div class="npsm-form-grid">' +
					field('Where should it apply?', '<select id="npsm-scope">' + optionList(scopes, 'global') + '</select>', 'Select the page-matching method.') +
					field('URL or matching value', '<input id="npsm-value" placeholder="Example: /checkout/ or product">', 'Leave empty when the selected location does not need a value.', true) +
				'</div></section>' +
				'<section class="npsm-form-section"><div class="npsm-form-section-head"><span>3</span><div><strong>Choose who receives the rule</strong><small>Target devices, login states or specific WordPress roles.</small></div></div><div class="npsm-form-grid">' +
					field('Devices', '<select id="npsm-device"><option value="all">All devices</option><option value="desktop">Desktop only</option><option value="mobile">Mobile only</option></select>', 'Use device targeting only when the page loads different assets.') +
					field('Visitors', '<select id="npsm-auth"><option value="all">All visitors</option><option value="guest">Logged-out visitors</option><option value="logged_in">Logged-in users</option></select>', 'Choose whether login status matters.') +
					field('User roles', '<input id="npsm-roles" placeholder="administrator, shop_manager">', 'Optional. Separate multiple role names with commas.', true) +
					field('Language', '<input id="npsm-language" placeholder="en or en-us">', 'Optional locale prefix.') +
					field('Query parameter', '<input id="npsm-query" placeholder="campaign=spring">', 'Optional key=value condition.') +
					field('Cookie condition', '<input id="npsm-cookie" placeholder="currency=USD">', 'Optional key=value condition.') +
					field('Internal note', '<input id="npsm-note" placeholder="Why this rule exists">', 'Shown only to administrators.', true) +
				'</div></section>' +
				'<section class="npsm-form-section npsm-safety-section"><div class="npsm-form-section-head"><span>4</span><div><strong>Safety</strong><small>Dependent files can be included only when you deliberately enable this option.</small></div></div>' +
					toggleMarkup('npsm-cascade', false, 'Include dependent files', 'Also unload files that require this asset. Use this only after testing the page carefully.', 'is-warning') +
					'<div class="npsm-builder-submit"><p><strong>Recommended:</strong> Keep Testing Mode on, add one rule at a time, and check the frontend before adding another.</p><button type="button" class="button button-primary npsm-add" id="npsm-add"><span class="dashicons dashicons-plus-alt2"></span> Add this rule</button></div>' +
				'</section>' +
			'</div>' +
		'</section>' +
		'<section class="npsm-card npsm-rules-card"><div class="npsm-card-head"><div><span class="npsm-kicker">Saved policy</span><h2>Rules</h2><p>Turn rules on or off, adjust their action, or remove rules you no longer need.</p></div><span class="npsm-count">' + state.rules.length + ' rules</span></div>' + rulesTable() + '</section>';
		if (window.NBHPerformanceI18n) { window.NBHPerformanceI18n.localizeTree(root); }
		bind();
	}

	function rulesTable() {
		if (!state.rules.length) {
			return '<div class="npsm-empty"><span class="dashicons dashicons-filter"></span><strong>No rules created yet</strong><p>Create your first rule above. Keep Testing Mode enabled while checking the frontend.</p></div>';
		}
		return '<div class="npsm-table-wrap"><table class="npsm-table"><thead><tr><th>Status</th><th>File</th><th>Action</th><th>Location</th><th>Visitors</th><th>Dependencies</th><th><span class="screen-reader-text">Remove</span></th></tr></thead><tbody>' + state.rules.map(function (rule, index) {
			var statusToggle = '<label class="npsm-mini-switch"><input class="npsm-rule-enabled" data-index="' + index + '" type="checkbox"' + (rule.enabled ? ' checked' : '') + '><span><span></span></span><b>' + (rule.enabled ? 'On' : 'Off') + '</b></label>';
			return '<tr><td>' + statusToggle + '</td><td><strong>' + escape(rule.handle) + '</strong><small>' + escape(rule.type === 'style' ? 'Stylesheet' : 'JavaScript') + '</small></td><td><select class="npsm-rule-action" data-index="' + index + '"><option value="unload"' + (rule.action === 'unload' ? ' selected' : '') + '>Do not load</option><option value="delay"' + (rule.action === 'delay' ? ' selected' : '') + '>After interaction</option><option value="defer"' + (rule.action === 'defer' ? ' selected' : '') + '>Defer</option><option value="async"' + (rule.action === 'async' ? ' selected' : '') + '>Async</option><option value="move_footer"' + (rule.action === 'move_footer' ? ' selected' : '') + '>Move to footer</option><option value="preload"' + (rule.action === 'preload' ? ' selected' : '') + '>Preload</option><option value="preconnect"' + (rule.action === 'preconnect' ? ' selected' : '') + '>Preconnect</option><option value="dns-prefetch"' + (rule.action === 'dns-prefetch' ? ' selected' : '') + '>DNS prefetch</option><option value="remove_version"' + (rule.action === 'remove_version' ? ' selected' : '') + '>Remove version</option></select></td><td><strong>' + escape(scopes[rule.scope] || rule.scope) + '</strong><small>' + escape(rule.value || 'No extra value') + '</small></td><td><strong>' + escape(rule.device === 'all' ? 'All devices' : rule.device) + '</strong><small>' + escape((rule.auth === 'all' ? 'All visitors' : rule.auth) + ((rule.roles || []).length ? ' · ' + rule.roles.join(', ') : '')) + '</small></td><td><span class="npsm-dependency-badge ' + (rule.cascade ? 'is-on' : '') + '">' + (rule.cascade ? 'Included' : 'Protected') + '</span></td><td><button type="button" class="npsm-remove" data-index="' + index + '" aria-label="Remove ' + escape(rule.handle) + '"><span class="dashicons dashicons-trash"></span></button></td></tr>';
		}).join('') + '</tbody></table></div>';
	}

	function setNotice(title, message, tone) {
		state.notice = { title: title, message: message, tone: tone || 'success' };
	}

	function bind() {
		var testing = document.getElementById('npsm-testing');
		var refresh = document.getElementById('npsm-refresh');
		var saveButton = document.getElementById('npsm-save');
		var addButton = document.getElementById('npsm-add');
		var exportButton = document.getElementById('npsm-export');
		var importButton = document.getElementById('npsm-import');
		var importFile = document.getElementById('npsm-import-file');
		if (testing) { testing.onchange = function (event) {
			state.testing = event.target.checked;
			var card = root.querySelector('[data-stat="testing"]');
			if (card) { card.querySelector('strong').textContent = state.testing ? 'On' : 'Off'; card.querySelector('p').textContent = state.testing ? 'Only administrators see the rules' : 'Rules can affect visitors'; }
		}; }
		if (refresh) { refresh.onclick = load; }
		if (saveButton) { saveButton.onclick = save; }
		if (addButton) { addButton.onclick = add; }
		if (exportButton) { exportButton.onclick = exportRules; }
		if (importButton && importFile) { importButton.onclick = function () { importFile.click(); }; importFile.onchange = importRules; }
		var closeNotice = root.querySelector('.npsm-notice-close');
		if (closeNotice) { closeNotice.onclick = function () { state.notice = null; render(); }; }
		Array.prototype.forEach.call(root.querySelectorAll('.npsm-remove'), function (button) {
			button.onclick = function () {
				state.rules.splice(Number(button.dataset.index), 1);
				setNotice('Rule removed', 'The rule was removed from this draft. Select “Save all rules” to store the change.', 'info');
				render();
			};
		});
		Array.prototype.forEach.call(root.querySelectorAll('.npsm-rule-enabled'), function (input) {
			input.onchange = function () {
				state.rules[Number(input.dataset.index)].enabled = input.checked;
				var label = input.parentNode.querySelector('b');
				if (label) { label.textContent = input.checked ? 'On' : 'Off'; }
				var card = root.querySelector('[data-stat="active-rules"]');
				if (card) { card.querySelector('strong').textContent = String(activeRuleCount()); }
			};
		});
		Array.prototype.forEach.call(root.querySelectorAll('.npsm-rule-action'), function (select) {
			select.onchange = function () { state.rules[Number(select.dataset.index)].action = select.value; };
		});
	}

	function add() {
		var handle = document.getElementById('npsm-handle').value.trim();
		if (!handle) {
			setNotice('Choose a file first', 'Enter or select the WordPress handle of the script or stylesheet you want to control.', 'error');
			render();
			return;
		}
		var queryParts = document.getElementById('npsm-query').value.split('=');
		var cookieParts = document.getElementById('npsm-cookie').value.split('=');
		var proposed = {
			id: id(),
			handle: handle,
			type: document.getElementById('npsm-type').value,
			action: document.getElementById('npsm-action').value,
			scope: document.getElementById('npsm-scope').value,
			value: document.getElementById('npsm-value').value.trim(),
			device: document.getElementById('npsm-device').value,
			auth: document.getElementById('npsm-auth').value,
			roles: document.getElementById('npsm-roles').value.split(',').map(function (role) { return role.trim(); }).filter(Boolean),
			cascade: document.getElementById('npsm-cascade').checked,
			enabled: true,
			priority: 50,
			language: document.getElementById('npsm-language').value.trim(),
			query_key: (queryParts.shift() || '').trim(),
			query_value: queryParts.join('=').trim(),
			cookie_key: (cookieParts.shift() || '').trim(),
			cookie_value: cookieParts.join('=').trim(),
			note: document.getElementById('npsm-note').value.trim()
		};
		request('/script-manager/analyze', { method: 'POST', body: JSON.stringify({ rule: proposed }) }).then(function (analysis) {
			if (analysis.risk === 'critical') { throw new Error('This asset is protected and cannot be unloaded safely.'); }
			state.rules.push(proposed);
			setNotice('Rule added', 'Safety analysis: ' + analysis.risk + ' risk. Save the rules after reviewing the dependency information.', analysis.risk === 'high' ? 'info' : 'success');
			render();
		}).catch(function (error) { setNotice('Rule was not added', error.message, 'error'); render(); });
		return;
	}

	function exportRules() {
		request('/script-manager/export').then(function (payload) {
			var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
			var link = document.createElement('a');
			link.href = URL.createObjectURL(blob);
			link.download = 'nbh-performance-script-manager-rules.json';
			link.click();
			URL.revokeObjectURL(link.href);
		});
	}

	function importRules(event) {
		var file = event.target.files && event.target.files[0];
		if (!file) { return; }
		var reader = new FileReader();
		reader.onload = function () {
			request('/script-manager/import', { method: 'POST', body: JSON.stringify({ payload: reader.result }) }).then(function (data) {
				state.rules = data.rules || []; state.history = data.history || []; setNotice('Rules imported', 'The imported rules passed schema validation.', 'success'); render();
			}).catch(function (error) { setNotice('Import failed', error.message, 'error'); render(); });
		};
		reader.readAsText(file);
	}

	function save() {
		state.busy = true;
		state.notice = null;
		render();
		request('/script-manager/rules', { method: 'POST', body: JSON.stringify({ rules: state.rules, testing_mode: state.testing }) }).then(function (data) {
			state.rules = data.rules || [];
			state.testing = !!data.testing_mode;
			state.warnings = data.warnings || [];
			state.busy = false;
			setNotice('Rules saved', state.testing ? 'Your rules are saved and remain visible only to logged-in administrators.' : 'Your rules are saved and can now affect matching visitors.', 'success');
			render();
		}).catch(function (error) {
			state.busy = false;
			setNotice('Rules were not saved', error.message, 'error');
			render();
		});
	}

	function load() {
		root.innerHTML = '<div class="npsm-loading"><span class="spinner is-active"></span><strong>Refreshing scripts and styles…</strong><p>NBH Performance is reading the current WordPress asset inventory.</p></div>';
		if (window.NBHPerformanceI18n) { window.NBHPerformanceI18n.localizeTree(root); }
		Promise.all([request('/script-manager/rules'), request('/assets/inventory')]).then(function (responses) {
			state.rules = responses[0].rules || [];
			state.testing = !!responses[0].testing_mode;
			state.warnings = responses[0].warnings || [];
			state.history = responses[0].history || [];
			var inventory = responses[1] || {};
			state.assets = (inventory.scripts || []).concat(inventory.styles || []);
			state.notice = null;
			render();
		}).catch(function (error) {
			state.notice = { title: 'Script Manager could not load', message: error.message, tone: 'error' };
			state.assets = [];
			state.rules = [];
			render();
		});
	}

	load();
}());
