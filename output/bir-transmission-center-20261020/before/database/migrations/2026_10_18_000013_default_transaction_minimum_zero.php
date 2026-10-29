<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            $p = DB::table('website_subscription_plans')->where('slug', 'transaction-pricing')->lockForUpdate()->first();
            if (!$p || (int) $p->minimum_transaction_centavos === 0) return;
            $revision = (int) $p->revision + 1;
            DB::table('website_subscription_plans')->where('id', $p->id)->update([
                'minimum_transaction_centavos' => 0,
                'description' => 'Pay ₱'.number_format($p->fee_centavos / 100, 2, '.', ',').' per finalized sale, with no minimum amount. Unlimited storage with five-year retention.',
                'revision' => $revision, 'updated_at' => now(),
            ]);
            DB::table('wallet_pricing_history')->insert([
                'id' => (string) Str::uuid(), 'revision' => $revision,
                'previous_minimum_transaction_centavos' => $p->minimum_transaction_centavos, 'minimum_transaction_centavos' => 0,
                'previous_fee_centavos' => $p->fee_centavos, 'fee_centavos' => $p->fee_centavos,
                'actor_id' => 'system:authorized-pricing-update',
                'reason' => 'Operator requested zero minimum: every new finalized Live sale is chargeable. Minimum remains configurable; historical charges are preserved.',
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Change prospective pricing through the audited Admin pricing controls; do not rewrite pricing history.');
    }
};
