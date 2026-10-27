<?php

namespace App\Services;

use App\Support\SchemaMap;
use Illuminate\Support\Facades\{DB,Crypt,Schema};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MerchantWebhooks
{
    public const EVENTS = ['transaction.accepted'=>'API transaction durably recorded','source.received'=>'Owned-system original captured',
        'invoice.issued'=>'New NUERS invoice issued through the API','payment.recorded'=>'API payment receipt recorded'];

    public function enabled(): bool { return (bool)config('security.merchant_webhook_delivery_enabled'); }

    public function endpoint(object $p,bool $owner=true): array
    {
        return ['id'=>$p->id,'name'=>$p->name,'environment'=>$p->environment,'url'=>$owner?Crypt::decryptString($p->encrypted_url):'https://'.$p->host.'/…',
            'host'=>$p->host,'events'=>json_decode($p->events,true),'status'=>$p->status,'revision'=>(int)$p->revision,
            'secret_version'=>(int)$p->secret_version,'secret_hint'=>'whsec_••••'.$p->secret_last_four,'verified_at'=>$p->verified_at,
            'created_at'=>$p->created_at,'updated_at'=>$p->updated_at];
    }

    public function queue(object $p,string $type,string $subject,array $details,string $identity): string
    {
        $key=hash('sha256',json_encode([$p->id,$p->environment,$type,$identity]));
        $old=DB::table('merchant_webhook_deliveries')->where('event_key',$key)->first();if($old)return $old->id;
        $id=(string)Str::uuid();
        $body=json_encode(['id'=>$id,'type'=>$type,'environment'=>$p->environment,'merchant_id'=>$p->merchant_id,
            'subject_id'=>$subject,'occurred_at'=>now()->toIso8601String(),'data'=>$details],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        DB::table('merchant_webhook_deliveries')->insert(['id'=>$id,'endpoint_id'=>$p->id,'merchant_id'=>$p->merchant_id,'environment'=>$p->environment,
            'event_key'=>$key,'event_type'=>$type,'subject_id'=>$subject,'encrypted_body'=>Crypt::encryptString($body),'body_hash'=>hash('sha256',$body),
            'secret_version'=>$p->secret_version,'state'=>'pending','available_at'=>now(),'retry_deadline_at'=>$type==='webhook.endpoint_challenge'?now()->addMinutes(10):now()->addDay(),
            'created_at'=>now(),'updated_at'=>now()]);
        return $id;
    }

    /** Called within the source transaction. No HTTP occurs before its financial commit. */
    public function accepted(string $merchant,string $environment,string $id,array $response,string $specific): void
    {
        if(!SchemaMap::hasTable('merchant_webhook_endpoints'))return;
        $hooks=DB::table('merchant_webhook_endpoints')->where('merchant_id',$merchant)->where('environment',$environment)
            ->where('status','active')->whereNotNull('verified_at')->get();
        // Intentionally omit amounts, customer data, external payloads, credentials and document images.
        $details=array_intersect_key($response,array_flip(['id','transaction_id','receipt_id','record_mode','status','nuers_status','accepted_at']));
        foreach($hooks as $p) foreach(array_unique(['transaction.accepted',$specific]) as $event){
            if(in_array($event,json_decode($p->events,true),true))$this->queue($p,$event,$id,$details,$id);
        }
    }

    public function heartbeat(string $state): void
    {
        DB::table('merchant_webhook_runtime')->upsert([['id'=>'dispatcher','last_seen_at'=>now(),'state'=>$state]],['id'],['last_seen_at','state']);
    }

    public function runOne(): ?string
    {
        $this->heartbeat($this->enabled()?'running':'paused');
        if(!$this->enabled())return 'disabled';
        $d=DB::transaction(function(){
            $d=DB::table('merchant_webhook_deliveries')->whereIn('state',['pending','retry','sending'])->where('available_at','<=',now())
                ->where(fn($q)=>$q->whereNull('lease_until')->orWhere('lease_until','<',now()))->orderBy('available_at')->orderBy('id')->lockForUpdate()->first();
            if(!$d)return null;
            $d->lease_token=(string)Str::uuid();
            DB::table('merchant_webhook_deliveries')->where('id',$d->id)->update(['lease_token'=>$d->lease_token,'lease_until'=>now()->addMinutes(3),'state'=>'sending','updated_at'=>now()]);
            return $d;
        },3);
        if(!$d)return null;
        $p=DB::table('merchant_webhook_endpoints')->where('id',$d->endpoint_id)->where('merchant_id',$d->merchant_id)->where('environment',$d->environment)->first();
        $active=DB::table('merchants')->where('id',$d->merchant_id)->value('status')==='active';
        $challenge=$d->event_type==='webhook.endpoint_challenge';
        $valid=$p && $active && $p->status!=='revoked' && (int)$p->secret_version===(int)$d->secret_version
            && ($challenge?$p->verification_delivery_id===$d->id:($p->status==='active' && $p->verified_at && ($d->event_type==='webhook.test' || in_array($d->event_type,json_decode($p->events,true),true))));
        if(!$valid)return $this->finish($d,'cancelled',null,'endpoint_or_account_changed');
        if(now()->gte($d->retry_deadline_at) || $d->cycle_attempts>=12)return $this->finish($d,'dead_letter',null,'retry_budget_exhausted');
        try {
            app(AtomicQuota::class)->admit('merchant-webhook-global',500000,600);
            app(AtomicQuota::class)->admit('merchant-webhook:'.$p->id,50000,60);
        } catch(HttpExceptionInterface $e){
            if($e->getStatusCode()!==429)throw $e;
            DB::table('merchant_webhook_deliveries')->where('id',$d->id)->where('lease_token',$d->lease_token)->update(['state'=>'retry','last_error'=>'dispatch_rate_limit','available_at'=>now()->addMinute(),'lease_token'=>null,'lease_until'=>null,'updated_at'=>now()]);
            return 'retry';
        }
        $attempt=(string)Str::uuid();$number=$d->attempts+1;$started=microtime(true);
        DB::transaction(function()use($d,$attempt,$number){
            DB::table('merchant_webhook_deliveries')->where('id',$d->id)->where('lease_token',$d->lease_token)->update(['attempts'=>$number,'cycle_attempts'=>$d->cycle_attempts+1,'updated_at'=>now()]);
            DB::table('merchant_webhook_attempts')->insert(['id'=>$attempt,'delivery_id'=>$d->id,'number'=>$number,'state'=>'in_flight','started_at'=>now()]);
        },3);
        $status=null;$error=null;$state='retry';
        try {
            $body=Crypt::decryptString($d->encrypted_body);
            if(!hash_equals($d->body_hash,hash('sha256',$body)))throw new \RuntimeException('integrity');
            $at=(string)now()->timestamp;
            $signature=SignedWebhook::signature(Crypt::decryptString($p->encrypted_secret),$d->id,$at,$body);
            $r=app(MerchantWebhookTransport::class)->send(Crypt::decryptString($p->encrypted_url),[
                'Content-Type: application/json','Accept: application/json','X-NUERS-Event: '.$d->id,
                'X-NUERS-Timestamp: '.$at,'X-NUERS-Signature: v1='.$signature,
                'X-NUERS-Environment: '.$d->environment,'X-NUERS-Key-Version: '.$p->secret_version],$body);
            $status=$r['status'];$error=$r['error'];
            if(!$error && $status>=200 && $status<300){
                if($challenge){
                    $reply=json_decode($r['body'],true);$expected=json_decode($body,true)['data']['challenge'];
                    if(!is_array($reply)||!is_string($reply['nuers_challenge']??null)||!hash_equals($expected,$reply['nuers_challenge'])){$state='dead_letter';$error='challenge_echo_missing';}
                    else $state='delivered';
                }else $state='delivered';
            }elseif($status>=300 && $status<400){$state='dead_letter';$error='redirect_blocked';}
            elseif(in_array($status,[400,401,403,404,405,410,413,422],true)){$state='dead_letter';$error='receiver_rejected';}
            else $error=$error?:'receiver_unavailable';
        }catch(\Throwable $e){
            $error=$e instanceof HttpExceptionInterface?'destination_blocked':'transport_or_integrity_failure';
            if($e instanceof HttpExceptionInterface)$state='dead_letter';
            // Never log arbitrary receiver response bodies, callback credentials or exception URLs.
        }
        if($challenge && $state!=='delivered')$state='dead_letter';
        if($d->cycle_attempts+1>=12 && $state!=='delivered')$state='dead_letter';
        return DB::transaction(function()use($d,$p,$challenge,$attempt,$state,$status,$error,$started){
            if($challenge && $state==='delivered' && DB::table('merchant_webhook_deliveries')->where('id',$d->id)->where('lease_token',$d->lease_token)->lockForUpdate()->exists())DB::table('merchant_webhook_endpoints')->where('id',$p->id)->where('secret_version',$d->secret_version)
                ->where('verification_delivery_id',$d->id)->where('status','!=','revoked')->update(['verified_at'=>now(),'status'=>'paused','revision'=>DB::raw('revision + 1'),'updated_at'=>now()]);
            DB::table('merchant_webhook_attempts')->where('id',$attempt)->update(['state'=>$state,'http_status'=>$status,'error_code'=>$error,'latency_ms'=>(int)round((microtime(true)-$started)*1000),'finished_at'=>now()]);
            return $this->finish($d,$state,$status,$error);
        },3);
    }

    private function finish(object $d,string $state,?int $status,?string $error): string
    {
        DB::table('merchant_webhook_deliveries')->where('id',$d->id)->where('lease_token',$d->lease_token)->update([
            'state'=>$state,'last_http_status'=>$status,'last_error'=>$error,'delivered_at'=>$state==='delivered'?now():null,
            'available_at'=>now()->addSeconds(min(21600,60*(2**min(10,(int)$d->cycle_attempts)))+random_int(1,15)),
            'lease_token'=>null,'lease_until'=>null,'updated_at'=>now()]);
        return $state;
    }
}
