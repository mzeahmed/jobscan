import {useTranslation} from 'react-i18next';
import {Card} from '@/design-system/components/Card.tsx';
import {type Funnel, formatRate} from '@/modules/Analytics/domain/analytics.ts';

interface FunnelCardProps {
  funnel: Funnel;
}

/** Le parcours : envoyées → réponses → entretiens → propositions, chaque étape rapportée aux envois. */
export function FunnelCard({funnel}: FunnelCardProps) {
  const {t} = useTranslation();
  const steps = [
    {key: 'sent', label: t('analytics.funnel.sent'), value: funnel.sent, text: String(funnel.sent)},
    {
      key: 'responded',
      label: t('analytics.funnel.responded'),
      value: funnel.responded,
      text: formatRate(funnel.responded, funnel.sent, funnel.response_rate)
    },
    {
      key: 'interviews',
      label: t('analytics.funnel.interviews'),
      value: funnel.interviews,
      text: formatRate(funnel.interviews, funnel.sent, funnel.interview_rate)
    },
    {
      key: 'offers',
      label: t('analytics.funnel.offers'),
      value: funnel.offers,
      text: formatRate(funnel.offers, funnel.sent, funnel.offer_rate)
    }
  ];
  const outcomes = [
    {key: 'accepted', label: t('analytics.funnel.accepted'), value: funnel.accepted},
    {key: 'rejected', label: t('analytics.funnel.rejected'), value: funnel.rejected},
    {key: 'noResponse', label: t('analytics.funnel.noResponse'), value: funnel.no_response}
  ];

  return (
    <Card title={t('analytics.funnel.title')}>
      <ol className="js-funnel">
        {steps.map((step) => (
          <li key={step.key} className="js-funnel-step">
            <span className="js-funnel-label">{step.label}</span>
            <progress
              className="js-funnel-bar"
              value={step.value}
              max={Math.max(funnel.sent, 1)}
              aria-label={t('analytics.funnel.progress', {label: step.label, part: step.value, total: funnel.sent})}
            />
            <span className="js-funnel-value">{step.text}</span>
          </li>
        ))}
      </ol>
      <dl className="js-stats">
        {outcomes.map((outcome) => (
          <div key={outcome.key} className="js-stat">
            <dt>{outcome.label}</dt>
            <dd>{outcome.value}</dd>
          </div>
        ))}
        <div className="js-stat">
          <dt>{t('analytics.funnel.median')}</dt>
          <dd>
            {funnel.median_days_to_response === null
              ? t('analytics.funnel.medianUnknown')
              : t('analytics.funnel.medianValue', {count: funnel.median_days_to_response})}
          </dd>
        </div>
      </dl>
    </Card>
  );
}
