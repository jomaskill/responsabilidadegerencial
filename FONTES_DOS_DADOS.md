# Fontes dos dados municipais

Este documento é o catálogo de fontes do MVP do Responsabilidade Gerencial. O banco armazena fatos municipais e sua procedência. Pontuações, posições e rankings não são persistidos: serão calculados durante a consulta.

## Regras de transparência

- O município é identificado pelo código IBGE de sete dígitos.
- Cada carga gera um registro de fonte e versão em `source_releases`.
- A URL consultada, o ano de referência, a data de publicação, a data de coleta e o checksum SHA-256 do arquivo bruto são preservados.
- O arquivo bruto é guardado antes da transformação. Assim, uma observação pode ser reproduzida e auditada posteriormente.
- Ano de referência e data de publicação são campos diferentes. Um dado de 2023 publicado em 2025 continua sendo um dado de referência 2023.
- Valores ausentes, suprimidos, ainda não publicados e não aplicáveis não são transformados em zero.
- Indicadores derivados registram os insumos usados no cálculo.

## Catálogo atual

### Municípios

| Campo | Informação |
| --- | --- |
| Fonte oficial | IBGE — API de Localidades |
| Cobertura | Municípios brasileiros e Distrito Federal; o Distrito Estadual de Fernando de Noronha é tratado conforme o cadastro territorial do IBGE |
| Identificador | Código IBGE do município |
| Coleta | API JSON |
| Endereço exato | [API de municípios do IBGE](https://servicodados.ibge.gov.br/api/v1/localidades/municipios?orderBy=nome) |
| Página institucional | [Documentação da API de Localidades](https://servicodados.ibge.gov.br/api/docs/localidades) |

### População

| Exercício armazenado | Definição e fonte | Endereço exato |
| --- | --- | --- |
| 2021 | Estimativa municipal do IBGE, tabela SIDRA 6579, variável 9324, referência em 1º de julho | [Consulta SIDRA 2021](https://apisidra.ibge.gov.br/values/t/6579/n6/all/v/9324/p/2021?formato=json) |
| 2022 | População residente do Censo 2022, tabela SIDRA 4709, variável 93 | [Consulta SIDRA 2022](https://apisidra.ibge.gov.br/values/t/4709/n6/all/v/93/p/2022?formato=json) |
| 2023 | Relação oficial usada pelo TCU em 2023, baseada no Censo 2022 e na Malha 2023. A referência estatística da população permanece 2022 | [Arquivo ODS do IBGE](https://ftp.ibge.gov.br/Informacoes_Gerais_e_Referencia/Relacao_da_Populacao_dos_Municipios_para_publicacao_no_DOU_em_2023/POP_TCU_2023_Municipios_POP2022_Malha2023.ods) |
| 2024 | Estimativa municipal do IBGE, tabela SIDRA 6579, variável 9324, referência em 1º de julho | [Consulta SIDRA 2024](https://apisidra.ibge.gov.br/values/t/6579/n6/all/v/9324/p/2024?formato=json) |
| 2025 | Estimativa municipal do IBGE, tabela SIDRA 6579, variável 9324, referência em 1º de julho | [Consulta SIDRA 2025](https://apisidra.ibge.gov.br/values/t/6579/n6/all/v/9324/p/2025?formato=json) |

Página metodológica: [Estimativas da População — IBGE](https://www.ibge.gov.br/estatisticas/sociais/populacao/9103-estimativas-de-populacao.html). Para 2022, consultar também [Primeiros resultados do Censo Demográfico 2022](https://www.ibge.gov.br/estatisticas/sociais/populacao/22827-censo-demografico-2022.html).

### Homicídios

| Indicador | Definição |
| --- | --- |
| `homicide_count` | Óbitos por agressões e intervenções legais, códigos CID-10 X85–Y09 e Y35–Y36, segundo o município de residência da vítima |
| `homicide_rate` | `homicide_count / population × 100.000` |
| `homicide_rate_rolling_3y` | Soma dos homicídios em três anos dividida pela soma das populações dos mesmos anos, multiplicada por 100.000 |

- Fonte primária: [DATASUS — Mortalidade desde 1996 pela CID-10](https://datasus.saude.gov.br/mortalidade-desde-1996-pela-cid-10/).
- Consulta automatizada: [TABNET/SIM](http://tabnet.datasus.gov.br/cgi/tabcgi.exe?sim/cnv/obt10br.def), com linha por município e incremento de óbitos por residência.
- Definição metodológica: [Atlas da Violência — Perguntas frequentes](https://www.ipea.gov.br/atlasviolencia/pg/26/perguntas-frequentes).
- Exercícios carregados: 2021 a 2024. O exercício 2025 permanece como ainda não publicado na fonte usada.
- A taxa utiliza a observação de população correspondente e registra essa dependência no banco.

### PIB municipal

| Indicador | Definição |
| --- | --- |
| `gdp_nominal` | Produto Interno Bruto municipal a preços correntes, convertido de milhares de reais para reais |
| `gdp_per_capita` | PIB per capita oficial, em reais por habitante |

- Fonte oficial: [IBGE — Produto Interno Bruto dos Municípios](https://www.ibge.gov.br/estatisticas/economicas/contas-nacionais/9088-produto-interno-bruto-dos-municipios.html).
- Arquivo exato: [Base de dados 2010–2023 em TXT compactado](https://ftp.ibge.gov.br/Pib_Municipios/2022_2023/base/base_de_dados_2010_2023_txt.zip).
- Arquivo interno utilizado: `PIB dos Municípios - base de dados 2010-2023.txt`.
- Exercícios carregados: 2021, 2022 e 2023. O IBGE ainda não disponibilizou nessa base o PIB municipal de 2024 ou 2025.

### Água, esgoto e alfabetização

Os três indicadores são resultados do Censo Demográfico 2022 e, portanto, não devem ser apresentados como medições anuais.

| Indicador | Definição adotada | Tabela e consulta oficial |
| --- | --- | --- |
| `water_census` | Percentual de domicílios particulares permanentes ocupados que possuem ligação à rede geral e a utilizam como forma principal de abastecimento | SIDRA 6803, variável 1000381, categoria 72144 — [consulta JSON](https://apisidra.ibge.gov.br/values/t/6803/n6/all/v/1000381/p/2022/c1821/72144?formato=json) |
| `sewer_census` | Percentual de domicílios particulares permanentes ocupados com rede geral, rede pluvial ou fossa ligada à rede | SIDRA 6805, variável 1000381, categoria 46290 — [consulta JSON](https://apisidra.ibge.gov.br/values/t/6805/n6/all/v/1000381/p/2022/c11558/46290?formato=json) |
| `literacy_rate` | Taxa de alfabetização das pessoas de 15 anos ou mais | SIDRA 9543, variável 2513 — [consulta JSON](https://apisidra.ibge.gov.br/values/t/9543/n6/all/v/2513/p/2022?formato=json) |

Páginas metodológicas: [Características dos domicílios — Censo 2022](https://sidra.ibge.gov.br/pesquisa/censo-demografico/demografico-2022/universo-caracteristicas-dos-domicilios) e [Alfabetização — Censo 2022](https://sidra.ibge.gov.br/pesquisa/censo-demografico/demografico-2022/universo-alfabetizacao).

### IDEB municipal

| Indicador | Definição |
| --- | --- |
| `ideb_initial_years` | IDEB da rede municipal no ensino fundamental regular, anos iniciais |
| `ideb_final_years` | IDEB da rede municipal no ensino fundamental regular, anos finais |

- Fonte oficial e metodologia: [INEP — IDEB](https://www.gov.br/inep/pt-br/areas-de-atuacao/pesquisas-estatisticas-e-indicadores/ideb).
- Página de resultados: [INEP — Resultados do IDEB](https://www.gov.br/inep/pt-br/areas-de-atuacao/pesquisas-estatisticas-e-indicadores/ideb/resultados).
- Anos iniciais: [pacote municipal oficial de 2023](https://download.inep.gov.br/ideb/resultados/divulgacao_anos_iniciais_municipios_2023.zip), SHA-256 `866c50e858e5f4d3d0b7b380c3adc05b56f6137360d7830e23dae72284e8c9f4`.
- Anos finais: [pacote municipal oficial de 2023](https://download.inep.gov.br/ideb/resultados/divulgacao_anos_finais_municipios_2023.zip), SHA-256 `bb3b7d8a176e89f1208c6277fceed2a79724c04c11513ef5ce083fb44a861986`.
- Ciclos carregados: 2021 e 2023. A página oficial consultada ainda não apresenta o ciclo municipal de 2025.
- Uma nota publicada é armazenada como disponível. O marcador `-` da planilha é armazenado como ausente na fonte. Município sem linha para a rede municipal é armazenado como não aplicável. Nenhum desses casos recebe nota zero.

### Cobertura anual dos serviços de água e esgoto

O Censo 2022 é a fotografia estrutural disponível para todos os municípios. A evolução anual da prestação do serviço é obtida no [SINISA — Resultados](https://www.gov.br/cidades/pt-br/acesso-a-informacao/acoes-e-programas/saneamento/sinisa/resultados-sinisa/resultados-sinisa), do Ministério das Cidades.

| Indicador | Definição oficial | Código SINISA | Exercício carregado |
| --- | --- | --- | --- |
| `water_service_coverage` | Atendimento da população total com rede de abastecimento de água | IAG0001 | 2023 |
| `sewer_service_coverage` | Atendimento da população total com rede coletora de esgoto | IES0001 | 2023 |

- Água: pacote oficial `SINISA_Resultados_Ref2023.zip`, SHA-256 `1af66e823f534d5f2e513de5c4484cf5c12e8feb727ae602427af1a559bf9fea`.
- Esgoto: pacote oficial `SINISA_ESGOTO_Planilhas_2023_v2.zip`, SHA-256 `2ae2b09d8bc892ec1013ae91e5352deb9ee80611499b91bcc6309f6fc0d8b1e6`.
- As três planilhas de retificação publicadas pelo Ministério das Cidades são preservadas no mesmo artefato. A retificação acrescenta Vinhedo/SP aos resultados de cobertura de esgoto.
- Cobertura publicada: 5.211 municípios para água e 2.752 para esgoto. Municípios ausentes ou com marcador `Não Calc.` permanecem como sem dado na fonte.
- Os produtos SINISA 2025 têm 2024 como ano de referência e deverão ser incorporados como uma nova versão auditada quando as planilhas finais forem estabilizadas.

### Prefeitos eleitos e correspondência TSE–IBGE

As gestões são contexto para a ficha municipal e não alteram diretamente a nota do ranking.

| Dado | Fonte oficial | Arquivo exato |
| --- | --- | --- |
| Prefeitos eleitos em 2020 | TSE — Candidatos 2020 | [consulta_cand_2020.zip](https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_2020.zip) |
| Prefeitos eleitos em 2024 | TSE — Candidatos 2024 | [consulta_cand_2024.zip](https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_2024.zip) |
| Correspondência municipal | TSE — Códigos oficiais TSE e IBGE | [municipio_tse_ibge.zip](https://cdn.tse.jus.br/estatistica/sead/odsele/municipio_tse_ibge/municipio_tse_ibge.zip) |

O importador seleciona `DS_CARGO = PREFEITO` e `DS_SIT_TOT_TURNO = ELEITO`, registra `SQ_CANDIDATO` como identificador externo e relaciona `CD_MUNICIPIO` ao código IBGE pela correspondência oficial. Cada ZIP é preservado e recebe checksum SHA-256 no momento da carga.

O MVP cobre somente o eleito na eleição geral. Substituições e eleições suplementares são limitações explícitas e não são inferidas a partir desse arquivo.

## Disponibilidade por exercício

| Indicador | 2021 | 2022 | 2023 | 2024 | 2025 |
| --- | --- | --- | --- | --- | --- |
| População | Disponível | Disponível | Disponível, com referência estatística 2022 | Disponível | Disponível |
| Homicídios e taxa | Disponível | Disponível | Disponível | Disponível | Ainda não publicado |
| PIB nominal e per capita | Disponível | Disponível | Disponível | Ainda não publicado | Ainda não publicado |
| Água, esgoto e alfabetização | — | Disponível, Censo | — | — | — |
| Cobertura do serviço de água e esgoto — SINISA | — | — | Disponível | Ainda não carregado | — |
| IDEB municipal | Disponível | — | Disponível | — | Ainda não publicado |

`—` significa que o indicador não tem periodicidade anual ou não pertence àquele exercício. Não significa nota zero.

## Estrutura mínima mantida no MVP

- Cadastro territorial: municípios, unidades federativas e códigos oficiais.
- Catálogo: indicadores, versões metodológicas e dependências entre indicadores.
- Procedência: fontes, versões publicadas, arquivos brutos, checksums e execuções de importação.
- Observações: valor municipal por indicador e exercício, com estados de disponibilidade e qualidade.
- Auditoria: erros de processamento, alertas e insumos de indicadores derivados.

Não existem tabelas de definição, execução, pontuação ou componentes de ranking. O ranking será uma leitura calculada durante a requisição.

A regra completa está em [`METODOLOGIA_RANKING.md`](METODOLOGIA_RANKING.md).
