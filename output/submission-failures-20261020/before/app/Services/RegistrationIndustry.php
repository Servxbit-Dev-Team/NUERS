<?php
namespace App\Services;
class RegistrationIndustry
{
    public function catalog(): array { return json_decode(file_get_contents(resource_path('data/registration-industries.json')),true,512,JSON_THROW_ON_ERROR); }
    public function options(): array { $labels=[];foreach($this->catalog()['industries'] as $key=>$item)$labels[$key]=$item['code'].' — '.$item['name'];$labels['other']='Other business activity — specify';return $labels; }
    public function snapshot(string $key,?string $custom): array { return $key==='other'?['code'=>null,'name'=>$custom,'version'=>'User-described activity']:($this->catalog()['industries'][$key]??['code'=>$key,'name'=>$key,'version'=>'Legacy selection']); }
}
