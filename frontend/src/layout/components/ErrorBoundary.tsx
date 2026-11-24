import {Component, type ErrorInfo, type ReactNode} from 'react';
import i18n from '@/i18n/i18n.ts';

interface ErrorBoundaryProps {
  children: ReactNode;
}

interface ErrorBoundaryState {
  failed: boolean;
}

/**
 * Dernier filet : une erreur de rendu affiche un message et un bouton de rechargement au lieu d'une page blanche.
 * Les erreurs attendues (API, formulaire) sont traitées là où elles se produisent, pas ici.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = {failed: false};

  static getDerivedStateFromError(): ErrorBoundaryState {
    return {failed: true};
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('Erreur de rendu', error, info.componentStack);
  }

  render() {
    if (!this.state.failed) {
      return this.props.children;
    }

    return (
      <div className="js-centered" role="alert">
        <div className="js-auth-card">
          <p>{i18n.t('errors.unexpected')}</p>
          <button className="js-btn js-btn-primary" type="button" onClick={() => window.location.reload()}>
            {i18n.t('errors.reload')}
          </button>
        </div>
      </div>
    );
  }
}
