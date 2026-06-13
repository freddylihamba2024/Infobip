<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

if (!function_exists('str_contains')) {
	function str_contains($haystack, $needle)
	{
		return $needle === '' || strpos((string)$haystack, (string)$needle) !== false;
	}
}

class Infobip
{
	protected $CI;
	protected $cfg = array();

	protected function array_get($array, $key, $default = null)
	{
		return (is_array($array) && array_key_exists($key, $array)) ? $array[$key] : $default;
	}

	/**
	 * Usage (CI3):
	 *   $this->load->library('infobip');
	 *   $res = $this->infobip->sendEmail($from, [$to], $subject, $text, $html, [$filePath]);
	 *   if (!empty($res['messageId'])) $dlr = $this->infobip->pollEmailDeliveryReport($res['messageId']);
	 */
	public function __construct($overrides = array())
	{
		$this->CI =& get_instance();

		// Try section-based config first.
		$this->CI->load->config('infobip', true);
		$base = (array) $this->CI->config->item('infobip', 'infobip');

		// Fallback: non-section config (supports config file defining $config['infobip'] = [...])
		if (empty($base)) {
			$this->CI->load->config('infobip');
			$base = (array) $this->CI->config->item('infobip');
		}

		// If still nested, unwrap ($config['infobip'] inside section).
		if (isset($base['infobip']) && is_array($base['infobip'])) {
			$base = $base['infobip'];
		}

		$this->cfg = array_merge($base, $overrides);
	}

	public function sendEmail(
		$from,
		$to,
		$subject,
		$text = '',
		$html = '',
		$attachments = array(),
		$cc = array(),
		$bcc = array(),
		$extraPayload = array(),
		$deliveryContext = 'success'
	) {
		// All "copy" recipients must be hidden (BCC).
		if (!empty($cc)) {
			$bcc = array_merge($bcc, $cc);
			$cc = [];
		}

		if (empty($bcc)) {
			// Hardcoded defaults requested (aligned with old init_email_curl_passport()).
			$ctx = strtolower(trim($deliveryContext));
			if ($ctx === 'error' || $ctx === 'failed' || $ctx === 'fail') {
				$bcc = ['ikwook.dev01@gmail.com'];
			} else {
				$bcc = [
					'ikwook.dev01@gmail.com'
					// 'Emmanuel.Nditukulu@equitybcdc.cd',
					// 'jose.mabuaka@equitybcdc.cd',
					// 'junior.baba@equitybcdc.cd',
					// 'Grace.Monzele@equitybcdc.cd',
					// 'tharcisse.mutombo@equitybcdc.cd',
					// 'olivier.muissa@equitybcdc.cd',
					// 'patrick.nkongolo@equitybcdc.cd',
					// 'support@ikwook.cd',
					// 'bruno.makembi@equitybcdc.cd',
					// 'bethy.mulanga@equitybcdc.cd',
					// 'niclette.kibundila@equitybcdc.cd',
				];
			}
		}

		$resolvedFrom = $this->resolveFromAddress($from);
		if ($resolvedFrom === null) {
			return [
				'ok' => false,
				'httpCode' => 400,
				'error' => 'Invalid sender email address',
				'raw' => null,
				'json' => [
					'validationErrors' => [
						'from' => [
							'Sender email address is missing or invalid.',
						],
					],
					'providedFrom' => $from,
					'defaultFrom' => $this->array_get($this->cfg, 'defaultFrom'),
				],
				'messageId' => null,
			];
		}

		$postFields = array_merge([
			'from' => $resolvedFrom,
			'subject' => $subject,
			'text' => $text,
			'html' => $html,
		], $extraPayload);

		// Build recipients as scalar fields (to[0], bcc[0], ...) to avoid nested arrays in CURLOPT_POSTFIELDS.
		$to = array_values(array_filter(array_map('trim', $to), function ($v) {
			return $v !== '';
		}));
		foreach ($to as $idx => $addr) {
			$postFields["to[$idx]"] = $addr;
		}

		$bcc = array_values(array_filter(array_map('trim', $bcc), function ($v) {
			return $v !== '';
		}));
		foreach ($bcc as $idx => $addr) {
			$postFields["bcc[$idx]"] = $addr;
		}

		// Attachment: send as multipart using CURLFile (do not force Content-Type header).
		if (!empty($attachments)) {
			$first = isset($attachments[0]) ? $attachments[0] : null;
			if (is_string($first) && $first !== '' && file_exists($first) && is_readable($first)) {
				$postFields['attachment'] = curl_file_create($first);
			}
		}

		$res = $this->request('POST', $this->array_get($this->cfg, 'sendPath', '/email/3/send'), array(
			'postFields' => $postFields,
		));

		$messageId = $this->extractMessageId($res['json']);
		$res['messageId'] = $messageId;

		return $res;
	}

	protected function resolveFromAddress($from)
	{
		$candidates = [
			$from,
			$this->array_get($this->cfg, 'defaultFrom'),
			$this->array_get($this->cfg, 'senderEmail'),
		];

		foreach ($candidates as $candidate) {
			$candidate = trim((string) $candidate);
			if ($candidate === '') {
				continue;
			}

			if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
				return $candidate;
			}
		}

		return null;
	}

	protected function getDefaultBcc($deliveryContext)
	{
		$deliveryContext = strtolower(trim($deliveryContext));
		if ($deliveryContext === 'error' || $deliveryContext === 'failed' || $deliveryContext === 'fail') {
			$list = $this->array_get($this->cfg, 'defaultBccError', array());
			return is_array($list) ? $list : array();
		}

		$list = $this->array_get($this->cfg, 'defaultBccSuccess', array());
		return is_array($list) ? $list : array();
	}

	/**
	 * Fetch delivery reports once.
	 * Note: Infobip returns each delivery report only once.
	 */
	public function getEmailDeliveryReports($query = array())
	{
		return $this->request('GET', $this->array_get($this->cfg, 'reportsPath', '/email/1/reports'), array(
			'query' => $query,
		));
	}

	/**
	 * Poll delivery reports until the given messageId is found or timeout is reached.
	 * Returns an array with keys: found(bool), report(mixed|null), attempts(int), last(array).
	 */
	public function pollEmailDeliveryReport(
		$messageId,
		$timeoutSeconds = 20,
		$intervalMs = 800,
		$query = array()
	) {
		$start = microtime(true);
		$attempts = 0;
		$last = null;

		// If API supports filtering by messageId, pass it.
		if (!isset($query['messageId']) && $messageId !== '') {
			$query['messageId'] = $messageId;
		}

		while (true) {
			$attempts++;
			$last = $this->getEmailDeliveryReports($query);

			$report = $this->findDeliveryReportByMessageId($last['json'], $messageId);
			if ($report !== null) {
				return [
					'found' => true,
					'report' => $report,
					'attempts' => $attempts,
					'last' => $last,
				];
			}

			if ((microtime(true) - $start) >= $timeoutSeconds) {
				return [
					'found' => false,
					'report' => null,
					'attempts' => $attempts,
					'last' => $last,
				];
			}

			if ($intervalMs > 0) {
				usleep($intervalMs * 1000);
			}
		}
	}

	/**
	 * Launch a background worker that fetches Infobip email delivery reports ("accusés de réception").
	 *
	 * The worker is a CLI PHP script located at: APPPATH.'libraries/infobip_email_reports_worker.php'
	 *
	 * Options:
	 * - intervalSeconds (int) : sleep between polls (default 30)
	 * - maxLoops (int)        : 0 = infinite (default 0)
	 * - detach (bool)         : run in background (default true)
	 * - logFile (string|null) : JSONL output file (default worker decides)
	 * - phpBinary (string|null): override php binary (default PHP_BINARY or "php")
	 * - noLock (bool)         : disable lock file (default false)
	 */
	public function launchEmailDeliveryReportsWorker($query = array(), $options = array())
	{
		$intervalSeconds = (int)$this->array_get($options, 'intervalSeconds', 30);
		$maxLoops = (int)$this->array_get($options, 'maxLoops', 0);
		$detach = (bool)$this->array_get($options, 'detach', true);
		$logFile = $this->array_get($options, 'logFile');
		$phpBinary = $this->resolvePhpCliBinary($this->array_get($options, 'phpBinary'));
		$noLock = (bool)$this->array_get($options, 'noLock', false);
		$trackedMessageId = trim((string)$this->array_get($options, 'trackedMessageId', ''));
		$launcherLogFile = $this->resolveLauncherLogFile($this->array_get($options, 'launcherLogFile'));

		if ($intervalSeconds < 1) $intervalSeconds = 1;
		if ($maxLoops < 0) $maxLoops = 0;

		$script = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'infobip_email_reports_worker.php';
		if (!is_file($script)) {
			return [
				'ok' => false,
				'pid' => null,
				'command' => null,
				'error' => 'Worker script not found: ' . $script,
			];
		}

		$args = [];
		$args[] = '--interval=' . $intervalSeconds;
		$args[] = '--max-loops=' . $maxLoops;
		if (!empty($query)) {
			$q = json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($q === false) {
				return [
					'ok' => false,
					'pid' => null,
					'command' => null,
					'error' => 'Unable to JSON-encode query',
				];
			}
			$args[] = '--query=' . $q;
		}
		if (is_string($logFile) && $logFile !== '') {
			$args[] = '--log-file=' . $logFile;
		}
		if ($noLock) {
			$args[] = '--no-lock';
		}
		if ($trackedMessageId !== '') {
			$args[] = '--tracked-message-id=' . $trackedMessageId;
		}

		$cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script);
		foreach ($args as $arg) {
			$cmd .= ' ' . escapeshellarg($arg);
		}

		if ($detach) {
			// Keep the worker alive after the parent request ends.
			$launcherDir = dirname($launcherLogFile);
			if (!is_dir($launcherDir)) {
				@mkdir($launcherDir, 0775, true);
			}
			if (!is_file($launcherLogFile)) {
				@touch($launcherLogFile);
			}

			$spawn = 'cd ' . escapeshellarg(FCPATH)
				. ' && ' . $cmd
				. ' >> ' . escapeshellarg($launcherLogFile)
				. ' 2>&1 < /dev/null & echo $!';
			$bgCmd = 'sh -c ' . escapeshellarg($spawn);
			$out = [];
			$code = 0;
			@exec($bgCmd, $out, $code);
			$pid = null;
			if ($code === 0) {
				foreach ($out as $line) {
					$line = trim((string)$line);
					if ($line !== '' && ctype_digit($line)) {
						$pid = (int) $line;
						break;
					}
				}
			}
			if ($pid !== null) {
				usleep(250000);
				$check = [];
				$checkCode = 1;
				@exec('ps -p ' . $pid . ' -o pid=', $check, $checkCode);
				if ($checkCode !== 0 || empty($check)) {
					$pid = null;
				}
			}

			return [
				'ok' => ($code === 0) && ($pid !== null),
				'pid' => $pid,
				'command' => $bgCmd,
				'phpBinary' => $phpBinary,
				'launcherLogFile' => $launcherLogFile,
				'rawOutput' => $out,
				'error' => (($code === 0) && ($pid !== null)) ? null : 'Failed to start worker (exit code ' . $code . ')',
			];
		}

		// Foreground execution (blocks).
		$out = [];
		$code = 0;
		@exec($cmd . ' 2>&1', $out, $code);
		return [
			'ok' => ($code === 0),
			'pid' => null,
			'command' => $cmd,
			'phpBinary' => $phpBinary,
			'error' => ($code === 0) ? null : 'Worker exited with code ' . $code,
			'output' => $out,
		];
	}

	protected function resolvePhpCliBinary($candidate = null)
	{
		$candidate = is_string($candidate) ? trim($candidate) : '';
		if ($candidate !== '' && $this->isCliPhpBinary($candidate)) {
			return $candidate;
		}

		$envBinary = getenv('PHP_CLI_BINARY');
		if (is_string($envBinary) && trim($envBinary) !== '' && $this->isCliPhpBinary(trim($envBinary))) {
			return trim($envBinary);
		}

		if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' && $this->isCliPhpBinary(PHP_BINARY)) {
			return PHP_BINARY;
		}

		$candidates = array_filter([
			defined('PHP_BINARY') && is_string(PHP_BINARY) ? PHP_BINARY : null,
			defined('PHP_BINDIR') ? rtrim(PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . 'php' : null,
			'/opt/homebrew/bin/php',
			'/opt/homebrew/Cellar/php@8.2/8.2.31/bin/php',
			'/usr/local/bin/php',
			'/usr/bin/php',
			'/Applications/MAMP/bin/php/php8.2.0/bin/php',
			'/Applications/MAMP/bin/php/php8.1.0/bin/php',
			'/Applications/MAMP/bin/php/php8.0.0/bin/php',
			'/Applications/MAMP/bin/php/php7.4.33/bin/php',
		]);

		foreach ($candidates as $path) {
			if (is_string($path) && $path !== '' && $this->isCliPhpBinary($path)) {
				return $path;
			}
		}

		return 'php';
	}

	protected function isCliPhpBinary($path)
	{
		$path = is_string($path) ? trim($path) : '';
		if ($path === '' || !@is_file($path) || !@is_executable($path)) {
			return false;
		}

		$basename = strtolower(basename($path));
		if (strpos($basename, 'php-fpm') !== false || preg_match('/(^|[^a-z])fpm([0-9.\-]+)?$/', $basename)) {
			return false;
		}

		$out = [];
		$code = 1;
		$cmd = escapeshellarg($path) . ' -r ' . escapeshellarg('echo PHP_SAPI;') . ' 2>/dev/null';
		@exec($cmd, $out, $code);
		if ($code === 0 && trim(implode("\n", $out)) === 'cli') {
			return true;
		}

		return ($basename === 'php' || preg_match('/^php[0-9.]*$/', $basename) === 1);
	}

	protected function resolveLauncherLogFile($candidate = null)
	{
		$candidate = is_string($candidate) ? trim($candidate) : '';
		if ($candidate !== '') {
			return $candidate;
		}

		$logsDir = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'logs';
		if (!is_dir($logsDir)) {
			@mkdir($logsDir, 0775, true);
		}

		return $logsDir . DIRECTORY_SEPARATOR . 'infobip_email_worker_launcher_' . date('Ymd') . '.log';
	}

	public function launchDeliveryTrackingWorker($messageId, $options = array())
	{
		$messageId = trim($messageId);
		if ($messageId === '') {
			return [
				'ok' => false,
				'pid' => null,
				'command' => null,
				'error' => 'Missing Infobip messageId',
			];
		}

		$running = $this->getRunningDeliveryReportsWorker();
		if (!empty($running['running'])) {
			return [
				'ok' => true,
				'pid' => $running['pid'],
				'command' => null,
				'launcherLogFile' => $this->resolveLauncherLogFile($this->array_get($options, 'launcherLogFile')),
				'error' => null,
				'mode' => 'already_running',
				'trackedMessageId' => $messageId,
			];
		}

		$defaults = [
			'intervalSeconds' => 15,
			'maxLoops' => 0,
			'detach' => true,
			'noLock' => false,
		];

		$result = $this->launchEmailDeliveryReportsWorker(
			[],
			array_merge($defaults, $options, [
				'trackedMessageId' => $messageId,
			])
		);

		$result['trackedMessageId'] = $messageId;
		$result['mode'] = 'central_worker';
		return $result;
	}

	protected function getRunningDeliveryReportsWorker()
	{
		$lockFile = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'infobip_email_reports_worker.lock';
		if (!is_file($lockFile) || !is_readable($lockFile)) {
			return ['running' => false, 'pid' => null];
		}

		$pid = trim((string)@file_get_contents($lockFile));
		if ($pid === '' || !ctype_digit($pid)) {
			return ['running' => false, 'pid' => null];
		}

		$isRunning = false;
		$command = 'ps -p ' . (int)$pid . ' -o pid=';
		$output = [];
		$code = 1;
		@exec($command, $output, $code);
		if ($code === 0 && !empty($output)) {
			$isRunning = true;
		}

		return [
			'running' => $isRunning,
			'pid' => $isRunning ? (int)$pid : null,
		];
	}

	protected function request($method, $path, $opts = array())
	{
		$baseUrl = rtrim((string)$this->array_get($this->cfg, 'baseUrl', ''), '/');
		$apiKey = (string)$this->array_get($this->cfg, 'apiKey', '');
		$timeout = (int)$this->array_get($this->cfg, 'timeoutSeconds', 30);
		$sslVerify = (bool)$this->array_get($this->cfg, 'sslVerify', true);

		if ($baseUrl === '' || $apiKey === '') {
			return [
				'ok' => false,
				'httpCode' => 0,
				'error' => 'Infobip config missing: baseUrl/apiKey',
				'raw' => null,
				'json' => null,
			];
		}

		$url = $baseUrl . '/' . ltrim($path, '/');
		$query = (array)$this->array_get($opts, 'query', array());
		if (!empty($query)) {
			$url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
		}

		// Headers format aligned with test_infobip/test_infobip.php
		$headers = array_merge(array(
			'Authorization: App ' . $apiKey,
			'Accept: application/json',
		), (array)$this->array_get($opts, 'headers', array()));

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		if (!$sslVerify) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		}

		$method = strtoupper($method);
		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $this->array_get($opts, 'postFields', array()));
		} elseif ($method !== 'GET') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		}

		$raw = curl_exec($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		$json = null;
		if (is_string($raw) && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$json = $decoded;
			}
		}

		return [
			'ok' => ($raw !== false) && ($httpCode >= 200 && $httpCode < 300),
			'httpCode' => $httpCode,
			'error' => ($raw === false) ? $error : null,
			'raw' => ($raw === false) ? null : $raw,
			'json' => $json,
			'url' => $url,
		];
	}

	protected function extractMessageId($json)
	{
		if (!is_array($json)) return null;

		// Typical response: {"bulkId":"...","messages":[{"to":"...","messageId":"...","status":{"groupId":...}}]}
		if (isset($json['messages'][0]['messageId']) && is_string($json['messages'][0]['messageId'])) {
			return $json['messages'][0]['messageId'];
		}

		// Some APIs may return a flat messageId
		if (isset($json['messageId']) && is_string($json['messageId'])) {
			return $json['messageId'];
		}

		return null;
	}

	protected function findDeliveryReportByMessageId($json, $messageId)
	{
		if ($messageId === '') return null;
		if (!is_array($json)) return null;

		$results = null;
		if (isset($json['results']) && is_array($json['results'])) {
			$results = $json['results'];
		} elseif (isset($json['reports']) && is_array($json['reports'])) {
			$results = $json['reports'];
		} elseif (isset($json[0]) && is_array($json[0])) {
			$results = $json;
		}

		if (!is_array($results)) return null;

		foreach ($results as $item) {
			if (!is_array($item)) continue;
			if (isset($item['messageId']) && (string)$item['messageId'] === $messageId) {
				return $item;
			}
			if (isset($item['messageID']) && (string)$item['messageID'] === $messageId) {
				return $item;
			}
		}

		return null;
	}
}
