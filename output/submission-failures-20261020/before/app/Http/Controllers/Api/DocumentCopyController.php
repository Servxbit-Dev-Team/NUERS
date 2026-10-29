<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{ApiAccess, BirCompliance, DocumentPdf, ElectronicDocumentView, PlatformMail};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;
use Illuminate\Cache\RateLimiter as DurableRateLimiter;
use Illuminate\Support\Facades\Cache;
use Carbon\CarbonImmutable;

class DocumentCopyController extends Controller
{
    private function record(Request $r, string $source, string $id): array
    {
        $merchant = app(ApiAccess::class)->merchant($r);
        abort_unless(in_array($source, ['invoice', 'receipt'], true), 404);
        $row = DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('id', $id)->where('merchant_id', $merchant->id)->first();
        abort_unless($row, 404);
        app(\App\Services\MerchantTeamAccess::class)->assertDocument($source, $row);
        return [$merchant, $row];
    }

    public function info(Request $r, string $source, string $id)
    {
        [$merchant, $row] = $this->record($r, $source, $id);
        $settings = app(PlatformMail::class)->settings();
        $history = DB::table('document_email_deliveries')->where('merchant_id', $merchant->id)->where('document_source', $source)->where('document_id', $id)->orderByDesc('created_at')->limit(10)->get()->map(fn ($d) => ['id' => $d->id, 'email' => Crypt::decryptString($d->encrypted_recipient), 'state' => $d->state, 'created_at' => CarbonImmutable::parse($d->created_at, config('app.timezone'))->toIso8601String()]);
        return response()->json(['email' => $row->buyer_email ?? $row->customer_email ?? '', 'can_send' => !empty($settings['enabled']) && $row->status !== 'draft', 'draft' => $row->status === 'draft', 'smtp_enabled' => !empty($settings['enabled']), 'history' => $history])->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $r, string $source, string $id)
    {
        [$merchant, $row] = $this->record($r, $source, $id);
        $store = config('cache.default');
        $limiter = new DurableRateLimiter(Cache::store($store === 'array' ? 'database' : $store));
        $budget = 'document-pdf:'.$merchant->id;
        abort_if($limiter->tooManyAttempts($budget, 20), 429, 'PDF download budget reached. Try again in a minute.');
        $limiter->hit($budget, 60);
        $d = app(ElectronicDocumentView::class)->make($source, $row);
        $pdf = app(DocumentPdf::class)->render($d);
        app(BirCompliance::class)->audit('document.copy.downloaded', $id, (string) $r->attributes->get('api_actor')->id, ['merchant_id' => $merchant->id, 'source' => $source, 'pdf_hash' => hash('sha256', $pdf)]);
        return response($pdf)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.app(DocumentPdf::class)->filename($d).'"')->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $r, string $source, string $id)
    {
        [$merchant, $row] = $this->record($r, $source, $id);
        $v = $r->validate(['email' => 'required|email:rfc|max:254', 'request_id' => 'required|uuid']);
        abort_if($row->status === 'draft', 409, 'Drafts must not be emailed as issued customer copies.');
        $requestHash = hash('sha256', json_encode([$source, $id, $v['email']], JSON_THROW_ON_ERROR));
        $existing = DB::table('document_email_deliveries')->where('merchant_id', $merchant->id)->where('request_id', $v['request_id'])->first();
        if ($existing) return $this->previous($existing, $requestHash);
        $settings = app(PlatformMail::class)->settings();
        abort_unless(!empty($settings['enabled']), 503, 'Customer email delivery is not enabled. Ask an administrator to configure and test SMTP.');
        $budget = 'document-copy:'.$merchant->id;
        $store = config('cache.default');
        $limiter = new DurableRateLimiter(Cache::store($store === 'array' ? 'database' : $store));
        abort_if($limiter->tooManyAttempts($budget, 10), 429, 'Customer email budget reached. Try again in a minute.');
        $limiter->hit($budget, 60);
        return DB::transaction(function() use ($r,$v,$source,$id,$merchant,$requestHash) {
            DB::table('merchants')->where('id',$merchant->id)->lockForUpdate()->first();
            $pending=DB::table('document_email_deliveries')->where('merchant_id',$merchant->id)->where('request_hash',$requestHash)->whereIn('state',['queued','preparing','sending'])->lockForUpdate()->first();
            if($pending) return $this->previous($pending,$requestHash);
            $deliveryId=(string)Str::uuid();
            $inserted=DB::table('document_email_deliveries')->insertOrIgnore(['id'=>$deliveryId,'request_id'=>$v['request_id'],'merchant_id'=>$merchant->id,'document_source'=>$source,'document_id'=>$id,'actor_id'=>$r->attributes->get('api_actor')->id,'encrypted_recipient'=>Crypt::encryptString($v['email']),'request_hash'=>$requestHash,'state'=>'queued','created_at'=>now(),'updated_at'=>now()]);
            if (!$inserted) return $this->previous(DB::table('document_email_deliveries')->where('merchant_id',$merchant->id)->where('request_id',$v['request_id'])->first(),$requestHash);
            $outbox=app(\App\Services\PlatformNotifications::class)->queue($v['email'],'document.customer_copy',$deliveryId,'Your electronic document copy','Your requested electronic document from '.$merchant->business_name.' is attached as a PDF. For questions about the purchase, contact the issuing business. This customer copy preserves the recorded document; email delivery does not change payment or BIR acceptance status.','customer_copy',merchantId:$merchant->id,context:['delivery_id'=>$deliveryId]);
            abort_unless($outbox,503,'Email delivery is temporarily unavailable.');
            DB::table('document_email_deliveries')->where('id',$deliveryId)->update(['outbox_id'=>$outbox]);
            app(BirCompliance::class)->audit('document.copy.queued',$id,(string)$r->attributes->get('api_actor')->id,['merchant_id'=>$merchant->id,'source'=>$source,'delivery_id'=>$deliveryId]);
            return response()->json(['id'=>$deliveryId,'state'=>'queued','message'=>'Your PDF copy is queued for email delivery. Delivery history updates automatically.'],202);
        });
    }

    private function previous(object $row, string $hash)
    {
        abort_unless(hash_equals($row->request_hash, $hash), 409, 'This send request was already used for another recipient or document.');
        return response()->json(['id' => $row->id, 'state' => $row->state, 'message' => 'Existing send request: '.str_replace('_', ' ', $row->state).'. No duplicate email was sent.'], in_array($row->state,['queued','preparing','sending'],true) ? 202 : ($row->state === 'smtp_accepted' ? 200 : 409));
    }
}
