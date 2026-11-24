import type {ButtonHTMLAttributes, ReactNode} from 'react';
import {Icon, type IconSource} from './Icon.tsx';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  size?: 'sm';
  icon?: IconSource;
  block?: boolean;
  children?: ReactNode;
}

export function Button({
  variant = 'secondary',
  size,
  icon,
  block,
  className,
  children,
  type = 'button',
  ...rest
}: ButtonProps) {
  const classes = ['js-btn', `js-btn-${variant}`, size ? `js-btn-${size}` : '', block ? 'js-btn-block' : '', className]
    .filter(Boolean)
    .join(' ');

  return (
    <button className={classes} type={type} {...rest}>
      {icon && <Icon icon={icon} />}
      {children}
    </button>
  );
}
