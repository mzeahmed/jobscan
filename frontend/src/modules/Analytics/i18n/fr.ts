export const fr = {
  analytics: {
    eyebrow: 'Analytics',
    title: 'Analytics',
    subtitle: 'Parcours de vos candidatures et rendement de vos sources.',
    loadFailed: 'Impossible de charger les statistiques : {{message}}',
    windows: {
      label: 'Période',
      '30d': '30 jours',
      '90d': '90 jours',
      '365d': '12 mois',
      all: 'Tout'
    },
    sample: 'Un pourcentage n’est affiché qu’à partir de {{count}} candidatures : en dessous, seul « x/y » est donné.',
    empty: {
      title: 'Aucune candidature envoyée sur cette période',
      text: 'Les statistiques apparaissent dès qu’une candidature passe au statut « Postulé ».'
    },
    funnel: {
      title: 'Parcours',
      sent: 'Envoyées',
      responded: 'Réponses',
      interviews: 'Entretiens',
      offers: 'Propositions',
      accepted: 'Acceptées',
      rejected: 'Refusées',
      noResponse: 'Sans réponse',
      median: 'Délai médian de réponse',
      medianValue_one: '{{count}} jour',
      medianValue_other: '{{count}} jours',
      medianUnknown: 'Inconnu',
      progress: '{{label}} : {{part}} sur {{total}}'
    },
    breakdowns: {
      title: 'Répartitions',
      source: 'Par source',
      cv: 'Par version du CV',
      profile: 'Par profil de recherche',
      channel: 'Par canal',
      none: 'Aucune donnée.',
      label: 'Valeur',
      sent: 'Envoyées',
      responded: 'Réponses',
      interviews: 'Entretiens',
      offers: 'Propositions'
    },
    sourcing: {
      title: 'Rendement des sources',
      hint: 'Du nombre d’offres collectées à celles que vous avez réellement envoyées.',
      none: 'Aucune offre collectée sur cette période.',
      source: 'Source',
      collected: 'Collectées',
      compatible: 'Compatibles',
      notified: 'Notifiées',
      tracked: 'Suivies',
      applied: 'Postulées'
    },
    generatedAt: 'Calculé le {{date}}.'
  }
} as const;
