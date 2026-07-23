<template>
    <section class="page-stack settings-page">
        <header class="page-header settings-header">
            <div>
                <p class="page-kicker">Merchant settings</p>
                <h2 class="page-title">Settings</h2>
                <p class="page-subtitle">Set checkout defaults once, then reuse them when creating payment links.</p>
            </div>
            <div class="page-actions">
                <button class="btn btn-secondary" type="button" :disabled="loading" @click="loadSettings">
                    {{ loading ? 'Refreshing...' : 'Refresh' }}
                </button>
                <button class="btn btn-primary" type="submit" form="settings-form" :disabled="!canWriteSettings || saving">
                    {{ saving ? 'Saving...' : 'Save settings' }}
                </button>
            </div>
        </header>

        <div v-if="toast.message" class="settings-toast" :class="`settings-toast-${toast.type}`" role="status" aria-live="polite">
            <span></span>
            <div>
                <strong>{{ toast.type === 'danger' ? 'Settings failed' : 'Settings saved' }}</strong>
                <p>{{ toast.message }}</p>
            </div>
            <button type="button" aria-label="Dismiss notification" @click="clearToast">×</button>
        </div>

        <section class="settings-hero">
            <div>
                <p>Checkout profile</p>
                <strong>{{ form.checkout_display_name || profile.name || 'Merchant checkout' }}</strong>
                <small>{{ checkoutModeLabel }} · {{ expirationLabel }}</small>
            </div>
            <span class="settings-status" :class="profile.status === 'active' ? 'status-success' : 'status-warning'">
                {{ profile.status || 'unknown' }}
            </span>
        </section>

        <section class="settings-grid">
            <article class="card card-pad settings-card settings-profile-card">
                <div class="section-header">
                    <div>
                        <h3 class="card-title">Merchant profile</h3>
                        <p class="card-subtitle">Business identity used by portal and checkout surfaces.</p>
                    </div>
                </div>
                <div class="settings-facts">
                    <div>
                        <span>Merchant name</span>
                        <strong>{{ profile.name || '—' }}</strong>
                    </div>
                    <div>
                        <span>Account status</span>
                        <strong>{{ profile.status || '—' }}</strong>
                    </div>
                    <div>
                        <span>Platform fee</span>
                        <strong>{{ billing.fee_percent ?? 0 }}%</strong>
                    </div>
                </div>
                <p class="settings-note">Profile status and platform fee are controlled by the platform administrator.</p>
            </article>

            <form id="settings-form" class="card card-pad settings-card settings-form-card" @submit.prevent="saveSettings">
                <div class="section-header">
                    <div>
                        <h3 class="card-title">Checkout defaults</h3>
                        <p class="card-subtitle">These values prefill new payment links and keep payer flow consistent.</p>
                    </div>
                    <span class="status-badge" :class="canWriteSettings ? 'status-success' : 'status-neutral'">
                        {{ canWriteSettings ? 'Editable' : 'Read only' }}
                    </span>
                </div>

                <section class="settings-section">
                    <span class="settings-step">01</span>
                    <div>
                        <h4>Identity</h4>
                        <p>Make the hosted checkout recognizable to the payer.</p>
                    </div>
                    <div class="settings-fields">
                        <label class="field">
                            <span class="field-label">Checkout display name</span>
                            <input v-model.trim="form.checkout_display_name" class="input" :readonly="!canWriteSettings" placeholder="Merchant checkout" />
                            <span v-if="fieldErrors.checkout_display_name" class="field-error">{{ fieldErrors.checkout_display_name }}</span>
                        </label>
                        <label class="field">
                            <span class="field-label">Support email</span>
                            <input v-model.trim="form.checkout_support_email" class="input" type="email" :readonly="!canWriteSettings" placeholder="support@example.com" />
                            <span v-if="fieldErrors.checkout_support_email" class="field-error">{{ fieldErrors.checkout_support_email }}</span>
                        </label>
                        <label class="field">
                            <span class="field-label">Brand color</span>
                            <div class="color-row">
                                <input v-model="form.checkout_brand_color" class="color-input" type="color" :disabled="!canWriteSettings" />
                                <input v-model.trim="form.checkout_brand_color" class="input" :readonly="!canWriteSettings" placeholder="#246bfe" />
                            </div>
                            <span v-if="fieldErrors.checkout_brand_color" class="field-error">{{ fieldErrors.checkout_brand_color }}</span>
                        </label>
                    </div>
                </section>

                <section class="settings-section">
                    <span class="settings-step">02</span>
                    <div>
                        <h4>Payment assets</h4>
                        <p>Choose which assets payers can select on hosted checkout.</p>
                    </div>
                    <div class="field">
                        <span class="field-label">Allowed assets</span>
                        <div class="asset-toggle-grid">
                            <button
                                v-for="asset in assetOptions"
                                :key="asset.assetKey"
                                class="asset-toggle"
                                :class="{ 'is-selected': form.checkout_allowed_assets.includes(asset.assetKey) }"
                                type="button"
                                :disabled="!canWriteSettings"
                                @click="toggleAllowedAsset(asset.assetKey)"
                            >
                                <strong>{{ asset.symbol }}</strong>
                                <span>{{ asset.networkLabel }}</span>
                            </button>
                        </div>
                        <p class="field-help">Leave all unselected to allow every supported asset.</p>
                        <span v-if="fieldErrors.checkout_allowed_assets" class="field-error">{{ fieldErrors.checkout_allowed_assets }}</span>
                    </div>
                </section>

                <section class="settings-section">
                    <span class="settings-step">03</span>
                    <div>
                        <h4>Expiration and redirects</h4>
                        <p>Prefill operational defaults for payment links.</p>
                    </div>
                    <div class="settings-fields">
                        <label class="field">
                            <span class="field-label">Default expiration minutes</span>
                            <input v-model="form.checkout_expires_minutes" class="input" type="number" min="1" max="240" :readonly="!canWriteSettings" placeholder="Backend default" />
                            <span v-if="fieldErrors.checkout_expires_minutes" class="field-error">{{ fieldErrors.checkout_expires_minutes }}</span>
                        </label>
                        <label class="field">
                            <span class="field-label">Success URL</span>
                            <input v-model.trim="form.checkout_success_url" class="input" type="url" :readonly="!canWriteSettings" placeholder="https://merchant.example/success" />
                            <span v-if="fieldErrors.checkout_success_url" class="field-error">{{ fieldErrors.checkout_success_url }}</span>
                        </label>
                        <label class="field">
                            <span class="field-label">Cancel URL</span>
                            <input v-model.trim="form.checkout_cancel_url" class="input" type="url" :readonly="!canWriteSettings" placeholder="https://merchant.example/cancel" />
                            <span v-if="fieldErrors.checkout_cancel_url" class="field-error">{{ fieldErrors.checkout_cancel_url }}</span>
                        </label>
                        <label class="field">
                            <span class="field-label">Redirect delay seconds</span>
                            <input v-model="form.checkout_redirect_delay_seconds" class="input" type="number" min="0" max="30" :readonly="!canWriteSettings" />
                            <span v-if="fieldErrors.checkout_redirect_delay_seconds" class="field-error">{{ fieldErrors.checkout_redirect_delay_seconds }}</span>
                        </label>
                    </div>
                    <label class="settings-toggle">
                        <input v-model="form.checkout_auto_redirect" type="checkbox" :disabled="!canWriteSettings" />
                        <span>
                            <strong>Auto redirect after paid</strong>
                            <small>Return payers to the success URL after completion.</small>
                        </span>
                    </label>
                </section>

                <section class="settings-section">
                    <span class="settings-step">04</span>
                    <div>
                        <h4>Checkout behavior</h4>
                        <p>Control what the payer sees and how exceptions are presented.</p>
                    </div>
                    <div class="settings-fields">
                        <div class="toggle-grid">
                            <label class="settings-toggle">
                                <input v-model="form.checkout_show_invoice_id" type="checkbox" :disabled="!canWriteSettings" />
                                <span>
                                    <strong>Show invoice ID</strong>
                                    <small>Useful for payer support and reconciliation.</small>
                                </span>
                            </label>
                            <label class="settings-toggle">
                                <input v-model="form.checkout_show_support_email" type="checkbox" :disabled="!canWriteSettings" />
                                <span>
                                    <strong>Show support email</strong>
                                    <small>Display contact help on the hosted checkout.</small>
                                </span>
                            </label>
                        </div>
                        <div class="settings-fields two-columns">
                            <label class="field">
                                <span class="field-label">Partial payment policy</span>
                                <select v-model="form.checkout_partial_payment_policy" class="input" :disabled="!canWriteSettings">
                                    <option value="allow_top_up">Allow payer top-up</option>
                                    <option value="support_required">Ask payer to contact support</option>
                                    <option value="expire_on_partial">Expire if underpaid</option>
                                </select>
                            </label>
                            <label class="field">
                                <span class="field-label">Confirmation display</span>
                                <select v-model="form.checkout_confirmation_display" class="input" :disabled="!canWriteSettings">
                                    <option value="simple">Simple status</option>
                                    <option value="show_confirmations">Show confirmation progress</option>
                                </select>
                            </label>
                        </div>
                        <div class="settings-fields two-columns">
                            <label class="field">
                                <span class="field-label">Minimum amount USD</span>
                                <input v-model="form.checkout_min_amount_usd" class="input" type="number" min="0.01" step="0.01" :readonly="!canWriteSettings" placeholder="No minimum" />
                                <span v-if="fieldErrors.checkout_min_amount_usd" class="field-error">{{ fieldErrors.checkout_min_amount_usd }}</span>
                            </label>
                            <label class="field">
                                <span class="field-label">Maximum amount USD</span>
                                <input v-model="form.checkout_max_amount_usd" class="input" type="number" min="0.01" step="0.01" :readonly="!canWriteSettings" placeholder="No maximum" />
                                <span v-if="fieldErrors.checkout_max_amount_usd" class="field-error">{{ fieldErrors.checkout_max_amount_usd }}</span>
                            </label>
                        </div>
                    </div>
                </section>

                <div class="settings-actions">
                    <button class="btn btn-primary" type="submit" :disabled="!canWriteSettings || saving">
                        {{ saving ? 'Saving...' : 'Save settings' }}
                    </button>
                    <button class="btn btn-secondary" type="button" :disabled="loading" @click="applySettings(settingsPayload)">
                        Reset changes
                    </button>
                </div>
            </form>

            <aside class="settings-side">
                <article class="card card-pad checkout-preview" :style="{ '--preview-brand': form.checkout_brand_color || '#246bfe' }">
                    <div class="preview-brand-dot"></div>
                    <p>Hosted checkout</p>
                    <h3>{{ form.checkout_display_name || profile.name || 'Merchant checkout' }}</h3>
                    <strong>Pay $10.00</strong>
                    <span>{{ checkoutModeLabel }}</span>
                    <div class="preview-detail">
                        <small>Expires</small>
                        <b>{{ expirationLabel }}</b>
                    </div>
                    <div class="preview-detail">
                        <small>Redirects</small>
                        <b>{{ redirectLabel }}</b>
                    </div>
                </article>

                <article class="card card-pad settings-card">
                    <h3 class="card-title">Applied to new links</h3>
                    <p class="card-subtitle">Existing invoices keep their original metadata and expiration.</p>
                    <div class="settings-checklist">
                        <span>Checkout form prefill</span>
                        <span>Hosted page merchant label</span>
                        <span>Default redirects metadata</span>
                    </div>
                </article>
            </aside>
        </section>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useAuthStore } from '../../../stores/auth';
import { MERCHANT_ASSET_CATALOG } from '../../../utils/merchantAssetCatalog';
import { merchantApi } from '../../services/merchantApi';

const authStore = useAuthStore();
const loading = ref(true);
const saving = ref(false);
const settingsPayload = ref(null);
const profile = reactive({ id: null, name: '', status: '' });
const billing = reactive({ fee_percent: 0 });
const toast = reactive({ message: '', type: 'success' });
const fieldErrors = reactive({});
let toastTimer = null;

const form = reactive({
    checkout_display_name: '',
    checkout_support_email: '',
    checkout_brand_color: '#246bfe',
    checkout_expires_minutes: '',
    checkout_payer_can_choose_asset: true,
    checkout_default_asset: '',
    checkout_allowed_assets: [],
    checkout_success_url: '',
    checkout_cancel_url: '',
    checkout_auto_redirect: true,
    checkout_redirect_delay_seconds: 5,
    checkout_show_invoice_id: true,
    checkout_show_support_email: true,
    checkout_partial_payment_policy: 'allow_top_up',
    checkout_confirmation_display: 'simple',
    checkout_min_amount_usd: '',
    checkout_max_amount_usd: '',
});

const allAssetOptions = MERCHANT_ASSET_CATALOG.filter((asset) => asset.assetKey);
const assetOptions = computed(() => {
    const available = settingsPayload.value?.checkout?.available_assets;
    if (!Array.isArray(available)) return allAssetOptions;

    return allAssetOptions.filter((asset) => available.includes(asset.assetKey));
});
const canWriteSettings = computed(() => authStore.hasCapability('invoices.write'));
const checkoutModeLabel = computed(() => (form.checkout_allowed_assets.length ? `${form.checkout_allowed_assets.length} allowed assets` : 'All supported assets'));
const expirationLabel = computed(() => (form.checkout_expires_minutes ? `${form.checkout_expires_minutes} minutes` : 'Backend default'));
const redirectLabel = computed(() => [form.checkout_success_url && 'success', form.checkout_cancel_url && 'cancel'].filter(Boolean).join(', ') || 'None');

const toggleAllowedAsset = (assetKey) => {
    const index = form.checkout_allowed_assets.indexOf(assetKey);
    if (index >= 0) {
        form.checkout_allowed_assets.splice(index, 1);
    } else {
        form.checkout_allowed_assets.push(assetKey);
    }
};

const clearToast = () => {
    toast.message = '';
    if (toastTimer) window.clearTimeout(toastTimer);
    toastTimer = null;
};

const showToast = (message, type = 'success') => {
    toast.message = message;
    toast.type = type;
    if (toastTimer) window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(clearToast, 3200);
};

const clearFieldErrors = () => Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key]);
const setFieldErrors = (errors = {}) => {
    clearFieldErrors();
    Object.entries(errors).forEach(([key, messages]) => {
        fieldErrors[key] = Array.isArray(messages) ? messages[0] : String(messages);
    });
};

const applySettings = (payload) => {
    if (!payload) return;
    settingsPayload.value = payload;
    Object.assign(profile, payload.profile || {});
    Object.assign(billing, payload.billing || {});

    const checkout = payload.checkout || {};
    form.checkout_display_name = checkout.display_name || '';
    form.checkout_support_email = checkout.support_email || '';
    form.checkout_brand_color = checkout.brand_color || '#246bfe';
    form.checkout_expires_minutes = checkout.expires_minutes || '';
    form.checkout_payer_can_choose_asset = true;
    form.checkout_default_asset = '';
    form.checkout_allowed_assets = Array.isArray(checkout.allowed_assets) ? [...checkout.allowed_assets] : [];
    form.checkout_success_url = checkout.success_url || '';
    form.checkout_cancel_url = checkout.cancel_url || '';
    form.checkout_auto_redirect = checkout.auto_redirect !== false;
    form.checkout_redirect_delay_seconds = checkout.redirect_delay_seconds ?? 5;
    form.checkout_show_invoice_id = checkout.show_invoice_id !== false;
    form.checkout_show_support_email = checkout.show_support_email !== false;
    form.checkout_partial_payment_policy = checkout.partial_payment_policy || 'allow_top_up';
    form.checkout_confirmation_display = checkout.confirmation_display || 'simple';
    form.checkout_min_amount_usd = checkout.min_amount_usd ?? '';
    form.checkout_max_amount_usd = checkout.max_amount_usd ?? '';
    clearFieldErrors();
};

const loadSettings = async () => {
    loading.value = true;
    try {
        const response = await merchantApi.settings();
        applySettings(response.data?.data || {});
    } catch (exception) {
        showToast(exception?.response?.data?.message || 'Failed to load settings.', 'danger');
    } finally {
        loading.value = false;
    }
};

const saveSettings = async () => {
    if (!canWriteSettings.value) return;
    saving.value = true;
    clearFieldErrors();

    try {
        const payload = {
            checkout_display_name: form.checkout_display_name || null,
            checkout_support_email: form.checkout_support_email || null,
            checkout_brand_color: form.checkout_brand_color || null,
            checkout_expires_minutes: form.checkout_expires_minutes ? Number(form.checkout_expires_minutes) : null,
            checkout_payer_can_choose_asset: true,
            checkout_default_asset: null,
            checkout_allowed_assets: form.checkout_allowed_assets,
            checkout_success_url: form.checkout_success_url || null,
            checkout_cancel_url: form.checkout_cancel_url || null,
            checkout_auto_redirect: Boolean(form.checkout_auto_redirect),
            checkout_redirect_delay_seconds: Number(form.checkout_redirect_delay_seconds ?? 5),
            checkout_show_invoice_id: Boolean(form.checkout_show_invoice_id),
            checkout_show_support_email: Boolean(form.checkout_show_support_email),
            checkout_partial_payment_policy: form.checkout_partial_payment_policy,
            checkout_confirmation_display: form.checkout_confirmation_display,
            checkout_min_amount_usd: form.checkout_min_amount_usd ? Number(form.checkout_min_amount_usd) : null,
            checkout_max_amount_usd: form.checkout_max_amount_usd ? Number(form.checkout_max_amount_usd) : null,
        };
        const response = await merchantApi.updateSettings(payload);
        applySettings(response.data?.data || {});
        showToast('Checkout defaults updated.');
    } catch (exception) {
        setFieldErrors(exception?.response?.data?.errors || {});
        showToast(exception?.response?.data?.message || 'Failed to save settings.', 'danger');
    } finally {
        saving.value = false;
    }
};

onMounted(loadSettings);
onBeforeUnmount(() => {
    if (toastTimer) window.clearTimeout(toastTimer);
});
</script>

<style scoped>
.settings-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 380px;
    gap: 20px;
    align-items: start;
}

.settings-form-card,
.settings-side {
    display: grid;
    gap: 18px;
}

.settings-profile-card {
    grid-column: 1 / -1;
}

.settings-facts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.settings-facts div,
.preview-detail {
    padding: 14px;
    border: 1px solid var(--m-border);
    border-radius: 14px;
    background: var(--m-surface-subtle);
}

.settings-facts span,
.preview-detail small {
    display: block;
    color: var(--m-muted);
    font-size: 12px;
    font-weight: 650;
}

.settings-facts strong,
.preview-detail b {
    display: block;
    margin-top: 4px;
    color: var(--m-text);
    font-size: 15px;
    overflow-wrap: anywhere;
}

.settings-note {
    margin: 14px 0 0;
    color: var(--m-muted);
    font-size: 13px;
}

.settings-section {
    display: grid;
    grid-template-columns: 34px minmax(170px, 0.35fr) minmax(0, 0.65fr);
    gap: 14px;
    padding: 18px 0;
    border-top: 1px solid var(--m-border);
}

.settings-section:first-of-type {
    border-top: 0;
}

.settings-step {
    width: 30px;
    height: 30px;
    border-radius: 999px;
    display: grid;
    place-items: center;
    background: var(--m-brand-50);
    color: var(--m-brand-700, #004eeb);
    font-size: 12px;
    font-weight: 800;
}

.settings-section h4 {
    margin: 0;
    color: var(--m-text);
    font-size: 15px;
}

.settings-section p {
    margin: 5px 0 0;
    color: var(--m-muted);
    font-size: 13px;
    line-height: 1.4;
}

.settings-fields,
.settings-actions {
    display: grid;
    gap: 12px;
}

.settings-fields.two-columns,
.toggle-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.settings-section > .settings-toggle {
    grid-column: 3;
}

.field-error {
    color: var(--m-danger-700);
    font-size: 12px;
}

.color-row {
    display: grid;
    grid-template-columns: 48px 1fr;
    gap: 8px;
}

.color-input {
    width: 48px;
    height: 42px;
    border: 1px solid var(--m-border);
    border-radius: 12px;
    background: var(--m-surface);
}

.choice-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}

.choice-card {
    min-height: 92px;
    display: flex;
    gap: 10px;
    padding: 14px;
    border: 1px solid var(--m-border);
    border-radius: 16px;
    background: var(--m-surface);
    cursor: pointer;
}

.choice-card.is-selected {
    border-color: var(--m-brand-500);
    background: var(--m-brand-50);
}

.choice-card strong,
.checkout-preview strong {
    display: block;
    color: var(--m-text);
    font-size: 14px;
}

.choice-card small {
    display: block;
    margin-top: 4px;
    color: var(--m-muted);
    font-size: 12px;
    line-height: 1.4;
}

.asset-toggle-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
}

.asset-toggle {
    min-height: 64px;
    padding: 10px;
    border: 1px solid var(--m-border);
    border-radius: 14px;
    background: var(--m-surface);
    text-align: left;
}

.asset-toggle.is-selected {
    border-color: var(--m-success-500);
    background: var(--m-success-50);
    box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.12);
}

.asset-toggle strong,
.asset-toggle span {
    display: block;
}

.asset-toggle strong {
    color: var(--m-text);
    font-size: 13px;
}

.asset-toggle span {
    margin-top: 3px;
    color: var(--m-muted);
    font-size: 11px;
}

.settings-toggle {
    min-height: 70px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 14px;
    border: 1px solid var(--m-border);
    border-radius: 16px;
    background: var(--m-surface);
}

.settings-toggle input {
    margin-top: 3px;
}

.settings-toggle strong,
.settings-toggle small {
    display: block;
}

.settings-toggle strong {
    color: var(--m-text);
    font-size: 13px;
}

.settings-toggle small {
    margin-top: 4px;
    color: var(--m-muted);
    font-size: 12px;
    line-height: 1.35;
}

.settings-actions {
    grid-template-columns: auto auto;
    justify-content: end;
}

.settings-side {
    position: sticky;
    top: calc(var(--m-topbar-height) + 24px);
}

.checkout-preview {
    position: relative;
    overflow: hidden;
    min-height: 360px;
    background:
        radial-gradient(circle at 12% 0%, color-mix(in srgb, var(--preview-brand) 18%, transparent), transparent 34%),
        linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.preview-brand-dot {
    width: 42px;
    height: 42px;
    border-radius: 16px;
    background: var(--preview-brand);
    box-shadow: 0 14px 28px color-mix(in srgb, var(--preview-brand) 22%, transparent);
}

.checkout-preview p {
    margin: 22px 0 0;
    color: var(--m-muted);
    font-size: 12px;
    font-weight: 750;
}

.checkout-preview h3 {
    margin: 5px 0 22px;
    color: var(--m-text);
    font-size: 24px;
    line-height: 1.15;
}

.checkout-preview strong {
    font-size: 28px;
}

.checkout-preview > span {
    display: block;
    margin: 6px 0 18px;
    color: var(--m-muted);
    font-size: 13px;
}

.preview-detail + .preview-detail {
    margin-top: 10px;
}

.settings-checklist {
    display: grid;
    gap: 10px;
    margin-top: 16px;
}

.settings-checklist span {
    min-height: 38px;
    display: flex;
    align-items: center;
    padding: 0 12px;
    border-radius: 12px;
    background: var(--m-success-50);
    color: var(--m-success-700);
    font-size: 13px;
    font-weight: 700;
}

.settings-hero {
    display: none;
}

.settings-toast {
    position: fixed;
    right: 24px;
    top: calc(var(--m-topbar-height, 70px) + 18px);
    z-index: 90;
    width: min(380px, calc(100vw - 32px));
    display: grid;
    grid-template-columns: 10px 1fr auto;
    gap: 12px;
    align-items: start;
    padding: 14px;
    border: 1px solid rgba(214, 221, 232, 0.95);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.96);
    box-shadow: 0 18px 46px rgba(16, 24, 40, 0.18);
    backdrop-filter: blur(14px);
    overflow: hidden;
}

.settings-toast::before {
    content: '';
    position: absolute;
    inset: -40px auto auto -42px;
    width: 118px;
    height: 118px;
    border-radius: 999px;
    background: rgba(22, 163, 74, 0.18);
    filter: blur(18px);
    pointer-events: none;
}

.settings-toast-danger::before {
    background: rgba(240, 68, 56, 0.14);
}

.settings-toast > * {
    position: relative;
    z-index: 1;
}

.settings-toast > span {
    width: 10px;
    height: 10px;
    margin-top: 5px;
    border-radius: 999px;
    background: var(--m-success-500);
}

.settings-toast-danger > span {
    background: var(--m-danger-500);
}

.settings-toast strong {
    display: block;
    color: var(--m-text);
    font-size: 13px;
    line-height: 1.2;
}

.settings-toast p {
    margin: 4px 0 0;
    color: var(--m-muted);
    font-size: 13px;
    line-height: 1.35;
}

.settings-toast button {
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 999px;
    background: var(--m-surface-subtle);
    color: var(--m-muted);
}

.settings-status {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    padding: 0 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    text-transform: capitalize;
}

@media (max-width: 1023px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }

    .settings-side {
        position: static;
    }

    .settings-section {
        grid-template-columns: 34px 1fr;
    }

    .settings-section > .settings-fields,
    .settings-section > .choice-grid,
    .settings-section > .field,
    .settings-section > .settings-toggle {
        grid-column: 1 / -1;
    }
}

@media (max-width: 767px) {
    .settings-header {
        display: none;
    }

    .settings-hero {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        align-items: center;
        padding: 20px;
        border: 1px solid var(--m-border);
        border-radius: 26px;
        background:
            radial-gradient(circle at 12% 0%, rgba(36, 107, 254, 0.18), transparent 30%),
            linear-gradient(145deg, #ffffff 0%, #f8fbff 100%);
        box-shadow: 0 16px 34px rgba(16, 24, 40, 0.1);
    }

    .settings-hero p {
        margin: 0;
        color: var(--m-muted);
        font-size: 12px;
        font-weight: 700;
    }

    .settings-hero strong {
        display: block;
        margin-top: 4px;
        color: var(--m-text);
        font-size: 21px;
        line-height: 1.12;
    }

    .settings-hero small {
        display: block;
        margin-top: 5px;
        color: var(--m-muted);
        font-size: 12px;
    }

    .settings-status {
        flex: 0 0 auto;
    }

    .settings-toast {
        top: calc(var(--m-topbar-height, 70px) + 10px);
        right: 12px;
        left: 14px;
        width: auto;
        border-radius: 22px;
    }

    .settings-profile-card,
    .settings-form-card,
    .settings-side .card {
        border-radius: 24px;
        box-shadow: 0 12px 30px rgba(16, 24, 40, 0.08);
    }

    .settings-facts,
    .choice-grid,
    .asset-toggle-grid,
    .settings-fields.two-columns,
    .toggle-grid,
    .settings-actions {
        grid-template-columns: 1fr;
    }

    .settings-section {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .settings-step {
        width: 34px;
        height: 34px;
    }

    .settings-actions .btn {
        width: 100%;
    }
}
</style>
