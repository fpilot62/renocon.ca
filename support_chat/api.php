<?php
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type');
	exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$input = support_chat_json_input();
if (!$action && isset($input['action'])) {
	$action = $input['action'];
}

try {
	if ($action === 'start') {
		if (!support_chat_rate_ok()) {
			support_chat_respond(array('ok' => false, 'error' => 'Too many messages. Please wait and try again.'), 429);
		}
		$name = support_chat_clean(isset($input['name']) ? $input['name'] : '', 80);
		$email = support_chat_clean(isset($input['email']) ? $input['email'] : '', 120);
		$message = support_chat_clean(isset($input['message']) ? $input['message'] : '', SUPPORT_CHAT_MAX_MESSAGE);
		$pageUrl = support_chat_clean(isset($input['page_url']) ? $input['page_url'] : '', 300);
		if ($name === '' || $message === '') {
			support_chat_respond(array('ok' => false, 'error' => 'Name and message are required.'), 400);
		}
		if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			support_chat_respond(array('ok' => false, 'error' => 'Please enter a valid email so we can reach you.'), 400);
		}
		$now = gmdate('c');
		$thread = array(
			'id' => support_chat_new_id(),
			'token' => support_chat_new_id(),
			'created_at' => $now,
			'updated_at' => $now,
			'customer_name' => $name,
			'customer_email' => $email,
			'page_url' => $pageUrl,
			'ip' => support_chat_client_ip(),
			'status' => 'open',
			'messages' => array(
				array(
					'role' => 'customer',
					'text' => $message,
					'at' => $now
				)
			)
		);
		if (!support_chat_save_thread($thread)) {
			support_chat_respond(array('ok' => false, 'error' => 'Could not save the chat. Check folder permissions on support_chat_data.'), 500);
		}
		support_chat_notify($thread, $message);
		support_chat_respond(array(
			'ok' => true,
			'thread_id' => $thread['id'],
			'token' => $thread['token'],
			'thread' => support_chat_public_thread($thread)
		));
	}

	if ($action === 'send') {
		if (!support_chat_rate_ok()) {
			support_chat_respond(array('ok' => false, 'error' => 'Too many messages. Please wait and try again.'), 429);
		}
		$id = isset($input['thread_id']) ? $input['thread_id'] : '';
		$token = isset($input['token']) ? $input['token'] : '';
		$message = support_chat_clean(isset($input['message']) ? $input['message'] : '', SUPPORT_CHAT_MAX_MESSAGE);
		$thread = support_chat_load_thread($id);
		if (!$thread || !hash_equals($thread['token'], (string)$token)) {
			support_chat_respond(array('ok' => false, 'error' => 'Chat session expired. Start a new chat.'), 403);
		}
		if ($message === '') {
			support_chat_respond(array('ok' => false, 'error' => 'Message is required.'), 400);
		}
		$now = gmdate('c');
		$thread['messages'][] = array('role' => 'customer', 'text' => $message, 'at' => $now);
		$thread['updated_at'] = $now;
		$thread['status'] = 'open';
		if (!support_chat_save_thread($thread)) {
			support_chat_respond(array('ok' => false, 'error' => 'Could not save the message.'), 500);
		}
		support_chat_notify($thread, $message);
		support_chat_respond(array('ok' => true, 'thread' => support_chat_public_thread($thread)));
	}

	if ($action === 'poll') {
		$id = isset($_GET['thread_id']) ? $_GET['thread_id'] : (isset($input['thread_id']) ? $input['thread_id'] : '');
		$token = isset($_GET['token']) ? $_GET['token'] : (isset($input['token']) ? $input['token'] : '');
		$thread = support_chat_load_thread($id);
		if (!$thread || !hash_equals($thread['token'], (string)$token)) {
			support_chat_respond(array('ok' => false, 'error' => 'Chat session expired.'), 403);
		}
		support_chat_respond(array('ok' => true, 'thread' => support_chat_public_thread($thread)));
	}

	if ($action === 'admin_login') {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		$password = isset($input['password']) ? (string)$input['password'] : '';
		if (!hash_equals(SUPPORT_CHAT_ADMIN_PASSWORD, $password)) {
			support_chat_respond(array('ok' => false, 'error' => 'Wrong password.'), 403);
		}
		$_SESSION['support_chat_admin'] = 1;
		support_chat_respond(array('ok' => true));
	}

	if ($action === 'admin_logout') {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		unset($_SESSION['support_chat_admin']);
		support_chat_respond(array('ok' => true));
	}

	if ($action === 'admin_list') {
		if (!support_chat_admin_ok()) {
			support_chat_respond(array('ok' => false, 'error' => 'Login required.'), 401);
		}
		support_chat_ensure_storage();
		$index = support_chat_json_read(SUPPORT_CHAT_DATA_DIR . '/index.json');
		support_chat_respond(array('ok' => true, 'threads' => $index ? $index['threads'] : array()));
	}

	if ($action === 'admin_thread') {
		if (!support_chat_admin_ok()) {
			support_chat_respond(array('ok' => false, 'error' => 'Login required.'), 401);
		}
		$id = isset($_GET['thread_id']) ? $_GET['thread_id'] : (isset($input['thread_id']) ? $input['thread_id'] : '');
		$thread = support_chat_load_thread($id);
		if (!$thread) {
			support_chat_respond(array('ok' => false, 'error' => 'Thread not found.'), 404);
		}
		support_chat_respond(array(
			'ok' => true,
			'thread' => array(
				'id' => $thread['id'],
				'customer_name' => $thread['customer_name'],
				'customer_email' => $thread['customer_email'],
				'page_url' => $thread['page_url'],
				'status' => $thread['status'],
				'created_at' => $thread['created_at'],
				'updated_at' => $thread['updated_at'],
				'messages' => $thread['messages']
			)
		));
	}

	if ($action === 'admin_reply') {
		if (!support_chat_admin_ok()) {
			support_chat_respond(array('ok' => false, 'error' => 'Login required.'), 401);
		}
		$id = isset($input['thread_id']) ? $input['thread_id'] : '';
		$message = support_chat_clean(isset($input['message']) ? $input['message'] : '', SUPPORT_CHAT_MAX_MESSAGE);
		$thread = support_chat_load_thread($id);
		if (!$thread) {
			support_chat_respond(array('ok' => false, 'error' => 'Thread not found.'), 404);
		}
		if ($message === '') {
			support_chat_respond(array('ok' => false, 'error' => 'Message is required.'), 400);
		}
		$now = gmdate('c');
		$thread['messages'][] = array('role' => 'support', 'text' => $message, 'at' => $now);
		$thread['updated_at'] = $now;
		$thread['status'] = 'open';
		if (!support_chat_save_thread($thread)) {
			support_chat_respond(array('ok' => false, 'error' => 'Could not save the reply.'), 500);
		}
		if (!empty($thread['customer_email']) && filter_var($thread['customer_email'], FILTER_VALIDATE_EMAIL)) {
			$subject = 'Reply from payroll support';
			$body = "Hi " . $thread['customer_name'] . ",\n\n" . $message . "\n\nYou can also continue this chat on the payroll site.\n";
			require_once __DIR__ . '/../engine/staging.php';
			if (!payroll_staging_outbound_blocked('mail')) {
				@mail($thread['customer_email'], $subject, $body, "From: " . SUPPORT_CHAT_FROM_EMAIL . "\r\n");
			}
		}
		support_chat_respond(array(
			'ok' => true,
			'thread' => array(
				'id' => $thread['id'],
				'customer_name' => $thread['customer_name'],
				'customer_email' => $thread['customer_email'],
				'status' => $thread['status'],
				'messages' => $thread['messages']
			)
		));
	}

	if ($action === 'admin_close') {
		if (!support_chat_admin_ok()) {
			support_chat_respond(array('ok' => false, 'error' => 'Login required.'), 401);
		}
		$id = isset($input['thread_id']) ? $input['thread_id'] : '';
		$thread = support_chat_load_thread($id);
		if (!$thread) {
			support_chat_respond(array('ok' => false, 'error' => 'Thread not found.'), 404);
		}
		$thread['status'] = 'closed';
		$thread['updated_at'] = gmdate('c');
		support_chat_save_thread($thread);
		support_chat_respond(array('ok' => true));
	}

	support_chat_respond(array('ok' => false, 'error' => 'Unknown action.'), 400);
} catch (Exception $e) {
	support_chat_respond(array('ok' => false, 'error' => 'Chat is temporarily unavailable.'), 500);
}
