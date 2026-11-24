import {BrowserRouter, Route, Routes} from 'react-router-dom';
import {AuthProvider} from '@/auth/AuthProvider.tsx';
import {AppShell} from '@/layout/components/AppShell.tsx';
import {ErrorBoundary} from '@/layout/components/ErrorBoundary.tsx';
import {ModuleNotBuiltPage} from '@/layout/pages/ModuleNotBuiltPage.tsx';
import {NotFoundPage} from '@/layout/pages/NotFoundPage.tsx';
import {Login} from '@/modules/Auth/Login.tsx';
import {HomePage} from '@/modules/System/HomePage.tsx';
import {QueryProvider} from '@/queries/QueryProvider.tsx';
import {ProtectedRoute} from '@/routes/ProtectedRoute.tsx';
import {PublicOnlyRoute} from '@/routes/PublicOnlyRoute.tsx';

function App() {
  return (
    <ErrorBoundary>
      <QueryProvider>
        <AuthProvider>
          <BrowserRouter>
            <Routes>
              <Route element={<PublicOnlyRoute />}>
                <Route path="/login" element={<Login />} />
              </Route>
              <Route element={<ProtectedRoute />}>
                <Route element={<AppShell />}>
                  <Route index element={<HomePage />} />
                  {/* Écrans migrés aux lots Front 1 à 3. */}
                  <Route path="/jobs" element={<ModuleNotBuiltPage />} />
                  <Route path="/applications" element={<ModuleNotBuiltPage />} />
                  <Route path="/analytics" element={<ModuleNotBuiltPage />} />
                  <Route path="*" element={<NotFoundPage />} />
                </Route>
              </Route>
            </Routes>
          </BrowserRouter>
        </AuthProvider>
      </QueryProvider>
    </ErrorBoundary>
  );
}

export default App;
