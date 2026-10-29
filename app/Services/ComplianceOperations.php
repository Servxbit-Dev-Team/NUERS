<?php

namespace App\Services;

use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tenant-bound operational evidence. Originals and wallet debits are never rewritten. */
class ComplianceOperations
{
    public function row(object $r): array
    {
        $payload = json_decode(Crypt::decryptString($r->payload), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($r->payload_hash, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))), 409, 'Compliance evidence integrity check failed.');
        return [...collect((array) $r)->except(['payload', 'request_hash'])->all(), 'details' => $payload];
    }

    public function source(string $merchant, string $source, string $id): array
    {
        abort_unless(in_array($source, ['invoice', 'receipt'], true), 422, 'Select an invoice or captured receipt.');
        $r = DB::table($source === 'invoice' ? 'business_invoices' : 'transaction_receipts')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($r && in_array($r->status, ['issued', 'validated', 'sent', 'paid', 'partially_paid', 'overdue'], true), 404, 'Issued original not found in this business.');
        abort_unless(in_array($r->document_type, ['sales_invoice', 'vat_invoice', 'non_vat_invoice', 'vat_exempt_invoice', 'zero_rated_invoice', 'mixed_sales_invoice', 'service_invoice'], true), 422, 'Select a principal sales invoice.');
        $c = app(InvoiceCalculator::class);
        return ['source' => $source, 'id' => $id, 'number' => $source === 'invoice' ? $r->invoice_number : $r->receipt_number,
            'business_date' => substr($source === 'invoice' ? $r->issue_date : $r->issued_at, 0, 10),
            'branch_code' => $r->seller_branch_code, 'gross_centavos' => $c->cents($source === 'invoice' ? $r->total_amount : $r->total_due, 'total'),
            'vat_centavos' => $c->cents($r->vat_amount, 'vat'), 'withholding_centavos' => $c->cents($r->withholding_tax ?? 0, 'withholding'),
            'buyer_name' => $r->buyer_name, 'buyer_tin' => $r->buyer_tin, 'buyer_address' => $r->buyer_address,
            'seller_name' => $r->merchant_name, 'seller_tin' => $r->merchant_tin, 'seller_address' => $r->merchant_address];
    }

    public function currentWithholding(string $merchant, array $source): int
    {
        $last = DB::table('merchant_compliance_records')->where('merchant_id', $merchant)->where('kind', 'cwt_memo')->where('related_source', $source['source'])->where('related_id', $source['id'])->where('status', 'issued')->orderByDesc('source_sequence')->first();
        return $last ? (int) $this->row($last)['details']['new_withholding_centavos'] : $source['withholding_centavos'];
    }

    private function persist(string $merchant, string $actor, string $kind, array $v, array $payload, array $extra = []): array
    {
        $id = (string) Str::uuid();
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::table('merchant_compliance_records')->insert([
            'id' => $id, 'merchant_id' => $merchant, 'kind' => $kind, 'reference' => ($kind==='outage'?'OUTAGE-':'DRAFT-').strtoupper($id),
            'status' => 'draft', 'business_date' => now('Asia/Manila')->toDateString(), 'payload' => Crypt::encryptString($json), 'payload_hash' => hash('sha256', $json),
            'request_id' => $v['request_id'], 'request_hash' => app(IntegrationContract::class)->canonicalHash($v), 'created_by' => $actor,
            'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);
        app(BirCompliance::class)->audit('compliance.'.$kind.'.created', $id, $actor, ['merchant_id' => $merchant, 'payload_hash' => hash('sha256', $json)]);
        return $this->row(DB::table('merchant_compliance_records')->find($id));
    }

    public function create(string $merchant, string $actor, string $kind, array $v): array
    {
        return DB::transaction(function () use ($merchant, $actor, $kind, $v) {
            abort_unless(DB::table('merchants')->where('id', $merchant)->lockForUpdate()->exists(), 404);
            $old = DB::table('merchant_compliance_records')->where('merchant_id', $merchant)->where('request_id', $v['request_id'])->first();
            if ($old) {
                abort_unless($old->kind === $kind && hash_equals($old->request_hash, app(IntegrationContract::class)->canonicalHash($v)), 409, 'Request ID was already used with different content.');
                return $this->row($old);
            }
            if ($kind === 'outage') {
                $p = validator($v, ['title'=>'required|string|min:5|max:200', 'started_at'=>'required|date|before_or_equal:now', 'evidence_reference'=>'required|string|min:8|max:2000'])->validate();
                $p['started_at'] = C::parse($p['started_at'])->toIso8601String();
                return $this->persist($merchant, $actor, $kind, $v, $p, ['status'=>'open', 'business_date'=>C::parse($p['started_at'])->setTimezone('Asia/Manila')->toDateString()]);
            }
            if ($kind === 'manual_invoice') {
                $p = validator($v, ['outage_id'=>'required|uuid', 'manual_number'=>'required|string|max:114', 'branch_code'=>['required','regex:/^[0-9]{5}$/D'], 'issued_at'=>'required|date|before_or_equal:now', 'amount'=>'required|numeric|min:0|max:10000000000|decimal:0,2', 'buyer_name'=>'required|string|max:255', 'authority_reference'=>'required|string|min:8|max:2000', 'evidence_reference'=>'required|string|min:8|max:2000'])->validate();
                $outage = DB::table('merchant_compliance_records')->where('merchant_id', $merchant)->where('kind', 'outage')->where('id', $p['outage_id'])->first();
                abort_unless($outage, 404, 'Outage not found in this business.');
                $op = $this->row($outage)['details'];
                abort_unless(C::parse($p['issued_at'])->gte(C::parse($op['started_at'])) && C::parse($p['issued_at'])->lte(C::parse($op['restored_at'] ?? now())), 422, 'Manual invoice time must fall within the recorded outage.');
                $p['issued_at'] = C::parse($p['issued_at'])->toIso8601String();
                $p['gross_centavos'] = app(InvoiceCalculator::class)->cents($p['amount'], 'amount');
                return $this->persist($merchant, $actor, $kind, $v, $p, ['reference'=>$p['branch_code'].'/'.$p['manual_number'], 'branch_code'=>$p['branch_code'], 'status'=>'awaiting_replacement', 'business_date'=>C::parse($p['issued_at'])->setTimezone('Asia/Manila')->toDateString(), 'amount_centavos'=>$p['gross_centavos']]);
            }
            if ($kind === 'cwt_memo') {
                $p = validator($v, ['source'=>'required|in:invoice,receipt', 'document_id'=>'required|string|max:100', 'withholding'=>'required|array', 'reason'=>'required|string|min:10|max:2000'])->validate();
                $source = $this->source($merchant, $p['source'], $p['document_id']);
                $p['withholding'] = app(WithholdingEvidence::class)->validate($p['withholding'], $source['gross_centavos']);
                $p['original'] = $source;
                $p['previous_withholding_centavos'] = $this->currentWithholding($merchant, $source);
                $p['new_withholding_centavos'] = $p['withholding']['amount_centavos'];
                abort_if($p['previous_withholding_centavos'] === $p['new_withholding_centavos'], 422, 'CWT has not changed.');
                $p['sales_adjustment_centavos'] = 0; $p['vat_adjustment_centavos'] = 0;
                $p['corrected_net_payable_centavos'] = $source['gross_centavos'] - $p['new_withholding_centavos'];
                return $this->persist($merchant, $actor, $kind, $v, $p, ['related_source'=>$p['source'], 'related_id'=>$p['document_id'], 'branch_code'=>$source['branch_code'], 'amount_centavos'=>$p['new_withholding_centavos'] - $p['previous_withholding_centavos']]);
            }
            abort_unless($kind === 'service_fee', 422);
            $p = validator($v, ['from'=>'required|date_format:Y-m-d', 'to'=>'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:today', 'reason'=>'required|string|min:10|max:2000'])->validate();
            abort_if(C::parse($p['from'])->diffInDays(C::parse($p['to'])) > 31, 422, 'Choose a billing period of up to 32 days.');
            $fees = DB::table('merchant_wallet_entries as w')->where('w.merchant_id', $merchant)->where('w.kind', 'fee')->where('w.created_at', '>=', $p['from'].' 00:00:00')->where('w.created_at', '<', C::parse($p['to'])->addDay()->format('Y-m-d H:i:s'))->whereNotExists(fn($q)=>$q->selectRaw('1')->from('service_fee_invoice_entries as f')->whereColumn('f.wallet_entry_id','w.id'))->orderBy('w.sequence')->limit(10001)->get();
            abort_unless($fees->isNotEmpty(), 422, 'No unbilled fee debits in this period.');
            abort_if($fees->count()>10000, 422, 'Narrow the billing period to at most 10,000 fee entries.');
            $p['fee_count'] = $fees->count(); $p['gross_centavos'] = -(int) $fees->sum('amount_centavos');
            $p['recipient'] = (array) DB::table('merchants')->where('id',$merchant)->first(['business_name','tin','address']);
            $p['entry_digest'] = hash('sha256', json_encode($fees->map(fn($f)=>[$f->id, $f->amount_centavos, $f->entry_hash])->all()));
            $record = $this->persist($merchant, $actor, $kind, $v, $p, ['amount_centavos'=>$p['gross_centavos']]);
            foreach ($fees as $fee) DB::table('service_fee_invoice_entries')->insert(['wallet_entry_id'=>$fee->id, 'compliance_record_id'=>$record['id'], 'created_at'=>now(), 'updated_at'=>now()]);
            return $record;
        }, 3);
    }

    private function update(object $r, array $p, array $fields, string $actor, string $event): array
    {
        $json = json_encode($p, JSON_THROW_ON_ERROR);
        DB::table('merchant_compliance_records')->where('id',$r->id)->update([...$fields, 'payload'=>Crypt::encryptString($json), 'payload_hash'=>hash('sha256',$json), 'revision'=>$r->revision+1, 'updated_at'=>now()]);
        app(BirCompliance::class)->audit('compliance.'.$event, $r->id, $actor, ['merchant_id'=>$r->merchant_id,'before_hash'=>$r->payload_hash,'after_hash'=>hash('sha256',$json),'revision'=>$r->revision+1]);
        return $this->row(DB::table('merchant_compliance_records')->find($r->id));
    }

    public function act(string $merchant, string $id, string $actor, array $v): array
    {
        return DB::transaction(function () use ($merchant,$id,$actor,$v) {
            DB::table('merchants')->where('id',$merchant)->lockForUpdate()->first();
            $r=DB::table('merchant_compliance_records')->where('merchant_id',$merchant)->where('id',$id)->lockForUpdate()->first();
            abort_unless($r,404);
            abort_unless((int)$r->revision === (int)$v['revision'],409,'Record changed; refresh before continuing.');
            $p=$this->row($r)['details'];
            if ($v['action']==='cancel') {
                abort_unless($r->status==='draft',409,'Only drafts may be cancelled.');
                $x=validator($v,['reason'=>'required|string|min:10|max:2000'])->validate();
                $p['cancellation_reason']=$x['reason'];
                if ($r->kind==='service_fee') DB::table('service_fee_invoice_entries')->where('compliance_record_id',$id)->delete();
                return $this->update($r,$p,['status'=>'cancelled'],$actor,'draft.cancelled');
            }
            if ($v['action']==='restore') {
                abort_unless($r->kind==='outage' && $r->status==='open',409);
                $x=validator($v,['restored_at'=>'required|date|before_or_equal:now','evidence_reference'=>'required|string|min:8|max:2000'])->validate();
                abort_unless(C::parse($x['restored_at'])->gte(C::parse($p['started_at'])),422,'Restoration cannot precede the outage.');
                $p['restored_at']=C::parse($x['restored_at'])->toIso8601String();$p['restoration_evidence']=$x['evidence_reference'];
                $p['recovery_due_at']=C::parse($x['restored_at'])->setTimezone('Asia/Manila')->addDays(3)->endOfDay()->toIso8601String();
                return $this->update($r,$p,['status'=>'restored'],$actor,'outage.restored');
            }
            if ($v['action']==='link') {
                $x=validator($v,['source'=>'required|in:invoice,receipt','document_id'=>'required|string|max:100'])->validate();
                return $this->linkManual($merchant,$id,$x['source'],$x['document_id'],$actor);
            }
            abort_unless($v['action']==='issue' && $r->status==='draft' && in_array($r->kind,['cwt_memo','service_fee']),409,'Only an eligible draft can be issued.');
            if ($r->kind==='cwt_memo') {
                $source=$this->source($merchant,$r->related_source,$r->related_id);
                abort_unless($source===$p['original'] && $this->currentWithholding($merchant,$source)===$p['previous_withholding_centavos'],409,'The original or current withholding changed. Prepare a new draft.');
                abort_unless(preg_match('/^[0-9]{5}$/D',$r->branch_code??''),422,'The original needs a registered branch code.');
                $sequence=1+(int) DB::table('merchant_compliance_records')->where('merchant_id',$merchant)->where('kind','cwt_memo')->where('related_source',$r->related_source)->where('related_id',$r->related_id)->where('status','issued')->max('source_sequence');
                $authority=app(BirCompliance::class)->assertCanIssue($merchant,$r->branch_code);
                $seq=app(InvoiceSequence::class)->reserve($merchant,$r->branch_code,'credit_memo');
                $p['authority']=$authority;$p['series_authority']=$seq['authority_reference'];$p['document_registration']=$seq['document_registration']??null;$p['issued_at']=now()->toIso8601String();
                return $this->update($r,$p,['source_sequence'=>$sequence,'reference'=>$seq['number'],'status'=>'issued','business_date'=>now('Asia/Manila')->toDateString()],$actor,'cwt_memo.issued');
            }
            return $this->issueFees($r,$p,$actor);
        },3);
    }

    public function linkManual(string $merchant,string $id,string $source,string $documentId,string $actor): array
    {
        abort_unless(DB::transactionLevel()>0,500);
        $r=DB::table('merchant_compliance_records')->where('merchant_id',$merchant)->where('id',$id)->where('kind','manual_invoice')->lockForUpdate()->first();
        abort_unless($r,404,'Manual recovery record not found in this business.');
        if ($r->status==='reconciled') {
            abort_unless($r->related_source===$source && $r->related_id===$documentId,409,'Manual invoice already has a replacement.');
            return $this->row($r);
        }
        $p=$this->row($r)['details'];$replacement=$this->source($merchant,$source,$documentId);
        abort_unless($replacement['gross_centavos']===$p['gross_centavos'] && $replacement['branch_code']===$r->branch_code,422,'Replacement branch and amount must match the manual invoice.');
        abort_unless(mb_strtolower(trim($replacement['buyer_name']??''))===mb_strtolower(trim($p['buyer_name'])),422,'Replacement buyer must match the manual invoice.');
        $outage=DB::table('merchant_compliance_records')->where('merchant_id',$merchant)->where('id',$p['outage_id'])->where('status','restored')->first();
        abort_unless($outage,409,'Record restoration before reconciling replacements.');
        $restored=$this->row($outage)['details']['restored_at'];
        abort_unless($replacement['business_date']>=C::parse($restored)->setTimezone('Asia/Manila')->toDateString(),422,'Replacement must be issued after restoration.');
        if ($source==='invoice') {
            $ref=DB::table('business_invoices')->where('id',$documentId)->value('reference_number');
            abort_unless($ref===$p['manual_number'],422,'The replacement invoice must explicitly reference the original manual number.');
        } else {
            $receipt=DB::table('transaction_receipts')->where('id',$documentId)->first();
            abort_unless(C::parse($receipt->issued_at, config('app.timezone'))->gte(C::parse($restored)),422,'Replacement must be issued after restoration.');
            $payload=json_decode(DB::table('merchant_transactions')->where('id',$receipt->transaction_id)->value('external_payload')??'{}',true);
            abort_unless(($payload['manual_recovery_id']??null)===$id && ($payload['manual_invoice_number']??null)===$p['manual_number'],422,'The original API payload must identify this manual_recovery_id.');
        }
        abort_if(DB::table('merchant_compliance_records')->where('merchant_id',$merchant)->where('kind','manual_invoice')->where('related_source',$source)->where('related_id',$documentId)->where('id','!=',$id)->exists(),409,'Replacement is already linked to another manual invoice.');
        $p['replacement']=$replacement;$p['reconciled_at']=now()->toIso8601String();
        return $this->update($r,$p,['status'=>'reconciled','related_source'=>$source,'related_id'=>$documentId],$actor,'manual.reconciled');
    }

    private function issueFees(object $r,array $p,string $actor): array
    {
        $issuerId=config('bir_compliance.service_invoice_issuer_id');
        abort_unless($issuerId,409,'Configure the registered NUERS billing entity before issuing service invoices. The fee statement remains available.');
        $issuer=DB::table('merchants')->where('id',$issuerId)->lockForUpdate()->first();
        abort_unless($issuer && $issuer->status==='active' && $issuer->id!==$r->merchant_id,409,'A separate active registered NUERS billing entity is required.');
        $branch=config('bir_compliance.service_invoice_branch','00000');
        $authority=app(BirCompliance::class)->assertCanIssue($issuerId,$branch);
        $seq=app(InvoiceSequence::class)->reserve($issuerId,$branch,'invoice');
        $gross=(int)$p['gross_centavos'];$net=$issuer->vat_registered?(int)round($gross/1.12):$gross;$vat=$gross-$net;
        $id=(string)Str::uuid();$now=now();
        // Fees already paid from the recipient wallet. No second wallet debit is made.
        $invoice=['id'=>$id,'merchant_id'=>$issuerId,'invoice_number'=>$seq['number'],'document_registration'=>json_encode($seq['document_registration']??null),'document_type'=>$issuer->vat_registered?'vat_invoice':'non_vat_invoice','status'=>'issued','version_number'=>1,'merchant_name'=>$issuer->business_name,'merchant_tin'=>$issuer->tin,'merchant_address'=>$issuer->address??'', 'buyer_name'=>$p['recipient']['business_name'],'buyer_tin'=>$p['recipient']['tin'],'buyer_address'=>$p['recipient']['address'], 'issue_date'=>now('Asia/Manila')->toDateString(),'subtotal_amount'=>$net/100,'discount_amount'=>0,'taxable_amount'=>$issuer->vat_registered?$net/100:0,'vat_amount'=>$vat/100,'withholding_tax'=>0,'other_charges'=>0,'total_amount'=>$gross/100,'amount_paid'=>$gross/100,'amount_due'=>0,'currency'=>'PHP','vat_registered'=>(bool)$issuer->vat_registered,'vat_rate'=>$issuer->vat_registered?12:0,'zero_rated_amount'=>0,'vat_exempt_amount'=>$issuer->vat_registered?0:$net/100,'reference_number'=>$r->reference,'seller_branch_code'=>$branch,'seller_rdo_code'=>$issuer->rdo_code,'presentation_settings'=>json_encode(app(MerchantInvoiceSettings::class)->snapshot($issuer)),'validation_hash'=>hash('sha256',json_encode([$p,$seq])),'created_at'=>$now,'updated_at'=>$now,...$authority];
        DB::table('business_invoices')->insert($invoice);
        DB::table('business_invoice_items')->insert(['id'=>(string)Str::uuid(),'invoice_id'=>$id,'merchant_id'=>$issuerId,'line_number'=>1,'description'=>'NUERS transaction service fees · '.$p['from'].' to '.$p['to'].' · '.$p['fee_count'].' posted debits','quantity'=>1,'unit_price'=>$gross/100,'discount_pct'=>0,'discount_amount'=>0,'taxable_amount'=>$issuer->vat_registered?$net/100:0,'vat_rate'=>$issuer->vat_registered?12:0,'vat_amount'=>$vat/100,'line_total'=>$gross/100,'tax_type'=>$issuer->vat_registered?'vatable':'non_vat','created_at'=>$now,'updated_at'=>$now]);
        app(EisDocumentReadiness::class)->prepare('invoice',$id);
        $p['invoice']=$invoice;$p['issuer_id']=$issuerId;$p['issued_at']=$now->toIso8601String();
        return $this->update($r,$p,['reference'=>$seq['number'],'status'=>'issued','related_source'=>'invoice','related_id'=>$id],$actor,'service_fee.issued');
    }
}
