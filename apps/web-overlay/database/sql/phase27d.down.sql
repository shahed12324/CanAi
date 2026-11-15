DROP FUNCTION IF EXISTS plugin_uninstall(char, char, text);
DROP FUNCTION IF EXISTS plugin_install_record(char, char, char, text, integer, text[]);
DROP FUNCTION IF EXISTS plugin_review(char, text, text);
DROP FUNCTION IF EXISTS plugin_withdraw(char, char, char);
DROP FUNCTION IF EXISTS plugin_submit(char, char, char, text, text, text, jsonb, text[]);
DROP TABLE IF EXISTS plugin_installs;
DROP TABLE IF EXISTS plugin_versions;
