<template>
    <section class="page-stack settlement-rules-page">
        <header class="page-header rules-header">
            <div>
                <p class="page-kicker">Funds</p>
                <h2 class="page-title">Settlement Rules</h2>
                <p class="page-subtitle">Control how each paid invoice is forwarded within platform limits.</p>
            </div>
            <div class="page-actions">
                <RouterLink class="btn btn-secondary" :to="{ name: 'merchant-v2.settlements' }">Wallets</RouterLink>
                <button class="btn btn-secondary" type="button" :disabled="loading" @click="reloadPolicies">
                    {{ loading ? 'Refreshing...' : 'Refresh' }}
                </button>
            </div>
        </header>

        <div v-if="notice.message" class="alert" :class="`alert-${notice.type}`">{{ notice.message }}</div>

        <div v-if="loading" class="rules-loading" role="status">
            <span></span>
            <strong>Loading settlement rules...</strong>
        </div>

        <div v-else-if="!policies.length" class="rules-empty">
            <strong>No settlement policies are available.</strong>
            <p>There are no registered assets available to this merchant.</p>
        </div>

        <template v-else-if="activePolicy && activeDraft">
            <section class="rules-desktop" aria-label="Desktop settlement policy editor">
                <nav class="asset-rail" aria-label="Settlement assets">
                    <div class="asset-rail-heading">
                        <strong>Assets</strong>
                        <span>{{ policies.length }} available</span>
                    </div>
                    <button
                        v-for="policy in policies"
                        :key="policyKey(policy)"
                        class="asset-rail-item"
                        :class="{ 'is-active': policyKey(policy) === activeKey }"
                        type="button"
                        @click="selectPolicy(policy)"
                    >
                        <AssetLogo :item="{ asset_key: policy.asset.key }" />
                        <span>
                            <strong>{{ policy.asset.symbol }}</strong>
                            <small>{{ policy.asset.network.name }}</small>
                        </span>
                        <em :class="`mode-${policy.effective.mode}`">{{ shortModeLabel(policy.effective.mode) }}</em>
                    </button>
                </nav>

                <main class="rule-editor">
                    <div class="editor-title-row">
                        <div class="editor-asset-title">
                            <AssetLogo :item="{ asset_key: activePolicy.asset.key }" />
                            <div>
                                <h3>{{ activePolicy.asset.name }}</h3>
                                <p>{{ activePolicy.asset.network.name }} · {{ activePolicy.asset.network.family.toUpperCase() }}</p>
                            </div>
                        </div>
                        <span class="effective-badge" :class="`mode-${activePolicy.effective.mode}`">
                            Effective: {{ modeLabel(activePolicy.effective.mode) }}
                        </span>
                    </div>

                    <section class="effective-strip">
                        <div>
                            <span>Current behavior</span>
                            <strong>{{ effectiveBehavior(activePolicy) }}</strong>
                        </div>
                        <div>
                            <span>Destination wallet</span>
                            <strong :class="activePolicy.destination_wallet.ready ? 'text-success' : 'text-warning'">
                                {{ walletReadiness(activePolicy) }}
                            </strong>
                        </div>
                        <div>
                            <span>Preference revision</span>
                            <strong>{{ activePolicy.revision }}</strong>
                        </div>
                    </section>

                    <section class="editor-section">
                        <div class="section-copy">
                            <h4>Forwarding mode</h4>
                            <p>Choose a rule for future settlement evaluations. Paid invoices already locked to a settlement attempt keep their snapshot.</p>
                        </div>
                        <div class="mode-control" role="group" aria-label="Forwarding mode">
                            <button
                                v-for="option in editorModeOptions(activePolicy)"
                                :key="option.value"
                                class="mode-option"
                                :class="{ 'is-selected': activeDraft.mode === option.value }"
                                type="button"
                                :disabled="!option.available || !canWrite"
                                :title="modeOptionTitle(option)"
                                @click="setMode(option.value)"
                            >
                                <strong>{{ option.label }}</strong>
                                <small>{{ modeDescription(option.value) }}</small>
                            </button>
                        </div>
                        <p v-if="!canWrite" class="readonly-note">Your role can view settlement rules but cannot change them.</p>
                    </section>

                    <section v-if="activeDraft.mode === 'threshold'" class="editor-section threshold-section">
                        <div class="section-copy">
                            <h4>Minimum invoice payout</h4>
                            <p>Applied separately to each invoice. Payments below this amount are held and are not automatically combined.</p>
                        </div>
                        <label class="threshold-field">
                            <span>Minimum invoice payout</span>
                            <div class="amount-input">
                                <input
                                    v-model.trim="activeDraft.minimumInvoicePayout"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    :disabled="!canWrite"
                                    :placeholder="activePolicy.inherited.minimum_invoice_payout || '0.00'"
                                    @input="clearActiveError"
                                />
                                <strong>{{ activePolicy.asset.symbol }}</strong>
                            </div>
                            <small v-if="activePolicy.inherited.minimum_invoice_payout">
                                Platform minimum: {{ activePolicy.inherited.minimum_invoice_payout }} {{ activePolicy.asset.symbol }}
                            </small>
                            <em v-if="activeError">{{ activeError }}</em>
                        </label>
                    </section>

                    <section class="editor-section policy-details">
                        <div class="section-copy">
                            <h4>Platform constraints</h4>
                            <p>Merchant preferences can only make inherited policy more restrictive.</p>
                        </div>
                        <div class="constraint-list">
                            <div>
                                <span>Inherited mode</span>
                                <strong>{{ modeLabel(activePolicy.inherited.mode) }}</strong>
                            </div>
                            <div>
                                <span>Asset availability</span>
                                <strong>{{ activePolicy.inherited.asset_enabled ? 'Enabled' : 'Disabled by platform' }}</strong>
                            </div>
                            <div>
                                <span>Automatic forwarding</span>
                                <strong>{{ activePolicy.inherited.forwarding_enabled ? 'Allowed' : 'Disabled by platform' }}</strong>
                            </div>
                            <p v-for="constraint in activePolicy.inherited.constraints" :key="`${constraint.source}:${constraint.code}`">
                                <strong>{{ constraint.message }}</strong>
                                <span>{{ sourceLabel(constraint.source) }}</span>
                            </p>
                        </div>
                    </section>

                    <section v-if="activePolicy.destination_wallet.required && !activePolicy.destination_wallet.ready" class="wallet-warning">
                        <div>
                            <strong>Merchant destination wallet required</strong>
                            <p>{{ missingWalletMessage(activePolicy) }}</p>
                        </div>
                        <RouterLink class="btn btn-secondary" :to="{ name: 'merchant-v2.settlements' }">Configure wallet</RouterLink>
                    </section>

                    <footer class="desktop-save-row">
                        <span>{{ activeDirty ? 'Unsaved changes' : 'Policy is up to date' }}</span>
                        <button class="btn btn-primary" type="button" :disabled="!canSave" @click="saveActivePolicy">
                            {{ saving ? 'Saving...' : 'Save rule' }}
                        </button>
                    </footer>
                </main>
            </section>

            <section class="rules-mobile" aria-label="Mobile settlement policy editor">
                <div class="mobile-asset-strip" role="tablist" aria-label="Settlement assets">
                    <button
                        v-for="policy in policies"
                        :key="`mobile:${policyKey(policy)}`"
                        :class="{ 'is-active': policyKey(policy) === activeKey }"
                        type="button"
                        role="tab"
                        :aria-selected="policyKey(policy) === activeKey"
                        @click="selectPolicy(policy)"
                    >
                        <AssetLogo :item="{ asset_key: policy.asset.key }" />
                        <span>{{ policy.asset.symbol }}</span>
                    </button>
                </div>

                <div class="mobile-policy-summary">
                    <div>
                        <span>{{ activePolicy.asset.network.name }}</span>
                        <strong>{{ modeLabel(activePolicy.effective.mode) }}</strong>
                    </div>
                    <span :class="activePolicy.destination_wallet.ready ? 'summary-ready' : 'summary-warning'">
                        {{ walletReadiness(activePolicy) }}
                    </span>
                </div>

                <div class="mobile-editor">
                    <section>
                        <h3>Forwarding mode</h3>
                        <p>{{ effectiveBehavior(activePolicy) }}</p>
                        <div class="mobile-mode-list">
                            <button
                                v-for="option in editorModeOptions(activePolicy)"
                                :key="`mobile-mode:${option.value}`"
                                :class="{ 'is-selected': activeDraft.mode === option.value }"
                                type="button"
                                :disabled="!option.available || !canWrite"
                                @click="setMode(option.value)"
                            >
                                <span>
                                    <strong>{{ option.label }}</strong>
                                    <small>{{ modeDescription(option.value) }}</small>
                                </span>
                                <b aria-hidden="true">{{ activeDraft.mode === option.value ? '✓' : '' }}</b>
                            </button>
                        </div>
                    </section>

                    <section v-if="activeDraft.mode === 'threshold'">
                        <h3>Minimum invoice payout</h3>
                        <p>Applied separately to each invoice. Payments below this amount are held and are not automatically combined.</p>
                        <label class="threshold-field">
                            <div class="amount-input">
                                <input
                                    v-model.trim="activeDraft.minimumInvoicePayout"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    :disabled="!canWrite"
                                    :placeholder="activePolicy.inherited.minimum_invoice_payout || '0.00'"
                                    aria-label="Minimum invoice payout"
                                    @input="clearActiveError"
                                />
                                <strong>{{ activePolicy.asset.symbol }}</strong>
                            </div>
                            <small v-if="activePolicy.inherited.minimum_invoice_payout">
                                Platform minimum {{ activePolicy.inherited.minimum_invoice_payout }} {{ activePolicy.asset.symbol }}
                            </small>
                            <em v-if="activeError">{{ activeError }}</em>
                        </label>
                    </section>

                    <section v-if="activePolicy.effective.restriction" class="mobile-restriction">
                        <h3>Platform restriction</h3>
                        <p>{{ activePolicy.effective.restriction.message }}</p>
                    </section>

                    <section v-if="activePolicy.destination_wallet.required && !activePolicy.destination_wallet.ready" class="mobile-wallet-warning">
                        <h3>Destination wallet missing</h3>
                        <p>{{ missingWalletMessage(activePolicy) }}</p>
                        <RouterLink :to="{ name: 'merchant-v2.settlements' }">Configure wallet</RouterLink>
                    </section>

                    <p v-if="!canWrite" class="readonly-note">Your role has read-only access.</p>
                </div>

                <footer class="mobile-save-area">
                    <span>{{ activeDirty ? 'Unsaved rule' : `Revision ${activePolicy.revision}` }}</span>
                    <button class="btn btn-primary" type="button" :disabled="!canSave" @click="saveActivePolicy">
                        {{ saving ? 'Saving...' : 'Save' }}
                    </button>
                </footer>
            </section>
        </template>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { useAuthStore } from '../../../stores/auth';
import AssetLogo from '../../components/payments/AssetLogo.vue';
import { merchantApi } from '../../services/merchantApi';
import {
    canonicalSettlementRequest,
    sameSettlementDraft,
    settlementDraftFromPolicy,
    settlementPolicyKey,
    validateSettlementDraft,
} from '../../services/settlementRulesState';

const authStore = useAuthStore();
const policies = ref([]);
const activeKey = ref('');
const loading = ref(true);
const saving = ref(false);
const permissions = reactive({ can_read: false, can_write: false });
const drafts = reactive({});
const baselines = reactive({});
const errors = reactive({});
const notice = reactive({ message: '', type: 'danger' });
let noticeTimer = null;

const policyKey = settlementPolicyKey;
const activePolicy = computed(() => policies.value.find((policy) => policyKey(policy) === activeKey.value) || null);
const activeDraft = computed(() => drafts[activeKey.value] || null);
const canWrite = computed(() => permissions.can_write && authStore.hasCapability('settlements.write'));
const activeDirty = computed(() => activeDraft.value && !sameSettlementDraft(activeDraft.value, baselines[activeKey.value]));
const anyDirty = computed(() => policies.value.some((policy) => {
    const key = policyKey(policy);
    return drafts[key] && !sameSettlementDraft(drafts[key], baselines[key]);
}));
const activeError = computed(() => errors[activeKey.value] || '');
const canSave = computed(() => canWrite.value && activeDirty.value && !saving.value && !activeError.value);

const setNotice = (message, type = 'danger') => {
    notice.message = message;
    notice.type = type;
    if (noticeTimer) window.clearTimeout(noticeTimer);
    noticeTimer = window.setTimeout(() => {
        notice.message = '';
        noticeTimer = null;
    }, 5000);
};

const replacePolicy = (policy, preserveDraft = false) => {
    const key = policyKey(policy);
    const index = policies.value.findIndex((item) => policyKey(item) === key);
    if (index >= 0) policies.value.splice(index, 1, policy);
    else policies.value.push(policy);

    const serverDraft = settlementDraftFromPolicy(policy);
    baselines[key] = serverDraft;
    if (!preserveDraft || !drafts[key]) drafts[key] = { ...serverDraft };
    errors[key] = '';
};

const loadPolicies = async () => {
    loading.value = true;
    try {
        const response = await merchantApi.settlementPolicies();
        const data = response.data?.data || {};
        policies.value = [];
        Object.assign(permissions, data.permissions || {});
        (data.policies || []).forEach((policy) => replacePolicy(policy));
        if (!policies.value.some((policy) => policyKey(policy) === activeKey.value)) {
            activeKey.value = policies.value[0] ? policyKey(policies.value[0]) : '';
        }
    } catch (exception) {
        policies.value = [];
        setNotice(exception?.response?.data?.message || 'Failed to load settlement rules.');
    } finally {
        loading.value = false;
    }
};

const reloadPolicies = async () => {
    if (anyDirty.value && !window.confirm('Discard all unsaved settlement rule changes?')) return;
    await loadPolicies();
};

const selectPolicy = (policy) => {
    const nextKey = policyKey(policy);
    if (nextKey === activeKey.value) return;
    if (activeDirty.value && !window.confirm('Discard unsaved changes for this asset?')) return;
    if (activeDirty.value) drafts[activeKey.value] = { ...baselines[activeKey.value] };
    activeKey.value = nextKey;
};

const editorModeOptions = (policy) => [
    { value: 'inherit', label: 'Use platform default', available: canWrite.value, reason_code: null },
    ...(policy.editable?.mode?.options || []).filter((option) => ['immediate', 'threshold', 'disabled'].includes(option.value)),
];

const setMode = (mode) => {
    if (!activeDraft.value || !canWrite.value) return;
    activeDraft.value.mode = mode;
    if (mode !== 'threshold') activeDraft.value.minimumInvoicePayout = '';
    clearActiveError();
};

const clearActiveError = () => {
    errors[activeKey.value] = '';
};

const saveActivePolicy = async () => {
    if (saving.value || !activePolicy.value || !activeDraft.value || !canWrite.value) return;
    const validationError = validateSettlementDraft(activeDraft.value, activePolicy.value.asset.settlement_scale);
    if (validationError) {
        errors[activeKey.value] = validationError;
        return;
    }

    if (activeDraft.value.mode === 'disabled' && !window.confirm(
        'Pause settlements for this asset? Checkout remains enabled, and future payouts for this asset will be held.',
    )) return;

    saving.value = true;
    clearActiveError();
    const submittedKey = activeKey.value;

    try {
        const response = await merchantApi.updateSettlementPolicy(activePolicy.value.asset.key, {
            revision: activePolicy.value.revision,
            requested: canonicalSettlementRequest(activeDraft.value),
        });
        replacePolicy(response.data?.data?.policy);
        setNotice('Settlement rule saved.', 'success');
    } catch (exception) {
        const response = exception?.response;
        if (response?.status === 409 && response.data?.data?.policy) {
            replacePolicy(response.data.data.policy, true);
            setNotice('This rule changed elsewhere. Review your unsaved values against the latest policy, then save again.', 'warning');
        } else {
            const fieldErrors = response?.data?.errors || {};
            errors[submittedKey] = fieldErrors['requested.minimum_invoice_payout']?.[0]
                || fieldErrors['requested.mode']?.[0]
                || response?.data?.message
                || 'Failed to save settlement rule.';
        }
    } finally {
        saving.value = false;
    }
};

const modeLabel = (mode) => ({
    immediate: 'Immediate',
    threshold: 'Minimum invoice payout',
    manual: 'Manual hold',
    internal_balance_only: 'Internal balance only',
    disabled: 'Pause settlements',
}[mode] || mode || 'Unknown');

const shortModeLabel = (mode) => ({
    immediate: 'Immediate',
    threshold: 'Minimum',
    manual: 'Manual',
    internal_balance_only: 'Internal',
    disabled: 'Paused',
}[mode] || 'Policy');

const modeDescription = (mode) => ({
    inherit: 'Follow the inherited platform rule.',
    immediate: 'Forward each paid invoice.',
    threshold: 'Hold invoices below an asset amount.',
    disabled: 'Hold future payouts; checkout remains enabled.',
}[mode] || '');

const effectiveBehavior = (policy) => {
    if (policy.effective.mode === 'threshold') {
        return `Forward each invoice at or above ${policy.effective.minimum_invoice_payout} ${policy.asset.symbol}.`;
    }

    return ({
        immediate: 'Forward every paid invoice separately.',
        manual: 'Hold each paid invoice for an authorized operator.',
        internal_balance_only: 'Credit each paid invoice to the internal merchant balance.',
        disabled: 'Hold future paid invoices without disabling checkout.',
    })[policy.effective.mode] || 'Held by platform policy.';
};

const walletReadiness = (policy) => {
    if (!policy.destination_wallet.required) return 'Not required by current mode';
    if (policy.destination_wallet.ready) return 'Merchant wallet ready';
    if (platformFallbackActive(policy)) return 'Merchant wallet missing · platform custody active';
    return 'Merchant wallet missing';
};

const platformFallbackActive = (policy) => (
    policy.destination_wallet.platform_fallback?.configured
    && policy.destination_wallet.platform_fallback?.allowed
);

const missingWalletMessage = (policy) => platformFallbackActive(policy)
    ? 'Platform custody fallback is enabled for forwarding, but it does not count as merchant wallet readiness.'
    : 'Future paid invoices will be held with reason destination_wallet_missing. A platform wallet is not treated as merchant readiness.';

const sourceLabel = (source) => ({
    registry: 'Asset registry',
    registry_default: 'Registry default',
    registry_config: 'Forwarding configuration',
    global_asset_policy: 'Global asset policy',
    merchant_admin_wildcard: 'Merchant-wide admin restriction',
    merchant_admin_exact: 'Asset-specific admin restriction',
    merchant_preference: 'Merchant preference',
}[source] || source);

const modeOptionTitle = (option) => option.available ? '' : ({
    operator_release_unavailable: 'Operator release workflow is not available.',
    admin_only_custodial_mode: 'This custodial mode is controlled by the platform administrator.',
    platform_policy_more_restrictive: 'Platform policy does not permit this mode.',
    missing_settlements_write: 'Your role cannot change settlement rules.',
}[option.reason_code] || 'This mode is unavailable.');

const confirmLeave = () => !anyDirty.value || window.confirm('Leave without saving settlement rule changes?');
const handleBeforeUnload = (event) => {
    if (!anyDirty.value) return;
    event.preventDefault();
    event.returnValue = '';
};

onBeforeRouteLeave(() => confirmLeave());
onMounted(() => {
    window.addEventListener('beforeunload', handleBeforeUnload);
    loadPolicies();
});
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
    if (noticeTimer) window.clearTimeout(noticeTimer);
});
</script>

<style scoped>
.rules-loading,
.rules-empty {
    min-height: 280px;
    display: grid;
    place-content: center;
    gap: 8px;
    text-align: center;
    color: var(--m-muted);
    border: 1px solid var(--m-border);
    border-radius: 8px;
    background: var(--m-surface);
}

.rules-loading span {
    width: 28px;
    height: 28px;
    margin: 0 auto;
    border: 3px solid var(--m-border);
    border-top-color: var(--m-brand-500);
    border-radius: 50%;
    animation: rules-spin 0.8s linear infinite;
}

.rules-empty strong { color: var(--m-text); }
.rules-empty p { margin: 0; }

.rules-desktop {
    min-height: 620px;
    display: grid;
    grid-template-columns: 264px minmax(0, 1fr);
    border: 1px solid var(--m-border);
    border-radius: 8px;
    background: var(--m-surface);
    overflow: hidden;
}

.asset-rail {
    padding: 18px 12px;
    border-right: 1px solid var(--m-border);
    background: var(--m-surface-subtle);
}

.asset-rail-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 8px 12px;
    color: var(--m-text);
}

.asset-rail-heading span { color: var(--m-muted); font-size: 12px; }

.asset-rail-item {
    width: 100%;
    min-height: 62px;
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    padding: 9px 10px;
    border: 1px solid transparent;
    border-radius: 7px;
    background: transparent;
    color: var(--m-text);
    text-align: left;
    cursor: pointer;
}

.asset-rail-item:hover { background: var(--m-surface-hover); }
.asset-rail-item.is-active { border-color: var(--m-brand-100); background: var(--m-brand-50); }
.asset-rail-item > span { min-width: 0; display: grid; gap: 3px; }
.asset-rail-item strong { overflow-wrap: anywhere; }
.asset-rail-item small { overflow: hidden; color: var(--m-muted); font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.asset-rail-item em { font-size: 10px; font-style: normal; font-weight: 750; }

.rule-editor { min-width: 0; padding: 24px 28px; }
.editor-title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; }
.editor-asset-title { min-width: 0; display: flex; align-items: center; gap: 12px; }
.editor-asset-title > div { min-width: 0; }
.editor-asset-title h3 { margin: 0; color: var(--m-text); font-size: 20px; }
.editor-asset-title p { margin: 4px 0 0; color: var(--m-muted); font-size: 12px; }
.editor-asset-title h3,
.editor-asset-title p { overflow-wrap: anywhere; }

.effective-badge {
    max-width: 42%;
    padding: 7px 10px;
    border-radius: 6px;
    background: var(--m-surface-subtle);
    font-size: 12px;
    font-weight: 750;
    overflow-wrap: anywhere;
    text-align: right;
}

.mode-immediate { color: var(--m-success-700); }
.mode-threshold { color: var(--m-warning-700); }
.mode-manual,
.mode-disabled { color: var(--m-danger-700); }
.mode-internal_balance_only { color: var(--m-info-700); }

.effective-strip {
    display: grid;
    grid-template-columns: 1.5fr 1fr 0.65fr;
    gap: 1px;
    margin-top: 22px;
    border: 1px solid var(--m-border);
    border-radius: 7px;
    background: var(--m-border);
    overflow: hidden;
}

.effective-strip div { min-width: 0; padding: 13px 14px; background: var(--m-surface-subtle); }
.effective-strip span,
.constraint-list span { display: block; color: var(--m-muted); font-size: 11px; font-weight: 650; }
.effective-strip strong { display: block; margin-top: 4px; color: var(--m-text); font-size: 13px; overflow-wrap: anywhere; }
.effective-strip .text-success { color: var(--m-success-700); }
.effective-strip .text-warning { color: var(--m-warning-700); }

.editor-section {
    display: grid;
    grid-template-columns: minmax(180px, 0.32fr) minmax(0, 0.68fr);
    gap: 26px;
    padding: 24px 0;
    border-bottom: 1px solid var(--m-border);
}

.section-copy h4,
.mobile-editor h3 { margin: 0; color: var(--m-text); font-size: 14px; }
.section-copy p,
.mobile-editor > section > p { margin: 6px 0 0; color: var(--m-muted); font-size: 12px; line-height: 1.5; }

.mode-control { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.mode-option {
    min-height: 70px;
    padding: 11px 12px;
    border: 1px solid var(--m-border);
    border-radius: 7px;
    background: var(--m-surface);
    color: var(--m-text);
    text-align: left;
    cursor: pointer;
}
.mode-option:hover:not(:disabled) { border-color: var(--m-brand-500); }
.mode-option.is-selected { border-color: var(--m-brand-500); background: var(--m-brand-50); box-shadow: inset 3px 0 0 var(--m-brand-500); }
.mode-option:disabled { cursor: not-allowed; opacity: 0.5; }
.mode-option strong,
.mode-option small { display: block; }
.mode-option small { margin-top: 5px; color: var(--m-muted); font-size: 11px; line-height: 1.35; }

.threshold-field { display: grid; gap: 7px; color: var(--m-text); font-size: 12px; font-weight: 700; }
.amount-input { display: grid; grid-template-columns: minmax(0, 1fr) auto; border: 1px solid var(--m-border-strong); border-radius: 7px; overflow: hidden; }
.amount-input:focus-within { border-color: var(--m-brand-500); box-shadow: 0 0 0 3px var(--m-brand-50); }
.amount-input input { min-width: 0; height: 44px; padding: 0 12px; border: 0; outline: 0; color: var(--m-text); background: var(--m-surface); font: inherit; }
.amount-input strong { display: grid; place-items: center; min-width: 62px; padding: 0 10px; border-left: 1px solid var(--m-border); background: var(--m-surface-subtle); }
.threshold-field small { color: var(--m-muted); font-weight: 500; }
.threshold-field em { color: var(--m-danger-700); font-size: 12px; font-style: normal; font-weight: 600; }

.constraint-list { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.constraint-list > div { padding: 11px; border-left: 2px solid var(--m-border-strong); background: var(--m-surface-subtle); }
.constraint-list > div strong { display: block; margin-top: 4px; color: var(--m-text); font-size: 12px; overflow-wrap: anywhere; }
.constraint-list > p { grid-column: 1 / -1; margin: 0; padding: 10px 12px; border-left: 3px solid var(--m-warning-500); background: var(--m-warning-50); color: var(--m-warning-700); font-size: 12px; }
.constraint-list > p strong,
.constraint-list > p span { display: block; }
.constraint-list > p span { margin-top: 3px; color: var(--m-warning-700); }

.wallet-warning {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-top: 18px;
    padding: 14px;
    border-left: 3px solid var(--m-warning-500);
    background: var(--m-warning-50);
}
.wallet-warning strong { color: var(--m-warning-700); font-size: 13px; }
.wallet-warning p { margin: 4px 0 0; color: var(--m-warning-700); font-size: 12px; line-height: 1.4; }

.readonly-note { grid-column: 2; margin: 0; color: var(--m-warning-700); font-size: 12px; }
.desktop-save-row { display: flex; align-items: center; justify-content: flex-end; gap: 16px; padding-top: 18px; }
.desktop-save-row span { color: var(--m-muted); font-size: 12px; }

.rules-mobile { display: none; }

@keyframes rules-spin { to { transform: rotate(360deg); } }

@media (max-width: 899px) {
    .rules-desktop { display: none; }
    .rules-mobile {
        width: calc(100% + 32px);
        min-width: 0;
        max-width: calc(100% + 32px);
        display: block;
        margin: 0 -16px;
        padding-bottom: 82px;
    }
    .mobile-asset-strip {
        width: 100%;
        min-width: 0;
        box-sizing: border-box;
        display: flex;
        gap: 8px;
        padding: 3px 16px 12px;
        overflow-x: auto;
        scrollbar-width: none;
    }
    .mobile-asset-strip::-webkit-scrollbar { display: none; }
    .mobile-asset-strip button {
        flex: 0 0 auto;
        min-width: 76px;
        height: 66px;
        display: grid;
        place-items: center;
        gap: 4px;
        padding: 7px;
        border: 1px solid var(--m-border);
        border-radius: 7px;
        background: var(--m-surface);
        color: var(--m-text);
        font-size: 11px;
        font-weight: 750;
    }
    .mobile-asset-strip button.is-active { border-color: var(--m-brand-500); background: var(--m-brand-50); color: var(--m-brand-600); }
    .mobile-policy-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 14px 16px;
        border-top: 1px solid var(--m-border);
        border-bottom: 1px solid var(--m-border);
        background: var(--m-surface-subtle);
    }
    .mobile-policy-summary div { min-width: 0; }
    .mobile-policy-summary span { display: block; color: var(--m-muted); font-size: 11px; }
    .mobile-policy-summary strong { display: block; margin-top: 3px; color: var(--m-text); font-size: 14px; }
    .mobile-policy-summary span,
    .mobile-policy-summary strong { overflow-wrap: anywhere; }
    .mobile-policy-summary > span { max-width: 42%; text-align: right; font-weight: 700; }
    .mobile-policy-summary .summary-ready { color: var(--m-success-700); }
    .mobile-policy-summary .summary-warning { color: var(--m-warning-700); }
    .mobile-editor { padding: 0 16px; }
    .mobile-editor > section { padding: 20px 0; border-bottom: 1px solid var(--m-border); }
    .mobile-mode-list { display: grid; gap: 8px; margin-top: 14px; }
    .mobile-mode-list button {
        min-height: 62px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 10px 12px;
        border: 1px solid var(--m-border);
        border-radius: 7px;
        background: var(--m-surface);
        color: var(--m-text);
        text-align: left;
    }
    .mobile-mode-list button.is-selected { border-color: var(--m-brand-500); background: var(--m-brand-50); }
    .mobile-mode-list button:disabled { opacity: 0.5; }
    .mobile-mode-list span,
    .mobile-mode-list strong,
    .mobile-mode-list small { display: block; }
    .mobile-mode-list small { margin-top: 4px; color: var(--m-muted); font-size: 11px; }
    .mobile-mode-list b { min-width: 18px; color: var(--m-brand-600); font-size: 16px; }
    .mobile-editor .threshold-field { margin-top: 14px; }
    .mobile-restriction p,
    .mobile-wallet-warning p { color: var(--m-warning-700); }
    .mobile-wallet-warning a { display: inline-block; margin-top: 10px; color: var(--m-brand-600); font-size: 12px; font-weight: 750; }
    .mobile-save-area {
        position: fixed;
        z-index: 25;
        right: 0;
        bottom: 54px;
        left: 0;
        min-height: 66px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 10px 16px;
        border-top: 1px solid var(--m-border);
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 -4px 12px rgba(16, 24, 40, 0.08);
    }
    .mobile-save-area span { color: var(--m-muted); font-size: 12px; }
    .mobile-save-area .btn { min-width: 96px; }
}

@media (max-width: 640px) {
    .rules-mobile {
        width: calc(100% + 24px);
        max-width: calc(100% + 24px);
        margin-right: -12px;
        margin-left: -12px;
    }
    .rules-header { align-items: flex-start; }
    .rules-header .page-actions { width: 100%; display: grid; grid-template-columns: 1fr 1fr; }
    .rules-header .page-actions .btn { width: 100%; }
    .rules-header .page-title { font-size: 24px; }
}

@media (prefers-reduced-motion: reduce) {
    .rules-loading span { animation: none; }
}
</style>
