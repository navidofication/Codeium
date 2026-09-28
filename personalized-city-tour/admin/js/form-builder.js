/* global pctFormBuilder, pctAdmin */
(function () {
	'use strict';

	var cfg = window.pctFormBuilder;
	var api = window.pctAdmin;
	if (!cfg || !api) { return; }

	var i18n = cfg.i18n;
	var root = document.getElementById('pct-form-builder');
	var status = document.getElementById('pct-fb-status');
	var jsonBox = document.getElementById('pct-fb-json');
	var schema = normalize(cfg.schema);
	var collapsed = {};

	/* ------------------------------------------------------------- utils */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (attrs[k] === undefined) { return; }
			if (k === 'text') { node.textContent = attrs[k]; }
			else if (k === 'html') { node.innerHTML = attrs[k]; }
			else if (k.indexOf('on') === 0) { node.addEventListener(k.slice(2), attrs[k]); }
			else if (k === 'checked' || k === 'selected' || k === 'disabled') { node[k] = !!attrs[k]; }
			else { node.setAttribute(k, attrs[k]); }
		});
		(children || []).forEach(function (c) { if (c) { node.appendChild(c); } });
		return node;
	}

	function slug(str) {
		return String(str || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
	}

	function normalize(s) {
		s = s && Array.isArray(s.sections) ? s : { sections: [] };
		s.sections = s.sections.map(function (sec, i) {
			return {
				id: sec.id || 'section_' + (i + 1),
				title: sec.title || '',
				description: sec.description || '',
				condition: sec.condition && sec.condition.field ? sec.condition : null,
				fields: (sec.fields || []).map(function (f, j) {
					return {
						id: f.id || (sec.id || 'section') + '_field_' + (j + 1),
						type: f.type || 'text',
						label: f.label || '',
						required: !!f.required,
						placeholder: f.placeholder || '',
						help: f.help || '',
						options: (f.options || []).map(function (o) {
							return typeof o === 'string' ? { value: o, label: o } : { value: o.value, label: o.label };
						}),
						min: f.min === undefined || f.min === null ? '' : f.min,
						max: f.max === undefined || f.max === null ? '' : f.max,
						step: f.step === undefined || f.step === null ? '' : f.step,
						condition: f.condition && f.condition.field ? f.condition : null
					};
				})
			};
		});
		return s;
	}

	function allFieldIds(exclude) {
		var ids = [];
		schema.sections.forEach(function (sec) {
			sec.fields.forEach(function (f) { if (f.id !== exclude) { ids.push({ id: f.id, label: f.label }); } });
		});
		return ids;
	}

	function hasOptions(type) {
		return ['select', 'radio', 'checkbox', 'priority'].indexOf(type) !== -1;
	}
	function hasRange(type) {
		return ['number', 'range'].indexOf(type) !== -1;
	}

	function setStatus(msg, isError) {
		status.textContent = msg;
		status.style.color = isError ? '#b32d2e' : '#00701a';
		if (!isError && msg) { setTimeout(function () { if (status.textContent === msg) { status.textContent = ''; } }, 4000); }
	}

	/* ---------------------------------------------------------- rendering */

	function render() {
		root.innerHTML = '';
		if (!schema.sections.length) {
			root.appendChild(el('p', { class: 'pct-fb-empty', text: i18n.addSection }));
		}
		schema.sections.forEach(function (sec, si) { root.appendChild(renderSection(sec, si)); });
		jsonBox.value = JSON.stringify(schema, null, 2);
	}

	function moveButtons(list, index, onMove) {
		return el('span', { class: 'pct-fb-btns' }, [
			el('button', { type: 'button', class: 'button', title: i18n.up, text: '↑', disabled: index === 0, onclick: function (e) { e.stopPropagation(); onMove(-1); } }),
			el('button', { type: 'button', class: 'button', title: i18n.down, text: '↓', disabled: index === list.length - 1, onclick: function (e) { e.stopPropagation(); onMove(1); } })
		]);
	}

	function swap(list, i, dir) {
		var j = i + dir;
		if (j < 0 || j >= list.length) { return; }
		var tmp = list[i]; list[i] = list[j]; list[j] = tmp;
		render();
	}

	function renderCondition(owner, label, excludeId) {
		var ids = allFieldIds(excludeId);
		var fieldSel = el('select', {}, [el('option', { value: '', text: i18n.always })].concat(ids.map(function (f) {
			return el('option', { value: f.id, text: f.label ? f.label + ' (' + f.id + ')' : f.id, selected: owner.condition && owner.condition.field === f.id });
		})));
		var opSel = el('select', {}, Object.keys(cfg.operators).map(function (k) {
			return el('option', { value: k, text: cfg.operators[k], selected: owner.condition && owner.condition.operator === k });
		}));
		var valIn = el('input', { type: 'text', placeholder: i18n.value, value: owner.condition ? (owner.condition.value || '') : '' });

		function sync() {
			if (!fieldSel.value) {
				owner.condition = null;
				opSel.disabled = valIn.disabled = true;
			} else {
				owner.condition = { field: fieldSel.value, operator: opSel.value, value: valIn.value };
				opSel.disabled = false;
				valIn.disabled = opSel.value === 'not_empty';
			}
		}
		fieldSel.addEventListener('change', sync);
		opSel.addEventListener('change', sync);
		valIn.addEventListener('input', sync);
		sync();

		return el('label', { class: 'pct-fb-wide' }, [
			el('span', { text: label }),
			el('span', { class: 'pct-fb-cond' }, [fieldSel, opSel, valIn])
		]);
	}

	function input(obj, key, attrs, onchange) {
		attrs = attrs || {};
		var node = el(attrs.tag || 'input', Object.assign({}, attrs, { value: undefined, tag: undefined, checked: undefined }));
		if (attrs.tag === 'textarea') { node.value = obj[key]; }
		else if (attrs.type === 'checkbox') { node.checked = !!obj[key]; }
		else { node.value = obj[key]; }
		node.addEventListener('input', function () {
			obj[key] = attrs.type === 'checkbox' ? node.checked : node.value;
			if (onchange) { onchange(node); }
		});
		return node;
	}

	function renderSection(sec, si) {
		var key = 's:' + si;
		var wrap = el('div', { class: 'pct-fb-section' + (collapsed[key] ? ' is-collapsed' : '') });
		var title = el('span', { class: 'pct-fb-title', text: sec.title || i18n.section });
		var count = el('span', { class: 'pct-muted', text: sec.fields.length + ' ' + i18n.fields });

		var head = el('div', { class: 'pct-fb-section-head', onclick: function () { collapsed[key] = !collapsed[key]; wrap.classList.toggle('is-collapsed'); } }, [
			title, count,
			moveButtons(schema.sections, si, function (dir) { swap(schema.sections, si, dir); }),
			el('button', { type: 'button', class: 'button button-link-delete', text: i18n.remove, onclick: function (e) {
				e.stopPropagation();
				if (window.confirm(api.i18n.confirm)) { schema.sections.splice(si, 1); render(); }
			} })
		]);

		var body = el('div', { class: 'pct-fb-section-body' });
		body.appendChild(el('div', { class: 'pct-fb-grid' }, [
			el('label', {}, [el('span', { text: i18n.title }), input(sec, 'title', { type: 'text' }, function () { title.textContent = sec.title || i18n.section; })]),
			el('label', {}, [el('span', { text: i18n.key }), input(sec, 'id', { type: 'text' }, function (n) { sec.id = slug(n.value); })]),
			el('label', { class: 'pct-fb-wide' }, [el('span', { text: i18n.description }), input(sec, 'description', { tag: 'textarea', rows: 2 })]),
			renderCondition(sec, i18n.sectionCondition, null)
		]));

		var list = el('div', { class: 'pct-fb-fields' });
		if (!sec.fields.length) { list.appendChild(el('p', { class: 'pct-fb-empty', text: i18n.addField })); }
		sec.fields.forEach(function (f, fi) { list.appendChild(renderField(sec, f, si, fi)); });
		body.appendChild(list);
		body.appendChild(el('p', {}, [el('button', { type: 'button', class: 'button', text: i18n.addField, onclick: function () {
			var n = sec.fields.length + 1;
			sec.fields.push({ id: slug(sec.id + '_field_' + n), type: 'text', label: i18n.newField, required: false, placeholder: '', help: '', options: [], min: '', max: '', step: '', condition: null });
			collapsed['f:' + si + ':' + (n - 1)] = false;
			render();
		} })]));

		wrap.appendChild(head);
		wrap.appendChild(body);
		return wrap;
	}

	function renderField(sec, f, si, fi) {
		var key = 'f:' + si + ':' + fi;
		if (collapsed[key] === undefined) { collapsed[key] = true; }
		var wrap = el('div', { class: 'pct-fb-field' + (collapsed[key] ? ' is-collapsed' : '') });
		var title = el('span', { class: 'pct-fb-title', text: f.label || i18n.newField });
		var code = el('code', { text: f.id });
		var typeTag = el('span', { class: 'pct-muted', text: cfg.types[f.type] || f.type });

		var head = el('div', { class: 'pct-fb-field-head', onclick: function () { collapsed[key] = !collapsed[key]; wrap.classList.toggle('is-collapsed'); } }, [
			title, code, typeTag,
			moveButtons(sec.fields, fi, function (dir) { swap(sec.fields, fi, dir); }),
			el('button', { type: 'button', class: 'button button-link-delete', text: i18n.remove, onclick: function (e) {
				e.stopPropagation();
				sec.fields.splice(fi, 1); render();
			} })
		]);

		var body = el('div', { class: 'pct-fb-field-body' });
		var grid = el('div', { class: 'pct-fb-grid' });

		var typeSel = el('select', {}, Object.keys(cfg.types).map(function (t) {
			return el('option', { value: t, text: cfg.types[t], selected: f.type === t });
		}));
		typeSel.addEventListener('change', function () { f.type = typeSel.value; render(); });

		grid.appendChild(el('label', {}, [el('span', { text: i18n.label }), input(f, 'label', { type: 'text' }, function () { title.textContent = f.label || i18n.newField; })]));
		grid.appendChild(el('label', {}, [el('span', { text: i18n.key }), input(f, 'id', { type: 'text' }, function (n) { f.id = slug(n.value); code.textContent = f.id; })]));
		grid.appendChild(el('label', {}, [el('span', { text: i18n.type }), typeSel]));
		grid.appendChild(el('label', { class: 'pct-fb-check' }, [input(f, 'required', { type: 'checkbox' }), el('span', { text: ' ' + i18n.required })]));
		grid.appendChild(el('label', {}, [el('span', { text: i18n.placeholder }), input(f, 'placeholder', { type: 'text' })]));
		grid.appendChild(el('label', {}, [el('span', { text: i18n.help }), input(f, 'help', { type: 'text' })]));

		if (hasOptions(f.type)) {
			var ta = el('textarea', { rows: Math.min(10, Math.max(3, f.options.length + 1)) });
			ta.value = f.options.map(function (o) { return o.value === o.label ? o.value : o.value + '|' + o.label; }).join('\n');
			ta.addEventListener('input', function () {
				f.options = ta.value.split('\n').map(function (line) {
					line = line.trim();
					if (!line) { return null; }
					var parts = line.split('|');
					var value = parts[0].trim();
					var label = (parts[1] || parts[0]).trim();
					return { value: value || slug(label), label: label || value };
				}).filter(Boolean);
			});
			grid.appendChild(el('label', { class: 'pct-fb-wide' }, [el('span', { text: i18n.options }), ta]));
		}
		if (hasRange(f.type)) {
			grid.appendChild(el('label', {}, [el('span', { text: i18n.min }), input(f, 'min', { type: 'number', step: 'any' })]));
			grid.appendChild(el('label', {}, [el('span', { text: i18n.max }), input(f, 'max', { type: 'number', step: 'any' })]));
			grid.appendChild(el('label', {}, [el('span', { text: i18n.step }), input(f, 'step', { type: 'number', step: 'any' })]));
		}
		grid.appendChild(renderCondition(f, i18n.condition, f.id));

		body.appendChild(grid);
		wrap.appendChild(head);
		wrap.appendChild(body);
		return wrap;
	}

	/* ------------------------------------------------------------ actions */

	function request(path, method, body) {
		return fetch(api.rest + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': api.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { throw new Error(j.message || r.statusText); }
				return j;
			});
		});
	}

	document.getElementById('pct-fb-save').addEventListener('click', function () {
		setStatus(i18n.saving);
		request('/admin/form-schema', 'POST', schema).then(function (saved) {
			schema = normalize(saved);
			render();
			setStatus(i18n.saved);
		}).catch(function (err) { setStatus(err.message, true); });
	});

	document.getElementById('pct-fb-reset').addEventListener('click', function () {
		if (!window.confirm(i18n.resetConfirm)) { return; }
		request('/admin/form-schema/reset', 'POST', {}).then(function (def) {
			schema = normalize(def);
			collapsed = {};
			render();
			setStatus(i18n.saved);
		}).catch(function (err) { setStatus(err.message, true); });
	});

	document.getElementById('pct-fb-add-section').addEventListener('click', function () {
		var n = schema.sections.length + 1;
		schema.sections.push({ id: 'section_' + n, title: i18n.newSection, description: '', condition: null, fields: [] });
		render();
		root.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'start' });
	});

	document.getElementById('pct-fb-expand').addEventListener('click', function () {
		Object.keys(collapsed).forEach(function (k) { collapsed[k] = false; });
		schema.sections.forEach(function (sec, si) {
			collapsed['s:' + si] = false;
			sec.fields.forEach(function (f, fi) { collapsed['f:' + si + ':' + fi] = false; });
		});
		render();
	});
	document.getElementById('pct-fb-collapse').addEventListener('click', function () {
		schema.sections.forEach(function (sec, si) {
			collapsed['s:' + si] = false;
			sec.fields.forEach(function (f, fi) { collapsed['f:' + si + ':' + fi] = true; });
		});
		render();
	});

	document.getElementById('pct-fb-import').addEventListener('click', function () {
		try {
			schema = normalize(JSON.parse(jsonBox.value));
			collapsed = {};
			render();
			setStatus('');
		} catch (err) {
			setStatus(err.message, true);
		}
	});

	render();
})();
