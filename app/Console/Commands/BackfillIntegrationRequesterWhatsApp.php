<?php

namespace App\Console\Commands;

use App\Models\ConnectedSystem;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\IntegrationRequesterWhatsAppSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackfillIntegrationRequesterWhatsApp extends Command
{
    protected $signature = 'tickets:backfill-requester-whatsapp {--limit=5 : Máximo de solicitantes por execução}';
    protected $description = 'Completa os contatos de tickets antigos do Estúdio França sem substituir números existentes';

    public function handle(IntegrationRequesterWhatsAppSync $sync): int
    {
        $limit = min(10, max(1, (int) $this->option('limit')));
        $cursorKey = 'backfill.estudio_franca_whatsapp.cursor';
        $cursor = max(0, (int) Setting::getValue($cursorKey, 0));

        // O cursor impede que perfis sem WhatsApp bloqueiem os usuários seguintes.
        $candidates = Ticket::query()
            ->whereNull('requester_whatsapp')
            ->whereNull('trashed_at')
            ->whereNotNull('system_id')
            ->where('external_requester_id', 'like', 'estudio-franca-%')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(250)
            ->get(['id', 'system_id', 'external_requester_id']);

        $seen = [];
        $updated = 0;
        $errors = 0;
        $causes = [];
        $processed = 0;
        $lastId = $cursor;

        foreach ($candidates as $candidate) {
            $lastId = $candidate->id;
            $key = $candidate->system_id.':'.$candidate->external_requester_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $processed++;

            try {
                $system = ConnectedSystem::query()->find($candidate->system_id);
                if ($system) {
                    $updated += $sync->syncRequester($system, (string) $candidate->external_requester_id);
                }
            } catch (Throwable $exception) {
                $errors++;
                $message = $exception->getMessage();
                // Código operacional resumido: nunca imprimir telefone, nome, URL ou segredo.
                $reason = preg_match('/\bHTTP ([1-5][0-9]{2})\b/', $message, $matches)
                    ? 'http_'.$matches[1]
                    : (str_contains($message, 'autenticação') ? 'sem_segredo'
                        : (str_contains($message, 'resolver o host') ? 'dns'
                            : (str_contains($message, 'privado') ? 'url_bloqueada'
                                : ($exception instanceof \Illuminate\Http\Client\ConnectionException ? 'rede'
                                    : 'outro_'.class_basename($exception)))));
                $causes[$reason] = ($causes[$reason] ?? 0) + 1;
                // Não registrar o número nem outros dados pessoais.
                Log::warning('Falha na sincronização de contato de integração', [
                    'system_id' => $candidate->system_id,
                    'ticket_id' => $candidate->id,
                    'exception_class' => get_class($exception),
                ]);
            }

            if ($processed >= $limit) {
                break;
            }
        }

        // Ao finalizar a varredura, recomeçar na próxima execução para alcançar
        // cadastros que inicialmente não possuíam número e foram atualizados depois.
        $hasMore = Ticket::query()
            ->whereNull('requester_whatsapp')
            ->whereNull('trashed_at')
            ->whereNotNull('system_id')
            ->where('external_requester_id', 'like', 'estudio-franca-%')
            ->where('id', '>', $lastId)
            ->exists();
        Setting::setValue($cursorKey, $hasMore ? $lastId : 0);

        $reasons = $causes === [] ? 'nenhuma' : implode(',', array_map(
            fn ($reason, $count) => $reason.':'.$count,
            array_keys($causes),
            array_values($causes)
        ));
        $this->info("solicitantes_verificados={$processed} tickets_preenchidos={$updated} falhas={$errors} causas={$reasons} proxima_varredura=".($hasMore ? 'pendente' : 'reinicio'));
        return self::SUCCESS;
    }
}
