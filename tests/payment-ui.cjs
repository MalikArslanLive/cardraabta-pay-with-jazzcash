const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require('playwright');

const source = path.join(__dirname, '..');
const origin = 'https://cardraabta-fixture.example';

async function verifyRefund(page) {
    await page.route(origin + '/wp-admin/admin-ajax.php', route => {
        const body = new URLSearchParams(route.request().postData());
        assert.equal(body.get('action'), 'crj_direct_refund');
        assert.equal(body.get('_wpnonce'), 'fixture');
        assert.equal(body.get('crj_amount'), '10.00');
        return route.fulfill({ json: { success: false, message: 'Merchant MPIN not found.', code: '999' } });
    });
    await page.route(origin + '/history', route => route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: '<details class="crj-refund" data-cancel="Cancel" data-processing="Processing refund…" data-ajax-url="/wp-admin/admin-ajax.php">' +
            '<summary>Refund</summary><form><h2>CardRaabta Refund</h2><input type="hidden" name="action" value="crj_direct_refund">' +
            '<input type="hidden" name="_wpnonce" value="fixture"><input name="crj_amount" type="number" value="10.00">' +
            '<div class="crj-refund-actions"><button>Process Refund</button></div></form></details>'
    }));
    await page.goto(origin + '/history');
    await page.addScriptTag({ path: path.join(source, 'assets/refund.js') });
    await page.getByRole('button', { name: 'Refund', exact: true }).click();
    await page.getByRole('button', { name: 'Process Refund' }).click();
    await page.getByText('Merchant MPIN not found. (Code: 999)', { exact: true }).waitFor();
    assert.equal(page.url(), origin + '/history');
    console.log('PASS: refund dialog keeps the history page and displays the exact provider error and code');
}

(async () => {
    const browser = await chromium.launch({
        ...(process.env.CRJ_CHROMIUM ? { executablePath: process.env.CRJ_CHROMIUM } : {}),
        headless: true,
        args: ['--no-sandbox']
    });
    try {
        const refunds = await browser.newPage();
        refunds.setDefaultTimeout(5000);
        await verifyRefund(refunds);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
