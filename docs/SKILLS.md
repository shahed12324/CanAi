# Skills (v0.113.0)

`/app/skills`. A skill is a saved procedure (name, when to use, numbered steps). `/slug request` anywhere the AI chat is used expands to
the full instructions (`SkillExpander`, hooked into the web chat and `ServiceChatBridge`, which Telegram, WhatsApp and scheduled jobs use).

Learning: the model drafts a skill from one of your chats or a description (`SkillAuthor::learn`, strict JSON, placeholders for private data).
The draft is shown in a form; nothing is stored until you press Save. Improving: 👍/👎 + notes are kept (`skill_feedback`); "Suggest an improvement"
asks the model for a revised text and stores it as a *proposed* version that is only used after you approve it. Every change is a new row in
`skill_versions`; Restore copies an old version forward. Skills are personal (tenant + owner), max 50 active per person.

Paid model calls (learn, improve) go through `PaidCompletion` (reserve → call → settle/release). Running a skill is a normal chat turn.
Tests: `tests/Unit/SkillTextTest.php`. Migration `phase32a`.
