<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Report;
enum RecommendationStatus:string { case Proposed='PROPOSED'; case Accepted='ACCEPTED'; case Planned='PLANNED'; case InProgress='IN_PROGRESS'; case Implemented='IMPLEMENTED'; case Measured='MEASURED'; case Successful='SUCCESSFUL'; case Failed='FAILED'; case Rejected='REJECTED'; }
