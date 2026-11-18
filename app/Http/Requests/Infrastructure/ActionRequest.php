<?php

namespace App\Http\Requests\Infrastructure;

use App\Services\ApiAccess;
use App\Services\Infrastructure\Catalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(ApiAccess::class)->require($this, ['super_admin']);

        return true;
    }

    public function rules(): array
    {
        return [
            'node_id' => 'required|uuid', 'action' => ['required', Rule::in(array_keys(Catalog::actions()))],
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/(?:password|secret|token|api_key)\s*[:=]/i'],
            'parameters' => 'present|array:service,artifact_id,runbook_id',
            'parameters.service' => ['required_if:action,service.restart,service.reload', 'prohibited_unless:action,service.restart,service.reload', Rule::in(array_unique(array_merge(...array_values(Catalog::SERVICES))))],
            'parameters.artifact_id' => 'required_if:action,configuration.deploy,network.change,restore.test,restore.production|prohibited_unless:action,configuration.deploy,network.change,restore.test,restore.production|uuid',
            'parameters.runbook_id' => ['nullable', 'regex:/^[a-zA-Z0-9_.-]{3,80}$/'],
            'idempotency_key' => 'required|uuid',
            'proof_id' => 'nullable|uuid', 'confirmation' => 'nullable|string|max:30',
        ];
    }

    protected function passedValidation(): void
    {
        abort_if(array_diff(array_keys($this->all()), ['node_id', 'action', 'reason', 'parameters', 'idempotency_key', 'proof_id', 'confirmation']), 422, 'Unsupported action fields. Commands and arbitrary targets are not accepted.');
    }
}
