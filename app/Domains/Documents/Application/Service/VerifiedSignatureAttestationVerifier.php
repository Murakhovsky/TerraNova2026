<?php
declare(strict_types=1);

namespace Domains\Documents\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use JsonException;

/**
 * Cryptographic boundary for future independent signer/provider attestations.
 * An arbitrary signature_reference is NEVER sufficient proof.
 *
 * This verifier does NOT initiate, impersonate or certify a legal signature.
 * Provider transport and one-time proof consumption are separate, mandatory
 * integration boundaries before enabling documents.signature.sign.
 */
final class VerifiedSignatureAttestationVerifier
{
    public const AUDIENCE = 'cos.documents.signature-proof.v1';

    /**
     * @return array<string,string> Verified claims only; never trust raw JSON alone.
     */
    public function verify(
        string $envelopeJson,
        string $trustedPublicKeyPem,
        string $trustedIssuer,
        string $organizationId,
        string $documentId,
        string $signatureId,
        string $signerId,
        string $currentVersionId,
        string $currentFileSha256,
        ?DateTimeImmutable $now = null,
        ?string $expectedRunId = null,
        ?string $expectedProofId = null,
    ): array {
        if ($envelopeJson === '' || strlen($envelopeJson) > 16384
            || $trustedIssuer === '' || $organizationId === ''
            || $documentId === '' || $signatureId === '' || $signerId === ''
            || $currentVersionId === '' || !preg_match('/^[a-f0-9]{64}$/D', $currentFileSha256)) {
            throw new DomainException('Missing or oversized trusted signing verification context.');
        }
        try {
            $envelope = json_decode($envelopeJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DomainException('Malformed signature attestation envelope.', 0, $e);
        }
        if (!is_array($envelope) || array_is_list($envelope)
            || count($envelope) !== 3
            || ($envelope['format'] ?? null) !== 'cos-docs-rs256-v1'
            || !is_string($envelope['payload'] ?? null)
            || !is_string($envelope['signature'] ?? null)) {
            throw new DomainException('Unsupported or incomplete signing attestation envelope.');
        }
        $payload = self::decodeBase64Url($envelope['payload'], 12288);
        $signature = self::decodeBase64Url($envelope['signature'], 4096);
        $key = openssl_pkey_get_public($trustedPublicKeyPem);
        $details = $key !== false ? openssl_pkey_get_details($key) : false;
        if ($key === false || !is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || (int) $details['bits'] < 2048
            || openssl_verify($payload, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new DomainException('Attestation must be RS256-verified against a pinned provider RSA public key.');
        }
        try {
            $claims = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DomainException('Signed attestation contains malformed JSON.', 0, $e);
        }
        $expected = [
            'issuer' => $trustedIssuer,
            'audience' => self::AUDIENCE,
            'organization_id' => $organizationId,
            'document_id' => $documentId,
            'signature_id' => $signatureId,
            'signer_id' => $signerId,
            'document_version_id' => $currentVersionId,
            'file_sha256' => $currentFileSha256,
        ];
        if ($expectedRunId !== null) $expected['run_id'] = $expectedRunId;
        if ($expectedProofId !== null) $expected['proof_id'] = $expectedProofId;
        if (!is_array($claims) || array_is_list($claims)) {
            throw new DomainException('Signed attestation payload must be a claim object.');
        }
        foreach ($expected as $key => $value) {
            if (($claims[$key] ?? null) !== $value) {
                throw new DomainException('Signer attestation does not match the trusted document context: ' . $key);
            }
        }
        foreach (['proof_id', 'signature_reference', 'issued_at', 'signed_at', 'expires_at'] as $required) {
            if (!is_string($claims[$required] ?? null) || trim($claims[$required]) === '') {
                throw new DomainException('Signer attestation lacks a mandatory verified claim: ' . $required);
            }
        }
        if (strlen($claims['proof_id']) > 191 || strlen($claims['signature_reference']) > 255
            || !preg_match('/^[a-zA-Z0-9:_-]{8,191}$/D', $claims['proof_id'])) {
            throw new DomainException('Signer attestation proof identity is invalid.');
        }
        $times = [];
        foreach (['issued_at', 'signed_at', 'expires_at'] as $field) {
            try {
                $instant = new DateTimeImmutable($claims[$field]);
                if (!preg_match('/(?:Z|[+-]\d\d:\d\d)$/', $claims[$field])) {
                    throw new DomainException('Signed attestation timestamps require explicit UTC offsets.');
                }
                $times[$field] = $instant->setTimezone(new DateTimeZone('UTC'));
            } catch (\Exception $e) {
                throw new DomainException('Invalid signing attestation time: ' . $field, 0, $e);
            }
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        if ($times['signed_at'] > $times['issued_at']
            || $times['issued_at'] > $now->modify('+60 seconds')
            || $times['expires_at'] < $now
            || $times['expires_at'] > $times['issued_at']->modify('+1 hour')
            || $times['signed_at'] < $now->modify('-24 hours')) {
            throw new DomainException('Signer proof is stale, expired or chronologically inconsistent.');
        }
        return [
            'proof_id' => $claims['proof_id'],
            'signature_reference' => $claims['signature_reference'],
            'signed_at' => $times['signed_at']->format('Y-m-d H:i:s.u'),
            'issuer' => $trustedIssuer,
            'signer_id' => $signerId,
            'document_version_id' => $currentVersionId,
            'file_sha256' => $currentFileSha256,
            'payload_sha256' => hash('sha256', $payload),
        ];
    }

    private static function decodeBase64Url(string $text, int $maxDecoded): string
    {
        if ($text === '' || strlen($text) > ($maxDecoded * 2)
            || !preg_match('/^[A-Za-z0-9_-]+$/D', $text)) {
            throw new DomainException('Signer proof has malformed base64url data.');
        }
        $raw = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if (!is_string($raw) || $raw === '' || strlen($raw) > $maxDecoded
            || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $text) {
            throw new DomainException('Signer proof base64url data is not canonical.');
        }
        return $raw;
    }
}
