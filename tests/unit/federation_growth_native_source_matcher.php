<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Platform\Orchestration\Goal\FederationDiscoverySourceMatcher;

$matcher = new FederationDiscoverySourceMatcher();
$org = 'tenant-50';
$universe = 'universe-50';
$run = 'GMRN-50';
$members = [];
for ($i=1;$i<=50;$i++) {
    $suffix = sprintf('%03d',$i);
    $members[] = [
        'organization_id'=>$org,'universe_id'=>$universe,
        'candidate_id'=>'C-'.$suffix,'account_id'=>'A-'.$suffix,
        'external_key_hash'=>hash('sha256','source-'.$suffix),
        'source_reference'=>'https://example.test/account/'.$suffix,
    ];
}
$brief = [
    'universe'=>['organization_id'=>$org,'universe_id'=>$universe,'target_domain'=>'sales'],
    'runs'=>[['organization_id'=>$org,'universe_id'=>$universe,'run_id'=>$run,'status'=>'completed']],
    'memberships'=>$members,
];
foreach ($members as $member) {
    $matcher->assertMatch($org,$universe,$run,$member['candidate_id'],
        $member['account_id'],$member['external_key_hash'],$brief);
}
$reject = static function (array $snapshot,string $candidate,string $account,string $hash) use ($matcher,$org,$universe,$run):void {
    try {
        $matcher->assertMatch($org,$universe,$run,$candidate,$account,$hash,$snapshot);
        throw new RuntimeException('Forged Growth source evidence was accepted.');
    } catch (DomainException) {}
};
$reject($brief,'C-001','A-002',$members[0]['external_key_hash']);
$reject($brief,'C-001','A-001',$members[1]['external_key_hash']);
$reject($brief,'C-999','A-999',hash('sha256','source-999'));
$duplicate = $brief;
$duplicate['memberships'][] = $members[0];
$reject($duplicate,'C-001','A-001',$members[0]['external_key_hash']);
$foreign = $brief;
$foreign['memberships'][0]['organization_id']='other-tenant';
$reject($foreign,'C-001','A-001',$members[0]['external_key_hash']);
$partial = $brief;
$partial['runs'][0]['status']='partial';
$reject($partial,'C-001','A-001',$members[0]['external_key_hash']);
$wrongUniverse = $brief;
$wrongUniverse['universe']['target_domain']='capital_markets';
$reject($wrongUniverse,'C-001','A-001',$members[0]['external_key_hash']);
echo "Federation Growth native provenance matcher: fifty source identities and negative cases PASS.\n";
