// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for the prado-privacy end-to-end tests.
 *
 * PHP's built-in server serves a small PRADO application from tests/playwright/app.
 * The specs open its pages in a real browser and drive the consent controls: the
 * banner's accept-all and reject-all (postback and callback), the preferences, the
 * link that reopens them, and the gates around embedded content.
 *
 * Run:
 *   npm install && npx playwright install chromium
 *   npx playwright test --project=chromium
 *   HEADLESS=false npx playwright test     # watch it run
 */
const PORT = 8381;

export default defineConfig({
	testDir: './tests/playwright',
	testMatch: '**/*.spec.js',

	timeout: 30_000,
	forbidOnly: !!process.env.CI,
	retries: 0,
	workers: 1,

	reporter: [
		['list'],
		['html', { outputFolder: 'build/playwright-report', open: 'never' }],
	],

	webServer: {
		command: `php -d display_errors=stderr -d session.save_path=/tmp -S 127.0.0.1:${PORT} -t tests/playwright`,
		url: `http://127.0.0.1:${PORT}/status.txt`,
		reuseExistingServer: !process.env.CI,
		timeout: 30_000,
	},

	use: {
		baseURL: `http://127.0.0.1:${PORT}`,
		headless: process.env.HEADLESS !== 'false',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},

	projects: [
		// PW_CHROMIUM points at a Chromium binary when the one Playwright expects is not installed.
		{ name: 'chromium', use: { ...devices['Desktop Chrome'], launchOptions: process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {} } },
		{ name: 'firefox', use: { ...devices['Desktop Firefox'] } },
		{ name: 'webkit', use: { ...devices['Desktop Safari'] } },
	],

	outputDir: 'build/playwright-results',
});
