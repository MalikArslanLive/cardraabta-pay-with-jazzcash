const { chromium } = require('playwright');
(async()=>{
 const browser=await chromium.launch({...(process.env.CRJ_CHROMIUM ? {executablePath:process.env.CRJ_CHROMIUM} : {}),headless:true,args:['--no-sandbox']});
 const page=await browser.newPage();
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://localhost:8080/wp-login.php');
 await page.locator('#user_login').fill('crjtest');
 await page.locator('#user_pass').fill('local-test-only-password');
 await Promise.all([page.waitForURL(/wp-admin/),page.locator('#wp-submit').click()]);
 await page.goto('http://localhost:8080/wp-admin/admin.php?page=crj-transactions');
 await page.getByRole('heading',{name:'📊 Transaction History',exact:true}).waitFor();
 const button=page.locator('.crj-view-details').first();
 await button.waitFor();
 {await button.click();await page.waitForFunction(()=>document.querySelector('.crj-view-details')?.getAttribute('aria-expanded')==='true');await button.click();await page.waitForFunction(()=>document.querySelector('.crj-view-details')?.getAttribute('aria-expanded')==='false');}
 
 await page.goto('http://localhost:8080/wp-admin/admin.php?page=wc-settings&tab=checkout&section=crj_card');
 await page.locator('#woocommerce_crj_card_environment').waitFor();
 await page.locator('#woocommerce_crj_card_environment').selectOption('live');
 await page.locator('#woocommerce_crj_card_environment').selectOption('sandbox');
 
 if(errors.length)throw new Error(errors.join('\n'));
 console.log('PASS: manager transactions, details toggle, settings environment switch; no JavaScript exceptions');
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
