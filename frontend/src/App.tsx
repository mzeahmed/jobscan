import {BrowserRouter, Route, Routes} from 'react-router-dom';
import {AuthProvider} from '@/auth/AuthProvider.tsx';
import {AppShell} from '@/layout/components/AppShell.tsx';
import {ErrorBoundary} from '@/layout/components/ErrorBoundary.tsx';
import {NotFoundPage} from '@/layout/pages/NotFoundPage.tsx';
import {AnalyticsPage} from '@/modules/Analytics/AnalyticsPage.tsx';
import {Login} from '@/modules/Auth/Login.tsx';
import {ApplicationDetailPage} from '@/modules/Applications/ApplicationDetailPage.tsx';
import {ApplicationsPage} from '@/modules/Applications/ApplicationsPage.tsx';
import {JobsPage} from '@/modules/Jobs/JobsPage.tsx';
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
                  <Route path="/jobs" element={<JobsPage />} />
                  <Route path="/applications" element={<ApplicationsPage />} />
                  <Route path="/applications/:id" element={<ApplicationDetailPage />} />
                  <Route path="/analytics" element={<AnalyticsPage />} />
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
