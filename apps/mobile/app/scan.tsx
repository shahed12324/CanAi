import { CameraView, useCameraPermissions } from 'expo-camera';
import { Redirect, useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { Text, View } from 'react-native';
import { Btn, Notice, useTheme } from '@/components/ui';
import { parsePairing } from '@/lib/pairing';
import { useSession } from '@/state/session';

export default function Scan() {
  const t = useTheme();
  const router = useRouter();
  const { status, pairWith } = useSession();
  const [perm, ask] = useCameraPermissions();
  const [err, setErr] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const done = useRef(false);
  if (status === 'in') return <Redirect href="/home" />;

  const onCode = async (data: string) => {
    if (done.current) return;
    const p = parsePairing(data, __DEV__);
    if (!p) { setErr(new Error('This is not a CANAI QR code. Use the one on the "Mobile app" page of the website.')); return; }
    done.current = true; setBusy(true); setErr(null);
    try {
      await pairWith(p.server, p.code);
    } catch (e) {
      done.current = false; setErr(e);
    } finally {
      setBusy(false);
    }
  };

  if (!perm) return <View style={{ flex: 1, backgroundColor: t.bg }} />;
  if (!perm.granted) {
    return (
      <View style={{ flex: 1, backgroundColor: t.bg, padding: 20, gap: 12, justifyContent: 'center' }}>
        <Text style={{ color: t.text, fontSize: 16 }}>The camera is used only to scan the QR code from the website.</Text>
        <Btn title="Allow camera" onPress={() => { void ask(); }} />
        <Btn title="Back to login" kind="ghost" onPress={() => router.back()} />
      </View>
    );
  }
  return (
    <View style={{ flex: 1, backgroundColor: '#000' }}>
      <CameraView style={{ flex: 1 }} facing="back" barcodeScannerSettings={{ barcodeTypes: ['qr'] }} onBarcodeScanned={busy ? undefined : (r) => { void onCode(r.data); }} />
      <View style={{ position: 'absolute', left: 0, right: 0, bottom: 0, padding: 16, gap: 8, backgroundColor: t.bg }}>
        <Notice error={err} />
        <Text style={{ color: t.sub, textAlign: 'center' }}>{busy ? 'Connecting…' : 'Point the camera at the QR code on the website (Mobile app page).'}</Text>
        <Btn title="Back to login" kind="ghost" small onPress={() => router.back()} />
      </View>
    </View>
  );
}
