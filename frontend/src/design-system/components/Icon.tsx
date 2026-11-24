import {FontAwesomeIcon} from '@fortawesome/react-fontawesome';
import type {IconDefinition} from '@fortawesome/fontawesome-svg-core';

/**
 * Icône Font Awesome en SVG (paquets npm `@fortawesome/free-*-svg-icons`) : seules les icônes importées sont
 * embarquées, leur nom est vérifié par TypeScript. Importer l'icône (`import {faHouse} from
 * '@fortawesome/free-solid-svg-icons'`) et la passer en objet ; une règle ESLint signale les classes `fa-…`.
 *
 * Décorative par défaut (`aria-hidden`) : le texte qui l'accompagne porte le sens. Avec `label`, l'icône est
 * annoncée aux lecteurs d'écran et affiche une infobulle.
 */
export type IconSource = IconDefinition;

export function Icon({icon, className, label}: {icon: IconSource; className?: string; label?: string}) {
  return label ? (
    <span title={label}>
      <FontAwesomeIcon icon={icon} className={className} role="img" aria-label={label} />
    </span>
  ) : (
    <FontAwesomeIcon icon={icon} className={className} aria-hidden="true" />
  );
}
