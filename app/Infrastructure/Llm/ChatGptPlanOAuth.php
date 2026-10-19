<?php
declare(strict_types=1);

namespace Infrastructure\Llm;

use Closure;
use RuntimeException;

/** Only approved remote SIWC partner clients. OSS loopback registration is a separate contract. */
final class ChatGptPlanOAuth
{
    private const ISSUER = 'https://auth.openai.com';
    private const TOKEN = self::ISSUER.'/api/accounts/oauth/token';
    public const PLAN_SCOPE = 'chatgpt.tokens.use.direct';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly ?Closure $transport = null,
    ) {}

    public function configured(): bool
    {
        $p = parse_url($this->redirectUri);
        return $this->enabled && trim($this->clientId) !== '' && is_array($p)
            && ($p['scheme'] ?? '') === 'https' && is_string($p['host'] ?? null)
            && ($p['path'] ?? '') === '/admin/settings/llm/chatgpt/callback'
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
    }

    /** @return array{state:string,verifier:string,nonce:string,expires_at:int,url:string} */
    public function start(): array
    {
        $this->requireConfig();
        $state = self::b64url(random_bytes(32));
        $nonce = self::b64url(random_bytes(32));
        $verifier = self::b64url(random_bytes(64));
        $query = [
            'client_id' => $this->clientId, 'redirect_uri' => $this->redirectUri,
            'response_type' => 'code', 'scope' => 'openid profile email offline_access resource.invoke '.self::PLAN_SCOPE,
            'resource' => 'https://api.openai.com/v1',
            'state' => $state, 'nonce' => $nonce,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];
        return ['state'=>$state,'verifier'=>$verifier,'nonce'=>$nonce,'expires_at'=>time()+600,
            'url'=>self::ISSUER.'/api/accounts/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)];
    }

    /** @return array<string,mixed> */
    public function redeem(string $code, string $verifier): array
    {
        $this->requireConfig();
        if ($code === '' || $verifier === '') throw new RuntimeException('Missing OAuth code/verifier.');
        return $this->token(['grant_type'=>'authorization_code','code'=>$code,
            'redirect_uri'=>$this->redirectUri,'code_verifier'=>$verifier]);
    }

    /** @return array<string,mixed> */
    public function refresh(string $refreshToken): array
    {
        $this->requireConfig();
        if ($refreshToken === '') throw new RuntimeException('Missing refresh token.');
        return $this->token(['grant_type'=>'refresh_token','refresh_token'=>$refreshToken]);
    }

    /** @param array<string,mixed> $tokens */
    public function hasPlanScope(array $tokens): bool
    {
        return is_string($tokens['scope'] ?? null)
            && in_array(self::PLAN_SCOPE, preg_split('/\s+/', trim($tokens['scope'])) ?: [], true);
    }

    public function verifiedSubject(string $jwt, ?string $nonce): string
    {
        $this->requireConfig();
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new RuntimeException('Malformed ID token.');
        $header = self::json(self::unb64($parts[0]));
        $claims = self::json(self::unb64($parts[1]));
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null))
            throw new RuntimeException('Unsupported ID token signing method.');
        $jwks = $this->request(self::ISSUER.'/.well-known/jwks.json', null);
        $verified = false;
        foreach (($jwks['keys'] ?? []) as $jwk) {
            if (!is_array($jwk) || ($jwk['kid'] ?? null) !== $header['kid']
                || ($jwk['kty'] ?? null) !== 'RSA'
                || (isset($jwk['use']) && $jwk['use'] !== 'sig')
                || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) continue;
            $key = openssl_pkey_get_public(self::rsaPem(self::unb64($jwk['n']), self::unb64($jwk['e'])));
            if ($key === false || (openssl_pkey_get_details($key)['bits'] ?? 0) < 2048) continue;
            if (openssl_verify($parts[0].'.'.$parts[1], self::unb64($parts[2]), $key, OPENSSL_ALGO_SHA256) === 1) {
                $verified = true;
                break;
            }
        }
        if (!$verified) throw new RuntimeException('ID token signature invalid.');
        $now = time();
        $aud = $claims['aud'] ?? null;
        if (($claims['iss'] ?? null) !== self::ISSUER
            || !($aud === $this->clientId || (is_array($aud) && in_array($this->clientId,$aud,true)))
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - 30
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 30
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + 30)))
            throw new RuntimeException('ID token claims invalid.');
        if ($nonce !== null && (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce,$claims['nonce'])))
            throw new RuntimeException('ID token nonce invalid.');
        if (!is_string($claims['sub'] ?? null) || trim($claims['sub']) === '')
            throw new RuntimeException('ID token subject missing.');
        return $claims['sub'];
    }

    /** @param array<string,string> $fields @return array<string,mixed> */
    private function token(array $fields): array
    {
        $fields['client_id'] = $this->clientId;
        if ($this->clientSecret !== '') $fields['client_secret'] = $this->clientSecret;
        return $this->request(self::TOKEN,$fields);
    }

    /** @param array<string,string>|null $fields @return array<string,mixed> */
    private function request(string $url, ?array $fields): array
    {
        if ($this->transport !== null) {
            $result = ($this->transport)($url,$fields);
        } else {
            $curl = curl_init($url);
            if ($curl === false) throw new RuntimeException('OpenAI OAuth transport unavailable.');
            $opts = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_MAXREDIRS=>0];
            if ($fields !== null) {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
                $opts[CURLOPT_POSTFIELDS] = http_build_query($fields,'','&',PHP_QUERY_RFC3986);
            }
            curl_setopt_array($curl,$opts);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            $result = [$status,is_string($body)?$body:''];
        }
        if (!is_array($result) || !isset($result[0],$result[1]) || (int)$result[0] < 200 || (int)$result[0] >= 300)
            throw new RuntimeException('OpenAI OAuth exchange failed.');
        return self::json((string)$result[1]);
    }

    private function requireConfig(): void
    {
        if (!$this->configured()) throw new RuntimeException('Partner OAuth is disabled or unconfigured.');
    }

    /** @return array<string,mixed> */
    private static function json(string $s): array
    {
        $value = json_decode($s,true);
        if (!is_array($value)) throw new RuntimeException('Invalid OpenAI OAuth JSON.');
        return $value;
    }
    private static function b64url(string $s): string { return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }
    private static function unb64(string $s): string
    {
        if ($s === '' || preg_match('/[^A-Za-z0-9_-]/',$s)) throw new RuntimeException('Malformed base64url.');
        $data = base64_decode(strtr($s,'-_','+/').str_repeat('=',(4-strlen($s)%4)%4),true);
        if ($data === false) throw new RuntimeException('Malformed base64url.');
        return $data;
    }
    private static function len(int $length): string
    {
        if ($length < 128) return chr($length);
        if ($length < 256) return "\x81".chr($length);
        return "\x82".pack('n',$length);
    }
    private static function seq(string $data): string { return "\x30".self::len(strlen($data)).$data; }
    private static function integer(string $data): string
    {
        $data = ltrim($data,"\x00");
        if ($data === '') throw new RuntimeException('Bad RSA key.');
        if (ord($data[0]) & 0x80) $data = "\x00".$data;
        return "\x02".self::len(strlen($data)).$data;
    }
    private static function rsaPem(string $n,string $e): string
    {
        $rsa = self::seq(self::integer($n).self::integer($e));
        $alg = hex2bin('300d06092a864886f70d0101010500');
        $spki = self::seq($alg."\x03".self::len(strlen($rsa)+1)."\x00".$rsa);
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64)."-----END PUBLIC KEY-----\n";
    }
}
