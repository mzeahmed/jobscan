import {useTranslation} from 'react-i18next';
import {Card} from '@/design-system/components/Card.tsx';
import type {SourcingRow} from '@/modules/Analytics/domain/analytics.ts';

interface SourcingTableProps {
  rows: readonly SourcingRow[];
}

/** Rendement des sources : des offres collectées à celles réellement envoyées. */
export function SourcingTable({rows}: SourcingTableProps) {
  const {t} = useTranslation();

  return (
    <Card title={t('analytics.sourcing.title')}>
      <p className="js-form-hint">{t('analytics.sourcing.hint')}</p>
      {rows.length === 0 ? (
        <p className="js-form-hint">{t('analytics.sourcing.none')}</p>
      ) : (
        <div className="js-table-wrap">
          <table className="js-table">
            <caption className="js-visually-hidden">{t('analytics.sourcing.title')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('analytics.sourcing.source')}</th>
                <th scope="col">{t('analytics.sourcing.collected')}</th>
                <th scope="col">{t('analytics.sourcing.compatible')}</th>
                <th scope="col">{t('analytics.sourcing.notified')}</th>
                <th scope="col">{t('analytics.sourcing.tracked')}</th>
                <th scope="col">{t('analytics.sourcing.applied')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.source}>
                  <th scope="row">{row.source}</th>
                  <td>{row.collected}</td>
                  <td>{row.compatible}</td>
                  <td>{row.notified}</td>
                  <td>{row.tracked}</td>
                  <td>{row.applied}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}
