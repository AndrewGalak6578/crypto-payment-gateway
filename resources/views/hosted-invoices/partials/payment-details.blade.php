<div class="details-panel">
    <div class="detail-row">
        <span class="detail-label">Network</span>
        <span class="detail-value" data-network-label></span>
        <span></span>
    </div>

    <div class="detail-row">
        <span class="detail-label">Wallet address</span>
        <code class="detail-value detail-code" data-address></code>
        <button class="copy-icon-button" type="button" data-copy-kind="address" aria-label="Copy wallet address">Copy</button>
    </div>

    <div class="detail-row">
        <span class="detail-label">{{ $amountLabel }}</span>
        <span class="detail-value amount" data-{{ $copyKind === 'remaining' ? 'remaining' : 'crypto' }}-amount></span>
        <button class="copy-icon-button" type="button" data-copy-kind="{{ $copyKind }}" aria-label="Copy amount">Copy</button>
    </div>
</div>
