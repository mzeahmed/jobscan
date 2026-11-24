import type {ReactNode} from 'react';
import {Icon, type IconSource} from './Icon.tsx';

interface EmptyStateProps {
  icon: IconSource;
  title: string;
  text?: string;
  action?: ReactNode;
}

export function EmptyState({icon, title, text, action}: EmptyStateProps) {
  return (
    <div className="js-empty-state">
      <div className="js-empty-state-icon">
        <Icon icon={icon} />
      </div>
      <h2 className="js-empty-state-title">{title}</h2>
      {text && <p className="js-empty-state-text">{text}</p>}
      {action && <div className="js-empty-state-action">{action}</div>}
    </div>
  );
}
