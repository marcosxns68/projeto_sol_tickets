<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * production rollback tombstone
     *
     * Esta migration substitui a versão publicada em 2026-09-11 que falhou
     * no banco de produção e deixou o bootstrap preso em erro 500. Ela é
     * intencionalmente vazia para permitir que o Laravel registre a migration
     * como aplicada e remova o marcador de deploy. A implementação corrigida
     * das integrações deve usar uma nova migration, com outro timestamp/nome.
     */
    public function up(): void
    {
        // Intencionalmente vazio.
    }

    public function down(): void
    {
        // Intencionalmente vazio.
    }
};
