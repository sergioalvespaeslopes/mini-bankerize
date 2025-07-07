<?php

namespace App\Jobs;

use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotifyProposalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 9999;
    public $backoff = [10, 30, 60, 120, 300];

    protected int $proposalId;

    public function __construct(int $proposalId)
    {
        $this->proposalId = $proposalId;
    }

    public function handle(): void
    {
        $proposal = Proposal::find($this->proposalId);

        if (!$proposal) {
            Log::warning("NotifyProposalJob: Proposal {$this->proposalId} not found. Aborting.");
            return;
        }

        if ($proposal->notification_status === 'sent' || $proposal->notification_status === 'failed') {
             Log::info("NotifyProposalJob: Proposal {$proposal->id} notification is already in status '{$proposal->notification_status}'. Aborting.");
             return;
        }

        try {
            // Primeiro, muda o status para 'processing' para indicar que o Job está trabalhando.
            if ($proposal->notification_status === 'pending') {
                $proposal->notification_status = 'processing';
                $proposal->save(); // Salva o status 'processing' no banco de dados
                Log::info("Proposal {$proposal->id} notification status set to 'processing'.");
            }

            // Realiza a chamada para a API externa de notificação.
            $response = Http::timeout(5)->post('https://util.devi.tools/api/v1/notify', [
                'cpf' => $proposal->cpf,
                'message' => 'Sua proposta de empréstimo foi processada!',
                'recipient' => $proposal->pix_key,
            ]);

            // AQUI ESTÁ A LÓGICA CRÍTICA:
            // SÓ MUDA PARA 'SENT' SE A RESPOSTA DA API FOR DE SUCESSO HTTP (2xx).
            if ($response->successful()) {
                $proposal->notification_status = 'sent'; // <--- O status é definido como 'sent' AQUI
                $proposal->notification_error = null;
                $proposal->save(); // <--- E SALVO NO BANCO APENAS APÓS O SUCESSO DA API.
                Log::info("Proposal {$proposal->id} notification successfully sent.");
                return;
            } else {
                // Se a API não retornar sucesso, lança uma exceção para que o Job seja retentado.
                $errorMessage = $response->body() ?: 'Unknown error or HTTP error from notification API.';
                Log::error("Failed to send notification for proposal {$proposal->id}: {$errorMessage}. Retrying...");
                throw new \Exception("Failed to send notification: {$errorMessage}");
            }
        } catch (\Exception $e) {
            Log::error("Exception caught in NotifyProposalJob for proposal {$proposal->id}: {$e->getMessage()}. Retrying...");
            throw $e;
        }
    }
}