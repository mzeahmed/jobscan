import {useTranslation} from 'react-i18next';
import {formatDateTime} from '@/i18n/format.ts';
import type {HistoryStep} from '@/modules/Applications/domain/application.ts';

/** Historique des changements de statut, du plus récent au plus ancien. */
export function HistoryTimeline({history}: {history: readonly HistoryStep[]}) {
  const {t} = useTranslation();
  const steps = [...history].reverse();

  return (
    <ol className="js-timeline" aria-label={t('applications.detail.historyTitle')}>
      {steps.map((step, index) => (
        <li key={`${step.changed_at}-${index}`} className="js-timeline-step">
          <div className="js-timeline-title">
            {step.from && <span className="js-timeline-from">{step.from.label} → </span>}
            {step.to.label}
          </div>
          <time className="js-timeline-date" dateTime={step.changed_at}>
            {formatDateTime(step.changed_at)}
          </time>
          {step.note && <p className="js-timeline-note">{step.note}</p>}
        </li>
      ))}
    </ol>
  );
}
