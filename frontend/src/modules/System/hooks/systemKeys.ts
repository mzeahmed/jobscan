export const systemKeys = {
  all: ['system'] as const,
  apiInfo: () => [...systemKeys.all, 'api-info'] as const
};
