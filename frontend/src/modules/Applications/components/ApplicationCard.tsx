import {Link} from 'react-router-dom';
import {useTranslation} from 'react-i18next';
import {faArrowUpRightFromSquare} from '@fortawesome/free-solid-svg-icons';
import {Badge} from '@/design-system/components/Badge.tsx';
import {Icon} from '@/design-system/components/Icon.tsx';
import {formatCalendarDay, formatDate} from '@/i18n/format.ts';
import {StatusBadge} from '@/modules/Applications/components/StatusBadge.tsx';
import type {ApplicationSummary} from '@/modules/Applications/domain/application.ts';

/** Une candidature en liste. Titre, entreprise et lieu viennent d'une annonce : rendus comme du texte. */
export function ApplicationCard({application}: {application: ApplicationSummary}) {
  const {t} = useTranslation();
  const {job} = application;
  const title = job.title?.trim() || t('applications.card.untitled');
  const where = [job.company?.trim() || t('applications.card.unknownCompany'), job.location?.trim()].filter(Boolean);
  const facts = [
    application.applied_at && t('applications.card.appliedOn', {date: formatDate(application.applied_at)}),
    application.channel?.label,
    application.cv_version &&
      t('applications.card.cv', {label: application.cv_version.label ?? application.cv_version.id}),
    application.recruiter_name
  ].filter(Boolean);

  return (
    <li>
      <article className="js-application">
        <div className="js-application-main">
          <h2 className="js-application-title">
            <Link to={`/applications/${application.id}`}>{title}</Link>
          </h2>
          <p className="js-application-meta">{where.join(' · ')}</p>
          {facts.length > 0 && <p className="js-application-facts">{facts.join(' · ')}</p>}
        </div>

        <div className="js-application-side">
          <StatusBadge status={application.status} />
          {application.follow_up_at && (
            <span className="js-application-followup">
              {t('applications.card.followUp', {date: formatCalendarDay(application.follow_up_at)})}
            </span>
          )}
          {application.follow_up_due && <Badge tone="warning">{t('applications.card.followUpDue')}</Badge>}
          {job.url && (
            <a
              className="js-application-offer"
              href={job.url}
              target="_blank"
              rel="noopener noreferrer nofollow"
              title={t('applications.card.openOffer')}
              aria-label={`${t('applications.card.openOffer')} : ${title}`}
            >
              <Icon icon={faArrowUpRightFromSquare} />
            </a>
          )}
        </div>
      </article>
    </li>
  );
}
