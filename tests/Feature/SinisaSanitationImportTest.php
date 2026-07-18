<?php

use App\Enums\AvailabilityStatus;
use App\Models\FederativeUnit;
use App\Models\IndicatorObservation;
use App\Models\Municipality;
use App\Models\SourceRelease;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    $this->seed(DatabaseSeeder::class);

    foreach ([
        ['code' => '3550308', 'name' => 'São Paulo'],
        ['code' => '3548500', 'name' => 'Santos'],
        ['code' => '1101807', 'name' => 'Vale do Paraíso'],
    ] as $municipality) {
        Municipality::query()->create([
            'federative_unit_id' => FederativeUnit::query()->where('acronym', str_starts_with($municipality['code'], '11') ? 'RO' : 'SP')->value('id'),
            'ibge_code' => $municipality['code'],
            'name' => $municipality['name'],
            'normalized_name' => str($municipality['name'])->ascii()->lower()->toString(),
            'is_active' => true,
        ]);
    }

    config()->set('municipal_data.sinisa.expected_municipalities', 3);
    config()->set('municipal_data.sinisa.datasets.water_service_coverage.expected_source_rows', 3);
    config()->set('municipal_data.sinisa.datasets.sewer_service_coverage.expected_source_rows', 1);
    config()->set('municipal_data.sinisa.datasets.sewer_service_coverage.corrections.0.expected_source_rows', 1);
    config()->set('municipal_data.sinisa.datasets.sewer_service_coverage.corrections.1.expected_source_rows', 0);
    config()->set('municipal_data.sinisa.datasets.sewer_service_coverage.corrections.2.expected_source_rows', 0);

    foreach (['water_service_coverage', 'sewer_service_coverage'] as $slug) {
        config()->set("municipal_data.sinisa.datasets.{$slug}.sha256");
        config()->set("municipal_data.sinisa.datasets.{$slug}.local_path");
    }

    foreach ([0, 1, 2] as $index) {
        config()->set("municipal_data.sinisa.datasets.sewer_service_coverage.corrections.{$index}.sha256");
        config()->set("municipal_data.sinisa.datasets.sewer_service_coverage.corrections.{$index}.local_path");
    }
});

test('official SINISA workbooks preserve corrections and missing results', function () {
    $water = config('municipal_data.sinisa.datasets.water_service_coverage');
    $sewer = config('municipal_data.sinisa.datasets.sewer_service_coverage');
    $waterWorkbook = sinisaTestWorkbook('IAG0001', [
        ['code' => '3550308', 'value' => '99.63'],
        ['code' => '3548500', 'value' => '80'],
        ['code' => '1101807', 'value' => 'Não Calc.- Dados Não Inf.'],
    ]);
    $sewerWorkbook = sinisaTestWorkbook('IES0001', [
        ['code' => '3550308', 'value' => '98.49'],
    ]);
    $correctionWorkbook = sinisaTestWorkbook('IES0001', [
        ['code' => '3548500', 'value' => '90.38'],
    ]);
    $emptyCorrectionWorkbook = sinisaTestWorkbook('IES0001', []);
    $waterPackage = sinisaTestPackage($water['xlsx_entry'], $waterWorkbook);
    $sewerPackage = sinisaTestPackage($sewer['xlsx_entry'], $sewerWorkbook);
    $fakes = [
        $water['url'] => Http::response($waterPackage),
        $sewer['url'] => Http::response($sewerPackage),
    ];

    config()->set('municipal_data.sinisa.datasets.water_service_coverage.sha256', hash('sha256', $waterPackage));
    config()->set('municipal_data.sinisa.datasets.sewer_service_coverage.sha256', hash('sha256', $sewerPackage));

    foreach ($sewer['corrections'] as $index => $correction) {
        $correctionPackage = $index === 0 ? $correctionWorkbook : $emptyCorrectionWorkbook;
        $fakes[$correction['url']] = Http::response($correctionPackage);
        config()->set("municipal_data.sinisa.datasets.sewer_service_coverage.corrections.{$index}.sha256", hash('sha256', $correctionPackage));
    }

    Http::fake($fakes);

    $arguments = [
        'source' => 'sinisa',
        '--from' => '2021',
        '--to' => '2025',
    ];

    $this->artisan('data:import', $arguments)
        ->expectsOutput('O SINISA ainda não publicou as planilhas finais auditadas para este exercício.')
        ->assertSuccessful();

    $observations = IndicatorObservation::query()
        ->with(['indicatorVersion.indicator', 'municipality'])
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->whereIn('slug', [
            'water_service_coverage',
            'sewer_service_coverage',
        ]))
        ->get()
        ->keyBy(fn (IndicatorObservation $observation): string => $observation->indicatorVersion->indicator->slug.':'.$observation->municipality->ibge_code);

    expect($observations)->toHaveCount(6)
        ->and((float) $observations['water_service_coverage:3550308']->value)->toBe(99.63)
        ->and($observations['water_service_coverage:1101807']->availability_status)->toBe(AvailabilityStatus::MissingFromSource)
        ->and($observations['water_service_coverage:1101807']->metadata['source_marker'])->toBe('Não Calc.- Dados Não Inf.')
        ->and((float) $observations['sewer_service_coverage:3548500']->value)->toBe(90.38)
        ->and($observations['sewer_service_coverage:3548500']->metadata['source_layer'])->toBe('correction_1')
        ->and($observations['sewer_service_coverage:1101807']->availability_status)->toBe(AvailabilityStatus::MissingFromSource)
        ->and($observations['sewer_service_coverage:1101807']->metadata['source_marker'])->toBe('municipality_absent_from_source')
        ->and($observations['sewer_service_coverage:3550308']->metadata['indicator_code'])->toBe('IES0001');

    $release = SourceRelease::query()->sole();

    expect(Storage::disk('local')->exists($release->artifact_path))->toBeTrue()
        ->and($release->reference_year)->toBe(2023);

    $this->artisan('data:import', $arguments)->assertSuccessful();

    expect(IndicatorObservation::query()->count())->toBe(6)
        ->and(SourceRelease::query()->count())->toBe(1);
    Http::assertSentCount(10);
});

/** @param list<array{code: string, value: string}> $rows */
function sinisaTestWorkbook(string $indicatorCode, array $rows): string
{
    $path = sinisaTemporaryFile('sinisa-xlsx-test-');

    try {
        $archive = new ZipArchive;
        expect($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="10"><c r="A10" t="inlineStr"><is><t>cod_IBGE</t></is></c>'
            .'<c r="B10" t="inlineStr"><is><t>'.$indicatorCode.'</t></is></c></row>';

        foreach ($rows as $index => $row) {
            $number = $index + 11;
            $valueCell = is_numeric($row['value'])
                ? '<c r="B'.$number.'"><v>'.$row['value'].'</v></c>'
                : '<c r="B'.$number.'" t="inlineStr"><is><t>'.htmlspecialchars($row['value'], ENT_XML1).'</t></is></c>';
            $xml .= '<row r="'.$number.'"><c r="A'.$number.'"><v>'.$row['code'].'</v></c>'.$valueCell.'</row>';
        }

        $archive->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        $archive->close();
        $contents = file_get_contents($path);
        expect($contents)->toBeString();

        return $contents;
    } finally {
        @unlink($path);
    }
}

function sinisaTestPackage(string $xlsxEntry, string $workbook): string
{
    $path = sinisaTemporaryFile('sinisa-package-test-');

    try {
        $archive = new ZipArchive;
        expect($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
        $archive->addFromString($xlsxEntry, $workbook);
        $archive->close();
        $contents = file_get_contents($path);
        expect($contents)->toBeString();

        return $contents;
    } finally {
        @unlink($path);
    }
}

function sinisaTemporaryFile(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);
    expect($path)->toBeString();

    return $path;
}
