# WhatsApp channel (text + voice commands)

Lets a CANAI user message a WhatsApp number (text or voice note) and have CANAI answer, or run a workflow by voice
("run <workflow name>"). Uses the official **WhatsApp Cloud API (Meta)**. Same gateway as the Telegram bot.

## What you need (outside this code)
1. A Meta developer account, a Business app with the **WhatsApp** product, and a phone number registered in it.
2. From Meta: **Phone number ID**, a **permanent access token** (System User), and the **App secret** (App settings → Basic).

## Setup
1. Back up first: `selfhost/backup.sh`. Then update and run `./install.sh` (it runs migration `2026_12_27_100000_whatsapp_channel`, which only widens two CHECK constraints and adds one read function).
2. Admin → **Chat channels** → *Add a WhatsApp number*: name, phone number ID, (display number), access token, app secret.
3. The row shows a **Callback URL** and **Verify token**. In Meta → WhatsApp → Configuration → Webhook paste both, press *Verify and save*, subscribe to the **messages** field.
4. Press **Activate** on the row.
5. Each user: sidebar → **WhatsApp** → *Get code*, then send `/link CODE` to the number from their own WhatsApp.
6. Voice needs a speech-to-text model enabled (Admin → Providers/AI Models, category for listening) and credits; the same wallet pricing as the in-app voice feature applies.

## How it behaves
- Only linked numbers get answers; unknown senders are ignored (except `/start`/`/link` how-to).
- Voice note → transcribed (billed in credits) → echoed back as "🎤 …" → if it matches a workflow command it is run, otherwise it goes to the AI chat.
- Webhook is signed with the app secret (`X-Hub-Signature-256`); a bad signature gets 403. Duplicates are dropped by message id.
- Meta only allows free-form replies within 24 hours of the user's last message; a quiet number must message first.

## Not verified here
Written and unit-tested without a real Postgres or Meta account (normalizer, signature, voice path with fakes). First live test: send "hi" from a linked phone and watch Admin → Chat channels → Recent events.
