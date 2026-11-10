/* PrivacyPilot request form: submit via REST, no page reload. */
(function () {
	'use strict';
	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form.classList || !form.classList.contains('pp-request-form')) {
			return;
		}
		e.preventDefault();
		var status = form.querySelector('.pp-request-status');
		if (!form.checkValidity()) {
			form.reportValidity();
			return;
		}
		var data = {};
		Array.prototype.forEach.call(form.elements, function (el) {
			if (!el.name) { return; }
			if (el.type === 'checkbox') { data[el.name] = el.checked ? '1' : ''; return; }
			data[el.name] = el.value;
		});
		var btn = form.querySelector('button[type="submit"]');
		if (btn) { btn.disabled = true; }
		fetch(form.getAttribute('data-pp-rest'), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'omit',
			body: JSON.stringify(data)
		}).then(function (r) {
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		}).then(function () {
			status.textContent = status.getAttribute('data-ok');
			form.reset();
		}).catch(function () {
			status.textContent = status.getAttribute('data-error');
			if (btn) { btn.disabled = false; }
		});
	});
})();
