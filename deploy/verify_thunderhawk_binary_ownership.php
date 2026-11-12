<?php
/** Rollback-only regression against the isolated Core staging runtime. */
declare(strict_types=1);
const BINARY_OWNERSHIP_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const BINARY_OWNERSHIP_PRIVATE = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 1003 || getcwd() !== BINARY_OWNERSHIP_ROOT
    || getenv('COREFLUX_ENV') !== 'staging' || ($argv[1] ?? '') !== '--verify-isolated-binary-ownership'
    || !preg_match('/^[a-f0-9]{64}$/D', $argv[2] ?? '')) exit(2);
require_once BINARY_OWNERSHIP_ROOT . '/core/db.php';
require_once BINARY_OWNERSHIP_ROOT . '/core/integrations/identity.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx' || !COREFLUX_STAGING
    || integrationInstanceId() !== '9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    || COREFLUX_INTEGRATION_DELIVERY_TENANTS !== '2002'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn() !== 1) exit(2);
$fixture = __DIR__ . '/binary-ownership-fixture.php';
if (is_link($fixture) || !hash_equals($argv[2], hash_file('sha256', $fixture))) exit(2);
$install = json_decode(file_get_contents(BINARY_OWNERSHIP_PRIVATE . '/binary-ownership-install-result.json'), true, 64, JSON_THROW_ON_ERROR);
if (($install['passed'] ?? false) !== true) exit(2);
foreach (json_decode('{"modules/placements/lib/end.php":"ac4ea08bafdc616808e0d95c4ad3c04ba203a2a57013ea66d7d688cb83b1d93f","modules/placements/lib/placements.php":"f7e2574c4afb3c40dacaa3305247099d0d2b9c052e93451eea7fb4f3e9f554bc","core/staffing_economics.php":"3e0da7dcd16056153ae10b0fbcfb5ce44a31598d00ef0fdd125cba7291505795","core/tenant_scope.php":"93f970e3e27a67694350f932fc51bc937b6d6837839cc50ca4d486a5073ac143","core/audit.php":"e1a4acf587ce7a21a7cfffb8a1c19095f1c22ea1f42727b28c75a4941d83ab42","modules/people/lib/companies.php":"86dbdcf8422b429d54b8791a255da2bfc19627e34e2b917f14f70b42ba65be3e","core/record_numbers.php":"a1a48d8e705ab1d7ef15649e740078166911fdac81df3e297fbdc4710797fe15","core/sub_tenants.php":"93708814436bbd06c7a560db1c65972cf0f706978aea4a64ea234bb652971658","core/api_bootstrap.php":"f2ca7d9e2558a374159c10f9d10d3da407874620e8ad211544ead6c187df0579","core/RBAC.php":"fea568b164d652dde0cc1f5684d1924202e174167325d1830cce9de958457cad","core/jobdiva/client.php":"7a67fe2d44118cb6451a69fdc657bdcdc997e4b61c9f92f235743e916875f30c","core/jobdiva/dates.php":"ef0a8db601d73700aacf8df190f1c7ed0bf7158460e6bfcc0f16c1f37b5e8a58","core/jobdiva/assignment_identity.php":"090748f8504155ccb2f157a3d21018114b17bc0156fad19971cb1fc000292685","core/jobdiva/assignment_contract.php":"02b174f84356e42f3f2eb53a2cb0ba2d9468bd5798884390fd5902e254252910","core/jobdiva/classification_mapping.php":"17085fd7efba296ed90eec7b6ba1717697f3e24a6dda6203519c5a7976c25b97","core/jobdiva/canonical_graph.php":"ec888ecda374a76a2338f15b9d8b0657b214d5f719db288b2ddf27b416b54dfe","core/jobdiva/projector.php":"7e755642fe01d3758a44d41858b84d28aa78362864445456f0625196668ded1e","core/integrations/field_map.php":"1cbcfd439c3f9ab0e3f44e55a039328925c434333d118ae6dd0d9cfb5cb5d521","core/integrations/source_values.php":"e0c31f420ef53dad84c65493637b6a2219256362b35b12ca8d97cc058a4848a9","core/integrations/entity_mappings.php":"f4074fd4aa9529fdeb00e8c2f200f49577850d5d6977eba60fd203b3307bf455","core/integrations/payload_field_index.php":"b50f094f128fffe17046fa110c7ec3fbb1b14397a669a203b29241e5a1760f14","modules/staffing/lib/clients.php":"ab2e3023efdc4ae441c681172ec4ba0dcab7b0dd701ca3ba0369c12971178a2c","modules/staffing/lib/jobs.php":"4fa53c75b86bf59207a8b2ec441988dc8d7461e92b7be27f93c011972222587d","modules/accounting/lib/accounting.php":"7803cd2912d2cd4cf5893750d34090d2a6922741ad7c6158b218a74bb7ec201b","core/jobdiva/sync.php":"e75bc552e4b0796b29d1776b7633b0ca0cedc43daf2bbc760c9b87655ddeeb09","modules/placements/lib/ownership.php":"37d797f2cf7a045230d03809634ecd262b7e7b53005254dd7c08ac2950ca8a76","modules/placements/lib/edit.php":"7f468d24a6032c6802f1591ef82cf8ebfa5e34ed51be1f42f2cb95f5b0d4ac05","core/jobdiva/stored_review.php":"4bca4f875bc7aceeaf8bdd55738ae7c6f7b898a56c6da8d5920c7056c80ca5e8","core/rbac/permissions.php":"1343e49a42c4d1039ea722e601015f666086ae2694629dc048d4267f8d3d3f59","core/rbac/legacy_map.php":"b9da416fcc63b87ab9cd9a8aaecc9226bbcc43dc1a1b97121bd71b91954dac35","core/memberships.php":"61a0a6c28dd1a3aaf30ed2b3fa631b89599a4e0e80f4431f760c17a34dbd58b2","modules/placements/lib/corp_details.php":"c062ddcb63446eaab6cdf21936a35061ce7ecd9bf395b321a2970313edce2623","modules/placements/api/corp.php":"c874fd04f474766ed8d0887f56c839cbf0697f645bb769346ae61831cd1c71c0","core/integrations/field_map_apply.php":"e0d5372ac278acd4af2e699559106f714722b492aa0b9fe8900008041c2a1cac","modules/placements/lib/economics_participants.php":"e52e799d2434fab9413872fbfdc9bca380764d8d5c98da16999824b9825dcd76","modules/placements/lib/economic_items_edit.php":"e2e21a4397e8b4823963dd539e0661f3fa991d6a0d80dc94f4ea5026d1aea61c","modules/placements/api/economics.php":"2e1bb76d34e8fa34d7e4e149d8944f2a95abe7f7e0ac950915de43646ff1c50d","modules/placements/ui/PlacementDetail.jsx":"87c55921a9ed46fb3793a013f23017ab6feae484557e0515d85fe54195bb21ea","modules/placements/ui/OneTimeItems.jsx":"ffad15e756c8a23c4760a7d697993624f1861ed8ff98e22caeb9ffda7a846191","modules/placements/lib/cycle_assignments.php":"03f68bcda9ec961374b66735ff67a1b6fd154ac84846061d59ba43a7fed47dab","modules/placements/lib/economics.php":"ad051b299fa124871a5d39e9b7ba4ca7c6cbcd1e854f67ac04ae0d60784b8af9","modules/placements/lib/economics_edit.php":"7d02ec88b77ca51a60654a7136e243476cb6740c6fe3515cceae054353f9f31c","core/jobdiva/contract_projection.php":"aa3e4d7cf6229c48d419db5e63257fceb4f021e4d56984a3085c5e72b854b591","modules/payroll/lib/payroll.php":"054fd65ffb780d3523d1ab175c4334591200dd87ef122885ce01933a3c9c22ec","modules/payroll/lib/cycles.php":"a908d57cd1ea89ef3878b3d4bb7c81b95799f168e4f5faaf3964bf4a6f2a57a4","modules/payroll/lib/artifacts.php":"db87958e0d49e2a947a5b2158e230ec2c687fc3578f31976a4826dd20cf44894","modules/people/lib/employees.php":"12b5883158416310b1261fde7a8be3172c61cf6af68f132e94e775d2e5eb1865","core/integrations/purepay_assignment_contract.php":"837549856661ab0b084546f780f6fa655ed6e8f85f52f65cafbc26fcbca734d0","core/integrations/delivery.php":"78e00174fbe5789d3a79cf5f2fc999cbb399f0243ac725adc618873771c96bcd","core/encryption.php":"0724924e9362eb0f5fac3a1f04556c4e85fc66a2b141d04ec8ecd1dbba1f27cf","spa-assets/index-C9Kqh6OI.js":"77c51ce08259e5ba76a52bae14d98b022efbcd98e21887d1b5606756b2876ee1","spa-assets/index-CHmo4T_t.js":"21d5536830008f57dd682a7c7856b9ae060484a289bc690044c215f64294f3ab","spa-assets/index-CYcM0MSD.css":"34076d40e2e528e51f4313b79dc1942a93b9c629e9f426fadd5f8b795d28d9fc","spa-assets/index-SRkxIh9x.css":"e4a8bde4c3c6f480a044435c9c2ff93c61eaef47fad8471d791d3df9fb5976e8","spa-assets/sw.js":"19e999980277149d126b97e45629963c161c6feab0c08464b00e620f8947d721","spa-assets/index.html":"6f12d9822b426d23d68f2f34f03aab618599bac835d4d80c4298ff67ca82c24d","dashboard/dist/index.html":"6f12d9822b426d23d68f2f34f03aab618599bac835d4d80c4298ff67ca82c24d","index.html":"6f12d9822b426d23d68f2f34f03aab618599bac835d4d80c4298ff67ca82c24d",".deploy-version":"3dd2feef4b0f6d43333cd0d1b253612bfb44ca74a398779ce8aae481d50d5015","modules/placements/lib/cycle_edits.php":"22b17a1cd3c0a74cb556b4251962abd7fa4cef318278e1a998a849529311970c","modules/placements/api/cycles.php":"0f6c48ba492dc770d61b2f1e50ecb509392c0f193f00b44ac70d4f23aeb4d533","modules/payroll/lib/staffing_cycle_guard.php":"27975577befe779e2ef24c9086fd3db72e881e63fb4e0ed02fadae11f4876a9f","modules/payroll/api/cycles.php":"ee49527cd6219e595d28eb17626f6af99ad02ba443c86e01f082eec1d7ffabce","modules/payroll/api/pay_schedules.php":"af9479c63be84cda2f570f4a9254a103cc2779e035b6cf7fdf5b573dc2dfee9d","modules/placements/lib/commercial_ownership.php":"3d0691ba7b5fb2324c59453f4f4dd6106ad93c2ade92c3050d12884e83970874"}',true) as $file=>$expected) {
    if (is_link(BINARY_OWNERSHIP_ROOT.'/'.$file) || hash('sha256',str_replace("\r\n","\n",file_get_contents(BINARY_OWNERSHIP_ROOT.'/'.$file)))!==$expected) exit(2);
}
$lock = fopen(BINARY_OWNERSHIP_PRIVATE . '/contact-install.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(2);
if ((int) $pdo->query("SELECT COUNT(*) FROM placements WHERE tenant_id=2002 AND external_id IN ('99183001','jd:99183001')")->fetchColumn() !== 0) exit(2);
$binaryOwnershipReceipt = BINARY_OWNERSHIP_PRIVATE . '/binary-ownership-hosted-result.json';
if (file_exists($binaryOwnershipReceipt)) exit(2);
function corporateIdentitySnapshot(PDO $pdo): array {
    $result = [];
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Unexpected table');
        $hashes = [];
        foreach ($pdo->query('SELECT * FROM `' . $table . '`') as $row) $hashes[] = hash('sha256', serialize($row));
        sort($hashes);
        $result[$table] = ['rows' => count($hashes), 'hash' => hash('sha256', implode("\n", $hashes))];
    }
    ksort($result);
    return $result;
}
$report = ['checked_at' => gmdate('c'), 'source_commit' => $install['source_commit'], 'passed' => false,
    'status' => 'running', 'pid' => getmypid(), 'tenant_id' => 2002,
    'provider_changed' => false, 'production_changed' => false, 'financial_postings' => false,
    'test_data_policy' => 'Synthetic rows enclosed in an outer transaction and rolled back; sequence gaps are allowed.'];
file_put_contents($binaryOwnershipReceipt, json_encode($report, JSON_THROW_ON_ERROR));
$corporateIdentityBefore = corporateIdentitySnapshot($pdo);
ob_start();
try {
    require $fixture;
    $log = ob_get_contents();
    if (!str_contains($log, '41 corporate identity ownership checks passed.')) throw new RuntimeException('Regression did not finish');
    if ($pdo->inTransaction()) throw new RuntimeException('Fixture transaction still open');
    if (corporateIdentitySnapshot($pdo) !== $corporateIdentityBefore) throw new RuntimeException('Original database rows changed');
    $report += ['binary_ownership_checks_passed' => 41, 'tables_verified' => count($corporateIdentityBefore),
        'original_rows_preserved' => array_sum(array_column($corporateIdentityBefore, 'rows')), 'synthetic_rows_rolled_back' => true];
    $report['passed'] = true;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $report['failure'] = $e->getMessage();
    $report['original_rows_preserved_after_failure'] = corporateIdentitySnapshot($pdo) === $corporateIdentityBefore;
} finally {
    $log = ob_get_clean();
    file_put_contents(__DIR__ . '/binary-ownership-hosted.log', $log);
    $report['status'] = $report['passed'] ? 'complete' : 'failed';
    file_put_contents($binaryOwnershipReceipt, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
