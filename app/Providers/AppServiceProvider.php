<?php

namespace App\Providers;

use App\MunicipalData\Auditors\MunicipalCoverageAuditor;
use App\MunicipalData\Calculators\HomicideIndicatorCalculator;
use App\MunicipalData\CensusIndicatorFetcher;
use App\MunicipalData\DataQualityAuditor;
use App\MunicipalData\Fetchers\DatasusHomicideFetcher;
use App\MunicipalData\Fetchers\IbgeCensusIndicatorFetcher;
use App\MunicipalData\Fetchers\IbgeGdpFetcher;
use App\MunicipalData\Fetchers\IbgeMunicipalityFetcher;
use App\MunicipalData\Fetchers\IbgePopulationFetcher;
use App\MunicipalData\Fetchers\InepIdebFetcher;
use App\MunicipalData\Fetchers\SinisaSanitationFetcher;
use App\MunicipalData\GdpFetcher;
use App\MunicipalData\HomicideFetcher;
use App\MunicipalData\IdebFetcher;
use App\MunicipalData\IndicatorCalculator;
use App\MunicipalData\ObservationTransformer;
use App\MunicipalData\Parsers\DelimitedSourceParser;
use App\MunicipalData\PopulationFetcher;
use App\MunicipalData\SanitationFetcher;
use App\MunicipalData\SourceFetcher;
use App\MunicipalData\SourceParser;
use App\MunicipalData\Transformers\CanonicalObservationTransformer;
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
        $this->app->bind(SourceParser::class, DelimitedSourceParser::class);
        $this->app->bind(ObservationTransformer::class, CanonicalObservationTransformer::class);
        $this->app->bind(IndicatorCalculator::class, HomicideIndicatorCalculator::class);
        $this->app->bind(DataQualityAuditor::class, MunicipalCoverageAuditor::class);
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
