/**
 * Configuration Prettier de jobscan-frontend (identique à celle de yoostart-front).
 *
 * @type {import('prettier').Config}
 */
const config = {
  semi: true,
  singleQuote: true,
  trailingComma: 'none',
  bracketSpacing: false,
  arrowParens: 'always',
  printWidth: 120,
  tabWidth: 2,
  useTabs: false,
  endOfLine: 'lf',
  singleAttributePerLine: false,
  objectWrap: 'preserve'
};

export default config;
