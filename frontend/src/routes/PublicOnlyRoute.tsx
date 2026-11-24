import {Navigate, Outlet, useLocation} from 'react-router-dom';
import {useAuth} from '@/auth/useAuth.ts';
import {FullPageLoader} from '@/layout/components/FullPageLoader.tsx';
import {ServiceUnavailable} from '@/layout/components/ServiceUnavailable.tsx';

/** Adresse de retour posée par `ProtectedRoute` : acceptée seulement si c'est un chemin interne de l'application. */
function returnPath(state: unknown): string {
  const from = typeof state === 'object' && state !== null && 'from' in state ? (state as {from: unknown}).from : null;

  return typeof from === 'string' && from.startsWith('/') && !from.startsWith('//') && from !== '/login' ? from : '/';
}

/** Routes réservées aux visiteurs (connexion) : un utilisateur déjà connecté est renvoyé là d'où il venait. */
export function PublicOnlyRoute() {
  const {user, loading, unavailable} = useAuth();
  const location = useLocation();

  if (loading) {
    return <FullPageLoader />;
  }

  if (unavailable) {
    return <ServiceUnavailable />;
  }

  if (user) {
    return <Navigate to={returnPath(location.state)} replace />;
  }

  return <Outlet />;
}
