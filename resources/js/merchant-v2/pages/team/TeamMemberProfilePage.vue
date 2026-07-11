<template>
    <section class="page-stack teammate-profile-page">
        <header class="profile-header">
            <RouterLink class="btn btn-secondary" :to="{ name: 'merchant-v2.team' }">Back to team</RouterLink>
            <button class="btn btn-secondary" type="button" :disabled="loading" @click="loadProfile">
                {{ loading ? 'Refreshing...' : 'Refresh' }}
            </button>
        </header>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>

        <section v-if="profile" class="profile-hero">
            <div class="profile-identity">
                <span class="profile-avatar">{{ initials(profile.user) }}</span>
                <div>
                    <p class="page-kicker">Teammate dossier</p>
                    <h2 class="page-title">{{ profile.user.name || profile.user.email }}</h2>
                    <p class="page-subtitle">{{ profile.user.email }}</p>
                </div>
            </div>

            <div class="profile-status-strip">
                <span class="status-badge" :class="profile.user.status === 'active' ? 'status-success' : 'status-neutral'">
                    {{ profile.user.status || 'unknown' }}
                </span>
                <span class="role-pill">{{ profile.user.role_name || 'No role' }}</span>
                <span class="profile-id">ID #{{ profile.user.id }}</span>
            </div>
        </section>

        <section v-if="profile" class="profile-metric-grid">
            <article v-for="metric in metrics" :key="metric.label" class="card card-pad profile-metric" :class="metric.tone">
                <span :class="['metric-dot', metric.tone]"></span>
                <div>
                    <p>{{ metric.label }}</p>
                    <strong>{{ metric.value }}</strong>
                    <small>{{ metric.note }}</small>
                </div>
            </article>
        </section>

        <section v-if="profile" class="profile-workspace">
            <div class="profile-main-column">
                <article class="card card-pad activity-panel">
                    <div class="section-header">
                        <div>
                            <h3 class="card-title">Activity timeline</h3>
                            <p class="card-subtitle">Actions performed by this teammate or targeting this teammate.</p>
                        </div>
                        <span class="status-badge status-info">{{ profile.meta.total }} events</span>
                    </div>

                    <div class="activity-filters">
                        <button
                            v-for="section in sectionTabs"
                            :key="section.key"
                            class="activity-filter"
                            :class="{ 'is-active': activeSection === section.key }"
                            type="button"
                            @click="activeSection = section.key"
                        >
                            <span>{{ section.label }}</span>
                            <strong>{{ section.count }}</strong>
                        </button>
                    </div>

                    <div v-if="filteredActivity.length" class="activity-timeline">
                        <article v-for="event in filteredActivity" :key="event.id" class="activity-item" :class="`activity-item-${event.type || 'action'}`">
                            <span :class="['activity-dot', `activity-dot-${event.type || 'action'}`]"></span>
                            <div class="activity-card" :class="`activity-card-${event.type || 'action'}`">
                                <div class="activity-card-head">
                                    <div>
                                        <strong>{{ actionLabel(event.action) }}</strong>
                                        <p>
                                            {{ sectionLabel(event.section) }}
                                            <span :class="['type-pill', `type-pill-${event.type || 'action'}`]">{{ typeLabel(event.type) }}</span>
                                        </p>
                                    </div>
                                    <time>{{ formatDateTime(event.created_at) }}</time>
                                </div>
                                <div class="activity-context">
                                    <span>Actor: <strong>{{ event.actor?.name || event.actor?.email || 'System' }}</strong></span>
                                    <span v-if="event.target_label">Target: <strong>{{ event.target_label }}</strong></span>
                                    <span v-if="event.ip_address">IP: <strong>{{ event.ip_address }}</strong></span>
                                </div>
                                <div v-if="metadataEntries(event).length" class="metadata-grid">
                                    <div v-for="[key, value] in metadataEntries(event)" :key="key">
                                        <span>{{ metadataLabel(key) }}</span>
                                        <strong>{{ metadataValue(value) }}</strong>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div v-else class="empty-state">No activity found for this filter.</div>
                </article>
            </div>

            <aside class="profile-side-column">
                <article class="card card-pad role-access-card">
                    <h3 class="card-title">Role access</h3>
                    <p class="card-subtitle">{{ profile.role?.description || 'Role details returned by the backend.' }}</p>
                    <div class="capability-chip-list">
                        <span v-for="capability in profile.role?.capabilities || []" :key="capability.code" class="capability-chip">
                            {{ capabilityLabel(capability.code) }}
                        </span>
                    </div>
                </article>

                <article class="card card-pad profile-facts">
                    <h3 class="card-title">Profile facts</h3>
                    <div class="fact-list">
                        <div>
                            <span>Created</span>
                            <strong>{{ formatDateTime(profile.user.created_at) }}</strong>
                        </div>
                        <div>
                            <span>Last login</span>
                            <strong>{{ formatDateTime(profile.user.last_login_at) }}</strong>
                        </div>
                        <div>
                            <span>Last event</span>
                            <strong>{{ formatDateTime(profile.stats.last_event_at) }}</strong>
                        </div>
                    </div>
                </article>
            </aside>
        </section>

        <section v-else-if="loading" class="card card-pad">
            <div class="skeleton profile-skeleton"></div>
        </section>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { merchantApi } from '../../services/merchantApi';

const props = defineProps({
    userId: {
        type: [String, Number],
        required: true,
    },
});

const loading = ref(true);
const error = ref('');
const profile = ref(null);
const activeSection = ref('all');

const activity = computed(() => profile.value?.activity?.data || []);
const metrics = computed(() => {
    const stats = profile.value?.stats || {};

    return [
        { label: 'Total events', value: stats.total_events || 0, note: 'All tracked actions', tone: 'metric-blue' },
        { label: 'Write actions', value: stats.write_events || 0, note: 'Changed business data', tone: 'metric-green' },
        { label: 'Security events', value: stats.security_events || 0, note: 'Access-sensitive actions', tone: 'metric-amber' },
        { label: 'Last activity', value: shortDate(stats.last_event_at), note: 'Most recent audit record', tone: 'metric-gray' },
    ];
});
const sectionTabs = computed(() => {
    const counts = activity.value.reduce((carry, event) => {
        const section = event.section || 'other';
        carry[section] = (carry[section] || 0) + 1;
        return carry;
    }, {});

    return [
        { key: 'all', label: 'All', count: activity.value.length },
        ...Object.entries(counts).map(([key, count]) => ({ key, label: sectionLabel(key), count })),
    ];
});
const filteredActivity = computed(() => (
    activeSection.value === 'all'
        ? activity.value
        : activity.value.filter((event) => event.section === activeSection.value)
));

const loadProfile = async () => {
    loading.value = true;
    error.value = '';

    try {
        const response = await merchantApi.userProfile(props.userId);
        profile.value = response.data?.data || null;
    } catch (exception) {
        error.value = exception?.response?.data?.message || 'Failed to load teammate profile.';
    } finally {
        loading.value = false;
    }
};

const initials = (user) => {
    const source = user?.name || user?.email || 'U';
    return source.split(/[ @._-]+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase()).join('') || 'U';
};
const capabilityLabel = (code) => code.replaceAll('_', ' ').replace('.', ' / ');
const sectionLabel = (section) => ({
    payments: 'Payments',
    team: 'Team',
    developers: 'Developers',
    settlements: 'Settlements',
    settings: 'Settings',
}[section] || section || 'Other');
const typeLabel = (type) => ({
    action: 'Action',
    write: 'Write',
    security: 'Security',
}[type] || type || 'Action');
const actionLabel = (action) => (action || '')
    .split('.')
    .map((part) => part.replaceAll('_', ' '))
    .join(' / ');
const metadataLabel = (key) => key.replaceAll('_', ' ');
const metadataValue = (value) => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) return value.join(', ') || '—';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};
const metadataEntries = (event) => Object.entries(event.metadata || {}).slice(0, 8);
const formatDateTime = (value) => (value ? new Date(value).toLocaleString() : '—');
const shortDate = (value) => (value ? new Date(value).toLocaleDateString() : '—');

onMounted(loadProfile);
</script>

<style scoped>
.profile-header {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
}

.profile-hero {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 22px;
    align-items: center;
    min-height: 156px;
    padding: 26px;
    border: 1px solid rgba(214, 221, 232, 0.92);
    border-radius: 28px;
    background:
        radial-gradient(circle at 12% 8%, rgba(36, 107, 254, 0.20), transparent 34%),
        radial-gradient(circle at 88% 18%, rgba(22, 163, 74, 0.13), transparent 26%),
        linear-gradient(145deg, #ffffff 0%, #f4f8ff 56%, #f9fafb 100%);
    box-shadow: 0 22px 54px rgba(16, 24, 40, 0.11);
    overflow: hidden;
}

.profile-hero::after {
    content: "";
    position: absolute;
    right: -46px;
    bottom: -66px;
    width: 168px;
    height: 168px;
    border-radius: 999px;
    background: rgba(36, 107, 254, 0.10);
    pointer-events: none;
}

.profile-identity,
.profile-status-strip {
    position: relative;
    z-index: 1;
}

.profile-identity {
    display: grid;
    grid-template-columns: 70px minmax(0, 1fr);
    gap: 16px;
    align-items: center;
    min-width: 0;
}

.profile-avatar {
    width: 76px;
    height: 76px;
    border-radius: 24px;
    display: grid;
    place-items: center;
    background: #ffffff;
    color: var(--color-brand-700);
    border: 1px solid rgba(36, 107, 254, 0.16);
    box-shadow: 0 16px 32px rgba(36, 107, 254, 0.16);
    font-size: 23px;
    font-weight: 850;
    flex: 0 0 auto;
}

.profile-status-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
    align-items: center;
    max-width: 280px;
}

.profile-id,
.role-pill {
    min-height: 30px;
    padding: 0 11px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    border: 1px solid var(--color-border);
    background: rgba(255, 255, 255, 0.78);
    color: var(--color-text-muted);
    font-size: 12px;
    font-weight: 750;
    box-shadow: var(--shadow-sm);
}

.profile-metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.profile-metric {
    position: relative;
    overflow: hidden;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 17px;
    border-radius: 18px;
    border-color: rgba(214, 221, 232, 0.95);
    background:
        radial-gradient(circle at top right, rgba(36, 107, 254, 0.07), transparent 34%),
        linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
}

.profile-metric::after {
    content: '';
    position: absolute;
    right: -22px;
    bottom: -32px;
    width: 92px;
    height: 92px;
    border-radius: 999px;
    opacity: 0.14;
    pointer-events: none;
}

.profile-metric.metric-blue {
    border-color: rgba(46, 144, 250, 0.24);
    background:
        radial-gradient(circle at top right, rgba(46, 144, 250, 0.17), transparent 38%),
        linear-gradient(180deg, #ffffff 0%, #f4f9ff 100%);
}

.profile-metric.metric-green {
    border-color: rgba(22, 163, 74, 0.24);
    background:
        radial-gradient(circle at top right, rgba(22, 163, 74, 0.16), transparent 38%),
        linear-gradient(180deg, #ffffff 0%, #f4fff8 100%);
}

.profile-metric.metric-amber {
    border-color: rgba(247, 144, 9, 0.30);
    background:
        radial-gradient(circle at top right, rgba(247, 144, 9, 0.20), transparent 38%),
        linear-gradient(180deg, #ffffff 0%, #fff8eb 100%);
}

.profile-metric.metric-gray {
    border-color: rgba(152, 162, 179, 0.28);
    background:
        radial-gradient(circle at top right, rgba(152, 162, 179, 0.16), transparent 38%),
        linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.profile-metric.metric-blue::after { background: var(--color-info-500); }
.profile-metric.metric-green::after { background: var(--color-success-500); }
.profile-metric.metric-amber::after { background: var(--color-warning-500); }
.profile-metric.metric-gray::after { background: var(--color-text-subtle); }

.metric-dot,
.activity-dot {
    width: 9px;
    height: 9px;
    border-radius: 999px;
    margin-top: 5px;
    flex: 0 0 auto;
}

.metric-blue,
.activity-dot-action { background: var(--color-brand-500); }

.metric-green,
.activity-dot-write { background: var(--color-success-500); }

.metric-amber,
.activity-dot-security { background: var(--color-warning-500); }

.metric-gray { background: var(--color-text-subtle); }

.activity-dot {
    position: relative;
    z-index: 1;
    width: 11px;
    height: 11px;
    border: 2px solid #ffffff;
    box-shadow: 0 0 0 3px rgba(36, 107, 254, 0.10);
}

.activity-dot-write {
    box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.13);
}

.activity-dot-security {
    box-shadow: 0 0 0 3px rgba(247, 144, 9, 0.20);
}

.profile-metric p,
.profile-metric small {
    margin: 0;
    color: var(--color-text-muted);
    font-size: 12px;
    line-height: 1.35;
}

.profile-metric strong {
    display: block;
    margin: 4px 0;
    color: var(--color-text);
    font-size: 22px;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}

.profile-workspace {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 20px;
    align-items: start;
}

.profile-main-column,
.profile-side-column {
    display: grid;
    gap: 20px;
}

.profile-side-column {
    position: sticky;
    top: calc(var(--topbar-height) + 24px);
}

.activity-panel {
    padding: 22px;
    border-radius: 22px;
}

.activity-filters {
    position: sticky;
    top: calc(var(--topbar-height) + 12px);
    z-index: 5;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 16px -6px 0;
    padding: 8px 6px;
    border: 1px solid transparent;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.88);
    backdrop-filter: blur(14px);
}

.activity-filter {
    min-height: 32px;
    border: 1px solid var(--color-border);
    border-radius: 999px;
    background: var(--color-surface-subtle);
    color: var(--color-text-muted);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 0 11px;
    font-size: 12px;
    font-weight: 750;
}

.activity-filter.is-active {
    border-color: var(--color-brand-100);
    background: var(--color-brand-50);
    color: var(--color-brand-700);
}

.activity-filter strong {
    color: var(--color-text);
}

.activity-timeline {
    position: relative;
    display: grid;
    gap: 12px;
    margin-top: 18px;
}

.activity-timeline::before {
    content: '';
    position: absolute;
    top: 8px;
    bottom: 8px;
    left: 4px;
    width: 2px;
    border-radius: 999px;
    background: linear-gradient(180deg, rgba(36, 107, 254, 0.22), rgba(214, 221, 232, 0.65));
}

.activity-item {
    position: relative;
    display: grid;
    grid-template-columns: 16px minmax(0, 1fr);
    gap: 12px;
}

.activity-card {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--color-border);
    border-radius: 16px;
    padding: 15px 15px 15px 17px;
    background:
        linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
    box-shadow: 0 8px 20px rgba(16, 24, 40, 0.055);
}

.activity-card::before {
    content: '';
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: var(--color-info-500);
}

.activity-card-write::before {
    background: var(--color-success-500);
}

.activity-card-security::before {
    background: var(--color-warning-500);
}

.activity-card-action {
    border-color: rgba(46, 144, 250, 0.20);
    background:
        radial-gradient(circle at 100% 0%, rgba(46, 144, 250, 0.10), transparent 32%),
        linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
}

.activity-card-write {
    border-color: rgba(22, 163, 74, 0.24);
    background:
        radial-gradient(circle at 100% 0%, rgba(22, 163, 74, 0.12), transparent 32%),
        linear-gradient(180deg, #ffffff 0%, #f6fff9 100%);
}

.activity-card-security {
    border-color: rgba(247, 144, 9, 0.38);
    background:
        radial-gradient(circle at 100% 0%, rgba(247, 144, 9, 0.20), transparent 35%),
        linear-gradient(180deg, #fffaf0 0%, #ffffff 100%);
    box-shadow: 0 10px 24px rgba(181, 71, 8, 0.09);
}

.activity-card-head {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
}

.activity-card-head strong {
    color: var(--color-text);
    font-size: 14px;
    text-transform: capitalize;
}

.activity-card-head p,
.activity-card-head time {
    margin: 4px 0 0;
    color: var(--color-text-muted);
    font-size: 12px;
    line-height: 1.35;
}

.activity-card-head time {
    flex: 0 0 auto;
    text-align: right;
}

.type-pill {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    margin-left: 7px;
    padding: 0 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    vertical-align: middle;
}

.type-pill-action {
    border: 1px solid rgba(46, 144, 250, 0.22);
    background: var(--color-info-50);
    color: var(--color-info-700);
}

.type-pill-write {
    border: 1px solid rgba(22, 163, 74, 0.22);
    background: var(--color-success-50);
    color: var(--color-success-700);
}

.type-pill-security {
    border: 1px solid rgba(247, 144, 9, 0.30);
    background: var(--color-warning-50);
    color: var(--color-warning-700);
}

.metadata-grid,
.fact-list {
    display: grid;
    gap: 8px;
}

.activity-context {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 11px;
}

.activity-context span {
    min-width: 0;
    max-width: 100%;
    padding: 7px 10px;
    border: 1px solid rgba(229, 233, 240, 0.9);
    border-radius: 12px;
    background: #ffffff;
    color: var(--color-text-muted);
    font-size: 11px;
    line-height: 1.25;
    overflow-wrap: anywhere;
}

.activity-context strong {
    color: var(--color-text);
    font-weight: 750;
}

.metadata-grid div,
.fact-list div {
    min-width: 0;
    padding: 11px;
    border-radius: 12px;
    background: var(--color-surface-subtle);
    border: 1px solid var(--color-border);
}

.metadata-grid span,
.fact-list span {
    display: block;
    color: var(--color-text-muted);
    font-size: 11px;
    font-weight: 750;
    text-transform: uppercase;
}

.metadata-grid strong,
.fact-list strong {
    display: block;
    margin: 4px 0 0;
    color: var(--color-text);
    font-size: 13px;
    font-weight: 750;
    overflow-wrap: anywhere;
}

.metadata-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 10px;
}

.capability-chip-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 16px;
}

.capability-chip {
    min-height: 24px;
    display: inline-flex;
    align-items: center;
    padding: 0 8px;
    border: 1px solid var(--color-border);
    border-radius: 999px;
    background: var(--color-surface-subtle);
    color: var(--color-text-muted);
    font-size: 11px;
    font-weight: 750;
}

.role-access-card,
.profile-facts {
    border-radius: 22px;
    border-color: rgba(214, 221, 232, 0.95);
    box-shadow: 0 12px 28px rgba(16, 24, 40, 0.07);
}

.profile-skeleton {
    height: 220px;
}

@media (max-width: 1023px) {
    .profile-workspace,
    .profile-metric-grid {
        grid-template-columns: 1fr;
    }

    .profile-side-column {
        position: static;
        grid-row: 1;
    }
}

@media (max-width: 640px) {
    .profile-header {
        align-items: stretch;
        flex-direction: column;
    }

    .profile-header .btn {
        width: 100%;
    }

    .profile-hero {
        grid-template-columns: 1fr;
        padding: 18px;
        border-radius: 20px;
    }

    .profile-identity {
        grid-template-columns: 56px minmax(0, 1fr);
        gap: 12px;
    }

    .profile-avatar {
        width: 56px;
        height: 56px;
        border-radius: 18px;
        font-size: 18px;
    }

    .activity-card-head {
        align-items: stretch;
        flex-direction: column;
    }

    .activity-card-head time {
        text-align: left;
    }

    .profile-status-strip {
        justify-content: flex-start;
        max-width: none;
    }

    .metadata-grid {
        grid-template-columns: 1fr;
    }

    .profile-metric-grid {
        display: grid;
        grid-auto-columns: minmax(238px, 78%);
        grid-auto-flow: column;
        grid-template-columns: none;
        gap: 10px;
        margin: 0 -16px;
        padding: 0 16px 4px;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        scroll-snap-type: x proximity;
        scrollbar-width: none;
    }

    .profile-metric-grid::-webkit-scrollbar {
        display: none;
    }

    .profile-metric {
        padding: 13px;
        scroll-snap-align: start;
    }

    .activity-panel {
        padding: 16px;
        border-radius: 22px;
    }

    .activity-filters {
        top: 8px;
        flex-wrap: nowrap;
        margin: 14px -8px 0;
        padding: 8px;
        overflow-x: auto;
        border-color: rgba(214, 221, 232, 0.78);
        box-shadow: 0 10px 26px rgba(16, 24, 40, 0.10);
        scrollbar-width: none;
    }

    .activity-filters::-webkit-scrollbar {
        display: none;
    }

    .activity-filter {
        flex: 0 0 auto;
    }
}
</style>
