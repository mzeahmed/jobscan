import {Link} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import {faArrowUpRightFromSquare} from '@fortawesome/free-solid-svg-icons';
import {Badge} from '@/design-system/components/Badge.tsx';
import {Button} from '@/design-system/components/Button.tsx';
import {Icon} from '@/design-system/components/Icon.tsx';
import {formatDateTime, isSameDay} from '@/i18n/format.ts';
import {ScoreRing} from '@/modules/Jobs/components/ScoreRing.tsx';
import {scoreLevel} from '@/modules/Jobs/domain/scoreLevel.ts';
import type {JobStateValue, JobSummary} from '@/modules/Jobs/domain/job.ts';

const DECISIONS: readonly JobStateValue[] = ['saved', 'ignored', 'archived'];

interface JobCardProps {
  job: JobSummary;
  /** Une action est en cours sur cette offre : ses boutons sont désactivés. */
  busy: boolean;
  tracking: boolean;
  onTrack: (jobId: number) => void;
  onDecide: (jobId: number, state: JobStateValue | null) => void;
}

/**
 * Une offre. Titre, entreprise et extrait viennent d'une annonce non fiable : ils sont rendus comme du texte par React
 * (échappés). Le lien vers l'annonce n'existe que si l'API a fourni une URL http(s), et s'ouvre sans transmettre
 * l'origine (`noopener noreferrer`).
 */
export function JobCard({job, busy, tracking, onTrack, onDecide}: JobCardProps) {
  const {t} = useTranslation();
  const title = job.title?.trim() || t('jobs.card.untitled');
  const level = job.score === null ? 'unknown' : scoreLevel(job.score);
  const contract = job.contract_type !== 'unknown' ? t(`jobs.contract.${job.contract_type}`, {defaultValue: ''}) : '';
  const meta = [job.company, job.location].filter((part): part is string => Boolean(part?.trim()));

  return (
    <li>
      <article className={`js-job js-job-${level}`}>
        <ScoreRing score={job.score} />

        <div className="js-job-main">
          <h2 className="js-job-title">
            {job.url ? (
              <a href={job.url} target="_blank" rel="noopener noreferrer nofollow" title={t('jobs.card.open')}>
                {title}
                <Icon icon={faArrowUpRightFromSquare} className="js-job-external" />
              </a>
            ) : (
              title
            )}
          </h2>

          <p className="js-job-meta">
            {job.source && <span className="js-job-source">{job.source}</span>}
            {meta.map((part) => (
              <span key={part}>{part}</span>
            ))}
            {job.created_at && <time dateTime={job.created_at}>{formatDateTime(job.created_at)}</time>}
          </p>

          {job.excerpt && <p className="js-job-excerpt">{job.excerpt}</p>}

          <div className="js-job-badges">
            {isSameDay(job.created_at) && <Badge tone="info">{t('jobs.card.isNew')}</Badge>}
            {job.remote && <Badge tone="success">{t('jobs.card.remote')}</Badge>}
            {contract && <Badge>{contract}</Badge>}
            {job.freelance && <Badge>{t('jobs.card.freelance')}</Badge>}
            {job.notified_at && <Badge title={formatDateTime(job.notified_at)}>{t('jobs.card.notified')}</Badge>}
            {job.user_state && <Badge tone="warning">{job.user_state.label}</Badge>}
          </div>

          <div className="js-job-actions">
            {job.application_id !== null ? (
              <Link className="js-btn js-btn-secondary js-btn-sm" to={`/applications/${job.application_id}`}>
                {t('jobs.card.application')}
              </Link>
            ) : (
              <Button size="sm" variant="primary" disabled={busy} onClick={() => onTrack(job.id)}>
                {tracking ? t('jobs.card.tracking') : t('jobs.card.track')}
              </Button>
            )}
            {DECISIONS.filter((state) => job.user_state?.value !== state).map((state) => (
              <Button
                key={state}
                size="sm"
                variant={state === 'ignored' ? 'danger-ghost' : 'ghost'}
                disabled={busy}
                onClick={() => onDecide(job.id, state)}
              >
                {t(`jobs.card.${state}`)}
              </Button>
            ))}
            {job.user_state && (
              <Button size="sm" variant="ghost" disabled={busy} onClick={() => onDecide(job.id, null)}>
                {t('jobs.card.clear')}
              </Button>
            )}
          </div>
        </div>
      </article>
    </li>
  );
}
