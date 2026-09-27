/* Jalali <-> Gregorian conversion and parsing in public/js/pct-datepicker.js (no browser needed). */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const src = fs.readFileSync(path.join(__dirname, '../../personalized-city-tour/public/js/pct-datepicker.js'), 'utf8');
const sandbox = {
	document: { readyState: 'complete', querySelectorAll: () => [], addEventListener: () => {} },
	pctDatepicker: { calendar: 'jalali', i18n: {} }
};
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(src, sandbox);
const D = sandbox.PCTDate;

let fails = 0;
function ok(cond, msg) { console.log((cond ? '  ok: ' : '  FAIL: ') + msg); if (!cond) { fails++; } }
function eq(a, b, msg) { ok(JSON.stringify(a) === JSON.stringify(b), msg + ' -> ' + JSON.stringify(a)); }

console.log('== conversions');
eq(D.toJalali(2025, 3, 21), [1404, 1, 1], 'Nowruz 2025-03-21 = 1404/01/01');
eq(D.toJalali(2026, 9, 22), [1405, 6, 31], '2026-09-22 = 1405/06/31');
eq(D.toGregorian(1404, 6, 30), [2025, 9, 21], '1404/06/30 = 2025-09-21');
eq(D.toGregorian(1403, 12, 30), [2025, 3, 20], 'leap year 1403 has Esfand 30');
ok(D.isLeapJalali(1403) && !D.isLeapJalali(1404), '1403 leap, 1404 not');
eq(D.jalaliMonthLength(1404, 12), 29, 'Esfand 1404 = 29 days');
eq(D.jalaliMonthLength(1403, 12), 30, 'Esfand 1403 = 30 days');
for (let y = 2020; y <= 2030; y++) {
	for (const [m, d] of [[1, 1], [3, 20], [3, 21], [12, 31]]) {
		const j = D.toJalali(y, m, d);
		const g = D.toGregorian(j[0], j[1], j[2]);
		if (g[0] !== y || g[1] !== m || g[2] !== d) { ok(false, 'round trip ' + y + '-' + m + '-' + d); }
	}
}
ok(true, 'round trips 2020-2030 stable');

console.log('== parsing');
eq(D.parse('1404/06/30 10:30'), { g: [2025, 9, 21], time: '10:30' }, 'Jalali string with time parses to Gregorian');
eq(D.parse('۱۴۰۴/۰۶/۳۰'), { g: [2025, 9, 21], time: '' }, 'Persian digits accepted');
eq(D.parse('2025-09-21T09:05'), { g: [2025, 9, 21], time: '09:05' }, 'ISO datetime-local parses');
eq(D.parse('nonsense'), null, 'garbage rejected');
eq(D.parse('2025-13-01'), null, 'month 13 rejected');

console.log('== formatting');
eq(D.format([2025, 9, 21], 'jalali', '10:30'), '1404/06/30 10:30', 'format Jalali with time');
eq(D.format([2025, 9, 21], 'gregorian', ''), '2025-09-21', 'format Gregorian');
eq(D.display('2025-09-21 10:30', 'jalali'), '1404/06/30 10:30', 'display stored Gregorian as Jalali');
eq(D.display('2025-09-21', 'gregorian'), '2025-09-21', 'display Gregorian unchanged');

console.log('\nFAILS: ' + fails);
process.exit(fails ? 1 : 0);
