import {type InputHTMLAttributes, type Ref, useId} from 'react';

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string;
  error?: string | null;
  ref?: Ref<HTMLInputElement>;
}

/** Champ de saisie avec son libellé (relié par `htmlFor`) et son message d'erreur annoncé aux lecteurs d'écran. */
export function TextField({label, error, id, className, ref, ...rest}: TextFieldProps) {
  const generatedId = useId();
  const fieldId = id ?? generatedId;
  const errorId = `${fieldId}-error`;

  return (
    <div className="js-field">
      <label className="js-field-label" htmlFor={fieldId}>
        {label}
      </label>
      <input
        ref={ref}
        id={fieldId}
        className={['js-input', className].filter(Boolean).join(' ')}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        {...rest}
      />
      {error && (
        <p className="js-field-error" id={errorId}>
          {error}
        </p>
      )}
    </div>
  );
}
