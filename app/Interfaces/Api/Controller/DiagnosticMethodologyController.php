<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Interfaces\Web\Controller\WebController;
use Phalcon\Http\ResponseInterface;
use Throwable;

final class DiagnosticMethodologyController extends WebController
{
    public function packsAction(): ResponseInterface
    {
        return $this->call(fn ($service, $org, $user) => $this->request->isPost()
            ? ['created' => $service->create($org, $this->input(), (string) $user['id'])]
            : ['packs' => $service->packs($org)], $this->request->isPost() ? 'admin' : 'viewer');
    }

    public function packAction(?string $id = null): ResponseInterface
    {
        $pack = (string) ($id ?: $this->dispatcher->getParam('id'));
        return $this->call(fn ($service, $org) => ['pack' => $service->pack($org, $pack)]);
    }

    public function versionsAction(?string $id = null): ResponseInterface
    {
        $pack = (string) ($id ?: $this->dispatcher->getParam('id'));
        return $this->call(fn ($service, $org) => ['versions' => $service->versions($org, $pack)]);
    }

    public function entitiesAction(?string $id = null, ?string $version = null): ResponseInterface
    {
        [$pack, $methodology] = $this->identity($id, $version);
        return $this->call(fn ($service, $org) => ['entities' => $service->entities($org, $pack, $methodology)]);
    }

    public function entityAction(?string $id = null, ?string $version = null, ?string $type = null): ResponseInterface
    {
        [$pack, $methodology] = $this->identity($id, $version);
        $entityType = (string) ($type ?: $this->dispatcher->getParam('type'));
        return $this->call(function ($service, $org, $user) use ($pack, $methodology, $entityType) {
            $service->saveEntity($org, $pack, $methodology, $entityType, $this->input(), (string) $user['id']);
            return ['saved' => true];
        }, 'admin');
    }

    public function deleteEntityAction(?string $id = null, ?string $version = null, ?string $type = null, ?string $entity = null): ResponseInterface
    {
        [$pack, $methodology] = $this->identity($id, $version);
        $entityType = (string) ($type ?: $this->dispatcher->getParam('type'));
        $entityId = (string) ($entity ?: $this->dispatcher->getParam('entity'));
        return $this->call(function ($service, $org, $user) use ($pack, $methodology, $entityType, $entityId) {
            $service->deleteEntity($org, $pack, $methodology, $entityType, $entityId, (string) $user['id']);
            return ['deleted' => true];
        }, 'admin');
    }

    public function scenariosAction(?string $id = null, ?string $version = null): ResponseInterface
    {
        [$pack, $methodology] = $this->identity($id, $version);
        return $this->call(fn ($service, $org) => ['scenarios' => $service->scenarios($org, $pack, $methodology)]);
    }

    public function scenarioAction(?string $id = null, ?string $version = null): ResponseInterface
    {
        [$pack, $methodology] = $this->identity($id, $version);
        return $this->call(function ($service, $org, $user) use ($pack, $methodology) {
            $service->saveScenario($org, $pack, $methodology, $this->input(), (string) $user['id']);
            return ['saved' => true];
        }, 'admin');
    }

    public function validateAction(?string $id = null, ?string $version = null): ResponseInterface {[$p,$v]=$this->identity($id,$version);return $this->call(fn($s,$o)=>$s->validate($o,$p,$v),'admin');}
    public function simulateAction(?string $id = null, ?string $version = null): ResponseInterface {[$p,$v]=$this->identity($id,$version);return $this->call(fn($s,$o)=>$s->simulate($o,$p,$v,$this->input()),'admin');}
    public function regressionAction(?string $id = null, ?string $version = null): ResponseInterface {[$p,$v]=$this->identity($id,$version);return $this->call(fn($s,$o)=>$s->runRegression($o,$p,$v),'admin');}
    public function cloneAction(?string $id = null, ?string $version = null): ResponseInterface {[$p,$v]=$this->identity($id,$version);return $this->call(function($s,$o,$u)use($p,$v){$s->clone($o,$p,$v,(string)($this->input()['version']??''),(string)$u['id']);return ['cloned'=>true];},'admin');}
    public function publishAction(?string $id = null, ?string $version = null): ResponseInterface {[$p,$v]=$this->identity($id,$version);return $this->call(function($s,$o,$u)use($p,$v){$s->publish($o,$p,$v,(string)$u['id']);return ['published'=>true];},'publisher');}
    public function archiveAction(?string $id = null, ?string $version = null): ResponseInterface {[$p,$v]=$this->identity($id,$version);return $this->call(function($s,$o,$u)use($p,$v){$s->archive($o,$p,$v,(string)$u['id']);return ['archived'=>true];},'publisher');}
    public function activateAction(?string $id = null, ?string $version = null): ResponseInterface {[$p,$v]=$this->identity($id,$version);return $this->call(function($s,$o,$u)use($p,$v){$s->activate($o,$p,$v,(string)$u['id']);return ['activated'=>true];},'publisher');}

    private function call(callable $callback, string $access = 'viewer'): ResponseInterface
    {
        $user = $this->auth()->currentUser();
        if ($user === null) return $this->jsonOut(401, ['ok' => false, 'error' => 'Authentication required.']);
        if ($access === 'admin' && !$this->auth()->isManager($user)) return $this->jsonOut(403, ['ok' => false, 'error' => 'Diagnostic Admin role required.']);
        if ($access === 'publisher' && !$this->auth()->isAdmin($user)) return $this->jsonOut(403, ['ok' => false, 'error' => 'Diagnostic Publisher role required.']);
        if ($access !== 'viewer' && !$this->validMutation()) return $this->jsonOut(400, ['ok' => false, 'error' => 'Invalid CSRF token.']);
        try {
            /** @var MethodologyStudioService $service */
            $service = $this->di->getShared('diagnosticMethodologyStudio');
            return $this->jsonOut(200, ['ok' => true, 'data' => $callback($service, $this->organization()->id(), $user)]);
        } catch (Throwable $exception) {return $this->jsonOut(422, ['ok' => false, 'error' => $exception->getMessage()]);}
    }

    /** @return array{string,string} */
    private function identity(?string $id, ?string $version): array {return [(string)($id?:$this->dispatcher->getParam('id')),(string)($version?:$this->dispatcher->getParam('version'))];}
    private function input(): array {$value=$this->request->getJsonRawBody(true);return is_array($value)?$value:(array)$this->request->getPost();}
    private function jsonOut(int $status, array $data): ResponseInterface {$this->view->disable();$this->response->setStatusCode($status);$this->response->setContentType('application/json','UTF-8');$this->response->setJsonContent($data);return $this->response;}
}
