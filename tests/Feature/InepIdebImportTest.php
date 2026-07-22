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

    Municipality::query()->create([
        'federative_unit_id' => FederativeUnit::query()->where('acronym', 'SP')->value('id'),
        'ibge_code' => '3550308',
        'name' => 'São Paulo',
        'normalized_name' => 'sao paulo',
        'is_active' => true,
    ]);

    Municipality::query()->create([
        'federative_unit_id' => FederativeUnit::query()->where('acronym', 'SP')->value('id'),
        'ibge_code' => '3548500',
        'name' => 'Santos',
        'normalized_name' => 'santos',
        'is_active' => true,
    ]);

    config()->set('municipal_data.ideb.expected_municipalities', 2);
    config()->set('municipal_data.ideb.datasets.ideb_initial_years.expected_source_rows', 2);
    config()->set('municipal_data.ideb.datasets.ideb_final_years.expected_source_rows', 1);
    config()->set('municipal_data.ideb.datasets.ideb_initial_years.sha256');
    config()->set('municipal_data.ideb.datasets.ideb_final_years.sha256');
});

test('official municipal IDEB workbooks preserve missing and not applicable results', function () {
    $initialConfiguration = config('municipal_data.ideb.datasets.ideb_initial_years');
    $finalConfiguration = config('municipal_data.ideb.datasets.ideb_final_years');

    Http::fake([
        $initialConfiguration['url'] => Http::response(idebOfficialPackage(
            $initialConfiguration['xlsx_entry'],
            [
                ['code' => '3550308', '2017' => '5.2', '2019' => '5.5', '2021' => '5.8', '2023' => '6.2'],
                ['code' => '3548500', '2017' => '5.0', '2019' => '5.4', '2021' => '-', '2023' => '6.0'],
            ],
        )),
        $finalConfiguration['url'] => Http::response(idebOfficialPackage(
            $finalConfiguration['xlsx_entry'],
            [
                ['code' => '3550308', '2017' => '4.0', '2019' => '4.3', '2021' => '-', '2023' => '4.8'],
            ],
        )),
    ]);

    $arguments = [
        'source' => 'inep-ideb',
        '--from' => '2017',
        '--to' => '2025',
    ];

    $this->artisan('data:import', $arguments)
        ->expectsOutput('O INEP ainda não publicou o IDEB municipal de 2025.')
        ->assertSuccessful();

    $observations = IndicatorObservation::query()
        ->with(['indicatorVersion.indicator', 'municipality'])
        ->whereHas('indicatorVersion.indicator', fn ($query) => $query->whereIn('slug', [
            'ideb_initial_years',
            'ideb_final_years',
        ]))
        ->get()
        ->keyBy(fn (IndicatorObservation $observation): string => implode(':', [
            $observation->indicatorVersion->indicator->slug,
            $observation->municipality->ibge_code,
            $observation->reference_year,
        ]));

    expect($observations)->toHaveCount(16)
        ->and((float) $observations['ideb_initial_years:3550308:2017']->value)->toBe(5.2)
        ->and((float) $observations['ideb_initial_years:3550308:2019']->value)->toBe(5.5)
        ->and((float) $observations['ideb_initial_years:3550308:2021']->value)->toBe(5.8)
        ->and((float) $observations['ideb_initial_years:3550308:2023']->value)->toBe(6.2)
        ->and($observations['ideb_initial_years:3548500:2021']->availability_status)->toBe(AvailabilityStatus::MissingFromSource)
        ->and($observations['ideb_initial_years:3548500:2021']->metadata['source_marker'])->toBe('-')
        ->and((float) $observations['ideb_initial_years:3548500:2023']->value)->toBe(6.0)
        ->and($observations['ideb_final_years:3550308:2021']->availability_status)->toBe(AvailabilityStatus::MissingFromSource)
        ->and((float) $observations['ideb_final_years:3550308:2023']->value)->toBe(4.8)
        ->and($observations['ideb_final_years:3548500:2021']->availability_status)->toBe(AvailabilityStatus::NotApplicable)
        ->and($observations['ideb_final_years:3548500:2023']->availability_status)->toBe(AvailabilityStatus::NotApplicable);

    expect(SourceRelease::query()->count())->toBe(4);

    foreach (SourceRelease::query()->get() as $release) {
        expect(Storage::disk('local')->exists($release->artifact_path))->toBeTrue();
    }

    $this->artisan('data:import', $arguments)->assertSuccessful();

    expect(IndicatorObservation::query()->count())->toBe(16)
        ->and(SourceRelease::query()->count())->toBe(4);
    Http::assertSentCount(4);
});

/**
 * @param  list<array{code: string, 2017: string, 2019: string, 2021: string, 2023: string}>  $rows
 */
function idebOfficialPackage(string $xlsxEntry, array $rows): string
{
    $xlsx = temporaryZipFile('ideb-xlsx-test-');
    $package = temporaryZipFile('ideb-package-test-');

    try {
        $workbook = new ZipArchive;
        expect($workbook->open($xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
        $workbook->addFromString('xl/sharedStrings.xml', idebSharedStrings());
        $workbook->addFromString('xl/worksheets/sheet1.xml', idebWorksheet($rows));
        $workbook->close();

        $archive = new ZipArchive;
        expect($archive->open($package, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
        $archive->addFile($xlsx, $xlsxEntry);
        $archive->close();

        $contents = file_get_contents($package);
        expect($contents)->toBeString();

        return $contents;
    } finally {
        @unlink($xlsx);
        @unlink($package);
    }
}

function idebSharedStrings(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="8" uniqueCount="8">'
        .'<si><t>CO_MUNICIPIO</t></si><si><t>REDE</t></si>'
        .'<si><t>VL_OBSERVADO_2017</t></si><si><t>VL_OBSERVADO_2019</t></si>'
        .'<si><t>VL_OBSERVADO_2021</t></si><si><t>VL_OBSERVADO_2023</t></si>'
        .'<si><t>Municipal</t></si><si><t>-</t></si></sst>';
}

/**
 * @param  list<array{code: string, 2017: string, 2019: string, 2021: string, 2023: string}>  $rows
 */
function idebWorksheet(array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="10"><c r="A10" t="s"><v>0</v></c><c r="D10" t="s"><v>1</v></c>'
        .'<c r="E10" t="s"><v>2</v></c><c r="F10" t="s"><v>3</v></c>'
        .'<c r="G10" t="s"><v>4</v></c><c r="H10" t="s"><v>5</v></c></row>';

    foreach ($rows as $index => $row) {
        $number = $index + 11;
        $xml .= '<row r="'.$number.'"><c r="A'.$number.'"><v>'.$row['code'].'</v></c>'
            .'<c r="D'.$number.'" t="s"><v>6</v></c>'
            .idebResultCell('E', $number, $row[2017])
            .idebResultCell('F', $number, $row[2019])
            .idebResultCell('G', $number, $row[2021])
            .idebResultCell('H', $number, $row[2023]).'</row>';
    }

    return $xml.'</sheetData></worksheet>';
}

function idebResultCell(string $column, int $row, string $value): string
{
    if ($value === '-') {
        return '<c r="'.$column.$row.'" t="s"><v>7</v></c>';
    }

    return '<c r="'.$column.$row.'"><v>'.$value.'</v></c>';
}

function temporaryZipFile(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), $prefix);
    expect($path)->toBeString();

    return $path;
}
