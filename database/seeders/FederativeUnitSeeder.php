<?php

namespace Database\Seeders;

use App\Models\FederativeUnit;
use Illuminate\Database\Seeder;

class FederativeUnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $units = [
            ['ibge_code' => '11', 'acronym' => 'RO', 'name' => 'Rondônia', 'region' => 'Norte'],
            ['ibge_code' => '12', 'acronym' => 'AC', 'name' => 'Acre', 'region' => 'Norte'],
            ['ibge_code' => '13', 'acronym' => 'AM', 'name' => 'Amazonas', 'region' => 'Norte'],
            ['ibge_code' => '14', 'acronym' => 'RR', 'name' => 'Roraima', 'region' => 'Norte'],
            ['ibge_code' => '15', 'acronym' => 'PA', 'name' => 'Pará', 'region' => 'Norte'],
            ['ibge_code' => '16', 'acronym' => 'AP', 'name' => 'Amapá', 'region' => 'Norte'],
            ['ibge_code' => '17', 'acronym' => 'TO', 'name' => 'Tocantins', 'region' => 'Norte'],
            ['ibge_code' => '21', 'acronym' => 'MA', 'name' => 'Maranhão', 'region' => 'Nordeste'],
            ['ibge_code' => '22', 'acronym' => 'PI', 'name' => 'Piauí', 'region' => 'Nordeste'],
            ['ibge_code' => '23', 'acronym' => 'CE', 'name' => 'Ceará', 'region' => 'Nordeste'],
            ['ibge_code' => '24', 'acronym' => 'RN', 'name' => 'Rio Grande do Norte', 'region' => 'Nordeste'],
            ['ibge_code' => '25', 'acronym' => 'PB', 'name' => 'Paraíba', 'region' => 'Nordeste'],
            ['ibge_code' => '26', 'acronym' => 'PE', 'name' => 'Pernambuco', 'region' => 'Nordeste'],
            ['ibge_code' => '27', 'acronym' => 'AL', 'name' => 'Alagoas', 'region' => 'Nordeste'],
            ['ibge_code' => '28', 'acronym' => 'SE', 'name' => 'Sergipe', 'region' => 'Nordeste'],
            ['ibge_code' => '29', 'acronym' => 'BA', 'name' => 'Bahia', 'region' => 'Nordeste'],
            ['ibge_code' => '31', 'acronym' => 'MG', 'name' => 'Minas Gerais', 'region' => 'Sudeste'],
            ['ibge_code' => '32', 'acronym' => 'ES', 'name' => 'Espírito Santo', 'region' => 'Sudeste'],
            ['ibge_code' => '33', 'acronym' => 'RJ', 'name' => 'Rio de Janeiro', 'region' => 'Sudeste'],
            ['ibge_code' => '35', 'acronym' => 'SP', 'name' => 'São Paulo', 'region' => 'Sudeste'],
            ['ibge_code' => '41', 'acronym' => 'PR', 'name' => 'Paraná', 'region' => 'Sul'],
            ['ibge_code' => '42', 'acronym' => 'SC', 'name' => 'Santa Catarina', 'region' => 'Sul'],
            ['ibge_code' => '43', 'acronym' => 'RS', 'name' => 'Rio Grande do Sul', 'region' => 'Sul'],
            ['ibge_code' => '50', 'acronym' => 'MS', 'name' => 'Mato Grosso do Sul', 'region' => 'Centro-Oeste'],
            ['ibge_code' => '51', 'acronym' => 'MT', 'name' => 'Mato Grosso', 'region' => 'Centro-Oeste'],
            ['ibge_code' => '52', 'acronym' => 'GO', 'name' => 'Goiás', 'region' => 'Centro-Oeste'],
            ['ibge_code' => '53', 'acronym' => 'DF', 'name' => 'Distrito Federal', 'region' => 'Centro-Oeste'],
        ];

        foreach ($units as $unit) {
            FederativeUnit::query()->updateOrCreate(['ibge_code' => $unit['ibge_code']], $unit);
        }
    }
}
