/** Statuts d'une candidature (voir `ApplicationStatus` côté API). */
export type ApplicationStatusValue =
  | 'draft'
  | 'applied'
  | 'interview'
  | 'technical_test'
  | 'offer'
  | 'accepted'
  | 'rejected'
  | 'withdrawn'
  | 'no_response';

export interface StatusRef {
  value: ApplicationStatusValue;
  label: string;
}

export interface ApplicationJob {
  id: number;
  title: string | null;
  company: string | null;
  location: string | null;
  /** URL http(s) filtrée par l'API, ou `null`. */
  url: string | null;
}

/** Candidature en liste. Les dates sont en ISO 8601 ; une valeur inconnue est `null`. */
export interface ApplicationSummary {
  id: number;
  status: StatusRef;
  job: ApplicationJob;
  channel: {value: string; label: string} | null;
  cv_version: {id: string; label: string | null} | null;
  search_profile_id: string | null;
  recruiter_name: string | null;
  applied_at: string | null;
  last_contact_at: string | null;
  follow_up_at: string | null;
  follow_up_due: boolean;
  status_changed_at: string;
  created_at: string;
}

export interface HistoryStep {
  from: StatusRef | null;
  to: StatusRef;
  note: string | null;
  changed_at: string;
}

/** Détail : texte saisi par l'utilisateur (notes, message envoyé) inclus. */
export interface ApplicationDetail extends ApplicationSummary {
  recruiter_contact: string | null;
  message_sent: string | null;
  notes: string | null;
  follow_up_reminded_at: string | null;
  responded_at: string | null;
  allowed_transitions: StatusRef[];
  history: HistoryStep[];
}

export interface StatusCount extends StatusRef {
  count: number;
}

export interface ApplicationsList {
  items: ApplicationSummary[];
  /** Un compteur par statut, indépendant du filtre. */
  counts: StatusCount[];
  total: number;
  due_follow_ups: number;
  limit: number;
}

export interface ApplicationOptions {
  channels: {value: string; label: string}[];
  cv_versions: {id: string; label: string}[];
}

export interface ApplicationDetailResponse {
  application: ApplicationDetail;
  options: ApplicationOptions;
}

/** Champs modifiables par `PATCH` : seuls les champs présents sont envoyés. */
export interface ApplicationPatch {
  recruiter_name?: string | null;
  recruiter_contact?: string | null;
  channel?: string | null;
  cv?: string | null;
  message_sent?: string | null;
  notes?: string | null;
  follow_up_at?: string | null;
}

export interface StatusChange {
  status: ApplicationStatusValue;
  at?: string;
  note?: string;
}
