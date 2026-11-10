/*!
 * PrivacyPilot consent banner.
 * Vanilla JS, no dependencies. Loaded in <head> so stored choices apply
 * before blocked scripts would run.
 */
(function () {
	'use strict';

	var cfg = window.goo1PpConfig;
	if (!cfg) {
		return;
	}

	var COOKIE = 'pp_consent';
	var T = cfg.texts || {};
	var P = cfg.placeholder || {};
	var state = null; // current consent {id,v,t,c,s,r,a}
	var rule = null; // optin | optout
	var country = '';
	var gpc = !!(cfg.gpc && navigator.globalPrivacyControl);
	var bannerEl = null;
	var lastFocus = null;

	function t(key, fallback) {
		return T[key] || fallback || '';
	}

	function uuid() {
		if (window.crypto && crypto.randomUUID) {
			return crypto.randomUUID();
		}
		var b = new Uint8Array(16);
		(window.crypto || window.msCrypto).getRandomValues(b);
		b[6] = (b[6] & 0x0f) | 0x40;
		b[8] = (b[8] & 0x3f) | 0x80;
		var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
		return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
	}

	function readCookie() {
		var m = document.cookie.match(/(?:^|; )pp_consent=([^;]+)/);
		if (!m) {
			return null;
		}
		try {
			var c = JSON.parse(decodeURIComponent(m[1]));
			if (!c || !c.id || !Array.isArray(c.c)) {
				return null;
			}
			return c;
		} catch (e) {
			return null;
		}
	}

	function writeCookie(c) {
		var secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(c)) + '; Max-Age=' + (cfg.days * 86400) + '; Path=/; SameSite=Lax' + secure;
	}

	function usedCategories() {
		return cfg.categories.filter(function (c) { return c.used; }).map(function (c) { return c.id; });
	}

	function allowed(cat, svc) {
		if (!cat || cat === 'necessary') {
			return true;
		}
		if (!state) {
			return false;
		}
		return state.c.indexOf(cat) > -1 || (svc && state.s && state.s.indexOf(svc) > -1);
	}

	function onReady(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	/* ------------------------------------------------------------------ *
	 * Applying consent
	 * ------------------------------------------------------------------ */

	var lastConsentUpdate = '';

	function consentModeUpdate() {
		if (!cfg.consentMode || typeof window.gtag !== 'function') {
			return;
		}
		var g = function (k) { return allowed(k) ? 'granted' : 'denied'; };
		var update = {
			analytics_storage: g('analytics'),
			ad_storage: g('marketing'),
			ad_user_data: g('marketing'),
			ad_personalization: g('marketing'),
			functionality_storage: g('preferences'),
			personalization_storage: g('preferences')
		};
		var key = JSON.stringify(update);
		if (key === lastConsentUpdate) {
			return;
		}
		lastConsentUpdate = key;
		window.gtag('consent', 'update', update);
	}

	function activateScript(el) {
		var s = document.createElement('script');
		for (var i = 0; i < el.attributes.length; i++) {
			var a = el.attributes[i];
			if (a.name === 'type' || a.name.indexOf('data-pp-') === 0) {
				continue;
			}
			s.setAttribute(a.name, a.value);
		}
		if (el.getAttribute('data-pp-type') === 'module') {
			s.type = 'module';
		}
		if (el.src) {
			s.async = el.hasAttribute('async');
		} else {
			s.text = el.text;
		}
		el.parentNode.replaceChild(s, el);
	}

	function b64decode(str) {
		try {
			return decodeURIComponent(Array.prototype.map.call(atob(str), function (c) {
				return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
			}).join(''));
		} catch (e) {
			return '';
		}
	}

	function activateEmbed(el) {
		var html = b64decode(el.getAttribute('data-pp-html') || '');
		if (!html) {
			return;
		}
		var tpl = document.createElement('template');
		tpl.innerHTML = html;
		el.parentNode.replaceChild(tpl.content, el);
	}

	function activateElementor(el) {
		var settings = el.getAttribute('data-pp-settings');
		el.setAttribute('data-settings', settings);
		el.removeAttribute('data-pp-settings');
		el.classList.remove('pp-elementor-video');
		var overlay = el.querySelector(':scope > .pp-embed-overlay');
		if (overlay) {
			overlay.parentNode.removeChild(overlay);
		}
		if (window.elementorFrontend && window.elementorFrontend.elementsHandler && window.jQuery) {
			try {
				window.elementorFrontend.elementsHandler.runReadyTrigger(window.jQuery(el));
			} catch (e) { /* Elementor not ready yet: it will pick up data-settings itself. */ }
		}
	}

	function applyAll() {
		consentModeUpdate();
		onReady(function () {
			var scripts = document.querySelectorAll('script[type="text/plain"][data-pp-category]');
			Array.prototype.forEach.call(scripts, function (el) {
				if (allowed(el.getAttribute('data-pp-category'), el.getAttribute('data-pp-service'))) {
					activateScript(el);
				}
			});
			Array.prototype.forEach.call(document.querySelectorAll('.pp-embed[data-pp-html]'), function (el) {
				if (allowed(el.getAttribute('data-pp-category'), el.getAttribute('data-pp-service'))) {
					activateEmbed(el);
				}
			});
			Array.prototype.forEach.call(document.querySelectorAll('.pp-elementor-video[data-pp-settings]'), function (el) {
				if (allowed(el.getAttribute('data-pp-category'), el.getAttribute('data-pp-service'))) {
					activateElementor(el);
				}
			});
			document.dispatchEvent(new CustomEvent('goo1:consent', { detail: state }));
		});
	}

	/* ------------------------------------------------------------------ *
	 * Saving
	 * ------------------------------------------------------------------ */

	function log(action) {
		if (!state) {
			return;
		}
		var body = JSON.stringify({
			id: state.id,
			categories: state.c,
			services: state.s || [],
			rule: gpc && action === 'gpc' ? 'gpc' : rule,
			action: action,
			country: country,
			banner_version: cfg.version,
			library_version: cfg.libraryVersion
		});
		try {
			fetch(cfg.rest + 'consent', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'omit' });
		} catch (e) { /* logging must never break the page */ }
	}

	function save(cats, services, action) {
		var before = state ? state.c.slice() : [];
		var beforeS = state && state.s ? state.s.slice() : [];
		if (gpc) {
			cats = cats.filter(function (c) { return c !== 'marketing'; });
		}
		state = {
			id: state ? state.id : uuid(),
			v: cfg.version,
			t: Math.floor(Date.now() / 1000),
			c: ['necessary'].concat(cats.filter(function (c) { return c !== 'necessary'; })),
			s: services || [],
			r: rule,
			a: action
		};
		writeCookie(state);
		log(action);
		var revoked = before.some(function (c) { return state.c.indexOf(c) === -1; }) ||
			beforeS.some(function (s) { return state.s.indexOf(s) === -1; });
		if (revoked) {
			// Scripts that already ran cannot be unloaded: reload the page.
			location.reload();
			return;
		}
		applyAll();
	}

	/* ------------------------------------------------------------------ *
	 * UI
	 * ------------------------------------------------------------------ */

	function el(tag, attrs, children) {
		var e = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'text') {
					e.textContent = attrs[k];
				} else if (k === 'html') {
					e.innerHTML = attrs[k];
				} else if (k.indexOf('on') === 0) {
					e.addEventListener(k.slice(2), attrs[k]);
				} else {
					e.setAttribute(k, attrs[k]);
				}
			});
		}
		(children || []).forEach(function (c) {
			if (c) {
				e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
			}
		});
		return e;
	}

	function docLinks() {
		var p = el('p', { class: 'pp-links' });
		if (cfg.docs.privacy) {
			p.appendChild(el('a', { href: cfg.docs.privacy, text: t('privacy_link', 'Privacy policy') }));
		}
		if (cfg.docs.cookies) {
			p.appendChild(document.createTextNode(' · '));
			p.appendChild(el('a', { href: cfg.docs.cookies, text: t('cookies_link', 'Cookie policy') }));
		}
		if (cfg.docs.impressum) {
			p.appendChild(document.createTextNode(' · '));
			p.appendChild(el('a', { href: cfg.docs.impressum, text: t('impressum_link', 'Imprint') }));
		}
		return p;
	}

	function close() {
		if (bannerEl && bannerEl.parentNode) {
			bannerEl.parentNode.removeChild(bannerEl);
		}
		bannerEl = null;
		document.documentElement.classList.remove('pp-modal-open');
		if (lastFocus && lastFocus.focus) {
			lastFocus.focus();
		}
	}

	function mount(node, modal) {
		close();
		lastFocus = document.activeElement;
		bannerEl = node;
		document.body.appendChild(node);
		if (modal) {
			document.documentElement.classList.add('pp-modal-open');
		}
		var first = node.querySelector('input:not([disabled]), button:not([disabled]), [href]');
		if (first && modal) {
			first.focus();
		}
	}

	function mainView() {
		var optout = rule === 'optout';
		var box = el('div', {
			id: 'pp-banner',
			class: 'pp-banner pp-pos-' + (optout ? 'bottom' : cfg.position) + (optout ? ' pp-notice' : ''),
			role: 'dialog',
			'aria-modal': optout || cfg.position !== 'center' ? 'false' : 'true',
			'aria-labelledby': 'pp-banner-title',
			'aria-describedby': 'pp-banner-text'
		});
		var inner = el('div', { class: 'pp-inner' });
		inner.appendChild(el('h2', { id: 'pp-banner-title', class: 'pp-title', text: optout ? t('notice_title', 'Your privacy choices') : t('title', 'Privacy settings') }));
		inner.appendChild(el('p', { id: 'pp-banner-text', class: 'pp-text', text: optout ? t('notice_text', '') : t('text', '') }));
		if (optout && gpc) {
			inner.appendChild(el('p', { class: 'pp-gpc', text: t('gpc_note', 'We detected a Global Privacy Control signal and treat it as your request to opt out of the sale or sharing of personal information.') }));
		}
		inner.appendChild(docLinks());
		var actions = el('div', { class: 'pp-actions' });
		if (optout) {
			actions.appendChild(el('button', { type: 'button', class: 'pp-btn pp-btn-primary', text: t('ok', 'OK'), onclick: function () { save(defaultOptoutCats(), [], gpc ? 'gpc' : 'ok'); close(); } }));
			actions.appendChild(el('button', { type: 'button', class: 'pp-btn', text: t('dnss', 'Do Not Sell or Share My Personal Information'), onclick: function () { save(usedCategories().filter(function (c) { return c !== 'marketing'; }), [], 'custom'); close(); } }));
			actions.appendChild(el('button', { type: 'button', class: 'pp-btn pp-btn-link', text: t('settings', 'Settings'), onclick: settingsView }));
		} else {
			actions.appendChild(el('button', { type: 'button', class: 'pp-btn pp-btn-primary', text: t('accept', 'Accept all'), onclick: function () { save(usedCategories(), [], 'accept_all'); close(); } }));
			actions.appendChild(el('button', { type: 'button', class: 'pp-btn pp-btn-primary', text: t('reject', 'Reject all'), onclick: function () { save([], [], 'reject_all'); close(); } }));
			actions.appendChild(el('button', { type: 'button', class: 'pp-btn', text: t('settings', 'Settings'), onclick: settingsView }));
		}
		inner.appendChild(actions);
		box.appendChild(inner);
		mount(box, !optout && cfg.position === 'center');
	}

	function settingsView() {
		var current = state ? state.c : (rule === 'optout' ? defaultOptoutCats() : ['necessary']);
		var currentS = state && state.s ? state.s : [];
		var box = el('div', { id: 'pp-banner', class: 'pp-banner pp-settings pp-pos-center', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'pp-settings-title' });
		var inner = el('div', { class: 'pp-inner' });
		inner.appendChild(el('h2', { id: 'pp-settings-title', class: 'pp-title', text: t('settings_title', 'Privacy settings') }));
		inner.appendChild(el('p', { class: 'pp-text', text: t('settings_text', '') }));
		if (gpc) {
			inner.appendChild(el('p', { class: 'pp-gpc', text: t('gpc_note', 'We detected a Global Privacy Control signal and treat it as your request to opt out of the sale or sharing of personal information.') }));
		}
		var list = el('div', { class: 'pp-cats' });
		cfg.categories.forEach(function (cat) {
			if (!cat.used) {
				return;
			}
			var id = 'pp-cat-' + cat.id;
			var svcs = cfg.services.filter(function (s) { return s.category === cat.id; });
			var input = el('input', { type: 'checkbox', id: id, value: cat.id, class: 'pp-cat-input' });
			input.checked = cat.locked || current.indexOf(cat.id) > -1;
			if (cat.locked || (gpc && cat.id === 'marketing')) {
				input.disabled = true;
			}
			var row = el('div', { class: 'pp-cat' }, [
				el('label', { for: id, class: 'pp-cat-label' }, [input, el('span', { text: cat.label })]),
				cat.description ? el('p', { class: 'pp-cat-desc', text: cat.description }) : null
			]);
			if (svcs.length) {
				var det = el('details', { class: 'pp-svcs' }, [el('summary', { text: t('services_label', 'Services') + ' (' + svcs.length + ')' })]);
				svcs.forEach(function (s) {
					var sid = 'pp-svc-' + s.id;
					var sin = el('input', { type: 'checkbox', id: sid, value: s.id, class: 'pp-svc-input', 'data-cat': cat.id });
					sin.checked = input.checked || currentS.indexOf(s.id) > -1;
					if (gpc && cat.id === 'marketing') {
						sin.disabled = true;
					}
					input.addEventListener('change', function () { sin.checked = input.checked; });
					det.appendChild(el('div', { class: 'pp-svc' }, [
						el('label', { for: sid }, [sin, el('strong', { text: s.name }), document.createTextNode(' – ' + s.provider)]),
						s.purpose ? el('p', { text: s.purpose }) : null,
						s.privacy ? el('a', { href: s.privacy, target: '_blank', rel: 'noopener', text: t('privacy_link', 'Privacy policy') }) : null
					]));
				});
				row.appendChild(det);
			}
			list.appendChild(row);
		});
		inner.appendChild(list);
		inner.appendChild(docLinks());
		if (state) {
			inner.appendChild(el('p', { class: 'pp-consent-id', text: t('consent_id', 'Your consent ID') + ': ' + state.id }));
		}
		var actions = el('div', { class: 'pp-actions' });
		actions.appendChild(el('button', {
			type: 'button', class: 'pp-btn pp-btn-primary', text: t('save', 'Save selection'), onclick: function () {
				var cats = Array.prototype.filter.call(box.querySelectorAll('.pp-cat-input'), function (i) { return i.checked; }).map(function (i) { return i.value; });
				var svcs = Array.prototype.filter.call(box.querySelectorAll('.pp-svc-input'), function (i) { return i.checked && cats.indexOf(i.getAttribute('data-cat')) === -1; }).map(function (i) { return i.value; });
				save(cats, svcs, 'custom');
				close();
			}
		}));
		actions.appendChild(el('button', { type: 'button', class: 'pp-btn', text: t('accept', 'Accept all'), onclick: function () { save(usedCategories(), [], 'accept_all'); close(); } }));
		actions.appendChild(el('button', { type: 'button', class: 'pp-btn', text: t('reject', 'Reject all'), onclick: function () { save([], [], 'reject_all'); close(); } }));
		if (state) {
			actions.appendChild(el('button', { type: 'button', class: 'pp-btn pp-btn-link', text: t('close', 'Close'), onclick: close }));
		} else {
			actions.appendChild(el('button', { type: 'button', class: 'pp-btn pp-btn-link', text: t('back', 'Back'), onclick: mainView }));
		}
		inner.appendChild(actions);
		box.appendChild(inner);
		box.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				if (state) { close(); } else { mainView(); }
			}
			if (e.key === 'Tab') {
				trapFocus(e, box);
			}
		});
		mount(box, true);
	}

	function trapFocus(e, box) {
		var f = box.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), summary');
		if (!f.length) {
			return;
		}
		var first = f[0], last = f[f.length - 1];
		if (e.shiftKey && document.activeElement === first) {
			last.focus();
			e.preventDefault();
		} else if (!e.shiftKey && document.activeElement === last) {
			first.focus();
			e.preventDefault();
		}
	}

	function defaultOptoutCats() {
		return usedCategories().filter(function (c) { return !(gpc && c === 'marketing'); });
	}

	/* ------------------------------------------------------------------ *
	 * Placeholders (per-embed "load" button)
	 * ------------------------------------------------------------------ */

	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.pp-embed-load');
		if (btn) {
			var holder = btn.closest('.pp-embed, .pp-elementor-video');
			if (!holder) {
				return;
			}
			var svc = holder.getAttribute('data-pp-service');
			var remember = holder.querySelector('.pp-embed-remember');
			if (remember && remember.checked && svc) {
				var cats = state ? state.c.slice() : ['necessary'];
				var svcs = state && state.s ? state.s.slice() : [];
				if (svcs.indexOf(svc) === -1) {
					svcs.push(svc);
				}
				save(cats, svcs, 'custom');
			} else if (holder.classList.contains('pp-elementor-video')) {
				activateElementor(holder);
			} else {
				activateEmbed(holder);
			}
			return;
		}
		var opener = e.target.closest && e.target.closest('[data-pp-open], .pp-open-settings, a[href="#privacypilot-settings"], li.pp-open-settings > a');
		if (opener) {
			e.preventDefault();
			settingsView();
		}
	});

	/* ------------------------------------------------------------------ *
	 * Region
	 * ------------------------------------------------------------------ */

	function fetchText(url, ms) {
		return new Promise(function (resolve, reject) {
			var done = false;
			var timer = setTimeout(function () { if (!done) { done = true; reject(new Error('timeout')); } }, ms);
			fetch(url, { cache: 'no-store', credentials: 'omit' }).then(function (r) {
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r.text();
			}).then(function (txt) {
				if (!done) { done = true; clearTimeout(timer); resolve(txt); }
			}).catch(function (err) {
				if (!done) { done = true; clearTimeout(timer); reject(err); }
			});
		});
	}

	function ruleFor(cc) {
		var r = cfg.geo.rules;
		if (cc && cfg.geo.optin.indexOf(cc) > -1) {
			return r.eu;
		}
		if (cc === 'US') {
			return r.us;
		}
		return r.other;
	}

	function determineRule() {
		if (cfg.geo.mode === 'strict' || cfg.audit) {
			return Promise.resolve('optin');
		}
		try {
			var cached = sessionStorage.getItem('pp_cc');
			if (cached !== null) {
				country = cached;
				return Promise.resolve(ruleFor(cached || null));
			}
		} catch (e) { /* storage blocked */ }
		if (!cfg.geo.cloudflare) {
			return Promise.resolve(cfg.geo.rules.other);
		}
		return fetchText('/cdn-cgi/trace', 1500).then(function (txt) {
			var m = txt.match(/(?:^|\n)loc=([A-Z]{2})/);
			if (!m || m[1] === 'XX') { throw new Error('no loc'); }
			return m[1];
		}).catch(function () {
			return fetchText(cfg.rest + 'geo', 2000).then(function (txt) {
				var d = JSON.parse(txt);
				return d && d.country ? d.country : '';
			});
		}).then(function (cc) {
			country = cc || '';
			try { sessionStorage.setItem('pp_cc', country); } catch (e) { /* ignore */ }
			return ruleFor(country || null);
		}).catch(function () {
			return cfg.geo.rules.other;
		});
	}

	/* ------------------------------------------------------------------ *
	 * Audit mode (admins: ?pp-audit=1)
	 * ------------------------------------------------------------------ */

	function auditRun() {
		var phase = '';
		try { phase = sessionStorage.getItem('pp_audit') || ''; } catch (e) { /* ignore */ }
		var keep = /^(wordpress_|wp-settings|wp_lang)/;
		if (phase !== '2') {
			// Phase 1: remove first-party cookies and storage, then reload clean.
			document.cookie.split('; ').forEach(function (c) {
				var name = c.split('=')[0];
				if (name && !keep.test(name)) {
					var host = location.hostname.split('.');
					for (var i = 0; i < host.length - 1; i++) {
						var dom = host.slice(i).join('.');
						document.cookie = name + '=; Max-Age=0; Path=/; Domain=' + dom;
						document.cookie = name + '=; Max-Age=0; Path=/; Domain=.' + dom;
					}
					document.cookie = name + '=; Max-Age=0; Path=/';
				}
			});
			try { localStorage.clear(); sessionStorage.setItem('pp_audit', '2'); } catch (e) { /* ignore */ }
			location.reload();
			return false;
		}
		try { sessionStorage.removeItem('pp_audit'); } catch (e) { /* ignore */ }
		onReady(function () {
			setTimeout(function () {
				var cookies = document.cookie ? document.cookie.split('; ').map(function (c) { return c.split('=')[0]; }) : [];
				var storage = [];
				try { for (var i = 0; i < localStorage.length; i++) { storage.push(localStorage.key(i)); } } catch (e) { /* ignore */ }
				fetch(cfg.rest + 'audit', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.audit.nonce },
					body: JSON.stringify({ cookies: cookies, storage: storage, url: location.href.split('?')[0] })
				}).then(function (r) { return r.json(); }).then(function (d) {
					var msg = el('div', { class: 'pp-audit-result', role: 'status' }, [
						el('strong', { text: 'PrivacyPilot audit: ' }),
						document.createTextNode((d && typeof d.problems === 'number' ? d.problems : '?') + ' cookie(s) before consent that are not strictly necessary. Details: Settings → PrivacyPilot → Services & scan.')
					]);
					document.body.appendChild(msg);
				});
			}, 5000);
		});
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Start
	 * ------------------------------------------------------------------ */

	window.goo1Pp = {
		open: function () { onReady(settingsView); },
		get: function () { return state; },
		allowed: allowed
	};

	if (cfg.audit) {
		// Phase 1 reloads the page; only phase 2 shows the banner.
		if (auditRun()) {
			onReady(function () { determineRule().then(function (r) { rule = r; mainView(); }); });
		}
		return;
	}

	var stored = readCookie();
	var now = Math.floor(Date.now() / 1000);
	if (stored && stored.v === cfg.version && stored.t + cfg.days * 86400 > now) {
		state = stored;
		rule = stored.r || null;
		if (gpc && state.c.indexOf('marketing') > -1) {
			state.c = state.c.filter(function (c) { return c !== 'marketing'; });
			writeCookie(state);
			log('gpc');
		}
		applyAll();
		return;
	}

	if (!cfg.enabled) {
		return;
	}

	determineRule().then(function (r) {
		rule = r === 'optout' ? 'optout' : 'optin';
		if (rule === 'optout') {
			// US-style: services run unless the visitor objects (or sends GPC).
			// The choice is only stored (cookie + log) once the visitor clicks.
			state = { id: uuid(), v: cfg.version, t: now, c: ['necessary'].concat(defaultOptoutCats().filter(function (c) { return c !== 'necessary'; })), s: [], r: 'optout', a: 'implicit' };
			applyAll();
		}
		onReady(mainView);
	});
})();
