# Rollback de produção — 2026-09-11

Produção restaurada para o último commit estável após erro HTTP 500 imediatamente depois da publicação da fundação de integrações.

Hipótese principal em investigação: falha da migration de integrações no banco MySQL/MariaDB de produção. O bootstrap atual executa `migrate --force` antes de atender a requisição e mantém o marcador quando a migration falha, causando 500 em todas as páginas.

A implementação de integrações permanece preservada na branch `feature/integrations-api-v1-20260911` para correção e nova validação antes de nova promoção.
