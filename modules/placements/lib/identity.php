<?php
/** Reviewed number aliases resolve current identity without replacing historical placement rows. */
declare(strict_types=1);

function placementIdentityResolve(PDO $pdo, int $owner, int $sourceId, bool $lock = false): ?array
{
    if ($owner <= 0 || $sourceId <= 0 || ($lock && !$pdo->inTransaction())) {
        throw new InvalidArgumentException('A scoped placement identity and transaction are required.');
    }
    $suffix = $lock ? ' FOR UPDATE' : '';
    $read = static function(string $table, int $id) use ($pdo, $owner, $suffix): ?array {
        $sql = $table === 'placements'
            ? 'SELECT * FROM placements WHERE tenant_id=? AND id=?'
            : 'SELECT * FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=?';
        $q = $pdo->prepare($sql.$suffix);
        $q->execute([$owner,$id]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) throw new RuntimeException('The placement has conflicting permanent identities. Review its numbers.',409);
        return $rows[0] ?? null;
    };
    $source = $read('placements',$sourceId);
    if (!$source) return null;
    $number = $read('core_record_numbers',$sourceId);
    if (!$number) throw new RuntimeException('The placement has no permanent number in its authorized directory.',409);
    $canonical = (int)$number['canonical_record_id'];
    if ($source['deleted_at'] === null) {
        if ($canonical !== $sourceId || ($number['aliased_at'] ?? null) !== null) {
            throw new RuntimeException('An active placement has a conflicting number alias. Review its identity.',409);
        }
        return ['id'=>$sourceId,'source_id'=>$sourceId,'merged'=>false,'placement'=>$source,
            'source_placement'=>$source,'placement_number'=>$number['record_number'],'source_number'=>$number['record_number']];
    }
    // An archive alone is not a reviewed merge and must not regain operational access.
    if ($canonical === $sourceId) return null;
    if ($canonical <= 0 || ($number['aliased_at'] ?? null) === null) {
        throw new RuntimeException('The archived placement has an incomplete number alias. Review its identity.',409);
    }
    $target = $read('placements',$canonical);
    $targetNumber = $read('core_record_numbers',$canonical);
    // Merges flatten aliases. Do not chase a chain, cross a directory, or revive an archived target.
    if (!$target || $target['deleted_at'] !== null || !$targetNumber
        || (int)$targetNumber['canonical_record_id'] !== $canonical || ($targetNumber['aliased_at'] ?? null) !== null) {
        throw new RuntimeException('The merged placement is unavailable in this directory. Review its identity.',409);
    }
    return ['id'=>$canonical,'source_id'=>$sourceId,'merged'=>true,'placement'=>$target,
        'source_placement'=>$source,'placement_number'=>$targetNumber['record_number'],'source_number'=>$number['record_number']];
}

/** Resolve the stored number, never infer its physical ID from the display suffix. */
function placementNumberIdentityResolve(PDO $pdo, int $owner, string $number, bool $lock = false): ?array
{
    if ($owner <= 0 || !preg_match('/^PL-[1-9][0-9]*$/D',$number) || ($lock && !$pdo->inTransaction())) {
        throw new InvalidArgumentException('A scoped permanent placement number and transaction are required.');
    }
    $q = $pdo->prepare('SELECT record_id FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_number=?'.($lock?' FOR UPDATE':''));
    $q->execute([$owner,$number]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return null;
    if (count($ids) !== 1) throw new RuntimeException('This number has conflicting placement identities.',409);
    $identity = placementIdentityResolve($pdo,$owner,(int)$ids[0],$lock);
    if ($identity && $identity['source_number'] !== $number) throw new RuntimeException('The placement number changed during lookup. Review its identity.',409);
    return $identity;
}
