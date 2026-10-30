<?php

namespace App\Services;

use Illuminate\Support\Facades\{Crypt,DB};

class PtiApplications
{
    public const SOURCE = 'https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2098-2026%20redacted.pdf';
    public const STATUSES = ['draft' => 'Draft','in_review' => 'NUERS preparation review','needs_changes' => 'Changes requested','ready_to_file' => 'Ready for RDO / LT filing','filed' => 'BIR filing recorded','issued' => 'BIR-issued PTI recorded','declined' => 'BIR decision: not approved','withdrawn' => 'Withdrawn in NUERS'];
    public const DOCUMENTS = ['registration' => 'Registration / BIR COR','authority' => 'Representative authority / owner declaration','system_description' => 'System description / manual','sample_invoice' => 'Sample electronic invoice','office_requirements' => 'RDO / LT instructions or checklist','supporting' => 'Additional supporting document','bir_receipt' => 'BIR receiving / filing evidence','bir_permit' => 'BIR-issued PTI','bir_decision' => 'BIR decision / correspondence'];
    public const REVIEW_DOCUMENTS = ['registration','authority','system_description','sample_invoice'];

    public function payload(object $row): array
    {
        return json_decode(Crypt::decryptString($row->payload_encrypted), true, flags:JSON_THROW_ON_ERROR);
    }

    public function identity(object $m): array
    {
        return ['business_name' => $m->business_name,'tin' => $m->tin,'address' => $m->address ?? '', 'rdo_code' => $m->rdo_code ?? '', 'owner_name' => $m->owner_name ?? '', 'email' => $m->email ?? '', 'phone' => $m->phone ?? ''];
    }

    public function record(object $row): array
    {
        return ['id' => $row->id,'reference' => 'PTI-APP-'.strtoupper(substr($row->id, 0, 8)), 'merchant_id' => $row->merchant_id,'status' => $row->status,'status_label' => self::STATUSES[$row->status], 'revision' => (int)$row->revision, 'authorization_id' => $row->authorization_id, 'submitted_at' => $row->submitted_at,'created_at' => $row->created_at,'updated_at' => $row->updated_at,'payload' => $this->payload($row)];
    }

    public function documents(string $id)
    {
        return DB::table('pti_application_documents')->where('application_id', $id)->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function presentDocument(object $d): array
    {
        return ['id' => $d->id,'kind' => $d->kind,'label' => self::DOCUMENTS[$d->kind],'filename' => Crypt::decryptString($d->filename_encrypted),'bytes' => (int)$d->bytes,'sha256' => $d->sha256,'created_at' => $d->created_at];
    }

    public function event(object $row, string $actor, string $event, array $details): void
    {
        DB::table('pti_application_events')->insert(['application_id' => $row->id,'actor_id' => $actor,'event' => $event,'revision' => $row->revision,'details_encrypted' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)),'created_at' => now()]);
        app(BirCompliance::class)->audit('pti.application.'.$event, $row->id, $actor, ['merchant_id' => $row->merchant_id,'revision' => $row->revision,'status' => $row->status]);
    }

    public function catalog(): array
    {
        return ['statuses' => self::STATUSES,'document_kinds' => self::DOCUMENTS,'review_documents' => self::REVIEW_DOCUMENTS,
            'source_url' => self::SOURCE,'checked_on' => '2026-10-28','direct_submission_available' => false,
            'filing_notice' => 'File with the RDO / LT Office where the taxpayer is registered. NUERS prepares and tracks the application; direct electronic submission to BIR is not connected.',
            'checklist_notice' => 'The document list below is a NUERS preparation checklist, not a BIR-prescribed application form or exhaustive official requirement list. Confirm the current forms, supporting documents and filing channel with your RDO / LT Office.',
            'reference_notice' => 'RMC 98-2026, section IV.12–15: PTI identifies the approved system and coverage. Different systems may need separate PTIs. EIS certification and PTT remain separate. Application preparation does not grant issuance authority.'];
    }
}
