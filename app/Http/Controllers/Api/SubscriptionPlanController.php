<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanController extends Controller
{
    private function admin(Request $request): object
    {
        $user = $request->bearerToken() ? DB::table('users')->where('api_token', $request->bearerToken())->first() : null;
        abort_unless($user, 401, 'An authenticated administrator is required.');
        abort_unless(in_array(DB::table('profiles')->where('id', $user->id)->value('role'), ['admin','super_admin'], true), 403, 'Only administrators can edit subscription plans.');
        return $user;
    }
    public function index()
    {
        return response()->json(['plans'=>DB::table('website_subscription_plans')->where('published', true)->orderBy('position')->orderBy('id')->get()]);
    }
    public function manage(Request $request)
    {
        $this->admin($request);
        return response()->json(['plans'=>DB::table('website_subscription_plans')->orderBy('position')->orderBy('id')->get()]);
    }
    public function update(Request $request, int $id)
    {
        $actor=$this->admin($request);
        $v=$request->validate(['name'=>'required|string|max:80', 'description'=>'required|string|max:1000', 'badge'=>'nullable|string|max:60', 'fee_centavos'=>'required|integer|min:0|max:1000000', 'minimum_transaction_centavos'=>'required|integer|min:0|max:100000000000', 'storage_mb'=>'nullable|integer|min:1|max:100000000', 'retention_years'=>'nullable|integer|min:1|max:100', 'featured'=>'required|boolean', 'published'=>'required|boolean', 'position'=>'required|integer|min:0|max:100', 'revision'=>'required|integer|min:1']);
        return DB::transaction(function() use($v,$id,$actor) {
            $old=DB::table('website_subscription_plans')->where('id',$id)->lockForUpdate()->first();
            abort_unless($old,404,'Plan not found.');
            abort_unless($old->revision===$v['revision'],409,'This plan was updated by another administrator. Reload before saving.');
            $v['revision']++; $v['updated_at']=now();
            DB::table('website_subscription_plans')->where('id',$id)->update($v);
            app(BirCompliance::class)->audit('website.subscription_plan.updated',(string)$id,(string)$actor->id,['before'=>(array)$old,'after'=>$v]);
            return response()->json(['plan'=>DB::table('website_subscription_plans')->where('id',$id)->first()]);
        });
    }
}
