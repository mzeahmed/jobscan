# Assistant de candidature

`app:assistant:draft <offre>` prépare un **brouillon** de message de candidature. Il ne l'envoie à personne, ne l'enregistre pas et ne contacte aucun recruteur : vous le relisez, l'adaptez et l'envoyez
vous-même, puis vous pouvez consigner le message envoyé avec `app:applications:status … --message=…`.

```bash
php bin/console app:assistant:draft 42                              # brouillon sans LLM (par défaut)
php bin/console app:assistant:draft 42 --search-profile=backend     # un profil de recherche précis
php bin/console app:assistant:draft 42 --llm                        # texte rédigé par le LLM configuré, puis contrôlé
```

## Ce que contient le brouillon

Uniquement des **faits que vous avez déclarés** dans `candidate.yaml` et que l'offre rencontre : compétences demandées ou appréciées (avec le niveau et les années déclarés), expériences et projets
pertinents, langue demandée couverte. Ce sont les « arguments » calculés par le matching, repris mot pour mot. Le brouillon n'ajoute aucun chiffre, résultat, employeur, diplôme ni compétence.

- Les compétences demandées que vous **ne déclarez pas** ne sont **jamais mentionnées** dans le message ; elles sont listées dans « À vérifier avant d'envoyer ».
- Salaire, préférences de télétravail ou de contrat, email et téléphone n'apparaissent jamais dans le message. Le nom de la signature vient de votre profil (`[Votre nom]` s'il est absent) ; le nom du
  recruteur n'est utilisé que si vous l'avez saisi sur la candidature.
- Sans fait à mettre en avant (offre non rapprochée, profil vide), la commande refuse de rédiger plutôt que d'inventer. Une offre non jugée compatible donne un brouillon, avec un avertissement.

## Brouillon sans LLM, ou avec LLM

Par défaut, le texte vient d'un **modèle déterministe** : il ne peut rien inventer. Avec `--llm`, un LLM peut rédiger un texte plus naturel, mais son texte n'est utilisé qu'après un contrôle automatique
(`DraftGuard`) ; au moindre problème, vous recevez le brouillon sans LLM, avec la raison. Sont refusés : tout **chiffre** absent des faits, toute **compétence** connue absente des faits ou que vous
ne déclarez pas, tout **lien ou email**, toute **consigne** détectée, un texte vide ou trop long, une réponse qui n'est pas le JSON demandé.

Ce contrôle **ne prouve pas** l'exactitude de chaque phrase (il ne repère ni un employeur ni une formulation trompeuse inventés sans chiffre ni compétence connue) : la relecture reste indispensable.

## Confidentialité

| | Moteur local (Ollama, LM Studio sur votre machine ou réseau privé) | Moteur distant (Gemini, adresse non locale) |
|---|---|---|
| Faits du profil envoyés | oui, avec `--llm` | **non**, sauf `ASSISTANT_ALLOW_REMOTE_LLM=true` dans `.env.local` |
| Nom, email, téléphone, salaire, fichier de CV | jamais | jamais, même autorisé |
| Texte brut de l'annonce | jamais | jamais |

- Un moteur n'est « local » que si son adresse l'est clairement (localhost, boucle locale, hôte Docker, réseau privé). Une `OLLAMA_BASE_URL` pointant vers Internet est traitée comme **distante**.
- Le LLM ne reçoit que l'intitulé du poste et l'entreprise (entre balises à identifiant aléatoire, neutralisés), plus vos faits : **jamais le texte de l'annonce**, ce qui supprime l'essentiel du risque
  d'injection par une annonce piégée. Le contrôle de sortie couvre le reste.
- Votre signature est ajoutée localement, jamais par le LLM.

## Limites

- Français uniquement ; pas de lettre de motivation longue : un court message d'accompagnement.
- Le contenu du CV n'est pas lu (voir [CV.md](CV.md)) : les faits viennent du profil.
- Rien n'est enregistré : relancez la commande pour obtenir un nouveau brouillon (celui sans LLM est identique à chaque fois).
- Avec `--llm`, la qualité dépend du modèle ; un petit modèle local peut être refusé par le contrôle plus souvent. Le repli est alors le brouillon sans LLM.
- Pas de bouton ni d'envoi depuis Telegram ou l'interface (lots suivants).
