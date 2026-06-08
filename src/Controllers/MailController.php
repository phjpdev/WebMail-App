<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\HtmlSanitizer;
use App\Services\AliasService;
use App\Services\FolderCache;
use App\Services\ImapService;

class MailController
{
    public function home(): void
    {
        requireAuth();
        redirect('folder/' . encode_folder_path('INBOX'));
    }

    /**
     * @param array<string, string> $params
     */
    public function folder(array $params): void
    {
        requireAuth();

        $folderPath = decode_folder_path($params['folderB64'] ?? '');
        if ($folderPath === '') {
            http_response_code(404);
            echo '404 Folder not found';
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $folderData = FolderCache::load();
        $folders = $folderData['folders'];
        $imapConnected = $folderData['connected'];
        $imapError = $folderData['error'];

        if ($imapConnected && !$this->folderExists($folders, $folderPath)) {
            flash('error', 'Folder not found on mail server.');
            redirect('folder/' . encode_folder_path('INBOX'));
        }

        $list = ['messages' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'total_pages' => 0];

        if ($imapConnected) {
            $imap = new ImapService();
            if ($imap->connect()) {
                $list = $imap->listMessages($folderPath, $page);
            } else {
                $imapConnected = false;
                $imapError = $imap->getLastError();
            }
        }

        $this->renderMailView('mail/list', [
            'title' => $this->folderDisplayName($folders, $folderPath),
            'folderPath' => $folderPath,
            'folders' => $folders,
            'activeFolder' => $folderPath,
            'messages' => $list['messages'],
            'page' => $list['page'],
            'totalPages' => $list['total_pages'],
            'totalMessages' => $list['total'],
            'imapConnected' => $imapConnected,
            'imapError' => $imapError,
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
            http_response_code(404);
            echo '404 Not found';
            return;
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

        $aliasService = new AliasService();
        $replyFrom = $aliasService->resolveReplyAlias(
            $message['delivered_to'] ?? null,
            $message['to'] ?? null
        );

        $this->renderMailView('mail/read', [
            'title' => $message['subject'] ?: '(no subject)',
            'folderPath' => $folderPath,
            'folders' => $folders,
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
        ]);
    }

    public function attachment(): void
    {
        requireAuth();

        $folderPath = decode_folder_path($_GET['folder'] ?? '');
        $uid = (int) ($_GET['uid'] ?? 0);
        $partId = $_GET['part'] ?? '';

        if ($folderPath === '' || $uid <= 0 || $partId === '') {
            http_response_code(404);
            echo '404 Not found';
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            http_response_code(500);
            echo 'IMAP connection failed';
            return;
        }

        $attachment = $imap->getAttachment($folderPath, $uid, $partId);
        if ($attachment === null) {
            http_response_code(404);
            echo 'Attachment not found';
            return;
        }

        header('Content-Type: ' . $attachment['mime']);
        header('Content-Disposition: attachment; filename="' . addslashes($attachment['filename']) . '"');
        header('Content-Length: ' . strlen($attachment['content']));
        echo $attachment['content'];
        exit;
    }

    public function move(): void
    {
        requireAuth();

        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        $targetPath = $_POST['target_folder'] ?? '';

        if ($folderPath === '' || $uid <= 0 || $targetPath === '') {
            flash('error', 'Invalid move request.');
            redirect('folder/' . encode_folder_path('INBOX'));
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            flash('error', $imap->getLastError());
            redirect('folder/' . encode_folder_path($folderPath));
        }

        if ($imap->moveMessage($folderPath, $uid, $targetPath)) {
            (new FolderCache())->clear();
            flash('success', 'Message moved successfully.');
            redirect('folder/' . encode_folder_path($folderPath));
        }

        flash('error', 'Failed to move message: ' . $imap->getLastError());
        redirect(message_url($folderPath, $uid));
    }

    public function trash(): void
    {
        requireAuth();

        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);
        $trashPath = trash_folder_path();

        if ($folderPath === '' || $uid <= 0) {
            flash('error', 'Invalid delete request.');
            redirect('folder/' . encode_folder_path('INBOX'));
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            flash('error', $imap->getLastError());
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $folders = FolderCache::load()['folders'];
        if (!$this->folderExists($folders, $trashPath)) {
            flash('error', 'Trash folder (INBOX.Trash) not found on mail server.');
            redirect(message_url($folderPath, $uid));
        }

        if ($imap->moveMessage($folderPath, $uid, $trashPath)) {
            (new FolderCache())->clear();
            flash('success', 'Message moved to Trash.');
            redirect('folder/' . encode_folder_path($folderPath));
        }

        flash('error', 'Failed to delete message: ' . $imap->getLastError());
        redirect(message_url($folderPath, $uid));
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
        $data['user'] = Auth::user();
        $data['success'] = flash('success');
        $data['error'] = flash('error');

        view($viewName, $data);
    }
}
