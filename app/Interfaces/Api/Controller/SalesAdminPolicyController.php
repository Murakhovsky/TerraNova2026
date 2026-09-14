<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DomainException;
use Domains\Sales\Application\Contract\SalesPolicyAdministrationInterface;
use Interfaces\Web\Controller\WebController;
use PDOException;
use Phalcon\Http\Response;
use Throwable;

final class SalesAdminPolicyController extends WebController
{
    public function catalogAction(): Response { return $this->read(fn($s,$o)=>$s->catalog($o)); }
    public function actionsAction(): Response { return $this->read(fn($s,$o)=>$s->actions($o)); }
    public function createAction(): Response { return $this->write(fn($s,$o,$u,$i)=>$s->create($o,$i,(string)$u['id']),201); }
    public function updateAction(?string $id=null): Response { return $this->write(fn($s,$o,$u,$i)=>$s->update($o,$this->id($id),$i,(int)($i['configuration_version']??0),(string)$u['id'])); }
    public function archiveAction(?string $id=null): Response { return $this->write(fn($s,$o,$u,$i)=>$s->archive($o,$this->id($id),(int)($i['configuration_version']??0),(string)$u['id'])); }
    public function previewAction(): Response { return $this->write(fn($s,$o,$u,$i)=>$s->preview($o,$i)); }
    public function revisionsAction(?string $id=null): Response { return $this->read(fn($s,$o)=>$s->revisions($o,$this->id($id),(int)$this->request->getQuery('limit','int',100))); }
    private function service(): SalesPolicyAdministrationInterface { return $this->di->getShared('salesPolicyAdministration'); }
    private function read(callable $fn): Response { $u=$this->admin(); if($u instanceof Response)return $u; try{return $this->json(200,['ok'=>true,'data'=>$fn($this->service(),$this->organization()->id())]);}catch(Throwable $e){return $this->error($e);} }
    private function write(callable $fn,int $status=200): Response { $u=$this->admin(); if($u instanceof Response)return $u; if(!$this->validMutation())return $this->json(400,['ok'=>false,'error'=>'Invalid CSRF token.']); try{return $this->json($status,['ok'=>true,'data'=>$fn($this->service(),$this->organization()->id(),$u,$this->input())]);}catch(Throwable $e){return $this->error($e);} }
    private function admin(): array|Response { $u=$this->auth()->currentUser(); return $u!==null&&$this->auth()->isAdmin($u)?$u:$this->json(403,['ok'=>false,'error'=>'Sales Administrator authorization required.']); }
    private function input(): array { $j=$this->request->getJsonRawBody(true); return is_array($j)?$j:(array)$this->request->getPost(); }
    private function id(?string $id): string { $v=trim((string)($id?:$this->dispatcher->getParam('id'))); if(!preg_match('/^[A-Za-z0-9_.-]{8,64}$/',$v))throw new DomainException('Invalid policy id.'); return $v; }
    private function error(Throwable $e): Response { $m=$e->getMessage();$s=match(true){$m==='CONFIGURATION_CONFLICT'=>409,$e instanceof DomainException=>422,$e instanceof PDOException&&(string)$e->getCode()==='23000'=>409,default=>500};return $this->json($s,['ok'=>false,'error'=>$m]); }
    private function json(int $s,array $p): Response {$this->view->disable();$this->response->setStatusCode($s);$this->response->setContentType('application/json','UTF-8');return $this->response->setJsonContent($p);}
}
