import * as SecureStore from 'expo-secure-store';

/** Everything sensitive lives in the OS keychain/keystore (SecureStore), never in AsyncStorage or plain files. */
const K = { token: 'j1_token', tenant: 'j1_tenant', base: 'j1_base', pin: 'j1_pin_bio', bio: 'j1_bio_on' } as const;

export const store = {
  get: (k: keyof typeof K) => SecureStore.getItemAsync(K[k]),
  set: (k: keyof typeof K, v: string) => SecureStore.setItemAsync(K[k], v),
  del: (k: keyof typeof K) => SecureStore.deleteItemAsync(K[k]),

  /** The PIN, locked behind fingerprint / face. Reading it makes the OS show the biometric prompt. */
  setBioPin: (pin: string) => SecureStore.setItemAsync(K.pin, pin, { requireAuthentication: true, authenticationPrompt: 'Confirm saving the PIN' }),
  getBioPin: () => SecureStore.getItemAsync(K.pin, { requireAuthentication: true, authenticationPrompt: 'Confirm the approval' }),
  clearBio: async () => { await SecureStore.deleteItemAsync(K.pin); await SecureStore.deleteItemAsync(K.bio); },
  bioEnabled: async () => (await SecureStore.getItemAsync(K.bio)) === '1',
  setBioEnabled: (on: boolean) => (on ? SecureStore.setItemAsync(K.bio, '1') : SecureStore.deleteItemAsync(K.bio)),

  /** Logout: forget the account, the workspace and any stored PIN, but keep the server address for next time. */
  clearSession: async () => { await Promise.all([K.token, K.tenant, K.pin, K.bio].map((k) => SecureStore.deleteItemAsync(k))); },
};
