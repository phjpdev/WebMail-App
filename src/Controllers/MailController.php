<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\HtmlSanitizer;
use App\Services\AliasService;
use App\Services\FilterService;
use App\Services\FolderCache;
use App\Services\ImapService;

class MailController
{
    public function home(): void
    {
        requireAuth();
        $this->markFilterPending();
        redirect('folder/' . encode_folder_path('INBOX'));
    }

    /**
     * @param array<string, string> $params
     */
    public function folder(array $params): void
    {
        requireAuth();
        $this->markFilterPending();

        $folderPath = decode_folder_path($params['folderB64'] ?? '');
        if ($folderPath === '') {
            error_page(404, 'Folder not found.');
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $query = trim($_GET['q'] ?? '');
        $folderData = FolderCache::load();
        $folders = $folderData['folders'];
        $imapConnected = $folderData['connected'];
        $imapError = $folderData['error'];

        if ($imapConnected && !$this->folderExists($folders, $folderPath)) {
            flash('error', 'Folder not found on mail server.');
            redirect('folder/' . encode_folder_path('INBOX'));
        }

        $perPage = mail_per_page();
        $list = ['messages' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];

        if ($imapConnected) {
            $imap = new ImapService();
            if ($imap->connect()) {
                $list = $query !== ''
                    ? $imap->searchMessages($folderPath, $query, $page, $perPage)
                    : $imap->listMessages($folderPath, $page, $perPage);
            } else {
                $imapConnected = false;
                $imapError = $imap->getLastError();
            }
        }

        $prefs = user_preferences();
        $this->renderMailView('mail/list', [
            'title' => $this->folderDisplayName($folders, $folderPath),
            'folderPath' => $folderPath,
            'folderB64' => encode_folder_path($folderPath),
            'folders' => $folders,
            'unreadCounts' => $folderData['unread_counts'] ?? [],
            'activeFolder' => $folderPath,
            'messages' => $list['messages'],
            'page' => $list['page'],
            'totalPages' => $list['total_pages'],
            'totalMessages' => $list['total'],
            'searchQuery' => $query,
            'imapConnected' => $imapConnected,
            'imapError' => $imapError,
            'pollInterval' => $prefs['poll_interval'] ?? config('app')['mail_poll_interval'],
            'filterPending' => !empty($_SESSION['_filter_pending']),
            'perPage' => $perPage,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function folderSync(array $params): void
    {
        requireAuth();

        header('Content-Type: application/json; charset=utf-8');

        $folderPath = decode_folder_path($params['folderB64'] ?? '');
        if ($folderPath === '') {
            http_response_code(404);
            echo json_encode(['error' => 'Folder not found']);
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $query = trim($_GET['q'] ?? '');

        $imap = new ImapService();
        if (!$imap->connect()) {
            http_response_code(503);
            echo json_encode(['error' => $imap->getLastError()]);
            return;
        }

        $perPage = mail_per_page();
        $list = $query !== ''
            ? $imap->searchMessages($folderPath, $query, $page, $perPage)
            : $imap->listMessages($folderPath, $page, $perPage);
        $messages = [];

        foreach ($list['messages'] as $msg) {
            $uid = (int) $msg['uid'];
            $messages[] = [
                'uid' => $uid,
                'from' => format_mail_from($msg['from'] ?? ''),
                'subject' => $msg['subject'] ?? '(no subject)',
                'date' => format_mail_date($msg['date'] ?? ''),
                'seen' => (bool) ($msg['seen'] ?? false),
                'flagged' => (bool) ($msg['flagged'] ?? false),
                'url' => message_url($folderPath, $uid),
            ];
        }

        echo json_encode([
            'total' => $list['total'],
            'page' => $list['page'],
            'total_pages' => $list['total_pages'],
            'messages' => $messages,
        ]);
    }

    public function foldersUnread(): void
    {
        requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        $folderData = FolderCache::load(true);
        echo json_encode(['unread_counts' => $folderData['unread_counts'] ?? []]);
    }

    /**
     * @param array<string, string> $params
     */
    public function messageSync(array $params): void
    {
        requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        $folderPath = decode_folder_path($params['folderB64'] ?? '');
        $uid = (int) ($params['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            http_response_code(404);
            echo json_encode(['exists' => false]);
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            http_response_code(503);
            echo json_encode(['error' => $imap->getLastError()]);
            return;
        }

        echo json_encode(['exists' => $imap->messageExists($folderPath, $uid)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function read(array $params): void
    {
        requireAuth();

        $folderPath = decode_folder_path($params['folderB64'] ?? '');
        $uid = (int) ($params['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            error_page(404);
        }

        $folderData = FolderCache::load();
        $folders = $folderData['folders'];

        $imap = new ImapService();
        if (!$imap->connect()) {
            flash('error', $imap->getLastError());
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $message = $imap->getMessageByUid($folderPath, $uid);

        if ($message === null) {
            flash('error', 'Message not found.');
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $imap->markSeen($folderPath, $uid);

        // Reflect the just-read message locally instead of clearing and re-listing
        // every folder over IMAP (which made opening a message slow).
        if (($folderData['unread_counts'][$folderPath] ?? 0) > 0) {
            $folderData['unread_counts'][$folderPath]--;
        }

        $replyFrom = (new AliasService())->userAlias(Auth::user()['id'] ?? null);

        $prefs = user_preferences();
        $this->renderMailView('mail/read', [
            'title' => $message['subject'] ?: '(no subject)',
            'folderPath' => $folderPath,
            'folderB64' => encode_folder_path($folderPath),
            'folders' => $folders,
            'unreadCounts' => $folderData['unread_counts'] ?? [],
            'activeFolder' => $folderPath,
            'message' => $message,
            'sanitizedHtml' => HtmlSanitizer::sanitize($message['html']),
            'replyFrom' => $replyFrom,
            'moveTargets' => array_values(array_filter(
                $folders,
                fn ($f) => $f['path'] !== $folderPath
            )),
            'imapConnected' => true,
            'imapError' => '',
            'pollInterval' => $prefs['poll_interval'] ?? config('app')['mail_poll_interval'],
        ]);
    }

    public function attachment(): void
    {
        requireAuth();

        $folderPath = decode_folder_path($_GET['folder'] ?? '');
        $uid = (int) ($_GET['uid'] ?? 0);
        $partId = $_GET['part'] ?? '';
        $inline = ($_GET['disposition'] ?? '') === 'inline';

        if ($folderPath === '' || $uid <= 0 || $partId === '') {
            error_page(404);
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            error_page(500, 'IMAP connection failed.');
        }

        $attachment = $imap->getAttachment($folderPath, $uid, $partId);
        if ($attachment === null) {
            error_page(404, 'Attachment not found.');
        }

        $mime = $attachment['mime'];
        $canInline = $inline && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');

        header('Content-Type: ' . $mime);
        header(
            'Content-Disposition: ' . ($canInline ? 'inline' : 'attachment')
            . '; filename="' . addslashes($attachment['filename']) . '"'
        );
        header('Content-Length: ' . strlen($attachment['content']));
        echo $attachment['content'];
        exit;
    }

    public function move(): void
    {
        requireAuth();
        verify_csrf_or_fail();

        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        $targetPath = $_POST['target_folder'] ?? '';

        if ($folderPath === '' || $uid <= 0 || $targetPath === '') {
            $this->actionError('Invalid move request.', 'folder/' . encode_folder_path('INBOX'));
        }

        $this->performMove($folderPath, [$uid], $targetPath, 'folder/' . encode_folder_path($folderPath));
    }

    public function trash(): void
    {
        requireAuth();
        verify_csrf_or_fail();

        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        $trashPath = trash_folder_path();

        if ($folderPath === '' || $uid <= 0) {
            $this->actionError('Invalid delete request.', 'folder/' . encode_folder_path('INBOX'));
        }

        $this->performMove($folderPath, [$uid], $trashPath, 'folder/' . encode_folder_path($folderPath), 'Message moved to Trash.');
    }

    public function bulkMove(): void
    {
        requireAuth();
        verify_csrf_or_fail();

        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uids = array_map('intval', $_POST['uids'] ?? []);
        $uids = array_values(array_filter($uids, fn ($u) => $u > 0));
        $targetPath = $_POST['target_folder'] ?? '';

        if ($folderPath === '' || $uids === [] || $targetPath === '') {
            flash('error', 'Invalid bulk move request.');
            redirect('folder/' . encode_folder_path($folderPath ?: 'INBOX'));
        }

        $this->performMove($folderPath, $uids, $targetPath, 'folder/' . encode_folder_path($folderPath));
    }

    public function bulkTrash(): void
    {
        requireAuth();
        verify_csrf_or_fail();

        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uids = array_map('intval', $_POST['uids'] ?? []);
        $uids = array_values(array_filter($uids, fn ($u) => $u > 0));

        if ($folderPath === '' || $uids === []) {
            flash('error', 'Invalid bulk delete request.');
            redirect('folder/' . encode_folder_path($folderPath ?: 'INBOX'));
        }

        $this->performMove($folderPath, $uids, trash_folder_path(), 'folder/' . encode_folder_path($folderPath), 'Selected messages moved to Trash.');
    }

    public function markRead(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        $this->setSeenFlag(true);
    }

    public function markUnread(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        $this->setSeenFlag(false);
    }

    public function flag(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        $this->setFlaggedFlag(true);
    }

    public function unflag(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        $this->setFlaggedFlag(false);
    }

    public function spam(): void
    {
        requireAuth();
        verify_csrf_or_fail();

        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            $this->actionError('Invalid request.', 'folder/' . encode_folder_path('INBOX'));
        }

        $this->performMove($folderPath, [$uid], spam_folder_path(), 'folder/' . encode_folder_path($folderPath), 'Message moved to Spam.');
    }

    public function bulkMarkRead(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        $this->setSeenFlagBulk(true);
    }

    public function bulkMarkUnread(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        $this->setSeenFlagBulk(false);
    }

    public function runFilter(): void
    {
        requireAuth();
        verify_csrf_or_fail();

        header('Content-Type: application/json; charset=utf-8');

        FilterService::clearSessionFlag();
        $result = FilterService::runIfNeeded(true);
        unset($_SESSION['_filter_pending']);

        echo json_encode($result ?? ['processed' => 0, 'moved' => 0, 'errors' => [], 'duration_ms' => 0, 'done' => true]);
    }

    /**
     * @param list<int> $uids
     */
    private function performMove(string $folderPath, array $uids, string $targetPath, string $redirect, ?string $successMsg = null): void
    {
        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        $folders = FolderCache::load()['folders'];
        if (!$this->folderExists($folders, $targetPath)) {
            $this->actionError('Target folder not found on mail server.', $redirect);
        }

        $moved = 0;
        $errors = 0;
        foreach ($uids as $uid) {
            if ($imap->moveMessage($folderPath, $uid, $targetPath)) {
                $moved++;
            } else {
                $errors++;
            }
        }

        (new FolderCache())->clear();

        if (wants_json()) {
            json_response([
                'ok' => $moved > 0,
                'moved' => $moved,
                'errors' => $errors,
                'uids' => array_values($uids),
                'target' => $targetPath,
            ], $moved > 0 ? 200 : 422);
        }

        if ($moved > 0) {
            flash('success', $successMsg ?? sprintf('%d message(s) moved successfully.', $moved));
        }
        if ($errors > 0) {
            flash('error', sprintf('%d message(s) could not be moved.', $errors));
        }

        redirect($redirect);
    }

    private function actionError(string $message, string $redirect): never
    {
        if (wants_json()) {
            json_response(['ok' => false, 'error' => $message], 422);
        }

        flash('error', $message);
        redirect($redirect);
    }

    private function setSeenFlag(bool $seen): void
    {
        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        $redirect = $_POST['redirect'] ?? ('folder/' . encode_folder_path($folderPath));

        if ($folderPath === '' || $uid <= 0) {
            $this->actionError('Invalid request.', 'folder/' . encode_folder_path('INBOX'));
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        if ($seen) {
            $imap->markSeen($folderPath, $uid);
        } else {
            $imap->markUnseen($folderPath, $uid);
        }

        (new FolderCache())->clear();

        if (wants_json()) {
            json_response(['ok' => true, 'seen' => $seen, 'uid' => $uid]);
        }

        flash('success', $seen ? 'Marked as read.' : 'Marked as unread.');
        redirect($redirect);
    }

    private function setFlaggedFlag(bool $flagged): void
    {
        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        $redirect = $_POST['redirect'] ?? ('folder/' . encode_folder_path($folderPath));

        if ($folderPath === '' || $uid <= 0) {
            $this->actionError('Invalid request.', 'folder/' . encode_folder_path('INBOX'));
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        if ($flagged) {
            $imap->markFlagged($folderPath, $uid);
        } else {
            $imap->markUnflagged($folderPath, $uid);
        }

        if (wants_json()) {
            json_response(['ok' => true, 'flagged' => $flagged, 'uid' => $uid]);
        }

        flash('success', $flagged ? 'Marked as important.' : 'Importance removed.');
        redirect($redirect);
    }

    private function setSeenFlagBulk(bool $seen): void
    {
        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uids = array_map('intval', $_POST['uids'] ?? []);
        $uids = array_values(array_filter($uids, fn ($u) => $u > 0));
        $redirect = 'folder/' . encode_folder_path($folderPath ?: 'INBOX');

        if ($folderPath === '' || $uids === []) {
            $this->actionError('No messages selected.', $redirect);
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        foreach ($uids as $uid) {
            if ($seen) {
                $imap->markSeen($folderPath, $uid);
            } else {
                $imap->markUnseen($folderPath, $uid);
            }
        }

        (new FolderCache())->clear();

        if (wants_json()) {
            json_response(['ok' => true, 'seen' => $seen, 'uids' => $uids]);
        }

        flash('success', sprintf('%d message(s) marked as %s.', count($uids), $seen ? 'read' : 'unread'));
        redirect($redirect);
    }

    /**
     * @param list<array{path: string, name: string}> $folders
     */
    private function folderExists(array $folders, string $path): bool
    {
        foreach ($folders as $folder) {
            if ($folder['path'] === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{path: string, name: string}> $folders
     */
    private function folderDisplayName(array $folders, string $path): string
    {
        foreach ($folders as $folder) {
            if ($folder['path'] === $path) {
                return $folder['path'] === 'INBOX' ? 'Inbox' : $folder['name'];
            }
        }

        return $path === 'INBOX' ? 'Inbox' : $path;
    }

    private function markFilterPending(): void
    {
        if (!isset($_SESSION['_filter_ran'])) {
            $_SESSION['_filter_pending'] = true;
        }
    }

    private function renderMailView(string $viewName, array $data): void
    {
        if (!isset($data['unreadCounts'])) {
            $data['unreadCounts'] = FolderCache::load()['unread_counts'] ?? [];
        }

        $data['user'] = Auth::user();
        $data['authUser'] = Auth::user();
        $data['success'] = flash('success');
        $data['error'] = flash('error');
        $data['prefs'] = user_preferences();

        view($viewName, $data);
    }
}
