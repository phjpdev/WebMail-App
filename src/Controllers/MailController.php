<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\HtmlSanitizer;
use App\Services\AliasService;
use App\Services\FilterService;
use App\Services\FolderCache;
use App\Services\ImapService;
use App\Services\MailCacheService;
use App\Services\SystemBootstrapService;

class MailController
{
    public function home(): void
    {
        requireAuth();
        redirect('folder/' . encode_folder_path(default_mail_folder()));
    }

    public function search(): void
    {
        requireAuth();
        releaseSessionLock();

        $query = trim($_GET['q'] ?? '');
        if ($query === '') {
            redirect('folder/' . encode_folder_path(default_mail_folder()));
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = mail_per_page();
        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $folders = $folderData['folders'];
        $imapConnected = $folderData['connected'];
        $imapError = $folderData['error'];

        $list = MailCacheService::searchAllMessages($query, $page, $perPage);

        if ($list['total'] === 0 && $imapConnected) {
            $accessiblePaths = [];
            foreach ($folders as $folder) {
                $path = (string) ($folder['path'] ?? '');
                if ($path !== '' && FolderCache::canAccess($path)) {
                    $accessiblePaths[] = $path;
                }
            }

            if ($accessiblePaths !== []) {
                $imap = new ImapService();
                if ($imap->connect()) {
                    $list = $imap->searchAllMessages($accessiblePaths, $query, $page, $perPage);
                } else {
                    $imapConnected = false;
                    $imapError = $imap->getLastError();
                }
            }
        }

        foreach ($list['messages'] as &$msg) {
            $folderPath = (string) ($msg['_folder_path'] ?? '');
            $msg['_folder_label'] = folder_display_name($folders, $folderPath);

            // Professional search UX: show WHY a message matched. When the query
            // hit the body, replace the default snippet with the matching context;
            // highlight the query in both subject and snippet (<mark>).
            $plain = (string) ($msg['_plain'] ?? '');
            if ($plain === '') {
                $body = MailCacheService::getBody($folderPath, (int) ($msg['uid'] ?? 0));
                $plain = (string) ($body['plain'] ?? '');
            }
            $context = mail_search_context_snippet($plain, $query);
            if ($context !== null) {
                $msg['snippet'] = $context;
            }
            $msg['_hl_subject'] = mail_search_highlight((string) ($msg['subject'] ?? '(no subject)'), $query);
            $msg['_hl_snippet'] = mail_search_highlight((string) ($msg['snippet'] ?? ''), $query);
            $fromDisplay = (string) ($msg['list_from'] ?? '');
            if ($fromDisplay !== '' && mb_stripos($fromDisplay, $query) !== false) {
                $msg['_hl_from'] = mail_search_highlight($fromDisplay, $query);
            }
        }
        unset($msg);

        $prefs = user_preferences();

        $this->renderMailView('mail/search', [
            'title' => 'Search',
            'searchQuery' => $query,
            'globalSearchQuery' => $query,
            'folders' => $folders,
            'messages' => $list['messages'],
            'page' => $list['page'],
            'totalPages' => $list['total_pages'],
            'totalMessages' => $list['total'],
            'imapConnected' => $imapConnected,
            'imapError' => $imapError,
            'pollInterval' => $prefs['poll_interval'] ?? config('app')['mail_poll_interval'],
            'perPage' => $perPage,
            'showFolder' => true,
            'activeFolder' => '',
        ]);
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

        $explicitRefresh = ($params['refresh'] ?? $_GET['refresh'] ?? '') === '1';
        $forceRefresh = $explicitRefresh;
        $query = trim($_GET['q'] ?? '');
        $preferCache = $query === '' && !$explicitRefresh;

        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $badgePending = FolderCache::isPendingBadgePath($folderPath)
            || MailCacheService::badgeAheadOfIndex($folderPath)
            || mail_get_post_send_preview($folderPath) !== null
            // Messages were just moved INTO this folder (delete → Trash / move):
            // mark the cached list stale so the client force-syncs and swaps the
            // optimistic arrival rows for the real ones.
            || mail_get_pending_arrivals($folderPath) !== [];

        // The routing filter runs inline ONLY on an explicit refresh: on plain
        // page/folder opens it blocked first paint for up to ~7s whenever its
        // throttle had expired (measured on the client's system). Ordinary
        // routing is owned by the periodic live-sync, which runs the same
        // filter in a background request.
        if (
            !$this->shouldSkipPostSendFilter()
            && $explicitRefresh
            && ($this->isFilterSource($folderPath) || is_routed_destination_folder($folderPath))
        ) {
            $filterResult = $this->maybeRunFilter($folderPath, true);
            if (($filterResult['moved'] ?? 0) > 0) {
                FolderCache::syncUnreadBadges($folderPath);
            }
        }
        perf_mark('ctx_filter_done');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $folders = $folderData['folders'];
        $imapConnected = $folderData['connected'];
        $imapError = $folderData['error'];

        $perPage = mail_per_page();
        $list = ['messages' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
        $servedFromCache = false;

        // Full-history browsing: when the requested page reaches past the
        // indexed window and the folder's history isn't fully indexed yet,
        // extend the index older-ward on demand (bounded chunk budget), then
        // serve from the cache as usual. listFromCache clamps out-of-range
        // pages, so detection must happen here, before the cache read.
        $historyState = MailCacheService::getSyncState($folderPath);
        if ($imapConnected && $query === '' && $page > 1) {
            $serverTotal = (int) ($historyState['server_total'] ?? 0);
            $indexed = MailCacheService::countListableMessagesInIndex($folderPath);
            $indexedPages = (int) ceil(max(0, $indexed) / max(1, $perPage));
            if (
                $page > max(1, $indexedPages)
                && empty($historyState['backfill_done'])
                && $serverTotal > MailCacheService::countMessagesInIndex($folderPath)
            ) {
                $imap = new ImapService();
                if ($imap->connect()) {
                    perf_mark('deep_page_extend_start');
                    MailCacheService::extendIndexForDeepPage($imap, $folderPath, $page, $perPage);
                    perf_mark('deep_page_extend_done');
                    $historyState = MailCacheService::getSyncState($folderPath);
                }
            }
        }

        if ($imapConnected && $query === '' && $preferCache) {
            $cached = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
            if ($cached !== null) {
                $list = $cached;
                $list['from_cache'] = true;
                if (MailCacheService::isStale($folderPath) || $badgePending) {
                    // Instant folder switch: show cached list, let the client poll refresh.
                    $list['stale'] = true;
                }
            } else {
                // No cache yet — return instantly; client poll loads mail from IMAP.
                $list = [
                    'messages' => [],
                    'total' => 0,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => 0,
                    'from_cache' => true,
                    'stale' => true,
                ];
            }
            $servedFromCache = true;
        }

        if ($imapConnected && !$servedFromCache) {
            $imap = new ImapService();
            if ($imap->connect()) {
                if ($preferCache && $badgePending && $query === '' && !$this->shouldSkipPostSendFilter()) {
                    $headerLimit = (int) (config('app')['mail_cache_post_send_limit'] ?? 30);
                    MailCacheService::syncFolderHeaders($imap, $folderPath, $headerLimit);
                    $refreshed = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
                    if ($refreshed !== null) {
                        $list = $refreshed;
                        $list['from_cache'] = true;
                        $list['stale'] = MailCacheService::isStale($folderPath)
                            || MailCacheService::badgeAheadOfIndex($folderPath);
                        $servedFromCache = true;
                    }
                }
                if (!$servedFromCache && !$this->shouldSkipPostSendFilter()) {
                    $list = $this->fetchFolderList($folderPath, $page, $perPage, $query, $imap, $forceRefresh);
                    if ($explicitRefresh) {
                        $sessionBadge = (int) ($folderData['unread_counts'][$folderPath] ?? 0);
                        if ($sessionBadge > 0 || MailCacheService::badgeAheadOfIndex($folderPath)) {
                            MailCacheService::reconcileFolderBadge($imap, $folderPath);
                            if (!empty($list['from_cache'])) {
                                $refreshed = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
                                if ($refreshed !== null) {
                                    $list = $refreshed;
                                }
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
        perf_mark('ctx_list_ready');

        // Mail server unreachable (host IMAP outage): serve the cached list
        // read-only instead of an empty folder — the user keeps seeing their
        // mail, with the connection error shown, and polling recovers later.
        if (!$imapConnected && $query === '' && empty($list['messages'])) {
            $cached = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
            if ($cached !== null) {
                $list = $cached;
                $list['from_cache'] = true;
            }
        }

        if ($imapConnected && $query === '') {
            if ($preferCache && !$explicitRefresh) {
                // Fast folder switch: count from the local index (one cheap SQL
                // COUNT), not the session badge — the session number can be stale
                // and briefly renders a wrong header count until a poll heals it.
                $folderUnread = MailCacheService::countBadgeFromIndex($folderPath);
                FolderCache::setUnreadCount($folderPath, $folderUnread);
            } elseif ($this->shouldSkipPostSendFilter()) {
                $folderUnread = FolderCache::sessionUnreadCountRaw($folderPath);
            } else {
                $folderUnread = MailCacheService::reconcileBadgeFromIndex($folderPath, $list['messages']);
                if (folder_uses_draft_badge($folderPath)) {
                    MailCacheService::reconcileSyncStateFromIndex($folderPath);
                    $folderUnread = MailCacheService::countBadgeFromIndex($folderPath);
                    FolderCache::setUnreadCount($folderPath, $folderUnread);
                }
            }
            if (!isset($folderData['unread_counts']) || !is_array($folderData['unread_counts'])) {
                $folderData['unread_counts'] = [];
            }
            $folderData['unread_counts'][$folderPath] = $folderUnread;
        }

        foreach ($folders as $folder) {
            $listed = (string) ($folder['path'] ?? '');
            if ($listed !== '' && strcasecmp($listed, $folderPath) === 0) {
                $folderPath = $listed;
                break;
            }
        }

        $prefs = user_preferences();
        $list = mail_filter_removed_messages($folderPath, $list);

        // Plain per-folder view: the folder shows exactly its own cached rows.
        // Same pipeline as the AJAX /sync path (no cross-folder merge, thread
        // grouping, or correspondent/shared finalize), so a full page load and a
        // background sync render identically and the blue bars == index == badge.
        $list = mail_apply_folder_list_view_pipeline($folderPath, $list);

        // Only block the list UI when there is nothing to show yet; otherwise
        // render cached rows immediately and sync in the background.
        $preview = mail_get_post_send_preview($folderPath);
        $previewWouldShow = $preview !== null
            && mail_post_send_preview_visible_in_folder($folderPath, $preview);
        // Only show the "Loading messages…" state when the folder actually has
        // mail to load that isn't in the index yet — a post-send preview, or an
        // unread badge running ahead of the (not-yet-synced) index. A brand-new or
        // empty folder has nothing to await, so it renders "No messages" straight
        // away instead of a spinner that could hang on the very first open.
        $listAwaitingSync = $query === ''
            && $imapConnected
            && empty($list['messages'])
            && (
                $previewWouldShow
                || (
                    MailCacheService::badgeAheadOfIndex($folderPath)
                    && !\App\Services\MailCacheService::hasFolderData($folderPath)
                )
            );

        if ($query === '' && folder_badge_uses_index_truth($folderPath) && !$preferCache) {
            MailCacheService::reconcileBadgeFromIndex($folderPath, $list['messages']);
        }

        $sidebarUnread = ($preferCache && !$explicitRefresh)
            ? FolderCache::sidebarUnreadCountsFromSession()
            : FolderCache::sidebarUnreadCounts();
        perf_mark('ctx_unread_done');
        // Badge writes along the way re-acquired the session lock — the list
        // render that follows (fragment JSON or full page) must run unlocked.
        releaseSessionLock();

        return [
            'title' => $this->folderDisplayName($folders, $folderPath),
            'folderPath' => $folderPath,
            'folderB64' => encode_folder_path($folderPath),
            'folders' => $folders,
            'unreadCounts' => $sidebarUnread,
            'unreadCount' => folder_shows_unread_badge($folderPath)
                ? (int) ($sidebarUnread[$folderPath]
                    ?? $sidebarUnread[employee_messages_imap_path($folderPath)]
                    ?? 0)
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
            'listCacheStale' => !empty($list['stale']),
            // Full-history browsing: true server count + whether all of it is
            // indexed (drives the "N of M synced" header and Load-older link).
            'serverTotal' => (int) ($historyState['server_total'] ?? 0),
            'backfillDone' => !empty($historyState['backfill_done']),
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

    /** Skip redundant filter runs while post-send-deferred is still syncing. */
    private function shouldSkipPostSendFilter(): bool
    {
        return mail_in_post_send_fast_window();
    }

    /**
     * Route INBOX mail for every employee/folder using the current rule set.
     * Throttled via filter_min_interval unless $force is true (after send, bootstrap).
     */
    private function runThrottledGlobalFilter(bool $force = false): void
    {
        if ($this->shouldSkipPostSendFilter()) {
            return;
        }

        FilterService::runBackground($force, 8);
    }

    /**
     * Run filter on inbox visits; throttle on other folders and poll sync.
     *
     * @return array{processed: int, moved: int, errors: list<string>, duration_ms: int, done?: bool}|null
     */
    private function maybeRunFilter(string $folderPath, bool $force = false): ?array
    {
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
            $cached = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
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

        if ($this->shouldSkipPostSendFilter()) {
            json_response([
                'ok' => true,
                'synced' => [],
                'folders_changed' => false,
                'unread_counts' => FolderCache::sidebarUnreadCountsSessionOnly(),
            ]);
        }

        $folderData = FolderCache::load(skipUnreadRefresh: true);
        if (!$folderData['connected']) {
            json_response(['ok' => false, 'error' => $folderData['error'] ?: 'IMAP unavailable'], 503);
        }

        $bootstrapped = (new SystemBootstrapService())->ensureDefaults();
        if ($bootstrapped) {
            (new FolderCache())->clear();
            $folderData = FolderCache::load(refresh: true, skipUnreadRefresh: true);
            if (!$folderData['connected']) {
                json_response(['ok' => false, 'error' => $folderData['error'] ?: 'IMAP unavailable'], 503);
            }
        }

        $activeEncoded = (string) ($_GET['folder'] ?? '');
        $paths = $this->bootstrapFolderPathsLight($folderData['folders'], $activeEncoded);
        $paths = array_values(array_filter($paths, fn (string $p) => FolderCache::canAccess($p)));

        $payload = [
            'ok' => true,
            'synced' => [],
            'folders_changed' => $bootstrapped,
            'unread_counts' => FolderCache::sidebarUnreadCountsFromSession(),
        ];

        json_response_then($payload, function () use ($paths, $activeEncoded): void {
            $imap = new ImapService();
            if (!$imap->connect()) {
                return;
            }

            MailCacheService::bootstrapSync($imap, $paths);

            $active = decode_folder_path($activeEncoded);
            if ($active !== '' && folder_badge_uses_index_truth($active)) {
                $perPage = mail_per_page();
                $cached = MailCacheService::listFromCache($active, 1, $perPage);
                MailCacheService::reconcileBadgeFromIndex($active, $cached['messages'] ?? []);
            }

            $draftPaths = array_values(array_filter($paths, static fn (string $p): bool => folder_uses_draft_badge($p)));
            if ($draftPaths !== []) {
                FolderCache::refreshPaths($draftPaths);
            }
        });
    }

    /**
     * Periodic live check for new mail — polled roughly every ~30s while the
     * webmail is open. Responds instantly with the current counts, then (after the
     * session lock is released, so it never blocks an interactive folder/message
     * open) routes any new INBOX mail into the name folders and reconciles this
     * session's sidebar badges from the freshly-synced index. The existing light
     * polls then surface the new rows + bumped counts and fire the sound / desktop
     * notification.
     */
    public function liveSync(): void
    {
        requireAuth();

        // Release the session lock up front so this background poll never blocks an
        // interactive folder-open or message-open (those need the session) while it
        // does its IMAP/index work below.
        releaseSessionLock();

        // Route new INBOX mail into the name folders. Throttled + shared-lock guarded
        // (filter_min_interval): with many sessions polling in parallel, only ONE
        // filter runs per interval across all of them — a fast no-op the rest of the
        // time. This is the gentle variant (a single ~2-min poll per session), sized
        // for shared hosting after the 3-request/30s version overloaded it.
        perf_mark('livesync_filter_start');
        FilterService::runBackground(false);
        perf_mark('livesync_filter_done');

        // Background old-email import: any user's poll re-kicks a stalled
        // import chain (cheap state-file read; no-op when not importing).
        history_import_watchdog();

        // Reflect any newly-routed mail in THIS session's sidebar badges from the
        // updated index (mostly DB work; an IMAP STATUS only for not-yet-warmed
        // folders), then return the FRESH counts so the client bumps the badge and
        // fires the new-mail sound/notification in this one request — no follow-ups.
        $this->reconcileSidebarBadgesFromIndex();

        // Detect folders created/removed in another mail client (throttled) so the
        // sidebar can live-refresh without a re-login, and hand the client a
        // signature of the current folder set to compare against.
        FolderCache::syncFoldersIfDue();
        $sidebarFolders = FolderCache::load(skipUnreadRefresh: true)['folders'] ?? [];

        json_response([
            'ok' => true,
            'unread_counts' => FolderCache::sidebarUnreadCountsFromSession(),
            'folders_sig' => FolderCache::foldersSignature($sidebarFolders),
        ]);
    }

    /**
     * Re-rendered sidebar folder list, fetched by the live-sync poll only when
     * the folder signature changed — so folders created/removed in another mail
     * client appear/disappear in the sidebar without a re-login or navigation.
     */
    public function sidebarFragment(): void
    {
        requireAuth();
        $folderData = FolderCache::load(skipUnreadRefresh: true);
        releaseSessionLock();

        $sidebarFolders = $folderData['folders'];
        $folders = $sidebarFolders;
        $unreadCounts = $folderData['unread_counts'] ?? [];
        $active = decode_folder_path((string) ($_GET['active'] ?? ''));
        $activeFolder = $active !== '' ? $active : null;
        // The partial's admin footer (Admin panel / Connection status) is gated on
        // $sessionUser; layout.php sets it for full page renders, so set it here too
        // or the live-swapped sidebar loses those links for admins.
        $sessionUser = Auth::user();

        ob_start();
        require base_path('views/partials/folder-sidebar.php');
        $html = (string) ob_get_clean();

        json_response([
            'ok' => true,
            'html' => $html,
            'sig' => FolderCache::foldersSignature($sidebarFolders),
        ]);
    }

    /**
     * Completion status of a deferred move/delete (from the pending-ops journal),
     * so the client's toast can report the truth: "Moving…" → "Moved" / "failed".
     */
    public function opStatus(): void
    {
        requireAuth();
        releaseSessionLock();

        $status = mail_ops_journal_status((int) ($_GET['id'] ?? 0));
        if ($status === null) {
            json_response(['ok' => false, 'status' => 'unknown'], 404);
        }

        json_response(['ok' => true, 'status' => $status['status'], 'detail' => $status['detail']]);
    }

    /**
     * Warm only the active folder and inbox on bootstrap — not every sidebar folder.
     *
     * @param list<array{path: string, name: string}> $folders
     * @return list<string>
     */
    private function bootstrapFolderPathsLight(array $folders, string $activeFolderEncoded): array
    {
        $paths = [
            $this->filterSourceFolder(),
        ];

        $ownInbox = employee_linked_inbox_path();
        if ($ownInbox !== null && $ownInbox !== '') {
            $paths[] = employee_messages_imap_path($ownInbox);
        }

        if ($activeFolderEncoded !== '') {
            $active = decode_folder_path($activeFolderEncoded);
            if ($active !== '') {
                $navPath = sidebar_folder_nav_path($active);
                $paths[] = $navPath;
                if ($navPath !== $active) {
                    $paths[] = $active;
                }
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

        // Lightweight poll. When the viewed folder is stale, sync its headers
        // from IMAP INLINE (before responding) so the SAME response carries any
        // new mail — the poll is self-sufficient. The old design synced in the
        // background and depended on a JS follow-up poll to show the row, which
        // browser background-tab timer throttling and timing races broke (new
        // mail only appeared after a manual refresh). The inline sync holds this
        // one background poll ~700ms longer, which is imperceptible (the user
        // never waits on it), and removes the whole fragile follow-up chain.
        // A folder mid-optimistic-update (post-send preview / pending arrival)
        // is NOT synced here — a sync would race its optimistic row.
        if ($light && $query === '') {
            $age = MailCacheService::secondsSinceLastSync($folderPath);
            $shouldSync = ($age === null || $age >= 25)
                && mail_get_post_send_preview($folderPath) === null
                && mail_get_pending_arrivals($folderPath) === [];

            if ($shouldSync) {
                $imap = new ImapService();
                if ($imap->connect()) {
                    MailCacheService::syncFolderHeaders($imap, $folderPath);
                    // Don't clobber an optimistically-ahead badge with raw
                    // index truth while a new-mail bump is still pending.
                    if (folder_badge_uses_index_truth($folderPath)
                        && !FolderCache::isPendingBadgePath($folderPath)) {
                        MailCacheService::reconcileBadgeFromIndex($folderPath);
                    }
                }
            }

            // Read the (now-fresh) window AFTER the sync so new rows are included.
            $cached = MailCacheService::listConversationWindow($folderPath, $page, $perPage)
                ?? ['messages' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 0];

            // refreshing:false — the response already reflects the sync, so the
            // client needs no follow-up poll.
            $this->echoFolderSyncJson($folderPath, $cached, light: true, refreshing: false);
            releaseSessionLock();

            return;
        }

        $forceFilter = ($_GET['filter'] ?? '') === '1';
        $forceRefresh = ($_GET['force'] ?? '') === '1';

        if ($forceFilter && $this->shouldSkipPostSendFilter()) {
            $forceFilter = false;
        }

        $needsHeaderSync = $query === '' && $page === 1 && (
            $forceFilter
            || (
                !$this->shouldSkipPostSendFilter()
                && (
                    MailCacheService::isStale($folderPath)
                    || MailCacheService::badgeAheadOfIndex($folderPath)
                    || FolderCache::isPendingBadgePath($folderPath)
                    || mail_get_post_send_preview($folderPath) !== null
                    // Messages just moved INTO this folder are still optimistic
                    // rows — sync headers so the real rows replace them.
                    || mail_get_pending_arrivals($folderPath) !== []
                )
            )
            || ($forceRefresh && !MailCacheService::hasFolderData($folderPath))
        );

        // Warm cache: avoid opening IMAP when the indexed list is already current.
        if ($query === '' && !$needsHeaderSync) {
            $cached = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
            if ($cached !== null) {
                $this->echoFolderSyncJson($folderPath, $cached, light: true);

                return;
            }
        }

        // Folder-open revalidation poll (force=1 without an explicit filter run):
        // the client already painted this folder instantly from its own cached
        // fragment, so THIS request is a background revalidation. Sync headers
        // INLINE so the response carries any mail that arrived while the user was
        // in another folder — then a single navigation poll shows it, instead of
        // returning the stale cache and waiting for the next 30s interval poll
        // (which is exactly why new mail "only showed after clicking Refresh":
        // Refresh sends filter=1 and takes the synchronous path below, plain
        // navigation did not). A ~20s floor still skips the IMAP round trip on
        // rapid folder switching, where the cache is already current.
        if (
            $needsHeaderSync
            && !$forceFilter
            && (!$forceRefresh || MailCacheService::hasFolderData($folderPath))
            && mail_get_post_send_preview($folderPath) === null
            && mail_get_pending_arrivals($folderPath) === []
        ) {
            $age = MailCacheService::secondsSinceLastSync($folderPath);
            if ($age === null || $age >= 20) {
                $imap = new ImapService();
                if ($imap->connect()) {
                    MailCacheService::syncFolderHeaders($imap, $folderPath);
                    if (folder_badge_uses_index_truth($folderPath)
                        && !FolderCache::isPendingBadgePath($folderPath)) {
                        MailCacheService::reconcileBadgeFromIndex($folderPath);
                    }
                    if (MailCacheService::hasFolderData($folderPath)
                        && !MailCacheService::badgeAheadOfIndex($folderPath)) {
                        FolderCache::clearPendingBadgePath($folderPath);
                    }
                }
            }

            // Read the (now-fresh) window AFTER the sync so new rows are included.
            $cached = MailCacheService::listConversationWindow($folderPath, $page, $perPage)
                ?? ['messages' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 0];
            $this->echoFolderSyncJson($folderPath, $cached, light: true);
            releaseSessionLock();

            return;
        }

        if ($forceFilter || (!$light && !$forceRefresh)) {
            $filterResult = $this->maybeRunFilter($folderPath, $forceFilter);
            if ($this->isFilterSource($folderPath) || ($filterResult['moved'] ?? 0) > 0) {
                FolderCache::syncUnreadBadges($folderPath);
            }
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            $cached = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
            if ($cached !== null) {
                $this->echoFolderSyncJson($folderPath, $cached, light: true);

                return;
            }
            http_response_code(503);
            echo json_encode(['error' => $imap->getLastError()]);
            return;
        }

        $list = null;
        $didImapSync = false;

        if ($query === '' && $page === 1 && $needsHeaderSync) {
            MailCacheService::syncFolderHeaders($imap, $folderPath);
            $didImapSync = true;
            $list = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
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
            $didImapSync = true;
            $list = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
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
            $list = MailCacheService::listConversationWindow($folderPath, $page, $perPage)
                ?? ['messages' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 0];
        }

        if ($forceFilter) {
            $sessionBadge = (int) (FolderCache::load(skipUnreadRefresh: true)['unread_counts'][$folderPath] ?? 0);
            if ($sessionBadge > 0 || MailCacheService::badgeAheadOfIndex($folderPath)) {
                MailCacheService::reconcileFolderBadge($imap, $folderPath);
            }
            $refreshed = MailCacheService::listConversationWindow($folderPath, $page, $perPage);
            if ($refreshed !== null) {
                $list = $refreshed;
            }
        }

        $this->echoFolderSyncJson($folderPath, $list, light: $light || !$didImapSync);
    }

    /**
     * @param array{messages: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int} $list
     */
    private function echoFolderSyncJson(string $folderPath, array $list, bool $light = false, bool $refreshing = false): void
    {
        $list = mail_filter_removed_messages($folderPath, $list);

        $preview = mail_get_post_send_preview($folderPath);

        $list = mail_apply_folder_list_view_pipeline($folderPath, $list, $light);
        // Never shrink the pipeline's total (with conversation pagination the
        // page holds fewer rows than the folder's conversation count); only
        // grow it so optimistic arrival rows remain counted.
        $list['total'] = max((int) ($list['total'] ?? 0), count($list['messages']));

        if (trim($_GET['q'] ?? '') === '' && !$light) {
            if (folder_badge_uses_index_truth($folderPath)) {
                MailCacheService::reconcileBadgeFromIndex($folderPath, $list['messages']);
            }
            if ($preview !== null && mail_post_send_preview_already_synced($folderPath, $preview)) {
                mail_clear_post_send_preview($folderPath);
            } elseif (
                $preview === null
                && MailCacheService::hasFolderData($folderPath)
                && !MailCacheService::badgeAheadOfIndex($folderPath)
            ) {
                FolderCache::clearPendingBadgePath($folderPath);
            }
        }

        $messages = [];

        foreach ($list['messages'] as $msg) {
            $uid = (int) $msg['uid'];
            $msgFolder = (string) ($msg['list_folder'] ?? $folderPath);
            $isDraft = is_draft_folder($folderPath);
            $listFrom = (string) ($msg['list_from'] ?? '');
            if ($listFrom === '') {
                $to = (string) ($msg['to'] ?? '');
                $listFrom = ($isDraft && $to !== '')
                    ? format_mail_from($to)
                    : format_mail_from($msg['from'] ?? '');
            }
            $rowDisplay = mail_list_row_display($msg, $folderPath);
            $entry = [
                'uid' => $uid,
                'from' => $listFrom,
                'subject' => $msg['subject'] ?? '(no subject)',
                'snippet' => (string) ($msg['snippet'] ?? ''),
                'is_draft' => $isDraft,
                'date' => format_mail_date($msg['date'] ?? ''),
                'sort_date' => (string) ($msg['sort_date'] ?? $msg['date'] ?? ''),
                'seen' => (bool) ($msg['seen'] ?? false),
                'flagged' => (bool) ($msg['flagged'] ?? false),
                'has_attachment' => (bool) ($msg['has_attachment'] ?? false),
                'avatar_initial' => mail_avatar_initial($rowDisplay['avatar_from']),
                'avatar_color' => mail_avatar_color($rowDisplay['avatar_from']),
            ];
            if (!empty($msg['thread_key'])) {
                $entry['thread_key'] = (string) $msg['thread_key'];
            }
            if (empty($msg['optimistic'])) {
                // Conversation metadata: all uids of this thread in this folder
                // (newest first) + count. Single-message rows carry [uid]/1.
                $threadUids = array_values(array_filter(array_map(
                    'intval',
                    is_array($msg['thread_uids'] ?? null) ? $msg['thread_uids'] : [$uid]
                ), static fn (int $u): bool => $u > 0));
                $entry['thread_uids'] = $threadUids !== [] ? $threadUids : [$uid];
                $entry['thread_count'] = max(1, (int) ($msg['thread_count'] ?? count($entry['thread_uids'])));
            }
            if (!empty($msg['optimistic'])) {
                $entry['optimistic'] = true;
            } else {
                $entry['folder_b64'] = encode_folder_path($msgFolder);
                $entry['url'] = message_url($msgFolder, $uid);
                $entry['reply_url'] = url('compose/reply?folder=' . encode_folder_path($msgFolder) . '&uid=' . $uid);
                $entry['reply_all_url'] = url('compose/reply-all?folder=' . encode_folder_path($msgFolder) . '&uid=' . $uid);
                $entry['forward_url'] = url('compose/forward?folder=' . encode_folder_path($msgFolder) . '&uid=' . $uid);
            }
            $messages[] = $entry;
        }

        // On the light (~30s) poll, cheaply re-derive the sidebar badges from the
        // SHARED index (DB only, no IMAP) so a read/unread done in another account
        // propagates here within a poll cycle instead of only on the 2-min
        // live-sync. The non-light path already reconciles (with IMAP STATUS).
        if ($light && trim($_GET['q'] ?? '') === '') {
            $this->reconcileSidebarBadgesFromIndex(dbOnly: true);
        }

        echo json_encode([
            'total' => $list['total'],
            'page' => $list['page'],
            'total_pages' => $list['total_pages'],
            // q-aware: search responses are per-message, so the client must not
            // run its thread-collapse pruning on them.
            'list_grouped' => trim($_GET['q'] ?? '') === '' && mail_should_group_list_by_thread($folderPath),
            'messages' => $messages,
            // True when this response kicked a background IMAP refresh of the
            // folder — the client polls again in a few seconds to pick up the
            // fresh rows instead of waiting a full poll interval.
            'refreshing' => $refreshing,
            'unread_counts' => $light
                ? FolderCache::sidebarUnreadCountsFromSession()
                : FolderCache::sidebarUnreadCounts(),
        ]);
    }

    public function foldersUnread(): void
    {
        requireAuth();
        releaseSessionLock();
        header('Content-Type: application/json; charset=utf-8');

        $light = ($_GET['light'] ?? '') === '1';
        if ($light) {
            // Cheap DB-only reconcile (index COUNTs, no IMAP) so a read/unread or
            // new mail in ANOTHER account (shared mailbox) propagates to this
            // already-open session's sidebar every poll. Without it the light
            // path returned stale session-only counts — the "sender" browser
            // never saw the recipient folder's badge appear or clear.
            $this->reconcileSidebarBadgesFromIndex(dbOnly: true);
            echo json_encode(['unread_counts' => FolderCache::sidebarUnreadCountsFromSession()]);

            return;
        }

        $afterSend = ($_GET['after_send'] ?? '') === '1';
        if ($afterSend) {
            echo json_encode(['unread_counts' => FolderCache::sidebarUnreadCounts()]);

            return;
        }

        $this->reconcileSidebarBadgesFromIndex();

        echo json_encode(['unread_counts' => FolderCache::sidebarUnreadCounts()]);
    }

    /**
     * Refresh this session's sidebar badges from the SHARED index so a read/unread
     * in another account (shared mailbox) shows up here. $dbOnly skips the IMAP
     * STATUS pass for cold folders, making it a cheap index-only reconcile suitable
     * for the frequent (~30s) poll path — the read state itself is index truth, so
     * no IMAP round-trip is needed to pick up another account's read.
     */
    private function reconcileSidebarBadgesFromIndex(bool $dbOnly = false): void
    {
        // Light polls piggyback this reconcile on every 30s folder sync from
        // every open tab. It only propagates DB-truth badge changes, so once
        // per ~20s per session is plenty — the skipped polls still return
        // their cached rows instantly.
        if ($dbOnly && time() - (int) ($_SESSION['_badge_reconcile_at'] ?? 0) < 20) {
            return;
        }

        perf_mark('reconcile_start');
        $folderData = FolderCache::load(skipUnreadRefresh: true);

        // ONE query for "which folders have index data" instead of a
        // hasFolderData() query per folder — with a huge imported registry
        // (~1000 folders) the per-folder loop meant thousands of SQL calls per
        // badge poll and pegged the server CPU.
        $synced = MailCacheService::syncedFolderPathSet();

        if (!$dbOnly) {
            $refreshPaths = [];
            foreach ($folderData['folders'] ?? [] as $folder) {
                $path = (string) ($folder['path'] ?? '');
                if ($path === '' || !folder_shows_unread_badge($path)) {
                    continue;
                }
                if (!folder_badge_uses_index_truth($path)) {
                    continue;
                }
                if (!MailCacheService::hasFolderDataInSet($path, $synced)) {
                    $refreshPaths[] = $path;
                }
            }

            if ($refreshPaths !== []) {
                FolderCache::refreshPaths(array_values(array_unique($refreshPaths)));
            }
        }

        FolderCache::beginUnreadBatch();
        try {
            foreach ($folderData['folders'] ?? [] as $folder) {
                $path = (string) ($folder['path'] ?? '');
                if ($path === '' || !folder_badge_uses_index_truth($path)) {
                    continue;
                }
                // In the frequent DB-only path, don't clobber a badge that's
                // optimistically ahead of a not-yet-synced index (pending new mail) —
                // only reconcile settled folders. That still lets another account's
                // READ (which lowers the shared index count) propagate here, without
                // prematurely dropping a fresh new-mail bump.
                if ($dbOnly && FolderCache::isPendingBadgePath($path)) {
                    continue;
                }
                // Never-indexed folders have no index truth to reconcile — their
                // badge keeps the session/STATUS value. Skipping them turns a
                // ~1000-folder loop into a handful of active folders.
                if (!MailCacheService::hasFolderDataInSet($path, $synced)) {
                    continue;
                }
                MailCacheService::reconcileBadgeFromIndex($path);
            }
        } finally {
            FolderCache::endUnreadBatch();
        }
        ensure_session_writable();
        $_SESSION['_badge_reconcile_at'] = time();
        // setUnreadCount() re-acquired the session lock for its writes; without
        // this release the lock stays held to the end of the request, and every
        // other request of this session queues behind it in session_start().
        releaseSessionLock();
        perf_mark('reconcile_done');
    }

    /**
     * Keep shared mailbox indexes fresh for admin so employee inbound (e.g. Erik → Support)
     * badges update without waiting for a filter move.
     */
    private function syncAdminSharedMailboxIndexes(): void
    {
        if (!MailCacheService::viewerIsAdmin()) {
            return;
        }

        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $paths = [];
        foreach ($folderData['folders'] ?? [] as $folder) {
            $path = FolderCache::resolvePath((string) ($folder['path'] ?? ''));
            if ($path !== '' && MailCacheService::isSharedEmployeeMailbox($path)) {
                $paths[] = $path;
            }
        }

        if ($paths === []) {
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return;
        }

        foreach (array_values(array_unique($paths)) as $path) {
            try {
                MailCacheService::refreshHeadersIfNeeded($imap, $path);
            } catch (\Throwable $e) {
                app_log('Admin shared mailbox sync failed for ' . $path . ': ' . $e->getMessage());
            }
        }
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

        if (MailCacheService::messageInIndex($folderPath, $uid)) {
            echo json_encode(['exists' => true]);
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
        $uids = employee_visible_correspondent_uids($folderPath, $uids);
        if ($uids === []) {
            echo json_encode(['ok' => true, 'has_attachment' => []]);
            return;
        }

        // Serve cached attachment flags; a message's attachment status never
        // changes, so we only pay the slow IMAP body-structure fetch once per
        // message. This is the difference between an instant folder open and a
        // multi-second one against a remote IMAP server.
        $result = mail_cached_attachment_flags($folderPath, $uids);
        $missing = array_values(array_diff($uids, array_keys($result)));

        if ($missing !== []) {
            $imap = new ImapService();
            if ($imap->connect() && $imap->openFolder($folderPath)) {
                // Cap per-request probes: each is an IMAP round trip (~150ms
                // remote). Uncapped rows are re-requested by the next
                // enrichment pass and served from the flag cache thereafter.
                $fetched = $imap->batchHasAttachments(array_slice($missing, 0, 8));
                mail_store_attachment_flags($folderPath, $fetched);
                foreach ($fetched as $uid => $has) {
                    $result[(int) $uid] = (bool) $has;
                }
            } elseif ($result === []) {
                http_response_code(503);
                echo json_encode(['ok' => false, 'error' => $imap->getLastError()]);
                return;
            }
        }

        echo json_encode([
            'ok' => true,
            'has_attachment' => $result,
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
        $uids = employee_visible_correspondent_uids($folderPath, $uids);
        if ($uids === []) {
            echo json_encode(['ok' => true, 'snippets' => []]);
            return;
        }

        // Don't connect to IMAP up front: resolveSnippetsForUids() serves cached
        // snippets from mail_bodies and only opens the folder (which connects lazily)
        // for uids it still has to fetch. On a repeat open — where every snippet is
        // cached — this avoids a wasted ~1.5s IMAP connect against a remote server.
        $imap = new ImapService();
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

        if ($folderPath === '' || $uid === 0) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Message not found']);
            return;
        }

        $prefetch = ($_GET['prefetch'] ?? '') === '1';
        $deferred = null;

        if ($uid < 0) {
            $preview = mail_get_post_send_preview_by_uid($folderPath, $uid);
            if ($preview === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Message not found']);
                return;
            }

            $anchorUid = mail_find_thread_anchor_uid_for_preview($folderPath, $preview);
            if ($anchorUid > 0) {
                $context = $this->loadMessageContext($folderPath, $anchorUid, markRead: false, deferred: $deferred);
                if ($context !== null) {
                    $html = view_string('mail/pane-read', $context);
                    $payload = [
                        'ok' => true,
                        'uid' => $uid,
                        'subject' => $context['message']['subject'] ?: '(no subject)',
                        'seen' => !empty($context['message']['seen']),
                        'was_unread' => false,
                        'html' => $html,
                        'optimistic' => true,
                        'unread_counts' => $context['unreadCounts'],
                        'folder_unread' => mail_folder_unread_count($context['unreadCounts'], $folderPath),
                    ];
                    if ($deferred !== null) {
                        json_response_then($payload, $deferred);
                    }
                    json_response($payload);
                }
            }

            $context = mail_build_optimistic_pane_context($folderPath, $preview);
            $html = view_string('mail/pane-read', $context);
            json_response([
                'ok' => true,
                'uid' => $uid,
                'subject' => $context['message']['subject'] ?: '(no subject)',
                'seen' => false,
                'was_unread' => false,
                'html' => $html,
                'optimistic' => true,
                'unread_counts' => $context['unreadCounts'],
                'folder_unread' => mail_folder_unread_count($context['unreadCounts'], $folderPath),
            ]);
        }

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
                'unread_counts' => FolderCache::sidebarUnreadCountsFromSession(),
                'folder_unread' => (int) (FolderCache::sidebarUnreadCountsFromSession()[$folderPath] ?? 0),
            ]);
        }

        $failureReason = null;
        $context = $this->loadMessageContext($folderPath, $uid, markRead: !$prefetch, deferred: $deferred, failureReason: $failureReason);
        if ($context === null) {
            if ($failureReason === 'unavailable') {
                // Couldn't reach the mail server this time — not a deleted message.
                // Signal a retryable error so the client keeps the row and retries.
                http_response_code(503);
                echo json_encode(['ok' => false, 'error' => 'Message is temporarily unavailable. Please try again.']);
                return;
            }
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Message not found', 'gone' => true]);
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
            'folder_unread' => mail_folder_unread_count($context['unreadCounts'], $folderPath),
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

        $privacyEmails = employee_correspondent_privacy_emails($folderPath);
        if ($privacyEmails !== null && !mail_message_involves_user($message, $privacyEmails)) {
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

        $failureReason = null;
        $context = $this->loadMessageContext($folderPath, $uid, failureReason: $failureReason);
        if ($context === null) {
            flash('error', $failureReason === 'unavailable'
                ? 'Could not reach the mail server. Please try again.'
                : 'Message not found.');
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
    private function loadMessageContext(
        string $folderPath,
        int $uid,
        bool $markRead = true,
        ?callable &$deferred = null,
        ?string &$failureReason = null
    ): ?array {
        assert_folder_access($folderPath);

        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $folders = $folderData['folders'];
        $unreadCounts = $folderData['unread_counts'] ?? [];

        $message = MailCacheService::getBody($folderPath, $uid);

        // Not cached locally — go to the mail server. Track whether we actually
        // reached it: against a slow/flaky remote host a transient connection
        // failure must NOT be mistaken for a deleted message, or we'd evict a
        // message that still exists on the server (and 404 the client).
        $serverReached = false;
        if ($message === null) {
            $imap = new ImapService();
            if ($imap->connect()) {
                $serverReached = true;
                $message = $this->fetchMessageFromImap($folderPath, $uid, $imap);
                if ($message === null) {
                    MailCacheService::syncFolderHeaders($imap, $folderPath);
                    $message = MailCacheService::getBody($folderPath, $uid)
                        ?? $this->fetchMessageFromImap($folderPath, $uid, $imap);
                }
                if ($message !== null) {
                    MailCacheService::saveBody($folderPath, $message);
                }
            }
        }

        if ($message === null) {
            // "Gone" must be CONFIRMED, not inferred: connect() succeeding does
            // not mean the fetch worked — this host silently drops SELECTs, and
            // a wrong "gone" evicts the cache row and blanks the client's pane
            // ("Select a message to read"). Only report gone when uid resolution
            // itself failed AND an independent overview probe (with its own
            // re-SELECT retry) agrees the message is absent.
            $confirmedGone = false;
            if ($serverReached) {
                $confirmedGone = $imap->getLastError() === 'Message not found.'
                    && $imap->getMessageOverviewByUid($folderPath, $uid) === null;
            }

            if ($confirmedGone) {
                MailCacheService::removeMessage($folderPath, $uid);
                $failureReason = 'gone';
            } else {
                // Transient failure — leave the cached row intact so the message
                // doesn't vanish; the caller reports a retryable error.
                $failureReason = 'unavailable';
            }

            return null;
        }

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
     * @return array<string, mixed>|null
     */
    private function fetchMessageFromImap(string $folderPath, int $uid, ?ImapService $imap = null): ?array
    {
        if ($imap === null) {
            $imap = new ImapService();
            if (!$imap->connect()) {
                return null;
            }
        }

        return $imap->getMessageByUid($folderPath, $uid);
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
        $privacyEmails = employee_correspondent_privacy_emails($folderPath);
        if ($privacyEmails !== null && !mail_message_involves_user($message, $privacyEmails)) {
            return null;
        }

        $message = mail_enrich_message_recipients_from_copies($folderPath, $uid, $message);

        mail_note_correspondents_from_message($message);

        // Plain per-folder model: opening an unseen message marks it read on IMAP
        // + index (one shared read state). No per-user read, no thread/correspondent
        // cross-folder marking. The index is the truth for "was it unread".
        $shouldMarkRead = $markRead && !MailCacheService::effectiveSeen($folderPath, $uid);
        if ($shouldMarkRead) {
            $message['seen'] = true;
            MailCacheService::updateIndexSeen($folderPath, $uid, true);
            $deferred = static function () use ($folderPath, $uid): void {
                $imap = new ImapService();
                if ($imap->connect()) {
                    $imap->markSeen($folderPath, $uid);
                }
            };
        }

        $markedRead = $shouldMarkRead;
        $unreadCounts = $markedRead
            ? mail_unread_counts_after_read($folderPath)
            : FolderCache::sidebarUnreadCounts();
        // The badge writes above re-acquired the session lock; the expensive
        // sanitize + thread build below must not hold it (parallel pane/sync
        // requests of the same session queue on session_start otherwise).
        releaseSessionLock();

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

        $sanitizedHtml = HtmlSanitizer::sanitize($html);
        $conversationThread = mail_build_conversation_thread(
            $message,
            $sanitizedHtml,
            $replyFrom,
            $folderPath,
            $uid,
        );

        return [
            'folderPath' => $folderPath,
            'folderB64' => encode_folder_path($folderPath),
            'folders' => $folders,
            'unreadCounts' => $unreadCounts,
            'message' => $message,
            'sanitizedHtml' => $sanitizedHtml,
            'conversationThread' => $conversationThread,
            'replyFrom' => $replyFrom,
            'moveTargets' => mail_move_target_folders($folders, $folderPath),
            'pollInterval' => (int) ($prefs['poll_interval'] ?? config('app')['mail_poll_interval']),
            'wasUnread' => $shouldMarkRead,
        ];
    }

    public function attachment(): void
    {
        requireAuth();
        releaseSessionLock();

        $folderPath = mail_folder_path($_GET['folder'] ?? '');
        $uid = (int) ($_GET['uid'] ?? 0);
        $partId = (string) ($_GET['part'] ?? '');
        $inline = ($_GET['disposition'] ?? '') === 'inline';
        $threadPending = ($_GET['thread_pending'] ?? '') === '1';

        if ($folderPath === '' || $uid <= 0 || $partId === '') {
            error_page(404);
        }
        assert_folder_access($folderPath);

        if ($threadPending) {
            $pending = mail_load_thread_reply_attachment($folderPath, $uid, $partId);
            if ($pending === null) {
                error_page(404, 'Attachment not found.');
            }

            $mime = $this->safeAttachmentMime($pending['mime'] ?? '', $pending['filename'] ?? '');
            $canInline = $inline && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
            $filename = $this->safeAttachmentName($pending['filename'] ?? 'attachment');

            header('Content-Type: ' . $mime);
            header('X-Content-Type-Options: nosniff');
            header(
                'Content-Disposition: ' . ($canInline ? 'inline' : 'attachment')
                . '; filename="' . $filename . '"'
                . "; filename*=UTF-8''" . rawurlencode($filename)
            );
            header('Content-Length: ' . strlen($pending['content']));
            echo $pending['content'];
            exit;
        }

        // Connect lazily: a disk-cache hit must cost zero IMAP round trips.
        $imap = null;

        $privacyEmails = employee_correspondent_privacy_emails($folderPath);
        if ($privacyEmails !== null) {
            $message = MailCacheService::getBody($folderPath, $uid);
            if ($message === null) {
                $imap = new ImapService();
                if (!$imap->connect()) {
                    error_page(500, 'IMAP connection failed.');
                }
                $message = $imap->getMessageByUid($folderPath, $uid);
            }
            if ($message === null || !mail_message_involves_user($message, $privacyEmails)) {
                error_page(404, 'Attachment not found.');
            }
        }

        // Attachment bytes are immutable per (folder, uid, part) — serve repeat
        // requests from disk. Without this, every open of an email with inline
        // images fired one fresh IMAP connection PER IMAGE, every time.
        $cacheDir = base_path('storage/cache/attachments');
        $cacheKey = sha1($folderPath . '|' . $uid . '|' . $partId);
        $metaFile = $cacheDir . '/' . $cacheKey . '.json';
        $binFile = $cacheDir . '/' . $cacheKey . '.bin';

        if (is_file($metaFile) && is_file($binFile)) {
            $meta = json_decode((string) @file_get_contents($metaFile), true);
            $content = @file_get_contents($binFile);
            if (is_array($meta) && $content !== false) {
                $this->emitAttachment(
                    (string) ($meta['mime'] ?? ''),
                    (string) ($meta['filename'] ?? ''),
                    $content,
                    $inline
                );
            }
        }

        if ($imap === null) {
            $imap = new ImapService();
            if (!$imap->connect()) {
                error_page(500, 'IMAP connection failed.');
            }
        }

        $attachment = $imap->getAttachment($folderPath, $uid, $partId);
        if ($attachment === null) {
            error_page(404, 'Attachment not found.');
        }

        // Best-effort cache write (bounded at 5MB per part): bin first via
        // tmp+rename (never serve a truncated file), then the small meta json.
        // Any failure just leaves the IMAP path in place for the next request.
        if (strlen($attachment['content']) <= 5 * 1024 * 1024) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $tmp = $binFile . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $attachment['content']) !== false) {
                @unlink($binFile);
                if (@rename($tmp, $binFile)) {
                    @file_put_contents($metaFile, json_encode([
                        'mime' => (string) ($attachment['mime'] ?? ''),
                        'filename' => (string) ($attachment['filename'] ?? ''),
                    ]));
                } else {
                    @unlink($tmp);
                }
            }
            // Occasionally prune month-old entries so the cache stays bounded.
            if (mt_rand(1, 50) === 1) {
                $cutoff = time() - 30 * 86400;
                $scanned = 0;
                foreach ((glob($cacheDir . '/*') ?: []) as $old) {
                    if (++$scanned > 500) {
                        break;
                    }
                    if (@filemtime($old) < $cutoff) {
                        @unlink($old);
                    }
                }
            }
        }

        $this->emitAttachment(
            (string) ($attachment['mime'] ?? ''),
            (string) ($attachment['filename'] ?? ''),
            $attachment['content'],
            $inline
        );
    }

    /**
     * Send attachment bytes with browser-cacheable headers. Success responses
     * only — error pages keep the global no-cache header so a transient IMAP
     * failure is never pinned in the browser cache.
     */
    private function emitAttachment(string $rawMime, string $rawName, string $content, bool $inline): never
    {
        $mime = $this->safeAttachmentMime($rawMime, $rawName);
        $canInline = $inline && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
        $filename = $this->safeAttachmentName($rawName !== '' ? $rawName : 'attachment');

        // Attachment bytes never change for a given uid+part — let the browser
        // keep them (overrides the global no-cache header from the front controller).
        header('Cache-Control: private, max-age=604800, immutable');
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header(
            'Content-Disposition: ' . ($canInline ? 'inline' : 'attachment')
            . '; filename="' . $filename . '"'
            . "; filename*=UTF-8''" . rawurlencode($filename)
        );
        header('Content-Length: ' . strlen($content));
        echo $content;
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
        assert_folder_access(mail_resolve_move_target_path($targetPath));

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
        assert_folder_access(mail_resolve_move_target_path($targetPath));

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

    /**
     * Restore a single Trash message to the folder it was deleted from.
     */
    public function restore(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();

        $folderPath = mail_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        if ($folderPath === '' || $uid <= 0) {
            json_response(['ok' => false, 'error' => 'Invalid restore request.'], 422);
        }
        assert_folder_access($folderPath);

        $this->performRestore($folderPath, [$uid]);
    }

    /**
     * Restore selected Trash messages to the folders they were deleted from.
     */
    public function bulkRestore(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();

        $folderPath = mail_folder_path($_POST['folder'] ?? '');
        $uids = $this->resolveBulkUids($folderPath);
        if ($folderPath === '' || $uids === []) {
            json_response(['ok' => false, 'error' => 'No messages selected to restore.'], 422);
        }
        assert_folder_access($folderPath);

        $this->performRestore($folderPath, $uids);
    }

    /**
     * Move Trash messages back to their ORIGINAL folders (Outlook-style restore).
     * The origin is the source folder of the latest journaled move that put each
     * message (by Message-ID) into Trash; messages without a journal record fall
     * back to the Inbox. Messages are grouped per origin and each group runs
     * through the full verified move pipeline (repairs + live verification).
     *
     * @param list<int> $uids
     */
    private function performRestore(string $folderPath, array $uids): void
    {
        $resolved = FolderCache::resolvePath($folderPath);
        if (!is_trash_folder($resolved)) {
            json_response(['ok' => false, 'error' => 'Restore is only available in the Trash folder.'], 422);
        }

        $indexPath = MailCacheService::indexFolderPath($resolved);
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $u): bool => $u > 0)));
        if ($uids === []) {
            json_response(['ok' => false, 'error' => 'No messages selected to restore.'], 422);
        }

        $filterInbox = FolderCache::resolvePath((string) (config('app')['filter_source_folder'] ?? 'INBOX'));

        // Group uids by their origin folder.
        $groups = [];
        foreach ($uids as $uid) {
            $row = Database::fetchOne(
                'SELECT message_id FROM mail_index WHERE folder_path = ? AND imap_uid = ? LIMIT 1',
                [$indexPath, $uid]
            );
            $mid = mail_normalize_thread_id((string) ($row['message_id'] ?? ''));
            $origin = $mid !== '' ? $this->trashOriginForMessageId($mid) : null;
            $target = ($origin !== null && $origin !== '') ? $origin : $filterInbox;
            $groups[$target][] = $uid;
        }

        try {
            $opIds = [];
            $runners = [];
            foreach ($groups as $targetPath => $groupUids) {
                [$opId, $runner] = $this->queueMoveToTarget($resolved, $indexPath, $groupUids, (string) $targetPath, $filterInbox);
                if ($opId > 0) {
                    $opIds[] = $opId;
                }
                $runners[] = $runner;
            }

            mail_reconcile_linked_correspondent_badges($resolved);
            $counts = FolderCache::sidebarUnreadCountsFromSession();

            json_response_then($this->appendCorrespondentFolderPrune($resolved, [
                'ok' => true,
                'restored' => count($uids),
                'uids' => array_values($uids),
                'targets' => array_keys($groups),
                'op_id' => $opIds[0] ?? 0,
                'op_ids' => $opIds,
                'unread_counts' => $counts,
            ]), static function () use ($runners): void {
                foreach ($runners as $run) {
                    $run();
                }
            });
        } catch (\Throwable $e) {
            app_log('performRestore failed: ' . $e->getMessage());
            json_response(['ok' => false, 'error' => 'Could not restore messages.'], 500);
        }
    }

    /**
     * Origin folder of the latest journaled move that sent this Message-ID into
     * Trash. Null when unknown (journal purged or pre-journal message).
     */
    private function trashOriginForMessageId(string $mid): ?string
    {
        try {
            $rows = Database::query(
                "SELECT source_json, target_folder FROM mail_pending_ops
                 WHERE op_type = 'move' AND message_ids_json LIKE ?
                 ORDER BY id DESC
                 LIMIT 10",
                ['%' . $mid . '%']
            )->fetchAll();
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $r) {
            $target = FolderCache::resolvePath((string) ($r['target_folder'] ?? ''));
            if ($target === '' || !is_trash_folder($target)) {
                continue;
            }
            $sources = json_decode((string) ($r['source_json'] ?? ''), true);
            if (!is_array($sources)) {
                continue;
            }
            foreach (array_keys($sources) as $src) {
                $srcResolved = FolderCache::resolvePath((string) $src);
                if ($srcResolved !== '' && !is_trash_folder($srcResolved) && !is_draft_folder($srcResolved)) {
                    return $srcResolved;
                }
            }
        }

        return null;
    }

    /**
     * Optimistic phase of a move from ONE source folder: cache relocate, arrival
     * placeholders, tombstones, badges, journal entry. Returns the op id and a
     * deferred runner that completes the move (server move + repair + live
     * verification). Shared by restore; performMove keeps its own inline flow.
     *
     * @param list<int> $uids
     * @return array{0: int, 1: callable}
     */
    private function queueMoveToTarget(string $fromResolved, string $fromIndexPath, array $uids, string $targetPath, string $filterInbox): array
    {
        $targetPath = mail_resolve_move_target_path($targetPath);
        $targetIndexPath = MailCacheService::indexFolderPath($targetPath);
        $siblingMode = $this->siblingCopyModeForTarget($targetPath);
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));

        if (strcasecmp($targetPath, $filterInbox) === 0) {
            FilterService::preserveManualInboxPlacementFromIndex($fromIndexPath, $uids, $filterInbox);
        }

        $arrivalRows = mail_capture_move_arrival_rows($fromIndexPath, $uids);
        MailCacheService::relocateCachedMessages($fromIndexPath, $targetIndexPath, $uids);
        mail_mark_uids_removed($fromResolved, $uids);
        mail_clear_removed_uids($targetIndexPath, $uids);
        if ($siblingMode !== 'none') {
            $this->removeSiblingCopiesFromCache($fromResolved, $uids, $targetPath);
        }
        MailCacheService::reconcileBadgeFromIndex($fromIndexPath);
        if ($targetIndexPath !== '' && strcasecmp($targetIndexPath, $fromIndexPath) !== 0) {
            MailCacheService::reconcileBadgeFromIndex($targetIndexPath);
        }

        $movesByFolder = [$fromIndexPath => $uids];
        $opMessageIds = array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['message_id'] ?? ''),
            $arrivalRows
        )));
        $opId = mail_ops_journal_create('move', $movesByFolder, $targetPath, $opMessageIds);
        foreach ($arrivalRows as &$arrivalRow) {
            $arrivalRow['op_id'] = $opId;
        }
        unset($arrivalRow);
        mail_queue_pending_arrivals($targetIndexPath, $arrivalRows);

        $runner = function () use ($movesByFolder, $targetPath, $targetIndexPath, $siblingMode, $filterInbox, $opId, $opMessageIds): void {
            $this->runMoveOpToCompletion($movesByFolder, $targetPath, $targetIndexPath, $siblingMode, $filterInbox, $opId, $opMessageIds);
        };

        return [$opId, $runner];
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
        // Conversation rows send uids[] (all thread messages); single rows send uid.
        $uids = [];
        if (is_array($_POST['uids'] ?? null)) {
            $uids = array_values(array_filter(array_map('intval', $_POST['uids']), static fn (int $u): bool => $u > 0));
        }
        if ($uids === []) {
            $uid = (int) ($_POST['uid'] ?? 0);
            if ($uid > 0) {
                $uids = [$uid];
            }
        }

        if ($folderPath === '' || $uids === []) {
            $this->actionError('Invalid request.', 'folder/' . encode_folder_path('INBOX'));
        }
        assert_folder_access($folderPath);

        $this->performMove($folderPath, $uids, spam_folder_path(), 'folder/' . encode_folder_path($folderPath), 'Message moved to Spam.');
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
        $locations = $this->resolveBulkLocations($folderPath, $uids);
        if ($locations === []) {
            if (wants_json()) {
                json_response(['ok' => false, 'error' => 'Selected messages are no longer in this folder.'], 422);
            }
            $this->actionError('Selected messages are no longer in this folder.', $redirect);
        }

        $uids = $this->locationsToUids($locations);
        $movesByFolder = [];
        foreach ($locations as $location) {
            $fromPath = (string) ($location['folder_path'] ?? '');
            $uid = (int) ($location['imap_uid'] ?? 0);
            if ($fromPath === '' || $uid <= 0) {
                continue;
            }
            $movesByFolder[$fromPath][] = $uid;
        }
        foreach ($movesByFolder as $fromPath => $folderUids) {
            $movesByFolder[$fromPath] = array_values(array_unique($folderUids));
        }

        $indexFolderPath = MailCacheService::indexFolderPath($resolvedFolderPath);
        $targetPath = mail_resolve_move_target_path($targetPath);
        if ($targetPath === '' || is_draft_folder($targetPath)) {
            if (wants_json()) {
                json_response(['ok' => false, 'error' => 'Messages cannot be moved to Drafts.'], 422);
            }
            $this->actionError('Messages cannot be moved to Drafts.', $redirect);
        }
        $targetIndexPath = MailCacheService::indexFolderPath($targetPath);
        $siblingMode = $this->siblingCopyModeForTarget($targetPath);
        $filterInbox = FolderCache::resolvePath((string) (config('app')['filter_source_folder'] ?? 'INBOX'));

        $unreadDelta = max(0, (int) ($_POST['unread_delta'] ?? 0));
        if ($unreadDelta === 0 && ($_POST['all_in_folder'] ?? '') === '1') {
            $counts = FolderCache::load(skipUnreadRefresh: true)['unread_counts'];
            $unreadDelta = (int) ($counts[$resolvedFolderPath] ?? $counts[$indexFolderPath] ?? 0);
        }

        if (wants_json()) {
            try {
                $movedTotal = 0;
                $arrivalRows = [];
                foreach ($movesByFolder as $fromIndexPath => $folderUids) {
                    $fromResolved = FolderCache::resolvePath($fromIndexPath);
                    // Before relocate deletes the source rows: when rescuing into the
                    // filter inbox, record the messages' Message-IDs as processed so
                    // the filter won't route them straight back out (e.g. to Junk).
                    if (strcasecmp($targetPath, $filterInbox) === 0) {
                        FilterService::preserveManualInboxPlacementFromIndex($fromIndexPath, $folderUids, $filterInbox);
                    }
                    // Capture optimistic rows for the TARGET list before the source
                    // rows are deleted — this is what makes a deleted message appear
                    // in Trash (or any move target) instantly instead of after the
                    // deferred IMAP move + next sync.
                    $arrivalRows = array_merge(
                        $arrivalRows,
                        mail_capture_move_arrival_rows($fromIndexPath, $folderUids)
                    );
                    $relocated = MailCacheService::relocateCachedMessages($fromIndexPath, $targetIndexPath, $folderUids);
                    mail_mark_uids_removed($fromResolved, $folderUids);
                    mail_clear_removed_uids($targetIndexPath, $folderUids);
                    if ($siblingMode !== 'none') {
                        $this->removeSiblingCopiesFromCache($fromResolved, $folderUids, $targetPath);
                    }
                    if ($relocated === 0 && !is_trash_folder($targetPath)) {
                        MailCacheService::invalidateFolder($targetPath);
                    }

                    MailCacheService::reconcileBadgeFromIndex($fromIndexPath);
                    $movedTotal += count($folderUids);
                }

                $opMessageIds = array_values(array_filter(array_map(
                    static fn (array $r): string => (string) ($r['message_id'] ?? ''),
                    $arrivalRows
                )));
                $opId = mail_ops_journal_create('move', $movesByFolder, $targetPath, $opMessageIds);
                // Tag each optimistic row with its op so the list merge can drop
                // ghosts (op settled but the real row never appeared).
                foreach ($arrivalRows as &$arrivalRow) {
                    $arrivalRow['op_id'] = $opId;
                }
                unset($arrivalRow);
                mail_queue_pending_arrivals($targetIndexPath, $arrivalRows);

                if ($targetIndexPath !== '' && strcasecmp($targetIndexPath, $indexFolderPath) !== 0) {
                    MailCacheService::reconcileBadgeFromIndex($targetIndexPath);
                }
                mail_reconcile_linked_correspondent_badges($resolvedFolderPath);
                $counts = FolderCache::sidebarUnreadCountsFromSession();

                json_response_then($this->appendCorrespondentFolderPrune($resolvedFolderPath, [
                    'ok' => true,
                    'moved' => $movedTotal,
                    'errors' => 0,
                    'uids' => array_values($uids),
                    'target' => $targetPath,
                    'op_id' => $opId,
                    'unread_counts' => $counts,
                ]), function () use ($movesByFolder, $targetPath, $targetIndexPath, $siblingMode, $filterInbox, $opId, $opMessageIds): void {
                    $this->runMoveOpToCompletion($movesByFolder, $targetPath, $targetIndexPath, $siblingMode, $filterInbox, $opId, $opMessageIds);
                });
            } catch (\Throwable $e) {
                app_log('performMove json failed: ' . $e->getMessage());
                json_response(['ok' => false, 'error' => 'Could not move messages.'], 500);
            }
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->actionError($imap->getLastError(), $redirect);
        }

        $folders = FolderCache::load(skipUnreadRefresh: true)['folders'];
        if (!$this->folderExists($folders, $targetPath)) {
            if (!$imap->folderExistsOnServer($targetPath) && !$imap->ensureFolderPath($targetPath)) {
                $this->actionError('Target folder could not be created on the mail server.', $redirect);
            }
            (new FolderCache())->clear();
        }

        $moved = 0;
        $errors = 0;
        $allMovedUids = [];
        foreach ($movesByFolder as $fromIndexPath => $folderUids) {
            $fromResolved = FolderCache::resolvePath($fromIndexPath);
            $result = $imap->moveMessages($fromResolved, $folderUids, $targetPath);
            $moved += (int) ($result['moved'] ?? 0);
            $errors += (int) ($result['errors'] ?? 0);
            $movedUids = $result['uids'] ?? [];
            $allMovedUids = array_merge($allMovedUids, $movedUids);

            if ($movedUids !== []) {
                MailCacheService::removeMessages($fromIndexPath, $movedUids);
                if (strcasecmp($targetPath, $filterInbox) === 0) {
                    FilterService::preserveManualInboxPlacement($imap, $targetPath, $movedUids);
                }
            }
        }
        $movedUids = $allMovedUids;

        if ($movedUids !== []) {
            try {
                MailCacheService::resyncFolderAfterMove($imap, $targetPath, $movedUids, 30);
                MailCacheService::reconcileBadgeFromIndex($targetPath);
            } catch (\Throwable $e) {
                app_log('Post-move cache sync failed for ' . $targetPath . ': ' . $e->getMessage());
            }
        }

        if ($siblingMode !== 'none' && $movedUids !== []) {
            $this->removeSiblingCopiesFromCache($resolvedFolderPath, $movedUids);
            $imap = new ImapService();
            if ($imap->connect()) {
                $this->handleSiblingCopies($imap, $resolvedFolderPath, $movedUids, $targetPath, $siblingMode);
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
    private function removeSiblingCopiesFromCache(string $sourceFolder, array $uids, string $targetPath = ''): array
    {
        $affected = [];
        $seenMessageIds = [];
        $targetPath = FolderCache::resolvePath($targetPath);

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
                if ($targetPath !== '' && strcasecmp($copyPath, $targetPath) === 0) {
                    continue;
                }
                if (strcasecmp($copyPath, FolderCache::resolvePath($sourceFolder)) === 0 && $copyUid === $uid) {
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
     * Execute a journaled move to completion: server move, half-move repair,
     * per-uid retry, live verification against the server, journal finish.
     * Runs in the deferred (post-response) phase; shared by move and restore.
     *
     * @param array<string, list<int>> $movesByFolder source index path => uids
     * @param list<string> $opMessageIds
     */
    private function runMoveOpToCompletion(
        array $movesByFolder,
        string $targetPath,
        string $targetIndexPath,
        string $siblingMode,
        string $filterInbox,
        int $opId,
        array $opMessageIds,
    ): void {
        $requested = 0;
        $moved = 0;
        foreach ($movesByFolder as $fromIndexPath => $folderUids) {
            $fromResolved = FolderCache::resolvePath($fromIndexPath);
            $requested += count($folderUids);
            $moved += $this->executeMoveOnServer(
                $fromResolved,
                $folderUids,
                $targetPath,
                $siblingMode,
                $filterInbox,
                $targetIndexPath,
            );
        }

        // Truth check + half-move repair. The host's IMAP is known to "half-move":
        // the copy lands in the target but the source delete/expunge silently
        // fails, leaving the message in BOTH folders while reporting success.
        // After executeMoveOnServer's final source sync re-mirrored the server:
        // for any of this op's Message-IDs still in a SOURCE folder, check the
        // TARGET — if the copy is there, FINISH the move by deleting the source
        // copy (repair); if not, retry the move for that message individually.
        $targetIdx = MailCacheService::indexFolderPath(FolderCache::resolvePath($targetPath));
        $repaired = 0;
        $repairImap = null;
        foreach (array_keys($movesByFolder) as $fromIndexPath) {
            $fromResolved = FolderCache::resolvePath((string) $fromIndexPath);
            $srcIndex = MailCacheService::indexFolderPath($fromResolved);
            foreach ($opMessageIds as $mid) {
                $mid = mail_normalize_thread_id((string) $mid);
                if ($mid === '') {
                    continue;
                }
                try {
                    $row = Database::fetchOne(
                        'SELECT imap_uid FROM mail_index
                         WHERE folder_path = ? AND message_id IS NOT NULL
                           AND LOWER(REPLACE(REPLACE(message_id, \'<\', \'\'), \'>\', \'\')) = ?
                         LIMIT 1',
                        [$srcIndex, $mid]
                    );
                } catch (\Throwable) {
                    $row = null;
                }
                if ($row === null) {
                    continue; // gone from source — moved cleanly
                }

                $inTarget = null;
                try {
                    $inTarget = Database::fetchOne(
                        'SELECT 1 FROM mail_index
                         WHERE folder_path = ? AND message_id IS NOT NULL
                           AND LOWER(REPLACE(REPLACE(message_id, \'<\', \'\'), \'>\', \'\')) = ?
                         LIMIT 1',
                        [$targetIdx, $mid]
                    );
                } catch (\Throwable) {
                    $inTarget = null;
                }

                $srcUid = (int) $row['imap_uid'];
                if ($inTarget !== null) {
                    // Copy landed — delete the leftover source copy.
                    $repairImap ??= new ImapService();
                    if ($repairImap->connect()) {
                        $repairImap->deleteMessages($fromResolved, [$srcUid]);
                        $repaired++;
                    }
                } else {
                    // Didn't move at all — the host has been seen accepting a batch
                    // move and silently applying it to only some uids. Retry this
                    // one individually.
                    $repairImap ??= new ImapService();
                    if ($repairImap->connect()) {
                        $repairImap->moveMessage($fromResolved, $srcUid, $targetPath);
                        $repaired++;
                    }
                }
            }
        }

        // FINAL verification against LIVE server state. This host has returned
        // "success" for batch AND per-uid moves without acting, so command results
        // (and repair bookkeeping) prove nothing: list each source folder and
        // count the op's Message-IDs still there.
        $stillInSource = 0;
        $verified = true;
        $verifyImap = $repairImap ?? new ImapService();
        if ($opMessageIds !== [] && $verifyImap->connect()) {
            foreach (array_keys($movesByFolder) as $fromIndexPath) {
                $fromResolved = FolderCache::resolvePath((string) $fromIndexPath);
                $srcList = $verifyImap->listMessages($fromResolved, 1, 200);
                if (!empty($srcList['failed'])) {
                    $verified = false;
                    break;
                }
                $srcMids = [];
                foreach ($srcList['messages'] as $srcMsg) {
                    $m = mail_normalize_thread_id((string) ($srcMsg['message_id'] ?? ''));
                    if ($m !== '') {
                        $srcMids[$m] = true;
                    }
                }
                foreach ($opMessageIds as $mid) {
                    $mid = mail_normalize_thread_id((string) $mid);
                    if ($mid !== '' && isset($srcMids[$mid])) {
                        $stillInSource++;
                    }
                }
                // Re-mirror the source index to the live truth so the UI shows
                // exactly what the server has.
                try {
                    MailCacheService::syncFolderHeaders($verifyImap, $fromResolved);
                    MailCacheService::reconcileBadgeFromIndex($fromResolved);
                } catch (\Throwable $e) {
                    app_log('Verify source sync failed: ' . $e->getMessage());
                }
            }
        } elseif ($opMessageIds !== []) {
            $verified = false;
        }

        if ($repaired > 0 || $stillInSource === 0) {
            // Index the target so the list (and arrival ghost-kill) sees the moved
            // messages as real rows.
            try {
                if ($verifyImap->connect()) {
                    MailCacheService::resyncFolderAfterMove($verifyImap, $targetIdx, [], 50);
                    MailCacheService::reconcileBadgeFromIndex($targetIdx);
                }
            } catch (\Throwable $e) {
                app_log('Post-repair target sync failed: ' . $e->getMessage());
            }
        }
        releaseSessionLock();

        if (!$verified) {
            // Could not confirm against the live server (outage window): leave the
            // op PENDING — FilterService::resumePendingOps will re-verify and
            // finish or retry it within a couple of minutes.
            app_log('Move op ' . $opId . ' left pending: source unverifiable (mail server unreachable).');

            return;
        }

        $ok = $stillInSource === 0 && ($moved > 0 || $repaired > 0 || $requested === 0);
        mail_ops_journal_finish(
            $opId,
            $ok,
            $ok
                ? ($repaired > 0 ? sprintf('repaired %d half-moved', $repaired) : '')
                : sprintf('%d of %d moved; %d still in source (verified live)', $moved, $requested, $stillInSource)
        );
    }

    /**
     * Rescue spam-tagged messages into the inbox by rewriting them clean (strip the
     * ***SPAM*** subject + X-Spam headers) and dropping the tagged original, so the
     * host's own spam sieve stops re-junking them. Returns the uids NOT handled here
     * (not spam-tagged) for the normal move path.
     *
     * @param list<int> $uids
     * @param array<string, list<int>> $removed
     * @param list<int> $allMovedUids
     * @return list<int>
     */
    private function unspamRescueOnServer(
        string $fromPath,
        string $inboxPath,
        array $uids,
        array &$removed,
        array &$allMovedUids,
        int &$movedTotal,
    ): array {
        $imap = new ImapService();
        if (!$imap->connect()) {
            return $uids;
        }

        $resolvedInbox = FolderCache::resolvePath($inboxPath);
        $remaining = [];

        foreach ($uids as $uid) {
            $uid = (int) $uid;
            $headers = $imap->fetchFilterHeaders($fromPath, $uid);
            if ($headers === null || !mail_headers_indicate_spam($headers)) {
                $remaining[] = $uid;
                continue;
            }

            if (!mail_unspam_rescue_message($imap, $fromPath, $uid, $resolvedInbox)) {
                app_log('Un-spam rescue failed for uid ' . $uid . ': ' . $imap->getLastError());
                $remaining[] = $uid;
                continue;
            }

            // Trust this sender going forward: their future mail is auto-rescued
            // from Junk by FilterService (so it stops landing in Junk at all).
            mail_allowlist_add((string) ($headers['from'] ?? ''));

            MailCacheService::removeMessages($fromPath, [$uid]);
            $removed[$fromPath] = array_merge($removed[$fromPath] ?? [], [$uid]);
            $allMovedUids[] = $uid;
            $movedTotal++;
        }

        return $remaining;
    }

    /**
     * @param list<int> $uids
     * @return int messages actually moved on the server
     */
    private function executeMoveOnServer(
        string $folderPath,
        array $uids,
        string $targetPath,
        string $siblingMode = 'none',
        string $filterInbox = '',
        string $targetIndexPath = '',
    ): int {
        $uids = array_values(array_unique(array_filter(
            array_map('intval', $uids),
            static fn (int $u): bool => $u > 0
        )));

        if ($uids === []) {
            return 0;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            app_log('Background move failed: ' . $imap->getLastError());

            return 0;
        }

        $targetPath = ensure_employee_messages_folder_exists(FolderCache::resolvePath($targetPath));
        if ($targetPath === '') {
            ImapService::closeShared();

            return 0;
        }

        if (!$imap->folderExistsOnServer($targetPath) && !$imap->ensureFolderPath($targetPath)) {
            app_log('Background move: could not create target folder ' . $targetPath);
            ImapService::closeShared();

            return 0;
        }

        $movedTotal = 0;
        $removed = [];
        $allMovedUids = [];

        // Rescuing into the filter inbox: un-spam any server-tagged messages by
        // rewriting them without the ***SPAM*** subject prefix + X-Spam headers, so
        // the mail host's own spam sieve won't bounce them straight back to Junk.
        // Non-spam uids fall through to the normal move below.
        if ($filterInbox !== '' && strcasecmp(FolderCache::resolvePath($targetPath), FolderCache::resolvePath($filterInbox)) === 0) {
            $uids = $this->unspamRescueOnServer($folderPath, $targetPath, $uids, $removed, $allMovedUids, $movedTotal);
        }

        foreach (array_chunk($uids, 50) as $chunk) {
            $chunkMoved = false;
            $moveResult = ['moved' => 0, 'errors' => 0, 'removed' => []];

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $imap = new ImapService();
                if (!$imap->connect()) {
                    app_log('Background move chunk failed: ' . $imap->getLastError());
                    break;
                }

                $moveResult = $this->runMovesOnImap($imap, $folderPath, $chunk, $targetPath, $siblingMode);

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
                    // The attempt failed — ONLY now discard the connection so the
                    // retry gets a clean one. Closing after every attempt (incl.
                    // successful ones) forced a full TLS+LOGIN (~1-2s on this
                    // host) per chunk and per follow-up step of the same op.
                    ImapService::closeShared();
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
            return 0;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return $movedTotal;
        }

        $resolvedTarget = FolderCache::resolvePath($targetPath);
        $resolvedInbox = $filterInbox !== '' ? FolderCache::resolvePath($filterInbox) : '';
        if ($resolvedInbox !== '' && strcasecmp($resolvedTarget, $resolvedInbox) === 0 && $allMovedUids !== []) {
            try {
                FilterService::preserveManualInboxPlacement($imap, $resolvedTarget, $allMovedUids);
            } catch (\Throwable $e) {
                app_log('Manual inbox preserve failed: ' . $e->getMessage());
            }
        }

        if ($siblingMode === 'trash' && $allMovedUids !== []) {
            try {
                $this->handleSiblingCopies($imap, $folderPath, $allMovedUids, $targetPath, 'trash');
            } catch (\Throwable $e) {
                app_log('Trash sibling copies failed: ' . $e->getMessage());
            }
        }

        // Resync the target for EVERY destination — including Trash. Trash used to
        // be skipped here, which left its cached list stale after a delete: the
        // user saw "moved to Trash" but an empty Trash until the ~2-min cache TTL
        // expired. The resync also swaps the optimistic pending-arrival rows for
        // the real ones on the next poll.
        try {
            $syncPath = $targetIndexPath !== '' ? $targetIndexPath : $targetPath;
            MailCacheService::resyncFolderAfterMove($imap, $syncPath, $allMovedUids, 30);
            MailCacheService::reconcileBadgeFromIndex($syncPath);
        } catch (\Throwable $e) {
            app_log('Post-move cache sync failed for ' . $targetPath . ': ' . $e->getMessage());
            MailCacheService::invalidateFolder($targetPath);
        }

        try {
            // Full-depth sync (not a small limit): the truth-check that follows
            // resolves the op's Message-IDs against this index — a shallow sync
            // would hide older not-actually-moved messages from verification.
            MailCacheService::syncFolderHeaders($imap, $folderPath);
            MailCacheService::reconcileBadgeFromIndex($folderPath);
        } catch (\Throwable $e) {
            app_log('Post-move source sync failed for ' . $folderPath . ': ' . $e->getMessage());
        }

        // The badge/cache writes above re-acquired the session lock inside this
        // deferred (post-flush) work — release so parallel requests of the same
        // session stop queuing behind the move's verification tail.
        releaseSessionLock();

        return $movedTotal;
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

            $opId = mail_ops_journal_create('delete', [$resolvedFolderPath => array_values($uids)], null);

            json_response_then($this->appendCorrespondentFolderPrune($resolvedFolderPath, [
                'ok' => true,
                'deleted' => count($uids),
                'errors' => 0,
                'uids' => array_values($uids),
                'op_id' => $opId,
                'unread_counts' => $counts,
            ]), function () use ($resolvedFolderPath, $uids, $opId): void {
                $deleted = $this->executeDeleteOnServer($resolvedFolderPath, $uids);
                $requested = count(array_unique(array_filter(array_map('intval', $uids))));
                mail_ops_journal_finish(
                    $opId,
                    $deleted >= $requested,
                    $deleted >= $requested ? '' : sprintf('%d of %d deleted', $deleted, $requested)
                );
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
    /**
     * @return int messages actually deleted on the server
     */
    private function executeDeleteOnServer(string $folderPath, array $uids): int
    {
        $uids = array_values(array_unique(array_filter(
            array_map('intval', $uids),
            static fn (int $u): bool => $u > 0
        )));

        if ($uids === []) {
            return 0;
        }

        $deletedTotal = 0;
        foreach (array_chunk($uids, 100) as $chunk) {
            $imap = new ImapService();
            if (!$imap->connect()) {
                app_log('Background delete chunk failed: ' . $imap->getLastError());
                break;
            }

            $result = $imap->deleteMessages($folderPath, $chunk);
            $deletedUids = $result['uids'] ?? [];
            if ($deletedUids !== []) {
                $deletedTotal += count($deletedUids);
                mail_clear_removed_uids($folderPath, $deletedUids);
            } else {
                // Chunk failed — discard the connection so the next chunk gets a
                // clean one. Keeping it on success avoids a full TLS+LOGIN per chunk.
                ImapService::closeShared();
            }
        }

        return $deletedTotal;
    }

    /**
     * @return list<int>
     */
    private function resolveBulkUids(string $folderPath): array
    {
        $resolvedPath = FolderCache::resolvePath($folderPath);
        if ($resolvedPath === '') {
            return [];
        }

        $searchQuery = trim($_POST['q'] ?? '');

        if (($_POST['all_in_folder'] ?? '') === '1') {
            // Safety cap: with full-history indexing a folder can hold tens of
            // thousands of rows — an uncapped "all in folder" would attempt that
            // many IMAP operations. Server-side authoritative (the client hides
            // the affordance above the cap, but a forged POST must fail too).
            if ($searchQuery === '') {
                $cap = (int) (config('app')['mail_bulk_all_max'] ?? 500);
                $indexedCount = MailCacheService::countMessagesInIndex(
                    MailCacheService::indexFolderPath($resolvedPath)
                );
                if ($indexedCount > $cap) {
                    json_response([
                        'ok' => false,
                        'error' => 'This folder has too many messages for a bulk action (limit '
                            . $cap . '). Use search to narrow down, or act page by page.',
                    ], 422);
                }
            }

            // Refresh from the server FIRST. "Select all" is commonly used for a
            // destructive permanent-delete, and a stale cache (e.g. a Trash the
            // flaky host's deferred moves haven't caught up to) would silently
            // make it miss messages — the user saw "delete all" leave some behind.
            if ($searchQuery === '') {
                try {
                    $syncImap = new ImapService();
                    if ($syncImap->connect()) {
                        MailCacheService::syncFolderHeaders($syncImap, $resolvedPath);
                    }
                } catch (\Throwable $e) {
                    app_log('all_in_folder pre-sync failed: ' . $e->getMessage());
                }
            }

            $list = mail_visible_folder_list($resolvedPath, $searchQuery);
            $uids = [];
            foreach ($list['messages'] as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $uid = (int) ($msg['uid'] ?? 0);
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }

            if ($uids !== []) {
                return $this->locationsToUids(
                    $this->resolveBulkLocations($folderPath, $uids),
                );
            }

            $indexPath = MailCacheService::indexFolderPath($resolvedPath);
            $cached = MailCacheService::folderMessageUids($indexPath, $searchQuery);
            if ($cached !== []) {
                return $this->locationsToUids(
                    $this->resolveBulkLocations($folderPath, $cached),
                );
            }

            $imap = new ImapService();
            if (!$imap->connect()) {
                return [];
            }

            $imapUids = $imap->allMessageUids($resolvedPath, $searchQuery);

            return $this->locationsToUids(
                $this->resolveBulkLocations($folderPath, $imapUids),
            );
        }

        $uids = array_values(array_filter(
            array_map('intval', $_POST['uids'] ?? []),
            static fn (int $u): bool => $u > 0,
        ));
        if ($uids === []) {
            return [];
        }

        return $this->locationsToUids(
            $this->resolveBulkLocations($folderPath, $uids),
        );
    }

    /**
     * UID → folder map for bulk actions (POST map + visible list list_folder hints).
     *
     * @param list<int> $uids
     * @return array<int, string>
     */
    private function buildBulkUidFolderMap(string $folderPath, array $uids = []): array
    {
        $resolvedPath = FolderCache::resolvePath($folderPath);
        if ($resolvedPath === '') {
            return [];
        }

        $map = $this->parseUidFolderMap($_POST['uid_folders'] ?? []);
        $allInFolder = ($_POST['all_in_folder'] ?? '') === '1';
        $uidLookup = $allInFolder ? null : array_fill_keys($uids, true);
        if ($map === [] && $uidLookup === null && $uids === []) {
            return [];
        }

        $list = mail_visible_folder_list($resolvedPath, trim($_POST['q'] ?? ''));
        foreach ($list['messages'] as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $uid = (int) ($msg['uid'] ?? 0);
            if ($uid <= 0 || isset($map[$uid])) {
                continue;
            }
            if ($uidLookup !== null && !isset($uidLookup[$uid])) {
                continue;
            }

            $rowFolder = (string) ($msg['list_folder'] ?? $resolvedPath);
            if ($rowFolder === '') {
                continue;
            }
            $map[$uid] = encode_folder_path($rowFolder);
        }

        return $map;
    }

    /**
     * @param array<int|string, string> $raw
     * @return array<int, string>
     */
    private function parseUidFolderMap(array $raw): array
    {
        $map = [];
        foreach ($raw as $uid => $folder) {
            $uid = (int) $uid;
            $folder = trim((string) $folder);
            if ($uid > 0 && $folder !== '') {
                $map[$uid] = $folder;
            }
        }

        return $map;
    }

    /**
     * @param list<array{folder_path: string, imap_uid: int}> $locations
     * @return list<int>
     */
    private function locationsToUids(array $locations): array
    {
        $uids = [];
        foreach ($locations as $location) {
            $uid = (int) ($location['imap_uid'] ?? 0);
            if ($uid > 0) {
                $uids[] = $uid;
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * @param list<int> $uids
     * @return list<array{folder_path: string, imap_uid: int}>
     */
    private function resolveBulkLocations(string $folderPath, array $uids): array
    {
        $resolvedPath = FolderCache::resolvePath($folderPath);
        if ($resolvedPath === '' || $uids === []) {
            return [];
        }

        return mail_resolve_bulk_message_locations(
            $resolvedPath,
            $uids,
            $this->buildBulkUidFolderMap($folderPath, $uids),
        );
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

        // Plain per-folder model: set the message's IMAP \Seen + mirror it in the
        // index, then recompute this folder's badge. No per-user read, no
        // thread/correspondent cross-folder marking. clearBackfilled: a human
        // explicitly touched this message, so it counts in badges again even if
        // it came from the history backfill.
        MailCacheService::updateIndexSeen($folderPath, $uid, $seen, clearBackfilled: true);
        MailCacheService::reconcileBadgeFromIndex($folderPath);
        $counts = FolderCache::sidebarUnreadCountsFromSession();

        if (wants_json()) {
            json_response_then([
                'ok' => true,
                'seen' => $seen,
                'uid' => $uid,
                'unread_counts' => $counts,
                'folder_unread' => mail_folder_unread_count($counts, $folderPath),
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

        // Plain per-folder model: mirror IMAP \Seen in the index for all uids,
        // recompute the badge, then apply on IMAP.
        MailCacheService::updateIndexSeenBulk($folderPath, $uids, $seen, clearBackfilled: true);
        MailCacheService::reconcileBadgeFromIndex($folderPath);
        $counts = FolderCache::sidebarUnreadCountsFromSession();

        if (wants_json()) {
            json_response_then([
                'ok' => true,
                'seen' => $seen,
                'uids' => $uids,
                'unread_counts' => $counts,
                'folder_unread' => mail_folder_unread_count($counts, $folderPath),
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
        return folder_display_name($folders, $path);
    }

    private function renderMailView(string $viewName, array $data): void
    {
        if (!isset($data['unreadCounts'])) {
            $data['unreadCounts'] = FolderCache::load(skipUnreadRefresh: true)['unread_counts'] ?? [];
        }

        $data['user'] = Auth::user();
        $data['authUser'] = Auth::user();
        // Consuming a flash unsets it — that write needs the session open, or
        // the unset is discarded and the flash re-displays on the next page.
        ensure_session_writable();
        $data['success'] = flash('success');
        $data['error'] = flash('error');
        $data['prefs'] = user_preferences();
        // Don't hold the lock through the multi-second sidebar/list render.
        releaseSessionLock();

        view($viewName, $data);
    }
}
