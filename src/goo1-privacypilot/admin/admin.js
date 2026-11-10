(function () {
	'use strict';
	document.addEventListener('click', function (e) {
		var toggle = e.target.closest('.pp-toggle');
		if (toggle) {
			e.preventDefault();
			var row = document.querySelector(toggle.getAttribute('href'));
			if (row) { row.hidden = !row.hidden; }
			return;
		}
		var copy = e.target.closest('.pp-copy');
		if (copy && navigator.clipboard) {
			navigator.clipboard.writeText(copy.getAttribute('data-copy'));
			copy.textContent = '✓';
			return;
		}
		var confirmBtn = e.target.closest('[data-confirm]');
		if (confirmBtn && !window.confirm(confirmBtn.getAttribute('data-confirm'))) {
			e.preventDefault();
		}
	});

	// Hub tab: show client fields only for "client".
	function syncMode() {
		var checked = document.querySelector('input[name="mode"]:checked');
		var isClient = checked && checked.value === 'client';
		document.querySelectorAll('.pp-client-only').forEach(function (r) { r.style.display = isClient ? '' : 'none'; });
	}
	document.addEventListener('change', function (e) {
		if (e.target.name === 'mode') { syncMode(); }
	});
	document.addEventListener('DOMContentLoaded', syncMode);
})();
