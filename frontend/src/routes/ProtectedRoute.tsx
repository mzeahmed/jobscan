import {Navigate, Outlet, useLocation} from 'react-router-dom';
import {useAuth} from '@/auth/useAuth.ts';
import {FullPageLoader} from '@/layout/components/FullPageLoader.tsx';
import {ServiceUnavailable} from '@/layout/components/ServiceUnavailable.tsx';

/**
 * Réserve les routes enfants à un utilisateur connecté. Un contrôle côté navigateur n'est qu'un confort :
 * l'autorisation réelle est celle de l'API, qui refuse toute requête sans jeton valide.
 */
export function ProtectedRoute() {
  const {user, loading, unavailable} = useAuth();
  const location = useLocation();

  if (loading) {
    return <FullPageLoader />;
  }

  if (unavailable) {
    return <ServiceUnavailable />;
  }

  if (!user) {
    return <Navigate to="/login" replace state={{from: location.pathname}} />;
  }

  return <Outlet />;
}
