DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM pc_plans) THEN RAISE EXCEPTION 'pc_plans holds rows (audit trail); cannot roll back' USING ERRCODE = '22023'; END IF;
END $$;
DROP FUNCTION IF EXISTS mobile_pc_plan_step_approve(char, char, char, text), pc_plan_report(char, char, char, text, text), pc_plan_next(char, char), pc_plan_step_approve(char, char, char, text),
  pc_plan_decide(char, char, char, text), pc_plan_submit(char, char, char, text, jsonb), pc_hmac_sha256(text, text);
DROP TABLE IF EXISTS pc_plan_step_approvals, pc_plans;
DROP FUNCTION IF EXISTS pc_plans_guard(), pc_plan_approvals_guard();
