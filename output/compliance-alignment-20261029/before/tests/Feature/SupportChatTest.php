<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class SupportChatTest extends TestCase
{
 protected function setUp():void {parent::setUp();config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array']);DB::purge('sqlite');foreach(['0001_01_01_000000_create_users_table.php','2026_06_02_000001_create_nuers_tables.php','2026_06_03_000002_add_api_token_to_users_table.php','2026_10_15_000008_create_support_chat_tables.php'] as $f)(require database_path('migrations/'.$f))->up();}
 private function admin(string $role='admin'):User {$u=User::factory()->create(['api_token'=>Str::random(64)]);DB::table('profiles')->insert(['id'=>$u->id,'email'=>$u->email,'role'=>$role]);return $u;}
 private function thread():array {$p=['id'=>(string)Str::uuid(),'visitor_token'=>bin2hex(random_bytes(32)),'request_id'=>(string)Str::uuid(),'name'=>'Test Visitor','email'=>'visitor@example.test','subject'=>'Registration help','message'=>'How do I choose my business activity?'];$this->postJson('/api/support/conversations',$p)->assertCreated()->assertJsonMissingPath('conversation.visitor_token_hash');return $p;}
 public function test_visitor_is_scoped_and_only_admins_can_read_inbox():void {
  $p=$this->thread();$this->getJson('/api/support/conversations/'.$p['id'])->assertNotFound();$this->withHeader('X-Chat-Token',str_repeat('x',64))->getJson('/api/support/conversations/'.$p['id'])->assertNotFound();
  $this->withHeader('X-Chat-Token',$p['visitor_token'])->getJson('/api/support/conversations/'.$p['id'])->assertOk()->assertJsonCount(1,'messages')->assertJsonMissingPath('conversation.visitor_email')->assertJsonMissingPath('messages.0.sender_id');
  $this->getJson('/api/admin/support')->assertUnauthorized();$merchant=$this->admin('merchant');$this->withToken($merchant->api_token)->getJson('/api/admin/support')->assertForbidden();
  $admin=$this->admin();$this->withToken($admin->api_token)->getJson('/api/admin/support')->assertOk()->assertJsonPath('counts.unread',1)->assertJsonPath('data.0.visitor_email','visitor@example.test')->assertJsonMissingPath('data.0.visitor_token_hash');
  $row=DB::table('support_conversations')->first();$this->assertNotSame($p['visitor_token'],$row->visitor_token_hash);$this->assertSame(hash('sha256',$p['visitor_token']),$row->visitor_token_hash);
 }
 public function test_creation_and_replies_are_idempotent_and_unread_markers_are_scoped():void {
  $p=$this->thread();$this->postJson('/api/support/conversations',$p)->assertOk();$this->assertSame(1,DB::table('support_conversations')->count());
  $p['message']='Changed';$this->postJson('/api/support/conversations',$p)->assertConflict();
  $admin=$this->admin();$reply=['request_id'=>(string)Str::uuid(),'body'=>'Choose the activity that best describes your primary business.'];
  $m=$this->withToken($admin->api_token)->postJson('/api/admin/support/'.$p['id'].'/messages',$reply)->assertCreated()->json('message.id');
  $this->withToken($admin->api_token)->postJson('/api/admin/support/'.$p['id'].'/messages',$reply)->assertOk();$this->assertSame(2,DB::table('support_messages')->count());
  $this->withHeader('X-Chat-Token',$p['visitor_token'])->getJson('/api/support/conversations/'.$p['id'])->assertJsonPath('conversation.unread_count',1)->assertJsonPath('conversation.status','pending');
  $this->withHeader('X-Chat-Token',$p['visitor_token'])->postJson('/api/support/conversations/'.$p['id'].'/read',['message_id'=>$m])->assertOk();
  $this->withHeader('X-Chat-Token',$p['visitor_token'])->getJson('/api/support/conversations/'.$p['id'])->assertJsonPath('conversation.unread_count',0);
  $this->withHeader('X-Chat-Token',$p['visitor_token'])->postJson('/api/support/conversations/'.$p['id'].'/read',['message_id'=>999])->assertUnprocessable();
 }
 public function test_triage_checks_revision_assignee_role_and_tracks_events():void {
  $p=$this->thread();$admin=$this->admin();$merchant=$this->admin('merchant');$payload=['revision'=>1,'status'=>'resolved','priority'=>'urgent','assigned_to'=>$merchant->id];
  $this->withToken($admin->api_token)->patchJson('/api/admin/support/'.$p['id'],$payload)->assertUnprocessable();$payload['assigned_to']=$admin->id;
  $this->withToken($admin->api_token)->patchJson('/api/admin/support/'.$p['id'],$payload)->assertOk();$this->withToken($admin->api_token)->patchJson('/api/admin/support/'.$p['id'],$payload)->assertConflict();
  $this->assertDatabaseHas('support_events',['conversation_id'=>$p['id'],'action'=>'conversation.updated','actor_id'=>$admin->id]);
  $this->withHeader('X-Chat-Token',$p['visitor_token'])->postJson('/api/support/conversations/'.$p['id'].'/messages',['request_id'=>(string)Str::uuid(),'body'=>'One more question.'])->assertCreated();$this->assertDatabaseHas('support_conversations',['id'=>$p['id'],'status'=>'open']);
 }
 public function test_message_history_pagination_search_and_expired_tokens():void {
  $p=$this->thread();for($i=0;$i<110;$i++)DB::table('support_messages')->insert(['conversation_id'=>$p['id'],'request_id'=>(string)Str::uuid(),'sender_type'=>'visitor','body'=>'Message '.$i,'created_at'=>now()]);
  $r=$this->withHeader('X-Chat-Token',$p['visitor_token'])->getJson('/api/support/conversations/'.$p['id'])->assertJsonCount(100,'messages')->assertJsonPath('has_more',true);
  $first=$r->json('messages.0.id');$last=$r->json('messages.99.id');$this->assertLessThan($last,$first);
  $this->withHeader('X-Chat-Token',$p['visitor_token'])->getJson('/api/support/conversations/'.$p['id'].'?before='.$first)->assertJsonCount(11,'messages');
  $this->withHeader('X-Chat-Token',$p['visitor_token'])->getJson('/api/support/conversations/'.$p['id'].'?after='.$last)->assertJsonCount(0,'messages');
  $admin=$this->admin();$this->withToken($admin->api_token)->getJson('/api/admin/support?search=Message%20109&unread=1')->assertJsonPath('total',1);
  DB::table('support_conversations')->where('id',$p['id'])->update(['token_expires_at'=>now()->subSecond()]);$this->withHeader('X-Chat-Token',$p['visitor_token'])->getJson('/api/support/conversations/'.$p['id'])->assertNotFound();
 }
 public function test_body_size_and_missing_data_are_rejected():void {
  $this->postJson('/api/support/conversations',[])->assertUnprocessable();$p=$this->thread();
  $this->withHeader('X-Chat-Token',$p['visitor_token'])->postJson('/api/support/conversations/'.$p['id'].'/messages',['request_id'=>(string)Str::uuid(),'body'=>str_repeat('x',4001)])->assertUnprocessable();
 }
}
