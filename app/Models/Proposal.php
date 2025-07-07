<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'cpf',
        'name',
        'birth_date',
        'loan_amount',
        'pix_key',
        'status',
        'registration_error',
        'notification_status',
        'notification_error',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'loan_amount' => 'decimal:2',
    ];
}