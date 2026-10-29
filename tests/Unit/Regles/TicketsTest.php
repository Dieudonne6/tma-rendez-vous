<?php

namespace Tests\Unit\Regles;

use App\Regles\RegleException;
use App\Regles\RendezVous;
use App\Regles\ReglesRendezVous;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Un test par ticket : chaque test rejoue la trace du support
 * et échouerait avec le comportement signalé.
 */
class TicketsTest extends TestCase
{
    private ReglesRendezVous $regles;

    protected function setUp(): void
    {
        $this->regles = new ReglesRendezVous();
    }

    public static function date(string $valeur): DateTimeImmutable
    {
        return new DateTimeImmutable($valeur, new DateTimeZone(ReglesRendezVous::FUSEAU));
    }

    public static function rdv(int $id, string $npi, string $service, string $dateHeure, string $statut): RendezVous
    {
        return new RendezVous($id, $npi, $service, self::date($dateHeure), $statut);
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

    /** TMA-101 : anomalie, le rendez-vous du samedi 14/11 avait été accepté (201). */
    public function test_tma101_refuse_un_rendez_vous_le_samedi(): void
    {
        $this->assertRefus(409, 'Le centre est fermé le week-end.', fn () => $this->regles->controlerPrise(
            '0614253647', 'PASSEPORT', '2026-11-14T10:00', [], self::date('2026-11-09 23:59:11'),
        ));
    }

    /** TMA-102 : anomalie, le n° 697 a été accepté alors que 632, 644 et 681 occupaient le créneau. */
    public function test_tma102_refuse_un_quatrieme_usager_sur_un_creneau_plein(): void
    {
        $existants = [
            self::rdv(632, '0638958971', 'PASSEPORT', '2026-11-10 09:00', 'confirme'),
            self::rdv(644, '0671968463', 'CNI', '2026-11-10 09:00', 'confirme'),
            self::rdv(681, '0609394161', 'PERMIS', '2026-11-10 09:00', 'confirme'),
        ];

        $this->assertRefus(409, 'Ce créneau est complet.', fn () => $this->regles->controlerPrise(
            '0620214467', 'CNI', '2026-11-10T09:00', $existants, self::date('2026-11-04 23:43:37'),
        ));
    }

    /** TMA-103 : anomalie, une lettre dans le NPI provoquait une erreur 500. */
    public function test_tma103_refuse_un_npi_contenant_une_lettre_avec_le_message_prevu(): void
    {
        $this->assertRefus(400, 'Le NPI doit comporter exactement 10 chiffres.', fn () => $this->regles->controlerPrise(
            '06142A3647', 'CNI', '2026-11-23T09:00', [], self::date('2026-11-20 18:00'),
        ));
    }

    /** TMA-104 : anomalie, l'annulation 40 h avant le rendez-vous n° 709 avait été refusée. */
    public function test_tma104_accepte_l_annulation_40_heures_avant(): void
    {
        $rdv = self::rdv(709, '0339205992', 'PERMIS', '2026-11-19 10:00', 'confirme');

        $annule = $this->regles->controlerAnnulation($rdv, self::date('2026-11-17 18:00'));

        $this->assertSame('annule', $annule->statut);
    }

    /** TMA-105 : anomalie, le rendez-vous n° 292 annulé avait pu passer à « honore ». */
    public function test_tma105_refuse_de_modifier_un_rendez_vous_annule(): void
    {
        $rdv = self::rdv(292, '0330677555', 'PASSEPORT', '2026-11-12 10:00', 'annule');

        $this->assertRefus(409, 'Ce rendez-vous est déjà clôturé.', fn () => $this->regles->controlerChangementStatut($rdv, 'honore'));
    }

    /** TMA-106 : anomalie, liste triée sur le texte JJ/MM/AAAA et page de 9 éléments. */
    public function test_tma106_met_le_prochain_rendez_vous_en_tete_et_renvoie_10_elements(): void
    {
        $page = $this->regles->listerPourUsager(self::rendezVousUsager0203040506(), 1, self::date('2026-11-20 18:00'));

        $dates = array_map(fn (RendezVous $r) => $r->dateHeure->format('d/m/Y H:i'), $page['rendezVous']);
        $this->assertSame(13, $page['total']);
        $this->assertSame(10, $page['parPage']);
        $this->assertSame([
            '02/12/2026 08:30', '25/09/2026 11:00', '11/08/2026 14:00', '28/07/2026 09:30', '07/07/2026 10:30',
            '16/06/2026 13:30', '02/06/2026 08:00', '19/05/2026 15:00', '08/05/2026 11:30', '21/04/2026 09:00',
        ], $dates);
    }

    /** TMA-107 : conforme, l'usager a déjà le rendez-vous n° 800 du 26/11 à venir. */
    public function test_tma107_refuse_un_second_rendez_vous_a_venir_tous_services_confondus(): void
    {
        $existants = [self::rdv(800, '0102030405', 'PASSEPORT', '2026-11-26 10:00', 'confirme')];

        $this->assertRefus(409, 'Vous avez déjà un rendez-vous à venir.', fn () => $this->regles->controlerPrise(
            '0102030405', 'PERMIS', '2026-11-27T09:00', $existants, self::date('2026-11-20 18:00'),
        ));
    }

    /** @return RendezVous[] les 13 rendez-vous de l'usager 0203040506 dans l'export */
    public static function rendezVousUsager0203040506(): array
    {
        $npi = '0203040506';

        return [
            self::rdv(1, $npi, 'PASSEPORT', '2026-01-05 08:30', 'honore'),
            self::rdv(2, $npi, 'CNI', '2026-02-12 10:00', 'honore'),
            self::rdv(3, $npi, 'PERMIS', '2026-03-03 14:30', 'absent'),
            self::rdv(4, $npi, 'PASSEPORT', '2026-04-21 09:00', 'honore'),
            self::rdv(5, $npi, 'CNI', '2026-05-08 11:30', 'honore'),
            self::rdv(6, $npi, 'PERMIS', '2026-05-19 15:00', 'absent'),
            self::rdv(7, $npi, 'PASSEPORT', '2026-06-02 08:00', 'honore'),
            self::rdv(8, $npi, 'CNI', '2026-06-16 13:30', 'honore'),
            self::rdv(9, $npi, 'PERMIS', '2026-07-07 10:30', 'honore'),
            self::rdv(10, $npi, 'PASSEPORT', '2026-07-28 09:30', 'absent'),
            self::rdv(11, $npi, 'CNI', '2026-08-11 14:00', 'honore'),
            self::rdv(259, $npi, 'PERMIS', '2026-09-25 11:00', 'honore'),
            self::rdv(826, $npi, 'PASSEPORT', '2026-12-02 08:30', 'confirme'),
        ];
    }
}
