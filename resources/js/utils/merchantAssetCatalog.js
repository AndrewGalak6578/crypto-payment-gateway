export const MERCHANT_ASSET_CATALOG = [
  {
    assetKey: 'btc',
    assetLabel: 'Bitcoin',
    symbol: 'BTC',
    networkKey: 'bitcoin',
    networkLabel: 'Bitcoin',
  },
  {
    assetKey: 'ltc',
    assetLabel: 'Litecoin',
    symbol: 'LTC',
    networkKey: 'litecoin',
    networkLabel: 'Litecoin',
  },
  {
    assetKey: 'dash',
    assetLabel: 'Dash',
    symbol: 'DASH',
    networkKey: 'dash',
    networkLabel: 'Dash',
  },
  {
    assetKey: 'eth_local',
    assetLabel: 'Ether (Local)',
    symbol: 'ETH',
    networkKey: 'evm_local',
    networkLabel: 'Local EVM',
  },
  {
    assetKey: 'eth_usdt_local',
    assetLabel: 'Tether USD (Local ERC-20)',
    symbol: 'USDT',
    networkKey: 'evm_local',
    networkLabel: 'Local EVM',
  },
];

export const findCatalogAsset = (assetKey) => {
  if (typeof assetKey !== 'string' || assetKey.trim() === '') {
    return null;
  }

  const normalized = assetKey.trim().toLowerCase();
  return MERCHANT_ASSET_CATALOG.find((item) => item.assetKey === normalized) || null;
};

export const assetOptionLabel = (assetKey) => {
  const item = findCatalogAsset(assetKey);
  if (!item) {
    return assetKey;
  }

  return `${item.assetLabel} (${item.symbol}) • ${item.assetKey} • ${item.networkLabel}`;
};
