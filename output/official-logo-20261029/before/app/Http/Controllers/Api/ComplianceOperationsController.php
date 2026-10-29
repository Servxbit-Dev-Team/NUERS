<?php
namespace App\Http\Controllers\Api;

use App\Services\{ApiAccess,BirCompliance,ComplianceOperations,DocumentPdf,ReportCsv};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComplianceOperationsController
{
    private function access(Request $r): array
    {
        $u=app(ApiAccess::class)->require($r,['merchant','admin','super_admin']);
        $role=$r->attributes->get('api_role');
        $admin=in_array($role,['admin','super_admin'],true);
        $m=$admin?null:DB::table('merchants')->where('merchant_account_id',$u->id)->first();
        abort_unless($admin||$m,403,'This workspace is managed by the business owner.');
        return [$u,$admin,$m,$role];
    }
    private function query(Request $r)
    {
        [,$admin,$m]=$this->access($r);
        $r->validate(['merchant_id'=>'nullable|uuid','kind'=>'nullable|in:outage,manual_invoice,cwt_memo,service_fee','status'=>'nullable|in:open,restored,awaiting_replacement,reconciled,draft,issued,cancelled','from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from','page'=>'nullable|integer|min:1|max:100000']);
        return DB::table('merchant_compliance_records as c')->join('merchants as m','m.id','=','c.merchant_id')->select('c.*','m.business_name','m.tin')
            ->when(!$admin,fn($q)=>$q->where('c.merchant_id',$m->id))
            ->when($admin&&$r->filled('merchant_id'),fn($q)=>$q->where('c.merchant_id',$r->merchant_id))
            ->when($r->filled('kind'),fn($q)=>$q->where('c.kind',$r->kind))
            ->when($r->filled('status'),fn($q)=>$q->where('c.status',$r->status))
            ->when($r->filled('from'),fn($q)=>$q->where('c.business_date','>=',$r->from))
            ->when($r->filled('to'),fn($q)=>$q->where('c.business_date','<=',$r->to));
    }
    public function index(Request $r)
    {
        [,$admin,$m,$role]=$this->access($r);$q=$this->query($r);
        $total=(clone $q)->count();$page=min($r->integer('page',1),max(1,(int)ceil($total/20)));
        $rows=$q->orderByDesc('c.business_date')->orderByDesc('c.created_at')->orderByDesc('c.id')->offset(($page-1)*20)->limit(20)->get()->map(fn($x)=>app(ComplianceOperations::class)->row($x));
        return response()->json(['data'=>$rows,'total'=>$total,'page'=>$page,'last_page'=>max(1,(int)ceil($total/20)),'is_admin'=>$admin,'can_bill'=>$role==='super_admin','merchant_id'=>$m?->id,
            'readiness'=>['source_intake_enforced'=>(bool)config('bir_compliance.source_capture_accreditation_enforced'),'service_issuer_configured'=>(bool)config('bir_compliance.service_invoice_issuer_id'),'official_issuance'=>'Requires verified ESP accreditation, applicable PTI and registered document series.','transmission'=>'BIR credentials, certification and production acceptance remain separate.']])->header('Cache-Control','private, no-store');
    }
    public function businesses(Request $r)
    {
        [,$admin,$m]=$this->access($r);$r->validate(['search'=>'nullable|string|max:100']);
        $q=DB::table('merchants')->when(!$admin,fn($q)=>$q->where('id',$m->id));
        if($r->filled('search'))$q->where(fn($q)=>$q->where('business_name','like','%'.$r->search.'%')->orWhere('tin','like',$r->search.'%'));
        return response()->json(['data'=>$q->orderBy('business_name')->limit(30)->get(['id','business_name','tin'])])->header('Cache-Control','private, no-store');
    }
    private function merchant(Request $r): string
    {
        [,$admin,$m]=$this->access($r);
        $r->validate(['merchant_id'=>$admin?'required|uuid':'prohibited']);
        $id=$admin?$r->merchant_id:$m->id;
        abort_unless(DB::table('merchants')->where('id',$id)->where('status','active')->exists(),422,'Select an active registered business.');
        return $id;
    }
    public function documents(Request $r)
    {
        [$u,$admin,$own]=$this->access($r);$merchant=$admin?$r->input('merchant_id'):$own->id;
        $r->validate(['merchant_id'=>$admin?'required|uuid':'nullable|uuid','source'=>'required|in:invoice,receipt','search'=>'required|string|min:2|max:100']);
        $native=$r->source==='invoice';$table=$native?'business_invoices':'transaction_receipts';$number=$native?'invoice_number':'receipt_number';
        return response()->json(['data'=>DB::table($table)->where('merchant_id',$merchant)->whereIn('status',['issued','paid','partially_paid','sent','overdue'])->where($number,'like',$r->search.'%')->orderByDesc('created_at')->limit(20)->get(['id',$number.' as number','document_type','buyer_name',($native?'total_amount':'total_due').' as amount'])])->header('Cache-Control','private, no-store');
    }
    public function store(Request $r)
    {
        [$u,,,$role]=$this->access($r);$merchant=$this->merchant($r);
        $r->validate(['kind'=>'required|in:outage,manual_invoice,cwt_memo,service_fee','request_id'=>'required|uuid']);
        abort_if($r->kind==='service_fee'&&$role!=='super_admin',403,'Platform billing requires Super Admin.');
        $record=app(ComplianceOperations::class)->create($merchant,(string)$u->id,$r->kind,$r->except('merchant_id'));
        return response()->json(['record'=>$record],201)->header('Cache-Control','private, no-store');
    }
    public function action(Request $r,string $id)
    {
        [$u,$admin,$m,$role]=$this->access($r);
        $row=DB::table('merchant_compliance_records')->where('id',$id)->when(!$admin,fn($q)=>$q->where('merchant_id',$m->id))->first();abort_unless($row,404);
        abort_unless(DB::table('merchants')->where('id',$row->merchant_id)->where('status','active')->exists(),403,'Business access is inactive.');
        abort_if($row->kind==='service_fee'&&$role!=='super_admin',403,'Platform billing requires Super Admin.');
        $r->validate(['revision'=>'required|integer|min:1','action'=>'required|in:restore,link,issue,cancel']);
        return response()->json(['record'=>app(ComplianceOperations::class)->act($row->merchant_id,$id,(string)$u->id,$r->all())])->header('Cache-Control','private, no-store');
    }
    public function export(Request $r)
    {
        $r->validate(['format'=>'required|in:pdf,csv','id'=>'nullable|uuid']);
        $q=$this->query($r)->when($r->filled('id'),fn($q)=>$q->where('c.id',$r->id));
        $count=(clone $q)->count();abort_if($count>($r->format==='pdf'?100:5000),422,'Narrow the filters. PDF supports 100 detailed records; CSV supports 5,000 records per export. No partial file was generated.');
        abort_if($r->filled('id')&&$count===0,404);
        $rows=$q->orderBy('c.business_date')->orderBy('c.id')->get()->map(fn($x)=>app(ComplianceOperations::class)->row($x));
        if($r->format==='csv')return ReportCsv::download(['Record ID','Merchant','TIN','Type','Reference','Status','Date (PHT)','Amount / adjustment (PHP)','Detail field','Detail value'],(function()use($rows){foreach($rows as $x)foreach(ReportCsv::flatten($x['details']) as [$key,$value])yield[$x['id'],$x['business_name'],$x['tin'],$x['kind'],$x['reference'],$x['status'],$x['business_date'],number_format($x['amount_centavos']/100,2,'.',''),$key,$value];})(),'NUERS-Compliance-Records.csv');
        if ($r->filled('id') && $rows->count()===1 && $rows[0]['kind']==='service_fee' && $rows[0]['status']==='issued') {
            $item=$rows[0];
            // Recipient access comes from the scoped billing record, never a caller-supplied issuer ID.
            $invoice=DB::table('business_invoices')->where('id',$item['related_id'])->where('merchant_id',$item['details']['issuer_id'])->first();
            abort_unless($invoice,409,'Issued billing document unavailable.');
            $pdf=app(DocumentPdf::class)->render(app(\App\Services\ElectronicDocumentView::class)->make('invoice',$invoice));
            return response($pdf)->header('Content-Type','application/pdf')->header('Content-Disposition','attachment; filename="NUERS-Service-Invoice.pdf"')->header('Cache-Control','private, no-store');
        }
        $logo='data:image/png;base64,'.base64_encode(file_get_contents(public_path('auth-assets/nuers-digital-logo.png')));
        $html=view('reports.compliance-operations',['rows'=>$rows,'logo'=>$logo,'filters'=>$r->only(['merchant_id','kind','status','from','to','id']),'generated'=>now('Asia/Manila')->format('Y-m-d H:i:s P'),'version'=>config('bir_compliance.solution_version')])->render();
        return response(app(DocumentPdf::class)->renderHtml($html))->header('Content-Type','application/pdf')->header('Content-Disposition','attachment; filename="NUERS-Compliance-Records.pdf"')->header('Cache-Control','private, no-store');
    }
}
