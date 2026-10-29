// Isolated SQLite fixture; all API requests are intercepted to localhost.
const { chromium } = require(process.env.NUERS_PLAYWRIGHT_PATH);
const fs = require('fs');
const base = 'http://127.0.0.1:8098';
(async () => {
 const browser = await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
 const context = await browser.newContext({viewport:{width:1600,height:1000}});
 const checks = [], errors = [], failedApi = [];
 await context.route('**/*', async route => {
  const u = new URL(route.request().url());
  if(u.hostname === 'nuers.net' && u.pathname.startsWith('/public/')) {
   const response = await route.fetch({url:base+u.pathname+u.search});
   await route.fulfill({response}); return;
  }
  if(u.hostname === '127.0.0.1' || ['data:','blob:'].includes(u.protocol)) return route.continue();
  return route.abort();
 });
 const s=JSON.parse(fs.readFileSync('/private/tmp/nuers-infra-preview-session.json','utf8'));
 await context.addInitScript(s=>{localStorage.setItem('nuers_api_token',s.token);localStorage.setItem('nuers_browser_device',s.device)},s);
 const page=await context.newPage(); page.on('pageerror',e=>errors.push(e.message));
 page.on('response',r=>{if(r.url().includes('/public/api/') && r.status()>=400)failedApi.push({url:new URL(r.url()).pathname,status:r.status()})});
 await page.goto(base+'/super-admin/reports');await page.getByRole('heading',{name:'Reports & Exports'}).waitFor();
 const buttons=page.getByRole('button',{name:/Download (CSV|JSON)/});
 if(await buttons.count()!==6)throw Error('Expected six actual report exports');
 for(let i=0;i<6;i++){
  const event=page.waitForEvent('download');await buttons.nth(i).click();const download=await event;
  const path='output/admin-security-audit/ui-report-'+i+(i===5?'.json':'.csv');await download.saveAs(path);
  if(!fs.statSync(path).size)throw Error('Empty export '+i);
 }
 checks.push('All six authenticated reports downloaded real backend responses');
 await page.locator('aside img').first().evaluate(el=>{if(!el.complete||!el.naturalWidth)throw Error('Brand image not loaded')});
 await page.screenshot({path:'output/admin-security-audit/reports-desktop.png',fullPage:true});
 await page.getByLabel('Business date from').fill('2026-10-20');await page.getByLabel('Business date to').fill('2026-10-19');await buttons.first().click();await page.getByRole('alert').filter({hasText:'End date must'}).waitFor();checks.push('Invalid report date range blocked');
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:'output/admin-security-audit/reports-mobile.png',fullPage:true});
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);if(overflow)throw Error('Report mobile overflow');checks.push('Report mobile layout');
 await page.setViewportSize({width:1600,height:1000});await page.goto(base+'/super-admin/users-rbac');
 const nav=page.getByRole('navigation',{name:'User directory pagination'});await nav.getByText('Page 1 of 2').waitFor();
 await nav.getByRole('button',{name:'Next',exact:true}).click();await nav.getByText('Showing 26–33 of 33 profiles').waitFor();
 await page.getByPlaceholder('Search name, email, TIN, organization...').fill('Audit Client 29');await nav.getByText('Showing 1–1 of 1 profiles').waitFor();await page.getByText('Audit Client 29',{exact:true}).waitFor();checks.push('Server-side directory paging and full-dataset search');
 await page.getByPlaceholder('Search name, email, TIN, organization...').fill('Audit Merchant ALPHA');await page.getByText('Audit Merchant ALPHA',{exact:true}).waitFor();
 await page.locator('button[data-slot="dropdown-menu-trigger"]').last().click();await page.getByRole('menuitem',{name:'Edit Profile'}).click();
 const dialog=page.getByRole('dialog');await dialog.waitFor();const inputs=dialog.locator('input');
 if(!await inputs.nth(0).isDisabled() || await inputs.nth(1).getAttribute("readonly") === null)throw Error('Controlled merchant identity inputs editable');
 await page.screenshot({path:'output/admin-security-audit/merchant-identity-controls.png',fullPage:true});await page.keyboard.press('Escape');checks.push('Merchant identity editing routed through registry');
 await page.goto(base+'/super-admin/security');await page.getByText('Software identity',{exact:true}).waitFor();await page.getByText('API account isolation',{exact:true}).waitFor();await page.getByText('Required external evidence',{exact:true}).waitFor();await page.screenshot({path:'output/admin-security-audit/readiness-evidence.png',fullPage:true});checks.push('Readiness reports actual configuration and missing external evidence');
 await page.goto(base+'/api/eis/performance');await page.getByRole('heading',{name:'API Integrations',exact:true}).waitFor();checks.push('Legacy performance routes to measured integrations workspace');
 await page.goto(base+'/taxpayer/registry');await page.getByRole('heading',{name:'Registered Businesses',exact:true}).waitFor();await page.getByText('Audit Merchant ALPHA',{exact:true}).first().waitFor();checks.push('Legacy registry uses real business records');
 await page.goto(base+'/api/sdks');await page.waitForURL('**/api/docs');checks.push('Stale SDK instructions redirect to maintained API documentation');
 const anon=await browser.newContext();const ap=await anon.newPage();await anon.route('https://nuers.net/**',r=>r.abort());await ap.goto(base+'/verify');await ap.getByRole('heading',{name:'Look up your recorded receipts.'}).waitFor();if(await ap.getByRole('button',{name:'Verify Receipt',exact:true}).isEnabled())throw Error('Anonymous lookup enabled');checks.push('Anonymous receipt lookup UI requires authentication');await ap.screenshot({path:'output/admin-security-audit/receipt-auth-required.png',fullPage:true});
 if(errors.length || failedApi.length)throw Error(JSON.stringify({errors,failedApi}));
 fs.writeFileSync('output/admin-security-audit/ui-results.json',JSON.stringify({environment:'isolated localhost SQLite; synthetic users; external requests blocked',checks,pageErrors:errors,failedApi,mobileOverflow:overflow},null,2));
 console.log(JSON.stringify({passed:checks.length,errors,failedApi}));await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
