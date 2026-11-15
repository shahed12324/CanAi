DROP FUNCTION IF EXISTS channel_account_token_for_admin(char), channel_events_purge(integer), channel_account_health(char, text), channel_account_authenticate(char, char),
  channel_account_for_send(char), channel_link_redeem(char, char, varchar, varchar, varchar, char), channel_resolve(char, varchar),
  channel_event_finish(char, bigint, text, text, char, char), channel_event_claim(char, bigint, text);
DROP TABLE IF EXISTS channel_events, channel_links, channel_link_codes, channel_accounts CASCADE;
