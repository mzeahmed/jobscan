const dateTime = new Intl.DateTimeFormat('fr-FR', {dateStyle: 'short', timeStyle: 'short'});

/** « 24/11/2026 14:30 » ; chaîne vide si la date est absente ou illisible (jamais « Invalid Date »). */
export function formatDateTime(iso: string | null): string {
  if (iso === null) {
    return '';
  }

  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? '' : dateTime.format(date);
}

/** La date (ISO) tombe-t-elle le même jour local que `now` ? */
export function isSameDay(iso: string | null, now: Date = new Date()): boolean {
  if (iso === null) {
    return false;
  }

  const date = new Date(iso);

  return !Number.isNaN(date.getTime()) && date.toDateString() === now.toDateString();
}
