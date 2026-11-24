import type {HTMLAttributes, ReactNode} from 'react';

interface CardProps extends Omit<HTMLAttributes<HTMLElement>, 'title'> {
  title?: ReactNode;
  /** Zone à droite du titre (badge, bouton). */
  actions?: ReactNode;
  children: ReactNode;
}

export function Card({title, actions, className, children, ...rest}: CardProps) {
  return (
    <section className={['js-card', className].filter(Boolean).join(' ')} {...rest}>
      {title && (
        <header className="js-card-header">
          <h2 className="js-card-title">{title}</h2>
          {actions}
        </header>
      )}
      <div className="js-card-body">{children}</div>
    </section>
  );
}
