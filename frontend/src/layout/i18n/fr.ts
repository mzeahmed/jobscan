export const fr = {
  layout: {
    skipToContent: 'Aller au contenu',
    nav: {
      label: 'Navigation principale',
      section: 'Navigation',
      home: 'Accueil',
      jobs: 'Offres',
      applications: 'Candidatures',
      analytics: 'Analytics'
    },
    signedInAs: 'Connecté : {{username}}',
    logout: 'Déconnexion',
    loggingOut: 'Déconnexion…',
    logoutFailed: "La déconnexion a échoué : le serveur ne l'a pas confirmée. Votre session reste ouverte, réessayez.",
    notFound: {
      title: 'Page introuvable',
      text: "Cette adresse ne correspond à aucun écran de l'application.",
      back: "Retour à l'accueil"
    },
    unavailable: {
      title: 'Service indisponible',
      text: "Jobscan n'arrive pas à joindre son serveur. Votre session n'est pas perdue : réessayez dans un instant.",
      reason: 'Cause : {{reason}}'
    },
    notConfigured: {
      title: 'Authentification non configurée',
      text: "Le serveur répond, mais la connexion n'y est pas encore activée. Vérifiez ces deux réglages dans app/.env.local (la commande indiquée produit la valeur), puis réessayez."
    }
  }
} as const;
