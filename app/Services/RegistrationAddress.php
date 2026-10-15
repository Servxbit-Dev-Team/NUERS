<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RegistrationAddress
{
    private static ?array $geoCache=null;
    private array $geo;
    private array $reference;
    public function __construct()
    {
        $this->geo=self::$geoCache??=json_decode(file_get_contents(resource_path('data/philippine-addresses.json')),true,512,JSON_THROW_ON_ERROR);
        $this->reference=json_decode(file_get_contents(resource_path('data/registration-rdos.json')),true,512,JSON_THROW_ON_ERROR);
    }
    public function geography(): array { return $this->geo; }
    public function locations(string $level,?string $parent): array
    {
        return collect($this->geo[$level])->filter(fn($x)=>($level==='regions'||$x['parent']===$parent)&&!($level==='cities'&&($x['is_district']??false)))->sortBy('name',SORT_NATURAL|SORT_FLAG_CASE)->values()->all();
    }
    public function offices(): array
    {
        $offices=$this->reference['offices'];
        // Managed office labels override the directory; inactive offices cannot be selected.
        foreach(DB::table('rdo_offices')->get(['rdo_code','rdo_name','status']) as $row){
            if($row->status==='active' && isset($offices[$row->rdo_code]))$offices[$row->rdo_code]=['rdo_code'=>$row->rdo_code,'rdo_name'=>$row->rdo_name];
            else unset($offices[$row->rdo_code]);
        }
        ksort($offices,SORT_NATURAL);return array_values($offices);
    }
    public function resolve(string $cityCode,?string $barangayCode=null): array
    {
        $city=$this->geo['cities'][$cityCode]??null;
        if(!$city)throw ValidationException::withMessages(['city_code'=>'Select a valid city or municipality.']);
        $barangay=$barangayCode?($this->geo['barangays'][$barangayCode]??null):null;
        if($barangayCode&&(!$barangay||$barangay['parent']!==$cityCode))throw ValidationException::withMessages(['barangay_code'=>'This barangay does not belong to the selected city.']);
        if (Schema::hasTable('registration_rdo_mappings')) {
            $specific=$barangayCode?DB::table('registration_rdo_mappings')->where('scope','barangay:'.$barangayCode)->first():null;
            $managedCity=DB::table('registration_rdo_mappings')->where('scope','city:'.$cityCode)->first();
            $managed=$specific??(!isset($this->reference['barangay_rules'][$barangayCode??''])?$managedCity:null);
            if($managed){$office=$managed->active?collect($this->offices())->firstWhere('rdo_code',$managed->rdo_code):null;return ['rdo'=>$office,'automatic'=>(bool)$office,'message'=>$office?'RDO selected from maintained city / barangay jurisdiction mapping. Confirm it matches your BIR registration.':'This jurisdiction rule requires manual RDO confirmation.'];}
        }
        $code=$this->reference['barangay_rules'][$barangayCode??'']??$this->reference['city_rules'][$cityCode]??null;
        // Explicit PSGC rules maintained in the existing RDO coverage editor are also supported.
        $matches=[];
        foreach(DB::table('rdo_offices')->where('status','active')->get(['rdo_code','coverage_area']) as $office){
            $coverage=json_decode($office->coverage_area??'[]',true)?:[];
            $specific=$barangayCode&&in_array('psgc:barangay:'.$barangayCode,$coverage,true);
            if($specific)$matches[2][]=$office->rdo_code;
            elseif(in_array('psgc:city:'.$cityCode,$coverage,true))$matches[1][]=$office->rdo_code;
        }
        if($matches&&(!isset($this->reference['barangay_rules'][$barangayCode??''])||isset($matches[2]))){krsort($matches);$codes=array_unique(reset($matches));$code=count($codes)===1?reset($codes):null;}
        $offices=$this->offices();$office=collect($offices)->firstWhere('rdo_code',$code);
        return ['rdo'=>$office,'automatic'=>(bool)$office,'message'=>$office?'RDO selected from your business address. Confirm it matches your BIR registration.':($barangayCode?'Confirm your RDO from your BIR registration. This address needs a jurisdiction check.':'Select your barangay to check the RDO jurisdiction.')];
    }
    public function validate(array $v): array
    {
        $region=$this->geo['regions'][$v['region_code']]??null;
        $province=$this->geo['provinces'][$v['province_code']]??null;
        $city=$this->geo['cities'][$v['city_code']]??null;
        $barangay=$this->geo['barangays'][$v['barangay_code']]??null;
        if(!$region||!$province||!$city||!$barangay||$province['parent']!==$region['code']||$city['parent']!==$province['code']||$barangay['parent']!==$city['code'])throw ValidationException::withMessages(['address'=>'Select a matching region, province/area, city and barangay.']);
        $resolution=$this->resolve($city['code'],$barangay['code']);
        if($resolution['automatic']&&$v['rdo_code']!==$resolution['rdo']['rdo_code'])throw ValidationException::withMessages(['rdo_code'=>'The RDO does not match this address. Check your location selections.']);
        $details=['region'=>$region,'province'=>$province,'city'=>$city,'barangay'=>$barangay,'street_address'=>$v['street_address'],'zip_code'=>$v['zip_code'],'rdo_automatic'=>$resolution['automatic']];
        $parts=[$v['street_address'],$barangay['name'],$city['name']];
        if(!($province['is_group']??false))$parts[]=$province['name'];
        $parts[]=$region['name'];$parts[]=$v['zip_code'];$parts[]='Philippines';
        return ['address'=>implode(', ',$parts),'details'=>$details];
    }
}
