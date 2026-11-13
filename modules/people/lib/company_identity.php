<?php
/** Resolve reviewed company-number aliases without ever rebinding provider IDs. */
declare(strict_types=1);

function companyIdentityResolve(PDO $pdo, int $owner, int $sourceId, bool $lock = false): ?array
{
    if ($owner <= 0 || $sourceId <= 0 || ($lock && !$pdo->inTransaction())) {
        throw new InvalidArgumentException('A scoped company identity and transaction are required.');
    }
    $suffix = $lock ? ' FOR UPDATE' : '';
    $q = $pdo->prepare('SELECT * FROM companies WHERE tenant_id=? AND id=?'.$suffix);
    $q->execute([$owner,$sourceId]);
    $source = $q->fetch(PDO::FETCH_ASSOC);
    if (!$source) return null;
    $q = $pdo->prepare('SELECT record_number,canonical_record_id,aliased_at FROM core_record_numbers WHERE tenant_id=? AND entity_type="company" AND record_id=?'.$suffix);
    $q->execute([$owner,$sourceId]);
    $number = $q->fetch(PDO::FETCH_ASSOC);
    $canonical = $number ? (int)$number['canonical_record_id'] : $sourceId;
    if ($source['deleted_at'] === null) {
        if ($canonical !== $sourceId) throw new RuntimeException('An active company has a conflicting number alias. Review its identity.',409);
        return ['id'=>$sourceId,'source_id'=>$sourceId,'merged'=>false,'company'=>$source,
            'source_company'=>$source,'company_number'=>$number['record_number']??null,'source_number'=>$number['record_number']??null];
    }
    // Ordinary deletion is not a merge and must never resurrect a source record.
    if (!$number || $canonical === $sourceId || $canonical <= 0 || $number['aliased_at'] === null) return null;
    $q = $pdo->prepare('SELECT * FROM companies WHERE tenant_id=? AND id=? AND deleted_at IS NULL'.$suffix);
    $q->execute([$owner,$canonical]);
    $target = $q->fetch(PDO::FETCH_ASSOC);
    $q = $pdo->prepare('SELECT record_number,canonical_record_id FROM core_record_numbers WHERE tenant_id=? AND entity_type="company" AND record_id=?'.$suffix);
    $q->execute([$owner,$canonical]);
    $targetNumber = $q->fetch(PDO::FETCH_ASSOC);
    if (!$target || !$targetNumber || (int)$targetNumber['canonical_record_id'] !== $canonical) {
        throw new RuntimeException('The merged company is unavailable in this directory. Review its identity.',409);
    }
    return ['id'=>$canonical,'source_id'=>$sourceId,'merged'=>true,'company'=>$target,
        'source_company'=>$source,'company_number'=>$targetNumber['record_number'],'source_number'=>$number['record_number']];
}
