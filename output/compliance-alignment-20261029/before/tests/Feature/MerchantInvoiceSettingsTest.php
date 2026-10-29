<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\{MerchantInvoiceSettings,BirCompliance,ElectronicDocumentView};
use Illuminate\Support\Facades\{DB,File};
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
class MerchantInvoiceSettingsTest extends TestCase {
 private User $owner;private string $m;private string $foreign;private User $other;private string $images;
 protected function setUp():void {parent::setUp();config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','notifications.dispatch_enabled'=>false]);DB::purge('sqlite');$this->artisan('migrate:fresh',['--force'=>true])->assertSuccessful();[$this->owner,$this->m]=$this->account();[$this->other,$this->foreign]=$this->account();$this->withToken($this->owner->api_token);$this->images=storage_path('framework/testing/brand-'.Str::uuid());File::ensureDirectoryExists($this->images);$this->app->usePublicPath($this->images);}
 protected function tearDown():void {File::deleteDirectory($this->images);parent::tearDown();}
 private function account(string $role='merchant'):array {$u=User::factory()->create(['api_token'=>Str::random(64)]);DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);$id=(string)Str::uuid();DB::table('merchants')->insert(['id'=>$id,'merchant_account_id'=>$u->id,'business_name'=>'Synthetic Invoice QA','tin'=>'123456789000','status'=>'active','vat_registered'=>true,'address'=>'Synthetic Address']);return[$u,$id];}
 private function prefs():array {return app(MerchantInvoiceSettings::class)->defaults(DB::table('merchants')->where('id',$this->m)->first());}
 private function save(array $change=[],int $revision=0){return $this->putJson('/api/merchant/invoice-settings',['revision'=>$revision,'settings'=>array_replace($this->prefs(),$change)]);}
 public function test_defaults_are_self_scoped_and_revisions_reject_overwrites():void {$this->getJson('/api/merchant/invoice-settings?merchant_id='.$this->foreign)->assertOk()->assertJsonPath('business.id',$this->m)->assertJsonPath('revision',0)->assertJsonPath('settings.default_document_type','vat_invoice')->assertHeader('Cache-Control','no-store, private');$this->save(['footer_text'=>'Thank you, synthetic customer.'])->assertOk()->assertJsonPath('revision',1);$this->save()->assertConflict();$this->assertDatabaseCount('merchant_invoice_settings',1);$this->assertSame(null,DB::table('merchant_invoice_settings')->where('merchant_id',$this->foreign)->first());$this->assertTrue(app(BirCompliance::class)->auditIntegrity());}
 public function test_foreign_roles_staff_and_suspended_owners_cannot_change_settings_or_image():void {[$admin]=$this->account('super_admin');$this->withToken($admin->api_token)->getJson('/api/merchant/invoice-settings')->assertForbidden();$staff=User::factory()->create(['api_token'=>Str::random(64)]);DB::table('profiles')->insert(['id'=>$staff->id,'email'=>$staff->email,'role'=>'merchant']);$this->withToken($staff->api_token)->getJson('/api/merchant/invoice-settings')->assertForbidden();$this->withToken($staff->api_token)->deleteJson('/api/merchant/business-profile/image',['expected_logo'=>null])->assertForbidden();$this->withToken($this->owner->api_token);DB::table('merchants')->where('id',$this->m)->update(['status'=>'suspended']);$this->getJson('/api/merchant/invoice-settings')->assertOk();$this->save()->assertForbidden();$this->deleteJson('/api/merchant/business-profile/image',['expected_logo'=>null])->assertForbidden();}
 public function test_rejects_injected_layout_and_non_vat_incompatible_preferences():void {foreach([['font_family'=>'url(https://evil.test)'],['primary_color'=>'#ffffff'],['secondary_color'=>'red; background:url(x)'],['currency'=>'USD'],['default_document_type'=>'official_receipt'],['payment_methods'=>[]],['payment_methods'=>['cash','cash']],['payment_terms_days'=>366],['extra'=>'injected'],['logo_url'=>'https://evil.test/a.png']]as$change)$this->save($change)->assertUnprocessable();DB::table('merchants')->where('id',$this->m)->update(['vat_registered'=>false]);$this->save(['default_document_type'=>'vat_invoice'])->assertUnprocessable();$this->save(['allow_zero_rated'=>true])->assertUnprocessable();$this->save(['default_document_type'=>'non_vat_invoice'])->assertOk();}
 public function test_preview_is_server_calculated_and_never_issues_or_advances_series():void {$p=$this->prefs();$this->postJson('/api/merchant/invoice-settings/preview',['settings'=>$p,'document_type'=>'invoice'])->assertOk()->assertJsonPath('document.record_status','draft')->assertJsonPath('document.amount',2000)->assertJsonPath('document.vat',214.28)->assertJsonPath('document.verification_url',null);$this->postJson('/api/merchant/invoice-settings/preview',['settings'=>[...$p,'price_display'=>'exclusive'],'document_type'=>'invoice'])->assertOk()->assertJsonPath('document.amount',2240)->assertJsonPath('document.vat',240);$this->postJson('/api/merchant/invoice-settings/preview',['settings'=>[...$p,'allow_zero_rated'=>true,'default_document_type'=>'zero_rated_invoice'],'document_type'=>'invoice'])->assertOk()->assertJsonPath('document.vat',0)->assertJsonPath('document.zero_rated',2000);$this->assertDatabaseCount('business_invoices',0);$this->assertDatabaseCount('invoice_series',0);$this->assertDatabaseCount('merchant_wallet_entries',0);}
 public function test_profile_image_is_immediate_and_previous_files_are_preserved():void {
  $url=$this->post('/api/merchant/business-profile/image',['expected_logo'=>'','logo'=>UploadedFile::fake()->image('business.jpg',60,60)],['Accept'=>'application/json'])->assertOk()->assertJsonPath('pending_approval',false)->json('logo');
  $this->assertSame($url,DB::table('merchants')->where('id',$this->m)->value('logo_url'));
  $this->assertMatchesRegularExpression('#^/public/uploads/business-logos/[a-f0-9-]+\.png$#',$url);
  $this->assertFileExists(public_path(substr($url,8)));
  $this->assertDatabaseCount('business_registry_requests',0);
  $this->getJson('/api/merchant/business-profile')->assertOk()->assertJsonPath('business.logo',$url);
  $this->deleteJson('/api/merchant/business-profile/image',['expected_logo'=>$url])->assertOk()->assertJsonPath('pending_approval',false)->assertJsonPath('logo',null);
  $this->assertNull(DB::table('merchants')->where('id',$this->m)->value('logo_url'));
  $this->assertFileExists(public_path(substr($url,8)));
  $this->assertTrue(app(BirCompliance::class)->auditIntegrity());
 }
 public function test_receipt_logo_and_profile_picture_are_independent_in_preview_and_removal():void {
  $profile=$this->post('/api/merchant/business-profile/image',['expected_logo'=>'','logo'=>UploadedFile::fake()->image('profile.png',20,20)],['Accept'=>'application/json'])->assertOk()->json('logo');
  $logo=$this->post('/api/merchant/invoice-settings/logo',['expected_logo'=>'','merchant_id'=>$this->foreign,'logo'=>UploadedFile::fake()->image('receipt.png',120,40)],['Accept'=>'application/json'])->assertOk()->assertJsonPath('pending_approval',false)->json('logo');
  $this->assertStringContainsString('/invoice-logos/',$logo);
  $this->getJson('/api/merchant/invoice-settings')->assertOk()->assertJsonPath('invoice_logo',$logo)->assertJsonPath('business.logo',$profile);
  $this->postJson('/api/merchant/invoice-settings/preview',['settings'=>$this->prefs(),'document_type'=>'invoice'])->assertOk()->assertJsonPath('document.presentation.logo_url',$logo);
  $m=DB::table('merchants')->where('id',$this->m)->first();$this->assertSame($logo,app(MerchantInvoiceSettings::class)->snapshot($m)['logo_url']);
  $this->assertNull(DB::table('merchants')->where('id',$this->foreign)->value('invoice_logo_url'));
  $next=$this->post('/api/merchant/business-profile/image',['expected_logo'=>$profile,'logo'=>UploadedFile::fake()->image('profile2.png',20,20)],['Accept'=>'application/json'])->assertOk()->json('logo');
  $this->assertSame($logo,DB::table('merchants')->where('id',$this->m)->value('invoice_logo_url'));
  $this->deleteJson('/api/merchant/invoice-settings/logo',['expected_logo'=>'stale'])->assertConflict();
  $this->deleteJson('/api/merchant/invoice-settings/logo',['expected_logo'=>$logo])->assertOk()->assertJsonPath('logo',null);
  $this->postJson('/api/merchant/invoice-settings/preview',['settings'=>$this->prefs(),'document_type'=>'payment_receipt'])->assertOk()->assertJsonPath('document.presentation.logo_url',null);
  $this->assertSame($next,DB::table('merchants')->where('id',$this->m)->value('logo_url'));
  $this->assertFileExists(public_path(substr($logo,8)));
 }
 public function test_profile_upload_supersedes_only_pending_image_requests():void {
  $m=DB::table('merchants')->where('id',$this->m)->first();
  $pending=DB::transaction(fn()=>app(\App\Services\BusinessProfileChanges::class)->submit($m,(string)$this->owner->id,(string)Str::uuid(),['logo_url'=>'/public/uploads/business-logos/old.png'],'Synthetic old image request.',true));
  $this->post('/api/merchant/business-profile/image',['expected_logo'=>'','logo'=>UploadedFile::fake()->image('profile.png',20,20)],['Accept'=>'application/json'])->assertOk();
  $this->assertNotNull(DB::table('business_registry_requests')->where('id',$pending['id'])->value('completed_at'));
  $request=$this->postJson('/api/merchant/profile-changes',['request_id'=>(string)Str::uuid(),'changes'=>['business_name'=>'Synthetic reviewed identity'],'reason'=>'Synthetic identity change request.'])->assertCreated()->json('id');
  $logo=DB::table('merchants')->where('id',$this->m)->value('logo_url');
  $this->deleteJson('/api/merchant/business-profile/image',['expected_logo'=>$logo])->assertOk();
  $this->assertNull(DB::table('business_registry_requests')->where('id',$request)->value('completed_at'));
  $this->assertSame('Synthetic Invoice QA',DB::table('merchants')->where('id',$this->m)->value('business_name'));
 }
 public function test_invoice_logo_rejects_unsafe_files_suspended_access_and_missing_mfa():void {
  $this->post('/api/merchant/invoice-settings/logo',['expected_logo'=>'','logo'=>UploadedFile::fake()->createWithContent('x.svg','<svg onload="alert(1)"/>')],['Accept'=>'application/json'])->assertUnprocessable();
  $this->post('/api/merchant/invoice-settings/logo',['expected_logo'=>'stale','logo'=>UploadedFile::fake()->image('x.png',10,10)],['Accept'=>'application/json'])->assertConflict();
  $this->assertCount(0,File::glob(public_path('uploads/invoice-logos/*.png')));
  config(['security.enforce_privileged_mfa'=>true]);
  $this->deleteJson('/api/merchant/invoice-settings/logo',['expected_logo'=>null])->assertForbidden()->assertJsonPath('code','MFA_REQUIRED');
  config(['security.enforce_privileged_mfa'=>false]);DB::table('merchants')->where('id',$this->m)->update(['status'=>'suspended']);
  $this->deleteJson('/api/merchant/invoice-settings/logo',['expected_logo'=>null])->assertForbidden();
  $this->withToken($this->other->api_token)->deleteJson('/api/merchant/invoice-settings/logo',['expected_logo'=>null,'merchant_id'=>$this->m])->assertOk()->assertJsonPath('merchant_id',$this->foreign);
 }
 public function test_unsafe_image_inputs_and_stale_uploads_are_rejected():void {$this->post('/api/merchant/business-profile/image',['expected_logo'=>'','logo'=>UploadedFile::fake()->createWithContent('x.svg','<svg onload="alert(1)"/>')],['Accept'=>'application/json'])->assertUnprocessable();$this->post('/api/merchant/business-profile/image',['expected_logo'=>'stale','logo'=>UploadedFile::fake()->image('x.png',10,10)],['Accept'=>'application/json'])->assertConflict();$this->assertNull(DB::table('merchants')->where('id',$this->m)->value('logo_url'));$this->assertCount(0,File::glob(public_path('uploads/business-logos/*.png')));}
 public function test_draft_snapshots_exclusive_tax_policy_and_idempotent_replay_preserve_originals():void {
        // Synthetic funds satisfy the configurable minimum-zero wallet gate.
        DB::transaction(fn () => app(\App\Services\MerchantWallet::class)->credit($this->m, 600, (string) \Illuminate\Support\Str::uuid(), 'Synthetic settled test funds', 'test-fixture'));
  $this->save(['footer_text'=>'Original footer','price_display'=>'exclusive'])->assertOk();
  $payload=['invoice_number'=>'SYNTHETIC-SETTINGS-001','request_id'=>(string)Str::uuid(),'document_type'=>'vat_invoice','status'=>'draft','buyer_name'=>'Synthetic Settings Buyer','issue_date'=>now()->toDateString(),'prices_include_vat'=>false,'line_items'=>[['line_number'=>1,'description'=>'Synthetic tax test','quantity'=>1,'unit_price'=>10000,'tax_type'=>'vatable','taxable_amount'=>10000,'vat_amount'=>1200,'line_total'=>11200]]];
  $r=$this->postJson('/api/business-invoices',$payload)->assertCreated();
  $row=DB::table('business_invoices')->where('merchant_id',$this->m)->first();$this->assertEquals(112,$row->total_amount);$this->assertEquals(12,$row->vat_amount);$snapshot=json_decode($row->presentation_settings,true);$this->assertSame('Original footer',$snapshot['footer_text']);
  $this->save(['footer_text'=>'New footer','require_customer_tin'=>true],1)->assertOk();
  $this->assertSame($row->presentation_settings,DB::table('business_invoices')->where('id',$row->id)->value('presentation_settings'));
  $view=app(ElectronicDocumentView::class)->make('invoice',DB::table('business_invoices')->where('id',$row->id)->first());$this->assertSame('Original footer',$view['presentation']['footer_text']);
  DB::table('merchants')->where('id',$this->m)->update(['invoice_logo_url'=>'/public/uploads/invoice-logos/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png']);
  $view=app(ElectronicDocumentView::class)->make('invoice',DB::table('business_invoices')->where('id',$row->id)->first());$this->assertStringContainsString('/invoice-logos/',$view['presentation']['logo_url']);$this->assertSame($row->presentation_settings,DB::table('business_invoices')->where('id',$row->id)->value('presentation_settings'));
  $this->postJson('/api/business-invoices',$payload)->assertOk();$this->assertDatabaseCount('business_invoices',1);
  $new=[...$payload,'invoice_number'=>'SYNTHETIC-SETTINGS-002','request_id'=>(string)Str::uuid()];$this->postJson('/api/business-invoices',$new)->assertUnprocessable();
  $this->assertDatabaseCount('business_invoices',1);$this->assertDatabaseCount('invoice_series',0);
 }
 public function test_mfa_still_protects_settings_and_branding():void {config(['security.enforce_privileged_mfa'=>true]);$this->save()->assertForbidden()->assertJsonPath('code','MFA_REQUIRED');$this->deleteJson('/api/merchant/business-profile/image',['expected_logo'=>null])->assertForbidden()->assertJsonPath('code','MFA_REQUIRED');}
 public function test_logo_separation_migration_preserves_old_image_once():void {
  \Illuminate\Support\Facades\Schema::table('merchants',fn($t)=>$t->dropColumn('invoice_logo_url'));
  $url='/public/uploads/business-logos/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png';
  DB::table('merchants')->where('id',$this->m)->update(['logo_url'=>$url]);
  $migration=require database_path('migrations/2026_10_19_000003_separate_invoice_branding_logo.php');$migration->up();
  $this->assertSame($url,DB::table('merchants')->where('id',$this->m)->value('invoice_logo_url'));
  DB::table('merchants')->where('id',$this->m)->update(['invoice_logo_url'=>null]);$migration->up();
  $this->assertNull(DB::table('merchants')->where('id',$this->m)->value('invoice_logo_url'));
 }
 public function test_non_vat_preview_separates_non_vat_sales_from_exempt_sales():void {DB::table('merchants')->where('id',$this->m)->update(['vat_registered'=>false]);$s=app(MerchantInvoiceSettings::class)->defaults(DB::table('merchants')->where('id',$this->m)->first());$this->postJson('/api/merchant/invoice-settings/preview',['settings'=>$s,'document_type'=>'invoice'])->assertOk()->assertJsonPath('document.vat',0)->assertJsonPath('document.sspt_sales',2000)->assertJsonPath('document.vat_exempt',0);}
}
