# Shop platform & admin AI assistant (v0.109.0)

User side: Settings → Shop profile (`/app/settings/shop`), Support (`/app/support`).
Admin side: Dashboard stats, Support tickets, Free trials, Usage, Announcements, AI assistant (`/admin/assistant`).

Assistant examples: `how many shops`, `revenue this month`, `open tickets`, `running trials`, `top shops by usage`,
`find shop acme`; commands (confirm required): `suspend shop acme because unpaid`, `activate shop acme`,
`start trial for acme 14 days on pro`, `extend trial acme by 7 days`, `announce: Maintenance tonight`.
Rule-based parser (`App\Admin\AssistantParser`); each intent checks the admin's permission; changes are audited.
Migration `phase30a` adds shop_profiles, support_tickets, support_messages, admin_broadcasts — back up first.
