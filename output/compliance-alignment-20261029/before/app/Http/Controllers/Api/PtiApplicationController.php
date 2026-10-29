<?php

namespace App\Http\Controllers\Api;

use App\Services\{ApiAccess,DocumentPdf,PtiApplications,PrivateDocumentStream};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB,Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PtiApplicationController
{
    private function access(Request $r): array
    {
        $u=app(ApiAccess::class)->require($r,['merchant','admin','super_admin']);
        $admin=in_array($r->attributes->get('api_role'),['admin','super_admin'],true);
        $m=$admin?null:DB::table('merchants')->where('merchant_account_id',$u->id)->orderBy('id')->first();
        abort_unless($admin||$m,403,'PTI applications are managed by the registered business owner.');
        return [$u,$admin,$m];
    }

    private function query(Request $r)
    {
        [, $admin,$m]=$this->access($r);
        return DB::table('pti_applications')->when(!$admin,fn($q)=>$q->where('merchant_id',$m->id));
    }

    private function row(Request $r,string $id,bool $lock=false): object
    {
        $row=$this->query($r)->where('id',$id)->when($lock,fn($q)=>$q->lockForUpdate())->first();
        abort_unless($row,404);
        if($lock){
            $r->validate(['revision'=>'required|integer|min:1']);
            abort_unless((int)$row->revision===$r->integer('revision'),409,'Application changed. Refresh it before saving.');
            $m=DB::table('merchants')->where('id',$row->merchant_id)->first();
            abort_unless($m&&!in_array($m->status,['suspended','inactive']),403,'Business access is suspended or inactive.');
        }
        return $row;
    }

    private function result($data,int $status=200)
    {
        return response()->json($data,$status)->header('Cache-Control','private, no-store');
    }

    public function businesses(Request $r)
    {
        [, $admin,$m]=$this->access($r);
        $r->validate(['search'=>'nullable|string|max:120']);
        $q=DB::table('merchants')->when(!$admin,fn($q)=>$q->where('id',$m->id));
        if($r->filled('search'))$q->where(fn($q)=>$q->where('business_name','like','%'.$r->search.'%')->orWhere('tin','like','%'.$r->search.'%'));
        return $this->result(['data'=>$q->orderBy('business_name')->limit(30)->get(['id','business_name','tin','rdo_code','address','owner_name','email','phone','status'])]);
    }

    public function index(Request $r)
    {
        [, $admin,$m]=$this->access($r);
        $r->validate(['page'=>'nullable|integer|min:1|max:100000','status'=>['nullable',Rule::in(['all',...array_keys(PtiApplications::STATUSES)])],'search'=>'nullable|string|max:120']);
        $q=$this->query($r)->join('merchants as m','m.id','=','pti_applications.merchant_id');
        if($r->filled('status')&&$r->status!=='all')$q->where('pti_applications.status',$r->status);
        if($r->filled('search'))$q->where(fn($q)=>$q->where('m.business_name','like','%'.$r->search.'%')->orWhere('m.tin','like','%'.$r->search.'%')->orWhere('pti_applications.id','like',Str::after($r->search,'PTI-APP-').'%'));
        $total=(clone $q)->count();$page=min($r->integer('page',1),max(1,(int)ceil($total/15)));
        $rows=$q->select('pti_applications.*','m.business_name','m.tin')->orderByDesc('pti_applications.created_at')->orderByDesc('pti_applications.id')->offset(($page-1)*15)->limit(15)->get()->map(fn($row)=>collect(app(PtiApplications::class)->record($row))->except('payload')->all()+['business_name'=>$row->business_name,'tin'=>$row->tin]);
        $summary=$this->query($r)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total','status');
        return $this->result(['data'=>$rows,'total'=>$total,'page'=>$page,'last_page'=>max(1,(int)ceil($total/15)),'summary'=>$summary,'is_admin'=>$admin,'merchant'=>$m?app(PtiApplications::class)->identity($m)+['id'=>$m->id]:null,'catalog'=>app(PtiApplications::class)->catalog()]);
    }

    private function form(Request $r): array
    {
        return $r->validate(['application_type'=>'required|in:new,amendment','previous_reference'=>'nullable|required_if:application_type,amendment|string|max:120','system_name'=>'required|string|max:120','system_version'=>'required|string|max:80','system_provider'=>'required|string|max:160','system_type'=>'required|in:nuers,erp,pos,ecommerce,other','scope'=>'required|string|min:10|max:1500','branches'=>'required|array|min:1|max:50','branches.*'=>'required|distinct|regex:/^[0-9]{5}$/','representative'=>'required|string|max:160','contact_email'=>'required|email:rfc|max:254','contact_phone'=>'required|string|max:40','office'=>'required|string|max:160','notes'=>'nullable|string|max:3000']);
    }

    public function store(Request $r)
    {
        [$u,$admin,$own]=$this->access($r);
        $r->validate(['request_id'=>'required|uuid','merchant_id'=>$admin?'required|uuid':'prohibited']);
        $v=$this->form($r);$merchant=$admin?$r->merchant_id:$own->id;
        return DB::transaction(function()use($r,$u,$v,$merchant){
            $m=DB::table('merchants')->where('id',$merchant)->lockForUpdate()->first();
            abort_unless($m&&!in_array($m->status,['suspended','inactive']),422,'Select an available registered business.');
            $hash=hash('sha256',json_encode([$merchant,$v]));
            $old=DB::table('pti_applications')->where('request_id',$r->request_id)->first();
            if($old){abort_unless($old->merchant_id===$merchant&&(string)$old->created_by===(string)$u->id&&hash_equals($old->request_hash,$hash),409,'This request identifier is already used.');return $this->result(['record'=>app(PtiApplications::class)->record($old),'duplicate'=>true]);}
            abort_if(DB::table('pti_applications')->where('merchant_id',$merchant)->whereIn('status',['draft','in_review','needs_changes','ready_to_file','filed'])->count()>=20,422,'There are 20 open applications. Complete or withdraw an existing application first.');
            $id=(string)Str::uuid();
            DB::table('pti_applications')->insert(['id'=>$id,'request_id'=>$r->request_id,'request_hash'=>$hash,'merchant_id'=>$merchant,'created_by'=>$u->id,'payload_encrypted'=>Crypt::encryptString(json_encode(['identity'=>app(PtiApplications::class)->identity($m),'form'=>$v],JSON_THROW_ON_ERROR)),'created_at'=>now(),'updated_at'=>now()]);
            $row=DB::table('pti_applications')->find($id);app(PtiApplications::class)->event($row,(string)$u->id,'created',['note'=>'Application draft prepared in NUERS.']);
            return $this->result(['record'=>app(PtiApplications::class)->record($row)],201);
        });
    }

    public function show(Request $r,string $id)
    {
        $row=$this->row($r,$id);$service=app(PtiApplications::class);
        return $this->result(['record'=>$service->record($row),'documents'=>$service->documents($id)->map(fn($d)=>$service->presentDocument($d)),
            'history'=>DB::table('pti_application_events')->where('application_id',$id)->orderByDesc('id')->limit(100)->get()->map(fn($e)=>['event'=>$e->event,'revision'=>$e->revision,'created_at'=>$e->created_at,'details'=>json_decode(Crypt::decryptString($e->details_encrypted),true)]),'history_limit'=>100]);
    }

    private function change(object $row,string $actor,string $event,array $data,array $details): object
    {
        DB::table('pti_applications')->where('id',$row->id)->update($data+['revision'=>$row->revision+1,'updated_at'=>now()]);
        $row=DB::table('pti_applications')->find($row->id);app(PtiApplications::class)->event($row,$actor,$event,$details);return $row;
    }

    public function update(Request $r,string $id)
    {
        [$u]=$this->access($r);$v=$this->form($r);
        return DB::transaction(function()use($r,$id,$u,$v){
            $row=$this->row($r,$id,true);abort_unless(in_array($row->status,['draft','needs_changes']),409,'Application details are locked during review and after filing.');
            $p=app(PtiApplications::class)->payload($row);$before=$p;$p['form']=$v;
            $p['identity']=app(PtiApplications::class)->identity(DB::table('merchants')->find($row->merchant_id));
            $row=$this->change($row,(string)$u->id,'updated',['payload_encrypted'=>Crypt::encryptString(json_encode($p,JSON_THROW_ON_ERROR))],['note'=>'Draft details saved; registered identity refreshed.','before'=>$before]);
            return $this->result(['record'=>app(PtiApplications::class)->record($row)]);
        });
    }

    public function upload(Request $r,string $id)
    {
        [$u,$admin]=$this->access($r);
        $v=$r->validate(['kind'=>['required',Rule::in(array_keys(PtiApplications::DOCUMENTS))],'file'=>'required|file|min:1|max:3072|mimes:pdf,png,jpg,jpeg']);
        return DB::transaction(function()use($r,$id,$u,$admin,$v){
            $row=$this->row($r,$id,true);
            abort_unless(in_array($row->status,['draft','needs_changes'])||($admin&&in_array($row->status,['in_review','ready_to_file','filed'])),409,'Attachments are locked at this stage.');
            abort_unless($admin||!in_array($v['kind'],['bir_receipt','bir_permit','bir_decision']),403,'Official filing evidence is recorded by an administrator.');
            $docs=app(PtiApplications::class)->documents($id);$file=$r->file('file');$sha=hash_file('sha256',$file->getRealPath());
            abort_if($docs->count()>=25||$docs->sum('bytes')+$file->getSize()>30*1024*1024,422,'Application attachment limit: 25 files / 30 MB. Contact an administrator for additional records.');
            $docId=(string)Str::uuid();$path='pti-applications/'.$id.'/'.$docId.'.enc';
            app(PrivateDocumentStream::class)->store($file->getRealPath(),$path);
            try{
                DB::table('pti_application_documents')->insert(['id'=>$docId,'application_id'=>$id,'kind'=>$v['kind'],'filename_encrypted'=>Crypt::encryptString(basename($file->getClientOriginalName())),'private_path'=>$path,'mime'=>$file->getMimeType(),'bytes'=>$file->getSize(),'sha256'=>$sha,'uploaded_by'=>$u->id,'created_at'=>now()]);
                $row=$this->change($row,(string)$u->id,'document_added',[],['note'=>PtiApplications::DOCUMENTS[$v['kind']].' uploaded.','document_id'=>$docId,'sha256'=>$sha]);
            }catch(\Throwable $e){Storage::disk('local')->delete($path);throw $e;}
            return $this->result(['record'=>app(PtiApplications::class)->record($row)],201);
        });
    }

    private function proof(object $row,string $id,string $kind): object
    {
        $d=DB::table('pti_application_documents')->where('id',$id)->where('application_id',$row->id)->where('kind',$kind)->first();
        abort_unless($d,422,'Attach and select the matching BIR evidence document for this application.');
        fclose(app(PrivateDocumentStream::class)->verified($d));return $d;
    }

    public function transition(Request $r,string $id)
    {
        [$u,$admin]=$this->access($r);
        $v=$r->validate(['status'=>['required',Rule::in(array_keys(PtiApplications::STATUSES))],'note'=>'required|string|min:10|max:2000','confirmed'=>'accepted','evidence_id'=>'nullable|uuid','filing_reference'=>'nullable|string|max:160','filed_on'=>'nullable|date_format:Y-m-d|before_or_equal:today','receiving_office'=>'nullable|string|max:160','permit_reference'=>'nullable|string|max:120','issued_at'=>'nullable|date_format:Y-m-d|before_or_equal:today','valid_until'=>'nullable|date_format:Y-m-d|after_or_equal:issued_at','branch_code'=>'nullable|regex:/^[0-9]{5}$/']);
        return DB::transaction(function()use($r,$id,$u,$admin,$v){
            // Same lock order as the permit register when an issued permit is recorded.
            DB::table('business_registry_lock')->where('id',1)->lockForUpdate()->first();
            $row=$this->row($r,$id,true);$next=$v['status'];
            $allowed=['draft'=>['in_review','withdrawn'],'needs_changes'=>['in_review','withdrawn'],'in_review'=>['needs_changes','ready_to_file','withdrawn'],'ready_to_file'=>['needs_changes','filed','withdrawn'],'filed'=>['issued','declined']];
            abort_unless(in_array($next,$allowed[$row->status]??[]),409,'This status transition is not available.');
            abort_unless($admin||in_array($next,['in_review','withdrawn']),403,'An administrator must record this review or BIR outcome.');
            $p=app(PtiApplications::class)->payload($row);$data=['status'=>$next];
            if(in_array($next,['in_review','ready_to_file'])){
                foreach(PtiApplications::REVIEW_DOCUMENTS as $kind){$d=app(PtiApplications::class)->documents($id)->firstWhere('kind',$kind);abort_unless($d,422,'NUERS preparation checklist: attach '.PtiApplications::DOCUMENTS[$kind].'.');fclose(app(PrivateDocumentStream::class)->verified($d));}
                $current=app(PtiApplications::class)->identity(DB::table('merchants')->find($row->merchant_id));
                abort_unless($current===$p['identity'],409,'Registered identity changed. Update the draft before submitting, or request changes from review.');
                if($next==='in_review')$data['submitted_at']=now();
            }
            if($next==='filed'){
                abort_unless(!empty($v['filing_reference'])&&!empty($v['filed_on'])&&!empty($v['receiving_office']),422,'Record the BIR receiving reference, filing date and receiving RDO / LT Office.');
                $this->proof($row,$v['evidence_id']??'','bir_receipt');
                $p['filing']=collect($v)->only(['filing_reference','filed_on','receiving_office','evidence_id'])->all()+['recorded_by'=>(string)$u->id,'source'=>'Administrator-recorded receiving evidence; no direct BIR API submission'];
                $data['payload_encrypted']=Crypt::encryptString(json_encode($p,JSON_THROW_ON_ERROR));
            }
            if($next==='declined')$this->proof($row,$v['evidence_id']??'','bir_decision');
            if($next==='issued'){
                $this->proof($row,$v['evidence_id']??'','bir_permit');
                abort_unless(!empty($v['permit_reference'])&&!empty($v['issued_at'])&&in_array($v['branch_code']??'', $p['form']['branches']),422,'Record the issued permit reference, issue date and a covered branch code.');
                abort_if($v['issued_at']<($p['filing']['filed_on']??'0000-00-00'),422,'Permit issue date cannot precede the recorded application filing date.');
                $permitData=['request_id'=>(string)Str::uuid(),'merchant_id'=>$row->merchant_id,'kind'=>'PTI','environment'=>'production','branch_code'=>$v['branch_code'],'reference'=>$v['permit_reference'],'scope'=>$p['form']['system_name'].' '.$p['form']['system_version'].' — '.$p['form']['scope'],'issued_at'=>$v['issued_at'],'valid_until'=>$v['valid_until']??null,'status'=>'active','evidence'=>'PTI application '.$id.'; BIR permit document '.$v['evidence_id'],'reason'=>$v['note']];
                $permit=new Request([], $permitData);
                $permit->setMethod('POST');
                $permit->headers->replace($r->headers->all());
                $permit->setJson(new \Symfony\Component\HttpFoundation\InputBag($permitData));
                $response=app(TaxpayerAuthorizationController::class)->store($permit);
                $data['authorization_id']=$response->getData(true)['record']['id'];
                $p['permit']=collect($v)->only(['permit_reference','issued_at','valid_until','branch_code','evidence_id'])->all();
                $data['payload_encrypted']=Crypt::encryptString(json_encode($p,JSON_THROW_ON_ERROR));
            }
            $row=$this->change($row,(string)$u->id,$next,$data,['note'=>$v['note'],'previous_status'=>$row->status,'evidence_id'=>$v['evidence_id']??null]);
            return $this->result(['record'=>app(PtiApplications::class)->record($row)]);
        });
    }

    public function download(Request $r,string $id,string $document)
    {
        [$u]=$this->access($r);$row=$this->row($r,$id);
        $d=DB::table('pti_application_documents')->where('application_id',$id)->where('id',$document)->first();abort_unless($d,404);
        $stream=app(PrivateDocumentStream::class)->verified($d);
        app(\App\Services\BirCompliance::class)->audit('pti.application.document_downloaded',$row->id,(string)$u->id,['document_id'=>$d->id]);
        return response()->streamDownload(function()use($stream){try{fpassthru($stream);}finally{fclose($stream);}},'NUERS-PTI-'.$d->kind.'-'.$d->id.($d->mime==='application/pdf'?'.pdf':($d->mime==='image/png'?'.png':'.jpg')),['Content-Type'=>$d->mime,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function packet(Request $r,string $id)
    {
        [$u]=$this->access($r);$row=$this->row($r,$id);$service=app(PtiApplications::class);
        $r->validate(['format'=>'nullable|in:pdf,zip']);
        $documents=$service->documents($id);
        $pdf=app(DocumentPdf::class)->renderHtml(view('reports.pti-application',['record'=>$service->record($row),'documents'=>$documents->map(fn($d)=>$service->presentDocument($d)),'catalog'=>$service->catalog()])->render());
        app(\App\Services\BirCompliance::class)->audit('pti.application.packet_exported',$id,(string)$u->id,['revision'=>$row->revision,'format'=>$r->format??'pdf']);
        if($r->format!=='zip')return response($pdf)->withHeaders(['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="NUERS-PTI-Application-'.$id.'.pdf"','Cache-Control'=>'private, no-store']);
        $path=tempnam(storage_path('app/private'),'pti-packet-');chmod($path,0600);
        $zip=new \ZipArchive();
        try{
            abort_unless($zip->open($path,\ZipArchive::OVERWRITE)===true,503,'Application package could not be created.');
            $zip->addFromString('NUERS-Application-Summary.pdf',$pdf);
            foreach($documents as $d){$s=app(PrivateDocumentStream::class)->verified($d);try{$zip->addFromString('attachments/'.$d->kind.'-'.$d->id.($d->mime==='application/pdf'?'.pdf':($d->mime==='image/png'?'.png':'.jpg')),stream_get_contents($s));}finally{fclose($s);}}
            $zip->addFromString('README.txt',"NUERS PTI preparation packet. Not an official BIR form or permit.\nSubmit through your registered RDO/LT Office's prescribed channel.\nNo data has been transmitted to BIR by this download.\nOriginal filenames and SHA-256 checksums are in the summary PDF.\n");
            $zip->close();
            return response()->download($path,'NUERS-PTI-Packet-'.$id.'.zip',['Cache-Control'=>'private, no-store'])->deleteFileAfterSend(true);
        }catch(\Throwable $e){if(is_file($path))unlink($path);throw $e;}
    }
}
