export const fr = {
  jobs: {
    eyebrow: 'Offres',
    title: 'Offres',
    count_one: '{{count}} offre analysée et scorée',
    count_other: '{{count}} offres analysées et scorées',
    countFiltered_one: '{{count}} offre correspond aux filtres',
    countFiltered_other: '{{count}} offres correspondent aux filtres',
    loadFailed: 'Impossible de charger les offres : {{message}}',
    actionFailed: "L'action a échoué : {{message}}",
    filters: {
      label: 'Filtres des offres',
      location: 'Lieu',
      locationPlaceholder: 'Ex. Paris, France',
      remote: 'Mode',
      contract: 'Contrat',
      seniority: 'Séniorité',
      freelance: 'Freelance',
      minScore: 'Score minimum',
      source: 'Source',
      age: 'Ancienneté',
      notified: 'Notification',
      sort: 'Tri',
      state: 'Affichage',
      all: 'Tous',
      apply: 'Filtrer',
      reset: 'Réinitialiser'
    },
    options: {
      remote: 'Remote',
      onsite: 'Sur site',
      yes: 'Oui',
      no: 'Non',
      age1: '24 heures',
      age7: '7 jours',
      age30: '30 jours',
      notifiedYes: 'Notifiée',
      notifiedNo: 'Non notifiée',
      sortDate: "Date d'ajout",
      sortScore: 'Score',
      stateActive: 'À trier',
      stateSaved: 'Sauvegardées',
      stateIgnored: 'Ignorées',
      stateArchived: 'Archivées',
      stateAll: 'Toutes'
    },
    contract: {
      freelance: 'Freelance',
      cdi: 'CDI',
      cdd: 'CDD',
      alternance: 'Alternance',
      stage: 'Stage',
      unknown: 'Contrat non précisé'
    },
    seniority: {
      junior: 'Junior',
      mid: 'Confirmé',
      senior: 'Senior',
      unknown: 'Séniorité non précisée'
    },
    card: {
      untitled: 'Sans titre',
      scoreLabel: 'Score de pertinence : {{score}} sur 100',
      scoreUnknown: 'Score non calculé',
      open: "Voir l'offre (nouvel onglet)",
      isNew: 'Nouvelle',
      remote: 'Remote',
      freelance: 'Freelance',
      notified: 'Notifiée',
      track: 'Suivre',
      tracking: 'Ouverture…',
      application: 'Candidature',
      saved: 'Sauvegarder',
      ignored: 'Ignorer',
      archived: 'Archiver',
      clear: 'Retirer la décision',
      working: 'En cours…'
    },
    empty: {
      title: 'Aucune offre',
      filtered: 'Aucune offre ne correspond à ces filtres.',
      none: "Rien à trier pour l'instant : les nouvelles offres apparaîtront ici après la prochaine collecte."
    }
  }
} as const;
