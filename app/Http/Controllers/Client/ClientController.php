<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Utils\Clash;
use App\Utils\QuantumultX;
use App\Utils\Shadowrocket;
use App\Utils\Surge;
use App\Utils\Surfboard;
use App\Utils\URLSchemes;
use Illuminate\Http\Request;
use App\Models\Server;
use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;
use App\Services\UserService;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAllServers($user);

            if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $_SERVER['HTTP_USER_AGENT'] = strtolower($_SERVER['HTTP_USER_AGENT']);
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'quantumult%20x') !== false) {
                    die($this->quantumultX($user, $servers['vmess'], $servers['trojan']));
                }
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'quantumult') !== false) {
                    die($this->quantumult($user, $servers['vmess']));
                }
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'clash') !== false) {
                    die($this->clash($user, $servers['shadowsocks'], $servers['vmess'], $servers['trojan']));
                }
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'surfboard') !== false) {
                    die($this->surfboard($user, $servers['shadowsocks'], $servers['vmess']));
                }
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'surge') !== false) {
                    die($this->surge($user, $servers['vmess'], $servers['trojan']));
                }
                if (strpos($_SERVER['HTTP_USER_AGENT'], 'shadowrocket') !== false) {
                    die($this->shadowrocket($user, $servers['shadowsocks'], $servers['vmess'], $servers['trojan']));
                }
            }
            die($this->origin($user, $servers['shadowsocks'], $servers['vmess'], $servers['trojan']));
        }
    }
    // TODO: Ready to stop support
    private function quantumult($user, $vmess = [])
    {
        $uri = '';
        header('subscription-userinfo: upload=' . $user->u . '; download=' . $user->d . ';total=' . $user->transfer_enable);
        foreach ($vmess as $item) {
            $str = '';
            $str .= $item->name . '= vmess, ' . $item->host . ', ' . $item->port . ', chacha20-ietf-poly1305, "' . $user->uuid . '", over-tls=' . ($item->tls ? "true" : "false") . ', certificate=0, group=' . config('v2board.app_name', 'V2Board');
            if ($item->network === 'ws') {
                $str .= ', obfs=ws';
                if ($item->networkSettings) {
                    $wsSettings = json_decode($item->networkSettings);
                    if (isset($wsSettings->path)) $str .= ', obfs-path="' . $wsSettings->path . '"';
                    if (isset($wsSettings->headers->Host)) $str .= ', obfs-header="Host:' . $wsSettings->headers->Host . '"';
                }
            }
            $uri .= "vmess://" . base64_encode($str) . "\r\n";
        }
        return base64_encode($uri);
    }

    private function shadowrocket($user, $shadowsocks = [], $vmess = [], $trojan = [])
    {
        $uri = '';
        //display remaining traffic and expire date
        $upload = round($user->u / (1024*1024*1024), 2);
        $download = round($user->d / (1024*1024*1024), 2);
        $totalTraffic = round($user->transfer_enable / (1024*1024*1024), 2);
        $expiredDate = date('Y-m-d', $user->expired_at);
        $uri .= "STATUS=🚀↑:{$upload}GB,↓:{$download}GB,TOT:{$totalTraffic}GB💡Expires:{$expiredDate}\r\n";
        foreach ($shadowsocks as $item) {
            $uri .= Shadowrocket::buildShadowsocks($user->uuid, $item);
        }
        foreach ($vmess as $item) {
            $uri .= Shadowrocket::buildVmess($user->uuid, $item);
        }
        foreach ($trojan as $item) {
            $uri .= Shadowrocket::buildTrojan($user->uuid, $item);
        }
        return base64_encode($uri);
    }

    private function quantumultX($user, $vmess = [], $trojan = [])
    {
        $uri = '';
        header("subscription-userinfo: upload={$user->u}; download={$user->d}; total={$user->transfer_enable}; expire={$user->expired_at}");
        foreach ($vmess as $item) {
            $uri .= QuantumultX::buildVmess($user->uuid, $item);
        }
        foreach ($trojan as $item) {
            $uri .= QuantumultX::buildTrojan($user->uuid, $item);
        }
        return base64_encode($uri);
    }

    private function origin($user, $shadowsocks = [], $vmess = [], $trojan = [])
    {
        $uri = '';
        foreach ($shadowsocks as $item) {
            $uri .= URLSchemes::buildShadowsocks($item, $user);
        }
        foreach ($vmess as $item) {
            $uri .= URLSchemes::buildVmess($item, $user);
        }
        foreach ($trojan as $item) {
            $uri .= URLSchemes::buildTrojan($item, $user);
        }
        return base64_encode($uri);
    }

    private function surge($user, $vmess = [], $trojan = [])
    {
        $proxies = '';
        $proxyGroup = '';
        foreach ($vmess as $item) {
            // [Proxy]
            $proxies .= Surge::buildVmess($user->uuid, $item);
            // [Proxy Group]
            $proxyGroup .= $item->name . ', ';
        }

        foreach ($trojan as $item) {
            // [Proxy]
            $proxies .= Surge::buildTrojan($user->uuid, $item);
            // [Proxy Group]
            $proxyGroup .= $item->name . ', ';
        }

        $defaultConfig = base_path() . '/resources/rules/default.surge.conf';
        $customConfig = base_path() . '/resources/rules/custom.surge.conf';
        if (\File::exists($customConfig)) {
            $config = file_get_contents("$customConfig");
        } else {
            $config = file_get_contents("$defaultConfig");
        }

        // Subscription link
        $subsURL = config('v2board.subscribe_url', config('v2board.app_url', env('APP_URL'))) . '/api/v1/client/subscribe?token=' . $user['token'];

        $config = str_replace('$subs_link', $subsURL, $config);
        $config = str_replace('$proxies', $proxies, $config);
        $config = str_replace('$proxy_group', rtrim($proxyGroup, ', '), $config);
        return $config;
    }

    private function surfboard($user, $shadowsocks = [], $vmess = [])
    {
        $proxies = '';
        $proxyGroup = '';

        foreach ($shadowsocks as $item) {
            // [Proxy]
            $proxies .= Surfboard::buildShadowsocks($user->uuid, $item);
            // [Proxy Group]
            $proxyGroup .= $item->name . ', ';
        }

        foreach ($vmess as $item) {
            // [Proxy]
            $proxies .= Surfboard::buildVmess($user->uuid, $item);
            // [Proxy Group]
            $proxyGroup .= $item->name . ', ';
        }

        $defaultConfig = base_path() . '/resources/rules/default.surfboard.conf';
        $customConfig = base_path() . '/resources/rules/custom.surfboard.conf';
        if (\File::exists($customConfig)) {
            $config = file_get_contents("$customConfig");
        } else {
            $config = file_get_contents("$defaultConfig");
        }

        // Subscription link
        $subsURL = config('v2board.subscribe_url', config('v2board.app_url', env('APP_URL'))) . '/api/v1/client/subscribe?token=' . $user['token'];

        $config = str_replace('$subs_link', $subsURL, $config);
        $config = str_replace('$proxies', $proxies, $config);
        $config = str_replace('$proxy_group', rtrim($proxyGroup, ', '), $config);
        return $config;
    }

    private function clash($user, $shadowsocks = [], $vmess = [], $trojan = [])
    {
        $defaultConfig = base_path() . '/resources/rules/default.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.clash.yaml';
        if (\File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }
        $proxy = [];
        $proxies = [];

        foreach ($shadowsocks as $item) {
            array_push($proxy, Clash::buildShadowsocks($user->uuid, $item));
            array_push($proxies, $item->name);
        }

        foreach ($vmess as $item) {
            array_push($proxy, Clash::buildVmess($user->uuid, $item));
            array_push($proxies, $item->name);
        }


        foreach ($trojan as $item) {
            array_push($proxy, Clash::buildTrojan($user->uuid, $item));
            array_push($proxies, $item->name);
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies'])) continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $yaml = Yaml::dump($config);
        $yaml = str_replace('$app_name', config('v2board.app_name', 'V2Board'), $yaml);
        return $yaml;
    }
}
