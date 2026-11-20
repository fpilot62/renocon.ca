<?php
/**
 * Drop this one line on any payroll page, before </body>:
 *   <?php include $_SERVER['DOCUMENT_ROOT'] . '/support_chat/include.php'; ?>
 */
$supportChatBase = '/support_chat';
?>
<script src="<?php echo htmlspecialchars($supportChatBase, ENT_QUOTES, 'UTF-8'); ?>/widget.js"></script>
