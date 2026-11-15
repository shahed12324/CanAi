import type { ApiClient } from './client';
import type { AppNotification, Approval, PcAction, PcDevice, PcPlan, UserMode, Automations, Bootstrap, ChatMessage, ChatReply, Conversation, Dashboard, Decision, EmergencyState, Health, LoginResult, Usage } from './types';

/** One function per server route, so the rest of the app never builds a URL by hand. */
export function endpoints(c: ApiClient) {
  const e = encodeURIComponent;
  return {
    login: (email: string, password: string, deviceName: string, code?: string) =>
      c.request<LoginResult>('POST', '/auth/login', { email, password, device_name: deviceName, ...(code ? { code } : {}) }, { tenant: false }),
    pair: (code: string, deviceName: string) =>
      c.request<LoginResult>('POST', '/auth/pair', { code, device_name: deviceName }, { tenant: false }),
    logout: () => c.request<{ logged_out: boolean }>('POST', '/auth/logout', undefined, { tenant: false }),
    bootstrap: () => c.request<Bootstrap>('GET', '/mobile/bootstrap'),

    registerDevice: (name: string, platform: 'android' | 'ios', pushToken: string | null, appVersion: string) =>
      c.request<{ device_id: string; has_pin: boolean }>('POST', '/mobile/device', { name, platform, push_token: pushToken, app_version: appVersion }),
    setPushToken: (token: string | null) => c.request<{ ok: boolean }>('PUT', '/mobile/device/push', { push_token: token }),
    removeDevice: () => c.request<{ revoked: boolean }>('DELETE', '/mobile/device'),
    setPin: (pin: string, password: string) => c.request<{ pin_set: boolean }>('POST', '/mobile/pin', { pin, password }),
    stepUp: (pin: string) => c.request<{ verified: boolean; expires_in: number }>('POST', '/mobile/step-up', { pin }),

    dashboard: () => c.request<Dashboard>('GET', '/mobile/dashboard'),
    usage: () => c.request<Usage>('GET', '/mobile/usage'),
    automations: () => c.request<Automations>('GET', '/mobile/automations'),
    health: () => c.request<Health>('GET', '/mobile/health'),

    approvals: () => c.request<Approval[]>('GET', '/mobile/approvals'),
    decide: (kind: string, ref: string, decision: Decision) => c.request<{ status: string }>('POST', `/mobile/approvals/${e(kind)}/${e(ref)}`, { decision }),

    pcDevices: () => c.request<PcDevice[]>('GET', '/mobile/pc/devices'),
    pcRequest: (deviceId: string, kind: string, target: string) => c.request<{ id: string | null; status: string; message: string }>('POST', '/mobile/pc/request', { device_id: deviceId, kind, target }),
    pcActions: () => c.request<PcAction[]>('GET', '/mobile/pc/actions'),
    pcPlans: () => c.request<PcPlan[]>('GET', '/mobile/pc/plans'),
    approvePlanStep: (id: string, step: string) => c.request<{ status: string }>('POST', `/mobile/pc/plans/${e(id)}/step`, { step }),
    userMode: () => c.request<{ mode: UserMode; character_id: string | null }>('GET', '/mobile/mode'),
    setUserMode: (mode: UserMode) => c.request<{ mode: UserMode }>('POST', '/mobile/mode', { mode }),
    decidePc: (id: string, decision: Decision) => c.request<{ status: string }>('POST', `/mobile/pc/actions/${e(id)}`, { decision }),

    emergency: () => c.request<EmergencyState>('GET', '/mobile/emergency'),
    setEmergency: (action: 'stop' | 'resume', reason?: string) =>
      c.request<EmergencyState & { result: string }>('POST', '/mobile/emergency', { action, ...(reason ? { reason } : {}) }),

    notifications: (unreadOnly = false) => c.request<AppNotification[]>('GET', `/mobile/notifications${unreadOnly ? '?unread=1' : ''}`),
    markRead: (ids: string[] | 'all') => c.request<{ marked: number; unread: number }>('POST', '/mobile/notifications/read', ids === 'all' ? { all: true } : { ids }),

    conversations: () => c.request<Conversation[]>('GET', '/mobile/chat/conversations'),
    conversation: (id: string) => c.request<{ conversation: Conversation; messages: ChatMessage[] }>('GET', `/mobile/chat/conversations/${e(id)}`),
    sendChat: (text: string, conversationId?: string | null) =>
      c.request<ChatReply>('POST', '/mobile/chat/send', { text, ...(conversationId ? { conversation_id: conversationId } : {}) }, { timeoutMs: 150000 }),
  };
}
export type Api = ReturnType<typeof endpoints>;
