<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
/** Read only. Access tokens are ephemeral, never returned or persisted. */
interface PaidMediaProviderInterface {
 public function provider():string;
 /** @return array{items:list<array<string,mixed>>,next_cursor:?string} */
 public function campaigns(string $accountId,string $token,?string $cursor=null):array;
 /** @return array{items:list<array<string,mixed>>,next_cursor:?string} */
 public function adSets(string $accountId,string $token,?string $cursor=null):array;
 /** @return array{items:list<array<string,mixed>>,next_cursor:?string} */
 public function ads(string $accountId,string $token,?string $cursor=null):array;
 /** @return array{items:list<array<string,mixed>>,next_cursor:?string} */
 public function insights(string $accountId,string $token,string $level,string $since,string $until,?string $cursor=null):array;
}
