<?php

namespace App\Enums;

enum AvailabilityStatus: string
{
    case Available = 'available';
    case Provisional = 'provisional';
    case MissingFromSource = 'missing_from_source';
    case NotApplicable = 'not_applicable';
    case Suppressed = 'suppressed';
    case NotYetPublished = 'not_yet_published';
    case Rejected = 'rejected';
}
