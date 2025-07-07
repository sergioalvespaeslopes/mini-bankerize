<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Adicione a rota da sua API aqui para que ela não exija CSRF token
        '/proposal', // Ou apenas '/', se toda a sua aplicação for API
    ];
}