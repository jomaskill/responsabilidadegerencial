<?php

namespace App\Console\Commands;

use App\Actions\PublicHome\BuildPublicHomeHighlights;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('data:warm-public-cache {--year=* : Exercises to warm, defaults to 2017 through 2025}')]
#[Description('Warm the public home, municipal ranking, indicator and administration caches.')]
class WarmPublicCache extends Command
{
    public function handle(BuildPublicHomeHighlights $home): int
    {
        $years = $this->option('year');
        $years = $years === [] ? range(2017, 2025) : array_map('intval', $years);

        foreach ($years as $year) {
            if ($year < 2017 || $year > 2025) {
                $this->error("Unsupported exercise: {$year}");

                return self::FAILURE;
            }

            $this->components->task("Warming consolidated ranking for {$year}", fn () => $home->consolidated($year));
            $this->components->task("Warming indicator highlights for {$year}", fn () => $home->indicators($year));
            $this->components->task("Warming source freshness for {$year}", fn () => $home->freshness($year));
        }

        foreach ([2016, 2020, 2024] as $electionYear) {
            $this->components->task(
                "Warming administration evolution highlights for {$electionYear}",
                fn () => $home->administration($electionYear),
            );
        }
        $this->info('Public caches are warm.');

        return self::SUCCESS;
    }
}
