<?php
declare(strict_types=1);
namespace Platform\Workflow\Service;

use DomainException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Repository-wide static evidence of technical execution writers and known
 * ingress surfaces. Does NOT pretend to enumerate dynamic reflection, service
 * locator invocations, live DB credentials or external queue traffic.
 */
final class WorkflowExecutionAuthorityInventory
{
    /** @return array<string,mixed> */
    public static function inspect(string $root):array
    {
        $root=rtrim($root,'/');
        $config=json_decode((string)file_get_contents(
            $root.'/resources/architecture/cos-execution-authority-freeze.json'
        ),true,512,JSON_THROW_ON_ERROR);
        $allowed=$config['allowed_writers']??[];
        $actual=[];$readers=[];$phpFiles=0;$dynamic=[];
        $contractSymbols=[
            'WorkflowExecutionStoreInterface','WorkflowTechnicalExternalStoreInterface',
            'WorkflowExecutionCoordinatorInterface','WorkflowEvidenceSignalStoreInterface',
            'FederationCanonicalWorkflowBridge','EngineeringCanonicalTechnicalExecutionGateway',
            'FederationSequentialOrchestrator','FederationGoalStore','WorkflowEngine',
            'ActionExecutionJobHandler','AgentRunJobHandler','ToolRuntime',
        ];
        $consumers=[];
        $dml='~\\b(?:INSERT\\s+(?:IGNORE\\s+)?INTO|REPLACE\\s+INTO|UPDATE|DELETE\\s+FROM)\\s+[`"]?(cos_(?:federation|workflow)_(?:runs|steps))[`"]?\\b~i';
        $dbal='~->\\s*(?:insert|update|delete)\\s*\\(\\s*[\'"](cos_(?:federation|workflow)_(?:runs|steps))[\'"]~i';
        foreach(['app','symfony/src','bin'] as $relative) {
            $directory=new RecursiveDirectoryIterator($root.'/'.$relative,FilesystemIterator::SKIP_DOTS);
            foreach(new RecursiveIteratorIterator($directory) as $entry) {
                if(!$entry->isFile() || strtolower($entry->getExtension())!=='php') continue;
                ++$phpFiles;
                $path=str_replace('\\','/',substr($entry->getPathname(),strlen($root)+1));
                if($path==='symfony/src/Command/FederationPersistenceSmokeCommand.php') continue;
                $source=(string)file_get_contents($entry->getPathname());
                // Strip PHP comments, retaining SQL strings and heredocs.
                $body='';
                foreach(token_get_all($source) as $token) {
                    if(is_array($token)&&in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true))continue;
                    $body.=is_array($token)?$token[1]:$token;
                }
                foreach($contractSymbols as $symbol) {
                    if(preg_match('/\\b'.preg_quote($symbol,'/').'\\b/',$body)) {
                        $consumers[$symbol][$path]=true;
                    }
                }
                foreach([$dml,$dbal] as $pattern) {
                    if(preg_match_all($pattern,$body,$m)) {
                        foreach($m[1] as $table) $actual[strtolower($table)][$path]=true;
                    }
                }
                if(preg_match_all('/\\bcos_(?:federation|workflow)_(?:runs|steps)\\b/i',$body,$m)) {
                    foreach($m[0] as $table) $readers[strtolower($table)][$path]=true;
                    if(preg_match('/->\\s*(?:update|insert|delete)\\s*\\(\\s*\\$/',$body)) {
                        $dynamic[$path]=true;
                    }
                }
            }
        }
        $errors=[];
        foreach($allowed as $table=>$paths) {
            $seen=array_keys($actual[$table]??[]);
            sort($seen);
            $want=$paths;
            sort($want);
            if($seen!==$want) $errors[]='writer_drift:'.$table.':'.implode(',',$seen);
        }
        foreach($actual as $table=>$paths) {
            if(!isset($allowed[$table])) $errors[]='unregistered_execution_table:'.$table;
        }
        $surfaces=[
            'canonical_store'=>[
                'symfony/src/Persistence/Workflow/DoctrineWorkflowExecutionStore.php',
                'symfony/src/Persistence/Workflow/DoctrineWorkflowRecoveryManager.php',
            ],
            'legacy_frozen'=>[
                'symfony/src/Persistence/Federation/FederationGoalStore.php',
                'symfony/src/Persistence/Federation/FederationExternalActionSubmission.php',
                'symfony/src/Persistence/Federation/FederationRunFinalizer.php',
            ],
            'goal_web_and_bridge'=>[
                'symfony/src/Web/Federation/FederationRunController.php',
                'symfony/src/Persistence/Federation/FederationCanonicalWorkflowBridge.php',
                'symfony/src/Persistence/Workflow/DoctrineWorkflowExecutionCoordinator.php',
            ],
            'engineering_agent'=>[
                'symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringAgentRunStore.php',
                'symfony/src/Engineering/Infrastructure/Persistence/Doctrine/EngineeringCanonicalTechnicalExecutionGateway.php',
                'symfony/src/Engineering/Infrastructure/Persistence/Doctrine/EngineeringCanonicalTechnicalExecutionAdmission.php',
            ],
            'kernel_queue_and_agent'=>[
                'app/Kernel/Queue/Service/QueueWorker.php',
                'app/Kernel/Queue/Handler/ActionExecutionJobHandler.php',
                'app/Kernel/Queue/Handler/AgentRunJobHandler.php',
                'app/Kernel/Agent/Service/AgentRuntime.php',
            ],
            'tool_independent_runtime'=>['app/Kernel/Tool/Service/ToolRuntime.php'],
            'kernel_in_memory'=>['app/Kernel/Workflow/Service/WorkflowEngine.php'],
            'operator_audits'=>[
                'symfony/src/Command/WorkflowShadowAuditCommand.php',
                'symfony/src/Command/FederationHistoricalCutoverCommand.php',
                'symfony/src/Command/FederationExecutionFleetAuditCommand.php',
            ],
        ];
        foreach($surfaces as $kind=>$paths) foreach($paths as $path) {
            if(!is_file($root.'/'.$path)) $errors[]='missing_surface:'.$kind.':'.$path;
        }
        $di=(string)file_get_contents($root.'/symfony/config/services.yaml');
        foreach([
            'WorkflowExecutionStoreInterface:',
            'WorkflowExecutionCoordinatorInterface:',
            'WorkflowTechnicalExternalStoreInterface:',
            'WorkflowEvidenceSignalStoreInterface:',
        ] as $port) {
            if(!str_contains($di,$port)) $errors[]='missing_runtime_port:'.$port;
        }
        $normalized=static function(array $data):array {
            ksort($data);
            foreach($data as &$value) { $value=array_keys($value);sort($value); }
            return $data;
        };
        return [
            'status'=>$errors===[]?'static_covered_not_fleet_accepted':'drift',
            'php_files_scanned'=>$phpFiles,
            'technical_writers'=>$normalized($actual),
            'read_consumer_candidates'=>$normalized($readers),
            'runtime_surfaces'=>$surfaces,
            'consumer_symbol_references'=>$normalized($consumers),
            'dynamic_dml_candidates'=>array_keys($dynamic),
            'errors'=>$errors,
            'consumer_fleet_verified'=>false,
            'live_tenant_verified'=>false,
            'read_only'=>true,
        ];
    }
}
