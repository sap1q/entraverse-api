<?php

declare(strict_types=1);

use App\Services\Mekari\MekariService;
use Carbon\CarbonImmutable;
use Dotenv\Dotenv;
use GuzzleHttp\Client;

require __DIR__.'/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$clientId = $_ENV['MEKARI_CLIENT_ID'] ?? getenv('MEKARI_CLIENT_ID') ?: '';
$clientSecret = $_ENV['MEKARI_CLIENT_SECRET'] ?? getenv('MEKARI_CLIENT_SECRET') ?: '';
$baseUrl = rtrim((string) ($_ENV['MEKARI_BASE_URL'] ?? getenv('MEKARI_BASE_URL') ?: 'https://api.mekari.com'), '/');
$jurnalBasePath = (string) ($_ENV['MEKARI_JURNAL_BASE_PATH'] ?? getenv('MEKARI_JURNAL_BASE_PATH') ?: '/public/jurnal/api/v1');
if (! str_starts_with($jurnalBasePath, '/')) {
    $jurnalBasePath = '/'.$jurnalBasePath;
}

$path = rtrim($jurnalBasePath, '/').'/products?page=1&per_page=1';
$date = CarbonImmutable::now('GMT')->toRfc7231String();
$method = 'GET';

echo "========== TEST KONEKSI MEKARI ==========\n\n";

echo "1. ENVIRONMENT\n";
echo '   MEKARI_CLIENT_ID: '.($clientId !== '' ? $clientId : '[EMPTY]')."\n";
echo '   MEKARI_CLIENT_SECRET: '.($clientSecret !== '' ? '[SET]' : '[EMPTY]')."\n";
echo "   MEKARI_BASE_URL: {$baseUrl}\n";
echo "   MEKARI_JURNAL_BASE_PATH: {$jurnalBasePath}\n\n";

if ($clientId === '' || $clientSecret === '') {
    echo "[ERROR] MEKARI_CLIENT_ID / MEKARI_CLIENT_SECRET belum terisi.\n";
    exit(1);
}

$requestLine = "{$method} {$path} HTTP/1.1";
$payload = "date: {$date}\n{$requestLine}";
$manualSignature = base64_encode(hash_hmac('sha256', $payload, $clientSecret, true));
$manualAuthHeader = sprintf(
    'hmac username="%s", algorithm="hmac-sha256", headers="date request-line", signature="%s"',
    $clientId,
    $manualSignature
);

echo "2. SIGNATURE (MANUAL)\n";
echo "   Date: {$date}\n";
echo "   Request Line: {$requestLine}\n";
echo "   Payload:\n{$payload}\n";
echo "   Signature: {$manualSignature}\n";
echo "   Authorization: {$manualAuthHeader}\n\n";

$service = new MekariService(
    httpClient: new Client([
        'base_uri' => $baseUrl,
        'timeout' => 30,
        'connect_timeout' => 10,
        'http_errors' => false,
    ]),
    clientId: $clientId,
    clientSecret: $clientSecret,
    baseUrl: $baseUrl
);

$serviceSignature = $service->generateSignature($method, $path, $date);
$serviceAuthHeader = $service->buildAuthHeader($serviceSignature);

echo "3. SIGNATURE (SERVICE)\n";
echo "   Signature: {$serviceSignature}\n";
echo "   Authorization: {$serviceAuthHeader}\n";
echo '   Match manual: '.($serviceSignature === $manualSignature ? '[YES]' : '[NO]')."\n\n";

echo "4. CURL REQUEST\n";
$url = "{$baseUrl}{$path}";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Date: '.$date,
    'Authorization: '.$manualAuthHeader,
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$rawResponse = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$curlError = curl_error($ch);
curl_close($ch);

$responseHeaders = $rawResponse !== false ? substr($rawResponse, 0, $headerSize) : '';
$responseBody = $rawResponse !== false ? substr($rawResponse, $headerSize) : '';

echo "   URL: {$url}\n";
echo "   HTTP Code: {$httpCode}\n";
echo '   CURL Error: '.($curlError !== '' ? $curlError : 'Tidak ada')."\n\n";

if ($httpCode === 200) {
    echo "[OK] KONEKSI BERHASIL\n";
    echo "Response Body:\n{$responseBody}\n";
    exit(0);
}

echo "[FAIL] KONEKSI GAGAL (HTTP {$httpCode})\n";
echo "Response Headers:\n{$responseHeaders}\n";
echo "Response Body:\n{$responseBody}\n\n";

if ($httpCode === 401) {
    echo "Checklist 401 Unauthorized:\n";
    echo "1. Pastikan Client ID dan Client Secret milik aplikasi yang sama.\n";
    echo "2. Pastikan jam server sinkron (NTP). Selisih waktu bisa membuat signature invalid.\n";
    echo "3. Pastikan path yang ditandatangani EXACT sama dengan request path + query.\n";
    echo "4. Pastikan kredensial belum revoked / regenerated di Mekari Developer Hub.\n";
    echo "5. Pastikan base path Jurnal benar: /public/jurnal/api/v1 (set MEKARI_JURNAL_BASE_PATH).\n";
    echo "6. Pastikan app Anda sudah diberi akses Jurnal di organisasi/workspace yang benar.\n";
}

exit(1);
