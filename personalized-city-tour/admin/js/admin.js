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

	/* Repeaters (My Maps, channels, model map, planner) --------------- */
	function resetField(el) {
		if (el.tagName === 'SELECT') {
			el.selectedIndex = 0;
		} else if (el.type === 'checkbox' || el.type === 'radio') {
			el.checked = false;
		} else {
			el.value = '';
		}
	}
	function renameFields(root, idx) {
		$$('input, select, textarea', root).forEach(function (el) {
			if (el.name) { el.name = el.name.replace(/\[\d+\]/, '[' + idx + ']'); }
			resetField(el);
		});
	}
	document.addEventListener('click', function (e) {
		var add = e.target.closest('.pct-repeater-add');
		if (add) {
			var table = document.getElementById(add.getAttribute('data-target'));
			var body = table && table.querySelector('tbody');
			if (!body) { return; }
			var rows = body.querySelectorAll('tr');
			var tpl = rows[rows.length - 1].cloneNode(true);
			renameFields(tpl, rows.length);
			body.appendChild(tpl);
			var first = tpl.querySelector('input, select, textarea');
			if (first) { first.focus(); }
			return;
		}
		var remove = e.target.closest('.pct-repeater-remove');
		if (remove) {
			var row = remove.closest('tr');
			var tbody = row.parentNode;
			if (tbody.querySelectorAll('tr').length > 1) {
				row.remove();
			} else {
				$$('input, select, textarea', row).forEach(resetField);
			}
			return;
		}
		var ruleRemove = e.target.closest('.pct-rule-remove');
		if (ruleRemove) {
			var rule = ruleRemove.closest('.pct-rule');
			if (rule && window.confirm(cfg.i18n.confirm || 'Are you sure?')) { rule.remove(); }
		}
	});

	/* Planner: add rule (clone the last, blank, rule block) ------------ */
	var ruleAdd = $('#pct-rule-add');
	if (ruleAdd) {
		ruleAdd.addEventListener('click', function () {
			var rules = $$('#pct-rules .pct-rule');
			var last = rules[rules.length - 1];
			var tpl = last.cloneNode(true);
			var idx = rules.length;
			tpl.setAttribute('data-index', idx);
			$$('input, select, textarea', tpl).forEach(function (el) {
				if (el.name) { el.name = el.name.replace(/\[rules\]\[\d+\]/, '[rules][' + idx + ']'); }
				if (el.name && /\[enabled\]$/.test(el.name)) { el.checked = true; return; }
				if (el.name && /\[priority\]$/.test(el.name)) { el.value = '10'; return; }
				resetField(el);
			});
			$$('table.pct-repeater', tpl).forEach(function (table) {
				var newId = table.id.replace(/-\d+$/, '-' + idx);
				var btn = tpl.querySelector('[data-target="' + table.id + '"]');
				table.id = newId;
				if (btn) { btn.setAttribute('data-target', newId); }
				var body = table.querySelector('tbody');
				$$('tr', body).slice(1).forEach(function (tr) { tr.remove(); });
			});
			$('#pct-rules').appendChild(tpl);
			var name = tpl.querySelector('input[name$="[name]"]');
			if (name) { name.focus(); }
		});
	}

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

	/* Settings: provider-driven model list + custom model ------------- */
	var provider = $('#pct_ai_provider');
	var modelSel = $('#pct_ai_model');
	var modelCustom = $('#pct_ai_model_custom');
	if (provider && modelSel) {
		var lists = {};
		try { lists = JSON.parse(modelSel.getAttribute('data-models') || '{}'); } catch (err) { lists = {}; }
		var customLabel = modelSel.querySelector('option[value="__custom"]').textContent;
		var syncCustom = function () {
			var isCustom = modelSel.value === '__custom';
			modelCustom.style.display = isCustom ? '' : 'none';
			if (isCustom) { modelCustom.focus(); }
		};
		var fillModels = function (keepCurrent) {
			var list = lists[provider.value] || [];
			var current = keepCurrent ? modelSel.getAttribute('data-current') : (list[0] ? list[0].model : '__custom');
			modelSel.innerHTML = '';
			var known = false;
			list.forEach(function (m) {
				var opt = document.createElement('option');
				opt.value = m.model;
				opt.textContent = m.label ? m.label + ' — ' + m.model : m.model;
				if (m.model === current) { opt.selected = true; known = true; }
				modelSel.appendChild(opt);
			});
			var custom = document.createElement('option');
			custom.value = '__custom';
			custom.textContent = customLabel;
			custom.selected = !known;
			modelSel.appendChild(custom);
			if (!known && current && current !== '__custom' && keepCurrent) { modelCustom.value = current; }
			syncCustom();
		};
		var sync = function (fromChange) {
			var off = provider.value === 'none';
			$$('#pct_ai_api_key, #pct_ai_model, #pct_ai_deployment, #pct_ai_endpoint, #pct_ai_prompt_template').forEach(function (el) {
				var row = el.closest('tr');
				if (row) { row.style.opacity = off ? '0.5' : ''; }
			});
			fillModels(!fromChange);
		};
		modelSel.addEventListener('change', syncCustom);
		provider.addEventListener('change', function () { sync(true); });
		sync(false);
	}

	/* Analytics: draw charts on <canvas> and export PNG ----------------- */
	var report = $('#pct-report');
	if (report) {
		var data = {};
		try { data = JSON.parse(report.getAttribute('data-report') || '{}'); } catch (err2) { data = {}; }
		var palette = ['#2271b1', '#00a32a', '#d63638', '#dba617', '#8c8f94', '#72aee6', '#1d2327', '#b32d2e'];
		function drawChart(canvas, chart) {
			var ctx = canvas.getContext('2d');
			var W = canvas.width, H = canvas.height, pad = 36;
			var labels = chart.labels || [], values = (chart.values || []).map(Number);
			var max = Math.max.apply(null, values.concat([1]));
			ctx.clearRect(0, 0, W, H);
			ctx.fillStyle = '#fff';
			ctx.fillRect(0, 0, W, H);
			ctx.font = '12px sans-serif';
			ctx.fillStyle = '#1d2327';
			if (chart.type === 'pie') {
				var total = values.reduce(function (a, b) { return a + b; }, 0) || 1;
				var start = -Math.PI / 2, cx = W / 3, cy = H / 2, r = Math.min(W / 3, H / 2) - 10;
				values.forEach(function (v, i) {
					var ang = (v / total) * Math.PI * 2;
					ctx.beginPath();
					ctx.moveTo(cx, cy);
					ctx.arc(cx, cy, r, start, start + ang);
					ctx.closePath();
					ctx.fillStyle = palette[i % palette.length];
					ctx.fill();
					start += ang;
					ctx.fillStyle = palette[i % palette.length];
					ctx.fillRect(W * 2 / 3 - 10, 20 + i * 18, 12, 12);
					ctx.fillStyle = '#1d2327';
					ctx.fillText(labels[i] + ' (' + v + ')', W * 2 / 3 + 8, 31 + i * 18);
				});
				return;
			}
			var n = Math.max(values.length, 1);
			var plotW = W - pad * 2, plotH = H - pad * 2;
			ctx.strokeStyle = '#c3c4c7';
			ctx.beginPath();
			ctx.moveTo(pad, pad); ctx.lineTo(pad, H - pad); ctx.lineTo(W - pad, H - pad);
			ctx.stroke();
			ctx.textAlign = 'right';
			ctx.fillText(String(max), pad - 4, pad + 4);
			ctx.fillText('0', pad - 4, H - pad + 4);
			ctx.textAlign = 'center';
			if (chart.type === 'line') {
				ctx.strokeStyle = palette[0];
				ctx.lineWidth = 2;
				ctx.beginPath();
				values.forEach(function (v, i) {
					var x = pad + (n === 1 ? plotW / 2 : (i / (n - 1)) * plotW);
					var y = H - pad - (v / max) * plotH;
					if (i === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
				});
				ctx.stroke();
				values.forEach(function (v, i) {
					var x = pad + (n === 1 ? plotW / 2 : (i / (n - 1)) * plotW);
					var y = H - pad - (v / max) * plotH;
					ctx.fillStyle = palette[0];
					ctx.beginPath(); ctx.arc(x, y, 3, 0, Math.PI * 2); ctx.fill();
					ctx.fillStyle = '#1d2327';
					if (n <= 12 || i % Math.ceil(n / 12) === 0) { ctx.fillText(String(labels[i]).slice(-5), x, H - pad + 14); }
				});
				return;
			}
			var bw = plotW / n * 0.7, gap = plotW / n * 0.3;
			values.forEach(function (v, i) {
				var x = pad + gap / 2 + i * (bw + gap);
				var h = (v / max) * plotH;
				ctx.fillStyle = palette[i % palette.length];
				ctx.fillRect(x, H - pad - h, bw, h);
				ctx.fillStyle = '#1d2327';
				ctx.fillText(String(v), x + bw / 2, H - pad - h - 4);
				ctx.fillText(String(labels[i]).slice(0, 14), x + bw / 2, H - pad + 14);
			});
		}
		$$('canvas[data-chart]', report).forEach(function (canvas) {
			var chart = (data.charts || [])[parseInt(canvas.getAttribute('data-chart'), 10)];
			if (chart) { drawChart(canvas, chart); }
		});
		var png = $('#pct-report-png');
		if (png) {
			png.addEventListener('click', function () {
				$$('canvas[data-chart]', report).forEach(function (canvas, i) {
					var a = document.createElement('a');
					a.download = 'pct-chart-' + (i + 1) + '.png';
					a.href = canvas.toDataURL('image/png');
					document.body.appendChild(a);
					a.click();
					a.remove();
				});
			});
		}
	}
})();
