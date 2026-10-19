from pathlib import Path
import importlib.util, json, statistics, hashlib, math
from datetime import datetime, timezone, timedelta
from reportlab.pdfgen import canvas
from reportlab.lib.colors import HexColor, white
from reportlab.lib.styles import ParagraphStyle
from reportlab.platypus import Paragraph, Flowable
from pypdf import PdfReader, PdfWriter
from pypdf.generic import NameObject, DictionaryObject, BooleanObject

ROOT=Path('/Users/marksioson/Desktop/NUERS')
BASE=ROOT/'output/backup-dr'; TMP=ROOT/'tmp/pdfs/backup-dr'
OUT=ROOT/'output/pdf/NUERS-Backup-Procedure-and-Disaster-Recovery-Plan-v1.0.0.pdf'
spec=importlib.util.spec_from_file_location('manual',ROOT/'output/technical-manual/build_manual.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
R=json.loads((BASE/'lab-results.json').read_text())
PAGES=[]
def page(ch,title,dek,*items):PAGES.append(dict(ch=ch,title=title,dek=dek,items=items))
p,h,note,table,bullets,diagram=m.p,m.h,m.note,m.table,m.bullets,m.diagram
CW,PW,PH=m.CW,m.PW,m.PH
NAVY,BLUE,PALE,GOLD,INK,GREY,LINE=m.NAVY,m.BLUE,m.PALE,m.GOLD,m.INK,m.GREY,m.LINE

class DRDiagram(Flowable):
 def __init__(self,kind):super().__init__();self.kind=kind;self.width=CW;self.height=335
 def draw(self):
  c=self.canv;w=self.width
  c.setFillColor(HexColor('#f4f8ff'));c.roundRect(0,0,w,self.height,10,fill=1,stroke=0)
  def txt(s,x,y,width,bold=False):
   style=ParagraphStyle('d',fontName='Bold' if bold else 'Body',fontSize=9,leading=13,textColor=INK,alignment=1)
   q=Paragraph(s,style);_,hh=q.wrap(width,100);q.drawOn(c,x,y-hh/2)
  def box(x,y,width,height,s,color=LINE):
   c.setFillColor(white);c.setStrokeColor(color);c.setLineWidth(1.1);c.roundRect(x,y,width,height,7,fill=1,stroke=1);txt(s,x+9,y+height/2,width-18)
  def arr(x1,y1,x2,y2):
   c.setStrokeColor(BLUE);c.setFillColor(BLUE);c.setLineWidth(1.4);c.line(x1,y1,x2,y2)
   a=math.atan2(y2-y1,x2-x1);s=5;path=c.beginPath();path.moveTo(x2,y2);path.lineTo(x2-s*math.cos(a-.5),y2-s*math.sin(a-.5));path.lineTo(x2-s*math.cos(a+.5),y2-s*math.sin(a+.5));path.close();c.drawPath(path,fill=1,stroke=0)
  if self.kind=='backup':
   txt('PROPOSED BACKUP CONTROL PATH',15,312,w-30,True)
   box(20,232,130,55,'Primary writer<br/>DB + private objects')
   box(177,232,130,55,'Consistent checkpoint<br/>DB position + manifest')
   box(334,232,130,55,'Encrypted backup<br/>Hash + key reference')
   arr(150,260,177,260);arr(307,260,334,260)
   box(334,133,130,55,'Independent vault<br/>Version retention lock',GOLD);arr(399,232,399,188)
   box(177,133,130,55,'Isolated restore<br/>Workers / egress off',GOLD);arr(334,160,307,160)
   box(20,133,130,55,'Verify data + money<br/>Objects + audit + IDs');arr(177,160,150,160)
   box(20,35,287,56,'Evidence + independent review<br/>Accept, remediate or reject recovery point');arr(85,133,85,91)
   box(334,35,130,56,'Separate key custody<br/>Restricted recovery',GOLD);arr(334,65,307,65)
  elif self.kind=='recovery':
   steps=[('1 Declare + fence','Confirm outage; stop all writers'),('2 Restore clean point','Database, objects, keys and exact release'),('3 Validate + reconcile','Ledger, audit, sequence and unknown sends'),('4 Authorize + reopen','One writer; controlled worker restart'),('5 Observe + fail back','Measure recovery; rebuild standby safely')]
   for i,(a,b) in enumerate(steps):
    y=264-i*59;box(28,y,w-56,47,'<b>'+a+'</b><br/>'+b)
    if i<4:arr(w/2,y,w/2,y-12)
  elif self.kind=='measurement':
   txt('TWO DIFFERENT MEASURES',15,310,w-30,True)
   box(22,232,139,52,'Recovered commit<br/>C<sub>restored</sub>');box(322,232,139,52,'Last source commit<br/>C<sub>source</sub>')
   arr(163,258,320,258);txt('Observed RPO gap',167,281,151,True)
   box(22,116,139,52,'Outage begins<br/>T<sub>outage</sub>');box(322,116,139,52,'Service accepted<br/>T<sub>recovered</sub>')
   arr(163,142,320,142);txt('Observed recovery time',165,165,154,True)
   txt('A completed file copy is not the end of a full-service RTO test.<br/>Integrity, access, application and business acceptance are required.',29,53,w-58)
  elif self.kind=='lab':
   txt('ACTUAL ISOLATED TEST BOUNDARY',15,312,w-30,True)
   box(25,230,190,60,'Fresh migrated SQLite schema<br/>500 synthetic financial events')
   box(268,230,190,60,'8 encrypted private files<br/>16 MiB plaintext total');arr(215,259,266,259)
   box(25,134,190,60,'SQLite Backup API snapshot<br/>Encrypted DB + signed manifest');arr(120,230,120,194)
   box(268,134,190,60,'Primary file renamed unavailable<br/>New empty restore directory');arr(215,164,266,164)
   box(25,38,190,60,'Read lab keys from escrow<br/>Verify counts, ledger and files');arr(363,134,363,112);arr(363,112,120,112);arr(120,112,120,98)
   box(268,38,190,60,'Measured local recovery time<br/>No DNS, cloud or live traffic');arr(215,68,266,68)
  elif self.kind=='priority':
   for i,(a,b) in enumerate([('FOUNDATION','Access, clean host, network, time and key custody'),('STATE','Database, document objects, audit and wallet chains'),('SERVICE','API and portal checks, source identity and reconciliation'),('DELIVERY','Customer mail, callbacks and authorized external jobs'),('CONFIDENCE','Monitoring, evidence, business acceptance and standby')]):
    y=265-i*60;box(30,y,w-60,47,'<b>'+a+'</b><br/>'+b)
    if i<4:arr(w/2,y,w/2,y-13)

m.Diagram=DRDiagram

page('01 | Control and assurance','Document control','A practical recovery plan with measured, explicitly limited lab evidence.',
 table(['Field','Controlled value'],[
 ['System','NUERS - National Unified Electronic Receipt System'],['Software / document edition','1.0.0 / 1.0 | 19 October 2026'],['Document ID','NUERS-BCDR-1.0.0-20261019'],['Owner / audience','NUERS service owner; infrastructure, application, security, finance, support and assurance teams'],['Status','Prepared operating plan. Production policy, named owners and infrastructure implementation require approval. Lab results are executed observations, not independent certification.'],['Evidence basis','Local source review; isolated synthetic restore test; 15 application governance tests; selected official references.'],['Distribution','Controlled operational reference. No production secrets or customer data included.'],['Print format','A4, actual size, duplex, long-edge binding. Mirrored margins. Printed copies are uncontrolled after a newer edition is released.']],[.28,.72]),
 p('The owner confirmed that no existing production restore report is available and authorized clearly labeled lab results. Dataplugs, Contabo and AWS Singapore / Hong Kong remain intended future providers or locations; no recovery capacity there is claimed.'),
 note('Evidence labels: MEASURED LAB = actually executed within the stated local scope. CODE BASELINE = reviewed implementation. PROPOSED = operating requirement or target awaiting approval. UNVERIFIED = evidence not available.'))

page('01 | Control and assurance','Executive recovery position','Keep tested component behavior separate from operational service commitments.',
 table(['Measure','Position as of this edition'],[
 ['Production RTO / RPO','UNVERIFIED. No provider failover, full-service restoration or production commit-loss measurement was performed.'],['Proposed service objectives','RTO: 4 hours. RPO: 15 minutes for the consistent critical dataset. Both require business approval and production-like validation.'],['Actual lab restore time','Three quiesced local runs: 0.334-0.341 seconds, measured from simulated outage to component validation. This is a component RTO proxy, not a cloud-service recovery guarantee.'],['Actual lab data gap','Quiesced runs: 0 seconds / 0 missing synthetic events. Deliberately older checkpoint: 3.393 seconds / 3 missing synthetic events.'],['Failure controls','Wrong backup key, altered encrypted database, missing private object and incorrect plaintext hash were rejected.'],['Application governance','15 tests / 156 assertions passed. Their fixture timestamps are not operational RTO/RPO measurements.']],[.31,.69]),
 h('Immediate operational priorities'),
 p('Choose the actual hosting and recovery locations; assign named on-call owners; implement and monitor encrypted offsite backups; test key recovery; and complete a full-scale database-engine restore plus end-user acceptance before publishing a recovery commitment.'),
 note('The laboratory used a small local dataset and a pre-existing runtime. Its subsecond timing must not appear in a customer SLA or accreditation claim as the production RTO.'))

page('02 | Objectives and impact','Definitions and measurement rules','Use recoverable business state, not backup-job success, as the measure.',
 table(['Term','NUERS operating interpretation'],[
 ['RTO target','Approved maximum time from the actual start of business-service interruption to validated restoration of the agreed minimum service.'],['Observed recovery time','T(recovered and accepted) minus T(outage began). Record declaration delay separately; do not restart the clock when an engineer begins work.'],['RPO target','Approved maximum tolerable age of lost committed data at the interruption boundary. Define it for all dependent stores, not only the database.'],['Observed data-loss interval','C(last acknowledged source commit) minus C(latest contiguous recovered commit). Also count lost records and financial value.'],['Recovery point age','T(outage) minus C(recovered). Report alongside commit gap, especially during quiet periods when no new data exists.'],['MTPD / business tolerance','Maximum tolerable period of disruption. Proposed critical-service planning ceiling: 8 hours, requiring service-owner approval.'],['Recovery complete','Authorized users can access the intended data and services; integrity and financial checks pass; delivery ambiguity is reconciled; monitoring is active.']],[.29,.71]),
 p('Use UTC timestamps with precision and a monotonic elapsed timer when available. Preserve the timezone, clock source and uncertainty. The lab uses observed transaction-commit markers rather than database log sequence numbers; production tests need engine-native positions and business acknowledgments.'),
 note('Planning reference: NIST SP 800-34 Rev. 1 describes contingency planning, business impact analysis and recovery planning. The specific targets and NUERS procedures in this document are proposed local policy. [R1]'))

page('02 | Objectives and impact','Recovery timing and data-loss diagram','D01 - Distinguish the outage clock from the recovered-data watermark.',
 diagram('measurement','The RPO gap is not the backup duration. A restore can be fast while recovering an old or incomplete dataset.'),
 p('For a multi-store service, identify the latest point at which transactions, receipts, wallet entries, intake identities and private file metadata are consistent. A database restored beyond available object versions may expose records whose documents cannot be read.'),
 p('Use the oldest necessary recoverable dependency as the effective service boundary. Log replay is useful only when its continuity, encryption keys and linked object versions can be demonstrated. Report gaps explicitly and quarantine affected records.'),
 note('This edition measures local component restoration and an observed synthetic transaction watermark. Network service availability, full business acceptance and production data-location recovery remain untested.'))

page('02 | Objectives and impact','Business impact and restoration tiers','PROPOSED - Approve these priorities with the service owner and merchants.',
 table(['Tier / service','Target and business impact','Restore dependency'],[
 ['0 - Recovery foundation','Access within 30 minutes as an internal planning step; unavailable keys or hosts block every later stage.','Clean administrative access, key custody, DNS authority, time, image and network controls.'],['1 - Critical financial state','RTO 4 hours; consistent RPO 15 minutes. Missing acceptance or money records may cause duplicate charges, incorrect balances or document loss.','Database, private objects, audit, wallet, source identities, exact application release.'],['2 - Delivery services','Resume within the Tier 1 window when safe; clear reconciled backlog within a separately agreed window.','Committed email/webhook jobs, customer PDF data, signing keys and recipient/endpoint state.'],['3 - Reports and exports','Target 8 hours; rebuild only from verified original records.','Tenant/environment filters, financial snapshots, audit references.'],['4 - Non-critical presentation','Target 24 hours where separately approved. Required receipt logos/templates must instead accompany Tier 1.','Static assets, optional analytics, derived caches.']],[.25,.44,.31]),
 p('The proposed 8-hour disruption ceiling is not a contract or proven capability. If the business cannot tolerate the proposed 15-minute data-loss window, change the architecture and test it before offering a tighter objective.'),
 note('No fallback procedure may fabricate NUERS acceptance, BIR acknowledgment or a successful wallet debit. External systems should retain unsent events in their own durable queues and show a clear pending/error state.'))

page('03 | Ownership and safeguards','Roles, authority and escalation','PROPOSED - Fill named primary and alternate contacts before operational use.',
 table(['Role','Responsibility / decision'],[
 ['Service owner','Approves impact tolerance, recovery objectives, budget, residual loss and return to service.'],['Incident commander','Declares recovery, coordinates timeline and communications, authorizes runbook stages and prevents conflicting changes.'],['Infrastructure / database lead','Fences writers; restores clean compute, database, logs and objects; records positions and verification evidence.'],['Application lead','Restores the matching release; checks configuration, tenant isolation, financial logic and delivery state.'],['Security / key custodian','Investigates compromise; releases recovery keys under approved custody; validates access and key rotations.'],['Finance / reconciliation owner','Reconciles balances, fees, deposits, transactions, numbering and unresolved external outcomes.'],['Support / communications owner','Publishes approved operational updates and coordinates merchant verification without collecting secrets.'],['Independent reviewer','Checks restore evidence and rejects unsupported success claims. Cannot substitute an unperformed test with a review approval.']],[.32,.68]),
 p('Require an incident lead and a second authorized reviewer for destructive restore or traffic cutover. An emergency exception must record why waiting would increase harm, the approving authority, exact actions and retrospective review. Maintain an offline contact sheet and test access quarterly.'),
 note('No named person, phone number or contractual response time is invented in this plan. Complete the contact register in the appendices and store sensitive contact details in restricted operations records.'))

page('03 | Ownership and safeguards','Recovery safeguards and release gates','Preserve one authoritative writer and one verifiable business history.',
 bullets(
 '<b>Fence before restore:</b> disable old-site database writes and transaction intake; pause schedulers and delivery workers. DNS changes alone do not fence a writer.',
 '<b>Restore into an empty target:</b> never overwrite the only surviving copy. Preserve incident snapshots and immutable evidence before remediation.',
 '<b>Keep external delivery paused:</b> mail, merchant callbacks and government transmission must not replay automatically from restored queues.',
 '<b>Protect identities and secrets:</b> keep tenant scope, Live/Sandbox separation, MFA, API restrictions and key version associations intact.',
 '<b>Reconcile money and identifiers:</b> validate wallet chains, accepted event IDs, invoice numbering and receipts before enabling new writes.',
 '<b>Release on evidence:</b> record test results, known losses, business acceptance and rollback criteria; never label an untested backup as recoverable.'),
 h('Stop conditions'),
 p('Stop recovery release if a checksum fails, a required key cannot be recovered, audit or wallet integrity fails, source/outbox state is ambiguous, the selected recovery point is incomplete, or the old writer cannot be fenced. Escalate to the incident commander and preserve the failed attempt.'),
 note('CODE BASELINE: the NUERS backup dashboard records requests, evidence and independent reviews. It does not itself perform infrastructure backup, live restore or failover. The operator must execute the approved procedure outside that evidence workflow.'))

page('04 | Backup architecture','Planned backup and recovery topology','D02 - Logical control path; provider assignment is still pending.',
 diagram('backup','Each backup set binds database state, private objects, release/configuration references, integrity evidence and key version identifiers.'),
 p('Keep at least one recovery copy outside the primary host and administrative failure domain. A second directory on the same server is useful for speed but cannot protect against host loss or privileged compromise. Keep credentials for backup deletion and production administration separate.'),
 p('The destination must be selected, contracted, access-controlled and tested before it is called an offsite or immutable backup. A provider name or cloud region alone is not evidence of replication, immutability or disaster recovery readiness.'),
 note('CISA recommends offline encrypted backups and regular availability/integrity testing. The NUERS copy layout and schedules here are proposed implementation requirements. [R2]'))

page('04 | Backup architecture','Provider and data-location disclosure','PLANNED ONLY - The owner has not yet adopted this future arrangement.',
 table(['Provider / location','Intended planning status','Evidence required before designation'],[
 ['Dataplugs','Candidate provider; data-center country, primary/backup role and service tier not confirmed.','Contract, actual region/facility, storage scope, subprocessors, access and restore capability.'],['Contabo','Candidate provider; exact region and primary/recovery role not confirmed.','Instance/storage location, operational ownership, independent recovery design and service terms.'],['AWS Singapore','Candidate region ap-southeast-1; workload, vault and recovery roles not allocated.','Account ownership, resource region, encryption/key region, replication and retention settings.'],['AWS Hong Kong','Candidate region ap-east-1; workload, vault and recovery roles not allocated.','Same controls plus access and cross-region transfer validation.'],['Current production','Not inventoried or tested for this PDF. No claim of current provider availability, redundancy or data residence.','Signed inventory of app, database, objects, keys, logs, backup copies and control-plane accounts.']],[.24,.35,.41]),
 p('Record actual data locations for primary databases, document objects, key stores, backup copies, logs and support exports separately. Assess cross-border transfers and privacy obligations before copying production data. Use approved agreements and qualified review for retention or legal requirements.'),
 note('The lab ran on a local Darwin arm64 workstation with SQLite. It did not use Dataplugs, Contabo, AWS, WAN transfer, offsite storage or provider recovery services.'))

page('04 | Backup architecture','Backup asset inventory','Recover linked state together; a database dump alone is insufficient.',
 table(['Asset group','Required contents','Consistency evidence'],[
 ['Financial database','Transactions, receipts, invoice records, wallet/deposit ledger, pricing revision, seller and tax snapshots.','Engine snapshot/log position; balances, counts and linked record IDs.'],['Integration state','Intake identities, canonical hashes, API-key metadata, source profiles, idempotency and delivery intents.','No duplicate acceptance or repeated wallet debit after recovery.'],['Private files','Verification versions, supporting evidence and document files; file metadata, sizes and plaintext hashes.','All required object versions present and decryptable.'],['Presentation and configuration','Dedicated receipt logos, templates, settings, app release, dependency locks, infrastructure definitions.','Manifest matches the restored application and document schema.'],['Identity and access','Users, profiles, grants, MFA devices, recovery state, credential hashes and revocations.','Tenant boundaries, active/revoked identity behavior and key version mapping.'],['Audit / operations','Audit chain/head, checkpoints, incidents, notification attempts and recovery evidence.','Chain integrity plus independently retained checkpoint where available.'],['Secrets / key custody','Application encryption keys, backup keys, signing keys, external credentials and rotation history.','Separate restricted escrow; correct key version decrypts the retained data.']],[.24,.44,.32]),
 note('Do not back up only uploaded plaintext or only encrypted bytes without metadata. Do not place usable keys in a public PDF, repository, support ticket or unencrypted backup manifest.'))

page('05 | Backup procedures','Schedule, retention and monitoring','PROPOSED policy baseline - configure after architecture and retention approval.',
 table(['Asset / activity','Proposed cadence','Retention / monitoring'],[
 ['Database full backup','Daily consistent full backup; hourly recoverable checkpoint where supported.','Daily full sets: 90 days; hourly sets: 7 days. Verify each job, size trend and checksum.'],['Transaction log / PITR','Continuous shipping or batches no longer than 5 minutes.','At least 14 days of complete log chains with their matching full backups. Alert on lag before 15 minutes.'],['Private object versions','Continuous versioning/copy or batches no longer than 5 minutes.','Align recoverable versions with every retained DB point; keep deleted versions for approved recovery window.'],['Release and configuration','Before and after each approved change; daily inventory.','Keep all releases needed by retained backups; no plaintext secrets in artifacts.'],['Audit and manifests','Export/checkpoint at least every 5 minutes where supported.','Independent restricted retention; cover recovery and legal-hold requirements.'],['Offline / isolated copy','Weekly protected copy and custody check.','Maintain 12 weekly sets plus the primary 90-day backup window. Test access monthly.'],['Restore verification','Weekly sample, monthly representative restore, quarterly full DR exercise.','Also after material DB, storage, identity, key or provider changes.']],[.27,.36,.37]),
 p('These are operational recovery windows, not statements of statutory record retention. The data owner must approve legal retention and holds separately. Do not purge historical originals merely because a backup expires.'),
 note('Important gap: hourly database snapshots or six-hour object backups cannot alone establish a 15-minute whole-service RPO. Log continuity and object-version availability must meet the same tested recovery boundary.'))

page('05 | Backup procedures','BP-01 | Prepare and capture a backup','Operator checklist - consistent state first, copy second.',
 table(['Step','Procedure and evidence'],[
 ['1. Authorize','Record job ID, environment, tenant/all-tenant scope, release, operator and approved destination. Verify that capacity and retention policies are active.'],['2. Check dependencies','Verify key references, destination access, synchronized clocks, database health and previous successful backup. Alert on missing prerequisites.'],['3. Choose consistency method','Use the deployed engine\'s supported consistent snapshot plus native log position. For file-based lab SQLite use its Backup API. Do not copy a changing database file blindly.'],['4. Bind object versions','Capture a manifest of private object versions associated with the database point. Quiesce writes if no proven cross-store consistency method exists.'],['5. Capture database and logs','Record snapshot/transaction position, start/end time, row-level control totals and encryption metadata. Confirm the expected backup format and engine compatibility.'],['6. Capture dependent assets','Copy required object versions, templates, release/dependency manifests and configuration references. Exclude live secrets from ordinary artifacts.'],['7. Confirm completion','Verify that the process succeeded, files are non-empty, bytes/hash match and the set is complete. Treat partial completion as failed.']],[.24,.76]),
 p('When quiescing, announce the maintenance window, reject or safely queue new intake, wait for in-flight commits, pause mutating jobs, take the consistency point and record the write-resume time. An outage caused by backup quiescing belongs in service monitoring.'),
 note('The SQLite Backup API provides a consistent database snapshot. Its use in the lab does not validate a production MySQL/MariaDB backup procedure; the deployed engine/version needs its own restore test. [R3]'))

page('05 | Backup procedures','BP-02 | Encrypt, transfer and verify','A backup is usable only after integrity and recoverability are established.',
 table(['Step','Procedure and acceptance evidence'],[
 ['1. Encrypt','Use the approved backup encryption mechanism and versioned key reference. Ensure application-encrypted objects remain encrypted in transit and at rest.'],['2. Authenticate manifest','List backup ID, environment, release, engine/version, capture position, object versions, byte counts, SHA-256 values and key references. Sign/authenticate the manifest with separate restricted custody.'],['3. Transfer','Upload through an approved encrypted transport to an independently administered destination. Record destination object/version identifiers and provider completion evidence.'],['4. Read back','Verify hashes from the destination, not just the local staging file. Confirm retention, versioning, access controls and expected storage region.'],['5. Lock and isolate','Apply the approved retention lock/offline process. Confirm that ordinary application credentials cannot remove protected copies.'],['6. Restore sample','Restore a representative subset into an isolated target; decrypt and inspect private files and verify ledger/identity links. Capture measured results.'],['7. Register evidence','Record backup and test evidence in the NUERS governance workflow, then obtain independent review. Preserve failed attempts and remediation actions.']],[.24,.76]),
 p('Do not mark a backup successful when transfer, verification, key recovery or required assets are missing. Route failures to an accountable operator, retry within the recovery-point budget, and escalate when the latest usable point approaches the RPO target.'),
 note('AWS Object Lock is a possible future WORM control for versioned objects. Governance bypass permissions and retention mode must be evaluated; no such control was configured or tested in this exercise. [R4]'))

page('05 | Backup procedures','Encryption and recovery key custody','A recoverable database with unrecoverable keys is still a failed recovery.',
 p('CODE BASELINE: private documents use authenticated Laravel encryption in bounded frames and verify plaintext size and SHA-256 before exposing contents. Wallet and audit chains provide integrity checks. These application controls do not prove volume encryption, offsite backup encryption or cloud key-service availability.'),
 table(['Control','Proposed operating requirement'],[
 ['Key register','Map each backup and encrypted dataset to required application, backup and signing key versions. Record owner, activation, retirement and recovery location.'],['Custody separation','Keep key escrow separate from backup data and from the same primary host/account failure domain. Use a vault or equivalent approved control.'],['Recovery access','Require named custodians, strong MFA and a second-person approval for emergency key release. Test alternate access without relying on the failed identity service.'],['Rotation','Retain prior decrypting keys for as long as corresponding data/backups must remain readable. Validate restore before retiring a version.'],['Compromise','Preserve evidence; rebuild a clean target; rotate exposed credentials in an ordered plan. Do not rotate the encryption key blindly and render retained data unreadable.'],['Evidence','Record key version and custody approval, never the key value. Store recovery secrets outside this document and ordinary test exports.']],[.28,.72]),
 note('LAB LIMIT: the drill read separate lab-only keys from a restricted local escrow file. This proves the tested decryption path, not dual-person custody, hardware protection or recovery after workstation loss.'))

page('06 | Disaster recovery runbooks','Activation and incident declaration','DR-01 - Establish authority, scope and a reliable outage timeline.',
 table(['Trigger','Response'],[
 ['Primary host or database unavailable','Confirm impact from independent observations; begin outage clock at the actual service interruption; assign an incident commander.'],['Data corruption or integrity failure','Pause writes and delivery. Preserve suspect state and identify the last known clean point. Do not overwrite evidence.'],['Ransomware / privileged compromise','Isolate affected systems and credentials; preserve forensic copies; engage security and restore into a clean environment.'],['Provider / region failure','Use only a separately validated recovery site. Verify that control plane, keys, DNS and backup access do not share the failed dependency.'],['Accidental deletion / bad release','Determine scope. Prefer isolated point-in-time restore and controlled repair where possible; avoid discarding unrelated valid business activity.']],[.33,.67]),
 h('Initial command checklist'),
 p('Open an incident record; record UTC/PHT timestamps and affected service functions; assign operations, security, application, finance and communications owners; freeze unrelated deployments; preserve logs and commit positions; confirm fencing; select the relevant runbook and recovery point.'),
 p('Proposed communications: issue an internal first update within 15 minutes of confirmed major impact and repeat at least every 30 minutes while unstable. State known impact and next update time. Do not publish an unverified recovery ETA.'),
 note('These communication intervals are proposed operating targets. The plan does not establish a legal notification deadline or provider SLA.'))

page('06 | Disaster recovery runbooks','Recovery sequence and control gates','D03 - Move forward only when each preceding gate has evidence.',
 diagram('recovery','Each stage has a named operator, timestamp, validation output and release authority. Preserve failed attempts as part of the incident record.'),
 p('Traffic switching is late in the sequence. Restore and reconcile before accepting new financial events. Prevent split-brain by disabling the old writer at the database/network/service layer and checking that no scheduled worker continues to mutate state.'),
 p('Recovery time includes the time needed to detect the interruption, obtain access, select data, transfer, restore, validate and accept service. A separate component timing can aid diagnosis but does not replace the full-service measure.'),
 note('Live BIR delivery remains governed by its own approved connection, permits and authentic acknowledgment controls. Recovery must preserve those gates and cannot substitute an internal success record for an external acknowledgment.'))

page('06 | Disaster recovery runbooks','DR-02 | Restore to an isolated target','Database, objects and release must describe the same recovery point.',
 table(['Stage','Required action / stop condition'],[
 ['1. Establish clean target','Provision approved OS/runtime/database versions and storage. Verify network restrictions, time, disk capacity and administrative access. Keep user traffic and outbound delivery blocked.'],['2. Select backup set','Confirm clean point, manifest authentication, hashes, engine version, required logs, object versions and decrypting keys. Stop if any required item is absent.'],['3. Restore database','Use the engine-supported restore procedure. Replay only the verified continuous log range up to the selected point. Record final LSN/GTID/binlog or equivalent marker.'],['4. Restore objects','Restore corresponding private object versions, file metadata and receipt branding. Verify existence, decryptability, size and plaintext hash.'],['5. Restore application','Deploy the exact compatible release and dependencies. Restore approved configuration and key versions. Do not automatically run forward migrations against the only recovered copy.'],['6. Isolate workers','Pause schedulers, customer mail, callbacks, BIR jobs and automatic retries. Capture queue/lease status before changing it.'],['7. Validate state','Run database integrity, business control totals, ledger and audit checks, source identity checks and scoped read-only UI/API acceptance.']],[.26,.74]),
 note('Restore production snapshots only into an approved restricted environment. Use masking for non-production development, but retain sufficient protected evidence for an authorized recovery validation. The lab contains synthetic data only.'))

page('06 | Disaster recovery runbooks','DR-03 | Reconcile financial state','A successful SQL restore does not establish a correct merchant balance.',
 table(['Domain','Required reconciliation'],[
 ['Transactions and receipts','Match accepted intake identities to transactions and receipt IDs. Compare counts, amounts, VAT/discount totals and issue-date ranges. Identify missing and duplicate records.'],['Wallet and deposits','Opening balance + verified credits - posted charges/withdrawals = closing balance. Validate sequence, previous hash, entry hash and head. Do not repair by editing posted money rows.'],['Pricing','Preserve the pricing revision recorded for the original charge. Do not recalculate old fees using today\'s minimum or fee setting.'],['Numbering','Verify sequence high-watermarks and issued document references. Never reuse an issued number because its acknowledgment was lost.'],['Tenant / environment','Reconcile per merchant and Live/Sandbox context. Restore must not merge tenants or convert synthetic activity into Live financial records.'],['External unknowns','Compare API acknowledgments, merchant outboxes, payment confirmations and delivery/provider evidence with recovered state. Open exceptions before replay.'],['Audit','Verify chain linkage and head; compare against an independent checkpoint where available. A locally consistent chain alone cannot prove no privileged rewriting occurred.']],[.28,.72]),
 p('For post-backup loss, quantify missing accepted events and their financial effect. Obtain originals from the authorized source and use the controlled reconciliation/intake process with stable identity. Record compensating actions and evidence; do not silently recreate history.'),
 note('Release requires finance/application sign-off on the agreed minimum scope. If an exception remains unresolved, restrict the affected function or merchant and disclose the limitation in the recovery acceptance record.'))

page('06 | Disaster recovery runbooks','DR-04 | Reconcile delivery and retries','Keep financial acceptance separate from delivery outcome.',
 table(['Work stream','Recovery handling'],[
 ['Merchant intake retry','Return the original durable result when an accepted event is replayed unchanged. Resolve identity conflicts; never invent a new reference to bypass duplicate protection.'],['Customer e-receipt email','Check queued/attempt/sent records and provider evidence. A restored pending row may already have been delivered. Reconcile before resend; prevent duplicates where provider semantics allow.'],['Signed merchant webhook','Keep event identity, endpoint/environment, signature key version and attempt history. Mark uncertain outcomes and retry with the same event identifier under bounded policy.'],['BIR or other external delivery','Preserve authentic provider references and statuses. Query an authorized provider when possible. No blind retransmission or invented successful acknowledgment.'],['Expired work leases','Compare worker state with the restored time boundary. Release stale leases only after proving the former worker cannot act and no valid result is being overwritten.'],['Merchant-facing status','Show success only for committed acceptance; show pending/error and a useful reason when unresolved. Do not label restored queues as completed merely because the application is online.']],[.29,.71]),
 p('Resume one delivery category at a time with a monitored rate and known reconciliation sample. Watch repeated delivery, retry storms, signature errors and wallet mutations. Pause again when an unexpected duplicate or tenant-boundary failure occurs.'),
 note('LAB LIMIT: no real email, webhook, payment or BIR provider was called. The synthetic fixture did not validate live delivery reconciliation or queue replay. These remain mandatory production-like drill steps.'))

page('06 | Disaster recovery runbooks','DR-05 | Cutover, observation and failback','Authorize traffic only after integrity and business acceptance.',
 table(['Gate','Required evidence'],[
 ['Fencing','Old writer and mutating workers are disabled. There is one authoritative target for new writes. Retain proof of the control, not only a DNS screenshot.'],['Application acceptance','Login/MFA, tenant/environment isolation, API authentication, document access, wallet display and report totals pass in the approved scope.'],['Business decision','Incident lead and service owner accept the recovery point and recorded data-loss/exceptions. Finance signs reconciliation.'],['Traffic change','Switch approved load balancer/DNS/routing configuration; record TTL/cache behavior and actual client success. Preserve a rollback path.'],['Controlled restart','Enable intake, then reconciled worker categories. Observe error rate, latency, wallet/fee consistency, queue lag and duplicate events.'],['Observation','Proposed minimum 60 minutes stable observation after reopening. Document service restoration time separately from observation end.'],['Failback','Rebuild and validate standby; synchronize from the current authoritative writer; schedule a controlled return. Never reactivate stale primary data.']],[.27,.73]),
 h('Rollback rule'),
 p('If integrity, tenant isolation or money checks fail, pause writes and delivery immediately. Once new writes exist on the recovery site, rollback must preserve them; a simple switch back to the old database is unsafe. Treat reverse synchronization as a new controlled recovery operation.'),
 note('Production RTO stops at validated minimum-service acceptance as defined in the test plan. Post-restoration observation and full backlog clearance are reported separately.'))

page('06 | Disaster recovery runbooks','Scenario-specific response playbooks','Adapt the common runbook to the actual failure mode.',
 table(['Scenario','Additional controls'],[
 ['Database corruption','Preserve source copy; locate first bad commit; restore a clean clone and verify log replay. Compare unaffected later activity before choosing whole-service rollback.'],['Private object loss','Identify missing versions by manifest. Restore encrypted originals with metadata; verify hashes and authorize scoped access. Do not substitute a generated document for missing evidence.'],['Ransomware','Recover from independently protected clean copies; rebuild hosts; rotate compromised access; inspect backup administrators and trust roots before reopening.'],['Key loss','Invoke separate custody; recover the exact key versions; test representative encrypted data. If recovery fails, record unrecoverable scope rather than claiming full restoration.'],['Credential compromise','Fence access and revoke exposed secrets. Preserve valid decrypting keys under controlled custody while rotating outward-facing credentials and sessions appropriately.'],['Region / provider outage','Confirm recovery-site capacity, key availability and independence; account for download time, bandwidth and DNS. Run full user acceptance.'],['Erroneous deployment','Restore compatible code/configuration; assess schema reversibility and writes made after release. Avoid destructive schema rollback without a validated data plan.']],[.29,.71]),
 p('Each scenario must have an owner, executable provider/engine procedure, required permissions, decision thresholds and test evidence. This document supplies the control sequence; provider-specific commands are finalized only after the hosting arrangement and engine are selected.'),
 note('Do not treat replicated corruption as a clean backup. Selection of the last known clean point may require investigation and may exceed the nominal RPO target.'))

page('07 | Validation and evidence','Service restoration acceptance checklist','DR-06 - Record pass, fail or not tested; never silently omit a dependency.',
 table(['Check','Evidence needed before full-service acceptance'],[
 ['Database','Integrity output, migration/release compatibility, restored commit position and required record counts.'],['Documents','Every critical object version available; decrypt/size/hash checks; authorized PDF/image rendering sample.'],['Key recovery','Approved key version retrieval, restricted access evidence and successful representative decryption.'],['Audit integrity','Chain/head verification plus independent checkpoint comparison and incident evidence continuity.'],['Sequence reconciliation','No reused numbers; correct source/idempotency boundaries; no duplicate transactions or fees.'],['Pending transmissions','All uncertain mail/webhook/provider outcomes reconciled or held under explicit restriction.'],['Money','Wallet balance/entry chain, deposit status, transaction/receipt totals, pricing revision and exception register.'],['Identity / security','Registered-user login, MFA, revoked keys, permissions, tenant boundaries and Live/Sandbox separation.'],['Availability','External API/portal checks, error rate, latency, customer document access and monitoring from outside the restored host.']],[.29,.71]),
 note('The NUERS recovery-evidence service explicitly tracks database, documents, key recovery, audit integrity, sequence reconciliation and pending transmissions. Do not mark all checks true from the lab alone: live application acceptance and transmission reconciliation were not exercised.'))

page('07 | Validation and evidence','Evidence workflow in NUERS','CODE BASELINE - Requests and review records do not execute infrastructure actions.',
 p('Record a backup evidence entry with scope, start/end timestamps, result, encryption status, checksum, size and destination label. Link the consistency snapshot to the backup. After the operator performs an actual restore, attach the test type, target, observed source/recovered commit times and verification results.'),
 table(['Control in reviewed implementation','Operational meaning'],[
 ['Independent review','The author cannot approve their own evidence. Preserve review note, reference and revision.'],['Measured restore selection','Only reviewed, verified, successful non-tabletop drills qualify for measured recovery summaries.'],['Chronology validation','Recovered commit cannot be after source commit; source commit cannot be after outage; completion cannot precede outage.'],['Latest failure','A newer failed drill can invalidate readiness. Do not present an older passing exercise as current proof without explaining the failure.'],['Freshness','A drill-age policy determines whether measurements remain current. Re-test after material changes even if the calendar window has not expired.'],['Test environment restrictions','Drill evidence accepts local, staging or DR targets. The reviewed submission path rejects a production drill environment.'],['Execution boundary','Backup, restore and failover actions are performed by authorized operators; dashboard requests are governance records.']],[.36,.64]),
 note('No evidence rows were added to a production NUERS database for this PDF. The included lab report is pending independent human review and must not be entered as a verified full-service DR drill.'))

page('08 | Executed laboratory test','Test charter and isolation boundary','MEASURED LAB - Actual execution on 19 October 2026, Philippine time.',
 table(['Test field','Executed scope'],[
 ['Test ID',R['test_id']],['Objective','Demonstrate encrypted backup recovery of synthetic NUERS data and private files; measure component elapsed time and transaction watermark gap.'],['Runtime',f"PHP {R['php_version']}; SQLite {R['sqlite_version']}; {R['os']}. Application schema created by the local migrations."],['Isolation','New unique local directory and SQLite database; separate environment, storage and lab-only keys; no production .env, database or secrets loaded.'],['Fixture design','500 transaction/receipt/intake records inserted as synthetic fixtures; real wallet and audit services used. This is not an HTTP intake or invoice-issuance test.'],['Recovery method','Consistent SQLite Backup API snapshot; encrypted DB; encrypted private files; authenticated manifest; source DB renamed unavailable; restore into new directories.'],['External effects','No real funds, user messages, outbound callbacks, BIR transmission or provider infrastructure changes.'],['Reviewer status','Automated execution evidence prepared by Codex; independent operator/security/business review pending.']],[.28,.72]),
 note('Local synthetic measurements are permitted by the owner\'s clarification. They provide reproducible component evidence while production RTO/RPO remain unverified.'))

page('08 | Executed laboratory test','Test topology and dataset','D04 - Real application components within a deliberately limited test boundary.',
 diagram('lab','Keys were re-read from a separate restricted local file. The primary database filename was made unavailable during each restore.'),
 p('Dataset: 500 transactions, 500 receipts, 500 intake identity rows, 501 wallet entries and 500 audit events. Eight private files contained 16 MiB of random synthetic plaintext. The database snapshot was 4,476,928 bytes; encrypted backup files totaled 37,788,696 bytes, excluding the small manifest.'),
 p('Control totals: synthetic sales PHP 56,000.00; opening synthetic wallet credit PHP 10,000.00; 500 recorded charges of PHP 3.00; recovered wallet PHP 8,500.00. These values are test fixtures and are not merchant or platform financial statements.'),
 note('The source schema was fully migrated, but most application tables were empty. The test does not establish recovery performance at production volume, under concurrency, over a network or with cold infrastructure.'))

page('08 | Executed laboratory test','Executed steps and timing method','Restore time includes local key loading, verification and component checks.',
 table(['Sequence','Actual procedure'],[
 ['1. Seed','Create a fresh isolated schema; insert synthetic linked records; create wallet/audit chains and encrypted private files. Verify source integrity.'],['2. Checkpoint','Stop synthetic writes. Capture the highest observed committed transaction marker, counts, wallet result and consistent SQLite snapshot.'],['3. Back up','Encrypt database bytes; copy encrypted private objects; record hashes and authenticate the manifest. Backup packaging measured 0.154777 seconds.'],['4. Start outage','Record UTC timestamp and monotonic timer; disconnect source; rename its database file unavailable. Start with an empty target.'],['5. Restore','Read lab escrow keys; authenticate manifest and ciphertext hashes; decrypt DB; copy objects; select the restored database/storage.'],['6. Validate','Run SQLite integrity, row counts, sales/receipt totals and linkage, wallet chain, audit chain, every private-file decryption/hash and transaction marker.'],['7. Complete','Record completion time after all component checks. Restore original lab filename for subsequent trials; no production source is touched.'],['8. Exercise loss','After three repeats, commit three new synthetic events beyond the unchanged backup and restore that older checkpoint.']],[.24,.76]),
 note('Elapsed time uses PHP hrtime, rounded to microseconds. UTC wall-clock timestamps support review. Commit markers are observed immediately after the synthetic event transaction commits; they are not native SQLite transaction-log positions.'))

page('08 | Executed laboratory test','Measured recovery results','Actual component recovery timings; no production-service claim.',
 table(['Run','Recovery time','RPO gap','Missing events','Result'],[[x['run'],f"{x['rto_seconds']:.6f} s",f"{x['rpo_seconds']:.6f} s",str(x['records_not_recovered']),'Checkpoint verified' if x['records_not_recovered']==0 else 'Loss detected']for x in R['runs']],[.12,.23,.23,.18,.24]),
 h('Quiesced checkpoint - A1, A2 and A3'),
 p(f"Three runs restored the same checkpoint, with no post-checkpoint transaction writes. Median component recovery time was {statistics.median(x['rto_seconds'] for x in R['runs'][:3]):.6f} seconds; minimum 0.333713 and maximum 0.341049 seconds. All 500 event identities and eight private files were verified. The measured transaction commit gap was zero."),
 h('Deliberately older checkpoint - B1'),
 p('Three new synthetic transactions were committed after the backup. Recovery returned to sequence 500 while the source had reached 503. The observed commit gap was 3.393494 seconds, with three transaction/receipt/intake events missing. Component restore time was 0.370079 seconds. This is a successful detection of expected loss, not a loss-free recovery result.'),
 p('The three missing events represented PHP 336.00 synthetic gross sales and PHP 9.00 recorded fees beyond the checkpoint. The restored wallet remained PHP 8,500.00; the later source wallet was PHP 8,491.00. Such a gap must be reconciled before actual service release.'),
 note('Zero RPO in a quiesced local test does not prove continuous zero-data-loss operation. Repeated local reads may benefit from warm caches. No statistical production performance guarantee is inferred from four small trials.'))

page('08 | Executed laboratory test','Timestamp and watermark evidence','UTC observations preserved from the executed run.',
 table(['Event','Recorded UTC value'],[
 ['Checkpoint transaction marker','2026-10-18T23:47:31.575817Z | sequence 500'],['Backup packaging started','2026-10-18T23:47:31.771752Z'],['A1 simulated outage','2026-10-18T23:47:31.926566Z'],['A1 component validation complete','2026-10-18T23:47:32.265907Z'],['A2 outage / complete','23:47:32.266202Z / 23:47:32.607251Z'],['A3 outage / complete','23:47:32.607514Z / 23:47:32.941226Z'],['B1 latest source marker','2026-10-18T23:47:34.969311Z | sequence 503'],['B1 simulated outage','2026-10-18T23:47:34.969880Z'],['B1 component validation complete','2026-10-18T23:47:35.339959Z'],['B1 restored marker','2026-10-18T23:47:31.575817Z | sequence 500']],[.41,.59]),
 p('All times above refer to the same UTC date. Philippine local time is 19 October 2026, approximately 07:47:31-07:47:35 (UTC+08:00). The run identifier uses its UTC start date. The system software version remains 1.0.0.'),
 p('A1 recovery-point age at simulated outage was about 0.351 seconds while its transaction loss gap was zero. B1 recovery-point age was about 3.394 seconds and its observed transaction loss gap was 3.393494 seconds. This distinction prevents a quiet source from being mistaken for a fresh continuous backup.'),
 note('The raw JSON retains full precision and per-stage durations. Wall-clock subtraction may differ slightly from monotonic elapsed time due to the exact sampling calls and rounding.'))

page('08 | Executed laboratory test','Integrity results and negative controls','The drill validated recoverability and explicit failure handling.',
 table(['Executed check','Observed result'],[
 ['Database checksum + PRAGMA integrity_check','Passed on all four restored targets.'],['Counts, financial totals and receipt linkage','500 transactions/receipts/intake identities; sales and receipts PHP 56,000.00; no missing receipt links at the checkpoint.'],['Wallet chain and state','501 entries; valid hashes/sequence/head; closing synthetic balance 850,000 centavos.'],['Audit chain','500 synthetic audit events; recomputed linkage and head passed. No independent offsite checkpoint tested.'],['Private-file recovery','All eight files decrypted through PrivateDocumentStream; plaintext sizes and SHA-256 hashes matched on every restore.'],['Wrong backup decryption key','Rejected by authenticated decryption.'],['Altered database ciphertext','Rejected; modified backup was not accepted as a valid restored database.'],['Missing private object','Rejected by the application document-verification path.'],['Wrong expected plaintext hash','Rejected by the application document-integrity check.']],[.43,.57]),
 p('Negative checks used disposable data and deliberately invalid inputs. A rejection proves the tested failure path; it does not establish exhaustive tamper resistance or independent penetration-test assurance.'),
 note('The lab did not mutate live records, mark a production backup as verified, exercise real external delivery, or perform an actual provider failover.'))

page('08 | Executed laboratory test','Software tests and evidence provenance','Separate correctness tests from measured operational performance.',
 p('The current BackupRecoveryTest feature suite completed successfully: <b>15 tests, 156 assertions, 2.148 seconds</b> under PHPUnit 11.5.55 and PHP 8.5.9. It covers the governance path, including authorization, independent evidence review, chronology, freshness and failure handling. Test-created timestamps are fixtures and are not measurements of restoration speed or data loss.'),
 table(['Evidence artifact','Purpose'],[
 ['lab-results.json','Actual trial timestamps, component timings, counts, financial control totals, negative checks and selected source hashes.'],['run_isolated_recovery.php','Reproducible synthetic-only runner with a fresh path and explicit database/environment isolation.'],['governance-tests.xml','JUnit result with the current 15-test governance execution evidence.'],['evidence-manifest.json','SHA-256 manifest for the delivered report and supporting evidence files.'],['Private local lab directory','Encrypted synthetic backup, separate lab key escrow and restored targets retained for controlled reproducibility; keys excluded from the public evidence bundle.']],[.37,.63]),
 h('Review and traceability'),
 p('The JSON records hashes for the reviewed private-document, wallet, audit and backup-governance services, controller and dependency lock file. The runner hash binds the execution recipe. An independent reviewer should rerun the exercise in a clean approved environment and compare outcomes before using it as an organizational test attestation.'),
 note('SHA-256 manifests detect mismatches when the manifest itself is trusted. The local evidence bundle is not a qualified digital signature, external timestamp or independent audit certificate.'))

page('09 | Production qualification','Production test plan and acceptance criteria','Close these gaps before calling the production RTO/RPO tested.',
 table(['Exercise requirement','Pass criterion'],[
 ['Representative infrastructure','Use the selected production database engine/version, storage, app/runtime and network path in an isolated approved recovery environment.'],['Representative volume','Test current production-size data plus forecast growth and object counts; document compression, bandwidth and concurrent load.'],['Real backup retrieval','Retrieve from the actual independent vault/region; include key recovery, access approval and transfer time in the outage clock.'],['Continuous data probe','Generate uniquely identifiable synthetic acknowledged events around backup and failure boundaries; compare the contiguous recovered watermark and exact losses.'],['Full-service acceptance','External login/MFA, tenant/environment boundaries, API status, documents, wallet/report totals and controlled delivery reconciliation pass.'],['Timing objective','Observed end-to-end recovery within the approved RTO; all critical datasets within the approved RPO; no undisclosed exceptions.'],['Negative cases','Missing logs, inaccessible keys, corrupt objects, unavailable identity service, stale writer and failed transfer safely stop or trigger documented alternate recovery.'],['Independent review','Named reviewer validates raw evidence and business owner accepts results and remediation. Repeat failed scenarios after correction.']],[.32,.68]),
 note('Use test merchants and isolated external receivers. Live data or traffic may only be exercised under an approved change/incident plan with scope, rollback and privacy controls.'))

page('09 | Production qualification','Exercise schedule and readiness register','PROPOSED cadence - evidence freshness follows change, not only a calendar.',
 table(['Frequency / trigger','Exercise and accountable owner'],[
 ['Every backup','Backup operator verifies completion, bytes, hashes, destination and recoverable dependency chain.'],['Weekly','Infrastructure operator restores a sample of database and encrypted objects; security reviews backup access failures.'],['Monthly','Application/database leads restore a representative service clone; validate wallet, documents, identities and report totals.'],['Quarterly','Incident commander runs a full end-to-end DR drill with simulated writer loss, independent backup retrieval and business acceptance.'],['At least annually','Service owner reviews impact, architecture, contracts, geographic risk and contact/custody arrangements; exercise alternate personnel.'],['After material change','Repeat affected tests after key rotation, engine/schema/storage change, new provider, major data growth, significant incident or failed drill.']],[.29,.71]),
 h('Current readiness gaps'),
 p('Pending: actual provider/region inventory; independent immutable/offline backup implementation; approved key custody; engine-native PITR restore; production-scale transfer measurement; full-service identity and API acceptance; uncertain transmission reconciliation; named contacts and independent review.'),
 p('Assign each gap an owner, due date, evidence link, severity and closure test. A rejected or failed drill remains open until remediation is verified. Do not erase the failed result when recording the later successful retest.'),
 note('A tabletop exercise is useful for roles and decision practice, but it does not measure database recovery, data loss or infrastructure failover capability.'))

page('10 | Operational forms','Backup and recovery execution record','Reusable template - complete one record for each backup/restore attempt.',
 table(['Field','Record to complete'],[
 ['Identity','Incident/change ID; backup set ID; environment; test type; operator; independent reviewer.'],['System baseline','Application release/build hash; database engine/version; source and target labels; data locations.'],['Capture','Start/end UTC; native snapshot/log position; last acknowledged source marker; quiesce interval.'],['Backup set','Database bytes/hash; object count/version manifest; configuration and release references; encryption/key version IDs.'],['Protection','Vault/location; destination read-back hash; retention/immutability evidence; access-review reference.'],['Restore','Outage start; incident declaration; key access; transfer start/end; restore start/end; validation end; service acceptance.'],['Data loss','Recovered marker; source marker; RPO gap; recovery-point age; missing record IDs/counts/value by dataset.'],['Validation','Database; documents; keys; audit; sequences; pending transmissions; money; access; external availability.'],['Outcome','Pass/fail/partial/not tested; observed RTO; RPO; exceptions; remediation owner/date; review decision.'],['Retention','Evidence storage reference; access classification; hash/signature; approved destruction/hold date.']],[.27,.73]),
 note('Record a failed attempt as failed. Do not fill blank timestamps from the target SLA or from an earlier successful run. Do not include secrets, recovery codes or unredacted customer records in general evidence exports.'))

page('10 | Operational forms','Incident communications and contact register','Prepared wording and contact fields; no fabricated operational contacts.',
 h('Internal incident update template'),
 p('<b>Incident [ID] | [UTC/PHT time]</b><br/>Impact: [affected service, merchants and environment]. Outage began: [time / confidence]. Current status: [investigating / restoring / validating / restricted service]. Recovery point: [known point or pending]. Actions completed: [facts]. Known risks/data loss: [facts or pending]. Next action and owner: [name]. Next update: [time].'),
 h('Merchant status update template'),
 p('NUERS is investigating an interruption affecting [service]. Your source system should retain pending events and reuse the same event identities when retrying. We will provide the next update at [time]. Confirmed accepted transactions remain subject to reconciliation; we will contact affected merchants if a recovery exception is identified.'),
 table(['Contact function','Required fields in restricted register'],[
 ['Incident command / service owner','Primary and alternate; verified phone; secure channel; escalation authority; availability.'],['Database / infrastructure / application','Primary and alternate; access prerequisites; provider case route; runbook ownership.'],['Security / key custody / finance','Emergency recovery method; dual-control counterpart; reconciliation and privacy escalation.'],['Provider / registrar / mail service','Contract/account reference; support route; delegated access; escalation and response terms.']],[.39,.61]),
 note('Approve public statements through the communications owner. Regulatory/privacy notifications require the applicable legal review; this template does not set or replace statutory deadlines.'))

page('10 | Operational forms','Approval, exception and revision control','Keep the plan current and distinguish preparation from organizational approval.',
 table(['Approval role','Required decision / record'],[
 ['Service owner','Approve service scope, RTO/RPO targets, business tolerance, budget and residual risk. Name/date/signature pending.'],['Infrastructure and application leads','Confirm executable engine/provider procedures, capacity and dependency inventory. Name/date/signature pending.'],['Security / data owner','Approve custody, access, encryption, location, retention and privacy controls. Name/date/signature pending.'],['Finance / operations','Approve ledger and transaction reconciliation, merchant communications and minimum-service acceptance. Name/date/signature pending.'],['Independent reviewer','Review actual test logs, limitations, results and retests. Name/date/signature pending.']],[.33,.67]),
 h('Exception record'),
 p('Document the affected control, reason, risk, compensating measure, accountable owner, expiry and approving authority. A temporary exception does not convert an unmeasured target into a tested result. Reassess after a failure or architecture change.'),
 h('Edition history'),
 p('<b>Edition 1.0 | 19 October 2026:</b> Initial NUERS 1.0.0 backup procedure and disaster recovery plan, including four actual isolated restore trials, four negative controls and current application governance test evidence. Production recovery objectives remain proposed and unverified.'),
 note('Review at least quarterly and after every material incident, infrastructure/key change or failed drill. Replace controlled copies, archive superseded editions and retain the evidence used for each approved claim.'))

page('11 | References and supporting evidence','References and source register','Official references checked on 19 October 2026; local source is the implementation baseline.',
 p('<b>[R1] NIST SP 800-34 Rev. 1</b> - Contingency Planning Guide for Federal Information Systems. Planning reference, not a NUERS certification.<br/><link href="https://csrc.nist.gov/pubs/sp/800/34/r1/final" color="#0056d6">csrc.nist.gov/pubs/sp/800/34/r1/final</link>'),
 p('<b>[R2] CISA #StopRansomware Guide</b> - Backup isolation, encryption and restoration-testing guidance.<br/><link href="https://www.cisa.gov/resources-tools/resources/stopransomware-guide" color="#0056d6">cisa.gov/resources-tools/resources/stopransomware-guide</link>'),
 p('<b>[R3] SQLite Online Backup API</b> - Technical basis for the consistent SQLite snapshot used in the isolated lab.<br/><link href="https://www.sqlite.org/backup.html" color="#0056d6">sqlite.org/backup.html</link>'),
 p('<b>[R4] Amazon S3 Object Lock</b> - Reference for a possible future versioned-object retention control; not deployed/tested by this exercise.<br/><link href="https://docs.aws.amazon.com/AmazonS3/latest/userguide/object-lock.html" color="#0056d6">docs.aws.amazon.com/AmazonS3/latest/userguide/object-lock.html</link>'),
 table(['Local evidence','Reviewed purpose'],[
 ['BackupRecovery service / controller','Evidence selection, review, chronology, readiness and execution boundaries.'],['PrivateDocumentStream / MerchantWallet / BirCompliance','Encrypted file integrity, wallet ledger validation and audit-chain validation used by the actual lab.'],['BackupRecoveryTest','Current 15-test governance suite; fixture timing is not a DR measurement.'],['Technical Specifications & System Manual 1.0.0','Related architecture, identity, incident and service-framework documentation.'],['Owner clarification','Providers are planned, not in use for the future arrangement; no existing production DR report; clearly labeled lab results requested.']],[.41,.59]),
 note('Source and evidence hashes are supplied in the supporting JSON manifest. This document does not assert accreditation, legal compliance certification, contractual availability or tested production failover.'))

def footer(c,n,total,ch):
 x=64 if n%2 else 48
 c.setStrokeColor(LINE);c.setLineWidth(.5);c.line(x,45,x+CW,45)
 c.setFillColor(GREY);c.setFont('Body',7);c.drawString(x,31,'NUERS 1.0.0  |  Backup Procedure & Disaster Recovery Plan')
 c.drawRightString(x+CW,31,f'{n:02d} / {total:02d}')
 c.setFont('Body',6.5);c.drawString(x,20,'19 OCT 2026  |  Measured local lab evidence; production RTO/RPO remain unverified')
m.draw_footer=footer

def build():
 total=len(PAGES)+3;raw=TMP/'backup-dr-unoptimized.pdf'
 c=canvas.Canvas(str(raw),pagesize=(PW,PH),pageCompression=1)
 c.setTitle('NUERS Backup Procedure and Disaster Recovery Plan | Version 1.0.0')
 c.setAuthor('NUERS');c.setSubject('Backup procedures, disaster recovery, proposed targets and measured isolated laboratory RTO/RPO evidence')
 c.setCreator('NUERS controlled operational documentation')
 c.bookmarkPage('cover');c.addOutlineEntry('Cover','cover',0)
 c.setFillColor(NAVY);c.rect(0,0,PW,PH,fill=1,stroke=0)
 c.drawImage(str(ROOT/'public/auth-assets/nuers-digital-white.png'),52,PH-123,width=205,height=77,mask='auto',preserveAspectRatio=True)
 c.setFillColor(GOLD);c.rect(52,PH-151,43,4,fill=1,stroke=0)
 c.setFont('Bold',9);c.setFillColor(HexColor('#9fc8ff'));c.drawString(52,PH-178,'NATIONAL UNIFIED ELECTRONIC RECEIPT SYSTEM')
 for s,y in [('Backup Procedure',PH-226),('& Disaster Recovery',PH-266),('Plan',PH-306)]:
  c.setFont('Bold',30);c.setFillColor(white);c.drawString(52,y,s)
 c.setFont('Body',11);c.setFillColor(HexColor('#bed7fa'));c.drawString(52,PH-339,'Operational runbooks • Recovery controls • Executed test evidence')
 # Purpose-built vector visual rather than a fictional data-center photograph.
 for i,label in enumerate(['PROTECT','RESTORE','VERIFY']):
  x=52+i*166;c.setFillColor(HexColor('#163d73'));c.roundRect(x,280,153,155,10,fill=1,stroke=0)
  c.setFillColor(BLUE);c.circle(x+76,375,28,fill=1,stroke=0)
  c.setStrokeColor(white);c.setLineWidth(2)
  if i==0:
   pth=c.beginPath();pth.moveTo(x+59,387);pth.lineTo(x+76,394);pth.lineTo(x+93,387);pth.lineTo(x+91,369);pth.curveTo(x+88,360,x+80,355,x+76,353);pth.curveTo(x+66,358,x+61,365,x+61,369);pth.close();c.drawPath(pth,stroke=1,fill=0)
  elif i==1:
   c.arc(x+59,359,x+93,393,startAng=40,extent=290);c.line(x+90,389,x+81,389);c.line(x+90,389,x+90,398)
  else:
   c.line(x+60,375,x+72,363);c.line(x+72,363,x+93,386)
  c.setFont('Bold',11);c.setFillColor(white);c.drawCentredString(x+76,317,label)
 c.setFillColor(GOLD);c.roundRect(52,225,PW-104,33,6,fill=1,stroke=0)
 c.setFillColor(NAVY);c.setFont('Bold',9);c.drawCentredString(PW/2,237,'MEASURED LAB RESULTS  |  PRODUCTION TARGETS UNVERIFIED')
 c.setFont('Bold',12);c.setFillColor(white);c.drawString(52,179,'SOFTWARE VERSION 1.0.0')
 c.setFont('Body',10);c.setFillColor(HexColor('#bed7fa'));c.drawString(52,157,'Plan edition 1.0  |  19 October 2026')
 q=Paragraph('Includes actual isolated restoration of synthetic NUERS records and encrypted files. Planned hosting: Dataplugs, Contabo and AWS Singapore / Hong Kong; provider roles remain unassigned.',ParagraphStyle('cv',fontName='Body',fontSize=9,leading=14,textColor=HexColor('#bed7fa')))
 _,hh=q.wrap(PW-104,100);q.drawOn(c,52,83)
 c.setFillColor(BLUE);c.rect(52,47,255,4,fill=1,stroke=0);c.setFillColor(GOLD);c.rect(307,47,46,4,fill=1,stroke=0);c.setFillColor(HexColor('#e5233c'));c.rect(353,47,42,4,fill=1,stroke=0);c.showPage()
 split=(len(PAGES)+1)//2
 for k,subset in enumerate([PAGES[:split],PAGES[split:]]):
  n=k+2;x=48 if n%2==0 else 64
  c.bookmarkPage(f'contents{k}');c.addOutlineEntry('Contents'+(' / continued' if k else ''),f'contents{k}',0)
  c.setFont('Bold',8);c.setFillColor(BLUE);c.drawString(x,PH-58,'NAVIGATION  |  CLICK A TITLE')
  c.setFont('Bold',25);c.setFillColor(NAVY);c.drawString(x,PH-100,'Contents'+(' / continued' if k else ''))
  y=PH-144;start=0 if k==0 else split
  for j,data in enumerate(subset,start):
   c.setFont('Body',9);c.setFillColor(INK);c.drawString(x,y,data['title']);c.setFillColor(BLUE);c.drawRightString(x+CW,y,str(j+4))
   c.linkRect('',f'p{j+4}',(x,y-4,x+CW,y+12),relative=0,thickness=0)
   c.setStrokeColor(LINE);c.setLineWidth(.4);c.line(x,y-9,x+CW,y-9);y-=28
  footer(c,n,total,'Contents');c.showPage()
 metrics=[];last=None
 for n,data in enumerate(PAGES,4):
  c.bookmarkPage(f'p{n}')
  if data['ch']!=last:c.addOutlineEntry(data['ch'],f'p{n}',0);last=data['ch']
  c.addOutlineEntry(data['title'],f'p{n}',1)
  bottom=m.draw_content(c,n,total,data);metrics.append({'page':n,'title':data['title'],'bottom':round(bottom,1)});c.showPage()
 c.save()
 reader=PdfReader(raw);writer=PdfWriter();writer.clone_document_from_reader(reader);writer.pdf_header='%PDF-1.7'
 writer.root_object[NameObject('/ViewerPreferences')]=DictionaryObject({NameObject('/DisplayDocTitle'):BooleanObject(True),NameObject('/PrintScaling'):NameObject('/None'),NameObject('/Duplex'):NameObject('/DuplexFlipLongEdge')})
 for pg in writer.pages:pg.compress_content_streams()
 for _ in range(3):writer.compress_identical_objects(remove_duplicates=True,remove_unreferenced=True)
 with OUT.open('wb')as f:writer.write(f)
 (BASE/'layout-metrics.json').write_text(json.dumps(metrics,indent=2))
 print(json.dumps({'pages':total,'bytes':OUT.stat().st_size,'path':str(OUT),'min_body_bottom':min(x['bottom']for x in metrics)},indent=2))

if __name__=='__main__':build()
