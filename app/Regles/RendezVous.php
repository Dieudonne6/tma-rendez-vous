<?php

namespace App\Regles;

use DateTimeImmutable;

/**
 * Rendez-vous tel que le module de règles le manipule, sans lien avec la base.
 * La date et l'heure sont exprimées à l'heure du Bénin.
 */
final class RendezVous
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $npi,
        public readonly string $service,
        public readonly DateTimeImmutable $dateHeure,
        public readonly string $statut = ReglesRendezVous::CONFIRME,
    ) {
    }

    public function avecStatut(string $statut): self
    {
        return new self($this->id, $this->npi, $this->service, $this->dateHeure, $statut);
    }

    /** @return array{id: ?int, npi: string, service: string, dateHeure: string, statut: string} */
    public function versTableau(): array
    {
        return [
            'id' => $this->id,
            'npi' => $this->npi,
            'service' => $this->service,
            'dateHeure' => $this->dateHeure->format('d/m/Y H:i'),
            'statut' => $this->statut,
        ];
    }
}
