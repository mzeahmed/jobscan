import {useTranslation} from 'react-i18next';
import {Button} from './Button.tsx';

interface PaginationProps {
  page: number;
  totalPages: number;
  onChange: (page: number) => void;
}

/** Précédent / Suivant avec « Page x sur y ». Masquée quand il n'y a qu'une page. */
export function Pagination({page, totalPages, onChange}: PaginationProps) {
  const {t} = useTranslation();

  if (totalPages <= 1) {
    return null;
  }

  return (
    <nav className="js-pagination" aria-label={t('common.pagination.label')}>
      <Button size="sm" variant="ghost" disabled={page <= 1} onClick={() => onChange(page - 1)}>
        {t('common.pagination.previous')}
      </Button>
      <span className="js-pagination-info" aria-current="page">
        {t('common.pagination.info', {page, total: totalPages})}
      </span>
      <Button size="sm" variant="ghost" disabled={page >= totalPages} onClick={() => onChange(page + 1)}>
        {t('common.pagination.next')}
      </Button>
    </nav>
  );
}
