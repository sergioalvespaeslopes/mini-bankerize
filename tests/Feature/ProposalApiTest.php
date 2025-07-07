<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Proposal;
use Illuminate\Support\Facades\Queue;
use App\Jobs\RegisterProposalJob;
use App\Jobs\NotifyProposalJob;

class ProposalApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_a_proposal_and_dispatches_jobs_successfully()
    {
        Queue::fake();

        $data = [
            'cpf' => '12345678901',
            'nome' => 'João da Silva',
            'data_nascimento' => '1985-01-01',
            'valor_emprestimo' => 1500.75,
            'chave_pix' => 'joao.silva@email.com',
        ];

        $response = $this->postJson('/proposal', $data);

        $response->assertStatus(202);

        $response->assertJsonStructure([
            'message',
            'proposal_id',
            'status',
        ]);

        $this->assertDatabaseHas('proposals', [
            'cpf' => '12345678901',
            'name' => 'João da Silva',
            'status' => 'pending',
            'notification_status' => 'pending',
        ]);

        $proposal = Proposal::first();

        // --- MUDANÇA AQUI PARA RegisterProposalJob ---
        Queue::assertPushed(RegisterProposalJob::class, function ($job) use ($proposal) {
            // Cria um objeto de reflexão para a propriedade 'proposalId' do Job
            $reflection = new \ReflectionProperty($job, 'proposalId');
            // Define a propriedade como acessível, mesmo sendo protected
            $reflection->setAccessible(true);
            // Obtém o valor da propriedade e compara com o ID da proposta
            return $reflection->getValue($job) === $proposal->id;
        });

        // --- MUDANÇA AQUI PARA NotifyProposalJob ---
        Queue::assertPushed(NotifyProposalJob::class, function ($job) use ($proposal) {
            // Faça o mesmo para o NotifyProposalJob
            $reflection = new \ReflectionProperty($job, 'proposalId');
            $reflection->setAccessible(true);
            return $reflection->getValue($job) === $proposal->id;
        });
    }

    /** @test */
    public function it_returns_validation_errors_for_invalid_input_data()
    {
        $response = $this->postJson('/proposal', [
            'cpf' => '123',
            'nome' => '',
            'data_nascimento' => '01-01-1985',
            'valor_emprestimo' => -100,
            'chave_pix' => 'invalid-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'cpf',
            'nome',
            'data_nascimento',
            'valor_emprestimo',
            'chave_pix'
        ]);
    }

    /** @test */
    public function it_prevents_duplicate_cpf()
    {
        Proposal::create([
            'cpf' => '11122233344',
            'name' => 'Teste Duplicado',
            'birth_date' => '1990-01-01',
            'loan_amount' => 100.00,
            'pix_key' => 'teste@duplicado.com',
        ]);

        $data = [
            'cpf' => '11122233344',
            'nome' => 'Outro Teste',
            'data_nascimento' => '1995-05-05',
            'valor_emprestimo' => 200.00,
            'chave_pix' => 'teste@duplicado.com',
        ];

        $response = $this->postJson('/proposal', $data);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cpf']);
    }
}