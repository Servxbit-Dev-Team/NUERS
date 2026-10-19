<?php

namespace App\Services;

use App\Http\Controllers\Api\BusinessRegistrationController;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Proposed identity changes never replace the registered record before independent approval. */
class BusinessProfileChanges
{
    public const FIELDS = ['business_name','owner_name','tin','email','business_type','sector','address','city','region','zip_code','rdo_code','vat_registered','bir_registration_date','registration_number','trade_name','representative_name','representative_position','representative_email','representative_phone'];
    public const DETAILS = ['trade_name','representative_name','representative_position','representative_email','representative_phone'];

    public function current(object $m): array
    {
        $row = DB::table('merchant_business_profiles')->where('merchant_id',$m->id)->first();
        $details = $row ? json_decode(Crypt::decryptString($row->encrypted_details),true,flags:JSON_THROW_ON_ERROR) : [];
        $reg = DB::table('business_registrations')->where('merchant_id',$m->id)->orderByDesc('created_at')->first();
        $details = array_replace(['representative_name'=>$reg->contact_name??'','representative_position'=>$reg->contact_position??'','representative_phone'=>$reg->mobile??''], $details);
        $meta = json_decode($m->registry_details??'{}',true)?:[];
        $values = [];
        foreach (self::FIELDS as $field) $values[$field] = in_array($field,self::DETAILS,true) ? ($details[$field]??'') : ($field==='registration_number' ? ($meta[$field]??'') : ($m->$field??''));
        $values['vat_registered']=(bool)$m->vat_registered;
        $values['logo_url']=$m->logo_url??null;
        return $values;
    }

    public function validate(array $changes, object $m): array
    {
        abort_if(array_diff(array_keys($changes),self::FIELDS),422,'Only registered business profile fields can be requested.');
        $rules=[];
        foreach (self::FIELDS as $field) $rules[$field]='sometimes|nullable|string|max:255';
        foreach (['business_name','owner_name','address','city','region'] as $field) $rules[$field]='sometimes|required|string|max:'.($field==='address'?1000:255);
        $rules['email']='sometimes|required|email:rfc|max:254';
        $rules['representative_email']='sometimes|nullable|email:rfc|max:254';
        $rules['representative_phone']=['sometimes','nullable','string','max:32','regex:/^\+?[0-9 ()-]{7,32}$/'];
        $rules['representative_name']='sometimes|nullable|string|max:150';
        $rules['representative_position']='sometimes|nullable|string|max:120';
        $rules['vat_registered']='sometimes|required|boolean';
        $rules['business_type']=['sometimes','required',Rule::in(array_keys(BusinessRegistrationController::BUSINESS_TYPES))];
        $rules['sector']=['sometimes','required',Rule::in(array_unique(array_merge(array_keys(app(RegistrationIndustry::class)->options()),array_keys(BusinessRegistrationController::INDUSTRIES),[$m->sector??''])))];
        $rules['rdo_code']=['sometimes','required',Rule::in(array_column(app(RegistrationAddress::class)->offices(),'rdo_code'))];
        $rules['zip_code']='sometimes|nullable|digits:4';
        $rules['bir_registration_date']='sometimes|nullable|date_format:Y-m-d|before_or_equal:today';
        if (array_key_exists('tin',$changes)) {
            abort_unless(preg_match('/^[0-9\s-]+$/',(string)$changes['tin']),422,'TIN must contain digits, spaces or hyphens only.');
            $changes['tin']=preg_replace('/\D/','',$changes['tin']);
            if(strlen($changes['tin'])===9)$changes['tin'].='000';
        }
        $rules['tin']='sometimes|required|digits:12|not_in:000000000000';
        $v=Validator::make($changes,$rules)->validate();
        foreach($v as $key=>$value) $v[$key]=$key==='vat_registered'?(bool)$value:($value===null?null:trim($value));
        foreach(['business_name','owner_name','address','city','region'] as $field) if(array_key_exists($field,$v))abort_if($v[$field]==='',422,'Registered identity fields must not be blank.');
        if(isset($v['tin']))abort_if(DB::table('merchants')->where('id','!=',$m->id)->whereRaw("REPLACE(REPLACE(tin,'-',''),' ','') = ?",[$v['tin']])->exists(),422,'This TIN and branch are already registered.');
        return $v;
    }

    public function present(object $row): array
    {
        $p=json_decode(Crypt::decryptString($row->payload),true,flags:JSON_THROW_ON_ERROR);
        return ['id'=>$row->id,'merchant_id'=>$row->merchant_id,'business_name'=>$row->business_name??null,'status'=>$p['status'],'changes'=>$p['changes'],'before'=>$p['before'],'reason'=>$p['reason'],'review_reason'=>$p['review_reason']??null,'created_at'=>$row->created_at,'reviewed_at'=>$row->completed_at];
    }

    // Caller locks the merchant row; request IDs provide safe submission retries.
    public function submit(object $m, string $actor, string $id, array $changes, string $reason, bool $image=false): array
    {
        $hash=hash('sha256',json_encode([$changes,$reason],JSON_THROW_ON_ERROR));
        $prior=DB::table('business_registry_requests')->where('id',$id)->first();
        if($prior){abort_unless($prior->purpose==='profile_change'&&$prior->merchant_id===$m->id&&$prior->actor_id===$actor&&hash_equals($prior->request_hash,$hash),409,'Request identifier already used for different changes.');return $this->present($prior);}
        abort_if(DB::table('business_registry_requests')->where('merchant_id',$m->id)->where('purpose','profile_change')->whereNull('completed_at')->exists(),409,'A profile change is already awaiting review. Withdraw it before sending another request.');
        if(!$image)$changes=$this->validate($changes,$m);
        $current=$this->current($m);$before=[];$actual=[];
        foreach($changes as $key=>$value)if((string)($current[$key]??'')!==(string)($value??'')){$before[$key]=$current[$key]??null;$actual[$key]=$value;}
        abort_unless($actual,422,'No changed profile fields were provided.');
        $payload=['status'=>'pending','changes'=>$actual,'before'=>$before,'reason'=>$reason];
        DB::table('business_registry_requests')->insert(['id'=>$id,'actor_id'=>$actor,'purpose'=>'profile_change','merchant_id'=>$m->id,'request_hash'=>$hash,'payload'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),'created_at'=>now(),'updated_at'=>now()]);
        app(BirCompliance::class)->audit('business.profile.change_requested',$m->id,$actor,['request_id'=>$id,'fields'=>array_keys($actual)]);
        return $this->present(DB::table('business_registry_requests')->where('id',$id)->first());
    }

    public function apply(object $m,array $changes,string $actor,string $reason): void
    {
        $detailsRow=DB::table('merchant_business_profiles')->where('merchant_id',$m->id)->lockForUpdate()->first();
        $details=$detailsRow?json_decode(Crypt::decryptString($detailsRow->encrypted_details),true,flags:JSON_THROW_ON_ERROR):[];
        $metadata=json_decode($m->registry_details??'{}',true)?:[];
        $direct=[];
        foreach($changes as $key=>$value){if(in_array($key,self::DETAILS,true))$details[$key]=$value;elseif($key==='registration_number')$metadata[$key]=$value;else $direct[$key]=$value;}
        if(array_key_exists('address',$direct))$direct['business_registration_address']=$direct['address'];
        if(array_intersect(array_keys($direct),['address','city','region','zip_code'])){unset($metadata['address']);$direct+=['city_code'=>null,'barangay_code'=>null,'barangay'=>null];}
        if(array_key_exists('rdo_code',$direct))$direct['rdo_name']=collect(app(RegistrationAddress::class)->offices())->firstWhere('rdo_code',$direct['rdo_code'])['rdo_name'];
        if(array_key_exists('sector',$direct))$metadata['industry']=app(RegistrationIndustry::class)->snapshot($direct['sector'],null);
        $payload=['encrypted_details'=>Crypt::encryptString(json_encode($details,JSON_THROW_ON_ERROR)),'revision'=>($detailsRow->revision??0)+1,'updated_at'=>now()];
        if($detailsRow)DB::table('merchant_business_profiles')->where('merchant_id',$m->id)->update($payload);
        else DB::table('merchant_business_profiles')->insert(['merchant_id'=>$m->id,'created_at'=>now(),...$payload]);
        DB::table('merchants')->where('id',$m->id)->update($direct+['registry_details'=>json_encode($metadata,JSON_THROW_ON_ERROR),'registry_revision'=>$m->registry_revision+1,'updated_at'=>now()]);
        if(array_intersect(array_keys($changes),['business_name','tin','vat_registered','address','rdo_code'])) DB::table('eis_integrations')->where('merchant_id',$m->id)->update(['pti_status'=>'pending','ptt_status'=>'pending','certification_status'=>'pending','updated_at'=>now()]);
        app(BirCompliance::class)->audit('business.profile.change_applied',$m->id,$actor,['fields'=>array_keys($changes),'reason'=>$reason]);
    }
}
