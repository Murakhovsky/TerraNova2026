<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum OpportunityType: string
{
    case CustomerAcquisition = 'customer_acquisition';
    case CustomerExpansion = 'customer_expansion';
    case Reactivation = 'reactivation';
    case Partnership = 'partnership';
    case Supplier = 'supplier';
    case Investor = 'investor';
    case Candidate = 'candidate';
    case Tender = 'tender';
    case Property = 'property';
    case Acquisition = 'acquisition';
    case Project = 'project';
    case Technology = 'technology';
}
