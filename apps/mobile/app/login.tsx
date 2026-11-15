import { Redirect, useRouter } from 'expo-router';
import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Text, View } from 'react-native';
import { ApiError } from '@/api/client';
import { Btn, Field, Notice, Screen, useTheme } from '@/components/ui';
import { useSession } from '@/state/session';

export default function Login() {
  const t = useTheme();
  const router = useRouter();
  const { status, baseUrl, signIn } = useSession();
  const [server, setServer] = useState(baseUrl);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [needCode, setNeedCode] = useState(false);
  const [err, setErr] = useState<unknown>(null);
  if (status === 'in') return <Redirect href="/home" />;

  const submit = async () => {
    setErr(null);
    try {
      await signIn(server || baseUrl, email, password, needCode ? code : undefined);
    } catch (e) {
      if (e instanceof ApiError && e.code === 'two_factor_required') setNeedCode(true);
      setErr(e);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: t.bg }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen>
        <View style={{ alignItems: 'center', paddingVertical: 28, gap: 6 }}>
          <Text style={{ color: t.primary, fontSize: 32, fontWeight: '800' }}>CANAI</Text>
          <Text style={{ color: t.sub, fontSize: 15 }}>Your AI assistant, now in your pocket</Text>
        </View>
        <Btn title="Scan QR code from the website" onPress={() => router.push('/scan')} />
        <Text style={{ color: t.sub, textAlign: 'center', fontSize: 13 }}>or log in with email</Text>
        <Notice error={err} />
        {!baseUrl ? <Field label="Server address" value={server} onChangeText={setServer} autoCapitalize="none" autoCorrect={false} keyboardType="url" placeholder="https://your-domain.com" /> : null}
        <Field label="Email" value={email} onChangeText={setEmail} autoCapitalize="none" autoCorrect={false} keyboardType="email-address" textContentType="username" />
        <Field label="Password" value={password} onChangeText={setPassword} secureTextEntry textContentType="password" />
        {needCode ? <Field label="Two-step code (or the 6 digits from your authenticator app)" value={code} onChangeText={setCode} keyboardType="number-pad" maxLength={10} /> : null}
        <Btn title="Log in" onPress={submit} disabled={!email || !password || (!baseUrl && !server)} />
        {baseUrl ? <Btn title={`Server: ${baseUrl.replace('https://', '')} (change)`} kind="ghost" small onPress={() => setServer(server === baseUrl ? '' : baseUrl)} /> : null}
      </Screen>
    </KeyboardAvoidingView>
  );
}
