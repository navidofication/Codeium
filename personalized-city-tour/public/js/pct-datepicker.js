/* global pctDatepicker */
/**
 * Shared Jalali / Gregorian date utilities and a dependency-free date picker
 * used by the traveler form, the guide event form and the admin event screen.
 *
 * Jalali conversion follows the jalaali-js algorithm (Borkowski).
 */
(function () {
	'use strict';

	var cfg = typeof pctDatepicker !== 'undefined' ? pctDatepicker : {};
	var i18n = cfg.i18n || {};
	var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

	function div(a, b) { return ~~(a / b); }
	function mod(a, b) { return a - ~~(a / b) * b; }

	function jalCal(jy) {
		var breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
		var gy = jy + 621, leapJ = -14, jp = breaks[0], jm, jump = 0, leap, leapG, march, n, i;
		for (i = 1; i < breaks.length; i++) {
			jm = breaks[i];
			jump = jm - jp;
			if (jy < jm) { break; }
			leapJ = leapJ + div(jump, 33) * 8 + div(mod(jump, 33), 4);
			jp = jm;
		}
		n = jy - jp;
		leapJ = leapJ + div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
		if (mod(jump, 33) === 4 && jump - n === 4) { leapJ += 1; }
		leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
		march = 20 + leapJ - leapG;
		if (jump - n < 6) { n = n - jump + div(jump + 4, 33) * 33; }
		leap = mod(mod(n + 1, 33) - 1, 4);
		if (leap === -1) { leap = 4; }
		return { leap: leap, gy: gy, march: march };
	}
	function g2d(gy, gm, gd) {
		var d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
		return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
	}
	function d2g(jdn) {
		var j = 4 * jdn + 139361631;
		j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
		var i = div(mod(j, 1461), 4) * 5 + 308;
		var gd = div(mod(i, 153), 5) + 1;
		var gm = mod(div(i, 153), 12) + 1;
		var gy = div(j, 1461) - 100100 + div(8 - gm, 6);
		return [gy, gm, gd];
	}
	function j2d(jy, jm, jd) {
		var r = jalCal(jy);
		return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
	}
	function d2j(jdn) {
		var gy = d2g(jdn)[0], jy = gy - 621, r = jalCal(jy), k = jdn - g2d(gy, 3, r.march), jm, jd;
		if (k >= 0) {
			if (k <= 185) { return [jy, 1 + div(k, 31), mod(k, 31) + 1]; }
			k -= 186;
		} else {
			jy -= 1;
			k += 179;
			if (r.leap === 1) { k += 1; }
		}
		jm = 7 + div(k, 30);
		jd = mod(k, 30) + 1;
		return [jy, jm, jd];
	}

	function toJalali(gy, gm, gd) { return d2j(g2d(gy, gm, gd)); }
	function toGregorian(jy, jm, jd) { return d2g(j2d(jy, jm, jd)); }
	function isLeapJalali(jy) { return jalCal(jy).leap === 0; }
	function jalaliMonthLength(jy, jm) { return jm <= 6 ? 31 : (jm <= 11 ? 30 : (isLeapJalali(jy) ? 30 : 29)); }
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function latinDigits(s) {
		return String(s || '').replace(/[۰-۹]/g, function (c) { return String(FA_DIGITS.indexOf(c)); });
	}

	/**
	 * Parse "YYYY-MM-DD[ HH:MM]" (Gregorian) or "YYYY/MM/DD[ HH:MM]" (Jalali when year < 1700).
	 * @return {{g:[y,m,d], time:string}|null}
	 */
	function parse(value) {
		var m = /^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})(?:[ T](\d{1,2}):(\d{2}))?/.exec(latinDigits(value).trim());
		if (!m) { return null; }
		var y = +m[1], mo = +m[2], d = +m[3];
		if (mo < 1 || mo > 12 || d < 1 || d > 31) { return null; }
		var g = y < 1700 ? toGregorian(y, mo, d) : [y, mo, d];
		return { g: g, time: m[4] ? pad(+m[4]) + ':' + m[5] : '' };
	}

	/** Format a Gregorian [y,m,d] for display in the given calendar. */
	function format(g, calendar, time) {
		var out;
		if (calendar === 'jalali') {
			var j = toJalali(g[0], g[1], g[2]);
			out = j[0] + '/' + pad(j[1]) + '/' + pad(j[2]);
		} else {
			out = g[0] + '-' + pad(g[1]) + '-' + pad(g[2]);
		}
		return out + (time ? ' ' + time : '');
	}

	/** Re-format any stored date string (Gregorian) for display. */
	function display(value, calendar) {
		var p = parse(value);
		return p ? format(p.g, calendar || cfg.calendar || 'gregorian', p.time) : String(value || '');
	}

	/* ------------------------------------------------------------------ */
	/* picker                                                              */
	/* ------------------------------------------------------------------ */

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined) { n.textContent = text; }
		return n;
	}

	/**
	 * Attach a picker to a text input. The input value is kept in the
	 * calendar's own notation (Jalali "1404/06/30 10:30" or Gregorian
	 * "2025-09-21 10:30"); the server accepts both.
	 *
	 * @param {HTMLInputElement} input
	 * @param {{calendar?:string, withTime?:boolean, onChange?:function}} opts
	 */
	function attach(input, opts) {
		opts = opts || {};
		var calendar = opts.calendar || cfg.calendar || 'gregorian';
		var withTime = !!opts.withTime;
		var jal = calendar === 'jalali';

		if (input.type !== 'text') {
			try { input.type = 'text'; } catch (e) { /* IE */ }
		}
		input.setAttribute('inputmode', 'numeric');
		input.setAttribute('autocomplete', 'off');
		input.setAttribute('dir', 'ltr');
		input.placeholder = (jal ? '1404/06/30' : '2025-09-21') + (withTime ? ' 10:30' : '');
		if (input.value) {
			input.value = display(input.value, calendar);
		}

		var wrap = el('div', 'pct-dp-wrap');
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(input);
		var pop = el('div', 'pct-dp' + (jal ? ' is-jalali' : ''));
		pop.setAttribute('dir', jal || cfg.rtl ? 'rtl' : 'ltr');
		pop.hidden = true;
		wrap.appendChild(pop);

		var view = { y: 0, m: 0 }; // month being displayed, in the active calendar
		var selected = null;       // Gregorian [y,m,d]
		var time = '';

		function today() {
			var d = new Date();
			return [d.getFullYear(), d.getMonth() + 1, d.getDate()];
		}
		function toCal(g) { return jal ? toJalali(g[0], g[1], g[2]) : g; }
		function fromCal(y, m, d) { return jal ? toGregorian(y, m, d) : [y, m, d]; }
		function monthLength(y, m) {
			return jal ? jalaliMonthLength(y, m) : new Date(y, m, 0).getDate();
		}
		function firstWeekday(y, m) {
			var g = fromCal(y, m, 1);
			var js = new Date(g[0], g[1] - 1, g[2]).getDay(); // 0 = Sunday
			return jal ? (js + 1) % 7 : js;                   // Jalali weeks start on Saturday
		}
		function sync() {
			var p = parse(input.value);
			selected = p ? p.g : null;
			time = p && p.time ? p.time : time;
			var c = toCal(selected || today());
			view = { y: c[0], m: c[1] };
		}
		function commit(g) {
			selected = g;
			input.value = g ? format(g, calendar, withTime ? (time || '00:00') : '') : '';
			input.dispatchEvent(new Event('change', { bubbles: true }));
			if (opts.onChange) { opts.onChange(input.value); }
		}

		function draw() {
			pop.innerHTML = '';
			var head = el('div', 'pct-dp-head');
			var prev = el('button', 'pct-dp-nav', jal || cfg.rtl ? '›' : '‹');
			var next = el('button', 'pct-dp-nav', jal || cfg.rtl ? '‹' : '›');
			prev.type = next.type = 'button';
			prev.onclick = function () { view.m--; if (view.m < 1) { view.m = 12; view.y--; } draw(); };
			next.onclick = function () { view.m++; if (view.m > 12) { view.m = 1; view.y++; } draw(); };
			var months = jal ? (i18n.jalaliMonths || []) : (i18n.months || []);
			head.appendChild(prev);
			head.appendChild(el('span', 'pct-dp-title', (months[view.m - 1] || view.m) + ' ' + view.y));
			head.appendChild(next);
			pop.appendChild(head);

			var grid = el('div', 'pct-dp-grid');
			var dayNames = jal ? (i18n.jalaliDays || []) : (i18n.days || []);
			dayNames.forEach(function (n) { grid.appendChild(el('span', 'pct-dp-dow', n)); });
			var offset = firstWeekday(view.y, view.m);
			for (var i = 0; i < offset; i++) { grid.appendChild(el('span', 'pct-dp-empty')); }
			var len = monthLength(view.y, view.m);
			var sel = selected ? toCal(selected) : null;
			var tod = toCal(today());
			for (var d = 1; d <= len; d++) {
				var b = el('button', 'pct-dp-day', String(d));
				b.type = 'button';
				if (sel && sel[0] === view.y && sel[1] === view.m && sel[2] === d) { b.classList.add('is-selected'); }
				if (tod[0] === view.y && tod[1] === view.m && tod[2] === d) { b.classList.add('is-today'); }
				b.onclick = (function (day) {
					return function () {
						commit(fromCal(view.y, view.m, day));
						if (!withTime) { close(); } else { draw(); }
					};
				})(d);
				grid.appendChild(b);
			}
			pop.appendChild(grid);

			var foot = el('div', 'pct-dp-foot');
			if (withTime) {
				var tl = el('label', 'pct-dp-time');
				tl.appendChild(el('span', '', i18n.time || 'Time'));
				var ti = document.createElement('input');
				ti.type = 'time';
				ti.value = time || '00:00';
				ti.onchange = function () {
					time = ti.value || '00:00';
					if (selected) { commit(selected); }
				};
				tl.appendChild(ti);
				foot.appendChild(tl);
			}
			var bt = el('button', 'pct-dp-link', i18n.today || 'Today');
			bt.type = 'button';
			bt.onclick = function () { commit(today()); close(); };
			var bc = el('button', 'pct-dp-link', i18n.clear || 'Clear');
			bc.type = 'button';
			bc.onclick = function () { commit(null); close(); };
			foot.appendChild(bt);
			foot.appendChild(bc);
			pop.appendChild(foot);
		}

		function open() {
			if (!pop.hidden) { return; }
			sync();
			draw();
			pop.hidden = false;
			document.addEventListener('mousedown', outside, true);
		}
		function close() {
			pop.hidden = true;
			document.removeEventListener('mousedown', outside, true);
		}
		function outside(e) {
			if (!wrap.contains(e.target)) { close(); }
		}

		input.addEventListener('focus', open);
		input.addEventListener('click', open);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { close(); }
			if (e.key === 'Enter') { e.preventDefault(); close(); }
		});
		// Typed values are accepted as-is (server parses both calendars); normalise on blur.
		input.addEventListener('blur', function () {
			var p = parse(input.value);
			if (p) {
				input.value = format(p.g, calendar, withTime ? (p.time || time || '00:00') : '');
			} else if (input.value.trim() !== '') {
				input.value = '';
			}
		});

		return { open: open, close: close, set: function (v) { input.value = v ? display(v, calendar) : ''; } };
	}

	/** Enhance every input[data-pct-date] on the page (admin screens). */
	function autoAttach(rootNode) {
		(rootNode || document).querySelectorAll('input[data-pct-date]').forEach(function (inp) {
			if (inp.getAttribute('data-pct-date-ready')) { return; }
			inp.setAttribute('data-pct-date-ready', '1');
			attach(inp, { withTime: inp.getAttribute('data-pct-date') === 'datetime' });
		});
	}

	window.PCTDate = {
		toJalali: toJalali,
		toGregorian: toGregorian,
		isLeapJalali: isLeapJalali,
		jalaliMonthLength: jalaliMonthLength,
		parse: parse,
		format: format,
		display: display,
		attach: attach,
		autoAttach: autoAttach,
		calendar: function () { return cfg.calendar || 'gregorian'; }
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { autoAttach(); });
	} else {
		autoAttach();
	}
})();
