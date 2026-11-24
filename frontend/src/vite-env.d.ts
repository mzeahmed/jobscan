/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Base de l'API Symfony (même origine par défaut : `/api/v1`). */
  readonly VITE_SYMFONY_API_BASE_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
