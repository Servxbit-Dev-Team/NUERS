import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import { Workbook, SpreadsheetFile } from '@oai/artifact-tool';
import {cases,testData} from './cases.mjs';

const dir=path.dirname(new URL(import.meta.url).pathname);
const root=path.resolve(dir,'../..');
const wb=Workbook.create();
const overview=wb.worksheets.add('QA Overview');
const tests=wb.worksheets.add('Test Cases');
const pages=wb.worksheets.add('Page Inventory');
const data=wb.worksheets.add('Test Data');
const defects=wb.worksheets.add('Defect Log');
const navy='#082B52', blue='#005BDF', muted='#537091', light='#EFF5FC', amber='#FFF5D6', red='#BA223B';
const statuses=['Not Run','In Progress','Pass','Fail','Blocked','Deferred','N/A'];
const letters=n=>{let s='';for(n++;n;n=Math.floor((n-1)/26))s=String.fromCharCode(65+(n-1)%26)+s;return s;};
function base(sheet,lastCol,lastRow,widths){
 sheet.showGridLines=false;
 sheet.getRange(`A1:${lastCol}${lastRow}`).format={font:{name:'Arial',size:11,color:navy},verticalAlignment:'center',rowHeight:23};
 widths.forEach((w,i)=>sheet.getRange(`${letters(i)}1:${letters(i)}${lastRow}`).format.columnWidth=w);
 sheet.getRange(`A1:${lastCol}1`).format.rowHeight=10;
 sheet.getRange(`A4:${lastCol}4`).format.borders={bottom:{style:'thin',color:blue}};
 sheet.getRange(`A4:${lastCol}4`).format.rowHeight=8;
}
function title(sheet,text,context){
 sheet.getRange('A2').values=[[text]];
 sheet.getRange('A2').format.font={name:'Arial',size:17,bold:true,color:navy};
 sheet.getRange('A2').format.rowHeight=29;
 sheet.getRange('A3').values=[[context]];
 sheet.getRange('A3').format.font={name:'Arial',size:11,color:muted};
 sheet.getRange('A3').format.rowHeight=24;
}
function header(sheet,range){sheet.getRange(range).format={fill:navy,font:{name:'Arial',size:11,bold:true,color:'#FFFFFF'},wrapText:true,horizontalAlignment:'center',verticalAlignment:'center',rowHeight:34};}
function table(sheet,headers,rows,name,start=5){
 const last=start+rows.length, col=letters(headers.length-1);
 sheet.getRange(`A${start}:${col}${last}`).values=[headers,...rows];
 const t=sheet.tables.add(`A${start}:${col}${last}`,true,name);
 t.showFilterButton=true;
 header(sheet,`A${start}:${col}${start}`);
 sheet.getRange(`A${start+1}:${col}${last}`).format.wrapText=true;
 sheet.getRange(`A${start+1}:${col}${last}`).format.verticalAlignment='top';
 for(let i=0;i<rows.length;i++){
   if(i%2===1)sheet.getRange(`A${start+1+i}:${col}${start+1+i}`).format.fill=light;
 }
 return last;
}
function statusRange(sheet,range,col,start){
 sheet.getRange(range).dataValidation={rule:{type:'list',values:statuses}};
 sheet.getRange(range).conditionalFormats.addCustom(`$${col}${start}="Fail"`,{fill:'#FDEBED',font:{color:red,bold:true}});
 sheet.getRange(range).conditionalFormats.addCustom(`OR($${col}${start}="Blocked",$${col}${start}="Deferred")`,{fill:amber,font:{color:'#805A00'}});
 sheet.getRange(range).conditionalFormats.addCustom(`$${col}${start}="Pass"`,{font:{color:'#14734F'}});
}
const testHeaders=['Test ID','Area','Priority','Result','Scenario','Steps','Expected result','Test data','Test role','Execution phase','Actual result','Evidence / attachment link','Defect ID','Tester','Tested on (PHT)','Release / build','Environment'];
const rows=cases.map(c=>[c.id,c.module,c.priority,'Not Run',c.scenario,c.steps,c.expected,c.data,c.role,c.phase,'','','','','','','']);
base(tests,'Q',rows.length+5,[16,28,11,15,36,66,72,20,28,27,60,40,18,20,23,26,23]);
title(tests,'NUERS QA TEST CASES','225 scenarios. Each parameterized variant needs evidence. Enter execution results in the amber cells.');
const last=table(tests,testHeaders,rows,'NuersTestCases');
tests.getRange(`D6:D${last}`).format.fill=amber;
tests.getRange(`K6:Q${last}`).format.fill='#FFF9E9';
tests.getRange(`O6:O${last}`).setNumberFormat('yyyy-mm-dd hh:mm');
statusRange(tests,`D6:D${last}`,'D',6);
for(let i=0;i<cases.length;i++){
 const c=cases[i];
 const lines=Math.max(Math.ceil(c.scenario.length/33),Math.ceil(c.steps.length/61),Math.ceil(c.expected.length/67),Math.ceil(c.module.length/25));
 tests.getRange(`A${i+6}:Q${i+6}`).format.rowHeight=Math.max(70,lines*15+17);
}
tests.freezePanes.freezeRows(5);tests.freezePanes.freezeColumns(2);
tests.tabColor=blue;

// Extract routes from the declared JSX tree; nested child routes inherit their parent URL.
const app=await fs.readFile(path.join(root,'src/App.tsx'),'utf8');
const routes=[];let parent='';let role='Public';
function routeRole(line,inherited='Public'){
 const m=line.match(/role=\{\[([^\]]+)\]\}/);
 if(m)return [...m[1].matchAll(/"([^"]+)"/g)].map(v=>v[1]).join(', ');
 const one=line.match(/role="([^"]+)"/);if(one)return one[1];
 if(line.includes('<RequireAuth>'))return 'Authenticated; verify resource policy';
 if(line.includes('<GuestOnly>'))return 'Guest only';
 return inherited;
}
for(const line of app.split('\n')){
 if(line.trim()==='</Route>'){parent='';role='Public';continue;}
 if(!line.includes('<Route ')&&!line.includes('<Route index'))continue;
 const p=line.match(/<Route path="([^"]+)"/);
 const idx=line.includes('<Route index');
 if(!p&&!idx)continue;
 const raw=p?.[1]||'';
 const full=raw.startsWith('/')?raw:(parent+(raw?'/'+raw:''));
 const scoped=routeRole(line,raw.startsWith('/')?'Public':role);
 const open=line.trimEnd().endsWith('}>');
 if(open&&raw.startsWith('/')){parent=raw;role=scoped;continue;}
 const target=line.match(/<Navigate to="([^"]+)"/);
 const component=(line.match(/element=\{<(\w+)/)||[])[1]||'';
 routes.push({url:full||'/',roles:scoped,kind:target?'Redirect':'Page',target:target?.[1]||'',component});
}
assert.equal(new Set(routes.map(r=>r.url)).size,routes.length,'Duplicate route inventory');
const pageRows=routes.map((r,i)=>[`URL-${String(i+1).padStart(3,'0')}`,r.url,r.roles,r.kind,r.target||'PAGE-001 to PAGE-008',r.kind==='Redirect'?'Expected redirect, authentication and destination scope.':'Navigation; API access; controls; responsive UI; loading; branding; export; timing.','Not Run','','','','']);
base(pages,'K',pageRows.length+5,[16,44,47,14,40,65,15,36,46,22,24]);
title(pages,'NUERS PAGE INVENTORY','Declared frontend routes as of 29 October 2026. Route access shown here is a starting point; verify server authorization separately.');
const pLast=table(pages,['Route ID','URL pattern','Declared route access','Route type','Destination / common cases','Required coverage','Result','Failed test IDs','Evidence / notes','Tester','Release / build'],pageRows,'NuersPageInventory');
pages.getRange(`G6:K${pLast}`).format.fill='#FFF9E9';statusRange(pages,`G6:G${pLast}`,'G',6);
pages.getRange(`A6:K${pLast}`).format.rowHeight=70;
pages.freezePanes.freezeRows(5);pages.freezePanes.freezeColumns(2);

base(data,'D',43,[15,29,84,83]);
title(data,'NUERS QA TEST DATA','Synthetic fixtures and proposed performance targets. Record exact fixture version and approved oracle before execution.');
const dLast=table(data,['Data ID','Fixture','Required setup / values','Expected control / use'],testData,'NuersTestData');
data.getRange(`A6:D${dLast}`).format.rowHeight=86;
data.getRange('A28').values=[['PROPOSED PERFORMANCE TARGETS']];data.getRange('A28').format.font.bold=true;
data.getRange('A29:D29').values=[['Target ID','Measure','Proposed acceptance target','Protocol / limitation']];header(data,'A29:D29');
const targets=[
 ['T01','Paged read API','p95 ≤ 2 s; p99 ≤ 5 s on supported indexed filters.','Measure at the agreed concurrency with 1M rows, warm/cold caches and realistic selectivity. Proposed target, not an existing SLA.'],
 ['T02','First useful page content','p95 ≤ 3 s on the agreed desktop/network profile.','Measure rows/widgets becoming usable, not just HTML response. Track slow devices separately.'],
 ['T03','Realtime freshness','Configured revision check around 1 s; changed data visible p95 ≤ 5 s after commit.','Keep one coordinated refresh loop; no overlapping heavy queries or unexpected 429 storm. Record browser background behavior.'],
 ['T04','Read/write correctness','0 tenant leaks, 0 duplicate accepted sales/fees, 0 unexplained balance or total mismatch.','Expected validation/permission rejections are not service failures. Reconcile after every load/recovery run.'],
 ['T05','Unexpected request failure','Unexpected 5xx/timeouts < 0.1% during the agreed steady-state run.','Separate scheduled fault injection and expected throttling. Log absolute request counts and each failure reason.'],
 ['T06','Load protocol','Ramp 1, 10, 50, 100 concurrent users; higher load only against an agreed capacity plan.','Run for 15 minutes per stage, then 2-hour soak. Capture p50/p95/p99, throughput, CPU/RAM, DB plans/locks and queue lag.'],
 ['T07','Exports and recovery','Agree size/job completion and RTO/RPO targets before execution; record actual results.','No invented production recovery result. Large PDFs may have explicit supported limits; no silent truncation.'],
 ['T08','Approval record','QA lead: ______   Ops lead: ______   Approved targets/date: ______','Write the exact environment, hardware, build, data volume, scenario mix and target deviations in execution evidence.']
];
data.getRange('A30:D37').values=targets;data.getRange('A30:D37').format.wrapText=true;data.getRange('A30:D37').format.verticalAlignment='top';data.getRange('A30:D37').format.rowHeight=86;
data.freezePanes.freezeRows(5);

base(defects,'M',35,[18,20,19,19,38,70,62,62,40,22,25,25,25]);
title(defects,'NUERS QA DEFECT LOG','Record a defect for each confirmed failure; link test ID, exact build and reproducible evidence.');
const defectRows=Array.from({length:25},()=>Array(13).fill(''));
table(defects,['Defect ID','Test / route ID','Severity','Status','Summary','Steps to reproduce','Expected result','Actual result / evidence','Environment / build','Owner','Found on (PHT)','Retested on (PHT)','Retest evidence'],defectRows,'NuersDefects');
defects.getRange('A6:M30').format.fill='#FFF9E9';defects.getRange('A6:M30').format.rowHeight=65;
defects.getRange('C6:C30').dataValidation={rule:{type:'list',values:['S1 Critical','S2 High','S3 Medium','S4 Low']}};
defects.getRange('D6:D30').dataValidation={rule:{type:'list',values:['New','In Progress','Fixed','Awaiting Retest','Reopened','Closed','Accepted Risk']}};
defects.getRange('K6:L30').setNumberFormat('yyyy-mm-dd hh:mm');
defects.freezePanes.freezeRows(5);defects.freezePanes.freezeColumns(2);

base(overview,'H',66,[46,13,12,13,13,15,15,27]);
title(overview,'NUERS QA MASTER CHECKLIST','Software 1.0.0 | Plan dated 29 October 2026 | Enter the exact release/build tested');
overview.tabColor=navy;
const logo=await fs.readFile(path.join(root,'public/brand/nuers-official-logo.png'));
overview.images.add({dataUrl:`data:image/png;base64,${logo.toString('base64')}`,anchor:{from:{row:1,col:6},extent:{widthPx:230,heightPx:86}}});
overview.getRange('A6:D6').values=[['Detailed test cases',null,'Page routes',routes.length]];
overview.getRange('B6').formulas=[[`=COUNTA('Test Cases'!A6:A${last})`]];
overview.getRange('A7:D7').values=[['Passed test cases',null,'Failed',null]];
overview.getRange('B7').formulas=[[`=COUNTIFS('Test Cases'!D6:D${last},"Pass")`]];
overview.getRange('D7').formulas=[[`=COUNTIFS('Test Cases'!D6:D${last},"Fail")`]];
overview.getRange('A8:D8').values=[['Not Run',null,'External gates',cases.filter(c=>/required/.test(c.phase)).length]];
overview.getRange('B8').formulas=[[`=COUNTIFS('Test Cases'!D6:D${last},"Not Run")`]];
overview.getRange('A6:D8').format.rowHeight=26;
overview.getRange('B6:B8').format.font.bold=true;
overview.getRange('D6:D8').format.font.bold=true;
const modules=[...new Set(cases.map(c=>c.module))];
overview.getRange('A10:F10').values=[['Coverage area','Cases','P0','Passed','Failed','Other results']];header(overview,'A10:F10');
for(let i=0;i<modules.length;i++){
 const r=11+i;overview.getRange(`A${r}`).values=[[modules[i]]];
 overview.getRange(`B${r}:F${r}`).formulas=[[
 `=COUNTIFS('Test Cases'!$B$6:$B$${last},$A${r})`,
 `=COUNTIFS('Test Cases'!$B$6:$B$${last},$A${r},'Test Cases'!$C$6:$C$${last},"P0")`,
 `=COUNTIFS('Test Cases'!$B$6:$B$${last},$A${r},'Test Cases'!$D$6:$D$${last},"Pass")`,
 `=COUNTIFS('Test Cases'!$B$6:$B$${last},$A${r},'Test Cases'!$D$6:$D$${last},"Fail")`,
 `=B${r}-SUM(D${r}:E${r})`
 ]];
 if(i%2)overview.getRange(`A${r}:F${r}`).format.fill=light;
 overview.getRange(`A${r}:F${r}`).format.rowHeight=25;
}
overview.getRange('A33').values=[['EXECUTION GUIDE']];overview.getRange('A33').format.font.bold=true;
const guidance=[
 ['Plan status','No system QA execution is claimed by this workbook. All cases/routes start Not Run. Existing automated tests are supporting coverage, not current results.'],
 ['Environment','Run functional mutations on staging with synthetic data. Run performance, fault injection and recovery in an isolated environment. Live smoke is read-only unless separately authorized.'],
 ['Execution order','1. Login and tenant isolation  2. Invoice/API/wallet smoke  3. Full functional cases  4. All page routes/exports  5. Security/load/recovery  6. Defect retest and regression.'],
 ['Priorities','P0: security, ownership, financial correctness, immutable records or release-critical control. P1: core usability, reporting and operational behavior. Run P0 first.'],
 ['Record a result','For each variant record Result, Actual result, Evidence, Tester, Tested on, Build and Environment. Evidence should include request/response or screenshot plus relevant record IDs, with secrets redacted.'],
 ['Status definitions','Not Run: no execution. In Progress: underway. Pass: expected result met with evidence. Fail: mismatch. Blocked: unmet prerequisite. Deferred: approved later scope. N/A: documented reason and QA approval.'],
 ['Page inventory','Run PAGE-001 to PAGE-008 on every route and each nested tab/modal. Substitute actual authorized IDs for URL parameters. Declared route access is not proof of server authorization.'],
 ['External gates','BIR-issued credentials/authorization and unprovisioned infrastructure are separate gated cases. Use Blocked/Deferred with owner and prerequisite; do not mark Pass based on a mock.'],
 ['Receipt baseline','Current shared receipt omits the removed Record Evidence / verification / custom footer section. Preserve merchant logo, invoice identity, line items, financial totals and tax breakdown.'],
 ['Performance baseline','Test both 1M platform rows and 1M rows for one merchant. Proposed targets are on Test Data; QA/Ops must confirm them for the exact environment before measurement.'],
 ['Entry criteria','Identified release/build; isolated environment and mail sink; approved account matrix, fixtures/oracles and credentials; stable dependency setup; recovery path for test data.'],
 ['Exit criteria','All in-scope P0/P1 cases and page checks passed with evidence; no open critical/high security or financial defects; fixes retested; scope exceptions and external gates explicitly signed off.'],
 ['Defect severity','S1: data leak/loss, unauthorized access or widespread outage. S2: wrong financial result, blocked core flow or major control failure. S3: limited function defect. S4: minor visual/text issue.'],
 ['Sign-off','QA lead: ______  Product owner: ______  Security/Ops reviewer: ______  Release/build: ______  Date: ______  Approved exceptions and unresolved gates: ______'],
 ['Coverage basis','Current NUERS module/route inventory, API contract, existing regression suite names and accepted product requirements through 29 October 2026. This plan does not certify BIR approval.']
];
// Wide prose uses one descriptive column without merging or tiny fonts.
overview.getRange('A34:A48').values=guidance.map(x=>[x[0]]);
overview.getRange('B34:B48').values=guidance.map(x=>[x[1]]);
// Put the readable guide in ordinary cells below the summary: spans empty cells by overflow.
overview.getRange('A34:A48').format.font.bold=true;
overview.getRange('B34:B48').format.wrapText=false;
overview.getRange('A34:H48').format.rowHeight=26;
// Detailed guidance is also kept in a dedicated wide area of the same sheet for printing/readability.
// Use row-wise wrapped values across a single wide column on Test Data, where widths support prose.
data.getRange('A40:D40').values=[['Guide','Topic','Execution guidance','']];header(data,'A40:D40');
for(let i=0;i<guidance.length;i++){
 const r=41+i;
 data.getRange(`A${r}:D${r}`).values=[[`G${i+1}`,guidance[i][0],guidance[i][1],'']];
 data.getRange(`A${r}:D${r}`).format={font:{name:'Arial',size:11,color:navy},wrapText:true,verticalAlignment:'top',rowHeight:Math.max(65,Math.ceil(guidance[i][1].length/78)*15+18)};
}
// Overview keeps concise instructions; long guidance lives only on Test Data.
overview.getRange('A33:H48').clear({applyTo:'contents'});
overview.getRange('A33').values=[['START HERE']];overview.getRange('A33').format.font.bold=true;
const quick=[
 'All results start Not Run. This workbook is a test plan, not a completed audit.',
 'Use Test Cases for steps/results; Page Inventory for every route; Test Data for fixtures and execution guide.',
 'P0 = security/financial/release-critical. P1 = core functional/operational. Run P0 first.',
 'BIR and infrastructure cases marked as required dependencies remain gated until provisioned.',
 'Enter the exact build, environment, tester, time, actual result and evidence for each executed case.',
 'Exit: in-scope P0/P1 passed; no open critical/high defects; retests complete; exceptions approved.'
];
quick.forEach((v,i)=>overview.getRange(`A${34+i}`).values=[[v]]);
overview.getRange('A34:H39').format.font={name:'Arial',size:11,color:muted};overview.getRange('A34:H39').format.rowHeight=26;
overview.getRange('A42').values=[['QA lead: __________________  Release/build: __________________  Environment: __________________']];
overview.getRange('A44').values=[['Sign-off and approved exceptions: ______________________________________________________________________']];

assert.equal(cases.length,new Set(cases.map(c=>c.id)).size);
assert(cases.every(c=>c.steps&&c.expected&&/^P[01]$/.test(c.priority)));
wb.recalculate();
assert.equal(overview.getRange('B6').values[0][0],cases.length);
assert.equal(overview.getRange('B7').values[0][0],0);
assert.equal(overview.getRange('B8').values[0][0],cases.length);
// Exercise status recalculation, then restore the unexecuted baseline.
tests.getRange('D6').values=[['Pass']];
assert.equal(overview.getRange('B7').values[0][0],1);
tests.getRange('D6').values=[['Not Run']];
wb.recalculate();
const inspection=await wb.inspect({kind:'table',range:'QA Overview!A6:F15',include:'values,formulas',tableMaxRows:10,tableMaxCols:6,maxChars:3500});
await fs.writeFile(path.join(dir,'verification-inspect.ndjson'),inspection.ndjson);
const errors=await wb.inspect({kind:'match',searchTerm:'#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!',options:{useRegex:true,maxResults:30},summary:'QA workbook formula error scan'});
await fs.writeFile(path.join(dir,'verification-errors.ndjson'),errors.ndjson);
const xlsx=await SpreadsheetFile.exportXlsx(wb);
await xlsx.save(path.join(dir,'NUERS_QA_Master_Checklist_v1.0.0.xlsx'));
const views=[['QA Overview','A1:H30','overview'],['Test Cases','A5:G9','cases'],['Page Inventory','A5:G9','pages'],['Test Data','A5:D8','data'],['Defect Log','A5:H8','defects']];
for(const [sheetName,range,name] of views){
 const img=await wb.render({sheetName,range,scale:1,format:'png'});
 await fs.writeFile(path.join(dir,`${name}-preview.png`),new Uint8Array(await img.arrayBuffer()));
}
await fs.writeFile(path.join(dir,'manifest.json'),JSON.stringify({softwareVersion:'1.0.0',planDate:'2026-10-29',testCases:cases.length,areas:modules.length,pageRoutes:routes.length,externalGates:cases.filter(c=>/required/.test(c.phase)).length,allResults:'Not Run',formulaStatusChangeVerified:true,systemTestsExecuted:false},null,2));
console.log(JSON.stringify({cases:cases.length,areas:modules.length,routes:routes.length,output:path.join(dir,'NUERS_QA_Master_Checklist_v1.0.0.xlsx'),formulaErrors:errors.ndjson}));
