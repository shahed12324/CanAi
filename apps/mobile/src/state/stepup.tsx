import * as LocalAuthentication from 'expo-local-authentication';
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { Modal, StyleSheet, Text, TextInput, View } from 'react-native';
import { ApiError } from '@/api/client';
import { withStepUp } from '@/lib/stepup';
import { store } from '@/lib/storage';
import { useSession } from './session';
import { Btn, useTheme } from '@/components/ui';

type Sensitive = <T>(action: () => Promise<T>) => Promise<T>;
type Ask = (previous: ApiError | null) => Promise<string | null>;
const Ctx = createContext<{ run: Sensitive; ask: Ask } | null>(null);

/** `const run = useSensitive(); await run(() => api.decide(...))` — asks for the PIN only if the server says so. */
export const useSensitive = () => {
  const c = useContext(Ctx);
  if (!c) throw new Error('StepUpProvider missing');
  return c.run;
};

/** Asks for the PIN once without any action behind it (used when switching fingerprint confirmation on). */
export const useAskPin = () => {
  const c = useContext(Ctx);
  if (!c) throw new Error('StepUpProvider missing');
  return c.ask;
};

export function StepUpProvider({ children }: { children: ReactNode }) {
  const { api } = useSession();
  const [open, setOpen] = useState(false);
  const [prev, setPrev] = useState<ApiError | null>(null);
  const [bio, setBio] = useState(false);
  const resolver = useRef<((pin: string | null) => void) | null>(null);

  const askPin = useCallback((previous: ApiError | null) => new Promise<string | null>((resolve) => {
    resolver.current = resolve;
    setPrev(previous); setOpen(true);
  }), []);
  const close = (pin: string | null) => { setOpen(false); const r = resolver.current; resolver.current = null; r?.(pin); };

  useEffect(() => {
    if (!open) return;
    (async () => {
      const on = await store.bioEnabled();
      const ok = on && (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync());
      setBio(ok);
      if (ok && prev === null) {                       // first ask only: after a wrong PIN, type it
        try { const pin = await store.getBioPin(); if (pin) close(pin); } catch { /* cancelled: type the PIN */ }
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const run: Sensitive = (action) => withStepUp(action, (pin) => api.stepUp(pin), askPin);
  return (
    <Ctx.Provider value={{ run, ask: askPin }}>
      {children}
      <PinModal open={open} error={prev} bio={bio} onDone={close} onBio={async () => { try { const pin = await store.getBioPin(); if (pin) close(pin); } catch { /* ignore */ } }} />
    </Ctx.Provider>
  );
}

function PinModal({ open, error, bio, onDone, onBio }: { open: boolean; error: ApiError | null; bio: boolean; onDone: (pin: string | null) => void; onBio: () => void }) {
  const t = useTheme();
  const [pin, setPin] = useState('');
  useEffect(() => { if (open) setPin(''); }, [open, error]);
  return (
    <Modal visible={open} transparent animationType="fade" onRequestClose={() => onDone(null)}>
      <View style={styles.back}>
        <View style={[styles.box, { backgroundColor: t.card }]}>
          <Text style={{ color: t.text, fontSize: 18, fontWeight: '700' }}>Confirm with your PIN</Text>
          <Text style={{ color: error ? t.danger : t.sub, fontSize: 14, textAlign: 'center' }}>{error ? error.message : 'This action needs your 6-digit PIN.'}</Text>
          <TextInput
            autoFocus keyboardType="number-pad" secureTextEntry maxLength={6} value={pin} accessibilityLabel="PIN" placeholder="••••••" placeholderTextColor={t.sub}
            onChangeText={(v) => { const d = v.replace(/\D/g, ''); setPin(d); if (d.length === 6) onDone(d); }}
            style={{ color: t.text, backgroundColor: t.bg, borderColor: t.line, borderWidth: 1, borderRadius: 12, width: '70%', textAlign: 'center', fontSize: 28, letterSpacing: 10, paddingVertical: 10 }}
          />
          {bio ? <Btn title="Fingerprint / Face" kind="ghost" onPress={onBio} /> : null}
          <Btn title="Reject" kind="ghost" onPress={() => onDone(null)} />
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  back: { flex: 1, backgroundColor: 'rgba(0,0,0,0.55)', justifyContent: 'center', padding: 24 },
  box: { borderRadius: 18, padding: 20, alignItems: 'center', gap: 10 },
});
