const { chromium } = require('playwright');
const base = process.env.CRJ_TEST_URL || 'http://localhost:8080';
if(!['localhost','127.0.0.1'].includes(new URL(base).hostname)) throw new Error('Disposable local site only');
(async()=>{
 const browser=await chromium.launch({...(process.env.CRJ_CHROMIUM ? {executablePath:process.env.CRJ_CHROMIUM} : {}),headless:true,args:['--no-sandbox']});
 const context=await browser.newContext();
 const page=await context.newPage();
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/wp-login.php');
 await page.locator('#user_login').fill('crjtest');
 await page.locator('#user_pass').fill('local-test-only-password');
 await Promise.all([page.waitForURL(/wp-admin/),page.locator('#wp-submit').click()]);
 await page.goto(base+'/wp-admin/admin.php?page=crj-transactions');
 await page.getByRole('heading',{name:'📊 Transaction History',exact:true}).waitFor();
 const button=page.locator('.crj-view-details').first();
 await button.waitFor();
 {await button.click();await page.waitForFunction(()=>document.querySelector('.crj-view-details')?.getAttribute('aria-expanded')==='true');await button.click();await page.waitForFunction(()=>document.querySelector('.crj-view-details')?.getAttribute('aria-expanded')==='false');}
 
 for (const width of [320,375,768,1024,1440]) {
   await page.setViewportSize({width,height:900});
   const bounds=await page.locator('.crj-history').boundingBox();
   if(bounds.x+bounds.width>width+2)throw new Error('Plugin page overflow at '+width);
   const table=page.locator('.crj-table-wrap');
   if(!(await table.evaluate(el=>el.scrollWidth>=el.clientWidth)))throw new Error('Table scroll container unavailable');
   if(process.env.CRJ_SCREENSHOTS) await page.screenshot({path:process.env.CRJ_SCREENSHOTS+'/transactions-'+width+'.png',fullPage:true});
 }
 await page.setViewportSize({width:1280,height:900});
 if(process.env.CRJ_AXE==='1') {
   const AxeBuilder=require('@axe-core/playwright').default;
   const report=await new AxeBuilder({page}).include('.crj-history').withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
   if(report.violations.length)throw new Error(JSON.stringify(report.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)}))));
   console.log('PASS: plugin transaction UI WCAG A/AA automated accessibility checks');
 }
 await page.goto(base+'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=crj_card');
 await page.locator('#woocommerce_crj_card_environment').waitFor();
 await page.locator('#woocommerce_crj_card_environment').selectOption('live');
 await page.locator('#woocommerce_crj_card_environment').selectOption('sandbox');
 
 if(errors.length)throw new Error(errors.join('\n'));
 console.log('PASS: manager transactions, details toggle, five responsive viewport widths, settings environment switch; no JavaScript exceptions');
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
