<?php

namespace App\Providers;

use App\Contracts\MunicipalData\CensusIndicatorFetcher;
use App\Contracts\MunicipalData\GdpFetcher;
use App\Contracts\MunicipalData\HomicideFetcher;
use App\Contracts\MunicipalData\IdebFetcher;
use App\Contracts\MunicipalData\PopulationFetcher;
use App\Contracts\MunicipalData\SanitationFetcher;
use App\Contracts\MunicipalData\SourceFetcher;
use App\Infrastructure\MunicipalData\Fetchers\DatasusHomicideFetcher;
use App\Infrastructure\MunicipalData\Fetchers\IbgeCensusIndicatorFetcher;
use App\Infrastructure\MunicipalData\Fetchers\IbgeGdpFetcher;
use App\Infrastructure\MunicipalData\Fetchers\IbgeMunicipalityFetcher;
use App\Infrastructure\MunicipalData\Fetchers\IbgePopulationFetcher;
use App\Infrastructure\MunicipalData\Fetchers\InepIdebFetcher;
use App\Infrastructure\MunicipalData\Fetchers\SinisaSanitationFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SourceFetcher::class, IbgeMunicipalityFetcher::class);
        $this->app->bind(PopulationFetcher::class, IbgePopulationFetcher::class);
        $this->app->bind(HomicideFetcher::class, DatasusHomicideFetcher::class);
        $this->app->bind(GdpFetcher::class, IbgeGdpFetcher::class);
        $this->app->bind(CensusIndicatorFetcher::class, IbgeCensusIndicatorFetcher::class);
        $this->app->bind(IdebFetcher::class, InepIdebFetcher::class);
        $this->app->bind(SanitationFetcher::class, SinisaSanitationFetcher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
