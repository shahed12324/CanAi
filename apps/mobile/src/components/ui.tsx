import { useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View, useColorScheme, type TextInputProps, type ViewStyle } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { ApiError } from '@/api/client';

export function useTheme() {
  const dark = useColorScheme() === 'dark';
  return dark
    ? { dark, bg: '#0b1220', card: '#131c2e', text: '#f1f5f9', sub: '#94a3b8', line: '#243049', primary: '#3b82f6', danger: '#ef4444', ok: '#22c55e', warn: '#f59e0b', onPrimary: '#ffffff' }
    : { dark, bg: '#f1f5f9', card: '#ffffff', text: '#0f172a', sub: '#64748b', line: '#e2e8f0', primary: '#2563eb', danger: '#dc2626', ok: '#16a34a', warn: '#d97706', onPrimary: '#ffffff' };
}

export function Screen({ children, onRefresh, padded = true }: { children: ReactNode; onRefresh?: () => Promise<unknown>; padded?: boolean }) {
  const t = useTheme();
  const [busy, setBusy] = useState(false);
  const insets = useSafeAreaInsets();
  return (
    <ScrollView
      style={{ backgroundColor: t.bg }}
      contentContainerStyle={{ padding: padded ? 16 : 0, paddingBottom: insets.bottom + 24, gap: 12 }}
      keyboardShouldPersistTaps="handled"
      refreshControl={onRefresh ? <RefreshControl refreshing={busy} onRefresh={async () => { setBusy(true); try { await onRefresh(); } finally { setBusy(false); } }} /> : undefined}
    >
      {children}
    </ScrollView>
  );
}

export function Card({ children, style, tone }: { children: ReactNode; style?: ViewStyle; tone?: 'danger' | 'warn' | 'ok' }) {
  const t = useTheme();
  const border = tone ? t[tone] : t.line;
  return <View style={[{ backgroundColor: t.card, borderRadius: 14, padding: 14, borderWidth: 1, borderColor: border, gap: 6 }, style]}>{children}</View>;
}

export function H({ children }: { children: ReactNode }) {
  const t = useTheme();
  return <Text style={{ color: t.text, fontSize: 17, fontWeight: '700' }}>{children}</Text>;
}

export function P({ children, sub, small, bold, color }: { children: ReactNode; sub?: boolean; small?: boolean; bold?: boolean; color?: string }) {
  const t = useTheme();
  return <Text style={{ color: color ?? (sub ? t.sub : t.text), fontSize: small ? 13 : 15, fontWeight: bold ? '700' : '400', lineHeight: small ? 18 : 22 }}>{children}</Text>;
}

export function Big({ children, color }: { children: ReactNode; color?: string }) {
  const t = useTheme();
  return <Text style={{ color: color ?? t.text, fontSize: 30, fontWeight: '800' }}>{children}</Text>;
}

export function Btn({ title, onPress, kind = 'primary', busy, disabled, small }: { title: string; onPress: () => void | Promise<void>; kind?: 'primary' | 'danger' | 'ghost'; busy?: boolean; disabled?: boolean; small?: boolean }) {
  const t = useTheme();
  const [own, setOwn] = useState(false);
  const loading = busy || own;
  const bg = kind === 'primary' ? t.primary : kind === 'danger' ? t.danger : 'transparent';
  const fg = kind === 'ghost' ? t.primary : t.onPrimary;
  return (
    <Pressable
      accessibilityRole="button"
      disabled={disabled || loading}
      onPress={async () => { setOwn(true); try { await onPress(); } finally { setOwn(false); } }}
      style={({ pressed }) => ({ backgroundColor: bg, borderRadius: 12, paddingVertical: small ? 9 : 13, paddingHorizontal: 16, alignItems: 'center', justifyContent: 'center', opacity: disabled ? 0.45 : pressed ? 0.8 : 1, borderWidth: kind === 'ghost' ? 1 : 0, borderColor: t.line, minHeight: small ? 38 : 48 })}
    >
      {loading ? <ActivityIndicator color={fg} /> : <Text style={{ color: fg, fontSize: small ? 14 : 16, fontWeight: '700' }}>{title}</Text>}
    </Pressable>
  );
}

export function Row({ children, between }: { children: ReactNode; between?: boolean }) {
  return <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, justifyContent: between ? 'space-between' : 'flex-start' }}>{children}</View>;
}

export function Badge({ text, tone = 'sub' }: { text: string; tone?: 'ok' | 'warn' | 'danger' | 'sub' | 'primary' }) {
  const t = useTheme();
  const c = t[tone];
  return <Text style={{ color: c, borderColor: c, borderWidth: 1, borderRadius: 20, paddingHorizontal: 9, paddingVertical: 2, fontSize: 12, fontWeight: '700', overflow: 'hidden' }}>{text}</Text>;
}

export function Field(props: TextInputProps & { label: string }) {
  const t = useTheme();
  const { label, style, ...rest } = props;
  return (
    <View style={{ gap: 4 }}>
      <Text style={{ color: t.sub, fontSize: 13 }}>{label}</Text>
      <TextInput placeholderTextColor={t.sub} {...rest} style={[{ color: t.text, backgroundColor: t.card, borderColor: t.line, borderWidth: 1, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12, fontSize: 16 }, style]} />
    </View>
  );
}

export function Notice({ error, text, tone = 'danger' }: { error?: unknown; text?: string; tone?: 'danger' | 'warn' | 'ok' }) {
  const t = useTheme();
  const msg = text ?? (error instanceof ApiError ? error.message : error ? 'Something went wrong.' : '');
  if (!msg) return null;
  return <Card tone={tone}><Text style={{ color: t[tone], fontSize: 14, fontWeight: '600' }}>{msg}</Text></Card>;
}

export function Empty({ text }: { text: string }) {
  const t = useTheme();
  return <View style={{ padding: 28, alignItems: 'center' }}><Text style={{ color: t.sub, fontSize: 15, textAlign: 'center' }}>{text}</Text></View>;
}

export function Loading() {
  return <View style={{ padding: 40 }}><ActivityIndicator size="large" /></View>;
}

export const s = StyleSheet.create({ flex: { flex: 1 } });
