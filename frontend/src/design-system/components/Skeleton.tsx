interface SkeletonProps {
  /** `line` (défaut), `short` (40 % de large) ou `block` (zone haute). */
  shape?: 'line' | 'short' | 'block';
}

/** Emplacement de contenu en cours de chargement. Décoratif : le texte « Chargement » est porté par le parent. */
export function Skeleton({shape = 'line'}: SkeletonProps) {
  const shapeClass = shape === 'line' ? '' : `js-skeleton-${shape}`;

  return <span className={['js-skeleton', shapeClass].filter(Boolean).join(' ')} aria-hidden="true" />;
}
