/* global pctConfig, L, google */
(function () {
	'use strict';

	if (typeof pctConfig === 'undefined') {
		return;
	}

	var cfg = pctConfig;
	var t = cfg.i18n;
	var root = document.querySelector('.pct-app');
	if (!root) {
		return;
	}
	var LS = {
		get: function (k) { try { return window.localStorage.getItem('pct_' + k); } catch (e) { return null; } },
		set: function (k, v) { try { if (v === null) { window.localStorage.removeItem('pct_' + k); } else { window.localStorage.setItem('pct_' + k, v); } } catch (e) { /* private mode */ } }
	};
	var lang = {
		list: (cfg.lang && cfg.lang.languages) || [],
		current: (cfg.lang && cfg.lang.current) || 'en',
		calendar: cfg.calendar || 'gregorian',
		rtl: !!cfg.rtl
	};
	var lastRender = null;

	/* ------------------------------------------------------------------ */
	/* helpers                                                             */
	/* ------------------------------------------------------------------ */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === undefined || v === null || v === false) {
					return;
				}
				if (k === 'class') {
					node.className = v;
				} else if (k === 'text') {
					node.textContent = v;
				} else if (k === 'html') {
					node.innerHTML = v;
				} else if (k.indexOf('on') === 0 && typeof v === 'function') {
					node.addEventListener(k.slice(2).toLowerCase(), v);
				} else if (k === 'checked' || k === 'disabled' || k === 'required' || k === 'hidden' || k === 'selected') {
					node[k] = !!v;
				} else if (k === 'value') {
					node.value = v;
				} else {
					node.setAttribute(k, v);
				}
			});
		}
		(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) {
				return;
			}
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(str).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
			var idx = pos ? parseInt(pos, 10) - 1 : i++;
			return args[idx] !== undefined ? args[idx] : '';
		});
	}

	function api(path, method, body, query) {
		var url = cfg.rest + path;
		if (query) {
			var qs = Object.keys(query).filter(function (k) {
				return query[k] !== undefined && query[k] !== '';
			}).map(function (k) {
				return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
			}).join('&');
			if (qs) {
				url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
			}
		}
		var opts = {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'X-PCT-LANG': lang.current }
		};
		var guideToken = LS.get('guide_token');
		if (guideToken) {
			opts.headers['X-PCT-GUIDE'] = guideToken;
		}
		var visitorToken = LS.get('visitor_token');
		if (visitorToken) {
			opts.headers['X-PCT-VISITOR'] = visitorToken;
		}
		if (body !== undefined) {
			if (typeof FormData !== 'undefined' && body instanceof FormData) {
				opts.body = body;
			} else {
				opts.headers['Content-Type'] = 'application/json';
				opts.body = JSON.stringify(body);
			}
		}
		return fetch(url, opts).then(function (res) {
			return res.text().then(function (txt) {
				var data = null;
				try {
					data = txt ? JSON.parse(txt) : null;
				} catch (e) {
					data = null;
				}
				if (!res.ok) {
					var err = new Error((data && data.message) || t.error);
					err.code = data && data.code;
					err.data = data && data.data;
					err.status = res.status;
					throw err;
				}
				return data;
			});
		});
	}

	function notice(msg, type) {
		return el('div', { class: 'pct-notice' + (type ? ' is-' + type : ''), role: type === 'error' ? 'alert' : 'status', text: msg });
	}

	function clear(node) {
		while (node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	function mount(view) {
		clear(root);
		root.appendChild(langBar());
		root.appendChild(view);
		if (root.getBoundingClientRect().top < 0) {
			root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	function rerender() {
		if (lastRender) {
			lastRender();
		}
	}

	/* ------------------------------------------------------------------ */
	/* language                                                            */
	/* ------------------------------------------------------------------ */

	function applyLanguage(code, data) {
		lang.current = code;
		lang.rtl = !!data.rtl;
		lang.calendar = data.calendar || 'gregorian';
		t = data.strings || t;
		cfg.i18n = t;
		if (data.form) {
			state.schema = data.form;
		}
		if (data.vibe !== undefined) {
			cfg.vibe = data.vibe;
		}
		if (guide.status && data.categories) {
			guide.status.categories = data.categories;
		}
		if (window.PCTDate) {
			cfg.calendar = lang.calendar;
		}
		root.setAttribute('dir', lang.rtl ? 'rtl' : 'ltr');
		root.setAttribute('lang', code);
		root.classList.toggle('is-rtl', lang.rtl);
		LS.set('lang', code);
		document.cookie = 'pct_lang=' + encodeURIComponent(code) + '; path=/; max-age=31536000; SameSite=Lax';
	}

	function switchLanguage(code, then) {
		if (code === lang.current && then) {
			then();
			return Promise.resolve();
		}
		root.classList.add('is-busy');
		return api('/i18n', 'GET', undefined, { lang: code }).then(function (data) {
			applyLanguage(code, data);
			root.classList.remove('is-busy');
			if (then) {
				then();
			} else {
				rerender();
			}
		}).catch(function () {
			root.classList.remove('is-busy');
			if (then) {
				then();
			}
		});
	}

	function langBar() {
		if (lang.list.length < 2) {
			return el('span');
		}
		var sel = el('select', { class: 'pct-lang-select', 'aria-label': t.language, onChange: function (e) { switchLanguage(e.target.value); } }, lang.list.map(function (l) {
			return el('option', { value: l.code, text: l.label, selected: l.code === lang.current });
		}));
		return el('div', { class: 'pct-lang-bar' }, [sel]);
	}

	function renderLanguage() {
		lastRender = renderLanguage;
		var view = el('div', { class: 'pct-langs' }, [
			el('h2', { text: t.chooseLanguage }),
			el('div', { class: 'pct-lang-grid' }, lang.list.map(function (l) {
				return el('button', { type: 'button', class: 'pct-lang-card' + (l.code === lang.current ? ' is-selected' : ''), dir: l.rtl ? 'rtl' : 'ltr', lang: l.code, onClick: function () {
					switchLanguage(l.code, function () {
						LS.set('lang_chosen', '1');
						bootDefault();
					});
				} }, [el('strong', { text: l.label })]);
			}))
		]);
		clear(root);
		root.appendChild(view);
	}

	function formatDistance(m) {
		if (m < 1000) {
			return Math.round(m) + ' m';
		}
		return (m / 1000).toFixed(m < 10000 ? 1 : 0) + ' km';
	}

	function formatDuration(sec) {
		var min = Math.round(sec / 60);
		if (min < 60) {
			return sprintf(t.minutes, min);
		}
		var h = Math.floor(min / 60);
		return h + ' h ' + (min % 60 ? (min % 60) + ' min' : '');
	}

	function haversine(a, b) {
		var R = 6371000;
		var dLat = (b[0] - a[0]) * Math.PI / 180;
		var dLng = (b[1] - a[1]) * Math.PI / 180;
		var la1 = a[0] * Math.PI / 180;
		var la2 = b[0] * Math.PI / 180;
		var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.sin(dLng / 2) * Math.sin(dLng / 2) * Math.cos(la1) * Math.cos(la2);
		return 2 * R * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
	}

	function hasCoords(s) {
		return s && s.lat && s.lng && !isNaN(parseFloat(s.lat)) && !isNaN(parseFloat(s.lng));
	}

	/* ------------------------------------------------------------------ */
	/* state                                                               */
	/* ------------------------------------------------------------------ */

	var state = {
		cities: null,
		schema: null,
		city: null,
		values: {},
		step: 0,
		errors: {},
		tour: null
	};

	/* ------------------------------------------------------------------ */
	/* entry                                                               */
	/* ------------------------------------------------------------------ */

	function renderEntry() {
		lastRender = renderEntry;
		var view = el('div', { class: 'pct-entry' }, [
			el('button', { type: 'button', class: 'pct-entry-card', onClick: startTraveler }, [
				el('strong', { text: t.traveler }),
				el('span', { class: 'pct-muted', text: t.travelerSub })
			]),
			el('button', { type: 'button', class: 'pct-entry-card', onClick: startGuide }, [
				el('strong', { text: t.guide }),
				el('span', { class: 'pct-muted', text: t.guideSub })
			]),
			cfg.vibe ? el('button', { type: 'button', class: 'pct-entry-card', onClick: startVibe }, [
				el('strong', { text: t.vibe }),
				el('span', { class: 'pct-muted', text: t.vibeSub })
			]) : null
		]);
		mount(view);
	}

	function header(title, onBack) {
		return el('div', { class: 'pct-head' }, [
			onBack ? el('button', { type: 'button', class: 'pct-btn pct-btn-sm', onClick: onBack, text: '‹ ' + t.back }) : null,
			el('h2', { text: title })
		]);
	}

	function backToEntry() {
		if (cfg.view === 'both') {
			renderEntry();
		} else if (cfg.view === 'guide') {
			startGuide();
		} else {
			startTraveler();
		}
	}

	/* ------------------------------------------------------------------ */
	/* traveler: city                                                      */
	/* ------------------------------------------------------------------ */

	function startTraveler() {
		if (!cfg.allowed) {
			mount(el('div', {}, [
				cfg.view === 'both' ? header(t.traveler, renderEntry) : null,
				el('div', { class: 'pct-notice' }, [
					el('a', { href: cfg.loginUrl, class: 'pct-btn pct-btn-primary', text: t.guideLogin })
				])
			]));
			return;
		}
		mount(el('div', { class: 'pct-loading', text: '…' }));
		Promise.all([
			state.cities ? Promise.resolve(state.cities) : api('/cities'),
			state.schema ? Promise.resolve(state.schema) : api('/form')
		]).then(function (res) {
			state.cities = res[0] || [];
			state.schema = res[1];
			if (cfg.cityId) {
				var pre = state.cities.filter(function (c) { return c.id === cfg.cityId; })[0];
				if (pre) {
					state.city = pre;
					state.step = 0;
					renderForm();
					return;
				}
			}
			renderCities();
		}).catch(function (e) {
			mount(el('div', {}, [header(t.traveler, cfg.view === 'both' ? renderEntry : null), notice(e.message, 'error')]));
		});
	}

	function renderCities() {
		lastRender = renderCities;
		var list = el('div', { class: 'pct-cities' });
		if (!state.cities.length) {
			list.appendChild(el('p', { class: 'pct-muted', text: t.noCities }));
		}
		state.cities.forEach(function (c) {
			list.appendChild(el('button', {
				type: 'button',
				class: 'pct-city' + (state.city && state.city.id === c.id ? ' is-selected' : ''),
				onClick: function () {
					state.city = c;
					state.step = 0;
					state.errors = {};
					renderForm();
				}
			}, [
				el('strong', { text: c.name }),
				c.country ? el('span', { class: 'pct-muted pct-small', text: c.country }) : null
			]));
		});
		mount(el('div', {}, [
			header(t.chooseCity, cfg.view === 'both' ? renderEntry : null),
			el('p', { class: 'pct-muted', text: t.chooseCitySub }),
			list
		]));
	}

	/* ------------------------------------------------------------------ */
	/* traveler: form                                                      */
	/* ------------------------------------------------------------------ */

	function condMet(cond) {
		if (!cond || !cond.field) {
			return true;
		}
		var v = state.values[cond.field];
		var want = String(cond.value === undefined ? '' : cond.value);
		var arr = Array.isArray(v) ? v.map(String) : null;
		switch (cond.operator) {
			case 'not_empty':
				return arr ? arr.length > 0 : (v !== undefined && v !== null && String(v) !== '');
			case 'contains':
				return arr ? arr.indexOf(want) !== -1 : String(v) === want;
			case 'not_contains':
			case 'not_equals':
				return arr ? arr.indexOf(want) === -1 : String(v === undefined ? '' : v) !== want;
			case 'gt':
				return v !== '' && v !== undefined && !isNaN(parseFloat(v)) && parseFloat(v) > parseFloat(want);
			case 'lt':
				return v !== '' && v !== undefined && !isNaN(parseFloat(v)) && parseFloat(v) < parseFloat(want);
			default:
				return arr ? arr.indexOf(want) !== -1 : String(v === undefined ? '' : v) === want;
		}
	}

	function visibleSections() {
		return state.schema.sections.filter(function (s) {
			return condMet(s.condition) && s.fields.length;
		});
	}

	function prefill() {
		if (cfg.userName && !state.values.name) {
			state.values.name = cfg.userName;
		}
		if (cfg.userEmail && !state.values.email) {
			state.values.email = cfg.userEmail;
		}
		state.schema.sections.forEach(function (s) {
			s.fields.forEach(function (f) {
				if (state.values[f.id] === undefined && (f.type === 'range') && f.min !== '') {
					state.values[f.id] = f.min;
				}
			});
		});
	}

	function isEmpty(v) {
		return Array.isArray(v) ? v.length === 0 : (v === undefined || v === null || String(v) === '');
	}

	function validateSection(section) {
		var errors = {};
		section.fields.forEach(function (f) {
			if (!condMet(f.condition)) {
				return;
			}
			var v = state.values[f.id];
			if (f.required && isEmpty(v)) {
				errors[f.id] = t.required;
			} else if (f.type === 'email' && !isEmpty(v) && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v))) {
				errors[f.id] = t.fixErrors;
			}
		});
		return errors;
	}

	function renderForm() {
		lastRender = renderForm;
		prefill();
		var sections = visibleSections();
		if (state.step >= sections.length) {
			state.step = sections.length - 1;
		}
		var section = sections[state.step];
		var total = sections.length;

		var form = el('form', { class: 'pct-form', novalidate: 'novalidate', onSubmit: function (e) { e.preventDefault(); goNext(); } });
		var fs = el('fieldset');
		fs.appendChild(el('legend', { class: 'pct-sr', text: section.title }));
		section.fields.forEach(function (f) {
			fs.appendChild(renderField(f));
		});
		form.appendChild(fs);

		var isLast = state.step === total - 1;
		form.appendChild(el('div', { class: 'pct-nav' }, [
			el('button', {
				type: 'button',
				class: 'pct-btn',
				text: t.previous,
				onClick: function () {
					if (state.step === 0) {
						cfg.cityId ? backToEntry() : renderCities();
					} else {
						state.step--;
						renderForm();
					}
				}
			}),
			el('button', { type: 'submit', class: 'pct-btn pct-btn-primary', text: isLast ? t.submit : t.next })
		]));

		var stepLabel = sprintf(t.step, state.step + 1, total);
		var view = el('div', {}, [
			header(section.title, null),
			el('p', { class: 'pct-muted pct-small', text: stepLabel + (state.city ? ' · ' + state.city.name : '') }),
			el('div', { class: 'pct-progress' }, [el('span', { style: 'width:' + Math.round(((state.step + 1) / total) * 100) + '%' })]),
			section.description ? el('p', { class: 'pct-muted', text: section.description }) : null,
			Object.keys(state.errors).length ? notice(t.fixErrors, 'error') : null,
			form
		]);
		mount(view);

		function goNext() {
			state.errors = validateSection(section);
			if (Object.keys(state.errors).length) {
				renderForm();
				var first = root.querySelector('.has-error');
				if (first) {
					first.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
				return;
			}
			var secs = visibleSections();
			if (state.step >= secs.length - 1) {
				submitTour();
			} else {
				state.step++;
				renderForm();
			}
		}
	}

	function setValue(id, v) {
		state.values[id] = v;
		delete state.errors[id];
		refreshConditionals();
	}

	function refreshConditionals() {
		root.querySelectorAll('[data-cond]').forEach(function (node) {
			var cond = JSON.parse(node.getAttribute('data-cond'));
			node.classList.toggle('pct-hidden', !condMet(cond));
		});
	}

	function renderField(f) {
		var wrap = el('div', {
			class: 'pct-field' + (state.errors[f.id] ? ' has-error' : ''),
			'data-field': f.id,
			'data-cond': f.condition && f.condition.field ? JSON.stringify(f.condition) : undefined
		});
		if (f.condition && f.condition.field && !condMet(f.condition)) {
			wrap.classList.add('pct-hidden');
		}
		var inputId = 'pct-f-' + f.id;
		var labelChildren = [f.label, f.required ? el('span', { class: 'pct-req', text: ' *', 'aria-hidden': 'true' }) : null];
		var grouped = ['radio', 'checkbox', 'priority', 'toggle'].indexOf(f.type) !== -1;
		wrap.appendChild(el(grouped ? 'div' : 'label', { class: grouped ? 'pct-label' : undefined, for: grouped ? undefined : inputId, id: inputId + '-label' }, labelChildren));
		if (f.description) {
			wrap.appendChild(el('p', { class: 'pct-help', text: f.description }));
		}

		var v = state.values[f.id];
		var control;
		switch (f.type) {
			case 'textarea':
				control = el('textarea', { id: inputId, rows: 3, value: v || '', placeholder: f.placeholder || undefined, onInput: function (e) { state.values[f.id] = e.target.value; } });
				break;
			case 'select':
				control = el('select', { id: inputId, onChange: function (e) { setValue(f.id, e.target.value); } }, [el('option', { value: '', text: t.labels.select })].concat(f.options.map(function (o) {
					return el('option', { value: o.value, text: o.label, selected: String(v) === String(o.value) });
				})));
				break;
			case 'radio':
				control = el('div', { class: 'pct-choices', role: 'radiogroup', 'aria-labelledby': inputId + '-label' }, f.options.map(function (o) {
					var checked = String(v) === String(o.value);
					var input = el('input', { type: 'radio', name: f.id, value: o.value, checked: checked, onChange: function () {
						setValue(f.id, o.value);
						control.querySelectorAll('.pct-choice').forEach(function (c) { c.classList.toggle('is-checked', c.querySelector('input').checked); });
					} });
					return el('label', { class: 'pct-choice' + (checked ? ' is-checked' : '') }, [input, o.label]);
				}));
				break;
			case 'checkbox':
				v = Array.isArray(v) ? v : [];
				control = el('div', { class: 'pct-choices', role: 'group', 'aria-labelledby': inputId + '-label' }, f.options.map(function (o) {
					var checked = v.map(String).indexOf(String(o.value)) !== -1;
					var input = el('input', { type: 'checkbox', name: f.id + '[]', value: o.value, checked: checked, onChange: function (e) {
						var cur = (state.values[f.id] || []).slice();
						if (e.target.checked) {
							cur.push(o.value);
						} else {
							cur = cur.filter(function (x) { return String(x) !== String(o.value); });
						}
						setValue(f.id, cur);
						e.target.parentNode.classList.toggle('is-checked', e.target.checked);
					} });
					return el('label', { class: 'pct-choice' + (checked ? ' is-checked' : '') }, [input, o.label]);
				}));
				break;
			case 'priority':
				control = renderPriority(f);
				break;
			case 'range': {
				var out = el('output', { text: v === undefined || v === '' ? (f.min || 0) : v });
				var range = el('input', { type: 'range', id: inputId, min: f.min !== '' ? f.min : 0, max: f.max !== '' ? f.max : 10, step: f.step || 1, value: v === undefined || v === '' ? (f.min || 0) : v, onInput: function (e) {
					out.textContent = e.target.value;
					state.values[f.id] = e.target.value;
				} });
				control = el('div', { class: 'pct-range' }, [range, out]);
				break;
			}
			case 'toggle':
				control = el('div', { class: 'pct-toggle', role: 'radiogroup', 'aria-labelledby': inputId + '-label' }, [['yes', t.labels.yes || 'Yes'], ['no', t.labels.no || 'No']].map(function (pair) {
					var checked = v === pair[0];
					var input = el('input', { type: 'radio', name: f.id, value: pair[0], checked: checked, onChange: function () {
						setValue(f.id, pair[0]);
						control.querySelectorAll('label').forEach(function (c) { c.classList.toggle('is-checked', c.querySelector('input').checked); });
					} });
					return el('label', { class: checked ? 'is-checked' : '' }, [input, pair[1]]);
				}));
				break;
			case 'number':
				control = renderStepper(f, inputId, v);
				break;
			case 'date':
				control = el('input', { type: 'text', id: inputId, value: v || '', onChange: function (e) { setValue(f.id, e.target.value); } });
				if (window.PCTDate) {
					// Attach after the node is in the DOM so the popover wrapper can be inserted.
					setTimeout(function () {
						if (control.parentNode) {
							window.PCTDate.attach(control, { calendar: lang.calendar });
						}
					}, 0);
				} else {
					control.type = 'date';
				}
				break;
			default:
				control = el('input', { type: f.type === 'email' ? 'email' : (f.type === 'tel' ? 'tel' : 'text'), id: inputId, value: v || '', placeholder: f.placeholder || undefined, autocomplete: f.id === 'name' ? 'name' : (f.type === 'email' ? 'email' : (f.type === 'tel' ? 'tel' : undefined)), onInput: function (e) { state.values[f.id] = e.target.value; } });
		}
		wrap.appendChild(control);
		if (f.help) {
			wrap.appendChild(el('p', { class: 'pct-help', text: f.help }));
		}
		if (state.errors[f.id]) {
			wrap.appendChild(el('p', { class: 'pct-error', text: state.errors[f.id] }));
		}
		return wrap;
	}

	/**
	 * Numeric field with explicit −/+ buttons. Each click changes the value by
	 * exactly one step; typing directly into the box is always allowed.
	 */
	function renderStepper(f, inputId, v) {
		var step = parseFloat(f.step) || 1;
		var min = f.min !== '' && f.min !== undefined ? parseFloat(f.min) : null;
		var max = f.max !== '' && f.max !== undefined ? parseFloat(f.max) : null;
		var input = el('input', { type: 'number', id: inputId, inputmode: 'numeric', min: min !== null ? min : undefined, max: max !== null ? max : undefined, step: step, value: v === undefined ? '' : v, placeholder: f.placeholder || undefined, onInput: function (e) {
			setValue(f.id, e.target.value);
		} });
		function clamp(n) {
			if (min !== null && n < min) { n = min; }
			if (max !== null && n > max) { n = max; }
			return Math.round(n * 1000) / 1000;
		}
		function bump(dir) {
			var cur = parseFloat(input.value);
			if (isNaN(cur)) {
				cur = min !== null ? min - (dir > 0 ? step : 0) : 0;
			}
			var next = clamp(cur + dir * step);
			input.value = next;
			setValue(f.id, String(next));
		}
		var minus = el('button', { type: 'button', class: 'pct-step', 'aria-label': t.decrease, text: '−', onClick: function (e) { e.preventDefault(); bump(-1); } });
		var plus = el('button', { type: 'button', class: 'pct-step', 'aria-label': t.increase, text: '+', onClick: function (e) { e.preventDefault(); bump(1); } });
		return el('div', { class: 'pct-stepper' }, [minus, input, plus]);
	}

	/* Ranked choice: pick options, then order them. */
	function renderPriority(f) {
		var selected = Array.isArray(state.values[f.id]) ? state.values[f.id].map(String) : [];
		var box = el('div', { class: 'pct-priority' });
		var chosenCol = el('div', { class: 'pct-priority-col' });
		var availCol = el('div', { class: 'pct-priority-col' });

		function labelFor(val) {
			var o = f.options.filter(function (x) { return String(x.value) === String(val); })[0];
			return o ? o.label : val;
		}

		function commit() {
			state.values[f.id] = selected.slice();
			delete state.errors[f.id];
			refreshConditionals();
			draw();
		}

		function move(val, dir) {
			var i = selected.indexOf(val);
			var j = i + dir;
			if (i === -1 || j < 0 || j >= selected.length) {
				return;
			}
			selected.splice(i, 1);
			selected.splice(j, 0, val);
			commit();
		}

		var dragging = null;

		function draw() {
			clear(chosenCol);
			clear(availCol);
			chosenCol.appendChild(el('h4', { text: (t.labels.selected || 'Your priorities') + ' (' + selected.length + ')' }));
			availCol.appendChild(el('h4', { text: t.labels.available || 'Available' }));
			selected.forEach(function (val, i) {
				var item = el('div', { class: 'pct-priority-item', draggable: 'true', title: t.dragHint, 'data-val': val }, [
					el('span', { class: 'pct-rank', text: String(i + 1) }),
					el('span', { text: labelFor(val) }),
					el('button', { type: 'button', 'aria-label': '↑', text: '↑', disabled: i === 0, onClick: function () { move(val, -1); } }),
					el('button', { type: 'button', 'aria-label': '↓', text: '↓', disabled: i === selected.length - 1, onClick: function () { move(val, 1); } }),
					el('button', { type: 'button', 'aria-label': t.close, text: '×', onClick: function () {
						selected = selected.filter(function (x) { return x !== val; });
						commit();
					} })
				]);
				item.addEventListener('dragstart', function () { dragging = val; item.classList.add('is-dragging'); });
				item.addEventListener('dragend', function () { dragging = null; item.classList.remove('is-dragging'); });
				item.addEventListener('dragover', function (e) { e.preventDefault(); });
				item.addEventListener('drop', function (e) {
					e.preventDefault();
					if (!dragging || dragging === val) {
						return;
					}
					var from = selected.indexOf(dragging);
					var to = selected.indexOf(val);
					selected.splice(from, 1);
					selected.splice(to, 0, dragging);
					commit();
				});
				chosenCol.appendChild(item);
			});
			f.options.forEach(function (o) {
				if (selected.indexOf(String(o.value)) !== -1) {
					return;
				}
				availCol.appendChild(el('button', { type: 'button', class: 'pct-choice', text: '+ ' + o.label, onClick: function () {
					selected.push(String(o.value));
					commit();
				} }));
			});
		}
		draw();
		box.appendChild(chosenCol);
		box.appendChild(availCol);
		return box;
	}

	/* ------------------------------------------------------------------ */
	/* traveler: submit + result                                           */
	/* ------------------------------------------------------------------ */

	function submitTour() {
		var steps = t.progressSteps || [];
		var list = el('ol', { class: 'pct-progress-steps' }, steps.map(function (s) { return el('li', { text: s }); }));
		var idx = 0;
		function tick() {
			list.querySelectorAll('li').forEach(function (li, i) {
				li.classList.toggle('is-done', i < idx);
				li.classList.toggle('is-active', i === idx);
			});
		}
		tick();
		var timer = setInterval(function () {
			if (idx < steps.length - 1) {
				idx++;
				tick();
			}
		}, 2500);
		mount(el('div', {}, [
			header(t.yourTour, null),
			el('div', { class: 'pct-loading', 'aria-live': 'polite', 'aria-busy': 'true' }, [el('p', { text: t.generating }), list])
		]));
		api('/tours', 'POST', { city_id: state.city.id, form: state.values }).then(function (res) {
			clearInterval(timer);
			state.tour = res;
			renderTour(res);
		}).catch(function (e) {
			clearInterval(timer);
			if (e.data && e.data.fields) {
				state.errors = e.data.fields;
				var secs = visibleSections();
				var firstBad = Object.keys(e.data.fields)[0];
				secs.forEach(function (s, i) {
					if (s.fields.some(function (f) { return f.id === firstBad; })) {
						state.step = i;
					}
				});
				renderForm();
				return;
			}
			mount(el('div', {}, [
				header(t.yourTour, function () { renderForm(); }),
				notice(e.message || t.error, 'error')
			]));
		});
	}

	function loadSharedTour(id, token) {
		mount(el('div', { class: 'pct-loading', text: '…' }));
		api('/tours/' + id, 'GET', undefined, { token: token }).then(function (res) {
			state.tour = res;
			renderTour(res);
		}).catch(function (e) {
			mount(el('div', {}, [notice(e.message, 'error')]));
			setTimeout(bootDefault, 1500);
		});
	}

	var tourMap = null; // active MapView instance

	function renderTour(res) {
		lastRender = function () { renderTour(res); };
		var result = res.result;
		var days = result.days || [];
		var active = 0;
		var visited = {};
		var city = result.city || {};

		var daysBox = el('aside', { class: 'pct-days', id: 'pct-days' });
		var backdrop = el('div', { class: 'pct-days-backdrop', onClick: function () { openMenu(false); } });
		var message = el('section', { class: 'pct-message', 'aria-live': 'polite' });
		var burger = el('button', { type: 'button', class: 'pct-hamburger', 'aria-label': t.menu, 'aria-controls': 'pct-days', 'aria-expanded': 'false', onClick: function () { openMenu(true); } }, [el('span'), el('span'), el('span')]);

		function openMenu(open) {
			daysBox.classList.toggle('is-open', open);
			backdrop.classList.toggle('is-open', open);
			burger.setAttribute('aria-expanded', open ? 'true' : 'false');
		}

		var nav = el('nav', { 'aria-label': t.days });
		function drawNav() {
			clear(nav);
			days.forEach(function (d, i) {
				nav.appendChild(el('button', { type: 'button', class: i === active ? 'is-active' : '', 'aria-current': i === active ? 'true' : undefined, onClick: function () {
					active = i;
					drawNav();
					drawDay();
					openMenu(false);
				} }, [
					el('strong', { text: sprintf(t.day, d.day) }),
					el('span', { class: 'pct-small', text: d.title.replace(/^[^—–-]*[—–-]\s*/, '') })
				]));
			});
		}

		var shareUrl = res.url || '';
		if (!shareUrl && res.id && res.token) {
			var u = new URL(window.location.href);
			u.searchParams.set('pct_tour', res.id);
			u.searchParams.set('pct_token', res.token);
			shareUrl = u.toString();
		}

		daysBox.appendChild(el('div', { class: 'pct-days-head' }, [
			el('span', { text: t.days }),
			el('button', { type: 'button', class: 'pct-days-close', 'aria-label': t.close, text: '×', onClick: function () { openMenu(false); } })
		]));
		daysBox.appendChild(nav);
		var foot = el('div', { class: 'pct-days-foot' });
		if (shareUrl) {
			var copyBtn = el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.copyLink, onClick: function () {
				var done = function () { copyBtn.textContent = t.copied; setTimeout(function () { copyBtn.textContent = t.copyLink; }, 2000); };
				if (navigator.clipboard) {
					navigator.clipboard.writeText(shareUrl).then(done, function () { window.prompt('', shareUrl); });
				} else {
					window.prompt('', shareUrl);
				}
			} });
			foot.appendChild(copyBtn);
		}
		if (cfg.allowed) {
			foot.appendChild(el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.startOver, onClick: function () {
				state.tour = null;
				state.step = 0;
				state.errors = {};
				if (tourMap) { tourMap.destroy(); tourMap = null; }
				if (window.history && cfg.tourId) {
					var clean = new URL(window.location.href);
					clean.searchParams.delete('pct_tour');
					clean.searchParams.delete('pct_token');
					window.history.replaceState({}, '', clean.toString());
					cfg.tourId = 0;
				}
				startTraveler();
			} }));
		}
		daysBox.appendChild(foot);

		function stopCard(s, idx, isAlt) {
			var tags = (s.tags || []).map(function (tag) { return el('span', { class: 'pct-tag', text: tag }); });
			if (s.accessible) {
				tags.push(el('span', { class: 'pct-tag is-access', text: t.accessOptions.wheelchair || 'Accessible' }));
			}
			if (s.acc_warning) {
				tags.push(el('span', { class: 'pct-tag is-warn', text: t.accessibility }));
			}
			var links = [];
			if (s.link) {
				links.push(el('a', { href: s.link, target: '_blank', rel: 'noopener', text: t.website }));
			}
			if (s.organizer_url || s.organizer) {
				links.push(s.organizer_url ? el('a', { href: s.organizer_url, target: '_blank', rel: 'noopener', text: t.organizer + ': ' + (s.organizer || '') }) : el('span', { class: 'pct-muted', text: t.organizer + ': ' + s.organizer }));
			}
			var gurl = s.gmaps_url || (hasCoords(s) ? 'https://www.google.com/maps/search/?api=1&query=' + s.lat + ',' + s.lng : '');
			if (gurl) {
				links.push(el('a', { href: gurl, target: '_blank', rel: 'noopener', text: t.openGoogle }));
			}
			var key = active + ':' + s.item_id;
			var card = el('li', { class: 'pct-stop' + (isAlt ? ' is-alt' : '') + (visited[key] ? ' is-visited' : ''), 'data-stop': idx }, [
				el('div', { class: 'pct-stop-num', text: isAlt ? '+' : String(idx + 1) }),
				el('div', {}, [
					el('div', { class: 'pct-stop-title' }, [
						el('strong', { text: s.name }),
						s.time ? el('span', { class: 'pct-stop-time', text: s.time + (s.duration ? ' · ' + sprintf(t.minutes, s.duration) : '') }) : null,
						s.start ? el('span', { class: 'pct-stop-time', text: s.start }) : null
					]),
					tags.length ? el('div', { class: 'pct-tags' }, tags) : null,
					s.summary ? el('p', { class: 'pct-stop-note pct-muted', text: s.summary }) : null,
					s.note ? el('p', { class: 'pct-stop-note', text: s.note }) : null,
					s.address ? el('p', { class: 'pct-stop-note pct-small pct-muted', text: s.address }) : null,
					links.length ? el('div', { class: 'pct-stop-links' }, links) : null
				])
			]);
			return card;
		}

		function drawDay() {
			var d = days[active];
			clear(message);
			if (!d) {
				message.appendChild(notice(t.noCities));
				return;
			}
			message.appendChild(el('h3', { text: d.title }));
			if (d.summary) {
				message.appendChild(el('p', { class: 'pct-summary', text: d.summary }));
			}
			message.appendChild(el('div', { class: 'pct-block' }, [
				el('h4', { text: t.stops }),
				el('ol', { class: 'pct-stops' }, d.stops.map(function (s, i) { return stopCard(s, i, false); }))
			]));
			if (d.alternatives && d.alternatives.length) {
				message.appendChild(el('div', { class: 'pct-block' }, [
					el('h4', { text: t.alternatives }),
					el('ul', { class: 'pct-stops' }, d.alternatives.map(function (s, i) { return stopCard(s, i, true); }))
				]));
			}
			if (d.accessibility_notes) {
				message.appendChild(el('div', { class: 'pct-block' }, [el('h4', { text: t.accessibility }), el('p', { text: d.accessibility_notes })]));
			}
			if (d.quick_search_hints && d.quick_search_hints.length) {
				var searchBase = city.search_url || '';
				message.appendChild(el('div', { class: 'pct-block' }, [
					el('h4', { text: t.hints }),
					el('div', { class: 'pct-hints' }, d.quick_search_hints.map(function (h) {
						var href = searchBase ? searchBase + (searchBase.indexOf('?') > -1 ? '&' : '?') + 's=' + encodeURIComponent(h) : 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(h + ' ' + (city.name || ''));
						return el('a', { href: href, target: '_blank', rel: 'noopener', text: h });
					}).concat((city.channels || []).map(function (c) {
						return el('a', { class: 'is-channel', href: c.url, target: '_blank', rel: 'noopener', text: c.label || c.url });
					})))
				]));
			}

			/* map */
			if (tourMap) {
				tourMap.destroy();
				tourMap = null;
			}
			var mapWrap = el('div', { class: 'pct-map-wrap' });
			message.appendChild(mapWrap);
			if (typeof L !== 'undefined') {
				tourMap = new MapView(mapWrap, d, result.city, {
					onVisited: function (stopIndex) {
						var s = d.stops[stopIndex];
						visited[active + ':' + s.item_id] = true;
						var card = message.querySelector('.pct-stops .pct-stop[data-stop="' + stopIndex + '"]');
						if (card) {
							card.classList.add('is-visited');
						}
					},
					onCurrent: function (stopIndex) {
						message.querySelectorAll('.pct-stops .pct-stop').forEach(function (c) {
							c.classList.toggle('is-current', parseInt(c.getAttribute('data-stop'), 10) === stopIndex && !c.classList.contains('is-alt'));
						});
					}
				});
			}
			var links = el('div', { class: 'pct-map-links' });
			if (city.gmaps_url) {
				links.appendChild(el('a', { href: city.gmaps_url, target: '_blank', rel: 'noopener', text: t.cityOnGoogle }));
			}
			if (d.map && d.map.google_url) {
				links.appendChild(el('a', { href: d.map.google_url, target: '_blank', rel: 'noopener', text: t.openGoogle }));
			}
			if (d.map && d.map.osm_url) {
				links.appendChild(el('a', { href: d.map.osm_url, target: '_blank', rel: 'noopener', text: t.openOsm }));
			}
			if (links.childNodes.length) {
				mapWrap.appendChild(links);
			}

			/* curated My Maps */
			if (city.mymaps && city.mymaps.length) {
				var mm = el('div', { class: 'pct-block pct-mymaps' }, [el('h4', { text: t.myMaps })]);
				city.mymaps.forEach(function (m) {
					mm.appendChild(el('p', {}, [el('a', { href: m.url, target: '_blank', rel: 'noopener', text: m.label || m.url })]));
					if (m.embed) {
						mm.appendChild(el('iframe', { src: m.embed, loading: 'lazy', title: m.label || t.myMaps, referrerpolicy: 'no-referrer-when-downgrade' }));
					}
				});
				message.appendChild(mm);
			}
		}

		drawNav();
		var layout = el('div', { class: 'pct-result' }, [
			el('div', { class: 'pct-result-head' }, [
				burger,
				el('h2', { text: result.title || (t.yourTour + (city.name ? ' · ' + city.name : '')) })
			]),
			daysBox,
			message,
			backdrop
		]);
		mount(layout);
		drawDay();
	}

	/* ------------------------------------------------------------------ */
	/* Leaflet map with preview + navigation modes                         */
	/* ------------------------------------------------------------------ */

	function MapView(container, day, city, hooks) {
		var self = this;
		this.day = day;
		this.city = city || {};
		this.hooks = hooks || {};
		this.stops = day.stops.filter(hasCoords).map(function (s) {
			return { idx: day.stops.indexOf(s), stop: s, latlng: [parseFloat(s.lat), parseFloat(s.lng)] };
		});
		this.mode = 'preview';
		this.watchId = null;
		this.you = null;
		this.accuracy = null;
		this.liveLine = null;   // blue visitor route (trimmed as the visitor moves)
		this.liveCoords = null; // full geometry of the current blue route
		this.liveKey = '';
		this.goto = null;       // saved place currently navigated to
		this.current = 0;
		this.visited = {};
		this.lastPos = null;
		this.placeMarkers = {};

		this.status = el('span', { class: 'pct-map-status', text: '' });
		this.btnPreview = el('button', { type: 'button', class: 'pct-btn pct-btn-sm is-active', text: t.preview, onClick: function () { self.setMode('preview'); } });
		this.btnNav = el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.navigate, onClick: function () { self.setMode('navigate'); } });
		container.appendChild(el('div', { class: 'pct-map-bar' }, [this.btnPreview, this.btnNav, this.status]));
		this.canvas = el('div', { class: 'pct-map', role: 'region', 'aria-label': t.preview });
		container.appendChild(this.canvas);
		container.appendChild(el('div', { class: 'pct-legend' }, [
			el('span', { class: 'pct-legend-item is-planned', text: t.plannedRoute }),
			el('span', { class: 'pct-legend-item is-live', text: t.yourRoute })
		]));
		this.panel = el('div', { class: 'pct-nav-panel pct-hidden' });
		container.appendChild(this.panel);
		this.places = el('div', { class: 'pct-places' });
		container.appendChild(this.places);

		this.map = L.map(this.canvas, { scrollWheelZoom: false });
		L.tileLayer(cfg.maps.tileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: cfg.maps.attribution || '', maxZoom: 19 }).addTo(this.map);

		this.markers = this.stops.map(function (s) {
			var first = s === self.stops[0];
			var icon = L.divIcon({ className: '', html: '<div class="pct-stop-marker' + (first ? ' is-start' : '') + '">' + (s.idx + 1) + '</div>', iconSize: [28, 28], iconAnchor: [14, 14] });
			var m = L.marker(s.latlng, { icon: icon, title: s.stop.name }).addTo(self.map);
			var popup = el('div', {}, [
				el('strong', { text: s.stop.name }),
				s.stop.time ? el('div', { text: s.stop.time }) : null,
				s.stop.address ? el('small', { text: s.stop.address }) : null,
				first ? el('div', {}, [el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.goToStart, onClick: function () {
					self.map.closePopup();
					self.setGoto('start', s.latlng, t.goToStart);
				} })]) : null
			]);
			m.bindPopup(popup);
			return m;
		});
		(day.alternatives || []).filter(hasCoords).forEach(function (s) {
			var icon = L.divIcon({ className: '', html: '<div class="pct-stop-marker is-alt">+</div>', iconSize: [28, 28], iconAnchor: [14, 14] });
			L.marker([parseFloat(s.lat), parseFloat(s.lng)], { icon: icon, title: s.name, opacity: 0.85 }).addTo(self.map).bindPopup('<strong>' + escapeHtml(s.name) + '</strong>');
		});

		// Planned tour route: green, static until the tour is re-generated.
		this.route = L.polyline(this.stops.map(function (s) { return s.latlng; }), { color: '#067647', weight: 4, opacity: 0.8, dashArray: '6 8' }).addTo(this.map);
		if (this.stops.length > 1) {
			this.map.fitBounds(this.route.getBounds(), { padding: [30, 30], animate: false });
		} else {
			this.map.setView(this.stops.length ? this.stops[0].latlng : [parseFloat(city.lat) || 0, parseFloat(city.lng) || 0], city.zoom || 13);
		}
		this.drawPlaces();
		this.fetchRoute();
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* Routing goes through the plugin's REST proxy (OSRM / Mapbox / Google) so no provider keys reach the browser. */
	MapView.prototype.routeBetween = function (points, mode) {
		return api('/route', 'POST', { points: points, mode: mode || (this.day.map && this.day.map.mode) || 'walking' }).then(function (r) {
			if (!r || !r.coordinates || r.coordinates.length < 2) {
				throw new Error('no route');
			}
			return r;
		});
	};

	MapView.prototype.fetchRoute = function () {
		var self = this;
		if (this.stops.length < 2) {
			return;
		}
		var straight = 0;
		for (var i = 1; i < this.stops.length; i++) {
			straight += haversine(this.stops[i - 1].latlng, this.stops[i].latlng);
		}
		this.status.textContent = formatDistance(straight);
		if (cfg.maps.routing === 'straight') {
			this.route.setStyle({ dashArray: null });
			return;
		}
		this.routeBetween(this.stops.map(function (s) { return s.latlng; })).then(function (r) {
			self.route.setLatLngs(r.coordinates);
			self.route.setStyle({ dashArray: null });
			if (r.distance) {
				self.status.textContent = sprintf(t.routeTotal, formatDistance(r.distance), formatDuration(r.duration));
			}
		}).catch(function () {
			self.status.textContent = t.routeFailed + ' · ' + formatDistance(straight);
		});
	};

	/* ---- device-scoped saved places (parking / bus / metro / accommodation) ---- */

	MapView.prototype.placesKey = function () {
		return 'places_' + (this.city.id || 0);
	};
	MapView.prototype.loadPlaces = function () {
		try { return JSON.parse(LS.get(this.placesKey()) || '{}') || {}; } catch (e) { return {}; }
	};
	MapView.prototype.savePlace = function (type, latlng) {
		var all = this.loadPlaces();
		if (latlng) {
			all[type] = { lat: latlng[0], lng: latlng[1], at: Date.now() };
		} else {
			delete all[type];
			if (this.goto && this.goto.type === type) {
				this.clearGoto();
			}
		}
		LS.set(this.placesKey(), JSON.stringify(all));
		this.drawPlaces();
	};
	MapView.prototype.drawPlaces = function () {
		var self = this;
		var types = t.placeTypes || {};
		var all = this.loadPlaces();
		clear(this.places);
		Object.keys(this.placeMarkers).forEach(function (k) { self.map.removeLayer(self.placeMarkers[k]); });
		this.placeMarkers = {};
		var rows = Object.keys(types).map(function (type) {
			var p = all[type];
			if (p) {
				var icon = L.divIcon({ className: '', html: '<div class="pct-place-marker" data-type="' + type + '">' + escapeHtml(types[type].charAt(0)) + '</div>', iconSize: [24, 24], iconAnchor: [12, 12] });
				self.placeMarkers[type] = L.marker([p.lat, p.lng], { icon: icon, title: types[type] }).addTo(self.map).bindPopup('<strong>' + escapeHtml(types[type]) + '</strong>');
			}
			var isGoto = self.goto && self.goto.type === type;
			return el('div', { class: 'pct-place' + (isGoto ? ' is-goto' : '') }, [
				el('span', { class: 'pct-place-name', text: types[type] }),
				el('span', { class: 'pct-place-actions' }, [
					el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.saveHere, onClick: function () {
						if (!self.lastPos) {
							self.flash(t.needLocation);
							return;
						}
						self.savePlace(type, self.lastPos);
						self.flash(t.saved);
					} }),
					p ? el('button', { type: 'button', class: 'pct-btn pct-btn-sm is-primary', text: t.goTo, onClick: function () {
						self.setGoto(type, [p.lat, p.lng], types[type]);
					} }) : null,
					p ? el('button', { type: 'button', class: 'pct-btn pct-btn-sm pct-btn-link', text: t.remove, onClick: function () { self.savePlace(type, null); } }) : null
				])
			]);
		});
		this.places.appendChild(el('details', { class: 'pct-places-box', open: this.goto ? true : undefined }, [
			el('summary', {}, [el('strong', { text: t.savedPlaces }), el('span', { class: 'pct-muted pct-small', text: ' ' + t.savedPlacesSub })]),
			el('div', { class: 'pct-place-list' }, rows),
			this.goto ? el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.backToTour, onClick: function () { self.clearGoto(); } }) : null
		]));
	};
	MapView.prototype.flash = function (msg) {
		var self = this;
		this.status.textContent = msg;
		clearTimeout(this.flashTimer);
		this.flashTimer = setTimeout(function () { if (self.lastPos) { self.onPosition(self.lastPos, self.lastAcc); } }, 2500);
	};
	MapView.prototype.setGoto = function (type, latlng, label) {
		this.goto = { type: type, latlng: latlng, label: label };
		this.resetLive();
		this.drawPlaces();
		if (this.mode !== 'navigate') {
			this.setMode('navigate');
		} else if (this.lastPos) {
			this.onPosition(this.lastPos, this.lastAcc);
		}
	};
	MapView.prototype.clearGoto = function () {
		this.goto = null;
		this.resetLive();
		this.drawPlaces();
		if (this.lastPos) {
			this.onPosition(this.lastPos, this.lastAcc);
		}
	};

	/* ---- modes ---- */

	MapView.prototype.setMode = function (mode) {
		var self = this;
		if (mode === this.mode) {
			return;
		}
		this.mode = mode;
		this.btnPreview.classList.toggle('is-active', mode === 'preview');
		this.btnNav.classList.toggle('is-active', mode === 'navigate');
		this.panel.classList.toggle('pct-hidden', mode !== 'navigate');

		if (mode === 'preview') {
			this.stopWatching();
			if (this.stops.length > 1) {
				this.map.fitBounds(this.route.getBounds(), { padding: [30, 30] });
			}
			return;
		}

		if (!navigator.geolocation) {
			this.status.textContent = t.noGeo;
			this.panel.textContent = t.noGeo;
			return;
		}
		this.status.textContent = t.locating;
		clear(this.panel);
		this.watchId = navigator.geolocation.watchPosition(function (pos) {
			self.onPosition([pos.coords.latitude, pos.coords.longitude], pos.coords.accuracy);
		}, function (err) {
			self.status.textContent = err.code === 1 ? t.geoDenied : t.noGeo;
			self.panel.textContent = self.status.textContent;
		}, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
	};

	MapView.prototype.stopWatching = function () {
		if (this.watchId !== null && navigator.geolocation) {
			navigator.geolocation.clearWatch(this.watchId);
		}
		this.watchId = null;
		this.resetLive();
	};

	MapView.prototype.resetLive = function () {
		if (this.liveLine) {
			this.map.removeLayer(this.liveLine);
			this.liveLine = null;
		}
		this.liveCoords = null;
		this.liveKey = '';
		this.followed = false;
	};

	/* ---- blue visitor route: routed from the live position, trimmed behind the visitor ---- */

	MapView.prototype.updateLive = function (latlng, target) {
		var self = this;
		var key = target.latlng.join(',');
		if (this.liveKey !== key) {
			this.resetLive();
			this.liveKey = key;
		}
		if (!this.liveLine) {
			this.liveLine = L.polyline([latlng, target.latlng], { color: '#1f5fbf', weight: 5, opacity: 0.85, dashArray: '2 8' }).addTo(this.map);
		}
		if (this.liveCoords) {
			// Drop everything behind the closest point on the route, then re-anchor on the visitor.
			var best = 0;
			var bestD = Infinity;
			for (var i = 0; i < this.liveCoords.length; i++) {
				var d = haversine(latlng, this.liveCoords[i]);
				if (d < bestD) {
					bestD = d;
					best = i;
				}
			}
			if (bestD > 60) {
				// Off the computed route: request a fresh one from here.
				this.liveCoords = null;
				this.liveLine.setLatLngs([latlng, target.latlng]).setStyle({ dashArray: '2 8' });
			} else {
				this.liveCoords = this.liveCoords.slice(best);
				this.liveLine.setLatLngs([latlng].concat(this.liveCoords.slice(1)));
				return;
			}
		} else {
			this.liveLine.setLatLngs([latlng, target.latlng]);
		}
		if (this.livePending || cfg.maps.routing === 'straight') {
			return;
		}
		this.livePending = true;
		this.routeBetween([latlng, target.latlng]).then(function (r) {
			self.livePending = false;
			if (self.liveKey !== key || !self.liveLine) {
				return;
			}
			self.liveCoords = r.coordinates;
			self.liveLine.setLatLngs([latlng].concat(r.coordinates.slice(1))).setStyle({ dashArray: null });
		}).catch(function () {
			self.livePending = false;
		});
	};

	MapView.prototype.onPosition = function (latlng, accuracy) {
		var self = this;
		this.lastPos = latlng;
		this.lastAcc = accuracy;
		if (!this.you) {
			var icon = L.divIcon({ className: '', html: '<div class="pct-you-marker"></div>', iconSize: [18, 18], iconAnchor: [9, 9] });
			this.you = L.marker(latlng, { icon: icon, title: t.youAreHere, zIndexOffset: 1000 }).addTo(this.map);
			this.accuracy = L.circle(latlng, { radius: accuracy || 0, color: '#1f5fbf', weight: 1, fillOpacity: 0.08 }).addTo(this.map);
		} else {
			this.you.setLatLng(latlng);
			this.accuracy.setLatLng(latlng).setRadius(accuracy || 0);
		}
		if (this.mode !== 'navigate') {
			return;
		}

		var target;
		var mode = (this.day.map && this.day.map.mode) || 'walking';
		if (this.goto) {
			target = { latlng: this.goto.latlng, label: this.goto.label, isPlace: true };
			if (haversine(latlng, target.latlng) < 40) {
				this.status.textContent = t.reached;
				this.clearGoto();
				return;
			}
		} else {
			// Advance to the next unvisited stop; mark stops reached within 40 m.
			while (this.current < this.stops.length && this.visited[this.current]) {
				this.current++;
			}
			var stopT = this.stops[this.current];
			if (!stopT) {
				this.status.textContent = t.arriveAll;
				clear(this.panel);
				this.panel.appendChild(el('strong', { text: t.arriveAll }));
				this.resetLive();
				return;
			}
			if (haversine(latlng, stopT.latlng) < 40) {
				this.markVisited(this.current);
				this.status.textContent = t.reached;
				this.resetLive();
				this.onPosition(latlng, accuracy);
				return;
			}
			target = { latlng: stopT.latlng, label: (stopT.idx + 1) + '. ' + stopT.stop.name, stop: stopT };
			if (this.hooks.onCurrent) {
				this.hooks.onCurrent(stopT.idx);
			}
		}

		var dist = haversine(latlng, target.latlng);
		// The blue route only guides the visitor to the tour start (or a saved
		// place); once on the tour the green planned route is followed.
		var approaching = target.isPlace || this.current === 0;
		if (approaching) {
			this.updateLive(latlng, target);
		} else {
			this.resetLive();
		}
		this.status.textContent = t.youAreHere + ' · ' + sprintf(t.distance, formatDistance(dist));
		clear(this.panel);
		this.panel.appendChild(el('div', {}, [
			el('div', { class: 'pct-small pct-muted', text: target.isPlace ? t.goTo : t.nextStop }),
			el('strong', { text: target.label }),
			el('div', { class: 'pct-dist', text: sprintf(t.distance, formatDistance(dist)) + (target.stop && target.stop.stop.time ? ' · ' + target.stop.stop.time : '') })
		]));
		if (target.stop) {
			this.panel.appendChild(el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.markVisited, onClick: function () {
				self.markVisited(self.current);
				self.resetLive();
				self.onPosition(latlng, accuracy);
			} }));
			if (this.current > 0 && this.stops[0]) {
				this.panel.appendChild(el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.goToStart, onClick: function () {
					self.setGoto('start', self.stops[0].latlng, t.goToStart);
				} }));
			}
		} else {
			this.panel.appendChild(el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.backToTour, onClick: function () { self.clearGoto(); } }));
		}
		this.panel.appendChild(el('a', { class: 'pct-btn pct-btn-sm', target: '_blank', rel: 'noopener', text: t.openGoogle, href: 'https://www.google.com/maps/dir/?api=1&origin=' + latlng[0] + ',' + latlng[1] + '&destination=' + target.latlng[0] + ',' + target.latlng[1] + '&travelmode=' + mode }));

		if (!this.followed) {
			this.followed = true;
			this.map.fitBounds(L.latLngBounds([latlng, target.latlng]), { padding: [40, 40], maxZoom: 17 });
		}
	};

	MapView.prototype.markVisited = function (i) {
		this.visited[i] = true;
		var m = this.markers[i];
		if (m) {
			m.setIcon(L.divIcon({ className: '', html: '<div class="pct-stop-marker is-visited">✓</div>', iconSize: [28, 28], iconAnchor: [14, 14] }));
		}
		if (this.hooks.onVisited) {
			this.hooks.onVisited(this.stops[i].idx);
		}
		this.followed = false;
	};

	MapView.prototype.destroy = function () {
		this.stopWatching();
		clearTimeout(this.flashTimer);
		this.map.remove();
	};

	/* ------------------------------------------------------------------ */
	/* local guide                                                         */
	/* ------------------------------------------------------------------ */

	var guide = { status: null, events: null };

	/* ------------------------------------------------------------------ */
	/* My Trip, My Vibe                                                    */
	/* ------------------------------------------------------------------ */

	var vibe = { mobile: LS.get('visitor_mobile') || '', requests: null, timer: null };

	function vibeHeader() {
		var h = header(t.vibe, backToEntry);
		if (LS.get('visitor_token')) {
			h.appendChild(el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.guideForget, onClick: function () {
				LS.set('visitor_token', null);
				LS.set('visitor_mobile', null);
				vibe.requests = null;
				startVibe();
			} }));
		}
		return h;
	}

	function startVibe() {
		lastRender = startVibe;
		clearTimeout(vibe.timer);
		if (!cfg.vibe) {
			mount(el('div', {}, [header(t.vibe, backToEntry), notice(t.error, 'error')]));
			return;
		}
		if (LS.get('visitor_token')) {
			renderVibeHome();
			return;
		}
		var mobile = el('input', { type: 'tel', dir: 'ltr', placeholder: t.mobileHint, autocomplete: 'tel', value: vibe.mobile });
		var code = el('input', { type: 'text', inputmode: 'numeric', dir: 'ltr', autocomplete: 'one-time-code', maxlength: 6 });
		var msg = el('div');
		var codeWrap = el('div', { class: 'pct-hidden' }, [
			el('label', {}, [el('span', { text: t.code }), code]),
			el('button', { type: 'button', class: 'pct-btn pct-btn-primary', text: t.verify, onClick: function () {
				api('/vibe/otp/verify', 'POST', { mobile: mobile.value, code: code.value }).then(function (r) {
					LS.set('visitor_token', r.token);
					LS.set('visitor_mobile', r.mobile);
					vibe.mobile = r.mobile;
					renderVibeHome();
				}).catch(function (e) {
					clear(msg);
					msg.appendChild(notice(e.message || t.error, 'error'));
				});
			} })
		]);
		var sendBtn = el('button', { type: 'button', class: 'pct-btn', text: t.sendCode, onClick: function () {
			sendBtn.disabled = true;
			api('/vibe/otp', 'POST', { mobile: mobile.value }).then(function (r) {
				clear(msg);
				msg.appendChild(notice(t.vibeOtpSent + (r && r.dev_code ? ' (sandbox: ' + r.dev_code + ')' : ''), 'ok'));
				codeWrap.classList.remove('pct-hidden');
				code.focus();
			}).catch(function (e) {
				clear(msg);
				msg.appendChild(notice(e.message || t.error, 'error'));
			}).then(function () { sendBtn.disabled = false; });
		} });
		mount(el('div', { class: 'pct-vibe' }, [
			header(t.vibe, backToEntry),
			el('p', { class: 'pct-muted', text: t.vibeSub }),
			cfg.vibe.fee ? el('p', { class: 'pct-fee', text: cfg.vibe.fee }) : null,
			el('h3', { text: t.vibeOtpTitle }),
			el('div', { class: 'pct-otp' }, [
				el('label', {}, [el('span', { text: t.mobile }), mobile]),
				sendBtn
			]),
			codeWrap,
			msg
		]));
	}

	function choiceGroup(name, options, current, onPick) {
		return el('div', { class: 'pct-choices', role: 'radiogroup' }, (options || []).map(function (o) {
			var input = el('input', { type: 'radio', name: name, value: o, checked: o === current, onChange: function (e) {
				onPick(o);
				e.target.parentNode.parentNode.querySelectorAll('.pct-choice').forEach(function (c) { c.classList.toggle('is-checked', c.querySelector('input').checked); });
			} });
			return el('label', { class: 'pct-choice' + (o === current ? ' is-checked' : '') }, [input, o]);
		}));
	}

	function renderVibeHome() {
		lastRender = renderVibeHome;
		var v = cfg.vibe;
		var picked = { vibe: (v.vibes || [])[0] || '', style: (v.styles || [])[0] || '', subject: (v.subjects || [])[0] || '' };
		var msg = el('div');
		var name = el('input', { type: 'text', autocomplete: 'name' });
		var email = el('input', { type: 'email', autocomplete: 'email' });
		var citySel = el('select', {}, [el('option', { value: '', text: t.labels.select })]);
		(state.cities ? Promise.resolve(state.cities) : api('/cities').then(function (c) { state.cities = c; return c; })).then(function (cities) {
			cities.forEach(function (c) { citySel.appendChild(el('option', { value: c.id, text: c.name })); });
		}).catch(function () {});
		var photo = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', required: true });
		var preview = el('img', { class: 'pct-photo-preview pct-hidden', alt: '' });
		photo.addEventListener('change', function () {
			var f = photo.files && photo.files[0];
			if (!f) {
				preview.classList.add('pct-hidden');
				return;
			}
			preview.src = URL.createObjectURL(f);
			preview.classList.remove('pct-hidden');
		});
		var notes = el('textarea', { rows: 3 });
		var consent = el('input', { type: 'checkbox', required: true });
		var submit = el('button', { type: 'submit', class: 'pct-btn pct-btn-primary', text: t.vibeSubmit });
		var form = el('form', { class: 'pct-form pct-vibe-form', onSubmit: function (e) {
			e.preventDefault();
			if (!photo.files || !photo.files[0] || !consent.checked) {
				return;
			}
			var fd = new FormData();
			fd.append('photo', photo.files[0]);
			fd.append('name', name.value);
			fd.append('email', email.value);
			fd.append('city_id', citySel.value);
			fd.append('city', citySel.options[citySel.selectedIndex] ? citySel.options[citySel.selectedIndex].text : '');
			fd.append('vibe', picked.vibe);
			fd.append('style', picked.style);
			fd.append('subject', picked.subject);
			fd.append('notes', notes.value);
			submit.disabled = true;
			submit.textContent = t.saving;
			api('/vibe/requests', 'POST', fd).then(function (r) {
				clear(msg);
				msg.appendChild(notice(t.vibeSent, 'ok'));
				form.reset();
				preview.classList.add('pct-hidden');
				vibe.requests = [r].concat(vibe.requests || []);
				drawRequests();
				msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}).catch(function (err) {
				clear(msg);
				if (err.status === 401) {
					LS.set('visitor_token', null);
					startVibe();
					return;
				}
				msg.appendChild(notice(err.message || t.error, 'error'));
			}).then(function () {
				submit.disabled = false;
				submit.textContent = t.vibeSubmit;
			});
		} }, [
			el('div', { class: 'pct-field' }, [el('label', { text: t.photo }), photo, el('p', { class: 'pct-help', text: sprintf(t.photoHint, v.maxMb || 8) }), preview]),
			el('div', { class: 'pct-field' }, [el('span', { class: 'pct-label', text: t.vibeChoice }), choiceGroup('pct-vibe', v.vibes, picked.vibe, function (o) { picked.vibe = o; })]),
			el('div', { class: 'pct-field' }, [el('span', { class: 'pct-label', text: t.styleChoice }), choiceGroup('pct-style', v.styles, picked.style, function (o) { picked.style = o; })]),
			el('div', { class: 'pct-field' }, [el('span', { class: 'pct-label', text: t.subjectChoice }), choiceGroup('pct-subject', v.subjects, picked.subject, function (o) { picked.subject = o; })]),
			el('div', { class: 'pct-grid-2' }, [
				el('div', { class: 'pct-field' }, [el('label', { text: t.labels.name }), name]),
				el('div', { class: 'pct-field' }, [el('label', { text: t.labels.email + ' (' + t.optional + ')' }), email])
			]),
			el('div', { class: 'pct-field' }, [el('label', { text: t.labels.city }), citySel]),
			el('div', { class: 'pct-field' }, [el('label', { text: t.notes }), notes]),
			el('label', { class: 'pct-consent' }, [consent, ' ' + t.consent]),
			el('div', {}, [submit])
		]);

		var list = el('ul', { class: 'pct-requests' });
		function drawRequests() {
			clear(list);
			if (!vibe.requests) {
				list.appendChild(el('li', { class: 'pct-muted', text: '…' }));
				return;
			}
			if (!vibe.requests.length) {
				list.appendChild(el('li', { class: 'pct-muted', text: t.noRequests }));
				return;
			}
			vibe.requests.forEach(function (r) {
				var actions = [];
				if (r.image_url) {
					actions.push(el('a', { class: 'pct-btn pct-btn-sm', href: r.image_url, download: '', target: '_blank', rel: 'noopener', text: t.download }));
					if (navigator.share) {
						actions.push(el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.share, onClick: function () {
							navigator.share({ title: t.vibe, url: r.image_url }).catch(function () {});
						} }));
					}
				}
				list.appendChild(el('li', { class: 'pct-request is-' + r.status }, [
					r.image_url ? el('img', { src: r.image_url, alt: r.vibe + ' · ' + r.style, class: 'pct-request-img' }) : null,
					el('div', { class: 'pct-request-body' }, [
						el('strong', { text: [r.vibe, r.style].filter(Boolean).join(' · ') }),
						el('span', { class: 'pct-badge is-' + r.status, text: r.label || r.status }),
						r.error ? el('p', { class: 'pct-error', text: r.error }) : null,
						(r.status === 'pending' || r.status === 'approved' || r.status === 'generating') ? el('div', { class: 'pct-progress-bar' }, [el('span')]) : null,
						el('div', { class: 'pct-request-actions' }, actions)
					])
				]));
			});
		}
		function loadRequests() {
			return api('/vibe/requests').then(function (rows) {
				vibe.requests = rows;
				drawRequests();
				var busy = rows.some(function (r) { return r.status === 'pending' || r.status === 'approved' || r.status === 'generating'; });
				clearTimeout(vibe.timer);
				if (busy && lastRender === renderVibeHome) {
					vibe.timer = setTimeout(loadRequests, 15000);
				}
			}).catch(function (err) {
				if (err.status === 401) {
					LS.set('visitor_token', null);
					startVibe();
				}
			});
		}

		mount(el('div', { class: 'pct-vibe' }, [
			vibeHeader(),
			el('p', { class: 'pct-muted', text: t.vibeSub }),
			v.fee ? el('p', { class: 'pct-fee', text: v.fee }) : null,
			msg,
			el('h3', { text: t.vibeForm }),
			form,
			el('div', { class: 'pct-block' }, [
				el('div', { class: 'pct-head' }, [el('h3', { text: t.myRequests }), el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.refresh, onClick: loadRequests })]),
				list
			])
		]));
		drawRequests();
		loadRequests();
	}

	/* ------------------------------------------------------------------ */
	/* local guide (no login required)                                     */
	/* ------------------------------------------------------------------ */

	function startGuide() {
		lastRender = startGuide;
		mount(el('div', { class: 'pct-loading', text: '…' }));
		api('/guide/status').then(function (s) {
			guide.status = s;
			if (s.is_guide) {
				return api('/guide/events').then(function (ev) {
					guide.events = ev;
					renderGuideDashboard();
				});
			}
			renderGuideApply();
		}).catch(function (e) {
			mount(el('div', {}, [header(t.guideIntro, cfg.view === 'both' ? renderEntry : null), notice(e.message, 'error')]));
		});
	}

	function forgetGuideDevice() {
		LS.set('guide_token', null);
		guide.status = null;
		guide.events = null;
		startGuide();
	}

	function guideHeader() {
		var s = guide.status;
		var h = header(t.guideIntro, cfg.view === 'both' ? renderEntry : null);
		if (s.logged_in) {
			h.appendChild(el('span', { class: 'pct-muted pct-small', text: sprintf(t.guideWelcome, s.display_name) }));
			h.appendChild(el('a', { href: s.logout_url, class: 'pct-btn pct-btn-sm', text: t.guideLogout }));
		} else if (s.registered) {
			h.appendChild(el('span', { class: 'pct-muted pct-small', text: sprintf(t.guideWelcome, s.display_name) }));
			h.appendChild(el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.guideForget, onClick: forgetGuideDevice }));
		}
		return h;
	}

	function guideStatusBox() {
		var s = guide.status;
		if (!s.registered && !s.logged_in) {
			return null;
		}
		var verified = s.verified;
		return el('div', { class: 'pct-status-box' }, [
			el('span', { class: 'pct-muted pct-small', text: t.guideStatus }),
			el('span', { class: 'pct-badge is-' + (verified ? 'publish' : 'pending'), text: verified ? t.guideApproved : t.pending }),
			verified ? null : el('p', { class: 'pct-small pct-muted', text: t.guidePendingSub })
		]);
	}

	function cityOptions(selected) {
		var cities = guide.status.cities || {};
		return [el('option', { value: '', text: t.labels.select })].concat(Object.keys(cities).map(function (id) {
			return el('option', { value: id, text: cities[id], selected: String(selected) === String(id) });
		}));
	}

	function renderGuideApply() {
		lastRender = renderGuideApply;
		var s = guide.status;
		var view = el('div', {}, [guideHeader()]);
		if (s.status === 'revoked') {
			view.appendChild(notice(t.guideRevoked, 'error'));
			view.appendChild(el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.guideForget, onClick: forgetGuideDevice }));
			mount(view);
			return;
		}
		if (s.logged_in && s.status === 'pending') {
			view.appendChild(notice(t.guidePending));
			mount(view);
			return;
		}
		if (!s.can_apply) {
			view.appendChild(notice(t.guideClosed));
			mount(view);
			return;
		}

		var fields = {};
		function field(id, label, control, wide) {
			control.id = 'pct-g-' + id;
			fields[id] = control;
			return el('div', { class: 'pct-field' + (wide ? ' pct-wide' : '') }, [el('label', { for: control.id, text: label }), control]);
		}
		var msg = el('div');
		var btn = el('button', { type: 'submit', class: 'pct-btn pct-btn-primary', text: t.apply });
		var form = el('form', { class: 'pct-form pct-guide-grid', onSubmit: function (e) {
			e.preventDefault();
			var body = {};
			Object.keys(fields).forEach(function (k) { body[k] = fields[k].value; });
			btn.disabled = true;
			btn.textContent = t.saving;
			api('/guide/apply', 'POST', body).then(function (r) {
				clear(msg);
				if (r.token) {
					LS.set('guide_token', r.token);
				}
				startGuide();
			}).catch(function (err) {
				clear(msg);
				msg.appendChild(notice(err.message, 'error'));
				btn.disabled = false;
				btn.textContent = t.apply;
			});
		} });
		if (!s.logged_in) {
			form.appendChild(field('name', t.labels.name, el('input', { type: 'text', required: true, autocomplete: 'name' })));
			var mobileInput = el('input', { type: 'tel', dir: 'ltr', required: !!s.require_mobile, autocomplete: 'tel', placeholder: t.mobileHint });
			form.appendChild(field('mobile', t.mobile + (s.require_mobile ? '' : ' (' + t.optional + ')'), mobileInput));
			form.appendChild(field('email', t.labels.email + ' (' + t.optional + ')', el('input', { type: 'email', autocomplete: 'email' })));
		} else {
			form.appendChild(field('mobile', t.mobile, el('input', { type: 'tel', dir: 'ltr', required: !!s.require_mobile, autocomplete: 'tel', placeholder: t.mobileHint })));
		}
		form.appendChild(field('city_id', t.labels.city, el('select', {}, cityOptions(''))));
		form.appendChild(field('languages', t.labels.languages, el('input', { type: 'text' })));
		form.appendChild(field('note', t.labels.note, el('textarea', { rows: 4 }), true));
		form.appendChild(el('div', { class: 'pct-wide' }, [btn]));

		view.appendChild(el('h3', { text: s.logged_in ? t.guideApplyTitle : t.guideRegister }));
		view.appendChild(el('p', { class: 'pct-muted', text: s.logged_in ? t.guideApplySub : t.guideRegisterSub }));
		view.appendChild(msg);
		view.appendChild(form);
		mount(view);
	}

	function renderGuideDashboard() {
		lastRender = renderGuideDashboard;
		var s = guide.status;
		var view = el('div', {}, [guideHeader()]);
		var msg = el('div');
		var statusBox = guideStatusBox();
		if (statusBox) {
			view.appendChild(statusBox);
		}
		view.appendChild(msg);

		/* my events */
		var list = el('ul', { class: 'pct-events' });
		function drawEvents() {
			clear(list);
			if (!guide.events.length) {
				list.appendChild(el('li', { class: 'pct-muted', text: t.noEvents }));
			}
			guide.events.forEach(function (ev) {
				var badgeText = ev.status === 'publish' ? t.published : (ev.status === 'pending' ? t.pending : t.draft);
				list.appendChild(el('li', {}, [
					el('strong', { text: ev.title }),
					el('span', { class: 'pct-muted pct-small', text: [ev.city, ev.start].filter(Boolean).join(' · ') }),
					el('span', { class: 'pct-badge is-' + ev.status, text: badgeText }),
					el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: t.delete, onClick: function () {
						if (!window.confirm(t.confirmDelete)) {
							return;
						}
						api('/guide/events/' + ev.id, 'DELETE').then(function () {
							guide.events = guide.events.filter(function (x) { return x.id !== ev.id; });
							drawEvents();
						}).catch(function (err) {
							clear(msg);
							msg.appendChild(notice(err.message, 'error'));
						});
					} })
				]));
			});
		}
		drawEvents();

		/* new event form */
		var f = {};
		function field(id, label, control, wide) {
			control.id = 'pct-e-' + id;
			f[id] = control;
			return el('div', { class: 'pct-field' + (wide ? ' pct-wide' : '') }, [el('label', { for: control.id, text: label }), control]);
		}
		var form = el('form', { class: 'pct-form pct-guide-grid' });
		form.appendChild(field('title', t.labels.title, el('input', { type: 'text', required: true }), true));
		form.appendChild(field('city_id', t.labels.city, el('select', { required: true }, cityOptions(''))));
		var catSel = el('div', { class: 'pct-choices' }, Object.keys(s.categories || {}).map(function (slug) {
			var input = el('input', { type: 'checkbox', value: slug, onChange: function (e) { e.target.parentNode.classList.toggle('is-checked', e.target.checked); } });
			return el('label', { class: 'pct-choice' }, [input, s.categories[slug]]);
		}));
		form.appendChild(el('div', { class: 'pct-field' }, [el('span', { class: 'pct-label', text: t.labels.categories }), catSel]));
		var startInput = el('input', { type: 'text', required: true, dir: 'ltr', autocomplete: 'off' });
		var endInput = el('input', { type: 'text', dir: 'ltr', autocomplete: 'off' });
		form.appendChild(field('start', t.labels.start, startInput));
		form.appendChild(field('end', t.labels.end, endInput));
		form.appendChild(field('description', t.labels.description, el('textarea', { rows: 4 }), true));
		form.appendChild(field('organizer_name', t.labels.organizer_name, el('input', { type: 'text' })));
		form.appendChild(field('organizer_mobile', t.organizerMobile + (s.registered || !s.require_mobile ? ' (' + t.optional + ')' : ''), el('input', { type: 'tel', dir: 'ltr', autocomplete: 'tel', placeholder: t.mobileHint, required: !!s.require_mobile && !s.registered })));
		form.appendChild(field('organizer_url', t.labels.organizer_url, el('input', { type: 'url', placeholder: 'https://' })));
		form.appendChild(field('event_url', t.labels.event_url, el('input', { type: 'url', placeholder: 'https://' }), true));
		form.appendChild(field('gmaps_url', t.openGoogle, el('input', { type: 'url', dir: 'ltr', placeholder: 'https://maps.google.com/…' }), true));
		form.appendChild(field('venue', t.labels.venue, el('input', { type: 'text' })));
		form.appendChild(field('price_tier', t.labels.price_tier, el('select', {}, [el('option', { value: '', text: t.labels.select })].concat(Object.keys(t.priceTiers).map(function (k) {
			return el('option', { value: k, text: t.priceTiers[k] });
		})))));
		var accSel = el('div', { class: 'pct-choices' }, Object.keys(t.accessOptions).map(function (k) {
			var input = el('input', { type: 'checkbox', value: k, onChange: function (e) { e.target.parentNode.classList.toggle('is-checked', e.target.checked); } });
			return el('label', { class: 'pct-choice' }, [input, t.accessOptions[k]]);
		}));
		form.appendChild(el('div', { class: 'pct-field pct-wide' }, [el('span', { class: 'pct-label', text: t.labels.accessibility }), accSel]));

		/* location: address search + map click */
		var lat = el('input', { type: 'hidden' });
		var lng = el('input', { type: 'hidden' });
		var address = el('input', { type: 'text', placeholder: t.labels.address });
		var findBtn = el('button', { type: 'button', class: 'pct-btn', text: t.find });
		var geoResults = el('div', { class: 'pct-geo-results' });
		var pickMap = el('div', { class: 'pct-pick-map' });
		var locWrap = el('div', { class: 'pct-field pct-wide' }, [
			el('label', { for: 'pct-e-address', text: t.labels.location }),
			el('div', { style: 'display:flex;gap:8px' }, [address, findBtn]),
			geoResults,
			el('p', { class: 'pct-help', text: t.mapHint }),
			pickMap,
			lat,
			lng
		]);
		address.id = 'pct-e-address';
		f.address = address;
		f.lat = lat;
		f.lng = lng;
		form.appendChild(locWrap);

		var leaflet = null;
		var marker = null;
		function placeMarker(ll) {
			lat.value = ll[0].toFixed(6);
			lng.value = ll[1].toFixed(6);
			if (!marker) {
				marker = L.marker(ll, { draggable: true }).addTo(leaflet);
				marker.on('dragend', function () { var p = marker.getLatLng(); placeMarker([p.lat, p.lng]); });
			} else {
				marker.setLatLng(ll);
			}
		}
		function initMap() {
			if (leaflet || typeof L === 'undefined') {
				return;
			}
			leaflet = L.map(pickMap, { scrollWheelZoom: false }).setView([48.8566, 2.3522], 4);
			L.tileLayer(cfg.maps.tileUrl || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: cfg.maps.attribution || '', maxZoom: 19 }).addTo(leaflet);
			leaflet.on('click', function (e) { placeMarker([e.latlng.lat, e.latlng.lng]); });
			// centre on the selected city if we can resolve its coordinates
			f.city_id.addEventListener('change', centreOnCity);
			centreOnCity();
		}
		function centreOnCity() {
			var id = parseInt(f.city_id.value, 10);
			if (!id) {
				return;
			}
			(state.cities ? Promise.resolve(state.cities) : api('/cities').then(function (c) { state.cities = c; return c; })).then(function (cities) {
				var c = cities.filter(function (x) { return x.id === id; })[0];
				if (c && c.lat && c.lng && !marker) {
					leaflet.setView([c.lat, c.lng], c.zoom || 13);
				}
			});
		}
		findBtn.addEventListener('click', function () {
			var q = address.value.trim();
			if (q.length < 3) {
				return;
			}
			var cityName = f.city_id.options[f.city_id.selectedIndex] ? f.city_id.options[f.city_id.selectedIndex].text : '';
			findBtn.disabled = true;
			api('/geocode', 'GET', undefined, { q: f.city_id.value && q.indexOf(cityName) === -1 ? q + ', ' + cityName : q }).then(function (res) {
				clear(geoResults);
				res.forEach(function (r) {
					geoResults.appendChild(el('button', { type: 'button', class: 'pct-btn pct-btn-sm', text: r.label, onClick: function () {
						placeMarker([r.lat, r.lng]);
						leaflet.setView([r.lat, r.lng], 16);
						clear(geoResults);
					} }));
				});
			}).catch(function () {}).then(function () { findBtn.disabled = false; });
		});

		var saveBtn = el('button', { type: 'submit', class: 'pct-btn pct-btn-primary', text: t.save });
		form.appendChild(el('div', { class: 'pct-wide' }, [saveBtn]));
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var body = {};
			Object.keys(f).forEach(function (k) { body[k] = f[k].value; });
			body.categories = Array.prototype.map.call(catSel.querySelectorAll('input:checked'), function (i) { return i.value; });
			body.accessibility = Array.prototype.map.call(accSel.querySelectorAll('input:checked'), function (i) { return i.value; });
			saveBtn.disabled = true;
			saveBtn.textContent = t.saving;
			api('/guide/events', 'POST', body).then(function (r) {
				clear(msg);
				msg.appendChild(notice(r.status === 'publish' ? t.eventSaved : t.eventPending, 'ok'));
				form.reset();
				catSel.querySelectorAll('.pct-choice').forEach(function (c) { c.classList.remove('is-checked'); });
				accSel.querySelectorAll('.pct-choice').forEach(function (c) { c.classList.remove('is-checked'); });
				if (marker) {
					leaflet.removeLayer(marker);
					marker = null;
				}
				return api('/guide/events').then(function (ev) {
					guide.events = ev;
					drawEvents();
				});
			}).catch(function (err) {
				clear(msg);
				msg.appendChild(notice(err.message, 'error'));
			}).then(function () {
				saveBtn.disabled = false;
				saveBtn.textContent = t.save;
				msg.scrollIntoView({ behavior: 'smooth', block: 'center' });
			});
		});

		view.appendChild(el('h3', { text: t.newEvent }));
		view.appendChild(form);
		view.appendChild(el('div', { class: 'pct-block' }, [el('h3', { text: t.myEvents }), list]));
		mount(view);
		initMap();
		if (window.PCTDate) {
			window.PCTDate.attach(startInput, { calendar: lang.calendar, withTime: true });
			window.PCTDate.attach(endInput, { calendar: lang.calendar, withTime: true });
		} else {
			startInput.type = 'datetime-local';
			endInput.type = 'datetime-local';
		}
	}

	/* ------------------------------------------------------------------ */
	/* boot                                                                */
	/* ------------------------------------------------------------------ */

	function bootDefault() {
		if (cfg.view === 'traveler') {
			startTraveler();
		} else if (cfg.view === 'guide') {
			startGuide();
		} else {
			renderEntry();
		}
	}

	function boot() {
		if (cfg.tourId && cfg.tourToken) {
			loadSharedTour(cfg.tourId, cfg.tourToken);
			return;
		}
		if (lang.list.length > 1 && !LS.get('lang_chosen') && cfg.view === 'both') {
			renderLanguage();
			return;
		}
		bootDefault();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
