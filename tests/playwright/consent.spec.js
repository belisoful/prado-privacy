// @ts-check
import { test, expect } from '@playwright/test';
import { HOME, LOG, SETTINGS, consentAfterReload, expectSameWindow, markWindow, ui } from './helpers.js';

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

	test('accept all and reject all have equal prominence', async ({ page }) => {
		await page.goto(HOME);
		const { accept, reject } = ui(page);
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
