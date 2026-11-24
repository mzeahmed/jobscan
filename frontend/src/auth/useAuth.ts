import {useContext} from 'react';
import {AuthContext, type AuthContextValue} from '@/auth/AuthContext.ts';

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth doit être utilisé dans un AuthProvider.');
  }

  return context;
}
