<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProposalsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->string('cpf')->unique();
            $table->string('name');
            $table->date('birth_date');
            $table->decimal('loan_amount', 10, 2);
            $table->string('pix_key');
            $table->enum('status', ['pending', 'processing', 'registered', 'failed'])->default('pending');
            $table->text('registration_error')->nullable();
            $table->enum('notification_status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('notification_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('proposals');
    }
}
