<?php

namespace App\Services;

use App\Models\ConnectedSystem;
use App\Models\Ticket;

class IntegrationRequesterWhatsAppSync
{
    public function __construct(private IntegrationUserDirectory $directory)
    {
    }

    /**
     * Preenche somente chamados sem contato pertencentes ao mesmo sistema e ID externo.
     * Nunca usa correspondência aproximada de nome/e-mail nem sobrescreve contatos existentes.
     * Não dispara mensagens retroativas nem modifica o histórico de atendimento.
     */
    public function syncRequester(ConnectedSystem $system, string $externalRequesterId): int
    {
        if (!$system->active || !$system->base_url
            || !preg_match('/^estudio-franca-[1-9][0-9]*$/D', $externalRequesterId)) {
            return 0;
        }

        $pending = fn () => Ticket::query()
            ->where('system_id', $system->id)
            ->where('external_requester_id', $externalRequesterId)
            ->whereNull('requester_whatsapp')
            ->whereNull('trashed_at');

        if (!$pending()->exists()) {
            return 0;
        }

        // A consulta assinada retorna o cadastro ativo associado ao ID exato.
        $profile = $this->directory->find($system, $externalRequesterId);
        $number = WhatsAppConnection::normalizeNumber($profile['whatsapp'] ?? null);
        if ($number === null) {
            return 0;
        }

        $count = 0;
        $pending()->orderBy('id')->chunkById(100, function ($tickets) use ($number, &$count): void {
            foreach ($tickets as $ticket) {
                // O mutator criptografa o número antes de armazená-lo.
                $ticket->requester_whatsapp = $number;
                $ticket->save();
                $count++;
            }
        });

        return $count;
    }
}
