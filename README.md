# Sutoorii Tickets

Central unificada de tickets internos e de suporte, desenvolvida em Laravel e preparada como PWA.

## Requisitos

- PHP 8.2 ou superior, com extensões comuns do Laravel e IMAP para a futura captura de respostas;
- Composer 2;
- MySQL 8 ou MariaDB compatível;
- cron executando `php artisan schedule:run` a cada minuto;
- worker de fila (`php artisan queue:work`) ou tarefa cron equivalente.

## Instalação

1. Copie `.env.example` para `.env` e preencha banco e credenciais de e-mail.
2. Execute `composer install --no-dev --optimize-autoloader`.
3. Execute `php artisan key:generate` e `php artisan migrate --seed`.
4. Aponte o domínio exclusivamente para a pasta `public`.
5. Configure HTTPS, cron e processamento de filas.

Credenciais nunca devem ser salvas no Git. O SMTP usa `mail.sutoorii.com:465` com SSL e o IMAP usa `mail.sutoorii.com:993` com SSL.

## Desenvolvimento

A branch `feature/finalizacao-sutoorii-tickets` possui CI próprio. Ela executa a suíte de testes em SQLite em memória antes de qualquer integração com `main`. A branch `main` continua sendo a única publicada automaticamente em produção.

## Estado atual

A base inclui autenticação, recuperação de senha, verificação de e-mail, empresas, sistemas, departamentos, status, tickets, participantes, etiquetas, checklist, comentários, anexos, recorrência, auditoria e PWA. A finalização funcional está sendo implementada de forma incremental e testada na branch de desenvolvimento.

## Configuração do cron na Hostoo

A publicação pelo GitHub envia o código e executa as migrations, mas **não cadastra o cron no painel da hospedagem**.

1. Na hospedagem do domínio tickets.sutoorii.com, entre em **Configurações > Cron > Adicionar tarefa**.
2. Selecione execução **a cada minuto**.
3. Descubra o caminho real da pasta do projeto (a que contém o arquivo \`artisan\`) e o caminho do executável PHP CLI na sua hospedagem. Não use o caminho da pasta \`public\`.
4. Informe no campo **Comando**, substituindo os dois caminhos pelos valores reais do servidor:

\`\`\`sh
cd /CAMINHO/REAL/DO/TICKETS && /CAMINHO/DO/PHP artisan schedule:run >> /dev/null 2>&1
\`\`\`

O cron precisa ser cadastrado **uma única vez**. As frequências de cada rotina estão em \`routes/console.php\`: fila a cada minuto, recorrências a cada 15 minutos, manutenção a cada hora e resumo dos tickets atribuídos às 08h (horário de São Paulo).

Para verificar o agendamento pelo terminal da hospedagem, execute, a partir da pasta do projeto, \`php artisan schedule:list\`. Para investigar a execução real, substitua temporariamente o redirecionamento \`/dev/null\` pelo arquivo \`storage/logs/scheduler-cron.log\` e confirme que há execuções; depois volte para \`/dev/null\` para não acumular logs. \`schedule:list\` confirma que as rotinas estão definidas, mas **não prova que o cron da hospedagem está executando**.

O resumo diário só é enviado aos usuários ativos com e-mail confirmado. Ele mostra a quantidade de tickets ativos atribuídos ao usuário, mesmo quando essa quantidade é zero; tickets resolvidos, fechados, cancelados e na lixeira não entram. O comando aplica as configurações de SMTP salvas no painel do Tickets e registra a última data enviada para evitar reenvios no mesmo dia.

**Importante:** executar o deploy no GitHub não configura cron, não valida entrega de e-mails reais e não confirma o horário ou PHP CLI do painel da Hostoo.
