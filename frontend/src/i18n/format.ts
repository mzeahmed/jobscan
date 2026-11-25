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

const dateOnly = new Intl.DateTimeFormat('fr-FR', {dateStyle: 'short'});

/** « 24/11/2026 » pour un instant (date et heure ISO) ; chaîne vide si absente ou illisible. */
export function formatDate(iso: string | null): string {
  if (iso === null) {
    return '';
  }

  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? '' : dateOnly.format(date);
}

/** Jour civil (`YYYY-MM-DD`) d'une date ISO, tel que le serveur l'a enregistré : pour un `<input type="date">`. */
export function calendarDay(iso: string | null): string {
  return iso !== null && /^\d{4}-\d{2}-\d{2}/.test(iso) ? iso.slice(0, 10) : '';
}

/** « 20/12/2026 » pour une date de calendrier (une relance) : le jour saisi, sans décalage de fuseau. */
export function formatCalendarDay(iso: string | null): string {
  const day = calendarDay(iso);

  return day === '' ? '' : day.split('-').reverse().join('/');
}

/** Aujourd'hui au format `YYYY-MM-DD` (heure locale), pour borner un champ date. */
export function todayInput(now: Date = new Date()): string {
  const pad = (value: number) => String(value).padStart(2, '0');

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
