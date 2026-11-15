export type Money = string; // credits come as decimal strings so nothing is lost to float rounding

export interface Workspace { id: string; name: string; type: string }
export interface Capabilities { approve: boolean; outreach: boolean; emergency: boolean; health: boolean; billing: boolean }
export interface UserInfo { id: string; name: string; email: string; locale?: string | null }
export interface Bootstrap { user: UserInfo; workspaces: Workspace[]; can: Capabilities | null; api_version: number; min_app_version: string }
export interface LoginResult { token: string; token_type: 'Bearer'; expires_at: string; user: UserInfo }

export interface EmergencyState { active: boolean; since: string | null; reason: string | null }
export interface Dashboard {
  balance: Money; low_balance: boolean; spent_this_month: Money; pending_approvals: number; leads: number; unread: number;
  emergency: EmergencyState; recent_chats: { id: string; title: string | null; last_message_at: string | null }[];
}
export interface Usage { balance: Money; spent_this_month: Money; monthly_limit: Money | null; daily: { day: string; credits: Money; calls: number }[] }
export interface Campaign { id: string; name: string; status: string; pending: number; queued: number; sent: number; replied: number; failed: number }
export interface Automations { campaigns: Campaign[]; research_jobs: { id: string; name: string; status: string; total: number; created_at: string }[] }
export interface Health {
  ai_engine: boolean; emergency: EmergencyState; pending_approvals: number; failed_mails_24h: number;
  email_accounts_with_errors: { id: string; name: string; from: string; provider: string; error: string }[]; ok: boolean;
}
export interface Approval { kind: string; ref: string; title: string; summary: string; created_at: string }
export interface PcAction { id: string; kind: string; risk: 'low' | 'medium' | 'high' | 'critical'; device: string; params: Record<string, unknown>; created_at: string; expires_at: string }
export interface PcDevice { id: string; name: string; platform: string; last_seen_at: string | null }
export interface PcPlanStep { id: string; kind: string; target: string }
export interface PcPlan { id: string; device_id: string; device: string; goal: string; steps: PcPlanStep[]; needs: string[]; status: string; reason: string | null; result: string | null; created_at: string; expires_at: string }
export type UserMode = 'companion' | 'agent';
export type Decision = 'approve' | 'reject';
export interface AppNotification { id: string; kind: string; title: string; body: string; ref_kind: string | null; ref_id: string | null; created_at: string; read: boolean }
export interface Conversation { id: string; title: string | null; last_message_at: string | null }
export interface ChatMessage { id: string; role: 'user' | 'assistant' | 'system'; content: string; status: string; error_code: string | null }
export interface ChatReply { conversation_id: string | null; message_id: string | null; title: string | null; text: string; credits: Money | null; error: { code: string; message: string } | null }
