/* Administrator browser push controls for My Trip, My Vibe. */
(function () {
	'use strict';
	function ready(fn) {
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn); else fn();
	}
	function b64ToBytes(s) {
		var pad = '='.repeat((4 - s.length % 4) % 4);
		var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
		return out;
	}
	function request(url, method, body) {
		var opts = {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': window.pctPushAdmin.nonce, 'Content-Type': 'application/json' }
		};
		if (body !== undefined) opts.body = JSON.stringify(body);
		return fetch(url, opts).then(function (r) {
			return r.text().then(function (text) {
				var data = null;
				try { data = text ? JSON.parse(text) : null; } catch (e) {}
				if (!r.ok) throw new Error((data && data.message) || 'request_failed');
				return data;
			});
		});
	}
	ready(function () {
		var cfg = window.pctPushAdmin || {};
		var i18n = cfg.i18n || {};
		var input = document.getElementById('pct-push-permission');
		var status = document.getElementById('pct-push-status');
		var test = document.getElementById('pct-push-test');
		if (!input || !status || !test) return;
		function setStatus(text, error) {
			status.textContent = text || '';
			status.classList.toggle('pct-push-status-error', !!error);
		}
		function unsupported() {
			input.disabled = true;
			test.disabled = true;
			setStatus(i18n.unsupported || 'Browser push notifications are not supported on this device/browser.', true);
		}
		if (!cfg.available || !cfg.publicKey || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
			unsupported();
			return;
		}
		function currentSubscription() {
			return navigator.serviceWorker.register(cfg.swUrl, { scope: '/' }).then(function (reg) {
				return reg.pushManager.getSubscription();
			});
		}
		function serverSubscriptionCount() {
			return request(cfg.subscribeUrl, 'GET').then(function (data) { return data && Number(data.count || 0); });
		}
		function refresh() {
			if (Notification.permission === 'denied') {
				input.checked = false;
				input.disabled = true;
				test.disabled = true;
				setStatus(i18n.blocked || 'Browser notification permission is blocked. Allow notifications for this site in the browser settings.', true);
				return;
			}
			input.disabled = false;
			Promise.all([currentSubscription(), serverSubscriptionCount()]).then(function (parts) {
				var sub = parts[0];
				var count = parts[1];
				input.checked = !!sub;
				test.disabled = !(sub && count > 0);
				if (sub && count > 0) {
					setStatus(i18n.enabled || 'Notifications are enabled on this device.');
				} else if (sub && count === 0) {
					setStatus(i18n.serverMissing || 'This browser is subscribed, but the server has no subscription for this administrator. Enable notifications again.', true);
				} else {
					setStatus(i18n.disabled || 'Notifications are not enabled on this device.');
				}
			}).catch(function () {
				input.checked = false;
				test.disabled = true;
				setStatus(i18n.swError || 'Could not register the notification service worker.', true);
			});
		}
		input.addEventListener('change', function () {
			input.disabled = true;
			test.disabled = true;
			if (!input.checked) {
				currentSubscription().then(function (sub) {
					if (!sub) return null;
					return request(cfg.unsubscribeUrl, 'DELETE', { endpoint: sub.endpoint }).then(function () { return sub.unsubscribe(); });
				}).then(function () { refresh(); }).catch(function () {
					input.checked = true;
					input.disabled = false;
					setStatus(i18n.disableError || 'Could not disable notifications.', true);
				});
				return;
			}
			navigator.serviceWorker.register(cfg.swUrl, { scope: '/' }).then(function (reg) {
				return reg.update().catch(function () {}).then(function () { return reg.pushManager.getSubscription(); }).then(function (sub) {
					// Recreate the subscription on every explicit enable. This prevents
					// a subscription created with an older VAPID public key from
					// silently surviving a plugin/key migration.
					if (sub) {
						return request(cfg.unsubscribeUrl, 'DELETE', { endpoint: sub.endpoint }).catch(function () {}).then(function () { return sub.unsubscribe().catch(function () {}); });
					}
				}).then(function () {
					return Notification.requestPermission().then(function (permission) {
						if (permission !== 'granted') throw new Error('permission_denied');
						return navigator.serviceWorker.ready.then(function (readyReg) {
							return readyReg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(cfg.publicKey) });
						});
					});
				});
			}).then(function (sub) {
				return request(cfg.subscribeUrl, 'POST', sub.toJSON()).then(function () {
					input.checked = true;
					input.disabled = false;
					test.disabled = false;
					setStatus(i18n.enabled || 'Notifications are enabled on this device.');
				});
			}).catch(function (e) {
				input.checked = false;
				input.disabled = false;
				test.disabled = true;
				setStatus(e && e.message === 'permission_denied' ? (i18n.permission || 'Notification permission was not granted.') : (i18n.enableError || 'Could not enable browser notifications.'), true);
			});
		});
		refresh();
	});
}());
