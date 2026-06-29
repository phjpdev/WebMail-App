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
        releaseSessionLock();

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

        $context = $this->buildFolderListContext($folderPath, $params);
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
            'list_loading' => !empty($context['listAwaitingSync']),
        ]);
        exit;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|null
     */
    private function buildFolderListContext(string $folderPath, array $params): ?array
    {
        if ($folderPath === '') {
            return null;
        }

        $forceRefresh = ($params['refresh'] ?? $_GET['refresh'] ?? '') === '1';
        $cacheFirst = !$forceRefresh;

        $folderData = FolderCache::load(skipUnreadRefresh: true);

        if ($forceRefresh) {
            $filterResult = $this->maybeRunFilter($folderPath, true);
            if ($this->isFilterSource($folderPath) || ($filterResult['moved'] ?? 0) > 0) {
                FolderCache::syncUnreadBadges($folderPath);
            }
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $query = trim($_GET['q'] ?? '');
        $folders = $folderData['folders'];
        $imapConnected = $folderData['connected'];
        $imapError = $folderData['error'];

        $perPage = mail_per_page();
        $list = ['messages' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
        $servedFromCache = false;

        if ($imapConnected && $query === '') {
            $cached = MailCacheService::listFromCache($folderPath, $page, $perPage);
            if ($cached !== null && $cacheFirst) {
                $list = $cached;
                $list['from_cache'] = true;
                if (MailCacheService::isStale($folderPath)) {
                    // Instant folder switch: show cached list, let the client poll refresh.
                    $list['stale'] = true;
                }
                $servedFromCache = true;
            } elseif ($cacheFirst && employee_is_correspondent_folder($folderPath)) {
                // Correspondent folder with no cache yet — return instantly; client poll loads mail.
                $list = [
                    'messages' => [],
                    'total' => 0,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => 0,
                    'from_cache' => true,
                    'stale' => true,
                ];
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
            if (folder_uses_draft_badge($folderPath)) {
                MailCacheService::reconcileSyncStateFromIndex($folderPath);
                $folderUnread = MailCacheService::countBadgeFromIndex($folderPath);
                FolderCache::setUnreadCount($folderPath, $folderUnread);
            }
            if (!isset($folderData['unread_counts']) || !is_array($folderData['unread_counts'])) {
                $folderData['unread_counts'] = [];
            }
            $folderData['unread_counts'][$folderPath] = $folderUnread;
        }

        $list['messages'] = MailCacheService::enrichListMessages($folderPath, $list['messages']);

        foreach ($folders as $folder) {
            $listed = (string) ($folder['path'] ?? '');
            if ($listed !== '' && strcasecmp($listed, $folderPath) === 0) {
                $folderPath = $listed;
                break;
            }
        }

        $prefs = user_preferences();
        $list = mail_filter_removed_messages($folderPath, $list);

        $listAwaitingSync = $query === ''
            && employee_is_correspondent_folder($folderPath)
            && $servedFromCache
            && (!empty($list['stale']) || (int) ($list['total'] ?? 0) === 0);

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
            'listAwaitingSync' => $listAwaitingSync,
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

        return mail_filter_removed_messages($folderPath, $list);
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

        FilterService::runBackground(false);

        $synced = MailCacheService::bootstrapSync($imap, $paths);
        $draftPaths = array_values(array_filter($paths, static fn (string $p): bool => folder_uses_draft_badge($p)));
        if ($draftPaths !== []) {
            foreach ($draftPaths as $draftPath) {
                MailCacheService::reconcileBadgeFromIndex($draftPath);
            }
            FolderCache::refreshPaths($draftPaths);
        }

        echo json_encode([
            'ok' => true,
            'synced' => $synced,
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
        $primaryBuckets = sidebar_primary_folder_order();

        foreach ($folders as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $bucket = sidebar_folder_bucket($path);
            if (in_array($bucket, $primaryBuckets, true) || $bucket === 'other') {
                $paths[] = $path;
            }
        }

        if ($activeFolderEncoded !== '') {
            $active = decode_folder_path($activeFolderEncoded);
            if ($active !== '') {
                $paths[] = $active;
            }
        }

        return array_values(array_unique(array_filter($paths)));
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

        // Lightweight poll: MySQL cache only — no IMAP (keeps polling fast).
        if ($light && $query === '') {
            $cached = MailCacheService::listFromCache($folderPath, $page, $perPage);
            if ($cached !== null) {
                $this->echoFolderSyncJson($folderPath, $cached);

                return;
            }
        }

        $forceFilter = ($_GET['filter'] ?? '') === '1';
        $forceRefresh = ($_GET['force'] ?? '') === '1';

        if ($forceFilter || (!$light && !$forceRefresh)) {
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

        if ($query === '' && $page === 1) {
            $needsHeaderSync = $forceRefresh
                || $forceFilter
                || MailCacheService::isStale($folderPath)
                || MailCacheService::badgeAheadOfIndex($folderPath);
            if ($needsHeaderSync) {
                MailCacheService::syncFolderHeaders($imap, $folderPath);
                $list = MailCacheService::listFromCache($folderPath, $page, $perPage);
            }
        }

        if (
            $list === null
            && $forceRefresh
            && $query === ''
            && $page === 1
            && employee_is_correspondent_folder($folderPath)
        ) {
            if ($forceFilter) {
                $filterResult = $this->maybeRunFilter($folderPath, true);
                if ($this->isFilterSource($folderPath) || ($filterResult['moved'] ?? 0) > 0) {
                    FolderCache::syncUnreadBadges($folderPath);
                }
            }
            $headerLimit = (int) (config('app')['mail_cache_post_send_limit'] ?? 30);
            MailCacheService::syncFolderHeaders($imap, $folderPath, $headerLimit);
            $list = MailCacheService::listFromCache($folderPath, $page, $perPage);
        }

        if ($list === null && ($forceFilter || (!$light && !$forceRefresh))) {
            $list = $query !== ''
                ? $imap->searchMessages($folderPath, $query, $page, $perPage)
                : $imap->listMessages($folderPath, $page, $perPage);

            if ($query === '') {
                MailCacheService::upsertIndexMessages($folderPath, $list['messages'], (int) $list['total']);
            }
        }

        if ($list === null) {
            $list = MailCacheService::listFromCache($folderPath, $page, $perPage)
                ?? ['messages' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 0];
        }

        if ($forceFilter) {
            $sessionBadge = (int) (FolderCache::load(skipUnreadRefresh: true)['unread_counts'][$folderPath] ?? 0);
            if ($sessionBadge > 0 || MailCacheService::badgeAheadOfIndex($folderPath)) {
                MailCacheService::reconcileFolderBadge($imap, $folderPath);
            }
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
        $list = mail_filter_removed_messages($folderPath, $list);

        if (trim($_GET['q'] ?? '') === '') {
            MailCacheService::reconcileBadgeFromIndex($folderPath, $list['messages']);
            if (($_GET['filter'] ?? '') === '1') {
                MailCacheService::reconcileAllIndexedBadges();
            }
        }

        $list['messages'] = MailCacheService::enrichListMessages($folderPath, $list['messages']);

        $messages = [];

        foreach ($list['messages'] as $msg) {
            $uid = (int) $msg['uid'];
            $isDraft = is_draft_folder($folderPath);
            $listFrom = (string) ($msg['list_from'] ?? '');
            if ($listFrom === '') {
                $to = (string) ($msg['to'] ?? '');
                $listFrom = ($isDraft && $to !== '')
                    ? format_mail_from($to)
                    : format_mail_from($msg['from'] ?? '');
            }
            $rowDisplay = mail_list_row_display($msg, $folderPath);
            $messages[] = [
                'uid' => $uid,
                'from' => $listFrom,
                'subject' => $msg['subject'] ?? '(no subject)',
                'snippet' => (string) ($msg['snippet'] ?? ''),
                'is_draft' => $isDraft,
                'date' => format_mail_date($msg['date'] ?? ''),
                'seen' => (bool) ($msg['seen'] ?? false),
                'flagged' => (bool) ($msg['flagged'] ?? false),
                'has_attachment' => (bool) ($msg['has_attachment'] ?? false),
                'avatar_initial' => mail_avatar_initial($rowDisplay['avatar_from']),
                'avatar_color' => mail_avatar_color($rowDisplay['avatar_from']),
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

        $light = ($_GET['light'] ?? '') === '1';
        $afterSend = ($_GET['after_send'] ?? '') === '1';

        // Fast path: session badge counts only (post-send-deferred handles IMAP/filter work).
        if ($light || $afterSend) {
            echo json_encode(['unread_counts' => FolderCache::sidebarUnreadCounts()]);

            return;
        }

        if (($_GET['filter'] ?? '') === '1') {
            FilterService::runBackground(false);
        }

        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $counts = $folderData['unread_counts'] ?? [];

        foreach ($folderData['folders'] ?? [] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $badge = (int) ($counts[$path] ?? 0);
            if ($badge <= 0 && !MailCacheService::badgeAheadOfIndex($path)) {
                continue;
            }
            MailCacheService::reconcileBadgeFromIndex($path);
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
     * Deferred list preview snippets for rows without cached bodies.
     *
     * @param array<string, string> $params
     */
    public function messageSnippets(array $params): void
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
            echo json_encode(['ok' => true, 'snippets' => []]);
            return;
        }

        $uids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), fn ($u) => $u > 0)));
        $uids = array_slice($uids, 0, 20);

        $imap = new ImapService();
        if (!$imap->connect()) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => $imap->getLastError()]);
            return;
        }

        $snippets = MailCacheService::resolveSnippetsForUids($imap, $folderPath, $uids);

        echo json_encode(['ok' => true, 'snippets' => $snippets]);
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

        if (is_draft_folder($folderPath)) {
            $draftContext = compose_draft_form_context($folderPath, $uid);
            if ($draftContext === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Draft not found']);
                return;
            }

            $html = view_string('mail/pane-draft', $draftContext);
            json_response([
                'ok' => true,
                'uid' => $uid,
                'subject' => $draftContext['subject'] !== '' ? $draftContext['subject'] : '(no subject)',
                'seen' => true,
                'was_unread' => false,
                'html' => $html,
                'is_draft_editor' => true,
                'unread_counts' => FolderCache::sidebarUnreadCounts(),
                'folder_unread' => (int) (FolderCache::sidebarUnreadCounts()[$folderPath] ?? 0),
            ]);
        }

        $context = $this->loadMessageContext($folderPath, $uid, markRead: !$prefetch, deferred: $deferred);
        if ($context === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Message not found']);
            return;
        }

        $html = view_string('mail/pane-read', $context);

        $payload = [
            'ok' => true,
            'uid' => $uid,
            'subject' => $context['message']['subject'] ?: '(no subject)',
            'seen' => !empty($context['message']['seen']),
            'was_unread' => $context['wasUnread'] && !$prefetch,
            'html' => $html,
            'unread_counts' => $context['unreadCounts'],
            'folder_unread' => (int) ($context['unreadCounts'][$folderPath] ?? 0),
        ];

        if ($deferred !== null) {
            json_response_then($payload, $deferred);
        }

        json_response($payload);
    }

    /**
     * Cache message body for compose (reply/forward) without marking read.
     *
     * @param array<string, string> $params
     */
    public function warmBody(array $params): void
    {
        requireAuth();
        releaseSessionLock();
        header('Content-Type: application/json; charset=utf-8');

        $folderPath = mail_folder_path($params['folderB64'] ?? '');
        $uid = (int) ($params['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            http_response_code(404);
            echo json_encode(['ok' => false]);
            return;
        }

        assert_folder_access($folderPath);

        if (MailCacheService::getBody($folderPath, $uid) !== null) {
            echo json_encode(['ok' => true, 'cached' => true]);
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => $imap->getLastError()]);
            return;
        }

        $message = $imap->getMessageByUid($folderPath, $uid);
        if ($message === null) {
            http_response_code(404);
            echo json_encode(['ok' => false]);
            return;
        }

        MailCacheService::saveBody($folderPath, $message);
        echo json_encode(['ok' => true, 'cached' => false]);
    }

    /**
     * @param array<string, string> $params
     */
    public function read(array $params): void
    {
        requireAuth();
        releaseSessionLock();

        $folderPath = mail_folder_path($params['folderB64'] ?? '');
        $uid = (int) ($params['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            error_page(404);
        }

        if (is_draft_folder($folderPath)) {
            redirect('compose/edit-draft?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid);
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
        mail_note_correspondents_from_message($message);

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
        $userId = Auth::user()['id'] ?? null;
        if (is_draft_folder($folderPath)) {
            $replyFrom = $aliasService->resolveAllowedFrom($message['from'] ?? null, $userId);
        } else {
            $replyFrom = $aliasService->resolveReplyAlias($message['delivered_to'] ?? null, $message['to'] ?? null)
                ?? $aliasService->userAlias($userId);
        }

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
            if (wants_json()) {
                json_response(['ok' => false, 'error' => 'Invalid bulk move request.'], 422);
            }
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
            if (wants_json()) {
                json_response(['ok' => false, 'error' => 'No messages selected to delete.'], 422);
            }
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
        $resolvedFolderPath = FolderCache::resolvePath($folderPath);
        $indexFolderPath = MailCacheService::indexFolderPath($resolvedFolderPath);
        $targetPath = FolderCache::resolvePath($targetPath);
        $siblingMode = $this->siblingCopyModeForTarget($targetPath);

        $unreadDelta = max(0, (int) ($_POST['unread_delta'] ?? 0));
        if ($unreadDelta === 0 && ($_POST['all_in_folder'] ?? '') === '1') {
            $counts = FolderCache::load(skipUnreadRefresh: true)['unread_counts'];
            $unreadDelta = (int) ($counts[$resolvedFolderPath] ?? $counts[$indexFolderPath] ?? 0);
        }

        if (wants_json()) {
            MailCacheService::removeMessages($indexFolderPath, $uids);
            mail_mark_uids_removed($resolvedFolderPath, $uids);
            $siblingFoldersTouched = false;
            if ($siblingMode !== 'none') {
                $siblingFoldersTouched = count($this->removeSiblingCopiesFromCache($resolvedFolderPath, $uids)) > 1;
            }
            if (!is_trash_folder($targetPath)) {
                MailCacheService::invalidateFolder($targetPath);
            }

            if ($siblingFoldersTouched) {
                $counts = FolderCache::sidebarUnreadCounts();
            } elseif (folder_uses_draft_badge($resolvedFolderPath)) {
                MailCacheService::reconcileBadgeFromIndex($indexFolderPath);
                $counts = FolderCache::sidebarUnreadCounts();
            } elseif ($unreadDelta > 0) {
                $unreadDelta = min($unreadDelta, count($uids));
                $counts = FolderCache::bumpUnread($resolvedFolderPath, -$unreadDelta);
                if (folder_shows_unread_badge($targetPath)) {
                    $counts = FolderCache::bumpUnread($targetPath, $unreadDelta);
                } else {
                    FolderCache::setUnreadCount($targetPath, 0);
                }
            } else {
                $counts = FolderCache::bumpUnread($resolvedFolderPath, 0);
            }

            json_response_then($this->appendCorrespondentFolderPrune($resolvedFolderPath, [
                'ok' => true,
                'moved' => count($uids),
                'errors' => 0,
                'uids' => array_values($uids),
                'target' => $targetPath,
                'unread_counts' => $counts,
            ]), function () use ($resolvedFolderPath, $uids, $targetPath, $siblingMode): void {
                $this->executeMoveOnServer($resolvedFolderPath, $uids, $targetPath, $siblingMode);
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

        $result = $imap->moveMessages($resolvedFolderPath, $uids, $targetPath);
        $moved = (int) ($result['moved'] ?? 0);
        $errors = (int) ($result['errors'] ?? 0);
        $movedUids = $result['uids'] ?? [];

        if ($movedUids !== []) {
            MailCacheService::removeMessages($indexFolderPath, $movedUids);
        }

        if ($siblingMode !== 'none' && $movedUids !== []) {
            $this->removeSiblingCopiesFromCache($resolvedFolderPath, $movedUids);
            $imap = new ImapService();
            if ($imap->connect()) {
                $this->handleSiblingCopies($imap, $resolvedFolderPath, $movedUids, $targetPath, $siblingMode);
                ImapService::closeShared();
            }
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
     * @return 'none'|'delete'|'trash'
     */
    private function siblingCopyModeForTarget(string $targetPath): string
    {
        if (strcasecmp($targetPath, spam_folder_path()) === 0) {
            return 'delete';
        }
        if (is_trash_folder($targetPath)) {
            return 'trash';
        }

        return 'none';
    }

    /**
     * Optimistically hide routed duplicate copies (BCC delivery to multiple folders).
     *
     * @param list<int> $uids
     * @return list<string> folder paths touched in the cache
     */
    private function removeSiblingCopiesFromCache(string $sourceFolder, array $uids): array
    {
        $affected = [];
        $seenMessageIds = [];

        foreach ($uids as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }

            $messageId = MailCacheService::messageIdForUid($sourceFolder, $uid);
            if ($messageId === null || $messageId === '' || isset($seenMessageIds[$messageId])) {
                continue;
            }
            $seenMessageIds[$messageId] = true;

            foreach (MailCacheService::copiesByMessageId($messageId) as $copy) {
                $copyPath = FolderCache::resolvePath($copy['folder_path']);
                $copyUid = (int) $copy['imap_uid'];
                if ($copyUid <= 0 || !FolderCache::canAccess($copyPath)) {
                    continue;
                }

                MailCacheService::removeMessages($copyPath, [$copyUid]);
                mail_mark_uids_removed($copyPath, [$copyUid]);
                $affected[$copyPath] = true;
            }
        }

        return array_keys($affected);
    }

    /**
     * @param list<int> $uids
     * @return array{moved: int, errors: int, removed: array<string, list<int>>, error: string}
     */
    private function runMovesOnImap(
        ImapService $imap,
        string $folderPath,
        array $uids,
        string $targetPath,
        string $siblingMode = 'none',
    ): array {
        $folderPath = FolderCache::resolvePath($folderPath);
        $targetPath = FolderCache::resolvePath($targetPath);

        $movesByFolder = [$folderPath => $uids];

        $movedTotal = 0;
        $errorTotal = 0;
        $removed = [];
        $lastError = '';

        foreach ($movesByFolder as $fromPath => $folderUids) {
            $folderUids = array_values(array_unique(array_filter(
                array_map('intval', $folderUids),
                static fn (int $u): bool => $u > 0
            )));
            if ($folderUids === []) {
                continue;
            }

            $result = $imap->moveMessages($fromPath, $folderUids, $targetPath);
            $movedUids = $result['uids'] ?? [];
            if ($movedUids !== []) {
                $movedTotal += count($movedUids);
                $removed[$fromPath] = $movedUids;
            }

            $errorTotal += (int) ($result['errors'] ?? 0);
            if (($result['moved'] ?? 0) === 0 || ($result['errors'] ?? 0) > 0) {
                $lastError = $imap->getLastError();
                app_log(sprintf(
                    'Move failed: %s → %s (%s)',
                    $fromPath,
                    $targetPath,
                    $lastError
                ));
            }
        }

        if ($siblingMode === 'delete' && $movedTotal > 0) {
            $this->handleSiblingCopies($imap, $folderPath, $uids, $targetPath, 'delete');
        }

        return [
            'moved' => $movedTotal,
            'errors' => $errorTotal,
            'removed' => $removed,
            'error' => $lastError,
        ];
    }

    /**
     * Handle routed duplicate copies of the same message (e.g. BCC in User + Support).
     *
     * @param list<int> $movedUids
     * @param 'delete'|'trash' $mode
     */
    private function handleSiblingCopies(
        ImapService $imap,
        string $sourceFolder,
        array $movedUids,
        string $targetPath,
        string $mode,
    ): void {
        $messageId = null;
        foreach ($movedUids as $uid) {
            $messageId = MailCacheService::messageIdForUid($sourceFolder, (int) $uid);
            if ($messageId !== null) {
                break;
            }
        }

        if ($messageId === null) {
            $headers = $imap->fetchFilterHeaders($sourceFolder, (int) ($movedUids[0] ?? 0));
            $messageId = $headers['message_id'] ?? null;
        }

        if ($messageId === null || $messageId === '') {
            return;
        }

        $movedLookup = array_fill_keys(array_map('intval', $movedUids), true);

        try {
            foreach (MailCacheService::copiesByMessageId($messageId) as $copy) {
                $copyPath = FolderCache::resolvePath($copy['folder_path']);
                $uid = (int) $copy['imap_uid'];
                if ($uid <= 0 || $copyPath === $targetPath || !FolderCache::canAccess($copyPath)) {
                    continue;
                }
                if ($copyPath === $sourceFolder && isset($movedLookup[$uid])) {
                    continue;
                }

                if ($mode === 'trash') {
                    $trashPath = is_trash_folder($targetPath) ? $targetPath : trash_folder_path();
                    if ($imap->moveMessage($copyPath, $uid, $trashPath)) {
                        MailCacheService::removeMessage($copyPath, $uid);
                        mail_clear_removed_uids($copyPath, [$uid]);
                    }
                } elseif ($imap->deleteMessage($copyPath, $uid)) {
                    MailCacheService::removeMessage($copyPath, $uid);
                    mail_clear_removed_uids($copyPath, [$uid]);
                }
            }
        } catch (\Throwable $e) {
            app_log('Sibling copy handling failed: ' . $e->getMessage());
        }
    }

    /**
     * @param list<int> $uids
     */
    private function executeMoveOnServer(
        string $folderPath,
        array $uids,
        string $targetPath,
        string $siblingMode = 'none',
    ): void {
        $uids = array_values(array_unique(array_filter(
            array_map('intval', $uids),
            static fn (int $u): bool => $u > 0
        )));

        if ($uids === []) {
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            app_log('Background move failed: ' . $imap->getLastError());

            return;
        }

        if (!$imap->folderExistsOnServer($targetPath) && !$imap->createFolder($targetPath)) {
            app_log('Background move: could not create target folder ' . $targetPath);
            ImapService::closeShared();

            return;
        }
        ImapService::closeShared();

        $movedTotal = 0;
        $removed = [];
        $allMovedUids = [];

        foreach (array_chunk($uids, 50) as $chunk) {
            $chunkMoved = false;
            $moveResult = ['moved' => 0, 'errors' => 0, 'removed' => []];

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $imap = new ImapService();
                if (!$imap->connect()) {
                    app_log('Background move chunk failed: ' . $imap->getLastError());
                    break;
                }

                $moveResult = $this->runMovesOnImap($imap, $folderPath, $chunk, $targetPath, false);
                ImapService::closeShared();

                $imapFromPath = FolderCache::resolvePath($folderPath);
                $movedUids = $moveResult['removed'][$imapFromPath] ?? [];
                if ($movedUids === [] && !empty($moveResult['removed'])) {
                    $movedUids = array_merge(...array_values($moveResult['removed']));
                }
                if ($movedUids !== []) {
                    $movedTotal += count($movedUids);
                    $allMovedUids = array_merge($allMovedUids, $movedUids);
                    $removed[$folderPath] = array_merge($removed[$folderPath] ?? [], $movedUids);
                    MailCacheService::removeMessages($folderPath, $movedUids);
                    $chunkMoved = true;
                    break;
                }

                if (($moveResult['moved'] ?? 0) > 0) {
                    $chunkMoved = true;
                    break;
                }

                if ($attempt < 2) {
                    usleep(500000);
                }
            }

            if (!$chunkMoved && ($moveResult['errors'] ?? 0) > 0) {
                app_log(sprintf(
                    'Background move failed for %s → %s (%d uid(s))',
                    $folderPath,
                    $targetPath,
                    count($chunk)
                ));
            }
        }

        if ($allMovedUids !== []) {
            mail_clear_removed_uids($folderPath, $allMovedUids);
        }

        if ($movedTotal === 0) {
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return;
        }

        if ($siblingMode === 'trash' && $allMovedUids !== []) {
            try {
                $this->handleSiblingCopies($imap, $folderPath, $allMovedUids, $targetPath, 'trash');
            } catch (\Throwable $e) {
                app_log('Trash sibling copies failed: ' . $e->getMessage());
            }
        }

        if ($siblingMode === 'delete') {
            try {
                $imap->removeDuplicateDeliveries($targetPath, 20);
            } catch (\Throwable $e) {
                app_log('Post-spam dedupe failed: ' . $e->getMessage());
            }
        }

        if (!is_trash_folder($targetPath)) {
            try {
                MailCacheService::syncFolderHeaders($imap, $targetPath, 30);
            } catch (\Throwable $e) {
                app_log('Post-move cache sync failed for ' . $targetPath . ': ' . $e->getMessage());
                MailCacheService::invalidateFolder($targetPath);
            }
        }

        ImapService::closeShared();
    }

    /**
     * @param list<int> $uids
     */
    private function performPermanentDelete(string $folderPath, array $uids, string $redirect, ?string $successMsg = null): void
    {
        $resolvedFolderPath = FolderCache::resolvePath($folderPath);
        $indexFolderPath = MailCacheService::indexFolderPath($resolvedFolderPath);

        $unreadDelta = max(0, (int) ($_POST['unread_delta'] ?? 0));
        if ($unreadDelta === 0 && ($_POST['all_in_folder'] ?? '') === '1') {
            $counts = FolderCache::load(skipUnreadRefresh: true)['unread_counts'];
            $unreadDelta = (int) ($counts[$resolvedFolderPath] ?? $counts[$indexFolderPath] ?? 0);
        }

        if (wants_json()) {
            MailCacheService::removeMessages($indexFolderPath, $uids);
            mail_mark_uids_removed($resolvedFolderPath, $uids);
            if (folder_uses_draft_badge($resolvedFolderPath)) {
                MailCacheService::reconcileBadgeFromIndex($indexFolderPath);
                $counts = FolderCache::sidebarUnreadCounts();
            } elseif ($unreadDelta > 0) {
                $unreadDelta = min($unreadDelta, count($uids));
                $counts = FolderCache::bumpUnread($resolvedFolderPath, -$unreadDelta);
            } else {
                $counts = FolderCache::bumpUnread($resolvedFolderPath, 0);
            }

            json_response_then($this->appendCorrespondentFolderPrune($resolvedFolderPath, [
                'ok' => true,
                'deleted' => count($uids),
                'errors' => 0,
                'uids' => array_values($uids),
                'unread_counts' => $counts,
            ]), function () use ($resolvedFolderPath, $uids): void {
                $this->executeDeleteOnServer($resolvedFolderPath, $uids);
            });
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        $result = $imap->deleteMessages($resolvedFolderPath, $uids);
        $deleted = (int) ($result['deleted'] ?? 0);
        $errors = (int) ($result['errors'] ?? 0);
        $deletedUids = $result['uids'] ?? [];

        if ($deletedUids !== []) {
            MailCacheService::removeMessages($indexFolderPath, $deletedUids);
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
        $uids = array_values(array_unique(array_filter(
            array_map('intval', $uids),
            static fn (int $u): bool => $u > 0
        )));

        if ($uids === []) {
            return;
        }

        foreach (array_chunk($uids, 100) as $chunk) {
            $imap = new ImapService();
            if (!$imap->connect()) {
                app_log('Background delete chunk failed: ' . $imap->getLastError());
                break;
            }

            $result = $imap->deleteMessages($folderPath, $chunk);
            $deletedUids = $result['uids'] ?? [];
            if ($deletedUids !== []) {
                mail_clear_removed_uids($folderPath, $deletedUids);
            }
            ImapService::closeShared();
        }
    }

    /**
     * @return list<int>
     */
    private function resolveBulkUids(string $folderPath): array
    {
        $resolvedPath = FolderCache::resolvePath($folderPath);
        $indexPath = MailCacheService::indexFolderPath($resolvedPath);

        if (($_POST['all_in_folder'] ?? '') === '1') {
            if ($resolvedPath === '') {
                return [];
            }

            $searchQuery = trim($_POST['q'] ?? '');
            $cached = MailCacheService::folderMessageUids($indexPath, $searchQuery);
            if ($cached !== []) {
                return $cached;
            }

            $imap = new ImapService();
            if (!$imap->connect()) {
                return [];
            }

            return $imap->allMessageUids($resolvedPath, $searchQuery);
        }

        $uids = array_map('intval', $_POST['uids'] ?? []);

        return array_values(array_filter($uids, fn ($u) => $u > 0));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function appendCorrespondentFolderPrune(string $folderPath, array $payload): array
    {
        $pruned = mail_prune_empty_correspondent_folder($folderPath);
        if ($pruned !== null) {
            $payload['remove_correspondent_folder'] = $pruned;
        }

        return $payload;
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
            if (strcasecmp((string) ($folder['path'] ?? ''), $path) === 0) {
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
            if (strcasecmp((string) ($folder['path'] ?? ''), $path) === 0) {
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
