import {useTranslation} from 'react-i18next';
import {scoreLevel} from '@/modules/Jobs/domain/scoreLevel.ts';

const RADIUS = 18;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

/**
 * Anneau de score. Le décalage du trait est un attribut SVG (pas un style en ligne : la CSP les interdit). Sans score,
 * « — » : un score inconnu n'est pas un score nul.
 */
export function ScoreRing({score}: {score: number | null}) {
  const {t} = useTranslation();

  if (score === null) {
    return (
      <div className="js-score js-score-unknown" role="img" aria-label={t('jobs.card.scoreUnknown')}>
        <span className="js-score-value">—</span>
      </div>
    );
  }

  const bounded = Math.max(0, Math.min(100, score));

  return (
    <div
      className={`js-score js-score-${scoreLevel(bounded)}`}
      role="img"
      aria-label={t('jobs.card.scoreLabel', {score: bounded})}
    >
      <svg viewBox="0 0 44 44" aria-hidden="true">
        <circle className="js-score-track" cx="22" cy="22" r={RADIUS} fill="none" strokeWidth="4" />
        <circle
          className="js-score-fill"
          cx="22"
          cy="22"
          r={RADIUS}
          fill="none"
          strokeWidth="4"
          strokeLinecap="round"
          strokeDasharray={CIRCUMFERENCE}
          strokeDashoffset={CIRCUMFERENCE * (1 - bounded / 100)}
          transform="rotate(-90 22 22)"
        />
      </svg>
      <span className="js-score-value">{bounded}</span>
    </div>
  );
}
