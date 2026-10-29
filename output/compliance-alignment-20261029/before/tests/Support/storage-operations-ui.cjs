const {chromium}=require(process.env.NUERS_PLAYWRIGHT_PATH);
const fs=require('fs');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
 const context=await browser.newContext({viewport:{width:1600,height:1100}});
 const errors=[],failed=[];
 let stale=false;
 await context.route('**/*',async route=>{
  const u=new URL(route.request().url());
  if(u.hostname==='nuers.net' && u.pathname.startsWith('/public/')){
   const r=await route.fetch({url:'http://127.0.0.1:8098'+u.pathname+u.search});
   if(stale && u.pathname.endsWith('/operations/readiness')){
    const body=await r.json();body.storage_operations.runtime.fresh=false;
    return route.fulfill({response:r,json:body});
   }
   return route.fulfill({response:r});
  }
  if(u.hostname==='127.0.0.1'||['data:','blob:'].includes(u.protocol))return route.continue();
  return route.abort();
 });
 const s=JSON.parse(fs.readFileSync('/private/tmp/nuers-infra-preview-session.json','utf8'));
 await context.addInitScript(s=>{localStorage.setItem('nuers_api_token',s.token);localStorage.setItem('nuers_browser_device',s.device)},s);
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 page.on('response',r=>{if(r.url().includes('/public/api/')&&r.status()>=400)failed.push({path:new URL(r.url()).pathname,status:r.status()})});
 await page.goto('http://127.0.0.1:8098/super-admin/security');
 await page.getByText('Core storage checks passed',{exact:true}).waitFor();
 await page.getByText('Audit and wallet checks passed',{exact:true}).waitFor();
 await page.getByText('Local backup evidence needed',{exact:true}).waitFor();
 await page.getByRole('table').getByRole('cell',{name:'mail',exact:true}).waitFor();
 await page.screenshot({path:'output/storage-operations-audit/readiness-desktop.png',fullPage:true});
 stale=true;await page.getByRole('button',{name:'Refresh',exact:true}).click();
 await page.getByText('Monitoring stale or unavailable',{exact:true}).waitFor();
 await page.setViewportSize({width:390,height:844});
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);
 await page.screenshot({path:'output/storage-operations-audit/readiness-mobile.png',fullPage:true});
 if(overflow||errors.length||failed.length)throw Error(JSON.stringify({overflow,errors,failed}));
 await page.getByRole('link',{name:'Backup & recovery evidence →'}).click();await page.waitForURL('**/super-admin/backups');
 fs.writeFileSync('output/storage-operations-audit/ui-results.json',JSON.stringify({scope:'localhost synthetic; all external requests blocked; stale response explicitly simulated',checks:['Actual API health results render','Audit and wallet integrity render','No invented backup success','Delivery backlog renders','Stale observation loses healthy badge','Mobile has no horizontal overflow','Backup evidence link resolves'],errors,failed,mobileOverflow:overflow},null,2));
 console.log('7 storage operations UI checks passed');await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
