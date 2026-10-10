<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Documents\Application\Service\VerifiedSignatureAttestationVerifier as Verifier;

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($key === false) throw new RuntimeException('OpenSSL cannot generate test-only signer keys.');
$private = '';
openssl_pkey_export($key, $private);
$public = openssl_pkey_get_details($key)['key'];
$now = new DateTimeImmutable('2026-10-09T12:00:00+00:00');
$claims = [
    'issuer' => 'trusted-test-provider',
    'audience' => Verifier::AUDIENCE,
    'organization_id' => 'tenant-a',
    'document_id' => 'DOC-a',
    'signature_id' => 'SIG-a',
    'signer_id' => 'human-signer',
    'document_version_id' => 'VER-1',
    'file_sha256' => str_repeat('f', 64),
    'proof_id' => 'proof-verified-1',
    'run_id' => 'run-test-01',
    'signature_reference' => 'external-proof-reference',
    'signed_at' => '2026-10-09T11:59:00Z',
    'issued_at' => '2026-10-09T12:00:00Z',
    'expires_at' => '2026-10-09T12:30:00Z',
];
$encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
$issue = static function (array $claims) use ($private, $encode): string {
    $raw = json_encode($claims, JSON_THROW_ON_ERROR);
    if (!openssl_sign($raw, $signature, $private, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Fixture signer failed.');
    }
    return json_encode([
        'format' => 'cos-docs-rs256-v1',
        'payload' => $encode($raw),
        'signature' => $encode($signature),
    ], JSON_THROW_ON_ERROR);
};
$verify = new Verifier();
$input = [$issue($claims), $public, 'trusted-test-provider', 'tenant-a', 'DOC-a',
    'SIG-a', 'human-signer', 'VER-1', str_repeat('f', 64), $now,
    'run-test-01', 'proof-verified-1'];
$verified = $verify->verify(...$input);
if ($verified['proof_id'] !== 'proof-verified-1'
    || $verified['signature_reference'] !== 'external-proof-reference'
    || $verified['issuer'] !== 'trusted-test-provider') {
    throw new RuntimeException('Cryptographic signer evidence was not verified correctly.');
}
$deny = static function (array $args, string $case) use ($verify): void {
    try {
        $verify->verify(...$args);
        throw new RuntimeException('Forged signer attestation was accepted: ' . $case);
    } catch (DomainException) {}
};
foreach ([3 => 'tenant-b', 4 => 'DOC-wrong', 5 => 'SIG-wrong', 6 => 'different-signer',
    7 => 'different-version', 8 => str_repeat('a', 64), 2 => 'untrusted-issuer',
    10 => 'another-run', 11 => 'different-proof-id'] as $index => $replacement) {
    $changed = $input;
    $changed[$index] = $replacement;
    $deny($changed, 'context-' . $index);
}
$mutated = json_decode($input[0], true);
$mutated['payload'] = $encode(str_replace('DOC-a', 'DOC-b', json_encode($claims, JSON_THROW_ON_ERROR)));
$changed = $input;
$changed[0] = json_encode($mutated, JSON_THROW_ON_ERROR);
$deny($changed, 'tampered-payload');
foreach ([
    ['signed_at' => '2026-10-07T11:59:00Z'],
    ['expires_at' => '2026-10-09T11:59:00Z'],
    ['issued_at' => '2026-10-09T12:03:00Z'],
    ['audience' => 'another-application'],
] as $overrides) {
    $changed = $input;
    $changed[0] = $issue(array_replace($claims, $overrides));
    $deny($changed, 'signed-but-invalid-claims');
}
echo "Documents signer attestation: RSA proof, tenant, signer, version, file-hash and time gates passed.\n";
