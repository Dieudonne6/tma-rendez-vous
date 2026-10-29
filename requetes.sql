-- Requêtes d'impact sur l'export base_rendez_vous (MySQL / MariaDB).
-- L'export date du vendredi 20 novembre 2026 à 18:00 (heure du Bénin) :
-- « à venir » est mesuré par rapport à cet instant, pas à NOW().

-- 1. TMA-101 : rendez-vous confirmés à venir pris un samedi ou un dimanche, pour prévenir les usagers.
--    DAYOFWEEK : 1 = dimanche, 7 = samedi.
SELECT id, npi, service, date_heure, statut, cree_le
FROM rendez_vous
WHERE statut = 'confirme'
  AND date_heure >= '2026-11-20 18:00:00'
  AND DAYOFWEEK(date_heure) IN (1, 7)
ORDER BY date_heure, id;

-- 2. TMA-102 : créneaux qui comptent plus de 3 rendez-vous non annulés.
SELECT date_heure,
       COUNT(*) AS nb_rendez_vous,
       GROUP_CONCAT(id ORDER BY cree_le) AS ids_par_ordre_de_reservation
FROM rendez_vous
WHERE statut <> 'annule'
GROUP BY date_heure
HAVING COUNT(*) > 3
ORDER BY date_heure;

-- 3. TMA-105 : rendez-vous passés du statut « annulé » à un autre statut.
SELECT h.rendez_vous_id, r.npi, r.date_heure,
       h.ancien_statut, h.nouveau_statut, h.modifie_le, h.modifie_par,
       r.statut AS statut_actuel
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.ancien_statut = 'annule'
  AND h.nouveau_statut <> 'annule'
ORDER BY h.modifie_le;

-- TMA-104 (diagnostic) : annulations par mois et délai minimal avant le rendez-vous.
--   Jusqu'au 03/10 des annulations à 30 h et 39 h passent ; ensuite, plus aucune.
SELECT DATE_FORMAT(h.modifie_le, '%Y-%m') AS mois,
       COUNT(*) AS annulations,
       ROUND(MIN(TIMESTAMPDIFF(MINUTE, h.modifie_le, r.date_heure)) / 60, 1) AS delai_min_heures,
       MAX(h.modifie_le) AS derniere
FROM historique_statuts h
JOIN rendez_vous r ON r.id = h.rendez_vous_id
WHERE h.nouveau_statut = 'annule'
GROUP BY 1
ORDER BY 1;

-- Bonus 1 : NPI qui ne comportent pas exactement 10 chiffres.
SELECT id, npi, CHAR_LENGTH(npi) AS longueur, service, date_heure, statut
FROM rendez_vous
WHERE npi NOT REGEXP '^[0-9]{10}$'
ORDER BY id;

-- Bonus 2 : rendez-vous hors grille (avant 08:00, après 15:30, ou pas sur une demi-heure).
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE TIME(date_heure) < '08:00:00'
   OR TIME(date_heure) > '15:30:00'
   OR MINUTE(date_heure) NOT IN (0, 30)
   OR SECOND(date_heure) <> 0
ORDER BY date_heure;

-- Bonus 3 : rendez-vous pris alors que l'usager en avait déjà un autre à venir
--           (créé avant, prévu après la nouvelle réservation, et pas encore clôturé à ce moment-là).
SELECT b.id, b.npi, b.service, b.date_heure, b.cree_le,
       a.id AS deja_a_venir, a.service AS son_service, a.date_heure AS sa_date
FROM rendez_vous b
JOIN rendez_vous a ON a.npi = b.npi AND a.id <> b.id
WHERE a.cree_le < b.cree_le
  AND a.date_heure > b.cree_le
  AND NOT EXISTS (SELECT 1 FROM historique_statuts h
                  WHERE h.rendez_vous_id = a.id AND h.modifie_le <= b.cree_le)
ORDER BY b.cree_le;
