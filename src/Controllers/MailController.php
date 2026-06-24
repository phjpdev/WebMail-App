<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\HtmlSanitizer;
use App\Services\AliasService;
use App\Services\FilterService;
use App\Services\FolderCache;
use App\Services\ImapService;
use App\Services\MailCacheService;

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

        $folderPath = mail_folder_path($params['folderB64'] ?? '');
        if ($folderPath === '') {
            error_page(404, 'Folder not found.');
        }
        assert_folder_access($folderPath);

        $context = $this->buildFolderListContext($folderPath, $params);
        if ($context === null) {
            flash('error', 'Folder not found on mail server.');
            redirect('folder/' . encode_folder_path('INBOX'));
        }

        $this->renderMailView('mail/list', $context);
    }

    /**
     * AJAX fragment for fast folder switches (list column only).
     *
     * @param array<string, string> $params
     */
    public function folderFragment(array $params): void
    {
        requireAuth();
        releaseSessionLock();
        header('Content-Type: application/json; charset=utf-8');

        $folderPath = mail_folder_path($params['folderB64'] ?? '');
        if ($folderPath === '') {
            http_response_code(404);
            echo json_encode_safe(['ok' => false, 'error' => 'Folder not found']);
            exit;
        }
        if (!FolderCache::canAccess($folderPath)) {
            http_response_code(403);
            echo json_encode_safe(['ok' => false, 'error' => 'Access denied']);
            exit;
        }

        $context = $this->buildFolderListContext($folderPath, $params, ajaxFast: true);
        if ($context === null) {
            http_response_code(404);
            echo json_encode_safe(['ok' => false, 'error' => 'Folder not found']);
            exit;
        }

        echo json_encode_safe([
            'ok' => true,
            'folder_path' => $folderPath,
            'folder_b64' => $context['folderB64'],
            'title' => $context['title'],
            'url' => folder_url($folderPath),
            'html' => view_string('mail/list-column', $context),
            'unread_counts' => $context['unreadCounts'] ?? [],
        ]);
        exit;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function buildFolderListContext(string $folderPath, array $params, bool $ajaxFast = false): ?array
    {
        if ($folderPath === '') {
            return null;
        }

        $forceRefresh = ($params['refresh'] ?? $_GET['refresh'] ?? '') === '1';
        $fastOpen = $ajaxFast && !$forceRefresh;

        FolderCache::load(skipUnreadRefresh: true);

        if (!$fastOpen) {
            $filterResult = $this->maybeRunFilter($folderPath, $forceRefresh);
            if ($this->isFilterSource($folderPath) || ($filterResult['moved'] ?? 0) > 0) {
                FolderCache::syncUnreadBadges($folderPath);
            }
        }

        $folderData = FolderCache::load(skipUnreadRefresh: true);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $query = trim($_GET['q'] ?? '');
        $folders = $folderData['folders'];
        $imapConnected = $folderData['connected'];
        $imapError = $folderData['error'];

        if ($imapConnected && !$this->folderExists($folders, $folderPath)) {
            return null;
        }

        $perPage = mail_per_page();
        $list = ['messages' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
        $servedFromCache = false;

        if ($imapConnected && $query === '') {
            $cached = MailCacheService::listFromCache($folderPath, $page, $perPage);
            if ($cached !== null && ($fastOpen || !$forceRefresh) && !MailCacheService::isStale($folderPath)) {
                $list = $cached;
                $list['from_cache'] = true;
                $servedFromCache = true;
            }
        }

        if ($imapConnected && !$servedFromCache) {
            $imap = new ImapService();
            if ($imap->connect()) {
                $list = $this->fetchFolderList($folderPath, $page, $perPage, $query, $imap, $forceRefresh);
                if ($forceRefresh) {
                    $sessionBadge = (int) ($folderData['unread_counts'][$folderPath] ?? 0);
                    if ($sessionBadge > 0 || MailCacheService::badgeAheadOfIndex($folderPath)) {
                        MailCacheService::reconcileFolderBadge($imap, $folderPath);
                        if (!empty($list['from_cache'])) {
                            $refreshed = MailCacheService::listFromCache($folderPath, $page, $perPage);
                            if ($refreshed !== null) {
                                $list = $refreshed;
                            }
                        }
                    }
                }
                $folderData = FolderCache::load(skipUnreadRefresh: true);
            } else {
                $imapConnected = false;
                $imapError = $imap->getLastError();
            }
        }

        if ($imapConnected && $query === '') {
            $folderUnread = MailCacheService::reconcileBadgeFromIndex($folderPath, $list['messages']);
            MailCacheService::reconcileAllIndexedBadges();
            $folderData = FolderCache::load(skipUnreadRefresh: true);
            $folderData['unread_counts'][$folderPath] = $folderUnread;
        }

        $prefs = user_preferences();

        return [
            'title' => $this->folderDisplayName($folders, $folderPath),
            'folderPath' => $folderPath,
            'folderB64' => encode_folder_path($folderPath),
            'folders' => $folders,
            'unreadCounts' => $folderData['unread_counts'] ?? [],
            'unreadCount' => folder_shows_unread_badge($folderPath)
                ? (int) ($folderData['unread_counts'][$folderPath] ?? 0)
                : 0,
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
        ];
    }

    private function filterSourceFolder(): string
    {
        return (string) (config('app')['filter_source_folder'] ?? 'INBOX');
    }

    private function isFilterSource(string $folderPath): bool
    {
        $source = $this->filterSourceFolder();

        return $folderPath === $source || strtoupper($folderPath) === 'INBOX';
    }

    /**
     * Run filter on inbox visits; throttle on other folders and poll sync.
     *
     * @return array{processed: int, moved: int, errors: list<string>, duration_ms: int, done?: bool}|null
     */
    private function maybeRunFilter(string $folderPath, bool $force = false): ?array
    {
        if ($this->isFilterSource($folderPath)) {
            return FilterService::runBeforeMailList();
        }

        return FilterService::runBackground($force);
    }

    /**
     * @return array{messages: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int, from_cache?: bool}
     */
    private function fetchFolderList(
        string $folderPath,
        int $page,
        int $perPage,
        string $query,
        ImapService $imap,
        bool $forceRefresh = false,
    ): array {
        if ($query === '' && !$forceRefresh) {
            $cached = MailCacheService::listFromCache($folderPath, $page, $perPage);
            if ($cached !== null && !MailCacheService::isStale($folderPath)) {
                $cached['from_cache'] = true;

                return $cached;
            }
        }

        $list = $query !== ''
            ? $imap->searchMessages($folderPath, $query, $page, $perPage)
            : $imap->listMessages($folderPath, $page, $perPage);

        if ($query === '') {
            MailCacheService::upsertIndexMessages($folderPath, $list['messages'], (int) $list['total']);
        }

        $list['from_cache'] = false;

        return $list;
    }

    /**
     * Warm header cache for common folders (XHR after login — no cron).
     */
    public function mailBootstrap(): void
    {
        requireAuth();
        releaseSessionLock();
        header('Content-Type: application/json; charset=utf-8');

        $folderData = FolderCache::load(skipUnreadRefresh: true);
        if (!$folderData['connected']) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => $folderData['error'] ?: 'IMAP unavailable']);
            return;
        }

        $paths = $this->bootstrapFolderPaths($folderData['folders'], $_GET['folder'] ?? '');
        $paths = array_values(array_filter($paths, fn (string $p) => FolderCache::canAccess($p)));

        $imap = new ImapService();
        if (!$imap->connect()) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => $imap->getLastError()]);
            return;
        }

        $this->maybeRunFilter($this->filterSourceFolder());

        echo json_encode([
            'ok' => true,
            'synced' => MailCacheService::bootstrapSync($imap, $paths),
        ]);
    }

    /**
     * @param list<array{path: string, name: string}> $folders
     * @return list<string>
     */
    private function bootstrapFolderPaths(array $folders, string $activeFolderEncoded): array
    {
        $paths = [
            $this->filterSourceFolder(),
            'INBOX',
        ];

        if ($activeFolderEncoded !== '') {
            $active = decode_folder_path($activeFolderEncoded);
            if ($active !== '') {
                $paths[] = $active;
            }
        }

        foreach ($folders as $folder) {
            $path = $folder['path'];
            $lower = strtolower($path);
            if (str_contains($lower, 'sent') || str_contains($lower, 'draft')) {
                $paths[] = $path;
            }
        }

        $paths = array_values(array_unique(array_filter($paths)));

        return array_slice($paths, 0, 8);
    }

    /**
     * @param array<string, string> $params
     */
    public function folderSync(array $params): void
    {
        requireAuth();
        releaseSessionLock();

        header('Content-Type: application/json; charset=utf-8');

        $folderPath = mail_folder_path($params['folderB64'] ?? '');
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

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $query = trim($_GET['q'] ?? '');
        $light = ($_GET['light'] ?? '') === '1';
        $perPage = mail_per_page();

        // Lightweight poll: MySQL cache when fresh — re-sync when stale so moves
        // into Trash (and other folders) appear without a manual refresh.
        if ($light && $query === '') {
            $cached = MailCacheService::listFromCache($folderPath, $page, $perPage);
            if ($cached !== null && !MailCacheService::isStale($folderPath)) {
                $this->echoFolderSyncJson($folderPath, $cached);
                return;
            }
        }

        $forceFilter = ($_GET['force'] ?? '') === '1';

        if (!$light || $forceFilter) {
            $filterResult = $this->maybeRunFilter($folderPath, $forceFilter);
            if ($this->isFilterSource($folderPath) || ($filterResult['moved'] ?? 0) > 0) {
                FolderCache::syncUnreadBadges($folderPath);
            }
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            http_response_code(503);
            echo json_encode(['error' => $imap->getLastError()]);
            return;
        }

        $list = null;

        if ($query === '' && $page === 1 && !$light) {
            if ($forceFilter || MailCacheService::isStale($folderPath)) {
                MailCacheService::syncFolderHeaders($imap, $folderPath);
                $list = MailCacheService::listFromCache($folderPath, $page, $perPage);
            }
        }

        if ($list === null || $forceFilter) {
            $list = $query !== ''
                ? $imap->searchMessages($folderPath, $query, $page, $perPage)
                : $imap->listMessages($folderPath, $page, $perPage);

            if ($query === '') {
                MailCacheService::upsertIndexMessages($folderPath, $list['messages'], (int) $list['total']);
            }
        }

        if (!$light || $forceFilter) {
            $sessionBadge = (int) (FolderCache::load(skipUnreadRefresh: true)['unread_counts'][$folderPath] ?? 0);
            if ($forceFilter || $sessionBadge > 0 || MailCacheService::badgeAheadOfIndex($folderPath)) {
                MailCacheService::reconcileFolderBadge($imap, $folderPath);
            }
        }
        if ($query === '') {
            $refreshed = MailCacheService::listFromCache($folderPath, $page, $perPage);
            if ($refreshed !== null) {
                $list = $refreshed;
            }
        }

        $this->echoFolderSyncJson($folderPath, $list);
    }

    /**
     * @param array{messages: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int} $list
     */
    private function echoFolderSyncJson(string $folderPath, array $list): void
    {
        if (trim($_GET['q'] ?? '') === '') {
            MailCacheService::reconcileBadgeFromIndex($folderPath, $list['messages']);
            MailCacheService::reconcileAllIndexedBadges();
        }

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
                'has_attachment' => (bool) ($msg['has_attachment'] ?? false),
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
        releaseSessionLock();
        header('Content-Type: application/json; charset=utf-8');

        FilterService::runBackground(false);
        $folderData = FolderCache::load(skipUnreadRefresh: true);

        foreach ($folderData['folders'] ?? [] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '') {
                continue;
            }
            if (MailCacheService::hasFolderData($path)) {
                MailCacheService::reconcileBadgeFromIndex($path);
            } else {
                MailCacheService::syncBadgeFromIndex($path);
            }
        }

        echo json_encode(['unread_counts' => FolderCache::load(skipUnreadRefresh: true)['unread_counts'] ?? []]);
    }

    /**
     * @param array<string, string> $params
     */
    public function messageSync(array $params): void
    {
        requireAuth();
        releaseSessionLock();
        header('Content-Type: application/json; charset=utf-8');

        $folderPath = mail_folder_path($params['folderB64'] ?? '');
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
     * Deferred attachment hints for list rows (after fast overview load).
     *
     * @param array<string, string> $params
     */
    public function messageAttachments(array $params): void
    {
        requireAuth();
        releaseSessionLock();
        header('Content-Type: application/json; charset=utf-8');

        $folderPath = mail_folder_path($params['folderB64'] ?? '');
        if ($folderPath === '' || !FolderCache::canAccess($folderPath)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Access denied']);
            return;
        }

        $raw = trim($_GET['uids'] ?? '');
        if ($raw === '') {
            echo json_encode(['ok' => true, 'has_attachment' => []]);
            return;
        }

        $uids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), fn ($u) => $u > 0)));
        $uids = array_slice($uids, 0, 50);

        $imap = new ImapService();
        if (!$imap->connect() || !$imap->openFolder($folderPath)) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => $imap->getLastError()]);
            return;
        }

        echo json_encode([
            'ok' => true,
            'has_attachment' => $imap->batchHasAttachments($uids),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function messagePane(array $params): void
    {
        requireAuth();
        releaseSessionLock();
        header('Content-Type: application/json; charset=utf-8');

        $folderPath = mail_folder_path($params['folderB64'] ?? '');
        $uid = (int) ($params['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Message not found']);
            return;
        }

        $prefetch = ($_GET['prefetch'] ?? '') === '1';
        $deferred = null;
        $context = $this->loadMessageContext($folderPath, $uid, markRead: !$prefetch, deferred: $deferred);
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
            'was_unread' => $context['wasUnread'] && !$prefetch,
            'html' => $html,
            'unread_counts' => $context['unreadCounts'],
            'folder_unread' => (int) ($context['unreadCounts'][$folderPath] ?? 0),
        ]);

        if ($deferred !== null) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $deferred();
        }
    }

    /**
     * @param array<string, string> $params
     */
    public function read(array $params): void
    {
        requireAuth();

        $folderPath = mail_folder_path($params['folderB64'] ?? '');
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
    private function loadMessageContext(string $folderPath, int $uid, bool $markRead = true, ?callable &$deferred = null): ?array
    {
        assert_folder_access($folderPath);

        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $folders = $folderData['folders'];
        $unreadCounts = $folderData['unread_counts'] ?? [];

        $message = MailCacheService::getBody($folderPath, $uid);
        if ($message !== null) {
            return $this->assembleMessageContext(
                $folderPath,
                $folders,
                $unreadCounts,
                $message,
                $uid,
                $markRead,
                $deferred,
            );
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return null;
        }

        $message = $imap->getMessageByUid($folderPath, $uid);
        if ($message === null) {
            return null;
        }

        MailCacheService::saveBody($folderPath, $message);

        return $this->assembleMessageContext(
            $folderPath,
            $folders,
            $unreadCounts,
            $message,
            $uid,
            $markRead,
            $deferred,
        );
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, int> $unreadCounts
     * @param list<array{path: string, name: string, delimiter?: string}> $folders
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
    private function assembleMessageContext(
        string $folderPath,
        array $folders,
        array $unreadCounts,
        array $message,
        int $uid,
        bool $markRead,
        ?callable &$deferred,
    ): ?array {
        $wasUnread = empty($message['seen']);

        if ($wasUnread && $markRead) {
            $message['seen'] = true;
            MailCacheService::updateIndexSeen($folderPath, $uid, true);
            $unreadCounts = FolderCache::bumpUnread($folderPath, -1);

            $deferred = static function () use ($folderPath, $uid): void {
                $imap = new ImapService();
                if ($imap->connect()) {
                    $imap->markSeen($folderPath, $uid);
                }
            };
        }

        $aliasService = new AliasService();
        $replyFrom = $aliasService->resolveReplyAlias($message['delivered_to'] ?? null, $message['to'] ?? null)
            ?? $aliasService->userAlias(Auth::user()['id'] ?? null);

        $prefs = user_preferences();

        $html = (string) ($message['html'] ?? '');
        if ($html === '' && !empty($message['plain'])) {
            $html = '<pre class="mail-plain">' . e((string) $message['plain']) . '</pre>';
        }

        return [
            'folderPath' => $folderPath,
            'folderB64' => encode_folder_path($folderPath),
            'folders' => $folders,
            'unreadCounts' => $unreadCounts,
            'message' => $message,
            'sanitizedHtml' => HtmlSanitizer::sanitize($html),
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
        releaseSessionLock();

        $folderPath = mail_folder_path($_GET['folder'] ?? '');
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

        $mime = $this->safeAttachmentMime($attachment['mime'] ?? '', $attachment['filename'] ?? '');
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
    private function safeAttachmentMime(string $mime, string $filename = ''): string
    {
        $mime = strtolower(trim($mime));

        if ($mime === '' || !preg_match('#^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$#', $mime)) {
            $mime = 'application/octet-stream';
        }

        if ($mime === 'application/octet-stream' && $filename !== '') {
            $inferred = $this->inferMimeFromFilename($filename);
            if ($inferred !== 'application/octet-stream') {
                return $inferred;
            }
        }

        return $mime;
    }

    private function inferMimeFromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            default => 'application/octet-stream',
        };
    }

    public function move(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();

        $folderPath = mail_folder_path($_POST['folder'] ?? '');
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
        releaseSessionLock();

        $folderPath = mail_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            $this->actionError('Invalid delete request.', 'folder/' . encode_folder_path('INBOX'));
        }
        assert_folder_access($folderPath);

        if (is_trash_folder($folderPath)) {
            $this->performPermanentDelete($folderPath, [$uid], 'folder/' . encode_folder_path($folderPath), 'Message deleted permanently.');
            return;
        }

        $this->performMove($folderPath, [$uid], trash_folder_path(), 'folder/' . encode_folder_path($folderPath), 'Message moved to Trash.');
    }

    public function bulkMove(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();

        $folderPath = mail_folder_path($_POST['folder'] ?? '');
        $targetPath = $_POST['target_folder'] ?? '';
        $uids = $this->resolveBulkUids($folderPath);

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
        releaseSessionLock();

        $folderPath = mail_folder_path($_POST['folder'] ?? '');
        $uids = $this->resolveBulkUids($folderPath);

        if ($folderPath === '' || $uids === []) {
            flash('error', 'Invalid bulk delete request.');
            redirect('folder/' . encode_folder_path($folderPath ?: 'INBOX'));
        }
        assert_folder_access($folderPath);

        if (is_trash_folder($folderPath)) {
            $this->performPermanentDelete($folderPath, $uids, 'folder/' . encode_folder_path($folderPath), 'Selected messages deleted permanently.');
            return;
        }

        $this->performMove($folderPath, $uids, trash_folder_path(), 'folder/' . encode_folder_path($folderPath), 'Selected messages moved to Trash.');
    }

    public function markRead(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();
        $this->setSeenFlag(true);
    }

    public function markUnread(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();
        $this->setSeenFlag(false);
    }

    public function flag(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();
        $this->setFlaggedFlag(true);
    }

    public function unflag(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();
        $this->setFlaggedFlag(false);
    }

    public function spam(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();

        $folderPath = mail_folder_path($_POST['folder'] ?? '');
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
        releaseSessionLock();
        $this->setSeenFlagBulk(true);
    }

    public function bulkMarkUnread(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();
        $this->setSeenFlagBulk(false);
    }

    public function bulkFlag(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();
        $this->setFlaggedFlagBulk(true);
    }

    public function bulkUnflag(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();
        $this->setFlaggedFlagBulk(false);
    }

    /**
     * @param list<int> $uids
     */
    private function performMove(string $folderPath, array $uids, string $targetPath, string $redirect, ?string $successMsg = null): void
    {
        $unreadDelta = max(0, (int) ($_POST['unread_delta'] ?? 0));
        if ($unreadDelta === 0 && ($_POST['all_in_folder'] ?? '') === '1') {
            $unreadDelta = (int) (FolderCache::load(skipUnreadRefresh: true)['unread_counts'][$folderPath] ?? 0);
        }

        if (wants_json()) {
            MailCacheService::removeMessages($folderPath, $uids);
            MailCacheService::invalidateFolder($targetPath);
            if ($unreadDelta > 0) {
                FolderCache::bumpUnread($folderPath, -$unreadDelta);
                if (folder_shows_unread_badge($targetPath)) {
                    $counts = FolderCache::bumpUnread($targetPath, $unreadDelta);
                } else {
                    FolderCache::setUnreadCount($targetPath, 0);
                    $counts = FolderCache::bumpUnread($folderPath, 0);
                }
            } else {
                $counts = FolderCache::bumpUnread($folderPath, 0);
            }

            json_response_then([
                'ok' => true,
                'moved' => count($uids),
                'errors' => 0,
                'uids' => array_values($uids),
                'target' => $targetPath,
                'unread_counts' => $counts,
            ], function () use ($folderPath, $uids, $targetPath): void {
                $this->executeMoveOnServer($folderPath, $uids, $targetPath);
            });
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        $folders = FolderCache::load(skipUnreadRefresh: true)['folders'];
        if (!$this->folderExists($folders, $targetPath)) {
            if (!$imap->folderExistsOnServer($targetPath) && !$imap->createFolder($targetPath)) {
                $this->actionError('Target folder could not be created on the mail server.', $redirect);
            }
            (new FolderCache())->clear();
        }

        $result = $imap->moveMessages($folderPath, $uids, $targetPath);
        $moved = (int) ($result['moved'] ?? 0);
        $errors = (int) ($result['errors'] ?? 0);
        $movedUids = $result['uids'] ?? [];

        if ($movedUids !== []) {
            MailCacheService::removeMessages($folderPath, $movedUids);
        }

        (new FolderCache())->clear();

        if ($moved > 0) {
            flash('success', $successMsg ?? sprintf('%d message(s) moved successfully.', $moved));
        }
        if ($errors > 0) {
            flash('error', sprintf('%d message(s) could not be moved.', $errors));
        }

        redirect($redirect);
    }

    /**
     * @param list<int> $uids
     */
    private function executeMoveOnServer(string $folderPath, array $uids, string $targetPath): void
    {
        if ($uids === []) {
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            app_log('Background move failed: ' . $imap->getLastError());

            return;
        }

        $folders = FolderCache::load(skipUnreadRefresh: true)['folders'];
        if (!$this->folderExists($folders, $targetPath)) {
            if (!$imap->folderExistsOnServer($targetPath) && !$imap->createFolder($targetPath)) {
                app_log('Background move: could not create target folder ' . $targetPath);

                return;
            }
            (new FolderCache())->clear();
        }

        $imap->moveMessages($folderPath, $uids, $targetPath);

        try {
            MailCacheService::syncFolderHeaders($imap, $targetPath);
            if (!folder_shows_unread_badge($targetPath)) {
                FolderCache::setUnreadCount($targetPath, 0);
            }
        } catch (\Throwable $e) {
            app_log('Post-move cache sync failed for ' . $targetPath . ': ' . $e->getMessage());
            MailCacheService::invalidateFolder($targetPath);
        }
    }

    /**
     * @param list<int> $uids
     */
    private function performPermanentDelete(string $folderPath, array $uids, string $redirect, ?string $successMsg = null): void
    {
        $unreadDelta = max(0, (int) ($_POST['unread_delta'] ?? 0));
        if ($unreadDelta === 0 && ($_POST['all_in_folder'] ?? '') === '1') {
            $unreadDelta = (int) (FolderCache::load(skipUnreadRefresh: true)['unread_counts'][$folderPath] ?? 0);
        }

        if (wants_json()) {
            MailCacheService::removeMessages($folderPath, $uids);
            $unreadDelta = min($unreadDelta, count($uids));
            if ($unreadDelta > 0) {
                $counts = FolderCache::bumpUnread($folderPath, -$unreadDelta);
            } else {
                $counts = FolderCache::bumpUnread($folderPath, 0);
            }

            json_response_then([
                'ok' => true,
                'deleted' => count($uids),
                'errors' => 0,
                'uids' => array_values($uids),
                'unread_counts' => $counts,
            ], function () use ($folderPath, $uids): void {
                $this->executeDeleteOnServer($folderPath, $uids);
            });
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        $result = $imap->deleteMessages($folderPath, $uids);
        $deleted = (int) ($result['deleted'] ?? 0);
        $errors = (int) ($result['errors'] ?? 0);
        $deletedUids = $result['uids'] ?? [];

        if ($deletedUids !== []) {
            MailCacheService::removeMessages($folderPath, $deletedUids);
        }

        (new FolderCache())->clear();

        if ($deleted > 0) {
            flash('success', $successMsg ?? sprintf('%d message(s) deleted permanently.', $deleted));
        }
        if ($errors > 0) {
            flash('error', sprintf('%d message(s) could not be deleted.', $errors));
        }

        redirect($redirect);
    }

    /**
     * @param list<int> $uids
     */
    private function executeDeleteOnServer(string $folderPath, array $uids): void
    {
        if ($uids === []) {
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            app_log('Background delete failed: ' . $imap->getLastError());

            return;
        }

        $imap->deleteMessages($folderPath, $uids);
    }

    /**
     * @return list<int>
     */
    private function resolveBulkUids(string $folderPath): array
    {
        if (($_POST['all_in_folder'] ?? '') === '1') {
            if ($folderPath === '') {
                return [];
            }

            $searchQuery = trim($_POST['q'] ?? '');
            $cached = MailCacheService::folderMessageUids($folderPath, $searchQuery);
            if ($cached !== []) {
                return $cached;
            }

            $imap = new ImapService();
            if (!$imap->connect()) {
                return [];
            }

            return $imap->allMessageUids($folderPath, $searchQuery);
        }

        $uids = array_map('intval', $_POST['uids'] ?? []);

        return array_values(array_filter($uids, fn ($u) => $u > 0));
    }

    private function actionError(string $message, string $redirect): never
    {
        if (wants_json()) {
            json_response(['ok' => false, 'error' => $message], 422);
        }

        flash('error', $message);
        redirect($redirect);
    }

    /**
     * @return array<string, int>
     */
    private function unreadCountsAfterSeenChange(string $folderPath, int $seenDelta = 0): array
    {
        return FolderCache::bumpUnread($folderPath, $seenDelta);
    }

    private function setSeenFlag(bool $seen): void
    {
        $folderPath = mail_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        $redirect = $_POST['redirect'] ?? ('folder/' . encode_folder_path($folderPath));

        if ($folderPath === '' || $uid <= 0) {
            $this->actionError('Invalid request.', 'folder/' . encode_folder_path('INBOX'));
        }
        assert_folder_access($folderPath);

        $alreadySeen = MailCacheService::indexSeenState($folderPath, $uid);

        MailCacheService::updateIndexSeen($folderPath, $uid, $seen);

        $delta = 0;
        if ($seen && $alreadySeen === false) {
            $delta = -1;
        } elseif (!$seen && $alreadySeen === true) {
            $delta = 1;
        }
        $counts = FolderCache::bumpUnread($folderPath, $delta);

        if (wants_json()) {
            json_response_then([
                'ok' => true,
                'seen' => $seen,
                'uid' => $uid,
                'unread_counts' => $counts,
            ], function () use ($folderPath, $uid, $seen): void {
                $imap = new ImapService();
                if (!$imap->connect()) {
                    app_log('Background mark seen failed: ' . $imap->getLastError());

                    return;
                }
                if ($seen) {
                    $imap->markSeen($folderPath, $uid);
                } else {
                    $imap->markUnseen($folderPath, $uid);
                }
            });
        }

        $imap = new ImapService();
        if ($imap->connect()) {
            if ($seen) {
                $imap->markSeen($folderPath, $uid);
            } else {
                $imap->markUnseen($folderPath, $uid);
            }
        }

        flash('success', $seen ? 'Marked as read.' : 'Marked as unread.');
        redirect($redirect);
    }

    private function setFlaggedFlag(bool $flagged): void
    {
        $folderPath = mail_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        $redirect = $_POST['redirect'] ?? ('folder/' . encode_folder_path($folderPath));

        if ($folderPath === '' || $uid <= 0) {
            $this->actionError('Invalid request.', 'folder/' . encode_folder_path('INBOX'));
        }
        assert_folder_access($folderPath);

        MailCacheService::updateIndexFlagged($folderPath, $uid, $flagged);

        if (wants_json()) {
            json_response_then([
                'ok' => true,
                'flagged' => $flagged,
                'uid' => $uid,
                'unread_counts' => FolderCache::bumpUnread($folderPath, 0),
            ], function () use ($folderPath, $uid, $flagged): void {
                $imap = new ImapService();
                if (!$imap->connect()) {
                    app_log('Background flag failed: ' . $imap->getLastError());

                    return;
                }
                if ($flagged) {
                    $imap->markFlagged($folderPath, $uid);
                } else {
                    $imap->markUnflagged($folderPath, $uid);
                }
            });
        }

        $imap = new ImapService();
        if ($imap->connect()) {
            if ($flagged) {
                $imap->markFlagged($folderPath, $uid);
            } else {
                $imap->markUnflagged($folderPath, $uid);
            }
        }

        flash('success', $flagged ? 'Marked as important.' : 'Importance removed.');
        redirect($redirect);
    }

    private function setSeenFlagBulk(bool $seen): void
    {
        $folderPath = mail_folder_path($_POST['folder'] ?? '');
        $uids = $this->resolveBulkUids($folderPath);
        $redirect = 'folder/' . encode_folder_path($folderPath ?: 'INBOX');

        if ($folderPath === '' || $uids === []) {
            $this->actionError('No messages selected.', $redirect);
        }
        assert_folder_access($folderPath);

        $delta = $seen
            ? -MailCacheService::countUnreadAmongUids($folderPath, $uids)
            : MailCacheService::countSeenAmongUids($folderPath, $uids);

        MailCacheService::updateIndexSeenBulk($folderPath, $uids, $seen);
        $counts = $this->unreadCountsAfterSeenChange($folderPath, $delta);

        if (wants_json()) {
            json_response_then([
                'ok' => true,
                'seen' => $seen,
                'uids' => $uids,
                'unread_counts' => $counts,
            ], function () use ($folderPath, $uids, $seen): void {
                $imap = new ImapService();
                if (!$imap->connect()) {
                    app_log('Background bulk seen failed: ' . $imap->getLastError());

                    return;
                }
                if ($seen) {
                    $imap->markSeenBulk($folderPath, $uids);
                } else {
                    $imap->markUnseenBulk($folderPath, $uids);
                }
            });
        }

        $imap = new ImapService();
        if ($imap->connect()) {
            if ($seen) {
                $imap->markSeenBulk($folderPath, $uids);
            } else {
                $imap->markUnseenBulk($folderPath, $uids);
            }
        }

        flash('success', sprintf('%d message(s) marked as %s.', count($uids), $seen ? 'read' : 'unread'));
        redirect($redirect);
    }

    private function setFlaggedFlagBulk(bool $flagged): void
    {
        $folderPath = mail_folder_path($_POST['folder'] ?? '');
        $uids = $this->resolveBulkUids($folderPath);
        $redirect = 'folder/' . encode_folder_path($folderPath ?: 'INBOX');

        if ($folderPath === '' || $uids === []) {
            $this->actionError('No messages selected.', $redirect);
        }
        assert_folder_access($folderPath);

        MailCacheService::updateIndexFlaggedBulk($folderPath, $uids, $flagged);

        if (wants_json()) {
            json_response_then([
                'ok' => true,
                'flagged' => $flagged,
                'uids' => $uids,
                'unread_counts' => FolderCache::bumpUnread($folderPath, 0),
            ], function () use ($folderPath, $uids, $flagged): void {
                $imap = new ImapService();
                if (!$imap->connect()) {
                    app_log('Background bulk flag failed: ' . $imap->getLastError());

                    return;
                }
                if ($flagged) {
                    $imap->markFlaggedBulk($folderPath, $uids);
                } else {
                    $imap->markUnflaggedBulk($folderPath, $uids);
                }
            });
        }

        $imap = new ImapService();
        if ($imap->connect()) {
            if ($flagged) {
                $imap->markFlaggedBulk($folderPath, $uids);
            } else {
                $imap->markUnflaggedBulk($folderPath, $uids);
            }
        }

        flash('success', sprintf('%d message(s) %s.', count($uids), $flagged ? 'marked as important' : 'unflagged'));
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
            $data['unreadCounts'] = FolderCache::load(skipUnreadRefresh: true)['unread_counts'] ?? [];
        }

        $data['user'] = Auth::user();
        $data['authUser'] = Auth::user();
        $data['success'] = flash('success');
        $data['error'] = flash('error');
        $data['prefs'] = user_preferences();

        view($viewName, $data);
    }
}
