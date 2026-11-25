import {Badge} from '@/design-system/components/Badge.tsx';
import type {ApplicationStatusValue, StatusRef} from '@/modules/Applications/domain/application.ts';

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

const TONES: Record<ApplicationStatusValue, Tone> = {
  draft: 'neutral',
  applied: 'info',
  interview: 'info',
  technical_test: 'info',
  offer: 'success',
  accepted: 'success',
  rejected: 'danger',
  withdrawn: 'neutral',
  no_response: 'warning'
};

/** Pastille de statut : la couleur renforce le libellé, elle ne le remplace jamais. */
export function StatusBadge({status}: {status: StatusRef}) {
  return <Badge tone={TONES[status.value] ?? 'neutral'}>{status.label}</Badge>;
}
