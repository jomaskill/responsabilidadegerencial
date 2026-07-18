<?php

namespace App\Enums;

enum QualityStatus: string
{
    case Accepted = 'accepted';
    case Warning = 'warning';
    case Rejected = 'rejected';
}
