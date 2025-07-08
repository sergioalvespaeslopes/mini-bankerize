<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Jobs\RegisterProposalJob;
use App\Jobs\NotifyProposalJob;
use Illuminate\Support\Facades\DB;

class ProposalController extends Controller
{
    /**
     * Cadastra uma nova proposta e a coloca na fila para processamento.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        Log::info('Requisição recebida no ProposalController@store.');
        // --- ADICIONE ESTAS DUAS LINHAS PARA DEPURAR ---
        Log::info('Content-Type recebido: ' . $request->header('Content-Type'));
        Log::info('Corpo da requisição (request->all()): ' . json_encode($request->all()));
        // ---------------------------------------------

        try {
            $validatedData = $request->validate([
                'cpf' => 'required|string|regex:/^\d{11}$/|unique:proposals,cpf',
                'nome' => 'required|string|max:255',
                'data_nascimento' => 'required|date_format:Y-m-d',
                'valor_emprestimo' => 'required|numeric|min:0.01',
                'chave_pix' => 'required|string|email|max:255',
            ]);

            Log::info('Dados validados com sucesso.', ['data' => $validatedData]);

            $proposal = DB::transaction(function () use ($validatedData) {
                $p = Proposal::create([
                    'cpf' => $validatedData['cpf'],
                    'name' => $validatedData['nome'],
                    'birth_date' => $validatedData['data_nascimento'],
                    'loan_amount' => $validatedData['valor_emprestimo'],
                    'pix_key' => $validatedData['chave_pix'],
                    'status' => 'pending', // <--- Sempre inicia como 'pending'
                    'notification_status' => 'pending', // <--- Sempre inicia como 'pending'
                ]);
                Log::info('Proposta criada no banco de dados.', ['proposal_id' => $p->id]);
                return $p;
            });
 
            // Jobs são despachados para processamento assíncrono
            RegisterProposalJob::dispatch($proposal->id);
            NotifyProposalJob::dispatch($proposal->id);
            Log::info('Jobs despachados para a fila.', ['proposal_id' => $proposal->id]);

            return response()->json([
                'message' => 'Proposta recebida com sucesso e em processamento. Você será notificado em breve.',
                'proposal_id' => $proposal->id,
                'status' => $proposal->status, // Retorna o status inicial 'pending'
            ], 202);

        } catch (ValidationException $e) {
            Log::warning('Erro de validação na proposta.', ['errors' => $e->errors()]);
            return response()->json([
                'message' => 'Erro de validação nos dados da proposta.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error("Erro interno ao criar proposta: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'Ocorreu um erro interno ao processar sua solicitação. Por favor, tente novamente mais tarde.',
            ], 500);
        }
    }
}
