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
            // Primeiro, muda o status para 'processing' para indicar que o Job está trabalhando.
            if ($proposal->status === 'pending') {
                $proposal->status = 'processing';
                $proposal->save(); // Salva o status 'processing' no banco de dados
                Log::info("RegisterProposalJob: Proposta {$proposal->id} status definido para 'processing'.");
            }

            // Realiza a chamada para a API externa de autorização.
            $response = Http::timeout(5)->post('https://util.devi.tools/api/v2/authorize', [
                'cpf' => $proposal->cpf,
                'name' => $proposal->name,
                'birth_date' => $proposal->birth_date->format('Y-m-d'),
                'loan_amount' => $proposal->loan_amount,
                'pix_key' => $proposal->pix_key,
            ]);

            $responseData = $response->json();

            // AQUI ESTÁ A LÓGICA CRÍTICA:
            // SÓ MUDA PARA 'REGISTERED' SE A RESPOSTA DA API FOR EXATAMENTE A QUE INDICA SUCESSO.
            if ($response->successful() &&
                isset($responseData['status']) && $responseData['status'] === 'success' &&
                isset($responseData['data']['authorization']) && $responseData['data']['authorization'] === true
            ) {
                $proposal->status = 'registered'; // <--- O status é definido como 'registered' AQUI
                $proposal->registration_error = null;
                $proposal->save(); // <--- E SALVO NO BANCO APENAS APÓS O SUCESSO DA API.
                Log::info("RegisterProposalJob: Proposta {$proposal->id} autorizada com sucesso e status definido para '{$proposal->status}'. Job concluído.");
                return;

            } else {
                // Se a API não retornar o sucesso esperado, lança uma exceção para que o Job seja retentado.
                // O status permanece 'processing' enquanto ele tenta novamente.
                $errorMessage = "API externa: ";
                if ($response->status()) {
                    $errorMessage .= "Status HTTP {$response->status()}. ";
                }
                $errorMessage .= "Resposta: " . ($response->body() ?: "Corpo vazio ou irreconhecível.");

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
            $proposal->status = 'failed'; // Define como 'failed' se todas as tentativas falharem
            $proposal->registration_error = 'Job falhou permanentemente após todas as tentativas. Motivo: ' . $exception->getMessage();
            $proposal->save();
            Log::critical("RegisterProposalJob: Proposta {$this->proposalId} falhou permanentemente após todas as tentativas. Status definido para 'failed'. Motivo: {$exception->getMessage()}");
        }
    }
}