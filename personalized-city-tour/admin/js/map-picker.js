/* global L, pctMap, pctAdmin */
(function () {
	'use strict';

	if (typeof L === 'undefined') { return; }

	var picker = document.querySelector('.pct-map-picker');
	if (!picker) { return; }

	var latInput = document.querySelector('input[data-pct="lat"]');
	var lngInput = document.querySelector('input[data-pct="lng"]');
	var canvas = picker.querySelector('.pct-map-canvas');

	function num(v) { var n = parseFloat(v); return isNaN(n) ? null : n; }

	var lat = num(latInput.value);
	var lng = num(lngInput.value);
	var cityLat = num(picker.getAttribute('data-city-lat'));
	var cityLng = num(picker.getAttribute('data-city-lng'));
	var center = (lat !== null && lng !== null) ? [lat, lng] : (cityLat !== null ? [cityLat, cityLng] : [48.8566, 2.3522]);
	var zoom = (lat !== null) ? 15 : (cityLat !== null ? 12 : 3);

	var map = L.map(canvas, { scrollWheelZoom: false }).setView(center, zoom);
	var tileUrls = [pctMap.tileUrl].concat(pctMap.tileFallbacks || []);
	var tileAttrs = [pctMap.attribution].concat(pctMap.tileFallbackAttributions || []);
	var tileIndex = 0;
	function addTiles() {
		if (!tileUrls[tileIndex]) { return; }
		var layer = L.tileLayer(tileUrls[tileIndex], { attribution: tileAttrs[tileIndex] || '', maxZoom: 19 }).addTo(map);
		var switched = false;
		layer.on('tileerror', function () {
			if (switched || tileIndex >= tileUrls.length - 1) { return; }
			switched = true;
			map.removeLayer(layer);
			tileIndex++;
			addTiles();
		});
	}
	addTiles();

	var marker = null;
	function place(latlng, pan) {
		if (!marker) {
			marker = L.marker(latlng, { draggable: true }).addTo(map);
			marker.on('dragend', function () { write(marker.getLatLng()); });
		} else {
			marker.setLatLng(latlng);
		}
		write(latlng);
		if (pan) { map.setView(latlng, Math.max(map.getZoom(), 15)); }
	}
	function write(latlng) {
		latInput.value = latlng.lat.toFixed(6);
		lngInput.value = latlng.lng.toFixed(6);
	}

	if (lat !== null && lng !== null) { place(L.latLng(lat, lng), false); }
	map.on('click', function (e) { place(e.latlng, false); });

	function syncFromInputs() {
		var a = num(latInput.value), b = num(lngInput.value);
		if (a !== null && b !== null) { place(L.latLng(a, b), true); }
	}
	latInput.addEventListener('change', syncFromInputs);
	lngInput.addEventListener('change', syncFromInputs);

	/* Address search (server-side Nominatim proxy) */
	var input = picker.querySelector('.pct-geocode-input');
	var btn = picker.querySelector('.pct-geocode-btn');
	var results = picker.querySelector('.pct-geocode-results');

	function search() {
		var q = input.value.trim();
		if (q.length < 3) { return; }
		results.textContent = '…';
		fetch(pctAdmin.rest + '/geocode?q=' + encodeURIComponent(q), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': pctAdmin.nonce }
		}).then(function (r) { return r.json(); }).then(function (rows) {
			results.innerHTML = '';
			if (!Array.isArray(rows) || !rows.length) {
				results.textContent = '—';
				return;
			}
			rows.forEach(function (row) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'button';
				b.textContent = row.label;
				b.addEventListener('click', function () {
					place(L.latLng(row.lat, row.lng), true);
					var addr = document.getElementById('_pct_address');
					if (addr && !addr.value) { addr.value = row.label; }
					results.innerHTML = '';
				});
				results.appendChild(b);
			});
		}).catch(function () { results.textContent = '—'; });
	}
	btn.addEventListener('click', search);
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter') { e.preventDefault(); search(); }
	});

	// Meta boxes can be toggled/collapsed; make sure tiles render correctly.
	setTimeout(function () { map.invalidateSize(); }, 300);
})();
