<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

enum EngineeringDomainRuntimeEventType: string
{
    case DOMAIN_CREATED = 'DomainCreated';
    case DOMAIN_SPECIFICATION_READY = 'DomainSpecificationReady';
    case DOMAIN_DECOMPOSITION_READY = 'DomainDecompositionReady';
    case DOMAIN_ARCHITECTURE_APPROVED = 'DomainArchitectureApproved';
    case CAPABILITY_READY = 'CapabilityReady';
    case FEATURE_READY = 'FeatureReady';
    case FEATURE_STARTED = 'FeatureStarted';
    case FEATURE_COMPLETED = 'FeatureCompleted';
    case FEATURE_BLOCKED = 'FeatureBlocked';
    case CONTRACT_CHANGED = 'ContractChanged';
    case ARCHITECTURE_CHANGED = 'ArchitectureChanged';
    case DOMAIN_INTEGRATION_STARTED = 'DomainIntegrationStarted';
    case DOMAIN_INTEGRATION_COMPLETED = 'DomainIntegrationCompleted';
    case DOMAIN_QA_STARTED = 'DomainQaStarted';
    case DOMAIN_QA_COMPLETED = 'DomainQaCompleted';
    case DOMAIN_RELEASE_READY = 'DomainReleaseReady';
}
