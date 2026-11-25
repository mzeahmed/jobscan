import {type SubmitEvent, useState} from 'react';
import {useTranslation} from 'react-i18next';
import {fieldErrors} from '@/api/apiErrors.ts';
import {Button} from '@/design-system/components/Button.tsx';
import {InlineAlert} from '@/design-system/components/InlineAlert.tsx';
import {SelectField} from '@/design-system/components/SelectField.tsx';
import {TextField} from '@/design-system/components/TextField.tsx';
import {todayInput} from '@/i18n/format.ts';
import type {ApplicationDetail, ApplicationStatusValue} from '@/modules/Applications/domain/application.ts';
import {useChangeStatus} from '@/modules/Applications/hooks/useApplicationActions.ts';

/** Changement de statut déclaré par l'utilisateur. Seules les transitions que l'API autorise sont proposées. */
export function StatusChangeForm({application}: {application: ApplicationDetail}) {
  const {t} = useTranslation();
  const change = useChangeStatus(application.id);
  const [formKey, setFormKey] = useState(0);
  const errors = fieldErrors(change.error);

  if (application.allowed_transitions.length === 0) {
    return <p className="js-final-note">{t('applications.detail.finalStatus', {status: application.status.label})}</p>;
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const at = String(data.get('at') ?? '');
    const note = String(data.get('note') ?? '').trim();

    change.mutate(
      {
        status: String(data.get('status')) as ApplicationStatusValue,
        ...(at !== '' ? {at} : {}),
        ...(note !== '' ? {note} : {})
      },
      // Formulaire remis à zéro après un succès seulement : une erreur garde la saisie à corriger.
      {onSuccess: () => setFormKey((key) => key + 1)}
    );
  }

  return (
    <form key={formKey} className="js-form" onSubmit={handleSubmit}>
      <p className="js-form-hint">{t('applications.detail.statusHint')}</p>

      <SelectField
        name="status"
        label={t('applications.detail.newStatus')}
        required
        defaultValue=""
        options={[
          {value: '', label: t('applications.detail.choose')},
          ...application.allowed_transitions.map((status) => ({value: status.value, label: status.label}))
        ]}
      />
      {errors.status && <p className="js-field-error">{errors.status}</p>}

      <TextField
        name="at"
        type="date"
        label={t('applications.detail.statusDate')}
        max={todayInput()}
        error={errors.at}
      />
      <TextField name="note" label={t('applications.detail.statusNote')} maxLength={500} error={errors.note} />

      {change.isError && !Object.keys(errors).length && <InlineAlert tone="danger">{change.error.message}</InlineAlert>}
      {change.isSuccess && <InlineAlert tone="success">{change.data.message}</InlineAlert>}

      <div>
        <Button type="submit" variant="primary" size="sm" disabled={change.isPending}>
          {change.isPending ? t('applications.detail.statusSubmitting') : t('applications.detail.statusSubmit')}
        </Button>
      </div>
    </form>
  );
}
