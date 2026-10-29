# Étude de cas TMA : prise de rendez-vous au guichet

Correction des tickets TMA-101 à TMA-107. Le dépôt contient :

| Élément | Emplacement |
|---|---|
| Module de règles de gestion (PHP 8.2 pur, sans base ni framework) | [`app/Regles/`](app/Regles) |
| Tests automatisés (PHPUnit) | [`tests/Unit/Regles/`](tests/Unit/Regles) |
| Requêtes d'impact | [`requetes.sql`](requetes.sql) |
| Export fourni | [`database/base_rendez_vous.sql`](database/base_rendez_vous.sql) |

Le projet est un squelette Laravel 11, mais le module n'en dépend pas : `ReglesRendezVous`
n'importe que des classes natives de PHP. Il reçoit les rendez-vous existants et l'heure courante
en paramètres, et chaque refus lève une `RegleException` qui porte le code HTTP et le message prévus.

## Lancer les tests

Prérequis : PHP 8.2 et Composer.

```bash
composer install
php vendor/bin/phpunit --testsuite Unit
```

Résultat attendu : `OK (42 tests, 77 assertions)`. Les tests n'ont besoin ni de base de données ni de fichier `.env`.

## Utiliser le module

```php
use App\Regles\ReglesRendezVous;
use App\Regles\RegleException;

$regles = new ReglesRendezVous();
$maintenant = new DateTimeImmutable('2026-11-20 18:00', new DateTimeZone('Africa/Porto-Novo'));

try {
    $rdv = $regles->controlerPrise('0614253647', 'CNI', '2026-11-23T09:00', $existants, $maintenant);
} catch (RegleException $e) {
    // $e->codeHttp (400 ou 409) et $e->getMessage() : le message prévu
}
```

| Méthode | Rôle |
|---|---|
| `controlerPrise($npi, $service, $dateHeure, $existants, $maintenant)` | contrôles dans l'ordre du tableau des refus ; renvoie le rendez-vous `confirme` à créer |
| `controlerAnnulation($rdv, $maintenant)` | renvoie le rendez-vous `annule` |
| `controlerChangementStatut($rdv, $statut)` | renvoie le rendez-vous `honore` ou `absent` |
| `listerPourUsager($rendezVous, $page, $maintenant)` | renvoie `page`, `parPage`, `total` et `rendezVous` (page de 10 éléments) |

## Tickets

L'export date du vendredi 20/11/2026 à 18:00. Les requêtes « à venir » sont mesurées par rapport à cet instant.

### TMA-101 : rendez-vous accepté un samedi

- **Qualification : anomalie.** Règle : « Le centre reçoit du lundi au vendredi ». Le 14/11/2026 est un samedi, la réponse aurait dû être `409 Le centre est fermé le week-end.`, pas `201`.
- **Cause probable :** le contrôle du jour de la semaine est absent. Seuls le format et l'heure sont vérifiés. L'export contient 8 rendez-vous un samedi (475, 680, 715, 730, 742, 834, 857, 874) et aucun un dimanche, ce qui montre qu'aucun filtre n'existe.
- **Correction :** `controlerPrise` refuse si `format('N') >= 6` (6 = samedi, 7 = dimanche).
- **Test :** `TicketsTest::test_tma101_refuse_un_rendez_vous_le_samedi` rejoue la requête du ticket (NPI 0614253647, 2026-11-14T10:00).
- **Impact (requête 1) :** 5 rendez-vous confirmés à venir tombent un samedi. Il faut prévenir ces usagers :

| id | npi | service | date_heure |
|---|---|---|---|
| 680 | 0669147625 | PASSEPORT | 2026-11-21 08:30 |
| 730 | 0679617916 | CNI | 2026-11-28 09:00 |
| 834 | 0610588823 | CNI | 2026-12-05 10:00 |
| 857 | 0671987446 | PERMIS | 2026-12-05 10:00 |
| 874 | 0648953073 | PASSEPORT | 2026-12-12 11:00 |

### TMA-102 : quatre usagers sur le même créneau

- **Qualification : anomalie.** Règle : « Un créneau accueille au plus 3 usagers ».
- **Cause probable : erreur de borne.** L'application compte les rendez-vous non annulés du créneau et refuse si `nb > 3`. Avec 3 rendez-vous existants (632, 644, 681), `3 > 3` est faux, donc le 697 est accepté. Il faut refuser dès `nb >= 3`. Les créneaux trouvés en contiennent tous exactement 4, jamais 5 : c'est la signature d'un décalage de un.
- **Correction :** `if ($occupes >= self::CAPACITE_CRENEAU)`, en ne comptant que les rendez-vous non annulés (« un rendez-vous annulé libère sa place »).
- **Test :** `test_tma102_refuse_un_quatrieme_usager_sur_un_creneau_plein` (632, 644 et 681 existants, réservation du 697). Un autre test, `test_un_rendez_vous_annule_libere_sa_place`, vérifie la place libérée.
- **Impact (requête 2) :** 3 créneaux sont en surréservation.

| créneau | nb | rendez-vous, par ordre de réservation |
|---|---|---|
| 2026-10-22 10:30 | 4 | 502, 511, 517, 519 |
| 2026-11-10 09:00 | 4 | 632, 644, 681, 697 |
| 2026-11-24 14:00 | 4 | 728, 750, 763, 783 (à venir : à traiter) |

### TMA-103 : « Erreur interne du serveur » sur un NPI avec une lettre

- **Qualification : anomalie.** Règle : « Une saisie invalide […] est refusée avec l'un des messages ci-dessous, jamais par une erreur interne ». La réponse attendue était `400 Le NPI doit comporter exactement 10 chiffres.`
- **Cause probable : erreur de format, contrôle absent.** Le NPI n'est pas validé avant d'être converti en nombre (`Long.parseLong`, `Integer.parseInt`…). La conversion de `06142A3647` lève une exception, qui n'est pas interceptée et devient une erreur 500. Un NPI est un identifiant, pas un nombre : la conversion perdrait aussi les zéros en tête.
- **Correction :** le NPI est validé comme une chaîne, avec `preg_match('/^[0-9]{10}$/D', …)`. Il n'est jamais converti en nombre, et le refus porte le message prévu.
- **Test :** `test_tma103_refuse_un_npi_contenant_une_lettre_avec_le_message_prevu`, avec `06142A3647`.

### TMA-104 : annulation refusée 40 h avant le rendez-vous

- **Qualification : anomalie.** Règle : « L'usager peut annuler son rendez-vous jusqu'à 24 heures avant l'heure prévue ». Entre le 17/11 à 18:00 et le 19/11 à 10:00, il y a 40 h, donc l'annulation devait être acceptée.
- **Cause probable : erreur de calcul.** 40 h, c'est 1 jour et 16 h. L'application lit sans doute la seule composante « heures » de l'intervalle (`DateInterval->h`, `Duration.toHoursPart()`…) au lieu de la durée totale. Elle trouve 16 h, et 16 < 24 entraîne le refus. Comme cette composante est toujours comprise entre 0 et 23, ce calcul refuse **toutes** les annulations. L'historique le confirme (requête « TMA-104 » de `requetes.sql`) : jusqu'au 03/10/2026, les usagers ont annulé 37 rendez-vous, dont certains 30,2 h et 39,3 h avant, ce qui montre que la règle des 24 h fonctionnait. Depuis le 03/10/2026 à 16:32, il n'y a plus **aucune** annulation, alors que près de 500 rendez-vous ont été pris depuis. C'est la marque d'une régression livrée début octobre.
- **Correction :** l'écart est calculé en secondes, `dateHeure->getTimestamp() - maintenant->getTimestamp()`, et l'annulation est refusée seulement s'il est inférieur à 24 × 3600. Pile 24 h avant, elle est acceptée (« jusqu'à »).
- **Test :** `test_tma104_accepte_l_annulation_40_heures_avant` (rendez-vous 709, maintenant = 17/11/2026 18:00). Les bornes sont couvertes par `test_annulation_possible_a_24_heures_pile` et `test_annulation_refusee_moins_de_24_heures_avant`.

### TMA-105 : un rendez-vous annulé passé à « honoré »

- **Qualification : anomalie.** Règle : « honoré, absent ou annulé. Ces trois statuts sont définitifs ». La réponse attendue était `409 Ce rendez-vous est déjà clôturé.`
- **Cause probable : contrôle absent.** L'application vérifie seulement que le statut demandé est `honore` ou `absent`, puis écrase le statut courant sans vérifier qu'il vaut encore `confirme`.
- **Correction :** `controlerChangementStatut` valide d'abord le statut demandé (400), puis refuse tout rendez-vous dont le statut n'est plus `confirme` (409).
- **Test :** `test_tma105_refuse_de_modifier_un_rendez_vous_annule` (rendez-vous 292, `annule` → `honore`), ainsi que les trois statuts définitifs dans `test_un_statut_definitif_ne_change_plus`.
- **Impact (requête 3) :** 4 rendez-vous annulés ont été réécrits. Leur statut est à rétablir à `annule` après vérification auprès des agents.

| rendez-vous | npi | date | changement | le | par |
|---|---|---|---|---|---|
| 208 | 0384914669 | 2026-10-07 10:00 | annule → honore | 2026-10-07 10:20 | AG-01 |
| 231 | 0370202195 | 2026-10-15 10:00 | annule → honore | 2026-10-15 10:26 | AG-02 |
| 262 | 0317656053 | 2026-10-29 10:00 | annule → absent | 2026-10-29 10:21 | AG-03 |
| 292 | 0330677555 | 2026-11-12 10:00 | annule → honore | 2026-11-12 10:13 | AG-04 |

### TMA-106 : « Mes rendez-vous » mal triés, prochain rendez-vous absent de la page 1

- **Qualification : anomalie.** Règle : « listés du plus récent au plus ancien, le prochain rendez-vous en tête, par pages de 10 ».
- **Cause probable : deux erreurs.**
  1. **Erreur de format :** le tri porte sur la date déjà formatée en texte `JJ/MM/AAAA`, par ordre décroissant. La trace est triée par jour du mois (28, 25, 21, 19, 16, 12, 11, 08, 07), puis mois et année sont ignorés. Le 02/12/2026 arrive donc en 12ᵉ position, en page 2.
  2. **Erreur de borne :** la page contient 9 éléments alors que `parPage` vaut 10 et `total` vaut 13. La fin de page est probablement calculée avec un `- 1` en trop (par exemple `slice(debut, debut + parPage - 1)` avec une fin exclusive, ou `LIMIT parPage - 1`).
- **Correction :** le prochain rendez-vous (le rendez-vous `confirme` à venir le plus proche) est placé en tête, puis les autres sont triés sur l'objet date, du plus récent au plus ancien. Le découpage se fait avec `array_slice($tries, ($page - 1) * 10, 10)`.
- **Test :** `test_tma106_met_le_prochain_rendez_vous_en_tete_et_renvoie_10_elements`, sur les 13 rendez-vous réels de l'usager 0203040506. On attend 10 éléments, dont le 02/12/2026 en premier. `test_la_page_2_contient_les_rendez_vous_restants` vérifie les 3 derniers.

### TMA-107 : impossible de prendre un second rendez-vous

- **Qualification : comportement conforme.** Règle : « Un usager ne peut avoir qu'un seul rendez-vous à venir, tous services confondus ». L'usager 0102030405 a déjà le rendez-vous n° 800 (PASSEPORT, 26/11/2026 à 10:00, `confirme`). Le refus `409 Vous avez déjà un rendez-vous à venir.` est donc correct. Pour retirer son permis, il peut prendre un rendez-vous une fois le premier honoré, ou annuler le premier (plus de 24 h avant).
- **Correction :** aucune. Le module applique la même règle : un rendez-vous `confirme` dont la date n'est pas passée.
- **Test :** `test_tma107_refuse_un_second_rendez_vous_a_venir_tous_services_confondus`. Un test complémentaire vérifie qu'un rendez-vous passé ou annulé n'empêche pas d'en prendre un nouveau.

## Bonus : anomalies non signalées

Requêtes « Bonus » de [`requetes.sql`](requetes.sql).

1. **NPI de longueur incorrecte acceptés.** Les rendez-vous 459 (`01234567890`, 11 chiffres) et 642 (`061425364711`, 12 chiffres) ont été enregistrés. Le contrôle actuel vérifie au mieux que le NPI est numérique, pas qu'il comporte *exactement* 10 chiffres. **Correction :** expression `^[0-9]{10}$` avec `/D`, pour qu'un retour à la ligne final ne passe pas. **Tests :** `test_refuse_un_npi_qui_n_a_pas_exactement_10_chiffres` (11, 12 et 9 chiffres, espace, `\n`, nombre, null).
2. **Heures hors grille acceptées.** Les rendez-vous 490 (27/10 à 16:00), 698 (13/11 à 16:30) et 842 (08/12 à 16:00, **à venir**, l'usager est à prévenir) ont été enregistrés. Le dernier créneau est 15:30, et la borne haute est sans doute fixée à 16:30 au lieu de 15:30 (confusion entre l'heure de début du dernier créneau et l'heure de fermeture). **Correction :** contrôle en minutes depuis minuit, entre 480 et 930, par pas de 30. **Tests :** `test_refuse_une_heure_hors_grille` (07:30, 09:15, 16:00, 16:30) et `test_accepte_le_dernier_creneau_de_15h30`.
3. **Règle « un seul rendez-vous à venir » contournée.** 51 réservations, pour 47 usagers, ont été acceptées entre le 24/08 et le 17/11/2026 alors que l'usager avait déjà un rendez-vous confirmé à venir, non clôturé à ce moment-là. Dans 22 cas, c'était pour le même service. Ces cas ne suivent pas de motif net : ni même jour, ni même semaine, ni même service. Le contrôle n'est donc probablement pas systématique : une autre voie de réservation l'ignore, ou deux requêtes concurrentes passent la vérification avant l'insertion. Il fonctionne en tout cas dans le cas du TMA-107. **Correction :** le module applique la règle à chaque prise. En production, il faut aussi la vérification et l'insertion dans la même transaction. **Test :** `test_tma107_…`.
4. **Rendez-vous passés jamais clôturés.** Les seuls rendez-vous passés encore `confirme` sont les rendez-vous du samedi : 475 (31/10), 715 et 742 (14/11). Le centre était fermé, donc aucun agent n'a pu les noter. Ces usagers se sont déplacés pour rien (c'est le TMA-101) : ils sont à recontacter, et leurs rendez-vous sont à régulariser. Tant que ces rendez-vous restent `confirme`, ils ne gênent pas une nouvelle réservation, puisque « à venir » ne porte que sur les dates futures.
