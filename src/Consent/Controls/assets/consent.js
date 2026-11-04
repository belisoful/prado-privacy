/*
 * prado-privacy focus management for the consent controls.
 *
 * A callback replaces the banner and the preferences, so the focused button disappears. The
 * controls queue these functions as callback actions; each moves the focus once the response has
 * updated the page (the prado:callbackUpdated event).
 */
(function () {
	'use strict';

	/** The id of the element that opened the preferences. */
	let opener = '';

	function afterUpdate(fn) {
		document.addEventListener('prado:callbackUpdated', fn, { once: true });
	}

	function focusById(id) {
		const element = id ? document.getElementById(id) : null;
		if (element === null) {
			return false;
		}
		element.focus();
		return document.activeElement === element;
	}

	/** Focuses the start of the body, so the next Tab reaches the page's first control. */
	function focusBodyStart() {
		const body = document.body;
		if (!body.hasAttribute('tabindex')) {
			body.setAttribute('tabindex', '-1');
			body.addEventListener('blur', () => body.removeAttribute('tabindex'), { once: true });
		}
		body.focus();
	}

	window.PradoConsent = {
		/**
		 * The preferences opened: focus their heading and remember the opener.
		 * @param {string} headingId the preferences heading
		 * @param {string} openerId the link or button that opened them
		 */
		open(headingId, openerId) {
			opener = openerId;
			afterUpdate(() => focusById(headingId));
		},

		/**
		 * The preferences closed: focus the opener when it still exists, else the fallback (the
		 * button pressed, on a settings page that keeps them shown), else the body start.
		 * @param {string} fallbackId the button pressed
		 */
		close(fallbackId) {
			const id = opener;
			opener = '';
			afterUpdate(() => focusById(id) || focusById(fallbackId) || focusBodyStart());
		},

		/**
		 * The banner recorded a choice and is gone: focus the page's last consent link (a footer's),
		 * which reopens the preferences, else the body start.
		 */
		chosen() {
			afterUpdate(() => {
				const links = document.querySelectorAll('[data-consent-link]');
				const link = links.length > 0 ? links[links.length - 1] : null;
				if (link === null || !focusById(link.id)) {
					focusBodyStart();
				}
			});
		},
	};
})();
