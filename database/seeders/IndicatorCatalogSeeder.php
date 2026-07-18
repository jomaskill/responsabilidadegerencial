<?php

namespace Database\Seeders;

use App\Enums\IndicatorDirection;
use App\Enums\Periodicity;
use App\Models\Indicator;
use App\Models\IndicatorDependency;
use App\Models\IndicatorVersion;
use Illuminate\Database\Seeder;

class IndicatorCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $indicators = [
            ['slug' => 'population', 'name' => 'População', 'description' => 'População residente ou estimada pelo IBGE.', 'theme' => 'demografia', 'unit' => 'pessoas', 'direction' => IndicatorDirection::ContextOnly, 'periodicity' => Periodicity::Annual],
            ['slug' => 'gdp_nominal', 'name' => 'PIB nominal', 'description' => 'Produto Interno Bruto municipal a preços correntes.', 'theme' => 'economia', 'unit' => 'BRL', 'direction' => IndicatorDirection::ContextOnly, 'periodicity' => Periodicity::Annual],
            ['slug' => 'gdp_per_capita', 'name' => 'PIB per capita', 'description' => 'PIB nominal dividido pela população de referência.', 'theme' => 'economia', 'unit' => 'BRL_por_habitante', 'direction' => IndicatorDirection::HigherIsBetter, 'periodicity' => Periodicity::Annual],
            ['slug' => 'gdp_real_growth', 'name' => 'Crescimento real do PIB', 'description' => 'Variação anual do PIB municipal descontada pelo deflator configurado.', 'theme' => 'economia', 'unit' => 'percentual', 'direction' => IndicatorDirection::HigherIsBetter, 'periodicity' => Periodicity::Annual, 'is_derived' => true, 'formula' => '((PIB_t / deflator_t) / (PIB_t-1 / deflator_t-1) - 1) * 100'],
            ['slug' => 'homicide_count', 'name' => 'Óbitos por homicídio', 'description' => 'Óbitos por agressão segundo local de residência, definição CID documentada na versão.', 'theme' => 'seguranca', 'unit' => 'obitos', 'direction' => IndicatorDirection::LowerIsBetter, 'periodicity' => Periodicity::Annual],
            ['slug' => 'homicide_rate', 'name' => 'Taxa de homicídios', 'description' => 'Óbitos por homicídio por 100 mil habitantes.', 'theme' => 'seguranca', 'unit' => 'por_100_mil_habitantes', 'direction' => IndicatorDirection::LowerIsBetter, 'periodicity' => Periodicity::Annual, 'is_derived' => true, 'formula' => '(homicide_count / population) * 100000'],
            ['slug' => 'homicide_rate_rolling_3y', 'name' => 'Taxa média de homicídios em 3 anos', 'description' => 'Soma dos homicídios em três anos dividida pela soma das populações, multiplicada por 100 mil.', 'theme' => 'seguranca', 'unit' => 'por_100_mil_habitantes', 'direction' => IndicatorDirection::LowerIsBetter, 'periodicity' => Periodicity::Annual, 'is_derived' => true, 'formula' => '(sum(homicide_count[t-2:t]) / sum(population[t-2:t])) * 100000'],
            ['slug' => 'water_census', 'name' => 'Cobertura de água — Censo', 'description' => 'Percentual da população ou domicílios com abastecimento adequado, conforme definição versionada.', 'theme' => 'saneamento', 'unit' => 'percentual', 'direction' => IndicatorDirection::HigherIsBetter, 'periodicity' => Periodicity::Decennial],
            ['slug' => 'sewer_census', 'name' => 'Cobertura de esgoto — Censo', 'description' => 'Percentual da população ou domicílios com esgotamento adequado, conforme definição versionada.', 'theme' => 'saneamento', 'unit' => 'percentual', 'direction' => IndicatorDirection::HigherIsBetter, 'periodicity' => Periodicity::Decennial],
            ['slug' => 'water_service_coverage', 'name' => 'Cobertura do serviço de água — SNIS/SINISA', 'description' => 'Indicador anual declarado pelo prestador ao SNIS ou SINISA.', 'theme' => 'saneamento', 'unit' => 'percentual', 'direction' => IndicatorDirection::HigherIsBetter, 'periodicity' => Periodicity::Annual],
            ['slug' => 'sewer_service_coverage', 'name' => 'Cobertura do serviço de esgoto — SNIS/SINISA', 'description' => 'Indicador anual declarado pelo prestador ao SNIS ou SINISA.', 'theme' => 'saneamento', 'unit' => 'percentual', 'direction' => IndicatorDirection::HigherIsBetter, 'periodicity' => Periodicity::Annual],
            ['slug' => 'literacy_rate', 'name' => 'Taxa de alfabetização', 'description' => 'Percentual alfabetizado na população da faixa etária definida pela versão.', 'theme' => 'educacao', 'unit' => 'percentual', 'direction' => IndicatorDirection::HigherIsBetter, 'periodicity' => Periodicity::Decennial],
            ['slug' => 'ideb_initial_years', 'name' => 'IDEB — anos iniciais', 'description' => 'IDEB da rede pública municipal nos anos iniciais do ensino fundamental.', 'theme' => 'educacao', 'unit' => 'indice_0_10', 'direction' => IndicatorDirection::HigherIsBetter, 'periodicity' => Periodicity::Biennial],
            ['slug' => 'ideb_final_years', 'name' => 'IDEB — anos finais', 'description' => 'IDEB da rede pública municipal nos anos finais do ensino fundamental.', 'theme' => 'educacao', 'unit' => 'indice_0_10', 'direction' => IndicatorDirection::HigherIsBetter, 'periodicity' => Periodicity::Biennial],
        ];

        foreach ($indicators as $definition) {
            $formula = $definition['formula'] ?? null;
            unset($definition['formula']);

            $indicator = Indicator::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                $definition + ['aggregation_method' => 'value', 'is_derived' => false, 'is_active' => true],
            );

            IndicatorVersion::query()->updateOrCreate(
                ['indicator_id' => $indicator->id, 'version' => 1],
                [
                    'valid_from' => '2017-01-01',
                    'formula' => $formula,
                    'methodology_url' => match ($indicator->slug) {
                        'homicide_count' => 'https://www.ipea.gov.br/atlasviolencia/pg/26/perguntas-frequentes',
                        'gdp_nominal', 'gdp_per_capita' => 'https://www.ibge.gov.br/estatisticas/economicas/contas-nacionais/9088-produto-interno-bruto-dos-municipios.html',
                        'water_census', 'sewer_census', 'literacy_rate' => 'https://sidra.ibge.gov.br/pesquisa/censo-demografico/demografico-2022',
                        'water_service_coverage', 'sewer_service_coverage' => 'https://www.gov.br/cidades/pt-br/acesso-a-informacao/acoes-e-programas/saneamento/sinisa/resultados-sinisa/resultados-sinisa',
                        'ideb_initial_years', 'ideb_final_years' => 'https://www.gov.br/inep/pt-br/areas-de-atuacao/pesquisas-estatisticas-e-indicadores/ideb/resultados',
                        default => null,
                    },
                    'notes' => match ($indicator->slug) {
                        'homicide_count' => 'CID-10 X85-Y09 e Y35-Y36; município de residência da vítima.',
                        'gdp_nominal' => 'PIB municipal a preços correntes convertido de R$ 1.000 para R$.',
                        'gdp_per_capita' => 'PIB per capita oficial do arquivo-base do IBGE, em R$ por habitante.',
                        'water_census' => 'Domicílios ligados à rede geral e que a utilizam como forma principal; Censo 2022.',
                        'sewer_census' => 'Domicílios com rede geral, rede pluvial ou fossa ligada à rede; Censo 2022.',
                        'literacy_rate' => 'Pessoas de 15 anos ou mais alfabetizadas; Censo 2022.',
                        'water_service_coverage' => 'SINISA, indicador IAG0001: atendimento da população total com rede de abastecimento de água; ano de referência 2023.',
                        'sewer_service_coverage' => 'SINISA, indicador IES0001: atendimento da população total com rede coletora de esgoto; ano de referência 2023.',
                        'ideb_initial_years' => 'IDEB da rede municipal, ensino fundamental regular, anos iniciais; ciclos de 2021 e 2023.',
                        'ideb_final_years' => 'IDEB da rede municipal, ensino fundamental regular, anos finais; ciclos de 2021 e 2023.',
                        default => null,
                    },
                ],
            );
        }

        $this->seedDependencies();
    }

    private function seedDependencies(): void
    {
        $dependencies = [
            'gdp_real_growth' => ['gdp_nominal' => 'nominal_gdp'],
            'homicide_rate' => ['homicide_count' => 'numerator', 'population' => 'denominator'],
            'homicide_rate_rolling_3y' => ['homicide_count' => 'numerator', 'population' => 'denominator'],
        ];

        foreach ($dependencies as $derivedSlug => $inputs) {
            $derived = $this->versionFor($derivedSlug);

            foreach ($inputs as $inputSlug => $role) {
                $input = $this->versionFor($inputSlug);
                IndicatorDependency::query()->updateOrCreate(
                    ['indicator_version_id' => $derived->id, 'depends_on_indicator_version_id' => $input->id],
                    ['role' => $role],
                );
            }
        }
    }

    private function versionFor(string $slug): IndicatorVersion
    {
        return IndicatorVersion::query()
            ->whereHas('indicator', fn ($query) => $query->where('slug', $slug))
            ->firstOrFail();
    }
}
