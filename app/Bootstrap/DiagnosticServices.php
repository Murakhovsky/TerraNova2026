<?php
declare(strict_types=1);

use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Domains\Diagnostic\Application\Service\MethodologyWorkbenchService;
use Domains\Diagnostic\Application\UseCase\AcceptDiagnosticRecommendation;
use Domains\Diagnostic\Application\UseCase\CancelDiagnosticSession;
use Domains\Diagnostic\Application\UseCase\CaptureDiagnosticEvidence;
use Domains\Diagnostic\Application\UseCase\CompleteDiagnosticSession;
use Domains\Diagnostic\Application\UseCase\DraftDiagnosticPack;
use Domains\Diagnostic\Application\UseCase\EvaluateDiagnosticSession;
use Domains\Diagnostic\Application\UseCase\PublishDiagnosticPack;
use Domains\Diagnostic\Application\UseCase\RecordDiagnosticResult;
use Domains\Diagnostic\Application\UseCase\ReviseDiagnosticPack;
use Domains\Diagnostic\Application\UseCase\StartDiagnosticSession;
use Domains\Diagnostic\Automation\Handler\DiagnosticActionOutcomeHandler;
use Domains\Diagnostic\Automation\Handler\ImplementDiagnosticRecommendationActionHandler;
use Domains\Diagnostic\Bootstrap\DiagnosticDomainModule;
use Domains\Diagnostic\Evaluation\GenericEvaluationRunner;
use Domains\Diagnostic\Infrastructure\AI\StructuredLlmAiGateway;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlDiagnosticAssessmentProjection;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlDiagnosticPackRepository;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlDiagnosticRuntimeRepository;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlDiagnosticSessionRepository;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlMethodologyStudioRepository;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlMethodologyWorkbenchRepository;
use Domains\Diagnostic\Interview\FactExtractionService;
use Domains\Diagnostic\Interview\HypothesisGenerationService;

$di->setShared('diagnosticPackRepository', fn (): MysqlDiagnosticPackRepository => new MysqlDiagnosticPackRepository($this->getShared('databaseService')->connection()));
$di->setShared('diagnosticSessionRepository', fn (): MysqlDiagnosticSessionRepository => new MysqlDiagnosticSessionRepository($this->getShared('databaseService')->connection()));
$di->setShared('diagnosticAssessmentProjection', fn (): MysqlDiagnosticAssessmentProjection => new MysqlDiagnosticAssessmentProjection($this->getShared('databaseService')->connection()));
$di->setShared('diagnosticMethodologyStudioRepository', fn (): MysqlMethodologyStudioRepository => new MysqlMethodologyStudioRepository($this->getShared('databaseService')->connection()));
$di->setShared('diagnosticMethodologyStudio', fn (): MethodologyStudioService => new MethodologyStudioService($this->getShared('diagnosticMethodologyStudioRepository')));
$di->setShared('diagnosticMethodologyWorkbenchRepository', fn (): MysqlMethodologyWorkbenchRepository => new MysqlMethodologyWorkbenchRepository($this->getShared('databaseService')->connection()));
$di->setShared('diagnosticMethodologyWorkbench', fn (): MethodologyWorkbenchService => new MethodologyWorkbenchService(
    $this->getShared('diagnosticMethodologyStudio'),
    $this->getShared('diagnosticMethodologyStudioRepository'),
    $this->getShared('diagnosticMethodologyWorkbenchRepository'),
));
$di->setShared('diagnosticMethodologyAccess', fn (): DiagnosticMethodologyAccess => new DiagnosticMethodologyAccess($this->getShared('databaseService')->connection()));

$di->setShared('diagnosticPublishPack', fn (): PublishDiagnosticPack => new PublishDiagnosticPack($this->getShared('diagnosticPackRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager')));
$di->setShared('diagnosticDraftPack', fn (): DraftDiagnosticPack => new DraftDiagnosticPack($this->getShared('diagnosticPackRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager')));
$di->setShared('diagnosticRevisePack', fn (): ReviseDiagnosticPack => new ReviseDiagnosticPack($this->getShared('diagnosticPackRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager')));
$di->setShared('diagnosticStartSession', fn (): StartDiagnosticSession => new StartDiagnosticSession($this->getShared('diagnosticPackRepository'), $this->getShared('diagnosticSessionRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager')));
$di->setShared('diagnosticCaptureEvidence', fn (): CaptureDiagnosticEvidence => new CaptureDiagnosticEvidence($this->getShared('diagnosticSessionRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager')));
$di->setShared('diagnosticRecordResult', fn (): RecordDiagnosticResult => new RecordDiagnosticResult($this->getShared('diagnosticPackRepository'), $this->getShared('diagnosticSessionRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager')));
$di->setShared('diagnosticEvaluateSession', fn (): EvaluateDiagnosticSession => new EvaluateDiagnosticSession(
    packs: $this->getShared('diagnosticPackRepository'),
    sessions: $this->getShared('diagnosticSessionRepository'),
    events: $this->getShared('eventBus'),
    transactions: $this->getShared('cosTransactionManager'),
    assessmentProjection: $this->getShared('diagnosticAssessmentProjection'),
));
$di->setShared('diagnosticCompleteSession', fn (): CompleteDiagnosticSession => new CompleteDiagnosticSession($this->getShared('diagnosticPackRepository'), $this->getShared('diagnosticSessionRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager')));
$di->setShared('diagnosticCancelSession', fn (): CancelDiagnosticSession => new CancelDiagnosticSession($this->getShared('diagnosticSessionRepository'), $this->getShared('eventBus'), $this->getShared('cosTransactionManager')));

$di->setShared('diagnosticRuntimeRepository', fn (): MysqlDiagnosticRuntimeRepository => new MysqlDiagnosticRuntimeRepository($this->getShared('databaseService')->connection()));
$di->setShared('diagnosticAiGateway', fn (): StructuredLlmAiGateway => new StructuredLlmAiGateway($this->getShared('cosLlmClient')));
$di->setShared('diagnosticFactExtractor', fn (): FactExtractionService => new FactExtractionService($this->getShared('diagnosticAiGateway')));
$di->setShared('diagnosticHypothesisGenerator', fn (): HypothesisGenerationService => new HypothesisGenerationService($this->getShared('diagnosticAiGateway')));
$di->setShared('diagnosticAcceptRecommendation', fn (): AcceptDiagnosticRecommendation => new AcceptDiagnosticRecommendation($this->getShared('cosActionService')));
$di->setShared('diagnosticEvaluationRunner', fn (): GenericEvaluationRunner => new GenericEvaluationRunner());

$di->setShared('diagnosticRuntimeService', fn (): DiagnosticRuntimeService => new DiagnosticRuntimeService(
    $this->getShared('diagnosticRuntimeRepository'),
    $this->getShared('diagnosticSessionRepository'),
    $this->getShared('diagnosticMethodologyStudio'),
    $this->getShared('diagnosticStartSession'),
    $this->getShared('diagnosticCaptureEvidence'),
    $this->getShared('diagnosticRecordResult'),
    $this->getShared('diagnosticEvaluateSession'),
    $this->getShared('diagnosticCompleteSession'),
    $this->getShared('diagnosticFactExtractor'),
    $this->getShared('diagnosticHypothesisGenerator'),
    $this->getShared('diagnosticAcceptRecommendation'),
));

$di->setShared('diagnosticRecommendationActionHandler', fn (): ImplementDiagnosticRecommendationActionHandler => new ImplementDiagnosticRecommendationActionHandler());
$di->setShared('diagnosticActionOutcomeHandler', fn (): DiagnosticActionOutcomeHandler => new DiagnosticActionOutcomeHandler($this->getShared('diagnosticRuntimeRepository')));
$di->setShared('diagnosticDomainModule', fn (): DiagnosticDomainModule => new DiagnosticDomainModule([
    $this->getShared('diagnosticRecommendationActionHandler'),
]));
