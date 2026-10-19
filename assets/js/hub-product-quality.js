(function (wp) {
	'use strict';
	if (!wp || !wp.element) { return; }
	var rawEl = wp.element.createElement;
	var translate = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (value) { return value; };
	function el(type, props) {
		var args = Array.prototype.slice.call(arguments);
		if (props && typeof props === 'object' && !Array.isArray(props)) {
			props = Object.assign({}, props);
			['title', 'placeholder', 'aria-label', 'alt'].forEach(function (key) { if (typeof props[key] === 'string') { props[key] = translate(props[key], 'nbh-performance'); } });
			args[1] = props;
		}
		for (var index = 2; index < args.length; index += 1) { if (typeof args[index] === 'string') { args[index] = translate(args[index], 'nbh-performance'); } }
		return rawEl.apply(null, args);
	}

	function formatTime(timestamp) {
		var value = Number(timestamp || 0);
		if (!value) { return 'Not yet'; }
		try { return new Date(value * 1000).toLocaleString(); } catch (error) { return 'Recorded'; }
	}

	function StatusPill(label, active, warning) {
		return el('span', { className: 'np-quality-pill is-' + (warning ? 'warning' : (active ? 'good' : 'muted')) }, label);
	}

	function riskLabel(risk) {
		var value = String(risk || 'low').toLowerCase();
		if (value === 'high') { return 'High risk'; }
		if (value === 'medium') { return 'Medium risk'; }
		return 'Low risk';
	}

	function ownershipIconFor(feature) {
		return {
			page_cache: 'layers',
			css_minify: 'css',
			js_minify: 'js',
			lazy_load: 'image',
			critical_css: 'bolt',
			image_conversion: 'convert'
		}[feature] || 'shield';
	}

	function OwnershipSection(props) {
		var ownership = Array.isArray(props.ownership) ? props.ownership : [];
		var icon = props.icon || function () { return null; };
		if (!ownership.length) { return null; }

		var managed = ownership.filter(function (item) { return !!item.managed_here; });
		var external = ownership.filter(function (item) { return !item.managed_here; });
		var allClear = external.length === 0;

		return el('div', { className: 'np-quality-ownership' + (allClear ? ' is-clear' : ' is-conflict'), 'aria-labelledby': 'np-ownership-title' },
			el('div', { className: 'np-quality-ownership-head' },
				el('div', { className: 'np-quality-ownership-copy' },
					el('h4', { id: 'np-ownership-title' }, 'Optimization ownership'),
					el('p', null, 'Only one product should manage each optimization layer. Overlaps can cause double caching, broken CSS, or missed purges.')
				),
				el('div', { className: 'np-quality-ownership-meta' },
					el('span', { className: 'np-quality-pill is-good' }, managed.length + ' managed here'),
					external.length
						? el('span', { className: 'np-quality-pill is-warning' }, external.length + ' owned elsewhere')
						: el('span', { className: 'np-quality-pill is-muted' }, 'No overlaps detected'),
					el('button', {
						type: 'button',
						className: 'np-button np-button-secondary',
						disabled: !!props.busy.compatibility_matrix,
						onClick: function () { props.runAction('compatibility_matrix'); }
					}, icon('shield'), props.busy.compatibility_matrix ? 'Checking…' : 'Check conflicts')
				)
			),
			!allClear ? el('div', { className: 'np-quality-ownership-alert', role: 'status' },
				el('span', { className: 'np-quality-ownership-alert-icon' }, icon('warning')),
				el('div', null,
					el('strong', null, 'Resolve overlapping optimizers'),
					el('p', null, 'Another plugin currently owns ' + external.map(function (item) { return item.label; }).join(', ') + '. Keep one owner per layer, then re-check conflicts.')
				)
			) : null,
			el('div', { className: 'np-quality-owner-grid' }, ownership.map(function (item) {
				var here = !!item.managed_here;
				return el('article', {
					key: item.feature,
					className: 'np-quality-owner-card' + (here ? ' is-nbh' : ' is-external'),
					'aria-label': (item.label || 'Feature') + ' owned by ' + (item.owner_label || 'unknown')
				},
					el('span', { className: 'np-quality-owner-icon', 'aria-hidden': 'true' }, icon(ownershipIconFor(item.feature))),
					el('div', { className: 'np-quality-owner-body' },
						el('strong', null, item.label || item.feature),
						el('span', { className: 'np-quality-owner-state' }, here ? 'Managed by NBH Performance' : 'Owned by another product')
					),
					el('span', { className: 'np-quality-owner-badge' }, item.owner_label || 'Unknown')
				);
			}))
		);
	}

	function RecoveryCenterPanel(props) {
		var quality = props.data || {};
		var recovery = quality.recovery || {};
		var ownership = Array.isArray(quality.ownership) ? quality.ownership : [];
		var runbook = Array.isArray(props.runbook) ? props.runbook : [];
		var next = quality.next_action || {};
		var active = !!recovery.active;
		var icon = props.icon || function () { return null; };

		return el('section', { className: 'np-panel np-quality-center', 'aria-labelledby': 'np-recovery-center-title' },
			el('div', { className: 'np-quality-center-head' },
				el('div', null,
					el('span', { className: 'np-kicker' }, 'Guided recovery'),
					el('h3', { id: 'np-recovery-center-title' }, 'Recovery Center'),
					el('p', null, active ? 'The optimization runtime is in a reversible stable state.' : 'Stabilize the site before troubleshooting, then restore only after validation passes.')
				),
				el('div', { className: 'np-quality-pills', 'aria-label': 'Recovery status' },
					StatusPill(recovery.safe_mode ? 'Safe Mode active' : 'Optimized mode active', !recovery.safe_mode, recovery.safe_mode),
					StatusPill(recovery.queue_paused ? 'Queue paused' : 'Queue running', !recovery.queue_paused, recovery.queue_paused),
					StatusPill(recovery.validation_passed ? 'Validation passed' : 'Validation required', recovery.validation_passed, !recovery.validation_passed)
				)
			),
			el('div', { className: 'np-quality-grid' },
				el('article', { className: 'np-quality-action-card' },
					el('span', { className: 'np-quality-action-icon' }, icon(active ? 'shield' : 'warning')),
					el('div', null, el('small', null, 'Recommended next action'), el('strong', null, next.label || 'Review site status'), el('p', null, next.reason || 'No recovery action is required.')),
					el('div', { className: 'np-quality-actions' },
						!active ? el('button', { className: 'np-button np-button-danger-solid', disabled: !!props.busy.recovery_stabilize, onClick: function () {
							props.confirmAction({ variant: 'warning', kicker: 'Recovery Center', title: 'Stabilize NBH Performance now?', message: 'Safe Mode will be activated, background jobs will pause, and generated page cache will be cleared.', note: 'Your current NBH Performance configuration is preserved so it can be restored after frontend validation passes.', confirmText: 'Stabilize site', cancelText: 'Cancel', icon: 'shield' }).then(function (confirmed) { if (confirmed) { props.runAction('recovery_stabilize'); } });
						} }, icon('shield'), props.busy.recovery_stabilize ? 'Stabilizing…' : 'Stabilize site') : null,
						active && !recovery.validation_passed ? el('button', { className: 'np-button np-button-primary', disabled: !!props.busy.staging_validation, onClick: function () { props.runAction('staging_validation'); } }, icon('eye'), props.busy.staging_validation ? 'Validating…' : 'Validate frontend') : null,
						active && recovery.validation_passed ? el('button', { className: 'np-button np-button-primary', disabled: !!props.busy.recovery_resume, onClick: function () {
							props.confirmAction({ variant: 'info', kicker: 'Recovery Center', title: 'Restore the saved optimization configuration?', message: 'Frontend validation passed. NBH Performance will restore the saved features, resume background work, and warm the homepage when page cache is enabled.', note: 'If a new problem appears, you can stabilize the site again without losing the saved configuration.', confirmText: 'Resume optimized mode', cancelText: 'Stay in Recovery Mode', icon: 'refresh' }).then(function (confirmed) { if (confirmed) { props.runAction('recovery_resume'); } });
						} }, icon('refresh'), props.busy.recovery_resume ? 'Restoring…' : 'Resume optimized mode') : null
					)
				),
				el('article', { className: 'np-quality-evidence' },
					el('h4', null, 'Recovery evidence'),
					el('dl', null,
						el('div', null, el('dt', null, 'Configuration snapshot'), el('dd', null, recovery.snapshot_available ? 'Available' : 'Not available')),
						el('div', null, el('dt', null, 'Runtime watchdog'), el('dd', null, recovery.runtime_degraded ? 'Degraded' : 'Healthy')),
						el('div', null, el('dt', null, 'Last stabilized'), el('dd', null, formatTime(recovery.stabilized_at))),
						el('div', null, el('dt', null, 'Last restored'), el('dd', null, formatTime(recovery.resumed_at)))
					)
				)
			),
			runbook.length ? el('details', { className: 'np-quality-runbook' },
				el('summary', null,
					el('strong', null, 'Recovery runbook'),
					el('span', null, runbook.length + ' steps')
				),
				el('p', null, 'Follow these steps in order when the site needs a controlled rollback.'),
				el('ol', { className: 'np-quality-runbook-list' }, runbook.map(function (step, index) {
					return el('li', { key: step.id || ('step-' + index), className: 'np-quality-runbook-item is-' + String(step.risk || 'low') },
						el('div', null,
							el('strong', null, (index + 1) + '. ' + (step.title || 'Recovery step')),
							el('span', { className: 'np-quality-runbook-risk' }, riskLabel(step.risk))
						),
						el('p', null, step.action || '')
					);
				}))
			) : null,
			el(OwnershipSection, { ownership: ownership, icon: icon, busy: props.busy || {}, runAction: props.runAction })
		);
	}

	window.NBHPerformanceProductQuality = { RecoveryCenterPanel: RecoveryCenterPanel };
}(window.wp));
