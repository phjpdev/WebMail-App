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
use App\Services\SmtpService;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

class ComposeController
{
    private const DRAFT_KEY = '_compose_draft';
    private const FORWARD_KEY = '_forward_attachments';
    private const MAX_ATTACHMENTS = 5;
    private const MAX_ATTACHMENT_BYTES = 10485760;

    public function compose(): void
    {
        requireAuth();
        releaseSessionLock();
        $this->showForm('compose', $this->defaultComposeData());
    }

    public function reply(): void
    {
        requireAuth();
        releaseSessionLock();
        $this->loadMessageForm('reply');
    }

    public function replyAll(): void
    {
        requireAuth();
        releaseSessionLock();
        $this->loadMessageForm('reply-all');
    }

    public function forward(): void
    {
        requireAuth();
        releaseSessionLock();
        $this->loadMessageForm('forward');
    }

    public function saveDraft(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();

        $fromEmail = (new AliasService())->resolveAllowedFrom(
            trim($_POST['from_email'] ?? ''),
            Auth::user()['id'] ?? null
        );
        $to = trim($_POST['to'] ?? '');
        $cc = trim($_POST['cc'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $bodyHtml = trim($_POST['body_html'] ?? '');

        try {
            $draftContent = $this->buildDraftMessage($fromEmail, $to, $cc, $subject, $body, $bodyHtml);
        } catch (\Throwable $e) {
            $this->composeFail('Could not save draft: ' . $e->getMessage());
        }

        $raw = $draftContent['raw'];

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->composeFail('Could not save draft: ' . $imap->getLastError());
        }

        $draftFolder = $this->resolveDraftsFolder();
        $existingDraftUid = (int) ($_POST['draft_uid'] ?? 0);
        $existingDraftFolder = decode_folder_path($_POST['draft_folder'] ?? '');
        if ($existingDraftFolder !== '' && !FolderCache::canAccess($existingDraftFolder)) {
            $existingDraftFolder = '';
            $existingDraftUid = 0;
        }
        if ($existingDraftUid > 0 && $existingDraftFolder === '') {
            $existingDraftFolder = $draftFolder;
        }

        if (!$imap->appendMessage($draftFolder, $raw)) {
            $this->composeFail('Could not save draft: ' . $imap->getLastError());
        }

        $isReplacement = $existingDraftUid > 0 && $existingDraftFolder !== '';
        if ($isReplacement) {
            if ($imap->moveMessage($existingDraftFolder, $existingDraftUid, trash_folder_path())) {
                MailCacheService::removeMessage($existingDraftFolder, $existingDraftUid);
            }
        }

        $headerLimit = (int) (config('app')['mail_cache_post_send_limit'] ?? 30);
        MailCacheService::invalidateFolder($draftFolder);
        try {
            MailCacheService::syncFolderHeaders($imap, $draftFolder, $headerLimit);
        } catch (\Throwable $e) {
            app_log('Post-draft cache sync failed: ' . $e->getMessage());
        }

        $this->cacheSavedDraftBody($imap, $draftFolder, $fromEmail, $to, $cc, $subject, $draftContent);

        if (!$isReplacement) {
            FolderCache::bumpUnread($draftFolder, 1);
        }
        MailCacheService::reconcileBadgeFromIndex($draftFolder);
        FolderCache::refreshPaths([$draftFolder]);
        $unreadCounts = FolderCache::sidebarUnreadCounts();

        $savedDraftUid = $this->resolveLatestDraftUid($imap, $draftFolder);

        if (wants_json()) {
            json_response([
                'ok' => true,
                'message' => 'Draft saved.',
                'draft_folder' => encode_folder_path($draftFolder),
                'draft_uid' => $savedDraftUid > 0 ? $savedDraftUid : null,
                'unread_counts' => $unreadCounts,
            ]);
        }
        flash('success', 'Draft saved.');

        redirect('compose');
    }

    public function send(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();

        $mode = $_POST['mode'] ?? 'compose';
        $to = trim($_POST['to'] ?? '');
        $cc = trim($_POST['cc'] ?? '');
        $bcc = trim($_POST['bcc'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $bodyHtml = trim($_POST['body_html'] ?? '');
        $fromEmail = (new AliasService())->resolveAllowedFrom(
            trim($_POST['from_email'] ?? ''),
            Auth::user()['id'] ?? null
        );
        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        $returnFolder = decode_folder_path($_POST['return_folder'] ?? '');
        $draftUid = (int) ($_POST['draft_uid'] ?? 0);

        if ($folderPath !== '') {
            assert_folder_access($folderPath);
        }

        $body = $this->appendSignature($body);
        if ($bodyHtml !== '') {
            $bodyHtml = $this->appendSignatureHtml($bodyHtml);
        }

        $draft = compact('to', 'cc', 'bcc', 'subject', 'body', 'fromEmail', 'mode', 'folderPath', 'uid');
        $draft['body_html'] = $bodyHtml;

        if ($subject === '' || ($body === '' && $bodyHtml === '')) {
            $this->composeFail('Subject and body are required.', $draft, $mode, $folderPath, $uid);
        }

        $toParsed = parse_email_list($to);
        if ($toParsed['valid'] === []) {
            $this->composeFail('At least one valid To address is required.', $draft, $mode, $folderPath, $uid);
        }

        foreach ([['Cc', $cc], ['Bcc', $bcc]] as [$label, $value]) {
            $parsed = parse_email_list($value);
            if ($parsed['invalid'] !== []) {
                $this->composeFail("Invalid {$label} address: " . implode(', ', $parsed['invalid']), $draft, $mode, $folderPath, $uid);
            }
        }

        $aliasService = new AliasService();
        if (!$this->isValidFrom($aliasService, $fromEmail)) {
            $this->composeFail('Invalid send-as address.', $draft, $mode, $folderPath, $uid);
        }

        $plainBody = $body !== '' ? $body : strip_tags($bodyHtml);
        $options = [
            'to' => implode(', ', $toParsed['valid']),
            'subject' => $subject,
            'body' => $plainBody,
            'from' => $fromEmail,
            'from_name' => $aliasService->getDisplayName($fromEmail),
        ];

        $ccParsed = parse_email_list($cc);
        if ($ccParsed['valid'] !== []) {
            $options['cc'] = implode(', ', $ccParsed['valid']);
        }

        $bccParsed = parse_email_list($bcc);
        if ($bccParsed['valid'] !== []) {
            $options['bcc'] = implode(', ', $bccParsed['valid']);
        }

        $toHeader = implode(', ', $toParsed['valid']);
        $ccHeader = $ccParsed['valid'] !== [] ? implode(', ', $ccParsed['valid']) : '';
        $bccHeader = $bccParsed['valid'] !== [] ? implode(', ', $bccParsed['valid']) : '';

        if ($bodyHtml !== '') {
            $options['html_body'] = HtmlSanitizer::sanitize($bodyHtml);
        }

        if (in_array($mode, ['reply', 'reply-all'], true) && $folderPath !== '' && $uid > 0) {
            $this->attachReplyHeaders($options, $folderPath, $uid);
        }

        $attachments = $this->collectAttachments();
        if ($attachments['error'] !== null) {
            $this->composeFail($attachments['error'], $draft, $mode, $folderPath, $uid);
        }
        if ($attachments['files'] !== []) {
            $options['attachments'] = $attachments['files'];
        }

        // Re-attach the original attachments when forwarding.
        if ($mode === 'forward') {
            $forwarded = $this->collectForwardAttachments();
            if ($forwarded['error'] !== null) {
                $this->composeFail($forwarded['error'], $draft, $mode, $folderPath, $uid);
            }
            if ($forwarded['files'] !== []) {
                $options['raw_attachments'] = $forwarded['files'];
            }
        }

        $smtp = new SmtpService();
        if ($smtp->send($options)) {
            $sentFolder = $this->resolveSentFolderFast();
            $sentMime = $smtp->getLastMime();
            $contextFolder = compose_context_folder($returnFolder, $folderPath, $fromEmail);
            // Sent-only model: the SMTP server delivers to recipients and the
            // filter engine routes inbound copies to their real folders, so we no
            // longer predict destinations or copy the message into them.
            $destPaths = [];
            $sentMessageId = extract_message_id_from_mime($sentMime);

            $threadReply = null;
            if (
                in_array($mode, ['reply', 'reply-all'], true)
                && $folderPath !== ''
                && $uid > 0
            ) {
                $split = compose_split_reply_body($plainBody);
                $composeHtml = '';
                if ($bodyHtml !== '') {
                    $htmlSplit = mail_split_html_quote(HtmlSanitizer::sanitize($bodyHtml));
                    $composeHtml = $htmlSplit['visible'];
                }
                $replyAttachments = mail_persist_thread_reply_attachments(
                    $folderPath,
                    $uid,
                    $attachments['files'] ?? [],
                );
                $threadReply = [
                    'folder_path' => $folderPath,
                    'uid' => $uid,
                    'reply' => [
                        'from' => $fromEmail,
                        'to' => $options['to'],
                        'cc' => $options['cc'] ?? '',
                        'date' => date('r'),
                        'body' => mail_unquote_plain($split['compose']),
                        'body_html' => $composeHtml,
                        'attachments' => $replyAttachments,
                    ],
                ];
            }

            $snippet = mail_conversation_snippet($plainBody);
            with_session_write(function () use (
                $fromEmail,
                $contextFolder,
                $destPaths,
                $toHeader,
                $ccHeader,
                $bccHeader,
                $subject,
                $sentMessageId,
                $snippet,
                $threadReply,
            ): void {
                mail_apply_post_send_session_state(
                    $fromEmail,
                    $contextFolder,
                    $destPaths,
                    $toHeader,
                    $ccHeader,
                    $bccHeader,
                    $subject,
                    $sentMessageId,
                    $snippet,
                    $threadReply,
                );
            });

            $postSendToken = $this->enqueuePostSendSync(
                $fromEmail,
                $returnFolder,
                $folderPath,
                $sentFolder,
                $destPaths,
                $sentMime,
                $sentMessageId,
                $mode,
                $uid,
                decode_folder_path($_POST['draft_folder'] ?? ''),
                (int) ($_POST['draft_uid'] ?? 0),
                $threadReply,
                true,
            );

            if (wants_json()) {
                // No optimistic unread_counts / list previews: the client polls
                // folders/unread for server-truth badges. Keep the reply-thread and
                // correspondent-sidebar payload (features, not badge predictions).
                $jsonPayload = [
                    'ok' => true,
                    'message' => 'Email sent successfully.',
                    'return_folder' => $contextFolder !== '' ? encode_folder_path($contextFolder) : '',
                    'draft_uid' => $draftUid > 0 ? $draftUid : null,
                    'post_send_token' => $postSendToken,
                    'reply_uid' => $threadReply !== null ? (int) $threadReply['uid'] : null,
                    'reply_folder' => $threadReply !== null
                        ? encode_folder_path((string) $threadReply['folder_path'])
                        : '',
                    'thread_preview' => $threadReply !== null
                        ? mail_conversation_snippet((string) ($threadReply['reply']['body'] ?? ''))
                        : null,
                ];
                if (($user = Auth::user()) !== null && ($user['role'] ?? '') === 'employee') {
                    $jsonPayload['correspondent_folders'] = mail_correspondent_folders_sidebar_payload(
                        (int) ($user['id'] ?? 0)
                    );
                }
                if ($threadReply !== null) {
                    $jsonPayload['reply_date'] = (string) ($threadReply['reply']['date'] ?? '');
                    $jsonPayload['thread_subject'] = $subject;
                }
                dispatch_post_send($postSendToken);
                releaseSessionLock();
                json_response($jsonPayload);
            } else {
                dispatch_post_send($postSendToken);
            }

            flash('success', 'Email sent successfully.');
            $redirectFolder = $contextFolder !== '' ? $contextFolder : $sentFolder;
            redirect('folder/' . encode_folder_path($redirectFolder));
        }

        $this->composeFail($this->friendlySendError($smtp->getLastError()), $draft, $mode, $folderPath, $uid);
    }

    /**
     * Background post-send sync (separate HTTP request so compose/send returns fast).
     */
    public function postSendDeferred(): void
    {
        requireAuth();

        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            http_response_code(400);
            exit;
        }

        json_response_then(['ok' => true, 'accepted' => true], function () use ($token): void {
            ignore_user_abort(true);
            @set_time_limit(120);
            $this->runPostSendJobByToken($token);
        });
    }

    public function runPostSendJobByToken(string $token): void
    {
        $job = $this->claimPostSendJob($token);
        if ($job === null) {
            return;
        }

        $processingPath = $this->postSendProcessingPath($token);

        try {
            $this->runPostSendWork($job);
            if (is_file($processingPath)) {
                @unlink($processingPath);
            }
        } catch (\Throwable $e) {
            app_log('Post-send job failed: ' . $e->getMessage());
            if (is_file($processingPath)) {
                @rename($processingPath, $this->postSendJobPath($token));
            }
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return array{context_folder: string, sent_folder: string, unread_counts: array<string, int>}
     */
    private function runPostSendWork(array $job): array
    {
        if (empty($job['session_hints_applied'])) {
            $this->applyPostSendSessionHints($job);
        }

        $threadReply = is_array($job['thread_reply'] ?? null) ? $job['thread_reply'] : null;
        if ($threadReply !== null && empty($job['session_hints_applied'])) {
            with_session_write(function () use ($threadReply): void {
                mail_store_thread_reply(
                    (string) ($threadReply['folder_path'] ?? ''),
                    (int) ($threadReply['uid'] ?? 0),
                    is_array($threadReply['reply'] ?? null) ? $threadReply['reply'] : [],
                );
            });
        }

        $mimePath = (string) ($job['mime_path'] ?? '');
        $sentMime = ($mimePath !== '' && is_file($mimePath)) ? (string) file_get_contents($mimePath) : '';
        if ($mimePath !== '') {
            @unlink($mimePath);
        }

        // The whole post-send job runs after json_response_then() has flushed the
        // response and released the session lock. Every phase below does slow IMAP
        // and ends with a badge write that re-acquires the lock, so we release
        // after each phase — otherwise the lock is held unbroken for the whole job
        // and concurrent sidebar polls / folder loads serialise behind it (a ~30s
        // UI freeze). Session data written here is authoritative on the next read.
        $sentFolder = (string) ($job['sent_folder'] ?? '');
        $this->saveToSent($sentFolder, $sentMime);
        releaseSessionLock();
        $this->deleteSourceDraft(
            (string) ($job['draft_folder'] ?? ''),
            (int) ($job['draft_uid'] ?? 0),
        );
        releaseSessionLock();
        $destPaths = is_array($job['dest_paths'] ?? null) ? $job['dest_paths'] : [];
        $fromEmail = (string) ($job['from_email'] ?? '');
        $sentMessageId = isset($job['sent_message_id']) ? (string) $job['sent_message_id'] : null;

        $syncResult = $this->syncMailboxAfterSend(
            $fromEmail,
            (string) ($job['return_folder'] ?? ''),
            (string) ($job['folder_path'] ?? ''),
            $sentFolder,
            $destPaths,
            $sentMime,
            (string) ($job['mode'] ?? ''),
            (int) ($job['uid'] ?? 0),
        );

        with_session_write(function (): void {
            unset($_SESSION['_post_send_at'], $_SESSION['_post_send_message_id']);
            mail_clear_admin_outbound_badge_suppression();
        });

        return $syncResult;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimPostSendJob(string $token): ?array
    {
        $jobPath = $this->postSendJobPath($token);
        $processingPath = $this->postSendProcessingPath($token);
        $staleAfter = 300;

        if (!is_file($jobPath)) {
            if (!is_file($processingPath)) {
                return null;
            }
            $mtime = @filemtime($processingPath);
            if ($mtime === false || (time() - $mtime) < $staleAfter) {
                return null;
            }
            @rename($processingPath, $jobPath);
        }

        if (!is_file($jobPath)) {
            return null;
        }

        if (!@rename($jobPath, $processingPath)) {
            return null;
        }

        $raw = file_get_contents($processingPath);
        if ($raw === false || $raw === '') {
            @unlink($processingPath);

            return null;
        }

        $job = json_decode($raw, true);

        return is_array($job) ? $job : null;
    }

    private function postSendJobPath(string $token): string
    {
        return base_path('storage/post_send/' . $token . '.json');
    }

    private function postSendProcessingPath(string $token): string
    {
        return base_path('storage/post_send/' . $token . '.processing');
    }

    /**
     * @param array<string, mixed> $job
     */
    private function applyPostSendSessionHints(array $job): void
    {
        $destPaths = is_array($job['dest_paths'] ?? null) ? $job['dest_paths'] : [];
        $sentMessageId = isset($job['sent_message_id']) ? (string) $job['sent_message_id'] : null;
        $fromEmail = (string) ($job['from_email'] ?? '');

        with_session_write(function () use ($destPaths, $sentMessageId, $fromEmail): void {
            unset($_SESSION[self::DRAFT_KEY], $_SESSION[self::FORWARD_KEY]);
            $_SESSION['_post_send_at'] = time();
            unset($_SESSION['_after_send_filter_ran']);
            $_SESSION['_post_send_dest_paths'] = $destPaths;
            $_SESSION['_post_send_from'] = $fromEmail;
            $_SESSION['_post_send_message_id'] = $sentMessageId;

            // Correspondent-folder sidebar notes only — no optimistic badge
            // suppression; syncMailboxAfterSend recomputes badges from server truth.
            foreach ($destPaths as $path) {
                mail_note_employee_correspondent((string) $path);
            }

            if ($destPaths !== [] && $sentMessageId !== null && $sentMessageId !== '') {
                FolderCache::queuePendingFilterRoute($sentMessageId, $destPaths);
            }
        });
    }

    /**
     * @param list<string> $destPaths
     */
    private function enqueuePostSendSync(
        string $fromEmail,
        string $returnFolder,
        string $folderPath,
        string $sentFolder,
        array $destPaths,
        string $sentMime,
        ?string $sentMessageId,
        string $mode,
        int $uid,
        string $draftFolder,
        int $draftUid,
        ?array $threadReply = null,
        bool $sessionHintsApplied = false,
    ): string {
        $token = bin2hex(random_bytes(16));
        $dir = base_path('storage/post_send');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $cutoff = time() - 3600;
        foreach (glob($dir . '/*.json') ?: [] as $oldJobPath) {
            $mtime = @filemtime($oldJobPath);
            if ($mtime !== false && $mtime < $cutoff) {
                $old = json_decode((string) file_get_contents($oldJobPath), true);
                if (is_array($old)) {
                    @unlink((string) ($old['mime_path'] ?? ''));
                }
                @unlink($oldJobPath);
            }
        }

        $mimePath = $dir . '/' . $token . '.mime';
        file_put_contents($mimePath, $sentMime);

        $job = [
            'created' => time(),
            'from_email' => $fromEmail,
            'return_folder' => $returnFolder,
            'folder_path' => $folderPath,
            'sent_folder' => $sentFolder,
            'dest_paths' => $destPaths,
            'sent_message_id' => $sentMessageId,
            'mime_path' => $mimePath,
            'mode' => $mode,
            'uid' => $uid,
            'draft_folder' => $draftFolder,
            'draft_uid' => $draftUid,
            'thread_reply' => $threadReply,
            'session_hints_applied' => $sessionHintsApplied,
            'session_id' => post_send_session_id(),
        ];

        file_put_contents($dir . '/' . $token . '.json', json_encode_safe($job));

        return $token;
    }

    /**
     * Translate raw SMTP errors into actionable, user-friendly messages. The
     * underlying error is still logged by SmtpService for diagnostics.
     */
    private function friendlySendError(string $error): string
    {
        $lower = strtolower($error);

        if (str_contains($lower, 'spam') || str_contains($lower, '550')) {
            return 'The mail server rejected this message as spam. Try editing the subject and message text (remove spammy wording or quoted spam), then send again.';
        }

        if (str_contains($lower, 'authenticat') || str_contains($lower, '535')) {
            return 'Could not sign in to the mail server. Please check the mailbox credentials in the server settings.';
        }

        if (
            str_contains($lower, 'connect')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
        ) {
            return 'Could not reach the mail server. Please check your connection and try again.';
        }

        if (str_contains($lower, 'recipient') || str_contains($lower, '5.1.1') || str_contains($lower, '550 5.1.1')) {
            return 'The recipient address was rejected by the mail server. Please check the To/Cc addresses.';
        }

        return 'Failed to send email. ' . ($error !== '' ? 'Mail server said: ' . $error : 'Please try again.');
    }

    private function loadMessageForm(string $mode): void
    {
        $folderPath = mail_folder_path((string) ($_GET['folder'] ?? ''));
        $uid = (int) ($_GET['uid'] ?? 0);

        $draft = $this->peekDraft();
        if ($draft !== null && ($draft['mode'] ?? '') === $mode) {
            $this->showForm($mode, $draft);
            return;
        }

        if ($folderPath === '' || $uid <= 0) {
            flash('error', 'Message not specified.');
            redirect('folder/' . encode_folder_path('INBOX'));
        }
        assert_folder_access($folderPath);

        $message = MailCacheService::getBody($folderPath, $uid);
        if ($message === null) {
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
            MailCacheService::saveBody($folderPath, $message);
        }

        $privacyEmails = employee_correspondent_privacy_emails($folderPath);
        if ($privacyEmails !== null && !mail_message_involves_user($message, $privacyEmails)) {
            flash('error', 'Message not found.');
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $aliasService = new AliasService();
        $quoted = mail_build_reply_quoted_body($message, $folderPath);
        $replyFrom = mail_resolve_reply_from($message, $folderPath);
        $replyTo = mail_resolve_reply_to($message, $folderPath);

        if ($mode === 'reply') {
            $subject = $this->replySubject($message['subject'] ?? '');
            $this->showForm('reply', [
                'to' => $replyTo,
                'cc' => '',
                'bcc' => '',
                'subject' => $subject,
                'body' => "\n\n" . $quoted,
                'from_email' => $replyFrom,
                'folderPath' => $folderPath,
                'uid' => $uid,
            ]);
            return;
        }

        if ($mode === 'reply-all') {
            $subject = $this->replySubject($message['subject'] ?? '');
            $cc = $this->buildReplyAllCc($message, $replyFrom, $folderPath, $replyTo);
            $this->showForm('reply-all', [
                'to' => $replyTo,
                'cc' => $cc,
                'bcc' => '',
                'subject' => $subject,
                'body' => "\n\n" . $quoted,
                'from_email' => $replyFrom,
                'folderPath' => $folderPath,
                'uid' => $uid,
            ]);
            return;
        }

        $subject = $this->cleanSubject($message['subject'] ?? '');
        if (!preg_match('/^Fwd:\s/i', $subject)) {
            $subject = 'Fwd: ' . $subject;
        }

        $forwardHeader = sprintf(
            "---------- Forwarded message ----------\nFrom: %s\nDate: %s\nSubject: %s\nTo: %s\n\n",
            $message['from'] ?? '',
            $message['date'] ?? '',
            $message['subject'] ?? '',
            $message['to'] ?? ''
        );

        // Remember the original message's attachments so they can be re-attached
        // when the forward is actually sent.
        $this->rememberForwardAttachments($folderPath, $uid, $message['attachments'] ?? []);

        $this->showForm('forward', [
            'to' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => $subject,
            'body' => $forwardHeader . ($message['plain'] ?? strip_tags($message['html'] ?? '')),
            'from_email' => $replyFrom,
            'folderPath' => $folderPath,
            'uid' => $uid,
        ]);
    }

    public function editDraft(): void
    {
        requireAuth();
        releaseSessionLock();

        $folderPath = decode_folder_path($_GET['folder'] ?? '');
        $uid = (int) ($_GET['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            flash('error', 'Draft not specified.');
            redirect('compose');
        }
        assert_folder_access($folderPath);

        $defaults = compose_draft_form_context($folderPath, $uid);
        if ($defaults === null) {
            flash('error', 'Draft not found.');
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $this->showForm('edit-draft', $defaults);
    }

    /**
     * @param list<array{id: string, filename: string, size: int, mime: string}> $attachments
     */
    private function rememberForwardAttachments(string $folderPath, int $uid, array $attachments): void
    {
        if ($attachments === []) {
            unset($_SESSION[self::FORWARD_KEY]);

            return;
        }

        $_SESSION[self::FORWARD_KEY] = [
            'folder' => $folderPath,
            'uid' => $uid,
            'parts' => array_map(fn ($a) => [
                'id' => $a['id'],
                'filename' => $a['filename'],
                'mime' => $a['mime'],
            ], $attachments),
        ];
    }

    /**
     * Fetch any remembered forwarded attachments from IMAP for re-sending.
     *
     * @return array{files: list<array{content: string, name: string, mime: string}>, error: string|null}
     */
    private function collectForwardAttachments(): array
    {
        $ref = $this->resolveForwardAttachmentRef();
        if ($ref === null || empty($ref['parts'])) {
            return ['files' => [], 'error' => null];
        }

        $expected = count($ref['parts']);
        $imap = new ImapService();
        if (!$imap->connect()) {
            return [
                'files' => [],
                'error' => 'Could not connect to mail server to attach forwarded files. Please try again.',
            ];
        }

        $out = [];
        foreach ($ref['parts'] as $part) {
            $attachment = $imap->getAttachment($ref['folder'], (int) $ref['uid'], (string) $part['id']);
            if ($attachment === null) {
                continue;
            }
            $out[] = [
                'content' => $attachment['content'],
                'name' => $part['filename'] ?? ($attachment['filename'] ?? 'attachment'),
                'mime' => $part['mime'] ?? ($attachment['mime'] ?? 'application/octet-stream'),
            ];
        }

        if (count($out) < $expected) {
            return [
                'files' => [],
                'error' => 'One or more forwarded attachments could not be loaded. Re-open the message and try forwarding again.',
            ];
        }

        return ['files' => $out, 'error' => null];
    }

    /**
     * @return array{folder: string, uid: int, parts: list<array{id: string, filename?: string, mime?: string}>}|null
     */
    private function resolveForwardAttachmentRef(): ?array
    {
        $ref = $_SESSION[self::FORWARD_KEY] ?? null;
        if (is_array($ref) && !empty($ref['parts'])) {
            return [
                'folder' => (string) ($ref['folder'] ?? ''),
                'uid' => (int) ($ref['uid'] ?? 0),
                'parts' => $ref['parts'],
            ];
        }

        $folder = decode_folder_path((string) ($_POST['forward_folder'] ?? ''));
        $uid = (int) ($_POST['forward_uid'] ?? 0);
        $partsRaw = trim((string) ($_POST['forward_parts'] ?? ''));
        if ($folder === '' || $uid <= 0 || $partsRaw === '') {
            return null;
        }

        $parts = json_decode($partsRaw, true);
        if (!is_array($parts) || $parts === []) {
            return null;
        }

        return [
            'folder' => $folder,
            'uid' => $uid,
            'parts' => $parts,
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function buildReplyAllCc(array $message, string $replyFrom, string $folderPath = '', string $replyTo = ''): string
    {
        $aliasService = new AliasService();
        $ownEmails = array_map(
            fn ($a) => strtolower($a['email']),
            $aliasService->listActive()
        );
        $ownEmails[] = strtolower(config('mail')['mailbox_email']);
        $ownEmails[] = strtolower($replyFrom);

        $replyToEmail = strtolower(normalize_email_token($replyTo));
        if ($replyToEmail !== '') {
            $ownEmails[] = $replyToEmail;
        }

        $recipients = [];
        foreach (parse_email_list(($message['to'] ?? '') . ',' . ($message['cc'] ?? ''))['valid'] as $email) {
            if (!in_array(strtolower($email), $ownEmails, true)) {
                $recipients[] = $email;
            }
        }

        return implode(', ', array_unique($recipients));
    }

    private function replySubject(string $subject): string
    {
        $subject = $this->cleanSubject($subject);

        return preg_match('/^Re:\s/i', $subject) ? $subject : 'Re: ' . $subject;
    }

    /**
     * Strip spam/scanner tags the mail server prepends to inbound subjects
     * (e.g. "***SPAM***", "[SPAM]", "[BULK]"). Carrying these into a reply or
     * forward subject causes the outbound spam filter to reject the message
     * with a 550 ("classified as SPAM"), so we remove them before composing.
     */
    private function cleanSubject(string $subject): string
    {
        $subject = trim($subject);

        do {
            $before = $subject;
            $subject = preg_replace(
                '/^\s*(\*{2,}\s*[A-Z]+\s*\*{2,}|\[(?:SPAM|BULK|SUSPECTED SPAM|VIRUS)\])\s*/i',
                '',
                $subject
            ) ?? $subject;
            $subject = trim($subject);
        } while ($subject !== $before);

        return $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultComposeData(): array
    {
        return [
            'to' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => '',
            'body' => '',
            'body_html' => '',
            'from_email' => (new AliasService())->userAlias(Auth::user()['id'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function showForm(string $mode, array $defaults): void
    {
        $draft = $this->pullDraft();
        if ($draft !== null && ($draft['mode'] ?? '') === $mode) {
            $defaults = array_merge($defaults, $draft);
        }

        $aliasService = new AliasService();
        $sessionUser = Auth::user();
        $folderData = $this->isEmbedRequest()
            ? ['folders' => [], 'unread_counts' => []]
            : FolderCache::load(skipUnreadRefresh: true);
        $returnFolder = decode_folder_path($_GET['return_folder'] ?? '');
        if ($returnFolder === '') {
            $returnFolder = compose_context_folder(
                '',
                (string) ($defaults['folderPath'] ?? ''),
                (string) ($defaults['from_email'] ?? '')
            );
        }

        $composeAliases = compose_send_as_aliases(
            (string) ($defaults['from_email'] ?? ''),
            $aliasService->listForCompose($sessionUser)
        );
        $sendAsFixed = $sessionUser !== null && ($sessionUser['role'] ?? '') === 'employee';
        if ($sendAsFixed) {
            $defaults['from_email'] = $aliasService->userAlias((int) ($sessionUser['id'] ?? 0));
            $composeAliases = compose_send_as_aliases($defaults['from_email'], $composeAliases);
        } elseif (($defaults['from_email'] ?? '') === '' && $composeAliases !== []) {
            $defaults['from_email'] = $composeAliases[0]['email'];
        }

        $viewData = array_merge([
            'title' => ucfirst(str_replace('-', ' ', $mode)),
            'mode' => $mode,
            'user' => $sessionUser,
            'aliases' => $composeAliases,
            'send_as_fixed' => $sendAsFixed,
            'to' => $defaults['to'] ?? '',
            'cc' => $defaults['cc'] ?? '',
            'bcc' => $defaults['bcc'] ?? '',
            'subject' => $defaults['subject'] ?? '',
            'body' => $defaults['body'] ?? '',
            'body_html' => $defaults['body_html'] ?? '',
            'from_email' => $defaults['from_email'] ?? config('mail')['mailbox_email'],
            'folderPath' => $defaults['folderPath'] ?? '',
            'uid' => $defaults['uid'] ?? 0,
            'draftFolder' => $defaults['draftFolder'] ?? '',
            'draftUid' => $defaults['draftUid'] ?? 0,
            'returnFolder' => $returnFolder !== '' ? encode_folder_path($returnFolder) : '',
            'forwardedAttachments' => ($mode === 'forward' && isset($_SESSION[self::FORWARD_KEY]['parts']))
                ? $_SESSION[self::FORWARD_KEY]['parts']
                : [],
            'folders' => $folderData['folders'],
            'unreadCounts' => $folderData['unread_counts'] ?? [],
            'activeFolder' => null,
            'success' => flash('success'),
            'error' => flash('error'),
            'embed' => $this->isEmbedRequest(),
        ], [
            'authUser' => Auth::user(),
            'prefs' => user_preferences(),
        ]);

        if ($this->isEmbedRequest()) {
            send_csrf_header();
            view('mail/compose-embed', $viewData);

            return;
        }

        view('mail/compose', $viewData);
    }

    private function isEmbedRequest(): bool
    {
        return ($_GET['embed'] ?? '') === '1';
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function composeFail(string $message, ?array $draft = null, string $mode = 'compose', string $folderPath = '', int $uid = 0): never
    {
        if (wants_json()) {
            json_response(['ok' => false, 'error' => $message], 422);
        }

        flash('error', $message);
        if ($draft !== null) {
            $this->saveDraftSession($draft, $mode, $folderPath, $uid);
        }

        redirect('compose');
    }

    private function appendSignature(string $body): string
    {
        $user = Auth::user();
        $sig = trim($user['signature'] ?? '');
        if ($sig === '') {
            return $body;
        }

        return rtrim($body) . "\n\n--\n" . $sig;
    }

    private function appendSignatureHtml(string $html): string
    {
        $user = Auth::user();
        $sig = trim($user['signature'] ?? '');
        if ($sig === '') {
            return $html;
        }

        return rtrim($html) . '<br><br>--<br>' . nl2br(e($sig));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function attachReplyHeaders(array &$options, string $folderPath, int $uid): void
    {
        $messageId = MailCacheService::messageIdForUid($folderPath, $uid);

        if ($messageId === null || $messageId === '') {
            $imap = new ImapService();
            if ($imap->connect()) {
                $message = $imap->getMessageByUid($folderPath, $uid);
                if ($message !== null) {
                    $messageId = trim((string) ($message['message_id'] ?? ''));
                }
            }
        }

        if ($messageId === null || $messageId === '') {
            return;
        }

        $options['in_reply_to'] = $messageId;
        $options['references'] = $messageId;
    }

    private function isValidFrom(AliasService $aliasService, string $fromEmail): bool
    {
        // The user's own alias is always allowed.
        if (strcasecmp($aliasService->userAlias(Auth::user()['id'] ?? null), $fromEmail) === 0) {
            return true;
        }

        // The shared mailbox address is always allowed.
        if (strcasecmp(config('mail')['mailbox_email'], $fromEmail) === 0) {
            return true;
        }

        // Only admins may send as any other configured alias; employees must not
        // be able to impersonate another person's address.
        $user = Auth::user();
        if ($user !== null && ($user['role'] ?? '') === 'admin') {
            foreach ($aliasService->listActive() as $alias) {
                if (strcasecmp($alias['email'], $fromEmail) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{files: list<array{path: string, name: string}>, error: string|null}
     */
    private function collectAttachments(): array
    {
        $files = [];
        $uploads = $_FILES['attachments'] ?? null;
        if ($uploads === null || !isset($uploads['name']) || !is_array($uploads['name'])) {
            return ['files' => [], 'error' => null];
        }

        $count = 0;
        foreach ($uploads['name'] as $i => $name) {
            if ($name === '' || ($uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($uploads['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                return ['files' => [], 'error' => 'Attachment upload failed.'];
            }
            if (($uploads['size'][$i] ?? 0) > self::MAX_ATTACHMENT_BYTES) {
                return ['files' => [], 'error' => 'Each attachment must be under 10 MB.'];
            }
            $count++;
            if ($count > self::MAX_ATTACHMENTS) {
                return ['files' => [], 'error' => 'Maximum ' . self::MAX_ATTACHMENTS . ' attachments allowed.'];
            }
            $files[] = [
                'path' => $uploads['tmp_name'][$i],
                'name' => $name,
            ];
        }

        return ['files' => $files, 'error' => null];
    }

    /**
     * @return array{raw: string, message_id: string, plain: string, html: string|null}
     */
    private function buildDraftMessage(
        string $from,
        string $to,
        string $cc,
        string $subject,
        string $body,
        string $bodyHtml
    ): array {
        $plain = $this->resolveDraftPlainBody($body, $bodyHtml);
        $html = HtmlSanitizer::sanitize($bodyHtml);
        if ($html !== '' && $plain === '') {
            $plain = trim(html_entity_decode(
                strip_tags(str_replace(['<br>', '<br/>', '<br />', '</div>', '</p>'], "\n", $html)),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
        }

        $msgId = '<draft.' . bin2hex(random_bytes(8)) . '@webmail.local>';
        $aliasService = new AliasService();

        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($from, $aliasService->getDisplayName($from) ?: '');
        $mail->addCustomHeader('X-DJ-Send-As', $from);
        $this->applyDraftRecipients($mail, $to, $cc, $from);
        $mail->Subject = $subject;
        $mail->MessageID = $msgId;

        if ($html !== '') {
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $plain;
        } else {
            $mail->isHTML(false);
            $mail->Body = $plain;
        }

        try {
            $mail->preSend();
        } catch (MailerException $e) {
            throw new \RuntimeException('Could not build draft: ' . $e->getMessage(), 0, $e);
        }

        return [
            'raw' => $mail->getSentMIMEMessage(),
            'message_id' => $msgId,
            'plain' => $plain,
            'html' => $html !== '' ? $html : null,
        ];
    }

    private function resolveDraftPlainBody(string $body, string $bodyHtml): string
    {
        if ($body !== '') {
            return $body;
        }

        if ($bodyHtml === '') {
            return '';
        }

        return trim(html_entity_decode(
            strip_tags(str_replace(['<br>', '<br/>', '<br />', '</div>', '</p>'], "\n", $bodyHtml)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
    }

    private function applyDraftRecipients(PHPMailer $mail, string $to, string $cc, string $from): void
    {
        $hasMailboxRecipient = false;

        foreach (parse_email_list($to)['valid'] as $addr) {
            $mail->addAddress($addr);
            $hasMailboxRecipient = true;
        }
        if ($to !== '' && !$hasMailboxRecipient) {
            $mail->addCustomHeader('To', $to);
        }

        foreach (parse_email_list($cc)['valid'] as $addr) {
            $mail->addCC($addr);
            $hasMailboxRecipient = true;
        }
        if ($cc !== '' && parse_email_list($cc)['valid'] === []) {
            $mail->addCustomHeader('Cc', $cc);
        }

        // PHPMailer requires at least one recipient to assemble MIME.
        if (!$hasMailboxRecipient && $from !== '') {
            $mail->addAddress($from);
        }
    }

    /**
     * @param array{message_id: string, plain: string, html: string|null} $draftContent
     */
    private function cacheSavedDraftBody(
        ImapService $imap,
        string $draftFolder,
        string $fromEmail,
        string $to,
        string $cc,
        string $subject,
        array $draftContent,
    ): void {
        if ($draftContent['plain'] === '' && ($draftContent['html'] ?? null) === null) {
            return;
        }

        $uid = $this->resolveLatestDraftUid($imap, $draftFolder);
        if ($uid <= 0) {
            return;
        }

        MailCacheService::saveBody($draftFolder, [
            'uid' => $uid,
            'from' => $fromEmail,
            'to' => $to,
            'cc' => $cc,
            'subject' => $subject,
            'date' => date('Y-m-d H:i:s'),
            'message_id' => $draftContent['message_id'],
            'html' => $draftContent['html'],
            'plain' => $draftContent['plain'],
            'attachments' => [],
            'seen' => false,
        ]);
    }

    private function resolveLatestDraftUid(ImapService $imap, string $draftFolder): int
    {
        $list = $imap->listMessages($draftFolder, 1, 1);

        return (int) ($list['messages'][0]['uid'] ?? 0);
    }

    private function resolveDraftsFolder(): string
    {
        return resolve_system_folder(['draft'], 'INBOX.Drafts');
    }

    private function resolveSentFolderFast(): string
    {
        return resolve_system_folder(['sent'], 'INBOX.Sent');
    }

    /**
     * Save a copy of an outgoing message to the Sent folder (best effort).
     */
    private function saveToSent(string $sentFolder, string $mime): void
    {
        if ($mime === '') {
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return;
        }

        if (!$imap->appendMessage($sentFolder, $mime, '\\Seen')) {
            app_log('Could not save sent copy: ' . $imap->getLastError());
        }
    }

    /**
     * Post-send sync (runs in background after the client gets a response).
     *
     * @return array{context_folder: string, sent_folder: string, unread_counts: array<string, int>}
     */
    private function syncMailboxAfterSend(
        string $fromEmail,
        string $returnFolder,
        string $folderPath,
        string $sentFolder,
        array $destPaths = [],
        string $sentMime = '',
        string $mode = '',
        int $replyUid = 0,
    ): array {
        $contextFolder = compose_context_folder($returnFolder, $folderPath, $fromEmail);
        $inbox = (string) (config('app')['filter_source_folder'] ?? 'INBOX');
        $fullHeaderLimit = (int) (config('app')['mail_cache_post_send_limit'] ?? 30);
        $sentMessageId = $sentMime !== '' ? extract_message_id_from_mime($sentMime) : null;

        // The heavy post-send IMAP below must NOT run while holding the main
        // session lock, or concurrent sidebar polls (folders/unread) and folder
        // loads serialise behind it for the whole job. Badge writers re-acquire
        // the lock briefly (ensure_session_writable) so we release again after
        // each IMAP batch; the final badge block re-acquires for its writes.
        releaseSessionLock();

        // Sent-only model: nothing is copied into recipient folders. The SMTP
        // server delivers to recipients; the filter engine (below) routes any
        // inbound copy into its real folder, which we then sync into mail_index.
        $imap = new ImapService();

        $filterResult = FilterService::runBackground(true, 10);
        $routedPaths = is_array($filterResult['refresh_paths'] ?? null)
            ? $filterResult['refresh_paths']
            : [];
        releaseSessionLock();

        if ($imap->connect()) {
            if (outbound_send_skips_inbox_badge($destPaths, $inbox)) {
                $imap->suppressInboundEchoOfSentMessage($inbox, $fromEmail, 20, $sentMessageId);
            } else {
                $imap->clearRecentSelfSentCopies([$inbox], $fromEmail, 6);
            }

            if (
                in_array($mode, ['reply', 'reply-all'], true)
                && $folderPath !== ''
                && $replyUid > 0
                && FolderCache::canAccess($folderPath)
            ) {
                $imap->markSeen($folderPath, $replyUid);
                MailCacheService::updateIndexSeen($folderPath, $replyUid, true);
            }

            $senderFolder = folder_for_alias_email($fromEmail);
            $pathsToSync = mail_normalize_sync_paths(array_merge(
                $destPaths,
                [$inbox, $contextFolder, $sentFolder],
                $routedPaths,
                $senderFolder !== null && $senderFolder !== '' ? [$senderFolder] : [],
            ));

            if ($senderFolder !== null && $senderFolder !== '') {
                $senderResolved = FolderCache::resolvePath(employee_messages_imap_path($senderFolder));
                if ($senderResolved !== '') {
                    $pathsToSync[] = $senderResolved;
                }
            }

            $senderInbox = employee_linked_inbox_path_for_email($fromEmail);
            if ($senderInbox !== null && $senderInbox !== '') {
                foreach (mail_normalize_sync_paths([$senderInbox]) as $senderPath) {
                    $pathsToSync[] = $senderPath;
                }
            }
            if (in_array($mode, ['reply', 'reply-all'], true) && $folderPath !== '') {
                foreach (mail_normalize_sync_paths([$folderPath]) as $replyContextPath) {
                    $pathsToSync[] = $replyContextPath;
                }
                $employeeSent = employee_personal_sent_folder_path();
                if ($employeeSent !== null && $employeeSent !== '') {
                    $pathsToSync[] = $employeeSent;
                }
            }
            $pathsToSync = array_values(array_unique(array_filter($pathsToSync)));

            foreach ($pathsToSync as $path) {
                if ($path !== '') {
                    $imap->removeDuplicateDeliveries($path, 12);
                }
                try {
                    MailCacheService::syncFolderHeaders($imap, $path, $fullHeaderLimit);
                } catch (\Throwable $e) {
                    app_log('Post-send cache sync failed for ' . $path . ': ' . $e->getMessage());
                }
                // syncFolderHeaders reconciles badges (a session write that
                // re-acquires the lock). Release after each folder so the next
                // folder's IMAP fetch — and any concurrent UI poll — is not blocked.
                releaseSessionLock();
            }
        }

        with_session_write(function () use ($routedPaths, $fromEmail, $destPaths, $inbox, $contextFolder, $sentFolder): void {
            $senderInbox = employee_linked_inbox_path(Auth::user());
            $senderFolder = folder_for_alias_email($fromEmail);

            // Server is the single source of truth. The affected folders were just
            // re-synced into mail_index above, so recompute each badge from that
            // fresh index. Our own outbound copies were appended \Seen, so they are
            // already excluded — no prediction, bump, or suppression.
            $affected = array_values(array_unique(array_filter(array_merge(
                mail_normalize_sync_paths($destPaths),
                mail_normalize_sync_paths(array_filter([$inbox, $contextFolder, $sentFolder])),
                mail_normalize_sync_paths($routedPaths),
                ($senderInbox !== null && $senderInbox !== '') ? mail_normalize_sync_paths([$senderInbox]) : [],
                ($senderFolder !== null && $senderFolder !== '') ? mail_normalize_sync_paths([$senderFolder]) : [],
            ))));

            // Drop any optimistic markers so the recompute reflects truth rather
            // than a predicted or suppressed session value.
            FolderCache::clearPendingBadgePaths();
            mail_clear_admin_outbound_badge_suppression();

            // Recompute from the fresh index (DB only). Correspondent / shared /
            // per-user folders are index-authoritative, so we must NOT hit them
            // with another round of slow IMAP STATUS here — that serialised the
            // post-send job against the sidebar polls. Only ordinary folders (a
            // small subset) fall back to an IMAP refresh.
            $imapRefreshPaths = [];
            foreach ($affected as $path) {
                mail_clear_post_send_preview($path);
                MailCacheService::reconcileBadgeFromIndex($path);
                if (!folder_badge_uses_index_truth($path)) {
                    $imapRefreshPaths[] = $path;
                }
            }
            if ($imapRefreshPaths !== []) {
                FolderCache::refreshPaths($imapRefreshPaths);
            }
        });

        return [
            'context_folder' => $contextFolder,
            'sent_folder' => $sentFolder,
            'unread_counts' => FolderCache::sidebarUnreadCounts(),
        ];
    }

    private function deleteSourceDraft(string $draftFolder, int $draftUid): void
    {
        if ($draftFolder === '' || $draftUid <= 0 || !FolderCache::canAccess($draftFolder)) {
            return;
        }

        $imap = new ImapService();
        if ($imap->connect()) {
            if ($imap->moveMessage($draftFolder, $draftUid, trash_folder_path())) {
                MailCacheService::removeMessage($draftFolder, $draftUid);
                MailCacheService::reconcileBadgeFromIndex($draftFolder);
            }
        }
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function saveDraftSession(array $draft, string $mode, string $folderPath, int $uid): void
    {
        $draft['mode'] = $mode;
        $draft['folderPath'] = $folderPath;
        $draft['uid'] = $uid;
        $_SESSION[self::DRAFT_KEY] = $draft;
        $this->redirectBack($mode, $folderPath, $uid);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function peekDraft(): ?array
    {
        $draft = $_SESSION[self::DRAFT_KEY] ?? null;

        return is_array($draft) ? $draft : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pullDraft(): ?array
    {
        $draft = $this->peekDraft();
        unset($_SESSION[self::DRAFT_KEY]);

        return $draft;
    }

    private function redirectBack(string $mode, string $folderPath, int $uid): never
    {
        $embed = $this->isEmbedRequest() ? '&embed=1' : '';
        $return = $_GET['return_folder'] ?? '';
        $returnQuery = $return !== '' ? '&return_folder=' . urlencode($return) : '';

        if ($mode === 'reply') {
            redirect('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid . $embed . $returnQuery);
        }

        if ($mode === 'reply-all') {
            redirect('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid . $embed . $returnQuery);
        }

        if ($mode === 'forward') {
            redirect('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid . $embed . $returnQuery);
        }

        redirect('compose' . ($embed !== '' ? '?embed=1' . $returnQuery : ''));
    }
}
