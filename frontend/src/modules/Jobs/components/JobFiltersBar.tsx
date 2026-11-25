import {type SubmitEvent} from 'react';
import {useTranslation} from 'react-i18next';
import {Button} from '@/design-system/components/Button.tsx';
import {SelectField, type SelectOption} from '@/design-system/components/SelectField.tsx';
import {TextField} from '@/design-system/components/TextField.tsx';
import {
  activeFilterCount,
  contractValues,
  defaultFilters,
  type JobFilters,
  seniorityValues
} from '@/modules/Jobs/domain/jobFilters.ts';

interface JobFiltersBarProps {
  filters: JobFilters;
  sources: readonly string[];
  onApply: (filters: JobFilters) => void;
}

/**
 * Formulaire de filtres. Les champs ne sont lus qu'à l'envoi (bouton « Filtrer » ou Entrée) : on ne lance pas une
 * requête à chaque frappe. La clé du formulaire suit les filtres appliqués, ce qui le réinitialise quand l'adresse change.
 */
export function JobFiltersBar({filters, sources, onApply}: JobFiltersBarProps) {
  const {t} = useTranslation();
  const any = (label: string): SelectOption => ({value: '', label});
  const yesNo = [
    any(t('jobs.filters.all')),
    {value: 'yes', label: t('jobs.options.yes')},
    {value: 'no', label: t('jobs.options.no')}
  ];
  // Une source déjà filtrée reste proposée même si la liste des sources n'est pas encore (re)chargée.
  const sourceNames =
    filters.source !== '' && !sources.includes(filters.source) ? [filters.source, ...sources] : sources;

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const read = (name: string) => String(data.get(name) ?? '');

    // Toute valeur est revalidée par `parseFilters` à la relecture de l'adresse : ici on ne fait que transmettre.
    onApply({
      ...defaultFilters,
      location: read('location').trim(),
      remote: read('remote') as JobFilters['remote'],
      contract: read('contract'),
      seniority: read('seniority'),
      freelance: read('freelance') as JobFilters['freelance'],
      minScore: read('minScore').trim(),
      source: read('source'),
      age: read('age') as JobFilters['age'],
      notified: read('notified') as JobFilters['notified'],
      sort: read('sort') as JobFilters['sort'],
      state: read('state') as JobFilters['state'],
      page: 1
    });
  }

  return (
    <form
      key={JSON.stringify(filters)}
      className="js-filters"
      onSubmit={handleSubmit}
      aria-label={t('jobs.filters.label')}
    >
      <TextField
        name="location"
        label={t('jobs.filters.location')}
        placeholder={t('jobs.filters.locationPlaceholder')}
        type="search"
        autoComplete="off"
        maxLength={100}
        defaultValue={filters.location}
      />
      <SelectField
        name="remote"
        label={t('jobs.filters.remote')}
        defaultValue={filters.remote}
        options={[
          any(t('jobs.filters.all')),
          {value: 'remote', label: t('jobs.options.remote')},
          {value: 'onsite', label: t('jobs.options.onsite')}
        ]}
      />
      <SelectField
        name="contract"
        label={t('jobs.filters.contract')}
        defaultValue={filters.contract}
        options={[
          any(t('jobs.filters.all')),
          ...contractValues.map((value) => ({value, label: t(`jobs.contract.${value}`)}))
        ]}
      />
      <SelectField
        name="seniority"
        label={t('jobs.filters.seniority')}
        defaultValue={filters.seniority}
        options={[
          any(t('jobs.filters.all')),
          ...seniorityValues.map((value) => ({value, label: t(`jobs.seniority.${value}`)}))
        ]}
      />
      <SelectField
        name="freelance"
        label={t('jobs.filters.freelance')}
        defaultValue={filters.freelance}
        options={yesNo}
      />
      <TextField
        name="minScore"
        label={t('jobs.filters.minScore')}
        type="number"
        min={0}
        max={100}
        inputMode="numeric"
        placeholder="0"
        defaultValue={filters.minScore}
      />
      <SelectField
        name="source"
        label={t('jobs.filters.source')}
        defaultValue={filters.source}
        options={[any(t('jobs.filters.all')), ...sourceNames.map((value) => ({value, label: value}))]}
      />
      <SelectField
        name="age"
        label={t('jobs.filters.age')}
        defaultValue={filters.age}
        options={[
          any(t('jobs.filters.all')),
          {value: '1', label: t('jobs.options.age1')},
          {value: '7', label: t('jobs.options.age7')},
          {value: '30', label: t('jobs.options.age30')}
        ]}
      />
      <SelectField
        name="notified"
        label={t('jobs.filters.notified')}
        defaultValue={filters.notified}
        options={[
          any(t('jobs.filters.all')),
          {value: 'yes', label: t('jobs.options.notifiedYes')},
          {value: 'no', label: t('jobs.options.notifiedNo')}
        ]}
      />
      <SelectField
        name="state"
        label={t('jobs.filters.state')}
        defaultValue={filters.state}
        options={[
          {value: '', label: t('jobs.options.stateActive')},
          {value: 'saved', label: t('jobs.options.stateSaved')},
          {value: 'ignored', label: t('jobs.options.stateIgnored')},
          {value: 'archived', label: t('jobs.options.stateArchived')},
          {value: 'all', label: t('jobs.options.stateAll')}
        ]}
      />
      <SelectField
        name="sort"
        label={t('jobs.filters.sort')}
        defaultValue={filters.sort}
        options={[
          {value: 'date', label: t('jobs.options.sortDate')},
          {value: 'score', label: t('jobs.options.sortScore')}
        ]}
      />

      <div className="js-filters-actions">
        <Button type="submit" variant="primary" size="sm">
          {t('jobs.filters.apply')}
        </Button>
        {activeFilterCount(filters) > 0 && (
          <Button size="sm" variant="ghost" onClick={() => onApply({...defaultFilters, sort: filters.sort})}>
            {t('jobs.filters.reset')}
          </Button>
        )}
      </div>
    </form>
  );
}
