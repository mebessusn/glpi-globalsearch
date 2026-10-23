# Global Search (GLPI 11)

Replaces the top search bar with one search across:

- Tickets, Problems, Changes: title, description, followups, tasks
- Knowledge base: article title and content

Results are grouped by type, newest first, with the matched field and a highlighted snippet.

## Permissions

Results go through GLPI's own `canViewItem()` checks for each item. That means the plugin respects:

- entities
- "see all / see my group's / see my own" ticket rights
- private followups and tasks
- KB visibility targets

Only users with the standard (central) interface get the plugin. Self-service users keep the normal search.

## Install

1. Copy this folder to `<glpi>/plugins/globalsearch`. The folder name must be `globalsearch`.
2. Run `bin/console plugin:install globalsearch` and then `bin/console plugin:activate globalsearch`, or use Setup → Plugins.
3. Hard-refresh the browser.

## Search rules

- The query is split on spaces, and every word must appear in the item.
- Words shorter than 2 characters are ignored. At most 6 words are used.
- Hostnames, IPs and paths are matched as typed. For example, `web-01.corp.local` works.
- It searches with LIKE, not the MySQL full-text index, so there is no minimum word length or stopword problem.
- Limits: 150 candidate rows per table and 30 results per section.

## Performance

LIKE '%term%' scans the whole table, which is fine up to a few hundred thousand tickets and followups. If it gets slow, the next step is a FULLTEXT index plus MATCH ... AGAINST, or an external index.

## License

GPLv3 or later. See `LICENSE`.
