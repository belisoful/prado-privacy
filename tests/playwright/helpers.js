// @ts-check
import { expect } from '@playwright/test';

export const HOME = '/app/index.php?page=Home';
export const SETTINGS = '/app/index.php?page=Settings';
export const LOG = '/app/index.php?page=Log';

/**
 * Reads the module's consent as the page rendered it (#consent-state), after a GET of the home
 * page, so the value reflects the stored cookie rather than the page's last callback.
 * @param {import('@playwright/test').Page} page
 */
export async function consentAfterReload(page) {
	await page.goto(HOME);
	return JSON.parse(await page.locator('#consent-state').textContent() ?? '{}');
}

/**
 * Marks the window, so a spec can assert that a click did not reload the page.
 * @param {import('@playwright/test').Page} page
 */
export async function markWindow(page) {
	await page.evaluate(() => { /** @type {any} */ (window).__noReload = true; });
}

/**
 * @param {import('@playwright/test').Page} page
 */
export async function expectSameWindow(page) {
	expect(await page.evaluate(() => /** @type {any} */ (window).__noReload === true)).toBe(true);
}

/**
 * The banner, the preferences, and their buttons, by role and text.
 * @param {import('@playwright/test').Page} page
 */
export function ui(page) {
	const banner = page.locator('.prado-consent-banner-inner');
	const preferences = page.locator('.prado-consent-preferences-inner');
	return {
		banner,
		preferences,
		accept: banner.getByRole('button', { name: 'Accept all' }),
		reject: banner.getByRole('button', { name: 'Reject all' }),
		customize: banner.getByRole('button', { name: 'Customize' }),
		save: preferences.getByRole('button', { name: 'Save choices' }),
		checkbox: (/** @type {string} */ category) => preferences.locator(`[data-consent-category="${category}"] input[type=checkbox]`),
	};
}
