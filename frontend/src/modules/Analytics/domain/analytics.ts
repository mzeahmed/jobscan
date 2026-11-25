/** Fenêtres acceptées par `GET /api/v1/analytics?since=`. */
export const windows = ['30d', '90d', '365d', 'all'] as const;
export type AnalyticsWindow = (typeof windows)[number];
export const defaultWindow: AnalyticsWindow = '90d';

/** La fenêtre demandée dans l'adresse, ou la valeur par défaut : une valeur inconnue n'est jamais envoyée à l'API. */
export function windowFromParams(params: URLSearchParams): AnalyticsWindow {
  return windows.find((value) => value === params.get('since')) ?? defaultWindow;
}

/** Un taux vaut `null` sous l'effectif minimal (`min_sample`) : on n'affiche alors que « x/y ». */
export interface Rates {
  response_rate: number | null;
  interview_rate: number | null;
  offer_rate: number | null;
}

export interface Funnel extends Rates {
  sent: number;
  responded: number;
  interviews: number;
  offers: number;
  accepted: number;
  rejected: number;
  no_response: number;
  /** Délai médian avant réponse, en jours ; `null` si aucune réponse. */
  median_days_to_response: number | null;
}

export interface BreakdownRow extends Rates {
  label: string;
  sent: number;
  responded: number;
  interviews: number;
  offers: number;
}

export const dimensions = ['source', 'cv', 'profile', 'channel'] as const;
export type Dimension = (typeof dimensions)[number];

export interface SourcingRow {
  source: string;
  collected: number;
  compatible: number;
  notified: number;
  tracked: number;
  applied: number;
}

export interface AnalyticsReport {
  window: AnalyticsWindow;
  since: string | null;
  generated_at: string;
  min_sample: number;
  funnel: Funnel;
  breakdowns: Record<Dimension, BreakdownRow[]>;
  sourcing: SourcingRow[];
}

/**
 * « 3/8 » sous l'effectif minimal, « 3/8 (38 %) » au-dessus. Le pourcentage vient du taux de l'API (`null` = trop
 * peu de données) : le front ne calcule jamais lui-même un pourcentage trompeur.
 */
export function formatRate(part: number, total: number, rate: number | null): string {
  return rate === null ? `${part}/${total}` : `${part}/${total} (${Math.round(rate * 100)} %)`;
}
