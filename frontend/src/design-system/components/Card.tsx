import {type HTMLAttributes, type ReactNode, useId} from 'react';

interface CardProps extends Omit<HTMLAttributes<HTMLElement>, 'title'> {
  title?: ReactNode;
  /** Zone à droite du titre (badge, bouton). */
  actions?: ReactNode;
  children: ReactNode;
}

/** Carte ; avec un titre, la section est une région nommée par ce titre (repère pour les lecteurs d'écran). */
export function Card({title, actions, className, children, ...rest}: CardProps) {
  const titleId = useId();

  return (
    <section
      className={['js-card', className].filter(Boolean).join(' ')}
      aria-labelledby={title ? titleId : undefined}
      {...rest}
    >
      {title && (
        <header className="js-card-header">
          <h2 id={titleId} className="js-card-title">
            {title}
          </h2>
          {actions}
        </header>
      )}
      <div className="js-card-body">{children}</div>
    </section>
  );
}
