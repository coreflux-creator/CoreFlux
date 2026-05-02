<?php
/**
 * NACHA driver — generates a NACHA-format ACH origination file.
 *
 * No external API. The tenant downloads the resulting file and uploads it
 * to their bank's cash-management / treasury workstation, which originates
 * the ACH credits to vendors / employees.
 *
 * File spec (NACHA Operating Rules — PPD / CCD entries, credits-only):
 *   - 94-character fixed-width records (ASCII, single-line, CR-LF or LF separator)
 *   - File Header  (record type 1)
 *   - Batch Header (record type 5)   ← one per SEC code (ppd vs ccd)
 *   - Entry Detail (record type 6)   ← one per recipient
 *   - Batch Control(record type 8)
 *   - File Control (record type 9)
 *   - File padded to 10-record blocks with 9999... fillers
 *
 * Per playbook & NACHA Op Rules:
 *   - PPD (Prearranged Payment / Deposit) → consumer credits (W-2 payroll DD)
 *   - CCD (Corporate Credit / Debit)      → business credits (AP vendor)
 * Items in a batch must share a SEC code, so we split a mixed batch into
 * separate batches inside the same file.
 */

declare(strict_types=1);

require_once __DIR__ . '/../payment_rails.php';

class NachaDriver implements PaymentRailsDriver
{
    public function name(): string { return 'nacha'; }

    /** Always true — file generation needs nothing external. */
    public function isConfigured(): bool { return true; }

    public function originate(array $items, array $opts): array
    {
        if (count($items) === 0) {
            throw new PaymentRailsOriginateException('originate() requires at least one item');
        }

        $companyName    = $this->pad($opts['company_name'] ?? 'CORE FLUX', 16, 'left');
        $companyId      = $this->pad((string) ($opts['company_id'] ?? '1234567890'), 10, 'left');
        $originRouting  = preg_replace('/\D/', '', (string) ($opts['origin_routing'] ?? '')) ?: '021000021'; // demo
        $effectiveDate  = (string) ($opts['effective_date'] ?? date('Y-m-d', strtotime('+1 day')));
        $batchId        = $opts['batch_id'] ?? ('nacha_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)));

        // Group items by SEC code so we emit one batch per (consumer / business) class.
        $bySec = ['ppd' => [], 'ccd' => []];
        foreach ($items as $i => $it) {
            $sec = strtolower((string) ($it['sec_code'] ?? 'ppd'));
            if (!isset($bySec[$sec])) {
                throw new PaymentRailsOriginateException("Unsupported SEC code at item {$i}: {$sec}");
            }
            $this->validateItem($it, $i);
            $bySec[$sec][] = $it;
        }

        $records   = [];
        $records[] = $this->fileHeader($originRouting, $companyId, $effectiveDate);
        $batchNo   = 0;
        $traceSeed = 1;
        $totalCreditCents  = 0;
        $totalEntries      = 0;
        $fileEntryHashSum  = 0;
        $resultItems       = [];

        foreach ($bySec as $sec => $batchItems) {
            if (count($batchItems) === 0) continue;
            $batchNo++;

            $records[] = $this->batchHeader(
                $batchNo, $companyName, $companyId, $sec, $effectiveDate, $originRouting
            );

            $batchEntryHash    = 0;
            $batchCreditCents  = 0;
            foreach ($batchItems as $it) {
                $traceNumber = substr($originRouting, 0, 8) . str_pad((string) $traceSeed++, 7, '0', STR_PAD_LEFT);
                $records[]   = $this->entryDetail($it, $traceNumber);
                $batchEntryHash   += (int) substr(preg_replace('/\D/', '', $it['account_routing']), 0, 8);
                $batchCreditCents += (int) $it['amount_cents'];
                $totalEntries++;
                $resultItems[] = [
                    'external_ref'      => (string) $it['external_ref'],
                    'status'            => 'queued',
                    'rail_external_ref' => $traceNumber,
                ];
            }

            $records[] = $this->batchControl(
                $batchNo, $companyId, count($batchItems), $batchEntryHash, $batchCreditCents, $originRouting
            );
            $totalCreditCents += $batchCreditCents;
            $fileEntryHashSum += $batchEntryHash;
        }

        // File Control
        // Block count = ceil(record count / 10). Records padded with 9-filler 94-char lines.
        $records[] = $this->fileControl(
            $batchNo, $totalEntries, $fileEntryHashSum, $totalCreditCents,
            count($records) + 1 // include this control record itself
        );
        $padded = $this->padToBlocks($records);

        $fileBody = implode("\n", $padded) . "\n";

        return [
            'batch_id' => $batchId,
            'status'   => 'queued',
            'items'    => $resultItems,
            'payload'  => [
                'mime'         => 'text/plain',
                'filename'     => "nacha-{$batchId}.ach",
                'content'      => $fileBody,
                'record_count' => count($padded),
                'batches'      => $batchNo,
                'entries'      => $totalEntries,
                'credit_cents' => $totalCreditCents,
            ],
        ];
    }

    public function getStatus(string $railExternalRef): string
    {
        // File-based rail: once the tenant uploads to their bank, status
        // tracking is out-of-band. We never auto-flip beyond "submitted".
        // The bank rec / payment-clear flow updates ap_payments.cleared_at
        // once the bank confirms.
        return 'submitted';
    }

    public function metadata(): array
    {
        return [
            'cost_per_item_dollars'    => 0.0,
            'cost_pct'                 => 0.0,
            'settlement_business_days' => ['min' => 1, 'max' => 2],
            'supports_same_day_ach'    => true,    // tenant can request SDA at the bank portal
            'supports_rtp'             => false,
            'needs_pre_approval'       => false,
            'needs_funding_link'       => false,
            'fallback_to'              => null,    // already the fallback
            'pros'                     => [
                'Zero per-transfer cost',
                'No third-party dependency or API approvals',
                'Works with any bank that originates ACH',
            ],
            'cons'                     => [
                'Tenant must manually upload the file to their bank portal',
                'Status tracking is out-of-band (bank rec only)',
                'Requires an ACH origination agreement with the tenant\'s bank',
            ],
        ];
    }

    // -------------------------------------------------------------------
    // Internal record builders
    // -------------------------------------------------------------------

    /** Validate a single RailItem. Throws on bad input. */
    private function validateItem(array $it, int $i): void
    {
        $req = ['external_ref','recipient_name','account_routing','account_number','account_type','amount_cents','sec_code'];
        foreach ($req as $f) {
            if (!isset($it[$f]) || $it[$f] === '') {
                throw new PaymentRailsOriginateException("Item {$i} missing required field: {$f}");
            }
        }
        $aba = preg_replace('/\D/', '', (string) $it['account_routing']);
        if (strlen($aba) !== 9) {
            throw new PaymentRailsOriginateException("Item {$i} routing must be 9 digits: {$it['account_routing']}");
        }
        if (!$this->validRoutingChecksum($aba)) {
            throw new PaymentRailsOriginateException("Item {$i} routing checksum invalid: {$aba}");
        }
        if (!in_array($it['account_type'], ['checking','savings'], true)) {
            throw new PaymentRailsOriginateException("Item {$i} account_type must be checking|savings");
        }
        if ((int) $it['amount_cents'] <= 0) {
            throw new PaymentRailsOriginateException("Item {$i} amount_cents must be > 0");
        }
    }

    /** ABA routing-number Luhn-style checksum (3,7,1 weighting per NACHA). */
    private function validRoutingChecksum(string $aba): bool
    {
        if (strlen($aba) !== 9) return false;
        $w = [3,7,1,3,7,1,3,7,1];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) $sum += ((int) $aba[$i]) * $w[$i];
        return $sum % 10 === 0;
    }

    /** Fixed-width pad. */
    private function pad(string $s, int $n, string $align = 'left'): string
    {
        $s = preg_replace('/[^A-Za-z0-9 \-\.\&\/]/', '', $s) ?? '';
        if (strlen($s) > $n) return substr($s, 0, $n);
        return $align === 'left' ? str_pad($s, $n, ' ', STR_PAD_RIGHT) : str_pad($s, $n, ' ', STR_PAD_LEFT);
    }

    private function padNum(int $n, int $width): string
    {
        return str_pad((string) $n, $width, '0', STR_PAD_LEFT);
    }

    private function fileHeader(string $originRouting, string $immediateOrigin, string $effectiveDate): string
    {
        // Record Type 1
        $fileIdMod = 'A'; // first file of the day
        $now       = strtotime($effectiveDate) ?: time();
        $line  = '1';                                                          // Record Type
        $line .= '01';                                                         // Priority Code
        $line .= ' ' . str_pad(substr(preg_replace('/\D/','',$originRouting),0,9), 9, '0', STR_PAD_LEFT); // Immediate Destination (10 chars, leading space)
        $line .= ' ' . str_pad(substr(preg_replace('/\D/','',$immediateOrigin),0,9), 9, '0', STR_PAD_LEFT); // Immediate Origin (10 chars)
        $line .= date('ymd', $now);                                            // File Creation Date
        $line .= date('Hi',  $now);                                            // File Creation Time
        $line .= $fileIdMod;                                                   // File ID Modifier
        $line .= '094';                                                        // Record Size
        $line .= '10';                                                         // Blocking Factor
        $line .= '1';                                                          // Format Code
        $line .= $this->pad('CoreFlux Bank', 23);                              // Immediate Destination Name
        $line .= $this->pad('CoreFlux',      23);                              // Immediate Origin Name
        $line .= $this->pad('',              8);                               // Reference Code
        return $line;
    }

    private function batchHeader(int $batchNo, string $companyName, string $companyId, string $sec, string $effectiveDate, string $originRouting): string
    {
        $serviceClass = '220'; // ACH Credits Only
        $entryDesc    = $sec === 'ppd' ? 'PAYROLL ' : 'VEND PMT';
        $line  = '5';                                                          // Record Type
        $line .= $serviceClass;
        $line .= $this->pad($companyName, 16);                                 // Company Name
        $line .= $this->pad('',           20);                                 // Company Discretionary Data
        $line .= $this->pad($companyId,   10);                                 // Company Identification (already padded)
        $line .= strtoupper($sec === 'ppd' ? 'PPD' : 'CCD');                   // Standard Entry Class
        $line .= $this->pad($entryDesc,   10);                                 // Company Entry Description
        $line .= date('ymd');                                                  // Company Descriptive Date
        $line .= date('ymd', strtotime($effectiveDate) ?: time());             // Effective Entry Date
        $line .= '   ';                                                        // Settlement Date (3, blank — bank fills)
        $line .= '1';                                                          // Originator Status Code
        $line .= str_pad(substr(preg_replace('/\D/','',$originRouting),0,8), 8, '0', STR_PAD_LEFT); // Originating DFI ID
        $line .= $this->padNum($batchNo, 7);                                   // Batch Number
        return $line;
    }

    private function entryDetail(array $it, string $traceNumber): string
    {
        // Transaction Code: 22 = checking credit, 32 = savings credit
        $tx  = $it['account_type'] === 'savings' ? '32' : '22';
        $aba = preg_replace('/\D/', '', (string) $it['account_routing']);
        $tdb = substr($aba, 0, 8);                                             // Transit / RDFI ID
        $cd  = substr($aba, 8, 1);                                             // Check Digit
        $acc = $this->pad((string) $it['account_number'], 17);
        $line  = '6';                                                          // Record Type
        $line .= $tx;
        $line .= $tdb;
        $line .= $cd;
        $line .= $acc;
        $line .= $this->padNum((int) $it['amount_cents'], 10);                 // Amount in cents (10)
        $line .= $this->pad(substr((string) $it['external_ref'], 0, 15), 15);  // Individual ID Number
        $line .= $this->pad((string) $it['recipient_name'], 22);               // Individual Name
        $line .= '  ';                                                         // Discretionary Data (2)
        $line .= '0';                                                          // Addenda Indicator (0 = none)
        $line .= str_pad($traceNumber, 15, '0', STR_PAD_LEFT);                 // Trace Number
        return $line;
    }

    private function batchControl(int $batchNo, string $companyId, int $entries, int $entryHash, int $creditCents, string $originRouting): string
    {
        $line  = '8';
        $line .= '220';                                                        // Service Class Code
        $line .= $this->padNum($entries, 6);                                   // Entry / Addenda count
        $line .= $this->padNum($entryHash % 10000000000, 10);                  // Entry Hash
        $line .= $this->padNum(0, 12);                                         // Total Debit ($)
        $line .= $this->padNum($creditCents, 12);                              // Total Credit ($)
        $line .= $this->pad($companyId, 10);                                   // Company ID
        $line .= $this->pad('', 19);                                           // Message Authentication Code
        $line .= $this->pad('', 6);                                            // Reserved
        $line .= str_pad(substr(preg_replace('/\D/','',$originRouting),0,8), 8, '0', STR_PAD_LEFT); // Originating DFI ID
        $line .= $this->padNum($batchNo, 7);                                   // Batch Number
        return $line;
    }

    private function fileControl(int $batchCount, int $entryCount, int $entryHash, int $creditCents, int $recordCountSoFar): string
    {
        // Block count is the integer ceiling of record count / 10.
        $blockCount = (int) ceil($recordCountSoFar / 10);
        $line  = '9';
        $line .= $this->padNum($batchCount,  6);                               // Batch Count
        $line .= $this->padNum($blockCount,  6);                               // Block Count
        $line .= $this->padNum($entryCount, 8);                                // Entry / Addenda Count
        $line .= $this->padNum($entryHash % 10000000000, 10);                  // Entry Hash
        $line .= $this->padNum(0, 12);                                         // Total Debit
        $line .= $this->padNum($creditCents, 12);                              // Total Credit
        $line .= $this->pad('', 39);                                           // Reserved
        return $line;
    }

    /** Pad with 9-filler 94-char lines so total record count is a multiple of 10. */
    private function padToBlocks(array $records): array
    {
        $remainder = count($records) % 10;
        if ($remainder === 0) return $records;
        $filler = str_repeat('9', 94);
        for ($i = 0; $i < (10 - $remainder); $i++) $records[] = $filler;
        return $records;
    }
}
