<?php

namespace App\Regles;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Règles de gestion de la prise de rendez-vous au guichet (section 2 du sujet).
 *
 * Indépendant de toute base de données et de tout framework : l'appelant fournit
 * les rendez-vous existants et l'heure courante, le module décide.
 * Chaque refus lève une RegleException portant le code HTTP et le message prévus.
 */
final class ReglesRendezVous
{
    public const FUSEAU = 'Africa/Porto-Novo';

    public const SERVICES = ['PASSEPORT', 'CNI', 'PERMIS'];

    public const CONFIRME = 'confirme';
    public const HONORE = 'honore';
    public const ABSENT = 'absent';
    public const ANNULE = 'annule';

    public const CAPACITE_CRENEAU = 3;
    public const DELAI_ANNULATION_SECONDES = 24 * 3600;
    public const PAR_PAGE = 10;

    private const PREMIER_CRENEAU = 8 * 60;       // 08:00, en minutes
    private const DERNIER_CRENEAU = 15 * 60 + 30; // 15:30, en minutes
    private const DUREE_CRENEAU = 30;

    /**
     * Contrôle une demande de prise de rendez-vous et renvoie le rendez-vous à créer.
     *
     * @param RendezVous[] $existants rendez-vous déjà enregistrés (au moins ceux de l'usager et ceux du créneau)
     */
    public function controlerPrise(
        mixed $npi,
        mixed $service,
        mixed $dateHeure,
        array $existants,
        DateTimeImmutable $maintenant,
    ): RendezVous {
        $npi = $this->validerNpi($npi);
        $service = $this->validerService($service);
        $creneau = $this->lireDateHeure($dateHeure);
        $maintenant = $this->versHeureDuBenin($maintenant);

        if ($creneau < $maintenant) {
            throw new RegleException(409, 'Impossible de prendre un rendez-vous dans le passé.');
        }

        // 'N' : 1 = lundi … 6 = samedi, 7 = dimanche (TMA-101 : contrôle absent).
        if ((int) $creneau->format('N') >= 6) {
            throw new RegleException(409, 'Le centre est fermé le week-end.');
        }

        $minutes = (int) $creneau->format('G') * 60 + (int) $creneau->format('i');
        if ($minutes < self::PREMIER_CRENEAU
            || $minutes > self::DERNIER_CRENEAU
            || ($minutes - self::PREMIER_CRENEAU) % self::DUREE_CRENEAU !== 0) {
            throw new RegleException(409, 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.');
        }

        foreach ($existants as $rdv) {
            if ($rdv->npi === $npi && $this->estAVenir($rdv, $maintenant)) {
                throw new RegleException(409, 'Vous avez déjà un rendez-vous à venir.');
            }
        }

        // Un rendez-vous annulé libère sa place ; le créneau est plein dès 3 (TMA-102 : >= et non >).
        $occupes = 0;
        foreach ($existants as $rdv) {
            if ($rdv->statut !== self::ANNULE && $rdv->dateHeure == $creneau) {
                $occupes++;
            }
        }
        if ($occupes >= self::CAPACITE_CRENEAU) {
            throw new RegleException(409, 'Ce créneau est complet.');
        }

        return new RendezVous(null, $npi, $service, $creneau, self::CONFIRME);
    }

    /**
     * Contrôle l'annulation par l'usager et renvoie le rendez-vous annulé.
     */
    public function controlerAnnulation(RendezVous $rdv, DateTimeImmutable $maintenant): RendezVous
    {
        if ($rdv->statut !== self::CONFIRME) {
            throw new RegleException(409, 'Ce rendez-vous ne peut plus être annulé.');
        }

        // Écart total en secondes (TMA-104 : ne pas lire la seule composante « heures » d'un intervalle).
        $ecart = $rdv->dateHeure->getTimestamp() - $maintenant->getTimestamp();
        if ($ecart < self::DELAI_ANNULATION_SECONDES) {
            throw new RegleException(409, 'Annulation impossible moins de 24 h avant le rendez-vous.');
        }

        return $rdv->avecStatut(self::ANNULE);
    }

    /**
     * Contrôle la clôture d'un rendez-vous par un agent et renvoie le rendez-vous clôturé.
     */
    public function controlerChangementStatut(RendezVous $rdv, mixed $nouveauStatut): RendezVous
    {
        if (!in_array($nouveauStatut, [self::HONORE, self::ABSENT], true)) {
            throw new RegleException(400, 'Statut invalide : honore ou absent.');
        }

        // honore, absent et annule sont définitifs (TMA-105 : contrôle absent).
        if ($rdv->statut !== self::CONFIRME) {
            throw new RegleException(409, 'Ce rendez-vous est déjà clôturé.');
        }

        return $rdv->avecStatut($nouveauStatut);
    }

    /**
     * Trie les rendez-vous d'un usager (prochain rendez-vous en tête, puis du plus récent
     * au plus ancien) et renvoie la page demandée, de 10 éléments.
     *
     * @param RendezVous[] $rendezVous
     * @return array{page: int, parPage: int, total: int, rendezVous: RendezVous[]}
     */
    public function listerPourUsager(array $rendezVous, int $page, DateTimeImmutable $maintenant): array
    {
        $page = max(1, $page);

        $prochain = null;
        foreach ($rendezVous as $rdv) {
            if ($this->estAVenir($rdv, $maintenant)
                && ($prochain === null || $rdv->dateHeure < $prochain->dateHeure)) {
                $prochain = $rdv;
            }
        }

        $autres = array_values(array_filter($rendezVous, fn (RendezVous $rdv) => $rdv !== $prochain));
        // Tri chronologique sur la date réelle (TMA-106 : pas sur le texte JJ/MM/AAAA).
        usort($autres, fn (RendezVous $a, RendezVous $b) => $b->dateHeure <=> $a->dateHeure);

        $tries = $prochain === null ? $autres : [$prochain, ...$autres];

        return [
            'page' => $page,
            'parPage' => self::PAR_PAGE,
            'total' => count($tries),
            'rendezVous' => array_slice($tries, ($page - 1) * self::PAR_PAGE, self::PAR_PAGE),
        ];
    }

    public function estAVenir(RendezVous $rdv, DateTimeImmutable $maintenant): bool
    {
        return $rdv->statut === self::CONFIRME && $rdv->dateHeure >= $maintenant;
    }

    private function validerNpi(mixed $npi): string
    {
        // Contrôle sur la chaîne, sans conversion en nombre (TMA-103) ; exactement 10 chiffres (bonus).
        if (!is_string($npi) || preg_match('/^[0-9]{10}$/D', $npi) !== 1) {
            throw new RegleException(400, 'Le NPI doit comporter exactement 10 chiffres.');
        }

        return $npi;
    }

    private function validerService(mixed $service): string
    {
        if (!is_string($service) || !in_array($service, self::SERVICES, true)) {
            throw new RegleException(400, 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.');
        }

        return $service;
    }

    private function lireDateHeure(mixed $valeur): DateTimeImmutable
    {
        $erreur = new RegleException(400, 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).');

        if (!is_string($valeur) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $valeur) !== 1) {
            throw $erreur;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $valeur, new DateTimeZone(self::FUSEAU));
        // Refuse les dates qui « débordent » (2026-02-30, 25:00…) au lieu de les corriger.
        if ($date === false || $date->format('Y-m-d\TH:i') !== $valeur) {
            throw $erreur;
        }

        return $date;
    }

    private function versHeureDuBenin(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone(self::FUSEAU));
    }
}
