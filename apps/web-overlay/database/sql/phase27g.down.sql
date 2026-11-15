DROP FUNCTION IF EXISTS license_seats_left(char);
DROP FUNCTION IF EXISTS license_remove(char);
DROP FUNCTION IF EXISTS license_set(char, integer, timestamptz, text);
DROP TRIGGER IF EXISTS tenant_users_seat_guard ON tenant_users;
DROP FUNCTION IF EXISTS license_seat_guard();
DROP TABLE IF EXISTS licenses;
