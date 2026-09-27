<?php

namespace App\Console\Commands;

use App\Services\WhatsAppConnection;
use Illuminate\Console\Command;
use Throwable;

class SyncWhatsAppBrand extends Command
{
    protected $signature = 'tickets:sync-whatsapp-brand';
    protected $description = 'Aplica o logotipo oficial do Sutoorii Tickets à foto de perfil da instância do WhatsApp.';

    public function handle(WhatsAppConnection $connection): int
    {
        $values = $connection->values();

        if (!$values['api_key_saved'] || $values['base_url'] === '' || $values['instance'] === '') {
            $this->warn('WhatsApp ainda não configurado; logotipo não foi aplicado.');
            return self::SUCCESS;
        }

        try {
            $connection->updateProfilePicture(
                'https://tickets.sutoorii.com/icons/sutoorii-tickets-icon-512.png'
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Logotipo oficial aplicado à foto de perfil do WhatsApp.');
        return self::SUCCESS;
    }
}
