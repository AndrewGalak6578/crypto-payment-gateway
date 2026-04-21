<?php
declare(strict_types=1);

namespace App\Services\PaymentAddresses\Evm;

use App\Contracts\EvmAddressDeriverInterface;
use App\Services\Evm\EvmRpcClient;
use App\Support\Chains\ChainRegistry;
use RuntimeException;

class LocalHdMnemonicEvmDeriver implements EvmAddressDeriverInterface
{
    private const SECP256K1_ORDER_HEX = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
    private const SECP256K1_ORDER_COMPLEMENT_HEX = '14551231950b75fc4402da1732fc9bebf';

    public function __construct(
        private readonly ChainRegistry $chains,
    )
    {
    }

    public function derive(string $networkKey, string $keyRef, int $index, string $pathTemplate): DerivedAddressResult
    {
        $this->assertLocalEnvironment();
        $rootConfig = $this->resolveRootConfig($keyRef, $pathTemplate);
        $mnemonic = $rootConfig['mnemonic'];
        $passphrase = $rootConfig['passphrase'];
        $effectivePathTemplate = $rootConfig['pathTemplate'];
        $derivationPath = $this->formatDerivationPath($effectivePathTemplate, $index);

        try {
            $seed = hash_pbkdf2('sha512', $mnemonic, 'mnemonic' . $passphrase, 2048, 64, true);
            $privateKey = $this->derivePrivateKeyFromPath($seed, $derivationPath);
            $address = $this->privateKeyToAddress($networkKey, $privateKey);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Local HD derivation failed for [{$networkKey}] at path [{$derivationPath}] index [{$index}].",
                previous: $e
            );
        }

        if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            throw new RuntimeException(
                "Local HD deriver produced invalid address [{$address}] for [{$networkKey}] at path [{$derivationPath}]."
            );
        }

        return new DerivedAddressResult(
            address: $address,
            derivationPath: $derivationPath,
            derivationIndex: $index,
            keyRef: $keyRef,
            meta: [
                'source' => 'local_hd_mnemonic',
                'temporary' => true,
                'local_testing_only' => true,
                'root_scope' => $rootConfig['rootScope'],
                'single_root_fallback' => $rootConfig['singleRootFallback'],
                'root_scope_warning' => $rootConfig['rootScopeWarning'],
            ]
        );
    }

    /**
     * @return array{
     *     mnemonic: string,
     *     passphrase: string,
     *     pathTemplate: string,
     *     rootScope: string,
     *     singleRootFallback: bool,
     *     rootScopeWarning: ?string
     * }
     */
    private function resolveRootConfig(string $keyRef, string $pathTemplate): array
    {
        $keyRefRoots = config('payment_addresses.evm.local_hd_key_refs', []);
        if (is_array($keyRefRoots) && array_key_exists($keyRef, $keyRefRoots) && is_array($keyRefRoots[$keyRef])) {
            $root = $keyRefRoots[$keyRef];
            $mnemonic = trim((string) ($root['mnemonic'] ?? ''));
            if ($mnemonic === '') {
                throw new RuntimeException(
                    "Missing payment_addresses.evm.local_hd_key_refs[{$keyRef}].mnemonic for local HD derivation."
                );
            }

            $keyRefPathTemplate = trim((string) ($root['path_template'] ?? ''));
            $globalPathTemplate = trim((string) config('payment_addresses.evm.local_hd_path_template', ''));

            return [
                'mnemonic' => $mnemonic,
                'passphrase' => (string) ($root['passphrase'] ?? ''),
                'pathTemplate' => $keyRefPathTemplate !== ''
                    ? $keyRefPathTemplate
                    : ($globalPathTemplate !== '' ? $globalPathTemplate : $pathTemplate),
                'rootScope' => 'per_key_ref',
                'singleRootFallback' => false,
                'rootScopeWarning' => null,
            ];
        }

        $mnemonic = trim((string) config('payment_addresses.evm.local_hd_mnemonic', ''));
        if ($mnemonic === '') {
            throw new RuntimeException(
                "No per-key local HD root configured for key_ref [{$keyRef}] and payment_addresses.evm.local_hd_mnemonic is empty."
            );
        }

        $templateOverride = trim((string) config('payment_addresses.evm.local_hd_path_template', ''));

        // Single-root fallback is explicit local mode and does not provide isolated root material per key_ref.
        return [
            'mnemonic' => $mnemonic,
            'passphrase' => (string) config('payment_addresses.evm.local_hd_passphrase', ''),
            'pathTemplate' => $templateOverride !== '' ? $templateOverride : $pathTemplate,
            'rootScope' => 'single_root_fallback',
            'singleRootFallback' => true,
            'rootScopeWarning' => 'Different key_ref values share one mnemonic root unless payment_addresses.evm.local_hd_key_refs is configured.',
        ];
    }

    protected function makeRpcClient(string $networkKey): object
    {
        $chain = $this->chains->get($networkKey);
        $rpcUrl = (string) ($chain['rpc_url'] ?? '');

        if ($rpcUrl === '') {
            throw new RuntimeException("Missing rpc_url for EVM network [{$networkKey}]");
        }

        return new EvmRpcClient($rpcUrl);
    }

    private function assertLocalEnvironment(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalHdMnemonicEvmDeriver is only available in local/testing environments.');
        }
    }

    private function formatDerivationPath(string $template, int $index): string
    {
        if (str_contains($template, '%d')) {
            $path = sprintf($template, $index);
        } else {
            $path = $template;
        }

        $path = trim($path);
        $this->assertValidPath($path);

        return $path;
    }

    private function assertValidPath(string $path): void
    {
        if (!preg_match("/^m(\\/[0-9]+'?)*$/", $path)) {
            throw new RuntimeException("Invalid EVM derivation path [{$path}]");
        }
    }

    /**
     * @return array<int, array{index: int, hardened: bool}>
     */
    private function parsePath(string $path): array
    {
        $parts = explode('/', $path);
        if (($parts[0] ?? '') !== 'm') {
            throw new RuntimeException("Unsupported derivation path [{$path}]");
        }

        $segments = [];
        foreach (array_slice($parts, 1) as $segment) {
            $hardened = str_ends_with($segment, "'");
            $number = $hardened ? substr($segment, 0, -1) : $segment;

            if ($number === '' || !ctype_digit($number)) {
                throw new RuntimeException("Invalid derivation path segment [{$segment}]");
            }

            $value = (int) $number;
            if ($value < 0 || $value > 2147483647) {
                throw new RuntimeException("Derivation path segment out of range [{$segment}]");
            }

            $segments[] = [
                'index' => $value,
                'hardened' => $hardened,
            ];
        }

        return $segments;
    }

    private function derivePrivateKeyFromPath(string $seed, string $path): string
    {
        if (strlen($seed) !== 64) {
            throw new RuntimeException('Invalid BIP39 seed length.');
        }

        $master = hash_hmac('sha512', $seed, 'Bitcoin seed', true);
        $privateKey = substr($master, 0, 32);
        $chainCode = substr($master, 32, 32);

        if (strlen($privateKey) !== 32 || strlen($chainCode) !== 32) {
            throw new RuntimeException('Invalid master key material length.');
        }

        $curveOrder = hex2bin(self::SECP256K1_ORDER_HEX);
        if ($curveOrder === false) {
            throw new RuntimeException('Unable to decode secp256k1 curve order.');
        }

        foreach ($this->parsePath($path) as $segment) {
            $childIndex = $segment['hardened']
                ? ($segment['index'] + 0x80000000)
                : $segment['index'];

            $data = $segment['hardened']
                ? ("\x00" . $privateKey . pack('N', $childIndex))
                : ($this->compressedPublicKey($privateKey) . pack('N', $childIndex));

            $intermediary = hash_hmac('sha512', $data, $chainCode, true);
            $left = substr($intermediary, 0, 32);
            $chainCode = substr($intermediary, 32, 32);

            if ($this->compare32($left, $curveOrder) >= 0) {
                throw new RuntimeException('Derived child key is outside secp256k1 order.');
            }

            $privateKey = $this->addModuloCurveOrder($privateKey, $left, $curveOrder);
            if ($privateKey === str_repeat("\x00", 32)) {
                throw new RuntimeException('Derived child key is zero.');
            }
        }

        return $privateKey;
    }

    private function addModuloCurveOrder(string $key, string $addend, string $curveOrder): string
    {
        [$sum, $carry] = $this->add32($key, $addend);

        if ($carry === 0) {
            if ($this->compare32($sum, $curveOrder) >= 0) {
                return $this->sub32($sum, $curveOrder);
            }

            return $sum;
        }

        if ($carry !== 1) {
            throw new RuntimeException('add32 returned unsupported carry value.');
        }

        // For overflow, (key + addend) = sum + 2^256. Reduce by secp256k1 order as:
        // sum + (2^256 - n), where n is the curve order.
        [$reduced, $extraCarry] = $this->add32($sum, $this->curveOrderComplement32());
        if ($extraCarry !== 0) {
            throw new RuntimeException('Complement-based modulo reduction overflowed unexpectedly.');
        }

        return $reduced;
    }

    private function curveOrderComplement32(): string
    {
        return $this->leftPadHexTo32Bytes(self::SECP256K1_ORDER_COMPLEMENT_HEX);
    }

    private function leftPadHexTo32Bytes(string $hex): string
    {
        $normalized = strtolower(trim($hex));
        if ($normalized === '' || preg_match('/[^0-9a-f]/', $normalized) === 1) {
            throw new RuntimeException('Invalid hexadecimal input.');
        }

        if ((strlen($normalized) % 2) === 1) {
            $normalized = '0' . $normalized;
        }

        if (strlen($normalized) > 64) {
            throw new RuntimeException('Hexadecimal input exceeds 32 bytes.');
        }

        $normalized = str_pad($normalized, 64, '0', STR_PAD_LEFT);
        $bytes = hex2bin($normalized);
        if ($bytes === false) {
            throw new RuntimeException('Unable to decode hexadecimal input.');
        }

        return $bytes;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function add32(string $left, string $right): array
    {
        if (strlen($left) !== 32 || strlen($right) !== 32) {
            throw new RuntimeException('add32 expects 32-byte inputs.');
        }

        $carry = 0;
        $bytes = array_fill(0, 32, 0);

        for ($i = 31; $i >= 0; $i--) {
            $sum = ord($left[$i]) + ord($right[$i]) + $carry;
            $bytes[$i] = $sum & 0xff;
            $carry = $sum >> 8;
        }

        $result = '';
        foreach ($bytes as $byte) {
            $result .= chr($byte);
        }

        return [$result, $carry];
    }

    private function sub32(string $left, string $right): string
    {
        if (strlen($left) !== 32 || strlen($right) !== 32) {
            throw new RuntimeException('sub32 expects 32-byte inputs.');
        }

        if ($this->compare32($left, $right) < 0) {
            throw new RuntimeException('sub32 requires left operand >= right operand.');
        }

        $borrow = 0;
        $bytes = array_fill(0, 32, 0);

        for ($i = 31; $i >= 0; $i--) {
            $diff = ord($left[$i]) - ord($right[$i]) - $borrow;
            if ($diff < 0) {
                $diff += 256;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $bytes[$i] = $diff;
        }

        $result = '';
        foreach ($bytes as $byte) {
            $result .= chr($byte);
        }

        return $result;
    }

    private function compare32(string $left, string $right): int
    {
        if (strlen($left) !== 32 || strlen($right) !== 32) {
            throw new RuntimeException('compare32 expects 32-byte inputs.');
        }

        for ($i = 0; $i < 32; $i++) {
            $a = ord($left[$i]);
            $b = ord($right[$i]);
            if ($a < $b) {
                return -1;
            }
            if ($a > $b) {
                return 1;
            }
        }

        return 0;
    }

    private function privateKeyToAddress(string $networkKey, string $privateKey): string
    {
        [$x, $y] = $this->publicKeyCoordinates($privateKey);
        $publicKey = $x . $y;

        $client = $this->makeRpcClient($networkKey);
        $hash = strtolower((string) $client->call('web3_sha3', ['0x' . bin2hex($publicKey)]));

        if (!preg_match('/^0x[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException("web3_sha3 returned invalid hash [{$hash}]");
        }

        return '0x' . substr($hash, -40);
    }

    private function compressedPublicKey(string $privateKey): string
    {
        [$x, $y] = $this->publicKeyCoordinates($privateKey);
        $prefix = (ord($y[31]) & 1) === 0 ? "\x02" : "\x03";

        return $prefix . $x;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function publicKeyCoordinates(string $privateKey): array
    {
        if (strlen($privateKey) !== 32) {
            throw new RuntimeException('Invalid private key length.');
        }

        $asn1 = hex2bin('302e0201010420') . $privateKey . hex2bin('a00706052b8104000a');
        if ($asn1 === false) {
            throw new RuntimeException('Unable to build EC private key ASN.1 payload.');
        }

        $pem = "-----BEGIN EC PRIVATE KEY-----\n" .
            chunk_split(base64_encode($asn1), 64, "\n") .
            "-----END EC PRIVATE KEY-----\n";

        $resource = openssl_pkey_get_private($pem);
        if ($resource === false) {
            throw new RuntimeException('Failed to parse secp256k1 private key with OpenSSL.');
        }

        $details = openssl_pkey_get_details($resource);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('OpenSSL did not return EC public key coordinates.');
        }

        $x = $details['ec']['x'];
        $y = $details['ec']['y'];

        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Invalid EC public key coordinate length.');
        }

        return [$x, $y];
    }
}
