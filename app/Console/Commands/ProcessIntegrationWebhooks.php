<?php

namespace App\Console\Commands;

use App\Services\WebhookDeliveryProcessor;
use Illuminate\Console\Command;

class ProcessIntegrationWebhooks extends Command
{
    protected $signature = 'tickets:webhooks-process {--limit=50}';
    protected $description = 'Processa entregas pendentes de webhooks das integrações.';

    public function handle(WebhookDeliveryProcessor $processor): int
    {
        $count = $processor->processPending(max(1, min((int) $this->option('limit'), 500)));
        $this->info("{$count} entrega(s) processada(s).");
        return self::SUCCESS;
    }
}
