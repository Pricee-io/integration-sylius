// Admin part of the end-to-end tests, driven in a real browser by e2e/run.sh:
// log in, open the sync page, check the channel and the category counts,
// then run the sync, which sends one request per batch from the page's
// JavaScript.
//
// Env: SHOP_URL, ADMIN_USER, ADMIN_PASSWD, SYNC_CATEGORY, SYNC_PRODUCTS.
import { chromium } from 'playwright';

const env = (name) => {
    const value = process.env[name];
    if (!value) {
        throw new Error(`${name} is not set`);
    }

    return value;
};

const shopUrl = env('SHOP_URL');
const category = env('SYNC_CATEGORY');
const products = env('SYNC_PRODUCTS');

const browser = await chromium.launch();
const page = await browser.newPage();
page.setDefaultTimeout(60000);

try {
    await page.goto(`${shopUrl}/admin/login`);
    await page.locator('input[name="_username"]').fill(env('ADMIN_USER'));
    await page.locator('input[name="_password"]').fill(env('ADMIN_PASSWD'));
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login')),
        page.locator('input[name="_password"]').press('Enter'),
    ]);
    console.log('ok - logged in to the admin');

    // From the menu, as a merchant reaches it
    await page.goto(`${shopUrl}/admin/`);
    await page.locator('a[href$="/admin/priceeio/sync"]').first().click();
    await page.locator('#priceeio-sync-form').waitFor();
    console.log('ok - sync page opened from the admin menu');

    // The channel served on the admin's host is preselected, with its own category counts
    const channel = await page.locator('#priceeio-sync-channel').inputValue();
    if (channel !== 'E2E_WEB') {
        throw new Error(`unexpected preselected channel: ${channel}`);
    }
    console.log('ok - channel of the admin host preselected');

    const checkbox = page.locator('.form-check', { hasText: `${category} (${products})` }).locator('input');
    await checkbox.check();
    await page.locator('#priceeio-sync-form button[type="submit"]').click();
    const result = page.locator('#priceeio-sync-result.alert-success');
    await result.waitFor({ timeout: 120000 });
    const message = (await result.innerText()).trim();
    if (!message.startsWith(`${products} `)) {
        throw new Error(`unexpected sync result: ${message}`);
    }
    console.log(`ok - sync finished: ${message}`);
} catch (error) {
    await page.screenshot({ path: '/output/failure.png', fullPage: true }).catch(() => {});
    console.error(`FAIL: ${error.message} (page: ${page.url()}, screenshot: e2e/output/failure.png)`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
