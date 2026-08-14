/* LunarSystem front-end behaviour — dependency-free (no jQuery), no animation.
 * Behaviour is wired entirely through data-attributes, so there are no inline
 * event handlers and the CSP needs no 'unsafe-inline':
 *   [data-href]                   click a row (off its own controls) to navigate
 *   select[data-navigate]         navigate to the chosen option's value
 *   select[data-submit-on-change] submit the enclosing form on change
 *   [data-confirm]                confirm before the click/submit proceeds
 * Show/hide is instant; add a CSS transition on .box-content or a row if you
 * want movement. */

// Navigable rows: a click anywhere on a [data-href] that is not itself an
// interactive control navigates to its target.
document.addEventListener('click', function (e) {
	var row = e.target.closest('[data-href]');
	if (row && !e.target.closest('a, button, input, select, textarea, label')) {
		window.location.href = row.getAttribute('data-href');
	}
});

// Selects that navigate to the chosen option's value, or submit their form.
document.addEventListener('change', function (e) {
	var el = e.target;
	if (!el.matches) { return; }
	if (el.matches('select[data-navigate]')) {
		if (el.value) { window.location.href = el.value; }
	} else if (el.matches('select[data-submit-on-change]') && el.form) {
		el.form.submit();
	}
});

// Confirm before a destructive action; cancel the click (and so the submit) if declined.
document.addEventListener('click', function (e) {
	var btn = e.target.closest('[data-confirm]');
	if (btn && !window.confirm(btn.getAttribute('data-confirm'))) {
		e.preventDefault();
	}
});

document.addEventListener('DOMContentLoaded', function () {

	// Zebra striping + row hover. The stylesheet styles .even / .odd / .hover.
	var rows = document.querySelectorAll('.zebra tr');
	for (var i = 0; i < rows.length; i++) {
		rows[i].classList.add(i % 2 ? 'odd' : 'even');
		rows[i].addEventListener('mouseover', function () { this.classList.add('hover'); });
		rows[i].addEventListener('mouseout', function () { this.classList.remove('hover'); });
	}

	// Collapsible box handles: the bottom-bar hamburger sitemap, the search box, …
	// Instant open/close — flip the +/- class and the adjacent .box-content display.
	// These are <h2>s, not <button>s, so they are made keyboard-operable here: expose the
	// button role + expanded state and respond to Enter/Space, not just a mouse click. Without
	// this the site navigation (the hamburger sitemap) is unreachable for keyboard users.
	var toggleHandle = function (handle) {
		var opening = handle.classList.contains('collapsed');
		handle.classList.toggle('collapsed', !opening);
		handle.classList.toggle('expanded', opening);
		handle.setAttribute('aria-expanded', opening ? 'true' : 'false');
		var content = handle.nextElementSibling;
		if (content) { content.style.display = opening ? 'block' : 'none'; }
	};
	var handles = document.querySelectorAll('.box-handle');
	for (var j = 0; j < handles.length; j++) {
		var handle = handles[j];
		if (!handle.hasAttribute('role')) { handle.setAttribute('role', 'button'); }
		if (!handle.hasAttribute('tabindex')) { handle.setAttribute('tabindex', '0'); }
		handle.setAttribute('aria-expanded', handle.classList.contains('expanded') ? 'true' : 'false');
		handle.addEventListener('click', function () { toggleHandle(this); });
		handle.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
				e.preventDefault(); // Space would otherwise scroll the page
				toggleHandle(this);
			}
		});
	}
});
