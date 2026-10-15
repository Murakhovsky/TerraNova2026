<?php
declare(strict_types=1);
/** P0 Federation Migration Safety: forbidden replay paths and separated proofs. */
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$cutover=$read('symfony/src/Persistence/Federation/FederationHistoricalRunCutover.php');
$auth=$read('symfony/src/Persistence/Federation/FederationMigrationOperatorAuthorization.php');
$integrity=$read('symfony/src/Persistence/Federation/FederationPostCutoverIntegrity.php');
$command=$read('symfony/src/Command/FederationHistoricalCutoverCommand.php');
$migration=$read('symfony/migrations/Version20261015183000.php');
foreach([
  [$cutover,'cutover_parity'],
  [$cutover,'post_cutover_integrity'],
  [$cutover,'operatorAuthorization->assertAuthorized'],
  [$cutover,'verified_historical_receipt_archive_only'],
  [$cutover,'assertCompletedReceipt'],
  [$cutover,'cos_federation_migration_audit'],
  [$auth,'cos_organization_memberships'],
  [$auth,'hash_equals(hash_hmac'],
  [$command,'fileperms('],
  [$auth,'COS_FEDERATION_MIGRATION_AUTHORITY_KEY'],
  [$integrity,'canonical_immutable_identity_changed'],
  [$integrity,'completed_receipt_requires_reconciliation'],
  [$integrity,'attestForAudit'],
  [$integrity,'nonActions->assertVerified'],
  [$command,"addOption('permit-file'"],
  [$migration,'canonical_identity_sha256'],
  [$migration,'permit_sha256'],
] as [$source,$needle]){
  if(!str_contains($source,$needle))
    throw new RuntimeException('Federation Migration Safety acceptance missing: '.$needle);
}
if(str_contains($cutover,'$this->parity->federation($org,$runId)->status->value :') ||
   str_contains($integrity,'ActionService') ||
   str_contains($integrity,'->queue(') ||
   str_contains($integrity,'->submit(') ||
   str_contains($cutover,'->queue(') ||
   str_contains($cutover,'->submit(')){
   throw new RuntimeException('Historical integrity/reconciliation must be read only.');
}
echo "Federation migration safety architecture contracts passed.\n";
