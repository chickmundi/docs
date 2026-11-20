import { defineConfig } from 'astro/config';

export default defineConfig({
  // SITE_URL lets a preview build (e.g. nouveau.marchecheminduroy.com) use its own address.
  site: process.env.SITE_URL || 'https://marchecheminduroy.com',
  trailingSlash: 'ignore',
  build: { format: 'directory' },
});
