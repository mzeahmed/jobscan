import {useTranslation} from 'react-i18next';
import {Card} from '@/design-system/components/Card.tsx';
import {type BreakdownRow, type Dimension, formatRate} from '@/modules/Analytics/domain/analytics.ts';

interface BreakdownTableProps {
  dimension: Dimension;
  rows: readonly BreakdownRow[];
}

/** Une répartition (source, CV, profil ou canal) : envois puis réponses, entretiens et propositions en « x/y ». */
export function BreakdownTable({dimension, rows}: BreakdownTableProps) {
  const {t} = useTranslation();
  const title = t(`analytics.breakdowns.${dimension}`);

  return (
    <Card title={title}>
      {rows.length === 0 ? (
        <p className="js-form-hint">{t('analytics.breakdowns.none')}</p>
      ) : (
        <div className="js-table-wrap">
          <table className="js-table">
            <caption className="js-visually-hidden">{title}</caption>
            <thead>
              <tr>
                <th scope="col">{t('analytics.breakdowns.label')}</th>
                <th scope="col">{t('analytics.breakdowns.sent')}</th>
                <th scope="col">{t('analytics.breakdowns.responded')}</th>
                <th scope="col">{t('analytics.breakdowns.interviews')}</th>
                <th scope="col">{t('analytics.breakdowns.offers')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.label}>
                  <th scope="row">{row.label}</th>
                  <td>{row.sent}</td>
                  <td>{formatRate(row.responded, row.sent, row.response_rate)}</td>
                  <td>{formatRate(row.interviews, row.sent, row.interview_rate)}</td>
                  <td>{formatRate(row.offers, row.sent, row.offer_rate)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}
