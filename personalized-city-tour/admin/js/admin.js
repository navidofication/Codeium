/* global pctAdmin */
(function () {
	'use strict';

	var cfg = window.pctAdmin || { i18n: {} };

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	/* Hamburger drawer ----------------------------------------------- */
	var burger = $('.pct-hamburger');
	var drawer = $('#pct-drawer');
	var backdrop = $('.pct-drawer-backdrop');

	function setDrawer(open) {
		if (!drawer) { return; }
		drawer.hidden = !open;
		backdrop.hidden = !open;
		burger.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open) {
			var first = drawer.querySelector('select, a, button');
			if (first) { first.focus(); }
		} else {
			burger.focus();
		}
	}
	if (burger && drawer) {
		burger.addEventListener('click', function () { setDrawer(drawer.hidden); });
		backdrop.addEventListener('click', function () { setDrawer(false); });
		$('.pct-drawer-close', drawer).addEventListener('click', function () { setDrawer(false); });
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !drawer.hidden) { setDrawer(false); }
		});
	}

	/* Confirm forms --------------------------------------------------- */
	$$('form[data-confirm]').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			if (!window.confirm(cfg.i18n.confirm || 'Are you sure?')) { e.preventDefault(); }
		});
	});

	/* Repeater (Google My Maps) -------------------------------------- */
	document.addEventListener('click', function (e) {
		var add = e.target.closest('.pct-repeater-add');
		if (add) {
			var table = document.getElementById(add.getAttribute('data-target'));
			var body = table && table.querySelector('tbody');
			if (!body) { return; }
			var rows = body.querySelectorAll('tr');
			var tpl = rows[rows.length - 1].cloneNode(true);
			var idx = rows.length;
			$$('input', tpl).forEach(function (input) {
				input.name = input.name.replace(/\[\d+\]/, '[' + idx + ']');
				input.value = '';
			});
			body.appendChild(tpl);
			tpl.querySelector('input').focus();
			return;
		}
		var remove = e.target.closest('.pct-repeater-remove');
		if (remove) {
			var row = remove.closest('tr');
			var tbody = row.parentNode;
			if (tbody.querySelectorAll('tr').length > 1) {
				row.remove();
			} else {
				$$('input', row).forEach(function (i) { i.value = ''; });
			}
		}
	});

	/* AI connection test ---------------------------------------------- */
	var testBtn = $('#pct-ai-test');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			var out = $('#pct-ai-test-result');
			out.textContent = cfg.i18n.testing;
			testBtn.disabled = true;
			fetch(cfg.rest + '/admin/ai-test', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
				body: '{}'
			}).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
				.then(function (res) {
					out.textContent = res.ok ? (res.json.message || cfg.i18n.ok) : (res.json.message || 'Error');
					out.style.color = res.ok ? '#00701a' : '#b32d2e';
				})
				.catch(function (err) {
					out.textContent = err.message;
					out.style.color = '#b32d2e';
				})
				.then(function () { testBtn.disabled = false; });
		});
	}

	/* Settings: toggle provider hints --------------------------------- */
	var provider = $('#pct_ai_provider');
	if (provider) {
		var sync = function () {
			var v = provider.value;
			var endpointRow = $('#pct_ai_endpoint').closest('tr');
			endpointRow.style.opacity = (v === 'none') ? '0.5' : '';
			$$('#pct_ai_api_key, #pct_ai_model, #pct_ai_temperature, #pct_ai_max_items').forEach(function (el) {
				el.closest('tr').style.opacity = (v === 'none') ? '0.5' : '';
			});
		};
		provider.addEventListener('change', sync);
		sync();
	}
})();
