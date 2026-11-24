# Interface web sécurisée

L'interface web (liste des offres, suivi des candidatures) contient des données personnelles : contacts de recruteurs, messages envoyés, notes. Elle est **fermée par défaut** : tant qu'aucun mot de passe n'est
configuré, personne ne peut se connecter, et toutes les pages renvoient vers `/login`.

> **Changement de comportement** : `/job` était auparavant accessible sans authentification ; il exige maintenant une connexion.

## Activer l'accès

```bash
php bin/console app:ui:hash-password        # saisie masquée, 12 caractères minimum, confirmation
```

Ajoutez la ligne affichée à `app/.env.local` (ignoré par Git), **entre apostrophes** (le hash contient des `$`) :

```dotenv
JOBSCAN_UI_USER=admin
JOBSCAN_UI_PASSWORD_HASH='$2y$...'
```

Seul le **hash** est stocké, jamais le mot de passe. Il n'y a qu'un compte : l'interface est un outil personnel, pas un service multi-utilisateurs. Pour changer le mot de passe, relancez la commande.

## Ce qui est protégé

| Protection | Détail |
|---|---|
| Authentification | Toutes les routes exigent la connexion, sauf `/login` et les ressources statiques. Une nouvelle route est protégée sans rien faire. |
| CSRF | Connexion, déconnexion et chaque écriture (suivre, décider, changer le statut, enregistrer) exigent un jeton propre à l'action **et** à l'objet : un jeton valide pour l'offre 1 est refusé pour l'offre 2. Les écritures n'existent qu'en POST. |
| Force brute | 5 tentatives par 15 minutes pour un même identifiant et une même adresse ; au-delà, même le bon mot de passe est refusé. Message d'erreur identique pour un identifiant inconnu et un mauvais mot de passe. |
| Session | Cookie `HttpOnly`, `SameSite=Lax`, `Secure` dès que la requête est en HTTPS. |
| En-têtes | CSP stricte (`default-src 'none'`, scripts limités à `'self'` et à un nonce différent à chaque requête, `frame-ancestors 'none'`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store` (aucune page personnelle en cache). |
| Contenu | Tout est échappé par Twig. Une URL d'annonce n'est cliquable que si elle est en `http(s)` (une annonce piégée ne peut pas y mettre `javascript:`) et ouvre un nouvel onglet avec `noopener noreferrer`. |
| Saisies | Statuts, canaux, CV et dates sont validés par les mêmes règles que les commandes (transitions interdites, dates dans le futur, CV non déclaré, etc.). Une saisie refusée ne change rien. |

## Fonctions

- **Offres** (`/job`) : *Suivre* (ouvre une candidature en brouillon : rien n'est envoyé), *Sauvegarder*, *Ignorer*, *Archiver*, *Retirer* ; filtre « Décision ». Les offres ignorées et archivées sont masquées par défaut.
- **Candidatures** (`/applications`) : liste filtrable par statut, avec compteurs.
- **Détail** (`/applications/{id}`) : changement de statut (seules les transitions autorisées sont proposées, avec date réelle et note), recruteur, contact, canal, version de CV, relance, message envoyé, notes, historique.

## Exposition

- **En local** (`php -S 127.0.0.1:8000 -t public`, `symfony serve`) : n'écoute que sur votre machine. C'est la configuration recommandée.
- **Derrière Traefik / HTTPS** : définissez `TRUSTED_PROXIES` (ex. `private_ranges`) pour que HTTPS et l'adresse du client soient correctement détectés ; sans cela, le cookie de session n'est pas marqué `Secure`. N'y mettez que vos propres proxys.
- N'exposez pas l'interface sur Internet sans HTTPS. Le mot de passe ne protège pas un échange en clair.

## Limites

- Un seul compte, pas de rôles, pas d'authentification à deux facteurs.
- La limitation des tentatives est stockée dans le cache de l'application : vider le cache la réinitialise.
- Pas de journal d'audit des actions dans l'interface.
- Le Telegram interactif et les rappels de relance ne font pas partie de ce lot.
- Les fonts et icônes viennent de Google Fonts et cdnjs (déjà le cas avant ce lot) : la CSP les autorise explicitement.
