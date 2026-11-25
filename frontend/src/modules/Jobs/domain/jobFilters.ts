/**
 * Filtres de la liste des offres. Ils vivent dans l'adresse (`?source=rss&min_score=60`) : la page se partage, se met
 * en favori et le bouton « précédent » fonctionne. Une chaîne vide veut dire « pas de filtre ».
 */
export interface JobFilters {
  location: string;
  remote: '' | 'remote' | 'onsite';
  contract: string;
  seniority: string;
  freelance: '' | 'yes' | 'no';
  minScore: string;
  source: string;
  age: '' | '1' | '7' | '30';
  notified: '' | 'yes' | 'no';
  sort: 'date' | 'score';
  /** `''` : offres sans décision (valeur par défaut de l'API). */
  state: '' | 'saved' | 'ignored' | 'archived' | 'all';
  page: number;
}

export const defaultFilters: JobFilters = {
  location: '',
  remote: '',
  contract: '',
  seniority: '',
  freelance: '',
  minScore: '',
  source: '',
  age: '',
  notified: '',
  sort: 'date',
  state: '',
  page: 1
};

export const contractValues = ['freelance', 'cdi', 'cdd', 'alternance', 'stage'] as const;
export const seniorityValues = ['junior', 'mid', 'senior'] as const;

/** Paramètre d'adresse (identique au paramètre d'API) → clé du filtre. */
const PARAMS: Record<keyof JobFilters, string> = {
  location: 'location',
  remote: 'remote',
  contract: 'contract',
  seniority: 'seniority',
  freelance: 'freelance',
  minScore: 'min_score',
  source: 'source',
  age: 'age',
  notified: 'notified',
  sort: 'sort',
  state: 'state',
  page: 'page'
};

function oneOf<T extends string>(value: string | null, allowed: readonly T[], fallback: T): T {
  return allowed.find((candidate) => candidate === value) ?? fallback;
}

/** Lit l'adresse sans jamais échouer : une valeur inconnue (adresse modifiée à la main) retombe sur le défaut. */
export function parseFilters(params: URLSearchParams): JobFilters {
  const minScore = params.get(PARAMS.minScore) ?? '';
  const page = Number.parseInt(params.get(PARAMS.page) ?? '1', 10);

  return {
    location: (params.get(PARAMS.location) ?? '').slice(0, 100),
    remote: oneOf(params.get(PARAMS.remote), ['', 'remote', 'onsite'], ''),
    contract: oneOf(params.get(PARAMS.contract), ['', ...contractValues], ''),
    seniority: oneOf(params.get(PARAMS.seniority), ['', ...seniorityValues], ''),
    freelance: oneOf(params.get(PARAMS.freelance), ['', 'yes', 'no'], ''),
    minScore: /^(?:100|[1-9]?\d)$/.test(minScore) ? minScore : '',
    source: (params.get(PARAMS.source) ?? '').slice(0, 100),
    age: oneOf(params.get(PARAMS.age), ['', '1', '7', '30'], ''),
    notified: oneOf(params.get(PARAMS.notified), ['', 'yes', 'no'], ''),
    sort: oneOf(params.get(PARAMS.sort), ['date', 'score'], 'date'),
    state: oneOf(params.get(PARAMS.state), ['', 'saved', 'ignored', 'archived', 'all'], ''),
    page: Number.isInteger(page) && page >= 1 && page <= 100000 ? page : 1
  };
}

/** Paramètres (adresse comme API) : seules les valeurs qui diffèrent du défaut sont écrites. */
export function filtersToParams(filters: JobFilters): URLSearchParams {
  const params = new URLSearchParams();

  (Object.keys(PARAMS) as (keyof JobFilters)[]).forEach((key) => {
    const value = String(filters[key]);

    if (value !== String(defaultFilters[key])) {
      params.set(PARAMS[key], value);
    }
  });

  return params;
}

/** Nombre de filtres actifs (hors tri et page) : sert à afficher « Réinitialiser ». */
export function activeFilterCount(filters: JobFilters): number {
  return (Object.keys(filters) as (keyof JobFilters)[]).filter(
    (key) => key !== 'page' && key !== 'sort' && String(filters[key]) !== String(defaultFilters[key])
  ).length;
}
