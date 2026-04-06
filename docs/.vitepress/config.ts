import { defineConfig } from 'vitepress'
import { configPlugin, consoleCommandPlugin, head } from '../../../docs'

export default defineConfig({
  head,
  base: '/not-found-redirects/',
  srcDir: '.',
  title: '404 Redirects',
  description: 'Catches 404 errors, logs them, and redirects visitors based on configurable rules.',
  ignoreDeadLinks: true,

  srcExclude: [
    'node_modules/**',
    'plans/**',
  ],

  markdown: {
    config(md) {
      md.use(configPlugin)
      md.use(consoleCommandPlugin)
    },
  },

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'View on Plugin Store', link: 'https://plugins.craftcms.com/not-found-redirects' },
      { text: 'All Plugins', link: 'https://plugins.newism.com.au/', target: '_self' },
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/installation' },
          { text: 'Configuration', link: '/configuration' },
        ],
      },
      {
        text: 'Features',
        items: [
          { text: '404 Logging', link: '/404-logging' },
          { text: '404 Redirects', link: '/redirects' },
          { text: 'UI / UX', link: '/user-interface-user-experience' },
          { text: 'Dashboard Widgets', link: '/dashboard-widgets' },
          { text: 'Multi-site Support', link: '/multi-site-support' },
        ],
      },
      {
        text: 'Guides',
        items: [
          { text: 'Pattern Matching', link: '/pattern-matching' },
          { text: 'Export & Import', link: '/import-export' },
          { text: 'Permissions', link: '/permissions' },
          { text: 'Migrating from Retour', link: '/migration-from-retour' },
        ],
      },
      {
        text: 'Developers',
        items: [
          { text: 'How it works', link: '/how-it-works' },
          { text: 'Events', link: '/events' },
          { text: 'Garbage Collection', link: '/garbage-collection' },
          { text: 'Console Commands', link: '/console-commands' },
          { text: 'GraphQL', link: '/graphql' },
          { text: 'Logs', link: '/logs' },
        ],
      },
      { text: 'Support', link: '/support' },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/newism' },
      { icon: 'linkedin', link: 'https://www.linkedin.com/company/newism' },
    ],
  },
})
