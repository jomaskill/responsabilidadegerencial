# Metodologia do Ranking Municipal

Versão vigente: `1.3.0`.

O ranking é calculado durante cada consulta. O banco guarda somente municípios, gestões, indicadores, observações, fontes, releases e importações. Não existem tabelas de nota, posição, peso ou execução do ranking.

## Exercício e dado vigente

O exercício selecionado pode ser de 2017 a 2025. Para cada indicador, o motor procura o último ano de referência menor ou igual ao exercício. Uma observação futura nunca pode ser usada.

Entram no cálculo somente:

- release que não foi substituída por outra;
- observação com qualidade `accepted`;
- disponibilidade `available` ou `provisional`.

O ano de referência efetivamente usado, a URL oficial, a versão da release e seu checksum SHA-256 são devolvidos na explicação.

## Grupo de comparação

O conjunto pode ser filtrado por Brasil, UF e faixa populacional. O filtro populacional usa a última população oficial vigente até o exercício. A normalização é refeita dentro do conjunto resultante; portanto, a posição de um município pode mudar quando o grupo muda.

## Indicadores e pesos

O padrão distribui 25% para cada tema:

- economia;
- educação;
- saneamento;
- segurança.

Dentro de um tema, o peso é dividido entre as dimensões utilizáveis. Quando há uma fonte anual e uma fonte estrutural para a mesma dimensão, o catálogo usa a primeira alternativa publicada na ordem definida em `config/municipal_ranking.php`.

População, PIB nominal e número absoluto de homicídios são contexto. O cálculo prefere PIB per capita e taxa de homicídios; para segurança, prefere a média móvel de três anos quando disponível.

Pesos personalizados usam `weights[indicator_slug]`. Todos devem ser não negativos e ao menos um deve ser maior que zero. O motor normaliza a soma para 100%.

## Percentis, direção e empates

Cada indicador é convertido em percentil de 0 a 100 dentro do grupo filtrado. Valores empatados recebem a média das posições empatadas e, portanto, o mesmo percentil.

Para indicadores `higher_is_better`, valores maiores recebem percentis maiores. Para `lower_is_better`, como a taxa de homicídios, a direção é invertida.

A nota é a soma das contribuições ponderadas. Empates na nota recebem a mesma posição e a próxima posição segue competição: `1, 2, 2, 4`.

## Dados ausentes

Um indicador só entra no perfil quando possui dados comparáveis para todos os municípios existentes no ano da fonte. Enquanto a cobertura nacional estiver incompleta, o indicador é removido para todos e não prejudica nenhum município. Quando existir um ano anterior nacionalmente completo, esse último ano completo pode ser utilizado.

Ausência específica do município reduz sua cobertura ponderada:

- cobertura menor que 60%: status `insufficient_data`, sem nota e sem posição;
- cobertura igual ou maior que 60%: o peso ausente é redistribuído proporcionalmente entre os indicadores disponíveis.

Ausência, supressão, não aplicabilidade ou dado ainda não publicado nunca são convertidos em zero.

## Cache e revisão dos dados

O resultado usa `Cache::flexible`: 10 minutos fresco e até 30 minutos temporariamente stale. A chave contém:

- parâmetros e pesos canonizados;
- versão da metodologia;
- assinatura das releases vigentes;
- última alteração do catálogo de indicadores.

Uma nova release, revisão ou substituição muda a assinatura e faz a consulta usar uma nova chave automaticamente.

## Reprodução e transparência

A interface pública apresenta o ano efetivo, os pesos, a cobertura, a direção de cada indicador e as fontes usadas no cálculo. A página de dados abertos lista somente os indicadores nacionalmente comparáveis. A aplicação não expõe uma API pública.

## Limitações do MVP

- A gestão associa o candidato eleito na eleição municipal geral de 2020 ou 2024.
- Substituições no mandato e eleições suplementares não são interpretadas nesta versão.
- Um exercício representa “último dado oficial disponível até o ano”, e não a promessa de que todo indicador tenha periodicidade anual.
- Alterações metodológicas devem criar uma nova versão explícita; observações históricas não devem ser reescritas.
