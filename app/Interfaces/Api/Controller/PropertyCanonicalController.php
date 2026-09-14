<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Interfaces\Web\Controller\WebController;
use InvalidArgumentException;
use Phalcon\Http\Response;
use Throwable;

final class PropertyCanonicalController extends WebController
{
    public function assetAction(?string $assetId = null): Response
    {
        $assetId=(string)($assetId?:$this->dispatcher->getParam('assetId'));
        try{return $this->json(200,['ok'=>true,'data'=>$this->runtime()->snapshot($this->organization()->id(),$assetId)]);}
        catch(InvalidArgumentException $e){return $this->json(404,['ok'=>false,'error'=>$e->getMessage()]);}
        catch(Throwable){return $this->json(500,['ok'=>false,'error'=>'Property registry is unavailable.']);}
    }

    public function createAssetAction(): Response { return $this->mutate(fn($i,$a,$c)=>$this->runtime()->registerAsset($this->organization()->id(),$i,$a,$c),201); }
    public function updateAssetAction(?string $assetId=null): Response { $assetId=(string)($assetId?:$this->dispatcher->getParam('assetId'));return $this->mutate(fn($i,$a,$c)=>$this->runtime()->updateAsset($this->organization()->id(),$assetId,$i,$a,$c)); }
    public function lifecycleAction(?string $assetId=null): Response { $assetId=(string)($assetId?:$this->dispatcher->getParam('assetId'));return $this->mutate(fn($i,$a,$c)=>$this->runtime()->changeLifecycle($this->organization()->id(),$assetId,(string)($i['lifecycle']??''),$a,$c)); }
    public function createInventoryAction(?string $assetId=null): Response { $assetId=(string)($assetId?:$this->dispatcher->getParam('assetId'));return $this->mutate(fn($i,$a,$c)=>$this->runtime()->createInventory($this->organization()->id(),$assetId,$i,$a,$c),201); }
    public function inventoryPriceAction(?string $inventoryId=null): Response
    {
        $inventoryId=(string)($inventoryId?:$this->dispatcher->getParam('inventoryId'));
        return $this->mutate(fn($i,$a,$c)=>$this->runtime()->changeInventoryPrice($this->organization()->id(),$inventoryId,array_key_exists('price_amount',$i)&&$i['price_amount']!==''?(float)$i['price_amount']:null,isset($i['price_currency'])?(string)$i['price_currency']:null,isset($i['price_period'])?(string)$i['price_period']:null,$a,$c));
    }
    public function inventoryStatusAction(?string $inventoryId=null): Response { $inventoryId=(string)($inventoryId?:$this->dispatcher->getParam('inventoryId'));return $this->mutate(fn($i,$a,$c)=>$this->runtime()->changeInventoryStatus($this->organization()->id(),$inventoryId,(string)($i['status']??''),isset($i['reason'])?(string)$i['reason']:null,$a,$c)); }
    public function reserveAction(?string $inventoryId=null): Response { $inventoryId=(string)($inventoryId?:$this->dispatcher->getParam('inventoryId'));return $this->mutate(fn($i,$a,$c)=>$this->runtime()->reserveInventory($this->organization()->id(),$inventoryId,$i,$a,$c),201); }
    public function releaseReservationAction(?string $inventoryId=null): Response { $inventoryId=(string)($inventoryId?:$this->dispatcher->getParam('inventoryId'));return $this->mutate(fn($i,$a,$c)=>$this->runtime()->releaseReservation($this->organization()->id(),$inventoryId,$a,$c)); }
    public function createListingAction(?string $inventoryId=null): Response { $inventoryId=(string)($inventoryId?:$this->dispatcher->getParam('inventoryId'));return $this->mutate(fn($i,$a,$c)=>$this->runtime()->createListing($this->organization()->id(),$inventoryId,$i,$a,$c),201); }
    public function updateListingAction(?string $listingId=null): Response { $listingId=(string)($listingId?:$this->dispatcher->getParam('listingId'));return $this->mutate(fn($i,$a,$c)=>$this->runtime()->updateListing($this->organization()->id(),$listingId,$i,$a,$c)); }
    public function publishListingAction(?string $listingId=null): Response
    {
        $listingId=(string)($listingId?:$this->dispatcher->getParam('listingId'));
        return $this->mutate(fn($i,$a,$c)=>$this->runtime()->publishListing($this->organization()->id(),$listingId,(string)($i['channel']??'estatebook'),isset($i['external_id'])?(string)$i['external_id']:null,isset($i['external_url'])?(string)$i['external_url']:null,$a,$c));
    }
    public function hideListingAction(?string $listingId=null): Response { $listingId=(string)($listingId?:$this->dispatcher->getParam('listingId'));return $this->mutate(fn($i,$a,$c)=>$this->runtime()->hideListing($this->organization()->id(),$listingId,(string)($i['channel']??'estatebook'),$a,$c)); }

    private function mutate(callable $callback,int $success=200): Response
    {
        $user=$this->auth()->currentUser();if($user===null||!$this->auth()->isManager($user))return $this->json(403,['ok'=>false,'error'=>'Manager authorization required.']);
        if(!$this->validMutation())return $this->json(400,['ok'=>false,'error'=>'Invalid request or CSRF token.']);
        try{return $this->json($success,['ok'=>true,'data'=>$callback($this->input(),'user:'.(string)$user['id'],$this->correlationId())]);}
        catch(InvalidArgumentException $e){return $this->json(422,['ok'=>false,'error'=>$e->getMessage()]);}
        catch(Throwable $e){return $this->json(500,['ok'=>false,'error'=>$e->getMessage()]);}
    }
    private function input(): array
    {
        $type=mb_strtolower((string)$this->request->getHeader('Content-Type'));
        if(str_contains($type,'application/json')){$decoded=json_decode((string)$this->request->getRawBody(),true);return is_array($decoded)&&!array_is_list($decoded)?$decoded:[];}
        return (array)$this->request->getPost();
    }
    private function correlationId(): string { $v=trim((string)$this->request->getHeader('X-Correlation-ID'));return$v!==''?mb_substr($v,0,120):bin2hex(random_bytes(12)); }
    private function runtime(): PropertyCanonicalRuntimeService { return $this->di->getShared('propertyCanonicalRuntime'); }
    private function json(int $status,array $payload): Response { $this->view->disable();$this->response->setStatusCode($status);$this->response->setContentType('application/json','UTF-8');return $this->response->setJsonContent($payload); }
}
