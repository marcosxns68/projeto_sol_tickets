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

## Estado atual

A primeira fundação inclui autenticação restrita a `@sutoorii.com`, verificação de e-mail, modelo de permissões, empresas, sistemas, departamentos, status, tickets, participantes, etiquetas, checklist, comentários, anexos, tempo, recorrência, auditoria e interface PWA inicial. As telas administrativas e as rotinas operacionais serão implementadas nas próximas etapas.
