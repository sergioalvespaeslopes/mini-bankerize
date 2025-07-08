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
            if ($proposal->notification_status === 'pending') {
                $proposal->notification_status = 'processing';
                $proposal->save();
                Log::info("Proposal {$proposal->id} notification status set to 'processing'.");
            }


            $simularSucessoNotificacao = true; 
            $responseSuccessful = $simularSucessoNotificacao;

            if (!$simularSucessoNotificacao) {
                Log::error("NotifyProposalJob: Simulação de FALHA da API de notificação para proposta {$proposal->id}. Motivo: 'The service is not available, try again later' (simulado).");
            } else {
                Log::info("NotifyProposalJob: Simulação de SUCESSO da API de notificação para proposta {$proposal->id}.");
            }

            if ($responseSuccessful) {
                $proposal->notification_status = 'sent';
                $proposal->notification_error = null;
                $proposal->save();
                Log::info("Proposal {$proposal->id} notification successfully sent.");
                return;
            } else {
                $errorMessage = "Simulação de falha ou erro real da API de notificação.";
                Log::error("Failed to send notification for proposal {$proposal->id}: {$errorMessage}. Retrying...");
                throw new \Exception("Failed to send notification: {$errorMessage}");
            }
        } catch (\Exception $e) {
            Log::error("Exception caught in NotifyProposalJob for proposal {$proposal->id}: {$e->getMessage()}. Retrying...");
            throw $e;
        }
    }

    /**
     * Lida com a falha permanente do Job (após esgotar todas as tentativas).
     */
    public function failed(\Throwable $exception): void
    {
        $proposal = Proposal::find($this->proposalId);
        if ($proposal) {
            $proposal->notification_status = 'failed'; // Define como 'failed' se todas as tentativas falharem
            $proposal->notification_error = 'Job falhou permanentemente após todas as tentativas. Motivo: ' . $exception->getMessage();
            $proposal->save();
            Log::critical("NotifyProposalJob: Proposta {$this->proposalId} falhou permanentemente após todas as tentativas. Status definido para 'failed'. Motivo: {$exception->getMessage()}");
        }
    }
}
