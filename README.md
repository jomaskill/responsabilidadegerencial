# Responsabilidade Gerencial

Plataforma cívica para acompanhar a evolução das gestões municipais brasileiras com dados oficiais, metodologia explícita e fontes auditáveis.

O produto é centrado nos prefeitos: a página inicial destaca as gestões que mais avançaram nos indicadores durante o mandato e permite abrir cada prefeito para consultar o detalhamento das melhorias, quedas e indicadores estáveis.

> A evolução observada durante uma gestão é uma associação temporal. Ela não comprova que o prefeito causou o resultado.

## O que a aplicação oferece

- ranking de evolução das gestões de 2017–2020 e 2021–2024;
- acompanhamento metodológico do mandato 2025–2028, sem publicar posições prematuras;
- detalhamento por prefeito, município e indicador;
- ranking municipal consolidado e rankings individuais;
- busca de municípios pelo nome ou código IBGE;
- procedência das observações, com fonte, versão, data e checksum do arquivo bruto;
- metodologia versionada, pesos visíveis e tratamento explícito de dados ausentes.

## Regra de publicação dos indicadores

Um indicador só participa dos rankings quando possui dados comparáveis para todos os municípios existentes no ano da fonte.

- Se o ano mais recente estiver incompleto, o sistema procura o último ano nacionalmente completo.
- Se não houver ano completo, o indicador é ocultado para todos.
- Ausência, supressão ou dado ainda não publicado nunca é transformado em zero.
- Os pesos dos indicadores restantes são redistribuídos pelo motor de cálculo.

Essa regra impede que um município seja favorecido ou prejudicado apenas porque determinada fonte possui cobertura parcial.

## Fontes oficiais

O projeto integra dados de:

- **IBGE:** municípios, população, PIB municipal, alfabetização, água e esgoto do Censo;
- **DATASUS/SIM:** homicídios e taxas por 100 mil habitantes;
- **INEP:** IDEB da rede municipal;
- **Ministério das Cidades/SINISA:** cobertura anual dos serviços de água e esgoto;
- **TSE:** prefeitos eleitos e correspondência entre os códigos TSE e IBGE.

Consulte [FONTES_DOS_DADOS.md](FONTES_DOS_DADOS.md) para definições, arquivos oficiais e limitações de cobertura.

## Tecnologias

- PHP 8.5;
- Laravel 13;
- Livewire 4 e Flux UI 2;
- Tailwind CSS 4 e Vite 8;
- PostgreSQL;
- Pest 4 e PHPStan/Larastan.

## Instalação local

### Requisitos

- PHP 8.5 com as extensões `curl`, `dom`, `libxml`, `pdo`, `xmlreader` e `zip`;
- Composer;
- Node.js e npm;
- PostgreSQL.

### Configuração

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure a conexão PostgreSQL no arquivo `.env` e execute:

```bash
php artisan migrate
php artisan db:seed --class=DatabaseSeeder
npm install
npm run build
```

Para iniciar o ambiente de desenvolvimento completo:

```bash
composer run dev
```

Com Laravel Herd, a aplicação também fica disponível em `http://responsabilidadegerencial.test` sem iniciar outro servidor PHP.

## Carga dos dados

Os arquivos oficiais são preservados antes da transformação. Cada carga registra sua origem, checksum, data de coleta e estado de processamento.

Exemplos:

```bash
php artisan data:import ibge-localidades --to=2025 --no-interaction
php artisan data:import ibge-censo-2022 --no-interaction
php artisan data:import tse-administrations --from=2016 --to=2016 --no-interaction
php artisan data:import tse-administrations --from=2020 --to=2020 --no-interaction
php artisan data:import tse-administrations --from=2024 --to=2024 --no-interaction
```

Alguns arquivos, especialmente IDEB e SINISA, exigem mais memória:

```bash
php -d memory_limit=512M artisan data:import inep-ideb --from=2017 --to=2023 --no-interaction
php -d memory_limit=512M artisan data:import sinisa --from=2023 --to=2023 --no-interaction
```

Depois de atualizar as fontes, aqueça os resultados públicos:

```bash
php artisan data:warm-public-cache --no-interaction
```

A ordem completa de importação, as variáveis opcionais para arquivos locais e as recomendações de produção estão em [GUIA_PRODUCAO.md](GUIA_PRODUCAO.md).

## Qualidade e testes

O comando principal executa a verificação de formatação, a análise estática e os testes:

```bash
composer test
```

Também é possível executar as verificações separadamente:

```bash
vendor/bin/pint --format agent
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
php artisan test --compact
```

## Páginas públicas

| Caminho | Conteúdo |
| --- | --- |
| `/` | Destaque das gestões com maior evolução |
| `/prefeitos` | Ranking completo e detalhamento dos prefeitos |
| `/municipios` | Busca e listagem municipal |
| `/municipios/{codigo-ibge}` | Ficha do município e da gestão |
| `/ranking` | Ranking municipal consolidado |
| `/metodologia` | Explicação do cálculo |
| `/dados-abertos` | Indicadores e fontes atualmente publicados |

A aplicação não disponibiliza API pública.

## Documentação

- [Metodologia do ranking](METODOLOGIA_RANKING.md)
- [Fontes dos dados](FONTES_DOS_DADOS.md)
- [Guia de produção](GUIA_PRODUCAO.md)
- [Definição do produto](PRODUCT.md)
- [Sistema visual](DESIGN.md)

## Licença

O projeto está licenciado sob a licença MIT.
