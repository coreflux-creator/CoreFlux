<?php
/** Read an explicitly linked commission recipient; never infer identity from contact data. */
declare(strict_types=1);
require_once __DIR__.'/../../people/lib/employment_links.php';

function placementCommissionPersonIdentity(PDO $pdo,int $peopleOwner,int $userId): ?int
{
    if ($peopleOwner<=0 || $userId<=0 || !peopleEmploymentSchema($pdo)) return null;
    // Include contradictory person-account links in the candidate set. Filtering
    // them out could turn an ambiguous account into an apparently unique match.
    $s=$pdo->prepare('SELECT p.id,p.user_id AS person_user_id,p.classification,
        e.id AS employee_id,e.user_id AS employee_user_id,n.canonical_record_id
        FROM people p
        LEFT JOIN people_employment_links l ON l.tenant_id=p.tenant_id AND l.person_id=p.id
        LEFT JOIN people_employees e ON e.tenant_id=l.tenant_id AND e.id=l.employee_id
        LEFT JOIN core_record_numbers n ON n.tenant_id=p.tenant_id AND n.entity_type="person" AND n.record_id=p.id
        WHERE p.tenant_id=? AND p.deleted_at IS NULL AND (p.user_id=? OR e.user_id=?)
        ORDER BY p.id LIMIT 2');
    $s->execute([$peopleOwner,$userId,$userId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)return null;
    $r=$rows[0];
    if(strtolower((string)$r['classification'])!=='w2' || empty($r['employee_id'])
        || (int)$r['employee_user_id']!==$userId
        || (!empty($r['person_user_id']) && (int)$r['person_user_id']!==$userId)
        || (int)$r['canonical_record_id']!==(int)$r['id'])return null;
    return (int)$r['id'];
}
