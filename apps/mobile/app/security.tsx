import * as LocalAuthentication from 'expo-local-authentication';
import { useEffect, useState } from 'react';
import { Switch } from 'react-native';
import { ApiError } from '@/api/client';
import { Btn, Card, Field, H, Notice, P, Row, Screen } from '@/components/ui';
import { pinProblem } from '@/lib/pin';
import { store } from '@/lib/storage';
import { useSession } from '@/state/session';
import { useAskPin } from '@/state/stepup';

export default function Security() {
  const { api } = useSession();
  const ask = useAskPin();
  const [password, setPassword] = useState('');
  const [pin, setPin] = useState('');
  const [again, setAgain] = useState('');
  const [err, setErr] = useState<unknown>(null);
  const [ok, setOk] = useState('');
  const [bio, setBio] = useState(false);
  const [canBio, setCanBio] = useState(false);

  useEffect(() => {
    (async () => {
      setBio(await store.bioEnabled());
      setCanBio((await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync()));
    })();
  }, []);

  const local = pinProblem(pin) ?? (pin !== again ? 'The two PINs do not match.' : null);

  const save = async () => {
    setErr(null); setOk('');
    try {
      await api.setPin(pin, password);
      await store.clearBio(); setBio(false);                 // an old stored PIN would now be wrong
      setOk('PIN has been set.'); setPassword(''); setPin(''); setAgain('');
    } catch (e) { setErr(e); }
  };

  /** The PIN is verified by the server first; only then is it locked behind the fingerprint on this phone. */
  const toggleBio = async (on: boolean) => {
    setErr(null);
    if (!on) { await store.clearBio(); setBio(false); return; }
    try {
      const typed = await ask(null);
      if (typed === null) return;
      await api.stepUp(typed);
      await store.setBioPin(typed);
      await store.setBioEnabled(true);
      setBio(true);
    } catch (e) { if (!(e instanceof ApiError && e.code === 'cancelled')) setErr(e); }
  };

  return (
    <Screen>
      <Card>
        <H>Approval PIN</H>
        <P sub small>Approving emails and turning on the emergency stop need a 6-digit PIN. Changing the PIN needs your account password.</P>
      </Card>
      <Notice error={err} />
      <Notice tone="ok" text={ok} />
      <Field label="Account password" value={password} onChangeText={setPassword} secureTextEntry />
      <Field label="New PIN (6 digits)" value={pin} onChangeText={(v) => setPin(v.replace(/\D/g, '').slice(0, 6))} secureTextEntry keyboardType="number-pad" maxLength={6} />
      <Field label="PIN again" value={again} onChangeText={(v) => setAgain(v.replace(/\D/g, '').slice(0, 6))} secureTextEntry keyboardType="number-pad" maxLength={6} />
      {pin.length === 6 && local ? <P color="#d97706" small>{local}</P> : null}
      <Btn title="Save PIN" onPress={save} disabled={!password || !!local} />
      {canBio ? (
        <Card>
          <Row between><H>Confirm with fingerprint / Face</H><Switch value={bio} onValueChange={(v) => { void toggleBio(v); }} /></Row>
          <P sub small>When on, approvals use your fingerprint instead of typing the PIN. The PIN is still checked on the server.</P>
        </Card>
      ) : null}
    </Screen>
  );
}
