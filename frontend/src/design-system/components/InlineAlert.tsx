import type {HTMLAttributes, ReactNode} from 'react';

interface InlineAlertProps extends HTMLAttributes<HTMLDivElement> {
  tone?: 'info' | 'success' | 'warning' | 'danger';
  children: ReactNode;
}

export function InlineAlert({tone = 'info', className, children, ...rest}: InlineAlertProps) {
  return (
    <div
      className={['js-inline-alert', `js-inline-alert-${tone}`, className].filter(Boolean).join(' ')}
      role={tone === 'danger' ? 'alert' : 'status'}
      {...rest}
    >
      {children}
    </div>
  );
}
