---
title: 404 Redirects
description: Catch every broken link on your Craft CMS site. Know where they come from. Fix them fast.
layout: home
hero:
    name: 404 Redirects
    text: For Craft CMS
    icon: /logo.svg
    tagline: Catch every broken link.<br>Know where they come from.<br>Fix them fast.
    image:
        src: /hero.png
        alt: 404 Redirects plugin showing 404s listing in Craft CMS control panel
    actions:
        - text: Get Started
          link: ./installation
        - text: View on Plugin Store
          link: https://plugins.craftcms.com/not-found-redirects
          theme: alt
features:
    - title: 404 Monitoring
      details: Every 404 is captured at the end of the request lifecycle, after Craft's routing has already failed. Hit counts, timestamps, and handled status give you full visibility into broken links.
      link: ./404-logging
      icon: 🔍
    - title: 404 Redirects
      details: Resolve captured 404s with exact match, named parameters, or pure regex. Supports entry destinations, 410 Gone, and fast 404 text responses for bot traffic.
      link: ./redirects
      icon: 🔀
    - title: Native Craft 5.x UI
      details: Built entirely with native Craft components. No custom widgets, no third-party frameworks. Fast, familiar, and consistent with the rest of your control panel.
      link: ./user-interface-user-experience
      icon: ✨
    - title: Referrer Tracking
      details: Know exactly where broken links come from. Multiple referrers are tracked per 404 with individual hit counts, so you can prioritise fixes based on traffic source.
      link: ./404-logging#referrer-tracking
      icon: 🔗
    - title: Dashboard Widgets
      details: Three widget types (latest/top 404s table, trend chart, and handled/unhandled coverage chart) so you can monitor 404 activity from your dashboard.
      link: ./dashboard-widgets
      icon: 📊
    - title: Auto-Redirects
      details: When editors move entries or update URLs, redirects are created automatically with chain flattening. No manual work needed.
      link: ./redirects#auto-redirects-on-uri-change
      icon: ⚡
    - title: Entry Sidebar
      details: View and manage all incoming redirects directly from the entry editor sidebar. See what old URLs point at your content without leaving the page.
      link: ./redirects#entry-sidebar
      icon: 📋
    - title: Export, Import Data
      details: Export 404s and redirects as CSV or JSON at any time. Migrate from Retour via console command or control panel. Your data is always accessible.
      link: ./import-export
      icon: 📦
    - title: Developer API
      details: GraphQL queries, comprehensive console commands, and configurable pipeline events for custom 404 handling logic.
      link: ./how-it-works
      icon: 🛠️
---
