<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\TransactionPricing;
use Illuminate\Http\Request;

/** Legacy catalogue routes remain compatible; the active offer has no tiers. */
class SubscriptionPlanController extends Controller
{
    private function admin(Request $request): void
    {
        app(ApiAccess::class)->require($request, ['admin', 'super_admin']);
    }
    public function index(TransactionPricing $pricing)
    {
        $offer = $pricing->offer();
        return response()->json(['pricing'=>$offer, 'plans'=>[$offer]]);
    }
    public function manage(Request $request, TransactionPricing $pricing)
    {
        $this->admin($request);
        return $this->index($pricing);
    }
    public function update(Request $request, int $id)
    {
        $this->admin($request);
        abort(422, 'NUERS uses one transaction pricing policy: the published fee at ₱500 and above; Super Admin manages the prospective fee in Wallets & Transaction Pricing, unlimited storage and five-year retention. Subscription tiers are not available.');
    }
}
