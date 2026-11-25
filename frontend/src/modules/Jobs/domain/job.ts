/** Décision de l'utilisateur sur une offre (`GET /api/v1/jobs`, voir docs/API.md). */
export type JobStateValue = 'saved' | 'ignored' | 'archived';

export interface JobUserState {
  value: JobStateValue;
  label: string;
}

/**
 * Offre telle que l'API la publie. Les textes (titre, entreprise, extrait) viennent d'une annonce écrite par un tiers :
 * ils s'affichent comme du texte, jamais comme du HTML. `url` est déjà filtrée côté API (http/https) et peut être nulle.
 * Une valeur inconnue est `null` : on ne la remplace jamais par une valeur inventée.
 */
export interface JobSummary {
  id: number;
  title: string | null;
  url: string | null;
  source: string | null;
  company: string | null;
  location: string | null;
  remote: boolean;
  freelance: boolean;
  contract_type: string;
  seniority: string;
  stack: string[];
  score: number | null;
  excerpt: string;
  created_at: string | null;
  published_at: string | null;
  notified_at: string | null;
  user_state: JobUserState | null;
  application_id: number | null;
}

export interface Pagination {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export interface JobsPage {
  items: JobSummary[];
  pagination: Pagination;
  sources: string[];
}
