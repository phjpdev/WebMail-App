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
        redirect('folder/' . encode_folder_path(default_mail_folder()));
    }

    /**
     * @param array<string, string> $params
     */
    public function folder(array $params): void
    {
        requireAuth();

        $folderPath = decode_folder_path($params['folderB64'] ?? '');
        if ($folderPath === '') {
            error_page(404, 'Folder not found.');
        }
        assert_folder_access($folderPath);

        // Load sidebar cache, move routed mail out of INBOX, then list (no throttle).
        FolderCache::load(skipUnreadRefresh: true);
        FilterService::runBeforeMailList();
        FolderCache::syncUnreadBadges($folderPath);
        $folderData = FolderCache::load(skipUnreadRefresh: true);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $query = trim($_GET['q'] ?? '');
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
        if (!FolderCache::canAccess($folderPath)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        FolderCache::load(skipUnreadRefresh: true);

        // Move routed mail before returning the list (same request, no extra XHR).
        FilterService::runBeforeMailList();
        FolderCache::syncUnreadBadges($folderPath);

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
                'reply_url' => url('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid),
                'reply_all_url' => url('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid),
                'forward_url' => url('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid),
            ];
        }

        echo json_encode([
            'total' => $list['total'],
            'page' => $list['page'],
            'total_pages' => $list['total_pages'],
            'messages' => $messages,
            'unread_counts' => FolderCache::load(skipUnreadRefresh: true)['unread_counts'] ?? [],
        ]);
    }

    public function foldersUnread(): void
    {
        requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        // Refresh expired counts so sidebar badges stay accurate between filter runs.
        $folderData = FolderCache::load(skipUnreadRefresh: false);
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
        if (!FolderCache::canAccess($folderPath)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
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
    public function messagePane(array $params): void
    {
        requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        $folderPath = decode_folder_path($params['folderB64'] ?? '');
        $uid = (int) ($params['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Message not found']);
            return;
        }

        $context = $this->loadMessageContext($folderPath, $uid);
        if ($context === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Message not found']);
            return;
        }

        $html = view_string('mail/pane-read', $context);

        echo json_encode([
            'ok' => true,
            'uid' => $uid,
            'subject' => $context['message']['subject'] ?: '(no subject)',
            'seen' => !empty($context['message']['seen']),
            'was_unread' => $context['wasUnread'],
            'html' => $html,
            'unread_counts' => $context['unreadCounts'],
        ]);
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

        $context = $this->loadMessageContext($folderPath, $uid);
        if ($context === null) {
            $imap = new ImapService();
            if (!$imap->connect()) {
                flash('error', $imap->getLastError());
            } else {
                flash('error', 'Message not found.');
            }
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $this->renderMailView('mail/read', [
            'title' => $context['message']['subject'] ?: '(no subject)',
            'folderPath' => $context['folderPath'],
            'folderB64' => $context['folderB64'],
            'folders' => $context['folders'],
            'unreadCounts' => $context['unreadCounts'],
            'activeFolder' => $context['folderPath'],
            'message' => $context['message'],
            'sanitizedHtml' => $context['sanitizedHtml'],
            'replyFrom' => $context['replyFrom'],
            'moveTargets' => $context['moveTargets'],
            'imapConnected' => true,
            'imapError' => '',
            'pollInterval' => $context['pollInterval'],
        ]);
    }

    /**
     * @return array{
     *     folderPath: string,
     *     folderB64: string,
     *     folders: list<array{path: string, name: string, delimiter?: string}>,
     *     unreadCounts: array<string, int>,
     *     message: array<string, mixed>,
     *     sanitizedHtml: string,
     *     replyFrom: string|null,
     *     moveTargets: list<array{path: string, name: string}>,
     *     pollInterval: int,
     *     wasUnread: bool
     * }|null
     */
    private function loadMessageContext(string $folderPath, int $uid): ?array
    {
        assert_folder_access($folderPath);

        $folderData = FolderCache::load(skipUnreadRefresh: true);
        FolderCache::syncUnreadBadges($folderPath);
        $folderData['unread_counts'] = FolderCache::load(skipUnreadRefresh: true)['unread_counts'] ?? $folderData['unread_counts'];
        $folders = $folderData['folders'];

        $imap = new ImapService();
        if (!$imap->connect()) {
            return null;
        }

        $message = $imap->getMessageByUid($folderPath, $uid);
        if ($message === null) {
            return null;
        }

        $wasUnread = empty($message['seen']);
        $imap->markSeen($folderPath, $uid);
        $message['seen'] = true;

        if ($wasUnread) {
            $updatedCounts = FolderCache::bumpUnread($folderPath, -1);
            if ($updatedCounts !== []) {
                $folderData['unread_counts'] = $updatedCounts;
            } elseif (($folderData['unread_counts'][$folderPath] ?? 0) > 0) {
                $folderData['unread_counts'][$folderPath]--;
            }
        }

        $aliasService = new AliasService();
        $replyFrom = $aliasService->resolveReplyAlias($message['delivered_to'] ?? null, $message['to'] ?? null)
            ?? $aliasService->userAlias(Auth::user()['id'] ?? null);

        $prefs = user_preferences();

        return [
            'folderPath' => $folderPath,
            'folderB64' => encode_folder_path($folderPath),
            'folders' => $folders,
            'unreadCounts' => $folderData['unread_counts'] ?? [],
            'message' => $message,
            'sanitizedHtml' => HtmlSanitizer::sanitize($message['html']),
            'replyFrom' => $replyFrom,
            'moveTargets' => array_values(array_filter(
                $folders,
                fn ($f) => $f['path'] !== $folderPath
            )),
            'pollInterval' => (int) ($prefs['poll_interval'] ?? config('app')['mail_poll_interval']),
            'wasUnread' => $wasUnread,
        ];
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
        assert_folder_access($folderPath);

        $imap = new ImapService();
        if (!$imap->connect()) {
            error_page(500, 'IMAP connection failed.');
        }

        $attachment = $imap->getAttachment($folderPath, $uid, $partId);
        if ($attachment === null) {
            error_page(404, 'Attachment not found.');
        }

        $mime = $this->safeAttachmentMime($attachment['mime'] ?? '');
        $canInline = $inline && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
        $filename = $this->safeAttachmentName($attachment['filename'] ?? 'attachment');

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header(
            'Content-Disposition: ' . ($canInline ? 'inline' : 'attachment')
            . '; filename="' . $filename . '"'
            . "; filename*=UTF-8''" . rawurlencode($filename)
        );
        header('Content-Length: ' . strlen($attachment['content']));
        echo $attachment['content'];
        exit;
    }

    /**
     * Strip path separators and header-injection characters from a download
     * filename so it is safe to echo into a Content-Disposition header.
     */
    private function safeAttachmentName(string $name): string
    {
        $name = str_replace(["\r", "\n", "\0", '"', '\\', '/'], '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            return 'attachment';
        }

        return mb_substr($name, 0, 200);
    }

    /**
     * Validate an attachment MIME type, defaulting unknown/garbage to a safe
     * binary type so the browser never sniffs and executes the content.
     */
    private function safeAttachmentMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        if ($mime === '' || !preg_match('#^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$#', $mime)) {
            return 'application/octet-stream';
        }

        return $mime;
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
        assert_folder_access($folderPath);
        assert_folder_access($targetPath);

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
        assert_folder_access($folderPath);

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
        assert_folder_access($folderPath);
        assert_folder_access($targetPath);

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
        assert_folder_access($folderPath);

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
        assert_folder_access($folderPath);

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
            // Standard destinations like Trash/Spam may not exist yet on a fresh
            // mailbox — create them on demand instead of failing the move.
            if (!$imap->folderExistsOnServer($targetPath) && !$imap->createFolder($targetPath)) {
                $this->actionError('Target folder could not be created on the mail server.', $redirect);
            }
            (new FolderCache())->clear();
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

        if (wants_json()) {
            // The client tells us how many of the moved messages were unread so
            // we can adjust both folder badges without a costly status sweep.
            $unreadDelta = max(0, (int) ($_POST['unread_delta'] ?? 0));
            if ($unreadDelta > 0 && $moved > 0) {
                FolderCache::bumpUnread($folderPath, -$unreadDelta);
                $counts = FolderCache::bumpUnread($targetPath, $unreadDelta);
            } else {
                $counts = FolderCache::bumpUnread($folderPath, 0);
            }

            json_response([
                'ok' => $moved > 0,
                'moved' => $moved,
                'errors' => $errors,
                'uids' => array_values($uids),
                'target' => $targetPath,
                'unread_counts' => $counts,
            ], $moved > 0 ? 200 : 422);
        }

        // Non-AJAX (full page) path: drop the cache so the next render is fresh.
        (new FolderCache())->clear();

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
        assert_folder_access($folderPath);

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        // Only adjust the badge if the flag actually changes, so repeated clicks
        // (or acting on an already-read message) can't drive the count negative
        // or inflate it.
        // Lightweight overview fetch — getMessageByUid() would download the full
        // MIME body just to learn whether the \\Seen flag is already set.
        $overview = $imap->getMessageOverviewByUid($folderPath, $uid);
        $alreadySeen = $overview !== null ? $overview['seen'] : null;

        if ($seen) {
            $imap->markSeen($folderPath, $uid);
        } else {
            $imap->markUnseen($folderPath, $uid);
        }

        $delta = 0;
        if ($alreadySeen === null) {
            $delta = $seen ? -1 : 1;
        } elseif ($seen && !$alreadySeen) {
            $delta = -1;
        } elseif (!$seen && $alreadySeen) {
            $delta = 1;
        }

        $counts = FolderCache::bumpUnread($folderPath, $delta);

        if (wants_json()) {
            json_response(['ok' => true, 'seen' => $seen, 'uid' => $uid, 'unread_counts' => $counts]);
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
        assert_folder_access($folderPath);

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
            json_response(['ok' => true, 'flagged' => $flagged, 'uid' => $uid, 'unread_counts' => FolderCache::bumpUnread($folderPath, 0)]);
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
        assert_folder_access($folderPath);

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        $unreadDelta = 0;
        foreach ($uids as $uid) {
            $overview = $imap->getMessageOverviewByUid($folderPath, $uid);
            $wasSeen = $overview !== null ? $overview['seen'] : true;

            if ($seen) {
                if (!$wasSeen) {
                    $unreadDelta++;
                }
                $imap->markSeen($folderPath, $uid);
            } else {
                if ($wasSeen) {
                    $unreadDelta++;
                }
                $imap->markUnseen($folderPath, $uid);
            }
        }

        $counts = [];
        if ($unreadDelta > 0) {
            $counts = FolderCache::bumpUnread($folderPath, $seen ? -$unreadDelta : $unreadDelta);
        } else {
            $counts = FolderCache::bumpUnread($folderPath, 0);
        }

        if (wants_json()) {
            json_response(['ok' => true, 'seen' => $seen, 'uids' => $uids, 'unread_counts' => $counts]);
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
