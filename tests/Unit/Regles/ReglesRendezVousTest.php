<?php

namespace Tests\Unit\Regles;

use App\Regles\RegleException;
use App\Regles\ReglesRendezVous;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Règles de la section 2 hors tickets : bornes, messages prévus et anomalies du bonus.
 */
class ReglesRendezVousTest extends TestCase
{
    private ReglesRendezVous $regles;

    protected function setUp(): void
    {
        $this->regles = new ReglesRendezVous();
    }

    private function maintenant(): \DateTimeImmutable
    {
        return TicketsTest::date('2026-11-20 18:00');
    }

    private function prise(mixed $npi, mixed $service, mixed $dateHeure, array $existants = [])
    {
        return $this->regles->controlerPrise($npi, $service, $dateHeure, $existants, $this->maintenant());
    }

    private function assertRefus(int $code, string $message, callable $action): void
    {
        try {
            $action();
        } catch (RegleException $e) {
            $this->assertSame($code, $e->codeHttp);
            $this->assertSame($message, $e->getMessage());

            return;
        }
        $this->fail("Refus attendu : $message");
    }

    public function test_accepte_une_prise_valide(): void
    {
        $rdv = $this->prise('0614253647', 'CNI', '2026-11-23T08:00');

        $this->assertSame('confirme', $rdv->statut);
        $this->assertSame('23/11/2026 08:00', $rdv->versTableau()['dateHeure']);
    }

    /** Bonus : des NPI de 11 et 12 chiffres ont été acceptés (rendez-vous 459 et 642). */
    #[DataProvider('npiInvalides')]
    public function test_refuse_un_npi_qui_n_a_pas_exactement_10_chiffres(mixed $npi): void
    {
        $this->assertRefus(400, 'Le NPI doit comporter exactement 10 chiffres.', fn () => $this->prise($npi, 'CNI', '2026-11-23T09:00'));
    }

    public static function npiInvalides(): array
    {
        return [
            '11 chiffres' => ['06142536471'],
            '12 chiffres' => ['061425364712'],
            '9 chiffres' => ['061425364'],
            'espace' => ['0614 53647'],
            'retour à la ligne final' => ["0614253647\n"],
            'nombre' => [614253647],
            'absent' => [null],
        ];
    }

    public function test_refuse_un_service_inconnu(): void
    {
        $this->assertRefus(400, 'Service inconnu : choisissez PASSEPORT, CNI ou PERMIS.', fn () => $this->prise('0614253647', 'cni', '2026-11-23T09:00'));
    }

    #[DataProvider('datesIllisibles')]
    public function test_refuse_une_date_illisible(mixed $dateHeure): void
    {
        $this->assertRefus(400, 'Date et heure invalides (format attendu : AAAA-MM-JJTHH:MM).', fn () => $this->prise('0614253647', 'CNI', $dateHeure));
    }

    public static function datesIllisibles(): array
    {
        return [
            'format français' => ['23/11/2026 09:00'],
            'sans heure' => ['2026-11-23'],
            'jour inexistant' => ['2026-02-30T09:00'],
            'heure inexistante' => ['2026-11-23T25:00'],
            'absente' => [null],
        ];
    }

    public function test_refuse_une_date_passee(): void
    {
        $this->assertRefus(409, 'Impossible de prendre un rendez-vous dans le passé.', fn () => $this->prise('0614253647', 'CNI', '2026-11-20T15:30'));
    }

    public function test_refuse_le_dimanche(): void
    {
        $this->assertRefus(409, 'Le centre est fermé le week-end.', fn () => $this->prise('0614253647', 'CNI', '2026-11-22T09:00'));
    }

    /** Bonus : des rendez-vous à 16:00 et 16:30 ont été acceptés (490, 698, 842). */
    #[DataProvider('heuresHorsGrille')]
    public function test_refuse_une_heure_hors_grille(string $heure): void
    {
        $this->assertRefus(409, 'Créneau invalide : de 08:00 à 15:30, toutes les 30 minutes.', fn () => $this->prise('0614253647', 'CNI', "2026-11-23T$heure"));
    }

    public static function heuresHorsGrille(): array
    {
        return [['07:30'], ['09:15'], ['16:00'], ['16:30']];
    }

    public function test_accepte_le_dernier_creneau_de_15h30(): void
    {
        $this->assertSame('confirme', $this->prise('0614253647', 'CNI', '2026-11-23T15:30')->statut);
    }

    public function test_un_rendez_vous_annule_libere_sa_place(): void
    {
        $existants = [
            TicketsTest::rdv(1, '0000000001', 'CNI', '2026-11-23 09:00', 'confirme'),
            TicketsTest::rdv(2, '0000000002', 'CNI', '2026-11-23 09:00', 'confirme'),
            TicketsTest::rdv(3, '0000000003', 'CNI', '2026-11-23 09:00', 'annule'),
        ];

        $this->assertSame('confirme', $this->prise('0614253647', 'CNI', '2026-11-23T09:00', $existants)->statut);
    }

    public function test_un_rendez_vous_passe_ou_annule_n_empeche_pas_d_en_prendre_un_autre(): void
    {
        $existants = [
            TicketsTest::rdv(1, '0614253647', 'CNI', '2026-11-02 09:00', 'honore'),
            TicketsTest::rdv(2, '0614253647', 'CNI', '2026-11-25 09:00', 'annule'),
        ];

        $this->assertSame('confirme', $this->prise('0614253647', 'PERMIS', '2026-11-23T09:00', $existants)->statut);
    }

    public function test_annulation_possible_a_24_heures_pile(): void
    {
        $rdv = TicketsTest::rdv(1, '0614253647', 'CNI', '2026-11-23 09:00', 'confirme');

        $this->assertSame('annule', $this->regles->controlerAnnulation($rdv, TicketsTest::date('2026-11-22 09:00'))->statut);
    }

    public function test_annulation_refusee_moins_de_24_heures_avant(): void
    {
        $rdv = TicketsTest::rdv(1, '0614253647', 'CNI', '2026-11-23 09:00', 'confirme');

        $this->assertRefus(409, 'Annulation impossible moins de 24 h avant le rendez-vous.', fn () => $this->regles->controlerAnnulation($rdv, TicketsTest::date('2026-11-22 09:01')));
    }

    #[DataProvider('statutsDefinitifs')]
    public function test_annulation_refusee_si_le_rendez_vous_n_est_plus_confirme(string $statut): void
    {
        $rdv = TicketsTest::rdv(1, '0614253647', 'CNI', '2026-12-23 09:00', $statut);

        $this->assertRefus(409, 'Ce rendez-vous ne peut plus être annulé.', fn () => $this->regles->controlerAnnulation($rdv, $this->maintenant()));
    }

    #[DataProvider('statutsDefinitifs')]
    public function test_un_statut_definitif_ne_change_plus(string $statut): void
    {
        $rdv = TicketsTest::rdv(1, '0614253647', 'CNI', '2026-11-12 09:00', $statut);

        $this->assertRefus(409, 'Ce rendez-vous est déjà clôturé.', fn () => $this->regles->controlerChangementStatut($rdv, 'absent'));
    }

    public static function statutsDefinitifs(): array
    {
        return [['honore'], ['absent'], ['annule']];
    }

    public function test_refuse_un_statut_inconnu(): void
    {
        $rdv = TicketsTest::rdv(1, '0614253647', 'CNI', '2026-11-12 09:00', 'confirme');

        $this->assertRefus(400, 'Statut invalide : honore ou absent.', fn () => $this->regles->controlerChangementStatut($rdv, 'annule'));
    }

    public function test_un_agent_peut_noter_l_absence(): void
    {
        $rdv = TicketsTest::rdv(1, '0614253647', 'CNI', '2026-11-12 09:00', 'confirme');

        $this->assertSame('absent', $this->regles->controlerChangementStatut($rdv, 'absent')->statut);
    }

    public function test_la_page_2_contient_les_rendez_vous_restants(): void
    {
        $page = $this->regles->listerPourUsager(TicketsTest::rendezVousUsager0203040506(), 2, $this->maintenant());

        $dates = array_map(fn ($r) => $r->dateHeure->format('d/m/Y'), $page['rendezVous']);
        $this->assertSame(['03/03/2026', '12/02/2026', '05/01/2026'], $dates);
    }
}
