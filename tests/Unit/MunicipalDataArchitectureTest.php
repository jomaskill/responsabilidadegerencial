<?php

use App\Contracts\MunicipalData\CensusIndicatorFetcher;
use App\Contracts\MunicipalData\GdpFetcher;
use App\Contracts\MunicipalData\HomicideFetcher;
use App\Contracts\MunicipalData\IdebFetcher;
use App\Contracts\MunicipalData\PopulationFetcher;
use App\Contracts\MunicipalData\SanitationFetcher;
use App\Contracts\MunicipalData\SourceFetcher;
use App\DTO\MunicipalData\ImportPeriod;
use App\Infrastructure\MunicipalData\Fetchers\DatasusHomicideFetcher;
use App\Infrastructure\MunicipalData\Fetchers\IbgeCensusIndicatorFetcher;
use App\Infrastructure\MunicipalData\Fetchers\IbgeGdpFetcher;
use App\Infrastructure\MunicipalData\Fetchers\IbgeMunicipalityFetcher;
use App\Infrastructure\MunicipalData\Fetchers\IbgePopulationFetcher;
use App\Infrastructure\MunicipalData\Fetchers\InepIdebFetcher;
use App\Infrastructure\MunicipalData\Fetchers\SinisaSanitationFetcher;
use App\Support\MunicipalData\IndicatorValueRangeValidator;

test('import periods are immutable validated boundaries', function () {
    $period = new ImportPeriod(2021, 2025);

    expect($period->years())->toBe([2021, 2022, 2023, 2024, 2025])
        ->and($period->contains(2023))->toBeTrue()
        ->and($period->contains(2020))->toBeFalse()
        ->and(fn () => new ImportPeriod(2025, 2021))
        ->toThrow(InvalidArgumentException::class);
});

test('indicator range validation is independent from persistence', function (?string $value, string $unit, ?string $violation) {
    expect((new IndicatorValueRangeValidator)->violation($value, $unit))->toBe($violation);
})->with([
    'valid percentage' => ['99.5', 'percentual', null],
    'invalid percentage' => ['100.1', 'percentual', 'Percentage is outside the expected 0–100 range.'],
    'invalid index' => ['11', 'indice_0_10', 'Index is outside the expected 0–10 range.'],
    'negative count' => ['-1', 'obitos', 'Value cannot be negative for this indicator.'],
    'missing value' => [null, 'percentual', null],
]);

arch('municipal data DTOs are immutable and independent from infrastructure')
    ->expect('App\DTO\MunicipalData')
    ->toBeReadonly()
    ->not->toUse([
        'App\Models',
        'Illuminate\Database',
        'Illuminate\Http',
        'Livewire',
    ]);

arch('municipal data support is independent from presentation state')
    ->expect('App\Support\MunicipalData')
    ->not->toUse([
        'App\Http',
        'App\Livewire',
        'App\Models',
        'Illuminate\Database',
        'Illuminate\Http',
        'Livewire',
    ]);

arch('municipal data actions are independent from presentation state')
    ->expect('App\Actions\MunicipalData')
    ->not->toUse([
        'App\Http',
        'App\Livewire',
        'Illuminate\Http',
        'Livewire',
    ]);

arch('municipal data commands do not persist directly')
    ->expect([
        'App\Console\Commands\AuditMunicipalData',
        'App\Console\Commands\ImportMunicipalData',
        'App\Console\Commands\RecalculateMunicipalIndicators',
        'App\Console\Commands\ReportMunicipalCoverage',
    ])
    ->not->toUse([
        'App\Models',
        'Illuminate\Database',
    ]);

arch('municipal data integration boundaries are contracts')
    ->expect('App\Contracts\MunicipalData')
    ->toBeInterfaces();

test('municipal data fetchers are infrastructure adapters', function (string $adapter, string $contract) {
    expect(is_subclass_of($adapter, $contract))->toBeTrue();
})->with([
    [IbgeMunicipalityFetcher::class, SourceFetcher::class],
    [IbgePopulationFetcher::class, PopulationFetcher::class],
    [DatasusHomicideFetcher::class, HomicideFetcher::class],
    [IbgeGdpFetcher::class, GdpFetcher::class],
    [IbgeCensusIndicatorFetcher::class, CensusIndicatorFetcher::class],
    [InepIdebFetcher::class, IdebFetcher::class],
    [SinisaSanitationFetcher::class, SanitationFetcher::class],
]);
