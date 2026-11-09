# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et le versioning utilise CalVer (`YYYY.MM.PATCH`).

## [2026.11.1] - 2026-11-09

### Added

- Webhook SES/SNS : désinscription automatique des hard bounces et des plaintes, avec URL signée et Topic ARN optionnel.
- Rapport hebdomadaire (lundi 09:00) avec statistiques de la semaine et liste des inscriptions en attente.
- Courbes de statistiques : abonnés actifs, inscriptions/désinscriptions, emails envoyés et erreurs.
- Cartes Pending, Abonnés actifs, Désinscrits et Erreurs dans l'onglet Statistiques.
- Carte Erreurs sur le dashboard.

### Changed

- Le rapport hebdomadaire inclut désormais les statistiques de la semaine en plus des pending.
- Le sujet du rapport devient « Rapport hebdo — X inscrit(s), Y pending ».
- Le versioning du projet passe en CalVer.

### Fixed

- La courbe des abonnés actifs tient compte des réinscriptions.
- L'expéditeur du rapport hebdomadaire utilise l'email vérifié du plugin.
- Le compteur d'erreurs est persisté dans les logs d'envoi.
