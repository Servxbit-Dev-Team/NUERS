<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        // Preserve legacy IDs and registration snapshots; retire their public offers.
        DB::transaction(function () {
            DB::table('website_subscription_plans')->where('id', '!=', 1)->update(['published' => false, 'updated_at' => now()]);
            DB::table('website_subscription_plans')->updateOrInsert(['id' => 1], [
                'slug' => 'transaction-pricing', 'name' => 'NUERS Transaction Pricing',
                'description' => 'Pay ₱3 per transaction of ₱500 and above. Unlimited storage with five-year record retention.',
                'fee_centavos' => 300, 'minimum_transaction_centavos' => 50000, 'storage_mb' => null,
                'retention_years' => 5, 'badge' => 'No subscription required', 'featured' => true,
                'published' => true, 'position' => 1, 'revision' => (DB::table('website_subscription_plans')->where('id', 1)->value('revision') ?? 0) + 1, 'updated_at' => now(),
            ]);
        });
    }
    public function down(): void
    { /* Historical prices and registration snapshots are preserved. */
    }
};
