/**
 * Single source of truth for status → visual tone mapping.
 * The live portal duplicates these maps across Dashboard, Orders and
 * ReservationsPanel; the lab centralises them so a redesign only changes one file.
 */

export type Tone = 'green' | 'red' | 'amber' | 'blue' | 'gray' | 'purple';

const ORDER_STATUS: Record<string, Tone> = {
    completed: 'green',
    processing: 'blue',
    confirmed: 'blue',
    pending: 'amber',
    'on-hold': 'amber',
    cancelled: 'red',
    failed: 'red',
    trash: 'red',
    refunded: 'purple',
    returned: 'purple',
    'returned-with-charge': 'purple',
};

const RESERVATION_STATE: Record<string, Tone> = {
    confirmed: 'green',
    reserved: 'blue',
    partial: 'amber',
    returned: 'purple',
    released: 'gray',
    none: 'gray',
};

const COMPONENT_STATUS: Record<string, Tone> = {
    confirmed: 'green',
    reserved: 'blue',
    partial: 'amber',
    returned: 'purple',
    released: 'gray',
    pending: 'gray',
};

const MOVEMENT_TYPE: Record<string, { label: string; tone: Tone }> = {
    reserve: { label: 'Reserved', tone: 'blue' },
    confirm: { label: 'Confirmed', tone: 'green' },
    release: { label: 'Released', tone: 'gray' },
    return: { label: 'Returned', tone: 'purple' },
    adjustment: { label: 'Adjustment', tone: 'amber' },
    initial: { label: 'Initial', tone: 'gray' },
    purchase_in: { label: 'Purchase', tone: 'gray' },
    sync_fix: { label: 'Sync fix', tone: 'gray' },
};

const SYNC_STATUS: Record<string, { label: string; tone: Tone }> = {
    ok: { label: 'ok', tone: 'green' },
    mismatch: { label: 'mismatch', tone: 'amber' },
    unmapped: { label: 'unmapped', tone: 'amber' },
    error: { label: 'error', tone: 'red' },
};

const RISK_LEVEL: Record<string, Tone> = {
    critical: 'red',
    warning: 'amber',
    healthy: 'green',
};

const URGENCY: Record<string, Tone> = {
    high: 'red',
    medium: 'amber',
    low: 'blue',
};

const ROLE: Record<string, Tone> = {
    admin: 'purple',
    manager: 'blue',
    staff: 'gray',
};

function pick(map: Record<string, Tone>, key: string | null | undefined): Tone {
    if (!key) return 'gray';
    return map[key.toLowerCase()] ?? 'gray';
}

export const statusTone = (s?: string | null) => pick(ORDER_STATUS, s);
export const reservationTone = (s?: string | null) => pick(RESERVATION_STATE, s);
export const componentTone = (s?: string | null) => pick(COMPONENT_STATUS, s);
export const riskTone = (s?: string | null) => pick(RISK_LEVEL, s);
export const urgencyTone = (s?: string | null) => pick(URGENCY, s);
export const roleTone = (s?: string | null) => pick(ROLE, s);

export const movementMeta = (type: string) =>
    MOVEMENT_TYPE[type] ?? { label: type, tone: 'gray' as Tone };

export const syncMeta = (s: string) => SYNC_STATUS[s] ?? { label: s, tone: 'gray' as Tone };
