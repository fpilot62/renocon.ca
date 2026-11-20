-- Help → Admin Tutorial is a menu_manager row, not a hardcoded link.
-- Its LinkURL was left as '#', so the item shows and does nothing.
-- help_center.php in this repo has <section id="manager">, the Admin / Manager
-- tutorial (Approving Your Team). Point that row there.
-- Match by the label and the placeholder URL. Never by MenuID.
-- Safe to run more than once: after the first run the URL is no longer '#'.
-- Run on the payroll database after a backup. This file does not deploy itself.

UPDATE menu_manager
SET LinkURL = 'help_center.php#manager'
WHERE UPPER(TRIM(MenuTitle)) = 'ADMIN TUTORIAL'
  AND TRIM(LinkURL) = '#';
