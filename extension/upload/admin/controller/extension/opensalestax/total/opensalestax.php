<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Opensalestax\Total;

/**
 * Admin settings controller for the OpenSalesTax order-total extension.
 *
 * Implements the OpenCart 4.x extension settings contract:
 *  - `index()` renders the settings form
 *  - `save()` validates and persists the form values
 *
 * The page lives under
 * `Admin → Extensions → Extensions → Order Totals → OpenSalesTax`.
 *
 * Server-side validation:
 *  - The engine base URL is run through our `UrlValidator` so SSRF defenses
 *    cannot be bypassed by submitting `localhost`, `10.0.0.1`, `169.254.x.x`,
 *    etc. (unless `module_opensalestax_allow_private_nets` is on).
 *
 * Per-route ACL: the user must have `modify` permission on the controller's
 * route. We do NOT bypass `user->hasPermission()`.
 */
class Opensalestax extends \Opencart\System\Engine\Controller
{
    private const ROUTE = 'extension/opensalestax/total/opensalestax';
    private const TOKEN_QS = 'user_token=';
    private const SETTINGS_NS = 'module_opensalestax';

    /**
     * Render the settings form.
     */
    public function index(): void
    {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));

        $token = (string) $this->session->data['user_token'];
        $tokenQs = self::TOKEN_QS . $token;
        $tokenQsType = $tokenQs . '&type=total';

        $data['breadcrumbs'] = [
            [
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', $tokenQs),
            ],
            [
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link('marketplace/extension', $tokenQsType),
            ],
            [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link(self::ROUTE, $tokenQs),
            ],
        ];

        $data['save'] = $this->url->link(self::ROUTE . '|save', $tokenQs);
        $data['back'] = $this->url->link('marketplace/extension', $tokenQsType);

        $keys = [
            self::SETTINGS_NS . '_status'                     => false,
            self::SETTINGS_NS . '_base_url'                   => '',
            self::SETTINGS_NS . '_api_key'                    => '',
            self::SETTINGS_NS . '_timeout_seconds'            => 10,
            self::SETTINGS_NS . '_tls_verify'                 => true,
            self::SETTINGS_NS . '_allow_private_nets'         => false,
            self::SETTINGS_NS . '_fail_hard'                  => false,
            self::SETTINGS_NS . '_cache_ttl_seconds'          => 86400,
            self::SETTINGS_NS . '_exempt_customer_group_ids'  => '',
            self::SETTINGS_NS . '_per_jurisdiction_lines'     => false,
        ];
        foreach ($keys as $key => $default) {
            $data[$key] = $this->config->get($key) ?? $default;
        }

        $data['test_connection'] = $this->url->link(self::ROUTE . '|testConnection', $tokenQs);

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    /**
     * Persist the submitted settings, after server-side validation.
     */
    public function save(): void
    {
        $this->load->language(self::ROUTE);

        $json = [];

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $json['error'] = $this->language->get('error_permission');
        }

        $post = $this->request->post;
        $baseUrl = isset($post[self::SETTINGS_NS . '_base_url'])
            ? (string) $post[self::SETTINGS_NS . '_base_url']
            : '';
        $allowPrivate = !empty($post[self::SETTINGS_NS . '_allow_private_nets']);

        if (!isset($json['error']) && $baseUrl !== '') {
            $this->validateBaseUrl($baseUrl, $allowPrivate, $json);
        }

        if (!isset($json['error'])) {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting(self::SETTINGS_NS, $this->request->post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }

    /**
     * Validate the engine base URL using the same SSRF-defending validator
     * the runtime uses. Populates `$json['error']` on rejection.
     *
     * @param array<string, mixed> $json
     */
    private function validateBaseUrl(string $baseUrl, bool $allowPrivate, array &$json): void
    {
        // The bootstrap file pulls in our bundled autoloader so the validator
        // class can be referenced here at save-validation time.
        require_once DIR_EXTENSION . 'opensalestax/system/library/opensalestax/bootstrap.php'; // NOSONAR — bundled autoload entry
        try {
            $validator = new \OpenSalesTax\OpenCart\Support\UrlValidator($allowPrivate);
            $validator->validate($baseUrl);
        } catch (\InvalidArgumentException $e) {
            $json['error'] = $e->getMessage();
        }
    }

    /**
     * "Test Connection" admin action.
     *
     * Reads the form's current URL / API key / TLS / private-nets toggle from
     * the POST body, runs the same SSRF validator the save path runs, then
     * calls the SDK's `health()` to confirm reachability + auth.
     *
     * Responds with `{ok: bool, message: string, version?: string}` JSON.
     * Never blocks save — this is a diagnostic-only round trip and the
     * merchant chooses to invoke it.
     *
     * ACL-gated to the same `modify` permission as `save()`: a user who
     * cannot edit settings cannot use this as an SSRF probe.
     */
    public function testConnection(): void
    {
        $this->load->language(self::ROUTE);
        $this->response->addHeader('Content-Type: application/json');

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->response->setOutput((string) json_encode([
                'ok'      => false,
                'message' => $this->language->get('error_permission'),
            ]));
            return;
        }

        $post = $this->request->post;
        $baseUrl     = isset($post[self::SETTINGS_NS . '_base_url']) ? trim((string) $post[self::SETTINGS_NS . '_base_url']) : '';
        $apiKey      = isset($post[self::SETTINGS_NS . '_api_key']) ? trim((string) $post[self::SETTINGS_NS . '_api_key']) : '';
        $tlsVerify   = !empty($post[self::SETTINGS_NS . '_tls_verify']);
        $allowPriv   = !empty($post[self::SETTINGS_NS . '_allow_private_nets']);
        $timeoutRaw  = $post[self::SETTINGS_NS . '_timeout_seconds'] ?? 10;
        $timeoutSec  = is_numeric($timeoutRaw) ? (float) $timeoutRaw : 10.0;

        if ($baseUrl === '') {
            $this->response->setOutput((string) json_encode([
                'ok'      => false,
                'message' => $this->language->get('error_test_no_url'),
            ]));
            return;
        }

        $this->response->setOutput((string) json_encode(
            $this->runHealthProbe($baseUrl, $apiKey, $tlsVerify, $allowPriv, $timeoutSec),
        ));
    }

    /**
     * Validate the URL, build the SDK client with the form's transport
     * settings, call `health()`. Returns the JSON-ready response payload.
     *
     * @return array<string, mixed>
     */
    private function runHealthProbe(
        string $baseUrl,
        string $apiKey,
        bool $tlsVerify,
        bool $allowPriv,
        float $timeoutSec,
    ): array {
        require_once DIR_EXTENSION . 'opensalestax/system/library/opensalestax/bootstrap.php'; // NOSONAR — bundled autoload entry

        try {
            (new \OpenSalesTax\OpenCart\Support\UrlValidator($allowPriv))->validate($baseUrl);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        try {
            $http = new \GuzzleHttp\Client([
                'timeout' => $timeoutSec,
                'verify'  => $tlsVerify,
            ]);
            $client = new \OpenSalesTax\Client(
                baseUrl: $baseUrl,
                apiKey: $apiKey !== '' ? $apiKey : null,
                timeoutSeconds: $timeoutSec,
                httpClient: $http,
            );
            $health = $client->health();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return [
            'ok'      => true,
            'message' => $this->language->get('text_test_ok'),
            'version' => $health->version,
        ];
    }
}
