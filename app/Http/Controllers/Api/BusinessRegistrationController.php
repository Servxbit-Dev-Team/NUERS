<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationCodeMail;
use App\Models\Merchant;
use App\Models\Profile;
use App\Models\User;
use App\Services\BirCompliance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class BusinessRegistrationController extends Controller
{
    public const NOTICE_VERSION = '2026-10-15.2';
    public const BUSINESS_TYPES = ['sole_proprietorship'=>'Sole Proprietorship','partnership'=>'Partnership','corporation'=>'Corporation','one_person_corporation'=>'One Person Corporation','cooperative'=>'Cooperative','non_stock_non_profit'=>'Non-stock / Non-profit Organization','government'=>'Government Entity','other'=>'Other'];
    public const INDUSTRIES = ['retail'=>'Retail & Wholesale Trade','services'=>'Professional & Business Services','it'=>'Information Technology','manufacturing'=>'Manufacturing','food'=>'Food & Hospitality','construction'=>'Construction & Real Estate','transport'=>'Transportation & Logistics','healthcare'=>'Healthcare','education'=>'Education','finance'=>'Financial Services','agriculture'=>'Agriculture & Fisheries','utilities'=>'Utilities & Telecommunications','other'=>'Other'];

    public function options()
    {
        return response()->json(['business_types'=>self::BUSINESS_TYPES,'industries'=>app(\App\Services\RegistrationIndustry::class)->options(),'rdos'=>app(\App\Services\RegistrationAddress::class)->offices(),'notice_version'=>self::NOTICE_VERSION]);
    }
    public function locations(Request $request)
    {
        $v=$request->validate(['level'=>['required',Rule::in(['regions','provinces','cities','barangays'])],'parent'=>'nullable|digits:10']);
        return response()->json(['data'=>app(\App\Services\RegistrationAddress::class)->locations($v['level'],$v['parent']??null)]);
    }
    public function resolveRdo(Request $request)
    {
        $v=$request->validate(['city_code'=>'required|digits:10','barangay_code'=>'nullable|digits:10']);
        return response()->json(app(\App\Services\RegistrationAddress::class)->resolve($v['city_code'],$v['barangay_code']??null));
    }
    public function sendCode(Request $request)
    {
        $request->merge(['email'=>strtolower(trim((string)$request->input('email')))]);
        $v=$request->validate(['email'=>'required|email|max:255']);
        $key='registration-email:'.hash('sha256',$v['email']);
        abort_if(RateLimiter::tooManyAttempts($key,1),429,'Please wait one minute before requesting another code.');
        $development=app()->environment('local') && config('mail.default')==='log';
        abort_if(!$development && in_array(config('mail.default'),['log','array'],true),503,'Email verification delivery is not configured. Please contact NUERS support.');
        RateLimiter::hit($key,60);
        $code=(string)random_int(100000,999999);$id=(string)Str::uuid();
        DB::table('registration_email_verifications')->insert(['id'=>$id,'email'=>$v['email'],'code_hash'=>Hash::make($code),'attempts'=>0,'expires_at'=>now()->addMinutes(10),'development_delivery'=>$development,'created_at'=>now(),'updated_at'=>now()]);
        try { Mail::to($v['email'])->send(new RegistrationCodeMail($code)); }
        catch (\Throwable $e) { DB::table('registration_email_verifications')->where('id',$id)->delete(); report($e); abort(503,'The verification email could not be sent. Please try again later.'); }
        return response()->json(['challenge_id'=>$id,'expires_in'=>600,'development_delivery'=>$development,...($development?['development_code'=>$code]:[])]);
    }
    public function verifyCode(Request $request)
    {
        $v=$request->validate(['challenge_id'=>'required|uuid','code'=>'required|digits:6']);
        $result=DB::transaction(function()use($v){
            $row=DB::table('registration_email_verifications')->where('id',$v['challenge_id'])->lockForUpdate()->first();
            if(!$row || $row->consumed_at || Carbon::parse($row->expires_at)->isPast() || $row->attempts>=5) return null;
            DB::table('registration_email_verifications')->where('id',$row->id)->increment('attempts');
            if(!Hash::check($v['code'],$row->code_hash)) return null;
            $proof=Str::random(64);
            DB::table('registration_email_verifications')->where('id',$row->id)->update(['verified_at'=>now(),'proof_hash'=>hash('sha256',$proof),'updated_at'=>now()]);
            return ['verification_token'=>$proof,'email'=>$row->email,'development_delivery'=>(bool)$row->development_delivery];
        });
        if(!$result) throw ValidationException::withMessages(['code'=>'The code is invalid, expired, or has reached its attempt limit. Request a new code.']);
        return response()->json($result);
    }
    public function store(Request $request)
    {
        $request->merge(['email'=>strtolower(trim((string)$request->input('email'))),'tin'=>preg_replace('/\D/','',(string)$request->input('tin'))]);
        $v=$request->validate([
            'email'=>['required','email','max:255',Rule::unique('users','email')],
            'password'=>['required','string','max:72','confirmed',Password::min(12)->letters()->numbers()],
            'tin'=>'required|digits:12|unique:business_registrations,business_tin',
            'business_name'=>'required|string|max:255','street_address'=>'required|string|max:500','zip_code'=>'required|digits:4',
            'region_code'=>'required|digits:10','province_code'=>'required|digits:10','city_code'=>'required|digits:10','barangay_code'=>'required|digits:10',
            'business_type'=>['required',Rule::in(array_keys(self::BUSINESS_TYPES))], 'industry'=>['required',Rule::in(array_merge(array_keys(self::INDUSTRIES),array_keys(app(\App\Services\RegistrationIndustry::class)->options())))],
            'industry_other'=>'required_if:industry,other|nullable|string|max:255',
            'rdo_code'=>['required',Rule::in(array_column(app(\App\Services\RegistrationAddress::class)->offices(),'rdo_code'))],
            'contact_name'=>'required|string|max:255','contact_position'=>'required|string|max:120','mobile'=>['required','regex:/^\+639\d{9}$/'],
            'vat_registered'=>'required|boolean','subscription_plan_id'=>['nullable',Rule::exists('website_subscription_plans','id')->where('published',true)],
            'terms_accepted'=>'required|accepted','privacy_accepted'=>'required|accepted','notice_version'=>['required',Rule::in([self::NOTICE_VERSION])],
            'challenge_id'=>'required|uuid','verification_token'=>'required|string|size:64', 'role'=>'prohibited','status'=>'prohibited',
        ]);
        $address=app(\App\Services\RegistrationAddress::class)->validate($v);
        $v['address']=$address['address'];$v['address_details']=$address['details'];
        return DB::transaction(function()use($v){
            if(\Illuminate\Support\Facades\Schema::hasTable('business_registry_lock'))DB::table('business_registry_lock')->where('id',1)->lockForUpdate()->first();
            $verification=DB::table('registration_email_verifications')->where('id',$v['challenge_id'])->lockForUpdate()->first();
            abort_unless($verification && $verification->email===$v['email'] && $verification->verified_at && !$verification->consumed_at && !Carbon::parse($verification->expires_at)->isPast() && hash_equals($verification->proof_hash??'',hash('sha256',$v['verification_token'])),422,'Email verification expired or does not match this registration. Verify your email again.');
            $tinVariants=[$v['tin']];
            if(substr($v['tin'],-3)==='000')$tinVariants[]=substr($v['tin'],0,9);
            $existing=DB::table('merchants')->whereIn(DB::raw("REPLACE(REPLACE(tin,'-',''),' ','')"),$tinVariants)->exists();
            if($existing) throw ValidationException::withMessages(['tin'=>'This business TIN is already registered. Sign in or contact your NUERS administrator.']);
            $plan=!empty($v['subscription_plan_id'])?DB::table('website_subscription_plans')->where('id',$v['subscription_plan_id'])->where('published',true)->lockForUpdate()->first():null;
            if(!empty($v['subscription_plan_id'])&&!$plan) throw ValidationException::withMessages(['subscription_plan_id'=>'This subscription is no longer available. Select another plan.']);
            $user=User::create(['name'=>$v['contact_name'],'email'=>$v['email'],'password'=>Hash::make($v['password']),'api_token'=>null]);
            if(!$verification->development_delivery)$user->forceFill(['email_verified_at'=>now()])->save();
            Profile::create(['id'=>$user->id,'email'=>$user->email,'role'=>'merchant','full_name'=>$v['contact_name'],'organization'=>$v['business_name'],'tin'=>$v['tin'],'tin_bound_at'=>now()]);
            $rdo=(object)collect(app(\App\Services\RegistrationAddress::class)->offices())->firstWhere('rdo_code',$v['rdo_code']);
            $merchant=Merchant::create(['merchant_account_id'=>$user->id,'merchant_account_email'=>$user->email,'business_name'=>$v['business_name'],'tin'=>$v['tin'],'owner_name'=>$v['contact_name'],'email'=>$user->email,'phone'=>$v['mobile'],'address'=>$v['address'],'business_registration_address'=>$v['address'],'business_type'=>$v['business_type'],'sector'=>$v['industry'],'rdo_code'=>$rdo->rdo_code,'rdo_name'=>$rdo->rdo_name,'region'=>$v['address_details']['region']['name'],'city'=>$v['address_details']['city']['name'],'zip_code'=>$v['zip_code'],'country'=>'Philippines','country_code'=>'PH','vat_registered'=>$v['vat_registered'],'status'=>'pending','registration_date'=>now()->toDateString()]);
            $id=(string)Str::uuid();
            DB::table('business_registrations')->insert(['id'=>$id,'user_id'=>$user->id,'merchant_id'=>$merchant->id,'business_tin'=>$v['tin'],'industry_details'=>json_encode(app(\App\Services\RegistrationIndustry::class)->snapshot($v['industry'],$v['industry_other']??null)),'address_details'=>json_encode($v['address_details']),'contact_name'=>$v['contact_name'],'contact_position'=>$v['contact_position'],'mobile'=>$v['mobile'],'status'=>'pending_review','subscription_plan_id'=>$plan?->id,'plan_snapshot'=>$plan?json_encode($plan):null,'terms_version'=>self::NOTICE_VERSION,'privacy_version'=>self::NOTICE_VERSION,'consented_at'=>now(),'email_verified'=>!$verification->development_delivery,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('registration_email_verifications')->where('id',$verification->id)->update(['consumed_at'=>now(),'updated_at'=>now()]);
            app(BirCompliance::class)->audit('business.registration.submitted',$merchant->id,(string)$user->id,['registration_id'=>$id,'status'=>'pending_review','email_verified'=>!$verification->development_delivery]);
            return response()->json(['registration_id'=>$id,'business_name'=>$merchant->business_name,'status'=>'pending_review','email_verified'=>!$verification->development_delivery,'development_delivery'=>(bool)$verification->development_delivery],201);
        });
    }
}
