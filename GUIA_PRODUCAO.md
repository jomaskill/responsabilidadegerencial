# Guia de produção

Este guia descreve como instalar, configurar, carregar e auditar os dados municipais do MVP em produção.

## 1. Requisitos

- PHP 8.5 com extensões exigidas pelo Laravel, incluindo `curl`, `dom`, `libxml`, `pdo`, `xmlreader` e `zip`.
- Banco de dados configurado pelo Laravel.
- Pelo menos 512 MB de memória para processar os arquivos IDEB e SINISA.
- Saída HTTPS para IBGE, INEP, DATASUS e Ministério das Cidades.
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
```

`MUNICIPAL_DATA_DISK` deve apontar para um disco persistente configurado em `config/filesystems.php`. Em servidores com contêiner descartável, configure um volume persistente ou um disco de objetos compatível com a aplicação antes de executar as cargas.

As variáveis devem existir antes de executar `php artisan config:cache`.

## 3. Primeira implantação

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force --no-interaction
php artisan db:seed --class=DatabaseSeeder --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
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
php -d memory_limit=512M artisan data:import inep-ideb --from=2021 --to=2023 --no-interaction
php -d memory_limit=512M artisan data:import sinisa --from=2023 --to=2023 --no-interaction
```

Uma execução repetida com os mesmos arquivos oficiais é idempotente: o artefato e as observações não são duplicados.

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

O SINISA 2025 possui ano de referência 2024. Essa edição deverá entrar como uma nova versão; não deve sobrescrever o artefato de referência 2023.

## 8. Automação

O projeto ainda não agenda importações automaticamente. Até existir um comando de orquestração com trava, execute as cargas manualmente ou pelo agendador da plataforma, garantindo que apenas uma carga da mesma fonte rode por vez.

Uma rotina de produção deve:

1. executar o importador;
2. verificar o código de saída;
3. executar `data:audit` e `data:coverage`;
4. registrar os resultados no sistema de logs/alertas;
5. alertar um responsável quando houver rejeição, checksum divergente ou cobertura inesperada.

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

## 10. Referências

- Catálogo de fontes e definições: [`FONTES_DOS_DADOS.md`](FONTES_DOS_DADOS.md)
- Configuração das versões auditadas: `config/municipal_data.php`
- Comando de importação: `php artisan data:import --help`
- Comando de auditoria: `php artisan data:audit --help`
- Comando de cobertura: `php artisan data:coverage --help`
