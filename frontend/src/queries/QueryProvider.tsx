import {lazy, type ReactNode, Suspense} from 'react';
import {QueryClientProvider} from '@tanstack/react-query';
import {queryClient} from '@/queries/queryClient.ts';

// Chargement paresseux : les Devtools ne doivent jamais alourdir le bundle de production.
// `import.meta.env.DEV` est éliminé statiquement par Vite en production.
const ReactQueryDevtools = lazy(() =>
  import('@tanstack/react-query-devtools').then((module) => ({
    default: module.ReactQueryDevtools
  }))
);

export function QueryProvider({children}: {children: ReactNode}) {
  return (
    <QueryClientProvider client={queryClient}>
      {children}
      {import.meta.env.DEV && (
        <Suspense fallback={null}>
          <ReactQueryDevtools initialIsOpen={false} buttonPosition="bottom-left" />
        </Suspense>
      )}
    </QueryClientProvider>
  );
}
