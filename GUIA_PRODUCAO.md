# Guia de produção

Este guia descreve como instalar, configurar, carregar e auditar os dados municipais do MVP em produção.

## 1. Requisitos

- PHP 8.5 com extensões exigidas pelo Laravel, incluindo `curl`, `dom`, `libxml`, `pdo`, `xmlreader` e `zip`.
- Banco de dados configurado pelo Laravel.
- Pelo menos 512 MB de memória para processar os arquivos IDEB e SINISA.
- Saída HTTPS para IBGE, INEP, DATASUS, Ministério das Cidades e TSE.
- Certificados de autoridades confiáveis configurados no PHP/cURL.
- Armazenamento persistente para os arquivos brutos. Não use o disco efêmero do contêiner.
- Permissão de escrita em `storage` e `bootstrap/cache`.

Os arquivos brutos e seus checksums fazem parte da trilha de auditoria. O banco e o armazenamento de artefatos devem ser incluídos na política de backup.

## 2. Variáveis de ambiente

Além das variáveis padrão do Laravel, configure:

```dotenv
APP_ENV=production
APP_DEBUG=false

MUNICIPAL_DATA_DISK=local
MUNICIPAL_DATA_ARTIFACT_PATH=municipal-data/sources
MUNICIPAL_DATA_HTTP_TIMEOUT=180
MUNICIPAL_DATA_HTTP_CONNECT_TIMEOUT=15
MUNICIPAL_DATA_HTTP_RETRIES=3
MUNICIPAL_DATA_HTTP_RETRY_SLEEP=1000

CACHE_STORE=redis
```

`MUNICIPAL_DATA_DISK` deve apontar para um disco persistente configurado em `config/filesystems.php`. Em servidores com contêiner descartável, configure um volume persistente ou um disco de objetos compatível com a aplicação antes de executar as cargas.

Use um cache compartilhado, preferencialmente Redis, quando houver mais de uma instância da aplicação. O ranking usa uma janela fresca de 10 minutos e stale de até 30 minutos.

As variáveis devem existir antes de executar `php artisan config:cache`.

## 3. Primeira implantação

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --class=DatabaseSeeder --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan data:warm-public-cache --no-interaction
```

Se o frontend for servido pela mesma implantação:

```bash
npm ci
npm run build
```

## 4. Ordem da carga inicial

O cadastro municipal deve ser carregado antes das observações.

```bash
php artisan data:import ibge-localidades --to=2025 --no-interaction
php artisan data:import ibge-populacao --from=2021 --to=2025 --no-interaction
php artisan data:import datasus-sim --from=2021 --to=2024 --no-interaction
php artisan data:import ibge-pib-municipios --from=2021 --to=2023 --no-interaction
php artisan data:import ibge-censo-2022 --no-interaction
php -d memory_limit=512M artisan data:import inep-ideb --from=2017 --to=2023 --no-interaction
php -d memory_limit=512M artisan data:import sinisa --from=2023 --to=2023 --no-interaction
php -d memory_limit=512M artisan data:import tse-administrations --from=2020 --to=2020 --no-interaction
php -d memory_limit=512M artisan data:import tse-administrations --from=2024 --to=2024 --no-interaction
```

Uma execução repetida com os mesmos arquivos oficiais é idempotente: o artefato e as observações não são duplicados.

Depois da carga inicial, aqueça novamente os resultados públicos:

```bash
php artisan data:warm-public-cache --no-interaction
```

O comando prepara a home, os rankings consolidados de 2021 a 2025, os destaques por indicador e a evolução das gestões. Ele não cria tabelas nem persiste pontuações.

## 5. Fontes com arquivo local opcional

Normalmente, IDEB e SINISA são baixados diretamente das fontes oficiais. Caso o portal esteja indisponível ou apresente problema de certificado, baixe os arquivos oficiais por um canal HTTPS confiável, valide sua origem e configure os caminhos abaixo. O importador sempre confere os checksums auditados antes de aceitar o conteúdo.

### IDEB

```dotenv
MUNICIPAL_DATA_IDEB_INITIAL_FILE=/dados/fontes/ideb-iniciais-2023.zip
MUNICIPAL_DATA_IDEB_FINAL_FILE=/dados/fontes/ideb-finais-2023.zip
```

### SINISA 2023

```dotenv
MUNICIPAL_DATA_SINISA_WATER_FILE=/dados/fontes/sinisa-agua-ref2023.zip
MUNICIPAL_DATA_SINISA_SEWER_FILE=/dados/fontes/sinisa-esgoto-ref2023.zip
MUNICIPAL_DATA_SINISA_SEWER_CORRECTION_1_FILE=/dados/fontes/sinisa-esgoto-retificacao-uberaba-vinhedo.xlsx
MUNICIPAL_DATA_SINISA_SEWER_CORRECTION_2_FILE=/dados/fontes/sinisa-esgoto-retificacao-joinville.xlsx
MUNICIPAL_DATA_SINISA_SEWER_CORRECTION_3_FILE=/dados/fontes/sinisa-esgoto-retificacao-para-de-minas.xlsx
```

### TSE

```dotenv
MUNICIPAL_DATA_TSE_CODES_FILE=/dados/fontes/municipio_tse_ibge.zip
MUNICIPAL_DATA_TSE_CANDIDATES_2020_FILE=/dados/fontes/consulta_cand_2020.zip
MUNICIPAL_DATA_TSE_CANDIDATES_2024_FILE=/dados/fontes/consulta_cand_2024.zip
```

Os caminhos locais do TSE são opcionais. Se não forem configurados, a aplicação baixa os três arquivos do CDN oficial. O checksum calculado é registrado na release e uma alteração futura do arquivo gera uma nova versão, sem sobrescrever a anterior.

Depois de alterar essas variáveis:

```bash
php artisan config:cache
```

Não desative a verificação TLS para contornar erros de certificado.

## 6. Auditoria depois de cada carga

Execute a auditoria e a cobertura para cada exercício afetado:

```bash
php artisan data:audit --year=2021 --no-interaction
php artisan data:audit --year=2022 --no-interaction
php artisan data:audit --year=2023 --no-interaction
php artisan data:audit --year=2024 --no-interaction
php artisan data:coverage --year=2023 --no-interaction
```

Condições para considerar a carga concluída:

- comando de importação com código de saída zero;
- zero observações rejeitadas;
- nenhuma faixa inválida na auditoria;
- quantidade de municípios coerente com a cobertura documentada;
- artefato bruto presente no disco persistente;
- `source_releases` com URL, datas, tamanho e checksum preenchidos.

Ausência de cobertura não é automaticamente erro. IDEB e SINISA possuem municípios sem resultado publicado; esses casos devem continuar com um estado de indisponibilidade, não com valor zero.

## 7. Atualizações periódicas

Os comandos atuais importam somente as versões explicitamente auditadas em `config/municipal_data.php`. Eles não aceitam automaticamente um arquivo novo só porque apareceu no portal oficial.

| Fonte | Frequência esperada | Ação operacional |
| --- | --- | --- |
| Cadastro municipal | Quando o IBGE alterar a malha | Executar `ibge-localidades` antes das demais cargas |
| População | Anual | Adicionar e auditar o novo exercício na configuração; executar `ibge-populacao` |
| SIM/DATASUS | Anual, com possíveis revisões | Adicionar o arquivo anual auditado; executar `datasus-sim` |
| PIB municipal | Anual, com defasagem | Atualizar URL, período e cobertura esperada; executar `ibge-pib-municipios` |
| Censo | Decenal | Não executar como se fosse série anual |
| IDEB | Bienal | Auditar os novos pacotes e checksums antes de atualizar a configuração |
| SINISA | Anual | Auditar planilhas e retificações; criar uma nova configuração por ano de referência |
| TSE — candidaturas | A cada eleição municipal ou revisão oficial | Reexecutar 2020 ou 2024; o checksum torna a carga idempotente |
| TSE–IBGE | Sob demanda | Reexecutado automaticamente antes de cada carga de gestão |

O SINISA 2025 possui ano de referência 2024. Essa edição deverá entrar como uma nova versão; não deve sobrescrever o artefato de referência 2023.

## 8. Automação

O projeto ainda não agenda importações automaticamente. Até existir um comando de orquestração com trava, execute as cargas manualmente ou pelo agendador da plataforma, garantindo que apenas uma carga da mesma fonte rode por vez.

Uma rotina de produção deve:

1. executar o importador;
2. verificar o código de saída;
3. executar `data:audit` e `data:coverage`;
4. executar `data:warm-public-cache`;
5. registrar os resultados no sistema de logs/alertas;
6. alertar um responsável quando houver rejeição, checksum divergente ou cobertura inesperada.

Não programe Censo, IDEB ou SINISA para execução diária. A carga deve acompanhar a periodicidade e as publicações oficiais.

## 9. Falhas esperadas e resposta

| Mensagem ou situação | Resposta |
| --- | --- |
| Checksum divergente | Interromper. Confirmar se a fonte publicou uma revisão e auditar o novo arquivo antes de atualizar a configuração |
| Cobertura diferente da esperada | Interromper. Conferir layout, retificações e número de linhas da fonte |
| Certificado HTTPS inválido | Corrigir a cadeia de certificados do servidor ou usar arquivo local auditado; nunca usar `verify=false` |
| Memória esgotada | Executar com `php -d memory_limit=512M` ou valor superior |
| Município desconhecido | Atualizar primeiro o cadastro IBGE e revisar a referência territorial |
| Fonte sem dado para um município | Manter `missing_from_source`; não substituir por zero |

## 10. Verificação das páginas públicas

Depois das cargas, valide:

```bash
php artisan route:list --except-vendor
php artisan test --compact
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
vendor/bin/pint --format agent
```

Faça uma consulta fria e outra aquecida nas páginas principais:

```text
GET /
GET /ranking?year=2025
GET /prefeitos?electionYear=2020
```

Metas no ambiente de homologação:

- cálculo frio dos 5.571 municípios em até 2 segundos;
- resposta com cache em até 250 ms;
- primeira estrutura visual da home em até 1 segundo;
- pico de memória abaixo de 128 MB nas consultas públicas;
- nenhum caminho interno de artefato ou erro bruto presente nas páginas públicas.

Antes de liberar a home, confirme também:

```bash
php artisan migrate:status
php artisan data:import tse-administrations --from=2020 --to=2020 --no-interaction
php artisan data:import tse-administrations --from=2024 --to=2024 --no-interaction
php artisan data:warm-public-cache --no-interaction
npm run build
```

O ranking 2025–2028 permanecerá como `awaiting_new_data` até que pelo menos 60% do perfil nacional avance de ano efetivo. Isso é esperado e não representa falha de implantação.

O deploy deve manter um worker apto a executar callbacks adiados do Laravel. Em plataformas que encerram imediatamente o processo após a resposta, monitore a renovação stale do `Cache::flexible`.

## 11. Referências

- Catálogo de fontes e definições: [`FONTES_DOS_DADOS.md`](FONTES_DOS_DADOS.md)
- Metodologia do ranking: [`METODOLOGIA_RANKING.md`](METODOLOGIA_RANKING.md)
- Configuração das versões auditadas: `config/municipal_data.php`
- Comando de importação: `php artisan data:import --help`
- Comando de auditoria: `php artisan data:audit --help`
- Comando de cobertura: `php artisan data:coverage --help`
