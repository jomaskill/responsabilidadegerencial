<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class DataSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sources = [
            ['slug' => 'ibge-localidades', 'name' => 'IBGE Localidades', 'publisher' => 'IBGE', 'acquisition_method' => 'api', 'homepage_url' => 'https://servicodados.ibge.gov.br/api/docs/localidades'],
            ['slug' => 'ibge-populacao', 'name' => 'Estimativas da população', 'publisher' => 'IBGE', 'acquisition_method' => 'csv_or_api', 'homepage_url' => 'https://www.ibge.gov.br/estatisticas/sociais/populacao.html'],
            ['slug' => 'ibge-pib-municipios', 'name' => 'PIB dos Municípios', 'publisher' => 'IBGE', 'acquisition_method' => 'official_zip_fixed_width', 'homepage_url' => 'https://www.ibge.gov.br/estatisticas/economicas/contas-nacionais/9088-produto-interno-bruto-dos-municipios.html'],
            ['slug' => 'datasus-sim', 'name' => 'Sistema de Informações sobre Mortalidade', 'publisher' => 'Ministério da Saúde', 'acquisition_method' => 'tabnet', 'homepage_url' => 'https://datasus.saude.gov.br/mortalidade-desde-1996-pela-cid-10/'],
            ['slug' => 'ibge-censo-2022', 'name' => 'Censo Demográfico 2022', 'publisher' => 'IBGE', 'acquisition_method' => 'sidra_api', 'homepage_url' => 'https://sidra.ibge.gov.br/pesquisa/censo-demografico/demografico-2022'],
            ['slug' => 'snis', 'name' => 'Sistema Nacional de Informações sobre Saneamento', 'publisher' => 'Ministério das Cidades', 'acquisition_method' => 'csv', 'homepage_url' => 'https://www.gov.br/cidades/pt-br/acesso-a-informacao/acoes-e-programas/saneamento/snis'],
            ['slug' => 'sinisa', 'name' => 'Sistema Nacional de Informações em Saneamento Básico', 'publisher' => 'Ministério das Cidades', 'acquisition_method' => 'official_zip_xlsx', 'homepage_url' => 'https://www.gov.br/cidades/pt-br/acesso-a-informacao/acoes-e-programas/saneamento/sinisa/resultados-sinisa/resultados-sinisa'],
            ['slug' => 'inep-ideb', 'name' => 'Índice de Desenvolvimento da Educação Básica', 'publisher' => 'INEP', 'acquisition_method' => 'official_zip_xlsx', 'homepage_url' => 'https://www.gov.br/inep/pt-br/areas-de-atuacao/pesquisas-estatisticas-e-indicadores/ideb/resultados'],
            ['slug' => 'ibge-alfabetizacao', 'name' => 'Alfabetização no Censo Demográfico', 'publisher' => 'IBGE', 'acquisition_method' => 'sidra_csv', 'homepage_url' => 'https://sidra.ibge.gov.br/'],
            ['slug' => 'system-calculated', 'name' => 'Indicadores calculados pelo sistema', 'publisher' => 'Responsabilidade Gerencial', 'acquisition_method' => 'derived', 'homepage_url' => 'https://localhost'],
            ['slug' => 'manual-compiled', 'name' => 'Compilado manual auditado', 'publisher' => 'Responsabilidade Gerencial', 'acquisition_method' => 'manual_csv', 'homepage_url' => 'https://localhost'],
            ['slug' => 'tse-candidatos', 'name' => 'Candidaturas municipais', 'publisher' => 'Tribunal Superior Eleitoral', 'acquisition_method' => 'official_zip_csv', 'homepage_url' => 'https://dadosabertos.tse.jus.br/group/candidatos'],
            ['slug' => 'tse-municipality-codes', 'name' => 'Correspondência de municípios TSE–IBGE', 'publisher' => 'Tribunal Superior Eleitoral', 'acquisition_method' => 'official_zip_csv', 'homepage_url' => 'https://dadosabertos.tse.jus.br/dataset/codigos-oficiais-de-uf-e-municipios-segundo-o-tse-e-o-ibge'],
        ];

        foreach ($sources as $source) {
            DataSource::query()->updateOrCreate(['slug' => $source['slug']], $source + ['is_active' => true]);
        }
    }
}
