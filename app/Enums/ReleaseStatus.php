<?php

namespace App\Enums;

enum ReleaseStatus: string
{
    case Provisional = 'provisional';
    case Final = 'final';
    case Revised = 'revised';
}
