# Scheduled AI jobs (v0.112.0)

`/app/jobs`: a prompt plus a schedule in Bangla or English. `jobs:run-due` (every minute) claims due jobs on the admin connection with
`FOR UPDATE SKIP LOCKED`, moves `next_run_at` forward first (at most once per scheduled time), then runs each as a chat turn for the owner through
`ChatBridge` (same memory, credits and limits as the chat) and delivers the answer to `inbox_notifications` and the owner's linked Telegram/WhatsApp chats.

Rules: min interval 15 minutes, 20 jobs per user, 10 jobs per minute per server, 5 failures in a row pause the job (failure notices go to the inbox).
The schedule parser is rule based and refuses what it cannot read. Time zone defaults to Asia/Dhaka.
Tests: `tests/Unit/ScheduleTest.php`. Migration `phase31a` (back up first).
