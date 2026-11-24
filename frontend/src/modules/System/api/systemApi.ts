import {symfonyClient} from '@/api/symfonyClient.ts';
import type {ApiInfo} from '@/modules/System/domain/apiInfo.ts';

/** Racine de l'API : publique, elle sert à vérifier que le front joint bien Symfony. */
export function getApiInfo(signal?: AbortSignal): Promise<ApiInfo> {
  return symfonyClient.fetch('/', null, {signal});
}
