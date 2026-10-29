<?php
namespace App\Http\Controllers\Api;
use App\Services\{ApiAccess,SandboxTransactions};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SandboxRecordsController {
    private function query(Request $r): array {
        $access=app(ApiAccess::class);$u=$access->require($r,['merchant','admin','super_admin']);
        $scope=$r->route('registryMerchant');
        $admin=is_string($scope)||str_starts_with($r->path(),'api/admin/');
        if($admin)$access->require($r,['admin','super_admin']);
        $v=$r->validate(['sale_category'=>['nullable',\Illuminate\Validation\Rule::in(['all',...\App\Services\StatutorySales::CATEGORIES])],'search'=>'nullable|string|max:200','from'=>'nullable|date_format:Y-m-d','to'=>['nullable','date_format:Y-m-d',...($r->filled('from')?['after_or_equal:from']:[])],'page'=>'sometimes|integer|min:1|max:100000','per_page'=>'sometimes|integer|min:5|max:100','merchant'=>'nullable|string|max:80','document'=>'nullable|string|max:60','min'=>'nullable|numeric|min:0','max'=>'nullable|numeric|min:0','format'=>['nullable',\Illuminate\Validation\Rule::in(['csv','pdf'])]]);
        if(is_string($scope)){abort_unless(DB::table('merchants')->where('id',$scope)->exists(),404);abort_if($r->filled('merchant')&&$r->query('merchant')!==$scope,422,'The merchant scope is fixed by the selected business.');$v['merchant']=$scope;}
        $q=DB::table('integration_sandbox_events as s')->join('merchants as m','m.id','=','s.merchant_id')->select(['s.id','s.details_encrypted','s.merchant_id','s.environment','s.branch_code','s.source_system','s.source_event_id','s.external_reference','s.receipt_number','s.document_type','s.event_type','s.gross_centavos','s.sale_category','s.statutory_discount_amount','s.vat_relief_amount','s.occurred_at','s.created_at','m.business_name','m.tin']);
        if(!$admin){$m=$access->merchants($u)->first();abort_unless($m,404);$q->where('s.merchant_id',$m->id);}elseif(!empty($v['merchant']))$q->where('s.merchant_id',$v['merchant']);
        if(($v['sale_category']??'all')!=='all')$q->where('s.sale_category',$v['sale_category']);
        if(!empty($v['search'])){$like='%'.$v['search'].'%';$q->where(fn($x)=>$x->where('s.external_reference','like',$like)->orWhere('s.receipt_number','like',$like)->orWhere('s.source_system','like',$like)->orWhere('m.business_name','like',$like));}
        if(!empty($v['document']))$q->where('s.document_type',$v['document']);
        if(isset($v['min'],$v['max']))abort_if($v['max']<$v['min'],422,'Maximum amount must be at least the minimum amount.');
        if(isset($v['min']))$q->where('s.gross_centavos','>=',(int)round($v['min']*100));
        if(isset($v['max']))$q->where('s.gross_centavos','<=',(int)round($v['max']*100));
        if(!empty($v['from']))$q->where('s.created_at','>=',\Carbon\CarbonImmutable::parse($v['from'],'Asia/Manila')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        if(!empty($v['to']))$q->where('s.created_at','<',\Carbon\CarbonImmutable::parse($v['to'],'Asia/Manila')->addDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        return [$q,$v];
    }
    private function record(object $row):object { $calculation=app(SandboxTransactions::class)->details($row)['response']['calculation'] ?? []; $row->discount_amount=(float)($calculation['discount_amount'] ?? 0); $row->manual_discount_amount=max(0, round($row->discount_amount-(float)$row->statutory_discount_amount,2)); unset($row->details_encrypted); foreach(['created_at','occurred_at'] as $field) $row->$field=\Carbon\Carbon::parse($row->$field,config('app.timezone'))->toIso8601String(); return $row; }
    public function index(Request $r){[$q,$v]=$this->query($r);$summary=['total'=>(clone$q)->count(),'gross_centavos'=>(int)(clone$q)->sum('s.gross_centavos')];$p=$q->orderByDesc('s.created_at')->orderByDesc('s.id')->paginate($v['per_page']??10);return response()->json(['environment'=>'sandbox','test_only'=>true,'data'=>array_map(fn($x)=>$this->record($x),$p->items()),'total'=>$p->total(),'page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'summary'=>$summary])->header('Cache-Control','private, no-store');}
    public function merchantShow(Request $r,string $registryMerchant,string $id){return $this->show($r,$id);}
    public function show(Request $r,string $id){[$q]=$this->query($r);$row=$q->where('s.id',$id)->first();abort_unless($row,404);$raw=DB::table('integration_sandbox_events')->where('id',$row->id)->first();return response()->json(['record'=>$this->record($row),...app(SandboxTransactions::class)->details($raw)])->header('Cache-Control','private, no-store');}
    public function export(Request $r) {
        $actor=app(ApiAccess::class)->require($r,['admin','super_admin']);
        [$q,$v]=$this->query($r);
        $q->orderByDesc('s.created_at')->orderByDesc('s.id');
        return app(\App\Services\TransactionReportExport::class)->download($q,$v,$actor,'sandbox',function($source){
            $details=app(SandboxTransactions::class)->details($source);
            $tax=$details['response']['calculation']??[];$request=$details['request']??[];
            return [
                'id'=>$source->id,'intake_environment'=>'sandbox','merchant_id'=>$source->merchant_id,'business'=>$source->business_name,'tin'=>$source->tin,
                'transaction_ref'=>$source->external_reference,'receipt_number'=>$source->receipt_number,'business_date'=>$source->occurred_at,
                'recorded_at'=>\Carbon\Carbon::parse($source->created_at,config('app.timezone'))->toIso8601String(),
                'document_source'=>'sandbox','document_id'=>$source->id,'document_type'=>$source->document_type,'channel'=>$source->source_system,
                'status'=>'success','record_status'=>'accepted_test','branch_code'=>$source->branch_code,'sale_category'=>$source->sale_category,
                'customer_name'=>$request['customer_name']??null,'customer_tin'=>$request['customer_tin']??null,
                'amount'=>$source->gross_centavos/100,'vat_amount'=>$tax['vat_amount']??0,'vatable_sales'=>$tax['vatable_sales']??0,
                'vat_exempt_sales'=>$tax['vat_exempt_sales']??0,'zero_rated_sales'=>$tax['zero_rated_sales']??0,'sspt_sales'=>$tax['sspt_sales']??0,'net_amount'=>$tax['net_amount']??0,
                'discount_amount'=>$tax['discount_amount']??0,'manual_discount_amount'=>max(0,round(($tax['discount_amount']??0)-$source->statutory_discount_amount,2)),
                'statutory_discount_amount'=>$source->statutory_discount_amount,'vat_relief_amount'=>$source->vat_relief_amount,
                'source_record_count'=>1,'bir_status'=>'not_applicable','payment_count'=>0,'payment_amount'=>0,
            ];
        });
    }
}
