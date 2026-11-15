DROP FUNCTION IF EXISTS pc_metrics_purge(integer), pc_metrics_add(char, char, numeric, numeric, numeric, numeric, numeric, numeric, boolean),
  pc_action_report(char, char, char, boolean, text), pc_action_next(char, char), pc_action_decide(char, char, char, text),
  pc_action_request(char, char, char, text, jsonb), pc_policy_save(char, char, char, text[], text[], text[], text[], jsonb),
  pc_device_revoke(char, char, char), pc_agent_lookup(text), pc_pair_redeem(text, text), pc_pair_create(char, char, text, text, text);
DROP TABLE IF EXISTS pc_metrics, pc_actions, pc_policies, pc_pairings, pc_devices;
DROP FUNCTION IF EXISTS pc_actions_guard(), pc_target_ok(text, jsonb, text[], text[], text[], text[]);
DROP FUNCTION IF EXISTS pc_kind_risk(text);
