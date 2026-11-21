<?php
require_once __DIR__ . '/config.php';

function support_chat_ensure_storage() {
	$dir = SUPPORT_CHAT_DATA_DIR;
	$threads = $dir . '/threads';
	if (!is_dir($threads)) {
		@mkdir($threads, 0775, true);
	}
	$htaccess = $dir . '/.htaccess';
	if (!is_file($htaccess)) {
		@file_put_contents($htaccess, "Require all denied\nDeny from all\n");
	}
	$index = $dir . '/index.json';
	if (!is_file($index)) {
		@file_put_contents($index, json_encode(array('threads' => array()), JSON_PRETTY_PRINT));
	}
}

function support_chat_json_read($path) {
	if (!is_file($path)) {
		return null;
	}
	$raw = @file_get_contents($path);
	if ($raw === false || $raw === '') {
		return null;
	}
	$data = json_decode($raw, true);
	return is_array($data) ? $data : null;
}

function support_chat_json_write($path, $data) {
	$tmp = $path . '.tmp';
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	if ($json === false) {
		return false;
	}
	if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
		return false;
	}
	return @rename($tmp, $path);
}

function support_chat_client_ip() {
	$keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
	foreach ($keys as $key) {
		if (!empty($_SERVER[$key])) {
			$parts = explode(',', $_SERVER[$key]);
			return trim($parts[0]);
		}
	}
	return '0.0.0.0';
}

function support_chat_rate_ok() {
	support_chat_ensure_storage();
	$ip = preg_replace('/[^0-9a-fA-F:\.]/', '', support_chat_client_ip());
	$file = SUPPORT_CHAT_DATA_DIR . '/rate_' . md5($ip) . '.json';
	$now = time();
	$data = support_chat_json_read($file);
	if (!$data) {
		$data = array('hits' => array());
	}
	$hits = array();
	foreach ($data['hits'] as $ts) {
		if ($now - intval($ts) < 3600) {
			$hits[] = intval($ts);
		}
	}
	if (count($hits) >= SUPPORT_CHAT_RATE_PER_HOUR) {
		return false;
	}
	$hits[] = $now;
	support_chat_json_write($file, array('hits' => $hits));
	return true;
}

function support_chat_new_id() {
	try {
		return bin2hex(random_bytes(16));
	} catch (Exception $e) {
		return sha1(uniqid(mt_rand(), true));
	}
}

function support_chat_thread_path($id) {
	$id = preg_replace('/[^a-f0-9]/', '', strtolower($id));
	return SUPPORT_CHAT_DATA_DIR . '/threads/' . $id . '.json';
}

function support_chat_load_thread($id) {
	support_chat_ensure_storage();
	return support_chat_json_read(support_chat_thread_path($id));
}

function support_chat_save_thread($thread) {
	support_chat_ensure_storage();
	$path = support_chat_thread_path($thread['id']);
	if (!support_chat_json_write($path, $thread)) {
		return false;
	}
	$indexPath = SUPPORT_CHAT_DATA_DIR . '/index.json';
	$index = support_chat_json_read($indexPath);
	if (!$index) {
		$index = array('threads' => array());
	}
	$summary = array(
		'id' => $thread['id'],
		'created_at' => $thread['created_at'],
		'updated_at' => $thread['updated_at'],
		'customer_name' => $thread['customer_name'],
		'customer_email' => $thread['customer_email'],
		'status' => $thread['status'],
		'preview' => support_chat_preview($thread),
		'message_count' => count($thread['messages'])
	);
	$found = false;
	foreach ($index['threads'] as $i => $row) {
		if ($row['id'] === $thread['id']) {
			$index['threads'][$i] = $summary;
			$found = true;
			break;
		}
	}
	if (!$found) {
		array_unshift($index['threads'], $summary);
	}
	usort($index['threads'], function ($a, $b) {
		return strcmp($b['updated_at'], $a['updated_at']);
	});
	return support_chat_json_write($indexPath, $index);
}

function support_chat_preview($thread) {
	if (empty($thread['messages'])) {
		return '';
	}
	$last = $thread['messages'][count($thread['messages']) - 1];
	$text = isset($last['text']) ? $last['text'] : '';
	if (function_exists('mb_substr')) {
		return mb_substr($text, 0, 80);
	}
	return substr($text, 0, 80);
}

function support_chat_public_thread($thread) {
	$out = array(
		'id' => $thread['id'],
		'status' => $thread['status'],
		'messages' => array()
	);
	foreach ($thread['messages'] as $msg) {
		$out['messages'][] = array(
			'role' => $msg['role'],
			'text' => $msg['text'],
			'at' => $msg['at']
		);
	}
	return $out;
}

function support_chat_notify($thread, $text) {
	$to = SUPPORT_CHAT_NOTIFY_EMAIL;
	if (!$to) {
		return;
	}
	$subject = 'Payroll support chat — ' . $thread['customer_name'];
	$body = "New customer message on payroll support chat.\n\n";
	$body .= "Name: " . $thread['customer_name'] . "\n";
	$body .= "Email: " . $thread['customer_email'] . "\n";
	$body .= "Page: " . $thread['page_url'] . "\n\n";
	$body .= $text . "\n\n";
	$body .= "Reply in the inbox: /support_chat/inbox.php\n";
	$headers = "From: " . SUPPORT_CHAT_FROM_EMAIL . "\r\n";
	$headers .= "Reply-To: " . $thread['customer_email'] . "\r\n";
	require_once __DIR__ . '/../engine/staging.php';
	if (payroll_staging_outbound_blocked('mail')) {
		return;
	}
	@mail($to, $subject, $body, $headers);
}

function support_chat_clean($value, $max) {
	$value = trim((string)$value);
	$value = str_replace(array("\0", "\r"), '', $value);
	if (function_exists('mb_substr')) {
		return mb_substr($value, 0, $max);
	}
	return substr($value, 0, $max);
}

function support_chat_json_input() {
	$raw = file_get_contents('php://input');
	if ($raw) {
		$data = json_decode($raw, true);
		if (is_array($data)) {
			return $data;
		}
	}
	return $_POST;
}

function support_chat_respond($payload, $code = 200) {
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($payload);
	exit;
}

function support_chat_admin_ok() {
	if (session_status() !== PHP_SESSION_ACTIVE) {
		@session_start();
	}
	return !empty($_SESSION['support_chat_admin']);
}
