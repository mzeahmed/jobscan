# Alertes email

`EmailJobProvider` (nom CLI : `email`) récupère les offres contenues dans les emails d'alerte des
plateformes de recrutement reçus dans une **boîte dédiée**. Il est **désactivé par défaut**
(`EMAIL_ENABLED=false`).

> **État de validation : les parsers n'ont pas été validés sur de vrais emails.** Aucun email réel n'était
> disponible : les fixtures de `tests/Fixtures/email/` sont **reconstruites et anonymisées** à partir de
> la forme supposée des URLs d'offres de chaque plateforme. Les tests prouvent le comportement du code sur
> cette mise en page, pas sur les emails réels. Voir « Validation » ci-dessous.

## Configuration (`app/.env.local`)

```dotenv
EMAIL_ENABLED=true
EMAIL_IMAP_HOST=imap.example.com
EMAIL_IMAP_USERNAME=alertes@example.com
EMAIL_IMAP_PASSWORD=mot-de-passe-d-application
# EMAIL_IMAP_PORT=993        EMAIL_IMAP_ENCRYPTION=ssl      EMAIL_IMAP_FOLDER=INBOX
# EMAIL_IMAP_AUTH=plain      (ou oauth, voir ci-dessous)    EMAIL_IMAP_VALIDATE_CERT=true
```

- **Boîte dédiée** : créez une adresse réservée aux alertes (ou un dossier/libellé dédié via `EMAIL_IMAP_FOLDER`)
  et configurez les alertes des plateformes pour qu'elles y arrivent. Ne pointez pas Jobscan sur votre boîte personnelle.
- **Mot de passe d'application** : à privilégier (Gmail, Outlook, etc. en proposent) plutôt que le mot de passe du compte.
- **OAuth2** : `EMAIL_IMAP_AUTH=oauth` et `EMAIL_IMAP_PASSWORD=<jeton d'accès>` (XOAUTH2). Jobscan **ne renouvelle pas**
  le jeton : un jeton expiré fait échouer le provider (visible dans `app:jobs:stats`). Le flux OAuth2 complet avec
  jeton de rafraîchissement n'est pas implémenté ; à n'utiliser que si le fournisseur impose OAuth2.
- Les secrets ne vont que dans `.env.local` (ignoré par Git) et ne sont jamais journalisés.
- Les valeurs `.env.example` sont des exemples sans secret.

## Fonctionnement

1. À chaque run, le client IMAP liste les en-têtes des emails reçus depuis `app.profile.email_lookback_days` (7 jours),
   **sans modifier la boîte** (lecture « peek » : les messages restent non lus) ; il ignore ceux déjà dans `processed_email`.
2. Jusqu'à `app.profile.email_max_messages` (50) emails sont téléchargés, du plus ancien au plus récent.
3. Le premier parser dont `supports()` reconnaît le domaine de l'expéditeur extrait les offres. Le parser générique
   (opt-in) passe en dernier.
4. Les offres suivent le pipeline habituel (filtres, déduplication, persistance, analyse).
5. **Après** l'ingestion, `acknowledge()` marque chaque email comme traité. Un email dont l'ingestion a échoué n'est
   **pas** marqué et sera retenté. En `--dry-run`, rien n'est marqué.

Le suivi (`processed_email`) est indépendant de l'état lu/non lu. L'identité d'un email est son `Message-ID` ou, à défaut,
dossier + `UIDVALIDITY` + UID. Statuts : `ingested`, `no_offers` (email reconnu sans offre), `parse_failed`
(structure inexploitable, relevé dans les diagnostics du provider), `unsupported` (expéditeur inconnu).
Seuls des métadonnées (expéditeur, sujet tronqué, date, plateforme, compteurs) sont conservées, jamais le corps.

## Ce qui est extrait, et ce qui ne l'est pas

Extrait : **titre, URL normalisée, identifiant externe** et le **texte de la « carte »** de l'offre (utilisé comme description,
il contient en général entreprise, lieu et contrat). Le texte et le HTML sont pris en charge (repli sur le texte brut).

**Non extrait volontairement** : entreprise, lieu, contrat, salaire et date de publication restent inconnus dans les champs
structurés — ils seront lus dans la description par l'analyse IA. Sans emails réels, deviner leur emplacement reviendrait à les
inventer. La **date de publication est toujours inconnue** : la date de réception d'un email n'est pas celle de publication de l'offre.

Les offres sont rattachées à la source `email-{plateforme}` ; l'identifiant externe est celui de la plateforme.

## Plateformes

| Plateforme | Domaines d'expéditeur | Lien d'offre attendu | Validation sur emails réels |
|---|---|---|---|
| LinkedIn | `linkedin.com` | `/(comm/)jobs/view/{id}` | **À valider** |
| Indeed | `indeed.com` | `/viewjob?jk=` ou `/rc/clk?jk=` | **À valider** |
| HelloWork | `hellowork.com` | `/fr-fr/emplois/{id}.html` | **À valider** |
| Apec | `apec.fr` | `/candidat/recherche-emploi.html/emploi/detail-offre/{id}` | **À valider** |
| France Travail | `francetravail.fr`, `pole-emploi.fr` | `/offres/recherche/detail/{id}` | **À valider** |
| Welcome to the Jungle | `welcometothejungle.com` | `/{langue}/companies/{entreprise}/jobs/{offre}` | **À valider** |
| Générique (opt-in) | `EMAIL_GENERIC_SENDER_DOMAINS` | hôtes de `EMAIL_GENERIC_LINK_DOMAINS` | n/a |

L'URL France Travail est la même que celle de `FranceTravailProvider` : une offre vue par les deux sources est un doublon certain.
LinkedIn et Indeed produisent une URL canonique propre (suivi retiré), identique à celle d'une offre déjà connue sans paramètres de suivi.

## Sécurité

- **Contenu non fiable** : un email n'est jamais exécuté ni rendu ; le HTML est réduit à du texte (scripts et styles retirés,
  caractères invisibles supprimés), puis traité comme n'importe quelle donnée externe par l'analyse IA.
- **Pas de SSRF** : aucun lien n'est suivi. Les liens de suivi qui passent par un domaine tiers (redirections) sont **ignorés**,
  jamais résolus. Seuls les liens vers les domaines de la plateforme, dans une forme d'offre reconnue, sont conservés.
- **Expéditeur** : reconnu par domaine (sous-domaines inclus, pas `unlinkedin.com`). L'en-tête `From` peut être falsifié ;
  l'impact est limité car seuls des liens d'offres vers les domaines de la plateforme sont extraits. Les en-têtes SPF/DKIM
  (`Authentication-Results`) ne sont pas vérifiés.
- Le parser générique ne fait rien tant qu'il n'est pas configuré.

## Ne pas exclure LinkedIn / Indeed par erreur

`EXCLUDED_PLATFORM_DOMAINS` écarte les offres de ces domaines **quelle que soit leur provenance**, alertes email comprises. Si
vous y avez mis `linkedin.com` ou `indeed.com` pour éviter leur collecte par SearXNG, déplacez-les dans
`COLLECTION_BLOCKED_DOMAINS` (qui n'agit que sur SearXNG et les flux RSS), sinon les offres de vos alertes email seront écartées.

## Validation sur de vrais emails

Pour chaque plateforme : transférez un vrai email d'alerte (anonymisé) vers la boîte dédiée ou ajoutez-le en fixture, puis vérifiez que
les offres, titres et URLs extraits sont corrects et que les liens de suivi sont bien reconnus. Lancez d'abord
`php bin/console app:jobs:run --dry-run --provider=email` : le dry-run n'enregistre rien et ne marque aucun email.

Points les plus susceptibles de différer des emails réels : les liens passent par un domaine de suivi tiers (alors aucune offre n'est
extraite) ; le titre n'est pas le texte du lien ; plusieurs offres partagent un même bloc HTML.

## Ajouter une plateforme

Créer une classe qui étend `AbstractLinkEmailParser` (domaines d'expéditeur, reconnaissance et normalisation du lien d'offre) dans
`src/Email/Parser/` : elle est autoconfigurée (tag `app.email_parser`), sans modifier `EmailJobProvider`.

## Limites connues

- Les parsers ne sont pas validés sur de vrais emails (voir ci-dessus) et l'extraction ne couvre que titre, URL, identifiant, texte de carte.
- La conversation IMAP réelle (connexion, authentification, recherche) n'est testée qu'avec les doubles de la bibliothèque, pas sur un vrai serveur.
- Pas de renouvellement automatique du jeton OAuth2.
- Un email en `parse_failed` n'est pas retenté automatiquement (il faudrait corriger le parser puis supprimer sa ligne de `processed_email`).
- Un email de plus de `email_lookback_days` jours non traité est perdu.
