<?php

declare(strict_types=1);

namespace App\Web\Experience\Registry;

enum PageExperienceStatus: string
{
    case Discovered = 'DISCOVERED';
    case Inventoried = 'INVENTORIED';
    case Contracted = 'CONTRACTED';
    case UxApproved = 'UX_APPROVED';
    case Implementing = 'IMPLEMENTING';
    case Implemented = 'IMPLEMENTED';
    case VisualQa = 'VISUAL_QA';
    case FunctionalQa = 'FUNCTIONAL_QA';
    case AccessibilityQa = 'ACCESSIBILITY_QA';
    case HumanReview = 'HUMAN_REVIEW';
    case Accepted = 'ACCEPTED';
    case V1Ready = 'V1_READY';
    case Blocked = 'BLOCKED';
    case NeedsRework = 'NEEDS_REWORK';
    case Deprecated = 'DEPRECATED';
    case Superseded = 'SUPERSEDED';
    case Exempt = 'EXEMPT';
}
