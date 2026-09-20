<?php

namespace App\Jobs;

/**
 * Compatibilidade com os tickets que já utilizam esta tarefa de abertura.
 * Novas automações reutilizam SendTicketWhatsAppAutomation.
 */
class SendTicketOpenedWhatsApp extends SendTicketWhatsAppAutomation
{
    public function __construct(int $ticketId)
    {
        parent::__construct($ticketId, 'opened');
    }
}
