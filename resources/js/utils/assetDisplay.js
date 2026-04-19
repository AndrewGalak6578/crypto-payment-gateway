import { findCatalogAsset } from './merchantAssetCatalog';

const LEGACY_NETWORK_BY_ASSET = {
  btc: 'bitcoin',
  ltc: 'litecoin',
  dash: 'dash',
};

const asString = (value) => (typeof value === 'string' ? value.trim() : '');

export const normalizeAssetKey = (item = {}) => {
  const assetKey = asString(item.asset_key).toLowerCase();
  if (assetKey) {
    return assetKey;
  }

  const coin = asString(item.coin).toLowerCase();
  return coin || '';
};

export const normalizeNetworkKey = (item = {}) => {
  const networkKey = asString(item.network_key).toLowerCase();
  if (networkKey) {
    return networkKey;
  }

  const assetKey = normalizeAssetKey(item);
  return LEGACY_NETWORK_BY_ASSET[assetKey] || '';
};

export const displayAssetKey = (item = {}) => normalizeAssetKey(item) || '—';

export const displayNetworkKey = (item = {}) => normalizeNetworkKey(item) || '—';

export const displayAssetNetwork = (item = {}) => {
  const asset = displayAssetKey(item);
  const network = displayNetworkKey(item);

  if (asset === '—' && network === '—') {
    return '—';
  }

  if (network === '—') {
    return asset;
  }

  if (asset === '—') {
    return network;
  }

  return `${asset} @ ${network}`;
};

export const displayAssetLabel = (item = {}) => {
  const assetKey = normalizeAssetKey(item);
  if (!assetKey) {
    return '—';
  }

  const catalog = findCatalogAsset(assetKey);
  return catalog ? `${catalog.assetLabel} (${catalog.symbol})` : assetKey;
};

export const displayNetworkLabel = (item = {}) => {
  const networkKey = normalizeNetworkKey(item);
  if (!networkKey) {
    return '—';
  }

  const assetKey = normalizeAssetKey(item);
  const catalog = findCatalogAsset(assetKey);

  if (catalog && catalog.networkKey === networkKey) {
    return catalog.networkLabel;
  }

  return networkKey;
};
