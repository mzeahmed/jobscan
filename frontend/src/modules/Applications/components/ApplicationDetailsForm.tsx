import {type SubmitEvent} from 'react';
import {useTranslation} from 'react-i18next';
import {fieldErrors} from '@/api/apiErrors.ts';
import {Button} from '@/design-system/components/Button.tsx';
import {InlineAlert} from '@/design-system/components/InlineAlert.tsx';
import {SelectField} from '@/design-system/components/SelectField.tsx';
import {TextField} from '@/design-system/components/TextField.tsx';
import {calendarDay, formatDateTime} from '@/i18n/format.ts';
import type {
  ApplicationDetail,
  ApplicationOptions,
  ApplicationPatch
} from '@/modules/Applications/domain/application.ts';
import {useUpdateApplication} from '@/modules/Applications/hooks/useApplicationActions.ts';

interface ApplicationDetailsFormProps {
  application: ApplicationDetail;
  options: ApplicationOptions;
}

/** Texte vide = champ effacé (`null`) : c'est le contrat de `PATCH` (docs/API.md). */
const orNull = (value: FormDataEntryValue | null): string | null => {
  const text = String(value ?? '').trim();

  return text === '' ? null : text;
};

/**
 * Informations saisies par l'utilisateur. Le formulaire est repris à zéro (clé) quand l'API renvoie la candidature
 * enregistrée, et garde la saisie en cas d'erreur pour qu'on la corrige sans la retaper. Le texte est affiché et
 * envoyé tel quel : notes et message sont des textes de l'utilisateur, jamais interprétés.
 */
export function ApplicationDetailsForm({application, options}: ApplicationDetailsFormProps) {
  const {t} = useTranslation();
  const update = useUpdateApplication(application.id);
  const errors = fieldErrors(update.error);
  const none = {value: '', label: t('applications.detail.noValue')};

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const patch: ApplicationPatch = {
      recruiter_name: orNull(data.get('recruiter_name')),
      recruiter_contact: orNull(data.get('recruiter_contact')),
      channel: orNull(data.get('channel')),
      follow_up_at: orNull(data.get('follow_up_at')),
      message_sent: orNull(data.get('message_sent')),
      notes: orNull(data.get('notes')),
      // Sans profil candidat, aucun choix de CV n'est proposé : on ne touche pas au CV déjà associé.
      ...(options.cv_versions.length > 0 ? {cv: orNull(data.get('cv'))} : {})
    };

    update.mutate(patch);
  }

  return (
    <form
      key={JSON.stringify([
        application.id,
        application.status_changed_at,
        application.recruiter_name,
        application.notes,
        application.follow_up_at,
        application.channel,
        application.cv_version,
        application.message_sent,
        application.recruiter_contact
      ])}
      className="js-form"
      onSubmit={handleSubmit}
    >
      <div className="js-form-grid">
        <TextField
          name="recruiter_name"
          label={t('applications.detail.recruiterName')}
          maxLength={255}
          defaultValue={application.recruiter_name ?? ''}
          error={errors.recruiter_name}
        />
        <TextField
          name="recruiter_contact"
          label={t('applications.detail.recruiterContact')}
          maxLength={255}
          defaultValue={application.recruiter_contact ?? ''}
          error={errors.recruiter_contact}
        />
        <SelectField
          name="channel"
          label={t('applications.detail.channel')}
          defaultValue={application.channel?.value ?? ''}
          options={[none, ...options.channels]}
        />
        {errors.channel && <p className="js-field-error">{errors.channel}</p>}
        {options.cv_versions.length > 0 && (
          <SelectField
            name="cv"
            label={t('applications.detail.cv')}
            defaultValue={application.cv_version?.id ?? ''}
            options={[none, ...options.cv_versions.map((cv) => ({value: cv.id, label: cv.label}))]}
          />
        )}
        {errors.cv && <p className="js-field-error">{errors.cv}</p>}
        <TextField
          name="follow_up_at"
          type="date"
          label={t('applications.detail.followUpAt')}
          defaultValue={calendarDay(application.follow_up_at)}
          error={errors.follow_up_at}
        />
      </div>

      {application.follow_up_due && <p className="js-form-hint">⏰ {t('applications.detail.followUpDue')}.</p>}
      {application.follow_up_reminded_at && (
        <p className="js-form-hint">
          {t('applications.detail.followUpReminded', {date: formatDateTime(application.follow_up_reminded_at)})}
        </p>
      )}

      <div className="js-field">
        <label className="js-field-label" htmlFor="message_sent">
          {t('applications.detail.messageSent')}
        </label>
        <textarea
          id="message_sent"
          name="message_sent"
          className="js-input js-textarea"
          rows={6}
          maxLength={20000}
          defaultValue={application.message_sent ?? ''}
          aria-invalid={errors.message_sent ? true : undefined}
        />
        {errors.message_sent && <p className="js-field-error">{errors.message_sent}</p>}
      </div>

      <div className="js-field">
        <label className="js-field-label" htmlFor="notes">
          {t('applications.detail.notes')}
        </label>
        <textarea
          id="notes"
          name="notes"
          className="js-input js-textarea"
          rows={4}
          maxLength={20000}
          defaultValue={application.notes ?? ''}
          aria-invalid={errors.notes ? true : undefined}
        />
        {errors.notes && <p className="js-field-error">{errors.notes}</p>}
      </div>

      {update.isError && !Object.keys(errors).length && (
        <InlineAlert tone="danger">{t('applications.detail.saveFailed', {message: update.error.message})}</InlineAlert>
      )}
      {update.isSuccess && <InlineAlert tone="success">{update.data.message}</InlineAlert>}

      <div>
        <Button type="submit" variant="primary" size="sm" disabled={update.isPending}>
          {update.isPending ? t('applications.detail.saving') : t('applications.detail.save')}
        </Button>
      </div>
    </form>
  );
}
