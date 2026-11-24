import type {HTMLAttributes, ReactNode} from 'react';

interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info';
  children: ReactNode;
}

export function Badge({tone = 'neutral', className, children, ...rest}: BadgeProps) {
  const toneClass = tone === 'neutral' ? '' : `js-badge-${tone}`;

  return (
    <span className={['js-badge', toneClass, className].filter(Boolean).join(' ')} {...rest}>
      {children}
    </span>
  );
}
