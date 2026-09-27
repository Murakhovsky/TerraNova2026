<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Integration;

use DateTimeImmutable;
use InvalidArgumentException;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;
use RuntimeException;

final readonly class EnvironmentCredentialVault implements CredentialVaultInterface
{
    public function resolve(Credential $credential):array
    {
        if($credential->expired(new DateTimeImmutable('now'))){
            throw new InvalidArgumentException('Credential is expired.');
        }

        $reference=trim($credential->secretReference);
        if(!preg_match('/^env:\/\/([A-Z][A-Z0-9_]{2,127})$/',$reference,$matches)){
            throw new InvalidArgumentException('Environment credential vault supports only env:// references.');
        }

        $raw=getenv($matches[1]);
        if(!is_string($raw)||trim($raw)===''){
            throw new RuntimeException('Credential secret material is unavailable.');
        }

        try{
            $decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        }catch(\Throwable){
            throw new RuntimeException('Credential secret material is malformed.');
        }
        if(!is_array($decoded)||array_is_list($decoded)||$decoded===[]||count($decoded)>32){
            throw new RuntimeException('Credential secret material must be a non-empty JSON object.');
        }

        $material=[];
        foreach($decoded as $key=>$value){
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/',$key)){
                throw new RuntimeException('Credential secret material contains an invalid key.');
            }
            if(!is_string($value)||$value===''||strlen($value)>16384||str_contains($value,"\r")||str_contains($value,"\n")){
                throw new RuntimeException('Credential secret material contains an invalid value.');
            }
            $material[$key]=$value;
        }
        return $material;
    }
}
