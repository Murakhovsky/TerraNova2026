<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root.'/'.$path);

$migration = $read('symfony/migrations/Version20261004143000.php');
$settings = $read('app/Platform/Settings/Service/PlatformSettingsService.php');
$crypto = $read('app/Infrastructure/Platform/Security/SodiumSecretEncryption.php');
$repo = $read('app/Infrastructure/Platform/Persistence/MySql/Settings/MysqlPlatformSettingsRepository.php');
$routes = $read('symfony/config/routes.yaml');
$controller = $read('symfony/src/Web/Administration/PlatformSettingsController.php');
$services = $read('symfony/config/services.yaml');
$openai = $read('app/Infrastructure/Llm/OpenAiResponsesStructuredLlmClient.php');
$factory = $read('symfony/src/Engineering/Application/Agent/EngineeringAgentDefinitionFactory.php');

foreach (['cos_platform_settings','cos_platform_secrets','organization_id','namespace','setting_key','secret_key','ciphertext','nonce','encryption_version','key_id','updated_by'] as $needle) {
    if (!str_contains($migration, $needle)) throw new RuntimeException('Platform Settings migration missing '.$needle);
}
foreach (['XCHACHA20POLY1305','associatedData','COS_SECRET_MASTER_KEY'] as $needle) {
    if (!str_contains($crypto.$services, $needle)) throw new RuntimeException('Platform Settings encryption contract missing '.$needle);
}
foreach (['PLATFORM_SETTINGS','SETTING_UPDATED','SECRET_REPLACED','SETTING_DELETED','SECRET_DELETED','deleteSecret'] as $needle) {
    if (!str_contains($settings, $needle)) throw new RuntimeException('Platform Settings audit contract missing '.$needle);
}
foreach (['/admin/settings','/admin/settings/llm','SessionCsrfValidator','isAdmin()','openai.api_key'] as $needle) {
    if (!str_contains($routes.$controller, $needle)) throw new RuntimeException('Platform Settings admin contract missing '.$needle);
}
foreach (['PlatformSettingsReaderInterface','openai.api_key','timeout_seconds','max_attempts'] as $needle) {
    if (!str_contains($openai, $needle)) throw new RuntimeException('OpenAI runtime settings contract missing '.$needle);
}
foreach (['manager.model','architect.model','developer.model','reviewer.model','qa.model'] as $needle) {
    if (!str_contains($factory, $needle)) throw new RuntimeException('Engineering model settings contract missing '.$needle);
}
if (str_contains($controller, "name=\"openai_api_key\" value=")) throw new RuntimeException('Secret must never be rendered back into the admin form.');

echo "Platform Settings architecture contract passed.\n";
