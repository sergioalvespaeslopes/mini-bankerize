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

class RegisterProposalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 9999;
    public $backoff = [10, 30, 60, 120, 300, 600, 1200, 2400, 3600];

    protected int $proposalId;

    public function __construct(int $proposalId)
    {
        $this->proposalId = $proposalId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $proposal = Proposal::find($this->proposalId);

        if (!$proposal) {
            Log::warning("RegisterProposalJob: Proposta {$this->proposalId} não encontrada. Abortando Job.");
            return;
        }
        if ($proposal->status === 'registered' || $proposal->status === 'accepted' || $proposal->status === 'failed') {
             Log::info("RegisterProposalJob: Proposta {$proposal->id} já está no status '{$proposal->status}'. Abortando Job.");
             return;
        }

        try {
            if ($proposal->status === 'pending') {
                $proposal->status = 'processing';
                $proposal->save(); // Salva o status 'processing' no banco de dados
                Log::info("RegisterProposalJob: Proposta {$proposal->id} status definido para 'processing'.");
            }

            $simularSucessoAutorizacao = true; 

            if ($simularSucessoAutorizacao) {
                $responseData = [
                    "status" => "success",
                    "data" => [
                        "authorization" => true
                    ]
                ];
                $responseSuccessful = true; // Simula que a resposta HTTP foi bem-sucedida (ex: 200 OK)
                Log::info("RegisterProposalJob: Simulação de SUCESSO da API externa para proposta {$proposal->id}.");
            } else {
                $responseData = [
                    "status" => "fail",
                    "data" => [
                        "authorization" => false,
                        "message" => "Simulação de falha na autorização."
                    ]
                ];
                $responseSuccessful = true; // Simula que a resposta HTTP foi bem-sucedida (ex: 200 OK), mas a lógica falhou
                Log::info("RegisterProposalJob: Simulação de FALHA da API externa para proposta {$proposal->id}.");
            }
            if ($responseSuccessful &&
                isset($responseData['status']) && $responseData['status'] === 'success' &&
                isset($responseData['data']['authorization']) && $responseData['data']['authorization'] === true
            ) {
                $proposal->status = 'registered'; 
                $proposal->registration_error = null;
                $proposal->save();
                Log::info("RegisterProposalJob: Proposta {$proposal->id} autorizada com sucesso e status definido para '{$proposal->status}'. Job concluído.");
                return;

            } else {
                $errorMessage = "API externa: ";
                if (isset($response)) {
                    $errorMessage .= "Status HTTP " . ($response->status() ?? 'N/A') . ". ";
                }
                $errorMessage .= "Resposta: " . (json_encode($responseData) ?: "Corpo vazio ou irreconhecível.");

                Log::error("RegisterProposalJob: Autorização negada ou resposta inesperada para proposta {$proposal->id}. Motivo: {$errorMessage}. Re-tentando...");
                throw new \Exception("Autorização negada ou resposta inesperada: {$errorMessage}");
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("RegisterProposalJob: Erro de conexão com a API para proposta {$proposal->id}. Motivo: {$e->getMessage()}. Re-tentando...");
            throw $e;

        } catch (\Exception $e) {
            Log::error("RegisterProposalJob: Exceção inesperada para proposta {$proposal->id}. Motivo: {$e->getMessage()}. Re-tentando...");
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
            $proposal->status = 'failed'; 
            $proposal->registration_error = 'Job falhou permanentemente após todas as tentativas. Motivo: ' . $exception->getMessage();
            $proposal->save();
            Log::critical("RegisterProposalJob: Proposta {$this->proposalId} falhou permanentemente após todas as tentativas. Status definido para 'failed'. Motivo: {$exception->getMessage()}");
        }
    }
}
