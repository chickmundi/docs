import { defineConfig } from 'astro/config';

export default defineConfig({
  site: 'https://marchecheminduroy.com',
  trailingSlash: 'ignore',
  build: { format: 'directory' },
});
