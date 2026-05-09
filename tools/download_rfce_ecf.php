<?php
$apiBase = 'https://gratex.net/api';
$apiKey  = '7a775f6fb0d5ccab15cf149d2c60f15c';

$casos = [
    ['id' => 862, 'encf' => 'E320000000011'],
    ['id' => 863, 'encf' => 'E320000000012'],
    ['id' => 864, 'encf' => 'E320000000014'],
    ['id' => 865, 'encf' => 'E320000000015'],
];

foreach ($casos as $c) {
    $url = $apiBase . '/facturas/' . $c['id'] . '/xml';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $apiKey, 'Accept: application/xml'],
        CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NATIVE_CA,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $file = __DIR__ . '/xmls/' . $c['encf'] . '.xml';
    file_put_contents($file, $body);
    echo $c['encf'] . '.xml -> ' . strlen($body) . " bytes\n";
}
echo "Listo.\n";
