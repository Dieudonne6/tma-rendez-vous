<?php

namespace App\Regles;

use DomainException;

/**
 * Refus d'une saisie invalide ou d'une action interdite :
 * porte le code HTTP et le message prévus par les règles de gestion.
 */
class RegleException extends DomainException
{
    public function __construct(public readonly int $codeHttp, string $message)
    {
        parent::__construct($message, $codeHttp);
    }
}
