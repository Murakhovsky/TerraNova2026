<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Infrastructure\Shopify;

use DomainException;

/** Admin GraphQL read queries only. No mutation operation is accepted. */
final readonly class ShopifyReadClient
{
    public const API_VERSION = '2026-10';

    public function __construct(private ShopifyHttpTransportInterface $http) {}

    /**
     * Admin GraphQL read only, with bounded throttling retries.
     * HTTP 200 can contain GraphQL THROTTLED errors; these must never
     * be mistaken for complete Product/Inventory responses.
     * @param array<string,mixed> $variables @return array<string,mixed>
     */
    public function query(string $shopDomain, string $token, string $query, array $variables=[]): array
    {
        self::assertShopDomain($shopDomain);
        if ($token===''||strlen($token)>2048) throw new DomainException('Missing Shopify access token.');
        if (!preg_match('/^\\s*query\\b/i',$query)
            || preg_match('/\\bmutation\\b/i',$query)) throw new DomainException('Shopify write operation is forbidden in E4.');
        $body=json_encode(['query'=>$query,'variables'=>$variables],JSON_THROW_ON_ERROR);
        for ($attempt=1;$attempt<=3;++$attempt) {
            $response=$this->http->post('https://'.$shopDomain.'/admin/api/'.self::API_VERSION.'/graphql.json',[
                'Content-Type'=>'application/json',
                'X-Shopify-Access-Token'=>$token,
            ],$body);
            if (in_array($response['status'],[429,503],true)) {
                if ($attempt<3) {
                    usleep(250000*$attempt);
                    continue;
                }
                throw new DomainException('Shopify read throttled or temporarily unavailable after bounded retries.');
            }
            if ($response['status']<200||$response['status']>=300) {
                throw new DomainException('Shopify HTTP read error: '.$response['status']);
            }
            $result=json_decode($response['body'],true,flags:JSON_THROW_ON_ERROR);
            if (!is_array($result)) throw new DomainException('Shopify GraphQL response is not an object.');
            $errors=$result['errors']??[];
            if (is_array($errors)&&$errors!==[]) {
                $throttled=true;
                foreach ($errors as $error) {
                    if (!is_array($error)||($error['extensions']['code']??null)!=='THROTTLED') {
                        $throttled=false;
                        break;
                    }
                }
                if ($throttled && $attempt<3) {
                    usleep(250000*$attempt);
                    continue;
                }
                throw new DomainException($throttled
                    ?'Shopify GraphQL throttle budget exhausted.'
                    :'Shopify GraphQL returned errors.');
            }
            if (!array_key_exists('data',$result)||!is_array($result['data'])) {
                throw new DomainException('Shopify GraphQL missing data.');
            }
            return $result;
        }
        throw new DomainException('Shopify read retry budget exceeded.');
    }

    public static function assertShopDomain(string $host): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D',$host)||strlen($host)>190) {
            throw new DomainException('Shop domain must be <shop>.myshopify.com.');
        }
    }
}
