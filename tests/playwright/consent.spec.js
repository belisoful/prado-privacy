// @ts-check
import { test, expect } from '@playwright/test';
import { BARE, HOME, LOG, SETTINGS, consentAfterReload, expectSameWindow, focusedId, markWindow, ui } from './helpers.js';

test.describe('undecided visitor in an opt-in region', () => {
	test('sees the banner and no gated content', async ({ page }) => {
		await page.goto(HOME);
		const { banner, accept, reject, preferences } = ui(page);
		await expect(banner).toBeVisible();
		await expect(accept).toBeVisible();
		await expect(reject).toBeVisible();
		await expect(banner.getByRole('link', { name: 'Privacy policy' })).toHaveAttribute('href', '/privacy');
		await expect(preferences).toHaveCount(0);
		await expect(page.locator('#video-embed')).toHaveCount(0);
		await expect(page.locator('#video-denied')).toBeVisible();
		await expect(page.locator('#ads')).toHaveCount(0);
		await expect(page.locator('[data-consent-link="optout"]')).toHaveCount(0);
		const state = JSON.parse(await page.locator('#consent-state').textContent() ?? '{}');
		expect(state.mode).toBe('optin');
		expect(state.decided).toBe(false);
		expect(state.consent.analytics).toBe('undecided');
	});

	for (const colorScheme of /** @type {const} */ (['light', 'dark'])) {
		test(`accept all and reject all have equal prominence (${colorScheme})`, async ({ page }) => {
			await page.emulateMedia({ colorScheme });
			await page.goto(HOME);
			const { accept, reject, banner } = ui(page);
			for (const button of [accept, reject]) {
				await expect(button).toHaveClass('prado-consent-button');
			}
			const [a, r] = [await accept.boundingBox(), await reject.boundingBox()];
			expect(a && r).toBeTruthy();
			expect(Math.abs((a?.height ?? 0) - (r?.height ?? 0))).toBeLessThanOrEqual(1);
			expect(Math.abs((a?.width ?? 0) - (r?.width ?? 0))).toBeLessThanOrEqual(2);
			const style = (/** @type {import('@playwright/test').Locator} */ l) => l.evaluate((el) => {
				const s = getComputedStyle(el);
				return [s.backgroundColor, s.color, s.fontWeight, s.fontSize, s.borderColor].join('|');
			});
			expect(await style(accept)).toBe(await style(reject));
			const dark = colorScheme === 'dark';
			await expect(accept).toHaveCSS('background-color', dark ? 'rgb(240, 240, 240)' : 'rgb(26, 26, 26)');
			await expect(banner).toHaveCSS('background-color', dark ? 'rgb(31, 31, 31)' : 'rgb(255, 255, 255)');
			// The policy link: #8ab4f8 on #1f1f1f is 7.8:1, #0b57d0 on #fff is 6.4:1.
			await expect(banner.locator('.prado-consent-policy')).toHaveCSS('color', dark ? 'rgb(138, 180, 248)' : 'rgb(11, 87, 208)');
		});
	}

	test('the banner reserves scroll padding so it does not cover focused content', async ({ page }) => {
		await page.goto(HOME);
		const padding = () => page.evaluate(() => getComputedStyle(document.documentElement).scrollPaddingBottom);
		expect(await padding()).toBe('224px');
		await ui(page).accept.click();
		await expect(ui(page).banner).toHaveCount(0);
		expect(await padding()).toBe('auto');
	});

	test('accept all by callback updates the page in place and sets the cookie', async ({ page, context }) => {
		await page.goto(HOME);
		await markWindow(page);
		await ui(page).accept.click();
		await expect(ui(page).banner).toHaveCount(0);
		await expect(page.locator('#video-embed')).toBeVisible();
		await expect(page.locator('#ads')).toBeVisible();
		await expectSameWindow(page);
		const cookie = (await context.cookies()).find((c) => c.name === 'prado_consent');
		expect(cookie?.httpOnly).toBe(true);
		expect(cookie?.sameSite).toBe('Lax');
		expect((cookie?.expires ?? 0) - Date.now() / 1000).toBeGreaterThan(179 * 86400);
		const state = await consentAfterReload(page);
		expect(state.decided).toBe(true);
		expect(state.consent).toEqual({ necessary: 'granted', analytics: 'granted', marketing: 'granted', functional: 'granted', personalization: 'granted', video: 'granted' });
		expect(state.record.s).toBe('banner');
		await expect(ui(page).banner).toHaveCount(0);
	});

	test('reject all by callback denies every optional category', async ({ page }) => {
		await page.goto(HOME);
		await markWindow(page);
		await ui(page).reject.click();
		await expect(ui(page).banner).toHaveCount(0);
		await expect(page.locator('#video-denied')).toBeVisible();
		await expectSameWindow(page);
		const state = await consentAfterReload(page);
		expect(state.consent.analytics).toBe('denied');
		expect(state.consent.necessary).toBe('granted');
		await expect(page.locator('#video-embed')).toHaveCount(0);
	});

	test('the consent log records the choice with hashed identifiers', async ({ page }) => {
		await page.goto(HOME);
		await ui(page).accept.click();
		await expect(ui(page).banner).toHaveCount(0);
		const response = await page.request.get(LOG);
		const entries = await response.json();
		expect(entries).toHaveLength(1);
		expect(entries[0].source).toBe('banner');
		expect(entries[0].version).toBe('2026-09');
		expect(entries[0].ip_hash).toMatch(/^[0-9a-f]{64}$/);
		expect(entries[0].categories.analytics).toBe('granted');
	});
});

test.describe('without JavaScript', () => {
	test.use({ javaScriptEnabled: false });

	test('the banner works by postback', async ({ page }) => {
		await page.goto(HOME);
		await ui(page).reject.click();
		await page.waitForLoadState();
		await expect(ui(page).banner).toHaveCount(0);
		expect((await consentAfterReload(page)).consent.marketing).toBe('denied');
	});

	test('customize and save work by postback', async ({ page }) => {
		await page.goto(HOME);
		await ui(page).customize.click();
		await page.waitForLoadState();
		await ui(page).checkbox('video').check();
		await ui(page).save.click();
		await page.waitForLoadState();
		await expect(ui(page).preferences).toHaveCount(0);
		await expect(page.locator('#video-embed')).toBeVisible();
		const state = await consentAfterReload(page);
		expect(state.consent.video).toBe('granted');
		expect(state.consent.analytics).toBe('denied');
		expect(state.record.s).toBe('preferences');
	});

	test('the footer link follows PreferencesUrl to the settings page', async ({ page }) => {
		await page.goto(HOME);
		await page.locator('#FooterLink').click();
		await expect(page).toHaveURL(/page=Settings/);
		await expect(page.locator('.prado-consent-preferences-inner')).toBeVisible();
	});
});

test.describe('preferences', () => {
	test('customize opens them in place; save records the checkboxes', async ({ page }) => {
		await page.goto(HOME);
		await markWindow(page);
		const { banner, customize, preferences, checkbox, save } = ui(page);
		await customize.click();
		await expect(preferences).toBeVisible();
		await expect(banner).toHaveCount(0);
		await expect(checkbox('necessary')).toBeChecked();
		await expect(checkbox('necessary')).toBeDisabled();
		for (const category of ['analytics', 'marketing', 'functional', 'personalization', 'video']) {
			await expect(checkbox(category)).not.toBeChecked();
		}
		await expect(preferences.locator('[data-consent-category="video"] .prado-consent-description')).toHaveText('Embedded video players.');
		await checkbox('video').check();
		await checkbox('analytics').check();
		await save.click();
		await expect(preferences).toHaveCount(0);
		await expect(page.locator('#video-embed')).toBeVisible();
		await expect(page.locator('#ads')).toHaveCount(0);
		await expectSameWindow(page);
		const state = await consentAfterReload(page);
		expect(state.consent).toMatchObject({ analytics: 'granted', video: 'granted', marketing: 'denied', functional: 'denied' });
		expect(state.record.s).toBe('preferences');
	});

	test('the footer link reopens them with the current choice', async ({ page }) => {
		await page.goto(HOME);
		await ui(page).accept.click();
		await expect(ui(page).banner).toHaveCount(0);
		await page.locator('[data-consent-link="preferences"]').last().click();
		const { preferences, checkbox, save } = ui(page);
		await expect(preferences).toBeVisible();
		await expect(checkbox('marketing')).toBeChecked();
		await checkbox('marketing').uncheck();
		await save.click();
		await expect(preferences).toHaveCount(0);
		await expect(page.locator('#ads')).toHaveCount(0);
		expect((await consentAfterReload(page)).consent.marketing).toBe('denied');
	});

	test('the gate\'s own link opens them', async ({ page }) => {
		await page.goto(HOME);
		await page.locator('#video-denied').getByRole('link', { name: 'Allow video' }).click();
		await expect(ui(page).preferences).toBeVisible();
		await page.getByRole('button', { name: 'Close' }).click();
		await expect(ui(page).preferences).toHaveCount(0);
		await expect(ui(page).banner).toBeVisible();
	});

	test('a settings page shows them always, without the packaged stylesheet', async ({ page }) => {
		await page.goto(SETTINGS);
		const preferences = page.locator('.prado-consent-preferences-inner');
		await expect(preferences).toBeVisible();
		await expect(preferences.getByRole('heading')).toHaveText('Your privacy settings');
		await expect(page.getByRole('button', { name: 'Close' })).toHaveCount(0);
		await expect(page.locator('link[href$="consent.css"]')).toHaveCount(0);
		await preferences.locator('[data-consent-category="analytics"] input').check();
		await Promise.all([
			page.waitForResponse((response) => response.request().method() === 'POST'),
			preferences.getByRole('button', { name: 'Save choices' }).click(),
		]);
		await expect(preferences).toBeVisible();
		await expect(preferences.locator('[data-consent-category="analytics"] input')).toBeChecked();
		expect((await consentAfterReload(page)).consent.analytics).toBe('granted');
	});
});

test.describe('Global Privacy Control in an opt-in region', () => {
	test.use({ extraHTTPHeaders: { 'Sec-GPC': '1' } });

	test('the banner says so and accept all leaves marketing off', async ({ page }) => {
		await page.goto(HOME);
		await expect(page.locator('.prado-consent-gpc')).toBeVisible();
		await ui(page).accept.click();
		await expect(ui(page).banner).toHaveCount(0);
		await expect(page.locator('#video-embed')).toBeVisible();
		await expect(page.locator('#ads')).toHaveCount(0);
		const state = await consentAfterReload(page);
		expect(state.consent.marketing).toBe('denied');
		expect(state.consent.analytics).toBe('granted');
	});

	test('an explicit grant in the preferences stands', async ({ page }) => {
		await page.goto(HOME);
		await ui(page).customize.click();
		await ui(page).checkbox('marketing').check();
		await ui(page).save.click();
		await expect(page.locator('#ads')).toBeVisible();
		expect((await consentAfterReload(page)).consent.marketing).toBe('granted');
	});
});

test.describe('an opt-out region (California)', () => {
	test.use({ extraHTTPHeaders: { 'X-Test-Region': 'US', 'X-Test-Subdivision': 'CA' } });

	test('no banner, the opt-out link, and everything granted', async ({ page }) => {
		const state = await consentAfterReload(page);
		expect(state.mode).toBe('optout');
		expect(state.region).toBe('US-CA');
		expect(state.decided).toBe(true);
		expect(state.consent.marketing).toBe('granted');
		await expect(ui(page).banner).toHaveCount(0);
		await expect(page.locator('#video-embed')).toBeVisible();
		const optOut = page.locator('[data-consent-link="optout"]');
		await expect(optOut).toHaveText('Do Not Sell or Share My Personal Information');
		await optOut.click();
		await ui(page).checkbox('marketing').uncheck();
		await ui(page).save.click();
		await expect(page.locator('#ads')).toHaveCount(0);
	});
});

test.describe('an opt-out region with Global Privacy Control', () => {
	test.use({ extraHTTPHeaders: { 'X-Test-Region': 'US', 'X-Test-Subdivision': 'CA', 'Sec-GPC': '1' } });

	test('marketing is off without a choice', async ({ page }) => {
		const state = await consentAfterReload(page);
		expect(state.consent.marketing).toBe('denied');
		expect(state.consent.analytics).toBe('granted');
		await expect(page.locator('#ads')).toHaveCount(0);
		await expect(ui(page).banner).toHaveCount(0);
	});
});

test.describe('a region outside every list', () => {
	test.use({ extraHTTPHeaders: { 'X-Test-Region': 'JP' } });

	test('no banner, no opt-out link, everything granted', async ({ page }) => {
		const state = await consentAfterReload(page);
		expect(state.mode).toBe('none');
		expect(state.consent.analytics).toBe('granted');
		await expect(ui(page).banner).toHaveCount(0);
		await expect(page.locator('[data-consent-link="optout"]')).toHaveCount(0);
		await expect(page.locator('#video-embed')).toBeVisible();
	});
});

test.describe('a page without preferences', () => {
	test('the link is a plain link to PreferencesUrl', async ({ page }) => {
		await page.goto(BARE);
		const link = page.locator('#BareLink');
		await expect(link).toHaveAttribute('href', '?page=Settings');
		await link.click();
		await expect(page).toHaveURL(/page=Settings/);
		await expect(page.locator('.prado-consent-preferences-inner')).toBeVisible();
	});

	test('customize links to PreferencesUrl and the banner stays until then', async ({ page }) => {
		await page.goto(BARE);
		const { banner, accept, reject } = ui(page);
		const customize = banner.getByRole('link', { name: 'Customize' });
		await expect(customize).toHaveAttribute('href', '?page=Settings');
		await expect(accept).toBeVisible();
		await expect(reject).toBeVisible();
		await customize.click();
		await expect(page).toHaveURL(/page=Settings/);
		await expect(page.locator('.prado-consent-preferences-inner')).toBeVisible();
		await page.locator('[data-consent-category="video"] input').check();
		await Promise.all([
			page.waitForResponse((response) => response.request().method() === 'POST'),
			page.getByRole('button', { name: 'Save choices' }).click(),
		]);
		expect((await consentAfterReload(page)).consent.video).toBe('granted');
	});
});

test.describe('keyboard focus', () => {
	test('customize moves the focus to the preferences heading; close returns it', async ({ page, browserName }) => {
		await page.goto(HOME);
		const { customize, preferences } = ui(page);
		await customize.focus();
		await page.keyboard.press('Enter');
		await expect(preferences).toBeVisible();
		await expect.poll(() => focusedId(page)).toBe('Preferences_Heading');
		await expect(preferences.getByRole('heading')).toHaveAttribute('tabindex', '-1');
		// WebKit, like Safari by default, tabs to text fields only.
		if (browserName !== 'webkit') {
			// The necessary box is disabled, so Tab from the heading reaches analytics first.
			await page.keyboard.press('Tab');
			expect(await focusedId(page)).toBe('Preferences_Category_analytics');
			await page.keyboard.press('Space');
			await expect(ui(page).checkbox('analytics')).toBeChecked();
		}
		await page.getByRole('button', { name: 'Close' }).focus();
		await page.keyboard.press('Enter');
		await expect(preferences).toHaveCount(0);
		await expect(ui(page).banner).toBeVisible();
		await expect.poll(() => focusedId(page)).toBe('Banner_Customize');
	});

	test('accept all in the banner moves the focus to the footer link', async ({ page }) => {
		await page.goto(HOME);
		await ui(page).accept.focus();
		await page.keyboard.press('Enter');
		await expect(ui(page).banner).toHaveCount(0);
		await expect.poll(() => focusedId(page)).toBe('FooterLink');
	});

	test('reject all in the banner moves the focus to the last consent link', async ({ page }) => {
		await page.goto(HOME);
		await ui(page).reject.focus();
		await page.keyboard.press('Enter');
		await expect(ui(page).banner).toHaveCount(0);
		await expect.poll(() => focusedId(page)).toBe('FooterLink');
	});

	test('the footer link opens the preferences; save returns the focus to it', async ({ page }) => {
		await page.goto(HOME);
		await ui(page).accept.click();
		await expect(ui(page).banner).toHaveCount(0);
		await page.locator('#FooterLink').focus();
		await page.keyboard.press('Enter');
		const { preferences, save } = ui(page);
		await expect(preferences).toBeVisible();
		await expect.poll(() => focusedId(page)).toBe('Preferences_Heading');
		await save.focus();
		await page.keyboard.press('Enter');
		await expect(preferences).toHaveCount(0);
		await expect.poll(() => focusedId(page)).toBe('FooterLink');
	});

	test('accept all in the preferences opened by customize focuses the page start', async ({ page }) => {
		await page.goto(HOME);
		await ui(page).customize.focus();
		await page.keyboard.press('Enter');
		const { preferences } = ui(page);
		await expect.poll(() => focusedId(page)).toBe('Preferences_Heading');
		await preferences.getByRole('button', { name: 'Accept all' }).focus();
		await page.keyboard.press('Enter');
		await expect(preferences).toHaveCount(0);
		await expect(ui(page).banner).toHaveCount(0);
		await expect.poll(() => focusedId(page)).toBe('BODY');
		expect((await consentAfterReload(page)).record.s).toBe('preferences');
	});

	test('on a settings page the focus stays on the button pressed', async ({ page }) => {
		await page.goto(SETTINGS);
		const save = page.getByRole('button', { name: 'Save choices' });
		await save.focus();
		await page.keyboard.press('Enter');
		await expect.poll(() => focusedId(page)).toBe('Preferences_Save');
		await expect(page.locator('.prado-consent-preferences-inner')).toBeVisible();
	});
});

test.describe('CSRF', () => {
	test('a callback with a tampered token is rejected', async ({ page }) => {
		await page.goto(HOME);
		await page.evaluate(() => { /** @type {HTMLInputElement} */ (document.getElementById('PRADO_CSRF_TOKEN')).value = 'tampered'; });
		const [response] = await Promise.all([
			page.waitForResponse((r) => r.request().method() === 'POST'),
			ui(page).accept.click(),
		]);
		expect(response.status()).toBe(400);
		const state = await consentAfterReload(page);
		expect(state.decided).toBe(false);
		await expect(ui(page).banner).toBeVisible();
	});

	test('a postback with a tampered token is rejected', async ({ page }) => {
		await page.goto(HOME);
		const field = (/** @type {string} */ name) => page.locator(`input[name="${name}"]`).inputValue();
		const form = {
			PRADO_PAGESTATE: await field('PRADO_PAGESTATE'),
			PRADO_CSRF_TOKEN: 'tampered',
			PRADO_POSTBACK_TARGET: 'Banner$AcceptAll',
			'Banner$AcceptAll': 'Accept all',
		};
		const rejected = await page.request.post(HOME, { form, failOnStatusCode: false });
		expect(rejected.status()).toBe(400);
		expect((await consentAfterReload(page)).decided).toBe(false);
		// The same postback with the page's token is accepted.
		form.PRADO_CSRF_TOKEN = await field('PRADO_CSRF_TOKEN');
		const accepted = await page.request.post(HOME, { form });
		expect(accepted.status()).toBe(200);
		expect((await consentAfterReload(page)).consent.analytics).toBe('granted');
	});
});
