import {type SelectHTMLAttributes, useId} from 'react';

export interface SelectOption {
  value: string;
  label: string;
}

interface SelectFieldProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label: string;
  options: readonly SelectOption[];
}

/** Liste déroulante avec son libellé relié par `htmlFor`. */
export function SelectField({label, options, id, className, ...rest}: SelectFieldProps) {
  const generatedId = useId();
  const fieldId = id ?? generatedId;

  return (
    <div className="js-field">
      <label className="js-field-label" htmlFor={fieldId}>
        {label}
      </label>
      <select id={fieldId} className={['js-input', className].filter(Boolean).join(' ')} {...rest}>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
}
