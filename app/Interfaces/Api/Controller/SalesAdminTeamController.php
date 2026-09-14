<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use DomainException;
use Domains\Sales\Application\Contract\SalesTeamAdministrationInterface;
use Domains\Sales\Model\SalesCapability;
use Interfaces\Web\Controller\WebController;
use PDOException;
use Phalcon\Http\Response;
use Throwable;

final class SalesAdminTeamController extends WebController
{
    public function catalogAction(): Response { return $this->read(fn($s,$o)=>$s->catalog()); }
    public function usersAction(): Response { return $this->read(fn($s,$o)=>$s->users($o)); }
    public function teamsAction(): Response { return $this->read(fn($s,$o)=>$s->teams($o)); }
    public function teamAction(?string $id=null): Response { return $this->read(function($s,$o) use($id){$team=$s->team($o,$this->id($id));if($team===null)throw new DomainException('Sales team was not found.');return $team;}); }
    public function createAction(): Response { return $this->write(fn($s,$o,$u,$i)=>$s->createTeam($o,$i,(string)$u['id']),201); }
    public function updateAction(?string $id=null): Response { return $this->write(fn($s,$o,$u,$i)=>$s->updateTeam($o,$this->id($id),$i,(int)($i['configuration_version']??0),(string)$u['id'])); }
    public function memberAction(?string $id=null, ?string $userId=null): Response { return $this->write(fn($s,$o,$u,$i)=>$s->setMember($o,$this->id($id),$this->userId($userId),$i,(string)$u['id'])); }
    public function capabilitiesAction(?string $userId=null): Response { return $this->write(fn($s,$o,$u,$i)=>$s->setCapabilities($o,$this->userId($userId),(array)($i['capabilities']??[]),(string)$u['id'])); }
    public function revisionsAction(?string $id=null): Response { return $this->read(fn($s,$o)=>$s->revisions($o,$this->revisionId($id),(int)$this->request->getQuery('limit','int',100))); }

    private function service(): SalesTeamAdministrationInterface { return $this->di->getShared('salesTeamAdministration'); }
    private function read(callable $fn): Response { $u=$this->admin();if($u instanceof Response)return $u;try{return $this->json(200,['ok'=>true,'data'=>$fn($this->service(),$this->organization()->id())]);}catch(Throwable $e){return $this->error($e);} }
    private function write(callable $fn,int $status=200): Response { $u=$this->admin();if($u instanceof Response)return $u;if(!$this->validMutation())return $this->json(400,['ok'=>false,'error'=>'Invalid CSRF token.']);try{return $this->json($status,['ok'=>true,'data'=>$fn($this->service(),$this->organization()->id(),$u,$this->input())]);}catch(Throwable $e){return $this->error($e);} }
    private function admin(): array|Response {
        $u=$this->auth()->currentUser();
        if($u!==null && ($this->auth()->isAdmin($u) || $this->di->getShared('salesAccessControl')->hasCapability($this->organization()->id(),(int)$u['id'],SalesCapability::AdminTeamsManage->value))) return $u;
        return $this->json(403,['ok'=>false,'error'=>'Sales Teams administration capability required.']);
    }
    private function input(): array { $json=$this->request->getJsonRawBody(true);return is_array($json)?$json:(array)$this->request->getPost(); }
    private function id(?string $id): string { $value=trim((string)($id?:$this->dispatcher->getParam('id')));if(!preg_match('/^[A-Za-z0-9_-]{8,64}$/',$value))throw new DomainException('Invalid team id.');return $value; }
    private function revisionId(?string $id): string { $value=trim((string)($id?:$this->dispatcher->getParam('id')));if($value===''||mb_strlen($value)>191)throw new DomainException('Invalid revision entity id.');return $value; }
    private function userId(?string $id): int { $value=(string)($id?:$this->dispatcher->getParam('userId'));if(!ctype_digit($value)||(int)$value<=0)throw new DomainException('Invalid user id.');return (int)$value; }
    private function error(Throwable $e): Response { $m=$e->getMessage();$s=match(true){$m==='CONFIGURATION_CONFLICT'=>409,$m==='Sales team was not found.'=>404,$e instanceof DomainException=>422,$e instanceof PDOException&&(string)$e->getCode()==='23000'=>409,default=>500};return $this->json($s,['ok'=>false,'error'=>$m]); }
    private function json(int $status,array $payload): Response { $this->view->disable();$this->response->setStatusCode($status);$this->response->setContentType('application/json','UTF-8');return $this->response->setJsonContent($payload); }
}
