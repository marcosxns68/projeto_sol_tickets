# Abertura Progressiva de Tickets — Design

## Objetivo
Simplificar a tela de criação de tickets mostrando apenas opções que o usuário realmente pode utilizar e apenas os campos pertinentes ao tipo de chamado escolhido.

## Regras de permissão
- `tickets.create` continua sendo a permissão-base para abrir tickets.
- `tickets.create_integration` habilita adicionalmente a opção de criar tickets para empresa/cliente integrado.
- Sem `tickets.create_integration`, a tela não exibe seletor de tipo nem qualquer campo de integração; o ticket é tratado como interno.
- Com `tickets.create_integration`, a tela permite alternar entre ticket interno e ticket para empresa/cliente.
- A autorização de backend existente continua obrigatória; esconder controles não substitui permissão.

## Ticket interno
- Exibir apenas campos internos.
- Não exibir campos de empresa/sistema, solicitante externo ou busca de usuário externo.
- Solicitante interno é opcional e fica dentro do bloco recolhível de atribuição inicial.

## Ticket para integração
- Exibir seleção de empresa/sistema integrado.
- Exibir escolha entre `Chamado geral` e `Usuário específico`.
- `Chamado geral`: mostrar apenas nome e e-mail manuais do solicitante.
- `Usuário específico`: mostrar apenas a busca de usuário da integração.
- Os dois subfluxos nunca ficam visíveis ao mesmo tempo.

## Campos comuns
- Departamento de destino, título, descrição, prioridade e prazo permanecem no fluxo principal.
- Responsável, colaboradores, seguidores e solicitante interno ficam agrupados em `Atribuição inicial (opcional)` para reduzir ruído visual.
- Busca dinâmica por nome/e-mail continua sendo usada para pessoas.

## Comportamento visual
- A tela começa enxuta e revela campos progressivamente.
- Usuários sem permissão de integração não veem nenhum texto ou controle relacionado a integração.
- Usuários com permissão de integração veem o seletor `Minha equipe / Empresa ou cliente` no topo.
- A seção opcional usa `<details>` para permanecer recolhida por padrão e pode abrir automaticamente quando houver valores antigos após erro de validação.
- Em telas móveis, todos os blocos permanecem em uma coluna e sem sensação de versão desktop espremida.

## Preservação de dados
Alternar entre interno e integração deve desabilitar os campos ocultos para que valores de um fluxo não sejam enviados acidentalmente no outro. O mesmo vale para `Chamado geral` versus `Usuário específico`.
