# WP PK Newsletter

[🇫🇷 FR](README.md) · [🇬🇧 EN](README_en.md)

✨ Plugin WordPress pour gérer une newsletter éditoriale quotidienne avec base abonnés, digest HTML, historique d’inscription et envoi SMTP intégré.

## ✅ Fonctionnalités
- Dashboard admin dédié avec vues `Dashboard`, `Abonnés`, `Statistiques`, `Réglages`
- Formulaire d’inscription front via shortcode
- Gestion abonnés avec ajout manuel, import/export CSV, filtres, pagination et actions unitaires
- Historique conservé des abonnements, désinscriptions et réinscriptions
- Digest email HTML avec plusieurs mises en forme et thèmes visuels
- Prévisualisation email directement dans l’admin
- Envoi de digest de test vers une adresse dédiée avec choix du jour (`aujourd’hui` / `hier`) et du template à tester
- SMTP intégré dans le plugin
- Désinscription par lien tokenisé
- Statistiques d’envoi et courbes d’évolution des abonnés
- Dashboard `Posts du jour` affichant les contenus publiés et planifiés du jour, avec flag visuel sur les planifiés

## 🧠 Utilisation
Le plugin sert à piloter une newsletter “digest” depuis WordPress sans dépendre d’un SaaS pour l’interface de gestion.

Flux typique :
- les articles du jour sont récupérés depuis WordPress
- le digest est généré en HTML
- un email de test peut être envoyé depuis l’admin
- les abonnés sont gérés dans la vue `Abonnés`
- l’envoi réel peut ensuite être branché sur SMTP, par exemple Amazon SES

## ⚙️ Réglages
Dans `WP PK Newsletter > Réglages`, tu peux configurer :
- identité de marque
- email expéditeur et `Reply-To`
- couleur d’accent
- URL du logo
- mise en forme du digest
- thème visuel de l’email
- sujet avec variable `%date%`
- heure quotidienne
- nombre maximum de posts
- texte d’introduction et de footer
- SMTP host / port / sécurité / identifiants

## 🧾 Commandes
Le plugin n’expose pas de commande CLI dédiée.

Actions principales dans l’admin :
- `Envoyer un digest de test`
- `Importer CSV`
- `Exporter CSV`
- `Réactiver` / `Désactiver` un abonné

Shortcode disponible :

```text
[wppk_newsletter_form]
```

## 📦 Build & Package
Le plugin est prévu pour être distribué en zip WordPress classique.

Fichiers principaux :
- `src/wppknewsletter.php`
- `src/includes/class-wppk-newsletter.php`
- `src/templates/email-digest.php`
- `src/assets/admin.css`
- `src/assets/form.css`

Pour préparer un zip :
- lancer le script :

```bash
scripts/build-plugin.sh
```

- récupérer l’archive générée dans `extensions/`
- téléverser l’archive depuis `Extensions > Ajouter > Téléverser une extension`

Le build produit une archive avec un dossier racine stable `WPpknewsletter/`, ce qui permet à WordPress de remplacer correctement une version existante.

## 🧪 Installation
1. Téléverse le plugin dans WordPress.
2. Active `WP PK Newsletter`.
3. Ouvre `WP PK Newsletter` dans l’admin.
4. Configure l’identité, le digest et le SMTP.
5. Ajoute une page avec le shortcode :

```text
[wppk_newsletter_form]
```

## 📁 CSV abonnés
Import supporté :

```text
email,status,source,signup_process,delivery_channel,content_mode,preferred_hour,confirmed
```

Colonnes utiles exportées :
- `created_at`
- `subscribed_at`
- `unsubscribed_at`
- `resubscribed_at`

## 🔌 SMTP
Le plugin peut envoyer via `wp_mail()` ou avec une configuration SMTP intégrée.

Exemple Amazon SES :
- host : `email-smtp.eu-north-1.amazonaws.com`
- port : `587`
- sécurité : `TLS`

## 🧾 Changelog
- `1.18` : logo header agrandi, thumbnails email avec radius allégé, mot de passe SMTP non prérempli dans l’admin
- `1.17` : ajustements email visuels
- `1.16` : courbe abonnés en volume cumulé
- `1.15` : courbe abonnés compactée
- `1.14` : bloc `Envoyer un test` déplacé au-dessus de `Posts du jour` dans le dashboard
- `1.13` : retrait de l’onglet `Posts du jour`, flag orange plus visible sur les contenus planifiés, build zip stabilisé
- `1.12` : `Posts du jour` affiche les contenus publiés + planifiés avec heure prévue
- `1.11` : normalisation du versioning au format `1.x`
- `1.0.0` : première version stable utilisable

## 🔗 Liens
- EN README : [README_en.md](README_en.md)
- Site : <https://mondary.design>
