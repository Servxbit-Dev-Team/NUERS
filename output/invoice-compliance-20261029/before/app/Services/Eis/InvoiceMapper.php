<?php

namespace App\Services\Eis;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Pure mapping of reconciled, immutable source snapshots to the published BIR field names. */
final class InvoiceMapper
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['bir_mapping' => $message]);
    }

    private function text(mixed $value, int $max, string $field, bool $required = true): string
    {
        $text = trim((string) $value);
        if (($required && $text === '') || mb_strlen($text) > $max) {
            $this->fail("{$field} is missing or exceeds {$max} characters. Source values are never truncated.");
        }

        return $text;
    }

    /** Metadata supplies reviewed registration/correction evidence, never replacements for source amounts. */
    public function map(array $m, object $p, array $metadata = [], array $uids = []): array
    {
        if (($m['schema'] ?? '') !== 'NUERS-normalized-document-5') {
            $this->fail('Prepare the document using the current mapper before creating a BIR batch.');
        }
        if (($m['currency'] ?? '') !== 'PHP') {
            $this->fail('A reconciled PHP document is required.');
        }
        if (! preg_match('/^[A-Z0-9]{8}$/D', (string) $p->accreditation_id)) {
            $this->fail('Record the BIR-issued eight-character accreditation ID.');
        }
        if (($m['seller']['branch_code'] ?? '') !== $p->branch_code) {
            $this->fail('Document branch does not match the selected connection.');
        }
        $tin = (string) ($m['seller']['taxpayer_tin'] ?? '');
        if (! preg_match('/^\d{9}$/D', $tin)) {
            $this->fail('Seller requires a nine-digit taxpayer TIN.');
        }
        if (! in_array((string) $p->seller_type, ['0', '1', '2'], true)) {
            $this->fail('Record the verified seller registration type.');
        }
        if (isset($m['seller']['vat_registered']) && (($p->seller_type === '1' && $m['seller']['vat_registered']) || ($p->seller_type === '0' && ! $m['seller']['vat_registered']))) {
            $this->fail('Source seller VAT registration does not match the selected BIR profile.');
        }
        $date = CarbonImmutable::parse($m['issue_date'], 'Asia/Manila')->format('Ymd');
        if ($date > now('Asia/Manila')->format('Ymd')) {
            $this->fail('Future invoice dates cannot be transmitted.');
        }
        $kind = $m['document_type'];
        $cas = $p->system_type === 'CAS';
        $credit = in_array($kind, ['credit_memo', 'credit_note'], true);
        $debit = in_array($kind, ['debit_memo', 'debit_note'], true);
        if (! in_array($kind, ['sales_invoice', 'vat_invoice', 'non_vat_invoice', 'mixed_sales_invoice', 'zero_rated_invoice', 'credit_memo', 'credit_note', 'debit_memo', 'debit_note'], true)) {
            $this->fail('Only principal invoices and supported adjustment documents are transmitted.');
        }
        if ($cas && $debit && $p->schema_dialect !== 'guide_20260730') {
            $this->fail('The September workbook removes CAS debit notes. Obtain BIR confirmation of the applicable dialect before sending this document.');
        }
        $corrected = $credit || $debit || ! empty($metadata['correction_code']);
        if ($corrected && (! in_array($metadata['correction_code'] ?? '', ['01', '02', '03', '04', '05', '09'], true) || empty($metadata['previous_uids']))) {
            $this->fail('An adjustment requires a reviewed correction code and previously accepted EIS invoice ID for every tax class.');
        }
        $buyerTin = preg_replace('/\D/', '', (string) ($m['buyer']['tin'] ?? ''));
        if (! in_array(strlen($buyerTin), [0, 9, 12, 14], true)) {
            $this->fail('Buyer TIN must have 9, 12 or 14 digits; malformed identity is never truncated.');
        }
        if ($cas && $buyerTin === '000000000' && empty($metadata['buyer_not_registered'])) {
            $this->fail('Confirm the unregistered buyer before reporting a zero TIN.');
        }
        $buyerBranch = $metadata['buyer_branch_code'] ?? (strlen($buyerTin) === 14 ? substr($buyerTin, 9) : (strlen($buyerTin) === 12 ? str_pad(substr($buyerTin, 9), 5, '0', STR_PAD_LEFT) : null));
        if (strlen($buyerTin) > 9) {
            $buyerTin = substr($buyerTin, 0, 9);
        }
        if (! $buyerTin && ! empty($metadata['buyer_not_registered'])) {
            $buyerTin = '000000000';
        }
        if ($cas && (! preg_match('/^\d{9}$/D', $buyerTin) || ! preg_match('/^\d{5}$/D', (string) $buyerBranch))) {
            $this->fail('CAS buyer TIN and branch are required. Explicitly attest an unregistered buyer before using the BIR zero-TIN convention.');
        }
        $seller = ['Tin' => $tin, 'BranchCd' => $p->branch_code, 'Type' => (string) $p->seller_type, 'RegNm' => $this->text($m['seller']['name'] ?? null, 200, 'Seller name'), 'BusinessNm' => '', 'Email' => '', 'RegAddr' => $this->text($m['seller']['address'] ?? null, 300, 'Seller address')];
        $buyer = ['Tin' => $buyerTin, 'BranchCd' => $buyerBranch ?? '', 'RegNm' => $this->text($m['buyer']['name'] ?? null, 200, 'Buyer name', $cas), 'BusinessNm' => '', 'Email' => '', 'RegAddr' => $this->text($m['buyer']['address'] ?? '', 300, 'Buyer address', false)];
        $groups = [];
        foreach ($m['items'] ?? [] as $line) {
            $class = match ($line['tax_type']) {
                'vatable' => '01','zero_rated' => '02','vat_exempt','non_vat' => '03',default => null
            };
            if (! $class) {
                $this->fail('Unsupported source tax classification.');
            }
            if ($p->seller_type === '1' && in_array($class, ['01', '02'], true)) {
                $this->fail('Non-VAT seller cannot report VATable or zero-rated sales.');
            }
            if ($p->seller_type === '2' && ($metadata['rbe_local_sale'] ?? false)) {
                $class = '04';
            }
            $groups[$cas ? $class : '00'][] = $line;
        }
        if (! $groups) {
            $this->fail('Reconciled line items are required.');
        }
        $withholding = (int) ($m['totals']['withholding_centavos'] ?? 0);
        $withholdingParts = $metadata['withholding_by_class'] ?? [];
        if (array_sum(array_map(fn ($x) => array_sum($x), $withholdingParts)) !== $withholding) {
            $this->fail('Allocate withholding by tax class and income/VAT/percentage type; its total must match the immutable document.');
        }
        if (array_diff(array_keys($withholdingParts), array_keys($groups))) {
            $this->fail('Withholding allocation references an absent tax class.');
        }
        $result = [];
        $sign = $credit ? -1 : 1;
        $benefit = $m['sales_benefit']['eligibility']['category'] ?? 'regular';
        foreach ($groups as $class => $lines) {
            $class = (string) $class;
            $uid = $uids[$class] ?? ($date.$p->accreditation_id.implode('', array_map(fn () => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'[random_int(0, 35)], range(1, 8))));
            if (! preg_match('/^'.$date.preg_quote($p->accreditation_id, '/').'[A-Z0-9]{8}$/D', $uid)) {
                $this->fail('Invalid EIS invoice identity.');
            }
            $previous = $metadata['previous_uids'][$class] ?? '';
            if ($corrected && ! preg_match('/^[A-Z0-9]{24}$/D', $previous)) {
                $this->fail('Missing original accepted EIS identity for this tax class.');
            }
            $d = ['CompInvoiceId' => $this->text($m['number'], 50, 'Document number'), 'IssueDtm' => $date, 'EisUniqueId' => $uid, 'DocType' => $cas ? ($credit ? '03' : ($debit ? '02' : '01')) : ($corrected ? '06' : '01'), 'CorrYN' => $corrected ? 'Y' : 'N', 'CorrectionCd' => $corrected ? $metadata['correction_code'] : '', 'PrevUniqueId' => $corrected ? $previous : '', 'Rmk1' => $this->text($metadata['remarks'] ?? '', 500, 'Correction remarks', false), 'SellerInfo' => $seller];
            if ($cas || $buyerTin || $buyer['RegNm']) {
                $d['BuyerInfo'] = $buyer;
            }
            $disc = ['ScAmt' => 0, 'PwdAmt' => 0, 'RegAmt' => 0, 'SpeAmt' => 0, 'Rmk2' => $benefit === 'regular' ? '' : str_replace('_', ' ', $benefit).' · recorded source benefit'];
            $net = $vat = $gross = $regular = $statutory = 0;
            $items = [];
            $taxBases = ['01' => 0, '02' => 0, '03' => 0];
            foreach ($lines as $l) {
                foreach (['gross_base_centavos', 'net_base_centavos', 'regular_discount_base_centavos', 'statutory_discount_centavos'] as $f) {
                    if (! isset($l[$f]) || ! is_int($l[$f]) || $l[$f] < 0) {
                        $this->fail('Source discount allocation needs review.');
                    }
                }
                $base = $l['gross_base_centavos'];
                $reg = $l['regular_discount_base_centavos'];
                $stat = $l['statutory_discount_centavos'];
                if ($base - $reg - $stat !== $l['net_base_centavos']) {
                    $this->fail('Source discount reconciliation failed.');
                }
                $qty = (float) $l['quantity'];
                if (! is_finite($qty) || $qty <= 0) {
                    $this->fail('A positive source quantity is required.');
                }
                if ($cas) {
                    if (abs($qty * 100 - round($qty * 100)) > 0.000001) {
                        $this->fail('BIR CAS quantity supports two decimals. Review this source quantity without rounding it silently.');
                    }
                    $unit = (int) round($base / $qty);
                    if ((int) round($unit * $qty) !== $base) {
                        $this->fail('The VAT-exclusive unit price cannot reproduce the original line amount at BIR precision. Review the source allocation.');
                    }
                    $desc = $this->text($l['description'], 100, 'Item description');
                    $items[] = ['Nm' => $desc, 'Desc' => $desc, 'Qty' => $sign * $qty, 'Unit' => '', 'UnitCost' => $unit / 100, 'SalesAmt' => $sign * $base / 100, 'RegDscntAmt' => $sign * $reg / 100, 'SpeDscntAmt' => 0.0, 'NetSales' => $sign * ($base - $reg) / 100];
                }
                $gross += $base;
                $regular += $reg;
                $statutory += $stat;
                $net += $l['net_base_centavos'];
                $vat += $l['vat_centavos'];
                $key = match ($l['tax_type']) {
                    'vatable' => '01','zero_rated' => '02',default => '03'
                };
                $taxBases[$key] += $base;
            }
            if (count($items) > 1000) {
                $this->fail('CAS supports at most 1,000 items per invoice.');
            }
            if (in_array($class, ['02', '03'], true) && $vat !== 0) {
                $this->fail('Exempt/zero-rated invoices cannot contain VAT.');
            }
            $discountField = match ($benefit) {
                'senior_citizen' => 'ScAmt','pwd' => 'PwdAmt',default => 'RegAmt'
            };
            $disc[$discountField] = $sign * $statutory / 100;
            if (! $cas) {
                $disc['RegAmt'] += $sign * $regular / 100;
            }
            if ($cas) {
                $d['TransClass'] = $class;
                $d['ItemList'] = $items;
                $d['TotNetItemSales'] = $sign * ($gross - $regular) / 100;
            } else {
                $vatField = $p->schema_dialect === 'guide_20260730' ? 'VatSales' : 'VATSales';
                $d += [$vatField => $sign * $taxBases['01'] / 100, 'ExemptSales' => $sign * $taxBases['03'] / 100, 'ZeroSales' => $sign * $taxBases['02'] / 100, 'TotSalesAmt' => $sign * $gross / 100];
            }
            $w = $withholdingParts[$class] ?? ['income' => 0, 'vat' => 0, 'percentage' => 0];
            foreach ($w as $v) {
                if (! is_int($v) || $v < 0) {
                    $this->fail('Withholding allocations must use non-negative integer centavos.');
                }
            }
            $d += ['Discount' => $disc, 'OtherTaxRev' => 0.0, 'TotNetSalesAftDisct' => $sign * $net / 100, 'VATAmt' => $sign * $vat / 100, 'WithholdIncome' => $sign * ($w['income'] ?? 0) / 100, 'WithholdBusVAT' => $sign * ($w['vat'] ?? 0) / 100, 'WithholdBusPT' => $sign * ($w['percentage'] ?? 0) / 100, 'OtherNonTaxCharge' => 0.0, 'NetAmtPay' => $sign * ($net + $vat - array_sum($w)) / 100, 'PtuNum' => $this->text($p->ptu_number, 50, 'CAS acknowledgment / POS PTU number')];
            if (! $cas) {
                $d += ['Min' => $this->text($p->machine_id, 50, 'POS MIN'), 'Msn' => $this->text($p->machine_serial, 50, 'POS serial')];
            }
            $result[$class] = $d;
        }
        $sum = (int) round(array_sum(array_column($result, 'NetAmtPay')) * 100);
        if ($sum !== $sign * ((int) $m['totals']['amount_centavos'] - $withholding)) {
            $this->fail('Mapped payable does not reconcile to the source document.');
        }

        return $result;
    }
}
