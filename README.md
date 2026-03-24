# WP PK Newsletter

[🇫🇷 FR](README.md) · [🇬🇧 EN](README_en.md)

✨ Plugin WordPress pour gérer une newsletter éditoriale quotidienne avec base abonnés, digest HTML, historique d’inscription et envoi SMTP intégré.

## ✅ Fonctionnalités
- Dashboard admin dédié avec vues `Dashboard`, `Abonnés`, `Posts du jour`, `Statistiques`, `Réglages`
- Formulaire d’inscription front via shortcode
- Gestion abonnés avec ajout manuel, import/export CSV, filtres, pagination et actions unitaires
- Historique conservé des abonnements, désinscriptions et réinscriptions
- Digest email HTML avec plusieurs mises en forme et thèmes visuels
- Prévisualisation email directement dans l’admin
- Envoi de digest de test vers une adresse dédiée
- SMTP intégré dans le plugin
- Désinscription par lien tokenisé
- Statistiques d’envoi et courbes d’évolution des abonnés

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
- utiliser une archive de build dans `extensions/`
- téléverser l’archive depuis `Extensions > Ajouter > Téléverser une extension`

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
- `1.0.7` : historique abonnés non destructif, courbe abonnés/désabonnés, rappel AWS free plan, stats compactées
- `1.0.6` : correction du layout `List + thumbnails`
- `1.0.5` : pagination abonnés et sélecteur du nombre d’éléments par page
- `1.0.0` : première version stable utilisable

## 🔗 Liens
- EN README : [README_en.md](README_en.md)
- Site : <https://mondary.design>
