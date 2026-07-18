<?php

namespace App\Enums;

enum Periodicity: string
{
    case Annual = 'annual';
    case Biennial = 'biennial';
    case Decennial = 'decennial';
    case Irregular = 'irregular';
}
