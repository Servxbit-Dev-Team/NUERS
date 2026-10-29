<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationCodeMail;
use App\Models\Merchant;
use App\Models\Profile;
use App\Models\User;
use App\Services\AtomicQuota;
use App\Services\BirCompliance;
use App\Services\MerchantVerificationDocuments;
use App\Services\PlatformMail;
use App\Services\PlatformNotifications;
use App\Services\RegistrationAddress;
use App\Services\RegistrationIndustry;
use App\Services\RegistrationVerification;
use App\Services\TransactionPricing;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class BusinessRegistrationController extends Controller
{
    public const NOTICE_VERSION = '2026-10-17.2';

    public const BUSINESS_TYPES = ['sole_proprietorship' => 'Sole Proprietorship', 'partnership' => 'Partnership', 'corporation' => 'Corporation', 'one_person_corporation' => 'One Person Corporation', 'cooperative' => 'Cooperative', 'non_stock_non_profit' => 'Non-stock / Non-profit Organization', 'government' => 'Government Entity', 'other' => 'Other'];

    public const INDUSTRIES = ['retail' => 'Retail & Wholesale Trade', 'services' => 'Professional & Business Services', 'it' => 'Information Technology', 'manufacturing' => 'Manufacturing', 'food' => 'Food & Hospitality', 'construction' => 'Construction & Real Estate', 'transport' => 'Transportation & Logistics', 'healthcare' => 'Healthcare', 'education' => 'Education', 'finance' => 'Financial Services', 'agriculture' => 'Agriculture & Fisheries', 'utilities' => 'Utilities & Telecommunications', 'other' => 'Other'];

    public function options()
    {
        return response()->json(['business_types' => self::BUSINESS_TYPES, 'industries' => app(RegistrationIndustry::class)->options(), 'rdos' => app(RegistrationAddress::class)->offices(), 'notice_version' => self::NOTICE_VERSION, 'verification_documents' => MerchantVerificationDocuments::KINDS]);
    }

    public function locations(Request $request)
    {
        $v = $request->validate(['level' => ['required', Rule::in(['regions', 'provinces', 'cities', 'barangays'])], 'parent' => 'nullable|digits:10']);

        return response()->json(['data' => app(RegistrationAddress::class)->locations($v['level'], $v['parent'] ?? null)]);
    }

    public function resolveRdo(Request $request)
    {
        $v = $request->validate(['city_code' => 'required|digits:10', 'barangay_code' => 'nullable|digits:10']);

        return response()->json(app(RegistrationAddress::class)->resolve($v['city_code'], $v['barangay_code'] ?? null));
    }

    public function sendCode(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $v = $request->validate(['email' => 'required|email|max:255', 'previous_challenge_id' => 'nullable|uuid']);
        $key = 'registration-email:'.hash('sha256', $v['email']);
        abort_if(RateLimiter::tooManyAttempts($key, 1), 429, 'Please wait one minute before requesting another code.');
        $notifications = app(PlatformNotifications::class);
        $savedSettings = Schema::hasTable('platform_mail_settings') ? app(PlatformMail::class)->settings() : null;
        $configured = Schema::hasTable('platform_mail_settings') && ! empty(app(PlatformMail::class)->settings()['enabled']);
        $development = ! $savedSettings && app()->environment('local') && config('mail.default') === 'log';
        abort_if(! $configured && ! $development && ! (app()->environment('testing') && ! $notifications->ready()), 503, 'Email verification delivery is not configured. Please contact NUERS support.');
        abort_if($configured && ! $notifications->ready(), 503, 'Email notification schema is not available. Contact NUERS support.');
        RateLimiter::hit($key, 60);
        if ($configured) {
            app(AtomicQuota::class)->admit('registration-code:'.hash('sha256', $v['email']), 10, 1);
            app(AtomicQuota::class)->admit('registration-code-ip:'.hash('sha256', $request->ip()), 50, 5);
        }
        $code = (string) random_int(100000, 999999);
        $id = (string) Str::uuid();
        DB::table('registration_email_verifications')->insert(['id' => $id, 'email' => $v['email'], 'code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'development_delivery' => $development, 'created_at' => now(), 'updated_at' => now()]);
        try {
            if ($configured) {
                $notifications->queue($v['email'], 'registration.verification', $id, 'Verify your business email', 'Your NUERS verification code is: '.$code."\nThis code expires in 10 minutes. Do not share it with anyone. If you did not request this code, ignore this email.", 'security', expires: now()->addMinutes(10), context: ['challenge_id' => $id]);
            } else {
                Mail::to($v['email'])->send(new RegistrationCodeMail($code));
            }
        } catch (\Throwable $e) {
            DB::table('registration_email_verifications')->where('id', $id)->delete();
            report($e);
            abort(503, 'The verification email could not be sent. Please try again later.');
        }
        if (! empty($v['previous_challenge_id'])) {
            DB::table('registration_email_verifications')->where('id', $v['previous_challenge_id'])->where('email', $v['email'])->whereNull('consumed_at')->update(['expires_at' => now()->subSecond(), 'proof_hash' => null, 'updated_at' => now()]);
        }

        return response()->json(['challenge_id' => $id, 'expires_in' => 600, 'resend_after' => 60, 'expires_at' => now()->addMinutes(10)->toIso8601String(), 'development_delivery' => $development, ...($development ? ['development_code' => $code] : [])])->header('Cache-Control', 'no-store');
    }

    public function verifyCode(Request $request)
    {
        $v = $request->validate(['challenge_id' => 'required|uuid', 'code' => 'required|digits:6']);
        $result = DB::transaction(function () use ($v) {
            $row = DB::table('registration_email_verifications')->where('id', $v['challenge_id'])->lockForUpdate()->first();
            if (! $row) {
                return ['error_code' => 'REGISTRATION_CODE_NOT_FOUND', 'message' => 'This verification request is no longer available. Send a new code.'];
            }
            if ($row->verified_at || $row->consumed_at) {
                return ['error_code' => 'REGISTRATION_CODE_USED', 'message' => 'This code has already been verified. Continue registration, or send a new code if needed.'];
            }
            if (! Carbon::parse($row->expires_at)->isFuture()) {
                return ['error_code' => 'REGISTRATION_CODE_EXPIRED', 'message' => 'This code has expired or was replaced by a newer code. Send a new code.'];
            }
            if ($row->attempts >= 5) {
                return ['error_code' => 'REGISTRATION_CODE_LOCKED', 'message' => 'Five incorrect codes were entered. Send a new code to continue.'];
            }
            // Explicitly preserve the deadline, including on legacy MySQL schemas with automatic timestamp updates.
            DB::table('registration_email_verifications')->where('id', $row->id)->update(['attempts' => $row->attempts + 1, 'expires_at' => $row->expires_at]);
            if (! Hash::check($v['code'], $row->code_hash)) {
                return $row->attempts + 1 >= 5
                    ? ['error_code' => 'REGISTRATION_CODE_LOCKED', 'message' => 'Five incorrect codes were entered. Send a new code to continue.']
                    : ['error_code' => 'REGISTRATION_CODE_INCORRECT', 'message' => 'The code does not match this verification request. Check the latest email and try again.'];
            }
            $proof = Str::random(64);
            DB::table('registration_email_verifications')->where('id', $row->id)->update(['verified_at' => now(), 'proof_hash' => hash('sha256', $proof), 'expires_at' => now()->addMinutes(15), 'updated_at' => now()]);

            return ['verification_token' => $proof, 'email' => $row->email, 'expires_in' => 900, 'expires_at' => now()->addMinutes(15)->toIso8601String(), 'development_delivery' => (bool) $row->development_delivery];
        });
        if (isset($result['error_code'])) {
            return response()->json(['code' => $result['error_code'], 'message' => $result['message'], 'errors' => ['code' => [$result['message']]]], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email'))), 'tin' => preg_replace('/\D/', '', (string) $request->input('tin'))]);
        $v = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->letters()->numbers()],
            'tin' => 'required|digits:12|unique:business_registrations,business_tin',
            'business_name' => 'required|string|max:255', 'street_address' => 'required|string|max:500', 'zip_code' => 'required|digits:4',
            'region_code' => 'required|digits:10', 'province_code' => 'required|digits:10', 'city_code' => 'required|digits:10', 'barangay_code' => 'required|digits:10',
            'business_type' => ['required', Rule::in(array_keys(self::BUSINESS_TYPES))], 'industry' => ['required', Rule::in(array_merge(array_keys(self::INDUSTRIES), array_keys(app(RegistrationIndustry::class)->options())))],
            'industry_other' => 'required_if:industry,other|nullable|string|max:255',
            'rdo_code' => ['required', Rule::in(array_column(app(RegistrationAddress::class)->offices(), 'rdo_code'))],
            'contact_name' => 'required|string|max:255', 'contact_position' => 'required|string|max:120', 'mobile' => ['required', 'regex:/^\+639\d{9}$/'],
            'vat_registered' => 'required|boolean', 'subscription_plan_id' => ['nullable', Rule::exists('website_subscription_plans', 'id')->where('published', true)],
            'terms_accepted' => 'required|accepted', 'privacy_accepted' => 'required|accepted', 'notice_version' => ['required', Rule::in([self::NOTICE_VERSION])],
            'challenge_id' => 'required|uuid', 'verification_token' => 'required|string|size:64', 'role' => 'prohibited', 'status' => 'prohibited', 'uploaded_document_ids' => 'nullable|array|max:5', 'uploaded_document_ids.*' => 'required|uuid|distinct',
        ]);
        $address = app(RegistrationAddress::class)->validate($v);
        $v['address'] = $address['address'];
        $v['address_details'] = $address['details'];

        return DB::transaction(function () use ($v) {
            if (Schema::hasTable('business_registry_lock')) {
                DB::table('business_registry_lock')->where('id', 1)->lockForUpdate()->first();
            }
            $verification = DB::table('registration_email_verifications')->where('id', $v['challenge_id'])->lockForUpdate()->first();
            app(RegistrationVerification::class)->requireValid($verification, $v['verification_token'], $v['email']);
            $tinVariants = [$v['tin']];
            if (substr($v['tin'], -3) === '000') {
                $tinVariants[] = substr($v['tin'], 0, 9);
            }
            $existing = DB::table('merchants')->whereIn(DB::raw("REPLACE(REPLACE(tin,'-',''),' ','')"), $tinVariants)->exists();
            if ($existing) {
                throw ValidationException::withMessages(['tin' => 'This business TIN is already registered. Sign in or contact your NUERS administrator.']);
            }
            $plan = app(TransactionPricing::class)->offer();
            $user = User::create(['name' => $v['contact_name'], 'email' => $v['email'], 'password' => Hash::make($v['password']), 'api_token' => null]);
            if (! $verification->development_delivery) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            Profile::create(['id' => $user->id, 'email' => $user->email, 'role' => 'merchant', 'full_name' => $v['contact_name'], 'organization' => $v['business_name'], 'tin' => $v['tin'], 'tin_bound_at' => null]);
            $rdo = (object) collect(app(RegistrationAddress::class)->offices())->firstWhere('rdo_code', $v['rdo_code']);
            $merchant = Merchant::create(['merchant_account_id' => $user->id, 'merchant_account_email' => $user->email, 'business_name' => $v['business_name'], 'tin' => $v['tin'], 'owner_name' => $v['contact_name'], 'email' => $user->email, 'phone' => $v['mobile'], 'address' => $v['address'], 'business_registration_address' => $v['address'], 'business_type' => $v['business_type'], 'sector' => $v['industry'], 'rdo_code' => $rdo->rdo_code, 'rdo_name' => $rdo->rdo_name, 'region' => $v['address_details']['region']['name'], 'city' => $v['address_details']['city']['name'], 'zip_code' => $v['zip_code'], 'country' => 'Philippines', 'country_code' => 'PH', 'vat_registered' => $v['vat_registered'], 'status' => 'pending', 'registration_date' => now()->toDateString()]);
            $id = (string) Str::uuid();
            DB::table('business_registrations')->insert(['id' => $id, 'user_id' => $user->id, 'merchant_id' => $merchant->id, 'business_tin' => $v['tin'], 'industry_details' => json_encode(app(RegistrationIndustry::class)->snapshot($v['industry'], $v['industry_other'] ?? null)), 'address_details' => json_encode($v['address_details']), 'contact_name' => $v['contact_name'], 'contact_position' => $v['contact_position'], 'mobile' => $v['mobile'], 'status' => 'pending_review', 'subscription_plan_id' => $plan?->id, 'plan_snapshot' => $plan ? json_encode($plan) : null, 'terms_version' => self::NOTICE_VERSION, 'privacy_version' => self::NOTICE_VERSION, 'consented_at' => now(), 'email_verified' => ! $verification->development_delivery, 'created_at' => now(), 'updated_at' => now()]);
            $documents = app(MerchantVerificationDocuments::class);
            if ($documents->available()) {
                $documents->claim($merchant->id, $verification->id, $v['uploaded_document_ids'] ?? [], (string) $user->id);
                DB::table('business_registrations')->where('id', $id)->update(['documents_required' => true]);
            } elseif (! app()->environment('testing')) {
                abort(503, 'Verification document storage is not available. Please contact NUERS support.');
            }
            DB::table('registration_email_verifications')->where('id', $verification->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            app(BirCompliance::class)->audit('business.registration.submitted', $merchant->id, (string) $user->id, ['registration_id' => $id, 'status' => 'pending_review', 'email_verified' => ! $verification->development_delivery]);

            return response()->json(['registration_id' => $id, 'business_name' => $merchant->business_name, 'status' => 'pending_review', 'email_verified' => ! $verification->development_delivery, 'development_delivery' => (bool) $verification->development_delivery, 'documents_uploaded' => count($v['uploaded_document_ids'] ?? []), 'documents_required' => 5], 201);
        });
    }
}
