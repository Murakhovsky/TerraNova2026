<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Hermes;

use DomainException;
use Platform\Integration\Contract\CredentialVaultInterface;

/** Read-only remote discovery shared with the execution adapter. No LLM call. */
final readonly class HermesAnalystProfileProbe
{
    public function __construct(private CredentialVaultInterface $vault,private HermesAnalystTransport $transport){}

    public function token(HermesAnalystConnection $connection):string
    {
        $secret=$this->vault->resolve($connection->credential);
        $token=$secret['access_token']??null;
        if(!is_string($token)||$token===''||strlen($token)>4096||preg_match('/[\\x00-\\x20\\x7f]/',$token)){
            throw new DomainException('Hermes profile credential unavailable.');
        }
        return $token;
    }

    /** @return array{toolsets_reported:int,enabled_toolsets_with_tools:int} */
    public function inspect(HermesAnalystConnection $connection,string $token):array
    {
        $sets=$this->transport->get($connection->profileBaseUrl.'/v1/toolsets',$token);
        self::assertNoTools($sets);
        return ['toolsets_reported'=>count($sets),'enabled_toolsets_with_tools'=>0];
    }

    public static function assertNoTools(array $sets):void
    {
        if(!array_is_list($sets))throw new DomainException('Hermes toolset discovery unavailable.');
        foreach($sets as $set){
            if(!is_array($set)||!is_bool($set['enabled']??null)||!is_array($set['tools']??null)
                ||!array_is_list($set['tools'])||($set['enabled']&&$set['tools']!==[])){
                throw new DomainException('Hermes advisory profile has enabled tools or unverifiable configuration.');
            }
            foreach($set['tools'] as $tool){
                if(!is_string($tool)||$tool==='')throw new DomainException('Invalid Hermes toolset entry.');
            }
        }
    }
}
