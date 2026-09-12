# Design — Tickets criados internamente para integrações

Data: 2026-09-12

## Objetivo

Permitir que um atendente interno do Sutoorii Tickets crie um ticket vinculado a um sistema integrado, fazendo com que esse ticket apareça imediatamente no painel de suporte daquele sistema sem duplicar o ticket em outro banco de dados.

O Sutoorii Tickets permanece como fonte oficial do chamado. O sistema integrado apenas consulta e interage com o ticket por meio da API existente.

## Modos de criação

O formulário interno de criação de ticket terá dois destinos quando a origem escolhida for uma integração:

1. **Chamado geral da integração**
   - O atendente escolhe a integração.
   - `system_id` é preenchido.
   - `external_requester_id`, `requester_name` e `requester_email` ficam vazios, exceto quando houver informação opcional explicitamente fornecida.
   - O ticket fica visível para usuários externos com papel de gestor/manager daquela integração.
   - O ticket não aparece em “Meus chamados” de um usuário externo comum.

2. **Chamado para usuário da integração**
   - O atendente escolhe a integração.
   - O Sutoorii Tickets consulta os usuários ativos diretamente no sistema integrado.
   - O atendente seleciona um usuário pelo nome/e-mail.
   - O ticket salva `system_id`, `external_requester_id`, `requester_name` e `requester_email`.
   - O ticket fica visível para os gestores da integração e também em “Meus chamados” daquele usuário.

## Modelo de dados

Não será criada cópia do usuário externo nem tabela de sincronização de usuários nesta fase.

Serão reutilizados os campos já existentes em `tickets`:

- `origin`
- `system_id`
- `external_requester_id`
- `requester_name`
- `requester_email`
- `external_reference`

Para tickets criados pela equipe interna e vinculados a uma integração:

- `origin` continuará como `internal`, preservando quem realmente iniciou o chamado;
- `system_id` indicará com qual integração o ticket é compartilhado;
- o vínculo com a integração deixará de depender de `origin = integration`.

## Diretório de usuários da integração

Cada sistema integrado que quiser permitir seleção de usuário deverá implementar um endpoint padrão:

`GET /api/sutoorii/users?search={texto}`

Resposta esperada:

```json
{
  "data": [
    {
      "id": "12345",
      "name": "Maria Silva",
      "email": "maria@example.com"
    }
  ]
}
```

Regras:

- retornar apenas usuários ativos que possam receber tickets;
- `id` deve ser estável no sistema de origem;
- `name` é obrigatório;
- `email` pode ser nulo;
- limite máximo recomendado de 20 resultados por consulta;
- a busca deve aceitar nome e e-mail;
- o endpoint não deve expor senha, documentos, telefone ou outros dados desnecessários.

## Autenticação servidor-servidor

A consulta ao diretório nunca será feita diretamente pelo navegador.

Fluxo:

1. navegador chama uma rota autenticada do Sutoorii Tickets;
2. Sutoorii Tickets valida que o operador pode criar tickets;
3. o backend consulta o `base_url` da integração;
4. a chamada ao sistema externo é assinada com o segredo compartilhado já utilizado pela integração;
5. o sistema externo valida assinatura e timestamp;
6. o Sutoorii Tickets devolve ao navegador somente `id`, `name` e `email`.

Assinatura proposta:

- `X-Sutoorii-Timestamp`
- `X-Sutoorii-Signature`

A assinatura HMAC-SHA256 será calculada sobre uma string canônica composta por método HTTP, caminho, query string e timestamp. Requisições com timestamp fora de uma pequena janela de tolerância devem ser rejeitadas.

Nenhum segredo será enviado ao JavaScript ou armazenado no navegador.

## Interface de criação

O formulário “Novo ticket” continuará simples.

Campos adicionais quando o operador selecionar “Integração”:

- Integração
- Destino:
  - Chamado geral da integração
  - Usuário específico
- Busca de usuário, exibida apenas no segundo modo

A busca de usuário será sob demanda e não carregará toda a base. O operador poderá digitar parte do nome ou e-mail e selecionar um resultado.

Ao selecionar um usuário, o formulário guardará internamente o ID externo e exibirá nome/e-mail para confirmação.

## Criação do ticket

Ao salvar:

### Ticket geral

- `origin = internal`
- `system_id = integração selecionada`
- `external_requester_id = null`
- status inicial conforme regra atual
- departamento padrão da integração, quando configurado; caso contrário, regra interna atual
- etiquetas padrão da integração devem ser aplicadas, quando configuradas

### Ticket para usuário

Além das regras acima:

- `external_requester_id = id retornado pela integração`
- `requester_name = nome retornado pela integração`
- `requester_email = e-mail retornado pela integração`

O backend não confiará cegamente nos dados escondidos enviados pelo formulário: antes de criar o ticket para usuário específico, deverá validar que o usuário selecionado pertence à integração, preferencialmente consultando novamente o diretório pelo ID ou usando um resultado assinado/validado pelo backend.

## Visibilidade na API externa

A regra atual será preservada:

- gestor/manager da integração vê todos os tickets com aquele `system_id`;
- usuário comum vê apenas tickets cujo `external_requester_id` seja igual ao próprio ID externo.

Com isso, um ticket geral aparece apenas para gestores, enquanto um ticket direcionado aparece também ao usuário escolhido.

## Webhooks

Hoje o envio de webhook depende de `origin = integration`. Essa regra será alterada.

Novo critério:

- se o ticket possuir `system_id`;
- a integração estiver ativa;
- houver `webhook_url` e segredo válido;

então eventos públicos relevantes podem ser enviados à integração, independentemente de o ticket ter sido criado internamente ou pela própria integração.

Isso garante que tickets criados pela equipe interna continuem sincronizando:

- mudança de status;
- comentários públicos;
- resolução/fechamento/reabertura;
- demais eventos já suportados pelo dispatcher.

Notas internas nunca serão enviadas.

## API e compatibilidade

A API v1 continuará sendo a fonte usada pelo painel integrado para listar e abrir tickets.

Não haverá duplicação de ticket no sistema externo.

O novo endpoint de diretório de usuários é uma capacidade do sistema integrado, não do usuário final. Sistemas que não implementarem esse endpoint ainda poderão receber **chamados gerais da integração**, mas a opção “Usuário específico” ficará indisponível.

## Tratamento de falhas

Se o diretório externo estiver indisponível:

- a criação de ticket geral continua funcionando;
- a busca de usuários mostra uma mensagem clara de indisponibilidade;
- não será permitido criar ticket para um ID digitado manualmente como fallback, evitando vínculo incorreto.

Se a integração retornar dados inválidos:

- o resultado é descartado;
- o evento técnico pode ser registrado em log;
- nenhum segredo ou conteúdo sensível da resposta externa é exibido ao operador.

Se o usuário externo for removido depois da criação, o ticket já existente permanece com seu `external_requester_id` histórico. Ele deixa de aparecer para aquele usuário caso o sistema de origem deixe de autenticá-lo, mas continua visível para gestores da integração.

## Permissões

A criação continuará exigindo `tickets.create`.

A escolha de uma integração deve ser permitida apenas a usuários internos autorizados a criar tickets. Nesta fase não será criada uma permissão separada, a menos que os testes revelem necessidade operacional.

## Auditoria

O evento de criação deverá registrar, além dos campos atuais:

- integração vinculada;
- modo de destino (`integration` ou `external_user`);
- ID externo do usuário, quando houver;
- nome do usuário externo, quando houver.

Nenhum segredo será escrito em eventos ou logs de auditoria.

## Testes obrigatórios

A implementação deverá ser feita por TDD e cobrir pelo menos:

1. usuário autorizado consegue criar ticket geral para integração;
2. ticket geral recebe `system_id` e não recebe `external_requester_id`;
3. gestor externo consegue listar ticket geral;
4. usuário externo comum não consegue listar ticket geral;
5. busca de usuários usa chamada servidor-servidor e não expõe segredo;
6. ticket pode ser criado para usuário externo válido;
7. ticket direcionado aparece para o usuário externo correto;
8. outro usuário externo da mesma integração não vê o ticket direcionado;
9. integração inválida/inativa não pode ser usada;
10. falha no diretório não impede criação de chamado geral;
11. etiquetas e departamento padrão da integração continuam sendo aplicados;
12. webhook é disparado para ticket vinculado a integração mesmo quando `origin = internal`;
13. ticket puramente interno sem `system_id` não dispara webhook de integração.

## Primeira integração compatível

O primeiro sistema a receber o endpoint de diretório será o Estúdio França. Depois, o mesmo contrato poderá ser aplicado aos demais sistemas sem alterar a lógica central do Sutoorii Tickets.

## Fora do escopo desta etapa

- sincronização completa da base de usuários externos;
- criação de contas externas dentro do Sutoorii Tickets;
- armazenamento local permanente do diretório externo;
- edição de usuários externos;
- notificações por e-mail de criação de ticket, que será tratada em etapa separada após esta função.
