<?php
/**
 * UserAgentParser — extrae navegador, sistema operativo y tipo de dispositivo
 * de un User-Agent, sin dependencias (no hay Composer en este proyecto).
 *
 * Es un parser ligero por heuristica de regex: cubre los navegadores y sistemas
 * comunes y detecta bots. Lo desconocido degrada a 'Unknown' / 'desktop'. No
 * pretende ser exhaustivo; su salida alimenta audit_logs (browser/os/device_type)
 * para reportes de actividad y auditorias de seguridad.
 *
 * Uso:
 *   $ua = UserAgentParser::parse($_SERVER['HTTP_USER_AGENT'] ?? '');
 *   // ['browser' => 'Chrome 120', 'os' => 'Windows 10', 'device_type' => 'desktop']
 */
class UserAgentParser
{
    /**
     * @return array{browser:string, os:string, device_type:string}
     */
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return ['browser' => 'Unknown', 'os' => 'Unknown', 'device_type' => 'unknown'];
        }

        return [
            'browser'     => self::browser($ua),
            'os'          => self::os($ua),
            'device_type' => self::deviceType($ua),
        ];
    }

    private static function browser(string $ua): string
    {
        // Orden importante: Edge/Opera/Brave se anuncian tambien como Chrome.
        $patterns = [
            'Edge'              => '/Edg(?:e|A|iOS)?\/([0-9]+)/i',
            'Opera'             => '/(?:OPR|Opera)\/([0-9]+)/i',
            'Samsung Internet'  => '/SamsungBrowser\/([0-9]+)/i',
            'Vivaldi'           => '/Vivaldi\/([0-9]+)/i',
            'Yandex'            => '/YaBrowser\/([0-9]+)/i',
            'Firefox'           => '/(?:Firefox|FxiOS)\/([0-9]+)/i',
            'Chrome'            => '/(?:Chrome|CriOS)\/([0-9]+)/i',
            'Safari'            => '/Version\/([0-9]+).*Safari/i',
            'Internet Explorer' => '/(?:MSIE |rv:)([0-9]+).*(?:Trident|MSIE)/i',
        ];
        foreach ($patterns as $name => $re) {
            if (preg_match($re, $ua, $m)) {
                return trim($name . ' ' . ($m[1] ?? ''));
            }
        }
        // Clientes no-browser frecuentes (integraciones, pruebas).
        foreach (['curl', 'PostmanRuntime', 'insomnia', 'python-requests', 'Go-http-client', 'okhttp', 'Guzzle', 'axios', 'node-fetch'] as $tool) {
            if (stripos($ua, $tool) !== false) {
                return $tool;
            }
        }
        return 'Unknown';
    }

    private static function os(string $ua): string
    {
        if (preg_match('/Windows NT ([0-9.]+)/i', $ua, $m)) {
            $map = [
                '10.0' => 'Windows 10/11', '6.3' => 'Windows 8.1', '6.2' => 'Windows 8',
                '6.1' => 'Windows 7', '6.0' => 'Windows Vista', '5.1' => 'Windows XP',
            ];
            return $map[$m[1]] ?? ('Windows NT ' . $m[1]);
        }
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return preg_match('/OS ([0-9_]+)/i', $ua, $m) ? 'iOS ' . str_replace('_', '.', $m[1]) : 'iOS';
        }
        if (preg_match('/Android ([0-9.]+)/i', $ua, $m)) {
            return 'Android ' . $m[1];
        }
        if (preg_match('/Mac OS X ([0-9_]+)/i', $ua, $m)) {
            return 'macOS ' . str_replace('_', '.', $m[1]);
        }
        if (stripos($ua, 'Macintosh') !== false) {
            return 'macOS';
        }
        if (stripos($ua, 'CrOS') !== false) {
            return 'ChromeOS';
        }
        if (preg_match('/(Ubuntu|Debian|Fedora|CentOS)/i', $ua, $m)) {
            return $m[1];
        }
        if (stripos($ua, 'Linux') !== false) {
            return 'Linux';
        }
        return 'Unknown';
    }

    private static function deviceType(string $ua): string
    {
        if (preg_match('/bot|crawler|spider|crawling|slurp|mediapartners|facebookexternalhit/i', $ua)) {
            return 'bot';
        }
        if (preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/Mobile|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini|IEMobile/i', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }
}
